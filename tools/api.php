<?php
/**
 * Command-line client for the JSON API (development helper). Logs in once, keeps the session cookie and
 * CSRF token in tools/.cache/api-<user>.json, then performs the request and prints the JSON response.
 *
 *   php tools/api.php GET  crud/fees/meta
 *   php tools/api.php GET  "crud/students?per_page=5&f[status]=active"
 *   php tools/api.php POST crud/departments '{"name":"Law","code":"LAW","status":"active"}'
 *   php tools/api.php DELETE crud/departments/9
 *   php tools/api.php --user accounts@gimt.ac.in --pass Demo@12345 GET fees/summary
 *
 * Options: --base http://127.0.0.1:8000 (or GIMT_BASE env), --user, --pass, --raw (print body as-is), --fresh (new login)
 * Exit code: 0 when the API answered ok:true, 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$args = array_slice($argv, 1);
$opt = function (string $name, $default = null) use (&$args) {
    $i = array_search('--' . $name, $args, true);
    if ($i === false) {
        return $default;
    }
    $v = $args[$i + 1] ?? null;
    array_splice($args, $i, 2);
    return $v;
};
$flag = function (string $name) use (&$args) {
    $i = array_search('--' . $name, $args, true);
    if ($i === false) {
        return false;
    }
    array_splice($args, $i, 1);
    return true;
};
$base = rtrim($opt('base', getenv('GIMT_BASE') ?: 'http://127.0.0.1:8000'), '/');
$user = $opt('user', 'admin');
$pass = $opt('pass', 'Admin@12345');
$raw = $flag('raw');
$fresh = $flag('fresh');
[$method, $path, $body] = array_pad($args, 3, null);
if (!$method || !$path) {
    fwrite(STDERR, "Usage: php tools/api.php [--user u --pass p] METHOD path [json-body]\n");
    exit(2);
}
$method = strtoupper($method);

$cacheDir = __DIR__ . '/.cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}
$stateFile = $cacheDir . '/api-' . preg_replace('/[^a-z0-9]+/i', '_', $user) . '.json';
$state = (!$fresh && is_file($stateFile)) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$state += ['cookies' => [], 'csrf' => ''];

$http = function (string $method, string $path, $body = null) use ($base, &$state) {
    $url = $base . '/api/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'];
    if ($method !== 'GET') {
        $headers[] = 'X-CSRF-Token: ' . $state['csrf'];
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    if ($state['cookies']) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($state['cookies']), $state['cookies']));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 120]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        fwrite(STDERR, 'HTTP error: ' . curl_error($ch) . "\n");
        exit(2);
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($resp, 0, $hsize);
    foreach (preg_split('/\r?\n/', $head) as $line) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $line, $m)) {
            if ($m[2] === '' || $m[2] === 'deleted') {
                unset($state['cookies'][$m[1]]);
            } else {
                $state['cookies'][$m[1]] = $m[2];
            }
        }
    }
    return [$status, substr($resp, $hsize), $head];
};

$ensureLogin = function () use ($http, &$state, $user, $pass, $stateFile) {
    [, $sess] = $http('GET', 'auth/session');
    $j = json_decode($sess, true);
    $state['csrf'] = $j['data']['csrf_token'] ?? '';
    if (empty($j['data']['authenticated'])) {
        [$st, $resp] = $http('POST', 'auth/login', ['identifier' => $user, 'password' => $pass, 'remember' => false]);
        $lj = json_decode($resp, true);
        if (empty($lj['ok'])) {
            fwrite(STDERR, "Login failed ($st): " . ($lj['message'] ?? $resp) . "\n");
            exit(2);
        }
        $state['csrf'] = $lj['data']['csrf_token'] ?? $state['csrf'];
        [, $sess] = $http('GET', 'auth/session');
        $j = json_decode($sess, true);
        $state['csrf'] = $j['data']['csrf_token'] ?? $state['csrf'];
    }
    file_put_contents($stateFile, json_encode($state));
};

$ensureLogin();
[$status, $out, $head] = $http($method, $path, $body);
if ($status === 419 || $status === 401) {
    $state = ['cookies' => [], 'csrf' => ''];
    $ensureLogin();
    [$status, $out, $head] = $http($method, $path, $body);
}
file_put_contents($stateFile, json_encode($state));

$json = json_decode($out, true);
fwrite(STDOUT, "HTTP $status\n");
if ($raw || $json === null) {
    if (preg_match('/^Content-Type:\s*(.+)$/im', $head, $m) && !str_contains($m[1], 'json')) {
        fwrite(STDOUT, 'Content-Type: ' . trim($m[1]) . ', ' . strlen($out) . " bytes\n");
        fwrite(STDOUT, substr($out, 0, 1500) . "\n");
    } else {
        fwrite(STDOUT, $out . "\n");
    }
    exit($status < 400 ? 0 : 1);
}
fwrite(STDOUT, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
exit(!empty($json['ok']) ? 0 : 1);
