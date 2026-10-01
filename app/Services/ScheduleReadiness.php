<?php

declare(strict_types=1);

namespace Edm\Services;

use DateTimeImmutable;
use Edm\Core\Model;
use Edm\Core\ValidationException;
use Edm\Models\Campaign;
use Edm\Models\CampaignContent;
use Edm\Models\CampaignQa;
use Edm\Models\Sender;

/**
 * Whether a campaign can get final approval and be scheduled (spec 5.2
 * step 6): complete, verified sender, usable segment, automated QA (step 5)
 * passed - with or without warnings - on the current design, and a send
 * date in the future. Same pre-send rules the send queue checks later
 * (Ses\CampaignSender::preflight()), surfaced before the campaign locks.
 */
final class ScheduleReadiness
{
    /**
     * Each check with its outcome, for the Final Approval card.
     * $sendAt null skips the send date check (the date is chosen on the card).
     *
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    public function checks(array $campaign, ?DateTimeImmutable $sendAt = null, bool $checkDate = false): array
    {
        $out = [];
        $missing = Campaign::missing($campaign);
        $out[] = ['label' => 'Campaign complete', 'ok' => $missing === [],
            'detail' => $missing === [] ? 'Sender, recipients, subject and design are set.' : 'Add ' . implode(', ', $missing) . '.'];

        $sender = $campaign['sender_id'] !== null ? Sender::find((int) $campaign['sender_id']) : null;
        $verified = $sender !== null && $sender['status'] === 2;
        $out[] = ['label' => 'Sender verified', 'ok' => $verified,
            'detail' => $sender === null ? 'No sender chosen.' : ($verified ? $sender['email'] . ' is verified in SES.' : $sender['email'] . ' is not verified in SES.')];

        try {
            SegmentQuery::forCampaign($campaign['segment_id'] !== null ? (int) $campaign['segment_id'] : null, Campaign::audienceListId($campaign));
            $out[] = ['label' => 'Segment usable', 'ok' => true, 'detail' => $campaign['segment_id'] !== null ? 'The segment fits the recipient list.' : 'No segment - the whole list.'];
        } catch (ValidationException $e) {
            $out[] = ['label' => 'Segment usable', 'ok' => false, 'detail' => $e->getMessage()];
        }

        $out[] = $this->qa($campaign);

        if ($checkDate) {
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
            return ['The campaign is already scheduled or sent.'];
        }
        $out = [];
        foreach ($this->checks($campaign, $sendAt, true) as $c) {
            if (!$c['ok']) {
                $out[] = $c['detail'];
            }
        }

        return $out;
    }

    /** @return array{label: string, ok: bool, detail: string} */
    private function qa(array $campaign): array
    {
        $label = 'Automated QA passed';
        $run = CampaignQa::latest((int) $campaign['id']);
        if ($run === null) {
            return ['label' => $label, 'ok' => false, 'detail' => 'Automated QA has not run yet.'];
        }
        if (in_array($run['status'], [CampaignQa::QUEUED, CampaignQa::RUNNING], true)) {
            return ['label' => $label, 'ok' => false, 'detail' => 'Automated QA is still running.'];
        }
        if ($run['status'] === CampaignQa::FAILED) {
            return ['label' => $label, 'ok' => false, 'detail' => 'Automated QA failed - fix the failed checks and run it again.'];
        }
        // A pass only counts for the design it checked.
        $content = CampaignContent::forCampaign((int) $campaign['id']);
        $savedAt = Model::parseDate((string) ($content['updated_at'] ?? ''));
        $checkedAt = Model::parseDate((string) ($run['started_at'] ?? ''));
        if ($savedAt !== null && $checkedAt !== null && $savedAt > $checkedAt) {
            return ['label' => $label, 'ok' => false, 'detail' => 'The design changed after the last QA run - run it again.'];
        }

        return ['label' => $label, 'ok' => true,
            'detail' => $run['status'] === CampaignQa::WARNINGS ? 'Passed with warnings.' : 'All checks passed.'];
    }
}
