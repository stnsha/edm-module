<?php

declare(strict_types=1);

namespace Edm\Services\Import;

use Edm\Core\Database;
use Edm\Core\HttpException;
use Edm\Core\ValidationException;
use Edm\Models\ContactList;
use Edm\Models\CustomField;
use Edm\Models\ListMember;

/**
 * Contacts > Import contacts, in two steps like GetResponse:
 *
 *   stage()  parsed rows are kept in a server temp file under a random token
 *            (owned by the staff member) and a preview + suggested column
 *            mapping is returned;
 *   run()    the staged rows are written to the list with the confirmed
 *            mapping, one batch per call (the page shows progress and a log
 *            of every problem row); after the last batch the temp file is
 *            removed. A dry run checks the rows without writing.
 *
 * Contacts are matched on email within the list: a new address is added as
 * subscribed; an existing one gets its name / member code / field values
 * updated but keeps its status, so an unsubscribed contact is never
 * re-subscribed by an import. The update mode (GetResponse's "What should we
 * do with contact information?") can limit a run to new addresses only or to
 * existing contacts only. Suppressed addresses are imported (they stay on the
 * list) but the send queue never emails them.
 *
 * Custom field values are checked against the field type: text is clipped at
 * 255 characters, a date must be YYYY-MM-DD, a number numeric, a yes/no field
 * 1/0 (true/false, yes/no accepted), a select field one of its options. A
 * value that does not fit is dropped (the contact is still imported) and
 * counted in the summary.
 */
final class ContactImport
{
    public const TARGET_SKIP = 'skip';
    public const TARGET_EMAIL = 'email';
    public const TARGET_MEMBER_CODE = 'member_code';
    public const TARGET_NAME = 'name';
    public const FIELD_PREFIX = 'field:';

    public const MODE_ADD_UPDATE = 'add_update';
    public const MODE_ADD = 'add';
    public const MODE_UPDATE = 'update';
    public const MODES = [
        self::MODE_ADD_UPDATE => 'Add and update existing',
        self::MODE_ADD        => 'Only add new',
        self::MODE_UPDATE     => 'Only update existing',
    ];

    public const MAX_EMAIL = 128;
    public const MAX_VALUE = 255;

    /** Rows per import_run request (the page may ask for up to MAX_BATCH_ROWS). */
    public const BATCH_ROWS = 2000;
    public const MAX_BATCH_ROWS = 5000;

    private const PREVIEW_ROWS = 5;
    private const STAGE_TTL = 86400;

    public function __construct(private Database $db, private int $staffId)
    {
    }

    /**
     * @param list<list<string>> $rows
     * @return array<string, mixed> token, preview, suggested mapping, targets
     */
    public function stage(array $rows): array
    {
        $this->purgeOld();
        $token = bin2hex(random_bytes(16));
        // One JSON-encoded row per line, so run() can read a batch by seeking
        // instead of decoding the whole file on every request.
        $fh = @fopen($this->path($token), 'wb');
        if ($fh === false) {
            throw new HttpException('The import could not be staged on the server.', 500);
        }
        foreach ($rows as $row) {
            fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
        }
        fclose($fh);

        $hasHeader = !self::rowHasEmail($rows[0]);
        $width = count($rows[0]);
        $headers = $hasHeader ? $rows[0] : array_map(static fn (int $i): string => 'Column ' . ($i + 1), range(0, $width - 1));

        return [
            'token'      => $token,
            'total_rows' => count($rows),
            'has_header' => $hasHeader,
            'headers'    => $headers,
            'first_row'  => $rows[0],
            'sample'     => array_slice($rows, $hasHeader ? 1 : 0, self::PREVIEW_ROWS),
            'mapping'    => $this->suggest($rows, $hasHeader),
            'targets'    => self::targets(),
        ];
    }

    /**
     * Imports (or, with $dryRun, only checks) one batch of the staged rows.
     * The page calls it repeatedly - offset 0, then each returned `next` -
     * until `next` is null, so a large file never runs into the PHP time
     * limit and the page can show progress. Batches must run in order: the
     * read position and the emails already seen (for in-file duplicates) are
     * kept in a per-token state file between calls. A dry run writes nothing
     * and keeps the staged rows, so the real import can follow.
     *
     * @param list<string> $mapping one target per column
     * @return array{total: int, offset: int, processed: int, next: int|null,
     *               sum: array<string, int>, issues: list<array<string, mixed>>}
     */
    public function run(
        string $token,
        int $listId,
        array $mapping,
        bool $hasHeader,
        string $mode = self::MODE_ADD_UPDATE,
        int $offset = 0,
        int $limit = self::BATCH_ROWS,
        bool $dryRun = false,
    ): array {
        ContactList::findOrFail($listId);
        if (!isset(self::MODES[$mode])) {
            throw ValidationException::single('mode', 'Choose what to do with contact information.');
        }
        $limit = max(1, min($limit, self::MAX_BATCH_ROWS));
        $file = $this->path($token);
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file($file)) {
            throw new HttpException('This import has expired. Upload the file again.', 410);
        }
        $fh = fopen($file, 'rb');
        $first = json_decode((string) fgets($fh), true);
        if (!is_array($first)) {
            fclose($fh);
            throw new HttpException('This import has expired. Upload the file again.', 410);
        }
        $mapping = $this->checkMapping($mapping, count($first));
        $emailCol = (int) array_search(self::TARGET_EMAIL, $mapping, true);

        // Batch state: read position, total data rows, emails seen so far.
        if ($offset === 0) {
            if (!$hasHeader) {
                rewind($fh);
            }
            $pos = ftell($fh);
            $total = 0;
            while (fgets($fh) !== false) {
                $total++;
            }
            $state = ['offset' => 0, 'pos' => $pos, 'total' => $total, 'seen' => []];
        } else {
            $state = json_decode((string) @file_get_contents($this->statePath($token)), true);
            if (!is_array($state) || ($state['offset'] ?? -1) !== $offset) {
                fclose($fh);
                throw new HttpException('The import went out of step. Start it again from the column matching step.', 409);
            }
        }

        $rows = [];
        fseek($fh, (int) $state['pos']);
        while (count($rows) < $limit && ($line = fgets($fh)) !== false) {
            $rows[] = json_decode($line, true) ?: [];
        }
        $state['pos'] = ftell($fh);
        fclose($fh);

        $firstRowNumber = $offset + 1 + ($hasHeader ? 1 : 0);
        $sum = ['added' => 0, 'updated' => 0, 'ignored' => 0, 'invalid' => 0, 'duplicates' => 0, 'suppressed' => 0, 'values_dropped' => 0];
        $issues = [];
        $seen = array_fill_keys($state['seen'], true);

        // Existing members / suppressions, only for this batch's addresses.
        $emails = [];
        foreach ($rows as $row) {
            $e = strtolower(trim((string) ($row[$emailCol] ?? '')));
            if ($e !== '') {
                $emails[$e] = true;
            }
        }
        $existing = [];
        $suppressed = [];
        if ($emails !== []) {
            $in = implode(',', array_fill(0, count($emails), '?'));
            foreach ($this->db->select(
                'SELECT `id`, LOWER(TRIM(`email`)) AS email, `fields` FROM `edm_list_members`
                 WHERE `list_id` = ? AND `deleted_at` IS NULL AND LOWER(TRIM(`email`)) IN (' . $in . ')',
                [$listId, ...array_keys($emails)]
            ) as $r) {
                $existing[$r['email']] = ['id' => (int) $r['id'], 'fields' => json_decode((string) $r['fields'], true) ?: []];
            }
            foreach ($this->db->select(
                'SELECT LOWER(TRIM(`email`)) AS email FROM `edm_suppressions` WHERE `deleted_at` IS NULL AND LOWER(TRIM(`email`)) IN (' . $in . ')',
                array_keys($emails)
            ) as $r) {
                $suppressed[$r['email']] = true;
            }
        }

        $customFields = [];
        foreach (CustomField::where('`is_active` = 1') as $f) {
            $customFields[(string) $f['key']] = $f;
        }
        $now = date('Y-m-d H:i:s');

        $work = function () use ($rows, $mapping, $emailCol, $listId, $now, $mode, $customFields, $existing, $suppressed, $firstRowNumber, $dryRun, &$seen, &$sum, &$issues): void {
            foreach ($rows as $i => $row) {
                $rowNumber = $firstRowNumber + $i;
                $raw = trim((string) ($row[$emailCol] ?? ''));
                $email = strtolower($raw);
                $problem = match (true) {
                    $email === ''                                        => 'Email is empty.',
                    strlen($email) > self::MAX_EMAIL                     => 'Email is longer than ' . self::MAX_EMAIL . ' characters.',
                    filter_var($email, FILTER_VALIDATE_EMAIL) === false  => 'Email address is not valid.',
                    default                                              => null,
                };
                if ($problem !== null) {
                    $sum['invalid']++;
                    $issues[] = ['row' => $rowNumber, 'email' => mb_substr($raw, 0, 140), 'type' => 'invalid', 'message' => $problem];
                    continue;
                }
                if (isset($seen[$email])) {
                    $sum['duplicates']++;
                    $issues[] = ['row' => $rowNumber, 'email' => $email, 'type' => 'duplicate', 'message' => 'Repeated in the file - only the first row is used.'];
                    continue;
                }
                $seen[$email] = true;
                $isExisting = isset($existing[$email]);
                if (($isExisting && $mode === self::MODE_ADD) || (!$isExisting && $mode === self::MODE_UPDATE)) {
                    $sum['ignored']++;
                    continue;
                }
                if (isset($suppressed[$email])) {
                    $sum['suppressed']++;
                    $issues[] = ['row' => $rowNumber, 'email' => $email, 'type' => 'suppressed', 'message' => 'On the suppression list - imported but never emailed.'];
                }

                $data = [];
                $fields = [];
                foreach ($mapping as $col => $target) {
                    $value = trim((string) ($row[$col] ?? ''));
                    if ($value === '' || $target === self::TARGET_SKIP || $target === self::TARGET_EMAIL) {
                        continue;
                    }
                    if (str_starts_with($target, self::FIELD_PREFIX)) {
                        $key = substr($target, strlen(self::FIELD_PREFIX));
                        $field = $customFields[$key] ?? null;
                        $clean = self::fieldValue($field, $value);
                        if ($clean === null) {
                            $sum['values_dropped']++;
                            $issues[] = [
                                'row' => $rowNumber, 'email' => $email, 'type' => 'value',
                                'message' => '"' . mb_substr($value, 0, 60) . '" does not fit ' . ($field['label'] ?? $key) . ' (' . self::typeHint($field) . ') - value left out.',
                            ];
                            continue;
                        }
                        $fields[$key] = $clean;
                    } else {
                        $data[$target] = mb_substr($value, 0, self::MAX_VALUE);
                    }
                }

                if ($isExisting) {
                    if (!$dryRun) {
                        if ($fields !== []) {
                            $data['fields'] = $fields + $existing[$email]['fields'];
                        }
                        if ($data !== []) {
                            ListMember::update($existing[$email]['id'], $data);
                        }
                    }
                    $sum['updated']++;
                    continue;
                }

                if (!$dryRun) {
                    // Plain insert (Model::create re-reads every row - too slow
                    // for a 200K-row file); values are already in storage form.
                    $this->db->insert('edm_list_members', [
                        'list_id'       => $listId,
                        'email'         => $email,
                        'member_code'   => $data['member_code'] ?? '',
                        'name'          => $data['name'] ?? null,
                        'fields'        => $fields !== [] ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null,
                        'source'        => 'import',
                        'status'        => ListMember::SUBSCRIBED,
                        'subscribed_at' => $now,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                }
                $sum['added']++;
            }
        };
        if ($dryRun) {
            $work();
        } else {
            $this->db->transaction($work);
        }

        $processed = count($rows);
        $next = $offset + $processed < $state['total'] && $processed > 0 ? $offset + $processed : null;
        if ($next === null) {
            @unlink($this->statePath($token));
            if (!$dryRun) {
                @unlink($file);
            }
        } else {
            $state['offset'] = $next;
            $state['seen'] = array_keys($seen);
            file_put_contents($this->statePath($token), json_encode($state, JSON_UNESCAPED_UNICODE));
        }

        return [
            'total'     => (int) $state['total'],
            'offset'    => $offset,
            'processed' => $processed,
            'next'      => $next,
            'sum'       => $sum,
            'issues'    => $issues,
        ];
    }

    /**
     * Validated mapping padded to the file width: known targets only, an
     * email column, each field used once.
     *
     * @param list<string> $mapping
     * @return list<string>
     */
    private function checkMapping(array $mapping, int $width): array
    {
        $valid = array_column(self::targets(), 'value');
        $mapping = array_pad(array_slice(array_values($mapping), 0, $width), $width, self::TARGET_SKIP);
        foreach ($mapping as $t) {
            if (!in_array($t, $valid, true)) {
                throw ValidationException::single('mapping', 'Unknown column target: ' . $t . '.');
            }
        }
        if (!in_array(self::TARGET_EMAIL, $mapping, true)) {
            throw ValidationException::single('mapping', 'Choose which column holds the email address.');
        }
        foreach (array_count_values(array_filter($mapping, static fn (string $t): bool => $t !== self::TARGET_SKIP)) as $n) {
            if ($n > 1) {
                throw ValidationException::single('mapping', 'Each field can be used for one column only.');
            }
        }

        return $mapping;
    }

    /** @param array<string, mixed>|null $field */
    private static function typeHint(?array $field): string
    {
        return match ($field['type'] ?? 'text') {
            'number'  => 'a number',
            'date'    => 'a date as YYYY-MM-DD',
            'boolean' => '1 or 0',
            'select'  => 'one of: ' . implode(', ', array_map('strval', (array) ($field['options'] ?? []))),
            default   => 'text',
        };
    }

    /**
     * Rows of the downloadable import template (audience/api.php
     * import_template): a header row whose names are matched automatically -
     * email, name, member_code, then the key of every active custom field -
     * and two sample contacts with a valid value for each field type.
     *
     * @return list<list<string>>
     */
    public static function template(): array
    {
        $header = ['email', 'name', 'member_code'];
        $rows = [
            ['jane.tan@example.com', 'Jane Tan', 'M0001'],
            ['ali.ahmad@example.com', 'Ali Ahmad', 'M0002'],
        ];
        foreach (CustomField::where('`is_active` = 1') as $f) {
            $header[] = (string) $f['key'];
            $options = array_values(array_map('strval', (array) ($f['options'] ?? [])));
            [$a, $b] = match ((string) $f['type']) {
                'number'  => ['100', '250'],
                'date'    => ['2026-01-31', '2026-12-15'],
                'boolean' => ['1', '0'],
                'select'  => [$options[0] ?? '', $options[1] ?? ($options[0] ?? '')],
                default   => ['Sample text', ''],
            };
            $rows[0][] = $a;
            $rows[1][] = $b;
        }

        return [$header, ...$rows];
    }

    /**
     * A custom field value in storage form, or null when it does not fit the
     * field type (see the class comment).
     *
     * @param array<string, mixed>|null $field
     */
    private static function fieldValue(?array $field, string $value): ?string
    {
        switch ($field['type'] ?? 'text') {
            case 'number':
                return is_numeric($value) ? $value : null;
            case 'date':
                return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
            case 'boolean':
                return match (strtolower($value)) {
                    '1', 'true', 'yes' => '1',
                    '0', 'false', 'no' => '0',
                    default            => null,
                };
            case 'select':
                foreach ((array) ($field['options'] ?? []) as $option) {
                    if (strcasecmp((string) $option, $value) === 0) {
                        return (string) $option;
                    }
                }

                return null;
            default:
                return mb_substr($value, 0, self::MAX_VALUE);
        }
    }

    /**
     * Column targets for the mapping selects: the built-ins, then every
     * active custom field.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function targets(): array
    {
        $out = [
            ['value' => self::TARGET_SKIP, 'label' => 'Do not import'],
            ['value' => self::TARGET_EMAIL, 'label' => 'Email (required)'],
            ['value' => self::TARGET_NAME, 'label' => 'Name'],
            ['value' => self::TARGET_MEMBER_CODE, 'label' => 'Member code'],
        ];
        foreach (CustomField::where('`is_active` = 1') as $f) {
            $out[] = ['value' => self::FIELD_PREFIX . $f['key'], 'label' => $f['label'] . ' (custom field)'];
        }

        return $out;
    }

    /**
     * Suggested target per column: header names first, then the column whose
     * values look like email addresses.
     *
     * @param list<list<string>> $rows
     * @return list<string>
     */
    private function suggest(array $rows, bool $hasHeader): array
    {
        $aliases = [
            self::TARGET_EMAIL       => ['email', 'e-mail', 'email address', 'e-mail address', 'emel', 'mail'],
            self::TARGET_NAME        => ['name', 'full name', 'fullname', 'nama', 'contact name', 'first name', 'firstname'],
            self::TARGET_MEMBER_CODE => ['member code', 'member_code', 'membercode', 'member id', 'member no', 'membership no', 'code'],
        ];
        foreach (CustomField::where('`is_active` = 1') as $f) {
            $aliases[self::FIELD_PREFIX . $f['key']] = [strtolower((string) $f['key']), strtolower((string) $f['label'])];
        }

        $width = count($rows[0]);
        $mapping = array_fill(0, $width, self::TARGET_SKIP);
        $used = [];
        if ($hasHeader) {
            foreach ($rows[0] as $i => $header) {
                $h = strtolower(trim(str_replace('_', ' ', $header)));
                foreach ($aliases as $target => $names) {
                    if (!isset($used[$target]) && in_array($h, array_map(static fn (string $n): string => str_replace('_', ' ', $n), $names), true)) {
                        $mapping[$i] = $target;
                        $used[$target] = true;
                        break;
                    }
                }
            }
        }
        if (!isset($used[self::TARGET_EMAIL])) {
            $sample = array_slice($rows, $hasHeader ? 1 : 0, 20);
            for ($i = 0; $i < $width; $i++) {
                if ($mapping[$i] !== self::TARGET_SKIP) {
                    continue;
                }
                foreach ($sample as $r) {
                    if (filter_var(trim($r[$i] ?? ''), FILTER_VALIDATE_EMAIL) !== false) {
                        $mapping[$i] = self::TARGET_EMAIL;
                        break 2;
                    }
                }
            }
        }

        return $mapping;
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

    /** Temp file for a token, scoped to the staff member who staged it. */
    private function path(string $token): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edm-import-' . $this->staffId . '-' . $token . '.json';
    }

    /** Batch state (read position, emails seen) for a token's running import. */
    private function statePath(string $token): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edm-import-' . $this->staffId . '-' . $token . '.state.json';
    }

    private function purgeOld(): void
    {
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edm-import-*.json') ?: [] as $file) {
            if (@filemtime($file) < time() - self::STAGE_TTL) {
                @unlink($file);
            }
        }
    }
}
