<?php

declare(strict_types=1);

namespace Edm\Services;

use DateTimeImmutable;
use Edm\Core\Database;
use Edm\Models\Campaign;

/**
 * Calendar conflict check (spec 5.2 step 3: "BPT checks calendar for a free
 * slot; conflict detection runs").
 *
 * A day is taken by:
 *   - another newsletter scheduled that day (any status except archived), or
 *   - a calendar slot reserved that day, unless the slot is linked to the
 *     newsletter being checked.
 *
 * Conflicts are warnings for BPT to judge, not a block. calendar/calendar.js
 * applies the same rule to the month grid.
 */
final class ScheduleConflicts
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array{type: string, id: int, name: string}>
     */
    public function forDate(DateTimeImmutable $date, ?int $campaignId = null, ?AccessScope $scope = null): array
    {
        $day = $date->format('Y-m-d');
        $exclude = $campaignId ?? 0;
        $out = [];
        // Only newsletters the user may see (own / department / all).
        [$cond, $condParams] = ($scope ?? AccessScope::all())->campaignWhere('c');

        $campaigns = $this->db->select(
            'SELECT c.`id`, c.`name` FROM `edm_campaigns` c
              WHERE c.`deleted_at` IS NULL AND c.`status` <> ? AND c.`scheduled_at` IS NOT NULL
                AND DATE(c.`scheduled_at`) = ? AND c.`id` <> ? AND ' . $cond . '
              ORDER BY c.`scheduled_at`',
            [Campaign::ARCHIVED, $day, $exclude, ...$condParams]
        );
        foreach ($campaigns as $r) {
            $out[] = ['type' => 'campaign', 'id' => (int) $r['id'], 'name' => (string) $r['name']];
        }

        $slots = $this->db->select(
            'SELECT `id`, `slot_label`, `category` FROM `edm_calendar_slots`
              WHERE `deleted_at` IS NULL AND `slot_date` = ?
                AND (`campaign_id` IS NULL OR `campaign_id` <> ?)
              ORDER BY `id`',
            [$day, $exclude]
        );
        foreach ($slots as $r) {
            $out[] = ['type' => 'slot', 'id' => (int) $r['id'], 'name' => (string) ($r['slot_label'] ?: ($r['category'] ?: 'Reserved slot'))];
        }

        return $out;
    }
}
