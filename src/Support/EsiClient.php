<?php
// namespace App\Support; // <- uncomment & adjust if you want a namespace

namespace CapsuleCmdr\Affinity\Support;

use Seat\Eveapi\Models\RefreshToken;

/**
 * Laravel-aware ESI helper (native cURL).
 * - Defaults X-Compatibility-Date to today's UTC date (Y-m-d).
 * - Build directly from a character_id via forCharacter($id).
 * - Reads SSO creds from config('services.eveonline.*').
 * - Auto-refreshes access token; persists rotated refresh_token.
 */
class EsiClient
{
    private string $base = 'https://esi.evetech.net';
    private string $datasource;
    private ?string $compatDate;
    private ?string $acceptLanguage;
    private string $userAgent;

    private ?string $accessToken;
    private ?string $refreshToken;
    private ?string $clientId;
    private ?string $clientSecret;
    private ?int $accessTokenExpiresAt;

    private ?int $characterId = null; // used to persist rotated refresh tokens
    private int $maxRetries = 3;

    /**
     * Construct directly (Laravel-safe).
     * If compat_date omitted, defaults to today's UTC (Y-m-d).
     */
    public function __construct(array $opts = [])
    {
        $this->datasource      = $opts['datasource']       ?? 'tranquility';
        $this->compatDate      = $opts['compat_date']      ?? gmdate('Y-m-d'); // <- default today (UTC)
        $this->acceptLanguage  = $opts['accept_language']  ?? null;
        $this->userAgent       = $opts['user_agent']       ?? 'CapsuleCmdr-Affinity/1.0 (+https://github.com/capsulecmdr/seat-affinity)';

        $this->accessToken     = $opts['access_token']     ?? null;
        $this->refreshToken    = $opts['refresh_token']    ?? null;
        $this->clientId        = $opts['client_id']        ?? null;
        $this->clientSecret    = $opts['client_secret']    ?? null;
        $this->accessTokenExpiresAt = $opts['access_token_expires_at'] ?? null;
        $this->characterId     = $opts['character_id']     ?? null;
    }

    /**
     * Build an authenticated client from a SeAT character_id.
     * - Pulls refresh token from Seat\Eveapi\Models\RefreshToken
     * - Uses config('services.eveonline.client_id/secret')
     * - Immediately refreshes to obtain a fresh access token
     */
    public static function forCharacter(int $characterId): self
    {
        // 1) Load refresh token row (SeAT DB)
        /** @var RefreshToken|null $row */
        $row = RefreshToken::where('character_id', $characterId)->first();
        if (!$row) {
            throw new \RuntimeException("No RefreshToken found for character_id {$characterId}");
        }

        // 2) Get SSO creds from config
        $clientId     = config('services.eveonline.client_id');
        $clientSecret = config('services.eveonline.client_secret');

        if (!$clientId || !$clientSecret) {
            throw new \RuntimeException("Missing eveonline SSO credentials in config('services.eveonline.*').");
        }

        // 3) Construct client with defaults (compat_date -> today)
        $client = new self([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $row->refresh_token,
            'character_id'  => $characterId,
            // 'accept_language' => 'en', // uncomment if you want localization
        ]);

        // 4) Get a fresh access token (and persist rotated refresh token if CCP issues one)
        $tok = $client->refreshAccessToken();

        // Persist rotated refresh token if present/changed
        if (!empty($tok['refresh_token']) && $tok['refresh_token'] !== $row->refresh_token) {
            $row->refresh_token = $tok['refresh_token'];
            $row->save();
        }

        return $client;
    }

    // ---------- Public convenience wrappers ----------
    public function get(string $path, array $query = [], array $headers = [], ?string $etag = null): array
    {
        return $this->request('GET', $path, $query, null, $headers, $etag);
    }

    public function delete(string $path, array $query = [], array $headers = [], ?array $body = null): array
    {
        return $this->request('DELETE', $path, $query, $body, $headers);
    }

    public function post(string $path, array $query = [], ?array $json = null, array $headers = []): array
    {
        return $this->request('POST', $path, $query, $json, $headers);
    }

    public function put(string $path, array $query = [], ?array $json = null, array $headers = []): array
    {
        return $this->request('PUT', $path, $query, $json, $headers);
    }

    // ---------- Core request ----------
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

            if ($status === 401 && $this->canRefresh()) {
                try {
                    $tok = $this->refreshAccessToken();
                    $headers['Authorization'] = 'Bearer ' . $this->accessToken;

                    // If CCP rotated refresh_token and we know characterId, persist it
                    if (!empty($tok['refresh_token']) && $this->characterId) {
                        /** @var RefreshToken|null $row */
                        $row = RefreshToken::where('character_id', $this->characterId)->first();
                        if ($row && $row->refresh_token !== $tok['refresh_token']) {
                            $row->refresh_token = $tok['refresh_token'];
                            $row->save();
                        }
                    }

                    // retry immediately after refresh
                    [$status, $respHeaders, $body] = $this->curlJson($method, $url, $headers, $json);
                } catch (\Throwable $e) {
                    $lastErr = $e;
                }
            }

            if (($status >= 200 && $status < 300) || $status === 304) {
                return $this->makeReturn($status, $respHeaders, $body);
            }

            if ($status === 420 || ($status >= 500 && $status <= 599)) {
                $sleep = $this->computeBackoff($attempt, $respHeaders);
                usleep($sleep * 1_000_000);
                continue;
            }

            return $this->makeReturn($status, $respHeaders, $body);

        } while ($attempt <= $this->maxRetries);

        if ($lastErr) {
            return ['status' => 0, 'headers' => [], 'error' => $lastErr->getMessage(), 'data' => null, 'raw' => null];
        }

        return [
            'status'  => $status ?? 0,
            'headers' => $respHeaders ?? [],
            'error'   => 'Max retries exceeded.',
            'data'    => $this->safeJson($body ?? ''),
            'raw'     => $body ?? null,
        ];
    }

    // ---------- SSO helpers ----------
    public function exchangeCodeForTokens(string $authCode, string $redirectUri): array
    {
        $this->ensureSsoConfig();
        $tokenEndpoint = 'https://login.eveonline.com/v2/oauth/token';

        $payload = [
            'grant_type'   => 'authorization_code',
            'code'         => $authCode,
            'redirect_uri' => $redirectUri,
        ];

        [$status, , $raw] = $this->curlJson('POST', $tokenEndpoint, $this->ssoHeaders(), $payload);
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
        $tokenEndpoint = 'https://login.eveonline.com/v2/oauth/token';

        $payload = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ];

        [$status, , $raw] = $this->curlJson('POST', $tokenEndpoint, $this->ssoHeaders(), $payload);
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
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    // ---------- Internal ----------
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
            'Accept'                 => 'application/json',
            'Content-Type'           => 'application/json',
            'User-Agent'             => $this->userAgent,
            'X-Compatibility-Date'   => $this->compatDate ?? gmdate('Y-m-d'),
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
        ];

        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES);
        }

        $opts[CURLOPT_HTTPHEADER] = $flatHeaders;

        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

    private function parseHeaders(string $raw): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($raw));
        $headers = [];
        foreach ($lines as $line) {
            if (stripos($line, 'HTTP/') === 0) { continue; }
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
                $reset = (float)$headers[$h];
                break;
            }
        }
        if ($reset !== null && $reset > 0) {
            return max(1.0, $reset) + (mt_rand(0, 300) / 1000.0);
        }
        $base = pow(2, $attempt - 1);
        return min(10.0, $base + (mt_rand(0, 500) / 1000.0));
    }

    private function ssoHeaders(): array
    {
        $basic = base64_encode($this->clientId . ':' . $this->clientSecret);
        return [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization'=> 'Basic ' . $basic,
            'User-Agent'   => $this->userAgent,
        ];
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
            $this->accessTokenExpiresAt = time() + ((int)$tok['expires_in'] - 30);
        }
    }
}
