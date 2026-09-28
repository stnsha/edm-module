/**
 * Template editor. Hosts the same EmailBuilder.js iframe as the Email creator
 * (email-builder/editor, source in email-builder/editor-src) and saves through
 * templates/api.php (load / save). Saved per template: editor_json (the block
 * tree, so the design reopens editable) and html (the rendered email).
 *
 * The postMessage protocol is documented in editor-src/src/bridge.ts; this is
 * the Email creator's host logic (email-builder/email-builder.js) without the
 * newsletter-only parts (status, submit for review).
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
