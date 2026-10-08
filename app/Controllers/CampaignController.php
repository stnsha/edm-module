<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Models\Campaign;
use Edm\Models\CampaignContent;
use Edm\Services\CampaignRecall;
use Edm\Services\Ses\CampaignMessage;

/**
 * Newsletters (campaign/). Actions:
 *   campaigns_list (?archived=1: only archived, else all but archived)
 *   campaigns_create | campaigns_update | campaigns_delete
 *   (campaigns_create takes an optional template_id: its design is copied in)
 *   campaigns_submit    draft / revision -> pending submission
 *   campaigns_duplicate "Reuse": a copy as a new draft
 *   campaigns_stop      scheduled -> draft, sending -> completed
 *   campaigns_recall    "Don't send it": scheduled (not yet sending) -> content revision
 *   Settings also take audience { include, exclude: { segments, contacts } },
 *   send_now, track_opens, track_clicks, utm_enabled, utm { source, medium,
 *   campaign, content } (Campaign::settingsInput()).
 *   campaigns_archive   draft / content revision / completed -> archived (archived_from kept)
 *   campaigns_unarchive archived -> the status it had before
 *   campaigns_counts    { active, archived } for the Newsletters / Archived tabs
 *   Scheduled is reached only by final approval (ApprovalController, spec
 *   5.2 step 6); from then on the newsletter is locked (Campaign::isLocked()).
 *   campaigns_preview   raw email HTML for an <iframe> (not JSON)
 *   campaigns_new    POST -> empty draft (default sender preset), opened in the
 *                    Email creator by the New newsletter button
 */
final class CampaignController extends Controller
{
    private const RULES = [
        'name'              => ['required', 'string', 'max:255'],
        'subject'           => ['required', 'string', 'max:255'],
        'subject_b'         => ['nullable', 'string', 'max:255'],
        'preheader'         => ['nullable', 'string', 'max:255'],
        'sender_id'         => ['required', 'integer', 'exists:edm_senders,id'],
        'reply_to_id'       => ['nullable', 'integer', 'exists:edm_senders,id'],
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
        'reply_to_id'  => ['nullable', 'integer', 'exists:edm_senders,id'],
        'scheduled_at' => ['nullable', 'date'],
        'status'     => ['sometimes', 'integer', 'in:1,2,3,4,5,6,7,8,9'],
    ];

    protected function handle(string $action): mixed
    {
        switch ($action) {
            case 'campaigns_list':
                return Campaign::listing(null, !empty($this->request->query('archived')));
            case 'campaigns_new':
                return Campaign::createBlank($this->stamp('requested_by'));
            case 'campaigns_create':
                $data = $this->validator->validate($this->payload() + $this->stamp('requested_by'), self::RULES)
                    + Campaign::settingsInput($this->request->input);
                $template = $this->validator->validate(
                    $this->request->ids(['template_id']),
                    ['template_id' => ['sometimes', 'nullable', 'integer', 'exists:edm_templates,id']]
                );
                return Campaign::createDraft($data, $template['template_id'] ?? null);
            case 'campaigns_update':
                $id = $this->requireId('Newsletter');
                $existing = Campaign::findOrFail($id);
                Campaign::assertUnlocked($existing);
                $data = $this->validator->validate($this->payload(), self::UPDATE_RULES, $id)
                    + Campaign::settingsInput($this->request->input);
                Campaign::update($id, $data);
                return Campaign::withDetails($id);
            case 'campaigns_delete':
                Campaign::delete($this->requireId('Newsletter'));
                return null;
            case 'campaigns_submit':
                return Campaign::submit($this->requireId('Newsletter'));
            case 'campaigns_duplicate':
                return Campaign::duplicate($this->requireId('Newsletter'), $this->stamp('requested_by'));
            case 'campaigns_stop':
                return Campaign::stop($this->requireId('Newsletter'));
            case 'campaigns_recall':
                return (new CampaignRecall($this->db))->recall($this->requireId('Newsletter'), $this->auth);
            case 'campaigns_archive':
                return Campaign::archive($this->requireId('Newsletter'));
            case 'campaigns_counts':
                $byStatus = Campaign::countsByStatus();
                return ['archived' => $byStatus['archived'], 'active' => array_sum($byStatus) - $byStatus['archived']];
            case 'campaigns_unarchive':
                return Campaign::unarchive($this->requireId('Newsletter'));
            case 'campaigns_preview':
                $this->preview();
        }
        $this->unknown();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return $this->request->only(['name', 'subject', 'subject_b', 'preheader', 'scheduled_at'])
            + $this->request->ids(['sender_id', 'reply_to_id']);
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
        // As it will be sent: preview text, UTM tags, click tracking off.
        $campaign = $id > 0 ? Campaign::find($id) : null;
        echo $campaign !== null ? CampaignMessage::html($campaign, (string) (CampaignContent::forCampaign($id)['html'] ?? '')) : '';
        exit;
    }
}
