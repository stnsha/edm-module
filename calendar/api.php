<?php
/**
 * Campaign Calendar page API. Proxies to edm-api edm/calendar-slots and
 * edm/campaigns over JWT.
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

if ($action === 'month') {
    $from = isset($_GET['from']) ? $_GET['from'] : '';
    $to   = isset($_GET['to']) ? $_GET['to'] : '';
    $slots = edmApiResult(
        getApiDataWithJWT('edm/calendar-slots?from=' . urlencode($from) . '&to=' . urlencode($to), null, 'GET', $staff_id),
        'Failed to load calendar'
    );
    $campaigns = edmApiResult(getApiDataWithJWT('edm/campaigns', null, 'GET', $staff_id), 'Failed to load newsletters');
    $response = array(
        'success'   => !empty($slots['success']),
        'slots'     => !empty($slots['success']) ? $slots['data'] : array(),
        'campaigns' => !empty($campaigns['success']) ? $campaigns['data'] : array()
    );

} elseif (preg_match('/^slots_(create|update|delete)$/', (string)$action, $m)) {
    $verb    = $m[1];
    $payload = edmPick($input, array('slot_date', 'slot_label', 'category', 'note'));
    if (array_key_exists('campaign_id', $input)) {
        $payload['campaign_id'] = ($input['campaign_id'] === '' || $input['campaign_id'] === null) ? null : (int)$input['campaign_id'];
    }
    $response = edmCrud($verb, 'edm/calendar-slots', $payload, $input, $staff_id, 'slot');
}

echo json_encode($response);
exit;
