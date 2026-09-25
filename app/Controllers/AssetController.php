<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\ValidationException;
use Edm\Models\Asset;
use finfo;

/**
 * Files (assets/). Actions:
 *   assets_(list|create|update|delete)   create = an external image URL
 *   assets_upload                         multipart image upload (field `file`)
 *
 * Uploads are stored in edm/uploads/assets/ under a random name (the original
 * name is kept only on the row) and served as static files. Their URL is
 * absolute because mail clients load images from the internet: on a local
 * machine it points at localhost and only renders there.
 */
final class AssetController extends Controller
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Allowed image MIME types (sniffed from content) => stored extension. */
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    private const UPLOAD_DIR = 'uploads/assets';

    protected function handle(string $action): mixed
    {
        if ($action === 'assets_upload') {
            return $this->upload();
        }
        if (!preg_match('/^assets_(list|create|update|delete)$/', $action, $m)) {
            $this->unknown();
        }
        if ($m[1] === 'delete') {
            $id = $this->requireId('File');
            $asset = Asset::findOrFail($id);
            Asset::delete($id);
            $this->removeStoredFile((string) $asset['url']);

            return null;
        }

        $payload = $this->request->only(['name', 'url', 'type']) + ($m[1] === 'create' ? $this->stamp('uploaded_by') : []);
        $rules = [
            'name'             => ['required', 'string', 'max:255'],
            'url'              => ['required', 'string', 'max:255'],
            'type'             => ['nullable', 'string', 'max:50'],
            'uploaded_by'      => ['nullable', 'integer'],
            'uploaded_by_name' => ['nullable', 'string', 'max:150'],
        ];

        return $this->crud($m[1], Asset::class, $payload, $rules, [
            'name' => ['sometimes', 'string', 'max:255'],
            'url'  => ['sometimes', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:50'],
        ]);
    }

    private function upload(): array
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::single('file', 'Choose an image to upload.');
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > self::MAX_BYTES) {
            throw ValidationException::single('file', 'The image must not be larger than 5 MB.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw ValidationException::single('file', 'The upload failed. Please try again.');
        }

        // Trust the file's content, not its name or the browser's MIME claim.
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::TYPES[$mime]) || @getimagesize($file['tmp_name']) === false) {
            throw ValidationException::single('file', 'Only JPG, PNG, GIF or WebP images can be uploaded.');
        }

        $dir = dirname(__DIR__, 2) . '/' . self::UPLOAD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw ValidationException::single('file', 'The upload folder is not writable.');
        }
        $stored = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
            throw ValidationException::single('file', 'The upload could not be saved.');
        }

        $name = trim((string) ($this->request->get('name') ?: $file['name']));
        $name = mb_substr($name !== '' ? $name : $stored, 0, 255);

        return Asset::create([
            'name'       => $name,
            'url'        => $this->publicUrl($stored),
            'type'       => 'image',
            'size_bytes' => (int) $file['size'],
        ] + $this->stamp('uploaded_by'));
    }

    /** Absolute URL of an uploaded file, following however odb is being served. */
    private function publicUrl(string $stored): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return ($https ? 'https' : 'http') . '://' . $host . EDM_BASE . self::UPLOAD_DIR . '/' . $stored;
    }

    /** Remove the stored file for an uploaded asset; external URLs are left alone. */
    private function removeStoredFile(string $url): void
    {
        $marker = EDM_BASE . self::UPLOAD_DIR . '/';
        $pos = strpos($url, $marker);
        if ($pos === false) {
            return;
        }
        $file = basename(substr($url, $pos + strlen($marker)));
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $file)) {
            @unlink(dirname(__DIR__, 2) . '/' . self::UPLOAD_DIR . '/' . $file);
        }
    }
}
