<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A timed autoresponder: one email step of a journey (workflow_id ->
 * edm_workflows), sent offset_days relative to the journey's trigger date.
 * Table: edm_autoresponders.
 */
final class Autoresponder extends Model
{
    public const STATUSES = [1 => 'draft', 2 => 'active', 3 => 'paused'];

    protected const TABLE = 'edm_autoresponders';

    protected const FILLABLE = ['name', 'workflow_id', 'list_id', 'offset_days', 'subject', 'status'];

    protected const CASTS = ['status' => 'int', 'workflow_id' => 'int', 'offset_days' => 'int'];

    protected const ORDER = '`workflow_id` ASC, `offset_days` ASC';
}
