<?php
$page_title = 'Files';
$page_subtitle = 'Images and banners for EDM artwork. Direct upload is a later feature; register a hosted URL for now.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('Add file');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Name', 'URL', 'Type', 'Added'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'assets/api.php',
    entity: 'file',
    actions: { list: 'assets_list', create: 'assets_create', update: 'assets_update', 'delete': 'assets_delete' },
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'url', label: 'URL' },
        { key: 'type', label: 'Type' },
        { key: 'created_at', label: 'Added' }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'url', label: 'URL', type: 'text', required: true },
        { name: 'type', label: 'Type', type: 'text', help: 'e.g. image/png' }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
