/**
 * Settings > Users & permissions (settings/users.php), laid out like atem's
 * Access Control: staff with an EDM role on the left (filter, # column,
 * atem pager, row menu Edit / Remove access), the add / update panel on the
 * right (staff search -> details -> role -> Add access / Update access).
 * Talks to settings/api.php users_list / users_search / users_save /
 * users_delete (superadmin only). Nobody can change their own role.
 */
(function () {
    'use strict';

    var BASE  = window.EDM_MODULE_BASE || '/odb/edm/';
    var API   = BASE + 'settings/api.php';
    var ROLES = window.EDM_USER_ROLES || {};
    var SELF  = window.EDM_USER_SELF;

    var rowsEl    = document.getElementById('edm-users-rows');
    var pagerEl   = document.getElementById('edm-users-pager');
    var alertEl   = document.getElementById('edm-users-alert');
    var fName     = document.getElementById('edm-users-filter-name');
    var fRole     = document.getElementById('edm-users-filter-role');
    var titleEl   = document.getElementById('edm-users-panel-title');
    var formAlert = document.getElementById('edm-users-form-alert');
    var searchEl  = document.getElementById('edm-users-search');
    var resultsEl = document.getElementById('edm-users-results');
    var infoEl    = document.getElementById('edm-users-info');
    var rolesEl   = document.getElementById('edm-users-roles');
    var actionsEl = document.getElementById('edm-users-actions');
    var saveBtn   = document.getElementById('edm-users-save');

    var all = [];      // users_list rows
    var shown = [];    // after filters
    var page = 1;
    var perPage = 30;
    var picked = null; // staff row in the panel
    var results = [];
    var searchTimer = null;
    var searchSeq = 0;

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function firstError(res) {
        if (res && res.errors) {
            for (var k in res.errors) {
                if (Object.prototype.hasOwnProperty.call(res.errors, k)) { return res.errors[k][0]; }
            }
        }
        return (res && res.message) || 'Request failed.';
    }
    function call(action, method, body, query) {
        return fetch(API + '?action=' + action + (query || ''), {
            method: method || 'GET',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: body ? JSON.stringify(body) : null
        }).then(function (r) { return r.json(); });
    }
    function rolePill(edm) {
        var r = ROLES[edm];
        return r ? '<span class="edm-pill ' + r.cls + '">' + esc(r.label) + '</span>' : '<span class="text-muted">No access</span>';
    }
    function showAlert(msg) { alertEl.textContent = msg; alertEl.hidden = false; }
    // Same alert as atem's #form-alert: dismissible, success / danger.
    function formMessage(msg, ok) {
        formAlert.className = 'alert alert-dismissible fade show mb-3 ' + (ok ? 'alert-success' : 'alert-danger');
        document.getElementById('edm-users-form-alert-msg').textContent = msg;
        formAlert.hidden = false;
    }
    document.getElementById('edm-users-form-alert-close').addEventListener('click', function () { formAlert.hidden = true; });

    // ---- list ----

    function load() {
        alertEl.hidden = true;
        call('users_list').then(function (res) {
            if (!res.success) { showAlert(firstError(res)); all = []; } else { all = res.data || []; }
            applyFilter();
        }).catch(function () { showAlert('Could not reach the server.'); });
    }

    function applyFilter() {
        var q = fName.value.trim().toLowerCase();
        var role = parseInt(fRole.value, 10);
        shown = all.filter(function (r) {
            return (!q || r.nama_staff.toLowerCase().indexOf(q) !== -1) && (!role || r.edm === role);
        });
        page = 1;
        renderPage();
    }

    function renderPage() {
        var total = shown.length;
        var pages = Math.max(1, Math.ceil(total / perPage));
        if (page > pages) { page = pages; }
        var start = (page - 1) * perPage;
        var rows = shown.slice(start, start + perPage);
        rowsEl.innerHTML = rows.length ? rows.map(function (r, i) {
            var self = r.id === SELF;
            return '<tr data-id="' + r.id + '"' + (picked && picked.id === r.id ? ' class="table-active"' : '') + '>' +
                '<td class="text-muted">' + (start + i + 1) + '</td>' +
                '<td>' + esc(r.nama_staff) + (self ? ' <span class="text-muted small">(you)</span>' : '') + '</td>' +
                '<td class="text-muted">' + esc(r.department || '-') + '</td>' +
                '<td>' + rolePill(r.edm) + '</td>' +
                '<td class="text-end">' + (self ? '' :
                    '<div class="dropdown">' +
                        '<button type="button" class="edm-row-kebab" data-bs-toggle="dropdown" aria-expanded="false"' +
                            ' data-bs-popper-config=\'{"strategy":"fixed"}\' aria-label="Actions" title="Actions">' +
                            '<i class="bi bi-three-dots-vertical"></i></button>' +
                        '<ul class="dropdown-menu dropdown-menu-end edm-row-menu">' +
                            '<li><button type="button" class="dropdown-item" data-act="edit">Edit</button></li>' +
                            '<li><hr class="dropdown-divider"></li>' +
                            '<li><button type="button" class="dropdown-item text-danger" data-act="delete">Remove access</button></li>' +
                        '</ul>' +
                    '</div>') + '</td>' +
            '</tr>';
        }).join('') : '<tr><td colspan="5" class="text-center text-muted py-3">No records found.</td></tr>';
        renderPager(total, pages, start, rows.length);
    }

    function pageBtn(p) {
        return '<button type="button" class="edm-pager-btn' + (p === page ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
    }
    function renderPager(total, pages, start, count) {
        if (!total) { pagerEl.innerHTML = ''; return; }
        var sel = '<select class="edm-perpage-select" id="edm-users-perpage">' + [10, 30, 50, 100].map(function (o) {
            return '<option value="' + o + '"' + (perPage === o ? ' selected' : '') + '>' + o + '</option>';
        }).join('') + '</select>';
        var btns = '<button type="button" class="edm-pager-btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>Previous</button>';
        var from = Math.max(1, page - 2), to = Math.min(pages, page + 2);
        if (from > 1) { btns += pageBtn(1) + (from > 2 ? '<span class="edm-pager-gap">...</span>' : ''); }
        for (var p = from; p <= to; p++) { btns += pageBtn(p); }
        if (to < pages) { btns += (to < pages - 1 ? '<span class="edm-pager-gap">...</span>' : '') + pageBtn(pages); }
        btns += '<button type="button" class="edm-pager-btn" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>Next</button>';
        pagerEl.innerHTML = '<div class="edm-pager-left">Show ' + sel + ' entries</div>' +
            '<div class="d-flex align-items-center gap-2"><span class="edm-pager-info">Showing ' + (start + 1) + ' to ' + (start + count) +
            ' of ' + total + ' entries</span><div class="edm-pager-bar">' + btns + '</div></div>';
    }
    pagerEl.addEventListener('click', function (e) {
        var b = e.target.closest('.edm-pager-btn[data-page]');
        if (!b || b.disabled) { return; }
        page = parseInt(b.getAttribute('data-page'), 10);
        renderPage();
    });
    pagerEl.addEventListener('change', function (e) {
        if (e.target.id !== 'edm-users-perpage') { return; }
        perPage = parseInt(e.target.value, 10);
        page = 1;
        renderPage();
    });

    fName.addEventListener('input', applyFilter);
    fRole.addEventListener('change', applyFilter);
    document.getElementById('edm-users-filter-reset').addEventListener('click', function () {
        fName.value = '';
        fRole.value = '0';
        applyFilter();
    });

    rowsEl.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-act]');
        if (!btn) { return; }
        var id = parseInt(btn.closest('tr').getAttribute('data-id'), 10);
        var row = all.filter(function (r) { return r.id === id; })[0];
        if (!row) { return; }
        if (btn.getAttribute('data-act') === 'edit') {
            pick(row);
            document.getElementById('edm-users-panel-title').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        window.edmConfirm('Remove EDM access for ' + row.nama_staff + '? They will no longer be able to open EDM.', function () {
            call('users_delete', 'DELETE', { id: id }).then(function (res) {
                if (!res.success) { showAlert(firstError(res)); return; }
                if (picked && picked.id === id) { resetPanel(); }
                formMessage('Access removed for ' + row.nama_staff + '.', true);
                load();
            }).catch(function () { showAlert('Could not reach the server.'); });
        });
    });

    // ---- panel: search, details, role ----

    function closeResults() {
        resultsEl.hidden = true;
        searchEl.setAttribute('aria-expanded', 'false');
    }
    function renderResults(msg) {
        resultsEl.innerHTML = msg
            ? '<li class="edm-users-results-empty">' + esc(msg) + '</li>'
            : results.map(function (r, i) {
                return '<li role="option" data-i="' + i + '">' +
                    '<span class="edm-users-results-name">' + esc(r.nama_staff) + '</span>' +
                    '<span class="edm-users-results-meta">' + esc(r.department || '-') + '</span>' +
                    '<span class="edm-users-results-role">' + rolePill(r.edm) + '</span>' +
                '</li>';
            }).join('');
        resultsEl.hidden = false;
        searchEl.setAttribute('aria-expanded', 'true');
    }
    searchEl.addEventListener('input', function () {
        clearTimeout(searchTimer);
        var q = searchEl.value.trim();
        if (q.length < 2) { closeResults(); return; }
        searchTimer = setTimeout(function () {
            var mine = ++searchSeq;
            renderResults('Searching...');
            call('users_search', 'GET', null, '&q=' + encodeURIComponent(q)).then(function (res) {
                if (mine !== searchSeq) { return; }
                results = (res.success && res.data) || [];
                renderResults(results.length ? '' : (res.success ? 'No active staff match "' + q + '".' : firstError(res)));
            }).catch(function () { if (mine === searchSeq) { renderResults('Could not reach the server.'); } });
        }, 250);
    });
    resultsEl.addEventListener('mousedown', function (e) {
        var li = e.target.closest('li[data-i]');
        if (!li) { return; }
        e.preventDefault();
        pick(results[parseInt(li.getAttribute('data-i'), 10)]);
    });
    searchEl.addEventListener('blur', function () { setTimeout(closeResults, 150); });
    searchEl.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeResults(); }
        if (e.key === 'Enter' && results.length === 1 && !resultsEl.hidden) { e.preventDefault(); pick(results[0]); }
    });

    function pick(row) {
        picked = row;
        closeResults();
        formAlert.hidden = true;
        searchEl.value = row.nama_staff;
        document.getElementById('edm-users-info-name').textContent = row.nama_staff;
        document.getElementById('edm-users-info-dept').textContent = row.department || '-';
        document.getElementById('edm-users-info-status').textContent = row.status || '-';
        document.getElementById('edm-users-info-role').innerHTML = rolePill(row.edm);
        infoEl.hidden = false;
        var self = row.id === SELF;
        [].forEach.call(rolesEl.querySelectorAll('input'), function (r) {
            r.checked = parseInt(r.value, 10) === row.edm;
            r.disabled = self;
        });
        rolesEl.hidden = false;
        actionsEl.hidden = false;
        var update = row.edm > 0;
        titleEl.textContent = update ? 'Update EDM Access' : 'Add EDM Access';
        saveBtn.textContent = update ? 'Update Access' : 'Add Access';
        saveBtn.disabled = self;
        if (self) { formMessage('This is you - ask another superadmin to change your own access.', false); }
        renderPage();
    }

    function resetPanel() {
        picked = null;
        searchEl.value = '';
        infoEl.hidden = true;
        rolesEl.hidden = true;
        actionsEl.hidden = true;
        formAlert.hidden = true;
        titleEl.textContent = 'Add / Update EDM Access';
        saveBtn.textContent = 'Add Access';
        [].forEach.call(rolesEl.querySelectorAll('input'), function (r) { r.checked = false; r.disabled = false; });
        renderPage();
    }
    document.getElementById('edm-users-cancel').addEventListener('click', resetPanel);

    saveBtn.addEventListener('click', function () {
        if (!picked) { return; }
        var checked = rolesEl.querySelector('input:checked');
        if (!checked) { formMessage('Choose a role.', false); return; }
        var role = parseInt(checked.value, 10);
        var wasNew = picked.edm === 0;
        saveBtn.disabled = true;
        call('users_save', 'PUT', { id: picked.id, edm: role }).then(function (res) {
            saveBtn.disabled = false;
            if (!res.success) { formMessage(firstError(res), false); return; }
            var name = picked.nama_staff;
            resetPanel();
            formMessage((wasNew ? 'Access added: ' : 'Access updated: ') + name + ' is now ' + (ROLES[role] ? ROLES[role].label : role) + '.', true);
            load();
        }).catch(function () {
            saveBtn.disabled = false;
            formMessage('Could not reach the server.', false);
        });
    });

    load();
})();
