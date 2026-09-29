<?php

declare(strict_types=1);

namespace Edm\Services;

use DateTimeImmutable;
use Edm\Core\Database;
use Edm\Core\ValidationException;
use Edm\Models\CustomField;
use Edm\Models\ListMember;
use Edm\Models\Segment;

/**
 * Contacts > Segments: turns a saved definition into SQL over
 * edm_list_members and answers "who matches".
 *
 * Definition: { match: all|any, rules: [ { field, op, value } ] }.
 *   field  a key of fields(): email, name, member_code, source,
 *          subscribed_at, or field:<custom field key>
 *   op     one of OPS for the field's type
 *   value  string (number / YYYY-MM-DD date / select option);
 *          unused for is_empty, is_not_empty, is_true, is_false
 *
 * No tag condition: per the spec (13.1 / 13.2) tags and scores such as
 * LOFRA, RFM and Engagement Score are computed by BI in the Customer Data
 * Warehouse and arrive as contact data (custom fields until that sync
 * exists), so segments filter on those fields.
 *
 * Rules saved by the first free-text builder ({ field: "gender", op: "is not" })
 * are upgraded by normalize(): a bare custom field key becomes field:<key>,
 * "is not" / ">" / "<" become is_not / gt / lt.
 *
 * Matching always covers subscribed, non-deleted members only (the people a
 * newsletter can reach), optionally within one list. Used by the Segments
 * screen (live count + sample), the newsletter segment picker and
 * Ses\CampaignSender (recipients).
 */
final class SegmentQuery
{
    public const MATCH = ['all', 'any'];

    /** Operators per field type, in display order. */
    public const OPS = [
        'text'    => ['is', 'is_not', 'contains', 'not_contains', 'starts_with', 'is_empty', 'is_not_empty'],
        'number'  => ['is', 'is_not', 'gt', 'lt', 'is_empty', 'is_not_empty'],
        'date'    => ['is', 'before', 'after', 'is_empty', 'is_not_empty'],
        'select'  => ['is', 'is_not', 'is_empty', 'is_not_empty'],
        'boolean' => ['is_true', 'is_false'],
    ];

    public const OP_LABELS = [
        'is'           => 'is',
        'is_not'       => 'is not',
        'contains'     => 'contains',
        'not_contains' => 'does not contain',
        'starts_with'  => 'starts with',
        'gt'           => 'is greater than',
        'lt'           => 'is less than',
        'before'       => 'is before',
        'after'        => 'is after',
        'is_empty'     => 'is empty',
        'is_not_empty' => 'is not empty',
        'is_true'      => 'is yes',
        'is_false'     => 'is no',
    ];

    /** Operators that take no value. */
    public const NO_VALUE = ['is_empty', 'is_not_empty', 'is_true', 'is_false'];

    public const MAX_RULES = 20;

    private const LEGACY_OPS = ['is not' => 'is_not', '>' => 'gt', '<' => 'lt'];

    /** Built-in contact columns: key => [label, type, column]. */
    private const COLUMNS = [
        'email'         => ['Email', 'text', 'email'],
        'name'          => ['Name', 'text', 'name'],
        'member_code'   => ['Member code', 'text', 'member_code'],
        'source'        => ['Source', 'text', 'source'],
        'subscribed_at' => ['Subscribed date', 'date', 'subscribed_at'],
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * Every field a rule can use: built-in columns and active custom
     * fields. options: [{ value, label }] for select fields.
     *
     * @return array<string, array{label: string, type: string, group: string, options?: list<array{value: string, label: string}>}>
     */
    public static function fields(): array
    {
        $out = [];
        foreach (self::COLUMNS as $key => [$label, $type]) {
            $out[$key] = ['label' => $label, 'type' => $type, 'group' => 'Contact'];
        }
        foreach (CustomField::where('`is_active` = 1') as $f) {
            $type = in_array($f['type'], ['text', 'number', 'date', 'boolean', 'select'], true) ? (string) $f['type'] : 'text';
            $entry = ['label' => (string) $f['label'], 'type' => $type, 'group' => 'Custom fields'];
            if ($type === 'select') {
                $entry['options'] = array_map(
                    static fn ($o): array => ['value' => (string) $o, 'label' => (string) $o],
                    array_values((array) ($f['options'] ?? []))
                );
            }
            $out['field:' . $f['key']] = $entry;
        }

        return $out;
    }

    /**
     * Validated definition in storage form (legacy rules upgraded, select
     * values in the option's own spelling, dates as YYYY-MM-DD).
     *
     * @throws ValidationException
     * @return array{match: string, rules: list<array{field: string, op: string, value: string}>}
     */
    public static function normalize(mixed $definition): array
    {
        if (!is_array($definition)) {
            throw ValidationException::single('definition', 'Add at least one condition.');
        }
        $match = (string) ($definition['match'] ?? 'all');
        if (!in_array($match, self::MATCH, true)) {
            throw ValidationException::single('definition', 'Choose whether all or any of the conditions must match.');
        }
        $rules = is_array($definition['rules'] ?? null) ? array_values($definition['rules']) : [];
        if ($rules === []) {
            throw ValidationException::single('definition', 'Add at least one condition.');
        }
        if (count($rules) > self::MAX_RULES) {
            throw ValidationException::single('definition', 'Use at most ' . self::MAX_RULES . ' conditions.');
        }

        $fields = self::fields();
        $out = [];
        foreach ($rules as $i => $rule) {
            $n = 'Condition ' . ($i + 1) . ': ';
            $key = trim((string) ($rule['field'] ?? ''));
            if (!isset($fields[$key]) && isset($fields['field:' . $key])) {
                $key = 'field:' . $key;
            }
            if (!isset($fields[$key])) {
                throw ValidationException::single('definition', $n . ($key === '' ? 'choose a field.' : 'the field "' . $key . '" does not exist or is inactive.'));
            }
            $field = $fields[$key];
            $op = (string) ($rule['op'] ?? '');
            $op = self::LEGACY_OPS[$op] ?? $op;
            if (!in_array($op, self::OPS[$field['type']], true)) {
                throw ValidationException::single('definition', $n . 'choose how to compare ' . $field['label'] . '.');
            }
            $value = trim((string) ($rule['value'] ?? ''));
            if (in_array($op, self::NO_VALUE, true)) {
                $value = '';
            } else {
                $value = self::checkValue($field, $value, $n);
            }
            $out[] = ['field' => $key, 'op' => $op, 'value' => $value];
        }

        return ['match' => $match, 'rules' => $out];
    }

    /**
     * A newsletter's segment, checked against its list: it must still exist,
     * belong to that list (or to no list) and have valid conditions. Returns
     * the normalized definition, or null when the newsletter has no segment.
     *
     * @throws ValidationException on segment_id
     * @return array{match: string, rules: list<array{field: string, op: string, value: string}>}|null
     */
    public static function forNewsletter(?int $segmentId, ?int $listId): ?array
    {
        if ($segmentId === null) {
            return null;
        }
        $segment = Segment::find($segmentId);
        if ($segment === null) {
            throw ValidationException::single('segment_id', 'The segment was deleted - choose another segment or none.');
        }
        if ($segment['list_id'] !== null && (int) $segment['list_id'] !== $listId) {
            throw ValidationException::single('segment_id', 'The segment "' . $segment['name'] . '" is for another list - choose a segment for this list or none.');
        }
        try {
            return self::normalize($segment['definition']);
        } catch (ValidationException $e) {
            throw ValidationException::single('segment_id', 'The segment "' . $segment['name'] . '" needs fixing: ' . $e->getMessage());
        }
    }

    /** One-line summary, e.g. "Gender is F and Email contains @gmail". */
    public static function describe(array $definition): string
    {
        try {
            $def = self::normalize($definition);
        } catch (ValidationException $e) {
            return 'Needs fixing: ' . $e->getMessage();
        }
        $fields = self::fields();
        $parts = array_map(static function (array $r) use ($fields): string {
            $f = $fields[$r['field']];
            $value = $r['value'];
            foreach ($f['options'] ?? [] as $o) {
                if ($o['value'] === $value) {
                    $value = $o['label'];
                }
            }
            return $f['label'] . ' ' . self::OP_LABELS[$r['op']] . ($value !== '' ? ' ' . $value : '');
        }, $def['rules']);

        return implode($def['match'] === 'all' ? ' and ' : ' or ', $parts);
    }

    /**
     * SQL condition over edm_list_members aliased $alias, plus its bound
     * values. The definition must come from normalize().
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function where(array $definition, string $alias = 'm'): array
    {
        $fields = self::fields();
        $parts = [];
        $params = [];
        foreach ($definition['rules'] as $rule) {
            [$sql, $p] = $this->rule($fields[$rule['field']], $rule, $alias);
            $parts[] = '(' . $sql . ')';
            array_push($params, ...$p);
        }

        return ['(' . implode($definition['match'] === 'all' ? ' AND ' : ' OR ', $parts) . ')', $params];
    }

    /**
     * Subscribed members matching the definition (all subscribed members when
     * it is null), in one list or every list.
     *
     * @return array{matched: int, total: int, sample: list<array{email: string, name: ?string, list: ?string}>}
     */
    public function count(?array $definition, ?int $listId, int $sample = 5): array
    {
        [$scope, $scopeParams] = $this->scope($listId);
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM `edm_list_members` m WHERE ' . $scope, $scopeParams);
        if ($definition === null) {
            $where = $scope;
            $params = $scopeParams;
        } else {
            [$cond, $condParams] = $this->where($definition);
            $where = $scope . ' AND ' . $cond;
            $params = [...$scopeParams, ...$condParams];
        }
        $matched = $definition === null
            ? $total
            : (int) $this->db->scalar('SELECT COUNT(*) FROM `edm_list_members` m WHERE ' . $where, $params);
        $rows = $sample > 0 ? $this->db->select(
            'SELECT m.`email`, m.`name`, l.`name` AS list FROM `edm_list_members` m
               LEFT JOIN `edm_lists` l ON l.`id` = m.`list_id`
              WHERE ' . $where . ' ORDER BY m.`id` DESC LIMIT ' . $sample,
            $params
        ) : [];

        return ['matched' => $matched, 'total' => $total, 'sample' => $rows];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function scope(?int $listId): array
    {
        $sql = 'm.`deleted_at` IS NULL AND m.`status` = ?';
        $params = [ListMember::SUBSCRIBED];
        if ($listId !== null) {
            $sql .= ' AND m.`list_id` = ?';
            $params[] = $listId;
        }

        return [$sql, $params];
    }

    /**
     * @param array{label: string, type: string} $field
     * @param array{field: string, op: string, value: string} $rule
     * @return array{0: string, 1: list<mixed>}
     */
    private function rule(array $field, array $rule, string $a): array
    {
        $op = $rule['op'];
        $v = $rule['value'];

        // The value expression: a real column, or the custom field's JSON value.
        if (str_starts_with($rule['field'], 'field:')) {
            $expr = 'JSON_UNQUOTE(JSON_EXTRACT(' . $a . '.`fields`, ?))';
            $exprParams = ['$."' . substr($rule['field'], 6) . '"'];
        } else {
            $column = $a . '.' . Database::ident(self::COLUMNS[$rule['field']][2]);
            $expr = $rule['field'] === 'subscribed_at' ? 'DATE_FORMAT(' . $column . ', \'%Y-%m-%d\')' : $column;
            $exprParams = [];
        }
        $text = 'LOWER(TRIM(COALESCE(' . $expr . ', \'\')))';
        $empty = 'TRIM(COALESCE(' . $expr . ', \'\')) = \'\'';

        if ($op === 'is_empty') {
            return [$empty, $exprParams];
        }
        if ($op === 'is_not_empty') {
            return ['NOT (' . $empty . ')', $exprParams];
        }

        switch ($field['type']) {
            case 'number':
                $num = 'CAST(NULLIF(TRIM(' . $expr . '), \'\') AS DECIMAL(20,6))';
                $cmp = ['is' => '=', 'is_not' => '<>', 'gt' => '>', 'lt' => '<'][$op];
                return [$num . ' ' . $cmp . ' CAST(? AS DECIMAL(20,6))', [...$exprParams, $v]];
            case 'date':
                $cmp = ['is' => '=', 'before' => '<', 'after' => '>'][$op];
                return ['NOT (' . $empty . ') AND TRIM(' . $expr . ') ' . $cmp . ' ?', [...$exprParams, ...$exprParams, $v]];
            case 'boolean':
                return $op === 'is_true'
                    ? [$text . ' IN (\'1\', \'true\', \'yes\')', $exprParams]
                    : [$text . ' NOT IN (\'1\', \'true\', \'yes\')', $exprParams];
        }

        // text / select
        $like = static fn (string $s): string => addcslashes(mb_strtolower($s), '\\%_');
        return match ($op) {
            'is'           => [$text . ' = ?', [...$exprParams, mb_strtolower($v)]],
            'is_not'       => [$text . ' <> ?', [...$exprParams, mb_strtolower($v)]],
            'contains'     => [$text . ' LIKE ?', [...$exprParams, '%' . $like($v) . '%']],
            'not_contains' => [$text . ' NOT LIKE ?', [...$exprParams, '%' . $like($v) . '%']],
            'starts_with'  => [$text . ' LIKE ?', [...$exprParams, $like($v) . '%']],
        };
    }

    /** @param array{label: string, type: string, options?: list<array{value: string, label: string}>} $field */
    private static function checkValue(array $field, string $value, string $n): string
    {
        if ($value === '') {
            throw ValidationException::single('definition', $n . 'enter a value for ' . $field['label'] . '.');
        }
        switch ($field['type']) {
            case 'number':
                if (!is_numeric($value)) {
                    throw ValidationException::single('definition', $n . $field['label'] . ' needs a number.');
                }
                return $value;
            case 'date':
                foreach (['Y-m-d', 'd-m-Y'] as $format) {
                    $d = DateTimeImmutable::createFromFormat('!' . $format, $value);
                    if ($d !== false && $d->format($format) === $value) {
                        return $d->format('Y-m-d');
                    }
                }
                throw ValidationException::single('definition', $n . $field['label'] . ' needs a date (YYYY-MM-DD).');
            case 'select':
                foreach ($field['options'] ?? [] as $o) {
                    if (strcasecmp($o['value'], $value) === 0) {
                        return $o['value'];
                    }
                }
                throw ValidationException::single('definition', $n . '"' . $value . '" is not one of the ' . $field['label'] . ' options.');
            default:
                return mb_substr($value, 0, 255);
        }
    }
}
