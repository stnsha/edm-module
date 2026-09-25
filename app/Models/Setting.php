<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A grouped key/value setting (groups: general, integrations).
 * Table: edm_settings.
 */
final class Setting extends Model
{
    protected const TABLE = 'edm_settings';

    protected const FILLABLE = ['group', 'key', 'value', 'label'];

    protected const CASTS = [];

    protected const ORDER = '`group` ASC, `key` ASC';
}
