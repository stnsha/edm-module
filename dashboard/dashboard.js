/**
 * Dashboard. Read-only platform overview from dashboard/api.php?action=overview
 * (Edm\Services\ReportingOverview): KPI tiles plus one card per newsletter
 * status.
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var cardsEl  = document.getElementById('edm-dash-cards');
    var statusEl = document.getElementById('edm-dash-status');
    var alertEl  = document.getElementById('edm-dash-alert');

    // by_status arrives keyed by Campaign::STATUSES label, in lifecycle order.
    // Same labels / pill colours as the Newsletters list (campaign/index.php).
    var STATUS = {
        draft:               { label: 'Draft', cls: 'edm-pill-secondary' },
        pending_submission:  { label: 'Pending submission', cls: 'edm-pill-info' },
        under_bpt_review:    { label: 'Under BPT review', cls: 'edm-pill-info' },
        content_revision:    { label: 'Content revision', cls: 'edm-pill-warning' },
        audience_validation: { label: 'Audience validation', cls: 'edm-pill-info' },
        scheduled:           { label: 'Scheduled', cls: 'edm-pill-primary' },
        sending:             { label: 'Sending', cls: 'edm-pill-primary' },
        completed:           { label: 'Completed', cls: 'edm-pill-success' },
        archived:            { label: 'Archived', cls: 'edm-pill-dark' }
    };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function fail(msg) {
        alertEl.textContent = msg;
        alertEl.hidden = false;
        cardsEl.innerHTML = '';
    }

    function card(label, value, sub) {
        return '<div class="col-6 col-lg-3">' +
            '<div class="edm-card h-100">' +
                '<div class="text-muted small">' + esc(label) + '</div>' +
                '<div class="fs-4 fw-semibold">' + esc(value) + '</div>' +
                (sub ? '<div class="text-muted small">' + esc(sub) + '</div>' : '') +
            '</div></div>';
    }

    function statusCard(key, count) {
        var st = STATUS[key] || { label: key.replace(/_/g, ' '), cls: 'edm-pill-secondary' };
        return '<div class="col-6 col-md-4 col-xl-3">' +
            '<div class="edm-card h-100">' +
                '<span class="edm-pill ' + st.cls + '">' + esc(st.label) + '</span>' +
                '<div class="fs-4 fw-semibold mt-2">' + esc(count) + '</div>' +
                '<div class="text-muted small">' + (count === 1 ? 'newsletter' : 'newsletters') + '</div>' +
            '</div></div>';
    }

    fetch(BASE + 'dashboard/api.php?action=overview', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) { fail(res.message || 'Failed to load.'); return; }
            var d = res.data || {};
            var c = d.campaigns || {}, a = d.audience || {}, s = d.senders || {}, del = d.delivery || {};

            cardsEl.innerHTML =
                card('Newsletters', c.total || 0, (c.scheduled || 0) + ' scheduled') +
                card('Lists', a.lists || 0, (a.list_members || 0) + ' members') +
                card('Suppressed', a.suppressed || 0, 'addresses') +
                card('Senders verified', (s.verified || 0) + ' / ' + (s.total || 0), '') +
                card('Delivery rate', (del.delivery_rate || 0) + '%', (del.delivered || 0) + ' of ' + (del.sent || 0) + ' sent') +
                card('Open rate', (del.open_rate || 0) + '%', (del.opened || 0) + ' opened') +
                card('Click rate', (del.click_rate || 0) + '%', (del.clicked || 0) + ' clicked') +
                card('Bounce rate', (del.bounce_rate || 0) + '%', (del.bounced || 0) + ' bounced, ' + (del.complained || 0) + ' complaints');

            var bs = c.by_status || {};
            statusEl.innerHTML = Object.keys(bs).map(function (k) {
                return statusCard(k, bs[k]);
            }).join('');
        })
        .catch(function () { fail('Could not reach the server.'); });
})();
