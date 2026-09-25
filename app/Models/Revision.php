<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A revision request on a newsletter.
 * Table: edm_revisions.
 */
final class Revision extends Model
{
    protected const TABLE = 'edm_revisions';

    protected const FILLABLE = ['campaign_id', 'note', 'requested_by', 'requested_by_name'];

    protected const CASTS = [];

    protected const ORDER = '`created_at` DESC, `id` DESC';
}
