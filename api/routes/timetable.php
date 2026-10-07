<?php
/**
 * Timetable endpoints (weekly grid editor, views, conflict-checked edits).
 *   GET    /api/timetable/meta?session_id=                         days, slots, period types, session summary
 *   GET    /api/timetable/grid?view=class|faculty|room|student&id=&session_id=&published=1
 *   GET    /api/timetable/daily?day=1&session_id=&department_id=&program_id=
 *   GET    /api/timetable/cell?section_id=&day=&time_slot_id=&exclude_id=   editor options: subjects + faculty, rooms, busy flags
 *   GET    /api/timetable/free-rooms?day=&time_slot_id=&type=&min_capacity=&building=&session_id=
 *   POST   /api/timetable/check                                     conflict preview (no save)
 *   POST   /api/timetable/entries                                   create a period
 *   PUT    /api/timetable/entries/{id}                              update a period
 *   DELETE /api/timetable/entries/{id}
 *   POST   /api/timetable/entries/{id}/move                         {day_of_week, time_slot_id, swap}
 *   POST   /api/timetable/copy                                      {from_section_id, to_section_id, mode: replace|merge, session_id}
 *   POST   /api/timetable/publish                                   {section_id?, session_id, publish: bool}
 *   DELETE /api/timetable/sections/{id}?session_id=                 clear a section's timetable
 */
require_once APP_ROOT . '/app/services/timetable.php';

function tt_request_session(): int
{
    $sid = (int) (api_param('session_id') ?: 0);
    if ($sid && db_value('SELECT id FROM academic_sessions WHERE id = ?', [$sid])) {
        return $sid;
    }
    $cur = current_session_id();
    if (!$cur) {
        api_error('No academic session is configured. Create one under Academics → Sessions.', 422);
    }
    return $cur;
}

route('GET', '/timetable/meta', function () {
    api_require('timetable', 'view');
    $sid = tt_request_session();
    api_ok([
        'session_id' => $sid,
        'days' => tt_days(),
        'slots' => tt_slots(),
        'types' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(TT_TYPES), TT_TYPES),
        'summary' => tt_summary($sid),
    ]);
});

route('GET', '/timetable/grid', function () {
    api_require('timetable', 'view');
    $view = (string) ($_GET['view'] ?? 'class');
    if (!in_array($view, ['class', 'faculty', 'room', 'student'], true)) {
        api_error('Unknown timetable view.', 400);
    }
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        api_error('Select a ' . ['class' => 'section', 'faculty' => 'faculty member', 'room' => 'room', 'student' => 'student'][$view] . ' to view the timetable.', 422);
    }
    $grid = tt_grid($view, $id, tt_request_session(), !empty($_GET['published']));
    if (!$grid) {
        api_error(ucfirst($view === 'class' ? 'section' : $view) . ' not found.', 404);
    }
    api_ok($grid + ['days' => tt_days(), 'slots' => tt_slots()]);
});

route('GET', '/timetable/daily', function () {
    api_require('timetable', 'view');
    $day = (int) ($_GET['day'] ?? 0) ?: max(1, min(6, (int) date('N')));
    if ($day < 1 || $day > 7) {
        api_error('Select a valid day.', 422, ['day' => 'Select a valid day.']);
    }
    api_ok(tt_daily(tt_request_session(), $day, ['department_id' => $_GET['department_id'] ?? null, 'program_id' => $_GET['program_id'] ?? null]) + ['slots' => tt_slots(), 'days' => tt_days()]);
});

route('GET', '/timetable/cell', function () {
    api_require('timetable', 'view');
    $sid = tt_request_session();
    $section = tt_section((int) ($_GET['section_id'] ?? 0));
    if (!$section) {
        api_error('Section not found.', 404);
    }
    $day = (int) ($_GET['day'] ?? 0);
    $slot = (int) ($_GET['time_slot_id'] ?? 0);
    $exclude = (int) ($_GET['exclude_id'] ?? 0) ?: null;
    $busy = $day && $slot ? tt_slot_busy($sid, $day, $slot, $exclude) : ['faculty' => (object) [], 'rooms' => (object) []];
    $rooms = db_all("SELECT id, code, name, type, capacity, building FROM classrooms WHERE status = 'active' ORDER BY FIELD(type, 'classroom', 'lab', 'seminar_hall', 'exam_hall', 'auditorium'), code");
    api_ok([
        'section' => ['id' => (int) $section['id'], 'label' => $section['label'], 'strength' => (int) $section['strength'], 'classroom_id' => $section['classroom_id'] ? (int) $section['classroom_id'] : null],
        'subjects' => tt_section_subjects($section, $sid),
        'rooms' => array_map(fn ($r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['type'], 'capacity' => (int) $r['capacity'], 'building' => $r['building']], $rooms),
        'busy' => $busy,
    ]);
});

route('GET', '/timetable/free-rooms', function () {
    api_require('timetable', 'view');
    $day = (int) ($_GET['day'] ?? 0);
    $slot = (int) ($_GET['time_slot_id'] ?? 0);
    $errors = [];
    if ($day < 1 || $day > 7) {
        $errors['day'] = 'Select a day.';
    }
    if (!$slot || !db_value('SELECT id FROM time_slots WHERE id = ?', [$slot])) {
        $errors['time_slot_id'] = 'Select a time slot.';
    }
    if ($errors) {
        api_error('Choose a day and a time slot.', 422, $errors);
    }
    $rows = tt_free_rooms(tt_request_session(), $day, $slot, ['type' => $_GET['type'] ?? null, 'min_capacity' => $_GET['min_capacity'] ?? null, 'building' => $_GET['building'] ?? null]);
    api_ok(['rows' => $rows, 'total' => count($rows), 'busy' => (int) db_value("SELECT COUNT(*) FROM classrooms WHERE status = 'active'") - count($rows)]);
});

route('POST', '/timetable/check', function () {
    api_require('timetable', 'view');
    $in = api_input();
    $in['academic_session_id'] = $in['academic_session_id'] ?? tt_request_session();
    $id = !empty($in['id']) ? (int) $in['id'] : null;
    $old = $id ? db_row('SELECT * FROM timetables WHERE id = ?', [$id]) : null;
    [, $errors] = tt_validate_entry($in, $id, $old);
    api_ok(['ok' => !$errors, 'errors' => (object) $errors]);
});

route('POST', '/timetable/entries', function () {
    api_require('timetable', 'create');
    $in = api_input();
    $in['academic_session_id'] = $in['academic_session_id'] ?? tt_request_session();
    $entry = tt_save_entry($in);
    api_ok($entry, sprintf('%s scheduled on %s, %s.', $entry['subject_code'], $entry['day_name'], $entry['slot_name']), 201);
});

route('PUT', '/timetable/entries/{id:\d+}', function ($p) {
    api_require('timetable', 'edit');
    $entry = tt_save_entry(api_input(), (int) $p['id']);
    api_ok($entry, 'Period updated.');
});

route('DELETE', '/timetable/entries/{id:\d+}', function ($p) {
    api_require('timetable', 'delete');
    if (!db_value('SELECT id FROM timetables WHERE id = ?', [(int) $p['id']])) {
        api_error('This period no longer exists. Refresh the timetable.', 404);
    }
    $e = tt_delete_entry((int) $p['id']);
    api_ok(null, sprintf('%s removed from %s, %s.', $e['subject_code'], $e['day_name'], $e['slot_name']));
});

route('POST', '/timetable/entries/{id:\d+}/move', function ($p) {
    api_require('timetable', 'edit');
    $in = api_validate(['day_of_week' => 'required|integer|min:1|max:7', 'time_slot_id' => 'required|integer|exists:time_slots,id'], null, ['day_of_week' => 'Day', 'time_slot_id' => 'Time slot']);
    $res = tt_move_entry((int) $p['id'], (int) $in['day_of_week'], (int) $in['time_slot_id'], !empty(api_input()['swap']));
    api_ok($res, $res['swapped'] ? 'Periods swapped.' : sprintf('Moved to %s, %s.', $res['moved']['day_name'], $res['moved']['slot_name']));
});

route('POST', '/timetable/copy', function () {
    api_require('timetable', 'create');
    $in = api_validate([
        'from_section_id' => 'required|integer|exists:sections,id',
        'to_section_id' => 'required|integer|exists:sections,id',
        'mode' => 'required|in:replace,merge',
    ], null, ['from_section_id' => 'Source section', 'to_section_id' => 'Target section', 'mode' => 'Copy mode']);
    $report = tt_copy((int) $in['from_section_id'], (int) $in['to_section_id'], $in['mode'], tt_request_session());
    $msg = sprintf('Copied %d period%s.', $report['copied'], $report['copied'] === 1 ? '' : 's');
    if ($report['without_faculty'] || $report['without_room'] || $report['skipped']) {
        $msg .= ' Some periods need attention.';
    }
    api_ok($report, $msg);
});

route('POST', '/timetable/publish', function () {
    api_require('timetable', 'publish');
    $in = api_input();
    $sectionId = !empty($in['section_id']) ? (int) $in['section_id'] : null;
    if ($sectionId && !db_value('SELECT id FROM sections WHERE id = ?', [$sectionId])) {
        api_error('Section not found.', 404);
    }
    $publish = !empty($in['publish']);
    $sid = tt_request_session();
    $n = tt_set_status($sectionId, $sid, $publish);
    if (!$n && $publish) {
        api_error('There are no periods to publish yet. Add classes to the timetable first.', 422);
    }
    api_ok(['updated' => $n], ($publish ? 'Timetable published' : 'Timetable moved back to draft') . ' (' . $n . ' period' . ($n === 1 ? '' : 's') . ').');
});

route('DELETE', '/timetable/sections/{id:\d+}', function ($p) {
    api_require('timetable', 'delete');
    $sec = tt_section((int) $p['id']);
    if (!$sec) {
        api_error('Section not found.', 404);
    }
    $sid = tt_request_session();
    $n = db_exec('DELETE FROM timetables WHERE section_id = ? AND academic_session_id = ?', [(int) $p['id'], $sid]);
    log_activity('delete', 'timetable', (int) $p['id'], 'Cleared timetable of ' . $sec['label'] . " ($n periods)");
    api_ok(['deleted' => $n], 'Timetable of ' . $sec['label'] . ' cleared (' . $n . ' periods).');
});
