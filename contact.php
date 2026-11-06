<?php
/**
 * Contact (/contact) — clean form page: compact header, "Send Us a Message" (EnquiryForm island → /api/public/contact)
 * beside the contact details (phone, emails, address, WhatsApp) and the map, FAQs. No CTA band or floating widgets
 * (site_form_page_footer()).
 * ?type=<admission|program|scholarship|campus_visit|placement|student_support|general> preselects the enquiry type.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_forms.php';

$c = sf_contact();
$types = pf_contact_types();
$type = (string) ($_GET['type'] ?? '');
$type = isset($types[$type]) && empty($types[$type]['hidden']) ? $type : '';
$faqs = sf_faqs(['general', 'admissions', 'campus'], sf_contact_faq_defaults(), 6);
$city = (string) setting('city', 'Noida');
$name = institute_name();

site_header([
    'title' => 'Contact Us — Admissions, Campus Visits & Support',
    'description' => 'Contact Global Institute of Management & Technology (GIMT), ' . $city . '. Call ' . $c['phone'] . ', email ' . $c['email'] . ', visit our Sector 18 campus or send us a message — we reply within 24 hours.',
    'active' => 'contact', 'seo' => ['route', '/contact'], 'announce' => false, 'loader' => false,
    'schema' => [
        sf_breadcrumb_schema(['Home' => '', 'Contact Us' => 'contact']),
        ['@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => 'Contact ' . $name, 'url' => absolute_url('contact'),
         'mainEntity' => ['@type' => 'CollegeOrUniversity', 'name' => $name, 'telephone' => $c['phone'], 'email' => $c['email'],
             'address' => ['@type' => 'PostalAddress', 'streetAddress' => $c['address'], 'addressLocality' => $city, 'addressRegion' => setting('state', 'Uttar Pradesh'), 'postalCode' => setting('pincode', '201301'), 'addressCountry' => 'IN'],
             'contactPoint' => [
                 ['@type' => 'ContactPoint', 'contactType' => 'admissions', 'telephone' => $c['phone'], 'email' => $c['admission_email'], 'areaServed' => 'IN', 'availableLanguage' => ['English', 'Hindi']],
                 ['@type' => 'ContactPoint', 'contactType' => 'customer support', 'email' => $c['support_email'], 'areaServed' => 'IN'],
             ]]],
        sf_faq_schema($faqs),
    ],
]);

site_form_page_hero('Contact Us', 'Admissions, programs, campus visits or student support — send us a message and our team replies within 24 hours.', ['Contact Us']);

// contact details: [icon, label, value, href, note]
$details = array_values(array_filter([
    ['phone', 'Call us', $c['phone'], sf_tel($c['phone']), $c['hours']],
    ['mail', 'Email', $c['email'], 'mailto:' . $c['email'], ''],
    $c['admission_email'] !== $c['email'] ? ['graduation-cap', 'Admissions', $c['admission_email'], 'mailto:' . $c['admission_email'], ''] : null,
    $c['support_email'] !== $c['email'] ? ['messages-square', 'Student support', $c['support_email'], 'mailto:' . $c['support_email'], ''] : null,
    ['map-pin', 'Visit us', $c['address'], null, ''],
]));
?>

<!-- Message form + contact details / map -->
<?= section_open(['id' => 'message', 'bg' => 'light', 'class' => 'scroll-mt-24 !pt-8 sm:!pt-10']) ?>
  <div class="grid gap-6 lg:grid-cols-12">
    <div class="lg:col-span-7">
      <?= enquiry_form([
          'endpoint' => base_url('api/public/contact'), 'theme' => 'light', 'title' => 'Send Us a Message', 'titleTag' => 'h2', 'subtitle' => 'Fill out the form and our team will get back to you shortly.',
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
      <p class="mt-3 px-1 text-xs text-slate-500"><?= sf_page_exists('/terms-and-conditions') || sf_page_exists('/privacy-policy') ? 'Read our ' . sf_link('/terms-and-conditions', 'Terms & Conditions') . ' and ' . sf_link('/privacy-policy', 'Privacy Policy') . '. ' : '' ?>Your details are used only to respond to your enquiry and are never shared with third parties.</p>
    </div>
    <div class="flex flex-col gap-6 lg:col-span-5">
      <div class="rounded-3xl border border-warm-2 bg-white p-5 sm:p-6">
        <h2 class="font-display text-lg font-extrabold text-brand-900"><?= e($name) ?></h2>
        <ul class="mt-4 divide-y divide-warm-2">
          <?php foreach ($details as [$ic, $label, $value, $href, $note]): ?>
            <li class="flex gap-3.5 py-3 first:pt-0 last:pb-0">
              <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl tint-navy"><?= icon($ic, 'h-4 w-4') ?></span>
              <span class="min-w-0">
                <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-500"><?= e($label) ?></span>
                <?php if ($href): ?>
                  <a href="<?= e($href) ?>" class="block text-sm font-semibold text-brand-900 [overflow-wrap:anywhere] hover:text-accent-700"><?= e($value) ?></a>
                <?php else: ?>
                  <span class="block text-sm leading-relaxed text-slate-700"><?= e($value) ?></span>
                  <a href="<?= e($c['directions_url']) ?>" target="_blank" rel="noopener" class="link-arrow mt-1 text-[13px]">Get directions<?= icon('arrow-right', 'h-3.5 w-3.5') ?><span class="sr-only"> (opens Google Maps)</span></a>
                <?php endif; ?>
                <?php if ($note !== ''): ?><span class="block text-xs text-slate-500"><?= e($note) ?></span><?php endif; ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($c['whatsapp_url']): ?>
          <a href="<?= e($c['whatsapp_url']) ?>" target="_blank" rel="noopener" class="mt-5 flex items-center gap-3 rounded-2xl bg-emerald-50 p-3.5 ring-1 ring-emerald-100 transition hover:ring-emerald-200">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#25D366] text-white"><?= icon('whatsapp-brand', 'h-5 w-5') ?></span>
            <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-brand-900">Chat on WhatsApp</span><span class="block text-xs text-slate-600">Quick answers to your questions</span></span>
            <?= icon('chevron-right', 'h-4 w-4 text-slate-400') ?><span class="sr-only">(opens WhatsApp)</span>
          </a>
        <?php endif; ?>
      </div>
      <div class="relative min-h-[260px] flex-1 overflow-hidden rounded-3xl border border-warm-2 bg-slate-100">
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

<!-- FAQs -->
<?php if ($faqs): ?>
<?= section_open(['id' => 'faq', 'class' => 'scroll-mt-24']) ?>
  <?php $faqUrl = sf_url_if('/faq'); ?>
  <?= section_heading('Frequently Asked Questions', 'Quick answers to common queries.', '', 'left',
      '<a href="' . e($faqUrl ?: site_url('admissions#faq')) . '" class="link-arrow">' . ($faqUrl ? 'View All FAQs' : 'Admission FAQs') . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <?= faq_accordion($faqs, ['columns' => 2, 'defaultOpen' => -1]) ?>
<?= section_close() ?>
<?php endif; ?>

<?php site_footer(site_form_page_footer());
