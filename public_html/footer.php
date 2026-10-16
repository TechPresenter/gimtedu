<?php
/**
 * Public site footer. Called by page_footer().
 * Columns come from Admin > Menus (footer_about, footer_involved, footer_legal);
 * blocks (newsletter, contact, social, registration) are switched on/off in Admin > Settings > Footer.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$footerMenus = [];
foreach (db_rows("SELECT menu_key, name FROM menus WHERE menu_key LIKE 'footer\\_%' ORDER BY id") as $fm) {
    $items = menu_tree($fm['menu_key']);
    if ($items) {
        $footerMenus[] = ['title' => trim(preg_replace('/^Footer:\s*/i', '', $fm['name'])), 'items' => $items];
    }
}
$contactEmail = setting('contact_email');
$contactPhone = setting('contact_phone');
// "Cookie settings" whenever the cookie notice can appear (Google Analytics and/or our own return-visit cookie)
$consentNeeded = ga_enabled() || (function_exists('analytics_consent_banner_needed') && analytics_consent_banner_needed());
$regBits = array_filter([
    'CIN' => setting('cin_number'), 'NGO ID' => setting('ngo_id'), 'Reg. No.' => setting('registration_number'), 'PAN' => setting('pan_number'),
    '12A' => setting('reg_12a_number'), '80G' => setting('reg_80g_number'),
    'Darpan ID' => setting('darpan_id') !== setting('ngo_id') ? setting('darpan_id') : '', 'MSME' => setting('msme_udyam_number'),
]);
?>
</main>

<footer class="site-footer">
    <div class="footer-cta">
        <div class="container footer-cta-inner">
            <div>
                <p class="footer-cta-title"><?= e(setting('site_tagline_en', 'Together, let us build a better tomorrow')) ?></p>
                <p class="hindi footer-cta-hindi" lang="hi"><?= e(setting('site_tagline')) ?></p>
            </div>
            <div class="btn-row">
                <a class="btn btn-donate btn-3d" href="<?= e(page_url('donate')) ?>"><?= icon('heart') ?>Donate Now</a>
                <a class="btn btn-light btn-3d" href="<?= e(page_url('become-a-volunteer')) ?>"><?= icon('users') ?>Become a Volunteer</a>
            </div>
        </div>
    </div>

    <div class="container footer-grid">
        <div class="footer-about">
            <a class="footer-brand" href="<?= e(url('/')) ?>"><?= brand_lockup('footer') ?><span class="sr-only"> - home</span></a>
            <?php if (setting('brand_slogan')): ?><p class="footer-slogan"><?= e(setting('brand_slogan')) ?></p><?php endif; ?>
            <p><?= e(setting('footer_about_text', setting('site_description'))) ?></p>
            <?php if (setting('focus_areas_text') !== ''): ?><p class="footer-focus"><?= e(setting('focus_areas_text')) ?></p><?php endif; ?>
            <?php if (setting('footer_show_registration', '1') === '1' && $regBits): ?>
                <?php if (setting('registration_authority') !== ''): ?><p class="footer-reg-note"><?= icon('shield') ?>Registered with the <?= e(preg_replace('/^the\s+/i', '', setting('registration_authority'))) ?></p><?php endif; ?>
                <ul class="footer-reg">
                    <?php foreach ($regBits as $label => $value): ?><li><span><?= e($label) ?></span><?= e($value) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (setting('footer_show_social', '1') === '1' && social_links()): ?>
                <div class="footer-social">
                    <?php foreach (social_links() as $key => $link): ?>
                        <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener" aria-label="<?= e($link['label']) ?>" data-tip="<?= e($link['label']) ?>"><?= icon($key) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php foreach ($footerMenus as $fm): ?>
            <div class="footer-col">
                <h2 class="footer-title"><?= e($fm['title']) ?></h2>
                <ul>
                    <?php foreach ($fm['items'] as $fi): ?>
                        <li><a href="<?= e($fi['url']) ?>"<?= $fi['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= icon('chevron-right') ?><?= e($fi['label']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>

        <div class="footer-col footer-contact">
            <?php if (setting('footer_show_contact', '1') === '1'): ?>
                <h2 class="footer-title">Contact</h2>
                <ul class="footer-contact-list">
                    <?php if (setting('contact_address')): ?><li><?= icon('map-pin') ?><span><?= e(setting('contact_address')) ?></span></li><?php endif; ?>
                    <?php if ($contactEmail): ?><li><?= icon('mail') ?><a href="mailto:<?= e($contactEmail) ?>"><?= str_replace('@', '<wbr>@', e($contactEmail)) ?></a></li><?php endif; ?>
                    <?php if ($contactPhone): ?><li><?= icon('phone') ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $contactPhone)) ?>"><?= e($contactPhone) ?></a></li><?php endif; ?>
                    <?php if (setting('office_hours')): ?><li><?= icon('clock') ?><span><?= e(setting('office_hours')) ?></span></li><?php endif; ?>
                </ul>
            <?php endif; ?>
            <?php if (setting('newsletter_enabled', '1') === '1' && setting('footer_show_newsletter', '1') === '1' && empty($GLOBALS['newsletter_section_rendered'])): ?>
                <form class="newsletter-form" method="post" action="<?= e(page_url('newsletter')) ?>">
                    <?= csrf_field() ?><?= honeypot_field() ?>
                    <input type="hidden" name="source" value="footer">
                    <label for="nl-email">Get updates on our work</label>
                    <div class="newsletter-row">
                        <input id="nl-email" type="email" name="email" required maxlength="190" placeholder="Your email address" autocomplete="email">
                        <button class="btn btn-donate btn-sm" type="submit" aria-label="Subscribe"><?= icon('send') ?></button>
                    </div>
                    <label class="checkbox newsletter-consent"><input type="checkbox" name="consent" value="1" required><span><?= e(setting('newsletter_consent_text')) ?></span></label>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer-bottom">
        <div class="container footer-bottom-inner">
            <p><?= e(str_replace('{year}', date('Y'), setting('footer_copyright', '© {year} ' . site_name() . '. All Rights Reserved.'))) ?></p>
            <?php if (setting('footer_bottom_links', '1') === '1'): ?>
                <nav aria-label="Legal"><a href="<?= e(page_url('privacy-policy')) ?>">Privacy</a><a href="<?= e(page_url('terms-conditions')) ?>">Terms</a><a href="<?= e(page_url('donation-refund-policy')) ?>">Refunds</a><a href="<?= e(page_url('report-fraud')) ?>">Report fraud</a><a href="<?= e(page_url('sitemap')) ?>">Sitemap</a><?php if ($consentNeeded): ?><button type="button" class="link-button" data-consent-reset hidden>Cookie settings</button><?php endif; ?></nav>
            <?php elseif ($consentNeeded): ?>
                <nav aria-label="Legal"><button type="button" class="link-button" data-consent-reset hidden>Cookie settings</button></nav>
            <?php endif; ?>
        </div>
    </div>
</footer>

<?= render_floating_widgets() ?>
<?= render_fraud_alert() ?>
<?= render_security_notice() ?>
<?= render_consent_banner() ?>
<?php // module widgets (AI assistant, analytics beacon ...): each hook returns HTML and may add to $GLOBALS['extraScripts']
foreach (['render_assistant_widget', 'render_analytics_beacon'] as $footerHook) {
    if (function_exists($footerHook)) {
        echo $footerHook();
    }
} ?>
<?php $faqSchema = faq_schema(); if ($faqSchema) { echo json_ld($faqSchema); } ?>
<script src="<?= e(asset('assets/js/script.js')) ?>" defer></script>
<?php foreach ($GLOBALS['extraScripts'] ?? [] as $src): ?><script src="<?= e($src) ?>" defer></script><?php endforeach; ?>
</body>
</html>
