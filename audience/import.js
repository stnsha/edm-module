/**
 * Contacts > Import contacts (audience/import.php). Three steps, modelled on
 * GetResponse's import:
 *   1. list + file (drag and drop or picker) or pasted text + permission
 *      -> audience/api.php import_preview (multipart)
 *   2. match each column to Email / Name / Member code / a custom field
 *      -> import_run
 *   3. summary counts
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var API  = BASE + 'audience/api.php';

    var alertEl  = document.getElementById('edm-imp-alert');
    var step1    = document.getElementById('edm-imp-step1');
    var step2    = document.getElementById('edm-imp-step2');
    var step3    = document.getElementById('edm-imp-step3');
    var listEl   = document.getElementById('edm-imp-list');
    var fileEl   = document.getElementById('edm-imp-file');
    var fileName = document.getElementById('edm-imp-file-name');
    var dropEl   = document.getElementById('edm-imp-drop');
    var pasteEl  = document.getElementById('edm-imp-paste');
    var consent  = document.getElementById('edm-imp-consent');
    var nextBtn  = document.getElementById('edm-imp-next');
    var headerCb = document.getElementById('edm-imp-header');
    var mapHead  = document.getElementById('edm-imp-map-head');
    var mapBody  = document.getElementById('edm-imp-map-body');
    var runBtn   = document.getElementById('edm-imp-run');

    var mode = 'file';
    var file = null;
    var staged = null; // import_preview response

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function showAlert(msg) { alertEl.textContent = msg || 'Something went wrong.'; alertEl.hidden = false; }
    function clearAlert() { alertEl.hidden = true; }
    function firstError(res) {
        if (res && res.errors) {
            for (var k in res.errors) {
                if (Object.prototype.hasOwnProperty.call(res.errors, k)) { return res.errors[k][0]; }
            }
        }
        return (res && res.message) || 'Request failed.';
    }

    function goStep(n) {
        step1.hidden = n !== 1;
        step2.hidden = n !== 2;
        step3.hidden = n !== 3;
        [].forEach.call(document.querySelectorAll('.edm-imp-steps li'), function (li) {
            var s = parseInt(li.getAttribute('data-step'), 10);
            li.classList.toggle('active', s === n);
            li.classList.toggle('done', s < n);
        });
        window.scrollTo(0, 0);
    }

    // --- Step 1: upload / paste ---

    [].forEach.call(document.querySelectorAll('[data-imp-mode]'), function (tab) {
        tab.addEventListener('click', function () {
            mode = tab.getAttribute('data-imp-mode');
            [].forEach.call(document.querySelectorAll('[data-imp-mode]'), function (t) {
                var on = t === tab;
                t.classList.toggle('active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            document.getElementById('edm-imp-file-pane').hidden = mode !== 'file';
            document.getElementById('edm-imp-paste-pane').hidden = mode !== 'paste';
        });
    });

    function setFile(f) {
        file = f || null;
        fileName.textContent = file ? file.name + ' (' + Math.max(1, Math.round(file.size / 1024)) + ' KB)' : 'No file chosen';
        dropEl.classList.toggle('has-file', !!file);
    }
    fileEl.addEventListener('change', function () { setFile(fileEl.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) {
        dropEl.addEventListener(ev, function (e) { e.preventDefault(); dropEl.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        dropEl.addEventListener(ev, function (e) { e.preventDefault(); dropEl.classList.remove('is-over'); });
    });
    dropEl.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files.length) { setFile(e.dataTransfer.files[0]); }
    });

    step1.addEventListener('submit', function (e) {
        e.preventDefault();
        clearAlert();
        if (!listEl.value) { showAlert('Choose the list to import into.'); listEl.focus(); return; }
        if (mode === 'file' && !file) { showAlert('Choose a file to upload.'); return; }
        if (mode === 'paste' && !pasteEl.value.trim()) { showAlert('Paste at least one contact.'); pasteEl.focus(); return; }
        if (!consent.checked) { showAlert('Confirm that you have permission to add these people to the list.'); consent.focus(); return; }

        var fd = new FormData();
        fd.append('list_id', listEl.value);
        fd.append('consent', '1');
        if (mode === 'file') { fd.append('file', file); } else { fd.append('paste', pasteEl.value); }

        nextBtn.disabled = true;
        nextBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Reading...';
        fetch(API + '?action=import_preview', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { showAlert(firstError(res)); return; }
                staged = res.data;
                headerCb.checked = !!staged.has_header;
                renderMapping();
                goStep(2);
            })
            .catch(function () { showAlert('Could not reach the server, or the file is too large for the server settings.'); })
            .then(function () {
                nextBtn.disabled = false;
                nextBtn.innerHTML = 'Next <i class="bi bi-arrow-right ms-1"></i>';
            });
    });

    // --- Step 2: mapping ---

    function renderMapping() {
        var hasHeader = headerCb.checked;
        var width = staged.first_row.length;
        var headers = hasHeader
            ? staged.first_row
            : staged.first_row.map(function (_, i) { return 'Column ' + (i + 1); });
        var rows = staged.sample.slice();
        if (staged.has_header && !hasHeader) { rows.unshift(staged.first_row); }
        if (!staged.has_header && hasHeader) { rows = rows.slice(1); }
        rows = rows.slice(0, 5);

        var count = staged.total_rows - (hasHeader ? 1 : 0);
        document.getElementById('edm-imp-count').textContent = count;
        document.getElementById('edm-imp-count-label').textContent = count === 1 ? 'contact row found.' : 'contact rows found.';

        var current = currentMapping();
        mapHead.innerHTML = '<tr>' + headers.map(function (h, i) {
            var chosen = current ? current[i] : staged.mapping[i];
            return '<th><div class="small text-muted text-truncate mb-1" title="' + esc(h) + '">' + esc(h) + '</div>' +
                '<select class="form-select form-select-sm edm-imp-target" data-col="' + i + '">' +
                staged.targets.map(function (t) {
                    return '<option value="' + esc(t.value) + '"' + (t.value === chosen ? ' selected' : '') + '>' + esc(t.label) + '</option>';
                }).join('') + '</select></th>';
        }).join('') + '</tr>';
        mapBody.innerHTML = rows.length ? rows.map(function (r) {
            return '<tr>' + r.map(function (c) { return '<td class="text-truncate" style="max-width:220px;">' + esc(c) + '</td>'; }).join('') + '</tr>';
        }).join('') : '<tr><td colspan="' + width + '" class="text-muted">No data rows.</td></tr>';
        markSkipped();
    }

    function currentMapping() {
        var sels = mapHead.querySelectorAll('.edm-imp-target');
        if (!sels.length) { return null; }
        return [].map.call(sels, function (s) { return s.value; });
    }

    // Grey out columns that will not be imported.
    function markSkipped() {
        var map = currentMapping() || [];
        [].forEach.call(document.querySelectorAll('.edm-imp-map tr'), function (tr) {
            [].forEach.call(tr.children, function (cell, i) { cell.classList.toggle('edm-imp-skip', map[i] === 'skip'); });
        });
    }

    mapHead.addEventListener('change', function (e) {
        if (!e.target.classList.contains('edm-imp-target')) { return; }
        // A field can hold one column only: clear the same choice elsewhere.
        var v = e.target.value;
        if (v !== 'skip') {
            [].forEach.call(mapHead.querySelectorAll('.edm-imp-target'), function (s) {
                if (s !== e.target && s.value === v) { s.value = 'skip'; }
            });
        }
        markSkipped();
    });
    headerCb.addEventListener('change', renderMapping);
    document.getElementById('edm-imp-back').addEventListener('click', function () { clearAlert(); goStep(1); });

    runBtn.addEventListener('click', function () {
        clearAlert();
        var mapping = currentMapping();
        if (mapping.indexOf('email') === -1) { showAlert('Choose which column holds the email address.'); return; }
        runBtn.disabled = true;
        runBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing...';
        fetch(API + '?action=import_run', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ token: staged.token, list_id: parseInt(listEl.value, 10), mapping: mapping, has_header: headerCb.checked })
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { showAlert(firstError(res)); return; }
                renderSummary(res.data);
                goStep(3);
            })
            .catch(function () { showAlert('Could not reach the server.'); })
            .then(function () {
                runBtn.disabled = false;
                runBtn.innerHTML = '<i class="bi bi-person-plus me-1"></i>Import contacts';
            });
    });

    // --- Step 3: summary (atem stat cards) ---

    function renderSummary(s) {
        var listName = listEl.options[listEl.selectedIndex].text;
        document.getElementById('edm-imp-done-msg').textContent =
            'Import finished: ' + (s.added + s.updated) + ' contact(s) saved to "' + listName + '".';
        function card(title, value, cls, label) {
            return '<div class="col-12 col-sm-6 col-xl"><div class="edm-card edm-dash-stat h-100">' +
                '<div class="edm-card-title mb-1">' + esc(title) + '</div>' +
                '<div class="edm-stat-value ' + cls + '">' + esc(value) + '</div>' +
                '<div class="edm-stat-label">' + esc(label) + '</div></div></div>';
        }
        document.getElementById('edm-imp-summary').innerHTML =
            card('Added', s.added, 'edm-stat-value--green', 'new contacts') +
            card('Updated', s.updated, 'edm-stat-value--blue', 'already on the list') +
            card('Skipped', s.invalid, 'edm-stat-value--red', 'missing or invalid email') +
            card('Duplicates', s.duplicates, 'edm-stat-value--orange', 'repeated in the file') +
            card('Suppressed', s.suppressed, 'edm-stat-value--red', 'imported, never emailed');
        document.getElementById('edm-imp-again').href = BASE + 'audience/import.php?list=' + encodeURIComponent(listEl.value);
    }
})();
