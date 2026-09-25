<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A tag assigned to a member_code.
 * Table: edm_member_tags.
 */
final class MemberTag extends Model
{
    protected const TABLE = 'edm_member_tags';

    protected const FILLABLE = ['member_code', 'tag_id'];

    protected const CASTS = [];

    protected const ORDER = '`id` ASC';
}
