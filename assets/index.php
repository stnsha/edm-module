<?php
$page_title = 'Files';
$page_subtitle = 'Images and banners for EDM artwork. Upload an image (JPG, PNG, GIF or WebP, up to 5 MB) or register an image that is already hosted elsewhere.';
require __DIR__ . '/../partials.php';
$page_title_actions = '<div class="d-flex gap-2">'
    . '<button type="button" class="btn btn-outline-primary d-inline-flex align-items-center" id="edm-crud-add"><i class="bi bi-link-45deg me-1"></i>Add URL</button>'
    . '<button type="button" class="btn btn-primary d-inline-flex align-items-center" id="edm-asset-upload-btn"><i class="bi bi-upload me-1"></i>Upload image</button>'
    . '</div>';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Name', 'URL', 'Type', 'Added'),
));
?>
<!-- Upload image: posts multipart to assets/api.php?action=assets_upload. -->
<div class="modal fade" id="edm-asset-upload-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="edm-asset-upload-form">
                <div class="modal-header">
                    <h5 class="modal-title">Upload image</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="edm-asset-file">Image <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="file" class="form-control" id="edm-asset-file" accept="image/jpeg,image/png,image/gif,image/webp" required>
                        <div class="form-text">JPG, PNG, GIF or WebP, up to 5 MB.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edm-asset-name">Name</label>
                        <input type="text" class="form-control" id="edm-asset-name" maxlength="255" placeholder="Defaults to the file name">
                    </div>
                    <div class="edm-asset-preview" id="edm-asset-preview" hidden>
                        <img id="edm-asset-preview-img" alt="Selected image preview">
                    </div>
                    <div id="edm-asset-upload-error" class="text-danger small mt-2" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="edm-asset-upload-save">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var MAX_BYTES = 5 * 1024 * 1024;
    var TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    var modalEl = document.getElementById('edm-asset-upload-modal');
    var formEl  = document.getElementById('edm-asset-upload-form');
    var fileEl  = document.getElementById('edm-asset-file');
    var nameEl  = document.getElementById('edm-asset-name');
    var prevEl  = document.getElementById('edm-asset-preview');
    var imgEl   = document.getElementById('edm-asset-preview-img');
    var errEl   = document.getElementById('edm-asset-upload-error');
    var saveBtn = document.getElementById('edm-asset-upload-save');
    var modal   = null;
    var objectUrl = null;

    function showError(msg) {
        errEl.textContent = msg;
        errEl.hidden = false;
    }

    function clearPreview() {
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        imgEl.removeAttribute('src');
        prevEl.hidden = true;
    }

    // Checked again on the server (content sniffing); this only saves a round trip.
    function checkFile(file) {
        if (!file) { return 'Choose an image to upload.'; }
        if (TYPES.indexOf(file.type) === -1) { return 'Only JPG, PNG, GIF or WebP images can be uploaded.'; }
        if (file.size > MAX_BYTES) { return 'The image must not be larger than 5 MB.'; }
        return null;
    }

    document.getElementById('edm-asset-upload-btn').addEventListener('click', function () {
        if (!modal) { modal = new bootstrap.Modal(modalEl); }
        formEl.reset();
        errEl.hidden = true;
        clearPreview();
        modal.show();
    });

    fileEl.addEventListener('change', function () {
        errEl.hidden = true;
        clearPreview();
        var file = fileEl.files[0];
        var problem = checkFile(file);
        if (problem) { showError(problem); return; }
        objectUrl = URL.createObjectURL(file);
        imgEl.src = objectUrl;
        prevEl.hidden = false;
    });

    modalEl.addEventListener('hidden.bs.modal', clearPreview);

    formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        errEl.hidden = true;
        var file = fileEl.files[0];
        var problem = checkFile(file);
        if (problem) { showError(problem); return; }

        var body = new FormData();
        body.append('file', file);
        if (nameEl.value.trim() !== '') { body.append('name', nameEl.value.trim()); }

        saveBtn.disabled = true;
        saveBtn.textContent = 'Uploading...';
        fetch(BASE + 'assets/api.php?action=assets_upload', { method: 'POST', body: body, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Upload';
                if (!res.success) { showError(res.message || 'Upload failed.'); return; }
                modal.hide();
                if (window.edmCrudReload) { window.edmCrudReload(); }
            })
            .catch(function () {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Upload';
                showError('Could not reach the server.');
            });
    });
})();

window.EDM_CRUD_CONFIG = {
    api: 'assets/api.php',
    entity: 'file',
    actions: { list: 'assets_list', create: 'assets_create', update: 'assets_update', 'delete': 'assets_delete' },
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'url', label: 'URL' },
        { key: 'type', label: 'Type' },
        { key: 'created_at', label: 'Added' }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'url', label: 'URL', type: 'text', required: true },
        { name: 'type', label: 'Type', type: 'text', help: 'e.g. image' }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
