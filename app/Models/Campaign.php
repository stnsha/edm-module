<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Database;
use Edm\Core\HttpException;
use Edm\Core\Model;

/**
 * A campaign. Table: edm_campaigns.
 *
 * status is an integer, never a word (spec 5.1, 9-state flow) - see STATUSES.
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

    /** Statuses a campaign can be raised for review from (approval/edit.php). */
    public const REVIEWABLE = [self::DRAFT, self::PENDING_SUBMISSION, self::CONTENT_REVISION];

    protected const TABLE = 'edm_campaigns';

    protected const FILLABLE = [
        'name', 'subject', 'subject_b', 'preheader', 'sender_id', 'list_id', 'all_lists', 'segment_id',
        'status', 'scheduled_at', 'requested_by', 'requested_by_name',
    ];

    protected const CASTS = ['status' => 'int', 'all_lists' => 'bool', 'scheduled_at' => 'datetime'];

    /** Recipient list select value meaning "every list" (all_lists = 1, list_id NULL). */
    public const ALL_LISTS = 'all';

    /** Shown in place of a list name for an all-lists campaign. */
    public const ALL_LISTS_LABEL = 'All lists';

    protected const ORDER = '`created_at` DESC, `id` DESC';

    /** True when the campaign has recipients chosen: one list, or all lists. */
    public static function hasAudience(array $campaign): bool
    {
        return $campaign['all_lists'] || $campaign['list_id'] !== null;
    }

    /**
     * The list recipients come from: its id, or null for every list. Only
     * meaningful when hasAudience() is true.
     */
    public static function audienceListId(array $campaign): ?int
    {
        return $campaign['all_lists'] || $campaign['list_id'] === null ? null : (int) $campaign['list_id'];
    }

    /**
     * Recipient list fields from a request's list_id: ALL_LISTS sets
     * all_lists, anything else is the list id (or null) and clears it. Empty
     * when the request has no list_id.
     *
     * @param array<string, mixed> $input
     * @return array{list_id?: ?int, all_lists?: bool}
     */
    public static function audienceInput(array $input): array
    {
        if (!array_key_exists('list_id', $input)) {
            return [];
        }
        $v = $input['list_id'];
        if ($v === self::ALL_LISTS) {
            return ['list_id' => null, 'all_lists' => true];
        }

        return ['list_id' => ($v === '' || $v === null) ? null : (int) $v, 'all_lists' => false];
    }

    /** Draft and content revision are the only states still being worked on. */
    public static function isEditable(array $campaign): bool
    {
        return in_array($campaign['status'], [self::DRAFT, self::CONTENT_REVISION], true);
    }

    /**
     * Campaigns list: every row plus `delivered` (edm_send_log rows) and
     * `list` ({ id, name } or null). Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function listing(?int $status = null, ?int $listId = null): array
    {
        $where = ['c.`deleted_at` IS NULL'];
        $params = [];
        if ($status !== null) {
            $where[] = 'c.`status` = ?';
            $params[] = $status;
        }
        if ($listId !== null) {
            $where[] = 'c.`list_id` = ?';
            $params[] = $listId;
        }
        $sql = 'SELECT c.*,
                    (SELECT COUNT(*) FROM `edm_send_log` s
                        WHERE s.`campaign_id` = c.`id` AND s.`deleted_at` IS NULL) AS delivered,
                    l.`name` AS list_name
                FROM `edm_campaigns` c
                LEFT JOIN `edm_lists` l ON l.`id` = c.`list_id` AND l.`deleted_at` IS NULL
                WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.`created_at` DESC, c.`id` DESC';

        return array_map(static function (array $row): array {
            $listName = $row['list_name'];
            unset($row['list_name']);
            $row = self::present($row);
            $row['delivered'] = (int) $row['delivered'];
            $row['list'] = match (true) {
                $row['all_lists']                              => ['id' => null, 'name' => self::ALL_LISTS_LABEL],
                $row['list_id'] !== null && $listName !== null => ['id' => (int) $row['list_id'], 'name' => $listName],
                default                                        => null,
            };

            return $row;
        }, self::db()->select($sql, $params));
    }

    /**
     * One campaign with its `content` and `list`.
     *
     * @return array<string, mixed>
     */
    public static function withDetails(int $id): array
    {
        $campaign = self::findOrFail($id);
        $campaign['content'] = CampaignContent::forCampaign($id);
        $list = !$campaign['all_lists'] && $campaign['list_id'] !== null ? ContactList::find((int) $campaign['list_id']) : null;
        $campaign['list'] = $campaign['all_lists']
            ? ['id' => null, 'name' => self::ALL_LISTS_LABEL]
            : ($list ? ['id' => $list['id'], 'name' => $list['name']] : null);

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
     * "Reuse" / "Copy to another list": settings + body copied into a new
     * draft. The schedule is never copied; a new list drops the segment.
     *
     * @param array<string, mixed> $stamp requested_by / requested_by_name
     * @return array<string, mixed>
     */
    public static function duplicate(int $id, array $stamp, bool $changeList = false, ?int $listId = null): array
    {
        $source = self::withDetails($id);

        return self::db()->transaction(static function () use ($source, $stamp, $changeList, $listId): array {
            $copy = self::create([
                'name'         => mb_substr($source['name'] . ' (copy)', 0, 255),
                'subject'      => $source['subject'],
                'subject_b'    => $source['subject_b'],
                'preheader'    => $source['preheader'],
                'sender_id'    => $source['sender_id'],
                'list_id'      => $changeList ? $listId : $source['list_id'],
                'all_lists'    => $changeList ? false : $source['all_lists'],
                'segment_id'   => $changeList ? null : $source['segment_id'],
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
     * New campaign from the Campaigns list: an empty draft opened
     * straight in the Email creator. Sender, list and subject are filled in
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
            'name'      => 'Untitled campaign ' . date('d-m-Y H:i'),
            'sender_id' => $sender !== null ? (int) $sender : null,
        ] + $stamp);
    }

    public static function submit(int $id): array
    {
        $campaign = self::findOrFail($id);
        if (!self::isEditable($campaign)) {
            throw new HttpException('Only a draft or a campaign in revision can be submitted for review.', 422);
        }
        // Drafts may be saved half-filled; a submitted one must be complete.
        $missing = self::missing($campaign);
        if ($missing !== []) {
            throw new HttpException('Before submitting, add ' . implode(', ', $missing) . '.', 422);
        }

        return self::update($id, ['status' => self::PENDING_SUBMISSION]);
    }

    /**
     * What a campaign still lacks before it can be submitted or scheduled,
     * e.g. ['a sender', 'a design']; empty when complete.
     *
     * @return list<string>
     */
    public static function missing(array $campaign): array
    {
        return array_keys(array_filter([
            'a sender'         => $campaign['sender_id'] === null,
            'a recipient list' => !self::hasAudience($campaign),
            'a subject line'   => trim((string) $campaign['subject']) === '',
            'a design'         => trim((string) (CampaignContent::forCampaign((int) $campaign['id'])['html'] ?? '')) === '',
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

    /** @throws HttpException 422 when the campaign is locked (see isLocked()). */
    public static function assertUnlocked(array $campaign): void
    {
        if (self::isLocked($campaign)) {
            throw new HttpException('This campaign is scheduled or sent, so it is locked. Stop it first (scheduled only) to make changes.', 422);
        }
    }

    /** Completed -> archived (spec 5.1 status 9): closed, kept for reporting. */
    public static function archive(int $id): array
    {
        $campaign = self::findOrFail($id);
        if ($campaign['status'] !== self::COMPLETED) {
            throw new HttpException('Only a completed campaign can be archived.', 422);
        }

        return self::update($id, ['status' => self::ARCHIVED]);
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
            throw new HttpException('Only a scheduled or sending campaign can be stopped.', 422);
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
