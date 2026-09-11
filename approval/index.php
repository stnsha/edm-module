<?php
$page_title = 'Approval Centre';
$page_subtitle = 'Review queue for the approval chain (spec 5.2). Raise a review step for a newsletter, then approve or reject with a comment.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('Raise review');
include __DIR__ . '/../header.php';

define('API_JWT_INCLUDED', true);
require __DIR__ . '/../api-jwt.php';
require __DIR__ . '/../api-proxy.php';

$edm_campaign_opts = array();
$_c = edmApiResult(getApiDataWithJWT('edm/campaigns', null, 'GET', $staff_id), '');
if (!empty($_c['success']) && is_array($_c['data'])) {
    foreach ($_c['data'] as $row) {
        $edm_campaign_opts[] = array('value' => $row['id'], 'label' => '#' . $row['id'] . ' ' . $row['name']);
    }
}
if (!$edm_campaign_opts) {
    $edm_campaign_opts[] = array('value' => '', 'label' => '(no newsletters yet)');
}

$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns' => array('Newsletter', 'Step', 'Status', 'Reviewer', 'Comment', 'Raised'),
));
?>
<script>
(function () {
    var BASE = window.EDM_MODULE_BASE || '/odb/edm/';
    function decide(row, status, reload) {
        var comment = window.prompt((status === 'approved' ? 'Approve' : 'Reject') + ' - comment (optional):', '');
        if (comment === null) { return; }
        fetch(BASE + 'approval/api.php?action=approvals_decide', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ id: row.id, status: status, comment: comment })
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.success) { window.alert(res.message || 'Failed.'); return; }
            reload();
        });
    }
    window.EDM_CRUD_CONFIG = {
        api: 'approval/api.php',
        entity: 'review',
        noEdit: true,
        actions: { list: 'approvals_list', create: 'approvals_create', update: 'approvals_update', 'delete': 'approvals_delete' },
        badges: { status: { pending: 'edm-pill-warning', approved: 'edm-pill-success', rejected: 'edm-pill-danger' } },
        rowActions: [
            { label: 'Approve', className: 'btn-outline-success', handler: function (row, reload) { decide(row, 'approved', reload); } },
            { label: 'Reject', className: 'btn-outline-danger', handler: function (row, reload) { decide(row, 'rejected', reload); } }
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
            { name: 'campaign_id', label: 'Newsletter', type: 'select', options: <?php echo json_encode($edm_campaign_opts); ?> },
            { name: 'step', label: 'Approval step (1-8)', type: 'number', default: 1 },
            { name: 'comment', label: 'Note', type: 'text' }
        ]
    };
})();
</script>
<?php
include __DIR__ . '/../footer.php';
?>
