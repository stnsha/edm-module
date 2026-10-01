<?php
$page_title = 'Segments';
$page_subtitle = 'Saved groups of contacts, picked by conditions on their details, custom fields and email engagement, combined with AND / OR across condition groups. Choose one on a campaign to send only to the contacts on its list who match.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New segment');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

require __DIR__ . '/../app/bootstrap.php';
$edm_segment_lists = array(array('value' => '', 'label' => 'All lists'));
foreach (\Edm\Models\ContactList::all() as $row) {
    $edm_segment_lists[] = array('value' => $row['id'], 'label' => $row['name']);
}
$edm_segment_catalog = \Edm\Services\SegmentQuery::catalog();

edm_crud_screen(array(
    'columns'    => array('Name', 'List', 'Conditions', 'Contacts'),
    'modal_size' => 'modal-lg',
));
?>
<script>
window.EDM_CRUD_CONFIG = {
    api: 'audience/api.php',
    entity: 'segment',
    actions: { list: 'segments_list', create: 'segments_create', update: 'segments_update', 'delete': 'segments_delete' },
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'list_name', label: 'List', value: function (r) { return r.list_name || 'All lists'; } },
        { key: 'summary', label: 'Conditions' },
        { key: 'matched', label: 'Contacts', value: function (r) {
            return r.matched === null ? 'Needs fixing' : Number(r.matched).toLocaleString('en-US');
        } }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'description', label: 'Description', type: 'textarea' },
        { name: 'list_id', label: 'List', type: 'select', options: <?php echo json_encode($edm_segment_lists); ?>,
          help: 'Optional. A segment on one list is offered only for campaigns sent to that list.' },
        { name: 'definition', label: 'Conditions', type: 'rules', required: true,
          catalog: <?php echo json_encode($edm_segment_catalog); ?>,
          count: { action: 'segments_count', listField: 'list_id' } }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
