<?php

declare(strict_types=1);

namespace Edm\Services\Ses;

use DateTimeImmutable;
use DateTimeZone;
use Edm\Core\Database;
use Edm\Models\SendLog;
use Edm\Models\SesEvent;
use Edm\Models\Suppression;
use Exception;

/**
 * Applies one SES event (the JSON inside an SNS Notification) to the data:
 *
 *   Delivery         send log -> delivered
 *   Bounce           send log -> bounced; Permanent = suppress (hard_bounce).
 *                    Transient (soft) bounces are recorded only - the spec's
 *                    "retry once after 3 days" is not built yet.
 *   Complaint        send log -> complained; suppress (spam_complaint)
 *   Open / Click     first open / click time
 *   Reject,
 *   RenderingFailure send log -> failed
 *
 * Accepts both configuration-set event publishing (`eventType`) and identity
 * notifications (`notificationType`). Every event is also kept raw in
 * edm_ses_events.
 */
final class EventHandler
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string, mixed> $event decoded SES event */
    public function handle(array $event): void
    {
        $type = (string) ($event['eventType'] ?? $event['notificationType'] ?? '');
        $mail = $event['mail'] ?? [];
        $messageId = isset($mail['messageId']) ? (string) $mail['messageId'] : null;
        if ($type === '') {
            return;
        }

        $detail = $event[lcfirst($type)] ?? [];
        $at = self::localTime($detail['timestamp'] ?? $mail['timestamp'] ?? null);

        SesEvent::create([
            'ses_message_id' => $messageId,
            'event_type'     => $type,
            'email'          => isset($mail['destination'][0]) ? strtolower((string) $mail['destination'][0]) : null,
            'payload'        => $event,
            'occurred_at'    => $at,
        ]);

        switch ($type) {
            case 'Delivery':
                $this->mark($messageId, 'delivered_at', $at, SendLog::DELIVERED, [SendLog::SENT]);
                break;
            case 'Bounce':
                $this->mark($messageId, 'bounced_at', $at, SendLog::BOUNCED, [SendLog::SENT, SendLog::DELIVERED]);
                if (($detail['bounceType'] ?? '') === 'Permanent') {
                    foreach ($detail['bouncedRecipients'] ?? [] as $r) {
                        Suppression::suppress(
                            (string) ($r['emailAddress'] ?? ''),
                            'hard_bounce',
                            'Amazon SES',
                            trim(($detail['bounceSubType'] ?? '') . ' ' . ($r['diagnosticCode'] ?? ''))
                        );
                    }
                }
                break;
            case 'Complaint':
                $this->mark($messageId, 'complained_at', $at, SendLog::COMPLAINED, [SendLog::SENT, SendLog::DELIVERED]);
                foreach ($detail['complainedRecipients'] ?? [] as $r) {
                    Suppression::suppress(
                        (string) ($r['emailAddress'] ?? ''),
                        'spam_complaint',
                        'Amazon SES',
                        $detail['complaintFeedbackType'] ?? null
                    );
                }
                break;
            case 'Open':
                $this->mark($messageId, 'opened_at', $at);
                break;
            case 'Click':
                $this->mark($messageId, 'opened_at', $at); // a click implies the email was opened
                $this->mark($messageId, 'clicked_at', $at);
                break;
            case 'Reject':
            case 'RenderingFailure':
                if ($messageId !== null) {
                    $this->db->execute(
                        'UPDATE `edm_send_log` SET `status` = ?, `error` = ?, `updated_at` = ? WHERE `ses_message_id` = ? AND `deleted_at` IS NULL',
                        [SendLog::FAILED, mb_substr('SES ' . $type . ': ' . (string) ($detail['reason'] ?? $detail['errorMessage'] ?? ''), 0, 255), date('Y-m-d H:i:s'), $messageId]
                    );
                }
                break;
        }
    }

    /**
     * Set a first-time timestamp column and, optionally, move the status on
     * (only from the listed earlier statuses, so a late Delivery never
     * overwrites a Complaint).
     *
     * @param list<int> $from
     */
    private function mark(?string $messageId, string $column, ?string $at, ?int $status = null, array $from = []): void
    {
        if ($messageId === null) {
            return;
        }
        $col = Database::ident($column);
        $at ??= date('Y-m-d H:i:s');
        $this->db->execute(
            "UPDATE `edm_send_log` SET {$col} = COALESCE({$col}, ?), `updated_at` = ? WHERE `ses_message_id` = ? AND `deleted_at` IS NULL",
            [$at, date('Y-m-d H:i:s'), $messageId]
        );
        if ($status !== null && $from !== []) {
            $marks = implode(', ', array_fill(0, count($from), '?'));
            $this->db->execute(
                "UPDATE `edm_send_log` SET `status` = ? WHERE `ses_message_id` = ? AND `deleted_at` IS NULL AND `status` IN ({$marks})",
                array_merge([$status, $messageId], $from)
            );
        }
    }

    /** SES ISO-8601 UTC timestamp -> local 'Y-m-d H:i:s'. */
    private static function localTime(mixed $iso): ?string
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($iso))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        } catch (Exception) {
            return null;
        }
    }
}
