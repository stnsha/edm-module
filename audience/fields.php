<?php
$page_title = 'Custom fields';
$page_subtitle = 'Field definitions used in segments and email personalisation. Each active field appears as a {{key}} variable in the Personalisation box of the Email creator. Values come from the Customer Data Warehouse.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New field');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

require __DIR__ . '/../app/bootstrap.php';
$edm_field_categories = array(array('value' => '', 'label' => 'None (Custom fields)'));
foreach (\Edm\Models\CustomField::CATEGORIES as $category) {
    $edm_field_categories[] = array('value' => $category, 'label' => $category);
}

edm_crud_screen(array(
    'columns' => array('Label', 'Key', 'Type', 'Category', 'Active'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'audience/api.php',
    entity: 'field',
    actions: { list: 'fields_list', create: 'fields_create', update: 'fields_update', 'delete': 'fields_delete' },
    rowActions: [
        { label: function (r) { return r.is_active ? 'Set inactive' : 'Set active'; },
          body: function (r) { return { is_active: !r.is_active }; },
          action: 'fields_update', method: 'PUT' }
    ],
    columns: [
        { key: 'label', label: 'Label' },
        { key: 'key', label: 'Key' },
        { key: 'type', label: 'Type' },
        { key: 'category', label: 'Category', value: function (r) { return r.category || ''; } },
        { key: 'is_active', label: 'Active', type: 'bool', trueLabel: 'Active', falseLabel: 'Inactive' }
    ],
    fields: [
        { name: 'label', label: 'Label', type: 'text', required: true },
        { name: 'type', label: 'Type', type: 'select', required: true, options: ['text', 'number', 'date', 'boolean', 'select'] },
        { name: 'options', label: 'Options (one per line, for select type)', type: 'textarea', help: 'Ignored unless Type is select.' },
        { name: 'category', label: 'Category', type: 'select', options: <?php echo json_encode($edm_field_categories); ?>,
          help: 'Audience Builder filter category: groups the field in the segment condition picker.' },
        { name: 'is_active', label: 'Active', type: 'checkbox', default: true }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
