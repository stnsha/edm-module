<?php

declare(strict_types=1);

namespace Edm\Core;

/**
 * Minimal Laravel-style validator for flat payloads.
 *
 * Rules: required, sometimes, nullable, string, integer, boolean, array,
 * email, date, max:N, min:N, in:a,b,c, unique:table,column, exists:table,column.
 *
 * unique / exists only consider active rows (deleted_at IS NULL): a
 * soft-deleted record never blocks re-creating it, and cannot be referenced.
 *
 * Semantics follow Laravel so the pages see the same behaviour as before:
 * '' is treated as null, only keys present in the input are returned,
 * `sometimes` skips an absent key, `nullable` lets null through untouched.
 * Returned values are normalised (integer -> int, boolean -> bool).
 */
final class Validator
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $rules
     * @param int|null $ignoreId row id to ignore for unique rules (updates)
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(array $input, array $rules, ?int $ignoreId = null): array
    {
        $out = [];
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $present = array_key_exists($field, $input);
            $value = $present ? $input[$field] : null;
            if (is_string($value) && trim($value) === '') {
                $value = null;
            }
            $label = str_replace('_', ' ', $field);

            if (!$present) {
                if (in_array('required', $fieldRules, true) && !in_array('sometimes', $fieldRules, true)) {
                    $errors[$field][] = "The {$label} field is required.";
                }
                continue;
            }
            if ($value === null) {
                if (in_array('required', $fieldRules, true)) {
                    $errors[$field][] = "The {$label} field is required.";
                } elseif (in_array('nullable', $fieldRules, true)) {
                    $out[$field] = null;
                } else {
                    $errors[$field][] = "The {$label} field must not be empty.";
                }
                continue;
            }

            $message = null;
            foreach ($fieldRules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $message = $this->check($name, $arg, $value, $label, $field, $fieldRules, $ignoreId);
                if ($message !== null) {
                    break;
                }
                // Normalise as we go so later rules (min/max) see the cast value.
                if ($name === 'integer') {
                    $value = (int) $value;
                } elseif ($name === 'boolean') {
                    $value = in_array($value, [true, 1, '1', 'true', 'on'], true);
                } elseif ($name === 'string') {
                    $value = trim((string) $value);
                }
            }
            if ($message !== null) {
                $errors[$field][] = $message;
                continue;
            }
            $out[$field] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $out;
    }

    /** @param list<string> $fieldRules */
    private function check(
        string $name,
        ?string $arg,
        mixed $value,
        string $label,
        string $field,
        array $fieldRules,
        ?int $ignoreId,
    ): ?string {
        $numeric = in_array('integer', $fieldRules, true);

        switch ($name) {
            case 'required':
            case 'sometimes':
            case 'nullable':
                return null;
            case 'string':
                return is_scalar($value) && !is_bool($value) ? null : "The {$label} field must be a string.";
            case 'integer':
                return (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value)))
                    ? null : "The {$label} field must be an integer.";
            case 'boolean':
                return in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off'], true)
                    ? null : "The {$label} field must be true or false.";
            case 'array':
                return is_array($value) ? null : "The {$label} field must be an array.";
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                    ? null : "The {$label} field must be a valid email address.";
            case 'date':
                return (is_string($value) && strtotime(str_replace('T', ' ', $value)) !== false)
                    ? null : "The {$label} field must be a valid date.";
            case 'max':
                if ($numeric) {
                    return (int) $value <= (int) $arg ? null : "The {$label} field must not be greater than {$arg}.";
                }
                return mb_strlen((string) $value) <= (int) $arg
                    ? null : "The {$label} field must not be greater than {$arg} characters.";
            case 'min':
                if ($numeric) {
                    return (int) $value >= (int) $arg ? null : "The {$label} field must be at least {$arg}.";
                }
                return mb_strlen((string) $value) >= (int) $arg
                    ? null : "The {$label} field must be at least {$arg} characters.";
            case 'in':
                $allowed = explode(',', (string) $arg);
                return in_array((string) $value, $allowed, true) ? null : "The selected {$label} is invalid.";
            case 'unique':
                [$table, $column] = array_pad(explode(',', (string) $arg), 2, $field);
                $sql = 'SELECT 1 FROM ' . Database::ident($table) . ' WHERE `deleted_at` IS NULL AND '
                    . Database::ident($column) . ' = ?';
                $params = [$value];
                if ($ignoreId !== null) {
                    $sql .= ' AND `id` != ?';
                    $params[] = $ignoreId;
                }
                return $this->db->first($sql . ' LIMIT 1', $params) === null
                    ? null : "The {$label} has already been taken.";
            case 'exists':
                [$table, $column] = array_pad(explode(',', (string) $arg), 2, 'id');
                $sql = 'SELECT 1 FROM ' . Database::ident($table) . ' WHERE `deleted_at` IS NULL AND '
                    . Database::ident($column) . ' = ? LIMIT 1';
                return $this->db->first($sql, [$value]) !== null ? null : "The selected {$label} is invalid.";
        }

        return null;
    }
}
