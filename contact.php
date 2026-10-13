<?php
/**
 * Contact (/contact) — mockup 05-contact: hero, five contact cards, "Send Us a Message" (EnquiryForm island →
 * /api/public/contact) + map, campus locations, quick enquiry options, Connect With Us (+ WhatsApp), FAQs, CTA band.
 * ?type=<admission|program|scholarship|campus_visit|placement|student_support|general> preselects the enquiry type.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_forms.php';

$c = sf_contact();
$types = pf_contact_types();
$type = (string) ($_GET['type'] ?? '');
$type = isset($types[$type]) && empty($types[$type]['hidden']) ? $type : '';
$faqs = sf_faqs(['general', 'admissions', 'campus'], sf_contact_faq_defaults(), 6);
$social = social_links();
$city = (string) setting('city', 'Greater Noida');
$heroImage = 'assets/images/site/hero-campus-students.jpg';
$name = institute_name();

site_header([
    'title' => 'Contact Us — Admissions, Campus Visits & Support',
    'description' => 'Contact Global Institute of Management & Technology (GIMT), ' . $city . '. Call ' . $c['phone'] . ', email ' . $c['email'] . ', visit our Knowledge Park campus or send us a message — we reply within 24 hours.',
    'active' => 'contact', 'seo' => ['route', '/contact'],
    'preload' => [asset($heroImage)],
    'schema' => [
        sf_breadcrumb_schema(['Home' => '', 'Contact Us' => 'contact']),
        ['@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => 'Contact ' . $name, 'url' => absolute_url('contact'),
         'mainEntity' => ['@type' => 'CollegeOrUniversity', 'name' => $name, 'telephone' => $c['phone'], 'email' => $c['email'],
             'address' => ['@type' => 'PostalAddress', 'streetAddress' => $c['address'], 'addressLocality' => $city, 'addressRegion' => setting('state', 'Uttar Pradesh'), 'postalCode' => setting('pincode', '201310'), 'addressCountry' => 'IN'],
             'contactPoint' => [
                 ['@type' => 'ContactPoint', 'contactType' => 'admissions', 'telephone' => $c['phone'], 'email' => $c['admission_email'], 'areaServed' => 'IN', 'availableLanguage' => ['English', 'Hindi']],
                 ['@type' => 'ContactPoint', 'contactType' => 'customer support', 'email' => $c['support_email'], 'areaServed' => 'IN'],
             ]]],
        sf_faq_schema($faqs),
    ],
]);

page_hero(
    'Contact Us',
    "We're Here to Help",
    ['Contact Us'],
    $heroImage,
    'Get in touch with us for admission enquiries, academic information, program details or any other support. Our team is always ready to assist you.',
    [],
    ['image_alt' => 'GIMT students in front of the campus building', 'size' => 'lg']
);

$cards = [
    ['icon' => 'phone', 'tint' => 'bg-brand-600', 'title' => 'Call Us', 'main' => $c['phone'], 'href' => sf_tel($c['phone']), 'sub' => $c['hours']],
    ['icon' => 'mail', 'tint' => 'bg-accent-600', 'title' => 'Email Us', 'main' => $c['email'], 'href' => 'mailto:' . $c['email'], 'sub' => 'We typically reply within 24 hours'],
    ['icon' => 'map-pin', 'tint' => 'bg-blue-600', 'title' => 'Visit Us', 'main' => $c['address'], 'href' => null, 'sub' => '', 'link' => ['Get Directions', $c['directions_url']]],
    ['icon' => 'graduation-cap', 'tint' => 'bg-emerald-600', 'title' => 'Admission Enquiry', 'main' => $c['admission_email'], 'href' => 'mailto:' . $c['admission_email'], 'sub' => 'For admission related queries'],
    ['icon' => 'messages-square', 'tint' => 'bg-violet-600', 'title' => 'Student Support', 'main' => $c['support_email'], 'href' => 'mailto:' . $c['support_email'], 'sub' => 'For academic and student support'],
];
$locations = [
    ['Main Campus', $c['address'], 'assets/images/site/campus-main.jpg', 'GIMT main campus building'],
    ['Academic Block', 'Knowledge Park, ' . $city, 'assets/images/site/campus-academic-block.jpg', 'GIMT academic block'],
    ['Library & Learning Center', 'Knowledge Park, ' . $city, 'assets/images/site/campus-library.jpg', 'Central library and learning center'],
    ['Hostel Facility', 'Knowledge Park, ' . $city, 'assets/images/site/campus-hostel.jpg', 'GIMT hostel building'],
];
$quick = [
    ['graduation-cap', 'tint-green', 'Admission Enquiry', 'Know about programs, eligibility and the admission process.', 'Enquire Now', '/admissions#enquiry'],
    ['book-open', 'tint-blue', 'Program Information', 'Get detailed information about our academic programs.', 'Enquire Now', '/contact?type=program#message'],
    ['award', 'tint-violet', 'Scholarships', 'Learn about available scholarships and financial aid options.', 'Enquire Now', '/contact?type=scholarship#message'],
    ['building-2', 'tint-amber', 'Campus Visit', 'Schedule a campus visit and experience GIMT first-hand.', 'Book a Visit', '/contact?type=campus_visit#message'],
];
$socialStyle = [
    'facebook' => ['Facebook', 'bg-[#1877F2]'], 'instagram' => ['Instagram', 'bg-gradient-to-br from-[#F58529] via-[#DD2A7B] to-[#8134AF]'],
    'linkedin' => ['LinkedIn', 'bg-[#0A66C2]'], 'youtube' => ['YouTube', 'bg-[#FF0000]'], 'x-twitter' => ['X (Twitter)', 'bg-slate-900'],
];
?>

<!-- Contact cards -->
<section class="relative z-10 -mt-10 sm:-mt-12" aria-label="Contact details">
  <div class="container-site">
    <ul class="grid overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-pop sm:grid-cols-2 lg:grid-cols-5 lg:divide-x lg:divide-slate-100" data-stagger="70">
      <?php foreach ($cards as $i => $card): ?>
        <li class="group flex gap-4 border-b border-slate-100 p-5 last:border-b-0 sm:[&:nth-last-child(-n+2)]:border-b-0 lg:flex-col lg:gap-3 lg:border-b-0 xl:p-6<?= $i === 4 ? ' sm:col-span-2 lg:col-span-1' : '' ?>">
          <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-white shadow-soft transition duration-300 group-hover:-translate-y-0.5 <?= $card['tint'] ?>"><?= icon($card['icon'], 'h-5 w-5') ?></span>
          <span class="min-w-0">
            <span class="block font-display text-base font-bold text-brand-900"><?= e($card['title']) ?></span>
            <?php if ($card['href']): ?>
              <a href="<?= e($card['href']) ?>" class="mt-1 block text-sm font-semibold text-slate-800 [overflow-wrap:anywhere] hover:text-accent-700"><?= e($card['main']) ?></a>
            <?php else: ?>
              <span class="mt-1 block text-[13px] leading-relaxed text-slate-600"><?= e($card['main']) ?></span>
            <?php endif; ?>
            <?php if ($card['sub'] !== ''): ?><span class="mt-0.5 block text-xs text-slate-500"><?= e($card['sub']) ?></span><?php endif; ?>
            <?php if (!empty($card['link'])): ?><a href="<?= e($card['link'][1]) ?>" target="_blank" rel="noopener" class="link-arrow mt-1.5 text-sm"><?= e($card['link'][0]) ?><?= icon('arrow-right', 'h-4 w-4') ?><span class="sr-only"> (opens Google Maps)</span></a><?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- Message form + map -->
<?= section_open(['id' => 'message', 'class' => 'scroll-mt-24']) ?>
  <div class="grid gap-6 lg:grid-cols-12">
    <div class="rounded-3xl bg-gradient-to-br from-brand-50/80 to-white p-1.5 ring-1 ring-brand-100/60 lg:col-span-7"<?= reveal_attr('fade-up') ?>>
      <?= sf_flash_notice('contact') ?>
      <?= enquiry_form([
          'endpoint' => base_url('api/public/contact'), 'theme' => 'light', 'title' => 'Send Us a Message', 'subtitle' => 'Fill out the form and our team will get back to you shortly.',
          'submitLabel' => 'Send Message', 'successTitle' => 'Message sent!', 'successMessage' => 'Thank you for reaching out. Our team will get back to you within 24 hours.',
          'consent' => 'I agree to the Terms & Conditions and Privacy Policy, and allow GIMT to contact me about my enquiry.',
          'hidden' => ['source' => 'contact-page'],
          'fields' => [
              ['name' => 'name', 'label' => 'Full Name', 'required' => true, 'col' => 'half', 'minLength' => 2, 'maxLength' => 150, 'autocomplete' => 'name', 'placeholder' => 'Enter your full name'],
              ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true, 'col' => 'half', 'maxLength' => 190, 'autocomplete' => 'email', 'placeholder' => 'Enter your email address'],
              ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'tel', 'required' => true, 'col' => 'half', 'autocomplete' => 'tel', 'placeholder' => 'Enter your phone number'],
              ['name' => 'enquiry_type', 'label' => 'Enquiry Type', 'type' => 'select', 'required' => true, 'col' => 'half', 'options' => pf_contact_type_options(), 'placeholder' => 'Select enquiry type', 'value' => $type],
              ['name' => 'message', 'label' => 'Your Message', 'type' => 'textarea', 'required' => true, 'rows' => 5, 'minLength' => 10, 'maxLength' => 2000, 'placeholder' => 'Write your message here...'],
          ],
      ]) ?>
      <p class="px-6 pb-4 text-xs text-slate-500 sm:px-7"><?= sf_page_exists('/terms-and-conditions') || sf_page_exists('/privacy-policy') ? 'Read our ' . sf_link('/terms-and-conditions', 'Terms & Conditions') . ' and ' . sf_link('/privacy-policy', 'Privacy Policy') . '. ' : '' ?>Your details are used only to respond to your enquiry and are never shared with third parties.</p>
    </div>
    <div class="flex flex-col overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-soft lg:col-span-5"<?= reveal_attr('fade-up', 120) ?>>
      <div class="flex items-start gap-4 p-5 sm:p-6">
        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white"><?= icon('map-pin', 'h-5 w-5') ?></span>
        <div class="min-w-0">
          <h2 class="font-display text-base font-bold text-brand-900"><?= e($name) ?> (<?= e((string) setting('institute_short_name', 'GIMT')) ?>)</h2>
          <p class="mt-0.5 text-sm text-slate-600"><?= e($c['address']) ?></p>
          <a href="<?= e($c['map_url']) ?>" target="_blank" rel="noopener" class="link-arrow mt-1.5 text-sm">View on Google Maps<?= icon('arrow-right', 'h-4 w-4') ?></a>
        </div>
      </div>
      <div class="relative min-h-[320px] flex-1 bg-slate-100">
        <?php if ($c['map_embed'] !== '' && preg_match('#^https://(www\.)?google\.[a-z.]+/maps#', $c['map_embed'])): ?>
          <iframe src="<?= e($c['map_embed']) ?>" title="Map showing the location of <?= e($name) ?>" class="absolute inset-0 h-full w-full border-0" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
        <?php else: ?>
          <img src="<?= e(asset('assets/images/site/campus-main.jpg')) ?>" alt="<?= e($name) ?> campus" loading="lazy" decoding="async" class="absolute inset-0 h-full w-full object-cover">
          <div class="absolute inset-x-4 bottom-4"><?= button_link('Open in Google Maps', $c['map_url'], 'white', ['icon' => 'external-link', 'target' => '_blank']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?= section_close() ?>

<!-- Campus locations -->
<?= section_open(['id' => 'locations', 'bg' => 'light', 'padding' => 'tight', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Our Campus Locations', 'Visit our campus to experience a world-class learning environment.', '', 'left', button_link('View Virtual Campus Tour', '/campus', 'outline', ['size' => 'sm', 'icon' => 'arrow-right'])) ?>
  <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4" data-stagger="80">
    <?php foreach ($locations as [$title, $addr, $img, $alt]): ?>
      <article class="card card-lift group flex flex-col overflow-hidden">
        <div class="img-zoom aspect-[16/10]"><img src="<?= e(asset($img)) ?>" alt="<?= e($alt) ?>" loading="lazy" decoding="async" width="640" height="400" class="h-full w-full object-cover"></div>
        <div class="flex flex-1 flex-col items-start p-5">
          <h3 class="font-display text-base font-bold text-brand-900"><?= e($title) ?></h3>
          <p class="mb-3 mt-1 flex-1 text-sm text-slate-600"><?= e($addr) ?></p>
          <a href="<?= e($c['directions_url']) ?>" target="_blank" rel="noopener" class="link-arrow text-sm">Get Directions<?= icon('arrow-right', 'h-4 w-4') ?><span class="sr-only"> to <?= e($title) ?> (opens Google Maps)</span></a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?= section_close() ?>

<!-- Quick enquiry + connect -->
<?= section_open(['id' => 'quick-enquiry', 'padding' => 'tight', 'class' => 'scroll-mt-24']) ?>
  <div class="grid gap-10 lg:grid-cols-12">
    <div class="lg:col-span-8">
      <h2 class="section-title !text-2xl sm:!text-3xl">Quick Enquiry Options</h2>
      <p class="mt-1 text-slate-600">Choose the option that best suits your requirement.</p>
      <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-stagger="70">
        <?php foreach ($quick as [$ic, $tint, $title, $text, $cta, $url]): ?>
          <article class="card card-lift group relative flex flex-col p-5 xl:p-4">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl transition duration-300 group-hover:-translate-y-0.5 <?= $tint ?>"><?= icon($ic, 'h-5 w-5') ?></span>
            <h3 class="mt-3 font-display text-[15px] font-bold leading-snug text-brand-900"><?= e($title) ?></h3>
            <p class="mt-1.5 flex-1 text-[13px] leading-relaxed text-slate-600"><?= e($text) ?></p>
            <a href="<?= e(cms_link($url)) ?>" class="link-arrow stretched-link mt-4 text-sm"><?= e($cta) ?><?= icon('arrow-right', 'h-4 w-4') ?><span class="sr-only"> — <?= e($title) ?></span></a>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="lg:col-span-4 lg:border-l lg:border-slate-100 lg:pl-10"<?= reveal_attr('slide-right') ?>>
      <h2 class="section-title !text-2xl sm:!text-3xl">Connect With Us</h2>
      <p class="mt-1 text-slate-600">Follow us on social media for the latest updates, events and news.</p>
      <?php if ($social): ?>
        <ul class="mt-6 flex flex-wrap gap-3">
          <?php foreach ($social as $ic => $url): $st = $socialStyle[$ic] ?? [ucfirst($ic), 'bg-brand-900']; ?>
            <li><a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex h-12 w-12 items-center justify-center rounded-full text-white shadow-soft transition duration-300 hover:-translate-y-1 hover:shadow-pop <?= $st[1] ?>" aria-label="<?= e($st[0]) ?> (opens in a new tab)" data-magnetic="0.3"><?= icon($ic, 'h-5 w-5') ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($c['whatsapp_url']): ?>
        <div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-3 rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-100">
          <span class="flex min-w-0 flex-1 items-center gap-3.5 lg:basis-full">
            <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-[#25D366] text-white"><?= icon('whatsapp-brand', 'h-6 w-6') ?></span>
            <span class="min-w-0">
              <span class="block font-display text-sm font-bold text-brand-900">Chat on WhatsApp</span>
              <span class="block text-xs leading-relaxed text-slate-600">Get instant answers to your questions.</span>
            </span>
          </span>
          <a href="<?= e($c['whatsapp_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm w-full border border-emerald-600/30 bg-white text-emerald-700 hover:bg-emerald-600 hover:text-white sm:w-auto lg:w-full">Chat Now<?= icon('arrow-right', 'h-3.5 w-3.5') ?><span class="sr-only"> (opens WhatsApp)</span></a>
        </div>
      <?php else: ?>
        <div class="mt-6 rounded-2xl bg-brand-50 p-4 text-sm text-brand-900 ring-1 ring-brand-100">Call us on <a class="font-semibold" href="<?= e(sf_tel($c['phone'])) ?>"><?= e($c['phone']) ?></a> — <?= e($c['hours']) ?>.</div>
      <?php endif; ?>
    </div>
  </div>
<?= section_close() ?>

<!-- FAQs -->
<?= section_open(['id' => 'faq', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
  <?php $faqUrl = sf_url_if('/faq'); ?>
  <?= section_heading('Frequently Asked Questions', $faqUrl ? 'Quick answers to common queries. For more details, visit our full FAQ section.' : 'Quick answers to common queries. More admission answers are on the Admissions page.', '', 'left',
      '<a href="' . e($faqUrl ?: site_url('admissions#faq')) . '" class="link-arrow">' . ($faqUrl ? 'View All FAQs' : 'Admission FAQs') . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <?= faq_accordion($faqs, ['columns' => 2, 'defaultOpen' => -1]) ?>
<?= section_close() ?>

<?php site_footer([
    'cta_title' => 'Still Have Questions?',
    'cta_text' => 'Our admission counsellors are here to help you make the right choice.',
    'cta_primary' => ['label' => 'Talk to Our Counsellor', 'url' => '/admissions#enquiry'],
    'cta_secondary' => ['label' => 'Call ' . $c['phone'], 'url' => sf_tel($c['phone']), 'icon' => 'phone'],
    'cta_image' => 'assets/images/site/placement-student.jpg',
]);
