<?php
$page_title = 'Sending domains';
$page_subtitle = 'Domains used to send from. Check with SES registers the domain in Amazon SES, refreshes DKIM / SPF / DMARC and lists the DNS records to publish.';
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
        { label: 'Check with SES', handler: function (r, reload) { edmDomainCheck(r, reload); } },
        { label: function (r) { return r.is_active ? 'Set inactive' : 'Set active'; },
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
        // DKIM / SPF / DMARC statuses come from "Check with SES", not the form.
        { name: 'domain', label: 'Domain', type: 'text', required: true, help: 'e.g. mail.example.com' },
        { name: 'is_active', label: 'Active', type: 'checkbox', default: true }
    ]
};
</script>

<!-- Check with SES result: status message + DNS records to publish. -->
<div class="modal fade" id="edm-dom-ses-modal" tabindex="-1" aria-labelledby="edm-dom-ses-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="edm-dom-ses-title">Amazon SES</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="edm-dom-ses-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
function edmDomainCheck(row, reload) {
    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var modalEl = document.getElementById('edm-dom-ses-modal');
    var bodyEl = document.getElementById('edm-dom-ses-body');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    document.getElementById('edm-dom-ses-title').textContent = 'Amazon SES - ' + row.domain;
    bodyEl.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm"></span> Checking...</div>';
    modal.show();
    fetch(BASE + 'settings/api.php?action=ses_domain_check', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ id: row.id })
    }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.success) {
            bodyEl.innerHTML = '<div class="alert alert-danger py-2 px-3 small mb-0">' + esc(res.message || 'Check failed.') + '</div>';
            return;
        }
        var d = res.data;
        var html = '<div class="alert alert-' + (d.domain.dkim_status === 2 ? 'success' : 'info') + ' py-2 px-3 small">' + esc(d.message) + '</div>';
        if (d.dns.length) {
            html += '<p class="small mb-2">Publish these records at the DNS provider for ' + esc(row.domain) + ':</p>' +
                '<div class="table-responsive"><table class="table table-sm align-middle edm-view-tbl small mb-0"><thead><tr>' +
                '<th>Purpose</th><th>Type</th><th>Name</th><th>Value</th></tr></thead><tbody>' +
                d.dns.map(function (x) {
                    return '<tr><td>' + esc(x.purpose) + '</td><td>' + esc(x.type) + '</td>' +
                        '<td><code class="user-select-all">' + esc(x.name) + '</code></td>' +
                        '<td><code class="user-select-all">' + esc(x.value) + '</code></td></tr>';
                }).join('') + '</tbody></table></div>';
        }
        bodyEl.innerHTML = html;
        reload();
    }).catch(function () {
        bodyEl.innerHTML = '<div class="alert alert-danger py-2 px-3 small mb-0">Could not reach the server.</div>';
    });
}
</script>
<?php
include __DIR__ . '/../footer.php';
?>
