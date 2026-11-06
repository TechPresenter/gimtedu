<?php
/**
 * Online application (/apply) — one clean single-page form (sf_apply_classic_form(), multipart POST here, server-side
 * validation with inline errors). The multi-step React island ApplyWizard (frontend/site/islands/ApplyWizard.tsx →
 * /api/public/apply) is still available at /apply?wizard=1.
 *
 *   /apply?program=<slug|id>         preselect a program
 *   /apply?wizard=1                  step-by-step wizard instead of the single page
 *   /apply?ack=<application_no>&t=…  printable acknowledgement (token = HMAC, see pf_ack_token())
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_forms.php';

$programs = pf_programs();

/** Add the one-time form token (timing check + duplicate protection, see pf_form_token()) to the server-rendered form. */
function apply_with_token(string $html): string
{
    return (string) preg_replace('/(<input type="hidden" name="_csrf"[^>]*>)/', '$1<input type="hidden" name="_ft" value="' . e(pf_form_token()) . '">', $html, 1);
}
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
    site_footer(site_form_page_footer());
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
            // E.164 phones (+ "<field>_country"), one-time form token (a resubmitted form goes to the first acknowledgement)
            $in = pf_prepare_input($_POST);
            if (($replay = pf_token_replay($in)) && !empty($replay['data']['ack_url'])) {
                redirect($replay['data']['ack_url'] . '&new=1', 303);
            }
            pf_guard('apply', $in, 20, 3600);
            $res = pf_submit_application($in, $_FILES);
            pf_token_consume($in, $res);
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
    $initial = $initial ?? pf_find_program(is_string($_POST['program_id'] ?? null) ? $_POST['program_id'] : '');
}
// one single-page form by default; the step-by-step ApplyWizard stays available at /apply?wizard=1
$classic = empty($_GET['wizard']) || is_post();
$open = sf_admissions_open();

$title = $initial ? 'Apply for ' . $initial['short_name'] . ' — Admissions ' . $session : 'Apply Online — Admissions ' . $session;
site_header([
    'title' => $title,
    'description' => 'Apply online to Global Institute of Management & Technology (GIMT), Noida for the ' . $session . ' session. Simple 7-step application, secure document upload, instant application number and no application fee.',
    'active' => 'admissions', 'seo' => ['route', '/apply'], 'canonical' => absolute_url('apply'),
    'announce' => false, 'loader' => false,
    'schema' => [sf_breadcrumb_schema(['Home' => '', 'Admissions' => 'admissions', 'Apply Online' => 'apply'])],
]);

// form page: quiet header, the application is the only focus
site_form_page_hero(
    ($initial ? 'Apply for ' . $initial['short_name'] : 'Apply Online') . ' — Admissions ' . $session,
    'One simple form — fill in your details, attach your documents and submit. It takes about 10 minutes.',
    ['Admissions' => '/admissions', 'Apply Online'],
    [['clock', 'About 10 minutes'], ['shield-check', 'Secure document upload'], ['badge-check', 'Instant application number'], ['wallet', 'No application fee']]
);
?>

<?= section_open(['id' => 'application', 'bg' => 'light', 'label' => 'Online application form', 'class' => 'scroll-mt-24 !pt-8 sm:!pt-10']) ?>
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
      <div class="mb-5 flex flex-col gap-2 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between">
        <p>Fields marked <span class="form-required">*</span> are required.</p>
        <p>Need help? Call <a href="<?= e(sf_tel($c['phone'])) ?>" class="font-semibold text-brand-900 hover:text-accent-700"><?= e($c['phone']) ?></a><?= $c['whatsapp_url'] ? ' or <a href="' . e($c['whatsapp_url']) . '" target="_blank" rel="noopener" class="font-semibold text-brand-900 hover:text-accent-700">chat on WhatsApp<span class="sr-only"> (opens WhatsApp)</span></a>' : '' ?></p>
      </div>
      <?= apply_with_token(sf_apply_classic_form($programs, $initial, $old, $classicErrors, $classicMessage, true)) ?>
    </div>
  <?php else: ?>
    <?= island('ApplyWizard', sf_apply_props($programs, $initial) + ['security' => ['token' => pf_form_token(), 'captcha' => pf_captcha_public()]], sf_apply_skeleton(count($programs)) . apply_with_token(sf_apply_classic_form($programs, $initial)), ['eager' => true, 'class' => 'block']) ?>
  <?php endif; ?>
<?= section_close() ?>

<?php $applyFaqs = sf_faqs(['admissions', 'fees'], sf_admission_faq_defaults(), 8); ?>
<?= section_open(['id' => 'faq', 'bg' => 'white']) ?>
  <?= section_heading('Application FAQs', 'Answers to the questions applicants ask most often.', '', 'left', '<a href="' . e(site_url('admissions#faq')) . '" class="link-arrow">Admissions FAQs' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <?= faq_accordion(array_slice($applyFaqs, 0, 6), ['columns' => 2, 'defaultOpen' => -1]) ?>
<?= section_close() ?>

<?php site_footer(site_form_page_footer());
