/**
 * Raise / edit a review request (approval/edit.php), after atem/js/edit.js:
 * Quill editors for Objective, Audience Brief and Copywriting (toolbar as
 * atem's ATEM Description, without image embeds - artwork goes in the
 * Artwork card), an artwork drop zone with a staged file list, inline
 * field errors and the save bar. Saves as one multipart POST to
 * approval/api.php approvals_save (new files + ids of removed ones), then
 * returns to the Approval Centre list.
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var API  = BASE + 'approval/api.php';
    var CFG  = window.EDM_REVIEW || { id: 0, canEdit: true, files: [] };
    var MAX_BYTES = 10 * 1024 * 1024;
    var MAX_FILES = 10;
    var ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fileExt(name) { var i = name.lastIndexOf('.'); return i < 0 ? '' : name.slice(i + 1).toLowerCase(); }
    function fileSize(b) {
        return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
    }
    function setError(id, msg) { var el = $(id); if (el) { el.textContent = msg || ''; } }

    // ---- editors ----

    var TOOLBAR = [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ color: [] }, { background: [] }],
        [{ list: 'ordered' }, { list: 'bullet' }],
        [{ indent: '-1' }, { indent: '+1' }],
        [{ align: [] }],
        ['link'],
        ['clean']
    ];
    function editor(id, html, placeholder) {
        var q = new Quill('#' + id, {
            theme: 'snow',
            readOnly: !CFG.canEdit,
            placeholder: CFG.canEdit ? placeholder : '',
            modules: { toolbar: CFG.canEdit ? TOOLBAR : false }
        });
        if (html) { q.clipboard.dangerouslyPasteHTML(html); q.history.clear(); }
        return q;
    }
    var editors = {
        objective:      editor('edm-rv-objective', CFG.objective, 'What should this campaign achieve? e.g. drive sign-ups for the year-end promotion.'),
        audience_brief: editor('edm-rv-audience', CFG.audience_brief, 'Who should receive it? e.g. members in Klang Valley, active in the last 6 months.'),
        copywriting:    editor('edm-rv-copy', CFG.copywriting, 'Subject line, headline, body copy and call to action.')
    };
    // Empty editor ("<p><br></p>") saves as ''.
    function html(q) { return q.getText().trim() === '' ? '' : q.root.innerHTML; }

    // ---- artwork ----

    var existing = (CFG.files || []).slice(); // saved { id, name, url, size }
    var staged = [];                           // File objects not yet saved
    var removeIds = [];

    // File cards as in Files > Upload images: thumbnail (or PDF icon), name,
    // size, a tick once saved; new files show "not saved yet" and get a
    // progress bar while the review is being saved.
    var thumbs = []; // object URLs of staged images, revoked on re-render
    function card(nameHtml, fileName, meta, thumb, state, isNew) {
        return '<li class="edm-up-item' + (isNew ? ' is-new' : ' is-done') + '">' +
            '<span class="edm-up-thumb">' + (thumb
                ? '<img src="' + esc(thumb) + '" alt="">'
                : '<i class="bi ' + (/\.pdf$/i.test(fileName) ? 'bi-file-earmark-pdf' : 'bi-image') + '"></i>') + '</span>' +
            '<span class="edm-up-info"><span class="edm-up-name">' + nameHtml + '</span><span class="edm-up-meta">' + esc(meta) + '</span></span>' +
            '<span class="edm-up-state">' + state + '</span>' +
            (isNew ? '<span class="edm-up-bar" hidden><span class="edm-up-bar-fill"></span></span><span class="edm-up-pct"></span>' : '') +
        '</li>';
    }
    function removeBtn(attr, v) {
        return CFG.canEdit ? '<button type="button" class="edm-up-icon-btn" ' + attr + '="' + v + '" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button>' : '';
    }
    function renderFiles() {
        thumbs.forEach(function (u) { URL.revokeObjectURL(u); });
        thumbs = [];
        var html = existing.map(function (f) {
            return card('<a href="' + esc(f.url) + '" target="_blank" rel="noopener">' + esc(f.name) + '</a>', f.name, fileSize(f.size),
                /\.pdf$/i.test(f.name) ? null : f.url,
                '<i class="bi bi-check-lg text-success" title="Saved"></i>' + removeBtn('data-saved', f.id), false);
        }).concat(staged.map(function (f, i) {
            var t = /^image\//.test(f.type) ? URL.createObjectURL(f) : null;
            if (t) { thumbs.push(t); }
            return card(esc(f.name), f.name, fileSize(f.size) + ' - not saved yet', t, removeBtn('data-staged', i), true);
        }));
        $('edm-rv-file-list').innerHTML = html.join('');
        var empty = $('edm-rv-file-empty');
        if (empty) { empty.hidden = html.length > 0; }
    }
    // Save progress on every new file's card.
    function showUploadProgress(pct) {
        [].forEach.call(document.querySelectorAll('#edm-rv-file-list .edm-up-item.is-new'), function (li) {
            li.querySelector('.edm-up-bar').hidden = pct <= 0;
            li.querySelector('.edm-up-bar-fill').style.width = pct + '%';
            li.querySelector('.edm-up-pct').textContent = pct > 0 ? Math.round(pct) + '%' : '';
        });
    }

    function addFiles(list) {
        setError('edm-rv-files-error', '');
        var problems = [];
        [].forEach.call(list || [], function (f) {
            if (ALLOWED_EXT.indexOf(fileExt(f.name)) < 0) { problems.push(f.name + ': file type not allowed.'); return; }
            if (f.size > MAX_BYTES) { problems.push(f.name + ': larger than 10 MB.'); return; }
            if (existing.length + staged.length >= MAX_FILES) { problems.push(f.name + ': up to ' + MAX_FILES + ' files.'); return; }
            staged.push(f);
        });
        if (problems.length) { setError('edm-rv-files-error', problems.join(' ')); }
        renderFiles();
    }

    if (CFG.canEdit) {
        var drop = $('edm-rv-dropzone');
        var input = $('edm-rv-file-input');
        input.addEventListener('change', function () { addFiles(input.files); input.value = ''; });
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
        });
        drop.addEventListener('drop', function (e) { if (e.dataTransfer) { addFiles(e.dataTransfer.files); } });

        $('edm-rv-file-list').addEventListener('click', function (e) {
            var x = e.target.closest('.edm-up-icon-btn');
            if (!x) { return; }
            if (x.hasAttribute('data-saved')) {
                var id = parseInt(x.getAttribute('data-saved'), 10);
                removeIds.push(id);
                existing = existing.filter(function (f) { return f.id !== id; });
            } else {
                staged.splice(parseInt(x.getAttribute('data-staged'), 10), 1);
            }
            renderFiles();
        });
    }
    renderFiles();

    // ---- save / delete ----

    var FIELD_ERRORS = {
        title: 'edm-rv-title-error',
        campaign_id: 'edm-rv-campaign-error',
        objective: 'edm-rv-objective-error',
        audience_brief: 'edm-rv-audience_brief-error',
        copywriting: 'edm-rv-copywriting-error',
        files: 'edm-rv-files-error'
    };
    function clearErrors() {
        Object.keys(FIELD_ERRORS).forEach(function (k) { setError(FIELD_ERRORS[k], ''); });
        setError('edm-rv-save-error', '');
    }

    function validate() {
        var ok = true;
        if (!$('edm-rv-title').value.trim()) { setError('edm-rv-title-error', 'Title is required.'); ok = false; }
        if (!$('edm-rv-campaign').value) { setError('edm-rv-campaign-error', 'Choose a campaign.'); ok = false; }
        if (!html(editors.objective)) { setError('edm-rv-objective-error', 'Objective is required.'); ok = false; }
        if (!html(editors.audience_brief)) { setError('edm-rv-audience_brief-error', 'Audience brief is required.'); ok = false; }
        if (!html(editors.copywriting)) { setError('edm-rv-copywriting-error', 'Copywriting is required.'); ok = false; }
        if (!ok) { setError('edm-rv-save-error', 'Please fix the highlighted fields.'); }
        return ok;
    }

    var saveBtn = $('edm-rv-save-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            clearErrors();
            if (!validate()) { return; }
            var fd = new FormData();
            if (CFG.id) { fd.append('id', CFG.id); }
            fd.append('title', $('edm-rv-title').value.trim());
            fd.append('campaign_id', $('edm-rv-campaign').value);
            Object.keys(editors).forEach(function (k) { fd.append(k, html(editors[k])); });
            staged.forEach(function (f) { fd.append('files[]', f, f.name); });
            fd.append('remove_files', removeIds.join(','));

            var label = saveBtn.textContent;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
            function failed(msg) {
                saveBtn.disabled = false;
                saveBtn.textContent = label;
                showUploadProgress(0);
                setError('edm-rv-save-error', msg);
            }
            // XHR rather than fetch, for upload progress on the file cards.
            var xhr = new XMLHttpRequest();
            xhr.open('POST', API + '?action=approvals_save');
            xhr.setRequestHeader('Accept', 'application/json');
            if (staged.length) {
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) { showUploadProgress(Math.min(95, e.loaded / e.total * 100)); }
                });
            }
            xhr.addEventListener('load', function () {
                var res = null;
                try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }
                if (!res) { failed('The server could not save the review (HTTP ' + xhr.status + '). The files may be too large for the server settings.'); return; }
                if (res.success) { showUploadProgress(100); window.location.href = BASE + 'approval/index.php'; return; }
                var shown = false;
                Object.keys(res.errors || {}).forEach(function (k) {
                    if (FIELD_ERRORS[k]) { setError(FIELD_ERRORS[k], res.errors[k][0]); shown = true; }
                });
                failed(shown ? 'Please fix the highlighted fields.' : (res.message || 'Could not save the review.'));
            });
            xhr.addEventListener('error', function () { failed('Could not reach the server.'); });
            xhr.send(fd);
        });
    }

    // ---- BPT Review (spec 5.2 steps 2-3): approve / reject ----

    function bptDecide(decision) {
        setError('edm-bpt-error', '');
        var checks = [].map.call(document.querySelectorAll('.edm-bpt-check:checked'), function (c) { return c.value; });
        var total = document.querySelectorAll('.edm-bpt-check').length;
        var comment = $('edm-bpt-comment').value.trim();
        if (decision === 2 && checks.length !== total) {
            setError('edm-bpt-error', 'Tick every item on the checklist before approving.');
            return;
        }
        if (decision === 3 && !comment) {
            setError('edm-bpt-error', 'State the reason for rejection in the comment.');
            $('edm-bpt-comment').focus();
            return;
        }
        var approve = decision === 2;
        window.edmConfirm(approve
            ? 'Approve this request? It moves on to BI/CRM for audience validation.'
            : 'Reject this request? It goes back to the requester to change and resubmit, and the campaign returns to content revision.',
        function () {
            fetch(API + '?action=approvals_decide', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ id: CFG.id, stage: 'bpt', decision: decision, comment: comment, checks: checks })
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) {
                        var errs = res.errors || {};
                        setError('edm-bpt-error', (errs.checks || errs.comment || [res.message || 'The decision could not be saved.'])[0]);
                        return;
                    }
                    window.edmAlert(approve
                        ? 'BPT review approved. The request is now with BI/CRM for audience validation.'
                        : 'BPT review rejected. The requester has been sent back the request.',
                    { variant: approve ? 'success' : 'warning', title: approve ? 'Approved' : 'Rejected', onClose: function () { window.location.reload(); } });
                })
                .catch(function () { setError('edm-bpt-error', 'Could not reach the server.'); });
        }, {
            title: approve ? 'Approve BPT review' : 'Reject BPT review',
            variant: approve ? 'success' : 'danger',
            confirmLabel: approve ? 'Approve' : 'Reject'
        });
    }
    if ($('edm-bpt-approve')) {
        $('edm-bpt-approve').addEventListener('click', function () { bptDecide(2); });
        $('edm-bpt-reject').addEventListener('click', function () { bptDecide(3); });
    }

    // ---- Automated QA (spec 5.2 step 5): status card, polled while running ----

    var QA_STATUS = {
        1: { label: 'Queued', cls: 'edm-pill-info' },
        2: { label: 'Running...', cls: 'edm-pill-info' },
        3: { label: 'Passed', cls: 'edm-pill-success' },
        4: { label: 'Passed with warnings', cls: 'edm-pill-warning' },
        5: { label: 'Failed', cls: 'edm-pill-danger' }
    };
    var QA_ICON = {
        pass: 'bi-check-circle-fill text-success',
        warn: 'bi-exclamation-triangle-fill text-warning',
        fail: 'bi-x-circle-fill text-danger'
    };
    var QA_TRIGGER = { bpt_approved: 'after the BPT approval', resaved: 'after the campaign was saved again', manual: 'run by hand' };
    var qaTimer = null;

    function renderQa(run) {
        var pill = $('edm-qa-pill');
        var meta = $('edm-qa-meta');
        var list = $('edm-qa-list');
        var note = $('edm-qa-note');
        if (!run) {
            pill.className = 'edm-pill edm-pill-secondary ms-2';
            pill.textContent = 'Not run yet';
            meta.textContent = 'It runs automatically once the BPT team approves. Use Run again to check the design now.';
            list.innerHTML = '';
            note.innerHTML = '';
            return;
        }
        var st = QA_STATUS[run.status] || QA_STATUS[1];
        pill.className = 'edm-pill ' + st.cls + ' ms-2';
        pill.textContent = st.label;
        if (run.status === 1 || run.status === 2) {
            meta.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Checking the design - usually under a minute.';
            list.innerHTML = '';
            note.innerHTML = '';
            clearTimeout(qaTimer);
            qaTimer = setTimeout(loadQa, 4000);
            return;
        }
        meta.textContent = 'Last run ' + (run.finished_at || run.created_at) +
            (QA_TRIGGER[run.trigger] ? ' - ' + QA_TRIGGER[run.trigger] : '') +
            (run.queued_by_name ? ' (' + run.queued_by_name + ')' : '') + '.';
        list.innerHTML = (run.results || []).map(function (c) {
            var details = c.details || [];
            var shown = details.slice(0, 10);
            return '<li class="edm-qa-item is-' + esc(c.result) + '">' +
                '<i class="bi ' + (QA_ICON[c.result] || QA_ICON.warn) + '"></i>' +
                '<div class="edm-qa-body">' +
                    '<div class="edm-qa-line"><span class="edm-qa-label">' + esc(c.label) + '</span>' +
                    '<span class="edm-qa-summary">' + esc(c.summary) + '</span>' +
                    (c.key === 'utm' && c.result === 'warn' && CFG.canRunQa !== false
                        ? '<button type="button" class="btn btn-outline-secondary btn-sm edm-qa-utm">Add UTM tags</button>' : '') +
                    '</div>' +
                    (shown.length ? '<ul class="edm-qa-details">' + shown.map(function (d) { return '<li>' + esc(d) + '</li>'; }).join('') +
                        (details.length > shown.length ? '<li>... and ' + (details.length - shown.length) + ' more</li>' : '') + '</ul>' : '') +
                '</div>' +
            '</li>';
        }).join('');
        note.innerHTML = run.status === 5
            ? '<div class="alert alert-danger py-2 px-3 small mt-3 mb-0"><i class="bi bi-x-octagon me-1"></i>Scheduling is blocked until the failed checks are fixed. ' +
                '<a href="' + BASE + 'email-builder/index.php?campaign=' + CFG.campaignId + '" target="_blank" rel="noopener">Open the campaign in the Email creator</a> - saving the fix runs the check again.</div>'
            : '';
    }

    function loadQa() {
        if (!$('edm-qa-card')) { return; }
        fetch(API + '?action=qa_status&id=' + CFG.id, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { $('edm-qa-meta').textContent = res.message || 'Could not load the QA status.'; return; }
                renderQa(res.data || null);
            })
            .catch(function () { $('edm-qa-meta').textContent = 'Could not reach the server.'; });
    }

    function qaAction(action, button, busyText) {
        var label = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + busyText;
        fetch(API + '?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ id: CFG.id })
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                button.disabled = false;
                button.innerHTML = label;
                if (!res.success) { window.edmAlert(res.message || 'Could not start the QA check.', { variant: 'danger' }); return; }
                renderQa(res.data);
            })
            .catch(function () {
                button.disabled = false;
                button.innerHTML = label;
                window.edmAlert('Could not reach the server.', { variant: 'danger' });
            });
    }

    if ($('edm-qa-card')) {
        $('edm-qa-run').addEventListener('click', function () { qaAction('qa_run', this, 'Queuing...'); });
        $('edm-qa-list').addEventListener('click', function (e) {
            var b = e.target.closest('.edm-qa-utm');
            if (!b) { return; }
            window.edmConfirm('Add utm_source=edm, utm_medium=email and utm_campaign to every link in the campaign design? The design is saved as a new version and checked again.', function () {
                qaAction('qa_utm', b, 'Tagging...');
            }, { title: 'Add UTM tags', variant: 'info', confirmLabel: 'Add tags' });
        });
        loadQa();
    }

    var delBtn = $('edm-rv-delete-btn');
    if (delBtn) {
        delBtn.addEventListener('click', function () {
            window.edmConfirm('Delete this review request and its artwork?', function () {
                fetch(API + '?action=approvals_delete', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ id: CFG.id })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) { setError('edm-rv-save-error', res.message || 'Could not delete.'); return; }
                        window.location.href = BASE + 'approval/index.php';
                    })
                    .catch(function () { setError('edm-rv-save-error', 'Could not reach the server.'); });
            });
        });
    }
})();
