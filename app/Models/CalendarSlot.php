<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A campaign calendar slot.
 * Table: edm_calendar_slots.
 */
final class CalendarSlot extends Model
{
    protected const TABLE = 'edm_calendar_slots';

    protected const FILLABLE = ['slot_date', 'slot_label', 'category', 'campaign_id', 'note'];

    protected const CASTS = ['slot_date' => 'date'];

    protected const ORDER = '`slot_date` ASC';
}
