<?php
$page_title  = 'Email creator';
$campaign_id = isset($_GET['campaign']) ? (int)$_GET['campaign'] : 0;
if (!$campaign_id) {
    $page_subtitle = 'Pick a newsletter to design.';
} else {
    // The editor bar below carries the newsletter name instead.
    $page_hide_title = true;
}
require __DIR__ . '/../partials.php';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'email-builder/email-builder.js';
?>
<?php if (!$campaign_id): ?>
<div class="table-responsive">
    <table class="table table-hover align-middle edm-view-tbl">
        <thead>
            <tr>
                <th style="width:3rem;">#</th>
                <th>Name</th>
                <th>Subject</th>
                <th>Status</th>
                <th class="text-end">Action</th>
            </tr>
        </thead>
        <tbody id="edm-eb-picker-rows">
            <tr><td colspan="5" class="text-center text-muted py-4">Loading...</td></tr>
        </tbody>
    </table>
</div>
<script>
(function () {
    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var rowsEl = document.getElementById('edm-eb-picker-rows');
    var badges = {
        1: { cls: 'edm-pill-secondary', label: 'Draft' },
        2: { cls: 'edm-pill-info', label: 'Pending submission' },
        3: { cls: 'edm-pill-info', label: 'Under BPT review' },
        4: { cls: 'edm-pill-warning', label: 'Content revision' },
        5: { cls: 'edm-pill-info', label: 'Audience validation' },
        6: { cls: 'edm-pill-primary', label: 'Scheduled' },
        7: { cls: 'edm-pill-primary', label: 'Sending' },
        8: { cls: 'edm-pill-success', label: 'Completed' },
        9: { cls: 'edm-pill-dark', label: 'Archived' }
    };
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    fetch(BASE + 'email-builder/api.php?action=campaigns_list', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            var rows = (res.success && res.data) || [];
            if (!rows.length) {
                rowsEl.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No newsletters yet - create one under Newsletters.</td></tr>';
                return;
            }
            rowsEl.innerHTML = rows.map(function (r, i) {
                return '<tr>' +
                    '<td class="text-muted">' + (i + 1) + '</td>' +
                    '<td>' + esc(r.name) + '</td>' +
                    '<td>' + (r.subject ? esc(r.subject) : '<span class="text-muted">-</span>') + '</td>' +
                    '<td><span class="edm-pill ' + ((badges[r.status] && badges[r.status].cls) || 'edm-pill-secondary') + '">' + esc((badges[r.status] && badges[r.status].label) || r.status) + '</span></td>' +
                    '<td class="text-end"><div class="dropdown">' +
                        '<button type="button" class="edm-row-kebab" data-bs-toggle="dropdown" aria-expanded="false"' +
                            ' data-bs-popper-config=\'{"strategy":"fixed"}\' aria-label="Actions" title="Actions">' +
                            '<i class="bi bi-three-dots-vertical"></i></button>' +
                        '<ul class="dropdown-menu dropdown-menu-end edm-row-menu">' +
                            '<li><a class="dropdown-item" href="' + esc(BASE + 'email-builder/index.php?campaign=' + r.id) + '">Design</a></li>' +
                        '</ul></div></td>' +
                '</tr>';
            }).join('');
        })
        .catch(function () {
            rowsEl.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Could not reach the server.</td></tr>';
        });
})();
</script>
<?php else: ?>
<div class="edm-eb-bar">
    <a class="edm-eb-back" href="<?php echo EDM_BASE; ?>campaign/index.php" title="Back to newsletters" aria-label="Back to newsletters">
        <i class="bi bi-arrow-left"></i>
    </a>
    <div class="edm-eb-heading">
        <div class="edm-eb-title-row">
            <h1 class="edm-page-title mb-0 text-truncate" id="edm-eb-name">Loading...</h1>
        </div>
    </div>
    <div class="edm-eb-actions">
        <div class="edm-eb-buttons">
            <span class="edm-pill edm-pill-secondary" id="edm-eb-status" hidden></span>
            <button type="button" class="btn btn-outline-secondary" id="edm-eb-template" disabled title="Replace the design with a template">
                <i class="bi bi-layout-text-window me-1"></i>Start from template
            </button>
            <button type="button" class="btn btn-outline-secondary" id="edm-eb-test" disabled title="Save, then send one test email through Amazon SES">
                <i class="bi bi-envelope-paper me-1"></i>Send test
            </button>
            <button type="button" class="btn btn-outline-primary" id="edm-eb-save" disabled title="Save (Ctrl+S)">
                <i class="bi bi-floppy me-1"></i>Save
            </button>
            <button type="button" class="btn btn-success" id="edm-eb-submit" hidden title="Save, then submit for review">
                <i class="bi bi-send me-1"></i>Submit
            </button>
        </div>
        <span class="edm-eb-state" id="edm-eb-state"></span>
    </div>
</div>

<div id="edm-eb-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<?php
require __DIR__ . '/../app/bootstrap.php';

// Active Contacts > Custom fields are the personalisation variables ({{key}}).
$edm_custom_vars = array();
foreach (\Edm\Models\CustomField::where('`is_active` = 1') as $row) {
    if (!empty($row['key'])) {
        $edm_custom_vars[] = array('token' => '{{' . $row['key'] . '}}', 'label' => $row['label'] ?: $row['key']);
    }
}

// Images in the Files library, offered in the editor's Image block.
$edm_assets = array();
foreach (\Edm\Models\Asset::where('`type` = ?', array('image')) as $row) {
    $edm_assets[] = array('id' => (int)$row['id'], 'name' => $row['name'], 'url' => $row['url']);
}

// Sender / list options for the settings panel.
$edm_senders = array();
foreach (\Edm\Models\Sender::all() as $row) {
    $edm_senders[] = array('id' => (int)$row['id'], 'label' => $row['from_name'] . ' <' . $row['email'] . '>');
}
$edm_lists = array();
foreach (\Edm\Models\ContactList::all() as $row) {
    $edm_lists[] = array('id' => (int)$row['id'], 'label' => $row['name']);
}
$edm_segments = array();
$edm_segment_lists = array();
foreach (\Edm\Models\Segment::all() as $row) {
    $edm_segments[] = array('id' => (int)$row['id'], 'label' => $row['name']);
    $edm_segment_lists[$row['id']] = $row['list_id'];
}
$edm_templates = \Edm\Models\Template::options();
?>
<script>
window.EDM_EB_CUSTOM_VARS = <?php echo json_encode($edm_custom_vars); ?>;
window.EDM_EB_ASSETS = <?php echo json_encode($edm_assets); ?>;
window.EDM_EB_SEGMENT_LISTS = <?php echo json_encode((object) $edm_segment_lists); ?>;
</script>

<!-- Newsletter settings (same fields as the Newsletters create form). Saved
     together with the design by the Save button / Ctrl+S. -->
<form class="edm-eb-settings" id="edm-eb-settings" autocomplete="off" onsubmit="return false;">
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="edm-eb-f-name">Name <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="edm-eb-f-name" maxlength="255" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="edm-eb-f-sender">Sender <span class="text-danger" aria-hidden="true">*</span></label>
            <select class="form-select" id="edm-eb-f-sender" required>
                <option value="">Select a sender</option>
                <?php foreach ($edm_senders as $o): ?>
                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="edm-eb-f-list">Recipient list <span class="text-danger" aria-hidden="true">*</span></label>
            <select class="form-select" id="edm-eb-f-list" required>
                <option value="">Select a list</option>
                <?php foreach ($edm_lists as $o): ?>
                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="edm-eb-f-segment">Segment</label>
            <select class="form-select" id="edm-eb-f-segment">
                <option value="">None - send to the whole list</option>
                <?php foreach ($edm_segments as $o): ?>
                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text" id="edm-eb-segment-hint"></div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="edm-eb-f-subject">Subject line <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="edm-eb-f-subject" maxlength="255" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="edm-eb-f-scheduled">Scheduled send</label>
            <input type="datetime-local" class="form-control" id="edm-eb-f-scheduled">
            <div class="form-text text-warning-emphasis" id="edm-eb-conflicts" hidden></div>
        </div>
    </div>
</form>

<!-- EmailBuilder.js (usewaypoint/email-builder-js, MIT), prebuilt from
     email-builder/editor-src into email-builder/editor. Talks to
     email-builder.js over postMessage (see editor-src/src/bridge.ts). -->
<div class="edm-eb-frame-wrap" data-campaign="<?php echo (int)$campaign_id; ?>">
    <iframe id="edm-eb-frame" class="edm-eb-frame" title="Email creator"
        src="<?php echo EDM_BASE; ?>email-builder/editor/index.html?v=<?php echo (int)@filemtime(__DIR__ . '/editor/index.html'); ?>"></iframe>
</div>
<!-- Start from template: replaces the editor's design with a copy of a
     template. Nothing is written until Save. -->
<div class="modal fade" id="edm-eb-template-modal" tabindex="-1" aria-labelledby="edm-eb-template-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="edm-eb-template-title">Start from template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($edm_templates): ?>
                <label class="form-label" for="edm-eb-template-select">Template</label>
                <select class="form-select" id="edm-eb-template-select">
                    <?php foreach ($edm_templates as $t): ?>
                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name'] . ($t['category'] ? ' (' . $t['category'] . ')' : '')); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">The current design is replaced. Nothing is saved until you click Save.</div>
                <?php else: ?>
                <p class="text-muted mb-0">No templates yet - create one under Templates.</p>
                <?php endif; ?>
                <div class="alert alert-danger py-2 px-3 small mt-3 mb-0" id="edm-eb-template-error" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <?php if ($edm_templates): ?>
                <button type="button" class="btn btn-primary btn-sm" id="edm-eb-template-apply">Use template</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<!-- Send test: saves, then sends one "[Test]" email of the design via SES. -->
<div class="modal fade" id="edm-eb-test-modal" tabindex="-1" aria-labelledby="edm-eb-test-title" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="edm-eb-test-form" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="edm-eb-test-title">Send test email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="edm-eb-test-to">Send to <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="email" class="form-control" id="edm-eb-test-to" maxlength="255" required autocomplete="email">
                <div class="form-text">The newsletter is saved first. While the SES account is in the sandbox, this address must be verified in SES too.</div>
                <div class="alert py-2 px-3 small mt-3 mb-0" id="edm-eb-test-result" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary btn-sm" id="edm-eb-test-send">Save and send test</button>
            </div>
        </form>
    </div>
</div>
<script src="<?php echo EDM_BASE; ?>js/edm-confirm.js"></script>
<script src="<?php echo EDM_BASE; ?>js/edm-segment-picker.js"></script>
<?php endif; ?>
<?php
include __DIR__ . '/../footer.php';
?>
