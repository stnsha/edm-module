<?php

declare(strict_types=1);

namespace Edm\Services\Analytics;

/**
 * Google Analytics 4 settings, read from edm/.env (loaded by app/bootstrap.php).
 * Template and descriptions: .env.example.
 *
 *   GA4_PROPERTY_ID       numeric Property ID (not the G-XXXX Measurement ID)
 *   GA4_CREDENTIALS_JSON  the service account key file, base64-encoded on one
 *                         line (a raw one-line JSON value is accepted too)
 */
final class Ga4Config
{
    /** @param array<string, mixed>|null $credentials the decoded service account key */
    public function __construct(
        public readonly ?string $propertyId,
        public readonly ?array $credentials,
        private bool $credentialsSet,
    ) {
    }

    public static function fromEnv(): self
    {
        $raw = self::env('GA4_CREDENTIALS_JSON');
        $credentials = null;
        if ($raw !== null) {
            $json = str_starts_with($raw, '{') ? $raw : base64_decode($raw, true);
            $key = is_string($json) ? json_decode($json, true) : null;
            if (is_array($key) && is_string($key['client_email'] ?? null) && is_string($key['private_key'] ?? null)) {
                $credentials = $key;
            }
        }

        return new self(self::env('GA4_PROPERTY_ID'), $credentials, $raw !== null);
    }

    /**
     * What is missing or wrong in edm/.env. Empty = ready.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];
        if ($this->propertyId === null) {
            $missing[] = 'GA4_PROPERTY_ID';
        } elseif (!ctype_digit($this->propertyId)) {
            $missing[] = 'GA4_PROPERTY_ID (the numeric Property ID, not the G- Measurement ID)';
        }
        if ($this->credentials === null) {
            $missing[] = $this->credentialsSet ? 'GA4_CREDENTIALS_JSON (not a valid service account key)' : 'GA4_CREDENTIALS_JSON';
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missing() === [];
    }

    /** The service account address that must be a Viewer on the GA4 property. */
    public function clientEmail(): ?string
    {
        return $this->credentials['client_email'] ?? null;
    }

    private static function env(string $key): ?string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
