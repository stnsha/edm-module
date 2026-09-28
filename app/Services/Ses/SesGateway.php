<?php

declare(strict_types=1);

namespace Edm\Services\Ses;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\SesV2\SesV2Client;

/**
 * Thin wrapper over the AWS SDK's SES v2 client: the only class that talks to
 * SES. Every AWS failure surfaces as SesException with the AWS error code.
 */
final class SesGateway
{
    private ?SesV2Client $client = null;

    public function __construct(public readonly SesConfig $config)
    {
    }

    public static function fromEnv(): self
    {
        return new self(SesConfig::fromEnv());
    }

    /**
     * Account state: sandbox / production, 24-hour quota, send rate.
     *
     * @return array{sending_enabled: bool, production_access: bool, enforcement: ?string,
     *               max_24h: float, sent_24h: float, max_rate: float, region: string}
     */
    public function account(): array
    {
        $r = $this->call(fn (SesV2Client $c) => $c->getAccount());
        $q = $r['SendQuota'] ?? [];

        return [
            'sending_enabled'   => (bool) ($r['SendingEnabled'] ?? false),
            'production_access' => (bool) ($r['ProductionAccessEnabled'] ?? false),
            'enforcement'       => $r['EnforcementStatus'] ?? null,
            'max_24h'           => (float) ($q['Max24HourSend'] ?? 0),
            'sent_24h'          => (float) ($q['SentLast24Hours'] ?? 0),
            'max_rate'          => (float) ($q['MaxSendRate'] ?? 1),
            'region'            => $this->config->region,
        ];
    }

    /**
     * Send one HTML email. Returns the SES message id.
     *
     * @param array<string, string> $headers extra headers (List-Unsubscribe, ...)
     * @param array<string, string> $tags    message tags, echoed back on every SES event
     */
    public function send(
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $html,
        ?string $replyTo = null,
        array $headers = [],
        array $tags = [],
    ): string {
        $message = [
            'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
            'Body'    => [
                'Html' => ['Data' => $html, 'Charset' => 'UTF-8'],
                'Text' => ['Data' => self::textPart($html), 'Charset' => 'UTF-8'],
            ],
        ];
        if ($headers !== []) {
            $message['Headers'] = array_map(
                static fn (string $name, string $value): array => ['Name' => $name, 'Value' => $value],
                array_keys($headers),
                array_values($headers)
            );
        }

        $args = [
            'FromEmailAddress' => self::formatAddress($fromEmail, $fromName),
            'Destination'      => ['ToAddresses' => [$to]],
            'Content'          => ['Simple' => $message],
        ];
        if ($replyTo !== null && $replyTo !== '') {
            $args['ReplyToAddresses'] = [$replyTo];
        }
        if ($this->config->configurationSet !== null) {
            $args['ConfigurationSetName'] = $this->config->configurationSet;
        }
        if ($tags !== []) {
            $args['EmailTags'] = array_map(
                static fn (string $name, string $value): array => ['Name' => $name, 'Value' => $value],
                array_keys($tags),
                array_values($tags)
            );
        }

        $r = $this->call(fn (SesV2Client $c) => $c->sendEmail($args));

        return (string) ($r['MessageId'] ?? '');
    }

    /**
     * An email address or domain identity, or null when SES does not know it.
     *
     * @return array{type: string, verified: bool, verification_status: ?string, dkim_status: ?string,
     *               dkim_tokens: list<string>, mail_from_status: ?string}|null
     */
    public function identity(string $identity): ?array
    {
        try {
            $r = $this->call(fn (SesV2Client $c) => $c->getEmailIdentity(['EmailIdentity' => $identity]));
        } catch (SesException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }

        return self::shapeIdentity($r->toArray());
    }

    /**
     * Register an identity with SES. An email address gets a verification
     * email from AWS; a domain gets DKIM tokens to publish as CNAME records.
     *
     * @return array{type: string, verified: bool, verification_status: ?string, dkim_status: ?string,
     *               dkim_tokens: list<string>, mail_from_status: ?string}
     */
    public function createIdentity(string $identity): array
    {
        $this->call(fn (SesV2Client $c) => $c->createEmailIdentity(['EmailIdentity' => $identity]));

        return $this->identity($identity) ?? throw new SesException('SES did not return the new identity ' . $identity . '.');
    }

    /** @param array<string, mixed> $r GetEmailIdentity response */
    private static function shapeIdentity(array $r): array
    {
        $dkim = $r['DkimAttributes'] ?? [];

        return [
            'type'                => (string) ($r['IdentityType'] ?? ''),
            'verified'            => (bool) ($r['VerifiedForSendingStatus'] ?? false),
            'verification_status' => $r['VerificationStatus'] ?? null,
            'dkim_status'         => $dkim['Status'] ?? null,
            'dkim_tokens'         => array_values(array_map('strval', $dkim['Tokens'] ?? [])),
            'mail_from_status'    => $r['MailFromAttributes']['MailFromDomainStatus'] ?? null,
        ];
    }

    /**
     * @template T
     * @param callable(SesV2Client): T $fn
     * @return T
     */
    private function call(callable $fn): mixed
    {
        try {
            return $fn($this->client());
        } catch (AwsException $e) {
            $code = (string) $e->getAwsErrorCode();
            $message = $e->getAwsErrorMessage() ?: $e->getMessage();
            throw new SesException(
                'Amazon SES: ' . ($code !== '' ? $code . ' - ' : '') . $message,
                $code !== '' ? $code : null,
                $e
            );
        } catch (CredentialsException $e) {
            throw new SesException(
                'Amazon SES: no AWS credentials - set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY in edm/.env.',
                null,
                $e
            );
        }
    }

    private function client(): SesV2Client
    {
        if ($this->client === null) {
            $args = ['region' => $this->config->region, 'version' => '2019-09-27'];
            if ($this->config->hasStaticCredentials()) {
                $args['credentials'] = [
                    'key'    => $this->config->accessKeyId,
                    'secret' => $this->config->secretAccessKey,
                ];
            }
            $this->client = new SesV2Client($args);
        }

        return $this->client;
    }

    /** "Name <email>", with the name RFC 2047-encoded when it is not plain ASCII. */
    private static function formatAddress(string $email, string $name): string
    {
        $name = trim(str_replace(["\r", "\n", '"'], ' ', $name));
        if ($name === '') {
            return $email;
        }
        $display = preg_match('/^[\x20-\x7E]*$/', $name)
            ? '"' . $name . '"'
            : '=?UTF-8?B?' . base64_encode($name) . '?=';

        return $display . ' <' . $email . '>';
    }

    /** Plain-text alternative: the HTML without tags, links kept as "text (url)". */
    private static function textPart(string $html): string
    {
        $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2 ($1)', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/tr|/h[1-6]|/li)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n\s*\n+/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
