<?php
require_once __DIR__ . '/functions.php';

$slug = (string) ($_GET['slug'] ?? '');
$c = db_row(campaign_sql() . " WHERE c.slug = ? AND c.status <> 'draft'", [$slug]);
if (!$c) {
    not_found();
}
$pct = campaign_percent($c);
$days = campaign_days_left($c);
$recent = db_rows("SELECT donor_name, is_anonymous, amount, paid_at FROM donations
                   WHERE campaign_id = ? AND status = 'success' ORDER BY paid_at DESC LIMIT 8", [$c['id']]);
$shareUrl = abs_url('campaign-details.php?slug=' . urlencode($c['slug']));
$categories = campaign_categories();

$pageTitle = $c['title'];
$pageDescription = $c['short_description'];
$ogImage = $c['image'] ?: null;
require __DIR__ . '/header.php';

page_banner($c['title'], $c['short_description'], ['Campaigns' => 'campaigns.php']);
?>
<section class="section">
    <div class="container detail-layout">
        <div>
            <div class="detail-hero-img"><img src="<?= e(campaign_image($c)) ?>" alt="<?= e($c['title']) ?>"></div>
            <p class="eyebrow"><?= e($categories[$c['category']] ?? humanize($c['category'])) ?><?= $c['location'] ? ' · ' . e($c['location']) : '' ?></p>
            <article class="prose"><?= $c['description'] ?></article>
            <div class="share-row">
                <strong class="small">Share this campaign:</strong>
                <a href="https://wa.me/?text=<?= e(urlencode($c['title'] . ' ' . $shareUrl)) ?>" target="_blank" rel="noopener noreferrer"><?= icon('whatsapp') ?>WhatsApp</a>
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?= e(urlencode($shareUrl)) ?>" target="_blank" rel="noopener noreferrer"><?= icon('facebook') ?>Facebook</a>
                <a href="https://twitter.com/intent/tweet?url=<?= e(urlencode($shareUrl)) ?>&amp;text=<?= e(urlencode($c['title'])) ?>" target="_blank" rel="noopener noreferrer"><?= icon('twitter') ?>X</a>
                <button type="button" data-copy="<?= e($shareUrl) ?>"><?= icon('external') ?>Copy link</button>
            </div>
        </div>
        <aside class="sticky-card">
            <div class="raise-box">
                <div class="raise-amount"><?= e(money($c['raised'])) ?></div>
                <div class="raise-sub">raised<?= (float) $c['goal_amount'] > 0 ? ' of ' . e(money($c['goal_amount'])) . ' goal' : '' ?></div>
                <?php if ((float) $c['goal_amount'] > 0): ?>
                    <div class="progress progress-lg" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Funding progress"><span style="width:<?= $pct ?>%"></span></div>
                <?php endif; ?>
                <div class="raise-grid">
                    <div><strong><?= $pct ?>%</strong><small>funded</small></div>
                    <div><strong><?= (int) $c['donor_count'] ?></strong><small>donations</small></div>
                    <div><strong><?= $c['status'] !== 'active' ? '—' : ($days === null ? '∞' : $days) ?></strong><small><?= $c['status'] !== 'active' ? e(humanize($c['status'])) : 'days left' ?></small></div>
                </div>
                <?php if ($c['status'] === 'active'): ?>
                    <a class="btn btn-donate btn-lg btn-block" href="<?= e(url('donate.php?campaign=' . (int) $c['id'])) ?>"><?= icon('heart') ?>Donate to this campaign</a>
                <?php else: ?>
                    <div class="notice notice-green"><?= icon('check-circle') ?><div>This campaign is <?= e(strtolower(humanize($c['status']))) ?>. Thank you to everyone who contributed.</div></div>
                    <a class="btn btn-donate btn-block mt-2" href="<?= e(url('donate.php')) ?>">Make a general donation</a>
                <?php endif; ?>
                <p class="verified-note"><?= icon('shield') ?>Amount raised includes only donations verified with our payment gateway or by our finance team.</p>
            </div>
            <?php if ($recent): ?>
                <div class="raise-box mt-3">
                    <h3>Recent supporters</h3>
                    <ul class="donor-list">
                        <?php foreach ($recent as $d): ?>
                            <li><span><?= icon('heart') ?> <?= e($d['is_anonymous'] ? 'Anonymous donor' : public_donor_name($d['donor_name'])) ?></span><strong><?= e(money($d['amount'])) ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
