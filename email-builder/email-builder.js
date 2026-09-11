/**
 * Email creator. Loads a campaign's HTML body from
 * email-builder/api.php (which proxies edm-api edm/campaigns/{id}/content),
 * edits it as rich text via Quill (https://quilljs.com), and saves the
 * resulting HTML as a new version. Live preview mirrors the editor output.
 */
(function () {
    'use strict';

    var wrap = document.querySelector('[data-campaign]');
    if (!wrap) { return; }

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var CID  = wrap.getAttribute('data-campaign');
    var API  = BASE + 'email-builder/api.php?campaign=' + encodeURIComponent(CID);

    var previewEl = document.getElementById('edm-eb-preview');
    var nameEl    = document.getElementById('edm-eb-name');
    var subEl     = document.getElementById('edm-eb-sub');
    var alertEl   = document.getElementById('edm-eb-alert');
    var savedEl   = document.getElementById('edm-eb-saved');
    var saveBtn   = document.getElementById('edm-eb-save');
    var deskBtn   = document.getElementById('edm-eb-desktop');
    var mobBtn    = document.getElementById('edm-eb-mobile');

    var quill = new Quill('#edm-eb-quill', {
        theme: 'snow',
        modules: {
            toolbar: [
                [{ header: [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                ['blockquote', 'link', 'image'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                [{ align: [] }],
                ['clean']
            ]
        }
    });

    var contentLoaded = false;

    function call(action, method, body) {
        return fetch(API + '&action=' + action, {
            method: method || 'GET',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: body ? JSON.stringify(body) : null
        }).then(function (r) { return r.json(); });
    }

    function showAlert(m) { alertEl.textContent = m || 'Something went wrong.'; alertEl.hidden = false; }

    function renderPreview() {
        var doc = previewEl.contentDocument || previewEl.contentWindow.document;
        doc.open();
        doc.write(quill.root.innerHTML || '<p style="color:#999;font-family:sans-serif">Empty</p>');
        doc.close();
    }

    function setContent(html) {
        if (contentLoaded) { return; }
        contentLoaded = true;
        quill.root.innerHTML = html || '';
        renderPreview();
    }

    var t;
    quill.on('text-change', function () {
        clearTimeout(t);
        t = setTimeout(renderPreview, 250);
        savedEl.hidden = true;
    });

    deskBtn.addEventListener('click', function () {
        previewEl.style.maxWidth = '';
        deskBtn.classList.add('active'); mobBtn.classList.remove('active');
    });
    mobBtn.addEventListener('click', function () {
        previewEl.style.maxWidth = '390px';
        mobBtn.classList.add('active'); deskBtn.classList.remove('active');
    });

    saveBtn.addEventListener('click', function () {
        saveBtn.disabled = true;
        alertEl.hidden = true;
        call('content_save', 'POST', { html: quill.root.innerHTML }).then(function (res) {
            saveBtn.disabled = false;
            if (!res.success) { showAlert(res.message); return; }
            savedEl.hidden = false;
        }).catch(function () { saveBtn.disabled = false; showAlert('Could not reach the server.'); });
    });

    call('load').then(function (res) {
        if (!res.success) { showAlert(res.message); return; }
        var c = res.data || {};
        nameEl.textContent = c.name || ('Newsletter #' + CID);
        subEl.textContent = (c.subject ? 'Subject: ' + c.subject + '  -  ' : '') + 'Status: ' + (c.status || 'draft');
        if (c.content && c.content.html != null) { setContent(c.content.html); }
    });

    call('content_get').then(function (res) {
        if (res.success && res.data && res.data.html != null) { setContent(res.data.html); }
    });
})();
