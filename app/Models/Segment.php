<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A saved AND/OR segment; definition = { match: all|any, rules: [...] }.
 * Table: edm_segments.
 */
final class Segment extends Model
{
    protected const TABLE = 'edm_segments';

    protected const FILLABLE = ['name', 'description', 'definition', 'list_id', 'created_by', 'created_by_name'];

    protected const CASTS = ['definition' => 'json'];

    protected const ORDER = '`name` ASC';
}
