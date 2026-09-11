<?php
$page_title = 'Newsletters';
$page_subtitle = 'One-time email campaigns. Create a draft here, design it in the Email creator, then submit for review.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New newsletter');
include __DIR__ . '/../header.php';

// Server-side lookups for the sender / list selects.
define('API_JWT_INCLUDED', true);
require __DIR__ . '/../api-jwt.php';
require __DIR__ . '/../api-proxy.php';

$edm_sender_opts = array(array('value' => '', 'label' => '(none)'));
$edm_list_opts   = array(array('value' => '', 'label' => '(none)'));

$_s = edmApiResult(getApiDataWithJWT('edm/senders', null, 'GET', $staff_id), '');
if (!empty($_s['success']) && is_array($_s['data'])) {
    foreach ($_s['data'] as $row) {
        $edm_sender_opts[] = array('value' => $row['id'], 'label' => $row['from_name'] . ' <' . $row['email'] . '>');
    }
}
$_l = edmApiResult(getApiDataWithJWT('edm/lists', null, 'GET', $staff_id), '');
if (!empty($_l['success']) && is_array($_l['data'])) {
    foreach ($_l['data'] as $row) {
        $edm_list_opts[] = array('value' => $row['id'], 'label' => $row['name']);
    }
}

$page_js = EDM_BASE . 'js/edm-crud.js';

edm_crud_screen(array(
    'columns'    => array('Name', 'Subject', 'Status', 'Scheduled'),
    'modal_size' => 'modal-lg',
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'campaign/api.php',
    entity: 'newsletter',
    actions: { list: 'campaigns_list', create: 'campaigns_create', update: 'campaigns_update', 'delete': 'campaigns_delete' },
    badges: { status: {
        draft: 'edm-pill-secondary', pending_submission: 'edm-pill-info', under_bpt_review: 'edm-pill-info',
        content_revision: 'edm-pill-warning', audience_validation: 'edm-pill-info', scheduled: 'edm-pill-primary',
        sending: 'edm-pill-primary', completed: 'edm-pill-success', archived: 'edm-pill-dark'
    } },
    rowActions: [
        { label: 'Design', className: 'btn-outline-primary', link: function (r) { return 'email-builder/index.php?campaign=' + r.id; } },
        { label: 'Submit', className: 'btn-outline-success', action: 'campaigns_submit', confirm: 'Submit this newsletter for review?',
            visible: function (r) { return r.status === 'draft' || r.status === 'content_revision'; } }
    ],
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'subject', label: 'Subject' },
        { key: 'status', label: 'Status', type: 'badge' },
        { key: 'scheduled_at', label: 'Scheduled' }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'subject', label: 'Subject line', type: 'text' },
        { name: 'subject_b', label: 'Subject line B (A/B test)', type: 'text' },
        { name: 'preheader', label: 'Preheader', type: 'text' },
        { name: 'sender_id', label: 'Sender', type: 'select', options: <?php echo json_encode($edm_sender_opts); ?> },
        { name: 'list_id', label: 'Recipient list', type: 'select', options: <?php echo json_encode($edm_list_opts); ?> },
        { name: 'scheduled_at', label: 'Scheduled send', type: 'date' }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
