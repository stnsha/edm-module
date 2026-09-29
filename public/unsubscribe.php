<?php

declare(strict_types=1);

/**
 * Unsubscribe link target. PUBLIC endpoint (no odb login): recipients open it
 * from the email footer, and mail clients POST to it for one-click
 * unsubscribe (List-Unsubscribe-Post, RFC 8058).
 *
 *   GET   signed link -> confirmation page with an Unsubscribe button (link
 *         scanners and prefetchers must not unsubscribe anyone)
 *   POST  signed link -> suppress the address (reason: unsubscribed), mark the
 *         list member unsubscribed and stamp the send log
 *
 * Links are signed per campaign + address by Edm\Services\Ses\Unsubscribe.
 */

use Edm\Core\Database;
use Edm\Models\Campaign;
use Edm\Models\ListMember;
use Edm\Models\Suppression;
use Edm\Services\Ses\SesConfig;
use Edm\Services\Ses\Unsubscribe;

require __DIR__ . '/../app/bootstrap.php';

$link = (new Unsubscribe(SesConfig::fromEnv()))->verify(
    (string) ($_GET['c'] ?? ''),
    (string) ($_GET['e'] ?? ''),
    (string) ($_GET['t'] ?? '')
);

$done = false;
if ($link !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = strtolower($link['email']);
    $campaign = Campaign::find($link['campaign_id']);
    Suppression::suppress($email, 'unsubscribed', 'Unsubscribe link', 'Campaign #' . $link['campaign_id']);
    if ($campaign !== null && $campaign['list_id'] !== null) {
        foreach (ListMember::where('`list_id` = ? AND LOWER(`email`) = ?', [(int) $campaign['list_id'], $email]) as $m) {
            ListMember::update((int) $m['id'], ['status' => 2]); // 2 = unsubscribed
        }
    }
    Database::get()->execute(
        'UPDATE `edm_send_log` SET `unsubscribed_at` = COALESCE(`unsubscribed_at`, ?), `updated_at` = ?
          WHERE `campaign_id` = ? AND `email` = ? AND `deleted_at` IS NULL',
        [date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $link['campaign_id'], $email]
    );
    $done = true;
}

if ($link === null) {
    http_response_code(400);
}
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Unsubscribe</title>
    <style>
        body { margin: 0; background: #f2f5f7; font: 15px/1.5 Arial, sans-serif; color: #212529; }
        .box { max-width: 460px; margin: 12vh auto 0; padding: 32px 28px; background: #fff; border-radius: 10px;
               box-shadow: 0 1px 3px rgba(0, 0, 0, .08); text-align: center; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { margin: 0 0 20px; color: #495057; }
        button { padding: 10px 22px; font-size: 15px; border: 0; border-radius: 6px; background: #dc3545; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
<div class="box">
<?php if ($link === null): ?>
    <h1>Link not valid</h1>
    <p>This unsubscribe link is incomplete or has been changed. Please use the link from the email.</p>
<?php elseif ($done): ?>
    <h1>You have been unsubscribed</h1>
    <p><?php echo $h($link['email']); ?> will no longer receive these emails.</p>
<?php else: ?>
    <h1>Unsubscribe</h1>
    <p>Stop sending marketing emails to <strong><?php echo $h($link['email']); ?></strong>?</p>
    <form method="post">
        <button type="submit">Unsubscribe</button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
