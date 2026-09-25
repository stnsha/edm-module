<?php
$page_title = 'Custom fields';
$page_subtitle = 'Field definitions used in segments and email personalisation. Each active field appears as a {{key}} variable in the Personalisation box of the Email creator. Values come from the Customer Data Warehouse.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New field');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Label', 'Key', 'Type', 'Active'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'audience/api.php',
    entity: 'field',
    actions: { list: 'fields_list', create: 'fields_create', update: 'fields_update', 'delete': 'fields_delete' },
    rowActions: [
        { label: function (r) { return r.is_active ? 'Set inactive' : 'Set active'; },
          className: function (r) { return r.is_active ? 'btn-outline-danger' : 'btn-outline-success'; },
          body: function (r) { return { is_active: !r.is_active }; },
          action: 'fields_update', method: 'PUT' }
    ],
    columns: [
        { key: 'label', label: 'Label' },
        { key: 'key', label: 'Key' },
        { key: 'type', label: 'Type' },
        { key: 'is_active', label: 'Active', type: 'bool', trueLabel: 'Active', falseLabel: 'Inactive' }
    ],
    fields: [
        { name: 'label', label: 'Label', type: 'text', required: true },
        { name: 'type', label: 'Type', type: 'select', required: true, options: ['text', 'number', 'date', 'boolean', 'select'] },
        { name: 'options', label: 'Options (one per line, for select type)', type: 'textarea', help: 'Ignored unless Type is select.' },
        { name: 'is_active', label: 'Active', type: 'checkbox', default: true }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
