<?php
/**
 * Shared helpers for a page's api.php when it proxies to edm-api over JWT.
 *
 * Include AFTER api-jwt.php (which must be loaded with API_JWT_INCLUDED):
 *
 *   define('API_JWT_INCLUDED', true);
 *   require __DIR__ . '/../api-jwt.php';
 *   require __DIR__ . '/../api-proxy.php';
 *
 * Keeps the per-page router thin without turning into a shared mega-API: this
 * file has no routing of its own, only the request/response plumbing every
 * page repeats.
 */

if (!function_exists('edmApiResult')) {
    /**
     * Normalise a getApiDataWithJWT() result into { success, data } /
     * { success, message, errors } for the browser.
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
}

if (!function_exists('edmReqId')) {
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
}

if (!function_exists('edmCrud')) {
    /**
     * Generic CRUD dispatch against an edm-api resource.
     * @param string $verb list|create|update|delete
     * @param string $resource edm-api path, e.g. 'edm/templates'
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
}

if (!function_exists('edmPick')) {
    /**
     * Copy a whitelist of keys from $input, trimming strings and mapping '' to
     * null. Used to build an edm-api payload from the browser body.
     * @param array $input
     * @param array $fields
     * @return array
     */
    function edmPick($input, $fields)
    {
        $out = array();
        foreach ($fields as $f) {
            if (!array_key_exists($f, $input)) {
                continue;
            }
            $v = $input[$f];
            if (is_string($v)) {
                $v = trim($v);
                if ($v === '') {
                    $v = null;
                }
            }
            $out[$f] = $v;
        }
        return $out;
    }
}

if (!function_exists('edmStaffName')) {
    /**
     * Logged-in staff display name (cached per request).
     * @param int $staff_id
     * @return string|null
     */
    function edmStaffName($staff_id)
    {
        static $name = false;
        if ($name === false) {
            $info = getStaffAuthData($staff_id);
            $name = $info ? $info['staff_name'] : null;
        }
        return $name;
    }
}

if (!function_exists('edmReadBody')) {
    /**
     * Decoded JSON request body as an array (never null).
     * @return array
     */
    function edmReadBody()
    {
        $raw = json_decode(file_get_contents('php://input'), true);
        return is_array($raw) ? $raw : array();
    }
}

if (!function_exists('edmResolveAction')) {
    /**
     * Resolve the requested action from query string, POST or JSON body.
     * @param array $input
     * @return string|null
     */
    function edmResolveAction($input)
    {
        if (isset($_GET['action'])) {
            return $_GET['action'];
        }
        if (isset($_POST['action'])) {
            return $_POST['action'];
        }
        return isset($input['action']) ? $input['action'] : null;
    }
}
