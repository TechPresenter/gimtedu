<?php
/** Date sheet of an exam: one paper per subject with room and invigilators (embedded on the exam page with scope exam_id). */
require_once APP_ROOT . '/app/services/exams.php';

return [
    'table' => 'exam_schedules',
    'title' => 'Exam Schedule',
    'singular' => 'Paper',
    'permission' => 'examination',
    'icon' => 'calendar-clock',
    'description' => 'Paper dates, timings, rooms and invigilators.',
    'select' => "t.*, sb.code AS subject_code, sb.name AS subject_name, sb.type AS subject_type, r.code AS room_code, r.name AS room_name, r.capacity AS room_capacity,
                 TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS chief_invigilator, f.photo AS chief_photo, DAYNAME(t.exam_date) AS weekday,
                 (SELECT GROUP_CONCAT(ei.faculty_id ORDER BY ei.id) FROM exam_invigilators ei WHERE ei.exam_schedule_id = t.id) AS invigilator_ids,
                 (SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', fi.title, fi.first_name, fi.last_name)) ORDER BY ei.id SEPARATOR ', ') FROM exam_invigilators ei JOIN faculty fi ON fi.id = ei.faculty_id WHERE ei.exam_schedule_id = t.id) AS invigilator_names,
                 (SELECT COUNT(*) FROM exam_attendance a WHERE a.exam_schedule_id = t.id AND a.status = 'present') AS present_count,
                 (SELECT COUNT(*) FROM exam_attendance a WHERE a.exam_schedule_id = t.id AND a.status = 'absent') AS absent_count,
                 (SELECT COUNT(*) FROM exam_attendance a WHERE a.exam_schedule_id = t.id AND a.status = 'malpractice') AS malpractice_count,
                 (SELECT COUNT(*) FROM exam_students x WHERE x.exam_id = t.exam_id AND x.is_eligible = 1) AS students_count",
    'joins' => 'JOIN subjects sb ON sb.id = t.subject_id LEFT JOIN classrooms r ON r.id = t.classroom_id LEFT JOIN faculty f ON f.id = t.invigilator_id',
    'search' => ['sb.name', 'sb.code', 'r.code', 'r.name', 'f.first_name', 'f.last_name'],
    'search_placeholder' => 'Search papers, rooms, invigilators…',
    'order' => 't.exam_date ASC, t.start_time ASC',
    'per_page' => 50,
    'columns' => [
        ['key' => 'exam_date', 'label' => 'Date', 'format' => 'date', 'sub' => 'weekday', 'sortable' => true],
        ['key' => 'start_time', 'label' => 'Time', 'format' => 'time', 'sortable' => true],
        ['key' => 'subject_name', 'label' => 'Subject', 'format' => 'title', 'sub' => 'subject_code', 'sortable' => 'sb.name', 'link' => 'view'],
        ['key' => 'room_code', 'label' => 'Room', 'format' => 'text', 'sub' => 'room_name', 'sortable' => 'r.code'],
        ['key' => 'chief_invigilator', 'label' => 'Chief invigilator', 'format' => 'person', 'image' => 'chief_photo', 'sortable' => 'f.first_name'],
        ['key' => 'invigilator_names', 'label' => 'Invigilators', 'format' => 'truncate', 'truncate' => 40],
        ['key' => 'present_count', 'label' => 'Attendance', 'format' => 'number', 'align' => 'center'],
        ['key' => 'notes', 'label' => 'Notes', 'format' => 'truncate', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'exam_date', 'label' => 'Date', 'type' => 'date'],
        ['key' => 'classroom_id', 'label' => 'Room', 'source' => 'classrooms'],
    ],
    'scopes' => ['exam_id' => 't.exam_id'],
    'fields' => [
        ['name' => 'exam_id', 'type' => 'hidden', 'form' => true],
        ['name' => 'subject_id', 'label' => 'Subject', 'type' => 'combobox', 'required' => true, 'col' => 12, 'depends' => ['exam_id' => 'exam_id'],
            'source' => ['table' => 'exam_subjects es', 'joins' => 'JOIN subjects sb ON sb.id = es.subject_id', 'value' => 'sb.id', 'label' => "CONCAT(sb.code, ' — ', sb.name)",
                'sub' => "CONCAT(es.max_internal + es.max_external + es.max_practical, ' marks · ', sb.type)", 'order' => 'sb.code', 'search' => ['sb.name', 'sb.code'], 'filters' => ['exam_id' => 'es.exam_id']]],
        ['name' => 'exam_date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'col' => 4],
        ['name' => 'start_time', 'label' => 'Starts', 'type' => 'time', 'required' => true, 'col' => 4],
        ['name' => 'end_time', 'label' => 'Ends', 'type' => 'time', 'required' => true, 'col' => 4],
        ['name' => 'classroom_id', 'label' => 'Room / hall', 'type' => 'combobox', 'source' => 'classrooms', 'col' => 12, 'help' => 'Examination halls can host several papers while seats last; classrooms and labs one paper at a time.'],
        ['name' => 'invigilator_id', 'label' => 'Chief invigilator', 'type' => 'combobox', 'source' => 'faculty', 'col' => 6],
        ['name' => 'invigilator_ids', 'label' => 'Additional invigilators', 'type' => 'multiselect', 'source' => 'faculty', 'col' => 6],
        ['name' => 'notes', 'label' => 'Notes', 'type' => 'text', 'maxlength' => 255, 'col' => 12, 'placeholder' => 'e.g. Calculators allowed, open-book, answer booklet + graph sheet'],
    ],
    'bulk' => ['delete' => true],
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $d, ?int $id, ?array $old, array $input): array {
            $get = fn ($k) => array_key_exists($k, $d) ? $d[$k] : ($old[$k] ?? null);
            $examId = (int) $get('exam_id');
            $e = $examId ? exam_find($examId) : null;
            if (!$e) {
                return ['subject_id' => 'Open an exam to schedule its papers.'];
            }
            if ($e['status'] === 'published' || $e['status'] === 'cancelled') {
                return ['exam_date' => $e['status'] === 'cancelled' ? 'This exam is cancelled.' : 'Results are published - the schedule can no longer change.'];
            }
            $errors = [];
            $subjectId = (int) $get('subject_id');
            if (!db_value('SELECT 1 FROM exam_subjects WHERE exam_id = ? AND subject_id = ?', [$examId, $subjectId])) {
                $errors['subject_id'] = 'Add this subject to the exam first.';
            } elseif (db_value('SELECT 1 FROM exam_schedules WHERE exam_id = ? AND subject_id = ? AND id <> ?', [$examId, $subjectId, (int) $id])) {
                $errors['subject_id'] = 'This subject already has a paper in the schedule. Edit that paper instead.';
            }
            $date = (string) $get('exam_date');
            if ($date && $e['start_date'] && $date < $e['start_date']) {
                $errors['exam_date'] = 'The exam starts on ' . format_date($e['start_date']) . '.';
            } elseif ($date && $e['end_date'] && $date > $e['end_date']) {
                $errors['exam_date'] = 'The exam ends on ' . format_date($e['end_date']) . '. Extend the exam dates first.';
            } elseif ($date && date('N', strtotime($date)) == 7) {
                $errors['exam_date'] = 'Papers cannot be scheduled on a Sunday.';
            } elseif ($date && ($h = db_row("SELECT title FROM holidays WHERE type IN ('holiday','vacation') AND holiday_date <= ? AND COALESCE(end_date, holiday_date) >= ?", [$date, $date]))) {
                $errors['exam_date'] = format_date($date) . ' is a holiday (' . $h['title'] . ').';
            }
            $start = substr((string) $get('start_time'), 0, 5);
            $end = substr((string) $get('end_time'), 0, 5);
            if ($start && $end && $end <= $start) {
                $errors['end_time'] = 'The paper must end after it starts.';
            }
            if ($errors) {
                return $errors;
            }
            $inv = array_key_exists('invigilator_ids', $input) ? (array) (is_string($input['invigilator_ids']) ? (json_decode($input['invigilator_ids'], true) ?? explode(',', $input['invigilator_ids'])) : $input['invigilator_ids'])
                : array_filter(explode(',', (string) db_value('SELECT GROUP_CONCAT(faculty_id) FROM exam_invigilators WHERE exam_schedule_id = ?', [(int) $id])));
            if ($get('invigilator_id') && in_array((string) $get('invigilator_id'), array_map('strval', $inv), true)) {
                return ['invigilator_ids' => 'The chief invigilator is already selected - choose other faculty as additional invigilators.'];
            }
            return exam_schedule_conflicts(['exam_id' => $examId, 'subject_id' => $subjectId, 'exam_date' => $date, 'start_time' => $start, 'end_time' => $end,
                'classroom_id' => $get('classroom_id'), 'invigilator_id' => $get('invigilator_id')], $id, $inv);
        },
        'after_save' => function (int $id, array $d, ?array $old, array $input): void {
            if (array_key_exists('invigilator_ids', $input)) {
                $ids = is_array($input['invigilator_ids']) ? $input['invigilator_ids'] : (json_decode((string) $input['invigilator_ids'], true) ?? array_filter(explode(',', (string) $input['invigilator_ids'])));
                $room = $d['classroom_id'] ?? ($old['classroom_id'] ?? null);
                exam_sync_invigilators($id, (array) $ids, $room ? (int) $room : null);
            }
            $examId = (int) ($d['exam_id'] ?? $old['exam_id'] ?? 0);
            exam_refresh_schedule_status($examId);
            exam_assign_seats($examId);
        },
        'before_delete' => function (int $id, array $row): ?string {
            $e = exam_find((int) $row['exam_id']);
            if ($e && in_array($e['status'], ['verified', 'published'], true)) {
                return 'Marks of this exam are locked - the schedule cannot be changed.';
            }
            $n = (int) db_value('SELECT COUNT(*) FROM exam_attendance WHERE exam_schedule_id = ?', [$id]);
            return $n ? 'Attendance is already recorded for this paper and it cannot be removed.' : null;
        },
        'after_delete' => function (int $id, array $row): void {
            $e = db_row('SELECT status FROM exams WHERE id = ?', [(int) $row['exam_id']]);
            if ($e && $e['status'] === 'scheduled') {
                db_update('exams', ['status' => 'draft'], 'id = ?', [(int) $row['exam_id']]);
            }
            exam_assign_seats((int) $row['exam_id']);
        },
        'describe' => function (array $r): string {
            $s = db_row('SELECT code, name FROM subjects WHERE id = ?', [(int) ($r['subject_id'] ?? 0)]);
            return ($s ? $s['code'] . ' ' . $s['name'] : 'paper') . ' on ' . format_date($r['exam_date'] ?? null);
        },
    ],
];
