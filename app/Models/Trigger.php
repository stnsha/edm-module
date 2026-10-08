<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A custom workflow trigger, created from Automation > Workflows
 * ("+ Create new trigger"). Built-in triggers are Workflow::BUILT_IN_TRIGGERS;
 * edm_workflows.trigger stores either kind's key.
 * Table: edm_triggers.
 */
final class Trigger extends Model
{
    protected const TABLE = 'edm_triggers';

    protected const FILLABLE = ['key', 'label', 'description', 'created_by', 'created_by_name'];

    protected const CASTS = [];

    protected const ORDER = '`label` ASC';

    /**
     * Key from a label: "Points expiring soon" -> points_expiring_soon,
     * suffixed _2, _3 ... while a built-in or an active custom trigger has it.
     */
    public static function uniqueKey(string $label): string
    {
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) : $label;
        $base = mb_substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($ascii)), '_') ?: 'trigger', 0, 44);
        $key = $base;
        $i = 2;
        while (isset(Workflow::BUILT_IN_TRIGGERS[$key]) || self::exists(['key' => $key])) {
            $key = $base . '_' . $i++;
        }

        return $key;
    }
}
