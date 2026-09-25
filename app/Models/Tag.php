<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A contact tag.
 * Table: edm_tags.
 */
final class Tag extends Model
{
    protected const TABLE = 'edm_tags';

    protected const FILLABLE = ['name', 'color', 'description'];

    protected const CASTS = [];

    protected const ORDER = '`name` ASC';

    /** Soft delete with its member assignments. */
    public static function delete(int $id): void
    {
        self::findOrFail($id);
        self::db()->transaction(static function () use ($id): void {
            MemberTag::deleteWhere('tag_id', $id);
            parent::delete($id);
        });
    }
}
