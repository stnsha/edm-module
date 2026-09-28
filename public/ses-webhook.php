<?php

declare(strict_types=1);

/**
 * Amazon SNS -> SES event receiver. PUBLIC endpoint (no odb login): AWS posts
 * here. Subscribe it (HTTPS) to the SNS topic that the SES configuration set
 * publishes to, and put that topic's ARN in edm/.env as SES_SNS_TOPIC_ARN.
 *
 * Trust comes from the message itself: the SNS signature is verified against
 * the AWS signing certificate, and only the configured topic is accepted.
 * The subscription is confirmed automatically on the first request.
 */

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Edm\Core\Database;
use Edm\Services\Ses\EventHandler;
use Edm\Services\Ses\SesConfig;

require __DIR__ . '/../app/bootstrap.php';

function edm_webhook_reply(int $status, string $text): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    edm_webhook_reply(405, 'POST only');
}

$config = SesConfig::fromEnv();
if ($config->snsTopicArn === null) {
    edm_webhook_reply(503, 'SES_SNS_TOPIC_ARN is not configured');
}

try {
    $message = Message::fromRawPostData();
    (new MessageValidator())->validate($message);
} catch (InvalidSnsMessageException | InvalidArgumentException | RuntimeException $e) {
    edm_webhook_reply(403, 'Invalid SNS message');
}

if (($message['TopicArn'] ?? '') !== $config->snsTopicArn) {
    edm_webhook_reply(403, 'Unexpected topic');
}

switch ($message['Type']) {
    case 'SubscriptionConfirmation':
        // Signature already verified; the URL must still be an SNS endpoint.
        $url = (string) $message['SubscribeURL'];
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host)) {
            edm_webhook_reply(403, 'Unexpected SubscribeURL');
        }
        $ok = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10]]));
        edm_webhook_reply($ok === false ? 502 : 200, $ok === false ? 'Could not confirm' : 'Subscribed');

    case 'Notification':
        $event = json_decode((string) $message['Message'], true);
        if (is_array($event)) {
            (new EventHandler(Database::get()))->handle($event);
        }
        edm_webhook_reply(200, 'OK');

    default: // UnsubscribeConfirmation and anything new
        edm_webhook_reply(200, 'Ignored');
}
