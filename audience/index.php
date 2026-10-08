<?php
$page_title = 'All contacts';
$page_subtitle = 'The contact masterlist: one record per email address. Add or update contacts with Import (file or paste); newsletters go to every subscribed contact or to a segment.';
require __DIR__ . '/../partials.php';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';
?>
<div class="d-flex justify-content-end mb-3">
    <a class="btn btn-outline-primary" href="<?php echo EDM_BASE; ?>audience/import.php"><i class="bi bi-upload me-1"></i>Import contacts</a>
</div>
<?php
edm_crud_screen(array(
    'columns' => array('Email', 'Name', 'Status', 'Source', 'Imported on'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'audience/api.php',
    entity: 'contact',
    actions: { list: 'contacts_list', 'delete': 'contacts_delete' },
    noCreate: true,
    noEdit: true,
    columns: [
        { key: 'email', label: 'Email' },
        { key: 'name', label: 'Name' },
        { key: 'status', label: 'Status', type: 'badge' },
        { key: 'source', label: 'Source' },
        { key: 'created_at', label: 'Imported on' }
    ],
    badges: {
        status: {
            1: { cls: 'edm-pill-success', label: 'Subscribed' },
            2: { cls: 'edm-pill-secondary', label: 'Unsubscribed' },
            3: { cls: 'edm-pill-danger', label: 'Bounced' }
        }
    },
    fields: []
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
