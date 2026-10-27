<?php

declare(strict_types=1);

namespace Edm\Services\Import;

use Edm\Core\HttpException;
use Edm\Core\ValidationException;

/**
 * Email cleaner (email-cleaner/): any EDM user uploads a list, it goes
 * through the rules of cleanse/clean_email_list.py (EmailCleanser) and comes
 * back as two workbooks - Valid Emails.xlsx (the file's columns + Cleaned
 * Email, Notes, Flags) and Invalid Emails.xlsx (the file's columns +
 * Reason), the same layout as running the script by hand. Nothing is written
 * to the database.
 *
 *   stage()     the parsed rows are kept in a temp file under a random token
 *               (owned by the staff member), with a preview and suggested
 *               columns for email / name / mobile / IC;
 *   run()       one batch per call (offset -> next, like ContactImport), in
 *               the script's order: first-pass rules (validate +
 *               record_reason), shared by 5+ customers, duplicate of an
 *               earlier valid email, DNS; after the last batch the two
 *               workbooks are written and the working files removed;
 *   download()  the path of a finished workbook.
 *
 * Temp files expire after STAGE_TTL.
 */
final class EmailListCleanse
{
    /** Columns the rules read; only email is required. */
    public const COLUMNS = ['email', 'name', 'mobile', 'ic'];

    public const BATCH_ROWS = 5000;

    /**
     * Rows per file. Excel holds 1,048,576 rows per sheet, so this is about
     * the most the Valid / Invalid workbooks can take.
     */
    public const MAX_ROWS = 1000000;

    /**
     * Upload size caps (MB). .xls / .ods are not taken: only PhpSpreadsheet
     * reads them, at ~1 KB of PHP memory per cell - save them as .xlsx.
     */
    public const LIMITS_MB = ['xlsx' => 150, 'csv' => 200, 'txt' => 200];

    public const DOWNLOADS = [
        'valid'   => 'Valid Emails',
        'invalid' => 'Invalid Emails',
    ];

    private const PREVIEW_ROWS = 5;
    private const STAGE_TTL = 86400;

    public function __construct(private int $staffId, private EmailCleanser $cleanser)
    {
    }

    /**
     * Stages an upload: its rows go straight to a temp file (ImportFileReader::
     * toFile - .xlsx streamed by Python, .csv / .txt line by line), so PHP
     * memory does not grow with the sheet; only the first rows are read back
     * for the preview.
     *
     * @param array<string, mixed> $file a $_FILES entry
     * @return array<string, mixed> token, preview, suggested columns
     */
    public function stageUpload(array $file): array
    {
        $this->purgeOld();
        $token = bin2hex(random_bytes(16));
        $staged = $this->path($token, 'rows.jsonl');
        try {
            $total = ImportFileReader::toFile($file, $staged, self::MAX_ROWS, self::LIMITS_MB);
        } catch (ValidationException $e) {
            @unlink($staged);
            throw $e;
        }
        $fileName = (string) ($file['name'] ?? '');
        file_put_contents($this->path($token, 'meta.json'), json_encode(['name' => $fileName], JSON_UNESCAPED_UNICODE));

        $rows = [];
        $fh = fopen($staged, 'rb');
        while (count($rows) <= self::PREVIEW_ROWS && ($line = fgets($fh)) !== false) {
            $rows[] = json_decode($line, true) ?: [];
        }
        fclose($fh);
        $hasHeader = !self::rowHasEmail($rows[0]);

        return [
            'token'      => $token,
            'file_name'  => $fileName,
            'total_rows' => $total,
            'has_header' => $hasHeader,
            'first_row'  => $rows[0],
            'sample'     => array_slice($rows, $hasHeader ? 1 : 0, self::PREVIEW_ROWS),
            'columns'    => self::suggest($rows, $hasHeader),
        ];
    }

    /**
     * Cleanses one batch. Batches run in order (state file per token); the
     * last one (next null) also writes the workbooks and returns the totals
     * and the reason breakdown.
     *
     * @param array<string, int|null> $columns column index per COLUMNS key
     * @return array<string, mixed>
     */
    public function run(string $token, array $columns, bool $hasHeader, int $offset = 0): array
    {
        $file = $this->path($token, 'rows.jsonl');
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file($file)) {
            throw new HttpException('This file has expired. Upload it again.', 410);
        }
        $fh = fopen($file, 'rb');
        $first = json_decode((string) fgets($fh), true);
        if (!is_array($first)) {
            fclose($fh);
            throw new HttpException('This file has expired. Upload it again.', 410);
        }
        $columns = self::checkColumns($columns, count($first));
        $pick = static function (array $row) use ($columns): array {
            $out = [];
            foreach ($columns as $key => $col) {
                $out[$key] = $col === null ? '' : (string) ($row[$col] ?? '');
            }

            return $out;
        };

        if ($offset === 0) {
            if (!$hasHeader) {
                rewind($fh);
            }
            $pos = ftell($fh);
            // Count rows and the widest row without decoding the file into memory.
            $total = 0;
            $width = count($first);
            while (($line = fgets($fh)) !== false) {
                $total++;
                $width = max($width, substr_count($line, '","') + 1);
            }
            $shared = $columns['name'] === null && $columns['ic'] === null
                ? []
                : $this->cleanser->sharedFromFile($file, $columns, $hasHeader);
            $headers = [];
            for ($i = 0; $i < $width; $i++) {
                $h = $hasHeader ? trim((string) ($first[$i] ?? '')) : '';
                $headers[] = $h !== '' ? $h : 'Column ' . ($i + 1);
            }
            $state = [
                'offset' => 0, 'pos' => $pos, 'total' => $total, 'headers' => $headers,
                'seen' => [], 'shared' => $shared, 'sum' => ['valid' => 0, 'invalid' => 0, 'corrected' => 0], 'reasons' => [],
            ];
            file_put_contents($this->path($token, 'results.jsonl'), '');
        } else {
            $state = json_decode((string) @file_get_contents($this->path($token, 'state.json')), true);
            if (!is_array($state) || ($state['offset'] ?? -1) !== $offset) {
                fclose($fh);
                throw new HttpException('The cleanse went out of step. Start it again.', 409);
            }
        }

        $rows = [];
        fseek($fh, (int) $state['pos']);
        while (count($rows) < self::BATCH_ROWS && ($line = fgets($fh)) !== false) {
            $rows[] = json_decode($line, true) ?: [];
        }
        $state['pos'] = ftell($fh);
        fclose($fh);

        $checks = $this->cleanser->check(array_map($pick, $rows));
        $seen = array_fill_keys($state['seen'], true);
        $sum = ['valid' => 0, 'invalid' => 0, 'corrected' => 0];
        $out = '';
        foreach ($rows as $i => $row) {
            $check = $checks[$i];
            $email = $check['email'];
            $reason = match (true) {
                !$check['ok'] && !$check['dns'] => $check['message'],
                isset($state['shared'][$email]) => 'Shared by ' . $state['shared'][$email] . ' distinct customers (counter/staff email)',
                isset($seen[$email])            => 'Duplicate of earlier valid email',
                !$check['ok']                   => $check['message'],
                default                         => null,
            };
            $result = ['cells' => array_values($row), 'ok' => $reason === null, 'email' => $email];
            if ($reason === null) {
                $seen[$email] = true;
                $sum['valid']++;
                $raw = strtolower(trim((string) ($row[$columns['email']] ?? '')));
                if ($email !== $raw) {
                    $sum['corrected']++;
                }
                $result += ['message' => $check['message'], 'flag' => $check['flag']];
            } else {
                $sum['invalid']++;
                $key = (string) preg_replace('/\d+ distinct/', 'N distinct', $reason);
                $state['reasons'][$key] = ($state['reasons'][$key] ?? 0) + 1;
                $result += ['message' => $reason, 'flag' => ''];
            }
            $out .= json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        }
        file_put_contents($this->path($token, 'results.jsonl'), $out, FILE_APPEND);
        foreach ($sum as $k => $n) {
            $state['sum'][$k] += $n;
        }

        $processed = count($rows);
        $next = $offset + $processed < $state['total'] && $processed > 0 ? $offset + $processed : null;
        $response = [
            'total'     => (int) $state['total'],
            'offset'    => $offset,
            'processed' => $processed,
            'next'      => $next,
            'sum'       => $sum,
        ];

        if ($next === null) {
            $this->cleanser->export(
                $this->path($token, 'results.jsonl'),
                $state['headers'],
                $this->path($token, 'valid.xlsx'),
                $this->path($token, 'invalid.xlsx'),
            );
            foreach (['rows.jsonl', 'results.jsonl', 'state.json'] as $kind) {
                @unlink($this->path($token, $kind));
            }
            arsort($state['reasons']);
            $response['totals'] = $state['sum'];
            $response['reasons'] = $state['reasons'];
        } else {
            $state['offset'] = $next;
            $state['seen'] = array_keys($seen);
            file_put_contents($this->path($token, 'state.json'), json_encode($state, JSON_UNESCAPED_UNICODE));
        }

        return $response;
    }

    /**
     * Path and download name of a finished workbook.
     *
     * @return array{path: string, name: string}
     */
    public function download(string $token, string $kind): array
    {
        $path = $this->path($token, $kind . '.xlsx');
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !isset(self::DOWNLOADS[$kind]) || !is_file($path)) {
            throw new HttpException('This file has expired. Cleanse the list again.', 410);
        }
        $meta = json_decode((string) @file_get_contents($this->path($token, 'meta.json')), true);
        $base = pathinfo((string) ($meta['name'] ?? ''), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\w .()-]+/u', '', $base));

        return [
            'path' => $path,
            'name' => ($base !== '' ? $base . ' - ' : '') . self::DOWNLOADS[$kind] . '.xlsx',
        ];
    }

    /**
     * Column index per COLUMNS key (null = not in the file), checked against
     * the file width; email is required and a column is used once.
     *
     * @param array<string, mixed> $columns
     * @return array<string, int|null>
     */
    private static function checkColumns(array $columns, int $width): array
    {
        $out = [];
        foreach (self::COLUMNS as $key) {
            $v = $columns[$key] ?? null;
            $out[$key] = $v === null || $v === '' || (int) $v < 0 ? null : (int) $v;
            if ($out[$key] !== null && $out[$key] >= $width) {
                throw ValidationException::single('columns', 'Unknown column for ' . $key . '.');
            }
        }
        if ($out['email'] === null) {
            throw ValidationException::single('columns', 'Choose which column holds the email address.');
        }
        $used = array_filter($out, static fn (?int $c): bool => $c !== null);
        if (count($used) !== count(array_unique($used))) {
            throw ValidationException::single('columns', 'Each column can be used once.');
        }

        return $out;
    }

    /**
     * Suggested column per COLUMNS key from the header names, then the
     * column whose values look like email addresses.
     *
     * @param list<list<string>> $rows
     * @return array<string, int|null>
     */
    private static function suggest(array $rows, bool $hasHeader): array
    {
        $patterns = [
            'email'  => '/e-?mail|emel/',
            'ic'     => '/(^|[^a-z])(ic|nric|mykad|ic no|ic number)([^a-z]|$)/',
            'mobile' => '/mobile|phone|telefon|(^|[^a-z])(hp|tel|contact no)([^a-z]|$)/',
            'name'   => '/name|nama/',
        ];
        $out = array_fill_keys(self::COLUMNS, null);
        if ($hasHeader) {
            foreach ($rows[0] as $i => $header) {
                $h = strtolower(trim($header));
                foreach ($patterns as $key => $re) {
                    if ($out[$key] === null && !in_array($i, $out, true) && preg_match($re, $h) === 1) {
                        $out[$key] = $i;
                        break;
                    }
                }
            }
        }
        if ($out['email'] === null) {
            foreach (array_slice($rows, $hasHeader ? 1 : 0, 20) as $r) {
                foreach ($r as $i => $cell) {
                    if (!in_array($i, $out, true) && filter_var(trim($cell), FILTER_VALIDATE_EMAIL) !== false) {
                        $out['email'] = $i;
                        break 2;
                    }
                }
            }
        }

        return $out;
    }

    /** @param list<string> $row */
    private static function rowHasEmail(array $row): bool
    {
        foreach ($row as $cell) {
            if (filter_var(trim($cell), FILTER_VALIDATE_EMAIL) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Working / output file of a token, scoped to the staff member who uploaded it. */
    private function path(string $token, string $kind): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edm-clean-' . $this->staffId . '-' . $token . '.' . $kind;
    }

    private function purgeOld(): void
    {
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edm-clean-*') ?: [] as $file) {
            if (@filemtime($file) < time() - self::STAGE_TTL) {
                @unlink($file);
            }
        }
    }
}
