<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;
use Edm\Core\ValidationException;

/**
 * A newsletter tag on the master list (e.g. Promotion, Birthday). A newsletter
 * holds its tags as edm_campaigns.tag_ids. Not the contact tags (Tag).
 * Table: edm_master_tags.
 */
final class MasterTag extends Model
{
    /** Most tags one newsletter can have. */
    public const MAX_PER_NEWSLETTER = 10;

    protected const TABLE = 'edm_master_tags';

    protected const FILLABLE = ['name', 'created_by', 'created_by_name'];

    protected const CASTS = [];

    protected const ORDER = '`name` ASC';

    /**
     * The tag ids to store for a newsletter: chosen ids (must exist) plus
     * new names typed in the form - a name already on the list (any case)
     * reuses that tag, any other is created. Each id once, in order.
     *
     * @param array<string, mixed> $stamp created_by / created_by_name
     * @throws ValidationException on tags
     * @return list<int>
     */
    public static function resolve(mixed $ids, mixed $newNames, array $stamp): array
    {
        $out = [];
        foreach ((array) ($ids ?? []) as $v) {
            $id = (int) $v;
            if ($id <= 0) {
                continue;
            }
            if (self::find($id) === null) {
                throw ValidationException::single('tags', 'A chosen tag was deleted - remove it and save again.');
            }
            $out[$id] = true;
        }
        $byName = [];
        foreach (self::all() as $t) {
            $byName[mb_strtolower((string) $t['name'])] = (int) $t['id'];
        }
        foreach ((array) ($newNames ?? []) as $v) {
            $name = trim((string) $v);
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 100) {
                throw ValidationException::single('tags', 'A tag name must not be longer than 100 characters.');
            }
            $key = mb_strtolower($name);
            if (!isset($byName[$key])) {
                $byName[$key] = (int) self::create(['name' => $name] + $stamp)['id'];
            }
            $out[$byName[$key]] = true;
        }
        if (count($out) > self::MAX_PER_NEWSLETTER) {
            throw ValidationException::single('tags', 'A newsletter can have at most ' . self::MAX_PER_NEWSLETTER . ' tags.');
        }

        return array_keys($out);
    }

    /**
     * { id, name } for the given ids that still exist, in that order.
     *
     * @param list<int>|null $ids
     * @return list<array{id: int, name: string}>
     */
    public static function named(?array $ids): array
    {
        $out = [];
        foreach ($ids ?? [] as $id) {
            $tag = self::find((int) $id);
            if ($tag !== null) {
                $out[] = ['id' => (int) $tag['id'], 'name' => (string) $tag['name']];
            }
        }

        return $out;
    }
}
