<?php

declare(strict_types=1);

namespace Edm\Core;

/**
 * The logged-in staff member and their EDM role (staff.edm):
 *   0 = normal user        - own newsletters, contacts, calendar, dashboard
 *   1 = superadmin         - everything
 *   2 = business unit head - everything of their department
 *                            (staff.department); approves its newsletters
 * Every logged-in odb staff has EDM access (0 included). Any other stored
 * value (the removed 3 bpt team / 4 management) counts as 0.
 *
 * The dev role override (dev-switch-role.php, localhost only) replaces the
 * role for UI testing but never grants the real-superadmin flag
 * ($isSuperadmin, used for Settings > Users & permissions).
 */
final class Auth
{
    public const NORMAL = 0;

    public const SUPERADMIN = 1;

    public const BU_HEAD = 2;

    /** Role => label (Settings > Users & permissions, dev toolbar). */
    public const ROLES = [
        self::NORMAL     => 'Normal user',
        self::SUPERADMIN => 'Super Admin',
        self::BU_HEAD    => 'Head of Business Unit',
    ];

    public function __construct(
        public readonly ?int $staffId,
        public readonly ?string $staffName,
        public readonly int $edmFlag,
        public readonly int $permission,
        public readonly bool $isSuperadmin,
        public readonly ?int $departmentId = null,
    ) {
    }

    public static function fromSession(Database $db): self
    {
        $staffId = null;
        $name = null;
        $flag = 0;
        $departmentId = null;

        $username = $_SESSION['myusername'] ?? null;
        if (is_string($username) && $username !== '') {
            $row = $db->first(
                'SELECT id, nama_staff, edm, department FROM staff WHERE username = ? AND recycle != 1 LIMIT 1',
                [$username]
            );
            if ($row !== null) {
                $staffId = (int) $row['id'];
                $name = (string) $row['nama_staff'];
                $flag = (int) ($row['edm'] ?? 0);
                $departmentId = (int) ($row['department'] ?? 0) > 0 ? (int) $row['department'] : null;
            }
        }

        $override = self::isLocal() && isset($_SESSION['edm_dev_role_override'])
            ? (int) $_SESSION['edm_dev_role_override']
            : null;

        return new self($staffId, $name, $flag, $override ?? $flag, $override === null && $flag === 1, $departmentId);
    }

    public function isLoggedIn(): bool
    {
        return $this->staffId !== null;
    }

    /**
     * The staff member's department name (odb staff.department ->
     * staff_department.depart_name), or null when none is set. Looked up on
     * demand - most requests do not need it.
     */
    public function department(Database $db): ?string
    {
        if ($this->staffId === null) {
            return null;
        }
        $name = $db->scalar(
            'SELECT d.`depart_name` FROM `staff` s
               LEFT JOIN `staff_department` d ON d.`id` = s.`department`
              WHERE s.`id` = ? LIMIT 1',
            [$this->staffId]
        );

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** Effective role: NORMAL, SUPERADMIN or BU_HEAD (unknown values = NORMAL). */
    public function role(): int
    {
        if ($this->isSuperadmin) {
            return self::SUPERADMIN;
        }

        return isset(self::ROLES[$this->permission]) ? $this->permission : self::NORMAL;
    }

    /** Superadmin (staff.edm = 1, or the dev override to 1): sees and does everything. */
    public function isAdmin(): bool
    {
        return $this->role() === self::SUPERADMIN;
    }

    /** Business unit head (role 2): the whole department's records. */
    public function isBuHead(): bool
    {
        return $this->role() === self::BU_HEAD;
    }

    /**
     * Normal user or business unit head: limited to their own / their
     * department's records (Services\AccessScope) and to the actions each
     * controller lists in allowsScoped() (Controller::run()).
     */
    public function isScoped(): bool
    {
        return !$this->isAdmin();
    }

    /** Every logged-in staff member has EDM access. */
    public function hasAccess(): bool
    {
        return $this->isLoggedIn();
    }

    public static function isLocal(): bool
    {
        $server = (string) ($_SERVER['SERVER_NAME'] ?? '');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        return in_array($server, ['localhost', '127.0.0.1'], true)
            || str_contains($server, 'localhost')
            || str_contains($host, 'localhost')
            || str_contains($host, '127.0.0.1');
    }
}
