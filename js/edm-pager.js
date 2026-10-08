/**
 * Client-side pager for a bespoke listing table, in atem's view.php style
 * (same markup and classes as js/edm-crud.js / settings/senders.js:
 * .edm-pager-left / .edm-perpage-select / .edm-pager-info / .edm-pager-bar /
 * .edm-pager-btn / .edm-pager-gap). 30 rows per page by default, 10/30/50/100.
 *
 *   var pager = edmPager(document.getElementById('my-pager'), function (pageRows, startIdx) {
 *       // paint pageRows; row number = startIdx + i + 1 (continuous across pages)
 *   });
 *   pager.set(rows);      // new data (e.g. after a filter): back to page 1
 */
(function () {
    var OPTIONS = [10, 30, 50, 100];

    window.edmPager = function (el, renderRows, opts) {
        opts = opts || {};
        var rows = [];
        var page = 1;
        var perPage = opts.perPage || 30;

        function pageBtn(p) {
            return '<button type="button" class="edm-pager-btn' + (p === page ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
        }

        function renderPager(total, pages, startIdx, shown) {
            if (total === 0) { el.innerHTML = ''; return; }
            var sel = '<select class="edm-perpage-select">' + OPTIONS.map(function (o) {
                return '<option value="' + o + '"' + (perPage === o ? ' selected' : '') + '>' + o + '</option>';
            }).join('') + '</select>';
            var info = '<span class="edm-pager-info">Showing ' + (startIdx + 1) + ' to ' + (startIdx + shown) + ' of ' + total + ' entries</span>';
            var btns = '<button type="button" class="edm-pager-btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>Previous</button>';
            var from = Math.max(1, page - 2), to = Math.min(pages, page + 2);
            if (from > 1) { btns += pageBtn(1) + (from > 2 ? '<span class="edm-pager-gap">...</span>' : ''); }
            for (var p = from; p <= to; p++) { btns += pageBtn(p); }
            if (to < pages) { btns += (to < pages - 1 ? '<span class="edm-pager-gap">...</span>' : '') + pageBtn(pages); }
            btns += '<button type="button" class="edm-pager-btn" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>Next</button>';

            el.innerHTML = '<div class="edm-pager-left">Show ' + sel + ' entries</div>' +
                '<div class="d-flex align-items-center gap-2">' + info + '<div class="edm-pager-bar">' + btns + '</div></div>';

            [].slice.call(el.querySelectorAll('.edm-pager-btn[data-page]')).forEach(function (b) {
                b.addEventListener('click', function () {
                    var p2 = parseInt(b.getAttribute('data-page'), 10);
                    if (p2 >= 1 && p2 <= pages && p2 !== page) { page = p2; render(); }
                });
            });
            el.querySelector('.edm-perpage-select').addEventListener('change', function () {
                perPage = parseInt(this.value, 10);
                page = 1;
                render();
            });
        }

        function render() {
            var total = rows.length;
            var pages = Math.max(1, Math.ceil(total / perPage));
            if (page > pages) { page = pages; }
            var startIdx = (page - 1) * perPage;
            var pageRows = rows.slice(startIdx, startIdx + perPage);
            renderRows(pageRows, startIdx);
            renderPager(total, pages, startIdx, pageRows.length);
        }

        return {
            set: function (list) { rows = list || []; page = 1; render(); },
            render: render
        };
    };
})();
