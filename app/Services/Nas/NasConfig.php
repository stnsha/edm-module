<?php

declare(strict_types=1);

namespace Edm\Services\Nas;

/**
 * Synology NAS settings, read from edm/.env (loaded by app/autoload.php).
 * Template and descriptions: .env.example. The password never goes to the
 * database or the browser.
 */
final class NasConfig
{
    public function __construct(
        public readonly ?string $host,
        public readonly int $port,
        public readonly ?string $user,
        public readonly ?string $password,
        public readonly string $folder,
        public readonly bool $verifyTls,
    ) {
    }

    public static function fromEnv(): self
    {
        $port = self::env('NAS_PORT');
        $verify = self::env('NAS_VERIFY_TLS');

        return new self(
            host: self::env('NAS_HOST'),
            port: $port !== null && ctype_digit($port) ? (int) $port : 5001,
            user: self::env('NAS_USER'),
            password: self::env('NAS_PASSWORD'),
            folder: rtrim(self::env('NAS_FOLDER') ?? '/edm', '/'),
            verifyTls: $verify === null || filter_var($verify, FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function isConfigured(): bool
    {
        return $this->host !== null && $this->user !== null && $this->password !== null;
    }

    /** NAS folder that holds the Files library images. */
    public function assetsFolder(): string
    {
        return $this->folder . '/assets';
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
