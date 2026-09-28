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
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::single('file', 'Choose a file to upload.');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!isset(self::LIMITS[$ext])) {
            throw ValidationException::single('file', 'Use a .CSV, .TXT, .VCF, .XLS, .XLSX or .ODS file.');
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > self::LIMITS[$ext] * self::MB) {
            throw ValidationException::single('file', 'A .' . strtoupper($ext) . ' file can be up to ' . self::LIMITS[$ext] . ' MB.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw ValidationException::single('file', 'The upload failed. Please try again.');
        }

        $rows = match ($ext) {
            'csv', 'txt' => self::fromText((string) file_get_contents($file['tmp_name'])),
            'vcf'        => self::fromVcard((string) file_get_contents($file['tmp_name'])),
            default      => self::fromSpreadsheet($file['tmp_name'], $ext),
        };

        return self::finish($rows);
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

    /** @return list<list<string>> */
    private static function fromSpreadsheet(string $path, string $ext): array
    {
        $reader = match ($ext) {
            'xls'  => new Xls(),
            'ods'  => new Ods(),
            default => new Xlsx(),
        };
        $reader->setReadDataOnly(true);
        try {
            $sheet = $reader->load($path)->getActiveSheet();
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
        $out = [];
        foreach ($rows as $row) {
            $cells = array_map(static fn ($v): string => trim(self::utf8((string) $v)), array_values($row));
            if (implode('', $cells) === '') {
                continue;
            }
            $out[] = $cells;
        }
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

        return array_map(static fn (array $c): array => array_pad(array_slice($c, 0, $width), $width, ''), $out);
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
