<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A raw Amazon SES event (Send, Delivery, Bounce, Complaint, Open, Click, ...)
 * as received through SNS by public/ses-webhook.php. Kept for audit / BI; the
 * per-recipient outcome is rolled up onto edm_send_log.
 * Table: edm_ses_events.
 */
final class SesEvent extends Model
{
    protected const TABLE = 'edm_ses_events';

    protected const FILLABLE = ['ses_message_id', 'event_type', 'email', 'payload', 'occurred_at'];

    protected const CASTS = ['payload' => 'json', 'occurred_at' => 'datetime'];

    protected const ORDER = '`id` DESC';
}
