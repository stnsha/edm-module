<?php
/**
 * Contacts (Audience Builder) page API. Per-page action router (project rule:
 * one page, one folder). Proxies to edm-api over JWT; every endpoint is
 * mirrored there. Covers Lists, Segments, Tags and Custom fields.
 */
define('API_JWT_INCLUDED', true);
require __DIR__ . '/../api-jwt.php';
require __DIR__ . '/../api-proxy.php';

header('Content-Type: application/json');

$input  = edmReadBody();
$action = edmResolveAction($input);

if (!$staff_id) {
    echo json_encode(array(
        'success' => false,
        'error'   => 'No staff ID available for authentication',
        'message' => 'Staff ID is required. Please ensure you are logged in.'
    ));
    exit;
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

$response = array('success' => false, 'message' => 'Unknown action');

if (preg_match('/^(lists|segments|tags|fields)_(list|create|update|delete)$/', (string)$action, $m)) {
    $group = $m[1];
    $verb  = $m[2];

    if ($group === 'lists') {
        $payload = array();
        if (array_key_exists('name', $input)) {
            $payload['name'] = edmTrim($input['name']);
        }
        if (array_key_exists('description', $input)) {
            $payload['description'] = ($input['description'] === '') ? null : edmTrim($input['description']);
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
            $payload['name'] = edmTrim($input['name']);
        }
        if (array_key_exists('description', $input)) {
            $payload['description'] = ($input['description'] === '') ? null : edmTrim($input['description']);
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
                $payload[$f] = ($input[$f] === '') ? null : edmTrim($input[$f]);
            }
        }
        $response = edmCrud($verb, 'edm/tags', $payload, $input, $staff_id, 'tag');

    } elseif ($group === 'fields') {
        $payload = array();
        if (array_key_exists('label', $input)) {
            $payload['label'] = edmTrim($input['label']);
        }
        if (array_key_exists('type', $input)) {
            $payload['type'] = edmTrim($input['type']);
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
