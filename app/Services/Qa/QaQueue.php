<?php

declare(strict_types=1);

namespace Edm\Services\Qa;

use Edm\Core\Database;
use Edm\Models\Approval;
use Edm\Models\ApprovalLog;
use Edm\Models\CampaignQa;
use Throwable;

/**
 * Automated QA queue (spec 5.2 step 5). A run is queued when the BPT team
 * approves a review, when a campaign whose last run failed is saved again,
 * or by the "Run again" button; it is worked by cron/qa.php (every minute)
 * or, when no cron runs (local development), by the review page's status
 * poll. A run is claimed with a conditional UPDATE, so two workers never
 * run the same one; a run stuck in "running" for 10 minutes is retried.
 *
 * The outcome is written to the campaign's QA row and, when the run belongs
 * to a review, to that review's Activity Log (qa_passed / qa_failed).
 */
final class QaQueue
{
    private const STALE_MINUTES = 10;

    public function __construct(private Database $db)
    {
    }

    /**
     * Queue a run unless one is already queued / running for the campaign.
     *
     * @return array<string, mixed> the queued (or already pending) run
     */
    public function queue(int $campaignId, ?int $approvalId, string $trigger, ?int $staffId, ?string $staffName): array
    {
        $pending = CampaignQa::where('`campaign_id` = ? AND `status` IN (1, 2)', [$campaignId], null, 1);
        if ($pending !== []) {
            return $pending[0];
        }

        return CampaignQa::create([
            'campaign_id'    => $campaignId,
            'approval_id'    => $approvalId,
            'status'         => CampaignQa::QUEUED,
            'trigger'        => $trigger,
            'queued_by'      => $staffId,
            'queued_by_name' => $staffName,
        ]);
    }

    /** After a save: check again when the campaign's last run failed. */
    public function requeueIfFailed(int $campaignId, ?int $staffId, ?string $staffName): void
    {
        $last = CampaignQa::latest($campaignId);
        if ($last !== null && $last['status'] === CampaignQa::FAILED) {
            $this->queue($campaignId, $last['approval_id'], 'resaved', $staffId, $staffName);
        }
    }

    /**
     * Work queued runs (oldest first) until none is left or the deadline
     * passes. $campaignId limits it to one campaign (the review page poll).
     *
     * @return int runs finished
     */
    public function work(float $deadline, ?int $campaignId = null, ?callable $log = null): int
    {
        $this->db->execute(
            'UPDATE `edm_campaign_qa` SET `status` = ?, `updated_at` = ? WHERE `status` = ? AND `started_at` < ? AND `deleted_at` IS NULL',
            [CampaignQa::QUEUED, date('Y-m-d H:i:s'), CampaignQa::RUNNING, date('Y-m-d H:i:s', time() - self::STALE_MINUTES * 60)]
        );

        $done = 0;
        while (microtime(true) < $deadline) {
            $sql = 'SELECT `id` FROM `edm_campaign_qa` WHERE `status` = ? AND `deleted_at` IS NULL';
            $params = [CampaignQa::QUEUED];
            if ($campaignId !== null) {
                $sql .= ' AND `campaign_id` = ?';
                $params[] = $campaignId;
            }
            $id = $this->db->scalar($sql . ' ORDER BY `id` LIMIT 1', $params);
            if ($id === null) {
                break;
            }
            $claimed = $this->db->execute(
                'UPDATE `edm_campaign_qa` SET `status` = ?, `started_at` = ?, `updated_at` = ? WHERE `id` = ? AND `status` = ?',
                [CampaignQa::RUNNING, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int) $id, CampaignQa::QUEUED]
            );
            if ($claimed !== 1) {
                continue; // another worker took it
            }
            $this->runOne((int) $id, $log);
            $done++;
        }

        return $done;
    }

    private function runOne(int $id, ?callable $log): void
    {
        $run = CampaignQa::findOrFail($id);
        try {
            $checks = (new QaChecker($this->db))->run((int) $run['campaign_id']);
        } catch (Throwable $e) {
            $checks = [[
                'key' => 'error', 'label' => 'QA run', 'result' => 'fail',
                'summary' => 'The check could not finish: ' . mb_substr($e->getMessage(), 0, 200), 'details' => [],
            ]];
        }
        $status = QaChecker::status($checks);
        CampaignQa::update($id, ['status' => $status, 'results' => $checks, 'finished_at' => date('Y-m-d H:i:s')]);

        $failed = array_column(array_filter($checks, static fn (array $c): bool => $c['result'] === 'fail'), 'label');
        $warned = array_column(array_filter($checks, static fn (array $c): bool => $c['result'] === 'warn'), 'label');
        $summary = $status === CampaignQa::FAILED
            ? 'Automated QA failed: ' . implode(', ', $failed) . '.'
            : 'Automated QA passed' . ($warned !== [] ? ' with warnings: ' . implode(', ', $warned) . '.' : '.');
        if ($log !== null) {
            $log('Campaign #' . $run['campaign_id'] . ' - ' . $summary);
        }
        if ($run['approval_id'] !== null && Approval::find((int) $run['approval_id']) !== null) {
            ApprovalLog::create([
                'approval_id' => (int) $run['approval_id'],
                'event'       => $status === CampaignQa::FAILED ? 'qa_failed' : 'qa_passed',
                'summary'     => $summary,
                'actor_name'  => 'System (automated QA)',
            ]);
        }
    }
}
