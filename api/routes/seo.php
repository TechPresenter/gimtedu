<?php
/**
 * SEO Management (docs/CMS.md §6).
 *   GET    /api/seo/overview                                  score, coverage, issues per type, worst URLs
 *   GET    /api/seo/entries?type=&issue=&status=&q=&page=     coverage table (server-side filter + pagination)
 *   GET    /api/seo/entry?type=page&id=5 | ?type=route&route=/about
 *   PUT    /api/seo/entry                                     {type, id|route, meta_title, …}  upsert
 *   DELETE /api/seo/entry?type=&id=|route=                    reset to defaults (delete the record)
 *   GET    /api/seo/robots      PUT /api/seo/robots {content} robots.txt (setting robots_txt)
 *   GET    /api/seo/sitemap                                   URLs that /sitemap.xml contains (+ pending templates)
 *   GET    /api/seo/sitemap-xml | /api/seo/robots-txt         download the generated sitemap.xml / robots.txt files
 */
require_once APP_ROOT . '/app/services/cms.php';

function seo_target_from_request(array $src): array
{
    $type = (string) ($src['type'] ?? '');
    $target = cms_seo_resolve($type, isset($src['id']) && $src['id'] !== '' ? (int) $src['id'] : null, isset($src['route']) ? (string) $src['route'] : null);
    if (!$target) {
        api_error($type === 'route' ? 'Enter a valid path starting with /, e.g. /contact.' : 'The page or record you are editing no longer exists.', $type === 'route' ? 422 : 404,
            $type === 'route' ? ['route' => 'Enter a valid path starting with /, e.g. /contact.'] : []);
    }
    return $target;
}

function seo_entry_payload(array $target): array
{
    $row = cms_seo_find($target);
    $e = $target['entity'];
    $suffix = cms_seo_suffix();
    return [
        'entity' => ['type' => $e['type'], 'id' => $e['id'], 'route' => $e['route'], 'title' => $e['title'], 'url' => $e['url'], 'absolute_url' => absolute_url(ltrim($e['url'], '/')), 'status' => $e['status']],
        'ref' => ['type' => $target['ref_type'], 'id' => $target['ref_id'], 'route' => $target['ref_route']],
        'seo' => $row ? array_merge($row, ['id' => (int) $row['id'], 'include_in_sitemap' => (bool) $row['include_in_sitemap'], 'sitemap_priority' => (float) $row['sitemap_priority']]) : null,
        'defaults' => [
            'meta_title' => ($e['type'] === 'route' && $e['route'] === '/') ? institute_name() . $suffix : $e['title'] . $suffix,
            'meta_description' => str_limit((string) $e['fallback_description'], 160),
            'og_image' => $e['fallback_image'] ?: 'assets/images/site/hero-campus-students.jpg',
            'site_name' => institute_name(),
        ],
    ];
}

route('GET', '/seo/overview', function () {
    cms_api_require('seo', 'view');
    api_ok(cms_seo_overview() + ['can' => ['edit' => can('seo', 'edit')], 'site_url' => absolute_url('')]);
});

route('GET', '/seo/entries', function () {
    cms_api_require('seo', 'view');
    $type = (string) ($_GET['type'] ?? '');
    if ($type !== '' && !isset(cms_seo_entity_types()[$type])) {
        api_error('Unknown type.', 422);
    }
    $rows = cms_seo_entries($type ?: null);
    $issue = (string) ($_GET['issue'] ?? '');
    $status = (string) ($_GET['status'] ?? '');
    $q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
    $rows = array_values(array_filter($rows, function ($r) use ($issue, $status, $q) {
        if ($issue === 'any') {
            if (!array_diff($r['issues'], ['noindex', 'auto_description'])) {
                return false;
            }
        } elseif ($issue === 'ok') {
            if (array_diff($r['issues'], ['auto_description'])) {
                return false;
            }
        } elseif ($issue !== '' && !in_array($issue, $r['issues'], true)) {
            return false;
        }
        if ($status === 'public' && !$r['is_public']) {
            return false;
        }
        if ($status === 'hidden' && $r['is_public']) {
            return false;
        }
        if ($status === 'custom' && !$r['has_seo']) {
            return false;
        }
        if ($status === 'default' && $r['has_seo']) {
            return false;
        }
        if ($q !== '' && !str_contains(mb_strtolower($r['title'] . ' ' . $r['url'] . ' ' . $r['effective_title']), $q)) {
            return false;
        }
        return true;
    }));
    $sort = (string) ($_GET['sort'] ?? 'score');
    $dir = ($_GET['dir'] ?? 'asc') === 'desc' ? -1 : 1;
    usort($rows, function ($a, $b) use ($sort, $dir) {
        $va = $a[$sort] ?? $a['score'];
        $vb = $b[$sort] ?? $b['score'];
        $c = is_numeric($va) && is_numeric($vb) ? $va <=> $vb : strcasecmp((string) $va, (string) $vb);
        return ($c ?: strcasecmp($a['title'], $b['title'])) * $dir;
    });
    [$page, $perPage] = api_pagination(25);
    $p = paginate(count($rows), $page, $perPage);
    api_ok(['rows' => array_slice($rows, $p['offset'], $p['per_page']), 'total' => $p['total'], 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages'],
        'from' => $p['from'], 'to' => $p['to'], 'issue_labels' => cms_seo_issue_labels()]);
});

route('GET', '/seo/entry', function () {
    cms_api_require('seo', 'view');
    api_ok(seo_entry_payload(seo_target_from_request($_GET)) + ['can' => ['edit' => can('seo', 'edit')]]);
});

route('PUT', '/seo/entry', function () {
    cms_api_require('seo', 'edit');
    $in = api_input();
    $target = seo_target_from_request($in);
    [$data, $errors] = cms_seo_check_input($in);
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $row = cms_seo_find($target);
    if ($row) {
        db_update('seo_settings', $data, 'id = ?', [$row['id']]);
        db_exec('UPDATE seo_settings SET updated_at = NOW() WHERE id = ?', [$row['id']]);
    } else {
        db_insert('seo_settings', $data + ['entity_type' => $target['ref_type'], 'entity_id' => $target['ref_id'], 'route' => $target['ref_route']]);
    }
    $label = $target['entity']['title'] . ' (' . $target['entity']['url'] . ')';
    log_activity($row ? 'update' : 'create', 'seo', $target['ref_route'] ?? ($target['ref_type'] . ':' . $target['ref_id']), 'Updated SEO for ' . $label);
    api_ok(seo_entry_payload($target), 'SEO settings saved for ' . $target['entity']['title'] . '.');
});

route('DELETE', '/seo/entry', function () {
    cms_api_require('seo', 'edit');
    $target = seo_target_from_request($_GET + api_input());
    $row = cms_seo_find($target);
    if (!$row) {
        api_error('This URL already uses the default SEO settings.', 404);
    }
    db_delete('seo_settings', 'id = ?', [$row['id']]);
    log_activity('delete', 'seo', $row['id'], 'Reset SEO of ' . $target['entity']['title'] . ' (' . $target['entity']['url'] . ') to defaults');
    api_ok(seo_entry_payload($target), 'SEO reset to the automatic defaults.');
});

route('GET', '/seo/robots', function () {
    cms_api_require('seo', 'view');
    $custom = trim((string) setting('robots_txt', ''));
    api_ok(['content' => $custom !== '' ? $custom : rtrim(cms_default_robots_txt()), 'is_custom' => $custom !== '', 'default' => rtrim(cms_default_robots_txt()),
        'robots_url' => absolute_url('robots.txt'), 'sitemap_url' => absolute_url('sitemap.xml'), 'served' => cms_seo_files_served(), 'can' => ['edit' => can('seo', 'edit')]]);
});

route('PUT', '/seo/robots', function () {
    cms_api_require('seo', 'edit');
    $in = api_input();
    $content = trim(str_replace("\r\n", "\n", (string) ($in['content'] ?? '')));
    $reset = !empty($in['reset']) || $content === '' || $content === rtrim(cms_default_robots_txt());
    if (!$reset && ($err = cms_robots_error($content))) {
        api_error($err, 422, ['content' => $err]);
    }
    save_setting('robots_txt', $reset ? '' : $content, 'seo');
    log_activity('update', 'seo', 'robots_txt', $reset ? 'Reset robots.txt to the default rules' : 'Updated robots.txt (' . count(array_filter(explode("\n", $content), 'trim')) . ' lines)');
    api_ok(['content' => $reset ? rtrim(cms_default_robots_txt()) : $content, 'is_custom' => !$reset], $reset ? 'robots.txt reset to the default rules.' : 'robots.txt saved.');
});

route('GET', '/seo/sitemap', function () {
    cms_api_require('seo', 'view');
    $urls = cms_sitemap_urls(true);
    $counts = [];
    foreach ($urls as $u) {
        $counts[$u['type']] = ($counts[$u['type']] ?? 0) + ($u['pending'] ? 0 : 1);
    }
    api_ok(['urls' => $urls, 'counts' => $counts, 'live' => count(array_filter($urls, fn ($u) => !$u['pending'])), 'pending' => count(array_filter($urls, fn ($u) => $u['pending'])),
        'sitemap_url' => absolute_url('sitemap.xml'), 'served' => cms_seo_files_served()]);
});

/** Generated files for download (submit to Search Console / upload while the website does not serve them yet). */
route('GET', '/seo/sitemap-xml', function () {
    cms_api_require('seo', 'view');
    $xml = cms_sitemap_xml();
    log_activity('export', 'seo', 'sitemap', 'Downloaded sitemap.xml (' . substr_count($xml, '<url>') . ' URLs)');
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="sitemap.xml"');
    header('Cache-Control: no-store');
    echo $xml;
    exit;
});

route('GET', '/seo/robots-txt', function () {
    cms_api_require('seo', 'view');
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="robots.txt"');
    header('Cache-Control: no-store');
    echo cms_robots_txt();
    exit;
});
