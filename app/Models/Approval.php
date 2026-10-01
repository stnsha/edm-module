<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Auth;
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
    /**
     * step after audience validation: final approval (step 6), which needs
     * the automated QA (step 5) passed; approving schedules the campaign.
     */
    public const STEP_FINAL = 6;

    /** BI/CRM audience validation checklist (spec 5.2 step 4): key => label. */
    public const AUDIENCE_CHECKS = [
        'segment'     => 'Segment / LOFRA filters match the audience brief',
        'suppression' => 'Suppression filters applied (unsubscribed, bounced, complaints excluded)',
        'count'       => 'Recipient count confirmed',
    ];

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

    /** True while the request waits for BI/CRM audience validation. */
    public static function awaitingAudience(array $row): bool
    {
        return (int) $row['status'] === self::PENDING && (int) $row['step'] === self::STEP_AUDIENCE;
    }

    /** True while the request waits for final approval. */
    public static function awaitingFinal(array $row): bool
    {
        return (int) $row['status'] === self::PENDING && (int) $row['step'] === self::STEP_FINAL;
    }

    /** Stage (ApprovalDecision::STAGE_*) the request is waiting on, or null. */
    public static function pendingStage(array $row): ?string
    {
        return match (true) {
            self::awaitingBpt($row)      => ApprovalDecision::STAGE_BPT,
            self::awaitingAudience($row) => ApprovalDecision::STAGE_AUDIENCE,
            self::awaitingFinal($row)    => ApprovalDecision::STAGE_FINAL,
            default                      => null,
        };
    }

    /**
     * Who decides a stage. BPT review and final approval (spec: Scheduled is
     * owned by BPT): BPT team (effective role 3). Audience validation
     * (BI/CRM): admin (role 2). A superadmin can decide every stage.
     */
    public static function canDecide(Auth $auth, string $stage): bool
    {
        if ($auth->isSuperadmin) {
            return true;
        }

        return match ($stage) {
            ApprovalDecision::STAGE_BPT, ApprovalDecision::STAGE_FINAL => $auth->permission === 3,
            ApprovalDecision::STAGE_AUDIENCE                           => $auth->permission === 2,
            default                                                    => false,
        };
    }

    /** Who can decide a stage, for "Waiting for ..." messages. */
    public const STAGE_OWNERS = [
        'bpt'      => 'the BPT team',
        'audience' => 'BI/CRM (admin)',
        'final'    => 'the BPT team',
    ];

    /** The open (pending) request for a campaign, other than $exceptId. */
    public static function openFor(int $campaignId, int $exceptId = 0): ?array
    {
        return self::where('`campaign_id` = ? AND `status` = ? AND `id` <> ?', [$campaignId, self::PENDING, $exceptId], null, 1)[0] ?? null;
    }

    protected const FILLABLE = [
        'campaign_id', 'title', 'requested_by', 'requested_by_name', 'objective', 'audience_brief', 'copywriting',
        'step', 'status', 'reviewer_id', 'reviewer_name', 'comment',
    ];

    protected const CASTS = ['status' => 'int', 'step' => 'int'];

    protected const ORDER = '`created_at` DESC, `id` DESC';
}
