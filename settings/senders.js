/**
 * Settings > Senders.
 *
 * Talks to settings/api.php (senders_* actions), which proxies to edm-api
 * edm/senders over JWT. Data is owned by edm-api.
 */
(function () {
    'use strict';

    var API = (window.EDM_MODULE_BASE || '/odb/edm/') + 'settings/api.php';

    var rowsEl   = document.getElementById('edm-sender-rows');
    var alertEl  = document.getElementById('edm-sender-alert');
    var pagerEl  = document.getElementById('edm-sender-pager');
    var modalEl  = document.getElementById('edm-sender-modal');
    var formEl   = document.getElementById('edm-sender-form');
    var titleEl  = document.getElementById('edm-sender-modal-title');
    var idEl     = document.getElementById('edm-sender-id');
    var nameEl   = document.getElementById('edm-sender-from-name');
    var emailEl  = document.getElementById('edm-sender-email');
    var replyEl  = document.getElementById('edm-sender-reply-to');
    var defEl    = document.getElementById('edm-sender-default');
    var formErr  = document.getElementById('edm-sender-form-error');
    var saveBtn  = document.getElementById('edm-sender-save');

    var modal = new bootstrap.Modal(modalEl);
    var current = [];
    var page = 1;
    var perPage = 30;

    // Never Bootstrap's .badge/text-bg-* here - a legacy common/css/page.css
    // rule hijacks .badge as an absolutely-positioned notification dot (see
    // the .edm-pill note in css/style.css). Use edm-pill-* instead.
    var STATUS = {
        verified: { label: 'Verified', cls: 'edm-pill-success' },
        pending:  { label: 'Pending',  cls: 'edm-pill-secondary' },
        failed:   { label: 'Failed',   cls: 'edm-pill-danger' }
    };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function showAlert(msg) {
        alertEl.textContent = msg || 'Something went wrong.';
        alertEl.hidden = false;
    }

    function clearAlert() {
        alertEl.hidden = true;
    }

    function call(action, method, body) {
        return fetch(API + '?action=' + encodeURIComponent(action), {
            method: method || 'GET',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: body ? JSON.stringify(body) : null
        }).then(function (r) { return r.json(); });
    }

    function firstError(res) {
        if (res && res.errors) {
            for (var k in res.errors) {
                if (Object.prototype.hasOwnProperty.call(res.errors, k)) {
                    return res.errors[k][0];
                }
            }
        }
        return (res && res.message) || 'Request failed.';
    }

    function renderRows(rows, startIdx) {
        if (!rows.length) {
            rowsEl.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No senders yet.</td></tr>';
            return;
        }
        rowsEl.innerHTML = rows.map(function (s, i) {
            var st = STATUS[s.status] || STATUS.pending;
            var next = { pending: 'verified', verified: 'failed', failed: 'pending' }[s.status] || 'verified';
            return '' +
                '<tr data-id="' + s.id + '">' +
                    '<td class="text-muted">' + (startIdx + i + 1) + '</td>' +
                    '<td>' + (s.is_default
                        ? '<i class="bi bi-star-fill text-warning" title="Default sender"></i>'
                        : '') + '</td>' +
                    '<td>' + esc(s.from_name) + '</td>' +
                    '<td>' + esc(s.email) + '</td>' +
                    '<td>' + (s.reply_to ? esc(s.reply_to) : '<span class="text-muted">-</span>') + '</td>' +
                    '<td><span class="edm-pill ' + st.cls + '">' + st.label + '</span></td>' +
                    '<td class="text-end">' +
                        '<div class="d-flex justify-content-end align-items-center gap-2">' +
                            (s.is_default ? '' :
                                '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="default">Set default</button>') +
                            '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="status" data-next="' + next + '">' +
                                'Mark ' + next +
                            '</button>' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary edm-icon-btn" data-act="edit" title="Edit"><i class="bi bi-pencil"></i></button>' +
                            '<button type="button" class="btn btn-sm btn-outline-danger edm-icon-btn" data-act="delete" title="Delete"><i class="bi bi-trash"></i></button>' +
                        '</div>' +
                    '</td>' +
                '</tr>';
        }).join('');
    }

    function pageBtn(p) {
        return '<button type="button" class="edm-pager-btn' + (p === page ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
    }

    function renderPager(total, pages, startIdx, shown) {
        if (!pagerEl) { return; }
        if (total === 0) { pagerEl.innerHTML = ''; return; }

        var opts = [10, 30, 50, 100];
        var selHtml = '<select class="edm-perpage-select" id="edm-sender-perpage">';
        opts.forEach(function (o) {
            selHtml += '<option value="' + o + '"' + (perPage === o ? ' selected' : '') + '>' + o + '</option>';
        });
        selHtml += '</select>';
        var leftHtml = '<div class="edm-pager-left">Show ' + selHtml + ' entries</div>';

        var info = '<span class="edm-pager-info">Showing ' + (startIdx + 1) + ' to ' + (startIdx + shown) + ' of ' + total + ' entries</span>';
        var btns = '<button type="button" class="edm-pager-btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>Previous</button>';

        var win = 2, from = Math.max(1, page - win), to = Math.min(pages, page + win);
        if (from > 1) { btns += pageBtn(1) + (from > 2 ? '<span class="edm-pager-gap">...</span>' : ''); }
        for (var p = from; p <= to; p++) { btns += pageBtn(p); }
        if (to < pages) { btns += (to < pages - 1 ? '<span class="edm-pager-gap">...</span>' : '') + pageBtn(pages); }
        btns += '<button type="button" class="edm-pager-btn" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>Next</button>';

        pagerEl.innerHTML = leftHtml + '<div class="d-flex align-items-center gap-2">' + info + '<div class="edm-pager-bar">' + btns + '</div></div>';

        [].slice.call(pagerEl.querySelectorAll('.edm-pager-btn[data-page]')).forEach(function (b) {
            b.addEventListener('click', function () {
                var p2 = parseInt(b.getAttribute('data-page'), 10);
                if (p2 >= 1 && p2 <= pages && p2 !== page) { page = p2; renderPage(); }
            });
        });
        var sel = document.getElementById('edm-sender-perpage');
        if (sel) {
            sel.addEventListener('change', function () {
                perPage = parseInt(sel.value, 10);
                page = 1;
                renderPage();
            });
        }
    }

    function renderPage() {
        var total = current.length;
        var pages = Math.max(1, Math.ceil(total / perPage));
        if (page > pages) { page = pages; }
        var startIdx = (page - 1) * perPage;
        var pageRows = current.slice(startIdx, startIdx + perPage);
        renderRows(pageRows, startIdx);
        renderPager(total, pages, startIdx, pageRows.length);
    }

    function load() {
        clearAlert();
        rowsEl.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Loading...</td></tr>';
        call('senders_list').then(function (res) {
            if (!res.success) { showAlert(res.message); current = []; renderPage(); return; }
            current = res.data || [];
            page = 1;
            renderPage();
        }).catch(function () {
            showAlert('Could not reach the server.');
            current = [];
            renderPage();
        });
    }

    function openModal(sender) {
        formErr.hidden = true;
        formErr.textContent = '';
        if (sender) {
            titleEl.textContent = 'Edit sender';
            idEl.value    = sender.id;
            nameEl.value  = sender.from_name || '';
            emailEl.value = sender.email || '';
            replyEl.value = sender.reply_to || '';
            defEl.checked = !!sender.is_default;
        } else {
            titleEl.textContent = 'Add sender';
            formEl.reset();
            idEl.value = '';
        }
        modal.show();
    }

    // --- events ---

    document.getElementById('edm-sender-add').addEventListener('click', function () {
        openModal(null);
    });

    formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        formErr.hidden = true;
        saveBtn.disabled = true;

        var id = idEl.value;
        var payload = {
            from_name: nameEl.value.trim(),
            email: emailEl.value.trim(),
            reply_to: replyEl.value.trim(),
            is_default: defEl.checked
        };
        var action = id ? 'senders_update' : 'senders_create';
        var method = id ? 'PUT' : 'POST';
        if (id) { payload.id = parseInt(id, 10); }

        call(action, method, payload).then(function (res) {
            saveBtn.disabled = false;
            if (!res.success) {
                formErr.textContent = firstError(res);
                formErr.hidden = false;
                return;
            }
            modal.hide();
            load();
        }).catch(function () {
            saveBtn.disabled = false;
            formErr.textContent = 'Could not reach the server.';
            formErr.hidden = false;
        });
    });

    rowsEl.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-act]');
        if (!btn) { return; }
        var tr = btn.closest('tr');
        var id = parseInt(tr.getAttribute('data-id'), 10);
        var act = btn.getAttribute('data-act');

        if (act === 'edit') {
            var s = current.filter(function (x) { return x.id === id; })[0];
            if (s) { openModal(s); }
            return;
        }

        if (act === 'default') {
            call('senders_update', 'PUT', { id: id, is_default: true }).then(function (res) {
                if (!res.success) { showAlert(firstError(res)); return; }
                load();
            });
            return;
        }

        if (act === 'status') {
            var next = btn.getAttribute('data-next');
            call('senders_verify', 'POST', { id: id, status: next }).then(function (res) {
                if (!res.success) { showAlert(firstError(res)); return; }
                load();
            });
            return;
        }

        if (act === 'delete') {
            window.edmConfirm('Delete this sender?', function () {
                call('senders_delete', 'DELETE', { id: id }).then(function (res) {
                    if (!res.success) { showAlert(firstError(res)); return; }
                    load();
                });
            });
        }
    });

    load();
})();
