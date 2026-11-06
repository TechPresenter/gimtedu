<?php
/**
 * Redirects manager (Website CMS → Redirects, unit seo_platform). Rules are applied by app/services/seo.php:
 * includes/header.php (live pages: 3xx rules), 404.php / tools/router.php / .htaccess (missing URLs: 3xx and 410,
 * lower-case and trailing-slash variants). Validation, loop + chain protection and normalisation:
 * seo_redirect_prepare(); after saving, rules pointing at the saved "from" URL are flattened to the new target.
 * CSV import/export through the generic CRUD engine: field labels equal the exported column labels (Old URL,
 * Redirects to, Type, Active, Notes), so an exported file imports again (upsert matches on the old URL); the import
 * template uses the field names from_path, to_url, status_code, preserve_query, is_active, notes.
 */
require_once APP_ROOT . '/app/services/seo.php';

if (!function_exists('redirects_input')) {
    /**
     * Values for seo_redirect_prepare(): the CRUD engine turns toggles that a CSV import does not contain into 0, which
     * would silently switch rules off and drop "keep the query string". A toggle the request did not send keeps its
     * stored value (or the default 1 for a new rule).
     */
    function redirects_input(array $data, array $input): array
    {
        foreach (['preserve_query', 'is_active'] as $k) {
            if (!array_key_exists($k, $input) || $input[$k] === '' || $input[$k] === null) {
                unset($data[$k], $input[$k]);
            }
        }
        return $data + $input;
    }
}

$codes = ['301' => '301 Moved permanently', '302' => '302 Found (temporary)', '307' => '307 Temporary (keeps method)', '308' => '308 Permanent (keeps method)', '410' => '410 Gone (removed)'];

return [
    'table' => 'redirects',
    'title' => 'Redirects',
    'singular' => 'Redirect',
    'permission' => 'seo',
    'permissions' => ['create' => ['seo', 'edit'], 'edit' => ['seo', 'edit'], 'delete' => ['seo', 'edit'], 'export' => ['seo', 'view'], 'import' => ['seo', 'edit']],
    'icon' => 'arrow-left-right',
    'description' => '301 / 302 redirects and 410 Gone rules for moved or removed URLs.',
    'select' => 't.*, u.name AS created_by_name',
    'joins' => 'LEFT JOIN users u ON u.id = t.created_by',
    'search' => ['t.from_path', 't.to_url', 't.notes'],
    'search_placeholder' => 'Search old or new URL, notes…',
    'order' => 't.id DESC',
    'columns' => [
        ['key' => 'from_path', 'label' => 'Old URL', 'format' => 'code', 'sortable' => true],
        ['key' => 'to_url', 'label' => 'Redirects to', 'format' => 'code', 'sortable' => true],
        ['key' => 'status_code', 'label' => 'Type', 'format' => 'badge', 'align' => 'center', 'sortable' => true,
            'colors' => ['301' => 'blue', '308' => 'blue', '302' => 'amber', '307' => 'amber', '410' => 'red']],
        ['key' => 'hits', 'label' => 'Hits', 'format' => 'number', 'align' => 'right', 'sortable' => true],
        ['key' => 'last_hit_at', 'label' => 'Last hit', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'is_active', 'label' => 'Active', 'format' => 'boolean', 'align' => 'center', 'sortable' => true],
        // "Preserve query" normalises to the field name preserve_query, so an exported file imports the setting back.
        ['key' => 'preserve_query', 'label' => 'Preserve query', 'format' => 'boolean', 'align' => 'center', 'hidden' => true],
        ['key' => 'source', 'label' => 'Source', 'format' => 'badge', 'hidden' => true, 'sortable' => true,
            'colors' => ['manual' => 'slate', 'import' => 'purple', 'system' => 'navy', '404' => 'amber']],
        ['key' => 'notes', 'label' => 'Notes', 'format' => 'truncate', 'hidden' => true],
        ['key' => 'created_by_name', 'label' => 'Added by', 'hidden' => true],
        ['key' => 'updated_at', 'label' => 'Updated', 'format' => 'datetime', 'hidden' => true, 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'status_code', 'label' => 'Type', 'options' => $codes],
        ['key' => 'is_active', 'label' => 'Status', 'options' => ['1' => 'Active', '0' => 'Inactive']],
        ['key' => 'source', 'label' => 'Source', 'options' => ['manual' => 'Added by hand', 'import' => 'Imported', 'system' => 'Built-in', '404' => 'From the 404 report']],
        ['key' => 'usage', 'label' => 'Usage', 'sql_map' => [
            'recent' => 't.last_hit_at >= NOW() - INTERVAL 30 DAY', 'used' => 't.hits > 0', 'unused' => 't.hits = 0',
            'stale' => '(t.last_hit_at IS NULL OR t.last_hit_at < NOW() - INTERVAL 180 DAY)',
        ], 'options' => ['recent' => 'Used in the last 30 days', 'used' => 'Used at least once', 'unused' => 'Never used', 'stale' => 'Not used for 6 months']],
    ],
    'fields' => [
        ['name' => 'from_path', 'label' => 'Old URL', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 12, 'maxlength' => 500,
            'placeholder' => '/old-page', 'help' => 'A path on this website, or a full URL of this website. Add ?query to match one exact query string only.'],
        ['name' => 'status_code', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => '301', 'options' => $codes, 'col' => 6],
        ['name' => 'to_url', 'label' => 'Redirects to', 'type' => 'text', 'col' => 12, 'maxlength' => 1000,
            'placeholder' => '/new-page or https://…', 'help' => 'A path on this website or a full https:// address. Not used for 410 Gone.'],
        ['name' => 'preserve_query', 'label' => 'Keep the visitor’s query string (e.g. ?utm_source=…)', 'type' => 'toggle', 'default' => 1, 'col' => 6],
        ['name' => 'is_active', 'label' => 'Active', 'type' => 'toggle', 'default' => 1, 'col' => 6],
        ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 500],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            return seo_redirect_prepare(redirects_input($data, $input), $id, $old)['errors'];
        },
        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            $r = seo_redirect_prepare(redirects_input($data, $input), $id, $old);
            if ($r['errors']) {
                throw new CrudValidationException($r['errors']);
            }
            $data = array_merge($data, $r['data']);
            if ($id === null) {
                $importing = function_exists('api_path') && str_ends_with(api_path(), '/import');
                $data['source'] = $importing ? 'import' : (($input['source'] ?? '') === '404' ? '404' : 'manual');
            }
            return $data;
        },
        'after_save' => function (int $id): void {
            seo_redirect_flatten_incoming($id);
        },
        'describe' => function (array $row): string {
            return ($row['from_path'] ?? '') . ' → ' . ((int) ($row['status_code'] ?? 301) === 410 ? '410 Gone' : ($row['to_url'] ?? '') . ' (' . ($row['status_code'] ?? 301) . ')');
        },
        'transform_row' => function (array $row): array {
            $warnings = [];
            if ((int) $row['is_active'] === 1) {
                if ((int) $row['status_code'] !== 410 && !empty($row['to_path']) && !seo_path_exists((string) $row['to_path'])) {
                    $warnings[] = 'target_missing';
                }
                if (seo_path_exists(mb_strtolower(explode('?', (string) $row['from_path'], 2)[0]))) {
                    $warnings[] = (int) $row['status_code'] === 410 ? 'gone_but_live' : 'from_live';
                }
            }
            $row['warnings'] = $warnings;
            $row['status_code'] = (string) $row['status_code'];
            return $row;
        },
    ],
];
