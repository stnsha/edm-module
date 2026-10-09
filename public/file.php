<?php

declare(strict_types=1);

/**
 * Files library image proxy. PUBLIC endpoint (no odb login): newsletter
 * images link here, and recipients' mail clients load them.
 *
 *   GET ?f=<32 hex>.<jpg|png|gif|webp>
 *
 * Serves edm/cache/nas/<name>, fetching it from the NAS on a cache miss
 * (Edm\Services\Nas\NasImageStore). Only names matching
 * NasImageStore::NAME_PATTERN are accepted, so nothing else on the NAS can be
 * reached. Names are random and never reused, so the response is cacheable
 * for a year. Loads app/autoload.php only - no session, no database.
 */

use Edm\Services\Nas\NasException;
use Edm\Services\Nas\NasImageStore;

require __DIR__ . '/../app/autoload.php';

$name = (string) ($_GET['f'] ?? '');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) || !NasImageStore::isName($name)) {
    http_response_code(404);
    exit;
}

try {
    $path = NasImageStore::fromEnv()->local($name);
} catch (NasException $e) {
    error_log('[EDM] file.php ' . $name . ': ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 60');
    exit;
}
if ($path === null) {
    http_response_code(404);
    exit;
}

$etag = '"' . $name . '"';
header('Content-Type: ' . NasImageStore::MIME[pathinfo($name, PATHINFO_EXTENSION)]);
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($path));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    readfile($path);
}
