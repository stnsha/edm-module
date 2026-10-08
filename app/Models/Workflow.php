<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;
use Edm\Core\ValidationException;

/**
 * An automation workflow (journey, spec section 8). definition holds the
 * journey plan: { entry, steps: [{ offset_days, type: email|retry|mark_inactive,
 * name }], exit }; its email steps are edm_autoresponders rows (workflow_id).
 * content_type + content_id: the email it sends - a newsletter (edm_campaigns)
 * or a template (edm_templates); both null = none chosen yet.
 * Table: edm_workflows.
 */
final class Workflow extends Model
{
    public const STATUSES = [1 => 'draft', 2 => 'active', 3 => 'paused'];

    /** Triggers that come with the module (spec section 8), key => label. */
    public const BUILT_IN_TRIGGERS = [
        'new_member'       => 'New member registration',
        'birthday'         => 'Birthday (DOB match)',
        'inactivity'       => 'No activity for 90 days',
        'cart_abandonment' => 'Cart abandoned',
        'soft_bounce'      => 'Soft bounce detected',
        'manual'           => 'Manual',
    ];

    /**
     * Every trigger a workflow can use, key => label: the built-ins, then the
     * custom ones (edm_triggers) by name.
     *
     * @return array<string, string>
     */
    public static function triggers(): array
    {
        $out = self::BUILT_IN_TRIGGERS;
        foreach (Trigger::all() as $t) {
            $out[(string) $t['key']] = (string) $t['label'];
        }

        return $out;
    }

    protected const TABLE = 'edm_workflows';

    protected const FILLABLE = [
        'name', 'description', 'trigger_type', 'trigger', 'timing', 'status', 'definition', 'content_type', 'content_id',
        'created_by', 'created_by_name',
    ];

    protected const CASTS = ['status' => 'int', 'definition' => 'json', 'timing' => 'json', 'content_id' => 'int'];

    /**
     * When a workflow sends (trigger_type + timing):
     *   event     an event (trigger) happens, then send now or after a delay,
     *             optionally at a time of day
     *   schedule  on a date (once) or repeating daily / weekly / monthly, at a time
     */
    public const TRIGGER_TYPES = ['event' => 'When something happens', 'schedule' => 'On a schedule'];
    public const DELAY_UNITS = ['minutes' => 'minutes', 'hours' => 'hours', 'days' => 'days'];
    public const REPEATS = ['once' => 'Once', 'daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'];
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * trigger_type + timing from the form's flat fields (trigger_type,
     * send_mode, delay_value, delay_unit, send_time, repeat, schedule_date,
     * schedule_time, schedule_weekday, schedule_day). Only the fields of the
     * chosen type are kept.
     *
     * @param array<string, mixed> $in
     * @throws ValidationException naming the field at fault
     * @return array{trigger_type: string, timing: array<string, mixed>}
     */
    public static function timingInput(array $in): array
    {
        $type = (string) ($in['trigger_type'] ?? 'event');
        if (!isset(self::TRIGGER_TYPES[$type])) {
            throw ValidationException::single('trigger_type', 'Choose when the workflow sends.');
        }
        $time = static function (mixed $v, string $field, bool $required): ?string {
            $v = trim((string) ($v ?? ''));
            if ($v === '') {
                if ($required) {
                    throw ValidationException::single($field, 'Choose a time.');
                }
                return null;
            }
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:\d\d)?$/', $v, $m) !== 1) {
                throw ValidationException::single($field, 'Enter a time as HH:MM.');
            }
            return $m[1] . ':' . $m[2];
        };

        if ($type === 'event') {
            $mode = (string) ($in['send_mode'] ?? 'now');
            if ($mode !== 'now' && $mode !== 'delay') {
                throw ValidationException::single('send_mode', 'Choose when to send after the event.');
            }
            $timing = ['send' => $mode, 'delay_value' => null, 'delay_unit' => null, 'at_time' => $time($in['send_time'] ?? null, 'send_time', false)];
            if ($mode === 'delay') {
                $value = filter_var($in['delay_value'] ?? null, FILTER_VALIDATE_INT);
                if ($value === false || $value < 1 || $value > 999) {
                    throw ValidationException::single('delay_value', 'Enter a delay from 1 to 999.');
                }
                $unit = (string) ($in['delay_unit'] ?? '');
                if (!isset(self::DELAY_UNITS[$unit])) {
                    throw ValidationException::single('delay_unit', 'Choose minutes, hours or days.');
                }
                $timing['delay_value'] = $value;
                $timing['delay_unit'] = $unit;
            }

            return ['trigger_type' => 'event', 'timing' => $timing];
        }

        $repeat = (string) ($in['repeat'] ?? '');
        if (!isset(self::REPEATS[$repeat])) {
            throw ValidationException::single('repeat', 'Choose how often it sends.');
        }
        $timing = ['repeat' => $repeat, 'date' => null, 'time' => $time($in['schedule_time'] ?? null, 'schedule_time', true), 'weekday' => null, 'day' => null];
        if ($repeat === 'once') {
            $date = self::parseDate((string) ($in['schedule_date'] ?? ''));
            if ($date === null) {
                throw ValidationException::single('schedule_date', 'Choose the date.');
            }
            $timing['date'] = $date->format('Y-m-d');
        } elseif ($repeat === 'weekly') {
            $day = (int) ($in['schedule_weekday'] ?? 0);
            if (!isset(self::WEEKDAYS[$day])) {
                throw ValidationException::single('schedule_weekday', 'Choose the day of the week.');
            }
            $timing['weekday'] = $day;
        } elseif ($repeat === 'monthly') {
            $day = filter_var($in['schedule_day'] ?? null, FILTER_VALIDATE_INT);
            if ($day === false || $day < 1 || $day > 31) {
                throw ValidationException::single('schedule_day', 'Enter a day of the month from 1 to 31.');
            }
            $timing['day'] = $day;
        }

        return ['trigger_type' => 'schedule', 'timing' => $timing];
    }

    /** One line, e.g. "Birthday - 2 days after, at 10:00" or "Every week on Monday at 09:00". */
    public static function describeTiming(array $row, string $triggerLabel): string
    {
        $t = is_array($row['timing'] ?? null) ? $row['timing'] : [];
        if (($row['trigger_type'] ?? 'event') === 'schedule') {
            $time = (string) ($t['time'] ?? '');
            return match ($t['repeat'] ?? '') {
                'once'    => 'Once on ' . date('d-m-Y', strtotime((string) ($t['date'] ?? ''))) . ' at ' . $time,
                'daily'   => 'Every day at ' . $time,
                'weekly'  => 'Every week on ' . (self::WEEKDAYS[(int) ($t['weekday'] ?? 0)] ?? '?') . ' at ' . $time,
                'monthly' => 'Every month on day ' . (int) ($t['day'] ?? 0) . ' at ' . $time,
                default   => 'On a schedule',
            };
        }
        $when = ($t['send'] ?? 'now') === 'delay'
            ? (int) ($t['delay_value'] ?? 0) . ' ' . ((int) ($t['delay_value'] ?? 0) === 1 ? rtrim((string) ($t['delay_unit'] ?? ''), 's') : (string) ($t['delay_unit'] ?? '')) . ' after'
            : 'immediately';

        return $triggerLabel . ' - ' . $when . (!empty($t['at_time']) ? ', at ' . $t['at_time'] : '');
    }

    /** What a workflow can send: content_type values. */
    public const CONTENT_TYPES = ['campaign', 'template'];

    /**
     * The form's single "Email content" value ("campaign:12", "template:3"
     * or "") as content_type / content_id; null when not in the request.
     *
     * @throws ValidationException on content when malformed or the row does not exist
     * @return array{content_type: ?string, content_id: ?int}|null
     */
    public static function contentInput(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return ['content_type' => null, 'content_id' => null];
        }
        if (preg_match('/^(campaign|template):(\d+)$/', $value, $m) !== 1) {
            throw ValidationException::single('content', 'Choose a newsletter or a template.');
        }
        $id = (int) $m[2];
        $exists = $m[1] === 'campaign' ? Campaign::find($id) !== null : Template::find($id) !== null;
        if (!$exists) {
            throw ValidationException::single('content', 'The chosen ' . $m[1] . ' was deleted - choose another.');
        }

        return ['content_type' => $m[1], 'content_id' => $id];
    }

    /**
     * A row for the list / form: `trigger_label`, `content` ("type:id" or "")
     * and `content_label` ("Newsletter: name", "Template: name", "Deleted ..." or "").
     */
    public static function withContent(array $row, ?array $triggers = null): array
    {
        $triggers ??= self::triggers();
        $row['trigger_label'] = $triggers[(string) ($row['trigger'] ?? '')] ?? (string) ($row['trigger'] ?? '');
        $row['trigger_type'] = ($row['trigger_type'] ?? '') === 'schedule' ? 'schedule' : 'event';
        $row['when_label'] = self::describeTiming($row, $row['trigger_label']);
        // The form's flat fields, so Edit opens with the saved timing.
        $t = is_array($row['timing'] ?? null) ? $row['timing'] : [];
        $row['send_mode'] = ($t['send'] ?? 'now') === 'delay' ? 'delay' : 'now';
        $row['delay_value'] = $t['delay_value'] ?? null;
        $row['delay_unit'] = $t['delay_unit'] ?? 'days';
        $row['send_time'] = $t['at_time'] ?? '';
        $row['repeat'] = $t['repeat'] ?? 'once';
        $row['schedule_date'] = $t['date'] ?? '';
        $row['schedule_time'] = $t['time'] ?? '';
        $row['schedule_weekday'] = $t['weekday'] ?? 1;
        $row['schedule_day'] = $t['day'] ?? null;
        $type = $row['content_type'] ?? null;
        $id = $row['content_id'] ?? null;
        $row['content'] = $type !== null && $id !== null ? $type . ':' . $id : '';
        $row['content_label'] = '';
        if ($row['content'] !== '') {
            $source = $type === 'campaign' ? Campaign::find((int) $id) : Template::find((int) $id);
            $label = $type === 'campaign' ? 'Newsletter' : 'Template';
            $row['content_label'] = $source !== null
                ? $label . ': ' . $source['name']
                : 'Deleted ' . strtolower($label);
        }

        return $row;
    }

    protected const ORDER = '`name` ASC';

    /** Soft delete; its steps stay as standalone autoresponders (FK cascades do not fire on a soft delete). */
    public static function delete(int $id): void
    {
        self::findOrFail($id);
        self::db()->transaction(static function () use ($id): void {
            Autoresponder::unlinkWhere('workflow_id', $id);
            parent::delete($id);
        });
    }
}
