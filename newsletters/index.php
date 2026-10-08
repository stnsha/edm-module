<?php
$page_title = 'Newsletters';
$page_subtitle = 'One-time email newsletters. Create a newsletter, design it in the Email creator, then submit it for review.';
require __DIR__ . '/../partials.php';
// New newsletter: creates an empty draft and opens it in the Email creator
// (no pop-up form); settings are filled in there.
$page_title_actions = edm_title_button('New newsletter', 'edm-nl-new');
include __DIR__ . '/../header.php';

$page_js = EDM_BASE . 'js/edm-crud.js';
?>
<!-- Newsletters / Archived tabs, as atem/view.php HQ / Outlet: archived
     newsletters (status 9) are listed only under Archived (campaigns_list
     ?archived=1) and can be unarchived there. -->
<ul class="nav nav-tabs edm-view-tabs mb-3" id="edm-newsletter-tabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active edm-tab-color-outlet" type="button" role="tab" data-view="active" aria-selected="true">
            <i class="bi bi-envelope"></i> Newsletters <span class="edm-tab-count" id="edm-tab-active-count">0</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link edm-tab-color-hq" type="button" role="tab" data-view="archived" aria-selected="false">
            <i class="bi bi-archive"></i> Archived <span class="edm-tab-count" id="edm-tab-archived-count">0</span>
        </button>
    </li>
</ul>
<?php
edm_crud_screen(array(
    'columns'    => array('Name', 'Subject', 'Tags', 'Audience', 'Status', 'Scheduled'),
    'modal_size' => 'modal-lg',
));
?>
<!-- Preview (email HTML is served by newsletters/api.php?action=campaigns_preview
     with a CSP sandbox header; the iframe is sandboxed as well). -->
<div class="modal fade" id="edm-nl-preview-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="edm-nl-preview-title">Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe class="edm-nl-preview-frame" id="edm-nl-preview-frame" sandbox="" title="Newsletter preview"></iframe>
            </div>
        </div>
    </div>
</div>
<!-- Show recipients (campaigns_recipients): who the audience resolves to
     now, or once sending has started, who it was sent to and how it went. -->
<div class="modal fade" id="edm-nl-rcpt-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="edm-nl-rcpt-title">Recipients</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <div class="small text-muted" id="edm-nl-rcpt-summary">Loading...</div>
                    <input type="search" class="form-control form-control-sm" id="edm-nl-rcpt-search" placeholder="Search email or name" style="max-width: 16rem;" autocomplete="off">
                </div>
                <div class="table-responsive">
                    <table class="table table-sm edm-view-tbl mb-0">
                        <thead id="edm-nl-rcpt-head"></thead>
                        <tbody id="edm-nl-rcpt-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var previewModalEl = document.getElementById('edm-nl-preview-modal');
    var previewFrame   = document.getElementById('edm-nl-preview-frame');
    var previewModal   = null;

    previewModalEl.addEventListener('hidden.bs.modal', function () {
        previewFrame.src = 'about:blank';
    });

    function openPreview(r) {
        if (!previewModal) { previewModal = new bootstrap.Modal(previewModalEl); }
        document.getElementById('edm-nl-preview-title').textContent = r.name + (r.subject ? ' - ' + r.subject : '');
        previewFrame.src = BASE + 'newsletters/api.php?action=campaigns_preview&id=' + r.id;
        previewModal.show();
    }

    // Show recipients: one modal, filled per newsletter.
    var rcptModalEl = document.getElementById('edm-nl-rcpt-modal');
    var rcptModal   = null;
    var rcptRows    = [];
    var rcptSource  = 'audience';
    var RCPT_PILLS  = { 1: 'edm-pill-primary', 2: 'edm-pill-success', 3: 'edm-pill-danger', 4: 'edm-pill-danger', 5: 'edm-pill-danger', 6: 'edm-pill-secondary' };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function paintRecipients() {
        var q = document.getElementById('edm-nl-rcpt-search').value.trim().toLowerCase();
        var rows = rcptRows.filter(function (r) {
            return !q || String(r.email).toLowerCase().indexOf(q) !== -1 || String(r.name || '').toLowerCase().indexOf(q) !== -1;
        });
        var sent = rcptSource === 'sent';
        document.getElementById('edm-nl-rcpt-head').innerHTML = sent
            ? '<tr><th style="width:3rem">#</th><th>Email</th><th>Status</th><th>Sent at</th></tr>'
            : '<tr><th style="width:3rem">#</th><th>Email</th><th>Name</th><th>Status</th></tr>';
        document.getElementById('edm-nl-rcpt-body').innerHTML = rows.length ? rows.map(function (r, i) {
            if (sent) {
                return '<tr><td>' + (i + 1) + '</td><td>' + esc(r.email) + '</td><td><span class="edm-pill ' + (RCPT_PILLS[r.status] || 'edm-pill-secondary') + '"' +
                    (r.error ? ' title="' + esc(r.error) + '"' : '') + '>' + esc(r.label) + '</span>' +
                    (r.error ? '<div class="small text-muted">' + esc(r.error) + '</div>' : '') + '</td><td>' + esc(r.sent_at || '-') + '</td></tr>';
            }
            return '<tr><td>' + (i + 1) + '</td><td>' + esc(r.email) + '</td><td>' + esc(r.name || '-') + '</td><td>' +
                (r.suppressed
                    ? '<span class="edm-pill edm-pill-warning" title="On the exclusion list - skipped at send time">Excluded</span>'
                    : '<span class="edm-pill edm-pill-success">Will receive</span>') + '</td></tr>';
        }).join('') : '<tr><td colspan="4" class="text-center text-muted py-3">' + (q ? 'No recipient matches the search.' : 'No recipients.') + '</td></tr>';
    }

    function openRecipients(r) {
        if (!rcptModal) { rcptModal = new bootstrap.Modal(rcptModalEl); }
        document.getElementById('edm-nl-rcpt-title').textContent = 'Recipients - ' + r.name;
        document.getElementById('edm-nl-rcpt-summary').textContent = 'Loading...';
        document.getElementById('edm-nl-rcpt-search').value = '';
        document.getElementById('edm-nl-rcpt-head').innerHTML = '';
        document.getElementById('edm-nl-rcpt-body').innerHTML = '';
        rcptModal.show();
        fetch(BASE + 'newsletters/api.php?action=campaigns_recipients&id=' + r.id, { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (!res.success) {
                    document.getElementById('edm-nl-rcpt-summary').textContent = res.message || 'Could not load the recipients.';
                    return;
                }
                rcptSource = res.data.source;
                rcptRows = res.data.rows || [];
                var shown = rcptRows.length < res.data.total ? ' (first ' + rcptRows.length + ' shown)' : '';
                document.getElementById('edm-nl-rcpt-summary').textContent = rcptSource === 'sent'
                    ? res.data.total + ' recipient(s) in the send log' + shown + '.'
                    : res.data.total + ' subscribed contact(s) match the audience now' + shown + '. The list is worked out again when the newsletter is sent.';
                paintRecipients();
            })
            .catch(function () {
                document.getElementById('edm-nl-rcpt-summary').textContent = 'Could not load the recipients.';
            });
    }
    document.getElementById('edm-nl-rcpt-search').addEventListener('input', paintRecipients);

    // Draft (1) and content revision (4) are the only states still being worked
    // on; later states are in review / scheduled / sent and stay read-only here.
    function editable(r) { return r.status === 1 || r.status === 4; }

    document.getElementById('edm-nl-new').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        fetch(BASE + 'newsletters/api.php?action=campaigns_new', { method: 'POST', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    btn.disabled = false;
                    var el = document.getElementById('edm-crud-alert');
                    el.textContent = res.message || 'Could not create the newsletter.';
                    el.hidden = false;
                    return;
                }
                window.location.href = BASE + 'email-builder/index.php?newsletter=' + res.data.id;
            })
            .catch(function () { btn.disabled = false; });
    });

    window.EDM_CRUD_CONFIG = {
        api: 'newsletters/api.php',
        entity: 'newsletter',
        actions: { list: 'campaigns_list', create: 'campaigns_create', update: 'campaigns_update', 'delete': 'campaigns_delete' },
        badges: { status: {
            1: { cls: 'edm-pill-secondary', label: 'Draft' },
            2: { cls: 'edm-pill-info', label: 'Pending submission' },
            3: { cls: 'edm-pill-info', label: 'Under BPT review' },
            4: { cls: 'edm-pill-warning', label: 'Content revision' },
            5: { cls: 'edm-pill-info', label: 'Audience validation' },
            6: { cls: 'edm-pill-primary', label: 'Scheduled' },
            7: { cls: 'edm-pill-primary', label: 'Sending' },
            8: { cls: 'edm-pill-success', label: 'Completed' },
            9: { cls: 'edm-pill-dark', label: 'Archived' }
        } },
        // Create and edit both happen in the Email creator page, which holds
        // the settings and the design together; no modal form here.
        noCreate: true,
        // Tab counts follow every reload (archive / unarchive / delete).
        onLoad: function () {
            fetch(BASE + 'newsletters/api.php?action=campaigns_counts', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) { return; }
                    document.getElementById('edm-tab-active-count').textContent = res.data.active;
                    document.getElementById('edm-tab-archived-count').textContent = res.data.archived;
                })
                .catch(function () { /* counts are best-effort */ });
        },
        noEdit: true,
        rowActions: [
            { label: 'Edit', visible: editable,
                link: function (r) { return 'email-builder/index.php?newsletter=' + r.id; } },
            { label: 'Submit', action: 'campaigns_submit', confirm: 'Submit this newsletter for review?',
                visible: editable },
            { label: 'Preview', handler: function (r) { openPreview(r); } },
            { label: 'Show recipients', handler: function (r) { openRecipients(r); } },
            // Once anything was sent (rows in the send log, `delivered` on the
            // listing row): figures, opens / clicks per recipient.
            { label: 'View report', visible: function (r) { return (r.delivered || 0) > 0; },
                link: function (r) { return 'newsletters/report.php?newsletter=' + r.id; } },
            { label: 'Reuse', action: 'campaigns_duplicate', confirm: 'Create a copy of this newsletter as a new draft?' },
            { label: 'Recall - don’t send it', action: 'campaigns_recall',
                confirm: 'Recall this newsletter? Nothing is sent; it returns to content revision and needs a new review.',
                visible: function (r) { return r.status === 6; } },
            { label: 'Stop sending', action: 'campaigns_stop',
                confirm: 'Stop this newsletter? Emails already sent stay sent; the rest are not sent and the newsletter is closed as completed.',
                visible: function (r) { return r.status === 7; } },
            { label: 'Archive', action: 'campaigns_archive',
                confirm: 'Archive this newsletter? It moves to the Archived tab and is locked until you unarchive it.',
                visible: function (r) { return r.status === 1 || r.status === 4 || r.status === 8; } },
            { label: 'Unarchive', action: 'campaigns_unarchive',
                confirm: 'Unarchive this newsletter? It returns to the Newsletters list with the status it had before.',
                visible: function (r) { return r.status === 9; } }
        ],
        columns: [
            { key: 'name', label: 'Name' },
            { key: 'subject', label: 'Subject' },
            { key: 'tags', label: 'Tags', value: function (r) {
                return (r.tags || []).map(function (t) { return t.name; }).join(', ');
            } },
            { key: 'audience_label', label: 'Audience' },
            { key: 'status', label: 'Status', type: 'badge' },
            { key: 'scheduled_at', label: 'Scheduled' }
        ],
        fields: []
    };

    // Newsletters / Archived tabs: same table, list reloaded with ?archived=1.
    var tabs = [].slice.call(document.querySelectorAll('#edm-newsletter-tabs .nav-link'));
    tabs.forEach(function (b) {
        b.addEventListener('click', function () {
            var archived = b.getAttribute('data-view') === 'archived';
            tabs.forEach(function (x) {
                x.classList.toggle('active', x === b);
                x.setAttribute('aria-selected', x === b ? 'true' : 'false');
            });
            window.EDM_CRUD_CONFIG.listParams = archived ? { archived: 1 } : null;
            window.edmCrudReload();
        });
    });
})();
</script>
<?php
include __DIR__ . '/../footer.php';
?>
