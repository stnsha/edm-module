<?php
$page_title = 'Approval Centre';
$page_subtitle = 'Review queue for the approval chain (spec 5.2). Raise a review step for a newsletter, then approve or reject with a comment.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('Raise review');
include __DIR__ . '/../header.php';

require __DIR__ . '/../app/bootstrap.php';

$edm_campaign_opts = array();
foreach (\Edm\Models\Campaign::all() as $row) {
    $edm_campaign_opts[] = array('value' => $row['id'], 'label' => '#' . $row['id'] . ' ' . $row['name']);
}
if (!$edm_campaign_opts) {
    $edm_campaign_opts[] = array('value' => '', 'label' => '(no newsletters yet)');
}

$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Newsletter', 'Step', 'Status', 'Reviewer', 'Comment', 'Raised'),
));
?>
<div class="modal fade" id="edm-decide-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="edm-decide-title">Decision</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="edm-decide-comment">Comment</label>
                <textarea class="form-control" id="edm-decide-comment" rows="3"></textarea>
                <div id="edm-decide-error" class="text-danger small mt-2" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm" id="edm-decide-confirm">Confirm</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';

    var decideModalEl  = document.getElementById('edm-decide-modal');
    var decideModal    = null; // lazy - bootstrap.bundle loads in footer.php, after this script
    var decideTitle    = document.getElementById('edm-decide-title');
    var decideComment  = document.getElementById('edm-decide-comment');
    var decideError    = document.getElementById('edm-decide-error');
    var decideConfirm  = document.getElementById('edm-decide-confirm');
    var pendingDecide  = null;

    function decide(row, status, reload) {
        if (!decideModal) { decideModal = new bootstrap.Modal(decideModalEl); }
        pendingDecide = { row: row, status: status, reload: reload };
        decideTitle.textContent = (status === 2 ? 'Approve' : 'Reject') + ' review';
        decideComment.value = '';
        decideError.hidden = true;
        decideConfirm.className = 'btn btn-sm ' + (status === 2 ? 'btn-success' : 'btn-danger');
        decideConfirm.textContent = status === 2 ? 'Approve' : 'Reject';
        decideModal.show();
    }

    decideConfirm.addEventListener('click', function () {
        if (!pendingDecide) { return; }
        var p = pendingDecide;
        decideError.hidden = true;
        fetch(BASE + 'approval/api.php?action=approvals_decide', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ id: p.row.id, status: p.status, comment: decideComment.value })
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.success) { decideError.textContent = res.message || 'Failed.'; decideError.hidden = false; return; }
            decideModal.hide();
            p.reload();
        }).catch(function () {
            decideError.textContent = 'Could not reach the server.';
            decideError.hidden = false;
        });
    });

    window.EDM_CRUD_CONFIG = {
        api: 'approval/api.php',
        entity: 'review',
        noEdit: true,
        actions: { list: 'approvals_list', create: 'approvals_create', update: 'approvals_update', 'delete': 'approvals_delete' },
        badges: { status: {
            1: { cls: 'edm-pill-warning', label: 'Pending' },
            2: { cls: 'edm-pill-success', label: 'Approved' },
            3: { cls: 'edm-pill-danger', label: 'Rejected' }
        } },
        rowActions: [
            { label: 'Approve', className: 'btn-outline-success', visible: function (row) { return row.status === 1; }, handler: function (row, reload) { decide(row, 2, reload); } },
            { label: 'Reject', className: 'btn-outline-danger', visible: function (row) { return row.status === 1; }, handler: function (row, reload) { decide(row, 3, reload); } }
        ],
        columns: [
            { key: 'campaign_name', label: 'Newsletter' },
            { key: 'step', label: 'Step' },
            { key: 'status', label: 'Status', type: 'badge' },
            { key: 'reviewer_name', label: 'Reviewer' },
            { key: 'comment', label: 'Comment' },
            { key: 'created_at', label: 'Raised' }
        ],
        fields: [
            { name: 'campaign_id', label: 'Newsletter', type: 'select', required: true, options: <?php echo json_encode($edm_campaign_opts); ?> },
            { name: 'step', label: 'Approval step (1-8)', type: 'number', required: true, default: 1 },
            { name: 'comment', label: 'Note', type: 'text' }
        ]
    };
})();
</script>
<?php
include __DIR__ . '/../footer.php';
?>
