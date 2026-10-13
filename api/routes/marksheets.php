<?php
/**
 * Marksheets API (brief sections 16 + 47). The list is the CRUD module "marksheets"; printing is admin/print/marksheet.php.
 *
 *   GET  /marksheets/summary?session_id=          KPIs + exams with published results awaiting marksheets
 *   POST /marksheets/generate   {exam_id, result_ids?: []}   unique numbers via next_number('marksheet')
 *   POST /marksheets/{id}/revoke  {reason}
 *   POST /marksheets/{id}/reissue
 *   GET  /marksheets/verify/{no}?code=             public verification - the URL in the QR code on the printed marksheet. Browsers get an
 *                                                  HTML verification page (website chrome), API clients JSON. Without the security code only
 *                                                  the existence/status of the marksheet is confirmed (numbers are sequential).
 */
require_once APP_ROOT . '/app/services/exams.php';

route('GET', '/marksheets/summary', function () {
    api_require('results', 'view');
    $sid = isset($_GET['session_id']) && $_GET['session_id'] !== '' ? (int) $_GET['session_id'] : null;
    $w = $sid ? ' AND r.academic_session_id = ?' : '';
    $a = $sid ? [$sid] : [];
    $k = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(m.status = 'issued'), 0) AS issued, COALESCE(SUM(m.status = 'revoked'), 0) AS revoked,
                        COALESCE(SUM(m.status = 'issued' AND m.printed_count = 0), 0) AS not_printed, COALESCE(SUM(m.printed_count), 0) AS prints,
                        COALESCE(SUM(m.issued_at >= ?), 0) AS this_month
                 FROM marksheets m JOIN results r ON r.id = m.result_id WHERE 1 = 1$w", array_merge([date('Y-m-01')], $a));
    $pendingTotal = (int) db_value("SELECT COUNT(*) FROM results r JOIN exams e ON e.id = r.exam_id JOIN exam_types et ON et.id = e.exam_type_id LEFT JOIN marksheets m ON m.result_id = r.id
                                     WHERE et.is_final = 1 AND r.is_published = 1 AND r.result_status <> 'WITHHELD' AND m.id IS NULL$w", $a);
    $pending = db_all("SELECT e.id, e.name, e.published_at, e.result_date, et.is_final, CONCAT(COALESCE(p.short_name, 'All programs'), IFNULL(CONCAT(' · Sem ', e.semester_no), '')) AS cohort,
                              ss.name AS session_name, COUNT(r.id) AS pending,
                              (SELECT COUNT(*) FROM marksheets m2 JOIN results r2 ON r2.id = m2.result_id WHERE r2.exam_id = e.id AND m2.status = 'issued') AS issued,
                              (SELECT COUNT(*) FROM results r3 WHERE r3.exam_id = e.id AND r3.result_status = 'WITHHELD') AS withheld
                       FROM results r JOIN exams e ON e.id = r.exam_id JOIN exam_types et ON et.id = e.exam_type_id LEFT JOIN programs p ON p.id = e.program_id
                       LEFT JOIN academic_sessions ss ON ss.id = e.academic_session_id LEFT JOIN marksheets m ON m.result_id = r.id
                       WHERE et.is_final = 1 AND r.is_published = 1 AND r.result_status <> 'WITHHELD' AND m.id IS NULL$w
                       GROUP BY e.id, e.name, e.published_at, e.result_date, et.is_final, p.short_name, e.semester_no, ss.name, p.sort_order
                       ORDER BY e.published_at DESC, p.sort_order, e.semester_no LIMIT 50", $a);
    foreach ($pending as &$p) {
        foreach (['id', 'is_final', 'pending', 'issued', 'withheld'] as $key) {
            $p[$key] = (int) $p[$key];
        }
    }
    unset($p);
    $recent = db_all("SELECT DATE(m.issued_at) AS d, COUNT(*) AS n FROM marksheets m JOIN results r ON r.id = m.result_id WHERE m.issued_at >= ?$w GROUP BY DATE(m.issued_at) ORDER BY d",
        array_merge([date('Y-m-d', strtotime('-29 days'))], $a));
    api_ok(['kpis' => array_map('intval', $k) + ['pending' => $pendingTotal], 'pending' => $pending, 'recent' => $recent, 'can_generate' => marksheets_can_issue(), 'can_revoke' => marksheets_can_revoke()]);
});

route('POST', '/marksheets/generate', function () {
    if (!marksheets_can_issue()) {
        api_error('You do not have permission to issue marksheets.', 403);
    }
    $in = api_validate(['exam_id' => 'required|integer|exists:exams,id'], null, ['exam_id' => 'Exam']);
    $raw = api_input()['result_ids'] ?? null;
    $ids = null;
    if ($raw !== null && $raw !== '') {
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : explode(',', (string) $raw)))));
        if (!$ids) {
            api_error('Select at least one result.', 422);
        }
    }
    $res = db_transaction(fn () => marksheet_generate((int) $in['exam_id'], $ids));
    $e = exam_find((int) $in['exam_id']);
    if (!$res['generated']) {
        api_ok($res, $res['skipped_withheld'] ? 'No marksheets generated — the remaining results are withheld.' : 'Marksheets are already generated for every published result of this exam.');
    }
    log_activity('create', 'results', (int) $in['exam_id'], sprintf('Generated %d marksheet%s for "%s"', $res['generated'], $res['generated'] === 1 ? '' : 's', $e['name'] ?? ''));
    notify(exam_users_with('results', 'publish'), 'marksheets_generated', $res['generated'] . ' marksheets generated', ($e['name'] ?? '') . ' — ready to print.', '/marksheets?exam=' . (int) $in['exam_id'], 'file-badge');
    api_ok($res, sprintf('%d marksheet%s generated%s.', $res['generated'], $res['generated'] === 1 ? '' : 's', $res['skipped_withheld'] ? ' (' . $res['skipped_withheld'] . ' withheld results skipped)' : ''));
});

route('POST', '/marksheets/{id:\d+}/revoke', function ($p) {
    if (!marksheets_can_revoke()) {
        api_error('You do not have permission to revoke marksheets.', 403);
    }
    $m = marksheet_find((int) $p['id']);
    if (!$m) {
        api_error('Marksheet not found.', 404);
    }
    $in = api_validate(['reason' => 'required|min:5|max:255'], null, ['reason' => 'Reason']);
    marksheet_revoke($m, (string) $in['reason']);
    log_activity('update', 'results', (int) $m['exam_id'], 'Revoked marksheet ' . $m['marksheet_no'] . ' of ' . $m['student_name'] . ': ' . $in['reason']);
    api_ok(null, 'Marksheet ' . $m['marksheet_no'] . ' revoked. QR verification now reports it as revoked.');
});

route('POST', '/marksheets/{id:\d+}/reissue', function ($p) {
    if (!marksheets_can_issue()) {
        api_error('You do not have permission to issue marksheets.', 403);
    }
    $m = marksheet_find((int) $p['id']);
    if (!$m) {
        api_error('Marksheet not found.', 404);
    }
    $no = db_transaction(fn () => marksheet_reissue($m));
    log_activity('create', 'results', (int) $m['exam_id'], 'Re-issued marksheet ' . $m['marksheet_no'] . ' of ' . $m['student_name'] . ' as ' . $no);
    api_ok(['marksheet_no' => $no], 'Marksheet re-issued as ' . $no . '.');
});

route('GET', '/marksheets/verify/{no}', function ($p) {
    $no = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9\-]/', '', (string) $p['no'])));
    $code = isset($_GET['code']) ? (string) $_GET['code'] : null;
    $html = marksheets_verify_wants_html();
    if (!rate_limit('ms-verify:' . client_ip(), 30, 60)) {
        $html ? marksheets_verify_page($no, null, true) : api_error('Too many verification attempts. Please wait a minute and try again.', 429);
    }
    $m = $no !== '' ? marksheet_verify(substr($no, 0, 40), $code) : null;
    if ($html) {
        marksheets_verify_page($no, $m);
    }
    if (!$m) {
        api_error('No marksheet with this number was issued by ' . institute_name() . '.', 404);
    }
    api_ok($m, $m['status'] === 'revoked' ? (!empty($m['replaced']) ? 'This marksheet was revoked and replaced by a re-issued copy.' : 'This marksheet has been revoked.') : ($m['valid'] ? 'Genuine marksheet.'
        : ($m['code_checked'] ? 'The security code does not match this marksheet.' : 'Marksheet found. Provide the security code printed on it to see the verified details.')));
}, ['auth' => false, 'csrf' => false]);

/** Browsers (QR scans) get the verification page; API clients (Accept: application/json or ?format=json) get JSON. */
function marksheets_verify_wants_html(): bool
{
    $format = (string) ($_GET['format'] ?? '');
    if ($format !== '') {
        return $format === 'html';
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'text/html') && !str_contains($accept, 'application/json');
}

/** Public marksheet verification page (website chrome). The result itself is shown only when the security code matches. */
function marksheets_verify_page(string $no, ?array $m, bool $limited = false): void
{
    require_once APP_ROOT . '/includes/config.php';
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    http_response_code($limited ? 429 : ($m || $no === '' ? 200 : 404));
    $institute = institute_name();
    $self = base_url('api/marksheets/verify/' . rawurlencode($no));
    $fmt = fn (?string $d) => $d ? e(date('d M Y', strtotime($d))) : '—';
    $detail = fn (string $label, string $value, string $extra = '') => '<div class="rounded-xl bg-slate-50 px-4 py-3 ' . $extra . '"><dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">'
        . e($label) . '</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-900">' . $value . '</dd></div>';
    $statusLabel = ['PASS' => 'Pass', 'BACKLOG' => 'Pass with backlog', 'FAIL' => 'Fail', 'ABSENT' => 'Absent', 'WITHHELD' => 'Withheld'];
    $state = $limited ? 'limited' : (!$m ? 'not_found' : ($m['status'] === 'revoked' ? 'revoked' : ($m['valid'] ? 'valid' : ($m['code_checked'] ? 'mismatch' : 'needs_code'))));
    site_header(['title' => 'Verify Marksheet' . ($no !== '' ? ' ' . $no : ''), 'description' => 'Verify the authenticity of a marksheet issued by ' . $institute . '.',
        'active' => 'verify-certificate', 'robots' => 'noindex,nofollow', 'canonical' => absolute_url('verify-certificate')]);
    page_hero('Marksheet Verification', 'Instant. Secure. Tamper-proof.', ['Verify Marksheet'], '',
        'Result of the authenticity check for marksheet ' . ($no !== '' ? $no : '') . '.', [], ['size' => 'md']);
    ?>
<div class="verify-certificate-page">
  <section class="section-tight relative isolate bg-gradient-to-b from-brand-50/70 to-white" aria-label="Marksheet verification">
    <div class="container-site">
      <div class="mx-auto max-w-3xl">
        <div class="relative -mt-16 sm:-mt-20" aria-live="polite">
        <?php if ($state === 'valid'): ?>
          <article class="card overflow-hidden ring-1 ring-emerald-200">
            <div class="flex flex-col gap-4 bg-emerald-50 px-5 py-5 sm:flex-row sm:items-center sm:px-7">
              <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white shadow-lg"><?= icon('badge-check', 'h-7 w-7') ?></span>
              <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-widest text-emerald-700">Verified · Genuine marksheet</p>
                <h3 class="mt-0.5 font-display text-xl font-bold text-slate-900 sm:text-2xl">This marksheet is valid</h3>
                <p class="mt-1 text-sm text-slate-600">It was issued by <?= e($institute) ?> and matches our records. Compare the details below with the paper copy.</p>
              </div>
            </div>
            <div class="flex items-center gap-2 border-y border-emerald-100 px-5 py-2.5 text-xs font-medium text-emerald-700 sm:px-7"><?= icon('qr-code', 'h-4 w-4') ?>Security code verified.</div>
            <dl class="grid gap-3 p-5 sm:grid-cols-2 sm:p-7">
              <?= $detail('Student', e($m['student_name']), 'sm:col-span-2') ?>
              <?= $detail('Program', e($m['program_name'] ?: '—')) ?>
              <?= $detail('Semester · Session', e('Semester ' . ($m['semester_no'] ?? '—') . ' · ' . ($m['session_name'] ?? '—'))) ?>
              <?= $detail('Examination', e($m['exam_name']), 'sm:col-span-2') ?>
              <?= $detail('SGPA', e($m['sgpa'] !== null ? number_format($m['sgpa'], 2) : '—')) ?>
              <?= $detail('CGPA', e($m['cgpa'] !== null ? number_format($m['cgpa'], 2) : '—')) ?>
              <?= $detail('Result', '<span class="badge ' . ($m['result_status'] === 'PASS' ? 'badge-green' : ($m['result_status'] === 'BACKLOG' ? 'badge-amber' : 'badge-red')) . '">' . e($statusLabel[$m['result_status']] ?? $m['result_status']) . '</span>') ?>
              <?= $detail('Percentage', e($m['percentage'] !== null ? rtrim(rtrim(number_format($m['percentage'], 2), '0'), '.') . '%' : '—')) ?>
              <?= $detail('Marksheet number', '<span class="font-mono">' . e($m['marksheet_no']) . '</span>') ?>
              <?= $detail('Date of issue', $fmt($m['issued_at'])) ?>
              <?= $detail('Security code', '<span class="font-mono tracking-wider">' . e($m['security_code']) . '</span><span class="mt-0.5 block text-xs font-normal text-slate-500">Must match the code printed next to the QR code.</span>', 'sm:col-span-2') ?>
            </dl>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-xs text-slate-500 sm:px-7">
              <span>Verified on <?= e(date('d M Y, h:i A')) ?> IST · <?= e($institute) ?></span>
              <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm"><?= icon('printer', 'h-4 w-4') ?>Print verification</button>
            </div>
          </article>
        <?php elseif ($state === 'revoked'): ?>
          <article class="card overflow-hidden ring-1 ring-red-200">
            <div class="flex flex-col gap-4 bg-red-50 px-5 py-5 sm:flex-row sm:items-center sm:px-7">
              <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-red-600 text-white shadow-lg"><?= icon('ban', 'h-7 w-7') ?></span>
              <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-widest text-red-700">Not valid</p>
                <h3 class="mt-0.5 font-display text-xl font-bold text-slate-900 sm:text-2xl">This marksheet has been revoked</h3>
                <p class="mt-1 text-sm text-slate-600"><?= !empty($m['replaced'])
                    ? 'This copy was withdrawn by ' . e($institute) . ' and replaced by a re-issued marksheet with a new number. It must not be accepted as proof &mdash; ask the student for the current copy.'
                    : 'It was issued by ' . e($institute) . ' but withdrawn' . ($m['revoked_at'] ? ' on ' . $fmt($m['revoked_at']) : '') . '. It must not be accepted as proof. A corrected marksheet may have been issued under a new number.' ?></p>
              </div>
            </div>
            <dl class="grid gap-3 p-5 sm:grid-cols-2 sm:p-7">
              <?= $detail('Student', e($m['student_name'])) ?>
              <?= $detail('Marksheet number', '<span class="font-mono">' . e($m['marksheet_no']) . '</span>') ?>
              <?= $detail('Program', e($m['program_name'] ?: '—')) ?>
              <?= $detail('Status', '<span class="badge badge-red">Revoked</span>') ?>
            </dl>
          </article>
        <?php elseif ($state === 'needs_code' || $state === 'mismatch'): ?>
          <?php $mismatch = $state === 'mismatch'; ?>
          <article class="card overflow-hidden ring-1 <?= $mismatch ? 'ring-amber-200' : 'ring-slate-200' ?>">
            <div class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:px-7 <?= $mismatch ? 'bg-amber-50' : '' ?>">
              <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full <?= $mismatch ? 'bg-amber-500 text-white shadow-lg' : 'bg-brand-50 text-brand-700' ?>"><?= icon($mismatch ? 'triangle-alert' : 'shield-check', 'h-7 w-7') ?></span>
              <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-widest <?= $mismatch ? 'text-amber-700' : 'text-brand-700' ?>"><?= $mismatch ? 'Security code does not match' : 'Marksheet found' ?></p>
                <h3 class="mt-0.5 font-display text-xl font-bold text-slate-900 sm:text-2xl"><?= $mismatch ? 'Check the security code' : 'Enter the security code to see the result' ?></h3>
                <p class="mt-1 text-sm text-slate-600"><?= $mismatch
                    ? 'A marksheet with this number exists, but this is not the security code printed on it. The document may have been altered — contact the examination office before accepting it.'
                    : 'A marksheet with this number was issued by ' . e($institute) . '. To protect the student&rsquo;s privacy, the result is shown only with the security code printed next to the QR code.' ?></p>
              </div>
            </div>
            <dl class="grid gap-3 border-t border-slate-100 p-5 sm:grid-cols-2 sm:p-7">
              <?= $detail('Marksheet number', '<span class="font-mono">' . e($m['marksheet_no']) . '</span>') ?>
              <?= $detail('Student', e($m['student_name'])) ?>
              <?= $detail('Program', e($m['program_name'] ?: '—')) ?>
              <?= $detail('Semester · Session', e('Semester ' . ($m['semester_no'] ?? '—') . ' · ' . ($m['session_name'] ?? '—'))) ?>
            </dl>
            <form method="get" action="<?= e($self) ?>" class="flex flex-col gap-3 border-t border-slate-100 px-5 py-5 sm:flex-row sm:items-end sm:px-7">
              <div class="flex-1">
                <label for="ms-code" class="form-label">Security code</label>
                <input id="ms-code" name="code" type="text" required maxlength="40" autocomplete="off" spellcheck="false" autocapitalize="characters" placeholder="e.g. 21BD-E3E0-9716"
                       class="form-input w-full font-mono uppercase tracking-wide" pattern="[A-Fa-f0-9\- ]{12,40}" title="12 characters (0-9, A-F), as printed next to the QR code">
              </div>
              <button type="submit" class="btn btn-accent justify-center sm:px-7"><?= icon('shield-check', 'h-4 w-4') ?>Verify</button>
            </form>
          </article>
        <?php elseif ($state === 'limited'): ?>
          <article class="card flex items-start gap-4 p-5 ring-1 ring-amber-200 sm:p-7">
            <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700"><?= icon('hourglass', 'h-6 w-6') ?></span>
            <div><h3 class="font-display text-lg font-bold text-slate-900">Too many lookups</h3>
              <p class="mt-1 text-sm text-slate-600">For security, verification requests from your network are limited. Please wait a minute and try again.</p></div>
          </article>
        <?php else: ?>
          <article class="card overflow-hidden ring-1 ring-slate-200">
            <div class="flex flex-col gap-4 px-5 py-6 sm:flex-row sm:items-center sm:px-7">
              <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-600"><?= icon('search-check', 'h-7 w-7') ?></span>
              <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-widest text-red-600">No match</p>
                <h3 class="mt-0.5 font-display text-xl font-bold text-slate-900 sm:text-2xl">No marksheet found</h3>
                <p class="mt-1 text-sm text-slate-600">We could not find a marksheet numbered <span class="font-mono font-semibold text-slate-800"><?= e($no) ?></span> in the records of <?= e($institute) ?>. Check the number (it looks like <span class="whitespace-nowrap font-mono">GIMT-MS-<?= e(date('Y')) ?>-000123</span>) or scan the QR code on the marksheet.</p>
              </div>
            </div>
          </article>
        <?php endif; ?>
        </div>
        <p class="mt-6 flex items-start gap-2 text-xs text-slate-500"><?= icon('lock', 'mt-0.5 h-3.5 w-3.5 shrink-0') ?>Lookups are rate limited for security. Verification never reveals contact details, date of birth or addresses. For written confirmation, write to the examination office with the marksheet number and the student's consent.</p>
      </div>
    </div>
  </section>
</div>
<?php
    site_footer();
    exit;
}
