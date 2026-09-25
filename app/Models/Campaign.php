<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Database;
use Edm\Core\HttpException;
use Edm\Core\Model;

/**
 * A newsletter. Table: edm_campaigns.
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
    public const CONTENT_REVISION = 4;
    public const SCHEDULED = 6;
    public const SENDING = 7;
    public const COMPLETED = 8;

    protected const TABLE = 'edm_campaigns';

    protected const FILLABLE = [
        'name', 'subject', 'subject_b', 'preheader', 'sender_id', 'list_id', 'segment_id',
        'status', 'scheduled_at', 'requested_by', 'requested_by_name',
    ];

    protected const CASTS = ['status' => 'int', 'scheduled_at' => 'datetime'];

    protected const ORDER = '`created_at` DESC, `id` DESC';

    /** Draft and content revision are the only states still being worked on. */
    public static function isEditable(array $campaign): bool
    {
        return in_array($campaign['status'], [self::DRAFT, self::CONTENT_REVISION], true);
    }

    /**
     * Newsletters list: every row plus `delivered` (edm_send_log rows) and
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
            $row['list'] = $row['list_id'] !== null && $listName !== null
                ? ['id' => (int) $row['list_id'], 'name' => $listName] : null;

            return $row;
        }, self::db()->select($sql, $params));
    }

    /**
     * One newsletter with its `content` and `list`.
     *
     * @return array<string, mixed>
     */
    public static function withDetails(int $id): array
    {
        $campaign = self::findOrFail($id);
        $campaign['content'] = CampaignContent::forCampaign($id);
        $list = $campaign['list_id'] !== null ? ContactList::find((int) $campaign['list_id']) : null;
        $campaign['list'] = $list ? ['id' => $list['id'], 'name' => $list['name']] : null;

        return $campaign;
    }

    /**
     * New draft plus its empty content row.
     *
     * @param array<string, mixed> $data validated payload
     * @return array<string, mixed>
     */
    public static function createDraft(array $data): array
    {
        return self::db()->transaction(static function () use ($data): array {
            $campaign = self::create(['status' => self::DRAFT] + $data);
            CampaignContent::create(['campaign_id' => $campaign['id'], 'html' => '', 'version' => 1]);

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
    public static function submit(int $id): array
    {
        $campaign = self::findOrFail($id);
        if (!self::isEditable($campaign)) {
            throw new HttpException('Only a draft or a campaign in revision can be submitted for review.', 422);
        }

        return self::update($id, ['status' => self::PENDING_SUBMISSION]);
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
