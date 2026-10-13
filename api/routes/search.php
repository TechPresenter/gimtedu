<?php
/**
 * Global search (Ctrl/Cmd + K command palette in the admin top bar).
 *   GET /api/search?q=rahul&limit=5
 *     -> { query, groups: [{ key, label, icon, items: [{ id, title, subtitle, url, badge, image, icon }] }] }
 *
 * Grouped, permission-aware results: a group is searched only when the user may view its module. People and document
 * numbers use prefix matches (index friendly); short content tables (notices, events, pages, posts …) use contains
 * matches on the title. `url` is an SPA route (relative to /admin). Minimum query length: 2 characters.
 */

/** Escape LIKE wildcards in user input. */
function search_like(string $s): string
{
    return addcslashes($s, '\\%_');
}

/**
 * WHERE fragment for a person name split into first/last columns.
 * One word: first or last name starts with it. Several words: first starts with the first word and last starts
 * with the last word, or the full name starts with the whole query.
 */
function search_name_sql(string $first, string $last, array $tokens, string $full): array
{
    if (count($tokens) === 1) {
        return ["($first LIKE ? OR $last LIKE ?)", [$tokens[0] . '%', $tokens[0] . '%']];
    }
    return ["(($first LIKE ? AND $last LIKE ?) OR CONCAT_WS(' ', $first, $last) LIKE ?)", [$tokens[0] . '%', end($tokens) . '%', $full . '%']];
}

/** Digits of the query when it looks like a phone number fragment (4+ digits), else null. */
function search_digits(string $q): ?string
{
    $d = preg_replace('/\D+/', '', $q);
    return strlen($d) >= 4 && preg_match('/^[\d\s+\-()]+$/', $q) ? $d : null;
}

function search_item($id, string $title, ?string $subtitle, string $url, string $icon, ?string $badge = null, ?string $image = null): array
{
    return ['id' => $id, 'title' => $title, 'subtitle' => $subtitle ?: null, 'url' => $url, 'icon' => $icon, 'badge' => $badge, 'image' => $image ? upload_url($image) : null];
}

/** Display name from first/middle/last columns (single spaces). */
function search_person(array $r): string
{
    return trim(preg_replace('/\s+/', ' ', ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? '')));
}

/** "MBA · Sem 3" style joiner that skips empty parts. */
function search_join(array $parts): string
{
    return implode(' · ', array_values(array_filter(array_map(fn ($p) => is_string($p) ? trim($p) : $p, $parts), fn ($p) => $p !== null && $p !== '')));
}

route('GET', '/search', function () {
    $q = trim(preg_replace('/\s+/', ' ', (string) ($_GET['q'] ?? '')));
    $q = mb_substr($q, 0, 80);
    if (mb_strlen($q) < 2) {
        api_ok(['query' => $q, 'groups' => []]);
    }
    $limit = max(1, min(10, (int) ($_GET['limit'] ?? 5)));
    $like = search_like($q);
    $tokens = array_values(array_filter(explode(' ', $like), fn ($t) => $t !== ''));
    $contains = '%' . $like . '%';
    $prefix = $like . '%';
    $digits = search_digits($q);
    $groups = [];
    $add = function (string $key, string $label, string $icon, callable $fn) use (&$groups) {
        try {
            $items = $fn();
        } catch (Throwable $e) {
            error_log('[GIMT] search group ' . $key . ' failed: ' . $e->getMessage());
            $items = [];
        }
        if ($items) {
            $groups[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'items' => $items];
        }
    };

    if (can('students')) {
        $add('students', 'Students', 'graduation-cap', function () use ($tokens, $like, $prefix, $digits, $limit) {
            [$nameSql, $args] = search_name_sql('s.first_name', 's.last_name', $tokens, $like);
            $where = "$nameSql OR s.student_uid LIKE ? OR s.admission_no LIKE ? OR s.roll_no LIKE ? OR s.enrollment_no LIKE ? OR s.email LIKE ?";
            array_push($args, $prefix, $prefix, $prefix, $prefix, $prefix);
            if ($digits) {
                $where .= ' OR s.mobile LIKE ?';
                $args[] = '%' . $digits . '%';
            }
            $rows = db_all("SELECT s.id, s.first_name, s.middle_name, s.last_name, s.student_uid, s.photo, s.status, s.current_semester, COALESCE(NULLIF(p.short_name, ''), p.name) AS program
                            FROM students s LEFT JOIN programs p ON p.id = s.program_id WHERE $where
                            ORDER BY (s.status = 'active') DESC, (CONCAT_WS(' ', s.first_name, s.last_name) LIKE ?) DESC, s.first_name, s.last_name LIMIT $limit", array_merge($args, [$like . '%']));
            return array_map(fn ($r) => search_item((int) $r['id'], search_person($r), search_join([$r['student_uid'], $r['program'], $r['current_semester'] ? 'Sem ' . $r['current_semester'] : null]),
                '/students/' . $r['id'], 'graduation-cap', $r['status'] !== 'active' ? label_from_key($r['status']) : null, $r['photo']), $rows);
        });
    }

    if (can('faculty')) {
        $add('faculty', 'Faculty', 'users', function () use ($tokens, $like, $prefix, $digits, $limit) {
            [$nameSql, $args] = search_name_sql('f.first_name', 'f.last_name', $tokens, $like);
            $where = "$nameSql OR f.employee_id LIKE ? OR f.email LIKE ?";
            array_push($args, $prefix, $prefix);
            if ($digits) {
                $where .= ' OR f.phone LIKE ?';
                $args[] = '%' . $digits . '%';
            }
            $rows = db_all("SELECT f.id, f.title, f.first_name, f.last_name, f.employee_id, f.designation, f.photo, f.status, d.code AS dept
                            FROM faculty f LEFT JOIN departments d ON d.id = f.department_id WHERE $where ORDER BY (f.status = 'active') DESC, (CONCAT_WS(' ', f.first_name, f.last_name) LIKE ?) DESC, f.first_name LIMIT $limit", array_merge($args, [$like . '%']));
            return array_map(fn ($r) => search_item((int) $r['id'], trim(($r['title'] ? $r['title'] . ' ' : '') . $r['first_name'] . ' ' . $r['last_name']),
                search_join([$r['employee_id'], $r['designation'], $r['dept']]), '/faculty/' . $r['id'], 'user-round', $r['status'] !== 'active' ? label_from_key($r['status']) : null, $r['photo']), $rows);
        });
        $add('staff', 'Staff', 'id-card', function () use ($tokens, $like, $prefix, $digits, $limit) {
            [$nameSql, $args] = search_name_sql('s.first_name', 's.last_name', $tokens, $like);
            $where = "$nameSql OR s.employee_id LIKE ? OR s.email LIKE ?";
            array_push($args, $prefix, $prefix);
            if ($digits) {
                $where .= ' OR s.phone LIKE ?';
                $args[] = '%' . $digits . '%';
            }
            $rows = db_all("SELECT s.id, s.first_name, s.last_name, s.employee_id, s.designation, s.photo, s.status FROM staff s WHERE $where ORDER BY (s.status = 'active') DESC, (CONCAT_WS(' ', s.first_name, s.last_name) LIKE ?) DESC, s.first_name LIMIT $limit", array_merge($args, [$like . '%']));
            return array_map(fn ($r) => search_item((int) $r['id'], trim($r['first_name'] . ' ' . $r['last_name']), search_join([$r['employee_id'], $r['designation']]),
                '/staff/' . $r['id'], 'id-card', $r['status'] !== 'active' ? label_from_key($r['status']) : null, $r['photo']), $rows);
        });
    }

    if (can('admissions')) {
        $add('admissions', 'Admissions', 'user-plus', function () use ($tokens, $like, $prefix, $digits, $limit) {
            [$nameSql, $args] = search_name_sql('a.first_name', 'a.last_name', $tokens, $like);
            $where = "$nameSql OR a.application_no LIKE ? OR a.email LIKE ?";
            array_push($args, $prefix, $prefix);
            if ($digits) {
                $where .= ' OR a.phone LIKE ?';
                $args[] = '%' . $digits . '%';
            }
            $rows = db_all("SELECT a.id, a.first_name, a.middle_name, a.last_name, a.application_no, a.stage, a.photo, COALESCE(NULLIF(p.short_name, ''), p.name) AS program
                            FROM admissions a LEFT JOIN programs p ON p.id = a.program_id WHERE $where ORDER BY (CONCAT_WS(' ', a.first_name, a.last_name) LIKE ?) DESC, a.created_at DESC LIMIT $limit", array_merge($args, [$like . '%']));
            return array_map(fn ($r) => search_item((int) $r['id'], search_person($r), search_join([$r['application_no'], $r['program']]), '/admissions/' . $r['id'], 'user-plus', label_from_key((string) $r['stage']), $r['photo']), $rows);
        });
    }

    if (can('enquiries')) {
        $add('enquiries', 'Enquiries', 'message-circle', function () use ($prefix, $digits, $limit) {
            // name starts with the query, or any later word of the name does
            $where = 'e.name LIKE ? OR e.name LIKE ? OR e.email LIKE ?';
            $args = [$prefix, '% ' . $prefix, $prefix];
            if ($digits) {
                $where .= ' OR e.phone LIKE ?';
                $args[] = '%' . $digits . '%';
            }
            $rows = db_all("SELECT e.id, e.name, e.phone, e.status, e.created_at, COALESCE(NULLIF(p.short_name, ''), p.name, e.program_interest) AS program
                            FROM enquiries e LEFT JOIN programs p ON p.id = e.program_id WHERE $where ORDER BY e.created_at DESC LIMIT $limit", $args);
            return array_map(fn ($r) => search_item((int) $r['id'], $r['name'], search_join([$r['phone'], $r['program'], 'Received ' . format_date($r['created_at'])]),
                '/enquiries?q=' . rawurlencode($r['phone'] ?: $r['name']), 'message-circle', label_from_key((string) $r['status'])), $rows);
        });
    }

    if (can('academics') || can('admissions') || can('students')) {
        $add('programs', 'Programs & Courses', 'book-open', function () use ($contains, $prefix, $limit) {
            $rows = db_all("SELECT p.id, p.name, p.short_name, p.code, p.level, p.duration_label, p.status, d.code AS dept FROM programs p LEFT JOIN departments d ON d.id = p.department_id
                            WHERE p.name LIKE ? OR p.short_name LIKE ? OR p.code LIKE ? ORDER BY (p.status = 'active') DESC, p.sort_order LIMIT $limit", [$contains, $contains, $prefix]);
            $items = array_map(fn ($r) => search_item('p' . $r['id'], $r['name'], search_join([$r['code'], $r['level'], $r['duration_label'], $r['dept']]),
                '/academics?tab=programs&q=' . rawurlencode($r['code'] ?: $r['name']), 'book-open', $r['status'] !== 'active' ? label_from_key($r['status']) : 'Program'), $rows);
            if (count($items) < $limit) {
                $courses = db_all("SELECT c.id, c.name, c.code, c.status, COALESCE(NULLIF(p.short_name, ''), p.name) AS program FROM courses c LEFT JOIN programs p ON p.id = c.program_id
                                   WHERE c.name LIKE ? OR c.code LIKE ? ORDER BY c.name LIMIT " . ($limit - count($items)), [$contains, $prefix]);
                foreach ($courses as $r) {
                    $items[] = search_item('c' . $r['id'], $r['name'], search_join([$r['code'], $r['program']]), '/academics?tab=courses&q=' . rawurlencode($r['code'] ?: $r['name']), 'layers', 'Course');
                }
            }
            return $items;
        });
    }

    if (can('fees')) {
        $add('fees', 'Fees, Invoices & Receipts', 'indian-rupee', function () use ($tokens, $like, $prefix, $limit) {
            $items = [];
            [$nameSql, $nameArgs] = search_name_sql('s.first_name', 's.last_name', $tokens, $like);
            $pays = db_all("SELECT p.id, p.receipt_no, p.amount + p.fine_amount AS amount, p.payment_date, p.status, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student
                            FROM payments p LEFT JOIN students s ON s.id = p.student_id WHERE p.receipt_no LIKE ? ORDER BY p.payment_date DESC LIMIT $limit", [$prefix]);
            foreach ($pays as $r) {
                $items[] = search_item('r' . $r['id'], 'Receipt ' . $r['receipt_no'], search_join([$r['student'], money((float) $r['amount']), format_date($r['payment_date'])]),
                    '/fees/payments?q=' . rawurlencode($r['receipt_no']), 'receipt', label_from_key((string) $r['status']));
            }
            $inv = db_all("SELECT t.id, t.invoice_no, t.title, t.net_amount, t.balance_amount, t.status, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student, s.student_uid
                           FROM student_fees t JOIN students s ON s.id = t.student_id WHERE t.invoice_no LIKE ? OR $nameSql OR s.student_uid LIKE ?
                           ORDER BY (t.invoice_no LIKE ?) DESC, t.created_at DESC LIMIT " . max(1, $limit - count($items)),
                array_merge([$prefix], $nameArgs, [$prefix, $prefix]));
            foreach ($inv as $r) {
                $items[] = search_item('i' . $r['id'], $r['invoice_no'] . ' · ' . $r['student'], search_join([$r['title'], 'Net ' . money((float) $r['net_amount']), (float) $r['balance_amount'] > 0 ? 'Due ' . money((float) $r['balance_amount']) : null]),
                    '/fees/invoices?q=' . rawurlencode($r['invoice_no']), 'file-text', label_from_key((string) $r['status']));
            }
            return array_slice($items, 0, $limit);
        });
    }

    if (can('results')) {
        $add('results', 'Results', 'award', function () use ($tokens, $like, $prefix, $limit) {
            [$nameSql, $args] = search_name_sql('s.first_name', 's.last_name', $tokens, $like);
            $args[] = $prefix;
            $rows = db_all("SELECT r.id, r.sgpa, r.percentage, r.result_status, r.is_published, e.name AS exam, s.first_name, s.last_name, s.student_uid
                            FROM results r JOIN students s ON s.id = r.student_id LEFT JOIN exams e ON e.id = r.exam_id
                            WHERE $nameSql OR s.student_uid LIKE ? ORDER BY r.created_at DESC LIMIT $limit", $args);
            return array_map(fn ($r) => search_item((int) $r['id'], trim($r['first_name'] . ' ' . $r['last_name']) . ' · ' . ($r['exam'] ?: 'Result'),
                search_join([$r['student_uid'], $r['sgpa'] !== null ? 'SGPA ' . $r['sgpa'] : null, $r['percentage'] !== null ? $r['percentage'] . '%' : null, $r['is_published'] ? 'Published' : 'Not published']),
                '/results?q=' . rawurlencode($r['student_uid']), 'award', $r['result_status']), $rows);
        });
    }

    if (can('notices')) {
        $add('notices', 'Notices & Circulars', 'megaphone', function () use ($contains, $limit) {
            $rows = db_all("SELECT id, title, category, status, publish_date, created_at FROM notices WHERE title LIKE ? ORDER BY COALESCE(publish_date, created_at) DESC LIMIT $limit", [$contains]);
            return array_map(fn ($r) => search_item((int) $r['id'], $r['title'], search_join([label_from_key((string) $r['category']), format_date($r['publish_date'] ?: $r['created_at'])]),
                '/notices?q=' . rawurlencode($r['title']), 'megaphone', label_from_key((string) $r['status'])), $rows);
        });
    }

    if (can('events')) {
        $add('events', 'Events', 'calendar-range', function () use ($contains, $limit) {
            $rows = db_all("SELECT id, title, category, venue, start_datetime, status FROM events WHERE title LIKE ? OR venue LIKE ? ORDER BY start_datetime DESC LIMIT $limit", [$contains, $contains]);
            return array_map(fn ($r) => search_item((int) $r['id'], $r['title'], search_join([format_datetime($r['start_datetime']), $r['venue']]),
                '/events?q=' . rawurlencode($r['title']), 'calendar-range', label_from_key((string) $r['status'])), $rows);
        });
    }

    if (can('cms')) {
        $add('pages', 'Website Pages', 'globe', function () use ($contains, $limit) {
            $rows = db_all("SELECT id, title, slug, status, updated_at FROM pages WHERE title LIKE ? OR slug LIKE ? ORDER BY title LIMIT $limit", [$contains, $contains]);
            return array_map(fn ($r) => search_item((int) $r['id'], $r['title'], '/' . ltrim((string) $r['slug'], '/'), '/cms/pages?q=' . rawurlencode($r['title']), 'globe', label_from_key((string) $r['status'])), $rows);
        });
    }

    if (can('blog')) {
        $add('blog', 'Blog & News', 'newspaper', function () use ($contains, $limit) {
            $rows = db_all("SELECT id, title, type, status, published_at, created_at FROM blog_posts WHERE title LIKE ? OR tags LIKE ? ORDER BY COALESCE(published_at, created_at) DESC LIMIT $limit", [$contains, $contains]);
            return array_map(fn ($r) => search_item((int) $r['id'], $r['title'], search_join([label_from_key((string) ($r['type'] ?: 'post')), format_date($r['published_at'] ?: $r['created_at'])]),
                '/cms/blog?q=' . rawurlencode($r['title']), 'newspaper', label_from_key((string) $r['status'])), $rows);
        });
    }

    if (can('alumni')) {
        $add('alumni', 'Alumni', 'users-round', function () use ($prefix, $limit) {
            $rows = db_all("SELECT a.id, a.name, a.batch_year, a.company, a.designation, a.photo, COALESCE(NULLIF(p.short_name, ''), p.name) AS program
                            FROM alumni a LEFT JOIN programs p ON p.id = a.program_id WHERE a.name LIKE ? OR a.name LIKE ? OR a.email LIKE ? OR a.company LIKE ? ORDER BY a.name LIMIT $limit",
                [$prefix, '% ' . $prefix, $prefix, $prefix]);
            return array_map(fn ($r) => search_item((int) $r['id'], $r['name'], search_join([$r['program'], 'Batch ' . $r['batch_year'], $r['designation'] && $r['company'] ? $r['designation'] . ' at ' . $r['company'] : $r['company']]),
                '/alumni?q=' . rawurlencode($r['name']), 'users-round', null, $r['photo']), $rows);
        });
    }

    if (can('placement')) {
        $add('companies', 'Companies & Recruiters', 'building-2', function () use ($contains, $limit) {
            $rows = db_all("SELECT id, name, industry, city, status, is_recruiter FROM companies WHERE name LIKE ? ORDER BY name LIMIT $limit", [$contains]);
            return array_map(fn ($r) => search_item((int) $r['id'], $r['name'], search_join([$r['industry'], $r['city'], $r['is_recruiter'] ? 'Recruiter' : null]),
                '/placement/companies?q=' . rawurlencode($r['name']), 'building-2', $r['status'] !== 'active' ? label_from_key((string) $r['status']) : null), $rows);
        });
    }

    if (can('library')) {
        $add('books', 'Library Books', 'library', function () use ($contains, $prefix, $limit) {
            $rows = db_all("SELECT b.id, b.title, b.isbn, b.available_copies, b.total_copies, au.name AS author FROM books b LEFT JOIN authors au ON au.id = b.author_id
                            WHERE b.title LIKE ? OR b.isbn LIKE ? OR au.name LIKE ? ORDER BY (b.title LIKE ?) DESC, b.title LIMIT $limit", [$contains, $prefix, $prefix, $prefix]);
            return array_map(fn ($r) => search_item((int) $r['id'], $r['title'], search_join([$r['author'], $r['isbn'] ? 'ISBN ' . $r['isbn'] : null, (int) $r['available_copies'] . '/' . (int) $r['total_copies'] . ' available']),
                '/library/books?q=' . rawurlencode($r['isbn'] ?: $r['title']), 'library', (int) $r['available_copies'] > 0 ? 'Available' : 'Issued out'), $rows);
        });
    }

    if (can('users')) {
        $add('users', 'Admin Users', 'user-cog', function () use ($prefix, $limit) {
            $rows = db_all("SELECT id, name, username, email, designation, avatar, status FROM users WHERE name LIKE ? OR name LIKE ? OR username LIKE ? OR email LIKE ? ORDER BY name LIMIT $limit",
                [$prefix, '% ' . $prefix, $prefix, $prefix]);
            return array_map(fn ($r) => search_item((int) $r['id'], $r['name'], search_join([$r['designation'], $r['email']]), '/users?q=' . rawurlencode($r['email']), 'user-cog',
                $r['status'] !== 'active' ? label_from_key((string) $r['status']) : null, $r['avatar']), $rows);
        });
    }

    api_ok(['query' => $q, 'groups' => $groups]);
});
