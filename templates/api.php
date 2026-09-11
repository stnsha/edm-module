<?php
/**
 * Template Library page API. Proxies to edm-api edm/templates over JWT.
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

if (preg_match('/^templates_(list|create|update|delete)$/', (string)$action, $m)) {
    $verb    = $m[1];
    $payload = edmPick($input, array('name', 'category', 'thumbnail_url', 'html'));
    if ($verb === 'create') {
        $payload['created_by']      = $staff_id;
        $payload['created_by_name'] = edmStaffName($staff_id);
    }
    $response = edmCrud($verb, 'edm/templates', $payload, $input, $staff_id, 'template');
}

echo json_encode($response);
exit;
