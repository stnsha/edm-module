<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Services\ReportingOverview;

/**
 * Dashboard (dashboard/). Action: overview - the same snapshot as Statistics.
 */
final class DashboardController extends Controller
{
    protected function handle(string $action): mixed
    {
        if ($action === 'overview') {
            return (new ReportingOverview($this->db))->build();
        }
        $this->unknown();
    }
}
