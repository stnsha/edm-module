/**
 * Shared list + CRUD widget for EDM admin screens.
 *
 * A page sets window.EDM_CRUD_CONFIG then loads this script (via $page_js).
 * The page must contain:
 *   <div id="edm-crud" data-... > with the standard markup emitted by
 *   crud_screen() in edm/partials.php  (table#edm-crud-rows, the modal, etc.)
 *
 * Config shape:
 * {
 *   api:     'audience/api.php',          // relative to EDM_MODULE_BASE
 *   title:   'Lists',
 *   entity:  'list',                      // used in confirm() text
 *   idKey:   'id',
 *   actions: { list, create, update, delete },   // api.php action names
 *   columns: [ { key, label, type } ],   // type: text|bool|count|badge
 *   badges:  { status: { verified:'edm-pill-success', ... } },  // for type:badge -
 *            // always edm-pill-* (secondary|success|danger|warning|info|primary|dark),
 *            // NEVER Bootstrap's .badge/text-bg-* - a legacy common/css/page.css
 *            // rule hijacks .badge as an absolutely-positioned notification dot
 *            // (see the .edm-pill note in css/style.css).
 *   fields:  [ { name, label, type, required, default, options, help } ]
 *            // type: text|textarea|number|email|checkbox|select|date|rules
 * }
 */
(function () {
    'use strict';

    var cfg = window.EDM_CRUD_CONFIG;
    if (!cfg) { return; }

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var API  = BASE + cfg.api;
    var idKey = cfg.idKey || 'id';

    var rowsEl  = document.getElementById('edm-crud-rows');
    var alertEl = document.getElementById('edm-crud-alert');
    var pagerEl = document.getElementById('edm-crud-pager');
    var modalEl = document.getElementById('edm-crud-modal');
    var formEl  = document.getElementById('edm-crud-form');
    var bodyEl  = document.getElementById('edm-crud-form-body');
    var titleEl = document.getElementById('edm-crud-modal-title');
    var errEl   = document.getElementById('edm-crud-form-error');
    var saveBtn = document.getElementById('edm-crud-save');
    var addBtn  = document.getElementById('edm-crud-add');

    var modal = new bootstrap.Modal(modalEl);
    var current = [];
    var page = 1;
    var perPage = 30;
    var colCount = cfg.columns.length + 2; // # + configured columns + Action
    var quillFields = {}; // field name -> Quill instance, for type:'richtext'

    if (cfg.noCreate && addBtn) { addBtn.hidden = true; }

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function showAlert(msg) { alertEl.textContent = msg || 'Something went wrong.'; alertEl.hidden = false; }
    function clearAlert() { alertEl.hidden = true; }

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
                if (Object.prototype.hasOwnProperty.call(res.errors, k)) { return res.errors[k][0]; }
            }
        }
        return (res && res.message) || 'Request failed.';
    }

    // ---- table ----

    function cell(row, col) {
        var v = row[col.key];
        if (col.type === 'bool') {
            return v ? '<span class="edm-pill edm-pill-success">Yes</span>' : '<span class="edm-pill edm-pill-secondary">No</span>';
        }
        if (col.type === 'count') { return v == null ? '0' : esc(v); }
        if (col.type === 'badge') {
            var map = (cfg.badges && cfg.badges[col.key]) || {};
            return '<span class="edm-pill ' + (map[v] || 'edm-pill-secondary') + '">' + esc(v) + '</span>';
        }
        return v == null || v === '' ? '<span class="text-muted">-</span>' : esc(v);
    }

    function renderRows(rows, startIdx) {
        if (!rows.length) {
            rowsEl.innerHTML = '<tr><td colspan="' + colCount + '" class="text-center text-muted py-4">Nothing here yet.</td></tr>';
            return;
        }
        rowsEl.innerHTML = rows.map(function (row, i) {
            var extra = (cfg.rowActions || []).map(function (a, ai) {
                if (a.visible && !a.visible(row)) { return ''; }
                if (a.link) {
                    return '<a class="btn btn-sm ' + (a.className || 'btn-outline-secondary') + '" href="' +
                        esc(BASE + a.link(row)) + '">' + esc(a.label) + '</a>';
                }
                return '<button type="button" class="btn btn-sm ' + (a.className || 'btn-outline-secondary') +
                    '" data-act="custom" data-i="' + ai + '">' + esc(a.label) + '</button>';
            }).join('');
            return '<tr data-id="' + esc(row[idKey]) + '">' +
                '<td class="text-muted">' + (startIdx + i + 1) + '</td>' +
                cfg.columns.map(function (col) { return '<td>' + cell(row, col) + '</td>'; }).join('') +
                '<td class="text-end"><div class="d-flex justify-content-end align-items-center gap-2">' +
                    extra +
                    (cfg.noEdit ? '' : '<button type="button" class="btn btn-sm btn-outline-secondary edm-icon-btn" data-act="edit" title="Edit"><i class="bi bi-pencil"></i></button>') +
                    (cfg.noDelete ? '' : '<button type="button" class="btn btn-sm btn-outline-danger edm-icon-btn" data-act="delete" title="Delete"><i class="bi bi-trash"></i></button>') +
                '</div></td>' +
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
        var selHtml = '<select class="edm-perpage-select" id="edm-crud-perpage">';
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
        var sel = document.getElementById('edm-crud-perpage');
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
        rowsEl.innerHTML = '<tr><td colspan="' + colCount + '" class="text-center text-muted py-4">Loading...</td></tr>';
        call(cfg.actions.list).then(function (res) {
            if (!res.success) { showAlert(res.message); current = []; renderPage(); return; }
            current = res.data || [];
            page = 1;
            renderPage();
        }).catch(function () { showAlert('Could not reach the server.'); current = []; renderPage(); });
    }

    // ---- form ----

    function fieldControl(f) {
        var id = 'edm-f-' + f.name;
        if (f.type === 'textarea') {
            return '<textarea class="form-control" id="' + id + '" rows="3"></textarea>';
        }
        if (f.type === 'richtext') {
            return '<div id="' + id + '" class="edm-quill-field"></div>';
        }
        if (f.type === 'checkbox') {
            return '<div class="form-check"><input class="form-check-input" type="checkbox" id="' + id + '">' +
                '<label class="form-check-label" for="' + id + '">' + esc(f.label) + '</label></div>';
        }
        if (f.type === 'select') {
            return '<select class="form-select" id="' + id + '">' +
                (f.options || []).map(function (o) {
                    var val = (o && o.value !== undefined) ? o.value : o;
                    var lab = (o && o.label !== undefined) ? o.label : o;
                    return '<option value="' + esc(val) + '">' + esc(lab) + '</option>';
                }).join('') + '</select>';
        }
        if (f.type === 'rules') {
            return '<div id="' + id + '" class="edm-rules">' +
                '<div class="mb-2 d-flex align-items-center gap-2">Match ' +
                    '<select class="form-select form-select-sm w-auto edm-rules-match">' +
                        '<option value="all">all</option><option value="any">any</option>' +
                    '</select> of:' +
                '</div>' +
                '<div class="edm-rules-rows"></div>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary edm-rules-add">Add condition</button>' +
            '</div>';
        }
        var t = (f.type === 'email' || f.type === 'number' || f.type === 'date') ? f.type : 'text';
        return '<input type="' + t + '" class="form-control" id="' + id + '">';
    }

    function buildForm() {
        bodyEl.innerHTML = '<input type="hidden" id="edm-crud-id">' + cfg.fields.map(function (f) {
            if (f.type === 'checkbox') {
                return '<div class="mb-3">' + fieldControl(f) + (f.help ? '<div class="form-text">' + esc(f.help) + '</div>' : '') + '</div>';
            }
            return '<div class="mb-3">' +
                '<label class="form-label" for="edm-f-' + f.name + '">' + esc(f.label) +
                    (f.required ? ' <span class="text-danger" aria-hidden="true">*</span>' : '') + '</label>' +
                fieldControl(f) +
                (f.help ? '<div class="form-text">' + esc(f.help) + '</div>' : '') +
            '</div>';
        }).join('');

        var add = bodyEl.querySelector('.edm-rules-add');
        if (add) { add.addEventListener('click', function () { addRuleRow(); }); }

        quillFields = {};
        if (window.Quill) {
            cfg.fields.forEach(function (f) {
                if (f.type === 'richtext') {
                    quillFields[f.name] = new Quill('#edm-f-' + f.name, {
                        theme: 'snow',
                        modules: {
                            toolbar: [
                                [{ header: [1, 2, 3, false] }],
                                ['bold', 'italic', 'underline', 'link'],
                                [{ list: 'ordered' }, { list: 'bullet' }],
                                ['clean']
                            ]
                        }
                    });
                }
            });
        }
    }

    function addRuleRow(rule) {
        var wrap = bodyEl.querySelector('.edm-rules-rows');
        if (!wrap) { return; }
        var div = document.createElement('div');
        div.className = 'd-flex gap-2 mb-2 edm-rule-row';
        div.innerHTML =
            '<input type="text" class="form-control form-control-sm edm-rule-field" placeholder="field" value="' + esc(rule && rule.field || '') + '">' +
            '<select class="form-select form-select-sm edm-rule-op" style="max-width:8rem;">' +
                ['is', 'is not', 'contains', '>', '<', 'in'].map(function (o) {
                    return '<option' + (rule && rule.op === o ? ' selected' : '') + '>' + o + '</option>';
                }).join('') +
            '</select>' +
            '<input type="text" class="form-control form-control-sm edm-rule-value" placeholder="value" value="' + esc(rule && rule.value != null ? rule.value : '') + '">' +
            '<button type="button" class="btn btn-sm btn-outline-danger edm-rule-del">&times;</button>';
        div.querySelector('.edm-rule-del').addEventListener('click', function () { div.remove(); });
        wrap.appendChild(div);
    }

    function setValue(f, row) {
        var el = document.getElementById('edm-f-' + f.name);
        if (!el) { return; }
        if (f.type === 'checkbox') {
            el.checked = row ? !!row[f.name] : (f.default !== undefined ? !!f.default : false);
            return;
        }
        if (f.type === 'rules') {
            var def = (row && row[f.name]) || { match: 'all', rules: [] };
            el.querySelector('.edm-rules-match').value = def.match || 'all';
            el.querySelector('.edm-rules-rows').innerHTML = '';
            (def.rules || []).forEach(function (r) { addRuleRow(r); });
            return;
        }
        if (f.type === 'richtext') {
            var qf = quillFields[f.name];
            if (qf) { qf.root.innerHTML = row && row[f.name] != null ? row[f.name] : ''; }
            return;
        }
        if (row && row[f.name] != null) {
            el.value = row[f.name];
            return;
        }
        if (f.default !== undefined) {
            el.value = f.default;
            return;
        }
        // A <select> with no explicit default: fall back to its first option
        // rather than '', which matches no <option> and leaves it blank/unset.
        el.value = (f.type === 'select' && el.options.length) ? el.options[0].value : '';
    }

    function getValue(f) {
        var el = document.getElementById('edm-f-' + f.name);
        if (!el) { return undefined; }
        if (f.type === 'checkbox') { return el.checked; }
        if (f.type === 'rules') {
            var rows = [].slice.call(el.querySelectorAll('.edm-rule-row')).map(function (r) {
                return {
                    field: r.querySelector('.edm-rule-field').value.trim(),
                    op: r.querySelector('.edm-rule-op').value,
                    value: r.querySelector('.edm-rule-value').value.trim()
                };
            }).filter(function (r) { return r.field !== ''; });
            return { match: el.querySelector('.edm-rules-match').value, rules: rows };
        }
        if (f.type === 'richtext') {
            var qf = quillFields[f.name];
            return qf ? qf.root.innerHTML : '';
        }
        var v = el.value.trim();
        return v === '' ? null : v;
    }

    function openModal(row) {
        errEl.hidden = true; errEl.textContent = '';
        titleEl.textContent = (row ? 'Edit ' : 'Add ') + (cfg.entity || 'item');
        document.getElementById('edm-crud-id').value = row ? row[idKey] : '';
        cfg.fields.forEach(function (f) { setValue(f, row); });
        modal.show();
    }

    // ---- events ----

    if (addBtn) { addBtn.addEventListener('click', function () { openModal(null); }); }

    formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        errEl.hidden = true;
        saveBtn.disabled = true;

        var id = document.getElementById('edm-crud-id').value;
        var payload = {};
        cfg.fields.forEach(function (f) {
            var val = getValue(f);
            if (val !== undefined) { payload[f.name] = val; }
        });
        var action = id ? cfg.actions.update : cfg.actions.create;
        var method = id ? 'PUT' : 'POST';
        if (id) { payload[idKey] = /^\d+$/.test(id) ? parseInt(id, 10) : id; }

        call(action, method, payload).then(function (res) {
            saveBtn.disabled = false;
            if (!res.success) { errEl.textContent = firstError(res); errEl.hidden = false; return; }
            modal.hide();
            load();
        }).catch(function () {
            saveBtn.disabled = false;
            errEl.textContent = 'Could not reach the server.'; errEl.hidden = false;
        });
    });

    rowsEl.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-act]');
        if (!btn) { return; }
        var tr = btn.closest('tr');
        var id = tr.getAttribute('data-id');
        var row = current.filter(function (x) { return String(x[idKey]) === String(id); })[0];
        var act = btn.getAttribute('data-act');

        if (act === 'edit' && row) { openModal(row); return; }

        if (act === 'custom' && row) {
            var a = (cfg.rowActions || [])[parseInt(btn.getAttribute('data-i'), 10)];
            if (!a) { return; }
            var runCustom = function () {
                if (a.handler) { a.handler(row, load); return; }
                var body = {};
                body[idKey] = /^\d+$/.test(id) ? parseInt(id, 10) : id;
                call(a.action, a.method || 'POST', body).then(function (res) {
                    if (!res.success) { showAlert(firstError(res)); return; }
                    load();
                });
            };
            if (a.confirm) { window.edmConfirm(a.confirm, runCustom); } else { runCustom(); }
            return;
        }

        if (act === 'delete') {
            window.edmConfirm('Delete this ' + (cfg.entity || 'item') + '?', function () {
                var payload = {};
                payload[idKey] = /^\d+$/.test(id) ? parseInt(id, 10) : id;
                call(cfg.actions['delete'], 'DELETE', payload).then(function (res) {
                    if (!res.success) { showAlert(firstError(res)); return; }
                    load();
                });
            });
        }
    });

    buildForm();
    load();
})();
