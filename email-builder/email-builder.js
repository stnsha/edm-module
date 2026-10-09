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
    var API    = BASE + 'email-builder/api.php?newsletter=' + encodeURIComponent(CID);
    var SOURCE = 'edm-email-creator';

    var frameEl = document.getElementById('edm-eb-frame');
    var nameEl   = document.getElementById('edm-eb-name');
    var statusEl = document.getElementById('edm-eb-status');
    var f = {
        name:      document.getElementById('edm-eb-f-name'),
        sender:    document.getElementById('edm-eb-f-sender'),
        replyTo:   document.getElementById('edm-eb-f-reply-to'),
        subject:   document.getElementById('edm-eb-f-subject'),
        preheader: document.getElementById('edm-eb-f-preheader'),
        scheduled: document.getElementById('edm-eb-f-scheduled'),
        whenNow:   document.getElementById('edm-eb-f-when-now'),
        whenLater: document.getElementById('edm-eb-f-when-later'),
        trackOpens:  document.getElementById('edm-eb-f-track-opens'),
        trackClicks: document.getElementById('edm-eb-f-track-clicks'),
        utmEnabled:  document.getElementById('edm-eb-f-utm-enabled'),
        utmSource:   document.getElementById('edm-eb-f-utm-source'),
        utmMedium:   document.getElementById('edm-eb-f-utm-medium'),
        utmCampaign: document.getElementById('edm-eb-f-utm-campaign'),
        utmContent:  document.getElementById('edm-eb-f-utm-content')
    };
    var alertEl = document.getElementById('edm-eb-alert');
    var stateEl = document.getElementById('edm-eb-state');
    var saveBtn = document.getElementById('edm-eb-save');
    var submitBtn = document.getElementById('edm-eb-submit');
    var nextApprovalBtn = document.getElementById('edm-eb-next-approval');
    var templateBtn = document.getElementById('edm-eb-template');

    // Same labels / pill colours as the Newsletters list (newsletters/index.php).
    var STATUS = {
        1: { label: 'Draft', cls: 'edm-pill-secondary' },
        2: { label: 'Pending approval', cls: 'edm-pill-warning' },
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

    // Tags (Setup step): several per newsletter, as chips. A typed name that
    // matches a tag on the master list (any case) uses it; any other becomes
    // a new tag, created by the server on Save (settings_save new_tags) -
    // except for a department PIC, who can only pick existing tags.
    var IS_PIC = !!window.EDM_EB_PIC;
    var MAX_TAGS = 10;
    var tagBox = document.getElementById('edm-eb-tags');
    var tagInput = document.getElementById('edm-eb-f-tag-input');
    var tagList = document.getElementById('edm-eb-tag-list');
    var knownTags = (window.EDM_EB_TAGS || []).slice();
    var tags = []; // [{ id: number|null, name }]

    function renderTags() {
        [].forEach.call(tagBox.querySelectorAll('.edm-tag-chip'), function (c) { c.remove(); });
        tags.forEach(function (t, i) {
            var chip = document.createElement('span');
            chip.className = 'edm-tag-chip' + (t.id ? '' : ' is-new');
            chip.title = t.id ? t.name : t.name + ' (new - created on Save)';
            chip.innerHTML = '<i class="bi bi-tag"></i>' + esc(t.name) +
                (tagInput.disabled ? '' : '<button type="button" aria-label="Remove ' + esc(t.name) + '" data-i="' + i + '">&times;</button>');
            tagBox.insertBefore(chip, tagInput);
        });
        tagInput.placeholder = tags.length ? '' : (IS_PIC ? 'Pick a tag...' : 'Add a tag...');
    }
    function addTag(raw) {
        var name = String(raw || '').replace(/\s+/g, ' ').trim();
        tagInput.value = '';
        if (!name) { return; }
        var lower = name.toLowerCase();
        if (tags.some(function (t) { return t.name.toLowerCase() === lower; })) { return; }
        if (tags.length >= MAX_TAGS) { showAlert('A newsletter can have at most ' + MAX_TAGS + ' tags.'); return; }
        var known = knownTags.filter(function (t) { return t.name.toLowerCase() === lower; })[0];
        if (!known && IS_PIC) { showAlert('Choose an existing tag from the list - new tags are added by the EDM team.'); return; }
        tags.push(known ? { id: known.id, name: known.name } : { id: null, name: name.slice(0, 100) });
        renderTags();
        setDirty(true);
        if (typeof paintSteps === 'function') { paintSteps(); }
    }
    function setTags(list) {
        tags = (list || []).map(function (t) { return { id: t.id, name: t.name }; });
        (list || []).forEach(function (t) {
            if (!knownTags.some(function (k) { return k.id === t.id; })) {
                knownTags.push({ id: t.id, name: t.name });
                var o = document.createElement('option');
                o.value = t.name;
                tagList.appendChild(o);
            }
        });
        renderTags();
    }
    tagInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addTag(tagInput.value);
        } else if (e.key === 'Backspace' && tagInput.value === '' && tags.length) {
            tags.pop();
            renderTags();
            setDirty(true);
        }
    });
    // Picking a suggestion from the list adds it straight away.
    // (a datalist pick fires input without inputType, or as a replacement)
    tagInput.addEventListener('input', function (e) {
        var picked = !e.inputType || e.inputType === 'insertReplacementText';
        var v = tagInput.value.trim().toLowerCase();
        if (picked && v && knownTags.some(function (t) { return t.name.toLowerCase() === v; })) {
            addTag(tagInput.value);
        }
    });
    tagInput.addEventListener('blur', function () { if (tagInput.value.trim()) { addTag(tagInput.value); } });
    tagBox.addEventListener('click', function (e) {
        var x = e.target.closest('button[data-i]');
        if (x) {
            tags.splice(parseInt(x.getAttribute('data-i'), 10), 1);
            renderTags();
            setDirty(true);
            return;
        }
        tagInput.focus();
    });
    function tagsText() {
        return tags.map(function (t) { return t.name; }).join(', ');
    }

    // Recipients: segments and contacts to send to / leave out, with a live
    // count (js/edm-audience-picker.js).
    var audiencePicker = window.edmAudiencePicker({
        root: document.getElementById('edm-eb-audience'),
        segments: window.EDM_EB_SEGMENTS || [],
        onChange: function () { setDirty(true); paintSteps(); }
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
            submitted:  '<i class="bi bi-send-check-fill text-success"></i> Submitted for review',
            deciding:   '<span class="spinner-border spinner-border-sm"></span> Saving decision...'
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
            assets: window.EDM_EB_ASSETS || [], // Files library images for the Image block
            assetsNoDelete: IS_PIC // PIC: may upload images, not delete them
        });
        setDirty(false);
        saveBtn.disabled = false;
        templateBtn.disabled = false;
        document.getElementById('edm-eb-test').disabled = false;
        // Wizard: Design check / Review summary need the loaded design.
        refreshDesignState();
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
        f.replyTo.value = c.reply_to_id != null ? String(c.reply_to_id) : '';
        rememberPicks();
        audiencePicker.set(c.audience, c.audience_names);
        f.subject.value = c.subject || '';
        f.preheader.value = c.preheader || '';
        setTags(c.tags);
        f.scheduled.value = toInputDate(c.scheduled_at);
        f.whenNow.checked = !!c.send_now;
        f.whenLater.checked = !c.send_now;
        f.trackOpens.checked = c.track_opens !== false;
        f.trackClicks.checked = c.track_clicks !== false;
        f.utmEnabled.checked = c.utm_enabled !== false;
        var utm = c.utm || {};
        f.utmSource.value = utm.source || '';
        f.utmMedium.value = utm.medium || '';
        f.utmCampaign.value = utm.campaign || '';
        f.utmContent.value = utm.content || '';
        syncTracking();
        syncWhen();
        checkConflicts();
    }

    // Tracking step: UTM fields only while Google Analytics (UTM) is on, with
    // an example link built from them (empty campaign = slug of the title,
    // like Campaign::utm()).
    var utmFieldsEl = document.getElementById('edm-eb-utm-fields');
    var utmExampleEl = document.getElementById('edm-eb-utm-example');
    function slug(v) {
        return String(v || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'campaign';
    }
    function utmValues() {
        return {
            source: f.utmSource.value.trim() || 'edm',
            medium: f.utmMedium.value.trim() || 'email',
            campaign: f.utmCampaign.value.trim() || slug(f.name.value),
            content: f.utmContent.value.trim()
        };
    }
    function syncTracking() {
        utmFieldsEl.hidden = !f.utmEnabled.checked;
        f.utmCampaign.placeholder = slug(f.name.value);
        var u = utmValues();
        utmExampleEl.innerHTML = '<i class="bi bi-link-45deg me-1"></i>Example: https://www.example.com/page?utm_source=' + esc(u.source) +
            '&amp;utm_medium=' + esc(u.medium) + '&amp;utm_campaign=' + esc(u.campaign) + (u.content ? '&amp;utm_content=' + esc(u.content) : '');
    }
    [f.utmEnabled, f.utmSource, f.utmMedium, f.utmCampaign, f.utmContent, f.name].forEach(function (el) {
        el.addEventListener('input', syncTracking);
        el.addEventListener('change', syncTracking);
    });

    // Send step: the date only for Schedule.
    var scheduleRow = document.getElementById('edm-eb-schedule-row');
    function syncWhen() {
        scheduleRow.hidden = !f.whenLater.checked;
    }
    f.whenNow.addEventListener('change', syncWhen);
    f.whenLater.addEventListener('change', syncWhen);

    function settingsPayload() {
        return {
            name: f.name.value.trim(),
            sender_id: f.sender.value,
            reply_to_id: f.replyTo.value,
            subject: f.subject.value,
            preheader: f.preheader.value,
            tags: tags.filter(function (t) { return t.id; }).map(function (t) { return t.id; }),
            new_tags: tags.filter(function (t) { return !t.id; }).map(function (t) { return t.name; }),
            audience: audiencePicker.get(),
            send_now: f.whenNow.checked,
            scheduled_at: f.whenNow.checked ? '' : f.scheduled.value,
            track_opens: f.trackOpens.checked,
            track_clicks: f.trackClicks.checked,
            utm_enabled: f.utmEnabled.checked,
            utm: {
                source: f.utmSource.value,
                medium: f.utmMedium.value,
                campaign: f.utmCampaign.value,
                content: f.utmContent.value
            }
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
    // A draft saves with just a name (New newsletter opens an empty one);
    // Scheduling needs every field (the server checks the design too), a test
    // email needs a sender and a subject.
    var FIELD = {
        name:    { el: f.name,    label: 'Title' },
        sender:  { el: f.sender,  label: 'From' },
        subject: { el: f.subject, label: 'Subject line' }
    };
    var REQUIRED_SAVE   = [FIELD.name];
    var REQUIRED_SUBMIT = [FIELD.name, FIELD.sender, FIELD.subject];
    var REQUIRED_TEST   = [FIELD.name, FIELD.sender, FIELD.subject];

    function missingField(list) {
        list = list || REQUIRED_SAVE;
        for (var i = 0; i < list.length; i++) {
            if (!list[i].el.value.trim()) { return list[i]; }
        }
        return null;
    }

    // One Save for both halves: settings first (validated server-side), then
    // the design. A settings error stops before the design is written.
    function save() {
        var missing = saveBtn.disabled ? null : missingField();
        if (saveBtn.disabled || missing) {
            // Could not start (still loading / already saving / a required
            // field is empty): drop a pending Schedule so its button does not
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
            // New tags now have ids (and join the suggestions).
            if (res.data && res.data.tags) { setTags(res.data.tags); }
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
        var missing = missingField(REQUIRED_TEST);
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

    // Create new sender: the last option of the Sender and Reply-to selects
    // (value "__new"). It opens a small form instead of being selected; the
    // new sender is added to both selects and picked in the one used.
    var NEW_SENDER     = '__new';
    var senderModalEl  = document.getElementById('edm-eb-sender-modal');
    var senderForm     = document.getElementById('edm-eb-sender-form');
    var senderName     = document.getElementById('edm-eb-sender-name');
    var senderEmail    = document.getElementById('edm-eb-sender-email');
    var senderSave     = document.getElementById('edm-eb-sender-save');
    var senderResult   = document.getElementById('edm-eb-sender-result');
    var senderModal    = null;
    var senderTarget   = 'sender';
    var lastPick       = { sender: '', replyTo: '' };

    // Called by fillSettings() so a cancelled "Create new sender" restores
    // the saved choice.
    function rememberPicks() {
        lastPick.sender = f.sender.value;
        lastPick.replyTo = f.replyTo.value;
    }

    ['sender', 'replyTo'].forEach(function (k) {
        f[k].addEventListener('change', function () {
            if (f[k].value !== NEW_SENDER) { lastPick[k] = f[k].value; return; }
            f[k].value = lastPick[k];
            senderTarget = k;
            if (!senderModal) { senderModal = new bootstrap.Modal(senderModalEl); }
            senderForm.reset();
            senderResult.hidden = true;
            senderSave.disabled = false;
            senderModal.show();
        });
    });
    senderModalEl.addEventListener('shown.bs.modal', function () { senderName.focus(); });

    senderForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var name = senderName.value.trim();
        var email = senderEmail.value.trim();
        if (!name) { senderMessage('danger', 'From name is required.'); senderName.focus(); return; }
        if (!email || !senderEmail.checkValidity()) { senderMessage('danger', 'Enter a valid email address.'); senderEmail.focus(); return; }

        senderSave.disabled = true;
        senderMessage('secondary', 'Creating...');
        call('sender_create', 'POST', { from_name: name, email: email }).then(function (res) {
            senderSave.disabled = false;
            if (!res.success) { senderMessage('danger', firstError(res)); return; }
            var s = res.data.sender;
            var label = s.from_name + ' <' + s.email + '>';
            [f.sender, f.replyTo].forEach(function (sel) {
                var opt = document.createElement('option');
                opt.value = String(s.id);
                opt.textContent = label;
                sel.insertBefore(opt, sel.querySelector('option[value="' + NEW_SENDER + '"]'));
            });
            f[senderTarget].value = String(s.id);
            lastPick[senderTarget] = String(s.id);
            setDirty(true);
            senderModal.hide();
            window.edmAlert(res.data.verification, { title: 'Sender created', variant: 'success' });
        }).catch(function () {
            senderSave.disabled = false;
            senderMessage('danger', 'Could not reach the server.');
        });
    });

    function senderMessage(kind, text) {
        senderResult.className = 'alert alert-' + kind + ' py-2 px-3 small mt-3 mb-0';
        senderResult.textContent = text;
        senderResult.hidden = false;
    }

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
                    assets: window.EDM_EB_ASSETS || [],
                    assetsNoDelete: IS_PIC
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

    // Only a draft (1) can be submitted for review. From submission on
    // (pending approval 2, scheduled 6, sending 7, completed 8, archived 9 -
    // Campaign::isLocked()) the newsletter is locked: the server refuses saves,
    // so Save and Start from template are hidden and a notice says why. A
    // withdrawn or rejected newsletter is a draft again and unlocks.
    var lockNotice = null;
    var currentStatus = null;
    function setStatus(status) {
        var st = STATUS[status] || STATUS[1];
        var locked = [2, 6, 7, 8, 9].indexOf(status) !== -1;
        currentStatus = status;
        statusEl.className = 'edm-pill ' + st.cls;
        statusEl.textContent = st.label;
        statusEl.hidden = false;
        submitBtn.hidden = status !== 1;
        nextApprovalBtn.hidden = status === 1;
        saveBtn.hidden = locked;
        templateBtn.hidden = locked;
        audiencePicker.disable(locked);
        tagInput.disabled = locked;
        renderTags();
        if (!locked) {
            if (lockNotice) { lockNotice.remove(); lockNotice = null; }
            return;
        }
        if (!lockNotice) {
            lockNotice = document.createElement('div');
            lockNotice.className = 'alert alert-info py-2 px-3 small';
            alertEl.parentNode.insertBefore(lockNotice, alertEl);
        }
        lockNotice.innerHTML = '<i class="bi bi-lock-fill me-1"></i>' + (status === 2
            ? 'This newsletter is pending approval and is locked - changes are not saved. To change it, withdraw it in step 6 (Approval).'
            : 'This newsletter is scheduled or sent and is locked - changes are not saved.' +
                (status === 6 ? ' To change it, use Recall on the Newsletters list (it returns to Draft).' : ''));
    }

    // Steps 5 and 6 from the server's answer (load / submit / approve /
    // reject / withdraw all return the newsletter plus `approval`).
    var approval = null;
    var submitterEl = document.getElementById('edm-eb-f-submitter');
    var departmentEl = document.getElementById('edm-eb-f-department');
    var apStateEl = document.getElementById('edm-eb-approval-state');
    var apInfoEl = document.getElementById('edm-eb-approval-info');
    var apChecksEl = document.getElementById('edm-eb-approval-checks');
    var apDecideEl = document.getElementById('edm-eb-approval-decide');
    var apCommentEl = document.getElementById('edm-eb-approval-comment');
    var approveBtn = document.getElementById('edm-eb-approve');
    var rejectBtn = document.getElementById('edm-eb-reject');
    var withdrawBtn = document.getElementById('edm-eb-withdraw');
    var lastCampaign = null;

    function applyDetails(c) {
        lastCampaign = c;
        approval = c.approval || null;
        setStatus(c.status);
        renderApproval();
        paintSteps();
        if (typeof wizStep !== 'undefined' && wizStep === LAST_STEP) { renderSummary(approvalSummaryEl, true); }
    }

    function sendTimeText(c) {
        if (c.status >= 6 && c.scheduled_at) { return c.scheduled_at; }
        return c.send_now ? 'Send now - one minute after approval' : (c.scheduled_at || 'Not chosen');
    }

    function renderApproval() {
        var c = lastCampaign;
        if (!c) { return; }
        var me = (approval && approval.me) || {};
        // Step 5: the stored submitter once submitted, else whoever is signed in.
        var submitted = c.status !== 1 && !!c.submitted_by_name;
        submitterEl.value = submitted ? c.submitted_by_name : (me.name || '');
        departmentEl.value = (submitted ? c.submitted_department : me.department) || 'Not set';

        var state;
        if (c.status === 1 && c.review_comment) {
            state = '<div class="alert alert-danger py-2 px-3 small"><i class="bi bi-x-circle-fill me-1"></i>Rejected by ' +
                esc(c.reviewed_by_name || 'the reviewer') + (c.reviewed_at ? ' on ' + esc(c.reviewed_at) : '') +
                ': <strong>' + esc(c.review_comment) + '</strong><br>Make the changes, then submit it for review again in step 5.</div>';
        } else if (c.status === 1) {
            state = '<div class="alert alert-info py-2 px-3 small"><i class="bi bi-info-circle-fill me-1"></i>Not submitted yet. Submit it for review in step 5.</div>';
        } else if (c.status === 2) {
            var approvers = (approval && approval.approvers) || [];
            state = '<div class="alert alert-warning py-2 px-3 small"><i class="bi bi-hourglass-split me-1"></i>Waiting for approval by ' +
                esc(approvers.length ? approvers.join(', ') : 'the EDM approver') + '.' +
                (approval && approval.can_approve ? ' You can approve or reject it below.' : '') + '</div>';
        } else if (c.reviewed_by_name) {
            state = '<div class="alert alert-success py-2 px-3 small"><i class="bi bi-check-circle-fill me-1"></i>Approved by ' +
                esc(c.reviewed_by_name) + (c.reviewed_at ? ' on ' + esc(c.reviewed_at) : '') + '.</div>';
        } else {
            state = '<div class="alert alert-success py-2 px-3 small"><i class="bi bi-check-circle-fill me-1"></i>' + esc((STATUS[c.status] || STATUS[1]).label) + '.</div>';
        }
        apStateEl.innerHTML = state;

        function row(label, value) {
            return '<div class="edm-wiz-row"><dt>' + esc(label) + '</dt><dd>' + esc(value) + '</dd></div>';
        }
        apInfoEl.innerHTML = c.submitted_by_name
            ? row('Submitted by', c.submitted_by_name) +
              row('Department', c.submitted_department || 'Not set') +
              row('Submitted on', c.submitted_at || '') +
              row('Send time', sendTimeText(c)) +
              (c.status !== 2 && c.reviewed_by_name ? row(c.status === 1 ? 'Rejected by' : 'Approved by', c.reviewed_by_name + (c.reviewed_at ? ' on ' + c.reviewed_at : '')) : '')
            : row('Send time', sendTimeText(c));

        // The checks matter while it can still be submitted or approved.
        var checks = (approval && approval.checks) || [];
        var showChecks = c.status === 1 || c.status === 2;
        apChecksEl.previousElementSibling.hidden = !showChecks;
        apChecksEl.hidden = !showChecks;
        apChecksEl.innerHTML = checks.map(function (k) {
            return '<li class="' + (k.ok ? 'is-ok' : 'is-fail') + '"><i class="bi ' + (k.ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + '"></i>' +
                '<span><strong>' + esc(k.label) + '</strong> - ' + esc(k.detail) + '</span></li>';
        }).join('');

        var canApprove = !!(approval && approval.can_approve);
        apDecideEl.hidden = !canApprove;
        approveBtn.hidden = !canApprove;
        rejectBtn.hidden = !canApprove;
        withdrawBtn.hidden = !(approval && approval.can_withdraw);
    }

    // One decision call (approve / reject / withdraw) and its result.
    function decide(action, body, done) {
        [approveBtn, rejectBtn, withdrawBtn].forEach(function (b) { b.disabled = true; });
        setState('deciding');
        call(action, 'POST', body || {}).then(function (res) {
            [approveBtn, rejectBtn, withdrawBtn].forEach(function (b) { b.disabled = false; });
            setState('');
            if (!res.success) { showAlert(firstError(res)); return; }
            alertEl.hidden = true;
            apCommentEl.value = '';
            applyDetails(res.data || {});
            if (done) { done(); }
        }).catch(function () {
            [approveBtn, rejectBtn, withdrawBtn].forEach(function (b) { b.disabled = false; });
            setState('');
            showAlert('Could not reach the server.');
        });
    }

    approveBtn.addEventListener('click', function () {
        var c = lastCampaign || {};
        window.edmConfirm('Approve "' + (c.name || 'this newsletter') + '"? It is scheduled (' + sendTimeText(c) +
            ') and stays locked. It can still be recalled from the Newsletters list until it starts sending.', function () {
            decide('approve');
        }, { title: 'Approve newsletter', variant: 'success', confirmLabel: 'Approve' });
    });
    rejectBtn.addEventListener('click', function () {
        var comment = apCommentEl.value.trim();
        if (!comment) {
            apCommentEl.focus();
            showAlert('State the reason for rejection in the comment box.');
            return;
        }
        window.edmConfirm('Reject this newsletter? It goes back to Draft and the submitter sees your comment.', function () {
            decide('reject', { comment: comment });
        }, { title: 'Reject newsletter', variant: 'danger', confirmLabel: 'Reject' });
    });
    withdrawBtn.addEventListener('click', function () {
        window.edmConfirm('Withdraw this newsletter from review? It goes back to Draft so you can change it; submit it again when ready.', function () {
            decide('withdraw');
        }, { title: 'Withdraw newsletter', variant: 'warning', confirmLabel: 'Withdraw' });
    });

    // Submit for review = save everything first (so the approver sees the
    // current version), then submit; the server runs the readiness checks and
    // stamps the submitter + department. Step 6 opens afterwards.
    submitBtn.addEventListener('click', function () {
        var missing = missingField(REQUIRED_SUBMIT);
        if (missing) {
            goStep(1);
            missing.el.focus();
            showAlert(missing.label + ' is required before submitting for review.');
            return;
        }
        if (!f.whenNow.checked && !f.scheduled.value) {
            f.scheduled.focus();
            showAlert('Choose Send now, or a send date and time, before submitting for review.');
            return;
        }
        var name = f.name.value.trim() || 'this newsletter';
        window.edmConfirm('Save and submit "' + name + '" for review? It is locked while it waits for approval.', function () {
            submitBtn.disabled = true;
            afterSave = function () {
                setState('submitting');
                call('submit', 'POST', {}).then(function (res) {
                    submitBtn.disabled = false;
                    if (!res.success) { setState(''); showAlert(firstError(res)); return; }
                    applyDetails(res.data || {});
                    setState('submitted');
                    goStep(6);
                }).catch(function () {
                    submitBtn.disabled = false;
                    setState('');
                    showAlert('Could not reach the server.');
                });
            };
            save();
        }, { title: 'Submit for review', variant: 'success', confirmLabel: 'Submit' });
    });

    // Wizard: six steps on one page (Setup, Recipients, Design, Tracking,
    // Send, Approval). Steps never block each other - every field is saved by
    // the top-bar Save from any step; a step shows a green check once it is
    // complete (Recipients / Tracking once visited - both have defaults; Send
    // once submitted; Approval once approved). The current step is kept in
    // the URL hash, so a reload stays on it.
    var SEND_STEP = 5;
    var LAST_STEP = 6;
    var wizSteps = [].slice.call(document.querySelectorAll('.edm-wiz-step'));
    var wizPanes = [].slice.call(document.querySelectorAll('.edm-wiz-pane'));
    var summaryEl = document.getElementById('edm-wiz-summary');
    var approvalSummaryEl = document.getElementById('edm-eb-approval-summary');
    var wizStep = 1;
    var visited = {};
    var designBlocks = null; // top-level blocks in the design; null until known

    function optionText(sel) {
        var o = sel.options[sel.selectedIndex];
        return o && o.value && o.value !== '__new' ? o.textContent : '';
    }
    function stepComplete(n) {
        switch (n) {
            case 1: return !missingField(REQUIRED_SUBMIT);
            case 2: return !!visited[2];
            case 3: return designBlocks !== null && designBlocks > 0;
            case 4: return !!visited[4];
            case 5: return currentStatus !== null && currentStatus !== 1;
            case 6: return [6, 7, 8, 9].indexOf(currentStatus) !== -1;
            default: return false;
        }
    }
    function paintSteps() {
        wizSteps.forEach(function (b) {
            var n = parseInt(b.getAttribute('data-step'), 10);
            var done = n !== wizStep && stepComplete(n);
            b.classList.toggle('is-active', n === wizStep);
            b.classList.toggle('is-done', done);
            b.querySelector('.edm-wiz-dot').innerHTML = done ? '<i class="bi bi-check-lg"></i>' : String(n);
            b.setAttribute('aria-current', n === wizStep ? 'step' : 'false');
        });
    }
    function goStep(n, scroll) {
        visited[wizStep] = true;
        wizStep = Math.min(LAST_STEP, Math.max(1, n));
        wizPanes.forEach(function (p) {
            p.hidden = parseInt(p.getAttribute('data-pane'), 10) !== wizStep;
        });
        // Full path: the odb layout sets a <base>, so a bare '#step-n' would
        // resolve against it and change the URL.
        try { window.history.replaceState(null, '', window.location.pathname + window.location.search + '#step-' + wizStep); } catch (err) { /* ignore */ }
        if (wizStep === SEND_STEP) { renderSummary(); }
        if (wizStep === LAST_STEP) { renderSummary(approvalSummaryEl, true); }
        paintSteps();
        if (scroll !== false) {
            document.getElementById('edm-wiz-steps').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    // Design state for the Send summary and the Design check: asks the
    // editor for its document (an empty design has no top-level blocks).
    function refreshDesignState(done) {
        if (!editorReady) { if (done) { done(); } return; }
        exportDesign(function (out) {
            var root = out.document && out.document.root;
            designBlocks = root && root.data && root.data.childrenIds ? root.data.childrenIds.length : 0;
            paintSteps();
            if (done) { done(); }
        });
    }

    // Summary of steps 1-5: in step 5 (Edit links, When row) and in step 6
    // for the approver (View links - the newsletter is locked there; the
    // send time is in the approval details above it).
    function renderSummary(target, forApproval) {
        target = target || summaryEl;
        var linkLabel = forApproval ? 'View' : 'Edit';
        function row(label, value, step, missing) {
            return '<div class="edm-wiz-row' + (missing ? ' is-missing' : '') + '">' +
                '<dt>' + esc(label) + '</dt>' +
                '<dd>' + (missing ? '<i class="bi bi-exclamation-circle me-1"></i>' : '') + value + '</dd>' +
                '<dd class="edm-wiz-row-edit"><button type="button" class="btn btn-link btn-sm p-0" data-wiz-go="' + step + '">' + linkLabel + '</button></dd>' +
            '</div>';
        }
        function onOff(v) { return v ? 'on' : 'off'; }
        function draw() {
            var sender = optionText(f.sender);
            var count = audiencePicker.lastCount;
            var when = f.whenNow.checked ? 'Send now - one minute after approval'
                : (f.scheduled.value ? f.scheduled.value.replace('T', ' ') : '');
            var u = utmValues();
            target.innerHTML =
                row('Title', esc(f.name.value.trim()) || 'Not set', 1, !f.name.value.trim()) +
                row('From', sender ? esc(sender) : 'Not chosen', 1, !sender) +
                row('Reply-to', esc(optionText(f.replyTo)) || 'Same as sender', 1, false) +
                row('Subject line', esc(f.subject.value.trim()) || 'Not set', 1, !f.subject.value.trim()) +
                row('Preview text', esc(f.preheader.value.trim()) || '<span class="text-muted">None</span>', 1, false) +
                row('Tags', tags.length ? esc(tagsText()) : '<span class="text-muted">None</span>', 1, false) +
                row('Recipients', esc(audiencePicker.describe()) +
                    (count ? '<div class="edm-wiz-row-note">' + Number(count.matched).toLocaleString('en-US') + ' of ' +
                        Number(count.total).toLocaleString('en-US') + ' subscribed contacts</div>' : ''), 2, !!count && count.matched === 0) +
                row('Design', designBlocks === null ? 'Checking...'
                    : designBlocks > 0 ? designBlocks + ' block' + (designBlocks === 1 ? '' : 's') : 'Empty - add content in Design',
                    3, designBlocks === 0) +
                row('Tracking', 'Opens ' + onOff(f.trackOpens.checked) + ', clicks ' + onOff(f.trackClicks.checked) +
                    ', Google Analytics ' + onOff(f.utmEnabled.checked) +
                    (f.utmEnabled.checked ? '<div class="edm-wiz-row-note">utm_source=' + esc(u.source) + ', utm_medium=' + esc(u.medium) +
                        ', utm_campaign=' + esc(u.campaign) + (u.content ? ', utm_content=' + esc(u.content) : '') + '</div>' : ''), 4, false) +
                (forApproval ? '' : row('When', when ? esc(when) : 'Choose a date and time above', SEND_STEP, !when));
        }
        draw();
        refreshDesignState(draw);
    }
    // The When row follows the choice live.
    [f.whenNow, f.whenLater, f.scheduled].forEach(function (el) {
        el.addEventListener('change', function () { if (wizStep === SEND_STEP) { renderSummary(); } });
    });

    wizSteps.forEach(function (b) {
        b.addEventListener('click', function () { goStep(parseInt(b.getAttribute('data-step'), 10)); });
    });
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-wiz-next], [data-wiz-prev], [data-wiz-go]');
        if (!t) { return; }
        if (t.hasAttribute('data-wiz-go')) { goStep(parseInt(t.getAttribute('data-wiz-go'), 10)); return; }
        if (t.hasAttribute('data-wiz-prev')) { goStep(wizStep - 1); return; }
        // Next from Setup needs a title (a draft cannot save without one).
        if (wizStep === 1 && !f.name.value.trim()) {
            f.name.focus();
            showAlert('Title is required.');
            return;
        }
        alertEl.hidden = true;
        if (wizStep === 3) { refreshDesignState(); }
        goStep(wizStep + 1);
    });
    Object.keys(f).forEach(function (k) {
        f[k].addEventListener('input', paintSteps);
        f[k].addEventListener('change', paintSteps);
    });

    // Preview message: saves first (the preview is the saved email), then
    // shows it as it will be sent, under an inbox-style From / Subject /
    // preview text line.
    var previewModalEl = document.getElementById('edm-eb-preview-modal');
    var previewFrame = document.getElementById('edm-eb-preview-frame');
    var previewInbox = document.getElementById('edm-eb-preview-inbox');
    var previewModal = null;
    function openPreview() {
        if (!previewModal) { previewModal = new bootstrap.Modal(previewModalEl); }
        var sender = optionText(f.sender);
        previewInbox.innerHTML =
            '<div class="edm-wiz-inbox-from">' + (sender ? esc(sender) : '<span class="text-muted">No sender</span>') + '</div>' +
            '<div><strong>' + (esc(f.subject.value.trim()) || '<span class="text-muted">No subject</span>') + '</strong>' +
            (f.preheader.value.trim() ? ' <span class="text-muted">- ' + esc(f.preheader.value.trim()) + '</span>' : '') + '</div>';
        previewFrame.src = BASE + 'newsletters/api.php?action=campaigns_preview&id=' + encodeURIComponent(CID) + '&t=' + Date.now();
        previewModal.show();
    }
    previewModalEl.addEventListener('hidden.bs.modal', function () { previewFrame.src = 'about:blank'; });
    [].forEach.call(document.querySelectorAll('[data-eb-preview]'), function (b) {
        b.addEventListener('click', function () {
            if (!dirty || saveBtn.hidden) { openPreview(); return; }
            afterSave = openPreview;
            save();
        });
    });

    (function initialStep() {
        var m = /^#step-([1-6])$/.exec(window.location.hash);
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
        if (!res.success) { showAlert(res.message); return; }
        var c = res.data || {};
        nameEl.textContent = c.name || ('Newsletter #' + CID);
        nameEl.title = nameEl.textContent;
        fillSettings(c);
        applyDetails(c);
        content = c.content || { html: '', editor_json: null };
        pushContent();
    }).catch(function () {
        showAlert('Could not reach the server.');
    });
})();
