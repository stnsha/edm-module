<?php
$page_title = 'Integrations & API';
$page_subtitle = 'Amazon SES connection status, plus key/value configuration for third-party services.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('Add setting');
include __DIR__ . '/../header.php';

if (empty($_is_superadmin) && (int)$edm_permission !== 1) {
    if (function_exists('ob_get_level') && ob_get_level() > 0) { ob_end_clean(); }
    header('Location: ' . EDM_BASE . 'dashboard/index.php');
    exit;
}
$page_js = EDM_BASE . 'js/edm-crud.js';
?>
<!-- Amazon SES. Credentials and settings live in edm/.env (not editable here,
     so the secret key never reaches the browser); this panel shows what is
     configured and the live account state (settings/api.php ses_status). -->
<div class="edm-card mb-4" id="edm-ses-panel">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
        <h2 class="edm-card-title mb-0 pb-0"><i class="bi bi-envelope-at"></i> Amazon SES</h2>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="edm-ses-refresh">
            <i class="bi bi-arrow-repeat me-1"></i>Test connection
        </button>
    </div>
    <div id="edm-ses-body" class="small text-muted">Checking...</div>
    <p class="edm-card-hint mb-0">
        Configured in <code>edm/.env</code> (template: <code>.env.example</code>). SNS webhook URL:
        <code class="user-select-all"><?php echo htmlspecialchars(EDM_BASE . 'public/ses-webhook.php'); ?></code>
        on this server's public HTTPS address. Send queue: <code>cron/edm-send.bat</code> every minute.
    </p>
</div>
<script>
(function () {
    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var bodyEl = document.getElementById('edm-ses-body');
    var btn = document.getElementById('edm-ses-refresh');
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function row(label, value) {
        return '<tr><th class="text-muted fw-normal pe-3" style="width:14rem;">' + esc(label) + '</th><td>' + value + '</td></tr>';
    }
    function pill(ok, yes, no) {
        return '<span class="edm-pill ' + (ok ? 'edm-pill-success' : 'edm-pill-warning') + '">' + esc(ok ? yes : no) + '</span>';
    }
    function unset() { return '<span class="text-danger">not set</span>'; }
    function load() {
        btn.disabled = true;
        bodyEl.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Checking...';
        fetch(BASE + 'settings/api.php?action=ses_status', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                btn.disabled = false;
                if (!res.success) { bodyEl.innerHTML = '<div class="alert alert-danger py-2 px-3 mb-0">' + esc(res.message) + '</div>'; return; }
                var c = res.data.config, a = res.data.account;
                var html = '';
                if (res.data.error) {
                    html += '<div class="alert alert-danger py-2 px-3 mb-2">Not connected. ' + esc(res.data.error) + '</div>';
                } else if (a) {
                    html += '<div class="alert alert-success py-2 px-3 mb-2">Connected to Amazon SES.</div>';
                }
                if (c.missing.length) {
                    html += '<div class="alert alert-warning py-2 px-3 mb-2">Bulk sending is off until these are set in edm/.env: ' + esc(c.missing.join(', ')) + '.</div>';
                }
                html += '<table class="table table-sm mb-2"><tbody>' +
                    row('Region', esc(c.region)) +
                    row('Credentials', esc(c.credentials)) +
                    row('Configuration set', c.configuration_set ? esc(c.configuration_set) : '<span class="text-warning-emphasis">not set - no delivery / open / click events</span>') +
                    row('SNS topic', c.sns_topic_arn ? esc(c.sns_topic_arn) : '<span class="text-warning-emphasis">not set - webhook rejects everything</span>') +
                    row('Public URL', c.public_url ? esc(c.public_url) : unset());
                if (a) {
                    html +=
                        row('Account', pill(a.production_access, 'Production', 'Sandbox - only verified recipients')) +
                        row('Sending', pill(a.sending_enabled, 'Enabled', 'Disabled') + (a.enforcement ? ' <span class="text-muted">' + esc(a.enforcement) + '</span>' : '')) +
                        row('24-hour quota', esc(Math.round(a.sent_24h)) + ' / ' + esc(Math.round(a.max_24h)) + ' sent') +
                        row('Max send rate', esc(a.max_rate) + ' emails / second');
                }
                bodyEl.innerHTML = html + '</tbody></table>';
            })
            .catch(function () { btn.disabled = false; bodyEl.innerHTML = '<div class="alert alert-danger py-2 px-3 mb-0">Could not reach the server.</div>'; });
    }
    btn.addEventListener('click', load);
    load();
})();
</script>
<?php
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
        { name: 'key', label: 'Key', type: 'text', required: true, help: 'Other services only - Amazon SES is configured in edm/.env' },
        { name: 'label', label: 'Label', type: 'text' },
        { name: 'value', label: 'Value', type: 'textarea' }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
