<?php
/**
 * Timetable domain logic (weekly class periods) shared by api/routes/timetable.php, the timetables CRUD module,
 * the print view and other units (attendance reads timetables to build period-wise sheets).
 *
 * One timetables row = one period: (session, section, day_of_week, time_slot) -> subject + faculty + room.
 * Conflicts prevented here (with readable messages) and by unique keys in the schema:
 *   class double-booking   (session, section, day, slot)
 *   faculty double-booking (session, faculty, day, slot)
 *   room double-booking    (session, classroom, day, slot)
 *
 *   tt_conflicts($entry, $excludeId)    ['faculty_id' => 'Dr. X is already teaching ...', ...]
 *   tt_save_entry($input, $id = null)   validate + insert/update, returns id (throws CrudValidationException)
 *   tt_grid('class'|'faculty'|'room'|'student', $id, $sessionId)
 *   tt_copy($fromSection, $toSection, $mode)
 */

require_once __DIR__ . '/academics.php';

const TT_DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
const TT_TYPES = ['lecture' => 'Lecture', 'lab' => 'Lab / Practical', 'tutorial' => 'Tutorial', 'seminar' => 'Seminar'];

/** Working days shown in the weekly grid (Mon-Sat; Sunday when the setting enables it). */
function tt_days(): array
{
    $last = (int) setting('timetable_last_day', 6) === 7 ? 7 : 6;
    $out = [];
    for ($d = 1; $d <= $last; $d++) {
        $out[] = ['no' => $d, 'name' => TT_DAY_NAMES[$d], 'short' => substr(TT_DAY_NAMES[$d], 0, 3)];
    }
    return $out;
}

function tt_day_name(int $d): string
{
    return TT_DAY_NAMES[$d] ?? ('Day ' . $d);
}

/** Active time slots ordered by sort order / start time. */
function tt_slots(bool $includeInactive = false): array
{
    $rows = db_all('SELECT id, name, start_time, end_time, is_break, sort_order, status FROM time_slots' . ($includeInactive ? '' : " WHERE status = 'active'") . ' ORDER BY sort_order, start_time');
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'start_time' => substr($r['start_time'], 0, 5), 'end_time' => substr($r['end_time'], 0, 5),
        'is_break' => (bool) $r['is_break'], 'sort_order' => (int) $r['sort_order'], 'status' => $r['status']], $rows);
}

function tt_slot_label(array $slot): string
{
    return $slot['name'] . ' (' . format_time($slot['start_time']) . ' - ' . format_time($slot['end_time']) . ')';
}

/** Section with program details and strength. */
function tt_section(int $id): ?array
{
    $row = db_row(
        "SELECT sc.*, p.name AS program_name, p.short_name AS program_short, p.code AS program_code, p.department_id, d.name AS department_name,
                s.name AS session_name, b.name AS batch_name, r.code AS room_code, r.name AS room_name, " . acad_faculty_name_sql('f') . " AS class_teacher,
                (SELECT COUNT(*) FROM students st WHERE st.section_id = sc.id AND st.status = 'active') AS strength
         FROM sections sc JOIN programs p ON p.id = sc.program_id LEFT JOIN departments d ON d.id = p.department_id
         LEFT JOIN academic_sessions s ON s.id = sc.academic_session_id LEFT JOIN batches b ON b.id = sc.batch_id
         LEFT JOIN classrooms r ON r.id = sc.classroom_id LEFT JOIN faculty f ON f.id = sc.class_teacher_id
         WHERE sc.id = ?",
        [$id]
    );
    if ($row) {
        $row['label'] = acad_section_label($row + ['program_short' => $row['program_short']]);
    }
    return $row;
}

/** Base SELECT for timetable entries with display columns. */
function tt_entry_select(): string
{
    return "SELECT t.id, t.academic_session_id, t.program_id, t.semester_no, t.section_id, t.day_of_week, t.time_slot_id, t.subject_id, t.faculty_id, t.classroom_id,
                   t.type, t.notes, t.status, t.updated_at,
                   sb.code AS subject_code, sb.name AS subject_name, sb.type AS subject_type, sb.is_elective,
                   " . acad_faculty_name_sql('f') . " AS faculty_name, f.photo AS faculty_photo, f.employee_id AS faculty_code,
                   r.code AS room_code, r.name AS room_name, r.type AS room_type, r.capacity AS room_capacity,
                   sc.name AS section_name, p.short_name AS program_short, " . acad_section_label_sql('sc', 'p') . " AS section_label,
                   ts.name AS slot_name, ts.start_time, ts.end_time
            FROM timetables t
            JOIN subjects sb ON sb.id = t.subject_id
            JOIN sections sc ON sc.id = t.section_id
            JOIN programs p ON p.id = sc.program_id
            JOIN time_slots ts ON ts.id = t.time_slot_id
            LEFT JOIN faculty f ON f.id = t.faculty_id
            LEFT JOIN classrooms r ON r.id = t.classroom_id";
}

function tt_cast_entry(array $r): array
{
    foreach (['id', 'academic_session_id', 'program_id', 'semester_no', 'section_id', 'day_of_week', 'time_slot_id', 'subject_id', 'faculty_id', 'classroom_id', 'room_capacity'] as $k) {
        if (array_key_exists($k, $r) && $r[$k] !== null) {
            $r[$k] = (int) $r[$k];
        }
    }
    $r['is_elective'] = (bool) ($r['is_elective'] ?? false);
    $r['start_time'] = isset($r['start_time']) ? substr($r['start_time'], 0, 5) : null;
    $r['end_time'] = isset($r['end_time']) ? substr($r['end_time'], 0, 5) : null;
    $r['day_name'] = tt_day_name((int) $r['day_of_week']);
    return $r;
}

function tt_entry(int $id): ?array
{
    $row = db_row(tt_entry_select() . ' WHERE t.id = ?', [$id]);
    return $row ? tt_cast_entry($row) : null;
}

/** Entries matching simple column filters, ordered by day and slot. */
function tt_entries(array $where, array $args): array
{
    $rows = db_all(tt_entry_select() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY t.day_of_week, ts.sort_order, ts.start_time, p.sort_order, sc.semester_no, sc.name', $args);
    return array_map('tt_cast_entry', $rows);
}

/* ------------------------------------------------------------------
 * Conflict detection
 * ------------------------------------------------------------------ */

/**
 * Find clashes for a (prospective) entry. $e needs academic_session_id, section_id, day_of_week, time_slot_id, faculty_id?, classroom_id?.
 * @return array field => human message ('section_id' | 'faculty_id' | 'classroom_id')
 */
function tt_conflicts(array $e, ?int $excludeId = null): array
{
    $errors = [];
    $slot = db_row('SELECT name, start_time, end_time FROM time_slots WHERE id = ?', [(int) $e['time_slot_id']]);
    $when = tt_day_name((int) $e['day_of_week']) . ', ' . ($slot ? $slot['name'] . ' (' . format_time($slot['start_time']) . ' - ' . format_time($slot['end_time']) . ')' : 'this period');
    $base = 't.academic_session_id = ? AND t.day_of_week = ? AND t.time_slot_id = ?' . ($excludeId ? ' AND t.id <> ' . (int) $excludeId : '');
    $args = [(int) $e['academic_session_id'], (int) $e['day_of_week'], (int) $e['time_slot_id']];

    $clash = db_row(tt_entry_select() . " WHERE $base AND t.section_id = ? LIMIT 1", array_merge($args, [(int) $e['section_id']]));
    if ($clash) {
        $errors['section_id'] = sprintf('Class clash: %s already has %s (%s) on %s.', $clash['section_label'], $clash['subject_name'], $clash['subject_code'], $when);
    }
    if (!empty($e['faculty_id'])) {
        $clash = db_row(tt_entry_select() . " WHERE $base AND t.faculty_id = ? LIMIT 1", array_merge($args, [(int) $e['faculty_id']]));
        if ($clash) {
            $errors['faculty_id'] = sprintf('Faculty clash: %s is already teaching %s (%s) to %s on %s.', $clash['faculty_name'], $clash['subject_name'], $clash['subject_code'], $clash['section_label'], $when);
        }
    }
    if (!empty($e['classroom_id'])) {
        $clash = db_row(tt_entry_select() . " WHERE $base AND t.classroom_id = ? LIMIT 1", array_merge($args, [(int) $e['classroom_id']]));
        if ($clash) {
            $errors['classroom_id'] = sprintf('Room clash: %s is already booked for %s (%s, %s) on %s.', $clash['room_code'] . ' ' . $clash['room_name'], $clash['section_label'], $clash['subject_code'], $clash['faculty_name'] ?: 'no faculty', $when);
        }
    }
    return $errors;
}

/** Busy faculty/rooms for one cell: used by the editor to annotate options. */
function tt_slot_busy(int $sessionId, int $day, int $slotId, ?int $excludeId = null): array
{
    $where = ['t.academic_session_id = ?', 't.day_of_week = ?', 't.time_slot_id = ?'];
    if ($excludeId) {
        $where[] = 't.id <> ' . (int) $excludeId;
    }
    $rows = tt_entries($where, [$sessionId, $day, $slotId]);
    $faculty = [];
    $rooms = [];
    foreach ($rows as $r) {
        if ($r['faculty_id']) {
            $faculty[$r['faculty_id']] = $r['section_label'] . ' · ' . $r['subject_code'];
        }
        if ($r['classroom_id']) {
            $rooms[$r['classroom_id']] = $r['section_label'] . ' · ' . $r['subject_code'];
        }
    }
    return ['faculty' => (object) $faculty, 'rooms' => (object) $rooms];
}

/* ------------------------------------------------------------------
 * Validation & saving
 * ------------------------------------------------------------------ */

/**
 * Validate & normalise an entry. Input keys: academic_session_id, section_id, day_of_week, time_slot_id, subject_id, faculty_id,
 * classroom_id, type, notes, override_faculty (bool: allow a faculty not assigned via faculty_subjects).
 * @return array [clean data, errors]
 */
function tt_validate_entry(array $in, ?int $id = null, ?array $old = null): array
{
    $errors = [];
    $get = fn ($k) => array_key_exists($k, $in) ? (is_string($in[$k]) ? trim($in[$k]) : $in[$k]) : ($old[$k] ?? null);
    $d = [
        'academic_session_id' => (int) ($get('academic_session_id') ?: current_session_id()),
        'section_id' => (int) $get('section_id'),
        'day_of_week' => (int) $get('day_of_week'),
        'time_slot_id' => (int) $get('time_slot_id'),
        'subject_id' => (int) $get('subject_id'),
        'faculty_id' => $get('faculty_id') ? (int) $get('faculty_id') : null,
        'classroom_id' => $get('classroom_id') ? (int) $get('classroom_id') : null,
        'type' => (string) ($get('type') ?: 'lecture'),
        'notes' => $get('notes') !== null && $get('notes') !== '' ? mb_substr((string) $get('notes'), 0, 255) : null,
    ];
    if (!$d['academic_session_id'] || !db_value('SELECT id FROM academic_sessions WHERE id = ?', [$d['academic_session_id']])) {
        $errors['academic_session_id'] = 'Select a valid academic session.';
    }
    $section = $d['section_id'] ? tt_section($d['section_id']) : null;
    if (!$section) {
        $errors['section_id'] = 'Select a valid section.';
    } elseif ((int) $section['academic_session_id'] && (int) $section['academic_session_id'] !== $d['academic_session_id']) {
        $errors['section_id'] = $section['label'] . ' belongs to session ' . $section['session_name'] . '. Pick a section of the selected session.';
    }
    if ($d['day_of_week'] < 1 || $d['day_of_week'] > 7) {
        $errors['day_of_week'] = 'Select a valid day.';
    }
    $slot = $d['time_slot_id'] ? db_row('SELECT * FROM time_slots WHERE id = ?', [$d['time_slot_id']]) : null;
    if (!$slot) {
        $errors['time_slot_id'] = 'Select a valid time slot.';
    } elseif ((int) $slot['is_break']) {
        $errors['time_slot_id'] = $slot['name'] . ' is a break - classes cannot be scheduled in it.';
    } elseif ($slot['status'] !== 'active') {
        $errors['time_slot_id'] = $slot['name'] . ' is inactive.';
    }
    $subject = $d['subject_id'] ? db_row('SELECT * FROM subjects WHERE id = ?', [$d['subject_id']]) : null;
    if (!$subject) {
        $errors['subject_id'] = 'Select a subject.';
    } elseif ($section && ((int) $subject['program_id'] !== (int) $section['program_id'] || (int) $subject['semester_no'] !== (int) $section['semester_no'])) {
        $errors['subject_id'] = $subject['code'] . ' is not part of ' . $section['program_short'] . ' Semester ' . $section['semester_no'] . '.';
    } elseif ($subject['status'] !== 'active') {
        $errors['subject_id'] = $subject['code'] . ' is inactive.';
    }
    if ($d['faculty_id']) {
        $fac = db_row("SELECT id, status, " . acad_faculty_name_sql('faculty') . " AS name FROM faculty WHERE id = ?", [$d['faculty_id']]);
        if (!$fac) {
            $errors['faculty_id'] = 'Selected faculty member is invalid.';
        } elseif (!in_array($fac['status'], ['active', 'on_leave'], true)) {
            $errors['faculty_id'] = $fac['name'] . ' is not an active faculty member.';
        } elseif ($subject && $section && empty($in['override_faculty'])) {
            $assigned = db_value('SELECT COUNT(*) FROM faculty_subjects WHERE faculty_id = ? AND subject_id = ? AND (section_id = ? OR section_id IS NULL) AND (academic_session_id = ? OR academic_session_id IS NULL)',
                [$d['faculty_id'], $d['subject_id'], $d['section_id'], $d['academic_session_id']]);
            if (!(int) $assigned) {
                $errors['faculty_id'] = $fac['name'] . ' is not assigned to teach ' . $subject['code'] . ' for this section. Assign them under Faculty Assignments or tick "Override".';
            }
        }
    }
    if ($d['classroom_id']) {
        $room = db_row('SELECT id, code, name, status, capacity FROM classrooms WHERE id = ?', [$d['classroom_id']]);
        if (!$room) {
            $errors['classroom_id'] = 'Selected room is invalid.';
        } elseif ($room['status'] !== 'active') {
            $errors['classroom_id'] = $room['code'] . ' is not available (inactive).';
        }
    }
    if (!isset(TT_TYPES[$d['type']])) {
        $errors['type'] = 'Select a valid period type.';
    }
    if (!$errors) {
        $errors = tt_conflicts($d, $id);
    }
    if ($section) {
        $d['program_id'] = (int) $section['program_id'];
        $d['semester_no'] = (int) $section['semester_no'];
    }
    return [$d, $errors];
}

/** Status a new entry of a section should get: published when the section's timetable is already published. */
function tt_section_status(int $sectionId, int $sessionId): string
{
    $pub = (int) db_value("SELECT COUNT(*) FROM timetables WHERE section_id = ? AND academic_session_id = ? AND status = 'published'", [$sectionId, $sessionId]);
    return $pub > 0 ? 'published' : 'draft';
}

/** Create/update an entry. Throws CrudValidationException with field errors. Returns the saved entry. */
function tt_save_entry(array $in, ?int $id = null): array
{
    $old = null;
    if ($id) {
        $old = db_row('SELECT * FROM timetables WHERE id = ?', [$id]);
        if (!$old) {
            throw new CrudException('This period no longer exists. Refresh the timetable.');
        }
    }
    [$d, $errors] = tt_validate_entry($in, $id, $old);
    if ($errors) {
        throw new CrudValidationException($errors, reset($errors));
    }
    try {
        if ($id) {
            db_update('timetables', $d, 'id = ?', [$id]);
        } else {
            $d['status'] = tt_section_status($d['section_id'], $d['academic_session_id']);
            $d['created_by'] = user_id();
            $id = db_insert('timetables', $d);
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new CrudException('Someone just booked this period. Refresh the timetable and try again.');
        }
        throw $e;
    }
    $entry = tt_entry($id);
    log_activity($old ? 'update' : 'create', 'timetable', $id, sprintf('%s %s · %s %s: %s%s%s', $old ? 'Updated' : 'Scheduled', $entry['section_label'], $entry['day_name'], $entry['slot_name'],
        $entry['subject_code'], $entry['faculty_name'] ? ' with ' . $entry['faculty_name'] : '', $entry['room_code'] ? ' in ' . $entry['room_code'] : ''));
    return $entry;
}

function tt_delete_entry(int $id): array
{
    $entry = tt_entry($id);
    if (!$entry) {
        throw new CrudException('This period no longer exists. Refresh the timetable.');
    }
    db_delete('timetables', 'id = ?', [$id]);
    log_activity('delete', 'timetable', $id, sprintf('Removed %s · %s %s: %s', $entry['section_label'], $entry['day_name'], $entry['slot_name'], $entry['subject_code']));
    return $entry;
}

/**
 * Move an entry to another day/slot of the same section. When the target cell holds another period of the same
 * section and $swap is true, the two periods trade places (both are conflict-checked).
 */
function tt_move_entry(int $id, int $day, int $slotId, bool $swap = false): array
{
    $e = db_row('SELECT * FROM timetables WHERE id = ?', [$id]);
    if (!$e) {
        throw new CrudException('This period no longer exists. Refresh the timetable.');
    }
    if ((int) $e['day_of_week'] === $day && (int) $e['time_slot_id'] === $slotId) {
        return ['moved' => tt_entry($id), 'swapped' => null];
    }
    $target = db_row('SELECT * FROM timetables WHERE academic_session_id = ? AND section_id = ? AND day_of_week = ? AND time_slot_id = ?', [$e['academic_session_id'], $e['section_id'], $day, $slotId]);
    if ($target && !$swap) {
        throw new CrudException('The target period already has a class. Choose "Swap" to exchange the two periods.');
    }
    return db_transaction(function () use ($e, $target, $day, $slotId) {
        $id = (int) $e['id'];
        // Park the moving entry on a temporary day so unique keys do not trip during the swap.
        if ($target) {
            db_exec('UPDATE timetables SET day_of_week = 0 WHERE id = ?', [(int) $target['id']]);
        }
        [, $errors] = tt_validate_entry(['day_of_week' => $day, 'time_slot_id' => $slotId, 'override_faculty' => 1], $id, $e);
        if ($errors) {
            throw new CrudValidationException($errors, reset($errors));
        }
        db_update('timetables', ['day_of_week' => $day, 'time_slot_id' => $slotId], 'id = ?', [$id]);
        $swapped = null;
        if ($target) {
            [, $errors] = tt_validate_entry(['day_of_week' => (int) $e['day_of_week'], 'time_slot_id' => (int) $e['time_slot_id'], 'override_faculty' => 1], (int) $target['id'], $target);
            if ($errors) {
                throw new CrudValidationException($errors, 'Cannot swap: ' . lcfirst(reset($errors)));
            }
            db_update('timetables', ['day_of_week' => (int) $e['day_of_week'], 'time_slot_id' => (int) $e['time_slot_id']], 'id = ?', [(int) $target['id']]);
            $swapped = tt_entry((int) $target['id']);
        }
        $moved = tt_entry($id);
        log_activity('update', 'timetable', $id, sprintf('%s %s %s to %s %s%s', $swapped ? 'Swapped' : 'Moved', $moved['section_label'], $moved['subject_code'], $moved['day_name'], $moved['slot_name'],
            $swapped ? ' (with ' . $swapped['subject_code'] . ')' : ''));
        return ['moved' => $moved, 'swapped' => $swapped];
    });
}

/**
 * Copy one section's timetable to another section of the same program & semester.
 * $mode: 'replace' (clear target first) | 'merge' (only fill empty cells).
 * Faculty: the target section's own assignment for the subject when present, else the source faculty; a clashing
 * faculty/room is dropped (period kept, faculty/room left blank) and reported.
 */
function tt_copy(int $fromId, int $toId, string $mode = 'replace', ?int $sessionId = null): array
{
    $from = tt_section($fromId);
    $to = tt_section($toId);
    if (!$from || !$to) {
        throw new CrudException('Select both the source and the target section.');
    }
    if ($fromId === $toId) {
        throw new CrudValidationException(['to_section_id' => 'Choose a different target section.'], 'Choose a different target section.');
    }
    if ((int) $from['program_id'] !== (int) $to['program_id'] || (int) $from['semester_no'] !== (int) $to['semester_no']) {
        $msg = 'Timetables can only be copied between sections of the same program and semester (' . $from['program_short'] . ' Sem ' . $from['semester_no'] . ').';
        throw new CrudValidationException(['to_section_id' => $msg], $msg);
    }
    $sid = $sessionId ?: (int) ($to['academic_session_id'] ?: current_session_id());
    $source = db_all('SELECT * FROM timetables WHERE section_id = ? AND academic_session_id = ? ORDER BY day_of_week, time_slot_id', [$fromId, $sid]);
    if (!$source) {
        throw new CrudException($from['label'] . ' has no timetable to copy for this session.');
    }
    $assign = db_pairs('SELECT subject_id, faculty_id FROM faculty_subjects WHERE section_id = ? AND (academic_session_id = ? OR academic_session_id IS NULL) ORDER BY is_primary DESC', [$toId, $sid]);
    $report = ['copied' => 0, 'skipped' => 0, 'without_faculty' => 0, 'without_room' => 0, 'notes' => []];
    db_transaction(function () use ($source, $to, $toId, $sid, $mode, $assign, &$report) {
        if ($mode === 'replace') {
            db_exec('DELETE FROM timetables WHERE section_id = ? AND academic_session_id = ?', [$toId, $sid]);
        }
        $status = tt_section_status($toId, $sid);
        foreach ($source as $s) {
            $cell = ['academic_session_id' => $sid, 'section_id' => $toId, 'day_of_week' => (int) $s['day_of_week'], 'time_slot_id' => (int) $s['time_slot_id']];
            $when = tt_day_name((int) $s['day_of_week']) . ' ' . db_value('SELECT name FROM time_slots WHERE id = ?', [(int) $s['time_slot_id']]);
            if (tt_conflicts($cell)['section_id'] ?? null) {
                $report['skipped']++;
                $report['notes'][] = "$when: target already has a class - skipped.";
                continue;
            }
            $faculty = isset($assign[$s['subject_id']]) ? (int) $assign[$s['subject_id']] : ($s['faculty_id'] ? (int) $s['faculty_id'] : null);
            // Lectures move to the target's home room; labs keep the source lab.
            $room = $s['type'] !== 'lab' && $to['classroom_id'] ? (int) $to['classroom_id'] : ($s['classroom_id'] ? (int) $s['classroom_id'] : null);
            $c = tt_conflicts($cell + ['faculty_id' => $faculty, 'classroom_id' => $room]);
            if (isset($c['faculty_id'])) {
                $faculty = null;
                $report['without_faculty']++;
                $report['notes'][] = "$when: faculty busy - left unassigned.";
            }
            if (isset($c['classroom_id'])) {
                $alt = $room ? tt_free_rooms($sid, (int) $s['day_of_week'], (int) $s['time_slot_id'], ['type' => $s['type'] === 'lab' ? 'lab' : 'classroom', 'min_capacity' => (int) $to['strength']]) : [];
                $room = $alt ? (int) $alt[0]['id'] : null;
                if (!$room) {
                    $report['without_room']++;
                    $report['notes'][] = "$when: no free room - left unassigned.";
                }
            }
            db_insert('timetables', $cell + [
                'program_id' => (int) $to['program_id'], 'semester_no' => (int) $to['semester_no'], 'subject_id' => (int) $s['subject_id'],
                'faculty_id' => $faculty, 'classroom_id' => $room, 'type' => $s['type'], 'notes' => $s['notes'], 'status' => $status, 'created_by' => user_id(),
            ]);
            $report['copied']++;
        }
    });
    log_activity('create', 'timetable', $toId, sprintf('Copied timetable of %s to %s (%d periods, %s)', $from['label'], $to['label'], $report['copied'], $mode));
    return $report;
}

/** Publish / unpublish all periods of a section (or every section of the session when $sectionId is null). */
function tt_set_status(?int $sectionId, int $sessionId, bool $publish): int
{
    $status = $publish ? 'published' : 'draft';
    $sql = 'UPDATE timetables SET status = ? WHERE academic_session_id = ?' . ($sectionId ? ' AND section_id = ?' : '');
    db_exec($sql, $sectionId ? [$status, $sessionId, $sectionId] : [$status, $sessionId]);
    $n = (int) db_value('SELECT COUNT(*) FROM timetables WHERE academic_session_id = ?' . ($sectionId ? ' AND section_id = ?' : ''), $sectionId ? [$sessionId, $sectionId] : [$sessionId]);
    $label = $sectionId ? (tt_section($sectionId)['label'] ?? ('section #' . $sectionId)) : 'all sections';
    log_activity($publish ? 'publish' : 'update', 'timetable', $sectionId, ($publish ? 'Published' : 'Unpublished') . ' timetable of ' . $label);
    if ($publish && $sectionId && $n) {
        notify('perm:attendance', 'academic', 'Timetable published', 'The timetable of ' . $label . ' has been published.', 'admin/timetable?section=' . $sectionId, 'calendar-days');
    }
    return $n;
}

/** Rooms with no class in a given cell. Filters: type, min_capacity, building. */
function tt_free_rooms(int $sessionId, int $day, int $slotId, array $filters = []): array
{
    $where = ["r.status = 'active'", 'NOT EXISTS (SELECT 1 FROM timetables t WHERE t.classroom_id = r.id AND t.academic_session_id = ? AND t.day_of_week = ? AND t.time_slot_id = ?)'];
    $args = [$sessionId, $day, $slotId];
    if (!empty($filters['type'])) {
        $where[] = 'r.type = ?';
        $args[] = $filters['type'];
    }
    if (!empty($filters['min_capacity'])) {
        $where[] = 'r.capacity >= ?';
        $args[] = (int) $filters['min_capacity'];
    }
    if (!empty($filters['building'])) {
        $where[] = 'r.building = ?';
        $args[] = $filters['building'];
    }
    $rows = db_all("SELECT r.id, r.code, r.name, r.building, r.floor, r.capacity, r.type, r.facilities,
                           (SELECT COUNT(*) FROM timetables t2 WHERE t2.classroom_id = r.id AND t2.academic_session_id = ? AND t2.day_of_week = ?) AS periods_today
                    FROM classrooms r WHERE " . implode(' AND ', $where) . " ORDER BY FIELD(r.type, 'classroom', 'lab', 'seminar_hall', 'exam_hall', 'auditorium'), r.code",
        array_merge([$sessionId, $day], $args));
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'building' => $r['building'], 'floor' => $r['floor'],
        'capacity' => (int) $r['capacity'], 'type' => $r['type'], 'facilities' => $r['facilities'], 'periods_today' => (int) $r['periods_today']], $rows);
}

/* ------------------------------------------------------------------
 * Views
 * ------------------------------------------------------------------ */

/** Subjects of a section with required vs scheduled periods and assigned faculty. */
function tt_section_subjects(array $section, int $sessionId): array
{
    $subjects = db_all('SELECT id, code, name, type, is_elective, credits, hours_per_week FROM subjects WHERE program_id = ? AND semester_no = ? AND status = \'active\' ORDER BY code',
        [$section['program_id'], $section['semester_no']]);
    $scheduled = array_map('intval', db_pairs('SELECT subject_id, COUNT(*) FROM timetables WHERE section_id = ? AND academic_session_id = ? GROUP BY subject_id', [$section['id'], $sessionId]));
    $assign = db_all("SELECT fs.subject_id, fs.is_primary, f.id, " . acad_faculty_name_sql('f') . " AS name, f.designation, f.photo
                      FROM faculty_subjects fs JOIN faculty f ON f.id = fs.faculty_id
                      WHERE (fs.section_id = ? OR fs.section_id IS NULL) AND (fs.academic_session_id = ? OR fs.academic_session_id IS NULL)
                      ORDER BY fs.is_primary DESC, f.first_name", [$section['id'], $sessionId]);
    $bySubject = [];
    foreach ($assign as $a) {
        $bySubject[(int) $a['subject_id']][] = ['id' => (int) $a['id'], 'name' => $a['name'], 'designation' => $a['designation'], 'photo' => $a['photo'], 'primary' => (bool) $a['is_primary']];
    }
    return array_map(fn ($s) => [
        'id' => (int) $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'type' => $s['type'], 'elective' => (bool) $s['is_elective'], 'credits' => (float) $s['credits'],
        'required' => (int) ($s['hours_per_week'] ?: (in_array($s['type'], ['lab', 'project', 'practical'], true) ? 2 : 4)),
        'scheduled' => $scheduled[(int) $s['id']] ?? 0, 'faculty' => $bySubject[(int) $s['id']] ?? [],
    ], $subjects);
}

/**
 * Weekly grid for a view. $view: class (section id) | faculty | room | student.
 * @return array|null {view, context, entries, subjects?, stats}
 */
function tt_grid(string $view, int $id, int $sessionId, bool $publishedOnly = false): ?array
{
    $status = $publishedOnly ? " AND t.status = 'published'" : '';
    $context = null;
    $subjects = null;
    switch ($view) {
        case 'student':
            $st = db_row("SELECT s.id, s.student_uid, s.roll_no, s.first_name, s.last_name, s.photo, s.section_id, s.current_semester, p.short_name AS program_short
                          FROM students s LEFT JOIN programs p ON p.id = s.program_id WHERE s.id = ?", [$id]);
            if (!$st) {
                return null;
            }
            $student = ['id' => (int) $st['id'], 'name' => trim($st['first_name'] . ' ' . $st['last_name']), 'uid' => $st['student_uid'], 'roll_no' => $st['roll_no'],
                'photo' => $st['photo'], 'program' => $st['program_short'], 'semester' => (int) $st['current_semester'], 'section_id' => $st['section_id'] ? (int) $st['section_id'] : null];
            if (!$st['section_id']) {
                return ['view' => 'student', 'context' => ['student' => $student, 'section' => null], 'entries' => [], 'subjects' => [], 'stats' => tt_stats([])];
            }
            $grid = tt_grid('class', (int) $st['section_id'], $sessionId, $publishedOnly);
            if ($grid) {
                $grid['view'] = 'student';
                $grid['context']['student'] = $student;
            }
            return $grid;
        case 'class':
            $sec = tt_section($id);
            if (!$sec) {
                return null;
            }
            $entries = tt_entries(["t.section_id = ? AND t.academic_session_id = ?$status"], [$id, $sessionId]);
            $subjects = tt_section_subjects($sec, $sessionId);
            $context = ['section' => [
                'id' => (int) $sec['id'], 'label' => $sec['label'], 'name' => $sec['name'], 'program_id' => (int) $sec['program_id'], 'program' => $sec['program_name'],
                'program_short' => $sec['program_short'], 'semester_no' => (int) $sec['semester_no'], 'department' => $sec['department_name'], 'session' => $sec['session_name'],
                'academic_session_id' => $sec['academic_session_id'] ? (int) $sec['academic_session_id'] : null, 'batch' => $sec['batch_name'], 'strength' => (int) $sec['strength'],
                'capacity' => (int) $sec['capacity'], 'classroom_id' => $sec['classroom_id'] ? (int) $sec['classroom_id'] : null, 'room' => $sec['room_code'] ? $sec['room_code'] . ($sec['room_name'] && $sec['room_name'] !== 'Room ' . $sec['room_code'] ? ' · ' . $sec['room_name'] : '') : null,
                'class_teacher' => $sec['class_teacher'] ?: null,
            ]];
            break;
        case 'faculty':
            $f = db_row("SELECT f.id, " . acad_faculty_name_sql('f') . " AS name, f.employee_id, f.designation, f.photo, f.email, f.status, d.name AS department
                         FROM faculty f LEFT JOIN departments d ON d.id = f.department_id WHERE f.id = ?", [$id]);
            if (!$f) {
                return null;
            }
            $entries = tt_entries(["t.faculty_id = ? AND t.academic_session_id = ?$status"], [$id, $sessionId]);
            $context = ['faculty' => ['id' => (int) $f['id'], 'name' => $f['name'], 'employee_id' => $f['employee_id'], 'designation' => $f['designation'], 'photo' => $f['photo'],
                'email' => $f['email'], 'department' => $f['department'], 'status' => $f['status'],
                'assignments' => (int) db_value('SELECT COUNT(*) FROM faculty_subjects WHERE faculty_id = ? AND (academic_session_id = ? OR academic_session_id IS NULL)', [$id, $sessionId])]];
            break;
        case 'room':
            $r = db_row('SELECT id, code, name, building, floor, capacity, type, facilities, status FROM classrooms WHERE id = ?', [$id]);
            if (!$r) {
                return null;
            }
            $entries = tt_entries(["t.classroom_id = ? AND t.academic_session_id = ?$status"], [$id, $sessionId]);
            $context = ['room' => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'building' => $r['building'], 'floor' => $r['floor'], 'capacity' => (int) $r['capacity'],
                'type' => $r['type'], 'facilities' => $r['facilities'], 'status' => $r['status']]];
            break;
        default:
            throw new InvalidArgumentException('Unknown timetable view.');
    }
    return ['view' => $view, 'context' => $context, 'entries' => $entries, 'subjects' => $subjects, 'stats' => tt_stats($entries)];
}

function tt_stats(array $entries): array
{
    $teaching = count(array_filter(tt_slots(), fn ($s) => !$s['is_break'])) * count(tt_days());
    $published = count(array_filter($entries, fn ($e) => $e['status'] === 'published'));
    return [
        'periods' => count($entries), 'published' => $published, 'draft' => count($entries) - $published,
        'capacity' => $teaching, 'utilisation' => $teaching ? round(count($entries) / $teaching * 100, 1) : 0,
        'subjects' => count(array_unique(array_column($entries, 'subject_id'))),
        'faculty' => count(array_unique(array_filter(array_column($entries, 'faculty_id')))),
        'without_faculty' => count(array_filter($entries, fn ($e) => !$e['faculty_id'])),
        'without_room' => count(array_filter($entries, fn ($e) => !$e['classroom_id'])),
        'status' => !$entries ? 'empty' : ($published === count($entries) ? 'published' : ($published === 0 ? 'draft' : 'partial')),
    ];
}

/** All classes on one day: rows = sections, columns = slots. Filters: department_id, program_id. */
function tt_daily(int $sessionId, int $day, array $filters = []): array
{
    $where = ['sc.academic_session_id = ?', "sc.status = 'active'"];
    $args = [$sessionId];
    if (!empty($filters['department_id'])) {
        $where[] = 'p.department_id = ?';
        $args[] = (int) $filters['department_id'];
    }
    if (!empty($filters['program_id'])) {
        $where[] = 'sc.program_id = ?';
        $args[] = (int) $filters['program_id'];
    }
    $sections = db_all("SELECT sc.id, " . acad_section_label_sql('sc', 'p') . " AS label, p.short_name AS program_short, sc.semester_no, sc.name,
                               (SELECT COUNT(*) FROM students st WHERE st.section_id = sc.id AND st.status = 'active') AS strength
                        FROM sections sc JOIN programs p ON p.id = sc.program_id WHERE " . implode(' AND ', $where) . ' ORDER BY p.sort_order, sc.semester_no, sc.name', $args);
    $ids = array_map(fn ($s) => (int) $s['id'], $sections);
    $entries = $ids ? tt_entries(['t.academic_session_id = ?', 't.day_of_week = ?', 't.section_id IN (' . implode(',', $ids) . ')'], [$sessionId, $day]) : [];
    return [
        'day' => ['no' => $day, 'name' => tt_day_name($day)],
        'sections' => array_map(fn ($s) => ['id' => (int) $s['id'], 'label' => $s['label'], 'program_short' => $s['program_short'], 'semester_no' => (int) $s['semester_no'],
            'name' => $s['name'], 'strength' => (int) $s['strength']], $sections),
        'entries' => $entries,
        'stats' => [
            'periods' => count($entries),
            'faculty' => count(array_unique(array_filter(array_column($entries, 'faculty_id')))),
            'rooms' => count(array_unique(array_filter(array_column($entries, 'classroom_id')))),
            'sections' => count(array_unique(array_column($entries, 'section_id'))),
        ],
    ];
}

/** Session-wide numbers for the timetable header. */
function tt_summary(int $sessionId): array
{
    $sections = (int) db_value("SELECT COUNT(*) FROM sections WHERE academic_session_id = ? AND status = 'active'", [$sessionId]);
    $bySection = db_all("SELECT section_id, SUM(status = 'published') AS pub, COUNT(*) AS n FROM timetables WHERE academic_session_id = ? GROUP BY section_id", [$sessionId]);
    $published = count(array_filter($bySection, fn ($r) => (int) $r['pub'] === (int) $r['n']));
    $teachingSlots = count(array_filter(tt_slots(), fn ($s) => !$s['is_break'])) * count(tt_days());
    $rooms = (int) db_value("SELECT COUNT(*) FROM classrooms WHERE status = 'active' AND type IN ('classroom','lab')");
    $periods = (int) db_value('SELECT COUNT(*) FROM timetables WHERE academic_session_id = ?', [$sessionId]);
    return [
        'sections' => $sections,
        'scheduled_sections' => count($bySection),
        'published_sections' => $published,
        'draft_sections' => count($bySection) - $published,
        'unscheduled_sections' => max(0, $sections - count($bySection)),
        'periods' => $periods,
        'faculty' => (int) db_value('SELECT COUNT(DISTINCT faculty_id) FROM timetables WHERE academic_session_id = ? AND faculty_id IS NOT NULL', [$sessionId]),
        'room_utilisation' => $rooms && $teachingSlots ? round((int) db_value('SELECT COUNT(*) FROM timetables WHERE academic_session_id = ? AND classroom_id IS NOT NULL', [$sessionId]) / ($rooms * $teachingSlots) * 100, 1) : 0,
        'without_faculty' => (int) db_value('SELECT COUNT(*) FROM timetables WHERE academic_session_id = ? AND faculty_id IS NULL', [$sessionId]),
    ];
}
