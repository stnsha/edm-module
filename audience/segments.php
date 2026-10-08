<?php
$page_title = 'Segments';
$page_subtitle = 'Saved groups of contacts, picked by conditions on their details, custom fields and email engagement, combined with AND / OR across condition groups. Choose one on a newsletter to send only to the subscribed contacts who match.';
require __DIR__ . '/../partials.php';
$page_title_actions = edm_title_button('New segment');
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

require __DIR__ . '/../app/bootstrap.php';
$edm_segment_catalog = \Edm\Services\SegmentQuery::catalog();

edm_crud_screen(array(
    'columns'    => array('Name', 'Conditions', 'Contacts'),
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
        { key: 'summary', label: 'Conditions' },
        { key: 'matched', label: 'Contacts', value: function (r) {
            return r.matched === null ? 'Needs fixing' : Number(r.matched).toLocaleString('en-US');
        } }
    ],
    fields: [
        { name: 'name', label: 'Name', type: 'text', required: true },
        { name: 'description', label: 'Description', type: 'textarea' },
        { name: 'definition', label: 'Conditions', type: 'rules', required: true,
          catalog: <?php echo json_encode($edm_segment_catalog); ?>,
          count: { action: 'segments_count' } }
    ]
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
