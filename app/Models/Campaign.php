<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Database;
use Edm\Core\HttpException;
use Edm\Core\Model;
use Edm\Services\CampaignRecipients;
use Edm\Services\Qa\UtmTagger;

/**
 * A newsletter. Table: edm_campaigns.
 *
 * status is an integer, never a word (spec 5.1, 9-state flow) - see STATUSES.
 * Recipients: the `audience` JSON (included / excluded segments and
 * contacts on the masterlist), resolved by Services\CampaignRecipients.
 * Tracking: track_opens / track_clicks (SES) and utm_enabled + utm (links).
 */
final class Campaign extends Model
{
    public const STATUSES = [
        1 => 'draft', 2 => 'pending_submission', 3 => 'under_bpt_review', 4 => 'content_revision',
        5 => 'audience_validation', 6 => 'scheduled', 7 => 'sending', 8 => 'completed', 9 => 'archived',
    ];

    public const DRAFT = 1;
    public const PENDING_SUBMISSION = 2;
    public const UNDER_BPT_REVIEW = 3;
    public const CONTENT_REVISION = 4;
    public const AUDIENCE_VALIDATION = 5;
    public const SCHEDULED = 6;
    public const SENDING = 7;
    public const COMPLETED = 8;
    public const ARCHIVED = 9;

    /** Statuses a newsletter can be raised for review from (approval/edit.php). */
    public const REVIEWABLE = [self::DRAFT, self::PENDING_SUBMISSION, self::CONTENT_REVISION];

    protected const TABLE = 'edm_campaigns';

    protected const FILLABLE = [
        'name', 'subject', 'subject_b', 'preheader', 'tag_ids', 'sender_id', 'reply_to_id', 'audience', 'send_now',
        'track_opens', 'track_clicks', 'utm_enabled', 'utm',
        'status', 'archived_from', 'scheduled_at', 'requested_by', 'requested_by_name',
    ];

    protected const CASTS = [
        'status' => 'int', 'archived_from' => 'int', 'scheduled_at' => 'datetime', 'audience' => 'json', 'utm' => 'json',
        'tag_ids' => 'json',
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

    /** Draft and content revision are the only states still being worked on. */
    public static function isEditable(array $campaign): bool
    {
        return in_array($campaign['status'], [self::DRAFT, self::CONTENT_REVISION], true);
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
     * Soft delete with its dependants: content, approvals and revisions are
     * soft-deleted too; calendar slots keep their date but lose the link.
     */
    public static function delete(int $id): void
    {
        self::findOrFail($id);
        self::db()->transaction(static function () use ($id): void {
            CampaignContent::deleteWhere('campaign_id', $id);
            Approval::deleteWhere('campaign_id', $id);
            Revision::deleteWhere('campaign_id', $id);
            CalendarSlot::unlinkWhere('campaign_id', $id);
            parent::delete($id);
        });
    }

    /** Draft / content revision -> pending submission. */
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

    public static function submit(int $id): array
    {
        $campaign = self::findOrFail($id);
        if (!self::isEditable($campaign)) {
            throw new HttpException('Only a draft or a newsletter in revision can be submitted for review.', 422);
        }
        // Drafts may be saved half-filled; a submitted one must be complete.
        $missing = self::missing($campaign);
        if ($missing !== []) {
            throw new HttpException('Before submitting, add ' . implode(', ', $missing) . '.', 422);
        }

        return self::update($id, ['status' => self::PENDING_SUBMISSION]);
    }

    /**
     * What a newsletter still lacks before it can be submitted or scheduled,
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
     * Locked (spec 5.2 step 6): from final approval on - scheduled, sending,
     * completed or archived - the settings and design can no longer change.
     */
    public static function isLocked(array $campaign): bool
    {
        return in_array($campaign['status'], [self::SCHEDULED, self::SENDING, self::COMPLETED, self::ARCHIVED], true);
    }

    /** @throws HttpException 422 when the newsletter is locked (see isLocked()). */
    public static function assertUnlocked(array $campaign): void
    {
        if (self::isLocked($campaign)) {
            throw new HttpException('This newsletter is scheduled or sent, so it is locked. Stop it first (scheduled only) to make changes.', 422);
        }
    }

    /**
     * Statuses a newsletter can be archived from: not in review, scheduled or
     * sending. Archived newsletters are hidden from the Newsletters list (they
     * show under its Archived tab) and locked until unarchived.
     */
    public const ARCHIVABLE = [self::DRAFT, self::CONTENT_REVISION, self::COMPLETED];

    /** -> archived (spec 5.1 status 9), remembering the status it had. */
    public static function archive(int $id): array
    {
        $campaign = self::findOrFail($id);
        if (!in_array($campaign['status'], self::ARCHIVABLE, true)) {
            throw new HttpException('Only a draft, a newsletter in content revision or a completed newsletter can be archived.', 422);
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
     * sending goes back to content revision (a new review is needed). The
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

        return self::update($id, ['status' => self::CONTENT_REVISION]);
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
