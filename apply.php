<?php
/**
 * Online application (/apply) — multi-step React island ApplyWizard (frontend/site/islands/ApplyWizard.tsx) that posts
 * to /api/public/apply, with a complete server-rendered form as the no-JS / fallback path (POSTs here).
 *
 *   /apply?program=<slug|id>         preselect a program
 *   /apply?classic=1                 single-page form without the wizard
 *   /apply?ack=<application_no>&t=…  printable acknowledgement (token = HMAC, see pf_ack_token())
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_forms.php';

$programs = pf_programs();
$initial = pf_find_program((string) ($_GET['program'] ?? ''));
$c = sf_contact();
$session = sf_session_name();

/* ---------------------------------------------------------------- acknowledgement view */
if (isset($_GET['ack'])) {
    $ack = pf_find_ack((string) $_GET['ack'], (string) ($_GET['t'] ?? ''));
    if (!$ack) {
        http_response_code(404);
    }
    site_header([
        'title' => $ack ? 'Application Acknowledgement ' . $ack['application_no'] : 'Acknowledgement Not Found',
        'description' => 'Acknowledgement of your online application to Global Institute of Management & Technology.',
        'robots' => 'noindex,nofollow', 'active' => 'admissions', 'loader' => false, 'cursor' => false, 'canonical' => absolute_url('apply'),
    ]);
    ?>
    <style>@media print { footer, .cta-band, .whatsapp-fab { display: none !important; } body { background: #fff !important; } main section { padding: 0 !important; } }</style>
    <?= section_open(['bg' => 'light', 'class' => 'print:bg-white']) ?>
      <?php if ($ack): ?>
        <?= sf_ack_view($ack, !empty($_GET['new'])) ?>
      <?php else: ?>
        <div class="mx-auto max-w-xl rounded-3xl border border-slate-100 bg-white p-8 text-center shadow-soft">
          <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-600"><?= icon('file-clock', 'h-7 w-7') ?></span>
          <h1 class="mt-4 font-display text-2xl font-extrabold text-brand-900">We couldn't find that acknowledgement</h1>
          <p class="mt-2 text-sm text-slate-600">The link may be incomplete. Use the "Download Acknowledgement" link shown after submitting, or contact the admission office with your application number.</p>
          <div class="mt-6 flex flex-wrap justify-center gap-3">
            <?= button_link('Contact Admissions', '/contact?type=admission#message', 'accent') ?>
            <?= button_link('Back to Admissions', '/admissions', 'outline', ['icon' => '']) ?>
          </div>
        </div>
      <?php endif; ?>
    <?= section_close() ?>
    <?php
    site_footer(['cta' => false]);
    return;
}

/* ---------------------------------------------------------------- no-JS form post */
$classicErrors = [];
$classicMessage = '';
$old = [];
if (is_post()) {
    $old = array_map(fn ($v) => is_string($v) ? $v : '', $_POST);
    if (pf_post_too_large()) {
        $classicMessage = 'Your documents are too large to upload together (maximum ' . human_filesize(pf_upload_limits()['total']) . ' in total). Please choose smaller files and submit again.';
    } elseif (!csrf_valid()) {
        $classicMessage = 'Your session expired before the form was submitted. Please attach your documents and submit again.';
    } else {
        try {
            pf_guard('apply', $_POST, 20, 3600);
            $res = pf_submit_application($_POST, $_FILES);
            redirect($res['data']['ack_url'] . '&new=1', 303);
        } catch (PublicFormException $e) {
            $classicMessage = $e->getMessage();
            $classicErrors = $e->errors;
            if ($classicErrors && array_filter(array_keys($classicErrors), fn ($k) => str_starts_with($k, 'doc_')) === array_keys($classicErrors)) {
                $classicMessage .= ' Please attach your documents again.';
            } elseif ($classicErrors) {
                $classicMessage .= ' For security, documents must be attached again.';
            }
        }
    }
    $initial = $initial ?? pf_find_program((string) ($_POST['program_id'] ?? ''));
}
$classic = !empty($_GET['classic']) || is_post();
$open = sf_admissions_open();

$title = $initial ? 'Apply for ' . $initial['short_name'] . ' — Admissions ' . $session : 'Apply Online — Admissions ' . $session;
$heroImage = 'assets/images/site/students-laptop.jpg';
site_header([
    'title' => $title,
    'description' => 'Apply online to Global Institute of Management & Technology (GIMT), Greater Noida for the ' . $session . ' session. Simple 7-step application, secure document upload, instant application number and no application fee.',
    'active' => 'admissions', 'seo' => ['route', '/apply'], 'canonical' => absolute_url('apply'),
    'preload' => [asset($heroImage)],
    'schema' => [sf_breadcrumb_schema(['Home' => '', 'Admissions' => 'admissions', 'Apply Online' => 'apply'])],
]);

page_hero(
    $initial ? 'Apply for ' . $initial['short_name'] : 'Apply Online',
    'Admissions ' . $session,
    ['Admissions' => '/admissions', 'Apply Online'],
    $heroImage,
    'Complete your application in about 10 minutes. Save your progress as you go, upload documents securely and get your application number instantly.',
    [['icon' => 'clock', 'label' => 'About 10 minutes'], ['icon' => 'shield-check', 'label' => 'Secure document upload'], ['icon' => 'badge-check', 'label' => 'Instant application number'], ['icon' => 'wallet', 'label' => 'No application fee']],
    ['eyebrow' => (string) setting('admissions_open_text', 'Admissions Open for ' . $session), 'image_alt' => 'Students working together on a laptop', 'script' => 'Learn Grow Innovate Succeed']
);
?>

<?= section_open(['id' => 'application', 'bg' => 'brand-soft', 'label' => 'Online application form', 'class' => 'scroll-mt-24']) ?>
  <?php if (!$programs): ?>
    <div class="mx-auto max-w-xl rounded-3xl border border-slate-100 bg-white p-8 text-center shadow-soft">
      <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-800"><?= icon('graduation-cap', 'h-7 w-7') ?></span>
      <h2 class="mt-4 font-display text-2xl font-extrabold text-brand-900">Programs will be announced soon</h2>
      <p class="mt-2 text-sm text-slate-600">Online applications open as soon as programs for the <?= e($session) ?> session are published. Leave your details and a counsellor will call you.</p>
      <div class="mt-6"><?= button_link('Request a Call Back', '/admissions#enquiry', 'accent') ?></div>
    </div>
  <?php elseif (!$open): ?>
    <div class="mx-auto max-w-2xl rounded-3xl border border-slate-100 bg-white p-8 text-center shadow-soft">
      <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-600"><?= icon('calendar-clock', 'h-7 w-7') ?></span>
      <h2 class="mt-4 font-display text-2xl font-extrabold text-brand-900">Online applications are currently closed</h2>
      <p class="mt-2 text-sm text-slate-600">Register your interest and our admission team will inform you as soon as applications open for the next intake.</p>
      <div class="mt-6 flex flex-wrap justify-center gap-3"><?= button_link('Register Your Interest', '/admissions#enquiry', 'accent') ?><?= button_link('Call ' . $c['phone'], sf_tel($c['phone']), 'outline', ['icon' => '', 'icon_left' => 'phone']) ?></div>
    </div>
  <?php elseif ($classic): ?>
    <div class="mx-auto max-w-4xl">
      <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p class="eyebrow mb-1">Single-page form</p>
          <h2 class="font-display text-2xl font-extrabold text-brand-900">Application for Admission <?= e($session) ?></h2>
        </div>
        <a href="<?= e(site_url('apply' . ($initial ? '?program=' . rawurlencode($initial['slug']) : ''))) ?>" class="link-arrow">Use the step-by-step form<?= icon('arrow-right', 'h-4 w-4') ?></a>
      </div>
      <?= sf_apply_classic_form($programs, $initial, $old, $classicErrors, $classicMessage, true) ?>
    </div>
  <?php else: ?>
    <?= island('ApplyWizard', sf_apply_props($programs, $initial), sf_apply_skeleton(count($programs)) . sf_apply_classic_form($programs, $initial), ['eager' => true, 'class' => 'block']) ?>
  <?php endif; ?>
<?= section_close() ?>

<?= section_open(['bg' => 'white', 'padding' => 'tight', 'label' => 'Why apply online']) ?>
  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-stagger="70">
    <?= feature_tile('shield-check', 'Secure & Private', 'Documents are stored privately and used only for admission verification.', ['variant' => 'compact', 'tint' => 'green']) ?>
    <?= feature_tile('save', 'Save & Continue', 'Your progress is saved on this device — finish whenever you are ready.', ['variant' => 'compact', 'tint' => 'blue']) ?>
    <?= feature_tile('mail-check', 'Instant Acknowledgement', 'Get your application number on screen and by email immediately.', ['variant' => 'compact', 'tint' => 'violet']) ?>
    <?= feature_tile('messages-square', 'Counsellor Support', 'Call ' . $c['phone'] . ' — ' . $c['hours'] . '.', ['variant' => 'compact', 'tint' => 'amber']) ?>
  </div>
<?= section_close() ?>

<?php $applyFaqs = sf_faqs(['admissions', 'fees'], sf_admission_faq_defaults(), 8); ?>
<?= section_open(['id' => 'faq', 'bg' => 'light']) ?>
  <?= section_heading('Application FAQs', 'Answers to the questions applicants ask most often.', 'Need help?', 'left', '<a href="' . e(site_url('admissions#faq')) . '" class="link-arrow">Admissions FAQs' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <?= faq_accordion(array_slice($applyFaqs, 0, 6), ['columns' => 2, 'defaultOpen' => -1]) ?>
<?= section_close() ?>

<?php site_footer([
    'cta_title' => 'Questions About Your Application?',
    'cta_text' => 'Our admission counsellors are here to help — ' . $c['hours'] . '.',
    'cta_primary' => ['label' => 'Talk to a Counsellor', 'url' => '/admissions#enquiry'],
    'cta_secondary' => ['label' => 'Call ' . $c['phone'], 'url' => sf_tel($c['phone']), 'icon' => 'phone'],
    'cta_image' => 'assets/images/site/students-atrium.jpg',
]);
