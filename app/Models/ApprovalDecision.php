<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A stage decision on a review request: stage bpt (BPT team, spec 5.2 steps
 * 2-3) or audience (BI/CRM, step 4); decision 2 approved / 3 rejected.
 * Table: edm_approval_decisions.
 */
final class ApprovalDecision extends Model
{
    public const STAGE_BPT = 'bpt';

    protected const TABLE = 'edm_approval_decisions';

    protected const FILLABLE = ['approval_id', 'stage', 'decision', 'checks', 'comment', 'decided_by', 'decided_by_name'];

    protected const CASTS = ['approval_id' => 'int', 'decision' => 'int', 'checks' => 'json'];

    protected const ORDER = '`id` DESC';
}
