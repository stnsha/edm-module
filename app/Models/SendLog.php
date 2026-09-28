<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * Per-recipient send history: frequency caps + SES delivery state. One row per
 * (campaign, recipient) the send pipeline has processed, including skipped
 * recipients, so a campaign resumes where it stopped. Delivery / open / click /
 * bounce / complaint timestamps are filled in by SES events.
 * Table: edm_send_log.
 */
final class SendLog extends Model
{
    public const STATUSES = [1 => 'sent', 2 => 'delivered', 3 => 'bounced', 4 => 'complained', 5 => 'failed', 6 => 'skipped'];

    public const SENT = 1;
    public const DELIVERED = 2;
    public const BOUNCED = 3;
    public const COMPLAINED = 4;
    public const FAILED = 5;
    public const SKIPPED = 6;

    protected const TABLE = 'edm_send_log';

    protected const FILLABLE = [
        'campaign_id', 'member_code', 'email', 'sent_at', 'status', 'ses_message_id', 'error',
        'delivered_at', 'opened_at', 'clicked_at', 'bounced_at', 'complained_at', 'unsubscribed_at',
    ];

    protected const CASTS = [
        'status' => 'int', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'opened_at' => 'datetime',
        'clicked_at' => 'datetime', 'bounced_at' => 'datetime', 'complained_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    protected const ORDER = '`id` DESC';
}
