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

<!-- Template settings (same fields as the Templates create form). Saved
     together with the design by the Save button / Ctrl+S. -->
<form class="edm-eb-settings" id="edm-eb-settings" autocomplete="off" onsubmit="return false;">
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="edm-eb-f-name">Name <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="edm-eb-f-name" maxlength="255" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="edm-eb-f-category">Category</label>
            <input type="text" class="form-control" id="edm-eb-f-category" maxlength="255">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="edm-eb-f-thumbnail">Thumbnail URL</label>
            <input type="text" class="form-control" id="edm-eb-f-thumbnail" maxlength="255">
        </div>
    </div>
</form>

<!-- EmailBuilder.js, the same prebuilt editor the Email creator hosts
     (email-builder/editor, source in email-builder/editor-src). Talks to
     templates/edit.js over postMessage (see editor-src/src/bridge.ts). -->
<div class="edm-eb-frame-wrap" data-template="<?php echo (int)$template_id; ?>">
    <iframe id="edm-eb-frame" class="edm-eb-frame" title="Template editor"
        src="<?php echo EDM_BASE; ?>email-builder/editor/index.html?v=<?php echo (int)@filemtime(__DIR__ . '/../email-builder/editor/index.html'); ?>"></iframe>
</div>
<?php
include __DIR__ . '/../footer.php';
?>
