<?php
/**
 * Admit cards / hall tickets (two per A4 page) for eligible students of an exam.
 *   /admin/print/admit-card.php?exam_id={id}                       every eligible student
 *   /admin/print/admit-card.php?exam_id={id}&student_id={id}       one student
 *   /admin/print/admit-card.php?exam_id={id}&ids=1,2,3             selected students
 *   /admin/print/admit-card.php?exam_id={id}&section_id={id}       one section
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/exams.php';
require_permission('examination', 'view');

$e = exam_find(input_int('exam_id'));
$only = null;
if (input_int('student_id')) {
    $only = [input_int('student_id')];
} elseif (trim((string) input('ids', '')) !== '') {
    $only = array_values(array_filter(array_map('intval', explode(',', (string) input('ids')))));
}
$data = $e ? exam_admit_cards($e, $only) : ['students' => [], 'papers' => []];
if ($e && input_int('section_id')) {
    $sec = input_int('section_id');
    $secIds = array_map('intval', db_column('SELECT id FROM students WHERE section_id = ?', [$sec]));
    $data['students'] = array_values(array_filter($data['students'], fn ($s) => in_array((int) $s['student_id'], $secIds, true)));
}

if (!$e || !$data['students'] || !$data['papers']) {
    http_response_code($e ? 200 : 404);
    print_layout_start($e ? 'Admit cards not available' : 'Exam not found');
    $msg = !$e ? 'The exam does not exist or has been deleted.'
        : (!$data['papers'] ? 'Schedule the papers of this exam before printing admit cards.'
            : ($only ? 'The selected student is not an eligible candidate of this exam. Allocate the student or override the eligibility first.' : 'Allocate eligible students to the exam before printing admit cards.'));
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">' . ($e ? 'Admit cards not available' : 'Exam not found') . '</p>'
        . '<p class="mx-auto mt-2 max-w-md text-sm text-slate-500">' . e($msg) . '</p>'
        . '<a href="' . e(admin_url($e ? 'examination/' . $e['id'] . '?tab=students' : 'examination')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to exam</a></div>';
    print_layout_end();
    exit;
}

log_activity('print', 'examination', $e['id'], 'Printed ' . count($data['students']) . ' admit card' . (count($data['students']) === 1 ? '' : 's') . ' for "' . $e['name'] . '"');
$controllerTitle = (string) setting('exam_controller_designation', 'Controller of Examinations');
$instructions = array_values(array_filter(array_map('trim', preg_split('/\r?\n|(?<=\.)\s+(?=[A-Z])/', (string) ($e['instructions'] ?: 'Report 30 minutes before the paper with this admit card and your college ID card. Mobile phones and electronic gadgets are not allowed in the examination hall.')))));
$sheetClass = 'print-sheet bg-white mx-auto my-6 shadow-xl w-[210mm] min-h-[297mm] p-[12mm]';

print_layout_start('Admit cards — ' . $e['name'], false);
?>
<style>
  .ac-card{border:1.5px solid #0B2A5B;border-radius:10px;padding:5mm;height:132mm;display:flex;flex-direction:column;position:relative;overflow:hidden}
  .ac-card .ac-band{background:#0B2A5B;color:#fff}
  .ac-table th,.ac-table td{border:1px solid #cbd5e1;padding:2.5px 4px}
  .ac-table thead th{background:#EEF2F7;color:#0B2A5B;font-weight:700}
  @media print{.ac-pair{break-after:page}.ac-pair:last-child{break-after:auto}}
</style>
<?php foreach (array_chunk($data['students'], 2) as $pi => $pair):
    if ($pi > 0) {
        echo '</div><div class="' . $sheetClass . '">';
    }
    ?>
  <div class="ac-pair space-y-[7mm]">
    <?php foreach ($pair as $s):
        $name = trim(implode(' ', array_filter([$s['first_name'], $s['middle_name'], $s['last_name']])));
        $photo = !empty($s['photo']) ? upload_url($s['photo']) : '';
        ?>
      <section class="ac-card">
        <header class="flex items-center gap-3 border-b border-slate-200 pb-2">
          <img src="<?= e(logo_url()) ?>" alt="GIMT" class="h-11 w-auto">
          <div class="flex-1">
            <p class="font-display text-[13px] font-extrabold uppercase leading-tight text-brand-900"><?= e(institute_name()) ?></p>
            <p class="text-[9.5px] text-slate-500"><?= e($e['type_name']) ?> · <?= e(date('F Y', strtotime($e['start_date'] ?: 'now'))) ?> · Session <?= e($e['session_name']) ?></p>
          </div>
          <span class="ac-band rounded-md px-3 py-1 text-[11px] font-bold uppercase tracking-widest">Admit Card</span>
        </header>
        <div class="mt-2.5 flex gap-3">
          <table class="w-full text-[10px]">
            <tbody>
              <tr><td class="w-[22%] py-[1px] text-slate-500">Candidate</td><td class="py-[1px] font-bold uppercase text-slate-900" colspan="3"><?= e($name) ?></td></tr>
              <tr><td class="py-[1px] text-slate-500">Father's name</td><td class="py-[1px] text-slate-800" colspan="3"><?= e($s['father_name'] ?: '—') ?></td></tr>
              <tr><td class="py-[1px] text-slate-500">Programme</td><td class="py-[1px] font-medium text-slate-800"><?= e($e['program_short'] ?? '') ?> · Sem <?= e($e['semester_no']) ?><?= $s['section_name'] ? ' · Sec ' . e($s['section_name']) : '' ?></td>
                  <td class="w-[18%] py-[1px] text-slate-500">Roll no.</td><td class="py-[1px] font-mono font-semibold text-slate-900"><?= e($s['roll_no'] ?: '—') ?></td></tr>
              <tr><td class="py-[1px] text-slate-500">Hall ticket</td><td class="py-[1px] font-mono font-bold text-brand-900"><?= e($s['hall_ticket_no'] ?: '—') ?></td>
                  <td class="py-[1px] text-slate-500">Student ID</td><td class="py-[1px] font-mono text-slate-800"><?= e($s['student_uid']) ?></td></tr>
              <tr><td class="py-[1px] text-slate-500">Seat / room</td><td class="py-[1px] font-semibold text-slate-900"><?= e($s['seat_no'] ?: 'Displayed on the notice board') ?><?= $s['room_name'] ? ' · ' . e($s['room_name']) : '' ?></td>
                  <td class="py-[1px] text-slate-500">Enrollment</td><td class="py-[1px] font-mono text-slate-800"><?= e($s['enrollment_no'] ?: '—') ?></td></tr>
            </tbody>
          </table>
          <div class="flex shrink-0 flex-col items-center gap-1">
            <div class="flex h-[27mm] w-[22mm] items-center justify-center overflow-hidden rounded border border-slate-300 bg-slate-50">
              <?php if ($photo): ?><img src="<?= e($photo) ?>" alt="" class="h-full w-full object-cover"><?php else: ?><span class="font-display text-lg font-bold text-slate-400"><?= e(initials($name)) ?></span><?php endif; ?>
            </div>
          </div>
          <div data-qr="<?= e('GIMT ADMIT CARD|' . $s['hall_ticket_no'] . '|' . $s['student_uid'] . '|EXAM ' . $e['id']) ?>" class="h-[19mm] w-[19mm] shrink-0"></div>
        </div>
        <table class="ac-table mt-2 w-full border-collapse text-[9.5px]">
          <thead><tr><th class="text-left">Date</th><th class="text-left">Time</th><th class="text-left">Paper</th><th class="text-left">Room</th><th class="w-[24mm] text-center">Invigilator's sign</th></tr></thead>
          <tbody>
            <?php foreach ($data['papers'] as $pp): ?>
              <tr>
                <td class="whitespace-nowrap"><?= e(date('d M Y, D', strtotime($pp['exam_date']))) ?></td>
                <td class="whitespace-nowrap"><?= e(exam_time_label($pp['start_time']) . ' – ' . exam_time_label($pp['end_time'])) ?></td>
                <td><span class="font-mono text-slate-500"><?= e($pp['code']) ?></span> <?= e($pp['name']) ?></td>
                <td class="whitespace-nowrap"><?= e($pp['room_code'] ?? '—') ?></td>
                <td></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <ul class="mt-2 list-disc space-y-0.5 pl-4 text-[8.5px] leading-snug text-slate-600">
          <?php foreach (array_slice($instructions, 0, 3) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
        </ul>
        <div class="mt-auto flex items-end justify-between pt-3 text-[9.5px] text-slate-600">
          <div class="w-36 border-t border-slate-400 pt-1 text-center">Candidate's signature</div>
          <p class="text-[8.5px] text-slate-400">Issued <?= e(format_date(date('Y-m-d'))) ?> · not transferable</p>
          <div class="w-40 border-t border-slate-500 pt-1 text-center font-semibold text-slate-800"><?= e($controllerTitle) ?></div>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php print_layout_end();
