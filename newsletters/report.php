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

// The four headline rates; id suffix => title. The rest (sent, complaints,
// unsubscribes, not sent) is one quiet line under them.
$report_kpis = array(
    'delivered' => 'Delivered',
    'opened'    => 'Opened',
    'clicked'   => 'Clicked',
    'bounced'   => 'Bounced',
);
?>
<form class="edm-rp-pick" method="get" action="<?php echo EDM_BASE; ?>newsletters/report.php">
    <select class="form-select" name="newsletter" id="edm-rp-pick" aria-label="Newsletter" autocomplete="off"<?php echo $report_choices ? '' : ' disabled'; ?>>
        <?php if (!$report_choices): ?>
        <option value="">No newsletter has been sent yet</option>
        <?php else: ?>
        <option value=""<?php echo $report_id ? '' : ' selected'; ?>>Choose a sent newsletter...</option>
        <?php foreach ($report_choices as $c): ?>
        <option value="<?php echo (int)$c['id']; ?>"<?php echo (int)$c['id'] === $report_id ? ' selected' : ''; ?>>
            <?php echo edm_h($c['name'] . ((int)$c['status'] === 9 ? ' (archived)' : '')); ?>
        </option>
        <?php endforeach; ?>
        <?php endif; ?>
    </select>
    <div class="edm-rp-meta" id="edm-rp-meta"></div>
</form>

<?php if (!$report_id): ?>
<div class="edm-rp-empty">
    <i class="bi bi-bar-chart"></i>
    <p><?php echo $report_choices
        ? 'Choose a newsletter to see its delivery, opens, clicks and recipients.'
        : 'Reports appear here once a newsletter has been sent.'; ?></p>
</div>
<script>
document.getElementById('edm-rp-pick').addEventListener('change', function () {
    if (this.value) { this.form.submit(); }
});
</script>
<?php
include __DIR__ . '/../footer.php';
return;
endif;
?>
<div id="edm-rp-alert" class="alert alert-danger py-2 px-3 small" hidden></div>
<div id="edm-rp-notice" class="edm-rp-notice" hidden>
    <i class="bi bi-info-circle"></i><span id="edm-rp-notice-text"></span>
</div>

<div class="edm-rp-stats" id="edm-rp-kpis" data-newsletter="<?php echo (int)$report_id; ?>">
    <?php foreach ($report_kpis as $key => $title): ?>
    <div class="edm-rp-stat">
        <div class="edm-rp-stat-title"><?php echo edm_h($title); ?></div>
        <div class="edm-rp-stat-value" id="edm-rp-<?php echo $key; ?>">-</div>
        <div class="edm-rp-stat-sub" id="edm-rp-<?php echo $key; ?>-sub">&nbsp;</div>
    </div>
    <?php endforeach; ?>
</div>
<div class="edm-rp-more" id="edm-rp-more"></div>

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
