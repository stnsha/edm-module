<?php
/**
 * Newsletters (Campaign Management) page API. Proxies to edm-api edm/campaigns
 * over JWT, plus the submit-for-review action.
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

if (preg_match('/^campaigns_(list|create|update|delete)$/', (string)$action, $m)) {
    $verb    = $m[1];
    $payload = edmPick($input, array('name', 'subject', 'subject_b', 'preheader', 'scheduled_at'));
    foreach (array('sender_id', 'list_id', 'segment_id') as $fk) {
        if (array_key_exists($fk, $input)) {
            $payload[$fk] = ($input[$fk] === '' || $input[$fk] === null) ? null : (int)$input[$fk];
        }
    }
    if ($verb === 'create') {
        $payload['requested_by']      = $staff_id;
        $payload['requested_by_name'] = edmStaffName($staff_id);
    }
    $response = edmCrud($verb, 'edm/campaigns', $payload, $input, $staff_id, 'newsletter');

} elseif ($action === 'campaigns_submit') {
    $id = edmReqId($input);
    if (!$id) {
        $response = array('success' => false, 'message' => 'Newsletter id is required');
    } else {
        $response = edmApiResult(
            getApiDataWithJWT('edm/campaigns/' . $id . '/submit', null, 'POST', $staff_id),
            'Failed to submit newsletter'
        );
    }
}

echo json_encode($response);
exit;
