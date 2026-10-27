<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Services\Import\EmailCleanser;
use Edm\Services\Import\EmailListCleanse;

/**
 * Email cleaner (email-cleaner/): upload a list, get Valid / Invalid
 * workbooks back. Open to every EDM role, department PICs included; nothing
 * touches the database.
 *   cleaner_preview   multipart: file -> token, preview, suggested columns
 *   cleaner_run       { token, columns { email, name, mobile, ic }, has_header, offset }
 *                     -> one batch: { total, offset, processed, next, sum };
 *                     the last (next null) adds totals + reasons
 *   cleaner_download  ?token=&kind=valid|invalid -> the .xlsx
 */
final class EmailCleanerController extends Controller
{
    protected static function allowsPic(string $action): bool
    {
        return in_array($action, ['cleaner_preview', 'cleaner_run', 'cleaner_download'], true);
    }

    protected function handle(string $action): mixed
    {
        $cleanse = new EmailListCleanse((int) $this->auth->staffId, EmailCleanser::fromEnv());

        return match ($action) {
            'cleaner_preview'  => $cleanse->stageUpload($_FILES['file'] ?? ['error' => UPLOAD_ERR_NO_FILE]),
            'cleaner_run'      => $this->runBatch($cleanse),
            'cleaner_download' => $this->download($cleanse),
            default            => $this->unknown(),
        };
    }

    private function runBatch(EmailListCleanse $cleanse): array
    {
        $data = $this->validator->validate(
            ['token' => $this->request->get('token'), 'columns' => $this->request->get('columns')],
            [
                'token'   => ['required', 'string', 'max:64'],
                'columns' => ['required', 'array'],
            ]
        );

        return $cleanse->run(
            (string) $data['token'],
            $data['columns'],
            !empty($this->request->get('has_header')),
            max(0, (int) $this->request->get('offset', 0))
        );
    }

    private function download(EmailListCleanse $cleanse): never
    {
        $file = $cleanse->download((string) $this->request->query('token', ''), (string) $this->request->query('kind', ''));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
        header('Content-Length: ' . filesize($file['path']));
        header('Cache-Control: no-store');
        readfile($file['path']);
        exit;
    }
}
