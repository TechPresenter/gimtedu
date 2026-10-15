<?php
/**
 * Audit log: staff actions, sign-ins and security events from `activity_logs`.
 * Filters: scope (staff / security / all), action, user, date range. Details are scrubbed so tokens or
 * passwords are never displayed even if they were ever written to a log entry by mistake.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('logs.view');

$range = admin_date_range('30d');
[$from, $to] = insights_bounds($range);
$scope = in_array($_GET['scope'] ?? '', ['staff', 'security', 'all'], true) ? (string) $_GET['scope'] : 'staff';
$action = preg_match('/^[a-z0-9_.\-]{1,80}$/i', (string) ($_GET['action'] ?? '')) ? (string) $_GET['action'] : '';
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 100));
$securityActions = ['login_failed', 'totp_failed', 'access_denied', 'password_changed', 'totp_enabled', 'totp_disabled', 'admin_login',
    'security_signout_everywhere', 'security_remember_revoked', 'security_password_change_required', 'login', 'logout'];

$where = 'WHERE l.created_at BETWEEN ? AND ?';
$params = [$from, $to];
if ($scope === 'staff') {
    $where .= ' AND l.is_staff = 1';
} elseif ($scope === 'security') {
    $where .= ' AND l.action IN (' . implode(',', array_fill(0, count($securityActions), '?')) . ')';
    $params = array_merge($params, $securityActions);
}
if ($action !== '') {
    $where .= ' AND l.action = ?';
    $params[] = $action;
}
if ($q !== '') {
    $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR l.ip_address LIKE ? OR l.details LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', $q . '%', '%' . $q . '%');
}
$fromSql = "FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id $where";

if (isset($_GET['export'])) {
    require_permission('exports.run');
    admin_log('report_exported', 'Audit log ' . $range['from'] . ' to ' . $range['to'] . ' (' . $scope . ($action ? ', ' . $action : '') . ')');
    $rows = db_rows("SELECT l.created_at, COALESCE(u.name, ''), COALESCE(u.email, ''), IF(l.is_staff, 'Staff', 'Visitor/member'), l.action, l.details, l.ip_address, l.user_agent
                     $fromSql ORDER BY l.id DESC LIMIT 50000", $params);
    send_csv('audit-log-' . $range['from'] . '-to-' . $range['to'] . '.csv', ['Time', 'User', 'Email', 'Type', 'Action', 'Details', 'IP address', 'Browser'],
        array_map(function ($r) {
            $r = array_values($r);
            $r[5] = insights_scrub($r[5]);
            return $r;
        }, $rows));
}

$p = paginate((int) db_value("SELECT COUNT(*) $fromSql", $params), 50);
$rows = db_rows("SELECT l.*, u.name AS user_name, u.email AS user_email $fromSql ORDER BY l.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$actions = array_column(db_rows('SELECT DISTINCT action FROM activity_logs ORDER BY action LIMIT 300'), 'action');
$hidden = function (array $skip): string {
    $html = '';
    foreach ($_GET as $k => $v) {
        if (is_string($v) && !in_array($k, $skip, true)) {
            $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
    }
    return $html;
};

admin_page_start('Audit Log');
?>
<div class="dash-welcome">
    <div><h2>Audit log</h2><p class="muted small mb-0"><?= e($range['label']) ?> · <?= number_format($p['total']) ?> entr<?= $p['total'] === 1 ? 'y' : 'ies' ?></p></div>
    <?= admin_range_picker($range) ?>
</div>

<form class="filter-row" method="get">
    <?= $hidden(['scope', 'action', 'q', 'page', 'export']) ?>
    <label class="sr-only" for="log-scope">Show</label>
    <select id="log-scope" name="scope" data-autosubmit>
        <?= options_html(['staff' => 'Staff actions', 'security' => 'Sign-ins & security events', 'all' => 'All activity (incl. visitors & forms)'], $scope, true, '') ?>
    </select>
    <label class="sr-only" for="log-action">Action</label>
    <select id="log-action" name="action">
        <option value="">All actions</option>
        <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= $a === $action ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
    </select>
    <label class="sr-only" for="log-q">Search</label>
    <input id="log-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email, IP or details">
    <button class="btn btn-primary btn-sm" type="submit"><?= icon('search') ?>Filter</button>
    <?php if ($action !== '' || $q !== '' || $scope !== 'staff'): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('activity-logs.php')) ?>">Reset</a><?php endif; ?>
    <?php if (admin_can('exports.run') && $p['total']): ?><a class="btn btn-ghost btn-sm" href="?<?= e(http_build_query(array_merge(array_diff_key($_GET, ['page' => 1]), ['export' => 1]))) ?>"><?= icon('download') ?>CSV</a><?php endif; ?>
</form>

<section class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table log-table">
            <thead><tr><th>Time</th><th>Who</th><th>Action</th><th>Details</th><th>IP address</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $details = insights_scrub($r['details']);
                $isRisk = in_array($r['action'], ['login_failed', 'totp_failed', 'access_denied'], true); ?>
                <tr<?= $isRisk ? ' class="log-risk"' : '' ?>>
                    <td class="nowrap"><?= e(format_date($r['created_at'], 'd M Y')) ?><br><small class="muted"><?= e(format_date($r['created_at'], 'h:i:s A')) ?></small></td>
                    <td><?php if ($r['user_name']): ?><?= $r['is_staff'] ? '<span class="badge badge-blue">Staff</span> ' : '' ?><strong><?= e($r['user_name']) ?></strong><br><small class="muted"><?= e($r['user_email']) ?></small><?php else: ?><span class="muted">Visitor</span><?php endif; ?></td>
                    <td><code><?= e($r['action']) ?></code></td>
                    <td class="log-details">
                        <?php if (mb_strlen($details) > 90 || $r['user_agent']): ?>
                            <details><summary><?= e(excerpt($details, 90) ?: 'Details') ?></summary><div class="log-more"><?= $details !== '' ? '<p>' . e($details) . '</p>' : '' ?><?php if ($r['user_agent']): ?><small class="muted"><?= icon('monitor') ?><?= e($r['user_agent']) ?></small><?php endif; ?></div></details>
                        <?php else: ?><?= e($details) ?><?php endif; ?>
                    </td>
                    <td class="nowrap"><code><?= e((string) $r['ip_address']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <p class="muted mb-0">No log entries match these filters.</p>
    <?php endif; ?>
</section>
<p class="muted small mt-2"><?= icon('info') ?> Entries are kept for <?= (int) retention_days()['logs'] ?> days (change this in <a href="<?= e(admin_url('backups.php')) ?>">Backup &amp; Data Retention</a>). Passwords, tokens and two-step secrets are never written to the log.</p>
<?php
admin_page_end();
