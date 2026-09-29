<?php
$page_title = 'Files';
$page_subtitle = 'Images and banners for EDM artwork.';
require __DIR__ . '/../partials.php';
$page_title_actions = '<button type="button" class="btn btn-primary d-inline-flex align-items-center" id="edm-asset-upload-btn">'
    . '<i class="bi bi-upload me-1"></i>Upload images</button>';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Name', 'URL', 'Type', 'Added'),
));
?>
<!-- Upload images: drop / browse (each file posts to assets_upload with its
     own progress bar) or import from a URL (assets_create). assets/upload.js. -->
<div class="modal fade" id="edm-asset-upload-modal" tabindex="-1" aria-labelledby="edm-up-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered edm-up-dialog">
        <div class="modal-content edm-up">
            <div class="edm-up-head">
                <h2 class="edm-up-title" id="edm-up-title">Upload images</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <label class="edm-up-drop" id="edm-up-drop" for="edm-up-file">
                <span class="edm-up-art" aria-hidden="true"><i class="bi bi-image"></i><i class="bi bi-image"></i></span>
                <span class="edm-up-drop-text">Drop your images here, or <span class="edm-up-browse">browse</span></span>
                <span class="edm-up-drop-over"><i class="bi bi-chevron-double-right"></i> Drop your files here <i class="bi bi-chevron-double-left"></i></span>
                <span class="edm-up-hint">Supports: JPG, JPEG, PNG, GIF, WEBP - up to 5 MB each</span>
            </label>
            <input type="file" id="edm-up-file" class="visually-hidden" accept="image/jpeg,image/png,image/gif,image/webp" multiple>

            <ul class="edm-up-list" id="edm-up-list"></ul>

            <div class="edm-up-or"><span>or</span></div>

            <label class="edm-up-label" for="edm-up-url">Import from URL</label>
            <div class="edm-up-url">
                <input type="url" id="edm-up-url" placeholder="Add image URL, e.g. https://example.com/banner.jpg" autocomplete="off">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="edm-up-url-btn">Upload</button>
            </div>
            <div class="edm-up-url-error" id="edm-up-url-error" hidden></div>

            <div class="edm-up-foot">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="edm-up-cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="edm-up-done">Import</button>
            </div>
        </div>
    </div>
</div>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'assets/api.php',
    entity: 'file',
    actions: { list: 'assets_list', create: 'assets_create', update: 'assets_update', 'delete': 'assets_delete' },
    noCreate: true,
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
    ],
    // Edit: show the image above the fields; follows the URL as it is typed.
    onFormOpen: function (row, bodyEl) {
        var box = document.getElementById('edm-asset-edit-preview');
        if (!box) {
            box = document.createElement('div');
            box.id = 'edm-asset-edit-preview';
            box.className = 'edm-asset-edit-preview';
            box.innerHTML = '<img alt="Image preview"><span class="text-muted small" hidden>Image could not be loaded from this URL.</span>';
            bodyEl.insertBefore(box, bodyEl.firstChild);
            var img = box.querySelector('img');
            var msg = box.querySelector('span');
            img.addEventListener('load', function () { img.hidden = false; msg.hidden = true; });
            img.addEventListener('error', function () { img.hidden = true; msg.hidden = false; });
            document.getElementById('edm-f-url').addEventListener('input', function () { show(this.value.trim()); });
        }
        function show(url) {
            var img = box.querySelector('img');
            box.hidden = !url;
            if (url) { img.src = url; }
        }
        show(row && row.url ? row.url : '');
    }
};
</script>
<script src="<?php echo EDM_BASE; ?>assets/upload.js"></script>
<?php
include __DIR__ . '/../footer.php';
?>
