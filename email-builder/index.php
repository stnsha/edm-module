<?php
$page_title = 'Email creator';
$extra_css  = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'email-builder/email-builder.js';
$campaign_id = isset($_GET['campaign']) ? (int)$_GET['campaign'] : 0;
?>
<?php if (!$campaign_id): ?>
<div class="text-muted py-5 text-center">
    Open the Email creator from a newsletter (Newsletters &rsaquo; Design).
</div>
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
