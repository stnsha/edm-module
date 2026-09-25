<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A timed autoresponder (offset_days relative to the trigger date).
 * Table: edm_autoresponders.
 */
final class Autoresponder extends Model
{
    public const STATUSES = [1 => 'draft', 2 => 'active', 3 => 'paused'];

    protected const TABLE = 'edm_autoresponders';

    protected const FILLABLE = ['name', 'list_id', 'offset_days', 'subject', 'status'];

    protected const CASTS = ['status' => 'int', 'offset_days' => 'int'];

    protected const ORDER = '`offset_days` ASC';
}
