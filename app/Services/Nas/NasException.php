<?php

declare(strict_types=1);

namespace Edm\Services\Nas;

use RuntimeException;

/**
 * A call to the Synology NAS failed or the NAS is not configured. The message
 * is safe to show to staff (it never contains the NAS password).
 */
final class NasException extends RuntimeException
{
}
