<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * An address that must never be emailed.
 * Table: edm_suppressions.
 */
final class Suppression extends Model
{
    public const REASONS = ['unsubscribed', 'hard_bounce', 'soft_bounce', 'spam_complaint', 'inactive', 'manual'];

    protected const TABLE = 'edm_suppressions';

    protected const FILLABLE = ['email', 'reason', 'source', 'note', 'created_by', 'created_by_name'];

    protected const CASTS = [];

    protected const ORDER = '`created_at` DESC, `id` DESC';

    /** Add an address unless it is already suppressed (any reason). */
    public static function suppress(string $email, string $reason, string $source, ?string $note = null): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || self::where('LOWER(`email`) = ?', [$email], null, 1) !== []) {
            return;
        }
        self::create([
            'email'  => $email,
            'reason' => $reason,
            'source' => $source,
            'note'   => $note !== null ? mb_substr($note, 0, 255) : null,
        ]);
    }
}
