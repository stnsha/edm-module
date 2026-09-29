/**
 * Dashboard. Read-only platform overview from dashboard/api.php?action=overview
 * (Edm\Services\ReportingOverview). The stat cards are rendered by index.php
 * (atem/index.php layout); this fills in their values and labels by id.
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var alertEl = document.getElementById('edm-dash-alert');

    function set(id, value) {
        var el = document.getElementById(id);
        if (el) { el.textContent = value; }
    }

    // A card's value and the line under it.
    function stat(key, value, label) {
        set('edm-dash-' + key, value);
        set('edm-dash-' + key + '-label', label);
    }

    fetch(BASE + 'dashboard/api.php?action=overview', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) {
                alertEl.textContent = res.message || 'Failed to load.';
                alertEl.hidden = false;
                return;
            }
            var d = res.data || {};
            var c = d.campaigns || {}, a = d.audience || {}, s = d.senders || {}, del = d.delivery || {};

            stat('campaigns', c.total || 0, (c.scheduled || 0) + ' scheduled');
            stat('lists', a.lists || 0, (a.list_members || 0) + ' members');
            stat('suppressed', a.suppressed || 0, 'addresses');
            stat('senders', (s.verified || 0) + ' / ' + (s.total || 0), 'verified / total');
            stat('delivery', (del.delivery_rate || 0) + '%', (del.delivered || 0) + ' of ' + (del.sent || 0) + ' sent');
            stat('open', (del.open_rate || 0) + '%', (del.opened || 0) + ' opened');
            stat('click', (del.click_rate || 0) + '%', (del.clicked || 0) + ' clicked');
            stat('bounce', (del.bounce_rate || 0) + '%', (del.bounced || 0) + ' bounced, ' + (del.complained || 0) + ' complaints');

            // by_status is keyed by Campaign::STATUSES label (draft, scheduled, ...).
            var bs = c.by_status || {};
            Object.keys(bs).forEach(function (k) { set('edm-dash-status-' + k, bs[k]); });
        })
        .catch(function () {
            alertEl.textContent = 'Could not reach the server.';
            alertEl.hidden = false;
        });
})();
