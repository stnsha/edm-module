<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\Response;
use Edm\Models\Campaign;

/**
 * Newsletter Calendar (calendar/). Read-only: newsletters placed on their
 * send date; a click opens the newsletter in the Email creator. Actions:
 *   month   { success, campaigns }
 */
final class CalendarController extends Controller
{
    protected function handle(string $action): mixed
    {
        if ($action === 'month') {
            $this->month();
        }
        $this->unknown();
    }

    private function month(): never
    {
        Response::json([
            'success'   => true,
            'campaigns' => Campaign::listing(),
        ]);
    }
}
