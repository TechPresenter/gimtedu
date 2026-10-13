<?php
/**
 * Printable / PDF version of any Reports & Analytics report (A4, letterhead, landscape when the table is wide).
 *   /admin/print/report.php?report={key}&{same filters as GET /api/reports/{key}}&sort&dir[&autoprint=1][&output=pdf]  (not 'mode': that is a report filter)
 * Needs reports.export + view on the report's module. Rows are capped at REPORTS_PRINT_LIMIT (CSV / Excel carry more).
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/reports.php';
require_login();
require_permission('reports', 'export');

$key = (string) ($_GET['report'] ?? '');
$cat = reports_catalog()[$key] ?? null;
if (!$cat) {
    http_response_code(404);
    print_layout_start('Report not found', true);
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">This report does not exist</p>'
        . '<p class="mt-2 text-sm text-slate-500">Open Reports &amp; Analytics and choose a report from the catalogue.</p>'
        . '<p class="mt-6 no-print"><a class="btn btn-primary btn-sm" href="' . e(admin_url('reports')) . '">Go to Reports</a></p></div>';
    print_layout_end();
    exit;
}
require_permission($cat['module'], 'view');

$def = reports_def($key);
$f = reports_filters($def, $_GET);
$run = reports_run($def, $f, null, 0, isset($_GET['sort']) ? (string) $_GET['sort'] : null, isset($_GET['dir']) ? (string) $_GET['dir'] : null, true, REPORTS_PRINT_LIMIT);
$applied = reports_applied($def, $f);
$isPdf = ($_GET['output'] ?? '') === 'pdf';
log_activity($isPdf ? 'export' : 'print', 'reports', null, sprintf('%s %s report (%s, %d rows%s)%s', $isPdf ? 'Exported' : 'Printed', $def['title'], $isPdf ? 'PDF' : 'print',
    count($run['rows']), $run['truncated'] ? ' of ' . $run['total'] : '', $applied ? ' — ' . implode('; ', $applied) : ''));

[$headers, $data, $numeric] = reports_export_table($def, $run['rows'], 'print');
$landscape = count($headers) > 6;
$docTitle = $isPdf ? str_replace('_', '-', $key) . '-report-' . date('Y-m-d') : $def['title'] . ' report';

function report_print_kpi(array $k): string
{
    $v = $k['value'];
    if ($v === null || $v === '') {
        return '—';
    }
    return match ($k['format']) {
        'money' => money((float) $v),
        'percent' => rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%',
        'decimal' => number_in((float) $v, 2),
        'lpa' => number_in((float) $v, 2) . ' LPA',
        'hours' => number_in((float) $v, 1) . ' h',
        'number' => number_in((float) $v),
        default => (string) $v,
    };
}

function report_print_value(float $v, string $format): string
{
    return match ($format) {
        'money' => money($v),
        'percent' => rtrim(rtrim(number_format($v, 1), '0'), '.') . '%',
        default => number_in($v, floor($v) == $v ? 0 : 1),
    };
}

$chart = $run['chart'];
$palette = ['#0B2A5B', '#22943F', '#F97316', '#8B5CF6', '#06B6D4'];
$chartRows = [];
$chartMax = 0.0;
if ($chart && $chart['labels'] && $chart['series']) {
    $labels = $chart['labels'];
    // Long time series: keep the most recent 24 points so the printed chart stays readable.
    $offset = max(0, count($labels) - 24);
    foreach (array_slice($labels, $offset, null, true) as $i => $label) {
        $vals = array_map(fn ($s) => (float) ($s['data'][$i] ?? 0), $chart['series']);
        $chartRows[] = ['label' => $label, 'values' => $vals];
        $chartMax = max($chartMax, $chart['type'] === 'stacked' ? array_sum($vals) : max($vals));
    }
    if ($chart['format'] === 'percent' && $chartMax > 0) {
        $chartMax = max($chartMax, 100.0); // percentages drawn against 100%
    }
}

print_layout_start($docTitle, true, $landscape ? 'landscape' : 'portrait');
?>
<style>
  .rp-meta { font-size: 10px; color: #475569; }
  .rp-kpis { display: grid; grid-template-columns: repeat(<?= max(1, min(5, count($run['kpis']))) ?>, minmax(0, 1fr)); gap: 8px; margin-top: 14px; }
  .rp-kpi { border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; }
  .rp-kpi b { display: block; font-size: 15px; color: #0B2A5B; font-variant-numeric: tabular-nums; }
  .rp-kpi span { font-size: 9.5px; color: #64748b; }
  .rp-kpi small { display: block; font-size: 9px; color: #94a3b8; margin-top: 1px; }
  .rp-chart { margin-top: 14px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; break-inside: avoid; }
  .rp-chart h2 { font-size: 11px; font-weight: 700; color: #0B2A5B; margin-bottom: 6px; }
  .rp-bar-row { display: grid; grid-template-columns: 150px 1fr 90px; gap: 8px; align-items: center; font-size: 9.5px; padding: 1.5px 0; }
  .rp-bar-row .lbl { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; color: #334155; }
  .rp-bar-track { display: flex; height: 9px; background: #f1f5f9; border-radius: 3px; overflow: hidden; }
  .rp-bar-group { display: flex; flex-direction: column; gap: 2px; }
  .rp-bar-group .rp-bar-track { height: 6px; }
  .rp-bar-row .val { text-align: right; font-variant-numeric: tabular-nums; color: #0f172a; }
  .rp-legend { display: flex; gap: 12px; font-size: 9px; color: #475569; margin-bottom: 6px; }
  .rp-legend i { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 4px; vertical-align: -1px; }
  .rp-table { width: 100%; border-collapse: collapse; font-size: <?= count($headers) > 9 ? '9px' : '10px' ?>; margin-top: 14px; }
  .rp-table th { background: #0B2A5B; color: #fff; font-weight: 600; padding: 5px 6px; border: 1px solid #0B2A5B; text-align: left; vertical-align: bottom; }
  .rp-table td { border: 1px solid #e2e8f0; padding: 4px 6px; vertical-align: top; white-space: pre-line; }
  .rp-table tbody tr:nth-child(even) td { background: #f8fafc; }
  .rp-table .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .rp-table thead { display: table-header-group; }
  .rp-table tr { break-inside: avoid; }
  @media print {
    .rp-table th, .rp-table tbody tr:nth-child(even) td, .rp-bar-track span, .rp-legend i { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>

<div class="mt-4 flex items-end justify-between gap-4">
  <div class="min-w-0">
    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500"><?= e($def['area_label']) ?> report</p>
    <h1 class="font-display text-xl font-bold text-brand-900"><?= e($def['title']) ?></h1>
    <p class="rp-meta mt-0.5"><?= e($applied ? implode('  ·  ', $applied) : 'All records') ?></p>
  </div>
  <div class="shrink-0 text-right text-[10px] text-slate-500">
    <p>Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p>
    <p>by <?= e(current_user()['name'] ?? '') ?></p>
    <p><?= e(number_in($run['total'])) ?> rows<?= $run['truncated'] ? ' · first ' . e(number_in(count($run['rows']))) . ' printed' : '' ?></p>
  </div>
</div>

<?php if ($run['kpis']): ?>
  <div class="rp-kpis">
    <?php foreach ($run['kpis'] as $k): ?>
      <div class="rp-kpi"><span><?= e($k['label']) ?></span><b><?= e(report_print_kpi($k)) ?></b><?php if (!empty($k['hint'])): ?><small><?= e($k['hint']) ?></small><?php endif; ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($chartRows && $chartMax > 0): ?>
  <div class="rp-chart">
    <h2><?= e($chart['title']) ?></h2>
    <?php if (count($chart['series']) > 1): ?>
      <div class="rp-legend"><?php foreach ($chart['series'] as $i => $s): ?><span><i style="background: <?= e($s['color'] ?? $palette[$i % count($palette)]) ?>"></i><?= e($s['label']) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <?php foreach ($chartRows as $row): ?>
      <div class="rp-bar-row">
        <span class="lbl" title="<?= e($row['label']) ?>"><?= e($row['label']) ?></span>
        <?php if ($chart['type'] === 'stacked' || count($row['values']) === 1): ?>
          <span class="rp-bar-track">
            <?php foreach ($row['values'] as $i => $v): ?><span style="width: <?= round(max(0, $v) / $chartMax * 100, 2) ?>%; background: <?= e($chart['series'][$i]['color'] ?? $palette[$i % count($palette)]) ?>"></span><?php endforeach; ?>
          </span>
          <span class="val"><?= e(report_print_value(array_sum($row['values']), $chart['format'])) ?></span>
        <?php else: ?>
          <span class="rp-bar-group">
            <?php foreach ($row['values'] as $i => $v): ?><span class="rp-bar-track"><span style="width: <?= round(max(0, $v) / $chartMax * 100, 2) ?>%; background: <?= e($chart['series'][$i]['color'] ?? $palette[$i % count($palette)]) ?>"></span></span><?php endforeach; ?>
          </span>
          <span class="val"><?= e(implode(' / ', array_map(fn ($v) => report_print_value($v, $chart['format']), $row['values']))) ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<table class="rp-table">
  <thead>
    <tr><?php foreach ($headers as $i => $h): ?><th class="<?= $numeric[$i] ? 'num' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr>
  </thead>
  <tbody>
    <?php foreach ($data as $line): ?>
      <tr><?php foreach ($line as $i => $cell): ?><td class="<?= $numeric[$i] ? 'num' : '' ?>"><?= e($cell) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if (!$data): ?>
      <tr><td colspan="<?= count($headers) ?>" style="text-align:center;padding:28px 6px;color:#64748b">No records match the selected filters.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php if ($run['truncated']): ?>
  <p class="rp-meta mt-2">Showing the first <?= e(number_in(count($run['rows']))) ?> of <?= e(number_in($run['total'])) ?> rows. Narrow the filters or export to Excel / CSV for the complete list.</p>
<?php endif; ?>
<?php if (!empty($def['note'])): ?>
  <p class="rp-meta mt-2"><?= e($def['note']) ?></p>
<?php endif; ?>
<p class="rp-meta mt-4 border-t border-slate-200 pt-2">Computer-generated report from <?= e(institute_name()) ?> SmartCampus. Figures reflect the records at the time of generation.</p>
<?php
print_layout_end(!empty($_GET['autoprint']));
