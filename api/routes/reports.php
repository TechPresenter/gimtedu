<?php
/**
 * Reports & Analytics endpoints (brief §29). Engine + report definitions live in app/services/reports.php.
 *
 *   GET /reports                                   catalogue: areas + reports this user may open, export activity
 *   GET /reports/{key}/meta                        filters (with options), columns, default sort
 *   GET /reports/{key}?filters&page&per_page&sort&dir   KPIs, chart, server-side paginated rows, applied filters
 *   GET /reports/{key}/export?format=csv|xlsx&filters   file download                          reports.export
 *   Print / PDF: /admin/print/report.php?report={key}&filters                                    reports.export
 *
 * Every report needs reports.view AND view on its underlying module (e.g. fees, placement).
 * Filters: from, to, session_id (absent = current session, 'all' = every session), department_id, program_id,
 * semester, status + report-specific keys, q (search).
 */
require_once APP_ROOT . '/app/services/reports.php';

/** Resolve a report for the current user or respond 404 / 403. */
function reports_api_def(string $key): array
{
    api_require('reports', 'view');
    $cat = reports_catalog()[$key] ?? null;
    if (!$cat) {
        api_error('This report does not exist.', 404);
    }
    if (!can($cat['module'], 'view')) {
        if (!function_exists('permission_modules')) {
            require_once APP_ROOT . '/app/registry.php';
        }
        $label = permission_modules()[$cat['module']]['label'] ?? label_from_key($cat['module']);
        api_error('You need view access to ' . $label . ' to open this report.', 403);
    }
    return reports_def($key);
}

route('GET', '/reports', function () {
    api_require('reports', 'view');
    $reports = [];
    $areaCounts = [];
    foreach (reports_catalog() as $key => $cat) {
        if (!can($cat['module'], 'view')) {
            continue;
        }
        $reports[] = ['key' => $key, 'area' => $cat['area'], 'title' => $cat['title'], 'description' => $cat['description'], 'icon' => $cat['icon'], 'module' => $cat['module']];
        $areaCounts[$cat['area']] = ($areaCounts[$cat['area']] ?? 0) + 1;
    }
    $areas = [];
    foreach (reports_areas() as $key => $a) {
        if (isset($areaCounts[$key])) {
            $areas[] = ['key' => $key, 'label' => $a['label'], 'icon' => $a['icon'], 'description' => $a['description'], 'count' => $areaCounts[$key]];
        }
    }
    api_ok([
        'areas' => $areas,
        'reports' => $reports,
        'total_reports' => count(reports_catalog()),
        'can_export' => can('reports', 'export'),
        'activity' => reports_activity(),
        'session' => current_session()['name'] ?? null,
    ]);
});

route('GET', '/reports/{key:[a-z_]+}/meta', function ($p) {
    $def = reports_api_def($p['key']);
    api_ok(reports_meta($def));
});

route('GET', '/reports/{key:[a-z_]+}/export', function ($p) {
    $def = reports_api_def($p['key']);
    api_require('reports', 'export');
    $format = (string) ($_GET['format'] ?? 'csv');
    if (!in_array($format, ['csv', 'xlsx'], true)) {
        api_error('Choose CSV or Excel. Use the print view for PDF.', 422);
    }
    reports_download($def, reports_filters($def, $_GET), $format);
});

route('GET', '/reports/{key:[a-z_]+}', function ($p) {
    $def = reports_api_def($p['key']);
    $f = reports_filters($def, $_GET);
    [$page, $perPage] = api_pagination(25);
    $sort = isset($_GET['sort']) ? (string) $_GET['sort'] : null;
    $dir = isset($_GET['dir']) ? (string) $_GET['dir'] : null;
    $run = reports_run($def, $f, $page, $perPage, $sort, $dir);
    api_ok([
        'key' => $def['key'],
        'kpis' => $run['kpis'],
        'chart' => $run['chart'],
        'rows' => $run['rows'],
        'pagination' => $run['pagination'],
        'sort' => $run['sort'],
        'applied' => reports_applied($def, $f),
        'filters' => array_filter($f, fn ($v) => $v !== null && $v !== ''),
        'generated_at' => date('Y-m-d H:i:s'),
    ]);
});
