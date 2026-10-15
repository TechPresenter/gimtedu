<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('enquiries');

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['email', 'name'], $q, $params);
if (in_array($status, ['subscribed', 'unsubscribed'], true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_rows("SELECT email, name, status, created_at, CONCAT(?, unsubscribe_token) FROM newsletter_subscribers $where ORDER BY created_at DESC",
        array_merge([abs_url('process-contact.php?unsubscribe=')], $params));
    admin_log('newsletter_exported', count($rows) . ' rows');
    send_csv('newsletter-' . date('Ymd') . '.csv', ['Email', 'Name', 'Status', 'Subscribed on', 'Unsubscribe link'], $rows);
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM newsletter_subscribers $where", $params), 50);
$rows = db_rows("SELECT * FROM newsletter_subscribers $where ORDER BY created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$active = (int) db_value("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'subscribed'");

$adminTitle = 'Newsletter Subscribers';
require __DIR__ . '/includes/header.php';
admin_filter_bar(['subscribed', 'unsubscribed'], ['placeholder' => 'Search email or name']);
?>
<div class="page-actions">
    <span class="muted small"><?= number_format($active) ?> active subscriber(s). Export the list to send newsletters from your email tool; include each person's unsubscribe link.</span>
    <a class="btn btn-ghost btn-sm" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv', 'page' => null]))) ?>"><?= icon('download') ?>Export CSV</a>
</div>
<div class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Subscribed</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['email']) ?></td>
                    <td><?= e($r['name'] ?: '-') ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                    <td class="actions-cell">
                        <form class="inline-form" method="post" action="actions/application-update.php">
                            <?= csrf_field() ?><?= return_field() ?>
                            <input type="hidden" name="type" value="newsletter_subscribers"><input type="hidden" name="action" value="status_toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="status" value="<?= $r['status'] === 'subscribed' ? 'unsubscribed' : 'subscribed' ?>">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= $r['status'] === 'subscribed' ? 'Unsubscribe' : 'Resubscribe' ?></button>
                        </form>
                        <form class="inline-form" method="post" action="actions/application-update.php" data-confirm="Remove this subscriber permanently?">
                            <?= csrf_field() ?><?= return_field() ?>
                            <input type="hidden" name="type" value="newsletter_subscribers"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="icon-btn danger" type="submit" aria-label="Delete"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No subscribers yet.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
