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
    var templateBtn = document.getElementById('edm-eb-template');

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
        templateBtn.disabled = false;
        document.getElementById('edm-eb-test').disabled = false;
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

    // Calendar conflicts for the chosen send date (app/Services/
    // ScheduleConflicts): a warning only, saving is never blocked.
    var conflictsEl = document.getElementById('edm-eb-conflicts');
    var conflictSeq = 0;

    function checkConflicts() {
        var v = f.scheduled.value;
        var mine = ++conflictSeq;
        if (!v) { conflictsEl.hidden = true; return; }
        call('conflicts&date=' + encodeURIComponent(v)).then(function (res) {
            if (mine !== conflictSeq) { return; } // a newer date was picked
            var list = (res.success && res.data) || [];
            if (!list.length) { conflictsEl.hidden = true; return; }
            conflictsEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Also on this day: ' +
                list.map(function (x) {
                    return esc((x.type === 'slot' ? 'Reserved: ' : '') + x.name);
                }).join(', ') +
                ' - <a href="' + BASE + 'calendar/index.php">Calendar</a>';
            conflictsEl.hidden = false;
        }).catch(function () { /* the warning is best-effort */ });
    }

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function fillSettings(c) {
        f.name.value = c.name || '';
        f.sender.value = c.sender_id != null ? String(c.sender_id) : '';
        f.list.value = c.list_id != null ? String(c.list_id) : '';
        f.subject.value = c.subject || '';
        f.scheduled.value = toInputDate(c.scheduled_at);
        checkConflicts();
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
    f.scheduled.addEventListener('change', checkConflicts);
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
        var failed = onSaveFailed;
        onSaveFailed = null;
        if (failed) { failed(msg); }
    }
    var onSaveFailed = null; // one-shot callback run when a save fails

    // Required settings, checked in form order before anything is sent
    // (the server enforces the same rules).
    var REQUIRED = [
        { el: f.name,    label: 'Name' },
        { el: f.sender,  label: 'Sender' },
        { el: f.list,    label: 'Recipient list' },
        { el: f.subject, label: 'Subject line' }
    ];

    function missingField() {
        for (var i = 0; i < REQUIRED.length; i++) {
            if (!REQUIRED[i].el.value.trim()) { return REQUIRED[i]; }
        }
        return null;
    }

    // One Save for both halves: settings first (validated server-side), then
    // the design. A settings error stops before the design is written.
    function save() {
        var missing = saveBtn.disabled ? null : missingField();
        if (saveBtn.disabled || missing) {
            // Could not start (still loading / already saving / a required
            // field is empty): drop a pending Submit so its button does not
            // stay stuck.
            afterSave = null;
            submitBtn.disabled = false;
            if (missing) {
                missing.el.focus();
                showAlert(missing.label + ' is required.');
            }
            var failed = onSaveFailed;
            onSaveFailed = null;
            if (failed) { failed(missing ? missing.label + ' is required.' : 'another save is still running - try again.'); }
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
                onSaveFailed = null;
                if (next) { next(); }
            }).catch(function () {
                saveFailed('Could not reach the server.');
            });
        });
    }

    saveBtn.addEventListener('click', save);

    // Send test: save everything, then one "[Test]" email of the saved design
    // through SES (send_test). The last address used is remembered per browser.
    var testBtn      = document.getElementById('edm-eb-test');
    var testModalEl  = document.getElementById('edm-eb-test-modal');
    var testForm     = document.getElementById('edm-eb-test-form');
    var testTo       = document.getElementById('edm-eb-test-to');
    var testSend     = document.getElementById('edm-eb-test-send');
    var testResult   = document.getElementById('edm-eb-test-result');
    var testModal    = null;
    var TEST_TO_KEY  = 'edm-eb-test-to';

    function testMessage(kind, text) {
        testResult.className = 'alert alert-' + kind + ' py-2 px-3 small mt-3 mb-0';
        testResult.textContent = text;
        testResult.hidden = false;
    }

    testBtn.addEventListener('click', function () {
        if (!testModal) { testModal = new bootstrap.Modal(testModalEl); }
        try { testTo.value = testTo.value || window.localStorage.getItem(TEST_TO_KEY) || ''; } catch (err) { /* storage blocked */ }
        testResult.hidden = true;
        testModal.show();
    });
    testModalEl.addEventListener('shown.bs.modal', function () { testTo.focus(); });

    testForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var to = testTo.value.trim();
        if (!testTo.checkValidity() || !to) { testMessage('danger', 'Enter a valid email address.'); return; }
        var missing = missingField();
        if (missing) { testMessage('danger', missing.label + ' is required - fill it in above first.'); return; }
        try { window.localStorage.setItem(TEST_TO_KEY, to); } catch (err) { /* storage blocked */ }

        testSend.disabled = true;
        testMessage('secondary', 'Saving...');
        onSaveFailed = function (msg) {
            testSend.disabled = false;
            testMessage('danger', 'Not sent - the save failed: ' + msg);
        };
        afterSave = function () {
            testMessage('secondary', 'Sending...');
            call('send_test', 'POST', { to: to }).then(function (res) {
                testSend.disabled = false;
                if (!res.success) { testMessage('danger', firstError(res)); return; }
                testMessage('success', 'Test sent to ' + res.data.to + '.');
            }).catch(function () {
                testSend.disabled = false;
                testMessage('danger', 'Could not reach the server.');
            });
        };
        save();
    });

    // Start from template: load a copy of a template into the editor. It
    // only becomes the newsletter's body on the next Save.
    var tplModalEl = document.getElementById('edm-eb-template-modal');
    var tplSelect  = document.getElementById('edm-eb-template-select');
    var tplApply   = document.getElementById('edm-eb-template-apply');
    var tplError   = document.getElementById('edm-eb-template-error');
    var tplModal   = null;

    templateBtn.addEventListener('click', function () {
        if (!tplModal) { tplModal = new bootstrap.Modal(tplModalEl); }
        tplError.hidden = true;
        tplModal.show();
    });

    if (tplApply) {
        tplApply.addEventListener('click', function () {
            tplApply.disabled = true;
            tplError.hidden = true;
            call('template_get&template=' + encodeURIComponent(tplSelect.value)).then(function (res) {
                tplApply.disabled = false;
                if (!res.success) {
                    tplError.textContent = firstError(res);
                    tplError.hidden = false;
                    return;
                }
                toEditor({
                    type: 'load',
                    document: res.data.editor_json || null,
                    html: res.data.html || '',
                    variables: VARIABLES,
                    assets: window.EDM_EB_ASSETS || []
                });
                setDirty(true);
                tplModal.hide();
            }).catch(function () {
                tplApply.disabled = false;
                tplError.textContent = 'Could not reach the server.';
                tplError.hidden = false;
            });
        });
    }

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
