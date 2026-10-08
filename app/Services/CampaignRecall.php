<?php

declare(strict_types=1);

namespace Edm\Services;

use Edm\Core\Auth;
use Edm\Core\Database;
use Edm\Models\Approval;
use Edm\Models\ApprovalLog;
use Edm\Models\Campaign;

/**
 * "Don't send it": recalls a scheduled newsletter before the send queue picks
 * it up (CampaignSender waits RECALL_SECONDS after the send time). The
 * newsletter goes back to content revision and its final approval is undone -
 * the approved review becomes rejected with a "Recalled" note, so the
 * requester can edit and resubmit it through the full chain again.
 * Used from the final approval preview (approval/edit.php) and the Newsletters
 * list.
 */
final class CampaignRecall
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed> the newsletter after the recall */
    public function recall(int $campaignId, Auth $auth): array
    {
        return $this->db->transaction(function () use ($campaignId, $auth): array {
            $campaign = Campaign::recall($campaignId);
            $approval = Approval::where('`campaign_id` = ? AND `status` = ?', [$campaignId, Approval::APPROVED], null, 1)[0] ?? null;
            if ($approval !== null) {
                $note = 'Recalled before sending by ' . ($auth->staffName ?? 'staff') . ' - newsletter back to content revision.';
                Approval::update((int) $approval['id'], [
                    'status'        => Approval::REJECTED,
                    'reviewer_id'   => $auth->staffId,
                    'reviewer_name' => $auth->staffName,
                    'comment'       => $note,
                ]);
                ApprovalLog::create([
                    'approval_id' => (int) $approval['id'],
                    'event'       => 'recalled',
                    'summary'     => $note,
                    'actor_id'    => $auth->staffId,
                    'actor_name'  => $auth->staffName,
                ]);
            }

            return $campaign;
        });
    }
}
