<?php
/**
 * WhatsApp shared inbox (Module 2) — conversation list, chat history, agent replies, assignment, status, notes, tags,
 * bot hand-over, quick replies, agent stats and the activity feed. Prefix wa_inbox_.
 *
 * Loaded by app/services/whatsapp.php (core). Only function definitions here: no queries at include time.
 *
 * RBAC (module `whatsapp`):
 *   view   — open the inbox; counsellors see conversations assigned to them + unassigned ones, managers see all
 *   create — send messages / templates, retry failed sends, add notes, claim an unassigned conversation
 *   edit   — assign / unassign, change status, tags, bot hand-over
 *   delete — delete other people's notes
 *   manage — see every conversation, manage quick replies
 */

const WA_INBOX_FILTERS = ['all', 'unread', 'read', 'open', 'pending', 'resolved', 'mine', 'unassigned', 'bot'];
const WA_INBOX_STATUSES = ['open', 'pending', 'resolved'];
const WA_INBOX_TAG_COLORS = ['blue', 'green', 'amber', 'red', 'violet', 'slate', 'cyan', 'pink', 'orange', 'navy'];

/** Managers (whatsapp manage, super admin) see every conversation; others see their own + unassigned. */
function wa_inbox_is_manager(): bool
{
    return can('whatsapp', 'manage');
}

/** Visibility clause for wa_conversations aliased $a. */
function wa_inbox_scope(string $a = 'c'): array
{
    if (wa_inbox_is_manager()) {
        return ['1=1', []];
    }
    return ["($a.assigned_to = ? OR $a.assigned_to IS NULL)", [(int) user_id()]];
}

function wa_inbox_visible(array $conv): bool
{
    if (wa_inbox_is_manager()) {
        return true;
    }
    return empty($conv['assigned_to']) || (int) $conv['assigned_to'] === (int) user_id();
}

/** SQL for the moment the 24-hour window opened (latest inbound of conversation or contact). */
function wa_inbox_inbound_sql(string $c = 'c', string $k = 'k'): string
{
    return "CASE WHEN $c.last_inbound_at IS NULL THEN $k.last_inbound_at
                 WHEN $k.last_inbound_at IS NULL OR $c.last_inbound_at >= $k.last_inbound_at THEN $c.last_inbound_at
                 ELSE $k.last_inbound_at END";
}

/** Filter key => SQL condition on c (conversation) / k (contact). */
function wa_inbox_filter_sql(string $filter): array
{
    $uid = (int) user_id();
    switch ($filter) {
        case 'unread':
            return ['c.unread_count > 0', []];
        case 'read':
            return ['c.unread_count = 0', []];
        case 'open':
        case 'pending':
        case 'resolved':
            return ['c.status = ?', [$filter]];
        case 'mine':
            return ['c.assigned_to = ?', [$uid]];
        case 'unassigned':
            return ["c.assigned_to IS NULL AND c.status <> 'resolved'", []];
        case 'bot':
            return ["c.bot_active = 1 AND c.status <> 'resolved'", []];
        default:
            return ['1=1', []];
    }
}

/** Search clause (name, phone digits, email, application no). */
function wa_inbox_search_sql(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return ['1=1', []];
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
    $sql = '(k.name LIKE ? OR k.email LIKE ? OR k.application_no LIKE ? OR c.last_message_preview LIKE ?';
    $args = [$like, $like, $like, $like];
    $digits = preg_replace('/\D+/', '', $q);
    if (strlen($digits) >= 3) {
        $sql .= ' OR k.phone LIKE ?';
        $args[] = '%' . $digits . '%';
    }
    return [$sql . ')', $args];
}

/**
 * Conversation list (cursor paged by last activity). $opts: q, filter, cursor ("Y-m-d H:i:s|id"), limit, tag.
 * Returns ['rows' => [], 'next_cursor' => ?string, 'counts' => [filter => n] (first page only)].
 */
function wa_inbox_list(array $opts): array
{
    $filter = in_array($opts['filter'] ?? 'all', WA_INBOX_FILTERS, true) ? $opts['filter'] : 'all';
    $limit = max(5, min(50, (int) ($opts['limit'] ?? 25)));
    $q = (string) ($opts['q'] ?? '');
    [$scopeSql, $scopeArgs] = wa_inbox_scope('c');
    [$filterSql, $filterArgs] = wa_inbox_filter_sql($filter);
    [$searchSql, $searchArgs] = wa_inbox_search_sql($q);
    $where = ["$scopeSql", "$filterSql", "$searchSql"];
    $args = array_merge($scopeArgs, $filterArgs, $searchArgs);
    $tagId = (int) ($opts['tag'] ?? 0);
    if ($tagId > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM wa_contact_tags ct WHERE ct.contact_id = c.contact_id AND ct.tag_id = ?)';
        $args[] = $tagId;
    }
    $sortExpr = 'COALESCE(c.last_message_at, c.created_at)';
    $cursor = (string) ($opts['cursor'] ?? '');
    if ($cursor !== '' && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $cursor, $mm)) {
        $where[] = "($sortExpr < ? OR ($sortExpr = ? AND c.id < ?))";
        array_push($args, $mm[1], $mm[1], (int) $mm[2]);
    }
    $inbound = wa_inbox_inbound_sql();
    $rows = db_all(
        "SELECT c.id, c.contact_id, c.status, c.assigned_to, c.unread_count, c.last_message_at, c.last_message_preview,
                c.last_message_direction, c.bot_active, c.created_at, $sortExpr AS sort_at, ($inbound) AS window_from,
                k.name, k.phone, k.country_code, k.national_number, k.contact_type, k.opt_in, k.status AS contact_status,
                k.program_name, u.name AS assigned_name, u.avatar AS assigned_avatar,
                (SELECT m.status FROM wa_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_status,
                (SELECT m.type FROM wa_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_type
           FROM wa_conversations c
           JOIN wa_contacts k ON k.id = c.contact_id
           LEFT JOIN users u ON u.id = c.assigned_to
          WHERE " . implode(' AND ', $where) . "
          ORDER BY $sortExpr DESC, c.id DESC
          LIMIT " . ($limit + 1),
        $args
    );
    $hasMore = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $tags = wa_inbox_tags_for_contacts(array_map(fn ($r) => (int) $r['contact_id'], $rows));
    $out = [];
    foreach ($rows as $r) {
        $out[] = wa_inbox_row($r, $tags[(int) $r['contact_id']] ?? []);
    }
    $next = null;
    if ($hasMore && $rows) {
        $last = end($rows);
        $next = $last['sort_at'] . '|' . $last['id'];
    }
    $res = ['rows' => $out, 'next_cursor' => $next];
    if ($cursor === '') {
        $res['counts'] = wa_inbox_counts($q);
    }
    return $res;
}

/** Shape one conversation list row. */
function wa_inbox_row(array $r, array $tags = []): array
{
    $windowFrom = $r['window_from'] ?? null;
    $expires = $windowFrom ? date('Y-m-d H:i:s', strtotime($windowFrom) + 86400) : null;
    $masked = wa_inbox_mask_phones();
    return [
        'id'                     => (int) $r['id'],
        'contact_id'             => (int) $r['contact_id'],
        'name'                   => $r['name'] ?: wa_inbox_phone_display($r['phone']),
        'has_name'               => (bool) $r['name'],
        'phone'                  => $masked ? wa_inbox_phone_display($r['phone']) : $r['phone'],
        'phone_display'          => wa_inbox_phone_display($r['phone']),
        'phone_masked'           => $masked,
        'contact_type'           => $r['contact_type'],
        'program_name'           => $r['program_name'] ?? null,
        'opt_in'                 => (bool) $r['opt_in'],
        'blocked'                => ($r['contact_status'] ?? 'active') === 'blocked',
        'status'                 => $r['status'],
        'assigned_to'            => $r['assigned_to'] !== null ? (int) $r['assigned_to'] : null,
        'assigned_name'          => $r['assigned_name'] ?? null,
        'assigned_avatar'        => $r['assigned_avatar'] ?? null,
        'unread_count'           => (int) $r['unread_count'],
        'last_message_at'        => $r['last_message_at'] ?: $r['created_at'],
        'last_message_preview'   => $r['last_message_preview'],
        'last_message_direction' => $r['last_message_direction'],
        'last_status'            => $r['last_status'] ?? null,
        'last_type'              => $r['last_type'] ?? null,
        'bot_active'             => (bool) $r['bot_active'],
        'window_open'            => $expires !== null && strtotime($expires) > time(),
        'window_expires_at'      => $expires,
        'tags'                   => $tags,
    ];
}

/** Counts per filter for the visible scope (and search). */
function wa_inbox_counts(string $q = ''): array
{
    [$scopeSql, $scopeArgs] = wa_inbox_scope('c');
    [$searchSql, $searchArgs] = wa_inbox_search_sql($q);
    $uid = (int) user_id();
    $row = db_row(
        "SELECT COUNT(*) AS all_count,
                SUM(c.unread_count > 0) AS unread,
                SUM(c.unread_count = 0) AS read_count,
                SUM(c.status = 'open') AS open_count,
                SUM(c.status = 'pending') AS pending,
                SUM(c.status = 'resolved') AS resolved,
                SUM(c.assigned_to = ?) AS mine,
                SUM(c.assigned_to IS NULL AND c.status <> 'resolved') AS unassigned,
                SUM(c.bot_active = 1 AND c.status <> 'resolved') AS bot,
                COALESCE(SUM(c.unread_count), 0) AS unread_messages
           FROM wa_conversations c JOIN wa_contacts k ON k.id = c.contact_id
          WHERE $scopeSql AND $searchSql",
        array_merge([$uid], $scopeArgs, $searchArgs)
    ) ?: [];
    return [
        'all'             => (int) ($row['all_count'] ?? 0),
        'unread'          => (int) ($row['unread'] ?? 0),
        'read'            => (int) ($row['read_count'] ?? 0),
        'open'            => (int) ($row['open_count'] ?? 0),
        'pending'         => (int) ($row['pending'] ?? 0),
        'resolved'        => (int) ($row['resolved'] ?? 0),
        'mine'            => (int) ($row['mine'] ?? 0),
        'unassigned'      => (int) ($row['unassigned'] ?? 0),
        'bot'             => (int) ($row['bot'] ?? 0),
        'unread_messages' => (int) ($row['unread_messages'] ?? 0),
    ];
}

/** Settings › Security › "mask phone numbers" (admin part) applies to this user. */
function wa_inbox_mask_phones(): bool
{
    static $mask = null;
    if ($mask === null) {
        try {
            $mask = function_exists('wa_adm_should_mask') && wa_adm_should_mask();
        } catch (Throwable $e) {
            $mask = false;
        }
    }
    return $mask;
}

/** "+91 98765 43210" from 919876543210 (masked "+91 98•••••210" when the security setting applies). */
function wa_inbox_phone_display(?string $phone): string
{
    if (wa_inbox_mask_phones() && function_exists('wa_adm_mask_phone')) {
        return wa_adm_mask_phone($phone);
    }
    $d = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($d) === 12 && str_starts_with($d, '91')) {
        return '+91 ' . substr($d, 2, 5) . ' ' . substr($d, 7);
    }
    if (function_exists('phone_display')) {
        $p = phone_display('+' . $d);
        if ($p) {
            return $p;
        }
    }
    return $d !== '' ? '+' . $d : '';
}

/** Raw conversation row joined with its contact (no visibility check). */
function wa_inbox_get(int $id): ?array
{
    return db_row(
        'SELECT c.*, k.name, k.phone, k.last_inbound_at AS contact_last_inbound_at, k.status AS contact_status, k.opt_in
           FROM wa_conversations c JOIN wa_contacts k ON k.id = c.contact_id WHERE c.id = ?',
        [$id]
    );
}

/** Live header state of a conversation (polled by the chat window). */
function wa_inbox_summary(int $id): ?array
{
    $inbound = wa_inbox_inbound_sql();
    $r = db_row(
        "SELECT c.*, ($inbound) AS window_from, k.name, k.phone, k.country_code, k.national_number, k.contact_type,
                k.opt_in, k.status AS contact_status, k.program_name, u.name AS assigned_name, u.avatar AS assigned_avatar,
                (SELECT m.status FROM wa_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_status,
                (SELECT m.type FROM wa_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_type,
                rb.name AS resolved_by_name
           FROM wa_conversations c
           JOIN wa_contacts k ON k.id = c.contact_id
           LEFT JOIN users u ON u.id = c.assigned_to
           LEFT JOIN users rb ON rb.id = c.resolved_by
          WHERE c.id = ?",
        [$id]
    );
    if (!$r) {
        return null;
    }
    $row = wa_inbox_row($r, wa_inbox_tags_for_contacts([(int) $r['contact_id']])[(int) $r['contact_id']] ?? []);
    $row['bot_handover_at'] = $r['bot_handover_at'];
    $row['resolved_at'] = $r['resolved_at'];
    $row['resolved_by_name'] = $r['resolved_by_name'];
    $row['window_last_inbound_at'] = $r['window_from'];
    return $row;
}

/** Tags of several contacts: contact_id => [{id,name,color}]. */
function wa_inbox_tags_for_contacts(array $contactIds): array
{
    $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
    if (!$contactIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($contactIds), '?'));
    $out = [];
    foreach (db_all("SELECT ct.contact_id, t.id, t.name, t.color FROM wa_contact_tags ct JOIN wa_tags t ON t.id = ct.tag_id
                      WHERE ct.contact_id IN ($in) ORDER BY t.name", $contactIds) as $t) {
        $out[(int) $t['contact_id']][] = ['id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color'] ?: 'slate'];
    }
    return $out;
}

/** Contact profile aggregate for the right panel (contact + linked ERP records). */
function wa_inbox_profile(int $contactId): array
{
    $k = db_row(
        'SELECT k.*, p.name AS program_label, cu.name AS counsellor_name
           FROM wa_contacts k
           LEFT JOIN programs p ON p.id = k.program_id
           LEFT JOIN users cu ON cu.id = k.counsellor_id
          WHERE k.id = ?',
        [$contactId]
    );
    if (!$k) {
        return [];
    }
    $links = [];
    $fees = null;
    if (!empty($k['student_id'])) {
        $s = db_row(
            "SELECT s.id, s.first_name, s.last_name, s.admission_no, s.status, s.current_semester, b.name AS batch_name, p.name AS program_name
               FROM students s LEFT JOIN batches b ON b.id = s.batch_id LEFT JOIN programs p ON p.id = s.program_id WHERE s.id = ?",
            [(int) $k['student_id']]
        );
        if ($s) {
            $links[] = [
                'type' => 'student', 'id' => (int) $s['id'], 'label' => 'Student profile',
                'title' => trim($s['first_name'] . ' ' . $s['last_name']), 'ref' => $s['admission_no'],
                'status' => $s['status'], 'detail' => trim(($s['program_name'] ?? '') . ($s['current_semester'] ? ' · Sem ' . $s['current_semester'] : '')),
                'to' => '/students/' . (int) $s['id'],
            ];
            $f = db_row(
                "SELECT COALESCE(SUM(balance_amount), 0) AS balance,
                        COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN balance_amount ELSE 0 END), 0) AS overdue,
                        MIN(CASE WHEN balance_amount > 0 THEN due_date END) AS next_due
                   FROM student_fees WHERE student_id = ? AND status NOT IN ('paid','waived','cancelled')",
                [(int) $s['id']]
            );
            if ($f) {
                $fees = ['balance' => (float) $f['balance'], 'overdue' => (float) $f['overdue'], 'next_due' => $f['next_due']];
            }
        }
    }
    if (!empty($k['admission_id'])) {
        $a = db_row(
            'SELECT a.id, a.application_no, a.first_name, a.last_name, a.stage, a.admission_fee, a.fee_paid, p.name AS program_name
               FROM admissions a LEFT JOIN programs p ON p.id = a.program_id WHERE a.id = ?',
            [(int) $k['admission_id']]
        );
        if ($a) {
            $links[] = [
                'type' => 'admission', 'id' => (int) $a['id'], 'label' => 'Application',
                'title' => trim($a['first_name'] . ' ' . $a['last_name']), 'ref' => $a['application_no'],
                'status' => $a['stage'], 'detail' => $a['program_name'],
                'to' => '/admissions/' . (int) $a['id'],
            ];
        }
    }
    if (!empty($k['enquiry_id'])) {
        $en = db_row(
            'SELECT e.id, e.name, e.status, e.follow_up_date, e.created_at, p.name AS program_name
               FROM enquiries e LEFT JOIN programs p ON p.id = e.program_id WHERE e.id = ?',
            [(int) $k['enquiry_id']]
        );
        if ($en) {
            $links[] = [
                'type' => 'enquiry', 'id' => (int) $en['id'], 'label' => 'Enquiry',
                'title' => $en['name'], 'ref' => 'ENQ #' . (int) $en['id'],
                'status' => $en['status'], 'detail' => trim(($en['program_name'] ?? '') . ($en['follow_up_date'] ? ' · Follow-up ' . date('d M', strtotime($en['follow_up_date'])) : '')),
                'to' => '/enquiries?q=' . rawurlencode((string) $en['name']),
            ];
        }
    }
    $stageName = null;
    if (!empty($k['stage'])) {
        $stageName = db_value('SELECT name FROM wa_followup_stages WHERE stage_key = ?', [$k['stage']]) ?: null;
    }
    $masked = wa_inbox_mask_phones();
    return [
        'id'               => (int) $k['id'],
        'name'             => $k['name'],
        'phone'            => $masked ? wa_inbox_phone_display($k['phone']) : $k['phone'],
        'phone_display'    => wa_inbox_phone_display($k['phone']),
        'phone_masked'     => $masked,
        'email'            => $k['email'],
        'alt_phone'        => $masked && $k['alt_phone'] ? wa_inbox_phone_display($k['alt_phone']) : $k['alt_phone'],
        'contact_type'     => $k['contact_type'],
        'program_id'       => $k['program_id'] !== null ? (int) $k['program_id'] : null,
        'program_name'     => $k['program_label'] ?: $k['program_name'],
        'batch'            => $k['batch'],
        'application_no'   => $k['application_no'],
        'stage'            => $k['stage'],
        'stage_name'       => $stageName,
        'admission_status' => $k['admission_status'],
        'payment_status'   => $k['payment_status'],
        'counsellor_id'    => $k['counsellor_id'] !== null ? (int) $k['counsellor_id'] : null,
        'counsellor_name'  => $k['counsellor_name'],
        'city'             => $k['city'],
        'state'            => $k['state'],
        'language'         => $k['language'],
        'opt_in'           => (bool) $k['opt_in'],
        'opt_in_at'        => $k['opt_in_at'],
        'opt_in_source'    => $k['opt_in_source'],
        'opted_out_at'     => $k['opted_out_at'],
        'source'           => $k['source'],
        'status'           => $k['status'],
        'student_id'       => $k['student_id'] !== null ? (int) $k['student_id'] : null,
        'enquiry_id'       => $k['enquiry_id'] !== null ? (int) $k['enquiry_id'] : null,
        'admission_id'     => $k['admission_id'] !== null ? (int) $k['admission_id'] : null,
        'last_inbound_at'  => $k['last_inbound_at'],
        'created_at'       => $k['created_at'],
        'links'            => $links,
        'fees'             => $fees,
    ];
}

/** Full conversation payload: summary + profile + permissions. */
function wa_inbox_detail(int $id): ?array
{
    $summary = wa_inbox_summary($id);
    if (!$summary) {
        return null;
    }
    $notes = (int) db_value('SELECT COUNT(*) FROM wa_notes WHERE contact_id = ?', [$summary['contact_id']]);
    return [
        'conversation' => $summary,
        'profile'      => wa_inbox_profile($summary['contact_id']),
        'notes_count'  => $notes,
        'permissions'  => wa_inbox_permissions($summary),
    ];
}

function wa_inbox_permissions(?array $conv = null): array
{
    return [
        'send'    => can('whatsapp', 'create'),
        'edit'    => can('whatsapp', 'edit'),
        'delete'  => can('whatsapp', 'delete'),
        'manage'  => wa_inbox_is_manager(),
        'claim'   => can('whatsapp', 'create') && $conv !== null && empty($conv['assigned_to']),
        'templates' => can('whatsapp', 'create'),
        // "Create admission enquiry" in the contact panel (POST whatsapp/contacts/{id}/enquiry)
        'create_enquiry' => can('whatsapp_contacts', 'edit') && can('enquiries', 'create'),
    ];
}

/* ------------------------------------------------------------------------------------------------ messages */

/**
 * Messages of a conversation, oldest first. $beforeId pages backwards (load older); without it the latest page
 * (polling merges by id, so status ticks of recent messages refresh too).
 */
function wa_inbox_messages(int $convId, ?int $beforeId = null, int $limit = 40): array
{
    $limit = max(10, min(100, $limit));
    $args = [$convId];
    $where = 'm.conversation_id = ?';
    if ($beforeId) {
        // chronological cursor (created_at, id): imported / backdated rows can have a higher id than newer ones
        $at = db_value('SELECT created_at FROM wa_messages WHERE id = ? AND conversation_id = ?', [$beforeId, $convId]);
        if ($at) {
            $where .= ' AND (m.created_at < ? OR (m.created_at = ? AND m.id < ?))';
            array_push($args, $at, $at, $beforeId);
        } else {
            $where .= ' AND m.id < ?';
            $args[] = $beforeId;
        }
    }
    $rows = db_all(
        "SELECT m.*, u.name AS sent_by_name FROM wa_messages m LEFT JOIN users u ON u.id = m.sent_by
          WHERE $where ORDER BY m.created_at DESC, m.id DESC LIMIT " . ($limit + 1),
        $args
    );
    $hasMore = count($rows) > $limit;
    $rows = array_reverse(array_slice($rows, 0, $limit));
    return ['messages' => array_map('wa_inbox_format_message', $rows), 'has_more' => $hasMore];
}

function wa_inbox_json($v)
{
    if ($v === null || $v === '') {
        return null;
    }
    $d = json_decode((string) $v, true);
    return is_array($d) ? $d : null;
}

/** Public shape of a wa_messages row (no raw payloads). */
function wa_inbox_format_message(array $m): array
{
    return [
        'id'              => (int) $m['id'],
        'conversation_id' => (int) $m['conversation_id'],
        'contact_id'      => (int) $m['contact_id'],
        'direction'       => $m['direction'],
        'type'            => $m['type'],
        'body'            => $m['body'],
        'media_url'       => $m['media_url'],
        'media_local'     => $m['media_local'],
        'media_mime'      => $m['media_mime'],
        'file_name'       => $m['file_name'],
        'interactive'     => wa_inbox_json($m['interactive'] ?? null),
        'template_id'     => $m['template_id'] !== null ? (int) $m['template_id'] : null,
        'template_name'   => $m['template_name'],
        'template_vars'   => wa_inbox_json($m['template_vars'] ?? null),
        'status'          => $m['status'],
        'error_code'      => $m['error_code'],
        'error'           => $m['error'],
        'sender_type'     => $m['sender_type'],
        'sent_by'         => $m['sent_by'] !== null ? (int) $m['sent_by'] : null,
        'sent_by_name'    => $m['sent_by_name'] ?? null,
        'reply_to_id'     => $m['reply_to_id'] !== null ? (int) $m['reply_to_id'] : null,
        'campaign_id'     => $m['campaign_id'] !== null ? (int) $m['campaign_id'] : null,
        'created_at'      => $m['created_at'],
        'sent_at'         => $m['sent_at'],
        'delivered_at'    => $m['delivered_at'],
        'read_at'         => $m['read_at'],
        'failed_at'       => $m['failed_at'],
        'can_retry'       => $m['direction'] === 'out' && $m['status'] === 'failed',
    ];
}

function wa_inbox_message(int $id): ?array
{
    $m = db_row('SELECT m.*, u.name AS sent_by_name FROM wa_messages m LEFT JOIN users u ON u.id = m.sent_by WHERE m.id = ?', [$id]);
    return $m ? wa_inbox_format_message($m) : null;
}

/** Human message for core send refusal codes. */
function wa_inbox_refusal(array $res, ?array $contact = null): string
{
    // core uses 'opted_out' both for real opt-outs and for marketing templates to contacts who never opted in
    if (($res['code'] ?? '') === 'opted_out' && $contact && empty($contact['opted_out_at'])) {
        return 'This is a marketing template and the contact has not opted in to WhatsApp marketing messages. Send a utility template, or record their opt-in first.';
    }
    $map = [
        'invalid_phone'         => 'This contact does not have a valid WhatsApp number.',
        'blocked'               => 'This contact is blocked. Unblock the contact before messaging.',
        'opted_out'             => 'This contact has opted out of WhatsApp messages.',
        'window_closed'         => 'The 24-hour customer service window is closed. Only approved templates can be sent until the contact replies.',
        'template_not_approved' => 'This template is not approved by WhatsApp yet.',
        'missing_variables'     => 'Fill every template variable before sending.',
    ];
    return $map[$res['code'] ?? ''] ?? ($res['error'] ?? 'The message could not be queued.');
}

/**
 * Template variables from the browser: either a named map {student_name: "…"} or the positional structure
 * {header: [..], body: [..], buttons: {"0": [..]}}. Scalars are trimmed strings; at most two levels deep.
 */
function wa_inbox_clean_vars(array $vars, int $depth = 0): array
{
    $out = [];
    foreach ($vars as $k => $v) {
        if (is_array($v)) {
            if ($depth < 2) {
                $out[$k] = wa_inbox_clean_vars($v, $depth + 1);
            }
        } elseif (is_scalar($v) || $v === null) {
            $out[$k] = mb_substr(trim((string) $v), 0, 1024);
        }
    }
    return $out;
}

/** Validate an agent message payload; returns [msg array for wa_send, field errors]. */
function wa_inbox_build_message(array $conv, array $in): array
{
    $type = (string) ($in['type'] ?? 'text');
    $errors = [];
    $body = isset($in['body']) ? trim((string) $in['body']) : '';
    $msg = ['contact_id' => (int) $conv['contact_id'], 'sender_type' => 'agent', 'sent_by' => user_id()];
    // a quoted message must belong to this conversation
    if (!empty($in['reply_to_id']) && is_numeric($in['reply_to_id'])
        && db_value('SELECT id FROM wa_messages WHERE id = ? AND conversation_id = ?', [(int) $in['reply_to_id'], (int) $conv['id']])) {
        $msg['reply_to_id'] = (int) $in['reply_to_id'];
    }
    switch ($type) {
        case 'text':
            if ($body === '') {
                $errors['body'] = 'Type a message.';
            } elseif (mb_strlen($body) > 4096) {
                $errors['body'] = 'A WhatsApp message can be at most 4,096 characters.';
            }
            $msg += ['type' => 'text', 'body' => $body];
            break;
        case 'location':
            $lat = isset($in['latitude']) && is_numeric($in['latitude']) ? (float) $in['latitude'] : null;
            $lng = isset($in['longitude']) && is_numeric($in['longitude']) ? (float) $in['longitude'] : null;
            $label = trim((string) ($in['label'] ?? ''));
            $address = trim((string) ($in['address'] ?? ''));
            if ($lat === null || $lat < -90 || $lat > 90) {
                $errors['latitude'] = 'Enter a latitude between -90 and 90.';
            }
            if ($lng === null || $lng < -180 || $lng > 180) {
                $errors['longitude'] = 'Enter a longitude between -180 and 180.';
            }
            if (mb_strlen($label) > 120) {
                $errors['label'] = 'Keep the place name under 120 characters.';
            }
            if (mb_strlen($address) > 200) {
                $errors['address'] = 'Keep the address under 200 characters.';
            }
            if (!$errors) {
                $url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(sprintf('%.6f,%.6f', $lat, $lng));
                $text = "📍 *Location" . ($label !== '' ? ': ' . $label : '') . "*\n" . ($address !== '' ? $address . "\n" : '') . $url;
                $msg += ['type' => 'text', 'body' => $text];
            }
            break;
        case 'contact':
            $cname = trim((string) ($in['contact_name'] ?? ''));
            $cphone = trim((string) ($in['contact_phone'] ?? ''));
            $cemail = trim((string) ($in['contact_email'] ?? ''));
            $corg = trim((string) ($in['contact_org'] ?? ''));
            if ($cname === '') {
                $errors['contact_name'] = 'Enter the name to share.';
            } elseif (mb_strlen($cname) > 120) {
                $errors['contact_name'] = 'Keep the name under 120 characters.';
            }
            $cdigits = strlen(preg_replace('/\D+/', '', $cphone));
            if ($cphone === '' || $cdigits < 8 || $cdigits > 15 || mb_strlen($cphone) > 30 || !preg_match('/^[+0-9 ()\-]+$/', $cphone)) {
                $errors['contact_phone'] = 'Enter a valid phone number.';
            }
            if ($cemail !== '' && (!filter_var($cemail, FILTER_VALIDATE_EMAIL) || mb_strlen($cemail) > 190)) {
                $errors['contact_email'] = 'Enter a valid email address.';
            }
            if (mb_strlen($corg) > 120) {
                $errors['contact_org'] = 'Keep the designation under 120 characters.';
            }
            if (!$errors) {
                $lines = ["👤 *Contact card*", $cname];
                if ($corg !== '') {
                    $lines[] = $corg;
                }
                $lines[] = '📞 ' . $cphone;
                if ($cemail !== '') {
                    $lines[] = '✉️ ' . $cemail;
                }
                $msg += ['type' => 'text', 'body' => implode("\n", $lines)];
            }
            break;
        case 'image':
        case 'video':
        case 'document':
        case 'audio':
            $url = trim((string) ($in['media_url'] ?? ''));
            // our own upload (assets/uploads/whatsapp/…, http on a dev install) or a public https link — nothing else
            $own = absolute_url('assets/uploads/whatsapp/');
            if ($url === '' || mb_strlen($url) > 500 || !(str_starts_with($url, $own) || preg_match('#^https://[^\s/]+\.[^\s/]+/\S*$#i', $url))) {
                $errors['media_url'] = 'Upload a file first (WhatsApp needs a public https link).';
            } elseif (str_contains($url, '..')) {
                $errors['media_url'] = 'This file link is not valid.';
            }
            if (mb_strlen($body) > 1024) {
                $errors['body'] = 'A caption can be at most 1,024 characters.';
            }
            if ($type === 'audio' && $body !== '') {
                $body = ''; // WhatsApp audio messages have no caption
            }
            $msg += ['type' => $type, 'body' => $body !== '' ? $body : null, 'media_url' => $url];
            if (!empty($in['file_name'])) {
                $msg['file_name'] = mb_substr(basename(str_replace('\\', '/', (string) $in['file_name'])), 0, 190);
            }
            if (!empty($in['media_mime']) && preg_match('#^[a-z]+/[a-z0-9.+\-]{1,80}$#i', (string) $in['media_mime'])) {
                $msg['media_mime'] = strtolower((string) $in['media_mime']);
            }
            break;
        case 'interactive_button':
            $buttons = is_array($in['buttons'] ?? null) ? array_values($in['buttons']) : [];
            if ($body === '') {
                $errors['body'] = 'Type the message text shown above the buttons.';
            } elseif (mb_strlen($body) > 1024) {
                $errors['body'] = 'Button message text can be at most 1,024 characters.';
            }
            $clean = [];
            foreach ($buttons as $i => $b) {
                $title = trim((string) (is_array($b) ? ($b['title'] ?? '') : $b));
                if ($title === '') {
                    continue;
                }
                if (mb_strlen($title) > 20) {
                    $errors['buttons'] = 'Button titles can be at most 20 characters.';
                }
                $clean[] = ['id' => 'btn_' . ($i + 1) . '_' . substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($title)), '_'), 0, 40), 'title' => $title];
            }
            if (!$clean) {
                $errors['buttons'] = 'Add at least one button.';
            } elseif (count($clean) > 3) {
                $errors['buttons'] = 'WhatsApp allows at most 3 reply buttons.';
            }
            $titles = array_map(fn ($c) => mb_strtolower($c['title']), $clean);
            if (count($titles) !== count(array_unique($titles))) {
                $errors['buttons'] = 'Button titles must be different.';
            }
            // stored in the flattened shape the shared WaBubble renders; core converts it to Interakt's payload
            $interactive = ['buttons' => $clean];
            $footer = trim((string) ($in['footer'] ?? ''));
            if ($footer !== '') {
                if (mb_strlen($footer) > 60) {
                    $errors['footer'] = 'Footer can be at most 60 characters.';
                }
                $interactive['footer'] = $footer;
            }
            $msg += ['type' => 'interactive_button', 'body' => $body, 'interactive' => $interactive];
            break;
        case 'interactive_list':
            $button = trim((string) ($in['list_button'] ?? ''));
            $sections = is_array($in['sections'] ?? null) ? array_values($in['sections']) : [];
            if ($body === '') {
                $errors['body'] = 'Type the message text shown above the list.';
            } elseif (mb_strlen($body) > 4096) {
                $errors['body'] = 'List message text can be at most 4,096 characters.';
            }
            if ($button === '') {
                $errors['list_button'] = 'Enter the button label that opens the list.';
            } elseif (mb_strlen($button) > 20) {
                $errors['list_button'] = 'The list button label can be at most 20 characters.';
            }
            $cleanSections = [];
            $rowCount = 0;
            $rowIds = [];
            foreach ($sections as $si => $s) {
                if (!is_array($s)) {
                    continue;
                }
                $stitle = trim((string) ($s['title'] ?? ''));
                $rows = [];
                foreach ((is_array($s['rows'] ?? null) ? $s['rows'] : []) as $ri => $r) {
                    $rt = trim((string) ($r['title'] ?? ''));
                    if ($rt === '') {
                        continue;
                    }
                    $rd = trim((string) ($r['description'] ?? ''));
                    if (mb_strlen($rt) > 24) {
                        $errors['sections'] = 'Row titles can be at most 24 characters.';
                    }
                    if (mb_strlen($rd) > 72) {
                        $errors['sections'] = 'Row descriptions can be at most 72 characters.';
                    }
                    $rid = 'row_' . ($si + 1) . '_' . ($ri + 1);
                    $rowIds[] = $rid;
                    $row = ['id' => $rid, 'title' => $rt];
                    if ($rd !== '') {
                        $row['description'] = $rd;
                    }
                    $rows[] = $row;
                    $rowCount++;
                }
                if (!$rows) {
                    continue;
                }
                if (mb_strlen($stitle) > 24) {
                    $errors['sections'] = 'Section titles can be at most 24 characters.';
                }
                $cleanSections[] = ['title' => $stitle !== '' ? $stitle : 'Options', 'rows' => $rows];
            }
            if ($rowCount === 0) {
                $errors['sections'] = 'Add at least one list row.';
            } elseif ($rowCount > 10) {
                $errors['sections'] = 'WhatsApp lists can have at most 10 rows in total.';
            }
            $interactive = ['button' => $button, 'sections' => $cleanSections];
            $footer = trim((string) ($in['footer'] ?? ''));
            if ($footer !== '') {
                if (mb_strlen($footer) > 60) {
                    $errors['footer'] = 'Footer can be at most 60 characters.';
                }
                $interactive['footer'] = $footer;
            }
            $msg += ['type' => 'interactive_list', 'body' => $body, 'interactive' => $interactive];
            break;
        case 'template':
            $tplId = (int) ($in['template_id'] ?? 0);
            $tpl = $tplId ? wa_template_find($tplId) : null;
            if (!$tpl) {
                $errors['template_id'] = 'Choose a template.';
            } elseif (($tpl['status'] ?? '') !== 'approved') {
                $errors['template_id'] = 'Only approved templates can be sent.';
            }
            $clean = wa_inbox_clean_vars(is_array($in['vars'] ?? null) ? $in['vars'] : []);
            if ($tpl && !$errors && in_array($tpl['header_type'] ?? 'none', ['image', 'video', 'document'], true)) {
                $hdr = is_array($clean['header'] ?? null) ? (string) ($clean['header'][0] ?? '') : '';
                if ($hdr !== '' && !(str_starts_with($hdr, absolute_url('assets/uploads/whatsapp/')) || preg_match('#^https://[^\s/]+\.[^\s/]+/\S*$#i', $hdr))) {
                    $errors['header_media'] = 'Use an uploaded file or a public https link for the header.';
                }
                if (isset($clean['file_name'])) {
                    $clean['file_name'] = mb_substr(basename(str_replace('\\', '/', (string) $clean['file_name'])), 0, 190);
                }
            }
            if ($tpl && !$errors) {
                $render = wa_render_template($tpl, $clean);
                foreach ((array) ($render['missing'] ?? []) as $miss) {
                    $errors['vars.' . $miss] = 'Required.';
                }
                if (!empty($render['missing'])) {
                    $errors['vars'] = 'Fill every template variable before sending.';
                }
            }
            $msg += ['type' => 'template', 'template' => $tplId, 'vars' => $clean];
            break;
        default:
            $errors['type'] = 'Unsupported message type.';
    }
    return [$msg, $errors];
}

/**
 * Agent sends a message from the inbox. Returns ['ok', 'message' => formatted row, 'error', 'code', 'errors'].
 * Side effects: auto-claims an unassigned conversation, reopens a resolved one, pauses the bot (human took over).
 */
function wa_inbox_send(array $conv, array $in): array
{
    [$msg, $errors] = wa_inbox_build_message($conv, $in);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'error' => 'Please fix the highlighted fields and try again.', 'code' => 'validation'];
    }
    $opts = ['priority' => 1];
    if (!empty($in['client_ref'])) {
        $opts['dedupe_key'] = 'inbox:' . (int) user_id() . ':' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $in['client_ref']), 0, 60);
    }
    $res = wa_send($msg, $opts);
    if (empty($res['ok']) || empty($res['message_id'])) {
        return ['ok' => false, 'error' => wa_inbox_refusal($res, wa_contact((int) $conv['contact_id'])), 'code' => $res['code'] ?? 'failed'];
    }
    $messageId = (int) $res['message_id'];
    if (($res['code'] ?? null) === 'duplicate') {
        // the browser re-sent the same client_ref (network retry): the message is already queued — no second log / claim
        return ['ok' => true, 'message' => wa_inbox_message($messageId), 'status' => $res['status'] ?? 'queued'];
    }
    $uid = (int) user_id();
    $convId = (int) $conv['id'];
    $contactId = (int) $conv['contact_id'];
    $isTemplate = $msg['type'] === 'template';
    $kind = (string) ($in['type'] ?? 'text');
    $detail = $isTemplate
        ? 'Sent template ' . ((wa_template_find((int) $msg['template'])['name'] ?? '') ?: '#' . $msg['template'])
        : ($kind === 'location' ? 'Shared a location' : ($kind === 'contact' ? 'Shared a contact card' : 'Replied (' . str_replace('_', ' ', $msg['type']) . ')'));
    wa_agent_log($isTemplate ? 'template' : 'reply', $convId, $contactId, mb_substr($detail, 0, 255));

    // first reply claims an unassigned chat — atomically, so two agents replying at once cannot both claim it
    if (empty($conv['assigned_to'])
        && db_exec('UPDATE wa_conversations SET assigned_to = ? WHERE id = ? AND assigned_to IS NULL', [$uid, $convId]) > 0) {
        wa_agent_log('assign', $convId, $contactId, 'Auto-assigned to ' . (current_user()['name'] ?? 'agent') . ' on first reply');
    }
    $updates = (int) ($conv['unread_count'] ?? 0) > 0 ? ['unread_count' => 0] : [];
    if (($conv['status'] ?? 'open') === 'resolved') {
        $updates['status'] = 'open';
        $updates['resolved_at'] = null;
        $updates['resolved_by'] = null;
        wa_agent_log('reopen', $convId, $contactId, 'Reopened by an agent reply');
    }
    if (!empty($conv['bot_active'])) {
        // bot_handover_at is left alone on purpose: it means "the bot handed over" and drives the admin part's
        // "Chat handed over by the AI bot" notification, which must not fire when an agent takes over by replying
        $updates['bot_active'] = 0;
        wa_agent_log('handover', $convId, $contactId, 'Agent took over from the AI bot');
        wa_inbox_bot_hook($contactId, false, 'Agent replied from the inbox');
    }
    if ($updates) {
        db_update('wa_conversations', $updates, 'id = ?', [$convId]);
    }
    return ['ok' => true, 'message' => wa_inbox_message($messageId), 'status' => $res['status'] ?? 'queued'];
}

/** Re-queue a failed outbound message (same row, so the bubble updates in place). */
function wa_inbox_retry(array $m, array $conv): array
{
    if ($m['direction'] !== 'out' || $m['status'] !== 'failed') {
        return ['ok' => false, 'error' => 'Only failed outgoing messages can be retried.'];
    }
    // core helper (same row re-queued so the bubble updates in place; checks window / template / opt-in; logs the retry)
    if (function_exists('wa_retry_message')) {
        $r = wa_retry_message((int) $m['id']);
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => $r['error'] ?? 'The message could not be queued again.', 'code' => $r['code'] ?? null];
        }
        return ['ok' => true, 'message' => wa_inbox_message((int) $m['id'])];
    }
    $contact = wa_contact((int) $m['contact_id']);
    if (!$contact) {
        return ['ok' => false, 'error' => 'The contact no longer exists.'];
    }
    if (($contact['status'] ?? 'active') === 'blocked') {
        return ['ok' => false, 'error' => 'This contact is blocked.'];
    }
    if ($m['type'] !== 'template' && !wa_contact_window_open($contact)) {
        return ['ok' => false, 'error' => 'The 24-hour window has closed, so this message can no longer be sent. Send an approved template instead.', 'code' => 'window_closed'];
    }
    if ($m['type'] === 'template') {
        $tpl = !empty($m['template_id']) ? wa_template_find((int) $m['template_id']) : null;
        if (!$tpl) {
            return ['ok' => false, 'error' => 'This template no longer exists. Send another approved template instead.', 'code' => 'template_not_found'];
        }
        if (wa_mode() === 'live' && ($tpl['status'] ?? '') !== 'approved') {
            return ['ok' => false, 'error' => 'This template is ' . ($tpl['status'] ?? 'not approved') . ' now, so it cannot be sent again.', 'code' => 'template_not_approved'];
        }
        if (($tpl['category'] ?? '') === 'MARKETING' && (int) ($contact['opt_in'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'The contact has not opted in to WhatsApp marketing messages, so this marketing template cannot be sent again.', 'code' => 'opted_out'];
        }
    }
    // fallback for an older core without wa_retry_message()
    $requeued = db_transaction(function () use ($m) {
        // guarded on status so a double click (or the admin retry queue) cannot queue it twice
        if (db_exec("UPDATE wa_messages SET status = 'queued', error = NULL, error_code = NULL, failed_at = NULL WHERE id = ? AND status = 'failed'", [(int) $m['id']]) < 1) {
            return false;
        }
        $box = db_row('SELECT id FROM wa_outbox WHERE message_id = ?', [(int) $m['id']]);
        if ($box) {
            db_exec("UPDATE wa_outbox SET status = 'pending', attempts = 0, scheduled_at = ?, locked_at = NULL, last_error = NULL, priority = 1 WHERE id = ?", [wa_now(), (int) $box['id']]);
        } else {
            db_insert('wa_outbox', ['message_id' => (int) $m['id'], 'priority' => 1, 'scheduled_at' => wa_now(), 'status' => 'pending']);
        }
        if (!empty($m['campaign_id'])) {
            // keep the campaign report in step (same as the admin retry queue)
            db_exec("UPDATE wa_campaign_recipients SET status = 'queued', error = NULL WHERE message_id = ? AND status = 'failed'", [(int) $m['id']]);
        }
        return true;
    });
    if (!$requeued) {
        return ['ok' => false, 'error' => 'This message is already queued again.'];
    }
    wa_agent_log('retry', (int) $conv['id'], (int) $conv['contact_id'], 'Retried failed message #' . (int) $m['id']);
    return ['ok' => true, 'message' => wa_inbox_message((int) $m['id'])];
}

function wa_inbox_mark_read(array $conv): void
{
    if ((int) $conv['unread_count'] > 0) {
        db_exec('UPDATE wa_conversations SET unread_count = 0 WHERE id = ?', [(int) $conv['id']]);
    }
}

/* ------------------------------------------------------------------------------------------------ team actions */

/** Users who can work the inbox (whatsapp view or super admin), active only. */
function wa_inbox_agent_ids(): array
{
    return array_map('intval', db_column(
        "SELECT DISTINCT u.id FROM users u
           JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id
           LEFT JOIN role_permissions rp ON rp.role_id = r.id
           LEFT JOIN permissions p ON p.id = rp.permission_id
          WHERE u.status = 'active' AND (r.is_super = 1 OR (p.module = 'whatsapp' AND p.action IN ('view','manage')))"
    ));
}

/** Agents with workload stats. */
function wa_inbox_agents(bool $withStats = true): array
{
    $ids = wa_inbox_agent_ids();
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $users = db_all("SELECT id, name, email, avatar, designation FROM users WHERE id IN ($in) ORDER BY name", $ids);
    $stats = [];
    if ($withStats) {
        foreach (db_all(
            "SELECT assigned_to AS uid, SUM(status <> 'resolved') AS open_assigned, SUM(status = 'pending') AS pending,
                    SUM(unread_count > 0 AND status <> 'resolved') AS unread
               FROM wa_conversations WHERE assigned_to IN ($in) GROUP BY assigned_to",
            $ids
        ) as $r) {
            $stats[(int) $r['uid']] = ['open_assigned' => (int) $r['open_assigned'], 'pending' => (int) $r['pending'], 'unread' => (int) $r['unread']];
        }
        foreach (db_all(
            "SELECT sent_by AS uid, COUNT(*) AS n FROM wa_messages
              WHERE sender_type = 'agent' AND direction = 'out' AND created_at >= CURDATE() AND sent_by IN ($in) GROUP BY sent_by",
            $ids
        ) as $r) {
            $stats[(int) $r['uid']]['replied_today'] = (int) $r['n'];
        }
        foreach (db_all(
            "SELECT uid, AVG(secs) AS avg_secs, COUNT(*) AS samples FROM (
                SELECT m.sent_by AS uid,
                       TIMESTAMPDIFF(SECOND, (SELECT MAX(i.created_at) FROM wa_messages i
                                               WHERE i.conversation_id = m.conversation_id AND i.direction = 'in' AND i.id < m.id), m.created_at) AS secs
                  FROM wa_messages m
                 WHERE m.sender_type = 'agent' AND m.direction = 'out' AND m.sent_by IN ($in)
                   AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                   AND (SELECT p.direction FROM wa_messages p WHERE p.conversation_id = m.conversation_id AND p.id < m.id ORDER BY p.id DESC LIMIT 1) = 'in'
             ) x WHERE secs IS NOT NULL AND secs >= 0 GROUP BY uid",
            $ids
        ) as $r) {
            $stats[(int) $r['uid']]['avg_first_response_secs'] = (int) round((float) $r['avg_secs']);
            $stats[(int) $r['uid']]['response_samples'] = (int) $r['samples'];
        }
        foreach (db_all(
            "SELECT user_id AS uid, MAX(created_at) AS last_at FROM wa_agent_activity WHERE user_id IN ($in) GROUP BY user_id",
            $ids
        ) as $r) {
            $stats[(int) $r['uid']]['last_active_at'] = $r['last_at'];
        }
    }
    $out = [];
    foreach ($users as $u) {
        $s = $stats[(int) $u['id']] ?? [];
        $out[] = [
            'id'                      => (int) $u['id'],
            'name'                    => $u['name'],
            'email'                   => $u['email'],
            'designation'             => $u['designation'],
            'avatar'                  => $u['avatar'] ?: null,
            'is_me'                   => (int) $u['id'] === (int) user_id(),
            'open_assigned'           => $s['open_assigned'] ?? 0,
            'pending'                 => $s['pending'] ?? 0,
            'unread'                  => $s['unread'] ?? 0,
            'replied_today'           => $s['replied_today'] ?? 0,
            'avg_first_response_secs' => $s['avg_first_response_secs'] ?? null,
            'response_samples'        => $s['response_samples'] ?? 0,
            'last_active_at'          => $s['last_active_at'] ?? null,
        ];
    }
    return $out;
}

/**
 * Assign (or unassign with null). Returns the updated summary, or null when $claimOnly and someone else claimed the
 * conversation first (the update is guarded on assigned_to IS NULL).
 */
function wa_inbox_assign(array $conv, ?int $userId, bool $claimOnly = false): ?array
{
    $convId = (int) $conv['id'];
    $contactId = (int) $conv['contact_id'];
    $old = $conv['assigned_to'] !== null ? (int) $conv['assigned_to'] : null;
    if ($old === $userId) {
        return wa_inbox_summary($convId);
    }
    if ($claimOnly) {
        if (db_exec('UPDATE wa_conversations SET assigned_to = ? WHERE id = ? AND assigned_to IS NULL', [$userId, $convId]) < 1) {
            return null;
        }
    } else {
        db_update('wa_conversations', ['assigned_to' => $userId], 'id = ?', [$convId]);
    }
    $who = $conv['name'] ?: wa_inbox_phone_display($conv['phone'] ?? '');
    if ($userId) {
        $agentName = db_value('SELECT name FROM users WHERE id = ?', [$userId]) ?: 'agent';
        $self = $userId === (int) user_id();
        wa_agent_log('assign', $convId, $contactId, $self ? ($old ? 'Took over the conversation' : 'Claimed the conversation') : 'Assigned to ' . $agentName);
        log_activity('assign', 'whatsapp', $convId, "Assigned WhatsApp conversation with $who to $agentName");
        // Settings › Notifications › "Conversation assigned" (admin part) can switch these off
        if (!$self && (!function_exists('wa_adm_notify_enabled') || wa_adm_notify_enabled('assignment'))) {
            notify($userId, 'whatsapp', 'WhatsApp chat assigned to you', (current_user()['name'] ?? 'A colleague') . " assigned the conversation with $who to you.", 'admin/whatsapp/inbox?c=' . $convId, 'message-circle');
        }
    } else {
        wa_agent_log('unassign', $convId, $contactId, 'Unassigned');
        log_activity('unassign', 'whatsapp', $convId, "Unassigned WhatsApp conversation with $who");
    }
    return wa_inbox_summary($convId);
}

function wa_inbox_set_status(array $conv, string $status): array
{
    $convId = (int) $conv['id'];
    $contactId = (int) $conv['contact_id'];
    if ($conv['status'] === $status) {
        return wa_inbox_summary($convId);
    }
    $data = ['status' => $status];
    if ($status === 'resolved') {
        $data['resolved_at'] = date('Y-m-d H:i:s');
        $data['resolved_by'] = user_id();
        $data['unread_count'] = 0;
    } else {
        $data['resolved_at'] = null;
        $data['resolved_by'] = null;
    }
    db_update('wa_conversations', $data, 'id = ?', [$convId]);
    $action = $status === 'resolved' ? 'resolve' : ($conv['status'] === 'resolved' ? 'reopen' : 'status');
    $label = ['open' => 'Open', 'pending' => 'Pending', 'resolved' => 'Resolved'][$status];
    wa_agent_log($action, $convId, $contactId, 'Marked ' . $label);
    $who = $conv['name'] ?: wa_inbox_phone_display($conv['phone'] ?? '');
    log_activity($action === 'status' ? 'update' : $action, 'whatsapp', $convId, "Marked WhatsApp conversation with $who as $label");
    return wa_inbox_summary($convId);
}

/** Bot on/off for the conversation (Take over / Hand back to bot). */
function wa_inbox_set_bot(array $conv, bool $active): array
{
    $convId = (int) $conv['id'];
    $contactId = (int) $conv['contact_id'];
    if ((bool) $conv['bot_active'] === $active) {
        return wa_inbox_summary($convId);
    }
    // bot_handover_at stays as it is on a manual take-over (it records the bot's own hand-over, see wa_inbox_send)
    db_update('wa_conversations', $active ? ['bot_active' => 1, 'bot_handover_at' => null] : ['bot_active' => 0], 'id = ?', [$convId]);
    $who = $conv['name'] ?: wa_inbox_phone_display($conv['phone'] ?? '');
    wa_agent_log('handover', $convId, $contactId, $active ? 'Handed the conversation back to the AI bot' : 'Took over from the AI bot');
    log_activity('update', 'whatsapp', $convId, ($active ? 'Handed back to the AI bot' : 'Took over from the AI bot') . ": WhatsApp conversation with $who");
    wa_inbox_bot_hook($contactId, $active, $active ? 'Handed back by an agent' : 'Agent took over from the inbox');
    return wa_inbox_summary($convId);
}

/** Let the bot part react to a hand-over when it exposes a hook (optional). */
function wa_inbox_bot_hook(int $contactId, bool $active, string $reason): void
{
    try {
        if (function_exists('wa_bot_set_handover')) {
            // a take-over only matters when the bot has a session with this contact (do not create empty "handed over" sessions)
            if (!$active && !db_value('SELECT id FROM wa_bot_sessions WHERE contact_id = ?', [$contactId])) {
                return;
            }
            wa_bot_set_handover($contactId, !$active, $reason);
        }
    } catch (Throwable $e) {
        error_log('[GIMT] wa_inbox bot hook: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------------------------------------ notes */

function wa_inbox_notes(int $contactId): array
{
    $rows = db_all(
        'SELECT n.id, n.body, n.created_at, n.user_id, n.conversation_id, u.name AS user_name, u.avatar AS user_avatar
           FROM wa_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.contact_id = ? ORDER BY n.id DESC LIMIT 200',
        [$contactId]
    );
    $uid = (int) user_id();
    $canDelete = can('whatsapp', 'delete');
    return array_map(fn ($n) => [
        'id'         => (int) $n['id'],
        'body'       => $n['body'],
        'created_at' => $n['created_at'],
        'user_id'    => $n['user_id'] !== null ? (int) $n['user_id'] : null,
        'user_name'  => $n['user_name'] ?? 'Former user',
        'user_avatar' => $n['user_avatar'] ?: null,
        'can_delete' => $canDelete || ($n['user_id'] !== null && (int) $n['user_id'] === $uid),
        'can_edit'   => $n['user_id'] !== null && (int) $n['user_id'] === $uid && can('whatsapp', 'create'),
    ], $rows);
}

function wa_inbox_add_note(array $conv, string $body): array
{
    $id = db_insert('wa_notes', [
        'conversation_id' => (int) $conv['id'], 'contact_id' => (int) $conv['contact_id'], 'user_id' => user_id(), 'body' => $body,
    ]);
    wa_agent_log('note', (int) $conv['id'], (int) $conv['contact_id'], mb_substr('Added a note: ' . preg_replace('/\s+/', ' ', $body), 0, 255));
    return $id ? ['id' => (int) $id] : [];
}

/* ------------------------------------------------------------------------------------------------ tags */

function wa_inbox_all_tags(): array
{
    return array_map(fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color'] ?: 'slate', 'contacts' => (int) $t['n']], db_all(
        'SELECT t.id, t.name, t.color, COUNT(ct.contact_id) AS n FROM wa_tags t LEFT JOIN wa_contact_tags ct ON ct.tag_id = t.id GROUP BY t.id, t.name, t.color ORDER BY t.name'
    ));
}

/** Add a tag (created when new, optional colour) to the conversation's contact. */
function wa_inbox_add_tag(array $conv, string $name, ?string $color = null): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name));
    $existing = db_row('SELECT id, color FROM wa_tags WHERE name = ?', [$name]);
    if (!$existing && $color && in_array($color, WA_INBOX_TAG_COLORS, true)) {
        db_exec('INSERT IGNORE INTO wa_tags (name, color) VALUES (?, ?)', [$name, $color]);
    }
    wa_contact_tag((int) $conv['contact_id'], [$name]);
    wa_agent_log('tag', (int) $conv['id'], (int) $conv['contact_id'], mb_substr('Tagged "' . $name . '"', 0, 255));
    return wa_inbox_tags_for_contacts([(int) $conv['contact_id']])[(int) $conv['contact_id']] ?? [];
}

function wa_inbox_remove_tag(array $conv, int $tagId): array
{
    $name = db_value('SELECT name FROM wa_tags WHERE id = ?', [$tagId]);
    if ($name) {
        wa_contact_untag((int) $conv['contact_id'], [$name]);
        wa_agent_log('untag', (int) $conv['id'], (int) $conv['contact_id'], mb_substr('Removed tag "' . $name . '"', 0, 255));
    }
    return wa_inbox_tags_for_contacts([(int) $conv['contact_id']])[(int) $conv['contact_id']] ?? [];
}

/* ------------------------------------------------------------------------------------------------ activity */

const WA_INBOX_ACTION_LABELS = [
    'reply' => 'Replied', 'template' => 'Sent template', 'assign' => 'Assigned', 'unassign' => 'Unassigned',
    'resolve' => 'Resolved', 'reopen' => 'Reopened', 'status' => 'Status changed', 'note' => 'Note', 'tag' => 'Tagged',
    'untag' => 'Tag removed', 'handover' => 'Bot hand-over', 'view' => 'Viewed', 'retry' => 'Retried',
    'stage' => 'Stage changed', 'opt_in' => 'Opted in', 'opt_out' => 'Opted out',
];

/** Conversation timeline: agent activity + key events (business events, stage changes, conversation start). */
function wa_inbox_activity(array $conv, int $limit = 60): array
{
    $items = [];
    foreach (db_all(
        'SELECT a.id, a.action, a.detail, a.created_at, u.name AS user_name FROM wa_agent_activity a
           LEFT JOIN users u ON u.id = a.user_id WHERE a.conversation_id = ? OR (a.conversation_id IS NULL AND a.contact_id = ?)
          ORDER BY a.id DESC LIMIT ' . (int) $limit,
        [(int) $conv['id'], (int) $conv['contact_id']]
    ) as $a) {
        $items[] = [
            'key' => 'a' . $a['id'], 'kind' => 'agent', 'action' => $a['action'],
            'title' => WA_INBOX_ACTION_LABELS[$a['action']] ?? ucfirst(str_replace('_', ' ', $a['action'])),
            'detail' => $a['detail'], 'user_name' => $a['user_name'] ?? ($a['action'] === 'handover' ? 'AI bot' : 'System'), 'at' => $a['created_at'],
        ];
    }
    foreach (db_all(
        "SELECT l.id, l.event_code, l.status, l.created_at, e.name AS event_name FROM wa_event_logs l
           LEFT JOIN wa_events e ON e.code = l.event_code WHERE l.contact_id = ? ORDER BY l.id DESC LIMIT 30",
        [(int) $conv['contact_id']]
    ) as $ev) {
        $items[] = [
            'key' => 'e' . $ev['id'], 'kind' => 'event', 'action' => 'event',
            'title' => $ev['event_name'] ?: ucwords(strtolower(str_replace(['GIMT_', 'WA_', '_'], ['', '', ' '], $ev['event_code']))),
            'detail' => $ev['event_code'] . ($ev['status'] !== 'processed' ? ' · ' . $ev['status'] : ''), 'user_name' => null, 'at' => $ev['created_at'],
        ];
    }
    $first = db_row('SELECT created_at, direction, sender_type FROM wa_messages WHERE conversation_id = ? ORDER BY id ASC LIMIT 1', [(int) $conv['id']]);
    if ($first) {
        $items[] = [
            'key' => 'start', 'kind' => 'system', 'action' => 'start', 'title' => 'Conversation started',
            'detail' => $first['direction'] === 'in' ? 'The contact messaged first' : 'First message sent by ' . str_replace('_', ' ', $first['sender_type']),
            'user_name' => null, 'at' => $first['created_at'],
        ];
    }
    usort($items, fn ($x, $y) => strcmp((string) $y['at'], (string) $x['at']));
    return array_slice($items, 0, $limit);
}

/** Team-wide (managers) or own agent activity feed. $opts: user_id, action, limit, before_id. */
function wa_inbox_activity_feed(array $opts = []): array
{
    $where = ['1=1'];
    $args = [];
    if (!wa_inbox_is_manager()) {
        $where[] = 'a.user_id = ?';
        $args[] = (int) user_id();
    } elseif (!empty($opts['user_id'])) {
        $where[] = 'a.user_id = ?';
        $args[] = (int) $opts['user_id'];
    }
    if (!empty($opts['action']) && isset(WA_INBOX_ACTION_LABELS[$opts['action']])) {
        $where[] = 'a.action = ?';
        $args[] = $opts['action'];
    }
    if (!empty($opts['before_id'])) {
        $where[] = 'a.id < ?';
        $args[] = (int) $opts['before_id'];
    }
    $limit = max(10, min(100, (int) ($opts['limit'] ?? 50)));
    $rows = db_all(
        'SELECT a.id, a.action, a.detail, a.created_at, a.conversation_id, a.contact_id, a.user_id, u.name AS user_name, u.avatar AS user_avatar,
                k.name AS contact_name, k.phone AS contact_phone
           FROM wa_agent_activity a
           LEFT JOIN users u ON u.id = a.user_id
           LEFT JOIN wa_contacts k ON k.id = a.contact_id
          WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT ' . ($limit + 1),
        $args
    );
    $hasMore = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    return [
        'rows' => array_map(fn ($a) => [
            'id' => (int) $a['id'], 'action' => $a['action'], 'title' => WA_INBOX_ACTION_LABELS[$a['action']] ?? ucfirst($a['action']),
            'detail' => $a['detail'], 'created_at' => $a['created_at'],
            'conversation_id' => $a['conversation_id'] !== null ? (int) $a['conversation_id'] : null,
            'contact_id' => $a['contact_id'] !== null ? (int) $a['contact_id'] : null,
            'contact_name' => $a['contact_name'] ?: ($a['contact_phone'] ? wa_inbox_phone_display($a['contact_phone']) : null),
            'user_id' => $a['user_id'] !== null ? (int) $a['user_id'] : null,
            'user_name' => $a['user_name'] ?? 'System',
            'user_avatar' => $a['user_avatar'] ?: null,
        ], $rows),
        'has_more' => $hasMore,
    ];
}

/* ------------------------------------------------------------------------------------------------ templates (picker) */

/** Approved templates with the contact's resolved variable values (for the template picker). */
function wa_inbox_templates_for(array $contactRow): array
{
    $rows = db_all(
        "SELECT * FROM wa_templates WHERE status = 'approved' ORDER BY (category = 'UTILITY') DESC, usage_count DESC, display_name, name LIMIT 300"
    );
    $catalog = function_exists('wa_variable_catalog') ? wa_variable_catalog() : [];
    $values = [];
    try {
        $values = wa_variables_for_contact($contactRow);
    } catch (Throwable $e) {
        error_log('[GIMT] wa_inbox templates vars: ' . $e->getMessage());
    }
    // core resolves a student's outstanding balance as due_amount; the fee templates map it as fee_amount
    if (empty($values['fee_amount']) && !empty($values['due_amount'])) {
        $values['fee_amount'] = $values['due_amount'];
    }
    $out = [];
    foreach ($rows as $t) {
        $vars = wa_inbox_json($t['variables']) ?? [];
        $fields = [];
        foreach (['header', 'body'] as $part) {
            foreach ((array) ($vars[$part] ?? []) as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $key = (string) ($v['key'] ?? '') !== '' ? (string) $v['key'] : $part . '_' . ($v['pos'] ?? count($fields) + 1);
                // one entry per slot: the same GIMT variable may fill the header and a body slot (the picker shows one input per key)
                $fields[$part . ':' . (int) ($v['pos'] ?? 0)] = [
                    'key'    => $key,
                    'part'   => $part,
                    'pos'    => (int) ($v['pos'] ?? 0),
                    'label'  => is_array($catalog[$key] ?? null) ? ($catalog[$key]['label'] ?? wa_inbox_label($key)) : (is_string($catalog[$key] ?? null) ? $catalog[$key] : wa_inbox_label($key)),
                    'sample' => $v['sample'] ?? '',
                    'value'  => isset($values[$key]) && is_scalar($values[$key]) ? (string) $values[$key] : '',
                ];
            }
        }
        foreach ((array) ($vars['buttons'] ?? []) as $idx => $v) {
            if (!is_array($v)) {
                continue;
            }
            $key = (string) ($v['key'] ?? '') !== '' ? (string) $v['key'] : 'button_' . $idx;
            $fields['button:' . (int) $idx] = [
                'key' => $key, 'part' => 'button', 'pos' => (int) $idx,
                'label' => is_array($catalog[$key] ?? null) ? ($catalog[$key]['label'] ?? wa_inbox_label($key)) : wa_inbox_label($key),
                'sample' => $v['sample'] ?? '',
                'value' => isset($values[$key]) && is_scalar($values[$key]) ? (string) $values[$key] : '',
            ];
        }
        $out[] = [
            'id'           => (int) $t['id'],
            'name'         => $t['name'],
            'display_name' => $t['display_name'] ?: $t['name'],
            'category'     => $t['category'],
            'language'     => $t['language'],
            'header_type'  => $t['header_type'],
            'header_text'  => $t['header_text'],
            'header_media_url' => $t['header_media_url'],
            'body'         => $t['body'],
            'footer'       => $t['footer'],
            'buttons'      => wa_inbox_json($t['buttons']) ?? [],
            'fields'       => array_values($fields),
            'usage_count'  => (int) $t['usage_count'],
        ];
    }
    return $out;
}

function wa_inbox_label(string $key): string
{
    return ucfirst(str_replace('_', ' ', $key));
}

/* ------------------------------------------------------------------------------------------------ quick replies */

function wa_inbox_quick_replies(string $q = '', string $category = ''): array
{
    $where = ['1=1'];
    $args = [];
    if (trim($q) !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim(ltrim($q, '/'))) . '%';
        $where[] = '(q.shortcut LIKE ? OR q.title LIKE ? OR q.body LIKE ?)';
        array_push($args, $like, $like, $like);
    }
    if ($category !== '') {
        $where[] = 'q.category = ?';
        $args[] = $category;
    }
    return array_map(fn ($r) => [
        'id' => (int) $r['id'], 'shortcut' => $r['shortcut'], 'title' => $r['title'], 'body' => $r['body'],
        'category' => $r['category'], 'usage_count' => (int) $r['usage_count'], 'created_by_name' => $r['created_by_name'],
        'updated_at' => $r['updated_at'] ?: $r['created_at'],
    ], db_all(
        'SELECT q.*, u.name AS created_by_name FROM wa_quick_replies q LEFT JOIN users u ON u.id = q.created_by
          WHERE ' . implode(' AND ', $where) . ' ORDER BY q.usage_count DESC, q.shortcut LIMIT 300',
        $args
    ));
}

/** Normalise a shortcut: lowercase letters, digits, - and _ (no leading slash). */
function wa_inbox_shortcut(string $s): string
{
    return substr(trim(preg_replace('/[^a-z0-9_-]+/', '-', strtolower(ltrim(trim($s), '/'))), '-'), 0, 40);
}

/**
 * Fill a quick reply body with contact values: {{name}}, {{first_name}}, {{program}}, {{application_no}}, {{agent}}.
 * Unknown placeholders are left as typed.
 */
function wa_inbox_fill_quick_reply(string $body, ?array $profile): string
{
    $name = trim((string) ($profile['name'] ?? ''));
    $map = [
        'name' => $name !== '' ? $name : 'there',
        'first_name' => $name !== '' ? explode(' ', $name)[0] : 'there',
        'program' => (string) ($profile['program_name'] ?? ''),
        'application_no' => (string) ($profile['application_no'] ?? ''),
        'agent' => (string) (current_user()['name'] ?? 'GIMT Admissions'),
        'institute' => (string) (wa_setting('wa_business_name', null) ?: setting('site_name', 'GIMT')),
    ];
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => array_key_exists($m[1], $map) && $map[$m[1]] !== '' ? $map[$m[1]] : $m[0], $body);
}
