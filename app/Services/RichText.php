<?php

declare(strict_types=1);

namespace Edm\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans HTML from a Quill editor before it is stored: only the tags Quill
 * produces survive, attributes are limited to Quill's own (ql-* classes,
 * colour styles, http / https / mailto links), everything else (scripts,
 * event handlers, javascript: URLs, embedded images) is dropped.
 */
final class RichText
{
    private const TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h1', 'h2', 'h3', 'ol', 'ul', 'li',
        'a', 'span', 'blockquote', 'pre', 'code', 'sub', 'sup',
    ];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="rt-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('rt-root');
        if ($root === null) {
            return '';
        }
        self::walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /** True when the HTML holds no visible text (Quill's empty "<p><br></p>"). */
    public static function isEmpty(?string $html): bool
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{00A0}") === '';
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'img', 'svg', 'math', 'form', 'input', 'button'], true)) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child);
            if (!in_array($tag, self::TAGS, true)) {
                // Unknown wrapper: keep its content, drop the tag.
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            self::cleanAttributes($child, $tag);
        }
    }

    private static function cleanAttributes(DOMElement $el, string $tag): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = trim($attr->value);
            $keep = match ($name) {
                'class' => preg_match('/^(ql-[a-z0-9-]+\s*)+$/', $value) === 1,
                'style' => preg_match('/^((background-)?color:\s*(#[0-9a-f]{3,6}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\))\s*;?\s*)+$/i', $value) === 1,
                'href'  => $tag === 'a' && preg_match('#^(https?://|mailto:)#i', $value) === 1,
                'target' => $tag === 'a' && $value === '_blank',
                'rel'   => $tag === 'a',
                default => false,
            };
            if (!$keep) {
                $el->removeAttribute($attr->name);
            }
        }
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }
}
