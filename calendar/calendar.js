/**
 * Newsletter Calendar. Read-only month grid of newsletters (edm_campaigns,
 * archived left out) on their send date. Clicking a newsletter opens it in
 * the Email creator.
 *
 * A day with two or more newsletters is flagged as a conflict.
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var API  = BASE + 'calendar/api.php';
    var OPEN = BASE + 'email-builder/index.php?newsletter=';

    var grid    = document.getElementById('edm-cal-grid');
    var titleEl = document.getElementById('edm-cal-title');
    var alertEl = document.getElementById('edm-cal-alert');

    var view = new Date();
    view.setDate(1);

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    // Pill colour and label per status (Campaign::STATUSES).
    var STATUS = {
        1: { cls: 'edm-pill-secondary', label: 'Draft' },
        2: { cls: 'edm-pill-warning', label: 'Pending approval' },
        6: { cls: 'edm-pill-primary', label: 'Scheduled' },
        7: { cls: 'edm-pill-info', label: 'Sending' },
        8: { cls: 'edm-pill-success', label: 'Completed' }
    };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function iso(d) {
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    // The API sends display dates ('d-m-Y H:i:s'); the grid keys by 'Y-m-d'.
    function dayKey(v) {
        var m = /^(\d{2})-(\d{2})-(\d{4})/.exec(v || '');
        if (m) { return m[3] + '-' + m[2] + '-' + m[1]; }
        return /^\d{4}-\d{2}-\d{2}/.test(v || '') ? v.slice(0, 10) : '';
    }
    function showAlert(m) { alertEl.textContent = m || 'Something went wrong.'; alertEl.hidden = false; }

    function monthStart() {
        var first = new Date(view.getFullYear(), view.getMonth(), 1);
        var start = new Date(first);
        start.setDate(first.getDate() - ((first.getDay() + 6) % 7)); // back to Monday
        return start;
    }

    function render(campaigns) {
        titleEl.textContent = MONTHS[view.getMonth()] + ' ' + view.getFullYear();
        var byDay = {};
        (campaigns || []).forEach(function (c) {
            var k = dayKey(c.scheduled_at);
            if (!k || c.status === 9) { return; } // archived
            (byDay[k] = byDay[k] || []).push(c);
        });

        var html = '';
        var d = monthStart();
        for (var w = 0; w < 6; w++) {
            html += '<tr>';
            for (var i = 0; i < 7; i++) {
                var k = iso(d);
                var muted = d.getMonth() !== view.getMonth() ? ' text-muted bg-light' : '';
                var day = byDay[k] || [];
                var clash = day.length > 1;
                var chips = day.map(function (c) {
                    var st = STATUS[c.status] || { cls: 'edm-pill-dark', label: '' };
                    var tip = c.name + (st.label ? ' (' + st.label + ')' : '');
                    return '<a href="' + OPEN + encodeURIComponent(c.id) + '" class="edm-pill ' + st.cls +
                        ' d-block text-truncate text-decoration-none mb-1" title="' + esc(tip) + '">' + esc(c.name) + '</a>';
                }).join('');
                html += '<td class="edm-cal-cell' + muted + (clash ? ' edm-cal-conflict' : '') + '" style="height:6.5rem; vertical-align:top;">' +
                    '<div class="small fw-semibold mb-1 d-flex justify-content-between">' + d.getDate() +
                        (clash ? '<i class="bi bi-exclamation-triangle-fill text-warning" title="Conflict: more than one newsletter on this day"></i>' : '') +
                    '</div>' + chips + '</td>';
                d.setDate(d.getDate() + 1);
            }
            html += '</tr>';
        }
        grid.innerHTML = html;
    }

    function load() {
        alertEl.hidden = true;
        fetch(API + '?action=month', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { showAlert(res.message); render([]); return; }
                render(res.campaigns);
            })
            .catch(function () { showAlert('Could not reach the server.'); });
    }

    document.getElementById('edm-cal-prev').addEventListener('click', function () { view.setMonth(view.getMonth() - 1); load(); });
    document.getElementById('edm-cal-next').addEventListener('click', function () { view.setMonth(view.getMonth() + 1); load(); });
    document.getElementById('edm-cal-today').addEventListener('click', function () { view = new Date(); view.setDate(1); load(); });

    load();
})();
