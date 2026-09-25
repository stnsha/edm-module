<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\Model;
use Edm\Core\Response;
use Edm\Models\Campaign;
use Edm\Models\CalendarSlot;

/**
 * Campaign Calendar (calendar/). Actions:
 *   month   { success, slots, campaigns } for ?from=&to= (Y-m-d)
 *   slots_(create|update|delete)
 */
final class CalendarController extends Controller
{
    private const RULES = [
        'slot_date'   => ['required', 'date'],
        'slot_label'  => ['nullable', 'string', 'max:100'],
        'category'    => ['nullable', 'string', 'max:50'],
        'campaign_id' => ['nullable', 'integer', 'exists:edm_campaigns,id'],
        'note'        => ['nullable', 'string', 'max:255'],
    ];

    protected function handle(string $action): mixed
    {
        if ($action === 'month') {
            $this->month();
        }
        if (preg_match('/^slots_(create|update|delete)$/', $action, $m)) {
            $payload = $this->request->only(['slot_date', 'slot_label', 'category', 'note'])
                + $this->request->ids(['campaign_id']);
            $update = ['slot_date' => ['sometimes', 'date']] + self::RULES;

            return $this->crud($m[1], CalendarSlot::class, $payload, self::RULES, $update);
        }
        $this->unknown();
    }

    private function month(): never
    {
        $where = '1 = 1';
        $params = [];
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $date = Model::parseDate((string) $this->request->query($key, ''));
            if ($date !== null) {
                $where .= ' AND `slot_date` ' . $op . ' ?';
                $params[] = $date->format('Y-m-d');
            }
        }

        Response::json([
            'success'   => true,
            'slots'     => CalendarSlot::where($where, $params),
            'campaigns' => Campaign::listing(),
        ]);
    }
}
