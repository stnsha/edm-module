<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A review request / approval step for a campaign. A request raised from
 * approval/edit.php (spec 5.2 step 1) carries title, requester, objective,
 * audience brief and copywriting (HTML) plus artwork (ApprovalFile).
 * Table: edm_approvals.
 */
final class Approval extends Model
{
    public const STATUSES = [1 => 'pending', 2 => 'approved', 3 => 'rejected'];

    protected const TABLE = 'edm_approvals';

    public const PENDING = 1;
    public const APPROVED = 2;
    public const REJECTED = 3;

    /** step while the request waits for the BPT team (spec 5.2 steps 2-3). */
    public const STEP_BPT = 2;
    /** step after BPT approval: BI/CRM audience validation (step 4). */
    public const STEP_AUDIENCE = 4;

    /** BPT review checklist (spec 5.2 steps 2-3): key => label. */
    public const BPT_CHECKS = [
        'content'    => 'Content is accurate and complete',
        'artwork'    => 'Artwork is correct and on brand',
        'cta'        => 'Call to action is clear and links to the right page',
        'grammar'    => 'Grammar and spelling checked',
        'compliance' => 'Compliant (claims, terms and legal wording)',
        'objective'  => 'Matches the campaign objective',
        'slot'       => 'Sending slot is free on the calendar',
    ];

    /** True while the request waits for the BPT team (old step 1 rows too). */
    public static function awaitingBpt(array $row): bool
    {
        return (int) $row['status'] === self::PENDING && (int) $row['step'] <= self::STEP_BPT;
    }

    protected const FILLABLE = [
        'campaign_id', 'title', 'requested_by', 'requested_by_name', 'objective', 'audience_brief', 'copywriting',
        'step', 'status', 'reviewer_id', 'reviewer_name', 'comment',
    ];

    protected const CASTS = ['status' => 'int', 'step' => 'int'];

    protected const ORDER = '`created_at` DESC, `id` DESC';
}
