<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\HttpException;
use Edm\Core\ValidationException;
use Edm\Models\Sender;
use Edm\Models\SendingDomain;
use Edm\Models\Setting;
use Edm\Services\Ses\IdentitySync;
use Edm\Services\Ses\SesException;
use Edm\Services\Ses\SesGateway;

/**
 * Settings (settings/). Actions:
 *   senders_(list|create|update|delete), senders_verify
 *   domains_(list|create|update|delete)
 *   (integrations|general)_(list|create|update|delete)   grouped key/value
 *   users_list, users_search, users_save, users_delete   staff.edm role (superadmin only)
 *   ses_status, ses_sender_check, ses_sender_request, ses_domain_check   Amazon SES
 */
final class SettingsController extends Controller
{
    private const STATUS_RULE = ['sometimes', 'integer', 'in:1,2,3'];

    protected function handle(string $action): mixed
    {
        if (preg_match('/^senders_(list|create|update|delete)$/', $action, $m)) {
            return $this->senders($m[1]);
        }
        if ($action === 'senders_verify') {
            return $this->verifySender();
        }
        if (preg_match('/^domains_(list|create|update|delete)$/', $action, $m)) {
            return $this->domains($m[1]);
        }
        if (preg_match('/^(integrations|general)_(list|create|update|delete)$/', $action, $m)) {
            return $this->settings($m[1], $m[2]);
        }
        if (in_array($action, ['users_list', 'users_search', 'users_save', 'users_delete'], true)) {
            return $this->users($action);
        }
        if (str_starts_with($action, 'ses_')) {
            return $this->ses($action);
        }
        $this->unknown();
    }

    /**
     * Amazon SES (settings tier only):
     *   ses_status          .env summary + live account state (Integrations page)
     *   ses_sender_check    sender status from SES (id)
     *   ses_sender_request  send the AWS verification email to a sender (id)
     *   ses_domain_check    register / check a domain, returns DNS records (id)
     */
    private function ses(string $action): array
    {
        if (!$this->auth->isSuperadmin && $this->auth->permission !== 1) {
            throw new HttpException('Only EDM superadmins can manage Amazon SES.', 403);
        }
        $gateway = SesGateway::fromEnv();

        switch ($action) {
            case 'ses_status':
                $c = $gateway->config;
                $out = [
                    'config' => [
                        'region'            => $c->region,
                        'credentials'       => $c->hasStaticCredentials() ? 'Access key ' . substr((string) $c->accessKeyId, 0, 4) . '...' . substr((string) $c->accessKeyId, -4) : 'AWS default credential chain',
                        'configuration_set' => $c->configurationSet,
                        'sns_topic_arn'     => $c->snsTopicArn,
                        'public_url'        => $c->publicUrl,
                        'missing'           => $c->missingForSending(),
                    ],
                    'account' => null,
                    'error'   => null,
                ];
                try {
                    $out['account'] = $gateway->account();
                } catch (SesException $e) {
                    $out['error'] = $e->getMessage();
                }
                return $out;
            case 'ses_sender_check':
                return (new IdentitySync($gateway))->checkSender($this->requireId('Sender'));
            case 'ses_sender_request':
                return (new IdentitySync($gateway))->requestSender($this->requireId('Sender'));
            case 'ses_domain_check':
                return (new IdentitySync($gateway))->checkDomain($this->requireId('Domain'));
        }
        $this->unknown();
    }

    private function senders(string $verb): mixed
    {
        $payload = $this->request->only(['email', 'from_name', 'reply_to']);
        if ($this->request->has('is_default')) {
            $payload['is_default'] = !empty($this->request->get('is_default'));
        }

        switch ($verb) {
            case 'list':
                return Sender::all();
            case 'create':
                $data = $this->validator->validate($payload + $this->stamp(), [
                    'email'           => ['required', 'email', 'max:255', 'unique:edm_senders,email'],
                    'from_name'       => ['required', 'string', 'max:255'],
                    'reply_to'        => ['nullable', 'email', 'max:255'],
                    'is_default'      => ['sometimes', 'boolean'],
                    'created_by'      => ['nullable', 'integer'],
                    'created_by_name' => ['nullable', 'string', 'max:150'],
                ]);
                $sender = Sender::create(['status' => 1] + $data); // 1 = pending
                if (!empty($data['is_default']) || count(Sender::all()) === 1) {
                    $this->promoteDefault((int) $sender['id']);
                }
                return Sender::findOrFail((int) $sender['id']);
            case 'update':
                $id = $this->requireId('Sender');
                $current = Sender::findOrFail($id);
                $data = $this->validator->validate($payload, [
                    'email'      => ['sometimes', 'email', 'max:255', 'unique:edm_senders,email'],
                    'from_name'  => ['sometimes', 'string', 'max:255'],
                    'reply_to'   => ['nullable', 'email', 'max:255'],
                    'is_default' => ['sometimes', 'boolean'],
                ], $id);
                // Changing the address re-triggers verification once SES is wired.
                if (isset($data['email']) && $data['email'] !== $current['email']) {
                    $data['status'] = 1;
                    $data['verified_at'] = null;
                }
                Sender::update($id, $data);
                if (!empty($data['is_default'])) {
                    $this->promoteDefault($id);
                }
                return Sender::findOrFail($id);
            case 'delete':
                $id = $this->requireId('Sender');
                $wasDefault = Sender::findOrFail($id)['is_default'];
                Sender::delete($id);
                if ($wasDefault) {
                    // Prefer a verified sender (2) over pending / failed as the replacement.
                    $next = Sender::where('1 = 1', [], '(`status` = 2) DESC, `from_name` ASC', 1);
                    if ($next !== []) {
                        Sender::update((int) $next[0]['id'], ['is_default' => true]);
                    }
                }
                return null;
        }
        $this->unknown();
    }

    /** Manual status set until SES GetIdentityVerificationAttributes is wired. */
    private function verifySender(): array
    {
        $id = $this->requireId('Sender');
        $data = $this->validator->validate($this->request->input, ['status' => ['required', 'integer', 'in:1,2,3']]);
        Sender::findOrFail($id);

        return Sender::update($id, [
            'status'      => $data['status'],
            'verified_at' => $data['status'] === 2 ? date('Y-m-d H:i:s') : null,
        ]);
    }

    /** Make $id the only default sender. */
    private function promoteDefault(int $id): void
    {
        $this->db->execute(
            'UPDATE `edm_senders` SET `is_default` = 0, `updated_at` = ? WHERE `id` != ? AND `is_default` = 1 AND `deleted_at` IS NULL',
            [date('Y-m-d H:i:s'), $id]
        );
        Sender::update($id, ['is_default' => true]);
    }

    private function domains(string $verb): mixed
    {
        $payload = $this->request->only(['domain', 'dkim_status', 'spf_status', 'dmarc_status']);
        if ($this->request->has('is_active')) {
            $payload['is_active'] = !empty($this->request->get('is_active'));
        }
        $rules = [
            'domain'       => ['required', 'string', 'max:255', 'unique:edm_sending_domains,domain'],
            'dkim_status'  => self::STATUS_RULE,
            'spf_status'   => self::STATUS_RULE,
            'dmarc_status' => self::STATUS_RULE,
            'is_active'    => ['sometimes', 'boolean'],
        ];
        $update = ['domain' => ['sometimes', 'string', 'max:255', 'unique:edm_sending_domains,domain']] + $rules;

        return $this->crud($verb, SendingDomain::class, $payload, $rules, $update);
    }

    private function settings(string $group, string $verb): mixed
    {
        switch ($verb) {
            case 'list':
                return Setting::where('`group` = ?', [$group]);
            case 'create':
                $data = $this->validator->validate($this->request->only(['key', 'label', 'value']) + ['group' => $group], [
                    'group' => ['required', 'string', 'max:255'],
                    'key'   => ['required', 'string', 'max:255'],
                    'value' => ['nullable', 'string'],
                    'label' => ['nullable', 'string', 'max:255'],
                ]);
                if (Setting::exists(['group' => $group, 'key' => $data['key']])) {
                    throw ValidationException::single('key', 'The key has already been taken.');
                }
                return Setting::create($data);
        }

        return $this->crud($verb, Setting::class, $this->request->only(['label', 'value']), [
            'value' => ['nullable', 'string'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * Users & permissions (settings/users.php, laid out like atem's Access
     * Control). staff.edm lives in odb's staff table, not an edm_* table.
     * Superadmin only.
     *   users_list    staff with an EDM role (edm 1-4)
     *   users_search  ?q= active staff by name (20), for "Add access"
     *   users_save    { id, edm 1-4 } grant or change a role
     *   users_delete  { id } remove access (edm = 0)
     * Nobody can change or remove their own role, so the last superadmin
     * cannot lock everyone out by accident.
     */
    private function users(string $action): mixed
    {
        if (!$this->auth->isSuperadmin) {
            throw new HttpException('Superadmin only.', 403);
        }

        switch ($action) {
            case 'users_list':
                return $this->staffRows('s.`edm` > 0', [], 's.`edm` ASC, s.`nama_staff` ASC', null);
            case 'users_search':
                $q = trim((string) $this->request->query('q', ''));
                if (mb_strlen($q) < 2) {
                    return [];
                }
                $like = '%' . addcslashes($q, '\\%_') . '%';
                return $this->staffRows('s.`nama_staff` LIKE ?', [$like], 's.`nama_staff` ASC', 20);
            case 'users_save':
                $id = $this->requireId('Staff');
                $role = (int) $this->request->get('edm', 0);
                if ($role < 1 || $role > 4) {
                    throw ValidationException::single('edm', 'Choose a role.');
                }
                $this->guardStaff($id);
                $this->db->execute('UPDATE `staff` SET `edm` = ? WHERE `id` = ? AND `recycle` != 1', [$role, $id]);
                return $this->staffRows('s.`id` = ?', [$id], 's.`id`', 1)[0];
            default: // users_delete
                $id = $this->requireId('Staff');
                $this->guardStaff($id);
                $this->db->execute('UPDATE `staff` SET `edm` = 0 WHERE `id` = ?', [$id]);
                return ['id' => $id, 'edm' => 0];
        }
    }

    /** Active staff member, and not the signed-in user. */
    private function guardStaff(int $id): void
    {
        if ($id === $this->auth->staffId) {
            throw new HttpException('You cannot change your own access - ask another superadmin.', 422);
        }
        if ($this->db->scalar('SELECT `id` FROM `staff` WHERE `id` = ? AND `recycle` != 1', [$id]) === null) {
            throw new HttpException('Staff member not found or no longer active.', 404);
        }
    }

    /**
     * Staff rows for the page: name, department, status and EDM role. Only
     * active staff (recycle != 1).
     *
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function staffRows(string $where, array $params, string $order, ?int $limit): array
    {
        $rows = $this->db->select(
            'SELECT s.`id`, s.`nama_staff`, s.`edm`, s.`status_semasa`, d.`depart_name`
               FROM `staff` s
               LEFT JOIN `staff_department` d ON d.`id` = s.`department`
              WHERE s.`recycle` != 1 AND ' . $where . '
              ORDER BY ' . $order . ($limit !== null ? ' LIMIT ' . $limit : ''),
            $params
        );

        return array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'nama_staff' => (string) $r['nama_staff'],
            'department' => $r['depart_name'] !== null ? (string) $r['depart_name'] : '',
            'status'     => (string) ($r['status_semasa'] ?? ''),
            'edm'        => (int) $r['edm'],
        ], $rows);
    }
}
