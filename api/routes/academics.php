<?php
/**
 * Academics structure endpoints (CRUD for each table lives at /api/crud/{module}).
 *   GET  /api/academics/overview?session_id=          KPIs + department/program/course tree
 *   GET  /api/academics/programs/{id}/tree?session_id= semesters -> subjects -> faculty -> students of one program
 *   GET  /api/academics/workload?session_id=&department_id=&q=   faculty teaching load
 *   GET  /api/academics/unassigned?session_id=&program_id=&department_id=   subjects of sections with no faculty
 *   POST /api/academics/sessions/{id}/current          make a session the current one (exactly one)
 *   POST /api/academics/assign                         {section_id, subject_id, faculty_id, session_id?} quick assignment
 */
require_once APP_ROOT . '/app/services/academics.php';

route('GET', '/academics/overview', function () {
    api_require('academics', 'view');
    api_ok(acad_overview(acad_request_session_id()));
});

route('GET', '/academics/programs/{id:\d+}/tree', function ($p) {
    api_require('academics', 'view');
    $tree = acad_program_tree((int) $p['id'], acad_request_session_id());
    if (!$tree) {
        api_error('Program not found.', 404);
    }
    api_ok($tree);
});

route('GET', '/academics/workload', function () {
    api_require('academics', 'view');
    $sid = acad_request_session_id();
    $rows = acad_workload($sid, ['department_id' => $_GET['department_id'] ?? null, 'q' => trim((string) ($_GET['q'] ?? ''))]);
    $assigned = array_filter($rows, fn ($r) => $r['planned_hours'] > 0);
    api_ok([
        'rows' => $rows,
        'stats' => [
            'faculty' => count($rows),
            'assigned' => count($assigned),
            'free' => count($rows) - count($assigned),
            'overloaded' => count(array_filter($rows, fn ($r) => $r['load'] === 'overloaded')),
            'avg_hours' => $assigned ? round(array_sum(array_column($assigned, 'planned_hours')) / count($assigned), 1) : 0,
            'max_hours' => $rows ? $rows[0]['max_hours'] : (int) setting('max_teaching_hours', 24),
        ],
    ]);
});

route('GET', '/academics/unassigned', function () {
    api_require('academics', 'view');
    $rows = acad_unassigned(acad_request_session_id(), ['program_id' => $_GET['program_id'] ?? null, 'department_id' => $_GET['department_id'] ?? null], 500);
    api_ok(['rows' => $rows, 'total' => count($rows)]);
});

route('POST', '/academics/sessions/{id:\d+}/current', function ($p) {
    api_require('academics', 'edit');
    $row = acad_set_current_session((int) $p['id']);
    api_ok($row, 'Session ' . $row['name'] . ' is now the current academic session.');
});

route('POST', '/academics/assign', function () {
    api_require('academics', 'create');
    $in = api_validate([
        'section_id' => 'required|integer|exists:sections,id',
        'subject_id' => 'required|integer|exists:subjects,id',
        'faculty_id' => 'required|integer|exists:faculty,id',
        'session_id' => 'integer|exists:academic_sessions,id',
    ], null, ['section_id' => 'Section', 'subject_id' => 'Subject', 'faculty_id' => 'Faculty member', 'session_id' => 'Session']);
    $m = crud_module('faculty_subjects');
    $sid = (int) ($in['session_id'] ?: (db_value('SELECT academic_session_id FROM sections WHERE id = ?', [(int) $in['section_id']]) ?: current_session_id()));
    $res = crud_save($m, ['section_id' => $in['section_id'], 'subject_id' => $in['subject_id'], 'faculty_id' => $in['faculty_id'], 'academic_session_id' => $sid, 'is_primary' => 1]);
    api_ok(['id' => $res['id']], 'Faculty assigned successfully.', 201);
});
