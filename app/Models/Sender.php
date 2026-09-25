<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A From-address newsletters are sent from.
 * Table: edm_senders.
 */
final class Sender extends Model
{
    public const STATUSES = [1 => 'pending', 2 => 'verified', 3 => 'failed'];

    protected const TABLE = 'edm_senders';

    protected const FILLABLE = ['email', 'from_name', 'reply_to', 'status', 'verified_at', 'is_default', 'created_by', 'created_by_name'];

    protected const CASTS = ['status' => 'int', 'is_default' => 'bool', 'verified_at' => 'datetime'];

    protected const ORDER = '`is_default` DESC, `from_name` ASC';
}
