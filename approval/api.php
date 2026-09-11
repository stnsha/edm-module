<?php
/**
 * Approval Centre page API. Proxies to edm-api edm/approvals over JWT.
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

if (preg_match('/^approvals_(list|create|delete)$/', (string)$action, $m)) {
    $verb = $m[1];
    $payload = array();
    if (array_key_exists('campaign_id', $input)) {
        $payload['campaign_id'] = (int)$input['campaign_id'];
    }
    if (array_key_exists('step', $input)) {
        $payload['step'] = (int)$input['step'];
    }
    if (array_key_exists('comment', $input)) {
        $payload['comment'] = ($input['comment'] === '') ? null : edmTrim($input['comment']);
    }
    $response = edmCrud($verb, 'edm/approvals', $payload, $input, $staff_id, 'approval');

} elseif ($action === 'approvals_decide') {
    $id = edmReqId($input);
    $status = isset($input['status']) ? $input['status'] : '';
    if (!$id || !in_array($status, array('approved', 'rejected'), true)) {
        $response = array('success' => false, 'message' => 'Approval id and a valid decision are required');
    } else {
        $payload = array(
            'status'        => $status,
            'comment'       => isset($input['comment']) && $input['comment'] !== '' ? edmTrim($input['comment']) : null,
            'reviewer_id'   => $staff_id,
            'reviewer_name' => edmStaffName($staff_id)
        );
        $response = edmApiResult(
            getApiDataWithJWT('edm/approvals/' . $id . '/decide', $payload, 'POST', $staff_id),
            'Failed to record decision'
        );
    }
}

echo json_encode($response);
exit;
