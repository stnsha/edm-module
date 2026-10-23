/**
 * Newsletter recipients picker (Email creator, Recipients step): any number of
 * segments and individual contacts to send to, and any number to leave out.
 *
 *   var picker = edmAudiencePicker({
 *       root: element,               // filled with the picker markup
 *       segments: [{ id, label }],   // every saved segment
 *       onChange: function () {}     // called after every add / remove
 *   });
 *   picker.set(audience, names);     // audience { include, exclude: { segments: [id], contacts: [id] } },
 *                                    // names (Campaign::withDetails audience_names) for the contact chips
 *   picker.get();                    // -> audience
 *   picker.disable(true);
 *
 * Contacts are found with audience/api.php contacts_search; the line under
 * the picker counts the recipients with audience_count (CampaignRecipients).
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var API = BASE + 'audience/api.php';

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fmt(n) { return Number(n || 0).toLocaleString('en-US'); }

    window.edmAudiencePicker = function (o) {
        var state = { include: { segments: [], contacts: [] }, exclude: { segments: [], contacts: [] } };
        var contactLabels = {};   // contact id -> email
        var segLabels = {};
        (o.segments || []).forEach(function (s) { segLabels[s.id] = s.label; });
        var disabled = false;
        var countSeq = 0;
        var countTimer = null;

        function side(key, title, hint) {
            return '<div class="edm-aud-side" data-side="' + key + '">' +
                '<div class="edm-aud-side-head"><span class="edm-aud-side-title">' + title + '</span>' +
                    '<span class="edm-aud-side-hint">' + hint + '</span></div>' +
                '<div class="edm-aud-chips" aria-live="polite"></div>' +
                '<div class="row g-2">' +
                    '<div class="col-md-6">' +
                        '<select class="form-select form-select-sm edm-aud-seg" aria-label="Add a segment">' +
                            '<option value="">+ Add a segment...</option>' +
                            (o.segments || []).map(function (s) {
                                return '<option value="' + s.id + '">' + esc(s.label) + '</option>';
                            }).join('') +
                        '</select>' +
                    '</div>' +
                    '<div class="col-md-6 position-relative">' +
                        '<input type="search" class="form-control form-control-sm edm-aud-search" placeholder="+ Add a contact (search email or name)" autocomplete="off" aria-label="Search contacts">' +
                        '<div class="dropdown-menu edm-aud-results"></div>' +
                    '</div>' +
                '</div>' +
            '</div>';
        }

        o.root.innerHTML =
            side('include', 'Send to', 'Optional - add segments or contacts to send to only them.') +
            side('exclude', 'Don’t send to', 'Optional - removed from the recipients above.') +
            '<div class="edm-aud-count" aria-live="polite"></div>';

        function sideEl(key) { return o.root.querySelector('.edm-aud-side[data-side="' + key + '"]'); }

        function render() {
            ['include', 'exclude'].forEach(function (key) {
                var part = state[key];
                var chips = part.segments.map(function (id) {
                    return chip(key, 'segments', id, 'bi-funnel', segLabels[id] || 'Deleted segment #' + id);
                }).concat(part.contacts.map(function (id) {
                    return chip(key, 'contacts', id, 'bi-person', contactLabels[id] || 'Contact #' + id);
                }));
                var box = sideEl(key).querySelector('.edm-aud-chips');
                box.innerHTML = chips.length ? chips.join('')
                    : (key === 'include'
                        // Nothing included = every subscribed contact (CampaignRecipients).
                        ? '<span class="edm-aud-default"><i class="bi bi-people-fill"></i>' +
                          '<span><b>By default, this newsletter is sent to all subscribed contacts.</b> ' +
                          'Add a segment or contact below to send to only them.</span></span>'
                        : '<span class="edm-aud-empty">Nobody excluded</span>');
                // A segment already chosen on either side is not offered again.
                [].forEach.call(sideEl(key).querySelectorAll('.edm-aud-seg option'), function (opt) {
                    if (!opt.value) { return; }
                    var id = parseInt(opt.value, 10);
                    opt.hidden = opt.disabled = state.include.segments.indexOf(id) !== -1 || state.exclude.segments.indexOf(id) !== -1;
                });
                sideEl(key).querySelectorAll('select, input').forEach(function (el) { el.disabled = disabled; });
            });
        }
        function chip(key, kind, id, icon, label) {
            return '<span class="edm-aud-chip edm-aud-chip--' + key + '">' +
                '<i class="bi ' + icon + '"></i>' + esc(label) +
                (disabled ? '' : '<button type="button" class="edm-aud-chip-x" data-side="' + key + '" data-kind="' + kind + '" data-id="' + id + '" aria-label="Remove ' + esc(label) + '">&times;</button>') +
            '</span>';
        }

        function changed() {
            render();
            scheduleCount();
            if (o.onChange) { o.onChange(); }
        }
        function add(key, kind, id) {
            var other = key === 'include' ? 'exclude' : 'include';
            // One place per item: adding to one side removes it from the other.
            state[other][kind] = state[other][kind].filter(function (x) { return x !== id; });
            if (state[key][kind].indexOf(id) === -1) { state[key][kind].push(id); }
            changed();
        }

        o.root.addEventListener('click', function (e) {
            var x = e.target.closest('.edm-aud-chip-x');
            if (x) {
                var key = x.getAttribute('data-side');
                var kind = x.getAttribute('data-kind');
                var id = parseInt(x.getAttribute('data-id'), 10);
                state[key][kind] = state[key][kind].filter(function (v) { return v !== id; });
                changed();
                return;
            }
            var pick = e.target.closest('.edm-aud-result');
            if (pick) {
                var sideKey = pick.closest('.edm-aud-side').getAttribute('data-side');
                var cid = parseInt(pick.getAttribute('data-id'), 10);
                contactLabels[cid] = pick.getAttribute('data-email');
                var input = pick.closest('.edm-aud-side').querySelector('.edm-aud-search');
                input.value = '';
                hideResults(input);
                add(sideKey, 'contacts', cid);
                input.focus();
            }
        });
        o.root.addEventListener('change', function (e) {
            if (!e.target.classList.contains('edm-aud-seg') || !e.target.value) { return; }
            var key = e.target.closest('.edm-aud-side').getAttribute('data-side');
            var id = parseInt(e.target.value, 10);
            e.target.value = '';
            add(key, 'segments', id);
        });

        // Contact search (debounced), results in a dropdown under the box.
        var searchTimers = {};
        function hideResults(input) {
            var menu = input.parentNode.querySelector('.edm-aud-results');
            menu.classList.remove('show');
            menu.innerHTML = '';
        }
        o.root.addEventListener('input', function (e) {
            if (!e.target.classList.contains('edm-aud-search')) { return; }
            var input = e.target;
            var key = input.closest('.edm-aud-side').getAttribute('data-side');
            clearTimeout(searchTimers[key]);
            var q = input.value.trim();
            if (q.length < 2) { hideResults(input); return; }
            searchTimers[key] = setTimeout(function () {
                fetch(API + '?action=contacts_search&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (input.value.trim() !== q) { return; }
                        var menu = input.parentNode.querySelector('.edm-aud-results');
                        var rows = (res.success && res.data) || [];
                        menu.innerHTML = rows.length ? rows.map(function (c) {
                            var taken = state[key].contacts.indexOf(c.id) !== -1;
                            return '<button type="button" class="dropdown-item edm-aud-result"' + (taken ? ' disabled' : '') +
                                ' data-id="' + c.id + '" data-email="' + esc(c.email) + '">' +
                                '<span class="edm-aud-result-email">' + esc(c.email) + '</span>' +
                                (c.name ? '<span class="edm-aud-result-name">' + esc(c.name) + '</span>' : '') +
                                (c.status !== 1 ? '<span class="edm-pill edm-pill-secondary ms-1">' + (c.status === 2 ? 'Unsubscribed' : 'Bounced') + '</span>' : '') +
                            '</button>';
                        }).join('') : '<span class="dropdown-item-text text-muted small">No contact matches "' + esc(q) + '".</span>';
                        menu.classList.add('show');
                    })
                    .catch(function () { /* the search is best-effort */ });
            }, 250);
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest('.edm-aud-search, .edm-aud-results')) { return; }
            o.root.querySelectorAll('.edm-aud-search').forEach(hideResults);
        });

        // Recipient count (CampaignRecipients), debounced.
        function scheduleCount() {
            clearTimeout(countTimer);
            countTimer = setTimeout(count, 250);
        }
        function count() {
            var box = o.root.querySelector('.edm-aud-count');
            var mine = ++countSeq;
            box.className = 'edm-aud-count';
            box.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Counting recipients...';
            fetch(API + '?action=audience_count', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ audience: state })
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (mine !== countSeq) { return; }
                    if (!res.success) {
                        box.className = 'edm-aud-count is-warn';
                        box.textContent = res.message || 'Could not count recipients.';
                        return;
                    }
                    var d = res.data;
                    box.className = 'edm-aud-count' + (d.matched ? ' is-ok' : ' is-warn');
                    box.innerHTML = '<i class="bi bi-people me-1"></i><strong>' + fmt(d.matched) + '</strong> of ' + fmt(d.total) +
                        ' subscribed contacts will receive it' +
                        (d.suppressed ? ' (' + fmt(d.suppressed) + ' on the exclusion list are skipped)' : '') + '.';
                    picker.lastCount = d;
                })
                .catch(function () {
                    if (mine === countSeq) { box.className = 'edm-aud-count is-warn'; box.textContent = 'Could not count recipients.'; }
                });
        }

        var picker = {
            lastCount: null,
            get: function () {
                return JSON.parse(JSON.stringify(state));
            },
            set: function (audience, names) {
                ['include', 'exclude'].forEach(function (key) {
                    var a = (audience && audience[key]) || {};
                    state[key].segments = (a.segments || []).map(Number);
                    state[key].contacts = (a.contacts || []).map(Number);
                    var labels = (names && names[key] && names[key].contacts) || [];
                    state[key].contacts.forEach(function (id, i) { if (labels[i]) { contactLabels[id] = labels[i]; } });
                });
                render();
                count();
            },
            disable: function (v) {
                disabled = !!v;
                render();
            },
            // One line for the Review summary.
            describe: function () {
                function part(p) {
                    var bits = p.segments.map(function (id) { return segLabels[id] || 'Deleted segment #' + id; });
                    if (p.contacts.length) {
                        bits.push(p.contacts.length > 3 ? p.contacts.length + ' contacts'
                            : p.contacts.map(function (id) { return contactLabels[id] || 'Contact #' + id; }).join(', '));
                    }
                    return bits.join(', ');
                }
                var inc = state.include.segments.length || state.include.contacts.length ? part(state.include) : 'All subscribed contacts';
                var exc = part(state.exclude);
                return inc + (exc ? ' - excluding ' + exc : '');
            }
        };
        render();
        return picker;
    };
})();
