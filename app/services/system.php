<?php
/**
 * System administration services: settings schema & persistence, users/roles helpers, permission matrix,
 * activity & login statistics and the security checklist.
 *
 * Used by api/routes/{users,roles,settings,security,activity-logs}.php and app/modules/{users,roles,...}.php.
 */

require_once APP_ROOT . '/app/registry.php';

/* ------------------------------------------------------------------
 * Users & roles
 * ------------------------------------------------------------------ */

/** Number of active users holding a super-admin role (optionally ignoring one user). */
function system_super_admin_count(?int $exceptUserId = null): int
{
    $sql = "SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
            WHERE r.is_super = 1 AND u.status = 'active'";
    $args = [];
    if ($exceptUserId) {
        $sql .= ' AND u.id <> ?';
        $args[] = $exceptUserId;
    }
    return (int) db_value($sql, $args);
}

function system_user_is_super(int $userId): bool
{
    return (bool) db_value('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.is_super = 1', [$userId]);
}

function system_super_role_ids(): array
{
    return array_map('intval', db_column('SELECT id FROM roles WHERE is_super = 1'));
}

/** Normalise role ids from input (array, JSON or comma list) to unique ints. */
function system_role_ids_from_input($value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : explode(',', $value);
    }
    return array_values(array_unique(array_filter(array_map('intval', (array) $value))));
}

/** Throws CrudException when changing $userId would leave the institute without an active super admin. */
function system_guard_last_super_admin(int $userId, string $what): void
{
    if (system_user_is_super($userId) && system_super_admin_count($userId) === 0) {
        throw new CrudException("You cannot $what the last active Super Admin. Assign the Super Admin role to another active user first.");
    }
}

/** Random password that satisfies the configured policy. */
function system_generate_password(int $length = 12): string
{
    $length = max($length, (int) setting('password_min_length', 8) + 2);
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '@#$%&*!?'];
    $chars = [];
    foreach ($sets as $set) {
        $chars[] = $set[random_int(0, strlen($set) - 1)];
    }
    $all = implode('', $sets);
    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

/** Meaning of each permission action (shown in the roles matrix legend). */
function permission_action_meta(): array
{
    return [
        'view' => ['label' => 'View', 'description' => 'Open the module, see lists, dashboards and record details.'],
        'create' => ['label' => 'Create', 'description' => 'Add new records (e.g. a student, a payment, a notice).'],
        'edit' => ['label' => 'Edit', 'description' => 'Change existing records and their status.'],
        'delete' => ['label' => 'Delete', 'description' => 'Permanently remove records. Use sparingly.'],
        'export' => ['label' => 'Export', 'description' => 'Download CSV / Excel / PDF exports and print reports.'],
        'import' => ['label' => 'Import', 'description' => 'Bulk-upload records from CSV or Excel files.'],
        'approve' => ['label' => 'Approve', 'description' => 'Approve or reject workflow items (admissions, refunds, leave…).'],
        'publish' => ['label' => 'Publish', 'description' => 'Make content or results visible to students and the website.'],
        'manage' => ['label' => 'Manage', 'description' => 'Full control of the module — implies every other action.'],
    ];
}

/** Registry grouped for the matrix editor: [{group, modules: [{key, label, actions[]}]}] */
function permission_matrix_groups(): array
{
    $groups = [];
    foreach (permission_modules() as $key => $def) {
        $groups[$def['group']][] = ['key' => $key, 'label' => $def['label'], 'actions' => array_values($def['actions'])];
    }
    $out = [];
    foreach ($groups as $name => $modules) {
        $out[] = ['group' => $name, 'modules' => $modules];
    }
    return $out;
}

/** 'module.action' keys granted to a role. */
function role_permission_keys(int $roleId): array
{
    return db_column("SELECT CONCAT(p.module, '.', p.action) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.module, p.action", [$roleId]);
}

/**
 * Replace a role's permissions atomically. $keys = ['students.view', ...]. Returns [added, removed] key lists.
 * Unknown module/action pairs are rejected with an InvalidArgumentException.
 */
function role_save_permissions(int $roleId, array $keys): array
{
    $registry = permission_modules();
    $wanted = [];
    foreach ($keys as $k) {
        if (!is_string($k) || !str_contains($k, '.')) {
            throw new InvalidArgumentException('Invalid permission key.');
        }
        [$module, $action] = explode('.', $k, 2);
        if (!isset($registry[$module]) || !in_array($action, $registry[$module]['actions'], true)) {
            throw new InvalidArgumentException("Unknown permission: $k");
        }
        $wanted[$k] = true;
    }
    $before = role_permission_keys($roleId);
    db_transaction(function () use ($roleId, $wanted, $registry) {
        // Make sure every permission row exists (registry may have grown since the seed ran).
        foreach (array_keys($wanted) as $k) {
            [$module, $action] = explode('.', $k, 2);
            db_exec('INSERT IGNORE INTO permissions (module, action, label) VALUES (?, ?, ?)', [$module, $action, ucfirst($action) . ' ' . $registry[$module]['label']]);
        }
        db_exec('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
        if ($wanted) {
            $pairs = [];
            $args = [];
            foreach (array_keys($wanted) as $k) {
                [$module, $action] = explode('.', $k, 2);
                $pairs[] = '(p.module = ? AND p.action = ?)';
                $args[] = $module;
                $args[] = $action;
            }
            foreach (array_chunk(array_keys($pairs), 100) as $chunk) {
                $sqlPairs = [];
                $sqlArgs = [$roleId];
                foreach ($chunk as $i) {
                    $sqlPairs[] = $pairs[$i];
                    $sqlArgs[] = $args[$i * 2];
                    $sqlArgs[] = $args[$i * 2 + 1];
                }
                db_exec('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, p.id FROM permissions p WHERE ' . implode(' OR ', $sqlPairs), $sqlArgs);
            }
        }
    });
    $after = array_keys($wanted);
    return [array_values(array_diff($after, $before)), array_values(array_diff($before, $after))];
}

/* ------------------------------------------------------------------
 * Settings schema
 * ------------------------------------------------------------------ */

/**
 * Settings dashboard definition. Every key seeded in database/seed/01_core.php appears here, grouped as there.
 * Field: key, label, type (text|email|url|tel|number|select|toggle|color|textarea|password|image|checkboxes),
 *        rules (validate() rules), options, help, placeholder, col (1-12), secret, suffix
 */
function settings_schema(): array
{
    $yesNo = ['1' => 'Yes', '0' => 'No'];
    $timezones = ['Asia/Kolkata' => 'Asia/Kolkata (IST, UTC+05:30)', 'Asia/Dubai' => 'Asia/Dubai (UTC+04:00)', 'Asia/Singapore' => 'Asia/Singapore (UTC+08:00)',
        'Asia/Kathmandu' => 'Asia/Kathmandu (UTC+05:45)', 'Asia/Dhaka' => 'Asia/Dhaka (UTC+06:00)', 'Europe/London' => 'Europe/London (GMT/BST)',
        'America/New_York' => 'America/New_York (EST/EDT)', 'UTC' => 'UTC'];
    $dateFormats = [];
    foreach (['d M Y', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'M d, Y', 'd F Y'] as $f) {
        $dateFormats[$f] = date($f, strtotime('2026-10-07')) . '  (' . $f . ')';
    }
    $fonts = ['Plus Jakarta Sans' => 'Plus Jakarta Sans', 'Inter' => 'Inter', 'Poppins' => 'Poppins', 'Montserrat' => 'Montserrat', 'Roboto' => 'Roboto',
        'Open Sans' => 'Open Sans', 'Lato' => 'Lato', 'Merriweather' => 'Merriweather (serif)', 'Playfair Display' => 'Playfair Display (serif)'];
    $hex = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];

    return [
        'general' => [
            'title' => 'General', 'icon' => 'building-2', 'description' => 'Institute identity, contact details, academic session and regional formats.',
            'sections' => [
                ['key' => 'institute', 'title' => 'Institute information', 'description' => 'Shown on the website, letterheads, receipts and emails.', 'fields' => [
                    ['key' => 'institute_name', 'label' => 'Institute name', 'rules' => 'required|max:190', 'col' => 8],
                    ['key' => 'institute_short_name', 'label' => 'Short name', 'rules' => 'required|max:20', 'col' => 4, 'placeholder' => 'GIMT'],
                    ['key' => 'brand_name', 'label' => 'Brand name', 'rules' => 'max:60', 'col' => 4],
                    ['key' => 'tagline', 'label' => 'Tagline', 'rules' => 'max:150', 'col' => 8],
                    ['key' => 'established_year', 'label' => 'Established year', 'type' => 'number', 'rules' => 'integer|min:1800|max:2100', 'col' => 4],
                    ['key' => 'affiliation', 'label' => 'Affiliation / approvals', 'rules' => 'max:255', 'col' => 8],
                    ['key' => 'admissions_open_text', 'label' => 'Admissions banner text', 'rules' => 'max:120', 'col' => 12, 'help' => 'Shown in the website top bar and dashboard welcome card.'],
                ]],
                ['key' => 'session', 'title' => 'Academic session', 'description' => 'The current session drives fees, attendance, exams and reports.', 'type' => 'session', 'fields' => []],
                ['key' => 'contact', 'title' => 'Address & contact', 'description' => 'Primary contact details used across the system.', 'fields' => [
                    ['key' => 'address', 'label' => 'Address', 'type' => 'textarea', 'rules' => 'required|max:500', 'col' => 12],
                    ['key' => 'city', 'label' => 'City', 'rules' => 'max:80', 'col' => 4],
                    ['key' => 'state', 'label' => 'State', 'rules' => 'max:80', 'col' => 4],
                    ['key' => 'pincode', 'label' => 'PIN code', 'rules' => ['regex:/^[0-9A-Za-z \-]{3,10}$/'], 'col' => 4],
                    ['key' => 'country', 'label' => 'Country', 'rules' => 'max:80', 'col' => 4],
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'rules' => 'required|phone', 'col' => 4],
                    ['key' => 'phone_alt', 'label' => 'Alternate phone', 'type' => 'tel', 'rules' => 'phone', 'col' => 4],
                    ['key' => 'email', 'label' => 'General email', 'type' => 'email', 'rules' => 'required|email', 'col' => 4],
                    ['key' => 'admission_email', 'label' => 'Admissions email', 'type' => 'email', 'rules' => 'email', 'col' => 4],
                    ['key' => 'support_email', 'label' => 'Support email', 'type' => 'email', 'rules' => 'email', 'col' => 4],
                    ['key' => 'website', 'label' => 'Website', 'rules' => 'max:120', 'col' => 6, 'placeholder' => 'www.gimt.ac.in'],
                    ['key' => 'office_hours', 'label' => 'Office hours', 'rules' => 'max:120', 'col' => 6],
                ]],
                ['key' => 'location', 'title' => 'Map & location', 'description' => 'Used by the contact page map and directions links.', 'fields' => [
                    ['key' => 'map_embed_url', 'label' => 'Google Maps embed URL', 'type' => 'url', 'rules' => 'url|max:500', 'col' => 12],
                    ['key' => 'latitude', 'label' => 'Latitude', 'rules' => 'numeric|min:-90|max:90', 'col' => 6],
                    ['key' => 'longitude', 'label' => 'Longitude', 'rules' => 'numeric|min:-180|max:180', 'col' => 6],
                ]],
                ['key' => 'regional', 'title' => 'Regional & formats', 'description' => 'Date, time zone and currency preferences.', 'fields' => [
                    ['key' => 'date_format', 'label' => 'Date format', 'type' => 'select', 'options' => $dateFormats, 'rules' => 'required', 'col' => 6],
                    ['key' => 'timezone', 'label' => 'Time zone', 'type' => 'select', 'options' => $timezones, 'rules' => 'required', 'col' => 6],
                    ['key' => 'currency_code', 'label' => 'Currency', 'type' => 'select', 'options' => ['INR' => 'INR — Indian Rupee', 'USD' => 'USD — US Dollar', 'AED' => 'AED — UAE Dirham', 'NPR' => 'NPR — Nepalese Rupee'], 'rules' => 'required', 'col' => 6],
                    ['key' => 'currency_symbol', 'label' => 'Currency symbol', 'rules' => 'required|max:5', 'col' => 6],
                ]],
            ],
        ],
        'website' => [
            'title' => 'Website', 'icon' => 'globe', 'description' => 'Logo, favicon, brand colours, typography, header and footer of the public website.',
            'sections' => [
                ['key' => 'branding', 'title' => 'Logo & favicon', 'description' => 'PNG, JPG or WEBP. Transparent PNG recommended for logos.', 'fields' => [
                    ['key' => 'logo', 'label' => 'Logo (light backgrounds)', 'type' => 'image', 'col' => 4, 'default_preview' => 'assets/images/logo.svg'],
                    ['key' => 'logo_white', 'label' => 'Logo (dark backgrounds)', 'type' => 'image', 'col' => 4, 'default_preview' => 'assets/images/logo-white.svg', 'dark' => true],
                    ['key' => 'favicon', 'label' => 'Favicon', 'type' => 'image', 'col' => 4, 'default_preview' => 'assets/images/favicon.svg', 'help' => 'Square image, at least 64×64 px.'],
                ]],
                ['key' => 'colors', 'title' => 'Brand colours & typography', 'description' => 'Applied to buttons, headings and highlights on the website.', 'fields' => [
                    ['key' => 'primary_color', 'label' => 'Primary (navy)', 'type' => 'color', 'rules' => $hex, 'col' => 4],
                    ['key' => 'accent_color', 'label' => 'Accent (green CTA)', 'type' => 'color', 'rules' => $hex, 'col' => 4],
                    ['key' => 'secondary_color', 'label' => 'Secondary (blue)', 'type' => 'color', 'rules' => $hex, 'col' => 4],
                    ['key' => 'heading_font', 'label' => 'Heading font', 'type' => 'select', 'options' => $fonts, 'rules' => 'required', 'col' => 6],
                    ['key' => 'body_font', 'label' => 'Body font', 'type' => 'select', 'options' => $fonts, 'rules' => 'required', 'col' => 6],
                ]],
                ['key' => 'header', 'title' => 'Header', 'description' => 'Top bar and call-to-action button.', 'fields' => [
                    ['key' => 'header_cta_text', 'label' => 'CTA button text', 'rules' => 'required|max:40', 'col' => 6],
                    ['key' => 'header_cta_url', 'label' => 'CTA button link', 'type' => 'url', 'rules' => 'required|url|max:255', 'col' => 6, 'placeholder' => '/apply'],
                    ['key' => 'whatsapp_number', 'label' => 'WhatsApp number', 'rules' => ['regex:/^[0-9]{8,15}$/'], 'col' => 6, 'help' => 'Digits only with country code, e.g. 919955446477.'],
                    ['key' => 'show_topbar', 'label' => 'Show top information bar', 'type' => 'toggle', 'col' => 6, 'help' => 'Admissions text, helpline and login links above the header.'],
                ]],
                ['key' => 'footer', 'title' => 'Footer', 'description' => 'About text and copyright line. Use {year} for the current year.', 'fields' => [
                    ['key' => 'footer_about', 'label' => 'About text', 'type' => 'textarea', 'rules' => 'max:600', 'col' => 12],
                    ['key' => 'copyright_text', 'label' => 'Copyright text', 'rules' => 'max:255', 'col' => 12],
                ]],
                ['key' => 'maintenance', 'title' => 'Maintenance mode', 'description' => 'Temporarily show a maintenance page to website visitors. The admin panel stays available.', 'fields' => [
                    ['key' => 'maintenance_mode', 'label' => 'Enable maintenance mode', 'type' => 'toggle', 'col' => 12, 'danger' => true],
                ]],
            ],
        ],
        'academic' => [
            'title' => 'Academic', 'icon' => 'graduation-cap', 'description' => 'Semester system, grading, passing criteria, attendance rules and ID formats.',
            'sections' => [
                ['key' => 'structure', 'title' => 'Structure & grading', 'description' => 'How terms are organised and results are computed.', 'fields' => [
                    ['key' => 'semester_system', 'label' => 'Term system', 'type' => 'select', 'options' => ['semester' => 'Semester (2 per year)', 'trimester' => 'Trimester (3 per year)', 'annual' => 'Annual'], 'rules' => 'required', 'col' => 6],
                    ['key' => 'grading_system', 'label' => 'Grading system', 'type' => 'select', 'options' => ['cgpa_10' => '10-point CGPA (CBCS)', 'cgpa_4' => '4-point GPA', 'percentage' => 'Percentage & division'], 'rules' => 'required', 'col' => 6],
                    ['key' => 'passing_percentage', 'label' => 'Passing percentage', 'type' => 'number', 'rules' => 'required|numeric|min:0|max:100', 'col' => 6, 'suffix' => '%'],
                    ['key' => 'max_sgpa', 'label' => 'Maximum SGPA / CGPA', 'type' => 'number', 'rules' => 'required|numeric|min:1|max:100', 'col' => 6],
                ]],
                ['key' => 'attendance', 'title' => 'Attendance rules', 'description' => 'Eligibility and low-attendance alerts.', 'fields' => [
                    ['key' => 'min_attendance_percent', 'label' => 'Minimum attendance for exams', 'type' => 'number', 'rules' => 'required|numeric|min:0|max:100', 'col' => 6, 'suffix' => '%'],
                    ['key' => 'attendance_alert_threshold', 'label' => 'Low attendance alert below', 'type' => 'number', 'rules' => 'required|numeric|min:0|max:100', 'col' => 6, 'suffix' => '%'],
                    ['key' => 'late_counts_as_present', 'label' => 'Count "late" as present', 'type' => 'toggle', 'col' => 12],
                ]],
                ['key' => 'ids', 'title' => 'ID & number formats', 'description' => 'Placeholders: {program}, {year}, {n:3} (running number with 3 digits).', 'fields' => [
                    ['key' => 'student_id_prefix', 'label' => 'Student ID prefix', 'rules' => 'required|alpha_dash|max:12', 'col' => 6],
                    ['key' => 'roll_no_format', 'label' => 'Roll number format', 'rules' => 'required|max:60', 'col' => 6],
                ]],
            ],
        ],
        'email' => [
            'title' => 'Email & SMS', 'icon' => 'mail', 'description' => 'Outgoing mail server, sender identity and SMS / WhatsApp gateways.',
            'sections' => [
                ['key' => 'mail', 'title' => 'Mail delivery (SMTP)', 'description' => 'Used for password resets, receipts, notices and newsletters.', 'test' => 'email', 'fields' => [
                    ['key' => 'mail_driver', 'label' => 'Mail driver', 'type' => 'select', 'options' => ['smtp' => 'SMTP server', 'mail' => 'PHP mail()', 'log' => 'Log only (no delivery)'], 'rules' => 'required', 'col' => 4],
                    ['key' => 'smtp_host', 'label' => 'SMTP host', 'rules' => 'max:190', 'col' => 5, 'placeholder' => 'smtp.gmail.com'],
                    ['key' => 'smtp_port', 'label' => 'Port', 'type' => 'number', 'rules' => 'integer|min:1|max:65535', 'col' => 3],
                    ['key' => 'smtp_encryption', 'label' => 'Encryption', 'type' => 'select', 'options' => ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None'], 'rules' => 'required', 'col' => 4],
                    ['key' => 'smtp_username', 'label' => 'SMTP username', 'rules' => 'max:190', 'col' => 4],
                    ['key' => 'smtp_password', 'label' => 'SMTP password', 'type' => 'password', 'secret' => true, 'rules' => 'max:255', 'col' => 4],
                    ['key' => 'mail_from_email', 'label' => 'From email', 'type' => 'email', 'rules' => 'required|email', 'col' => 6],
                    ['key' => 'mail_from_name', 'label' => 'From name', 'rules' => 'required|max:120', 'col' => 6],
                ]],
                ['key' => 'sms', 'title' => 'SMS gateway', 'description' => 'HTTP gateway. URL placeholders: {phone}, {message}, {sender}. API key is sent as a Bearer token.', 'test' => 'sms', 'fields' => [
                    ['key' => 'sms_enabled', 'label' => 'Enable SMS', 'type' => 'toggle', 'col' => 12],
                    ['key' => 'sms_api_url', 'label' => 'API URL', 'type' => 'url', 'rules' => 'url|max:500', 'col' => 8],
                    ['key' => 'sms_method', 'label' => 'HTTP method', 'type' => 'select', 'options' => ['POST' => 'POST', 'GET' => 'GET'], 'rules' => 'required', 'col' => 4],
                    ['key' => 'sms_api_key', 'label' => 'API key', 'type' => 'password', 'secret' => true, 'rules' => 'max:255', 'col' => 6],
                    ['key' => 'sms_sender_id', 'label' => 'Sender ID', 'rules' => 'max:20', 'col' => 6],
                ]],
                ['key' => 'whatsapp', 'title' => 'WhatsApp gateway', 'description' => 'WhatsApp Business API provider (integration-ready).', 'test' => 'whatsapp', 'fields' => [
                    ['key' => 'whatsapp_enabled', 'label' => 'Enable WhatsApp', 'type' => 'toggle', 'col' => 12],
                    ['key' => 'whatsapp_api_url', 'label' => 'API URL', 'type' => 'url', 'rules' => 'url|max:500', 'col' => 8],
                    ['key' => 'whatsapp_method', 'label' => 'HTTP method', 'type' => 'select', 'options' => ['POST' => 'POST', 'GET' => 'GET'], 'rules' => 'required', 'col' => 4],
                    ['key' => 'whatsapp_api_key', 'label' => 'API key / token', 'type' => 'password', 'secret' => true, 'rules' => 'max:255', 'col' => 6],
                    ['key' => 'whatsapp_sender_id', 'label' => 'Sender number / ID', 'rules' => 'max:30', 'col' => 6],
                ]],
            ],
        ],
        'payment' => [
            'title' => 'Payment', 'icon' => 'credit-card', 'description' => 'Online payment gateway, taxes, receipt and invoice numbering.',
            'sections' => [
                ['key' => 'gateway', 'title' => 'Payment gateway', 'description' => 'Keys are stored server-side and never shown again after saving.', 'fields' => [
                    ['key' => 'payment_gateway', 'label' => 'Gateway', 'type' => 'select', 'options' => ['razorpay' => 'Razorpay', 'payu' => 'PayU', 'ccavenue' => 'CCAvenue', 'stripe' => 'Stripe', 'none' => 'Offline only'], 'rules' => 'required', 'col' => 6],
                    ['key' => 'gateway_mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['test' => 'Test / sandbox', 'live' => 'Live'], 'rules' => 'required', 'col' => 6],
                    ['key' => 'gateway_key_id', 'label' => 'Key ID / merchant ID', 'rules' => 'max:190', 'col' => 6],
                    ['key' => 'gateway_key_secret', 'label' => 'Key secret', 'type' => 'password', 'secret' => true, 'rules' => 'max:255', 'col' => 6],
                    ['key' => 'enabled_payment_modes', 'label' => 'Accepted payment modes', 'type' => 'checkboxes', 'col' => 12, 'rules' => 'required',
                        'options' => ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer / NEFT', 'cheque' => 'Cheque', 'dd' => 'Demand draft', 'upi' => 'UPI', 'card' => 'Card (POS)', 'online' => 'Online gateway']],
                ]],
                ['key' => 'tax', 'title' => 'Tax & late fee', 'description' => 'Applied when invoices are generated.', 'fields' => [
                    ['key' => 'tax_percent', 'label' => 'Tax (GST) on fees', 'type' => 'number', 'rules' => 'required|numeric|min:0|max:50', 'col' => 4, 'suffix' => '%'],
                    ['key' => 'gstin', 'label' => 'GSTIN', 'rules' => ['regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/'], 'col' => 4, 'placeholder' => '09ABCDE1234F1Z5'],
                    ['key' => 'late_fee_per_day', 'label' => 'Late fee per day', 'type' => 'number', 'rules' => 'required|numeric|min:0|max:100000', 'col' => 4, 'prefix' => '₹'],
                ]],
                ['key' => 'numbering', 'title' => 'Receipts & invoices', 'description' => 'Placeholders: {session}, {Y}, {n:6}.', 'fields' => [
                    ['key' => 'receipt_prefix', 'label' => 'Receipt number format', 'rules' => 'required|max:60', 'col' => 6],
                    ['key' => 'invoice_prefix', 'label' => 'Invoice number format', 'rules' => 'required|max:60', 'col' => 6],
                    ['key' => 'receipt_footer_note', 'label' => 'Receipt footer note', 'type' => 'textarea', 'rules' => 'max:300', 'col' => 12],
                ]],
            ],
        ],
        'security' => [
            'title' => 'Security', 'icon' => 'shield-check', 'description' => 'Password policy, login protection, sessions and upload limits.',
            'sections' => [
                ['key' => 'password', 'title' => 'Password policy', 'description' => 'Enforced whenever a password is created or changed.', 'fields' => [
                    ['key' => 'password_min_length', 'label' => 'Minimum length', 'type' => 'number', 'rules' => 'required|integer|min:6|max:64', 'col' => 6, 'suffix' => 'chars'],
                    ['key' => 'password_expiry_days', 'label' => 'Password expiry', 'type' => 'number', 'rules' => 'required|integer|min:0|max:365', 'col' => 6, 'suffix' => 'days', 'help' => '0 = never expires.'],
                    ['key' => 'password_require_uppercase', 'label' => 'Require an uppercase letter', 'type' => 'toggle', 'col' => 4],
                    ['key' => 'password_require_number', 'label' => 'Require a number', 'type' => 'toggle', 'col' => 4],
                    ['key' => 'password_require_special', 'label' => 'Require a special character', 'type' => 'toggle', 'col' => 4],
                ]],
                ['key' => 'login', 'title' => 'Login protection', 'description' => 'Brute-force protection for accounts and IP addresses.', 'fields' => [
                    ['key' => 'max_login_attempts', 'label' => 'Failed attempts before lockout', 'type' => 'number', 'rules' => 'required|integer|min:1|max:20', 'col' => 4],
                    ['key' => 'lockout_minutes', 'label' => 'Lockout duration', 'type' => 'number', 'rules' => 'required|integer|min:1|max:1440', 'col' => 4, 'suffix' => 'min'],
                    ['key' => 'max_ip_attempts', 'label' => 'Max failed attempts per IP', 'type' => 'number', 'rules' => 'required|integer|min:3|max:500', 'col' => 4],
                    ['key' => 'two_factor_enabled', 'label' => 'Two-factor authentication (2FA-ready)', 'type' => 'toggle', 'col' => 6, 'help' => 'Prepares accounts for TOTP 2FA enrolment.'],
                    ['key' => 'trust_proxy_headers', 'label' => 'Trust X-Forwarded-For (behind a proxy / CDN)', 'type' => 'toggle', 'col' => 6, 'help' => 'Enable only when the site is behind a trusted reverse proxy.'],
                ]],
                ['key' => 'sessions', 'title' => 'Sessions', 'description' => 'Idle timeout and remember-me duration.', 'fields' => [
                    ['key' => 'session_timeout', 'label' => 'Idle session timeout', 'type' => 'number', 'rules' => 'required|integer|min:5|max:720', 'col' => 6, 'suffix' => 'min'],
                    ['key' => 'remember_me_days', 'label' => '"Remember me" duration', 'type' => 'number', 'rules' => 'required|integer|min:1|max:365', 'col' => 6, 'suffix' => 'days'],
                ]],
                ['key' => 'uploads', 'title' => 'File uploads', 'description' => 'Maximum sizes for uploaded files.', 'fields' => [
                    ['key' => 'max_upload_mb', 'label' => 'Max image / document size', 'type' => 'number', 'rules' => 'required|numeric|min:1|max:100', 'col' => 6, 'suffix' => 'MB'],
                    ['key' => 'max_video_upload_mb', 'label' => 'Max video size', 'type' => 'number', 'rules' => 'required|numeric|min:1|max:1024', 'col' => 6, 'suffix' => 'MB'],
                ]],
            ],
        ],
        'backup' => [
            'title' => 'Backup', 'icon' => 'database', 'description' => 'Automatic backup schedule and retention.',
            'sections' => [
                ['key' => 'schedule', 'title' => 'Backup schedule & retention', 'description' => 'Automatic backups run from the scheduled task shown on the Backup page.', 'type' => 'backup', 'fields' => [
                    ['key' => 'auto_backup', 'label' => 'Automatic database backup', 'type' => 'select', 'options' => ['off' => 'Off', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'], 'rules' => 'required', 'col' => 6],
                    ['key' => 'backup_retention', 'label' => 'Keep backups for', 'type' => 'number', 'rules' => 'required|integer|min:0|max:3650', 'col' => 6, 'suffix' => 'days', 'help' => '0 = keep forever. The 3 most recent backups are always kept.'],
                ]],
            ],
        ],
        'analytics' => [
            'title' => 'Analytics', 'icon' => 'chart-line', 'description' => 'Tracking and verification codes injected into the public website.',
            'sections' => [
                ['key' => 'tracking', 'title' => 'Tracking codes', 'description' => 'Leave blank to disable a service.', 'fields' => [
                    ['key' => 'google_analytics_id', 'label' => 'Google Analytics 4 measurement ID', 'rules' => ['regex:/^(G|UA)-[A-Z0-9\-]{4,20}$/'], 'col' => 6, 'placeholder' => 'G-XXXXXXXXXX'],
                    ['key' => 'gtm_id', 'label' => 'Google Tag Manager ID', 'rules' => ['regex:/^GTM-[A-Z0-9]{4,12}$/'], 'col' => 6, 'placeholder' => 'GTM-XXXXXXX'],
                    ['key' => 'search_console_verification', 'label' => 'Search Console verification code', 'rules' => ['regex:/^[A-Za-z0-9_\-]{10,100}$/'], 'col' => 6, 'help' => 'Content value of the google-site-verification meta tag.'],
                    ['key' => 'meta_pixel_id', 'label' => 'Meta (Facebook) Pixel ID', 'rules' => ['regex:/^[0-9]{6,20}$/'], 'col' => 6],
                ]],
            ],
        ],
        'social' => [
            'title' => 'Social', 'icon' => 'share-2', 'description' => 'Social profile links shown in the website header and footer.',
            'sections' => [
                ['key' => 'profiles', 'title' => 'Social profiles', 'description' => 'Full URLs including https://. Leave blank to hide an icon.', 'fields' => [
                    ['key' => 'facebook_url', 'label' => 'Facebook', 'type' => 'url', 'rules' => ['url', 'max:255', 'regex:/^https?:\/\//'], 'col' => 6],
                    ['key' => 'instagram_url', 'label' => 'Instagram', 'type' => 'url', 'rules' => ['url', 'max:255', 'regex:/^https?:\/\//'], 'col' => 6],
                    ['key' => 'linkedin_url', 'label' => 'LinkedIn', 'type' => 'url', 'rules' => ['url', 'max:255', 'regex:/^https?:\/\//'], 'col' => 6],
                    ['key' => 'youtube_url', 'label' => 'YouTube', 'type' => 'url', 'rules' => ['url', 'max:255', 'regex:/^https?:\/\//'], 'col' => 6],
                    ['key' => 'x_url', 'label' => 'X (Twitter)', 'type' => 'url', 'rules' => ['url', 'max:255', 'regex:/^https?:\/\//'], 'col' => 6],
                ]],
            ],
        ],
    ];
}

/** Flat key => field definition (with 'group') for one group or every group. */
function settings_fields(?string $group = null): array
{
    $out = [];
    foreach (settings_schema() as $g => $def) {
        if ($group !== null && $g !== $group) {
            continue;
        }
        foreach ($def['sections'] as $section) {
            foreach ($section['fields'] as $f) {
                $out[$f['key']] = $f + ['group' => $g, 'type' => 'text', 'section' => $section['key']];
            }
        }
    }
    return $out;
}

/** Keys that look like secrets (never echoed back). */
function settings_is_secret_key(string $key): bool
{
    return (bool) preg_match('/(password|secret|api_key|token|private_key)/i', $key);
}

/**
 * Keys stored in a group that are not part of the schema (added by other modules). They are editable as
 * plain text fields in an "Additional settings" section. Internal keys (leading underscore) are hidden.
 */
function settings_extra_keys(string $group): array
{
    $known = array_keys(settings_fields());
    $rows = db_all('SELECT `key`, `value` FROM settings WHERE `group` = ? ORDER BY `key`', [$group]);
    $out = [];
    foreach ($rows as $r) {
        if (in_array($r['key'], $known, true) || str_starts_with($r['key'], '_')) {
            continue;
        }
        $out[$r['key']] = $r['value'];
    }
    return $out;
}

/** Full payload for the settings dashboard (values masked for secrets). */
function settings_payload(): array
{
    $all = settings_all(true);
    $groups = [];
    foreach (settings_schema() as $g => $def) {
        $values = [];
        $secrets = [];
        foreach ($def['sections'] as $si => $section) {
            foreach ($section['fields'] as $fi => $f) {
                $type = $f['type'] ?? 'text';
                $v = $all[$f['key']] ?? '';
                if (!empty($f['secret'])) {
                    $secrets[$f['key']] = $v !== '' && $v !== null;
                    $v = '';
                }
                if (isset($f['options'])) {
                    $def['sections'][$si]['fields'][$fi]['options'] = array_map(fn ($k, $l) => ['value' => (string) $k, 'label' => $l], array_keys($f['options']), $f['options']);
                }
                if ($type === 'image') {
                    $def['sections'][$si]['fields'][$fi]['accept'] = '.png,.jpg,.jpeg,.webp';
                    $def['sections'][$si]['fields'][$fi]['max_size'] = upload_max_bytes('image');
                }
                unset($def['sections'][$si]['fields'][$fi]['rules']);
                $def['sections'][$si]['fields'][$fi]['required'] = str_contains(json_encode($f['rules'] ?? ''), 'required');
                $values[$f['key']] = (string) ($v ?? '');
            }
        }
        $extra = settings_extra_keys($g);
        if ($extra) {
            $fields = [];
            foreach ($extra as $k => $v) {
                $secret = settings_is_secret_key($k);
                $fields[] = ['key' => $k, 'label' => label_from_key($k), 'type' => $secret ? 'password' : 'text', 'secret' => $secret, 'col' => 6, 'required' => false];
                if ($secret) {
                    $secrets[$k] = $v !== '' && $v !== null;
                    $v = '';
                }
                $values[$k] = (string) ($v ?? '');
            }
            $def['sections'][] = ['key' => 'extra', 'title' => 'Additional settings', 'description' => 'Settings added by other modules in this group.', 'fields' => $fields];
        }
        $groups[] = ['key' => $g, 'title' => $def['title'], 'icon' => $def['icon'], 'description' => $def['description'], 'sections' => $def['sections'], 'values' => $values, 'secrets' => $secrets];
    }
    return $groups;
}

/**
 * Validate and save a subset of a group's settings.
 * $input: key => value (only keys sent are saved). $files: $_FILES for image fields. "<key>__remove" clears an image,
 * "__clear" => [secret keys] clears stored secrets.
 * @return array saved keys
 * @throws CrudValidationException
 */
function settings_save_group(string $group, array $input, array $files = []): array
{
    $schema = settings_schema();
    if (!isset($schema[$group])) {
        throw new InvalidArgumentException('Unknown settings group.');
    }
    $fields = settings_fields($group);
    foreach (settings_extra_keys($group) as $k => $v) {
        $secret = settings_is_secret_key($k);
        $fields[$k] = ['key' => $k, 'label' => label_from_key($k), 'type' => $secret ? 'password' : 'text', 'secret' => $secret, 'rules' => 'max:2000', 'group' => $group];
    }
    $errors = [];
    $save = [];
    $clear = array_filter((array) ($input['__clear'] ?? []), 'is_string');
    $oldFiles = [];
    foreach ($fields as $key => $f) {
        $type = $f['type'] ?? 'text';
        $label = $f['label'];
        if ($type === 'image') {
            $file = $files[$key] ?? null;
            if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $res = upload_file($file, 'image', 'settings');
                if (!$res['ok']) {
                    $errors[$key] = $res['error'];
                    continue;
                }
                if ($key === 'favicon' && (($res['width'] ?? 0) < 16 || ($res['height'] ?? 0) < 16)) {
                    delete_upload($res['path']);
                    $errors[$key] = 'Favicon must be at least 16×16 pixels.';
                    continue;
                }
                $save[$key] = $res['path'];
                $oldFiles[] = setting($key);
            } elseif (!empty($input[$key . '__remove'])) {
                $save[$key] = '';
                $oldFiles[] = setting($key);
            }
            continue;
        }
        if (!empty($f['secret']) && in_array($key, $clear, true)) {
            $save[$key] = '';
            continue;
        }
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = $input[$key];
        if ($type === 'checkboxes') {
            $arr = is_array($value) ? $value : array_filter(explode(',', (string) $value));
            $allowed = array_map('strval', array_keys($f['options'] ?? []));
            $arr = array_values(array_unique(array_map('strval', $arr)));
            if (array_diff($arr, $allowed)) {
                $errors[$key] = "Select valid $label.";
                continue;
            }
            if (!$arr && str_contains((string) json_encode($f['rules'] ?? ''), 'required')) {
                $errors[$key] = "Select at least one option for $label.";
                continue;
            }
            $save[$key] = implode(',', $arr);
            continue;
        }
        if ($type === 'toggle') {
            $save[$key] = in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true) ? '1' : '0';
            continue;
        }
        if (is_array($value)) {
            $errors[$key] = "$label is invalid.";
            continue;
        }
        $value = trim((string) $value);
        if (!empty($f['secret'])) {
            if ($value === '') {
                continue; // blank secret = keep the stored value
            }
        }
        if ($type === 'color' && $value !== '') {
            $value = strtoupper($value);
        }
        $rules = $f['rules'] ?? [];
        $rules = is_array($rules) ? $rules : array_filter(explode('|', (string) $rules));
        if (isset($f['options'])) {
            $rules[] = 'in:' . implode(',', array_map('strval', array_keys($f['options'])));
        }
        if ($rules) {
            $e = validate([$key => $value], [$key => $rules], [$key => $label]);
            if ($e) {
                $errors[$key] = $e[$key];
                continue;
            }
        }
        $save[$key] = $value;
    }
    // Cross-field rules
    if ($group === 'email' && ($save['mail_driver'] ?? null) === 'smtp' && ($save['smtp_host'] ?? setting('smtp_host', '')) === '') {
        $errors['smtp_host'] = 'SMTP host is required when the SMTP driver is selected.';
    }
    foreach (['sms', 'whatsapp'] as $ch) {
        if (($save[$ch . '_enabled'] ?? null) === '1' && ($save[$ch . '_api_url'] ?? setting($ch . '_api_url', '')) === '') {
            $errors[$ch . '_api_url'] = 'API URL is required when the gateway is enabled.';
        }
    }
    if ($group === 'academic' && isset($save['attendance_alert_threshold'], $save['min_attendance_percent']) && (float) $save['attendance_alert_threshold'] < (float) $save['min_attendance_percent']) {
        $errors['attendance_alert_threshold'] = 'Alert threshold should not be lower than the minimum attendance.';
    }
    if ($errors) {
        foreach ($save as $k => $v) {
            if (($fields[$k]['type'] ?? '') === 'image' && $v) {
                delete_upload($v);
            }
        }
        throw new CrudValidationException($errors);
    }
    db_transaction(function () use ($save, $group) {
        foreach ($save as $k => $v) {
            save_setting($k, $v, $group);
        }
    });
    foreach ($oldFiles as $p) {
        if ($p && str_starts_with((string) $p, 'assets/uploads/')) {
            delete_upload($p);
        }
    }
    settings_all(true);
    return array_keys($save);
}

/* ------------------------------------------------------------------
 * Activity & login statistics
 * ------------------------------------------------------------------ */

/** Human labels for module keys found in activity logs. */
function activity_module_labels(): array
{
    $labels = ['auth' => 'Authentication', 'system' => 'System', 'backup' => 'Backup & Restore', 'settings' => 'Settings'];
    foreach (permission_modules() as $k => $def) {
        $labels[$k] = preg_replace('/\s*\(.*\)$/', '', $def['label']);
    }
    return $labels;
}

/** Daily counts for the last $days days: ['labels' => [...], 'series' => [name => [...]]] */
function system_daily_series(string $table, array $statuses, int $days = 14, string $where = '1=1', array $args = []): array
{
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $rows = db_all("SELECT DATE(created_at) d, status, COUNT(*) c FROM `$table` WHERE created_at >= ? AND $where GROUP BY DATE(created_at), status", array_merge([$from], $args));
    $map = [];
    foreach ($rows as $r) {
        $map[$r['d']][$r['status']] = (int) $r['c'];
    }
    $labels = [];
    $series = [];
    foreach ($statuses as $name => $list) {
        $series[$name] = [];
    }
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime("$from +$i days"));
        $labels[] = date('d M', strtotime($d));
        foreach ($statuses as $name => $list) {
            $sum = 0;
            foreach ((array) $list as $s) {
                $sum += $map[$d][$s] ?? 0;
            }
            $series[$name][] = $sum;
        }
    }
    return ['labels' => $labels, 'series' => $series];
}

/* ------------------------------------------------------------------
 * Security checklist
 * ------------------------------------------------------------------ */

/** Does any active user (matching $sql filter) still use $password? Returns matching user names (max 50 checked). */
function system_users_with_password(string $password, string $extraWhere = '1=1'): array
{
    $users = db_all("SELECT u.id, u.name, u.password_hash FROM users u WHERE u.status = 'active' AND $extraWhere ORDER BY u.id LIMIT 50");
    // bcrypt checks are slow: cache results keyed by the hashes (any password change invalidates the entry).
    $cacheFile = STORAGE_PATH . '/cache/security-pw-' . substr(hash('sha256', $password . '|' . app_config('app')['key']), 0, 12) . '.json';
    $cache = is_file($cacheFile) ? (json_decode((string) @file_get_contents($cacheFile), true) ?: []) : [];
    $out = [];
    $fresh = [];
    foreach ($users as $u) {
        $sig = hash('sha256', $u['id'] . ':' . $u['password_hash']);
        $match = $cache[$sig] ?? password_verify($password, $u['password_hash']);
        $fresh[$sig] = $match;
        if ($match) {
            $out[] = $u['name'];
        }
    }
    if (array_diff_key($fresh, $cache)) {
        @file_put_contents($cacheFile, json_encode(array_slice($fresh + $cache, 0, 500, true)), LOCK_EX);
    }
    return $out;
}

/** Recursively look for executable scripts inside the public uploads directory (bounded scan). */
function system_find_upload_scripts(int $limit = 5000): array
{
    $found = [];
    if (!is_dir(UPLOAD_PATH)) {
        return $found;
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOAD_PATH, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (++$n > $limit) {
            break;
        }
        if ($file->isFile() && preg_match('/\.(php\d?|phtml|phar|pl|py|cgi|sh|asp|aspx|jsp)$/i', $file->getFilename())) {
            $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen(APP_ROOT) + 1));
        }
    }
    return $found;
}

/**
 * Security checklist items: [key, title, status pass|warn|fail|info, detail, action{label,to}?]
 */
function security_checklist(): array
{
    $items = [];
    $add = function (string $key, string $title, string $status, string $detail, ?array $action = null) use (&$items) {
        $items[] = ['key' => $key, 'title' => $title, 'status' => $status, 'detail' => $detail, 'action' => $action];
    };

    $superIds = 'u.id IN (SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.is_super = 1)';
    $defaultAdmin = system_users_with_password('Admin@12345', $superIds);
    $add('default_admin_password', 'Default administrator password changed', $defaultAdmin ? 'fail' : 'pass',
        $defaultAdmin ? 'The installer default password is still in use for: ' . implode(', ', $defaultAdmin) . '. Change it immediately.' : 'No Super Admin account uses the installer default password.',
        $defaultAdmin ? ['label' => 'Manage users', 'to' => '/users'] : null);

    $demo = system_users_with_password('Demo@12345');
    $add('demo_passwords', 'Demo account passwords removed', $demo ? 'warn' : 'pass',
        $demo ? count($demo) . ' active account(s) still use the shared demo password (e.g. ' . implode(', ', array_slice($demo, 0, 3)) . '). Reset them or deactivate demo accounts before going live.' : 'No active account uses the demo password.',
        $demo ? ['label' => 'Review users', 'to' => '/users'] : null);

    $dev = is_dev();
    $add('debug_mode', 'Production mode (debug off)', $dev ? 'warn' : (ini_get('display_errors') && ini_get('display_errors') !== '0' && strtolower((string) ini_get('display_errors')) !== 'off' ? 'warn' : 'pass'),
        $dev ? 'Development mode is enabled: PHP errors are shown and outgoing email is only written to storage/logs/mail.log. Set app.env = "production" in config/local.php.' : 'Running in production mode with error display disabled.');

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $force = !empty(app_config('app')['force_https']);
    $add('https', 'HTTPS enforced', $https && $force ? 'pass' : ($https ? 'warn' : ($dev ? 'warn' : 'fail')),
        $https ? ($force ? 'The site is served over HTTPS and HTTP requests are redirected.' : 'HTTPS is in use but not enforced. Set app.force_https = true in config/local.php.')
            : 'The admin panel is being accessed over plain HTTP. Install an SSL certificate and set app.force_https = true.');

    $key = (string) (app_config('app')['key'] ?? '');
    $add('app_key', 'Application secret key set', ($key === '' || $key === 'change-this-key-on-install' || strlen($key) < 32) ? 'fail' : 'pass',
        ($key === '' || $key === 'change-this-key-on-install' || strlen($key) < 32) ? 'config app.key is missing or still the placeholder. Generate a random 64-character key.' : 'A unique application key is configured (used for signed links and the backup task token).');

    $uploadHt = @file_get_contents(UPLOAD_PATH . '/.htaccess') ?: '';
    $scripts = system_find_upload_scripts();
    $blocked = str_contains($uploadHt, 'engine off') || str_contains($uploadHt, 'RemoveHandler') || str_contains($uploadHt, 'Require all denied');
    $add('upload_scripts', 'Script execution blocked in uploads', $scripts ? 'fail' : ($blocked ? 'pass' : 'warn'),
        $scripts ? 'Executable files found in assets/uploads: ' . implode(', ', array_slice($scripts, 0, 3)) . '. Remove them and review recent uploads.'
            : ($blocked ? 'assets/uploads/.htaccess disables PHP/CGI execution, uploads are re-encoded and stored with random names.' : 'assets/uploads/.htaccess is missing — add rules that disable script execution (nginx: deny *.php under /assets/uploads).'));

    $storageHt = @file_get_contents(STORAGE_PATH . '/.htaccess') ?: '';
    $storageOk = str_contains($storageHt, 'Require all denied') || str_contains($storageHt, 'Deny from all');
    $add('storage_protected', 'Private storage protected', $storageOk ? 'pass' : 'fail',
        $storageOk ? 'storage/ (backups, private documents, logs) denies all web access.' : 'storage/.htaccess is missing — backups and private files could be downloaded directly.');

    $installer = is_dir(APP_ROOT . '/install') && !is_file(APP_ROOT . '/install/.locked') && !is_file(STORAGE_PATH . '/installed.lock');
    $add('installer', 'Web installer removed or locked', $installer ? 'warn' : 'pass', $installer ? 'The /install directory is present. Delete it after installation.' : 'No web installer is exposed.');

    $min = (int) setting('password_min_length', 8);
    $complex = (int) (setting('password_require_uppercase', '1') === '1') + (int) (setting('password_require_number', '1') === '1') + (int) (setting('password_require_special', '1') === '1');
    $add('password_policy', 'Strong password policy', $min >= 8 && $complex >= 2 ? 'pass' : ($min >= 6 ? 'warn' : 'fail'),
        "Minimum $min characters with $complex of 3 complexity rules (uppercase, number, special).", $min >= 8 && $complex >= 2 ? null : ['label' => 'Edit policy', 'to' => '/settings?tab=security']);

    $attempts = (int) setting('max_login_attempts', 5);
    $add('lockout', 'Account lockout enabled', $attempts >= 1 && $attempts <= 10 ? 'pass' : 'warn',
        "Accounts lock for " . (int) setting('lockout_minutes', 15) . " minutes after $attempts failed attempts; IPs are throttled after " . (int) setting('max_ip_attempts', 20) . ' failures.');

    $timeout = (int) setting('session_timeout', 30);
    $add('session_timeout', 'Idle session timeout', $timeout <= 60 ? 'pass' : 'warn', "Idle admin sessions expire after $timeout minutes." . ($timeout > 60 ? ' 60 minutes or less is recommended.' : ''),
        $timeout <= 60 ? null : ['label' => 'Adjust', 'to' => '/settings?tab=security']);

    $last = db_row("SELECT created_at FROM backups WHERE status = 'completed' AND type = 'database' ORDER BY created_at DESC LIMIT 1");
    $age = $last ? (time() - strtotime($last['created_at'])) / 86400 : null;
    $add('backups', 'Recent database backup', $age === null ? 'fail' : ($age <= 7 ? 'pass' : ($age <= 30 ? 'warn' : 'fail')),
        $age === null ? 'No database backup has been taken yet.' : 'Last database backup: ' . format_datetime($last['created_at']) . ' (' . time_ago($last['created_at']) . ').',
        $age === null || $age > 7 ? ['label' => 'Back up now', 'to' => '/backup'] : null);

    $auto = setting('auto_backup', 'off');
    $add('auto_backup', 'Automatic backups scheduled', $auto !== 'off' ? 'pass' : 'warn', $auto !== 'off' ? 'Automatic ' . $auto . ' database backups are enabled.' : 'Automatic backups are off.', $auto !== 'off' ? null : ['label' => 'Configure', 'to' => '/backup']);

    $add('php_version', 'Supported PHP version', version_compare(PHP_VERSION, '8.1', '>=') ? 'pass' : 'warn', 'PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.1', '>=') ? ' is supported.' : ' is end-of-life — upgrade to 8.1 or newer.'));

    $twoFa = setting('two_factor_enabled', '0') === '1';
    $add('two_factor', 'Two-factor authentication', $twoFa ? 'pass' : 'info', $twoFa ? '2FA enrolment is enabled for admin accounts.' : 'The panel is 2FA-ready; enable it under Security settings when your team is ready to enrol.',
        $twoFa ? null : ['label' => 'Security settings', 'to' => '/settings?tab=security']);

    $failed = (int) db_value("SELECT COUNT(*) FROM login_logs WHERE status = 'failed' AND created_at >= NOW() - INTERVAL 1 DAY");
    $add('failed_logins', 'No brute-force activity (24 h)', $failed > 50 ? 'warn' : 'pass', "$failed failed login attempt(s) in the last 24 hours.", $failed > 50 ? ['label' => 'Review', 'to' => '/security?tab=logins'] : null);

    return $items;
}

/* ------------------------------------------------------------------
 * Print views (rendered by GET /api/users/{id}/print, /api/roles/{id}/print, /api/security/report)
 * ------------------------------------------------------------------ */

function system_print_styles(): string
{
    return '<style>
.sp-h1{font:800 20px/1.2 "Plus Jakarta Sans",Inter,sans-serif;color:#0B2A5B;margin:18px 0 2px}
.sp-sub{font-size:11px;color:#64748b;margin:0 0 14px}
.sp-h2{font:700 12px/1.3 "Plus Jakarta Sans",Inter,sans-serif;color:#0B2A5B;margin:18px 0 8px;padding-bottom:4px;border-bottom:1px solid #e2e8f0;text-transform:uppercase;letter-spacing:.04em}
.sp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px 18px;font-size:11.5px;margin:0}
.sp-grid dt{color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.05em}
.sp-grid dd{margin:1px 0 0;font-weight:600;color:#0f172a}
.sp-table{width:100%;border-collapse:collapse;font-size:10.5px}
.sp-table th{background:#0B2A5B;color:#fff;text-align:left;padding:5px 6px;font-weight:600}
.sp-table td{border:1px solid #e2e8f0;padding:4px 6px;vertical-align:top}
.sp-table tr:nth-child(even) td{background:#f8fafc}
.sp-c{text-align:center!important}
.sp-yes{color:#15803d;font-weight:700}
.sp-no{color:#cbd5e1}
.sp-na{color:#e2e8f0}
.sp-grp td{background:#eff4fb!important;font-weight:700;color:#0B2A5B;text-transform:uppercase;font-size:9.5px;letter-spacing:.05em}
.sp-pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:10px;font-weight:700}
.sp-pass{background:#dcfce7;color:#166534}.sp-warn{background:#fef3c7;color:#92400e}.sp-fail{background:#fee2e2;color:#991b1b}.sp-info{background:#e0e7ff;color:#3730a3}
.sp-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:6px 0}
.sp-kpi{border:1px solid #e2e8f0;border-radius:10px;padding:8px 10px}
.sp-kpi b{display:block;font:800 18px "Plus Jakarta Sans",sans-serif;color:#0B2A5B}
.sp-kpi span{font-size:10px;color:#64748b}
.sp-muted{font-size:11.5px;color:#64748b}
.sp-foot{margin-top:24px;font-size:10px;color:#94a3b8;display:flex;justify-content:space-between}
</style>';
}

function system_print_foot(): string
{
    $by = function_exists('current_user') ? (current_user()['name'] ?? '') : '';
    return '<div class="sp-foot"><span>Generated by ' . e($by) . ' on ' . e(format_datetime(date('Y-m-d H:i:s'))) . '</span><span>GIMT SmartCampus &middot; Confidential</span></div>';
}

/** Permission table rows for a set of granted keys (module => [action => true]). */
function system_print_permission_rows(array $granted, bool $all, bool $grouped): string
{
    $actions = array_keys(permission_action_meta());
    $html = '';
    foreach (permission_matrix_groups() as $g) {
        $rows = '';
        foreach ($g['modules'] as $m) {
            if (!$grouped && !$all && empty($granted[$m['key']])) {
                continue;
            }
            $manage = $all || !empty($granted[$m['key']]['manage']);
            $rows .= '<tr><td>' . e($m['label']) . '</td>';
            foreach ($actions as $a) {
                $applicable = in_array($a, $m['actions'], true);
                $has = $applicable && ($manage || !empty($granted[$m['key']][$a]));
                $rows .= '<td class="sp-c ' . ($has ? 'sp-yes' : ($applicable ? 'sp-no' : 'sp-na')) . '">' . ($has ? '&#10003;' : ($applicable ? '&middot;' : '&ndash;')) . '</td>';
            }
            $rows .= '</tr>';
        }
        if ($rows !== '' && $grouped) {
            $html .= '<tr class="sp-grp"><td colspan="' . (count($actions) + 1) . '">' . e($g['group']) . '</td></tr>';
        }
        $html .= $rows;
    }
    $head = '<table class="sp-table"><thead><tr><th style="width:28%">Module</th>';
    foreach ($actions as $a) {
        $head .= '<th class="sp-c">' . e(ucfirst($a)) . '</th>';
    }
    return $head . '</tr></thead><tbody>' . $html . '</tbody></table>';
}

/** Printable user access profile. */
function system_print_user_profile(array $u): void
{
    $roles = $u['role_list'] ?? [];
    $isSuper = (bool) ($u['is_super'] ?? false);
    $granted = [];
    foreach ($roles as $r) {
        foreach (role_permission_keys((int) $r['id']) as $k) {
            [$m, $a] = explode('.', $k, 2);
            $granted[$m][$a] = true;
        }
    }
    $logins = db_all('SELECT status, reason, ip_address, user_agent, created_at FROM login_logs WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 12', [$u['id']]);
    print_layout_start('User access profile - ' . $u['name']);
    echo system_print_styles();
    echo '<h1 class="sp-h1">User access profile</h1><p class="sp-sub">' . e($u['name']) . ' &middot; ' . e($u['email']) . '</p><dl class="sp-grid">';
    $facts = [
        'Full name' => $u['name'], 'Username' => $u['username'], 'Email' => $u['email'], 'Phone' => $u['phone'] ?: '-', 'Designation' => $u['designation'] ?: '-',
        'Department' => $u['department_name'] ?: '-', 'Status' => ucfirst((string) $u['status']) . (!empty($u['is_locked']) ? ' (locked)' : ''),
        'Roles' => implode(', ', array_column($roles, 'name')) ?: '-', 'Last sign-in' => $u['last_login_at'] ? format_datetime($u['last_login_at']) . ' from ' . $u['last_login_ip'] : 'Never',
        'Password changed' => $u['password_changed_at'] ? format_datetime($u['password_changed_at']) : '-', 'Must change password' => !empty($u['must_change_password']) ? 'Yes' : 'No',
        'Account created' => format_datetime($u['created_at']),
    ];
    foreach ($facts as $label => $value) {
        echo '<div><dt>' . e($label) . '</dt><dd>' . e((string) $value) . '</dd></div>';
    }
    echo '</dl><h2 class="sp-h2">Effective permissions</h2>';
    if ($isSuper) {
        echo '<p class="sp-muted">Super Admin: unrestricted access to every module and action.</p>';
    } elseif (!$granted) {
        echo '<p class="sp-muted">This user has no permissions.</p>';
    } else {
        echo system_print_permission_rows($granted, false, false);
    }
    echo '<h2 class="sp-h2">Recent sign-in activity</h2><table class="sp-table"><thead><tr><th>Date &amp; time</th><th>Result</th><th>Details</th><th>IP address</th><th>Browser</th></tr></thead><tbody>';
    foreach ($logins as $l) {
        echo '<tr><td>' . e(format_datetime($l['created_at'])) . '</td><td>' . e(ucfirst($l['status'])) . '</td><td>' . e($l['reason'] ?? '') . '</td><td>' . e($l['ip_address']) . '</td><td>' . e(browser_label($l['user_agent'])) . '</td></tr>';
    }
    if (!$logins) {
        echo '<tr><td colspan="5" class="sp-c sp-muted">No sign-in activity recorded.</td></tr>';
    }
    echo '</tbody></table>' . system_print_foot();
    print_layout_end();
}

/** Printable permission matrix of one role. */
function system_print_role_matrix(array $role): void
{
    $granted = [];
    foreach (role_permission_keys((int) $role['id']) as $k) {
        [$m, $a] = explode('.', $k, 2);
        $granted[$m][$a] = true;
    }
    $super = (bool) $role['is_super'];
    $count = array_sum(array_map('count', $granted));
    print_layout_start('Role permissions - ' . $role['name'], true, 'landscape');
    echo system_print_styles();
    echo '<h1 class="sp-h1">Role permissions: ' . e($role['name']) . '</h1><p class="sp-sub">' . e((string) ($role['description'] ?? '')) . ' &middot; ' . (int) $role['users_count'] . ' user(s) &middot; '
        . ($super ? 'All permissions (Super Admin)' : $count . ' permission(s)') . '</p>';
    echo system_print_permission_rows($granted, $super, true);
    echo '<p class="sp-muted" style="margin-top:8px;font-size:10px">&#10003; granted &middot; dot = not granted &middot; dash = not applicable to the module. Manage implies every action of the module.</p>';
    echo system_print_foot();
    print_layout_end();
}

/** Printable security audit report. */
function system_print_security_report(): void
{
    $items = security_checklist();
    $k = db_row("SELECT COALESCE(SUM(status = 'success'), 0) ok, COALESCE(SUM(status IN ('failed','locked','blocked')), 0) bad
                 FROM login_logs WHERE created_at >= NOW() - INTERVAL 30 DAY");
    $locked = db_all('SELECT name, email, locked_until FROM users WHERE locked_until > NOW() ORDER BY locked_until DESC');
    $blocked = db_all('SELECT ip_address, reason, expires_at FROM blocked_ips WHERE expires_at IS NULL OR expires_at > NOW() ORDER BY created_at DESC LIMIT 20');
    $failed = db_all("SELECT created_at, identifier, ip_address, reason, status FROM login_logs WHERE status IN ('failed','locked','blocked') ORDER BY created_at DESC, id DESC LIMIT 15");
    $pass = count(array_filter($items, fn ($i) => $i['status'] === 'pass'));
    print_layout_start('Security audit report');
    echo system_print_styles();
    echo '<h1 class="sp-h1">Security audit report</h1><p class="sp-sub">Checklist, sign-in activity (last 30 days), locked accounts and blocked IP addresses.</p>';
    echo '<div class="sp-kpis"><div class="sp-kpi"><b>' . $pass . '/' . count($items) . '</b><span>Checks passed</span></div><div class="sp-kpi"><b>' . number_format((int) $k['ok']) . '</b><span>Successful sign-ins (30 days)</span></div>'
        . '<div class="sp-kpi"><b>' . number_format((int) $k['bad']) . '</b><span>Failed / blocked attempts (30 days)</span></div><div class="sp-kpi"><b>' . count($blocked) . '</b><span>Blocked IP addresses</span></div></div>';
    echo '<h2 class="sp-h2">Security checklist</h2><table class="sp-table"><thead><tr><th style="width:26%">Check</th><th style="width:9%">Result</th><th>Details</th></tr></thead><tbody>';
    foreach ($items as $i) {
        echo '<tr><td>' . e($i['title']) . '</td><td><span class="sp-pill sp-' . e($i['status']) . '">' . e(strtoupper($i['status'])) . '</span></td><td>' . e($i['detail']) . '</td></tr>';
    }
    echo '</tbody></table><h2 class="sp-h2">Locked accounts</h2>';
    if ($locked) {
        echo '<table class="sp-table"><thead><tr><th>User</th><th>Email</th><th>Locked until</th></tr></thead><tbody>';
        foreach ($locked as $l) {
            echo '<tr><td>' . e($l['name']) . '</td><td>' . e($l['email']) . '</td><td>' . e(format_datetime($l['locked_until'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="sp-muted">No accounts are currently locked.</p>';
    }
    echo '<h2 class="sp-h2">Blocked IP addresses</h2><table class="sp-table"><thead><tr><th>IP address</th><th>Reason</th><th>Expires</th></tr></thead><tbody>';
    foreach ($blocked as $b) {
        echo '<tr><td>' . e($b['ip_address']) . '</td><td>' . e($b['reason'] ?? '') . '</td><td>' . e($b['expires_at'] ? format_datetime($b['expires_at']) : 'Never') . '</td></tr>';
    }
    if (!$blocked) {
        echo '<tr><td colspan="3" class="sp-c sp-muted">No blocked IP addresses.</td></tr>';
    }
    echo '</tbody></table><h2 class="sp-h2">Recent failed sign-in attempts</h2><table class="sp-table"><thead><tr><th>Date &amp; time</th><th>Login used</th><th>IP address</th><th>Result</th><th>Details</th></tr></thead><tbody>';
    foreach ($failed as $f) {
        echo '<tr><td>' . e(format_datetime($f['created_at'])) . '</td><td>' . e($f['identifier']) . '</td><td>' . e($f['ip_address']) . '</td><td>' . e(ucfirst($f['status'])) . '</td><td>' . e($f['reason'] ?? '') . '</td></tr>';
    }
    if (!$failed) {
        echo '<tr><td colspan="5" class="sp-c sp-muted">No failed attempts recorded.</td></tr>';
    }
    echo '</tbody></table>' . system_print_foot();
    print_layout_end();
}
