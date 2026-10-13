<?php
/**
 * Statement of marks / grade card (A4 portrait, one sheet per student) with QR verification.
 *   /admin/print/marksheet.php?id={marksheet_id}
 *   /admin/print/marksheet.php?ids=1,2,3                 bulk print
 *   /admin/print/marksheet.php?exam_id={exam_id}         every issued marksheet of an exam
 *   /admin/print/marksheet.php?result_id={result_id}     provisional statement for a published result without a marksheet
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/exams.php';
require_permission('results', 'view');

$ids = [];
$provisional = null;
if (input_int('id')) {
    $ids = [input_int('id')];
} elseif (trim((string) input('ids', '')) !== '') {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) input('ids')))))), 0, 500);
} elseif (input_int('exam_id')) {
    $ids = array_map('intval', db_column("SELECT m.id FROM marksheets m JOIN results r ON r.id = m.result_id JOIN students s ON s.id = m.student_id
                                          WHERE r.exam_id = ? AND m.status = 'issued' ORDER BY s.roll_no, s.student_uid LIMIT 500", [input_int('exam_id')]));
} elseif (input_int('result_id')) {
    $r = result_find(input_int('result_id'));
    if ($r && (int) $r['is_published'] && $r['result_status'] !== 'WITHHELD') {
        $provisional = $r;
    }
}

$sheets = [];
foreach ($ids as $id) {
    $d = marksheet_print_data($id);
    if ($d) {
        $sheets[] = $d;
    }
}
if ($provisional) {
    $student = db_row('SELECT s.*, b.name AS batch_name, c.name AS course_name FROM students s LEFT JOIN batches b ON b.id = s.batch_id LEFT JOIN courses c ON c.id = s.course_id WHERE s.id = ?', [(int) $provisional['student_id']]);
    $sheets[] = ['marksheet' => null, 'result' => $provisional, 'student' => $student, 'subjects' => result_subject_marks($provisional), 'verify_url' => null,
        'history' => (int) $provisional['is_final'] ? array_values(array_filter(result_student_history((int) $provisional['student_id']), fn ($h) => (int) $h['is_final'] === 1)) : []];
}

if (!$sheets) {
    http_response_code(404);
    print_layout_start('Marksheet not found');
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">Marksheet not found</p>'
        . '<p class="mt-2 text-sm text-slate-500">' . (input_int('result_id') ? 'Only published results that are not withheld can be printed.' : 'The marksheet does not exist, or no marksheets have been issued yet.') . '</p>'
        . '<a href="' . e(admin_url('marksheets')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to marksheets</a></div>';
    print_layout_end();
    exit;
}

// Print audit: count prints of issued marksheets
$printed = array_values(array_filter(array_map(fn ($s) => $s['marksheet'] && $s['marksheet']['status'] === 'issued' ? (int) $s['marksheet']['id'] : 0, $sheets)));
if ($printed) {
    db_exec('UPDATE marksheets SET printed_count = printed_count + 1, last_printed_at = NOW() WHERE id IN (' . implode(',', $printed) . ')');
}
$first = $sheets[0];
log_activity('print', 'results', (int) $first['result']['exam_id'], count($sheets) === 1
    ? 'Printed ' . ($first['marksheet'] ? 'marksheet ' . $first['marksheet']['marksheet_no'] : 'provisional statement of marks') . ' of ' . $first['result']['student_name']
    : 'Printed ' . count($sheets) . ' marksheets of "' . $first['result']['exam_name'] . '"');

$scale = grade_scale();
$controller = (string) setting('exam_controller_name', '');
$controllerTitle = (string) setting('exam_controller_designation', 'Controller of Examinations');
$affiliation = (string) setting('affiliation', 'Approved by AICTE · Affiliated to State University · NAAC Accredited');
$fmt = function ($v, int $d = 2): string {
    if ($v === null || $v === '') {
        return '—';
    }
    $s = number_format((float) $v, $d, '.', '');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
};
$sheetClass = 'print-sheet bg-white mx-auto my-6 shadow-xl w-[210mm] min-h-[297mm] p-[12mm]';

print_layout_start(count($sheets) === 1 ? ($first['marksheet'] ? 'Marksheet ' . $first['marksheet']['marksheet_no'] : 'Provisional statement of marks') : count($sheets) . ' marksheets', false);
?>
<style>
  .ms-frame{position:relative;border:2px solid #0B2A5B;padding:5mm;min-height:270mm;display:flex;flex-direction:column}
  .ms-frame:before{content:"";position:absolute;inset:1.6mm;border:.6px solid #22943F;pointer-events:none}
  .ms-watermark{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;opacity:.045}
  .ms-watermark img{width:62%}
  .ms-table th,.ms-table td{border:1px solid #cbd5e1;padding:4px 5px}
  .ms-table thead th{background:#0B2A5B;color:#fff;font-weight:600;border-color:#0B2A5B}
  .ms-stamp{position:absolute;top:38%;left:50%;transform:translate(-50%,-50%) rotate(-18deg);font-size:64px;font-weight:800;letter-spacing:.2em;border:6px solid currentColor;padding:4px 24px;border-radius:12px;opacity:.18}
  @media print{.ms-page{break-after:page}.ms-page:last-child{break-after:auto}}
</style>
<?php foreach ($sheets as $i => $d):
    $m = $d['marksheet'];
    $r = $d['result'];
    $s = $d['student'] ?? [];
    $isFinal = (int) $r['is_final'] === 1;
    $revoked = $m && $m['status'] === 'revoked';
    $statusText = ['PASS' => 'PASS', 'BACKLOG' => 'PASS WITH BACKLOG', 'FAIL' => 'FAIL', 'ABSENT' => 'ABSENT', 'WITHHELD' => 'WITHHELD'][$r['result_status']] ?? $r['result_status'];
    $statusColor = ['PASS' => 'text-emerald-700', 'BACKLOG' => 'text-amber-700', 'FAIL' => 'text-red-700', 'ABSENT' => 'text-slate-600', 'WITHHELD' => 'text-violet-700'][$r['result_status']] ?? 'text-slate-800';
    $examMonth = $r['end_date'] ? date('F Y', strtotime($r['end_date'])) : '';
    $photo = !empty($s['photo']) ? upload_url($s['photo']) : '';
    if ($i > 0) {
        echo '</div><div class="' . $sheetClass . '">';
    }
    ?>
  <section class="ms-page">
    <div class="ms-frame">
      <div class="ms-watermark"><img src="<?= e(logo_url()) ?>" alt=""></div>
      <?php if ($revoked): ?><div class="ms-stamp text-red-600">REVOKED</div><?php elseif (!$m): ?><div class="ms-stamp text-slate-500">PROVISIONAL</div><?php endif; ?>

      <header class="relative flex items-center gap-4 border-b-2 border-brand-900 pb-3">
        <img src="<?= e(logo_url()) ?>" alt="GIMT" class="h-16 w-auto">
        <div class="flex-1 text-center">
          <p class="font-display text-[19px] font-extrabold uppercase leading-tight tracking-wide text-brand-900"><?= e(institute_name()) ?></p>
          <p class="mt-0.5 text-[10px] text-slate-600"><?= e($affiliation) ?></p>
          <p class="text-[10px] text-slate-500"><?= e(setting('address', 'Knowledge Park, Greater Noida, Uttar Pradesh')) ?></p>
        </div>
        <div class="w-16 text-right text-[9px] leading-tight text-slate-500">
          <?php if ($m): ?>Sl. No.<br><span class="font-mono text-[10px] font-semibold text-slate-800"><?= e(str_pad((string) $m['id'], 6, '0', STR_PAD_LEFT)) ?></span><?php endif; ?>
        </div>
      </header>

      <div class="relative mt-3 text-center">
        <p class="inline-block rounded-full bg-brand-900 px-5 py-1 text-[12px] font-bold uppercase tracking-[0.2em] text-white"><?= $isFinal ? 'Grade Card · Statement of Marks' : 'Statement of Marks' ?></p>
        <p class="mt-2 text-[12px] font-semibold text-slate-800"><?= e($r['type_name']) ?> — <?= e($examMonth) ?></p>
        <p class="text-[10.5px] text-slate-600"><?= e($r['program_name'] ?? '') ?> · Semester <?= e($r['semester_no']) ?> · Academic Session <?= e($r['session_name'] ?? '') ?></p>
      </div>

      <div class="relative mt-3 flex gap-4">
        <table class="w-full text-[10.5px]">
          <tbody>
            <tr><td class="w-[24%] py-0.5 text-slate-500">Name of the student</td><td class="py-0.5 font-bold uppercase text-slate-900" colspan="3"><?= e($r['student_name']) ?></td></tr>
            <tr><td class="py-0.5 text-slate-500">Father's name</td><td class="py-0.5 font-medium text-slate-800"><?= e($r['father_name'] ?: '—') ?></td>
                <td class="w-[18%] py-0.5 text-slate-500">Mother's name</td><td class="py-0.5 font-medium text-slate-800"><?= e($r['mother_name'] ?: '—') ?></td></tr>
            <tr><td class="py-0.5 text-slate-500">Enrollment no.</td><td class="py-0.5 font-mono font-semibold text-slate-800"><?= e($r['enrollment_no'] ?: $r['student_uid']) ?></td>
                <td class="py-0.5 text-slate-500">Roll no.</td><td class="py-0.5 font-mono font-semibold text-slate-800"><?= e($r['roll_no'] ?: '—') ?></td></tr>
            <tr><td class="py-0.5 text-slate-500">Student ID</td><td class="py-0.5 font-mono text-slate-800"><?= e($r['student_uid']) ?></td>
                <td class="py-0.5 text-slate-500">Programme</td><td class="py-0.5 font-medium text-slate-800"><?= e($r['program_short'] ?? '') ?><?= !empty($s['course_name']) ? ' (' . e($s['course_name']) . ')' : '' ?></td></tr>
            <tr><td class="py-0.5 text-slate-500">Examination</td><td class="py-0.5 text-slate-800" colspan="3"><?= e($r['exam_name']) ?></td></tr>
          </tbody>
        </table>
        <div class="flex h-[30mm] w-[24mm] shrink-0 items-center justify-center overflow-hidden rounded border border-slate-300 bg-slate-50">
          <?php if ($photo): ?><img src="<?= e($photo) ?>" alt="" class="h-full w-full object-cover"><?php else: ?><span class="font-display text-xl font-bold text-slate-400"><?= e(initials($r['student_name'])) ?></span><?php endif; ?>
        </div>
      </div>

      <table class="ms-table relative mt-3 w-full border-collapse text-[10px]">
        <thead>
          <tr>
            <th class="w-6 text-center" rowspan="2">#</th><th class="text-left" rowspan="2">Code</th><th class="text-left" rowspan="2">Subject</th><th class="text-center" rowspan="2">Credits</th>
            <th class="text-center" colspan="4">Marks obtained (maximum)</th><th class="text-center" rowspan="2">Grade</th><th class="text-center" rowspan="2">Grade<br>point</th><th class="text-center" rowspan="2">Result</th>
          </tr>
          <tr><th class="text-center">Internal</th><th class="text-center">External</th><th class="text-center">Practical</th><th class="text-center">Total</th></tr>
        </thead>
        <tbody>
          <?php foreach ($d['subjects'] as $k => $sub):
              $absent = (int) $sub['is_absent'] === 1;
              $cell = fn ($v, $max) => (int) $max > 0 ? '<span class="font-semibold">' . ($v === null ? ($absent ? 'AB' : '—') : e($fmt($v))) . '</span> <span class="text-slate-400">(' . (int) $max . ')</span>' : '<span class="text-slate-300">—</span>';
              ?>
            <tr class="<?= $k % 2 ? 'bg-slate-50/60' : '' ?>">
              <td class="text-center text-slate-500"><?= $k + 1 ?></td>
              <td class="font-mono"><?= e($sub['code']) ?></td>
              <td><?= e($sub['name']) ?><?= in_array($sub['type'], ['lab', 'practical'], true) ? ' <span class="text-slate-400">(Practical)</span>' : '' ?></td>
              <td class="text-center"><?= e($fmt($sub['credits'], 1)) ?></td>
              <td class="text-center"><?= $cell($sub['internal_marks'], $sub['max_internal']) ?></td>
              <td class="text-center"><?= $cell($sub['external_marks'], $sub['max_external']) ?></td>
              <td class="text-center"><?= $cell($sub['practical_marks'], $sub['max_practical']) ?></td>
              <td class="text-center"><span class="font-bold"><?= e($fmt($sub['total_marks'])) ?></span> <span class="text-slate-400">(<?= (int) $sub['max_total'] ?>)</span></td>
              <td class="text-center font-bold text-brand-900"><?= e($sub['grade'] ?? '—') ?></td>
              <td class="text-center"><?= e($fmt($sub['grade_point'])) ?></td>
              <td class="text-center font-semibold <?= $absent ? 'text-slate-500' : ((int) $sub['is_pass'] ? 'text-emerald-700' : 'text-red-700') ?>"><?= $absent ? 'AB' : ((int) $sub['is_pass'] ? 'P' : 'F') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="relative mt-3 grid grid-cols-6 overflow-hidden rounded-lg border border-brand-900 text-center text-[10px]">
        <div class="border-r border-slate-200 p-2"><p class="text-slate-500">Marks obtained</p><p class="mt-0.5 text-[13px] font-bold text-slate-900"><?= e($fmt($r['total_marks'])) ?> / <?= e($fmt($r['max_marks'], 0)) ?></p></div>
        <div class="border-r border-slate-200 p-2"><p class="text-slate-500">Percentage</p><p class="mt-0.5 text-[13px] font-bold text-slate-900"><?= e($fmt($r['percentage'])) ?>%</p></div>
        <div class="border-r border-slate-200 p-2"><p class="text-slate-500">Credits earned</p><p class="mt-0.5 text-[13px] font-bold text-slate-900"><?= e($fmt($r['credits_earned'], 1)) ?> / <?= e($fmt($r['credits_registered'], 1)) ?></p></div>
        <div class="border-r border-slate-200 bg-brand-50 p-2"><p class="text-slate-500">SGPA</p><p class="mt-0.5 text-[15px] font-extrabold text-brand-900"><?= $isFinal ? e(number_format((float) $r['sgpa'], 2)) : '—' ?></p></div>
        <div class="border-r border-slate-200 bg-brand-50 p-2"><p class="text-slate-500">CGPA</p><p class="mt-0.5 text-[15px] font-extrabold text-brand-900"><?= $isFinal && $r['cgpa'] !== null ? e(number_format((float) $r['cgpa'], 2)) : '—' ?></p></div>
        <div class="p-2"><p class="text-slate-500">Result</p><p class="mt-0.5 text-[12px] font-extrabold <?= $statusColor ?>"><?= e($statusText) ?></p></div>
      </div>
      <p class="relative mt-1.5 text-[10px] text-slate-600">
        <?= $r['division'] ? 'Division: <strong class="text-slate-800">' . e($r['division']) . '</strong> · ' : '' ?>
        <?= (int) $r['backlog_count'] > 0 && $r['result_status'] !== 'ABSENT' ? 'Subjects to re-appear: <strong class="text-red-700">' . (int) $r['backlog_count'] . '</strong> · ' : '' ?>
        Result declared on <?= e(format_date($r['result_date'] ?: $r['published_at'])) ?>
      </p>

      <?php if ($isFinal && $d['history']): ?>
        <div class="relative mt-3">
          <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Semester-wise performance</p>
          <table class="ms-table mt-1 w-full border-collapse text-center text-[10px]">
            <thead><tr><th class="text-left">Semester</th><?php foreach ($d['history'] as $h): ?><th><?= e($h['semester_no']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
              <tr><td class="text-left font-semibold">SGPA</td><?php foreach ($d['history'] as $h): ?><td><?= $h['sgpa'] !== null ? e(number_format((float) $h['sgpa'], 2)) : '—' ?></td><?php endforeach; ?></tr>
              <tr><td class="text-left font-semibold">CGPA</td><?php foreach ($d['history'] as $h): ?><td><?= $h['cgpa'] !== null ? e(number_format((float) $h['cgpa'], 2)) : '—' ?></td><?php endforeach; ?></tr>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <div class="relative mt-3 rounded-lg bg-slate-50 px-3 py-2 text-[9px] leading-relaxed text-slate-600">
        <span class="font-semibold text-slate-700">Grading scale:</span>
        <?php foreach ($scale as $g): ?>
          <span class="whitespace-nowrap"><strong class="text-slate-800"><?= e($g['grade']) ?></strong> <?= e($fmt($g['min'])) ?>–<?= e($fmt($g['max'])) ?>% (<?= e($fmt($g['grade_point'])) ?>)</span><span class="text-slate-300"> · </span>
        <?php endforeach; ?>
        <strong class="text-slate-800">AB</strong> Absent. SGPA = Σ(credits × grade point) ÷ Σ credits; CGPA is the credit-weighted average of all semesters' SGPA.
      </div>

      <div class="relative mt-auto flex items-end justify-between gap-4 pt-6 text-[10px]">
        <div class="flex min-w-0 flex-1 items-end gap-3">
          <?php if ($m && !$revoked): ?>
            <div data-qr="<?= e($d['verify_url']) ?>" class="h-[22mm] w-[22mm] shrink-0"></div>
          <?php endif; ?>
          <div class="min-w-0 leading-snug text-slate-600">
            <?php if ($m): ?>
              <p class="whitespace-nowrap">Marksheet no. <strong class="font-mono text-slate-900"><?= e($m['marksheet_no']) ?></strong></p>
              <p>Date of issue: <strong class="text-slate-800"><?= e(format_date($m['issued_at'])) ?></strong></p>
              <?php if (!$revoked): ?>
                <p>Security code: <strong class="font-mono tracking-wider text-slate-900"><?= e(marksheet_security_code((string) $m['verification_code'])) ?></strong></p>
                <p class="max-w-[58mm] break-all text-[8px] leading-tight text-slate-500">Scan the QR code to verify online, or open <?= e(preg_replace('#^https?://#', '', absolute_url('api/marksheets/verify/' . $m['marksheet_no']))) ?> and enter the security code.</p>
              <?php else: ?>
                <p class="mt-1 max-w-[70mm] font-semibold text-red-700">Revoked on <?= e(format_date($m['revoked_at'])) ?>: <?= e($m['revoked_reason']) ?></p>
              <?php endif; ?>
            <?php else: ?>
              <p class="font-semibold text-slate-700">Provisional statement — not valid as a marksheet.</p>
              <p>Printed on <?= e(format_date(date('Y-m-d'))) ?></p>
            <?php endif; ?>
          </div>
        </div>
        <div class="flex shrink-0 gap-5 text-center text-slate-600">
          <div><div class="h-10"></div><div class="w-24 border-t border-slate-400 pt-1">Prepared by</div></div>
          <div><div class="h-10"></div><div class="w-24 border-t border-slate-400 pt-1">Checked by</div></div>
          <div><div class="h-10"></div><div class="w-36 border-t border-slate-500 pt-1 font-semibold text-slate-800"><?= e($controllerTitle) ?></div><?php if ($controller): ?><div class="text-[9px] text-slate-500"><?= e($controller) ?></div><?php endif; ?></div>
        </div>
      </div>
    </div>
  </section>
<?php endforeach; ?>
<?php print_layout_end();
