<?php
/** Feedback submissions: list, rating summary, status workflow (new -> reviewed -> actioned / closed) and notes. */
require_once __DIR__ . '/includes/auth.php';
require_permission('feedback.manage');

$statuses = application_statuses('feedback');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only('feedback.php');
    $id = (int) ($_POST['id'] ?? 0);
    $fb = db_row('SELECT * FROM feedback WHERE id = ?', [$id]);
    if ($fb && ($_POST['action'] ?? '') === 'update') {
        [$data, $errors] = validate($_POST, ['status' => 'required|in:' . implode(',', $statuses), 'note' => 'text|max:3000']);
        if ($errors) {
            back_with_errors($errors, 'admin/feedback.php?id=' . $id, $_POST);
        }
        $notes = (string) $fb['admin_notes'];
        $changes = [];
        if ($data['status'] !== $fb['status']) {
            $changes[] = 'status ' . humanize($fb['status']) . ' -> ' . humanize($data['status']);
        }
        if ($data['note'] !== '') {
            $changes[] = 'note: ' . $data['note'];
        }
        if ($changes) {
            $entry = '[' . date('d M Y, h:i A') . '] ' . (current_admin()['name'] ?? 'Staff') . ': ' . ucfirst(implode('; ', $changes));
            $notes = trim($notes) === '' ? $entry : rtrim($notes) . "\n\n" . $entry;
            db_update('feedback', ['status' => $data['status'], 'admin_notes' => $notes, 'handled_by' => admin_id()], 'id = :id', ['id' => $id]);
            admin_log('feedback_updated', ($fb['reference_no'] ?: 'Feedback #' . $id) . ': ' . ($data['status'] !== $fb['status'] ? $data['status'] : 'note added'));
            flash('success', 'Feedback updated.');
        }
        admin_redirect('feedback.php?id=' . $id);
    }
    admin_redirect('feedback.php');
}

$status = (string) ($_GET['status'] ?? '');
$category = (string) ($_GET['category'] ?? '');
$rating = (int) ($_GET['rating'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = 'WHERE 1=1';
if (in_array($status, $statuses, true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
if (isset(FEEDBACK_CATEGORIES[$category])) {
    $where .= ' AND category = ?';
    $params[] = $category;
}
if ($rating >= 1 && $rating <= 5) {
    $where .= ' AND rating = ?';
    $params[] = $rating;
}
$where .= search_where(['reference_no', 'name', 'email', 'message'], $q, $params);

if (isset($_GET['export'])) {
    require_permission('exports.run');
    $rows = db_rows("SELECT reference_no, category, rating, status, name, email, message, created_at FROM feedback $where ORDER BY created_at DESC", $params);
    admin_log('export_feedback', count($rows) . ' feedback rows exported');
    send_csv('feedback-' . date('Y-m-d') . '.csv', ['Reference', 'Category', 'Rating', 'Status', 'Name', 'Email', 'Feedback', 'Received'], $rows);
}

$view = isset($_GET['id']) ? db_row('SELECT f.*, u.name AS handler_name FROM feedback f LEFT JOIN users u ON u.id = f.handled_by WHERE f.id = ?', [(int) $_GET['id']]) : null;
admin_page_start($view ? 'Feedback ' . ($view['reference_no'] ?: '#' . $view['id']) : 'Feedback',
    $view ? [['label' => 'Back to list', 'url' => admin_url('feedback.php'), 'icon' => 'chevron-left', 'style' => 'ghost']] : []);

$stars = function (?int $n): string {
    if (!$n) {
        return '<span class="muted">No rating</span>';
    }
    return '<span aria-label="' . $n . ' out of 5" title="' . $n . ' out of 5" style="color:#d9a400;letter-spacing:1px">' . str_repeat('★', $n) . '<span style="color:#cfd8d2">' . str_repeat('★', 5 - $n) . '</span></span>';
};

if ($view): ?>
    <div class="admin-grid-main">
        <section class="panel">
            <div class="panel-head"><h2><?= e(FEEDBACK_CATEGORIES[$view['category']] ?? humanize($view['category'])) ?></h2><?= status_badge($view['status']) ?></div>
            <?php details_list([
                'Reference' => $view['reference_no'] ?: '#' . $view['id'],
                'Rating' => [$stars($view['rating'] !== null ? (int) $view['rating'] : null)],
                'From' => $view['name'],
                'Email' => ['<a href="mailto:' . e($view['email']) . '">' . e($view['email']) . '</a>'],
                'Received' => format_date($view['created_at'], 'd M Y, h:i A'),
                'Handled by' => $view['handler_name'] ?? '',
            ]); ?>
            <h3 class="mt-3">Feedback</h3>
            <div class="note-box" style="background:var(--bg-soft);border-color:var(--line)"><?= e($view['message']) ?></div>
        </section>
        <div>
            <section class="panel">
                <div class="panel-head"><h2>Status</h2></div>
                <form method="post" class="form-grid">
                    <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $view['id'] ?>">
                    <div class="form-group full"><label for="status">Status</label>
                        <select id="status" name="status"><?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"<?= $view['status'] === $s ? ' selected' : '' ?>><?= e(humanize($s)) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group full"><label for="note">Internal note (optional)</label><textarea id="note" name="note" rows="4" maxlength="3000"></textarea><?= field_error('note') ?></div>
                    <div class="form-actions full" style="grid-column:1/-1"><button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save</button>
                        <a class="btn btn-ghost btn-sm" href="mailto:<?= e($view['email']) ?>?subject=<?= rawurlencode('Your feedback ' . $view['reference_no']) ?>"><?= icon('mail') ?>Email the sender</a></div>
                </form>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Notes &amp; history</h2></div>
                <?= trim((string) $view['admin_notes']) !== '' ? '<div class="note-box">' . e($view['admin_notes']) . '</div>' : '<p class="muted">No notes yet.</p>' ?>
            </section>
        </div>
    </div>
    <?php
    admin_page_end();
    exit;
endif;

$summary = db_row("SELECT COUNT(*) n, SUM(status = 'new') n_new, AVG(rating) avg_rating, SUM(rating IS NOT NULL) rated FROM feedback");
$dist = [];
foreach ([1, 2, 3, 4, 5] as $i) {
    $dist[$i . ' ★'] = 0;
}
foreach (db_rows('SELECT rating, COUNT(*) n FROM feedback WHERE rating IS NOT NULL GROUP BY rating') as $d) {
    $dist[(int) $d['rating'] . ' ★'] = (int) $d['n'];
}
$total = (int) db_value("SELECT COUNT(*) FROM feedback $where", $params);
$p = paginate($total, 25);
$rows = db_rows("SELECT * FROM feedback $where ORDER BY FIELD(status, 'new', 'reviewed', 'actioned', 'closed'), created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$catOptions = '<select name="category" aria-label="Category"><option value="">All topics</option>';
foreach (FEEDBACK_CATEGORIES as $k => $v) {
    $catOptions .= '<option value="' . e($k) . '"' . ($category === $k ? ' selected' : '') . '>' . e($v) . '</option>';
}
$catOptions .= '</select><select name="rating" aria-label="Rating"><option value="">Any rating</option>';
for ($i = 5; $i >= 1; $i--) {
    $catOptions .= '<option value="' . $i . '"' . ($rating === $i ? ' selected' : '') . '>' . $i . ' star' . ($i > 1 ? 's' : '') . '</option>';
}
$catOptions .= '</select>';
?>
<div class="admin-grid-2">
    <div class="stat-cards" style="grid-template-columns:1fr 1fr;align-content:start">
        <div class="stat-card"><span class="icon-circle"><?= icon('message') ?></span><div><strong><?= (int) $summary['n'] ?></strong><small>Feedback received</small></div></div>
        <div class="stat-card"><span class="icon-circle gold"><?= icon('inbox') ?></span><div><strong><?= (int) $summary['n_new'] ?></strong><small>New</small></div></div>
        <div class="stat-card"><span class="icon-circle gold"><?= icon('star') ?></span><div><strong><?= $summary['rated'] ? number_format((float) $summary['avg_rating'], 1) . ' / 5' : '-' ?></strong><small>Average rating</small></div></div>
        <div class="stat-card"><span class="icon-circle blue"><?= icon('check-circle') ?></span><div><strong><?= (int) $summary['rated'] ?></strong><small>With a rating</small></div></div>
    </div>
    <section class="panel">
        <div class="panel-head"><h2>Rating distribution</h2></div>
        <?= $summary['rated'] ? admin_bar_chart($dist) : '<p class="muted">No ratings yet.</p>' ?>
    </section>
</div>

<section class="panel">
    <?php admin_filter_bar($statuses, ['placeholder' => 'Search reference, name, email or text', 'html' => $catOptions, 'active' => $category !== '' || $rating > 0]); ?>
    <div class="table-meta"><span><?= $total ?> result<?= $total === 1 ? '' : 's' ?></span>
        <?php if (admin_can('exports.run')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('feedback.php?' . http_build_query(array_filter(['status' => $status, 'category' => $category, 'rating' => $rating ?: '', 'q' => $q, 'export' => 1])))) ?>"><?= icon('download') ?>Export CSV</a><?php endif; ?></div>
    <?php if (!$rows): ?>
        <p class="muted">No feedback found.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Reference</th><th>Rating</th><th>Topic</th><th>Feedback</th><th>From</th><th>Status</th><th>Received</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="<?= e(admin_url('feedback.php?id=' . $r['id'])) ?>"><strong><?= e($r['reference_no'] ?: '#' . $r['id']) ?></strong></a></td>
                    <td class="nowrap"><?= $stars($r['rating'] !== null ? (int) $r['rating'] : null) ?></td>
                    <td><?= e(FEEDBACK_CATEGORIES[$r['category']] ?? humanize($r['category'])) ?></td>
                    <td><?= e(excerpt($r['message'], 90)) ?></td>
                    <td><?= e($r['name']) ?><br><small class="muted"><?= e($r['email']) ?></small></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php endif; ?>
</section>
<?php
admin_page_end();
