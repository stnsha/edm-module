<?php
/**
 * Automation page API. Per-page action router (project rule: one page, one
 * folder). Shared JWT transport lives in edm/api-jwt.php - included with
 * API_JWT_INCLUDED so only the helper functions load. Endpoints are added per
 * feature and must be mirrored in edm-api.
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

$response = array('success' => false, 'message' => 'Unknown action');

switch ($action) {
    // Actions added per feature.
}

echo json_encode($response);
exit;
