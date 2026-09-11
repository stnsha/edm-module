<?php
$page_title = 'Custom fields';
$page_subtitle = 'Field definitions used in segments and email personalisation. Values come from the Customer Data Warehouse.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New field');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Label', 'Key', 'Type'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'audience/api.php',
    entity: 'field',
    actions: { list: 'fields_list', create: 'fields_create', update: 'fields_update', 'delete': 'fields_delete' },
    columns: [
        { key: 'label', label: 'Label' },
        { key: 'key', label: 'Key' },
        { key: 'type', label: 'Type' }
    ],
    fields: [
        { name: 'label', label: 'Label', type: 'text', required: true },
        { name: 'type', label: 'Type', type: 'select', options: ['text', 'number', 'date', 'boolean', 'select'] },
        { name: 'options', label: 'Options (one per line, for select type)', type: 'textarea', help: 'Ignored unless Type is select.' }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
