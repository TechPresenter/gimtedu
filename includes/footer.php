<?php
/**
 * Design v3 footer: navy CTA card on a warm-grey backdrop (cta_band), large white rounded footer card (logo + contact
 * blocks, bold column headings from CMS menus, round social icons, newsletter mini form, expandable "All Programs"
 * directory grouped by department), copyright + developer credit on the warm grey (links use the orange-red hover
 * overlay), floating widgets (WhatsApp "Chat" tab or round button + call button, desktop "Enquire Now" pill + modal,
 * mobile admission bar, back-to-top), the cookie consent banner + preferences (includes/partials/consent.php; "Cookie
 * settings" link in the legal bar) and the site bundle scripts.
 * Rendered via site_footer($opts) — keys documented on site_footer() in includes/config.php.
 */
$opts = $GLOBALS['__site_footer'] ?? [];
$page = $GLOBALS['__site_page'] ?? [];
$name = institute_name();
$minimal = ($opts['footer'] ?? 'full') === 'minimal';
$showCta = ($opts['cta'] ?? true) !== false && !$minimal;
$legal = site_menu('legal') ?: [['title' => 'Privacy Policy', 'url' => '/privacy-policy'], ['title' => 'Terms & Conditions', 'url' => '/terms-and-conditions'], ['title' => 'Refund Policy', 'url' => '/refund-policy'], ['title' => 'Cookie Policy', 'url' => '/cookie-policy'], ['title' => 'Accessibility', 'url' => '/accessibility']];
// The "Policies & Disclosures" hub (all policies) is always reachable from the legal bar once it is published.
if (!array_filter($legal, fn ($l) => rtrim((string) ($l['url'] ?? ''), '/') === '/policies') && function_exists('seo_path_exists') && seo_path_exists('/policies')) {
    $legal[] = ['title' => 'All Policies', 'url' => '/policies'];
}
$cookieLink = function_exists('consent_settings_link') ? consent_settings_link() : '';
$copyright = str_replace('{year}', date('Y'), setting('copyright_text', '© {year} Global Institute of Management & Technology (GIMT). All Rights Reserved.'));
$fPhone = (string) setting('phone', '+91 9955446477');
$fEmail = (string) setting('email', 'info@gimt.ac.in');
$active = $page['active'] ?? trim(substr(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', strlen(base_path())), '/');
$wa = ($page['chat'] ?? true) !== false ? site_whatsapp_url() : null;
$mcta = $opts['mobile_cta'] ?? (setting('site_mobile_cta', '1') === '1' && !in_array($active, ['apply'], true));
$creditText = (string) setting('footer_credit_text', 'Design, Maintain And Developed By');
$creditName = (string) setting('footer_credit_name', 'Appsgain Technologies Private Limited');
$creditUrl = (string) setting('footer_credit_url', 'https://appsgain.in');
$creditHost = (string) (parse_url($creditUrl, PHP_URL_HOST) ?: $creditUrl);
$creditHtml = $creditName !== '' ? '<p class="footer-credit">' . e($creditText) . ' <a href="' . e($creditUrl) . '" target="_blank" rel="noopener" aria-label="' . e($creditName . ' (opens ' . $creditHost . ' in a new tab)') . '">' . e($creditName) . '</a></p>' : '';
$enquiryTab = ($opts['enquiry_tab'] ?? (setting('site_enquiry_tab', '1') === '1' && !in_array($active, ['apply', 'contact', 'admissions-open'], true) && ($page['header'] ?? '') !== 'minimal'));
// Enquiry popup (enquiry_modal()): rendered with the Enquire Now tab; on the homepage it also opens by itself once per
// visit (settings enquiry_popup_auto / enquiry_popup_delay — core/popup.ts); ?popup=1 opens it immediately on any page
// (previews / screenshots). Never auto-opens on /apply or /contact.
$popupPreview = ($_GET['popup'] ?? '') === '1';
$popupAuto = ($opts['popup_auto'] ?? ($active === 'home' && setting('enquiry_popup_auto', '1') === '1')) ? max(3, min(60, (int) setting('enquiry_popup_delay', '8'))) : 0;
$adminPreview = function_exists('site_admin_preview') && site_admin_preview(); // admin "Real website" frame: nothing opens by itself
if ($adminPreview) {
    $popupAuto = 0;
}
$renderModal = ($enquiryTab || $popupAuto > 0 || $popupPreview) && ($page['header'] ?? '') !== 'minimal' && !in_array($active, ['apply'], true);
$chatStyle = (string) ($opts['chat'] ?? setting('site_chat_style', 'tab'));
?>
</main>

<?php if ($showCta): ?>
<?= cta_band([
    'title' => $opts['cta_title'] ?? 'Ready to Build a Brighter Future?',
    'highlight' => $opts['cta_highlight'] ?? false,
    // yellow-gold marker on the key phrase of the default title (pages may pass cta_gold for their own title)
    'gold' => $opts['cta_gold'] ?? (isset($opts['cta_title']) || isset($opts['cta_highlight']) ? false : 'Brighter Future'),
    'text' => $opts['cta_text'] ?? 'Join GIMT and take the next step towards your academic and professional success.',
    'primary' => $opts['cta_primary'] ?? ['label' => 'Apply for Admission', 'url' => '/apply'],
    'secondary' => $opts['cta_secondary'] ?? ['label' => 'Download Brochure', 'url' => '/programs?brochure=1', 'icon' => 'download'],
    'image' => $opts['cta_image'] ?? 'assets/images/site/graduation.jpg',
]) ?>
<?php endif; ?>

<?php if ($minimal): ?>
<footer class="footer-min" aria-label="Footer">
  <?php /* campaign pages: the minimal header shows the Light / Dark / System control only from md — phones get it here */
  $minTheme = function_exists('site_theme_toggle') ? site_theme_toggle(['variant' => 'header']) : ''; if ($minTheme !== ''): ?>
  <div class="container-site mb-4 flex items-center justify-center gap-3 md:hidden">
    <span class="text-[11px] font-bold uppercase tracking-[0.14em] text-slate-500" aria-hidden="true">Appearance</span><?= $minTheme ?>
  </div>
  <?php endif; ?>
  <div class="container-site footer-bottom !mt-0">
    <div class="footer-legal-copy"><p><?= e($copyright) ?></p><?= $creditHtml ?></div>
    <div class="footer-visitors"><?php if (is_file(__DIR__ . '/partials/visitor_counter.php')) { require_once __DIR__ . '/partials/visitor_counter.php'; echo visitor_counter(); } ?></div>
    <nav aria-label="Legal" class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
      <a href="<?= e(site_url('contact')) ?>">Help Center</a>
      <?php foreach (array_slice($legal, 0, 2) as $l): ?><a href="<?= e(cms_link($l['url'])) ?>"><?= e($l['title']) ?></a><?php endforeach; ?>
      <?= $cookieLink ?>
    </nav>
  </div>
</footer>
<?php else:
    $cols = [
        'Institute' => site_menu('footer_quick') ?: [['title' => 'About GIMT', 'url' => '/about'], ['title' => 'Vision & Mission', 'url' => '/about#vision'], ['title' => 'Leadership', 'url' => '/leadership'], ['title' => 'Our Faculty', 'url' => '/faculty'], ['title' => 'Contact Us', 'url' => '/contact']],
        'Programs' => site_menu('footer_academics') ?: [['title' => 'All Programs', 'url' => '/programs'], ['title' => 'Undergraduate', 'url' => '/programs?category=ug'], ['title' => 'Postgraduate', 'url' => '/programs?category=pg'], ['title' => 'Diploma', 'url' => '/programs?category=diploma'], ['title' => 'Certificate Courses', 'url' => '/certifications'], ['title' => 'Academics', 'url' => '/academics']],
        'Admissions' => site_menu('footer_admissions') ?: [['title' => 'Admission Process', 'url' => '/admissions#process'], ['title' => 'Apply Online', 'url' => '/apply'], ['title' => 'Fees & Scholarships', 'url' => '/admissions#fees'], ['title' => 'Scholarships', 'url' => '/admissions#scholarships'], ['title' => 'Enquire Now', 'url' => '/admissions#enquiry'], ['title' => 'Verify Certificate', 'url' => '/verify-certificate']],
        'Student Corner' => site_menu('footer_support') ?: [['title' => 'Student Portal', 'url' => '/portal/student/login'], ['title' => 'Faculty Portal', 'url' => '/portal/faculty/login'], ['title' => 'Campus Life', 'url' => '/campus'], ['title' => 'Placements', 'url' => '/placement'], ['title' => 'Notices', 'url' => '/notices'], ['title' => 'FAQs', 'url' => '/faq']],
    ];
    $social = social_links();
    $dirPrograms = ($opts['directory'] ?? true) ? site_programs() : [];
    $courses = $dirPrograms ? site_program_courses() : [];
    $byDept = [];
    foreach ($dirPrograms as $p) {
        // certificate courses are listed by their group (Management & Business, Computer & Technology …) instead of one long department list
        $certGroup = site_cert_group_key($p);
        $byDept[$certGroup !== '' ? 'Certificate Courses — ' . site_cert_groups()[$certGroup]['label'] : ($p['department_name'] ?: program_level_label($p['level']))][] = $p;
    }
    ?>
<footer class="footer-v2<?= $showCta ? ' !pt-6' : '' ?>" aria-labelledby="footer-heading">
  <h2 id="footer-heading" class="sr-only">Footer</h2>
  <div class="container-site">
    <div class="footer-card">
      <div class="grid gap-x-8 gap-y-10 lg:grid-cols-12">
        <div class="lg:col-span-4">
          <a href="<?= e(site_url('')) ?>" class="inline-flex items-center gap-3">
            <img src="<?= e(logo_url()) ?>" alt="Global IMT" class="h-14 w-auto" loading="lazy" width="116" height="56">
            <span class="text-[11px] font-bold uppercase leading-tight tracking-[0.06em] text-brand-900">Global Institute of<br>Management &amp; Technology</span>
          </a>
          <p class="footer-tagline"><?= e(setting('tagline', 'Education • Innovation • Opportunity')) ?></p>
          <ul class="mt-5 space-y-3">
            <?php if ($addr = setting('address')): ?><li class="footer-contact"><?= icon('map-pin', '') ?><span><?= e($addr) ?></span></li><?php endif; ?>
            <li class="footer-contact"><?= icon('phone', '') ?><a href="<?= e(site_tel($fPhone)) ?>" class="hover:text-accent-700"><?= e($fPhone) ?></a></li>
            <li class="footer-contact"><?= icon('mail', '') ?><a href="mailto:<?= e($fEmail) ?>" class="break-all hover:text-accent-700"><?= e($fEmail) ?></a></li>
            <?php if ($hours = setting('office_hours')): ?><li class="footer-contact"><?= icon('clock', '') ?><span><?= e($hours) ?></span></li><?php endif; ?>
          </ul>
          <?php $waLink = site_whatsapp_url(); if ($social || $waLink): ?>
            <div class="mt-6 flex flex-wrap gap-2.5">
              <?php foreach ($social as $ic => $url): ?>
                <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer" class="social-btn" aria-label="<?= e(['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'x-twitter' => 'X (Twitter)'][$ic] ?? ucfirst($ic)) ?> (opens in a new tab)"><?= icon($ic, 'h-4 w-4') ?></a>
              <?php endforeach; ?>
              <?php if ($waLink): ?><a href="<?= e($waLink) ?>" target="_blank" rel="noopener" class="social-btn social-btn-wa" aria-label="Chat with us on WhatsApp (opens in a new tab)"><?= icon('whatsapp-brand', 'h-[18px] w-[18px]') ?></a><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="grid grid-cols-2 gap-x-6 gap-y-9 sm:grid-cols-4 lg:col-span-8">
          <?php foreach ($cols as $heading => $links): ?>
            <div>
              <h3 class="footer-heading"><?= e($heading) ?></h3>
              <ul class="mt-4 space-y-2.5">
                <?php foreach ($links as $l): ?><li><a href="<?= e(cms_link($l['url'] ?? '#')) ?>" class="footer-link"><?= e($l['title']) ?></a></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
          <form class="col-span-2 sm:col-span-4" data-ajax-form<?= site_secure_form_attrs('newsletter') ?> action="<?= e(base_url('api/public/newsletter')) ?>" method="post" data-success="Thank you for subscribing! Please check your inbox to confirm.">
            <?= csrf_field() ?><?= site_secure_form_fields() ?>
            <input type="hidden" name="source" value="footer">
            <label for="newsletter-email" class="footer-heading block">Stay updated on admissions &amp; campus news</label>
            <div class="newsletter-field mt-3 max-w-lg">
              <?= icon('mail', 'h-4 w-4 shrink-0 text-slate-400') ?>
              <input id="newsletter-email" name="email" type="email" required placeholder="Your email address" autocomplete="email">
              <button class="btn btn-primary btn-sm" type="submit">Subscribe</button>
            </div>
            <p class="form-error" data-error-for="email"></p>
            <p class="form-message max-w-lg" data-form-message></p>
          </form>
        </div>
      </div>

      <?php if ($byDept): ?>
      <div data-accordion="multi">
        <details class="footer-dir">
          <summary class="footer-dir-toggle">Show All Programs &amp; Specialisations<svg viewBox="0 0 10 6" class="h-[6px] w-[10px]" aria-hidden="true"><path d="M0 0h10L5 6z" fill="currentColor"/></svg></summary>
          <div class="pb-2">
            <?php foreach ($byDept as $dept => $list): ?>
              <div class="footer-dir-group">
                <h3><?= e($dept) ?></h3>
                <ul class="footer-dir-links">
                  <?php foreach ($list as $p): $url = site_url('programs/' . $p['slug']); ?>
                    <li><a href="<?= e($url) ?>"><?= e($p['name']) ?><?= ($sfx = program_title_suffix($p)) !== '' ? ' (' . e($sfx) . ')' : '' ?></a></li>
                    <?php foreach ($courses[(int) $p['id']] ?? [] as $c): ?><li><a href="<?= e($url) ?>#specializations"><?= e(str_replace(' - ', ' – ', $c['name'])) ?></a></li><?php endforeach; ?>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endforeach; ?>
          </div>
        </details>
      </div>
      <?php endif; ?>
    </div>
    <div class="footer-bottom">
      <div class="footer-legal-copy"><p><?= e($copyright) ?></p><?= $creditHtml ?></div>
      <div class="footer-visitors"><?php if (is_file(__DIR__ . '/partials/visitor_counter.php')) { require_once __DIR__ . '/partials/visitor_counter.php'; echo visitor_counter(); } ?></div>
      <nav aria-label="Legal" class="flex flex-wrap items-center justify-center gap-x-5 gap-y-1">
        <?php foreach ($legal as $l): ?><a href="<?= e(cms_link($l['url'])) ?>"><?= e($l['title']) ?></a><?php endforeach; ?>
        <?= $cookieLink ?>
      </nav>
    </div>
  </div>
</footer>
<?php endif; ?>

<?php if ($wa && $chatStyle === 'button'): ?>
<?= whatsapp_button() ?>
<?php elseif ($wa && $chatStyle !== 'none'): ?>
<a href="<?= e(site_tel($fPhone)) ?>" class="call-fab no-print" aria-label="Call admissions: <?= e($fPhone) ?>"><?= icon('phone', 'h-4 w-4') ?></a>
<a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="chat-tab no-print" aria-label="Chat with us on WhatsApp (opens in a new tab)"><span class="chat-tab-text" aria-hidden="true">Chat</span><?= icon('whatsapp-brand', 'h-7 w-7') ?></a>
<?php endif; ?>
<?php if ($enquiryTab): ?>
<?= enquiry_tab() ?>
<?php endif; ?>
<?php if ($renderModal): ?>
<?= enquiry_modal(['source' => ($popupAuto > 0 ? 'enquiry-popup-' : 'enquiry-tab-') . preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $active)), 'auto' => $popupAuto]) ?>
<?php endif; ?>
<?php /* CMS › Banners "Home popup" (homepage) / Announcements "Popup" (other pages): once per visit, core/popup.ts */ ?>
<?= function_exists('notice_modal') && (!$adminPreview || (function_exists('site_banner_preview') && (site_banner_preview()['placement'] ?? '') === 'popup')) ? notice_modal() : '' ?>
<?php if ($mcta): ?>
<?= mobile_cta_bar(is_array($mcta) ? $mcta : []) ?>
<?php endif; ?>
<?= back_to_top() ?>

<?= function_exists('consent_banner') ? consent_banner() : '' ?>
<?= site_assets_footer() ?>
<?php if (($opts['chatbot'] ?? true) !== false && is_file(__DIR__ . '/partials/chatbot.php')) { require_once __DIR__ . '/partials/chatbot.php'; if (function_exists('chatbot_widget')) { echo chatbot_widget(); } } ?>
<?= site_custom_code('body') ?>
</body>
</html>
