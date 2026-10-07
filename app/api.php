<?php
/**
 * Minimal JSON API router + helpers.
 *
 * Route files live in api/routes/{first-segment}.php and register handlers:
 *
 *   route('GET',  '/students/{id}/profile', function (array $p) {
 *       api_require('students', 'view');
 *       api_ok(['student' => ...]);
 *   });
 *   route('POST', '/admissions/{id}/convert', fn ($p) => ..., ['auth' => true]);   // auth defaults to true
 *   route('POST', '/public/contact', fn ($p) => ..., ['auth' => false, 'csrf' => true]);
 *
 * - URL:  /api/{path}   (Apache/nginx rewrite)  or  /api/index.php?r={path}  (no rewrite needed)
 * - Non-GET requests require the X-CSRF-Token header (token from GET /api/auth/session) unless 'csrf' => false.
 * - Every response is JSON: {ok: true, data: ..., message: ''} or {ok: false, message, errors: {field: msg}}.
 */

$GLOBALS['__routes'] = [];

function route(string $method, string $pattern, callable $handler, array $opts = []): void
{
    $GLOBALS['__routes'][] = ['method' => strtoupper($method), 'pattern' => $pattern, 'handler' => $handler, 'opts' => $opts + ['auth' => true]];
}

/** Request method with support for X-HTTP-Method-Override / _method (for multipart PUT/PATCH/DELETE). */
function api_method(): string
{
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($m === 'POST') {
        $o = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($_POST['_method'] ?? ''));
        if (in_array($o, ['PUT', 'PATCH', 'DELETE'], true)) {
            return $o;
        }
    }
    return $m;
}

/** Path after /api/, e.g. "students/5/profile". */
function api_path(): string
{
    if (isset($_GET['r'])) {
        return trim((string) $_GET['r'], '/');
    }
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = base_path() . '/api';
    if (str_starts_with($uri, $base . '/index.php')) {
        $uri = substr($uri, strlen($base . '/index.php'));
    } elseif (str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    return trim(rawurldecode($uri), '/');
}

/** Decoded request body (JSON or form). Cached. */
function api_input(): array
{
    static $input = null;
    if ($input !== null) {
        return $input;
    }
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($type, 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '[]', true);
        $input = is_array($decoded) ? $decoded : [];
    } else {
        $input = $_POST;
        // PUT/PATCH with urlencoded body
        if (!$input && in_array($_SERVER['REQUEST_METHOD'] ?? '', ['PUT', 'PATCH', 'DELETE'], true)) {
            parse_str(file_get_contents('php://input') ?: '', $input);
        }
        // Values sent as JSON strings inside multipart (e.g. arrays) - "__json" field holds an object to merge
        if (isset($input['__json']) && is_string($input['__json'])) {
            $extra = json_decode($input['__json'], true);
            unset($input['__json']);
            if (is_array($extra)) {
                $input = array_merge($input, $extra);
            }
        }
    }
    unset($input['_csrf'], $input['_method']);
    return $input;
}

function api_param(string $key, $default = null)
{
    $in = api_input();
    $v = $in[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function api_ok($data = null, string $message = '', int $status = 200): void
{
    json_response(['ok' => true, 'message' => $message, 'data' => $data], $status);
}

function api_error(string $message, int $status = 422, array $errors = []): void
{
    json_error($message, $status, $errors);
}

/** Permission guard for API handlers. */
function api_require(string $module, string $action = 'view'): void
{
    if (!can($module, $action)) {
        api_error('You do not have permission to ' . $action . ' ' . label_from_key($module) . '.', 403);
    }
}

/** Validate input with rules (see validate()); responds 422 with field errors on failure. Returns the subset of validated keys. */
function api_validate(array $rules, ?array $data = null, array $labels = []): array
{
    $data = $data ?? api_input();
    $errors = validate($data, $rules, $labels);
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $out = [];
    foreach (array_keys($rules) as $k) {
        $v = $data[$k] ?? null;
        $out[$k] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
    }
    return $out;
}

/** Standard pagination params from the query string. */
function api_pagination(int $defaultPerPage = 25): array
{
    $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? $defaultPerPage)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    return [$page, $perPage];
}

/** Fixed-window rate limiter. Returns false when the limit is exceeded. */
function rate_limit(string $key, int $max, int $windowSeconds): bool
{
    $key = substr($key, 0, 190);
    $row = db_row('SELECT hits, reset_at FROM rate_limits WHERE rl_key = ?', [$key]);
    if (!$row || strtotime($row['reset_at']) <= time()) {
        db_exec('REPLACE INTO rate_limits (rl_key, hits, reset_at) VALUES (?, 1, ?)', [$key, date('Y-m-d H:i:s', time() + $windowSeconds)]);
        return true;
    }
    if ((int) $row['hits'] >= $max) {
        return false;
    }
    db_exec('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ?', [$key]);
    return true;
}

function api_dispatch(): void
{
    $path = api_path();
    $method = api_method();
    $segments = $path === '' ? [] : explode('/', $path);
    $group = $segments[0] ?? '';
    if ($group === '' || !preg_match('/^[a-z0-9_\-]+$/', $group)) {
        api_error('Not found', 404);
    }
    $file = APP_ROOT . '/api/routes/' . $group . '.php';
    if (!is_file($file)) {
        api_error('Unknown API endpoint: /' . $path, 404);
    }
    require $file;

    $allowedMethods = [];
    foreach ($GLOBALS['__routes'] as $r) {
        $regex = '#^' . preg_replace_callback('/\{([a-z_]+)(?::([^}]+))?\}/', function ($mm) {
            return '(?P<' . $mm[1] . '>' . ($mm[2] ?? '[^/]+') . ')';
        }, trim($r['pattern'], '/')) . '$#';
        if (!preg_match($regex, $path, $matches)) {
            continue;
        }
        if ($r['method'] !== $method && !($r['method'] === 'GET' && $method === 'HEAD')) {
            $allowedMethods[] = $r['method'];
            continue;
        }
        $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        if ($r['opts']['auth']) {
            require_login();
        }
        $needsCsrf = $r['opts']['csrf'] ?? ($method !== 'GET' && $method !== 'HEAD');
        if ($needsCsrf && !csrf_valid()) {
            api_error('Your session token has expired. Please refresh the page and try again.', 419);
        }
        try {
            ($r['handler'])($params);
        } catch (CrudValidationException $e) {
            api_error($e->getMessage(), 422, $e->errors);
        } catch (CrudException $e) {
            api_error($e->getMessage(), 422);
        } catch (InvalidArgumentException $e) {
            api_error($e->getMessage(), 400);
        }
        api_ok();
    }
    if ($allowedMethods) {
        header('Allow: ' . implode(', ', array_unique($allowedMethods)));
        api_error('Method not allowed', 405);
    }
    api_error('Unknown API endpoint: ' . $method . ' /' . $path, 404);
}
