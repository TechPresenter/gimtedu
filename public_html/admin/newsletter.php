<?php
/**
 * Newsletter: subscribers with consent details (newsletter.manage) and compose & send (newsletter.send).
 * Sending goes out in batches of NEWSLETTER_BATCH per click so shared hosting limits are respected; the
 * progress of the current mailing is stored in the website setting `newsletter_job` (JSON).
 * Every email carries the subscriber's personal one-click unsubscribe link.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('newsletter.manage');

const NEWSLETTER_BATCH = 40;

$job = setting_json('newsletter_job');
$canSend = admin_can('newsletter.send');
$draft = $_SESSION['nl_draft'] ?? ['subject' => '', 'body' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only('newsletter.php');
    $action = (string) ($_POST['action'] ?? '');

    // ---- Subscriber actions (newsletter.manage) ----
    if (in_array($action, ['unsubscribe', 'delete'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        $sub = db_row('SELECT id, email, status FROM newsletter_subscribers WHERE id = ?', [$id]);
        if ($sub && $action === 'unsubscribe' && $sub['status'] === 'subscribed') {
            db_update('newsletter_subscribers', ['status' => 'unsubscribed'], 'id = :id', ['id' => $id]);
            admin_log('newsletter_unsubscribed', 'Subscriber #' . $id . ' unsubscribed by staff');
            flash('success', 'Subscriber marked as unsubscribed.');
        } elseif ($sub && $action === 'delete') {
            db_query('DELETE FROM newsletter_subscribers WHERE id = ?', [$id]);
            admin_log('newsletter_subscriber_deleted', 'Subscriber #' . $id . ' deleted (data removed)');
            flash('success', 'Subscriber record deleted.');
        }
        admin_redirect(admin_back('newsletter.php'));
    }

    // ---- Compose & send (newsletter.send) ----
    if (!$canSend) {
        require_permission('newsletter.send');
    }
    $tab = 'newsletter.php?tab=compose';

    if (in_array($action, ['preview', 'test', 'start'], true)) {
        [$data, $errors] = validate($_POST, ['subject' => 'required|min:3|max:150']);
        $body = clean_html((string) ($_POST['body'] ?? ''));
        if (trim(strip_tags($body)) === '') {
            $errors['body'] = 'Write the newsletter content.';
        } elseif (mb_strlen($body) > 200000) {
            $errors['body'] = 'The content is too long.';
        }
        $_SESSION['nl_draft'] = ['subject' => $data['subject'], 'body' => $body !== '' ? $body : (string) ($_POST['body'] ?? '')];
        if ($errors) {
            back_with_errors($errors, 'admin/' . $tab, ['subject' => $data['subject']]);
        }
        if ($action === 'preview') {
            $_SESSION['nl_preview'] = true;
            admin_redirect($tab . '#preview');
        }
        if ($action === 'test') {
            $me = current_admin();
            $html = $body . '<hr style="border:0;border-top:1px solid #e0e9e3;margin:24px 0 14px"><p style="font-size:12px;color:#5b6b62">Test email - subscribers will see their personal one-click unsubscribe link here.</p>';
            $ok = send_mail($me['email'], '[Test] ' . $data['subject'], $html);
            admin_log('newsletter_test', 'Test newsletter "' . mb_substr($data['subject'], 0, 100) . '" to ' . $me['email'] . ($ok ? '' : ' (not sent)'));
            flash($ok ? 'success' : 'warning', $ok ? 'Test email sent to ' . $me['email'] . '.' : 'The test email could not be sent. Check the mail settings.');
            admin_redirect($tab);
        }
        // start
        if (($job['status'] ?? '') === 'running') {
            flash('error', 'Another mailing is still being sent. Finish or cancel it first.');
            admin_redirect($tab);
        }
        $total = (int) db_value("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'subscribed'");
        if ($total === 0) {
            flash('error', 'There are no subscribed addresses to send to.');
            admin_redirect($tab);
        }
        $job = [
            'id' => bin2hex(random_bytes(6)), 'subject' => $data['subject'], 'body' => $body, 'status' => 'running',
            'total' => $total, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'last_id' => 0,
            'started_at' => date('Y-m-d H:i:s'), 'started_by' => admin_id(), 'started_by_name' => current_admin()['name'] ?? '', 'finished_at' => null,
        ];
        save_setting('newsletter_job', json_encode($job, JSON_UNESCAPED_UNICODE));
        unset($_SESSION['nl_draft'], $_SESSION['nl_preview']);
        admin_log('newsletter_started', 'Mailing ' . $job['id'] . ' "' . mb_substr($job['subject'], 0, 100) . '" to ' . $total . ' subscribers');
        flash('success', 'Mailing prepared for ' . $total . ' subscribers. Send it batch by batch below.');
        admin_redirect($tab);
    }

    if ($action === 'batch') {
        if (($job['status'] ?? '') !== 'running' || ($_POST['job'] ?? '') !== ($job['id'] ?? '')) {
            flash('error', 'There is no mailing in progress.');
            admin_redirect($tab);
        }
        @set_time_limit(300);
        $subs = db_rows("SELECT id, email, unsubscribe_token FROM newsletter_subscribers WHERE status = 'subscribed' AND id > ? ORDER BY id LIMIT " . NEWSLETTER_BATCH, [(int) $job['last_id']]);
        $sent = 0;
        $failed = 0;
        foreach ($subs as $s) {
            if (send_mail($s['email'], $job['subject'], $job['body'] . newsletter_email_footer($s['unsubscribe_token']))) {
                $sent++;
            } else {
                $failed++;
            }
            newsletter_dev_log($s['email'], $s['unsubscribe_token']);
            $job['last_id'] = (int) $s['id'];
        }
        $job['processed'] += count($subs);
        $job['sent'] += $sent;
        $job['failed'] += $failed;
        $remaining = (int) db_value("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'subscribed' AND id > ?", [(int) $job['last_id']]);
        if ($remaining === 0) {
            $job['status'] = 'completed';
            $job['finished_at'] = date('Y-m-d H:i:s');
        }
        save_setting('newsletter_job', json_encode($job, JSON_UNESCAPED_UNICODE));
        admin_log('newsletter_batch', 'Mailing ' . $job['id'] . ': batch of ' . count($subs) . ' (' . $sent . ' sent, ' . $failed . ' failed)' . ($job['status'] === 'completed' ? ' - completed' : ''));
        flash($failed ? 'warning' : 'success', 'Batch processed: ' . $sent . ' sent' . ($failed ? ', ' . $failed . ' could not be sent' : '') . '.' . ($job['status'] === 'completed' ? ' The mailing is complete.' : ' ' . $remaining . ' remaining.'));
        admin_redirect($tab);
    }

    if ($action === 'cancel' && ($job['status'] ?? '') === 'running') {
        $job['status'] = 'cancelled';
        $job['finished_at'] = date('Y-m-d H:i:s');
        save_setting('newsletter_job', json_encode($job, JSON_UNESCAPED_UNICODE));
        admin_log('newsletter_cancelled', 'Mailing ' . $job['id'] . ' cancelled after ' . (int) $job['processed'] . ' of ' . (int) $job['total']);
        flash('success', 'Mailing cancelled. No further emails will be sent.');
    }
    if ($action === 'discard') {
        unset($_SESSION['nl_draft'], $_SESSION['nl_preview']);
        flash('success', 'Draft cleared.');
    }
    admin_redirect($tab);
}

$tab = ($_GET['tab'] ?? '') === 'compose' && $canSend ? 'compose' : 'subscribers';

// ---------------------------------------------------------------------
// Subscribers list
// ---------------------------------------------------------------------
$status = (string) ($_GET['status'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = 'WHERE 1=1';
if (in_array($status, ['subscribed', 'unsubscribed'], true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
$where .= search_where(['email', 'name', 'source'], $q, $params);
if (isset($_GET['export'])) {
    require_permission('exports.run');
    $rows = db_rows("SELECT email, name, status, source, consent_text, consented_at, created_at, updated_at FROM newsletter_subscribers $where ORDER BY created_at DESC", $params);
    admin_log('export_newsletter', count($rows) . ' subscribers exported');
    send_csv('newsletter-subscribers-' . date('Y-m-d') . '.csv', ['Email', 'Name', 'Status', 'Source', 'Consent text', 'Consent given', 'Created', 'Updated'], $rows);
}
$stats = db_row("SELECT SUM(status = 'subscribed') subscribed, SUM(status = 'unsubscribed') unsubscribed,
                 SUM(status = 'subscribed' AND created_at >= NOW() - INTERVAL 30 DAY) recent FROM newsletter_subscribers");

admin_page_start('Newsletter');
?>
<div class="stat-cards">
    <div class="stat-card"><span class="icon-circle"><?= icon('mail') ?></span><div><strong><?= (int) $stats['subscribed'] ?></strong><small>Subscribed (with consent)</small></div></div>
    <div class="stat-card"><span class="icon-circle gold"><?= icon('trending-up') ?></span><div><strong><?= (int) $stats['recent'] ?></strong><small>Joined in the last 30 days</small></div></div>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('log-out') ?></span><div><strong><?= (int) $stats['unsubscribed'] ?></strong><small>Unsubscribed</small></div></div>
</div>

<nav class="admin-tabs" aria-label="Newsletter">
    <a href="<?= e(admin_url('newsletter.php')) ?>" class="<?= $tab === 'subscribers' ? 'active' : '' ?>">Subscribers</a>
    <?php if ($canSend): ?><a href="<?= e(admin_url('newsletter.php?tab=compose')) ?>" class="<?= $tab === 'compose' ? 'active' : '' ?>">Compose &amp; send<?= ($job['status'] ?? '') === 'running' ? ' <span class="badge badge-gold">in progress</span>' : '' ?></a><?php endif; ?>
</nav>

<?php if ($tab === 'subscribers'):
    $total = (int) db_value("SELECT COUNT(*) FROM newsletter_subscribers $where", $params);
    $p = paginate($total, 30);
    $rows = db_rows("SELECT * FROM newsletter_subscribers $where ORDER BY created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
    ?>
    <section class="panel">
        <?php admin_filter_bar(['subscribed', 'unsubscribed'], ['placeholder' => 'Search email, name or source']); ?>
        <div class="table-meta"><span><?= $total ?> address<?= $total === 1 ? '' : 'es' ?></span>
            <?php if (admin_can('exports.run')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('newsletter.php?' . http_build_query(array_filter(['status' => $status, 'q' => $q, 'export' => 1])))) ?>"><?= icon('download') ?>Export CSV</a><?php endif; ?></div>
        <?php if (!$rows): ?>
            <p class="muted">No subscribers found.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Email</th><th>Status</th><th>Source</th><th>Consent</th><th>Joined</th><th class="actions-cell">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= e($r['email']) ?></strong><?php if ($r['name']): ?><br><small class="muted"><?= e($r['name']) ?></small><?php endif; ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td><?= e($r['source'] ?: '-') ?></td>
                        <td><?php if ($r['consented_at']): ?><details><summary class="small"><?= e(format_date($r['consented_at'], 'd M Y, h:i A')) ?></summary><p class="small muted mb-0">"<?= e($r['consent_text']) ?>"<?= $r['ip_address'] ? '<br>IP ' . e($r['ip_address']) : '' ?></p></details><?php else: ?><span class="muted small">Not recorded</span><?php endif; ?></td>
                        <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                        <td class="actions-cell">
                            <?php if ($r['status'] === 'subscribed'): ?>
                                <form method="post" class="inline-form" data-confirm="Unsubscribe <?= e($r['email']) ?>?"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="unsubscribe"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="icon-btn" type="submit" title="Unsubscribe" aria-label="Unsubscribe <?= e($r['email']) ?>"><?= icon('log-out') ?></button></form>
                            <?php endif; ?>
                            <form method="post" class="inline-form" data-confirm="Delete the record of <?= e($r['email']) ?> permanently (for example after a deletion request)?"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn danger" type="submit" title="Delete" aria-label="Delete <?= e($r['email']) ?>"><?= icon('trash') ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?= pagination_links($p) ?>
        <?php endif; ?>
        <p class="small muted mt-2">Addresses are added only through the website's sign-up forms with the consent checkbox. Do not import lists of people who have not agreed to receive emails.</p>
    </section>

<?php else:
    $running = ($job['status'] ?? '') === 'running';
    $pct = !empty($job['total']) ? (int) min(100, floor(((int) $job['processed']) / max(1, (int) $job['total']) * 100)) : 0;
    $showPreview = !empty($_SESSION['nl_preview']);
    unset($_SESSION['nl_preview']);
    ?>
    <?php if ($job): ?>
        <section class="panel">
            <div class="panel-head"><h2><?= $running ? 'Mailing in progress' : 'Last mailing' ?></h2><?= status_badge($job['status'] === 'running' ? 'in_progress' : (string) $job['status']) ?></div>
            <p><strong><?= e($job['subject']) ?></strong><br><small class="muted">Started <?= e(format_date($job['started_at'], 'd M Y, h:i A')) ?> by <?= e($job['started_by_name'] ?? '') ?><?= !empty($job['finished_at']) ? ' · finished ' . e(format_date($job['finished_at'], 'd M Y, h:i A')) : '' ?></small></p>
            <div class="mini-progress" style="width:100%;height:12px" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Sending progress"><span style="width:<?= $pct ?>%"></span></div>
            <p class="small mt-1"><?= (int) $job['processed'] ?> of <?= (int) $job['total'] ?> processed (<?= $pct ?>%) · <?= (int) $job['sent'] ?> sent<?= (int) $job['failed'] ? ' · <span style="color:var(--red-600)">' . (int) $job['failed'] . ' could not be sent</span>' : '' ?></p>
            <?php if ($running): ?>
                <div class="btn-row">
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="batch"><input type="hidden" name="job" value="<?= e($job['id']) ?>">
                        <button class="btn btn-primary" type="submit"><?= icon('send') ?>Send next batch (up to <?= NEWSLETTER_BATCH ?>)</button></form>
                    <form method="post" class="inline-form" data-confirm="Stop this mailing? Subscribers who have not received it yet will not get it."><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                        <button class="btn btn-ghost" type="submit"><?= icon('x') ?>Cancel mailing</button></form>
                </div>
                <p class="small muted mt-1">Emails are sent <?= NEWSLETTER_BATCH ?> at a time to stay within the hosting provider's sending limits. Click again after each batch until the mailing is complete.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!$running): ?>
        <div class="admin-grid-main">
            <section class="panel">
                <div class="panel-head"><h2>Compose a newsletter</h2></div>
                <form method="post" class="form-grid">
                    <?= csrf_field() ?>
                    <div class="form-group full"><label for="subject">Subject</label><input type="text" id="subject" name="subject" required maxlength="150" value="<?= e(old('subject', $draft['subject'])) ?>"><?= field_error('subject') ?></div>
                    <div class="form-group full"><label for="body">Content</label>
                        <textarea id="body" name="body" rows="16" data-editor><?= e($draft['body']) ?></textarea><?= field_error('body') ?>
                        <span class="help">Simple HTML: headings, paragraphs, lists, links, images from the media library. Scripts and styles are removed. A personal unsubscribe link is added to every email automatically.</span></div>
                    <div class="form-actions full" style="grid-column:1/-1">
                        <button class="btn btn-ghost" type="submit" name="action" value="preview"><?= icon('eye') ?>Preview</button>
                        <button class="btn btn-outline" type="submit" name="action" value="test"><?= icon('mail') ?>Send test to me</button>
                        <button class="btn btn-primary" type="submit" name="action" value="start" data-confirm-click="Prepare this mailing for all <?= (int) $stats['subscribed'] ?> subscribed addresses?"><?= icon('send') ?>Send to <?= (int) $stats['subscribed'] ?> subscribers</button>
                    </div>
                </form>
                <?php if ($draft['subject'] !== '' || $draft['body'] !== ''): ?>
                    <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="discard"><button class="btn btn-ghost btn-sm" type="submit">Clear draft</button></form>
                <?php endif; ?>
            </section>
            <aside class="panel">
                <div class="panel-head"><h2>Good practice</h2></div>
                <ul class="small">
                    <li>Send only to people who subscribed - never add addresses yourself.</li>
                    <li>Share real updates: verified figures only, no invented stories or urgency.</li>
                    <li>Use photos of identifiable people only with their consent.</li>
                    <li>Always send a test to yourself first and check links on a phone.</li>
                    <li>Every email includes a working one-click unsubscribe link.</li>
                </ul>
            </aside>
        </div>
        <?php if ($showPreview && $draft['body'] !== ''): ?>
            <section class="panel" id="preview">
                <div class="panel-head"><h2>Preview</h2><span class="muted small">Subject: <strong><?= e($draft['subject']) ?></strong></span></div>
                <div style="background:#f3f6f4;padding:20px;border-radius:12px">
                    <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid var(--line)">
                        <div style="background:#1a7f46;color:#fff;font-weight:700;padding:16px 24px"><?= e(site_name()) ?></div>
                        <div class="prose" style="padding:24px"><?= clean_html($draft['body']) ?><hr style="border:0;border-top:1px solid #e0e9e3;margin:24px 0 14px"><p style="font-size:12px;color:#5b6b62">You are receiving this email because you subscribed to updates from <?= e(site_name()) ?>. <u>Unsubscribe</u> at any time with one click. <em>(Each subscriber gets a personal link here.)</em></p></div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php
admin_page_end();
