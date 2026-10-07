<?php
/**
 * Printable attendance reports (A4 landscape, institute letterhead).
 *   /admin/print/attendance-report.php?report=students|defaulters|department|subject|monthly|employees
 *     + the same filters as GET /api/attendance/reports/* : from, to, department_id, program_id, semester, section_id,
 *       subject_id, q, threshold, sort, dir  (monthly: section_id, month, subject_id; employees: month, type, department_id, q)
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/crud.php';
require_once APP_ROOT . '/app/services/attendance.php';
require_permission('attendance', 'view');

$report = in_array($_GET['report'] ?? '', ['students', 'defaulters', 'department', 'subject', 'monthly', 'employees'], true) ? $_GET['report'] : 'students';

try {
    $t = att_report_table($report, $_GET);
} catch (CrudValidationException $e) {
    print_layout_start('Attendance report', true, 'landscape');
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">Select a class to print the register</p>'
        . '<p class="mt-2 text-sm text-slate-500">' . e(implode(' ', $e->errors) ?: $e->getMessage()) . '</p></div>';
    print_layout_end();
    exit;
}

log_activity('print', 'attendance', null, 'Printed ' . strtolower($t['title']) . ' (' . count($t['rows']) . ' rows)');

$isRegister = !empty($t['register']);
$threshold = (float) ($t['threshold'] ?? att_min_percent());
$pctCols = array_flip($t['percent_cols'] ?? []);
$codeCls = ['P' => 'c-p', 'A' => 'c-a', 'L' => 'c-l', 'LV' => 'c-lv', 'HD' => 'c-hd', 'H' => 'c-h', 'WO' => 'c-off'];
$firstDayCol = $report === 'monthly' ? 2 : ($report === 'employees' ? 3 : -1);
$dayCount = $isRegister ? count($t['headers']) - $firstDayCol - ($report === 'monthly' ? 3 : 6) : 0;

print_layout_start($t['title'], true, 'landscape');
?>
<style>
  .att-table { width: 100%; border-collapse: collapse; font-size: <?= $isRegister ? '8.5px' : '10.5px' ?>; }
  .att-table th { background: #0B2A5B; color: #fff; font-weight: 600; padding: <?= $isRegister ? '3px 2px' : '5px 6px' ?>; border: 1px solid #0B2A5B; text-align: left; }
  .att-table td { border: 1px solid #e2e8f0; padding: <?= $isRegister ? '2px 2px' : '4px 6px' ?>; vertical-align: middle; }
  .att-table tbody tr:nth-child(even) td { background: #f8fafc; }
  .att-table .num { text-align: center; font-variant-numeric: tabular-nums; }
  .att-table .day { text-align: center; width: 18px; }
  .att-table .low { color: #dc2626; font-weight: 700; }
  .att-table .ok { color: #15803d; font-weight: 600; }
  .c-a { color: #dc2626; font-weight: 700; } .c-l { color: #b45309; font-weight: 700; } .c-lv { color: #0369a1; font-weight: 700; }
  .c-hd { color: #6d28d9; font-weight: 700; } .c-h { color: #e11d48; } .c-off { color: #cbd5e1; } .c-p { color: #15803d; }
  .att-summary { display: grid; grid-template-columns: repeat(<?= max(1, min(5, count($t['summary'] ?? []))) ?>, minmax(0, 1fr)); gap: 8px; }
  @media print { .att-table th { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .att-table tbody tr:nth-child(even) td { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>

<div class="mt-4 flex items-end justify-between gap-4">
  <div>
    <h1 class="font-display text-xl font-bold text-brand-900"><?= e($t['title']) ?></h1>
    <p class="mt-0.5 text-xs text-slate-600"><?= e(implode('  ·  ', $t['filters'])) ?></p>
  </div>
  <div class="text-right text-[10px] text-slate-500">
    <p>Academic session <?= e(current_session()['name'] ?? '') ?></p>
    <p>Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(current_user()['name'] ?? '') ?></p>
  </div>
</div>

<?php if (!empty($t['summary'])): ?>
  <div class="att-summary mt-3">
    <?php foreach ($t['summary'] as $label => $value): ?>
      <div class="rounded-lg border border-slate-200 px-3 py-2">
        <p class="text-[10px] uppercase tracking-wide text-slate-500"><?= e($label) ?></p>
        <p class="font-display text-base font-bold text-brand-900"><?= e((string) $value) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<table class="att-table mt-3">
  <thead>
    <tr>
      <?php foreach ($t['headers'] as $i => $h): ?>
        <th class="<?= $isRegister && $i >= $firstDayCol && $i < $firstDayCol + $dayCount ? 'day' : (is_numeric($h) || isset($pctCols[$i]) || in_array($h, ['#', 'Classes', 'Present', 'Late', 'Absent', 'Leave', 'Students', 'Held', 'Attended', 'P', 'L', 'HD', 'LV', 'A', '%'], true) ? 'num' : '') ?>"><?= e($h) ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php if (!$t['rows']): ?>
      <tr><td colspan="<?= count($t['headers']) ?>" class="py-6 text-center text-slate-500">No attendance records match the selected filters.</td></tr>
    <?php endif; ?>
    <?php foreach ($t['rows'] as $row): ?>
      <tr>
        <?php foreach ($row as $i => $v): ?>
          <?php
          $v = (string) $v;
          $cls = '';
          if ($isRegister && $i >= $firstDayCol && $i < $firstDayCol + $dayCount) {
              $cls = 'day ' . ($codeCls[$v] ?? (preg_match('#^(\d+)/(\d+)$#', $v, $m) ? ($m[1] === '0' ? 'c-a' : ($m[1] !== $m[2] ? 'c-l' : 'c-p')) : ''));
          } elseif (isset($pctCols[$i])) {
              $cls = 'num ' . ($v !== '' && (float) $v < $threshold ? 'low' : 'ok');
          } elseif (is_numeric($v)) {
              $cls = 'num';
          }
          ?>
          <td class="<?= $cls ?>"><?= e($v) ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php if (!empty($t['legend'])): ?>
  <p class="mt-2 text-[10px] text-slate-500"><?= e($t['legend']) ?></p>
<?php endif; ?>
<p class="mt-1 text-[10px] text-slate-500">
  Attendance % = (present<?= att_late_counts() ? ' + late' : '' ?><?= $report === 'employees' ? ' + ½ half days' : '' ?>) ÷ classes marked × 100. Minimum required attendance: <?= e((string) att_min_percent()) ?>%.
</p>

<div class="mt-12 grid grid-cols-3 gap-10 text-center text-xs text-slate-600">
  <div><div class="border-t border-slate-400 pt-1">Prepared by</div></div>
  <div><div class="border-t border-slate-400 pt-1"><?= $report === 'employees' ? 'Administrative Officer' : 'Head of Department' ?></div></div>
  <div><div class="border-t border-slate-400 pt-1">Principal / Director</div></div>
</div>
<?php print_layout_end();
