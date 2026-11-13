<?php
declare(strict_types=1);
namespace ForgeTrackingProxy;

// Public relay only: credentials and HMAC verification stay in the existing backend.
const BACKEND = 'https://xoibisxpbmhmqgyqieee.supabase.co/functions/v1/b2b-cs3d-forge-track';
const RESPONSE_HEADERS = [
    'Cache-Control' => 'no-store, private, max-age=0',
    'Referrer-Policy' => 'no-referrer',
    'X-Content-Type-Options' => 'nosniff',
];

function dispatch(array $server, callable $transport): array
{
    $method = $server['REQUEST_METHOD'] ?? '';
    if ($method !== 'GET' && $method !== 'HEAD') {
        return [405, RESPONSE_HEADERS + ['Allow' => 'GET, HEAD'], 'Method not allowed'];
    }
    $query = $server['QUERY_STRING'] ?? '';
    if (strlen($query) > 8192 || preg_match('/[\x00-\x20\x7f#]/', $query)) {
        return [400, RESPONSE_HEADERS, 'Invalid request'];
    }
    $agent = substr((string) ($server['HTTP_USER_AGENT'] ?? ''), 0, 512);
    if (preg_match('/[\x00-\x1f\x7f]/', $agent)) {
        $agent = '';
    }
    try {
        // Never forward cookies, authorization, referer, forwarding headers or a recipient IP.
        [$status, $upstreamHeaders, $body] = $transport(
            BACKEND . ($query !== '' ? '?' . $query : ''), $method, $agent
        );
        if (!is_int($status) || $status < 200 || $status > 599 || strlen($body) > 65536) {
            throw new \RuntimeException('Invalid upstream response');
        }
        $headers = RESPONSE_HEADERS;
        foreach (['content-type' => 'Content-Type', 'location' => 'Location'] as $key => $name) {
            $value = $upstreamHeaders[$key] ?? null;
            if (is_string($value) && !preg_match('/[\x00-\x1f\x7f]/', $value)) {
                $headers[$name] = $value;
            }
        }
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'text/plain; charset=utf-8';
        return [$status, $headers, $method === 'HEAD' ? '' : $body];
    } catch (\Throwable $error) {
        return [503, RESPONSE_HEADERS + ['Content-Type' => 'text/plain; charset=utf-8'], 'Temporarily unavailable'];
    }
}

function requestBackend(string $url, string $method, string $agent): array
{
    if (!\function_exists('curl_init')) {
        throw new \RuntimeException('cURL unavailable');
    }
    $handle = \curl_init($url);
    if ($handle === false) {
        throw new \RuntimeException('Connection unavailable');
    }
    $body = '';
    $headers = [];
    \curl_setopt_array($handle, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_USERAGENT => $agent,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                if ($name === 'content-type' || $name === 'location') {
                    $headers[$name] = trim($parts[1]);
                }
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 65536) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = \curl_exec($handle);
    $status = (int) \curl_getinfo($handle, CURLINFO_HTTP_CODE);
    \curl_close($handle);
    if ($ok === false) {
        throw new \RuntimeException('Connection unavailable');
    }
    return [$status, $headers, $body];
}

if (!defined('FORGE_PROXY_TEST_MODE')) {
    [$status, $headers, $body] = dispatch($_SERVER, __NAMESPACE__ . '\\requestBackend');
    \http_response_code($status);
    foreach ($headers as $name => $value) {
        \header($name . ': ' . $value);
    }
    echo $body;
}
