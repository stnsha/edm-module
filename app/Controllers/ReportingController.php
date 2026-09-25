<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Services\ReportingOverview;

/**
 * Statistics (reporting/). Action: overview.
 */
final class ReportingController extends Controller
{
    protected function handle(string $action): mixed
    {
        if ($action === 'overview') {
            return (new ReportingOverview($this->db))->build();
        }
        $this->unknown();
    }
}
