/**
 * Email cleaner (email-cleaner/index.php). Three steps:
 *   1. upload a file -> email-cleaner/api.php cleaner_preview (multipart, XHR
 *      for upload progress)
 *   2. choose the email / name / mobile / IC columns (suggested by the
 *      server), then cleaner_run batch after batch (offset -> next) with a
 *      progress bar and Stop
 *   3. counts, reason breakdown and the two downloads (cleaner_download
 *      kind=valid | invalid)
 * The rules run on the server in cleanse/clean_email_list.py; nothing is
 * saved to the contact list.
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var API  = BASE + 'email-cleaner/api.php';

    var COLUMNS = [
        { key: 'email',  label: 'Email', required: true },
        { key: 'name',   label: 'Name' },
        { key: 'mobile', label: 'Mobile' },
        { key: 'ic',     label: 'IC / NRIC' }
    ];

    var alertEl  = document.getElementById('edm-cln-alert');
    var step1    = document.getElementById('edm-cln-step1');
    var step2    = document.getElementById('edm-cln-step2');
    var step3    = document.getElementById('edm-cln-step3');
    var fileEl   = document.getElementById('edm-cln-file');
    var fileChip = document.getElementById('edm-cln-file-chip');
    var fileName = document.getElementById('edm-cln-file-name');
    var dropEl   = document.getElementById('edm-cln-drop');
    var nextBtn  = document.getElementById('edm-cln-next');
    var headerCb = document.getElementById('edm-cln-header');
    var colsEl   = document.getElementById('edm-cln-columns');
    var prevEl   = document.getElementById('edm-cln-preview');
    var runBtn   = document.getElementById('edm-cln-run');
    var backBtn  = document.getElementById('edm-cln-back');
    var panel    = document.getElementById('edm-cln-progress');
    var pBar     = panel.querySelector('.progress-bar');
    var pTrack   = panel.querySelector('.progress');
    var pLabel   = panel.querySelector('.edm-imp-progress-label');
    var pPct     = panel.querySelector('.edm-imp-progress-pct');
    var pMeta    = panel.querySelector('.edm-imp-progress-meta');
    var pStop    = document.getElementById('edm-cln-stop');

    var file = null;
    var staged = null;
    var running = false;
    var stopRequested = false;

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fmt(n) { return Number(n || 0).toLocaleString('en-US'); }
    function fileSize(b) {
        return b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1) + ' MB';
    }
    function elapsed(start) {
        var s = Math.round((Date.now() - start) / 1000);
        return s < 60 ? s + 's' : Math.floor(s / 60) + 'm ' + (s % 60) + 's';
    }
    function showAlert(msg) { alertEl.textContent = msg; alertEl.hidden = false; window.scrollTo(0, 0); }
    function clearAlert() { alertEl.hidden = true; alertEl.textContent = ''; }
    function firstError(res) {
        if (res && res.errors) {
            var k = Object.keys(res.errors)[0];
            if (k && res.errors[k] && res.errors[k][0]) { return res.errors[k][0]; }
        }
        return (res && res.message) || 'Something went wrong.';
    }

    function goStep(n) {
        step1.hidden = n !== 1;
        step2.hidden = n !== 2;
        step3.hidden = n !== 3;
        [].forEach.call(document.querySelectorAll('.edm-imp-steps li[data-step]'), function (li) {
            var s = Number(li.getAttribute('data-step'));
            li.classList.toggle('active', s === n);
            li.classList.toggle('done', s < n);
        });
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
    function setWorking(label) {
        setProgress(100, label);
        pBar.classList.add('progress-bar-striped', 'progress-bar-animated');
        pPct.textContent = '';
    }

    // --- Step 1: file ---

    function setFile(f) {
        file = f || null;
        fileChip.hidden = !file;
        dropEl.hidden = !!file;
        fileName.textContent = file ? file.name + ' (' + fileSize(file.size) + ')' : '';
        if (!file) { fileEl.value = ''; }
    }
    document.getElementById('edm-cln-file-clear').addEventListener('click', function () { setFile(null); });
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
        nextBtn.disabled = busy;
        nextBtn.innerHTML = busy
            ? '<span class="spinner-border spinner-border-sm me-1"></span>Reading...'
            : 'Next <i class="bi bi-arrow-right ms-1"></i>';
    }

    step1.addEventListener('submit', function (e) {
        e.preventDefault();
        clearAlert();
        if (running) { return; }
        if (!file) { showAlert('Choose a file to upload.'); return; }

        var fd = new FormData();
        fd.append('file', file);
        placePanel('edm-cln-progress-slot1');
        pStop.hidden = true;
        var start = Date.now();
        setProgress(0, 'Uploading ' + file.name);
        pMeta.textContent = '';
        setStep1Busy(true);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API + '?action=cleaner_preview');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', function (ev) {
            if (!ev.lengthComputable) { return; }
            setProgress(ev.loaded / ev.total * 100);
            pMeta.textContent = fileSize(ev.loaded) + ' of ' + fileSize(ev.total) + ' uploaded - ' + elapsed(start);
        });
        xhr.upload.addEventListener('load', function () {
            setWorking('Reading the file');
            pMeta.textContent = 'Large spreadsheets can take a minute to read.';
        });
        xhr.addEventListener('load', function () {
            setStep1Busy(false);
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }
            if (!res || !res.success) {
                var msg = res ? firstError(res)
                    : xhr.status === 413 ? 'The file is larger than the server accepts. Split it into smaller files.'
                    : 'The server could not read the file (HTTP ' + xhr.status + ').';
                setProgress(100, 'Could not read the file', 'bg-danger');
                showAlert(msg);
                return;
            }
            panel.hidden = true;
            staged = res.data;
            headerCb.checked = !!staged.has_header;
            renderColumns(staged.columns);
            renderPreview();
            goStep(2);
        });
        xhr.addEventListener('error', function () {
            setStep1Busy(false);
            setProgress(100, 'Upload failed', 'bg-danger');
            showAlert('Could not reach the server.');
        });
        xhr.send(fd);
    });

    // --- Step 2: columns ---

    function headers() {
        var first = staged.first_row || [];
        return first.map(function (h, i) {
            return headerCb.checked && String(h).trim() !== '' ? String(h) : 'Column ' + (i + 1);
        });
    }

    function renderColumns(selected) {
        var hs = headers();
        colsEl.innerHTML = COLUMNS.map(function (c) {
            var opts = (c.required ? '' : '<option value="">Not in the file</option>') +
                hs.map(function (h, i) {
                    var on = selected && selected[c.key] === i ? ' selected' : '';
                    return '<option value="' + i + '"' + on + '>' + esc(h) + '</option>';
                }).join('');
            if (c.required && !(selected && selected[c.key] != null)) {
                opts = '<option value="" selected disabled>Choose a column</option>' + opts;
            }
            return '<div class="col-sm-6 col-xl-3">' +
                '<label class="form-label" for="edm-cln-col-' + c.key + '">' + esc(c.label) +
                (c.required ? ' <span class="text-danger" aria-hidden="true">*</span>' : '') + '</label>' +
                '<select class="form-select" id="edm-cln-col-' + c.key + '" data-col="' + c.key + '">' + opts + '</select></div>';
        }).join('');
    }

    function currentColumns() {
        var out = {};
        COLUMNS.forEach(function (c) {
            var v = document.getElementById('edm-cln-col-' + c.key).value;
            out[c.key] = v === '' ? null : Number(v);
        });
        return out;
    }

    function renderPreview() {
        var hs = headers();
        var rows = headerCb.checked ? (staged.sample || []) : [staged.first_row].concat(staged.sample || []).slice(0, 5);
        document.getElementById('edm-cln-fname').textContent = staged.file_name || '';
        document.getElementById('edm-cln-count').textContent = fmt(staged.total_rows - (headerCb.checked ? 1 : 0));
        prevEl.innerHTML = '<thead><tr><th>#</th>' + hs.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('') + '</tr></thead>' +
            '<tbody>' + rows.map(function (r, n) {
                return '<tr><td>' + (n + 1) + '</td>' + hs.map(function (h, i) { return '<td>' + esc(r[i]) + '</td>'; }).join('') + '</tr>';
            }).join('') + '</tbody>';
    }

    headerCb.addEventListener('change', function () {
        var keep = currentColumns();
        renderColumns(keep);
        renderPreview();
    });

    backBtn.addEventListener('click', function () {
        if (running) { return; }
        clearAlert();
        panel.hidden = true;
        goStep(1);
    });

    function setStep2Busy(busy) {
        running = busy;
        [].forEach.call(step2.querySelectorAll('select, input'), function (el) { el.disabled = busy; });
        backBtn.disabled = busy;
        runBtn.disabled = busy;
        pStop.hidden = !busy;
        pStop.disabled = false;
        runBtn.innerHTML = busy
            ? '<span class="spinner-border spinner-border-sm me-1"></span>Cleaning...'
            : '<i class="bi bi-stars me-1"></i>Clean emails';
    }

    pStop.addEventListener('click', function () {
        stopRequested = true;
        pStop.disabled = true;
        pLabel.textContent = 'Stopping after this batch...';
    });

    runBtn.addEventListener('click', function () {
        clearAlert();
        if (running) { return; }
        var columns = currentColumns();
        if (columns.email === null) { showAlert('Choose which column holds the email address.'); return; }

        var start = Date.now();
        var total = staged.total_rows - (headerCb.checked ? 1 : 0);
        stopRequested = false;
        placePanel('edm-cln-progress-slot2');
        setStep2Busy(true);
        setWorking('Checking the whole file for shared addresses');
        pMeta.textContent = 'The first batch also looks up new email domains, which can take a while.';

        function fail(msg) {
            setStep2Busy(false);
            setProgress(100, 'Cleaning failed', 'bg-danger');
            showAlert(msg);
        }

        function batch(offset, done) {
            if (stopRequested) {
                setStep2Busy(false);
                setProgress(total ? done / total * 100 : 100, 'Stopped - no files were produced', 'bg-warning');
                return;
            }
            fetch(API + '?action=cleaner_run', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ token: staged.token, columns: columns, has_header: headerCb.checked, offset: offset })
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) { fail(firstError(res)); return; }
                    var d = res.data;
                    total = d.total;
                    done = d.offset + d.processed;
                    setProgress(total ? done / total * 100 : 100, 'Cleaning emails');
                    pMeta.textContent = fmt(done) + ' of ' + fmt(total) + ' rows - ' + elapsed(start);
                    if (d.next === null) {
                        setStep2Busy(false);
                        setProgress(100, 'Done', 'bg-success');
                        showResults(d, start);
                    } else {
                        if (total - d.next <= d.processed) {
                            pLabel.textContent = 'Cleaning the last rows and writing the two Excel files';
                        }
                        batch(d.next, done);
                    }
                })
                .catch(function () { fail('Could not reach the server while cleaning rows ' + fmt(offset + 1) + ' onwards.'); });
        }
        batch(0, 0);
    });

    // --- Step 3: results ---

    function showResults(d, start) {
        var t = d.totals || { valid: 0, invalid: 0, corrected: 0 };
        var sum = t.valid + t.invalid;
        document.getElementById('edm-cln-done-msg').textContent =
            'Finished in ' + elapsed(start) + ': ' + fmt(sum) + ' rows checked, ' + fmt(t.valid) + ' valid and ' + fmt(t.invalid) + ' invalid.';
        function card(title, value, cls, label) {
            return '<div class="col-12 col-sm-6 col-xl"><div class="edm-card edm-dash-stat h-100">' +
                '<div class="edm-card-title mb-1">' + esc(title) + '</div>' +
                '<div class="edm-stat-value ' + cls + '">' + esc(fmt(value)) + '</div>' +
                '<div class="edm-stat-label">' + esc(label) + '</div></div></div>';
        }
        function pct(n) { return sum ? (n / sum * 100).toFixed(1) + '% of rows' : ''; }
        document.getElementById('edm-cln-summary').innerHTML =
            card('Rows checked', sum, 'edm-stat-value--blue', 'in your file') +
            card('Valid', t.valid, 'edm-stat-value--green', pct(t.valid)) +
            card('Invalid', t.invalid, 'edm-stat-value--red', pct(t.invalid)) +
            card('Corrected', t.corrected, 'edm-stat-value--orange', 'valid after a domain fix');

        var link = API + '?action=cleaner_download&token=' + encodeURIComponent(staged.token) + '&kind=';
        document.getElementById('edm-cln-dl-valid').href = link + 'valid';
        document.getElementById('edm-cln-dl-invalid').href = link + 'invalid';

        var reasons = d.reasons || {};
        var keys = Object.keys(reasons);
        document.getElementById('edm-cln-reasons-wrap').hidden = keys.length === 0;
        document.getElementById('edm-cln-reasons').innerHTML = keys.map(function (k, i) {
            return '<tr><td>' + (i + 1) + '</td><td>' + esc(k) + '</td><td class="text-end">' + fmt(reasons[k]) + '</td></tr>';
        }).join('');

        panel.hidden = true;
        goStep(3);
    }
})();
