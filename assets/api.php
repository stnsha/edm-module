<?php
/**
 * Files (Asset Library) page API. Proxies to edm-api edm/assets over JWT.
 * Binary upload is a later feature; this stores metadata and a URL.
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

$response = array('success' => false, 'message' => 'Unknown action');

if (preg_match('/^assets_(list|create|update|delete)$/', (string)$action, $m)) {
    $verb    = $m[1];
    $payload = edmPick($input, array('name', 'url', 'type'));
    if ($verb === 'create') {
        $payload['uploaded_by']      = $staff_id;
        $payload['uploaded_by_name'] = edmStaffName($staff_id);
    }
    $response = edmCrud($verb, 'edm/assets', $payload, $input, $staff_id, 'file');
}

echo json_encode($response);
exit;
