<?php

declare(strict_types=1);

namespace Edm\Services\Nas;

use Edm\Services\Ses\SesConfig;

/**
 * Files library images on the NAS. The NAS copy is the master; recipients
 * never reach the NAS (it needs a login) - emails link to the public proxy
 * public/file.php?f=<name>, which serves a server-side cache in
 * edm/cache/nas/ and fills it from the NAS on a miss.
 *
 * Stored names are random: 32 hex characters + the extension of the sniffed
 * image type. NAME_PATTERN is the only shape the proxy accepts, so no other
 * NAS path can ever be requested through it.
 */
final class NasImageStore
{
    public const NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/';

    /** Extension => Content-Type the proxy sends. */
    public const MIME = [
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    private const PROXY = 'public/file.php';

    private ?SynologyNas $nas = null;

    public function __construct(private NasConfig $config)
    {
    }

    public static function fromEnv(): self
    {
        return new self(NasConfig::fromEnv());
    }

    public static function isName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /** A new random stored name for an image of $extension. */
    public static function newName(string $extension): string
    {
        return bin2hex(random_bytes(16)) . '.' . $extension;
    }

    /**
     * Uploads an image to the NAS and seeds the cache from the same file,
     * so the first view does not go to the NAS.
     *
     * @throws NasException
     */
    public function put(string $localPath, string $name): void
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $this->nas()->upload($localPath, $this->config->assetsFolder(), $name, self::MIME[$ext] ?? 'application/octet-stream');
        $cache = $this->cachePath($name);
        if ($this->ensureCacheDir()) {
            @copy($localPath, $cache);
        }
    }

    /**
     * Local path of the image, fetched from the NAS into the cache when it
     * is not there yet; null when the NAS does not have it (or it is not an
     * image). One request fetches a given file at a time (lock file).
     *
     * @throws NasException when the NAS cannot be reached
     */
    public function local(string $name): ?string
    {
        if (!self::isName($name)) {
            return null;
        }
        $cache = $this->cachePath($name);
        if (is_file($cache)) {
            return $cache;
        }
        if (!$this->ensureCacheDir()) {
            throw new NasException('The NAS cache folder is not writable.');
        }

        $lock = fopen($cache . '.lock', 'c');
        if ($lock === false) {
            throw new NasException('The NAS cache folder is not writable.');
        }
        try {
            flock($lock, LOCK_EX);
            // Another request may have filled it while this one waited.
            if (is_file($cache)) {
                return $cache;
            }
            $body = $this->nas()->download($this->config->assetsFolder() . '/' . $name);
            if ($body === null || @getimagesizefromstring($body) === false) {
                return null;
            }
            $tmp = $cache . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $body) === false || !rename($tmp, $cache)) {
                @unlink($tmp);
                throw new NasException('The NAS cache folder is not writable.');
            }

            return $cache;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($cache . '.lock');
        }
    }

    /**
     * Removes the image from the NAS and the cache.
     *
     * @throws NasException
     */
    public function delete(string $name): void
    {
        if (!self::isName($name)) {
            return;
        }
        $this->nas()->delete($this->config->assetsFolder() . '/' . $name);
        @unlink($this->cachePath($name));
    }

    /**
     * Absolute proxy URL of an image. Images go into emails, so they must
     * load for recipients: EDM_PUBLIC_URL when set; otherwise however odb is
     * being served (on localhost the image then shows in the editor but not
     * in a recipient's inbox).
     */
    public static function url(string $name): string
    {
        $path = self::PROXY . '?f=' . rawurlencode($name);
        $base = SesConfig::fromEnv()->publicUrl;
        if ($base !== null) {
            return $base . '/' . $path;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return ($https ? 'https' : 'http') . '://' . $host . EDM_BASE . $path;
    }

    /** The stored name inside a proxy URL, or null for any other URL. */
    public static function nameFromUrl(string $url): ?string
    {
        if (!str_contains($url, '/' . self::PROXY . '?')) {
            return null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $name = is_string($q['f'] ?? null) ? $q['f'] : '';

        return self::isName($name) ? $name : null;
    }

    private function nas(): SynologyNas
    {
        return $this->nas ??= new SynologyNas($this->config);
    }

    private function cachePath(string $name): string
    {
        return dirname(__DIR__, 3) . '/cache/nas/' . $name;
    }

    private function ensureCacheDir(): bool
    {
        $dir = dirname(__DIR__, 3) . '/cache/nas';

        return is_dir($dir) || mkdir($dir, 0755, true) || is_dir($dir);
    }
}
