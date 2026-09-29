<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * One entry in a review request's activity log (event is a category
 * string: created, updated, attachment_added, attachment_removed, approved,
 * rejected).
 * Table: edm_approval_logs.
 */
final class ApprovalLog extends Model
{
    protected const TABLE = 'edm_approval_logs';

    protected const FILLABLE = ['approval_id', 'event', 'summary', 'changes', 'actor_id', 'actor_name'];

    protected const CASTS = ['approval_id' => 'int', 'changes' => 'json', 'created_at' => 'datetime'];

    protected const ORDER = '`id` DESC';
}
