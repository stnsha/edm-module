/**
 * Files > Upload images (assets/index.php). One dialog, laid out like the
 * "Upload Photos" reference design:
 *   - drop zone / browse: every image uploads straight away to
 *     assets/api.php assets_upload (XHR, so each file has its own progress
 *     bar, a cancel button while uploading and a tick when done);
 *   - Import from URL: registers an image hosted elsewhere (assets_create).
 * A saved image can be renamed in place (pencil -> name field, Enter or
 * leaving the field saves via assets_update, Esc cancels).
 * Import (or closing the dialog) refreshes the Files table when anything
 * was added or renamed. Loaded before footer.php, so Bootstrap is touched lazily.
 */
(function () {
    'use strict';

    var BASE      = window.EDM_MODULE_BASE || '/odb/edm/';
    var API       = BASE + 'assets/api.php';
    var MAX_BYTES = 5 * 1024 * 1024;
    var TYPES     = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    var modalEl  = document.getElementById('edm-asset-upload-modal');
    var dropEl   = document.getElementById('edm-up-drop');
    var fileEl   = document.getElementById('edm-up-file');
    var listEl   = document.getElementById('edm-up-list');
    var urlEl    = document.getElementById('edm-up-url');
    var urlBtn   = document.getElementById('edm-up-url-btn');
    var urlErr   = document.getElementById('edm-up-url-error');
    var doneBtn  = document.getElementById('edm-up-done');
    var modal    = null;
    var added    = 0;   // files / URLs saved since the dialog opened
    var active   = [];  // XHRs in flight

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function size(bytes) {
        return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }
    function firstError(res) {
        if (res && res.errors) {
            for (var k in res.errors) {
                if (Object.prototype.hasOwnProperty.call(res.errors, k)) { return res.errors[k][0]; }
            }
        }
        return (res && res.message) || 'Upload failed.';
    }

    document.getElementById('edm-asset-upload-btn').addEventListener('click', function () {
        if (!modal) { modal = new bootstrap.Modal(modalEl); }
        listEl.innerHTML = '';
        urlEl.value = '';
        urlErr.hidden = true;
        added = 0;
        modal.show();
    });

    // Closing (Import, Cancel, X, Esc): stop unfinished uploads, refresh the table.
    modalEl.addEventListener('hidden.bs.modal', function () {
        active.forEach(function (x) { x.abort(); });
        active = [];
        [].forEach.call(listEl.querySelectorAll('img[data-object-url]'), function (img) { URL.revokeObjectURL(img.src); });
        if (added && window.edmCrudReload) { window.edmCrudReload(); }
    });
    doneBtn.addEventListener('click', function () { modal.hide(); });

    // ---- one row per file / URL ----

    function addRow(name, meta, thumbSrc, isObjectUrl) {
        var li = document.createElement('li');
        li.className = 'edm-up-item';
        li.innerHTML =
            '<span class="edm-up-thumb">' + (thumbSrc
                ? '<img src="' + esc(thumbSrc) + '" alt=""' + (isObjectUrl ? ' data-object-url="1"' : '') + '>'
                : '<i class="bi bi-image"></i>') + '</span>' +
            '<span class="edm-up-info">' +
                '<span class="edm-up-name">' + esc(name) + '</span>' +
                '<span class="edm-up-meta">' + esc(meta) + '</span>' +
            '</span>' +
            '<span class="edm-up-state"></span>' +
            '<span class="edm-up-bar"><span class="edm-up-bar-fill"></span></span>' +
            '<span class="edm-up-pct"></span>';
        var img = li.querySelector('.edm-up-thumb img');
        if (img) {
            img.addEventListener('error', function () { img.parentNode.innerHTML = '<i class="bi bi-image"></i>'; });
        }
        listEl.appendChild(li);
        return li;
    }
    function setProgress(li, pct) {
        li.querySelector('.edm-up-bar-fill').style.width = pct + '%';
        li.querySelector('.edm-up-pct').textContent = Math.round(pct) + '%';
    }
    function setDone(li, asset) {
        setProgress(li, 100);
        li.classList.add('is-done');
        li.setAttribute('data-id', asset.id);
        li.querySelector('.edm-up-name').textContent = asset.name;
        li.querySelector('.edm-up-state').innerHTML =
            '<button type="button" class="edm-up-icon-btn" data-rename title="Rename" aria-label="Rename"><i class="bi bi-pencil"></i></button>' +
            '<i class="bi bi-check-lg text-success" title="Added to Files"></i>';
    }

    // ---- rename a saved image ----

    listEl.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-rename]');
        if (btn) { startRename(btn.closest('.edm-up-item')); }
    });

    function startRename(li) {
        var nameEl = li.querySelector('.edm-up-name');
        if (li.querySelector('.edm-up-rename')) { return; }
        var old = nameEl.textContent;
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm edm-up-rename';
        input.maxLength = 255;
        input.value = old;
        input.setAttribute('aria-label', 'File name');
        nameEl.hidden = true;
        nameEl.parentNode.insertBefore(input, nameEl);
        input.focus();
        // Select the name without its extension, like a file manager.
        var dot = old.lastIndexOf('.');
        input.setSelectionRange(0, dot > 0 ? dot : old.length);

        var done = false;
        function close(save) {
            if (done) { return; }
            done = true;
            var name = input.value.trim();
            input.remove();
            nameEl.hidden = false;
            if (!save || name === '' || name === old) { return; }
            nameEl.textContent = name;
            li.querySelector('.edm-up-meta').textContent = 'Saving name...';
            fetch(API + '?action=assets_update', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ id: parseInt(li.getAttribute('data-id'), 10), name: name })
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) {
                        nameEl.textContent = old;
                        li.querySelector('.edm-up-meta').textContent = firstError(res);
                        return;
                    }
                    nameEl.textContent = res.data.name;
                    li.querySelector('.edm-up-meta').textContent = 'Renamed';
                    added++;
                })
                .catch(function () {
                    nameEl.textContent = old;
                    li.querySelector('.edm-up-meta').textContent = 'Could not reach the server - name not changed.';
                });
        }
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); close(true); }
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(false); }
        });
        input.addEventListener('blur', function () { close(true); });
    }
    function setFailed(li, msg) {
        li.classList.add('is-failed');
        li.querySelector('.edm-up-meta').textContent = msg;
        li.querySelector('.edm-up-pct').textContent = '';
        li.querySelector('.edm-up-state').innerHTML =
            '<button type="button" class="edm-up-icon-btn" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button>';
        li.querySelector('.edm-up-icon-btn').addEventListener('click', function () { li.remove(); });
    }

    // ---- files ----

    function upload(file) {
        var problem = TYPES.indexOf(file.type) === -1 ? 'Only JPG, PNG, GIF or WebP images can be uploaded.'
            : file.size > MAX_BYTES ? 'Larger than 5 MB.' : null;
        var thumb = problem ? null : URL.createObjectURL(file);
        var li = addRow(file.name, size(file.size), thumb, true);
        if (problem) { setFailed(li, problem); return; }

        var body = new FormData();
        body.append('file', file);
        var xhr = new XMLHttpRequest();
        active.push(xhr);
        li.querySelector('.edm-up-state').innerHTML =
            '<button type="button" class="edm-up-icon-btn is-cancel" title="Cancel upload" aria-label="Cancel upload"><i class="bi bi-x-lg"></i></button>';
        li.querySelector('.edm-up-icon-btn').addEventListener('click', function () { xhr.abort(); });
        setProgress(li, 0);

        function finish() { active = active.filter(function (x) { return x !== xhr; }); }
        xhr.upload.addEventListener('progress', function (e) {
            // Keep the last few percent for the server to store the file.
            if (e.lengthComputable) { setProgress(li, Math.min(95, e.loaded / e.total * 100)); }
        });
        xhr.addEventListener('load', function () {
            finish();
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) { res = null; }
            if (res && res.success) { added++; setDone(li, res.data); return; }
            setFailed(li, res ? firstError(res) : 'The server rejected the file (HTTP ' + xhr.status + ').');
        });
        xhr.addEventListener('error', function () { finish(); setFailed(li, 'Could not reach the server.'); });
        xhr.addEventListener('abort', function () { finish(); setFailed(li, 'Upload cancelled.'); });
        xhr.open('POST', API + '?action=assets_upload');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.send(body);
    }

    function addFiles(files) {
        [].forEach.call(files || [], upload);
        fileEl.value = '';
    }
    fileEl.addEventListener('change', function () { addFiles(fileEl.files); });
    ['dragenter', 'dragover'].forEach(function (ev) {
        dropEl.addEventListener(ev, function (e) { e.preventDefault(); dropEl.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        dropEl.addEventListener(ev, function (e) { e.preventDefault(); dropEl.classList.remove('is-over'); });
    });
    dropEl.addEventListener('drop', function (e) {
        if (e.dataTransfer) { addFiles(e.dataTransfer.files); }
    });

    // ---- URL ----

    function importUrl() {
        urlErr.hidden = true;
        var url = urlEl.value.trim();
        if (!/^https?:\/\/[^\s]+$/i.test(url)) {
            urlErr.textContent = 'Enter a full image address starting with http:// or https://.';
            urlErr.hidden = false;
            urlEl.focus();
            return;
        }
        var name = decodeURIComponent(url.split(/[?#]/)[0].split('/').pop() || '') || url;
        var li = addRow(name, url, url, false);
        setProgress(li, 60);
        urlBtn.disabled = true;
        fetch(API + '?action=assets_create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ name: name.slice(0, 255), url: url, type: 'image' })
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { setFailed(li, firstError(res)); return; }
                added++;
                setDone(li, res.data);
                urlEl.value = '';
            })
            .catch(function () { setFailed(li, 'Could not reach the server.'); })
            .then(function () { urlBtn.disabled = false; });
    }
    urlBtn.addEventListener('click', importUrl);
    urlEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); importUrl(); }
    });
})();
