<?php

declare(strict_types=1);

namespace Edm\Services;

use DateInterval;
use DateTimeImmutable;
use Edm\Core\Database;
use Edm\Models\SendLog;
use Edm\Services\Ses\Unsubscribe;

/**
 * Message performance (GetResponse "General performance" layout): the
 * Dashboard for a date range (dashboard/api.php?action=performance) and the
 * Newsletter report for one newsletter (CampaignReport, `performance`).
 * Everything comes from edm_send_log, kept current by Amazon SES events;
 * soft / hard bounces from the raw Bounce events in edm_ses_events.
 *
 * - summary   ReportingOverview::delivery() over emails sent in the period
 * - previous  the same figures for the period of equal length just before
 *             (Dashboard only; the change badges on the tiles)
 * - series    counts per hour / day / month by the time each event happened
 *             (an open is counted when it was opened, not when it was sent)
 * - messages  one row per newsletter with emails sent in the period
 * - domains   recipient domains by emails sent, top TOP_DOMAINS + Other
 * - reasons   unsubscribes by the reason given on the unsubscribe page
 *             (Unsubscribe::REASONS; none = no reason given)
 */
final class PerformanceReport
{
    /** Recipient domains shown by name; the rest are added up as Other. */
    public const TOP_DOMAINS = 9;

    /** series key => edm_send_log time column it counts. */
    private const SERIES = [
        'sent'         => 'sent_at',
        'delivered'    => 'delivered_at',
        'opened'       => 'opened_at',
        'clicked'      => 'clicked_at',
        'complained'   => 'complained_at',
        'unsubscribed' => 'unsubscribed_at',
    ];

    /** unit => [MySQL DATE_FORMAT, PHP date format, step, axis label format]. */
    private const UNITS = [
        'hour'  => ['%Y-%m-%d %H:00', 'Y-m-d H:00', 'PT1H', 'd M H:00'],
        'day'   => ['%Y-%m-%d', 'Y-m-d', 'P1D', 'd M'],
        'month' => ['%Y-%m', 'Y-m', 'P1M', 'M Y'],
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * Dashboard: every newsletter, emails sent from $from to $to (Y-m-d,
     * both days included).
     *
     * @return array<string, mixed>
     */
    public function forPeriod(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $start = $from->setTime(0, 0);
        $end = $to->setTime(23, 59, 59);
        $days = (int) $start->diff($end)->days + 1;
        $prevEnd = $start->modify('-1 second');
        $prevStart = $start->modify('-' . $days . ' days');

        $overview = new ReportingOverview($this->db);

        return [
            'from'     => $start->format('Y-m-d'),
            'to'       => $end->format('Y-m-d'),
            'period'   => self::period($start, $end),
            'summary'  => $overview->delivery(null, self::sql($start), self::sql($end)),
            'previous' => [
                'period' => self::period($prevStart, $prevEnd),
                'stats'  => $overview->delivery(null, self::sql($prevStart), self::sql($prevEnd)),
            ],
            'series'   => $this->series($start, $end, null),
            'messages' => $this->messages($start, $end),
            'domains'  => $this->domains($start, $end, null),
            'reasons'  => $this->reasons($start, $end, null),
        ];
    }

    /**
     * Newsletter report: one newsletter from its first email to its latest
     * event, without a period comparison or messages table.
     *
     * @return array<string, mixed>
     */
    public function forCampaign(int $campaignId): array
    {
        $span = $this->db->first(
            'SELECT MIN(`sent_at`) AS first_at,
                    GREATEST(MAX(`sent_at`), COALESCE(MAX(`delivered_at`), MAX(`sent_at`)),
                             COALESCE(MAX(`opened_at`), MAX(`sent_at`)), COALESCE(MAX(`clicked_at`), MAX(`sent_at`)),
                             COALESCE(MAX(`bounced_at`), MAX(`sent_at`)), COALESCE(MAX(`complained_at`), MAX(`sent_at`)),
                             COALESCE(MAX(`unsubscribed_at`), MAX(`sent_at`))) AS last_at
               FROM `edm_send_log` WHERE `campaign_id` = ? AND `deleted_at` IS NULL',
            [$campaignId]
        ) ?? [];
        $start = new DateTimeImmutable((string) ($span['first_at'] ?? 'now'));
        $end = new DateTimeImmutable((string) ($span['last_at'] ?? 'now'));

        return [
            'from'     => $start->format('Y-m-d'),
            'to'       => $end->format('Y-m-d'),
            'period'   => self::period($start, $end),
            'summary'  => (new ReportingOverview($this->db))->delivery($campaignId),
            'series'   => $this->series($start, $end, $campaignId),
            'domains'  => $this->domains(null, null, $campaignId),
            'reasons'  => $this->reasons(null, null, $campaignId),
        ];
    }

    /**
     * Counts per time bucket, every bucket of the period present (zero when
     * nothing happened). Hours up to 3 days, days up to 92, months beyond.
     *
     * @return array{unit: string, keys: list<string>, labels: list<string>, data: array<string, list<int>>}
     */
    private function series(DateTimeImmutable $start, DateTimeImmutable $end, ?int $campaignId): array
    {
        $days = (int) $start->setTime(0, 0)->diff($end->setTime(0, 0))->days + 1;
        $unit = $days <= 3 ? 'hour' : ($days <= 92 ? 'day' : 'month');
        [$sqlFormat, $phpFormat, $step, $labelFormat] = self::UNITS[$unit];

        // Every bucket key from the first to the last, in order, with its
        // axis label.
        $keys = [];
        $labels = [];
        $cursor = new DateTimeImmutable($start->format($unit === 'month' ? 'Y-m-01' : ($unit === 'day' ? 'Y-m-d' : 'Y-m-d H:00:00')));
        $interval = new DateInterval($step);
        while ($cursor <= $end) {
            $keys[] = $cursor->format($phpFormat);
            $labels[] = $cursor->format($labelFormat);
            $cursor = $cursor->add($interval);
        }

        $from = self::sql($start->setTime((int) $start->format('H'), 0));
        $to = self::sql($end);
        $scope = '';
        $scopeParams = [];
        if ($campaignId !== null) {
            $scope = ' AND `campaign_id` = ?';
            $scopeParams[] = $campaignId;
        }

        $data = [];
        foreach (self::SERIES as $key => $column) {
            $col = Database::ident($column);
            // Sent counts the emails the queue handed to SES (as the summary).
            $status = $key === 'sent' ? ' AND `status` IN (?, ?, ?, ?)' : '';
            $params = $key === 'sent' ? [SendLog::SENT, SendLog::DELIVERED, SendLog::BOUNCED, SendLog::COMPLAINED] : [];
            $rows = $this->db->select(
                'SELECT DATE_FORMAT(' . $col . ', ?) AS bucket, COUNT(*) AS n
                   FROM `edm_send_log`
                  WHERE `deleted_at` IS NULL AND ' . $col . ' BETWEEN ? AND ?' . $status . $scope . '
                  GROUP BY bucket',
                array_merge([$sqlFormat, $from, $to], $params, $scopeParams)
            );
            $data[$key] = self::fill($keys, $rows);
        }

        // Soft (Transient / Undetermined) and hard (Permanent) bounces from
        // the SES Bounce events; all bounces = both.
        $join = $campaignId !== null
            ? ' JOIN `edm_send_log` s ON s.`ses_message_id` = e.`ses_message_id` AND s.`deleted_at` IS NULL AND s.`campaign_id` = ?'
            : '';
        $rows = $this->db->select(
            'SELECT DATE_FORMAT(e.`occurred_at`, ?) AS bucket,
                    SUM(JSON_UNQUOTE(JSON_EXTRACT(e.`payload`, \'$.bounce.bounceType\')) = \'Permanent\') AS hard,
                    COUNT(*) AS n
               FROM `edm_ses_events` e' . $join . '
              WHERE e.`deleted_at` IS NULL AND e.`event_type` = ? AND e.`occurred_at` BETWEEN ? AND ?
              GROUP BY bucket',
            array_merge([$sqlFormat], $scopeParams, ['Bounce', $from, $to])
        );
        $data['bounced'] = self::fill($keys, $rows);
        $data['hard_bounced'] = self::fill($keys, $rows, 'hard');
        $data['soft_bounced'] = array_map(
            static fn (int $all, int $hard): int => $all - $hard,
            $data['bounced'],
            $data['hard_bounced']
        );

        return ['unit' => $unit, 'keys' => $keys, 'labels' => $labels, 'data' => $data];
    }

    /**
     * Newsletters with emails sent in the period, latest first.
     *
     * @return list<array<string, mixed>>
     */
    private function messages(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $rows = $this->db->select(
            'SELECT s.`campaign_id`, c.`name`, c.`deleted_at` AS campaign_deleted, sd.`from_name`,
                    MIN(s.`sent_at`) AS sent_on, COUNT(*) AS sent,
                    COUNT(s.`delivered_at`) AS delivered, COUNT(s.`opened_at`) AS opened,
                    COUNT(s.`clicked_at`) AS clicked, COUNT(s.`bounced_at`) AS bounced,
                    COUNT(s.`complained_at`) AS complained, COUNT(s.`unsubscribed_at`) AS unsubscribed
               FROM `edm_send_log` s
               LEFT JOIN `edm_campaigns` c ON c.`id` = s.`campaign_id`
               LEFT JOIN `edm_senders` sd ON sd.`id` = c.`sender_id`
              WHERE s.`deleted_at` IS NULL AND s.`campaign_id` IS NOT NULL
                AND s.`status` IN (?, ?, ?, ?) AND s.`sent_at` BETWEEN ? AND ?
              GROUP BY s.`campaign_id`, c.`name`, c.`deleted_at`, sd.`from_name`
              ORDER BY sent_on DESC',
            [SendLog::SENT, SendLog::DELIVERED, SendLog::BOUNCED, SendLog::COMPLAINED, self::sql($start), self::sql($end)]
        );

        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['campaign_id'],
            'name'         => $r['name'] !== null ? (string) $r['name'] : 'Deleted newsletter',
            'deleted'      => $r['name'] === null || $r['campaign_deleted'] !== null,
            'sender'       => (string) ($r['from_name'] ?? ''),
            'sent_on'      => date('d-m-Y H:i:s', strtotime((string) $r['sent_on'])),
            'sent'         => (int) $r['sent'],
            'delivered'    => (int) $r['delivered'],
            'opened'       => (int) $r['opened'],
            'clicked'      => (int) $r['clicked'],
            'bounced'      => (int) $r['bounced'],
            'complained'   => (int) $r['complained'],
            'unsubscribed' => (int) $r['unsubscribed'],
        ], $rows);
    }

    /**
     * Opens / clicks / bounces by recipient domain, busiest (by emails sent)
     * first; past TOP_DOMAINS they are added up as Other.
     *
     * @return list<array{domain: string, sent: int, opened: int, clicked: int, bounced: int}>
     */
    /**
     * Unsubscribes of the emails in scope (the summary's Unsubscribed count)
     * by reason, most first; NULL = "No reason given" (one-click from the
     * mail client, or nothing picked).
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    private function reasons(?DateTimeImmutable $start, ?DateTimeImmutable $end, ?int $campaignId): array
    {
        $where = '`deleted_at` IS NULL AND `unsubscribed_at` IS NOT NULL AND `status` IN (?, ?, ?, ?)';
        $params = [SendLog::SENT, SendLog::DELIVERED, SendLog::BOUNCED, SendLog::COMPLAINED];
        if ($campaignId !== null) {
            $where .= ' AND `campaign_id` = ?';
            $params[] = $campaignId;
        }
        if ($start !== null && $end !== null) {
            $where .= ' AND `sent_at` BETWEEN ? AND ?';
            array_push($params, self::sql($start), self::sql($end));
        }
        $rows = $this->db->select(
            'SELECT `unsubscribe_reason` AS reason, COUNT(*) AS n FROM `edm_send_log` WHERE ' . $where . '
              GROUP BY `unsubscribe_reason` ORDER BY n DESC',
            $params
        );

        return array_map(static function (array $r): array {
            $key = (string) ($r['reason'] ?? '');
            // A key no longer in REASONS still counts, under its own name.
            $label = $key === '' ? 'No reason given' : (Unsubscribe::REASONS[$key] ?? $key);

            return ['key' => $key === '' ? 'none' : $key, 'label' => $label, 'count' => (int) $r['n']];
        }, $rows);
    }

    private function domains(?DateTimeImmutable $start, ?DateTimeImmutable $end, ?int $campaignId): array
    {
        $where = '`deleted_at` IS NULL AND `status` IN (?, ?, ?, ?)';
        $params = [SendLog::SENT, SendLog::DELIVERED, SendLog::BOUNCED, SendLog::COMPLAINED];
        if ($campaignId !== null) {
            $where .= ' AND `campaign_id` = ?';
            $params[] = $campaignId;
        }
        if ($start !== null && $end !== null) {
            $where .= ' AND `sent_at` BETWEEN ? AND ?';
            array_push($params, self::sql($start), self::sql($end));
        }
        $rows = $this->db->select(
            'SELECT LOWER(SUBSTRING_INDEX(`email`, \'@\', -1)) AS domain, COUNT(*) AS sent,
                    COUNT(`opened_at`) AS opened, COUNT(`clicked_at`) AS clicked, COUNT(`bounced_at`) AS bounced
               FROM `edm_send_log` WHERE ' . $where . '
              GROUP BY domain ORDER BY sent DESC, domain ASC',
            $params
        );

        $out = [];
        $other = ['domain' => 'Other', 'sent' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0];
        foreach ($rows as $i => $r) {
            $row = [
                'domain'  => (string) $r['domain'],
                'sent'    => (int) $r['sent'],
                'opened'  => (int) $r['opened'],
                'clicked' => (int) $r['clicked'],
                'bounced' => (int) $r['bounced'],
            ];
            if ($i < self::TOP_DOMAINS) {
                $out[] = $row;
                continue;
            }
            foreach (['sent', 'opened', 'clicked', 'bounced'] as $k) {
                $other[$k] += $row[$k];
            }
        }
        if ($other['sent'] > 0) {
            $out[] = $other;
        }

        return $out;
    }

    /**
     * Bucket rows (bucket, $column) laid onto every key, zero where missing.
     *
     * @param list<string> $keys
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private static function fill(array $keys, array $rows, string $column = 'n'): array
    {
        $by = [];
        foreach ($rows as $r) {
            $by[(string) $r['bucket']] = (int) $r[$column];
        }

        return array_map(static fn (string $k): int => $by[$k] ?? 0, $keys);
    }

    /** "d-m-Y to d-m-Y" for display. */
    private static function period(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return $start->format('d-m-Y') . ' to ' . $end->format('d-m-Y');
    }

    private static function sql(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }
}
