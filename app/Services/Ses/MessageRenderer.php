<?php

declare(strict_types=1);

namespace Edm\Services\Ses;

/**
 * Fills personalisation variables ({{key}}) for one recipient.
 *
 * Known variables: {{email}}, {{member_code}} / {{MemberCode}},
 * {{unsubscribe_url}} / {{UnsubscribeLink}}. Contacts > Custom fields have no
 * per-member values stored yet, so any other {{key}} renders empty.
 *
 * The unsubscribe footer is mandatory (spec, dynamic content variables): when
 * the design has no unsubscribe variable, a footer with the link is appended.
 */
final class MessageRenderer
{
    private const UNSUBSCRIBE_KEYS = ['unsubscribe_url', 'unsubscribelink', 'unsubscribe'];

    /**
     * @param array{email: string, member_code?: ?string, unsubscribe_url: string} $vars
     */
    public static function html(string $html, array $vars): string
    {
        $hasUnsubscribe = false;
        $out = preg_replace_callback('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', static function (array $m) use ($vars, &$hasUnsubscribe): string {
            $key = strtolower($m[1]);
            if (in_array($key, self::UNSUBSCRIBE_KEYS, true)) {
                $hasUnsubscribe = true;
            }

            return htmlspecialchars(self::value($key, $vars), ENT_QUOTES, 'UTF-8');
        }, $html) ?? $html;

        if (!$hasUnsubscribe) {
            $out = self::appendFooter($out, $vars['unsubscribe_url']);
        }

        return $out;
    }

    /** @param array{email: string, member_code?: ?string, unsubscribe_url: string} $vars */
    public static function subject(string $subject, array $vars): string
    {
        return preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/',
            static fn (array $m): string => self::value(strtolower($m[1]), $vars),
            $subject
        ) ?? $subject;
    }

    /** @param array{email: string, member_code?: ?string, unsubscribe_url: string} $vars */
    private static function value(string $key, array $vars): string
    {
        return match (true) {
            $key === 'email' => $vars['email'],
            $key === 'member_code', $key === 'membercode' => (string) ($vars['member_code'] ?? ''),
            in_array($key, self::UNSUBSCRIBE_KEYS, true) => $vars['unsubscribe_url'],
            default => '',
        };
    }

    private static function appendFooter(string $html, string $url): string
    {
        $footer = '<div style="padding:16px;text-align:center;font:12px Arial,sans-serif;color:#888;">'
            . 'You received this email because you subscribed to our updates. '
            . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="color:#888;">Unsubscribe</a>'
            . '</div>';
        $pos = strripos($html, '</body>');

        return $pos === false ? $html . $footer : substr_replace($html, $footer, $pos, 0);
    }
}
