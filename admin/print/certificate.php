<?php
/**
 * Certificate print view — full-bleed A4 sheet(s) with letterhead, title, body, signatories, seal, certificate number,
 * security code and a QR code linking to the public verification page.
 *
 *   /admin/print/certificate.php?id=12                 one certificate (Print / Save as PDF toolbar)
 *   /admin/print/certificate.php?ids=12,13,14          several certificates (bulk print, one sheet each)
 *   /admin/print/certificate.php?template_id=3         specimen of a template with the sample student (&student_id= for a real one)
 *   &embed=1   bare sheet for the admin preview iframe (no toolbar, not counted as a print)
 *   &autoprint=1  open the browser print dialog on load
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/certificates.php';
require_login();
require_permission('certificates', 'view');

$embed = input_int('embed') === 1;
$autoPrint = input_int('autoprint') === 1;
$templateId = input_int('template_id');
$ids = [];
if (input_int('id')) {
    $ids[] = input_int('id');
}
foreach (explode(',', (string) input('ids', '')) as $v) {
    if ((int) $v > 0) {
        $ids[] = (int) $v;
    }
}
$ids = array_slice(array_values(array_unique($ids)), 0, 100);

$fail = function (string $title, string $text, int $code = 404) {
    http_response_code($code);
    print_layout_start($title);
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">' . e($title) . '</p>'
        . '<p class="mx-auto mt-2 max-w-md text-sm text-slate-500">' . e($text) . '</p>'
        . '<a href="' . e(admin_url('certificates')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to certificates</a></div>';
    print_layout_end();
    exit;
};

$toolbar = function (string $title, string $badge = '', string $extra = '') {
    return '<div class="no-print sticky top-0 z-10 flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-white/90 px-4 py-2 backdrop-blur">'
        . '<div class="flex min-w-0 items-center gap-2"><span class="truncate text-sm font-semibold text-slate-700">' . e($title) . '</span>' . $badge . '</div>'
        . '<div class="flex flex-wrap gap-2">'
        . '<button type="button" onclick="history.length>1?history.back():window.close()" class="btn btn-secondary btn-sm">' . icon('arrow-left', 'w-4 h-4') . 'Back</button>'
        . $extra
        . '<button type="button" onclick="window.print()" class="btn btn-primary btn-sm">' . icon('printer', 'w-4 h-4') . 'Print / Save PDF</button></div></div>';
};

$statusChip = function (string $status): string {
    $map = ['issued' => ['Valid', 'badge-green'], 'expired' => ['Expired', 'badge-purple'], 'pending' => ['Pending approval', 'badge-amber'],
        'revoked' => ['Revoked', 'badge-red'], 'rejected' => ['Rejected', 'badge-slate']];
    [$label, $cls] = $map[$status] ?? [ucfirst($status), 'badge-slate'];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
};

/* ---------------------------------------------------------------- Template specimen */
if ($templateId && !$ids) {
    $tpl = db_row('SELECT * FROM certificate_templates WHERE id = ?', [$templateId]);
    if (!$tpl) {
        $fail('Template not found', 'The certificate template does not exist or has been deleted.');
    }
    $res = cert_template_preview($tpl, input_int('student_id') ?: null, $embed);
    if ($embed) {
        echo $res['html'];
        exit;
    }
    $sub = $res['sample'] ? 'Specimen with sample student' : 'Specimen for ' . $res['student']['name'];
    echo cert_document($res['sheet'], [
        'title' => 'Specimen - ' . $tpl['name'], 'orientation' => $res['orientation'], 'auto_print' => $autoPrint,
        'toolbar' => $toolbar($tpl['name'] . ' · ' . $sub, '<span class="badge badge-slate">' . e(cert_type_label($tpl['type'])) . '</span>'),
    ]);
    exit;
}

/* ---------------------------------------------------------------- Issued certificates */
if (!$ids) {
    $fail('Certificate not specified', 'Open a certificate from the Certificates screen to print it.', 400);
}
$rows = db_all('SELECT * FROM certificates WHERE id IN (' . implode(',', $ids) . ')');
if (!$rows) {
    $fail('Certificate not found', 'The certificate does not exist or has been deleted.');
}
$byId = [];
foreach ($rows as $r) {
    $byId[(int) $r['id']] = $r;
}
$rows = array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));

$sheets = '';
foreach ($rows as $c) {
    $sheets .= cert_sheet_for($c);
}
$first = $rows[0];
$firstData = json_decode((string) $first['data'], true) ?: [];
$orientation = ($firstData['design']['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';

if ($embed) {
    echo cert_document($sheets, ['embed' => true, 'orientation' => $orientation, 'title' => $first['certificate_no']]);
    exit;
}

// Count prints of issued certificates (opening the print view = a print run) and log once per view.
$printable = array_values(array_filter($rows, fn ($c) => $c['status'] === 'issued'));
if ($printable) {
    db_exec('UPDATE certificates SET printed_count = printed_count + 1, last_printed_at = NOW() WHERE id IN (' . implode(',', array_map(fn ($c) => (int) $c['id'], $printable)) . ')');
}
log_activity('print', 'certificates', count($rows) === 1 ? $first['id'] : implode(',', array_slice(array_column($rows, 'id'), 0, 20)),
    count($rows) === 1 ? 'Printed ' . cert_type_label($first['type']) . ' ' . $first['certificate_no'] : 'Printed ' . count($rows) . ' certificates');

if (count($rows) === 1) {
    $title = cert_type_label($first['type']) . ' ' . $first['certificate_no'];
    $verify = '<a href="' . e(cert_verify_url($first['certificate_no'], $first['verification_hash'])) . '" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">'
        . icon('shield-check', 'w-4 h-4') . 'Verify online</a>';
    $bar = $toolbar($title, $statusChip(cert_effective_status($first)), $verify);
} else {
    $title = count($rows) . ' certificates';
    $bar = $toolbar($title . ' · ' . implode(', ', array_slice(array_column($rows, 'certificate_no'), 0, 3)) . (count($rows) > 3 ? ' …' : ''));
}
echo cert_document($sheets, ['title' => $title, 'orientation' => $orientation, 'toolbar' => $bar, 'auto_print' => $autoPrint]);
