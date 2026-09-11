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
    <button type="button" class="btn btn-sm btn-primary" id="edm-cal-add">Add slot</button>
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

<div class="modal fade" id="edm-cal-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="edm-cal-form">
                <div class="modal-header">
                    <h5 class="modal-title">Calendar slot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="edm-cal-id">
                    <div class="mb-3">
                        <label class="form-label" for="edm-cal-date">Date</label>
                        <input type="date" class="form-control" id="edm-cal-date" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edm-cal-label">Label</label>
                        <input type="text" class="form-control" id="edm-cal-label" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edm-cal-category">Category</label>
                        <input type="text" class="form-control" id="edm-cal-category" maxlength="50" placeholder="promo, newsletter, ...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edm-cal-note">Note</label>
                        <input type="text" class="form-control" id="edm-cal-note" maxlength="255">
                    </div>
                    <div id="edm-cal-form-error" class="text-danger small" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger btn-sm me-auto" id="edm-cal-del" hidden>Delete</button>
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
include __DIR__ . '/../footer.php';
?>
