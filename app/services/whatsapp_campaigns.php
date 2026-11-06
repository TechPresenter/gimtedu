<?php
/**
 * WhatsApp bulk campaigns (campaigns part of docs/WHATSAPP.md) — prefix wa_cmp_.
 *
 *  - Drafts: name, type, approved template, audience (CRM filters / saved segment and/or an uploaded CSV / XLSX list),
 *    variable mapping (template slot → contact variable | CSV column | literal, with a fallback), throttle, business hours.
 *  - Audience preview: live recipient count with the reasons people are skipped. Opt-in policy (brief: "only message
 *    opted-in recipients"; Meta requires opt-in for business-initiated messages of every category): every campaign,
 *    MARKETING or UTILITY, goes only to contacts with a recorded WhatsApp opt-in (wa_contacts.opt_in = 1, or a "yes" in
 *    the consent column of the uploaded list) — everyone else is skipped as no_consent; opted-out, blocked, invalid and
 *    duplicate numbers are never messaged. Transactional notices to students without opt-in go through events, not
 *    campaigns.
 *  - Sending: send now / schedule → recipients are built (wa_campaign_recipients snapshot) → the worker tick handler
 *    queues them in batches through core wa_send_template() (sender_type campaign, priority 9) at the campaign / account
 *    rate; pause / resume / cancel / retry failed; test message to one number.
 *  - Tracking: core moves recipient rows forward on status webhooks; this service aggregates the counters, marks
 *    completion, counts replies (inbound message within 72 h of the send, WA_MESSAGE_RECEIVED) and serves charts,
 *    recipient lists and CSV / XLSX exports. In live mode an Interakt API campaign is created for dashboard analytics
 *    (interakt_campaign_id → campaignId on every send, added by core wa_build_payload()).
 *
 * Loaded by app/services/whatsapp.php (core): only defines functions and registers hooks at include time.
 */

const WA_CMP_MAX_AUDIENCE = 50000;
const WA_CMP_MAX_UPLOAD_ROWS = 20000;
const WA_CMP_REPLY_WINDOW_HOURS = 72;

/* ============================================================================================ meta */

/** Campaign types from the brief: key => label, suggested template category, description. */
function wa_cmp_types(): array
{
    return [
        'admission' => ['label' => 'New admission', 'category' => 'MARKETING', 'description' => 'Admissions are open — invite enquiries and leads to apply.'],
        'course_promotion' => ['label' => 'Course promotion', 'category' => 'MARKETING', 'description' => 'Promote a programme, specialisation or new batch.'],
        'scholarship' => ['label' => 'Scholarship announcement', 'category' => 'MARKETING', 'description' => 'Merit / need-based scholarships and fee waivers.'],
        'admission_closing' => ['label' => 'Admission closing reminder', 'category' => 'MARKETING', 'description' => 'Last dates for applications, documents and fee payment.'],
        'exam' => ['label' => 'Exam announcement', 'category' => 'UTILITY', 'description' => 'Exam schedules, admit cards and results for students.'],
        'fee_reminder' => ['label' => 'Fee reminder', 'category' => 'UTILITY', 'description' => 'Fee due and overdue reminders with a payment link.'],
        'event' => ['label' => 'Event invitation', 'category' => 'MARKETING', 'description' => 'Seminars, fests, open days and webinars.'],
        'alumni' => ['label' => 'Alumni engagement', 'category' => 'MARKETING', 'description' => 'Alumni meets, mentoring and giving back.'],
        'festival' => ['label' => 'Festival greetings', 'category' => 'MARKETING', 'description' => 'Diwali, Holi, Eid, Christmas and New Year wishes.'],
        'announcement' => ['label' => 'Student announcement', 'category' => 'UTILITY', 'description' => 'Notices, holidays and timetable changes for students.'],
    ];
}

function wa_cmp_statuses(): array
{
    return [
        'draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'paused' => 'Paused',
        'completed' => 'Completed', 'cancelled' => 'Cancelled', 'failed' => 'Failed',
    ];
}

/** Why a recipient is not messaged ('no_opt_in' only appears on recipients recorded before every campaign needed opt-in). */
function wa_cmp_skip_reasons(): array
{
    return [
        'no_opt_in' => 'Not opted in (marketing template)',
        'no_consent' => 'No WhatsApp opt-in',
        'opted_out' => 'Opted out (replied STOP)',
        'blocked' => 'Contact blocked',
        'invalid_number' => 'Invalid WhatsApp number',
        'duplicate' => 'Duplicate number',
        'missing_variables' => 'Missing personalisation values',
        'template' => 'Template not available',
        'cancelled' => 'Campaign cancelled',
    ];
}

function wa_cmp_now(): string
{
    return function_exists('wa_now') ? wa_now() : date('Y-m-d H:i:s');
}

/** List-valued input (array or comma separated string), trimmed, unique, no blanks. */
function wa_cmp_values($v): array
{
    if ($v === null || $v === '') {
        return [];
    }
    $items = is_array($v) ? $v : explode(',', (string) $v);
    $out = [];
    foreach ($items as $i) {
        if (is_array($i) || is_object($i)) {
            continue;
        }
        $i = trim((string) $i);
        if ($i !== '' && mb_strlen($i) <= 120) {
            $out[] = $i;
        }
    }
    return array_values(array_unique($out));
}

/* ============================================================================================ rows */

/** Decode + cast a wa_campaigns row (JSON columns, ints). */
function wa_cmp_row(array $r): array
{
    $r['id'] = (int) $r['id'];
    $r['template_id'] = $r['template_id'] !== null ? (int) $r['template_id'] : null;
    $r['audience'] = wa_cmp_audience_clean($r['audience'] ?? null);
    $map = is_array($r['variable_map'] ?? null) ? $r['variable_map'] : (wa_json_decode($r['variable_map'] ?? null, []) ?: []);
    $r['variable_map'] = is_array($map) ? $map : [];
    foreach (['total_recipients', 'queued_count', 'sent_count', 'delivered_count', 'read_count', 'failed_count', 'replied_count', 'skipped_count'] as $k) {
        $r[$k] = (int) ($r[$k] ?? 0);
    }
    $r['rate_per_minute'] = isset($r['rate_per_minute']) && $r['rate_per_minute'] !== null ? (int) $r['rate_per_minute'] : null;
    $r['respect_business_hours'] = (int) ($r['respect_business_hours'] ?? 0);
    $r['created_by'] = isset($r['created_by']) && $r['created_by'] !== null ? (int) $r['created_by'] : null;
    $r['type_label'] = wa_cmp_types()[$r['type']]['label'] ?? label_from_key((string) $r['type']);
    return $r;
}

function wa_cmp_select_sql(): string
{
    return "SELECT c.*, t.name AS template_name, t.display_name AS template_display_name, t.category AS template_category,
                   t.language AS template_language, t.status AS template_status, u.name AS created_by_name
              FROM wa_campaigns c
              LEFT JOIN wa_templates t ON t.id = c.template_id
              LEFT JOIN users u ON u.id = c.created_by";
}

function wa_cmp_get(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $r = db_row(wa_cmp_select_sql() . ' WHERE c.id = ?', [$id]);
    return $r ? wa_cmp_row($r) : null;
}

/** Progress numbers for a row (processed = sent + failed + skipped). */
function wa_cmp_progress(array $c): array
{
    $total = (int) $c['total_recipients'];
    $processed = (int) $c['sent_count'] + (int) $c['failed_count'] + (int) $c['skipped_count'];
    $pct = $total > 0 ? min(100, round($processed / $total * 100, 1)) : ($c['status'] === 'completed' ? 100 : 0);
    $sent = (int) $c['sent_count'];
    $delivered = (int) $c['delivered_count'];
    return [
        'processed' => $processed,
        'percent' => $pct,
        'eligible' => max(0, $total - (int) $c['skipped_count']),
        'delivery_rate' => $sent > 0 ? round($delivered / $sent * 100, 1) : null,
        'read_rate' => $delivered > 0 ? round((int) $c['read_count'] / $delivered * 100, 1) : null,
        'reply_rate' => $delivered > 0 ? round((int) $c['replied_count'] / $delivered * 100, 1) : null,
        'failure_rate' => ($sent + (int) $c['failed_count']) > 0 ? round((int) $c['failed_count'] / ($sent + (int) $c['failed_count']) * 100, 1) : null,
    ];
}

/** Row shape for the list / detail APIs. */
function wa_cmp_public(array $c): array
{
    $c['progress'] = wa_cmp_progress($c);
    $c['template'] = $c['template_id'] ? [
        'id' => $c['template_id'], 'name' => $c['template_name'] ?? null, 'display_name' => $c['template_display_name'] ?? null,
        'category' => $c['template_category'] ?? null, 'language' => $c['template_language'] ?? null, 'status' => $c['template_status'] ?? null,
    ] : null;
    unset($c['template_display_name'], $c['template_category'], $c['template_language'], $c['template_status']);
    return $c;
}

/* ============================================================================================ list / summary */

/** Paginated list. Filters: status, type, template_id, q, from, to (created date), sort (created|scheduled|name|sent). */
function wa_cmp_list(array $f, int $page = 1, int $perPage = 20): array
{
    $w = ['1=1'];
    $args = [];
    $status = (string) ($f['status'] ?? '');
    if ($status === 'active') {
        $w[] = "c.status IN ('scheduled','sending','paused')";
    } elseif ($status !== '' && isset(wa_cmp_statuses()[$status])) {
        $w[] = 'c.status = ?';
        $args[] = $status;
    }
    $type = (string) ($f['type'] ?? '');
    if ($type !== '' && isset(wa_cmp_types()[$type])) {
        $w[] = 'c.type = ?';
        $args[] = $type;
    }
    if (!empty($f['template_id']) && ctype_digit((string) $f['template_id'])) {
        $w[] = 'c.template_id = ?';
        $args[] = (int) $f['template_id'];
    }
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $w[] = '(c.name LIKE ? OR c.description LIKE ? OR t.name LIKE ? OR t.display_name LIKE ?)';
        array_push($args, $like, $like, $like, $like);
    }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        $d = (string) ($f[$k] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $w[] = "COALESCE(c.started_at, c.scheduled_at, c.created_at) $op ?";
            $args[] = $d . ($k === 'from' ? ' 00:00:00' : ' 23:59:59');
        }
    }
    $where = implode(' AND ', $w);
    $from = 'wa_campaigns c LEFT JOIN wa_templates t ON t.id = c.template_id';
    $total = (int) db_value("SELECT COUNT(*) FROM $from WHERE $where", $args);
    $pg = paginate($total, $page, $perPage);
    $sort = [
        'created' => 'c.created_at DESC, c.id DESC',
        'scheduled' => 'COALESCE(c.scheduled_at, c.started_at, c.created_at) DESC, c.id DESC',
        'name' => 'c.name ASC, c.id DESC',
        'sent' => 'c.sent_count DESC, c.id DESC',
    ][(string) ($f['sort'] ?? 'created')] ?? 'c.created_at DESC, c.id DESC';
    // active campaigns first, then the chosen order
    $order = "(c.status IN ('sending','paused')) DESC, (c.status = 'scheduled') DESC, $sort";
    $rows = db_all(wa_cmp_select_sql() . " WHERE $where ORDER BY $order LIMIT " . (int) $pg['per_page'] . ' OFFSET ' . (int) (($pg['page'] - 1) * $pg['per_page']), $args);
    $data = [];
    foreach ($rows as $r) {
        $c = wa_cmp_public(wa_cmp_row($r));
        unset($c['variable_map']);
        $data[] = $c;
    }
    return ['data' => $data, 'meta' => $pg];
}

/** KPI strip for the campaigns page (counts + last 30 days performance). */
function wa_cmp_summary(): array
{
    $byStatus = db_pairs('SELECT status, COUNT(*) FROM wa_campaigns GROUP BY status');
    $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    $p = db_row(
        'SELECT COALESCE(SUM(sent_count),0) AS sent, COALESCE(SUM(delivered_count),0) AS delivered, COALESCE(SUM(read_count),0) AS rd,
                COALESCE(SUM(failed_count),0) AS failed, COALESCE(SUM(replied_count),0) AS replied, COALESCE(SUM(skipped_count),0) AS skipped,
                COALESCE(SUM(total_recipients),0) AS audience
           FROM wa_campaigns WHERE COALESCE(started_at, created_at) >= ?',
        [$since]
    ) ?: [];
    $sent = (int) ($p['sent'] ?? 0);
    $delivered = (int) ($p['delivered'] ?? 0);
    $next = db_row("SELECT id, name, scheduled_at FROM wa_campaigns WHERE status = 'scheduled' AND scheduled_at IS NOT NULL ORDER BY scheduled_at LIMIT 1");
    $statuses = [];
    foreach (wa_cmp_statuses() as $k => $label) {
        $statuses[$k] = (int) ($byStatus[$k] ?? 0);
    }
    return [
        'total' => array_sum($statuses),
        'by_status' => $statuses,
        'active' => $statuses['sending'] + $statuses['paused'] + $statuses['scheduled'],
        'sent_30d' => $sent,
        'delivered_30d' => $delivered,
        'read_30d' => (int) ($p['rd'] ?? 0),
        'failed_30d' => (int) ($p['failed'] ?? 0),
        'replied_30d' => (int) ($p['replied'] ?? 0),
        'skipped_30d' => (int) ($p['skipped'] ?? 0),
        'audience_30d' => (int) ($p['audience'] ?? 0),
        'delivery_rate' => $sent > 0 ? round($delivered / $sent * 100, 1) : null,
        'read_rate' => $delivered > 0 ? round((int) $p['rd'] / $delivered * 100, 1) : null,
        'reply_rate' => $delivered > 0 ? round((int) $p['replied'] / $delivered * 100, 1) : null,
        'next_scheduled' => $next ? ['id' => (int) $next['id'], 'name' => $next['name'], 'scheduled_at' => $next['scheduled_at']] : null,
    ];
}

/* ============================================================================================ templates */

/** Approved templates usable by campaigns (decoded, with slots). */
function wa_cmp_templates(): array
{
    $out = [];
    foreach (db_all("SELECT * FROM wa_templates WHERE status = 'approved' ORDER BY category = 'MARKETING' DESC, COALESCE(display_name, name)") as $t) {
        $t = wa_template_decode($t);
        $out[] = [
            'id' => (int) $t['id'], 'name' => $t['name'], 'display_name' => $t['display_name'], 'description' => $t['description'] ?? null,
            'category' => $t['category'], 'language' => $t['language'], 'header_type' => $t['header_type'], 'header_text' => $t['header_text'],
            'header_media_url' => $t['header_media_url'], 'body' => $t['body'], 'footer' => $t['footer'], 'buttons' => $t['buttons'] ?: [],
            'variables' => $t['variables'] ?: (object) [], 'usage_count' => (int) $t['usage_count'], 'last_used_at' => $t['last_used_at'],
            'slots' => wa_cmp_slots($t),
        ];
    }
    return $out;
}

/** Why a template cannot be used by a campaign (null = usable). Campaigns use approved templates only. */
function wa_cmp_template_problem(?array $tpl): ?string
{
    if (!$tpl) {
        return 'Choose an approved WhatsApp template.';
    }
    if (($tpl['status'] ?? '') !== 'approved') {
        return 'Template "' . ($tpl['display_name'] ?: $tpl['name']) . '" is ' . ($tpl['status'] ?? 'unknown') . ' — campaigns can only send approved templates.';
    }
    if (($tpl['category'] ?? '') === 'AUTHENTICATION') {
        return 'Authentication (OTP) templates cannot be used for campaigns.';
    }
    return null;
}

/**
 * Variable slots of a template: [{key: body.1|header.1|header_media|button.0, section, pos, label, var_key, sample, kind}].
 * kind: text | media | url | code.
 */
function wa_cmp_slots(array $tpl): array
{
    $tpl = isset($tpl['buttons']) && is_string($tpl['buttons']) ? wa_template_decode($tpl) : $tpl;
    $s = wa_template_slots($tpl);
    $vars = is_array($tpl['variables'] ?? null) ? $tpl['variables'] : [];
    $ref = function (string $section, int $pos) use ($vars): array {
        foreach ((array) ($vars[$section] ?? []) as $r) {
            if ((int) ($r['pos'] ?? 0) === $pos) {
                return ['key' => (string) ($r['key'] ?? ''), 'sample' => (string) ($r['sample'] ?? '')];
            }
        }
        return ['key' => '', 'sample' => ''];
    };
    $out = [];
    if ($s['header_type'] === 'text') {
        for ($p = 1; $p <= $s['header']; $p++) {
            $r = $ref('header', $p);
            $out[] = ['key' => 'header.' . $p, 'section' => 'header', 'pos' => $p, 'label' => 'Header {{' . $p . '}}', 'var_key' => $r['key'], 'sample' => $r['sample'], 'kind' => 'text'];
        }
    } elseif (in_array($s['header_type'], ['image', 'video', 'document'], true)) {
        $out[] = ['key' => 'header_media', 'section' => 'header', 'pos' => 1, 'label' => ucfirst($s['header_type']) . ' header (public URL)', 'var_key' => '',
            'sample' => (string) ($tpl['header_media_url'] ?? ''), 'kind' => 'media'];
    }
    for ($p = 1; $p <= $s['body']; $p++) {
        $r = $ref('body', $p);
        $out[] = ['key' => 'body.' . $p, 'section' => 'body', 'pos' => $p, 'label' => 'Body {{' . $p . '}}', 'var_key' => $r['key'], 'sample' => $r['sample'], 'kind' => 'text'];
    }
    foreach ($s['buttons'] as $idx => $type) {
        $b = array_values($tpl['buttons'] ?? [])[$idx] ?? [];
        $bref = $vars['buttons'][$idx] ?? ($vars['buttons'][(string) $idx] ?? null);
        $out[] = ['key' => 'button.' . $idx, 'section' => 'button', 'pos' => (int) $idx, 'label' => 'Button "' . ($b['text'] ?? ('#' . ($idx + 1))) . '"' . ($type === 'URL' ? ' URL suffix' : ' code'),
            'var_key' => is_array($bref) ? (string) ($bref['key'] ?? '') : '', 'sample' => is_array($bref) ? (string) ($bref['sample'] ?? ($b['example'] ?? '')) : (string) ($b['example'] ?? ''),
            'kind' => $type === 'URL' ? 'url' : 'code'];
    }
    return $out;
}

/* ============================================================================================ audience */

/** Normalised audience definition (JSON column / request). */
function wa_cmp_audience_clean($a): array
{
    if (!is_array($a)) {
        $a = wa_json_decode($a, []) ?: [];
    }
    $ints = fn ($v) => array_values(array_unique(array_filter(array_map('intval', array_filter(wa_cmp_values($v), 'ctype_digit')), fn ($x) => $x > 0)));
    $strs = fn ($v) => array_slice(wa_cmp_values($v), 0, 300);
    $types = function_exists('wa_crm_contact_types') ? array_keys(wa_crm_contact_types()) : ['lead', 'applicant', 'student', 'guardian', 'alumni', 'staff', 'other'];
    $seg = $a['segment_id'] ?? null;
    return [
        'programs' => $ints($a['programs'] ?? []),
        'stages' => $strs($a['stages'] ?? []),
        'admission_status' => $strs($a['admission_status'] ?? []),
        'payment_status' => $strs($a['payment_status'] ?? []),
        'cities' => $strs($a['cities'] ?? []),
        'states' => $strs($a['states'] ?? []),
        'batches' => $strs($a['batches'] ?? []),
        'tags' => $ints($a['tags'] ?? []),
        'contact_types' => array_values(array_intersect($strs($a['contact_types'] ?? []), $types)),
        'segment_id' => is_scalar($seg) && ctype_digit((string) $seg) && (int) $seg > 0 ? (int) $seg : null,
        // every campaign needs a WhatsApp opt-in (wa_cmp_skip_reason): no longer a choice, kept in the JSON for old clients
        'opt_in_only' => true,
    ];
}

function wa_cmp_has_filters(array $a): bool
{
    foreach (['programs', 'stages', 'admission_status', 'payment_status', 'cities', 'states', 'batches', 'tags', 'contact_types'] as $k) {
        if (!empty($a[$k])) {
            return true;
        }
    }
    return !empty($a['segment_id']);
}

/** Does the saved segment of an audience still exist? (true when no segment is used) */
function wa_cmp_segment_exists(array $a): bool
{
    return empty($a['segment_id']) || (bool) db_value('SELECT id FROM wa_segments WHERE id = ?', [(int) $a['segment_id']]);
}

/**
 * WHERE clause (alias c = wa_contacts) for an audience: archived contacts excluded, blocked kept (reported as skipped).
 * A saved segment is combined with the campaign's own filters as an intersection (segment AND filters), never merged
 * key by key — "Parents of current students" + contact type "student" means nobody, not every student. A segment that
 * was deleted after the campaign was saved matches nobody (it must never widen the audience to every contact).
 */
function wa_cmp_filter_where(array $a, array &$args): string
{
    if (!wa_cmp_segment_exists($a)) {
        return '1=0';
    }
    if (function_exists('wa_crm_filter_where')) {
        $f = [
            'type' => $a['contact_types'], 'program_id' => array_map('strval', $a['programs']), 'stage' => $a['stages'],
            'admission_status' => $a['admission_status'], 'payment_status' => $a['payment_status'], 'city' => $a['cities'],
            'state' => $a['states'], 'batch' => $a['batches'], 'tag' => array_map('strval', $a['tags']), 'status' => ['active', 'blocked'],
        ];
        $where = wa_crm_filter_where($f, $args);
        if (!empty($a['segment_id'])) {
            // the segment's own definition (incl. its status filter when it has one; else archived excluded)
            $segArgs = [];
            $segWhere = wa_crm_filter_where(['segment' => (string) $a['segment_id']], $segArgs);
            $where = '(' . $where . ') AND (' . $segWhere . ')';
            array_push($args, ...$segArgs);
        }
        return $where;
    }
    // fallback when the contacts service is not loaded (segments cannot be evaluated without it: match nobody)
    $w = ["c.status <> 'archived'"];
    if (!empty($a['segment_id'])) {
        $w[] = '1=0';
    }
    $in = function (string $col, array $vals) use (&$w, &$args) {
        if ($vals) {
            $w[] = $col . ' IN (' . implode(',', array_fill(0, count($vals), '?')) . ')';
            array_push($args, ...$vals);
        }
    };
    $in('c.contact_type', $a['contact_types']);
    $in('c.program_id', $a['programs']);
    $in('c.stage', $a['stages']);
    $in('c.admission_status', $a['admission_status']);
    $in('c.payment_status', $a['payment_status']);
    $in('c.city', $a['cities']);
    $in('c.state', $a['states']);
    $in('c.batch', $a['batches']);
    if ($a['tags']) {
        $w[] = 'EXISTS (SELECT 1 FROM wa_contact_tags ct WHERE ct.contact_id = c.id AND ct.tag_id IN (' . implode(',', array_fill(0, count($a['tags']), '?')) . '))';
        array_push($args, ...$a['tags']);
    }
    return implode(' AND ', $w);
}

/**
 * Skip reason for a recipient (null = will be messaged).
 * $contact: wa_contacts row (or null for a CSV number that is not a contact yet); $consent: CSV consent column says yes.
 * Every campaign — whatever the template category — needs a recorded WhatsApp opt-in (brief: "Only message opted-in
 * recipients"; Meta's business messaging policy asks for opt-in before any business-initiated message). Without one
 * the recipient is skipped as 'no_consent'. $category and $optInOnly are kept for callers and no longer relax the rule.
 */
function wa_cmp_skip_reason(?array $contact, bool $consent, string $category = 'MARKETING', bool $optInOnly = true): ?string
{
    $opted = false;
    if ($contact) {
        if (($contact['status'] ?? 'active') === 'blocked') {
            return 'blocked';
        }
        if ((int) $contact['opt_in'] !== 1 && !empty($contact['opted_out_at'])) {
            return 'opted_out';
        }
        $opted = (int) $contact['opt_in'] === 1;
    }
    return $opted || $consent ? null : 'no_consent';
}

/** Category used for the policy check: the template's, else the campaign type's suggestion. */
function wa_cmp_category(array $c, ?array $tpl): string
{
    if ($tpl && !empty($tpl['category'])) {
        return strtoupper((string) $tpl['category']);
    }
    return wa_cmp_types()[$c['type'] ?? '']['category'] ?? 'MARKETING';
}

/**
 * Every audience row of a campaign (filters and / or CSV), deduplicated by phone, with its skip reason.
 * Returns ['rows' => [[phone, name, contact_id, contact_type, source, consent, data, skip]], 'capped' => bool].
 * $c needs: audience (clean), audience_source, id (for the CSV upload), type.
 */
function wa_cmp_candidates(array $c, string $category): array
{
    $aud = wa_cmp_audience_clean($c['audience'] ?? []);
    $src = in_array($c['audience_source'] ?? 'filters', ['filters', 'csv', 'mixed'], true) ? $c['audience_source'] : 'filters';
    $rows = [];
    $filterContacts = [];
    $capped = false;
    $cols = 'c.id, c.phone, c.name, c.contact_type, c.opt_in, c.opted_out_at, c.status';

    if ($src === 'filters' || $src === 'mixed') {
        $args = [];
        $where = wa_cmp_filter_where($aud, $args);
        $list = db_all("SELECT $cols FROM wa_contacts c WHERE $where ORDER BY c.id LIMIT " . (WA_CMP_MAX_AUDIENCE + 1), $args);
        if (count($list) > WA_CMP_MAX_AUDIENCE) {
            $capped = true;
            array_pop($list);
        }
        foreach ($list as $ct) {
            $phone = (string) $ct['phone'];
            if (isset($rows[$phone])) {
                continue;
            }
            $filterContacts[$phone] = $ct;
            $rows[$phone] = [
                'phone' => $phone, 'name' => $ct['name'], 'contact_id' => (int) $ct['id'], 'contact_type' => $ct['contact_type'],
                'source' => 'filter', 'consent' => false, 'data' => [], 'skip' => wa_cmp_skip_reason($ct, false, $category),
            ];
        }
    }

    $dupes = [];
    if (($src === 'csv' || $src === 'mixed') && !empty($c['id'])) {
        $up = wa_cmp_upload_row((int) $c['id']);
        if ($up) {
            $an = wa_cmp_upload_analyse($up);
            $valid = $an['valid'];
            // existing contacts for the CSV numbers
            $known = [];
            foreach (array_chunk(array_values(array_unique(array_map(fn ($v) => $v['phone'], $valid))), 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                foreach (db_all("SELECT $cols FROM wa_contacts c WHERE c.phone IN ($ph)", $chunk) as $ct) {
                    $known[$ct['phone']] = $ct;
                }
            }
            foreach ($valid as $v) {
                $phone = $v['phone'];
                if (isset($rows[$phone])) {
                    // number already in the filtered audience: keep one message, merge the CSV columns for personalisation;
                    // consent recorded in the list counts for the policy check (an opted-out / blocked contact stays skipped)
                    $rows[$phone]['data'] = $v['data'];
                    $rows[$phone]['consent'] = $rows[$phone]['consent'] || $v['consent'];
                    if ($v['consent'] && isset($filterContacts[$phone])) {
                        $rows[$phone]['skip'] = wa_cmp_skip_reason($filterContacts[$phone], true, $category);
                    }
                    $dupes[] = ['phone' => $phone, 'name' => $v['name'], 'row' => $v['row']];
                    continue;
                }
                if (count($rows) >= WA_CMP_MAX_AUDIENCE) {
                    $capped = true;
                    break;
                }
                $ct = $known[$phone] ?? null;
                $rows[$phone] = [
                    'phone' => $phone, 'name' => $v['name'] ?: ($ct['name'] ?? null), 'contact_id' => $ct ? (int) $ct['id'] : null,
                    'contact_type' => $ct['contact_type'] ?? null, 'source' => 'csv', 'consent' => $v['consent'], 'data' => $v['data'],
                    'skip' => wa_cmp_skip_reason($ct, $v['consent'], $category),
                ];
            }
            foreach ($an['duplicates'] as $d) {
                $dupes[] = $d;
            }
            foreach ($an['invalid'] as $iv) {
                $rows['invalid:' . $iv['row']] = [
                    'phone' => null, 'raw_phone' => $iv['value'], 'name' => $iv['name'] ?? null, 'contact_id' => null, 'contact_type' => null,
                    'source' => 'csv', 'consent' => false, 'data' => [], 'skip' => 'invalid_number', 'row' => $iv['row'],
                ];
            }
        }
    }
    return ['rows' => array_values($rows), 'duplicates' => $dupes, 'capped' => $capped];
}

/** Live audience numbers for the editor: totals, skip breakdown, by type / source, sample recipients. */
function wa_cmp_audience_preview(array $c): array
{
    $tpl = !empty($c['template_id']) ? wa_template_find((int) $c['template_id']) : null;
    $category = wa_cmp_category($c, $tpl);
    $res = wa_cmp_candidates($c, $category);
    $breakdown = [];
    $byType = [];
    $bySource = ['filter' => 0, 'csv' => 0];
    $eligible = 0;
    $sample = [];
    foreach ($res['rows'] as $r) {
        if ($r['skip']) {
            $breakdown[$r['skip']] = ($breakdown[$r['skip']] ?? 0) + 1;
            continue;
        }
        $eligible++;
        $bySource[$r['source']] = ($bySource[$r['source']] ?? 0) + 1;
        $t = $r['contact_type'] ?: 'new';
        $byType[$t] = ($byType[$t] ?? 0) + 1;
        if (count($sample) < 8) {
            $sample[] = ['name' => $r['name'], 'phone' => wa_cmp_masking() ? null : $r['phone'], 'phone_display' => wa_cmp_phone_display((string) $r['phone']),
                'contact_id' => $r['contact_id'], 'contact_type' => $r['contact_type'], 'source' => $r['source']];
        }
    }
    $dupeCount = count($res['duplicates']);
    if ($dupeCount) {
        $breakdown['duplicate'] = ($breakdown['duplicate'] ?? 0) + $dupeCount;
    }
    $reasons = wa_cmp_skip_reasons();
    $list = [];
    foreach ($breakdown as $k => $n) {
        $list[] = ['reason' => $k, 'label' => $reasons[$k] ?? label_from_key($k), 'count' => $n];
    }
    usort($list, fn ($a, $b) => $b['count'] <=> $a['count']);
    $typeLabels = function_exists('wa_crm_contact_types') ? wa_crm_contact_types() : [];
    $types = [];
    arsort($byType);
    foreach ($byType as $k => $n) {
        $types[] = ['type' => $k, 'label' => $k === 'new' ? 'New number (CSV)' : ($typeLabels[$k] ?? label_from_key($k)), 'count' => $n];
    }
    $rate = wa_cmp_rate($c);
    $skipped = array_sum($breakdown);
    return [
        'total' => $eligible + $skipped,
        'eligible' => $eligible,
        'skipped' => $skipped,
        'breakdown' => $list,
        'by_type' => $types,
        'by_source' => $bySource,
        'sample' => $sample,
        'category' => $category,
        'template_chosen' => (bool) $tpl,
        'opt_in_only' => true, // every campaign needs a WhatsApp opt-in
        'segment_missing' => ($c['audience_source'] ?? 'filters') !== 'csv' && !wa_cmp_segment_exists(wa_cmp_audience_clean($c['audience'] ?? [])),
        'capped' => $res['capped'],
        'max' => WA_CMP_MAX_AUDIENCE,
        'rate_per_minute' => $rate,
        'estimated_minutes' => $eligible > 0 ? (int) ceil($eligible / max(1, $rate)) : 0,
        'csv' => !empty($c['id']) ? wa_cmp_upload_summary((int) $c['id']) : null,
    ];
}

/** Recipients queued per minute for a campaign. */
function wa_cmp_rate(array $c): int
{
    $account = function_exists('wa_rate_per_minute') ? wa_rate_per_minute() : 250;
    $own = (int) ($c['rate_per_minute'] ?? 0);
    return max(1, $own > 0 ? min($own, $account) : $account);
}

/* ============================================================================================ CSV / XLSX upload */

/** Header aliases used to pre-select the phone / name / consent columns. */
function wa_cmp_upload_aliases(): array
{
    return [
        'phone' => ['phone', 'mobile', 'whatsapp', 'whatsapp number', 'whatsapp no', 'mobile number', 'mobile no', 'contact', 'contact number', 'phone number', 'number', 'cell'],
        'name' => ['name', 'full name', 'student name', 'contact name', 'student', 'candidate', 'candidate name', 'parent name'],
        'consent' => ['consent', 'opt in', 'opt-in', 'optin', 'whatsapp opt-in', 'whatsapp opt in', 'whatsapp consent', 'opted in', 'opted-in'],
    ];
}

function wa_cmp_header_key(string $h): string
{
    return strtolower(trim((string) preg_replace('/[^a-z0-9 \-]+/i', ' ', str_replace('_', ' ', $h))));
}

function wa_cmp_truthy($v): bool
{
    return in_array(strtolower(trim((string) $v)), ['1', 'yes', 'y', 'true', 'opted in', 'opt-in', 'opt in', 'agree', 'agreed', 'consent', 'x', '✓', 'haan', 'ha'], true);
}

/** Stored upload row (decoded headers + raw rows) or null. */
function wa_cmp_upload_row(int $campaignId): ?array
{
    static $cache = [];
    if (array_key_exists($campaignId, $cache)) {
        return $cache[$campaignId];
    }
    $r = db_row('SELECT * FROM wa_campaign_uploads WHERE campaign_id = ?', [$campaignId]);
    if ($r) {
        $r['headers'] = wa_json_decode($r['headers'], []) ?: [];
        $r['rows'] = wa_json_decode($r['rows_data'], []) ?: [];
        unset($r['rows_data']);
        foreach (['phone_column', 'name_column', 'consent_column'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
    }
    return $cache[$campaignId] = $r;
}

/**
 * Normalise an upload with its column mapping.
 * @return array{valid: array, invalid: array, duplicates: array, total: int}
 *   valid: [{row, phone, name, consent, data{header: value}}], invalid: [{row, value, name, reason}], duplicates: [{row, phone, name}]
 */
function wa_cmp_upload_analyse(array $up): array
{
    $headers = $up['headers'];
    $pc = $up['phone_column'];
    $nc = $up['name_column'];
    $cc = $up['consent_column'];
    $valid = [];
    $invalid = [];
    $dupes = [];
    $seen = [];
    foreach ($up['rows'] as $i => $raw) {
        $rowNo = $i + 2; // spreadsheet row (header is row 1)
        $raw = is_array($raw) ? $raw : [];
        $name = $nc !== null ? trim((string) ($raw[$nc] ?? '')) : '';
        $value = $pc !== null ? trim((string) ($raw[$pc] ?? '')) : '';
        if ($pc === null) {
            $invalid[] = ['row' => $rowNo, 'value' => '', 'name' => $name, 'reason' => 'No phone column selected'];
            continue;
        }
        if ($value === '') {
            $invalid[] = ['row' => $rowNo, 'value' => '', 'name' => $name, 'reason' => 'Phone number is empty'];
            continue;
        }
        // Excel stores long numbers as floats: "9.19876543210E+11" / "919876543210.0" → digits
        $digitsValue = preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $value) || preg_match('/^\d{10,15}\.0+$/', $value) ? sprintf('%.0f', (float) $value) : $value;
        $norm = wa_normalize_phone($digitsValue);
        if (!$norm) {
            $invalid[] = ['row' => $rowNo, 'value' => $value, 'name' => $name, 'reason' => 'Not a valid WhatsApp number'];
            continue;
        }
        if (isset($seen[$norm['phone']])) {
            $dupes[] = ['row' => $rowNo, 'phone' => $norm['phone'], 'name' => $name, 'first_row' => $seen[$norm['phone']]];
            continue;
        }
        $seen[$norm['phone']] = $rowNo;
        $data = [];
        foreach ($headers as $hi => $h) {
            $h = (string) $h;
            if ($h === '') {
                continue;
            }
            $data[$h] = mb_substr(trim((string) ($raw[$hi] ?? '')), 0, 500);
        }
        $valid[] = ['row' => $rowNo, 'phone' => $norm['phone'], 'name' => $name !== '' ? mb_substr($name, 0, 150) : null,
            'consent' => $cc !== null && wa_cmp_truthy($raw[$cc] ?? ''), 'data' => $data];
    }
    return ['valid' => $valid, 'invalid' => $invalid, 'duplicates' => $dupes, 'total' => count($up['rows'])];
}

/** Store (replace) the CSV / XLSX audience of a campaign. Throws CrudValidationException on a bad file. */
function wa_cmp_upload_store(int $campaignId, ?array $file): array
{
    if (!$file || empty($file['tmp_name']) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new CrudValidationException(['file' => 'Choose a CSV or Excel (.xlsx) file.']);
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx', 'txt'], true)) {
        throw new CrudValidationException(['file' => 'Only .csv and .xlsx files can be uploaded.']);
    }
    if ((int) $file['size'] > 5 * 1024 * 1024) {
        throw new CrudValidationException(['file' => 'The file is larger than 5 MB. Split it into smaller files.']);
    }
    if ($ext === 'xlsx' && !class_exists('ZipArchive')) {
        throw new CrudValidationException(['file' => 'This server cannot read .xlsx files (PHP zip extension missing). Save the sheet as CSV and upload again.']);
    }
    try {
        // read one row more than allowed (header + max + 1) so an over-long file is refused instead of silently cut off
        $limit = WA_CMP_MAX_UPLOAD_ROWS + 2;
        $rows = $ext === 'xlsx' ? xlsx_read($file['tmp_name'], $limit) : csv_read($file['tmp_name'], $limit);
    } catch (Throwable $e) {
        throw new CrudValidationException(['file' => $e->getMessage()]);
    }
    return wa_cmp_upload_save_rows($campaignId, (string) $file['name'], $rows);
}

/** Save parsed spreadsheet rows (first row = headers) as the campaign's upload; auto-detects the columns. */
function wa_cmp_upload_save_rows(int $campaignId, string $fileName, array $rows): array
{
    $rows = array_values(array_filter($rows, fn ($r) => is_array($r) && trim(implode('', array_map('strval', $r))) !== ''));
    if (count($rows) < 2) {
        throw new CrudValidationException(['file' => 'The file needs a header row and at least one recipient row.']);
    }
    if (count($rows) > WA_CMP_MAX_UPLOAD_ROWS + 1) {
        throw new CrudValidationException(['file' => 'Upload up to ' . number_format(WA_CMP_MAX_UPLOAD_ROWS) . ' recipients per file.']);
    }
    $headers = array_map(fn ($h) => mb_substr(trim((string) $h), 0, 80), array_shift($rows));
    foreach ($headers as $i => $h) {
        if ($h === '') {
            $headers[$i] = 'Column ' . ($i + 1);
        }
    }
    // unique header names (columns are referenced by name in the variable mapping)
    $seenH = [];
    foreach ($headers as $i => $h) {
        $k = mb_strtolower($h);
        if (isset($seenH[$k])) {
            $headers[$i] = $h . ' (' . ($i + 1) . ')';
        }
        $seenH[mb_strtolower($headers[$i])] = true;
    }
    $width = count($headers);
    foreach ($rows as &$r) {
        $r = array_slice(array_pad(array_map(fn ($v) => trim((string) $v), $r), $width, ''), 0, $width);
    }
    unset($r);
    $detect = [];
    foreach (wa_cmp_upload_aliases() as $key => $aliases) {
        foreach ($headers as $i => $h) {
            if (in_array(wa_cmp_header_key($h), $aliases, true) && !in_array($i, $detect, true)) {
                $detect[$key] = $i;
                break;
            }
        }
    }
    if (!isset($detect['phone'])) {
        // fall back to the first column whose first values look like phone numbers
        foreach ($headers as $i => $h) {
            $ok = 0;
            foreach (array_slice($rows, 0, 10) as $r) {
                if (wa_normalize_phone((string) ($r[$i] ?? ''))) {
                    $ok++;
                }
            }
            if ($ok >= max(1, (int) floor(min(10, count($rows)) * 0.6))) {
                $detect['phone'] = $i;
                break;
            }
        }
    }
    $up = ['headers' => $headers, 'rows' => $rows, 'phone_column' => $detect['phone'] ?? null, 'name_column' => $detect['name'] ?? null, 'consent_column' => $detect['consent'] ?? null];
    $an = wa_cmp_upload_analyse($up);
    db_transaction(function () use ($campaignId, $fileName, $up, $an) {
        db_exec('DELETE FROM wa_campaign_uploads WHERE campaign_id = ?', [$campaignId]);
        db_insert('wa_campaign_uploads', [
            'campaign_id' => $campaignId, 'file_name' => mb_substr(basename($fileName), 0, 190), 'headers' => wa_json($up['headers']),
            'rows_data' => wa_json($up['rows']), 'phone_column' => $up['phone_column'], 'name_column' => $up['name_column'],
            'consent_column' => $up['consent_column'], 'total_rows' => $an['total'], 'valid_rows' => count($an['valid']),
            'invalid_rows' => count($an['invalid']), 'duplicate_rows' => count($an['duplicates']), 'uploaded_by' => wa_user_id(),
        ]);
        $src = db_value('SELECT audience_source FROM wa_campaigns WHERE id = ?', [$campaignId]);
        if ($src === 'filters') {
            db_update('wa_campaigns', ['audience_source' => 'csv'], 'id = ?', [$campaignId]);
        }
    });
    return wa_cmp_upload_summary($campaignId, true);
}

/** Change the phone / name / consent columns of the stored upload. */
function wa_cmp_upload_map(int $campaignId, ?int $phone, ?int $name, ?int $consent): array
{
    $up = wa_cmp_upload_row_fresh($campaignId);
    if (!$up) {
        throw new CrudException('Upload a CSV or Excel file first.');
    }
    $n = count($up['headers']);
    foreach (['phone' => $phone, 'name' => $name, 'consent' => $consent] as $k => $v) {
        if ($v !== null && ($v < 0 || $v >= $n)) {
            throw new CrudValidationException([$k . '_column' => 'Choose one of the file\'s columns.']);
        }
    }
    if ($phone === null) {
        throw new CrudValidationException(['phone_column' => 'Choose the column that holds the WhatsApp number.']);
    }
    $up['phone_column'] = $phone;
    $up['name_column'] = $name;
    $up['consent_column'] = $consent;
    $an = wa_cmp_upload_analyse($up);
    db_update('wa_campaign_uploads', ['phone_column' => $phone, 'name_column' => $name, 'consent_column' => $consent, 'valid_rows' => count($an['valid']),
        'invalid_rows' => count($an['invalid']), 'duplicate_rows' => count($an['duplicates'])], 'campaign_id = ?', [$campaignId]);
    return wa_cmp_upload_summary($campaignId, true);
}

/** Upload row bypassing the per-request cache. */
function wa_cmp_upload_row_fresh(int $campaignId): ?array
{
    $r = db_row('SELECT * FROM wa_campaign_uploads WHERE campaign_id = ?', [$campaignId]);
    if (!$r) {
        return null;
    }
    $r['headers'] = wa_json_decode($r['headers'], []) ?: [];
    $r['rows'] = wa_json_decode($r['rows_data'], []) ?: [];
    unset($r['rows_data']);
    foreach (['phone_column', 'name_column', 'consent_column'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    return $r;
}

/** Client summary of the upload: columns, counts, sample rows, first invalid rows. */
function wa_cmp_upload_summary(int $campaignId, bool $fresh = false): ?array
{
    $up = $fresh ? wa_cmp_upload_row_fresh($campaignId) : wa_cmp_upload_row($campaignId);
    if (!$up) {
        return null;
    }
    $an = wa_cmp_upload_analyse($up);
    $sample = array_slice($up['rows'], 0, 5);
    $invalidSample = array_slice($an['invalid'], 0, 20);
    $dupeSample = array_slice($an['duplicates'], 0, 10);
    if (wa_cmp_masking()) {
        // phone masking (Settings › Security) also covers the uploaded list shown back to non-managers
        $pc = $up['phone_column'];
        foreach ($sample as &$row) {
            if ($pc !== null && isset($row[$pc]) && $row[$pc] !== '') {
                $d = preg_replace('/\D+/', '', (string) $row[$pc]);
                $row[$pc] = $d !== '' ? wa_adm_mask_phone($d) : '•••';
            }
        }
        unset($row);
        $invalidSample = array_map(fn ($r) => ['value' => $r['value'] !== '' ? '•••' : ''] + $r, $invalidSample);
        $dupeSample = array_map(fn ($r) => ['phone_display' => wa_cmp_format_phone($r['phone']), 'phone' => ''] + $r, $dupeSample);
    }
    $dupeSample = array_map(fn ($r) => $r + ['phone_display' => wa_cmp_format_phone($r['phone'])], $dupeSample);
    return [
        'file_name' => $up['file_name'],
        'headers' => $up['headers'],
        'phone_column' => $up['phone_column'],
        'name_column' => $up['name_column'],
        'consent_column' => $up['consent_column'],
        'total_rows' => $an['total'],
        'valid_rows' => count($an['valid']),
        'invalid_rows' => count($an['invalid']),
        'duplicate_rows' => count($an['duplicates']),
        'consent_rows' => count(array_filter($an['valid'], fn ($v) => $v['consent'])),
        'sample' => $sample,
        'invalid_sample' => $invalidSample,
        'duplicate_sample' => $dupeSample,
        'uploaded_at' => $up['created_at'],
        'updated_at' => $up['updated_at'],
    ];
}

function wa_cmp_upload_delete(int $campaignId): void
{
    db_exec('DELETE FROM wa_campaign_uploads WHERE campaign_id = ?', [$campaignId]);
    db_exec("UPDATE wa_campaigns SET audience_source = 'filters' WHERE id = ? AND audience_source IN ('csv','mixed')", [$campaignId]);
}

/* ============================================================================================ personalisation */

/** Variable keys a campaign may map to (GIMT catalog). */
function wa_cmp_catalog_keys(): array
{
    static $keys = null;
    return $keys ??= array_keys(wa_variable_catalog());
}

/**
 * Catalog keys that wa_variables_for_contact() resolves per recipient (contact, linked student / application /
 * enquiry, counsellor, institute). Event-specific keys (exam_date, event_name, receipt_no …) are not per-contact data:
 * a campaign maps them to a fixed text or a CSV column.
 */
function wa_cmp_contact_keys(): array
{
    return array_values(array_intersect([
        'name', 'first_name', 'phone', 'email', 'city', 'student_name', 'student_first_name', 'admission_no', 'roll_no', 'enrollment_no',
        'course_name', 'program_short', 'batch', 'semester', 'department', 'guardian_name', 'application_number', 'application_status',
        'admission_stage', 'counsellor_name', 'counsellor_phone', 'due_amount', 'due_date', 'admission_link', 'institute_name', 'institute_phone',
        'institute_email', 'website_url', 'apply_link', 'academic_session', 'date_today',
    ], wa_cmp_catalog_keys()));
}

/** Default mapping for a template: catalog key → contact value; matching CSV column; media header → template sample. */
function wa_cmp_default_map(array $tpl, array $csvHeaders = []): array
{
    $byKey = [];
    foreach ($csvHeaders as $h) {
        $byKey[wa_cmp_header_key((string) $h)] = (string) $h;
    }
    $map = [];
    foreach (wa_cmp_slots($tpl) as $s) {
        if ($s['kind'] === 'media') {
            $map[$s['key']] = ['source' => 'literal', 'value' => (string) ($tpl['header_media_url'] ?? ''), 'fallback' => ''];
            continue;
        }
        $vk = $s['var_key'];
        $csvKey = $vk !== '' ? wa_cmp_header_key($vk) : '';
        if ($csvKey !== '' && isset($byKey[$csvKey])) {
            $map[$s['key']] = ['source' => 'csv', 'value' => $byKey[$csvKey], 'fallback' => ''];
        } elseif ($vk !== '' && in_array($vk, wa_cmp_contact_keys(), true)) {
            $map[$s['key']] = ['source' => 'contact', 'value' => $vk, 'fallback' => in_array($vk, ['student_name', 'name', 'first_name', 'student_first_name'], true) ? 'Student' : ''];
        } else {
            $map[$s['key']] = ['source' => 'literal', 'value' => '', 'fallback' => ''];
        }
    }
    return $map;
}

/** Keep only the template's slots; sources contact|csv|literal. */
function wa_cmp_map_clean($map, ?array $tpl): array
{
    $map = is_array($map) ? $map : (wa_json_decode($map, []) ?: []);
    if (!$tpl) {
        return [];
    }
    $out = [];
    foreach (wa_cmp_slots($tpl) as $s) {
        $m = $map[$s['key']] ?? null;
        if (!is_array($m)) {
            continue;
        }
        $src = in_array($m['source'] ?? '', ['contact', 'csv', 'literal'], true) ? $m['source'] : 'literal';
        $out[$s['key']] = [
            'source' => $src,
            'value' => mb_substr(trim(api_str($m['value'] ?? '')), 0, 1000),
            'fallback' => mb_substr(trim(api_str($m['fallback'] ?? '')), 0, 500),
        ];
    }
    return $out;
}

/** Mapping problems (slot key => message) for the readiness check. */
function wa_cmp_map_errors(array $tpl, array $map, ?array $upload): array
{
    $errors = [];
    $headers = $upload ? array_map('mb_strtolower', array_map('strval', $upload['headers'])) : [];
    foreach (wa_cmp_slots($tpl) as $s) {
        $m = $map[$s['key']] ?? null;
        if (!$m) {
            $errors[$s['key']] = 'Choose a value for ' . $s['label'] . '.';
            continue;
        }
        if ($m['source'] === 'literal') {
            if ($m['value'] === '' && $m['fallback'] === '') {
                $errors[$s['key']] = 'Enter a value for ' . $s['label'] . '.';
            } elseif ($s['kind'] === 'media' && !preg_match('#^https://#i', $m['value'])) {
                $errors[$s['key']] = 'The header needs a public https:// URL of the ' . ($tpl['header_type'] ?? 'media') . ' file.';
            }
        } elseif ($m['source'] === 'contact') {
            if (!in_array($m['value'], wa_cmp_catalog_keys(), true)) {
                $errors[$s['key']] = 'Choose a contact field for ' . $s['label'] . '.';
            } elseif (!in_array($m['value'], wa_cmp_contact_keys(), true) && $m['fallback'] === '') {
                $errors[$s['key']] = '"' . (wa_variable_catalog()[$m['value']]['label'] ?? $m['value']) . '" is not stored per contact — use a fixed text or a list column for ' . $s['label'] . '.';
            }
        } elseif ($m['source'] === 'csv') {
            if (!$upload) {
                $errors[$s['key']] = $s['label'] . ' uses a CSV column but no file is uploaded.';
            } elseif (!in_array(mb_strtolower($m['value']), $headers, true)) {
                $errors[$s['key']] = 'Choose one of the uploaded file\'s columns for ' . $s['label'] . '.';
            }
        }
    }
    return $errors;
}

/**
 * Resolve a recipient's values. $contact: wa_contacts row or a pseudo row ['phone','name']; $csv: header => value.
 * @return array{vars: array, values: array, missing: array}  vars = positional wa_send() variables
 */
function wa_cmp_resolve(array $tpl, array $map, ?array $contact, array $csv = [], bool $useSamples = false): array
{
    $slots = wa_cmp_slots($tpl);
    $cv = null;
    $csvLower = [];
    foreach ($csv as $k => $v) {
        $csvLower[mb_strtolower((string) $k)] = (string) $v;
    }
    $values = [];
    $missing = [];
    $vars = ['header' => [], 'body' => [], 'buttons' => []];
    foreach ($slots as $s) {
        $m = $map[$s['key']] ?? ['source' => 'literal', 'value' => '', 'fallback' => ''];
        $v = '';
        if ($m['source'] === 'contact') {
            if ($cv === null) {
                $cv = wa_variables_for_contact($contact ?: []);
            }
            $v = (string) ($cv[$m['value']] ?? '');
        } elseif ($m['source'] === 'csv') {
            $v = (string) ($csvLower[mb_strtolower((string) $m['value'])] ?? '');
        } else {
            $v = (string) $m['value'];
        }
        $v = trim($v);
        if ($v === '' && ($m['fallback'] ?? '') !== '') {
            $v = (string) $m['fallback'];
        }
        if ($v === '' && $useSamples) {
            $v = $s['sample'] !== '' ? $s['sample'] : (wa_variable_catalog()[$m['value'] ?? '']['sample'] ?? '');
        }
        $values[$s['key']] = $v;
        if ($v === '') {
            $missing[] = $s['key'];
        }
        if ($s['kind'] === 'media') {
            if ($v !== '') {
                $vars['header_media_url'] = $v;
            }
        } elseif ($s['section'] === 'header') {
            $vars['header'][$s['pos'] - 1] = $v;
        } elseif ($s['section'] === 'body') {
            $vars['body'][$s['pos'] - 1] = $v;
        } else {
            $vars['buttons'][(string) $s['pos']] = [$v];
        }
    }
    ksort($vars['header']);
    ksort($vars['body']);
    $vars['header'] = array_values($vars['header']);
    $vars['body'] = array_values($vars['body']);
    if (!$vars['buttons']) {
        unset($vars['buttons']);
    }
    return ['vars' => $vars, 'values' => $values, 'missing' => $missing];
}

/** Template preview data (WaBubble) for resolved values. */
function wa_cmp_render_preview(array $tpl, array $vars): array
{
    $r = wa_render_template($tpl, $vars);
    $buttons = [];
    foreach (array_values($tpl['buttons'] ?? []) as $i => $b) {
        if (!empty($b['url']) && isset($r['buttons'][(string) $i][0])) {
            $b['url'] = wa_fill_positional((string) $b['url'], [$r['buttons'][(string) $i][0]]);
        }
        $buttons[] = $b;
    }
    return [
        'header_type' => $tpl['header_type'], 'header_text' => $r['header_text'], 'header_media_url' => $r['media_url'] ?? ($tpl['header_media_url'] ?? null),
        'file_name' => $r['file_name'], 'body' => $r['text'], 'footer' => $tpl['footer'], 'buttons' => $buttons,
    ];
}

/** Rendered previews for the first eligible recipients (or one sample recipient when the audience is empty). */
function wa_cmp_personalize_preview(array $c, array $map, ?int $templateId = null, int $limit = 5): array
{
    $tpl = wa_template_find((int) ($templateId ?: $c['template_id']));
    if (!$tpl) {
        throw new CrudException('Choose a template first.');
    }
    $map = wa_cmp_map_clean($map, $tpl);
    $upload = !empty($c['id']) ? wa_cmp_upload_row((int) $c['id']) : null;
    $res = wa_cmp_candidates($c, wa_cmp_category($c, $tpl));
    $samples = [];
    foreach ($res['rows'] as $r) {
        if ($r['skip']) {
            continue;
        }
        $contact = $r['contact_id'] ? wa_contact((int) $r['contact_id']) : ['phone' => $r['phone'], 'name' => $r['name']];
        $samples[] = ['recipient' => ['name' => $r['name'], 'phone' => wa_cmp_masking() ? null : $r['phone'], 'phone_display' => wa_cmp_phone_display((string) $r['phone']),
            'contact_type' => $r['contact_type'], 'source' => $r['source']], 'contact' => $contact, 'csv' => $r['data']];
        if (count($samples) >= $limit) {
            break;
        }
    }
    $isSample = false;
    if (!$samples) {
        $isSample = true;
        // nobody eligible yet: still show the first uploaded row so CSV columns can be checked
        $first = $upload ? (wa_cmp_upload_analyse($upload)['valid'][0] ?? null) : null;
        $samples[] = $first
            ? ['recipient' => ['name' => $first['name'] ?: 'First row of the list', 'phone' => wa_cmp_masking() ? null : $first['phone'], 'phone_display' => wa_cmp_phone_display($first['phone']),
                'contact_type' => null, 'source' => 'sample'], 'contact' => ['phone' => $first['phone'], 'name' => $first['name']], 'csv' => $first['data']]
            : ['recipient' => ['name' => 'Sample recipient', 'phone' => null, 'phone_display' => null, 'contact_type' => null, 'source' => 'sample'], 'contact' => null, 'csv' => []];
    }
    $out = [];
    foreach ($samples as $s) {
        $rv = wa_cmp_resolve($tpl, $map, $s['contact'], $s['csv'], $isSample);
        // real recipients show their gaps as {{n}}; the empty-audience sample is filled with the template samples
        $out[] = ['recipient' => $s['recipient'], 'values' => $rv['values'], 'missing' => $rv['missing'],
            'preview' => wa_cmp_render_preview($tpl, $isSample ? $rv['vars'] + ['_use_samples' => true] : $rv['vars'])];
    }
    return ['samples' => $out, 'is_sample' => $isSample, 'errors' => wa_cmp_map_errors($tpl, $map, $upload), 'slots' => wa_cmp_slots($tpl)];
}

/* ============================================================================================ save / validate */

/**
 * Create or update a draft. $in: name, type, description, template_id, audience_source, audience{}, variable_map{},
 * rate_per_minute, respect_business_hours. Throws CrudValidationException.
 */
function wa_cmp_save(array $in, ?int $id = null): int
{
    $old = $id ? wa_cmp_get($id) : null;
    if ($id && !$old) {
        throw new CrudException('Campaign not found.');
    }
    if ($old && !in_array($old['status'], ['draft', 'scheduled'], true)) {
        throw new CrudException('Only draft or scheduled campaigns can be edited. Duplicate this campaign to send it again.');
    }
    $has = fn (string $k) => array_key_exists($k, $in);
    $errors = [];
    $data = [];

    if (!$old || $has('name')) {
        $name = trim(api_str($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $errors['name'] = 'Enter a campaign name (up to 150 characters).';
        } else {
            $data['name'] = $name;
        }
    }
    if (!$old || $has('type')) {
        $type = api_str($in['type'] ?? '');
        if (!isset(wa_cmp_types()[$type])) {
            $errors['type'] = 'Choose a campaign type.';
        } else {
            $data['type'] = $type;
        }
    }
    if ($has('description')) {
        $d = trim(api_str($in['description'] ?? ''));
        if (mb_strlen($d) > 500) {
            $errors['description'] = 'Keep the description under 500 characters.';
        } else {
            $data['description'] = $d !== '' ? $d : null;
        }
    }
    $tpl = null;
    if ($has('template_id')) {
        $tid = (int) api_str($in['template_id'] ?? '');
        if ($tid > 0) {
            $tpl = wa_template_find($tid);
            $problem = wa_cmp_template_problem($tpl);
            if ($problem) {
                $errors['template_id'] = $problem;
            } else {
                $data['template_id'] = $tid;
            }
        } else {
            $data['template_id'] = null;
        }
    } elseif ($old && $old['template_id']) {
        $tpl = wa_template_find((int) $old['template_id']);
    }
    if ($has('audience_source')) {
        $src = api_str($in['audience_source'] ?? '');
        if (!in_array($src, ['filters', 'csv', 'mixed'], true)) {
            $errors['audience_source'] = 'Choose who receives the campaign.';
        } else {
            $data['audience_source'] = $src;
        }
    }
    if ($has('audience')) {
        $aud = wa_cmp_audience_clean($in['audience']);
        if (!empty($aud['segment_id']) && !db_value('SELECT id FROM wa_segments WHERE id = ?', [$aud['segment_id']])) {
            $errors['segment_id'] = 'The saved segment no longer exists.';
        }
        $data['audience'] = wa_json($aud);
    }
    if ($has('variable_map')) {
        $data['variable_map'] = wa_json((object) wa_cmp_map_clean($in['variable_map'], $tpl));
    } elseif (isset($data['template_id']) && $old && (int) $old['template_id'] !== (int) $data['template_id']) {
        $data['variable_map'] = null; // template changed: the old mapping does not apply
    }
    if ($has('rate_per_minute')) {
        $r = api_str($in['rate_per_minute'] ?? '');
        if ($r === '' || $r === '0') {
            $data['rate_per_minute'] = null;
        } elseif (!ctype_digit($r) || (int) $r < 10 || (int) $r > 1000) {
            $errors['rate_per_minute'] = 'Use a speed between 10 and 1,000 messages per minute (or leave it empty for the account rate).';
        } else {
            $data['rate_per_minute'] = (int) $r;
        }
    }
    if ($has('respect_business_hours')) {
        $data['respect_business_hours'] = !empty($in['respect_business_hours']) && $in['respect_business_hours'] !== '0' && $in['respect_business_hours'] !== 'false' ? 1 : 0;
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    if (!$old) {
        $data += ['status' => 'draft', 'audience_source' => 'filters', 'audience' => wa_json(wa_cmp_audience_clean([])),
            'created_by' => wa_user_id(), 'updated_by' => wa_user_id()];
        if ($tpl && !isset($data['variable_map'])) {
            $data['variable_map'] = wa_json((object) wa_cmp_default_map($tpl));
        }
        return db_insert('wa_campaigns', $data);
    }
    if ($old['status'] === 'scheduled') {
        $data['status'] = 'draft'; // edited after scheduling: review and schedule again
        $data['scheduled_at'] = null;
    }
    if (isset($data['template_id']) && $data['template_id'] && (!isset($data['variable_map']) || $data['variable_map'] === null) && (int) $old['template_id'] !== (int) $data['template_id']) {
        $up = wa_cmp_upload_row_fresh($id);
        $data['variable_map'] = wa_json((object) wa_cmp_default_map($tpl, $up['headers'] ?? []));
    }
    $data['updated_by'] = wa_user_id();
    db_update('wa_campaigns', $data, 'id = ?', [$id]);
    return $id;
}

/** Store the live audience estimate on a draft / scheduled campaign (list column "Audience"). Never throws. */
function wa_cmp_store_estimate(int $id): void
{
    try {
        $c = wa_cmp_get($id);
        if ($c && in_array($c['status'], ['draft', 'scheduled'], true)) {
            $p = wa_cmp_audience_preview($c);
            db_update('wa_campaigns', ['total_recipients' => $p['total'], 'skipped_count' => $p['skipped']], 'id = ?', [$id]);
        }
    } catch (Throwable $e) {
        error_log('[GIMT] wa_cmp_store_estimate: ' . $e->getMessage());
    }
}

/**
 * Everything needed before sending: template, audience, mapping. Returns ['ok', 'errors' => [step => msg], 'eligible'].
 */
function wa_cmp_readiness(array $c): array
{
    $errors = [];
    $tpl = $c['template_id'] ? wa_template_find((int) $c['template_id']) : null;
    $problem = wa_cmp_template_problem($tpl);
    if ($problem) {
        $errors['template'] = $problem;
    }
    $preview = wa_cmp_audience_preview($c);
    if (in_array($c['audience_source'], ['filters', 'mixed'], true) && !wa_cmp_segment_exists(wa_cmp_audience_clean($c['audience'] ?? []))) {
        $errors['audience'] = 'The saved segment of this campaign was deleted. Choose another segment or clear it on the Audience step.';
    } elseif (in_array($c['audience_source'], ['csv', 'mixed'], true) && !wa_cmp_upload_row((int) $c['id'])) {
        $errors['audience'] = 'Upload the CSV / Excel recipient list, or switch the audience to CRM filters.';
    } elseif ($preview['eligible'] < 1) {
        $errors['audience'] = 'Nobody in this audience can receive the campaign. Widen the filters or check the skipped reasons.';
    }
    if ($tpl && !$problem) {
        $mapErrors = wa_cmp_map_errors($tpl, wa_cmp_map_clean($c['variable_map'], $tpl), wa_cmp_upload_row((int) $c['id']));
        if ($mapErrors) {
            $errors['personalise'] = reset($mapErrors);
            $errors['variables'] = $mapErrors;
        }
    }
    return ['ok' => !$errors, 'errors' => $errors, 'eligible' => $preview['eligible'], 'skipped' => $preview['skipped'], 'total' => $preview['total'],
        'estimated_minutes' => $preview['estimated_minutes'], 'category' => $preview['category']];
}

/* ============================================================================================ duplicate / delete */

function wa_cmp_duplicate(int $id): int
{
    $c = wa_cmp_get($id);
    if (!$c) {
        throw new CrudException('Campaign not found.');
    }
    $tpl = $c['template_id'] ? wa_template_find((int) $c['template_id']) : null;
    $newId = db_transaction(function () use ($c, $tpl) {
        $name = mb_substr($c['name'] . ' (copy)', 0, 150);
        $nid = db_insert('wa_campaigns', [
            'name' => $name, 'type' => $c['type'], 'description' => $c['description'] ?? null,
            'template_id' => $tpl && $tpl['status'] === 'approved' ? (int) $tpl['id'] : null,
            'audience' => wa_json($c['audience']), 'audience_source' => $c['audience_source'],
            'variable_map' => $tpl && $tpl['status'] === 'approved' ? wa_json((object) $c['variable_map']) : null,
            'rate_per_minute' => $c['rate_per_minute'], 'respect_business_hours' => $c['respect_business_hours'],
            'status' => 'draft', 'created_by' => wa_user_id(), 'updated_by' => wa_user_id(),
        ]);
        $up = db_row('SELECT * FROM wa_campaign_uploads WHERE campaign_id = ?', [(int) $c['id']]);
        if ($up) {
            unset($up['id'], $up['created_at'], $up['updated_at']);
            $up['campaign_id'] = $nid;
            $up['uploaded_by'] = wa_user_id();
            db_insert('wa_campaign_uploads', $up);
        }
        return $nid;
    });
    return (int) $newId;
}

function wa_cmp_delete(int $id, bool $canManage): array
{
    $c = wa_cmp_get($id);
    if (!$c) {
        throw new CrudException('Campaign not found.');
    }
    $allowed = in_array($c['status'], ['draft', 'cancelled', 'failed'], true) || ($c['status'] === 'completed' && $canManage);
    if (!$allowed) {
        throw new CrudException(in_array($c['status'], ['sending', 'paused', 'scheduled'], true)
            ? 'Cancel the campaign before deleting it.'
            : 'Completed campaigns are kept for reporting. Only a WhatsApp manager can delete them.');
    }
    db_delete('wa_campaigns', 'id = ?', [$id]); // recipients + upload cascade; sent messages keep campaign_id for history
    return $c;
}

/* ============================================================================================ lifecycle */

function wa_cmp_lock(int $id, int $timeout = 0): bool
{
    return (int) db_value('SELECT GET_LOCK(?, ?)', ['gimt_wa_cmp_' . $id, $timeout]) === 1;
}

function wa_cmp_unlock(int $id): void
{
    db_value('SELECT RELEASE_LOCK(?)', ['gimt_wa_cmp_' . $id]);
}

/** Schedule a ready campaign for later. */
function wa_cmp_schedule(int $id, string $at): array
{
    $c = wa_cmp_get($id);
    if (!$c) {
        throw new CrudException('Campaign not found.');
    }
    if (!in_array($c['status'], ['draft', 'scheduled'], true)) {
        throw new CrudException('Only draft campaigns can be scheduled.');
    }
    $ts = strtotime($at);
    if (!$ts) {
        throw new CrudValidationException(['scheduled_at' => 'Choose a valid date and time.']);
    }
    if ($ts < time() + 60) {
        throw new CrudValidationException(['scheduled_at' => 'Choose a time at least a minute from now (or send now).']);
    }
    if ($ts > strtotime('+180 days')) {
        throw new CrudValidationException(['scheduled_at' => 'Campaigns can be scheduled up to 6 months ahead.']);
    }
    $ready = wa_cmp_readiness($c);
    if (!$ready['ok']) {
        throw new CrudValidationException(array_filter($ready['errors'], 'is_string'));
    }
    db_update('wa_campaigns', ['status' => 'scheduled', 'scheduled_at' => date('Y-m-d H:i:s', $ts), 'last_error' => null, 'updated_by' => wa_user_id()], 'id = ?', [$id]);
    return $ready;
}

/**
 * Build the recipient snapshot of a campaign: eligible rows pending, the rest skipped with their reason.
 * Replaces any rows not yet sent. Returns counts.
 */
function wa_cmp_build_recipients(array $c, array $tpl): array
{
    $res = wa_cmp_candidates($c, wa_cmp_category($c, $tpl));
    $reasons = wa_cmp_skip_reasons();
    $id = (int) $c['id'];
    $now = wa_cmp_now();
    $insert = [];
    $eligible = 0;
    $skipped = 0;
    foreach ($res['rows'] as $r) {
        $phone = $r['phone'] ?? null;
        if ($phone === null) {
            // invalid CSV number: keep a row for the report; the unique (campaign, phone) key gets "x<row>:<raw value>"
            $phone = 'x' . (int) ($r['row'] ?? 0) . ':' . preg_replace('/[^0-9+]/', '', (string) ($r['raw_phone'] ?? ''));
        }
        $row = [
            'campaign_id' => $id, 'contact_id' => $r['contact_id'], 'phone' => mb_substr($phone, 0, 20), 'name' => $r['name'] ? mb_substr((string) $r['name'], 0, 150) : null,
            'source' => $r['source'], 'contact_type' => $r['contact_type'],
            'variables' => wa_json(['csv' => (object) $r['data'], 'consent' => $r['consent']]),
            'status' => $r['skip'] ? 'skipped' : 'pending', 'skip_reason' => $r['skip'], 'error' => $r['skip'] ? ($reasons[$r['skip']] ?? $r['skip']) : null,
            'created_at' => $now,
        ];
        $r['skip'] ? $skipped++ : $eligible++;
        $insert[] = $row;
    }
    foreach ($res['duplicates'] as $d) {
        // repeated number: one message per phone; the extra row is kept as skipped for the report ("d<row>:<phone>")
        $insert[] = [
            'campaign_id' => $id, 'contact_id' => null, 'phone' => mb_substr('d' . (int) $d['row'] . ':' . $d['phone'], 0, 20),
            'name' => $d['name'] ? mb_substr((string) $d['name'], 0, 150) : null, 'source' => 'csv', 'contact_type' => null,
            'variables' => null, 'status' => 'skipped', 'skip_reason' => 'duplicate', 'error' => $reasons['duplicate'], 'created_at' => $now,
        ];
        $skipped++;
    }
    db_transaction(function () use ($id, $insert) {
        db_exec("DELETE FROM wa_campaign_recipients WHERE campaign_id = ? AND message_id IS NULL AND status IN ('pending','skipped')", [$id]);
        $existing = array_flip(db_column('SELECT phone FROM wa_campaign_recipients WHERE campaign_id = ?', [$id]));
        $rows = array_values(array_filter($insert, fn ($r) => !isset($existing[$r['phone']])));
        foreach (array_chunk($rows, 300) as $chunk) {
            $cols = array_keys($chunk[0]);
            $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $args = [];
            foreach ($chunk as $r) {
                foreach ($cols as $col) {
                    $args[] = $r[$col];
                }
            }
            db_exec('INSERT IGNORE INTO wa_campaign_recipients (' . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $ph)), $args);
        }
    });
    return ['eligible' => $eligible, 'skipped' => $skipped, 'duplicates' => count($res['duplicates']), 'capped' => $res['capped']];
}

/** Interakt API campaign (live mode only) so the Interakt dashboard shows analytics. Returns the id or null. */
function wa_cmp_interakt_create(array $c, array $tpl): ?string
{
    if (!function_exists('wa_mode') || wa_mode() !== 'live' || !empty($c['interakt_campaign_id'])) {
        return $c['interakt_campaign_id'] ?? null;
    }
    $r = wa_interakt_request('POST', 'create-campaign/', [
        'campaign_name' => mb_substr($c['name'] . ' (GIMT #' . $c['id'] . ')', 0, 120),
        'campaign_type' => 'PublicAPI',
        'template_name' => $tpl['name'],
        'language_code' => $tpl['language'] ?: 'en',
    ]);
    $data = is_array($r['data'] ?? null) ? $r['data'] : [];
    $cid = $data['data']['campaign_id'] ?? ($data['data']['campaignId'] ?? ($data['campaign_id'] ?? ($data['campaignId'] ?? ($data['id'] ?? null))));
    if ($r['ok'] && $cid) {
        $cid = mb_substr((string) $cid, 0, 64);
        db_update('wa_campaigns', ['interakt_campaign_id' => $cid], 'id = ?', [(int) $c['id']]);
        return $cid;
    }
    error_log('[GIMT] Interakt create-campaign failed for campaign #' . $c['id'] . ': ' . ($r['error'] ?? 'no id returned'));
    return null;
}

/**
 * Start sending (manual "send now" or a due schedule): build recipients, create the Interakt campaign (live), mark
 * sending and queue the first batch. $firstBatch = how many to queue right away (0 = leave it to the worker).
 */
function wa_cmp_start(int $id, string $trigger = 'manual', int $firstBatch = 100): array
{
    if (!wa_cmp_lock($id, 5)) {
        throw new CrudException('This campaign is being processed. Try again in a moment.');
    }
    try {
        $c = wa_cmp_get($id);
        if (!$c) {
            throw new CrudException('Campaign not found.');
        }
        if (!in_array($c['status'], ['draft', 'scheduled'], true)) {
            throw new CrudException('This campaign has already been started.');
        }
        $tpl = $c['template_id'] ? wa_template_find((int) $c['template_id']) : null;
        $ready = wa_cmp_readiness($c);
        if (!$ready['ok']) {
            if ($trigger === 'schedule') {
                $msg = mb_substr(implode(' ', array_filter($ready['errors'], 'is_string')), 0, 255);
                if (db_exec("UPDATE wa_campaigns SET status = 'failed', last_error = ?, completed_at = ? WHERE id = ? AND status = 'scheduled'", [$msg, wa_cmp_now(), $id]) > 0) {
                    log_activity('send', 'whatsapp_campaigns', $id, 'Scheduled WhatsApp campaign "' . $c['name'] . '" could not start: ' . $msg, 'failed');
                    // tell the people who scheduled it (the worker has no user to show a toast to)
                    $who = array_values(array_unique(array_filter([(int) ($c['created_by'] ?? 0), (int) ($c['updated_by'] ?? 0)])));
                    if ($who) {
                        notify($who, 'whatsapp', 'Scheduled campaign did not start', '"' . $c['name'] . '": ' . $msg, 'admin/whatsapp/campaigns/' . $id, 'alert-triangle');
                    }
                }
                return ['ok' => false, 'error' => $msg];
            }
            throw new CrudValidationException(array_filter($ready['errors'], 'is_string'));
        }
        // claim the start atomically (a cancel or another start in between wins), then build the recipient snapshot
        $now = wa_cmp_now();
        $claimed = db_exec("UPDATE wa_campaigns SET status = 'sending', started_at = COALESCE(started_at, ?), paused_at = NULL, last_error = NULL,
                scheduled_at = COALESCE(scheduled_at, ?) WHERE id = ? AND status IN ('draft','scheduled')", [$now, $now, $id]);
        if ($claimed < 1) {
            throw new CrudException('This campaign has already been started or cancelled.');
        }
        try {
            $built = wa_cmp_build_recipients($c, $tpl);
        } catch (Throwable $e) {
            // building failed (e.g. database error): back to the previous state so it can be sent again
            db_exec("UPDATE wa_campaigns SET status = ?, started_at = ? WHERE id = ? AND status = 'sending'", [$c['status'], $c['started_at'], $id]);
            throw $e;
        }
        $c = wa_cmp_get($id);
        wa_cmp_interakt_create($c, $tpl);
        $c = wa_cmp_get($id);
        wa_cmp_sync($id);
        $queued = ['queued' => 0];
        if ($firstBatch > 0 && (!$c['respect_business_hours'] || wa_in_business_hours())) {
            $queued = wa_cmp_enqueue($c, min($firstBatch, wa_cmp_rate($c)), microtime(true) + 6.0);
        }
        $c = wa_cmp_sync($id);
        log_activity('send', 'whatsapp_campaigns', $id, ($trigger === 'schedule' ? 'WhatsApp campaign "' . $c['name'] . '" started at its scheduled time: ' : 'Started WhatsApp campaign "' . $c['name'] . '": ')
            . number_format($built['eligible']) . ' recipients (' . number_format($built['skipped']) . ' skipped)');
        return ['ok' => true, 'built' => $built, 'queued' => $queued['queued'] ?? 0, 'campaign' => $c];
    } finally {
        wa_cmp_unlock($id);
    }
}

/**
 * How many recipients a sending campaign may queue now: its rate per minute minus what is still waiting in the outbox
 * and minus what was queued during the last 55 s (so extra worker runs — "Process queue now", light ticks — never push
 * a campaign above its speed).
 */
function wa_cmp_room(array $c): int
{
    $id = (int) $c['id'];
    $rate = wa_cmp_rate($c);
    $backlog = (int) db_value("SELECT COUNT(*) FROM wa_campaign_recipients WHERE campaign_id = ? AND status = 'queued'", [$id]);
    $recent = (int) db_value('SELECT COUNT(*) FROM wa_campaign_recipients WHERE campaign_id = ? AND queued_at >= ?', [$id, date('Y-m-d H:i:s', time() - 55)]);
    return max(0, $rate - max($backlog, $recent));
}

/**
 * Self-healing for recipient rows left 'queued' (call with the campaign lock held):
 *  - claimed but never handed to wa_send (the PHP process died between claim and send) for longer than $olderThan s
 *    → back to pending ($to = 'pending') or skipped as cancelled ($to = 'cancelled');
 *  - their message already moved on (sent / simulated / delivered / read / failed) without the recipient following
 *    → the recipient takes the message's status;
 *  - their message was cancelled outside the campaign (e.g. from the retry queue) → skipped with that reason.
 * Returns the number of rows changed.
 */
function wa_cmp_recover_stuck(int $id, string $to = 'pending', int $olderThan = 600): int
{
    $n = 0;
    $cut = date('Y-m-d H:i:s', time() - max(0, $olderThan));
    if ($to === 'cancelled') {
        $n += db_exec("UPDATE wa_campaign_recipients SET status = 'skipped', skip_reason = 'cancelled', error = 'Campaign cancelled'
            WHERE campaign_id = ? AND status = 'queued' AND message_id IS NULL AND (queued_at IS NULL OR queued_at <= ?)", [$id, $cut]);
    } else {
        // the attempt counter goes back too: the next claim reuses the same dedupe key ("cmp:{campaign}:{recipient}:{n}"),
        // so a message that was created just before the process died is found by wa_send (code duplicate) and linked
        // instead of being sent a second time
        $n += db_exec("UPDATE wa_campaign_recipients SET status = 'pending', queued_at = NULL, attempts = GREATEST(attempts, 1) - 1
            WHERE campaign_id = ? AND status = 'queued' AND message_id IS NULL AND (queued_at IS NULL OR queued_at <= ?)", [$id, $cut]);
    }
    $n += db_exec("UPDATE wa_campaign_recipients r JOIN wa_messages m ON m.id = r.message_id
            SET r.status = CASE m.status WHEN 'simulated' THEN 'sent' ELSE m.status END,
                r.error = CASE WHEN m.status = 'failed' THEN LEFT(COALESCE(NULLIF(m.error, ''), 'Delivery failed'), 255) ELSE r.error END
          WHERE r.campaign_id = ? AND r.status = 'queued' AND m.status IN ('simulated','sent','delivered','read','failed')", [$id]);
    $n += db_exec("UPDATE wa_campaign_recipients r JOIN wa_messages m ON m.id = r.message_id
            SET r.status = 'skipped', r.skip_reason = COALESCE(r.skip_reason, 'cancelled'), r.error = LEFT(COALESCE(NULLIF(m.error, ''), 'Message cancelled'), 255)
          WHERE r.campaign_id = ? AND r.status = 'queued' AND m.status = 'cancelled'", [$id]);
    return $n;
}

/** Map a wa_send refusal code to a recipient skip reason. */
function wa_cmp_refusal_reason(?string $code): string
{
    return [
        // recipients are opt-in checked before sending, so a core 'opted_out' refusal means a STOP arrived in between
        'invalid_phone' => 'invalid_number', 'blocked' => 'blocked', 'opted_out' => 'opted_out', 'missing_variables' => 'missing_variables',
        'template_not_approved' => 'template', 'template_not_found' => 'template',
    ][$code ?? ''] ?? 'template';
}

/**
 * Queue up to $limit pending recipients of a sending campaign through wa_send_template(). Re-checks consent (a contact
 * may have opted out since the build). Returns ['queued', 'skipped', 'failed_campaign' => bool].
 */
function wa_cmp_enqueue(array $c, int $limit, float $deadline): array
{
    $out = ['queued' => 0, 'skipped' => 0, 'failed_campaign' => false];
    if ($limit < 1) {
        return $out;
    }
    $id = (int) $c['id'];
    $tpl = $c['template_id'] ? wa_template_find((int) $c['template_id']) : null;
    $problem = wa_cmp_template_problem($tpl);
    if ($problem) {
        db_update('wa_campaigns', ['status' => 'failed', 'last_error' => mb_substr($problem, 0, 255), 'completed_at' => wa_cmp_now()], 'id = ?', [$id]);
        db_exec("UPDATE wa_campaign_recipients SET status = 'skipped', skip_reason = 'template', error = ? WHERE campaign_id = ? AND status = 'pending'", [mb_substr($problem, 0, 255), $id]);
        log_activity('send', 'whatsapp_campaigns', $id, 'WhatsApp campaign "' . $c['name'] . '" stopped: ' . $problem, 'failed');
        $out['failed_campaign'] = true;
        return $out;
    }
    $map = wa_cmp_map_clean($c['variable_map'], $tpl);
    $category = wa_cmp_category($c, $tpl);
    $reasons = wa_cmp_skip_reasons();
    $rows = db_all("SELECT * FROM wa_campaign_recipients WHERE campaign_id = ? AND status = 'pending' ORDER BY id LIMIT " . (int) $limit, [$id]);
    // contacts of the batch in one query (re-read now: someone may have opted out or been blocked since the build)
    $contacts = [];
    $cids = array_values(array_unique(array_filter(array_map(fn ($r) => (int) $r['contact_id'], $rows))));
    foreach (array_chunk($cids, 500) as $chunk) {
        foreach (db_all('SELECT * FROM wa_contacts WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $ct) {
            $contacts[(int) $ct['id']] = $ct;
        }
    }
    $n = 0;
    foreach ($rows as $r) {
        if (microtime(true) > $deadline) {
            break;
        }
        // a pause / cancel from another request stops the batch quickly (it flips the status before recalling)
        if ($n++ % 25 === 0 && db_value('SELECT status FROM wa_campaigns WHERE id = ?', [$id]) !== 'sending') {
            break;
        }
        $claimed = db_exec("UPDATE wa_campaign_recipients SET status = 'queued', queued_at = ?, attempts = attempts + 1 WHERE id = ? AND status = 'pending'", [wa_cmp_now(), (int) $r['id']]);
        if (!$claimed) {
            continue;
        }
        $vars = wa_json_decode($r['variables'], []) ?: [];
        $csv = is_array($vars['csv'] ?? null) ? $vars['csv'] : [];
        $consent = !empty($vars['consent']);
        $contact = $r['contact_id'] ? ($contacts[(int) $r['contact_id']] ?? wa_contact((int) $r['contact_id'])) : wa_contact_by_phone((string) $r['phone']);
        $skip = function (string $reason, ?string $msg = null) use ($r, $reasons, &$out) {
            db_update('wa_campaign_recipients', ['status' => 'skipped', 'skip_reason' => $reason, 'error' => mb_substr($msg ?? ($reasons[$reason] ?? $reason), 0, 255)], 'id = ?', [(int) $r['id']]);
            $out['skipped']++;
        };
        $reason = wa_cmp_skip_reason($contact, $consent, $category);
        if ($reason) {
            $skip($reason);
            continue;
        }
        if (!$contact) {
            // CSV number that is not a contact yet: create it (consent from the file opts it in)
            try {
                $cid = wa_contact_upsert(['phone' => $r['phone'], 'name' => $r['name'], 'contact_type' => 'lead', 'opt_in' => $consent ? 1 : null,
                    'opt_in_source' => $consent ? 'Campaign upload consent' : null], ['source' => 'import']);
                $contact = wa_contact($cid);
            } catch (Throwable $e) {
                $skip('invalid_number', $e->getMessage());
                continue;
            }
        } elseif ($consent && (int) $contact['opt_in'] !== 1 && empty($contact['opted_out_at'])) {
            wa_contact_opt((int) $contact['id'], true, 'Campaign upload consent');
            $contact = wa_contact((int) $contact['id']);
        }
        $rv = wa_cmp_resolve($tpl, $map, $contact, $csv);
        if ($rv['missing']) {
            $labels = [];
            foreach (wa_cmp_slots($tpl) as $s) {
                if (in_array($s['key'], $rv['missing'], true)) {
                    $labels[] = $s['label'];
                }
            }
            $skip('missing_variables', 'No value for ' . implode(', ', $labels));
            continue;
        }
        $res = wa_send_template((int) $contact['id'], (int) $tpl['id'], $rv['vars'], [
            'sender_type' => 'campaign', 'campaign_id' => $id, 'priority' => 9,
            'dedupe_key' => 'cmp:' . $id . ':' . $r['id'] . ':' . ((int) $r['attempts'] + 1),
        ]);
        if (!empty($res['ok']) && !empty($res['message_id'])) {
            db_update('wa_campaign_recipients', ['message_id' => (int) $res['message_id'], 'contact_id' => (int) $contact['id'], 'error' => null, 'skip_reason' => null,
                'variables' => wa_json(['csv' => (object) $csv, 'consent' => $consent, 'resolved' => (object) $rv['values']])], 'id = ?', [(int) $r['id']]);
            $out['queued']++;
        } else {
            $skip(wa_cmp_refusal_reason($res['code'] ?? null), (string) ($res['error'] ?? 'Not sent'));
        }
    }
    return $out;
}

/** Recompute the counters of a campaign from its recipients and mark completion. Returns the fresh campaign. */
function wa_cmp_sync(int $id): ?array
{
    $a = db_row(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'pending'), 0) AS pending,
                COALESCE(SUM(status = 'queued'), 0) AS queued,
                COALESCE(SUM(status IN ('sent','delivered','read')), 0) AS sent,
                COALESCE(SUM(status IN ('delivered','read')), 0) AS delivered,
                COALESCE(SUM(status = 'read'), 0) AS rd,
                COALESCE(SUM(status = 'failed'), 0) AS failed,
                COALESCE(SUM(status = 'skipped'), 0) AS skipped,
                COALESCE(SUM(replied_at IS NOT NULL), 0) AS replied
           FROM wa_campaign_recipients WHERE campaign_id = ?",
        [$id]
    );
    $c = db_row('SELECT id, status FROM wa_campaigns WHERE id = ?', [$id]);
    if (!$c || !$a) {
        return null;
    }
    $set = [
        'total_recipients' => (int) $a['total'], 'queued_count' => (int) $a['queued'] + (int) $a['pending'], 'sent_count' => (int) $a['sent'],
        'delivered_count' => (int) $a['delivered'], 'read_count' => (int) $a['rd'], 'failed_count' => (int) $a['failed'],
        'skipped_count' => (int) $a['skipped'], 'replied_count' => (int) $a['replied'],
    ];
    if (in_array($c['status'], ['draft', 'scheduled'], true) && (int) $a['total'] === 0) {
        unset($set['total_recipients']); // drafts show the live preview instead
    }
    db_update('wa_campaigns', $set, 'id = ?', [$id]);
    // completion is claimed atomically so two concurrent syncs (worker + a page poll) log and notify it once
    if ($c['status'] === 'sending' && (int) $a['pending'] === 0 && (int) $a['queued'] === 0
        && db_exec("UPDATE wa_campaigns SET status = 'completed', completed_at = ? WHERE id = ? AND status = 'sending'
                AND NOT EXISTS (SELECT 1 FROM wa_campaign_recipients r WHERE r.campaign_id = ? AND r.status IN ('pending','queued'))", [wa_cmp_now(), $id, $id]) > 0) {
        $set['status'] = 'completed';
    }
    if (($set['status'] ?? null) === 'completed') {
        $c2 = wa_cmp_get($id);
        $summary = number_format($set['sent_count']) . ' sent, ' . number_format($set['failed_count']) . ' failed, ' . number_format($set['skipped_count']) . ' skipped';
        log_activity('complete', 'whatsapp_campaigns', $id, 'WhatsApp campaign "' . $c2['name'] . '" finished: ' . $summary);
        // the campaign finishes in the background (worker): tell the person who created it
        if (!empty($c2['created_by'])) {
            notify((int) $c2['created_by'], 'whatsapp', 'Campaign finished: ' . mb_substr($c2['name'], 0, 120), $summary . '. Delivery and read receipts keep arriving for a few days.', 'admin/whatsapp/campaigns/' . $id, 'send');
        }
        return $c2;
    }
    return wa_cmp_get($id);
}

/** Message ids of a campaign still waiting in the outbox. */
function wa_cmp_queued_message_ids(int $id): array
{
    return array_map('intval', db_column("SELECT r.message_id FROM wa_campaign_recipients r JOIN wa_messages m ON m.id = r.message_id
        WHERE r.campaign_id = ? AND r.status = 'queued' AND m.status = 'queued'", [$id]));
}

/** Pull back queued (unsent) messages: cancel them in the outbox, recipients → pending (pause) or skipped (cancel). */
function wa_cmp_recall_queued(int $id, bool $toPending): int
{
    $ids = wa_cmp_queued_message_ids($id);
    if (!$ids) {
        return 0;
    }
    wa_cancel_queued($ids, $toPending ? 'Campaign paused' : 'Campaign cancelled');
    $n = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $cancelled = array_map('intval', db_column("SELECT id FROM wa_messages WHERE status = 'cancelled' AND id IN ($ph)", $chunk));
        if (!$cancelled) {
            continue;
        }
        $ph2 = implode(',', array_fill(0, count($cancelled), '?'));
        if ($toPending) {
            $n += db_exec("UPDATE wa_campaign_recipients SET status = 'pending', message_id = NULL, queued_at = NULL, error = NULL, skip_reason = NULL
                WHERE campaign_id = ? AND message_id IN ($ph2)", array_merge([$id], $cancelled));
        } else {
            $n += db_exec("UPDATE wa_campaign_recipients SET status = 'skipped', skip_reason = 'cancelled', error = 'Campaign cancelled'
                WHERE campaign_id = ? AND message_id IN ($ph2)", array_merge([$id], $cancelled));
        }
    }
    return $n;
}

function wa_cmp_pause(int $id): array
{
    $c = wa_cmp_get($id);
    if (!$c) {
        throw new CrudException('Campaign not found.');
    }
    if ($c['status'] !== 'sending') {
        throw new CrudException('Only a campaign that is sending can be paused.');
    }
    // flip the status first: a batch being queued right now checks it and stops; then recall what is still waiting
    // (anything that slips through is pulled back by the next worker run)
    if (db_exec("UPDATE wa_campaigns SET status = 'paused', paused_at = ? WHERE id = ? AND status = 'sending'", [wa_cmp_now(), $id]) < 1) {
        throw new CrudException('The campaign is no longer sending.');
    }
    $locked = wa_cmp_lock($id, 8);
    try {
        $recalled = wa_cmp_recall_queued($id, true);
        if ($locked) {
            wa_cmp_recover_stuck($id, 'pending', 0);
        }
    } finally {
        if ($locked) {
            wa_cmp_unlock($id);
        }
    }
    return ['campaign' => wa_cmp_sync($id), 'recalled' => $recalled];
}

function wa_cmp_resume(int $id): array
{
    $c = wa_cmp_get($id);
    if (!$c) {
        throw new CrudException('Campaign not found.');
    }
    if ($c['status'] !== 'paused') {
        throw new CrudException('Only a paused campaign can be resumed.');
    }
    $problem = wa_cmp_template_problem($c['template_id'] ? wa_template_find((int) $c['template_id']) : null);
    if ($problem) {
        throw new CrudException($problem);
    }
    db_update('wa_campaigns', ['status' => 'sending', 'paused_at' => null], 'id = ?', [$id]);
    $c = wa_cmp_get($id);
    $q = ['queued' => 0];
    if ((!$c['respect_business_hours'] || wa_in_business_hours()) && wa_cmp_lock($id, 5)) {
        try {
            wa_cmp_recover_stuck($id, 'pending', 0);
            $q = wa_cmp_enqueue($c, min(100, wa_cmp_room($c)), microtime(true) + 5.0);
        } finally {
            wa_cmp_unlock($id);
        }
    }
    return ['campaign' => wa_cmp_sync($id), 'queued' => $q['queued']];
}

function wa_cmp_cancel(int $id): array
{
    $c = wa_cmp_get($id);
    if (!$c) {
        throw new CrudException('Campaign not found.');
    }
    if (!in_array($c['status'], ['scheduled', 'sending', 'paused'], true)) {
        throw new CrudException('Only scheduled, sending or paused campaigns can be cancelled.');
    }
    if (db_exec("UPDATE wa_campaigns SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status IN ('scheduled','sending','paused')", [wa_cmp_now(), $id]) < 1) {
        throw new CrudException('The campaign has already finished.');
    }
    $locked = wa_cmp_lock($id, 8);
    try {
        $recalled = wa_cmp_recall_queued($id, false);
        $pending = db_exec("UPDATE wa_campaign_recipients SET status = 'skipped', skip_reason = 'cancelled', error = 'Campaign cancelled' WHERE campaign_id = ? AND status = 'pending'", [$id]);
        if ($locked) {
            wa_cmp_recover_stuck($id, 'cancelled', 0);
        }
    } finally {
        if ($locked) {
            wa_cmp_unlock($id);
        }
    }
    return ['campaign' => wa_cmp_sync($id), 'stopped' => $recalled + $pending];
}

/** Put failed recipients back in the queue (campaign → sending). Returns how many. */
function wa_cmp_retry_failed(int $id): int
{
    $c = wa_cmp_get($id);
    if (!$c) {
        throw new CrudException('Campaign not found.');
    }
    if (!in_array($c['status'], ['completed', 'sending', 'paused', 'failed'], true)) {
        throw new CrudException('Failed messages can be retried once the campaign has started.');
    }
    $problem = wa_cmp_template_problem($c['template_id'] ? wa_template_find((int) $c['template_id']) : null);
    if ($problem) {
        throw new CrudException($problem);
    }
    $n = db_exec("UPDATE wa_campaign_recipients SET status = 'pending', message_id = NULL, error = NULL, skip_reason = NULL, queued_at = NULL
        WHERE campaign_id = ? AND status = 'failed' AND attempts < 5", [$id]);
    if ($n > 0 && in_array($c['status'], ['completed', 'failed'], true)) {
        db_update('wa_campaigns', ['status' => 'sending', 'completed_at' => null, 'last_error' => null], 'id = ?', [$id]);
    }
    if ($n > 0) {
        $c = wa_cmp_get($id);
        if ($c['status'] === 'sending' && (!$c['respect_business_hours'] || wa_in_business_hours()) && wa_cmp_lock($id, 5)) {
            try {
                wa_cmp_enqueue($c, min(100, wa_cmp_room($c)), microtime(true) + 5.0);
            } finally {
                wa_cmp_unlock($id);
            }
        }
    }
    wa_cmp_sync($id);
    return $n;
}

/** Send one test message of the campaign (current editor mapping) to a number. Not counted in the campaign. */
function wa_cmp_test_send(array $c, string $phone, $map = null, ?int $templateId = null): array
{
    $tpl = wa_template_find((int) ($templateId ?: $c['template_id']));
    $problem = wa_cmp_template_problem($tpl);
    if ($problem) {
        throw new CrudValidationException(['template_id' => $problem]);
    }
    $norm = wa_normalize_phone($phone);
    if (!$norm) {
        throw new CrudValidationException(['phone' => 'Enter a valid WhatsApp number with country code, e.g. +91 98765 43210.']);
    }
    $map = wa_cmp_map_clean($map !== null ? $map : $c['variable_map'], $tpl);
    if (!$map) {
        $map = wa_cmp_default_map($tpl, wa_cmp_upload_row((int) $c['id'])['headers'] ?? []);
    }
    $contact = wa_contact_by_phone($norm['phone']);
    if ($contact && ($contact['status'] ?? 'active') === 'blocked') {
        throw new CrudValidationException(['phone' => 'This number is blocked in WhatsApp contacts.']);
    }
    if ($contact && (int) $contact['opt_in'] !== 1 && !empty($contact['opted_out_at'])) {
        // a test is still a business-initiated message: never send one to a person who replied STOP
        throw new CrudValidationException(['phone' => 'This number unsubscribed from GIMT WhatsApp updates (STOP). Use a staff number for tests.']);
    }
    $csv = [];
    $up = !empty($c['id']) ? wa_cmp_upload_row((int) $c['id']) : null;
    if ($up) {
        $an = wa_cmp_upload_analyse($up);
        foreach ($an['valid'] as $v) {
            if ($v['phone'] === $norm['phone']) {
                $csv = $v['data'];
                break;
            }
        }
        if (!$csv && $an['valid']) {
            $csv = $an['valid'][0]['data'];
        }
    }
    $rv = wa_cmp_resolve($tpl, $map, $contact ?: ['phone' => $norm['phone'], 'name' => null], $csv, true);
    // sender 'system': a manual test goes out now (not held by the automation pause / quiet hours like campaign
    // messages) and is not counted as campaign traffic or as an agent reply in the reports
    $res = wa_send_template($contact ? (int) $contact['id'] : $norm['phone'], (int) $tpl['id'], $rv['vars'], [
        'sender_type' => 'system', 'sent_by' => wa_user_id(), 'priority' => 2, 'skip_opt_in_check' => true, 'use_samples' => true,
        'name' => $contact ? null : 'Campaign test recipient',
    ]);
    if (empty($res['ok'])) {
        throw new CrudValidationException(['phone' => (string) ($res['error'] ?? 'The test message could not be queued.')]);
    }
    try {
        wa_process_outbox(3.0, 10);
    } catch (Throwable $e) {
        error_log('[GIMT] campaign test send outbox: ' . $e->getMessage());
    }
    $m = db_row('SELECT id, status, error, interakt_id FROM wa_messages WHERE id = ?', [(int) $res['message_id']]);
    return ['message_id' => (int) $res['message_id'], 'status' => $m['status'] ?? 'queued', 'error' => $m['error'] ?? null, 'phone' => $norm['phone'],
        'mode' => wa_mode(), 'preview' => wa_cmp_render_preview($tpl, $rv['vars'] + ['_use_samples' => true])];
}

/* ============================================================================================ worker + listeners */

/**
 * Worker tick: start due scheduled campaigns, queue the next batch of every sending campaign (campaign / account rate per
 * minute minus what is still waiting in the outbox; business hours respected), refresh counters of recent campaigns.
 */
function wa_cmp_tick(float $budget): array
{
    $t0 = microtime(true);
    $deadline = $t0 + max(0.5, $budget - 0.5);
    $stats = ['started' => 0, 'queued' => 0, 'skipped' => 0, 'completed' => 0, 'waiting_hours' => 0];
    try {
        foreach (db_column("SELECT id FROM wa_campaigns WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at, id LIMIT 5", [wa_cmp_now()]) as $cid) {
            if (microtime(true) > $deadline) {
                break;
            }
            try {
                $r = wa_cmp_start((int) $cid, 'schedule', 0);
                if (!empty($r['ok'])) {
                    $stats['started']++;
                }
            } catch (Throwable $e) {
                error_log('[GIMT] campaign #' . $cid . ' scheduled start failed: ' . $e->getMessage());
            }
        }
        foreach (db_all("SELECT * FROM wa_campaigns WHERE status = 'sending' ORDER BY COALESCE(started_at, created_at), id LIMIT 20") as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            $c = wa_cmp_row($row);
            $id = (int) $c['id'];
            if ($c['respect_business_hours'] && !wa_in_business_hours()) {
                $stats['waiting_hours']++;
                wa_cmp_sync($id);
                continue;
            }
            if (!wa_cmp_lock($id, 0)) {
                continue;
            }
            try {
                // paused / cancelled by someone between the SELECT above and the lock: do not queue anything
                if (db_value('SELECT status FROM wa_campaigns WHERE id = ?', [$id]) !== 'sending') {
                    continue;
                }
                $stats['recovered'] = ($stats['recovered'] ?? 0) + wa_cmp_recover_stuck($id);
                $room = wa_cmp_room($c);
                if ($room > 0) {
                    $r = wa_cmp_enqueue($c, $room, $deadline);
                    $stats['queued'] += $r['queued'];
                    $stats['skipped'] += $r['skipped'];
                }
            } finally {
                wa_cmp_unlock($id);
            }
            $after = wa_cmp_sync($id);
            if ($after && $after['status'] === 'completed') {
                $stats['completed']++;
            }
        }
        // late delivery / read receipts of recently finished, paused or cancelled campaigns; a message that slipped into
        // the outbox while a pause / cancel was running is pulled back here
        $since = date('Y-m-d H:i:s', time() - 3 * 86400);
        foreach (db_all("SELECT id, status FROM wa_campaigns WHERE status IN ('completed','paused','cancelled','failed')
                AND COALESCE(completed_at, cancelled_at, paused_at, started_at) >= ? ORDER BY id DESC LIMIT 20", [$since]) as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            $cid = (int) $row['id'];
            if (in_array($row['status'], ['paused', 'cancelled'], true) && wa_cmp_lock($cid, 0)) {
                try {
                    if (db_value('SELECT status FROM wa_campaigns WHERE id = ?', [$cid]) === $row['status']) {
                        wa_cmp_recall_queued($cid, $row['status'] === 'paused');
                        wa_cmp_recover_stuck($cid, $row['status'] === 'paused' ? 'pending' : 'cancelled', 0);
                        if ($row['status'] === 'cancelled') {
                            db_exec("UPDATE wa_campaign_recipients SET status = 'skipped', skip_reason = 'cancelled', error = 'Campaign cancelled' WHERE campaign_id = ? AND status = 'pending'", [$cid]);
                        }
                    }
                } finally {
                    wa_cmp_unlock($cid);
                }
            }
            wa_cmp_sync($cid);
        }
    } catch (Throwable $e) {
        error_log('[GIMT] wa_cmp_tick: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $stats['error'] = $e->getMessage();
    }
    $stats['ms'] = (int) round((microtime(true) - $t0) * 1000);
    return $stats;
}

/** WA_MESSAGE_RECEIVED: the first reply within 72 h of a campaign message counts as "replied" for that campaign. */
function wa_cmp_on_inbound(array $event, array $payload, ?array $contact): ?array
{
    $cid = (int) ($payload['contact_id'] ?? ($contact['id'] ?? 0));
    if ($cid <= 0) {
        return null;
    }
    $since = date('Y-m-d H:i:s', time() - WA_CMP_REPLY_WINDOW_HOURS * 3600);
    $r = db_row(
        "SELECT r.id, r.campaign_id FROM wa_campaign_recipients r JOIN wa_messages m ON m.id = r.message_id
          WHERE r.contact_id = ? AND r.replied_at IS NULL AND r.status IN ('sent','delivered','read') AND m.sent_at >= ?
          ORDER BY m.sent_at DESC, r.id DESC LIMIT 1",
        [$cid, $since]
    );
    if (!$r) {
        return null;
    }
    if (db_exec('UPDATE wa_campaign_recipients SET replied_at = ? WHERE id = ? AND replied_at IS NULL', [wa_cmp_now(), (int) $r['id']]) > 0) {
        db_exec('UPDATE wa_campaigns SET replied_count = replied_count + 1 WHERE id = ?', [(int) $r['campaign_id']]);
        return ['type' => 'campaign_reply', 'campaign_id' => (int) $r['campaign_id'], 'recipient_id' => (int) $r['id']];
    }
    return null;
}

/* ============================================================================================ detail / reports */

/**
 * Activity series of a started campaign (sent, delivered, read, failed, replied per bucket). The bucket size follows
 * the span of the activity: 10 minutes (≤ 6 h), 1 hour (≤ 3 days) or 1 day.
 */
function wa_cmp_activity(array $c): array
{
    $start = $c['started_at'] ?: null;
    if (!$start) {
        return ['labels' => [], 'buckets' => [], 'granularity' => 'hour', 'series' => []];
    }
    $id = (int) $c['id'];
    $startTs = (int) strtotime((string) $start);
    $last = db_value('SELECT GREATEST(COALESCE(MAX(sent_at), 0), COALESCE(MAX(delivered_at), 0), COALESCE(MAX(read_at), 0), COALESCE(MAX(failed_at), 0)) FROM wa_messages WHERE campaign_id = ?', [$id]);
    $lastReply = db_value('SELECT MAX(replied_at) FROM wa_campaign_recipients WHERE campaign_id = ?', [$id]);
    $endTs = max($startTs + 600, (int) strtotime((string) ($last ?: $start)), (int) strtotime((string) ($lastReply ?: $start)));
    $endTs = min($endTs, time());
    $span = max(600, $endTs - $startTs);
    $gran = $span <= 6 * 3600 ? '10min' : ($span <= 3 * 86400 ? 'hour' : 'day');
    $expr = fn (string $col) => $gran === '10min'
        ? "CONCAT(DATE_FORMAT($col, '%Y-%m-%d %H:'), LPAD(FLOOR(MINUTE($col) / 10) * 10, 2, '0'))"
        : ($gran === 'hour' ? "DATE_FORMAT($col, '%Y-%m-%d %H:00')" : "DATE_FORMAT($col, '%Y-%m-%d')");
    $series = [];
    foreach (['sent' => 'sent_at', 'delivered' => 'delivered_at', 'read' => 'read_at', 'failed' => 'failed_at'] as $k => $col) {
        $series[$k] = db_pairs('SELECT ' . $expr($col) . " AS b, COUNT(*) FROM wa_messages WHERE campaign_id = ? AND $col IS NOT NULL GROUP BY b", [$id]);
    }
    $series['replied'] = db_pairs('SELECT ' . $expr('replied_at') . ' AS b, COUNT(*) FROM wa_campaign_recipients WHERE campaign_id = ? AND replied_at IS NOT NULL GROUP BY b', [$id]);
    $step = ['10min' => 600, 'hour' => 3600, 'day' => 86400][$gran];
    $t = $gran === 'day' ? strtotime(date('Y-m-d', $startTs)) : ($gran === 'hour' ? strtotime(date('Y-m-d H:00:00', $startTs)) : $startTs - ($startTs % 600));
    $buckets = [];
    $guard = 0;
    while ($t <= $endTs && $guard++ < 200) {
        $buckets[] = date($gran === 'day' ? 'Y-m-d' : 'Y-m-d H:i', $t);
        $t += $step;
    }
    $out = [];
    foreach ($series as $k => $s) {
        $out[$k] = array_map(fn ($b) => (int) ($s[$b] ?? 0), $buckets);
    }
    $labels = array_map(fn ($b) => $gran === 'day' ? date('d M', strtotime($b)) : ($gran === 'hour' ? date('d M, H:i', strtotime($b)) : date('H:i', strtotime($b))), $buckets);
    return ['labels' => $labels, 'buckets' => $buckets, 'granularity' => $gran, 'series' => $out];
}

/** Failure + skip reasons of a campaign. */
function wa_cmp_reasons(int $id): array
{
    $failed = db_all(
        "SELECT COALESCE(NULLIF(m.error, ''), NULLIF(r.error, ''), 'Delivery failed') AS reason, MAX(m.error_code) AS code, COUNT(*) AS n
           FROM wa_campaign_recipients r LEFT JOIN wa_messages m ON m.id = r.message_id
          WHERE r.campaign_id = ? AND r.status = 'failed' GROUP BY reason ORDER BY n DESC LIMIT 12",
        [$id]
    );
    $labels = wa_cmp_skip_reasons();
    $skipped = [];
    foreach (db_all("SELECT COALESCE(skip_reason, 'other') AS reason, COUNT(*) AS n FROM wa_campaign_recipients WHERE campaign_id = ? AND status = 'skipped' GROUP BY reason ORDER BY n DESC", [$id]) as $s) {
        $skipped[] = ['reason' => $s['reason'], 'label' => $labels[$s['reason']] ?? label_from_key((string) $s['reason']), 'count' => (int) $s['n']];
    }
    return [
        'failed' => array_map(fn ($f) => ['reason' => (string) $f['reason'], 'code' => $f['code'], 'count' => (int) $f['n']], $failed),
        'skipped' => $skipped,
    ];
}

/** Timeline: lifecycle stamps (created, started, completed / failed, cancelled) merged with the activity log. */
function wa_cmp_timeline(array $c): array
{
    $logs = db_all("SELECT l.action, l.description, l.status, l.created_at, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
            WHERE l.module = 'whatsapp_campaigns' AND l.record_id = ? ORDER BY l.id DESC LIMIT 80", [(string) $c['id']]);
    $by = function (string $action) use ($logs): ?array {
        foreach ($logs as $l) {
            if ($l['action'] === $action && $l['status'] !== 'failed') {
                return $l;
            }
        }
        return null;
    };
    $items = [];
    $add = function (?string $at, string $kind, string $title, ?string $who = null, ?string $detail = null) use (&$items) {
        if ($at) {
            $items[] = ['at' => $at, 'kind' => $kind, 'title' => $title, 'by' => $who, 'detail' => $detail];
        }
    };
    $add($c['created_at'], 'created', 'Campaign created', $c['created_by_name'] ?? null);
    $send = $by('send');
    $add($c['started_at'], 'started', 'Sending started', $send['user_name'] ?? null,
        number_format(max(0, $c['total_recipients'] - $c['skipped_count'])) . ' recipients · ' . number_format($c['skipped_count']) . ' skipped');
    $add($c['completed_at'], $c['status'] === 'failed' ? 'failed' : 'completed', $c['status'] === 'failed' ? 'Stopped with an error' : 'Completed', null,
        $c['status'] === 'failed' ? ($c['last_error'] ?? null) : number_format($c['sent_count']) . ' sent · ' . number_format($c['failed_count']) . ' failed');
    $cancel = $by('cancel');
    $add($c['cancelled_at'], 'cancelled', 'Cancelled', $cancel['user_name'] ?? null);
    $covered = ['create', 'complete', 'send', 'cancel'];
    foreach ($logs as $l) {
        if (in_array($l['action'], $covered, true) && $l['status'] !== 'failed') {
            continue;
        }
        $items[] = ['at' => $l['created_at'], 'kind' => $l['status'] === 'failed' ? 'failed' : $l['action'], 'title' => $l['description'], 'by' => $l['user_name'], 'detail' => null];
    }
    usort($items, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
    return $items;
}

/** Full detail for the campaign page. */
function wa_cmp_detail(int $id): ?array
{
    $c = wa_cmp_get($id);
    if (!$c) {
        return null;
    }
    if (in_array($c['status'], ['sending', 'paused', 'completed'], true)) {
        $c = wa_cmp_sync($id) ?? $c;
    }
    $tpl = $c['template_id'] ? wa_template_find((int) $c['template_id']) : null;
    $out = wa_cmp_public($c);
    $out['template_full'] = $tpl ? [
        'id' => (int) $tpl['id'], 'name' => $tpl['name'], 'display_name' => $tpl['display_name'], 'category' => $tpl['category'], 'language' => $tpl['language'],
        'status' => $tpl['status'], 'header_type' => $tpl['header_type'], 'header_text' => $tpl['header_text'], 'header_media_url' => $tpl['header_media_url'],
        'body' => $tpl['body'], 'footer' => $tpl['footer'], 'buttons' => $tpl['buttons'] ?: [], 'variables' => $tpl['variables'] ?: (object) [], 'slots' => wa_cmp_slots($tpl),
    ] : null;
    $out['upload'] = wa_cmp_upload_summary($id);
    $out['status_counts'] = [
        'pending' => (int) db_value("SELECT COUNT(*) FROM wa_campaign_recipients WHERE campaign_id = ? AND status = 'pending'", [$id]),
        'queued' => (int) db_value("SELECT COUNT(*) FROM wa_campaign_recipients WHERE campaign_id = ? AND status = 'queued'", [$id]),
    ];
    $started = (bool) $c['started_at'];
    $out['activity'] = $started ? wa_cmp_activity($c) : null;
    $out['reasons'] = $started ? wa_cmp_reasons($id) : ['failed' => [], 'skipped' => []];
    $out['timeline'] = wa_cmp_timeline($c);
    $out['rate_effective'] = wa_cmp_rate($c);
    $out['in_business_hours'] = wa_in_business_hours();
    $out['mode'] = wa_mode();
    $remaining = $out['status_counts']['pending'] + $out['status_counts']['queued'];
    $out['eta_minutes'] = $c['status'] === 'sending' && $remaining > 0 ? (int) ceil($remaining / max(1, $out['rate_effective'])) : null;
    if (in_array($c['status'], ['draft', 'scheduled'], true)) {
        $out['readiness'] = wa_cmp_readiness($c);
    }
    $cost = db_value('SELECT SUM(cost) FROM wa_messages WHERE campaign_id = ?', [$id]);
    $out['cost'] = $cost !== null ? round((float) $cost, 2) : null;
    return $out;
}

/** Recipient filters: status (pending|queued|sent|delivered|read|failed|skipped|replied), reason, source, q. */
function wa_cmp_recipient_where(int $id, array $f, array &$args): string
{
    $w = ['r.campaign_id = ?'];
    $args[] = $id;
    $st = (string) ($f['status'] ?? '');
    if ($st === 'replied') {
        $w[] = 'r.replied_at IS NOT NULL';
    } elseif ($st === 'sent') {
        $w[] = "r.status IN ('sent','delivered','read')";
    } elseif ($st === 'delivered') {
        $w[] = "r.status IN ('delivered','read')";
    } elseif ($st === 'pending' || $st === 'waiting') {
        // "Waiting" = not handed to WhatsApp yet: still to be queued or waiting in the outbox
        $w[] = "r.status IN ('pending','queued')";
    } elseif (in_array($st, ['queued', 'read', 'failed', 'skipped'], true)) {
        $w[] = 'r.status = ?';
        $args[] = $st;
    }
    $reason = (string) ($f['reason'] ?? '');
    if ($reason !== '' && isset(wa_cmp_skip_reasons()[$reason])) {
        $w[] = 'r.skip_reason = ?';
        $args[] = $reason;
    }
    $src = (string) ($f['source'] ?? '');
    if (in_array($src, ['filter', 'csv'], true)) {
        $w[] = 'r.source = ?';
        $args[] = $src;
    }
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $digits = preg_replace('/\D+/', '', $q);
        if (strlen($digits) >= 4) {
            $w[] = '(r.name LIKE ? OR r.phone LIKE ?)';
            array_push($args, $like, '%' . $digits . '%');
        } else {
            $w[] = 'r.name LIKE ?';
            $args[] = $like;
        }
    }
    return implode(' AND ', $w);
}

function wa_cmp_recipient_select(): string
{
    return "SELECT r.id, r.campaign_id, r.contact_id, r.phone, r.name, r.source, r.contact_type, r.status, r.error, r.skip_reason, r.attempts,
                   r.queued_at, r.replied_at, r.message_id, r.created_at, m.status AS message_status, m.sent_at, m.delivered_at, m.read_at, m.failed_at,
                   m.error_code, m.cost, cv.id AS conversation_id
              FROM wa_campaign_recipients r
              LEFT JOIN wa_messages m ON m.id = r.message_id
              LEFT JOIN wa_conversations cv ON cv.contact_id = r.contact_id";
}

function wa_cmp_recipient_row(array $r): array
{
    foreach (['id', 'campaign_id', 'contact_id', 'message_id', 'attempts', 'conversation_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['valid_phone'] = ctype_digit((string) $r['phone']);
    $r['phone_display'] = wa_cmp_phone_display((string) $r['phone']);
    if (wa_cmp_masking()) {
        $r['phone'] = $r['phone_display'];
    }
    return $r;
}

/** Route guard: 403 "Your role does not allow you to … in WhatsApp" unless the role has whatsapp_campaigns.$action. */
function wa_cmp_require(string $action, string $what): void
{
    wa_require_any([['whatsapp_campaigns', $action]], $what);
}

/** Settings › WhatsApp › Security "mask phone numbers" applies to the current user (managers always see full numbers). */
function wa_cmp_masking(): bool
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

/** Recipient report export allowed: whatsapp_campaigns.export plus the "who can export" security setting. */
function wa_cmp_can_export(): bool
{
    if (!wa_can('whatsapp_campaigns', 'export')) {
        return false;
    }
    try {
        if (function_exists('wa_adm_setting') && (string) wa_adm_setting('wa_export_scope') === 'managers') {
            return wa_can('whatsapp_settings', 'manage');
        }
    } catch (Throwable $e) {
    }
    return true;
}

/** "919876543210" → "+91 98765 43210" (masked "+91 98•••••210" when masking applies). */
function wa_cmp_format_phone(string $digits): string
{
    if (wa_cmp_masking() && function_exists('wa_adm_mask_phone')) {
        return wa_adm_mask_phone($digits);
    }
    if (strlen($digits) > 10) {
        $cc = substr($digits, 0, strlen($digits) - 10);
        $n = substr($digits, -10);
        return '+' . $cc . ' ' . substr($n, 0, 5) . ' ' . substr($n, 5);
    }
    return '+' . $digits;
}

/** Stored recipient phone → display value ("x<row>:<raw>" invalid upload rows, "d<row>:<phone>" duplicates). */
function wa_cmp_phone_display(string $phone): string
{
    if (ctype_digit($phone)) {
        return wa_cmp_format_phone($phone);
    }
    if (preg_match('/^([xd])(\d+):(.*)$/', $phone, $m)) {
        return ($m[1] === 'd' && ctype_digit($m[3]) ? wa_cmp_format_phone($m[3]) : (wa_cmp_masking() ? '•••' : $m[3])) . ' (row ' . $m[2] . ')';
    }
    return $phone;
}

function wa_cmp_recipients(int $id, array $f, int $page = 1, int $perPage = 25): array
{
    $args = [];
    $where = wa_cmp_recipient_where($id, $f, $args);
    $total = (int) db_value("SELECT COUNT(*) FROM wa_campaign_recipients r WHERE $where", $args);
    $pg = paginate($total, $page, $perPage);
    $sort = [
        'name' => 'r.name IS NULL, r.name ASC, r.id',
        'status' => "FIELD(r.status, 'failed','skipped','pending','queued','sent','delivered','read'), r.id",
        'sent' => 'm.sent_at IS NULL, m.sent_at DESC, r.id',
        'read' => 'm.read_at IS NULL, m.read_at DESC, r.id',
    ][(string) ($f['sort'] ?? '')] ?? 'r.id';
    $rows = db_all(wa_cmp_recipient_select() . " WHERE $where ORDER BY $sort LIMIT " . (int) $pg['per_page'] . ' OFFSET ' . (int) (($pg['page'] - 1) * $pg['per_page']), $args);
    $counts = db_row(
        "SELECT COUNT(*) AS all_count, COALESCE(SUM(status = 'pending'),0) AS pending, COALESCE(SUM(status = 'queued'),0) AS queued,
                COALESCE(SUM(status IN ('sent','delivered','read')),0) AS sent, COALESCE(SUM(status IN ('delivered','read')),0) AS delivered,
                COALESCE(SUM(status = 'read'),0) AS rd, COALESCE(SUM(status = 'failed'),0) AS failed, COALESCE(SUM(status = 'skipped'),0) AS skipped,
                COALESCE(SUM(replied_at IS NOT NULL),0) AS replied
           FROM wa_campaign_recipients WHERE campaign_id = ?",
        [$id]
    ) ?: [];
    return ['data' => array_map('wa_cmp_recipient_row', $rows), 'meta' => $pg, 'counts' => [
        'all' => (int) ($counts['all_count'] ?? 0), 'pending' => (int) ($counts['pending'] ?? 0), 'queued' => (int) ($counts['queued'] ?? 0),
        'sent' => (int) ($counts['sent'] ?? 0), 'delivered' => (int) ($counts['delivered'] ?? 0), 'read' => (int) ($counts['rd'] ?? 0),
        'failed' => (int) ($counts['failed'] ?? 0), 'skipped' => (int) ($counts['skipped'] ?? 0), 'replied' => (int) ($counts['replied'] ?? 0),
    ]];
}

/** Export rows (headers + rows) of the recipient report. */
function wa_cmp_export_rows(int $id, array $f, int $max = 50000): array
{
    $args = [];
    $where = wa_cmp_recipient_where($id, $f, $args);
    $rows = db_all(wa_cmp_recipient_select() . " WHERE $where ORDER BY r.id LIMIT " . $max, $args);
    $types = function_exists('wa_crm_contact_types') ? wa_crm_contact_types() : [];
    $reasons = wa_cmp_skip_reasons();
    $headers = ['Name', 'WhatsApp number', 'Contact type', 'Source', 'Status', 'Reason / error', 'Error code', 'Queued at', 'Sent at', 'Delivered at', 'Read at', 'Replied at', 'Attempts', 'Message ID'];
    $out = [];
    foreach ($rows as $r) {
        $status = $r['status'];
        $out[] = [
            (string) ($r['name'] ?? ''),
            wa_cmp_phone_display((string) $r['phone']),
            $r['contact_type'] ? ($types[$r['contact_type']] ?? label_from_key((string) $r['contact_type'])) : 'New number',
            $r['source'] === 'csv' ? 'Uploaded list' : 'CRM filters',
            ucfirst($status),
            $status === 'skipped' ? ($reasons[$r['skip_reason'] ?? ''] ?? (string) $r['error']) . ($r['error'] && isset($reasons[$r['skip_reason'] ?? '']) && $r['error'] !== $reasons[$r['skip_reason']] ? ' — ' . $r['error'] : '') : (string) ($r['error'] ?? ''),
            (string) ($r['error_code'] ?? ''),
            (string) ($r['queued_at'] ?? ''), (string) ($r['sent_at'] ?? ''), (string) ($r['delivered_at'] ?? ''), (string) ($r['read_at'] ?? ''), (string) ($r['replied_at'] ?? ''),
            (string) $r['attempts'], (string) ($r['message_id'] ?? ''),
        ];
    }
    return ['headers' => $headers, 'rows' => $out];
}

/** Editor reference data: types, statuses, reasons, approved templates, CRM facets, segments, mode, rate, business hours. */
function wa_cmp_meta(): array
{
    $types = [];
    foreach (wa_cmp_types() as $k => $t) {
        $types[] = ['value' => $k, 'label' => $t['label'], 'category' => $t['category'], 'description' => $t['description']];
    }
    $facets = function_exists('wa_crm_facets') ? wa_crm_facets() : null;
    if (!$facets) {
        $distinct = fn (string $col) => array_map(fn ($v) => ['value' => $v, 'label' => $v], db_column("SELECT DISTINCT $col FROM wa_contacts WHERE $col IS NOT NULL AND $col <> '' ORDER BY $col LIMIT 300"));
        $facets = [
            'types' => array_map(fn ($k) => ['value' => $k, 'label' => label_from_key($k)], ['lead', 'applicant', 'student', 'guardian', 'alumni', 'staff', 'other']),
            'stages' => array_map(fn ($v) => ['value' => $v, 'label' => label_from_key($v)], db_column("SELECT DISTINCT stage FROM wa_contacts ORDER BY stage")),
            'programs' => array_map(fn ($r) => ['value' => (int) $r['id'], 'label' => $r['name'], 'sub' => $r['code']], db_all('SELECT id, name, code FROM programs ORDER BY name')),
            'cities' => $distinct('city'), 'states' => $distinct('state'), 'batches' => $distinct('batch'),
            'admission_statuses' => array_map(fn ($o) => ['value' => $o['value'], 'label' => label_from_key((string) $o['value'])], $distinct('admission_status')),
            'payment_statuses' => array_map(fn ($o) => ['value' => $o['value'], 'label' => label_from_key((string) $o['value'])], $distinct('payment_status')),
            'tags' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'color' => $r['color']], db_all('SELECT id, name, color FROM wa_tags ORDER BY name')),
        ];
    }
    $segments = [];
    try {
        $segRows = function_exists('wa_crm_segments') ? wa_crm_segments() : db_all('SELECT id, name, description, contacts_count, opted_in_count FROM wa_segments ORDER BY name');
        foreach ($segRows as $s) {
            $segments[] = ['id' => (int) $s['id'], 'name' => $s['name'], 'description' => $s['description'] ?? null, 'contacts_count' => (int) ($s['contacts_count'] ?? 0),
                'opted_in_count' => (int) ($s['opted_in_count'] ?? 0)];
        }
    } catch (Throwable $e) {
        $segments = [];
    }
    $catalog = array_values(wa_variable_catalog());
    $hours = function_exists('wa_business_hours') ? wa_business_hours() : [];
    return [
        'types' => $types,
        'statuses' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(wa_cmp_statuses()), wa_cmp_statuses()),
        'skip_reasons' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(wa_cmp_skip_reasons()), wa_cmp_skip_reasons()),
        'templates' => wa_cmp_templates(),
        'facets' => [
            'types' => $facets['types'] ?? [], 'stages' => $facets['stages'] ?? [], 'programs' => $facets['programs'] ?? [], 'cities' => $facets['cities'] ?? [],
            'states' => $facets['states'] ?? [], 'batches' => $facets['batches'] ?? [], 'admission_statuses' => $facets['admission_statuses'] ?? [],
            'payment_statuses' => $facets['payment_statuses'] ?? [], 'tags' => $facets['tags'] ?? [],
        ],
        'segments' => $segments,
        'catalog' => $catalog,
        'contact_keys' => wa_cmp_contact_keys(),
        'mode' => wa_mode(),
        'rate_per_minute' => wa_rate_per_minute(),
        'business_hours' => $hours,
        'in_business_hours' => wa_in_business_hours(),
        'max_audience' => WA_CMP_MAX_AUDIENCE,
        'max_upload_rows' => WA_CMP_MAX_UPLOAD_ROWS,
        'reply_window_hours' => WA_CMP_REPLY_WINDOW_HOURS,
        'can_export' => wa_cmp_can_export(),
        'phones_masked' => wa_cmp_masking(),
        'xlsx' => class_exists('ZipArchive'),
    ];
}

/* ============================================================================================ hooks */

if (function_exists('wa_on_tick')) {
    wa_on_tick('campaigns', 'wa_cmp_tick');
}
if (function_exists('wa_on')) {
    wa_on('WA_MESSAGE_RECEIVED', 'wa_cmp_on_inbound', 40);
}
