<?php

declare(strict_types=1);

namespace Edm\Services\Ses;

/**
 * Signed unsubscribe links (public/unsubscribe.php). The link carries the
 * campaign id and the recipient address, signed with EDM_APP_KEY so it cannot
 * be forged to unsubscribe someone else.
 */
final class Unsubscribe
{
    public function __construct(private SesConfig $config)
    {
    }

    public function url(int $campaignId, string $email): string
    {
        $missing = $this->config->missingForSending();
        if ($missing !== []) {
            throw new SesException('Unsubscribe links need ' . implode(' and ', $missing) . ' in edm/.env.');
        }
        $e = self::b64(strtolower($email));

        return $this->config->publicUrl . '/public/unsubscribe.php?' . http_build_query([
            'c' => $campaignId,
            'e' => $e,
            't' => $this->sign($campaignId, $e),
        ]);
    }

    /**
     * The address a link is for, or null when the signature does not match.
     *
     * @return array{campaign_id: int, email: string}|null
     */
    public function verify(string $campaignId, string $encodedEmail, string $token): ?array
    {
        if (!ctype_digit($campaignId) || $this->config->appKey === null) {
            return null;
        }
        if (!hash_equals($this->sign((int) $campaignId, $encodedEmail), $token)) {
            return null;
        }
        $email = base64_decode(strtr($encodedEmail, '-_', '+/'), true);
        if ($email === false || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return ['campaign_id' => (int) $campaignId, 'email' => $email];
    }

    private function sign(int $campaignId, string $encodedEmail): string
    {
        return hash_hmac('sha256', 'unsubscribe|' . $campaignId . '|' . $encodedEmail, (string) $this->config->appKey);
    }

    private static function b64(string $v): string
    {
        return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    }
}
