<?php
/**
 * Public certificate verification.
 *   /verify-certificate                       search form
 *   /verify-certificate/{certificate_number}  result (pretty URL routed by .htaccess / tools/router.php -> ?no=)
 *   /verify-certificate?no=…                  form submit -> redirected to the pretty URL
 *   ?h=…                                      QR signature (first 12 hex of the HMAC) — checked when present
 * Shows only safe details (holder name, type, program, dates, status, security code). Rate limited per IP and logged in
 * certificate_verifications. JSON variant: GET /api/certificates/verify/{number}.
 */
require __DIR__ . '/includes/config.php';
require_once APP_ROOT . '/app/services/certificates.php';

$raw = trim((string) ($_GET['no'] ?? ''));
$sig = isset($_GET['h']) ? substr(preg_replace('/[^a-f0-9]/i', '', (string) $_GET['h']), 0, 12) : null;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Form submissions (?no=) -> shareable pretty URL
if ($raw !== '' && preg_match('#/verify-certificate(\.php)?/?$#', $path)) {
    $clean = cert_normalise_no($raw);
    if ($clean !== '' && preg_match('/^[A-Z0-9\-]+$/', $clean)) {
        header('Location: ' . base_url('verify-certificate/' . rawurlencode($clean)), true, 302);
        exit;
    }
}

$result = $raw !== '' ? cert_verify($raw, $sig !== null && $sig !== '' ? 'qr' : 'web', $sig) : null;
$state = $result['state'] ?? null;
$cert = $result['certificate'] ?? null;
$number = $result['number'] ?? '';
if ($state === 'rate_limited') {
    http_response_code(429);
}
$institute = institute_name();
$email = setting('exam_email', setting('email', 'info@gimt.ac.in'));
$phone = setting('phone', '+91 9955446477');

site_header([
    'title' => $number !== '' ? 'Verify Certificate ' . $number : 'Verify a Certificate',
    'description' => 'Verify the authenticity of certificates issued by ' . $institute . ' — bonafide, transfer, character, course completion, internship, migration and provisional certificates.',
    'active' => 'verify-certificate',
    'robots' => $raw !== '' ? 'noindex,nofollow' : 'index,follow',
    'canonical' => absolute_url('verify-certificate'),
    'seo' => ['route', '/verify-certificate'],
]);
page_hero(
    'Certificate Verification',
    'Instant. Secure. Tamper-proof.',
    ['Verify Certificate'],
    // Result pages (mostly QR scans on phones) use the compact text-only hero so the answer is on the first screen.
    $raw !== '' ? '' : 'assets/images/site/graduation.jpg',
    $raw !== '' ? 'Result of the authenticity check for certificate ' . ($number !== '' ? $number : $raw) . '.'
        : 'Employers, universities and embassies can confirm that a certificate was genuinely issued by ' . $institute . '. Scan the QR code printed on the certificate or enter its number below.',
    // Result pages (often opened from a QR scan on a phone) keep the hero compact so the answer is visible at once.
    $raw !== '' ? [] : [['icon' => 'qr-code', 'label' => 'QR-secured certificates'], ['icon' => 'shield-check', 'label' => 'Official institute records'], ['icon' => 'clock', 'label' => 'Result in seconds']],
    ['size' => 'md']
);

/** One detail row of the result card. */
$detail = function (string $label, string $value, string $extra = ''): string {
    return '<div class="rounded-xl bg-slate-50 px-4 py-3 ' . $extra . '"><dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">' . e($label) . '</dt>'
        . '<dd class="mt-1 break-words text-sm font-semibold text-slate-900">' . $value . '</dd></div>';
};
$fmt = fn (?string $d) => $d ? e(date('d M Y', strtotime($d))) : '—';
?>
<div class="verify-certificate-page">
  <section class="section-tight relative isolate bg-gradient-to-b from-brand-50/70 to-white" aria-label="Verify a certificate">
    <div class="container-site">
      <div class="grid gap-8 lg:grid-cols-12">
        <div class="lg:col-span-7">
          <?php ob_start(); ?>
          <form method="get" action="<?= e(base_url('verify-certificate')) ?>" class="card relative p-5 shadow-xl sm:p-7" role="search" aria-label="Certificate number"<?= reveal_attr('fade-up') ?>>
            <div class="flex items-start gap-4">
              <span class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 sm:inline-flex"><?= icon('search-check', 'h-6 w-6') ?></span>
              <div class="min-w-0 flex-1">
                <h2 class="font-display text-xl font-bold text-brand-900 sm:text-2xl">Verify a certificate</h2>
                <p class="mt-1 text-sm text-slate-600">Enter the certificate number exactly as printed at the top of the certificate.</p>
              </div>
            </div>
            <div class="mt-5 flex flex-col gap-3 sm:flex-row">
              <label for="cert-no" class="sr-only">Certificate number</label>
              <input id="cert-no" name="no" type="text" required maxlength="60" autocomplete="off" spellcheck="false" autocapitalize="characters"
                     value="<?= e($number !== '' ? $number : $raw) ?>" placeholder="e.g. GIMT-BON-<?= e(date('Y')) ?>-00012"
                     class="form-input flex-1 font-mono uppercase tracking-wide" pattern="[A-Za-z0-9\-\/ ]{4,60}" title="Letters, digits and hyphens, e.g. GIMT-BON-<?= e(date('Y')) ?>-00012">
              <button type="submit" class="btn btn-accent justify-center sm:px-7"><?= icon('shield-check', 'h-4 w-4') ?>Verify</button>
            </div>
            <p class="mt-3 flex items-center gap-2 text-xs text-slate-500"><?= icon('lock', 'h-3.5 w-3.5') ?>Lookups are logged for security. Only the holder's name, certificate type, program and dates are shown.</p>
          </form>
          <?php $formHtml = ob_get_clean(); ob_start(); ?>

          <?php if ($state !== null): ?>
          <div aria-live="polite">
            <?php if ($state === 'valid' || $state === 'expired'): ?>
              <?php $ok = $state === 'valid'; ?>
              <article class="card overflow-hidden ring-1 <?= $ok ? 'ring-emerald-200' : 'ring-amber-200' ?>"<?= reveal_attr('zoom-in') ?>>
                <div class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:px-7 <?= $ok ? 'bg-emerald-50' : 'bg-amber-50' ?>">
                  <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full <?= $ok ? 'bg-emerald-600' : 'bg-amber-500' ?> text-white shadow-lg"><?= icon($ok ? 'badge-check' : 'clock', 'h-7 w-7') ?></span>
                  <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-widest <?= $ok ? 'text-emerald-700' : 'text-amber-700' ?>"><?= $ok ? 'Verified · Genuine certificate' : 'Genuine · Validity ended' ?></p>
                    <h3 class="mt-0.5 font-display text-xl font-bold text-slate-900 sm:text-2xl"><?= $ok ? 'This certificate is valid' : 'This certificate has expired' ?></h3>
                    <p class="mt-1 text-sm text-slate-600"><?= $ok
                        ? 'It was issued by ' . e($institute) . ' and matches our records.'
                        : 'It was issued by ' . e($institute) . ', but its validity period ended on ' . $fmt($cert['valid_until']) . '. Ask the holder for a fresh copy.' ?></p>
                  </div>
                </div>
                <?php if ($result['signature_ok'] === false): ?>
                  <div class="flex items-start gap-3 border-y border-amber-200 bg-amber-50/60 px-5 py-3 text-sm text-amber-800 sm:px-7"><?= icon('triangle-alert', 'mt-0.5 h-4 w-4 shrink-0') ?>
                    <p>The QR code signature does not match our records — the link may have been altered. Compare the security code below with the one printed on the certificate.</p></div>
                <?php elseif ($result['signature_ok'] === true): ?>
                  <div class="flex items-center gap-2 border-y border-emerald-100 px-5 py-2.5 text-xs font-medium text-emerald-700 sm:px-7"><?= icon('qr-code', 'h-4 w-4') ?>QR code signature verified.</div>
                <?php endif; ?>
                <dl class="grid gap-3 p-5 sm:grid-cols-2 sm:p-7">
                  <?= $detail('Certificate holder', e($cert['holder']), 'sm:col-span-2') ?>
                  <?= $detail('Certificate', e($cert['type_label'])) ?>
                  <?= $detail('Program', e($cert['program'] ?: '—')) ?>
                  <?= $detail('Certificate number', '<span class="font-mono">' . e($cert['certificate_no']) . '</span>') ?>
                  <?= $detail('Date of issue', $fmt($cert['issue_date'])) ?>
                  <?= $detail('Valid until', $cert['valid_until'] ? $fmt($cert['valid_until']) : 'No expiry') ?>
                  <?= $detail('Status', '<span class="badge ' . ($ok ? 'badge-green' : 'badge-amber') . '">' . ($ok ? 'Valid' : 'Expired') . '</span>') ?>
                  <?= $detail('Security code', '<span class="font-mono tracking-wider">' . e($cert['security_code']) . '</span><span class="mt-0.5 block text-xs font-normal text-slate-500">Must match the code printed at the bottom of the certificate.</span>', 'sm:col-span-2') ?>
                </dl>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-xs text-slate-500 sm:px-7">
                  <span>Verified on <?= e(date('d M Y, h:i A')) ?> IST · <?= e($institute) ?></span>
                  <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm"><?= icon('printer', 'h-4 w-4') ?>Print verification</button>
                </div>
              </article>
            <?php elseif ($state === 'revoked'): ?>
              <article class="card overflow-hidden ring-1 ring-red-200"<?= reveal_attr('zoom-in') ?>>
                <div class="flex flex-col gap-4 bg-red-50 px-5 py-5 sm:flex-row sm:items-center sm:px-7">
                  <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-red-600 text-white shadow-lg"><?= icon('ban', 'h-7 w-7') ?></span>
                  <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-widest text-red-700">Not valid</p>
                    <h3 class="mt-0.5 font-display text-xl font-bold text-slate-900 sm:text-2xl">This certificate has been revoked</h3>
                    <p class="mt-1 text-sm text-slate-600">It was issued by <?= e($institute) ?> but withdrawn<?= $cert['revoked_at'] ? ' on ' . $fmt($cert['revoked_at']) : '' ?>. It must not be accepted as proof. A corrected certificate may have been issued under a new number.</p>
                  </div>
                </div>
                <dl class="grid gap-3 p-5 sm:grid-cols-2 sm:p-7">
                  <?= $detail('Certificate holder', e($cert['holder'])) ?>
                  <?= $detail('Certificate', e($cert['type_label'])) ?>
                  <?= $detail('Certificate number', '<span class="font-mono">' . e($cert['certificate_no']) . '</span>') ?>
                  <?= $detail('Status', '<span class="badge badge-red">Revoked</span>') ?>
                </dl>
              </article>
            <?php elseif ($state === 'rate_limited'): ?>
              <article class="card flex items-start gap-4 p-5 ring-1 ring-amber-200 sm:p-7">
                <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700"><?= icon('hourglass', 'h-6 w-6') ?></span>
                <div><h3 class="font-display text-lg font-bold text-slate-900">Too many lookups</h3>
                  <p class="mt-1 text-sm text-slate-600">For security, verification requests from your network are limited. Please wait a few minutes and try again, or contact the examination office for bulk verification.</p></div>
              </article>
            <?php else: ?>
              <article class="card overflow-hidden ring-1 ring-slate-200"<?= reveal_attr('zoom-in') ?>>
                <div class="flex flex-col gap-4 px-5 py-6 sm:flex-row sm:items-center sm:px-7">
                  <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-600"><?= icon('search-check', 'h-7 w-7') ?></span>
                  <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-widest text-red-600">No match</p>
                    <h3 class="mt-0.5 font-display text-xl font-bold text-slate-900 sm:text-2xl">No certificate found</h3>
                    <p class="mt-1 text-sm text-slate-600">We could not find a certificate numbered <span class="font-mono font-semibold text-slate-800"><?= e($number) ?></span> in the records of <?= e($institute) ?>.</p>
                  </div>
                </div>
                <ul class="space-y-2 border-t border-slate-100 px-5 py-4 text-sm text-slate-600 sm:px-7">
                  <li class="flex gap-2"><?= icon('check', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?><span>Check for typing mistakes — the number looks like <span class="whitespace-nowrap font-mono">GIMT-BON-<?= e(date('Y')) ?>-00012</span> (letter O vs digit 0, I vs 1).</span></li>
                  <li class="flex gap-2"><?= icon('check', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>Scan the QR code on the certificate to open its verification page directly.</li>
                  <li class="flex gap-2"><?= icon('check', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>Still no match? The document may not be genuine — contact us before accepting it.</li>
                </ul>
              </article>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php $resultHtml = ob_get_clean(); ?>
          <?php if ($state !== null): // result first (QR visitors on phones), then "verify another" ?>
            <div class="relative -mt-16 sm:-mt-20"><?= $resultHtml ?></div>
            <div class="mt-6"><?= str_replace(['>Verify a certificate<', 'shadow-xl'], ['>Verify another certificate<', 'shadow-card'], $formHtml) ?></div>
          <?php else: ?>
            <div class="relative -mt-16 sm:-mt-20"><?= $formHtml ?></div>
            <div class="mt-8"<?= reveal_attr('fade-up', 120) ?>>
              <h2 class="font-display text-lg font-bold text-brand-900">What the result means</h2>
              <p class="mt-1 text-sm text-slate-600">Every lookup returns one of four answers, straight from the institute register.</p>
              <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                <?php foreach ([
                    ['badge-check', 'bg-emerald-50 text-emerald-700 ring-emerald-100', 'Valid', 'Issued by the institute and currently in force. Compare the holder name and security code with the paper copy.'],
                    ['clock', 'bg-amber-50 text-amber-700 ring-amber-100', 'Expired', 'Genuine, but its validity period (for example a bonafide certificate) has ended. Ask the holder for a fresh copy.'],
                    ['ban', 'bg-red-50 text-red-700 ring-red-100', 'Revoked', 'Withdrawn or replaced by the institute. It must not be accepted — a corrected copy carries a new number.'],
                    ['circle-help', 'bg-slate-100 text-slate-700 ring-slate-200', 'Not found', 'No certificate with that number exists. Check for typing mistakes, then contact the examination office.'],
                ] as [$ic, $tone, $label, $text]): ?>
                  <li class="flex gap-3 rounded-2xl bg-white p-4 shadow-card ring-1 ring-slate-100">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 <?= $tone ?>"><?= icon($ic, 'h-5 w-5') ?></span>
                    <div><p class="font-semibold text-slate-900"><?= e($label) ?></p><p class="mt-0.5 text-sm text-slate-600"><?= e($text) ?></p></div>
                  </li>
                <?php endforeach; ?>
              </ul>
              <div class="mt-5 flex items-start gap-3 rounded-2xl border border-dashed border-slate-300 bg-white/70 p-4 text-sm text-slate-600">
                <?= icon('hash', 'mt-0.5 h-5 w-5 shrink-0 text-brand-700') ?>
                <p><span class="font-semibold text-slate-900">Where is the number?</span> It is printed at the top left of every certificate (for example
                  <span class="whitespace-nowrap font-mono text-slate-800">GIMT-TC-<?= e(date('Y')) ?>-00004</span>), next to the QR code at the bottom and in the e-mail sent to the student.</p>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <aside class="space-y-5 lg:col-span-5 lg:pt-2">
          <div class="card p-5 sm:p-6"<?= reveal_attr('fade-up', 80) ?>>
            <p class="eyebrow">How it works</p>
            <h2 class="mt-1 font-display text-lg font-bold text-brand-900">Three steps to a trusted answer</h2>
            <ol class="mt-4 space-y-4">
              <?php foreach ([
                  ['qr-code', 'Scan or type', 'Scan the QR code on the certificate, or type the certificate number printed at the top.'],
                  ['database', 'We check our records', 'The number is matched against the institute register of issued, revoked and expired certificates.'],
                  ['badge-check', 'Compare the details', 'Confirm the holder name, certificate type, dates and security code with the paper copy.'],
              ] as $i => [$ic, $t, $d]): ?>
                <li class="flex gap-3">
                  <span class="relative inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700"><?= icon($ic, 'h-5 w-5') ?>
                    <span class="absolute -right-1.5 -top-1.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-accent-600 text-[10px] font-bold text-white"><?= $i + 1 ?></span></span>
                  <div><p class="font-semibold text-slate-900"><?= e($t) ?></p><p class="text-sm text-slate-600"><?= e($d) ?></p></div>
                </li>
              <?php endforeach; ?>
            </ol>
          </div>
          <div class="card p-5 sm:p-6"<?= reveal_attr('fade-up', 160) ?>>
            <h2 class="flex items-center gap-2 font-display text-base font-bold text-brand-900"><?= icon('shield', 'h-5 w-5 text-accent-600') ?>Privacy first</h2>
            <p class="mt-2 text-sm text-slate-600">Verification never reveals contact details, date of birth, marks or addresses. Certificates that were revoked or replaced are clearly reported so they cannot be misused.</p>
            <div class="mt-4 grid grid-cols-2 gap-2 text-xs font-medium text-slate-600">
              <?php foreach (['Bonafide', 'Transfer (TC)', 'Character', 'Course Completion', 'Internship', 'Migration', 'Provisional'] as $t): ?>
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-50 px-2.5 py-1.5"><?= icon('file-badge', 'h-3.5 w-3.5 text-brand-600') ?><?= e($t) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="card relative overflow-hidden bg-brand-900 p-5 text-white sm:p-6"<?= reveal_attr('fade-up', 240) ?>>
            <div class="pattern-dots-light pointer-events-none absolute inset-0 opacity-40" aria-hidden="true"></div>
            <div class="relative">
              <h2 class="font-display text-base font-bold text-white">Need an official confirmation?</h2>
              <p class="mt-1.5 text-sm text-white/80">For bulk or written verification requests, write to the examination office with the certificate number and the holder's consent.</p>
              <div class="mt-4 flex flex-col gap-2 text-sm">
                <a class="inline-flex items-center gap-2 font-semibold text-white hover:text-accent-300" href="mailto:<?= e($email) ?>"><?= icon('mail', 'h-4 w-4') ?><?= e($email) ?></a>
                <a class="inline-flex items-center gap-2 font-semibold text-white hover:text-accent-300" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= icon('phone', 'h-4 w-4') ?><?= e($phone) ?></a>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  </section>
</div>
<?php site_footer();
