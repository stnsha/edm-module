<?php
$page_title = 'Workflows';
$page_subtitle = 'Trigger-based automation journeys (Phase 2). The visual canvas is a later feature; define the journey shell here.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New workflow');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Name', 'Trigger', 'Status'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'automation/api.php',
    entity: 'workflow',
    actions: { list: 'workflows_list', create: 'workflows_create', update: 'workflows_update', 'delete': 'workflows_delete' },
    badges: { status: {
        1: { cls: 'edm-pill-secondary', label: 'Draft' },
        2: { cls: 'edm-pill-success', label: 'Active' },
        3: { cls: 'edm-pill-warning', label: 'Paused' }
    } },
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'trigger', label: 'Trigger' },
        { key: 'status', label: 'Status', type: 'badge' }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'description', label: 'Description', type: 'text' },
        { name: 'trigger', label: 'Trigger', type: 'select', required: true, options: [
            { value: 'new_member', label: 'New member registration' },
            { value: 'birthday', label: 'Birthday (DOB match)' },
            { value: 'inactivity', label: 'No activity for 90 days' },
            { value: 'cart_abandonment', label: 'Cart abandoned' },
            { value: 'soft_bounce', label: 'Soft bounce detected' },
            { value: 'manual', label: 'Manual' }
        ] },
        { name: 'status', label: 'Status', type: 'select', options: [
            { value: 1, label: 'Draft' }, { value: 2, label: 'Active' }, { value: 3, label: 'Paused' }
        ] }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
