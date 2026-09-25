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
                    '<td class="text-end"><a class="btn btn-sm btn-outline-primary" href="' + esc(BASE + 'email-builder/index.php?campaign=' + r.id) + '">Design</a></td>' +
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
?>
<script>
window.EDM_EB_CUSTOM_VARS = <?php echo json_encode($edm_custom_vars); ?>;
window.EDM_EB_ASSETS = <?php echo json_encode($edm_assets); ?>;
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
            <label class="form-label" for="edm-eb-f-sender">Sender</label>
            <select class="form-select" id="edm-eb-f-sender">
                <option value="">(none)</option>
                <?php foreach ($edm_senders as $o): ?>
                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="edm-eb-f-list">Recipient list</label>
            <select class="form-select" id="edm-eb-f-list">
                <option value="">(none)</option>
                <?php foreach ($edm_lists as $o): ?>
                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-8">
            <label class="form-label" for="edm-eb-f-subject">Subject line</label>
            <input type="text" class="form-control" id="edm-eb-f-subject" maxlength="255">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="edm-eb-f-scheduled">Scheduled send</label>
            <input type="datetime-local" class="form-control" id="edm-eb-f-scheduled">
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
<script src="<?php echo EDM_BASE; ?>js/edm-confirm.js"></script>
<?php endif; ?>
<?php
include __DIR__ . '/../footer.php';
?>
