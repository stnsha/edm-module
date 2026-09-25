<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Models\Approval;
use Edm\Models\Campaign;

/**
 * Approval Centre (approval/). Actions:
 *   approvals_list | approvals_create | approvals_delete
 *   approvals_decide   approve (2) / reject (3); a rejection sends the
 *                      newsletter back to content revision (4)
 */
final class ApprovalController extends Controller
{
    protected function handle(string $action): mixed
    {
        switch ($action) {
            case 'approvals_list':
                return $this->listing();
            case 'approvals_create':
                $payload = $this->request->ids(['campaign_id', 'step']) + $this->request->only(['comment']);
                $data = $this->validator->validate($payload, [
                    'campaign_id' => ['required', 'integer', 'exists:edm_campaigns,id'],
                    'step'        => ['required', 'integer', 'min:1', 'max:8'],
                    'comment'     => ['nullable', 'string', 'max:1000'],
                ]);
                return Approval::create(['status' => 1] + $data); // 1 = pending
            case 'approvals_delete':
                Approval::delete($this->requireId('Approval'));
                return null;
            case 'approvals_decide':
                return $this->decide();
        }
        $this->unknown();
    }

    /** Each approval plus campaign_name / campaign_status. */
    private function listing(): array
    {
        $status = $this->request->query('status');
        $params = [];
        $sql = 'SELECT a.*, c.`name` AS campaign_name, c.`status` AS campaign_status
                FROM `edm_approvals` a
                LEFT JOIN `edm_campaigns` c ON c.`id` = a.`campaign_id` AND c.`deleted_at` IS NULL
                WHERE a.`deleted_at` IS NULL';
        if ($status !== null && $status !== '') {
            $sql .= ' AND a.`status` = ?';
            $params[] = (int) $status;
        }
        $sql .= ' ORDER BY a.`created_at` DESC, a.`id` DESC';

        return array_map(static function (array $row): array {
            $row = Approval::present($row);
            $row['campaign_status'] = $row['campaign_status'] === null ? null : (int) $row['campaign_status'];

            return $row;
        }, $this->db->select($sql, $params));
    }

    private function decide(): array
    {
        $id = $this->requireId('Approval');
        $approval = Approval::findOrFail($id);
        $data = $this->validator->validate($this->request->ids(['status']) + $this->request->only(['comment']), [
            // A decision is approved (2) or rejected (3); pending (1) is not a decision.
            'status'  => ['required', 'integer', 'in:2,3'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['reviewer_id'] = $this->auth->staffId;
        $data['reviewer_name'] = $this->auth->staffName;

        return $this->db->transaction(static function () use ($id, $approval, $data): array {
            $updated = Approval::update($id, $data);
            if ($data['status'] === 3 && Campaign::find((int) $approval['campaign_id']) !== null) {
                Campaign::update((int) $approval['campaign_id'], ['status' => Campaign::CONTENT_REVISION]);
            }

            return $updated;
        });
    }
}
