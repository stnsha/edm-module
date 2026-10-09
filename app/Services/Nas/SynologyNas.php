<?php

declare(strict_types=1);

namespace Edm\Services\Nas;

use CURLFile;
use CurlHandle;

/**
 * Synology DSM File Station Web API client: login, upload, download, delete.
 * The only class that talks to the NAS. Modernised from odb's
 * nas/synologynas.php (CorporateNAS):
 *   - the password is POSTed, never put in a URL (URLs end up in logs);
 *   - TLS certificates are verified unless NAS_VERIFY_TLS=false;
 *   - every failure is a NasException; logout() ends the DSM session.
 */
final class SynologyNas
{
    private ?string $sid = null;

    public function __construct(private NasConfig $config)
    {
        if (!$config->isConfigured()) {
            throw new NasException('The NAS is not configured (NAS_HOST, NAS_USER, NAS_PASSWORD in edm/.env).');
        }
    }

    public function __destruct()
    {
        $this->logout();
    }

    /**
     * Uploads $localPath into $folder as $fileName (folders are created,
     * an existing file with that name is overwritten).
     *
     * @return string the file's path on the NAS
     */
    public function upload(string $localPath, string $folder, string $fileName, string $mime): string
    {
        // Upload reads the API, method and session from the URL; the
        // multipart body holds only the target and the file (file last).
        $data = $this->call('entry.cgi?' . http_build_query([
            'api'     => 'SYNO.FileStation.Upload',
            'version' => 2,
            'method'  => 'upload',
            '_sid'    => $this->sid(),
        ]), [
            'path'           => $folder,
            'create_parents' => 'true',
            'overwrite'      => 'true',
            'file'           => new CURLFile($localPath, $mime, $fileName),
        ], 120);
        if (empty($data['success'])) {
            throw new NasException('The NAS refused the upload' . self::code($data) . '.');
        }

        return $folder . '/' . $fileName;
    }

    /** Contents of a NAS file, or null when it does not exist. */
    public function download(string $path): ?string
    {
        $ch = $this->curl('entry.cgi?' . http_build_query([
            'api'     => 'SYNO.FileStation.Download',
            'version' => 2,
            'method'  => 'download',
            'mode'    => 'download',
            'path'    => $path,
            '_sid'    => $this->sid(),
        ]), 60);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new NasException('Could not reach the NAS: ' . $error);
        }
        // A missing file is answered with a JSON error body, not the file.
        if ($status < 200 || $status >= 300 || $body === '' || str_starts_with($type, 'application/json')) {
            return null;
        }

        return (string) $body;
    }

    /** Deletes a NAS file (permanently, not to the recycle bin). */
    public function delete(string $path): void
    {
        $data = $this->call('entry.cgi', [
            'api'       => 'SYNO.FileStation.Delete',
            'version'   => '2',
            'method'    => 'delete',
            'path'      => $path,
            'recursive' => 'false',
            '_sid'      => $this->sid(),
        ]);
        if (empty($data['success'])) {
            throw new NasException('The NAS could not delete the file' . self::code($data) . '.');
        }
    }

    public function logout(): void
    {
        if ($this->sid === null) {
            return;
        }
        $sid = $this->sid;
        $this->sid = null;
        try {
            $this->call('auth.cgi', ['api' => 'SYNO.API.Auth', 'version' => '6', 'method' => 'logout', 'session' => 'FileStation', '_sid' => $sid], 10);
        } catch (NasException) {
            // The session expires on the NAS by itself.
        }
    }

    private function sid(): string
    {
        if ($this->sid !== null) {
            return $this->sid;
        }
        $data = $this->call('auth.cgi', [
            'api'     => 'SYNO.API.Auth',
            'version' => '6',
            'method'  => 'login',
            'account' => (string) $this->config->user,
            'passwd'  => (string) $this->config->password,
            'session' => 'FileStation',
            'format'  => 'sid',
        ], 20);
        if (!isset($data['data']['sid']) || !is_string($data['data']['sid'])) {
            throw new NasException('NAS login failed' . self::code($data) . '.');
        }

        return $this->sid = $data['data']['sid'];
    }

    /**
     * POST to a DSM web API script; returns the decoded JSON answer.
     *
     * @param array<string, string|CURLFile> $fields
     * @return array<string, mixed>
     */
    private function call(string $script, array $fields, int $timeout = 30): array
    {
        $ch = $this->curl($script, $timeout);
        curl_setopt($ch, CURLOPT_POST, true);
        // A CURLFile needs multipart; plain fields go url-encoded.
        $multipart = array_filter($fields, static fn ($v): bool => $v instanceof CURLFile) !== [];
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new NasException('Could not reach the NAS: ' . $error);
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new NasException('The NAS sent an unexpected answer.');
        }

        return $data;
    }

    private function curl(string $script, int $timeout): CurlHandle
    {
        $ch = curl_init('https://' . $this->config->host . ':' . $this->config->port . '/webapi/' . $script);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => $this->config->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->config->verifyTls ? 2 : 0,
        ]);

        return $ch;
    }

    /** " (code 408)" from a DSM error answer, for messages. */
    private static function code(array $data): string
    {
        return isset($data['error']['code']) ? ' (code ' . (int) $data['error']['code'] . ')' : '';
    }
}
