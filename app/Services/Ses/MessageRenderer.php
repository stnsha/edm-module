<?php

declare(strict_types=1);

namespace Edm\Services\Ses;

/**
 * Fills personalisation variables ({{key}}) for one recipient.
 *
 * Known variables: {{email}}, {{name}}, {{first_name}} / {{FirstName}}
 * (first word of the name), {{last_name}} / {{LastName}} (the rest),
 * {{member_code}} / {{MemberCode}}, {{unsubscribe_url}} /
 * {{UnsubscribeLink}}, and every Contacts > Custom field key from the
 * contact's stored values (edm_list_members.fields, filled by Import),
 * matched ignoring case and underscores (fieldKey(): {{PointsBalance}} =
 * points_balance). A variable with no value renders empty.
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

    /**
     * @param array{email: string, member_code?: ?string, name?: ?string,
     *              fields?: ?array<string, string>, unsubscribe_url: string} $vars
     */
    private static function value(string $key, array $vars): string
    {
        $name = trim((string) ($vars['name'] ?? ''));
        $fields = is_array($vars['fields'] ?? null) ? $vars['fields'] : [];
        $parts = $name !== '' ? preg_split('/\s+/', $name) : [];

        return match (true) {
            $key === 'email' => $vars['email'],
            $key === 'member_code', $key === 'membercode' => (string) ($vars['member_code'] ?? ''),
            $key === 'name' => $name,
            $key === 'first_name', $key === 'firstname' => (string) ($parts[0] ?? ''),
            // Everything after the first word of the name (spec {{LastName}}).
            $key === 'last_name', $key === 'lastname' => implode(' ', array_slice($parts, 1)),
            in_array($key, self::UNSUBSCRIBE_KEYS, true) => $vars['unsubscribe_url'],
            default => (string) ($fields[self::fieldKey($key, array_keys($fields)) ?? ''] ?? ''),
        };
    }

    /**
     * The custom field key a {{variable}} names, or null: matched ignoring
     * case and underscores, so the spec's {{MembershipType}} / {{PointsBalance}}
     * find the membership_type / points_balance fields.
     *
     * @param list<string> $keys custom field keys
     */
    public static function fieldKey(string $variable, array $keys): ?string
    {
        $want = str_replace('_', '', strtolower($variable));
        foreach ($keys as $k) {
            if (str_replace('_', '', strtolower((string) $k)) === $want) {
                return (string) $k;
            }
        }

        return null;
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
