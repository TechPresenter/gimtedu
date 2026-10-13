<?php
/**
 * Certificates workflow API (lists, export and remarks use /api/crud/certificates and /api/crud/certificate_templates).
 *
 *   GET    /certificates/summary                       KPIs, by-type counts, 6-month trend, recent verifications
 *   GET    /certificates/config                        types, extra fields, placeholders, styles, active templates, settings
 *   GET    /certificates/students?q=&ids=              student picker options (all statuses)
 *   GET    /certificates/students/{id}                 student card + existing certificates
 *   POST   /certificates/preview                       render an issue request (no number) -> {html, warnings, missing}
 *   POST   /certificates/issue                         issue (or request when approval is required)
 *   GET    /certificates/bulk-candidates               ?section_id= | ?program_id=&semester=, &type= -> students + eligibility
 *   POST   /certificates/bulk-issue                    {student_ids[], type, template_id, issue_date, fields{}}
 *   GET    /certificates/settings | POST              approval step, e-mail on issue, number prefix, verify rate limit
 *   GET    /certificates/{id}                          detail (fields, timeline, verification log, reissue chain)
 *   POST   /certificates/{id}/approve | reject {reason} | revoke {reason} | reissue {reason, issue_date, fields{}} | email
 *   POST   /certificates/templates/preview             live preview of an unsaved template draft
 *   POST   /certificates/templates/{id}/duplicate | default
 *   GET    /certificates/verify/{number}?h=            PUBLIC (no login): safe verification details, rate limited
 */
require_once APP_ROOT . '/app/services/certificates.php';

function cert_api_row(int $id): array
{
    $c = cert_find($id);
    if (!$c) {
        api_error('Certificate not found. It may have been deleted.', 404);
    }
    return $c;
}

function cert_api_issue_input(array $in): array
{
    return [
        'student_id' => $in['student_id'] ?? null, 'type' => $in['type'] ?? null, 'template_id' => $in['template_id'] ?? null,
        'issue_date' => $in['issue_date'] ?? null, 'valid_until' => $in['valid_until'] ?? null, 'remarks' => $in['remarks'] ?? null,
        'fields' => is_array($in['fields'] ?? null) ? $in['fields'] : [],
    ];
}

/* ------------------------------------------------------------------ Public verification (no login) */

route('GET', '/certificates/verify/{no:[A-Za-z0-9\-]+}', function ($p) {
    $res = cert_verify((string) $p['no'], 'api', isset($_GET['h']) ? (string) $_GET['h'] : null);
    if ($res['state'] === 'rate_limited') {
        api_error('Too many verification requests. Please wait a few minutes and try again.', 429);
    }
    $messages = [
        'valid' => 'This certificate is genuine and valid.',
        'expired' => 'This certificate is genuine but its validity period has ended.',
        'revoked' => 'This certificate has been revoked by the institute and is no longer valid.',
        'not_found' => 'No certificate with this number was issued by the institute.',
        'empty' => 'Enter a certificate number.',
    ];
    api_ok([
        'status' => $res['state'],
        'number' => $res['number'],
        'certificate' => $res['certificate'],
        'signature_ok' => $res['signature_ok'],
        'verify_url' => $res['number'] !== '' ? absolute_url('verify-certificate/' . rawurlencode($res['number'])) : null,
    ], $messages[$res['state']] ?? '');
}, ['auth' => false]);

/* ------------------------------------------------------------------ Dashboard & configuration */

route('GET', '/certificates/summary', function () {
    api_require('certificates', 'view');
    api_ok(cert_summary());
});

route('GET', '/certificates/config', function () {
    api_require('certificates', 'view');
    api_ok(cert_config() + ['can_approve' => can('certificates', 'approve')]);
});

route('GET', '/certificates/students', function () {
    api_require('certificates', 'view');
    api_ok(cert_student_options($_GET));
});

route('GET', '/certificates/students/{id:\d+}', function ($p) {
    api_require('certificates', 'view');
    $card = cert_student_card((int) $p['id']);
    if (!$card) {
        api_error('Student not found.', 404);
    }
    api_ok($card);
});

/* ------------------------------------------------------------------ Issue */

route('POST', '/certificates/preview', function () {
    api_require('certificates', 'create');
    $in = cert_api_issue_input(api_input());
    [$errors, $ctx] = cert_validate_issue($in);
    // Preview while the form is incomplete: report errors but still render when student + template are known.
    if (!$ctx['student'] || !$ctx['template'] || !$ctx['def']) {
        api_error('Choose the student and certificate type to see a preview.', 422, $errors);
    }
    $composed = cert_compose($ctx);
    $sheet = cert_preview_sheet($ctx, $composed, 'preview');
    $catalog = cert_placeholders();
    api_ok([
        'html' => cert_document($sheet, ['embed' => true, 'orientation' => $composed['design']['orientation'], 'title' => 'Preview']),
        'orientation' => $composed['design']['orientation'],
        'title' => $composed['title'],
        'errors' => (object) $errors,
        'warnings' => $ctx['warnings'],
        'missing' => array_map(fn ($k) => ['key' => $k, 'label' => $catalog[$k]['label'] ?? $k], $composed['missing']),
        'unknown' => $composed['unknown'],
        'field_errors' => (object) cert_missing_field_errors($composed, $ctx['def']),
        'valid_until' => $ctx['valid_until'],
        'template' => ['id' => (int) $ctx['template']['id'], 'name' => $ctx['template']['name']],
    ]);
});

route('POST', '/certificates/issue', function () {
    api_require('certificates', 'create');
    $in = api_input();
    $row = cert_issue(cert_api_issue_input($in), [
        'issue_now' => !array_key_exists('issue_now', $in) || in_array($in['issue_now'], [true, 1, '1', 'true'], true),
        'email_student' => cert_settings()['email_student'],
    ]);
    $label = cert_type_label($row['type']);
    api_ok(['id' => (int) $row['id'], 'certificate_no' => $row['certificate_no'], 'status' => $row['status']],
        $row['status'] === 'pending' ? $label . ' ' . $row['certificate_no'] . ' submitted for approval.' : $label . ' ' . $row['certificate_no'] . ' issued successfully.', 201);
});

route('GET', '/certificates/bulk-candidates', function () {
    api_require('certificates', 'create');
    api_ok(cert_bulk_candidates($_GET));
});

route('POST', '/certificates/bulk-issue', function () {
    api_require('certificates', 'create');
    $in = api_input();
    $res = cert_bulk_issue(cert_api_issue_input($in) + ['student_ids' => (array) ($in['student_ids'] ?? [])], [
        'issue_now' => !array_key_exists('issue_now', $in) || in_array($in['issue_now'], [true, 1, '1', 'true'], true),
        'email_student' => cert_settings()['email_student'],
    ]);
    $n = count($res['issued']);
    $pending = count(array_filter($res['issued'], fn ($r) => $r['status'] === 'pending'));
    if (!$n) {
        api_error('No certificates were issued — every selected student was skipped.', 422, ['student_ids' => $res['skipped'][0]['reason'] ?? 'No eligible students.']);
    }
    api_ok($res, sprintf('%d certificate%s %s%s.', $n, $n === 1 ? '' : 's', $pending ? 'submitted for approval' : 'issued', $res['skipped'] ? ', ' . count($res['skipped']) . ' skipped' : ''));
});

/* ------------------------------------------------------------------ Settings */

route('GET', '/certificates/settings', function () {
    api_require('certificates', 'view');
    api_ok(cert_settings() + ['prefix' => (string) setting('certificate_prefix', setting('institute_short_name', 'GIMT'))]);
});

route('POST', '/certificates/settings', function () {
    api_require('certificates', 'manage');
    $in = api_input();
    $errors = [];
    $prefix = strtoupper(trim((string) ($in['prefix'] ?? '')));
    if ($prefix === '' || !preg_match('/^[A-Z0-9]{2,10}$/', $prefix)) {
        $errors['prefix'] = 'Use 2–10 letters or digits (e.g. GIMT).';
    }
    $limit = (int) ($in['verify_rate_limit'] ?? 30);
    if ($limit < 5 || $limit > 500) {
        $errors['verify_rate_limit'] = 'Allow between 5 and 500 lookups per 10 minutes.';
    }
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $bool = fn ($v) => in_array($v, [true, 1, '1', 'true', 'on'], true) ? '1' : '0';
    save_setting('certificate_approval_required', $bool($in['approval_required'] ?? false), 'certificates');
    save_setting('certificate_email_student', $bool($in['email_student'] ?? false), 'certificates');
    save_setting('certificate_prefix', $prefix, 'certificates');
    save_setting('certificate_verify_rate_limit', (string) $limit, 'certificates');
    log_activity('update', 'certificates', null, 'Updated certificate settings (approval step ' . ($bool($in['approval_required'] ?? false) === '1' ? 'on' : 'off') . ', prefix ' . $prefix . ')');
    api_ok(cert_settings() + ['prefix' => $prefix], 'Certificate settings saved.');
});

/* ------------------------------------------------------------------ Templates */

route('POST', '/certificates/templates/preview', function () {
    api_require('certificates', 'view');
    $in = api_input();
    $tpl = cert_template_draft(is_array($in['template'] ?? null) ? $in['template'] : $in);
    $studentId = (int) ($in['student_id'] ?? 0) ?: null;
    $res = cert_template_preview($tpl, $studentId);
    unset($res['sheet']);
    api_ok($res);
});

route('POST', '/certificates/templates/{id:\d+}/duplicate', function ($p) {
    api_require('certificates', 'create');
    $t = db_row('SELECT * FROM certificate_templates WHERE id = ?', [(int) $p['id']]);
    if (!$t) {
        api_error('Template not found.', 404);
    }
    $base = preg_replace('/\s*\(copy( \d+)?\)$/i', '', $t['name']);
    $name = $base . ' (copy)';
    for ($i = 2; db_value('SELECT COUNT(*) FROM certificate_templates WHERE name = ?', [$name]); $i++) {
        $name = $base . ' (copy ' . $i . ')';
    }
    unset($t['id'], $t['created_at'], $t['updated_at']);
    $t['name'] = mb_substr($name, 0, 150);
    $t['is_default'] = 0;
    $t['created_by'] = user_id();
    $t['updated_by'] = null;
    $id = db_insert('certificate_templates', $t);
    log_activity('create', 'certificates', $id, 'Duplicated certificate template "' . $base . '" as "' . $t['name'] . '"');
    api_ok(['id' => $id, 'name' => $t['name']], 'Template duplicated as "' . $t['name'] . '".', 201);
});

route('POST', '/certificates/templates/{id:\d+}/default', function ($p) {
    api_require('certificates', 'edit');
    $t = db_row('SELECT id, name, type, status FROM certificate_templates WHERE id = ?', [(int) $p['id']]);
    if (!$t) {
        api_error('Template not found.', 404);
    }
    if ($t['status'] !== 'active') {
        api_error('Activate "' . $t['name'] . '" before making it the default.', 422);
    }
    db_transaction(function () use ($t) {
        db_exec('UPDATE certificate_templates SET is_default = 0 WHERE type = ?', [$t['type']]);
        db_exec('UPDATE certificate_templates SET is_default = 1, updated_by = ? WHERE id = ?', [user_id(), (int) $t['id']]);
    });
    log_activity('update', 'certificates', $t['id'], 'Set "' . $t['name'] . '" as the default ' . cert_type_label($t['type']) . ' template');
    api_ok(null, '"' . $t['name'] . '" is now the default ' . cert_type_label($t['type']) . ' template.');
});

/* ------------------------------------------------------------------ One certificate */

route('GET', '/certificates/{id:\d+}', function ($p) {
    api_require('certificates', 'view');
    $d = cert_detail((int) $p['id']);
    if (!$d) {
        api_error('Certificate not found. It may have been deleted.', 404);
    }
    $c = $d['certificate'];
    $d['can'] = [
        'approve' => $c['status'] === 'pending' && can('certificates', 'approve'),
        'reject' => $c['status'] === 'pending' && can('certificates', 'approve'),
        'revoke' => $c['status'] === 'issued' && can('certificates', 'edit'),
        'reissue' => in_array($c['status'], ['issued', 'revoked'], true) && can('certificates', 'create'),
        'email' => $c['status'] === 'issued' && $c['student_email'] && can('certificates', 'edit'),
        'delete' => in_array($c['status'], ['pending', 'rejected'], true) && !$d['replaced_by'] && can('certificates', 'delete'),
        'edit' => can('certificates', 'edit'),
    ];
    api_ok($d);
});

route('POST', '/certificates/{id:\d+}/approve', function ($p) {
    api_require('certificates', 'approve');
    $row = cert_approve(cert_api_row((int) $p['id']), user_id());
    api_ok(['id' => (int) $row['id'], 'status' => $row['status']], cert_type_label($row['type']) . ' ' . $row['certificate_no'] . ' approved and issued.');
});

route('POST', '/certificates/{id:\d+}/reject', function ($p) {
    api_require('certificates', 'approve');
    $row = cert_reject(cert_api_row((int) $p['id']), api_param('reason'), user_id());
    api_ok(['id' => (int) $row['id'], 'status' => $row['status']], 'Request ' . $row['certificate_no'] . ' rejected.');
});

route('POST', '/certificates/{id:\d+}/revoke', function ($p) {
    api_require('certificates', 'edit');
    $row = cert_revoke(cert_api_row((int) $p['id']), api_param('reason'), user_id());
    api_ok(['id' => (int) $row['id'], 'status' => $row['status']], $row['certificate_no'] . ' revoked. The verification page now reports it as revoked.');
});

route('POST', '/certificates/{id:\d+}/reissue', function ($p) {
    api_require('certificates', 'create');
    $old = cert_api_row((int) $p['id']);
    $in = api_input();
    $row = cert_reissue($old, [
        'reason' => $in['reason'] ?? null, 'issue_date' => $in['issue_date'] ?? null, 'valid_until' => $in['valid_until'] ?? null,
        'template_id' => $in['template_id'] ?? null, 'fields' => is_array($in['fields'] ?? null) ? $in['fields'] : [],
    ], [
        'issue_now' => !array_key_exists('issue_now', $in) || in_array($in['issue_now'], [true, 1, '1', 'true'], true),
        'email_student' => cert_settings()['email_student'],
    ]);
    api_ok(['id' => (int) $row['id'], 'certificate_no' => $row['certificate_no'], 'status' => $row['status']],
        $row['status'] === 'pending'
            ? 'Reissue ' . $row['certificate_no'] . ' submitted for approval. ' . $old['certificate_no'] . ' stays valid until it is approved.'
            : 'Reissued as ' . $row['certificate_no'] . '. ' . $old['certificate_no'] . ' has been superseded.', 201);
});

route('POST', '/certificates/{id:\d+}/email', function ($p) {
    api_require('certificates', 'edit');
    $c = cert_api_row((int) $p['id']);
    if ($c['status'] !== 'issued') {
        api_error('Only issued certificates can be e-mailed.', 422);
    }
    $student = cert_student((int) $c['student_id']);
    if (!$student || empty($student['email'])) {
        api_error('This student has no e-mail address on record.', 422);
    }
    if (!cert_email_student($c, $student)) {
        api_error('The e-mail could not be sent. Check the mail settings and try again.', 502);
    }
    log_activity('email', 'certificates', $c['id'], 'E-mailed ' . cert_type_label($c['type']) . ' ' . $c['certificate_no'] . ' verification link to ' . $student['email']);
    api_ok(null, 'Verification link e-mailed to ' . $student['email'] . '.');
});
