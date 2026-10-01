<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A stage decision on a review request: stage bpt (BPT team, spec 5.2 steps
 * 2-3), audience (BI/CRM, step 4) or final (final approval, step 6 - sets
 * the campaign Scheduled); decision 2 approved / 3 rejected.
 * Table: edm_approval_decisions.
 */
final class ApprovalDecision extends Model
{
    public const STAGE_BPT = 'bpt';
    public const STAGE_AUDIENCE = 'audience';
    public const STAGE_FINAL = 'final';

    public const STAGES = [self::STAGE_BPT, self::STAGE_AUDIENCE, self::STAGE_FINAL];

    /** Latest decision of one stage on a request, or null. */
    public static function latest(int $approvalId, string $stage): ?array
    {
        return self::where('`approval_id` = ? AND `stage` = ?', [$approvalId, $stage], null, 1)[0] ?? null;
    }

    protected const TABLE = 'edm_approval_decisions';

    protected const FILLABLE = ['approval_id', 'stage', 'decision', 'checks', 'comment', 'decided_by', 'decided_by_name'];

    protected const CASTS = ['approval_id' => 'int', 'decision' => 'int', 'checks' => 'json'];

    protected const ORDER = '`id` DESC';
}
