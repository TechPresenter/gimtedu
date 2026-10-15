<?php
/**
 * Campaign detail (/campaign/<slug>/).
 * Public only for active / completed / closed campaigns; drafts and proposals are 404 unless a staff member
 * with campaigns.manage opens ?preview=1. All money figures come from verified donations only.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$segments = route_segments();
if (!$segments) {
    header('Location: ' . page_url('campaigns'), true, 301);
    exit;
}
if (count($segments) !== 1 || !preg_match('/^[a-z0-9][a-z0-9\-]{0,219}$/', $segments[0])) {
    not_found();
}
$slug = $segments[0];
$c = db_row(campaign_sql() . ' WHERE c.slug = ?', [$slug]);
if (!$c) {
    not_found();
}
$preview = !campaign_is_public($c) && isset($_GET['preview']) && user_can('campaigns.manage');
if (!campaign_is_public($c) && !$preview) {
    not_found();
}

$cid = (int) $c['id'];
$accepting = campaign_accepts_donations($c);
$raised = (float) $c['raised'];
$goal = (float) $c['goal_amount'];
$updates = db_rows("SELECT * FROM campaign_updates WHERE campaign_id = ? AND status = 'published' ORDER BY COALESCE(published_at, created_at) DESC, id DESC", [$cid]);
$expenses = published_expenses($cid);
$spent = array_sum(array_map(function ($x) { return (float) $x['amount']; }, $expenses));
$docs = fundraising_documents(['utilization_report'], $cid);
$supporters = db_rows("SELECT donor_name, paid_at FROM donations WHERE campaign_id = ? AND status = 'success' AND is_anonymous = 0 ORDER BY paid_at DESC, id DESC LIMIT 8", [$cid]);
$anonymous = (int) db_value("SELECT COUNT(*) FROM donations WHERE campaign_id = ? AND status = 'success' AND is_anonymous = 1", [$cid]);
$related = db_rows(campaign_sql() . " WHERE c.status = 'active' AND c.id <> ? ORDER BY (c.category = ?) DESC, c.is_urgent DESC, c.is_featured DESC, c.created_at DESC LIMIT 3", [$cid, $c['category']]);
$related = array_values(array_filter($related, 'campaign_accepts_donations'));
$program = null;
if ($c['program_id']) {
    $program = db_row("SELECT pr.*, pg.slug AS page_slug FROM programs pr LEFT JOIN pages pg ON pg.id = pr.page_id AND pg.status = 'published' WHERE pr.id = ? AND pr.status = 'published'", [$c['program_id']]);
}

$canonical = abs_url('campaign/' . $slug . '/');
$donatePath = '/donate/' . ($accepting ? '?campaign=' . $cid : '');
$donateAbs = $accepting ? abs_page_url('donate', ['campaign' => $cid]) : abs_page_url('donate');
$imageAbs = campaign_image_abs($c);
$description = $c['meta_description'] ?: excerpt($c['short_description'], 160);
$schema = [
    '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $c['title'], 'url' => $canonical, 'description' => $description,
    'inLanguage' => 'en-IN', 'about' => ['@type' => 'Thing', 'name' => campaign_category_label($c['category'])],
    'publisher' => ['@type' => 'NGO', 'name' => site_name(), 'url' => abs_url('')],
    'potentialAction' => ['@type' => 'DonateAction', 'name' => $accepting ? 'Donate to ' . $c['title'] : 'Donate to ' . site_name(), 'target' => $donateAbs,
        'recipient' => ['@type' => 'NGO', 'name' => site_name(), 'url' => abs_url('')]],
];
if ($imageAbs !== '') {
    $schema['image'] = $imageAbs;
}
if ($c['updated_at'] || $c['created_at']) {
    $schema['dateModified'] = date('c', strtotime($c['updated_at'] ?: $c['created_at']));
}

page_header($page, [
    'title' => $c['title'],
    'subtitle' => $c['short_description'],
    'eyebrow' => campaign_category_label($c['category']),
    'meta_title' => $c['meta_title'] ?: $c['title'],
    'meta_description' => $description,
    'canonical' => $canonical,
    'og_image' => $imageAbs,
    'og_type' => 'article',
    'crumbs' => [['Campaigns', page_url('campaigns')]],
    'buttons' => $accepting ? [['label' => 'Donate now', 'url' => $donatePath, 'style' => 'donate', 'icon' => 'heart']] : [['label' => 'Make a general donation', 'url' => '/donate/', 'style' => 'donate', 'icon' => 'heart']],
    'schema' => [$schema],
    'noindex' => $preview,
]);

$presets = donation_presets();
$daysLeft = campaign_days_left($c);
$facts = [];
if ($c['location']) {
    $facts[] = [icon('map-pin'), e($c['location'])];
}
if ($c['start_date']) {
    $facts[] = [icon('calendar'), 'Started ' . e(format_date($c['start_date']))];
}
if ($c['end_date']) {
    $facts[] = [icon('clock'), (campaign_has_future_end($c) ? 'Ends ' : 'Ended ') . e(format_date($c['end_date']))];
}
if ($program) {
    $facts[] = [icon('target'), 'Part of <a href="' . e(program_url($program)) . '">' . e($program['title']) . '</a>'];
}
?>
<?php if ($preview): ?>
    <div class="container"><div class="alert alert-warning mt-2" role="status"><?= icon('eye') ?><div>Staff preview of a <strong><?= e($c['status']) ?></strong> campaign. Visitors cannot see this page until the campaign is published.</div></div></div>
<?php endif; ?>
<section class="section fr-detail">
    <div class="container detail-layout">
        <div class="fr-main">
            <figure class="detail-hero-img fr-hero-img">
                <img src="<?= e(campaign_image($c)) ?>" alt="<?= e($c['image'] ? $c['title'] : 'Illustration: ' . campaign_category_label($c['category'])) ?>" width="960" height="540">
                <?php if (!empty($c['is_urgent']) && $accepting): ?><span class="tag tag-urgent">Urgent</span><?php endif; ?>
            </figure>
            <?php if ($facts): ?>
                <ul class="fr-facts">
                    <?php foreach ($facts as [$ic, $text]): ?><li><?= $ic ?><span><?= $text ?></span></li><?php endforeach; ?>
                    <li><?= icon($accepting ? 'heart' : 'check-circle') ?><span><?= e(campaign_status_text($c)) ?></span></li>
                </ul>
            <?php endif; ?>

            <div class="fr-mobile-summary">
                <?= ui_progress($raised, $goal, (int) $c['donor_count']) ?>
                <a class="btn btn-donate btn-block" href="<?= e($accepting ? donate_url(['campaign' => $cid]) : donate_url()) ?>"><?= icon('heart') ?><?= $accepting ? 'Donate to this campaign' : 'Make a general donation' ?></a>
            </div>

            <article class="prose fr-story" aria-label="About this campaign">
                <?= clean_html((string) $c['description']) ?>
            </article>

            <section class="fr-block" id="updates" aria-labelledby="updates-title">
                <h2 id="updates-title"><?= icon('bell') ?>Campaign updates</h2>
                <?php if ($updates): ?>
                    <ol class="fr-updates">
                        <?php foreach ($updates as $u): ?>
                            <li class="reveal">
                                <time datetime="<?= e(date('Y-m-d', strtotime($u['published_at'] ?: $u['created_at']))) ?>"><?= e(format_date($u['published_at'] ?: $u['created_at'])) ?></time>
                                <h3><?= e($u['title']) ?></h3>
                                <?php if ($u['image'] && is_file(ROOT_PATH . '/' . $u['image'])): ?><img src="<?= e(url($u['image'])) ?>" alt="<?= e($u['title']) ?>" loading="lazy"><?php endif; ?>
                                <p><?= e_nl($u['body']) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <p class="muted">Updates from the field will be posted here as the campaign progresses.</p>
                <?php endif; ?>
            </section>

            <section class="fr-block" id="utilisation" aria-labelledby="utilisation-title">
                <h2 id="utilisation-title"><?= icon('pie-chart') ?>How funds are being used</h2>
                <div class="fr-util-summary">
                    <div><small>Verified donations received</small><strong><?= e(money($raised)) ?></strong></div>
                    <div><small>Approved spending published</small><strong><?= e(money($spent)) ?></strong></div>
                </div>
                <?php if ($expenses): ?>
                    <div class="table-wrap"><table class="table fr-table">
                        <caption class="sr-only">Approved expenses for <?= e($c['title']) ?></caption>
                        <thead><tr><th scope="col">Date</th><th scope="col" class="fr-hide-sm">Category</th><th scope="col">Details</th><th scope="col" class="text-right">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($expenses as $x): ?>
                            <tr><td class="nowrap"><?= e(format_date($x['expense_date'])) ?></td><td class="fr-hide-sm"><?= e($x['category']) ?></td><td><small class="fr-show-sm muted"><?= e($x['category']) ?></small><?= e($x['description']) ?></td><td class="text-right nowrap"><?= e(money($x['amount'], true)) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php else: ?>
                    <p class="muted">Expense details will be published here after they are approved by our finance team.</p>
                <?php endif; ?>
                <?php if ($docs): ?>
                    <h3 class="mt-3">Utilisation reports</h3>
                    <?= fundraising_document_list($docs) ?>
                <?php endif; ?>
                <p class="small muted mt-2"><?= icon('shield') ?> <?= e(verified_figures_note()) ?> <a href="<?= e(page_url('utilization-reports')) ?>">All utilisation reports</a></p>
            </section>

            <div class="fr-share">
                <p><strong>Share this campaign</strong></p>
                <?= share_buttons($canonical, $c['title']) ?>
            </div>
        </div>

        <aside class="sticky-card fr-aside" aria-label="Donate to this campaign">
            <div class="raise-box fr-raise">
                <?php if (!$accepting): ?><p class="fr-raise-status"><?= status_badge($c['status'] === 'active' ? 'closed' : $c['status']) ?></p><?php endif; ?>
                <?= ui_progress($raised, $goal, (int) $c['donor_count'], 'lg') ?>
                <div class="raise-grid">
                    <div><strong><?= (int) $c['donor_count'] ?></strong><small>verified <?= (int) $c['donor_count'] === 1 ? 'donation' : 'donations' ?></small></div>
                    <div><strong><?= $goal > 0 ? e(money($goal)) : '—' ?></strong><small><?= $goal > 0 ? 'target' : 'no fixed target' ?></small></div>
                    <div><strong><?= ($accepting && $daysLeft !== null) ? (int) $daysLeft : '—' ?></strong><small><?= ($accepting && $daysLeft !== null) ? ($daysLeft === 1 ? 'day left' : 'days left') : ($accepting ? 'open-ended' : e(strtolower(campaign_status_text($c)))) ?></small></div>
                </div>
                <?php if ($accepting && campaign_has_future_end($c)): ?><?= ui_countdown($c['end_date']) ?><?php endif; ?>
                <?php if ($accepting): ?>
                    <p class="fr-raise-label">Choose an amount</p>
                    <div class="fr-amounts fr-amounts-sm">
                        <?php foreach (array_slice($presets, 0, 4) as $a): ?>
                            <a class="fr-amount" href="<?= e(donate_url(['campaign' => $cid, 'amount' => $a])) ?>"><?= e(money($a)) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a class="btn btn-donate btn-lg btn-block" href="<?= e(donate_url(['campaign' => $cid])) ?>"><?= icon('heart') ?>Donate to this campaign</a>
                <?php else: ?>
                    <div class="notice notice-green"><?= icon('check-circle') ?><div>This campaign is no longer accepting donations. Thank you to everyone who contributed.</div></div>
                    <a class="btn btn-donate btn-block mt-2" href="<?= e(donate_url()) ?>"><?= icon('heart') ?>Make a general donation</a>
                <?php endif; ?>
                <p class="verified-note"><?= icon('shield') ?><span>Amount raised includes only donations verified with our payment gateway or reconciled by our finance team.</span></p>
            </div>

            <div class="raise-box fr-supporters mt-3">
                <h2 class="h3">Recent supporters</h2>
                <?php if ($supporters): ?>
                    <ul class="donor-list">
                        <?php foreach ($supporters as $s): ?>
                            <li><span><?= icon('heart') ?> <?= e(public_donor_name($s['donor_name'])) ?></span><small class="muted"><?= e(format_date($s['paid_at'])) ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($anonymous > 0): ?><p class="small muted mt-1">and <?= $anonymous ?> anonymous <?= $anonymous === 1 ? 'supporter' : 'supporters' ?>.</p><?php endif; ?>
                <?php elseif ($anonymous > 0): ?>
                    <p class="small muted"><?= $anonymous ?> anonymous <?= $anonymous === 1 ? 'supporter has' : 'supporters have' ?> given to this campaign.</p>
                <?php else: ?>
                    <p class="small muted"><?= $accepting ? 'Be the first to support this campaign.' : 'No verified online donations were recorded for this campaign.' ?></p>
                <?php endif; ?>
                <p class="small muted mt-1">Names are shown only with permission; amounts are never shown publicly.</p>
            </div>

            <div class="aside-card mt-3 fr-help-card">
                <h2 class="h3">Questions about this campaign?</h2>
                <p class="small">Write to us for details of the work, documents or reports.</p>
                <a class="btn btn-outline btn-block btn-sm" href="<?= e(page_url('contact')) ?>"><?= icon('mail') ?>Contact us</a>
                <a class="btn btn-ghost btn-block btn-sm" href="<?= e(page_url('start-a-fundraiser')) ?>"><?= icon('flag') ?>Start a fundraiser</a>
            </div>
        </aside>
    </div>
</section>

<?php if ($related): ?>
    <section class="section section-tint">
        <div class="container">
            <?= ui_heading('Other campaigns you can support', 'Keep the momentum going', 'Progress shows verified donations only.') ?>
            <div class="grid grid-3 fr-grid"><?php foreach ($related as $i => $r) { echo campaign_card($r, $i); } ?></div>
        </div>
    </section>
<?php endif; ?>
<?php
render_sections($page);
page_footer();
