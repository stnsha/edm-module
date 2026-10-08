<?php
$page_title = 'SES event log';
$page_subtitle = 'Every event Amazon SES reported back (delivery, open, click, bounce, complaint, reject ...), as received by public/ses-webhook.php. Use it to check that events arrive and to look into a single email.';
$page_js    = null; // set after header.php once EDM_BASE is defined
require __DIR__ . '/../partials.php';
include __DIR__ . '/../header.php';

// Settings is superadmin-only. header.php only blocks tier 0, so guard here.
if (empty($_is_superadmin) && (int)$edm_permission !== 1) {
    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . EDM_BASE . 'dashboard/index.php');
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
$event_types = \Edm\Models\SesEvent::TYPES;

$page_js = EDM_BASE . 'settings/events.js';
?>
<div id="edm-ev-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
    <div class="small text-muted" id="edm-ev-summary">Loading...</div>
    <div class="d-flex align-items-center flex-wrap gap-2">
        <select class="form-select form-select-sm" id="edm-ev-type" style="width:auto;" autocomplete="off">
            <option value="">All events</option>
            <?php foreach ($event_types as $t): ?>
            <option value="<?php echo edm_h($t); ?>"><?php echo edm_h($t); ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" id="edm-ev-days" style="width:auto;" autocomplete="off">
            <option value="1">Last 24 hours</option>
            <option value="7">Last 7 days</option>
            <option value="30" selected>Last 30 days</option>
            <option value="90">Last 90 days</option>
            <option value="0">All time</option>
        </select>
        <input type="search" class="form-control form-control-sm" id="edm-ev-search" placeholder="Search email, newsletter or detail" style="width:16rem;" autocomplete="off">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="edm-ev-refresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-hover align-middle edm-view-tbl">
        <thead>
            <tr>
                <th style="width:3rem;">#</th>
                <th>Time</th>
                <th>Event</th>
                <th>Email</th>
                <th>Newsletter</th>
                <th>Detail</th>
                <th class="text-end">Action</th>
            </tr>
        </thead>
        <tbody id="edm-ev-rows">
            <tr><td colspan="7" class="text-center text-muted py-4">Loading...</td></tr>
        </tbody>
    </table>
</div>
<div class="edm-pager" id="edm-ev-pager"></div>

<!-- Raw event (events_get): the SES event exactly as received. -->
<div class="modal fade" id="edm-ev-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="edm-ev-modal-title">Event</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <pre class="small mb-0" id="edm-ev-raw" style="white-space:pre-wrap; word-break:break-all;"></pre>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo EDM_BASE; ?>js/edm-pager.js?v=<?php echo filemtime(__DIR__ . '/../js/edm-pager.js'); ?>"></script>
<?php
include __DIR__ . '/../footer.php';
?>
