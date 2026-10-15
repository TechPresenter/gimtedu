<?php
/**
 * Admin helpers (no output). Staff accounts are ordinary users with a staff role
 * (Administrator, Content Editor, Campaign Manager, Donation Manager, Volunteer Coordinator).
 * Permissions are checked on the server with admin_can() / require_permission().
 */
if (!defined('PWF_APP')) {
    require_once dirname(__DIR__, 2) . '/functions.php';
}

/** The signed-in staff member (user row + 'role_name' + 'roles'), or null. */
function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) {
        return $admin;
    }
    $admin = null;
    $user = current_user();
    if (!$user || !is_staff((int) $user['id'])) {
        return null;
    }
    $roles = db_rows('SELECT r.name, r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.is_staff = 1 ORDER BY r.sort_order', [$user['id']]);
    $user['roles'] = array_column($roles, 'slug');
    $user['role_name'] = implode(', ', array_column($roles, 'name'));
    $admin = $user;
    return $admin;
}

function admin_id(): ?int
{
    $a = current_admin();
    return $a ? (int) $a['id'] : null;
}

/** Permission check for the signed-in staff member (administrators have every permission). */
function admin_can(string $permission): bool
{
    return current_admin() !== null && user_can($permission);
}

/** Record a staff action in the audit log. */
function admin_log(string $action, string $details = ''): void
{
    log_activity($action, $details, admin_id(), true);
}

/** URL inside the admin folder, e.g. admin_url('campaigns.php?status=active'). */
function admin_url(string $path = 'dashboard.php'): string
{
    return url('admin/' . ltrim($path, '/'));
}

function admin_redirect(string $path): void
{
    redirect('admin/' . ltrim($path, '/'));
}

/** Recruitment status flow (volunteer / teacher / staff / internship applications). */
const APPLICATION_STATUSES = ['submitted', 'under_review', 'info_requested', 'shortlisted', 'interview_scheduled', 'selected', 'onboarding', 'active', 'rejected', 'withdrawn'];

/** Status options used in admin filters and review forms. */
function application_statuses(string $type): array
{
    $map = [
        'volunteer' => APPLICATION_STATUSES, 'teacher' => APPLICATION_STATUSES, 'staff' => APPLICATION_STATUSES, 'internship' => APPLICATION_STATUSES,
        'beneficiaries' => ['pending', 'under_review', 'approved', 'assisted', 'rejected'],
        'scholarships' => ['pending', 'under_review', 'shortlisted', 'approved', 'rejected', 'disbursed'],
        'contact_enquiries' => ['new', 'in_progress', 'resolved', 'closed'],
        'feedback' => ['new', 'reviewed', 'actioned', 'closed'],
        'members' => ['pending', 'approved', 'rejected', 'suspended'],
        'data_requests' => ['pending', 'in_progress', 'completed', 'rejected'],
    ];
    return $map[$type] ?? APPLICATION_STATUSES;
}

/**
 * Date range from ?from=YYYY-MM-DD&to=YYYY-MM-DD or ?range=7d|30d|90d|12m|fy|all (default $default).
 * Returns ['from' => 'Y-m-d', 'to' => 'Y-m-d', 'label' => '...', 'key' => '30d'].
 */
function admin_date_range(string $default = '30d'): array
{
    $key = (string) ($_GET['range'] ?? $default);
    $to = date('Y-m-d');
    $from = (string) ($_GET['from'] ?? '');
    $toIn = (string) ($_GET['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIn) && $from <= $toIn) {
        return ['from' => $from, 'to' => $toIn, 'label' => format_date($from) . ' - ' . format_date($toIn), 'key' => 'custom'];
    }
    $ranges = [
        '7d' => ['-6 days', 'Last 7 days'], '30d' => ['-29 days', 'Last 30 days'], '90d' => ['-89 days', 'Last 90 days'],
        '12m' => ['-12 months', 'Last 12 months'],
    ];
    if ($key === 'fy') {
        $start = (int) date('n') >= 4 ? date('Y') . '-04-01' : (date('Y') - 1) . '-04-01';
        return ['from' => $start, 'to' => $to, 'label' => 'This financial year', 'key' => 'fy'];
    }
    if ($key === 'all') {
        return ['from' => '2000-01-01', 'to' => $to, 'label' => 'All time', 'key' => 'all'];
    }
    [$offset, $label] = $ranges[$key] ?? $ranges[$default] ?? $ranges['30d'];
    return ['from' => date('Y-m-d', strtotime($offset)), 'to' => $to, 'label' => $label, 'key' => isset($ranges[$key]) ? $key : $default];
}

/** Date-range picker form (keeps other GET parameters). */
function admin_range_picker(array $range): string
{
    $html = '<form class="filter-row range-picker" method="get">';
    foreach ($_GET as $k => $v) {
        if (!in_array($k, ['range', 'from', 'to', 'page'], true) && is_string($v)) {
            $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
    }
    $html .= '<select name="range" data-autosubmit aria-label="Period">';
    foreach (['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', '12m' => 'Last 12 months', 'fy' => 'This financial year', 'all' => 'All time', 'custom' => 'Custom dates'] as $k => $label) {
        $html .= '<option value="' . $k . '"' . ($range['key'] === $k ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $html .= '</select><input type="date" name="from" value="' . e($range['key'] === 'custom' ? $range['from'] : '') . '" aria-label="From">'
        . '<input type="date" name="to" value="' . e($range['key'] === 'custom' ? $range['to'] : '') . '" aria-label="To">'
        . '<button class="btn btn-ghost btn-sm" type="submit">' . icon('filter') . 'Apply</button></form>';
    return $html;
}

/** Simple bar chart: $data = ['label' => number, ...] (HTML/CSS only). */
function admin_bar_chart(array $data, bool $money = false): string
{
    if (!$data) {
        return '<p class="muted">No data for this period.</p>';
    }
    $max = max(1, max(array_map('floatval', $data)));
    $html = '<div class="chart-bars" role="img" aria-label="Bar chart">';
    foreach ($data as $label => $value) {
        $h = max(2, round((float) $value / $max * 100));
        $html .= '<div class="chart-bar"><em>' . e($money ? money($value) : number_format((float) $value)) . '</em><span class="bar" style="height:' . $h . '%"></span><small>' . e($label) . '</small></div>';
    }
    return $html . '</div>';
}

/** Admin page shell helpers. */
function admin_page_start(string $title, array $actions = []): void
{
    $GLOBALS['adminTitle'] = $title;
    require __DIR__ . '/header.php';
    if ($actions) {
        echo '<div class="page-actions"><div></div><div class="actions">';
        foreach ($actions as $a) {
            echo '<a class="btn btn-' . e($a['style'] ?? 'primary') . ' btn-sm" href="' . e($a['url']) . '">' . (!empty($a['icon']) ? icon($a['icon']) : '') . e($a['label']) . '</a>';
        }
        echo '</div></div>';
    }
}

function admin_page_end(): void
{
    require __DIR__ . '/footer.php';
}

/** Upload an image from $_FILES[$field] into uploads/<folder>/ and register it in the media library. Returns path or null (flashes errors). */
function admin_upload_image(string $field, string $folder, ?string $alt = null): ?string
{
    $up = handle_upload($field, 'image', $folder);
    if (!empty($up['error'])) {
        flash('error', $up['error']);
        return null;
    }
    if (empty($up['path'])) {
        return null;
    }
    $full = ROOT_PATH . '/' . $up['path'];
    $info = @getimagesize($full);
    try {
        db_insert('media', [
            'path' => $up['path'], 'original_name' => mb_substr((string) ($_FILES[$field]['name'] ?? ''), 0, 200),
            'mime_type' => $info['mime'] ?? null, 'file_size' => is_file($full) ? filesize($full) : null,
            'width' => $info[0] ?? null, 'height' => $info[1] ?? null, 'alt_text' => $alt, 'uploaded_by' => admin_id(),
        ]);
    } catch (Throwable $e) {
        error_log('Media register failed: ' . $e->getMessage());
    }
    return $up['path'];
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
