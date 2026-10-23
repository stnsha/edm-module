<?php

declare(strict_types=1);

namespace Edm\Controllers;

use DateTimeImmutable;
use Edm\Core\Controller;
use Edm\Core\ValidationException;
use Edm\Services\PerformanceReport;
use Edm\Services\ReportingOverview;

/**
 * Dashboard (dashboard/). Actions:
 *   overview     KPI snapshot + newsletters by status
 *   performance  ?from=&to= (Y-m-d, both days included; default 1 January
 *                this year to today) message performance for that period
 *                against the period of equal length before it
 *                (Services/PerformanceReport)
 */
final class DashboardController extends Controller
{
    /** Longest period one request may cover (days). */
    private const MAX_DAYS = 1100;

    protected function handle(string $action): mixed
    {
        return match ($action) {
            'overview'    => (new ReportingOverview($this->db))->build(),
            'performance' => $this->performance(),
            default       => $this->unknown(),
        };
    }

    /** @return array<string, mixed> */
    private function performance(): array
    {
        $today = new DateTimeImmutable('today');
        $from = self::day((string) $this->request->query('from', ''), 'from') ?? $today->modify('first day of january this year');
        $to = self::day((string) $this->request->query('to', ''), 'to') ?? $today;

        if ($from > $to) {
            throw ValidationException::single('to', 'The end date must be on or after the start date.');
        }
        if ((int) $from->diff($to)->days + 1 > self::MAX_DAYS) {
            throw ValidationException::single('to', 'Choose a period of at most ' . self::MAX_DAYS . ' days.');
        }

        return (new PerformanceReport($this->db))->forPeriod($from, $to);
    }

    /** A Y-m-d query value; null when empty, 422 when not a real date. */
    private static function day(string $value, string $field): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw ValidationException::single($field, 'The ' . $field . ' date must be a valid date (YYYY-MM-DD).');
        }

        return $day;
    }
}
