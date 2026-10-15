<?php
/**
 * Backup & data retention.
 * - Download a complete database backup (.sql) generated in PHP and streamed to the browser.
 * - Upload folder sizes, with advice to download files through the hosting File Manager.
 * - Data retention: preview and run the clean-up (old logs, anonymise old rejected applications, old closed
 *   enquiries and feedback, names / IP addresses of unsubscribed newsletter records, old analytics, expired tokens).
 *   It also runs automatically once a day (from the dashboard). Donations and receipts are never deleted.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('backups.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('backups.php');
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'download') {
        $skip = [];
        if (!empty($_POST['skip_page_views'])) {
            $skip[] = 'page_views';
        }
        if (!empty($_POST['skip_activity_logs'])) {
            $skip[] = 'activity_logs';
        }
        admin_log('backup_downloaded', 'Database backup downloaded' . ($skip ? ' (without rows of ' . implode(', ', $skip) . ')' : ''));
        $me = current_admin();
        backup_stream_sql($skip, $me ? $me['email'] : '');
    }
    if ($action === 'cleanup') {
        $before = retention_preview();
        $done = retention_run();
        $summary = [];
        foreach ($done as $key => $n) {
            $summary[] = $before[$key]['label'] . ': ' . (int) $n;
        }
        save_setting('retention_last_run', date('Y-m-d H:i:s'));
        admin_log('retention_cleanup', 'Data clean-up: ' . implode('; ', $summary));
        flash('success', 'Clean-up finished. ' . implode(' · ', $summary) . '.');
        admin_redirect('backups.php#retention');
    }
    if ($action === 'image_variants') {
        // Resized copies (640 / 1080 px) for public images uploaded before they were created automatically.
        $made = 0;
        foreach (['gallery', 'campaigns', 'events', 'blog', 'stories', 'pages', 'media', 'team'] as $folder) {
            $dir = UPLOAD_PATH . '/' . $folder;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $name = $file->getFilename();
                if (!$file->isFile() || !preg_match('/\.(jpe?g|png|webp)$/i', $name) || preg_match('/-(' . implode('|', IMAGE_VARIANT_WIDTHS) . ')\.[a-z]+$/i', $name)) {
                    continue;
                }
                $before = 0;
                foreach (IMAGE_VARIANT_WIDTHS as $vw) {
                    $before += is_file(image_variant_path($file->getPathname(), $vw)) ? 1 : 0;
                }
                make_image_variants($file->getPathname());
                foreach (IMAGE_VARIANT_WIDTHS as $vw) {
                    $made += is_file(image_variant_path($file->getPathname(), $vw)) ? 1 : 0;
                }
                $made -= $before;
            }
        }
        admin_log('image_variants_created', $made . ' resized image copies created');
        flash('success', $made ? $made . ' resized image copies were created for faster pages on phones.' : 'All public images already have their resized copies.');
        admin_redirect('backups.php#uploads');
    }
    if ($action === 'retention_settings') {
        $limits = ['retention_logs_days' => [90, 3650], 'retention_rejected_applications_days' => [180, 3650], 'retention_enquiries_days' => [180, 3650], 'analytics_retention_days' => [30, 1095]];
        $errors = [];
        foreach ($limits as $key => [$min, $max]) {
            $v = (string) ($_POST[$key] ?? '');
            if (!preg_match('/^\d{1,4}$/', $v) || (int) $v < $min || (int) $v > $max) {
                $errors[$key] = 'Enter a number of days between ' . $min . ' and ' . $max . '.';
            }
        }
        if ($errors) {
            back_with_errors($errors, 'admin/backups.php#retention-settings', $_POST);
        }
        foreach (array_keys($limits) as $key) {
            save_setting($key, (string) (int) $_POST[$key]);
        }
        admin_log('retention_settings_updated', 'Retention periods: logs ' . (int) $_POST['retention_logs_days'] . ', rejected applications ' . (int) $_POST['retention_rejected_applications_days']
            . ', enquiries ' . (int) $_POST['retention_enquiries_days'] . ', analytics ' . (int) $_POST['analytics_retention_days'] . ' days');
        flash('success', 'Retention periods saved.');
        admin_redirect('backups.php#retention-settings');
    }
    admin_redirect('backups.php');
}

$tables = db_rows('SELECT TABLE_NAME name, TABLE_ROWS approx_rows, DATA_LENGTH + INDEX_LENGTH size FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\' ORDER BY size DESC');
$dbSize = array_sum(array_column($tables, 'size'));
$bigTables = array_slice($tables, 0, 6);
$lastBackup = db_row("SELECT l.created_at, u.name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.action = 'backup_downloaded' ORDER BY l.id DESC LIMIT 1");
$folders = [];
foreach (glob(UPLOAD_PATH . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    [$bytes, $files] = insights_dir_size($dir);
    $name = basename($dir);
    $folders[] = ['name' => $name, 'bytes' => $bytes, 'files' => $files, 'private' => in_array($name, ['documents', 'certificates'], true)];
}
usort($folders, function ($a, $b) { return $b['bytes'] <=> $a['bytes']; });
$uploadsTotal = array_sum(array_column($folders, 'bytes'));
$preview = retention_preview();
$days = retention_days();
$lastRun = setting('retention_last_run');
$pvRows = (int) db_value('SELECT COUNT(*) FROM page_views');
$logRows = (int) db_value('SELECT COUNT(*) FROM activity_logs');

admin_page_start('Backup & Data Retention');
?>
<div class="admin-grid-2">
    <section class="panel">
        <div class="panel-head"><h2><?= icon('download') ?> Database backup</h2><span class="muted small"><?= e(insights_bytes((int) $dbSize)) ?> · <?= count($tables) ?> tables</span></div>
        <p>Download a complete copy of the database as an <code>.sql</code> file. It can be restored by importing it into an empty database with phpMyAdmin.</p>
        <form method="post" class="backup-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="download">
            <label class="checkbox"><input type="checkbox" name="skip_page_views" value="1"<?= $pvRows > 20000 ? ' checked' : '' ?>><span>Leave out website statistics rows (<?= number_format($pvRows) ?> page views) – the table structure is kept</span></label>
            <label class="checkbox"><input type="checkbox" name="skip_activity_logs" value="1"><span>Leave out audit log rows (<?= number_format($logRows) ?> entries) – the table structure is kept</span></label>
            <button class="btn btn-primary mt-2" type="submit"><?= icon('download') ?>Download backup (.sql)</button>
        </form>
        <p class="muted small mt-2 mb-0"><?= $lastBackup ? 'Last downloaded ' . e(format_date($lastBackup['created_at'], 'd M Y, h:i A')) . ($lastBackup['name'] ? ' by ' . e($lastBackup['name']) : '') . '.' : 'No backup has been downloaded from this page yet.' ?></p>
        <div class="note-box mt-2"><strong>Keep backups safe.</strong> The file contains personal data of donors, volunteers and applicants and the (hashed) passwords of all accounts. Store it in a private, encrypted location and delete old copies you no longer need. Hostinger also keeps automatic backups – see hPanel › Files › Backups.</div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2><?= icon('image') ?> Uploaded files</h2><span class="muted small"><?= e(insights_bytes((int) $uploadsTotal)) ?></span></div>
        <?php if ($folders): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Folder</th><th class="text-right">Files</th><th class="text-right">Size</th></tr></thead>
                <tbody>
                <?php foreach ($folders as $f): ?>
                    <tr><td><code>uploads/<?= e($f['name']) ?>/</code><?= $f['private'] ? ' <span class="badge badge-gold">Private</span>' : '' ?></td><td class="text-right"><?= number_format($f['files']) ?></td><td class="text-right nowrap"><?= e(insights_bytes($f['bytes'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?><p class="muted">No upload folders found.</p><?php endif; ?>
        <form method="post" class="mt-2" id="uploads"><?= csrf_field() ?><input type="hidden" name="action" value="image_variants">
            <button class="btn btn-ghost btn-sm" type="submit"><?= icon('refresh') ?>Create missing resized image copies</button>
            <span class="muted small">Smaller copies of public photos (gallery, campaigns, events, blog, stories) load faster on phones. New uploads get them automatically.</span></form>
        <p class="small mt-2 mb-0">Uploaded images and documents are not part of the database backup. To copy them, open <strong>hPanel › File Manager</strong>, right-click <code>public_html/uploads</code>, choose <em>Compress</em> and download the .zip file. Private folders hold resumes, ID documents and certificates – handle them with the same care as the database backup.</p>
    </section>
</div>

<section class="panel mt-3" id="retention">
    <div class="panel-head"><h2><?= icon('refresh') ?> Data retention clean-up</h2><span class="muted small">Last run: <?= $lastRun !== '' ? e(format_date($lastRun, 'd M Y, h:i A')) : 'never' ?></span></div>
    <p class="muted small">Personal data should not be kept longer than needed. The clean-up applies the periods below and also runs automatically once a day (when the admin dashboard is opened). <strong>Donations and receipts are never deleted</strong> – they are financial records.</p>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Data</th><th>Rule</th><th class="text-right">Affected now</th></tr></thead>
        <tbody>
        <?php foreach ($preview as $item): ?>
            <tr><td><strong><?= e($item['label']) ?></strong></td><td class="small"><?= e($item['detail']) ?></td><td class="text-right"><?= $item['count'] ? '<span class="badge badge-gold">' . number_format($item['count']) . '</span>' : '<span class="muted">0</span>' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php $toClean = array_sum(array_column($preview, 'count')); ?>
    <form method="post" class="mt-2" data-confirm="Run the clean-up now? <?= (int) $toClean ?> record(s) will be deleted or anonymised. This cannot be undone – download a backup first.">
        <?= csrf_field() ?><input type="hidden" name="action" value="cleanup">
        <button class="btn btn-<?= $toClean ? 'donate' : 'ghost' ?> btn-sm" type="submit"><?= icon('refresh') ?>Run clean-up now</button>
        <span class="muted small"><?= $toClean ? number_format($toClean) . ' record(s) would be removed or anonymised.' : 'Nothing to clean up at the moment.' ?></span>
    </form>
</section>

<section class="panel mt-3" id="retention-settings">
    <div class="panel-head"><h2><?= icon('sliders') ?> Retention periods</h2></div>
    <form method="post" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="action" value="retention_settings">
        <div class="form-group"><label for="retention_logs_days">Audit & activity logs (days)</label><input id="retention_logs_days" name="retention_logs_days" type="number" min="90" max="3650" required value="<?= e(old('retention_logs_days', (string) $days['logs'])) ?>"><?= field_error('retention_logs_days') ?><span class="help">Minimum 90 days.</span></div>
        <div class="form-group"><label for="retention_rejected_applications_days">Rejected applications & requests (days)</label><input id="retention_rejected_applications_days" name="retention_rejected_applications_days" type="number" min="180" max="3650" required value="<?= e(old('retention_rejected_applications_days', (string) $days['applications'])) ?>"><?= field_error('retention_rejected_applications_days') ?><span class="help">Personal details are anonymised after this period.</span></div>
        <div class="form-group"><label for="retention_enquiries_days">Resolved / closed enquiries &amp; feedback (days)</label><input id="retention_enquiries_days" name="retention_enquiries_days" type="number" min="180" max="3650" required value="<?= e(old('retention_enquiries_days', (string) $days['enquiries'])) ?>"><?= field_error('retention_enquiries_days') ?></div>
        <div class="form-group"><label for="analytics_retention_days">Website statistics (days)</label><input id="analytics_retention_days" name="analytics_retention_days" type="number" min="30" max="1095" required value="<?= e(old('analytics_retention_days', (string) $days['analytics'])) ?>"><?= field_error('analytics_retention_days') ?><span class="help">Minimum 30 days.</span></div>
        <div class="form-actions full"><button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save periods</button></div>
    </form>
</section>

<section class="panel mt-3">
    <div class="panel-head"><h2>Largest tables</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Table</th><th class="text-right">Rows (approx.)</th><th class="text-right">Size</th></tr></thead>
        <tbody><?php foreach ($bigTables as $t): ?><tr><td><code><?= e($t['name']) ?></code></td><td class="text-right"><?= number_format((int) $t['approx_rows']) ?></td><td class="text-right"><?= e(insights_bytes((int) $t['size'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
</section>
<?php
admin_page_end();
