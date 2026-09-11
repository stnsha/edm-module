<?php
$page_title = 'Integrations & API';
$page_subtitle = 'Key/value configuration for Amazon SES, webhook receivers and third-party services.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('Add setting');
include __DIR__ . '/../header.php';

if (empty($_is_superadmin) && (int)$edm_permission !== 1) {
    if (function_exists('ob_get_level') && ob_get_level() > 0) { ob_end_clean(); }
    header('Location: ' . EDM_BASE . 'dashboard/index.php');
    exit;
}
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Key', 'Label', 'Value'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'settings/api.php',
    entity: 'setting',
    actions: { list: 'integrations_list', create: 'integrations_create', update: 'integrations_update', 'delete': 'integrations_delete' },
    columns: [
        { key: 'key', label: 'Key' },
        { key: 'label', label: 'Label' },
        { key: 'value', label: 'Value' }
    ],
    fields: [
        { name: 'key', label: 'Key', type: 'text', required: true, help: 'e.g. ses_region, sns_topic_arn' },
        { name: 'label', label: 'Label', type: 'text' },
        { name: 'value', label: 'Value', type: 'textarea' }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
