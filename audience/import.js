/**
 * Contacts > Import contacts (audience/import.php). Three steps, modelled on
 * GetResponse's import:
 *   1. list + file (drag and drop or picker) or pasted text
 *      -> audience/api.php import_preview (multipart, XHR for upload progress)
 *   2. update mode (add + update / only add / only update) and match each
 *      column to Email / Name / Member code / a custom field, optionally
 *      "Check for errors" (import_run dry_run) -> import_run
 *   3. summary counts
 *
 * import_run works in batches (offset -> next), so a big file shows a real
 * progress bar and never hits the PHP time limit. One progress panel with a
 * log follows the user through the steps: upload, check and import lines,
 * plus one line per problem row (invalid email, duplicate, value that does
 * not fit a custom field, suppressed). The log downloads as a .txt file in
 * Laravel's log line format.
 *
 * The wizard state (step, staged token + preview, list, mapping, mode,
 * header row, progress, last log lines, summary) is kept in this tab's
 * sessionStorage, so a refresh on Match columns or Summary comes back where
 * it was; the staged rows stay on the server for 24 hours. Cancel, Back to
 * lists and Import another file clear it.
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
    var fileChip = document.getElementById('edm-imp-file-chip');
    var dropEl   = document.getElementById('edm-imp-drop');
    var pasteEl  = document.getElementById('edm-imp-paste');
    var modeEl   = document.getElementById('edm-imp-mode');
    var modeHelp = document.getElementById('edm-imp-mode-help');
    var nextBtn  = document.getElementById('edm-imp-next');
    var headerCb = document.getElementById('edm-imp-header');
    var mapEl    = document.getElementById('edm-imp-map');
    var runBtn   = document.getElementById('edm-imp-run');
    var checkBtn = document.getElementById('edm-imp-check');
    var backBtn  = document.getElementById('edm-imp-back');
    var checkRes = document.getElementById('edm-imp-check-result');

    var mode = 'file';
    var file = null;
    var staged = null; // import_preview response
    var running = false; // an upload or batch loop is in flight
    var stopRequested = false;
    var currentStep = 1;
    var summary = null;   // { tot, problems } after a finished import
    var runningWhat = ''; // 'checking' | 'importing' while a batch loop runs

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
        currentStep = n;
        step1.hidden = n !== 1;
        step2.hidden = n !== 2;
        step3.hidden = n !== 3;
        [].forEach.call(document.querySelectorAll('.edm-imp-steps li[data-step]'), function (li) {
            var s = parseInt(li.getAttribute('data-step'), 10);
            li.classList.toggle('active', s === n);
            li.classList.toggle('done', s < n);
        });
        window.scrollTo(0, 0);
        saveState();
    }

    // --- Progress panel + log (one panel, moved into the current step) ---

    var LOG_MAX_LINES = 1000; // lines kept on screen; the download has all
    var panel    = document.getElementById('edm-imp-progress');
    var pLabel   = panel.querySelector('.edm-imp-progress-label');
    var pPct     = panel.querySelector('.edm-imp-progress-pct');
    var pTrack   = panel.querySelector('.progress');
    var pBar     = panel.querySelector('.progress-bar');
    var pMeta    = panel.querySelector('.edm-imp-progress-meta');
    var pLog     = panel.querySelector('.edm-imp-log');
    var pStop    = panel.querySelector('.edm-imp-stop');
    var logEntries = [];
    var logHidden = 0;
    var logMore = null;

    function fmt(n) { return Number(n || 0).toLocaleString('en-US'); }
    function p2(n) { return (n < 10 ? '0' : '') + n; }
    function clock(d) {
        return p2(d.getHours()) + ':' + p2(d.getMinutes()) + ':' + p2(d.getSeconds());
    }
    function ymd(d) {
        return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate());
    }
    function elapsed(start) {
        var s = Math.round((Date.now() - start) / 1000);
        return s < 60 ? s + 's' : Math.floor(s / 60) + 'm ' + (s % 60) + 's';
    }

    function placePanel(slotId) {
        document.getElementById(slotId).appendChild(panel);
        panel.hidden = false;
    }
    function setProgress(pct, label, cls) {
        pBar.classList.remove('progress-bar-striped', 'progress-bar-animated', 'bg-success', 'bg-danger', 'bg-warning');
        if (cls) { pBar.classList.add(cls); }
        pct = Math.max(0, Math.min(100, pct));
        pBar.style.width = pct + '%';
        pTrack.setAttribute('aria-valuenow', String(Math.round(pct)));
        pPct.textContent = Math.floor(pct) + '%';
        if (label) { pLabel.textContent = label; }
    }
    // Server-side work with no measurable progress: striped moving bar.
    function setWorking(label) {
        setProgress(100, label);
        pBar.classList.add('progress-bar-striped', 'progress-bar-animated');
        pPct.textContent = '';
    }
    function resetLog() {
        logEntries = [];
        logHidden = 0;
        logMore = null;
        pLog.innerHTML = '';
        pMeta.textContent = '';
    }
    // level: info | ok | warn | error. issue: optional { row, email }.
    function log(level, message, issue) {
        var now = new Date();
        var entry = { at: ymd(now) + ' ' + clock(now), time: clock(now), level: level, row: issue ? issue.row : '', email: issue ? issue.email : '', message: message };
        logEntries.push(entry);
        renderLogEntry(entry);
    }
    function renderLogEntry(e) {
        if (pLog.children.length - (logMore ? 1 : 0) >= LOG_MAX_LINES) {
            logHidden++;
            if (!logMore) {
                logMore = document.createElement('li');
                logMore.className = 'edm-imp-log-more';
                pLog.appendChild(logMore);
            }
            logMore.textContent = fmt(logHidden) + ' more line(s) not shown - download the log to see everything.';
            return;
        }
        var li = document.createElement('li');
        li.className = 'is-' + e.level;
        li.innerHTML = '<span class="edm-imp-log-time">' + esc(e.time) + '</span>' +
            (e.row !== '' ? '<span class="edm-imp-log-row">Row ' + fmt(e.row) + '</span>' : '') +
            '<span class="edm-imp-log-msg">' + esc(e.message) + (e.email ? ' <span class="text-muted">' + esc(e.email) + '</span>' : '') + '</span>';
        pLog.appendChild(li);
        pLog.scrollTop = pLog.scrollHeight;
    }
    // Laravel (Monolog LineFormatter) line format:
    //   [2026-09-29 11:10:16] local.WARNING: Email address is not valid. {"row":5002,"email":"bad@aol"}
    // Environment is "local" on localhost, "production" elsewhere; empty
    // context is left out, as Laravel's default formatter does.
    var LOG_LEVEL_NAME = { info: 'INFO', ok: 'INFO', warn: 'WARNING', error: 'ERROR' };
    var LOG_ENV = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname) ? 'local' : 'production';
    panel.querySelector('.edm-imp-log-download').addEventListener('click', function () {
        var today = ymd(new Date());
        var text = logEntries.map(function (e) {
            var context = {};
            if (e.row !== '' && e.row != null) { context.row = e.row; }
            if (e.email) { context.email = e.email; }
            return '[' + (e.at || today + ' ' + e.time) + '] ' + LOG_ENV + '.' + (LOG_LEVEL_NAME[e.level] || 'INFO') + ': ' +
                e.message + (Object.keys(context).length ? ' ' + JSON.stringify(context) : '');
        }).join('\n') + '\n';
        var now = new Date();
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
        a.download = 'edm-import-' + ymd(now) + '-' + clock(now).replace(/:/g, '') + '.txt';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 0);
    });
    // --- Refresh-safe state (sessionStorage, this tab only) ---

    var STATE_KEY = 'edm-import-state';
    var STATE_LOG_LINES = 500;

    function saveState() {
        if (!staged || currentStep < 2) { clearState(); return; }
        var bar = ['bg-success', 'bg-danger', 'bg-warning'].filter(function (c) { return pBar.classList.contains(c); })[0] || '';
        try {
            sessionStorage.setItem(STATE_KEY, JSON.stringify({
                step: currentStep,
                staged: staged,
                list: listEl.value,
                mapping: currentMapping(),
                header: headerCb.checked,
                mode: modeEl.value,
                running: running ? runningWhat : '',
                summary: summary,
                check: checkRes.hidden ? null : { cls: checkRes.className, html: checkRes.innerHTML },
                progress: { label: pLabel.textContent, width: pBar.style.width, pct: pPct.textContent, bar: bar, meta: pMeta.textContent },
                log: logEntries.slice(-STATE_LOG_LINES)
            }));
        } catch (err) { /* storage full or blocked: refresh simply starts over */ }
    }
    function clearState() {
        try { sessionStorage.removeItem(STATE_KEY); } catch (err) { /* ignore */ }
    }
    [].forEach.call(document.querySelectorAll('[data-imp-reset]'), function (a) {
        a.addEventListener('click', clearState);
    });

    pStop.addEventListener('click', function () {
        stopRequested = true;
        pStop.disabled = true;
        pStop.textContent = 'Stopping...';
        log('warn', 'Stop requested - finishing the current batch.');
    });
    window.addEventListener('beforeunload', function (e) {
        if (running) { e.preventDefault(); e.returnValue = ''; }
    });

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
        fileName.textContent = file ? file.name + ' (' + fileSize(file.size) + ')' : '';
        fileChip.hidden = !file;
        dropEl.classList.toggle('has-file', !!file);
        if (!file) { fileEl.value = ''; }
    }
    function fileSize(bytes) {
        return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }
    document.getElementById('edm-imp-file-clear').addEventListener('click', function () { setFile(null); });
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

    function setStep1Busy(busy) {
        running = busy;
        [].forEach.call(step1.querySelectorAll('input, select, textarea, button'), function (el) { el.disabled = busy; });
        nextBtn.innerHTML = busy
            ? '<span class="spinner-border spinner-border-sm me-1"></span>Reading...'
            : 'Next <i class="bi bi-arrow-right ms-1"></i>';
    }

    step1.addEventListener('submit', function (e) {
        e.preventDefault();
        clearAlert();
        if (running) { return; }
        if (!listEl.value) { showAlert('Choose the list to import into.'); listEl.focus(); return; }
        if (mode === 'file' && !file) { showAlert('Choose a file to upload.'); return; }
        if (mode === 'paste' && !pasteEl.value.trim()) { showAlert('Paste at least one contact.'); pasteEl.focus(); return; }

        var fd = new FormData();
        fd.append('list_id', listEl.value);
        if (mode === 'file') { fd.append('file', file); } else { fd.append('paste', pasteEl.value); }

        resetLog();
        placePanel('edm-imp-progress-slot1');
        pStop.hidden = true;
        var start = Date.now();
        if (mode === 'file') {
            setProgress(0, 'Uploading ' + file.name);
            log('info', 'Uploading ' + file.name + ' (' + fileSize(file.size) + ').');
        } else {
            setWorking('Reading pasted contacts');
            log('info', 'Sending pasted text (' + fmt(pasteEl.value.split(/\r\n|\r|\n/).length) + ' lines).');
        }
        setStep1Busy(true);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API + '?action=import_preview');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', function (ev) {
            if (mode !== 'file' || !ev.lengthComputable) { return; }
            setProgress(ev.loaded / ev.total * 100);
            pMeta.textContent = fileSize(ev.loaded) + ' of ' + fileSize(ev.total) + ' uploaded - ' + elapsed(start);
        });
        xhr.upload.addEventListener('load', function () {
            if (mode === 'file') { log('ok', 'Upload complete in ' + elapsed(start) + '.'); }
            log('info', 'Reading the rows and looking for the email column...');
            setWorking('Reading and checking the file');
            pMeta.textContent = 'Large spreadsheets can take a minute to read.';
        });
        xhr.addEventListener('load', function () {
            setStep1Busy(false);
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }
            if (!res) {
                var msg = xhr.status === 413
                    ? 'The file is larger than the server accepts. Split it into smaller files.'
                    : 'The server could not read the file (HTTP ' + xhr.status + '). It may be too large for the server settings.';
                setProgress(100, 'Upload failed', 'bg-danger');
                log('error', msg);
                showAlert(msg);
                return;
            }
            if (!res.success) {
                setProgress(100, 'Could not read the file', 'bg-danger');
                log('error', firstError(res));
                showAlert(firstError(res));
                return;
            }
            staged = res.data;
            summary = null;
            var rows = staged.total_rows - (staged.has_header ? 1 : 0);
            setProgress(100, 'File ready', 'bg-success');
            pMeta.textContent = fmt(rows) + ' rows - ' + staged.first_row.length + ' columns - ' + elapsed(start);
            log('ok', 'Found ' + fmt(rows) + ' contact row(s) and ' + staged.first_row.length + ' column(s)' +
                (staged.has_header ? ' (first row used as column names).' : ' (no header row).'));
            var emailCol = staged.mapping.indexOf('email');
            log(emailCol === -1 ? 'warn' : 'info', emailCol === -1
                ? 'No email column found - choose it in the next step.'
                : 'Email column: ' + (staged.has_header ? staged.first_row[emailCol] : 'Column ' + (emailCol + 1)) + '.');
            headerCb.checked = !!staged.has_header;
            checkRes.hidden = true;
            renderMapping();
            placePanel('edm-imp-progress-slot2');
            goStep(2);
        });
        xhr.addEventListener('error', function () {
            setStep1Busy(false);
            setProgress(100, 'Upload failed', 'bg-danger');
            log('error', 'Could not reach the server. Check the connection and try again.');
            showAlert('Could not reach the server, or the file is too large for the server settings.');
        });
        xhr.send(fd);
    });

    // --- Step 2: mapping ---

    function renderMapping() {
        var hasHeader = headerCb.checked;
        var headers = hasHeader
            ? staged.first_row
            : staged.first_row.map(function (_, i) { return 'Column ' + (i + 1); });
        var rows = staged.sample.slice();
        if (staged.has_header && !hasHeader) { rows.unshift(staged.first_row); }
        if (!staged.has_header && hasHeader) { rows = rows.slice(1); }

        var count = staged.total_rows - (hasHeader ? 1 : 0);
        document.getElementById('edm-imp-count').textContent = fmt(count);
        document.getElementById('edm-imp-count-label').textContent = count === 1 ? 'contact found' : 'contacts found';

        // One row per file column: name + sample values -> contact field select.
        var current = currentMapping();
        mapEl.innerHTML =
            '<div class="edm-imp-map-head"><span>Columns in your file</span><span></span><span>Contact field</span><span></span></div>' +
            headers.map(function (h, i) {
                var chosen = current ? current[i] : staged.mapping[i];
                var samples = rows.map(function (r) { return r[i]; }).filter(function (v) { return v !== '' && v != null; }).slice(0, 3);
                return '<div class="edm-imp-map-row">' +
                    '<div class="edm-imp-map-src" title="' + esc(h) + '">' +
                        '<i class="bi bi-table"></i>' +
                        '<div class="min-w-0"><div class="edm-imp-map-name">' + esc(h || 'Column ' + (i + 1)) + '</div>' +
                        '<div class="edm-imp-map-sample">' + (samples.length ? esc(samples.join(', ')) : 'No values') + '</div></div>' +
                    '</div>' +
                    '<i class="bi bi-arrow-right edm-imp-map-arrow" aria-hidden="true"></i>' +
                    '<div class="min-w-0">' +
                        '<select class="form-select edm-imp-target" data-col="' + i + '" data-prev="' + esc(chosen) + '" aria-label="Contact field for ' + esc(h) + '">' +
                            staged.targets.map(function (t) {
                                return '<option value="' + esc(t.value) + '"' + (t.value === chosen ? ' selected' : '') + '>' + esc(t.label) + '</option>';
                            }).join('') +
                            '<option value="' + NEW_FIELD + '">+ Create new custom field...</option>' +
                        '</select>' +
                        '<button type="button" class="edm-imp-map-new" data-col="' + i + '"><i class="bi bi-plus-circle me-1"></i>Create custom field for "' + esc(h || 'Column ' + (i + 1)) + '"</button>' +
                    '</div>' +
                    '<span class="edm-imp-map-state"></span>' +
                '</div>';
            }).join('');
        markSkipped();
    }

    function currentMapping() {
        var sels = mapEl.querySelectorAll('.edm-imp-target');
        if (!sels.length) { return null; }
        return [].map.call(sels, function (s) { return s.value; });
    }

    // Non-empty preview values of one column (header row excluded when ticked).
    function columnValues(col) {
        var rows = staged.sample.slice();
        if (staged.has_header && !headerCb.checked) { rows.unshift(staged.first_row); }
        if (!staged.has_header && headerCb.checked) { rows = rows.slice(1); }
        return rows.map(function (r) { return String(r[col] == null ? '' : r[col]).trim(); }).filter(function (v) { return v !== ''; });
    }
    function columnName(col) {
        return headerCb.checked ? String(staged.first_row[col] || '').trim() : '';
    }

    // Mapped columns get a green check; "Do not import" columns are dimmed
    // and offer "Create custom field".
    function markSkipped() {
        [].forEach.call(mapEl.querySelectorAll('.edm-imp-map-row'), function (row) {
            var skip = row.querySelector('.edm-imp-target').value === 'skip';
            row.classList.toggle('is-skipped', skip);
            row.querySelector('.edm-imp-map-state').innerHTML = skip
                ? '<i class="bi bi-eye-slash text-muted" title="Not imported"></i>'
                : '<i class="bi bi-check-circle-fill text-success" title="Will be imported"></i>';
        });
    }

    mapEl.addEventListener('change', function (e) {
        if (!e.target.classList.contains('edm-imp-target')) { return; }
        var v = e.target.value;
        if (v === NEW_FIELD) {
            e.target.value = e.target.getAttribute('data-prev') || 'skip';
            openFieldModal(parseInt(e.target.getAttribute('data-col'), 10));
            return;
        }
        chooseTarget(e.target, v);
    });
    mapEl.addEventListener('click', function (e) {
        var btn = e.target.closest('.edm-imp-map-new');
        if (btn) { openFieldModal(parseInt(btn.getAttribute('data-col'), 10)); }
    });

    // Sets one column's target; a field can hold one column only, so the
    // same choice is cleared elsewhere.
    function chooseTarget(sel, v) {
        sel.value = v;
        sel.setAttribute('data-prev', v);
        if (v !== 'skip') {
            [].forEach.call(mapEl.querySelectorAll('.edm-imp-target'), function (s) {
                if (s !== sel && s.value === v) { s.value = 'skip'; s.setAttribute('data-prev', 'skip'); }
            });
        }
        markSkipped();
        checkRes.hidden = true;
        saveState();
    }

    // --- Create a custom field from a file column (Contacts > Custom fields) ---

    var NEW_FIELD = '__new_field';
    var fModalEl = document.getElementById('edm-imp-field-modal');
    var fModal   = new bootstrap.Modal(fModalEl);
    var fForm    = document.getElementById('edm-imp-field-form');
    var fLabel   = document.getElementById('edm-imp-field-label');
    var fType    = document.getElementById('edm-imp-field-type');
    var fOptWrap = document.getElementById('edm-imp-field-options-wrap');
    var fOptions = document.getElementById('edm-imp-field-options');
    var fKey     = document.getElementById('edm-imp-field-key');
    var fErr     = document.getElementById('edm-imp-field-error');
    var fSave    = document.getElementById('edm-imp-field-save');
    var fCol     = -1;

    function keyFromLabel(label) {
        return label.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'field';
    }
    // Type suggested from the preview values: all dates, all numbers, all 1/0.
    function guessType(values) {
        if (!values.length) { return 'text'; }
        function all(re) { return values.every(function (v) { return re.test(v); }); }
        if (all(/^\d{4}-\d{2}-\d{2}$/)) { return 'date'; }
        if (all(/^(1|0|yes|no|true|false)$/i)) { return 'boolean'; }
        if (all(/^-?\d+(\.\d+)?$/)) { return 'number'; }
        return 'text';
    }
    function syncFieldForm() {
        fOptWrap.hidden = fType.value !== 'select';
        fKey.textContent = '{{' + keyFromLabel(fLabel.value.trim()) + '}}';
    }
    function openFieldModal(col) {
        fCol = col;
        var values = columnValues(col);
        var unique = values.filter(function (v, i) { return values.indexOf(v) === i; });
        var name = columnName(col).replace(/[_-]+/g, ' ').replace(/\s+/g, ' ');
        fLabel.value = name ? name.charAt(0).toUpperCase() + name.slice(1) : '';
        fType.value = guessType(values);
        fOptions.value = unique.join('\n');
        fErr.hidden = true;
        syncFieldForm();
        fModal.show();
    }
    fModalEl.addEventListener('shown.bs.modal', function () { fLabel.focus(); fLabel.select(); });
    fLabel.addEventListener('input', syncFieldForm);
    fType.addEventListener('change', syncFieldForm);

    fForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var label = fLabel.value.trim();
        var options = fOptions.value.split(/\r\n|\r|\n/).map(function (v) { return v.trim(); }).filter(Boolean);
        if (!label) { fErr.textContent = 'Enter a name for the field.'; fErr.hidden = false; fLabel.focus(); return; }
        if (fType.value === 'select' && !options.length) { fErr.textContent = 'Add at least one option, one per line.'; fErr.hidden = false; fOptions.focus(); return; }
        fErr.hidden = true;
        fSave.disabled = true;
        fetch(API + '?action=fields_create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ label: label, type: fType.value, options: fType.value === 'select' ? options : null, is_active: true })
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { fErr.textContent = firstError(res); fErr.hidden = false; return; }
                var f = res.data;
                var target = { value: 'field:' + f.key, label: f.label + ' (custom field)' };
                staged.targets.push(target);
                renderMapping();
                chooseTarget(mapEl.querySelector('.edm-imp-target[data-col="' + fCol + '"]'), target.value);
                log('ok', 'Created custom field "' + f.label + '" ({{' + f.key + '}}, ' + f.type + ') and matched it to column "' + (columnName(fCol) || 'Column ' + (fCol + 1)) + '".');
                placePanel('edm-imp-progress-slot2');
                fModal.hide();
            })
            .catch(function () { fErr.textContent = 'Could not reach the server.'; fErr.hidden = false; })
            .then(function () { fSave.disabled = false; });
    });

    headerCb.addEventListener('change', function () { checkRes.hidden = true; renderMapping(); saveState(); });

    var MODE_HELP = {
        add_update: 'New email addresses are added; contacts already on the list are updated.',
        add: 'Only new email addresses are added; contacts already on the list are left unchanged.',
        update: 'Only contacts already on the list are updated; new email addresses are not added.'
    };
    function renderModeHelp() { modeHelp.textContent = MODE_HELP[modeEl.value] || ''; }
    modeEl.addEventListener('change', function () { checkRes.hidden = true; renderModeHelp(); saveState(); });
    renderModeHelp();
    backBtn.addEventListener('click', function () {
        clearAlert();
        placePanel('edm-imp-progress-slot1');
        goStep(1);
    });

    function setStep2Busy(busy, label) {
        running = busy;
        [].forEach.call(step2.querySelectorAll('input, select, button:not(.edm-imp-stop):not(.edm-imp-log-download)'), function (el) { el.disabled = busy; });
        pStop.hidden = !busy;
        pStop.disabled = false;
        pStop.innerHTML = '<i class="bi bi-stop-circle me-1"></i>Stop';
        runBtn.innerHTML = busy && label === 'import'
            ? '<span class="spinner-border spinner-border-sm me-1"></span>Importing...'
            : '<i class="bi bi-person-plus me-1"></i>Import contacts';
        checkBtn.innerHTML = busy && label === 'check'
            ? '<span class="spinner-border spinner-border-sm me-1"></span>Checking...'
            : '<i class="bi bi-clipboard-check me-1"></i>Check for errors';
    }

    var ISSUE_LEVEL = { invalid: 'error', duplicate: 'warn', value: 'warn', suppressed: 'info' };

    // Runs import_run batch after batch; dry = check only (nothing written).
    function runBatches(dry) {
        clearAlert();
        if (running) { return; }
        var mapping = currentMapping();
        if (mapping.indexOf('email') === -1) { showAlert('Choose which column holds the email address.'); return; }

        var verb = dry ? 'Checking' : 'Importing';
        var tot = { added: 0, updated: 0, ignored: 0, invalid: 0, duplicates: 0, suppressed: 0, values_dropped: 0 };
        var problems = 0;
        var start = Date.now();
        stopRequested = false;
        runningWhat = dry ? 'checking' : 'importing';
        checkRes.hidden = true;
        placePanel('edm-imp-progress-slot2');
        setStep2Busy(true, dry ? 'check' : 'import');
        setProgress(0, verb + ' contacts');
        log('info', (dry ? 'Checking' : 'Importing into "' + listEl.options[listEl.selectedIndex].text + '"') +
            ' - ' + modeEl.options[modeEl.selectedIndex].text.toLowerCase() + (dry ? ' (nothing is saved).' : '.'));

        function finish(state, total, done) {
            setStep2Busy(false);
            var label = state === 'done' ? (dry ? 'Check complete' : 'Import complete')
                : state === 'stopped' ? (dry ? 'Check stopped' : 'Import stopped') : (dry ? 'Check failed' : 'Import failed');
            setProgress(total ? done / total * 100 : 100, label, state === 'done' ? 'bg-success' : state === 'stopped' ? 'bg-warning' : 'bg-danger');
            pMeta.textContent = fmt(done) + ' of ' + fmt(total) + ' rows - ' + fmt(problems) + ' problem(s) - ' + elapsed(start);
            var counts = fmt(tot.added) + (dry ? ' to add, ' : ' added, ') + fmt(tot.updated) + (dry ? ' to update, ' : ' updated, ') +
                fmt(tot.invalid) + ' invalid, ' + fmt(tot.duplicates) + ' duplicate(s)' +
                (tot.ignored ? ', ' + fmt(tot.ignored) + ' not changed' : '') +
                (tot.values_dropped ? ', ' + fmt(tot.values_dropped) + ' value(s) left out' : '') +
                (tot.suppressed ? ', ' + fmt(tot.suppressed) + ' suppressed' : '') + '.';
            if (state === 'stopped') {
                log('warn', label + ' after ' + fmt(done) + ' of ' + fmt(total) + ' rows: ' + counts +
                    (dry ? '' : ' Contacts in the finished batches are saved; importing again updates them.'));
            } else if (state === 'done') {
                log('ok', label + ' in ' + elapsed(start) + ': ' + counts);
            }
            if (dry && state === 'done') {
                checkRes.className = 'alert py-2 px-3 small mb-0 mt-4 ' + (problems ? 'alert-warning' : 'alert-success');
                checkRes.innerHTML = (problems
                    ? '<i class="bi bi-exclamation-triangle me-1"></i><strong>' + fmt(problems) + ' problem(s) found.</strong> See the log below - problem rows are skipped or partly imported. '
                    : '<i class="bi bi-check-circle me-1"></i><strong>No problems found.</strong> ') +
                    'Ready to import: ' + fmt(tot.added) + ' new, ' + fmt(tot.updated) + ' updated.';
                checkRes.hidden = false;
            }
            if (!dry && state === 'done') {
                summary = { tot: tot, problems: problems };
                renderSummary(tot, problems);
                placePanel('edm-imp-progress-slot3');
                goStep(3);
            }
            saveState();
        }

        function batch(offset, total, done) {
            if (stopRequested) { finish('stopped', total, done); return; }
            fetch(API + '?action=import_run', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    token: staged.token, list_id: parseInt(listEl.value, 10), mapping: mapping,
                    has_header: headerCb.checked, mode: modeEl.value, offset: offset, dry_run: dry
                })
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) {
                        log('error', firstError(res));
                        showAlert(firstError(res));
                        finish('failed', total, done);
                        return;
                    }
                    var d = res.data;
                    Object.keys(tot).forEach(function (k) { tot[k] += d.sum[k] || 0; });
                    // File row numbers (the header row is row 1), same as the problem lines.
                    var lead = headerCb.checked ? 1 : 0;
                    var from = d.offset + 1 + lead, to = d.offset + d.processed;
                    var bad = d.sum.invalid + d.sum.duplicates + d.sum.values_dropped;
                    log(bad ? 'warn' : 'info', 'Rows ' + fmt(from) + '-' + fmt(to + lead) + ': ' +
                        fmt(d.sum.added) + (dry ? ' to add, ' : ' added, ') + fmt(d.sum.updated) + (dry ? ' to update' : ' updated') +
                        (bad ? ', ' + fmt(bad) + ' problem(s)' : '') + '.');
                    d.issues.forEach(function (is) {
                        if (is.type !== 'suppressed') { problems++; }
                        log(ISSUE_LEVEL[is.type] || 'warn', is.message, is);
                    });
                    total = d.total;
                    done = to;
                    setProgress(total ? done / total * 100 : 100, verb + ' contacts');
                    pMeta.textContent = fmt(done) + ' of ' + fmt(total) + ' rows - ' + fmt(problems) + ' problem(s) - ' + elapsed(start);
                    if (d.next === null) { finish('done', total, done); } else { saveState(); batch(d.next, total, done); }
                })
                .catch(function () {
                    log('error', 'Could not reach the server while ' + verb.toLowerCase() + ' rows ' + fmt(offset + 1) + ' onwards.');
                    showAlert('Could not reach the server.');
                    finish('failed', total, done);
                });
        }
        batch(0, staged.total_rows - (headerCb.checked ? 1 : 0), 0);
    }

    checkBtn.addEventListener('click', function () { runBatches(true); });
    runBtn.addEventListener('click', function () { runBatches(false); });

    // --- Step 3: summary (atem stat cards) ---

    function renderSummary(s, problems) {
        var listName = listEl.options[listEl.selectedIndex].text;
        document.getElementById('edm-imp-done-msg').textContent =
            'Import finished: ' + fmt(s.added + s.updated) + ' contact(s) saved to "' + listName + '".' +
            (problems ? ' ' + fmt(problems) + ' problem row(s) are listed in the log below.' : '');
        function card(title, value, cls, label) {
            return '<div class="col-12 col-sm-6 col-xl"><div class="edm-card edm-dash-stat h-100">' +
                '<div class="edm-card-title mb-1">' + esc(title) + '</div>' +
                '<div class="edm-stat-value ' + cls + '">' + esc(value) + '</div>' +
                '<div class="edm-stat-label">' + esc(label) + '</div></div></div>';
        }
        document.getElementById('edm-imp-summary').innerHTML =
            card('Added', s.added, 'edm-stat-value--green', 'new contacts') +
            card('Updated', s.updated, 'edm-stat-value--blue', 'already on the list') +
            (modeEl.value !== 'add_update'
                ? card('Not changed', s.ignored, 'edm-stat-value--orange', modeEl.value === 'add' ? 'already on the list' : 'not on the list')
                : '') +
            card('Skipped', s.invalid, 'edm-stat-value--red', 'missing or invalid email') +
            card('Duplicates', s.duplicates, 'edm-stat-value--orange', 'repeated in the file') +
            card('Suppressed', s.suppressed, 'edm-stat-value--red', 'imported, never emailed') +
            (s.values_dropped
                ? card('Values left out', s.values_dropped, 'edm-stat-value--orange', 'did not fit the custom field')
                : '');
        document.getElementById('edm-imp-again').href = BASE + 'audience/import.php?list=' + encodeURIComponent(listEl.value);
    }

    // --- Restore after a refresh ---

    (function restoreState() {
        var st = null;
        try { st = JSON.parse(sessionStorage.getItem(STATE_KEY) || 'null'); } catch (err) { st = null; }
        if (!st || !st.staged || st.step < 2) { return; }

        staged = st.staged;
        summary = st.summary || null;
        listEl.value = st.list;
        headerCb.checked = !!st.header;
        if (st.mode) { modeEl.value = st.mode; }
        renderModeHelp();
        if (st.mapping) { staged.mapping = st.mapping; }
        renderMapping();

        resetLog();
        (st.log || []).forEach(function (e) { logEntries.push(e); renderLogEntry(e); });
        var pr = st.progress || {};
        setProgress(parseFloat(pr.width) || 0, pr.label || 'Ready', pr.bar || '');
        pPct.textContent = pr.pct || '';
        pMeta.textContent = pr.meta || '';
        pStop.hidden = true;
        if (st.check) {
            checkRes.className = st.check.cls;
            checkRes.classList.add('mt-4');
            checkRes.innerHTML = st.check.html;
            checkRes.hidden = false;
        }

        if (st.step === 3 && summary) {
            renderSummary(summary.tot, summary.problems);
            placePanel('edm-imp-progress-slot3');
            goStep(3);
        } else {
            placePanel('edm-imp-progress-slot2');
            goStep(2);
        }
        if (st.running) {
            setProgress(parseFloat(pr.width) || 0, 'Interrupted by page reload', 'bg-warning');
            log('warn', 'The page was reloaded while ' + st.running + ' rows. ' + (st.running === 'importing'
                ? 'Contacts in the finished batches are saved - click Import contacts to finish (contacts already imported are updated, not duplicated).'
                : 'Click Check for errors to run the check again.'));
        } else {
            var last = logEntries[logEntries.length - 1];
            if (!last || last.message.indexOf('Page reloaded') !== 0) {
                log('info', 'Page reloaded - your upload, list and column matches were kept.');
            }
        }
        saveState();
    })();
})();
