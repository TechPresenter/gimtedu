<?php
/**
 * Examination date sheet (A4 landscape), grouped by date.
 *   /admin/print/exam-schedule.php?exam_id={id}
 *   /admin/print/exam-schedule.php?session_id=&program_id=&semester=&exam_type_id=&classroom_id=&from=&to=&q=
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/exams.php';
require_permission('examination', 'view');

$q = [];
foreach (['session_id', 'program_id', 'semester', 'exam_id', 'exam_type_id', 'classroom_id'] as $k) {
    if (input_int($k)) {
        $q[$k] = input_int($k);
    }
}
foreach (['from', 'to'] as $k) {
    $v = (string) input($k, '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        $q[$k] = $v;
    }
}
if (trim((string) input('q', '')) !== '') {
    $q['q'] = trim((string) input('q'));
}
if (!isset($q['session_id']) && !isset($q['exam_id'])) {
    $q['session_id'] = current_session_id();
}
$exam = isset($q['exam_id']) ? exam_find($q['exam_id']) : null;
$data = exam_datesheet($q);
$rows = $data['rows'];

// Filter description
$parts = [];
if ($exam) {
    $parts[] = $exam['name'];
} else {
    if (!empty($q['session_id'])) {
        $parts[] = 'Session ' . db_value('SELECT name FROM academic_sessions WHERE id = ?', [$q['session_id']]);
    }
    if (!empty($q['exam_type_id'])) {
        $parts[] = db_value('SELECT name FROM exam_types WHERE id = ?', [$q['exam_type_id']]);
    }
    if (!empty($q['program_id'])) {
        $parts[] = db_value('SELECT short_name FROM programs WHERE id = ?', [$q['program_id']]) . (!empty($q['semester']) ? ' · Semester ' . $q['semester'] : '');
    } elseif (!empty($q['semester'])) {
        $parts[] = 'Semester ' . $q['semester'];
    }
    if (!empty($q['classroom_id'])) {
        $parts[] = 'Room ' . db_value('SELECT code FROM classrooms WHERE id = ?', [$q['classroom_id']]);
    }
    if (!empty($q['from']) || !empty($q['to'])) {
        $parts[] = (!empty($q['from']) ? format_date($q['from']) : 'start') . ' – ' . (!empty($q['to']) ? format_date($q['to']) : 'end');
    }
}
$title = $exam ? 'Date sheet — ' . $exam['name'] : 'Examination date sheet';
log_activity('print', 'examination', $exam['id'] ?? null, 'Printed the ' . strtolower($title) . ' (' . count($rows) . ' papers)');
print_layout_start($title, true, 'landscape');

$byDate = [];
foreach ($rows as $r) {
    $byDate[$r['exam_date']][] = $r;
}
?>
<style>
  .ds-table th,.ds-table td{border:1px solid #cbd5e1;padding:4px 6px;vertical-align:top}
  .ds-table thead th{background:#0B2A5B;color:#fff;font-weight:600;border-color:#0B2A5B}
  .ds-date td{background:#EEF2F7;font-weight:700;color:#0B2A5B}
  @media print{.ds-table tr{break-inside:avoid}}
</style>
<div class="mt-4 flex items-end justify-between gap-4">
  <div>
    <h1 class="font-display text-lg font-extrabold uppercase tracking-wide text-brand-900"><?= $exam ? 'Date Sheet' : 'Examination Date Sheet' ?></h1>
    <p class="text-[12px] font-medium text-slate-700"><?= e(implode(' · ', array_filter($parts)) ?: 'All examinations') ?></p>
    <?php if ($exam && $exam['start_date']): ?><p class="text-[11px] text-slate-500"><?= e(format_date($exam['start_date'])) ?> – <?= e(format_date($exam['end_date'])) ?></p><?php endif; ?>
  </div>
  <div class="text-right text-[11px] text-slate-500">
    <p><strong class="text-slate-800"><?= count($rows) ?></strong> paper<?= count($rows) === 1 ? '' : 's' ?> · <strong class="text-slate-800"><?= count($byDate) ?></strong> day<?= count($byDate) === 1 ? '' : 's' ?></p>
    <p>Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p>
  </div>
</div>

<?php if (!$rows): ?>
  <div class="mt-10 rounded-xl border border-dashed border-slate-300 py-16 text-center">
    <p class="font-display text-base font-bold text-brand-900">No papers scheduled</p>
    <p class="mt-1 text-sm text-slate-500">No examination papers match the selected filters.</p>
  </div>
<?php else: ?>
  <table class="ds-table mt-4 w-full border-collapse text-[11px]">
    <thead>
      <tr>
        <th class="w-[13%] text-left">Time</th>
        <?php if (!$exam): ?><th class="w-[16%] text-left">Programme / Semester</th><?php endif; ?>
        <th class="text-left">Paper</th>
        <th class="w-[10%] text-left">Type</th>
        <th class="w-[12%] text-left">Room</th>
        <th class="w-[18%] text-left">Chief invigilator</th>
        <th class="w-[7%] text-center">Students</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($byDate as $date => $list): ?>
        <tr class="ds-date"><td colspan="<?= $exam ? 6 : 7 ?>"><?= e(date('l, d F Y', strtotime($date))) ?></td></tr>
        <?php foreach ($list as $r): ?>
          <tr>
            <td class="whitespace-nowrap"><?= e(exam_time_label($r['start_time'])) ?> – <?= e(exam_time_label($r['end_time'])) ?></td>
            <?php if (!$exam): ?><td><?= e(($r['program_short'] ?? 'All') . ($r['semester_no'] ? ' · Sem ' . $r['semester_no'] : '')) ?><div class="text-[9.5px] text-slate-500"><?= e($r['type_name']) ?></div></td><?php endif; ?>
            <td><span class="font-mono text-slate-500"><?= e($r['subject_code']) ?></span> <span class="font-semibold text-slate-900"><?= e($r['subject_name']) ?></span><?= $r['notes'] ? '<div class="text-[9.5px] text-slate-500">' . e($r['notes']) . '</div>' : '' ?></td>
            <td class="capitalize"><?= e($r['subject_type']) ?></td>
            <td><?= e($r['room_code'] ?? 'To be announced') ?><?= $r['room_name'] ? '<div class="text-[9.5px] text-slate-500">' . e($r['room_name']) . '</div>' : '' ?></td>
            <td><?= e($r['chief_invigilator'] ?: '—') ?><?= $r['invigilators_count'] ? '<div class="text-[9.5px] text-slate-500">+' . (int) $r['invigilators_count'] . ' invigilator' . ((int) $r['invigilators_count'] === 1 ? '' : 's') . '</div>' : '' ?></td>
            <td class="text-center"><?= (int) $r['students_count'] ?: '—' ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<div class="mt-6 flex items-end justify-between text-[11px] text-slate-600">
  <ul class="max-w-xl list-disc space-y-0.5 pl-4 text-[10px] text-slate-500">
    <li>Candidates must carry the admit card and college ID card to every paper.</li>
    <li>Entry is not permitted 30 minutes after the start of the paper.</li>
    <li>Any change to the date sheet will be notified on the notice board and the student portal.</li>
  </ul>
  <div class="text-center"><div class="h-10"></div><div class="w-48 border-t border-slate-500 pt-1 font-semibold text-slate-800"><?= e(setting('exam_controller_designation', 'Controller of Examinations')) ?></div></div>
</div>
<?php print_layout_end();
