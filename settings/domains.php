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
    rowActions: [
        { label: function (r) { return r.is_active ? 'Set inactive' : 'Set active'; },
          className: 'btn-outline-secondary',
          body: function (r) { return { is_active: !r.is_active }; },
          action: 'domains_update', method: 'PUT' }
    ],
    badges: {
        dkim_status:  { 1: { cls: 'edm-pill-secondary', label: 'Pending' }, 2: { cls: 'edm-pill-success', label: 'Verified' }, 3: { cls: 'edm-pill-danger', label: 'Failed' } },
        spf_status:   { 1: { cls: 'edm-pill-secondary', label: 'Pending' }, 2: { cls: 'edm-pill-success', label: 'Verified' }, 3: { cls: 'edm-pill-danger', label: 'Failed' } },
        dmarc_status: { 1: { cls: 'edm-pill-secondary', label: 'Pending' }, 2: { cls: 'edm-pill-success', label: 'Verified' }, 3: { cls: 'edm-pill-danger', label: 'Failed' } }
    },
    columns: [
        { key: 'domain', label: 'Domain' },
        { key: 'dkim_status', label: 'DKIM', type: 'badge' },
        { key: 'spf_status', label: 'SPF', type: 'badge' },
        { key: 'dmarc_status', label: 'DMARC', type: 'badge' },
        { key: 'is_active', label: 'Active', type: 'bool', trueLabel: 'Active', falseLabel: 'Inactive' }
    ],
    fields: [
        { name: 'domain', label: 'Domain', type: 'text', required: true },
        { name: 'dkim_status', label: 'DKIM status', type: 'select', options: [{ value: 1, label: 'Pending' }, { value: 2, label: 'Verified' }, { value: 3, label: 'Failed' }] },
        { name: 'spf_status', label: 'SPF status', type: 'select', options: [{ value: 1, label: 'Pending' }, { value: 2, label: 'Verified' }, { value: 3, label: 'Failed' }] },
        { name: 'dmarc_status', label: 'DMARC status', type: 'select', options: [{ value: 1, label: 'Pending' }, { value: 2, label: 'Verified' }, { value: 3, label: 'Failed' }] },
        { name: 'is_active', label: 'Active', type: 'checkbox', default: true }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
