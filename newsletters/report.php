<?php
// The newsletter report moved to the Dashboard (dashboard/index.php,
// ?newsletter=<id>). Kept so saved links and bookmarks still open it.
$report_id = isset($_GET['newsletter']) ? (int)$_GET['newsletter'] : 0;
header('Location: ../dashboard/index.php' . ($report_id ? '?newsletter=' . $report_id : ''), true, 302);
exit;
