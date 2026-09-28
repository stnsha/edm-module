<?php

declare(strict_types=1);

namespace Edm\Services\Ses;

/**
 * Amazon SES settings, read from edm/.env (loaded by app/bootstrap.php).
 * Template and descriptions: .env.example.
 */
final class SesConfig
{
    public function __construct(
        public readonly string $region,
        public readonly ?string $accessKeyId,
        public readonly ?string $secretAccessKey,
        public readonly ?string $configurationSet,
        public readonly ?string $snsTopicArn,
        public readonly ?float $maxSendRate,
        public readonly ?int $dailyLimit,
        public readonly ?string $publicUrl,
        public readonly ?string $appKey,
    ) {
    }

    public static function fromEnv(): self
    {
        $rate = self::env('SES_MAX_SEND_RATE');
        $daily = self::env('SES_DAILY_LIMIT');

        return new self(
            region: self::env('AWS_REGION') ?? 'ap-southeast-1',
            accessKeyId: self::env('AWS_ACCESS_KEY_ID'),
            secretAccessKey: self::env('AWS_SECRET_ACCESS_KEY'),
            configurationSet: self::env('SES_CONFIGURATION_SET'),
            snsTopicArn: self::env('SES_SNS_TOPIC_ARN'),
            maxSendRate: $rate !== null && is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null,
            dailyLimit: $daily !== null && ctype_digit($daily) && (int) $daily > 0 ? (int) $daily : null,
            publicUrl: ($url = self::env('EDM_PUBLIC_URL')) !== null ? rtrim($url, '/') : null,
            appKey: self::env('EDM_APP_KEY'),
        );
    }

    /** Explicit key pair set; otherwise the SDK's default credential chain is used. */
    public function hasStaticCredentials(): bool
    {
        return $this->accessKeyId !== null && $this->secretAccessKey !== null;
    }

    /**
     * What is missing for bulk sending (unsubscribe links need the public URL
     * and the signing key). Empty = ready.
     *
     * @return list<string>
     */
    public function missingForSending(): array
    {
        $missing = [];
        if ($this->publicUrl === null) {
            $missing[] = 'EDM_PUBLIC_URL';
        }
        if ($this->appKey === null || strlen($this->appKey) < 32) {
            $missing[] = 'EDM_APP_KEY (32+ characters)';
        }

        return $missing;
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
