<?php
/** Admin sidebar - shows only the sections the signed-in staff member may use. */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}
$navCurrent = basename($_SERVER['SCRIPT_NAME']);
$navType = get_str('type');
// [file, icon, label, permission (null = any staff), files that highlight this item]
$navGroups = [
    '' => [
        ['dashboard.php', 'grid', 'Dashboard', 'dashboard.view', ['dashboard.php']],
    ],
    'Website' => [
        ['pages.php', 'file-text', 'Pages & Sections', 'pages.manage', ['pages.php', 'page-edit.php']],
        ['menus.php', 'list', 'Menus (header, topbar, footer)', 'menus.manage', ['menus.php']],
        ['media.php', 'image', 'Media Library', 'media.manage', ['media.php']],
        ['widgets.php', 'layers', 'Widgets & Alerts', 'widgets.manage', ['widgets.php']],
        ['settings.php', 'sliders', 'Settings', 'settings.manage', ['settings.php']],
        ['redirects.php', 'refresh', 'Redirects & 404s', 'redirects.manage', ['redirects.php']],
        ['seo.php', 'search', 'SEO Audit', 'pages.manage', ['seo.php']],
    ],
    'Content' => [
        ['programs.php', 'target', 'Programmes', 'programs.manage', ['programs.php', 'program-edit.php']],
        ['schemes.php', 'list', 'Welfare Schemes', 'schemes.manage', ['schemes.php', 'scheme-edit.php']],
        ['blog.php', 'edit', 'Blog, News & Press', 'blog.manage', ['blog.php', 'blog-edit.php']],
        ['stories.php', 'star', 'Success Stories', 'stories.manage', ['stories.php', 'story-edit.php']],
        ['events.php', 'calendar', 'Events & Calendar', 'events.manage', ['events.php', 'event-edit.php', 'event-registrations.php']],
        ['program-categories.php', 'layers', 'Programme Categories', 'programs.manage', ['program-categories.php', 'program-category-edit.php']],
        ['speakers.php', 'users', 'Speakers & Trainers', 'events.manage', ['speakers.php']],
        ['partners.php', 'briefcase', 'Partners & Sponsors', 'events.manage', ['partners.php']],
        ['gallery.php', 'image', 'Photo & Video Gallery', 'gallery.manage', ['gallery.php']],
        ['team.php', 'users', 'Team & Management', 'team.manage', ['team.php']],
        ['resources.php', 'download', 'Certificates & Reports', 'resources.manage', ['resources.php']],
        ['testimonials.php', 'message', 'Testimonials', 'testimonials.manage', ['testimonials.php']],
        ['faqs.php', 'help-circle', 'FAQs', 'faqs.manage', ['faqs.php']],
    ],
    'Fundraising' => [
        ['campaigns.php', 'heart', 'Campaigns', 'campaigns.manage', ['campaigns.php', 'campaign-edit.php', 'campaign-view.php']],
        ['proposals.php', 'flag', 'Fundraiser Proposals', 'campaigns.proposals', ['proposals.php']],
        ['donations.php', 'rupee', 'Donations', 'donations.view', ['donations.php', 'donation-view.php']],
        ['receipts.php', 'file-text', 'Receipts', 'receipts.manage', ['receipts.php']],
        ['expenses.php', 'pie-chart', 'Expenses & Utilisation', 'campaigns.expenses', ['expenses.php']],
        ['payment-gateways.php', 'key', 'Payment Gateways', 'payments.configure', ['payment-gateways.php']],
    ],
    'People' => [
        ['applications.php?type=volunteer', 'heart', 'Volunteers', 'recruitment.view', ['applications.php:volunteer', 'application-view.php:volunteer']],
        ['applications.php?type=teacher', 'book', 'Volunteer Teachers', 'recruitment.view', ['applications.php:teacher', 'application-view.php:teacher']],
        ['applications.php?type=staff', 'briefcase', 'Staff Applications', 'recruitment.view', ['applications.php:staff', 'application-view.php:staff']],
        ['applications.php?type=internship', 'award', 'Internships', 'recruitment.view', ['applications.php:internship', 'application-view.php:internship']],
        ['tasks.php', 'check-circle', 'Volunteer Tasks', 'recruitment.manage', ['tasks.php']],
        ['certificates.php', 'award', 'Certificates', 'certificates.manage', ['certificates.php']],
        ['letters.php', 'file-text', 'Authorization Letters', 'letters.manage', ['letters.php', 'letter-edit.php', 'letter-print.php']],
        ['memberships.php', 'id-card', 'Memberships', 'members.view', ['memberships.php']],
        ['beneficiaries.php', 'life-buoy', 'Help Requests', 'beneficiaries.manage', ['beneficiaries.php']],
        ['scholarships.php', 'graduation', 'Scholarships', 'beneficiaries.manage', ['scholarships.php']],
        ['users.php', 'user', 'Users & Roles', 'members.view', ['users.php', 'user-view.php']],
        ['roles.php', 'lock', 'Roles & Permissions', 'roles.manage', ['roles.php']],
        ['data-requests.php', 'shield', 'Data Requests', 'data_requests.manage', ['data-requests.php']],
    ],
    'Communication' => [
        ['enquiries.php', 'inbox', 'Enquiries & Grievances', 'enquiries.manage', ['enquiries.php']],
        ['feedback.php', 'message', 'Feedback', 'feedback.manage', ['feedback.php']],
        ['newsletter.php', 'mail', 'Newsletter', 'newsletter.manage', ['newsletter.php']],
        ['assistant.php', 'zap', 'AI Assistant (chatbot)', 'assistant.manage', ['assistant.php']],
    ],
    'Insights' => [
        ['analytics.php', 'trending-up', 'Website Analytics', 'analytics.view', ['analytics.php']],
        ['deep-analytics.php', 'activity', 'Deep Analytics & Visitors', 'analytics.deep', ['deep-analytics.php']],
        ['marketing.php', 'megaphone', 'Marketing & Campaign Links', 'analytics.view', ['marketing.php']],
        ['reports.php', 'bar-chart', 'Reports & Exports', 'reports.view', ['reports.php']],
    ],
    'System' => [
        ['security.php', 'shield', 'Security Centre', 'security.manage', ['security.php']],
        ['activity-logs.php', 'activity', 'Audit Log', 'logs.view', ['activity-logs.php']],
        ['backups.php', 'download', 'Backup & Data Retention', 'backups.manage', ['backups.php']],
    ],
];
$navMatch = function (array $patterns) use ($navCurrent, $navType): bool {
    foreach ($patterns as $p) {
        [$file, $type] = array_pad(explode(':', $p, 2), 2, null);
        if ($file === $navCurrent && ($type === null || $type === $navType)) {
            return true;
        }
    }
    return false;
};
$navBadge = function (string $file): int {
    static $counts = null;
    if ($counts === null) {
        $counts = [];
        try {
            if (admin_can('enquiries.manage')) {
                $counts['enquiries.php'] = (int) db_value("SELECT COUNT(*) FROM contact_enquiries WHERE status = 'new'");
            }
            if (admin_can('recruitment.view')) {
                foreach (['volunteer' => 'volunteer_applications', 'teacher' => 'teacher_applications', 'staff' => 'staff_applications', 'internship' => 'internship_applications'] as $t => $table) {
                    $counts['applications.php?type=' . $t] = (int) db_value("SELECT COUNT(*) FROM `$table` WHERE status = 'submitted'");
                }
            }
            if (admin_can('campaigns.proposals')) {
                $counts['proposals.php'] = (int) db_value("SELECT COUNT(*) FROM campaigns WHERE status = 'proposal'");
            }
            if (admin_can('beneficiaries.manage')) {
                $counts['beneficiaries.php'] = (int) db_value("SELECT COUNT(*) FROM beneficiaries WHERE status = 'pending'");
                $counts['scholarships.php'] = (int) db_value("SELECT COUNT(*) FROM scholarships WHERE status = 'pending'");
            }
        } catch (Throwable $e) {
            error_log('Admin badges failed: ' . $e->getMessage());
        }
    }
    return $counts[$file] ?? 0;
};
?>
<aside class="admin-sidebar" id="admin-sidebar">
    <a class="admin-brand" href="<?= e(admin_url('dashboard.php')) ?>">
        <img src="<?= e(site_logo()) ?>" alt="" width="44" height="44">
        <span><strong><?= e(site_name()) ?></strong><small>Admin Panel</small></span>
    </a>
    <nav aria-label="Admin navigation">
        <?php foreach ($navGroups as $navTitle => $navItems):
            $navVisible = array_filter($navItems, function ($navI) { return $navI[3] === null || admin_can($navI[3]); });
            if (!$navVisible) { continue; } ?>
            <?php if ($navTitle !== ''): ?><div class="admin-nav-title"><?= e($navTitle) ?></div><?php endif; ?>
            <ul>
                <?php foreach ($navVisible as [$navFile, $navIcon, $navLabel, $navPerm, $navPatterns]): $navCount = $navBadge($navFile); ?>
                    <li><a class="<?= $navMatch($navPatterns) ? 'active' : '' ?>" href="<?= e(admin_url($navFile)) ?>"><?= icon($navIcon) ?><span><?= e($navLabel) ?></span>
                        <?php if ($navCount): ?><em class="nav-badge"><?= $navCount ?></em><?php endif; ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
        <ul class="admin-nav-bottom">
            <li><a class="<?= $navCurrent === 'profile.php' ? 'active' : '' ?>" href="<?= e(admin_url('profile.php')) ?>"><?= icon('user') ?><span>My Profile & 2-Step Login</span></a></li>
            <li><form method="post" action="<?= e(admin_url('logout.php')) ?>"><?= csrf_field() ?><button type="submit" class="admin-logout"><?= icon('log-out') ?><span>Sign out</span></button></form></li>
        </ul>
    </nav>
</aside>
