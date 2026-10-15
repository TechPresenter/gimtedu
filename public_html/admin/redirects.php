<?php
/**
 * Redirects (old address -> new address, 301/302) and the log of missing pages (404) with one-click fixes.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('redirects.manage');

$tab = ($_GET['tab'] ?? '') === '404' ? '404' : 'redirects';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('redirects.php' . ($tab === '404' ? '?tab=404' : ''));
    $action = (string) ($_POST['action'] ?? '');
    $back = admin_back('redirects.php');

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id ? db_row('SELECT * FROM redirects WHERE id = ?', [$id]) : null;
        $errors = [];
        $source = site_normalize_source((string) ($_POST['source_path'] ?? ''));
        if ($source === null) {
            $errors['source_path'] = 'Enter the old address of this website, e.g. old-page or about-us.php (no domain needed).';
        } else {
            $first = strtolower(explode('/', $source)[0]);
            if (in_array($first, ['admin', 'assets', 'includes', 'templates', 'api', 'sitemap.xml', 'robots.txt'], true)) {
                $errors['source_path'] = 'This address is used by the website itself and cannot be redirected.';
            } elseif (db_value('SELECT COUNT(*) FROM pages WHERE slug = ?', [strtolower($source)])) {
                $errors['source_path'] = 'A page uses this address - a redirect would hide that page. Change the page address instead (it creates a redirect automatically).';
            } elseif (db_value('SELECT COUNT(*) FROM redirects WHERE source_path = ? AND id <> ?', [$source, $id])) {
                $errors['source_path'] = 'A redirect for this address already exists - edit that one.';
            }
        }
        [$target, $err] = site_clean_redirect_target((string) ($_POST['target_url'] ?? ''));
        if ($err) {
            $errors['target_url'] = $err;
        } elseif ($source !== null && trim((string) parse_url($target, PHP_URL_PATH), '/') === $source && $target[0] === '/') {
            $errors['target_url'] = 'The target is the same as the old address (that would loop).';
        }
        $code = (int) ($_POST['status_code'] ?? 301) === 302 ? 302 : 301;
        if ($errors) {
            back_with_errors($errors, 'admin/redirects.php' . ($id ? '?edit=' . $id : '') . ($tab === '404' ? ($id ? '&' : '?') . 'tab=404' : ''), $_POST);
        }
        if ($existing) {
            db_update('redirects', ['source_path' => $source, 'target_url' => $target, 'status_code' => $code], 'id = :id', ['id' => $id]);
            admin_log('redirect_updated', 'Redirect #' . $id . ': /' . $source . ' -> ' . $target . ' (' . $code . ')');
        } else {
            $id = db_insert('redirects', ['source_path' => $source, 'target_url' => $target, 'status_code' => $code]);
            admin_log('redirect_added', 'Redirect #' . $id . ': /' . $source . ' -> ' . $target . ' (' . $code . ')');
        }
        $fixed = db_query('DELETE FROM not_found_log WHERE path = ?', [$source])->rowCount();
        $warn = '';
        if ($target[0] === '/') {
            $st = site_link_status($target);
            if ($st === 'missing') {
                $warn = ' Note: no page exists at ' . $target . ' yet.';
            } elseif ($st === 'draft') {
                $warn = ' Note: the page at ' . $target . ' is not published yet.';
            } elseif ($st === 'redirect') {
                $warn = ' Note: ' . $target . ' is itself redirected - point straight to the final address to avoid a chain.';
            }
        }
        flash($warn ? 'warning' : 'success', 'Redirect saved: /' . $source . ' → ' . $target . ($fixed ? ' (removed from the missing pages log)' : '') . '.' . $warn);
        admin_redirect($tab === '404' ? 'redirects.php?tab=404' : 'redirects.php');
    }

    if ($action === 'delete') {
        $row = db_row('SELECT * FROM redirects WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if ($row) {
            db_query('DELETE FROM redirects WHERE id = ?', [$row['id']]);
            admin_log('redirect_deleted', 'Redirect #' . $row['id'] . ' deleted: /' . $row['source_path'] . ' -> ' . $row['target_url']);
            flash('success', 'Redirect deleted.');
        }
        admin_redirect($back);
    }

    if ($action === 'clear_404') {
        $row = db_row('SELECT * FROM not_found_log WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if ($row) {
            db_query('DELETE FROM not_found_log WHERE id = ?', [$row['id']]);
            admin_log('404_cleared', 'Missing page entry cleared: /' . $row['path']);
            flash('success', 'Entry cleared.');
        }
        admin_redirect($back);
    }
    if ($action === 'clear_all_404') {
        $n = db_query('DELETE FROM not_found_log')->rowCount();
        admin_log('404_cleared', 'Missing pages log cleared (' . $n . ' entries)');
        flash('success', 'Missing pages log cleared (' . $n . ' entries).');
        admin_redirect('redirects.php?tab=404');
    }
    admin_redirect($back);
}

$q = trim((string) ($_GET['q'] ?? ''));
admin_page_start('Redirects & 404s');
$n404 = (int) db_value('SELECT COUNT(*) FROM not_found_log');
?>
<div class="site-admin">
<nav class="admin-tabs" aria-label="Redirects">
    <a href="<?= e(admin_url('redirects.php')) ?>" class="<?= $tab === 'redirects' ? 'active' : '' ?>"<?= $tab === 'redirects' ? ' aria-current="page"' : '' ?>><?= icon('refresh') ?> Redirects</a>
    <a href="<?= e(admin_url('redirects.php?tab=404')) ?>" class="<?= $tab === '404' ? 'active' : '' ?>"<?= $tab === '404' ? ' aria-current="page"' : '' ?>><?= icon('alert-triangle') ?> Missing pages (404)<?= $n404 ? ' · ' . $n404 : '' ?></a>
</nav>

<?php if ($tab === 'redirects'):
    $editId = (int) ($_GET['edit'] ?? 0);
    $edit = $editId ? db_row('SELECT * FROM redirects WHERE id = ?', [$editId]) : null;
    $f = $edit ?: ['id' => 0, 'source_path' => (string) ($_GET['source'] ?? ''), 'target_url' => '', 'status_code' => 301];
    $where = 'WHERE 1=1';
    $params = [];
    $where .= search_where(['source_path', 'target_url'], $q, $params);
    $total = (int) db_value("SELECT COUNT(*) FROM redirects $where", $params);
    $pg = paginate($total, 30);
    $rows = db_rows("SELECT * FROM redirects $where ORDER BY hits DESC, id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
?>
<div class="admin-grid-main">
    <section class="panel">
        <div class="panel-head"><h2>Redirects</h2><span class="muted small"><?= $total ?> redirect<?= $total === 1 ? '' : 's' ?></span></div>
        <?php admin_filter_bar([], ['placeholder' => 'Search old or new address']); ?>
        <?php if (!$rows): ?>
            <?= empty_state('No redirects found.') ?>
        <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Old address</th><th>Goes to</th><th>Type</th><th>Hits</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $st = $r['target_url'][0] === '/' ? site_link_status($r['target_url']) : 'external'; ?>
                <tr>
                    <td class="site-title-cell"><code>/<?= e($r['source_path']) ?></code></td>
                    <td class="site-title-cell"><code><?= e($r['target_url']) ?></code>
                        <?php if ($st === 'missing'): ?><span class="site-bad"><?= icon('alert-triangle') ?>No page at this address</span><?php elseif ($st === 'draft'): ?><span class="site-warn"><?= icon('alert-circle') ?>Page not published</span><?php elseif ($st === 'redirect'): ?><span class="site-warn"><?= icon('refresh') ?>Redirects again (chain)</span><?php endif; ?></td>
                    <td><span class="badge <?= (int) $r['status_code'] === 302 ? 'badge-gold' : 'badge-blue' ?>"><?= (int) $r['status_code'] ?></span></td>
                    <td><?= (int) $r['hits'] ?></td>
                    <td><div class="site-actions">
                        <a class="icon-btn" href="<?= e(url('/' . $r['source_path'])) ?>" target="_blank" rel="noopener" aria-label="Test /<?= e($r['source_path']) ?>" title="Test"><?= icon('external') ?></a>
                        <a class="icon-btn" href="<?= e(admin_url('redirects.php?edit=' . $r['id'] . '#redirect-form')) ?>" aria-label="Edit redirect /<?= e($r['source_path']) ?>" title="Edit"><?= icon('edit') ?></a>
                        <form method="post" action="<?= e(admin_url('redirects.php')) ?>" data-confirm="Delete the redirect for /<?= e($r['source_path']) ?>?"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="icon-btn danger" type="submit" aria-label="Delete redirect /<?= e($r['source_path']) ?>" title="Delete"><?= icon('trash') ?></button></form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($pg) ?>
        <?php endif; ?>
    </section>
    <aside>
        <section class="panel" id="redirect-form">
            <div class="panel-head"><h2><?= icon($edit ? 'edit' : 'plus') ?><?= $edit ? 'Edit redirect' : 'Add a redirect' ?></h2><?php if ($edit): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('redirects.php')) ?>">Cancel</a><?php endif; ?></div>
            <form method="post" action="<?= e(admin_url('redirects.php')) ?>" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <div class="form-group full"><label for="source_path">Old address<span class="req">*</span></label>
                    <input id="source_path" name="source_path" required maxlength="255" placeholder="old-page or about-us.php" value="<?= e(old('source_path', $f['source_path'])) ?>">
                    <span class="help">The part after the domain. Slashes at the start/end are removed automatically.</span><?= field_error('source_path') ?></div>
                <div class="form-group full"><label for="target_url">Goes to<span class="req">*</span></label>
                    <input id="target_url" name="target_url" required maxlength="255" list="site-page-urls" autocomplete="off" placeholder="/about/ or https://…" value="<?= e(old('target_url', $f['target_url'])) ?>">
                    <span class="help">A page of this site starting with / or a full https:// address.</span><?= field_error('target_url') ?></div>
                <div class="form-group full"><label for="status_code">Type</label>
                    <select id="status_code" name="status_code"><?= options_html(['301' => '301 - permanent (recommended, keeps search ranking)', '302' => '302 - temporary'], (string) old('status_code', (string) $f['status_code']), true, '') ?></select></div>
                <div class="form-actions full"><button class="btn btn-primary btn-block" type="submit"><?= icon('check') ?>Save redirect</button></div>
            </form>
            <p class="help mt-2 mb-0">When you change a page's address in Pages, a 301 redirect from the old address is added automatically.</p>
        </section>
    </aside>
</div>

<?php else:
    $where = 'WHERE 1=1';
    $params = [];
    $where .= search_where(['path', 'referrer_host'], $q, $params);
    $total = (int) db_value("SELECT COUNT(*) FROM not_found_log $where", $params);
    $pg = paginate($total, 30);
    $rows = db_rows("SELECT * FROM not_found_log $where ORDER BY hits DESC, last_seen DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
?>
<section class="panel">
    <div class="panel-head"><h2>Missing pages people tried to open</h2>
        <?php if ($n404): ?><form method="post" action="<?= e(admin_url('redirects.php?tab=404')) ?>" data-confirm="Clear the whole missing pages log?"><?= csrf_field() ?><input type="hidden" name="action" value="clear_all_404"><button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash') ?>Clear all</button></form><?php endif; ?></div>
    <p class="help mt-0">Addresses that returned "page not found", most visited first. Send useful ones to the right page with a redirect; clear entries that are just bots or typos.</p>
    <?php admin_filter_bar([], ['placeholder' => 'Search address or referring site', 'hidden' => ['tab' => '404']]); ?>
    <?php if (!$rows): ?>
        <?= empty_state('No missing pages recorded. 404 errors will appear here.') ?>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Address</th><th>Hits</th><th>Last seen</th><th>Create a redirect to</th><th class="text-right">Clear</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $src = site_normalize_source($r['path']); ?>
            <tr>
                <td class="site-title-cell"><code>/<?= e($r['path']) ?></code><?php if ($r['referrer_host']): ?><small>from <?= e($r['referrer_host']) ?></small><?php endif; ?></td>
                <td><strong><?= (int) $r['hits'] ?></strong></td>
                <td class="nowrap"><?= e(format_date($r['last_seen'] ?: $r['first_seen'], 'd M Y, H:i')) ?></td>
                <td><?php if ($src !== null): ?>
                    <form method="post" action="<?= e(admin_url('redirects.php?tab=404')) ?>" class="filter-row mb-0">
                        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="source_path" value="<?= e($src) ?>"><input type="hidden" name="status_code" value="301">
                        <label class="sr-only" for="t404-<?= (int) $r['id'] ?>">Target for /<?= e($r['path']) ?></label>
                        <input id="t404-<?= (int) $r['id'] ?>" name="target_url" required list="site-page-urls" autocomplete="off" placeholder="/page/">
                        <button class="btn btn-primary btn-sm" type="submit"><?= icon('refresh') ?>Redirect</button>
                    </form>
                    <?php else: ?><span class="muted small">Address cannot be redirected</span><?php endif; ?></td>
                <td><div class="site-actions"><form method="post" action="<?= e(admin_url('redirects.php?tab=404')) ?>"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="clear_404"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="icon-btn" type="submit" aria-label="Clear /<?= e($r['path']) ?>" title="Clear"><?= icon('x') ?></button></form></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= pagination_links($pg) ?>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>
<?= site_page_datalist() ?>
<script src="<?= e(asset('assets/js/modules/admin-site.js')) ?>" defer></script>
<?php
admin_page_end();
