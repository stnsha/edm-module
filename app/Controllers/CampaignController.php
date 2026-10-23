<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Models\Campaign;
use Edm\Models\CampaignContent;
use Edm\Models\Contact;
use Edm\Models\SendLog;
use Edm\Services\CampaignRecipients;
use Edm\Services\Analytics\CampaignAnalytics;
use Edm\Services\CampaignReport;
use Edm\Services\Ses\CampaignMessage;
use Edm\Services\Ses\MessageRenderer;
use Edm\Services\Ses\SesConfig;
use Edm\Services\Ses\Unsubscribe;

/**
 * Newsletters (newsletters/). Actions:
 *   campaigns_list (?archived=1: only archived, else all but archived)
 *   campaigns_create | campaigns_update | campaigns_delete
 *   (campaigns_create takes an optional template_id: its design is copied in)
 *   campaigns_duplicate "Reuse": a copy as a new draft
 *   campaigns_stop      scheduled -> draft, sending -> completed
 *   campaigns_recall    "Don't send it": scheduled (not yet sending) -> draft
 *   Settings also take audience { include, exclude: { segments, contacts } },
 *   send_now, track_opens, track_clicks, utm_enabled, utm { source, medium,
 *   campaign, content } (Campaign::settingsInput()).
 *   campaigns_archive   draft / completed -> archived (archived_from kept)
 *   campaigns_unarchive archived -> the status it had before
 *   campaigns_counts    { active, archived } for the Newsletters / Archived tabs
 *   Submit / approve / reject / withdraw live in the Email creator
 *   (EmailBuilderController, steps 5 and 6); there is no way to schedule
 *   without approval. From Pending approval on the newsletter is locked
 *   (Campaign::isLocked()).
 *   campaigns_recipients ?id= "Show recipients": once sending has started, the
 *                       addresses in edm_send_log with their send status
 *                       (source "sent"); before that, who the audience
 *                       resolves to now (source "audience", suppressed flag)
 *   campaigns_report    ?id= Newsletter report (dashboard/index.php?newsletter=):
 *                       figures + every send log row (CampaignReport)
 *   campaigns_analytics ?id= (&refresh=1 skips the cache) website figures from
 *                       Google Analytics 4 by the newsletter's UTM tags
 *                       (Services/Analytics/CampaignAnalytics); 502 when GA
 *                       answers with an error
 *   campaigns_preview   raw email HTML for an <iframe> (not JSON), exactly as
 *                       it is sent to the first recipient the audience
 *                       resolves to: variables filled from that contact, a
 *                       real signed unsubscribe link (marked preview=1 so
 *                       it cannot unsubscribe anyone), the automatic footer
 *                       when the design has no unsubscribe link
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
        'status'     => ['sometimes', 'integer', 'in:1,2,6,7,8,9'],
    ];

    /**
     * Department PICs (Auth::isPic()): their own newsletters only (handle()
     * checks the id), no templates-based create, no report.
     */
    private const PIC_ACTIONS = [
        'campaigns_list', 'campaigns_new', 'campaigns_update', 'campaigns_delete', 'campaigns_duplicate',
        'campaigns_stop', 'campaigns_recall', 'campaigns_archive', 'campaigns_unarchive', 'campaigns_counts',
        'campaigns_recipients', 'campaigns_preview',
    ];

    protected static function allowsPic(string $action): bool
    {
        return in_array($action, self::PIC_ACTIONS, true);
    }

    protected function handle(string $action): mixed
    {
        // A PIC may only act on newsletters they created.
        $owner = $this->auth->isPic() ? $this->auth->staffId : null;
        if ($owner !== null && $this->request->id() > 0) {
            Campaign::assertVisibleTo(Campaign::findOrFail($this->request->id()), $this->auth);
        }

        switch ($action) {
            case 'campaigns_list':
                return Campaign::listing(null, !empty($this->request->query('archived')), $owner);
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
            case 'campaigns_duplicate':
                return Campaign::duplicate($this->requireId('Newsletter'), $this->stamp('requested_by'));
            case 'campaigns_stop':
                return Campaign::stop($this->requireId('Newsletter'));
            case 'campaigns_recall':
                return Campaign::recall($this->requireId('Newsletter'));
            case 'campaigns_archive':
                return Campaign::archive($this->requireId('Newsletter'));
            case 'campaigns_counts':
                $byStatus = Campaign::countsByStatus($owner);
                return ['archived' => $byStatus['archived'], 'active' => array_sum($byStatus) - $byStatus['archived']];
            case 'campaigns_unarchive':
                return Campaign::unarchive($this->requireId('Newsletter'));
            case 'campaigns_recipients':
                return $this->recipients($this->requireId('Newsletter'));
            case 'campaigns_report':
                return (new CampaignReport($this->db))->build($this->requireId('Newsletter'));
            case 'campaigns_analytics':
                return CampaignAnalytics::fromEnv()->build(
                    Campaign::findOrFail($this->requireId('Newsletter')),
                    (string) $this->request->get('refresh', '') === '1'
                );
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

    /** Most rows the Show recipients list returns. */
    private const RECIPIENTS_LIMIT = 5000;

    /**
     * @return array{source: string, total: int, rows: list<array<string, mixed>>}
     */
    private function recipients(int $id): array
    {
        $campaign = Campaign::findOrFail($id);
        $sent = $this->db->select(
            'SELECT `email`, `status`, `sent_at`, `error` FROM `edm_send_log`
              WHERE `campaign_id` = ? AND `deleted_at` IS NULL ORDER BY `id` ASC LIMIT ' . self::RECIPIENTS_LIMIT,
            [$id]
        );
        if ($sent !== []) {
            return [
                'source' => 'sent',
                'total'  => (int) $this->db->scalar('SELECT COUNT(*) FROM `edm_send_log` WHERE `campaign_id` = ? AND `deleted_at` IS NULL', [$id]),
                'rows'   => array_map(static fn (array $r): array => [
                    'email'   => (string) $r['email'],
                    'status'  => (int) $r['status'],
                    'label'   => ucfirst(SendLog::STATUSES[(int) $r['status']] ?? ''),
                    'sent_at' => $r['sent_at'] !== null ? date('d-m-Y H:i:s', strtotime((string) $r['sent_at'])) : null,
                    'error'   => $r['error'],
                ], $sent),
            ];
        }

        $service = new CampaignRecipients($this->db);
        $audience = CampaignRecipients::of($campaign);

        return [
            'source' => 'audience',
            'total'  => $service->count($audience)['matched'],
            'rows'   => $service->rows($audience, self::RECIPIENTS_LIMIT),
        ];
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
        $campaign = $id > 0 ? Campaign::find($id) : null;
        if ($campaign === null) {
            exit;
        }
        // As it will be sent: preview text, UTM tags, click tracking off, then
        // the recipient's variables and unsubscribe link (MessageRenderer).
        $html = CampaignMessage::html($campaign, (string) (CampaignContent::forCampaign($id)['html'] ?? ''));
        echo MessageRenderer::html($html, $this->previewRecipient($campaign));
        exit;
    }

    /**
     * Variables of the person the preview is shown as: the first recipient the
     * audience resolves to now (with their custom fields), or a placeholder
     * address when nobody matches. The unsubscribe link is the real signed
     * one plus preview=1, which public/unsubscribe.php shows but never acts on.
     *
     * @return array{email: string, name: ?string, fields: ?array<string, mixed>, unsubscribe_url: string}
     */
    private function previewRecipient(array $campaign): array
    {
        // Suppressed addresses are listed by rows() but never sent to.
        $rows = (new CampaignRecipients($this->db))->rows(CampaignRecipients::of($campaign), 50);
        $first = array_values(array_filter($rows, static fn (array $r): bool => !$r['suppressed']))[0] ?? null;
        $email = $first['email'] ?? 'preview@example.com';
        $contact = $first !== null ? (Contact::where('LOWER(`email`) = ?', [strtolower($email)])[0] ?? null) : null;

        $config = SesConfig::fromEnv();
        $unsubscribe = $config->missingForSending() === []
            ? (new Unsubscribe($config))->url((int) $campaign['id'], $email) . '&preview=1'
            : '#';

        return [
            'email'           => $email,
            'name'            => $contact['name'] ?? ($first['name'] ?? null),
            'fields'          => is_array($contact['fields'] ?? null) ? $contact['fields'] : null,
            'unsubscribe_url' => $unsubscribe,
        ];
    }
}
