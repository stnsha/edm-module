<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * An automation workflow (journey, spec section 8). definition holds the
 * journey plan: { entry, steps: [{ offset_days, type: email|retry|mark_inactive,
 * name }], exit }; its email steps are edm_autoresponders rows (workflow_id).
 * Table: edm_workflows.
 */
final class Workflow extends Model
{
    public const STATUSES = [1 => 'draft', 2 => 'active', 3 => 'paused'];

    public const TRIGGERS = ['new_member', 'birthday', 'inactivity', 'cart_abandonment', 'soft_bounce', 'manual'];

    protected const TABLE = 'edm_workflows';

    protected const FILLABLE = ['name', 'description', 'trigger', 'status', 'definition', 'created_by', 'created_by_name'];

    protected const CASTS = ['status' => 'int', 'definition' => 'json'];

    protected const ORDER = '`name` ASC';

    /** Soft delete; its steps stay as standalone autoresponders (FK cascades do not fire on a soft delete). */
    public static function delete(int $id): void
    {
        self::findOrFail($id);
        self::db()->transaction(static function () use ($id): void {
            Autoresponder::unlinkWhere('workflow_id', $id);
            parent::delete($id);
        });
    }
}
