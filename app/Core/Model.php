<?php

declare(strict_types=1);

namespace Edm\Core;

use DateTimeImmutable;

/**
 * Base table gateway for one edm_* table.
 *
 * A subclass declares TABLE, FILLABLE (writable columns), CASTS and ORDER.
 * Rows travel as associative arrays: prepare() converts a validated payload
 * to column values, present() converts a DB row to the JSON the pages read.
 *
 * Casts: int, bool, json (array <-> JSON text), datetime (DB 'Y-m-d H:i:s'
 * <-> 'd-m-Y H:i:s'), date (DB 'Y-m-d' <-> 'd-m-Y'). created_at / updated_at /
 * deleted_at are always datetime; created_at / updated_at are stamped
 * automatically in Asia/Kuala_Lumpur time. The pages never reformat dates -
 * they show the string they are given.
 *
 * Soft deletes: every edm_* table has deleted_at. delete() stamps it instead
 * of removing the row, and every read here only sees rows where it is NULL.
 * Hand-written SQL in a subclass must add the same `deleted_at IS NULL`.
 */
abstract class Model
{
    protected const TABLE = '';

    /** @var list<string> */
    protected const FILLABLE = [];

    /** @var array<string, string> */
    protected const CASTS = [];

    /** ORDER BY clause for all(); code-defined only, never user input. */
    protected const ORDER = '`id` ASC';

    public static function table(): string
    {
        return static::TABLE;
    }

    public static function db(): Database
    {
        return Database::get();
    }

    /** @return list<array<string, mixed>> */
    public static function all(?string $orderBy = null): array
    {
        return static::where('1 = 1', [], $orderBy);
    }

    /**
     * @param string $whereSql SQL condition with ? placeholders (code-defined)
     * @return list<array<string, mixed>>
     */
    public static function where(string $whereSql, array $params = [], ?string $orderBy = null, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM ' . Database::ident(static::TABLE) . ' WHERE `deleted_at` IS NULL AND (' . $whereSql . ')'
            . ' ORDER BY ' . ($orderBy ?? static::ORDER)
            . ($limit !== null ? ' LIMIT ' . max(1, $limit) : '');

        return array_map([static::class, 'present'], static::db()->select($sql, $params));
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $row = static::db()->first(
            'SELECT * FROM ' . Database::ident(static::TABLE) . ' WHERE `id` = ? AND `deleted_at` IS NULL',
            [$id]
        );

        return $row === null ? null : static::present($row);
    }

    /** @return array<string, mixed> */
    public static function findOrFail(int $id): array
    {
        $row = static::find($id);
        if ($row === null) {
            throw new HttpException('Record not found.', 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data validated payload
     * @return array<string, mixed> the stored row, presented
     */
    public static function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $row = static::prepare($data) + ['created_at' => $now, 'updated_at' => $now];
        $id = static::db()->insert(static::TABLE, $row);

        return static::findOrFail($id);
    }

    /**
     * @param array<string, mixed> $data validated payload (partial)
     * @return array<string, mixed> the updated row, presented
     */
    public static function update(int $id, array $data): array
    {
        $row = static::prepare($data);
        if ($row !== []) {
            $row['updated_at'] = date('Y-m-d H:i:s');
            static::db()->update(static::TABLE, $id, $row);
        }

        return static::findOrFail($id);
    }

    /** Soft delete: stamps deleted_at (and updated_at). */
    public static function delete(int $id): void
    {
        static::findOrFail($id);
        $now = date('Y-m-d H:i:s');
        static::db()->execute(
            'UPDATE ' . Database::ident(static::TABLE) . ' SET `deleted_at` = ?, `updated_at` = ? WHERE `id` = ?',
            [$now, $now, $id]
        );
    }

    /** Soft-delete every active row where $column = $value (child rows of a deleted parent). */
    public static function deleteWhere(string $column, mixed $value): void
    {
        $now = date('Y-m-d H:i:s');
        static::db()->execute(
            'UPDATE ' . Database::ident(static::TABLE) . ' SET `deleted_at` = ?, `updated_at` = ? WHERE '
            . Database::ident($column) . ' = ? AND `deleted_at` IS NULL',
            [$now, $now, $value]
        );
    }

    /** Clear a nullable foreign key on every active row pointing at $value. */
    public static function unlinkWhere(string $column, mixed $value): void
    {
        static::db()->execute(
            'UPDATE ' . Database::ident(static::TABLE) . ' SET ' . Database::ident($column) . ' = NULL, `updated_at` = ? WHERE '
            . Database::ident($column) . ' = ? AND `deleted_at` IS NULL',
            [date('Y-m-d H:i:s'), $value]
        );
    }

    /** @param array<string, mixed> $where column => value, ANDed; active rows only */
    public static function exists(array $where): bool
    {
        $conds = array_map(static fn (string $c): string => Database::ident($c) . ' = ?', array_keys($where));
        $sql = 'SELECT 1 FROM ' . Database::ident(static::TABLE) . ' WHERE `deleted_at` IS NULL AND '
            . implode(' AND ', $conds) . ' LIMIT 1';

        return static::db()->first($sql, array_values($where)) !== null;
    }

    /**
     * Payload -> column values: FILLABLE only, casts applied.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected static function prepare(array $data): array
    {
        $out = [];
        foreach ($data as $col => $value) {
            if (!in_array($col, static::FILLABLE, true)) {
                continue;
            }
            $out[$col] = static::castIn(static::CASTS[$col] ?? null, $value);
        }

        return $out;
    }

    /**
     * DB row -> JSON-ready array.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $casts = static::CASTS + ['created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime'];
        foreach ($casts as $col => $cast) {
            if (array_key_exists($col, $row)) {
                $row[$col] = static::castOut($cast, $row[$col]);
            }
        }

        return $row;
    }

    private static function castIn(?string $cast, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($cast) {
            'int' => (int) $value,
            'bool' => $value ? 1 : 0,
            'json' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'datetime' => self::parseDate((string) $value)?->format('Y-m-d H:i:s'),
            'date' => self::parseDate((string) $value)?->format('Y-m-d'),
            default => $value,
        };
    }

    private static function castOut(string $cast, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($cast) {
            'int' => (int) $value,
            'bool' => (bool) $value,
            'json' => json_decode((string) $value, true),
            'datetime' => self::parseDate((string) $value)?->format('d-m-Y H:i:s'),
            'date' => self::parseDate((string) $value)?->format('d-m-Y'),
            default => $value,
        };
    }

    /**
     * Accepts DB format, <input type="datetime-local"> / <input type="date">
     * values and the d-m-Y display format the API hands out.
     */
    public static function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd-m-Y H:i'] as $format) {
            $d = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($d !== false) {
                return $d;
            }
        }
        foreach (['Y-m-d', 'd-m-Y'] as $format) {
            $d = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($d !== false) {
                return $d;
            }
        }
        $ts = strtotime($value);

        return $ts === false ? null : (new DateTimeImmutable())->setTimestamp($ts);
    }
}
