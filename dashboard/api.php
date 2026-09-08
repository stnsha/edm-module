<?php
/**
 * Dashboard page API. Per-page action router (see project rule: one page,
 * one folder). Shared JWT transport lives in edm/api-jwt.php - this file
 * includes it with API_JWT_INCLUDED so only the helper functions load, not
 * that file's own request handler.
 */
define('API_JWT_INCLUDED', true);
require __DIR__ . '/../api-jwt.php';

header('Content-Type: application/json');

$input  = json_decode(file_get_contents('php://input'), true);
$action = isset($_GET['action'])
    ? $_GET['action']
    : (isset($_POST['action'])
        ? $_POST['action']
        : (isset($input['action']) ? $input['action'] : null));

if (!$staff_id) {
    echo json_encode(array(
        'success' => false,
        'error'   => 'No staff ID available for authentication',
        'message' => 'Staff ID is required. Please ensure you are logged in.'
    ));
    exit;
}

/**
 * Example: pull dashboard overview data from edm-api.
 * Replace the endpoint with the real one once it exists on edm-api.
 * @param int $staff_id
 * @return array
 */
function getDashboardOverview($staff_id)
{
    $result = getApiDataWithJWT('edm/dashboard', null, 'GET', $staff_id);
    $httpCode = isset($result['httpCode']) ? $result['httpCode'] : 0;
    $decoded  = json_decode(isset($result['response']) ? $result['response'] : '', true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data'    => isset($decoded['data']) ? $decoded['data'] : $decoded
        );
    }

    return array(
        'success' => false,
        'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to load dashboard'
    );
}

$response = array('success' => false, 'message' => 'Unknown action');

switch ($action) {
    case 'overview':
        $response = getDashboardOverview($staff_id);
        break;
}

echo json_encode($response);
exit;
