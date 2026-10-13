<?php
/**
 * HTTP transport shared by the chatbot provider adapters (PHP curl — no SDKs / Composer).
 *
 *   chatbot_http_post($url, $headers, $payload, $timeout)            -> ['status', 'body', 'error', 'errno', 'ms']
 *   chatbot_http_post($url, $headers, $payload, $timeout, $onChunk)  -> streaming: 2xx body chunks go to $onChunk,
 *                                                                       error bodies are still collected in 'body'
 *   chatbot_sse_parser($onEvent)                                      -> closure(string $chunk) feeding a
 *                                                                       Server-Sent-Events parser: $onEvent($event, $data)
 *
 * TLS verification is always on. When php.ini has no CA bundle configured, a bundle is looked up in the usual places
 * (GIMT_CA_BUNDLE env, storage/cacert.pem, XAMPP's apache/bin/curl-ca-bundle.crt, php/extras/ssl/cacert.pem).
 */

/** CA bundle for curl when php.ini does not configure one (never disables verification). */
function chatbot_ca_bundle(): ?string
{
    static $path = false;
    if ($path !== false) {
        return $path;
    }
    $path = null;
    if (ini_get('curl.cainfo') || ini_get('openssl.cafile')) {
        return $path;
    }
    $bin = dirname((string) PHP_BINARY);
    $candidates = [getenv('GIMT_CA_BUNDLE') ?: null, APP_ROOT . '/storage/cacert.pem', $bin . '/curl-ca-bundle.crt', $bin . '/extras/ssl/cacert.pem',
        dirname($bin) . '/apache/bin/curl-ca-bundle.crt', dirname($bin) . '/php/extras/ssl/cacert.pem'];
    foreach ($candidates as $p) {
        if ($p && is_file($p)) {
            return $path = $p;
        }
    }
    return $path;
}

/**
 * POST a JSON payload over HTTPS.
 * @param array<string,string> $headers extra headers (name => value)
 * @param callable|null $onChunk streaming receiver for successful (2xx) response bytes
 * @return array{status:int, body:string, error:?string, errno:int, ms:int}
 */
function chatbot_http_post(string $url, array $headers, array $payload, int $timeout, ?callable $onChunk = null): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'error' => 'The PHP curl extension is not enabled on this server.', 'errno' => -1, 'ms' => 0];
    }
    $started = microtime(true);
    $lines = ['Content-Type: application/json', 'Accept: ' . ($onChunk ? 'text/event-stream' : 'application/json')];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER => $lines,
        CURLOPT_CONNECTTIMEOUT => max(2, min(8, $timeout)),
        CURLOPT_TIMEOUT => max(3, $timeout),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'GIMT-SmartCampus-Chatbot/1.0 (+php-curl)',
        CURLOPT_ENCODING => '',
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if ($ca = chatbot_ca_bundle()) {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    $body = '';
    if ($onChunk) {
        $opts[CURLOPT_WRITEFUNCTION] = function ($handle, string $chunk) use (&$body, $onChunk): int {
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($code >= 200 && $code < 300) {
                $onChunk($chunk);
            } elseif (strlen($body) < 65536) {
                $body .= $chunk;
            }
            return strlen($chunk);
        };
    } else {
        $opts[CURLOPT_RETURNTRANSFER] = true;
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    if (!$onChunk && is_string($res)) {
        $body = $res;
    }
    $errno = curl_errno($ch);
    $error = $errno ? curl_error($ch) : null;
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body, 'error' => $error, 'errno' => $errno, 'ms' => (int) round((microtime(true) - $started) * 1000)];
}

/**
 * Incremental Server-Sent-Events parser. Feed it raw chunks; it calls $onEvent(string $event, string $data) for every
 * complete event (multi-line data joined with "\n"; event defaults to "message"). Comments (":") are ignored.
 */
function chatbot_sse_parser(callable $onEvent): Closure
{
    $buffer = '';
    return function (string $chunk) use (&$buffer, $onEvent): void {
        $buffer .= str_replace("\r\n", "\n", $chunk);
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $block = substr($buffer, 0, $pos);
            $buffer = (string) substr($buffer, $pos + 2);
            $event = 'message';
            $data = [];
            foreach (explode("\n", $block) as $line) {
                if ($line === '' || $line[0] === ':') {
                    continue;
                }
                [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
                $value = ltrim($value, ' ');
                if ($field === 'event') {
                    $event = $value;
                } elseif ($field === 'data') {
                    $data[] = $value;
                }
            }
            if ($data) {
                $onEvent($event, implode("\n", $data));
            }
        }
    };
}

/** Classify an HTTP / transport failure into a short error type + whether another provider should be tried. */
function chatbot_http_error_type(int $status, int $errno): string
{
    if ($errno === 28) {
        return 'timeout';
    }
    if ($errno) {
        return in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true) ? 'tls' : 'network';
    }
    return match (true) {
        $status === 401, $status === 403 => 'auth',
        $status === 402 => 'billing',
        $status === 404 => 'not_found',
        $status === 408 => 'timeout',
        $status === 413 => 'too_large',
        $status === 429 => 'rate_limit',
        $status === 529, $status === 503 => 'overloaded',
        $status >= 500 => 'server',
        $status >= 400 => 'bad_request',
        default => 'unknown',
    };
}

/** Human-readable hint for admins, appended to provider error messages. */
function chatbot_error_hint(string $type, string $provider): string
{
    return match ($type) {
        'auth' => 'Check the ' . $provider . ' API key (it may be wrong, revoked or lack access).',
        'billing' => 'The provider account has a billing problem — add credit or a payment method.',
        'not_found' => 'The model id is not available for this key — pick another model.',
        'rate_limit' => 'The provider rate limit / quota was reached — try later or raise the limit.',
        'overloaded', 'server' => 'The provider is temporarily unavailable — the bot falls back automatically.',
        'timeout' => 'The provider did not answer within the timeout — the bot falls back automatically.',
        'tls' => 'This server cannot verify HTTPS certificates — set curl.cainfo in php.ini to a CA bundle (cacert.pem).',
        'network' => 'This server cannot reach the provider — check outbound HTTPS (firewall / proxy / DNS).',
        default => '',
    };
}
