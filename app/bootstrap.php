<?php

declare(strict_types=1);

/**
 * EDM module bootstrap - loaded by every <folder>/api.php and by any page that
 * reads EDM data server-side.
 *
 * MVC layout (namespace Edm\, PSR-4 under app/):
 *   Core/         Database (mysqli over odb's $conn), Request, Response, Auth,
 *                 Validator, base Model + Controller, exceptions
 *   Models/       one class per edm_* table - persistence + casts
 *   Controllers/  one class per module folder - that page's actions
 *   Views         the module folders themselves (index.php + JS)
 *
 * All data lives in the odb database (edm_* tables, see sql/edm_master.sql).
 */

date_default_timezone_set('Asia/Kuala_Lumpur');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Edm\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!defined('EDM_BASE')) {
    define('EDM_BASE', '/odb/' . basename(dirname(__DIR__)) . '/');
}

// Reuse odb's connection when a page (header.php) already opened it.
if (!isset($conn) || !($conn instanceof mysqli)) {
    $connect = 1;
    include __DIR__ . '/../../common/index_adv.php';
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit;
}

\Edm\Core\Database::boot($conn);
