<?php
$page_title = 'Approval Centre';
$page_subtitle = 'Review queue for the approval chain (spec 5.2). Raise a review for a campaign; the BPT team, then BI/CRM, approve or reject it on the review page.';
require __DIR__ . '/../partials.php';
// Raise review opens approval/edit.php (no pop-up form).
// Absolute: header.php sets <base href="/odb/">, so a relative link would
// resolve to /odb/edit.php. EDM_BASE is only defined inside header.php.
$page_title_actions = '<a class="btn btn-primary d-inline-flex align-items-center" href="/odb/' . basename(dirname(__DIR__)) . '/approval/edit.php"><i class="bi bi-plus-lg me-1"></i>Raise review</a>';
include __DIR__ . '/../header.php';

$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Title', 'Campaign', 'Requested by', 'Stage', 'Status', 'Reviewer', 'Raised'),
));
?>
<script>
(function () {
    // Where the request is in the chain (Approval::STEP_BPT = 2,
    // STEP_AUDIENCE = 4). Decisions are made on the review page.
    // After BPT approval the latest automated QA run (qa_status: 1 queued,
    // 2 running, 5 failed) is shown alongside audience validation.
    function stage(r) {
        if (r.status === 2) { return 'Approved'; }
        if (r.status === 3) { return 'Returned to requester'; }
        if (r.step < 4) { return 'BPT review'; }
        if (r.qa_status === 5) { return 'QA failed'; }
        if (r.qa_status === 1 || r.qa_status === 2) { return 'QA running'; }
        return 'Audience validation (BI/CRM)';
    }

    window.EDM_CRUD_CONFIG = {
        api: 'approval/api.php',
        entity: 'review',
        noCreate: true,
        noEdit: true,
        canDelete: function (row) { return row.can_edit; },
        actions: { list: 'approvals_list', create: 'approvals_create', update: 'approvals_update', 'delete': 'approvals_delete' },
        badges: { status: {
            1: { cls: 'edm-pill-warning', label: 'Pending' },
            2: { cls: 'edm-pill-success', label: 'Approved' },
            3: { cls: 'edm-pill-danger', label: 'Rejected' }
        } },
        rowActions: [
            { label: 'Open', link: function (row) { return 'approval/edit.php?id=' + row.id; } }
        ],
        columns: [
            { key: 'title', label: 'Title', value: function (r) { return r.title || '-'; } },
            { key: 'campaign_name', label: 'Campaign' },
            { key: 'requested_by_name', label: 'Requested by', value: function (r) { return r.requested_by_name || '-'; } },
            { key: 'step', label: 'Stage', value: stage },
            { key: 'status', label: 'Status', type: 'badge' },
            { key: 'reviewer_name', label: 'Reviewer' },
            { key: 'created_at', label: 'Raised' }
        ],
        fields: []
    };
})();
</script>
<?php
include __DIR__ . '/../footer.php';
?>
