<?php
$page_title = 'Lists';
$page_subtitle = 'Contact lists. Members are keyed by warehouse member code and synced by a later feature.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New list');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Name', 'Description', 'Members', 'Active'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'audience/api.php',
    entity: 'list',
    actions: { list: 'lists_list', create: 'lists_create', update: 'lists_update', 'delete': 'lists_delete' },
    rowActions: [
        { label: function (r) { return r.is_active ? 'Set inactive' : 'Set active'; },
          className: 'btn-outline-secondary',
          body: function (r) { return { is_active: !r.is_active }; },
          action: 'lists_update', method: 'PUT' }
    ],
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'description', label: 'Description' },
        { key: 'members_count', label: 'Members', type: 'count' },
        { key: 'is_active', label: 'Active', type: 'bool', trueLabel: 'Active', falseLabel: 'Inactive' }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'description', label: 'Description', type: 'textarea' },
        { name: 'is_active', label: 'Active', type: 'checkbox', default: true }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
