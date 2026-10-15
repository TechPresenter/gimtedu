<?php
/**
 * Admin helper functions (no output). Loaded by admin/includes/auth.php and
 * by public pages that need to recognise a logged-in administrator.
 */
if (!defined('PWF_APP')) {
    require_once dirname(__DIR__, 2) . '/functions.php';
}

/** Every permission key and what it unlocks. */
const ADMIN_PERMISSIONS = [
    'campaigns'     => 'Campaigns (create, edit, close)',
    'donations'     => 'Donations, receipts & offline donations',
    'applications'  => 'Volunteer, intern, teacher & staff applications',
    'members'       => 'Members, website users & certificates',
    'beneficiaries' => 'Help requests & scholarships',
    'content'       => 'Pages, homepage, menus, events, gallery, blog, testimonials & FAQs',
    'enquiries'     => 'Contact enquiries, grievances & newsletter',
    'reports'       => 'Reports & CSV exports',
    'settings'      => 'Website settings & payment/contact details',
    'users'         => 'Admin users & roles',
    'logs'          => 'Activity logs',
];

/** The logged-in admin (with role & permissions) or null. */
function current_admin(bool $refresh = false): ?array
{
    static $admin = false;
    if ($admin !== false && !$refresh) {
        return $admin;
    }
    $admin = null;
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    // Inactivity timeout
    if (!empty($_SESSION['admin_last_activity']) && time() - $_SESSION['admin_last_activity'] > ADMIN_SESSION_TIMEOUT) {
        admin_logout();
        return null;
    }
    // Bind the session to the browser that logged in
    if (($_SESSION['admin_agent'] ?? '') !== hash('sha256', user_agent())) {
        admin_logout();
        return null;
    }
    $row = db_row("SELECT a.*, r.name AS role_name, r.slug AS role_slug, r.permissions
                   FROM admins a JOIN roles r ON r.id = a.role_id
                   WHERE a.id = ? AND a.status = 'active'", [(int) $_SESSION['admin_id']]);
    if (!$row) {
        admin_logout();
        return null;
    }
    $_SESSION['admin_last_activity'] = time();
    $row['permission_list'] = $row['permissions'] === 'all'
        ? array_keys(ADMIN_PERMISSIONS)
        : array_values(array_filter(array_map('trim', explode(',', $row['permissions']))));
    $admin = $row;
    return $admin;
}

function admin_id(): ?int
{
    $a = current_admin();
    return $a ? (int) $a['id'] : null;
}

/** Does the current admin have this permission? */
function admin_can(string $permission): bool
{
    $admin = current_admin();
    if (!$admin) {
        return false;
    }
    return $admin['permissions'] === 'all' || in_array($permission, $admin['permission_list'], true);
}

function is_super_admin(): bool
{
    $admin = current_admin();
    return $admin && $admin['permissions'] === 'all';
}

function admin_login(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['admin_agent'] = hash('sha256', user_agent());
    db_update('admins', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip()], 'id = :id', ['id' => (int) $admin['id']]);
    log_activity('admin_login', 'Admin logged in: ' . $admin['email'], (int) $admin['id']);
}

function admin_logout(): void
{
    unset($_SESSION['admin_id'], $_SESSION['admin_last_activity'], $_SESSION['admin_agent']);
    session_regenerate_id(true);
}

/** Record an admin action in the audit log. */
function admin_log(string $action, string $details = ''): void
{
    log_activity($action, $details, admin_id());
}

/** URL inside the admin folder. */
function admin_url(string $path = 'dashboard.php'): string
{
    return url('admin/' . ltrim($path, '/'));
}

function admin_redirect(string $path): void
{
    redirect('admin/' . ltrim($path, '/'));
}

/** Status options used in admin filters and review forms. */
function application_statuses(string $type): array
{
    $common = ['pending', 'under_review', 'shortlisted', 'approved', 'rejected', 'on_hold'];
    $map = [
        'volunteers' => $common,
        'teacher_applications' => $common,
        'staff_applications' => $common,
        'beneficiaries' => ['pending', 'under_review', 'approved', 'assisted', 'rejected'],
        'scholarships' => ['pending', 'under_review', 'shortlisted', 'approved', 'rejected', 'disbursed'],
        'contact_enquiries' => ['new', 'in_progress', 'resolved', 'closed'],
        'members' => ['pending', 'approved', 'rejected', 'suspended'],
    ];
    return $map[$type] ?? $common;
}

/** CSV-safe cell (prevents formula injection when opened in Excel). */
function csv_cell($value): string
{
    $value = (string) ($value ?? '');
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $value = "'" . $value;
    }
    return $value;
}

/** Stream rows as a CSV download. */
function send_csv(string $filename, array $header, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename) . '"');
    header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_cell', array_values($row)));
    }
    fclose($out);
    exit;
}

/** Search box + status filter bar used on list pages. */
function admin_filter_bar(array $statuses = [], array $extra = []): void
{
    $q = (string) ($_GET['q'] ?? '');
    $status = (string) ($_GET['status'] ?? '');
    echo '<form class="filter-row" method="get">';
    foreach ($extra['hidden'] ?? [] as $name => $value) {
        echo '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
    }
    echo '<input type="search" name="q" value="' . e($q) . '" placeholder="' . e($extra['placeholder'] ?? 'Search name, email, phone or reference') . '" aria-label="Search">';
    if ($statuses) {
        echo '<select name="status" aria-label="Status"><option value="">All statuses</option>';
        foreach ($statuses as $s) {
            echo '<option value="' . e($s) . '"' . ($status === $s ? ' selected' : '') . '>' . e(humanize($s)) . '</option>';
        }
        echo '</select>';
    }
    echo $extra['html'] ?? '';
    echo '<button class="btn btn-primary btn-sm" type="submit">' . icon('search') . 'Filter</button>';
    if ($q !== '' || $status !== '' || !empty($extra['active'])) {
        echo '<a class="btn btn-ghost btn-sm" href="?' . e(http_build_query($extra['hidden'] ?? [])) . '">Reset</a>';
    }
    echo '</form>';
}

/** Build WHERE clause pieces for a simple search over several columns. */
function search_where(array $columns, string $q, array &$params): string
{
    if ($q === '') {
        return '';
    }
    $parts = [];
    foreach ($columns as $col) {
        $parts[] = "$col LIKE ?";
        $params[] = '%' . $q . '%';
    }
    return ' AND (' . implode(' OR ', $parts) . ')';
}

/** Return-to link that stays inside the admin panel. */
function admin_back(string $default): string
{
    $back = (string) ($_POST['return_to'] ?? '');
    return preg_match('#^[a-z0-9\-]+\.php(\?[A-Za-z0-9=&%_\-.+]*)?$#i', $back) ? $back : $default;
}

/** Hidden field to return to the current admin page after an action. */
function return_field(): string
{
    $page = basename($_SERVER['PHP_SELF']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    return '<input type="hidden" name="return_to" value="' . e($page) . '">';
}

/** Campaign create/edit form (used by campaign-add.php and campaign-edit.php). */
function campaign_form(?array $c): void
{
    $v = function (string $key, string $default = '') use ($c) {
        return (string) old($key, $c[$key] ?? $default);
    };
    ?>
    <form method="post" action="actions/campaign-save.php" enctype="multipart/form-data" data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($c['id'] ?? 0) ?>">
        <div class="admin-grid-main">
            <div class="panel">
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="title">Campaign title<span class="req">*</span></label>
                        <input id="title" type="text" name="title" required maxlength="200" value="<?= e($v('title')) ?>" data-slug-source="#slug">
                        <?= field_error('title') ?>
                    </div>
                    <div class="form-group full">
                        <label for="slug">URL slug</label>
                        <input id="slug" type="text" name="slug" maxlength="200" pattern="[a-z0-9\-]*" title="Lowercase letters, numbers and hyphens" value="<?= e($v('slug')) ?>">
                        <span class="help">Used in the link: campaign-details.php?slug=…  Leave blank to create automatically.</span>
                        <?= field_error('slug') ?>
                    </div>
                    <div class="form-group full">
                        <label for="short_description">Short description<span class="req">*</span></label>
                        <textarea id="short_description" name="short_description" required maxlength="300" rows="2" style="min-height:70px"><?= e($v('short_description')) ?></textarea>
                        <?= field_error('short_description') ?>
                    </div>
                    <div class="form-group full">
                        <label for="description">Full description<span class="req">*</span></label>
                        <textarea id="description" name="description" required data-editor data-upload-url="actions/upload-image.php"><?= e($v('description')) ?></textarea>
                        <span class="help">Basic HTML is allowed (paragraphs, headings, lists, links, images). Scripts are removed automatically.</span>
                        <?= field_error('description') ?>
                    </div>
                </div>
            </div>
            <div>
                <div class="panel">
                    <div class="form-group mb-2">
                        <label for="status">Status<span class="req">*</span></label>
                        <select id="status" name="status"><?= options_html(['draft' => 'Draft (hidden)', 'active' => 'Active (accepting donations)', 'completed' => 'Completed', 'closed' => 'Closed'], $v('status', 'draft'), true, '') ?></select>
                    </div>
                    <div class="form-group mb-2">
                        <label for="category">Cause / programme<span class="req">*</span></label>
                        <select id="category" name="category"><?= options_html(campaign_categories(), $v('category', 'general'), true, '') ?></select>
                    </div>
                    <div class="form-group mb-2">
                        <label for="goal_amount">Goal amount (₹)<span class="req">*</span></label>
                        <input id="goal_amount" type="number" name="goal_amount" required min="0" max="1000000000" step="1" value="<?= e($v('goal_amount', '0')) ?>">
                        <span class="help">Enter 0 for no goal. Raised amount is calculated only from verified donations.</span>
                        <?= field_error('goal_amount') ?>
                    </div>
                    <div class="form-group mb-2">
                        <label for="location">Location</label>
                        <input id="location" type="text" name="location" maxlength="150" value="<?= e($v('location')) ?>">
                    </div>
                    <div class="form-grid" style="gap:10px">
                        <div class="form-group"><label for="start_date">Start date</label><input id="start_date" type="date" name="start_date" value="<?= e($v('start_date')) ?>"><?= field_error('start_date') ?></div>
                        <div class="form-group"><label for="end_date">End date</label><input id="end_date" type="date" name="end_date" value="<?= e($v('end_date')) ?>"><?= field_error('end_date') ?></div>
                    </div>
                    <div class="mt-2">
                        <label class="checkbox"><input type="checkbox" name="is_featured" value="1"<?= $v('is_featured') ? ' checked' : '' ?>> <span>Featured on homepage</span></label>
                        <label class="checkbox"><input type="checkbox" name="is_urgent" value="1"<?= $v('is_urgent') ? ' checked' : '' ?>> <span>Mark as urgent (e.g. disaster relief)</span></label>
                    </div>
                </div>
                <div class="panel">
                    <div class="form-group">
                        <label for="image">Campaign image</label>
                        <img id="image-preview" class="mb-2" src="<?= e(!empty($c['image']) ? image_url($c['image']) : '') ?>" alt="" style="border-radius:10px;max-height:180px;object-fit:cover"<?= empty($c['image']) ? ' hidden' : '' ?>>
                        <input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp" data-preview="#image-preview" data-max-mb="<?= MAX_UPLOAD_MB ?>">
                        <span class="help">JPG, PNG or WebP, max <?= MAX_UPLOAD_MB ?> MB. Use a real photo from the campaign.</span>
                        <?= field_error('image') ?>
                        <?php if (!empty($c['image'])): ?><label class="checkbox mt-1"><input type="checkbox" name="remove_image" value="1"> <span>Remove current image</span></label><?php endif; ?>
                    </div>
                </div>
                <button class="btn btn-primary btn-lg btn-block" type="submit"><?= icon('check') ?>Save campaign</button>
            </div>
        </div>
    </form>
    <?php
}

/** Status / notes review panel posting to actions/application-update.php */
function review_form(string $type, array $row, array $options = []): void
{
    $statuses = application_statuses($type);
    $notesField = 'admin_notes';
    ?>
    <div class="panel">
        <div class="panel-head"><h3>Review</h3><?= status_badge($row['status']) ?></div>
        <form method="post" action="actions/application-update.php" data-validate>
            <?= csrf_field() ?>
            <?= return_field() ?>
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <div class="form-group mb-2">
                <label for="status">Status</label>
                <select id="status" name="status"><?= options_html(array_combine($statuses, array_map('humanize', $statuses)), $row['status'], true, '') ?></select>
            </div>
            <?php if (!empty($options['interview'])): ?>
                <div class="form-group mb-2">
                    <label for="interview_at">Interview date &amp; time</label>
                    <input id="interview_at" type="datetime-local" name="interview_at" value="<?= e($row['interview_at'] ? date('Y-m-d\TH:i', strtotime($row['interview_at'])) : '') ?>">
                </div>
            <?php endif; ?>
            <?php if (!empty($options['valid_until'])): ?>
                <div class="form-group mb-2">
                    <label for="valid_until">Membership valid until</label>
                    <input id="valid_until" type="date" name="valid_until" value="<?= e($row['valid_until'] ?? '') ?>">
                </div>
            <?php endif; ?>
            <div class="form-group mb-2">
                <label for="admin_notes">Internal notes</label>
                <textarea id="admin_notes" name="admin_notes" maxlength="5000" rows="4" style="min-height:100px"><?= e($row[$notesField] ?? '') ?></textarea>
                <span class="help">Visible to admins only.</span>
            </div>
            <?php if (!empty($row['email'])): ?>
                <label class="checkbox mb-2"><input type="checkbox" name="notify" value="1"> <span>Email the applicant about the status change</span></label>
                <div class="form-group mb-2">
                    <label for="message">Message to applicant (optional)</label>
                    <textarea id="message" name="message" maxlength="2000" rows="3" style="min-height:80px" placeholder="Included in the email if the box above is ticked"></textarea>
                </div>
            <?php endif; ?>
            <button class="btn btn-primary btn-block" type="submit">Update</button>
        </form>
        <?php if (!empty($row['reviewed_at'])): ?>
            <p class="small muted mt-2">Last reviewed <?= e(format_date($row['reviewed_at'], 'd M Y, h:i A')) ?><?= !empty($row['reviewer_name']) ? ' by ' . e($row['reviewer_name']) : '' ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/** Issue-certificate panel (only for applicants linked to a website account). */
function certificate_form(?int $userId, string $name, string $email, string $defaultType, string $defaultTitle): void
{
    ?>
    <div class="panel">
        <h3>Issue certificate</h3>
        <?php if (!$userId): ?>
            <p class="small muted mb-0">This applicant did not apply from a website account, so a certificate cannot be published to their dashboard. Ask them to register and apply while logged in, or issue the certificate from <a href="members.php">Members</a> once they have an account.</p>
        <?php else: ?>
            <form method="post" action="actions/application-update.php" enctype="multipart/form-data" data-validate>
                <?= csrf_field() ?>
                <?= return_field() ?>
                <input type="hidden" name="action" value="certificate">
                <input type="hidden" name="type" value="certificates">
                <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
                <div class="form-group mb-2"><label for="recipient_name">Name on certificate</label><input id="recipient_name" type="text" name="recipient_name" required maxlength="120" value="<?= e($name) ?>"></div>
                <input type="hidden" name="recipient_email" value="<?= e($email) ?>">
                <div class="form-group mb-2"><label for="certificate_type">Type</label>
                    <select id="certificate_type" name="certificate_type"><?= options_html(['volunteer' => 'Volunteer', 'internship' => 'Internship', 'teaching' => 'Teaching', 'membership' => 'Membership', 'appreciation' => 'Appreciation', 'participation' => 'Participation'], $defaultType, true, '') ?></select></div>
                <div class="form-group mb-2"><label for="cert_title">Title</label><input id="cert_title" type="text" name="title" required maxlength="200" value="<?= e($defaultTitle) ?>"></div>
                <div class="form-group mb-2"><label for="cert_desc">Description</label><textarea id="cert_desc" name="description" maxlength="500" rows="2" style="min-height:70px" placeholder="e.g. for volunteering in the education programme from June to August 2026"></textarea></div>
                <div class="form-group mb-2"><label for="issue_date">Issue date</label><input id="issue_date" type="date" name="issue_date" required value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group mb-2"><label for="cert_file">Signed PDF (optional)</label><input id="cert_file" type="file" name="file" accept=".pdf" data-max-mb="<?= MAX_UPLOAD_MB ?>"><span class="help">If no PDF is uploaded, a printable certificate is generated.</span></div>
                <button class="btn btn-donate btn-block" type="submit"><?= icon('award') ?>Issue certificate</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

/** Applicant details as a definition list. */
function details_list(array $fields): void
{
    echo '<dl class="detail-list">';
    foreach ($fields as $label => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        echo '<dt>' . e($label) . '</dt><dd>' . (is_array($value) ? $value[0] : e_nl($value)) . '</dd>';
    }
    echo '</dl>';
}
