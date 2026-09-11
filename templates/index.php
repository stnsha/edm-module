<?php
$page_title = 'Templates';
$page_subtitle = 'Reusable email layouts. Selected as the starting point in the newsletter wizard.';
$extra_css = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New template');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns'    => array('Name', 'Category'),
    'modal_size' => 'modal-lg',
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'templates/api.php',
    entity: 'template',
    actions: { list: 'templates_list', create: 'templates_create', update: 'templates_update', 'delete': 'templates_delete' },
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'category', label: 'Category' }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'category', label: 'Category', type: 'text' },
        { name: 'thumbnail_url', label: 'Thumbnail URL', type: 'text' },
        { name: 'html', label: 'HTML', type: 'richtext' }
    ]
};
</script>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<?php
include __DIR__ . '/../footer.php';
?>
