<?php
// Dashboard + reports in one page. Without ?newsletter: platform overview
// (stat cards) and message performance for a period, every newsletter
// (dashboard.js). With ?newsletter=<id>: that newsletter's report -
// performance, recipients by result, website figures, recipients
// (report.js). The picker switches between them.
$report_id = isset($_GET['newsletter']) ? (int)$_GET['newsletter'] : 0;
$page_title = 'Dashboard';
$page_subtitle = $report_id
    ? 'How a sent newsletter performed, from Amazon SES events.'
    : 'Platform overview and message performance for a period, from Amazon SES events.';
require __DIR__ . '/../partials.php';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . ($report_id ? 'dashboard/report.js' : 'dashboard/dashboard.js');

require_once __DIR__ . '/../app/bootstrap.php';

// Picker: every newsletter that has sent something (send log rows), archived
// ones included, newest first.
$report_choices = array_values(array_filter(\Edm\Models\Campaign::listing(), function ($c) {
    return (int)$c['delivered'] > 0;
}));
$report_current = null;
foreach ($report_choices as $c) {
    if ((int)$c['id'] === $report_id) {
        $report_current = $c;
    }
}
// Picker row status pill: status => pill class (others fall back to secondary).
$report_status_pills = array(7 => 'edm-pill-info', 8 => 'edm-pill-success', 9 => 'edm-pill-dark');
$report_statuses = \Edm\Models\Campaign::STATUSES;

// Period presets for the performance filter (value => label); dashboard.js
// turns a preset into from / to dates. Default: this year, as GetResponse.
$edm_periods = array(
    '7'      => 'Last 7 days',
    '30'     => 'Last 30 days',
    '90'     => 'Last 90 days',
    'month'  => 'This month',
    'year'   => 'This year',
    'custom' => 'Custom range',
);
$edm_today = date('Y-m-d');

// Stat cards follow atem/index.php: .edm-card.edm-dash-stat with a title,
// a coloured .edm-stat-value (filled in by dashboard.js) and a .edm-stat-label.
// id suffix => [title, value colour class, label]
$edm_kpis = array(
    'campaigns' => array('Newsletters', 'edm-stat-value--blue', 'scheduled'),
    'contacts'    => array('Contacts', 'edm-stat-value--blue', 'subscribed'),
    'suppressed'  => array('Suppressed', 'edm-stat-value--red', 'addresses'),
    'senders'     => array('Senders Verified', 'edm-stat-value--green', 'verified / total'),
);

// Campaign::STATUSES label => [title, value colour]. Colours match the status
// pills on the Newsletters list (newsletters/index.php).
$edm_status_cards = array(
    'draft'               => array('Draft', '#6c757d'),
    'pending_approval'    => array('Pending Approval', '#fd7e14'),
    'scheduled'           => array('Scheduled', '#0d6efd'),
    'sending'             => array('Sending', '#0d6efd'),
    'completed'           => array('Completed', '#198754'),
    'archived'            => array('Archived', '#212529'),
);

// Result filter of the recipients table (CampaignReport::RESULTS).
$report_results = \Edm\Services\CampaignReport::RESULTS;
?>
<div id="edm-dash-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<?php if (!$report_id): ?>
<!-- Stat Cards -->
<div class="row g-3 mb-4 edm-no-print">
    <?php foreach ($edm_kpis as $key => $k): ?>
    <div class="col-12 col-sm-6 col-xl">
        <div class="edm-card edm-dash-stat h-100">
            <div class="edm-card-title mb-1"><?php echo htmlspecialchars($k[0]); ?></div>
            <div class="edm-stat-value <?php echo $k[1]; ?>" id="edm-dash-<?php echo $key; ?>">---</div>
            <div class="edm-stat-label" id="edm-dash-<?php echo $key; ?>-label"><?php echo htmlspecialchars($k[2]); ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Newsletters by status: same stat cards, continuing below -->
<div class="row g-3 mb-4 edm-no-print">
    <?php foreach ($edm_status_cards as $key => $s): ?>
    <div class="col-12 col-sm-6 col-xl">
        <div class="edm-card edm-dash-stat h-100">
            <div class="edm-card-title mb-1"><?php echo htmlspecialchars($s[0]); ?></div>
            <div class="edm-stat-value" id="edm-dash-status-<?php echo $key; ?>" style="color:<?php echo $s[1]; ?>;">---</div>
            <div class="edm-stat-label">newsletters</div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Toolbar: newsletter picker (All newsletters, or one newsletter's report),
     the period filter for All newsletters, and Export. -->
<div class="edm-perf-toolbar edm-no-print">
    <div class="edm-rp-pick">
        <div class="dropdown">
            <button type="button" class="edm-rp-pick-btn" id="edm-rp-pick" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                <span class="edm-rp-pick-icon"><i class="bi <?php echo $report_id ? 'bi-envelope-paper' : 'bi-collection'; ?>"></i></span>
                <span class="edm-rp-pick-text">
                    <span class="edm-rp-pick-name"><?php echo $report_current ? edm_h($report_current['name']) : ($report_id ? 'Newsletter #' . $report_id : 'All newsletters'); ?></span>
                </span>
                <i class="bi bi-chevron-down edm-rp-pick-caret"></i>
            </button>
            <div class="dropdown-menu edm-rp-pick-menu" aria-labelledby="edm-rp-pick">
                <div class="edm-rp-pick-search">
                    <i class="bi bi-search"></i>
                    <input type="search" class="form-control form-control-sm" id="edm-rp-pick-q" placeholder="Search newsletters" aria-label="Search newsletters" autocomplete="off">
                </div>
                <div class="edm-rp-pick-list" id="edm-rp-pick-list">
                    <a class="edm-rp-pick-item<?php echo $report_id ? '' : ' is-current'; ?>" href="<?php echo EDM_BASE; ?>dashboard/index.php" data-search="all newsletters">
                        <span class="edm-rp-pick-item-main">
                            <span class="edm-rp-pick-item-name">All newsletters</span>
                            <span class="edm-rp-pick-item-sub">Overview and performance for a period</span>
                        </span>
                        <?php if (!$report_id): ?><i class="bi bi-check2 edm-rp-pick-check"></i><?php endif; ?>
                    </a>
                    <?php foreach ($report_choices as $c):
                        $status = (int)$c['status'];
                        $when = $c['scheduled_at'] ?: $c['created_at'];
                    ?>
                    <a class="edm-rp-pick-item<?php echo (int)$c['id'] === $report_id ? ' is-current' : ''; ?>"
                       href="<?php echo EDM_BASE; ?>dashboard/index.php?newsletter=<?php echo (int)$c['id']; ?>"
                       data-search="<?php echo edm_h(strtolower($c['name'] . ' ' . $c['subject'])); ?>">
                        <span class="edm-rp-pick-item-main">
                            <span class="edm-rp-pick-item-name"><?php echo edm_h($c['name']); ?></span>
                            <?php if ($c['subject']): ?>
                            <span class="edm-rp-pick-item-sub"><?php echo edm_h($c['subject']); ?></span>
                            <?php endif; ?>
                            <span class="edm-rp-pick-item-sub"><i class="bi bi-calendar3"></i><?php echo edm_h($when); ?><span class="edm-rp-dot">&middot;</span><i class="bi bi-people"></i><?php echo (int)$c['delivered']; ?></span>
                        </span>
                        <span class="edm-pill <?php echo $report_status_pills[$status] ?? 'edm-pill-secondary'; ?>"><?php echo edm_h(ucfirst($report_statuses[$status] ?? '')); ?></span>
                        <?php if ((int)$c['id'] === $report_id): ?><i class="bi bi-check2 edm-rp-pick-check"></i><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                    <div class="edm-rp-pick-none" id="edm-rp-pick-none" hidden>No newsletter matches.</div>
                </div>
            </div>
        </div>
    </div>
    <div class="edm-perf-filter">
        <?php if (!$report_id): ?>
        <form class="edm-perf-filter" id="edm-perf-filter" autocomplete="off">
            <select class="form-select form-select-sm" id="edm-perf-preset" aria-label="Period">
                <?php foreach ($edm_periods as $value => $label): ?>
                <option value="<?php echo $value; ?>"<?php echo $value === 'year' ? ' selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" class="form-control form-control-sm" id="edm-perf-from" aria-label="From" max="<?php echo $edm_today; ?>" value="<?php echo date('Y') . '-01-01'; ?>">
            <span class="edm-perf-until">to</span>
            <input type="date" class="form-control form-control-sm" id="edm-perf-to" aria-label="To" max="<?php echo $edm_today; ?>" value="<?php echo $edm_today; ?>">
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        </form>
        <?php endif; ?>
        <div class="dropdown">
            <button type="button" class="btn btn-light btn-sm dropdown-toggle" id="edm-perf-export" data-bs-toggle="dropdown" aria-expanded="false" disabled>
                <i class="bi bi-download"></i> Export
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><button type="button" class="dropdown-item" data-export="pdf"><i class="bi bi-filetype-pdf"></i> PDF (print)</button></li>
                <li><button type="button" class="dropdown-item" data-export="csv"><i class="bi bi-filetype-csv"></i> <?php echo $report_id ? 'Performance CSV' : 'CSV'; ?></button></li>
                <?php if ($report_id): ?>
                <li><button type="button" class="dropdown-item" data-export="recipients"><i class="bi bi-people"></i> Recipients CSV</button></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<script>
// Picker search: filter the rows as you type, focus the box on open.
(function () {
    var btn = document.getElementById('edm-rp-pick');
    var q = document.getElementById('edm-rp-pick-q');
    var items = document.querySelectorAll('#edm-rp-pick-list .edm-rp-pick-item');
    q.addEventListener('input', function () {
        var term = q.value.trim().toLowerCase();
        var shown = 0;
        items.forEach(function (a) {
            var hit = !term || a.getAttribute('data-search').indexOf(term) !== -1;
            a.hidden = !hit;
            if (hit) { shown++; }
        });
        document.getElementById('edm-rp-pick-none').hidden = shown > 0;
    });
    q.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        e.preventDefault();
        var first = document.querySelector('#edm-rp-pick-list .edm-rp-pick-item:not([hidden])');
        if (first) { window.location.href = first.href; }
    });
    btn.addEventListener('shown.bs.dropdown', function () { q.focus(); });
})();
</script>

<?php if (!$report_id): ?>
<!-- Message performance for a period (dashboard/api.php?action=performance,
     painted by js/edm-performance.js). -->
<div id="edm-perf" class="edm-perf">
    <div class="edm-rp-notice"><i class="bi bi-hourglass-split"></i><span>Loading performance...</span></div>
</div>
<?php else: ?>
<h2 class="edm-perf-title d-none d-print-block" id="edm-rp-print-title"></h2>
<div id="edm-rp-notice" class="edm-rp-notice" hidden>
    <i class="bi bi-info-circle"></i><span id="edm-rp-notice-text"></span>
</div>

<!-- One newsletter (newsletters/api.php?action=campaigns_report): its
     performance (js/edm-performance.js), then every recipient once by the
     furthest result; its legend filters the table below. -->
<div id="edm-perf" class="edm-perf" data-newsletter="<?php echo (int)$report_id; ?>">
    <div class="edm-rp-notice"><i class="bi bi-hourglass-split"></i><span>Loading performance...</span></div>
</div>
<section class="edm-perf-section">
    <h3 class="edm-perf-heading">Recipients by result</h3>
    <div id="edm-rp-split"></div>
</section>

<!-- Website figures from Google Analytics 4 by the newsletter's UTM tags
     (campaigns_analytics), loaded separately so a slow or failing GA call
     never holds up the SES figures. -->
<section class="edm-rp-ga edm-perf-section">
    <div class="edm-rp-toolbar">
        <h3 class="edm-rp-heading">Website <span class="edm-rp-ga-src">Google Analytics</span></h3>
        <div class="d-flex align-items-center gap-2">
            <span class="edm-rp-ga-meta" id="edm-rp-ga-meta"></span>
            <button type="button" class="btn btn-light btn-sm" id="edm-rp-ga-refresh" title="Fetch the latest figures from Google Analytics" aria-label="Refresh" hidden><i class="bi bi-arrow-clockwise"></i></button>
        </div>
    </div>
    <div id="edm-rp-ga-body">
        <div class="edm-rp-notice"><i class="bi bi-hourglass-split"></i><span>Loading website figures...</span></div>
    </div>
</section>

<div class="edm-rp-toolbar edm-no-print">
    <h3 class="edm-rp-heading">Recipients</h3>
    <div class="d-flex align-items-center gap-2">
        <select class="form-select form-select-sm" id="edm-rp-filter" aria-label="Filter recipients" autocomplete="off">
            <option value="">Everyone</option>
            <?php foreach ($report_results as $value => $label): ?>
            <option value="<?php echo edm_h($value); ?>"><?php echo edm_h($label); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" class="form-control form-control-sm" id="edm-rp-search" placeholder="Search" aria-label="Search email or name" autocomplete="off">
        <button type="button" class="btn btn-light btn-sm" id="edm-rp-export" title="Export the list as CSV" aria-label="Export CSV"><i class="bi bi-download"></i></button>
    </div>
</div>

<div class="table-responsive edm-no-print">
    <table class="table align-middle edm-view-tbl edm-rp-table">
        <thead>
            <tr>
                <th style="width:3rem;">#</th>
                <th>Recipient</th>
                <th>Result</th>
                <th>Last activity</th>
            </tr>
        </thead>
        <tbody id="edm-rp-rows">
            <tr><td colspan="4" class="text-center text-muted py-4">Loading...</td></tr>
        </tbody>
    </table>
</div>
<div class="edm-pager edm-no-print" id="edm-rp-pager"></div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
<script src="<?php echo EDM_BASE; ?>js/edm-pager.js?v=<?php echo filemtime(__DIR__ . '/../js/edm-pager.js'); ?>"></script>
<script src="<?php echo EDM_BASE; ?>js/edm-performance.js?v=<?php echo filemtime(__DIR__ . '/../js/edm-performance.js'); ?>"></script>
<?php
include __DIR__ . '/../footer.php';
?>
