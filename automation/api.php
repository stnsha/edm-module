<?php
/**
 * Automation page API. Proxies to edm-api edm/workflows and edm/autoresponders
 * over JWT. Serves both automation/index.php (Workflows) and
 * automation/autoresponders.php.
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

if (preg_match('/^(workflows|autoresponders)_(list|create|update|delete)$/', (string)$action, $m)) {
    $group = $m[1];
    $verb  = $m[2];

    if ($group === 'workflows') {
        $payload = edmPick($input, array('name', 'description', 'trigger', 'status'));
        if ($verb === 'create') {
            $payload['created_by']      = $staff_id;
            $payload['created_by_name'] = edmStaffName($staff_id);
        }
        $response = edmCrud($verb, 'edm/workflows', $payload, $input, $staff_id, 'workflow');
    } else {
        $payload = edmPick($input, array('name', 'subject', 'status'));
        if (array_key_exists('list_id', $input)) {
            $payload['list_id'] = ($input['list_id'] === '' || $input['list_id'] === null) ? null : (int)$input['list_id'];
        }
        if (array_key_exists('offset_days', $input)) {
            $payload['offset_days'] = (int)$input['offset_days'];
        }
        $response = edmCrud($verb, 'edm/autoresponders', $payload, $input, $staff_id, 'autoresponder');
    }
}

echo json_encode($response);
exit;
