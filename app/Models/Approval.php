<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * One approval step for a newsletter.
 * Table: edm_approvals.
 */
final class Approval extends Model
{
    public const STATUSES = [1 => 'pending', 2 => 'approved', 3 => 'rejected'];

    protected const TABLE = 'edm_approvals';

    protected const FILLABLE = ['campaign_id', 'step', 'status', 'reviewer_id', 'reviewer_name', 'comment'];

    protected const CASTS = ['status' => 'int', 'step' => 'int'];

    protected const ORDER = '`created_at` DESC, `id` DESC';
}
