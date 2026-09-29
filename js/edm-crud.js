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
 *   badges:  { status: { 2: { cls:'edm-pill-success', label:'Verified' }, ... } },  // for type:badge -
 *            // key is the raw status value (int); value is {cls,label} or a
 *            // plain class string (label then falls back to the raw value) -
 *            // always edm-pill-* (secondary|success|danger|warning|info|primary|dark),
 *            // NEVER Bootstrap's .badge/text-bg-* - a legacy common/css/page.css
 *            // rule hijacks .badge as an absolutely-positioned notification dot
 *            // (see the .edm-pill note in css/style.css).
 *   fields:  [ { name, label, type, required, default, options, help } ]
 *            // type: text|textarea|number|email|checkbox|select|date|datetime|rules
 *            // rules: the segment condition builder. Needs
 *            //   catalog: { fields: { key: { label, type, group, options? } },
 *            //              ops: { type: [op] }, op_labels: { op: label }, no_value: [op] }
 *            // (SegmentQuery::fields() etc., rendered by audience/segments.php) and
 *            // optionally count: { action, listField } for a live "N of M match"
 *            // line (POST { definition, list_id } -> { matched, total, sample }).
 *            // datetime: <input type="datetime-local">; the API's
 *            // 'd-m-Y H:i:s' value is converted for the input only.
 *   columns[].value: optional function (row) -> text, for a derived or
 *            // nested value (e.g. row.list.name); escaped like a text cell.
 *   canEdit / canDelete: optional function (row) -> bool, hides the
 *            // row's Edit / Delete button when false.
 *   Row actions (Edit, then rowActions, then Delete) always render as a
 *            // vertical-ellipsis dropdown in the Action column (GetResponse
 *            // style); rowActions[].className is not used by the menu.
 *   listParams: optional { name: value } query parameters sent with the
 *            // list action (e.g. { list_id: 5 } on audience/contacts.php).
 *   onFormOpen: optional function (row|null, bodyEl) - runs after the modal
 *            // form is filled, e.g. to wire dependent selects (campaign/index.php).
 *   saveAndGo: optional { label, icon, link: function (savedRow) -> path }
 *            // adds a second submit button to the modal that saves, then
 *            // navigates to BASE + link(savedRow) instead of closing.
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

    function call(action, method, body, params) {
        var qs = '';
        for (var k in (params || {})) {
            if (Object.prototype.hasOwnProperty.call(params, k)) { qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }
        }
        return fetch(API + '?action=' + encodeURIComponent(action) + qs, {
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
        var v = col.value ? col.value(row) : row[col.key];
        if (col.type === 'bool') {
            var onLabel = col.trueLabel || 'Yes', offLabel = col.falseLabel || 'No';
            return v ? '<span class="edm-pill edm-pill-success">' + esc(onLabel) + '</span>' : '<span class="edm-pill edm-pill-secondary">' + esc(offLabel) + '</span>';
        }
        if (col.type === 'count') { return v == null ? '0' : esc(v); }
        if (col.type === 'badge') {
            var map = (cfg.badges && cfg.badges[col.key]) || {};
            var entry = map[v];
            var cls = (entry && entry.cls) || (typeof entry === 'string' ? entry : 'edm-pill-secondary');
            var label = (entry && entry.label) || (typeof entry === 'string' ? v : v);
            return '<span class="edm-pill ' + cls + '">' + esc(label) + '</span>';
        }
        return v == null || v === '' ? '<span class="text-muted">-</span>' : esc(v);
    }

    function renderRows(rows, startIdx) {
        if (!rows.length) {
            rowsEl.innerHTML = '<tr><td colspan="' + colCount + '" class="text-center text-muted py-4">Nothing here yet.</td></tr>';
            return;
        }
        rowsEl.innerHTML = rows.map(function (row, i) {
            return '<tr data-id="' + esc(row[idKey]) + '">' +
                '<td class="text-muted">' + (startIdx + i + 1) + '</td>' +
                cfg.columns.map(function (col) { return '<td>' + cell(row, col) + '</td>'; }).join('') +
                '<td class="text-end">' + actionMenu(row) + '</td>' +
            '</tr>';
        }).join('');
    }

    // Vertical-ellipsis dropdown holding every row action. Items carry the
    // data-act / data-i attributes the rowsEl click handler reads. Fixed
    // popper strategy keeps the menu from being clipped by .table-responsive.
    function actionMenu(row) {
        var items = [];
        if (!cfg.noEdit && !(cfg.canEdit && !cfg.canEdit(row))) {
            items.push('<li><button type="button" class="dropdown-item" data-act="edit">Edit</button></li>');
        }
        items = items.concat((cfg.rowActions || []).map(function (a, ai) {
            if (a.visible && !a.visible(row)) { return ''; }
            var label = typeof a.label === 'function' ? a.label(row) : a.label;
            if (a.link) {
                return '<li><a class="dropdown-item" href="' + esc(BASE + a.link(row)) + '">' + esc(label) + '</a></li>';
            }
            return '<li><button type="button" class="dropdown-item" data-act="custom" data-i="' + ai + '">' + esc(label) + '</button></li>';
        }));
        if (!cfg.noDelete && !(cfg.canDelete && !cfg.canDelete(row))) {
            items.push('<li><hr class="dropdown-divider"></li>' +
                '<li><button type="button" class="dropdown-item text-danger" data-act="delete">Delete</button></li>');
        }
        var html = items.join('');
        if (!html) { return ''; }
        return '<div class="dropdown">' +
            '<button type="button" class="edm-row-kebab" data-bs-toggle="dropdown" aria-expanded="false"' +
                ' data-bs-popper-config=\'{"strategy":"fixed"}\' aria-label="Actions" title="Actions">' +
                '<i class="bi bi-three-dots-vertical"></i></button>' +
            '<ul class="dropdown-menu dropdown-menu-end edm-row-menu">' + html + '</ul>' +
        '</div>';
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
        call(cfg.actions.list, 'GET', null, cfg.listParams).then(function (res) {
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
                '<div class="edm-rules-match-row">Contacts must match ' +
                    '<select class="form-select form-select-sm w-auto edm-rules-match" aria-label="Match all or any">' +
                        '<option value="all">all</option><option value="any">any</option>' +
                    '</select> of these conditions:' +
                '</div>' +
                '<div class="edm-rules-rows"></div>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary edm-rules-add"><i class="bi bi-plus-lg me-1"></i>Add condition</button>' +
                (f.count ? '<div class="edm-rules-count" aria-live="polite"></div>' : '') +
            '</div>';
        }
        if (f.type === 'datetime') {
            return '<input type="datetime-local" class="form-control" id="' + id + '">';
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

        cfg.fields.forEach(function (f) {
            if (f.type !== 'rules') { return; }
            var el = document.getElementById('edm-f-' + f.name);
            el.querySelector('.edm-rules-add').addEventListener('click', function () {
                addRuleRow(f);
                scheduleCount(f);
            });
            if (f.count) {
                bodyEl.addEventListener('change', function () { scheduleCount(f); });
                bodyEl.addEventListener('input', function () { scheduleCount(f); });
            }
        });

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

    // ---- rules (segment conditions) ----

    var LEGACY_OPS = { 'is not': 'is_not', '>': 'gt', '<': 'lt' };

    function addRuleRow(f, rule) {
        var el = document.getElementById('edm-f-' + f.name);
        var cat = f.catalog || { fields: {}, ops: {}, op_labels: {}, no_value: [] };
        rule = rule || {};
        // Rules from the first free-text builder: bare custom field key, old op names.
        var fieldKey = rule.field || '';
        if (fieldKey && !cat.fields[fieldKey] && cat.fields['field:' + fieldKey]) { fieldKey = 'field:' + fieldKey; }
        var op = LEGACY_OPS[rule.op] || rule.op || '';

        var groups = {};
        Object.keys(cat.fields).forEach(function (k) {
            var g = cat.fields[k].group || 'Fields';
            (groups[g] = groups[g] || []).push(k);
        });
        var div = document.createElement('div');
        div.className = 'edm-rule-row';
        div.innerHTML =
            '<select class="form-select form-select-sm edm-rule-field" aria-label="Field">' +
                '<option value="">Choose a field</option>' +
                Object.keys(groups).map(function (g) {
                    return '<optgroup label="' + esc(g) + '">' + groups[g].map(function (k) {
                        return '<option value="' + esc(k) + '"' + (k === fieldKey ? ' selected' : '') + '>' + esc(cat.fields[k].label) + '</option>';
                    }).join('') + '</optgroup>';
                }).join('') +
                (fieldKey && !cat.fields[fieldKey] ? '<option value="' + esc(fieldKey) + '" selected>' + esc(fieldKey) + ' (missing)</option>' : '') +
            '</select>' +
            '<select class="form-select form-select-sm edm-rule-op" aria-label="Comparison"></select>' +
            '<div class="edm-rule-value-wrap"></div>' +
            '<button type="button" class="btn btn-sm btn-outline-danger edm-rule-del" title="Remove condition" aria-label="Remove condition"><i class="bi bi-x-lg"></i></button>';
        var fieldSel = div.querySelector('.edm-rule-field');
        var opSel = div.querySelector('.edm-rule-op');
        var valWrap = div.querySelector('.edm-rule-value-wrap');

        function fillOps(selected) {
            var def = cat.fields[fieldSel.value];
            var ops = def ? (cat.ops[def.type] || []) : [];
            opSel.innerHTML = ops.map(function (o) {
                return '<option value="' + esc(o) + '"' + (o === selected ? ' selected' : '') + '>' + esc(cat.op_labels[o] || o) + '</option>';
            }).join('');
            opSel.disabled = !ops.length;
        }
        function fillValue(value) {
            var def = cat.fields[fieldSel.value];
            if (!def || (cat.no_value || []).indexOf(opSel.value) !== -1) { valWrap.innerHTML = ''; return; }
            var v = value == null ? '' : String(value);
            if (def.type === 'select') {
                valWrap.innerHTML = '<select class="form-select form-select-sm edm-rule-value" aria-label="Value">' +
                    '<option value="">Choose an option</option>' +
                    (def.options || []).map(function (o) {
                        return '<option value="' + esc(o.value) + '"' + (String(o.value).toLowerCase() === v.toLowerCase() ? ' selected' : '') + '>' + esc(o.label) + '</option>';
                    }).join('') + '</select>';
                return;
            }
            var type = def.type === 'number' ? 'number' : def.type === 'date' ? 'date' : 'text';
            if (type === 'date') {
                var dm = /^(\d{2})-(\d{2})-(\d{4})$/.exec(v);
                if (dm) { v = dm[3] + '-' + dm[2] + '-' + dm[1]; }
            }
            valWrap.innerHTML = '<input type="' + type + '" class="form-control form-control-sm edm-rule-value" aria-label="Value"' +
                (type === 'number' ? ' step="any"' : '') + ' placeholder="Value" value="' + esc(v) + '">';
        }

        fieldSel.addEventListener('change', function () { fillOps(''); fillValue(''); });
        opSel.addEventListener('change', function () {
            var cur = valWrap.querySelector('.edm-rule-value');
            fillValue(cur ? cur.value : '');
        });
        div.querySelector('.edm-rule-del').addEventListener('click', function () {
            div.remove();
            scheduleCount(f);
        });
        fillOps(op);
        fillValue(rule.value);
        el.querySelector('.edm-rules-rows').appendChild(div);
    }

    // Live "N of M contacts match" under the builder (debounced).
    var countTimer = null;
    var countSeq = 0;
    function scheduleCount(f) {
        if (!f.count) { return; }
        clearTimeout(countTimer);
        countTimer = setTimeout(function () { refreshCount(f); }, 350);
    }
    function refreshCount(f) {
        var box = document.querySelector('#edm-f-' + f.name + ' .edm-rules-count');
        if (!box) { return; }
        var def = getValue(f);
        var listEl = f.count.listField ? document.getElementById('edm-f-' + f.count.listField) : null;
        var listId = listEl && listEl.value ? parseInt(listEl.value, 10) : null;
        var scope = listId ? 'on this list' : 'across all lists';
        if (!def.rules.length) {
            box.className = 'edm-rules-count text-muted';
            box.textContent = 'Add a condition to see how many contacts match.';
            return;
        }
        var mine = ++countSeq;
        box.className = 'edm-rules-count text-muted';
        box.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Counting contacts...';
        call(f.count.action, 'POST', { definition: def, list_id: listId }).then(function (res) {
            if (mine !== countSeq) { return; }
            if (!res.success) {
                box.className = 'edm-rules-count is-warn';
                box.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>' + esc(firstError(res));
                return;
            }
            var d = res.data;
            box.className = 'edm-rules-count' + (d.matched ? ' is-ok' : ' is-warn');
            box.innerHTML = '<i class="bi bi-people me-1"></i><strong>' + Number(d.matched).toLocaleString('en-US') + '</strong> of ' +
                Number(d.total).toLocaleString('en-US') + ' subscribed contacts ' + scope + ' match.' +
                (d.sample && d.sample.length
                    ? '<div class="edm-rules-sample">e.g. ' + d.sample.map(function (m) {
                        return esc(m.name ? m.name + ' <' + m.email + '>' : m.email);
                    }).join(', ') + '</div>'
                    : '');
        }).catch(function () {
            if (mine !== countSeq) { return; }
            box.className = 'edm-rules-count is-warn';
            box.textContent = 'Could not count contacts.';
        });
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
            (def.rules && def.rules.length ? def.rules : [null]).forEach(function (r) { addRuleRow(f, r); });
            scheduleCount(f);
            return;
        }
        if (f.type === 'richtext') {
            var qf = quillFields[f.name];
            if (qf) { qf.root.innerHTML = row && row[f.name] != null ? row[f.name] : ''; }
            return;
        }
        if (f.type === 'datetime') {
            // API gives 'd-m-Y H:i:s'; datetime-local wants 'Y-m-dTH:i'.
            var m = /^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2})/.exec((row && row[f.name]) || '');
            el.value = m ? m[3] + '-' + m[2] + '-' + m[1] + 'T' + m[4] + ':' + m[5] : '';
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
                var valEl = r.querySelector('.edm-rule-value');
                return {
                    field: r.querySelector('.edm-rule-field').value,
                    op: r.querySelector('.edm-rule-op').value,
                    value: valEl ? valEl.value.trim() : ''
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
        if (cfg.onFormOpen) { cfg.onFormOpen(row, bodyEl); }
        modal.show();
    }

    // ---- events ----

    if (addBtn) { addBtn.addEventListener('click', function () { openModal(null); }); }

    var goBtn = null;
    if (cfg.saveAndGo) {
        goBtn = document.createElement('button');
        goBtn.type = 'submit';
        goBtn.className = 'btn btn-primary btn-sm';
        goBtn.setAttribute('data-go', '1');
        goBtn.innerHTML = (cfg.saveAndGo.icon ? '<i class="bi ' + esc(cfg.saveAndGo.icon) + '"></i> ' : '') + esc(cfg.saveAndGo.label);
        saveBtn.className = 'btn btn-outline-primary btn-sm';
        saveBtn.parentNode.appendChild(goBtn);
    }

    formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        var go = !!(goBtn && e.submitter === goBtn);
        errEl.hidden = true;
        saveBtn.disabled = true;
        if (goBtn) { goBtn.disabled = true; }

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
            if (goBtn) { goBtn.disabled = false; }
            if (!res.success) { errEl.textContent = firstError(res); errEl.hidden = false; return; }
            if (go) {
                var saved = res.data || {};
                if (saved[idKey] == null) { saved[idKey] = payload[idKey]; }
                window.location.href = BASE + cfg.saveAndGo.link(saved);
                return;
            }
            modal.hide();
            load();
        }).catch(function () {
            saveBtn.disabled = false;
            if (goBtn) { goBtn.disabled = false; }
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
                var body = a.body ? a.body(row) : {};
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

    // Lets a page's own extra UI (e.g. Files > Upload image) refresh the list.
    window.edmCrudReload = load;

    buildForm();
    load();
})();
