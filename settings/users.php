<?php
$page_title = 'Users & permissions';
include __DIR__ . '/../header.php';
require __DIR__ . '/../partials.php';

// Same rule as settings/api.php users_* (Auth::isSuperadmin): real
// superadmin only, never a dev-simulated role.
if (empty($_is_superadmin)) {
    if (function_exists('ob_get_level') && ob_get_level() > 0) { ob_end_clean(); }
    header('Location: ' . EDM_BASE . 'dashboard/index.php');
    exit;
}
$page_js = EDM_BASE . 'settings/users.js';

// staff.edm roles (CLAUDE.md "staff.edm").
$edm_roles = array(
    1 => array('label' => 'Superadmin', 'cls' => 'edm-pill-danger'),
    2 => array('label' => 'Admin', 'cls' => 'edm-pill-primary'),
    3 => array('label' => 'BPT team', 'cls' => 'edm-pill-info'),
    4 => array('label' => 'Management', 'cls' => 'edm-pill-secondary'),
);
?>
<!-- Markup, classes and font sizes follow atem/access_control/index.php. -->
<div class="row g-4 edm-users-page">

    <!-- Left: staff with EDM access -->
    <div class="col-md-7">
        <div class="edm-card edm-filter mb-3">
            <h6 class="edm-card-title"><i class="bi bi-funnel"></i> Filter</h6>
            <div class="row g-2 mt-1 align-items-end">
                <div class="col-md-4 col-sm-6">
                    <label class="form-label" for="edm-users-filter-name">Staff Name</label>
                    <input type="text" id="edm-users-filter-name" class="form-control form-control-sm" placeholder="Search name...">
                </div>
                <div class="col-md-4 col-sm-6">
                    <label class="form-label" for="edm-users-filter-role">Role</label>
                    <select id="edm-users-filter-role" class="form-select form-select-sm">
                        <option value="0">All Role</option>
                        <?php foreach ($edm_roles as $v => $r): ?>
                        <option value="<?php echo $v; ?>"><?php echo edm_h($r['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto d-flex align-items-end gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="edm-users-filter-reset">Reset</button>
                </div>
            </div>
        </div>

        <div id="edm-users-alert" class="alert alert-danger py-2 px-3 small" hidden></div>

        <div>
            <div class="table-responsive">
                <table class="table table-hover align-middle edm-view-tbl mb-0">
                    <thead>
                        <tr>
                            <th style="width:3rem;">#</th>
                            <th>Staff Name</th>
                            <th>Department</th>
                            <th>Role</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody id="edm-users-rows">
                        <tr><td colspan="5" class="text-center text-muted py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="edm-pager" id="edm-users-pager" style="margin-top:12px;padding-top:12px;border-top:1px solid #e9ecef;"></div>
        </div>
    </div>

    <!-- Right: add / update access -->
    <div class="col-md-5">
        <div>
            <p class="mb-3 text-muted edm-users-panel-label" id="edm-users-panel-title">Add / Update EDM Access</p>

            <div id="edm-users-form-alert" class="alert alert-dismissible fade show mb-3" role="alert" hidden>
                <span id="edm-users-form-alert-msg"></span>
                <button type="button" class="btn-close" id="edm-users-form-alert-close" aria-label="Close"></button>
            </div>

            <div class="mb-3 edm-users-search">
                <label class="form-label" for="edm-users-search">Staff Name</label>
                <input type="search" class="form-control" id="edm-users-search" placeholder="Type staff name to search..." autocomplete="off"
                       role="combobox" aria-expanded="false" aria-controls="edm-users-results" aria-autocomplete="list">
                <ul class="edm-users-results" id="edm-users-results" role="listbox" hidden></ul>
            </div>

            <div class="edm-users-info mb-3" id="edm-users-info" hidden>
                <p><strong>Name:</strong> <span id="edm-users-info-name"></span></p>
                <p><strong>Department:</strong> <span id="edm-users-info-dept"></span></p>
                <p><strong>Status:</strong> <span id="edm-users-info-status"></span></p>
                <p><strong>Current Role:</strong> <span id="edm-users-info-role"></span></p>
            </div>

            <div class="mb-3 edm-users-roles" id="edm-users-roles" hidden>
                <label class="form-label">Role</label>
                <?php foreach ($edm_roles as $v => $r): ?>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="edm-users-role" id="edm-users-role-<?php echo $v; ?>" value="<?php echo $v; ?>">
                    <label class="form-check-label" for="edm-users-role-<?php echo $v; ?>"><?php echo edm_h($r['label']); ?></label>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="edm-users-actions" hidden>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="edm-users-cancel">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" id="edm-users-save">Add Access</button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
window.EDM_USER_ROLES = <?php echo json_encode($edm_roles); ?>;
window.EDM_USER_SELF = <?php echo json_encode(isset($id_user) ? (int)$id_user : null); ?>;
</script>
<script src="<?php echo EDM_BASE; ?>js/edm-confirm.js"></script>
<?php
include __DIR__ . '/../footer.php';
?>
