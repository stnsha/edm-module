<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\HttpException;
use Edm\Core\ValidationException;
use Edm\Models\Asset;
use Edm\Services\Nas\NasException;
use Edm\Services\Nas\NasImageStore;
use finfo;

/**
 * Files (assets/). Actions:
 *   assets_(list|create|update|delete)   create = an external image URL
 *   assets_upload                         multipart image upload (field `file`)
 *
 * Uploads are stored on the Synology NAS (Services\Nas\NasImageStore) under a
 * random name (the original name is kept only on the row) and linked through
 * the public proxy public/file.php. Their URL is absolute because mail
 * clients load images from the internet: on a local machine it points at
 * localhost and only renders there. Uploads made before the NAS stay in
 * edm/uploads/assets/ and keep their static URLs.
 *
 * Delete removes the row; the stored file is removed too, unless a newsletter
 * or template has used it - emails already sent keep their images.
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

    /** Uploads from before the NAS, served as static files. */
    private const LEGACY_DIR = 'uploads/assets';

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
            $url = (string) $asset['url'];
            if (!$this->isUsed($url)) {
                $this->removeStoredFile($url);
            }

            return null;
        }

        $payload = $this->request->only(['name', 'url', 'type']) + ($m[1] === 'create' ? $this->stamp('uploaded_by') : []);
        // An image hosted elsewhere must be a full web address - it is put
        // into emails as-is and loaded by the recipient's mail client.
        if (isset($payload['url'])) {
            $url = (string) $payload['url'];
            if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw ValidationException::single('url', 'Enter a full image address starting with http:// or https://.');
            }
        }
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

        $stored = NasImageStore::newName(self::TYPES[$mime]);
        try {
            NasImageStore::fromEnv()->put($file['tmp_name'], $stored);
        } catch (NasException $e) {
            error_log('[EDM] assets_upload: ' . $e->getMessage());
            throw new HttpException('The image could not be saved to the NAS: ' . $e->getMessage(), 502);
        }

        $name = trim((string) ($this->request->get('name') ?: $file['name']));
        $name = mb_substr($name !== '' ? $name : $stored, 0, 255);

        return Asset::create([
            'name'       => $name,
            'url'        => NasImageStore::url($stored),
            'type'       => 'image',
            'size_bytes' => (int) $file['size'],
        ] + $this->stamp('uploaded_by'));
    }

    /**
     * Whether a newsletter or template refers to the image URL. Soft-deleted
     * rows count too: a deleted newsletter's emails may already be in inboxes.
     */
    private function isUsed(string $url): bool
    {
        $like = '%' . addcslashes($url, '%_' . chr(92)) . '%';

        return (bool) $this->db->scalar(
            'SELECT EXISTS (SELECT 1 FROM `edm_campaign_content` WHERE `html` LIKE ? OR CAST(`editor_json` AS CHAR) LIKE ?)
                 OR EXISTS (SELECT 1 FROM `edm_templates` WHERE `html` LIKE ? OR CAST(`editor_json` AS CHAR) LIKE ? OR `thumbnail_url` = ?)',
            [$like, $like, $like, $like, $url]
        );
    }

    /**
     * Remove the stored file of an uploaded image: on the NAS (proxy URL) or
     * in the legacy upload folder. External URLs are left alone. A NAS
     * failure is only logged - the row is already gone.
     */
    private function removeStoredFile(string $url): void
    {
        $name = NasImageStore::nameFromUrl($url);
        if ($name !== null) {
            try {
                NasImageStore::fromEnv()->delete($name);
            } catch (NasException $e) {
                error_log('[EDM] assets_delete ' . $name . ': ' . $e->getMessage());
            }

            return;
        }
        $marker = EDM_BASE . self::LEGACY_DIR . '/';
        $pos = strpos($url, $marker);
        if ($pos === false) {
            return;
        }
        $file = basename(substr($url, $pos + strlen($marker)));
        if (NasImageStore::isName($file)) {
            @unlink(dirname(__DIR__, 2) . '/' . self::LEGACY_DIR . '/' . $file);
        }
    }
}
