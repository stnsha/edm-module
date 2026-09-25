<?php

declare(strict_types=1);

namespace Edm\Core;

/**
 * The logged-in staff member and their EDM role tier (staff.edm):
 * 0 = none, 1 = superadmin, 2 = admin, 3 = bpt team, 4 = management.
 *
 * The dev role override (dev-switch-role.php, localhost only) replaces the
 * tier for UI testing but never grants superadmin.
 */
final class Auth
{
    public function __construct(
        public readonly ?int $staffId,
        public readonly ?string $staffName,
        public readonly int $edmFlag,
        public readonly int $permission,
        public readonly bool $isSuperadmin,
    ) {
    }

    public static function fromSession(Database $db): self
    {
        $staffId = null;
        $name = null;
        $flag = 0;

        $username = $_SESSION['myusername'] ?? null;
        if (is_string($username) && $username !== '') {
            $row = $db->first(
                'SELECT id, nama_staff, edm FROM staff WHERE username = ? AND recycle != 1 LIMIT 1',
                [$username]
            );
            if ($row !== null) {
                $staffId = (int) $row['id'];
                $name = (string) $row['nama_staff'];
                $flag = (int) ($row['edm'] ?? 0);
            }
        }

        $override = self::isLocal() && isset($_SESSION['edm_dev_role_override'])
            ? (int) $_SESSION['edm_dev_role_override']
            : null;

        return new self($staffId, $name, $flag, $override ?? $flag, $override === null && $flag === 1);
    }

    public function isLoggedIn(): bool
    {
        return $this->staffId !== null;
    }

    /** Any EDM tier (1-4) or a real superadmin. */
    public function hasAccess(): bool
    {
        return $this->permission >= 1 || $this->isSuperadmin;
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
