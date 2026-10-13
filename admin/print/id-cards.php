<?php
/**
 * Student ID card print sheet (A4 portrait, CR80 cards 85.6 × 54 mm, 8 card faces per page, crop marks).
 *   /admin/print/id-cards.php?ids=1,2,3[&side=both|front|back]
 *   /admin/print/id-cards.php?program_id=1&semester=3&section_id=4&batch_id=2&status=active[&side=...]
 * side=both prints front|back pairs (4 students per page); front/back print 8 per page, backs mirrored per row for duplex.
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/students.php';
require_login();
require_permission('students', 'view');

$side = in_array($_GET['side'] ?? 'both', ['both', 'front', 'back'], true) ? ($_GET['side'] ?? 'both') : 'both';
$res = students_card_rows($_GET, 400);
$rows = $res['rows'];
$institute = institute_name();
$phone = (string) setting('phone', '+91 9955446477');
$email = (string) setting('email', 'info@gimt.ac.in');
$addressInst = (string) setting('address', '');
$logoWhite = logo_url(true);

/** Corner crop marks around a card (lines outside the card edges). */
function idc_marks(): string
{
    $m = '';
    foreach (['tl' => 'top:0;left:-4.5mm;width:3.5mm', 'tr' => 'top:0;right:-4.5mm;width:3.5mm', 'bl' => 'bottom:0;left:-4.5mm;width:3.5mm', 'br' => 'bottom:0;right:-4.5mm;width:3.5mm'] as $style) {
        $m .= '<i class="idc-mark idc-h" style="' . $style . '"></i>';
    }
    foreach (['top:-4.5mm;left:0;height:3.5mm', 'top:-4.5mm;right:0;height:3.5mm', 'bottom:-4.5mm;left:0;height:3.5mm', 'bottom:-4.5mm;right:0;height:3.5mm'] as $style) {
        $m .= '<i class="idc-mark idc-v" style="' . $style . '"></i>';
    }
    return $m;
}

function idc_front(array $s, string $institute, string $logoWhite): string
{
    $course = $s['course_name'] ? preg_replace('/^.* - /', '', $s['course_name']) : '';
    $photo = $s['photo']
        ? '<img src="' . e(upload_url($s['photo'])) . '" alt="">'
        : '<span class="idc-initials">' . e(initials($s['full_name'])) . '</span>';
    return '<div class="idc-card idc-front">' . idc_marks()
        . '<div class="idc-band"><img src="' . e($logoWhite) . '" alt="" class="idc-logo"><div class="idc-inst"><b>' . e($institute) . '</b><span>Student Identity Card</span></div></div>'
        . '<div class="idc-stripe"></div>'
        . '<div class="idc-body"><div class="idc-photo">' . $photo . '</div><div class="idc-info">'
        . '<p class="idc-name">' . e($s['full_name']) . '</p><p class="idc-uid">' . e($s['student_uid']) . '</p>'
        . '<table class="idc-dl"><tr><th>Program</th><td>' . e($s['program_name'] . ($course ? ' · ' . $course : '')) . '</td></tr>'
        . '<tr><th>Batch</th><td>' . e($s['batch_name'] ?? '—') . '</td></tr>'
        . '<tr><th>Roll No</th><td>' . e($s['roll_no'] ?? '—') . '</td></tr>'
        . '<tr><th>Blood</th><td class="idc-blood">' . e($s['blood_group'] ?? '—') . '</td></tr></table></div></div>'
        . '<div class="idc-foot"><span>Emergency: <b>' . e($s['emergency_contact_phone'] ?: ($s['guardian_phone'] ?: '—')) . '</b></span><span>Valid till <b>' . e(format_date($s['valid_until'])) . '</b></span></div>'
        . '</div>';
}

function idc_back(array $s, string $institute, string $addressInst, string $phone, string $email): string
{
    $address = implode(', ', array_filter([$s['address'], $s['city'], $s['state'], $s['pincode']]));
    return '<div class="idc-card idc-back">' . idc_marks()
        . '<div class="idc-topline"></div>'
        . '<div class="idc-backbody"><div class="idc-backtext">'
        . '<p class="idc-label">Student address</p><p class="idc-val idc-clamp3">' . e($address ?: '—') . '</p>'
        . '<p class="idc-label">Mobile</p><p class="idc-val">' . e($s['mobile']) . '</p>'
        . '<p class="idc-label">If found, please return to</p><p class="idc-val idc-clamp2">' . e($institute . ($addressInst ? ', ' . $addressInst : '')) . '</p>'
        . '</div><div class="idc-qrbox"><div class="idc-qr" data-qr="' . e($s['student_uid']) . '"></div><p>' . e($s['student_uid']) . '</p></div></div>'
        . '<div class="idc-backfoot"><span>' . e($phone . ($email ? ' · ' . $email : '')) . '</span><span class="idc-sign">Authorised signatory</span></div>'
        . '</div>';
}

// Build page cells: each page = 4 rows × 2 columns
$cells = [];
foreach ($rows as $s) {
    if ($side === 'both') {
        $cells[] = [idc_front($s, $institute, $logoWhite), idc_back($s, $institute, $addressInst, $phone, $email)];
    } else {
        $cells[] = $side === 'front' ? idc_front($s, $institute, $logoWhite) : idc_back($s, $institute, $addressInst, $phone, $email);
    }
}
$pages = [];
if ($side === 'both') {
    $pages = array_chunk($cells, 4);
} else {
    foreach (array_chunk($cells, 8) as $chunk) {
        $rowsOf2 = array_chunk($chunk, 2);
        if ($side === 'back') {
            // mirror each row so backs line up with fronts on long-edge duplex printing
            $rowsOf2 = array_map(fn ($r) => count($r) === 2 ? array_reverse($r) : ['', $r[0]], $rowsOf2);
        }
        $pages[] = $rowsOf2;
    }
}

if ($rows) {
    log_activity('print', 'students', count($rows) === 1 ? $rows[0]['id'] : null, 'Printed ' . count($rows) . ' student ID card' . (count($rows) === 1 ? '' : 's') . ' (' . $side . ')');
}
$query = $_GET;
$link = function (string $s) use ($query) {
    $query['side'] = $s;
    return '?' . http_build_query($query);
};
print_layout_start('Student ID Cards (' . count($rows) . ')', false);
?>
<style>
  .idc-page{position:relative;display:grid;grid-template-columns:85.6mm 85.6mm;column-gap:10mm;row-gap:12mm;justify-content:center;align-content:start;padding-top:6mm;min-height:262mm}
  .idc-page + .idc-page{break-before:page;page-break-before:always}
  @media screen{.idc-page + .idc-page{margin-top:14mm;border-top:1px dashed #cbd5e1;padding-top:14mm}}
  .idc-card{position:relative;width:85.6mm;height:54mm;border-radius:3mm;background:#fff;box-shadow:0 0 0 .25mm #cbd5e1;font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:#1e293b;-webkit-print-color-adjust:exact;print-color-adjust:exact;break-inside:avoid}
  .idc-card > :not(.idc-mark){overflow:hidden}
  .idc-mark{position:absolute;display:block;border-color:#475569;border-style:solid;border-width:0}
  .idc-h{height:0;border-top-width:.2mm}.idc-v{width:0;border-left-width:.2mm}
  .idc-band{position:absolute;inset:0 0 auto 0;height:14mm;border-radius:3mm 3mm 0 0;background:linear-gradient(90deg,#071A3B,#0B2A5B 55%,#183C7A);display:flex;align-items:center;gap:2.5mm;padding:0 3.5mm}
  .idc-logo{height:9mm;width:auto}
  .idc-inst{display:flex;flex-direction:column;line-height:1.15;color:#fff;min-width:0}
  .idc-inst b{font-size:2.3mm;line-height:1.2;font-weight:800;text-transform:uppercase;letter-spacing:.08mm;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .idc-inst span{font-size:2mm;color:rgba(255,255,255,.75)}
  .idc-stripe{position:absolute;left:0;right:0;top:14mm;height:.8mm;background:#2EA454}
  .idc-body{position:absolute;left:3.5mm;right:3.5mm;top:17.5mm;bottom:8mm;display:flex;gap:3.5mm}
  .idc-photo{width:21mm;height:27mm;flex:none;border-radius:1.5mm;overflow:hidden;background:#EFF4FB;box-shadow:0 0 0 .2mm #DBE6F6;display:flex;align-items:center;justify-content:center}
  .idc-photo img{width:100%;height:100%;object-fit:cover}
  .idc-initials{font-size:7mm;font-weight:700;color:#183C7A}
  .idc-info{min-width:0;flex:1;line-height:1.2}
  .idc-name{font-size:3.6mm;font-weight:700;color:#0B2A5B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:0}
  .idc-uid{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:2.8mm;font-weight:700;color:#1C7634;margin:.6mm 0 1.2mm}
  .idc-dl{border-collapse:collapse;font-size:2.2mm;width:100%;table-layout:fixed}
  .idc-dl th{width:11mm;text-align:left;font-weight:500;color:#64748b;padding:.25mm 0;vertical-align:top}
  .idc-dl td{font-weight:600;padding:.25mm 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .idc-blood{color:#dc2626}
  .idc-foot{position:absolute;left:0;right:0;bottom:0;height:6.5mm;border-radius:0 0 3mm 3mm;background:#f1f5f9;display:flex;align-items:center;justify-content:space-between;padding:0 3.5mm;font-size:2.1mm}
  .idc-topline{position:absolute;left:0;right:0;top:0;height:1.4mm;border-radius:3mm 3mm 0 0;background:#0B2A5B}
  .idc-backbody{position:absolute;left:3.5mm;right:3.5mm;top:4mm;bottom:9mm;display:flex;gap:3.5mm}
  .idc-backtext{min-width:0;flex:1;font-size:2.2mm;line-height:1.3}
  .idc-label{margin:1.4mm 0 0;font-size:1.9mm;font-weight:700;text-transform:uppercase;letter-spacing:.15mm;color:#64748b}
  .idc-label:first-child{margin-top:0}
  .idc-val{margin:.3mm 0 0;font-weight:500}
  .idc-clamp3{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
  .idc-clamp2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .idc-qrbox{width:22mm;flex:none;text-align:center}
  .idc-qr{width:20mm;height:20mm;margin:1mm auto 0}
  .idc-qrbox p{margin:.8mm 0 0;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:1.9mm;font-weight:700}
  .idc-backfoot{position:absolute;left:0;right:0;bottom:0;height:7mm;border-radius:0 0 3mm 3mm;background:#0B2A5B;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 3.5mm;font-size:2mm}
  .idc-sign{border-top:.2mm solid rgba(255,255,255,.6);padding-top:.4mm;font-style:italic}
  .idc-empty{width:85.6mm;height:54mm}
  @media print{.print-sheet{padding:0!important}}
</style>

<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
  <div>
    <p class="font-semibold text-slate-800"><?= count($rows) ?> card<?= count($rows) === 1 ? '' : 's' ?> · <?= count($pages) ?> A4 sheet<?= count($pages) === 1 ? '' : 's' ?><?= $res['total'] > count($rows) ? ' · first ' . count($rows) . ' of ' . (int) $res['total'] . ' students' : '' ?></p>
    <p class="text-xs text-slate-500">Print at 100% scale (no “fit to page”) on A4, then cut along the crop marks. Use 300 GSM card stock or PVC sheets for best results.</p>
  </div>
  <div class="flex gap-1 rounded-lg bg-white p-1 ring-1 ring-slate-200">
    <?php foreach (['both' => 'Front + back', 'front' => 'Fronts', 'back' => 'Backs'] as $k => $label): ?>
      <a href="<?= e($link($k)) ?>" class="rounded-md px-3 py-1.5 text-xs font-semibold <?= $side === $k ? 'bg-brand-800 text-white' : 'text-slate-600 hover:bg-slate-100' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$rows): ?>
  <div class="py-24 text-center">
    <p class="font-display text-xl font-bold text-brand-900">No students selected</p>
    <p class="mt-2 text-sm text-slate-500"><?= $res['empty_selection'] ? 'Choose students on the ID Cards screen (or pass ?ids=1,2,3 / ?program_id=…).' : 'No students match the selected class and status.' ?></p>
    <a href="<?= e(admin_url('students/id-cards')) ?>" class="btn btn-primary btn-sm no-print mt-5">Open ID Cards</a>
  </div>
<?php else: ?>
  <?php foreach ($pages as $page): ?>
    <div class="idc-page">
      <?php foreach ($page as $cell): ?>
        <?php foreach ((array) $cell as $face): ?>
          <?= $face !== '' ? $face : '<div class="idc-empty"></div>' ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php print_layout_end();
