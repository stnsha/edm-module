<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * An address that must never be emailed.
 * Table: edm_suppressions.
 */
final class Suppression extends Model
{
    public const REASONS = ['unsubscribed', 'hard_bounce', 'soft_bounce', 'spam_complaint', 'inactive', 'manual'];

    protected const TABLE = 'edm_suppressions';

    protected const FILLABLE = ['email', 'reason', 'source', 'note', 'created_by', 'created_by_name'];

    protected const CASTS = [];

    protected const ORDER = '`created_at` DESC, `id` DESC';
}
