<?php
/**
 * Email creator page API. Proxies to edm-api edm/campaigns/{id} and
 * edm/campaigns/{id}/content over JWT.
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

$campaignId = isset($_GET['campaign']) ? (int)$_GET['campaign'] : (isset($input['campaign']) ? (int)$input['campaign'] : 0);

$response = array('success' => false, 'message' => 'Unknown action');

if (!$campaignId) {
    $response = array('success' => false, 'message' => 'A campaign id is required');
} elseif ($action === 'load') {
    $campaign = edmApiResult(getApiDataWithJWT('edm/campaigns/' . $campaignId, null, 'GET', $staff_id), 'Failed to load newsletter');
    $response = $campaign;
} elseif ($action === 'content_get') {
    $response = edmApiResult(getApiDataWithJWT('edm/campaigns/' . $campaignId . '/content', null, 'GET', $staff_id), 'Failed to load content');
} elseif ($action === 'content_save') {
    $payload = array('html' => isset($input['html']) ? (string)$input['html'] : '');
    $response = edmApiResult(
        getApiDataWithJWT('edm/campaigns/' . $campaignId . '/content', $payload, 'PUT', $staff_id),
        'Failed to save content'
    );
}

echo json_encode($response);
exit;
