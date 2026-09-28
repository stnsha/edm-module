<?php

declare(strict_types=1);

namespace Edm\Services;

use Edm\Core\Database;
use Edm\Models\Campaign;
use Edm\Models\SendLog;

/**
 * Dashboard KPI snapshot. Delivery / open / click / bounce figures come from
 * edm_send_log, kept current by Amazon SES events (public/ses-webhook.php).
 */
final class ReportingOverview
{
    public function __construct(private Database $db)
    {
    }

    public function build(): array
    {
        $statusCounts = Campaign::countsByStatus();

        return [
            'campaigns' => [
                'total'     => array_sum($statusCounts),
                'by_status' => $statusCounts,
                'scheduled' => $statusCounts['scheduled'],
                'completed' => $statusCounts['completed'],
            ],
            'audience' => [
                'lists'        => $this->count('edm_lists'),
                'list_members' => $this->count('edm_list_members'),
                'suppressed'   => $this->count('edm_suppressions'),
            ],
            'senders' => [
                'total'    => $this->count('edm_senders'),
                'verified' => $this->count('edm_senders', '`status` = 2'), // 2 = verified
            ],
            'delivery' => $this->delivery(),
        ];
    }

    /**
     * Platform delivery figures from edm_send_log (sent by the queue, updated
     * by SES events). Delivery and bounce rates are over emails sent; open,
     * click, complaint and unsubscribe rates over emails delivered.
     *
     * @return array<string, int|float>
     */
    private function delivery(): array
    {
        $r = $this->db->first(
            'SELECT COUNT(*) AS sent,
                    COUNT(`delivered_at`) AS delivered, COUNT(`opened_at`) AS opened,
                    COUNT(`clicked_at`) AS clicked, COUNT(`bounced_at`) AS bounced,
                    COUNT(`complained_at`) AS complained, COUNT(`unsubscribed_at`) AS unsubscribed
               FROM `edm_send_log`
              WHERE `deleted_at` IS NULL AND `status` IN (?, ?, ?, ?)',
            [SendLog::SENT, SendLog::DELIVERED, SendLog::BOUNCED, SendLog::COMPLAINED]
        ) ?? [];
        $n = static fn (string $k): int => (int) ($r[$k] ?? 0);
        $pct = static fn (int $part, int $whole, int $dp = 1): float => $whole > 0 ? round($part * 100 / $whole, $dp) : 0.0;

        return [
            'sent'             => $n('sent'),
            'delivered'        => $n('delivered'),
            'opened'           => $n('opened'),
            'clicked'          => $n('clicked'),
            'bounced'          => $n('bounced'),
            'complained'       => $n('complained'),
            'unsubscribed'     => $n('unsubscribed'),
            'delivery_rate'    => $pct($n('delivered'), $n('sent')),
            'bounce_rate'      => $pct($n('bounced'), $n('sent')),
            'open_rate'        => $pct($n('opened'), $n('delivered')),
            'click_rate'       => $pct($n('clicked'), $n('delivered')),
            'complaint_rate'   => $pct($n('complained'), $n('delivered'), 2),
            'unsubscribe_rate' => $pct($n('unsubscribed'), $n('delivered'), 2),
        ];
    }

    /** Active-row count; $where is a code-defined condition. */
    private function count(string $table, string $where = '1 = 1'): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM ' . Database::ident($table) . ' WHERE `deleted_at` IS NULL AND (' . $where . ')'
        );
    }
}
