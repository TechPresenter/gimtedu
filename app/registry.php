<?php
/**
 * Central registry of permission modules (server side).
 * The roles & permissions matrix and the seeder read from here. The SPA navigation lives in
 * frontend/src/navigation.ts and references these module keys.
 */

/**
 * Permission modules: key => [label, group, applicable actions].
 * Permission rows are seeded for every (module, action) pair listed here (see database/seed/01_core.php).
 */
function permission_modules(): array
{
    $all = ['view', 'create', 'edit', 'delete', 'export', 'import', 'approve', 'publish', 'manage'];
    $crud = ['view', 'create', 'edit', 'delete', 'export', 'import', 'manage'];
    return [
        'dashboard'        => ['label' => 'Dashboard', 'group' => 'Main', 'actions' => ['view']],
        'admissions'       => ['label' => 'Admissions', 'group' => 'Academic', 'actions' => $all],
        'enquiries'        => ['label' => 'Enquiries', 'group' => 'Academic', 'actions' => $crud],
        'students'         => ['label' => 'Students', 'group' => 'Academic', 'actions' => $all],
        'faculty'          => ['label' => 'Faculty & Staff', 'group' => 'Academic', 'actions' => $all],
        'academics'        => ['label' => 'Academics (Programs, Subjects...)', 'group' => 'Academic', 'actions' => $crud],
        'timetable'        => ['label' => 'Timetable', 'group' => 'Academic', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'publish', 'manage']],
        'attendance'       => ['label' => 'Attendance', 'group' => 'Academic', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'approve', 'manage']],
        'examination'      => ['label' => 'Examination', 'group' => 'Academic', 'actions' => $all],
        'results'          => ['label' => 'Results & Marksheets', 'group' => 'Academic', 'actions' => $all],
        'certificates'     => ['label' => 'Certificates', 'group' => 'Academic', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'approve', 'manage']],
        'fees'             => ['label' => 'Fees & Accounts', 'group' => 'Finance', 'actions' => $all],
        'expenses'         => ['label' => 'Expenses', 'group' => 'Finance', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'approve', 'manage']],
        'library'          => ['label' => 'Library', 'group' => 'Campus', 'actions' => $crud],
        'hostel'           => ['label' => 'Hostel', 'group' => 'Campus', 'actions' => $crud],
        'transport'        => ['label' => 'Transport', 'group' => 'Campus', 'actions' => $crud],
        'placement'        => ['label' => 'Placement', 'group' => 'Career', 'actions' => $all],
        'alumni'           => ['label' => 'Alumni', 'group' => 'Career', 'actions' => $all],
        'notices'          => ['label' => 'Notices & Circulars', 'group' => 'Communication', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'publish', 'manage']],
        'events'           => ['label' => 'Events', 'group' => 'Communication', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'publish', 'manage']],
        'communication'    => ['label' => 'Newsletter / Email / SMS', 'group' => 'Communication', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'publish', 'manage']],
        'contact_messages' => ['label' => 'Contact Messages', 'group' => 'Communication', 'actions' => ['view', 'edit', 'delete', 'export', 'manage']],
        'feedback'         => ['label' => 'Feedback & Complaints', 'group' => 'Communication', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'manage']],
        'cms'              => ['label' => 'Website CMS (Pages, Menus, Banners...)', 'group' => 'Website', 'actions' => ['view', 'create', 'edit', 'delete', 'publish', 'manage']],
        'blog'             => ['label' => 'Blog / News', 'group' => 'Website', 'actions' => ['view', 'create', 'edit', 'delete', 'publish', 'manage']],
        'media'            => ['label' => 'Media Library & Gallery', 'group' => 'Website', 'actions' => ['view', 'create', 'edit', 'delete', 'manage']],
        'seo'              => ['label' => 'SEO Management', 'group' => 'Website', 'actions' => ['view', 'edit', 'manage']],
        'reports'          => ['label' => 'Reports & Analytics', 'group' => 'System', 'actions' => ['view', 'export']],
        'users'            => ['label' => 'Users', 'group' => 'System', 'actions' => ['view', 'create', 'edit', 'delete', 'export', 'manage']],
        'roles'            => ['label' => 'Roles & Permissions', 'group' => 'System', 'actions' => ['view', 'create', 'edit', 'delete', 'manage']],
        'activity_logs'    => ['label' => 'Activity Logs', 'group' => 'System', 'actions' => ['view', 'export', 'delete']],
        'settings'         => ['label' => 'System Settings', 'group' => 'System', 'actions' => ['view', 'edit', 'manage']],
        'backup'           => ['label' => 'Backup & Restore', 'group' => 'System', 'actions' => ['view', 'create', 'delete', 'manage']],
        'security'         => ['label' => 'Security', 'group' => 'System', 'actions' => ['view', 'edit', 'manage']],
    ];
}
