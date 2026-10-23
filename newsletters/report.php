<?php
// Newsletter report: figures + recipients of one sent newsletter
// (campaigns_report). Newsletters > Reports (pick a newsletter) or
// "View report" on the Newsletters list (?newsletter=<id>).
$report_id = isset($_GET['newsletter']) ? (int)$_GET['newsletter'] : 0;
$page_title = 'Reports';
$page_subtitle = 'How a sent newsletter performed, from Amazon SES events.';
require __DIR__ . '/../partials.php';
include __DIR__ . '/../header.php';

$page_js = $report_id ? EDM_BASE . 'newsletters/report.js' : null;

// Result filter (CampaignReport::RESULTS).
require_once __DIR__ . '/../app/bootstrap.php';
$report_results = \Edm\Services\CampaignReport::RESULTS;

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

// The four headline rates; id suffix => [title, icon]. Each card carries its
// colour (edm-rp-tone-<key>), a rate bar and a health tag set by report.js.
// The rest (sent, complaints, unsubscribes, not sent) is a chip row under them.
$report_kpis = array(
    'delivered' => array('Delivered', 'bi-envelope-check'),
    'opened'    => array('Opened', 'bi-envelope-open'),
    'clicked'   => array('Clicked', 'bi-hand-index'),
    'bounced'   => array('Bounced', 'bi-envelope-x'),
);
?>
<!-- Newsletter picker: a dropdown with a search box; each row is a link to
     that newsletter's report (name, subject, send time, recipients, status). -->
<div class="edm-rp-pick">
    <div class="dropdown">
        <button type="button" class="edm-rp-pick-btn" id="edm-rp-pick" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"<?php echo $report_choices ? '' : ' disabled'; ?>>
            <span class="edm-rp-pick-icon"><i class="bi bi-envelope-paper"></i></span>
            <span class="edm-rp-pick-text">
                <?php if ($report_current): ?>
                <span class="edm-rp-pick-name"><?php echo edm_h($report_current['name']); ?></span>
                <?php elseif ($report_choices): ?>
                <span class="edm-rp-pick-name">Choose a sent newsletter</span>
                <?php else: ?>
                <span class="edm-rp-pick-name">No newsletter has been sent yet</span>
                <?php endif; ?>
            </span>
            <i class="bi bi-chevron-down edm-rp-pick-caret"></i>
        </button>
        <?php if ($report_choices): ?>
        <div class="dropdown-menu edm-rp-pick-menu" aria-labelledby="edm-rp-pick">
            <div class="edm-rp-pick-search">
                <i class="bi bi-search"></i>
                <input type="search" class="form-control form-control-sm" id="edm-rp-pick-q" placeholder="Search newsletters" aria-label="Search newsletters" autocomplete="off">
            </div>
            <div class="edm-rp-pick-list" id="edm-rp-pick-list">
                <?php foreach ($report_choices as $c):
                    $status = (int)$c['status'];
                    $when = $c['scheduled_at'] ?: $c['created_at'];
                ?>
                <a class="edm-rp-pick-item<?php echo (int)$c['id'] === $report_id ? ' is-current' : ''; ?>"
                   href="<?php echo EDM_BASE; ?>newsletters/report.php?newsletter=<?php echo (int)$c['id']; ?>"
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
        <?php endif; ?>
    </div>
</div>
<script>
// Picker search: filter the rows as you type, focus the box on open.
(function () {
    var btn = document.getElementById('edm-rp-pick');
    var q = document.getElementById('edm-rp-pick-q');
    if (!q) { return; }
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
<div class="edm-rp-empty">
    <i class="bi bi-bar-chart"></i>
    <p><?php echo $report_choices
        ? 'Choose a newsletter to see its delivery, opens, clicks and recipients.'
        : 'Reports appear here once a newsletter has been sent.'; ?></p>
</div>
<?php
include __DIR__ . '/../footer.php';
return;
endif;
?>
<div id="edm-rp-alert" class="alert alert-danger py-2 px-3 small" hidden></div>
<div id="edm-rp-notice" class="edm-rp-notice" hidden>
    <i class="bi bi-info-circle"></i><span id="edm-rp-notice-text"></span>
</div>

<div class="edm-rp-kpis" id="edm-rp-kpis" data-newsletter="<?php echo (int)$report_id; ?>">
    <?php foreach ($report_kpis as $key => $kpi): ?>
    <div class="edm-rp-kpi edm-rp-tone-<?php echo $key; ?>">
        <div class="edm-rp-kpi-head">
            <span class="edm-rp-kpi-icon"><i class="bi <?php echo $kpi[1]; ?>"></i></span>
            <span class="edm-rp-stat-title"><?php echo edm_h($kpi[0]); ?></span>
            <span class="edm-rp-health" id="edm-rp-<?php echo $key; ?>-health" hidden></span>
        </div>
        <div class="edm-rp-stat-value" id="edm-rp-<?php echo $key; ?>">-</div>
        <div class="edm-rp-bar"><span id="edm-rp-<?php echo $key; ?>-bar"></span></div>
        <div class="edm-rp-stat-sub" id="edm-rp-<?php echo $key; ?>-sub">&nbsp;</div>
    </div>
    <?php endforeach; ?>
</div>
<div class="edm-rp-chips" id="edm-rp-more"></div>

<!-- Funnel (sent -> delivered -> opened -> clicked, against sent) and the
     recipients split by their furthest result; both painted by report.js. -->
<div class="edm-rp-charts">
    <section class="edm-rp-panel">
        <h3 class="edm-rp-heading">Engagement funnel <span class="edm-rp-ga-src">% of sent</span></h3>
        <div id="edm-rp-funnel" class="edm-rp-funnel"></div>
    </section>
    <section class="edm-rp-panel">
        <h3 class="edm-rp-heading">Recipients by result</h3>
        <div id="edm-rp-split"></div>
    </section>
</div>

<!-- Website figures from Google Analytics 4 by the newsletter's UTM tags
     (campaigns_analytics), loaded separately so a slow or failing GA call
     never holds up the SES figures. -->
<section class="edm-rp-ga">
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

<div class="edm-rp-toolbar">
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

<div class="table-responsive">
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
<div class="edm-pager" id="edm-rp-pager"></div>

<script src="<?php echo EDM_BASE; ?>js/edm-pager.js?v=<?php echo filemtime(__DIR__ . '/../js/edm-pager.js'); ?>"></script>
<?php
include __DIR__ . '/../footer.php';
?>
