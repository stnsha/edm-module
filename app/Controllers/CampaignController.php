<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Models\Campaign;
use Edm\Models\CampaignContent;

/**
 * Newsletters (campaign/). Actions:
 *   campaigns_list | campaigns_create | campaigns_update | campaigns_delete
 *   campaigns_submit    draft / revision -> pending submission
 *   campaigns_duplicate "Reuse" (same list) or copy to list_id
 *   campaigns_stop      scheduled -> draft, sending -> completed
 *   campaigns_preview   raw email HTML for an <iframe> (not JSON)
 */
final class CampaignController extends Controller
{
    private const RULES = [
        'name'              => ['required', 'string', 'max:255'],
        'subject'           => ['nullable', 'string', 'max:255'],
        'subject_b'         => ['nullable', 'string', 'max:255'],
        'preheader'         => ['nullable', 'string', 'max:255'],
        'sender_id'         => ['nullable', 'integer', 'exists:edm_senders,id'],
        'list_id'           => ['nullable', 'integer', 'exists:edm_lists,id'],
        'segment_id'        => ['nullable', 'integer', 'exists:edm_segments,id'],
        'scheduled_at'      => ['nullable', 'date'],
        'requested_by'      => ['nullable', 'integer'],
        'requested_by_name' => ['nullable', 'string', 'max:150'],
    ];

    private const UPDATE_RULES = [
        'name'         => ['sometimes', 'string', 'max:255'],
        'subject'      => ['nullable', 'string', 'max:255'],
        'subject_b'    => ['nullable', 'string', 'max:255'],
        'preheader'    => ['nullable', 'string', 'max:255'],
        'sender_id'    => ['nullable', 'integer', 'exists:edm_senders,id'],
        'list_id'      => ['nullable', 'integer', 'exists:edm_lists,id'],
        'segment_id'   => ['nullable', 'integer', 'exists:edm_segments,id'],
        'scheduled_at' => ['nullable', 'date'],
        'status'       => ['sometimes', 'integer', 'in:1,2,3,4,5,6,7,8,9'],
    ];

    protected function handle(string $action): mixed
    {
        switch ($action) {
            case 'campaigns_list':
                return Campaign::listing();
            case 'campaigns_create':
                $data = $this->validator->validate($this->payload() + $this->stamp('requested_by'), self::RULES);
                return Campaign::createDraft($data);
            case 'campaigns_update':
                $id = $this->requireId('Newsletter');
                Campaign::findOrFail($id);
                Campaign::update($id, $this->validator->validate($this->payload(), self::UPDATE_RULES, $id));
                return Campaign::withDetails($id);
            case 'campaigns_delete':
                Campaign::delete($this->requireId('Newsletter'));
                return null;
            case 'campaigns_submit':
                return Campaign::submit($this->requireId('Newsletter'));
            case 'campaigns_duplicate':
                $changeList = $this->request->has('list_id');
                $target = $this->request->ids(['list_id'])['list_id'] ?? null;
                if ($changeList && $target !== null) {
                    $this->validator->validate(['list_id' => $target], ['list_id' => ['integer', 'exists:edm_lists,id']]);
                }
                return Campaign::duplicate($this->requireId('Newsletter'), $this->stamp('requested_by'), $changeList, $target);
            case 'campaigns_stop':
                return Campaign::stop($this->requireId('Newsletter'));
            case 'campaigns_preview':
                $this->preview();
        }
        $this->unknown();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return $this->request->only(['name', 'subject', 'subject_b', 'preheader', 'scheduled_at'])
            + $this->request->ids(['sender_id', 'list_id', 'segment_id']);
    }

    /**
     * Raw email HTML for the row thumbnail / Preview modal. The CSP sandbox
     * header keeps staff-authored markup from running scripts on this
     * origin even if the URL is opened directly.
     */
    private function preview(): never
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Security-Policy: sandbox');
        $id = $this->request->id();
        echo $id > 0 && Campaign::find($id) !== null ? (string) (CampaignContent::forCampaign($id)['html'] ?? '') : '';
        exit;
    }
}
