/**
 * Template editor. Hosts the same EmailBuilder.js iframe as the Email creator
 * (email-builder/editor, source in email-builder/editor-src) and saves through
 * templates/api.php (load / save). Saved per template: editor_json (the block
 * tree, so the design reopens editable) and html (the rendered email).
 *
 * The postMessage protocol is documented in editor-src/src/bridge.ts; this is
 * the Email creator's host logic (email-builder/email-builder.js) without the
 * campaign-only parts (status, submit for review), as a 3-step wizard:
 * Details, Design, Review (summary, preview, save).
 */
(function () {
    'use strict';

    var wrap = document.querySelector('[data-template]');
    if (!wrap) { return; }

    var BASE   = window.EDM_MODULE_BASE || '/odb/edm/';
    var TID    = wrap.getAttribute('data-template');
    var API    = BASE + 'templates/api.php?template=' + encodeURIComponent(TID);
    var SOURCE = 'edm-email-creator';

    var frameEl = document.getElementById('edm-eb-frame');
    var nameEl  = document.getElementById('edm-eb-name');
    var f = {
        name:      document.getElementById('edm-eb-f-name'),
        category:  document.getElementById('edm-eb-f-category'),
        thumbnail: document.getElementById('edm-eb-f-thumbnail')
    };
    var alertEl = document.getElementById('edm-eb-alert');
    var stateEl = document.getElementById('edm-eb-state');
    var saveBtn = document.getElementById('edm-eb-save');

    // Personalisation variables (merge tags) from Contacts > Custom fields,
    // rendered server-side by edit.php.
    var VARIABLES = (window.EDM_EB_CUSTOM_VARS || []).map(function (v) {
        return { token: v.token, label: v.label };
    });

    var editorReady = false;
    var content = null;      // { html, editor_json } once loaded
    var dirty = false;
    var saving = false;
    var pending = {};        // export requestId -> callback
    var seq = 0;

    function call(action, method, body) {
        return fetch(API + '&action=' + action, {
            method: method || 'GET',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: body ? JSON.stringify(body) : null
        }).then(function (r) { return r.json(); });
    }

    function showAlert(m) {
        alertEl.textContent = m || 'Something went wrong.';
        alertEl.hidden = false;
    }

    // Save indicator: '' | 'dirty' | 'saving' | 'saved'.
    function setState(kind) {
        stateEl.innerHTML = {
            dirty:  '<i class="bi bi-circle-fill text-warning"></i> Unsaved changes',
            saving: '<span class="spinner-border spinner-border-sm"></span> Saving...',
            saved:  '<i class="bi bi-check-circle-fill text-success"></i> All changes saved'
        }[kind] || '';
    }

    function setDirty(v) {
        dirty = v;
        setState(v ? 'dirty' : '');
    }

    function firstError(res) {
        if (res && res.errors) {
            for (var k in res.errors) {
                if (Object.prototype.hasOwnProperty.call(res.errors, k)) { return res.errors[k][0]; }
            }
        }
        return (res && res.message) || 'Request failed.';
    }

    function toEditor(message) {
        message.source = SOURCE;
        frameEl.contentWindow.postMessage(message, window.location.origin);
    }

    // Push the saved design into the editor once both sides are ready.
    function pushContent() {
        if (!editorReady || !content) { return; }
        toEditor({
            type: 'load',
            document: content.editor_json || null,
            html: content.html || '',
            variables: VARIABLES,
            assets: window.EDM_EB_ASSETS || []
        });
        setDirty(false);
        saveBtn.disabled = false;
        // Wizard: Design check / Review summary need the loaded design.
        refreshDesignState();
    }

    function setHeading() {
        nameEl.textContent = f.name.value.trim() || 'Untitled template';
        nameEl.title = nameEl.textContent;
    }

    // Any settings edit counts as unsaved; the heading follows the name.
    Object.keys(f).forEach(function (k) {
        f[k].addEventListener('input', function () { setDirty(true); });
    });
    f.name.addEventListener('input', setHeading);

    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin || e.source !== frameEl.contentWindow) { return; }
        var d = e.data;
        if (!d || d.source !== SOURCE) { return; }

        if (d.type === 'ready') {
            markReady();
        } else if (d.type === 'change') {
            setDirty(true);
        } else if (d.type === 'export' && pending[d.requestId]) {
            var cb = pending[d.requestId];
            delete pending[d.requestId];
            cb(d);
        }
    });

    // Fallback for a 'ready' posted before this listener existed: the editor's
    // module script has always run by the time the iframe fires load.
    function markReady() {
        hookFrameKeys();
        if (editorReady) { return; }
        editorReady = true;
        pushContent();
    }
    frameEl.addEventListener('load', markReady);
    try {
        var fd = frameEl.contentDocument;
        if (fd && fd.readyState === 'complete' && fd.location.href.indexOf('/email-builder/editor/') !== -1) {
            markReady();
        }
    } catch (err) { /* not accessible yet */ }

    function saveFailed(msg) {
        saving = false;
        saveBtn.disabled = false;
        setState(dirty ? 'dirty' : '');
        showAlert(msg);
    }

    // One Save for both halves: the design is exported from the editor, then
    // settings + design go to the server in a single call.
    function save() {
        if (saving || saveBtn.disabled) { return; }
        if (!f.name.value.trim()) {
            goStep(1);
            f.name.focus();
            showAlert('Name is required.');
            return;
        }
        saving = true;
        saveBtn.disabled = true;
        alertEl.hidden = true;
        setState('saving');

        var id = 'x' + (++seq);
        pending[id] = function (out) {
            call('save', 'POST', {
                name: f.name.value.trim(),
                category: f.category.value,
                thumbnail_url: f.thumbnail.value,
                html: out.html,
                editor_json: out.document
            }).then(function (res) {
                if (!res.success) { saveFailed(firstError(res)); return; }
                saving = false;
                saveBtn.disabled = false;
                setDirty(false);
                setState('saved');
            }).catch(function () { saveFailed('Could not reach the server.'); });
        };
        toEditor({ type: 'export', requestId: id });
    }

    saveBtn.addEventListener('click', save);

    // Wizard: Details, Design, Review (same look as the Email creator). Steps
    // never block each other; the step is kept in the URL hash.
    var LAST_STEP = 3;
    var wizSteps = [].slice.call(document.querySelectorAll('.edm-wiz-step'));
    var wizPanes = [].slice.call(document.querySelectorAll('.edm-wiz-pane'));
    var summaryEl = document.getElementById('edm-wiz-summary');
    var wizStep = 1;
    var designBlocks = null; // top-level blocks in the design; null until known

    function exportDesign(cb) {
        var id = 'w' + (++seq);
        pending[id] = cb;
        toEditor({ type: 'export', requestId: id });
    }
    function refreshDesignState(done) {
        if (!editorReady) { if (done) { done(); } return; }
        exportDesign(function (out) {
            var root = out.document && out.document.root;
            designBlocks = root && root.data && root.data.childrenIds ? root.data.childrenIds.length : 0;
            paintSteps();
            if (done) { done(out); }
        });
    }
    function stepComplete(n) {
        if (n === 1) { return f.name.value.trim() !== ''; }
        if (n === 2) { return designBlocks !== null && designBlocks > 0; }
        return false;
    }
    function paintSteps() {
        wizSteps.forEach(function (b) {
            var n = parseInt(b.getAttribute('data-step'), 10);
            var done = n !== wizStep && n < LAST_STEP && stepComplete(n);
            b.classList.toggle('is-active', n === wizStep);
            b.classList.toggle('is-done', done);
            b.querySelector('.edm-wiz-dot').innerHTML = done ? '<i class="bi bi-check-lg"></i>' : String(n);
            b.setAttribute('aria-current', n === wizStep ? 'step' : 'false');
        });
    }
    function goStep(n, scroll) {
        wizStep = Math.min(LAST_STEP, Math.max(1, n));
        wizPanes.forEach(function (p) {
            p.hidden = parseInt(p.getAttribute('data-pane'), 10) !== wizStep;
        });
        // Full path: the odb layout sets a <base>.
        try { window.history.replaceState(null, '', window.location.pathname + window.location.search + '#step-' + wizStep); } catch (err) { /* ignore */ }
        if (wizStep === LAST_STEP) { renderSummary(); }
        paintSteps();
        if (scroll !== false) {
            document.getElementById('edm-wiz-steps').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function renderSummary() {
        function row(label, value, step, missing) {
            return '<div class="edm-wiz-row' + (missing ? ' is-missing' : '') + '">' +
                '<dt>' + esc(label) + '</dt>' +
                '<dd>' + (missing ? '<i class="bi bi-exclamation-circle me-1"></i>' : '') + value + '</dd>' +
                '<dd class="edm-wiz-row-edit"><button type="button" class="btn btn-link btn-sm p-0" data-wiz-go="' + step + '">Edit</button></dd>' +
            '</div>';
        }
        function draw() {
            var thumb = f.thumbnail.value.trim();
            summaryEl.innerHTML =
                row('Name', esc(f.name.value.trim()) || 'Not set', 1, !f.name.value.trim()) +
                row('Category', esc(f.category.value.trim()) || '<span class="text-muted">None</span>', 1, false) +
                row('Thumbnail', thumb && /^https?:\/\//i.test(thumb)
                    ? '<img src="' + esc(thumb) + '" alt="" class="edm-wiz-thumb">' : (esc(thumb) || '<span class="text-muted">None</span>'), 1, false) +
                row('Design', designBlocks === null ? 'Checking...'
                    : designBlocks > 0 ? designBlocks + ' block' + (designBlocks === 1 ? '' : 's') : 'Empty - add content in Design',
                    2, designBlocks === 0);
        }
        draw();
        refreshDesignState(draw);
    }

    wizSteps.forEach(function (b) {
        b.addEventListener('click', function () { goStep(parseInt(b.getAttribute('data-step'), 10)); });
    });
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-wiz-next], [data-wiz-prev], [data-wiz-go]');
        if (!t) { return; }
        if (t.hasAttribute('data-wiz-go')) { goStep(parseInt(t.getAttribute('data-wiz-go'), 10)); return; }
        if (t.hasAttribute('data-wiz-prev')) { goStep(wizStep - 1); return; }
        if (wizStep === 1 && !f.name.value.trim()) {
            f.name.focus();
            showAlert('Name is required.');
            return;
        }
        alertEl.hidden = true;
        if (wizStep === 2) { refreshDesignState(); }
        goStep(wizStep + 1);
    });
    Object.keys(f).forEach(function (k) {
        f[k].addEventListener('input', paintSteps);
    });
    document.getElementById('edm-eb-save-final').addEventListener('click', save);

    // Preview: the current design, unsaved edits included, in a sandboxed frame.
    var previewModalEl = document.getElementById('edm-eb-preview-modal');
    var previewFrame = document.getElementById('edm-eb-preview-frame');
    var previewModal = null;
    previewModalEl.addEventListener('hidden.bs.modal', function () { previewFrame.removeAttribute('srcdoc'); });
    document.getElementById('edm-eb-preview').addEventListener('click', function () {
        if (!editorReady) { showAlert('The editor is still loading.'); return; }
        if (!previewModal) { previewModal = new bootstrap.Modal(previewModalEl); }
        exportDesign(function (out) {
            previewFrame.srcdoc = out.html || '';
            previewModal.show();
        });
    });

    (function initialStep() {
        var m = /^#step-([1-3])$/.exec(window.location.hash);
        goStep(m ? parseInt(m[1], 10) : 1, false);
    })();

    // Ctrl+S / Cmd+S saves - on this page and inside the editor iframe
    // (same origin, so its document can be listened to directly).
    function onSaveKey(e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            save();
        }
    }
    document.addEventListener('keydown', onSaveKey);
    var keyHooked = null;
    function hookFrameKeys() {
        try {
            var d = frameEl.contentDocument;
            if (d && d !== keyHooked) {
                d.addEventListener('keydown', onSaveKey);
                keyHooked = d;
            }
        } catch (err) { /* not accessible */ }
    }

    window.addEventListener('beforeunload', function (e) {
        if (dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    call('load').then(function (res) {
        if (!res.success) { showAlert(firstError(res)); return; }
        var t = res.data || {};
        f.name.value = t.name || '';
        f.category.value = t.category || '';
        f.thumbnail.value = t.thumbnail_url || '';
        setHeading();
        content = { html: t.html || '', editor_json: t.editor_json || null };
        pushContent();
    }).catch(function () {
        showAlert('Could not reach the server.');
    });
})();
