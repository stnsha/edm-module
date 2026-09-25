/**
 * Email creator. Hosts EmailBuilder.js (https://github.com/usewaypoint/email-builder-js,
 * MIT) in an iframe - the prebuilt bundle in email-builder/editor/, source in
 * email-builder/editor-src/ - and owns load / save through email-builder/api.php
 * (which proxies edm-api edm/campaigns/{id}/content).
 *
 * The iframe and this page talk over postMessage; the protocol is documented
 * in editor-src/src/bridge.ts. Saved per campaign: editor_json (the block tree,
 * so the design reopens editable) and html (the rendered email that is sent).
 */
(function () {
    'use strict';

    var wrap = document.querySelector('[data-campaign]');
    if (!wrap) { return; }

    var BASE   = window.EDM_MODULE_BASE || '/odb/edm/';
    var CID    = wrap.getAttribute('data-campaign');
    var API    = BASE + 'email-builder/api.php?campaign=' + encodeURIComponent(CID);
    var SOURCE = 'edm-email-creator';

    var frameEl = document.getElementById('edm-eb-frame');
    var nameEl   = document.getElementById('edm-eb-name');
    var statusEl = document.getElementById('edm-eb-status');
    var f = {
        name:      document.getElementById('edm-eb-f-name'),
        sender:    document.getElementById('edm-eb-f-sender'),
        list:      document.getElementById('edm-eb-f-list'),
        subject:   document.getElementById('edm-eb-f-subject'),
        scheduled: document.getElementById('edm-eb-f-scheduled')
    };
    var alertEl = document.getElementById('edm-eb-alert');
    var stateEl = document.getElementById('edm-eb-state');
    var saveBtn = document.getElementById('edm-eb-save');
    var submitBtn = document.getElementById('edm-eb-submit');

    // Same labels / pill colours as the Newsletters list (campaign/index.php).
    var STATUS = {
        1: { label: 'Draft', cls: 'edm-pill-secondary' },
        2: { label: 'Pending submission', cls: 'edm-pill-info' },
        3: { label: 'Under BPT review', cls: 'edm-pill-info' },
        4: { label: 'Content revision', cls: 'edm-pill-warning' },
        5: { label: 'Audience validation', cls: 'edm-pill-info' },
        6: { label: 'Scheduled', cls: 'edm-pill-primary' },
        7: { label: 'Sending', cls: 'edm-pill-primary' },
        8: { label: 'Completed', cls: 'edm-pill-success' },
        9: { label: 'Archived', cls: 'edm-pill-dark' }
    };

    // Personalisation variables (merge tags), resolved at send time. Shown in
    // the editor's Personalisation box when a Text / Heading / Button / Html
    // block is selected - drag into a field or click to insert at the caret.
    // Sourced only from Contacts > Custom fields ({{key}}), rendered
    // server-side by index.php. No custom fields = no Personalisation box.
    var VARIABLES = (window.EDM_EB_CUSTOM_VARS || []).map(function (v) {
        return { token: v.token, label: v.label };
    });

    var editorReady = false;
    var content = null;      // { html, editor_json } from the API, once loaded
    var dirty = false;
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
        var html = {
            dirty:  '<i class="bi bi-circle-fill text-warning"></i> Unsaved changes',
            saving: '<span class="spinner-border spinner-border-sm"></span> Saving...',
            saved:  '<i class="bi bi-check-circle-fill text-success"></i> All changes saved',
            submitting: '<span class="spinner-border spinner-border-sm"></span> Submitting...',
            submitted:  '<i class="bi bi-send-check-fill text-success"></i> Submitted for review'
        }[kind] || '';
        stateEl.innerHTML = html;
    }

    function setDirty(v) {
        dirty = v;
        setState(v ? 'dirty' : '');
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
            assets: window.EDM_EB_ASSETS || [] // Files library images for the Image block
        });
        setDirty(false);
        saveBtn.disabled = false;
    }

    function firstError(res) {
        if (res && res.errors) {
            for (var k in res.errors) {
                if (Object.prototype.hasOwnProperty.call(res.errors, k)) { return res.errors[k][0]; }
            }
        }
        return (res && res.message) || 'Request failed.';
    }

    // API 'd-m-Y H:i:s' <-> <input type="datetime-local"> 'Y-m-dTH:i'.
    function toInputDate(v) {
        var m = /^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2})/.exec(v || '');
        return m ? m[3] + '-' + m[2] + '-' + m[1] + 'T' + m[4] + ':' + m[5] : '';
    }

    function fillSettings(c) {
        f.name.value = c.name || '';
        f.sender.value = c.sender_id != null ? String(c.sender_id) : '';
        f.list.value = c.list_id != null ? String(c.list_id) : '';
        f.subject.value = c.subject || '';
        f.scheduled.value = toInputDate(c.scheduled_at);
    }

    function settingsPayload() {
        return {
            name: f.name.value.trim(),
            sender_id: f.sender.value,
            list_id: f.list.value,
            subject: f.subject.value,
            scheduled_at: f.scheduled.value
        };
    }

    // Any settings edit counts as unsaved; the heading follows the name.
    Object.keys(f).forEach(function (k) {
        f[k].addEventListener('input', function () { setDirty(true); });
        f[k].addEventListener('change', function () { setDirty(true); });
    });
    f.name.addEventListener('input', function () {
        nameEl.textContent = f.name.value.trim() || 'Untitled newsletter';
        nameEl.title = nameEl.textContent;
    });

    function exportDesign(cb) {
        var id = 'x' + (++seq);
        pending[id] = cb;
        toEditor({ type: 'export', requestId: id });
    }

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

    var afterSave = null; // one-shot callback run after a successful save

    function saveFailed(msg) {
        saveBtn.disabled = false;
        submitBtn.disabled = false;
        afterSave = null;
        setState(dirty ? 'dirty' : '');
        showAlert(msg);
    }

    // One Save for both halves: settings first (validated by edm-api), then
    // the design. A settings error stops before the design is written.
    function save() {
        if (saveBtn.disabled || !f.name.value.trim()) {
            // Could not start (still loading / already saving / no name):
            // drop a pending Submit so its button does not stay stuck.
            afterSave = null;
            submitBtn.disabled = false;
            if (!saveBtn.disabled) {
                f.name.focus();
                showAlert('Name is required.');
            }
            return;
        }
        saveBtn.disabled = true;
        alertEl.hidden = true;
        setState('saving');
        call('settings_save', 'POST', settingsPayload()).then(function (res) {
            if (!res.success) { saveFailed(firstError(res)); return; }
            saveDesign();
        }).catch(function () { saveFailed('Could not reach the server.'); });
    }

    function saveDesign() {
        exportDesign(function (out) {
            call('content_save', 'POST', { html: out.html, editor_json: out.document }).then(function (res) {
                if (!res.success) { saveFailed(res.message); return; }
                saveBtn.disabled = false;
                setDirty(false);
                setState('saved');
                var next = afterSave;
                afterSave = null;
                if (next) { next(); }
            }).catch(function () {
                saveFailed('Could not reach the server.');
            });
        });
    }

    saveBtn.addEventListener('click', save);

    // Only a draft (1) or content revision (4) can go to review.
    function setStatus(status) {
        var st = STATUS[status] || STATUS[1];
        statusEl.className = 'edm-pill ' + st.cls;
        statusEl.textContent = st.label;
        statusEl.hidden = false;
        submitBtn.hidden = !(status === 1 || status === 4);
    }

    // Submit = save everything first (so review sees the current version),
    // then move the newsletter into review.
    submitBtn.addEventListener('click', function () {
        var name = f.name.value.trim() || 'this newsletter';
        window.edmConfirm('Save and submit "' + name + '" for review? It cannot be edited while under review.', function () {
            submitBtn.disabled = true;
            afterSave = function () {
                setState('submitting');
                call('submit', 'POST', {}).then(function (res) {
                    submitBtn.disabled = false;
                    if (!res.success) { setState(''); showAlert(firstError(res)); return; }
                    setStatus((res.data && res.data.status) || 2);
                    setState('submitted');
                }).catch(function () {
                    submitBtn.disabled = false;
                    setState('');
                    showAlert('Could not reach the server.');
                });
            };
            save();
        });
    });

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
        if (!res.success) { showAlert(res.message); return; }
        var c = res.data || {};
        nameEl.textContent = c.name || ('Newsletter #' + CID);
        nameEl.title = nameEl.textContent;
        setStatus(c.status);
        fillSettings(c);
        content = c.content || { html: '', editor_json: null };
        pushContent();
    }).catch(function () {
        showAlert('Could not reach the server.');
    });
})();
