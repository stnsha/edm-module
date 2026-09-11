<?php
$page_title = 'Segments';
$page_subtitle = 'Saved audience filters. Conditions resolve against the Customer Data Warehouse when a campaign uses the segment.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New segment');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns'    => array('Name', 'Description'),
    'modal_size' => 'modal-lg',
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'audience/api.php',
    entity: 'segment',
    actions: { list: 'segments_list', create: 'segments_create', update: 'segments_update', 'delete': 'segments_delete' },
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'description', label: 'Description' }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'description', label: 'Description', type: 'textarea' },
        { name: 'definition', label: 'Conditions', type: 'rules', required: true }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
