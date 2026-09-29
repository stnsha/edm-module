<?php

declare(strict_types=1);

namespace Edm\Services\Qa;

use CurlHandle;

/**
 * Checks that links / images in a campaign load, for the automated QA
 * (broken link check, image load check). All URLs are probed in parallel
 * (curl_multi), redirects are followed by hand so every hop is checked.
 *
 * Only public http / https addresses are fetched: a host that resolves to a
 * private, loopback or reserved address is not requested (the resolved IP is
 * pinned with CURLOPT_RESOLVE, so DNS cannot switch it afterwards). Such an
 * address is reported as "local" - recipients cannot load it either.
 */
final class UrlProbe
{
    private const TIMEOUT = 8;
    private const MAX_REDIRECTS = 5;
    private const MAX_URLS = 60;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; EDM-QA-LinkCheck/1.0)';

    /**
     * @param list<string> $urls
     * @return array<string, array{ok: bool, local: bool, status: int, type: ?string, error: ?string, final: string}>
     */
    public function probe(array $urls): array
    {
        $urls = array_slice(array_values(array_unique($urls)), 0, self::MAX_URLS);
        $state = [];
        foreach ($urls as $u) {
            $state[$u] = ['current' => $u, 'hops' => 0, 'method' => 'HEAD', 'done' => null];
        }

        for ($round = 0; $round <= self::MAX_REDIRECTS + 1; $round++) {
            $pending = array_filter($state, static fn (array $s): bool => $s['done'] === null);
            if ($pending === []) {
                break;
            }
            $multi = curl_multi_init();
            $handles = [];
            foreach ($pending as $orig => $s) {
                $target = $this->resolve($s['current']);
                if (isset($target['error'])) {
                    $state[$orig]['done'] = $this->result(false, (bool) ($target['local'] ?? false), 0, null, $target['error'], $s['current']);
                    continue;
                }
                $ch = $this->handle($s['current'], $target, $s['method']);
                $handles[$orig] = $ch;
                curl_multi_add_handle($multi, $ch);
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $orig => $ch) {
                $s = $state[$orig];
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                $err = curl_error($ch);
                $location = $this->headerValue((string) curl_multi_getcontent($ch), 'location');
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);

                if ($code === 0) {
                    $state[$orig]['done'] = $this->result(false, false, 0, null, $err !== '' ? $err : 'No response', $s['current']);
                } elseif ($code >= 300 && $code < 400 && $location !== null) {
                    if ($s['hops'] >= self::MAX_REDIRECTS) {
                        $state[$orig]['done'] = $this->result(false, false, $code, null, 'Too many redirects', $s['current']);
                    } else {
                        $state[$orig]['current'] = $this->absolute($location, $s['current']);
                        $state[$orig]['hops']++;
                        $state[$orig]['method'] = 'HEAD';
                    }
                } elseif ($s['method'] === 'HEAD' && in_array($code, [403, 405, 501], true)) {
                    // Some servers refuse HEAD: ask again with GET.
                    $state[$orig]['method'] = 'GET';
                } else {
                    $state[$orig]['done'] = $this->result($code < 400, false, $code, is_string($type) ? $type : null, $code >= 400 ? 'HTTP ' . $code : null, $s['current']);
                }
            }
            curl_multi_close($multi);
        }

        $out = [];
        foreach ($state as $orig => $s) {
            $out[$orig] = $s['done'] ?? $this->result(false, false, 0, null, 'Not checked', $s['current']);
        }

        return $out;
    }

    /**
     * Host -> a public IP to pin, or an error when the URL is not a public
     * http(s) address.
     *
     * @return array{host: string, port: int, ip: string}|array{error: string, local?: bool}
     */
    private function resolve(string $url): array
    {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['error' => 'Not a web address'];
        }
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return ['error' => 'Domain not found'];
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return ['error' => 'Local address - not reachable by recipients', 'local' => true];
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** @param array{host: string, port: int, ip: string} $target */
    private function handle(string $url, array $target, string $method): CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE        => [$target['host'] . ':' . $target['port'] . ':' . $target['ip']],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => $method === 'HEAD',
            CURLOPT_RANGE          => $method === 'GET' ? '0-1023' : null,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        return $ch;
    }

    private function headerValue(string $raw, string $name): ?string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $raw, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $root = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($b['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $root . $location;
        }
        $dir = rtrim(dirname((string) ($b['path'] ?? '/')), '/\\');

        return $root . $dir . '/' . $location;
    }

    /** @return array{ok: bool, local: bool, status: int, type: ?string, error: ?string, final: string} */
    private function result(bool $ok, bool $local, int $status, ?string $type, ?string $error, string $final): array
    {
        return ['ok' => $ok, 'local' => $local, 'status' => $status, 'type' => $type, 'error' => $error, 'final' => $final];
    }
}
