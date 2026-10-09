<?php

declare(strict_types=1);

namespace Edm\Services;

use Edm\Core\Database;
use Edm\Core\ValidationException;
use Edm\Models\Contact;
use Edm\Models\Segment;

/**
 * Who a newsletter goes to: its `audience` JSON (edm_campaigns.audience)
 *
 *   { include: { segments: [id], contacts: [id] },
 *     exclude: { segments: [id], contacts: [id] } }
 *
 * Recipients are the subscribed contacts on the masterlist who are in any
 * included segment or are an included contact (nothing included = every
 * subscribed contact), minus anyone in an excluded segment or excluded as a
 * contact. The one place that turns an audience into SQL; used by the send
 * queue, Show recipients, scheduling checks and the Email creator count.
 */
final class CampaignRecipients
{
    public const EMPTY = [
        'include' => ['segments' => [], 'contacts' => []],
        'exclude' => ['segments' => [], 'contacts' => []],
    ];

    /** Most segments / contacts one side (include or exclude) may list. */
    public const MAX_SEGMENTS = 20;
    public const MAX_CONTACTS = 500;

    public function __construct(private Database $db)
    {
    }

    /**
     * An audience in normal form: both sides present, positive integer ids,
     * each once, in the given order. Unknown keys are dropped.
     *
     * @return array{include: array{segments: list<int>, contacts: list<int>}, exclude: array{segments: list<int>, contacts: list<int>}}
     */
    public static function shape(mixed $input): array
    {
        $in = is_string($input) ? json_decode($input, true) : $input;
        $out = self::EMPTY;
        if (!is_array($in)) {
            return $out;
        }
        foreach (['include', 'exclude'] as $side) {
            foreach (['segments', 'contacts'] as $kind) {
                $ids = [];
                foreach ((array) ($in[$side][$kind] ?? []) as $v) {
                    $id = (int) $v;
                    if ($id > 0 && !in_array($id, $ids, true)) {
                        $ids[] = $id;
                    }
                }
                $out[$side][$kind] = $ids;
            }
        }

        return $out;
    }

    /**
     * shape() plus checks for a save: limits, and every segment / contact
     * must exist (active rows).
     *
     * @throws ValidationException on audience
     */
    public static function normalize(mixed $input): array
    {
        $aud = self::shape($input);
        foreach (['include', 'exclude'] as $side) {
            if (count($aud[$side]['segments']) > self::MAX_SEGMENTS || count($aud[$side]['contacts']) > self::MAX_CONTACTS) {
                throw ValidationException::single('audience', 'Choose at most ' . self::MAX_SEGMENTS . ' segments and ' . self::MAX_CONTACTS . ' contacts on each side.');
            }
            foreach ($aud[$side]['segments'] as $id) {
                if (Segment::find($id) === null) {
                    throw ValidationException::single('audience', 'A chosen segment was deleted - remove it from the recipients.');
                }
            }
            foreach ($aud[$side]['contacts'] as $id) {
                if (Contact::find($id) === null) {
                    throw ValidationException::single('audience', 'A chosen contact was deleted - remove it from the recipients.');
                }
            }
        }

        return $aud;
    }

    /** A newsletter row's audience in normal form (no existence checks). */
    public static function of(array $campaign): array
    {
        return self::shape($campaign['audience'] ?? null);
    }

    /** True when nothing is included: every subscribed contact is a recipient. */
    public static function includesEveryone(array $audience): bool
    {
        return $audience['include']['segments'] === [] && $audience['include']['contacts'] === [];
    }

    /**
     * The normalized definition of every segment the audience uses.
     *
     * @throws ValidationException on audience when a segment was deleted or
     *         its conditions no longer validate
     * @return array<int, array<string, mixed>> segment id => definition
     */
    public static function definitions(array $audience): array
    {
        $out = [];
        foreach (array_merge($audience['include']['segments'], $audience['exclude']['segments']) as $id) {
            $segment = Segment::find($id);
            if ($segment === null) {
                throw ValidationException::single('audience', 'A segment chosen for the recipients was deleted - remove it in the Email creator.');
            }
            try {
                $out[$id] = SegmentQuery::normalize($segment['definition']);
            } catch (ValidationException $e) {
                throw ValidationException::single('audience', 'The segment "' . $segment['name'] . '" needs fixing: ' . $e->getMessage());
            }
        }

        return $out;
    }

    /** Problem with the audience as a sentence, or null when it is usable. */
    public static function problem(array $audience): ?string
    {
        try {
            self::definitions($audience);
        } catch (ValidationException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * SQL condition over edm_contacts aliased $a selecting the audience (the
     * subscribed / not deleted scope is added by the caller), plus bound
     * values.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function where(array $audience, string $a = 'm'): array
    {
        $defs = self::definitions($audience);
        $query = new SegmentQuery($this->db);
        $side = function (array $part) use ($defs, $query, $a): array {
            $sql = [];
            $params = [];
            foreach ($part['segments'] as $id) {
                [$s, $p] = $query->where($defs[$id], $a);
                $sql[] = $s;
                array_push($params, ...$p);
            }
            if ($part['contacts'] !== []) {
                $sql[] = $a . '.`id` IN (' . implode(',', array_fill(0, count($part['contacts']), '?')) . ')';
                array_push($params, ...$part['contacts']);
            }

            return [$sql === [] ? '' : '(' . implode(' OR ', $sql) . ')', $params];
        };
        [$inc, $incParams] = $side($audience['include']);
        [$exc, $excParams] = $side($audience['exclude']);
        $sql = $inc !== '' ? $inc : '1 = 1';
        if ($exc !== '') {
            $sql .= ' AND NOT ' . $exc;
        }

        return ['(' . $sql . ')', [...$incParams, ...$excParams]];
    }

    /**
     * Subscribed recipients (distinct addresses) and all subscribed contacts,
     * plus up to $sample recipients.
     *
     * @return array{matched: int, total: int, sample: list<array{email: string, name: ?string}>}
     */
    public function count(array $audience, int $sample = 0): array
    {
        $scope = 'm.`deleted_at` IS NULL AND m.`status` = ?';
        $total = (int) $this->db->scalar('SELECT COUNT(DISTINCT LOWER(TRIM(m.`email`))) FROM `edm_contacts` m WHERE ' . $scope, [Contact::SUBSCRIBED]);
        [$cond, $params] = $this->where($audience);
        $where = $scope . ' AND ' . $cond;
        $params = [Contact::SUBSCRIBED, ...$params];
        $matched = (int) $this->db->scalar('SELECT COUNT(DISTINCT LOWER(TRIM(m.`email`))) FROM `edm_contacts` m WHERE ' . $where, $params);
        $rows = $sample > 0
            ? $this->db->select('SELECT m.`email`, m.`name` FROM `edm_contacts` m WHERE ' . $where . ' ORDER BY m.`id` DESC LIMIT ' . $sample, $params)
            : [];

        return ['matched' => $matched, 'total' => $total, 'sample' => $rows];
    }

    /**
     * The recipients themselves (one row per address, newest contact first),
     * each flagged when the address is on the exclusion list and so would
     * be skipped at send time. At most $limit rows.
     *
     * @return list<array{email: string, name: ?string, suppressed: bool}>
     */
    public function rows(array $audience, int $limit = 5000): array
    {
        [$cond, $params] = $this->where($audience);
        $rows = $this->db->select(
            'SELECT m.`email`, m.`name`,
                    EXISTS (SELECT 1 FROM `edm_suppressions` s
                             WHERE s.`deleted_at` IS NULL AND LOWER(s.`email`) = LOWER(TRIM(m.`email`))) AS `suppressed`
               FROM `edm_contacts` m
              WHERE m.`deleted_at` IS NULL AND m.`status` = ? AND ' . $cond . '
              ORDER BY m.`id` DESC',
            [Contact::SUBSCRIBED, ...$params]
        );

        $out = [];
        foreach ($rows as $r) {
            $key = strtolower(trim((string) $r['email']));
            if (isset($out[$key])) {
                continue;
            }
            $out[$key] = [
                'email'      => (string) $r['email'],
                'name'       => $r['name'] !== null ? (string) $r['name'] : null,
                'suppressed' => (bool) $r['suppressed'],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_values($out);
    }

    /** Of the recipients, how many addresses are on the exclusion list. */
    public function suppressedCount(array $audience): int
    {
        [$cond, $params] = $this->where($audience);

        return (int) $this->db->scalar(
            'SELECT COUNT(DISTINCT LOWER(TRIM(m.`email`))) FROM `edm_contacts` m
              WHERE m.`deleted_at` IS NULL AND m.`status` = ? AND ' . $cond . '
                AND EXISTS (SELECT 1 FROM `edm_suppressions` s
                             WHERE s.`deleted_at` IS NULL AND LOWER(s.`email`) = LOWER(TRIM(m.`email`)))',
            [Contact::SUBSCRIBED, ...$params]
        );
    }

    /**
     * Names for display: { include: { segments: [name], contacts: [email] }, exclude: ... }.
     * A deleted segment / contact shows as "Deleted segment #id" / "Deleted contact #id".
     *
     * @return array{include: array{segments: list<string>, contacts: list<string>}, exclude: array{segments: list<string>, contacts: list<string>}}
     */
    public static function names(array $audience): array
    {
        $out = self::EMPTY;
        foreach (['include', 'exclude'] as $side) {
            foreach ($audience[$side]['segments'] as $id) {
                $out[$side]['segments'][] = (string) (Segment::find($id)['name'] ?? 'Deleted segment #' . $id);
            }
            foreach ($audience[$side]['contacts'] as $id) {
                $out[$side]['contacts'][] = (string) (Contact::find($id)['email'] ?? 'Deleted contact #' . $id);
            }
        }

        return $out;
    }

    /**
     * One line, e.g. "All subscribed contacts", "VIP customers, Loyal + 2
     * contacts", "... - excluding Dormant".
     */
    public static function describe(array $audience, bool $short = false): string
    {
        $n = self::names($audience);
        $part = static function (array $side) use ($short): string {
            $bits = $side['segments'];
            $contacts = count($side['contacts']);
            if ($contacts > 0) {
                $bits[] = $short || $contacts > 3
                    ? $contacts . ' contact' . ($contacts === 1 ? '' : 's')
                    : implode(', ', $side['contacts']);
            }

            return implode(', ', $bits);
        };
        $include = self::includesEveryone($audience) ? 'All subscribed contacts' : $part($n['include']);
        $exclude = $part($n['exclude']);

        return $include . ($exclude !== '' ? ' - excluding ' . $exclude : '');
    }
}
