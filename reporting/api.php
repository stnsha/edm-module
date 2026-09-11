<?php
/**
 * Reporting Dashboard page API. Proxies to edm-api edm/reporting/overview over
 * JWT.
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

if ($action === 'overview') {
    $response = edmApiResult(getApiDataWithJWT('edm/reporting/overview', null, 'GET', $staff_id), 'Failed to load reporting');
}

echo json_encode($response);
exit;
