<?php

declare(strict_types=1);

namespace Edm\Services;

use DateTimeImmutable;
use Edm\Models\Campaign;
use Edm\Models\Sender;
use Edm\Services\Ses\CampaignSender;

/**
 * Whether a newsletter can be scheduled (Campaign::schedule()): complete,
 * verified sender, usable recipients and a send date in the future. Same
 * pre-send rules the send queue checks later
 * (Ses\CampaignSender::preflight()), surfaced before the newsletter locks.
 */
final class ScheduleReadiness
{
    /**
     * Each check with its outcome. The send date is checked only with
     * $checkDate.
     *
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    public function checks(array $campaign, ?DateTimeImmutable $sendAt = null, bool $checkDate = false): array
    {
        $out = [];
        $missing = Campaign::missing($campaign);
        $out[] = ['label' => 'Newsletter complete', 'ok' => $missing === [],
            'detail' => $missing === [] ? 'Sender, subject and design are set.' : 'Add ' . implode(', ', $missing) . '.'];

        $sender = $campaign['sender_id'] !== null ? Sender::find((int) $campaign['sender_id']) : null;
        $verified = $sender !== null && $sender['status'] === 2;
        $out[] = ['label' => 'Sender verified', 'ok' => $verified,
            'detail' => $sender === null ? 'No sender chosen.' : ($verified ? $sender['email'] . ' is verified in SES.' : $sender['email'] . ' is not verified in SES.')];

        $audience = CampaignRecipients::of($campaign);
        $problem = CampaignRecipients::problem($audience);
        $out[] = ['label' => 'Recipients usable', 'ok' => $problem === null,
            'detail' => $problem ?? CampaignRecipients::describe($audience) . '.'];

        if ($checkDate && !empty($campaign['send_now'])) {
            $out[] = ['label' => 'Send date', 'ok' => true,
                'detail' => 'Send now - starts ' . CampaignSender::RECALL_SECONDS . ' seconds after scheduling (time to recall it).'];
        } elseif ($checkDate) {
            $future = $sendAt !== null && $sendAt > new DateTimeImmutable();
            $out[] = ['label' => 'Send date', 'ok' => $future,
                'detail' => $sendAt === null ? 'Choose the send date and time.' : ($future ? 'Sends ' . $sendAt->format('d-m-Y H:i') . '.' : 'The send date must be in the future.')];
        }

        return $out;
    }

    /**
     * What blocks scheduling at $sendAt, as sentences; empty when it can go.
     *
     * @return list<string>
     */
    public function problems(array $campaign, ?DateTimeImmutable $sendAt): array
    {
        if (Campaign::isLocked($campaign)) {
            return ['The newsletter is already scheduled or sent.'];
        }
        $out = [];
        foreach ($this->checks($campaign, $sendAt, true) as $c) {
            if (!$c['ok']) {
                $out[] = $c['detail'];
            }
        }

        return $out;
    }
}
