<?php
$page_title = 'Senders';
$page_js    = null; // set after header.php once EDM_BASE is defined
include __DIR__ . '/../header.php';

// Settings is superadmin-only. header.php only blocks tier 0, so guard here.
if (empty($_is_superadmin) && (int)$edm_permission !== 1) {
    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . EDM_BASE . 'dashboard/index.php');
    exit;
}

$page_js = EDM_BASE . 'settings/senders.js';
?>
<div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <p class="text-muted small mb-0">
            From-addresses available to newsletters. Each must be verified in Amazon SES
            before it can send. SES verification is not wired yet - set the status manually
            for now.
        </p>
    </div>
    <button type="button" class="btn btn-primary btn-sm" id="edm-sender-add">
        <i class="bi bi-plus-lg"></i> Add sender
    </button>
</div>

<div id="edm-sender-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<div class="table-responsive">
    <table class="table table-hover align-middle edm-view-tbl">
        <thead>
            <tr>
                <th style="width:3rem;">#</th>
                <th style="width:2.5rem;"></th>
                <th>From name</th>
                <th>Email</th>
                <th>Reply-to</th>
                <th>Status</th>
                <th class="text-end">Action</th>
            </tr>
        </thead>
        <tbody id="edm-sender-rows">
            <tr><td colspan="7" class="text-center text-muted py-4">Loading...</td></tr>
        </tbody>
    </table>
</div>

<div class="edm-pager" id="edm-sender-pager"></div>

<div class="modal fade" id="edm-sender-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="edm-sender-form">
                <div class="modal-header">
                    <h5 class="modal-title" id="edm-sender-modal-title">Add sender</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="edm-sender-id">
                    <div class="mb-3">
                        <label class="form-label" for="edm-sender-from-name">From name <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control" id="edm-sender-from-name" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edm-sender-email">Email <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="email" class="form-control" id="edm-sender-email" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edm-sender-reply-to">Reply-to</label>
                        <input type="email" class="form-control" id="edm-sender-reply-to" maxlength="255">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="edm-sender-default">
                        <label class="form-check-label" for="edm-sender-default">Default sender for new newsletters</label>
                    </div>
                    <div id="edm-sender-form-error" class="text-danger small mt-2" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="edm-sender-save">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="<?php echo EDM_BASE; ?>js/edm-confirm.js"></script>
<?php
include __DIR__ . '/../footer.php';
?>
