<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A custom contact field; its key is the {{key}} personalisation variable.
 * Table: edm_custom_fields.
 */
final class CustomField extends Model
{
    public const TYPES = ['text', 'number', 'date', 'boolean', 'select'];

    protected const TABLE = 'edm_custom_fields';

    protected const FILLABLE = ['key', 'label', 'type', 'options', 'is_active'];

    protected const CASTS = ['options' => 'json', 'is_active' => 'bool'];

    protected const ORDER = '`label` ASC';

    /**
     * Variable key from a label: "Favourite Outlet" -> favourite_outlet,
     * suffixed _2, _3 ... while an active field already uses it.
     */
    public static function uniqueKey(string $label): string
    {
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) : $label;
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($ascii)), '_') ?: 'field';
        $key = $base;
        $i = 2;
        while (self::exists(['key' => $key])) {
            $key = $base . '_' . $i++;
        }

        return $key;
    }
}
