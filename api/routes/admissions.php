<?php
/**
 * Admissions API (admissions unit). Every endpoint requires login; non-GET requests need the CSRF header.
 *
 *   GET    /admissions/dashboard?session_id=                         KPIs, funnel, source/program/trend charts, follow-ups due
 *   GET    /admissions/board?session_id=&program_id=&assigned_to=&source=&q=&closed=1   Kanban columns (first 20 cards per stage)
 *   GET    /admissions/board?stage=approval&offset=20                one more page of a single column
 *   GET    /admissions/followups?scope=overdue|today|due|upcoming&session_id=&page=&per_page=
 *   GET    /admissions/form-meta?session_id=&program_id=             next application no, default fee, document types
 *   GET    /admissions/duplicates?phone=&email=&exclude=&exclude_enquiry=
 *   POST   /admissions/bulk-stage           {ids[], to_stage, remarks}
 *   GET    /admissions/{id}/profile                                  applicant profile + documents + follow-ups + timeline + next steps
 *   POST   /admissions/{id}/move            {to_stage, remarks}      validated stage transition (writes admission_stage_history)
 *   POST   /admissions/{id}/assign          {assigned_to}
 *   POST   /admissions/{id}/notes           {counselling_notes}
 *   POST   /admissions/{id}/assessment      {entrance_exam, entrance_score, interview_date, interview_score, interview_remarks}
 *   POST   /admissions/{id}/fee             {admission_fee, amount, mode, reference, paid_on}
 *   POST   /admissions/{id}/convert                                  create the student record (transaction)
 *   POST   /admissions/{id}/documents       multipart {doc_type, file, remarks}
 *   POST   /admissions/documents/{docId}/verify {status: verified|rejected|pending, remarks}
 *   DELETE /admissions/documents/{docId}
 *   POST   /admissions/{id}/followups       {type, notes, outcome, next_followup_date}
 *   POST   /admissions/followups/{fid}/done
 *   DELETE /admissions/followups/{fid}
 */
require_once APP_ROOT . '/app/services/admissions.php';

function adm_api_session_id(): ?int
{
    $sid = (int) ($_GET['session_id'] ?? 0);
    return $sid > 0 ? $sid : current_session_id();
}

function adm_api_find(int $id): array
{
    $a = db_row('SELECT * FROM admissions WHERE id = ?', [$id]);
    if (!$a) {
        api_error('Application not found. It may have been deleted.', 404);
    }
    return $a;
}

function adm_card_select(): string
{
    return "a.id, a.application_no, a.first_name, a.middle_name, a.last_name, a.photo, a.phone, a.stage, a.source, a.created_at, a.stage_changed_at,
            a.entrance_score, a.interview_score, a.admission_fee, a.fee_paid, a.student_id, a.rejection_reason, a.previous_percentage,
            p.short_name AS program, u.name AS counsellor, u.avatar AS counsellor_avatar,
            (SELECT COUNT(*) FROM admission_documents d WHERE d.admission_id = a.id) AS docs_count,
            (SELECT COUNT(*) FROM admission_documents d WHERE d.admission_id = a.id AND d.status = 'verified') AS docs_verified,
            (SELECT MIN(f.next_followup_date) FROM admission_followups f WHERE f.admission_id = a.id AND f.completed_at IS NULL AND f.next_followup_date IS NOT NULL) AS next_followup";
}

function adm_card(array $r): array
{
    $since = $r['stage_changed_at'] ?: $r['created_at'];
    return [
        'id' => (int) $r['id'], 'application_no' => $r['application_no'], 'name' => admission_full_name($r), 'photo' => $r['photo'], 'phone' => $r['phone'],
        'stage' => $r['stage'], 'source' => $r['source'], 'program' => $r['program'], 'counsellor' => $r['counsellor'], 'counsellor_avatar' => $r['counsellor_avatar'],
        'score' => $r['entrance_score'] !== null ? (float) $r['entrance_score'] : ($r['interview_score'] !== null ? (float) $r['interview_score'] : null),
        'percentage' => $r['previous_percentage'] !== null ? (float) $r['previous_percentage'] : null,
        'docs_count' => (int) $r['docs_count'], 'docs_verified' => (int) $r['docs_verified'],
        'admission_fee' => $r['admission_fee'] !== null ? (float) $r['admission_fee'] : null, 'fee_paid' => (float) $r['fee_paid'],
        'days_in_stage' => max(0, (int) floor((time() - strtotime((string) $since)) / 86400)), 'next_followup' => $r['next_followup'],
        'converted' => !empty($r['student_id']), 'rejection_reason' => $r['rejection_reason'], 'created_at' => $r['created_at'],
    ];
}

/* ---------------------------------------------------------------- Dashboard */
route('GET', '/admissions/dashboard', function () {
    api_require('admissions', 'view');
    $sid = adm_api_session_id();
    $session = $sid ? db_row('SELECT id, name, start_date, end_date, admissions_open FROM academic_sessions WHERE id = ?', [$sid]) : null;
    $w = 'a.academic_session_id ' . ($sid ? '= ?' : 'IS NULL');
    $args = $sid ? [$sid] : [];
    $k = db_row("SELECT COUNT(*) AS total,
                SUM(a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_30,
                SUM(a.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND a.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)) AS prev_30,
                SUM(a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS new_7,
                SUM(a.stage IN ('enquiry','application','document_verification','entrance_interview','approval','fee_payment','waitlisted')) AS pending,
                SUM(a.stage IN ('fee_payment','confirmed')) AS approved,
                SUM(a.stage = 'rejected') AS rejected,
                SUM(a.stage = 'withdrawn') AS withdrawn,
                SUM(a.stage = 'waitlisted') AS waitlisted,
                SUM(a.stage = 'confirmed') AS confirmed,
                SUM(a.student_id IS NOT NULL) AS converted,
                SUM(a.stage = 'fee_payment' AND a.fee_paid < COALESCE(a.admission_fee, 1)) AS fee_pending,
                COALESCE(SUM(a.fee_paid), 0) AS fee_collected
             FROM admissions a WHERE $w", $args);
    $k = array_map(fn ($v) => $v === null ? 0 : (float) $v, $k);
    $k['conversion_rate'] = $k['total'] > 0 ? round($k['confirmed'] / $k['total'] * 100, 1) : 0;
    $k['new_trend'] = $k['prev_30'] > 0 ? round(($k['new_30'] - $k['prev_30']) / $k['prev_30'] * 100, 1) : null;
    $k['enquiries_open'] = (int) db_value("SELECT COUNT(*) FROM enquiries WHERE status IN ('new','contacted','interested')");

    // Stage counts + funnel (applications that reached each pipeline stage)
    $stageCounts = array_map('intval', db_pairs("SELECT a.stage, COUNT(*) FROM admissions a WHERE $w GROUP BY a.stage", $args));
    $order = array_keys(admission_stages());
    $maxIdx = [];
    foreach (db_all("SELECT a.id, a.stage FROM admissions a WHERE $w", $args) as $r) {
        $i = array_search($r['stage'], $order, true);
        $maxIdx[(int) $r['id']] = $i === false ? 1 : $i;
    }
    if ($maxIdx) {
        foreach (db_all("SELECT h.admission_id, h.to_stage FROM admission_stage_history h JOIN admissions a ON a.id = h.admission_id WHERE $w", $args) as $h) {
            $i = array_search($h['to_stage'], $order, true);
            if ($i !== false && $i > ($maxIdx[(int) $h['admission_id']] ?? 0)) {
                $maxIdx[(int) $h['admission_id']] = $i;
            }
        }
    }
    $funnel = [];
    foreach ($order as $i => $stage) {
        $funnel[] = ['stage' => $stage, 'label' => admission_stages()[$stage], 'reached' => count(array_filter($maxIdx, fn ($m) => $m >= $i)), 'current' => $stageCounts[$stage] ?? 0];
    }

    $sources = db_all("SELECT a.source, COUNT(*) AS total, SUM(a.stage = 'confirmed') AS confirmed FROM admissions a WHERE $w GROUP BY a.source ORDER BY total DESC", $args);
    foreach ($sources as &$s) {
        $s['label'] = admission_sources()[$s['source']] ?? label_from_key($s['source']);
        $s['total'] = (int) $s['total'];
        $s['confirmed'] = (int) $s['confirmed'];
    }
    unset($s);
    $programs = db_all("SELECT p.id, p.short_name AS program, p.intake_capacity, COUNT(a.id) AS total, SUM(a.stage = 'confirmed') AS confirmed,
                               SUM(a.stage IN ('fee_payment','confirmed')) AS approved
                        FROM programs p LEFT JOIN admissions a ON a.program_id = p.id AND $w
                        WHERE p.status = 'active' GROUP BY p.id, p.short_name, p.intake_capacity, p.sort_order ORDER BY total DESC, p.sort_order", $args);
    foreach ($programs as &$p) {
        $p = ['id' => (int) $p['id'], 'program' => $p['program'], 'intake' => (int) $p['intake_capacity'], 'total' => (int) $p['total'], 'confirmed' => (int) $p['confirmed'], 'approved' => (int) $p['approved']];
    }
    unset($p);

    // Monthly trend: applications received vs admissions confirmed (last 8 months)
    $months = [];
    for ($i = 7; $i >= 0; $i--) {
        $months[date('Y-m', strtotime("first day of -$i month"))] = ['applications' => 0, 'confirmed' => 0];
    }
    $from = array_key_first($months) . '-01';
    foreach (db_all("SELECT DATE_FORMAT(a.created_at, '%Y-%m') AS m, COUNT(*) AS n FROM admissions a WHERE $w AND a.created_at >= ? GROUP BY m", array_merge($args, [$from])) as $r) {
        if (isset($months[$r['m']])) {
            $months[$r['m']]['applications'] = (int) $r['n'];
        }
    }
    foreach (db_all("SELECT DATE_FORMAT(h.created_at, '%Y-%m') AS m, COUNT(DISTINCT h.admission_id) AS n FROM admission_stage_history h JOIN admissions a ON a.id = h.admission_id
                     WHERE $w AND h.to_stage = 'confirmed' AND h.created_at >= ? GROUP BY m", array_merge($args, [$from])) as $r) {
        if (isset($months[$r['m']])) {
            $months[$r['m']]['confirmed'] = (int) $r['n'];
        }
    }
    $trend = [];
    foreach ($months as $m => $v) {
        $trend[] = ['month' => date('M Y', strtotime($m . '-01')), 'label' => date('M', strtotime($m . '-01'))] + $v;
    }

    $counsellors = db_all("SELECT u.id, u.name, u.avatar, COUNT(a.id) AS total, SUM(a.stage = 'confirmed') AS confirmed,
                                  SUM(a.stage IN ('enquiry','application','document_verification','entrance_interview','approval','fee_payment','waitlisted')) AS active
                           FROM admissions a JOIN users u ON u.id = a.assigned_to WHERE $w GROUP BY u.id, u.name, u.avatar ORDER BY confirmed DESC, total DESC LIMIT 6", $args);
    foreach ($counsellors as &$c) {
        $c = ['id' => (int) $c['id'], 'name' => $c['name'], 'avatar' => $c['avatar'], 'total' => (int) $c['total'], 'confirmed' => (int) $c['confirmed'], 'active' => (int) $c['active'],
            'rate' => (int) $c['total'] ? round($c['confirmed'] / $c['total'] * 100, 1) : 0];
    }
    unset($c);

    $overdue = followups_due('overdue', 8, $sid);
    $today = followups_due('today', 8, $sid);
    $recent = array_map('adm_card', db_all('SELECT ' . adm_card_select() . " FROM admissions a JOIN programs p ON p.id = a.program_id LEFT JOIN users u ON u.id = a.assigned_to
                                              WHERE $w ORDER BY a.created_at DESC, a.id DESC LIMIT 6", $args));
    api_ok([
        'session' => $session ? ['id' => (int) $session['id'], 'name' => $session['name'], 'admissions_open' => (bool) $session['admissions_open']] : null,
        'kpis' => $k, 'stage_counts' => $stageCounts, 'funnel' => $funnel, 'sources' => $sources, 'programs' => $programs, 'trend' => $trend,
        'counsellors' => $counsellors, 'followups' => ['overdue' => $overdue, 'today' => $today], 'recent' => $recent,
        'stages' => array_map(fn ($k2, $v) => ['key' => $k2, 'label' => $v], array_keys(admission_all_stages()), admission_all_stages()),
    ]);
});

/* ---------------------------------------------------------------- Kanban board */
route('GET', '/admissions/board', function () {
    api_require('admissions', 'view');
    $sid = adm_api_session_id();
    $where = ['a.academic_session_id ' . ($sid ? '= ?' : 'IS NULL')];
    $args = $sid ? [$sid] : [];
    foreach (['program_id' => 'a.program_id', 'assigned_to' => 'a.assigned_to', 'source' => 'a.source'] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '' && !is_array($_GET[$param])) {
            $where[] = "$col = ?";
            $args[] = $_GET[$param];
        }
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = "(a.application_no LIKE ? OR a.first_name LIKE ? OR a.last_name LIKE ? OR a.phone LIKE ? OR CONCAT(a.first_name, ' ', a.last_name) LIKE ?)";
        array_push($args, ...array_fill(0, 5, '%' . mb_substr($q, 0, 80) . '%'));
    }
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
    $base = 'SELECT ' . adm_card_select() . ' FROM admissions a JOIN programs p ON p.id = a.program_id LEFT JOIN users u ON u.id = a.assigned_to WHERE ' . implode(' AND ', $where);
    $orderSql = ' ORDER BY a.stage_changed_at DESC, a.id DESC';
    if (!empty($_GET['stage'])) {
        $stage = (string) $_GET['stage'];
        if (!isset(admission_all_stages()[$stage])) {
            api_error('Unknown stage.', 422);
        }
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $rows = db_all($base . ' AND a.stage = ?' . $orderSql . " LIMIT $limit OFFSET $offset", array_merge($args, [$stage]));
        api_ok(['stage' => $stage, 'cards' => array_map('adm_card', $rows), 'offset' => $offset]);
    }
    $stages = array_keys(admission_stages());
    if (!empty($_GET['closed'])) {
        $stages = array_merge($stages, array_keys(admission_closed_stages()));
    }
    $counts = array_map('intval', db_pairs('SELECT a.stage, COUNT(*) FROM admissions a WHERE ' . implode(' AND ', $where) . ' GROUP BY a.stage', $args));
    $columns = [];
    foreach ($stages as $stage) {
        $rows = db_all($base . ' AND a.stage = ?' . $orderSql . " LIMIT $limit", array_merge($args, [$stage]));
        $columns[] = ['stage' => $stage, 'label' => admission_stage_label($stage), 'count' => $counts[$stage] ?? 0, 'cards' => array_map('adm_card', $rows),
            'transitions' => admission_transitions($stage)];
    }
    api_ok(['columns' => $columns, 'counts' => $counts, 'can' => ['edit' => can('admissions', 'edit'), 'approve' => can('admissions', 'approve')]]);
});

/* ---------------------------------------------------------------- Follow-ups due */
route('GET', '/admissions/followups', function () {
    if (!can('admissions', 'view') && !can('enquiries', 'view')) {
        api_error('You do not have permission to view follow-ups.', 403);
    }
    [$page, $perPage] = api_pagination(10);
    $scope = in_array($_GET['scope'] ?? 'due', ['overdue', 'today', 'due', 'upcoming'], true) ? $_GET['scope'] : 'due';
    $res = followups_due($scope, $perPage, adm_api_session_id(), ($page - 1) * $perPage);
    $p = paginate($res['total'], $page, $perPage);
    api_ok(['rows' => $res['rows'], 'total' => $p['total'], 'page' => $p['page'], 'pages' => $p['pages'], 'per_page' => $p['per_page']]);
});

/* ---------------------------------------------------------------- Form helpers */
route('GET', '/admissions/form-meta', function () {
    if (!can('admissions', 'create') && !can('admissions', 'edit')) {
        api_error('You do not have permission to create applications.', 403);
    }
    $sid = adm_api_session_id();
    $programId = (int) ($_GET['program_id'] ?? 0) ?: null;
    $level = $programId ? db_value('SELECT level FROM programs WHERE id = ?', [$programId]) : null;
    $required = ['photo', '10th_marksheet', '12th_marksheet', 'id_proof'];
    if ($level === 'PG') {
        $required[] = 'graduation';
    }
    api_ok([
        'next_application_no' => admission_peek_application_no($sid),
        'default_fee' => admission_default_fee($programId, $sid),
        'doc_types' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v, 'required' => in_array($k, $required, true)], array_keys(admission_doc_types()), admission_doc_types()),
        'max_upload' => upload_max_bytes('any'),
        'accept' => implode(',', array_map(fn ($e) => '.' . $e, array_keys(upload_allowed('any')))),
    ]);
});

route('GET', '/admissions/duplicates', function () {
    if (!can('admissions', 'view') && !can('enquiries', 'view')) {
        api_error('You do not have permission to search applicants.', 403);
    }
    $phone = trim((string) ($_GET['phone'] ?? ''));
    $email = trim((string) ($_GET['email'] ?? ''));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    api_ok(admission_duplicates($phone, $email, (int) ($_GET['exclude'] ?? 0) ?: null, (int) ($_GET['exclude_enquiry'] ?? 0) ?: null));
});

/* ---------------------------------------------------------------- Stage moves */
route('POST', '/admissions/bulk-stage', function () {
    api_require('admissions', 'edit');
    $in = api_input();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));
    $to = (string) ($in['to_stage'] ?? '');
    $errors = [];
    if (!$ids) {
        $errors['ids'] = 'Select at least one application.';
    }
    if (!isset(admission_all_stages()[$to])) {
        $errors['to_stage'] = 'Select the stage to move to.';
    }
    if ($to === 'rejected' && trim((string) ($in['remarks'] ?? '')) === '') {
        $errors['remarks'] = 'Enter the reason for rejecting these applications.';
    }
    if (count($ids) > 200) {
        $errors['ids'] = 'Move at most 200 applications at a time.';
    }
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $moved = 0;
    $failed = [];
    foreach ($ids as $id) {
        try {
            admission_move($id, $to, ($in['remarks'] ?? '') !== '' ? (string) $in['remarks'] : 'Bulk stage change');
            $moved++;
        } catch (CrudException $e) {
            $no = db_value('SELECT application_no FROM admissions WHERE id = ?', [$id]) ?: '#' . $id;
            $failed[] = ['id' => $id, 'application_no' => $no, 'message' => $e->getMessage()];
        }
    }
    $msg = sprintf('%d application%s moved to %s.', $moved, $moved === 1 ? '' : 's', admission_stage_label($to));
    if ($failed) {
        $msg .= ' ' . count($failed) . ' could not be moved.';
    }
    api_ok(['moved' => $moved, 'failed' => $failed], $msg);
});

route('POST', '/admissions/{id:\d+}/move', function ($p) {
    api_require('admissions', 'edit');
    $in = api_input();
    $to = (string) ($in['to_stage'] ?? '');
    if (!isset(admission_all_stages()[$to])) {
        api_error('Select a valid stage.', 422, ['to_stage' => 'Select a valid stage.']);
    }
    $row = admission_move((int) $p['id'], $to, isset($in['remarks']) ? (string) $in['remarks'] : null);
    $msg = match ($to) {
        'fee_payment' => in_array($in['from'] ?? '', ['approval', 'waitlisted'], true) || $row['approved_at'] ? 'Application approved. Offer letter ' . $row['offer_letter_no'] . ' issued.' : 'Moved to Fee Payment.',
        'rejected' => 'Application rejected.',
        'waitlisted' => 'Application waitlisted.',
        'withdrawn' => 'Application marked as withdrawn.',
        'confirmed' => 'Admission confirmed.',
        default => 'Application moved to ' . admission_stage_label($to) . '.',
    };
    api_ok(['stage' => $row['stage'], 'id' => (int) $row['id']], $msg);
});

/* ---------------------------------------------------------------- Profile */
route('GET', '/admissions/{id:\d+}/profile', function ($p) {
    api_require('admissions', 'view');
    $id = (int) $p['id'];
    $m = crud_module('admissions');
    $a = crud_find($m, $id);
    if (!$a) {
        api_error('Application not found. It may have been deleted.', 404);
    }
    $a['id'] = (int) $a['id'];
    $a['full_name'] = admission_full_name($a);
    $docs = db_all('SELECT d.*, u.name AS verified_by_name FROM admission_documents d LEFT JOIN users u ON u.id = d.verified_by WHERE d.admission_id = ? ORDER BY d.created_at, d.id', [$id]);
    foreach ($docs as &$d) {
        $d['id'] = (int) $d['id'];
        $d['label'] = admission_doc_types()[$d['doc_type']] ?? label_from_key($d['doc_type']);
        $d['size_bytes'] = $d['size_bytes'] !== null ? (int) $d['size_bytes'] : null;
        $d['exists'] = is_file(APP_ROOT . '/' . $d['file_path']);
    }
    unset($d);
    $followups = db_all('SELECT f.*, u.name AS created_by_name, cu.name AS completed_by_name FROM admission_followups f LEFT JOIN users u ON u.id = f.created_by
                         LEFT JOIN users cu ON cu.id = f.completed_by WHERE f.admission_id = ? OR (f.enquiry_id IS NOT NULL AND f.enquiry_id = ?) ORDER BY f.created_at DESC, f.id DESC',
        [$id, (int) ($a['enquiry_id'] ?? 0)]);
    foreach ($followups as &$f) {
        $f['id'] = (int) $f['id'];
        $f['is_open'] = $f['next_followup_date'] && !$f['completed_at'];
        $f['from_enquiry'] = !$f['admission_id'];
    }
    unset($f);
    $history = db_all('SELECT h.*, u.name AS changed_by_name FROM admission_stage_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.admission_id = ? ORDER BY h.created_at, h.id', [$id]);
    $activity = db_all("SELECT l.action, l.description, l.created_at, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                        WHERE l.module = 'admissions' AND l.record_id = ? ORDER BY l.created_at DESC, l.id DESC LIMIT 60", [(string) $id]);
    // Timeline: stage history + follow-ups + document events + other activity, newest first
    $timeline = [];
    foreach ($history as $h) {
        $timeline[] = ['type' => 'stage', 'stage' => $h['to_stage'], 'title' => $h['from_stage'] ? 'Moved from ' . admission_stage_label($h['from_stage']) . ' to ' . admission_stage_label($h['to_stage']) : admission_stage_label($h['to_stage']),
            'description' => $h['remarks'], 'user' => $h['changed_by_name'], 'at' => $h['created_at']];
    }
    foreach ($followups as $f) {
        $timeline[] = ['type' => 'followup', 'title' => (admission_followup_types()[$f['type']] ?? label_from_key($f['type'])) . ($f['outcome'] ? ' · ' . (admission_followup_outcomes()[$f['outcome']] ?? $f['outcome']) : ''),
            'description' => $f['notes'], 'user' => $f['created_by_name'], 'at' => $f['created_at']];
    }
    foreach ($docs as $d) {
        $timeline[] = ['type' => 'document', 'title' => $d['label'] . ' uploaded', 'description' => $d['original_name'], 'user' => null, 'at' => $d['created_at']];
        if ($d['verified_at'] && $d['status'] !== 'pending') {
            $timeline[] = ['type' => $d['status'] === 'verified' ? 'verified' : 'doc_rejected', 'title' => $d['label'] . ' ' . $d['status'], 'description' => $d['remarks'], 'user' => $d['verified_by_name'], 'at' => $d['verified_at']];
        }
    }
    foreach ($activity as $l) {
        if (preg_match('/^(Recorded admission fee|Updated application|Assigned|Saved assessment|Updated counselling)/', $l['description'])) {
            $timeline[] = ['type' => str_starts_with($l['description'], 'Recorded admission fee') ? 'fee' : 'activity', 'title' => $l['description'], 'description' => null, 'user' => $l['user_name'], 'at' => $l['created_at']];
        }
    }
    usort($timeline, fn ($x, $y) => strcmp((string) $y['at'], (string) $x['at']));

    $transitions = [];
    foreach (admission_transitions($a['stage']) as $t) {
        $order = array_keys(admission_stages());
        $fi = array_search($a['stage'], $order, true);
        $ti = array_search($t, $order, true);
        $kind = in_array($t, ['rejected', 'withdrawn', 'waitlisted'], true) ? $t : ($fi !== false && $ti !== false && $ti < $fi ? 'back' : ($fi === false ? 'reopen' : 'next'));
        if ($t === 'fee_payment' && in_array($a['stage'], ['approval', 'waitlisted'], true)) {
            $kind = 'approve';
        }
        $transitions[] = ['stage' => $t, 'label' => admission_stage_label($t), 'kind' => $kind, 'blockers' => admission_stage_blockers($a, $t, 'x')];
    }
    $verified = array_column(array_filter($docs, fn ($d) => $d['status'] === 'verified'), 'doc_type');
    $required = array_map(fn ($d) => ['doc_type' => $d, 'label' => admission_doc_types()[$d], 'verified' => in_array($d, $verified, true),
        'uploaded' => in_array($d, array_column($docs, 'doc_type'), true)], admission_required_docs($a));
    $student = $a['student_id'] ? db_row('SELECT id, student_uid, admission_no, roll_no, status FROM students WHERE id = ?', [(int) $a['student_id']]) : null;
    $enquiry = $a['enquiry_id'] ? db_row('SELECT id, name, source, status, created_at FROM enquiries WHERE id = ?', [(int) $a['enquiry_id']]) : null;
    $payment = $a['payment_id'] ? db_row('SELECT p.id, p.receipt_no, p.amount, p.payment_date, sf.invoice_no FROM payments p LEFT JOIN student_fees sf ON sf.id = p.student_fee_id WHERE p.id = ?', [(int) $a['payment_id']]) : null;
    $approvedBy = $a['approved_by'] ? db_value('SELECT name FROM users WHERE id = ?', [(int) $a['approved_by']]) : null;
    $sinceStage = $a['stage_changed_at'] ?: $a['created_at'];

    api_ok([
        'admission' => crud_public_row($m, $a) + ['approved_by_name' => $approvedBy, 'days_in_stage' => max(0, (int) floor((time() - strtotime((string) $sinceStage)) / 86400))],
        'documents' => $docs, 'required_docs' => $required, 'followups' => $followups, 'history' => $history, 'timeline' => array_slice($timeline, 0, 80),
        'transitions' => $transitions, 'student' => $student, 'enquiry' => $enquiry, 'payment' => $payment,
        'default_fee' => $a['admission_fee'] !== null ? (float) $a['admission_fee'] : admission_default_fee((int) $a['program_id'], $a['academic_session_id'] ? (int) $a['academic_session_id'] : null),
        'doc_types' => array_map(fn ($k2, $v) => ['value' => $k2, 'label' => $v], array_keys(admission_doc_types()), admission_doc_types()),
        'can' => [
            'edit' => can('admissions', 'edit'), 'approve' => can('admissions', 'approve'), 'delete' => can('admissions', 'delete'),
            'fee' => can('fees', 'create'), 'convert' => can('admissions', 'edit') && can('students', 'create'), 'view_student' => can('students', 'view'),
        ],
    ]);
});

/* ---------------------------------------------------------------- Profile actions */
route('POST', '/admissions/{id:\d+}/assign', function ($p) {
    api_require('admissions', 'edit');
    $a = adm_api_find((int) $p['id']);
    $uid = (int) (api_input()['assigned_to'] ?? 0);
    if ($uid && !db_value("SELECT COUNT(*) FROM users WHERE id = ? AND status = 'active'", [$uid])) {
        api_error('Select a valid counsellor.', 422, ['assigned_to' => 'Select a valid counsellor.']);
    }
    db_update('admissions', ['assigned_to' => $uid ?: null], 'id = ?', [(int) $a['id']]);
    $who = $uid ? (string) db_value('SELECT name FROM users WHERE id = ?', [$uid]) : 'nobody';
    log_activity('update', 'admissions', $a['id'], sprintf('Assigned %s (%s) to %s', admission_full_name($a), $a['application_no'], $who));
    if ($uid && $uid !== (int) user_id()) {
        notify($uid, 'application', 'Application assigned to you', admission_full_name($a) . ' (' . $a['application_no'] . ')', 'admin/admissions/' . $a['id'], 'user-plus');
    }
    api_ok(null, $uid ? 'Counsellor assigned: ' . $who . '.' : 'Counsellor removed.');
});

route('POST', '/admissions/{id:\d+}/notes', function ($p) {
    api_require('admissions', 'edit');
    $a = adm_api_find((int) $p['id']);
    $data = api_validate(['counselling_notes' => 'max:5000'], null, ['counselling_notes' => 'Counselling notes']);
    db_update('admissions', ['counselling_notes' => $data['counselling_notes']], 'id = ?', [(int) $a['id']]);
    log_activity('update', 'admissions', $a['id'], sprintf('Updated counselling notes of %s (%s)', admission_full_name($a), $a['application_no']));
    api_ok(null, 'Counselling notes saved.');
});

route('POST', '/admissions/{id:\d+}/assessment', function ($p) {
    api_require('admissions', 'edit');
    $a = adm_api_find((int) $p['id']);
    if ($a['student_id'] || in_array($a['stage'], ['rejected', 'withdrawn'], true)) {
        api_error('Assessment scores cannot be changed for ' . ($a['student_id'] ? 'enrolled applicants.' : 'closed applications.'), 422);
    }
    $data = api_validate([
        'entrance_exam' => 'max:60', 'entrance_score' => 'numeric|min:0|max:1000', 'interview_date' => 'date',
        'interview_score' => 'numeric|min:0|max:100', 'interview_remarks' => 'max:500',
    ], null, ['interview_date' => 'Interview date']);
    if ($data['entrance_score'] === null && $data['interview_score'] === null) {
        api_error('Enter the entrance score, the interview score or both.', 422, ['entrance_score' => 'Enter at least one score.']);
    }
    $data['interview_date'] = $data['interview_date'] ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $data['interview_date']))) : null;
    db_update('admissions', $data, 'id = ?', [(int) $a['id']]);
    log_activity('update', 'admissions', $a['id'], sprintf('Saved assessment for %s (%s): entrance %s, interview %s', admission_full_name($a), $a['application_no'],
        $data['entrance_score'] ?? '—', $data['interview_score'] ?? '—'));
    api_ok(null, 'Assessment saved.');
});

route('POST', '/admissions/{id:\d+}/fee', function ($p) {
    api_require('fees', 'create');
    $row = admission_record_fee((int) $p['id'], api_input());
    api_ok(['receipt_no' => $row['fee_receipt_no'], 'fee_paid' => (float) $row['fee_paid'], 'admission_fee' => (float) $row['admission_fee']],
        'Admission fee recorded. Receipt ' . $row['fee_receipt_no'] . ' generated.');
});

route('POST', '/admissions/{id:\d+}/convert', function ($p) {
    api_require('admissions', 'edit');
    api_require('students', 'create');
    $res = admission_convert((int) $p['id']);
    api_ok($res, 'Student ' . $res['student_uid'] . ' created. Admission confirmed.');
});

/* ---------------------------------------------------------------- Documents */
route('POST', '/admissions/{id:\d+}/documents', function ($p) {
    api_require('admissions', 'edit');
    $a = adm_api_find((int) $p['id']);
    if ($a['student_id']) {
        api_error('Documents of enrolled students are managed from the student profile.', 422);
    }
    $type = (string) (api_input()['doc_type'] ?? '');
    $errors = [];
    if (!isset(admission_doc_types()[$type])) {
        $errors['doc_type'] = 'Select the document type.';
    }
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors['file'] = 'Choose a file to upload.';
    }
    $existing = $type !== 'other' && !$errors ? db_row('SELECT * FROM admission_documents WHERE admission_id = ? AND doc_type = ? ORDER BY id DESC LIMIT 1', [(int) $a['id'], $type]) : null;
    if ($existing && $existing['status'] === 'verified') {
        $errors['file'] = 'A verified ' . strtolower(admission_doc_types()[$type]) . ' is already on file. Delete it first to replace it.';
    }
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $res = upload_file($_FILES['file'], 'any', 'admission-docs', true);
    if (!$res['ok']) {
        api_error($res['error'], 422, ['file' => $res['error']]);
    }
    $row = ['admission_id' => (int) $a['id'], 'doc_type' => $type, 'file_path' => $res['path'], 'original_name' => $res['original_name'], 'mime' => $res['mime'],
        'size_bytes' => $res['size'], 'status' => 'pending', 'remarks' => mb_substr(trim((string) (api_input()['remarks'] ?? '')), 0, 255) ?: null, 'verified_by' => null, 'verified_at' => null];
    if ($existing) {
        db_update('admission_documents', $row, 'id = ?', [(int) $existing['id']]);
        delete_upload($existing['file_path']);
        $docId = (int) $existing['id'];
    } else {
        $docId = db_insert('admission_documents', $row);
    }
    if ($type === 'photo' && !$a['photo'] && in_array($res['extension'], ['jpg', 'jpeg', 'png', 'webp'], true)) {
        // Public copy of the passport photo for avatars / print views
        $dir = 'assets/uploads/admissions/' . date('Y/m');
        @mkdir(APP_ROOT . '/' . $dir, 0775, true);
        $dest = $dir . '/' . bin2hex(random_bytes(16)) . '.' . $res['extension'];
        if (@copy(APP_ROOT . '/' . $res['path'], APP_ROOT . '/' . $dest)) {
            db_update('admissions', ['photo' => $dest], 'id = ?', [(int) $a['id']]);
        }
    }
    log_activity('create', 'admissions', $a['id'], sprintf('Uploaded %s for %s (%s)', strtolower(admission_doc_types()[$type]), admission_full_name($a), $a['application_no']));
    api_ok(['id' => $docId], admission_doc_types()[$type] . ($existing ? ' replaced.' : ' uploaded.'));
});

route('POST', '/admissions/documents/{doc:\d+}/verify', function ($p) {
    api_require('admissions', 'edit');
    $d = db_row('SELECT d.*, a.application_no, a.first_name, a.middle_name, a.last_name, a.student_id FROM admission_documents d JOIN admissions a ON a.id = d.admission_id WHERE d.id = ?', [(int) $p['doc']]);
    if (!$d) {
        api_error('Document not found.', 404);
    }
    $in = api_input();
    $status = (string) ($in['status'] ?? '');
    $remarks = trim((string) ($in['remarks'] ?? ''));
    if (!in_array($status, ['verified', 'rejected', 'pending'], true)) {
        api_error('Select verified or rejected.', 422, ['status' => 'Select verified or rejected.']);
    }
    if ($status === 'rejected' && $remarks === '') {
        api_error('Enter the reason the document was rejected.', 422, ['remarks' => 'Enter the reason the document was rejected.']);
    }
    if (mb_strlen($remarks) > 255) {
        api_error('Remarks may not be longer than 255 characters.', 422, ['remarks' => 'Remarks may not be longer than 255 characters.']);
    }
    if ($d['student_id']) {
        api_error('Documents of enrolled students can no longer be changed here.', 422);
    }
    db_update('admission_documents', ['status' => $status, 'remarks' => $remarks ?: null, 'verified_by' => $status === 'pending' ? null : user_id(),
        'verified_at' => $status === 'pending' ? null : date('Y-m-d H:i:s')], 'id = ?', [(int) $d['id']]);
    $label = admission_doc_types()[$d['doc_type']] ?? 'Document';
    log_activity($status === 'verified' ? 'approve' : 'update', 'admissions', $d['admission_id'], sprintf('%s %s of %s (%s)%s', ucfirst($status === 'pending' ? 'reset' : $status), strtolower($label),
        admission_full_name($d), $d['application_no'], $remarks ? ' — ' . $remarks : ''));
    api_ok(['status' => $status], $label . ' ' . ($status === 'pending' ? 'reset to pending.' : $status . '.'));
});

route('DELETE', '/admissions/documents/{doc:\d+}', function ($p) {
    api_require('admissions', 'edit');
    $d = db_row('SELECT d.*, a.application_no, a.first_name, a.middle_name, a.last_name, a.stage, a.student_id FROM admission_documents d JOIN admissions a ON a.id = d.admission_id WHERE d.id = ?', [(int) $p['doc']]);
    if (!$d) {
        api_error('Document not found.', 404);
    }
    if ($d['student_id']) {
        api_error('Documents of enrolled students are kept on record.', 409);
    }
    db_delete('admission_documents', 'id = ?', [(int) $d['id']]);
    delete_upload($d['file_path']);
    $label = admission_doc_types()[$d['doc_type']] ?? 'Document';
    log_activity('delete', 'admissions', $d['admission_id'], sprintf('Deleted %s of %s (%s)', strtolower($label), admission_full_name($d), $d['application_no']));
    api_ok(null, $label . ' deleted.');
});

/* ---------------------------------------------------------------- Follow-ups */
route('POST', '/admissions/{id:\d+}/followups', function ($p) {
    api_require('admissions', 'edit');
    $a = adm_api_find((int) $p['id']);
    $fid = followup_log(['admission_id' => (int) $a['id']] + api_input());
    $type = admission_followup_types()[api_input()['type']] ?? 'Follow-up';
    log_activity('create', 'admissions', $a['id'], sprintf('Logged %s with %s (%s)', strtolower($type), admission_full_name($a), $a['application_no']));
    $next = api_input()['next_followup_date'] ?? null;
    api_ok(['id' => $fid], 'Follow-up logged.' . ($next ? ' Next follow-up on ' . format_date($next) . '.' : ''));
});

route('POST', '/admissions/followups/{fid:\d+}/done', function ($p) {
    $f = db_row('SELECT * FROM admission_followups WHERE id = ?', [(int) $p['fid']]);
    if (!$f) {
        api_error('Follow-up not found.', 404);
    }
    api_require($f['enquiry_id'] && !$f['admission_id'] ? 'enquiries' : 'admissions', 'edit');
    followup_complete((int) $f['id']);
    log_activity('update', $f['admission_id'] ? 'admissions' : 'enquiries', $f['admission_id'] ?: $f['enquiry_id'], 'Marked follow-up due ' . format_date($f['next_followup_date']) . ' as done');
    api_ok(null, 'Follow-up marked as done.');
});

route('DELETE', '/admissions/followups/{fid:\d+}', function ($p) {
    $f = db_row('SELECT * FROM admission_followups WHERE id = ?', [(int) $p['fid']]);
    if (!$f) {
        api_error('Follow-up not found.', 404);
    }
    api_require($f['enquiry_id'] && !$f['admission_id'] ? 'enquiries' : 'admissions', 'edit');
    if ((int) $f['created_by'] !== (int) user_id() && !can('admissions', 'delete') && !can('enquiries', 'delete')) {
        api_error('You can only delete follow-ups you logged.', 403);
    }
    db_delete('admission_followups', 'id = ?', [(int) $f['id']]);
    log_activity('delete', $f['admission_id'] ? 'admissions' : 'enquiries', $f['admission_id'] ?: $f['enquiry_id'], 'Deleted follow-up note: ' . str_limit($f['notes'], 80));
    api_ok(null, 'Follow-up deleted.');
});
