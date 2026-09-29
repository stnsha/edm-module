<?php

declare(strict_types=1);

namespace Edm\Services\Qa;

/**
 * "Add UTM tags" (QA UTM parameter check): adds utm_source=edm,
 * utm_medium=email and utm_campaign=<campaign slug> to every http(s) link
 * that does not have them yet. Existing utm_* values are kept. Applied to
 * both the rendered html (href attributes) and the EmailBuilder.js
 * editor_json (any string that is a URL, or HTML with href attributes), so
 * the next Save in the Email creator keeps the tags.
 */
final class UtmTagger
{
    public const SOURCE = 'edm';
    public const MEDIUM = 'email';

    public function __construct(private string $campaign)
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
        $add = array_diff_key([
            'utm_source'   => self::SOURCE,
            'utm_medium'   => self::MEDIUM,
            'utm_campaign' => self::slug($this->campaign),
        ], $q);

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

    /**
     * Walks the EmailBuilder.js document ({ blockId: { type, data: { props } } })
     * block by block, so only real links are tagged: Button props.url, Image
     * props.linkHref (props.url is the image itself and is left alone),
     * markdown / HTML links in Text, Heading and Html blocks.
     */
    public function editorJson(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        if (isset($node['type'], $node['data']) && is_string($node['type']) && is_array($node['data'])) {
            $props = is_array($node['data']['props'] ?? null) ? $node['data']['props'] : [];
            switch ($node['type']) {
                case 'Button':
                    if (is_string($props['url'] ?? null)) {
                        $props['url'] = $this->tag($props['url']);
                    }
                    break;
                case 'Image':
                    if (is_string($props['linkHref'] ?? null)) {
                        $props['linkHref'] = $this->tag($props['linkHref']);
                    }
                    break;
                default:
                    foreach (['text', 'contents'] as $k) {
                        if (is_string($props[$k] ?? null)) {
                            $props[$k] = $this->markdown($this->html($props[$k]));
                        }
                    }
            }
            if ($props !== []) {
                $node['data']['props'] = $props;
            }

            return $node;
        }
        foreach ($node as $k => $v) {
            $node[$k] = $this->editorJson($v);
        }

        return $node;
    }

    /** Tags [label](https://...) links in markdown text. */
    private function markdown(string $text): string
    {
        return (string) preg_replace_callback(
            '/\]\((https?:\/\/[^)\s]+)\)/i',
            fn (array $m): string => '](' . $this->tag($m[1]) . ')',
            $text
        );
    }
}
