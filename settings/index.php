<?php
$page_title = 'Settings';
$page_subtitle = 'Module configuration. Use the Settings menu for senders, sending domains, users and integrations.';
include __DIR__ . '/../header.php';
require __DIR__ . '/../partials.php';

if (empty($_is_superadmin) && (int)$edm_permission !== 1) {
    if (function_exists('ob_get_level') && ob_get_level() > 0) { ob_end_clean(); }
    header('Location: ' . EDM_BASE . 'dashboard/index.php');
    exit;
}
$page_js = EDM_BASE . 'js/edm-crud.js';
?>
<div class="d-flex flex-wrap gap-2 mb-4">
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo EDM_BASE; ?>settings/senders.php">Senders</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo EDM_BASE; ?>settings/domains.php">Sending domains</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo EDM_BASE; ?>settings/users.php">Users &amp; permissions</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo EDM_BASE; ?>settings/integrations.php">Integrations &amp; API</a>
</div>
<?php
edm_crud_screen(array(
    'title'         => 'General options',
    'subtitle'      => 'Notification preferences and general key/value options.',
    'add_label'     => 'Add option',
    'header_button' => false,
    'columns'       => array('Key', 'Label', 'Value'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'settings/api.php',
    entity: 'option',
    actions: { list: 'general_list', create: 'general_create', update: 'general_update', 'delete': 'general_delete' },
    columns: [
        { key: 'key', label: 'Key' },
        { key: 'label', label: 'Label' },
        { key: 'value', label: 'Value' }
    ],
    fields: [
        { name: 'key', label: 'Key', type: 'text', required: true },
        { name: 'label', label: 'Label', type: 'text' },
        { name: 'value', label: 'Value', type: 'textarea' }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
