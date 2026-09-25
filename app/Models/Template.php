<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A reusable email template.
 * Table: edm_templates.
 */
final class Template extends Model
{
    protected const TABLE = 'edm_templates';

    protected const FILLABLE = ['name', 'category', 'thumbnail_url', 'html', 'created_by', 'created_by_name'];

    protected const CASTS = [];

    protected const ORDER = '`name` ASC';
}
