<?php
/**
 * Settings page API. Per-page action router (project rule: one page, one folder).
 * Proxies to edm-api over JWT for module-owned tables; the Users screen reads
 * and writes the local odb `staff.edm` tier directly (that column is not owned
 * by edm-api).
 *
 * Screens served: senders.php, domains.php, integrations.php, users.php,
 * index.php (General).
 */
define('API_JWT_INCLUDED', true);
require __DIR__ . '/../api-jwt.php';
require __DIR__ . '/../api-proxy.php';

header('Content-Type: application/json');

$input  = edmReadBody();
$action = edmResolveAction($input);

if (!$staff_id) {
    echo json_encode(array('success' => false, 'message' => 'Staff ID is required. Please ensure you are logged in.'));
    exit;
}

/**
 * Guard: Settings is superadmin only. `$is_api_superadmin` is resolved by
 * api-jwt.php (real staff.edm === 1, and never under a dev role override).
 * @return bool
 */
function edmSettingsIsSuper()
{
    return !empty($GLOBALS['is_api_superadmin']);
}

$response = array('success' => false, 'message' => 'Unknown action');

// --- Senders (edm/senders) ---------------------------------------------------
if (preg_match('/^senders_(list|create|update|delete)$/', (string)$action, $m)) {
    $verb    = $m[1];
    $payload = edmPick($input, array('email', 'from_name', 'reply_to'));
    if (array_key_exists('is_default', $input)) {
        $payload['is_default'] = !empty($input['is_default']);
    }
    if ($verb === 'create') {
        $payload['created_by']      = $staff_id;
        $payload['created_by_name'] = edmStaffName($staff_id);
    }
    $response = edmCrud($verb, 'edm/senders', $payload, $input, $staff_id, 'sender');

} elseif ($action === 'senders_verify') {
    $id = edmReqId($input);
    $status = isset($input['status']) ? $input['status'] : '';
    if (!$id || !in_array($status, array('pending', 'verified', 'failed'), true)) {
        $response = array('success' => false, 'message' => 'Sender id and a valid status are required');
    } else {
        $response = edmApiResult(
            getApiDataWithJWT('edm/senders/' . $id . '/verify', array('status' => $status), 'POST', $staff_id),
            'Failed to update sender status'
        );
    }

// --- Sending domains (edm/sending-domains) ----------------------------------
} elseif (preg_match('/^domains_(list|create|update|delete)$/', (string)$action, $m)) {
    $verb    = $m[1];
    $payload = edmPick($input, array('domain', 'dkim_status', 'spf_status', 'dmarc_status'));
    if (array_key_exists('is_active', $input)) {
        $payload['is_active'] = !empty($input['is_active']);
    }
    $response = edmCrud($verb, 'edm/sending-domains', $payload, $input, $staff_id, 'domain');

// --- Settings KV, grouped (edm/settings) ----------------------------------
} elseif (preg_match('/^(integrations|general)_(list|create|update|delete)$/', (string)$action, $m)) {
    $group = $m[1];
    $verb  = $m[2];

    if ($verb === 'list') {
        $response = edmApiResult(
            getApiDataWithJWT('edm/settings?group=' . urlencode($group), null, 'GET', $staff_id),
            'Failed to load settings'
        );
    } else {
        $payload = edmPick($input, array('key', 'label', 'value'));
        if ($verb === 'create') {
            $payload['group'] = $group;
        }
        $response = edmCrud($verb, 'edm/settings', $payload, $input, $staff_id, 'setting');
    }

// --- Users & permissions (local staff.edm tier) --------------------------
} elseif ($action === 'users_list' || $action === 'users_update') {
    global $conn;
    if (!edmSettingsIsSuper()) {
        $response = array('success' => false, 'message' => 'Superadmin only.');
    } elseif ($action === 'users_list') {
        $rows = array();
        $res  = mysqli_query($conn, 'SELECT id, nama_staff, edm FROM staff WHERE edm > 0 ORDER BY nama_staff');
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = array('id' => (int)$r['id'], 'nama_staff' => $r['nama_staff'], 'edm' => (int)$r['edm']);
            }
            $response = array('success' => true, 'data' => $rows);
        } else {
            $response = array('success' => false, 'message' => 'Query failed: ' . mysqli_error($conn));
        }
    } else {
        $id  = edmReqId($input);
        $tier = isset($input['edm']) ? (int)$input['edm'] : -1;
        if (!$id || $tier < 0 || $tier > 4) {
            $response = array('success' => false, 'message' => 'A staff id and a tier 0-4 are required');
        } else {
            $stmt = mysqli_prepare($conn, 'UPDATE staff SET edm = ? WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'ii', $tier, $id);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $response = $ok
                ? array('success' => true, 'data' => array('id' => $id, 'edm' => $tier))
                : array('success' => false, 'message' => 'Update failed: ' . mysqli_error($conn));
        }
    }
}

echo json_encode($response);
exit;
