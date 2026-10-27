<?php

declare(strict_types=1);

namespace Edm\Services\Import;

use Edm\Core\ValidationException;
use PhpOffice\PhpSpreadsheet\Reader\Exception as SpreadsheetReaderException;
use PhpOffice\PhpSpreadsheet\Reader\Ods;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use ValueError;

/**
 * Turns an uploaded contacts file, or pasted text, into rows of strings.
 * Accepted like GetResponse's import: .XLS up to 10 MB; .CSV, .TXT, .VCF,
 * .XLSX, .ODS up to 50 MB. CSV / TXT / pasted text: delimiter (comma,
 * semicolon, tab, pipe) and encoding (UTF-8 or Windows-1252) are detected.
 */
final class ImportFileReader
{
    public const MAX_ROWS = 200000;

    private const MB = 1048576;
    private const LIMITS = ['xls' => 10, 'csv' => 50, 'txt' => 50, 'vcf' => 50, 'xlsx' => 50, 'ods' => 50];

    /**
     * @param array{name: string, tmp_name: string, size: int, error: int} $file a $_FILES entry
     * @return list<list<string>>
     */
    public static function fromUpload(array $file): array
    {
        $ext = self::checkUpload($file, self::LIMITS);

        $rows = match ($ext) {
            'csv', 'txt' => self::fromText((string) file_get_contents($file['tmp_name'])),
            'vcf'        => self::fromVcard((string) file_get_contents($file['tmp_name'])),
            default      => self::fromSpreadsheet($file['tmp_name'], $ext),
        };

        return self::finish($rows);
    }

    /**
     * Large-file path (Email cleaner): writes the rows of an upload to $out,
     * one JSON list of strings per line, without holding the sheet in PHP
     * memory - .xlsx streamed by Python, .csv / .txt line by line. .xls /
     * .ods / .vcf are small enough (size caps) to go through the in-memory
     * readers. Empty rows are skipped and trailing empty cells dropped.
     *
     * @param array{name: string, tmp_name: string, size: int, error: int} $file a $_FILES entry
     * @param array<string, int> $limitsMb size cap per extension, in MB
     * @return int rows written
     * @throws ValidationException when the file is missing, too big, unreadable, empty or over $maxRows
     */
    public static function toFile(array $file, string $out, int $maxRows, array $limitsMb = self::LIMITS): int
    {
        $ext = self::checkUpload($file, $limitsMb);
        $path = (string) $file['tmp_name'];

        if ($ext === 'xlsx') {
            $written = EmailCleanser::fromEnv()->workbookToFile($path, $out, $maxRows + 1);
        } elseif ($ext === 'csv' || $ext === 'txt') {
            $written = self::textToFile($path, $out, $maxRows + 1);
        } else {
            $rows = self::finish($ext === 'vcf' ? self::fromVcard((string) file_get_contents($path)) : self::fromSpreadsheet($path, $ext));
            $fh = fopen($out, 'wb');
            foreach ($rows as $row) {
                fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            }
            fclose($fh);
            $written = count($rows);
        }

        if ($written === 0) {
            throw ValidationException::single('file', 'No rows were found in the file.');
        }
        if ($written > $maxRows) {
            throw ValidationException::single('file', 'Up to ' . number_format($maxRows) . ' rows at a time - split the file.');
        }

        return $written;
    }

    /**
     * Extension of a valid upload, checked against the size caps.
     *
     * @param array<string, mixed> $file a $_FILES entry
     * @param array<string, int> $limitsMb
     */
    private static function checkUpload(array $file, array $limitsMb): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::single('file', 'Choose a file to upload.');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!isset($limitsMb[$ext])) {
            throw ValidationException::single('file', 'Use a .' . implode(', .', array_map('strtoupper', array_keys($limitsMb))) . ' file.');
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > $limitsMb[$ext] * self::MB) {
            throw ValidationException::single('file', 'A .' . strtoupper($ext) . ' file can be up to ' . $limitsMb[$ext] . ' MB.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw ValidationException::single('file', 'The upload failed. Please try again.');
        }

        return $ext;
    }

    /**
     * CSV / TXT to a JSON-lines file, line by line (delimiter from the first
     * non-empty line, encoding per line). A quoted cell spanning lines is
     * not supported, as in fromText().
     */
    private static function textToFile(string $path, string $out, int $maxRows): int
    {
        $in = fopen($path, 'rb');
        $fh = fopen($out, 'wb');
        $delimiter = null;
        $written = 0;
        while ($written < $maxRows && ($line = fgets($in)) !== false) {
            $line = rtrim(self::utf8($line), "\r\n");
            if (trim($line) === '') {
                continue;
            }
            $delimiter ??= self::delimiter($line);
            $cells = array_map(static fn ($v): string => trim((string) $v), str_getcsv($line, $delimiter, '"', ''));
            while ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }
            if ($cells === []) {
                continue;
            }
            fwrite($fh, json_encode($cells, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            $written++;
        }
        fclose($in);
        fclose($fh);

        return $written;
    }

    /** @return list<list<string>> */
    public static function fromPaste(string $text): array
    {
        if (trim($text) === '') {
            throw ValidationException::single('paste', 'Paste at least one contact.');
        }

        return self::finish(self::fromText($text));
    }

    /** @return list<list<string>> */
    private static function fromText(string $text): array
    {
        $text = self::utf8($text);
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $first = '';
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $first = $line;
                break;
            }
        }
        $delimiter = self::delimiter($first);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, $delimiter, '"', '');
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }

        return $rows;
    }

    /** Minimal vCard: one row per card, Name + Email columns. */
    private static function fromVcard(string $text): array
    {
        $text = self::utf8($text);
        // Unfold continuation lines (RFC 6350 3.2).
        $text = preg_replace("/\r?\n[ \t]/", '', $text) ?? $text;
        $rows = [['Name', 'Email']];
        foreach (preg_split('/BEGIN:VCARD/i', $text) ?: [] as $card) {
            $name = preg_match('/^FN[^:\r\n]*:(.*)$/mi', $card, $m) ? trim($m[1]) : '';
            $email = preg_match('/^(?:item\d+\.)?EMAIL[^:\r\n]*:(.*)$/mi', $card, $m) ? trim($m[1]) : '';
            if ($email !== '') {
                $rows[] = [$name, $email];
            }
        }

        return $rows;
    }

    /**
     * .xlsx goes through Python (EmailCleanser::readWorkbook, streamed -
     * PhpSpreadsheet holds ~1 KB per cell and a 6 MB workbook exhausts PHP's
     * memory); .xls / .ods (openpyxl cannot read them, and .xls is capped at
     * 10 MB) through PhpSpreadsheet.
     *
     * @return list<list<string>>
     */
    private static function fromSpreadsheet(string $path, string $ext): array
    {
        if ($ext === 'xlsx') {
            return EmailCleanser::fromEnv()->readWorkbook($path, self::MAX_ROWS + 2);
        }
        $reader = match ($ext) {
            'xls'  => new Xls(),
            'ods'  => new Ods(),
            default => new Xlsx(),
        };
        $reader->setReadDataOnly(true);
        try {
            // Only the first sheet of a multi-sheet workbook is imported.
            $sheet = $reader->load($path)->getSheet(0);
        } catch (SpreadsheetReaderException | ValueError $e) {
            throw ValidationException::single('file', 'The file could not be read as .' . strtoupper($ext) . '.');
        }

        return $sheet->toArray('', true, false, false);
    }

    /**
     * Trim cells, drop empty rows and trailing empty columns, cap the size.
     *
     * @param array<int, array<int, mixed>> $rows
     * @return list<list<string>>
     */
    private static function finish(array $rows): array
    {
        // Built in place, row by row, so a large file is never held twice.
        $out = [];
        foreach ($rows as $k => $row) {
            unset($rows[$k]);
            $cells = array_map(static fn ($v): string => trim(self::utf8((string) $v)), array_values($row));
            if (implode('', $cells) === '') {
                continue;
            }
            $out[] = $cells;
        }
        unset($rows);
        if ($out === []) {
            throw ValidationException::single('file', 'No contacts were found in the file.');
        }
        if (count($out) > self::MAX_ROWS + 1) {
            throw ValidationException::single('file', 'Import up to ' . number_format(self::MAX_ROWS) . ' contacts at a time - split the file.');
        }
        $width = 0;
        foreach ($out as $cells) {
            for ($i = count($cells) - 1; $i >= 0; $i--) {
                if ($cells[$i] !== '') {
                    $width = max($width, $i + 1);
                    break;
                }
            }
        }

        foreach ($out as &$cells) {
            if (count($cells) !== $width) {
                $cells = array_pad(array_slice($cells, 0, $width), $width, '');
            }
        }
        unset($cells);

        return $out;
    }

    private static function delimiter(string $line): string
    {
        $best = ',';
        $bestCount = 0;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($line, $d);
            if ($n > $bestCount) {
                $best = $d;
                $bestCount = $n;
            }
        }

        return $best;
    }

    private static function utf8(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            $s = substr($s, 3);
        }

        return mb_check_encoding($s, 'UTF-8') ? $s : (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
