<?php
/**
 * WhatsApp contact CRM, admission leads and the follow-up engine (contacts part of docs/WHATSAPP.md).
 *
 *  - Contacts: filtered/paginated lists, facets, add/edit with phone normalisation + duplicate check, bulk actions,
 *    CSV/XLSX import (upload → mapping → preview → run) and export, saved segments (wa_segments).
 *  - Sync with the admission portal: wa_crm_sync() builds one record per phone from enquiries, applications, students
 *    and their guardians (richest data wins, stage derived from the ERP status), incremental tick every 15 minutes and
 *    GIMT_* event listeners for immediate upserts. Duplicate report + merge.
 *  - CRM → admission portal: wa_crm_create_enquiry() turns a WhatsApp lead into an ERP enquiry (source whatsapp) or
 *    links the open one; staff stage moves are written back to the linked enquiry (wa_crm_enquiry_writeback()).
 *  - Admission leads: 9-stage kanban board, funnel statistics, stage history (wa_contact_stage_history).
 *  - Follow-ups: per-stage configuration (wa_followup_stages), scheduling on WA_STAGE_CHANGED, the tick handler that
 *    sends due follow-ups through wa_send_template(), stop conditions (reply, stage change, opt-out, admission confirmed).
 *
 * Loaded by app/services/whatsapp.php (core): this file only defines functions and registers hooks at include time.
 * Prefix: wa_crm_.
 */

/* ============================================================================================ constants / meta */

/** The 9 admission stages (key => name, badge colour, funnel rank; not_interested is outside the funnel). */
function wa_crm_stage_defaults(): array
{
    return [
        'new_enquiry' => ['name' => 'New Enquiry', 'color' => 'blue', 'rank' => 1],
        'contacted' => ['name' => 'Contacted', 'color' => 'cyan', 'rank' => 2],
        'interested' => ['name' => 'Interested', 'color' => 'purple', 'rank' => 3],
        'counselling_scheduled' => ['name' => 'Counselling Scheduled', 'color' => 'navy', 'rank' => 4],
        'application_started' => ['name' => 'Application Started', 'color' => 'amber', 'rank' => 5],
        'application_submitted' => ['name' => 'Application Submitted', 'color' => 'amber', 'rank' => 6],
        'payment_pending' => ['name' => 'Payment Pending', 'color' => 'red', 'rank' => 7],
        'admission_confirmed' => ['name' => 'Admission Confirmed', 'color' => 'green', 'rank' => 8],
        'not_interested' => ['name' => 'Not Interested', 'color' => 'slate', 'rank' => 0],
    ];
}

/** Stage list merged with the configured names/colours (wa_followup_stages). Cached per request. */
function wa_crm_stages(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $stages = wa_crm_stage_defaults();
    try {
        foreach (db_all('SELECT * FROM wa_followup_stages ORDER BY sort_order, id') as $r) {
            if (!isset($stages[$r['stage_key']])) {
                continue;
            }
            $stages[$r['stage_key']]['name'] = $r['name'] ?: $stages[$r['stage_key']]['name'];
            $stages[$r['stage_key']]['color'] = $r['color'] ?: $stages[$r['stage_key']]['color'];
            $stages[$r['stage_key']]['config'] = $r;
        }
    } catch (Throwable $e) {
        // table missing on a partial install: defaults only
    }
    return $cache = $stages;
}

function wa_crm_stage_name(?string $key): string
{
    $s = wa_crm_stages();
    return $key && isset($s[$key]) ? $s[$key]['name'] : label_from_key((string) $key);
}

function wa_crm_stage_rank(?string $key): int
{
    return (int) (wa_crm_stage_defaults()[$key ?? '']['rank'] ?? 0);
}

function wa_crm_terminal_stages(): array
{
    return ['admission_confirmed', 'not_interested'];
}

function wa_crm_contact_types(): array
{
    return ['lead' => 'Lead', 'applicant' => 'Applicant', 'student' => 'Student', 'guardian' => 'Parent / Guardian', 'alumni' => 'Alumni', 'staff' => 'Staff', 'other' => 'Other'];
}

/** Type precedence used when the sync meets an existing contact: never downgrade (student stays student). */
function wa_crm_type_rank(?string $type): int
{
    return ['other' => 0, 'guardian' => 1, 'lead' => 2, 'applicant' => 3, 'student' => 4, 'alumni' => 5, 'staff' => 6][$type ?? 'other'] ?? 0;
}

function wa_crm_payment_statuses(): array
{
    return ['paid' => 'Paid', 'partial' => 'Partially paid', 'pending' => 'Pending', 'overdue' => 'Overdue'];
}

function wa_crm_tag_colors(): array
{
    return ['blue', 'green', 'amber', 'red', 'purple', 'cyan', 'slate', 'navy'];
}

/** Suppress notifications (bulk sync / seeding). Returns the previous value. */
function wa_crm_quiet(?bool $set = null): bool
{
    static $quiet = false;
    $prev = $quiet;
    if ($set !== null) {
        $quiet = $set;
    }
    return $prev;
}

/** List-valued request parameter: accepts an array or a comma-separated string; drops blanks. */
function wa_crm_list($v): array
{
    if ($v === null || $v === '') {
        return [];
    }
    $items = is_array($v) ? $v : explode(',', (string) $v);
    $out = [];
    foreach ($items as $i) {
        if (is_array($i)) {
            continue;
        }
        $i = trim((string) $i);
        if ($i !== '') {
            $out[] = $i;
        }
    }
    return array_values(array_unique($out));
}

function wa_crm_now(): string
{
    return date('Y-m-d H:i:s');
}

/** Phone → ['phone','country_code','national'] using the core normaliser. */
function wa_crm_phone(?string $raw): ?array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    return wa_normalize_phone($raw);
}

/** Settings › WhatsApp › Security "mask phone numbers" applies to the current user (admin part; managers see numbers). */
function wa_crm_masking(): bool
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

/**
 * Contact export allowed: the role's whatsapp_contacts export permission plus Settings › WhatsApp › Security "who can
 * export" (admin part) — with "managers only", users without WhatsApp settings manage cannot export.
 */
function wa_crm_can_export(): bool
{
    if (!can('whatsapp_contacts', 'export')) {
        return false;
    }
    try {
        if (function_exists('wa_adm_setting') && (string) wa_adm_setting('wa_export_scope') === 'managers') {
            return can('whatsapp_settings', 'manage');
        }
    } catch (Throwable $e) {
    }
    return true;
}

/** A phone for display / export: '+91 98•••••210' when masking applies, unchanged otherwise. */
function wa_crm_show_phone(?string $phone): ?string
{
    return $phone !== null && $phone !== '' && !str_contains($phone, '•') && wa_crm_masking() ? wa_adm_mask_phone($phone) : $phone;
}

function wa_crm_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $k = $table . '.' . $column;
    if (!array_key_exists($k, $cache)) {
        try {
            $cache[$k] = in_array($column, db_table_columns($table), true);
        } catch (Throwable $e) {
            $cache[$k] = false;
        }
    }
    return $cache[$k];
}

/* ============================================================================================ contact filters */

/**
 * WHERE clause for a contact filter set (used by the list, export, bulk "all matching", segments and campaigns).
 * Keys: q, scope (leads), type[], program_id[], stage[], admission_status[], payment_status[], city[], state[], batch[],
 * counsellor_id[] ('none' = unassigned), tag[] (+ tag_mode any|all), opt_in (1|0), status[] (default: not archived),
 * window (open|closed), last_from, last_to, created_from, created_to, source[], lead_source[], has_followup (1|0),
 * segment (id → the saved filters AND the explicit ones: a segment of type=guardian plus type=student matches nobody,
 * it never widens to every student), ids[].
 * $defaultStatus: add "not archived" when no status[] is given (internal — off for the explicit half of a segment query).
 */
function wa_crm_filter_where(array $f, array &$args, bool $defaultStatus = true): string
{
    if (array_key_exists('segment', $f)) {
        $segId = is_scalar($f['segment']) ? trim((string) $f['segment']) : '';
        unset($f['segment']);
        $seg = $segId !== '' && ctype_digit($segId) ? wa_crm_segment((int) $segId) : null;
        if ($seg) {
            $segF = is_array($seg['filters'] ?? null) ? $seg['filters'] : [];
            unset($segF['segment']);
            // Each half is evaluated with its own keys (incl. its own tag_mode) and the two are ANDed. The default
            // "not archived" is added once, and only when neither half names a status (a segment of archived contacts
            // keeps working; an explicit status narrows the segment's).
            $ownStatus = wa_crm_list($f['status'] ?? null) !== [];
            $segSql = wa_crm_filter_where($segF, $args, $defaultStatus && !$ownStatus);
            $ownSql = wa_crm_filter_where($f, $args, false);
            return '(' . $segSql . ') AND (' . $ownSql . ')';
        }
    }
    $w = ['1=1'];
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $parts = ['c.name LIKE ?', 'c.email LIKE ?', 'c.application_no LIKE ?'];
        array_push($args, $like, $like, $like);
        $digits = preg_replace('/\D+/', '', $q);
        if (strlen($digits) >= 4) {
            $parts[] = 'c.phone LIKE ?';
            $parts[] = 'c.alt_phone LIKE ?';
            $args[] = '%' . $digits . '%';
            $args[] = '%' . $digits . '%';
        }
        $w[] = '(' . implode(' OR ', $parts) . ')';
    }
    if (($f['scope'] ?? '') === 'leads') {
        $w[] = wa_crm_leads_scope_sql();
    }
    $in = function (string $col, array $vals) use (&$w, &$args) {
        if (!$vals) {
            return;
        }
        $w[] = $col . ' IN (' . implode(',', array_fill(0, count($vals), '?')) . ')';
        array_push($args, ...$vals);
    };
    $in('c.contact_type', wa_crm_list($f['type'] ?? null));
    $in('c.program_id', array_map('intval', array_filter(wa_crm_list($f['program_id'] ?? null), 'ctype_digit')));
    $in('c.stage', wa_crm_list($f['stage'] ?? null));
    $in('c.admission_status', wa_crm_list($f['admission_status'] ?? null));
    $in('c.payment_status', wa_crm_list($f['payment_status'] ?? null));
    $in('c.city', wa_crm_list($f['city'] ?? null));
    $in('c.state', wa_crm_list($f['state'] ?? null));
    $in('c.batch', wa_crm_list($f['batch'] ?? null));
    $in('c.source', wa_crm_list($f['source'] ?? null));
    $in('c.lead_source', wa_crm_list($f['lead_source'] ?? null));
    $in('c.language', wa_crm_list($f['language'] ?? null));
    $in('c.id', array_map('intval', array_filter(wa_crm_list($f['ids'] ?? null), 'ctype_digit')));

    $counsellors = wa_crm_list($f['counsellor_id'] ?? null);
    if ($counsellors) {
        $ids = array_map('intval', array_filter($counsellors, 'ctype_digit'));
        $or = [];
        if (in_array('none', $counsellors, true)) {
            $or[] = 'c.counsellor_id IS NULL';
        }
        if (in_array('me', $counsellors, true) && wa_user_id()) {
            $ids[] = (int) wa_user_id();
        }
        if ($ids) {
            $or[] = 'c.counsellor_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($args, ...$ids);
        }
        if ($or) {
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
    }
    $statuses = wa_crm_list($f['status'] ?? null);
    if ($statuses) {
        $in('c.status', $statuses);
    } elseif ($defaultStatus) {
        $w[] = "c.status <> 'archived'";
    }
    $tags = array_map('intval', array_filter(wa_crm_list($f['tag'] ?? null), 'ctype_digit'));
    if ($tags) {
        $ph = implode(',', array_fill(0, count($tags), '?'));
        if (($f['tag_mode'] ?? 'any') === 'all') {
            $w[] = "(SELECT COUNT(DISTINCT ct.tag_id) FROM wa_contact_tags ct WHERE ct.contact_id = c.id AND ct.tag_id IN ($ph)) = " . count($tags);
        } else {
            $w[] = "EXISTS (SELECT 1 FROM wa_contact_tags ct WHERE ct.contact_id = c.id AND ct.tag_id IN ($ph))";
        }
        array_push($args, ...$tags);
    }
    $opt = (string) ($f['opt_in'] ?? '');
    if ($opt === '1' || $opt === '0') {
        $w[] = 'c.opt_in = ?';
        $args[] = (int) $opt;
    }
    $win = (string) ($f['window'] ?? '');
    if ($win === 'open') {
        $w[] = 'c.last_inbound_at >= ?';
        $args[] = date('Y-m-d H:i:s', time() - 86400);
    } elseif ($win === 'closed') {
        $w[] = '(c.last_inbound_at IS NULL OR c.last_inbound_at < ?)';
        $args[] = date('Y-m-d H:i:s', time() - 86400);
    }
    foreach (['last_from' => ['c.last_message_at', '>=', ' 00:00:00'], 'last_to' => ['c.last_message_at', '<=', ' 23:59:59'], 'created_from' => ['c.created_at', '>=', ' 00:00:00'], 'created_to' => ['c.created_at', '<=', ' 23:59:59']] as $k => [$col, $op, $suffix]) {
        $d = (string) ($f[$k] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $w[] = "$col $op ?";
            $args[] = $d . $suffix;
        }
    }
    $hf = (string) ($f['has_followup'] ?? '');
    if ($hf === '1' || $hf === '0') {
        $w[] = ($hf === '1' ? '' : 'NOT ') . "EXISTS (SELECT 1 FROM wa_followups fu WHERE fu.contact_id = c.id AND fu.status = 'scheduled')";
    }
    return implode(' AND ', $w);
}

/** Contacts that belong on the admission-leads board: leads/applicants plus students admitted in the last year. */
function wa_crm_leads_scope_sql(): string
{
    return "(c.contact_type IN ('lead','applicant') OR (c.contact_type = 'student' AND (c.admission_id IS NOT NULL OR c.enquiry_id IS NOT NULL) AND c.stage_changed_at >= '" . date('Y-m-d', strtotime('-365 days')) . "'))";
}

/** Sort keys allowed on the contacts list. */
function wa_crm_sort_sql(string $sort, string $dir): string
{
    $map = [
        'name' => 'c.name', 'phone' => 'c.phone', 'contact_type' => 'c.contact_type', 'stage' => 'c.stage', 'program_name' => 'c.program_name',
        'city' => 'c.city', 'state' => 'c.state', 'batch' => 'c.batch', 'counsellor_name' => 'u.name', 'last_message_at' => 'c.last_message_at',
        'created_at' => 'c.created_at', 'opt_in' => 'c.opt_in', 'payment_status' => 'c.payment_status', 'admission_status' => 'c.admission_status',
        'stage_changed_at' => 'c.stage_changed_at',
    ];
    $col = $map[$sort] ?? 'c.last_message_at';
    $d = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
    // NULLs last for dates in DESC order
    return ($d === 'DESC' ? "$col IS NULL, " : '') . "$col $d, c.id DESC";
}

function wa_crm_list_select(): string
{
    return "c.id, c.phone, c.country_code, c.national_number, c.name, c.email, c.alt_phone, c.contact_type, c.student_id, c.enquiry_id,
            c.admission_id, c.application_no, c.program_id, c.program_name, p.code AS program_code, c.batch, c.city, c.state, c.stage,
            c.stage_changed_at, c.admission_status, c.payment_status, c.counsellor_id, u.name AS counsellor_name, c.language, c.opt_in,
            c.opt_in_at, c.opt_in_source, c.opted_out_at, c.source, c.lead_source, c.status, c.last_inbound_at, c.last_outbound_at,
            c.last_message_at, c.created_at, c.updated_at, c.synced_at, cv.id AS conversation_id, cv.unread_count,
            (SELECT MIN(fu.due_at) FROM wa_followups fu WHERE fu.contact_id = c.id AND fu.status = 'scheduled') AS next_followup_at";
}

function wa_crm_list_from(): string
{
    return 'wa_contacts c LEFT JOIN users u ON u.id = c.counsellor_id LEFT JOIN programs p ON p.id = c.program_id LEFT JOIN wa_conversations cv ON cv.contact_id = c.id';
}

/** Attach tags + derived flags to contact rows. */
function wa_crm_decorate(array $rows): array
{
    if (!$rows) {
        return $rows;
    }
    $ids = array_map(fn ($r) => (int) $r['id'], $rows);
    $tags = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        foreach (db_all("SELECT ct.contact_id, t.id, t.name, t.color FROM wa_contact_tags ct JOIN wa_tags t ON t.id = ct.tag_id WHERE ct.contact_id IN ($ph) ORDER BY t.name", $chunk) as $t) {
            $tags[(int) $t['contact_id']][] = ['id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color'] ?: 'slate'];
        }
    }
    $cut = time() - 86400;
    $mask = wa_crm_masking();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['opt_in'] = (int) $r['opt_in'];
        if ($mask) {
            foreach (['phone', 'alt_phone', 'national_number'] as $k) {
                if (!empty($r[$k])) {
                    $r[$k] = wa_adm_mask_phone((string) $r[$k]);
                }
            }
            $r['phone_masked'] = true;
        }
        $r['tags'] = $tags[$r['id']] ?? [];
        $r['window_open'] = !empty($r['last_inbound_at']) && strtotime($r['last_inbound_at']) >= $cut;
        $r['window_expires_at'] = $r['window_open'] ? date('Y-m-d H:i:s', strtotime($r['last_inbound_at']) + 86400) : null;
        $r['stage_name'] = wa_crm_stage_name($r['stage'] ?? null);
        foreach (['student_id', 'enquiry_id', 'admission_id', 'program_id', 'counsellor_id', 'conversation_id', 'unread_count'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
        }
    }
    unset($r);
    return $rows;
}

/** Paginated contact list. */
function wa_crm_contacts(array $f, int $page = 1, int $perPage = 25): array
{
    $args = [];
    $where = wa_crm_filter_where($f, $args);
    $from = wa_crm_list_from();
    $total = (int) db_value("SELECT COUNT(*) FROM wa_contacts c WHERE $where", $args);
    $pg = paginate($total, $page, $perPage);
    $order = wa_crm_sort_sql((string) ($f['sort'] ?? 'last_message_at'), (string) ($f['dir'] ?? 'desc'));
    $rows = db_all('SELECT ' . wa_crm_list_select() . " FROM $from WHERE $where ORDER BY $order LIMIT " . (int) $pg['per_page'] . ' OFFSET ' . (int) (($pg['page'] - 1) * $pg['per_page']), $args);
    return ['data' => wa_crm_decorate($rows), 'meta' => $pg];
}

/** Ids of every contact matching a filter set (bulk "select all matching", campaigns). */
function wa_crm_contact_ids(array $f, int $limit = 20000): array
{
    $args = [];
    $where = wa_crm_filter_where($f, $args);
    return array_map('intval', db_column("SELECT c.id FROM wa_contacts c WHERE $where ORDER BY c.id LIMIT " . max(1, $limit), $args));
}

/** Count + opted-in count for a filter set. */
function wa_crm_count(array $f): array
{
    $args = [];
    $where = wa_crm_filter_where($f, $args);
    $r = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(c.opt_in = 1), 0) AS opted_in FROM wa_contacts c WHERE $where", $args);
    return ['total' => (int) ($r['total'] ?? 0), 'opted_in' => (int) ($r['opted_in'] ?? 0)];
}

/** Filter options for the contacts / leads screens. */
function wa_crm_facets(): array
{
    $distinct = fn (string $col) => db_column("SELECT DISTINCT $col FROM wa_contacts WHERE $col IS NOT NULL AND $col <> '' ORDER BY $col LIMIT 300");
    $opts = fn (array $vals, ?callable $label = null) => array_map(fn ($v) => ['value' => $v, 'label' => $label ? $label($v) : $v], $vals);
    $stages = [];
    foreach (wa_crm_stages() as $k => $s) {
        $stages[] = ['value' => $k, 'label' => $s['name'], 'color' => $s['color']];
    }
    return [
        'types' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(wa_crm_contact_types()), wa_crm_contact_types()),
        'stages' => $stages,
        'programs' => array_map(fn ($r) => ['value' => (int) $r['id'], 'label' => $r['name'], 'sub' => $r['code']], db_all("SELECT id, name, code FROM programs ORDER BY name")),
        'cities' => $opts($distinct('city')),
        'states' => $opts($distinct('state')),
        'batches' => $opts($distinct('batch')),
        'admission_statuses' => $opts($distinct('admission_status'), 'label_from_key'),
        'payment_statuses' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(wa_crm_payment_statuses()), wa_crm_payment_statuses()),
        'sources' => $opts($distinct('source'), 'label_from_key'),
        'lead_sources' => $opts($distinct('lead_source'), 'label_from_key'),
        'counsellors' => wa_crm_counsellors(),
        'tags' => wa_crm_tags(),
        'tag_colors' => wa_crm_tag_colors(),
        'xlsx' => class_exists('ZipArchive'),
        'can_export' => wa_crm_can_export(),
        'phones_masked' => wa_crm_masking(),
    ];
}

/** Users who can own leads: super admins and roles with enquiries/admissions/whatsapp_contacts edit or manage. */
function wa_crm_counsellors(): array
{
    $rows = db_all(
        "SELECT DISTINCT u.id, u.name, u.designation,
                (SELECT COUNT(*) FROM wa_contacts c WHERE c.counsellor_id = u.id AND c.status <> 'archived') AS contacts
         FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id
         LEFT JOIN role_permissions rp ON rp.role_id = r.id
         LEFT JOIN permissions p ON p.id = rp.permission_id
         WHERE u.status = 'active' AND (r.is_super = 1 OR (p.module IN ('enquiries','admissions','whatsapp_contacts') AND p.action IN ('edit','manage')))
         ORDER BY u.name"
    );
    return array_map(fn ($r) => ['value' => (int) $r['id'], 'label' => $r['name'], 'sub' => $r['designation'], 'contacts' => (int) $r['contacts']], $rows);
}

/** KPI strip for the contacts page. */
function wa_crm_summary(): array
{
    $cut = date('Y-m-d H:i:s', time() - 86400);
    $r = db_row(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(opt_in = 1), 0) AS opted_in,
                COALESCE(SUM(opted_out_at IS NOT NULL AND opt_in = 0), 0) AS opted_out,
                COALESCE(SUM(last_inbound_at >= ?), 0) AS window_open,
                COALESCE(SUM(contact_type IN ('lead','applicant')), 0) AS leads,
                COALESCE(SUM(contact_type = 'student'), 0) AS students,
                COALESCE(SUM(contact_type = 'guardian'), 0) AS guardians,
                COALESCE(SUM(counsellor_id IS NULL AND contact_type IN ('lead','applicant')), 0) AS unassigned_leads,
                COALESCE(SUM(created_at >= ?), 0) AS new_7d
         FROM wa_contacts WHERE status <> 'archived'",
        [$cut, date('Y-m-d 00:00:00', strtotime('-6 days'))]
    ) ?: [];
    $out = array_map('intval', $r);
    $out['last_sync_at'] = setting('wa_crm_sync_last');
    $stats = json_decode((string) setting('wa_crm_sync_stats', ''), true);
    $out['last_sync'] = is_array($stats) ? $stats : null;
    return $out;
}

/* ============================================================================================ tags */

function wa_crm_tags(): array
{
    return array_map(fn ($r) => ['value' => (int) $r['id'], 'id' => (int) $r['id'], 'label' => $r['name'], 'name' => $r['name'], 'color' => $r['color'] ?: 'slate', 'contacts' => (int) $r['contacts']], db_all(
        'SELECT t.id, t.name, t.color, (SELECT COUNT(*) FROM wa_contact_tags ct WHERE ct.tag_id = t.id) AS contacts FROM wa_tags t ORDER BY t.name'
    ));
}

function wa_crm_tag_save(array $in, ?int $id = null): int
{
    $name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')));
    $color = in_array($in['color'] ?? '', wa_crm_tag_colors(), true) ? $in['color'] : 'slate';
    if ($name === '' || mb_strlen($name) > 60) {
        throw new CrudValidationException(['name' => 'Enter a tag name (up to 60 characters).']);
    }
    $dup = db_value('SELECT id FROM wa_tags WHERE name = ? AND id <> ?', [$name, (int) $id]);
    if ($dup) {
        throw new CrudValidationException(['name' => 'A tag with this name already exists.']);
    }
    if ($id) {
        db_update('wa_tags', ['name' => $name, 'color' => $color], 'id = ?', [$id]);
        return $id;
    }
    return db_insert('wa_tags', ['name' => $name, 'color' => $color]);
}

/* ============================================================================================ segments */

function wa_crm_segment_row(array $r): array
{
    $r['id'] = (int) $r['id'];
    $r['filters'] = json_decode((string) $r['filters'], true) ?: [];
    $r['contacts_count'] = (int) $r['contacts_count'];
    $r['opted_in_count'] = (int) $r['opted_in_count'];
    return $r;
}

function wa_crm_segment(int $id): ?array
{
    $r = db_row('SELECT s.*, u.name AS created_by_name FROM wa_segments s LEFT JOIN users u ON u.id = s.created_by WHERE s.id = ?', [$id]);
    return $r ? wa_crm_segment_row($r) : null;
}

/** Saved segments (named filter sets) — campaigns use wa_crm_segment_filters() / wa_crm_contact_ids(['segment' => id]). */
function wa_crm_segments(): array
{
    return array_map('wa_crm_segment_row', db_all('SELECT s.*, u.name AS created_by_name FROM wa_segments s LEFT JOIN users u ON u.id = s.created_by ORDER BY s.name'));
}

function wa_crm_segment_filters(int $id): array
{
    return wa_crm_segment($id)['filters'] ?? [];
}

/**
 * Keep only the known filter keys of a filter set (bulk "all matching", counts, leads, segment definitions). A
 * `segment` id is kept so bulk actions on "all matching" never act on more contacts than the list shows; segment
 * definitions drop it (wa_crm_segment_save — a segment never nests another).
 */
function wa_crm_clean_filters(array $f): array
{
    $keys = ['q', 'scope', 'type', 'program_id', 'stage', 'admission_status', 'payment_status', 'city', 'state', 'batch', 'counsellor_id', 'tag', 'tag_mode', 'opt_in', 'status', 'window', 'last_from', 'last_to', 'created_from', 'created_to', 'source', 'lead_source', 'language', 'has_followup'];
    $out = [];
    $seg = is_scalar($f['segment'] ?? null) ? trim((string) $f['segment']) : '';
    if ($seg !== '' && ctype_digit($seg)) {
        $out['segment'] = $seg;
    }
    foreach ($keys as $k) {
        $v = $f[$k] ?? null;
        if (is_array($v)) {
            $v = wa_crm_list($v);
            if ($v) {
                $out[$k] = $v;
            }
        } elseif ($v !== null && trim((string) $v) !== '') {
            $out[$k] = trim((string) $v);
        }
    }
    return $out;
}

function wa_crm_segment_save(array $in, ?int $id = null): int
{
    $name = trim((string) ($in['name'] ?? ''));
    $errors = [];
    if ($name === '' || mb_strlen($name) > 120) {
        $errors['name'] = 'Enter a segment name (up to 120 characters).';
    } elseif (db_value('SELECT id FROM wa_segments WHERE name = ? AND id <> ?', [$name, (int) $id])) {
        $errors['name'] = 'A segment with this name already exists.';
    }
    $filters = wa_crm_clean_filters(is_array($in['filters'] ?? null) ? $in['filters'] : []);
    unset($filters['segment']);
    if (!$filters) {
        $errors['filters'] = 'Choose at least one filter before saving a segment.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $count = wa_crm_count($filters);
    $data = [
        'name' => $name, 'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 255) ?: null,
        'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE), 'contacts_count' => $count['total'], 'opted_in_count' => $count['opted_in'],
        'counted_at' => wa_crm_now(),
    ];
    if ($id) {
        db_update('wa_segments', $data, 'id = ?', [$id]);
        return $id;
    }
    return db_insert('wa_segments', $data + ['created_by' => wa_user_id()]);
}

/** Recount every segment (cheap; called after sync / imports). */
function wa_crm_segments_recount(): void
{
    foreach (db_all('SELECT id, filters FROM wa_segments') as $s) {
        $c = wa_crm_count(json_decode((string) $s['filters'], true) ?: []);
        db_update('wa_segments', ['contacts_count' => $c['total'], 'opted_in_count' => $c['opted_in'], 'counted_at' => wa_crm_now()], 'id = ?', [(int) $s['id']]);
    }
}

/* ============================================================================================ single contact */

/** Validate + save a contact from the add/edit modal. Returns the id. */
function wa_crm_save_contact(array $in, ?int $id = null): int
{
    $old = $id ? wa_contact($id) : null;
    if ($id && !$old) {
        throw new CrudException('Contact not found.');
    }
    foreach ($in as $k => $v) {
        if (is_array($v) && $k !== 'tags') {
            $in[$k] = ''; // a list where one value is expected: treated as empty (validated below)
        }
    }
    if ($old) {
        // a partial update keeps every field it does not send (never resets type, stage, opt-in or links to defaults);
        // a masked number (Settings › Security) sent back unchanged keeps the stored number
        foreach (['name', 'email', 'contact_type', 'stage', 'program_id', 'batch', 'city', 'state', 'counsellor_id', 'language', 'status', 'notes', 'application_no', 'opt_in'] as $k) {
            if (!array_key_exists($k, $in)) {
                $in[$k] = $old[$k];
            }
        }
        foreach (['phone', 'alt_phone'] as $k) {
            if (!array_key_exists($k, $in) || (is_string($in[$k]) && str_contains($in[$k], '•'))) {
                $in[$k] = $old[$k] ? '+' . $old[$k] : '';
            }
        }
    }
    $errors = [];
    $ph = wa_crm_phone((string) ($in['phone'] ?? ''));
    if (!$ph) {
        $errors['phone'] = 'Enter a valid WhatsApp number with the country code, e.g. +91 98765 43210.';
    } else {
        $dup = db_row('SELECT id, name FROM wa_contacts WHERE phone = ? AND id <> ?', [$ph['phone'], (int) $id]);
        if ($dup) {
            $errors['phone'] = 'This number already belongs to ' . ($dup['name'] ?: 'another contact') . ' (#' . $dup['id'] . ').';
        }
    }
    $name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')));
    if ($name === '') {
        $errors['name'] = 'Enter the contact name.';
    } elseif (mb_strlen($name) > 150) {
        $errors['name'] = 'Name is too long (150 characters max).';
    }
    $email = trim((string) ($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    $alt = null;
    if (trim((string) ($in['alt_phone'] ?? '')) !== '') {
        $altP = wa_crm_phone((string) $in['alt_phone']);
        if (!$altP) {
            $errors['alt_phone'] = 'Enter a valid alternate number or leave it blank.';
        } else {
            $alt = $altP['phone'];
        }
    }
    $type = (string) ($in['contact_type'] ?? 'lead');
    if (!isset(wa_crm_contact_types()[$type])) {
        $errors['contact_type'] = 'Choose a contact type.';
    }
    $stage = (string) ($in['stage'] ?? ($old['stage'] ?? 'new_enquiry'));
    if (!isset(wa_crm_stage_defaults()[$stage])) {
        $errors['stage'] = 'Choose a valid stage.';
    }
    $programId = !empty($in['program_id']) ? (int) $in['program_id'] : null;
    $programName = null;
    if ($programId) {
        $programName = db_value('SELECT name FROM programs WHERE id = ?', [$programId]);
        if (!$programName) {
            $errors['program_id'] = 'Choose a valid programme.';
        }
    }
    $counsellor = !empty($in['counsellor_id']) ? (int) $in['counsellor_id'] : null;
    if ($counsellor && !db_value("SELECT id FROM users WHERE id = ? AND status = 'active'", [$counsellor])) {
        $errors['counsellor_id'] = 'Choose an active counsellor.';
    }
    $status = (string) ($in['status'] ?? ($old['status'] ?? 'active'));
    if (!in_array($status, ['active', 'blocked', 'archived'], true)) {
        $errors['status'] = 'Choose a valid status.';
    }
    $lang = in_array($in['language'] ?? 'en', ['en', 'hi'], true) ? (string) ($in['language'] ?? 'en') : 'en';
    $optIn = !empty($in['opt_in']) && $in['opt_in'] !== '0' && $in['opt_in'] !== 'false';
    $optSource = trim((string) ($in['opt_in_source'] ?? ''));
    if ($optIn && (!$old || !(int) $old['opt_in']) && $optSource === '') {
        $errors['opt_in_source'] = 'Record where the consent came from (e.g. "Verbal consent on call 12 Nov").';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $data = [
        'phone' => $ph['phone'], 'country_code' => $ph['country_code'], 'national_number' => $ph['national'],
        'name' => $name, 'email' => $email ?: null, 'alt_phone' => $alt, 'contact_type' => $type,
        'program_id' => $programId, 'program_name' => $programName, 'batch' => trim((string) ($in['batch'] ?? '')) ?: null,
        'city' => trim((string) ($in['city'] ?? '')) ?: null, 'state' => trim((string) ($in['state'] ?? '')) ?: null,
        'application_no' => trim((string) ($in['application_no'] ?? '')) ?: null, 'counsellor_id' => $counsellor, 'language' => $lang,
        'status' => $status, 'notes' => trim((string) ($in['notes'] ?? '')) ?: null,
    ];
    return db_transaction(function () use ($data, $id, $old, $stage, $optIn, $optSource, $in) {
        if ($id) {
            db_update('wa_contacts', $data, 'id = ?', [$id]);
        } else {
            $id = wa_contact_upsert($data + ['source' => 'manual', 'stage' => $stage, 'created_by' => wa_user_id()], ['overwrite' => true]);
            db_update('wa_contacts', ['lead_source' => trim((string) ($in['lead_source'] ?? '')) ?: 'manual', 'stage_changed_at' => wa_crm_now()], 'id = ?', [$id]);
            wa_crm_history($id, null, $stage, 'manual', 'Contact created');
        }
        if ($old && $old['stage'] !== $stage) {
            wa_crm_set_stage($id, $stage, 'manual');
        }
        $wasIn = $old ? (int) $old['opt_in'] : 0;
        if ($optIn && !$wasIn) {
            wa_contact_opt($id, true, mb_substr($optSource, 0, 80));
        } elseif (!$optIn && $wasIn) {
            wa_contact_opt($id, false, 'manual');
        }
        if (array_key_exists('tags', $in) && is_array($in['tags'])) {
            wa_crm_set_tags($id, wa_crm_list($in['tags']));
        }
        if (!$old && in_array($data['contact_type'], ['lead', 'applicant'], true)) {
            wa_crm_followup_on_stage($id, null, $stage);
        }
        return $id;
    });
}

/** Replace the tag set of a contact with these tag names (creates missing tags). */
function wa_crm_set_tags(int $contactId, array $names): void
{
    $current = db_column('SELECT t.name FROM wa_contact_tags ct JOIN wa_tags t ON t.id = ct.tag_id WHERE ct.contact_id = ?', [$contactId]);
    $names = array_values(array_filter(array_map(fn ($n) => mb_substr(trim((string) $n), 0, 60), $names)));
    $lower = array_map('mb_strtolower', $names);
    $remove = array_values(array_filter($current, fn ($n) => !in_array(mb_strtolower($n), $lower, true)));
    $curLower = array_map('mb_strtolower', $current);
    $add = array_values(array_filter($names, fn ($n) => !in_array(mb_strtolower($n), $curLower, true)));
    if ($add) {
        wa_contact_tag($contactId, $add);
    }
    if ($remove) {
        wa_contact_untag($contactId, $remove);
    }
}

function wa_crm_tag_names(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    return db_column('SELECT name FROM wa_tags WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
}

/* ---------------------------------------------------------------- stage changes + history */

/** Write one stage-history row. */
function wa_crm_history(int $contactId, ?string $from, string $to, string $source = 'manual', ?string $note = null, ?int $by = null): void
{
    db_insert('wa_contact_stage_history', [
        'contact_id' => $contactId, 'from_stage' => $from, 'to_stage' => $to, 'source' => mb_substr($source, 0, 20),
        'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'changed_by' => $by ?? wa_user_id(),
    ]);
}

/** Context of a stage change started by this service (read by the WA_STAGE_CHANGED listener). */
function wa_crm_stage_ctx(?array $set = null, bool $clear = false): ?array
{
    static $ctx = null;
    if ($clear) {
        $prev = $ctx;
        $ctx = null;
        return $prev;
    }
    if ($set !== null) {
        $ctx = $set;
    }
    return $ctx;
}

/**
 * Change a contact's stage through the core (fires WA_STAGE_CHANGED for workflows) and make sure the history row and
 * the follow-up schedule exist even if the core dispatches the event later.
 */
function wa_crm_set_stage(int $contactId, string $stage, string $source = 'manual', ?string $note = null): bool
{
    if (!isset(wa_crm_stage_defaults()[$stage])) {
        throw new CrudException('Unknown stage.');
    }
    $from = db_value('SELECT stage FROM wa_contacts WHERE id = ?', [$contactId]);
    if ($from === null || $from === false) {
        throw new CrudException('Contact not found.');
    }
    if ($from === $stage) {
        return false;
    }
    wa_crm_stage_ctx(['contact_id' => $contactId, 'from' => $from, 'to' => $stage, 'source' => $source, 'note' => $note, 'handled' => false]);
    try {
        wa_contact_set_stage($contactId, $stage, ['reason' => $note ?? label_from_key($source), 'log' => false]);
    } finally {
        $ctx = wa_crm_stage_ctx(null, true);
    }
    if (!$ctx || empty($ctx['handled'])) {
        wa_crm_history($contactId, $from, $stage, $source, $note);
        wa_crm_followup_on_stage($contactId, $from, $stage);
    }
    if (in_array($source, ['manual', 'board', 'bulk'], true)) {
        // staff moved the lead in the WhatsApp CRM: mirror it on the linked ERP enquiry (sync / event moves come from the ERP)
        try {
            wa_crm_enquiry_writeback($contactId, $stage);
        } catch (Throwable $e) {
            error_log('[GIMT] wa_crm_enquiry_writeback: ' . $e->getMessage());
        }
    }
    return true;
}

/** Listener: WA_STAGE_CHANGED (from the core) → history + follow-up schedule. */
function wa_crm_on_stage_changed(array $event, array $payload, ?array $contact): ?array
{
    try {
        $id = (int) ($payload['contact_id'] ?? ($contact['id'] ?? 0));
        $to = (string) ($payload['to'] ?? ($contact['stage'] ?? ''));
        $from = isset($payload['from']) ? (string) $payload['from'] : null;
        if (!$id || $to === '' || $from === $to) {
            return null;
        }
        $ctx = wa_crm_stage_ctx();
        $source = (string) ($payload['source'] ?? 'automation');
        $note = isset($payload['reason']) && is_scalar($payload['reason']) ? (string) $payload['reason'] : null;
        $by = isset($payload['by']) && is_numeric($payload['by']) ? (int) $payload['by'] : null;
        if ($ctx && (int) $ctx['contact_id'] === $id && $ctx['to'] === $to) {
            $source = $ctx['source'];
            $note = $ctx['note'];
            $ctx['handled'] = true;
            wa_crm_stage_ctx($ctx);
        }
        wa_crm_history($id, $from, $to, $source, $note, $by);
        $r = wa_crm_followup_on_stage($id, $from, $to);
        return ['followups' => $r];
    } catch (Throwable $e) {
        error_log('[GIMT] wa_crm_on_stage_changed: ' . $e->getMessage());
        return null;
    }
}

/* ---------------------------------------------------------------- detail page */

function wa_crm_contact_detail(int $id): ?array
{
    $row = db_row('SELECT ' . wa_crm_list_select() . ', c.notes, c.erp_stage, c.created_by, cb.name AS created_by_name, c.interakt_synced_at
                   FROM ' . wa_crm_list_from() . ' LEFT JOIN users cb ON cb.id = c.created_by WHERE c.id = ?', [$id]);
    if (!$row) {
        return null;
    }
    $c = wa_crm_decorate([$row])[0];
    $c['history'] = array_map(function ($h) {
        $h['from_name'] = $h['from_stage'] ? wa_crm_stage_name($h['from_stage']) : null;
        $h['to_name'] = wa_crm_stage_name($h['to_stage']);
        return $h;
    }, db_all('SELECT h.*, u.name AS changed_by_name FROM wa_contact_stage_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.contact_id = ? ORDER BY h.id DESC LIMIT 50', [$id]));
    $c['followups'] = db_all(
        'SELECT f.*, t.name AS template_name, t.display_name AS template_display FROM wa_followups f LEFT JOIN wa_templates t ON t.id = f.template_id
         WHERE f.contact_id = ? ORDER BY f.due_at DESC, f.id DESC LIMIT 30', [$id]
    );
    foreach ($c['followups'] as &$f) {
        $f['stage_name'] = wa_crm_stage_name($f['stage_key']);
    }
    unset($f);
    $c['linked'] = wa_crm_linked_records($c);
    $c['stats'] = db_row(
        "SELECT COUNT(*) AS messages, COALESCE(SUM(direction = 'in'), 0) AS inbound, COALESCE(SUM(direction = 'out'), 0) AS outbound,
                COALESCE(SUM(status = 'read'), 0) AS read_count, MIN(created_at) AS first_message_at
         FROM wa_messages WHERE contact_id = ?", [$id]
    );
    $c['timeline'] = wa_crm_timeline($id);
    $c['related'] = $c['student_id'] ? array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'phone' => wa_crm_show_phone($r['phone']), 'contact_type' => $r['contact_type']], db_all(
        "SELECT id, name, phone, contact_type FROM wa_contacts WHERE student_id = ? AND id <> ? ORDER BY contact_type = 'student' DESC, id LIMIT 10", [$c['student_id'], $id]
    )) : [];
    if (wa_crm_masking()) {
        foreach ([['student', 'mobile'], ['student', 'guardian_phone'], ['enquiry', 'phone']] as [$rec, $k]) {
            if (!empty($c['linked'][$rec][$k])) {
                $c['linked'][$rec][$k] = wa_crm_show_phone((string) $c['linked'][$rec][$k]);
            }
        }
    }
    return $c;
}

/** ERP records linked to a contact (student + fees, enquiry, application). */
function wa_crm_linked_records(array $c): array
{
    $out = ['student' => null, 'enquiry' => null, 'admission' => null, 'fees' => null, 'payments' => []];
    if (!empty($c['student_id'])) {
        $out['student'] = db_row(
            "SELECT s.id, s.student_uid, s.admission_no, s.roll_no, TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)) AS name, s.mobile, s.email,
                    s.status, s.current_semester, s.guardian_name, s.guardian_phone, p.name AS program_name, b.name AS batch_name
             FROM students s LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN batches b ON b.id = s.batch_id WHERE s.id = ?", [$c['student_id']]
        );
        if ($out['student'] && (can('fees', 'view') || can('students', 'view') || can('whatsapp_contacts', 'manage'))) {
            $out['fees'] = db_row(
                "SELECT COUNT(*) AS invoices, COALESCE(SUM(net_amount), 0) AS total, COALESCE(SUM(paid_amount), 0) AS paid, COALESCE(SUM(balance_amount), 0) AS balance,
                        COALESCE(SUM(CASE WHEN balance_amount > 0 AND (status = 'overdue' OR due_date < CURDATE()) THEN balance_amount ELSE 0 END), 0) AS overdue,
                        MIN(CASE WHEN balance_amount > 0 THEN due_date END) AS next_due
                 FROM student_fees WHERE student_id = ? AND status NOT IN ('cancelled','waived')", [$c['student_id']]
            );
            $out['payments'] = db_all("SELECT id, receipt_no, amount, payment_date, mode, status FROM payments WHERE student_id = ? ORDER BY payment_date DESC, id DESC LIMIT 5", [$c['student_id']]);
        }
    }
    if (!empty($c['enquiry_id'])) {
        $out['enquiry'] = db_row(
            'SELECT e.id, e.name, e.phone, e.email, e.status, e.source, e.priority, e.follow_up_date, e.created_at, e.program_interest, p.name AS program_name, u.name AS assigned_name
             FROM enquiries e LEFT JOIN programs p ON p.id = e.program_id LEFT JOIN users u ON u.id = e.assigned_to WHERE e.id = ?', [$c['enquiry_id']]
        );
    }
    if (!empty($c['admission_id'])) {
        $out['admission'] = db_row(
            "SELECT a.id, a.application_no, TRIM(CONCAT_WS(' ', a.first_name, a.middle_name, a.last_name)) AS name, a.stage, a.fee_paid, a.admission_fee,
                    a.created_at, a.stage_changed_at, p.name AS program_name, u.name AS assigned_name, ses.name AS session_name
             FROM admissions a LEFT JOIN programs p ON p.id = a.program_id LEFT JOIN users u ON u.id = a.assigned_to
             LEFT JOIN academic_sessions ses ON ses.id = a.academic_session_id WHERE a.id = ?", [$c['admission_id']]
        );
    }
    return $out;
}

/** Activity timeline: messages, stage changes, notes, follow-ups, events, campaigns, agent actions (newest first). */
function wa_crm_timeline(int $id, int $limit = 80): array
{
    $items = [];
    foreach (db_all('SELECT id, direction, type, body, template_name, status, sender_type, created_at FROM wa_messages WHERE contact_id = ? ORDER BY id DESC LIMIT 40', [$id]) as $m) {
        $text = $m['type'] === 'template' ? 'Template "' . ($m['template_name'] ?: 'message') . '"' : ($m['body'] ?? '');
        $items[] = [
            'kind' => $m['direction'] === 'in' ? 'message_in' : 'message_out', 'at' => $m['created_at'],
            'title' => $m['direction'] === 'in' ? 'Message received' : 'Message sent' . ($m['sender_type'] !== 'agent' ? ' (' . (['followup' => 'Follow-up', 'bot' => 'Chatbot'][$m['sender_type']] ?? label_from_key((string) $m['sender_type'])) . ')' : ''),
            'detail' => mb_substr(trim((string) $text), 0, 180), 'status' => $m['status'], 'ref' => (int) $m['id'],
        ];
    }
    foreach (db_all('SELECT h.*, u.name AS by_name FROM wa_contact_stage_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.contact_id = ? ORDER BY h.id DESC LIMIT 30', [$id]) as $h) {
        $items[] = [
            'kind' => 'stage', 'at' => $h['created_at'],
            'title' => ($h['from_stage'] ? wa_crm_stage_name($h['from_stage']) . ' → ' : 'Stage: ') . wa_crm_stage_name($h['to_stage']),
            'detail' => trim(label_from_key($h['source']) . ($h['by_name'] ? ' by ' . $h['by_name'] : '') . ($h['note'] ? ' — ' . $h['note'] : '')),
        ];
    }
    foreach (db_all('SELECT n.body, n.created_at, u.name AS by_name FROM wa_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.contact_id = ? ORDER BY n.id DESC LIMIT 20', [$id]) as $n) {
        $items[] = ['kind' => 'note', 'at' => $n['created_at'], 'title' => 'Note' . ($n['by_name'] ? ' by ' . $n['by_name'] : ''), 'detail' => mb_substr((string) $n['body'], 0, 240)];
    }
    foreach (db_all('SELECT f.*, t.name AS template_name FROM wa_followups f LEFT JOIN wa_templates t ON t.id = f.template_id WHERE f.contact_id = ? ORDER BY f.id DESC LIMIT 20', [$id]) as $f) {
        $at = in_array($f['status'], ['sent', 'skipped', 'cancelled', 'failed'], true) ? ($f['updated_at'] ?: $f['due_at']) : $f['created_at'];
        $items[] = [
            'kind' => 'followup', 'at' => $at, 'status' => $f['status'],
            'title' => 'Follow-up #' . $f['attempt'] . ' (' . wa_crm_stage_name($f['stage_key']) . ') ' . $f['status'],
            'detail' => trim(($f['template_name'] && !str_contains((string) $f['note'], (string) $f['template_name']) ? 'Template ' . $f['template_name'] . '. ' : '') . ($f['status'] === 'scheduled' ? 'Due ' . date('d M Y, h:i A', strtotime($f['due_at'])) . '. ' : '') . ($f['note'] ?? '')),
        ];
    }
    foreach (db_all('SELECT event_code, status, created_at FROM wa_event_logs WHERE contact_id = ? ORDER BY id DESC LIMIT 20', [$id]) as $ev) {
        $items[] = ['kind' => 'event', 'at' => $ev['created_at'], 'title' => 'Event ' . $ev['event_code'], 'detail' => label_from_key($ev['status']), 'status' => $ev['status']];
    }
    foreach (db_all('SELECT r.status, r.created_at, r.updated_at, cp.name, cp.id AS campaign_id FROM wa_campaign_recipients r JOIN wa_campaigns cp ON cp.id = r.campaign_id WHERE r.contact_id = ? ORDER BY r.id DESC LIMIT 20', [$id]) as $r) {
        $items[] = ['kind' => 'campaign', 'at' => $r['updated_at'] ?: $r['created_at'], 'title' => 'Campaign "' . $r['name'] . '"', 'detail' => label_from_key($r['status']), 'status' => $r['status'], 'ref' => (int) $r['campaign_id']];
    }
    foreach (db_all('SELECT a.action, a.detail, a.created_at, u.name AS by_name FROM wa_agent_activity a LEFT JOIN users u ON u.id = a.user_id WHERE a.contact_id = ? AND a.action NOT IN (\'view\', \'reply\', \'note\', \'stage\') ORDER BY a.id DESC LIMIT 20', [$id]) as $a) {
        $items[] = ['kind' => 'agent', 'at' => $a['created_at'], 'title' => label_from_key($a['action']) . ($a['by_name'] ? ' by ' . $a['by_name'] : ''), 'detail' => (string) $a['detail']];
    }
    usort($items, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
    return array_slice($items, 0, $limit);
}

/* ============================================================================================ bulk actions */

/**
 * Bulk action on contact ids. Returns ['affected' => n, 'message' => text].
 * Actions: tag (tags[]), untag (tags[]), assign (counsellor_id|null), stage (stage), opt_in (source), opt_out (source),
 * status (status), delete.
 */
function wa_crm_bulk(string $action, array $ids, array $in): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        throw new CrudException('Select at least one contact.');
    }
    if (count($ids) > 20000) {
        throw new CrudException('Too many contacts in one action (20,000 max). Narrow the filters.');
    }
    $n = 0;
    switch ($action) {
        case 'tag':
        case 'untag':
            $names = wa_crm_list($in['tags'] ?? null);
            if (!$names) {
                throw new CrudValidationException(['tags' => 'Choose at least one tag.']);
            }
            db_transaction(function () use ($ids, $names, $action, &$n) {
                foreach ($ids as $id) {
                    $action === 'tag' ? wa_contact_tag($id, $names) : wa_contact_untag($id, $names);
                    $n++;
                }
            });
            return ['affected' => $n, 'message' => ($action === 'tag' ? 'Tagged ' : 'Removed tags from ') . $n . ' contact(s).'];
        case 'assign':
            $uid = !empty($in['counsellor_id']) ? (int) $in['counsellor_id'] : null;
            if ($uid && !db_value("SELECT id FROM users WHERE id = ? AND status = 'active'", [$uid])) {
                throw new CrudValidationException(['counsellor_id' => 'Choose an active counsellor.']);
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                $n += db_exec('UPDATE wa_contacts SET counsellor_id = ? WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', array_merge([$uid], $chunk));
            }
            if ($uid && $uid !== wa_user_id() && !wa_crm_quiet()) {
                notify($uid, 'whatsapp', count($ids) . ' WhatsApp contact(s) assigned to you', 'Assigned by ' . (function_exists('current_user') ? (current_user()['name'] ?? 'a colleague') : 'a colleague'), 'admin/whatsapp/contacts?counsellor_id=' . $uid, 'user-check');
            }
            return ['affected' => count($ids), 'message' => $uid ? 'Assigned ' . count($ids) . ' contact(s).' : 'Unassigned ' . count($ids) . ' contact(s).'];
        case 'stage':
            $stage = (string) ($in['stage'] ?? '');
            if (!isset(wa_crm_stage_defaults()[$stage])) {
                throw new CrudValidationException(['stage' => 'Choose a stage.']);
            }
            foreach ($ids as $id) {
                if (wa_crm_set_stage($id, $stage, 'bulk')) {
                    $n++;
                }
            }
            return ['affected' => $n, 'message' => "Moved $n contact(s) to " . wa_crm_stage_name($stage) . '.'];
        case 'opt_in':
        case 'opt_out':
            $source = mb_substr(trim((string) ($in['source'] ?? '')), 0, 80);
            if ($action === 'opt_in' && $source === '') {
                throw new CrudValidationException(['source' => 'Record where the consent came from (required for opt-in).']);
            }
            foreach ($ids as $id) {
                $cur = db_row('SELECT opt_in FROM wa_contacts WHERE id = ?', [$id]);
                if (!$cur || (int) $cur['opt_in'] === ($action === 'opt_in' ? 1 : 0)) {
                    continue;
                }
                wa_contact_opt($id, $action === 'opt_in', $source ?: 'manual');
                $n++;
            }
            return ['affected' => $n, 'message' => ($action === 'opt_in' ? 'Opted in ' : 'Opted out ') . $n . ' contact(s).'];
        case 'status':
            $status = (string) ($in['status'] ?? '');
            if (!in_array($status, ['active', 'blocked', 'archived'], true)) {
                throw new CrudValidationException(['status' => 'Choose a status.']);
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                $n += db_exec('UPDATE wa_contacts SET status = ? WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', array_merge([$status], $chunk));
            }
            if ($status !== 'active') {
                foreach (array_chunk($ids, 500) as $chunk) {
                    db_exec("UPDATE wa_followups SET status = 'cancelled', note = ? WHERE status = 'scheduled' AND contact_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')', array_merge(['Contact ' . $status], $chunk));
                }
            }
            return ['affected' => $n, 'message' => "Updated $n contact(s) to " . $status . '.'];
        case 'delete':
            foreach (array_chunk($ids, 500) as $chunk) {
                $n += db_exec('DELETE FROM wa_contacts WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
            }
            return ['affected' => $n, 'message' => "Deleted $n contact(s)."];
    }
    throw new CrudException('Unknown bulk action.');
}

/* ============================================================================================ export */

function wa_crm_export_columns(): array
{
    return [
        'name' => 'Name', 'phone' => 'WhatsApp number', 'email' => 'Email', 'alt_phone' => 'Alternate number', 'contact_type' => 'Type',
        'stage_name' => 'Stage', 'program_name' => 'Programme', 'batch' => 'Batch / intake', 'city' => 'City', 'state' => 'State',
        'application_no' => 'Application no', 'admission_status' => 'Admission status', 'payment_status' => 'Payment status',
        'counsellor_name' => 'Counsellor', 'tags' => 'Tags', 'opt_in' => 'WhatsApp opt-in', 'opt_in_source' => 'Opt-in source',
        'opt_in_at' => 'Opt-in date', 'lead_source' => 'Lead source', 'language' => 'Language', 'status' => 'Status',
        'last_message_at' => 'Last message', 'next_followup_at' => 'Next follow-up', 'created_at' => 'Added on',
    ];
}

function wa_crm_export_rows(array $f, int $max = 20000): array
{
    $args = [];
    $where = wa_crm_filter_where($f, $args);
    $order = wa_crm_sort_sql((string) ($f['sort'] ?? 'name'), (string) ($f['dir'] ?? 'asc'));
    $rows = wa_crm_decorate(db_all('SELECT ' . wa_crm_list_select() . ' FROM ' . wa_crm_list_from() . " WHERE $where ORDER BY $order LIMIT " . $max, $args));
    $cols = wa_crm_export_columns();
    $out = [];
    foreach ($rows as $r) {
        $line = [];
        foreach (array_keys($cols) as $k) {
            $v = $r[$k] ?? '';
            if ($k === 'phone' || $k === 'alt_phone') {
                $v = $v ? '+' . ltrim((string) $v, '+') : '';
            } elseif ($k === 'tags') {
                $v = implode(', ', array_map(fn ($t) => $t['name'], $r['tags']));
            } elseif ($k === 'opt_in') {
                $v = $r['opt_in'] ? 'Yes' : 'No';
            } elseif ($k === 'contact_type') {
                $v = wa_crm_contact_types()[$v] ?? $v;
            } elseif (in_array($k, ['admission_status', 'payment_status', 'lead_source', 'status'], true)) {
                $v = $v ? label_from_key((string) $v) : '';
            }
            $line[] = (string) $v;
        }
        $out[] = $line;
    }
    return ['headers' => array_values($cols), 'rows' => $out];
}

/* ============================================================================================ import */

function wa_crm_import_fields(): array
{
    return [
        'phone' => ['label' => 'WhatsApp number', 'required' => true, 'aliases' => ['phone', 'mobile', 'whatsapp', 'whatsapp number', 'mobile number', 'contact', 'contact number', 'phone number', 'number']],
        'name' => ['label' => 'Name', 'aliases' => ['name', 'full name', 'student name', 'contact name', 'student']],
        'email' => ['label' => 'Email', 'aliases' => ['email', 'email address', 'e-mail', 'mail']],
        'consent' => ['label' => 'WhatsApp consent (opt-in)', 'aliases' => ['consent', 'opt in', 'opt-in', 'optin', 'whatsapp opt-in', 'whatsapp consent', 'opted in']],
        'contact_type' => ['label' => 'Contact type', 'aliases' => ['type', 'contact type', 'category']],
        'program' => ['label' => 'Programme (name or code)', 'aliases' => ['program', 'programme', 'course', 'program name', 'course name', 'programme']],
        'city' => ['label' => 'City', 'aliases' => ['city', 'town', 'district']],
        'state' => ['label' => 'State', 'aliases' => ['state', 'province']],
        'batch' => ['label' => 'Batch / intake', 'aliases' => ['batch', 'intake', 'session', 'batch / intake']],
        'stage' => ['label' => 'Lead stage', 'aliases' => ['stage', 'lead stage', 'status']],
        'tags' => ['label' => 'Tags (comma separated)', 'aliases' => ['tags', 'tag', 'labels']],
        'application_no' => ['label' => 'Application no', 'aliases' => ['application no', 'application number', 'app no']],
        'alt_phone' => ['label' => 'Alternate number', 'aliases' => ['alternate number', 'alt phone', 'alternate phone', 'parent phone', 'guardian phone']],
        'counsellor' => ['label' => 'Counsellor (name or email)', 'aliases' => ['counsellor', 'counselor', 'assigned to', 'owner']],
        'language' => ['label' => 'Language (en / hi)', 'aliases' => ['language', 'lang']],
        'notes' => ['label' => 'Notes', 'aliases' => ['notes', 'remarks', 'comment', 'comments']],
    ];
}

function wa_crm_import_dir(): string
{
    $dir = STORAGE_PATH . '/tmp/wa_imports';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function wa_crm_import_path(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new CrudException('The import session has expired. Upload the file again.');
    }
    return wa_crm_import_dir() . '/' . (int) wa_user_id() . '_' . $token . '.json';
}

/** Step 1: read the uploaded CSV/XLSX, keep it server-side for 2 hours, return headers + sample + suggested mapping. */
function wa_crm_import_upload(array $file): array
{
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        throw new CrudValidationException(['file' => 'Choose a CSV or Excel (.xlsx) file.']);
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx', 'txt'], true)) {
        throw new CrudValidationException(['file' => 'Only .csv and .xlsx files can be imported.']);
    }
    if ((int) $file['size'] > 5 * 1024 * 1024) {
        throw new CrudValidationException(['file' => 'The file is larger than 5 MB. Split it into smaller files.']);
    }
    if ($ext === 'xlsx' && !class_exists('ZipArchive')) {
        throw new CrudValidationException(['file' => 'This server cannot read .xlsx files (PHP zip extension missing). Save the sheet as CSV and upload again.']);
    }
    try {
        $rows = $ext === 'xlsx' ? xlsx_read($file['tmp_name'], 10002) : wa_crm_csv_read($file['tmp_name'], 10002);
    } catch (Throwable $e) {
        throw new CrudValidationException(['file' => $e->getMessage()]);
    }
    $rows = array_values(array_filter($rows, fn ($r) => is_array($r) && implode('', array_map('strval', $r)) !== ''));
    if (count($rows) < 2) {
        throw new CrudValidationException(['file' => 'The file needs a header row and at least one contact row.']);
    }
    if (count($rows) > 10001) {
        throw new CrudValidationException(['file' => 'Import up to 10,000 contacts per file.']);
    }
    $headers = array_map(fn ($h) => trim((string) $h), array_shift($rows));
    $width = count($headers);
    foreach ($rows as &$r) {
        $r = array_slice(array_pad(array_map(fn ($v) => trim((string) $v), $r), $width, ''), 0, $width);
    }
    unset($r);
    // housekeeping: drop import files older than 2 hours
    foreach (glob(wa_crm_import_dir() . '/*.json') ?: [] as $old) {
        if (filemtime($old) < time() - 7200) {
            @unlink($old);
        }
    }
    $token = bin2hex(random_bytes(16));
    file_put_contents(wa_crm_import_path($token), json_encode(['file' => mb_substr((string) $file['name'], 0, 120), 'headers' => $headers, 'rows' => $rows], JSON_UNESCAPED_UNICODE));
    $mapping = [];
    foreach (wa_crm_import_fields() as $key => $def) {
        foreach ($headers as $i => $h) {
            $hl = strtolower(trim(preg_replace('/[^a-z0-9\/ -]+/i', ' ', $h)));
            if (in_array($hl, $def['aliases'], true) && !in_array($i, $mapping, true)) {
                $mapping[$key] = $i;
                break;
            }
        }
    }
    return [
        'token' => $token, 'file' => $file['name'], 'headers' => $headers, 'total_rows' => count($rows), 'sample' => array_slice($rows, 0, 5),
        'mapping' => (object) $mapping, 'fields' => array_map(fn ($k, $d) => ['key' => $k, 'label' => $d['label'], 'required' => !empty($d['required'])], array_keys(wa_crm_import_fields()), wa_crm_import_fields()),
    ];
}

/**
 * Read a CSV upload (UTF-8 with or without BOM; comma, semicolon or tab separated). Passes fgetcsv's escape argument
 * explicitly (no PHP 8.4 deprecation notice) and drops the protective apostrophe our own exports add.
 */
function wa_crm_csv_read(string $path, int $maxRows = 10002): array
{
    $fh = fopen($path, 'r');
    if (!$fh) {
        throw new RuntimeException('The CSV file could not be opened.');
    }
    $first = (string) fgets($fh);
    rewind($fh);
    $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    $rows = [];
    $isFirst = true;
    while (($line = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
        if ($isFirst) {
            $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);
            $isFirst = false;
        }
        if ($line === [null]) {
            continue;
        }
        $rows[] = array_map(fn ($v) => trim(function_exists('csv_unsafe') ? csv_unsafe((string) $v) : (string) $v), $line);
        if (count($rows) >= $maxRows) {
            break;
        }
    }
    fclose($fh);
    return $rows;
}

function wa_crm_import_load(string $token): array
{
    $path = wa_crm_import_path($token);
    if (!is_file($path)) {
        throw new CrudException('The import session has expired. Upload the file again.');
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['rows'])) {
        throw new CrudException('The import session is damaged. Upload the file again.');
    }
    return $data;
}

function wa_crm_truthy($v): bool
{
    return in_array(strtolower(trim((string) $v)), ['1', 'yes', 'y', 'true', 'opted in', 'opt-in', 'opt in', 'agree', 'agreed', 'consent', 'x', '✓'], true);
}

/** Analyse every row of an import with a mapping: normalised values + action (create|update|skip|error). */
function wa_crm_import_analyse(array $data, array $mapping, string $strategy): array
{
    $fields = wa_crm_import_fields();
    $map = [];
    foreach ($mapping as $k => $col) {
        if (isset($fields[$k]) && $col !== null && $col !== '' && is_numeric($col) && (int) $col >= 0 && (int) $col < count($data['headers'])) {
            $map[$k] = (int) $col;
        }
    }
    if (!isset($map['phone'])) {
        throw new CrudValidationException(['mapping.phone' => 'Choose the column that holds the WhatsApp number.']);
    }
    $programs = [];
    foreach (db_all('SELECT id, name, code FROM programs') as $p) {
        $programs[mb_strtolower($p['name'])] = (int) $p['id'];
        $programs[mb_strtolower((string) $p['code'])] = (int) $p['id'];
    }
    $users = [];
    foreach (db_all("SELECT id, name, email FROM users WHERE status = 'active'") as $u) {
        $users[mb_strtolower($u['name'])] = (int) $u['id'];
        if ($u['email']) {
            $users[mb_strtolower($u['email'])] = (int) $u['id'];
        }
    }
    $stageNames = [];
    foreach (wa_crm_stages() as $k => $s) {
        $stageNames[$k] = $k;
        $stageNames[mb_strtolower($s['name'])] = $k;
    }
    $types = [];
    foreach (wa_crm_contact_types() as $k => $l) {
        $types[$k] = $k;
        $types[mb_strtolower($l)] = $k;
    }
    $types['parent'] = 'guardian';
    $phones = [];
    foreach ($data['rows'] as $i => $r) {
        $ph = wa_crm_phone($r[$map['phone']] ?? '');
        if ($ph) {
            $phones[] = $ph['phone'];
        }
    }
    $existing = [];
    foreach (array_chunk(array_values(array_unique($phones)), 500) as $chunk) {
        foreach (db_all('SELECT id, phone, name FROM wa_contacts WHERE phone IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $c) {
            $existing[$c['phone']] = $c;
        }
    }
    $seen = [];
    $out = [];
    foreach ($data['rows'] as $i => $r) {
        $get = fn (string $k) => isset($map[$k]) ? trim((string) ($r[$map[$k]] ?? '')) : '';
        $row = ['row' => $i + 2, 'raw_phone' => $get('phone'), 'name' => $get('name'), 'errors' => [], 'warnings' => []];
        $ph = wa_crm_phone($row['raw_phone']);
        if (!$ph) {
            $row['errors'][] = $row['raw_phone'] === '' ? 'Number missing' : 'Invalid number';
        } else {
            $row['phone'] = $ph['phone'];
        }
        $email = $get('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $row['warnings'][] = 'Invalid email ignored';
            $email = '';
        }
        $row['email'] = $email;
        $row['consent'] = isset($map['consent']) ? wa_crm_truthy($get('consent')) : null;
        $t = mb_strtolower($get('contact_type'));
        $row['contact_type'] = $t !== '' ? ($types[$t] ?? null) : null;
        if ($t !== '' && !$row['contact_type']) {
            $row['warnings'][] = 'Unknown type "' . $get('contact_type') . '"';
        }
        $p = mb_strtolower($get('program'));
        $row['program_id'] = $p !== '' ? ($programs[$p] ?? null) : null;
        if ($p !== '' && !$row['program_id']) {
            $row['warnings'][] = 'Programme "' . $get('program') . '" not found';
        }
        $s = mb_strtolower(str_replace('-', ' ', $get('stage')));
        $s2 = str_replace(' ', '_', $s);
        $row['stage'] = $s !== '' ? ($stageNames[$s] ?? $stageNames[$s2] ?? null) : null;
        if ($s !== '' && !$row['stage']) {
            $row['warnings'][] = 'Unknown stage "' . $get('stage') . '"';
        }
        $cn = mb_strtolower($get('counsellor'));
        $row['counsellor_id'] = $cn !== '' ? ($users[$cn] ?? null) : null;
        if ($cn !== '' && !$row['counsellor_id']) {
            $row['warnings'][] = 'Counsellor "' . $get('counsellor') . '" not found';
        }
        $alt = $get('alt_phone') !== '' ? wa_crm_phone($get('alt_phone')) : null;
        $row['alt_phone'] = $alt['phone'] ?? null;
        $lang = mb_strtolower($get('language'));
        $row['language'] = in_array($lang, ['en', 'hi'], true) ? $lang : ($lang === 'hindi' ? 'hi' : null);
        foreach (['city', 'state', 'batch', 'application_no', 'notes'] as $k) {
            $row[$k] = $get($k);
        }
        $row['tags'] = array_values(array_filter(array_map('trim', explode(',', $get('tags')))));
        if ($row['errors']) {
            $row['action'] = 'error';
        } elseif (isset($seen[$row['phone']])) {
            $row['action'] = 'skip';
            $row['warnings'][] = 'Duplicate of row ' . $seen[$row['phone']] . ' in this file';
        } elseif (isset($existing[$row['phone']])) {
            $row['existing_id'] = (int) $existing[$row['phone']]['id'];
            $row['existing_name'] = $existing[$row['phone']]['name'];
            $row['action'] = $strategy === 'skip' ? 'skip' : 'update';
            if ($strategy === 'skip') {
                $row['warnings'][] = 'Already a contact (' . ($row['existing_name'] ?: '#' . $row['existing_id']) . ')';
            }
        } else {
            $row['action'] = 'create';
        }
        if (!empty($row['phone'])) {
            $seen[$row['phone']] = $seen[$row['phone']] ?? $row['row'];
        }
        $out[] = $row;
    }
    return $out;
}

function wa_crm_import_preview(string $token, array $mapping, string $strategy): array
{
    $data = wa_crm_import_load($token);
    $rows = wa_crm_import_analyse($data, $mapping, $strategy);
    $counts = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0, 'opt_in' => 0];
    foreach ($rows as $r) {
        $counts[$r['action']]++;
        if ($r['consent'] && $r['action'] !== 'error' && $r['action'] !== 'skip') {
            $counts['opt_in']++;
        }
    }
    // show problems first, then the first rows
    $problems = array_values(array_filter($rows, fn ($r) => $r['action'] === 'error' || $r['warnings']));
    $preview = array_slice(array_merge($problems, array_filter($rows, fn ($r) => !$r['errors'] && !$r['warnings'])), 0, 100);
    usort($preview, fn ($a, $b) => $a['row'] <=> $b['row']);
    return ['counts' => $counts, 'total' => count($rows), 'rows' => $preview, 'consent_mapped' => isset($mapping['consent']) && $mapping['consent'] !== '' && $mapping['consent'] !== null];
}

/** Final step: create/update contacts. Returns the result report. */
function wa_crm_import_run(string $token, array $mapping, string $strategy, array $opts): array
{
    $data = wa_crm_import_load($token);
    $rows = wa_crm_import_analyse($data, $mapping, $strategy);
    $defaultType = isset(wa_crm_contact_types()[$opts['contact_type'] ?? '']) ? $opts['contact_type'] : 'lead';
    $extraTags = wa_crm_list($opts['tags'] ?? null);
    // a "Yes" in a consent column is only as good as where it was collected: the importer has to name that source
    $optSource = mb_substr(trim((string) ($opts['opt_in_source'] ?? '')), 0, 80);
    $consenting = count(array_filter($rows, fn ($r) => $r['consent'] === true && in_array($r['action'], ['create', 'update'], true)));
    if ($consenting && $optSource === '') {
        throw new CrudValidationException(['opt_in_source' => 'Say where ' . ($consenting === 1 ? 'this contact' : 'these ' . $consenting . ' contacts') . ' agreed to WhatsApp messages (e.g. "Education fair 2026 sign-up sheet"). It is stored with every opt-in.']);
    }
    $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'opted_in' => 0, 'errors' => [], 'file' => $data['file']];
    $prevQuiet = wa_crm_quiet(true);
    try {
        foreach ($rows as $r) {
            if ($r['action'] === 'error') {
                $report['failed']++;
                $report['errors'][] = ['row' => $r['row'], 'message' => implode('; ', $r['errors']) . ($r['raw_phone'] !== '' ? ' (' . $r['raw_phone'] . ')' : '')];
                continue;
            }
            if ($r['action'] === 'skip') {
                $report['skipped']++;
                continue;
            }
            try {
                db_transaction(function () use ($r, $strategy, $defaultType, $extraTags, $optSource, &$report) {
                    $fields = array_filter([
                        'name' => $r['name'] ? mb_substr($r['name'], 0, 150) : null, 'email' => $r['email'] ?: null, 'alt_phone' => $r['alt_phone'],
                        'program_id' => $r['program_id'], 'program_name' => $r['program_id'] ? db_value('SELECT name FROM programs WHERE id = ?', [$r['program_id']]) : null,
                        'city' => $r['city'] ? mb_substr($r['city'], 0, 80) : null, 'state' => $r['state'] ? mb_substr($r['state'], 0, 80) : null,
                        'batch' => $r['batch'] ? mb_substr($r['batch'], 0, 80) : null, 'application_no' => $r['application_no'] ? mb_substr($r['application_no'], 0, 40) : null,
                        'counsellor_id' => $r['counsellor_id'], 'language' => $r['language'], 'notes' => $r['notes'] ?: null,
                    ], fn ($v) => $v !== null && $v !== '');
                    $id = $r['existing_id'] ?? null;
                    if ($id) {
                        $cur = db_row('SELECT * FROM wa_contacts WHERE id = ?', [$id]);
                        $upd = [];
                        foreach ($fields as $k => $v) {
                            if ($strategy === 'overwrite' || $cur[$k] === null || $cur[$k] === '') {
                                $upd[$k] = $v;
                            }
                        }
                        if ($r['contact_type'] && ($strategy === 'overwrite' || wa_crm_type_rank($r['contact_type']) > wa_crm_type_rank($cur['contact_type']))) {
                            $upd['contact_type'] = $r['contact_type'];
                        }
                        if ($upd) {
                            db_update('wa_contacts', $upd, 'id = ?', [$id]);
                        }
                        if ($r['stage'] && $r['stage'] !== $cur['stage'] && ($strategy === 'overwrite' || wa_crm_stage_rank($r['stage']) > wa_crm_stage_rank($cur['stage']))) {
                            wa_crm_set_stage($id, $r['stage'], 'import');
                        }
                        $report['updated']++;
                    } else {
                        $ph = wa_crm_phone($r['phone']);
                        $id = wa_contact_upsert($fields + [
                            'phone' => $ph['phone'], 'country_code' => $ph['country_code'], 'national_number' => $ph['national'],
                            'contact_type' => $r['contact_type'] ?: $defaultType, 'stage' => $r['stage'] ?: 'new_enquiry', 'source' => 'import', 'created_by' => wa_user_id(),
                        ]);
                        db_update('wa_contacts', ['lead_source' => 'import', 'stage_changed_at' => wa_crm_now()], 'id = ?', [$id]);
                        wa_crm_history($id, null, $r['stage'] ?: 'new_enquiry', 'import', 'Imported from ' . $report['file']);
                        if (in_array($r['contact_type'] ?: $defaultType, ['lead', 'applicant'], true)) {
                            wa_crm_followup_on_stage($id, null, $r['stage'] ?: 'new_enquiry');
                        }
                        $report['created']++;
                    }
                    $tags = array_values(array_unique(array_merge($r['tags'], $extraTags)));
                    if ($tags) {
                        wa_contact_tag($id, $tags);
                    }
                    if ($r['consent'] === true) {
                        $cur = db_row('SELECT opt_in FROM wa_contacts WHERE id = ?', [$id]);
                        if ($cur && !(int) $cur['opt_in']) {
                            wa_contact_opt($id, true, $optSource);
                            $report['opted_in']++;
                        }
                    }
                });
            } catch (Throwable $e) {
                $report['failed']++;
                $report['errors'][] = ['row' => $r['row'], 'message' => $e instanceof CrudException ? $e->getMessage() : 'Could not save this row.'];
                if (!$e instanceof CrudException) {
                    error_log('[GIMT] wa import row ' . $r['row'] . ': ' . $e->getMessage());
                }
            }
        }
    } finally {
        wa_crm_quiet($prevQuiet);
    }
    @unlink(wa_crm_import_path($token));
    $report['errors'] = array_slice($report['errors'], 0, 200);
    return $report;
}

/* ============================================================================================ sync with the ERP */

/** Enquiry status → stage. */
function wa_crm_stage_from_enquiry(string $status): ?string
{
    return ['new' => 'new_enquiry', 'contacted' => 'contacted', 'interested' => 'interested', 'not_interested' => 'not_interested', 'closed' => 'not_interested', 'converted' => 'application_submitted'][$status] ?? null;
}

/** Application stage (+ fee) → stage. */
function wa_crm_stage_from_admission(array $a): ?string
{
    if (!empty($a['student_id']) || $a['stage'] === 'confirmed') {
        return 'admission_confirmed';
    }
    switch ($a['stage']) {
        case 'enquiry':
            return 'application_started';
        case 'application':
        case 'document_verification':
        case 'entrance_interview':
        case 'approval':
        case 'waitlisted':
            return 'application_submitted';
        case 'fee_payment':
            return 'payment_pending';
        case 'rejected':
        case 'withdrawn':
            return 'not_interested';
    }
    return null;
}

/**
 * Website enquiry forms whose consent checkbox ("…contact me … by phone, SMS, email or WhatsApp") is mandatory and
 * enforced server-side (public_forms.php pf_enquiry_consent_forms()). Kept in step with that list.
 */
function wa_crm_consent_forms(): array
{
    return function_exists('pf_enquiry_consent_forms') ? pf_enquiry_consent_forms() : ['smart-enquiry', 'enquiry-popup', 'faq-question', 'callback', 'landing-hero', 'landing-footer'];
}

/**
 * WhatsApp consent of an ERP enquiry: [bool consent, ?string source]. Only an explicit signal counts — an
 * enquiries.whatsapp_opt_in column when the ERP has one, or an enquiry submitted through a website form whose
 * WhatsApp consent box is mandatory (the form name is in the enquiry notes, "Website form: Page · form"). The lead
 * source alone (website, WhatsApp, chatbot, FAQ) is NOT consent for marketing messages: a student who writes to GIMT
 * first only opens the 24-hour service window. Consent given on a form also arrives with the GIMT_* event payload.
 */
function wa_crm_enquiry_consent(array $e): array
{
    if (array_key_exists('consent', $e) && $e['consent'] !== null) {
        return [(bool) $e['consent'], (bool) $e['consent'] ? 'Enquiry form WhatsApp consent' : null];
    }
    if (preg_match('/^Website form:[^\n]*?[ \t]([a-z0-9]+(?:-[a-z0-9]+)*)[ \t\r]*$/m',(string) ($e['notes'] ?? ''), $m) && in_array($m[1], wa_crm_consent_forms(), true)) {
        return [true, 'Website form consent (' . $m[1] . ')'];
    }
    return [false, null];
}

/**
 * Collect candidate contact records from the ERP. $opts: since (Y-m-d H:i:s: rows changed after), scope
 * (enquiries|admissions|students|guardians|null = all), enquiry_ids / admission_ids / student_ids (single records).
 * Returns [phone => [candidates…]] where every candidate is a normalised array with a 'priority'.
 */
function wa_crm_collect(array $opts = []): array
{
    $scope = $opts['scope'] ?? null;
    $since = $opts['since'] ?? null;
    $by = [];
    $add = function (?string $raw, array $cand) use (&$by) {
        $ph = wa_crm_phone($raw);
        if (!$ph) {
            return;
        }
        $cand['phone'] = $ph['phone'];
        $cand['country_code'] = $ph['country_code'];
        $cand['national_number'] = $ph['national'];
        $by[$ph['phone']][] = $cand;
    };
    $idFilter = function (string $col, array $ids, array &$args): string {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return ' AND 1=0';
        }
        array_push($args, ...$ids);
        return " AND $col IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
    };
    $single = isset($opts['enquiry_ids']) || isset($opts['admission_ids']) || isset($opts['student_ids']);
    $sessionName = current_session()['name'] ?? null;
    $consentCol = wa_crm_column_exists('enquiries', 'whatsapp_opt_in') ? 'e.whatsapp_opt_in' : 'NULL';

    // ------------------------------------------------ enquiries
    if ((!$scope || $scope === 'enquiries') && (!$single || isset($opts['enquiry_ids']))) {
        $args = [];
        $sql = "SELECT e.id, e.name, e.email, e.phone, e.city, e.program_id, p.name AS program_name, e.program_interest, e.source, e.status,
                       e.assigned_to, e.admission_id, e.created_at, COALESCE(e.updated_at, e.created_at) AS changed_at, $consentCol AS consent, e.notes
                FROM enquiries e LEFT JOIN programs p ON p.id = e.program_id WHERE 1=1";
        if ($since) {
            $sql .= ' AND COALESCE(e.updated_at, e.created_at) >= ?';
            $args[] = $since;
        }
        if (isset($opts['enquiry_ids'])) {
            $sql .= $idFilter('e.id', $opts['enquiry_ids'], $args);
        }
        foreach (db_all($sql, $args) as $e) {
            [$consent, $consentSource] = wa_crm_enquiry_consent($e);
            $add($e['phone'], [
                'priority' => 30, 'kind' => 'enquiry', 'ref' => (int) $e['id'], 'name' => $e['name'], 'email' => $e['email'], 'contact_type' => 'lead',
                'enquiry_id' => (int) $e['id'], 'admission_id' => $e['admission_id'] ? (int) $e['admission_id'] : null,
                'program_id' => $e['program_id'] ? (int) $e['program_id'] : null, 'program_name' => $e['program_name'] ?: ($e['program_interest'] ?: null),
                'city' => $e['city'], 'batch' => $sessionName, 'erp_stage' => wa_crm_stage_from_enquiry((string) $e['status']),
                'admission_status' => $e['status'] ? 'enquiry_' . $e['status'] : null, 'counsellor_id' => $e['assigned_to'] ? (int) $e['assigned_to'] : null,
                'lead_source' => $e['source'], 'changed_at' => $e['changed_at'], 'stage_at' => $e['changed_at'], 'created_at' => $e['created_at'],
                'opt_in' => $consent, 'opt_in_source' => $consentSource,
            ]);
        }
    }
    // ------------------------------------------------ applications
    if ((!$scope || $scope === 'admissions') && (!$single || isset($opts['admission_ids']))) {
        $args = [];
        $sql = "SELECT a.id, a.application_no, TRIM(CONCAT_WS(' ', a.first_name, a.middle_name, a.last_name)) AS name, a.email, a.phone, a.whatsapp,
                       a.city, a.state, a.program_id, p.name AS program_name, ses.name AS session_name, a.source, a.stage, a.stage_changed_at,
                       a.assigned_to, a.fee_paid, a.admission_fee, a.student_id, a.enquiry_id, a.created_at, a.converted_at,
                       COALESCE(a.updated_at, a.created_at) AS changed_at
                FROM admissions a LEFT JOIN programs p ON p.id = a.program_id LEFT JOIN academic_sessions ses ON ses.id = a.academic_session_id WHERE 1=1";
        if ($since) {
            $sql .= ' AND COALESCE(a.updated_at, a.created_at) >= ?';
            $args[] = $since;
        }
        if (isset($opts['admission_ids'])) {
            $sql .= $idFilter('a.id', $opts['admission_ids'], $args);
        }
        foreach (db_all($sql, $args) as $a) {
            $fee = (float) $a['admission_fee'];
            $paid = (float) $a['fee_paid'];
            $pay = $fee > 0 && $paid >= $fee ? 'paid' : ($paid > 0 ? 'partial' : ($a['stage'] === 'fee_payment' ? 'pending' : null));
            $cand = [
                'priority' => 40, 'kind' => 'admission', 'ref' => (int) $a['id'], 'name' => $a['name'], 'email' => $a['email'],
                'contact_type' => $a['student_id'] ? 'student' : 'applicant', 'admission_id' => (int) $a['id'],
                'enquiry_id' => $a['enquiry_id'] ? (int) $a['enquiry_id'] : null, 'student_id' => $a['student_id'] ? (int) $a['student_id'] : null,
                'application_no' => $a['application_no'], 'program_id' => $a['program_id'] ? (int) $a['program_id'] : null, 'program_name' => $a['program_name'],
                'city' => $a['city'], 'state' => $a['state'], 'batch' => $a['session_name'], 'erp_stage' => wa_crm_stage_from_admission($a),
                'admission_status' => $a['stage'], 'payment_status' => $pay, 'counsellor_id' => $a['assigned_to'] ? (int) $a['assigned_to'] : null,
                'lead_source' => $a['source'], 'changed_at' => $a['changed_at'], 'stage_at' => $a['converted_at'] ?: ($a['stage_changed_at'] ?: $a['changed_at']),
                'created_at' => $a['created_at'],
            ];
            $primary = $a['whatsapp'] ?: $a['phone'];
            $altP = $a['whatsapp'] && $a['phone'] && preg_replace('/\D/', '', $a['whatsapp']) !== preg_replace('/\D/', '', $a['phone']) ? wa_crm_phone($a['phone']) : null;
            if ($altP) {
                $cand['alt_phone'] = $altP['phone'];
            }
            $add($primary, $cand);
        }
    }
    // ------------------------------------------------ students (+ guardians)
    $wantStudents = (!$scope || $scope === 'students') && (!$single || isset($opts['student_ids']));
    $wantGuardians = (!$scope || $scope === 'guardians') && (!$single || isset($opts['student_ids']));
    if ($wantStudents || $wantGuardians) {
        $args = [];
        $sql = "SELECT s.id, TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)) AS name, s.email, s.mobile, s.whatsapp, s.city, s.state,
                       s.program_id, p.name AS program_name, b.name AS batch_name, s.status, s.admission_id, s.guardian_name, s.guardian_relation,
                       s.guardian_phone, s.father_name, s.created_at, COALESCE(s.updated_at, s.created_at) AS changed_at, a.converted_at, a.enquiry_id,
                       a.application_no, a.assigned_to, a.source AS adm_source
                FROM students s LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN batches b ON b.id = s.batch_id
                LEFT JOIN admissions a ON a.id = s.admission_id WHERE 1=1";
        if ($since) {
            $sql .= ' AND COALESCE(s.updated_at, s.created_at) >= ?';
            $args[] = $since;
        }
        if (isset($opts['student_ids'])) {
            $sql .= $idFilter('s.id', $opts['student_ids'], $args);
        }
        $students = db_all($sql, $args);
        $fees = [];
        if ($students) {
            $sids = array_map(fn ($s) => (int) $s['id'], $students);
            foreach (array_chunk($sids, 1000) as $chunk) {
                foreach (db_all(
                    "SELECT student_id, COUNT(*) AS n, COALESCE(SUM(balance_amount), 0) AS balance, COALESCE(SUM(paid_amount), 0) AS paid,
                            COALESCE(SUM(CASE WHEN balance_amount > 0 AND (status = 'overdue' OR due_date < CURDATE()) THEN 1 ELSE 0 END), 0) AS overdue
                     FROM student_fees WHERE status NOT IN ('cancelled','waived') AND student_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ') GROUP BY student_id',
                    $chunk
                ) as $f) {
                    $fees[(int) $f['student_id']] = $f;
                }
            }
        }
        foreach ($students as $s) {
            $f = $fees[(int) $s['id']] ?? null;
            $pay = null;
            if ($f && (int) $f['n'] > 0) {
                $pay = (int) $f['overdue'] > 0 ? 'overdue' : ((float) $f['balance'] <= 0 ? 'paid' : ((float) $f['paid'] > 0 ? 'partial' : 'pending'));
            }
            $type = in_array($s['status'], ['graduated', 'alumni'], true) ? 'alumni' : 'student';
            $base = [
                'student_id' => (int) $s['id'], 'program_id' => $s['program_id'] ? (int) $s['program_id'] : null, 'program_name' => $s['program_name'],
                'batch' => $s['batch_name'], 'city' => $s['city'], 'state' => $s['state'], 'payment_status' => $pay, 'admission_status' => 'student_' . $s['status'],
                'erp_stage' => 'admission_confirmed', 'changed_at' => $s['changed_at'], 'stage_at' => $s['converted_at'] ?: $s['created_at'], 'created_at' => $s['created_at'],
            ];
            if ($wantStudents) {
                $add($s['whatsapp'] ?: $s['mobile'], $base + [
                    'priority' => 50, 'kind' => 'student', 'ref' => (int) $s['id'], 'name' => $s['name'], 'email' => $s['email'], 'contact_type' => $type,
                    'admission_id' => $s['admission_id'] ? (int) $s['admission_id'] : null, 'enquiry_id' => $s['enquiry_id'] ? (int) $s['enquiry_id'] : null,
                    'application_no' => $s['application_no'], 'counsellor_id' => $s['assigned_to'] ? (int) $s['assigned_to'] : null, 'lead_source' => $s['adm_source'],
                ]);
            }
            if ($wantGuardians && $s['guardian_phone'] && !in_array($s['status'], ['dropped'], true)) {
                $gName = $s['guardian_name'] ?: $s['father_name'];
                $add($s['guardian_phone'], $base + [
                    'priority' => 10, 'kind' => 'guardian', 'ref' => (int) $s['id'], 'name' => $gName ?: ('Parent of ' . $s['name']), 'contact_type' => 'guardian',
                    'ward' => $s['name'], 'relation' => $s['guardian_relation'],
                ]);
            }
        }
    }
    return $by;
}

/** Merge the candidates of one phone into a single record (highest priority / most recent first, empty fields filled). */
function wa_crm_merge_candidates(array $cands): array
{
    usort($cands, fn ($a, $b) => [$b['priority'], (string) $b['changed_at']] <=> [$a['priority'], (string) $a['changed_at']]);
    $rec = [];
    foreach ($cands as $c) {
        foreach ($c as $k => $v) {
            if (!array_key_exists($k, $rec) || $rec[$k] === null || $rec[$k] === '') {
                $rec[$k] = $v;
            }
        }
    }
    // opt-in: any consenting source counts
    foreach ($cands as $c) {
        if (!empty($c['opt_in'])) {
            $rec['opt_in'] = true;
            $rec['opt_in_source'] = $c['opt_in_source'] ?? 'Sync';
            break;
        }
    }
    $rec['sources'] = array_map(fn ($c) => ['kind' => $c['kind'], 'ref' => $c['ref'], 'name' => $c['name'] ?? null, 'ward' => $c['ward'] ?? null], $cands);
    return $rec;
}

/**
 * Import / refresh contacts from enquiries, applications, students and guardians.
 * $scope: null|'all' (everything), 'incremental' (rows changed since the last sync), 'enquiries'|'admissions'|'students'|'guardians'.
 * $opts: enquiry_ids / admission_ids / student_ids (single records, used by the event listeners), live (bool — fire
 * stage events + schedule follow-ups; default: only for rows changed in the last 7 days).
 */
function wa_crm_sync(?string $scope = null, array $opts = []): array
{
    $t0 = microtime(true);
    $startedAt = wa_crm_now();
    $collect = $opts;
    if ($scope === 'incremental') {
        $last = setting('wa_crm_sync_last');
        $collect['since'] = $last ? date('Y-m-d H:i:s', strtotime($last) - 120) : null;
        $scope = null;
    } elseif ($scope === 'all') {
        $scope = null;
    }
    if ($scope !== null && !in_array($scope, ['enquiries', 'admissions', 'students', 'guardians'], true)) {
        throw new CrudException('Unknown sync scope.');
    }
    $collect['scope'] = $scope;
    $byPhone = wa_crm_collect($collect);
    $stats = wa_crm_sync_empty_stats();
    wa_crm_sync_apply($byPhone, $opts, $stats);
    $stats['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);
    $stats['scope'] = $scope ?? (isset($collect['since']) ? 'incremental' : 'all');
    $stats['at'] = $startedAt;
    if (empty($opts['enquiry_ids']) && empty($opts['admission_ids']) && empty($opts['student_ids'])) {
        if ($scope === null) {
            // a complete pass (all / incremental): the next incremental run starts here; an unfinished tick pass is moot
            save_setting('wa_crm_sync_last', $startedAt, 'whatsapp');
            save_setting('_wa_crm_sync_cursor', '', 'internal');
        }
        save_setting('wa_crm_sync_stats', json_encode($stats), 'whatsapp');
        if ($stats['created'] || $stats['updated']) {
            wa_crm_segments_recount();
        }
    }
    return $stats;
}

function wa_crm_sync_empty_stats(): array
{
    return ['scanned' => 0, 'phones' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'stage_changes' => 0, 'opted_in' => 0, 'errors' => 0];
}

/**
 * Apply merged ERP records phone by phone (sorted or not — the caller decides the order). Adds to $stats. With a
 * $deadline (microtime) it stops once the time is spent, after at least one phone, and returns the last phone it
 * processed (the cursor for the next run); returns null when every phone was processed.
 */
function wa_crm_sync_apply(array $byPhone, array $opts, array &$stats, ?float $deadline = null): ?string
{
    if (!$byPhone) {
        return null;
    }
    $liveCut = date('Y-m-d H:i:s', strtotime('-7 days'));
    $prevQuiet = wa_crm_quiet(empty($opts['live']) && count($byPhone) > 25);
    // batch the writes (one commit per 250 contacts) unless the caller already runs a transaction
    $pdo = db();
    $own = count($byPhone) > 20 && !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    $n = 0;
    $last = null;
    $stopped = null;
    try {
        foreach (array_chunk(array_keys($byPhone), 200) as $chunk) {
            $existing = [];
            foreach (db_all('SELECT * FROM wa_contacts WHERE phone IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', array_map('strval', $chunk)) as $c) {
                $existing[$c['phone']] = $c;
            }
            foreach ($chunk as $phone) {
                if ($deadline !== null && $last !== null && microtime(true) >= $deadline) {
                    $stopped = $last;
                    break 2;
                }
                $phone = (string) $phone;
                $cands = $byPhone[$phone];
                if ($own && ++$n % 250 === 0) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                }
                $stats['phones']++;
                $stats['scanned'] += count($cands);
                try {
                    $rec = wa_crm_merge_candidates($cands);
                    $live = $opts['live'] ?? ((string) ($rec['changed_at'] ?? '') >= $liveCut);
                    $r = wa_crm_apply_record($phone, $rec, $existing[$phone] ?? null, (bool) $live);
                    $stats[$r['action']]++;
                    $stats['stage_changes'] += $r['stage_changed'] ? 1 : 0;
                    $stats['opted_in'] += $r['opted_in'] ? 1 : 0;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    error_log('[GIMT] wa_crm_sync ' . $phone . ': ' . $e->getMessage());
                }
                $last = $phone;
            }
        }
        if ($own && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        wa_crm_quiet($prevQuiet);
    }
    return $stopped;
}

/** Create or refresh one contact from a merged ERP record. Returns ['action' => created|updated|unchanged, 'stage_changed', 'opted_in']. */
function wa_crm_apply_record(string $phone, array $rec, ?array $cur, bool $live): array
{
    $now = wa_crm_now();
    $res = ['action' => 'unchanged', 'stage_changed' => false, 'opted_in' => false];
    $derived = $rec['erp_stage'] ?? null;
    if (!$cur) {
        $stage = $derived ?: ($rec['contact_type'] === 'guardian' ? 'admission_confirmed' : 'new_enquiry');
        $data = array_filter([
            'phone' => $phone, 'country_code' => $rec['country_code'], 'national_number' => $rec['national_number'], 'name' => $rec['name'] ? mb_substr($rec['name'], 0, 150) : null,
            'email' => $rec['email'] ?? null, 'alt_phone' => $rec['alt_phone'] ?? null, 'contact_type' => $rec['contact_type'] ?? 'lead',
            'student_id' => $rec['student_id'] ?? null, 'enquiry_id' => $rec['enquiry_id'] ?? null, 'admission_id' => $rec['admission_id'] ?? null,
            'application_no' => $rec['application_no'] ?? null, 'program_id' => $rec['program_id'] ?? null, 'program_name' => isset($rec['program_name']) ? mb_substr((string) $rec['program_name'], 0, 190) : null,
            'batch' => $rec['batch'] ?? null, 'city' => $rec['city'] ?? null, 'state' => $rec['state'] ?? null, 'stage' => $stage,
            'admission_status' => $rec['admission_status'] ?? null, 'payment_status' => $rec['payment_status'] ?? null, 'counsellor_id' => $rec['counsellor_id'] ?? null,
            'source' => 'sync',
        ], fn ($v) => $v !== null && $v !== '');
        $id = wa_contact_upsert($data);
        $extra = ['erp_stage' => $derived, 'lead_source' => $rec['lead_source'] ?? null, 'synced_at' => $now, 'stage' => $stage, 'stage_changed_at' => $rec['stage_at'] ?? $now, 'created_by' => null];
        if (!empty($rec['created_at'])) {
            $extra['created_at'] = $rec['created_at'];
        }
        if (!empty($rec['opt_in'])) {
            $extra += ['opt_in' => 1, 'opt_in_at' => $rec['created_at'] ?? $now, 'opt_in_source' => mb_substr((string) $rec['opt_in_source'], 0, 80)];
            $res['opted_in'] = true;
        }
        db_update('wa_contacts', $extra, 'id = ?', [$id]);
        wa_crm_history($id, null, $stage, 'sync', 'Imported from the admission portal', null);
        if ($live && in_array($data['contact_type'], ['lead', 'applicant'], true)) {
            wa_crm_followup_on_stage($id, null, $stage);
        }
        $res['action'] = 'created';
        return $res;
    }
    $id = (int) $cur['id'];
    $upd = [];
    // ERP-owned links / status fields: refresh when the ERP has a value
    foreach (['student_id', 'enquiry_id', 'admission_id', 'application_no', 'program_id', 'program_name', 'batch', 'admission_status', 'payment_status', 'lead_source'] as $k) {
        $v = $rec[$k] ?? null;
        if ($v !== null && $v !== '' && (string) $cur[$k] !== (string) $v) {
            $upd[$k] = $k === 'program_name' ? mb_substr((string) $v, 0, 190) : $v;
        }
    }
    // profile fields: only fill blanks (manual edits win)
    foreach (['name', 'email', 'alt_phone', 'city', 'state', 'counsellor_id'] as $k) {
        $v = $rec[$k] ?? null;
        if ($v !== null && $v !== '' && ($cur[$k] === null || $cur[$k] === '')) {
            $upd[$k] = $k === 'name' ? mb_substr((string) $v, 0, 150) : $v;
        }
    }
    if (!empty($rec['contact_type']) && $cur['contact_type'] !== 'staff' && wa_crm_type_rank($rec['contact_type']) > wa_crm_type_rank($cur['contact_type'])) {
        $upd['contact_type'] = $rec['contact_type'];
    }
    if (!empty($rec['opt_in']) && !(int) $cur['opt_in'] && empty($cur['opted_out_at'])) {
        $upd += ['opt_in' => 1, 'opt_in_at' => $now, 'opt_in_source' => mb_substr((string) $rec['opt_in_source'], 0, 80)];
        $res['opted_in'] = true;
    }
    // stage: apply the ERP-derived stage only when the ERP changed it since the last sync and it is not a step back
    // behind a manual stage (counselling scheduled / application started are set by counsellors)
    $newStage = null;
    if ($derived && $derived !== ($cur['erp_stage'] ?? null)) {
        $curStage = (string) $cur['stage'];
        if ($derived !== $curStage) {
            $terminal = in_array($derived, wa_crm_terminal_stages(), true) || in_array($curStage, wa_crm_terminal_stages(), true);
            if ($terminal || wa_crm_stage_rank($derived) >= wa_crm_stage_rank($curStage)) {
                $newStage = $derived;
            }
        }
        $upd['erp_stage'] = $derived;
    }
    if ($upd) {
        $upd['synced_at'] = $now;
        db_update('wa_contacts', $upd, 'id = ?', [$id]);
        $res['action'] = 'updated';
    }
    if ($newStage) {
        if ($live) {
            wa_crm_set_stage($id, $newStage, 'sync', 'Updated from the admission portal');
        } else {
            db_update('wa_contacts', ['stage' => $newStage, 'stage_changed_at' => $rec['stage_at'] ?? $now], 'id = ?', [$id]);
            wa_crm_history($id, (string) $cur['stage'], $newStage, 'sync', 'Updated from the admission portal', null);
            if (in_array($newStage, wa_crm_terminal_stages(), true)) {
                db_exec("UPDATE wa_followups SET status = 'cancelled', note = ? WHERE contact_id = ? AND status = 'scheduled'", ['Stage changed to ' . wa_crm_stage_name($newStage), $id]);
            }
        }
        $res['stage_changed'] = true;
        $res['action'] = 'updated';
    } elseif ($live && $derived && empty($cur['erp_stage']) && in_array($upd['contact_type'] ?? $cur['contact_type'], ['lead', 'applicant'], true)) {
        // first link to an ERP record (e.g. the contact was just created by an event): start the stage follow-ups
        wa_crm_followup_on_stage($id, null, (string) $cur['stage']);
    }
    return $res;
}

/**
 * Incremental sync tick (every 15 minutes; Settings › WhatsApp › auto sync contacts). Respects the worker's $budget:
 * one pass (rows changed since the last pass, or everything on the first run) is processed in phone order and, when
 * the time is spent, continues on the next tick from a cursor (setting _wa_crm_sync_cursor). wa_crm_sync_last only
 * moves when a pass is complete, so no change is lost between slices.
 */
function wa_crm_sync_tick(float $budget): array
{
    if ((string) setting('wa_auto_sync_contacts', '1') === '0') {
        return ['skipped' => 'disabled'];
    }
    $t0 = microtime(true);
    $deadline = $t0 + max(0.3, $budget - 0.25);
    $cursor = json_decode((string) setting('_wa_crm_sync_cursor', ''), true);
    if (!is_array($cursor) || empty($cursor['started_at']) || strtotime((string) $cursor['started_at']) < time() - 86400) {
        $last = setting('wa_crm_sync_last');
        if ($last && strtotime($last) > time() - 900) {
            return ['skipped' => 'not_due'];
        }
        $cursor = ['started_at' => wa_crm_now(), 'since' => $last ? date('Y-m-d H:i:s', strtotime($last) - 120) : null, 'after' => null,
            'stats' => wa_crm_sync_empty_stats(), 'ms' => 0, 'slices' => 0];
    }
    $byPhone = wa_crm_collect(['since' => $cursor['since'] ?? null]);
    $keys = array_map('strval', array_keys($byPhone));
    sort($keys, SORT_STRING);
    $after = isset($cursor['after']) ? (string) $cursor['after'] : null;
    $ordered = [];
    foreach ($keys as $k) {
        if ($after === null || strcmp($k, $after) > 0) {
            $ordered[$k] = $byPhone[$k];
        }
    }
    unset($byPhone);
    $stats = array_merge(wa_crm_sync_empty_stats(), is_array($cursor['stats'] ?? null) ? $cursor['stats'] : []);
    $before = $stats['phones'];
    $stopped = wa_crm_sync_apply($ordered, [], $stats, $deadline);
    $ms = (int) ($cursor['ms'] ?? 0) + (int) round((microtime(true) - $t0) * 1000);
    $slice = ['processed' => $stats['phones'] - $before, 'duration_ms' => (int) round((microtime(true) - $t0) * 1000)];
    if ($stopped !== null) {
        $cursor = ['after' => $stopped, 'stats' => $stats, 'ms' => $ms, 'slices' => (int) ($cursor['slices'] ?? 0) + 1] + $cursor;
        save_setting('_wa_crm_sync_cursor', json_encode($cursor), 'internal');
        return $slice + ['partial' => true, 'remaining' => count($ordered) - $slice['processed']];
    }
    $stats['duration_ms'] = $ms;
    $stats['scope'] = $cursor['since'] ? 'incremental' : 'all';
    $stats['at'] = $cursor['started_at'];
    $stats['slices'] = (int) ($cursor['slices'] ?? 0) + 1;
    save_setting('wa_crm_sync_last', $cursor['started_at'], 'whatsapp');
    save_setting('wa_crm_sync_stats', json_encode($stats), 'whatsapp');
    save_setting('_wa_crm_sync_cursor', '', 'internal');
    if (($stats['created'] || $stats['updated']) && microtime(true) < $deadline + 1.0) {
        wa_crm_segments_recount();
    }
    return $slice + ['complete' => true, 'created' => $stats['created'], 'updated' => $stats['updated'], 'stage_changes' => $stats['stage_changes']];
}

/** Listener for GIMT_* admission/enquiry/payment events: upsert the related contact immediately. */
function wa_crm_on_business_event(array $event, array $payload, ?array $contact): ?array
{
    try {
        $opts = ['live' => true];
        foreach (['enquiry_id' => 'enquiry_ids', 'admission_id' => 'admission_ids', 'student_id' => 'student_ids'] as $k => $o) {
            if (!empty($payload[$k]) && is_numeric($payload[$k])) {
                $opts[$o] = [(int) $payload[$k]];
            }
        }
        $stats = null;
        if (count($opts) > 1) {
            if (isset($opts['student_ids']) && !isset($opts['admission_ids'])) {
                $adm = db_value('SELECT admission_id FROM students WHERE id = ?', [$opts['student_ids'][0]]);
                if ($adm) {
                    $opts['admission_ids'] = [(int) $adm];
                }
            }
            $stats = wa_crm_sync(null, $opts);
        }
        // explicit consent captured by the form that raised the event
        $code = (string) ($event['event_code'] ?? $event['code'] ?? '');
        $phone = $payload['phone'] ?? $payload['whatsapp'] ?? null;
        $ph = $phone ? wa_crm_phone((string) $phone) : null;
        $c = $ph ? wa_contact_by_phone($ph['phone']) : ($contact ?: null);
        if ($c && (!empty($payload['whatsapp_consent']) || !empty($payload['consent']) || !empty($payload['opt_in'])) && !(int) $c['opt_in'] && empty($c['opted_out_at'])) {
            wa_contact_opt((int) $c['id'], true, 'Consent on ' . strtolower(str_replace(['GIMT_', '_'], ['', ' '], $code)));
        }
        // counselling booked (follow-up logged as a meeting / campus visit) → Counselling Scheduled
        if ($c && $code === 'GIMT_FOLLOWUP_LOGGED' && in_array($payload['type'] ?? '', ['meeting', 'campus_visit'], true)
            && wa_crm_stage_rank($c['stage']) < wa_crm_stage_rank('counselling_scheduled') && !in_array($c['stage'], wa_crm_terminal_stages(), true)) {
            wa_crm_set_stage((int) $c['id'], 'counselling_scheduled', 'event', 'Counselling ' . str_replace('_', ' ', (string) $payload['type']) . ' logged');
        }
        return $stats ? ['crm_sync' => array_intersect_key($stats, array_flip(['created', 'updated', 'stage_changes']))] : null;
    } catch (Throwable $e) {
        error_log('[GIMT] wa_crm_on_business_event: ' . $e->getMessage());
        return null;
    }
}

/* ============================================================================================ CRM → admission portal */

/**
 * CRM lead stage → ERP enquiry status (enquiries.status new|contacted|interested|not_interested|converted|closed).
 * Stages that need a real application (submitted, payment pending, confirmed) have no enquiry equivalent — staff turn
 * an enquiry into an application with "Convert to application" in Admissions › Enquiries.
 */
function wa_crm_enquiry_status_for_stage(?string $stage): ?string
{
    return [
        'new_enquiry' => 'new', 'contacted' => 'contacted', 'interested' => 'interested', 'counselling_scheduled' => 'interested',
        'application_started' => 'interested', 'not_interested' => 'not_interested',
    ][$stage ?? ''] ?? null;
}

/** Reference of an ERP enquiry: GIMT enquiries have no separate document number, the ERP shows them as "Enquiry #<id>". */
function wa_crm_enquiry_no(int $id): string
{
    return '#' . $id;
}

/**
 * Create — or link — the ERP admission enquiry of a WhatsApp contact, so leads that start on WhatsApp (inbox, Leads
 * board, bot, workflows) reach Admissions › Enquiries.
 *
 * Idempotent: a contact already linked to an enquiry returns it; when the number already has an open enquiry (new /
 * contacted / interested — the ERP's own duplicate rule) the contact is linked to that one. Both return created = false.
 * Contacts that are students, alumni, staff or already linked to an application / student record are refused.
 * A new enquiry is written like one added in the ERP: source 'whatsapp' (or $opts['source'] when it is an
 * admission_sources() key), programme / city / e-mail / counsellor from the contact, status from the lead stage
 * (wa_crm_enquiry_status_for_stage), priority high from "Interested" on, follow-up tomorrow, activity log, and the
 * "New enquiry" / "Enquiry assigned to you" notifications. No WhatsApp event is raised: GIMT_ENQUIRY_RECEIVED /
 * GIMT_ENQUIRY_CREATED_ADMIN would send the website welcome to someone who is already chatting on WhatsApp.
 *
 * $opts: name, email, city, program_id, program_interest, assigned_to (user id; default the contact's counsellor),
 *        priority (high|medium|low), status (new|contacted|interested|not_interested), message (what the lead asked;
 *        default their latest WhatsApp text), note (added to the enquiry notes), source, origin (inbox|leads|contact|
 *        bot|workflow|api — only changes the wording of notes and logs), quiet (no notifications).
 * Never throws. Returns ['ok', 'created', 'enquiry_id', 'enquiry_no', 'error', 'message', 'errors'] — errors maps the
 * option at fault (name, email, program_id, assigned_to, status, priority) to its message, like api_validate().
 */
function wa_crm_create_enquiry(int $contactId, array $opts = []): array
{
    $result = fn (bool $ok, bool $created, ?int $eid, ?string $error, string $message, array $errors = []) => [
        'ok' => $ok, 'created' => $created, 'enquiry_id' => $eid, 'enquiry_no' => $eid ? wa_crm_enquiry_no($eid) : null, 'error' => $error, 'message' => $message,
        'errors' => $errors,
    ];
    try {
        if (!function_exists('admission_phone_key')) {
            require_once APP_ROOT . '/app/services/admissions.php';
        }
        $origins = ['inbox' => 'the WhatsApp inbox', 'leads' => 'WhatsApp › Admission Leads', 'contact' => 'the WhatsApp contact record', 'bot' => 'the WhatsApp AI bot',
            'workflow' => 'a WhatsApp workflow', 'api' => 'WhatsApp'];
        $origin = is_string($opts['origin'] ?? null) && isset($origins[$opts['origin']]) ? $opts['origin'] : 'api';
        $text = fn ($v, int $max) => is_scalar($v) ? mb_substr(trim(preg_replace('/[ \t]+/u', ' ', (string) $v)), 0, $max) : '';

        // ---- validate the options before touching anything
        $errors = [];
        $email = $text($opts['email'] ?? '', 190);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid e-mail address.';
        }
        $programId = isset($opts['program_id']) && is_scalar($opts['program_id']) && (int) $opts['program_id'] > 0 ? (int) $opts['program_id'] : null;
        if ($programId && !db_value('SELECT id FROM programs WHERE id = ?', [$programId])) {
            $errors['program_id'] = 'Choose a valid programme.';
        }
        $assignee = null;
        foreach (['assigned_to', 'counsellor_id'] as $k) {
            if (isset($opts[$k]) && is_scalar($opts[$k]) && (int) $opts[$k] > 0) {
                $assignee = (int) $opts[$k];
                break;
            }
        }
        if ($assignee && !db_value("SELECT id FROM users WHERE id = ? AND status = 'active'", [$assignee])) {
            $errors['assigned_to'] = 'Choose an active counsellor.';
        }
        $status = $text($opts['status'] ?? '', 20);
        if ($status !== '' && !in_array($status, ['new', 'contacted', 'interested', 'not_interested'], true)) {
            $errors['status'] = 'Choose a valid enquiry status.';
        }
        $priority = $text($opts['priority'] ?? '', 10);
        if ($priority !== '' && !in_array($priority, ['high', 'medium', 'low'], true)) {
            $errors['priority'] = 'Choose a valid priority.';
        }
        $source = $text($opts['source'] ?? '', 30) ?: 'whatsapp';
        if (!isset(admission_sources()[$source])) {
            $source = 'whatsapp';
        }
        if ($errors) {
            return $result(false, false, null, implode(' ', $errors), implode(' ', $errors), $errors);
        }
        $by = function_exists('current_user') && wa_user_id() ? (string) (current_user()['name'] ?? '') : '';

        $out = db_transaction(function () use ($contactId, $opts, $text, $email, $programId, $assignee, $status, $priority, $source, $origin, $origins, $by) {
            // row lock: two clicks / a bot and an agent at the same moment create one enquiry, not two
            $c = db_row('SELECT * FROM wa_contacts WHERE id = ? FOR UPDATE', [$contactId]);
            if (!$c) {
                return ['error' => 'This WhatsApp contact no longer exists.'];
            }
            if (!empty($c['enquiry_id']) && ($e = db_row('SELECT id FROM enquiries WHERE id = ?', [(int) $c['enquiry_id']]))) {
                return ['linked' => 'already', 'id' => (int) $e['id'], 'contact' => $c];
            }
            if (!empty($c['student_id']) || !empty($c['admission_id']) || in_array($c['contact_type'], ['student', 'alumni', 'staff'], true)) {
                $what = !empty($c['admission_id']) ? 'an application' : (in_array($c['contact_type'], ['staff'], true) ? 'a staff member' : 'a student record');
                return ['error' => 'This contact is already linked to ' . $what . ' in the admission portal — an admission enquiry is not needed.'];
            }
            $key = admission_phone_key((string) $c['phone']);
            if (strlen($key) < 10) {
                return ['error' => 'The WhatsApp number is too short for an admission enquiry (10-digit mobile number needed).'];
            }
            $open = db_row('SELECT id, source FROM enquiries WHERE ' . admission_phone_sql('phone') . " = ? AND status NOT IN ('converted','closed','not_interested') ORDER BY id DESC LIMIT 1", [$key]);
            if ($open) {
                db_update('wa_contacts', ['enquiry_id' => (int) $open['id']] + (empty($c['lead_source']) ? ['lead_source' => $open['source']] : []), 'id = ?', [$contactId]);
                return ['linked' => 'open', 'id' => (int) $open['id'], 'contact' => $c];
            }

            $name = $text($opts['name'] ?? '', 150) ?: mb_substr(trim((string) $c['name']), 0, 150);
            if ($name === '') {
                return ['error' => 'Add the contact’s name first — an admission enquiry needs the student’s name.', 'field' => 'name'];
            }
            $programId = $programId ?: ((int) $c['program_id'] ?: null);
            $interest = $programId ? null : ($text($opts['program_interest'] ?? '', 190) ?: ($c['program_name'] ? mb_substr((string) $c['program_name'], 0, 190) : null));
            $city = $text($opts['city'] ?? '', 80) ?: ($c['city'] ?: null);
            $email = $email ?: ($c['email'] ?: null);
            $assignee = $assignee ?: ((int) $c['counsellor_id'] ?: null);
            $status = $status ?: (wa_crm_enquiry_status_for_stage($c['stage']) ?? 'interested');
            $priority = $priority ?: (wa_crm_stage_rank($c['stage']) >= wa_crm_stage_rank('interested') ? 'high' : 'medium');
            $message = is_scalar($opts['message'] ?? null) ? mb_substr(trim((string) $opts['message']), 0, 5000) : '';
            if ($message === '') {
                // what the lead actually wrote (their latest text message) gives the counsellor the context
                $message = mb_substr(trim((string) db_value("SELECT body FROM wa_messages WHERE contact_id = ? AND direction = 'in' AND type = 'text' AND body IS NOT NULL AND body <> '' ORDER BY id DESC LIMIT 1", [$contactId])), 0, 5000);
            }
            $location = implode(', ', array_filter([$city, $c['state'] ?? null]));
            $note = is_scalar($opts['note'] ?? null) ? mb_substr(trim((string) $opts['note']), 0, 1000) : '';
            $notes = 'WhatsApp lead: created from ' . $origins[$origin] . ($by !== '' ? ' by ' . $by : '') . ' (WhatsApp contact #' . $contactId . ').'
                . "\nWhatsApp chat: admin/whatsapp/inbox?contact=" . $contactId
                . ($location !== '' ? "\nLocation: " . $location : '')
                . ($note !== '' ? "\n" . $note : '');
            $phone = mb_substr(($c['country_code'] ?: '+91') . ' ' . $c['national_number'], 0, 20);
            $eid = db_insert('enquiries', [
                'name' => $name, 'email' => $email, 'phone' => $phone, 'city' => $city, 'program_id' => $programId, 'program_interest' => $interest,
                'source' => $source, 'message' => $message !== '' ? $message : null, 'status' => $status, 'priority' => $priority, 'assigned_to' => $assignee,
                'follow_up_date' => $status === 'not_interested' ? null : date('Y-m-d', strtotime('+1 day')), 'notes' => $notes, 'ip_address' => null,
            ]);
            // link the contact; erp_stage = what the ERP → CRM sync will derive from this status, so it never moves the lead
            $link = ['enquiry_id' => $eid, 'erp_stage' => wa_crm_stage_from_enquiry($status), 'synced_at' => wa_crm_now()];
            if (empty($c['lead_source'])) {
                $link['lead_source'] = $source;
            }
            foreach (['email' => $email, 'city' => $city, 'counsellor_id' => $assignee] as $k => $v) {
                if ($v && ($c[$k] === null || $c[$k] === '')) {
                    $link[$k] = $v; // fill blanks only — the contact's own data wins
                }
            }
            if ($programId && empty($c['program_id'])) {
                $link['program_id'] = $programId;
                $link['program_name'] = mb_substr((string) db_value('SELECT name FROM programs WHERE id = ?', [$programId]), 0, 190) ?: null;
            }
            if ($c['contact_type'] === 'other') {
                $link['contact_type'] = 'lead';
            }
            db_update('wa_contacts', $link, 'id = ?', [$contactId]);
            return ['created' => true, 'id' => $eid, 'contact' => $c, 'name' => $name, 'phone' => $phone, 'key' => $key, 'assignee' => $assignee, 'program_id' => $programId, 'interest' => $interest];
        });

        if (!empty($out['error'])) {
            return $result(false, false, null, $out['error'], $out['error'], !empty($out['field']) ? [$out['field'] => $out['error']] : []);
        }
        $eid = (int) $out['id'];
        $c = $out['contact'];
        $label = trim(($c['name'] ?: 'WhatsApp contact') . ' (+' . $c['phone'] . ')');
        $conv = db_value('SELECT id FROM wa_conversations WHERE contact_id = ?', [$contactId]);
        if (empty($out['created'])) {
            if ($out['linked'] === 'open') {
                log_activity('update', 'enquiries', $eid, sprintf('Linked WhatsApp contact %s to the open enquiry #%d (same mobile number)', $label, $eid));
                wa_agent_log('enquiry', $conv ? (int) $conv : null, $contactId, 'Linked to the open enquiry ' . wa_crm_enquiry_no($eid));
                return $result(true, false, $eid, null, 'This number already has an open admission enquiry (' . wa_crm_enquiry_no($eid) . ') — the contact is now linked to it.');
            }
            return $result(true, false, $eid, null, 'Already linked to admission enquiry ' . wa_crm_enquiry_no($eid) . '.');
        }
        $what = $out['program_id'] ? (string) db_value('SELECT short_name FROM programs WHERE id = ?', [(int) $out['program_id']]) : ((string) $out['interest'] ?: 'a program');
        log_activity('create', 'enquiries', $eid, sprintf('WhatsApp enquiry from %s for %s (created from %s)', $out['name'], $what, $origins[$origin]));
        wa_agent_log('enquiry', $conv ? (int) $conv : null, $contactId, 'Admission enquiry ' . wa_crm_enquiry_no($eid) . ' created');
        if (empty($opts['quiet']) && !wa_crm_quiet()) {
            $url = 'admin/enquiries?q=' . rawurlencode($out['key']);
            notify('perm:enquiries', 'enquiry', 'New WhatsApp enquiry', $out['name'] . ' (' . $out['phone'] . ') is interested in ' . $what . '.', $url, 'message-circle');
            if ($out['assignee'] && (int) $out['assignee'] !== (int) wa_user_id()) {
                notify((int) $out['assignee'], 'enquiry', 'Enquiry assigned to you', $out['name'] . ' (' . $out['phone'] . ') — WhatsApp lead, ' . $what . '.', $url, 'message-circle');
            }
        }
        return $result(true, true, $eid, null, 'Admission enquiry ' . wa_crm_enquiry_no($eid) . ' created in Admissions › Enquiries.');
    } catch (Throwable $e) {
        error_log('[GIMT] wa_crm_create_enquiry: ' . $e->getMessage());
        return $result(false, false, null, 'The admission enquiry could not be created. Please try again.', 'The admission enquiry could not be created. Please try again.');
    }
}

/**
 * Write a lead-stage change made by staff in the WhatsApp CRM (Leads board, contact edit, bulk move) back to the linked
 * ERP enquiry, so Admissions › Enquiries shows the same status. Only stages with an enquiry equivalent are mirrored
 * (wa_crm_enquiry_status_for_stage); converted / closed enquiries are never touched. erp_stage is set to the stage the
 * sync derives from the new status, so the next ERP → CRM sync does not move the lead again. No WhatsApp event is
 * raised — the CRM stage change itself already drives follow-ups and workflows. Returns the new status or null.
 */
function wa_crm_enquiry_writeback(int $contactId, string $stage): ?string
{
    $status = wa_crm_enquiry_status_for_stage($stage);
    if ($status === null) {
        return null;
    }
    if (!function_exists('enquiry_statuses')) {
        require_once APP_ROOT . '/app/services/admissions.php';
    }
    $c = db_row('SELECT id, name, phone, enquiry_id, admission_id, student_id FROM wa_contacts WHERE id = ?', [$contactId]);
    if (!$c || empty($c['enquiry_id'])) {
        return null;
    }
    $e = db_row('SELECT id, status FROM enquiries WHERE id = ?', [(int) $c['enquiry_id']]);
    if (!$e || in_array($e['status'], ['converted', 'closed'], true) || $e['status'] === $status) {
        return null;
    }
    db_update('enquiries', ['status' => $status], 'id = ?', [(int) $e['id']]);
    if (empty($c['admission_id']) && empty($c['student_id'])) {
        db_update('wa_contacts', ['erp_stage' => wa_crm_stage_from_enquiry($status)], 'id = ?', [$contactId]);
    }
    $labels = enquiry_statuses();
    log_activity('update', 'enquiries', $e['id'], sprintf('Enquiry #%d: status %s → %s (WhatsApp lead %s moved to %s)', $e['id'], $labels[$e['status']] ?? $e['status'],
        $labels[$status] ?? $status, ($c['name'] ?: '+' . $c['phone']), wa_crm_stage_name($stage)));
    return $status;
}

/* ============================================================================================ duplicates + merge */

function wa_crm_name_key(?string $name): string
{
    $n = mb_strtolower(trim((string) $name));
    $n = preg_replace('/^(mr|mrs|ms|dr|shri|smt)\.?\s+/u', '', $n);
    return preg_replace('/[^a-z\p{L}]+/u', '', $n);
}

/**
 * Duplicate report: same person with different numbers (same email, same application/enquiry, same name + programme)
 * and same number with different names in the ERP data (student using a parent's phone, siblings, typos).
 */
function wa_crm_duplicates(): array
{
    $groups = [];
    $seen = [];
    $addGroup = function (string $reason, array $ids) use (&$groups, &$seen) {
        sort($ids);
        $key = implode(',', $ids);
        if (count($ids) < 2 || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $groups[] = ['reason' => $reason, 'ids' => $ids];
    };
    foreach (db_all("SELECT LOWER(email) AS k, GROUP_CONCAT(id) AS ids FROM wa_contacts WHERE email IS NOT NULL AND email <> '' AND status <> 'archived' GROUP BY LOWER(email) HAVING COUNT(*) > 1 LIMIT 60") as $r) {
        $addGroup('Same email ' . $r['k'], array_map('intval', explode(',', $r['ids'])));
    }
    foreach (['admission_id' => 'Same application', 'enquiry_id' => 'Same enquiry'] as $col => $label) {
        foreach (db_all("SELECT $col AS k, GROUP_CONCAT(id) AS ids FROM wa_contacts WHERE $col IS NOT NULL AND contact_type <> 'guardian' AND status <> 'archived' GROUP BY $col HAVING COUNT(*) > 1 LIMIT 60") as $r) {
            $addGroup($label . ' #' . $r['k'], array_map('intval', explode(',', $r['ids'])));
        }
    }
    $byName = [];
    foreach (db_all("SELECT id, name, program_id FROM wa_contacts WHERE name IS NOT NULL AND contact_type IN ('lead','applicant','student') AND status <> 'archived'") as $r) {
        $k = wa_crm_name_key($r['name']);
        if (strlen($k) >= 6 && $r['program_id']) {
            $byName[$k . '|' . $r['program_id']][] = (int) $r['id'];
        }
    }
    foreach ($byName as $k => $ids) {
        if (count($ids) > 1 && count($ids) <= 5) {
            $addGroup('Same name and programme', $ids);
        }
    }
    $groups = array_slice($groups, 0, 60);
    $allIds = array_values(array_unique(array_merge(...array_map(fn ($g) => $g['ids'], $groups ?: [['ids' => []]]))));
    $contacts = [];
    if ($allIds) {
        foreach (wa_crm_decorate(db_all('SELECT ' . wa_crm_list_select() . ' FROM ' . wa_crm_list_from() . ' WHERE c.id IN (' . implode(',', array_fill(0, count($allIds), '?')) . ')', $allIds)) as $c) {
            $c['messages'] = (int) db_value('SELECT COUNT(*) FROM wa_messages WHERE contact_id = ?', [$c['id']]);
            $contacts[$c['id']] = $c;
        }
    }
    $samePerson = [];
    foreach ($groups as $g) {
        $members = array_values(array_filter(array_map(fn ($id) => $contacts[$id] ?? null, $g['ids'])));
        if (count($members) > 1) {
            $samePerson[] = ['reason' => $g['reason'], 'contacts' => $members];
        }
    }

    // same number, different names (from the ERP records behind each phone)
    $sameNumber = [];
    $by = wa_crm_collect([]);
    $conflicts = [];
    foreach ($by as $phone => $cands) {
        $names = [];
        foreach ($cands as $c) {
            $k = wa_crm_name_key($c['name'] ?? '');
            if ($k !== '' && !isset($names[$k])) {
                $label = ['enquiry' => 'Enquiry', 'admission' => 'Application', 'student' => 'Student', 'guardian' => 'Guardian'][$c['kind']] ?? $c['kind'];
                $names[$k] = ['name' => $c['name'], 'source' => $label . ' #' . $c['ref'] . (!empty($c['ward']) ? ' (parent of ' . $c['ward'] . ')' : '')];
            }
        }
        if (count($names) > 1) {
            $conflicts[(string) $phone] = array_values($names);
        }
        if (count($conflicts) >= 80) {
            break;
        }
    }
    if ($conflicts) {
        $phones = array_keys($conflicts);
        $rows = db_all('SELECT id, phone, name, contact_type FROM wa_contacts WHERE phone IN (' . implode(',', array_fill(0, count($phones), '?')) . ')', array_map('strval', $phones));
        foreach ($rows as $r) {
            $sameNumber[] = ['contact_id' => (int) $r['id'], 'phone' => wa_crm_show_phone($r['phone']), 'name' => $r['name'], 'contact_type' => $r['contact_type'], 'names' => $conflicts[$r['phone']]];
        }
    }
    return ['same_person' => $samePerson, 'same_number' => $sameNumber, 'checked_at' => wa_crm_now()];
}

/**
 * Merge contacts into $keepId: messages move to the kept conversation, notes / follow-ups / tags / history / campaign
 * recipients / event logs move over, empty fields are filled from the merged contacts, then they are deleted.
 */
function wa_crm_merge(int $keepId, array $mergeIds): array
{
    $mergeIds = array_values(array_diff(array_unique(array_filter(array_map('intval', $mergeIds))), [$keepId]));
    $keep = db_row('SELECT * FROM wa_contacts WHERE id = ?', [$keepId]);
    if (!$keep) {
        throw new CrudException('The contact to keep was not found.');
    }
    if (!$mergeIds) {
        throw new CrudException('Choose at least one contact to merge.');
    }
    return db_transaction(function () use ($keep, $keepId, $mergeIds) {
        $conv = wa_conversation_for($keepId);
        $convId = (int) $conv['id'];
        $merged = [];
        foreach ($mergeIds as $mid) {
            $m = db_row('SELECT * FROM wa_contacts WHERE id = ?', [$mid]);
            if (!$m) {
                continue;
            }
            db_exec('UPDATE wa_messages SET contact_id = ?, conversation_id = ? WHERE contact_id = ?', [$keepId, $convId, $mid]);
            db_exec('UPDATE wa_notes SET contact_id = ?, conversation_id = ? WHERE contact_id = ?', [$keepId, $convId, $mid]);
            db_exec("UPDATE wa_followups SET status = 'cancelled', note = 'Merged into another contact' WHERE contact_id = ? AND status = 'scheduled'", [$mid]);
            db_exec('UPDATE wa_followups SET contact_id = ? WHERE contact_id = ?', [$keepId, $mid]);
            db_exec('INSERT IGNORE INTO wa_contact_tags (contact_id, tag_id, created_at) SELECT ?, tag_id, created_at FROM wa_contact_tags WHERE contact_id = ?', [$keepId, $mid]);
            db_exec('UPDATE wa_contact_stage_history SET contact_id = ? WHERE contact_id = ?', [$keepId, $mid]);
            db_exec('UPDATE wa_campaign_recipients SET contact_id = ? WHERE contact_id = ?', [$keepId, $mid]);
            db_exec('UPDATE wa_event_logs SET contact_id = ? WHERE contact_id = ?', [$keepId, $mid]);
            db_exec('UPDATE wa_workflow_runs SET contact_id = ? WHERE contact_id = ?', [$keepId, $mid]);
            db_exec('UPDATE wa_agent_activity SET contact_id = ?, conversation_id = ? WHERE contact_id = ?', [$keepId, $convId, $mid]);
            if (!db_value('SELECT id FROM wa_bot_sessions WHERE contact_id = ?', [$keepId])) {
                db_exec('UPDATE wa_bot_sessions SET contact_id = ? WHERE contact_id = ?', [$keepId, $mid]);
            }
            $fill = [];
            foreach (['name', 'email', 'student_id', 'enquiry_id', 'admission_id', 'application_no', 'program_id', 'program_name', 'batch', 'city', 'state', 'admission_status', 'payment_status', 'counsellor_id', 'lead_source', 'notes'] as $k) {
                if (($keep[$k] === null || $keep[$k] === '') && $m[$k] !== null && $m[$k] !== '') {
                    $fill[$k] = $m[$k];
                    $keep[$k] = $m[$k];
                }
            }
            if (empty($keep['alt_phone'])) {
                $fill['alt_phone'] = $m['phone'];
                $keep['alt_phone'] = $m['phone'];
            }
            foreach (['last_inbound_at', 'last_outbound_at', 'last_message_at'] as $k) {
                if ($m[$k] && (!$keep[$k] || $m[$k] > $keep[$k])) {
                    $fill[$k] = $m[$k];
                    $keep[$k] = $m[$k];
                }
            }
            if ((int) $m['opt_in'] && !(int) $keep['opt_in'] && empty($keep['opted_out_at'])) {
                $fill += ['opt_in' => 1, 'opt_in_at' => $m['opt_in_at'], 'opt_in_source' => $m['opt_in_source']];
                $keep['opt_in'] = 1;
            }
            if (wa_crm_type_rank($m['contact_type']) > wa_crm_type_rank($keep['contact_type'])) {
                $fill['contact_type'] = $m['contact_type'];
                $keep['contact_type'] = $m['contact_type'];
            }
            if ($fill) {
                db_update('wa_contacts', $fill, 'id = ?', [$keepId]);
            }
            db_delete('wa_contacts', 'id = ?', [$mid]);
            $merged[] = ($m['name'] ?: '') . ' (+' . $m['phone'] . ')';
        }
        // refresh the kept conversation summary
        $last = db_row('SELECT body, direction, created_at, type, template_name FROM wa_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1', [$convId]);
        if ($last) {
            db_update('wa_conversations', [
                'last_message_at' => $last['created_at'], 'last_message_direction' => $last['direction'],
                'last_message_preview' => mb_substr((string) ($last['type'] === 'template' ? 'Template: ' . $last['template_name'] : $last['body']), 0, 255),
            ], 'id = ?', [$convId]);
        }
        return ['kept' => $keepId, 'merged' => $merged];
    });
}

/* ============================================================================================ leads board */

/** Filters specific to the leads screens (program, counsellor, source, date) on top of the contact filters. */
function wa_crm_leads_filters(array $in): array
{
    $f = wa_crm_clean_filters($in);
    $f['scope'] = 'leads';
    if (!empty($in['from'])) {
        $f['created_from'] = (string) $in['from'];
    }
    if (!empty($in['to'])) {
        $f['created_to'] = (string) $in['to'];
    }
    return $f;
}

function wa_crm_leads_board(array $in, int $perStage = 30): array
{
    $f = wa_crm_leads_filters($in);
    unset($f['stage']);
    $args = [];
    $where = wa_crm_filter_where($f, $args);
    $counts = db_pairs("SELECT c.stage, COUNT(*) FROM wa_contacts c WHERE $where GROUP BY c.stage", $args);
    $columns = [];
    foreach (wa_crm_stages() as $key => $s) {
        $a = $args;
        $a[] = $key;
        $order = in_array($key, wa_crm_terminal_stages(), true) ? 'c.stage_changed_at IS NULL, c.stage_changed_at DESC' : 'next_followup_at IS NULL, next_followup_at ASC, c.stage_changed_at DESC';
        $cards = wa_crm_decorate(db_all('SELECT ' . wa_crm_list_select() . ' FROM ' . wa_crm_list_from() . " WHERE $where AND c.stage = ? ORDER BY $order, c.id DESC LIMIT " . (int) $perStage, $a));
        $columns[] = ['key' => $key, 'name' => $s['name'], 'color' => $s['color'], 'count' => (int) ($counts[$key] ?? 0), 'cards' => $cards];
    }
    return ['columns' => $columns, 'total' => array_sum(array_map('intval', $counts))];
}

/** Funnel + conversion statistics for the leads screen. */
function wa_crm_leads_stats(array $in): array
{
    $f = wa_crm_leads_filters($in);
    unset($f['stage']);
    $args = [];
    $where = wa_crm_filter_where($f, $args);
    $counts = array_map('intval', db_pairs("SELECT c.stage, COUNT(*) FROM wa_contacts c WHERE $where GROUP BY c.stage", $args));
    $total = array_sum($counts);
    $funnel = [];
    foreach (wa_crm_stage_defaults() as $k => $s) {
        if ($k === 'not_interested') {
            continue;
        }
        $reached = 0;
        foreach ($counts as $sk => $n) {
            if ($sk !== 'not_interested' && wa_crm_stage_rank($sk) >= $s['rank']) {
                $reached += $n;
            }
        }
        $funnel[] = ['key' => $k, 'name' => wa_crm_stage_name($k), 'count' => $counts[$k] ?? 0, 'reached' => $reached];
    }
    $confirmed = $counts['admission_confirmed'] ?? 0;
    $lost = $counts['not_interested'] ?? 0;
    $bySource = db_all("SELECT COALESCE(c.lead_source, 'unknown') AS source, COUNT(*) AS total, COALESCE(SUM(c.stage = 'admission_confirmed'), 0) AS confirmed FROM wa_contacts c WHERE $where GROUP BY COALESCE(c.lead_source, 'unknown') ORDER BY total DESC LIMIT 10", $args);
    $byCounsellor = db_all("SELECT c.counsellor_id, COALESCE(u.name, 'Unassigned') AS name, COUNT(*) AS total, COALESCE(SUM(c.stage = 'admission_confirmed'), 0) AS confirmed FROM wa_contacts c LEFT JOIN users u ON u.id = c.counsellor_id WHERE $where GROUP BY c.counsellor_id, u.name ORDER BY total DESC LIMIT 10", $args);
    $a2 = $args;
    $a2[] = date('Y-m-d 00:00:00', strtotime('-6 days'));
    $new7 = (int) db_value("SELECT COUNT(*) FROM wa_contacts c WHERE $where AND c.created_at >= ?", $a2);
    $a3 = $args;
    $dueFollow = (int) db_value("SELECT COUNT(DISTINCT c.id) FROM wa_contacts c JOIN wa_followups fu ON fu.contact_id = c.id AND fu.status = 'scheduled' AND fu.due_at <= '" . date('Y-m-d 23:59:59') . "' WHERE $where", $a3);
    return [
        'total' => $total, 'confirmed' => $confirmed, 'lost' => $lost, 'open' => $total - $confirmed - $lost, 'new_7d' => $new7, 'followups_due' => $dueFollow,
        'conversion_rate' => $total ? round($confirmed * 100 / $total, 1) : 0,
        'counts' => (object) $counts, 'funnel' => $funnel,
        'by_source' => array_map(fn ($r) => ['source' => $r['source'], 'label' => label_from_key($r['source']), 'total' => (int) $r['total'], 'confirmed' => (int) $r['confirmed']], $bySource),
        'by_counsellor' => array_map(fn ($r) => ['id' => $r['counsellor_id'] ? (int) $r['counsellor_id'] : null, 'name' => $r['name'], 'total' => (int) $r['total'], 'confirmed' => (int) $r['confirmed']], $byCounsellor),
    ];
}

/* ============================================================================================ follow-up engine */

function wa_crm_stop_conditions($json): array
{
    $d = ['on_reply' => true, 'on_stage_change' => true, 'on_opt_out' => true, 'on_admission_confirmed' => true];
    $v = is_array($json) ? $json : (json_decode((string) $json, true) ?: []);
    foreach ($d as $k => $def) {
        if (array_key_exists($k, $v)) {
            $d[$k] = (bool) $v[$k];
        }
    }
    // an opt-out (STOP) always ends automated follow-ups — WhatsApp policy, not a per-stage choice
    $d['on_opt_out'] = true;
    return $d;
}

/** Stage config row (wa_followup_stages) or null. */
function wa_crm_stage_config(string $stage): ?array
{
    $cfg = wa_crm_stages()[$stage]['config'] ?? null;
    if (!$cfg) {
        return null;
    }
    $cfg['stop'] = wa_crm_stop_conditions($cfg['stop_conditions'] ?? null);
    return $cfg;
}

/**
 * A contact entered $to: cancel pending follow-ups (stop conditions), auto-assign the stage counsellor, schedule
 * follow-up #1 and raise the next-action task. Returns a short summary.
 */
function wa_crm_followup_on_stage(int $contactId, ?string $from, string $to): array
{
    $out = ['cancelled' => 0, 'scheduled' => null, 'assigned' => null];
    $contact = db_row('SELECT * FROM wa_contacts WHERE id = ?', [$contactId]);
    if (!$contact) {
        return $out;
    }
    $now = wa_crm_now();
    // cancel scheduled follow-ups of other stages
    foreach (db_all("SELECT id, stage_key FROM wa_followups WHERE contact_id = ? AND status = 'scheduled' AND stage_key <> ?", [$contactId, $to]) as $f) {
        $stop = (wa_crm_stage_config($f['stage_key']) ?? ['stop' => wa_crm_stop_conditions(null)])['stop'];
        if ($stop['on_stage_change'] || ($to === 'admission_confirmed' && $stop['on_admission_confirmed']) || $to === 'not_interested') {
            db_update('wa_followups', ['status' => 'cancelled', 'note' => 'Stage changed to ' . wa_crm_stage_name($to)], 'id = ?', [(int) $f['id']]);
            $out['cancelled']++;
        }
    }
    $cfg = wa_crm_stage_config($to);
    if (!$cfg || !(int) $cfg['is_active']) {
        return $out;
    }
    if (!in_array($contact['contact_type'], ['lead', 'applicant'], true) || $contact['status'] !== 'active') {
        return $out;
    }
    // counsellor auto-assignment (only for contacts without one; manual ownership wins)
    if (!empty($cfg['counsellor_id']) && empty($contact['counsellor_id'])) {
        db_update('wa_contacts', ['counsellor_id' => (int) $cfg['counsellor_id']], 'id = ?', [$contactId]);
        $contact['counsellor_id'] = (int) $cfg['counsellor_id'];
        $out['assigned'] = (int) $cfg['counsellor_id'];
    }
    $optedOut = !(int) $contact['opt_in'] && !empty($contact['opted_out_at']);
    if (!(int) $cfg['is_terminal'] && (int) $cfg['max_followups'] > 0 && !empty($cfg['template_id']) && !$optedOut) {
        $exists = db_value("SELECT id FROM wa_followups WHERE contact_id = ? AND stage_key = ? AND status = 'scheduled'", [$contactId, $to]);
        if (!$exists) {
            $due = date('Y-m-d H:i:s', strtotime($now) + max(0, (int) $cfg['delay_hours']) * 3600);
            $out['scheduled'] = db_insert('wa_followups', [
                'contact_id' => $contactId, 'stage_key' => $to, 'attempt' => 1, 'template_id' => (int) $cfg['template_id'], 'due_at' => $due, 'status' => 'scheduled',
            ]);
        }
    }
    if (!empty($cfg['next_action']) && !wa_crm_quiet()) {
        $target = $contact['counsellor_id'] ? (int) $contact['counsellor_id'] : 'perm:whatsapp_contacts';
        notify($target, 'whatsapp', 'Next action: ' . ($contact['name'] ?: '+' . $contact['phone']), $cfg['next_action'] . ' (' . wa_crm_stage_name($to) . ')', 'admin/whatsapp/contacts/' . $contactId, 'calendar-clock');
    }
    return $out;
}

/** Cancel scheduled follow-ups of a contact whose stage config stops on $condition. */
function wa_crm_followups_stop(int $contactId, string $condition, string $note): int
{
    $n = 0;
    foreach (db_all("SELECT id, stage_key FROM wa_followups WHERE contact_id = ? AND status = 'scheduled'", [$contactId]) as $f) {
        $stop = (wa_crm_stage_config($f['stage_key']) ?? ['stop' => wa_crm_stop_conditions(null)])['stop'];
        if (!empty($stop[$condition])) {
            db_update('wa_followups', ['status' => 'cancelled', 'note' => $note], 'id = ?', [(int) $f['id']]);
            $n++;
        }
    }
    return $n;
}

function wa_crm_on_message_received(array $event, array $payload, ?array $contact): ?array
{
    try {
        $id = (int) ($payload['contact_id'] ?? ($contact['id'] ?? 0));
        if (!$id) {
            return null;
        }
        $n = wa_crm_followups_stop($id, 'on_reply', 'Contact replied');
        return $n ? ['followups_cancelled' => $n] : null;
    } catch (Throwable $e) {
        error_log('[GIMT] wa_crm_on_message_received: ' . $e->getMessage());
        return null;
    }
}

function wa_crm_on_opted_out(array $event, array $payload, ?array $contact): ?array
{
    try {
        $id = (int) ($payload['contact_id'] ?? ($contact['id'] ?? 0));
        if (!$id) {
            return null;
        }
        $n = wa_crm_followups_stop($id, 'on_opt_out', 'Contact opted out');
        return $n ? ['followups_cancelled' => $n] : null;
    } catch (Throwable $e) {
        error_log('[GIMT] wa_crm_on_opted_out: ' . $e->getMessage());
        return null;
    }
}

/**
 * Process one follow-up: checks stop conditions, business hours and consent, then sends the stage template.
 * $manual = "Send now" from the Follow-up Manager (ignores business hours and a paused stage).
 * Returns ['status' => sent|skipped|cancelled|failed|rescheduled|busy, 'note' => …, 'message_id' => ?int]
 * (busy = another run claimed it first; nothing was changed).
 */
function wa_crm_followup_process(array $f, bool $manual = false): array
{
    $id = (int) $f['id'];
    // claim the row first: the worker tick, "Send due follow-ups" and "Send now" can run at the same time, and only
    // one of them may send it and schedule the next attempt (stale claims are released by wa_crm_followups_tick)
    if (db_exec("UPDATE wa_followups SET status = 'processing' WHERE id = ? AND status = 'scheduled'", [$id]) !== 1) {
        return ['status' => 'busy', 'note' => 'This follow-up is already being processed or is no longer scheduled.', 'message_id' => null];
    }
    $finish = function (string $status, string $note, ?int $messageId = null) use ($id) {
        db_update('wa_followups', ['status' => $status, 'note' => mb_substr($note, 0, 255), 'message_id' => $messageId], 'id = ?', [$id]);
        return ['status' => $status, 'note' => $note, 'message_id' => $messageId];
    };
    try {
        return wa_crm_followup_run($f, $manual, $finish);
    } catch (Throwable $e) {
        $finish('failed', 'Error: ' . mb_substr($e->getMessage(), 0, 200));
        throw $e;
    }
}

/** Body of wa_crm_followup_process() once the follow-up is claimed; $finish(status, note, messageId) closes it. */
function wa_crm_followup_run(array $f, bool $manual, callable $finish): array
{
    $id = (int) $f['id'];
    $contact = db_row('SELECT * FROM wa_contacts WHERE id = ?', [(int) $f['contact_id']]);
    if (!$contact) {
        return $finish('cancelled', 'Contact deleted');
    }
    $cfg = wa_crm_stage_config($f['stage_key']);
    $stop = $cfg['stop'] ?? wa_crm_stop_conditions(null);
    if ($contact['status'] !== 'active') {
        return $finish('skipped', 'Contact is ' . $contact['status']);
    }
    if (!(int) $contact['opt_in'] && !empty($contact['opted_out_at'])) {
        return $finish('skipped', 'Contact opted out');
    }
    if ($contact['stage'] === 'admission_confirmed' && $f['stage_key'] !== 'admission_confirmed' && $stop['on_admission_confirmed']) {
        return $finish('cancelled', 'Admission confirmed');
    }
    if ($contact['stage'] !== $f['stage_key'] && $stop['on_stage_change']) {
        return $finish('cancelled', 'Stage changed to ' . wa_crm_stage_name($contact['stage']));
    }
    if ($stop['on_reply'] && !empty($contact['last_inbound_at']) && $contact['last_inbound_at'] > $f['created_at']) {
        return $finish('cancelled', 'Contact replied');
    }
    if (!$manual && (!$cfg || !(int) $cfg['is_active'])) {
        return $finish('skipped', 'Follow-ups are paused for ' . wa_crm_stage_name($f['stage_key']));
    }
    if (!$manual && !wa_in_business_hours()) {
        $next = wa_next_business_time(new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        db_update('wa_followups', ['status' => 'scheduled', 'due_at' => $next, 'note' => 'Moved to business hours'], 'id = ?', [$id]);
        return ['status' => 'rescheduled', 'note' => 'Outside business hours — moved to ' . $next, 'message_id' => null];
    }
    $tplRef = $f['template_id'] ?: ($cfg['template_id'] ?? null);
    $tpl = $tplRef ? wa_template_find((int) $tplRef) : null;
    if (!$tpl) {
        return $finish('skipped', 'No WhatsApp template set for this stage');
    }
    if (strtoupper((string) $tpl['category']) === 'MARKETING' && !(int) $contact['opt_in']) {
        return $finish('skipped', 'Not opted in (marketing template needs opt-in)');
    }
    $vars = wa_crm_followup_vars($contact, $cfg);
    $res = wa_send_template((int) $contact['id'], (int) $tpl['id'], $vars, [
        'sender_type' => 'followup', 'followup_id' => $id, 'priority' => 6, 'dedupe_key' => 'wa-followup:' . $id, 'sent_by' => $manual ? wa_user_id() : null,
    ]);
    if (empty($res['ok'])) {
        $code = (string) ($res['code'] ?? '');
        $msg = (string) ($res['error'] ?? 'Send failed');
        $policy = in_array($code, ['opted_out', 'blocked', 'invalid_phone', 'template_not_approved', 'missing_variables', 'window_closed'], true);
        return $finish($policy ? 'skipped' : 'failed', $msg . ($code ? " ($code)" : ''));
    }
    $msgId = isset($res['message_id']) ? (int) $res['message_id'] : null;
    $result = $finish('sent', 'Sent template ' . $tpl['name'] . ($manual ? ' (sent manually)' : ''), $msgId);
    // next attempt or exhausted
    $max = (int) ($cfg['max_followups'] ?? 0);
    $queued = (bool) db_value("SELECT id FROM wa_followups WHERE contact_id = ? AND stage_key = ? AND status = 'scheduled' LIMIT 1", [(int) $contact['id'], $f['stage_key']]);
    if ($cfg && (int) $f['attempt'] < $max && (int) $cfg['is_active'] && !$queued) {
        db_insert('wa_followups', [
            'contact_id' => (int) $contact['id'], 'stage_key' => $f['stage_key'], 'attempt' => (int) $f['attempt'] + 1, 'template_id' => $f['template_id'] ?: $cfg['template_id'],
            'due_at' => date('Y-m-d H:i:s', time() + max(1, (int) $cfg['delay_hours']) * 3600), 'status' => 'scheduled',
        ]);
    } elseif ($cfg && $max > 0 && (int) $f['attempt'] >= $max && !$queued && !wa_crm_quiet()) {
        $target = $contact['counsellor_id'] ? (int) $contact['counsellor_id'] : 'perm:whatsapp_contacts';
        notify($target, 'whatsapp', 'Follow-ups finished: ' . ($contact['name'] ?: '+' . $contact['phone']), 'All ' . $max . ' WhatsApp follow-ups for ' . wa_crm_stage_name($f['stage_key']) . ' were sent without a reply. ' . ($cfg['next_action'] ?: 'Call the student.'), 'admin/whatsapp/contacts/' . (int) $contact['id'], 'phone-call');
    }
    return $result;
}

/**
 * Template values for a follow-up: the contact / ERP values from the core, plus values only the admission pipeline
 * knows (pending documents, counselling date, brochure link, support number) and finally the stage rule's defaults
 * (e.g. last_date) for anything still empty.
 */
function wa_crm_followup_vars(array $contact, ?array $cfg = null): array
{
    $vars = wa_variables_for_contact($contact);
    $fill = function (string $k, $v) use (&$vars) {
        if ($v !== null && $v !== '' && is_scalar($v) && (!isset($vars[$k]) || trim((string) $vars[$k]) === '')) {
            $vars[$k] = trim((string) $v);
        }
    };
    try {
        // Core's wa_variables_for_contact() already resolves these (programme brochure / page, wa_brochure_url, business
        // number); this is only a fallback through the same core helpers — never a hand-built /brochure link (404).
        if (function_exists('wa_brochure_link')) {
            $fill('brochure_link', wa_brochure_link(null));
        }
        if (function_exists('wa_support_number')) {
            $fill('support_number', wa_support_number());
        }
        if (!empty($contact['counsellor_id'])) {
            $u = db_row('SELECT name, phone FROM users WHERE id = ?', [(int) $contact['counsellor_id']]);
            $fill('counsellor_name', $u['name'] ?? null);
            $fill('counsellor_phone', $u['phone'] ?? null);
        }
        if (!empty($contact['admission_id'])) {
            $a = db_row('SELECT id, interview_date, program_id, fee_paid, admission_fee FROM admissions WHERE id = ?', [(int) $contact['admission_id']]);
            if ($a) {
                $labels = ['photo' => 'Photograph', '10th_marksheet' => 'Class 10 mark sheet', '12th_marksheet' => 'Class 12 mark sheet', 'graduation' => 'Graduation mark sheet', 'id_proof' => 'ID proof (Aadhaar)', 'transfer_certificate' => 'Transfer certificate', 'migration' => 'Migration certificate', 'caste_certificate' => 'Category certificate'];
                $have = db_column("SELECT doc_type FROM admission_documents WHERE admission_id = ? AND status <> 'rejected'", [(int) $a['id']]);
                $missing = array_diff(['photo', '10th_marksheet', '12th_marksheet', 'id_proof'], $have);
                $rejected = db_column("SELECT doc_type FROM admission_documents WHERE admission_id = ? AND status = 'rejected'", [(int) $a['id']]);
                $list = array_values(array_unique(array_map(fn ($d) => $labels[$d] ?? label_from_key($d), array_merge($missing, $rejected))));
                $fill('document_list', $list ? implode(', ', $list) : null);
                $fill('documents_pending', $list ? implode(', ', $list) : null);
                if (!empty($a['interview_date']) && strtotime($a['interview_date']) > time()) {
                    $fill('counselling_date', date('d M Y', strtotime($a['interview_date'])));
                    $fill('counselling_time', date('h:i A', strtotime($a['interview_date'])));
                }
                $due = (float) $a['admission_fee'] - (float) $a['fee_paid'];
                if ($due > 0) {
                    $fill('fee_amount', function_exists('money') ? money($due) : 'Rs. ' . number_format($due));
                    $fill('due_amount', function_exists('money') ? money($due) : 'Rs. ' . number_format($due));
                }
            }
        }
        if (!empty($contact['enquiry_id']) && empty($vars['counselling_date'])) {
            $next = db_value(
                "SELECT MIN(next_followup_date) FROM admission_followups WHERE enquiry_id = ? AND completed_at IS NULL AND next_followup_date >= CURDATE() AND type IN ('meeting','campus_visit','call')",
                [(int) $contact['enquiry_id']]
            ) ?: db_value('SELECT follow_up_date FROM enquiries WHERE id = ? AND follow_up_date >= CURDATE()', [(int) $contact['enquiry_id']]);
            if ($next) {
                $fill('counselling_date', date('d M Y', strtotime((string) $next)));
            }
        }
    } catch (Throwable $e) {
        error_log('[GIMT] wa_crm_followup_vars: ' . $e->getMessage());
    }
    $defaults = json_decode((string) ($cfg['variable_defaults'] ?? ''), true);
    if (is_array($defaults)) {
        foreach ($defaults as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $fill($k, wa_crm_default_value((string) $v));
            }
        }
    }
    return $vars;
}

/** Stage default values may use {+N days} for a date relative to today (e.g. last_date = "{+7 days}"). */
function wa_crm_default_value(string $v): string
{
    return preg_replace_callback('/\{\+(\d{1,3}) days?\}/i', fn ($m) => date('d M Y', strtotime('+' . (int) $m[1] . ' days')), $v) ?? $v;
}

/** Tick handler: send the follow-ups that are due. */
function wa_crm_followups_tick(float $budget): array
{
    $t0 = microtime(true);
    $deadline = $t0 + max(0.3, $budget - 0.25);
    $stats = ['sent' => 0, 'skipped' => 0, 'cancelled' => 0, 'failed' => 0, 'rescheduled' => 0];
    // release claims left behind by a run that died mid-way (the dedupe key stops a second send of the same follow-up)
    db_exec("UPDATE wa_followups SET status = 'scheduled' WHERE status = 'processing' AND updated_at < NOW() - INTERVAL 10 MINUTE");
    $done = 0;
    $lastId = 0;
    $now = wa_crm_now();
    while (true) {
        $due = db_all("SELECT * FROM wa_followups WHERE status = 'scheduled' AND due_at <= ? AND id > ? ORDER BY id LIMIT 50", [$now, $lastId]);
        if (!$due) {
            break;
        }
        foreach ($due as $f) {
            if ($done > 0 && microtime(true) >= $deadline) {
                $stats['left_for_next_run'] = (int) db_value("SELECT COUNT(*) FROM wa_followups WHERE status = 'scheduled' AND due_at <= ?", [$now]);
                return $stats;
            }
            $lastId = (int) $f['id'];
            $done++;
            try {
                $r = wa_crm_followup_process($f);
                if ($r['status'] === 'busy') {
                    continue;
                }
                $stats[$r['status']] = ($stats[$r['status']] ?? 0) + 1;
                if ($r['status'] === 'rescheduled') {
                    // outside business hours: the remaining due follow-ups wait for the next business slot as well
                    $next = wa_next_business_time(new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                    $stats['rescheduled'] += db_exec("UPDATE wa_followups SET due_at = ?, note = 'Moved to business hours' WHERE status = 'scheduled' AND due_at <= ?", [$next, $now]);
                    return $stats;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                error_log('[GIMT] wa follow-up ' . $f['id'] . ': ' . $e->getMessage());
            }
        }
    }
    return $stats;
}

/** Stage configuration + statistics for the Follow-up Manager. */
function wa_crm_followup_stages(): array
{
    $stages = wa_crm_stages(true);
    $today0 = date('Y-m-d 00:00:00');
    $today1 = date('Y-m-d 23:59:59');
    $d30 = date('Y-m-d 00:00:00', strtotime('-29 days'));
    $stats = [];
    foreach (db_all(
        "SELECT stage_key,
                COALESCE(SUM(status = 'scheduled'), 0) AS scheduled,
                COALESCE(SUM(status = 'scheduled' AND due_at < ?), 0) AS overdue,
                COALESCE(SUM(status = 'scheduled' AND due_at BETWEEN ? AND ?), 0) AS due_today,
                COALESCE(SUM(status = 'sent' AND updated_at >= ?), 0) AS sent_30d,
                COALESCE(SUM(status IN ('skipped','failed') AND updated_at >= ?), 0) AS skipped_30d,
                COALESCE(SUM(status = 'cancelled' AND note = 'Contact replied' AND updated_at >= ?), 0) AS replied_30d
         FROM wa_followups GROUP BY stage_key", [$today0, $today0, $today1, $d30, $d30, $d30]
    ) as $r) {
        $stats[$r['stage_key']] = array_map('intval', array_diff_key($r, ['stage_key' => 1]));
    }
    $inStage = array_map('intval', db_pairs("SELECT stage, COUNT(*) FROM wa_contacts c WHERE " . wa_crm_leads_scope_sql() . " AND c.status = 'active' GROUP BY stage"));
    $templates = db_pairs('SELECT id, name FROM wa_templates');
    $out = [];
    foreach ($stages as $key => $s) {
        $cfg = $s['config'] ?? null;
        $out[] = [
            'id' => $cfg ? (int) $cfg['id'] : null, 'stage_key' => $key, 'name' => $s['name'], 'color' => $s['color'],
            'sort_order' => $cfg ? (int) $cfg['sort_order'] : $s['rank'], 'template_id' => $cfg && $cfg['template_id'] ? (int) $cfg['template_id'] : null,
            'template_name' => $cfg && $cfg['template_id'] ? ($templates[$cfg['template_id']] ?? null) : null,
            'delay_hours' => $cfg ? (int) $cfg['delay_hours'] : 24, 'max_followups' => $cfg ? (int) $cfg['max_followups'] : 0,
            'counsellor_id' => $cfg && $cfg['counsellor_id'] ? (int) $cfg['counsellor_id'] : null,
            'counsellor_name' => $cfg && $cfg['counsellor_id'] ? db_value('SELECT name FROM users WHERE id = ?', [$cfg['counsellor_id']]) : null,
            'next_action' => $cfg['next_action'] ?? null, 'stop_conditions' => wa_crm_stop_conditions($cfg['stop_conditions'] ?? null),
            'variable_defaults' => (object) (json_decode((string) ($cfg['variable_defaults'] ?? ''), true) ?: []),
            'is_active' => $cfg ? (int) $cfg['is_active'] : 0, 'is_terminal' => $cfg ? (int) $cfg['is_terminal'] : (in_array($key, wa_crm_terminal_stages(), true) ? 1 : 0),
            'contacts' => $inStage[$key] ?? 0,
            'stats' => $stats[$key] ?? ['scheduled' => 0, 'overdue' => 0, 'due_today' => 0, 'sent_30d' => 0, 'skipped_30d' => 0, 'replied_30d' => 0],
        ];
    }
    usort($out, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);
    return $out;
}

/** Save one stage's follow-up configuration (creates the row when missing). */
function wa_crm_followup_stage_save(string $stageKey, array $in): array
{
    $defs = wa_crm_stage_defaults();
    if (!isset($defs[$stageKey])) {
        throw new CrudException('Unknown stage.');
    }
    $errors = [];
    $name = trim((string) ($in['name'] ?? $defs[$stageKey]['name']));
    if ($name === '' || mb_strlen($name) > 80) {
        $errors['name'] = 'Enter a stage name (up to 80 characters).';
    }
    $tpl = !empty($in['template_id']) ? (int) $in['template_id'] : null;
    if ($tpl && !db_value('SELECT id FROM wa_templates WHERE id = ?', [$tpl])) {
        $errors['template_id'] = 'Choose an existing template.';
    }
    $delay = (int) ($in['delay_hours'] ?? 24);
    if ($delay < 1 || $delay > 720) {
        $errors['delay_hours'] = 'Delay must be between 1 and 720 hours.';
    }
    $max = (int) ($in['max_followups'] ?? 0);
    if ($max < 0 || $max > 10) {
        $errors['max_followups'] = 'Use 0 to 10 follow-ups.';
    }
    $terminal = !empty($in['is_terminal']) ? 1 : 0;
    $active = !empty($in['is_active']) ? 1 : 0;
    if ($active && !$terminal && $max > 0 && !$tpl) {
        $errors['template_id'] = 'Choose the WhatsApp template to send (or set follow-ups to 0).';
    }
    $counsellor = !empty($in['counsellor_id']) ? (int) $in['counsellor_id'] : null;
    if ($counsellor && !db_value("SELECT id FROM users WHERE id = ? AND status = 'active'", [$counsellor])) {
        $errors['counsellor_id'] = 'Choose an active counsellor.';
    }
    $color = in_array($in['color'] ?? '', wa_crm_tag_colors(), true) ? $in['color'] : $defs[$stageKey]['color'];
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $stop = wa_crm_stop_conditions(is_array($in['stop_conditions'] ?? null) ? $in['stop_conditions'] : []);
    $defaults = [];
    foreach (is_array($in['variable_defaults'] ?? null) ? $in['variable_defaults'] : [] as $k => $v) {
        if (is_string($k) && preg_match('/^[a-z0-9_]{1,60}$/', $k) && is_scalar($v) && trim((string) $v) !== '') {
            $defaults[$k] = mb_substr(trim((string) $v), 0, 190);
        }
    }
    $data = [
        'name' => $name, 'color' => $color, 'template_id' => $tpl, 'delay_hours' => $delay, 'max_followups' => $terminal ? 0 : $max, 'counsellor_id' => $counsellor,
        'next_action' => mb_substr(trim((string) ($in['next_action'] ?? '')), 0, 190) ?: null, 'stop_conditions' => json_encode($stop),
        'is_active' => $active, 'is_terminal' => $terminal, 'variable_defaults' => $defaults ? json_encode(array_slice($defaults, 0, 30, true), JSON_UNESCAPED_UNICODE) : null,
    ];
    $id = db_value('SELECT id FROM wa_followup_stages WHERE stage_key = ?', [$stageKey]);
    $old = $id ? db_row('SELECT * FROM wa_followup_stages WHERE id = ?', [$id]) : null;
    if ($id) {
        db_update('wa_followup_stages', $data, 'id = ?', [$id]);
    } else {
        $id = db_insert('wa_followup_stages', $data + ['stage_key' => $stageKey, 'sort_order' => $defs[$stageKey]['rank'] ?: 9]);
    }
    // pausing / making terminal cancels what is queued for this stage; a new template applies to queued attempts
    $cancelled = 0;
    if (!$active || $terminal || $max === 0) {
        $cancelled = db_exec("UPDATE wa_followups SET status = 'cancelled', note = 'Stage follow-ups turned off' WHERE stage_key = ? AND status = 'scheduled'", [$stageKey]);
    } elseif ($old && (int) $old['template_id'] !== (int) $tpl) {
        db_exec("UPDATE wa_followups SET template_id = ? WHERE stage_key = ? AND status = 'scheduled'", [$tpl, $stageKey]);
    }
    wa_crm_stages(true);
    return ['id' => (int) $id, 'cancelled' => $cancelled, 'old' => $old];
}

/** Follow-up list for the manager (tabs: due_today, overdue, upcoming, sent, skipped, all). */
function wa_crm_followups(array $in, int $page = 1, int $perPage = 25): array
{
    $w = ['1=1'];
    $args = [];
    $tab = (string) ($in['tab'] ?? 'due_today');
    $t0 = date('Y-m-d 00:00:00');
    $t1 = date('Y-m-d 23:59:59');
    $now = wa_crm_now();
    switch ($tab) {
        case 'due_today':
            $w[] = "f.status = 'scheduled' AND f.due_at BETWEEN ? AND ?";
            array_push($args, $t0, $t1);
            break;
        case 'overdue':
            $w[] = "f.status = 'scheduled' AND f.due_at < ?";
            $args[] = $t0;
            break;
        case 'upcoming':
            $w[] = "f.status = 'scheduled' AND f.due_at > ?";
            $args[] = $t1;
            break;
        case 'sent':
            $w[] = "f.status = 'sent'";
            break;
        case 'skipped':
            $w[] = "f.status IN ('skipped','cancelled','failed')";
            break;
    }
    if (!empty($in['stage'])) {
        $w[] = 'f.stage_key = ?';
        $args[] = (string) $in['stage'];
    }
    if (!empty($in['counsellor_id'])) {
        if ($in['counsellor_id'] === 'none') {
            $w[] = 'c.counsellor_id IS NULL';
        } else {
            $w[] = 'c.counsellor_id = ?';
            $args[] = (int) $in['counsellor_id'];
        }
    }
    $q = trim((string) ($in['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $digits = preg_replace('/\D+/', '', $q);
        $w[] = '(c.name LIKE ?' . (strlen($digits) >= 4 ? ' OR c.phone LIKE ?' : '') . ')';
        $args[] = $like;
        if (strlen($digits) >= 4) {
            $args[] = '%' . $digits . '%';
        }
    }
    $where = implode(' AND ', $w);
    $from = 'wa_followups f JOIN wa_contacts c ON c.id = f.contact_id LEFT JOIN users u ON u.id = c.counsellor_id LEFT JOIN wa_templates t ON t.id = f.template_id LEFT JOIN wa_messages m ON m.id = f.message_id';
    $total = (int) db_value("SELECT COUNT(*) FROM $from WHERE $where", $args);
    $pg = paginate($total, $page, $perPage);
    $order = in_array($tab, ['sent', 'skipped'], true) ? 'COALESCE(f.updated_at, f.due_at) DESC, f.id DESC' : ($tab === 'all' ? 'f.due_at DESC, f.id DESC' : 'f.due_at ASC, f.id ASC');
    $rows = db_all(
        "SELECT f.id, f.contact_id, f.stage_key, f.attempt, f.template_id, f.due_at, f.status, f.message_id, f.note, f.created_at, f.updated_at,
                c.name AS contact_name, c.phone, c.stage AS contact_stage, c.opt_in, c.program_name, c.counsellor_id, u.name AS counsellor_name,
                t.name AS template_name, t.display_name AS template_display, t.category AS template_category, m.status AS message_status
         FROM $from WHERE $where ORDER BY $order LIMIT " . (int) $pg['per_page'] . ' OFFSET ' . (int) (($pg['page'] - 1) * $pg['per_page']),
        $args
    );
    $stages = wa_crm_stages();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['contact_id'] = (int) $r['contact_id'];
        $r['attempt'] = (int) $r['attempt'];
        $r['opt_in'] = (int) $r['opt_in'];
        $r['phone'] = wa_crm_show_phone($r['phone']);
        $r['stage_name'] = wa_crm_stage_name($r['stage_key']);
        $r['max_followups'] = (int) ($stages[$r['stage_key']]['config']['max_followups'] ?? 0);
        $r['overdue'] = $r['status'] === 'scheduled' && $r['due_at'] < $now;
    }
    unset($r);
    return ['data' => $rows, 'meta' => $pg];
}

function wa_crm_followup_counts(): array
{
    $t0 = date('Y-m-d 00:00:00');
    $t1 = date('Y-m-d 23:59:59');
    $r = db_row(
        "SELECT COALESCE(SUM(status = 'scheduled' AND due_at BETWEEN ? AND ?), 0) AS due_today,
                COALESCE(SUM(status = 'scheduled' AND due_at < ?), 0) AS overdue,
                COALESCE(SUM(status = 'scheduled' AND due_at > ?), 0) AS upcoming,
                COALESCE(SUM(status = 'sent'), 0) AS sent,
                COALESCE(SUM(status IN ('skipped','cancelled','failed')), 0) AS skipped,
                COUNT(*) AS `all`,
                COALESCE(SUM(status = 'sent' AND updated_at >= ?), 0) AS sent_7d
         FROM wa_followups", [$t0, $t1, $t0, $t1, date('Y-m-d 00:00:00', strtotime('-6 days'))]
    ) ?: [];
    return array_map('intval', $r);
}

/* ============================================================================================ hooks */

wa_on('WA_STAGE_CHANGED', 'wa_crm_on_stage_changed', 5);
wa_on('WA_MESSAGE_RECEIVED', 'wa_crm_on_message_received', 5);
wa_on('WA_OPTED_OUT', 'wa_crm_on_opted_out', 5);
foreach ([
    'GIMT_ENQUIRY_RECEIVED', 'GIMT_ENQUIRY_REPEAT', 'GIMT_CALLBACK_REQUEST', 'GIMT_COUNSELLING_REQUEST', 'GIMT_LANDING_LEAD', 'GIMT_COURSE_ENQUIRY',
    'GIMT_CHATBOT_LEAD', 'GIMT_ENQUIRY_CREATED_ADMIN', 'GIMT_ENQUIRY_CREATED', 'GIMT_ENQUIRY_ASSIGNED', 'GIMT_ENQUIRY_STATUS_CHANGED', 'GIMT_FOLLOWUP_LOGGED',
    'GIMT_APPLICATION_SUBMITTED', 'GIMT_APPLICATION_CREATED', 'GIMT_APPLICATION_ASSIGNED', 'GIMT_ADMISSION_STAGE_CHANGED', 'GIMT_ADMISSION_FEE_RECEIVED',
    'GIMT_ADMISSION_CONFIRMED', 'GIMT_STUDENT_ENROLLED', 'GIMT_STUDENT_STATUS_CHANGED', 'GIMT_PAYMENT_SUCCESS', 'GIMT_ONLINE_PAYMENT_SUCCESS',
    'GIMT_FEE_INVOICE_RAISED',
] as $waCrmCode) {
    wa_on($waCrmCode, 'wa_crm_on_business_event', 20);
}
unset($waCrmCode);
wa_on_tick('crm_followups', 'wa_crm_followups_tick');
wa_on_tick('crm_sync', 'wa_crm_sync_tick');
