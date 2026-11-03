<?php

declare(strict_types=1);

namespace Edm\Services;

use Edm\Core\Auth;

/**
 * Which records a user may see, or which contacts a newsletter may reach.
 *
 *   all         superadmin: every record
 *   department  business unit head: records of their department
 *               (edm_campaigns.department_id / edm_contacts.department_id)
 *   owner       normal user: records they created
 *               (edm_campaigns.requested_by / edm_contacts.owner_id)
 *
 * Ownership columns come from sql/migrations/2026_11_03_1001*_add_owner_columns_*. Rows from
 * before that migration have NULL owner / department and owner_role 1, so
 * they belong to superadmin: only the "all" scope sees them.
 *
 * where methods return a bound SQL condition: [sql, params].
 */
final class AccessScope
{
    public const ALL = 'all';

    public const DEPARTMENT = 'department';

    public const OWNER = 'owner';

    private function __construct(public readonly string $kind, public readonly ?int $id)
    {
    }

    public static function all(): self
    {
        return new self(self::ALL, null);
    }

    public static function department(int $departmentId): self
    {
        return new self(self::DEPARTMENT, $departmentId);
    }

    public static function owner(int $staffId): self
    {
        return new self(self::OWNER, $staffId);
    }

    /**
     * What the signed-in user may see. A business unit head without a
     * department, like a normal user, sees only their own records.
     */
    public static function forUser(Auth $auth): self
    {
        if ($auth->isAdmin()) {
            return self::all();
        }
        if ($auth->isBuHead() && $auth->departmentId !== null) {
            return self::department($auth->departmentId);
        }

        return self::owner((int) $auth->staffId);
    }

    /**
     * The contacts a newsletter may reach, from its creator's role stored at
     * creation (owner_role): superadmin = all, business unit head = their
     * department, normal user = the creator's own contacts.
     *
     * @param array<string, mixed> $campaign an edm_campaigns row
     */
    public static function forCampaign(array $campaign): self
    {
        $role = (int) ($campaign['owner_role'] ?? Auth::SUPERADMIN);
        $department = isset($campaign['department_id']) ? (int) $campaign['department_id'] : 0;
        $owner = isset($campaign['requested_by']) ? (int) $campaign['requested_by'] : 0;

        return match (true) {
            $role === Auth::SUPERADMIN                   => self::all(),
            $role === Auth::BU_HEAD && $department > 0   => self::department($department),
            default                                      => self::owner($owner),
        };
    }

    public function isAll(): bool
    {
        return $this->kind === self::ALL;
    }

    /**
     * Newsletters (edm_campaigns aliased $a; '' = no alias) in this scope.
     *
     * @return array{0: string, 1: list<int>}
     */
    public function campaignWhere(string $a = 'c'): array
    {
        $p = $a !== '' ? $a . '.' : '';

        return match ($this->kind) {
            self::DEPARTMENT => [$p . '`department_id` = ?', [(int) $this->id]],
            self::OWNER      => [$p . '`requested_by` = ?', [(int) $this->id]],
            default          => ['1 = 1', []],
        };
    }

    /**
     * Contacts (edm_contacts aliased $a; '' = no alias) in this scope.
     *
     * @return array{0: string, 1: list<int>}
     */
    public function contactWhere(string $a = 'm'): array
    {
        $p = $a !== '' ? $a . '.' : '';

        return match ($this->kind) {
            self::DEPARTMENT => [$p . '`department_id` = ?', [(int) $this->id]],
            self::OWNER      => [$p . '`owner_id` = ?', [(int) $this->id]],
            default          => ['1 = 1', []],
        };
    }

    /**
     * Send log rows (aliased $a, or unaliased when $a = '') of newsletters in
     * this scope, as " AND ..." to append; empty for "all".
     *
     * @return array{0: string, 1: list<int>}
     */
    public function sendLogAnd(string $a = ''): array
    {
        if ($this->isAll()) {
            return ['', []];
        }
        [$cond, $params] = $this->campaignWhere('sc');
        $col = ($a !== '' ? $a . '.' : '') . '`campaign_id`';

        return [' AND ' . $col . ' IN (SELECT sc.`id` FROM `edm_campaigns` sc WHERE ' . $cond . ')', $params];
    }

    /** Whether a contact row is in this scope. */
    public function hasContact(array $contact): bool
    {
        return match ($this->kind) {
            self::DEPARTMENT => (int) ($contact['department_id'] ?? 0) === $this->id,
            self::OWNER      => (int) ($contact['owner_id'] ?? 0) === $this->id,
            default          => true,
        };
    }

    /** Whether a newsletter row is in this scope. */
    public function hasCampaign(array $campaign): bool
    {
        return match ($this->kind) {
            self::DEPARTMENT => (int) ($campaign['department_id'] ?? 0) === $this->id,
            self::OWNER      => (int) ($campaign['requested_by'] ?? 0) === $this->id,
            default          => true,
        };
    }

    /**
     * Ownership columns for a contact the user adds (import): superadmin
     * contacts stay unowned (NULL = superadmin, as before the migration).
     *
     * @return array{owner_id: ?int, department_id: ?int}
     */
    public static function contactStamp(Auth $auth): array
    {
        return $auth->isAdmin()
            ? ['owner_id' => null, 'department_id' => null]
            : ['owner_id' => $auth->staffId, 'department_id' => $auth->departmentId];
    }

    /**
     * Ownership columns for a newsletter the user creates.
     *
     * @return array{owner_role: int, department_id: ?int}
     */
    public static function campaignStamp(Auth $auth): array
    {
        return $auth->isAdmin()
            ? ['owner_role' => Auth::SUPERADMIN, 'department_id' => null]
            : ['owner_role' => $auth->role(), 'department_id' => $auth->departmentId];
    }
}
