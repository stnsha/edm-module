<?php

declare(strict_types=1);

namespace Edm\Core;

/**
 * JSON response writer. Shape used by every page script:
 *   { success: true, data }  |  { success: false, message, errors? }
 */
final class Response
{
    public static function json(array $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
