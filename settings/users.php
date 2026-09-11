<?php
$page_title = 'Users & permissions';
$page_subtitle = 'EDM access tier per staff member (staff.edm). 1 superadmin, 2 admin, 3 BPT team, 4 management. Set to 0 to remove access.';
include __DIR__ . '/../header.php';
require __DIR__ . '/../partials.php';

if (empty($_is_superadmin) && (int)$edm_permission !== 1) {
    if (function_exists('ob_get_level') && ob_get_level() > 0) { ob_end_clean(); }
    header('Location: ' . EDM_BASE . 'dashboard/index.php');
    exit;
}
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Staff', 'Tier'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'settings/api.php',
    entity: 'user',
    idKey: 'id',
    noCreate: true,
    noDelete: true,
    actions: { list: 'users_list', update: 'users_update' },
    columns: [
        { key: 'nama_staff', label: 'Staff' },
        { key: 'edm', label: 'Tier' }
    ],
    fields: [
        { name: 'edm', label: 'Access tier', type: 'select', options: [
            { value: '0', label: '0 - none' },
            { value: '1', label: '1 - superadmin' },
            { value: '2', label: '2 - admin' },
            { value: '3', label: '3 - BPT team' },
            { value: '4', label: '4 - management' }
        ] }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
