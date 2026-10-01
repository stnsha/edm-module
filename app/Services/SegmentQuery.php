<?php

declare(strict_types=1);

namespace Edm\Services;

use DateTimeImmutable;
use Edm\Core\Database;
use Edm\Core\ValidationException;
use Edm\Models\Campaign;
use Edm\Models\CustomField;
use Edm\Models\ListMember;
use Edm\Models\Segment;

/**
 * Contacts > Segments: turns a saved definition into SQL over
 * edm_list_members and answers "who matches".
 *
 * Definition (spec section 6: AND/OR across multiple condition groups):
 *   { match: all|any, groups: [ { match: all|any, rules: [ { field, op, value } ] } ] }
 *   The outer match combines the groups, each group's match its rules,
 *   e.g. (State is Selangor and Gender is F) or (RFM segment is VIP).
 *   field  a key of fields(): email, name, member_code, source,
 *          subscribed_at, field:<custom field key>, or activity:opened /
 *          activity:clicked (engagement history from edm_send_log)
 *   op     one of OPS for the field's type
 *   value  string, by op:
 *          number / YYYY-MM-DD date / select option;
 *          in_month: month 1-12 in any year (birthdays);
 *          age_between: "min-max" whole years (from a date of birth);
 *          in_last_days / older_than_days / within_days / not_within_days:
 *          a number of days;
 *          in_campaign / not_in_campaign: a campaign id;
 *          unused for NO_VALUE ops.
 *
 * A definition saved before groups existed ({ match, rules }) is read as one
 * group. normalize() always returns the grouped form.
 *
 * No tag condition: per the spec (13.1 / 13.2) tags and scores such as
 * LOFRA, RFM and Engagement Score are computed by BI in the Customer Data
 * Warehouse and arrive as contact data (custom fields until that sync
 * exists), so segments filter on those fields. Custom fields carry a spec
 * filter category (CustomField::CATEGORIES) that groups them in the field
 * picker.
 *
 * Rules saved by the first free-text builder ({ field: "gender", op: "is not" })
 * are upgraded by normalize(): a bare custom field key becomes field:<key>,
 * "is not" / ">" / "<" become is_not / gt / lt.
 *
 * Matching always covers subscribed, non-deleted members only (the people a
 * campaign can reach), optionally within one list. Used by the Segments
 * screen (live count + sample), the campaign segment picker and
 * Ses\CampaignSender (recipients).
 */
final class SegmentQuery
{
    public const MATCH = ['all', 'any'];

    /** Operators per field type, in display order. */
    public const OPS = [
        'text'     => ['is', 'is_not', 'contains', 'not_contains', 'starts_with', 'is_empty', 'is_not_empty'],
        'number'   => ['is', 'is_not', 'gt', 'lt', 'is_empty', 'is_not_empty'],
        'date'     => ['is', 'before', 'after', 'in_last_days', 'older_than_days', 'in_month', 'age_between', 'is_empty', 'is_not_empty'],
        'select'   => ['is', 'is_not', 'is_empty', 'is_not_empty'],
        'boolean'  => ['is_true', 'is_false'],
        'activity' => ['within_days', 'not_within_days', 'in_campaign', 'not_in_campaign', 'ever', 'never'],
    ];

    public const OP_LABELS = [
        'is'              => 'is',
        'is_not'          => 'is not',
        'contains'        => 'contains',
        'not_contains'    => 'does not contain',
        'starts_with'     => 'starts with',
        'gt'              => 'is greater than',
        'lt'              => 'is less than',
        'before'          => 'is before',
        'after'           => 'is after',
        'in_last_days'    => 'is in the last',
        'older_than_days' => 'is more than',
        'in_month'        => 'is in month',
        'age_between'     => 'age is between',
        'is_empty'        => 'is empty',
        'is_not_empty'    => 'is not empty',
        'is_true'         => 'is yes',
        'is_false'        => 'is no',
        'within_days'     => 'in the last',
        'not_within_days' => 'not in the last',
        'in_campaign'     => 'in campaign',
        'not_in_campaign' => 'not in campaign',
        'ever'            => 'at any time',
        'never'           => 'not at any time',
    ];

    /** Unit shown after the value, e.g. "is in the last 30 days". */
    public const OP_SUFFIX = [
        'in_last_days'    => 'days',
        'older_than_days' => 'days ago',
        'within_days'     => 'days',
        'not_within_days' => 'days',
        'age_between'     => 'years',
    ];

    /** Operators that take no value. */
    public const NO_VALUE = ['is_empty', 'is_not_empty', 'is_true', 'is_false', 'ever', 'never'];

    /** Operators whose value is a number of days. */
    private const DAY_OPS = ['in_last_days', 'older_than_days', 'within_days', 'not_within_days'];

    /** Conditions across all groups. */
    public const MAX_RULES = 20;

    public const MAX_GROUPS = 5;

    private const MAX_DAYS = 36500;

    private const MAX_AGE = 150;

    private const LEGACY_OPS = ['is not' => 'is_not', '>' => 'gt', '<' => 'lt'];

    /** Built-in contact columns: key => [label, type, column]. */
    private const COLUMNS = [
        'email'         => ['Email', 'text', 'email'],
        'name'          => ['Name', 'text', 'name'],
        'member_code'   => ['Member code', 'text', 'member_code'],
        'source'        => ['Source', 'text', 'source'],
        'subscribed_at' => ['Subscribed date', 'date', 'subscribed_at'],
    ];

    /** Engagement history from edm_send_log: key => [label, timestamp column]. */
    private const ACTIVITY = [
        'activity:opened'  => ['Opened an email', 'opened_at'],
        'activity:clicked' => ['Clicked a link in an email', 'clicked_at'],
    ];

    /** Field picker group of the activity fields (spec category). */
    private const ACTIVITY_GROUP = 'Engagement';

    /** Campaigns that can have opens / clicks: sending, completed, archived. */
    private const SENT_STATUSES = [Campaign::SENDING, Campaign::COMPLETED, Campaign::ARCHIVED];

    public function __construct(private Database $db)
    {
    }

    /**
     * Everything the condition builder needs (js/edm-crud.js type 'rules').
     *
     * @return array<string, mixed>
     */
    public static function catalog(): array
    {
        return [
            'fields'     => self::fields(),
            'ops'        => self::OPS,
            'op_labels'  => self::OP_LABELS,
            'op_suffix'  => self::OP_SUFFIX,
            'no_value'   => self::NO_VALUE,
            'max_rules'  => self::MAX_RULES,
            'max_groups' => self::MAX_GROUPS,
        ];
    }

    /**
     * Every field a rule can use: built-in columns, active custom fields
     * (grouped by their category, in CustomField::CATEGORIES order) and the
     * engagement history fields. options: [{ value, label }] for select
     * fields, and the sent campaigns for activity fields.
     *
     * @return array<string, array{label: string, type: string, group: string, options?: list<array{value: string, label: string}>}>
     */
    public static function fields(): array
    {
        $out = [];
        foreach (self::COLUMNS as $key => [$label, $type]) {
            $out[$key] = ['label' => $label, 'type' => $type, 'group' => 'Contact'];
        }

        $order = array_flip(CustomField::CATEGORIES);
        $custom = CustomField::where('`is_active` = 1');
        usort($custom, static fn (array $a, array $b): int =>
            [$order[$a['category'] ?? ''] ?? PHP_INT_MAX, $a['label']] <=> [$order[$b['category'] ?? ''] ?? PHP_INT_MAX, $b['label']]);
        foreach ($custom as $f) {
            $type = in_array($f['type'], ['text', 'number', 'date', 'boolean', 'select'], true) ? (string) $f['type'] : 'text';
            $group = isset($order[$f['category'] ?? '']) ? (string) $f['category'] : 'Custom fields';
            $entry = ['label' => (string) $f['label'], 'type' => $type, 'group' => $group];
            if ($type === 'select') {
                $entry['options'] = array_map(
                    static fn ($o): array => ['value' => (string) $o, 'label' => (string) $o],
                    array_values((array) ($f['options'] ?? []))
                );
            }
            $out['field:' . $f['key']] = $entry;
        }

        $campaigns = array_map(
            static fn (array $c): array => ['value' => (string) $c['id'], 'label' => (string) $c['name']],
            Campaign::where('`status` IN (?, ?, ?)', self::SENT_STATUSES)
        );
        foreach (self::ACTIVITY as $key => [$label]) {
            $out[$key] = ['label' => $label, 'type' => 'activity', 'group' => self::ACTIVITY_GROUP, 'options' => $campaigns];
        }

        return $out;
    }

    /**
     * Validated definition in storage form: always grouped, legacy rules
     * upgraded, select values in the option's own spelling, dates as
     * YYYY-MM-DD.
     *
     * @throws ValidationException
     * @return array{match: string, groups: list<array{match: string, rules: list<array{field: string, op: string, value: string}>}>}
     */
    public static function normalize(mixed $definition): array
    {
        if (!is_array($definition)) {
            throw ValidationException::single('definition', 'Add at least one condition.');
        }
        // Saved before condition groups existed: one group.
        if (!isset($definition['groups']) && isset($definition['rules'])) {
            $definition = ['match' => 'all', 'groups' => [['match' => $definition['match'] ?? 'all', 'rules' => $definition['rules']]]];
        }
        $match = (string) ($definition['match'] ?? 'all');
        if (!in_array($match, self::MATCH, true)) {
            throw ValidationException::single('definition', 'Choose whether all or any of the groups must match.');
        }
        $groups = is_array($definition['groups'] ?? null) ? array_values($definition['groups']) : [];
        // A group left without conditions is dropped, not an error.
        $groups = array_values(array_filter($groups, static fn ($g): bool => is_array($g) && !empty($g['rules']) && is_array($g['rules'])));
        if ($groups === []) {
            throw ValidationException::single('definition', 'Add at least one condition.');
        }
        if (count($groups) > self::MAX_GROUPS) {
            throw ValidationException::single('definition', 'Use at most ' . self::MAX_GROUPS . ' condition groups.');
        }
        if (array_sum(array_map(static fn (array $g): int => count($g['rules']), $groups)) > self::MAX_RULES) {
            throw ValidationException::single('definition', 'Use at most ' . self::MAX_RULES . ' conditions.');
        }

        $fields = self::fields();
        $many = count($groups) > 1;
        $out = [];
        foreach ($groups as $gi => $group) {
            $groupMatch = (string) ($group['match'] ?? 'all');
            if (!in_array($groupMatch, self::MATCH, true)) {
                throw ValidationException::single('definition', ($many ? 'Group ' . ($gi + 1) . ': ' : '') . 'choose whether all or any of the conditions must match.');
            }
            $rules = [];
            foreach (array_values($group['rules']) as $i => $rule) {
                $n = ($many ? 'Group ' . ($gi + 1) . ', condition ' : 'Condition ') . ($i + 1) . ': ';
                $rules[] = self::normalizeRule($fields, is_array($rule) ? $rule : [], $n);
            }
            $out[] = ['match' => $groupMatch, 'rules' => $rules];
        }

        return ['match' => $match, 'groups' => $out];
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $rule
     * @return array{field: string, op: string, value: string}
     */
    private static function normalizeRule(array $fields, array $rule, string $n): array
    {
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
        $value = in_array($op, self::NO_VALUE, true) ? '' : self::checkValue($field, $op, $value, $n);

        return ['field' => $key, 'op' => $op, 'value' => $value];
    }

    /**
     * A campaign's segment, checked against its list: it must still exist,
     * belong to that list (or to no list) and have valid conditions. Returns
     * the normalized definition, or null when the campaign has no segment.
     *
     * @throws ValidationException on segment_id
     * @return array{match: string, groups: list<array{match: string, rules: list<array{field: string, op: string, value: string}>}>}|null
     */
    public static function forCampaign(?int $segmentId, ?int $listId): ?array
    {
        if ($segmentId === null) {
            return null;
        }
        $segment = Segment::find($segmentId);
        if ($segment === null) {
            throw ValidationException::single('segment_id', 'The segment was deleted - choose another segment or none.');
        }
        // $listId null = the campaign goes to all lists: only a segment for every list fits.
        if ($segment['list_id'] !== null && (int) $segment['list_id'] !== $listId) {
            throw ValidationException::single('segment_id', $listId === null
                ? 'The segment "' . $segment['name'] . '" is for one list only - with All lists, choose a segment for all lists or none.'
                : 'The segment "' . $segment['name'] . '" is for another list - choose a segment for this list or none.');
        }
        try {
            return self::normalize($segment['definition']);
        } catch (ValidationException $e) {
            throw ValidationException::single('segment_id', 'The segment "' . $segment['name'] . '" needs fixing: ' . $e->getMessage());
        }
    }

    /**
     * One-line summary, e.g. "Gender is F and Email contains @gmail", or with
     * several groups "(State is Selangor and Gender is F) or (RFM segment is VIP)".
     */
    public static function describe(array $definition): string
    {
        try {
            $def = self::normalize($definition);
        } catch (ValidationException $e) {
            return 'Needs fixing: ' . $e->getMessage();
        }
        $fields = self::fields();
        $groups = array_map(static function (array $g) use ($fields): string {
            $parts = array_map(static fn (array $r): string => self::describeRule($fields[$r['field']], $r), $g['rules']);

            return implode($g['match'] === 'all' ? ' and ' : ' or ', $parts);
        }, $def['groups']);
        if (count($groups) === 1) {
            return $groups[0];
        }

        return '(' . implode(') ' . ($def['match'] === 'all' ? 'and' : 'or') . ' (', $groups) . ')';
    }

    /**
     * @param array{label: string, options?: list<array{value: string, label: string}>} $field
     * @param array{field: string, op: string, value: string} $r
     */
    private static function describeRule(array $field, array $r): string
    {
        $value = $r['value'];
        foreach ($field['options'] ?? [] as $o) {
            if ($o['value'] === $value) {
                $value = $o['label'];
            }
        }
        if ($r['op'] === 'in_month' && $value !== '') {
            $value = date('F', mktime(0, 0, 0, (int) $value, 1));
        }
        if ($r['op'] === 'age_between') {
            $value = str_replace('-', ' and ', $value);
        }
        $suffix = self::OP_SUFFIX[$r['op']] ?? '';

        return $field['label'] . ' ' . self::OP_LABELS[$r['op']]
            . ($value !== '' ? ' ' . $value : '') . ($suffix !== '' ? ' ' . $suffix : '');
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
        $groups = [];
        $params = [];
        foreach ($definition['groups'] as $group) {
            $parts = [];
            foreach ($group['rules'] as $rule) {
                [$sql, $p] = $this->rule($fields[$rule['field']], $rule, $alias);
                $parts[] = '(' . $sql . ')';
                array_push($params, ...$p);
            }
            $groups[] = '(' . implode($group['match'] === 'all' ? ' AND ' : ' OR ', $parts) . ')';
        }

        return ['(' . implode($definition['match'] === 'all' ? ' AND ' : ' OR ', $groups) . ')', $params];
    }

    /**
     * Subscribed contacts matching the definition (all subscribed contacts
     * when it is null), in one list or every list. Counted by address, so a
     * contact on several lists (or twice on one) counts once - as it is sent.
     *
     * @return array{matched: int, total: int, sample: list<array{email: string, name: ?string, list: ?string}>}
     */
    public function count(?array $definition, ?int $listId, int $sample = 5): array
    {
        [$scope, $scopeParams] = $this->scope($listId);
        $total = (int) $this->db->scalar('SELECT COUNT(DISTINCT LOWER(TRIM(m.`email`))) FROM `edm_list_members` m WHERE ' . $scope, $scopeParams);
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
            : (int) $this->db->scalar('SELECT COUNT(DISTINCT LOWER(TRIM(m.`email`))) FROM `edm_list_members` m WHERE ' . $where, $params);
        $rows = $sample > 0 ? $this->db->select(
            'SELECT m.`email`, m.`name`, l.`name` AS list FROM `edm_list_members` m
               LEFT JOIN `edm_lists` l ON l.`id` = m.`list_id`
              WHERE ' . $where . ' ORDER BY m.`id` DESC LIMIT ' . $sample,
            $params
        ) : [];

        return ['matched' => $matched, 'total' => $total, 'sample' => $rows];
    }

    /**
     * Of the contacts count() matches, how many addresses are on the
     * suppression list (the send queue skips them).
     */
    public function suppressedCount(?array $definition, ?int $listId): int
    {
        [$where, $params] = $this->scope($listId);
        if ($definition !== null) {
            [$cond, $condParams] = $this->where($definition);
            $where .= ' AND ' . $cond;
            $params = [...$params, ...$condParams];
        }

        return (int) $this->db->scalar(
            'SELECT COUNT(DISTINCT LOWER(TRIM(m.`email`))) FROM `edm_list_members` m
              WHERE ' . $where . '
                AND EXISTS (SELECT 1 FROM `edm_suppressions` s
                             WHERE s.`deleted_at` IS NULL AND LOWER(s.`email`) = LOWER(TRIM(m.`email`)))',
            $params
        );
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

        if ($field['type'] === 'activity') {
            return $this->activity(self::ACTIVITY[$rule['field']][1], $op, $v, $a);
        }

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
                // Every date form below starts with NOT (empty), so its
                // expression parameters come first, then the date's own.
                $date = 'STR_TO_DATE(TRIM(' . $expr . '), \'%Y-%m-%d\')';
                $guard = 'NOT (' . $empty . ') AND ';
                switch ($op) {
                    case 'in_month':
                        return [$guard . 'MONTH(' . $date . ') = ?', [...$exprParams, ...$exprParams, (int) $v]];
                    case 'in_last_days':
                        return [
                            $guard . $date . ' >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND ' . $date . ' <= CURDATE()',
                            [...$exprParams, ...$exprParams, (int) $v, ...$exprParams],
                        ];
                    case 'older_than_days':
                        return [$guard . $date . ' < DATE_SUB(CURDATE(), INTERVAL ? DAY)', [...$exprParams, ...$exprParams, (int) $v]];
                    case 'age_between':
                        [$min, $max] = array_map('intval', explode('-', $v));
                        return [$guard . 'TIMESTAMPDIFF(YEAR, ' . $date . ', CURDATE()) BETWEEN ? AND ?', [...$exprParams, ...$exprParams, $min, $max]];
                }
                $cmp = ['is' => '=', 'before' => '<', 'after' => '>'][$op];
                return [$guard . 'TRIM(' . $expr . ') ' . $cmp . ' ?', [...$exprParams, ...$exprParams, $v]];
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

    /**
     * Engagement history: an edm_send_log row for the contact's address with
     * $column (opened_at / clicked_at) set - in the last N days, in one
     * campaign, or ever; the not_ / never forms negate it.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function activity(string $column, string $op, string $v, string $a): array
    {
        $col = 's.' . Database::ident($column);
        $sql = 'SELECT 1 FROM `edm_send_log` s
                 WHERE s.`email` = LOWER(TRIM(' . $a . '.`email`)) AND s.`deleted_at` IS NULL AND ' . $col . ' IS NOT NULL';
        $params = [];
        if ($op === 'within_days' || $op === 'not_within_days') {
            $sql .= ' AND ' . $col . ' >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = (int) $v;
        } elseif ($op === 'in_campaign' || $op === 'not_in_campaign') {
            $sql .= ' AND s.`campaign_id` = ?';
            $params[] = (int) $v;
        }
        $negate = in_array($op, ['not_within_days', 'not_in_campaign', 'never'], true);

        return [($negate ? 'NOT ' : '') . 'EXISTS (' . $sql . ')', $params];
    }

    /** @param array{label: string, type: string, options?: list<array{value: string, label: string}>} $field */
    private static function checkValue(array $field, string $op, string $value, string $n): string
    {
        if ($value === '') {
            $what = match (true) {
                $op === 'in_month'                                  => 'choose a month for ',
                $op === 'in_campaign' || $op === 'not_in_campaign' => 'choose a campaign for ',
                in_array($op, self::DAY_OPS, true)                  => 'enter a number of days for ',
                $op === 'age_between'                               => 'enter an age range for ',
                default                                             => 'enter a value for ',
            };
            throw ValidationException::single('definition', $n . $what . $field['label'] . '.');
        }
        if ($op === 'in_month') {
            if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 12) {
                throw ValidationException::single('definition', $n . $field['label'] . ' needs a month (1-12).');
            }
            return (string) (int) $value;
        }
        if (in_array($op, self::DAY_OPS, true)) {
            if (!ctype_digit($value) || (int) $value < 1 || (int) $value > self::MAX_DAYS) {
                throw ValidationException::single('definition', $n . $field['label'] . ' needs a whole number of days (1-' . self::MAX_DAYS . ').');
            }
            return (string) (int) $value;
        }
        if ($op === 'age_between') {
            if (preg_match('/^(\d{1,3})-(\d{1,3})$/', $value, $m) !== 1
                || (int) $m[1] > (int) $m[2] || (int) $m[2] > self::MAX_AGE) {
                throw ValidationException::single('definition', $n . $field['label'] . ' needs an age range: two whole numbers, the first not above the second (0-' . self::MAX_AGE . ').');
            }
            return (int) $m[1] . '-' . (int) $m[2];
        }
        if ($op === 'in_campaign' || $op === 'not_in_campaign') {
            foreach ($field['options'] ?? [] as $o) {
                if ($o['value'] === $value) {
                    return $value;
                }
            }
            throw ValidationException::single('definition', $n . 'the campaign was not found or has not been sent.');
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
