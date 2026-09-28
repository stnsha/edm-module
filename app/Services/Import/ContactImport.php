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
 *            mapping, then the temp file is removed.
 *
 * Contacts are matched on email within the list: a new address is added as
 * subscribed; an existing one gets its name / member code / field values
 * updated but keeps its status, so an unsubscribed contact is never
 * re-subscribed by an import. Suppressed addresses are imported (they stay on
 * the list) but the send queue never emails them.
 */
final class ContactImport
{
    public const TARGET_SKIP = 'skip';
    public const TARGET_EMAIL = 'email';
    public const TARGET_MEMBER_CODE = 'member_code';
    public const TARGET_NAME = 'name';
    public const FIELD_PREFIX = 'field:';

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
        if (file_put_contents($this->path($token), json_encode($rows, JSON_UNESCAPED_UNICODE)) === false) {
            throw new HttpException('The import could not be staged on the server.', 500);
        }

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
     * @param list<string> $mapping one target per column
     * @return array<string, int> summary counts
     */
    public function run(string $token, int $listId, array $mapping, bool $hasHeader): array
    {
        ContactList::findOrFail($listId);
        $rows = $this->load($token);
        $valid = array_column(self::targets(), 'value');
        $width = count($rows[0]);
        $mapping = array_pad(array_slice(array_values($mapping), 0, $width), $width, self::TARGET_SKIP);
        foreach ($mapping as $t) {
            if (!in_array($t, $valid, true)) {
                throw ValidationException::single('mapping', 'Unknown column target: ' . $t . '.');
            }
        }
        $emailCol = array_search(self::TARGET_EMAIL, $mapping, true);
        if ($emailCol === false) {
            throw ValidationException::single('mapping', 'Choose which column holds the email address.');
        }
        foreach (array_count_values(array_filter($mapping, static fn (string $t): bool => $t !== self::TARGET_SKIP)) as $t => $n) {
            if ($n > 1) {
                throw ValidationException::single('mapping', 'Each field can be used for one column only.');
            }
        }

        if ($hasHeader) {
            array_shift($rows);
        }

        // Existing members of this list, by lower-cased email.
        $existing = [];
        foreach ($this->db->select(
            'SELECT `id`, LOWER(TRIM(`email`)) AS email, `fields` FROM `edm_list_members` WHERE `list_id` = ? AND `deleted_at` IS NULL',
            [$listId]
        ) as $r) {
            $existing[$r['email']] = ['id' => (int) $r['id'], 'fields' => json_decode((string) $r['fields'], true) ?: []];
        }
        $suppressed = [];
        foreach ($this->db->select('SELECT LOWER(TRIM(`email`)) AS email FROM `edm_suppressions` WHERE `deleted_at` IS NULL') as $r) {
            $suppressed[$r['email']] = true;
        }

        $sum = ['rows' => count($rows), 'added' => 0, 'updated' => 0, 'invalid' => 0, 'duplicates' => 0, 'suppressed' => 0];
        $seen = [];
        $now = date('Y-m-d H:i:s');

        $this->db->transaction(function () use ($rows, $mapping, $emailCol, $listId, $now, &$existing, &$seen, &$sum, $suppressed): void {
            foreach ($rows as $row) {
                $email = strtolower(trim((string) ($row[$emailCol] ?? '')));
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
                    $sum['invalid']++;
                    continue;
                }
                if (isset($seen[$email])) {
                    $sum['duplicates']++;
                    continue;
                }
                $seen[$email] = true;
                if (isset($suppressed[$email])) {
                    $sum['suppressed']++;
                }

                $data = [];
                $fields = [];
                foreach ($mapping as $col => $target) {
                    $value = mb_substr(trim((string) ($row[$col] ?? '')), 0, 255);
                    if ($value === '' || $target === self::TARGET_SKIP || $target === self::TARGET_EMAIL) {
                        continue;
                    }
                    if (str_starts_with($target, self::FIELD_PREFIX)) {
                        $fields[substr($target, strlen(self::FIELD_PREFIX))] = $value;
                    } else {
                        $data[$target] = $value;
                    }
                }

                if (isset($existing[$email])) {
                    if ($fields !== []) {
                        $data['fields'] = $fields + $existing[$email]['fields'];
                    }
                    if ($data !== []) {
                        ListMember::update($existing[$email]['id'], $data);
                    }
                    $sum['updated']++;
                    continue;
                }

                // Plain insert (Model::create re-reads every row - too slow for
                // a 200K-row file); values are already in storage form.
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
                $sum['added']++;
            }
        });

        @unlink($this->path($token));

        return $sum;
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

    /** @return list<list<string>> */
    private function load(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file($this->path($token))) {
            throw new HttpException('This import has expired. Upload the file again.', 410);
        }
        $rows = json_decode((string) file_get_contents($this->path($token)), true);
        if (!is_array($rows) || $rows === []) {
            throw new HttpException('This import has expired. Upload the file again.', 410);
        }

        return $rows;
    }

    /** Temp file for a token, scoped to the staff member who staged it. */
    private function path(string $token): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edm-import-' . $this->staffId . '-' . $token . '.json';
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
