<?php
$page_title = 'Newsletters';
$page_subtitle = 'One-time email campaigns. Create a newsletter, design it in the Email creator, then submit it for review.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New newsletter');
include __DIR__ . '/../header.php';

// Server-side lookups for the sender / list selects.
require __DIR__ . '/../app/bootstrap.php';

$edm_sender_opts = array(array('value' => '', 'label' => '(none)'));
$edm_list_opts   = array(array('value' => '', 'label' => '(none)'));

foreach (\Edm\Models\Sender::all() as $row) {
    $edm_sender_opts[] = array('value' => $row['id'], 'label' => $row['from_name'] . ' <' . $row['email'] . '>');
}
foreach (\Edm\Models\ContactList::all() as $row) {
    $edm_list_opts[] = array('value' => $row['id'], 'label' => $row['name']);
}

$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns'    => array('Name', 'Subject', 'List', 'Status', 'Scheduled'),
    'modal_size' => 'modal-lg',
));
?>
<!-- Preview (email HTML is served by campaign/api.php?action=campaigns_preview
     with a CSP sandbox header; the iframe is sandboxed as well). -->
<div class="modal fade" id="edm-nl-preview-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="edm-nl-preview-title">Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe class="edm-nl-preview-frame" id="edm-nl-preview-frame" sandbox="" title="Newsletter preview"></iframe>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var previewModalEl = document.getElementById('edm-nl-preview-modal');
    var previewFrame   = document.getElementById('edm-nl-preview-frame');
    var previewModal   = null;

    previewModalEl.addEventListener('hidden.bs.modal', function () {
        previewFrame.src = 'about:blank';
    });

    function openPreview(r) {
        if (!previewModal) { previewModal = new bootstrap.Modal(previewModalEl); }
        document.getElementById('edm-nl-preview-title').textContent = r.name + (r.subject ? ' - ' + r.subject : '');
        previewFrame.src = BASE + 'campaign/api.php?action=campaigns_preview&id=' + r.id;
        previewModal.show();
    }

    // Draft (1) and content revision (4) are the only states still being worked
    // on; later states are in review / scheduled / sent and stay read-only here.
    function editable(r) { return r.status === 1 || r.status === 4; }

    window.EDM_CRUD_CONFIG = {
        api: 'campaign/api.php',
        entity: 'newsletter',
        actions: { list: 'campaigns_list', create: 'campaigns_create', update: 'campaigns_update', 'delete': 'campaigns_delete' },
        badges: { status: {
            1: { cls: 'edm-pill-secondary', label: 'Draft' },
            2: { cls: 'edm-pill-info', label: 'Pending submission' },
            3: { cls: 'edm-pill-info', label: 'Under BPT review' },
            4: { cls: 'edm-pill-warning', label: 'Content revision' },
            5: { cls: 'edm-pill-info', label: 'Audience validation' },
            6: { cls: 'edm-pill-primary', label: 'Scheduled' },
            7: { cls: 'edm-pill-primary', label: 'Sending' },
            8: { cls: 'edm-pill-success', label: 'Completed' },
            9: { cls: 'edm-pill-dark', label: 'Archived' }
        } },
        actionMenu: true,
        // Edit = the Email creator page, which holds the settings and the
        // design together; the list's own edit modal is not used.
        noEdit: true,
        saveAndGo: {
            label: 'Design message',
            icon: 'bi-brush',
            link: function (r) { return 'email-builder/index.php?campaign=' + r.id; }
        },
        rowActions: [
            { label: 'Edit', visible: editable,
                link: function (r) { return 'email-builder/index.php?campaign=' + r.id; } },
            { label: 'Submit', className: 'btn-outline-success', action: 'campaigns_submit', confirm: 'Submit this newsletter for review?',
                visible: editable },
            { label: 'Preview', handler: function (r) { openPreview(r); } },
            { label: 'Reuse', action: 'campaigns_duplicate', confirm: 'Create a copy of this newsletter as a new draft?' },
            { label: 'Stop sending', className: 'btn-outline-danger', action: 'campaigns_stop',
                confirm: 'Stop this newsletter? A scheduled newsletter goes back to Draft; one already sending is closed as completed.',
                visible: function (r) { return r.status === 6 || r.status === 7; } }
        ],
        columns: [
            { key: 'name', label: 'Name' },
            { key: 'subject', label: 'Subject' },
            { key: 'list', label: 'List', value: function (r) { return r.list ? r.list.name : ''; } },
            { key: 'status', label: 'Status', type: 'badge' },
            { key: 'scheduled_at', label: 'Scheduled' }
        ],
        fields: [
            { name: 'name', label: 'Name', type: 'text', required: true },
            { name: 'sender_id', label: 'Sender', type: 'select', options: <?php echo json_encode($edm_sender_opts); ?> },
            { name: 'list_id', label: 'Recipient list', type: 'select', options: <?php echo json_encode($edm_list_opts); ?> },
            { name: 'subject', label: 'Subject line', type: 'text' },
            { name: 'scheduled_at', label: 'Scheduled send', type: 'datetime' }
        ]
    };
})();
</script>
<?php
include __DIR__ . '/../footer.php';
?>
