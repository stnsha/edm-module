<?php
/**
 * EDM top navigation. Styling follows the atem module (renamed to the edm-
 * prefix; see css/style.css). Menu items are gated by the staff.edm tier.
 */

// Resolve role first so it can gate the dev toolbar and the menu.
$edm_role = 0;
if (isset($edm_permission)) {
    $edm_role = (int)$edm_permission;
}

// Menu visibility gates by tier (see sql/add_staff_edm_column.sql and SPEC.md).
$edm_can_build = in_array($edm_role, array(1, 2, 3), true); // superadmin / admin / bpt
$edm_can_view  = ($edm_role >= 1);                          // includes management (read-only)
$edm_is_super  = ($edm_role === 1) || !empty($_is_superadmin);

// Dev role switcher toolbar (localhost + real superadmin only)
$_navbar_serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
$_navbar_httpHost   = isset($_SERVER['HTTP_HOST'])   ? $_SERVER['HTTP_HOST']   : '';
$_navbar_isLocal    = in_array($_navbar_serverName, array('localhost', '127.0.0.1'))
    || strpos($_navbar_serverName, 'localhost') !== false
    || strpos($_navbar_httpHost,   'localhost') !== false
    || strpos($_navbar_httpHost,   '127.0.0.1') !== false;

$_navbar_isRealSuperAdmin = isset($edm_flag) && (int)$edm_flag === 1;

if ($_navbar_isLocal && $_navbar_isRealSuperAdmin) {
    if (session_id() == '') {
        session_start();
    }

    // staff.edm role levels (see sql/add_staff_edm_column.sql).
    $_navbar_roleLabels = array(
        1 => 'superadmin',
        2 => 'admin',
        3 => 'bpt team',
        4 => 'management',
    );

    $_navbar_activeRole      = isset($_SESSION['edm_dev_role_override']) ? (int)$_SESSION['edm_dev_role_override'] : null;
    $_navbar_activeRoleLabel = ($_navbar_activeRole !== null && isset($_navbar_roleLabels[$_navbar_activeRole]))
        ? $_navbar_roleLabels[$_navbar_activeRole]
        : 'DB Default';
    $_navbar_currentUri      = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : EDM_BASE . 'dashboard/index.php';
?>
<div
    style="background:#12122a;color:#d0d0f0;padding:5px 14px;font-size:11px;font-family:monospace;display:flex;align-items:center;gap:10px;flex-wrap:wrap;border-bottom:1px solid #333;">
    <span style="color:#888;letter-spacing:.05em;">DEV ROLE</span>
    <strong style="color:#f0c040;">[<?php echo htmlspecialchars($_navbar_activeRoleLabel); ?>]</strong>
    <?php foreach ($_navbar_roleLabels as $_r => $_label): ?>
    <form method="POST" action="<?php echo EDM_BASE; ?>dev-switch-role.php" style="display:inline;margin:0;">
        <input type="hidden" name="role" value="<?php echo $_r; ?>">
        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_navbar_currentUri); ?>">
        <button type="submit"
            style="background:<?php echo ($_navbar_activeRole === $_r ? '#2e2e6e' : '#1e1e3e'); ?>;color:<?php echo ($_navbar_activeRole === $_r ? '#f0c040' : '#aaa'); ?>;border:1px solid <?php echo ($_navbar_activeRole === $_r ? '#555' : '#333'); ?>;padding:2px 7px;font-size:11px;cursor:pointer;border-radius:3px;font-family:monospace;"><?php echo $_r; ?>:
            <?php echo $_label; ?></button>
    </form>
    <?php endforeach; ?>
    <?php if ($_navbar_activeRole !== null): ?>
    <form method="POST" action="<?php echo EDM_BASE; ?>dev-switch-role.php" style="display:inline;margin:0;">
        <input type="hidden" name="role" value="clear">
        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_navbar_currentUri); ?>">
        <button type="submit"
            style="background:#3a1010;color:#ff8888;border:1px solid #a44;padding:2px 7px;font-size:11px;cursor:pointer;border-radius:3px;font-family:monospace;">Clear
            Override</button>
    </form>
    <?php endif; ?>
</div>
<?php } ?>

<?php
// Current page identity. One page = one folder, so the folder name is the module
// identity and drives the active nav state; the script basename disambiguates
// sibling screens that share a folder (atem-style, e.g. audience/segments.php).
$_self          = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
$current_dir    = basename(dirname($_self));
$current_script = basename($_self);

/**
 * GetResponse-parity navigation.
 *
 * The marketing team is moving off GetResponse; this menu mirrors its grouping
 * and wording so the layout stays familiar. Folder names on disk remain
 * spec-aligned (campaign/, audience/, reporting/, assets/ ...) - only the
 * display label is decoupled here, via this map.
 *
 * Each entry:
 *   label - text shown in the bar
 *   gate  - 'build' (edm in 1,2,3), 'view' (edm >= 1), or 'super' (edm == 1)
 *   href    - single-link target relative to EDM_BASE; omit when 'items' is set
 *   folders - optional extra folders that also mark a single link active
 *   items   - dropdown children: array of (folder, file, label)
 */
$edm_nav = array(
    array(
        'label' => 'Dashboard',
        'gate'  => 'view',
        'href'  => 'dashboard/index.php',
    ),
    array(
        'label' => 'Contacts',
        'gate'  => 'build',
        'items' => array(
            array('folder' => 'audience',    'file' => 'index.php',    'label' => 'Lists'),
            array('folder' => 'audience',    'file' => 'segments.php', 'label' => 'Segments'),
            array('folder' => 'audience',    'file' => 'fields.php',   'label' => 'Custom fields'),
            array('folder' => 'audience',    'file' => 'tags.php',     'label' => 'Tags & scoring'),
            array('folder' => 'suppression', 'file' => 'index.php',    'label' => 'Suppression lists'),
        ),
    ),
    array(
        'label'   => 'Newsletters',
        'gate'    => 'build',
        'href'    => 'campaign/index.php',
        // Email creator has no menu entry of its own; it is reached from the
        // Newsletters list (Design button) and keeps this item highlighted.
        'folders' => array('email-builder'),
    ),
    array(
        'label' => 'Automation',
        'gate'  => 'build',
        'items' => array(
            array('folder' => 'automation', 'file' => 'index.php',          'label' => 'Workflows'),
            array('folder' => 'automation', 'file' => 'autoresponders.php', 'label' => 'Autoresponders'),
        ),
    ),
    array(
        'label' => 'Calendar',
        'gate'  => 'view',
        'href'  => 'calendar/index.php',
    ),
    array(
        'label' => 'Statistics',
        'gate'  => 'view',
        'href'  => 'reporting/index.php',
    ),
    array(
        'label' => 'Templates',
        'gate'  => 'build',
        'href'  => 'templates/index.php',
    ),
    array(
        'label' => 'Files',
        'gate'  => 'build',
        'href'  => 'assets/index.php',
    ),
    array(
        'label' => 'Approval',
        'gate'  => 'build',
        'href'  => 'approval/index.php',
    ),
    array(
        'label' => 'Settings',
        'gate'  => 'super',
        'items' => array(
            array('folder' => 'settings', 'file' => 'senders.php',      'label' => 'Senders'),
            array('folder' => 'settings', 'file' => 'domains.php',      'label' => 'Sending domains'),
            array('folder' => 'settings', 'file' => 'users.php',        'label' => 'Users & permissions'),
            array('folder' => 'settings', 'file' => 'integrations.php', 'label' => 'Integrations & API'),
            array('folder' => 'settings', 'file' => 'index.php',        'label' => 'General'),
        ),
    ),
);

$edm_gate_ok = array(
    'build' => $edm_can_build,
    'view'  => $edm_can_view,
    'super' => $edm_is_super,
);
?>
<nav class="edm-nav navbar navbar-expand-lg navbar-light mb-3">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
            aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <i class="bi bi-list"></i>
        </button>
        <div class="collapse navbar-collapse w-100" id="navbarNav">
            <ul class="navbar-nav align-items-lg-center w-100">
                <?php foreach ($edm_nav as $_item): ?>
                <?php if (empty($edm_gate_ok[$_item['gate']])) { continue; } ?>
                <?php if (isset($_item['items'])): ?>
                <?php
                    $_child_folders = array();
                    foreach ($_item['items'] as $_c) {
                        $_child_folders[$_c['folder']] = true;
                    }
                    $_group_active = isset($_child_folders[$current_dir]) ? 'active' : '';
                ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo $_group_active; ?>" href="#" role="button"
                        data-bs-toggle="dropdown" aria-expanded="false"><?php echo htmlspecialchars($_item['label']); ?></a>
                    <ul class="dropdown-menu">
                        <?php foreach ($_item['items'] as $_c): ?>
                        <?php $_child_active = ($current_dir === $_c['folder'] && $current_script === $_c['file']) ? 'active' : ''; ?>
                        <li>
                            <a class="dropdown-item <?php echo $_child_active; ?>"
                                href="<?php echo EDM_BASE . $_c['folder'] . '/' . $_c['file']; ?>"><?php echo htmlspecialchars($_c['label']); ?></a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <?php else: ?>
                <?php
                    $_slash          = strpos($_item['href'], '/');
                    $_href_folder    = ($_slash !== false) ? substr($_item['href'], 0, $_slash) : $_item['href'];
                    $_extra_folders  = isset($_item['folders']) ? $_item['folders'] : array();
                    $_single_active  = ($current_dir === $_href_folder || in_array($current_dir, $_extra_folders, true)) ? 'active' : '';
                ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $_single_active; ?>"
                        href="<?php echo EDM_BASE . $_item['href']; ?>"><?php echo htmlspecialchars($_item['label']); ?></a>
                </li>
                <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</nav>
