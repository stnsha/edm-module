<?php
$page_title = 'Templates';
$page_subtitle = 'Reusable email layouts. Pick one when creating a newsletter, or use Start from template in the Email creator.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New template');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Name', 'Category'),
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
    // Edit = the template editor page (templates/edit.php), which holds the
    // settings and the EmailBuilder.js design together, like the Email creator.
    noEdit: true,
    saveAndGo: {
        label: 'Design template',
        icon: 'bi-brush',
        link: function (r) { return 'templates/edit.php?template=' + r.id; }
    },
    rowActions: [
        { label: 'Edit', link: function (r) { return 'templates/edit.php?template=' + r.id; } }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'category', label: 'Category', type: 'text' },
        { name: 'thumbnail_url', label: 'Thumbnail URL', type: 'text' }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
