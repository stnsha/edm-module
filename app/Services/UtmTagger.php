<?php

declare(strict_types=1);

namespace Edm\Services;

/**
 * Adds utm_source=edm, utm_medium=email and utm_campaign=<newsletter slug>
 * to every http(s) link that does not have them yet. Existing utm_* values
 * are kept. Applied to the rendered html (href attributes).
 *
 * $params (source, medium, campaign, content - Campaign::utm()) replace the
 * defaults; the send queue tags every link this way when the newsletter's
 * Tracking step has UTM on (Ses\CampaignMessage).
 */
final class UtmTagger
{
    public const SOURCE = 'edm';
    public const MEDIUM = 'email';

    /** @param array<string, string> $params */
    public function __construct(private string $campaign, private array $params = [])
    {
    }

    public static function isTagged(string $url): bool
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return isset($q['utm_source'], $q['utm_medium'], $q['utm_campaign']);
    }

    public static function slug(string $name): string
    {
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-') ?: 'campaign';
    }

    /** One URL with the missing utm_* parameters added. */
    public function tag(string $url): string
    {
        if (!preg_match('#^https?://#i', $url) || str_contains($url, '{{') || self::isTagged($url)) {
            return $url;
        }
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $add = array_diff_key(array_filter([
            'utm_source'   => ($this->params['source'] ?? '') !== '' ? $this->params['source'] : self::SOURCE,
            'utm_medium'   => ($this->params['medium'] ?? '') !== '' ? $this->params['medium'] : self::MEDIUM,
            'utm_campaign' => ($this->params['campaign'] ?? '') !== '' ? $this->params['campaign'] : self::slug($this->campaign),
            'utm_content'  => $this->params['content'] ?? '',
        ], static fn (string $v): bool => $v !== ''), $q);

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($add) . $fragment;
    }

    /** Tags href="..." links inside an HTML string. */
    public function html(string $html): string
    {
        return (string) preg_replace_callback(
            '/(\bhref\s*=\s*)(["\'])(.*?)\2/i',
            fn (array $m): string => $m[1] . $m[2] . htmlspecialchars($this->tag(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8')), ENT_QUOTES, 'UTF-8', false) . $m[2],
            $html
        );
    }
}
