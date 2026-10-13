<?php
/**
 * Faculty endpoints (list/create/update/delete go through /api/crud/faculty).
 *   GET  /api/faculty/summary                       KPIs + charts for the faculty list
 *   GET  /api/faculty/{id}/profile                  profile header, stats, account, upcoming leaves, LOP
 *   GET  /api/faculty/{id}/subjects?session_id=     assigned subjects (faculty_subjects)
 *   GET  /api/faculty/{id}/timetable?session_id=    weekly timetable grid
 *   GET  /api/faculty/{id}/attendance?month=YYYY-MM employee attendance calendar + summary
 *   GET  /api/faculty/{id}/activity                 audit trail
 *   GET  /api/faculty/{id}/balance                  leave balance (current session)
 *   POST /api/faculty/{id}/account                  create login account {username?}
 *   POST /api/faculty/{id}/status                   {status}
 *   POST /api/faculty/documents/{id}/verify         {status: verified|rejected|pending, remarks}  (faculty & staff documents)
 */
require_once APP_ROOT . '/app/services/hr.php';

hr_register_employee_routes('faculty');

route('GET', '/faculty/{id:\d+}/subjects', function ($p) {
    api_require('faculty', 'view');
    hr_api_person('faculty', $p['id']);
    $sessionId = isset($_GET['session_id']) && $_GET['session_id'] !== '' ? (int) $_GET['session_id'] : current_session_id();
    if (($_GET['session_id'] ?? '') === 'all') {
        $sessionId = null;
    }
    $rows = hr_faculty_subjects((int) $p['id'], $sessionId);
    api_ok([
        'rows' => $rows, 'session_id' => $sessionId,
        'totals' => ['subjects' => count(array_unique(array_column($rows, 'subject_id'))), 'sections' => count($rows),
            'credits' => array_sum(array_map(fn ($r) => $r['credits'], $rows)), 'weekly_periods' => array_sum(array_column($rows, 'weekly_periods'))],
        'can_manage' => can('academics', 'edit'),
    ]);
});

route('GET', '/faculty/{id:\d+}/timetable', function ($p) {
    api_require('faculty', 'view');
    hr_api_person('faculty', $p['id']);
    $sessionId = !empty($_GET['session_id']) ? (int) $_GET['session_id'] : current_session_id();
    api_ok(hr_faculty_timetable((int) $p['id'], $sessionId) + ['can_manage' => can('timetable', 'edit')]);
});

route('POST', '/faculty/documents/{id:\d+}/verify', function ($p) {
    api_require('faculty', 'edit');
    $doc = db_row('SELECT * FROM employee_documents WHERE id = ?', [(int) $p['id']]);
    if (!$doc) {
        api_error('Document not found. It may have been deleted.', 404);
    }
    $v = api_validate(['status' => 'required|in:verified,rejected,pending', 'remarks' => 'max:255']);
    if ($v['status'] === 'rejected' && empty($v['remarks'])) {
        api_error('Please fix the highlighted fields and try again.', 422, ['remarks' => 'Tell the employee why the document was rejected.']);
    }
    $isPending = $v['status'] === 'pending';
    db_update('employee_documents', [
        'status' => $v['status'], 'verified_by' => $isPending ? null : user_id(), 'verified_at' => $isPending ? null : date('Y-m-d H:i:s'),
        'remarks' => $v['remarks'] ?? ($v['status'] === 'verified' ? null : $doc['remarks']),
    ], 'id = ?', [(int) $p['id']]);
    $person = hr_person($doc['employee_type'], (int) $doc['employee_id']);
    $verb = ['verified' => 'Verified', 'rejected' => 'Rejected', 'pending' => 'Reset verification of'][$v['status']];
    log_activity($v['status'] === 'verified' ? 'approve' : 'update', 'employee_documents', (int) $p['id'], $verb . ' document "' . $doc['title'] . '" of ' . ($person['full_name'] ?? '#' . $doc['employee_id']) . (!empty($v['remarks']) ? ' — ' . $v['remarks'] : ''));
    if ($v['status'] === 'rejected' && !empty($person['user_id'])) {
        notify((int) $person['user_id'], 'document', 'Document rejected: ' . $doc['title'], (string) $v['remarks'], null, 'file-x');
    }
    api_ok(['id' => (int) $p['id'], 'status' => $v['status']], 'Document "' . $doc['title'] . '" ' . ($isPending ? 'marked pending verification.' : strtolower($v['status']) . '.'));
});
