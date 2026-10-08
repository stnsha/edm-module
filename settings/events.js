/**
 * Settings > SES event log (settings/events.php). Lists events_list (type
 * and time range on the server; search, paging in the browser) and shows one
 * raw event (events_get) in a modal.
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var API  = BASE + 'settings/api.php';

    var EVENT_PILLS = {
        Send: 'edm-pill-secondary',
        Delivery: 'edm-pill-success',
        Open: 'edm-pill-info',
        Click: 'edm-pill-primary',
        Bounce: 'edm-pill-danger',
        Complaint: 'edm-pill-danger',
        Reject: 'edm-pill-danger',
        RenderingFailure: 'edm-pill-danger',
        DeliveryDelay: 'edm-pill-warning',
        Subscription: 'edm-pill-warning'
    };

    var rowsEl   = document.getElementById('edm-ev-rows');
    var typeEl   = document.getElementById('edm-ev-type');
    var daysEl   = document.getElementById('edm-ev-days');
    var searchEl = document.getElementById('edm-ev-search');
    var modalEl  = document.getElementById('edm-ev-modal');
    var modal    = null;
    var all = [];

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function showAlert(msg) {
        var el = document.getElementById('edm-ev-alert');
        el.textContent = msg;
        el.hidden = !msg;
    }

    function actionMenu(e) {
        return '<div class="dropdown">' +
            '<button type="button" class="edm-row-kebab" data-bs-toggle="dropdown" aria-expanded="false"' +
                ' data-bs-popper-config=\'{"strategy":"fixed"}\' aria-label="Actions" title="Actions">' +
                '<i class="bi bi-three-dots-vertical"></i></button>' +
            '<ul class="dropdown-menu dropdown-menu-end edm-row-menu">' +
                '<li><button type="button" class="dropdown-item" data-act="raw" data-id="' + e.id + '">View raw event</button></li>' +
                (e.campaign_id ? '<li><a class="dropdown-item" href="' + BASE + 'newsletters/report.php?newsletter=' + e.campaign_id + '">Newsletter report</a></li>' : '') +
            '</ul>' +
        '</div>';
    }

    var pager = window.edmPager(document.getElementById('edm-ev-pager'), function (pageRows, startIdx) {
        if (!pageRows.length) {
            rowsEl.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">' +
                (all.length ? 'No event matches the search.' : 'No SES events in this period.') + '</td></tr>';
            return;
        }
        rowsEl.innerHTML = pageRows.map(function (e, i) {
            return '<tr>' +
                '<td>' + (startIdx + i + 1) + '</td>' +
                '<td class="small text-nowrap">' + esc(e.occurred_at || '-') + '</td>' +
                '<td><span class="edm-pill ' + (EVENT_PILLS[e.event_type] || 'edm-pill-secondary') + '">' + esc(e.event_type) + '</span></td>' +
                '<td>' + esc(e.email || '-') + '</td>' +
                '<td>' + (e.campaign_id
                    ? '<a href="' + BASE + 'newsletters/report.php?newsletter=' + e.campaign_id + '">' + esc(e.campaign_name || ('#' + e.campaign_id)) + '</a>'
                    : '<span class="text-muted">-</span>') + '</td>' +
                '<td class="small text-break">' + esc(e.detail || '') + '</td>' +
                '<td class="text-end">' + actionMenu(e) + '</td>' +
                '</tr>';
        }).join('');
    });

    function applySearch() {
        var q = searchEl.value.trim().toLowerCase();
        pager.set(!q ? all : all.filter(function (e) {
            return [e.email, e.campaign_name, e.detail, e.event_type].some(function (v) {
                return String(v || '').toLowerCase().indexOf(q) !== -1;
            });
        }));
    }

    function load() {
        showAlert('');
        document.getElementById('edm-ev-summary').textContent = 'Loading...';
        var qs = '?action=events_list&type=' + encodeURIComponent(typeEl.value) + '&days=' + encodeURIComponent(daysEl.value);
        fetch(API + qs, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    showAlert(res.message || 'Could not load the events.');
                    document.getElementById('edm-ev-summary').textContent = '';
                    return;
                }
                all = res.data || [];
                document.getElementById('edm-ev-summary').textContent = all.length + ' event(s)' +
                    (all.length >= 5000 ? ' (newest 5000 shown - narrow the period or type)' : '') + '.';
                applySearch();
            })
            .catch(function () { showAlert('Could not load the events.'); });
    }

    rowsEl.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-act="raw"]');
        if (!b) { return; }
        if (!modal) { modal = new bootstrap.Modal(modalEl); }
        var raw = document.getElementById('edm-ev-raw');
        raw.textContent = 'Loading...';
        modal.show();
        fetch(API + '?action=events_get&id=' + encodeURIComponent(b.getAttribute('data-id')), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { raw.textContent = res.message || 'Could not load the event.'; return; }
                document.getElementById('edm-ev-modal-title').textContent = res.data.event_type + ' - ' + (res.data.email || '');
                raw.textContent = JSON.stringify(res.data.payload, null, 2);
            })
            .catch(function () { raw.textContent = 'Could not load the event.'; });
    });

    typeEl.addEventListener('change', load);
    daysEl.addEventListener('change', load);
    searchEl.addEventListener('input', applySearch);
    document.getElementById('edm-ev-refresh').addEventListener('click', load);

    load();
})();
