/**
 * Campaign Calendar. Month grid of calendar slots (edm_calendar_slots) with
 * scheduled newsletters (edm_campaigns) overlaid. Click a day to add a slot,
 * click a slot chip to edit.
 *
 * A day with a conflict is flagged. Same rule as app/Services/
 * ScheduleConflicts: two or more of { newsletters scheduled that day (not
 * archived), slots that day not linked to one of those newsletters }.
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var API  = BASE + 'calendar/api.php';

    var grid    = document.getElementById('edm-cal-grid');
    var titleEl = document.getElementById('edm-cal-title');
    var alertEl = document.getElementById('edm-cal-alert');
    var modalEl = document.getElementById('edm-cal-modal');
    var formEl  = document.getElementById('edm-cal-form');
    var idEl    = document.getElementById('edm-cal-id');
    var dateEl  = document.getElementById('edm-cal-date');
    var labelEl = document.getElementById('edm-cal-label');
    var catEl   = document.getElementById('edm-cal-category');
    var noteEl  = document.getElementById('edm-cal-note');
    var errEl   = document.getElementById('edm-cal-form-error');
    var delBtn  = document.getElementById('edm-cal-del');

    var modal = new bootstrap.Modal(modalEl);
    var view = new Date();
    view.setDate(1);

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function iso(d) {
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    // The API sends display dates ('d-m-Y' / 'd-m-Y H:i:s'); the grid and the
    // date input key by 'Y-m-d'.
    function dayKey(v) {
        var m = /^(\d{2})-(\d{2})-(\d{4})/.exec(v || '');
        if (m) { return m[3] + '-' + m[2] + '-' + m[1]; }
        return /^\d{4}-\d{2}-\d{2}/.test(v || '') ? v.slice(0, 10) : '';
    }
    function call(action, method, body) {
        return fetch(API + '?action=' + action, {
            method: method || 'GET',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: body ? JSON.stringify(body) : null
        }).then(function (r) { return r.json(); });
    }
    function showAlert(m) { alertEl.textContent = m || 'Something went wrong.'; alertEl.hidden = false; }

    function monthBounds() {
        var first = new Date(view.getFullYear(), view.getMonth(), 1);
        var start = new Date(first);
        start.setDate(first.getDate() - ((first.getDay() + 6) % 7)); // back to Monday
        var end = new Date(start);
        end.setDate(start.getDate() + 41);
        return { start: start, end: end };
    }

    function render(slots, campaigns) {
        titleEl.textContent = MONTHS[view.getMonth()] + ' ' + view.getFullYear();
        var b = monthBounds();
        var byDay = {};
        var sentOn = {}; // day -> { campaign id: true }, to skip a slot linked to its own newsletter
        (campaigns || []).forEach(function (c) {
            var k = dayKey(c.scheduled_at);
            if (!k || c.status === 9) { return; } // archived
            (byDay[k] = byDay[k] || []).push({ type: 'campaign', text: c.name, counts: true });
            (sentOn[k] = sentOn[k] || {})[c.id] = true;
        });
        (slots || []).forEach(function (s) {
            var k = dayKey(s.slot_date);
            if (!k) { return; }
            var own = s.campaign_id != null && sentOn[k] && sentOn[k][s.campaign_id];
            (byDay[k] = byDay[k] || []).push({ type: 'slot', id: s.id, text: s.slot_label || s.category || 'slot', counts: !own });
        });

        var html = '';
        var d = new Date(b.start);
        for (var w = 0; w < 6; w++) {
            html += '<tr>';
            for (var i = 0; i < 7; i++) {
                var k = iso(d);
                var muted = d.getMonth() !== view.getMonth() ? ' text-muted bg-light' : '';
                var clash = (byDay[k] || []).filter(function (x) { return x.counts; }).length > 1;
                var chips = (byDay[k] || []).map(function (x) {
                    if (x.type === 'campaign') {
                        return '<span class="edm-pill edm-pill-primary d-block text-truncate mb-1">' + esc(x.text) + '</span>';
                    }
                    return '<span class="edm-pill edm-pill-secondary d-block text-truncate mb-1 edm-cal-chip" style="cursor:pointer" data-id="' + x.id + '">' + esc(x.text) + '</span>';
                }).join('');
                html += '<td class="edm-cal-cell' + muted + (clash ? ' edm-cal-conflict' : '') + '" data-date="' + k + '" style="height:6.5rem; cursor:pointer; vertical-align:top;">' +
                    '<div class="small fw-semibold mb-1 d-flex justify-content-between">' + d.getDate() +
                        (clash ? '<i class="bi bi-exclamation-triangle-fill text-warning" title="Conflict: more than one send or reservation on this day"></i>' : '') +
                    '</div>' + chips + '</td>';
                d.setDate(d.getDate() + 1);
            }
            html += '</tr>';
        }
        grid.innerHTML = html;
    }

    function load() {
        alertEl.hidden = true;
        var b = monthBounds();
        call('month&from=' + iso(b.start) + '&to=' + iso(b.end)).then(function (res) {
            if (!res.success) { showAlert(res.message); render([], []); return; }
            render(res.slots, res.campaigns);
        }).catch(function () { showAlert('Could not reach the server.'); });
    }

    function openModal(date, slot) {
        errEl.hidden = true;
        idEl.value    = slot ? slot.id : '';
        dateEl.value  = slot ? dayKey(slot.slot_date) : date;
        labelEl.value = slot ? (slot.slot_label || '') : '';
        catEl.value   = slot ? (slot.category || '') : '';
        noteEl.value  = slot ? (slot.note || '') : '';
        delBtn.hidden = !slot;
        modal.show();
    }

    grid.addEventListener('click', function (e) {
        var chip = e.target.closest('.edm-cal-chip');
        if (chip) {
            e.stopPropagation();
            var b = monthBounds();
            call('month&from=' + iso(b.start) + '&to=' + iso(b.end)).then(function (res) {
                var s = (res.slots || []).filter(function (x) { return String(x.id) === chip.getAttribute('data-id'); })[0];
                if (s) { openModal(null, s); }
            });
            return;
        }
        var cell = e.target.closest('.edm-cal-cell');
        if (cell) { openModal(cell.getAttribute('data-date'), null); }
    });

    formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        errEl.hidden = true;
        var id = idEl.value;
        var payload = {
            slot_date: dateEl.value,
            slot_label: labelEl.value.trim(),
            category: catEl.value.trim(),
            note: noteEl.value.trim()
        };
        if (id) { payload.id = parseInt(id, 10); }
        call(id ? 'slots_update' : 'slots_create', id ? 'PUT' : 'POST', payload).then(function (res) {
            if (!res.success) { errEl.textContent = res.message || 'Failed to save.'; errEl.hidden = false; return; }
            modal.hide(); load();
        });
    });

    delBtn.addEventListener('click', function () {
        if (!idEl.value) { return; }
        window.edmConfirm('Delete this slot?', function () {
            call('slots_delete', 'DELETE', { id: parseInt(idEl.value, 10) }).then(function (res) {
                if (!res.success) { errEl.textContent = res.message || 'Failed to delete.'; errEl.hidden = false; return; }
                modal.hide(); load();
            });
        });
    });

    document.getElementById('edm-cal-prev').addEventListener('click', function () { view.setMonth(view.getMonth() - 1); load(); });
    document.getElementById('edm-cal-next').addEventListener('click', function () { view.setMonth(view.getMonth() + 1); load(); });
    document.getElementById('edm-cal-today').addEventListener('click', function () { view = new Date(); view.setDate(1); load(); });
    document.getElementById('edm-cal-add').addEventListener('click', function () { openModal(iso(new Date()), null); });

    load();
})();
