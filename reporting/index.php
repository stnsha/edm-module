<?php
$page_title = 'Statistics';
$page_subtitle = 'Platform overview. Delivery, open, click and bounce rates come from Amazon SES delivery events and appear once SES event ingestion is wired.';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'reporting/reporting.js';
?>
<div id="edm-rep-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<div class="row g-3" id="edm-rep-cards">
    <div class="col-12 text-center text-muted py-5">Loading...</div>
</div>

<h3 class="h6 mt-4 mb-2">Newsletters by status</h3>
<div class="table-responsive">
    <table class="table table-hover align-middle edm-view-tbl">
        <thead>
            <tr>
                <th style="width:3rem;">#</th>
                <th>Status</th>
                <th class="text-end">Count</th>
            </tr>
        </thead>
        <tbody id="edm-rep-status"></tbody>
    </table>
</div>
<?php
include __DIR__ . '/../footer.php';
?>
