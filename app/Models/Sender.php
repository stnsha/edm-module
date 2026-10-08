<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * An email address on the sender masterlist: a newsletter picks one as its
 * Sender (From, must be verified in SES) and optionally one as its Reply-to.
 * Table: edm_senders.
 */
final class Sender extends Model
{
    public const STATUSES = [1 => 'pending', 2 => 'verified', 3 => 'failed'];

    protected const TABLE = 'edm_senders';

    protected const FILLABLE = ['email', 'from_name', 'status', 'verified_at', 'is_default', 'created_by', 'created_by_name'];

    protected const CASTS = ['status' => 'int', 'is_default' => 'bool', 'verified_at' => 'datetime'];

    protected const ORDER = '`is_default` DESC, `from_name` ASC';
}
