<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\HttpException;
use Edm\Core\Model;
use Edm\Models\Campaign;
use Edm\Models\CampaignContent;
use Edm\Models\MasterTag;
use Edm\Models\Sender;
use Edm\Models\Template;
use Edm\Services\ScheduleConflicts;
use Edm\Services\Ses\CampaignMessage;
use Edm\Services\Ses\IdentitySync;
use Edm\Services\Ses\MessageRenderer;
use Edm\Services\Ses\SesException;
use Edm\Services\Ses\SesGateway;
use Edm\Services\Ses\Unsubscribe;

/**
 * Email creator (email-builder/). The newsletter id comes from ?newsletter=
 * (?campaign= still read, the old name) or the body's `campaign`. Actions:
 *   campaigns_list  picker for the bare landing page
 *   load            newsletter + content + audience
 *   settings_save   the wizard settings: name, subject, preheader, tags (ids)
 *                   + new_tags (names, created with the save), sender_id,
 *                   reply_to_id, audience, scheduled_at / send_now, track_*,
 *                   utm_enabled, utm (Campaign::settingsInput())
 *   content_get     html + editor_json
 *   content_save    new body version (html + EmailBuilder.js editor_json)
 *   conflicts       calendar conflicts for ?date= (Scheduled send warning)
 *   template_get    a template's html + editor_json ("Start from template";
 *                   loaded into the editor only, saved by the next Save)
 *   schedule        draft -> scheduled (Campaign::schedule())
 *   send_test       { to } one "[Test]" email of the saved design via SES
 *   sender_create   { from_name, email } "Create new sender" in the Sender /
 *                   Reply-to selects: adds a pending sender to Settings >
 *                   Senders and asks SES to email it a verification link
 */
final class EmailBuilderController extends Controller
{
    // Only the name is required: a draft is saved half-filled while it is
    // being built; Campaign::schedule() checks sender, subject, design.
    private const SETTINGS_RULES = [
        'name'         => ['sometimes', 'required', 'string', 'max:255'],
        'subject'      => ['nullable', 'string', 'max:255'],
        'preheader'    => ['nullable', 'string', 'max:255'],
        'sender_id'    => ['nullable', 'integer', 'exists:edm_senders,id'],
        'reply_to_id'  => ['nullable', 'integer', 'exists:edm_senders,id'],
        'scheduled_at' => ['nullable', 'date'],
    ];

    protected function handle(string $action): mixed
    {
        if ($action === 'campaigns_list') {
            return Campaign::listing();
        }

        $id = (int) ($this->request->query('newsletter') ?? $this->request->query('campaign') ?? $this->request->get('campaign', 0));
        if ($id <= 0) {
            throw new HttpException('A newsletter id is required', 422);
        }

        switch ($action) {
            case 'load':
                return Campaign::withDetails($id);
            case 'settings_save':
                $existing = Campaign::findOrFail($id);
                Campaign::assertUnlocked($existing);
                $payload = $this->request->only(['name', 'subject', 'preheader', 'scheduled_at'])
                    + $this->request->ids(['sender_id', 'reply_to_id']);
                $data = $this->validator->validate($payload, self::SETTINGS_RULES, $id)
                    + Campaign::settingsInput($this->request->input);
                // Tags: chosen ids + new names typed in the Setup step; new
                // ones are created with this save (one transaction).
                $this->db->transaction(function () use ($id, $data): void {
                    if ($this->request->has('tags') || $this->request->has('new_tags')) {
                        $data['tag_ids'] = MasterTag::resolve($this->request->get('tags'), $this->request->get('new_tags'), $this->stamp());
                    }
                    Campaign::update($id, $data);
                });
                return Campaign::withDetails($id);
            case 'content_get':
                Campaign::findOrFail($id);
                return CampaignContent::forCampaign($id);
            case 'content_save':
                Campaign::assertUnlocked(Campaign::findOrFail($id));
                $json = $this->request->get('editor_json');
                return CampaignContent::saveBody($id, (string) $this->request->get('html', ''), is_array($json) ? $json : null);
            case 'schedule':
                return Campaign::schedule($id);
            case 'conflicts':
                $date = Model::parseDate((string) $this->request->query('date', ''));
                if ($date === null) {
                    throw new HttpException('A valid date is required', 422);
                }
                return (new ScheduleConflicts($this->db))->forDate($date, $id);
            case 'template_get':
                $template = Template::findOrFail((int) $this->request->query('template', 0));
                return ['html' => $template['html'] ?? '', 'editor_json' => $template['editor_json'] ?? null];
            case 'send_test':
                return $this->sendTest($id);
            case 'sender_create':
                return $this->createSender();
        }
        $this->unknown();
    }

    /**
     * A new pending sender (same rules as Settings > Senders). SES is then
     * asked to email the address a verification link; when SES is not
     * configured or refuses, the sender is still created and `verification`
     * says why, so it can be verified later from Settings > Senders.
     *
     * @return array{sender: array<string, mixed>, verification: string}
     */
    private function createSender(): array
    {
        $data = $this->validator->validate($this->request->only(['from_name', 'email']) + $this->stamp(), [
            'from_name'       => ['required', 'string', 'max:255'],
            'email'           => ['required', 'email', 'max:255', 'unique:edm_senders,email'],
            'created_by'      => ['nullable', 'integer'],
            'created_by_name' => ['nullable', 'string', 'max:150'],
        ]);
        $sender = Sender::create(['status' => 1] + $data); // 1 = pending

        try {
            $result = (new IdentitySync(SesGateway::fromEnv()))->requestSender((int) $sender['id']);
            $verification = (int) ($result['sender']['status'] ?? 1) === 2
                ? 'Already verified in Amazon SES - ready to send.'
                : 'Amazon SES has emailed ' . $sender['email'] . ' a verification link. It can send once the link is clicked.';
        } catch (SesException $e) {
            $verification = 'Verification was not requested: ' . $e->getMessage() . ' Ask a superadmin to verify it in Settings > Senders.';
        }

        return ['sender' => Sender::findOrFail((int) $sender['id']), 'verification' => $verification];
    }

    /**
     * One test email of the saved design through SES, subject prefixed
     * "[Test]", built like the real send (CampaignMessage: preview text,
     * UTM, tracking). Not logged, not counted against the frequency caps.
     *
     * @return array{message_id: string, to: string}
     */
    private function sendTest(int $id): array
    {
        $to = $this->validator->validate($this->request->only(['to']), ['to' => ['required', 'email', 'max:255']])['to'];
        $campaign = Campaign::findOrFail($id);
        $sender = $campaign['sender_id'] !== null ? Sender::find((int) $campaign['sender_id']) : null;
        if ($sender === null) {
            throw new HttpException('Choose a sender and save before sending a test.', 422);
        }
        $html = (string) (CampaignContent::forCampaign($id)['html'] ?? '');
        if (trim($html) === '') {
            throw new HttpException('The design is empty - save a design before sending a test.', 422);
        }

        $ses = SesGateway::fromEnv();
        $vars = [
            'email'           => $to,
            // A real signed link when configured, so the footer can be tried out.
            'unsubscribe_url' => $ses->config->missingForSending() === []
                ? (new Unsubscribe($ses->config))->url($id, $to)
                : '#',
        ];
        $messageId = $ses->send(
            $sender['email'],
            $sender['from_name'],
            $to,
            '[Test] ' . MessageRenderer::subject((string) ($campaign['subject'] ?? ''), $vars),
            MessageRenderer::html(CampaignMessage::html($campaign, $html), $vars),
            Campaign::replyTo($campaign),
            [],
            [],
            CampaignMessage::configurationSet($campaign, $ses->config)
        );

        return ['message_id' => $messageId, 'to' => $to];
    }
}
