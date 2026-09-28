<?php
$page_title = 'Dashboard';
$page_subtitle = 'Platform overview. Delivery, open, click and bounce rates come from Amazon SES events.';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'dashboard/dashboard.js';
?>
<div id="edm-dash-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<div class="row g-3" id="edm-dash-cards">
    <div class="col-12 text-center text-muted py-5">Loading...</div>
</div>

<h3 class="h6 mt-4 mb-2">Newsletters by status</h3>
<div class="row g-3" id="edm-dash-status"></div>
<?php
include __DIR__ . '/../footer.php';
?>
