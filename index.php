<?php
/**
 * Default EDM entry point. The dashboard is the default page, so redirect
 * there. Access control / auth is enforced by dashboard/index.php -> header.php.
 */
if (!defined('EDM_BASE')) {
    define('EDM_BASE', '/odb/' . basename(__DIR__) . '/');
}
header('Location: ' . EDM_BASE . 'dashboard/index.php');
exit;
