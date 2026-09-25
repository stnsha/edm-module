<?php

declare(strict_types=1);

namespace Edm\Core;

use RuntimeException;

/**
 * An error that maps to an HTTP status and a { success: false, message }
 * response (404 not found, 403 forbidden, 422 business-rule refusal).
 */
class HttpException extends RuntimeException
{
    public function __construct(string $message, private int $status = 400)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
