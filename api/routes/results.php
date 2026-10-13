<?php
/**
 * Results & analysis API (brief section 16). Results are computed by the exam workflow (api/routes/exams.php: verify / publish);
 * the paginated, filterable list itself is the CRUD module "results" (/api/crud/results?scope[exam_id]=...).
 *
 *   GET  /results/exams?session_id&program_id&semester&q        exams that have computed results (picker + session table)
 *   GET  /results/overview?exam_id=                             counts, toppers, grade distribution, bands, subject analysis
 *   GET  /results/session?session_id=                           session-level analysis (program pass %, trend)
 *   GET  /results/{id}                                          one result with subject-wise marks and the student's history
 *   POST /results/{id}/withhold   {reason}
 *   POST /results/{id}/release
 */
require_once APP_ROOT . '/app/services/exams.php';

/** Withholding / releasing a result is an exam-controller decision (results: approve or manage) - not marks entry. */
function results_api_can_withhold(): bool
{
    return can('results', 'approve') || can('results', 'manage');
}

function results_api_find(array $p): array
{
    $r = result_find((int) $p['id']);
    if (!$r) {
        api_error('Result not found.', 404);
    }
    return $r;
}

route('GET', '/results/exams', function () {
    api_require('results', 'view');
    $where = ["e.status <> 'cancelled'", 'EXISTS (SELECT 1 FROM results r0 WHERE r0.exam_id = e.id)'];
    $args = [];
    $sid = array_key_exists('session_id', $_GET) ? (int) $_GET['session_id'] : (int) current_session_id();
    if ($sid) {
        $where[] = 'e.academic_session_id = ?';
        $args[] = $sid;
    }
    foreach (['program_id' => 'e.program_id', 'semester' => 'e.semester_no', 'exam_type_id' => 'e.exam_type_id'] as $k => $col) {
        if (!empty($_GET[$k])) {
            $where[] = "$col = ?";
            $args[] = (int) $_GET[$k];
        }
    }
    if (($_GET['status'] ?? '') !== '') {
        $where[] = 'e.status = ?';
        $args[] = (string) $_GET['status'];
    }
    if (trim((string) ($_GET['q'] ?? '')) !== '') {
        $where[] = '(e.name LIKE ? OR p.short_name LIKE ?)';
        array_push($args, '%' . trim($_GET['q']) . '%', '%' . trim($_GET['q']) . '%');
    }
    $rows = db_all("SELECT e.id, e.name, e.status, e.program_id, e.semester_no, e.start_date, e.end_date, e.result_date, e.published_at, et.name AS type_name, et.is_final,
                           p.short_name AS program_short, ss.name AS session_name,
                           CONCAT(COALESCE(p.short_name, 'All programs'), IFNULL(CONCAT(' · Sem ', e.semester_no), '')) AS cohort,
                           COUNT(r.id) AS total, COALESCE(SUM(r.result_status = 'PASS'), 0) AS pass, COALESCE(SUM(r.result_status IN ('BACKLOG','FAIL')), 0) AS failed,
                           COALESCE(SUM(r.result_status NOT IN ('ABSENT','WITHHELD')), 0) AS appeared, COALESCE(SUM(r.result_status = 'WITHHELD'), 0) AS withheld,
                           AVG(r.percentage) AS avg_percent, AVG(r.sgpa) AS avg_sgpa, MAX(r.percentage) AS top_percent,
                           (SELECT COUNT(*) FROM marksheets m JOIN results r2 ON r2.id = m.result_id WHERE r2.exam_id = e.id AND m.status = 'issued') AS marksheets
                    FROM exams e JOIN exam_types et ON et.id = e.exam_type_id LEFT JOIN programs p ON p.id = e.program_id LEFT JOIN academic_sessions ss ON ss.id = e.academic_session_id
                    LEFT JOIN results r ON r.exam_id = e.id
                    WHERE " . implode(' AND ', $where) . '
                    GROUP BY e.id, e.name, e.status, e.program_id, e.semester_no, e.start_date, e.end_date, e.result_date, e.published_at, et.name, et.is_final, p.short_name, ss.name, p.sort_order
                    ORDER BY e.end_date DESC, p.sort_order, e.semester_no LIMIT 300', $args);
    foreach ($rows as &$r) {
        foreach (['id', 'program_id', 'semester_no', 'is_final', 'total', 'pass', 'failed', 'appeared', 'withheld', 'marksheets'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
        $r['pass_percent'] = $r['appeared'] ? round(100 * $r['pass'] / $r['appeared'], 1) : null;
        $r['avg_percent'] = $r['avg_percent'] !== null ? round((float) $r['avg_percent'], 1) : null;
        $r['avg_sgpa'] = $r['avg_sgpa'] !== null ? round((float) $r['avg_sgpa'], 2) : null;
        $r['top_percent'] = $r['top_percent'] !== null ? (float) $r['top_percent'] : null;
    }
    unset($r);
    api_ok(['rows' => $rows, 'session_id' => $sid ?: null]);
});

route('GET', '/results/overview', function () {
    api_require('results', 'view');
    $e = exam_find((int) ($_GET['exam_id'] ?? 0));
    if (!$e) {
        api_error('Choose an exam to see its results.', 404);
    }
    $o = results_overview($e);
    $o['sections'] = db_all('SELECT sc.id, sc.name, COUNT(*) AS students FROM results r JOIN students s ON s.id = r.student_id JOIN sections sc ON sc.id = s.section_id
                             WHERE r.exam_id = ? GROUP BY sc.id, sc.name ORDER BY sc.name', [$e['id']]);
    $o['permissions'] = ['withhold' => results_api_can_withhold(), 'publish' => can('results', 'publish'), 'verify' => can('results', 'approve'),
        'marksheets' => marksheets_can_issue(), 'export' => can('results', 'export')];
    api_ok($o);
});

route('GET', '/results/session', function () {
    api_require('results', 'view');
    $sid = isset($_GET['session_id']) && $_GET['session_id'] !== '' ? (int) $_GET['session_id'] : (int) current_session_id();
    $programs = db_all("SELECT p.short_name AS label, COUNT(r.id) AS total, COALESCE(SUM(r.result_status = 'PASS'), 0) AS pass,
                               COALESCE(SUM(r.result_status NOT IN ('ABSENT','WITHHELD')), 0) AS appeared, AVG(r.sgpa) AS avg_sgpa, AVG(r.percentage) AS avg_percent
                        FROM results r JOIN exams e ON e.id = r.exam_id JOIN programs p ON p.id = e.program_id
                        WHERE r.is_published = 1 AND e.academic_session_id = ? GROUP BY p.id, p.short_name, p.sort_order ORDER BY p.sort_order", [$sid]);
    foreach ($programs as &$p) {
        $p['pass_percent'] = (int) $p['appeared'] ? round(100 * (int) $p['pass'] / (int) $p['appeared'], 1) : 0;
        $p['avg_sgpa'] = $p['avg_sgpa'] !== null ? round((float) $p['avg_sgpa'], 2) : null;
        $p['avg_percent'] = $p['avg_percent'] !== null ? round((float) $p['avg_percent'], 1) : null;
        $p['total'] = (int) $p['total'];
    }
    unset($p);
    $status = db_pairs("SELECT r.result_status, COUNT(*) FROM results r JOIN exams e ON e.id = r.exam_id WHERE r.is_published = 1 AND e.academic_session_id = ? GROUP BY r.result_status", [$sid]);
    $trend = db_all("SELECT ss.name AS label, ROUND(100 * SUM(r.result_status = 'PASS') / NULLIF(SUM(r.result_status NOT IN ('ABSENT','WITHHELD')), 0), 1) AS pass_percent, ROUND(AVG(r.sgpa), 2) AS avg_sgpa
                     FROM results r JOIN exams e ON e.id = r.exam_id JOIN exam_types et ON et.id = e.exam_type_id JOIN academic_sessions ss ON ss.id = e.academic_session_id
                     WHERE r.is_published = 1 AND et.is_final = 1 GROUP BY ss.id, ss.name, ss.start_date ORDER BY ss.start_date");
    $toppers = db_all("SELECT r.id, r.exam_id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.student_uid, s.photo, p.short_name AS program_short, r.semester_no, r.sgpa, r.cgpa, r.percentage, e.name AS exam_name
                       FROM results r JOIN students s ON s.id = r.student_id JOIN exams e ON e.id = r.exam_id JOIN exam_types et ON et.id = e.exam_type_id LEFT JOIN programs p ON p.id = r.program_id
                       WHERE r.is_published = 1 AND et.is_final = 1 AND r.result_status = 'PASS' AND e.academic_session_id = ? ORDER BY r.sgpa DESC, r.percentage DESC LIMIT 5", [$sid]);
    $toppersBasis = 'sgpa';
    if (!$toppers) {
        // No end-semester result published yet in this session (e.g. only mid-terms): rank by percentage instead
        $toppersBasis = 'percentage';
        $toppers = db_all("SELECT r.id, r.exam_id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.student_uid, s.photo, p.short_name AS program_short, r.semester_no, r.sgpa, r.cgpa, r.percentage, e.name AS exam_name
                           FROM results r JOIN students s ON s.id = r.student_id JOIN exams e ON e.id = r.exam_id LEFT JOIN programs p ON p.id = r.program_id
                           WHERE r.is_published = 1 AND r.result_status = 'PASS' AND e.academic_session_id = ? ORDER BY r.percentage DESC, r.total_marks DESC LIMIT 5", [$sid]);
    }
    foreach ($toppers as &$t) {
        foreach (['sgpa', 'cgpa', 'percentage'] as $k) {
            $t[$k] = $t[$k] === null ? null : (float) $t[$k];
        }
        $t['id'] = (int) $t['id'];
    }
    unset($t);
    $pending = db_all("SELECT e.id, e.name, e.status, CONCAT(COALESCE(p.short_name, 'All programs'), IFNULL(CONCAT(' · Sem ', e.semester_no), '')) AS cohort, e.end_date
                       FROM exams e LEFT JOIN programs p ON p.id = e.program_id
                       WHERE e.academic_session_id = ? AND (e.status IN ('marks_entry','verified') OR (e.status IN ('scheduled','ongoing') AND e.end_date < ?))
                       ORDER BY e.end_date, e.id LIMIT 12", [$sid, exam_today()]);
    api_ok(['session_id' => $sid, 'programs' => $programs, 'status' => array_map('intval', $status), 'trend' => array_map(fn ($t) => ['label' => $t['label'], 'pass_percent' => (float) $t['pass_percent'], 'avg_sgpa' => $t['avg_sgpa'] !== null ? (float) $t['avg_sgpa'] : null], $trend),
        'toppers' => $toppers, 'toppers_basis' => $toppersBasis, 'pending' => $pending]);
});

route('GET', '/results/{id:\d+}', function ($p) {
    api_require('results', 'view');
    $r = results_api_find($p);
    foreach (['percentage', 'sgpa', 'cgpa', 'total_marks', 'max_marks', 'credits_registered', 'credits_earned'] as $k) {
        $r[$k] = $r[$k] === null ? null : (float) $r[$k];
    }
    $r['id'] = (int) $r['id'];
    $r['is_published'] = (bool) $r['is_published'];
    $subjects = array_map(function ($s) {
        foreach (['internal_marks', 'external_marks', 'practical_marks', 'total_marks', 'grade_point', 'credits'] as $k) {
            $s[$k] = $s[$k] === null ? null : (float) $s[$k];
        }
        $s['is_absent'] = (bool) $s['is_absent'];
        $s['is_pass'] = $s['is_pass'] === null ? null : (bool) $s['is_pass'];
        return $s;
    }, result_subject_marks($r));
    api_ok(['result' => $r, 'subjects' => $subjects, 'history' => result_student_history((int) $r['student_id']),
        'can_withhold' => results_api_can_withhold()]);
});

route('POST', '/results/{id:\d+}/withhold', function ($p) {
    if (!results_api_can_withhold()) {
        api_error('You do not have permission to withhold results.', 403);
    }
    $r = results_api_find($p);
    $in = api_validate(['reason' => 'required|min:5|max:255'], null, ['reason' => 'Reason']);
    if (!in_array($r['exam_status'], ['verified', 'published'], true)) {
        api_error('Results can be withheld once the marks are verified.', 422);
    }
    result_withhold($r, (string) $in['reason']);
    $e = exam_find((int) $r['exam_id']);
    if ($e && $e['status'] === 'published') {
        db_exec('UPDATE student_academic sa SET sa.sgpa = NULL, sa.cgpa = NULL WHERE sa.student_id = ? AND sa.semester_no = ? AND sa.academic_session_id = ?', [(int) $r['student_id'], (int) $r['semester_no'], (int) $r['academic_session_id']]);
    }
    log_activity('update', 'results', (int) $r['exam_id'], 'Withheld the result of ' . $r['student_name'] . ' (' . $r['student_uid'] . ') in "' . $r['exam_name'] . '": ' . $in['reason']);
    api_ok(null, 'Result withheld for ' . $r['student_name'] . '.');
});

route('POST', '/results/{id:\d+}/release', function ($p) {
    if (!results_api_can_withhold()) {
        api_error('You do not have permission to release results.', 403);
    }
    $r = results_api_find($p);
    db_transaction(fn () => result_unwithhold($r));
    $fresh = result_find((int) $r['id']);
    log_activity('update', 'results', (int) $r['exam_id'], 'Released the withheld result of ' . $r['student_name'] . ' (' . $r['student_uid'] . ') in "' . $r['exam_name'] . '"');
    api_ok(['result_status' => $fresh['result_status'] ?? null], 'Result released — status recalculated as ' . ($fresh['result_status'] ?? 'PASS') . '.');
});
