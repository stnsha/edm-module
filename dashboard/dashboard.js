/**
 * Dashboard. Platform overview from dashboard/api.php?action=overview
 * (Edm\Services\ReportingOverview): the stat cards are rendered by index.php
 * (atem/index.php layout); this fills in their values and labels by id.
 *
 * Message performance for a period from ?action=performance&from=&to=
 * (Edm\Services\PerformanceReport), painted by js/edm-performance.js; the
 * period preset fills the dates, editing a date switches to Custom range,
 * Export downloads it as CSV or prints it (Save as PDF).
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

    function showAlert(msg) {
        alertEl.textContent = msg;
        alertEl.hidden = false;
    }

    fetch(BASE + 'dashboard/api.php?action=overview', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) {
                showAlert(res.message || 'Failed to load.');
                return;
            }
            var d = res.data || {};
            var c = d.campaigns || {}, a = d.audience || {}, s = d.senders || {};

            stat('campaigns', c.total || 0, (c.scheduled || 0) + ' scheduled');
            stat('contacts', a.contacts || 0, (a.subscribed || 0) + ' subscribed');
            stat('suppressed', a.suppressed || 0, 'addresses');
            stat('senders', (s.verified || 0) + ' / ' + (s.total || 0), 'verified / total');

            // by_status is keyed by Campaign::STATUSES label (draft, scheduled, ...).
            var bs = c.by_status || {};
            Object.keys(bs).forEach(function (k) { set('edm-dash-status-' + k, bs[k]); });
        })
        .catch(function () { showAlert('Could not reach the server.'); });

    // ---- Message performance ----

    var perfEl = document.getElementById('edm-perf');
    var form = document.getElementById('edm-perf-filter');
    var presetEl = document.getElementById('edm-perf-preset');
    var fromEl = document.getElementById('edm-perf-from');
    var toEl = document.getElementById('edm-perf-to');
    var exportBtn = document.getElementById('edm-perf-export');
    var current = null;

    function ymd(d) {
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }

    // Preset -> from / to (to = today; "Last 7 days" includes today).
    function applyPreset() {
        var today = new Date();
        var from = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        switch (presetEl.value) {
            case 'month': from.setDate(1); break;
            case 'year': from = new Date(today.getFullYear(), 0, 1); break;
            case 'custom': return;
            default: from.setDate(from.getDate() - parseInt(presetEl.value, 10) + 1);
        }
        fromEl.value = ymd(from);
        toEl.value = ymd(today);
    }

    function load() {
        if (!fromEl.value || !toEl.value) {
            showAlert('Choose both a start and an end date.');
            return;
        }
        alertEl.hidden = true;
        exportBtn.disabled = true;
        perfEl.classList.add('is-loading');
        var qs = '&from=' + encodeURIComponent(fromEl.value) + '&to=' + encodeURIComponent(toEl.value);
        fetch(BASE + 'dashboard/api.php?action=performance' + qs, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    showAlert(res.message || 'Could not load the performance figures.');
                    return;
                }
                current = res.data;
                window.edmPerformance.render(perfEl, current, { messages: true });
                exportBtn.disabled = false;
            })
            .catch(function () { showAlert('Could not load the performance figures.'); })
            .then(function () { perfEl.classList.remove('is-loading'); });
    }

    presetEl.addEventListener('change', function () {
        applyPreset();
        if (presetEl.value !== 'custom') { load(); }
    });
    [fromEl, toEl].forEach(function (el) {
        el.addEventListener('input', function () { presetEl.value = 'custom'; });
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        load();
    });

    document.querySelectorAll('[data-export]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!current) { return; }
            if (btn.getAttribute('data-export') === 'pdf') {
                window.edmPerformance.print();
            } else {
                window.edmPerformance.csv(current, 'message-performance_' + current.from + '_' + current.to + '.csv');
            }
        });
    });

    load();
})();
