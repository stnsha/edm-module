<?php
$page_title  = 'Email creator';
$campaign_id = isset($_GET['campaign']) ? (int)$_GET['campaign'] : 0;
if (!$campaign_id) {
    $page_subtitle = 'Pick a newsletter to design.';
}
$extra_css = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">';
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
                rowsEl.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No newsletters yet - create one under Email marketing &rsaquo; Newsletters.</td></tr>';
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
<div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h2 class="h5 mb-1" id="edm-eb-name">Newsletter</h2>
        <p class="text-muted small mb-0" id="edm-eb-sub">Loading...</p>
    </div>
    <div class="btn-toolbar gap-2">
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary active" id="edm-eb-desktop">Desktop</button>
            <button type="button" class="btn btn-outline-secondary" id="edm-eb-mobile">Mobile</button>
        </div>
        <button type="button" class="btn btn-sm btn-primary" id="edm-eb-save">Save</button>
    </div>
</div>

<div id="edm-eb-alert" class="alert alert-danger py-2 px-3 small" hidden></div>
<div id="edm-eb-saved" class="alert alert-success py-2 px-3 small" hidden>Saved.</div>

<div class="row g-3" data-campaign="<?php echo (int)$campaign_id; ?>">
    <div class="col-lg-6">
        <label class="form-label small text-muted">Content</label>
        <div id="edm-eb-quill"></div>
        <div class="form-text">
            Variables: <code>{{FirstName}}</code> <code>{{LastName}}</code> <code>{{MemberCode}}</code>
            <code>{{VoucherCode}}</code> <code>{{UnsubscribeLink}}</code> - type them directly, they resolve at send
            time. Drag-and-drop blocks are a later feature.
        </div>
    </div>
    <div class="col-lg-6">
        <label class="form-label small text-muted">Preview</label>
        <div class="border rounded p-2 bg-light" style="overflow:auto;">
            <iframe id="edm-eb-preview" title="Preview" style="width:100%; height:60vh; border:0; background:#fff;"></iframe>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<?php endif; ?>
<?php
include __DIR__ . '/../footer.php';
?>
