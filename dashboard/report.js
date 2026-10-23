/**
 * Newsletter report (dashboard/index.php?newsletter=<id>): figures and
 * recipients of one newsletter from newsletters/api.php campaigns_report. The performance sections (tiles, charts,
 * domains) are painted by js/edm-performance.js from `performance`; the
 * recipient list is filtered, searched, paged (js/edm-pager.js) and exported
 * to CSV in the browser. Export: PDF (print), performance CSV, recipients CSV.
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var ID = parseInt(document.getElementById('edm-perf').getAttribute('data-newsletter'), 10) || 0;

    var RESULT_PILLS = {
        sent:         { cls: 'edm-pill-secondary', label: 'Sent' },
        delivered:    { cls: 'edm-pill-success',   label: 'Delivered' },
        opened:       { cls: 'edm-pill-info',      label: 'Opened' },
        clicked:      { cls: 'edm-pill-primary',   label: 'Clicked' },
        unsubscribed: { cls: 'edm-pill-warning',   label: 'Unsubscribed' },
        bounced:      { cls: 'edm-pill-danger',    label: 'Bounced' },
        complained:   { cls: 'edm-pill-danger',    label: 'Complained' },
        failed:       { cls: 'edm-pill-danger',    label: 'Failed' },
        skipped:      { cls: 'edm-pill-dark',      label: 'Skipped' }
    };

    // Recipients-by-result split: furthest result -> bar colour, in the order
    // the segments are stacked (best outcome first). Same hues as the pills.
    var SPLIT = [
        ['clicked',      '#0d6efd'],
        ['opened',       '#0aa2c0'],
        ['delivered',    '#198754'],
        ['sent',         '#adb5bd'],
        ['unsubscribed', '#ffc107'],
        ['bounced',      '#dc3545'],
        ['complained',   '#a52834'],
        ['failed',       '#6f1a22'],
        ['skipped',      '#343a40']
    ];

    // Filter value -> test. Delivered / opened / clicked include recipients
    // who went further (a click is also an open and a delivery).
    var FILTERS = {
        delivered:    function (r) { return !!r.delivered_at; },
        opened:       function (r) { return !!r.opened_at; },
        clicked:      function (r) { return !!r.clicked_at; },
        not_opened:   function (r) { return !!r.delivered_at && !r.opened_at && !r.bounced_at; },
        sent:         function (r) { return r.result === 'sent'; },
        bounced:      function (r) { return r.result === 'bounced'; },
        complained:   function (r) { return r.result === 'complained'; },
        unsubscribed: function (r) { return !!r.unsubscribed_at; },
        failed:       function (r) { return r.result === 'failed'; },
        skipped:      function (r) { return r.result === 'skipped'; }
    };

    var rowsEl   = document.getElementById('edm-rp-rows');
    var filterEl = document.getElementById('edm-rp-filter');
    var searchEl = document.getElementById('edm-rp-search');
    var all = [];
    var shown = [];
    var campaignName = '';
    var report = null;

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function setText(id, v) { document.getElementById(id).textContent = v; }

    function showAlert(msg) {
        var el = document.getElementById('edm-dash-alert');
        el.textContent = msg;
        el.hidden = false;
    }

    // The furthest step's time (what "Last activity" shows).
    function lastActivity(r) {
        return r.complained_at || r.bounced_at || r.unsubscribed_at || r.clicked_at || r.opened_at || r.delivered_at || r.sent_at || '';
    }

    function resultCell(r) {
        var p = RESULT_PILLS[r.result] || RESULT_PILLS.sent;
        return '<span class="edm-pill ' + p.cls + '"' + (r.error ? ' title="' + esc(r.error) + '"' : '') + '>' + esc(p.label) + '</span>';
    }

    // Every time SES reported, as the row tooltip (the CSV has them as columns).
    function timeline(r) {
        return [['Sent', r.sent_at], ['Delivered', r.delivered_at], ['Opened', r.opened_at], ['Clicked', r.clicked_at],
            ['Bounced', r.bounced_at], ['Complained', r.complained_at], ['Unsubscribed', r.unsubscribed_at],
            ['Reason', r.unsubscribe_reason], ['Comment', r.unsubscribe_comment]]
            .filter(function (x) { return x[1]; })
            .map(function (x) { return x[0] + ': ' + x[1]; }).join('\n');
    }

    var pager = window.edmPager(document.getElementById('edm-rp-pager'), function (pageRows, startIdx) {
        if (!pageRows.length) {
            rowsEl.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">' +
                (all.length ? 'No recipient matches.' : 'Nobody has been sent this newsletter yet.') + '</td></tr>';
            return;
        }
        rowsEl.innerHTML = pageRows.map(function (r, i) {
            return '<tr title="' + esc(timeline(r)) + '">' +
                '<td class="text-muted">' + (startIdx + i + 1) + '</td>' +
                '<td><div>' + esc(r.email) + '</div>' + (r.name ? '<div class="edm-rp-sub">' + esc(r.name) + '</div>' : '') + '</td>' +
                '<td>' + resultCell(r) + '</td>' +
                '<td class="edm-rp-sub">' + esc(lastActivity(r) || '-') + '</td>' +
                '</tr>';
        }).join('');
    });

    function applyFilter() {
        var test = FILTERS[filterEl.value];
        var q = searchEl.value.trim().toLowerCase();
        shown = all.filter(function (r) {
            if (test && !test(r)) { return false; }
            return !q || r.email.toLowerCase().indexOf(q) !== -1 || String(r.name || '').toLowerCase().indexOf(q) !== -1;
        });
        pager.set(shown);
    }

    function paintStats(data) {
        var s = data.stats;
        var c = data.campaign;
        campaignName = c.name;
        document.title = c.name + ' - Report';
        setText('edm-rp-print-title', c.name + (c.subject ? ' - ' + c.subject : ''));

        window.edmPerformance.render(document.getElementById('edm-perf'), data.performance, {
            trackOpens: !!c.track_opens,
            trackClicks: !!c.track_clicks
        });

        // Sent, but SES has reported nothing back: the event destination /
        // webhook is not connected, so the rates stay at 0.
        var notice = document.getElementById('edm-rp-notice');
        if (s.sent > 0 && data.events === 0) {
            setText('edm-rp-notice-text', 'No SES events yet - figures stay at 0 until tracking is connected.');
            notice.title = 'The SES configuration set must publish events to the SNS topic that calls public/ses-webhook.php on a public server (DEPLOY.md, Amazon SES).';
            notice.hidden = false;
        } else {
            notice.hidden = true;
        }
    }

    // Every recipient once, by the furthest result: one stacked bar plus a
    // legend whose items filter the table below.
    function paintSplit() {
        var el = document.getElementById('edm-rp-split');
        if (!all.length) {
            el.innerHTML = '<div class="edm-rp-sub">Nobody has been sent this newsletter yet.</div>';
            return;
        }
        var counts = {};
        all.forEach(function (r) { counts[r.result] = (counts[r.result] || 0) + 1; });
        var parts = SPLIT.filter(function (x) { return counts[x[0]]; });
        function pct(n) { return Math.round(n * 1000 / all.length) / 10; }
        el.innerHTML = '<div class="edm-rp-stack">' + parts.map(function (x) {
            return '<span style="flex-grow:' + counts[x[0]] + ';background:' + x[1] + '" title="' +
                esc(RESULT_PILLS[x[0]].label + ': ' + num(counts[x[0]]) + ' (' + pct(counts[x[0]]) + '%)') + '"></span>';
        }).join('') + '</div>' +
            '<ul class="edm-rp-legend">' + parts.map(function (x) {
                return '<li><button type="button" data-result="' + x[0] + '" title="Show these recipients">' +
                    '<i style="background:' + x[1] + '"></i><span>' + esc(RESULT_PILLS[x[0]].label) + '</span>' +
                    '<b>' + num(counts[x[0]]) + '</b><small>' + pct(counts[x[0]]) + '%</small></button></li>';
            }).join('') + '</ul>';
    }

    // Legend click: filter the table. A split segment is the furthest result,
    // so "Delivered" there means delivered and not opened.
    document.getElementById('edm-rp-split').addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-result]');
        if (!btn) { return; }
        var result = btn.getAttribute('data-result');
        var value = result === 'delivered' ? 'not_opened' : result;
        if (filterEl.querySelector('option[value="' + value + '"]')) {
            filterEl.value = value;
            searchEl.value = '';
            applyFilter();
            filterEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    function csvCell(v) {
        var s = String(v == null ? '' : v);
        // Keep spreadsheet apps from running a value as a formula.
        if (/^[=+\-@\t\r]/.test(s)) { s = "'" + s; }
        return '"' + s.replace(/"/g, '""') + '"';
    }

    function fileName(suffix) {
        return (campaignName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'newsletter') + suffix;
    }

    function exportRecipients() {
        var head = ['Email', 'Name', 'Result', 'Sent', 'Delivered', 'Opened', 'Clicked', 'Bounced', 'Complained', 'Unsubscribed', 'Unsubscribe reason', 'Unsubscribe comment', 'Error'];
        var lines = [head.map(csvCell).join(',')].concat(shown.map(function (r) {
            return [r.email, r.name, (RESULT_PILLS[r.result] || RESULT_PILLS.sent).label, r.sent_at, r.delivered_at, r.opened_at,
                r.clicked_at, r.bounced_at, r.complained_at, r.unsubscribed_at, r.unsubscribe_reason, r.unsubscribe_comment, r.error].map(csvCell).join(',');
        }));
        var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = fileName('-recipients.csv');
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 0);
    }

    document.getElementById('edm-rp-export').addEventListener('click', exportRecipients);
    document.querySelectorAll('[data-export]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!report) { return; }
            var kind = btn.getAttribute('data-export');
            if (kind === 'pdf') {
                window.edmPerformance.print();
            } else if (kind === 'csv') {
                window.edmPerformance.csv(report.performance, fileName('-performance.csv'));
            } else {
                exportRecipients();
            }
        });
    });

    filterEl.addEventListener('change', applyFilter);
    searchEl.addEventListener('input', applyFilter);

    if (!ID) {
        return;
    }
    fetch(BASE + 'newsletters/api.php?action=campaigns_report&id=' + ID, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) {
                showAlert(res.message || 'Could not load the report.');
                rowsEl.innerHTML = '';
                document.getElementById('edm-perf').innerHTML = '';
                return;
            }
            report = res.data;
            all = res.data.rows || [];
            paintStats(res.data);
            document.getElementById('edm-perf-export').disabled = false;
            paintSplit();
            applyFilter();
        })
        .catch(function () { showAlert('Could not load the report.'); });

    // ---- Website figures (Google Analytics 4, campaigns_analytics) ----

    var gaBody = document.getElementById('edm-rp-ga-body');
    var gaMeta = document.getElementById('edm-rp-ga-meta');
    var gaRefresh = document.getElementById('edm-rp-ga-refresh');

    function num(v) { return Number(v || 0).toLocaleString(); }

    function duration(seconds) {
        var s = Math.round(seconds || 0);
        return Math.floor(s / 60) + 'm ' + (s % 60 < 10 ? '0' : '') + (s % 60) + 's';
    }

    function money(v, currency) {
        return (currency ? currency + ' ' : '') + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function gaNotice(icon, html, danger) {
        gaBody.innerHTML = '<div class="edm-rp-notice' + (danger ? ' edm-rp-ga-error' : '') + '"><i class="bi ' + icon + '"></i><span>' + html + '</span></div>';
    }

    function gaStat(tone, icon, title, value, sub) {
        return '<div class="edm-rp-kpi edm-rp-tone-' + tone + '"><div class="edm-rp-kpi-head">' +
            '<span class="edm-rp-kpi-icon"><i class="bi ' + icon + '"></i></span>' +
            '<span class="edm-rp-stat-title">' + esc(title) + '</span></div>' +
            '<div class="edm-rp-stat-value">' + esc(value) + '</div>' +
            '<div class="edm-rp-stat-sub">' + esc(sub) + '</div></div>';
    }

    // A small top-10 table; cols = [[heading, row => cell text]]. The first
    // number column (sessions) gets an inline bar against the busiest row.
    function gaTable(title, rows, cols) {
        if (!rows.length) { return ''; }
        var top = Math.max.apply(null, rows.map(function (r) { return Number(r.sessions || 0); })) || 1;
        return '<div class="edm-rp-ga-col"><h4 class="edm-rp-ga-sub">' + esc(title) + '</h4>' +
            '<table class="table align-middle edm-view-tbl edm-rp-table"><thead><tr><th style="width:3rem;">#</th>' +
            cols.map(function (c, i) { return '<th' + (i ? ' class="text-end"' : '') + '>' + esc(c[0]) + '</th>'; }).join('') +
            '</tr></thead><tbody>' +
            rows.map(function (r, n) {
                return '<tr><td class="text-muted">' + (n + 1) + '</td>' +
                    cols.map(function (c, i) {
                        if (i === 1) {
                            return '<td class="text-end edm-rp-cellbar"><span class="edm-rp-cellbar-fill" style="width:' +
                                Math.round(Number(r.sessions || 0) * 100 / top) + '%"></span><span class="edm-rp-cellbar-val">' + esc(c[1](r)) + '</span></td>';
                        }
                        return '<td' + (i ? ' class="text-end"' : '') + '>' + esc(c[1](r)) + '</td>';
                    }).join('') + '</tr>';
            }).join('') + '</tbody></table></div>';
    }

    function paintAnalytics(d) {
        gaRefresh.hidden = !(d.connected && d.utm_enabled);
        if (!d.connected) {
            gaMeta.textContent = '';
            gaNotice('bi-plug', 'Google Analytics is not connected yet. Missing in edm/.env: ' + esc((d.missing || []).join(', ')) + '.');
            return;
        }
        var meta = ['utm_campaign: ' + esc(d.utm.campaign)];
        if (d.start_date) { meta.push('since ' + esc(d.start_date)); }
        if (d.fetched_at) { meta.push('updated ' + esc(d.fetched_at)); }
        gaMeta.innerHTML = meta.join('<span class="edm-rp-dot">&middot;</span>');

        if (!d.utm_enabled) {
            gaNotice('bi-info-circle', 'UTM tags are off for this newsletter, so Google Analytics cannot tell its visits apart. Turn them on in the Email creator, step 4 Tracking.');
            return;
        }
        var t = d.totals;
        if (!t.sessions) {
            gaNotice('bi-info-circle', 'No website visits from this newsletter yet (source ' + esc(d.utm.source) + ' / medium ' + esc(d.utm.medium) +
                ' / campaign ' + esc(d.utm.campaign) + '). Standard Google Analytics reports can take 24-48 hours to fill.');
            return;
        }

        var html = '<div class="edm-rp-kpis">' +
            gaStat('sessions', 'bi-globe2', 'Sessions', num(t.sessions), num(t.users) + ' users') +
            gaStat('engagement', 'bi-activity', 'Engagement', t.engagement_rate + '%', num(t.engaged_sessions) + ' engaged sessions') +
            gaStat('events', 'bi-bullseye', 'Key events', num(t.key_events), 'conversions') +
            gaStat('revenue', 'bi-cash-coin', 'Revenue', money(t.revenue, d.currency), num(t.purchases) + ' purchases') +
            '</div>' +
            '<div class="edm-rp-chips">' +
            '<span class="edm-rp-chip edm-rp-chip-neutral"><i class="bi bi-file-earmark-text"></i>' + num(t.page_views) + ' page views</span>' +
            '<span class="edm-rp-chip edm-rp-chip-neutral"><i class="bi bi-clock"></i>average session ' + duration(t.avg_duration) + '</span></div>';
        if (d.thresholded) {
            html += '<div class="edm-rp-notice" title="Google Analytics withholds rows with very few users to protect privacy."><i class="bi bi-eye-slash"></i>' +
                '<span>Google Analytics hides very small numbers, so some figures may be missing.</span></div>';
        }
        var tables = gaTable('Top landing pages', d.pages || [], [
            ['Page', function (r) { return r.label; }],
            ['Sessions', function (r) { return num(r.sessions); }],
            ['Key events', function (r) { return num(r.key_events); }]
        ]) + gaTable('By link (utm_content)', d.content || [], [
            ['Content', function (r) { return r.label === '(not set)' ? '(no utm_content)' : r.label; }],
            ['Sessions', function (r) { return num(r.sessions); }],
            ['Revenue', function (r) { return money(r.revenue, ''); }]
        ]);
        if (tables) { html += '<div class="edm-rp-ga-tables">' + tables + '</div>'; }
        gaBody.innerHTML = html;
    }

    function loadAnalytics(refresh) {
        gaRefresh.disabled = true;
        fetch(BASE + 'newsletters/api.php?action=campaigns_analytics&id=' + ID + (refresh ? '&refresh=1' : ''), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    gaRefresh.hidden = false;
                    gaNotice('bi-exclamation-triangle', esc(res.message || 'Could not load Google Analytics figures.'), true);
                    return;
                }
                paintAnalytics(res.data);
            })
            .catch(function () { gaNotice('bi-exclamation-triangle', 'Could not load Google Analytics figures.', true); })
            .then(function () { gaRefresh.disabled = false; });
    }

    gaRefresh.addEventListener('click', function () { loadAnalytics(true); });
    loadAnalytics(false);
})();
