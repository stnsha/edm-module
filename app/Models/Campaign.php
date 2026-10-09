<?php

declare(strict_types=1);

namespace Edm\Models;

use DateTimeImmutable;
use Edm\Core\Auth;
use Edm\Core\Database;
use Edm\Core\HttpException;
use Edm\Core\Model;
use Edm\Core\ValidationException;
use Edm\Services\CampaignRecipients;
use Edm\Services\ScheduleReadiness;
use Edm\Services\UtmTagger;

/**
 * A newsletter. Table: edm_campaigns.
 *
 * status is an integer, never a word - see STATUSES. Draft -> Pending
 * approval (submit(), Email creator step 5) -> Scheduled (approve(), step 6,
 * BPT team / superadmin) -> Sending -> Completed; reject() and withdraw()
 * return a pending newsletter to Draft; Archived on the side. Values 3-5
 * belonged to the removed approval chain and are unused.
 * Submission: submitted_by / _name / _department / _at (snapshot of the
 * submitter); decision: reviewed_by / _name / _at and review_comment (the
 * rejection reason).
 * Recipients: the `audience` JSON (included / excluded segments and
 * contacts on the masterlist), resolved by Services\CampaignRecipients.
 * Tracking: track_opens / track_clicks (SES) and utm_enabled + utm (links).
 */
final class Campaign extends Model
{
    public const STATUSES = [
        1 => 'draft', 2 => 'pending_approval', 6 => 'scheduled', 7 => 'sending', 8 => 'completed', 9 => 'archived',
    ];

    public const DRAFT = 1;
    public const PENDING_APPROVAL = 2;
    public const SCHEDULED = 6;
    public const SENDING = 7;
    public const COMPLETED = 8;
    public const ARCHIVED = 9;

    protected const TABLE = 'edm_campaigns';

    protected const FILLABLE = [
        'name', 'subject', 'subject_b', 'preheader', 'tag_ids', 'sender_id', 'reply_to_id', 'audience', 'send_now',
        'track_opens', 'track_clicks', 'utm_enabled', 'utm',
        'status', 'archived_from', 'scheduled_at', 'requested_by', 'requested_by_name',
        'submitted_by', 'submitted_by_name', 'submitted_department', 'submitted_at',
        'reviewed_by', 'reviewed_by_name', 'reviewed_at', 'review_comment',
    ];

    protected const CASTS = [
        'status' => 'int', 'archived_from' => 'int', 'scheduled_at' => 'datetime', 'audience' => 'json', 'utm' => 'json',
        'tag_ids' => 'json',
        'submitted_by' => 'int', 'submitted_at' => 'datetime', 'reviewed_by' => 'int', 'reviewed_at' => 'datetime',
        'send_now' => 'bool', 'track_opens' => 'bool', 'track_clicks' => 'bool', 'utm_enabled' => 'bool',
    ];

    /**
     * The wizard's structured settings from a request body, in storage form
     * - only keys that are present: audience (CampaignRecipients::normalize),
     * utm (known keys, trimmed, 100 characters each), and the send_now /
     * track_opens / track_clicks / utm_enabled flags.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function settingsInput(array $input): array
    {
        $out = [];
        if (array_key_exists('audience', $input)) {
            $out['audience'] = CampaignRecipients::normalize($input['audience']);
        }
        if (array_key_exists('utm', $input)) {
            $utm = [];
            foreach (self::UTM_KEYS as $k) {
                $utm[$k] = mb_substr(trim((string) ($input['utm'][$k] ?? '')), 0, 100);
            }
            $out['utm'] = $utm;
        }
        foreach (['send_now', 'track_opens', 'track_clicks', 'utm_enabled'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $out[$flag] = filter_var($input[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }

    /** UTM parameters a newsletter can set (Tracking step), in link order. */
    public const UTM_KEYS = ['source', 'medium', 'campaign', 'content'];

    /**
     * The UTM values used when sending: the saved ones, with source "edm",
     * medium "email" and campaign = slug of the name when left empty.
     *
     * @return array{source: string, medium: string, campaign: string, content: string}
     */
    public static function utm(array $campaign): array
    {
        $saved = is_array($campaign['utm'] ?? null) ? $campaign['utm'] : [];
        $default = ['source' => 'edm', 'medium' => 'email', 'campaign' => UtmTagger::slug((string) ($campaign['name'] ?? '')), 'content' => ''];
        $out = [];
        foreach (self::UTM_KEYS as $k) {
            $v = trim((string) ($saved[$k] ?? ''));
            $out[$k] = $v !== '' ? $v : $default[$k];
        }

        return $out;
    }

    /**
     * The Reply-to address: the email of the newsletter's reply_to_id sender,
     * or null (none chosen, or that sender was deleted) - replies then go
     * to the Sender address.
     */
    public static function replyTo(array $campaign): ?string
    {
        if (($campaign['reply_to_id'] ?? null) === null) {
            return null;
        }

        return Sender::find((int) $campaign['reply_to_id'])['email'] ?? null;
    }

    protected const ORDER = '`created_at` DESC, `id` DESC';

    /** Draft is the only state still being worked on. */
    public static function isEditable(array $campaign): bool
    {
        return $campaign['status'] === self::DRAFT;
    }

    /**
     * Newsletters list: every row plus `delivered` (edm_send_log rows) and
     * `audience_label` (CampaignRecipients::describe, short). Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function listing(?int $status = null, ?bool $archived = null): array
    {
        $where = ['c.`deleted_at` IS NULL'];
        $params = [];
        if ($status !== null) {
            $where[] = 'c.`status` = ?';
            $params[] = $status;
        }
        // $archived: true = only archived, false = all but archived, null = all.
        if ($archived !== null) {
            $where[] = 'c.`status` ' . ($archived ? '=' : '<>') . ' ?';
            $params[] = self::ARCHIVED;
        }
        $sql = 'SELECT c.*,
                    (SELECT COUNT(*) FROM `edm_send_log` s
                        WHERE s.`campaign_id` = c.`id` AND s.`deleted_at` IS NULL) AS delivered
                FROM `edm_campaigns` c
                WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.`created_at` DESC, c.`id` DESC';

        return array_map(static function (array $row): array {
            $row = self::present($row);
            $row['delivered'] = (int) $row['delivered'];
            $row['audience_label'] = CampaignRecipients::describe(CampaignRecipients::of($row), true);
            $row['tags'] = MasterTag::named(is_array($row['tag_ids'] ?? null) ? $row['tag_ids'] : []);

            return $row;
        }, self::db()->select($sql, $params));
    }

    /**
     * One newsletter with its `content`, `audience` in normal form,
     * `audience_names` (for the Email creator chips), `audience_label` and
     * the effective `utm_values`.
     *
     * @return array<string, mixed>
     */
    public static function withDetails(int $id): array
    {
        $campaign = self::findOrFail($id);
        $campaign['content'] = CampaignContent::forCampaign($id);
        $campaign['audience'] = CampaignRecipients::of($campaign);
        $campaign['audience_names'] = CampaignRecipients::names($campaign['audience']);
        $campaign['audience_label'] = CampaignRecipients::describe($campaign['audience']);
        $campaign['utm_values'] = self::utm($campaign);
        $campaign['tags'] = MasterTag::named(is_array($campaign['tag_ids'] ?? null) ? $campaign['tag_ids'] : []);

        return $campaign;
    }

    /**
     * New draft plus its empty content row.
     *
     * @param array<string, mixed> $data validated payload
     * @return array<string, mixed>
     */
    public static function createDraft(array $data, ?int $templateId = null): array
    {
        // A chosen template is copied into the body; later edits to either
        // side do not affect the other.
        $template = $templateId !== null ? Template::findOrFail($templateId) : null;

        return self::db()->transaction(static function () use ($data, $template): array {
            $campaign = self::create(['status' => self::DRAFT] + $data);
            CampaignContent::create([
                'campaign_id' => $campaign['id'],
                'html'        => (string) ($template['html'] ?? ''),
                'editor_json' => $template['editor_json'] ?? null,
                'version'     => 1,
            ]);

            return self::withDetails((int) $campaign['id']);
        });
    }

    /**
     * "Reuse": settings + body copied into a new draft. The schedule is
     * never copied.
     *
     * @param array<string, mixed> $stamp requested_by / requested_by_name
     * @return array<string, mixed>
     */
    public static function duplicate(int $id, array $stamp): array
    {
        $source = self::withDetails($id);

        return self::db()->transaction(static function () use ($source, $stamp): array {
            $copy = self::create([
                'name'         => mb_substr($source['name'] . ' (copy)', 0, 255),
                'subject'      => $source['subject'],
                'subject_b'    => $source['subject_b'],
                'preheader'    => $source['preheader'],
                'sender_id'    => $source['sender_id'],
                'reply_to_id'  => $source['reply_to_id'],
                'audience'     => $source['audience'],
                'tag_ids'      => $source['tag_ids'],
                'send_now'     => $source['send_now'],
                'track_opens'  => $source['track_opens'],
                'track_clicks' => $source['track_clicks'],
                'utm_enabled'  => $source['utm_enabled'],
                'utm'          => $source['utm'],
                'status'       => self::DRAFT,
                'scheduled_at' => null,
            ] + $stamp);
            CampaignContent::create([
                'campaign_id' => $copy['id'],
                'html'        => $source['content']['html'] ?? '',
                'editor_json' => $source['content']['editor_json'] ?? null,
                'version'     => 1,
            ]);

            return self::withDetails((int) $copy['id']);
        });
    }

    /**
     * Soft delete with its dependants: content is soft-deleted too;
     * calendar slots keep their date but lose the link.
     */
    public static function delete(int $id): void
    {
        self::findOrFail($id);
        self::db()->transaction(static function () use ($id): void {
            CampaignContent::deleteWhere('campaign_id', $id);
            CalendarSlot::unlinkWhere('campaign_id', $id);
            parent::delete($id);
        });
    }

    /**
     * New newsletter from the Newsletters list: an empty draft opened
     * straight in the Email creator. Sender and subject are filled in
     * there; the default sender is preset. They are checked on submit().
     *
     * @param array<string, mixed> $stamp requested_by / requested_by_name
     * @return array<string, mixed>
     */
    public static function createBlank(array $stamp): array
    {
        $sender = self::db()->scalar(
            'SELECT `id` FROM `edm_senders` WHERE `deleted_at` IS NULL AND `is_default` = 1 ORDER BY `id` LIMIT 1'
        );

        return self::createDraft([
            'name'      => 'Untitled newsletter ' . date('d-m-Y H:i'),
            'sender_id' => $sender !== null ? (int) $sender : null,
        ] + $stamp);
    }

    /**
     * When the newsletter goes out, from its saved settings: now for Send
     * now, else scheduled_at (null when not chosen).
     */
    public static function sendAt(array $campaign): ?DateTimeImmutable
    {
        return !empty($campaign['send_now'])
            ? new DateTimeImmutable()
            : self::parseDate((string) ($campaign['scheduled_at'] ?? ''));
    }

    /**
     * "Submit for review" (Email creator step 5): Draft -> Pending approval.
     * The ScheduleReadiness checks must pass already, so an approver is not
     * handed a newsletter that cannot go out. The submitter and their
     * department (odb staff.department) are stamped; an earlier decision is
     * cleared. The newsletter is locked while pending.
     */
    public static function submit(int $id, Auth $auth): array
    {
        $campaign = self::findOrFail($id);
        if (!self::isEditable($campaign)) {
            throw new HttpException('Only a draft newsletter can be submitted for review.', 422);
        }
        $problems = (new ScheduleReadiness())->problems($campaign, self::sendAt($campaign));
        if ($problems !== []) {
            throw new HttpException('Cannot submit yet: ' . implode(' ', $problems), 422);
        }

        return self::update($id, [
            'status'               => self::PENDING_APPROVAL,
            'submitted_by'         => $auth->staffId,
            'submitted_by_name'    => $auth->staffName,
            'submitted_department' => $auth->department(self::db()),
            'submitted_at'         => date('Y-m-d H:i:s'),
            'reviewed_by'          => null,
            'reviewed_by_name'     => null,
            'reviewed_at'          => null,
            'review_comment'       => null,
        ]);
    }

    /**
     * Who may approve or reject (step 6): the BPT team (effective role 3,
     * dev override included) or a superadmin; never the submitter, unless
     * superadmin.
     */
    public static function canApprove(array $campaign, Auth $auth): bool
    {
        if ($campaign['status'] !== self::PENDING_APPROVAL) {
            return false;
        }
        if ($auth->isSuperadmin) {
            return true;
        }

        return $auth->permission === 3 && $campaign['submitted_by'] !== $auth->staffId;
    }

    /** The submitter (or a superadmin) may take a pending newsletter back to Draft. */
    public static function canWithdraw(array $campaign, Auth $auth): bool
    {
        return $campaign['status'] === self::PENDING_APPROVAL
            && ($auth->isSuperadmin || ($auth->staffId !== null && $campaign['submitted_by'] === $auth->staffId));
    }

    /**
     * Approve (step 6): Pending approval -> Scheduled at the submitter's
     * chosen time (Send now = now). The readiness checks run again; a send
     * time that has passed meanwhile blocks approval (reject it with a note
     * instead). The send queue waits CampaignSender::RECALL_SECONDS after the
     * send time, so it can still be recalled.
     */
    public static function approve(int $id, Auth $auth): array
    {
        $campaign = self::findOrFail($id);
        if (!self::canApprove($campaign, $auth)) {
            throw new HttpException(self::decideRefusal($campaign), 403);
        }
        $sendAt = self::sendAt($campaign);
        $problems = (new ScheduleReadiness())->problems($campaign, $sendAt);
        if ($problems !== []) {
            throw new HttpException('Cannot approve: ' . implode(' ', $problems) . ' Reject it so the submitter can fix it.', 422);
        }

        return self::update($id, [
            'status'           => self::SCHEDULED,
            'scheduled_at'     => $sendAt?->format('Y-m-d H:i:s'),
            'reviewed_by'      => $auth->staffId,
            'reviewed_by_name' => $auth->staffName,
            'reviewed_at'      => date('Y-m-d H:i:s'),
            'review_comment'   => null,
        ]);
    }

    /** Reject (step 6): Pending approval -> Draft, with the reason for the submitter. */
    public static function reject(int $id, Auth $auth, string $comment): array
    {
        $campaign = self::findOrFail($id);
        if (!self::canApprove($campaign, $auth)) {
            throw new HttpException(self::decideRefusal($campaign), 403);
        }
        $comment = trim($comment);
        if ($comment === '') {
            throw ValidationException::single('comment', 'State the reason for rejection.');
        }

        return self::update($id, [
            'status'           => self::DRAFT,
            'reviewed_by'      => $auth->staffId,
            'reviewed_by_name' => $auth->staffName,
            'reviewed_at'      => date('Y-m-d H:i:s'),
            'review_comment'   => mb_substr($comment, 0, 2000),
        ]);
    }

    /** Withdraw: the submitter takes a pending newsletter back to Draft to change it. */
    public static function withdraw(int $id, Auth $auth): array
    {
        $campaign = self::findOrFail($id);
        if (!self::canWithdraw($campaign, $auth)) {
            throw new HttpException($campaign['status'] === self::PENDING_APPROVAL
                ? 'Only the person who submitted it can withdraw this newsletter.'
                : 'Only a newsletter pending approval can be withdrawn.', 403);
        }

        return self::update($id, ['status' => self::DRAFT]);
    }

    private static function decideRefusal(array $campaign): string
    {
        return $campaign['status'] !== self::PENDING_APPROVAL
            ? 'This newsletter is not pending approval.'
            : 'Only the BPT team or a superadmin can approve or reject, and not their own submission.';
    }

    /**
     * What a newsletter still lacks before it can be scheduled,
     * e.g. ['a sender', 'a design']; empty when complete.
     *
     * @return list<string>
     */
    public static function missing(array $campaign): array
    {
        return array_keys(array_filter([
            'a sender'       => $campaign['sender_id'] === null,
            'a subject line' => trim((string) $campaign['subject']) === '',
            'a design'       => trim((string) (CampaignContent::forCampaign((int) $campaign['id'])['html'] ?? '')) === '',
        ]));
    }

    /**
     * Locked: from submission on - pending approval, scheduled, sending,
     * completed or archived - the settings and design can no longer change.
     */
    public static function isLocked(array $campaign): bool
    {
        return in_array($campaign['status'], [self::PENDING_APPROVAL, self::SCHEDULED, self::SENDING, self::COMPLETED, self::ARCHIVED], true);
    }

    /** @throws HttpException 422 when the newsletter is locked (see isLocked()). */
    public static function assertUnlocked(array $campaign): void
    {
        if ($campaign['status'] === self::PENDING_APPROVAL) {
            throw new HttpException('This newsletter is pending approval, so it is locked. Withdraw it (or have it rejected) to make changes.', 422);
        }
        if (self::isLocked($campaign)) {
            throw new HttpException('This newsletter is scheduled or sent, so it is locked. Recall it first (scheduled only) to make changes.', 422);
        }
    }

    /**
     * Statuses a newsletter can be archived from: not scheduled or
     * sending. Archived newsletters are hidden from the Newsletters list (they
     * show under its Archived tab) and locked until unarchived.
     */
    public const ARCHIVABLE = [self::DRAFT, self::COMPLETED];

    /** -> archived (spec 5.1 status 9), remembering the status it had. */
    public static function archive(int $id): array
    {
        $campaign = self::findOrFail($id);
        if (!in_array($campaign['status'], self::ARCHIVABLE, true)) {
            throw new HttpException('Only a draft or a completed newsletter can be archived.', 422);
        }

        return self::update($id, ['status' => self::ARCHIVED, 'archived_from' => $campaign['status']]);
    }

    /** Archived -> the status it had before (completed when unknown). */
    public static function unarchive(int $id): array
    {
        $campaign = self::findOrFail($id);
        if ($campaign['status'] !== self::ARCHIVED) {
            throw new HttpException('This newsletter is not archived.', 422);
        }
        $back = in_array($campaign['archived_from'], self::ARCHIVABLE, true) ? $campaign['archived_from'] : self::COMPLETED;

        return self::update($id, ['status' => $back, 'archived_from' => null]);
    }

    /**
     * "Recall" / "Don't send it": a scheduled newsletter that has not started
     * sending goes back to draft (schedule it again when ready). The
     * send queue waits CampaignSender::RECALL_SECONDS after the send time,
     * so there is always at least that long to recall it.
     */
    public static function recall(int $id): array
    {
        $campaign = self::findOrFail($id);
        if ($campaign['status'] !== self::SCHEDULED) {
            throw new HttpException($campaign['status'] === self::SENDING
                ? 'Too late to recall - this newsletter has started sending. Use Stop sending to halt the rest.'
                : 'Only a scheduled newsletter can be recalled.', 422);
        }

        return self::update($id, ['status' => self::DRAFT]);
    }

    /**
     * "Stop sending": scheduled -> draft (unscheduled); sending -> completed
     * with whatever has gone out.
     */
    public static function stop(int $id): array
    {
        $campaign = self::findOrFail($id);
        $next = match ($campaign['status']) {
            self::SCHEDULED => self::DRAFT,
            self::SENDING => self::COMPLETED,
            default => null,
        };
        if ($next === null) {
            throw new HttpException('Only a scheduled or sending newsletter can be stopped.', 422);
        }

        return self::update($id, ['status' => $next]);
    }

    /** Count per status label, every label present (0 when none). */
    public static function countsByStatus(): array
    {
        $rows = self::db()->select(
            'SELECT `status`, COUNT(*) AS total FROM ' . Database::ident(self::TABLE)
            . ' WHERE `deleted_at` IS NULL GROUP BY `status`'
        );
        $byInt = [];
        foreach ($rows as $r) {
            $byInt[(int) $r['status']] = (int) $r['total'];
        }
        $out = [];
        foreach (self::STATUSES as $int => $label) {
            $out[$label] = $byInt[$int] ?? 0;
        }

        return $out;
    }
}
