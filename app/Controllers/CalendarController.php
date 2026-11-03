<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\Response;
use Edm\Models\Campaign;
use Edm\Services\AccessScope;

/**
 * Newsletter Calendar (calendar/). Read-only: newsletters placed on their
 * send date; a click opens the newsletter in the Email creator. Actions:
 *   month   { success, campaigns } - the newsletters the user may see
 *           (AccessScope::forUser(): own / department / all)
 */
final class CalendarController extends Controller
{
    protected static function allowsScoped(string $action): bool
    {
        return $action === 'month';
    }

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
            'campaigns' => Campaign::listing(null, null, AccessScope::forUser($this->auth)),
        ]);
    }
}
