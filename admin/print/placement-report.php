<?php
/**
 * Printable placement report for an academic session (A4 portrait, institute letterhead).
 *   /admin/print/placement-report.php?session_id={id}   (default: current session)
 * Sections: summary KPIs, department-wise & program-wise placement, company-wise hiring, package distribution,
 * drives conducted and the list of placed students (best offer per student).
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/placement.php';
require_permission('placement', 'view');

$sessionId = input_int('session_id') ?: null;
$r = placement_report($sessionId);
$k = $r['kpis'];
$ses = $r['session'];
$n2 = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
$lpa = fn ($v) => $v === null ? '—' : $n2($v) . ' LPA';
$maxBand = max(1, ...array_map(fn ($b) => $b['count'], $r['distribution']));
$maxCompany = max(1, ...array_map(fn ($c) => $c['offers'], $r['companies'] ?: [['offers' => 1]]));

log_activity('print', 'placement', null, 'Printed the placement report for session ' . $ses['name']);
print_layout_start('Placement Report ' . $ses['name']);
?>
<style>
  .pr-table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
  .pr-table th { background: #0B2A5B; color: #fff; font-weight: 600; padding: 5px 6px; border: 1px solid #0B2A5B; text-align: left; }
  .pr-table td { border: 1px solid #e2e8f0; padding: 4px 6px; vertical-align: middle; }
  .pr-table tbody tr:nth-child(even) td { background: #f8fafc; }
  .pr-table .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .pr-bar { height: 7px; border-radius: 4px; background: #22943F; }
  .pr-bar-track { background: #e2e8f0; border-radius: 4px; height: 7px; width: 100%; }
  .pr-h2 { font-size: 13px; font-weight: 700; color: #0B2A5B; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #cbd5e1; }
  .pr-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
  .pr-kpi { border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 10px; }
  .pr-kpi p:first-child { font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
  .pr-kpi p:nth-child(2) { font-size: 17px; font-weight: 800; color: #0B2A5B; line-height: 1.25; }
  .pr-kpi p:nth-child(3) { font-size: 9.5px; color: #64748b; }
  .pr-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .pr-avoid { break-inside: avoid; page-break-inside: avoid; }
  @media print { .pr-table th, .pr-bar, .pr-table tbody tr:nth-child(even) td { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>

<div class="mt-4 flex items-end justify-between gap-4">
  <div>
    <h1 class="font-display text-xl font-bold text-brand-900">Training &amp; Placement Report</h1>
    <p class="mt-0.5 text-xs text-slate-600">Academic session <?= e($ses['name']) ?> (<?= e(format_date($ses['start_date'])) ?> – <?= e(format_date($ses['end_date'])) ?>)</p>
  </div>
  <div class="text-right text-[10px] text-slate-500">
    <p>Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p>
    <p>by <?= e(current_user()['name'] ?? '') ?></p>
  </div>
</div>

<div class="pr-kpis mt-3">
  <div class="pr-kpi"><p>Companies visited</p><p><?= e((string) $k['companies_visited']) ?></p><p><?= e((string) $k['companies']) ?> active recruiters · <?= e((string) $k['mou']) ?> MoUs</p></div>
  <div class="pr-kpi"><p>Placement drives</p><p><?= e((string) $k['drives']) ?></p><p><?= e((string) $k['drives_completed']) ?> completed · <?= e((string) $k['drives_upcoming']) ?> upcoming / ongoing</p></div>
  <div class="pr-kpi"><p>Eligible students</p><p><?= e((string) $k['eligible']) ?></p><p>Met at least one drive's criteria</p></div>
  <div class="pr-kpi"><p>Placed students</p><p><?= e((string) $k['placed']) ?> <span style="font-size:11px;font-weight:600;color:#22943F">(<?= e($n2($k['placement_percent'])) ?>%)</span></p><p><?= e((string) $k['confirmed']) ?> confirmed · <?= e((string) $k['offers']) ?> offers</p></div>
  <div class="pr-kpi"><p>Highest package</p><p><?= e($lpa($k['highest'])) ?></p><p><?= e(trim(($k['highest_company'] ?? '') . ($k['highest_student'] ? ' · ' . $k['highest_student'] : ''), ' ·')) ?: '—' ?></p></div>
  <div class="pr-kpi"><p>Average package</p><p><?= e($lpa($k['average'])) ?></p><p>Median <?= e($lpa($k['median'])) ?></p></div>
</div>

<div class="pr-avoid">
  <h2 class="pr-h2">Department-wise placement</h2>
  <table class="pr-table">
    <thead><tr><th>Department</th><th class="num">Eligible</th><th class="num">Placed</th><th style="width:28%">Placement %</th><th class="num">Avg CTC</th><th class="num">Highest</th></tr></thead>
    <tbody>
      <?php if (!$r['departments']): ?>
        <tr><td colspan="6" class="py-4 text-center text-slate-500">No eligible students in this session.</td></tr>
      <?php endif; ?>
      <?php foreach ($r['departments'] as $d): ?>
        <tr>
          <td><?= e($d['name']) ?></td>
          <td class="num"><?= e((string) $d['eligible']) ?></td>
          <td class="num"><strong><?= e((string) $d['placed']) ?></strong></td>
          <td><div style="display:flex;align-items:center;gap:6px"><div class="pr-bar-track"><div class="pr-bar" style="width:<?= e((string) min(100, $d['percent'])) ?>%"></div></div><span class="num" style="min-width:38px"><?= e($n2($d['percent'])) ?>%</span></div></td>
          <td class="num"><?= e($lpa($d['average'])) ?></td>
          <td class="num"><?= e($lpa($d['highest'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="pr-grid2">
  <div class="pr-avoid">
    <h2 class="pr-h2">Program-wise placement</h2>
    <table class="pr-table">
      <thead><tr><th>Program</th><th class="num">Placed</th><th class="num">Offers</th><th class="num">Avg</th><th class="num">Highest</th></tr></thead>
      <tbody>
        <?php if (!$r['programs']): ?><tr><td colspan="5" class="py-4 text-center text-slate-500">No offers yet.</td></tr><?php endif; ?>
        <?php foreach ($r['programs'] as $p): ?>
          <tr><td><?= e($p['label']) ?></td><td class="num"><strong><?= e((string) $p['placed']) ?></strong></td><td class="num"><?= e((string) $p['offers']) ?></td><td class="num"><?= e($n2($p['average'])) ?></td><td class="num"><?= e($n2($p['highest'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="pr-avoid">
    <h2 class="pr-h2">Package distribution</h2>
    <table class="pr-table">
      <thead><tr><th>CTC band</th><th style="width:55%">Offers</th><th class="num">Count</th></tr></thead>
      <tbody>
        <?php foreach ($r['distribution'] as $b): ?>
          <tr><td><?= e($b['label']) ?></td><td><div class="pr-bar-track"><div class="pr-bar" style="background:#1D4ED8;width:<?= e((string) round($b['count'] / $maxBand * 100)) ?>%"></div></div></td><td class="num"><?= e((string) $b['count']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="pr-avoid">
  <h2 class="pr-h2">Company-wise hiring (top recruiters)</h2>
  <table class="pr-table">
    <thead><tr><th style="width:30px">#</th><th>Company</th><th style="width:30%">Offers</th><th class="num">Students</th><th class="num">Average CTC</th><th class="num">Highest CTC</th></tr></thead>
    <tbody>
      <?php if (!$r['companies']): ?><tr><td colspan="6" class="py-4 text-center text-slate-500">No hiring recorded in this session.</td></tr><?php endif; ?>
      <?php foreach ($r['companies'] as $i => $c): ?>
        <tr>
          <td class="num"><?= $i + 1 ?></td>
          <td><?= e($c['label']) ?></td>
          <td><div style="display:flex;align-items:center;gap:6px"><div class="pr-bar-track"><div class="pr-bar" style="background:#0B2A5B;width:<?= e((string) round($c['offers'] / $maxCompany * 100)) ?>%"></div></div><span class="num" style="min-width:22px"><?= e((string) $c['offers']) ?></span></div></td>
          <td class="num"><?= e((string) $c['students']) ?></td>
          <td class="num"><?= e($lpa($c['average'])) ?></td>
          <td class="num"><?= e($lpa($c['highest'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h2 class="pr-h2">Drives conducted (<?= count($r['drives']) ?>)</h2>
<table class="pr-table">
  <thead><tr><th>Date</th><th>Company</th><th>Role(s)</th><th>Type</th><th class="num">CTC</th><th class="num">Applied</th><th class="num">Selected</th><th class="num">Offers</th><th>Status</th></tr></thead>
  <tbody>
    <?php if (!$r['drives']): ?><tr><td colspan="9" class="py-4 text-center text-slate-500">No drives in this session.</td></tr><?php endif; ?>
    <?php foreach ($r['drives'] as $d): ?>
      <?php $ctc = $d['package_min'] === null && $d['package_max'] === null ? '—' : ($d['package_min'] !== null && $d['package_max'] !== null && (float) $d['package_min'] !== (float) $d['package_max'] ? $n2($d['package_min']) . '–' . $n2($d['package_max']) : $n2($d['package_max'] ?? $d['package_min'])); ?>
      <tr>
        <td style="white-space:nowrap"><?= e($d['drive_date'] ? date('d M Y', strtotime($d['drive_date'])) : 'TBA') ?></td>
        <td><?= e($d['company_name']) ?></td>
        <td><?= e($d['job_role']) ?></td>
        <td><?= e(placement_job_types()[$d['job_type']] ?? $d['job_type']) ?></td>
        <td class="num"><?= e($ctc) ?></td>
        <td class="num"><?= e((string) $d['applicants']) ?></td>
        <td class="num"><?= e((string) $d['selected']) ?></td>
        <td class="num"><?= e((string) $d['offers']) ?></td>
        <td><?= e(placement_drive_statuses()[$d['status']] ?? $d['status']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<h2 class="pr-h2">Placed students (<?= count($r['placed_students']) ?>)</h2>
<table class="pr-table">
  <thead><tr><th style="width:30px">#</th><th>Student</th><th>Student ID</th><th>Program</th><th>Company</th><th>Role</th><th class="num">CTC (LPA)</th><th class="num">Offers</th><th>Status</th></tr></thead>
  <tbody>
    <?php if (!$r['placed_students']): ?><tr><td colspan="9" class="py-4 text-center text-slate-500">No students placed in this session yet.</td></tr><?php endif; ?>
    <?php foreach ($r['placed_students'] as $i => $p): ?>
      <tr>
        <td class="num"><?= $i + 1 ?></td>
        <td style="white-space:nowrap"><?= e($p['name']) ?></td>
        <td style="white-space:nowrap"><?= e($p['student_uid']) ?></td>
        <td><?= e($p['program_short']) ?></td>
        <td><?= e($p['company_name']) ?></td>
        <td><?= e($p['job_role']) ?></td>
        <td class="num"><strong><?= e($n2($p['package_lpa'])) ?></strong></td>
        <td class="num"><?= e((string) $p['offers']) ?></td>
        <td><?= e(label_from_key($p['status'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<p class="mt-2 text-[10px] text-slate-500">
  Placed = students holding at least one offer that is offered, accepted or joined, dated within the session. Placement % = placed ÷ (students eligible for at least one drive of the session ∪ placed students).
  Eligibility is computed from the eligible programs, passing-out batch, latest published CGPA, active backlogs and previous qualification %.
</p>

<div class="mt-12 grid grid-cols-3 gap-10 text-center text-xs text-slate-600">
  <div><div class="border-t border-slate-400 pt-1">Training &amp; Placement Officer</div></div>
  <div><div class="border-t border-slate-400 pt-1">Dean — Academics</div></div>
  <div><div class="border-t border-slate-400 pt-1">Director</div></div>
</div>
<?php print_layout_end();
