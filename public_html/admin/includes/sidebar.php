<?php
/** Admin sidebar - only shows sections the admin is allowed to use. */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}
$navCurrent = basename($_SERVER['PHP_SELF']);
$navGroups = [
    '' => [
        ['dashboard.php', 'grid', 'Dashboard', null, ['dashboard.php']],
    ],
    'Fundraising' => [
        ['campaigns.php', 'target', 'Campaigns', 'campaigns', ['campaigns.php', 'campaign-add.php', 'campaign-edit.php', 'campaign-view.php']],
        ['donations.php', 'heart', 'Donations', 'donations', ['donations.php', 'donation-view.php']],
        ['receipts.php', 'file-text', 'Receipts', 'donations', ['receipts.php']],
    ],
    'People' => [
        ['volunteers.php', 'users', 'Volunteers & Interns', 'applications', ['volunteers.php', 'volunteer-view.php']],
        ['teachers.php', 'graduation', 'Volunteer Teachers', 'applications', ['teachers.php', 'teacher-view.php']],
        ['staff-applications.php', 'briefcase', 'Staff Applications', 'applications', ['staff-applications.php']],
        ['members.php', 'user', 'Members & Users', 'members', ['members.php']],
        ['beneficiaries.php', 'package', 'Help Requests', 'beneficiaries', ['beneficiaries.php']],
        ['scholarships.php', 'award', 'Scholarships', 'beneficiaries', ['scholarships.php']],
    ],
    'Website' => [
        ['pages.php', 'file-text', 'Pages & Documents', 'content', ['pages.php']],
        ['homepage.php', 'home', 'Homepage', 'content', ['homepage.php']],
        ['menus.php', 'list', 'Menus', 'content', ['menus.php']],
        ['events.php', 'calendar', 'Events', 'content', ['events.php']],
        ['gallery.php', 'image', 'Gallery', 'content', ['gallery.php']],
        ['blog.php', 'edit', 'Blog & News', 'content', ['blog.php']],
        ['testimonials.php', 'message', 'Testimonials', 'content', ['testimonials.php']],
        ['faqs.php', 'help-circle', 'FAQs', 'content', ['faqs.php']],
    ],
    'Communication' => [
        ['contact-enquiries.php', 'inbox', 'Enquiries & Grievances', 'enquiries', ['contact-enquiries.php']],
        ['newsletter.php', 'mail', 'Newsletter', 'enquiries', ['newsletter.php']],
    ],
    'Administration' => [
        ['reports.php', 'bar-chart', 'Reports', 'reports', ['reports.php']],
        ['settings.php', 'sliders', 'Settings', 'settings', ['settings.php']],
        ['users.php', 'shield', 'Admin Users', 'users', ['users.php']],
        ['roles.php', 'lock', 'Roles & Permissions', 'users', ['roles.php']],
        ['activity-logs.php', 'activity', 'Activity Logs', 'logs', ['activity-logs.php']],
    ],
];
$navNewEnquiries = admin_can('enquiries') ? (int) db_value("SELECT COUNT(*) FROM contact_enquiries WHERE status = 'new'") : 0;
$navPendingApps = admin_can('applications') ? (int) db_value("SELECT (SELECT COUNT(*) FROM volunteers WHERE status = 'pending') + (SELECT COUNT(*) FROM teacher_applications WHERE status = 'pending') + (SELECT COUNT(*) FROM staff_applications WHERE status = 'pending')") : 0;
$navBadges = ['contact-enquiries.php' => $navNewEnquiries, 'volunteers.php' => $navPendingApps ? (int) db_value("SELECT COUNT(*) FROM volunteers WHERE status = 'pending'") : 0,
    'teachers.php' => $navPendingApps ? (int) db_value("SELECT COUNT(*) FROM teacher_applications WHERE status = 'pending'") : 0,
    'staff-applications.php' => $navPendingApps ? (int) db_value("SELECT COUNT(*) FROM staff_applications WHERE status = 'pending'") : 0];
?>
<aside class="admin-sidebar" id="admin-sidebar">
    <a class="admin-brand" href="<?= e(admin_url('dashboard.php')) ?>">
        <img src="<?= e(url('assets/images/logo.png')) ?>" alt="" width="44" height="44">
        <span><strong><?= e(site_name()) ?></strong><small>Admin Panel</small></span>
    </a>
    <nav aria-label="Admin navigation">
        <?php foreach ($navGroups as $navTitle => $navItems):
            $navVisible = array_filter($navItems, function ($navI) { return $navI[3] === null || admin_can($navI[3]); });
            if (!$navVisible) { continue; } ?>
            <?php if ($navTitle !== ''): ?><div class="admin-nav-title"><?= e($navTitle) ?></div><?php endif; ?>
            <ul>
                <?php foreach ($navVisible as [$navFile, $navIcon, $navLabel, $navPerm, $navMatch]): ?>
                    <li><a class="<?= in_array($navCurrent, $navMatch, true) ? 'active' : '' ?>" href="<?= e(admin_url($navFile)) ?>"><?= icon($navIcon) ?><span><?= e($navLabel) ?></span>
                        <?php if (!empty($navBadges[$navFile])): ?><em class="nav-badge"><?= (int) $navBadges[$navFile] ?></em><?php endif; ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
        <ul class="admin-nav-bottom">
            <li><a class="<?= $navCurrent === 'profile.php' ? 'active' : '' ?>" href="<?= e(admin_url('profile.php')) ?>"><?= icon('user') ?><span>My Profile</span></a></li>
            <li><a href="<?= e(admin_url('logout.php?token=' . csrf_token())) ?>"><?= icon('log-out') ?><span>Logout</span></a></li>
        </ul>
    </nav>
</aside>
