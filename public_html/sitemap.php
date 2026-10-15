<?php
require_once __DIR__ . '/functions.php';

$pages = [
    'About' => [
        'About Us' => 'about.php', 'Our Team' => 'our-team.php', 'Impact Reports' => 'impact-reports.php',
        'Financial Transparency' => 'financial-transparency.php', 'FAQ' => 'faq.php', 'Contact Us' => 'contact.php',
    ],
    'Our Work' => ['Our Work' => 'our-work.php'],
    'Donate' => ['Donate Now' => 'donate.php', 'Campaigns' => 'campaigns.php', 'Donation & Refund Policy' => 'donation-policy.php'],
    'Get Involved' => [
        'Volunteer With Us' => 'volunteer.php', 'Volunteer Application' => 'apply-volunteer.php', 'Internship Application' => 'apply-volunteer.php?type=intern',
        'Volunteer Teacher' => 'volunteer-teacher.php', 'Work With Us' => 'volunteer-staff.php', 'Membership' => 'membership.php',
        'Application Status' => 'application-status.php',
    ],
    'Support' => ['Beneficiary Support' => 'beneficiary.php', 'Request Help' => 'request-help.php', 'Scholarship' => 'scholarship.php', 'Grievance Redressal' => 'grievance.php'],
    'Media' => ['Events' => 'events.php', 'Gallery' => 'gallery.php', 'Blog & News' => 'blog.php', 'Success Stories' => 'success-stories.php'],
    'Account' => ['Login' => 'login.php', 'Register' => 'register.php', 'Forgot Password' => 'forgot-password.php', 'My Dashboard' => 'member-dashboard.php'],
    'Policies' => [
        'Privacy Policy' => 'privacy-policy.php', 'Terms & Conditions' => 'terms.php', 'Volunteer Policy' => 'volunteer-policy.php',
        'Child Protection Policy' => 'child-protection-policy.php',
    ],
];
foreach (PROGRAMS as $p) {
    $pages['Our Work'][$p['title']] = $p['file'];
}
$campaigns = db_rows("SELECT title, slug FROM campaigns WHERE status IN ('active','completed') ORDER BY created_at DESC LIMIT 50");
$events = db_rows("SELECT title, slug FROM events WHERE status = 'published' ORDER BY start_datetime DESC LIMIT 50");
$posts = db_rows("SELECT title, slug FROM blog_posts WHERE status = 'published' AND published_at <= NOW() ORDER BY published_at DESC LIMIT 50");

// Live XML sitemap (includes campaigns, events and articles): sitemap.php?format=xml
if (($_GET['format'] ?? '') === 'xml') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo '  <url><loc>' . e(abs_url('')) . '</loc><changefreq>daily</changefreq><priority>1.0</priority></url>' . "\n";
    foreach ($pages as $group => $links) {
        if ($group === 'Account') {
            continue;
        }
        foreach (array_unique($links) as $href) {
            echo '  <url><loc>' . e(abs_url($href)) . '</loc></url>' . "\n";
        }
    }
    foreach (['campaign-details.php' => $campaigns, 'event-details.php' => $events, 'blog-details.php' => $posts] as $file => $rows) {
        foreach ($rows as $r) {
            echo '  <url><loc>' . e(abs_url($file . '?slug=' . urlencode($r['slug']))) . '</loc></url>' . "\n";
        }
    }
    echo '</urlset>' . "\n";
    exit;
}

$pageTitle = 'Sitemap';
require __DIR__ . '/header.php';
page_banner('Sitemap', 'All pages on our website');
?>
<section class="section">
    <div class="container">
        <div class="sitemap-cols">
            <?php foreach ($pages as $group => $links): ?>
                <section>
                    <h2><?= e($group) ?></h2>
                    <ul class="link-list">
                        <?php foreach ($links as $label => $href): ?>
                            <li><a href="<?= e(url($href)) ?>"><?= icon('chevron-right') ?><?= e($label) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
            <?php foreach (['Campaigns' => [$campaigns, 'campaign-details.php'], 'Events' => [$events, 'event-details.php'], 'Articles' => [$posts, 'blog-details.php']] as $group => [$rows, $file]): if (!$rows) { continue; } ?>
                <section>
                    <h2><?= e($group) ?></h2>
                    <ul class="link-list">
                        <?php foreach ($rows as $r): ?>
                            <li><a href="<?= e(url($file . '?slug=' . urlencode($r['slug']))) ?>"><?= icon('chevron-right') ?><?= e($r['title']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
