<?php
/**
 * SEO module: overview of real issues (counts, no score), inventory of every public URL, per-URL SEO editor,
 * links (broken, unpublished, through redirects, orphans, manual external check), redirect chains and loops,
 * awareness-day editions, image descriptions, sitemap and robots previews, owner trust checklist, integrations,
 * CSV import / export and the field-level change log. Logic: includes/seo-admin.php.
 */
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/seo-admin.php';
seo_admin_require_access();

$tabs = [
    'overview' => ['Overview', 'activity'],
    'pages' => ['Pages & content', 'list'],
    'links' => ['Links', 'link'],
    'redirects' => ['Redirects', 'refresh'],
    'awareness' => ['Awareness days', 'calendar'],
    'images' => ['Images', 'image'],
    'sitemaps' => ['Sitemaps & robots', 'globe'],
    'trust' => ['Trust checklist', 'shield'],
    'integrations' => ['Integrations', 'settings'],
    'bulk' => ['Import / export', 'upload'],
    'log' => ['Change log', 'clock'],
];
$tab = get_str('tab', 'overview');
if ($tab !== 'edit' && !isset($tabs[$tab])) {
    $tab = 'overview';
}
$isAdmin = seo_admin_is_administrator();
$canSettings = admin_can('settings.manage');
$canExport = admin_can('exports.run');
$seoUrl = function (array $q = []): string {
    return admin_url('seo.php' . ($q ? '?' . http_build_query($q) : ''));
};

// ---------------------------------------------------------------------
// Downloads (GET)
// ---------------------------------------------------------------------
$export = get_str('export');
if ($export === 'template') {
    // column names only, no data: available to everyone with SEO access
    send_csv('seo-import-template.csv', seo_admin_csv_header(), []);
}
if ($export === 'inventory') {
    require_permission('exports.run');
    $rows = seo_admin_csv_rows();
    admin_log('seo_export', 'SEO metadata exported (' . count($rows) . ' URLs)');
    send_csv('seo-urls-' . date('Y-m-d') . '.csv', seo_admin_csv_header(), $rows);
}
if ($export === 'log') {
    require_permission('exports.run');
    [$logRows] = seo_admin_changes(['type' => get_str('type'), 'field' => get_str('field'), 'user' => (int) get_str('user'), 'from' => get_str('from'), 'to' => get_str('to')], 5000);
    admin_log('seo_export', 'SEO change log exported (' . count($logRows) . ' rows)');
    send_csv('seo-change-log-' . date('Y-m-d') . '.csv', ['Date', 'Staff', 'Type', 'ID', 'Field', 'Old value', 'New value'], array_map(function ($r) {
        return [$r['created_at'], $r['user_name'] ?? '', $r['entity_type'], $r['entity_id'], $r['field'], $r['old_value'], $r['new_value']];
    }, $logRows));
}

// ---------------------------------------------------------------------
// Actions (POST)
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postTab = post_str('tab');
    $back = 'seo.php' . (isset($tabs[$postTab]) || $postTab === 'edit' ? '?tab=' . $postTab : '');
    if ($postTab === 'edit') {
        $back .= '&type=' . rawurlencode(post_str('type')) . '&id=' . (int) post_str('id');
    }
    admin_post_only($back);
    $action = post_str('action');

    if ($action === 'save_entity') {
        $type = post_str('type');
        $id = (int) post_str('id');
        $entity = isset(SEO_ADMIN_TYPES[$type]) ? seo_admin_entity($type, $id) : null;
        if (!$entity) {
            flash('error', 'This page or item no longer exists.');
            admin_redirect('seo.php?tab=pages');
        }
        $editBack = 'admin/seo.php?tab=edit&type=' . rawurlencode($type) . '&id=' . $id;
        $input = [];
        foreach (seo_admin_field_defs() as $f => $def) {
            if (!seo_admin_storage($type, $f) || !seo_admin_field_available($type, $f) || !array_key_exists($f, $_POST)) {
                continue;
            }
            $input[$f] = $_POST[$f];
        }
        $upload = site_file_at('og_image_upload', []);
        if ($upload && seo_admin_field_available($type, 'og_image')) {
            $path = site_upload_image_array($upload, 'pages');
            if (!$path) {
                back_with_errors(['og_image' => 'Social image: the upload failed (see the message above).'], $editBack, $_POST);
            }
            $input['og_image'] = $path;
        }
        [$clean, $errors, $warnings] = seo_admin_validate($type, $input, $isAdmin);
        if ($errors) {
            back_with_errors($errors, $editBack, $_POST);
        }
        $changes = seo_admin_save($type, $id, $clean);
        if ($changes) {
            admin_log('seo_updated', SEO_ADMIN_TYPES[$type] . ' #' . $id . ' "' . seo_admin_short((string) ($entity['title'] ?? ''), 80) . '": ' . implode(', ', array_keys($changes)));
            flash('success', 'SEO settings saved (' . count($changes) . ' field' . (count($changes) === 1 ? '' : 's') . ' changed).');
        } else {
            flash('info', 'Nothing changed.');
        }
        foreach ($warnings as $w) {
            flash('warning', $w);
        }
        admin_redirect('seo.php?tab=edit&type=' . rawurlencode($type) . '&id=' . $id);
    }

    if ($action === 'prune_meta') {
        $n = seo_admin_prune_meta();
        admin_log('seo_meta_pruned', 'Removed ' . $n . ' SEO settings rows of deleted pages or items');
        flash('success', $n ? 'Removed ' . $n . ' SEO settings row' . ($n === 1 ? '' : 's') . ' of deleted pages or items.' : 'Nothing to remove.');
        admin_redirect('seo.php');
    }

    if ($action === 'ext_check') {
        $res = seo_check_external_links(SEO_ADMIN_EXT_BATCH);
        if ($res['error']) {
            flash('error', $res['error']);
        } else {
            admin_log('seo_external_check', 'External link check: ' . $res['checked'] . ' checked, ' . $res['remaining'] . ' never checked');
            flash('success', $res['total'] ? 'Checked ' . $res['checked'] . ' external link' . ($res['checked'] === 1 ? '' : 's') . '.'
                . ($res['remaining'] ? ' ' . $res['remaining'] . ' not checked yet - click again to continue.' : '') : 'No external links found in the content.');
        }
        admin_redirect('seo.php?tab=links#external');
    }

    if ($action === 'awareness') {
        [$ok, $msg] = seo_admin_awareness_save(post_str('group'), (int) post_str('edition'));
        if ($ok && $msg !== 'Nothing changed.') {
            admin_log('seo_awareness_canonical', post_str('group') . ': ' . $msg);
        }
        flash($ok ? 'success' : 'error', $msg);
        admin_redirect('seo.php?tab=awareness#group-' . preg_replace('/[^a-z0-9\-]/', '', post_str('group')));
    }

    if ($action === 'save_alt') {
        [$ok, $msg] = seo_admin_save_alt(post_str('path'), post_str('alt'));
        if ($ok && $msg !== 'Nothing changed.') {
            admin_log('media_alt_updated', 'Image description: ' . seo_admin_short(post_str('path'), 120));
        }
        flash($ok ? 'success' : 'error', $msg);
        admin_redirect(admin_back('seo.php?tab=images'));
    }

    if ($action === 'image_backfill' && function_exists('seo_image_backfill')) {
        try {
            $res = (array) seo_image_backfill(20, 15.0);
            admin_log('seo_image_backfill', 'Image copies: ' . json_encode($res));
            flash('success', 'Image copies processed: ' . e(implode(', ', array_map(function ($k, $v) {
                return $k . ' ' . (is_scalar($v) ? $v : count((array) $v));
            }, array_keys($res), $res))) . '.');
        } catch (Throwable $ex) {
            flash('error', 'The image copies could not be created: ' . $ex->getMessage());
        }
        admin_redirect('seo.php?tab=images');
    }

    if ($action === 'save_settings') {
        if (!$canSettings) {
            flash('error', 'Only staff with the "settings" permission can change these settings.');
            admin_redirect('seo.php?tab=integrations');
        }
        $fields = [];
        foreach (seo_admin_setting_groups() as $g) {
            $fields += seo_admin_group_fields($g[2]);
        }
        [$changed, $errors] = seo_admin_save_settings($fields, $_POST);
        if ($errors) {
            back_with_errors($errors, 'admin/seo.php?tab=integrations', $_POST);
        }
        if ($changed) {
            admin_log('settings_updated', 'SEO integrations: ' . implode(', ', $changed));
            flash('success', 'Settings saved.');
        } else {
            flash('info', 'Nothing changed.');
        }
        admin_redirect('seo.php?tab=integrations');
    }

    if ($action === 'import_preview') {
        $file = $_FILES['csv_file'] ?? null;
        if (!$file || is_array($file['error'] ?? null) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            flash('error', 'Choose a CSV file to import.');
            admin_redirect('seo.php?tab=bulk');
        }
        if ((int) $file['size'] > SEO_ADMIN_CSV_MAX_BYTES || !preg_match('/\.(csv|txt)$/i', (string) $file['name'])) {
            flash('error', 'Upload a .csv file of at most 2 MB.');
            admin_redirect('seo.php?tab=bulk');
        }
        $preview = seo_admin_csv_preview((string) $file['tmp_name'], post_str('mode') === 'overwrite', $isAdmin);
        if ($preview['error']) {
            flash('error', $preview['error']);
            admin_redirect('seo.php?tab=bulk');
        }
        $preview['token'] = bin2hex(random_bytes(16));
        $preview['file'] = mb_substr(basename((string) $file['name']), 0, 120);
        $preview['created'] = time();
        $_SESSION['seo_csv_preview'] = $preview;
        admin_redirect('seo.php?tab=bulk#preview');
    }

    if ($action === 'import_apply') {
        $preview = $_SESSION['seo_csv_preview'] ?? null;
        if (!is_array($preview) || !hash_equals((string) ($preview['token'] ?? ''), post_str('token')) || (int) ($preview['created'] ?? 0) < time() - 3600) {
            unset($_SESSION['seo_csv_preview']);
            flash('error', 'This preview has expired. Upload the file again.');
            admin_redirect('seo.php?tab=bulk');
        }
        unset($_SESSION['seo_csv_preview']);
        $res = seo_admin_csv_apply($preview, $isAdmin);
        admin_log('seo_import', 'SEO CSV import (' . ($preview['mode'] === 'overwrite' ? 'replace' : 'fill empty') . ', ' . $preview['file'] . '): '
            . $res['applied'] . ' fields on ' . $res['entities'] . ' URLs' . ($res['skipped'] ? ', ' . $res['skipped'] . ' skipped' : ''));
        flash('success', 'Import applied: ' . $res['applied'] . ' field' . ($res['applied'] === 1 ? '' : 's') . ' on ' . $res['entities'] . ' URL' . ($res['entities'] === 1 ? '' : 's') . '.'
            . ($res['skipped'] ? ' ' . $res['skipped'] . ' skipped because the value changed since the preview.' : ''));
        admin_redirect('seo.php?tab=log');
    }

    if ($action === 'import_cancel') {
        unset($_SESSION['seo_csv_preview']);
        flash('info', 'Import cancelled. Nothing was changed.');
        admin_redirect('seo.php?tab=bulk');
    }

    flash('error', 'Unknown action.');
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// Small render helpers
// ---------------------------------------------------------------------
/** Character count with a colour for the recommended range. */
function seo_page_len(int $len, int $min, int $max): string
{
    $cls = $len === 0 ? 'bad' : ($len < $min || $len > $max ? 'warn' : 'ok');
    return '<span class="seo-len seo-len-' . $cls . '" title="Recommended ' . $min . '-' . $max . ' characters">' . $len . '</span>';
}

/** Issue badges of an item (counts by level, with the labels as a list for screen readers and as a tooltip). */
/** Length of the printed title; warns when it is short (with the site name) or the title itself is over the limit. */
function seo_page_title_len(array $it): string
{
    $full = mb_strlen($it['full_title']);
    $own = mb_strlen($it['seo_title']);
    $cls = $own === 0 ? 'bad' : ($full < SEO_ADMIN_TITLE_MIN || $own > seo_admin_title_max() ? 'warn' : 'ok');
    return '<span class="seo-len seo-len-' . $cls . '" title="Printed title: ' . $full . ' characters; title before the site name: ' . $own . ' (limit ' . seo_admin_title_max() . ')">' . $full . '</span>';
}

function seo_page_issue_badges(array $issues): string
{
    $defs = seo_admin_check_defs();
    $by = ['error' => [], 'warn' => [], 'info' => []];
    foreach ($issues as $code => $detail) {
        if ($code === 'noindex' || $code === 'kw_none') {
            continue;
        }
        $lvl = $defs[$code][1] ?? 'info';
        $by[$lvl][] = $defs[$code][2] ?? $code;
    }
    $html = '';
    $names = ['error' => ['badge-red', 'problem'], 'warn' => ['badge-gold', 'warning'], 'info' => ['badge-gray', 'hint']];
    foreach ($by as $lvl => $labels) {
        if ($labels) {
            $html .= '<span class="badge ' . $names[$lvl][0] . '" title="' . e(implode(' · ', $labels)) . '">' . count($labels) . ' ' . $names[$lvl][1] . (count($labels) === 1 ? '' : 's')
                . '<span class="sr-only">: ' . e(implode(', ', $labels)) . '</span></span> ';
        }
    }
    return $html !== '' ? $html : '<span class="badge badge-green">OK</span>';
}

/** A stored value as readable text for the history and the import preview ('0' is a value, not empty). */
function seo_page_value(string $field, $v, int $len = 80): string
{
    $v = (string) ($v ?? '');
    if ($v === '') {
        return '(empty)';
    }
    $words = ['robots_index' => ['1' => 'index', '0' => 'noindex'], 'robots_follow' => ['1' => 'follow', '0' => 'nofollow'],
        'sitemap_include' => ['1' => 'include', '0' => 'leave out'], 'faq_markup' => ['1' => 'on', '0' => 'off']];
    if (isset($words[$field][$v])) {
        return $words[$field][$v];
    }
    if (in_array($field, ['author_member_id', 'reviewer_member_id'], true)) {
        $team = seo_admin_team_options();
        return $team[(int) $v] ?? 'team member #' . (int) $v;
    }
    if ($field === 'sources') {
        $v = str_replace("\n", '; ', seo_admin_sources_lines($v)) ?: $v;
        $v = str_replace("\n", '; ', $v);
    }
    return seo_admin_short($v, $len);
}

function seo_page_icon_status(bool $ok, string $yes, string $no): string
{
    return '<span class="seo-flag ' . ($ok ? 'is-ok' : 'is-off') . '">' . icon($ok ? 'check-circle' : 'x') . e($ok ? $yes : $no) . '</span>';
}

function seo_page_select(string $name, string $id, array $options, string $value, bool $disabled = false, string $describedBy = ''): string
{
    $html = '<select id="' . e($id) . '" name="' . e($name) . '"' . ($disabled ? ' disabled' : '') . ($describedBy !== '' ? ' aria-describedby="' . e($describedBy) . '"' : '') . '>';
    foreach ($options as $k => $label) {
        $html .= '<option value="' . e((string) $k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select>';
}

$tablesMissing = [];
foreach (['seo_changes' => 'change log', 'seo_link_checks' => 'external link results'] as $t => $label) {
    if (!seo_admin_table_ready($t)) {
        $tablesMissing[] = $label;
    }
}

$GLOBALS['adminExtraScripts'][] = asset('assets/js/modules/admin-seo.js');
admin_page_start('SEO');
?>
<div class="site-admin seo-admin">
<?php if (setting('seo_noindex_site') === '1'): ?>
    <div class="alert alert-error"><?= icon('alert-triangle') ?><div><strong>The whole website is hidden from search engines.</strong> Turn this off in <a href="<?= e(admin_url('settings.php?tab=seo')) ?>">Settings &gt; SEO &amp; analytics</a> when the site is ready.</div></div>
<?php endif; ?>
<?php if (!seo_admin_core_installed()): ?>
    <div class="alert alert-info"><?= icon('info') ?><div><strong>The SEO core update is not installed yet.</strong> Titles, descriptions, canonical addresses and noindex of pages already work. The other fields (keywords, social overrides, robots and sitemap options for articles, events, campaigns, stories and schemes) <?= seo_admin_table_ready('seo_meta') ? 'are saved and' : 'can be edited once its database table exists and' ?> take effect on the website when the update is installed. The inventory shows the rules of that update.</div></div>
<?php endif; ?>
<?php if ($tablesMissing): ?>
    <div class="alert alert-warning"><?= icon('alert-circle') ?><div>Database update pending for the <?= e(implode(' and ', $tablesMissing)) ?>. It runs automatically after this module is added to the website's start-up file. Until then changes are recorded in the activity log.</div></div>
<?php endif; ?>

<nav class="admin-tabs seo-tabs" aria-label="SEO sections">
    <?php foreach ($tabs as $key => [$label, $ic]): ?>
        <a href="<?= e($seoUrl(['tab' => $key])) ?>" class="<?= $tab === $key || ($tab === 'edit' && $key === 'pages') ? 'active' : '' ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php
// =====================================================================
// OVERVIEW
// =====================================================================
if ($tab === 'overview'):
    $counts = seo_issue_counts();
    $defs = seo_admin_check_defs();
    $tot = $counts['totals'];
    $orphanMeta = seo_admin_orphan_meta();
    $groups = ['problems' => [], 'hints' => []];
    foreach ($defs as $code => [$topic, $level, $label]) {
        if ($code === 'noindex') {
            continue;
        }
        $groups[$level === 'info' ? 'hints' : 'problems'][$topic][$code] = [$label, $counts['checks'][$code] ?? 0, $level];
    }
?>
    <p class="help mt-0">Counts of real issues found in the website's own data - there is no "SEO score". Click a number to see the pages. Keyword checks are advice only: nothing is changed automatically.</p>
    <div class="site-audit-cards seo-totals">
        <a class="site-audit-card" href="<?= e($seoUrl(['tab' => 'pages'])) ?>"><strong><?= (int) $tot['urls'] ?></strong><small>Public URLs checked</small></a>
        <a class="site-audit-card" href="<?= e($seoUrl(['tab' => 'pages', 'index' => 'yes'])) ?>"><strong><?= (int) $tot['indexable'] ?></strong><small>Indexable (own canonical)</small></a>
        <a class="site-audit-card" href="<?= e($seoUrl(['tab' => 'pages', 'issue' => 'noindex'])) ?>"><strong><?= (int) $tot['noindex'] ?></strong><small>Hidden (noindex)</small></a>
        <a class="site-audit-card" href="<?= e($seoUrl(['tab' => 'pages', 'issue' => 'canonical_other'])) ?>"><strong><?= (int) $tot['canonicalised'] ?></strong><small>Point to another canonical URL</small></a>
        <a class="site-audit-card" href="<?= e($seoUrl(['tab' => 'pages', 'sitemap' => 'yes'])) ?>"><strong><?= (int) $tot['in_sitemap'] ?></strong><small>Listed in the sitemap</small></a>
        <a class="site-audit-card" href="<?= e($seoUrl(['tab' => 'pages', 'keyword' => 'yes'])) ?>"><strong><?= (int) $tot['with_keyword'] ?></strong><small>With a focus keyword</small></a>
    </div>
    <p class="help">By type: <?php $parts = []; foreach ($tot['by_type'] as $t => $n) { $parts[] = '<a href="' . e($seoUrl(['tab' => 'pages', 'type' => $t])) . '">' . e(SEO_ADMIN_TYPES[$t] ?? $t) . ' ' . (int) $n . '</a>'; } echo implode(' · ', $parts); ?></p>

    <?php foreach (['problems' => ['Problems to fix', 'alert-triangle'], 'hints' => ['Suggestions', 'info']] as $gk => [$gTitle, $gIcon]): ?>
    <section class="panel" aria-labelledby="ov-<?= e($gk) ?>">
        <div class="panel-head"><h2 id="ov-<?= e($gk) ?>"><?= icon($gIcon) ?><?= e($gTitle) ?></h2></div>
        <?php foreach ($groups[$gk] as $topic => $list): ?>
            <h3 class="site-sub"><?= e($topic) ?></h3>
            <div class="site-audit-cards seo-issue-cards">
                <?php foreach ($list as $code => [$label, $n, $level]): ?>
                    <a class="site-audit-card <?= $n ? ($level === 'info' ? 'info' : 'warn') : 'ok' ?>" href="<?= e($seoUrl(['tab' => in_array($code, ['link_external'], true) ? 'links' : 'pages', 'issue' => $code])) ?>">
                        <strong><?= (int) $n ?></strong><small><?= e($label) ?></small></a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>

    <?php if ($orphanMeta): ?>
    <section class="panel">
        <div class="panel-head"><h2><?= icon('trash') ?>SEO settings of deleted pages</h2></div>
        <p class="mt-0"><?= count($orphanMeta) ?> saved SEO settings belong to pages or items that were deleted. They are not used anywhere.</p>
        <form method="post" data-confirm="Remove the SEO settings of <?= count($orphanMeta) ?> deleted pages or items?">
            <?= csrf_field() ?><input type="hidden" name="action" value="prune_meta"><input type="hidden" name="tab" value="overview">
            <button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash') ?>Remove them</button>
        </form>
    </section>
    <?php endif; ?>

<?php
// =====================================================================
// PAGES & CONTENT (inventory)
// =====================================================================
elseif ($tab === 'pages'):
    $defs = seo_admin_check_defs();
    $filters = [
        'type' => isset(SEO_ADMIN_TYPES[get_str('type')]) ? get_str('type') : '',
        'issue' => isset($defs[get_str('issue')]) ? get_str('issue') : '',
        'level' => in_array(get_str('level'), ['error', 'warn', 'info'], true) ? get_str('level') : '',
        'index' => in_array(get_str('index'), ['yes', 'no'], true) ? get_str('index') : '',
        'sitemap' => in_array(get_str('sitemap'), ['yes', 'no'], true) ? get_str('sitemap') : '',
        'keyword' => in_array(get_str('keyword'), ['yes', 'no'], true) ? get_str('keyword') : '',
        'q' => mb_substr(trim(get_str('q')), 0, 100),
    ];
    $list = seo_url_inventory($filters);
    $pg = paginate(count($list), 50);
    $rows = array_slice($list, $pg['offset'], $pg['per_page']);
    $active = array_filter($filters, 'strlen');
?>
    <form class="filter-row seo-filters" method="get" role="search" aria-label="Filter the URL list">
        <input type="hidden" name="tab" value="pages">
        <label class="sr-only" for="f-q">Search title or address</label>
        <input type="search" id="f-q" name="q" value="<?= e($filters['q']) ?>" placeholder="Search title, address or keyword">
        <label class="sr-only" for="f-type">Type</label>
        <?= seo_page_select('type', 'f-type', ['' => 'All types'] + SEO_ADMIN_TYPES, $filters['type']) ?>
        <label class="sr-only" for="f-issue">Check</label>
        <select id="f-issue" name="issue"><option value="">All checks</option>
            <?php $lastTopic = ''; foreach ($defs as $code => [$topic, $lvl, $label]): ?>
                <?php if ($topic !== $lastTopic): ?><?= $lastTopic !== '' ? '</optgroup>' : '' ?><optgroup label="<?= e($topic) ?>"><?php $lastTopic = $topic; endif; ?>
                <option value="<?= e($code) ?>"<?= $filters['issue'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?></optgroup>
        </select>
        <label class="sr-only" for="f-level">Severity</label>
        <?= seo_page_select('level', 'f-level', ['' => 'Any severity', 'error' => 'With problems', 'warn' => 'With warnings', 'info' => 'With hints'], $filters['level']) ?>
        <label class="sr-only" for="f-index">Indexing</label>
        <?= seo_page_select('index', 'f-index', ['' => 'Indexable or not', 'yes' => 'Indexable', 'no' => 'Noindex / canonicalised'], $filters['index']) ?>
        <label class="sr-only" for="f-sitemap">Sitemap</label>
        <?= seo_page_select('sitemap', 'f-sitemap', ['' => 'In sitemap or not', 'yes' => 'In the sitemap', 'no' => 'Not in the sitemap'], $filters['sitemap']) ?>
        <label class="sr-only" for="f-keyword">Focus keyword</label>
        <?= seo_page_select('keyword', 'f-keyword', ['' => 'Keyword set or not', 'yes' => 'With focus keyword', 'no' => 'Without focus keyword'], $filters['keyword']) ?>
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('filter') ?>Filter</button>
        <?php if ($active): ?><a class="btn btn-ghost btn-sm" href="<?= e($seoUrl(['tab' => 'pages'])) ?>">Reset</a><?php endif; ?>
    </form>
    <div class="seo-toolbar">
        <p class="help m-0"><strong><?= count($list) ?></strong> URL<?= count($list) === 1 ? '' : 's' ?><?= $filters['issue'] !== '' ? ' with "' . e($defs[$filters['issue']][2]) . '"' : '' ?>. Lengths: printed title at least <?= SEO_ADMIN_TITLE_MIN ?> (title itself up to <?= seo_admin_title_max() ?>), description <?= SEO_ADMIN_DESC_MIN ?>-<?= SEO_ADMIN_DESC_MAX ?> characters.</p>
        <?php if ($canExport): ?><a class="btn btn-ghost btn-sm" href="<?= e($seoUrl(['export' => 'inventory'])) ?>"><?= icon('download') ?>Export CSV</a><?php endif; ?>
    </div>
    <?php if (!$rows): ?>
        <p class="site-ok-line"><?= icon('check-circle') ?>No URL matches these filters.</p>
    <?php else: ?>
    <div class="table-wrap seo-table-wrap" tabindex="0" role="region" aria-label="Public URLs">
        <table class="table seo-inventory">
            <thead><tr><th scope="col">Page / URL</th><th scope="col">Search title</th><th scope="col">Description</th><th scope="col">H1</th><th scope="col">Indexing</th><th scope="col">Focus keyword</th><th scope="col">Checks</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $it): ?>
                <tr>
                    <td class="seo-cell-url"><strong><?= e(seo_admin_short($it['title'], 70)) ?></strong>
                        <small><span class="badge badge-gray"><?= e(SEO_ADMIN_TYPES[$it['type']]) ?></span> <a href="<?= e($it['url']) ?>" target="_blank" rel="noopener"><?= e(seo_admin_short($it['path'], 60)) ?></a></small></td>
                    <td><?= seo_page_title_len($it) ?> <span class="seo-clip"><?= e(seo_admin_short($it['full_title'], 90)) ?></span>
                        <?= $it['own_title'] === '' ? '<small class="muted">default</small>' : '' ?></td>
                    <td><?= seo_page_len(mb_strlen($it['description']), SEO_ADMIN_DESC_MIN, SEO_ADMIN_DESC_MAX) ?>
                        <small class="muted"><?= e(['own' => 'own', 'fallback' => 'from summary', 'default' => 'site default', 'none' => 'none'][$it['desc_source']] ?? '') ?></small></td>
                    <td><span class="seo-clip"><?= e(seo_admin_short($it['h1'], 60)) ?></span></td>
                    <td><?= seo_page_icon_status($it['index'], $it['follow'] ? 'index' : 'index, nofollow', $it['follow'] ? 'noindex' : 'noindex, nofollow') ?>
                        <?= !$it['canonical_self'] ? '<small>canonical → <a href="' . e($it['canonical']) . '" target="_blank" rel="noopener">' . e(seo_admin_short((string) parse_url($it['canonical'], PHP_URL_PATH), 40)) . '</a></small>' : '' ?>
                        <small><?= !empty($it['in_sitemap']) ? 'in sitemap' : 'not in sitemap' ?></small></td>
                    <td><?= $it['focus'] !== '' ? e(seo_admin_short($it['focus'], 40)) : '<span class="muted">-</span>' ?></td>
                    <td><?= seo_page_issue_badges($it['issues']) ?></td>
                    <td class="actions-cell"><a class="btn btn-ghost btn-sm" href="<?= e($it['edit_url']) ?>"><?= icon('edit') ?>SEO<span class="sr-only"> settings of <?= e($it['title']) ?></span></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($pg) ?>
    <?php endif; ?>

<?php
// =====================================================================
// EDITOR
// =====================================================================
elseif ($tab === 'edit'):
    $type = get_str('type');
    $id = (int) get_str('id');
    $entity = isset(SEO_ADMIN_TYPES[$type]) ? seo_admin_entity($type, $id) : null;
    if (!$entity):
?>
    <div class="panel text-center"><h2>Not found</h2><p class="muted">This page or item does not exist (any more).</p><a class="btn btn-primary" href="<?= e($seoUrl(['tab' => 'pages'])) ?>">Back to the list</a></div>
<?php
    else:
    $ctx = seo_admin_context();
    $item = $ctx['items'][$type . ':' . $id] ?? null;
    $meta = seo_admin_meta_row($type, $id);
    $vals = seo_admin_values($type, $entity, $meta);
    $defs = seo_admin_field_defs();
    $checkDefs = seo_admin_check_defs();
    $val = function (string $f) use ($vals) {
        $o = old($f, null);
        return is_string($o) ? $o : (string) ($vals[$f] ?? '');
    };
    $avail = function (string $f) use ($type) {
        return seo_admin_storage($type, $f) !== null && seo_admin_field_available($type, $f);
    };
    $applies = function (string $f) use ($type) {
        return seo_admin_storage($type, $f) !== null;
    };
    $title = (string) ($entity['title'] ?? '');
    $isHome = $type === 'page' && ($entity['template'] ?? '') === 'home';
    $defaultTitle = $item ? $item['default_title'] : $title;
    $url = $item['url'] ?? '';
    $fallbackDesc = $item ? $item['fallback_description'] : '';
    $metaMissingNote = '<span class="help seo-na">Needs the SEO core update (seo_meta table) before it can be saved.</span>';
    $fieldNote = function (string $f) use ($avail, $metaMissingNote) {
        return $avail($f) ? '' : $metaMissingNote;
    };
    $dis = function (string $f) use ($avail) {
        return $avail($f) ? '' : ' disabled';
    };
    $defaultImage = setting('seo_default_og_image') !== '' ? setting('seo_default_og_image') : 'assets/images/og-default.jpg';
    [$recent] = seo_admin_changes(['type' => $type, 'id' => $id], 10);
?>
    <div class="seo-edit-head">
        <div>
            <p class="m-0"><span class="badge badge-gray"><?= e(SEO_ADMIN_TYPES[$type]) ?></span> <strong><?= e($title) ?></strong></p>
            <?php if ($url !== ''): ?><p class="m-0 small"><a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($url) ?></a></p>
            <?php else: ?><p class="m-0 small muted">Not public at the moment (draft, scheduled or hidden): checks run once it is published.</p><?php endif; ?>
        </div>
        <div class="seo-edit-actions">
            <a class="btn btn-ghost btn-sm" href="<?= e(seo_admin_module_url($type, $id)) ?>"><?= icon('edit') ?>Edit content</a>
            <a class="btn btn-ghost btn-sm" href="<?= e($seoUrl(['tab' => 'pages'])) ?>"><?= icon('list') ?>All URLs</a>
        </div>
    </div>

    <div class="seo-edit-grid">
    <form class="panel seo-edit-form" method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_entity"><input type="hidden" name="tab" value="edit">
        <input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>">

        <fieldset class="seo-fieldset"><legend><?= icon('search') ?>Search result</legend>
            <div class="form-group"><label for="meta_title">Search title</label>
                <input id="meta_title" name="meta_title" maxlength="200" value="<?= e($val('meta_title')) ?>" placeholder="<?= e($defaultTitle) ?>"
                       data-seo-count="<?= SEO_ADMIN_TITLE_MIN ?>,<?= seo_admin_title_max() ?>" data-pv="title"<?= $dis('meta_title') ?> aria-describedby="meta_title-help">
                <span class="help" id="meta_title-help">Leave blank to use "<?= e(seo_admin_short($defaultTitle, 80)) ?>". Aim for <?= SEO_ADMIN_TITLE_MIN ?>-<?= seo_admin_title_max() ?> characters, written for people.</span>
                <?= field_error('meta_title') ?><?= $fieldNote('meta_title') ?></div>
            <div class="form-group"><label for="meta_description">Search description</label>
                <textarea id="meta_description" name="meta_description" rows="3" maxlength="300" data-seo-count="<?= SEO_ADMIN_DESC_MIN ?>,<?= SEO_ADMIN_DESC_MAX ?>" data-pv="desc"
                          placeholder="<?= e(seo_admin_short($fallbackDesc, 160)) ?>"<?= $dis('meta_description') ?> aria-describedby="meta_description-help"><?= e($val('meta_description')) ?></textarea>
                <span class="help" id="meta_description-help">One or two sentences (<?= SEO_ADMIN_DESC_MIN ?>-<?= SEO_ADMIN_DESC_MAX ?> characters) about what the page offers.<?= $fallbackDesc !== '' ? ' Blank uses the text shown as placeholder.' : '' ?></span>
                <?= field_error('meta_description') ?><?= $fieldNote('meta_description') ?></div>
            <div class="form-grid">
                <div class="form-group"><label for="focus_keyword">Focus keyword</label>
                    <input id="focus_keyword" name="focus_keyword" maxlength="120" value="<?= e($val('focus_keyword')) ?>" data-kw-input<?= $dis('focus_keyword') ?> aria-describedby="focus_keyword-help">
                    <span class="help" id="focus_keyword-help">The main search phrase this page should answer. Used only for the hints on this page; it is not printed on the website.</span>
                    <?= field_error('focus_keyword') ?><?= $fieldNote('focus_keyword') ?></div>
                <div class="form-group"><label for="secondary_keywords">Secondary keywords</label>
                    <input id="secondary_keywords" name="secondary_keywords" maxlength="500" value="<?= e($val('secondary_keywords')) ?>"<?= $dis('secondary_keywords') ?> aria-describedby="secondary_keywords-help">
                    <span class="help" id="secondary_keywords-help">Up to 10, separated by commas.</span>
                    <?= field_error('secondary_keywords') ?><?= $fieldNote('secondary_keywords') ?></div>
            </div>
            <ul class="seo-kw-hints" data-kw-hints data-h1="<?= e($item['h1'] ?? $title) ?>" data-path="<?= e($item['path'] ?? '') ?>" aria-live="polite" hidden>
                <li data-kw-check="title">In the search title</li>
                <li data-kw-check="h1">In the main heading (H1)</li>
                <li data-kw-check="desc">In the search description</li>
                <li data-kw-check="url">Words in the address (advice only)</li>
            </ul>
        </fieldset>

        <fieldset class="seo-fieldset"><legend><?= icon('globe') ?>Indexing</legend>
            <div class="form-grid">
                <div class="form-group"><label for="robots_index">Search engines</label>
                    <?= seo_page_select('robots_index', 'robots_index', $type === 'page' ? ['1' => 'May show this page (index)', '0' => 'Hide this page (noindex)'] : ['' => 'Default (index when public)', '1' => 'May show this page (index)', '0' => 'Hide this page (noindex)'], $val('robots_index'), !$avail('robots_index')) ?>
                    <?= field_error('robots_index') ?><?= $fieldNote('robots_index') ?></div>
                <div class="form-group"><label for="robots_follow">Links on this page</label>
                    <?= seo_page_select('robots_follow', 'robots_follow', ['' => 'Default (follow)', '1' => 'Follow', '0' => 'Do not follow (nofollow)'], $val('robots_follow'), !$avail('robots_follow'), 'robots_follow-help') ?>
                    <span class="help" id="robots_follow-help">Keep the default: "nofollow" stops search engines from discovering the pages this page links to.</span>
                    <?= field_error('robots_follow') ?><?= $fieldNote('robots_follow') ?></div>
                <div class="form-group full"><label for="canonical_url">Canonical address (advanced)</label>
                    <input id="canonical_url" name="canonical_url" maxlength="255" value="<?= e($val('canonical_url')) ?>" placeholder="<?= e($url !== '' ? $url : '/page-address/') ?>"<?= $dis('canonical_url') ?> aria-describedby="canonical_url-help">
                    <span class="help" id="canonical_url-help">Leave blank: the page's own address is used. Only set another address when this page is a copy of that page.<?= $isAdmin ? '' : ' Only administrators can point it to another website.' ?></span>
                    <?= field_error('canonical_url') ?><?= $fieldNote('canonical_url') ?></div>
                <div class="form-group"><label for="sitemap_include">Sitemap</label>
                    <?= seo_page_select('sitemap_include', 'sitemap_include', $type === 'page' ? ['1' => 'List in the sitemap', '0' => 'Leave out of the sitemap'] : ['' => 'Default (listed when public and indexable)', '1' => 'List in the sitemap', '0' => 'Leave out of the sitemap'], $val('sitemap_include'), !$avail('sitemap_include')) ?>
                    <?= field_error('sitemap_include') ?><?= $fieldNote('sitemap_include') ?></div>
                <div class="form-group"><label for="sitemap_priority">Sitemap priority</label>
                    <?php $prio = ['' => 'Not set (recommended)']; for ($p = 10; $p >= 0; $p--) { $prio[number_format($p / 10, 1, '.', '')] = number_format($p / 10, 1, '.', ''); } ?>
                    <?= seo_page_select('sitemap_priority', 'sitemap_priority', $prio, $val('sitemap_priority'), !$avail('sitemap_priority'), 'sitemap_priority-help') ?>
                    <span class="help" id="sitemap_priority-help">Google ignores it; Bing may read it.</span>
                    <?= field_error('sitemap_priority') ?><?= $fieldNote('sitemap_priority') ?></div>
            </div>
        </fieldset>

        <fieldset class="seo-fieldset"><legend><?= icon('share') ?>Social sharing</legend>
            <div class="form-group"><label for="og_title">Social title</label>
                <input id="og_title" name="og_title" maxlength="200" value="<?= e($val('og_title')) ?>" placeholder="Same as the search title" data-pv="og_title"<?= $dis('og_title') ?>>
                <?= field_error('og_title') ?><?= $fieldNote('og_title') ?></div>
            <div class="form-group"><label for="og_description">Social description</label>
                <textarea id="og_description" name="og_description" rows="2" maxlength="300" placeholder="Same as the search description" data-pv="og_desc"<?= $dis('og_description') ?>><?= e($val('og_description')) ?></textarea>
                <?= field_error('og_description') ?><?= $fieldNote('og_description') ?></div>
            <div class="form-group"><span class="label">Social image</span>
                <?php if ($avail('og_image')): ?>
                    <?= site_image_input('og_image', 'og_image', $val('og_image'), 'og_image_upload') ?>
                    <span class="help">1200 × 630 px JPG or PNG. Blank: <?= $type === 'page' ? 'the banner photo, else the default image' : 'the item\'s own photo, else the default image' ?>. SVG images are not shown by social networks.</span>
                <?php else: ?>
                    <input id="og_image" value="<?= e($val('og_image')) ?>" disabled aria-label="Social image"><?= $metaMissingNote ?>
                <?php endif; ?>
                <?= field_error('og_image') ?></div>
            <div class="form-group"><label for="og_image_alt">Social image description</label>
                <input id="og_image_alt" name="og_image_alt" maxlength="255" value="<?= e($val('og_image_alt')) ?>"<?= $dis('og_image_alt') ?> aria-describedby="og_image_alt-help">
                <span class="help" id="og_image_alt-help">What the image shows, e.g. "Volunteers teaching children at a community learning circle".</span>
                <?= field_error('og_image_alt') ?><?= $fieldNote('og_image_alt') ?></div>
        </fieldset>

        <fieldset class="seo-fieldset"><legend><?= icon('layers') ?>Structured data</legend>
            <div class="form-grid">
                <div class="form-group"><label for="schema_type">Type</label>
                    <?= seo_page_select('schema_type', 'schema_type', ['' => 'Automatic (recommended)'] + array_combine(SEO_ADMIN_SCHEMA_TYPES[$type], SEO_ADMIN_SCHEMA_TYPES[$type]), $val('schema_type'), !$avail('schema_type'), 'schema_type-help') ?>
                    <span class="help" id="schema_type-help">Only types that fit this kind of page are offered. Ratings, reviews, courses and job posts are never added.</span>
                    <?= field_error('schema_type') ?><?= $fieldNote('schema_type') ?></div>
                <?php if ($applies('about_name')): ?>
                <div class="form-group"><label for="about_name">Main topic (optional)</label>
                    <input id="about_name" name="about_name" maxlength="120" value="<?= e($val('about_name')) ?>"<?= $dis('about_name') ?>>
                    <?= field_error('about_name') ?><?= $fieldNote('about_name') ?></div>
                <div class="form-group full site-check"><input type="hidden" name="faq_markup" value="0"<?= $dis('faq_markup') ?>>
                    <label class="checkbox" for="faq_markup"><input type="checkbox" id="faq_markup" name="faq_markup" value="1"<?= $val('faq_markup') === '1' ? ' checked' : '' ?><?= $dis('faq_markup') ?>><span>Mark up the questions on this page as FAQ (only when they appear on no other page)</span></label>
                    <?= $fieldNote('faq_markup') ?></div>
                <?php endif; ?>
            </div>
        </fieldset>

        <fieldset class="seo-fieldset"><legend><?= icon('user') ?>Author &amp; review</legend>
            <?php $team = seo_admin_team_options(); ?>
            <?php if (!$team): ?><p class="help mt-0">No published team members yet. Add real people in <a href="<?= e(admin_url('team.php')) ?>">Team</a> to name authors and reviewers; nobody is invented.</p><?php endif; ?>
            <div class="form-grid">
                <div class="form-group"><label for="author_member_id">Author</label>
                    <?= seo_page_select('author_member_id', 'author_member_id', ['' => 'The foundation (default)'] + $team, $val('author_member_id'), !$avail('author_member_id')) ?>
                    <?= field_error('author_member_id') ?><?= $fieldNote('author_member_id') ?></div>
                <div class="form-group"><label for="reviewer_member_id">Reviewed by</label>
                    <?= seo_page_select('reviewer_member_id', 'reviewer_member_id', ['' => 'Not reviewed'] + $team, $val('reviewer_member_id'), !$avail('reviewer_member_id')) ?>
                    <?= field_error('reviewer_member_id') ?><?= $fieldNote('reviewer_member_id') ?></div>
                <div class="form-group"><label for="reviewed_at">Reviewed on</label>
                    <input type="date" id="reviewed_at" name="reviewed_at" value="<?= e($val('reviewed_at')) ?>" max="<?= e(date('Y-m-d')) ?>"<?= $dis('reviewed_at') ?>>
                    <?= field_error('reviewed_at') ?><?= $fieldNote('reviewed_at') ?></div>
                <div class="form-group full"><label for="sources">Sources</label>
                    <?php $srcVal = old('sources', null); ?>
                    <textarea id="sources" name="sources" rows="3" placeholder="Title | https://…"<?= $dis('sources') ?> aria-describedby="sources-help"><?= e(is_string($srcVal) ? $srcVal : seo_admin_sources_lines($vals['sources'] ?? '')) ?></textarea>
                    <span class="help" id="sources-help">One per line: title | https:// address (official sources only, up to 10).</span>
                    <?= field_error('sources') ?><?= $fieldNote('sources') ?></div>
            </div>
        </fieldset>

        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save SEO settings</button></div>
    </form>

    <aside class="seo-edit-side">
        <?php
        $suffix = setting('seo_title_suffix', ' | ' . site_name());
        ?>
        <section class="panel seo-preview" aria-labelledby="pv-head"
                 data-seo-preview data-default-title="<?= e($defaultTitle) ?>" data-default-desc="<?= e($fallbackDesc) ?>"
                 data-suffix="<?= e($suffix) ?>" data-site="<?= e(site_name()) ?>" data-limit="<?= function_exists('seo_finalize') ? seo_admin_title_max() : 70 ?>"
                 data-home="<?= $isHome ? '1' : '0' ?>" data-verbatim="<?= function_exists('seo_finalize') ? '1' : '0' ?>"
                 data-tagline="<?= e(setting('site_tagline_en', 'Together, let us build a better tomorrow')) ?>"
                 data-base="<?= e(url('')) ?>" data-default-image="<?= e(url($defaultImage)) ?>" data-item-image="<?= e(($item && $item['image'] !== '' && !preg_match('/\.svg$/i', $item['image'])) ? url($item['image']) : '') ?>">
            <div class="panel-head"><h2 id="pv-head"><?= icon('eye') ?>Preview</h2><span class="muted small">Approximate - search engines may show other text</span></div>
            <div class="seo-serp">
                <span class="seo-serp-url"><?= e($url !== '' ? preg_replace('#^https?://#', '', rtrim($url, '/')) : site_name()) ?></span>
                <span class="seo-serp-title" data-pv-title><?= e($item['full_title'] ?? $title) ?></span>
                <span class="seo-serp-desc" data-pv-desc><?= e(seo_admin_short($item['description'] ?? '', 165)) ?></span>
            </div>
            <div class="seo-card">
                <img src="<?= e(($item && $item['image'] !== '' && !preg_match('/\.svg$/i', $item['image'])) ? url($item['image']) : url($defaultImage)) ?>" alt="" width="600" height="315" data-pv-img>
                <div><small><?= e((string) parse_url(BASE_URL, PHP_URL_HOST)) ?></small><strong data-pv-og-title><?= e($vals['og_title'] !== '' ? $vals['og_title'] : ($item['seo_title'] ?? $title)) ?></strong>
                    <span data-pv-og-desc><?= e(seo_admin_short($vals['og_description'] !== '' ? $vals['og_description'] : ($item['description'] ?? ''), 120)) ?></span></div>
            </div>
        </section>

        <section class="panel" aria-labelledby="chk-head">
            <div class="panel-head"><h2 id="chk-head"><?= icon('check-circle') ?>Checks for this URL</h2></div>
            <?php if (!$item): ?>
                <p class="muted mt-0">Checks run for public pages only.</p>
            <?php else:
                $byLevel = ['error' => [], 'warn' => [], 'info' => []];
                foreach ($item['issues'] as $code => $detail) {
                    $byLevel[$checkDefs[$code][1] ?? 'info'][$code] = $detail;
                }
                if (!array_filter($byLevel)): ?>
                    <p class="site-ok-line"><?= icon('check-circle') ?>No issues found.</p>
                <?php endif;
                foreach (['error' => 'Problems', 'warn' => 'Warnings', 'info' => 'Hints'] as $lvl => $lvlLabel):
                    if (!$byLevel[$lvl]) { continue; } ?>
                    <h3 class="site-sub"><?= e($lvlLabel) ?></h3>
                    <ul class="health-list seo-checks">
                        <?php foreach ($byLevel[$lvl] as $code => $detail): ?>
                            <li class="<?= $lvl === 'error' ? 'bad' : ($lvl === 'warn' ? 'warn' : 'ok') ?>"><?= icon($lvl === 'info' ? 'info' : 'alert-triangle') ?><span><strong><?= e($checkDefs[$code][2] ?? $code) ?></strong><?= $detail !== '' ? '<small>' . e($detail) . '</small>' : '' ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
                <p class="help">Words in the page's own text: <?= (int) ($item['words'] ?? 0) ?>. Keyword hints are advice; addresses are never changed automatically.</p>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="hist-head">
            <div class="panel-head"><h2 id="hist-head"><?= icon('clock') ?>Recent changes</h2><a class="small" href="<?= e($seoUrl(['tab' => 'log', 'type' => $type, 'id' => $id])) ?>">All</a></div>
            <?php if (!$recent): ?><p class="muted mt-0">No SEO changes recorded yet.</p><?php else: ?>
                <ul class="seo-history">
                    <?php foreach ($recent as $r): ?>
                        <li><small><?= e(format_date((string) $r['created_at'], 'd M Y, H:i')) ?> · <?= e($r['user_name'] ?? 'System') ?></small>
                            <strong><?= e($defs[$r['field']][0] ?? humanize((string) $r['field'])) ?></strong>
                            <span class="seo-diff"><del><?= e(seo_page_value((string) $r['field'], $r['old_value'])) ?></del> → <ins><?= e(seo_page_value((string) $r['field'], $r['new_value'])) ?></ins></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </aside>
    </div>
    <?= site_media_picker() ?>
    <script src="<?= e(asset('assets/js/modules/admin-site.js')) ?>" defer></script>
<?php
    endif;

// =====================================================================
// LINKS
// =====================================================================
elseif ($tab === 'links'):
    $ctx = seo_admin_context();
    $scan = $ctx['scan'];
    $byStatus = ['broken' => [], 'draft' => [], 'redirect' => []];
    foreach ($scan['links'] as $l) {
        if ($l['status'] === 'broken') {
            $byStatus['broken'][] = $l;
        } elseif ($l['status'] === 'draft') {
            $byStatus['draft'][] = $l;
        }
        if ($l['hops'] > 0) {
            $byStatus['redirect'][] = $l;
        }
    }
    $orphans = seo_orphans();
    $ext = $ctx['external'];
    $extUrls = $scan['external'];
    $extErrors = array_filter($ext, function ($r) use ($extUrls) {
        return isset($extUrls[$r['url']]) && seo_admin_external_is_error($r);
    });
    $extChecked = count(array_intersect_key($ext, $extUrls));
    $linkTable = function (array $list, bool $showFinal) {
        if (!$list) {
            return '<p class="site-ok-line">' . icon('check-circle') . 'Nothing found.</p>';
        }
        $h = '<div class="table-wrap seo-table-wrap" tabindex="0" role="region" aria-label="Links"><table class="table"><thead><tr><th scope="col">Where</th><th scope="col">Link</th>'
            . ($showFinal ? '<th scope="col">Ends at</th>' : '<th scope="col">Status</th>') . '<th scope="col"><span class="sr-only">Fix</span></th></tr></thead><tbody>';
        foreach (array_slice($list, 0, 300) as $l) {
            $h .= '<tr><td><strong>' . e(seo_admin_short($l['label'], 70)) . '</strong><small>' . e($l['where']) . '</small></td><td><code>' . e(seo_admin_short($l['href'], 80)) . '</code></td><td>';
            if ($showFinal) {
                $h .= ($l['final_status'] === 'external' ? e(seo_admin_short((string) $l['final'], 60)) : '<code>/' . e(trim((string) $l['final'], '/')) . ($l['final'] !== '' ? '/' : '') . '</code>')
                    . ' <small>' . (int) $l['hops'] . ' redirect' . ($l['hops'] === 1 ? '' : 's') . ($l['final_status'] !== 'ok' && $l['final_status'] !== 'external' ? ' · ' . e($l['final_status']) : '') . '</small>';
            } else {
                $h .= e(['broken' => $l['final_status'] === 'loop' ? 'redirect loop' : 'page not found', 'draft' => 'not published'][$l['status']] ?? $l['status']);
            }
            $h .= '</td><td class="actions-cell"><a class="btn btn-ghost btn-sm" href="' . e($l['edit']) . '">' . icon('edit') . 'Fix</a></td></tr>';
        }
        return $h . '</tbody></table></div>' . (count($list) > 300 ? '<p class="help">Showing the first 300.</p>' : '');
    };
?>
    <p class="help mt-0">Links are read from the website's own content (page sections, banner buttons, article, story, event, campaign and scheme texts, menus and the header button) and checked against pages, items and redirects. No page is downloaded for this.</p>
    <div class="site-audit-cards">
        <a class="site-audit-card <?= $byStatus['broken'] ? 'warn' : 'ok' ?>" href="#broken"><strong><?= count($byStatus['broken']) ?></strong><small>Broken internal links</small></a>
        <a class="site-audit-card <?= $byStatus['draft'] ? 'warn' : 'ok' ?>" href="#drafts"><strong><?= count($byStatus['draft']) ?></strong><small>Links to unpublished content</small></a>
        <a class="site-audit-card <?= $byStatus['redirect'] ? 'info' : 'ok' ?>" href="#redirected"><strong><?= count($byStatus['redirect']) ?></strong><small>Links through redirects</small></a>
        <a class="site-audit-card <?= $orphans ? 'warn' : 'ok' ?>" href="#orphans"><strong><?= count($orphans) ?></strong><small>Orphan URLs</small></a>
        <a class="site-audit-card <?= $extErrors ? 'warn' : 'ok' ?>" href="#external"><strong><?= count($extErrors) ?></strong><small>External link errors (<?= (int) $extChecked ?> of <?= count($extUrls) ?> checked)</small></a>
    </div>

    <section class="panel" id="broken"><div class="panel-head"><h2><?= icon('alert-triangle') ?>Broken internal links</h2></div><?= $linkTable($byStatus['broken'], false) ?></section>
    <section class="panel" id="drafts"><div class="panel-head"><h2><?= icon('eye') ?>Links to unpublished content</h2></div><?= $linkTable($byStatus['draft'], false) ?></section>
    <section class="panel" id="redirected"><div class="panel-head"><h2><?= icon('refresh') ?>Links through redirects</h2><span class="muted small">Point them straight to the final address</span></div><?= $linkTable($byStatus['redirect'], true) ?></section>

    <section class="panel" id="orphans">
        <div class="panel-head"><h2><?= icon('link') ?>Orphan URLs</h2></div>
        <p class="help mt-0">Indexable pages that no menu, page text or button links to. Articles, stories, events, campaigns, schemes, programmes and categories count as linked when their list page is published; the sitemaps do not count. Add a link from a related page or a menu.</p>
        <?php if (!$orphans): ?><p class="site-ok-line"><?= icon('check-circle') ?>Every indexable page has at least one internal link.</p><?php else: ?>
            <ul class="site-audit-list">
                <?php foreach ($orphans as $o): ?>
                    <li><span><strong><?= e($o['title']) ?></strong><small><?= e(SEO_ADMIN_TYPES[$o['type']]) ?> · <a href="<?= e($o['url']) ?>" target="_blank" rel="noopener"><?= e($o['path']) ?></a></small></span>
                        <a class="btn btn-ghost btn-sm" href="<?= e($o['edit_url']) ?>"><?= icon('edit') ?>SEO</a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel" id="external">
        <div class="panel-head"><h2><?= icon('external') ?>External links</h2><span class="muted small"><?= count($extUrls) ?> found in the content</span></div>
        <p class="help mt-0">Checked only when you click the button: <?= SEO_ADMIN_EXT_BATCH ?> links per click (never-checked first), each with a <?= SEO_ADMIN_EXT_TIMEOUT ?>-second time limit. Some websites refuse automated checks (codes 401, 403, 429): open those in a browser before changing them.</p>
        <?php if (seo_admin_table_ready('seo_link_checks') && $extUrls): ?>
        <form method="post" class="mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="ext_check"><input type="hidden" name="tab" value="links">
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('refresh') ?>Check <?= min(SEO_ADMIN_EXT_BATCH, count($extUrls)) ?> external links</button></form>
        <?php elseif (!seo_admin_table_ready('seo_link_checks')): ?><p class="muted">Available after the database update.</p><?php endif; ?>
        <?php if ($extUrls): ?>
        <div class="table-wrap seo-table-wrap" tabindex="0" role="region" aria-label="External links"><table class="table">
            <thead><tr><th scope="col">Address</th><th scope="col">Result</th><th scope="col">Found on</th><th scope="col">Checked</th></tr></thead><tbody>
            <?php
            $extSorted = array_keys($extUrls);
            usort($extSorted, function ($a, $b) use ($ext) {
                $ea = isset($ext[$a]) && seo_admin_external_is_error($ext[$a]);
                $eb = isset($ext[$b]) && seo_admin_external_is_error($ext[$b]);
                return [$eb, $a] <=> [$ea, $b];
            });
            foreach (array_slice($extSorted, 0, 300) as $u):
                $r = $ext[$u] ?? null; ?>
                <tr><td><a href="<?= e($u) ?>" target="_blank" rel="noopener nofollow"><?= e(seo_admin_short($u, 70)) ?></a></td>
                    <td><?php if (!$r): ?><span class="muted">not checked</span><?php else:
                        $bad = seo_admin_external_is_error($r); ?>
                        <span class="badge <?= $bad ? 'badge-red' : ((int) $r['status_code'] >= 200 && (int) $r['status_code'] < 400 ? 'badge-green' : 'badge-gray') ?>"><?= $r['status_code'] ? (int) $r['status_code'] : '—' ?></span>
                        <?= $r['error'] ? '<small>' . e($r['error']) . '</small>' : '' ?>
                        <?= $r['final_url'] ? '<small>→ ' . e(seo_admin_short((string) $r['final_url'], 60)) . '</small>' : '' ?>
                    <?php endif; ?></td>
                    <td><small><?= e(implode(', ', array_map(function ($l) { return seo_admin_short($l, 40); }, array_slice(array_values($extUrls[$u]), 0, 3)))) ?></small></td>
                    <td><small><?= $r ? e(format_date((string) $r['checked_at'], 'd M Y, H:i')) : '' ?></small></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>

<?php
// =====================================================================
// REDIRECTS
// =====================================================================
elseif ($tab === 'redirects'):
    $report = seo_admin_redirect_report();
    $problems = array_filter($report, function ($r) {
        return $r['problem'] !== '' || $r['shadows'];
    });
    $labels = ['loop' => 'Redirect loop', 'chain' => 'Chain of redirects', 'target_missing' => 'Ends at a page that does not exist', 'target_draft' => 'Ends at an unpublished page'];
?>
    <div class="seo-toolbar">
        <p class="help m-0">Redirects send old addresses to new ones. Each should reach a live page in one step. Changing a page address in the page editor creates a redirect automatically.</p>
        <?php if (admin_can('redirects.manage')): ?><a class="btn btn-primary btn-sm" href="<?= e(admin_url('redirects.php')) ?>"><?= icon('refresh') ?>Manage redirects &amp; 404 log</a><?php endif; ?>
    </div>
    <section class="panel">
        <div class="panel-head"><h2><?= icon('alert-triangle') ?>Problems</h2><span class="muted small"><?= count($report) ?> redirects checked</span></div>
        <?php if (!$problems): ?><p class="site-ok-line"><?= icon('check-circle') ?>No loops, chains or redirects to missing pages.</p><?php else: ?>
        <div class="table-wrap seo-table-wrap" tabindex="0" role="region" aria-label="Redirect problems"><table class="table">
            <thead><tr><th scope="col">Old address</th><th scope="col">Problem</th><th scope="col">Path</th></tr></thead><tbody>
            <?php foreach ($problems as $r): ?>
                <tr><td><code>/<?= e($r['source_path']) ?></code></td>
                    <td><?= $r['problem'] !== '' ? '<span class="badge ' . ($r['problem'] === 'chain' ? 'badge-gold' : 'badge-red') . '">' . e($labels[$r['problem']] ?? $r['problem']) . '</span> ' : '' ?>
                        <?= $r['shadows'] ? '<span class="badge badge-gold">Hides a live page</span><small>Visitors to this address are redirected although a page with this address exists.</small>' : '' ?></td>
                    <td><small><?= e(implode(' → ', array_map(function ($p) { return '/' . trim((string) $p, '/') . ($p !== '' ? '/' : ''); }, $r['chain']))) ?><?= $r['final_status'] === 'external' ? ' → ' . e(seo_admin_short((string) $r['final'], 60)) : '' ?></small></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
    <section class="panel">
        <div class="panel-head"><h2><?= icon('list') ?>All redirects</h2></div>
        <?php if (!$report): ?><p class="muted mt-0">No redirects yet.</p><?php else: ?>
        <div class="table-wrap seo-table-wrap" tabindex="0" role="region" aria-label="All redirects"><table class="table">
            <thead><tr><th scope="col">Old address</th><th scope="col">Target</th><th scope="col">Type</th><th scope="col">Visits</th><th scope="col">Result</th></tr></thead><tbody>
            <?php foreach (array_slice($report, 0, 500) as $r): ?>
                <tr><td><code>/<?= e($r['source_path']) ?></code></td><td><code><?= e(seo_admin_short((string) $r['target_url'], 70)) ?></code></td>
                    <td><?= (int) $r['status_code'] === 302 ? '302 temporary' : '301' ?></td><td><?= (int) $r['hits'] ?></td>
                    <td><?php if ($r['problem'] !== ''): ?><span class="badge badge-red"><?= e($labels[$r['problem']] ?? $r['problem']) ?></span><?php elseif ($r['final_status'] === 'external'): ?><span class="badge badge-blue">Other website</span><?php else: ?><span class="badge badge-green">OK</span><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>

<?php
// =====================================================================
// AWARENESS DAYS
// =====================================================================
elseif ($tab === 'awareness'):
    $groups = seo_admin_awareness_groups();
    $locs = seo_admin_sitemap_locs();
    $multi = array_filter($groups, function ($g) {
        return count($g['editions']) > 1;
    });
?>
    <p class="help mt-0">Each observance (for example World Food Day) can have an edition per year with the same text. One edition is the main ("canonical") one: the others point to it and are left out of the sitemap, so search engines show one page instead of near-copies.
        Automatic rule: the next upcoming edition, else the most recent one. Choose an edition only to override it.<?= seo_admin_core_installed() ? '' : ' <strong>The canonical tags are printed once the SEO core update is installed.</strong>' ?></p>
    <?php if (!seo_admin_awareness_enabled()): ?><div class="alert alert-warning"><?= icon('alert-circle') ?><div>Pointing editions to a main edition is switched off in <a href="<?= e($seoUrl(['tab' => 'integrations'])) ?>">Integrations</a>: every edition is its own canonical page.</div></div><?php endif; ?>
    <?php if (!$multi): ?>
        <p class="site-ok-line"><?= icon('check-circle') ?>No observance has more than one edition.</p>
    <?php else: ?>
    <div class="seo-aw-list">
    <?php foreach ($multi as $g): ?>
        <section class="panel seo-aw" id="group-<?= e($g['key']) ?>" aria-labelledby="gh-<?= e($g['key']) ?>">
            <div class="panel-head"><h2 id="gh-<?= e($g['key']) ?>"><?= icon('calendar') ?><?= e($g['title']) ?></h2>
                <span class="small"><?= $g['override_id'] ? '<span class="badge badge-blue">Chosen by staff</span>' : '<span class="badge badge-gray">Automatic</span>' ?>
                <?= $g['same_description'] ? ' <span class="badge badge-gold">Same description in every edition</span>' : '' ?>
                <?= $g['stale_override'] ? ' <span class="badge badge-red">Chosen edition is no longer published - automatic rule used</span>' : '' ?></span></div>
            <div class="table-wrap"><table class="table seo-aw-table"><thead><tr><th scope="col">Edition</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col">Canonical</th><th scope="col">Sitemap</th></tr></thead><tbody>
            <?php foreach ($g['editions'] as $ed): $isCanon = (int) $ed['id'] === (int) $g['effective_id']; ?>
                <tr<?= $isCanon ? ' class="is-canonical"' : '' ?>><td><a href="<?= e($ed['url']) ?>" target="_blank" rel="noopener"><?= e($ed['slug']) ?></a></td>
                    <td><?= e(format_date((string) $ed['start_datetime'])) ?> <small class="muted"><?= $ed['upcoming'] ? 'upcoming' : 'past' ?></small></td>
                    <td><?= e(humanize((string) $ed['status'])) ?></td>
                    <td><?= $isCanon ? '<span class="badge badge-green">' . icon('check') . 'Main edition</span>' : '<small>→ main edition</small>' ?></td>
                    <td><?= isset($locs[seo_admin_norm_url($ed['url'])]) ? 'listed' : '<span class="muted">not listed</span>' ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <form method="post" class="filter-row mt-1">
                <?= csrf_field() ?><input type="hidden" name="action" value="awareness"><input type="hidden" name="tab" value="awareness"><input type="hidden" name="group" value="<?= e($g['key']) ?>">
                <label for="ed-<?= e($g['key']) ?>">Main edition</label>
                <?php $opts = ['0' => 'Automatic (next upcoming, else most recent)']; foreach ($g['editions'] as $ed) { if ($ed['status'] === 'published') { $opts[(string) (int) $ed['id']] = $ed['slug']; } } ?>
                <?= seo_page_select('edition', 'ed-' . $g['key'], $opts, (string) (int) ($g['override_id'] ?? 0)) ?>
                <button class="btn btn-ghost btn-sm" type="submit"><?= icon('check') ?>Save</button>
            </form>
        </section>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php
// =====================================================================
// IMAGES
// =====================================================================
elseif ($tab === 'images'):
    $all = seo_admin_images();
    $show = get_str('show') === 'all' ? 'all' : 'missing';
    $missing = array_filter($all, function ($i) {
        return $i['alt'] === '';
    });
    $list = $show === 'all' ? $all : $missing;
    $pg = paginate(count($list), 30);
    $rows = array_slice($list, $pg['offset'], $pg['per_page'], true);
?>
    <p class="help mt-0">Photos used on public pages. The description (alt text) is stored in the media library: it is read aloud by screen readers and used by search engines. Describe what the photo shows; do not repeat the page title. Sections may also have their own image description in the page editor.</p>
    <div class="seo-toolbar">
        <nav class="filter-row m-0" aria-label="Image filter">
            <a class="btn btn-sm <?= $show === 'missing' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e($seoUrl(['tab' => 'images'])) ?>">Without description (<?= count($missing) ?>)</a>
            <a class="btn btn-sm <?= $show === 'all' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e($seoUrl(['tab' => 'images', 'show' => 'all'])) ?>">All photos (<?= count($all) ?>)</a>
        </nav>
        <?php if (function_exists('seo_image_backfill')): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="image_backfill"><input type="hidden" name="tab" value="images">
                <button class="btn btn-ghost btn-sm" type="submit"><?= icon('refresh') ?>Create WebP copies (next 20)</button></form>
        <?php endif; ?>
    </div>
    <?php if (!$rows): ?><p class="site-ok-line"><?= icon('check-circle') ?><?= $show === 'missing' ? 'Every photo has a description.' : 'No uploaded photos are used on public pages yet.' ?></p><?php else: ?>
    <ul class="seo-images">
        <?php foreach ($rows as $img): $fid = 'alt-' . substr(sha1($img['path']), 0, 10); ?>
            <li class="seo-image">
                <?php if ($img['exists']): ?><img src="<?= e(url($img['path'])) ?>" alt="" width="120" height="80" loading="lazy"><?php else: ?><span class="seo-image-missing"><?= icon('alert-triangle') ?>File missing</span><?php endif; ?>
                <div class="seo-image-body">
                    <code><?= e($img['path']) ?></code>
                    <small>Used on: <?php $u = []; foreach (array_slice($img['uses'], 0, 4, true) as $label => $href) { $u[] = '<a href="' . e($href) . '">' . e(seo_admin_short($label, 50)) . '</a>'; } echo implode(', ', $u) . (count($img['uses']) > 4 ? ' and ' . (count($img['uses']) - 4) . ' more' : ''); ?>
                        <?= $img['webp'] ? ' · WebP copy' : '' ?><?= $img['media'] ? '' : ' · not in the media library yet' ?></small>
                    <form method="post" class="seo-alt-form">
                        <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="save_alt"><input type="hidden" name="tab" value="images"><input type="hidden" name="path" value="<?= e($img['path']) ?>">
                        <label class="sr-only" for="<?= e($fid) ?>">Description of <?= e(basename($img['path'])) ?></label>
                        <input id="<?= e($fid) ?>" name="alt" maxlength="255" value="<?= e($img['alt']) ?>" placeholder="Describe what the photo shows">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('check') ?>Save<span class="sr-only"> description</span></button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?= pagination_links($pg) ?>
    <?php endif; ?>

<?php
// =====================================================================
// SITEMAPS & ROBOTS
// =====================================================================
elseif ($tab === 'sitemaps'):
    $children = seo_admin_sitemap_children();
    $robots = seo_admin_robots_preview();
    $ctx = seo_admin_context();
    $notListed = array_filter($ctx['items'], function ($it) {
        return $it['indexable'] && empty($it['in_sitemap']);
    });
    $wrongListed = array_filter($ctx['items'], function ($it) {
        return !$it['indexable'] && !empty($it['in_sitemap']);
    });
?>
    <div class="admin-grid-2">
    <section class="panel">
        <div class="panel-head"><h2><?= icon('globe') ?>Sitemap</h2><a class="btn btn-ghost btn-sm" href="<?= e(url('sitemap.xml')) ?>" target="_blank" rel="noopener"><?= icon('external') ?>sitemap.xml</a></div>
        <?php if ($children): ?>
            <p class="help mt-0">/sitemap.xml is a sitemap index that lists these files (only those with entries):</p>
            <div class="table-wrap"><table class="table"><thead><tr><th scope="col">File</th><th scope="col">Entries</th><th scope="col">Newest change</th></tr></thead><tbody>
            <?php foreach ($children as $c): ?>
                <tr><td><a href="<?= e($c['url']) ?>" target="_blank" rel="noopener">sitemap-<?= e($c['name']) ?>.xml</a></td><td><?= (int) $c['count'] ?></td><td><?= $c['lastmod'] !== '' ? e(format_date($c['lastmod'])) : '<span class="muted">-</span>' ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else:
            $groupsCount = [];
            try { foreach (sitemap_entries() as $e) { $groupsCount[$e['group'] ?? 'Pages'] = ($groupsCount[$e['group'] ?? 'Pages'] ?? 0) + 1; } } catch (Throwable $ex) { $groupsCount = []; } ?>
            <p class="help mt-0">The current sitemap is one file. The sitemap index with separate files (and images) arrives with the sitemap update.</p>
            <ul class="site-audit-list"><?php foreach ($groupsCount as $g => $n): ?><li><span><strong><?= e($g) ?></strong></span><span><?= (int) $n ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <h3 class="site-sub">Indexable URLs not in the sitemap (<?= count($notListed) ?>)</h3>
        <?php if (!$notListed): ?><p class="site-ok-line"><?= icon('check-circle') ?>None.</p><?php else: ?>
            <ul class="site-audit-list"><?php foreach (array_slice($notListed, 0, 50) as $it): ?><li><span><strong><?= e($it['title']) ?></strong><small><?= e($it['path']) ?></small></span><a class="btn btn-ghost btn-sm" href="<?= e($it['edit_url']) ?>"><?= icon('edit') ?>SEO</a></li><?php endforeach; ?></ul>
            <p class="help">Often deliberate (for example pages left out of the sitemap in the page settings).</p>
        <?php endif; ?>
        <h3 class="site-sub">Noindex or canonicalised URLs in the sitemap (<?= count($wrongListed) ?>)</h3>
        <?php if (!$wrongListed): ?><p class="site-ok-line"><?= icon('check-circle') ?>None.</p><?php else: ?>
            <ul class="site-audit-list"><?php foreach (array_slice($wrongListed, 0, 50) as $it): ?><li><span><strong><?= e($it['title']) ?></strong><small><?= e($it['path']) ?> · <?= $it['index'] ? 'canonical points elsewhere' : 'noindex' ?></small></span><a class="btn btn-ghost btn-sm" href="<?= e($it['edit_url']) ?>"><?= icon('edit') ?>SEO</a></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
    <section class="panel">
        <div class="panel-head"><h2><?= icon('file-text') ?>robots.txt</h2><a class="btn btn-ghost btn-sm" href="<?= e(url('robots.txt')) ?>" target="_blank" rel="noopener"><?= icon('external') ?>robots.txt</a></div>
        <?php if ($robots !== null): ?>
            <pre class="seo-pre" tabindex="0" aria-label="robots.txt preview"><?= e($robots) ?></pre>
        <?php else: ?>
            <p class="help mt-0">The preview appears with the sitemap update. Open the live file with the button above. Extra lines can be added in <a href="<?= e($seoUrl(['tab' => 'integrations'])) ?>">Integrations</a>.</p>
        <?php endif; ?>
        <h3 class="site-sub">Search Console &amp; Bing</h3>
        <ol class="site-steps">
            <li>In <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a> add a <strong>Domain</strong> property (verified with a DNS record at the domain host), or a URL-prefix property for <code><?= e(abs_url('')) ?></code> with the HTML tag code from <a href="<?= e($seoUrl(['tab' => 'integrations'])) ?>">Integrations</a>.</li>
            <li>Open <strong>Sitemaps</strong> and submit <code><?= e(abs_url('sitemap.xml')) ?></code>.</li>
            <li>In <a href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Bing Webmaster Tools</a> choose "Import from Google Search Console", or add the site with the msvalidate.01 code.</li>
            <li>Check the "Pages" report every few weeks and fix missing pages in <a href="<?= e(admin_url('redirects.php?tab=404')) ?>">Redirects &amp; 404s</a>.</li>
        </ol>
    </section>
    </div>

<?php
// =====================================================================
// TRUST CHECKLIST
// =====================================================================
elseif ($tab === 'trust'):
    $list = seo_admin_trust_checklist();
    $done = count(array_filter(array_column($list, 1)));
?>
    <p class="help mt-0">What visitors and search engines can see about who runs the foundation. This list only shows whether information is filled in (<?= (int) $done ?> of <?= count($list) ?>); it says nothing about quality. Enter only real, verified facts and real people.</p>
    <section class="panel">
        <ul class="health-list seo-trust">
            <?php foreach ($list as [$label, $ok, $detail, $href, $linkLabel]): ?>
                <li class="<?= $ok ? 'ok' : 'warn' ?>"><?= icon($ok ? 'check-circle' : 'alert-circle') ?>
                    <span><strong><?= e($label) ?></strong><small><?= e($detail) ?></small></span>
                    <a class="btn btn-ghost btn-sm" href="<?= e($href) ?>"><?= e($linkLabel) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>

<?php
// =====================================================================
// INTEGRATIONS
// =====================================================================
elseif ($tab === 'integrations'):
    $groupsDef = seo_admin_setting_groups();
    $pending = [];
    [$hostOk, $detected] = seo_admin_host_check();
?>
    <?php if (!$canSettings): ?><div class="alert alert-info"><?= icon('lock') ?><div>Only staff with the "settings" permission can change these settings. You can see the current values.</div></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="seo-settings" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save_settings"><input type="hidden" name="tab" value="integrations">
        <?php foreach ($groupsDef as $gk => [$gTitle, $gHelp, $fields]):
            $installed = seo_admin_group_fields($fields);
            foreach (array_diff_key($fields, $installed) as $k => $d) { $pending[] = $d[1]; }
            if (!$installed) { continue; } ?>
            <section class="panel" aria-labelledby="ig-<?= e($gk) ?>">
                <div class="panel-head"><h2 id="ig-<?= e($gk) ?>"><?= e($gTitle) ?></h2></div>
                <?php if ($gHelp !== ''): ?><p class="help mt-0"><?= e($gHelp) ?></p><?php endif; ?>
                <fieldset class="form-grid seo-settings-grid"<?= $canSettings ? '' : ' disabled' ?>><legend class="sr-only"><?= e($gTitle) ?></legend>
                <?php foreach ($installed as $key => $def):
                    $value = old($key, null);
                    $value = is_string($value) ? $value : setting($key);
                    switch ($def[0]) {
                        case 'code':
                        case 'gtm':
                        case 'robotsmeta':
                            echo site_setting_input($key, ['text', $def[1], $def[2] ?? '', ['max' => 255]], $value);
                            break;
                        case 'hostredirect':
                            echo site_setting_input($key, ['checkbox', $def[1], ''], $value);
                            echo '<p class="help full seo-host">Main address: <code>' . e(rtrim(BASE_URL, '/')) . '</code> · this page was opened as <code>' . e($detected) . '</code>'
                                . ($hostOk ? ' - they match.' : ' - they differ, so the redirect cannot be switched on from here (it could send visitors in a loop).') . ' Prefer "Force HTTPS" at the hosting control panel.</p>';
                            break;
                        default:
                            echo site_setting_input($key, $def, $value);
                    }
                    echo field_error($key);
                endforeach; ?>
                </fieldset>
            </section>
        <?php endforeach; ?>
        <?php if ($canSettings): ?><div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save settings</button></div><?php endif; ?>
    </form>
    <?php if (function_exists('seo_tags_status_lines')): $statusLines = (array) seo_tags_status_lines(); if ($statusLines): ?>
        <section class="panel" aria-labelledby="ig-status"><div class="panel-head"><h2 id="ig-status"><?= icon('info') ?>What the website prints now</h2></div>
            <dl class="detail-list seo-status"><?php foreach ($statusLines as $line): $line = array_values((array) $line); ?><dt><?= e((string) ($line[0] ?? '')) ?></dt><dd><?= e((string) ($line[1] ?? '')) ?></dd><?php endforeach; ?></dl></section>
    <?php endif; endif; ?>
    <?php if ($pending): ?><p class="help">Appear after the related SEO updates are installed: <?= e(implode(', ', $pending)) ?>.</p><?php endif; ?>
    <?php if ($canSettings): ?><?= site_media_picker() ?><script src="<?= e(asset('assets/js/modules/admin-site.js')) ?>" defer></script><?php endif; ?>

<?php
// =====================================================================
// IMPORT / EXPORT
// =====================================================================
elseif ($tab === 'bulk'):
    $preview = $_SESSION['seo_csv_preview'] ?? null;
    $defs = seo_admin_field_defs();
?>
    <div class="admin-grid-2">
        <section class="panel">
            <div class="panel-head"><h2><?= icon('download') ?>Export</h2></div>
            <p class="mt-0">A spreadsheet of every public URL with its stored SEO values (columns: <?= e(implode(', ', seo_admin_csv_header())) ?>).</p>
            <p class="mb-0">
                <?php if ($canExport): ?><a class="btn btn-primary btn-sm" href="<?= e($seoUrl(['export' => 'inventory'])) ?>"><?= icon('download') ?>Export all URLs</a><?php else: ?><span class="muted small">Exporting data needs the "exports" permission. </span><?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="<?= e($seoUrl(['export' => 'template'])) ?>"><?= icon('file-text') ?>Empty template</a></p>
        </section>
        <section class="panel">
            <div class="panel-head"><h2><?= icon('upload') ?>Import</h2></div>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="action" value="import_preview"><input type="hidden" name="tab" value="bulk">
                <div class="form-group"><label for="csv_file">CSV file (UTF-8, up to 2 MB)</label><input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required></div>
                <fieldset class="seo-modes"><legend>What to change</legend>
                    <label class="checkbox"><input type="radio" name="mode" value="fill" checked><span>Fill only empty fields (recommended - nothing entered by staff is replaced)</span></label>
                    <label class="checkbox"><input type="radio" name="mode" value="overwrite"><span>Replace existing values with the values in the file</span></label>
                </fieldset>
                <p class="help">Rows are matched by type and id (or by url). Empty cells never clear a value. You see every change before anything is saved.</p>
                <button class="btn btn-primary btn-sm" type="submit"><?= icon('eye') ?>Preview changes</button>
            </form>
        </section>
    </div>
    <?php if (is_array($preview)): ?>
    <section class="panel" id="preview" aria-labelledby="prev-head">
        <div class="panel-head"><h2 id="prev-head"><?= icon('eye') ?>Preview: <?= e($preview['file'] ?? '') ?></h2>
            <span class="muted small"><?= (int) $preview['rows'] ?> rows read · <?= ($preview['mode'] ?? 'fill') === 'overwrite' ? 'replace values' : 'fill empty fields only' ?></span></div>
        <?php if (!empty($preview['ignored_columns'])): ?><p class="help mt-0">Ignored columns: <?= e(implode(', ', $preview['ignored_columns'])) ?>.</p><?php endif; ?>
        <?php if (!$preview['changes']): ?>
            <p class="mt-0">No changes to make.</p>
        <?php else: ?>
            <div class="table-wrap seo-table-wrap" tabindex="0" role="region" aria-label="Changes to apply"><table class="table">
                <thead><tr><th scope="col">Row</th><th scope="col">Page / item</th><th scope="col">Field</th><th scope="col">Now</th><th scope="col">New</th></tr></thead><tbody>
                <?php foreach (array_slice($preview['changes'], 0, 500) as $c): ?>
                    <tr><td><?= (int) $c['line'] ?></td><td><strong><?= e(seo_admin_short((string) $c['title'], 60)) ?></strong><small><?= e(SEO_ADMIN_TYPES[$c['type']] ?? $c['type']) ?> · <?= e($c['path']) ?></small></td>
                        <td><?= e($defs[$c['field']][0] ?? $c['field']) ?></td>
                        <td><span class="seo-diff"><del><?= e(seo_page_value((string) $c['field'], $c['old'], 120)) ?></del></span></td>
                        <td><span class="seo-diff"><ins><?= e(seo_page_value((string) $c['field'], $c['new'], 160)) ?></ins></span></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php if (count($preview['changes']) > 500): ?><p class="help">Showing 500 of <?= count($preview['changes']) ?> changes; all are applied.</p><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($preview['skipped'])): ?>
            <details class="site-json mt-2"><summary><?= icon('info') ?>Skipped (<?= count($preview['skipped']) ?>)</summary>
                <ul class="seo-skipped"><?php foreach (array_slice($preview['skipped'], 0, 300) as [$line, $key, $field, $why]): ?>
                    <li>Row <?= (int) $line ?><?= $key !== '' ? ' · ' . e($key) : '' ?><?= $field !== '' ? ' · ' . e($defs[$field][0] ?? $field) : '' ?>: <?= e($why) ?></li>
                <?php endforeach; ?></ul></details>
        <?php endif; ?>
        <div class="seo-toolbar mt-2">
            <?php if ($preview['changes']): ?>
            <form method="post" data-confirm="Apply <?= count($preview['changes']) ?> changes?"><?= csrf_field() ?><input type="hidden" name="action" value="import_apply"><input type="hidden" name="tab" value="bulk"><input type="hidden" name="token" value="<?= e($preview['token']) ?>">
                <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Apply <?= count($preview['changes']) ?> change<?= count($preview['changes']) === 1 ? '' : 's' ?></button></form>
            <?php endif; ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="import_cancel"><input type="hidden" name="tab" value="bulk"><button class="btn btn-ghost btn-sm" type="submit"><?= icon('x') ?>Cancel</button></form>
        </div>
    </section>
    <?php endif; ?>

<?php
// =====================================================================
// CHANGE LOG
// =====================================================================
elseif ($tab === 'log'):
    $lf = ['type' => mb_substr(get_str('type'), 0, 30), 'id' => (int) get_str('id'), 'field' => mb_substr(get_str('field'), 0, 40), 'user' => (int) get_str('user'),
        'from' => get_str('from'), 'to' => get_str('to')];
    $total = seo_admin_table_ready('seo_changes') ? (int) seo_admin_changes($lf, 1)[1] : 0;
    $pg = paginate($total, 50);
    [$rows] = seo_admin_changes($lf, $pg['per_page'], $pg['offset']);
    $defs = seo_admin_field_defs();
    $users = [];
    $types = [];
    $fieldsUsed = [];
    if (seo_admin_table_ready('seo_changes')) {
        foreach (db_rows('SELECT DISTINCT c.changed_by, u.name FROM seo_changes c JOIN users u ON u.id = c.changed_by ORDER BY u.name') as $u) {
            $users[(string) (int) $u['changed_by']] = $u['name'];
        }
        $types = array_column(db_rows('SELECT DISTINCT entity_type FROM seo_changes ORDER BY entity_type'), 'entity_type');
        $fieldsUsed = array_column(db_rows('SELECT DISTINCT field FROM seo_changes ORDER BY field'), 'field');
    }
    $typeLabels = SEO_ADMIN_TYPES + ['setting' => 'Setting', 'media' => 'Image', 'awareness_group' => 'Awareness day'];
?>
    <?php if (!seo_admin_table_ready('seo_changes')): ?>
        <p class="muted">The change log starts after the database update. Until then changes are listed in <a href="<?= e(admin_url('activity-logs.php')) ?>">Activity logs</a>.</p>
    <?php else: ?>
    <form class="filter-row" method="get" aria-label="Filter the change log">
        <input type="hidden" name="tab" value="log"><?php if ($lf['id']): ?><input type="hidden" name="id" value="<?= (int) $lf['id'] ?>"><?php endif; ?>
        <label class="sr-only" for="l-type">Type</label>
        <select id="l-type" name="type"><option value="">All types</option><?php foreach ($types as $t): ?><option value="<?= e($t) ?>"<?= $lf['type'] === $t ? ' selected' : '' ?>><?= e($typeLabels[$t] ?? $t) ?></option><?php endforeach; ?></select>
        <label class="sr-only" for="l-field">Field</label>
        <select id="l-field" name="field"><option value="">All fields</option><?php foreach ($fieldsUsed as $f): ?><option value="<?= e($f) ?>"<?= $lf['field'] === $f ? ' selected' : '' ?>><?= e($defs[$f][0] ?? $f) ?></option><?php endforeach; ?></select>
        <label class="sr-only" for="l-user">Staff member</label>
        <?= seo_page_select('user', 'l-user', ['' => 'All staff'] + $users, $lf['user'] ? (string) $lf['user'] : '') ?>
        <label class="sr-only" for="l-from">From</label><input type="date" id="l-from" name="from" value="<?= e(preg_match('/^\d{4}-\d{2}-\d{2}$/', $lf['from']) ? $lf['from'] : '') ?>" title="From">
        <label class="sr-only" for="l-to">To</label><input type="date" id="l-to" name="to" value="<?= e(preg_match('/^\d{4}-\d{2}-\d{2}$/', $lf['to']) ? $lf['to'] : '') ?>" title="To">
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('filter') ?>Filter</button>
        <?php if (array_filter($lf)): ?><a class="btn btn-ghost btn-sm" href="<?= e($seoUrl(['tab' => 'log'])) ?>">Reset</a><?php endif; ?>
        <?php if ($canExport): ?><a class="btn btn-ghost btn-sm" href="<?= e($seoUrl(['export' => 'log'] + array_filter(['type' => $lf['type'], 'field' => $lf['field'], 'user' => $lf['user'] ?: '', 'from' => $lf['from'], 'to' => $lf['to']], 'strlen'))) ?>"><?= icon('download') ?>CSV</a><?php endif; ?>
    </form>
    <?php if (!$rows): ?><p class="muted">No changes recorded<?= array_filter($lf) ? ' for these filters' : ' yet' ?>.</p><?php else: ?>
    <div class="table-wrap seo-table-wrap" tabindex="0" role="region" aria-label="SEO changes"><table class="table seo-log">
        <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What</th><th scope="col">Field</th><th scope="col">Old → new</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td><small><?= e(format_date((string) $r['created_at'], 'd M Y, H:i')) ?></small></td><td><?= e($r['user_name'] ?? 'System') ?></td>
                <td><?php if (isset(SEO_ADMIN_TYPES[$r['entity_type']])): ?><a href="<?= e(seo_entity_edit_url((string) $r['entity_type'], (int) $r['entity_id'])) ?>"><?= e(SEO_ADMIN_TYPES[$r['entity_type']]) ?> #<?= (int) $r['entity_id'] ?></a>
                    <?php else: ?><?= e($typeLabels[$r['entity_type']] ?? $r['entity_type']) ?><?= (int) $r['entity_id'] ? ' #' . (int) $r['entity_id'] : '' ?><?php endif; ?></td>
                <td><?= e($defs[$r['field']][0] ?? $r['field']) ?></td>
                <td><span class="seo-diff"><del><?= e(seo_page_value((string) $r['field'], $r['old_value'], 160)) ?></del> → <ins><?= e(seo_page_value((string) $r['field'], $r['new_value'], 160)) ?></ins></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?= pagination_links($pg) ?>
    <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php
admin_page_end();
