<?php
/**
 * Contacts (Audience Builder) page API. Per-page action router (project rule:
 * one page, one folder). Proxies to edm-api over JWT; every endpoint is
 * mirrored there. Covers Lists, Segments, Tags and Custom fields.
 */
define('API_JWT_INCLUDED', true);
require __DIR__ . '/../api-jwt.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = array();
}
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
 * Normalise a getApiDataWithJWT() result for the browser.
 * @param array $result
 * @param string $failMessage
 * @return array
 */
function edmApiResult($result, $failMessage)
{
    $httpCode = isset($result['httpCode']) ? (int)$result['httpCode'] : 0;
    $decoded  = json_decode(isset($result['response']) ? $result['response'] : '', true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return array(
            'success' => true,
            'data'    => (is_array($decoded) && array_key_exists('data', $decoded)) ? $decoded['data'] : $decoded
        );
    }

    return array(
        'success'  => false,
        'message'  => (is_array($decoded) && isset($decoded['message'])) ? $decoded['message'] : $failMessage,
        'errors'   => (is_array($decoded) && isset($decoded['errors'])) ? $decoded['errors'] : null,
        'httpCode' => $httpCode
    );
}

/**
 * Record id from query string or JSON body.
 * @param array $input
 * @return int
 */
function edmReqId($input)
{
    if (isset($_GET['id'])) {
        return (int)$_GET['id'];
    }
    return isset($input['id']) ? (int)$input['id'] : 0;
}

/**
 * Generic CRUD dispatch against an edm-api resource.
 * @param string $verb list|create|update|delete
 * @param string $resource edm-api path segment, e.g. 'edm/lists'
 * @param array $payload body for create / update
 * @param array $input request body (for id)
 * @param int $staff_id
 * @param string $label human label for error messages
 * @return array
 */
function edmCrud($verb, $resource, $payload, $input, $staff_id, $label)
{
    switch ($verb) {
        case 'list':
            return edmApiResult(getApiDataWithJWT($resource, null, 'GET', $staff_id), 'Failed to load ' . $label);
        case 'create':
            return edmApiResult(getApiDataWithJWT($resource, $payload, 'POST', $staff_id), 'Failed to create ' . $label);
        case 'update':
            $id = edmReqId($input);
            if (!$id) {
                return array('success' => false, 'message' => 'Record id is required');
            }
            return edmApiResult(getApiDataWithJWT($resource . '/' . $id, $payload, 'PUT', $staff_id), 'Failed to update ' . $label);
        case 'delete':
            $id = edmReqId($input);
            if (!$id) {
                return array('success' => false, 'message' => 'Record id is required');
            }
            return edmApiResult(getApiDataWithJWT($resource . '/' . $id, null, 'DELETE', $staff_id), 'Failed to delete ' . $label);
    }
    return array('success' => false, 'message' => 'Unknown action');
}

/**
 * Split a textarea value into a trimmed, non-empty list.
 * @param mixed $value
 * @return array
 */
function edmLines($value)
{
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', $value), 'strlen'));
    }
    $parts = preg_split('/\r\n|\r|\n/', (string)$value);
    return array_values(array_filter(array_map('trim', $parts), 'strlen'));
}

$staffInfo = null;
function edmStaffName($staff_id)
{
    global $staffInfo;
    if ($staffInfo === null) {
        $staffInfo = getStaffAuthData($staff_id);
    }
    return $staffInfo ? $staffInfo['staff_name'] : null;
}

$response = array('success' => false, 'message' => 'Unknown action');

if (preg_match('/^(lists|segments|tags|fields)_(list|create|update|delete)$/', (string)$action, $m)) {
    $group = $m[1];
    $verb  = $m[2];

    if ($group === 'lists') {
        $payload = array();
        if (array_key_exists('name', $input)) {
            $payload['name'] = trim($input['name']);
        }
        if (array_key_exists('description', $input)) {
            $payload['description'] = ($input['description'] === '') ? null : trim($input['description']);
        }
        if (array_key_exists('is_active', $input)) {
            $payload['is_active'] = !empty($input['is_active']);
        }
        if ($verb === 'create') {
            $payload['created_by']      = $staff_id;
            $payload['created_by_name'] = edmStaffName($staff_id);
        }
        $response = edmCrud($verb, 'edm/lists', $payload, $input, $staff_id, 'list');

    } elseif ($group === 'segments') {
        $payload = array();
        if (array_key_exists('name', $input)) {
            $payload['name'] = trim($input['name']);
        }
        if (array_key_exists('description', $input)) {
            $payload['description'] = ($input['description'] === '') ? null : trim($input['description']);
        }
        if (array_key_exists('definition', $input) && is_array($input['definition'])) {
            $payload['definition'] = array(
                'match' => isset($input['definition']['match']) ? $input['definition']['match'] : 'all',
                'rules' => isset($input['definition']['rules']) && is_array($input['definition']['rules'])
                    ? $input['definition']['rules'] : array()
            );
        }
        if ($verb === 'create') {
            $payload['created_by']      = $staff_id;
            $payload['created_by_name'] = edmStaffName($staff_id);
        }
        $response = edmCrud($verb, 'edm/segments', $payload, $input, $staff_id, 'segment');

    } elseif ($group === 'tags') {
        $payload = array();
        foreach (array('name', 'color', 'description') as $f) {
            if (array_key_exists($f, $input)) {
                $payload[$f] = ($input[$f] === '') ? null : trim($input[$f]);
            }
        }
        $response = edmCrud($verb, 'edm/tags', $payload, $input, $staff_id, 'tag');

    } elseif ($group === 'fields') {
        $payload = array();
        if (array_key_exists('label', $input)) {
            $payload['label'] = trim($input['label']);
        }
        if (array_key_exists('type', $input)) {
            $payload['type'] = trim($input['type']);
        }
        if (array_key_exists('options', $input)) {
            $lines = edmLines($input['options']);
            $payload['options'] = $lines ? $lines : null;
        }
        $response = edmCrud($verb, 'edm/custom-fields', $payload, $input, $staff_id, 'field');

        // Present options back to the edm-crud textarea as newline text.
        if ($response['success'] && $verb === 'list' && is_array($response['data'])) {
            foreach ($response['data'] as &$row) {
                if (isset($row['options']) && is_array($row['options'])) {
                    $row['options'] = implode("\n", $row['options']);
                }
            }
            unset($row);
        }
    }
}

echo json_encode($response);
exit;
