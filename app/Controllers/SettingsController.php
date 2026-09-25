<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\HttpException;
use Edm\Core\ValidationException;
use Edm\Models\Sender;
use Edm\Models\SendingDomain;
use Edm\Models\Setting;

/**
 * Settings (settings/). Actions:
 *   senders_(list|create|update|delete), senders_verify
 *   domains_(list|create|update|delete)
 *   (integrations|general)_(list|create|update|delete)   grouped key/value
 *   users_list, users_update                             staff.edm tier (superadmin only)
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
        if ($action === 'users_list' || $action === 'users_update') {
            return $this->users($action);
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

    /** staff.edm lives in odb's staff table, not an edm_* table. Superadmin only. */
    private function users(string $action): array
    {
        if (!$this->auth->isSuperadmin) {
            throw new HttpException('Superadmin only.', 403);
        }
        if ($action === 'users_list') {
            return array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'nama_staff' => $r['nama_staff'],
                'edm' => (int) $r['edm'],
            ], $this->db->select('SELECT id, nama_staff, edm FROM staff WHERE edm > 0 ORDER BY nama_staff'));
        }

        $id = $this->requireId('Staff');
        $tier = (int) $this->request->get('edm', -1);
        if ($tier < 0 || $tier > 4) {
            throw new HttpException('A staff id and a tier 0-4 are required', 422);
        }
        $this->db->execute('UPDATE staff SET edm = ? WHERE id = ?', [$tier, $id]);

        return ['id' => $id, 'edm' => $tier];
    }
}
