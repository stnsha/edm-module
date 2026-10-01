<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\HttpException;
use Edm\Core\ValidationException;
use Edm\Models\Approval;
use Edm\Models\ApprovalDecision;
use Edm\Models\ApprovalFile;
use Edm\Models\ApprovalLog;
use Edm\Models\CampaignContent;
use Edm\Models\CampaignQa;
use Edm\Core\Model;
use Edm\Services\Qa\QaQueue;
use Edm\Services\Qa\UtmTagger;
use Edm\Models\Campaign;
use Edm\Services\RichText;
use Edm\Services\ScheduleReadiness;
use finfo;
use Throwable;

/**
 * Approval Centre (approval/). Actions:
 *   approvals_list     every request / step, with campaign name and artwork count
 *   approvals_get      ?id= one request with its artwork and can_edit
 *   approvals_save     multipart: id?, title, campaign_id, objective,
 *                      audience_brief, copywriting (Quill HTML), files[]
 *                      (artwork), remove_files (comma-separated ids).
 *                      Raising a review (spec 5.2 step 1): step 1, pending,
 *                      requested by the signed-in staff member. A pending
 *                      request can be edited by its requester or a superadmin.
 *   approvals_delete
 *   approvals_decide   { id, stage: bpt|audience|final, decision: 2|3, comment,
 *                      checks[], scheduled_at (final) } - see decide():
 *                      bpt (steps 2-3, BPT team): every BPT_CHECKS item ->
 *                      step 4, campaign audience validation, QA queued;
 *                      audience (step 4, BI/CRM = admin): every
 *                      AUDIENCE_CHECKS item -> step 6; final (step 6, BPT
 *                      team): ScheduleReadiness passes -> approved, campaign
 *                      scheduled at scheduled_at and locked. Reject needs a
 *                      comment, returns the request to the requester
 *                      (editable again - saving it resubmits to BPT) and the
 *                      campaign to content revision. A superadmin can decide
 *                      every stage.
 *
 * Campaign status follows the request (spec 5.1): raising or resubmitting a
 * review -> under BPT review (3); deleting a request still with BPT -> back
 * to pending submission (2). One open request per campaign.
 *
 *   qa_status          ?id= latest automated QA run of the review's campaign;
 *                      a queued run is worked here when no cron did it
 *                      within 5 seconds (local development)
 *   qa_run             { id } queue a QA run now ("Run again")
 *   qa_utm             { id } add UTM tags to every link of the campaign
 *                      design (html + editor_json, new content version),
 *                      then queue a QA run
 *
 * BPT approval queues an automated QA run (Services\Qa\QaQueue).
 *
 * Every change is written to edm_approval_logs (Activity Log on the page):
 * created, updated (field-by-field), attachment_added / _removed,
 * approved / rejected.
 *
 * Artwork is stored in edm/uploads/approvals/ under a random name (images or
 * PDF, 10 MB each, content-sniffed), served as static files.
 */
final class ApprovalController extends Controller
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_FILES = 10;

    /** Allowed artwork MIME types (sniffed from content) => stored extension. */
    private const TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    private const UPLOAD_DIR = 'uploads/approvals';

    protected function handle(string $action): mixed
    {
        switch ($action) {
            case 'approvals_list':
                return $this->listing();
            case 'approvals_get':
                return $this->detail($this->requireId('Review'));
            case 'approvals_save':
                return $this->save();
            case 'approvals_delete':
                $id = $this->requireId('Review');
                $this->assertCanEdit(Approval::findOrFail($id));
                $approval = Approval::findOrFail($id);
                foreach (ApprovalFile::where('`approval_id` = ?', [$id]) as $file) {
                    $this->removeFile($file);
                }
                Approval::delete($id);
                $this->releaseCampaign((int) $approval['campaign_id']);
                return null;
            case 'approvals_decide':
                return $this->decide();
            case 'qa_status':
                return $this->qaStatus();
            case 'qa_run':
                return $this->qaRun();
            case 'qa_utm':
                return $this->qaUtm();
        }
        $this->unknown();
    }

    /** Each approval plus campaign_name / campaign_status / files_count. */
    private function listing(): array
    {
        $status = $this->request->query('status');
        $params = [];
        $sql = 'SELECT a.`id`, a.`campaign_id`, a.`title`, a.`requested_by`, a.`requested_by_name`, a.`step`, a.`status`,
                       a.`reviewer_id`, a.`reviewer_name`, a.`comment`, a.`created_at`, a.`updated_at`, a.`deleted_at`,
                       c.`name` AS campaign_name, c.`status` AS campaign_status,
                       (SELECT COUNT(*) FROM `edm_approval_files` f WHERE f.`approval_id` = a.`id` AND f.`deleted_at` IS NULL) AS files_count,
                       (SELECT q.`status` FROM `edm_campaign_qa` q WHERE q.`campaign_id` = a.`campaign_id` AND q.`deleted_at` IS NULL
                         ORDER BY q.`id` DESC LIMIT 1) AS qa_status
                FROM `edm_approvals` a
                LEFT JOIN `edm_campaigns` c ON c.`id` = a.`campaign_id` AND c.`deleted_at` IS NULL
                WHERE a.`deleted_at` IS NULL';
        if ($status !== null && $status !== '') {
            $sql .= ' AND a.`status` = ?';
            $params[] = (int) $status;
        }
        $sql .= ' ORDER BY a.`created_at` DESC, a.`id` DESC';

        return array_map(function (array $row): array {
            $row = Approval::present($row);
            $row['campaign_status'] = $row['campaign_status'] === null ? null : (int) $row['campaign_status'];
            $row['files_count'] = (int) $row['files_count'];
            $row['qa_status'] = $row['qa_status'] === null ? null : (int) $row['qa_status'];
            $row['can_edit'] = $this->canEdit($row);

            return $row;
        }, $this->db->select($sql, $params));
    }

    /** @return array<string, mixed> */
    private function detail(int $id): array
    {
        $row = Approval::findOrFail($id);
        $row['files'] = ApprovalFile::where('`approval_id` = ?', [$id]);
        $row['logs'] = ApprovalLog::where('`approval_id` = ?', [$id]);
        $row['can_edit'] = $this->canEdit($row);

        return $row;
    }

    /** Raise (no id) or update a review request. */
    private function save(): array
    {
        $id = $this->request->id();
        $existing = $id > 0 ? Approval::findOrFail($id) : null;
        if ($existing !== null) {
            $this->assertCanEdit($existing);
        }

        $payload = $this->request->only(['title']) + $this->request->ids(['campaign_id']);
        foreach (['objective', 'audience_brief', 'copywriting'] as $field) {
            $payload[$field] = RichText::clean((string) $this->request->get($field, ''));
        }
        $data = $this->validator->validate($payload, [
            'title'          => ['required', 'string', 'max:255'],
            'campaign_id'    => ['required', 'integer', 'exists:edm_campaigns,id'],
            'objective'      => ['nullable', 'string'],
            'audience_brief' => ['nullable', 'string'],
            'copywriting'    => ['nullable', 'string'],
        ]);
        $labels = ['objective' => 'Objective', 'audience_brief' => 'Audience brief', 'copywriting' => 'Copywriting'];
        $errors = [];
        foreach ($labels as $field => $label) {
            if (RichText::isEmpty($data[$field] ?? '')) {
                $errors[$field] = [$label . ' is required.'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // A campaign goes to review once at a time, and only while it is still being worked on.
        $campaign = Campaign::findOrFail((int) $data['campaign_id']);
        $changingCampaign = $existing === null || (int) $existing['campaign_id'] !== (int) $data['campaign_id'];
        if ($changingCampaign) {
            if (!in_array($campaign['status'], Campaign::REVIEWABLE, true)) {
                throw ValidationException::single('campaign_id', 'Only a draft, pending submission or content revision campaign can go to review.');
            }
            $open = Approval::openFor((int) $campaign['id'], $id);
            if ($open !== null) {
                throw ValidationException::single('campaign_id', 'This campaign already has an open review (#RV' . $open['id'] . ').');
            }
        }
        $resubmit = $existing !== null && (int) $existing['status'] === Approval::REJECTED;

        $uploads = $this->uploadedFiles();
        $remove = array_filter(array_map('intval', explode(',', (string) $this->request->get('remove_files', ''))));
        $kept = $existing !== null
            ? count(array_filter(ApprovalFile::where('`approval_id` = ?', [$id]), static fn (array $f): bool => !in_array((int) $f['id'], $remove, true)))
            : 0;
        if ($kept + count($uploads) > self::MAX_FILES) {
            throw ValidationException::single('files', 'Attach up to ' . self::MAX_FILES . ' artwork files.');
        }
        // Check every file before anything is written.
        $checked = array_map(fn (array $f): array => $this->checkFile($f), $uploads);

        $stored = [];
        try {
            $saved = $this->db->transaction(function () use ($existing, $id, $data, $checked, $remove, $campaign, $changingCampaign, $resubmit, &$stored): array {
                if ($existing === null) {
                    $row = Approval::create($data + [
                        'step'              => Approval::STEP_BPT,
                        'status'            => Approval::PENDING,
                        'requested_by'      => $this->auth->staffId,
                        'requested_by_name' => $this->auth->staffName,
                    ]);
                    $approvalId = (int) $row['id'];
                    $campaign = Campaign::find((int) $data['campaign_id']);
                    $this->log($approvalId, 'created', 'Review raised for "' . ($campaign['name'] ?? 'campaign') . '".');
                } else {
                    // A rejected request that is edited goes back to the BPT team.
                    Approval::update($id, $data + ($resubmit ? ['status' => Approval::PENDING, 'step' => Approval::STEP_BPT] : []));
                    $approvalId = $id;
                    if ($resubmit) {
                        $this->log($approvalId, 'resubmitted', 'Resubmitted to the BPT team after changes.');
                    }
                    $changes = $this->changes($existing, $data);
                    if ($changes !== []) {
                        $this->log($approvalId, 'updated', null, $changes);
                    }
                    $removedNames = array_column(array_filter(
                        ApprovalFile::where('`approval_id` = ?', [$id]),
                        static fn (array $f): bool => in_array((int) $f['id'], $remove, true)
                    ), 'name');
                    if ($removedNames !== []) {
                        $this->log($approvalId, 'attachment_removed', 'Removed artwork: ' . implode(', ', $removedNames) . '.');
                    }
                }
                foreach ($checked as $file) {
                    $name = bin2hex(random_bytes(16)) . '.' . $file['ext'];
                    $this->storeFile($file['tmp'], $name);
                    $stored[] = $name;
                    ApprovalFile::create([
                        'approval_id'      => $approvalId,
                        'name'             => $file['name'],
                        'url'              => $this->publicUrl($name),
                        'mime'             => $file['mime'],
                        'size_bytes'       => $file['size'],
                        'uploaded_by'      => $this->auth->staffId,
                        'uploaded_by_name' => $this->auth->staffName,
                    ]);
                }

                if ($checked !== []) {
                    $this->log($approvalId, 'attachment_added', 'Added artwork: ' . implode(', ', array_column($checked, 'name')) . '.');
                }

                // Campaign status follows the request (spec 5.1): with BPT = under BPT review.
                if (($changingCampaign || $resubmit) && in_array($campaign['status'], Campaign::REVIEWABLE, true)) {
                    Campaign::update((int) $campaign['id'], ['status' => Campaign::UNDER_BPT_REVIEW]);
                }
                if ($existing !== null && $changingCampaign) {
                    $this->releaseCampaign((int) $existing['campaign_id']);
                }

                return ['id' => $approvalId];
            });
        } catch (Throwable $e) {
            // Roll back files written before the failure.
            foreach ($stored as $name) {
                @unlink($this->uploadDir() . '/' . $name);
            }
            throw $e;
        }

        // Removed artwork goes only once the request itself is saved.
        if ($existing !== null && $remove !== []) {
            foreach (ApprovalFile::where('`approval_id` = ?', [$id]) as $file) {
                if (in_array((int) $file['id'], $remove, true)) {
                    $this->removeFile($file);
                }
            }
        }

        return $this->detail($saved['id']);
    }

    /**
     * A stage decision (spec 5.2): bpt (steps 2-3), audience (step 4, BI/CRM)
     * or final (step 6). Approving moves the request on - bpt -> audience
     * (campaign: audience validation, QA queued), audience -> final, final ->
     * approved with the campaign scheduled at scheduled_at and locked.
     * Rejecting (comment required) returns the request to the requester and
     * the campaign to content revision.
     */
    private function decide(): array
    {
        $id = $this->requireId('Review');
        $approval = Approval::findOrFail($id);
        $stage = (string) $this->request->get('stage', '');
        if (!in_array($stage, ApprovalDecision::STAGES, true)) {
            throw ValidationException::single('stage', 'Unknown review stage.');
        }
        $owner = Approval::STAGE_OWNERS[$stage];
        if (!Approval::canDecide($this->auth, $stage)) {
            throw new HttpException('Only ' . $owner . ' (or a superadmin) can make this decision.', 403);
        }
        if (Approval::pendingStage($approval) !== $stage) {
            throw new HttpException('This request is not waiting for this decision.', 422);
        }
        $data = $this->validator->validate($this->request->ids(['decision']) + $this->request->only(['comment', 'scheduled_at']), [
            'decision'     => ['required', 'integer', 'in:2,3'],
            'comment'      => ['nullable', 'string', 'max:1000'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
        $approve = $data['decision'] === Approval::APPROVED;
        $comment = trim((string) ($data['comment'] ?? ''));
        $list = match ($stage) {
            ApprovalDecision::STAGE_BPT      => Approval::BPT_CHECKS,
            ApprovalDecision::STAGE_AUDIENCE => Approval::AUDIENCE_CHECKS,
            default                          => [],
        };
        $checks = array_values(array_intersect(array_keys($list), array_map('strval', (array) $this->request->get('checks', []))));
        if ($approve && count($checks) !== count($list)) {
            throw ValidationException::single('checks', 'Tick every item on the checklist before approving.');
        }
        if (!$approve && $comment === '') {
            throw ValidationException::single('comment', 'State the reason for rejection.');
        }

        $campaignId = (int) $approval['campaign_id'];
        $campaign = Campaign::findOrFail($campaignId);
        $sendAt = null;
        if ($approve && $stage === ApprovalDecision::STAGE_FINAL) {
            $sendAt = Model::parseDate((string) ($data['scheduled_at'] ?? ''));
            $problems = (new ScheduleReadiness())->problems($campaign, $sendAt);
            if ($problems !== []) {
                throw ValidationException::single('final', 'Cannot schedule yet: ' . implode(' ', $problems));
            }
        }

        $this->db->transaction(function () use ($id, $stage, $approve, $comment, $checks, $campaign, $campaignId, $sendAt): void {
            ApprovalDecision::create([
                'approval_id'     => $id,
                'stage'           => $stage,
                'decision'        => $approve ? Approval::APPROVED : Approval::REJECTED,
                'checks'          => $checks,
                'comment'         => $comment !== '' ? $comment : null,
                'decided_by'      => $this->auth->staffId,
                'decided_by_name' => $this->auth->staffName,
            ]);
            $next = match (true) {
                !$approve                                 => ['status' => Approval::REJECTED],
                $stage === ApprovalDecision::STAGE_BPT      => ['step' => Approval::STEP_AUDIENCE],
                $stage === ApprovalDecision::STAGE_AUDIENCE => ['step' => Approval::STEP_FINAL],
                default                                   => ['status' => Approval::APPROVED],
            };
            Approval::update($id, [
                'reviewer_id'   => $this->auth->staffId,
                'reviewer_name' => $this->auth->staffName,
                'comment'       => $comment !== '' ? $comment : null,
            ] + $next);

            $label = ['bpt' => 'BPT review', 'audience' => 'Audience validation', 'final' => 'Final approval'][$stage];
            $summary = match (true) {
                !$approve                                 => $label . ' rejected - returned to the requester, campaign back to content revision',
                $stage === ApprovalDecision::STAGE_BPT      => 'BPT review approved - sent to BI/CRM for audience validation',
                $stage === ApprovalDecision::STAGE_AUDIENCE => 'Audience validated - ready for final approval',
                default                                   => 'Final approval given - campaign scheduled for ' . $sendAt?->format('d-m-Y H:i') . ' and locked',
            };
            $this->log($id, $stage . ($approve ? '_approved' : '_rejected'), $summary . ($comment !== '' ? '. Comment: ' . $comment : '.'));

            // A campaign already scheduled or sent is left alone.
            if (!Campaign::isLocked($campaign)) {
                if (!$approve) {
                    Campaign::update($campaignId, ['status' => Campaign::CONTENT_REVISION]);
                } elseif ($stage === ApprovalDecision::STAGE_BPT) {
                    Campaign::update($campaignId, ['status' => Campaign::AUDIENCE_VALIDATION]);
                } elseif ($stage === ApprovalDecision::STAGE_FINAL) {
                    Campaign::update($campaignId, ['status' => Campaign::SCHEDULED, 'scheduled_at' => $sendAt?->format('Y-m-d H:i:s')]);
                }
            }
            // Step 5: automated QA on the campaign design.
            if ($approve && $stage === ApprovalDecision::STAGE_BPT) {
                (new QaQueue($this->db))->queue($campaignId, $id, 'bpt_approved', $this->auth->staffId, $this->auth->staffName);
            }
        });

        return $this->detail($id);
    }

    /** Latest QA run of the review's campaign (worked inline when no cron has). */
    private function qaStatus(): ?array
    {
        $approval = Approval::findOrFail($this->requireId('Review'));
        $campaignId = (int) $approval['campaign_id'];
        $run = CampaignQa::latest($campaignId);
        if ($run !== null && $run['status'] === CampaignQa::QUEUED) {
            $queuedAt = Model::parseDate((string) $run['created_at']);
            if ($queuedAt !== null && time() - $queuedAt->getTimestamp() >= 5) {
                (new QaQueue($this->db))->work(microtime(true) + 60, $campaignId);
                $run = CampaignQa::latest($campaignId);
            }
        }

        return $run;
    }

    private function qaRun(): array
    {
        $approval = Approval::findOrFail($this->requireId('Review'));
        $this->assertCanRunQa();

        return (new QaQueue($this->db))->queue((int) $approval['campaign_id'], (int) $approval['id'], 'manual', $this->auth->staffId, $this->auth->staffName);
    }

    /** Add UTM tags to the campaign design, save it as a new version, queue QA. */
    private function qaUtm(): array
    {
        $approval = Approval::findOrFail($this->requireId('Review'));
        $this->assertCanRunQa();
        $campaignId = (int) $approval['campaign_id'];
        $campaign = Campaign::findOrFail($campaignId);
        if (!in_array((int) $campaign['status'], [1, 2, 3, 4, 5], true)) {
            throw new HttpException('The campaign is scheduled or sent - its design can no longer change.', 422);
        }
        $content = CampaignContent::forCampaign($campaignId);
        $tagger = new UtmTagger((string) $campaign['name']);
        $json = is_array($content['editor_json'] ?? null) ? $tagger->editorJson($content['editor_json']) : null;
        CampaignContent::saveBody($campaignId, $tagger->html((string) $content['html']), $json);
        $this->log((int) $approval['id'], 'updated', 'Added UTM tags to every link in the campaign design.');

        return (new QaQueue($this->db))->queue($campaignId, (int) $approval['id'], 'manual', $this->auth->staffId, $this->auth->staffName);
    }

    /** Building roles (superadmin, admin, BPT) may run QA / add UTM tags. */
    private function assertCanRunQa(): void
    {
        if (!$this->auth->isSuperadmin && !in_array($this->auth->permission, [1, 2, 3], true)) {
            throw new HttpException('Only the building roles can run the QA check.', 403);
        }
    }

    /** A campaign no longer under BPT review (request deleted or moved) goes back to pending submission. */
    private function releaseCampaign(int $campaignId): void
    {
        $campaign = Campaign::find($campaignId);
        if ($campaign !== null && $campaign['status'] === Campaign::UNDER_BPT_REVIEW) {
            Campaign::update($campaignId, ['status' => Campaign::PENDING_SUBMISSION]);
        }
    }

    private function log(int $approvalId, string $event, ?string $summary, ?array $changes = null): void
    {
        ApprovalLog::create([
            'approval_id' => $approvalId,
            'event'       => $event,
            'summary'     => $summary !== null ? mb_substr($summary, 0, 1000) : null,
            'changes'     => $changes,
            'actor_id'    => $this->auth->staffId,
            'actor_name'  => $this->auth->staffName,
        ]);
    }

    /**
     * Field-by-field edits for the log: title and campaign with old / new
     * value; rich-text fields only as "edited" (their HTML is not repeated).
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    private function changes(array $old, array $new): array
    {
        $out = [];
        if ((string) $old['title'] !== (string) $new['title']) {
            $out[] = ['label' => 'Title', 'from' => (string) $old['title'], 'to' => (string) $new['title']];
        }
        if ((int) $old['campaign_id'] !== (int) $new['campaign_id']) {
            $name = static fn (int $cid): string => (string) (Campaign::find($cid)['name'] ?? ('#' . $cid));
            $out[] = ['label' => 'Campaign', 'from' => $name((int) $old['campaign_id']), 'to' => $name((int) $new['campaign_id'])];
        }
        foreach (['objective' => 'Objective', 'audience_brief' => 'Audience brief', 'copywriting' => 'Copywriting'] as $field => $label) {
            if ((string) $old[$field] !== (string) $new[$field]) {
                $out[] = ['label' => $label, 'from' => '', 'to' => 'edited'];
            }
        }

        return $out;
    }

    /**
     * The requester (or a superadmin) can change a request while it waits for
     * the BPT team, or after a rejection (saving then resubmits it).
     */
    private function canEdit(array $row): bool
    {
        return (Approval::awaitingBpt($row) || (int) $row['status'] === Approval::REJECTED)
            && ($this->auth->isSuperadmin || ($row['requested_by'] !== null && (int) $row['requested_by'] === $this->auth->staffId));
    }

    private function assertCanEdit(array $row): void
    {
        if (!$this->canEdit($row)) {
            throw new HttpException('Only the requester (or a superadmin) can change a review, and only while it waits for the BPT team or after a rejection.', 403);
        }
    }

    /** @return list<array{name: string, tmp_name: string, size: int, error: int}> */
    private function uploadedFiles(): array
    {
        $f = $_FILES['files'] ?? null;
        if (!is_array($f) || !is_array($f['name'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => (string) $name, 'tmp_name' => (string) $f['tmp_name'][$i], 'size' => (int) $f['size'][$i], 'error' => (int) $f['error'][$i]];
        }

        return $out;
    }

    /** @return array{name: string, tmp: string, mime: string, ext: string, size: int} */
    private function checkFile(array $file): array
    {
        $label = $file['name'] . ': ';
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > self::MAX_BYTES) {
            throw ValidationException::single('files', $label . 'larger than 10 MB.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw ValidationException::single('files', $label . 'the upload failed. Please try again.');
        }
        // Trust the file's content, not its name or the browser's MIME claim.
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::TYPES[$mime]) || (str_starts_with($mime, 'image/') && @getimagesize($file['tmp_name']) === false)) {
            throw ValidationException::single('files', $label . 'only JPG, PNG, GIF, WebP or PDF files can be attached.');
        }

        return ['name' => mb_substr($file['name'], 0, 255), 'tmp' => $file['tmp_name'], 'mime' => $mime, 'ext' => self::TYPES[$mime], 'size' => $file['size']];
    }

    private function uploadDir(): string
    {
        return dirname(__DIR__, 2) . '/' . self::UPLOAD_DIR;
    }

    private function storeFile(string $tmp, string $name): void
    {
        $dir = $this->uploadDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw ValidationException::single('files', 'The upload folder is not writable.');
        }
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw ValidationException::single('files', 'An artwork file could not be saved.');
        }
    }

    /** Soft-deletes the row and removes the stored file. */
    private function removeFile(array $file): void
    {
        ApprovalFile::delete((int) $file['id']);
        $name = basename((string) parse_url((string) $file['url'], PHP_URL_PATH));
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp|pdf)$/', $name)) {
            @unlink($this->uploadDir() . '/' . $name);
        }
    }

    /** Absolute URL of a stored file, following however odb is being served. */
    private function publicUrl(string $name): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return ($https ? 'https' : 'http') . '://' . $host . EDM_BASE . self::UPLOAD_DIR . '/' . $name;
    }
}
