<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * Per-recipient send history (frequency caps). Written by the send pipeline.
 * Table: edm_send_log.
 */
final class SendLog extends Model
{
    protected const TABLE = 'edm_send_log';

    protected const FILLABLE = ['campaign_id', 'member_code', 'email', 'sent_at'];

    protected const CASTS = ['sent_at' => 'datetime'];

    protected const ORDER = '`id` DESC';
}
