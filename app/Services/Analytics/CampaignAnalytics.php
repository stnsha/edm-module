<?php

declare(strict_types=1);

namespace Edm\Services\Analytics;

use DateTimeImmutable;
use Edm\Core\HttpException;
use Edm\Models\Campaign;

/**
 * Website figures of one newsletter from Google Analytics 4, for the
 * newsletter report (campaigns_analytics). GA knows a visit came from the
 * newsletter only through the UTM tags on its links (UtmTagger), so the
 * reports are filtered on the newsletter's effective utm_source / utm_medium /
 * utm_campaign (Campaign::utm()), case-insensitive, from the day the
 * newsletter was created (test sends included) to today.
 *
 * Figures are aggregate only - GA holds no personal data, so a visit cannot
 * be tied to a recipient. Results are cached per newsletter in
 * edm/cache/ga4/ for CACHE_SECONDS; standard GA reports lag 24-48 hours.
 */
final class CampaignAnalytics
{
    /** How long fetched figures are reused before GA is asked again. */
    public const CACHE_SECONDS = 3 * 3600;

    /** Rows in the landing page / link content tables. */
    private const TOP_ROWS = 10;

    private const TOTAL_METRICS = [
        'sessions'               => 'sessions',
        'totalUsers'             => 'users',
        'engagedSessions'        => 'engaged_sessions',
        'engagementRate'         => 'engagement_rate',
        'averageSessionDuration' => 'avg_duration',
        'screenPageViews'        => 'page_views',
        'keyEvents'              => 'key_events',
        'ecommercePurchases'     => 'purchases',
        'purchaseRevenue'        => 'revenue',
    ];

    public function __construct(private Ga4Config $config)
    {
    }

    public static function fromEnv(): self
    {
        return new self(Ga4Config::fromEnv());
    }

    /**
     * @param array<string, mixed> $campaign a Campaign row (find())
     * @return array<string, mixed> { connected: false, missing } when GA is not
     *         configured; { connected: true, utm_enabled: false, utm } when the
     *         newsletter's links are not tagged; otherwise the figures
     * @throws HttpException 502 when Google Analytics answers with an error
     */
    public function build(array $campaign, bool $refresh = false): array
    {
        if (!$this->config->isConfigured()) {
            return ['connected' => false, 'missing' => $this->config->missing()];
        }
        $utm = Campaign::utm($campaign);
        $base = [
            'connected'   => true,
            'utm_enabled' => (bool) ($campaign['utm_enabled'] ?? false),
            'utm'         => ['source' => $utm['source'], 'medium' => $utm['medium'], 'campaign' => $utm['campaign']],
        ];
        if (!$base['utm_enabled']) {
            return $base;
        }

        $start = self::startDate($campaign);
        $cacheKey = md5((string) json_encode([$this->config->propertyId, $base['utm'], $start]));
        $file = self::cacheDir() . '/campaign-' . (int) $campaign['id'] . '.json';
        if (!$refresh && is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && ($cached['key'] ?? '') === $cacheKey && time() - (int) ($cached['at'] ?? 0) < self::CACHE_SECONDS) {
                return $base + $cached['data'];
            }
        }

        try {
            $data = $this->fetch($base['utm'], $start);
        } catch (Ga4Exception $e) {
            error_log('[EDM] campaigns_analytics: ' . $e->getMessage());
            throw new HttpException($e->getMessage(), 502);
        }
        $data['start_date'] = date('d-m-Y', (int) strtotime($start));
        $data['fetched_at'] = date('d-m-Y H:i:s');
        self::store($file, ['key' => $cacheKey, 'at' => time(), 'data' => $data]);

        return $base + $data;
    }

    /**
     * Totals, top landing pages and figures per utm_content in one batch call.
     *
     * @param array{source: string, medium: string, campaign: string} $utm
     * @return array<string, mixed>
     */
    private function fetch(array $utm, string $start): array
    {
        $common = [
            'dateRanges'      => [['startDate' => $start, 'endDate' => 'today']],
            'dimensionFilter' => ['andGroup' => ['expressions' => [
                self::equals('sessionSource', $utm['source']),
                self::equals('sessionMedium', $utm['medium']),
                self::equals('sessionCampaignName', $utm['campaign']),
            ]]],
        ];
        $top = static fn (string $dimension, array $metrics): array => $common + [
            'dimensions' => [['name' => $dimension]],
            'metrics'    => array_map(static fn (string $m): array => ['name' => $m], $metrics),
            'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit'      => self::TOP_ROWS,
        ];

        [$totals, $pages, $content] = (new Ga4Client($this->config))->batchRunReports([
            $common + ['metrics' => array_map(static fn (string $m): array => ['name' => $m], array_keys(self::TOTAL_METRICS))],
            $top('landingPage', ['sessions', 'keyEvents']),
            $top('sessionManualAdContent', ['sessions', 'keyEvents', 'purchaseRevenue']),
        ]) + [[], [], []];

        $values = array_map('floatval', array_column($totals['rows'][0]['metricValues'] ?? [], 'value'));
        $out = [];
        foreach (array_values(self::TOTAL_METRICS) as $i => $name) {
            $out[$name] = $values[$i] ?? 0.0;
        }
        $out['engagement_rate'] = round($out['engagement_rate'] * 100, 1);
        $out['avg_duration'] = (int) round($out['avg_duration']);
        $out['revenue'] = round($out['revenue'], 2);
        foreach (['sessions', 'users', 'engaged_sessions', 'page_views', 'key_events', 'purchases'] as $count) {
            $out[$count] = (int) $out[$count];
        }

        return [
            'totals'      => $out,
            'currency'    => (string) ($totals['metadata']['currencyCode'] ?? ''),
            'thresholded' => !empty($totals['metadata']['subjectToThresholding'])
                || !empty($pages['metadata']['subjectToThresholding']) || !empty($content['metadata']['subjectToThresholding']),
            'pages'       => self::rows($pages, ['sessions', 'key_events']),
            'content'     => self::rows($content, ['sessions', 'key_events', 'revenue']),
        ];
    }

    /**
     * One-dimension report rows as { label, <metric>... }.
     *
     * @param array<string, mixed> $report
     * @param list<string> $metrics output names, in request order
     * @return list<array<string, mixed>>
     */
    private static function rows(array $report, array $metrics): array
    {
        return array_map(static function (array $row) use ($metrics): array {
            $out = ['label' => (string) ($row['dimensionValues'][0]['value'] ?? '')];
            foreach ($metrics as $i => $name) {
                $v = (float) ($row['metricValues'][$i]['value'] ?? 0);
                $out[$name] = $name === 'revenue' ? round($v, 2) : (int) $v;
            }

            return $out;
        }, $report['rows'] ?? []);
    }

    /** @return array<string, mixed> a case-insensitive exact-match filter expression */
    private static function equals(string $field, string $value): array
    {
        return ['filter' => ['fieldName' => $field, 'stringFilter' => ['matchType' => 'EXACT', 'value' => $value, 'caseSensitive' => false]]];
    }

    /** The newsletter's creation day as YYYY-MM-DD (GA date range start). */
    private static function startDate(array $campaign): string
    {
        $created = (string) ($campaign['created_at'] ?? '');
        foreach (['d-m-Y H:i:s', 'Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $created);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return date('Y-m-d', strtotime('-90 days'));
    }

    private static function cacheDir(): string
    {
        return dirname(__DIR__, 3) . '/cache/ga4';
    }

    /** @param array<string, mixed> $payload */
    private static function store(string $file, array $payload): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($payload)) === false || !rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}
