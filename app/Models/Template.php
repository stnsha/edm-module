<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Database;
use Edm\Core\Model;

/**
 * A reusable email template: html (rendered email) + editor_json
 * (EmailBuilder.js block tree, so the design reopens editable).
 * Table: edm_templates.
 */
final class Template extends Model
{
    protected const TABLE = 'edm_templates';

    protected const FILLABLE = ['name', 'category', 'thumbnail_url', 'html', 'editor_json', 'created_by', 'created_by_name'];

    protected const CASTS = ['editor_json' => 'json'];

    protected const ORDER = '`name` ASC';

    /**
     * Active templates for a picker, without the heavy html / editor_json.
     *
     * @return list<array{id: int, name: string, category: ?string}>
     */
    public static function options(): array
    {
        $rows = self::db()->select(
            'SELECT `id`, `name`, `category` FROM ' . Database::ident(self::TABLE)
            . ' WHERE `deleted_at` IS NULL ORDER BY ' . self::ORDER
        );

        return array_map(static fn (array $r): array => [
            'id'       => (int) $r['id'],
            'name'     => (string) $r['name'],
            'category' => $r['category'] !== null ? (string) $r['category'] : null,
        ], $rows);
    }
}
