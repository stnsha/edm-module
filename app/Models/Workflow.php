<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * An automation workflow.
 * Table: edm_workflows.
 */
final class Workflow extends Model
{
    public const STATUSES = [1 => 'draft', 2 => 'active', 3 => 'paused'];

    public const TRIGGERS = ['new_member', 'birthday', 'inactivity', 'cart_abandonment', 'soft_bounce', 'manual'];

    protected const TABLE = 'edm_workflows';

    protected const FILLABLE = ['name', 'description', 'trigger', 'status', 'definition', 'created_by', 'created_by_name'];

    protected const CASTS = ['status' => 'int', 'definition' => 'json'];

    protected const ORDER = '`name` ASC';
}
