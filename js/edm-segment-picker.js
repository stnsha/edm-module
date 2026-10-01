/**
 * Campaign "Segment" select, shared by the New campaign form
 * (campaign/index.php) and the Email creator settings (email-builder/).
 *
 *   edmSegmentPicker({
 *       list: <select> recipient list (value 'all' = all lists),
 *       segment: <select> segment (first option value '' = whole list),
 *       hint: element for the audience line,
 *       segmentLists: { segmentId: listId|null }  // null = any list
 *   }) -> { sync() }
 *
 * Segments tied to another list are hidden (and cleared when selected), and
 * the hint shows how many subscribed contacts the campaign would reach,
 * from audience/api.php segments_count - before suppressions and send limits.
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    // Recipient list value for "All lists" (Campaign::ALL_LISTS): every list,
    // each address once; only segments for all lists fit.
    var ALL_LISTS = 'all';

    function fmt(n) { return Number(n || 0).toLocaleString('en-US'); }

    window.edmSegmentPicker = function (o) {
        var seq = 0;

        function refresh() {
            var mine = ++seq;
            if (!o.list.value) {
                o.hint.textContent = 'Choose a recipient list to see who receives it.';
                return;
            }
            var all = o.list.value === ALL_LISTS;
            var scope = all ? 'across all lists' : 'on this list';
            o.hint.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Counting contacts...';
            fetch(BASE + 'audience/api.php?action=segments_count', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    list_id: all ? null : parseInt(o.list.value, 10),
                    segment_id: o.segment.value ? parseInt(o.segment.value, 10) : null
                })
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (mine !== seq) { return; }
                    if (!res.success) { o.hint.textContent = res.message || 'Could not count contacts.'; return; }
                    var d = res.data;
                    o.hint.innerHTML = '<i class="bi bi-people me-1"></i>' + (o.segment.value
                        ? 'Sends to <strong>' + fmt(d.matched) + '</strong> of ' + fmt(d.total) + ' subscribed contacts ' + scope + '.'
                        : 'Sends to all <strong>' + fmt(d.total) + '</strong> subscribed contacts ' + scope + '.') +
                        ' Suppressed addresses and send limits can lower this.';
                })
                .catch(function () {
                    if (mine === seq) { o.hint.textContent = 'Could not count contacts.'; }
                });
        }

        function sync() {
            var list = o.list.value;
            [].forEach.call(o.segment.options, function (opt) {
                if (!opt.value) { return; }
                var only = o.segmentLists[opt.value];
                var fits = only === null || only === undefined || String(only) === String(list);
                opt.hidden = !fits;
                opt.disabled = !fits;
            });
            var sel = o.segment.options[o.segment.selectedIndex];
            if (sel && sel.disabled) { o.segment.value = ''; }
            refresh();
        }

        o.list.addEventListener('change', sync);
        o.segment.addEventListener('change', refresh);
        sync();

        return { sync: sync };
    };
})();
