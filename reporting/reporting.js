/**
 * Reporting Dashboard. Read-only platform overview from
 * reporting/api.php?action=overview (edm-api edm/reporting/overview).
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var cardsEl  = document.getElementById('edm-rep-cards');
    var statusEl = document.getElementById('edm-rep-status');
    var alertEl  = document.getElementById('edm-rep-alert');

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function card(label, value, sub) {
        return '<div class="col-6 col-lg-3">' +
            '<div class="border rounded p-3 h-100">' +
                '<div class="text-muted small">' + esc(label) + '</div>' +
                '<div class="fs-4 fw-semibold">' + esc(value) + '</div>' +
                (sub ? '<div class="text-muted small">' + esc(sub) + '</div>' : '') +
            '</div></div>';
    }

    fetch(BASE + 'reporting/api.php?action=overview', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) { alertEl.textContent = res.message || 'Failed to load.'; alertEl.hidden = false; cardsEl.innerHTML = ''; return; }
            var d = res.data || {};
            var c = d.campaigns || {}, a = d.audience || {}, s = d.senders || {}, del = d.delivery || {};

            cardsEl.innerHTML =
                card('Newsletters', c.total || 0, (c.scheduled || 0) + ' scheduled') +
                card('Lists', a.lists || 0, (a.list_members || 0) + ' members') +
                card('Suppressed', a.suppressed || 0, 'addresses') +
                card('Senders verified', (s.verified || 0) + ' / ' + (s.total || 0), '') +
                card('Delivery rate', (del.delivery_rate || 0) + '%', del.note || '') +
                card('Open rate', (del.open_rate || 0) + '%', 'SES pending') +
                card('Click rate', (del.click_rate || 0) + '%', 'SES pending') +
                card('Bounce rate', (del.bounce_rate || 0) + '%', 'SES pending');

            var bs = c.by_status || {};
            statusEl.innerHTML = Object.keys(bs).map(function (k, i) {
                return '<tr><td class="text-muted" style="width:3rem;">' + (i + 1) + '</td>' +
                    '<td class="text-capitalize">' + esc(k.replace(/_/g, ' ')) + '</td>' +
                    '<td class="text-end fw-semibold">' + esc(bs[k]) + '</td></tr>';
            }).join('');
        })
        .catch(function () { alertEl.textContent = 'Could not reach the server.'; alertEl.hidden = false; cardsEl.innerHTML = ''; });
})();
