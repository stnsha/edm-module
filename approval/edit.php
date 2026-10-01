<?php
// Raise / edit a review request (spec 5.2 step 1: Project PIC submits the
// campaign request with objective, audience brief, artwork and copywriting).
// Layout, classes and font sizes follow atem/edit.php. approval/edit.js.
$review_id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$page_title = $review_id > 0 ? 'Review Request' : 'Raise Review';
$extra_css  = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">';
include __DIR__ . '/../header.php';
require __DIR__ . '/../app/bootstrap.php';

// Building roles only (superadmin, admin, BPT); management is read-only.
if (!$_is_superadmin && !in_array((int)$edm_permission, array(1, 2, 3), true)) {
    if (function_exists('ob_get_level') && ob_get_level() > 0) { ob_end_clean(); }
    header('Location: ' . EDM_BASE . 'approval/index.php');
    exit;
}

$edm_auth = \Edm\Core\Auth::fromSession(\Edm\Core\Database::get());

$review = null;
$review_files = array();
$review_logs = array();
// Activity Log icons (atem js/edit.js AUDIT_ICONS + approval events).
$audit_icons = array(
    'created'            => 'bi-star',
    'updated'            => 'bi-pencil',
    'attachment_added'   => 'bi-paperclip',
    'attachment_removed' => 'bi-trash',
    'approved'           => 'bi-check-lg',
    'rejected'           => 'bi-x-lg',
    'bpt_approved'       => 'bi-person-check',
    'bpt_rejected'       => 'bi-person-x',
    'resubmitted'        => 'bi-arrow-repeat',
    'qa_passed'          => 'bi-shield-check',
    'qa_failed'          => 'bi-shield-exclamation',
    'audience_approved'  => 'bi-people',
    'audience_rejected'  => 'bi-person-x',
    'final_approved'     => 'bi-calendar-check',
    'final_rejected'     => 'bi-calendar-x',
);
$bpt_decision = null;
$audience_decision = null;
$final_decision = null;
if ($review_id > 0) {
    $review = \Edm\Models\Approval::find($review_id);
    if ($review === null) {
        if (function_exists('ob_get_level') && ob_get_level() > 0) { ob_end_clean(); }
        header('Location: ' . EDM_BASE . 'approval/index.php');
        exit;
    }
    $review_files = \Edm\Models\ApprovalFile::where('`approval_id` = ?', array($review_id));
    $review_logs = \Edm\Models\ApprovalLog::where('`approval_id` = ?', array($review_id));
    $bpt_decision = \Edm\Models\ApprovalDecision::latest($review_id, \Edm\Models\ApprovalDecision::STAGE_BPT);
    $audience_decision = \Edm\Models\ApprovalDecision::latest($review_id, \Edm\Models\ApprovalDecision::STAGE_AUDIENCE);
    $final_decision = \Edm\Models\ApprovalDecision::latest($review_id, \Edm\Models\ApprovalDecision::STAGE_FINAL);
}
// Same rule as ApprovalController::canEdit(): the requester (or a
// superadmin) while the request waits for the BPT team or after a rejection.
$can_edit = $review === null
    || ((\Edm\Models\Approval::awaitingBpt($review) || (int)$review['status'] === \Edm\Models\Approval::REJECTED)
        && ($edm_auth->isSuperadmin || ($review['requested_by'] !== null && (int)$review['requested_by'] === $edm_auth->staffId)));
// Who decides each stage: Approval::canDecide() (BPT team for bpt / final,
// admin for audience, superadmin for all; dev override included).
$is_bpt = \Edm\Models\Approval::canDecide($edm_auth, \Edm\Models\ApprovalDecision::STAGE_BPT);
$can_audience = \Edm\Models\Approval::canDecide($edm_auth, \Edm\Models\ApprovalDecision::STAGE_AUDIENCE);
$can_final = \Edm\Models\Approval::canDecide($edm_auth, \Edm\Models\ApprovalDecision::STAGE_FINAL);
$awaiting_bpt = $review !== null && \Edm\Models\Approval::awaitingBpt($review);
$awaiting_audience = $review !== null && \Edm\Models\Approval::awaitingAudience($review);
$awaiting_final = $review !== null && \Edm\Models\Approval::awaitingFinal($review);
$audience_checks = \Edm\Models\Approval::AUDIENCE_CHECKS;
$bpt_checks = \Edm\Models\Approval::BPT_CHECKS;
// Sending slot (spec 5.2 step 3): the campaign's send date + calendar clashes.
$slot_campaign = $review !== null ? \Edm\Models\Campaign::find((int)$review['campaign_id']) : null;
$slot_date = $slot_campaign !== null && !empty($slot_campaign['scheduled_at']) ? \Edm\Core\Model::parseDate((string)$slot_campaign['scheduled_at']) : null;
$slot_conflicts = $slot_date !== null
    ? (new \Edm\Services\ScheduleConflicts(\Edm\Core\Database::get()))->forDate($slot_date, (int)$review['campaign_id'])
    : array();
$mode = $can_edit ? 'edit' : 'read';
$requester = $review !== null ? (string)($review['requested_by_name'] ?? '') : (string)$edm_auth->staffName;

// Campaigns that can go to review (Campaign::REVIEWABLE: draft / pending
// submission / content revision) without another open review, plus the one
// this request is for.
$campaigns = array();
foreach (\Edm\Models\Campaign::all() as $c) {
    $is_current = $review !== null && (int)$c['id'] === (int)$review['campaign_id'];
    if ($is_current || (in_array((int)$c['status'], \Edm\Models\Campaign::REVIEWABLE, true)
        && \Edm\Models\Approval::openFor((int)$c['id'], $review_id) === null)) {
        $campaigns[] = $c;
    }
}

// Audience Validation (step 4) and Final Approval (step 6) cards.
$audience = null;
$readiness = array();
if ($review !== null && $slot_campaign !== null && ((int)$review['step'] >= \Edm\Models\Approval::STEP_AUDIENCE || $audience_decision !== null)) {
    $audience = (new \Edm\Services\CampaignAudience(\Edm\Core\Database::get()))->summary($slot_campaign);
    $readiness = (new \Edm\Services\ScheduleReadiness())->checks($slot_campaign);
}
$show_final = $review !== null && ((int)$review['step'] >= \Edm\Models\Approval::STEP_FINAL || $final_decision !== null);
// datetime-local value of the campaign's send date, or empty.
$final_at = $slot_date !== null ? $slot_date->format('Y-m-d\TH:i') : '';

$status_pills = array(
    1 => array('cls' => 'edm-pill-warning', 'label' => 'Pending'),
    2 => array('cls' => 'edm-pill-success', 'label' => 'Approved'),
    3 => array('cls' => 'edm-pill-danger', 'label' => 'Rejected'),
);
$page_js = EDM_BASE . 'approval/edit.js';
?>
<div class="edm-bento edm-mode-<?php echo $mode; ?>">

    <!-- One column, one card per row: Details (with Objective), Artwork,
         Audience Brief, Copywriting, Review Status, Activity Log -->
    <!-- Review Request Details -->
    <div class="edm-bento-item edm-span-12">
        <div class="edm-card">
            <h6 class="edm-card-title"><i class="bi bi-clipboard-check"></i> Review Request Details
                <?php if ($review_id > 0): ?>
                <span class="edm-id" style="font-size:15px;font-weight:normal;">#RV<?php echo $review_id; ?></span>
                <?php endif; ?>
            </h6>
            <p class="edm-card-hint">
                <?php echo $can_edit ? 'Raise a review for a campaign. Fields marked' : 'Viewing a review request (read only).'; ?>
                <?php if ($can_edit): ?><span class="edm-req">*</span> are required.<?php endif; ?>
            </p>
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <label for="edm-rv-title" class="form-label">Title <span class="edm-req">*</span></label>
                    <input type="text" class="form-control" id="edm-rv-title" maxlength="255" placeholder="Short, searchable title"
                        value="<?php echo htmlspecialchars((string)($review['title'] ?? '')); ?>"<?php echo $can_edit ? '' : ' disabled'; ?>>
                    <div class="edm-form-error" id="edm-rv-title-error"></div>
                </div>
                <div class="col-12">
                    <label for="edm-rv-requester" class="form-label">Requested By</label>
                    <input type="text" class="form-control" id="edm-rv-requester" value="<?php echo htmlspecialchars($requester); ?>" disabled>
                </div>
                <div class="col-12">
                    <label for="edm-rv-campaign" class="form-label">Campaign <span class="edm-req">*</span></label>
                    <select class="form-select" id="edm-rv-campaign"<?php echo $can_edit ? '' : ' disabled'; ?>>
                        <option value="">Select campaign</option>
                        <?php foreach ($campaigns as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"<?php echo $review !== null && (int)$c['id'] === (int)$review['campaign_id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="edm-form-error" id="edm-rv-campaign-error"></div>
                </div>
                <div class="col-12 mt-2">
                    <label class="form-label">Objective <span class="edm-req">*</span></label>
                    <div id="edm-rv-objective" class="edm-rv-editor edm-rv-editor-lg"></div>
                    <div class="edm-form-error" id="edm-rv-objective-error"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Artwork -->
    <div class="edm-bento-item edm-span-12">
        <div class="edm-card">
            <h6 class="edm-card-title"><i class="bi bi-paperclip"></i> Artwork</h6>
            <p class="edm-card-hint mb-3">Banners, visuals and drafts for this campaign.</p>
            <?php if ($can_edit): ?>
            <!-- Same drop zone and file cards as Files > Upload images (assets/). -->
            <label class="edm-up-drop" id="edm-rv-dropzone" for="edm-rv-file-input">
                <span class="edm-up-art" aria-hidden="true"><i class="bi bi-image"></i><i class="bi bi-image"></i></span>
                <span class="edm-up-drop-text">Drop your artwork here, or <span class="edm-up-browse">browse</span></span>
                <span class="edm-up-drop-over"><i class="bi bi-chevron-double-right"></i> Drop your files here <i class="bi bi-chevron-double-left"></i></span>
                <span class="edm-up-hint">Supports: JPG, JPEG, PNG, GIF, WEBP, PDF - up to 10 MB each, 10 files</span>
            </label>
            <input type="file" id="edm-rv-file-input" class="visually-hidden" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
            <div class="edm-form-error" id="edm-rv-files-error"></div>
            <?php endif; ?>
            <ul class="edm-up-list" id="edm-rv-file-list"></ul>
            <?php if (!$can_edit): ?>
            <div class="edm-empty-state" id="edm-rv-file-empty">No artwork.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Audience Brief -->
    <div class="edm-bento-item edm-span-12">
        <div class="edm-card">
            <h6 class="edm-card-title"><i class="bi bi-people"></i> Audience Brief <span class="edm-req">*</span></h6>
            <p class="edm-card-hint mb-3">Who the campaign is for: segment, filters and exclusions.</p>
            <div id="edm-rv-audience" class="edm-rv-editor"></div>
            <div class="edm-form-error" id="edm-rv-audience_brief-error"></div>
        </div>
    </div>

    <!-- Copywriting -->
    <div class="edm-bento-item edm-span-12">
        <div class="edm-card">
            <h6 class="edm-card-title"><i class="bi bi-pencil-square"></i> Copywriting <span class="edm-req">*</span></h6>
            <p class="edm-card-hint mb-3">Subject line ideas, headline, body copy and call to action.</p>
            <div id="edm-rv-copy" class="edm-rv-editor"></div>
            <div class="edm-form-error" id="edm-rv-copywriting-error"></div>
        </div>
    </div>

    <!-- BPT Review (spec 5.2 steps 2-3): the BPT team checks the content and
         the sending slot, then approves (-> BI/CRM audience validation) or
         rejects (-> back to the requester). approval/edit.js. -->
    <?php if ($review !== null): ?>
    <div class="edm-bento-item edm-span-12">
        <div class="edm-card edm-decision-card" id="edm-bpt-card">
            <h6 class="edm-card-title"><i class="bi bi-person-check"></i> BPT Review
                <?php if ($awaiting_bpt): ?>
                <span class="edm-pill edm-pill-warning ms-2">Waiting for BPT</span>
                <?php elseif ($bpt_decision !== null && (int)$bpt_decision['decision'] === 2): ?>
                <span class="edm-pill edm-pill-success ms-2">Approved</span>
                <?php elseif ($bpt_decision !== null): ?>
                <span class="edm-pill edm-pill-danger ms-2">Rejected</span>
                <?php endif; ?>
            </h6>
            <p class="edm-card-hint mb-3">Steps 2-3 of the approval chain: the BPT team checks the content and the sending slot. Approving passes the request to BI/CRM for audience validation; rejecting returns it to the requester.</p>

            <?php if ($awaiting_bpt && $bpt_decision !== null && (int)$bpt_decision['decision'] === 3): ?>
            <div class="alert alert-warning py-2 px-3 small mb-3">
                Resubmitted after a rejection by <?php echo htmlspecialchars((string)$bpt_decision['decided_by_name']); ?> (<?php echo htmlspecialchars((string)$bpt_decision['created_at']); ?>):
                <?php echo htmlspecialchars((string)$bpt_decision['comment']); ?>
            </div>
            <?php endif; ?>

            <?php
            // Checklist to show: live checkboxes while waiting (BPT only), the
            // ticked items of the latest decision otherwise.
            $show_form = $awaiting_bpt && $is_bpt;
            $ticked = (!$awaiting_bpt && $bpt_decision !== null) ? (array)$bpt_decision['checks'] : array();
            ?>
            <?php if (!$awaiting_bpt || $is_bpt): ?>
            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label">Content check <?php if ($show_form): ?><span class="edm-req">*</span><?php endif; ?></label>
                    <div class="edm-bpt-checks">
                        <?php foreach ($bpt_checks as $key => $label): if ($key === 'slot') { continue; } ?>
                        <div class="form-check">
                            <input class="form-check-input edm-bpt-check" type="checkbox" id="edm-bpt-<?php echo $key; ?>" value="<?php echo $key; ?>"
                                <?php echo in_array($key, $ticked, true) ? 'checked' : ''; ?><?php echo $show_form ? '' : ' disabled'; ?>>
                            <label class="form-check-label" for="edm-bpt-<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Sending slot <?php if ($show_form): ?><span class="edm-req">*</span><?php endif; ?></label>
                    <div class="edm-bpt-slot">
                        <?php if ($slot_date === null): ?>
                        <p class="mb-1">No send date set on the campaign yet.</p>
                        <?php else: ?>
                        <p class="mb-1"><strong>Send date:</strong> <span><?php echo htmlspecialchars($slot_date->format('d-m-Y H:i')); ?></span></p>
                        <?php if ($slot_conflicts): ?>
                        <p class="mb-1 text-warning-emphasis"><i class="bi bi-exclamation-triangle-fill"></i> Also on this day:
                            <?php echo htmlspecialchars(implode(', ', array_map(static function ($c) { return ($c['type'] === 'slot' ? 'Reserved: ' : '') . $c['name']; }, $slot_conflicts))); ?></p>
                        <?php else: ?>
                        <p class="mb-1 text-success"><i class="bi bi-check-circle-fill"></i> No other send or reservation that day.</p>
                        <?php endif; ?>
                        <?php endif; ?>
                        <a href="<?php echo EDM_BASE; ?>calendar/index.php" target="_blank" rel="noopener">Open calendar</a>
                        <?php if ($slot_campaign !== null): ?> &middot; <a href="<?php echo EDM_BASE; ?>email-builder/index.php?campaign=<?php echo (int)$slot_campaign['id']; ?>" target="_blank" rel="noopener">Set send date</a><?php endif; ?>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input edm-bpt-check" type="checkbox" id="edm-bpt-slot" value="slot"
                            <?php echo in_array('slot', $ticked, true) ? 'checked' : ''; ?><?php echo $show_form ? '' : ' disabled'; ?>>
                        <label class="form-check-label" for="edm-bpt-slot"><?php echo htmlspecialchars($bpt_checks['slot']); ?></label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="edm-bpt-comment">Comment <?php if ($show_form): ?><span class="text-muted fw-normal">(required when rejecting)</span><?php endif; ?></label>
                    <textarea class="form-control" id="edm-bpt-comment" rows="3" placeholder="<?php echo $show_form ? 'Notes for the requester, or the reason for rejection...' : 'No comment'; ?>"<?php echo $show_form ? '' : ' disabled'; ?>><?php echo $show_form ? '' : htmlspecialchars((string)($bpt_decision['comment'] ?? '')); ?></textarea>
                    <div class="edm-form-error" id="edm-bpt-error"></div>
                </div>
                <?php if ($show_form): ?>
                <div class="col-12 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-danger" id="edm-bpt-reject">Reject</button>
                    <button type="button" class="btn btn-success" id="edm-bpt-approve">Approve</button>
                </div>
                <?php elseif ($bpt_decision !== null): ?>
                <div class="col-12 edm-card-hint mb-0">
                    <?php echo (int)$bpt_decision['decision'] === 2 ? 'Approved' : 'Rejected'; ?> by
                    <strong><?php echo htmlspecialchars((string)$bpt_decision['decided_by_name']); ?></strong> on <?php echo htmlspecialchars((string)$bpt_decision['created_at']); ?>.
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="edm-empty-state">Waiting for the BPT team to review this request.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Audience Validation (spec 5.2 step 4): BI/CRM confirms who the
         campaign goes to - list, segment, recipients after suppression -
         then approves (-> final approval) or rejects (-> requester). -->
    <?php if ($audience !== null):
        $aud_form = $awaiting_audience && $can_audience;
        $aud_ticked = (!$awaiting_audience && $audience_decision !== null) ? (array)$audience_decision['checks'] : array();
    ?>
    <div class="edm-bento-item edm-span-12">
        <div class="edm-card edm-decision-card" id="edm-aud-card">
            <h6 class="edm-card-title"><i class="bi bi-people"></i> Audience Validation
                <?php if ($awaiting_audience): ?>
                <span class="edm-pill edm-pill-warning ms-2">Waiting for BI/CRM</span>
                <?php elseif ($audience_decision !== null): ?>
                <span class="edm-pill <?php echo (int)$audience_decision['decision'] === 2 ? 'edm-pill-success' : 'edm-pill-danger'; ?> ms-2"><?php echo (int)$audience_decision['decision'] === 2 ? 'Validated' : 'Rejected'; ?></span>
                <?php endif; ?>
            </h6>
            <p class="edm-card-hint mb-3">Step 4 of the approval chain: BI/CRM confirms the audience - segment and LOFRA filters, suppression applied, recipient count. Validating passes the request to final approval; rejecting returns it to the requester.</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="edm-bpt-slot">
                        <p class="mb-1"><strong>Recipient list:</strong> <?php echo htmlspecialchars($audience['list']); ?></p>
                        <p class="mb-1"><strong>Segment:</strong> <?php echo htmlspecialchars($audience['segment'] ?? 'None - the whole list'); ?></p>
                        <?php if ($audience['conditions'] !== null): ?>
                        <p class="mb-1"><strong>Conditions:</strong> <?php echo htmlspecialchars($audience['conditions']); ?></p>
                        <?php endif; ?>
                        <?php if ($audience['problem'] !== null): ?>
                        <p class="mb-1 text-danger"><i class="bi bi-x-circle-fill"></i> <?php echo htmlspecialchars($audience['problem']); ?></p>
                        <?php else: ?>
                        <p class="mb-1"><strong>Subscribed contacts matching:</strong> <?php echo number_format($audience['recipients']); ?></p>
                        <p class="mb-1"><strong>Suppressed (skipped):</strong> <?php echo number_format($audience['suppressed']); ?></p>
                        <p class="mb-1"><strong>Expected recipients:</strong> <?php echo number_format(max(0, $audience['recipients'] - $audience['suppressed'])); ?>
                            <span class="text-muted">(send limits per contact can lower this)</span></p>
                        <?php endif; ?>
                        <?php if ($slot_campaign !== null && !\Edm\Models\Campaign::isLocked($slot_campaign)): ?>
                        <a href="<?php echo EDM_BASE; ?>email-builder/index.php?campaign=<?php echo (int)$slot_campaign['id']; ?>" target="_blank" rel="noopener">Change list or segment</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Audience check <?php if ($aud_form): ?><span class="edm-req">*</span><?php endif; ?></label>
                    <?php foreach ($audience_checks as $key => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input edm-aud-check" type="checkbox" id="edm-aud-<?php echo $key; ?>" value="<?php echo $key; ?>"
                            <?php echo in_array($key, $aud_ticked, true) ? 'checked' : ''; ?><?php echo $aud_form ? '' : ' disabled'; ?>>
                        <label class="form-check-label" for="edm-aud-<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="edm-aud-comment">Comment <?php if ($aud_form): ?><span class="text-muted fw-normal">(required when rejecting)</span><?php endif; ?></label>
                    <textarea class="form-control" id="edm-aud-comment" rows="2" placeholder="<?php echo $aud_form ? 'Notes on the audience, or the reason for rejection...' : 'No comment'; ?>"<?php echo $aud_form ? '' : ' disabled'; ?>><?php echo $aud_form ? '' : htmlspecialchars((string)($audience_decision['comment'] ?? '')); ?></textarea>
                    <div class="edm-form-error" id="edm-aud-error"></div>
                </div>
                <?php if ($aud_form): ?>
                <div class="col-12 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-danger" id="edm-aud-reject">Reject</button>
                    <button type="button" class="btn btn-success" id="edm-aud-approve">Validate audience</button>
                </div>
                <?php elseif ($awaiting_audience): ?>
                <div class="col-12 edm-card-hint mb-0">Waiting for <?php echo htmlspecialchars(\Edm\Models\Approval::STAGE_OWNERS['audience']); ?> to validate the audience.</div>
                <?php elseif ($audience_decision !== null): ?>
                <div class="col-12 edm-card-hint mb-0">
                    <?php echo (int)$audience_decision['decision'] === 2 ? 'Validated' : 'Rejected'; ?> by
                    <strong><?php echo htmlspecialchars((string)$audience_decision['decided_by_name']); ?></strong> on <?php echo htmlspecialchars((string)$audience_decision['created_at']); ?>.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Automated QA (spec 5.2 step 5): filled and polled by approval/edit.js
         from approvals api qa_status; runs are worked by cron/qa.php. -->
    <?php if ($review !== null): ?>
    <div class="edm-bento-item edm-span-12">
        <div class="edm-card" id="edm-qa-card">
            <div class="edm-card-title-row">
                <h6 class="edm-card-title"><i class="bi bi-shield-check"></i> Automated QA
                    <span class="edm-pill edm-pill-secondary ms-2" id="edm-qa-pill">Not run yet</span>
                </h6>
                <button type="button" class="btn btn-outline-primary btn-sm" id="edm-qa-run"><i class="bi bi-arrow-repeat me-1"></i>Run again</button>
            </div>
            <p class="edm-card-hint mb-2">Step 5 of the approval chain: automatic checks on the campaign's saved design. Failed checks block scheduling; warnings do not. Runs after the BPT approval, and again by itself after a fix is saved.</p>
            <div class="edm-qa-meta" id="edm-qa-meta"></div>
            <ul class="edm-qa-list" id="edm-qa-list"></ul>
            <div id="edm-qa-note"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Final Approval (spec 5.2 step 6): readiness (ScheduleReadiness:
         complete, verified sender, usable segment, QA passed on the current
         design) + send date; approving schedules the campaign and locks it. -->
    <?php if ($show_final):
        $fin_form = $awaiting_final && $can_final;
    ?>
    <div class="edm-bento-item edm-span-12">
        <div class="edm-card edm-decision-card" id="edm-final-card">
            <h6 class="edm-card-title"><i class="bi bi-calendar-check"></i> Final Approval
                <?php if ($awaiting_final): ?>
                <span class="edm-pill edm-pill-warning ms-2">Waiting for final approval</span>
                <?php elseif ($final_decision !== null): ?>
                <span class="edm-pill <?php echo (int)$final_decision['decision'] === 2 ? 'edm-pill-success' : 'edm-pill-danger'; ?> ms-2"><?php echo (int)$final_decision['decision'] === 2 ? 'Approved - scheduled' : 'Rejected'; ?></span>
                <?php endif; ?>
            </h6>
            <p class="edm-card-hint mb-3">Step 6 of the approval chain: once every check passes, approve to schedule the campaign. It is then locked and Amazon SES sends it at the send date (step 7).</p>
            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label">Ready to schedule</label>
                    <ul class="edm-final-checks" id="edm-final-checks">
                        <?php foreach ($readiness as $chk): ?>
                        <li class="<?php echo $chk['ok'] ? 'is-ok' : 'is-bad'; ?>">
                            <i class="bi <?php echo $chk['ok'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'; ?>"></i>
                            <strong><?php echo htmlspecialchars($chk['label']); ?></strong> - <?php echo htmlspecialchars($chk['detail']); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="edm-final-at">Send date and time <?php if ($fin_form): ?><span class="edm-req">*</span><?php endif; ?></label>
                    <input type="datetime-local" class="form-control" id="edm-final-at" value="<?php echo htmlspecialchars($final_at); ?>"<?php echo $fin_form ? '' : ' disabled'; ?>>
                    <div class="edm-bpt-slot mt-2" id="edm-final-conflicts">
                        <?php if ($slot_date !== null && $slot_conflicts): ?>
                        <span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle-fill"></i> Also on <?php echo htmlspecialchars($slot_date->format('d-m-Y')); ?>:
                            <?php echo htmlspecialchars(implode(', ', array_map(static function ($c) { return ($c['type'] === 'slot' ? 'Reserved: ' : '') . $c['name']; }, $slot_conflicts))); ?></span>
                        <?php elseif ($slot_date !== null): ?>
                        <span class="text-success"><i class="bi bi-check-circle-fill"></i> No other send or reservation that day.</span>
                        <?php else: ?>
                        <span>Choose the date the campaign goes out.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="edm-final-comment">Comment <?php if ($fin_form): ?><span class="text-muted fw-normal">(required when rejecting)</span><?php endif; ?></label>
                    <textarea class="form-control" id="edm-final-comment" rows="2" placeholder="<?php echo $fin_form ? 'Notes, or the reason for rejection...' : 'No comment'; ?>"<?php echo $fin_form ? '' : ' disabled'; ?>><?php echo $fin_form ? '' : htmlspecialchars((string)($final_decision['comment'] ?? '')); ?></textarea>
                    <div class="edm-form-error" id="edm-final-error"></div>
                </div>
                <?php if ($fin_form): ?>
                <div class="col-12 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-danger" id="edm-final-reject">Reject</button>
                    <button type="button" class="btn btn-success" id="edm-final-approve"><i class="bi bi-calendar-check me-1"></i>Approve &amp; schedule</button>
                </div>
                <?php elseif ($awaiting_final): ?>
                <div class="col-12 edm-card-hint mb-0">Waiting for <?php echo htmlspecialchars(\Edm\Models\Approval::STAGE_OWNERS['final']); ?> to give final approval.</div>
                <?php elseif ($final_decision !== null): ?>
                <div class="col-12 edm-card-hint mb-0">
                    <?php echo (int)$final_decision['decision'] === 2 ? 'Approved and scheduled' : 'Rejected'; ?> by
                    <strong><?php echo htmlspecialchars((string)$final_decision['decided_by_name']); ?></strong> on <?php echo htmlspecialchars((string)$final_decision['created_at']); ?>.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Review Status (existing request only): read-only fields laid out
         like atem's Timeline card, without dates -->
    <?php if ($review !== null): $pill = $status_pills[(int)$review['status']] ?? $status_pills[1]; ?>
    <div class="edm-bento-item edm-span-12">
        <div class="edm-card">
            <h6 class="edm-card-title"><i class="bi bi-flag"></i> Review Status</h6>
            <p class="edm-card-hint mb-3">Where this request is in the approval chain.</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="edm-rv-status">Status</label>
                    <input type="text" class="form-control" id="edm-rv-status" value="<?php echo htmlspecialchars($pill['label']); ?>" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="edm-rv-reviewer">Reviewer</label>
                    <input type="text" class="form-control" id="edm-rv-reviewer" value="<?php echo htmlspecialchars((string)($review['reviewer_name'] ?? '')); ?>" placeholder="Not reviewed yet" disabled>
                </div>
                <div class="col-12">
                    <label class="form-label" for="edm-rv-comment">Reviewer Comment</label>
                    <textarea class="form-control" id="edm-rv-comment" rows="2" placeholder="No comment yet" disabled><?php echo htmlspecialchars((string)($review['comment'] ?? '')); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Log, as atem's Audit Log card -->
    <div class="edm-bento-item edm-span-12">
        <div class="edm-card" id="edm-audit-card">
            <h6 class="edm-card-title"><i class="bi bi-clock-history"></i> Activity Log</h6>
            <p class="edm-card-hint">Full history of changes made to this review request.</p>
            <?php
            $created_log = null;
            foreach ($review_logs as $_l) {
                if ($_l['event'] === 'created') { $created_log = $_l; }
            }
            $latest_log = $review_logs[0] ?? null;
            ?>
            <div class="edm-audit-meta">
                <div class="edm-audit-meta-item"><strong>Created by</strong> <?php echo htmlspecialchars((string)($created_log['actor_name'] ?? $review['requested_by_name'] ?? 'System')); ?> &mdash; <?php echo htmlspecialchars((string)$review['created_at']); ?></div>
                <div class="edm-audit-meta-item"><strong>Last updated by</strong> <?php echo htmlspecialchars((string)($latest_log['actor_name'] ?? $review['requested_by_name'] ?? 'System')); ?> &mdash; <?php echo htmlspecialchars((string)$review['updated_at']); ?></div>
            </div>
            <div class="edm-audit-log mt-3">
                <?php if (!$review_logs): ?>
                <div class="edm-empty-state">No activity recorded yet.</div>
                <?php endif; ?>
                <?php foreach ($review_logs as $log): ?>
                <div class="edm-audit-entry edm-audit-<?php echo htmlspecialchars($log['event']); ?>">
                    <div class="edm-audit-icon"><i class="bi <?php echo $audit_icons[$log['event']] ?? 'bi-circle'; ?>"></i></div>
                    <div class="edm-audit-body">
                        <div class="edm-audit-header"><span><?php echo htmlspecialchars((string)$log['created_at']); ?></span> &mdash; <strong><?php echo htmlspecialchars((string)($log['actor_name'] ?: 'System')); ?></strong></div>
                        <?php if (!empty($log['changes'])): ?>
                        <ul class="edm-audit-changes">
                            <?php foreach ($log['changes'] as $ch): ?>
                            <li><strong><?php echo htmlspecialchars((string)$ch['label']); ?></strong>:
                                <?php if ((string)$ch['from'] !== ''): ?>"<?php echo htmlspecialchars((string)$ch['from']); ?>" &rarr; <?php endif; ?>"<?php echo htmlspecialchars((string)$ch['to']); ?>"</li>
                            <?php endforeach; ?>
                        </ul>
                        <?php elseif (!empty($log['summary'])): ?>
                        <div class="edm-audit-summary"><?php echo htmlspecialchars((string)$log['summary']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="edm-save-error-wrap">
    <div class="edm-form-error" id="edm-rv-save-error"></div>
</div>
<div class="edm-save-bar">
    <a href="<?php echo EDM_BASE; ?>approval/index.php" class="btn btn-outline-secondary">Back to list</a>
    <?php if ($review !== null && $can_edit): ?>
    <button type="button" class="btn btn-outline-danger" id="edm-rv-delete-btn">Delete</button>
    <?php endif; ?>
    <?php if ($can_edit): ?>
    <button type="button" class="btn btn-primary" id="edm-rv-save-btn"><?php echo $review !== null ? 'Save Review' : 'Submit Review'; ?></button>
    <?php endif; ?>
</div>

<script>
window.EDM_REVIEW = <?php echo json_encode(array(
    'id'             => $review_id,
    'campaignId'     => $review !== null ? (int)$review['campaign_id'] : 0,
    'canEdit'        => $can_edit,
    'objective'      => (string)($review['objective'] ?? ''),
    'audience_brief' => (string)($review['audience_brief'] ?? ''),
    'copywriting'    => (string)($review['copywriting'] ?? ''),
    'files'          => array_map(static function ($f) {
        return array('id' => (int)$f['id'], 'name' => $f['name'], 'url' => $f['url'], 'size' => (int)$f['size_bytes']);
    }, $review_files),
), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script src="<?php echo EDM_BASE; ?>js/edm-confirm.js?v=<?php echo filemtime(__DIR__ . '/../js/edm-confirm.js'); ?>"></script>
<?php
include __DIR__ . '/../footer.php';
?>
