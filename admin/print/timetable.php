<?php
/**
 * Printable weekly timetable (A4 landscape, letterhead).
 *   /admin/print/timetable.php?view=class&id={section_id}&session_id=
 *   /admin/print/timetable.php?view=faculty&id={faculty_id}
 *   /admin/print/timetable.php?view=room&id={classroom_id}
 *   /admin/print/timetable.php?view=student&id={student_id}   (prints the student's section timetable)
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/timetable.php';
require_permission('timetable', 'view');

$view = in_array($_GET['view'] ?? 'class', ['class', 'faculty', 'room', 'student'], true) ? $_GET['view'] : 'class';
$id = input_int('id');
$sessionId = input_int('session_id') ?: (int) current_session_id();
$session = db_row('SELECT id, name FROM academic_sessions WHERE id = ?', [$sessionId]);
$grid = $id && $session ? tt_grid($view, $id, $sessionId) : null;

if (!$grid) {
    http_response_code(404);
    print_layout_start('Timetable not found', true, 'landscape');
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">Timetable not found</p>'
        . '<p class="mt-2 text-sm text-slate-500">The selected ' . e($view === 'class' ? 'section' : $view) . ' does not exist or no academic session is configured.</p></div>';
    print_layout_end();
    exit;
}

$days = tt_days();
$slots = tt_slots();
$entries = $grid['entries'];
$ctx = $grid['context'];
$isClass = in_array($grid['view'], ['class', 'student'], true);
$hasSection = !$isClass || !empty($ctx['section']);

// Matrix [day][slot] => entry (faculty/room views can only have one entry per cell; class view too)
$cells = [];
foreach ($entries as $e) {
    $cells[$e['day_of_week']][$e['time_slot_id']][] = $e;
}
// Drop empty Sunday etc. and trailing empty Saturday afternoon is fine - keep all working days.
$teachingSlots = array_values(array_filter($slots, fn ($s) => !$s['is_break']));

switch ($grid['view']) {
    case 'faculty':
        $f = $ctx['faculty'];
        $title = 'Faculty Timetable - ' . $f['name'];
        $heading = $f['name'];
        $meta = ['Employee ID' => $f['employee_id'], 'Designation' => $f['designation'], 'Department' => $f['department'] ?: '—',
            'Periods / week' => (string) count($entries), 'Classes' => (string) count(array_unique(array_column($entries, 'section_id')))];
        break;
    case 'room':
        $r = $ctx['room'];
        $title = 'Room Timetable - ' . $r['code'];
        $heading = $r['code'] . ($r['name'] && $r['name'] !== 'Room ' . $r['code'] ? ' · ' . $r['name'] : '');
        $meta = ['Type' => label_from_key($r['type']), 'Building' => trim(($r['building'] ?: '—') . ($r['floor'] ? ', Floor ' . $r['floor'] : '')), 'Capacity' => $r['capacity'] . ' seats',
            'Periods / week' => (string) count($entries), 'Utilisation' => $grid['stats']['utilisation'] . '%'];
        break;
    default:
        $s = $ctx['section'] ?? null;
        $student = $ctx['student'] ?? null;
        $title = $s ? 'Class Timetable - ' . $s['label'] : 'Student Timetable';
        $heading = $s ? $s['program'] . ' · Semester ' . $s['semester_no'] . ' · Section ' . $s['name'] : ($student['name'] ?? 'Student');
        $meta = $s ? ['Department' => $s['department'] ?: '—', 'Batch' => $s['batch'] ?: '—', 'Class teacher' => $s['class_teacher'] ?: '—',
            'Home room' => $s['room'] ?: '—', 'Strength' => $s['strength'] . ' students'] : [];
        if ($student) {
            $meta = ['Student' => $student['name'] . ' (' . $student['uid'] . ')', 'Roll no.' => $student['roll_no'] ?: '—'] + $meta;
        }
}
$status = $grid['stats']['status'];
$typeTone = ['lecture' => 'bg-blue-50 border-blue-200', 'lab' => 'bg-violet-50 border-violet-200', 'tutorial' => 'bg-cyan-50 border-cyan-200', 'seminar' => 'bg-amber-50 border-amber-200'];

/** Can entry $b continue entry $a in the next column (merged double period)? */
$same = fn (?array $a, ?array $b) => $a && $b && $a['subject_id'] === $b['subject_id'] && $a['faculty_id'] === $b['faculty_id'] && $a['classroom_id'] === $b['classroom_id'] && $a['section_id'] === $b['section_id'];

print_layout_start($title, true, 'landscape');
?>
<style>
  .tt-grid { table-layout: fixed; }
  .tt-grid td, .tt-grid th { border: 1px solid #cbd5e1; }
  .tt-break { writing-mode: vertical-rl; transform: rotate(180deg); }
  @media print { .tt-cell { break-inside: avoid; } }
</style>
<section class="mt-4 flex items-start justify-between gap-6">
  <div>
    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-accent-700"><?= e(['class' => 'Class timetable', 'student' => 'Student timetable', 'faculty' => 'Faculty timetable', 'room' => 'Room timetable'][$grid['view']]) ?> · Academic Session <?= e($session['name']) ?></p>
    <h1 class="mt-1 font-display text-xl font-extrabold leading-tight text-brand-900"><?= e($heading) ?></h1>
  </div>
  <div class="text-right">
    <?php if ($status === 'published'): ?>
      <span class="inline-block rounded-full border border-emerald-300 bg-emerald-50 px-3 py-0.5 text-[11px] font-semibold text-emerald-700">Published</span>
    <?php elseif ($status === 'empty'): ?>
      <span class="inline-block rounded-full border border-slate-300 bg-slate-50 px-3 py-0.5 text-[11px] font-semibold text-slate-600">No classes scheduled</span>
    <?php else: ?>
      <span class="inline-block rounded-full border border-amber-300 bg-amber-50 px-3 py-0.5 text-[11px] font-semibold text-amber-700">Draft - not yet published</span>
    <?php endif; ?>
    <p class="mt-1 text-[10px] text-slate-500">Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p>
  </div>
</section>

<?php if ($meta): ?>
<dl class="mt-3 grid grid-cols-5 gap-x-4 gap-y-1 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-[11px]">
  <?php foreach ($meta as $label => $value): ?>
    <div class="min-w-0"><dt class="text-[9px] font-semibold uppercase tracking-wider text-slate-500"><?= e($label) ?></dt><dd class="truncate font-semibold text-slate-800"><?= e($value) ?></dd></div>
  <?php endforeach; ?>
</dl>
<?php endif; ?>

<?php if (!$hasSection): ?>
  <div class="mt-10 rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500">This student is not assigned to a section yet, so there is no timetable to print.</div>
<?php else: ?>
<table class="tt-grid mt-4 w-full border-collapse text-[10px]">
  <thead>
    <tr class="bg-brand-900 text-white">
      <th class="w-[72px] px-2 py-1.5 text-left text-[10px] font-semibold">Day</th>
      <?php foreach ($slots as $sl): ?>
        <?php if ($sl['is_break']): ?>
          <th class="w-[22px] bg-brand-800 px-0 py-1.5"></th>
        <?php else: ?>
          <th class="px-1 py-1.5 text-center font-semibold leading-tight"><?= e($sl['name']) ?><br><span class="text-[9px] font-normal text-brand-100"><?= e(format_time($sl['start_time'])) ?> - <?= e(format_time($sl['end_time'])) ?></span></th>
        <?php endif; ?>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($days as $di => $d): ?>
      <tr>
        <th class="bg-slate-100 px-2 py-1 text-left font-display text-[11px] font-bold text-brand-900"><?= e($d['name']) ?></th>
        <?php
        $skip = 0;
        foreach ($slots as $si => $sl):
            if ($sl['is_break']) {
                if ($di === 0) {
                    echo '<td rowspan="' . count($days) . '" class="bg-slate-100 p-0 text-center align-middle"><span class="tt-break inline-block text-[9px] font-semibold uppercase tracking-[0.2em] text-slate-500">' . e($sl['name']) . '</span></td>';
                }
                continue;
            }
            if ($skip > 0) {
                $skip--;
                continue;
            }
            $list = $cells[$d['no']][$sl['id']] ?? [];
            $e = $list[0] ?? null;
            $span = 1;
            if ($e && count($list) === 1) {
                for ($k = $si + 1; isset($slots[$k]) && !$slots[$k]['is_break']; $k++) {
                    $next = $cells[$d['no']][$slots[$k]['id']] ?? [];
                    if (count($next) === 1 && $same($e, $next[0])) {
                        $span++;
                    } else {
                        break;
                    }
                }
            }
            $skip = $span - 1;
            ?>
            <td colspan="<?= $span ?>" class="tt-cell h-[58px] p-1 align-top">
              <?php foreach ($list as $e): ?>
                <div class="h-full rounded border px-1.5 py-1 <?= e($typeTone[$e['type']] ?? 'bg-slate-50 border-slate-200') ?>">
                  <p class="flex items-baseline justify-between gap-1 font-bold text-slate-900">
                    <span><?= e($e['subject_code']) ?><?= $e['type'] !== 'lecture' ? ' <span class="font-semibold text-slate-500">· ' . e(TT_TYPES[$e['type']] ?? $e['type']) . '</span>' : '' ?></span>
                    <?php if ($grid['view'] !== 'room' && $e['room_code']): ?><span class="shrink-0 text-[9px] font-semibold text-slate-500"><?= e($e['room_code']) ?></span><?php endif; ?>
                  </p>
                  <p class="truncate text-[9px] leading-tight text-slate-700"><?= e($e['subject_name']) ?></p>
                  <p class="mt-0.5 text-[9px] leading-tight text-slate-500">
                    <?php if ($grid['view'] === 'faculty'): ?>
                      <?= e($e['section_label']) ?>
                    <?php elseif ($grid['view'] === 'room'): ?>
                      <?= e($e['section_label']) ?><?= $e['faculty_name'] ? '<br>' . e($e['faculty_name']) : '' ?>
                    <?php else: ?>
                      <?= e($e['faculty_name'] ?: 'Faculty to be assigned') ?>
                    <?php endif; ?>
                  </p>
                </div>
              <?php endforeach; ?>
            </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php
// Legend / summary table
if ($isClass && !empty($grid['subjects'])):
?>
<table class="mt-4 w-full border-collapse text-[10px]">
  <thead><tr class="bg-slate-100 text-left text-slate-600">
    <th class="border border-slate-200 px-2 py-1 font-semibold">Code</th><th class="border border-slate-200 px-2 py-1 font-semibold">Subject</th>
    <th class="border border-slate-200 px-2 py-1 font-semibold">Type</th><th class="border border-slate-200 px-2 py-1 text-center font-semibold">Credits</th>
    <th class="border border-slate-200 px-2 py-1 font-semibold">Faculty</th><th class="border border-slate-200 px-2 py-1 text-center font-semibold">Periods / week</th>
  </tr></thead>
  <tbody>
  <?php foreach ($grid['subjects'] as $sub): ?>
    <tr>
      <td class="border border-slate-200 px-2 py-1 font-semibold"><?= e($sub['code']) ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= e($sub['name']) ?><?= $sub['elective'] ? ' <span class="text-slate-500">(Elective)</span>' : '' ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= e(label_from_key($sub['type'])) ?></td>
      <td class="border border-slate-200 px-2 py-1 text-center"><?= e(rtrim(rtrim(number_format($sub['credits'], 1), '0'), '.')) ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= e(implode(', ', array_column($sub['faculty'], 'name')) ?: '—') ?></td>
      <td class="border border-slate-200 px-2 py-1 text-center"><?= (int) $sub['scheduled'] ?> / <?= (int) $sub['required'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php elseif (!$isClass && $entries):
    $groups = [];
    foreach ($entries as $e) {
        $k = $e['section_id'] . '-' . $e['subject_id'];
        $groups[$k] = ($groups[$k] ?? $e + ['n' => 0]);
        $groups[$k]['n']++;
    }
?>
<table class="mt-4 w-full border-collapse text-[10px]">
  <thead><tr class="bg-slate-100 text-left text-slate-600">
    <th class="border border-slate-200 px-2 py-1 font-semibold">Class</th><th class="border border-slate-200 px-2 py-1 font-semibold">Subject</th>
    <th class="border border-slate-200 px-2 py-1 font-semibold"><?= $grid['view'] === 'faculty' ? 'Room(s)' : 'Faculty' ?></th><th class="border border-slate-200 px-2 py-1 text-center font-semibold">Periods / week</th>
  </tr></thead>
  <tbody>
  <?php foreach ($groups as $g): ?>
    <tr>
      <td class="border border-slate-200 px-2 py-1 font-semibold"><?= e($g['section_label']) ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= e($g['subject_code'] . ' · ' . $g['subject_name']) ?></td>
      <td class="border border-slate-200 px-2 py-1"><?= e($grid['view'] === 'faculty' ? ($g['room_code'] ?: '—') : ($g['faculty_name'] ?: '—')) ?></td>
      <td class="border border-slate-200 px-2 py-1 text-center"><?= (int) $g['n'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php endif; ?>

<footer class="mt-10 grid grid-cols-3 gap-10 text-center text-[10px] text-slate-600">
  <?php foreach ($grid['view'] === 'faculty' ? ['Faculty Member', 'Head of Department', 'Dean Academics'] : ($grid['view'] === 'room' ? ['Timetable Coordinator', 'Estate Officer', 'Dean Academics'] : ['Class Teacher', 'Head of Department', 'Dean Academics']) as $sig): ?>
    <div><div class="mx-auto mb-1 h-px w-40 bg-slate-400"></div><?= e($sig) ?></div>
  <?php endforeach; ?>
</footer>
<?php print_layout_end();
