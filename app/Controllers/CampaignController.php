<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\ValidationException;
use Edm\Models\Campaign;
use Edm\Models\CampaignContent;
use Edm\Services\SegmentQuery;

/**
 * Campaigns (campaign/). Actions:
 *   campaigns_list | campaigns_create | campaigns_update | campaigns_delete
 *   (campaigns_create takes an optional template_id: its design is copied in)
 *   campaigns_submit    draft / revision -> pending submission
 *   campaigns_duplicate "Reuse" (same list) or copy to list_id
 *   campaigns_stop      scheduled -> draft, sending -> completed
 *   campaigns_archive   completed -> archived
 *   Scheduled is reached only by final approval (ApprovalController, spec
 *   5.2 step 6); from then on the campaign is locked (Campaign::isLocked()).
 *   campaigns_preview   raw email HTML for an <iframe> (not JSON)
 *   campaigns_new    POST -> empty draft (default sender preset), opened in the
 *                    Email creator by the New campaign button
 */
final class CampaignController extends Controller
{
    private const RULES = [
        'name'              => ['required', 'string', 'max:255'],
        'subject'           => ['required', 'string', 'max:255'],
        'subject_b'         => ['nullable', 'string', 'max:255'],
        'preheader'         => ['nullable', 'string', 'max:255'],
        'sender_id'         => ['required', 'integer', 'exists:edm_senders,id'],
        // A list id, or "all" (all_lists); one of the two is required.
        'list_id'           => ['nullable', 'integer', 'exists:edm_lists,id'],
        'all_lists'         => ['sometimes', 'boolean'],
        'segment_id'        => ['nullable', 'integer', 'exists:edm_segments,id'],
        'scheduled_at'      => ['nullable', 'date'],
        'requested_by'      => ['nullable', 'integer'],
        'requested_by_name' => ['nullable', 'string', 'max:150'],
    ];

    private const UPDATE_RULES = [
        'name'         => ['sometimes', 'string', 'max:255'],
        'subject'      => ['sometimes', 'required', 'string', 'max:255'],
        'subject_b'    => ['nullable', 'string', 'max:255'],
        'preheader'    => ['nullable', 'string', 'max:255'],
        'sender_id'    => ['sometimes', 'required', 'integer', 'exists:edm_senders,id'],
        'list_id'      => ['nullable', 'integer', 'exists:edm_lists,id'],
        'all_lists'    => ['sometimes', 'boolean'],
        'segment_id'   => ['nullable', 'integer', 'exists:edm_segments,id'],
        'scheduled_at' => ['nullable', 'date'],
        'status'     => ['sometimes', 'integer', 'in:1,2,3,4,5,6,7,8,9'],
    ];

    protected function handle(string $action): mixed
    {
        switch ($action) {
            case 'campaigns_list':
                return Campaign::listing();
            case 'campaigns_new':
                return Campaign::createBlank($this->stamp('requested_by'));
            case 'campaigns_create':
                $data = $this->validator->validate($this->payload() + $this->stamp('requested_by'), self::RULES);
                if (!Campaign::hasAudience($data + ['list_id' => null, 'all_lists' => false])) {
                    throw ValidationException::single('list_id', 'Choose a recipient list.');
                }
                SegmentQuery::forCampaign($data['segment_id'] ?? null, Campaign::audienceListId($data + ['all_lists' => false]));
                $template = $this->validator->validate(
                    $this->request->ids(['template_id']),
                    ['template_id' => ['sometimes', 'nullable', 'integer', 'exists:edm_templates,id']]
                );
                return Campaign::createDraft($data, $template['template_id'] ?? null);
            case 'campaigns_update':
                $id = $this->requireId('Campaign');
                $existing = Campaign::findOrFail($id);
                Campaign::assertUnlocked($existing);
                $data = $this->validator->validate($this->payload(), self::UPDATE_RULES, $id);
                if (array_key_exists('list_id', $data) && !Campaign::hasAudience($data)) {
                    throw ValidationException::single('list_id', 'Choose a recipient list.');
                }
                SegmentQuery::forCampaign(
                    array_key_exists('segment_id', $data) ? $data['segment_id'] : $existing['segment_id'],
                    Campaign::audienceListId($data + $existing)
                );
                Campaign::update($id, $data);
                return Campaign::withDetails($id);
            case 'campaigns_delete':
                Campaign::delete($this->requireId('Campaign'));
                return null;
            case 'campaigns_submit':
                return Campaign::submit($this->requireId('Campaign'));
            case 'campaigns_duplicate':
                $changeList = $this->request->has('list_id');
                $target = $this->request->ids(['list_id'])['list_id'] ?? null;
                if ($changeList && $target !== null) {
                    $this->validator->validate(['list_id' => $target], ['list_id' => ['integer', 'exists:edm_lists,id']]);
                }
                return Campaign::duplicate($this->requireId('Campaign'), $this->stamp('requested_by'), $changeList, $target);
            case 'campaigns_stop':
                return Campaign::stop($this->requireId('Campaign'));
            case 'campaigns_archive':
                return Campaign::archive($this->requireId('Campaign'));
            case 'campaigns_preview':
                $this->preview();
        }
        $this->unknown();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        // list_id is a list id or Campaign::ALL_LISTS ("all").
        $payload = $this->request->only(['name', 'subject', 'subject_b', 'preheader', 'scheduled_at', 'list_id'])
            + $this->request->ids(['sender_id', 'segment_id']);

        return Campaign::audienceInput($payload) + $payload;
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
