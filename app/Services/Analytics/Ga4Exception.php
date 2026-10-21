<?php

declare(strict_types=1);

namespace Edm\Services\Analytics;

use RuntimeException;

/**
 * A Google Analytics Data API call failed or GA is not configured. The
 * message is safe to show to staff (it never contains the service account key).
 */
final class Ga4Exception extends RuntimeException
{
}
