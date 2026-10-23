<?php

declare(strict_types=1);

/**
 * Unsubscribe link target. PUBLIC endpoint (no odb login): recipients open it
 * from the email footer, and mail clients POST to it for one-click
 * unsubscribe (List-Unsubscribe-Post, RFC 8058).
 *
 *   GET   signed link -> confirmation page with an optional "Why are you
 *         leaving?" choice (Unsubscribe::REASONS + a comment) and an
 *         Unsubscribe button (link scanners and prefetchers must not
 *         unsubscribe anyone)
 *   preview=1 (the link in a newsletter Preview): same page, POST changes nothing
 *   POST  signed link -> suppress the address (reason: unsubscribed), mark the
 *         contact unsubscribed and stamp the send log, with the reason and
 *         comment when the form sent them. A one-click POST from the mail
 *         client carries no reason (stored NULL = no reason given).
 *
 * Links are signed per newsletter + address by Edm\Services\Ses\Unsubscribe.
 */

use Edm\Core\Database;
use Edm\Models\Contact;
use Edm\Models\Suppression;
use Edm\Services\Ses\SesConfig;
use Edm\Services\Ses\Unsubscribe;

require __DIR__ . '/../app/bootstrap.php';

$link = (new Unsubscribe(SesConfig::fromEnv()))->verify(
    (string) ($_GET['c'] ?? ''),
    (string) ($_GET['e'] ?? ''),
    (string) ($_GET['t'] ?? '')
);

// preview=1: the link from the newsletter Preview. The page looks the same,
// but submitting it changes nothing (it is a real recipient's link).
$preview = ($_GET['preview'] ?? '') === '1';

$done = false;
if ($link !== null && $preview && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $done = true;
} elseif ($link !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = strtolower($link['email']);
    $reason = (string) ($_POST['reason'] ?? '');
    $reason = isset(Unsubscribe::REASONS[$reason]) ? $reason : null;
    $comment = trim((string) ($_POST['comment'] ?? ''));
    $comment = $comment !== '' ? mb_substr($comment, 0, Unsubscribe::COMMENT_MAX) : null;
    Suppression::suppress($email, 'unsubscribed', 'Unsubscribe link', 'Newsletter #' . $link['campaign_id']);
    foreach (Contact::where('LOWER(`email`) = ?', [$email]) as $m) {
        Contact::update((int) $m['id'], ['status' => Contact::UNSUBSCRIBED]);
    }
    Database::get()->execute(
        'UPDATE `edm_send_log` SET `unsubscribed_at` = COALESCE(`unsubscribed_at`, ?),
                `unsubscribe_reason` = COALESCE(`unsubscribe_reason`, ?),
                `unsubscribe_comment` = COALESCE(`unsubscribe_comment`, ?), `updated_at` = ?
          WHERE `campaign_id` = ? AND `email` = ? AND `deleted_at` IS NULL',
        [date('Y-m-d H:i:s'), $reason, $comment, date('Y-m-d H:i:s'), $link['campaign_id'], $email]
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
        fieldset { border: 0; margin: 0 0 20px; padding: 0; text-align: left; }
        legend { font-size: 14px; font-weight: bold; margin-bottom: 8px; }
        label { display: block; font-size: 14px; padding: 4px 0; cursor: pointer; }
        label input { margin-right: 8px; }
        textarea { width: 100%; box-sizing: border-box; margin-top: 8px; padding: 8px; font: 14px Arial, sans-serif;
                   border: 1px solid #ced4da; border-radius: 6px; resize: vertical; }
        .hint { font-size: 12px; color: #868e96; margin: 4px 0 0; }
        .preview { font-size: 13px; color: #8a6100; background: #fff6dd; border: 2px solid #ffe69c; border-radius: 6px;
                   padding: 8px 12px; margin: 0 0 20px; }
    </style>
</head>
<body>
<div class="box">
<?php if ($link !== null && $preview): ?>
    <p class="preview">Preview - this is the page recipients see. Nobody is unsubscribed from here.</p>
<?php endif; ?>
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
        <fieldset>
            <legend>Why are you leaving? <span style="font-weight:normal;color:#868e96;">(optional)</span></legend>
            <?php foreach (Unsubscribe::REASONS as $key => $label): ?>
            <label><input type="radio" name="reason" value="<?php echo $h($key); ?>"><?php echo $h($label); ?></label>
            <?php endforeach; ?>
            <textarea name="comment" rows="2" maxlength="<?php echo Unsubscribe::COMMENT_MAX; ?>" placeholder="Anything else you would like to tell us?"></textarea>
            <p class="hint">Your answer helps us send better emails. It does not affect unsubscribing.</p>
        </fieldset>
        <button type="submit">Unsubscribe</button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
