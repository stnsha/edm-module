<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A sending domain and its DNS authentication state.
 * Table: edm_sending_domains.
 */
final class SendingDomain extends Model
{
    public const STATUSES = [1 => 'pending', 2 => 'verified', 3 => 'failed'];

    protected const TABLE = 'edm_sending_domains';

    protected const FILLABLE = ['domain', 'dkim_status', 'spf_status', 'dmarc_status', 'is_active'];

    protected const CASTS = ['dkim_status' => 'int', 'spf_status' => 'int', 'dmarc_status' => 'int', 'is_active' => 'bool'];

    protected const ORDER = '`domain` ASC';
}
