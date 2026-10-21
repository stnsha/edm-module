<?php

declare(strict_types=1);

namespace Edm\Services\Analytics;

use CurlHandle;

/**
 * Google Analytics Data API (v1beta) client. The only class that talks to
 * Google Analytics. Authenticates as the service account in
 * GA4_CREDENTIALS_JSON: a JWT signed with its private key (RS256) is
 * exchanged for a one-hour read-only access token, then reports are run
 * against GA4_PROPERTY_ID. No SDK - curl and openssl only.
 *
 * Every failure is a Ga4Exception with a message staff can act on.
 */
final class Ga4Client
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_URL = 'https://analyticsdata.googleapis.com/v1beta/properties/';

    private ?string $token = null;

    public function __construct(private Ga4Config $config)
    {
        if (!$config->isConfigured()) {
            throw new Ga4Exception('Google Analytics is not configured (' . implode(', ', $config->missing()) . ' in edm/.env).');
        }
    }

    /**
     * Runs up to 5 report requests in one call (properties.batchRunReports).
     *
     * @param list<array<string, mixed>> $requests RunReportRequest bodies
     * @return list<array<string, mixed>> the reports, in request order
     */
    public function batchRunReports(array $requests): array
    {
        $ch = $this->curl(self::API_URL . $this->config->propertyId . ':batchRunReports', 60);
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => json_encode(['requests' => $requests], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token(), 'Content-Type: application/json'],
        ]);
        [$status, $data] = $this->send($ch);

        if ($status !== 200) {
            $message = (string) ($data['error']['message'] ?? 'HTTP ' . $status);
            if (str_contains($message, 'sufficient permissions')) {
                throw new Ga4Exception('The service account ' . $this->config->clientEmail() . ' has no access to GA4 property '
                    . $this->config->propertyId . '. Add it as a Viewer in Google Analytics (Admin > Property access management).');
            }
            throw new Ga4Exception('Google Analytics: ' . $message);
        }

        return array_values($data['reports'] ?? []);
    }

    /** A read-only access token for this request (fetched once, valid one hour). */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $key = $this->config->credentials;
        $now = time();
        $unsigned = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)) . '.'
            . self::base64Url(json_encode([
                'iss'   => $key['client_email'],
                'scope' => self::SCOPE,
                'aud'   => self::TOKEN_URL,
                'iat'   => $now,
                'exp'   => $now + 3600,
            ], JSON_THROW_ON_ERROR));

        $private = openssl_pkey_get_private((string) $key['private_key']);
        if ($private === false || !openssl_sign($unsigned, $signature, $private, OPENSSL_ALGO_SHA256)) {
            throw new Ga4Exception('The private key in GA4_CREDENTIALS_JSON could not be read.');
        }

        $ch = $this->curl(self::TOKEN_URL, 20);
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $unsigned . '.' . self::base64Url($signature),
            ]),
        ]);
        [$status, $data] = $this->send($ch);

        if ($status !== 200 || !is_string($data['access_token'] ?? null)) {
            $error = (string) ($data['error'] ?? 'HTTP ' . $status);
            throw new Ga4Exception($error === 'invalid_grant'
                ? 'Google refused the service account key (invalid_grant): the key may have been deleted, or the server clock is wrong.'
                : 'Google sign-in for the service account failed: ' . $error . '.');
        }

        return $this->token = $data['access_token'];
    }

    /**
     * Executes a prepared request.
     *
     * @return array{0: int, 1: array<string, mixed>} HTTP status and decoded JSON body
     */
    private function send(CurlHandle $ch): array
    {
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Ga4Exception('Could not reach Google Analytics: ' . $error);
        }
        $data = json_decode((string) $body, true);

        return [$status, is_array($data) ? $data : []];
    }

    private function curl(string $url, int $timeout): CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        return $ch;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
