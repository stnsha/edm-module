<?php

declare(strict_types=1);

namespace Edm\Models;

use Edm\Core\Model;

/**
 * A newsletter body: html (what is sent) + editor_json (EmailBuilder.js
 * block tree, so the design reopens editable). One row per campaign.
 * Table: edm_campaign_content.
 */
final class CampaignContent extends Model
{
    protected const TABLE = 'edm_campaign_content';

    protected const FILLABLE = ['campaign_id', 'html', 'editor_json', 'version'];

    protected const CASTS = ['editor_json' => 'json', 'version' => 'int'];

    protected const ORDER = '`id` ASC';

    /** The campaign's content row, created empty when missing. */
    public static function forCampaign(int $campaignId): array
    {
        $rows = self::where('`campaign_id` = ?', [$campaignId], null, 1);
        if ($rows !== []) {
            return $rows[0];
        }

        return self::create(['campaign_id' => $campaignId, 'html' => '', 'version' => 1]);
    }

    /**
     * Save a new version of the body.
     *
     * @param array<mixed>|null $editorJson null keeps the stored design
     */
    public static function saveBody(int $campaignId, string $html, ?array $editorJson): array
    {
        $current = self::forCampaign($campaignId);
        $data = ['html' => $html, 'version' => (int) $current['version'] + 1];
        if ($editorJson !== null) {
            $data['editor_json'] = $editorJson;
        }

        return self::update((int) $current['id'], $data);
    }
}
