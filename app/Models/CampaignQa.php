<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * One automated QA run on a campaign (spec 5.2 step 5). results is the
 * checklist written by Services\Qa\QaChecker.
 * Table: edm_campaign_qa.
 */
final class CampaignQa extends Model
{
    public const STATUSES = [1 => 'queued', 2 => 'running', 3 => 'passed', 4 => 'passed with warnings', 5 => 'failed'];

    public const QUEUED = 1;
    public const RUNNING = 2;
    public const PASSED = 3;
    public const WARNINGS = 4;
    public const FAILED = 5;

    protected const TABLE = 'edm_campaign_qa';

    protected const FILLABLE = [
        'campaign_id', 'approval_id', 'status', 'trigger', 'results',
        'queued_by', 'queued_by_name', 'started_at', 'finished_at',
    ];

    protected const CASTS = [
        'campaign_id' => 'int', 'approval_id' => 'int', 'status' => 'int', 'results' => 'json',
        'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];

    protected const ORDER = '`id` DESC';

    /** Latest run for a campaign, or null. */
    public static function latest(int $campaignId): ?array
    {
        return self::where('`campaign_id` = ?', [$campaignId], null, 1)[0] ?? null;
    }
}
