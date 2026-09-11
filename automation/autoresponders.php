<?php
$page_title = 'Autoresponders';
$page_subtitle = 'Time-based drip messages within a journey (day offset from the trigger).';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New autoresponder');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Name', 'Offset (days)', 'Subject', 'Status'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'automation/api.php',
    entity: 'autoresponder',
    actions: { list: 'autoresponders_list', create: 'autoresponders_create', update: 'autoresponders_update', 'delete': 'autoresponders_delete' },
    badges: { status: {
        1: { cls: 'edm-pill-secondary', label: 'Draft' },
        2: { cls: 'edm-pill-success', label: 'Active' },
        3: { cls: 'edm-pill-warning', label: 'Paused' }
    } },
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'offset_days', label: 'Offset (days)' },
        { key: 'subject', label: 'Subject' },
        { key: 'status', label: 'Status', type: 'badge' }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'offset_days', label: 'Offset in days from trigger', type: 'number', required: true, default: 0 },
        { name: 'subject', label: 'Subject', type: 'text' },
        { name: 'status', label: 'Status', type: 'select', options: [
            { value: 1, label: 'Draft' }, { value: 2, label: 'Active' }, { value: 3, label: 'Paused' }
        ] }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
