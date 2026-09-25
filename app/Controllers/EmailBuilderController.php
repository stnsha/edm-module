<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\HttpException;
use Edm\Models\Campaign;
use Edm\Models\CampaignContent;

/**
 * Email creator (email-builder/). The campaign id comes from ?campaign= or
 * the body's `campaign`. Actions:
 *   campaigns_list  picker for the bare landing page
 *   load            newsletter + content + list
 *   settings_save   the settings panel above the builder
 *   content_get     html + editor_json
 *   content_save    new body version (html + EmailBuilder.js editor_json)
 *   submit          draft / revision -> pending submission
 */
final class EmailBuilderController extends Controller
{
    private const SETTINGS_RULES = [
        'name'         => ['sometimes', 'string', 'max:255'],
        'subject'      => ['nullable', 'string', 'max:255'],
        'sender_id'    => ['nullable', 'integer', 'exists:edm_senders,id'],
        'list_id'      => ['nullable', 'integer', 'exists:edm_lists,id'],
        'scheduled_at' => ['nullable', 'date'],
    ];

    protected function handle(string $action): mixed
    {
        if ($action === 'campaigns_list') {
            return Campaign::listing();
        }

        $id = (int) ($this->request->query('campaign') ?? $this->request->get('campaign', 0));
        if ($id <= 0) {
            throw new HttpException('A campaign id is required', 422);
        }

        switch ($action) {
            case 'load':
                return Campaign::withDetails($id);
            case 'settings_save':
                Campaign::findOrFail($id);
                $payload = $this->request->only(['name', 'subject', 'scheduled_at'])
                    + $this->request->ids(['sender_id', 'list_id']);
                Campaign::update($id, $this->validator->validate($payload, self::SETTINGS_RULES, $id));
                return Campaign::withDetails($id);
            case 'content_get':
                Campaign::findOrFail($id);
                return CampaignContent::forCampaign($id);
            case 'content_save':
                Campaign::findOrFail($id);
                $json = $this->request->get('editor_json');
                return CampaignContent::saveBody($id, (string) $this->request->get('html', ''), is_array($json) ? $json : null);
            case 'submit':
                return Campaign::submit($id);
        }
        $this->unknown();
    }
}
