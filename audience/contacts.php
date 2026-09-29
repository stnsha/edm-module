<?php
$page_title = 'Contacts';
$page_subtitle = 'Contacts on a list. Add contacts with Import (file or paste).';
require __DIR__ . '/../partials.php';
include __DIR__ . '/../header.php';
$page_js = EDM_BASE . 'js/edm-crud.js';

require __DIR__ . '/../app/bootstrap.php';
$edm_contacts_lists = \Edm\Models\ContactList::all();
$edm_contacts_list = isset($_GET['list']) ? (int)$_GET['list'] : 0;
if ($edm_contacts_lists && !in_array($edm_contacts_list, array_map(fn ($l) => (int)$l['id'], $edm_contacts_lists), true)) {
    $edm_contacts_list = (int)$edm_contacts_lists[0]['id'];
}
?>
<div class="d-flex align-items-end flex-wrap gap-2 mb-3">
<div style="width:420px; max-width:100%;">
    <label class="form-label" for="edm-contacts-list">List</label>
    <select class="form-select" id="edm-contacts-list">
        <?php foreach ($edm_contacts_lists as $l): ?>
        <option value="<?php echo (int)$l['id']; ?>"<?php echo (int)$l['id'] === $edm_contacts_list ? ' selected' : ''; ?>><?php echo htmlspecialchars($l['name']); ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (!$edm_contacts_lists): ?>
    <div class="form-text">No lists yet - <a href="<?php echo EDM_BASE; ?>audience/index.php">create a list</a> first.</div>
    <?php endif; ?>
</div>
<?php if ($edm_contacts_lists): ?>
<a class="btn btn-outline-primary" href="<?php echo EDM_BASE; ?>audience/import.php?list=<?php echo (int)$edm_contacts_list; ?>"><i class="bi bi-upload me-1"></i>Import contacts</a>
<?php endif; ?>
</div>
<?php
edm_crud_screen(array(
    'columns' => array('Email', 'Name', 'Member code', 'Status', 'Source', 'Subscribed'),
));
?>
<script>
document.getElementById('edm-contacts-list').addEventListener('change', function () {
    window.location.href = (window.EDM_MODULE_BASE || '/odb/edm/') + 'audience/contacts.php?list=' + encodeURIComponent(this.value);
});
window.EDM_CRUD_CONFIG = {
    api: 'audience/api.php',
    entity: 'contact',
    actions: { list: 'members_list', 'delete': 'members_delete' },
    listParams: { list_id: <?php echo (int)$edm_contacts_list; ?> },
    noCreate: true,
    noEdit: true,
    columns: [
        { key: 'email', label: 'Email' },
        { key: 'name', label: 'Name' },
        { key: 'member_code', label: 'Member code' },
        { key: 'status', label: 'Status', type: 'badge' },
        { key: 'source', label: 'Source' },
        { key: 'subscribed_at', label: 'Subscribed' }
    ],
    badges: {
        status: {
            1: { cls: 'edm-pill-success', label: 'Subscribed' },
            2: { cls: 'edm-pill-secondary', label: 'Unsubscribed' },
            3: { cls: 'edm-pill-danger', label: 'Bounced' }
        }
    },
    fields: []
};
</script>
<?php
include __DIR__ . '/../footer.php';
?>
