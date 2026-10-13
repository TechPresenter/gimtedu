<?php
/**
 * Named option sources used by selects, filters and async comboboxes (GET /api/lookup/{source}).
 *
 * Shape of a source:
 *   'table'   => 'programs p'                     (FROM clause, may include alias)
 *   'joins'   => 'LEFT JOIN ...'                   (optional)
 *   'value'   => 'p.id'
 *   'label'   => "CONCAT(p.short_name, ' - ', p.name)"
 *   'sub'     => 'p.code'                          (optional secondary text)
 *   'where'   => "p.status = 'active'"             (optional base condition)
 *   'order'   => 'p.sort_order, p.name'
 *   'search'  => ['p.name', 'p.short_name']        (columns used for ?q=)
 *   'filters' => ['department_id' => 'p.department_id']   (allowed ?param=value narrowing, e.g. dependent selects)
 *   'async'   => true                              (large tables: client searches instead of preloading)
 *
 * Module configs may also define a source inline using the same shape.
 */

function lookup_sources(): array
{
    $personName = fn (string $a) => "TRIM(CONCAT_WS(' ', $a.first_name, $a.last_name))";
    return [
        'sessions' => ['table' => 'academic_sessions s', 'value' => 's.id', 'label' => 's.name', 'order' => 's.start_date DESC', 'search' => ['s.name']],
        'departments' => ['table' => 'departments d', 'value' => 'd.id', 'label' => 'd.name', 'sub' => 'd.code', 'where' => "d.status = 'active'", 'order' => 'd.name', 'search' => ['d.name', 'd.code']],
        'programs' => ['table' => 'programs p', 'value' => 'p.id', 'label' => "CONCAT(p.short_name, ' — ', p.name)", 'sub' => 'p.level', 'where' => "p.status = 'active'", 'order' => 'p.sort_order, p.name',
            'search' => ['p.name', 'p.short_name', 'p.code'], 'filters' => ['department_id' => 'p.department_id', 'level' => 'p.level']],
        'courses' => ['table' => 'courses c', 'value' => 'c.id', 'label' => 'c.name', 'sub' => 'c.code', 'where' => "c.status = 'active'", 'order' => 'c.name', 'search' => ['c.name', 'c.code'], 'filters' => ['program_id' => 'c.program_id']],
        'subjects' => ['table' => 'subjects sb', 'value' => 'sb.id', 'label' => "CONCAT(sb.code, ' — ', sb.name)", 'sub' => "CONCAT('Sem ', sb.semester_no)", 'where' => "sb.status = 'active'", 'order' => 'sb.semester_no, sb.code',
            'search' => ['sb.name', 'sb.code'], 'filters' => ['program_id' => 'sb.program_id', 'semester_no' => 'sb.semester_no']],
        'sections' => ['table' => 'sections sc', 'joins' => 'JOIN programs p ON p.id = sc.program_id', 'value' => 'sc.id', 'label' => "CONCAT(p.short_name, ' · Sem ', sc.semester_no, ' · Sec ', sc.name)",
            'where' => "sc.status = 'active'", 'order' => 'p.sort_order, sc.semester_no, sc.name', 'search' => ['p.short_name', 'sc.name'],
            'filters' => ['program_id' => 'sc.program_id', 'semester_no' => 'sc.semester_no', 'academic_session_id' => 'sc.academic_session_id']],
        'batches' => ['table' => 'batches b', 'value' => 'b.id', 'label' => 'b.name', 'order' => 'b.start_year DESC', 'search' => ['b.name'], 'filters' => ['program_id' => 'b.program_id']],
        'classrooms' => ['table' => 'classrooms r', 'value' => 'r.id', 'label' => "CONCAT(r.code, ' — ', r.name)", 'sub' => "CONCAT(r.capacity, ' seats')", 'where' => "r.status = 'active'", 'order' => 'r.code', 'search' => ['r.name', 'r.code'], 'filters' => ['type' => 'r.type']],
        'time_slots' => ['table' => 'time_slots ts', 'value' => 'ts.id', 'label' => "CONCAT(ts.name, ' (', TIME_FORMAT(ts.start_time, '%h:%i %p'), ' - ', TIME_FORMAT(ts.end_time, '%h:%i %p'), ')')", 'order' => 'ts.sort_order', 'search' => ['ts.name']],
        'faculty' => ['table' => 'faculty f', 'value' => 'f.id', 'label' => "TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name))", 'sub' => 'f.designation', 'where' => "f.status IN ('active','on_leave')",
            'order' => 'f.first_name', 'search' => ['f.first_name', 'f.last_name', 'f.employee_id', 'f.email'], 'filters' => ['department_id' => 'f.department_id']],
        'staff' => ['table' => 'staff st', 'value' => 'st.id', 'label' => $personName('st'), 'sub' => 'st.designation', 'where' => "st.status = 'active'", 'order' => 'st.first_name',
            'search' => ['st.first_name', 'st.last_name', 'st.employee_id'], 'filters' => ['category' => 'st.category']],
        'students' => ['table' => 'students s', 'joins' => 'LEFT JOIN programs p ON p.id = s.program_id', 'value' => 's.id', 'label' => "CONCAT(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)), ' (', s.student_uid, ')')",
            'sub' => "CONCAT(p.short_name, ' · Sem ', s.current_semester)", 'where' => "s.status = 'active'", 'order' => 's.first_name, s.last_name',
            'search' => ['s.first_name', 's.last_name', 's.student_uid', 's.admission_no', 's.roll_no', 's.mobile', 's.email', "CONCAT(s.first_name, ' ', s.last_name)"],
            'filters' => ['program_id' => 's.program_id', 'semester' => 's.current_semester', 'section_id' => 's.section_id', 'department_id' => 's.department_id'], 'async' => true],
        'all_students' => ['table' => 'students s', 'value' => 's.id', 'label' => "CONCAT(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)), ' (', s.student_uid, ')')", 'sub' => 's.status', 'order' => 's.first_name',
            'search' => ['s.first_name', 's.last_name', 's.student_uid', 's.admission_no'], 'async' => true],
        'users' => ['table' => 'users u', 'value' => 'u.id', 'label' => 'u.name', 'sub' => 'u.email', 'where' => "u.status = 'active'", 'order' => 'u.name', 'search' => ['u.name', 'u.email', 'u.username']],
        'roles' => ['table' => 'roles r', 'value' => 'r.id', 'label' => 'r.name', 'order' => 'r.id', 'search' => ['r.name']],
        'fee_heads' => ['table' => 'fee_heads fh', 'value' => 'fh.id', 'label' => 'fh.name', 'sub' => 'fh.type', 'where' => "fh.status = 'active'", 'order' => 'fh.sort_order', 'search' => ['fh.name', 'fh.code']],
        'fee_structures' => ['table' => 'fee_structures fs', 'value' => 'fs.id', 'label' => 'fs.name', 'sub' => 'fs.total_amount', 'where' => "fs.status = 'active'", 'order' => 'fs.name', 'search' => ['fs.name'],
            'filters' => ['program_id' => 'fs.program_id', 'academic_session_id' => 'fs.academic_session_id']],
        'scholarships' => ['table' => 'scholarships sc', 'value' => 'sc.id', 'label' => 'sc.name', 'sub' => "CONCAT(sc.value, IF(sc.type = 'percentage', '%', ' INR'))", 'where' => "sc.status = 'active'", 'order' => 'sc.name', 'search' => ['sc.name']],
        'expense_categories' => ['table' => 'expense_categories ec', 'value' => 'ec.id', 'label' => 'ec.name', 'where' => "ec.status = 'active'", 'order' => 'ec.name', 'search' => ['ec.name']],
        'exam_types' => ['table' => 'exam_types et', 'value' => 'et.id', 'label' => 'et.name', 'where' => "et.status = 'active'", 'order' => 'et.id', 'search' => ['et.name']],
        'exams' => ['table' => 'exams e', 'joins' => 'LEFT JOIN programs p ON p.id = e.program_id', 'value' => 'e.id', 'label' => 'e.name', 'sub' => "CONCAT(COALESCE(p.short_name, 'All programs'), IFNULL(CONCAT(' · Sem ', e.semester_no), ''))",
            'order' => 'e.start_date DESC', 'search' => ['e.name'], 'filters' => ['program_id' => 'e.program_id', 'academic_session_id' => 'e.academic_session_id', 'status' => 'e.status']],
        'certificate_templates' => ['table' => 'certificate_templates ct', 'value' => 'ct.id', 'label' => 'ct.name', 'sub' => 'ct.type', 'where' => "ct.status = 'active'", 'order' => 'ct.type, ct.name', 'search' => ['ct.name'], 'filters' => ['type' => 'ct.type']],
        'companies' => ['table' => 'companies c', 'value' => 'c.id', 'label' => 'c.name', 'sub' => 'c.industry', 'where' => "c.status = 'active'", 'order' => 'c.name', 'search' => ['c.name', 'c.industry']],
        'placement_drives' => ['table' => 'placement_drives d', 'joins' => 'JOIN companies c ON c.id = d.company_id', 'value' => 'd.id', 'label' => "CONCAT(c.name, ' — ', d.job_role)", 'sub' => 'd.status',
            'order' => 'd.drive_date DESC', 'search' => ['c.name', 'd.job_role', 'd.title'], 'filters' => ['company_id' => 'd.company_id', 'status' => 'd.status']],
        'hostels' => ['table' => 'hostels h', 'value' => 'h.id', 'label' => 'h.name', 'sub' => 'h.type', 'where' => "h.status = 'active'", 'order' => 'h.name', 'search' => ['h.name', 'h.code']],
        'hostel_rooms' => ['table' => 'hostel_rooms hr', 'joins' => 'JOIN hostels h ON h.id = hr.hostel_id', 'value' => 'hr.id', 'label' => "CONCAT(h.code, ' · Room ', hr.room_no)",
            'sub' => "CONCAT(hr.occupied, '/', hr.capacity, ' occupied')", 'where' => "hr.status <> 'inactive'", 'order' => 'h.name, hr.room_no', 'search' => ['hr.room_no', 'h.name'], 'filters' => ['hostel_id' => 'hr.hostel_id', 'status' => 'hr.status']],
        'hostel_beds' => ['table' => 'hostel_beds hb', 'value' => 'hb.id', 'label' => "CONCAT('Bed ', hb.bed_no)", 'sub' => 'hb.status', 'order' => 'hb.bed_no', 'search' => ['hb.bed_no'], 'filters' => ['room_id' => 'hb.room_id', 'status' => 'hb.status']],
        'transport_routes' => ['table' => 'transport_routes tr', 'value' => 'tr.id', 'label' => "CONCAT(tr.code, ' — ', tr.name)", 'where' => "tr.status = 'active'", 'order' => 'tr.code', 'search' => ['tr.name', 'tr.code']],
        'transport_stops' => ['table' => 'transport_stops ts', 'value' => 'ts.id', 'label' => 'ts.name', 'sub' => "TIME_FORMAT(ts.pickup_time, '%h:%i %p')", 'order' => 'ts.sequence', 'search' => ['ts.name'], 'filters' => ['route_id' => 'ts.route_id']],
        'vehicles' => ['table' => 'vehicles v', 'value' => 'v.id', 'label' => "CONCAT(v.vehicle_no, ' (', v.type, ')')", 'sub' => "CONCAT(v.capacity, ' seats')", 'order' => 'v.vehicle_no', 'search' => ['v.vehicle_no', 'v.model'], 'filters' => ['route_id' => 'v.route_id', 'status' => 'v.status']],
        'drivers' => ['table' => 'drivers dr', 'value' => 'dr.id', 'label' => 'dr.name', 'sub' => 'dr.phone', 'where' => "dr.status = 'active'", 'order' => 'dr.name', 'search' => ['dr.name', 'dr.phone', 'dr.license_no'], 'filters' => ['type' => 'dr.type']],
        'book_categories' => ['table' => 'book_categories bc', 'value' => 'bc.id', 'label' => 'bc.name', 'order' => 'bc.name', 'search' => ['bc.name']],
        'authors' => ['table' => 'authors a', 'value' => 'a.id', 'label' => 'a.name', 'order' => 'a.name', 'search' => ['a.name'], 'async' => true],
        'publishers' => ['table' => 'publishers pb', 'value' => 'pb.id', 'label' => 'pb.name', 'order' => 'pb.name', 'search' => ['pb.name']],
        'books' => ['table' => 'books b', 'value' => 'b.id', 'label' => 'b.title', 'sub' => "CONCAT(COALESCE(b.isbn, ''), ' · ', b.available_copies, ' available')", 'order' => 'b.title', 'search' => ['b.title', 'b.isbn'], 'async' => true],
        'library_members' => ['table' => 'library_members lm', 'joins' => 'LEFT JOIN students s ON s.id = lm.student_id LEFT JOIN faculty f ON f.id = lm.faculty_id LEFT JOIN staff st ON st.id = lm.staff_id',
            'value' => 'lm.id', 'label' => "CONCAT(COALESCE(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)), TRIM(CONCAT_WS(' ', f.first_name, f.last_name)), TRIM(CONCAT_WS(' ', st.first_name, st.last_name))), ' (', lm.membership_no, ')')",
            'sub' => 'lm.member_type', 'where' => "lm.status = 'active'", 'order' => 'lm.membership_no', 'search' => ['lm.membership_no', 's.first_name', 's.last_name', 'f.first_name', 'f.last_name', 'st.first_name', 's.student_uid'], 'async' => true],
        'alumni' => ['table' => 'alumni a', 'value' => 'a.id', 'label' => 'a.name', 'sub' => "CONCAT('Batch ', a.batch_year, IFNULL(CONCAT(' · ', a.company), ''))", 'order' => 'a.name', 'search' => ['a.name', 'a.email', 'a.company'], 'async' => true],
        'blog_categories' => ['table' => 'blog_categories bc', 'value' => 'bc.id', 'label' => 'bc.name', 'order' => 'bc.name', 'search' => ['bc.name']],
        'pages' => ['table' => 'pages pg', 'value' => 'pg.id', 'label' => 'pg.title', 'sub' => "CONCAT('/', pg.slug)", 'order' => 'pg.title', 'search' => ['pg.title', 'pg.slug']],
        'menus' => ['table' => 'menus m', 'value' => 'm.id', 'label' => 'm.name', 'sub' => 'm.location', 'order' => 'm.name', 'search' => ['m.name']],
        'galleries' => ['table' => 'galleries g', 'value' => 'g.id', 'label' => 'g.title', 'order' => 'g.sort_order, g.title', 'search' => ['g.title']],
        'admissions' => ['table' => 'admissions a', 'value' => 'a.id', 'label' => "CONCAT(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), ' (', a.application_no, ')')", 'sub' => 'a.stage', 'order' => 'a.created_at DESC',
            'search' => ['a.first_name', 'a.last_name', 'a.application_no', 'a.phone', 'a.email'], 'async' => true],
        'student_fees' => ['table' => 'student_fees sf', 'value' => 'sf.id', 'label' => "CONCAT(sf.invoice_no, ' — ', sf.title)", 'sub' => "CONCAT('Balance ', sf.balance_amount)", 'order' => 'sf.due_date', 'search' => ['sf.invoice_no', 'sf.title'],
            'filters' => ['student_id' => 'sf.student_id', 'status' => 'sf.status'], 'async' => true],
    ];
}

/**
 * Resolve options for a source (name or inline array).
 * $params may contain: q (search), ids (comma list - fetch these values), any key in 'filters', limit.
 * @return array<int, array{value:mixed,label:string,sub?:string}>
 */
function lookup_options($source, array $params = [], int $defaultLimit = 1000): array
{
    $def = is_array($source) ? $source : (lookup_sources()[$source] ?? null);
    if (!$def) {
        throw new InvalidArgumentException('Unknown lookup source: ' . (is_string($source) ? $source : 'inline'));
    }
    $select = $def['value'] . ' AS value, ' . $def['label'] . ' AS label' . (!empty($def['sub']) ? ', ' . $def['sub'] . ' AS sub' : '');
    $sql = 'SELECT ' . $select . ' FROM ' . $def['table'] . ' ' . ($def['joins'] ?? '');
    $where = [];
    $args = [];
    if (!empty($params['ids'])) {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $params['ids'])), fn ($v) => $v !== ''));
        if (!$ids) {
            return [];
        }
        $where[] = $def['value'] . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = array_merge($args, $ids);
    } else {
        if (!empty($def['where'])) {
            $where[] = '(' . $def['where'] . ')';
        }
        foreach ($def['filters'] ?? [] as $param => $column) {
            if (isset($params[$param]) && $params[$param] !== '' && !is_array($params[$param])) {
                $where[] = $column . ' = ?';
                $args[] = $params[$param];
            }
        }
        $q = trim((string) ($params['q'] ?? ''));
        if ($q !== '' && !empty($def['search'])) {
            $or = [];
            foreach ($def['search'] as $col) {
                $or[] = $col . ' LIKE ?';
                $args[] = '%' . $q . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . ($def['order'] ?? '2');
    $limit = (int) ($params['limit'] ?? (!empty($def['async']) ? 30 : $defaultLimit));
    $sql .= ' LIMIT ' . max(1, min(1000, $limit));
    $rows = db_all($sql, $args);
    return array_map(function ($r) {
        $o = ['value' => is_numeric($r['value']) ? (int) $r['value'] : $r['value'], 'label' => (string) $r['label']];
        if (isset($r['sub']) && $r['sub'] !== null && $r['sub'] !== '') {
            $o['sub'] = (string) $r['sub'];
        }
        return $o;
    }, $rows);
}
