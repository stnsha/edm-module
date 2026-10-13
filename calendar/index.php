<?php
$page_title = 'Calendar';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'calendar/calendar.js';
?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="edm-cal-prev">&lsaquo;</button>
        <h2 class="h5 mb-0" id="edm-cal-title" style="min-width: 12rem; text-align:center;">-</h2>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="edm-cal-next">&rsaquo;</button>
        <button type="button" class="btn btn-sm btn-link" id="edm-cal-today">Today</button>
    </div>
</div>

<div id="edm-cal-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

<div class="table-responsive">
    <table class="table table-bordered align-top mb-0 edm-calendar">
        <thead>
            <tr class="text-center small text-muted">
                <th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th><th>Sun</th>
            </tr>
        </thead>
        <tbody id="edm-cal-grid"></tbody>
    </table>
</div>

<?php
include __DIR__ . '/../footer.php';
?>
