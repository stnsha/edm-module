<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\HttpException;
use Edm\Core\Model;
use Edm\Models\Campaign;
use Edm\Models\CampaignContent;
use Edm\Models\Sender;
use Edm\Models\Template;
use Edm\Services\ScheduleConflicts;
use Edm\Services\Ses\MessageRenderer;
use Edm\Services\Ses\SesGateway;
use Edm\Services\Ses\Unsubscribe;

/**
 * Email creator (email-builder/). The campaign id comes from ?campaign= or
 * the body's `campaign`. Actions:
 *   campaigns_list  picker for the bare landing page
 *   load            newsletter + content + list
 *   settings_save   the settings panel above the builder
 *   content_get     html + editor_json
 *   content_save    new body version (html + EmailBuilder.js editor_json)
 *   conflicts       calendar conflicts for ?date= (Scheduled send warning)
 *   template_get    a template's html + editor_json ("Start from template";
 *                   loaded into the editor only, saved by the next Save)
 *   submit          draft / revision -> pending submission
 *   send_test       { to } one "[Test]" email of the saved design via SES
 */
final class EmailBuilderController extends Controller
{
    private const SETTINGS_RULES = [
        'name'         => ['sometimes', 'string', 'max:255'],
        'subject'      => ['sometimes', 'required', 'string', 'max:255'],
        'sender_id'    => ['sometimes', 'required', 'integer', 'exists:edm_senders,id'],
        'list_id'      => ['sometimes', 'required', 'integer', 'exists:edm_lists,id'],
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
        }
        $this->unknown();
    }

    /**
     * One test email of the saved design through SES, subject prefixed
     * "[Test]". Not logged, not counted against the frequency caps.
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
            'member_code'     => 'TEST0001',
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
            MessageRenderer::html($html, $vars),
            $sender['reply_to']
        );

        return ['message_id' => $messageId, 'to' => $to];
    }
}
