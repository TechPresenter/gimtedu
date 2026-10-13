<?php
/**
 * Core seed (always runs): permissions, default roles + role permissions, settings,
 * super admin account, email templates, grading scale, sequences.
 */
return function (array $opts): void {
    // ---------------- Permissions ----------------
    foreach (permission_modules() as $module => $def) {
        foreach ($def['actions'] as $action) {
            db_exec('INSERT IGNORE INTO permissions (module, action, label) VALUES (?, ?, ?)', [$module, $action, ucfirst($action) . ' ' . $def['label']]);
        }
    }
    $permId = function (string $module, string $action): ?int {
        $id = db_value('SELECT id FROM permissions WHERE module = ? AND action = ?', [$module, $action]);
        return $id ? (int) $id : null;
    };

    // ---------------- Roles ----------------
    $all = array_keys(permission_modules());
    $roles = [
        ['Super Admin', 'super-admin', 'Unrestricted access to every module and setting.', 1, 1, 'red', []],
        ['Administrator', 'administrator', 'Full institute administration except role and security management.', 1, 0, 'blue',
            array_fill_keys(array_diff($all, ['roles', 'security', 'backup']), ['*']) + ['roles' => ['view'], 'security' => ['view'], 'backup' => ['view', 'create']]],
        ['Admission Officer', 'admission-officer', 'Enquiries, applications and admission confirmations.', 1, 0, 'green', [
            'dashboard' => ['view'], 'admissions' => ['view', 'create', 'edit', 'export', 'import', 'approve'], 'enquiries' => ['*'],
            'students' => ['view', 'create', 'export'], 'fees' => ['view', 'create'], 'contact_messages' => ['view', 'edit'],
            'reports' => ['view', 'export'], 'notices' => ['view'], 'events' => ['view'], 'academics' => ['view']]],
        ['Academic Admin', 'academic-admin', 'Programs, subjects, timetable and attendance.', 1, 0, 'purple', [
            'dashboard' => ['view'], 'academics' => ['*'], 'timetable' => ['*'], 'attendance' => ['*'], 'students' => ['view', 'edit', 'export'],
            'faculty' => ['view', 'export'], 'examination' => ['view'], 'results' => ['view'], 'reports' => ['view', 'export'],
            'notices' => ['view', 'create', 'edit', 'publish'], 'events' => ['view', 'create', 'edit']]],
        ['Faculty', 'faculty', 'Teaching staff: attendance, marks entry and class information.', 1, 0, 'cyan', [
            'dashboard' => ['view'], 'students' => ['view'], 'attendance' => ['view', 'create', 'edit'], 'timetable' => ['view'],
            'examination' => ['view'], 'results' => ['view', 'create', 'edit'], 'notices' => ['view'], 'events' => ['view'], 'library' => ['view'], 'academics' => ['view']]],
        ['Accountant', 'accountant', 'Fee collection, invoices, refunds and expenses.', 1, 0, 'amber', [
            'dashboard' => ['view'], 'fees' => ['*'], 'expenses' => ['*'], 'students' => ['view', 'export'], 'reports' => ['view', 'export'], 'admissions' => ['view'], 'academics' => ['view']]],
        ['Exam Controller', 'exam-controller', 'Examinations, results, marksheets and certificates.', 1, 0, 'orange', [
            'dashboard' => ['view'], 'examination' => ['*'], 'results' => ['*'], 'certificates' => ['*'], 'students' => ['view', 'export'],
            'academics' => ['view'], 'attendance' => ['view'], 'reports' => ['view', 'export'], 'notices' => ['view', 'create', 'publish']]],
        ['Librarian', 'librarian', 'Library catalogue, circulation and fines.', 1, 0, 'slate', [
            'dashboard' => ['view'], 'library' => ['*'], 'students' => ['view'], 'faculty' => ['view'], 'reports' => ['view']]],
        ['Hostel Warden', 'hostel-warden', 'Hostel rooms, allocations, complaints and visitors.', 1, 0, 'slate', [
            'dashboard' => ['view'], 'hostel' => ['*'], 'students' => ['view'], 'reports' => ['view']]],
        ['Transport Manager', 'transport-manager', 'Vehicles, routes, drivers and transport allocations.', 1, 0, 'slate', [
            'dashboard' => ['view'], 'transport' => ['*'], 'students' => ['view'], 'reports' => ['view']]],
        ['Placement Officer', 'placement-officer', 'Companies, drives, applications and offers.', 1, 0, 'green', [
            'dashboard' => ['view'], 'placement' => ['*'], 'students' => ['view', 'export'], 'alumni' => ['view'], 'reports' => ['view', 'export'], 'events' => ['view', 'create']]],
        ['Alumni Coordinator', 'alumni-coordinator', 'Alumni network, events, jobs and donations.', 1, 0, 'purple', [
            'dashboard' => ['view'], 'alumni' => ['*'], 'events' => ['view', 'create', 'edit', 'publish'], 'communication' => ['view', 'create'], 'reports' => ['view']]],
        ['Content Manager', 'content-manager', 'Website pages, blog, media, banners and SEO.', 1, 0, 'pink', [
            'dashboard' => ['view'], 'cms' => ['*'], 'blog' => ['*'], 'media' => ['*'], 'seo' => ['*'], 'events' => ['*'],
            'notices' => ['view', 'create', 'edit', 'publish'], 'enquiries' => ['view'], 'contact_messages' => ['view'], 'academics' => ['view']]],
        ['Staff', 'staff', 'General staff with read-only access to common modules.', 1, 0, 'slate', [
            'dashboard' => ['view'], 'students' => ['view'], 'notices' => ['view'], 'events' => ['view']]],
    ];
    foreach ($roles as [$name, $slug, $desc, $system, $super, $color, $perms]) {
        db_exec('INSERT INTO roles (name, slug, description, is_system, is_super, color) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)', [$name, $slug, $desc, $system, $super, $color]);
        $roleId = (int) db_value('SELECT id FROM roles WHERE slug = ?', [$slug]);
        foreach ($perms as $module => $actions) {
            $actions = $actions === ['*'] ? permission_modules()[$module]['actions'] : $actions;
            foreach ($actions as $action) {
                if ($pid = $permId($module, $action)) {
                    db_exec('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $pid]);
                }
            }
        }
    }

    // ---------------- Super admin ----------------
    $email = $opts['admin_email'] ?? 'admin@gimt.ac.in';
    if (!db_value('SELECT id FROM users WHERE email = ?', [$email])) {
        $uid = db_insert('users', [
            'name' => $opts['admin_name'] ?? 'Administrator', 'username' => 'admin', 'email' => $email, 'phone' => '+91 9955446477',
            'password_hash' => password_hash($opts['admin_password'] ?? 'Admin@12345', PASSWORD_DEFAULT), 'designation' => 'Super Admin',
            'status' => 'active', 'password_changed_at' => date('Y-m-d H:i:s'),
        ]);
        db_exec('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$uid, 'super-admin']);
    }

    // ---------------- Settings ----------------
    $settings = [
        'general' => [
            'institute_name' => 'Global Institute of Management & Technology', 'institute_short_name' => 'GIMT', 'brand_name' => 'Global IMT',
            'tagline' => 'Education • Innovation • Opportunity', 'established_year' => '2008',
            'address' => 'Plot No. 123, Knowledge Park, Greater Noida, Uttar Pradesh 201310, India', 'city' => 'Greater Noida', 'state' => 'Uttar Pradesh',
            'country' => 'India', 'pincode' => '201310', 'phone' => '+91 9955446477', 'phone_alt' => '+91 120 4567890', 'email' => 'info@gimt.ac.in',
            'admission_email' => 'admissions@gimt.ac.in', 'support_email' => 'support@gimt.ac.in', 'website' => 'www.gimt.ac.in',
            'office_hours' => 'Mon - Sat, 9:00 AM - 6:00 PM', 'affiliation' => 'Affiliated to State University · Approved by AICTE',
            'map_embed_url' => 'https://www.google.com/maps?q=Knowledge+Park+Greater+Noida&output=embed', 'latitude' => '28.4744', 'longitude' => '77.5040',
            'date_format' => 'd M Y', 'timezone' => 'Asia/Kolkata', 'currency_code' => 'INR', 'currency_symbol' => '₹',
            'admissions_open_text' => 'Admissions Open for 2026-27',
        ],
        'website' => [
            'logo' => '', 'logo_white' => '', 'favicon' => '', 'primary_color' => '#0B2A5B', 'accent_color' => '#22943F', 'secondary_color' => '#1D4ED8',
            'heading_font' => 'Plus Jakarta Sans', 'body_font' => 'Inter', 'header_cta_text' => 'Apply Now', 'header_cta_url' => '/apply',
            'show_topbar' => '1', 'footer_about' => 'Global Institute of Management & Technology (GIMT) is committed to providing high-quality, industry-oriented education with a focus on innovation, research and holistic development.',
            'copyright_text' => '© {year} Global Institute of Management & Technology (GIMT). All Rights Reserved.', 'whatsapp_number' => '919955446477',
            'maintenance_mode' => '0',
        ],
        'academic' => [
            'semester_system' => 'semester', 'grading_system' => 'cgpa_10', 'passing_percentage' => '40', 'min_attendance_percent' => '75',
            'attendance_alert_threshold' => '75', 'late_counts_as_present' => '1', 'max_sgpa' => '10', 'student_id_prefix' => 'GIMT',
            'roll_no_format' => '{program}{year}{n:3}',
        ],
        'email' => [
            'mail_driver' => 'smtp', 'smtp_host' => '', 'smtp_port' => '587', 'smtp_encryption' => 'tls', 'smtp_username' => '', 'smtp_password' => '',
            'mail_from_email' => 'no-reply@gimt.ac.in', 'mail_from_name' => 'GIMT SmartCampus',
            'sms_enabled' => '0', 'sms_api_url' => '', 'sms_method' => 'POST', 'sms_api_key' => '', 'sms_sender_id' => 'GIMTIN',
            'whatsapp_enabled' => '0', 'whatsapp_api_url' => '', 'whatsapp_method' => 'POST', 'whatsapp_api_key' => '', 'whatsapp_sender_id' => '',
        ],
        'payment' => [
            'payment_gateway' => 'razorpay', 'gateway_mode' => 'test', 'gateway_key_id' => '', 'gateway_key_secret' => '', 'tax_percent' => '0',
            'gstin' => '', 'receipt_prefix' => 'RCPT/{session}/{n:6}', 'invoice_prefix' => 'INV/{session}/{n:6}', 'late_fee_per_day' => '50',
            'receipt_footer_note' => 'This is a computer generated receipt and does not require a signature.', 'enabled_payment_modes' => 'cash,bank_transfer,cheque,dd,upi,card,online',
        ],
        'security' => [
            'password_min_length' => '8', 'password_require_uppercase' => '1', 'password_require_number' => '1', 'password_require_special' => '1',
            'password_expiry_days' => '0', 'max_login_attempts' => '5', 'lockout_minutes' => '15', 'max_ip_attempts' => '20', 'session_timeout' => '30',
            'remember_me_days' => '30', 'two_factor_enabled' => '0', 'trust_proxy_headers' => '0', 'max_upload_mb' => '5', 'max_video_upload_mb' => '50',
        ],
        'backup' => ['backup_retention' => '15', 'auto_backup' => 'weekly'],
        'analytics' => ['google_analytics_id' => '', 'search_console_verification' => '', 'meta_pixel_id' => '', 'gtm_id' => ''],
        'social' => [
            'facebook_url' => 'https://facebook.com/', 'instagram_url' => 'https://instagram.com/', 'linkedin_url' => 'https://linkedin.com/',
            'youtube_url' => 'https://youtube.com/', 'x_url' => 'https://x.com/',
        ],
    ];
    foreach ($settings as $group => $items) {
        foreach ($items as $key => $value) {
            db_exec('INSERT IGNORE INTO settings (`group`, `key`, `value`) VALUES (?, ?, ?)', [$group, $key, $value]);
        }
    }

    // ---------------- Email templates ----------------
    $templates = [
        ['password_reset', 'Password reset', 'Reset your {{institute_name}} admin password',
            '<p>Hello {{name}},</p><p>We received a request to reset your password. Click the button below to choose a new password. This link expires in 60 minutes.</p><p><a href="{{reset_link}}" style="display:inline-block;background:#0B2A5B;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Reset password</a></p><p>If you did not request this, you can ignore this email.</p>', 'name,reset_link'],
        ['admission_received', 'Application received', 'We received your application {{application_no}}',
            '<p>Dear {{name}},</p><p>Thank you for applying to <strong>{{program}}</strong> at {{institute_name}}. Your application number is <strong>{{application_no}}</strong>.</p><p>Our admission team will contact you shortly.</p>', 'name,program,application_no'],
        ['admission_confirmed', 'Admission confirmed', 'Admission confirmed - {{program}}',
            '<p>Dear {{name}},</p><p>Congratulations! Your admission to <strong>{{program}}</strong> is confirmed. Your Student ID is <strong>{{student_uid}}</strong>.</p>', 'name,program,student_uid'],
        ['fee_receipt', 'Fee receipt', 'Payment received - Receipt {{receipt_no}}',
            '<p>Dear {{name}},</p><p>We have received your payment of <strong>{{amount}}</strong> on {{date}}. Receipt number: <strong>{{receipt_no}}</strong>.</p>', 'name,amount,date,receipt_no'],
        ['fee_reminder', 'Fee reminder', 'Fee payment reminder',
            '<p>Dear {{name}},</p><p>This is a reminder that <strong>{{amount}}</strong> towards {{title}} is due on {{due_date}}. Please pay on time to avoid late fees.</p>', 'name,amount,title,due_date'],
        ['low_attendance', 'Low attendance alert', 'Attendance alert for {{name}}',
            '<p>Dear Parent/Student,</p><p>The attendance of <strong>{{name}}</strong> is <strong>{{percent}}%</strong>, below the required {{required}}%. Please ensure regular attendance.</p>', 'name,percent,required'],
        ['exam_reminder', 'Exam reminder', 'Upcoming exam: {{exam}}',
            '<p>Dear {{name}},</p><p>{{exam}} begins on <strong>{{date}}</strong>. Please check the schedule and carry your hall ticket.</p>', 'name,exam,date'],
        ['result_published', 'Result published', 'Your result for {{exam}} is published',
            '<p>Dear {{name}},</p><p>Your result for {{exam}} has been published. SGPA: <strong>{{sgpa}}</strong>, Result: <strong>{{status}}</strong>.</p>', 'name,exam,sgpa,status'],
        ['contact_reply', 'Contact reply', 'Re: {{subject}}', '<p>Dear {{name}},</p>{{reply}}<p>Regards,<br>{{institute_name}}</p>', 'name,subject,reply'],
        ['notice_published', 'New notice', '{{title}}', '<p>{{title}}</p>{{content}}', 'title,content'],
        ['newsletter_welcome', 'Newsletter welcome', 'Welcome to {{institute_name}} updates',
            '<p>Thank you for subscribing! You will receive the latest news, events and admission updates from {{institute_name}}.</p>', ''],
    ];
    foreach ($templates as [$code, $name, $subject, $body, $vars]) {
        db_exec('INSERT IGNORE INTO email_templates (code, name, subject, body_html, variables) VALUES (?, ?, ?, ?, ?)', [$code, $name, $subject, $body, $vars]);
    }

    // ---------------- Grading scale (10-point CBCS) ----------------
    $grades = [
        ['O', 90, 100, 10, 'Outstanding', 1], ['A+', 80, 89.99, 9, 'Excellent', 1], ['A', 70, 79.99, 8, 'Very Good', 1],
        ['B+', 60, 69.99, 7, 'Good', 1], ['B', 50, 59.99, 6, 'Above Average', 1], ['C', 45, 49.99, 5, 'Average', 1],
        ['P', 40, 44.99, 4, 'Pass', 1], ['F', 0, 39.99, 0, 'Fail', 0],
    ];
    foreach ($grades as $i => [$g, $min, $max, $gp, $desc, $pass]) {
        db_exec('INSERT IGNORE INTO grade_scales (grade, min_percent, max_percent, grade_point, description, is_pass, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)', [$g, $min, $max, $gp, $desc, $pass, $i]);
    }

    // ---------------- Exam types, fee heads, time slots (reference data) ----------------
    foreach ([['Internal Assessment', 'IA', 30, 0], ['Mid Term Examination', 'MID', 20, 0], ['End Semester Examination', 'ESE', 70, 1], ['Practical Examination', 'PRAC', null, 0], ['Supplementary Examination', 'SUPP', null, 1]] as [$n, $c, $w, $f]) {
        db_exec('INSERT IGNORE INTO exam_types (name, code, weightage, is_final) VALUES (?, ?, ?, ?)', [$n, $c, $w, $f]);
    }
    foreach ([['Admission Fee', 'ADM', 'admission', 0], ['Tuition Fee', 'TUI', 'tuition', 0], ['Examination Fee', 'EXM', 'examination', 0], ['Hostel Fee', 'HST', 'hostel', 1],
              ['Transport Fee', 'TRN', 'transport', 1], ['Library Fee', 'LIB', 'library', 0], ['Development Fee', 'DEV', 'miscellaneous', 0], ['Caution Deposit', 'CAU', 'miscellaneous', 1]] as $i => [$n, $c, $t, $r]) {
        db_exec('INSERT IGNORE INTO fee_heads (name, code, type, is_refundable, sort_order) VALUES (?, ?, ?, ?, ?)', [$n, $c, $t, $r, $i]);
    }
    if (!db_value('SELECT COUNT(*) FROM time_slots')) {
        $slots = [['Period 1', '09:00', '09:55', 0], ['Period 2', '09:55', '10:50', 0], ['Short Break', '10:50', '11:05', 1], ['Period 3', '11:05', '12:00', 0],
            ['Period 4', '12:00', '12:55', 0], ['Lunch Break', '12:55', '13:40', 1], ['Period 5', '13:40', '14:35', 0], ['Period 6', '14:35', '15:30', 0], ['Period 7', '15:30', '16:25', 0]];
        foreach ($slots as $i => [$n, $s, $e, $b]) {
            db_insert('time_slots', ['name' => $n, 'start_time' => $s, 'end_time' => $e, 'is_break' => $b, 'sort_order' => $i]);
        }
    }
    foreach ([['Infrastructure & Maintenance', 2500000], ['Salaries & Honorarium', 30000000], ['Utilities (Electricity, Water)', 1800000], ['Laboratory & Equipment', 1500000],
              ['Library Books & Journals', 600000], ['Events & Activities', 800000], ['Marketing & Admissions', 1200000], ['IT & Software', 900000], ['Transport & Fuel', 1400000], ['Office & Stationery', 300000]] as [$n, $b]) {
        db_exec('INSERT IGNORE INTO expense_categories (name, budget_amount) VALUES (?, ?)', [$n, $b]);
    }
};
