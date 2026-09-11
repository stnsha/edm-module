<?php
$page_title = 'Tags & scoring';
$page_subtitle = 'Labels applied to contacts for segmentation and journey triggers.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New tag');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Name', 'Colour', 'Description'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'audience/api.php',
    entity: 'tag',
    actions: { list: 'tags_list', create: 'tags_create', update: 'tags_update', 'delete': 'tags_delete' },
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'color', label: 'Colour' },
        { key: 'description', label: 'Description' }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'color', label: 'Colour', type: 'text', help: 'Hex or CSS colour name, e.g. #0d6efd.' },
        { name: 'description', label: 'Description', type: 'text' }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
