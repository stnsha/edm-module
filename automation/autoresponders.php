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
    badges: { status: { draft: 'edm-pill-secondary', active: 'edm-pill-success', paused: 'edm-pill-warning' } },
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'offset_days', label: 'Offset (days)' },
        { key: 'subject', label: 'Subject' },
        { key: 'status', label: 'Status', type: 'badge' }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'offset_days', label: 'Offset in days from trigger', type: 'number', default: 0 },
        { name: 'subject', label: 'Subject', type: 'text' },
        { name: 'status', label: 'Status', type: 'select', options: ['draft', 'active', 'paused'] }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
