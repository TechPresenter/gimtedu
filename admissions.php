<?php
/**
 * Admissions (/admissions) — admissions-open banner with key dates, admission journey (ProgressSteps island),
 * programs & eligibility (Tabs island), fee overview + scholarships, documents checklist, important dates, FAQs
 * (Accordion island) and the counselling enquiry form (EnquiryForm island → /api/public/enquiry).
 * Anchors used by menus: #process #eligibility #fees #scholarships #documents #dates #faq #enquiry
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_forms.php';

$programs = pf_programs();
$groups = sf_program_groups($programs);
$session = sf_session_name();
$c = sf_contact();
$faqs = sf_faqs(['admissions', 'fees'], sf_admission_faq_defaults(), 8);
$keyDates = sf_key_dates();
$dates = sf_important_dates();
$feeCards = sf_fee_overview($programs);
$scholarships = sf_scholarships();
$docs = sf_documents();
$modes = sf_payment_modes();
$openText = (string) setting('admissions_open_text', 'Admissions Open for ' . $session);
$heroImage = 'assets/images/site/students-lawn.jpg';

site_header([
    'title' => 'Admissions ' . $session . ' — Eligibility, Fees & How to Apply',
    'description' => 'Admissions open for ' . $session . ' at Global Institute of Management & Technology (GIMT), Greater Noida. Check eligibility, fees, scholarships, documents required and important dates, then apply online or talk to a counsellor.',
    'active' => 'admissions', 'seo' => ['route', '/admissions'],
    'preload' => [asset($heroImage)],
    'schema' => [sf_breadcrumb_schema(['Home' => '', 'Admissions' => 'admissions']), sf_faq_schema($faqs)],
]);

page_hero(
    'Admissions ' . $session,
    'Your Journey to GIMT Starts Here',
    ['Admissions'],
    $heroImage,
    'Industry-focused UG, PG, diploma and certificate programs with merit scholarships, experienced faculty and dedicated placement support. Apply online in minutes.',
    [['icon' => 'award', 'label' => 'Scholarships up to 50%'], ['icon' => 'file-check', 'label' => 'Simple online application'], ['icon' => 'banknote', 'label' => 'Education loan assistance'], ['icon' => 'building-2', 'label' => 'Hostel & transport']],
    ['eyebrow' => 'Shaping Future Leaders', 'size' => 'lg', 'image_alt' => 'GIMT students on the campus lawn',
     'actions' => button_link('Apply Online', '/apply', 'accent', ['size' => 'lg', 'shine' => true, 'magnetic' => true]) . button_link('Talk to a Counsellor', '#enquiry', 'outline-light', ['size' => 'lg', 'icon' => '', 'icon_left' => 'phone'])]
);
?>

<!-- Admissions open banner -->
<section class="relative z-10 -mt-10 sm:-mt-14" aria-labelledby="admissions-open-title">
  <div class="container-site">
    <div class="relative isolate overflow-hidden rounded-3xl bg-brand-900 text-white shadow-pop"<?= reveal_attr('fade-up') ?>>
      <div class="bg-animated-gradient absolute inset-0 -z-20" aria-hidden="true"></div>
      <div class="pattern-dots-light pattern-fade animate-pattern-drift absolute inset-0 -z-10 opacity-60" aria-hidden="true"></div>
      <div class="blob -right-16 -top-20 -z-10 h-64 w-64 bg-accent-500/25" aria-hidden="true"></div>
      <div class="grid gap-8 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:p-10">
        <div class="lg:col-span-5">
          <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-widest text-accent-300 ring-1 ring-white/15">
            <span class="live-dot pulse-ring" aria-hidden="true"></span><?= sf_admissions_open() ? 'Admissions Open' : 'Admissions Closed' ?>
          </p>
          <h2 id="admissions-open-title" class="mt-4 font-display text-3xl font-extrabold leading-tight text-white sm:text-4xl"><?= e($openText) ?></h2>
          <p class="mt-3 max-w-md text-white/75">Applications are reviewed on a rolling basis. Secure your seat early — merit scholarships are awarded in order of application.</p>
          <div class="mt-6 flex flex-wrap gap-3">
            <?= button_link('Apply Now', '/apply', 'accent', ['shine' => true, 'magnetic' => true]) ?>
            <?= button_link('Check Eligibility', '#eligibility', 'outline-light', ['icon' => '']) ?>
          </div>
        </div>
        <ul class="grid gap-3 sm:grid-cols-2 lg:col-span-7" data-stagger="80">
          <?php foreach ($keyDates as $d): ?>
            <li class="glass-dark flex items-start gap-3.5 rounded-2xl p-4">
              <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/10 text-accent-300 ring-1 ring-white/15"><?= icon($d['icon'], 'h-5 w-5') ?></span>
              <span class="min-w-0">
                <span class="block text-xs font-semibold uppercase tracking-wider text-white/60"><?= e($d['label']) ?></span>
                <span class="mt-0.5 block font-display text-lg font-bold text-white"><?= e($d['value']) ?></span>
                <span class="block text-xs text-white/60"><?= e($d['note']) ?></span>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- Quick jump -->
<nav class="container-site mt-8" aria-label="On this page">
  <ul class="pill-row scrollbar-none justify-start lg:justify-center">
    <?php foreach (['process' => 'Admission Process', 'eligibility' => 'Programs & Eligibility', 'fees' => 'Fees', 'scholarships' => 'Scholarships', 'documents' => 'Documents', 'dates' => 'Important Dates', 'faq' => 'FAQs', 'enquiry' => 'Counselling'] as $id => $label): ?>
      <li class="shrink-0"><a href="#<?= $id ?>" class="chip"><?= e($label) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>

<!-- Admission journey -->
<?= section_open(['id' => 'process', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('The Admission Journey', 'A simple, transparent five-step process — most applicants complete it within two weeks.', 'How to Apply', 'left', button_link('Start Your Application', '/apply', 'accent', ['shine' => true])) ?>
  <?= progress_steps([
      ['title' => 'Explore Programs', 'text' => 'Compare programs, eligibility and fees, or talk to a counsellor.', 'icon' => 'search'],
      ['title' => 'Submit Application', 'text' => 'Fill the online form in about 10 minutes — no application fee.', 'icon' => 'file-text'],
      ['title' => 'Upload Documents', 'text' => 'Photo, marksheets and ID proof as PDF or image files.', 'icon' => 'upload'],
      ['title' => 'Verification & Approval', 'text' => 'Documents verified in 3 working days, then test / interview.', 'icon' => 'clipboard-check'],
      ['title' => 'Enrollment & Fee Payment', 'text' => 'Accept your offer, pay the admission fee and get your student ID.', 'icon' => 'wallet'],
  ]) ?>
<?= section_close() ?>

<!-- Programs & eligibility -->
<?= section_open(['id' => 'eligibility', 'bg' => 'light', 'pattern' => 'dots', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Programs & Eligibility', 'Duration, eligibility, intake and annual tuition fee for every program offered in ' . $session . '.', 'Programs', 'left', '<a href="' . e(site_url('programs')) . '" class="link-arrow">Explore all programs' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <?php
  $tabMeta = ['ug' => ['Undergraduate', 'graduation-cap'], 'pg' => ['Postgraduate', 'school'], 'other' => ['Diploma & Certificate', 'award']];
  $tabs = [];
  $panels = [];
  foreach ($groups as $key => $list) {
      if ($list) {
          $tabs[] = ['key' => $key, 'label' => $tabMeta[$key][0] . ' (' . count($list) . ')', 'icon' => $tabMeta[$key][1]];
          $panels[] = sf_eligibility_table($list);
      }
  }
  ?>
  <?php if ($tabs): ?>
    <?= content_tabs($tabs, $panels, ['variant' => 'pills']) ?>
    <p class="mt-5 flex items-start gap-2 text-sm text-slate-500"><?= icon('info', 'mt-0.5 h-4 w-4 shrink-0 text-brand-500') ?>Relaxation of 5% in minimum marks for SC / ST / OBC (non-creamy layer) candidates as per government norms. Fees shown are tuition fees; hostel and transport are optional.</p>
  <?php else: ?>
    <div class="empty-state rounded-3xl border border-dashed border-slate-200 bg-white p-10 text-center">
      <p class="font-display text-lg font-bold text-brand-900">Programs for <?= e($session) ?> will be announced soon</p>
      <p class="mt-1 text-sm text-slate-500">Register your interest below and our counsellors will share details as soon as they are published.</p>
      <div class="mt-5"><?= button_link('Register Interest', '#enquiry', 'accent') ?></div>
    </div>
  <?php endif; ?>
<?= section_close() ?>

<!-- Fees & scholarships -->
<?= section_open(['id' => 'fees', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Fee Overview', 'Transparent, all-inclusive tuition with flexible payment options. No fee is charged to apply online.', 'Fees & Financial Aid') ?>
  <?php if ($feeCards): ?>
    <div class="grid gap-5 md:grid-cols-3" data-stagger="90">
      <?php foreach ($feeCards as $f): ?>
        <article class="card card-lift relative flex flex-col overflow-hidden p-6">
          <span class="feature-tile-icon tint-<?= e($f['tint']) ?>"><?= icon($f['icon'], 'h-7 w-7') ?></span>
          <h3 class="mt-4 font-display text-lg font-bold text-brand-900"><?= e($f['title']) ?></h3>
          <p class="mt-0.5 flex-1 text-xs text-slate-500"><?= e($f['count']) ?> program<?= $f['count'] === 1 ? '' : 's' ?> · <?= e($f['programs']) ?></p>
          <p class="mt-4 font-display text-2xl font-extrabold text-brand-900"><?= e($f['range']) ?></p>
          <p class="text-xs font-medium text-slate-500">Tuition fee <?= e($f['unit']) ?></p>
          <div class="mt-4 rounded-xl bg-slate-50 px-3.5 py-2.5 text-[13px] text-slate-600 ring-1 ring-slate-100">Admission fee <strong class="text-brand-900"><?= e($f['admission_fee']) ?></strong> — payable only after approval</div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="mt-6 grid gap-5 lg:grid-cols-3"<?= reveal_attr('fade-up') ?>>
    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm lg:col-span-2">
      <h3 class="flex items-center gap-2 font-display text-base font-bold text-brand-900"><?= icon('credit-card', 'h-5 w-5 text-accent-600') ?>Flexible payment options</h3>
      <p class="mt-1 text-sm text-slate-600">Pay tuition semester-wise or in instalments. Education loans are available through partner banks with minimal paperwork.</p>
      <?php if ($modes): ?>
        <ul class="mt-4 flex flex-wrap gap-2">
          <?php foreach ($modes as $m): ?><li class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-800"><?= icon('check', 'h-3.5 w-3.5 text-accent-600') ?><?= e($m) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div class="flex flex-col justify-between gap-4 rounded-2xl bg-accent-50 p-5 ring-1 ring-accent-100">
      <p class="text-sm text-accent-900"><strong class="font-semibold">Need the detailed fee structure?</strong> Our counsellors will share the program-wise break-up including hostel and transport.</p>
      <?= button_link('Request Fee Details', '/contact?type=scholarship#message', 'accent', ['size' => 'sm']) ?>
    </div>
  </div>

  <div id="scholarships" class="mt-16 scroll-mt-28">
    <?= section_heading('Scholarships', 'Rewarding merit, talent and need — scholarships are adjusted directly against your tuition fee.', 'Financial Aid', 'left', '<a href="' . e(site_url('contact?type=scholarship#message')) . '" class="link-arrow">Ask about eligibility' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3" data-stagger="70">
      <?php foreach ($scholarships as $i => $s): ?>
        <article class="card card-lift group flex gap-4 p-5">
          <span class="feature-tile-icon <?= ['tint-green', 'tint-blue', 'tint-rose', 'tint-amber', 'tint-violet', 'tint-cyan'][$i % 6] ?> !h-12 !w-12 shrink-0"><?= icon($s['icon'], 'h-6 w-6') ?></span>
          <div class="min-w-0">
            <p class="font-display text-xl font-extrabold text-accent-700"><?= e($s['value']) ?></p>
            <h3 class="font-display text-base font-bold text-brand-900"><?= e($s['title']) ?></h3>
            <p class="mt-1 text-sm leading-relaxed text-slate-600"><?= e($s['text']) ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
<?= section_close() ?>

<!-- Documents -->
<?= section_open(['id' => 'documents', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
  <div class="grid gap-10 lg:grid-cols-12">
    <div class="lg:col-span-7">
      <?= section_heading('Documents Required', 'Upload these with your online application — clear scans or phone photos are fine.', 'Checklist') ?>
      <ul class="grid gap-3 sm:grid-cols-2" data-stagger="60">
        <?php foreach ($docs['online'] as $d): ?>
          <li class="flex items-start gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-800"><?= icon($d['icon'], 'h-5 w-5') ?></span>
            <span class="min-w-0">
              <span class="block text-sm font-semibold text-brand-900"><?= e($d['label']) ?></span>
              <span class="mt-0.5 inline-flex items-center gap-1 text-xs <?= $d['required'] ? 'text-accent-700' : 'text-slate-500' ?>"><?= icon($d['required'] ? 'circle-check' : 'circle-dot', 'h-3.5 w-3.5') ?><?= e($d['note']) ?></span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="mt-4 flex items-center gap-2 text-sm text-slate-500"><?= icon('paperclip', 'h-4 w-4 text-brand-500') ?>Accepted formats: <?= e($docs['formats']) ?>.</p>
    </div>
    <div class="lg:col-span-5"<?= reveal_attr('slide-right') ?>>
      <div class="h-full rounded-3xl bg-white p-6 shadow-soft ring-1 ring-slate-100 sm:p-7">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-accent-50 text-accent-700"><?= icon('folder-open', 'h-6 w-6') ?></span>
        <h3 class="mt-4 font-display text-xl font-bold text-brand-900">Bring these at enrollment</h3>
        <p class="mt-1 text-sm text-slate-600">Originals are verified and returned on the same day. Keep one self-attested photocopy of each.</p>
        <ul class="check-list mt-5">
          <?php foreach ($docs['originals'] as $o): ?>
            <li><?= icon('circle-check', '') ?><span><span class="font-semibold text-slate-800"><?= e($o['label']) ?></span><span class="block text-xs text-slate-500"><?= e($o['note']) ?></span></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
<?= section_close() ?>

<!-- Important dates -->
<?= section_open(['id' => 'dates', 'class' => 'scroll-mt-24']) ?>
  <div class="grid gap-10 lg:grid-cols-12 lg:items-start">
    <div class="lg:col-span-5"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Admission Calendar</p>
      <h2 class="section-title">Important Dates</h2>
      <p class="section-lead">Key milestones of the <?= e($session) ?> admission cycle. Dates are confirmed to every applicant by email and SMS.</p>
      <div class="relative mt-8 overflow-hidden rounded-3xl shadow-soft">
        <div class="img-zoom aspect-[4/3]"><img src="<?= e(asset('assets/images/site/students-atrium.jpg')) ?>" alt="Students in the GIMT atrium" loading="lazy" decoding="async" class="h-full w-full object-cover" width="800" height="600"></div>
        <div class="absolute inset-x-4 bottom-4"><?= glass_chip('map-pin', 'Campus visits ' . $c['hours']) ?></div>
      </div>
    </div>
    <ol class="relative space-y-4 lg:col-span-7" data-stagger="80">
      <?php foreach ($dates as $i => $d):
          $state = $d['state'];
          $dot = $state === 'done' ? 'bg-accent-600 text-white' : ($state === 'current' ? 'bg-brand-900 text-white pulse-ring' : 'bg-white text-brand-700 ring-2 ring-brand-200');
          ?>
        <li class="relative flex gap-4">
          <?php if ($i < count($dates) - 1): ?><span class="absolute left-[22px] top-12 h-[calc(100%-1rem)] w-0.5 bg-slate-200" aria-hidden="true"></span><?php endif; ?>
          <span class="relative z-[1] inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full <?= $dot ?>"><?= icon($state === 'done' ? 'check' : $d['icon'], 'h-5 w-5') ?></span>
          <div class="min-w-0 flex-1 rounded-2xl border <?= $state === 'current' ? 'border-brand-200 bg-brand-50/60' : 'border-slate-100 bg-white' ?> p-4 shadow-sm">
            <p class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-wider <?= $state === 'done' ? 'text-slate-400' : 'text-accent-700' ?>">
              <?= e($d['when']) ?>
              <?php if ($state === 'current'): ?><span class="badge badge-blue normal-case tracking-normal">Ongoing</span><?php elseif ($state === 'done'): ?><span class="badge badge-slate normal-case tracking-normal">Completed</span><?php endif; ?>
            </p>
            <h3 class="mt-1 font-display text-base font-bold text-brand-900"><?= !empty($d['url']) ? '<a class="hover:text-brand-600" href="' . e(cms_link($d['url'])) . '">' . e($d['title']) . '</a>' : e($d['title']) ?></h3>
            <?php if ($d['text'] !== ''): ?><p class="mt-1 text-sm text-slate-600"><?= e($d['text']) ?></p><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
<?= section_close() ?>

<!-- FAQs -->
<?= section_open(['id' => 'faq', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Frequently Asked Questions', 'Everything you need to know about applying to GIMT.', 'FAQs', 'left', ($faqUrl = sf_url_if('/faq')) ? '<a href="' . e($faqUrl) . '" class="link-arrow">View all FAQs' . icon('arrow-right', 'h-4 w-4') . '</a>' : '<a href="' . e(site_url('contact?type=admission#message')) . '" class="link-arrow">Ask a question' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <?= faq_accordion($faqs, ['columns' => 2, 'search' => true, 'searchPlaceholder' => 'Search admission questions…']) ?>
<?= section_close() ?>

<!-- Counselling enquiry -->
<?= section_open(['id' => 'enquiry', 'bg' => 'gradient', 'pattern' => 'grid', 'class' => 'scroll-mt-24']) ?>
  <div class="blob -left-24 bottom-0 -z-[1] h-80 w-80 bg-accent-500/20" aria-hidden="true"></div>
  <div class="grid items-center gap-10 lg:grid-cols-12">
    <div class="lg:col-span-5"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Free Counselling</p>
      <h2 class="section-title">Talk to an Admission Counsellor</h2>
      <p class="section-lead">Not sure which program is right for you? Share your details and a counsellor will call you within 24 hours to guide you on eligibility, fees and scholarships.</p>
      <ul class="mt-7 space-y-3">
        <li><a href="<?= e(sf_tel($c['phone'])) ?>" class="group flex items-center gap-3 text-white/90 hover:text-white"><span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-white/10 text-accent-300 ring-1 ring-white/15 transition group-hover:bg-white/15"><?= icon('phone', 'h-5 w-5') ?></span><span><span class="block text-xs text-white/60">Admission helpline</span><span class="font-semibold"><?= e($c['phone']) ?></span></span></a></li>
        <li><a href="mailto:<?= e($c['admission_email']) ?>" class="group flex items-center gap-3 text-white/90 hover:text-white"><span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-white/10 text-accent-300 ring-1 ring-white/15 transition group-hover:bg-white/15"><?= icon('mail', 'h-5 w-5') ?></span><span><span class="block text-xs text-white/60">Email</span><span class="font-semibold"><?= e($c['admission_email']) ?></span></span></a></li>
        <?php if ($c['whatsapp_url']): ?>
          <li><a href="<?= e($c['whatsapp_url']) ?>" target="_blank" rel="noopener" class="group flex items-center gap-3 text-white/90 hover:text-white"><span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-white/10 text-[#25D366] ring-1 ring-white/15 transition group-hover:bg-white/15"><?= icon('whatsapp-brand', 'h-5 w-5') ?></span><span><span class="block text-xs text-white/60">WhatsApp</span><span class="font-semibold">Chat with a counsellor</span></span></a></li>
        <?php endif; ?>
        <li class="flex items-center gap-3 text-white/90"><span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-white/10 text-accent-300 ring-1 ring-white/15"><?= icon('clock', 'h-5 w-5') ?></span><span><span class="block text-xs text-white/60">Office hours</span><span class="font-semibold"><?= e($c['hours']) ?></span></span></li>
      </ul>
    </div>
    <div class="text-slate-700 lg:col-span-7 [&_.enquiry-title]:!text-brand-900">
      <?= sf_flash_notice('enquiry') ?>
      <?= enquiry_form([
          'endpoint' => base_url('api/public/enquiry'), 'theme' => 'light', 'icon' => 'graduation-cap',
          'title' => 'Request a Call Back', 'subtitle' => 'Free, no-obligation guidance from our admission team.', 'submitLabel' => 'Get Free Counselling',
          'successTitle' => 'Request received!', 'successMessage' => 'Thank you! Our admission counsellor will call you within 24 hours.',
          'hidden' => ['source' => 'admissions-page', 'form' => 'counselling'],
          'fields' => [
              ['name' => 'name', 'label' => 'Full Name', 'required' => true, 'col' => 'half', 'maxLength' => 150, 'minLength' => 2, 'autocomplete' => 'name'],
              ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'tel', 'required' => true, 'col' => 'half', 'autocomplete' => 'tel', 'placeholder' => '+91 98765 43210'],
              ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'col' => 'half', 'autocomplete' => 'email', 'maxLength' => 190],
              ['name' => 'city', 'label' => 'City', 'col' => 'half', 'maxLength' => 80, 'autocomplete' => 'address-level2'],
              ['name' => 'program_id', 'label' => 'Program of Interest', 'type' => 'select', 'options' => sf_program_select_options($programs), 'placeholder' => 'Select a program (optional)'],
              ['name' => 'message', 'label' => 'Your Question', 'type' => 'textarea', 'rows' => 3, 'maxLength' => 2000, 'placeholder' => 'e.g. I scored 82% in Class 12 — am I eligible for a scholarship?'],
          ],
      ]) ?>
    </div>
  </div>
<?= section_close() ?>

<?php site_footer([
    'cta_title' => 'Ready to Apply for ' . $session . '?',
    'cta_text' => 'Join GIMT and take the next step towards your academic and professional success.',
    'cta_primary' => ['label' => 'Apply Online', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Programs', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
