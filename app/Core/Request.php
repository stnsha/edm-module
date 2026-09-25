<?php

declare(strict_types=1);

namespace Edm\Core;

/**
 * The current api.php request: JSON body (or form POST), query string and the
 * resolved action name.
 */
final class Request
{
    /** @var array<string, mixed> */
    public readonly array $input;

    public readonly ?string $action;

    public function __construct()
    {
        $raw = json_decode((string) file_get_contents('php://input'), true);
        $this->input = is_array($raw) ? $raw : $_POST;
        $action = $_GET['action'] ?? $_POST['action'] ?? $this->input['action'] ?? null;
        $this->action = is_string($action) ? $action : null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** Record id from the query string or the body. */
    public function id(): int
    {
        return (int) ($_GET['id'] ?? $this->input['id'] ?? 0);
    }

    /**
     * Whitelisted body fields, strings trimmed; '' becomes null.
     *
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public function only(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (!array_key_exists($f, $this->input)) {
                continue;
            }
            $v = $this->input[$f];
            if (is_string($v)) {
                $v = trim($v);
                $v = $v === '' ? null : $v;
            }
            $out[$f] = $v;
        }

        return $out;
    }

    /**
     * Nullable integer foreign keys ('' / null -> null), only when present.
     *
     * @param list<string> $fields
     * @return array<string, int|null>
     */
    public function ids(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $this->input)) {
                $v = $this->input[$f];
                $out[$f] = ($v === '' || $v === null) ? null : (int) $v;
            }
        }

        return $out;
    }
}
