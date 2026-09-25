<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A contact list (class name avoids the reserved word "List").
 * Table: edm_lists.
 */
final class ContactList extends Model
{
    protected const TABLE = 'edm_lists';

    protected const FILLABLE = ['name', 'description', 'is_active', 'created_by', 'created_by_name'];

    protected const CASTS = ['is_active' => 'bool'];

    protected const ORDER = '`name` ASC';

    /**
     * Every list with `members_count`.
     *
     * @return list<array<string, mixed>>
     */
    public static function allWithMemberCount(): array
    {
        $rows = self::db()->select(
            'SELECT l.*, (SELECT COUNT(*) FROM `edm_list_members` m
                    WHERE m.`list_id` = l.`id` AND m.`deleted_at` IS NULL) AS members_count
             FROM `edm_lists` l WHERE l.`deleted_at` IS NULL ORDER BY l.`name` ASC'
        );

        return array_map(static function (array $row): array {
            $row = self::present($row);
            $row['members_count'] = (int) $row['members_count'];

            return $row;
        }, $rows);
    }

    /**
     * Soft delete with its dependants: members are soft-deleted; segments
     * and autoresponders keep existing but lose the list link.
     */
    public static function delete(int $id): void
    {
        self::findOrFail($id);
        self::db()->transaction(static function () use ($id): void {
            ListMember::deleteWhere('list_id', $id);
            Segment::unlinkWhere('list_id', $id);
            Autoresponder::unlinkWhere('list_id', $id);
            parent::delete($id);
        });
    }

    /** One list with `members_count`. */
    public static function withMemberCount(int $id): array
    {
        $row = self::findOrFail($id);
        $row['members_count'] = (int) self::db()->scalar(
            'SELECT COUNT(*) FROM `edm_list_members` WHERE `list_id` = ? AND `deleted_at` IS NULL',
            [$id]
        );

        return $row;
    }
}
