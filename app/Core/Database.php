<?php

declare(strict_types=1);

namespace Edm\Core;

use InvalidArgumentException;
use mysqli;
use mysqli_stmt;

/**
 * Thin prepared-statement layer over odb's mysqli connection. Every value is
 * bound; identifiers (table / column names) only ever come from code and are
 * still checked by ident().
 */
final class Database
{
    private static ?Database $instance = null;

    private function __construct(private mysqli $conn)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->conn->set_charset('utf8mb4');
    }

    public static function boot(mysqli $conn): void
    {
        self::$instance = new self($conn);
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new InvalidArgumentException('Database not booted - include app/bootstrap.php first.');
        }

        return self::$instance;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->run($sql, $params);
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $rows = $this->select($sql, $params);

        return $rows[0] ?? null;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $row = $this->first($sql, $params);

        return $row === null ? null : reset($row);
    }

    /** Runs a write statement; returns affected rows. */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->run($sql, $params);
        $affected = $stmt->affected_rows;
        $stmt->close();

        return (int) $affected;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $cols = array_map([self::class, 'ident'], array_keys($data));
        $sql = 'INSERT INTO ' . self::ident($table) . ' (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($data), '?')) . ')';
        $this->execute($sql, array_values($data));

        return (int) $this->conn->insert_id;
    }

    /** @param array<string, mixed> $data */
    public function update(string $table, int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $sets = array_map(static fn (string $c): string => self::ident($c) . ' = ?', array_keys($data));
        $params = array_values($data);
        $params[] = $id;
        $this->execute('UPDATE ' . self::ident($table) . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ?', $params);
    }

    public function transaction(callable $fn): mixed
    {
        $this->conn->begin_transaction();
        try {
            $result = $fn($this);
            $this->conn->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /** Backtick-quoted identifier; rejects anything that is not [A-Za-z0-9_]. */
    public static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid identifier: ' . $name);
        }

        return '`' . $name . '`';
    }

    private function run(string $sql, array $params): mysqli_stmt
    {
        $stmt = $this->conn->prepare($sql);
        if ($params !== []) {
            $types = '';
            $values = [];
            foreach ($params as $p) {
                if (is_bool($p)) {
                    $types .= 'i';
                    $values[] = $p ? 1 : 0;
                } elseif (is_int($p)) {
                    $types .= 'i';
                    $values[] = $p;
                } elseif (is_float($p)) {
                    $types .= 'd';
                    $values[] = $p;
                } else {
                    $types .= 's';
                    $values[] = $p === null ? null : (string) $p;
                }
            }
            $stmt->bind_param($types, ...$values);
        }
        $stmt->execute();

        return $stmt;
    }
}
