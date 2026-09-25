<?php
/**
 * EDM base layout - top partial. Styling follows the atem module strictly
 * (same css/style.css, same class names, same header markup).
 *
 * A page includes this after optionally setting:
 *   $page_title          - shown in <title> and as the page heading (default "EDM")
 *   $page_title_actions   - optional HTML rendered on the right of the page heading
 *   $page_hide_title      - true to skip the heading row (page renders its own bar;
 *                           $page_title still sets <title>)
 *   $extra_css            - optional extra <link>/<style> markup for <head>
 *   $page_js              - optional page-specific script path, emitted by footer.php
 *
 * Include paths are resolved from this file's directory with dirname(__FILE__)
 * so the partial works regardless of the including page's depth.
 */
ob_start();
$page_title = isset($page_title) ? $page_title : 'EDM';

// Absolute base URL for this module's own pages/assets, e.g. "/odb/edm/".
// Derived from this file's own folder name so every in-module link, redirect,
// and asset reference follows this module's actual location.
if (!defined('EDM_BASE')) {
    define('EDM_BASE', '/odb/' . basename(dirname(__FILE__)) . '/');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo EDM_BASE; ?>css/logo.svg">
    <base href="/odb/">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo EDM_BASE; ?>css/style.css?v=<?php echo time(); ?>" rel="stylesheet">
    <?php if (isset($extra_css)) echo $extra_css; ?>
</head>
<?php
require_once(dirname(__FILE__) . '/../lock_adv.php');
$connect = 1;
include(dirname(__FILE__) . '/../common/index_adv.php');

// staff.edm role level for the logged-in user. lock_adv.php does not expose
// this column, so it is queried directly here (mirrors atem header.php's
// separate okr flag query).
$edm_flag = 0;
if (isset($id_user)) {
    $_edm_check = mysqli_query($conn, 'SELECT edm FROM staff WHERE id = ' . (int)$id_user);
    if ($_edm_check && ($_edm_row = mysqli_fetch_assoc($_edm_check))) {
        $edm_flag = (int)$_edm_row['edm'];
    }
}

// Dev role override (localhost only, toggled via dev-switch-role.php).
$edm_permission = $edm_flag;
if (isset($_SESSION['edm_dev_role_override'])) {
    $edm_permission = (int)$_SESSION['edm_dev_role_override'];
}

// Real superadmin only - a dev override never grants superadmin.
$_is_superadmin = (!isset($_SESSION['edm_dev_role_override']) && $edm_flag === 1);

if ((int)$edm_permission === 0 && !$_is_superadmin) {
    ob_end_clean();
    if (isset($_SESSION['edm_dev_role_override'])) {
        unset($_SESSION['edm_dev_role_override']);
        header('Location: ' . EDM_BASE . 'dashboard/index.php');
    } else {
        header('Location: /odb/index.php');
    }
    exit;
}
?>

<body>
    <?php include(dirname(__FILE__) . '/navbar.php'); ?>
    <?php include(dirname(__FILE__) . '/getresponse-help.php'); ?>
    <div class="header" style="position: relative;">
        <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
        <h1 class="headerH1"><img src='<?php echo EDM_BASE; ?>css/logo.svg' width='20px'>EDM</h1>
        <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
    </div>
    <div class="edm-container mb-3">

        <?php if (empty($page_hide_title)): /* a page with its own heading bar sets $page_hide_title = true */ ?>
        <div class="row mb-4">
            <div class="col-12 d-flex align-items-start justify-content-between flex-wrap gap-2">
                <div>
                    <h1 class="edm-page-title mb-0">
                        <?php echo htmlspecialchars($page_title); ?><?php echo isset($page_title_badge) ? ' ' . $page_title_badge : ''; ?>
                    </h1>
                    <?php if (!empty($page_subtitle)): ?>
                    <p class="text-muted small mb-0 mt-1"><?php echo htmlspecialchars($page_subtitle); ?></p>
                    <?php endif; ?>
                </div>
                <?php if (!empty($page_title_actions)): ?>
                <div><?php echo $page_title_actions; ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
