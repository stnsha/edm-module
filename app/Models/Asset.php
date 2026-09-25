<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A Files-library entry: an uploaded image or an external URL.
 * Table: edm_assets.
 */
final class Asset extends Model
{
    protected const TABLE = 'edm_assets';

    protected const FILLABLE = ['name', 'url', 'type', 'size_bytes', 'uploaded_by', 'uploaded_by_name'];

    protected const CASTS = ['size_bytes' => 'int'];

    protected const ORDER = '`created_at` DESC, `id` DESC';
}
