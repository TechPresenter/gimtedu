<?php
/**
 * Public website footer: CTA band, animated gradient band, link columns, contact, newsletter (AJAX via the TS form
 * handler, degrades to a normal POST), legal bar, WhatsApp + back-to-top buttons and the site bundle scripts.
 * Rendered via site_footer($opts): cta (bool, default true), cta_title, cta_text, cta_primary [label,url],
 * cta_secondary [label,url,icon], cta_image.
 */
$opts = $GLOBALS['__site_footer'] ?? [];
$name = institute_name();
$cols = [
    'Quick Links' => site_menu('footer_quick') ?: [['title' => 'About', 'url' => '/about'], ['title' => 'Programs', 'url' => '/programs'], ['title' => 'Admissions', 'url' => '/admissions'], ['title' => 'Student Portal', 'url' => '/student-portal'], ['title' => 'Faculty Portal', 'url' => '/faculty-portal'], ['title' => 'Notices', 'url' => '/notices']],
    'Academics' => site_menu('footer_academics') ?: [['title' => 'Courses', 'url' => '/programs'], ['title' => 'Departments', 'url' => '/academics#departments'], ['title' => 'Faculty', 'url' => '/academics#faculty'], ['title' => 'Examinations', 'url' => '/notices?category=examination'], ['title' => 'Research', 'url' => '/academics#research'], ['title' => 'Academic Calendar', 'url' => '/academics#calendar']],
    'Support' => site_menu('footer_support') ?: [['title' => 'Helpdesk', 'url' => '/contact'], ['title' => 'Admission Enquiry', 'url' => '/admissions#enquiry'], ['title' => 'Certificate Verification', 'url' => '/verify-certificate'], ['title' => 'Contact Us', 'url' => '/contact'], ['title' => 'FAQs', 'url' => '/faq']],
];
$legal = site_menu('legal') ?: [['title' => 'Privacy Policy', 'url' => '/privacy-policy'], ['title' => 'Terms & Conditions', 'url' => '/terms-and-conditions'], ['title' => 'Refund Policy', 'url' => '/refund-policy'], ['title' => 'Accessibility', 'url' => '/accessibility']];
$social = social_links();
$whatsapp = setting('whatsapp_number');
$copyright = str_replace('{year}', date('Y'), setting('copyright_text', '© {year} Global Institute of Management & Technology (GIMT). All Rights Reserved.'));
$fPhone = (string) setting('phone', '+91 9955446477');
$fEmail = (string) setting('email', 'info@gimt.ac.in');
?>
</main>

<?php if (($opts['cta'] ?? true) !== false): ?>
<?= cta_band([
    'title' => $opts['cta_title'] ?? 'Ready to Build a Brighter Future?',
    'text' => $opts['cta_text'] ?? 'Join GIMT and take the next step towards your academic and professional success.',
    'primary' => $opts['cta_primary'] ?? ['label' => 'Apply for Admission', 'url' => '/apply'],
    'secondary' => $opts['cta_secondary'] ?? ['label' => 'Download Brochure', 'url' => '/programs#brochure', 'icon' => 'download'],
    'image' => $opts['cta_image'] ?? 'assets/images/site/graduation.jpg',
]) ?>
<?php endif; ?>

<footer aria-labelledby="footer-heading">
  <h2 id="footer-heading" class="sr-only">Footer</h2>
  <div class="footer-band" aria-hidden="true"></div>
  <div class="footer-main">
    <div class="pointer-events-none absolute inset-y-0 right-0 -z-10 w-1/2 pattern-dots pattern-fade animate-pattern-drift opacity-50" aria-hidden="true"></div>
    <div class="container-site grid grid-cols-2 gap-x-6 gap-y-10 py-14 lg:grid-cols-12" data-stagger="70">
      <div class="col-span-2 lg:col-span-4">
        <a href="<?= e(site_url('')) ?>" class="flex items-center gap-3">
          <img src="<?= e(logo_url()) ?>" alt="Global IMT" class="h-16 w-auto" loading="lazy" width="150" height="64">
          <span class="font-display text-[13px] font-extrabold uppercase leading-tight text-brand-900">Global Institute of<br>Management &amp; Technology</span>
        </a>
        <p class="mt-3 text-sm font-semibold tracking-wide text-brand-600"><?= e(setting('tagline', 'Education • Innovation • Opportunity')) ?></p>
        <?php if ($about = setting('footer_about', '')): ?><p class="mt-3 max-w-sm text-sm leading-relaxed text-slate-600"><?= e($about) ?></p><?php endif; ?>
        <form class="mt-6 max-w-sm" data-ajax-form action="<?= e(base_url('api/public/newsletter')) ?>" method="post" data-success="Thank you for subscribing! Please check your inbox to confirm.">
          <?= csrf_field() ?>
          <label for="newsletter-email" class="text-sm font-semibold text-brand-900">Subscribe to admission &amp; campus updates</label>
          <div class="newsletter-field mt-2">
            <?= icon('mail', 'h-4 w-4 shrink-0 text-slate-400') ?>
            <input id="newsletter-email" name="email" type="email" required placeholder="Your email address" autocomplete="email">
            <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
            <button class="btn btn-primary btn-sm" type="submit">Subscribe</button>
          </div>
          <p class="form-error" data-error-for="email"></p>
          <p class="form-message" data-form-message></p>
        </form>
      </div>
      <?php foreach ($cols as $heading => $links): ?>
        <div class="lg:col-span-2">
          <h3 class="font-display text-sm font-bold uppercase tracking-wider text-brand-900"><?= e($heading) ?></h3>
          <ul class="mt-4 space-y-2.5 text-sm">
            <?php foreach ($links as $l): ?><li><a href="<?= e(cms_link($l['url'] ?? '#')) ?>" class="footer-link"><?= e($l['title']) ?></a></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
      <div class="lg:col-span-2">
        <h3 class="font-display text-sm font-bold uppercase tracking-wider text-brand-900">Connect With Us</h3>
        <?php if ($social): ?>
          <div class="mt-4 flex flex-wrap gap-1">
            <?php foreach ($social as $ic => $url): ?>
              <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer" class="social-btn" aria-label="<?= e(ucfirst(str_replace('-twitter', '', $ic))) ?>"><?= icon($ic, 'h-4 w-4') ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <ul class="mt-5 space-y-3 text-sm text-slate-600">
          <?php if ($addr = setting('address')): ?><li class="flex gap-2"><?= icon('map-pin', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?><span><?= e($addr) ?></span></li><?php endif; ?>
          <li class="flex gap-2"><?= icon('mail', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?><a href="mailto:<?= e($fEmail) ?>" class="break-all hover:text-accent-600"><?= e($fEmail) ?></a></li>
          <li class="flex gap-2"><?= icon('phone', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?><a href="tel:<?= e(preg_replace('/\s+/', '', $fPhone)) ?>" class="hover:text-accent-600"><?= e($fPhone) ?></a></li>
        </ul>
      </div>
    </div>
  </div>
  <div class="bg-brand-950 text-white/70">
    <div class="container-site flex flex-col items-center justify-between gap-3 py-4 text-xs sm:flex-row">
      <p><?= e($copyright) ?></p>
      <nav aria-label="Legal" class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
        <?php foreach ($legal as $l): ?><a href="<?= e(cms_link($l['url'])) ?>" class="underline-grow hover:text-white"><?= e($l['title']) ?></a><?php endforeach; ?>
      </nav>
    </div>
  </div>
</footer>

<?php if ($whatsapp): ?>
<a href="https://wa.me/<?= e(preg_replace('/\D/', '', $whatsapp)) ?>?text=<?= rawurlencode('Hello GIMT, I would like to know more about admissions.') ?>" target="_blank" rel="noopener" class="whatsapp-fab pulse-ring text-[#25D366]" aria-label="Chat on WhatsApp"><span class="text-white"><?= icon('whatsapp-brand', 'h-7 w-7') ?></span></a>
<?php endif; ?>
<button type="button" data-back-to-top class="back-to-top" aria-label="Back to top" aria-hidden="true" tabindex="-1">
  <svg class="btt-ring-svg" viewBox="0 0 60 60" aria-hidden="true"><circle class="btt-track" cx="30" cy="30" r="27" pathLength="100"/><circle class="btt-ring" cx="30" cy="30" r="27" pathLength="100"/></svg>
  <?= icon('arrow-up', 'btt-arrow h-5 w-5') ?>
</button>

<?= site_assets_footer() ?>
</body>
</html>
