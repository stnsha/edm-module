/**
 * Message performance renderer (GetResponse "General performance" layout),
 * shared by the Dashboard (dashboard/api.php?action=performance) and the
 * Newsletter report (campaigns_report `performance`), both shaped by
 * Edm\Services\PerformanceReport.
 *
 *   edmPerformance.render(el, data, opts)  paint every section into el
 *       opts.messages     true: the "Messages sent" table (Dashboard)
 *       opts.trackOpens   false: the open tile and series say tracking is off
 *       opts.trackClicks  false: same for clicks
 *   edmPerformance.csv(data, filename)     download the figures as one CSV
 *   edmPerformance.print()                 browser print dialog (Save as PDF)
 *
 * Charts use Chart.js (window.Chart, loaded by the page from the CDN); the
 * table pages with js/edm-pager.js when the page loads it. Rates are worked
 * out here from the counts, to 2 decimals.
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';

    // Series colours (PDF palette): one hue per measure on every chart.
    var C = {
        opened: '#26b9a9', clicked: '#11b0e8', delivered: '#8fd14f', sent: '#11b0e8',
        bounced: '#f39c4a', soft: '#c8400f', hard: '#7b6cf6', unsubscribed: '#7b6cf6', complained: '#7b6cf6'
    };

    // The six tiles: key, title, rate(stats), lower is better, detail rows.
    var TILES = [
        { key: 'delivery', title: 'Delivery rate', part: 'delivered', whole: 'sent', rows: [['Delivered', 'delivered'], ['Sent', 'sent']] },
        { key: 'open', title: 'Open rate', part: 'opened', whole: 'delivered', rows: [['Opened', 'opened']], track: 'trackOpens' },
        { key: 'click', title: 'Click rate', part: 'clicked', whole: 'delivered', rows: [['Clicked', 'clicked'], ['CTOR', 'ctor']], track: 'trackClicks' },
        { key: 'bounce', title: 'Bounce rate', part: 'bounced', whole: 'sent', low: true, rows: [['Bounced', 'bounced']] },
        { key: 'complaint', title: 'Spam complaint rate', part: 'complained', whole: 'delivered', low: true, rows: [['Marked as spam', 'complained']] },
        { key: 'unsubscribe', title: 'Unsubscribe rate', part: 'unsubscribed', whole: 'delivered', low: true, rows: [['Unsubscribed', 'unsubscribed']] }
    ];

    // Unsubscribe reason (Unsubscribe::REASONS key, none = no reason given)
    // -> ring colour, as the GetResponse sample.
    var REASON_COLORS = {
        other: '#4c5fff', too_many_general: '#11b0e8', did_not_sign_up: '#8fbf1f', too_many_from_you: '#e83e8c',
        not_relevant: '#b5487f', no_longer_client: '#198754', none: '#9aa5b1'
    };

    var charts = [];

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function num(v) { return Number(v || 0).toLocaleString(); }

    // part of whole as a percentage, 2 decimals (0 when whole is 0).
    function rate(part, whole) { return whole ? Math.round(part * 10000 / whole) / 100 : 0; }

    function pct(v) { return v.toFixed(2) + '%'; }

    function tileRate(t, s) { return rate(s[t.part] || 0, s[t.whole] || 0); }

    function rowValue(key, s) {
        return key === 'ctor' ? pct(rate(s.clicked || 0, s.opened || 0)) : num(s[key]);
    }

    // Change against the previous period, relative to its rate: green when it
    // moved the right way (down for bounces, complaints, unsubscribes).
    function changeBadge(t, cur, prev, prevPeriod) {
        var before = tileRate(t, prev);
        if (!before || !(prev[t.whole] > 0)) { return ''; }
        var now = cur;
        var change = Math.round(Math.abs(now - before) * 100 / before);
        var up = now > before;
        var good = now === before ? null : (up !== !!t.low);
        var cls = good === null ? 'is-flat' : (good ? 'is-good' : 'is-bad');
        var icon = now === before ? 'bi-arrow-right' : (up ? 'bi-arrow-up-right' : 'bi-arrow-down-right');
        return '<span class="edm-perf-change ' + cls + '" title="' + esc('Previous period (' + prevPeriod + '): ' + pct(before)) + '">' +
            '<i class="bi ' + icon + '"></i>' + change + '%</span>';
    }

    function tilesHtml(data, opts) {
        var s = data.summary || {};
        var prev = data.previous ? data.previous.stats || {} : null;
        return TILES.map(function (t) {
            var off = t.track && opts[t.track] === false;
            var r = tileRate(t, s);
            return '<div class="edm-perf-tile">' +
                '<div class="edm-perf-tile-title">' + esc(t.title) + '</div>' +
                '<div class="edm-perf-tile-head"><span class="edm-perf-tile-value">' + (off ? 'Off' : pct(r)) + '</span>' +
                (prev && !off ? changeBadge(t, r, prev, data.previous.period) : '') + '</div>' +
                (off ? '<div class="edm-perf-tile-row"><span>Tracking is off</span><b>-</b></div>' :
                    t.rows.map(function (row) {
                        return '<div class="edm-perf-tile-row"><span>' + esc(row[0]) + '</span><b>' + rowValue(row[1], s) + '</b></div>';
                    }).join('')) +
                '</div>';
        }).join('');
    }

    function section(id, title, body, sub) {
        return '<section class="edm-perf-section" id="' + id + '">' +
            '<h3 class="edm-perf-heading">' + esc(title) + '</h3>' +
            (sub ? '<p class="edm-perf-sub">' + esc(sub) + '</p>' : '') + body + '</section>';
    }

    function chartBox(id) { return '<div class="edm-perf-chart"><canvas id="' + id + '"></canvas></div>'; }

    // A smooth line per series; `hidden` ones start switched off in the legend
    // and sit on their own right-hand axis (Delivered / Sent dwarf the rest).
    function lineChart(id, labels, series) {
        var ctx = document.getElementById(id);
        if (!ctx || !window.Chart) { return; }
        charts.push(new window.Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: series.map(function (x) {
                    return {
                        label: x.label, data: x.data, borderColor: x.color, backgroundColor: x.color,
                        tension: 0.4, borderWidth: 2, pointRadius: labels.length > 40 ? 0 : 2, pointHoverRadius: 4,
                        hidden: !!x.hidden, yAxisID: x.hidden ? 'y1' : 'y'
                    };
                })
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 16 } } },
                scales: {
                    x: { grid: { color: '#e6e9ee' }, ticks: { color: '#7a8794', maxRotation: 0, autoSkip: true } },
                    y: { beginAtZero: true, grid: { color: '#e6e9ee' }, ticks: { color: '#7a8794', precision: 0 } },
                    y1: { display: 'auto', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { color: '#7a8794', precision: 0 } }
                }
            }
        }));
    }

    function barChart(id, domains, opts) {
        var ctx = document.getElementById(id);
        if (!ctx || !window.Chart) { return; }
        var sets = [];
        if (opts.trackOpens !== false) { sets.push(['Opens', 'opened', C.opened]); }
        if (opts.trackClicks !== false) { sets.push(['Clicks', 'clicked', C.clicked]); }
        sets.push(['Bounces', 'bounced', C.hard]);
        charts.push(new window.Chart(ctx, {
            type: 'bar',
            data: {
                labels: domains.map(function (d) { return d.domain; }),
                datasets: sets.map(function (x) {
                    return {
                        label: x[0], backgroundColor: x[2], borderRadius: 4, maxBarThickness: 10,
                        data: domains.map(function (d) { return d[x[1]]; })
                    };
                })
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 16 } },
                    tooltip: { callbacks: { afterTitle: function (items) { return num(domains[items[0].dataIndex].sent) + ' sent'; } } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#7a8794', maxRotation: 0, autoSkip: false,
                        callback: function (v) { var l = this.getLabelForValue(v); return l.length > 12 ? l.slice(0, 11) + '...' : l; } } },
                    y: { beginAtZero: true, grid: { color: '#e6e9ee' }, ticks: { color: '#7a8794', precision: 0 } }
                }
            }
        }));
    }

    // Unsubscribe reasons: a ring and a legend with each count and share.
    function reasonsHtml(reasons) {
        var total = reasons.reduce(function (t, r) { return t + r.count; }, 0);
        if (!total) { return '<p class="edm-perf-sub">Nobody unsubscribed in this period.</p>'; }
        return '<div class="edm-perf-reasons"><div class="edm-perf-donut"><canvas id="edm-perf-c-reasons"></canvas></div>' +
            '<ul class="edm-perf-reason-list">' + reasons.map(function (r) {
                return '<li><i style="background:' + (REASON_COLORS[r.key] || '#6c757d') + '"></i>' +
                    '<span>' + esc(r.label) + '</span><b>' + num(r.count) + '</b>' +
                    '<small>' + pct(rate(r.count, total)) + '</small></li>';
            }).join('') + '</ul></div>';
    }

    function reasonsChart(reasons) {
        var ctx = document.getElementById('edm-perf-c-reasons');
        if (!ctx || !window.Chart) { return; }
        charts.push(new window.Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: reasons.map(function (r) { return r.label; }),
                datasets: [{
                    data: reasons.map(function (r) { return r.count; }),
                    backgroundColor: reasons.map(function (r) { return REASON_COLORS[r.key] || '#6c757d'; }),
                    borderWidth: 0
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '82%', plugins: { legend: { display: false } } }
        }));
    }

    // "Messages sent": rate with its count beside it, one line.
    function rateCell(part, whole) {
        return '<td class="edm-perf-num"><span class="edm-perf-rate">' + pct(rate(part, whole)) + '</span>' +
            '<span class="edm-perf-count">' + num(part) + '</span></td>';
    }

    function messagesTable(el, rows) {
        var body = el.querySelector('#edm-perf-msg-rows');
        var pagerEl = el.querySelector('#edm-perf-msg-pager');
        function paint(pageRows, startIdx) {
            if (!pageRows.length) {
                body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No newsletter was sent in this period.</td></tr>';
                return;
            }
            body.innerHTML = pageRows.map(function (m, i) {
                var name = m.deleted ? esc(m.name)
                    : '<a href="' + BASE + 'dashboard/index.php?newsletter=' + m.id + '">' + esc(m.name) + '</a>';
                return '<tr>' +
                    '<td class="edm-perf-idx">' + (startIdx + i + 1) + '</td>' +
                    '<td><div class="edm-perf-msg-name">' + name + '</div>' +
                    (m.sender ? '<div class="edm-perf-msg-meta">' + esc(m.sender) + '</div>' : '') + '</td>' +
                    '<td class="edm-perf-num edm-perf-msg-when">' + esc(m.sent_on) + '</td>' +
                    '<td class="edm-perf-num">' + num(m.delivered) + '</td>' +
                    rateCell(m.opened, m.delivered) + rateCell(m.clicked, m.delivered) + rateCell(m.bounced, m.sent) +
                    '</tr>';
            }).join('');
        }
        if (window.edmPager) {
            window.edmPager(pagerEl, paint).set(rows);
        } else {
            paint(rows, 0);
        }
    }

    function render(el, data, opts) {
        opts = opts || {};
        charts.forEach(function (c) { c.destroy(); });
        charts = [];

        var ser = data.series || { labels: [], data: {} };
        var d = ser.data || {};
        var s = data.summary || {};
        var html = section('edm-perf-general', 'General performance',
            (s.sent ? '' : '<div class="edm-rp-notice"><i class="bi bi-info-circle"></i><span>No emails were sent in this period.</span></div>') +
            '<div class="edm-perf-tiles">' + tilesHtml(data, opts) + '</div>',
            'The results show data from ' + data.period + '.' + (data.previous ? ' Changes compare with ' + data.previous.period + '.' : ''));

        html += section('edm-perf-opens', 'Opens and clicks over time', chartBox('edm-perf-c-opens'));
        if (opts.messages) {
            html += section('edm-perf-messages', 'Messages sent',
                '<div class="table-responsive"><table class="table align-middle edm-view-tbl edm-perf-msg-tbl"><thead><tr>' +
                '<th style="width:2.5rem;">#</th><th>Name</th><th class="edm-perf-num">Sent on</th><th class="edm-perf-num">Delivered</th>' +
                '<th class="edm-perf-num">Open rate</th><th class="edm-perf-num">Click rate</th><th class="edm-perf-num">Bounce rate</th>' +
                '</tr></thead><tbody id="edm-perf-msg-rows"></tbody></table></div><div class="edm-pager" id="edm-perf-msg-pager"></div>');
        }
        html += section('edm-perf-bounces', 'Bounces over time', chartBox('edm-perf-c-bounces'));
        html += section('edm-perf-unsubs', 'Unsubscribes over time', chartBox('edm-perf-c-unsubs'));
        html += section('edm-perf-reasons', 'Unsubscribe reasons', reasonsHtml(data.reasons || []),
            'From the optional question on the unsubscribe page. One-click unsubscribes from the mail app give no reason.');
        html += section('edm-perf-spam', 'Spam complaints over time', chartBox('edm-perf-c-spam'));
        html += section('edm-perf-domains', 'Top email domains',
            (data.domains || []).length ? chartBox('edm-perf-c-domains') : '<p class="edm-perf-sub">No emails were sent in this period.</p>');
        el.innerHTML = html;

        var opensSeries = [];
        if (opts.trackOpens !== false) { opensSeries.push({ label: 'Opens', data: d.opened, color: C.opened }); }
        if (opts.trackClicks !== false) { opensSeries.push({ label: 'Clicks', data: d.clicked, color: C.clicked }); }
        opensSeries.push({ label: 'Delivered', data: d.delivered, color: C.delivered, hidden: true });
        lineChart('edm-perf-c-opens', ser.labels, opensSeries);
        lineChart('edm-perf-c-bounces', ser.labels, [
            { label: 'All bounces', data: d.bounced, color: C.bounced },
            { label: 'Soft bounces', data: d.soft_bounced, color: C.soft },
            { label: 'Hard bounces', data: d.hard_bounced, color: C.hard },
            { label: 'Sent', data: d.sent, color: C.sent, hidden: true }
        ]);
        lineChart('edm-perf-c-unsubs', ser.labels, [
            { label: 'Unsubscribed', data: d.unsubscribed, color: C.unsubscribed },
            { label: 'Delivered', data: d.delivered, color: C.delivered, hidden: true }
        ]);
        lineChart('edm-perf-c-spam', ser.labels, [
            { label: 'Complaints', data: d.complained, color: C.complained },
            { label: 'Delivered', data: d.delivered, color: C.delivered, hidden: true }
        ]);
        reasonsChart(data.reasons || []);
        if ((data.domains || []).length) { barChart('edm-perf-c-domains', data.domains, opts); }
        if (opts.messages) { messagesTable(el, data.messages || []); }
    }

    // ---- Export ----

    function csvCell(v) {
        var s = String(v == null ? '' : v);
        // Keep spreadsheet apps from running a value as a formula.
        if (/^[=+\-@\t\r]/.test(s)) { s = "'" + s; }
        return '"' + s.replace(/"/g, '""') + '"';
    }

    function csv(data, filename) {
        var s = data.summary || {};
        var prev = data.previous ? data.previous.stats || {} : null;
        var lines = [];
        function line(cells) { lines.push(cells.map(csvCell).join(',')); }

        line(['Message performance', data.period]);
        if (prev) { line(['Previous period', data.previous.period]); }
        line([]);
        line(['General performance', 'Rate', 'Count'].concat(prev ? ['Previous rate', 'Previous count'] : []));
        TILES.forEach(function (t) {
            line([t.title, pct(tileRate(t, s)), s[t.part] || 0].concat(prev ? [pct(tileRate(t, prev)), prev[t.part] || 0] : []));
        });
        line(['Sent', '', s.sent || 0].concat(prev ? ['', prev.sent || 0] : []));
        line(['CTOR', pct(rate(s.clicked || 0, s.opened || 0)), ''].concat(prev ? [pct(rate(prev.clicked || 0, prev.opened || 0)), ''] : []));

        var ser = data.series || { labels: [], keys: [], data: {} };
        var cols = [['Sent', 'sent'], ['Delivered', 'delivered'], ['Opens', 'opened'], ['Clicks', 'clicked'], ['All bounces', 'bounced'],
            ['Soft bounces', 'soft_bounced'], ['Hard bounces', 'hard_bounced'], ['Complaints', 'complained'], ['Unsubscribed', 'unsubscribed']];
        line([]);
        line(['Over time (' + ser.unit + ')'].concat(cols.map(function (c) { return c[0]; })));
        (ser.keys || []).forEach(function (k, i) {
            line([k].concat(cols.map(function (c) { return (ser.data[c[1]] || [])[i] || 0; })));
        });

        if (data.messages) {
            line([]);
            line(['Messages sent', 'Sender', 'Sent on', 'Sent', 'Delivered', 'Opened', 'Open rate', 'Clicked', 'Click rate',
                'Bounced', 'Bounce rate', 'Complaints', 'Unsubscribed']);
            data.messages.forEach(function (m) {
                line([m.name, m.sender, m.sent_on, m.sent, m.delivered, m.opened, pct(rate(m.opened, m.delivered)), m.clicked,
                    pct(rate(m.clicked, m.delivered)), m.bounced, pct(rate(m.bounced, m.sent)), m.complained, m.unsubscribed]);
            });
        }

        line([]);
        line(['Unsubscribe reasons', 'Unsubscribed']);
        (data.reasons || []).forEach(function (r) { line([r.label, r.count]); });

        line([]);
        line(['Top email domains', 'Sent', 'Opens', 'Clicks', 'Bounces']);
        (data.domains || []).forEach(function (dm) { line([dm.domain, dm.sent, dm.opened, dm.clicked, dm.bounced]); });

        var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 0);
    }

    // Charts redraw at the printed width, and back after.
    function resizeAll() { charts.forEach(function (c) { c.resize(); }); }
    window.addEventListener('beforeprint', resizeAll);
    window.addEventListener('afterprint', resizeAll);

    window.edmPerformance = {
        render: render,
        csv: csv,
        print: function () { window.print(); }
    };
})();
