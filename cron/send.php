<?php

declare(strict_types=1);

/**
 * EDM send queue - command line only. Run every minute by Windows Task
 * Scheduler through cron/edm-send.bat (setup steps are in that file).
 *
 *   php edm/cron/send.php
 *
 * Starts due scheduled newsletters and sends for up to ~50 seconds
 * (Edm\Services\Ses\CampaignSender). A lock file stops two runs overlapping.
 * Output goes to the console and to edm/logs/ses-send.log.
 */

use Edm\Core\Database;
use Edm\Services\Ses\CampaignSender;
use Edm\Services\Ses\SesException;
use Edm\Services\Ses\SesGateway;

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
    file_put_contents($logDir . '/ses-send.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
};

$lock = fopen($logDir . '/ses-send.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo 'Another send run is still working - skipped.', PHP_EOL;
    exit(0);
}

require dirname(__DIR__) . '/app/bootstrap.php';

$code = 1;
try {
    $sender = new CampaignSender(Database::get(), SesGateway::fromEnv(), $log);
    $sent = $sender->run($started + 50);
    if ($sent > 0) {
        $log("Sent {$sent} email(s) in " . round(microtime(true) - $started, 1) . 's.');
    }
    $code = 0;
} catch (SesException $e) {
    $log('SES error: ' . $e->getMessage());
    $code = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($code);
