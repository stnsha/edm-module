<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A contact on the masterlist (one row per email address). `fields` holds
 * Contacts > Custom fields values keyed by the field key ({{key}} in a
 * design); `source` says how the contact arrived (e.g. "import").
 * Table: edm_contacts.
 */
final class Contact extends Model
{
    public const STATUSES = [1 => 'subscribed', 2 => 'unsubscribed', 3 => 'bounced'];

    public const SUBSCRIBED = 1;

    public const UNSUBSCRIBED = 2;

    protected const TABLE = 'edm_contacts';

    protected const FILLABLE = ['email', 'name', 'fields', 'source', 'status', 'subscribed_at'];

    protected const CASTS = ['status' => 'int', 'fields' => 'json', 'subscribed_at' => 'datetime'];

    protected const ORDER = '`id` DESC';
}
