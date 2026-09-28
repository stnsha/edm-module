<?php

declare(strict_types=1);

namespace Edm\Services\Ses;

use RuntimeException;
use Throwable;

/**
 * An SES call failed or SES is not usable (not configured, identity missing).
 * The message is safe to show to staff. $awsCode is the AWS error code, null
 * when the failure happened before AWS answered (network, credentials).
 */
final class SesException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $awsCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isNotFound(): bool
    {
        return $this->awsCode === 'NotFoundException';
    }
}
