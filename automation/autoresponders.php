<?php
$page_title = 'Autoresponders';
$page_subtitle = 'Timed emails that make up a journey: each step belongs to one journey and is sent a number of days from its trigger.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New autoresponder');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

require __DIR__ . '/../app/bootstrap.php';
$edm_journeys = array(array('value' => '', 'label' => 'None'));
$edm_journey_names = array();
foreach (\Edm\Models\Workflow::all() as $row) {
    $edm_journeys[] = array('value' => $row['id'], 'label' => $row['name']);
    $edm_journey_names[$row['id']] = $row['name'];
}

edm_crud_screen(array(
    'columns' => array('Journey', 'Name', 'Offset (days)', 'Subject', 'Status'),
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'automation/api.php',
    entity: 'autoresponder',
    actions: { list: 'autoresponders_list', create: 'autoresponders_create', update: 'autoresponders_update', 'delete': 'autoresponders_delete' },
    badges: { status: {
        1: { cls: 'edm-pill-secondary', label: 'Draft' },
        2: { cls: 'edm-pill-success', label: 'Active' },
        3: { cls: 'edm-pill-warning', label: 'Paused' }
    } },
    columns: [
        { key: 'workflow_id', label: 'Journey', value: function (r) {
            return (<?php echo json_encode((object) $edm_journey_names); ?>)[r.workflow_id] || '';
        } },
        { key: 'name', label: 'Name' },
        { key: 'offset_days', label: 'Offset (days)' },
        { key: 'subject', label: 'Subject' },
        { key: 'status', label: 'Status', type: 'badge' }
    ],
    fields: [
        { name: 'workflow_id', label: 'Journey', type: 'select', options: <?php echo json_encode($edm_journeys); ?> },
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'offset_days', label: 'Offset in days from trigger', type: 'number', required: true, default: 0 },
        { name: 'subject', label: 'Subject', type: 'text' },
        { name: 'status', label: 'Status', type: 'select', options: [
            { value: 1, label: 'Draft' }, { value: 2, label: 'Active' }, { value: 3, label: 'Paused' }
        ] }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
