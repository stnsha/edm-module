<?php

declare(strict_types=1);

namespace Edm\Services\Import;

use Edm\Core\HttpException;
use Edm\Core\ValidationException;

/**
 * Runs the email cleansing rules of cleanse/clean_email_list.py (with
 * cleanse/clean_emails.py) through cleanse/import_check.py, for the contact
 * import (ContactImport) and the Email cleaner page (EmailListCleanse).
 * The Python scripts are the single source of the rules; this class only
 * passes rows in and results out as JSON over stdin / stdout.
 *
 * Amazon SES reports a bad address only after sending to it (bounce), so the
 * import uses these rules to keep invalid addresses off the masterlist in the
 * first place.
 *
 * Settings (edm/.env, see .env.example): EDM_PYTHON - the Python 3
 * interpreter with openpyxl installed (default "python"); EDM_CLEANSE_DIR -
 * the folder holding the scripts (default edm/cleanse). Domain DNS results
 * are cached in edm/cache/cleanse/domain_mx_cache.json, shared by every
 * import.
 */
final class EmailCleanser
{
    /** Seconds one call may take (DNS lookups for new domains, writing a 1M-row workbook). */
    private const TIMEOUT = 900;

    public function __construct(
        private string $python,
        private string $dir,
        private string $cacheFile,
    ) {
    }

    public static function fromEnv(): self
    {
        $edm = dirname(__DIR__, 3);

        return new self(
            python: self::env('EDM_PYTHON') ?? 'python',
            dir: rtrim(self::env('EDM_CLEANSE_DIR') ?? $edm . DIRECTORY_SEPARATOR . 'cleanse', '\\/'),
            cacheFile: $edm . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'cleanse' . DIRECTORY_SEPARATOR . 'domain_mx_cache.json',
        );
    }

    /**
     * One result per row, in order: ok, the cleaned address (domain typo /
     * alias corrected) and message - the failing rule when not ok, else the
     * correction note ('' when unchanged); dns - the failing rule is the DNS
     * check; flag - "Role address" for a valid role mailbox.
     *
     * @param list<array{email: string, name?: string, mobile?: string, ic?: string}> $rows
     * @return list<array{ok: bool, email: string, message: string, dns: bool, flag: string}>
     */
    public function check(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $results = $this->call(['mode' => 'check', 'cache' => $this->cacheFile, 'rows' => $rows])['results'] ?? null;
        if (!is_array($results) || count($results) !== count($rows)) {
            throw new HttpException('Email cleansing returned an unexpected answer.', 500);
        }

        return array_map(static fn (array $r): array => [
            'ok'      => (bool) ($r['ok'] ?? false),
            'email'   => (string) ($r['email'] ?? ''),
            'message' => (string) ($r['message'] ?? ''),
            'dns'     => (bool) ($r['dns'] ?? false),
            'flag'    => (string) ($r['flag'] ?? ''),
        ], $results);
    }

    /**
     * Cleaned addresses used by 5 or more distinct client names or IC
     * numbers in the whole file (counter / staff email), with that count.
     *
     * @param list<array{email: string, name?: string, ic?: string}> $rows
     * @return array<string, int>
     */
    public function shared(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $shared = $this->call(['mode' => 'shared', 'rows' => $rows])['shared'] ?? [];

        return is_array($shared) ? array_map('intval', $shared) : [];
    }

    /**
     * Writes the Valid and Invalid workbooks from a results file (one JSON
     * line per row: cells, ok, email, message, flag).
     *
     * @param list<string> $headers the file's column names
     * @return array{valid: int, invalid: int}
     */
    public function export(string $resultsFile, array $headers, string $validFile, string $invalidFile): array
    {
        $counts = $this->call([
            'mode'         => 'export',
            'results_file' => $resultsFile,
            'headers'      => array_values($headers),
            'valid_file'   => $validFile,
            'invalid_file' => $invalidFile,
        ]);

        return ['valid' => (int) ($counts['valid'] ?? 0), 'invalid' => (int) ($counts['invalid'] ?? 0)];
    }

    /**
     * Shared addresses (as shared()) read straight from a staged rows file
     * (one JSON list per line), so a large file never passes through PHP
     * memory or stdin. $columns: index per email / name / mobile / ic, null
     * when the file has no such column.
     *
     * @param array<string, int|null> $columns
     * @return array<string, int>
     */
    public function sharedFromFile(string $rowsFile, array $columns, bool $skipFirst): array
    {
        $shared = $this->call([
            'mode'       => 'shared',
            'rows_file'  => $rowsFile,
            'columns'    => $columns,
            'skip_first' => $skipFirst,
        ])['shared'] ?? [];

        return is_array($shared) ? array_map('intval', $shared) : [];
    }

    /**
     * Streams the first sheet of an .xlsx to $out, one JSON list of strings
     * per row (openpyxl read-only; PhpSpreadsheet holds ~1 KB of PHP memory
     * per cell and runs out on a large workbook). Empty rows are skipped,
     * at most $maxRows rows are written.
     *
     * @return int rows written
     * @throws ValidationException when the file is not a readable workbook
     */
    public function workbookToFile(string $path, string $out, int $maxRows): int
    {
        $answer = $this->call(['mode' => 'read', 'path' => $path, 'out' => $out, 'max_rows' => $maxRows]);
        if (isset($answer['error'])) {
            throw ValidationException::single('file', 'The file could not be read as .XLSX.');
        }

        return (int) ($answer['rows'] ?? 0);
    }

    /**
     * Rows of the first sheet of an .xlsx (workbookToFile() read back), for
     * the contact import, whose rows are capped at ImportFileReader::MAX_ROWS.
     *
     * @return list<list<string>>
     * @throws ValidationException when the file is not a readable workbook
     */
    public function readWorkbook(string $path, int $maxRows): array
    {
        $out = tempnam(sys_get_temp_dir(), 'edm-xlsx-');
        try {
            $this->workbookToFile($path, $out, $maxRows);
            $rows = [];
            $fh = fopen($out, 'rb');
            while (($line = fgets($fh)) !== false) {
                $rows[] = json_decode($line, true) ?: [];
            }
            fclose($fh);

            return $rows;
        } finally {
            @unlink($out);
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function call(array $request): array
    {
        $script = $this->dir . DIRECTORY_SEPARATOR . 'import_check.py';
        if (!is_file($script)) {
            throw new HttpException('Email cleansing is not installed (' . $script . ' not found).', 500);
        }
        $input = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $in = tmpfile();
        $out = tmpfile();
        $err = tmpfile();
        fwrite($in, (string) $input);
        rewind($in);

        // Files rather than pipes: a large batch on stdin and stderr chatter
        // cannot deadlock the call.
        @set_time_limit(self::TIMEOUT + 60);
        $proc = proc_open(
            [$this->python, $script],
            [0 => $in, 1 => $out, 2 => $err],
            $pipes,
            $this->dir,
            ['PYTHONIOENCODING' => 'utf-8'] + getenv(),
        );
        if (!is_resource($proc)) {
            throw new HttpException('Email cleansing could not start Python (' . $this->python . ').', 500);
        }
        $started = time();
        while (($status = proc_get_status($proc))['running']) {
            if (time() - $started > self::TIMEOUT) {
                proc_terminate($proc);
                proc_close($proc);
                throw new HttpException('Email cleansing took too long. Try the import again - finished domain checks are cached.', 504);
            }
            usleep(100000);
        }
        proc_close($proc);

        rewind($out);
        rewind($err);
        $answer = json_decode((string) stream_get_contents($out), true);
        if (!is_array($answer)) {
            $detail = trim((string) stream_get_contents($err));
            $lines = $detail === '' ? [] : preg_split('/\R/', $detail);
            throw new HttpException(
                'Email cleansing failed' . ($lines ? ': ' . mb_substr((string) end($lines), 0, 300) : ' (exit code ' . $status['exitcode'] . ').'),
                500
            );
        }

        return $answer;
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
