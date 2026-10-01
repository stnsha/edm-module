<?php

declare(strict_types=1);

namespace Edm\Services\Ses;

use Edm\Core\Database;
use Edm\Core\ValidationException;
use Edm\Models\Campaign;
use Edm\Models\CampaignContent;
use Edm\Models\ContactList;
use Edm\Models\SendLog;
use Edm\Models\Sender;
use Edm\Services\SegmentQuery;

/**
 * The send queue, run every minute by cron/send.php (spec 5.2 step 7: "SES
 * queue triggered at scheduled datetime").
 *
 *   1. Scheduled (6) campaigns whose scheduled_at has passed and that pass
 *      the pre-send checks move to Sending (7).
 *   2. Sending campaigns are worked through in batches until the time
 *      budget runs out. With a segment, only list members matching its
 *      conditions (SegmentQuery) are recipients; the conditions are read
 *      at the start of each run. Every recipient gets exactly one edm_send_log row
 *      (sent, failed or skipped), so a run that stops part-way resumes where
 *      it left off. "Stop sending" (status leaves 7) is honoured per batch.
 *   3. A campaign with no member left to process becomes Completed (8).
 *
 * Suppression rules applied at queue time (spec, hard, no override):
 * suppressed address, 1 send per rolling 24 hours, 2 per rolling 7 days,
 * 8 per calendar month - counted over every campaign's actual sends.
 */
final class CampaignSender
{
    private const BATCH = 100;
    private const CAP_DAY = 1;
    private const CAP_WEEK = 2;
    private const CAP_MONTH = 8;

    /** @var callable(string): void */
    private $log;

    /**
     * @param callable(string): void $log progress / problem lines
     */
    public function __construct(
        private Database $db,
        private SesGateway $ses,
        callable $log,
    ) {
        $this->log = $log;
    }

    /** Work until $deadline (unix time, float). Returns the number of emails sent. */
    public function run(float $deadline): int
    {
        $missing = $this->ses->config->missingForSending();
        if ($missing !== []) {
            ($this->log)('Not sending: set ' . implode(' and ', $missing) . ' in edm/.env.');
            return 0;
        }

        $this->startDue();

        $account = $this->ses->account();
        if (!$account['sending_enabled']) {
            ($this->log)('Not sending: sending is disabled on the SES account.');
            return 0;
        }
        $rate = min($account['max_rate'], $this->ses->config->maxSendRate ?? $account['max_rate']);
        $gap = $rate > 0 ? 1 / $rate : 1.0;
        // Rolling 24-hour room: the SES quota, or SES_DAILY_LIMIT when set lower
        // (headroom under the quota, as in the ses-demo batch sender).
        $cap = min($account['max_24h'], $this->ses->config->dailyLimit ?? $account['max_24h']);
        $room = (int) max(0, $cap - $account['sent_24h']);

        $sent = 0;
        foreach ($this->sendingIds() as $id) {
            if (microtime(true) >= $deadline || $room <= 0) {
                break;
            }
            $sent += $this->work($id, $deadline, $gap, $room);
        }
        if ($room <= 0) {
            ($this->log)('Paused: the 24-hour sending limit is used up (SES quota / SES_DAILY_LIMIT).');
        }

        return $sent;
    }

    /** Scheduled + due + passing the checks -> Sending. */
    private function startDue(): void
    {
        $due = $this->db->select(
            'SELECT `id` FROM `edm_campaigns`
              WHERE `deleted_at` IS NULL AND `status` = ? AND `scheduled_at` IS NOT NULL AND `scheduled_at` <= ?
              ORDER BY `scheduled_at`, `id`',
            [Campaign::SCHEDULED, date('Y-m-d H:i:s')]
        );
        foreach ($due as $row) {
            $id = (int) $row['id'];
            $problem = $this->preflight(Campaign::findOrFail($id));
            if ($problem !== null) {
                ($this->log)("Campaign #{$id} not started: {$problem}");
                continue;
            }
            // Conditional on the status, so a campaign stopped meanwhile stays stopped.
            $this->db->execute(
                'UPDATE `edm_campaigns` SET `status` = ?, `updated_at` = ? WHERE `id` = ? AND `status` = ?',
                [Campaign::SENDING, date('Y-m-d H:i:s'), $id, Campaign::SCHEDULED]
            );
            ($this->log)("Campaign #{$id} started sending.");
        }
    }

    /** Why a campaign cannot be sent, or null when it can. */
    private function preflight(array $c): ?string
    {
        $sender = $c['sender_id'] !== null ? Sender::find((int) $c['sender_id']) : null;

        return match (true) {
            $sender === null => 'no sender.',
            $sender['status'] !== 2 => 'sender ' . $sender['email'] . ' is not verified in SES.',
            !Campaign::hasAudience($c)
                || (!$c['all_lists'] && ContactList::find((int) $c['list_id']) === null) => 'no recipient list.',
            ($segmentProblem = $this->segmentProblem($c)) !== null => $segmentProblem,
            trim((string) $c['subject']) === '' => 'no subject line.',
            trim((string) (CampaignContent::forCampaign((int) $c['id'])['html'] ?? '')) === '' => 'the design is empty.',
            default => null,
        };
    }

    /** Why the campaign's segment cannot be used, or null. */
    private function segmentProblem(array $c): ?string
    {
        try {
            SegmentQuery::forCampaign(
                $c['segment_id'] !== null ? (int) $c['segment_id'] : null,
                Campaign::audienceListId($c)
            );
        } catch (ValidationException $e) {
            return lcfirst(rtrim($e->getMessage(), '.')) . '.';
        }

        return null;
    }

    /** @return list<int> */
    private function sendingIds(): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->db->select(
                'SELECT `id` FROM `edm_campaigns` WHERE `deleted_at` IS NULL AND `status` = ? ORDER BY `scheduled_at`, `id`',
                [Campaign::SENDING]
            )
        );
    }

    /** Send one campaign's next batches. Returns emails sent. */
    private function work(int $id, float $deadline, float $gap, int &$room): int
    {
        $c = Campaign::findOrFail($id);
        $sender = Sender::find((int) $c['sender_id']);
        $problem = $this->preflight($c);
        if ($problem !== null || $sender === null) {
            ($this->log)("Campaign #{$id} paused: " . ($problem ?? 'no sender.'));
            return 0;
        }
        $html = (string) CampaignContent::forCampaign($id)['html'];
        // Checked by preflight() just above, so this does not throw.
        $listId = Campaign::audienceListId($c);
        $segment = SegmentQuery::forCampaign($c['segment_id'] !== null ? (int) $c['segment_id'] : null, $listId);
        $unsubscribe = new Unsubscribe($this->ses->config);
        $sent = 0;

        while (microtime(true) < $deadline && $room > 0) {
            if ((int) $this->db->scalar('SELECT `status` FROM `edm_campaigns` WHERE `id` = ?', [$id]) !== Campaign::SENDING) {
                ($this->log)("Campaign #{$id} was stopped.");
                return $sent;
            }
            $batch = $this->pending($id, $listId, $segment);
            if ($batch === []) {
                Campaign::update($id, ['status' => Campaign::COMPLETED]);
                ($this->log)("Campaign #{$id} completed.");
                return $sent;
            }

            foreach ($batch as $m) {
                if (microtime(true) >= $deadline || $room <= 0) {
                    return $sent;
                }
                $email = strtolower(trim((string) $m['email']));
                $skip = $this->skipReason($email);
                if ($skip !== null) {
                    $this->record($id, $m, SendLog::SKIPPED, null, $skip);
                    continue;
                }

                $vars = [
                    'email'           => $email,
                    'member_code'     => $m['member_code'],
                    'name'            => $m['name'],
                    'fields'          => json_decode((string) $m['fields'], true) ?: [],
                    'unsubscribe_url' => $unsubscribe->url($id, $email),
                ];
                $started = microtime(true);
                try {
                    $messageId = $this->ses->send(
                        $sender['email'],
                        $sender['from_name'],
                        $email,
                        MessageRenderer::subject((string) $c['subject'], $vars),
                        MessageRenderer::html($html, $vars),
                        $sender['reply_to'],
                        [
                            'List-Unsubscribe'      => '<' . $vars['unsubscribe_url'] . '>',
                            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                        ],
                        ['campaign_id' => (string) $id]
                    );
                    $this->record($id, $m, SendLog::SENT, $messageId, null);
                    $sent++;
                    $room--;
                } catch (SesException $e) {
                    if (self::isRetryable($e)) {
                        // Throttled / account-level pause: nothing is recorded,
                        // the recipient is retried on the next run.
                        ($this->log)("Campaign #{$id} paused: " . $e->getMessage());
                        return $sent;
                    }
                    $this->record($id, $m, SendLog::FAILED, null, mb_substr($e->getMessage(), 0, 255));
                }
                $wait = $gap - (microtime(true) - $started);
                if ($wait > 0) {
                    usleep((int) ($wait * 1_000_000));
                }
            }
        }

        return $sent;
    }

    /**
     * Next subscribed list members without a send-log row for this campaign,
     * limited to those matching the segment when there is one.
     *
     * One row per address (the oldest when a list - or, with $listId null
     * (all lists), several lists - hold it twice), with the contact's name and
     * custom field values for personalisation.
     *
     * @param array{match: string, rules: list<array<string, string>>}|null $segment normalized definition
     * @return list<array{email: string, member_code: ?string, name: ?string, fields: ?string}>
     */
    private function pending(int $campaignId, ?int $listId, ?array $segment): array
    {
        [$segmentSql, $segmentParams] = $segment !== null
            ? (new SegmentQuery($this->db))->where($segment, 'm')
            : ['1 = 1', []];

        return $this->db->select(
            'SELECT m.`member_code`, LOWER(TRIM(m.`email`)) AS email, m.`name`, m.`fields`
               FROM `edm_list_members` m
               JOIN (SELECT MIN(`id`) AS id FROM `edm_list_members`
                      WHERE (? IS NULL OR `list_id` = ?) AND `status` = 1 AND `deleted_at` IS NULL AND `email` <> \'\'
                      GROUP BY LOWER(TRIM(`email`))) oldest ON oldest.`id` = m.`id`
              WHERE ' . $segmentSql . '
                AND NOT EXISTS (SELECT 1 FROM `edm_send_log` s
                                 WHERE s.`campaign_id` = ? AND s.`email` = LOWER(TRIM(m.`email`)) AND s.`deleted_at` IS NULL)
              ORDER BY m.`id`
              LIMIT ' . self::BATCH,
            [$listId, $listId, ...$segmentParams, $campaignId]
        );
    }

    /** Why this address must not get the email now, or null. */
    private function skipReason(string $email): ?string
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'invalid address';
        }
        $reason = $this->db->scalar(
            'SELECT `reason` FROM `edm_suppressions` WHERE `deleted_at` IS NULL AND LOWER(`email`) = ? LIMIT 1',
            [$email]
        );
        if ($reason !== null) {
            return 'suppressed: ' . $reason;
        }

        $now = time();
        $counts = $this->db->first(
            'SELECT SUM(`sent_at` >= ?) AS day, SUM(`sent_at` >= ?) AS week, SUM(`sent_at` >= ?) AS month
               FROM `edm_send_log`
              WHERE `email` = ? AND `status` IN (?, ?, ?, ?) AND `deleted_at` IS NULL AND `sent_at` >= ?',
            [
                date('Y-m-d H:i:s', $now - 86400),
                date('Y-m-d H:i:s', $now - 7 * 86400),
                date('Y-m-01 00:00:00', $now),
                $email,
                SendLog::SENT, SendLog::DELIVERED, SendLog::BOUNCED, SendLog::COMPLAINED,
                min(date('Y-m-d H:i:s', $now - 7 * 86400), date('Y-m-01 00:00:00', $now)),
            ]
        ) ?? [];

        return match (true) {
            (int) ($counts['day'] ?? 0) >= self::CAP_DAY => 'cap: ' . self::CAP_DAY . ' per day',
            (int) ($counts['week'] ?? 0) >= self::CAP_WEEK => 'cap: ' . self::CAP_WEEK . ' per week',
            (int) ($counts['month'] ?? 0) >= self::CAP_MONTH => 'cap: ' . self::CAP_MONTH . ' per month',
            default => null,
        };
    }

    /** @param array{email: string, member_code: ?string} $member */
    private function record(int $campaignId, array $member, int $status, ?string $messageId, ?string $error): void
    {
        SendLog::create([
            'campaign_id'    => $campaignId,
            'member_code'    => $member['member_code'],
            'email'          => strtolower(trim((string) $member['email'])),
            'sent_at'        => date('Y-m-d H:i:s'),
            'status'         => $status,
            'ses_message_id' => $messageId,
            'error'          => $error,
        ]);
    }

    /**
     * Failures that are not about this recipient: throttling, quota, account
     * paused, AWS-side errors, or no answer from AWS at all (network).
     */
    private static function isRetryable(SesException $e): bool
    {
        return $e->awsCode === null || in_array($e->awsCode, [
            'ThrottlingException', 'TooManyRequestsException', 'LimitExceededException',
            'SendingPausedException', 'AccountSuspendedException', 'InternalFailure', 'ServiceUnavailable',
        ], true);
    }
}
