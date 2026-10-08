<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A raw Amazon SES event (Send, Delivery, Bounce, Complaint, Open, Click, ...)
 * as received through SNS by public/ses-webhook.php. Kept for audit / BI; the
 * per-recipient outcome is rolled up onto edm_send_log.
 * Table: edm_ses_events.
 */
final class SesEvent extends Model
{
    protected const TABLE = 'edm_ses_events';

    protected const FILLABLE = ['ses_message_id', 'event_type', 'email', 'payload', 'occurred_at'];

    protected const CASTS = ['payload' => 'json', 'occurred_at' => 'datetime'];

    protected const ORDER = '`id` DESC';

    /** Event types SES publishes, for the event log filter. */
    public const TYPES = [
        'Send', 'Delivery', 'Open', 'Click', 'Bounce', 'Complaint', 'Reject',
        'RenderingFailure', 'DeliveryDelay', 'Subscription',
    ];

    /** Most rows the event log returns. */
    public const LOG_LIMIT = 5000;

    /**
     * Event log (Settings > SES event log): newest first, joined to the send
     * log for the newsletter, with a one-line `detail`. $days = 0: no time
     * limit. Payloads are left out; events_get returns one in full.
     *
     * @return list<array<string, mixed>>
     */
    public static function log(?string $type, int $days): array
    {
        $where = ['e.`deleted_at` IS NULL'];
        $params = [];
        if ($type !== null && $type !== '') {
            $where[] = 'e.`event_type` = ?';
            $params[] = $type;
        }
        if ($days > 0) {
            $where[] = 'COALESCE(e.`occurred_at`, e.`created_at`) >= ?';
            $params[] = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        }
        $rows = self::db()->select(
            'SELECT e.`id`, e.`event_type`, e.`email`, e.`ses_message_id`, e.`occurred_at`, e.`created_at`, e.`payload`,
                    s.`campaign_id`, c.`name` AS `campaign_name`
               FROM `edm_ses_events` e
               LEFT JOIN `edm_send_log` s ON s.`ses_message_id` = e.`ses_message_id` AND s.`deleted_at` IS NULL
               LEFT JOIN `edm_campaigns` c ON c.`id` = s.`campaign_id`
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY e.`id` DESC
              LIMIT ' . self::LOG_LIMIT,
            $params
        );

        return array_map(static function (array $r): array {
            $payload = json_decode((string) $r['payload'], true);
            $at = $r['occurred_at'] ?? $r['created_at'];

            return [
                'id'            => (int) $r['id'],
                'event_type'    => (string) $r['event_type'],
                'email'         => $r['email'],
                'occurred_at'   => $at !== null ? date('d-m-Y H:i:s', strtotime((string) $at)) : null,
                'campaign_id'   => $r['campaign_id'] !== null ? (int) $r['campaign_id'] : null,
                'campaign_name' => $r['campaign_name'],
                'detail'        => self::detail((string) $r['event_type'], is_array($payload) ? $payload : []),
            ];
        }, $rows);
    }

    /**
     * One line about the event: the bounce type and reason, the clicked
     * link, the complaint type, the reject reason, ...
     *
     * @param array<string, mixed> $event decoded SES event
     */
    public static function detail(string $type, array $event): string
    {
        $d = $event[lcfirst($type)] ?? [];
        if (!is_array($d)) {
            return '';
        }
        $text = match ($type) {
            'Delivery'         => (string) ($d['smtpResponse'] ?? ''),
            'Bounce'           => trim(($d['bounceType'] ?? '') . ' / ' . ($d['bounceSubType'] ?? '') . ' '
                . ($d['bouncedRecipients'][0]['diagnosticCode'] ?? ''), ' /'),
            'Complaint'        => (string) ($d['complaintFeedbackType'] ?? 'complaint'),
            'Click'            => (string) ($d['link'] ?? ''),
            'Open'             => (string) ($d['userAgent'] ?? ''),
            'Reject'           => (string) ($d['reason'] ?? ''),
            'RenderingFailure' => (string) ($d['errorMessage'] ?? ''),
            'DeliveryDelay'    => trim(($d['delayType'] ?? '') . ' ' . ($d['delayedRecipients'][0]['diagnosticCode'] ?? '')),
            'Subscription'     => !empty($d['newTopicPreferences']['unsubscribeAll']) ? 'unsubscribed from all' : 'preferences changed',
            default            => '',
        };

        return mb_substr($text, 0, 300);
    }
}
