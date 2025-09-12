<?php

namespace CapsuleCmdr\Affinity\Support;

use Seat\Eveapi\Models\RefreshToken;

/**
 * EsiClient (Laravel-aware, native cURL)
 *
 * Features:
 * - GET/POST/PUT/DELETE (JSON) for ESI
 * - Auth via SeAT RefreshToken + SSO client credentials from config
 * - Auto token refresh; persists rotated refresh_token to DB
 * - ETag, retries with backoff on 420/5xx, nice errors
 * - X-Compatibility-Date defaults to today's UTC (Y-m-d)
 * - Safe default User-Agent for CCP debugging
 */
class EsiClient
{
    private string $base = 'https://esi.evetech.net';
    private string $datasource;
    private ?string $compatDate;         // defaults to today UTC
    private ?string $acceptLanguage;
    private string $userAgent;

    private ?string $accessToken;
    private ?string $refreshToken;
    private ?string $clientId;
    private ?string $clientSecret;
    private ?int    $accessTokenExpiresAt;

    private ?int $characterId = null;    // For persisting rotated refresh tokens
    private int  $maxRetries  = 3;

    /**
     * Construct directly. Omitted fields have sensible defaults.
     * opts:
     *  - datasource (default: 'tranquility')
     *  - compat_date (default: gmdate('Y-m-d'))
     *  - accept_language (e.g., 'en')
     *  - user_agent (default below)
     *  - access_token, refresh_token, client_id, client_secret
     *  - access_token_expires_at (unix time)
     *  - character_id (for rotating/persisting refresh token)
     */
    public function __construct(array $opts = [])
    {
        $this->datasource      = $opts['datasource']       ?? 'tranquility';
        $this->compatDate      = $opts['compat_date']      ?? gmdate('Y-m-d'); // default = today (UTC)
        $this->acceptLanguage  = $opts['accept_language']  ?? null;
        $this->userAgent       = $opts['user_agent']
            ?? 'CapsuleCmdr-Affinity/1.0 (+https://github.com/capsulecmdr/seat-affinity)';

        $this->accessToken     = $opts['access_token']     ?? null;
        $this->refreshToken    = $opts['refresh_token']    ?? null;
        $this->clientId        = $opts['client_id']        ?? null;
        $this->clientSecret    = $opts['client_secret']    ?? null;
        $this->accessTokenExpiresAt = $opts['access_token_expires_at'] ?? null;
        $this->characterId     = $opts['character_id']     ?? null;
    }

    /**
     * Build an authenticated client from a SeAT character_id.
     * - Loads RefreshToken from DB
     * - Loads SSO creds from config('services.eveonline.client_id/secret')
     * - Refreshes immediately to obtain a fresh access_token
     * - Persists rotated refresh_token if CCP sends one
     */
    public static function forCharacter(int $characterId): self
    {
        /** @var RefreshToken|null $row */
        $row = RefreshToken::where('character_id', $characterId)->first();
        if (!$row) {
            throw new \RuntimeException("No RefreshToken found for character_id {$characterId}");
        }

        $clientId     = config('services.eveonline.client_id');
        $clientSecret = config('services.eveonline.client_secret');

        if (!$clientId || !$clientSecret) {
            throw new \RuntimeException("Missing SSO creds in config('services.eveonline.{client_id,client_secret}').");
        }

        $client = new self([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $row->refresh_token,
            'character_id'  => $characterId,
            // 'accept_language' => 'en',
            // 'compat_date' => gmdate('Y-m-d'), // implicit default
        ]);

        $tok = $client->refreshAccessToken();

        if (!empty($tok['refresh_token']) && $tok['refresh_token'] !== $row->refresh_token) {
            $row->refresh_token = $tok['refresh_token'];
            $row->save();
        }

        return $client;
    }

    // ---- Convenience wrappers ------------------------------------------------

    public function get(string $path, array $query = [], array $headers = [], ?string $etag = null): array
    {
        return $this->request('GET', $path, $query, null, $headers, $etag);
    }

    public function post(string $path, array $query = [], ?array $json = null, array $headers = []): array
    {
        return $this->request('POST', $path, $query, $json, $headers);
    }

    public function put(string $path, array $query = [], ?array $json = null, array $headers = []): array
    {
        return $this->request('PUT', $path, $query, $json, $headers);
    }

    public function delete(string $path, array $query = [], array $headers = [], ?array $json = null): array
    {
        return $this->request('DELETE', $path, $query, $json, $headers);
    }

    // ---- Core request --------------------------------------------------------

    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        array $extraHeaders = [],
        ?string $etag = null
    ): array {
        if (!array_key_exists('datasource', $query)) {
            $query['datasource'] = $this->datasource;
        }

        $url = $this->buildUrl($path, $query);
        $headers = $this->buildHeaders($etag, $extraHeaders);

        if ($this->accessToken && !isset($headers['Authorization'])) {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        $attempt = 0;
        $lastErr = null;

        do {
            $attempt++;
            [$status, $respHeaders, $body] = $this->curlJson($method, $url, $headers, $json);

            // 401 → try refresh once (if possible), then retry
            if ($status === 401 && $this->canRefresh()) {
                try {
                    $tok = $this->refreshAccessToken();
                    $headers['Authorization'] = 'Bearer ' . $this->accessToken;

                    // persist rotated refresh token if character known
                    if (!empty($tok['refresh_token']) && $this->characterId) {
                        /** @var RefreshToken|null $row */
                        $row = RefreshToken::where('character_id', $this->characterId)->first();
                        if ($row && $row->refresh_token !== $tok['refresh_token']) {
                            $row->refresh_token = $tok['refresh_token'];
                            $row->save();
                        }
                    }

                    // retry after refresh
                    [$status, $respHeaders, $body] = $this->curlJson($method, $url, $headers, $json);
                } catch (\Throwable $e) {
                    $lastErr = $e;
                }
            }

            // Success or Not Modified
            if (($status >= 200 && $status < 300) || $status === 304) {
                return $this->makeReturn($status, $respHeaders, $body);
            }

            // Retry on 420 or 5xx with jittered backoff
            if ($status === 420 || ($status >= 500 && $status <= 599)) {
                $sleep = $this->computeBackoff($attempt, $respHeaders);
                usleep($sleep * 1_000_000);
                continue;
            }

            // Non-retryable
            return $this->makeReturn($status, $respHeaders, $body);

        } while ($attempt <= $this->maxRetries);

        if ($lastErr) {
            return [
                'status'  => 0,
                'headers' => [],
                'error'   => $lastErr->getMessage(),
                'data'    => null,
                'raw'     => null,
            ];
        }

        return [
            'status'  => $status ?? 0,
            'headers' => $respHeaders ?? [],
            'error'   => 'Max retries exceeded.',
            'data'    => $this->safeJson($body ?? ''),
            'raw'     => $body ?? null,
        ];
    }

    // ---- SSO (form-encoded) -------------------------------------------------

    public function exchangeCodeForTokens(string $authCode, string $redirectUri): array
    {
        $this->ensureSsoConfig();
        $endpoint = 'https://login.eveonline.com/v2/oauth/token';

        $form = [
            'grant_type'   => 'authorization_code',
            'code'         => $authCode,
            'redirect_uri' => $redirectUri,
        ];

        [$status, , $raw] = $this->curlForm('POST', $endpoint, $this->ssoHeaders(), $form);

        if ($status !== 200) {
            throw new \RuntimeException("SSO token exchange failed: HTTP $status — $raw");
        }

        $tok = json_decode($raw, true);
        $this->applyTokenResult($tok);
        return $tok;
    }

    public function refreshAccessToken(): array
    {
        $this->ensureSsoConfig(true);
        $endpoint = 'https://login.eveonline.com/v2/oauth/token';

        $form = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ];

        [$status, , $raw] = $this->curlForm('POST', $endpoint, $this->ssoHeaders(), $form);

        // Friendlier message for revoked/expired refresh tokens
        if ($status === 400 && stripos($raw, 'invalid_grant') !== false) {
            throw new \RuntimeException(
                "SSO refresh failed: invalid_grant (token revoked/expired). Re-auth the character."
            );
        }

        if ($status !== 200) {
            throw new \RuntimeException("SSO token refresh failed: HTTP $status — $raw");
        }

        $tok = json_decode($raw, true);
        $this->applyTokenResult($tok);
        return $tok;
    }

    public function setAccessToken(string $token, ?int $expiresAt = null): void
    {
        $this->accessToken = $token;
        $this->accessTokenExpiresAt = $expiresAt;
    }

    public function setRefreshCredentials(string $refreshToken, string $clientId, string $clientSecret): void
    {
        $this->refreshToken = $refreshToken;
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
    }

    // ---- Internals -----------------------------------------------------------

    private function buildUrl(string $path, array $query): string
    {
        $isAbsolute = str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
        $url = $isAbsolute ? $path : rtrim($this->base, '/') . '/' . ltrim($path, '/');

        if (!empty($query)) {
            $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $url .= (str_contains($url, '?') ? '&' : '?') . $qs;
        }
        return $url;
    }

    private function buildHeaders(?string $etag, array $extra): array
    {
        $headers = [
            'Accept'               => 'application/json',
            'Content-Type'         => 'application/json',
            'User-Agent'           => $this->userAgent,
            'X-Compatibility-Date' => $this->compatDate ?? gmdate('Y-m-d'),
        ];

        if ($this->acceptLanguage) {
            $headers['Accept-Language'] = $this->acceptLanguage;
        }
        if ($etag) {
            $headers['If-None-Match'] = $etag;
        }
        foreach ($extra as $k => $v) {
            $headers[$k] = $v;
        }
        return $headers;
    }

    private function ssoHeaders(): array
    {
        $basic = base64_encode(($this->clientId ?? '') . ':' . ($this->clientSecret ?? ''));
        return [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/x-www-form-urlencoded', // IMPORTANT
            'Authorization' => 'Basic ' . $basic,
            'User-Agent'    => $this->userAgent,
        ];
    }

    private function curlJson(string $method, string $url, array $headers, ?array $json): array
    {
        $ch = curl_init();
        $flatHeaders = [];
        foreach ($headers as $k => $v) {
            $flatHeaders[] = $k . ': ' . $v;
        }

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $flatHeaders,
        ];

        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $opts);
        $resp    = curl_exec($ch);
        $err     = curl_error($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('cURL error: ' . $err);
        }

        $rawHeaders = substr($resp, 0, $hdrSize);
        $body       = substr($resp, $hdrSize);
        $parsedHeaders = $this->parseHeaders($rawHeaders);

        return [$code, $parsedHeaders, $body];
    }

    private function curlForm(string $method, string $url, array $headers, array $form): array
    {
        $ch = curl_init();
        $flat = [];
        foreach ($headers as $k => $v) {
            $flat[] = $k . ': ' . $v;
        }

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $flat,
            CURLOPT_POSTFIELDS     => http_build_query($form, '', '&', PHP_QUERY_RFC3986),
        ];

        curl_setopt_array($ch, $opts);
        $resp    = curl_exec($ch);
        $err     = curl_error($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('cURL error: ' . $err);
        }

        $rawHeaders = substr($resp, 0, $hdrSize);
        $body       = substr($resp, $hdrSize);

        return [$code, $this->parseHeaders($rawHeaders), $body];
    }

    private function parseHeaders(string $raw): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($raw));
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '' || stripos($line, 'HTTP/') === 0) {
                continue; // skip status lines
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $name = trim(substr($line, 0, $pos));
                $val  = trim(substr($line, $pos + 1));
                if (isset($headers[$name])) {
                    if (is_array($headers[$name])) {
                        $headers[$name][] = $val;
                    } else {
                        $headers[$name] = [$headers[$name], $val];
                    }
                } else {
                    $headers[$name] = $val;
                }
            }
        }
        return $headers;
    }

    private function makeReturn(int $status, array $headers, string $body): array
    {
        return [
            'status'  => $status,
            'headers' => $headers,
            'data'    => $this->safeJson($body),
            'raw'     => $body,
        ];
    }

    private function safeJson(string $body)
    {
        $trim = ltrim($body);
        if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) {
            return null;
        }
        $decoded = json_decode($body, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
    }

    private function computeBackoff(int $attempt, array $headers): float
    {
        $reset = null;
        foreach (['X-Esi-Error-Limit-Reset', 'X-Rate-Limit-Reset'] as $h) {
            if (isset($headers[$h])) {
                $reset = (float) $headers[$h];
                break;
            }
        }
        if ($reset !== null && $reset > 0) {
            return max(1.0, $reset) + (mt_rand(0, 300) / 1000.0);
        }
        $base = pow(2, $attempt - 1); // 1,2,4,8...
        return min(10.0, $base + (mt_rand(0, 500) / 1000.0));
    }

    private function ensureSsoConfig(bool $needsRefreshToken = false): void
    {
        if (!$this->clientId || !$this->clientSecret) {
            throw new \InvalidArgumentException('SSO client_id/client_secret not set.');
        }
        if ($needsRefreshToken && !$this->refreshToken) {
            throw new \InvalidArgumentException('SSO refresh_token not set.');
        }
    }

    private function canRefresh(): bool
    {
        return $this->clientId && $this->clientSecret && $this->refreshToken;
    }

    private function applyTokenResult(array $tok): void
    {
        if (isset($tok['access_token'])) {
            $this->accessToken = $tok['access_token'];
        }
        if (isset($tok['refresh_token'])) {
            $this->refreshToken = $tok['refresh_token'];
        }
        if (isset($tok['expires_in'])) {
            $this->accessTokenExpiresAt = time() + ((int) $tok['expires_in'] - 30);
        }
    }
}
