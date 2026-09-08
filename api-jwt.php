<?php
date_default_timezone_set('Asia/Kuala_Lumpur');

// Only set JSON header if this file is accessed directly (not included)
if (!defined('API_JWT_INCLUDED')) {
    header('Content-Type: application/json');
}

// header.php normally defines this, but api-jwt.php is also hit directly as a
// standalone POST endpoint (no header.php in that request) - define it here too
// if not already set.
if (!defined('EDM_BASE')) {
    define('EDM_BASE', '/odb/' . basename(dirname(__FILE__)) . '/');
}

// Start session if not already started (PHP 5.3 compatible)
if (session_id() == '') {
    session_start();
}

// Include database connection (reuse the existing one when included)
if (!isset($conn)) {
    $connect = 1;
    include(__DIR__ . '/../common/index_adv.php');
}

if (!isset($conn)) {
    die(json_encode(array("status" => 500, "message" => "Database connection error")));
}

// Get staff information from session
$staff_id = null;
$department = null;
$outlet = null;
$nama_staff = null;
$edm_flag = 0;

if (isset($_SESSION["myusername"])) {
    $username = $_SESSION["myusername"];
    $query = "select * from staff where username = '$username' and recycle!=1";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        while ($rows = $result->fetch_assoc()) {
            $staff_id   = stripslashes((string)$rows['id']);
            $department = stripslashes((string)$rows['department']);
            $outlet     = stripslashes((string)$rows['outlet']);
            $nama_staff = stripslashes((string)$rows['nama_staff']);
            $edm_flag   = isset($rows['edm']) ? (int)$rows['edm'] : 0;
        }
    }
}

// Dev role override (localhost only, toggled via dev-switch-role.php).
// Mirrors atem's atem_dev_role_override: lets a developer exercise the
// staff.edm role levels (1 = superadmin ... 4 = management) without a real
// staff row carrying that value. Never active in production - dev-switch-role.php
// refuses to set it off localhost.
$edm_permission = $edm_flag;
if (getEnvironment() === 'local' && isset($_SESSION['edm_dev_role_override'])) {
    $edm_permission = (int)$_SESSION['edm_dev_role_override'];
}

// Real superadmin only - a dev override never grants superadmin (matches
// atem api.php's $is_api_superadmin).
$is_api_superadmin = (!isset($_SESSION['edm_dev_role_override']) && $edm_flag === 1);

/**
 * Log JWT API operations for monitoring and debugging
 * @param string $operation Operation name (e.g., 'getJWTToken', 'getEdmLookups')
 * @param string $message Log message describing the event
 * @param mixed $data Optional context data (will be JSON encoded if array)
 * @param string $level Log level: INFO, WARNING, ERROR
 * @return bool Success status of log write operation
 */
function logJWTOperation($operation, $message, $data = null, $level = 'INFO')
{
    $log_dir = __DIR__ . '/logs';
    $log_file = $log_dir . '/jwt_operations.log';

    // Create logs directory if it doesn't exist
    if (!is_dir($log_dir)) {
        if (!@mkdir($log_dir, 0755, true)) {
            @mkdir($log_dir, 0755);
        }
    }

    // Verify logs directory exists
    if (!is_dir($log_dir)) {
        return false;
    }

    // Ensure directory is writable
    if (!is_writable($log_dir)) {
        @chmod($log_dir, 0755);
    }

    // Build log message
    $timestamp = date('Y-m-d H:i:s');
    $env = getEnvironment();
    $log_message = "[$timestamp] [$env.$level] [$operation] $message";

    // Append data if provided
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log_message .= ' ' . json_encode($data);
        } else {
            $log_message .= ' ' . $data;
        }
    }

    $log_message .= PHP_EOL;

    $result = @file_put_contents($log_file, $log_message, FILE_APPEND);

    if ($result === false && !file_exists($log_file)) {
        @touch($log_file);
        @chmod($log_file, 0644);
        $result = @file_put_contents($log_file, $log_message, FILE_APPEND);
    }

    return $result !== false;
}

/**
 * Get current environment (local or production)
 * @return string Environment name ('local' or 'production')
 */
function getEnvironment()
{
    // Check if running on localhost (PHP 5.3 compatible)
    $serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
    $httpHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

    $isLocal = in_array($serverName, array('localhost', '127.0.0.1')) ||
        strpos($serverName, 'localhost') !== false ||
        strpos($httpHost, 'localhost') !== false ||
        strpos($httpHost, '127.0.0.1') !== false;

    return $isLocal ? 'local' : 'production';
}

/**
 * Get API host based on environment (auto-detect)
 * @return string API host URL
 */
function getApiHost()
{
    $env = getEnvironment();

    if ($env === 'local') {
        return 'http://127.0.0.1:8000/api/';
    } else {
        // Confirm the deployed path for edm-api on production.
        return 'http://mytotalhealth.com.my/edm-api/public/api/';
    }
}

/**
 * Read a value from the local .env file (parsed once and cached).
 * Minimal KEY=VALUE parser - no Composer / Dotenv dependency (PHP 5.3 safe).
 * Supports optional surrounding single or double quotes and #/; comment lines.
 * @param string $key Environment key to read
 * @param string|null $default Value returned when the key is absent
 * @return string|null
 */
function getEnvValue($key, $default = null)
{
    static $env = null;

    if ($env === null) {
        $env = array();
        $envFile = __DIR__ . '/.env';

        if (is_file($envFile) && is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                        continue;
                    }
                    $pos = strpos($line, '=');
                    if ($pos === false) {
                        continue;
                    }
                    $k = trim(substr($line, 0, $pos));
                    $v = trim(substr($line, $pos + 1));
                    $len = strlen($v);
                    if ($len >= 2) {
                        $first = $v[0];
                        $last = $v[$len - 1];
                        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                            $v = substr($v, 1, $len - 2);
                        }
                    }
                    $env[$k] = $v;
                }
            }
        }
    }

    return isset($env[$key]) ? $env[$key] : $default;
}

/**
 * Get the service account credentials used to obtain a JWT.
 * Values are read from the local .env file; the fallbacks keep local
 * development working when .env is missing.
 * @return array Credentials (email, password)
 */
function getServiceCredentials()
{
    return array(
        'email'    => getEnvValue('EDM_SERVICE_EMAIL', 'edm-service@local'),
        'password' => getEnvValue('EDM_SERVICE_PASSWORD', 'edm-service-local')
    );
}

/**
 * Get staff information for the current user from the odb database.
 * @param int $staff_id Staff ID from session
 * @return array Staff data or null if not found
 */
function getStaffAuthData($staff_id)
{
    global $conn;

    $staff_id = mysqli_real_escape_string($conn, $staff_id);

    logJWTOperation(
        'getStaffAuthData',
        'Retrieving staff data',
        array('staff_id' => $staff_id),
        'INFO'
    );

    $query = "SELECT s.id, s.nama_staff, s.department, d.depart_name
              FROM staff s
              LEFT JOIN staff_department d ON s.department = d.id
              WHERE s.id = $staff_id";
    $result = mysqli_query($conn, $query);

    if (!$result) {
        logJWTOperation(
            'getStaffAuthData',
            'Database query failed',
            array('staff_id' => $staff_id, 'error' => mysqli_error($conn)),
            'ERROR'
        );
        return null;
    }

    $row = mysqli_fetch_assoc($result);
    if (!$row) {
        logJWTOperation(
            'getStaffAuthData',
            'Staff not found',
            array('staff_id' => $staff_id),
            'WARNING'
        );
        return null;
    }

    return array(
        'staff_id'        => (int)$row['id'],
        'staff_name'      => $row['nama_staff'],
        'staff_dept_id'   => $row['department'] !== null ? (int)$row['department'] : null,
        'department_name' => $row['depart_name']
    );
}

/**
 * Get JWT token from the edm-api service using the service account
 * @param string $email Service account email
 * @param string $password Service account password
 * @return string|null JWT token or null on failure
 */
function getJWTToken($email, $password)
{
    logJWTOperation(
        'getJWTToken',
        'Requesting new JWT token',
        array('email' => $email),
        'INFO'
    );

    $host = getApiHost();
    $url = $host . 'login';

    $authData = array(
        'email'    => $email,
        'password' => $password
    );

    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json'
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($authData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        logJWTOperation(
            'getJWTToken',
            'Failed to obtain JWT token',
            array('httpCode' => $httpCode, 'error' => $error, 'response' => $response),
            'ERROR'
        );

        error_log("EDM JWT Auth failed: HTTP $httpCode, Error: $error, Response: $response");
        return null;
    }

    logJWTOperation(
        'getJWTToken',
        'JWT token obtained successfully',
        array('httpCode' => $httpCode),
        'INFO'
    );

    $decoded = json_decode($response, true);

    // Cache the token TTL alongside the token for getAuthToken to use.
    if (isset($decoded['data']['expires_in'])) {
        $_SESSION['edm_jwt_expires_in'] = (int)$decoded['data']['expires_in'];
    }

    return isset($decoded['data']['access_token']) ? $decoded['data']['access_token'] : null;
}

/**
 * Get or refresh JWT token with caching
 * @param int $staff_id Staff ID from session
 * @return string|null JWT token or null on failure
 */
function getAuthToken($staff_id)
{
    // Check if token exists in session and is still valid (basic check)
    if (
        isset($_SESSION['edm_jwt_token']) && isset($_SESSION['edm_jwt_expires']) &&
        time() < $_SESSION['edm_jwt_expires']
    ) {
        logJWTOperation(
            'getAuthToken',
            'Using cached token',
            array('staff_id' => $staff_id, 'expiry' => date('Y-m-d H:i:s', $_SESSION['edm_jwt_expires'])),
            'INFO'
        );
        return $_SESSION['edm_jwt_token'];
    } else if (isset($_SESSION['edm_jwt_expires'])) {
        logJWTOperation(
            'getAuthToken',
            'Cached token expired, refreshing',
            array('staff_id' => $staff_id, 'expired_at' => date('Y-m-d H:i:s', $_SESSION['edm_jwt_expires'])),
            'WARNING'
        );
    }

    // Obtain a token using the service account credentials
    $creds = getServiceCredentials();
    $token = getJWTToken($creds['email'], $creds['password']);

    if ($token) {
        // Store token in session using the TTL returned by the API (default 1 hour)
        $ttl = isset($_SESSION['edm_jwt_expires_in']) ? (int)$_SESSION['edm_jwt_expires_in'] : 3600;
        $_SESSION['edm_jwt_token'] = $token;
        $_SESSION['edm_jwt_expires'] = time() + $ttl;
        $_SESSION['edm_jwt_staff_id'] = $staff_id;

        logJWTOperation(
            'getAuthToken',
            'New token cached',
            array('staff_id' => $staff_id, 'expiry' => date('Y-m-d H:i:s', $_SESSION['edm_jwt_expires'])),
            'INFO'
        );
    } else {
        logJWTOperation(
            'getAuthToken',
            'Failed to get auth token',
            array('staff_id' => $staff_id),
            'ERROR'
        );
    }

    return $token;
}

/**
 * Make API call with JWT authentication
 * @param string $endpoint API endpoint
 * @param array|null $data Request data
 * @param string $method HTTP method
 * @param int $staff_id Staff ID for authentication
 * @param int $curlTimeout cURL timeout in seconds
 * @return array API response
 */
function getApiDataWithJWT($endpoint, $data = null, $method = 'GET', $staff_id = null, $curlTimeout = 30)
{
    logJWTOperation(
        'getApiDataWithJWT',
        'Starting API call',
        array(
            'endpoint' => $endpoint,
            'method' => $method,
            'staff_id' => $staff_id,
            'has_data' => $data !== null
        ),
        'INFO'
    );

    $host = getApiHost();
    $url = $host . $endpoint;

    // Get JWT token
    $token = getAuthToken($staff_id);
    if (!$token) {
        logJWTOperation(
            'getApiDataWithJWT',
            'Authentication failed - no token',
            array('endpoint' => $endpoint, 'staff_id' => $staff_id),
            'ERROR'
        );

        return array(
            'success' => false,
            'error' => 'Authentication failed - could not get JWT token',
            'response' => json_encode(array('error' => 'Authentication failed')),
            'httpCode' => 401
        );
    }

    $method = strtoupper($method);

    $headers = array(
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json'
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $curlTimeout);

    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_URL, $url);
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    // Log the outgoing request
    logJWTOperation(
        'getApiDataWithJWT',
        'Sending request to API',
        array(
            'url' => $url,
            'method' => $method,
            'data' => $data
        ),
        'INFO'
    );

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    // If unauthorized, clear token and retry once
    if ($httpCode === 401 && isset($_SESSION['edm_jwt_token'])) {
        unset($_SESSION['edm_jwt_token']);
        unset($_SESSION['edm_jwt_expires']);

        // Get new token and retry
        $token = getAuthToken($staff_id);
        if ($token) {
            $headers[0] = 'Authorization: Bearer ' . $token; // Update Authorization in request headers array
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
        }
    }

    curl_close($ch);

    // Handle HTTP status codes
    if ($response === false) {
        logJWTOperation(
            'getApiDataWithJWT',
            'API call failed - no response (connection error)',
            array(
                'endpoint' => $endpoint,
                'url' => $url,
                'curl_error' => $error
            ),
            'ERROR'
        );

        return array(
            'success' => false,
            'error' => 'API Request Failed',
            'message' => 'cURL error: ' . $error,
            'response' => json_encode(array('error' => 'No response')),
            'httpCode' => 0
        );
    }

    // Success codes: 200, 201, 204
    if ($httpCode === 200 || $httpCode === 201 || $httpCode === 204) {
        logJWTOperation(
            'getApiDataWithJWT',
            'API call successful',
            array(
                'endpoint' => $endpoint,
                'httpCode' => $httpCode,
                'response_length' => strlen($response)
            ),
            'INFO'
        );

        return array(
            'success' => true,
            'response' => $response,
            'httpCode' => $httpCode
        );
    }

    // Handle error codes
    $decodedError = json_decode($response, true);
    $errorMessage = isset($decodedError['message']) ? $decodedError['message'] : 'API Request Failed';

    logJWTOperation(
        'getApiDataWithJWT',
        'API call failed',
        array(
            'endpoint' => $endpoint,
            'httpCode' => $httpCode,
            'message' => $errorMessage,
            'response' => $response
        ),
        'ERROR'
    );

    return array(
        'success' => false,
        'error' => $errorMessage,
        'message' => $errorMessage,
        'response' => $response,
        'httpCode' => $httpCode,
        'details' => $decodedError
    );
}

/**
 * Example domain call: fetch EDM lookups from edm-api.
 * Replace / extend with the real EDM endpoints as they are built on edm-api.
 * @param int $staff_id Staff ID for authentication
 * @return array Lookups data
 */
function getEdmLookups($staff_id)
{
    $result = getApiDataWithJWT('edm/lookups', null, 'GET', $staff_id);
    $httpCode = isset($result['httpCode']) ? $result['httpCode'] : 0;
    $decoded = json_decode(isset($result['response']) ? $result['response'] : '', true);

    if ($httpCode == 200) {
        return array(
            'success' => true,
            'data' => isset($decoded['data']) ? $decoded['data'] : $decoded
        );
    } else {
        return array(
            'success' => false,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'Failed to retrieve lookups'
        );
    }
}

// Only run request handler if this file is accessed directly (not included)
if (!defined('API_JWT_INCLUDED')) {
    // Main request handler
    $input = file_get_contents('php://input');
    $jsonData = json_decode($input, true);
    $response = array('success' => false, 'message' => 'Invalid request');

    // Check for action in query parameter, multipart POST field, or JSON body.
    $action = isset($_GET['action'])
        ? $_GET['action']
        : (isset($_POST['action'])
            ? $_POST['action']
            : (isset($jsonData['action']) ? $jsonData['action'] : null));

    // Check if we have a staff ID for authentication
    if (!$staff_id) {
        echo json_encode(array(
            'success' => false,
            'error' => 'No staff ID available for authentication',
            'message' => 'Staff ID is required for JWT authentication. Please ensure you are logged in.',
            'debug' => array(
                'session_username' => isset($_SESSION["myusername"]) ? $_SESSION["myusername"] : 'not set',
                'staff_id' => $staff_id
            )
        ));
        exit;
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($action) {
            switch ($action) {
                case 'lookups':
                    $response = getEdmLookups($staff_id);
                    break;

                default:
                    $response = array('success' => false, 'message' => 'Unknown action: ' . $action);
                    break;
            }
        }
    } elseif (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action) {
            switch ($action) {
                case 'lookups':
                    $response = getEdmLookups($staff_id);
                    break;

                default:
                    $response = array('success' => false, 'message' => 'Unknown action: ' . $action);
                    break;
            }
        }
    }

    echo json_encode($response);
    exit;
}
