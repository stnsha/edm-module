/**
 * Newsletter report (newsletters/report.php): figures and recipients of one
 * newsletter from campaigns_report. The recipient list is filtered, searched,
 * paged (js/edm-pager.js) and exported to CSV in the browser.
 */
(function () {
    'use strict';

    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    var ID = parseInt(document.getElementById('edm-rp-kpis').getAttribute('data-newsletter'), 10) || 0;

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

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function setText(id, v) { document.getElementById(id).textContent = v; }

    function showAlert(msg) {
        var el = document.getElementById('edm-rp-alert');
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
            ['Bounced', r.bounced_at], ['Complained', r.complained_at], ['Unsubscribed', r.unsubscribed_at]]
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

        // One quiet line: subject, sender and send time only.
        var meta = [];
        if (c.subject) { meta.push(esc(c.subject)); }
        if (c.sender) { meta.push(esc(c.sender)); }
        if (c.first_sent) { meta.push('Sent ' + esc(c.first_sent)); }
        document.getElementById('edm-rp-meta').innerHTML = meta.join('<span class="edm-rp-dot">&middot;</span>');

        function stat(key, value, sub) {
            setText('edm-rp-' + key, value);
            setText('edm-rp-' + key + '-sub', sub);
        }
        stat('delivered', s.delivery_rate + '%', s.delivered + ' of ' + s.sent);
        stat('opened', c.track_opens ? s.open_rate + '%' : 'Off', c.track_opens ? s.opened + ' opened' : 'tracking off');
        stat('clicked', c.track_clicks ? s.click_rate + '%' : 'Off', c.track_clicks ? s.clicked + ' clicked' : 'tracking off');
        stat('bounced', s.bounce_rate + '%', s.bounced + ' bounced');

        var more = [s.sent + ' sent', s.complained + ' complaints', s.unsubscribed + ' unsubscribed'];
        if (s.failed + s.skipped > 0) { more.push((s.failed + s.skipped) + ' not sent'); }
        if (c.audience_label) { more.push('Audience: ' + esc(c.audience_label)); }
        document.getElementById('edm-rp-more').innerHTML = more.join('<span class="edm-rp-dot">&middot;</span>');

        // Sent, but SES has reported nothing back: the event destination /
        // webhook is not connected, so the rates stay at 0.
        var notice = document.getElementById('edm-rp-notice');
        if (s.sent > 0 && data.events === 0) {
            setText('edm-rp-notice-text', 'Waiting for Amazon SES events - delivery, opens and clicks show once event tracking is connected.');
            notice.title = 'The SES configuration set must publish events to the SNS topic that calls public/ses-webhook.php on a public server (DEPLOY.md, Amazon SES).';
            notice.hidden = false;
        } else {
            notice.hidden = true;
        }
    }

    function csvCell(v) {
        var s = String(v == null ? '' : v);
        // Keep spreadsheet apps from running a value as a formula.
        if (/^[=+\-@\t\r]/.test(s)) { s = "'" + s; }
        return '"' + s.replace(/"/g, '""') + '"';
    }

    document.getElementById('edm-rp-export').addEventListener('click', function () {
        var head = ['Email', 'Name', 'Result', 'Sent', 'Delivered', 'Opened', 'Clicked', 'Bounced', 'Complained', 'Unsubscribed', 'Error'];
        var lines = [head.map(csvCell).join(',')].concat(shown.map(function (r) {
            return [r.email, r.name, (RESULT_PILLS[r.result] || RESULT_PILLS.sent).label, r.sent_at, r.delivered_at, r.opened_at,
                r.clicked_at, r.bounced_at, r.complained_at, r.unsubscribed_at, r.error].map(csvCell).join(',');
        }));
        var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = (campaignName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'newsletter') + '-report.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 0);
    });

    // Newsletter picker: open another newsletter's report.
    var pickEl = document.getElementById('edm-rp-pick');
    if (pickEl) {
        pickEl.addEventListener('change', function () {
            if (pickEl.value) { pickEl.form.submit(); }
        });
    }

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
                return;
            }
            paintStats(res.data);
            all = res.data.rows || [];
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

    function gaStat(title, value, sub) {
        return '<div class="edm-rp-stat"><div class="edm-rp-stat-title">' + esc(title) + '</div>' +
            '<div class="edm-rp-stat-value">' + esc(value) + '</div>' +
            '<div class="edm-rp-stat-sub">' + esc(sub) + '</div></div>';
    }

    // A small top-10 table; cols = [[heading, row => cell text]].
    function gaTable(title, rows, cols) {
        if (!rows.length) { return ''; }
        return '<div class="edm-rp-ga-col"><h4 class="edm-rp-ga-sub">' + esc(title) + '</h4>' +
            '<table class="table align-middle edm-view-tbl edm-rp-table"><thead><tr><th style="width:3rem;">#</th>' +
            cols.map(function (c, i) { return '<th' + (i ? ' class="text-end"' : '') + '>' + esc(c[0]) + '</th>'; }).join('') +
            '</tr></thead><tbody>' +
            rows.map(function (r, n) {
                return '<tr><td class="text-muted">' + (n + 1) + '</td>' +
                    cols.map(function (c, i) { return '<td' + (i ? ' class="text-end"' : '') + '>' + esc(c[1](r)) + '</td>'; }).join('') + '</tr>';
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

        var html = '<div class="edm-rp-stats">' +
            gaStat('Sessions', num(t.sessions), num(t.users) + ' users') +
            gaStat('Engagement', t.engagement_rate + '%', num(t.engaged_sessions) + ' engaged sessions') +
            gaStat('Key events', num(t.key_events), 'conversions') +
            gaStat('Revenue', money(t.revenue, d.currency), num(t.purchases) + ' purchases') +
            '</div>' +
            '<div class="edm-rp-more">' + [num(t.page_views) + ' page views', 'average session ' + duration(t.avg_duration)].join('<span class="edm-rp-dot">&middot;</span>') + '</div>';
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
