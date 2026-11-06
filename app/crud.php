<?php
/**
 * Generic CRUD engine driven by module definitions in app/modules/{key}.php.
 * See docs/DEVELOPER.md ("CRUD module definitions") for the full config reference.
 *
 * HTTP surface (api/routes/crud.php):
 *   GET    /api/crud/{module}/meta                  table/form/filter definitions + permissions
 *   GET    /api/crud/{module}                       paginated list (server-side search/filter/sort)
 *   GET    /api/crud/{module}/{id}                  single record (form values + display row)
 *   POST   /api/crud/{module}                       create (multipart or JSON)
 *   POST   /api/crud/{module}/{id}                  update (multipart or JSON)
 *   DELETE /api/crud/{module}/{id}                  delete
 *   POST   /api/crud/{module}/bulk                  {action: delete|status, ids[], value}
 *   GET    /api/crud/{module}/export?format=csv|xlsx|print
 *   GET    /api/crud/{module}/import-template
 *   POST   /api/crud/{module}/import                file + mode (insert|upsert)
 *   GET    /api/crud/{module}/options/{field}       async options for a field/filter (q, ids, parent params)
 */

class CrudValidationException extends RuntimeException
{
    public array $errors;

    public function __construct(array $errors, string $message = 'Please fix the highlighted fields and try again.')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }
}

/** Throw from hooks to abort with a user-facing message (HTTP 422). */
class CrudException extends RuntimeException
{
}

function crud_module_exists(string $key): bool
{
    return (bool) preg_match('/^[a-z0-9_\-]+$/', $key) && is_file(APP_ROOT . '/app/modules/' . $key . '.php');
}

function crud_module(string $key): array
{
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!crud_module_exists($key)) {
        throw new InvalidArgumentException('Unknown module: ' . $key);
    }
    $m = require APP_ROOT . '/app/modules/' . $key . '.php';
    $m += [
        'key' => $key, 'table' => $key, 'title' => label_from_key($key), 'permission' => $key, 'primary' => 'id', 'alias' => 't',
        'select' => null, 'joins' => '', 'where' => null, 'search' => [], 'columns' => [], 'filters' => [], 'fields' => [], 'scopes' => [],
        'bulk' => ['delete' => true], 'export' => true, 'import' => false, 'order' => null, 'per_page' => 25, 'hooks' => [],
        'view' => ['type' => 'modal'], 'form' => ['size' => 'lg', 'mode' => 'modal'], 'description' => '', 'icon' => 'table',
        'permissions' => [], 'row_actions' => [], 'search_placeholder' => null, 'readonly' => false,
    ];
    $m['key'] = $key;
    $m['singular'] = $m['singular'] ?? rtrim($m['title'], 's');
    $m['select'] = $m['select'] ?? $m['alias'] . '.*';
    $m['order'] = $m['order'] ?? $m['alias'] . '.' . $m['primary'] . ' DESC';
    foreach ($m['fields'] as $i => $f) {
        $f['type'] = $f['type'] ?? 'text';
        if ($f['type'] !== 'section') {
            $f['label'] = $f['label'] ?? label_from_key($f['name']);
        }
        $f['col'] = $f['col'] ?? (in_array($f['type'], ['textarea', 'richtext', 'json', 'section', 'repeater'], true) ? 12 : 6);
        $m['fields'][$i] = $f;
    }
    foreach ($m['columns'] as $i => $c) {
        $m['columns'][$i] = $c + ['label' => label_from_key($c['key']), 'format' => 'text'];
    }
    return $cache[$key] = $m;
}

/** List of all module keys defined in app/modules. */
function crud_module_keys(): array
{
    return array_map(fn ($f) => basename($f, '.php'), glob(APP_ROOT . '/app/modules/*.php') ?: []);
}

function crud_can(array $m, string $action): bool
{
    if (!empty($m['readonly']) && in_array($action, ['create', 'edit', 'delete', 'import'], true)) {
        return false;
    }
    if (isset($m['permissions'][$action])) {
        [$module, $act] = $m['permissions'][$action];
        return can($module, $act);
    }
    return can($m['permission'], $action);
}

function crud_hook(array $m, string $name)
{
    return isset($m['hooks'][$name]) && is_callable($m['hooks'][$name]) ? $m['hooks'][$name] : null;
}

/** Find a field definition by name. */
function crud_field(array $m, string $name): ?array
{
    foreach ($m['fields'] as $f) {
        if (($f['name'] ?? null) === $name) {
            return $f;
        }
    }
    return null;
}

/** Normalise static options to [{value,label}] */
function crud_static_options($options): array
{
    $out = [];
    foreach ((array) $options as $k => $v) {
        if (is_array($v) && isset($v['value'])) {
            $out[] = ['value' => $v['value'], 'label' => (string) ($v['label'] ?? $v['value'])] + (isset($v['color']) ? ['color' => $v['color']] : []);
        } else {
            $out[] = ['value' => $k, 'label' => (string) $v];
        }
    }
    return $out;
}

/** Is this field/filter backed by a large (async) source? */
function crud_source_is_async($def): bool
{
    if (!empty($def['async'])) {
        return true;
    }
    $src = $def['source'] ?? null;
    if (is_string($src)) {
        return !empty(lookup_sources()[$src]['async']);
    }
    return is_array($src) && !empty($src['async']);
}

function crud_definition_options(array $def, array $params = []): array
{
    if (isset($def['options'])) {
        return crud_static_options($def['options']);
    }
    if (isset($def['source'])) {
        return lookup_options($def['source'], $params);
    }
    return [];
}

/* ------------------------------------------------------------------
 * Meta
 * ------------------------------------------------------------------ */

function crud_meta(array $m): array
{
    $optionsUrl = fn (string $name) => 'crud/' . $m['key'] . '/options/' . $name;
    $fields = [];
    foreach ($m['fields'] as $f) {
        if (($f['form'] ?? true) === false) {
            continue;
        }
        $out = array_intersect_key($f, array_flip(['name', 'label', 'type', 'required', 'col', 'placeholder', 'help', 'default', 'rows', 'accept', 'multiple',
            'readonly_on_edit', 'create_only', 'edit_only', 'min', 'max', 'step', 'depends', 'slug_from', 'prefix', 'suffix', 'maxlength', 'category', 'private', 'hidden', 'pattern']));
        if (in_array($f['type'], ['select', 'multiselect', 'radio', 'combobox'], true) || isset($f['options']) || isset($f['source'])) {
            if (crud_source_is_async($f) || !empty($f['depends'])) {
                $out['options_url'] = $optionsUrl($f['name']);
                $out['async'] = crud_source_is_async($f);
                if (!$out['async'] && empty($f['depends'])) {
                    $out['options'] = crud_definition_options($f);
                }
            } else {
                $out['options'] = crud_definition_options($f);
            }
        }
        if (in_array($f['type'], ['image', 'file'], true)) {
            $cat = $f['category'] ?? ($f['type'] === 'image' ? 'image' : 'any');
            $out['accept'] = $f['accept'] ?? implode(',', array_map(fn ($e) => '.' . $e, array_keys(upload_allowed($cat))));
            $out['max_size'] = upload_max_bytes($cat);
        }
        $fields[] = $out;
    }
    $filters = [];
    foreach ($m['filters'] as $f) {
        $out = array_intersect_key($f, array_flip(['key', 'label', 'type', 'placeholder', 'default', 'depends']));
        $out['type'] = $out['type'] ?? 'select';
        $out['label'] = $out['label'] ?? label_from_key($f['key']);
        if (isset($f['options']) || isset($f['source']) || isset($f['sql_map'])) {
            if (crud_source_is_async($f) || !empty($f['depends'])) {
                $out['options_url'] = $optionsUrl('filter:' . $f['key']);
                $out['async'] = crud_source_is_async($f);
            } else {
                $out['options'] = isset($f['sql_map']) && !isset($f['options'])
                    ? array_map(fn ($k) => ['value' => $k, 'label' => label_from_key($k)], array_keys($f['sql_map']))
                    : crud_definition_options($f);
            }
        }
        $filters[] = $out;
    }
    $columns = array_map(fn ($c) => array_intersect_key($c, array_flip(['key', 'label', 'format', 'sub', 'image', 'link', 'align', 'hidden', 'colors', 'width', 'truncate', 'prefix', 'suffix']))
        + ['sortable' => !empty($c['sortable'])], $m['columns']);
    $bulk = $m['bulk'];
    $can = [];
    foreach (['view', 'create', 'edit', 'delete', 'export', 'import'] as $a) {
        $can[$a] = crud_can($m, $a);
    }
    return [
        'key' => $m['key'], 'title' => $m['title'], 'singular' => $m['singular'], 'description' => $m['description'], 'icon' => $m['icon'],
        'can' => $can, 'columns' => $columns, 'filters' => $filters, 'fields' => $fields,
        'bulk' => ['delete' => !empty($bulk['delete']) && $can['delete'], 'status' => isset($bulk['status']) && $can['edit'] ? array_values((array) $bulk['status']) : null,
            'status_field' => $bulk['status_field'] ?? 'status'],
        'export' => (bool) $m['export'] && $can['export'], 'import' => (bool) $m['import'] && $can['import'],
        'view' => $m['view'], 'form' => $m['form'] + ['size' => 'lg', 'mode' => 'modal'], 'per_page' => $m['per_page'],
        'search_placeholder' => $m['search_placeholder'] ?? ('Search ' . strtolower($m['title']) . '...'),
        'row_actions' => $m['row_actions'], 'default_sort' => $m['default_sort'] ?? null,
    ];
}

/* ------------------------------------------------------------------
 * Listing
 * ------------------------------------------------------------------ */

function crud_base_query(array $m): string
{
    return ' FROM ' . db_quote_ident($m['table']) . ' ' . $m['alias'] . ' ' . $m['joins'];
}

function crud_apply_filters(array $m, array $req, array &$where, array &$args): void
{
    if (!empty($m['where'])) {
        $where[] = '(' . $m['where'] . ')';
    }
    // Scopes: hidden fixed filters used when embedding a table (e.g. documents of one student)
    foreach ((array) ($req['scope'] ?? []) as $key => $value) {
        if (isset($m['scopes'][$key]) && $value !== '' && !is_array($value)) {
            $where[] = $m['scopes'][$key] . ' = ?';
            $args[] = $value;
        }
    }
    $filters = (array) ($req['f'] ?? []);
    foreach ($m['filters'] as $def) {
        $key = $def['key'];
        if (!array_key_exists($key, $filters)) {
            continue;
        }
        $value = $filters[$key];
        if ($value === '' || $value === null || $value === []) {
            continue;
        }
        $col = $def['column'] ?? ($m['alias'] . '.' . $key);
        $type = $def['type'] ?? 'select';
        if (is_array($value) && !in_array($type, ['daterange', 'multiselect'], true)) {
            continue; // ?f[status][]=x on a single-value filter: ignore it (no "Illegal offset" / "Array to string")
        }
        if (isset($def['sql_map'])) {
            if (isset($def['sql_map'][$value])) {
                $where[] = '(' . $def['sql_map'][$value] . ')';
            }
            continue;
        }
        if (isset($def['sql'])) {
            $where[] = '(' . $def['sql'] . ')';
            if (str_contains($def['sql'], '?')) {
                $args[] = $value;
            }
            continue;
        }
        switch ($type) {
            case 'daterange':
                if (is_array($value)) {
                    if (!empty($value['from'])) {
                        $where[] = "$col >= ?";
                        $args[] = $value['from'];
                    }
                    if (!empty($value['to'])) {
                        $where[] = "$col < DATE_ADD(?, INTERVAL 1 DAY)";
                        $args[] = $value['to'];
                    }
                }
                break;
            case 'date':
                $where[] = "DATE($col) = ?";
                $args[] = $value;
                break;
            case 'text':
                $where[] = "$col LIKE ?";
                $args[] = '%' . $value . '%';
                break;
            case 'multiselect':
                $vals = array_values(array_filter((array) $value, fn ($v) => $v !== ''));
                if ($vals) {
                    $where[] = "$col IN (" . implode(',', array_fill(0, count($vals), '?')) . ')';
                    $args = array_merge($args, $vals);
                }
                break;
            case 'boolean':
                $where[] = "$col = ?";
                $args[] = in_array($value, ['1', 1, true, 'yes', 'true'], true) ? 1 : 0;
                break;
            default:
                if (is_array($value)) {
                    break;
                }
                $where[] = "$col = ?";
                $args[] = $value;
        }
    }
    $q = trim(api_str($req['q'] ?? null));
    if ($q !== '' && $m['search']) {
        foreach (preg_split('/\s+/', mb_substr($q, 0, 100)) as $word) {
            $or = [];
            foreach ($m['search'] as $expr) {
                $or[] = "$expr LIKE ?";
                $args[] = '%' . $word . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
    }
    if (!empty($req['ids'])) {
        $ids = array_values(array_filter(array_map('intval', is_array($req['ids']) ? $req['ids'] : explode(',', (string) $req['ids']))));
        if ($ids) {
            $where[] = $m['alias'] . '.' . $m['primary'] . ' IN (' . implode(',', $ids) . ')';
        }
    }
    if ($hook = crud_hook($m, 'list_query')) {
        $hook($where, $args, $req);
    }
}

function crud_order_sql(array $m, array $req): string
{
    $sort = api_str($req['sort'] ?? null);
    $dir = strtolower(api_str($req['dir'] ?? null, 'asc')) === 'desc' ? 'DESC' : 'ASC';
    foreach ($m['columns'] as $c) {
        if ($c['key'] === $sort && !empty($c['sortable'])) {
            $expr = is_string($c['sortable']) ? $c['sortable'] : (in_array($c['key'], db_table_columns($m['table']), true) ? $m['alias'] . '.' . $c['key'] : db_quote_ident($c['key']));
            return $expr . ' ' . $dir . ', ' . $m['alias'] . '.' . $m['primary'] . ' ' . $dir;
        }
    }
    return $m['order'];
}

function crud_list(array $m, array $req): array
{
    $where = [];
    $args = [];
    crud_apply_filters($m, $req, $where, $args);
    $from = crud_base_query($m) . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $total = (int) db_value('SELECT COUNT(*)' . $from, $args);
    $perPage = max(1, min(100, (int) ($req['per_page'] ?? $m['per_page'])));
    $p = paginate($total, max(1, (int) ($req['page'] ?? 1)), $perPage);
    $sql = 'SELECT ' . $m['select'] . $from . ' ORDER BY ' . crud_order_sql($m, $req) . ' LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'];
    $rows = db_all($sql, $args);
    if ($hook = crud_hook($m, 'transform_row')) {
        $rows = array_map($hook, $rows);
    }
    $rows = array_map(fn ($r) => crud_public_row($m, $r), $rows);
    $summary = null;
    if ($hook = crud_hook($m, 'summary')) {
        $summary = $hook($from, $args);
    }
    return ['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages'], 'from' => $p['from'], 'to' => $p['to'], 'summary' => $summary];
}

/** Remove secret columns before sending a row to the client. */
function crud_public_row(array $m, array $row): array
{
    foreach ($m['fields'] as $f) {
        if (($f['type'] ?? '') === 'password') {
            unset($row[$f['name']]);
        }
    }
    foreach (['password_hash', 'two_factor_secret', 'token_hash'] as $secret) {
        unset($row[$secret]);
    }
    foreach ($m['hidden_columns'] ?? [] as $h) {
        unset($row[$h]);
    }
    if (isset($row['id'])) {
        $row['id'] = (int) $row['id'];
    }
    return $row;
}

function crud_find(array $m, int $id): ?array
{
    $row = db_row('SELECT ' . $m['select'] . crud_base_query($m) . ' WHERE ' . $m['alias'] . '.' . $m['primary'] . ' = ?' . ($m['where'] ? ' AND (' . $m['where'] . ')' : ''), [$id]);
    if ($row && ($hook = crud_hook($m, 'transform_row'))) {
        $row = $hook($row);
    }
    return $row;
}

/** Values for the edit form + labels for async selects (so the client can show current selections). */
function crud_form_payload(array $m, array $row): array
{
    $values = [];
    $labels = [];
    foreach ($m['fields'] as $f) {
        if ($f['type'] === 'section' || !isset($f['name'])) {
            continue;
        }
        $name = $f['name'];
        $v = $row[$name] ?? null;
        if ($f['type'] === 'password') {
            $v = '';
        } elseif ($f['type'] === 'multiselect' && is_string($v)) {
            $decoded = json_decode($v, true);
            $v = is_array($decoded) ? $decoded : array_filter(explode(',', $v));
        } elseif ($f['type'] === 'datetime' && $v) {
            $v = date('Y-m-d\TH:i', strtotime($v));
        } elseif ($f['type'] === 'time' && $v) {
            $v = substr($v, 0, 5);
        } elseif (in_array($f['type'], ['boolean', 'toggle', 'checkbox'], true)) {
            $v = (bool) $v;
        } elseif ($f['type'] === 'json' && $v !== null && !is_string($v)) {
            $v = json_encode($v, JSON_PRETTY_PRINT);
        }
        $values[$name] = $v;
        if ($v !== null && $v !== '' && (isset($f['source'])) && (crud_source_is_async($f) || !empty($f['depends']))) {
            $ids = is_array($v) ? implode(',', $v) : (string) $v;
            foreach (lookup_options($f['source'], ['ids' => $ids]) as $o) {
                $labels[$name][] = $o;
            }
        }
    }
    return ['values' => $values, 'labels' => (object) $labels];
}

/* ------------------------------------------------------------------
 * Validation & saving
 * ------------------------------------------------------------------ */

/**
 * Validate & normalise input for a module.
 * @return array [clean data, errors]
 */
function crud_validate(array $m, array $input, ?int $id = null, ?array $old = null, bool $importing = false): array
{
    $clean = [];
    $errors = [];
    $isCreate = $id === null;
    foreach ($m['fields'] as $f) {
        $type = $f['type'];
        if ($type === 'section' || !isset($f['name']) || in_array($type, ['image', 'file'], true)) {
            continue;
        }
        if (($f['form'] ?? true) === false && !$importing) {
            continue;
        }
        if (!empty($f['create_only']) && !$isCreate) {
            continue;
        }
        if (!empty($f['readonly_on_edit']) && !$isCreate) {
            continue;
        }
        $name = $f['name'];
        $label = $f['label'] ?? label_from_key($name);
        $present = array_key_exists($name, $input);
        $value = $input[$name] ?? null;
        if (is_string($value)) {
            $value = trim($value);
        }
        // On update, fields not sent are left unchanged (allows partial updates).
        if (!$isCreate && !$present && !in_array($type, ['boolean', 'toggle', 'checkbox'], true)) {
            continue;
        }
        if ($isCreate && !$present && array_key_exists('default', $f)) {
            $value = $f['default'];
        }
        switch ($type) {
            case 'number':
            case 'decimal':
            case 'money':
                if ($value === '' || $value === null) {
                    $value = null;
                } elseif (!is_numeric(str_replace(',', '', (string) $value))) {
                    $errors[$name] = "$label must be a number.";
                    continue 2;
                } else {
                    $value = $type === 'number' && !isset($f['step']) ? (int) str_replace(',', '', (string) $value) : (float) str_replace(',', '', (string) $value);
                    if (isset($f['min']) && $value < $f['min']) {
                        $errors[$name] = "$label must be at least {$f['min']}.";
                    } elseif (isset($f['max']) && $value > $f['max']) {
                        $errors[$name] = "$label may not be greater than {$f['max']}.";
                    }
                }
                break;
            case 'boolean':
            case 'toggle':
            case 'checkbox':
                if (!$present && !$isCreate) {
                    // unchecked checkboxes are not posted by HTML forms; the SPA always sends booleans. An import that
                    // updates a record (upsert) leaves flags whose column is not in the file unchanged - a CSV export
                    // without "is_hosteller" must not switch every imported student's hostel flag off.
                    continue 2;
                }
                $value = in_array($value, [true, 1, '1', 'on', 'yes', 'true', 'Yes', 'YES'], true) ? 1 : 0;
                break;
            case 'date':
                if ($value === '' || $value === null) {
                    $value = null;
                } else {
                    $d = $importing ? spreadsheet_date($value) : $value;
                    $dt = DateTime::createFromFormat('Y-m-d', (string) $d);
                    if (!$dt || $dt->format('Y-m-d') !== $d) {
                        $errors[$name] = "$label must be a valid date.";
                        continue 2;
                    }
                    $value = $d;
                }
                break;
            case 'datetime':
                if ($value === '' || $value === null) {
                    $value = null;
                } else {
                    $ts = strtotime(str_replace('T', ' ', (string) $value));
                    if (!$ts) {
                        $errors[$name] = "$label must be a valid date & time.";
                        continue 2;
                    }
                    $value = date('Y-m-d H:i:s', $ts);
                }
                break;
            case 'time':
                if ($value === '' || $value === null) {
                    $value = null;
                } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $value)) {
                    $errors[$name] = "$label must be a valid time.";
                    continue 2;
                }
                break;
            case 'multiselect':
                $arr = is_array($value) ? $value : (($value === null || $value === '') ? [] : (json_decode((string) $value, true) ?? explode(',', (string) $value)));
                $arr = array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $arr), fn ($v) => $v !== '' && $v !== null));
                $value = $arr ? json_encode($arr) : null;
                break;
            case 'json':
            case 'repeater':
                if ($value === '' || $value === null) {
                    $value = null;
                } else {
                    $decoded = is_array($value) ? $value : json_decode((string) $value, true);
                    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                        $errors[$name] = "$label must be valid JSON.";
                        continue 2;
                    }
                    $value = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                break;
            case 'richtext':
                $value = $value === null ? null : sanitize_html((string) $value);
                break;
            case 'slug':
                if (($value === '' || $value === null) && !empty($f['slug_from'])) {
                    $value = (string) ($input[$f['slug_from']] ?? ($old[$f['slug_from']] ?? ''));
                }
                $value = $value === '' || $value === null ? null : slugify((string) $value);
                break;
            case 'password':
                if ($value === '' || $value === null) {
                    if ($isCreate && !empty($f['required'])) {
                        $errors[$name] = "$label is required.";
                    }
                    continue 2;
                }
                if ($err = password_policy_error((string) $value)) {
                    $errors[$name] = $err;
                    continue 2;
                }
                $value = password_hash((string) $value, PASSWORD_DEFAULT);
                break;
            case 'email':
                // trim, lowercase, IDN domain -> ASCII (validated by the 'email' rule below)
                if ($value !== null && $value !== '') {
                    require_once APP_ROOT . '/app/services/phone.php';
                    $value = email_normalize(is_array($value) ? '' : (string) $value);
                }
                break;
            case 'tel':
                // international numbers -> E.164 (+919876543210); numbers without a country code use the field's
                // 'country' (ISO2) or setting phone_default_country (IN). Invalid input is kept as typed so the 'phone'
                // rule below reports it. A leading apostrophe (spreadsheet text marker from CSV exports) is ignored.
                if ($value !== null && $value !== '' && !is_array($value)) {
                    require_once APP_ROOT . '/app/services/phone.php';
                    $raw = ltrim(trim((string) $value), "'");
                    $value = phone_normalize($raw, !empty($f['country']) ? (string) $f['country'] : null) ?? $raw;
                }
                break;
            default:
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
        }
        if (is_string($value) && $value === '') {
            $value = null;
        }
        // Required
        if (!empty($f['required']) && ($value === null || $value === '') && $type !== 'password') {
            $errors[$name] = "$label is required.";
            continue;
        }
        if ($value === null) {
            $clean[$name] = null;
            continue;
        }
        // Option membership
        if (in_array($type, ['select', 'radio', 'combobox'], true) && ($f['validate_options'] ?? true)) {
            if (isset($f['options'])) {
                $allowed = array_map(fn ($o) => (string) $o['value'], crud_static_options($f['options']));
                if (!in_array((string) $value, $allowed, true)) {
                    // Import convenience: accept option labels too
                    $match = null;
                    foreach (crud_static_options($f['options']) as $o) {
                        if (strcasecmp($o['label'], (string) $value) === 0) {
                            $match = $o['value'];
                        }
                    }
                    if ($match === null) {
                        $errors[$name] = "Select a valid $label.";
                        continue;
                    }
                    $value = $match;
                }
            } elseif (isset($f['source'])) {
                // imports carry names ("MBA", "A"): only digit strings are tried as ids there (MySQL would cast "3 A" to 3)
                $byId = !$importing || ctype_digit((string) $value) ? lookup_options($f['source'], ['ids' => (string) $value]) : [];
                if (!$byId) {
                    $found = $importing ? crud_import_resolve_source($f, (string) $value, $clean + $input) : null;
                    if ($found === null) {
                        $errors[$name] = "Selected $label is invalid.";
                        continue;
                    }
                    $value = $found;
                }
            }
        }
        // Length / format / custom rules
        $rules = [];
        if (in_array($type, ['text', 'textarea', 'email', 'tel', 'url', 'slug'], true)) {
            $rules[] = 'max:' . ($f['maxlength'] ?? ($type === 'textarea' ? 65000 : 255));
        }
        if ($type === 'email') {
            $rules[] = 'email';
        }
        if ($type === 'tel') {
            $rules[] = !empty($f['country']) ? 'phone:' . strtoupper((string) $f['country']) : 'phone';
        }
        if ($type === 'url') {
            $rules[] = 'url';
        }
        if (!empty($f['rules'])) {
            $rules = array_merge($rules, is_array($f['rules']) ? $f['rules'] : explode('|', $f['rules']));
        }
        if ($rules) {
            $e = validate([$name => $value], [$name => $rules], [$name => $label]);
            if ($e) {
                $errors[$name] = $e[$name];
                continue;
            }
        }
        if (!empty($f['unique'])) {
            $sql = 'SELECT COUNT(*) FROM ' . db_quote_ident($m['table']) . ' WHERE ' . db_quote_ident($name) . ' = ?' . ($id ? ' AND ' . db_quote_ident($m['primary']) . ' <> ?' : '');
            if ((int) db_value($sql, $id ? [$value, $id] : [$value])) {
                $errors[$name] = "A record with this $label already exists.";
                continue;
            }
        }
        $clean[$name] = $value;
    }
    // Scope values (e.g. student_id when embedded under a student profile) on create
    if ($isCreate) {
        foreach ((array) ($input['__scope'] ?? []) as $k => $v) {
            if (isset($m['scopes'][$k]) && !is_array($v) && $v !== '') {
                $clean[$k] = $v;
            }
        }
    }
    return [$clean, $errors];
}

/**
 * Import helper: the option of a lookup source whose name matches a spreadsheet value - the full label, the label
 * without a trailing "(...)", or one part of a composite label ("MBA" in "MBA — Master of ...", "A" in
 * "BBA · Sem 1 · Sec A"). Dependent sources are narrowed by the row's already-resolved values (a section by its
 * program + semester). Returns the option value, or null when nothing (or more than one option) matches.
 */
function crud_import_resolve_source(array $f, string $value, array $row)
{
    $want = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    if ($want === '') {
        return null;
    }
    $params = [];
    foreach ((array) ($f['depends'] ?? []) as $param => $field) {
        $v = $row[$field] ?? null;
        if (is_scalar($v) && (string) $v !== '') {
            $params[$param] = (string) $v;
        }
    }
    $norm = fn ($s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
    // 1) options found by the source's search columns, 2) every option in scope (labels such as "Sec A" are not searchable)
    foreach ([$params + ['q' => $value], $params] as $p) {
        try {
            $options = lookup_options($f['source'], $p + ['limit' => 300], 300);
        } catch (Throwable $e) {
            return null;
        }
        $hits = [];
        foreach ($options as $o) {
            $label = (string) $o['label'];
            $candidates = [$label, preg_replace('/\s*\(.*\)$/u', '', $label)];
            foreach (preg_split('/\s+(?:—|–|·|\|)\s+/u', $label) ?: [] as $part) {
                $candidates[] = $part;
                $candidates[] = preg_replace('/^(?:Sec|Section|Sem|Semester)\s+/iu', '', $part);
            }
            foreach ($candidates as $c) {
                if ($norm($c) === $want) {
                    $hits[(string) $o['value']] = $o['value'];
                    break;
                }
            }
        }
        if (count($hits) === 1) {
            return reset($hits);
        }
        if (count($hits) > 1) {
            return null; // ambiguous: ask for the id or the full name
        }
    }
    return null;
}

/**
 * Create or update a record. Throws CrudValidationException on invalid input.
 * @return array{id:int, created:bool}
 */
function crud_save(array $m, array $input, array $files = [], ?int $id = null, bool $importing = false): array
{
    $old = null;
    if ($id !== null) {
        $old = db_row('SELECT * FROM ' . db_quote_ident($m['table']) . ' WHERE ' . db_quote_ident($m['primary']) . ' = ?', [$id]);
        if (!$old) {
            throw new CrudException($m['singular'] . ' not found. It may have been deleted.');
        }
    }
    [$data, $errors] = crud_validate($m, $input, $id, $old, $importing);
    if ($hook = crud_hook($m, 'validate')) {
        $errors = array_merge($errors, (array) $hook($data, $id, $old, $input));
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    // Files
    $newFiles = [];
    foreach ($m['fields'] as $f) {
        if (!in_array($f['type'] ?? '', ['image', 'file'], true)) {
            continue;
        }
        $name = $f['name'];
        $file = $files[$name] ?? null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = upload_file($file, $f['category'] ?? ($f['type'] === 'image' ? 'image' : 'any'), $f['folder'] ?? $m['key'], !empty($f['private']));
            if (!$res['ok']) {
                foreach ($newFiles as $p) {
                    delete_upload($p);
                }
                throw new CrudValidationException([$name => $res['error']]);
            }
            $data[$name] = $res['path'];
            $newFiles[] = $res['path'];
        } elseif (!empty($input[$name . '__remove'])) {
            $data[$name] = null;
        } elseif ($id === null && !empty($f['required'])) {
            throw new CrudValidationException([$name => ($f['label'] ?? $name) . ' is required.']);
        }
    }
    if ($hook = crud_hook($m, 'before_save')) {
        $result = $hook($data, $id, $old, $input);
        if (is_array($result)) {
            $data = $result;
        }
    }
    $columns = db_table_columns($m['table']);
    $data = array_intersect_key($data, array_flip($columns));
    if ($id === null && in_array('created_by', $columns, true) && !isset($data['created_by'])) {
        $data['created_by'] = user_id();
    }
    if ($id !== null && in_array('updated_by', $columns, true)) {
        $data['updated_by'] = user_id();
    }
    try {
        $savedId = db_transaction(function () use ($m, $data, $id, $old, $input) {
            if ($id === null) {
                $newId = db_insert($m['table'], $data);
            } else {
                if ($data) {
                    db_update($m['table'], $data, db_quote_ident($m['primary']) . ' = ?', [$id]);
                }
                $newId = $id;
            }
            if ($hook = crud_hook($m, 'after_save')) {
                $hook($newId, $data, $old, $input);
            }
            return $newId;
        });
    } catch (PDOException $e) {
        foreach ($newFiles as $p) {
            delete_upload($p);
        }
        if ($e->getCode() === '23000') {
            if (preg_match("/Duplicate entry '.*' for key '(?:[^.']*\.)?([^']+)'/", $e->getMessage(), $mm)) {
                throw new CrudException('A ' . strtolower($m['singular']) . ' with the same details already exists (' . str_replace(['uq_', '_'], ['', ' '], $mm[1]) . ').');
            }
            throw new CrudException('This ' . strtolower($m['singular']) . ' references a record that does not exist or is still in use.');
        }
        throw $e;
    }
    // Remove replaced files (unless another record still points at the same stored file)
    if ($old) {
        foreach ($m['fields'] as $f) {
            if (in_array($f['type'] ?? '', ['image', 'file'], true) && array_key_exists($f['name'], $data) && $old[$f['name']] && $old[$f['name']] !== $data[$f['name']]) {
                crud_delete_upload_if_unused($m, $f['name'], (string) $old[$f['name']]);
            }
        }
    }
    if ($m['table'] === 'users' && !empty($data['password_hash']) && (int) $savedId === user_id() && function_exists('session_refresh_password_fp')) {
        // an administrator who changed their own password on the Users screen stays signed in here (other devices are not)
        session_refresh_password_fp();
    }
    if (!$importing) {
        $row = db_row('SELECT * FROM ' . db_quote_ident($m['table']) . ' WHERE ' . db_quote_ident($m['primary']) . ' = ?', [$savedId]) ?? [];
        log_activity($id === null ? 'create' : 'update', $m['permission'], $savedId, ($id === null ? 'Created ' : 'Updated ') . strtolower($m['singular']) . ' ' . crud_describe($m, $row));
        try { require_once APP_ROOT . '/app/whatsapp_hook.php'; gimt_wa_crud((string) ($m['key'] ?? ''), (int) $savedId, $old, $row); } catch (\Throwable $waE) { error_log('[GIMT] WhatsApp hook: ' . $waE->getMessage()); }
    }
    return ['id' => (int) $savedId, 'created' => $id === null];
}

/**
 * Deletes a stored upload only when no other row of the module's table still references it (records duplicated
 * from each other, or a media path chosen twice, share one file - deleting one record must not break the other).
 */
function crud_delete_upload_if_unused(array $m, string $column, string $path): void
{
    if ($path === '' || preg_match('#^(https?:)?//#i', $path)) {
        return;
    }
    try {
        if ((int) db_value('SELECT COUNT(*) FROM ' . db_quote_ident($m['table']) . ' WHERE ' . db_quote_ident($column) . ' = ?', [$path]) > 0) {
            return;
        }
    } catch (Throwable $e) {
        return; // virtual / non-column field: keep the file rather than risk deleting a shared one
    }
    delete_upload($path);
}

/**
 * Toast text after a save / delete. A module may set 'messages' => ['created' => 'Document uploaded.', 'updated' => ...,
 * 'deleted' => ...]; otherwise "{Singular} created successfully." etc.
 */
function crud_message(array $m, string $event): string
{
    $custom = $m['messages'][$event] ?? null;
    if (is_string($custom) && trim($custom) !== '') {
        return $custom;
    }
    return ($m['singular'] ?? 'Record') . ' ' . ['created' => 'created', 'updated' => 'updated', 'deleted' => 'deleted'][$event] . ' successfully.';
}

/** Human description of a record for logs/toasts. */
function crud_describe(array $m, array $row): string
{
    if ($hook = crud_hook($m, 'describe')) {
        return (string) $hook($row);
    }
    foreach (['name', 'title', 'full_name', 'question', 'subject', 'vehicle_no', 'receipt_no', 'invoice_no', 'application_no', 'code'] as $k) {
        if (!empty($row[$k])) {
            return '"' . str_limit((string) $row[$k], 80) . '"';
        }
    }
    if (!empty($row['first_name'])) {
        return '"' . trim($row['first_name'] . ' ' . ($row['last_name'] ?? '')) . '"';
    }
    return '#' . ($row['id'] ?? '');
}

/** Delete records. Returns ['deleted' => n, 'errors' => [id => message]] */
function crud_delete(array $m, array $ids): array
{
    $deleted = 0;
    $errors = [];
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $row = db_row('SELECT * FROM ' . db_quote_ident($m['table']) . ' WHERE ' . db_quote_ident($m['primary']) . ' = ?', [$id]);
        if (!$row) {
            continue;
        }
        if ($hook = crud_hook($m, 'before_delete')) {
            $msg = $hook($id, $row);
            if (is_string($msg) && $msg !== '') {
                $errors[$id] = $msg;
                continue;
            }
        }
        try {
            db_transaction(function () use ($m, $id, $row) {
                db_delete($m['table'], db_quote_ident($m['primary']) . ' = ?', [$id]);
                if ($hook = crud_hook($m, 'after_delete')) {
                    $hook($id, $row);
                }
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[$id] = crud_describe($m, $row) . ' is linked to other records and cannot be deleted. Mark it inactive instead.';
                continue;
            }
            throw $e;
        }
        foreach ($m['fields'] as $f) {
            if (in_array($f['type'] ?? '', ['image', 'file'], true) && !empty($row[$f['name']])) {
                crud_delete_upload_if_unused($m, $f['name'], (string) $row[$f['name']]);
            }
        }
        log_activity('delete', $m['permission'], $id, 'Deleted ' . strtolower($m['singular']) . ' ' . crud_describe($m, $row));
        $deleted++;
    }
    return ['deleted' => $deleted, 'errors' => $errors];
}

function crud_bulk_update(array $m, array $ids, string $field, $value): int
{
    $allowedField = $m['bulk']['status_field'] ?? 'status';
    if ($field !== $allowedField || !in_array($value, (array) ($m['bulk']['status'] ?? []), true)) {
        throw new CrudException('This bulk action is not allowed.');
    }
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return 0;
    }
    $n = db_exec('UPDATE ' . db_quote_ident($m['table']) . ' SET ' . db_quote_ident($field) . ' = ? WHERE ' . db_quote_ident($m['primary']) . ' IN (' . implode(',', $ids) . ')', [$value]);
    if ($hook = crud_hook($m, 'after_bulk')) {
        $hook($ids, $field, $value);
    }
    log_activity('update', $m['permission'], implode(',', array_slice($ids, 0, 20)), 'Bulk set ' . $field . ' = ' . $value . ' on ' . count($ids) . ' ' . strtolower($m['title']));
    return $n;
}

/* ------------------------------------------------------------------
 * Export
 * ------------------------------------------------------------------ */

function crud_export_columns(array $m): array
{
    if (!empty($m['export_columns'])) {
        return array_map(fn ($c) => $c + ['label' => label_from_key($c['key']), 'format' => 'text'], $m['export_columns']);
    }
    return array_values(array_filter($m['columns'], fn ($c) => !in_array($c['format'] ?? 'text', ['image', 'actions'], true)));
}

function crud_format_export_value($value, array $col): string
{
    if ($value === null) {
        return '';
    }
    switch ($col['format'] ?? 'text') {
        case 'date':
            return format_date($value);
        case 'datetime':
            return format_datetime($value);
        case 'badge':
            return label_from_key((string) $value);
        case 'boolean':
            return $value ? 'Yes' : 'No';
        case 'money':
        case 'number':
            return (string) $value;
        default:
            return is_scalar($value) ? (string) $value : json_encode($value);
    }
}

function crud_export(array $m, array $req, string $format): void
{
    $where = [];
    $args = [];
    crud_apply_filters($m, $req, $where, $args);
    $sql = 'SELECT ' . $m['select'] . crud_base_query($m) . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . crud_order_sql($m, $req) . ' LIMIT 50000';
    $rows = db_all($sql, $args);
    if ($hook = crud_hook($m, 'transform_row')) {
        $rows = array_map($hook, $rows);
    }
    $cols = crud_export_columns($m);
    $headers = array_map(fn ($c) => $c['label'], $cols);
    $data = [];
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) {
            $v = crud_format_export_value($r[$c['key']] ?? null, $c);
            if (!empty($c['sub']) && !empty($r[$c['sub']]) && ($c['format'] ?? '') === 'person') {
                $v .= ' (' . $r[$c['sub']] . ')';
            }
            $line[] = $v;
        }
        $data[] = $line;
    }
    log_activity('export', $m['permission'], null, 'Exported ' . count($data) . ' ' . strtolower($m['title']) . ' (' . strtoupper($format) . ')');
    $filename = $m['key'] . '-' . date('Y-m-d-His');
    if ($format === 'xlsx') {
        xlsx_download($filename . '.xlsx', $headers, $data, $m['title']);
    }
    if ($format === 'print' || $format === 'pdf') {
        print_layout_start($m['title'] . ' Report', true, count($cols) > 6 ? 'landscape' : 'portrait');
        echo '<div class="mt-5 mb-3 flex items-end justify-between"><div><h1 class="font-display text-xl font-bold text-brand-900">' . e($m['title']) . '</h1>'
            . '<p class="text-xs text-slate-500">Generated on ' . e(format_datetime(date('Y-m-d H:i:s'))) . ' · ' . count($data) . ' records</p></div></div>';
        echo '<table class="w-full text-[11px] border-collapse"><thead><tr>';
        foreach ($headers as $h) {
            echo '<th class="text-left font-semibold bg-brand-900 text-white px-2 py-1.5 border border-brand-900">' . e($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($data as $i => $line) {
            echo '<tr class="' . ($i % 2 ? 'bg-slate-50' : '') . '">';
            foreach ($line as $v) {
                echo '<td class="px-2 py-1 border border-slate-200 align-top">' . e($v) . '</td>';
            }
            echo '</tr>';
        }
        if (!$data) {
            echo '<tr><td colspan="' . count($headers) . '" class="px-2 py-6 text-center text-slate-500">No records match the current filters.</td></tr>';
        }
        echo '</tbody></table>';
        print_layout_end(true);
        exit;
    }
    csv_download($filename . '.csv', $headers, $data);
}

/* ------------------------------------------------------------------
 * Import
 * ------------------------------------------------------------------ */

function crud_import_fields(array $m): array
{
    return array_values(array_filter($m['fields'], fn ($f) => isset($f['name']) && ($f['import'] ?? true)
        && !in_array($f['type'], ['section', 'image', 'file', 'password', 'richtext', 'json', 'repeater'], true)));
}

/**
 * Example rows for the import template. A module can give its own with 'import_example' => [field => value] (or a
 * list of such rows); every other column gets a value that passes validation: the field default, the first option,
 * the first real record of a lookup (narrowed by the fields it depends on), a past date for birth dates, ...
 */
function crud_import_example_rows(array $m, array $fields): array
{
    $custom = $m['import_example'] ?? [];
    $custom = $custom && array_is_list($custom) ? $custom : [$custom];
    $rows = [];
    foreach ($custom as $over) {
        $over = is_array($over) ? $over : [];
        $ids = [];
        $row = [];
        foreach ($fields as $f) {
            $name = $f['name'];
            if (array_key_exists($name, $over)) {
                $row[] = (string) $over[$name];
                $ids[$name] = (string) $over[$name];
                continue;
            }
            $v = '';
            if (isset($f['options'])) {
                $opts = crud_static_options($f['options']);
                $v = array_key_exists('default', $f) ? (string) $f['default'] : (string) ($opts[0]['value'] ?? '');
                $ids[$name] = $v;
            } elseif (isset($f['source'])) {
                $params = ['limit' => 1];
                foreach ((array) ($f['depends'] ?? []) as $param => $field) {
                    if (($ids[$field] ?? '') !== '') {
                        $params[$param] = $ids[$field];
                    }
                }
                if ($f['source'] === 'sessions' && ($current = current_session_id())) {
                    $params['ids'] = (string) $current; // the current academic session, not the newest one in the list
                }
                try {
                    $o = lookup_options($f['source'], $params, 1)[0] ?? null;
                } catch (Throwable $e) {
                    $o = null;
                }
                if ($o) {
                    $ids[$name] = (string) $o['value'];
                    $v = (string) $o['label']; // the exact name is accepted on import (and reads better than an id)
                } elseif (!empty($f['required'])) {
                    $v = '(id or exact name)';
                }
            } else {
                $v = match ($f['type']) {
                    'date' => preg_match('/(^|_)(dob|birth|date_of_birth)/', $name) ? date('Y-m-d', strtotime('-19 years')) : date('Y-m-d'),
                    'email' => !empty($f['unique']) ? 'example.' . date('His') . '@example.com' : 'name@example.com',
                    'number', 'decimal', 'money' => (string) ($f['default'] ?? (str_contains($name, 'year') && ($f['min'] ?? 0) <= (int) date('Y') && ($f['max'] ?? 9999) >= (int) date('Y')
                        ? date('Y') : ($f['min'] ?? '0'))),
                    'boolean', 'toggle', 'checkbox' => (string) (int) !empty($f['default']),
                    'tel' => '+91 9876543210',
                    default => array_key_exists('default', $f) ? (string) $f['default']
                        : (['first_name' => 'Aarav', 'last_name' => 'Sharma'][$name]
                            ?? (!empty($f['required']) ? 'Sample ' . strtolower($f['label'] ?? label_from_key($name)) : '')),
                };
                $ids[$name] = $v;
            }
            $row[] = $v;
        }
        $rows[] = $row;
    }
    return $rows;
}

function crud_import_template(array $m): void
{
    $fields = crud_import_fields($m);
    $headers = array_map(fn ($f) => $f['name'], $fields);
    csv_download($m['key'] . '-import-template.csv', $headers, crud_import_example_rows($m, $fields));
}

/**
 * Column index => field name for an import header row. Accepted headers, in priority order: field names (the import
 * template), field labels, and the column labels of the module's own export - so a CSV / Excel export can be edited and
 * imported back. Export columns that hold a display name map to their id field ("program_name" -> program_id,
 * "session_name" -> academic_session_id) and "full_name" is split into first / middle / last name.
 */
function crud_import_header_map(array $m, array $header, array $fields): array
{
    $norm = fn ($s) => strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', (string) $s), '_'));
    $names = array_column($fields, 'name');
    $byName = [];
    $byLabel = [];
    foreach ($fields as $f) {
        $byName[$norm($f['name'])] = $f['name'];
        $byLabel[$norm($f['label'] ?? '')] ??= $f['name'];
    }
    $byExport = [];
    foreach (crud_export_columns($m) as $c) {
        $key = (string) ($c['key'] ?? '');
        $target = null;
        if (in_array($key, $names, true)) {
            $target = $key;
        } elseif ($key === 'full_name' && in_array('first_name', $names, true)) {
            $target = '__full_name';
        } elseif (preg_match('/^(.+)_name$/', $key, $mm)) {
            if (in_array($mm[1] . '_id', $names, true)) {
                $target = $mm[1] . '_id';
            } else {
                foreach ($names as $n) {
                    if (str_ends_with($n, '_' . $mm[1] . '_id')) {
                        $target = $n;
                        break;
                    }
                }
            }
        }
        if ($target !== null) {
            $byExport[$norm($c['label'] ?? $key)] ??= $target;
        }
    }
    $map = [];
    foreach ([$byName, $byLabel, $byExport] as $aliases) {
        foreach ($header as $i => $h) {
            $k = $norm($h);
            if ($k === '' || isset($map[$i]) || !isset($aliases[$k]) || in_array($aliases[$k], $map, true)) {
                continue;
            }
            $map[$i] = $aliases[$k];
        }
    }
    ksort($map);
    return $map;
}

/** Splits an exported "Name" column into first / middle / last name (unless the row already has a first name). */
function crud_import_split_name(array $input, array $names, ?array $existing): array
{
    $full = trim((string) preg_replace('/\s+/u', ' ', api_str($input['__full_name'] ?? null)));
    unset($input['__full_name']);
    if ($full === '' || trim(api_str($input['first_name'] ?? null)) !== '') {
        return $input;
    }
    if ($existing) {
        // unchanged name on an update: keep the stored split ("Mohd. Arif" + "Khan" stays as it is)
        $current = trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_filter([$existing['first_name'] ?? '', $existing['middle_name'] ?? '', $existing['last_name'] ?? ''], fn ($v) => trim((string) $v) !== ''))));
        if (mb_strtolower($current) === mb_strtolower($full)) {
            return $input;
        }
    }
    $parts = explode(' ', $full);
    $input['first_name'] = array_shift($parts);
    if ($parts && in_array('last_name', $names, true)) {
        $input['last_name'] = array_pop($parts);
    }
    if ($parts) {
        if (in_array('middle_name', $names, true)) {
            $input['middle_name'] = implode(' ', $parts);
        } elseif (in_array('last_name', $names, true)) {
            $input['last_name'] = trim(implode(' ', $parts) . ' ' . ($input['last_name'] ?? ''));
        } else {
            $input['first_name'] .= ' ' . implode(' ', $parts);
        }
    } elseif (in_array('middle_name', $names, true)) {
        $input['middle_name'] = '';
    }
    return $input;
}

/**
 * Import rows from an uploaded CSV/XLSX. Header row must contain field names or labels.
 * $mode: insert (skip rows that duplicate a unique field) | upsert (update matching record by first unique field)
 * $scope: the embedded table's scope (e.g. ['event_id' => 12]) - new rows are created inside it, like the add form does
 */
function crud_import(array $m, array $file, string $mode = 'insert', array $scope = []): array
{
    $scope = array_filter($scope, fn ($v, $k) => isset($m['scopes'][$k]) && is_scalar($v) && (string) $v !== '', ARRAY_FILTER_USE_BOTH);
    $res = upload_file($file, 'spreadsheet', 'imports', true);
    if (!$res['ok']) {
        throw new CrudException($res['error']);
    }
    $path = APP_ROOT . '/' . $res['path'];
    try {
        $rows = spreadsheet_read($path, $res['extension']);
    } catch (CrudException $e) {
        throw $e;
    } catch (RuntimeException $e) {
        throw new CrudException($e->getMessage()); // damaged / oversized workbook: a 422 with the reason, not a 500
    } finally {
        @unlink($path);
    }
    if (count($rows) < 2) {
        throw new CrudException('The file has no data rows. Download the template to see the expected columns.');
    }
    $fields = crud_import_fields($m);
    $fieldNames = array_column($fields, 'name');
    $header = array_shift($rows);
    $map = crud_import_header_map($m, $header, $fields);
    if (!$map) {
        throw new CrudException('No matching columns found. Use the column names from the import template.');
    }
    $uniqueField = null;
    foreach ($fields as $f) {
        if (!empty($f['unique'])) {
            $uniqueField = $f['name'];
            break;
        }
    }
    $summary = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    foreach ($rows as $n => $line) {
        if (!array_filter($line, fn ($v) => trim((string) $v) !== '')) {
            continue;
        }
        $summary['total']++;
        $input = [];
        foreach ($map as $i => $name) {
            $input[$name] = $line[$i] ?? '';
        }
        if ($scope) {
            $input['__scope'] = $scope;
            foreach ($scope as $k => $v) {
                if (($input[$k] ?? '') === '' && in_array($k, $fieldNames, true)) {
                    $input[$k] = (string) $v; // a scope column that is also a form field (event_id ...) is filled in
                }
            }
        }
        $existingId = null;
        if ($uniqueField && ($input[$uniqueField] ?? '') !== '') {
            $existingId = db_value('SELECT ' . db_quote_ident($m['primary']) . ' FROM ' . db_quote_ident($m['table']) . ' WHERE ' . db_quote_ident($uniqueField) . ' = ?', [$input[$uniqueField]]);
            if ($existingId && $mode !== 'upsert') {
                $summary['skipped']++;
                $summary['errors'][] = ['row' => $n + 2, 'message' => "Skipped: $uniqueField \"{$input[$uniqueField]}\" already exists."];
                continue;
            }
        }
        if (array_key_exists('__full_name', $input)) {
            $existing = $existingId ? db_row('SELECT * FROM ' . db_quote_ident($m['table']) . ' WHERE ' . db_quote_ident($m['primary']) . ' = ?', [(int) $existingId]) : null;
            $input = crud_import_split_name($input, $fieldNames, $existing);
        }
        try {
            crud_save($m, $input, [], $existingId ? (int) $existingId : null, true);
            $existingId ? $summary['updated']++ : $summary['inserted']++;
        } catch (CrudValidationException $e) {
            $summary['failed']++;
            $summary['errors'][] = ['row' => $n + 2, 'message' => implode(' ', array_values($e->errors))];
        } catch (CrudException $e) {
            $summary['failed']++;
            $summary['errors'][] = ['row' => $n + 2, 'message' => $e->getMessage()];
        }
        if (count($summary['errors']) > 500) {
            break;
        }
    }
    log_activity('import', $m['permission'], null, sprintf('Imported %s: %d added, %d updated, %d skipped, %d failed', strtolower($m['title']), $summary['inserted'], $summary['updated'], $summary['skipped'], $summary['failed']));
    return $summary;
}
