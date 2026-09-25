<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A member of a contact list.
 * Table: edm_list_members.
 */
final class ListMember extends Model
{
    public const STATUSES = [1 => 'subscribed', 2 => 'unsubscribed', 3 => 'bounced'];

    protected const TABLE = 'edm_list_members';

    protected const FILLABLE = ['list_id', 'member_code', 'email', 'status', 'subscribed_at'];

    protected const CASTS = ['status' => 'int', 'subscribed_at' => 'datetime'];

    protected const ORDER = '`id` ASC';
}
