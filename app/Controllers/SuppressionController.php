<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Models\Suppression;

/**
 * Suppression Centre (suppression/). Actions:
 *   suppressions_(list|create|update|delete); list accepts ?reason= and ?q=.
 */
final class SuppressionController extends Controller
{
    protected function handle(string $action): mixed
    {
        if (!preg_match('/^suppressions_(list|create|update|delete)$/', $action, $m)) {
            $this->unknown();
        }
        if ($m[1] === 'list') {
            return $this->listing();
        }

        $reasons = 'in:' . implode(',', Suppression::REASONS);
        $payload = $this->request->only(['email', 'reason', 'note']);
        if ($m[1] === 'create') {
            // Added from this screen = manual source.
            $payload += $this->stamp() + ['source' => 'manual'];
        }

        return $this->crud($m[1], Suppression::class, $payload, [
            'email'           => ['required', 'email', 'max:255', 'unique:edm_suppressions,email'],
            'reason'          => ['required', $reasons],
            'source'          => ['nullable', 'string', 'max:255'],
            'note'            => ['nullable', 'string', 'max:255'],
            'created_by'      => ['nullable', 'integer'],
            'created_by_name' => ['nullable', 'string', 'max:150'],
        ], [
            'reason' => ['sometimes', $reasons],
            'note'   => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function listing(): array
    {
        $where = '1 = 1';
        $params = [];
        $reason = (string) $this->request->query('reason', '');
        if ($reason !== '') {
            $where .= ' AND `reason` = ?';
            $params[] = $reason;
        }
        $q = trim((string) $this->request->query('q', ''));
        if ($q !== '') {
            $where .= ' AND `email` LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }

        return Suppression::where($where, $params, null, 500);
    }
}
