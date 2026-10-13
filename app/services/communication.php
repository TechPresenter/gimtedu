<?php
/**
 * Communication unit — notices & circulars, events & registrations, newsletter campaigns, email templates,
 * message logs and the batched outbound delivery queue (message_queue).
 *
 * Delivery model: a broadcast (notice publish, campaign send, event reminder) resolves its audience, writes one
 * message_queue row per (channel, recipient) under a batch id, then the queue is processed in time-boxed chunks
 * (comm_process_queue) — the SPA keeps calling the process endpoint and shows real progress. Each processed row is
 * handed to send_mail / send_sms / send_whatsapp (app/mailer.php) which writes the final message_logs entry.
 * SMS and WhatsApp are only used when enabled under Settings → Email & SMS.
 */

/* ------------------------------------------------------------------
 * Reference data
 * ------------------------------------------------------------------ */

function comm_notice_categories(): array
{
    return ['general' => 'General', 'academic' => 'Academic', 'examination' => 'Examination', 'admission' => 'Admission', 'holiday' => 'Holiday',
        'event' => 'Event', 'placement' => 'Placement', 'fee' => 'Fees', 'circular' => 'Circular', 'urgent' => 'Urgent'];
}

function comm_notice_audiences(): array
{
    return ['all' => 'Everyone', 'students' => 'All students', 'faculty' => 'Faculty', 'staff' => 'Staff', 'parents' => 'Parents',
        'alumni' => 'Alumni', 'department' => 'Department', 'course' => 'Course / Program', 'semester' => 'Semester'];
}

function comm_event_categories(): array
{
    return ['academic' => 'Academic', 'cultural' => 'Cultural', 'sports' => 'Sports', 'seminar' => 'Seminar', 'workshop' => 'Workshop', 'webinar' => 'Webinar',
        'conference' => 'Conference', 'placement' => 'Placement', 'admission' => 'Admission', 'fdp' => 'Faculty Development', 'alumni' => 'Alumni', 'other' => 'Other'];
}

function comm_campaign_audiences(): array
{
    return ['subscribers' => 'Newsletter subscribers', 'students' => 'All students', 'program' => 'Students by program', 'parents' => 'Parents',
        'faculty' => 'Faculty', 'staff' => 'Staff', 'alumni' => 'Alumni', 'custom' => 'Custom list'];
}

function comm_participant_types(): array
{
    return ['student' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff', 'alumni' => 'Alumni', 'parent' => 'Parent', 'guest' => 'Guest'];
}

function comm_subscriber_sources(): array
{
    return ['website' => 'Website', 'footer' => 'Website footer', 'blog' => 'Blog', 'events' => 'Event page', 'admissions' => 'Admissions', 'counselling' => 'Counselling',
        'alumni' => 'Alumni', 'import' => 'Import', 'admin' => 'Added by admin', 'item' => 'Website form'];
}

/** Template codes referenced by application code — they cannot be deleted or renamed. */
function comm_system_template_codes(): array
{
    return ['password_reset', 'admission_received', 'admission_confirmed', 'fee_receipt', 'fee_reminder', 'low_attendance', 'exam_reminder', 'result_published',
        'contact_reply', 'contact_received', 'enquiry_received', 'notice_published', 'notice_sms', 'newsletter_welcome', 'event_registration', 'event_reminder',
        'account_created'];
}

/** Human labels for message_logs.related_type values written across the ERP. */
function comm_related_types(): array
{
    return ['notice' => 'Notice', 'event' => 'Event reminder', 'event_registration' => 'Event registration', 'campaign' => 'Campaign', 'campaign_test' => 'Campaign test',
        'template_test' => 'Template test', 'admission' => 'Admission', 'enquiry' => 'Enquiry', 'contact_message' => 'Contact message', 'newsletter_subscriber' => 'Newsletter',
        'payment' => 'Fee payment', 'student_fee' => 'Fee invoice', 'exam' => 'Examination', 'result' => 'Result', 'certificate' => 'Certificate',
        'library_transaction' => 'Library', 'feedback' => 'Feedback', 'user' => 'User account', 'faculty' => 'Faculty', 'staff' => 'Staff', 'settings_test' => 'Settings test'];
}

function comm_channel_enabled(string $channel): bool
{
    if ($channel === 'email') {
        return true;
    }
    return in_array($channel, ['sms', 'whatsapp'], true) && (string) setting($channel . '_enabled', '0') === '1';
}

function comm_channels_state(): array
{
    return ['email' => true, 'sms' => comm_channel_enabled('sms'), 'whatsapp' => comm_channel_enabled('whatsapp')];
}

/* ------------------------------------------------------------------
 * Text helpers
 * ------------------------------------------------------------------ */

/** sanitize_html() percent-encodes {{placeholders}} inside href/src — put them back. */
function comm_restore_placeholders(?string $html): string
{
    return preg_replace('/%7B%7B\s*([a-zA-Z0-9_]+)\s*%7D%7D/i', '{{$1}}', (string) $html);
}

/** Rich text for e-mail bodies: sanitised, placeholders intact. */
function comm_clean_html(?string $html): string
{
    return comm_restore_placeholders(sanitize_html((string) $html));
}

/** Plain text for SMS / WhatsApp. */
function comm_plain_text(?string $html): string
{
    $text = preg_replace('/<\s*(br|\/p|\/li|\/h[1-6]|\/div)\s*\/?>/i', "\n", (string) $html);
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    return trim(preg_replace("/\n{3,}/", "\n\n", $text));
}

/** Placeholder names used in a subject/body. */
function comm_placeholders_in(string ...$texts): array
{
    $found = [];
    foreach ($texts as $t) {
        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $t, $m)) {
            $found = array_merge($found, $m[1]);
        }
    }
    return array_values(array_unique($found));
}

/**
 * Replace {{placeholders}}. Values are HTML-escaped unless the key is listed in $htmlKeys (trusted, already sanitised
 * HTML such as a notice body). For plain-text channels pass $escape = false.
 */
function comm_render(string $template, array $vars, bool $escape = true, array $htmlKeys = ['content', 'body', 'notice_body']): string
{
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars, $escape, $htmlKeys) {
        $v = $vars[$m[1]] ?? '';
        if (!$escape || in_array($m[1], $htmlKeys, true)) {
            return (string) $v;
        }
        return e($v);
    }, $template);
}

function comm_base_vars(): array
{
    return ['institute_name' => institute_name(), 'site_url' => absolute_url(''), 'year' => date('Y'), 'phone' => (string) setting('phone', ''), 'email_address' => (string) setting('email', '')];
}

/** Known placeholders with sample values (template help panel + previews). */
function comm_placeholder_catalog(): array
{
    $c = [
        ['name', 'Recipient name', 'Recipient', 'Ananya Sharma'],
        ['email', 'Recipient e-mail', 'Recipient', 'ananya.sharma@gmail.com'],
        ['institute_name', 'Institute name', 'Institute', institute_name()],
        ['site_url', 'Website address', 'Institute', absolute_url('')],
        ['year', 'Current year', 'Institute', date('Y')],
        ['unsubscribe_link', 'Unsubscribe link (newsletter)', 'Newsletter', absolute_url('api/newsletter/unsubscribe?token=sample')],
        ['program', 'Program / course', 'Admissions', 'BBA — Bachelor of Business Administration'],
        ['application_no', 'Application number', 'Admissions', 'GIMT/APP/2026/00124'],
        ['student_uid', 'Student ID', 'Admissions', 'GIMT26BBA014'],
        ['amount', 'Amount', 'Fees', '₹45,000'],
        ['title', 'Title (fee head / notice)', 'Fees', 'Semester 3 Tuition Fee'],
        ['due_date', 'Due date', 'Fees', format_date(date('Y-m-d', strtotime('+10 days')))],
        ['receipt_no', 'Receipt number', 'Fees', 'RCPT-2026-00412'],
        ['date', 'Date', 'General', format_date(date('Y-m-d'))],
        ['percent', 'Attendance %', 'Attendance', '68'],
        ['required', 'Required attendance %', 'Attendance', '75'],
        ['exam', 'Exam name', 'Examination', 'End Semester Examination — Dec 2026'],
        ['sgpa', 'SGPA / percentage', 'Examination', '8.42'],
        ['status', 'Result status', 'Examination', 'PASS'],
        ['subject', 'Subject of the enquiry', 'Contact', 'Hostel availability for MBA'],
        ['message', 'Original message', 'Contact', 'Please share hostel fee details for the 2026-27 session.'],
        ['reply', 'Reply text', 'Contact', 'Hostel seats are available. The annual fee is ₹85,000 including mess.'],
        ['content', 'Notice body (HTML)', 'Notices', '<p>All students are informed that classes will remain suspended on account of the annual sports meet.</p>'],
        ['summary', 'Short summary (SMS)', 'Notices', 'Classes suspended on 24 Oct for the annual sports meet.'],
        ['link', 'Link to details', 'Notices', absolute_url('notices')],
        ['event', 'Event name', 'Events', 'Annual Tech Fest — Innovate 2026'],
        ['venue', 'Venue', 'Events', 'Main Auditorium'],
        ['reset_link', 'Password reset link', 'Accounts', absolute_url('admin/reset-password?token=sample')],
        ['username', 'Username', 'Accounts', 'ananya.sharma'],
        ['password', 'Temporary password', 'Accounts', 'Gimt@2026'],
        ['login_url', 'Login URL', 'Accounts', absolute_url('admin/')],
        ['role', 'Role', 'Accounts', 'Faculty'],
    ];
    return array_map(fn ($r) => ['key' => $r[0], 'label' => $r[1], 'group' => $r[2], 'sample' => $r[3]], $c);
}

function comm_sample_vars(): array
{
    $v = [];
    foreach (comm_placeholder_catalog() as $p) {
        $v[$p['key']] = $p['sample'];
    }
    return $v;
}

/** Render a template (subject + body) for preview / test send. */
function comm_template_preview(string $channel, string $subject, string $body, array $vars = []): array
{
    $vars = $vars + comm_sample_vars();
    $used = comm_placeholders_in($subject, $body);
    $known = array_column(comm_placeholder_catalog(), 'key');
    $unknown = array_values(array_diff($used, $known, array_keys($vars)));
    if ($channel === 'email') {
        $s = comm_render($subject, $vars, false);
        $inner = comm_render(comm_clean_html($body), $vars);
        return ['subject' => $s, 'inner' => $inner, 'html' => email_layout($inner, e($s)), 'text' => comm_plain_text($inner), 'used' => $used, 'unknown' => $unknown];
    }
    $text = comm_render(comm_plain_text($body), $vars, false);
    return ['subject' => null, 'inner' => nl2br(e($text)), 'html' => null, 'text' => $text, 'length' => mb_strlen($text), 'segments' => (int) max(1, ceil(mb_strlen($text) / 160)), 'used' => $used, 'unknown' => $unknown];
}

function comm_unsubscribe_url(?string $token): string
{
    return $token ? absolute_url('api/newsletter/unsubscribe?token=' . rawurlencode($token)) : absolute_url('');
}

function comm_unique_slug(string $table, string $base, ?int $ignoreId = null): string
{
    $base = slugify($base) ?: 'item';
    $base = mb_substr($base, 0, 180);
    $slug = $base;
    $i = 2;
    while ((int) db_value('SELECT COUNT(*) FROM ' . db_quote_ident($table) . ' WHERE slug = ?' . ($ignoreId ? ' AND id <> ?' : ''), $ignoreId ? [$slug, $ignoreId] : [$slug])) {
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

/* ------------------------------------------------------------------
 * Audiences / recipients
 * ------------------------------------------------------------------ */

/**
 * People for an audience. Each: ['type', 'id', 'name', 'email', 'phone', 'whatsapp', 'user_id'].
 * $t keys: department_id, program_id, program_ids[], semester_no, emails (custom list string/array).
 */
function comm_audience_people(string $audience, array $t = []): array
{
    $students = function (array $where = [], array $args = []) {
        $sql = "SELECT 'student' AS type, s.id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.email, s.mobile AS phone,
                       COALESCE(NULLIF(s.whatsapp, ''), s.mobile) AS whatsapp, s.user_id
                FROM students s WHERE s.status = 'active'" . ($where ? ' AND ' . implode(' AND ', $where) : '') . ' ORDER BY s.first_name, s.last_name';
        return db_all($sql, $args);
    };
    $faculty = function (?int $dept = null) {
        return db_all("SELECT 'faculty' AS type, f.id, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS name, f.email, f.phone, f.phone AS whatsapp, f.user_id
                       FROM faculty f WHERE f.status IN ('active', 'on_leave')" . ($dept ? ' AND f.department_id = ?' : '') . ' ORDER BY f.first_name', $dept ? [$dept] : []);
    };
    $staff = function (?int $dept = null) {
        return db_all("SELECT 'staff' AS type, st.id, TRIM(CONCAT_WS(' ', st.first_name, st.last_name)) AS name, st.email, st.phone, st.phone AS whatsapp, st.user_id
                       FROM staff st WHERE st.status = 'active'" . ($dept ? ' AND st.department_id = ?' : '') . ' ORDER BY st.first_name', $dept ? [$dept] : []);
    };
    $dept = !empty($t['department_id']) ? (int) $t['department_id'] : null;
    $program = !empty($t['program_id']) ? (int) $t['program_id'] : null;
    $sem = !empty($t['semester_no']) ? (int) $t['semester_no'] : null;
    switch ($audience) {
        case 'all':
            return array_merge($students(), $faculty(), $staff());
        case 'students':
            return $students();
        case 'faculty':
            return $faculty();
        case 'staff':
            return $staff();
        case 'parents':
            $where = ["s.status = 'active'"];
            $args = [];
            if ($program) {
                $where[] = 's.program_id = ?';
                $args[] = $program;
            }
            return db_all("SELECT 'parent' AS type, sp.id, sp.name, sp.email, sp.phone, sp.phone AS whatsapp, NULL AS user_id
                           FROM student_parents sp JOIN students s ON s.id = sp.student_id WHERE " . implode(' AND ', $where) . ' ORDER BY sp.name', $args);
        case 'alumni':
            return db_all("SELECT 'alumni' AS type, a.id, a.name, a.email, a.phone, a.phone AS whatsapp, NULL AS user_id FROM alumni a WHERE a.status = 'active' ORDER BY a.name");
        case 'department':
            if (!$dept) {
                return [];
            }
            return array_merge($students(['s.department_id = ?'], [$dept]), $faculty($dept), $staff($dept));
        case 'course':
            return $program ? $students(['s.program_id = ?'], [$program]) : [];
        case 'semester':
            if (!$sem) {
                return [];
            }
            return $program ? $students(['s.program_id = ?', 's.current_semester = ?'], [$program, $sem]) : $students(['s.current_semester = ?'], [$sem]);
        case 'program':
            $ids = array_values(array_filter(array_map('intval', (array) ($t['program_ids'] ?? []))));
            if (!$ids) {
                return [];
            }
            $where = ['s.program_id IN (' . implode(',', $ids) . ')'];
            $args = [];
            if ($sem) {
                $where[] = 's.current_semester = ?';
                $args[] = $sem;
            }
            return $students($where, $args);
        case 'subscribers':
            return db_all("SELECT 'subscriber' AS type, n.id, COALESCE(NULLIF(n.name, ''), SUBSTRING_INDEX(n.email, '@', 1)) AS name, n.email, NULL AS phone, NULL AS whatsapp, NULL AS user_id, n.token
                           FROM newsletter_subscribers n WHERE n.status = 'subscribed' ORDER BY n.id");
        case 'custom':
            $list = is_array($t['emails'] ?? null) ? $t['emails'] : preg_split('/[\s,;]+/', (string) ($t['emails'] ?? ''));
            $out = [];
            foreach ($list as $em) {
                $em = strtolower(trim((string) $em));
                if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    $out[] = ['type' => 'custom', 'id' => null, 'name' => ucwords(str_replace(['.', '_'], ' ', strstr($em, '@', true))), 'email' => $em, 'phone' => null, 'whatsapp' => null, 'user_id' => null];
                }
            }
            return $out;
    }
    return [];
}

/** Address of a person for a channel ('' when missing / invalid). */
function comm_address(array $p, string $channel): string
{
    if ($channel === 'email') {
        $em = strtolower(trim((string) ($p['email'] ?? '')));
        return filter_var($em, FILTER_VALIDATE_EMAIL) ? $em : '';
    }
    $ph = preg_replace('/[^0-9+]/', '', (string) ($channel === 'whatsapp' ? ($p['whatsapp'] ?? $p['phone'] ?? '') : ($p['phone'] ?? '')));
    return strlen(preg_replace('/\D/', '', $ph)) >= 10 ? $ph : '';
}

/** Recipient counts for an audience (preview before sending). */
function comm_audience_summary(string $audience, array $t = []): array
{
    $people = comm_audience_people($audience, $t);
    $emails = $phones = $wa = [];
    $byType = [];
    foreach ($people as $p) {
        $byType[$p['type']] = ($byType[$p['type']] ?? 0) + 1;
        if ($a = comm_address($p, 'email')) {
            $emails[$a] = true;
        }
        if ($a = comm_address($p, 'sms')) {
            $phones[$a] = true;
        }
        if ($a = comm_address($p, 'whatsapp')) {
            $wa[$a] = true;
        }
    }
    $labels = ['student' => 'Students', 'faculty' => 'Faculty', 'staff' => 'Staff', 'parent' => 'Parents', 'alumni' => 'Alumni', 'subscriber' => 'Subscribers', 'custom' => 'Custom'];
    $breakdown = [];
    foreach ($byType as $k => $n) {
        $breakdown[] = ['type' => $k, 'label' => $labels[$k] ?? label_from_key($k), 'count' => $n];
    }
    return ['people' => count($people), 'email' => count($emails), 'sms' => count($phones), 'whatsapp' => count($wa), 'breakdown' => $breakdown, 'channels' => comm_channels_state()];
}

function comm_notice_audience_label(array $n): string
{
    $aud = comm_notice_audiences()[$n['audience'] ?? 'all'] ?? label_from_key((string) ($n['audience'] ?? 'all'));
    switch ($n['audience'] ?? '') {
        case 'department':
            return 'Department · ' . ($n['department_name'] ?? db_value('SELECT name FROM departments WHERE id = ?', [(int) ($n['department_id'] ?? 0)]) ?? '—');
        case 'course':
            return 'Course · ' . ($n['program_short'] ?? db_value('SELECT short_name FROM programs WHERE id = ?', [(int) ($n['program_id'] ?? 0)]) ?? '—');
        case 'semester':
            $p = $n['program_short'] ?? (!empty($n['program_id']) ? db_value('SELECT short_name FROM programs WHERE id = ?', [(int) $n['program_id']]) : null);
            return ($p ? $p . ' · ' : 'All programs · ') . 'Semester ' . ($n['semester_no'] ?? '—');
        case 'parents':
            return 'Parents' . (!empty($n['program_id']) ? ' · ' . ($n['program_short'] ?? db_value('SELECT short_name FROM programs WHERE id = ?', [(int) $n['program_id']])) : '');
    }
    return $aud;
}

/* ------------------------------------------------------------------
 * Delivery queue
 * ------------------------------------------------------------------ */

function comm_new_batch(): string
{
    return bin2hex(random_bytes(8));
}

/**
 * Queue one message per unique address. $render = fn(array $person): array{subject: ?string, body: string}
 * Returns the number of rows queued.
 */
function comm_enqueue(string $batch, string $channel, array $people, callable $render, array $meta = []): int
{
    $seen = [];
    $rows = [];
    foreach ($people as $p) {
        $addr = comm_address($p, $channel);
        if ($addr === '' || isset($seen[$addr])) {
            continue;
        }
        $seen[$addr] = true;
        $msg = $render($p);
        $rows[] = [$batch, $channel, mb_substr($addr, 0, 190), mb_substr((string) ($p['name'] ?? ''), 0, 150) ?: null, isset($msg['subject']) ? mb_substr((string) $msg['subject'], 0, 255) : null,
            (string) $msg['body'], $meta['related_type'] ?? null, $meta['related_id'] ?? null, $meta['campaign_id'] ?? null, user_id()];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        $ph = implode(', ', array_fill(0, count($chunk), "(?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)"));
        db_exec("INSERT INTO message_queue (batch_id, channel, recipient, recipient_name, subject, body, related_type, related_id, campaign_id, status, created_by) VALUES $ph",
            array_merge(...$chunk));
    }
    return count($rows);
}

/** WHERE fragment for a queue scope: ['batch' => x] | ['campaign_id' => n] | ['related_type' => t, 'related_id' => n]. */
function comm_queue_scope(array $scope): array
{
    if (!empty($scope['batch'])) {
        return ['batch_id = ?', [(string) $scope['batch']]];
    }
    if (!empty($scope['campaign_id'])) {
        return ['campaign_id = ?', [(int) $scope['campaign_id']]];
    }
    if (!empty($scope['related_type']) && !empty($scope['related_id'])) {
        return ['related_type = ? AND related_id = ?', [(string) $scope['related_type'], (int) $scope['related_id']]];
    }
    throw new InvalidArgumentException('A delivery batch, campaign or record is required.');
}

function comm_queue_progress(array $scope): array
{
    [$where, $args] = comm_queue_scope($scope);
    $counts = db_pairs("SELECT status, COUNT(*) FROM message_queue WHERE $where GROUP BY status", $args);
    $pending = (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0);
    $sent = (int) ($counts['sent'] ?? 0);
    $failed = (int) ($counts['failed'] ?? 0);
    $cancelled = (int) ($counts['cancelled'] ?? 0);
    $total = $pending + $sent + $failed + $cancelled;
    $doneCount = $sent + $failed + $cancelled;
    $byChannel = [];
    foreach (db_all("SELECT channel, status, COUNT(*) AS n FROM message_queue WHERE $where GROUP BY channel, status", $args) as $r) {
        $byChannel[$r['channel']] = $byChannel[$r['channel']] ?? ['total' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0];
        $byChannel[$r['channel']]['total'] += (int) $r['n'];
        $k = in_array($r['status'], ['pending', 'processing'], true) ? 'pending' : ($r['status'] === 'sent' ? 'sent' : ($r['status'] === 'failed' ? 'failed' : null));
        if ($k) {
            $byChannel[$r['channel']][$k] += (int) $r['n'];
        }
    }
    return ['total' => $total, 'pending' => $pending, 'sent' => $sent, 'failed' => $failed, 'cancelled' => $cancelled, 'done' => $pending === 0,
        'percent' => $total ? (int) floor($doneCount * 100 / $total) : 100, 'channels' => $byChannel] + (!empty($scope['batch']) ? ['batch' => $scope['batch']] : []);
}

/**
 * Send queued messages of a scope for up to $budget seconds (and at most $max messages).
 * Safe to call concurrently: rows are claimed with a token before sending.
 */
function comm_process_queue(array $scope, float $budget = 8.0, int $max = 300): array
{
    [$where, $args] = comm_queue_scope($scope);
    // release claims abandoned by a crashed / timed-out request
    db_exec("UPDATE message_queue SET status = 'pending', claim_token = NULL, claimed_at = NULL WHERE status = 'processing' AND claimed_at < ?", [date('Y-m-d H:i:s', time() - 300)]);
    @set_time_limit((int) ceil($budget) + 30);
    $started = microtime(true);
    $done = 0;
    while ($done < $max && (microtime(true) - $started) < $budget) {
        $token = bin2hex(random_bytes(8));
        $claimed = db_exec("UPDATE message_queue SET status = 'processing', claim_token = ?, claimed_at = ? WHERE status = 'pending' AND $where ORDER BY id LIMIT 20",
            array_merge([$token, date('Y-m-d H:i:s')], $args));
        if (!$claimed) {
            break;
        }
        foreach (db_all("SELECT * FROM message_queue WHERE claim_token = ? AND status = 'processing' ORDER BY id", [$token]) as $row) {
            [$ok, $error] = comm_deliver($row);
            db_update('message_queue', ['status' => $ok ? 'sent' : 'failed', 'error' => $error ? mb_substr($error, 0, 500) : null, 'processed_at' => date('Y-m-d H:i:s'),
                'claim_token' => null, 'body' => null], 'id = ?', [(int) $row['id']]);
            $done++;
        }
    }
    if (!empty($scope['campaign_id'])) {
        comm_campaign_sync((int) $scope['campaign_id']);
    } elseif (!empty($scope['batch'])) {
        $cid = db_value('SELECT campaign_id FROM message_queue WHERE batch_id = ? AND campaign_id IS NOT NULL LIMIT 1', [(string) $scope['batch']]);
        if ($cid) {
            comm_campaign_sync((int) $cid);
        }
    }
    return comm_queue_progress($scope) + ['processed' => $done];
}

/** Hand one queued row to the transport. Returns [ok, error]. */
function comm_deliver(array $row): array
{
    $opts = ['related_type' => $row['related_type'], 'related_id' => $row['related_id'] !== null ? (int) $row['related_id'] : null,
        'campaign_id' => $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null];
    $before = (int) db_value('SELECT COALESCE(MAX(id), 0) FROM message_logs');
    try {
        if ($row['channel'] === 'email') {
            $ok = send_mail($row['recipient'], (string) $row['subject'], (string) $row['body'], $opts);
        } elseif ($row['channel'] === 'sms') {
            $ok = send_sms($row['recipient'], (string) $row['body'], $opts);
        } else {
            $ok = send_whatsapp($row['recipient'], (string) $row['body'], $opts);
        }
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
    if ($ok) {
        return [true, null];
    }
    $err = db_value('SELECT error FROM message_logs WHERE id > ? AND recipient = ? ORDER BY id DESC LIMIT 1', [$before, $row['recipient']]);
    return [false, $err ?: ($row['channel'] === 'email' ? 'The mail server rejected the message.' : 'The gateway did not accept the message.')];
}

/** Cancel pending rows of a scope. */
function comm_queue_cancel(array $scope): int
{
    [$where, $args] = comm_queue_scope($scope);
    return db_exec("UPDATE message_queue SET status = 'cancelled', processed_at = ?, body = NULL WHERE status IN ('pending', 'processing') AND $where", array_merge([date('Y-m-d H:i:s')], $args));
}

/** Permission needed to drive a batch, from the kind of record it belongs to. */
function comm_queue_permission(array $scope): array
{
    [$where, $args] = comm_queue_scope($scope);
    $type = !empty($scope['related_type']) ? $scope['related_type'] : db_value("SELECT related_type FROM message_queue WHERE $where LIMIT 1", $args);
    return match ($type) {
        'notice' => ['notices', 'publish'],
        'event' => ['events', 'edit'],
        default => ['communication', 'publish'],
    };
}

/* ------------------------------------------------------------------
 * Notices
 * ------------------------------------------------------------------ */

/** Mark published notices past their expiry as expired (cheap housekeeping on read). */
function comm_expire_notices(): int
{
    return db_exec("UPDATE notices SET status = 'expired' WHERE status = 'published' AND expiry_date IS NOT NULL AND expiry_date < ?", [date('Y-m-d H:i:s')]);
}

/** SQL expression for the live state of a notice (alias t). */
function comm_notice_state_sql(string $a = 't'): string
{
    return "(CASE WHEN $a.status = 'draft' THEN 'draft'
                  WHEN $a.status IN ('expired', 'archived') OR ($a.expiry_date IS NOT NULL AND $a.expiry_date < NOW()) THEN 'expired'
                  WHEN $a.publish_date IS NOT NULL AND $a.publish_date > NOW() THEN 'scheduled'
                  ELSE 'live' END)";
}

function comm_notice_find(int $id): ?array
{
    $state = comm_notice_state_sql('n');
    return db_row("SELECT n.*, $state AS state, d.name AS department_name, p.short_name AS program_short, p.name AS program_name, u.name AS created_by_name
                   FROM notices n LEFT JOIN departments d ON d.id = n.department_id LEFT JOIN programs p ON p.id = n.program_id LEFT JOIN users u ON u.id = n.created_by
                   WHERE n.id = ?", [$id]);
}

function comm_notice_targets(array $n): array
{
    return ['department_id' => $n['department_id'] ?? null, 'program_id' => $n['program_id'] ?? null, 'semester_no' => $n['semester_no'] ?? null];
}

/** Delivery statistics of a notice (message logs + queue). */
function comm_notice_delivery(int $id): array
{
    $logs = db_all("SELECT channel, status, COUNT(*) AS n, MAX(created_at) AS last_at FROM message_logs WHERE related_type = 'notice' AND related_id = ? GROUP BY channel, status", [$id]);
    $channels = [];
    $last = null;
    foreach ($logs as $r) {
        $c = $r['channel'];
        $channels[$c] = $channels[$c] ?? ['channel' => $c, 'sent' => 0, 'failed' => 0, 'queued' => 0];
        $k = in_array($r['status'], ['sent', 'failed'], true) ? $r['status'] : 'queued';
        $channels[$c][$k] += (int) $r['n'];
        $last = max($last ?? '', (string) $r['last_at']);
    }
    $pending = (int) db_value("SELECT COUNT(*) FROM message_queue WHERE related_type = 'notice' AND related_id = ? AND status IN ('pending', 'processing')", [$id]);
    return ['channels' => array_values($channels), 'pending' => $pending, 'last_sent_at' => $last ?: null,
        'total_sent' => array_sum(array_column($channels, 'sent')), 'total_failed' => array_sum(array_column($channels, 'failed'))];
}

function comm_notice_detail(int $id): ?array
{
    $n = comm_notice_find($id);
    if (!$n) {
        return null;
    }
    $n['id'] = (int) $n['id'];
    $n['is_pinned'] = (bool) $n['is_pinned'];
    $n['show_on_website'] = (bool) $n['show_on_website'];
    $n['audience_label'] = comm_notice_audience_label($n);
    $n['category_label'] = comm_notice_categories()[$n['category']] ?? label_from_key((string) $n['category']);
    $n['attachment_url'] = $n['attachment'] ? upload_url($n['attachment']) : null;
    $n['attachment_name'] = $n['attachment'] ? basename((string) $n['attachment']) : null;
    $n['public_url'] = absolute_url('notices/' . $n['slug']);
    return ['notice' => $n, 'delivery' => comm_notice_delivery($id), 'audience' => comm_audience_summary((string) $n['audience'], comm_notice_targets($n))];
}

/** Message bodies for a notice per channel. */
function comm_notice_message(array $n, string $channel): array
{
    $link = absolute_url('notices/' . $n['slug']);
    $plain = comm_plain_text((string) $n['description']);
    $summary = mb_strlen($plain) > 140 ? rtrim(mb_substr($plain, 0, 137)) . '…' : $plain;
    $vars = comm_base_vars() + ['title' => (string) $n['title'], 'summary' => $summary, 'link' => $link, 'date' => format_date($n['publish_date'] ?: date('Y-m-d'))];
    if ($channel === 'email') {
        $tpl = db_row("SELECT subject, body_html FROM email_templates WHERE code = 'notice_published' AND status = 'active'");
        $content = (string) $n['description'];
        $meta = '<p style="margin:0 0 14px;font-size:12px;color:#64748b">' . e(comm_notice_categories()[$n['category']] ?? label_from_key((string) $n['category'])) . ' · ' . e(comm_notice_audience_label($n))
            . ($n['publish_date'] ? ' · ' . e(format_date($n['publish_date'])) : '') . '</p>';
        $extra = '';
        if ($n['attachment']) {
            $extra .= '<p><a href="' . e(absolute_url(ltrim((string) $n['attachment'], '/'))) . '" style="color:#1d4ed8">Download attachment (' . e(basename((string) $n['attachment'])) . ')</a></p>';
        }
        if ($n['show_on_website'] && $n['status'] !== 'draft') {
            $extra .= '<p><a href="' . e($link) . '" style="display:inline-block;background:#0B2A5B;color:#fff;padding:9px 16px;border-radius:8px;text-decoration:none">View on website</a></p>';
        }
        $vars['content'] = $meta . $content . $extra;
        $subject = $tpl ? comm_render((string) $tpl['subject'], $vars, false) : (string) $n['title'];
        $body = $tpl ? comm_render((string) $tpl['body_html'], $vars) : '<h2 style="margin:0 0 6px;color:#0B2A5B">' . e($n['title']) . '</h2>' . $vars['content'];
        if ($tpl && !str_contains((string) $tpl['body_html'], '{{content}}')) {
            $body .= $vars['content'];
        }
        return ['subject' => ($n['category'] === 'urgent' ? 'URGENT | ' : 'Notice | ') . $subject, 'body' => $body];
    }
    $tpl = db_row("SELECT body_html FROM email_templates WHERE code = 'notice_sms' AND status = 'active'");
    $text = $tpl ? comm_render(comm_plain_text((string) $tpl['body_html']), $vars, false) : sprintf('%s Notice: %s. %s Details: %s', setting('short_name', 'GIMT'), $n['title'], $summary, $link);
    return ['subject' => null, 'body' => $text];
}

/** In-app notifications for the users linked to a notice audience. */
function comm_notify_audience(array $n): int
{
    $aud = (string) $n['audience'];
    $ids = [];
    if (in_array($aud, ['all', 'staff'], true)) {
        $ids = db_column("SELECT id FROM users WHERE status = 'active'" . ($aud === 'staff' ? ' AND faculty_id IS NULL' : ''));
    } elseif ($aud === 'faculty') {
        $ids = db_column("SELECT id FROM users WHERE status = 'active' AND faculty_id IS NOT NULL");
    } elseif ($aud === 'department' && $n['department_id']) {
        $ids = array_merge(
            db_column("SELECT u.id FROM users u JOIN faculty f ON f.id = u.faculty_id WHERE u.status = 'active' AND f.department_id = ?", [(int) $n['department_id']]),
            db_column("SELECT u.id FROM users u WHERE u.status = 'active' AND u.department_id = ?", [(int) $n['department_id']]),
            db_column("SELECT s.user_id FROM students s WHERE s.status = 'active' AND s.user_id IS NOT NULL AND s.department_id = ?", [(int) $n['department_id']])
        );
    } elseif (in_array($aud, ['students', 'course', 'semester'], true)) {
        $ids = array_column(array_filter(comm_audience_people($aud, comm_notice_targets($n)), fn ($p) => !empty($p['user_id'])), 'user_id');
    }
    $ids = array_values(array_unique(array_diff(array_map('intval', $ids), [(int) user_id()])));
    if (!$ids) {
        return 0;
    }
    $plain = comm_plain_text((string) $n['description']);
    return notify($ids, 'notice', ($n['is_pinned'] ? '📌 ' : '') . 'New notice: ' . $n['title'], mb_strlen($plain) > 180 ? mb_substr($plain, 0, 177) . '…' : $plain, 'admin/notices?view=' . (int) $n['id'], 'megaphone');
}

/**
 * Publish a notice and (optionally) send it to its audience.
 * $channels: ['email' => bool, 'sms' => bool, 'whatsapp' => bool]
 */
function comm_notice_publish(int $id, array $channels, bool $inApp = true): array
{
    $n = comm_notice_find($id);
    if (!$n) {
        throw new CrudException('Notice not found. It may have been deleted.');
    }
    if ($n['expiry_date'] && strtotime((string) $n['expiry_date']) < time()) {
        throw new CrudException('This notice expired on ' . format_datetime($n['expiry_date']) . '. Extend the expiry date before publishing it again.');
    }
    $use = [];
    foreach (['email', 'sms', 'whatsapp'] as $c) {
        if (!empty($channels[$c]) && $channels[$c] !== '0' && $channels[$c] !== 'false') {
            if (!comm_channel_enabled($c)) {
                throw new CrudException(($c === 'sms' ? 'SMS' : 'WhatsApp') . ' is not enabled. Configure the gateway under Settings → Email & SMS first.');
            }
            $use[] = $c;
        }
    }
    $update = ['status' => 'published'];
    if (!$n['publish_date']) {
        $update['publish_date'] = date('Y-m-d H:i:s');
    }
    foreach ($use as $c) {
        $update['send_' . $c] = 1;
    }
    db_update('notices', $update, 'id = ?', [$id]);
    $n = comm_notice_find($id);
    $batch = comm_new_batch();
    $queued = [];
    if ($use) {
        $people = comm_audience_people((string) $n['audience'], comm_notice_targets($n));
        foreach ($use as $c) {
            $msg = comm_notice_message($n, $c);
            $queued[$c] = comm_enqueue($batch, $c, $people, fn () => $msg, ['related_type' => 'notice', 'related_id' => $id]);
        }
    }
    $notified = $inApp ? comm_notify_audience($n) : 0;
    $scheduled = $n['publish_date'] && strtotime((string) $n['publish_date']) > time();
    $parts = [];
    foreach ($queued as $c => $cnt) {
        $parts[] = number_format($cnt) . ' ' . ($c === 'email' ? 'e-mail' : ($c === 'sms' ? 'SMS' : 'WhatsApp'));
    }
    log_activity('publish', 'notices', $id, sprintf('Published notice "%s" for %s%s%s', str_limit((string) $n['title'], 90), comm_notice_audience_label($n),
        $parts ? ' — queued ' . implode(', ', $parts) : '', $notified ? " · $notified in-app alerts" : ''));
    $total = array_sum($queued);
    $progress = $total ? comm_process_queue(['batch' => $batch], 6.0) : null;
    return ['batch' => $total ? $batch : null, 'queued' => $queued, 'notified' => $notified, 'progress' => $progress, 'scheduled' => $scheduled];
}

/* ------------------------------------------------------------------
 * Events
 * ------------------------------------------------------------------ */

function comm_event_stats(int $eventId): array
{
    $r = db_row("SELECT COUNT(*) AS total, SUM(status = 'registered') AS registered, SUM(status = 'attended') AS attended, SUM(status = 'cancelled') AS cancelled,
                        SUM(source = 'website') AS website, SUM(created_at >= ?) AS last7
                 FROM event_registrations WHERE event_id = ?", [date('Y-m-d H:i:s', strtotime('-7 days')), $eventId]) ?? [];
    $out = array_map('intval', $r);
    $out['active'] = $out['registered'] + $out['attended'];
    $out['by_type'] = array_map(fn ($x) => ['type' => $x['participant_type'] ?: 'guest', 'label' => comm_participant_types()[$x['participant_type'] ?: 'guest'] ?? label_from_key((string) $x['participant_type']), 'count' => (int) $x['n']],
        db_all("SELECT participant_type, COUNT(*) AS n FROM event_registrations WHERE event_id = ? AND status <> 'cancelled' GROUP BY participant_type ORDER BY n DESC", [$eventId]));
    return $out;
}

function comm_event_detail(int $id): ?array
{
    $e = db_row('SELECT e.*, u.name AS created_by_name FROM events e LEFT JOIN users u ON u.id = e.created_by WHERE e.id = ?', [$id]);
    if (!$e) {
        return null;
    }
    $e['id'] = (int) $e['id'];
    foreach (['registration_enabled', 'is_featured', 'show_on_website'] as $b) {
        $e[$b] = (bool) $e[$b];
    }
    $e['max_participants'] = $e['max_participants'] !== null ? (int) $e['max_participants'] : null;
    $e['category_label'] = comm_event_categories()[$e['category']] ?? label_from_key((string) $e['category']);
    $e['image_url'] = $e['image'] ? upload_url($e['image']) : null;
    $e['public_url'] = absolute_url('events/' . $e['slug']);
    $now = time();
    $start = strtotime((string) $e['start_datetime']);
    $end = strtotime((string) ($e['end_datetime'] ?: $e['start_datetime']));
    $e['phase'] = $start > $now ? 'upcoming' : ($end < $now ? 'past' : 'ongoing');
    $stats = comm_event_stats($id);
    $deadline = $e['registration_deadline'] ? strtotime((string) $e['registration_deadline']) : $end;
    $full = $e['max_participants'] && $stats['active'] >= $e['max_participants'];
    $e['registration_open'] = $e['registration_enabled'] && $e['status'] === 'published' && $deadline >= $now && $end >= $now && !$full;
    $e['registration_state'] = !$e['registration_enabled'] ? 'disabled' : ($full ? 'full' : ($e['registration_open'] ? 'open' : 'closed'));
    $reminders = db_row("SELECT COUNT(*) AS n, MAX(created_at) AS last_at FROM message_logs WHERE related_type = 'event' AND related_id = ? AND status = 'sent'", [$id]);
    return ['event' => $e, 'stats' => $stats, 'reminders' => ['sent' => (int) ($reminders['n'] ?? 0), 'last_at' => $reminders['last_at'] ?? null],
        'pending' => (int) db_value("SELECT COUNT(*) FROM message_queue WHERE related_type = 'event' AND related_id = ? AND status IN ('pending', 'processing')", [$id])];
}

/** Queue a reminder e-mail to everyone registered (not cancelled / not yet attended for past events). */
function comm_event_remind(int $id, string $note = ''): array
{
    $e = db_row('SELECT * FROM events WHERE id = ?', [$id]);
    if (!$e) {
        throw new CrudException('Event not found. It may have been deleted.');
    }
    if ($e['status'] === 'cancelled') {
        throw new CrudException('This event is cancelled — reminders cannot be sent.');
    }
    $people = db_all("SELECT id, name, email FROM event_registrations WHERE event_id = ? AND status = 'registered' ORDER BY id", [$id]);
    if (!$people) {
        throw new CrudException('There are no registered participants to remind.');
    }
    $tpl = db_row("SELECT subject, body_html FROM email_templates WHERE code = 'event_reminder' AND status = 'active'");
    $when = format_date($e['start_datetime']) . ', ' . date('g:i A', strtotime((string) $e['start_datetime']));
    $noteHtml = trim($note) !== '' ? '<p style="padding:10px 12px;background:#f1f5f9;border-radius:8px">' . nl2br(e(trim($note))) . '</p>' : '';
    $batch = comm_new_batch();
    $count = comm_enqueue($batch, 'email', $people, function ($p) use ($tpl, $e, $when, $noteHtml) {
        $vars = comm_base_vars() + ['name' => $p['name'], 'event' => $e['title'], 'date' => $when, 'venue' => (string) ($e['venue'] ?: 'Campus'), 'link' => absolute_url('events/' . $e['slug'])];
        if ($tpl) {
            return ['subject' => comm_render((string) $tpl['subject'], $vars, false), 'body' => comm_render((string) $tpl['body_html'], $vars) . $noteHtml];
        }
        return ['subject' => 'Reminder: ' . $e['title'] . ' on ' . $when,
            'body' => '<p>Dear ' . e($p['name']) . ',</p><p>This is a reminder that you are registered for <strong>' . e($e['title']) . '</strong> on ' . e($when)
                . ($e['venue'] ? ' at ' . e($e['venue']) : '') . '.</p>' . $noteHtml . '<p>We look forward to seeing you.</p>'];
    }, ['related_type' => 'event', 'related_id' => $id]);
    log_activity('notify', 'events', $id, sprintf('Queued reminder e-mails to %d participants of "%s"', $count, str_limit((string) $e['title'], 90)));
    return ['batch' => $batch, 'queued' => ['email' => $count], 'progress' => comm_process_queue(['batch' => $batch], 6.0)];
}

/** Check-in / undo / cancel for registrations. Returns number changed. */
function comm_registration_action(array $ids, string $action): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return 0;
    }
    $in = implode(',', $ids);
    $now = date('Y-m-d H:i:s');
    $n = match ($action) {
        // only active (registered) participants can be checked in — cancelled ones must be restored first
        'check_in' => db_exec("UPDATE event_registrations SET status = 'attended', checked_in_at = COALESCE(checked_in_at, ?), checked_in_by = ? WHERE id IN ($in) AND status = 'registered'", [$now, user_id()]),
        'undo_check_in' => db_exec("UPDATE event_registrations SET status = 'registered', checked_in_at = NULL, checked_in_by = NULL WHERE id IN ($in) AND status = 'attended'"),
        'cancel' => db_exec("UPDATE event_registrations SET status = 'cancelled', checked_in_at = NULL, checked_in_by = NULL WHERE id IN ($in) AND status <> 'cancelled'"),
        'restore' => comm_restore_registrations($ids),
        default => throw new CrudException('Unknown registration action.'),
    };
    if ($n) {
        $names = db_column("SELECT r.name FROM event_registrations r WHERE r.id IN ($in) LIMIT 3");
        $eventId = db_value("SELECT event_id FROM event_registrations WHERE id IN ($in) LIMIT 1");
        $verb = ['check_in' => 'Checked in', 'undo_check_in' => 'Reverted check-in for', 'cancel' => 'Cancelled registration of', 'restore' => 'Restored registration of'][$action];
        log_activity($action === 'check_in' ? 'approve' : 'update', 'events', $eventId, sprintf('%s %s%s', $verb, implode(', ', $names), $n > 3 ? ' and ' . ($n - 3) . ' more' : ''));
    }
    return $n;
}

/** Restore cancelled registrations while respecting each event's capacity. Returns the number restored. */
function comm_restore_registrations(array $ids): int
{
    return db_transaction(function () use ($ids) {
        $n = 0;
        foreach (db_all("SELECT r.id, r.event_id, e.max_participants, e.status AS event_status FROM event_registrations r JOIN events e ON e.id = r.event_id
                         WHERE r.status = 'cancelled' AND r.id IN (" . implode(',', array_map('intval', $ids)) . ') ORDER BY r.id FOR UPDATE') as $r) {
            if ($r['event_status'] === 'cancelled') {
                continue;
            }
            if ($r['max_participants']) {
                $taken = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status <> 'cancelled'", [(int) $r['event_id']]);
                if ($taken >= (int) $r['max_participants']) {
                    continue;
                }
            }
            $n += db_exec("UPDATE event_registrations SET status = 'registered' WHERE id = ? AND status = 'cancelled'", [(int) $r['id']]);
        }
        return $n;
    });
}

/* ------------------------------------------------------------------
 * Campaigns
 * ------------------------------------------------------------------ */

function comm_campaign_filter(array $c): array
{
    $f = $c['audience_filter'] ?? null;
    $f = is_array($f) ? $f : (json_decode((string) $f, true) ?: []);
    return ['program_ids' => array_values(array_filter(array_map('intval', (array) ($f['program_ids'] ?? [])))), 'semester_no' => !empty($f['semester_no']) ? (int) $f['semester_no'] : null,
        'emails' => (string) ($f['emails'] ?? '')];
}

function comm_campaign_audience_label(array $c): string
{
    $aud = comm_campaign_audiences()[$c['audience']] ?? label_from_key((string) $c['audience']);
    $f = comm_campaign_filter($c);
    if ($c['audience'] === 'program' && $f['program_ids']) {
        $names = db_column('SELECT short_name FROM programs WHERE id IN (' . implode(',', $f['program_ids']) . ') ORDER BY sort_order, name');
        $aud = implode(', ', $names) . ($f['semester_no'] ? ' · Sem ' . $f['semester_no'] : '');
    } elseif ($c['audience'] === 'custom') {
        $aud = 'Custom list (' . count(comm_audience_people('custom', $f)) . ')';
    }
    return $aud;
}

/** Personalised message for one campaign recipient. */
function comm_campaign_message(array $c, array $p): array
{
    $vars = comm_base_vars() + ['name' => $p['name'] ?: 'there', 'email' => (string) ($p['email'] ?? ''), 'unsubscribe_link' => comm_unsubscribe_url($p['token'] ?? null)];
    if ($c['channel'] !== 'email') {
        return ['subject' => null, 'body' => comm_render(comm_plain_text((string) $c['body_html']), $vars, false)];
    }
    $body = comm_render((string) $c['body_html'], $vars);
    if ($c['audience'] === 'subscribers' && !str_contains((string) $c['body_html'], '{{unsubscribe_link}}')) {
        $body .= '<p style="margin-top:24px;font-size:12px;color:#94a3b8">You are receiving this because you subscribed to ' . e(institute_name())
            . ' updates. <a href="' . e($vars['unsubscribe_link']) . '" style="color:#64748b">Unsubscribe</a></p>';
    }
    return ['subject' => comm_render((string) $c['subject'], $vars, false), 'body' => $body];
}

function comm_campaign_people(array $c): array
{
    $f = comm_campaign_filter($c);
    return comm_audience_people((string) $c['audience'], $f);
}

/** Validate a campaign can be sent; returns the row. */
function comm_campaign_ready(int $id): array
{
    $c = db_row('SELECT * FROM email_campaigns WHERE id = ?', [$id]);
    if (!$c) {
        throw new CrudException('Campaign not found. It may have been deleted.');
    }
    if (in_array($c['status'], ['sending', 'sent'], true)) {
        throw new CrudException($c['status'] === 'sent' ? 'This campaign has already been sent. Duplicate it to send again.' : 'This campaign is already being sent.');
    }
    if (!comm_channel_enabled((string) $c['channel'])) {
        throw new CrudException(strtoupper((string) $c['channel']) . ' is not enabled under Settings → Email & SMS.');
    }
    if ($c['channel'] === 'email' && trim((string) $c['subject']) === '') {
        throw new CrudException('Add a subject line before sending.');
    }
    if (trim(comm_plain_text((string) $c['body_html'])) === '' && !preg_match('/<img/i', (string) $c['body_html'])) {
        throw new CrudException('The message body is empty.');
    }
    return $c;
}

/** Queue every recipient of a campaign and mark it sending. */
function comm_campaign_start(int $id, bool $auto = false): array
{
    $c = comm_campaign_ready($id);
    $people = comm_campaign_people($c);
    $batch = comm_new_batch();
    $count = comm_enqueue($batch, (string) $c['channel'], $people, fn ($p) => comm_campaign_message($c, $p), ['related_type' => 'campaign', 'related_id' => $id, 'campaign_id' => $id]);
    if (!$count) {
        throw new CrudException('No recipients with a valid ' . ($c['channel'] === 'email' ? 'e-mail address' : 'mobile number') . ' were found for this audience.');
    }
    db_update('email_campaigns', ['status' => 'sending', 'started_at' => date('Y-m-d H:i:s'), 'recipients_count' => $count, 'sent_count' => 0, 'failed_count' => 0, 'sent_at' => null], 'id = ?', [$id]);
    log_activity('publish', 'communication', $id, sprintf('%s campaign "%s" to %s recipients (%s)', $auto ? 'Started scheduled' : 'Started sending', str_limit((string) $c['title'], 80),
        number_format($count), comm_campaign_audience_label($c)));
    return ['batch' => $batch, 'recipients' => $count];
}

/** Refresh campaign counters from the queue; completes the campaign when nothing is pending. */
function comm_campaign_sync(int $id): ?array
{
    $c = db_row('SELECT * FROM email_campaigns WHERE id = ?', [$id]);
    if (!$c) {
        return null;
    }
    $p = comm_queue_progress(['campaign_id' => $id]);
    if (!$p['total']) {
        return $c;
    }
    $data = ['sent_count' => $p['sent'], 'failed_count' => $p['failed']];
    if ($p['pending'] === 0 && $c['status'] === 'sending') {
        $data['status'] = $p['sent'] > 0 ? 'sent' : 'failed';
        $data['sent_at'] = date('Y-m-d H:i:s');
        log_activity('publish', 'communication', $id, sprintf('Campaign "%s" finished: %d sent, %d failed', str_limit((string) $c['title'], 80), $p['sent'], $p['failed']),
            $p['sent'] > 0 ? 'success' : 'failed');
        if ($c['created_by']) {
            notify((int) $c['created_by'], 'campaign', 'Campaign sent: ' . $c['title'], sprintf('%s delivered, %s failed.', number_format($p['sent']), number_format($p['failed'])), 'admin/newsletter', 'send');
        }
    }
    db_update('email_campaigns', $data, 'id = ?', [$id]);
    return array_merge($c, $data);
}

/** Start scheduled campaigns that are due. Returns ids started. */
function comm_dispatch_due_campaigns(): array
{
    $started = [];
    foreach (db_column("SELECT id FROM email_campaigns WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= ? ORDER BY scheduled_at LIMIT 5", [date('Y-m-d H:i:s')]) as $id) {
        try {
            comm_campaign_start((int) $id, true);
            $started[] = (int) $id;
        } catch (CrudException $e) {
            db_update('email_campaigns', ['status' => 'failed'], 'id = ?', [(int) $id]);
            log_activity('publish', 'communication', $id, 'Scheduled campaign could not start: ' . $e->getMessage(), 'failed');
        }
    }
    return $started;
}

function comm_campaign_detail(int $id): ?array
{
    $c = db_row('SELECT c.*, u.name AS created_by_name, t.name AS template_name FROM email_campaigns c LEFT JOIN users u ON u.id = c.created_by LEFT JOIN email_templates t ON t.id = c.template_id WHERE c.id = ?', [$id]);
    if (!$c) {
        return null;
    }
    $c['id'] = (int) $c['id'];
    $c['audience_filter'] = comm_campaign_filter($c);
    $c['audience_label'] = comm_campaign_audience_label($c);
    foreach (['recipients_count', 'sent_count', 'failed_count'] as $k) {
        $c[$k] = (int) $c[$k];
    }
    $progress = comm_queue_progress(['campaign_id' => $id]);
    $errors = db_all("SELECT COALESCE(error, 'Unknown error') AS error, COUNT(*) AS n FROM message_queue WHERE campaign_id = ? AND status = 'failed' GROUP BY error ORDER BY n DESC LIMIT 5", [$id]);
    return ['campaign' => $c, 'progress' => $progress, 'errors' => array_map(fn ($r) => ['error' => $r['error'], 'count' => (int) $r['n']], $errors),
        'audience' => in_array($c['status'], ['draft', 'scheduled'], true) ? comm_audience_summary((string) $c['audience'], $c['audience_filter']) : null];
}

/* ------------------------------------------------------------------
 * Message logs
 * ------------------------------------------------------------------ */

/** Re-send a failed / queued message. Returns ['ok' => bool, 'new_id' => ?int]. */
function comm_resend_log(int $id): array
{
    $log = db_row('SELECT * FROM message_logs WHERE id = ?', [$id]);
    if (!$log) {
        throw new CrudException('Message not found.');
    }
    if ($log['status'] === 'sent') {
        throw new CrudException('This message was already delivered.');
    }
    $retried = db_value("SELECT id FROM message_logs WHERE retry_of = ? AND status = 'sent' ORDER BY id LIMIT 1", [$id]);
    if ($retried) {
        throw new CrudException('This message was already re-sent successfully (#' . $retried . ').');
    }
    if (!comm_channel_enabled((string) $log['channel'])) {
        throw new CrudException(($log['channel'] === 'sms' ? 'SMS' : 'WhatsApp') . ' is not enabled under Settings → Email & SMS.');
    }
    [$ok, $error] = comm_deliver(['channel' => $log['channel'], 'recipient' => $log['recipient'], 'subject' => $log['subject'], 'body' => $log['body'],
        'related_type' => $log['related_type'], 'related_id' => $log['related_id'], 'campaign_id' => $log['campaign_id']]);
    $newId = db_value('SELECT id FROM message_logs WHERE id > ? AND recipient = ? AND channel = ? ORDER BY id DESC LIMIT 1', [$id, $log['recipient'], $log['channel']]);
    if ($newId) {
        db_update('message_logs', ['retry_of' => $id], 'id = ?', [(int) $newId]);
    }
    log_activity('resend', 'communication', $id, sprintf('Re-sent %s to %s — %s', $log['channel'], $log['recipient'], $ok ? 'delivered' : 'failed again'), $ok ? 'success' : 'failed');
    return ['ok' => $ok, 'new_id' => $newId ? (int) $newId : null, 'error' => $error];
}

/**
 * True when a logged message carries sign-in secrets — password-reset links, temporary passwords, OTPs — written by
 * the account / portal modules. Broadcasts of this unit (notices, events, campaigns, template tests with sample data)
 * never do. Such bodies are only shown to administrators who manage users (see the message-log detail endpoint).
 */
function comm_log_is_sensitive(array $log): bool
{
    if (in_array($log['related_type'] ?? null, ['notice', 'event', 'event_registration', 'campaign', 'campaign_test', 'template_test', 'newsletter_subscriber'], true)) {
        return false;
    }
    $text = ($log['subject'] ?? '') . ' ' . strip_tags((string) ($log['body'] ?? '')) . ' ' . (string) ($log['body'] ?? '');
    return (bool) preg_match('/pass(word|code)|\bOTP\b|one[- ]time (code|pin)|verification code|reset[-_ ]?(link|token)|[?&](token|reset)=/i', $text);
}

function comm_can_view_sensitive(): bool
{
    return can('users', 'manage');
}

/* ------------------------------------------------------------------
 * Scheduled task (server cron): due campaigns + pending deliveries
 * ------------------------------------------------------------------ */

/** Secret for the scheduled-task URL (derived from the application key, like the backup cron). */
function comm_cron_token(): string
{
    return substr(hash_hmac('sha256', 'gimt-communication-cron', (string) (app_config('app')['key'] ?? '')), 0, 40);
}

function comm_cron_info(): array
{
    $url = absolute_url('api/newsletter/cron?token=' . comm_cron_token());
    return ['url' => $url, 'command' => '*/5 * * * * curl -fsS "' . $url . '" > /dev/null',
        'windows' => 'schtasks /Create /SC MINUTE /MO 5 /TN "GIMT Campaigns" /TR "curl -fsS ' . $url . '"', 'last_run' => setting('_comm_last_cron') ?: null];
}

/**
 * Run by the scheduled task: start scheduled campaigns that are due, then deliver pending queue rows (campaigns,
 * notices, event reminders) oldest batch first within the time budget.
 */
function comm_run_scheduled(float $budget = 20.0): array
{
    $deadline = microtime(true) + $budget;
    $started = comm_dispatch_due_campaigns();
    $processed = 0;
    $batches = db_column("SELECT batch_id FROM message_queue WHERE status IN ('pending', 'processing') GROUP BY batch_id ORDER BY MIN(id) LIMIT 50");
    foreach ($batches as $b) {
        $left = $deadline - microtime(true);
        if ($left < 1) {
            break;
        }
        $processed += (int) (comm_process_queue(['batch' => (string) $b], $left, 2000)['processed'] ?? 0);
    }
    save_setting('_comm_last_cron', date('Y-m-d H:i:s'), 'internal');
    return ['started' => $started, 'processed' => $processed,
        'pending' => (int) db_value("SELECT COUNT(*) FROM message_queue WHERE status IN ('pending', 'processing')")];
}

/* ------------------------------------------------------------------
 * Print views (HTML, A4) — served by api/routes/notices.php and events.php
 * ------------------------------------------------------------------ */

function comm_print_fail(string $title, string $text, int $status = 404): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    print_layout_start($title);
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">' . e($title) . '</p>'
        . '<p class="mx-auto mt-2 max-w-md text-sm text-slate-500">' . e($text) . '</p></div>';
    print_layout_end();
    exit;
}

function comm_print_notice(int $id): void
{
    header('Content-Type: text/html; charset=utf-8');
    if (!can('notices', 'view')) {
        comm_print_fail('Access denied', 'You do not have permission to view notices.', 403);
    }
    $n = comm_notice_find($id);
    if (!$n) {
        comm_print_fail('Notice not found', 'The notice does not exist or has been deleted.');
    }
    $ref = sprintf('GIMT/%s/%s/%03d', strtoupper(substr((string) $n['category'], 0, 3)), current_session()['name'] ?? date('Y'), (int) $n['id']);
    print_layout_start('Notice - ' . $n['title']);
    ?>
<div class="mt-5 flex items-start justify-between gap-6 text-[12px] text-slate-700">
  <p>Ref. No.: <strong class="font-mono"><?= e($ref) ?></strong></p>
  <p>Date: <strong><?= e(format_date($n['publish_date'] ?: $n['created_at'])) ?></strong></p>
</div>
<div class="mt-6 text-center">
  <p class="inline-block border-b-2 border-brand-900 pb-0.5 font-display text-lg font-extrabold uppercase tracking-[0.3em] text-brand-900"><?= $n['category'] === 'circular' ? 'Circular' : 'Notice' ?></p>
  <h1 class="mt-3 font-display text-xl font-bold text-slate-900"><?= e($n['title']) ?></h1>
  <p class="mt-1 text-[11px] uppercase tracking-wider text-slate-500"><?= e(comm_notice_categories()[$n['category']] ?? label_from_key((string) $n['category'])) ?> · For: <?= e(comm_notice_audience_label($n)) ?></p>
</div>
<div class="mt-6 text-[13px] leading-relaxed text-slate-800 [&_a]:text-brand-700 [&_a]:underline [&_h2]:mt-4 [&_h2]:text-base [&_h2]:font-bold [&_h3]:mt-3 [&_h3]:font-semibold [&_li]:mt-1 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:mt-2 [&_table]:mt-3 [&_table]:w-full [&_td]:border [&_td]:border-slate-300 [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:border-slate-300 [&_th]:bg-slate-50 [&_th]:px-2 [&_th]:py-1 [&_ul]:list-disc [&_ul]:pl-6">
  <?= $n['description'] ?: '<p class="text-slate-400">No description.</p>' ?>
</div>
<?php if ($n['attachment']): ?>
<p class="mt-5 text-[12px] text-slate-600">Attachment: <span class="font-medium text-slate-800"><?= e(basename((string) $n['attachment'])) ?></span></p>
<?php endif; ?>
<?php if ($n['expiry_date']): ?>
<p class="mt-2 text-[12px] text-slate-600">Valid till: <strong><?= e(format_datetime($n['expiry_date'])) ?></strong></p>
<?php endif; ?>
<div class="mt-16 flex items-end justify-between">
  <div class="text-[11px] text-slate-500">
    <?php if ($n['show_on_website'] && $n['status'] !== 'draft'): ?>
    <div data-qr="<?= e(absolute_url('notices/' . $n['slug'])) ?>" class="h-16 w-16"></div>
    <p class="mt-1">Scan to read online</p>
    <?php endif; ?>
  </div>
  <div class="text-center text-[12px] text-slate-700">
    <div class="w-56 border-t border-slate-500 pt-1 font-semibold">Registrar / Authorised Signatory</div>
    <p class="text-[11px] text-slate-500"><?= e(institute_name()) ?></p>
  </div>
</div>
<p class="mt-8 border-t border-slate-200 pt-2 text-[10px] text-slate-400">Copy to: Notice boards · Heads of Departments · Office file<?= $n['created_by_name'] ? ' · Issued by ' . e($n['created_by_name']) : '' ?></p>
    <?php
    print_layout_end();
    exit;
}

function comm_print_event_attendees(int $id): void
{
    header('Content-Type: text/html; charset=utf-8');
    if (!can('events', 'view')) {
        comm_print_fail('Access denied', 'You do not have permission to view events.', 403);
    }
    $e = db_row('SELECT * FROM events WHERE id = ?', [$id]);
    if (!$e) {
        comm_print_fail('Event not found', 'The event does not exist or has been deleted.');
    }
    $status = in_array($_GET['status'] ?? '', ['registered', 'attended'], true) ? $_GET['status'] : null;
    $rows = db_all("SELECT * FROM event_registrations WHERE event_id = ? AND status <> 'cancelled'" . ($status ? ' AND status = ?' : '') . ' ORDER BY name', $status ? [$id, $status] : [$id]);
    $stats = comm_event_stats($id);
    print_layout_start('Attendance Sheet - ' . $e['title'], true, 'portrait');
    ?>
<div class="mt-5 flex items-start justify-between gap-4">
  <div>
    <p class="text-[11px] font-semibold uppercase tracking-wider text-accent-700">Event attendance sheet</p>
    <h1 class="font-display text-xl font-extrabold text-brand-900"><?= e($e['title']) ?></h1>
    <p class="mt-0.5 text-[12px] text-slate-600"><?= e(format_datetime($e['start_datetime'])) ?><?= $e['end_datetime'] ? ' – ' . e(format_datetime($e['end_datetime'])) : '' ?><?= $e['venue'] ? ' · ' . e($e['venue']) : '' ?></p>
    <?php if ($e['organizer']): ?><p class="text-[12px] text-slate-600">Organised by <?= e($e['organizer']) ?></p><?php endif; ?>
  </div>
  <div class="grid shrink-0 grid-cols-3 gap-2 text-center text-[11px]">
    <div class="rounded-lg border border-slate-200 px-3 py-1.5"><p class="text-lg font-bold text-brand-900"><?= (int) $stats['active'] ?></p><p class="text-slate-500">Registered</p></div>
    <div class="rounded-lg border border-slate-200 px-3 py-1.5"><p class="text-lg font-bold text-emerald-700"><?= (int) $stats['attended'] ?></p><p class="text-slate-500">Checked in</p></div>
    <div class="rounded-lg border border-slate-200 px-3 py-1.5"><p class="text-lg font-bold text-slate-700"><?= $e['max_participants'] ? (int) $e['max_participants'] : '—' ?></p><p class="text-slate-500">Capacity</p></div>
  </div>
</div>
<table class="mt-5 w-full border-collapse text-[11px]">
  <thead><tr>
    <th class="w-8 border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">#</th>
    <th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Participant</th>
    <th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Contact</th>
    <th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Organisation</th>
    <th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Check-in</th>
    <th class="w-28 border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Signature</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $i => $r): ?>
    <tr class="<?= $i % 2 ? 'bg-slate-50' : '' ?>">
      <td class="border border-slate-200 px-2 py-1.5 align-top"><?= $i + 1 ?></td>
      <td class="border border-slate-200 px-2 py-1.5 align-top"><span class="font-semibold text-slate-800"><?= e($r['name']) ?></span><br><span class="text-slate-500"><?= e(comm_participant_types()[$r['participant_type'] ?: 'guest'] ?? label_from_key((string) $r['participant_type'])) ?></span></td>
      <td class="border border-slate-200 px-2 py-1.5 align-top"><?= e($r['email']) ?><?= $r['phone'] ? '<br>' . e($r['phone']) : '' ?></td>
      <td class="border border-slate-200 px-2 py-1.5 align-top"><?= e($r['organization'] ?: '—') ?></td>
      <td class="border border-slate-200 px-2 py-1.5 align-top"><?= $r['checked_in_at'] ? e(date('d M, g:i A', strtotime((string) $r['checked_in_at']))) : '' ?></td>
      <td class="border border-slate-200 px-2 py-1.5"></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <tr><td colspan="6" class="border border-slate-200 px-2 py-8 text-center text-slate-500">No registrations yet.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<div class="mt-14 grid grid-cols-2 gap-10 text-center text-[11px] text-slate-600">
  <div><div class="border-t border-slate-400 pt-1">Event Coordinator</div></div>
  <div><div class="border-t border-slate-400 pt-1">Head of Department / Dean</div></div>
</div>
<p class="mt-6 text-[10px] text-slate-400">Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> · <?= count($rows) ?> participants</p>
    <?php
    print_layout_end();
    exit;
}
