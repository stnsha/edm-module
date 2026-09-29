<?php

declare(strict_types=1);

/**
 * EDM automated QA worker - command line only. Run every minute by
 * cron/edm-send.bat, after send.php.
 *
 *   php edm/cron/qa.php
 *
 * Works queued QA runs (Edm\Services\Qa\QaQueue) for up to ~40 seconds.
 * A lock file stops two runs overlapping. Output goes to the console and to
 * edm/logs/qa.log.
 */

use Edm\Core\Database;
use Edm\Services\Qa\QaQueue;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// odb's common/index_adv.php (DB connection) expects a web request.
$_SERVER['HTTP_HOST'] ??= 'localhost';

$started = microtime(true);
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

$log = static function (string $line) use ($logDir): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $line;
    echo $line, PHP_EOL;
    file_put_contents($logDir . '/qa.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
};

$lock = fopen($logDir . '/qa.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo 'Another QA run is still working - skipped.', PHP_EOL;
    exit(0);
}

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    (new QaQueue(Database::get()))->work($started + 40, null, $log);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit(0);
