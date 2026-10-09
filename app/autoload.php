<?php

declare(strict_types=1);

/**
 * Class loading and configuration only - no session, no database. Used by
 * app/bootstrap.php and by public endpoints that must stay light (the image
 * proxy public/file.php is hit once per image per recipient).
 */

date_default_timezone_set('Asia/Kuala_Lumpur');

// Composer packages (AWS SDK, SNS message validator, phpdotenv). vendor/ is
// not committed: run `composer install` in edm/ on every server.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    // edm/.env (git-ignored; template in .env.example) -> $_ENV. safeLoad():
    // a missing file is not an error, SES then reports "not configured".
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

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
