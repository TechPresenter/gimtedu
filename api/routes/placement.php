<?php
/**
 * Placement cell endpoints (CRUD lists/forms go through /api/crud/{companies|placement_drives|placement_applications|
 * placement_interviews|placement_offers|placement_trainings}).
 *
 *   GET    /placement/dashboard?session_id=                 KPIs, department %, package bands, company-wise & month-wise offers, upcoming drives, top recruiters
 *   GET    /placement/stats/{scope}                         KPI strip of a list page: companies|drives|applications|offers|trainings
 *   GET    /placement/companies/{id}/history               company profile + drives, offers by session, recent hires
 *   GET    /placement/drives/{id}                           drive detail: eligibility, programs, status counts, round funnel, offers
 *   GET    /placement/drives/{id}/eligible?q&program_id&show=all|registered|unregistered&page&per_page&sort
 *   POST   /placement/drives/{id}/register                  {student_ids: [], all_eligible: bool, override: bool}
 *   GET    /placement/applications/{id}                     application + student + interviews + offer
 *   POST   /placement/applications/{id}/rounds              {round_name, scheduled_at, mode, panel, result, score, feedback}
 *   POST   /placement/applications/bulk                     {action: shortlist|advance|select|reject|hold|withdraw|reopen, ids: [], date, remarks}
 *   POST   /placement/offers/{id}/status                    {status: accepted|joined|declined|revoked|offered, remarks, date}
 *   POST   /placement/offers/bulk-status                    {ids: [], status}
 *   GET    /placement/trainings/{id}/participants?q&attended&page&per_page
 *   POST   /placement/trainings/{id}/participants           {student_ids: [], program_id, semester_no}
 *   POST   /placement/trainings/{id}/scores                 {rows: [{id, attended, score, remarks}]}
 *   DELETE /placement/trainings/{id}/participants/{pid}
 */

require_once APP_ROOT . '/app/services/placement.php';

route('GET', '/placement/dashboard', function () {
    api_require('placement', 'view');
    $sid = (int) ($_GET['session_id'] ?? 0);
    api_ok(placement_dashboard($sid ?: null));
});

route('GET', '/placement/stats/{scope:[a-z]+}', function ($p) {
    api_require('placement', 'view');
    api_ok(placement_page_stats($p['scope']));
});

route('GET', '/placement/companies/{id:\d+}/history', function ($p) {
    api_require('placement', 'view');
    $h = placement_company_history((int) $p['id']);
    if (!$h) {
        api_error('Company not found.', 404);
    }
    api_ok($h);
});

route('GET', '/placement/drives/{id:\d+}', function ($p) {
    api_require('placement', 'view');
    $d = placement_drive_detail((int) $p['id']);
    if (!$d) {
        api_error('Placement drive not found.', 404);
    }
    $d['can'] = ['edit' => can('placement', 'edit'), 'create' => can('placement', 'create'), 'delete' => can('placement', 'delete')];
    api_ok($d);
});

route('GET', '/placement/drives/{id:\d+}/eligible', function ($p) {
    api_require('placement', 'view');
    $drive = placement_drive_row((int) $p['id']);
    if (!$drive) {
        api_error('Placement drive not found.', 404);
    }
    api_ok(placement_eligible_list($drive, $_GET));
});

route('POST', '/placement/drives/{id:\d+}/register', function ($p) {
    api_require('placement', 'create');
    $in = api_input();
    $all = in_array($in['all_eligible'] ?? false, [true, 1, '1', 'true'], true);
    $override = in_array($in['override'] ?? false, [true, 1, '1', 'true'], true);
    $res = placement_register_students((int) $p['id'], (array) ($in['student_ids'] ?? []), $all, $override);
    $msg = $res['registered'] ? sprintf('%d student%s registered for the drive.', $res['registered'], $res['registered'] === 1 ? '' : 's') : 'No new students were registered.';
    if ($res['already']) {
        $msg .= sprintf(' %d already registered.', $res['already']);
    }
    if ($res['ineligible']) {
        $msg .= sprintf(' %d not eligible.', $res['ineligible']);
    }
    api_ok($res, $msg);
});

route('GET', '/placement/applications/options', function () {
    api_require('placement', 'view');
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = ["a.status <> 'withdrawn'"];
    $args = [];
    if ($q !== '') {
        foreach (preg_split('/\s+/', mb_substr($q, 0, 80)) as $word) {
            $where[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_uid LIKE ? OR c.name LIKE ?)';
            array_push($args, "%$word%", "%$word%", "%$word%", "%$word%");
        }
    }
    if (!empty($_GET['ids'])) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $_GET['ids']))));
        $where = $ids ? ['a.id IN (' . implode(',', $ids) . ')'] : ['1 = 0'];
        $args = [];
    }
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 30)));
    $rows = db_all("SELECT a.id AS value, CONCAT(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)), ' → ', c.name) AS label,
                           CONCAT(s.student_uid, ' · ', d.job_role, ' · ', a.status) AS sub
                    FROM placement_applications a JOIN students s ON s.id = a.student_id JOIN placement_drives d ON d.id = a.drive_id JOIN companies c ON c.id = d.company_id
                    WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(a.status, 'selected', 'rejected') ASC, d.drive_date DESC, s.first_name LIMIT $limit", $args);
    foreach ($rows as &$r) {
        $r['value'] = (int) $r['value'];
    }
    api_ok($rows);
});

route('GET', '/placement/applications/{id:\d+}', function ($p) {
    api_require('placement', 'view');
    $d = placement_application_detail((int) $p['id']);
    if (!$d) {
        api_error('Application not found.', 404);
    }
    api_ok($d);
});

route('POST', '/placement/applications/{id:\d+}/rounds', function ($p) {
    api_require('placement', 'edit');
    $in = api_input();
    $res = placement_record_round((int) $p['id'], $in);
    $msg = ($in['result'] ?? '') === 'pending'
        ? trim((string) ($in['round_name'] ?? 'Round')) . ' scheduled.'
        : match ($res['status']) {
            'selected' => 'Round recorded — the student cleared the final round and is selected.',
            'rejected' => 'Round recorded — the student did not clear this round.',
            default => 'Round recorded.' . ($res['current_round'] ? ' Next: ' . $res['current_round'] . '.' : ''),
        };
    api_ok($res, $msg);
});

route('POST', '/placement/applications/bulk', function () {
    api_require('placement', 'edit');
    $in = api_input();
    $res = placement_bulk_applications((string) ($in['action'] ?? ''), (array) ($in['ids'] ?? []), $in);
    api_ok($res, $res['message']);
});

route('POST', '/placement/offers/{id:\d+}/status', function ($p) {
    api_require('placement', 'edit');
    $in = api_input();
    api_validate(['status' => 'required|in:offered,accepted,declined,joined,revoked', 'remarks' => 'max:255', 'date' => 'date'], $in);
    $row = placement_set_offer_status((int) $p['id'], (string) $in['status'], $in['remarks'] ?? null, $in['date'] ?? null);
    $msg = ['accepted' => 'Student marked placed — offer accepted.', 'joined' => 'Marked as joined.', 'declined' => 'Offer marked declined.', 'revoked' => 'Offer revoked.', 'offered' => 'Offer reinstated.'][$in['status']];
    api_ok($row, $msg);
});

route('POST', '/placement/offers/bulk-status', function () {
    api_require('placement', 'edit');
    $in = api_input();
    api_validate(['status' => 'required|in:offered,accepted,declined,joined,revoked'], $in);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));
    if (!$ids) {
        api_error('Select at least one offer.', 422);
    }
    $done = 0;
    $skipped = 0;
    foreach ($ids as $id) {
        try {
            placement_set_offer_status($id, (string) $in['status']);
            $done++;
        } catch (CrudException $e) {
            $skipped++;
        }
    }
    $label = ['accepted' => 'marked placed', 'joined' => 'marked joined', 'declined' => 'marked declined', 'revoked' => 'revoked', 'offered' => 'reinstated'][$in['status']];
    api_ok(['updated' => $done, 'skipped' => $skipped], sprintf('%d offer%s %s.', $done, $done === 1 ? '' : 's', $label) . ($skipped ? sprintf(' %d skipped (status change not allowed).', $skipped) : ''));
});

route('GET', '/placement/trainings/{id:\d+}/participants', function ($p) {
    api_require('placement', 'view');
    $t = db_row('SELECT t.*, p.short_name AS program_short FROM placement_trainings t LEFT JOIN programs p ON p.id = t.program_id WHERE t.id = ?', [(int) $p['id']]);
    if (!$t) {
        api_error('Training not found.', 404);
    }
    api_ok(placement_training_participants((int) $p['id'], $_GET) + ['training' => $t]);
});

route('POST', '/placement/trainings/{id:\d+}/participants', function ($p) {
    api_require('placement', 'edit');
    $in = api_input();
    api_validate(['program_id' => 'integer|exists:programs,id', 'semester_no' => 'integer|min:1|max:12'], $in, ['program_id' => 'Program', 'semester_no' => 'Semester']);
    $res = placement_add_participants((int) $p['id'], (array) ($in['student_ids'] ?? []), !empty($in['program_id']) ? (int) $in['program_id'] : null, !empty($in['semester_no']) ? (int) $in['semester_no'] : null);
    $msg = $res['added'] ? sprintf('%d participant%s added.', $res['added'], $res['added'] === 1 ? '' : 's') : 'No new participants were added.';
    if ($res['already']) {
        $msg .= sprintf(' %d already enrolled.', $res['already']);
    }
    api_ok($res, $msg);
});

route('POST', '/placement/trainings/{id:\d+}/scores', function ($p) {
    api_require('placement', 'edit');
    $rows = (array) (api_input()['rows'] ?? []);
    if (!$rows) {
        api_error('Nothing to save.', 422);
    }
    $n = placement_save_scores((int) $p['id'], $rows);
    api_ok(['updated' => $n], sprintf('Attendance and scores saved for %d participant%s.', $n, $n === 1 ? '' : 's'));
});

route('DELETE', '/placement/trainings/{id:\d+}/participants/{pid:\d+}', function ($p) {
    api_require('placement', 'edit');
    $tid = (int) $p['id'];
    $row = db_row("SELECT tp.id, t.title, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name FROM placement_training_participants tp
                   JOIN placement_trainings t ON t.id = tp.training_id JOIN students s ON s.id = tp.student_id WHERE tp.id = ? AND tp.training_id = ?", [(int) $p['pid'], $tid]);
    if (!$row) {
        api_error('Participant not found.', 404);
    }
    db_transaction(function () use ($p, $tid) {
        db_delete('placement_training_participants', 'id = ?', [(int) $p['pid']]);
        placement_refresh_training_count($tid);
    });
    log_activity('update', 'placement', $tid, 'Removed ' . $row['name'] . ' from "' . $row['title'] . '"');
    api_ok(null, $row['name'] . ' removed from the session.');
});
