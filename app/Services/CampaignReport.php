<?php

declare(strict_types=1);

namespace Edm\Services;

use Edm\Core\Database;
use Edm\Models\Campaign;
use Edm\Models\Sender;
use Edm\Models\SendLog;

/**
 * Newsletter report (newsletters/report.php): the figures of one newsletter
 * and every recipient in its send log, with the times SES reported for it
 * (public/ses-webhook.php keeps edm_send_log current).
 *
 * A recipient's `result` is the furthest it got, in this order:
 * complained, bounced, failed, skipped, unsubscribed, clicked, opened,
 * delivered, sent - the value the report filters on.
 */
final class CampaignReport
{
    /** result => label, in the order the filter lists them. */
    public const RESULTS = [
        'delivered'    => 'Delivered',
        'opened'       => 'Opened',
        'clicked'      => 'Clicked',
        'not_opened'   => 'Delivered, not opened',
        'sent'         => 'Sent, no delivery report yet',
        'bounced'      => 'Bounced',
        'complained'   => 'Complained',
        'unsubscribed' => 'Unsubscribed',
        'failed'       => 'Failed',
        'skipped'      => 'Skipped',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{campaign: array<string, mixed>, stats: array<string, int|float>, events: int, rows: list<array<string, mixed>>}
     */
    public function build(int $id): array
    {
        $campaign = Campaign::withDetails($id);
        $sender = !empty($campaign['sender_id']) ? Sender::find((int) $campaign['sender_id']) : null;

        $stats = (new ReportingOverview($this->db))->delivery($id);
        $other = $this->db->first(
            'SELECT SUM(`status` = ?) AS failed, SUM(`status` = ?) AS skipped, MIN(`sent_at`) AS first_sent, MAX(`sent_at`) AS last_sent
               FROM `edm_send_log` WHERE `campaign_id` = ? AND `deleted_at` IS NULL',
            [SendLog::FAILED, SendLog::SKIPPED, $id]
        ) ?? [];
        $stats['failed'] = (int) ($other['failed'] ?? 0);
        $stats['skipped'] = (int) ($other['skipped'] ?? 0);

        // SES events tied to this newsletter's messages: none means the
        // event destination / webhook is not connected yet.
        $events = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `edm_ses_events` e
               JOIN `edm_send_log` s ON s.`ses_message_id` = e.`ses_message_id` AND s.`deleted_at` IS NULL
              WHERE s.`campaign_id` = ? AND e.`deleted_at` IS NULL AND e.`event_type` <> ?',
            [$id, 'Send']
        );

        $rows = $this->db->select(
            'SELECT s.`email`, s.`status`, s.`error`, s.`sent_at`, s.`delivered_at`, s.`opened_at`, s.`clicked_at`,
                    s.`bounced_at`, s.`complained_at`, s.`unsubscribed_at`,
                    (SELECT c.`name` FROM `edm_contacts` c
                      WHERE c.`deleted_at` IS NULL AND LOWER(c.`email`) = LOWER(s.`email`) ORDER BY c.`id` DESC LIMIT 1) AS `name`
               FROM `edm_send_log` s
              WHERE s.`campaign_id` = ? AND s.`deleted_at` IS NULL
              ORDER BY s.`id` ASC',
            [$id]
        );

        return [
            'campaign' => [
                'id'             => $id,
                'name'           => (string) $campaign['name'],
                'subject'        => (string) ($campaign['subject'] ?? ''),
                'status'         => (int) $campaign['status'],
                'audience_label' => (string) ($campaign['audience_label'] ?? ''),
                'sender'         => $sender !== null ? trim(($sender['from_name'] ?? '') . ' <' . $sender['email'] . '>') : '',
                'first_sent'     => self::time($other['first_sent'] ?? null),
                'last_sent'      => self::time($other['last_sent'] ?? null),
                'track_opens'    => (bool) ($campaign['track_opens'] ?? true),
                'track_clicks'   => (bool) ($campaign['track_clicks'] ?? true),
            ],
            'stats'  => $stats,
            'events' => $events,
            'rows'   => array_map(static fn (array $r): array => [
                'email'           => (string) $r['email'],
                'name'            => $r['name'] !== null ? (string) $r['name'] : null,
                'result'          => self::result($r),
                'error'           => $r['error'],
                'sent_at'         => self::time($r['sent_at']),
                'delivered_at'    => self::time($r['delivered_at']),
                'opened_at'       => self::time($r['opened_at']),
                'clicked_at'      => self::time($r['clicked_at']),
                'bounced_at'      => self::time($r['bounced_at']),
                'complained_at'   => self::time($r['complained_at']),
                'unsubscribed_at' => self::time($r['unsubscribed_at']),
            ], $rows),
        ];
    }

    /** @param array<string, mixed> $r a send log row */
    private static function result(array $r): string
    {
        return match (true) {
            $r['complained_at'] !== null || (int) $r['status'] === SendLog::COMPLAINED => 'complained',
            $r['bounced_at'] !== null || (int) $r['status'] === SendLog::BOUNCED       => 'bounced',
            (int) $r['status'] === SendLog::FAILED                                      => 'failed',
            (int) $r['status'] === SendLog::SKIPPED                                     => 'skipped',
            $r['unsubscribed_at'] !== null                                              => 'unsubscribed',
            $r['clicked_at'] !== null                                                   => 'clicked',
            $r['opened_at'] !== null                                                    => 'opened',
            $r['delivered_at'] !== null                                                 => 'delivered',
            default                                                                     => 'sent',
        };
    }

    private static function time(mixed $v): ?string
    {
        return $v !== null && $v !== '' ? date('d-m-Y H:i:s', strtotime((string) $v)) : null;
    }
}
