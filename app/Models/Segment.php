<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A saved AND/OR segment over the contact masterlist;
 * definition = { match: all|any, groups: [ { match, rules: [...] } ] }.
 * Table: edm_segments.
 */
final class Segment extends Model
{
    protected const TABLE = 'edm_segments';

    protected const FILLABLE = ['name', 'description', 'definition', 'created_by', 'created_by_name'];

    protected const CASTS = ['definition' => 'json'];

    protected const ORDER = '`name` ASC';
}
