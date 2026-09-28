<?php
$page_title = 'Dashboard';
$page_subtitle = 'Platform overview. Delivery, open, click and bounce rates come from Amazon SES events.';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'dashboard/dashboard.js';

// Stat cards follow atem/index.php: .edm-card.edm-dash-stat with a title,
// a coloured .edm-stat-value (filled in by dashboard.js) and a .edm-stat-label.
// id suffix => [title, value colour class, label]
$edm_kpis = array(
    'newsletters' => array('Newsletters', 'edm-stat-value--blue', 'scheduled'),
    'lists'       => array('Lists', 'edm-stat-value--blue', 'members'),
    'suppressed'  => array('Suppressed', 'edm-stat-value--red', 'addresses'),
    'senders'     => array('Senders Verified', 'edm-stat-value--green', 'verified / total'),
    'delivery'    => array('Delivery Rate', 'edm-stat-value--green', 'delivered of sent'),
    'open'        => array('Open Rate', 'edm-stat-value--blue', 'opened'),
    'click'       => array('Click Rate', 'edm-stat-value--blue', 'clicked'),
    'bounce'      => array('Bounce Rate', 'edm-stat-value--red', 'bounced'),
);

// Campaign::STATUSES label => [title, value colour]. Colours match the status
// pills on the Newsletters list (campaign/index.php).
$edm_status_cards = array(
    'draft'               => array('Draft', '#6c757d'),
    'pending_submission'  => array('Pending Submission', '#0aa2c0'),
    'under_bpt_review'    => array('Under BPT Review', '#0aa2c0'),
    'content_revision'    => array('Content Revision', '#fd7e14'),
    'audience_validation' => array('Audience Validation', '#0aa2c0'),
    'scheduled'           => array('Scheduled', '#0d6efd'),
    'sending'             => array('Sending', '#0d6efd'),
    'completed'           => array('Completed', '#198754'),
    'archived'            => array('Archived', '#212529'),
);
?>
<div id="edm-dash-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
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
<div class="row g-3 mb-4">
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
<?php
include __DIR__ . '/../footer.php';
?>
