<?php
$page_title = 'Sending domains';
$page_subtitle = 'Domains used to send from, with DKIM / SPF / DMARC status. SES verification is not wired yet - set the status manually.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('Add domain');
include __DIR__ . '/../header.php';

if (empty($_is_superadmin) && (int)$edm_permission !== 1) {
    if (function_exists('ob_get_level') && ob_get_level() > 0) { ob_end_clean(); }
    header('Location: ' . EDM_BASE . 'dashboard/index.php');
    exit;
}
$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Domain', 'DKIM', 'SPF', 'DMARC', 'Active'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'settings/api.php',
    entity: 'domain',
    actions: { list: 'domains_list', create: 'domains_create', update: 'domains_update', 'delete': 'domains_delete' },
    badges: {
        dkim_status:  { pending: 'edm-pill-secondary', verified: 'edm-pill-success', failed: 'edm-pill-danger' },
        spf_status:   { pending: 'edm-pill-secondary', verified: 'edm-pill-success', failed: 'edm-pill-danger' },
        dmarc_status: { pending: 'edm-pill-secondary', verified: 'edm-pill-success', failed: 'edm-pill-danger' }
    },
    columns: [
        { key: 'domain', label: 'Domain' },
        { key: 'dkim_status', label: 'DKIM', type: 'badge' },
        { key: 'spf_status', label: 'SPF', type: 'badge' },
        { key: 'dmarc_status', label: 'DMARC', type: 'badge' },
        { key: 'is_active', label: 'Active', type: 'bool' }
    ],
    fields: [
        { name: 'domain', label: 'Domain', type: 'text', required: true },
        { name: 'dkim_status', label: 'DKIM status', type: 'select', options: ['pending', 'verified', 'failed'] },
        { name: 'spf_status', label: 'SPF status', type: 'select', options: ['pending', 'verified', 'failed'] },
        { name: 'dmarc_status', label: 'DMARC status', type: 'select', options: ['pending', 'verified', 'failed'] },
        { name: 'is_active', label: 'Active', type: 'checkbox', default: true }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
