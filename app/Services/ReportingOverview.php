<?php

declare(strict_types=1);

namespace Edm\Services;

use Edm\Core\Database;
use Edm\Models\Campaign;

/**
 * Statistics / Dashboard KPI snapshot.
 *
 * Delivery / open / click / bounce figures come from Amazon SES delivery
 * events (SNS / SQS), which are not wired yet - they are returned as zero
 * with a note.
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
            'delivery' => [
                'note'               => 'Amazon SES event ingestion not wired yet.',
                'delivery_rate'      => 0,
                'open_rate'          => 0,
                'click_rate'         => 0,
                'bounce_rate'        => 0,
                'unsubscribe_rate'   => 0,
                'complaint_rate'     => 0,
                'revenue_attributed' => 0,
            ],
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
