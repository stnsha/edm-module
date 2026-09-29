<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * Artwork attached to a review request.
 * Table: edm_approval_files.
 */
final class ApprovalFile extends Model
{
    protected const TABLE = 'edm_approval_files';

    protected const FILLABLE = ['approval_id', 'name', 'url', 'mime', 'size_bytes', 'uploaded_by', 'uploaded_by_name'];

    protected const CASTS = ['approval_id' => 'int', 'size_bytes' => 'int'];

    protected const ORDER = '`id` ASC';
}
