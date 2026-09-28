<?php

declare(strict_types=1);

namespace Edm\Services\Ses;

use Edm\Models\Sender;
use Edm\Models\SendingDomain;

/**
 * Brings Settings > Senders / Sending domains in line with SES.
 *
 * Sender: verified when SES can send from it - either the address itself or
 * its whole domain is a verified identity.
 * Domain: DKIM from SES; SPF verified when SES reports a custom MAIL FROM as
 * set up or the domain publishes an SPF record; DMARC from the domain's
 * _dmarc TXT record (SES does not check DMARC). Status ints: 1 pending,
 * 2 verified, 3 failed (Sender::STATUSES / SendingDomain::STATUSES).
 */
final class IdentitySync
{
    public function __construct(private SesGateway $ses)
    {
    }

    /** @return array{sender: array<string, mixed>, message: string} */
    public function checkSender(int $id): array
    {
        $sender = Sender::findOrFail($id);
        $email = strtolower((string) $sender['email']);
        $domain = substr((string) strrchr($email, '@'), 1);

        $identity = $this->ses->identity($email);
        $viaDomain = false;
        if ($identity === null || !$identity['verified']) {
            $domainIdentity = $domain !== '' ? $this->ses->identity($domain) : null;
            if ($domainIdentity !== null && $domainIdentity['verified']) {
                $identity = $domainIdentity;
                $viaDomain = true;
            }
        }

        if ($identity === null) {
            $status = 1;
            $message = 'SES does not know ' . $email . ' or ' . $domain . ' yet. Use "Request verification" to send the AWS verification email.';
        } elseif ($identity['verified']) {
            $status = 2;
            $message = $viaDomain ? 'Verified through the domain ' . $domain . '.' : 'Verified in SES.';
        } elseif (in_array($identity['verification_status'], ['FAILED', 'TEMPORARY_FAILURE'], true)) {
            $status = 3;
            $message = 'SES verification failed (' . $identity['verification_status'] . '). Request verification again.';
        } else {
            $status = 1;
            $message = 'Waiting for the verification link in the AWS email to be clicked.';
        }

        return [
            'sender'  => Sender::update($id, [
                'status'      => $status,
                // Keep the first verification time; the cast accepts the display form.
                'verified_at' => $status === 2 ? ($sender['verified_at'] ?? date('Y-m-d H:i:s')) : null,
            ]),
            'message' => $message,
        ];
    }

    /** Ask SES to email the sender address a verification link. */
    public function requestSender(int $id): array
    {
        $sender = Sender::findOrFail($id);
        $email = strtolower((string) $sender['email']);
        $existing = $this->ses->identity($email);
        if ($existing === null) {
            $this->ses->createIdentity($email);
        } elseif (!$existing['verified']) {
            throw new SesException($email . ' is already registered with SES and waiting for its verification link. To get a new link, delete the identity in the AWS console and request again.');
        }

        return $this->checkSender($id) + ['requested' => $existing === null];
    }

    /**
     * Check a sending domain; registers it with SES first when missing.
     *
     * @return array{domain: array<string, mixed>, dns: list<array{type: string, name: string, value: string, purpose: string}>, message: string}
     */
    public function checkDomain(int $id): array
    {
        $row = SendingDomain::findOrFail($id);
        $domain = strtolower((string) $row['domain']);
        $identity = $this->ses->identity($domain) ?? $this->ses->createIdentity($domain);

        $dkim = match ($identity['dkim_status']) {
            'SUCCESS' => 2,
            'FAILED', 'TEMPORARY_FAILURE' => 3,
            default => 1,
        };
        $spf = $identity['mail_from_status'] === 'SUCCESS' || self::hasTxt($domain, 'v=spf1') ? 2 : 1;
        $dmarc = self::hasTxt('_dmarc.' . $domain, 'v=DMARC1') ? 2 : 1;

        $dns = array_map(static fn (string $token): array => [
            'type'    => 'CNAME',
            'name'    => $token . '._domainkey.' . $domain,
            'value'   => $token . '.dkim.amazonses.com',
            'purpose' => 'DKIM',
        ], $identity['dkim_tokens']);
        if ($dmarc !== 2) {
            $dns[] = ['type' => 'TXT', 'name' => '_dmarc.' . $domain, 'value' => 'v=DMARC1; p=none;', 'purpose' => 'DMARC (start with p=none)'];
        }

        return [
            'domain'  => SendingDomain::update($id, ['dkim_status' => $dkim, 'spf_status' => $spf, 'dmarc_status' => $dmarc]),
            'dns'     => $dns,
            'message' => $identity['verified']
                ? 'Verified in SES - any address @' . $domain . ' can send.'
                : 'Not verified yet. Publish the DNS records below, then check again (DNS can take up to 72 hours).',
        ];
    }

    private static function hasTxt(string $host, string $prefix): bool
    {
        $records = @dns_get_record($host, DNS_TXT);
        foreach (is_array($records) ? $records : [] as $r) {
            $txt = (string) ($r['txt'] ?? implode('', $r['entries'] ?? []));
            if (stripos(ltrim($txt), $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}
