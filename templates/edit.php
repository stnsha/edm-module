<?php
$page_title = 'Edit template';
$template_id = isset($_GET['template']) ? (int)$_GET['template'] : 0;
if (!$template_id) {
    header('Location: index.php');
    exit;
}
// The editor bar below carries the template name instead.
$page_hide_title = true;
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'templates/edit.js';
?>
<div class="edm-eb-bar">
    <a class="edm-eb-back" href="<?php echo EDM_BASE; ?>templates/index.php" title="Back to templates" aria-label="Back to templates">
        <i class="bi bi-arrow-left"></i>
    </a>
    <div class="edm-eb-heading">
        <div class="edm-eb-title-row">
            <h1 class="edm-page-title mb-0 text-truncate" id="edm-eb-name">Loading...</h1>
        </div>
    </div>
    <div class="edm-eb-actions">
        <div class="edm-eb-buttons">
            <button type="button" class="btn btn-primary" id="edm-eb-save" disabled title="Save (Ctrl+S)">
                <i class="bi bi-floppy me-1"></i>Save
            </button>
        </div>
        <span class="edm-eb-state" id="edm-eb-state"></span>
    </div>
</div>

<div id="edm-eb-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<?php
require __DIR__ . '/../app/bootstrap.php';

// Same editor data as the Email creator (email-builder/index.php):
// Contacts > Custom fields as personalisation variables ({{key}}) ...
$edm_custom_vars = array();
foreach (\Edm\Models\CustomField::where('`is_active` = 1') as $row) {
    if (!empty($row['key'])) {
        $edm_custom_vars[] = array('token' => '{{' . $row['key'] . '}}', 'label' => $row['label'] ?: $row['key']);
    }
}

// ... and the Files library images for the Image block.
$edm_assets = array();
foreach (\Edm\Models\Asset::where('`type` = ?', array('image')) as $row) {
    $edm_assets[] = array('id' => (int)$row['id'], 'name' => $row['name'], 'url' => $row['url']);
}
?>
<script>
window.EDM_EB_CUSTOM_VARS = <?php echo json_encode($edm_custom_vars); ?>;
window.EDM_EB_ASSETS = <?php echo json_encode($edm_assets); ?>;
</script>

<!-- Wizard, like the Email creator (email-builder/index.php): Details,
     Design, Review. The top-bar Save (Ctrl+S) saves everything from any step;
     the step is kept in the URL hash (#step-2). -->
<nav class="edm-wiz-steps edm-wiz-steps--3" id="edm-wiz-steps" aria-label="Template steps">
    <button type="button" class="edm-wiz-step" data-step="1"><span class="edm-wiz-dot">1</span><span class="edm-wiz-label">Details</span></button>
    <button type="button" class="edm-wiz-step" data-step="2"><span class="edm-wiz-dot">2</span><span class="edm-wiz-label">Design</span></button>
    <button type="button" class="edm-wiz-step" data-step="3"><span class="edm-wiz-dot">3</span><span class="edm-wiz-label">Review</span></button>
</nav>

<form class="edm-wiz" id="edm-eb-settings" autocomplete="off" onsubmit="return false;">

<section class="edm-wiz-pane" data-pane="1" aria-label="Details">
    <h2 class="edm-wiz-title">Template details</h2>
    <p class="edm-wiz-sub">How the template is listed in the Template Library and in the Email creator's Start from template.</p>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label" for="edm-eb-f-name">Name <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="edm-eb-f-name" maxlength="255" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="edm-eb-f-category">Category</label>
            <input type="text" class="form-control" id="edm-eb-f-category" maxlength="255" placeholder="e.g. Newsletter, Birthday, Promotion">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="edm-eb-f-thumbnail">Thumbnail URL</label>
            <input type="text" class="form-control" id="edm-eb-f-thumbnail" maxlength="255" placeholder="https://...">
            <div class="form-text">Optional image shown on the template card.</div>
        </div>
    </div>
    <div class="edm-wiz-nav">
        <span></span>
        <button type="button" class="btn btn-primary" data-wiz-next>Next: Design <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</section>

<section class="edm-wiz-pane edm-wiz-pane--wide" data-pane="2" aria-label="Design" hidden>
    <div class="edm-wiz-design-head">
        <div>
            <h2 class="edm-wiz-title">Design</h2>
            <p class="edm-wiz-sub mb-0">Drag blocks into the email. Newsletters started from this template get a copy of the design.</p>
        </div>
    </div>
    <!-- EmailBuilder.js, the same prebuilt editor the Email creator hosts
         (email-builder/editor, source in email-builder/editor-src). Talks to
         templates/edit.js over postMessage (see editor-src/src/bridge.ts). -->
    <div class="edm-eb-frame-wrap" data-template="<?php echo (int)$template_id; ?>">
        <iframe id="edm-eb-frame" class="edm-eb-frame" title="Template editor"
            src="<?php echo EDM_BASE; ?>email-builder/editor/index.html?v=<?php echo (int)@filemtime(__DIR__ . '/../email-builder/editor/index.html'); ?>"></iframe>
    </div>
    <div class="edm-wiz-nav">
        <button type="button" class="btn btn-outline-secondary" data-wiz-prev><i class="bi bi-arrow-left me-1"></i>Previous</button>
        <button type="button" class="btn btn-primary" data-wiz-next>Next: Review <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</section>

<section class="edm-wiz-pane" data-pane="3" aria-label="Review" hidden>
    <h2 class="edm-wiz-title">Review and save</h2>
    <p class="edm-wiz-sub">Check the template, preview it, then save.</p>
    <dl class="edm-wiz-summary" id="edm-wiz-summary"></dl>
    <div class="edm-wiz-nav">
        <button type="button" class="btn btn-outline-secondary" data-wiz-prev><i class="bi bi-arrow-left me-1"></i>Previous</button>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" id="edm-eb-preview"><i class="bi bi-eye me-1"></i>Preview</button>
            <button type="button" class="btn btn-primary" id="edm-eb-save-final"><i class="bi bi-floppy me-1"></i>Save template</button>
        </div>
    </div>
</section>

</form>

<!-- Preview: the current design (exported from the editor, unsaved edits
     included) in a sandboxed frame. -->
<div class="modal fade" id="edm-eb-preview-modal" tabindex="-1" aria-labelledby="edm-eb-preview-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="edm-eb-preview-title">Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe class="edm-nl-preview-frame" id="edm-eb-preview-frame" sandbox="" title="Template preview"></iframe>
            </div>
        </div>
    </div>
</div>
<!-- edmConfirm for the editor iframe (Files library delete in the Image block). -->
<script src="<?php echo EDM_BASE; ?>js/edm-confirm.js?v=<?php echo filemtime(__DIR__ . '/../js/edm-confirm.js'); ?>"></script>
<?php
include __DIR__ . '/../footer.php';
?>
