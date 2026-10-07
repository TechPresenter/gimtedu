<?php
/** Public website footer (CTA band, link columns, contact, newsletter, legal). Rendered via site_footer($opts). */
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
?>
</main>

<?php if (($opts['cta'] ?? true) !== false): ?>
<section class="relative isolate overflow-hidden bg-brand-900">
  <img src="<?= e(asset('assets/images/site/graduation.jpg')) ?>" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover opacity-25" loading="lazy">
  <div class="absolute inset-0 -z-10 bg-gradient-to-r from-brand-950 via-brand-900/95 to-brand-800/80"></div>
  <div class="container-site flex flex-col items-start justify-between gap-6 py-12 md:flex-row md:items-center">
    <div>
      <h2 class="font-display text-2xl font-extrabold text-white sm:text-3xl"><?= e($opts['cta_title'] ?? 'Ready to Build a Brighter Future?') ?></h2>
      <p class="mt-2 text-white/75"><?= e($opts['cta_text'] ?? 'Join GIMT and take the next step towards your academic and professional success.') ?></p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="<?= e(site_url('apply')) ?>" class="btn btn-accent btn-lg">Apply for Admission<?= icon('arrow-right', 'h-4 w-4') ?></a>
      <a href="<?= e(site_url('programs#brochure')) ?>" class="btn btn-outline-light btn-lg"><?= icon('download', 'h-4 w-4') ?>Download Brochure</a>
    </div>
  </div>
</section>
<?php endif; ?>

<footer class="border-t border-slate-200 bg-white" aria-labelledby="footer-heading">
  <h2 id="footer-heading" class="sr-only">Footer</h2>
  <div class="container-site grid gap-10 py-14 sm:grid-cols-2 lg:grid-cols-12">
    <div class="lg:col-span-4">
      <a href="<?= e(site_url('')) ?>" class="flex items-center gap-3">
        <img src="<?= e(logo_url()) ?>" alt="Global IMT" class="h-16 w-auto" loading="lazy">
        <span class="font-display text-[13px] font-extrabold uppercase leading-tight text-brand-900">Global Institute of<br>Management &amp; Technology</span>
      </a>
      <p class="mt-3 text-sm font-medium tracking-wide text-slate-500"><?= e(setting('tagline', 'Education • Innovation • Opportunity')) ?></p>
      <p class="mt-4 max-w-sm text-sm leading-relaxed text-slate-600"><?= e(setting('footer_about', '')) ?></p>
      <form class="mt-6 max-w-sm" data-ajax-form action="<?= e(base_url('api/public/newsletter')) ?>" data-success="Thank you for subscribing!">
        <label for="newsletter-email" class="text-sm font-semibold text-brand-900">Subscribe to updates</label>
        <div class="mt-2 flex gap-2">
          <input id="newsletter-email" name="email" type="email" required placeholder="Your email address" class="form-input !py-2.5">
          <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
          <button class="btn btn-primary !py-2.5" type="submit">Subscribe</button>
        </div>
        <p class="form-hint" data-form-message></p>
      </form>
    </div>
    <?php foreach ($cols as $heading => $links): ?>
      <div class="lg:col-span-2">
        <h3 class="font-display text-sm font-bold uppercase tracking-wider text-brand-900"><?= e($heading) ?></h3>
        <ul class="mt-4 space-y-2.5 text-sm">
          <?php foreach ($links as $l): ?><li><a href="<?= e(cms_link($l['url'] ?? '#')) ?>" class="text-slate-600 transition hover:text-accent-600"><?= e($l['title']) ?></a></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
    <div class="lg:col-span-2">
      <h3 class="font-display text-sm font-bold uppercase tracking-wider text-brand-900">Connect With Us</h3>
      <div class="mt-4 flex flex-wrap gap-2">
        <?php foreach ($social as $ic => $url): ?>
          <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-brand-900 text-white transition hover:-translate-y-0.5 hover:bg-accent-600" aria-label="<?= e(ucfirst(str_replace('-twitter', '', $ic))) ?>"><?= icon($ic, 'h-4 w-4') ?></a>
        <?php endforeach; ?>
      </div>
      <ul class="mt-5 space-y-3 text-sm text-slate-600">
        <li class="flex gap-2"><?= icon('map-pin', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?><span><?= e(setting('address')) ?></span></li>
        <li class="flex gap-2"><?= icon('mail', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?><a href="mailto:<?= e(setting('email')) ?>" class="hover:text-accent-600"><?= e(setting('email')) ?></a></li>
        <li class="flex gap-2"><?= icon('phone', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?><a href="tel:<?= e(preg_replace('/\s+/', '', (string) setting('phone'))) ?>" class="hover:text-accent-600"><?= e(setting('phone')) ?></a></li>
      </ul>
    </div>
  </div>
  <div class="bg-brand-950 text-white/70">
    <div class="container-site flex flex-col items-center justify-between gap-3 py-4 text-xs sm:flex-row">
      <p><?= e($copyright) ?></p>
      <nav aria-label="Legal" class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
        <?php foreach ($legal as $i => $l): ?><a href="<?= e(cms_link($l['url'])) ?>" class="hover:text-white"><?= e($l['title']) ?></a><?php endforeach; ?>
      </nav>
    </div>
  </div>
</footer>

<?php if ($whatsapp): ?>
<a href="https://wa.me/<?= e(preg_replace('/\D/', '', $whatsapp)) ?>?text=<?= rawurlencode('Hello GIMT, I would like to know more about admissions.') ?>" target="_blank" rel="noopener" class="fixed bottom-5 right-5 z-40 inline-flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg transition hover:scale-105" aria-label="Chat on WhatsApp"><?= icon('whatsapp-brand', 'h-7 w-7') ?></a>
<?php endif; ?>
<button type="button" data-back-to-top class="fixed bottom-24 right-6 z-40 hidden h-10 w-10 items-center justify-center rounded-full bg-brand-900 text-white shadow-lg hover:bg-brand-800" aria-label="Back to top"><?= icon('arrow-up', 'h-5 w-5') ?></button>

<script src="<?= e(asset('assets/js/main.js')) ?>" defer></script>
</body>
</html>
