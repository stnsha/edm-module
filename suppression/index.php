<?php
$page_title = 'Suppression lists';
$page_subtitle = 'Addresses that are never sent to. Hard bounces and spam complaints are added automatically once SES events are wired; add manual entries here. Delete to recover an address.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('Add address');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Email', 'Reason', 'Source', 'Note', 'Added'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'suppression/api.php',
    entity: 'address',
    actions: { list: 'suppressions_list', create: 'suppressions_create', update: 'suppressions_update', 'delete': 'suppressions_delete' },
    badges: { reason: {
        unsubscribed: 'edm-pill-secondary', hard_bounce: 'edm-pill-danger', soft_bounce: 'edm-pill-warning',
        spam_complaint: 'edm-pill-danger', inactive: 'edm-pill-secondary', manual: 'edm-pill-dark'
    } },
    columns: [
        { key: 'email', label: 'Email' },
        { key: 'reason', label: 'Reason', type: 'badge' },
        { key: 'source', label: 'Source' },
        { key: 'note', label: 'Note' },
        { key: 'created_at', label: 'Added' }
    ],
    fields: [
        { name: 'email', label: 'Email', type: 'email', required: true },
        { name: 'reason', label: 'Reason', type: 'select', required: true, options: [
            { value: 'manual', label: 'Manual' },
            { value: 'unsubscribed', label: 'Unsubscribed' },
            { value: 'hard_bounce', label: 'Hard bounce' },
            { value: 'soft_bounce', label: 'Soft bounce' },
            { value: 'spam_complaint', label: 'Spam complaint' },
            { value: 'inactive', label: 'Inactive' }
        ] },
        { name: 'note', label: 'Note', type: 'text' }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
