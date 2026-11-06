<?php
/**
 * WhatsApp ADMIN service (prefix wa_adm_): dashboard (Module 1), reports & analytics (Module 12), integrations + API &
 * webhooks (Module 11), team management (Module 13) and settings (Module 14). Loaded by app/services/whatsapp.php.
 *
 * At include time this file only defines functions and registers hooks (wa_on / wa_on_tick) — no queries.
 *
 * Settings owned here (group "whatsapp"): wa_notify_new_message, wa_notify_assignment, wa_notify_handover, wa_daily_digest,
 * wa_digest_time, wa_automation_paused, wa_quiet_enabled, wa_quiet_start, wa_quiet_end, wa_max_per_contact_day,
 * wa_export_scope (permission|managers), wa_mask_phones. Core keys edited here: wa_business_*, wa_default_language,
 * wa_brochure_url, wa_business_hours, wa_retention_days, wa_max_attempts, wa_auto_sync_contacts, wa_optin_reply, wa_optout_reply,
 * wa_allow_simulate_live, wa_interakt_*, wa_rate_per_minute, wa_mode, wa_webhook_secret.
 *
 * Helpers other parts may call (all guarded by function_exists on their side):
 *   wa_adm_send_gate(array $contact, string $senderType): ?array   refusal ['code','error','defer_until'] or null
 *   wa_adm_automation_paused(): bool, wa_adm_quiet_until(?DateTimeInterface): ?string
 *   wa_adm_notify_enabled('new_message'|'assignment'|'handover'|'digest'): bool
 *   wa_adm_should_mask(): bool, wa_adm_mask_phone(?string): string, wa_adm_can_export(): bool
 */

/* =================================================================================================================
 * Small helpers
 * ================================================================================================================= */

/** SQL condition (constant, no user input): the stored webhook headers carry an Interakt signature (core stores the key only when present). */
const WA_ADM_SIGNED_SQL = "headers LIKE '%\"interakt-signature\"%'";

/** Defaults of the settings this part owns (merged over core defaults when reading). */
function wa_adm_setting_defaults(): array
{
    return [
        'wa_notify_new_message' => '1',
        'wa_notify_assignment' => '1',
        'wa_notify_handover' => '1',
        'wa_daily_digest' => '0',
        'wa_digest_time' => '09:00',
        'wa_automation_paused' => '0',
        'wa_quiet_enabled' => '0',
        'wa_quiet_start' => '21:00',
        'wa_quiet_end' => '08:00',
        'wa_max_per_contact_day' => '0',
        'wa_export_scope' => 'permission',
        'wa_mask_phones' => '0',
    ];
}

/** wa_setting() with this part's defaults. */
function wa_adm_setting(string $key, $default = null)
{
    $d = $default ?? (wa_adm_setting_defaults()[$key] ?? null);
    return wa_setting($key, $d);
}

/**
 * Internal markers that settings forms / endpoints must never show or edit: every key starting with '_' plus the
 * state keys other parts keep in the whatsapp group (round-robin pointer, CRM sync bookkeeping, event auto-link flag).
 */
function wa_adm_internal_key(string $key): bool
{
    return $key === '' || $key[0] === '_'
        || in_array($key, ['wa_wf_round_robin', 'wa_crm_sync_last', 'wa_crm_sync_stats', 'wa_events_autolinked', 'wa_events_last_scan'], true);
}

/** Store a whatsapp setting; secrets are encrypted. Internal keys are refused (they are owned by the worker / other parts). */
function wa_adm_save_setting(string $key, $value): void
{
    if (wa_adm_internal_key($key)) {
        throw new InvalidArgumentException('Internal WhatsApp setting ' . $key . ' cannot be changed here.');
    }
    $v = is_bool($value) ? ($value ? '1' : '0') : (is_array($value) ? (string) wa_json($value) : (string) ($value ?? ''));
    if ($v !== '' && wa_is_secret_key($key)) {
        $v = wa_encrypt($v);
    }
    save_setting($key, $v, 'whatsapp');
}

function wa_adm_int($v): int
{
    return (int) ($v ?? 0);
}

/** Human duration: 45 s, 12 min, 3.5 h, 2.1 days. */
function wa_adm_duration(int $sec): string
{
    if ($sec < 60) {
        return $sec . ' s';
    }
    if ($sec < 3600) {
        return round($sec / 60) . ' min';
    }
    if ($sec < 86400) {
        return round($sec / 3600, 1) . ' h';
    }
    return round($sec / 86400, 1) . ' days';
}

/** Percentage helper (0 when the denominator is 0), one decimal. */
function wa_adm_pct($num, $den): float
{
    $den = (float) $den;
    return $den > 0 ? round(((float) $num / $den) * 100, 1) : 0.0;
}

/** Users with whatsapp_settings manage (or whatsapp manage) see unmasked phone numbers. */
function wa_adm_should_mask(): bool
{
    if ((string) wa_adm_setting('wa_mask_phones') !== '1') {
        return false;
    }
    return !(wa_can('whatsapp_settings', 'manage') || wa_can('whatsapp', 'manage'));
}

/** '919876543210' → '+91 98•••••210' (only the first 2 and last 3 digits of the national number). */
function wa_adm_mask_phone(?string $phone): string
{
    $d = preg_replace('/\D+/', '', (string) $phone);
    if ($d === '') {
        return '';
    }
    $cc = strlen($d) > 10 ? substr($d, 0, strlen($d) - 10) : '';
    $nat = strlen($d) > 10 ? substr($d, -10) : $d;
    $masked = strlen($nat) > 5 ? substr($nat, 0, 2) . str_repeat('•', strlen($nat) - 5) . substr($nat, -3) : str_repeat('•', strlen($nat));
    return ($cc !== '' ? '+' . $cc . ' ' : '') . $masked;
}

/** Phone for display (masked when the security setting says so for this user). */
function wa_adm_phone(?string $phone): ?string
{
    if ($phone === null || $phone === '') {
        return $phone;
    }
    return wa_adm_should_mask() ? wa_adm_mask_phone($phone) : $phone;
}

/** Reports export permission (export action + the "who can export" security setting). */
function wa_adm_can_export(): bool
{
    if (!wa_can('whatsapp_reports', 'export')) {
        return false;
    }
    return (string) wa_adm_setting('wa_export_scope') !== 'managers' || wa_can('whatsapp_settings', 'manage');
}

/** Notification switches for the inbox / bot / digest. */
function wa_adm_notify_enabled(string $kind): bool
{
    $key = ['new_message' => 'wa_notify_new_message', 'assignment' => 'wa_notify_assignment', 'handover' => 'wa_notify_handover', 'digest' => 'wa_daily_digest'][$kind] ?? null;
    return $key !== null && (string) wa_adm_setting($key) === '1';
}

/** Global "pause automations" switch (automations, events, follow-ups, campaigns — not agent replies). */
function wa_adm_automation_paused(): bool
{
    return (string) wa_adm_setting('wa_automation_paused') === '1';
}

/** When quiet hours are on and $at falls inside them, the end of the quiet period (Y-m-d H:i:s); else null. */
function wa_adm_quiet_until(?DateTimeInterface $at = null): ?string
{
    if ((string) wa_adm_setting('wa_quiet_enabled') !== '1') {
        return null;
    }
    $start = (string) wa_adm_setting('wa_quiet_start');
    $end = (string) wa_adm_setting('wa_quiet_end');
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start === $end) {
        return null;
    }
    $at = $at ? DateTimeImmutable::createFromInterface($at) : new DateTimeImmutable('now');
    $t = $at->format('H:i');
    [$eh, $em] = array_map('intval', explode(':', $end));
    if ($start < $end) { // same-day window, e.g. 13:00–14:00
        if ($t >= $start && $t < $end) {
            return $at->setTime($eh, $em)->format('Y-m-d H:i:s');
        }
        return null;
    }
    // overnight window, e.g. 21:00–08:00
    if ($t >= $start) {
        return $at->modify('+1 day')->setTime($eh, $em)->format('Y-m-d H:i:s');
    }
    if ($t < $end) {
        return $at->setTime($eh, $em)->format('Y-m-d H:i:s');
    }
    return null;
}

/**
 * Send gate for automated messages (automation / event / followup / campaign / bot): global pause, quiet hours and the
 * per-contact daily cap. Agent and system messages always pass. Returns null (allowed) or
 * ['code' => 'paused'|'quiet_hours'|'daily_limit', 'error' => string, 'defer_until' => ?string].
 * Core's wa_send() / outbox are expected to call this (function_exists guard) — see docs/WHATSAPP.md.
 */
function wa_adm_send_gate(array $contact, string $senderType): ?array
{
    if (!in_array($senderType, ['automation', 'event', 'followup', 'campaign', 'bot'], true)) {
        return null;
    }
    if ($senderType !== 'bot' && wa_adm_automation_paused()) {
        return ['code' => 'paused', 'error' => 'Automated WhatsApp messages are paused in WhatsApp › Settings.', 'defer_until' => null];
    }
    if ($senderType !== 'bot' && ($until = wa_adm_quiet_until())) {
        return ['code' => 'quiet_hours', 'error' => 'Quiet hours — the message will go out at ' . date('d M, H:i', strtotime($until)) . '.', 'defer_until' => $until];
    }
    $cap = (int) wa_adm_setting('wa_max_per_contact_day');
    if ($cap > 0 && $senderType !== 'bot' && !empty($contact['id'])) {
        $n = (int) db_value("SELECT COUNT(*) FROM wa_messages WHERE contact_id = ? AND direction = 'out' AND sender_type IN ('automation','event','followup','campaign')
                              AND status <> 'cancelled' AND created_at >= ?", [(int) $contact['id'], date('Y-m-d 00:00:00')]);
        if ($n >= $cap) {
            return ['code' => 'daily_limit', 'error' => 'This contact already received ' . $n . ' automated message(s) today (limit ' . $cap . ').',
                'defer_until' => date('Y-m-d', strtotime('+1 day')) . ' ' . ((string) wa_adm_setting('wa_quiet_end') ?: '09:00') . ':00'];
        }
    }
    return null;
}

/* =================================================================================================================
 * Date ranges / buckets
 * ================================================================================================================= */

/**
 * Resolve the period switch: daily (last 14 days by day), weekly (last 12 weeks by week), monthly (last 12 months by
 * month) or custom (from/to; bucket by day ≤ 62 days, week ≤ 182 days, else month). Also the previous window.
 */
function wa_adm_range(array $q): array
{
    $period = in_array($q['period'] ?? '', ['daily', 'weekly', 'monthly', 'custom'], true) ? $q['period'] : 'daily';
    $today = new DateTimeImmutable('today');
    $from = $to = null;
    $isDate = fn ($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s);
    if (($period === 'custom' || (!isset($q['period']) && ($q['from'] ?? null))) && $isDate($q['from'] ?? null) && $isDate($q['to'] ?? null)) {
        $period = 'custom';
        $from = new DateTimeImmutable($q['from']);
        $to = new DateTimeImmutable($q['to']);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ($from < $to->modify('-1095 days')) {
            $from = $to->modify('-1095 days');
        }
    } elseif ($period === 'custom') {
        $period = 'daily';
    }
    switch ($period) {
        case 'weekly':
            $to = $today;
            $from = $today->modify('monday this week')->modify('-11 weeks');
            $bucket = 'week';
            $label = 'Last 12 weeks';
            break;
        case 'monthly':
            $to = $today;
            $from = $today->modify('first day of this month')->modify('-11 months');
            $bucket = 'month';
            $label = 'Last 12 months';
            break;
        case 'custom':
            $days = (int) $from->diff($to)->days + 1;
            $bucket = $days <= 62 ? 'day' : ($days <= 182 ? 'week' : 'month');
            $label = $from->format('d M Y') . ' – ' . $to->format('d M Y');
            break;
        default:
            $to = $today;
            $from = $today->modify('-13 days');
            $bucket = 'day';
            $label = 'Last 14 days';
    }
    $days = (int) $from->diff($to)->days + 1;
    $prevTo = $from->modify('-1 day');
    $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');
    // bucket keys + labels
    $keys = [];
    $labels = [];
    $cur = $bucket === 'week' ? $from->modify('monday this week') : ($bucket === 'month' ? $from->modify('first day of this month') : $from);
    $guard = 0;
    while ($cur <= $to && $guard++ < 1200) {
        $k = wa_adm_bucket_key($cur->format('Y-m-d'), $bucket);
        if (!isset($keys[$k])) {
            $keys[$k] = count($keys);
            $labels[] = $bucket === 'day' ? $cur->format('d M') : ($bucket === 'week' ? 'Wk ' . $cur->format('d M') : $cur->format('M Y'));
        }
        $cur = $cur->modify($bucket === 'day' ? '+1 day' : ($bucket === 'week' ? '+1 week' : '+1 month'));
    }
    return [
        'period' => $period, 'bucket' => $bucket, 'label' => $label, 'days' => $days,
        'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
        'start' => $from->format('Y-m-d') . ' 00:00:00', 'end' => $to->format('Y-m-d') . ' 23:59:59',
        'prev_start' => $prevFrom->format('Y-m-d') . ' 00:00:00', 'prev_end' => $prevTo->format('Y-m-d') . ' 23:59:59',
        'keys' => $keys, 'labels' => $labels,
    ];
}

function wa_adm_bucket_key(string $date, string $bucket): string
{
    $ts = strtotime($date);
    if ($bucket === 'week') {
        return date('o-W', $ts);
    }
    if ($bucket === 'month') {
        return date('Y-m', $ts);
    }
    return date('Y-m-d', $ts);
}

/**
 * Run a query returning `d` (DATE) + numeric columns and fold it into the range buckets.
 * @return array<string,int[]>  column => values aligned with $range['labels']
 */
function wa_adm_series(string $sql, array $args, array $range, array $cols): array
{
    $out = [];
    foreach ($cols as $c) {
        $out[$c] = array_fill(0, count($range['labels']), 0);
    }
    foreach (db_all($sql, $args) as $r) {
        $k = wa_adm_bucket_key((string) $r['d'], $range['bucket']);
        if (!isset($range['keys'][$k])) {
            continue;
        }
        $i = $range['keys'][$k];
        foreach ($cols as $c) {
            $out[$c][$i] += (int) ($r[$c] ?? 0);
        }
    }
    return $out;
}

/** Trend object for StatCard: change vs the previous window. */
function wa_adm_trend(int $now, int $prev, bool $upIsGood = true): ?array
{
    if ($prev <= 0 && $now <= 0) {
        return null;
    }
    if ($prev <= 0) {
        return ['value' => 'new', 'dir' => 'up', 'positive' => $upIsGood];
    }
    $p = (int) round((($now - $prev) / $prev) * 100);
    $dir = $p > 0 ? 'up' : ($p < 0 ? 'down' : 'flat');
    return ['value' => ($p > 0 ? '+' : '') . $p . '%', 'dir' => $dir, 'positive' => $dir === 'flat' ? true : (($dir === 'up') === $upIsGood)];
}

/* =================================================================================================================
 * Dashboard (Module 1)
 * ================================================================================================================= */

/** Message counters for a window. */
function wa_adm_message_counts(string $start, string $end): array
{
    $r = db_row("SELECT
            SUM(direction = 'out' AND status NOT IN ('queued','cancelled')) AS sent,
            SUM(direction = 'out' AND status IN ('delivered','read')) AS delivered,
            SUM(direction = 'out' AND status = 'read') AS rd,
            SUM(direction = 'out' AND status = 'failed') AS failed,
            SUM(direction = 'out' AND status = 'queued') AS queued,
            SUM(direction = 'out' AND status = 'simulated') AS simulated,
            SUM(direction = 'in') AS received
        FROM wa_messages WHERE created_at BETWEEN ? AND ?", [$start, $end]) ?: [];
    return ['sent' => wa_adm_int($r['sent'] ?? 0), 'delivered' => wa_adm_int($r['delivered'] ?? 0), 'read' => wa_adm_int($r['rd'] ?? 0),
        'failed' => wa_adm_int($r['failed'] ?? 0), 'queued' => wa_adm_int($r['queued'] ?? 0), 'simulated' => wa_adm_int($r['simulated'] ?? 0),
        'received' => wa_adm_int($r['received'] ?? 0)];
}

/** Business events only (GIMT_* / custom) — excludes core's internal WA_* bus events (message received, stage changed…). */
function wa_adm_business_event_sql(string $alias = 'l'): string
{
    return "$alias.event_code NOT LIKE 'WA!_%' ESCAPE '!'";
}

/** Contacts that belong to the admission pipeline (leads, applicants, students admitted through an application). */
function wa_adm_pipeline_sql(string $alias = 'c'): string
{
    return "($alias.contact_type IN ('lead','applicant') OR ($alias.contact_type = 'student' AND ($alias.admission_id IS NOT NULL OR $alias.enquiry_id IS NOT NULL)))";
}

/** Ordered follow-up stages (key => name), terminal "not interested" last. */
function wa_adm_stages(): array
{
    $rows = db_all('SELECT stage_key, name, is_terminal FROM wa_followup_stages ORDER BY sort_order, id');
    if (!$rows) {
        return ['new_enquiry' => 'New Enquiry', 'contacted' => 'Contacted', 'interested' => 'Interested', 'counselling_scheduled' => 'Counselling Scheduled',
            'application_started' => 'Application Started', 'application_submitted' => 'Application Submitted', 'payment_pending' => 'Payment Pending',
            'admission_confirmed' => 'Admission Confirmed', 'not_interested' => 'Not Interested'];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[$r['stage_key']] = $r['name'];
    }
    return $out;
}

/**
 * Cumulative admission funnel for contacts created in the window: count of contacts that reached each stage (current
 * stage at or after it); "not_interested" is reported separately as lost.
 */
function wa_adm_funnel(string $start, string $end, array $filters = []): array
{
    $stages = wa_adm_stages();
    $order = array_values(array_diff(array_keys($stages), ['not_interested']));
    $where = ['c.created_at BETWEEN ? AND ?', wa_adm_pipeline_sql('c'), "c.status <> 'archived'"];
    $args = [$start, $end];
    if (!empty($filters['program_id'])) {
        $where[] = 'c.program_id = ?';
        $args[] = (int) $filters['program_id'];
    }
    if (!empty($filters['source'])) {
        $where[] = 'COALESCE(c.lead_source, c.source) = ?';
        $args[] = (string) $filters['source'];
    }
    $counts = db_pairs('SELECT c.stage, COUNT(*) FROM wa_contacts c WHERE ' . implode(' AND ', $where) . ' GROUP BY c.stage', $args);
    $total = array_sum(array_map('intval', $counts));
    $lost = (int) ($counts['not_interested'] ?? 0);
    $rows = [];
    $n = count($order);
    for ($i = 0; $i < $n; $i++) {
        $reached = 0;
        for ($j = $i; $j < $n; $j++) {
            $reached += (int) ($counts[$order[$j]] ?? 0);
        }
        $rows[] = ['key' => $order[$i], 'label' => $stages[$order[$i]], 'current' => (int) ($counts[$order[$i]] ?? 0), 'reached' => $i === 0 ? $total : $reached];
    }
    $first = $rows[0]['reached'] ?? 0;
    foreach ($rows as $i => &$r) {
        $r['pct_of_total'] = wa_adm_pct($r['reached'], $first);
        $r['pct_of_prev'] = $i === 0 ? 100.0 : wa_adm_pct($r['reached'], $rows[$i - 1]['reached']);
    }
    unset($r);
    $confirmed = (int) ($counts['admission_confirmed'] ?? 0);
    return ['stages' => $rows, 'total' => $total, 'lost' => $lost, 'confirmed' => $confirmed, 'conversion_rate' => wa_adm_pct($confirmed, $total)];
}

/** Recent activity: agent actions, events fired, inbound messages, campaign milestones (newest first). */
function wa_adm_activity_feed(int $limit = 20): array
{
    $items = [];
    // Chat content follows the inbox rule: only users with WhatsApp inbox access see agent actions and incoming
    // message previews, and non-managers only for chats assigned to them or unassigned (wa_inbox_scope()).
    $inbox = wa_can('whatsapp', 'view');
    $scope = '';
    $scopeArgs = [];
    if ($inbox && !wa_can('whatsapp', 'manage')) {
        $scope = ' AND (v.id IS NULL OR v.assigned_to IS NULL OR v.assigned_to = ?)';
        $scopeArgs = [(int) user_id()];
    }
    foreach (!$inbox ? [] : db_all("SELECT a.id, a.action, a.detail, a.created_at, a.conversation_id, a.contact_id, u.name AS user_name, c.name AS contact_name, c.phone
                     FROM wa_agent_activity a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN wa_contacts c ON c.id = a.contact_id
                     LEFT JOIN wa_conversations v ON v.id = a.conversation_id WHERE 1 = 1$scope
                     ORDER BY a.id DESC LIMIT " . (int) $limit, $scopeArgs) as $r) {
        $who = $r['contact_name'] ?: ($r['phone'] ? wa_adm_phone($r['phone']) : null);
        $items[] = ['id' => 'a' . $r['id'], 'kind' => 'agent', 'action' => $r['action'], 'at' => $r['created_at'],
            'title' => ($r['user_name'] ?: 'System') . ' · ' . str_replace('_', ' ', (string) $r['action']) . ($who ? ' — ' . $who : ''),
            'detail' => $r['detail'], 'link' => $r['conversation_id'] ? '/whatsapp/inbox?c=' . (int) $r['conversation_id'] : null];
    }
    foreach (db_all("SELECT l.id, l.event_code, l.status, l.created_at, l.error, e.name AS event_name, c.name AS contact_name, c.phone
                     FROM wa_event_logs l LEFT JOIN wa_events e ON e.code = l.event_code LEFT JOIN wa_contacts c ON c.id = l.contact_id
                     WHERE " . wa_adm_business_event_sql('l') . "
                     ORDER BY l.id DESC LIMIT " . (int) $limit) as $r) {
        $who = $r['contact_name'] ?: ($r['phone'] ? wa_adm_phone($r['phone']) : null);
        $items[] = ['id' => 'e' . $r['id'], 'kind' => 'event', 'action' => $r['status'], 'at' => $r['created_at'],
            'title' => 'Event ' . ($r['event_name'] ?: $r['event_code']) . ($who ? ' — ' . $who : ''),
            'detail' => $r['status'] === 'failed' ? ($r['error'] ?: 'Failed') : ucfirst((string) $r['status']), 'link' => '/whatsapp/events?tab=logs'];
    }
    foreach (!$inbox ? [] : db_all("SELECT m.id, m.body, m.type, m.created_at, m.conversation_id, c.name AS contact_name, c.phone
                     FROM wa_messages m JOIN wa_contacts c ON c.id = m.contact_id LEFT JOIN wa_conversations v ON v.id = m.conversation_id
                     WHERE m.direction = 'in'$scope
                     ORDER BY m.id DESC LIMIT " . (int) $limit, $scopeArgs) as $r) {
        $who = $r['contact_name'] ?: wa_adm_phone($r['phone']);
        $items[] = ['id' => 'm' . $r['id'], 'kind' => 'inbound', 'action' => 'received', 'at' => $r['created_at'],
            'title' => 'Message from ' . $who, 'detail' => $r['body'] ? wa_preview((string) $r['body'], 90) : ucfirst((string) $r['type']),
            'link' => '/whatsapp/inbox?c=' . (int) $r['conversation_id']];
    }
    foreach (db_all("SELECT id, name, status, started_at, completed_at, sent_count FROM wa_campaigns
                     WHERE started_at IS NOT NULL ORDER BY COALESCE(completed_at, started_at) DESC LIMIT 5") as $r) {
        $done = $r['status'] === 'completed' && $r['completed_at'];
        $items[] = ['id' => 'c' . $r['id'], 'kind' => 'campaign', 'action' => $r['status'], 'at' => $done ? $r['completed_at'] : $r['started_at'],
            'title' => 'Campaign ' . ($done ? 'completed' : ($r['status'] === 'sending' ? 'sending' : $r['status'])) . ' — ' . $r['name'],
            'detail' => number_format((int) $r['sent_count']) . ' sent', 'link' => '/whatsapp/campaigns/' . (int) $r['id']];
    }
    usort($items, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
    return array_slice($items, 0, $limit);
}

/** Integration health for the dashboard card and the Integrations page. */
function wa_adm_connection(): array
{
    $s = wa_status_summary();
    $since = date('Y-m-d H:i:s', time() - 86400);
    // only requests that carried an Interakt signature count as signature failures — unsigned scanner noise does not
    $hooks = db_row("SELECT COUNT(*) AS total, SUM(status = 'failed') AS failed, SUM(status = 'ignored' AND signature_valid = 0 AND " . WA_ADM_SIGNED_SQL . ") AS bad_sig
                     FROM wa_webhook_logs WHERE received_at >= ?", [$since]) ?: [];
    $api = db_row('SELECT COUNT(*) AS total, SUM(ok = 0) AS failed, AVG(duration_ms) AS avg_ms FROM wa_api_logs WHERE created_at >= ?', [$since]) ?: [];
    $test = wa_json_decode(setting('_wa_last_connection_test'), null);
    // Opportunistic light ticks (while WhatsApp pages are open) keep messages flowing without the cron, but never mark the
    // cron healthy — so a missing cron is shown honestly as "pages only" instead of "never ran".
    $lightAt = $s['last_light_tick_at'] ?? null;
    $lightAge = $lightAt ? max(0, time() - (int) strtotime((string) $lightAt)) : null;
    $pagesOnly = !$s['worker']['healthy'] && $lightAge !== null && $lightAge <= 600;
    $out = [
        'mode' => $s['mode'], 'mode_setting' => $s['mode_setting'], 'configured' => $s['configured'], 'is_dev' => $s['is_dev'], 'plan' => $s['plan'],
        'rate_per_minute' => $s['rate_per_minute'], 'business_number' => $s['business_number'], 'business_name' => $s['business_name'],
        'last_webhook_at' => $s['last_webhook_at'], 'last_webhook_simulated' => $s['last_webhook_simulated'], 'webhooks_pending' => $s['webhooks_pending'],
        'worker' => $s['worker'], 'last_cron_at' => $s['last_cron_at'], 'queue' => $s['queue'],
        'last_light_tick_at' => $lightAt, 'light_tick_age' => $lightAge, 'pages_only' => $pagesOnly,
        'webhooks_24h' => ['total' => wa_adm_int($hooks['total'] ?? 0), 'failed' => wa_adm_int($hooks['failed'] ?? 0), 'bad_signature' => wa_adm_int($hooks['bad_sig'] ?? 0)],
        'api_24h' => ['total' => wa_adm_int($api['total'] ?? 0), 'failed' => wa_adm_int($api['failed'] ?? 0), 'avg_ms' => (int) round((float) ($api['avg_ms'] ?? 0))],
        'last_test' => is_array($test) ? $test : null,
        'incoming_supported' => in_array($s['plan'], ['advanced', 'enterprise'], true),
        // core status summary (live mode): ok | silent | bad_signature | never | n/a — absent on older cores
        'webhook_health' => isset($s['webhook_health']) && is_string($s['webhook_health']) ? $s['webhook_health'] : null,
        'webhook_health_note' => isset($s['webhook_health_note']) && is_string($s['webhook_health_note']) && $s['webhook_health_note'] !== '' ? $s['webhook_health_note'] : null,
    ];
    // overall health: ok | warning | error
    $issues = [];
    if (!$s['configured']) {
        $issues[] = 'No Interakt API key saved';
    }
    if (!$s['worker']['healthy']) {
        $issues[] = $pagesOnly
            ? ($s['worker']['status'] === 'never' ? 'Cron not set up' : 'Cron stopped') . ' — messages are processed only while WhatsApp pages are open'
            : 'Background worker ' . ($s['worker']['status'] === 'never' ? 'has never run' : 'is stale');
    }
    if ($out['webhook_health'] === 'silent') {
        $issues[] = 'Interakt webhook silent — it may have been auto-disabled; re-enable it in Interakt › Developer Settings';
    } elseif ($out['webhook_health'] === 'bad_signature') {
        $issues[] = 'Interakt webhooks fail the signature check — the secret in GIMT and Interakt differ';
    }
    if ($out['webhooks_24h']['failed'] + $out['webhooks_24h']['bad_signature'] > 0) {
        $issues[] = ($out['webhooks_24h']['failed'] + $out['webhooks_24h']['bad_signature']) . ' webhook failure(s) in 24 h';
    }
    if ($out['api_24h']['failed'] > 0) {
        $issues[] = $out['api_24h']['failed'] . ' failed API call(s) in 24 h';
    }
    if ($s['queue']['due'] > 200) {
        $issues[] = $s['queue']['due'] . ' messages waiting in the queue';
    }
    $hookDown = in_array($out['webhook_health'], ['silent', 'bad_signature'], true);
    $out['health'] = (!$s['worker']['healthy'] && $s['worker']['status'] === 'never' && !$pagesOnly) || $hookDown ? 'error' : ($issues ? 'warning' : 'ok');
    $out['issues'] = $issues;
    return $out;
}

/** Full dashboard payload. */
function wa_adm_dashboard(array $q): array
{
    $range = wa_adm_range($q);
    [$start, $end, $ps, $pe] = [$range['start'], $range['end'], $range['prev_start'], $range['prev_end']];
    $now = wa_adm_message_counts($start, $end);
    $prev = wa_adm_message_counts($ps, $pe);

    $contacts = db_row("SELECT COUNT(*) AS total, SUM(created_at BETWEEN ? AND ?) AS new_in, SUM(opt_in = 1) AS opted_in
                        FROM wa_contacts WHERE status <> 'archived'", [$start, $end]) ?: [];
    $leads = (int) db_value("SELECT COUNT(*) FROM wa_contacts c WHERE c.status = 'active' AND c.contact_type IN ('lead','applicant')
                              AND c.stage NOT IN ('admission_confirmed','not_interested')");
    $leadsNew = (int) db_value("SELECT COUNT(*) FROM wa_contacts c WHERE c.contact_type IN ('lead','applicant') AND c.created_at BETWEEN ? AND ?", [$start, $end]);
    $enq = (int) db_value('SELECT COUNT(*) FROM enquiries WHERE created_at BETWEEN ? AND ?', [$start, $end]);
    $enqPrev = (int) db_value('SELECT COUNT(*) FROM enquiries WHERE created_at BETWEEN ? AND ?', [$ps, $pe]);
    $enqWa = (int) db_value("SELECT COUNT(*) FROM enquiries WHERE created_at BETWEEN ? AND ? AND source = 'whatsapp'", [$start, $end]);
    $conv = db_row("SELECT SUM(status IN ('open','pending')) AS active, SUM(status <> 'resolved' AND last_message_direction = 'in') AS waiting,
                           SUM(status <> 'resolved' AND last_message_direction = 'in' AND assigned_to IS NULL) AS waiting_unassigned
                    FROM wa_conversations") ?: [];
    // admission confirmations of pipeline contacts (leads / applicants / admitted students), not guardians or bulk-synced records
    $confirm = fn ($a, $b) => (int) db_value("SELECT COUNT(DISTINCT h.contact_id) FROM wa_contact_stage_history h JOIN wa_contacts c ON c.id = h.contact_id
                                              WHERE h.to_stage = 'admission_confirmed' AND h.created_at BETWEEN ? AND ? AND " . wa_adm_pipeline_sql('c'), [$a, $b]);
    $fee = fn ($a, $b) => (int) db_value("SELECT COUNT(*) FROM wa_messages m JOIN wa_event_logs l ON l.id = m.event_log_id JOIN wa_events e ON e.code = l.event_code
                                          WHERE e.category = 'payment' AND m.direction = 'out' AND m.status NOT IN ('cancelled') AND m.created_at BETWEEN ? AND ?", [$a, $b]);
    $wfActive = (int) db_value("SELECT COUNT(*) FROM wa_workflows WHERE status = 'active'");
    $evActive = (int) db_value('SELECT COUNT(*) FROM wa_events WHERE is_active = 1');
    $cmp = db_row("SELECT SUM(status IN ('scheduled','sending')) AS active, SUM(status = 'sending') AS sending, SUM(status = 'scheduled') AS scheduled FROM wa_campaigns") ?: [];

    $kpis = [
        'contacts' => ['value' => wa_adm_int($contacts['total'] ?? 0), 'new' => wa_adm_int($contacts['new_in'] ?? 0), 'opted_in' => wa_adm_int($contacts['opted_in'] ?? 0)],
        'leads' => ['value' => $leads, 'new' => $leadsNew],
        'enquiries' => ['value' => $enq, 'whatsapp' => $enqWa, 'trend' => wa_adm_trend($enq, $enqPrev)],
        // "sent" = handed to Interakt (or simulated in test mode), failed ones included — the delivery rates use it as the base
        'sent' => ['value' => $now['sent'], 'trend' => wa_adm_trend($now['sent'], $prev['sent']), 'simulated' => $now['simulated'], 'queued' => $now['queued']],
        'received' => ['value' => $now['received'], 'trend' => wa_adm_trend($now['received'], $prev['received'])],
        'delivered' => ['value' => $now['delivered'], 'rate' => wa_adm_pct($now['delivered'], $now['sent']), 'trend' => wa_adm_trend($now['delivered'], $prev['delivered'])],
        'read' => ['value' => $now['read'], 'rate' => wa_adm_pct($now['read'], $now['sent']), 'trend' => wa_adm_trend($now['read'], $prev['read'])],
        'failed' => ['value' => $now['failed'], 'rate' => wa_adm_pct($now['failed'], $now['sent']), 'trend' => wa_adm_trend($now['failed'], $prev['failed'], false)],
        'conversations' => ['value' => wa_adm_int($conv['active'] ?? 0)],
        'pending_replies' => ['value' => wa_adm_int($conv['waiting'] ?? 0), 'unassigned' => wa_adm_int($conv['waiting_unassigned'] ?? 0)],
        'admissions' => ['value' => $c1 = $confirm($start, $end), 'trend' => wa_adm_trend($c1, $confirm($ps, $pe))],
        'fee_notifications' => ['value' => $f1 = $fee($start, $end), 'trend' => wa_adm_trend($f1, $fee($ps, $pe))],
        'automations' => ['value' => $wfActive, 'events' => $evActive],
        'campaigns' => ['value' => wa_adm_int($cmp['active'] ?? 0), 'sending' => wa_adm_int($cmp['sending'] ?? 0), 'scheduled' => wa_adm_int($cmp['scheduled'] ?? 0)],
    ];

    $trend = wa_adm_series("SELECT DATE(created_at) AS d,
            SUM(direction = 'out' AND status NOT IN ('queued','cancelled')) AS sent,
            SUM(direction = 'out' AND status IN ('delivered','read')) AS delivered,
            SUM(direction = 'out' AND status = 'read') AS rd,
            SUM(direction = 'out' AND status = 'failed') AS failed,
            SUM(direction = 'out' AND status <> 'cancelled') AS outbound,
            SUM(direction = 'in') AS inbound
        FROM wa_messages WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at)", [$start, $end], $range,
        ['sent', 'delivered', 'rd', 'failed', 'outbound', 'inbound']);

    $campaigns = db_all("SELECT c.id, c.name, c.status, c.type, COUNT(m.id) AS sent,
                                SUM(m.status IN ('delivered','read')) AS delivered, SUM(m.status = 'read') AS rd, SUM(m.status = 'failed') AS failed
                         FROM wa_campaigns c JOIN wa_messages m ON m.campaign_id = c.id AND m.direction = 'out' AND m.status NOT IN ('queued','cancelled')
                         WHERE m.created_at BETWEEN ? AND ? GROUP BY c.id, c.name, c.status, c.type ORDER BY sent DESC LIMIT 6", [$start, $end]);
    foreach ($campaigns as &$c) {
        $c = ['id' => (int) $c['id'], 'name' => $c['name'], 'status' => $c['status'], 'type' => $c['type'], 'sent' => (int) $c['sent'],
            'delivered' => (int) $c['delivered'], 'read' => (int) $c['rd'], 'failed' => (int) $c['failed'],
            'read_rate' => wa_adm_pct($c['rd'], $c['sent']), 'delivery_rate' => wa_adm_pct($c['delivered'], $c['sent'])];
    }
    unset($c);

    $events = db_all("SELECT COALESCE(e.category, 'custom') AS category, COUNT(*) AS total, SUM(l.status = 'processed') AS processed,
                             SUM(l.status = 'failed') AS failed, SUM(l.status IN ('skipped','duplicate')) AS skipped
                      FROM wa_event_logs l LEFT JOIN wa_events e ON e.code = l.event_code
                      WHERE l.created_at BETWEEN ? AND ? AND " . wa_adm_business_event_sql('l') . " GROUP BY COALESCE(e.category, 'custom') ORDER BY total DESC", [$start, $end]);
    $events = array_map(fn ($r) => ['category' => $r['category'], 'label' => ucfirst((string) $r['category']), 'total' => (int) $r['total'], 'processed' => (int) $r['processed'],
        'failed' => (int) $r['failed'], 'skipped' => (int) $r['skipped']], $events);

    $today = date('Y-m-d 00:00:00');
    $runs = db_row("SELECT SUM(started_at >= ?) AS today, SUM(started_at >= ? AND status = 'failed') AS failed_today,
                           SUM(status IN ('running','waiting')) AS in_progress, SUM(started_at BETWEEN ? AND ?) AS in_window,
                           SUM(started_at BETWEEN ? AND ? AND status = 'completed') AS completed_window, SUM(started_at BETWEEN ? AND ? AND status = 'failed') AS failed_window
                    FROM wa_workflow_runs WHERE is_test = 0", [$today, $today, $start, $end, $start, $end, $start, $end]) ?: [];
    $evToday = db_row("SELECT COUNT(*) AS fired, SUM(l.status = 'failed') AS failed FROM wa_event_logs l WHERE l.created_at >= ? AND " . wa_adm_business_event_sql('l'), [$today]) ?: [];
    $fu = db_row("SELECT SUM(status = 'scheduled') AS scheduled, SUM(status = 'scheduled' AND due_at <= ?) AS due, SUM(status = 'sent' AND updated_at >= ?) AS sent_today
                  FROM wa_followups", [wa_now(), $today]) ?: [];
    $topWf = db_all("SELECT w.id, w.name, w.status, SUM(r.started_at >= ?) AS runs_today, COUNT(r.id) AS runs, SUM(r.status = 'failed') AS failed, SUM(r.status = 'completed') AS completed
                     FROM wa_workflows w LEFT JOIN wa_workflow_runs r ON r.workflow_id = w.id AND r.is_test = 0 AND r.started_at BETWEEN ? AND ?
                     WHERE w.status = 'active' GROUP BY w.id, w.name, w.status ORDER BY runs DESC LIMIT 5", [$today, $start, $end]);

    return [
        'range' => array_diff_key($range, ['keys' => 1]),
        'kpis' => $kpis,
        'charts' => [
            'labels' => $range['labels'],
            'delivery' => ['sent' => $trend['sent'], 'delivered' => $trend['delivered'], 'read' => $trend['rd'], 'failed' => $trend['failed']],
            'direction' => ['outbound' => $trend['outbound'], 'inbound' => $trend['inbound']],
            'funnel' => wa_adm_funnel($start, $end),
            'campaigns' => $campaigns,
            'events' => $events,
        ],
        'activity' => wa_adm_activity_feed(18),
        'connection' => wa_adm_connection(),
        'automation' => [
            'workflows_active' => $wfActive,
            'events_active' => $evActive,
            'runs_today' => wa_adm_int($runs['today'] ?? 0),
            'failed_today' => wa_adm_int($runs['failed_today'] ?? 0),
            'in_progress' => wa_adm_int($runs['in_progress'] ?? 0),
            'runs_window' => wa_adm_int($runs['in_window'] ?? 0),
            'completion_rate' => wa_adm_pct($runs['completed_window'] ?? 0, $runs['in_window'] ?? 0),
            'failed_window' => wa_adm_int($runs['failed_window'] ?? 0),
            'events_today' => wa_adm_int($evToday['fired'] ?? 0),
            'events_failed_today' => wa_adm_int($evToday['failed'] ?? 0),
            'followups_scheduled' => wa_adm_int($fu['scheduled'] ?? 0),
            'followups_due' => wa_adm_int($fu['due'] ?? 0),
            'followups_sent_today' => wa_adm_int($fu['sent_today'] ?? 0),
            'paused' => wa_adm_automation_paused(),
            'workflows' => array_map(fn ($w) => ['id' => (int) $w['id'], 'name' => $w['name'], 'runs' => (int) $w['runs'], 'runs_today' => (int) $w['runs_today'],
                'completed' => (int) $w['completed'], 'failed' => (int) $w['failed']], $topWf),
        ],
    ];
}

/* =================================================================================================================
 * Reports (Module 12)
 * ================================================================================================================= */

/** Report catalogue: key => [title, description]. */
function wa_adm_report_catalog(): array
{
    return [
        'messages' => ['Messages', 'Sent, delivered, read and failed messages by day, sender and template.'],
        'campaigns' => ['Campaigns', 'Recipients, delivery, read and reply rates per campaign.'],
        'templates' => ['Templates', 'Usage and performance of every WhatsApp template.'],
        'conversion' => ['Admission conversion', 'Enquiry-to-admission funnel by stage, program and source.'],
        'counsellors' => ['Counsellor performance', 'First response time, replies, resolved chats and open load per counsellor.'],
        'engagement' => ['Student engagement', 'Reply rates and active contacts by contact type.'],
        'automation' => ['Automation execution', 'Workflow runs and event executions with completion and failure rates.'],
        'failures' => ['Failed automation', 'Failed workflow runs, events and messages with their errors.'],
        'courses' => ['Course-wise enquiries', 'Enquiries, WhatsApp leads and admissions per program.'],
    ];
}

/** Column helper. */
function wa_adm_col(string $key, string $label, string $format = 'text', array $extra = []): array
{
    return ['key' => $key, 'label' => $label, 'format' => $format] + $extra;
}

/** Programs list for filters. */
function wa_adm_programs(): array
{
    return array_map(fn ($r) => ['value' => (int) $r['id'], 'label' => $r['name']], db_all('SELECT id, name FROM programs ORDER BY name'));
}

/** Filter metadata for the reports page. */
function wa_adm_report_meta(): array
{
    $sources = db_column("SELECT DISTINCT COALESCE(lead_source, source) FROM wa_contacts WHERE COALESCE(lead_source, source) IS NOT NULL AND COALESCE(lead_source, source) <> '' ORDER BY 1");
    return [
        'reports' => array_map(fn ($k, $v) => ['key' => $k, 'title' => $v[0], 'description' => $v[1]], array_keys(wa_adm_report_catalog()), wa_adm_report_catalog()),
        'programs' => wa_adm_programs(),
        'sources' => array_map(fn ($s) => ['value' => $s, 'label' => ucwords(str_replace('_', ' ', (string) $s))], $sources),
        'sender_types' => array_map(fn ($s) => ['value' => $s, 'label' => ucfirst($s)], ['agent', 'bot', 'automation', 'event', 'campaign', 'followup', 'system']),
        'categories' => [['value' => 'MARKETING', 'label' => 'Marketing'], ['value' => 'UTILITY', 'label' => 'Utility'], ['value' => 'AUTHENTICATION', 'label' => 'Authentication']],
        'contact_types' => array_map(fn ($s) => ['value' => $s, 'label' => ucfirst($s)], ['lead', 'applicant', 'student', 'guardian', 'alumni', 'staff', 'other']),
        'can_export' => wa_adm_can_export(),
    ];
}

/**
 * Build a report. $q: from, to, period, program_id, source, sender_type, category, contact_type.
 * @return array{key:string,title:string,range:array,summary:array,charts:array,tables:array}
 */
function wa_adm_report(string $key, array $q): array
{
    $cat = wa_adm_report_catalog();
    if (!isset($cat[$key])) {
        throw new InvalidArgumentException('Unknown report.');
    }
    if (empty($q['period']) && empty($q['from'])) {
        $q['period'] = 'custom';
        $q['from'] = date('Y-m-d', strtotime('-29 days'));
        $q['to'] = date('Y-m-d');
    }
    $range = wa_adm_range($q);
    $fn = 'wa_adm_report_' . $key;
    $r = $fn($range, $q);
    return ['key' => $key, 'title' => $cat[$key][0], 'description' => $cat[$key][1], 'range' => array_diff_key($range, ['keys' => 1])] + $r;
}

function wa_adm_report_messages(array $range, array $q): array
{
    $where = ['m.created_at BETWEEN ? AND ?'];
    $args = [$range['start'], $range['end']];
    if (!empty($q['sender_type']) && in_array($q['sender_type'], ['agent', 'bot', 'automation', 'event', 'campaign', 'followup', 'system', 'contact'], true)) {
        $where[] = 'm.sender_type = ?';
        $args[] = $q['sender_type'];
    }
    $w = implode(' AND ', $where);
    $sel = "SUM(m.direction = 'out' AND m.status NOT IN ('queued','cancelled')) AS sent, SUM(m.direction = 'out' AND m.status IN ('delivered','read')) AS delivered,
            SUM(m.direction = 'out' AND m.status = 'read') AS rd, SUM(m.direction = 'out' AND m.status = 'failed') AS failed, SUM(m.direction = 'in') AS received,
            SUM(COALESCE(m.cost, 0)) AS cost";
    $byDay = db_all("SELECT DATE(m.created_at) AS d, $sel FROM wa_messages m WHERE $w GROUP BY DATE(m.created_at) ORDER BY d", $args);
    $series = wa_adm_series("SELECT DATE(m.created_at) AS d, $sel FROM wa_messages m WHERE $w GROUP BY DATE(m.created_at)", $args, $range, ['sent', 'delivered', 'rd', 'failed', 'received']);
    $bySender = db_all("SELECT m.sender_type, $sel FROM wa_messages m WHERE $w GROUP BY m.sender_type ORDER BY sent DESC", $args);
    $byTpl = db_all("SELECT COALESCE(t.display_name, m.template_name) AS template, t.category, $sel FROM wa_messages m LEFT JOIN wa_templates t ON t.id = m.template_id
                     WHERE $w AND m.type = 'template' GROUP BY COALESCE(t.display_name, m.template_name), t.category ORDER BY sent DESC LIMIT 200", $args);
    $tot = ['sent' => 0, 'delivered' => 0, 'rd' => 0, 'failed' => 0, 'received' => 0, 'cost' => 0.0];
    $rows = [];
    foreach ($byDay as $r) {
        foreach ($tot as $k => $_) {
            $tot[$k] += $k === 'cost' ? (float) $r[$k] : (int) $r[$k];
        }
        $rows[] = ['date' => $r['d'], 'sent' => (int) $r['sent'], 'delivered' => (int) $r['delivered'], 'read' => (int) $r['rd'], 'failed' => (int) $r['failed'],
            'received' => (int) $r['received'], 'delivery_rate' => wa_adm_pct($r['delivered'], $r['sent']), 'read_rate' => wa_adm_pct($r['rd'], $r['sent']), 'cost' => round((float) $r['cost'], 2)];
    }
    $mapRates = fn ($r) => ['sent' => (int) $r['sent'], 'delivered' => (int) $r['delivered'], 'read' => (int) $r['rd'], 'failed' => (int) $r['failed'], 'received' => (int) $r['received'],
        'delivery_rate' => wa_adm_pct($r['delivered'], $r['sent']), 'read_rate' => wa_adm_pct($r['rd'], $r['sent']), 'failure_rate' => wa_adm_pct($r['failed'], $r['sent'])];
    $rateCols = [wa_adm_col('sent', 'Sent', 'number'), wa_adm_col('delivered', 'Delivered', 'number'), wa_adm_col('read', 'Read', 'number'), wa_adm_col('failed', 'Failed', 'number'),
        wa_adm_col('delivery_rate', 'Delivery %', 'percent'), wa_adm_col('read_rate', 'Read %', 'percent')];
    return [
        'summary' => [
            ['label' => 'Sent', 'value' => $tot['sent'], 'format' => 'number', 'tone' => 'blue'],
            ['label' => 'Delivered', 'value' => $tot['delivered'], 'format' => 'number', 'tone' => 'green', 'hint' => wa_adm_pct($tot['delivered'], $tot['sent']) . '% delivery'],
            ['label' => 'Read', 'value' => $tot['rd'], 'format' => 'number', 'tone' => 'navy', 'hint' => wa_adm_pct($tot['rd'], $tot['sent']) . '% read'],
            ['label' => 'Failed', 'value' => $tot['failed'], 'format' => 'number', 'tone' => 'red', 'hint' => wa_adm_pct($tot['failed'], $tot['sent']) . '% failed'],
            ['label' => 'Received', 'value' => $tot['received'], 'format' => 'number', 'tone' => 'cyan'],
            ['label' => 'Meta cost', 'value' => round($tot['cost'], 2), 'format' => 'money', 'tone' => 'amber', 'hint' => 'From delivery webhooks'],
        ],
        'charts' => [
            ['type' => 'line', 'title' => 'Message status trend', 'labels' => $range['labels'], 'series' => [
                ['label' => 'Sent', 'data' => $series['sent']], ['label' => 'Delivered', 'data' => $series['delivered']],
                ['label' => 'Read', 'data' => $series['rd']], ['label' => 'Failed', 'data' => $series['failed']], ['label' => 'Received', 'data' => $series['received'], 'dashed' => true]]],
            ['type' => 'doughnut', 'title' => 'Sent by sender type', 'labels' => array_map(fn ($r) => ucfirst((string) $r['sender_type']), $bySender),
                'data' => array_map(fn ($r) => (int) $r['sent'] + (int) $r['received'], $bySender)],
        ],
        'tables' => [
            ['key' => 'daily', 'title' => 'By day', 'columns' => array_merge([wa_adm_col('date', 'Date', 'date')], $rateCols, [wa_adm_col('received', 'Received', 'number'), wa_adm_col('cost', 'Cost', 'money')]), 'rows' => array_reverse($rows)],
            ['key' => 'sender', 'title' => 'By sender type', 'columns' => array_merge([wa_adm_col('sender_type', 'Sender', 'badge')], $rateCols, [wa_adm_col('received', 'Received', 'number'), wa_adm_col('failure_rate', 'Failure %', 'percent')]),
                'rows' => array_map(fn ($r) => ['sender_type' => $r['sender_type']] + $mapRates($r), $bySender)],
            ['key' => 'template', 'title' => 'By template', 'columns' => array_merge([wa_adm_col('template', 'Template'), wa_adm_col('category', 'Category', 'badge')], $rateCols, [wa_adm_col('failure_rate', 'Failure %', 'percent')]),
                'rows' => array_map(fn ($r) => ['template' => $r['template'] ?: '—', 'category' => $r['category'] ?: '—'] + $mapRates($r), $byTpl)],
        ],
    ];
}

function wa_adm_report_campaigns(array $range, array $q): array
{
    $rows = db_all("SELECT c.id, c.name, c.type, c.status, c.total_recipients, c.skipped_count, c.started_at, c.completed_at, t.display_name AS template, t.name AS template_code,
                           COUNT(m.id) AS sent, SUM(m.status IN ('delivered','read')) AS delivered, SUM(m.status = 'read') AS rd, SUM(m.status = 'failed') AS failed,
                           (SELECT COUNT(*) FROM wa_campaign_recipients r WHERE r.campaign_id = c.id AND r.replied_at IS NOT NULL) AS replied
                    FROM wa_campaigns c
                    LEFT JOIN wa_templates t ON t.id = c.template_id
                    LEFT JOIN wa_messages m ON m.campaign_id = c.id AND m.direction = 'out' AND m.status NOT IN ('queued','cancelled')
                    WHERE (c.started_at BETWEEN ? AND ?) OR (c.scheduled_at BETWEEN ? AND ?) OR (c.created_at BETWEEN ? AND ?)
                    GROUP BY c.id, c.name, c.type, c.status, c.total_recipients, c.skipped_count, c.started_at, c.completed_at, t.display_name, t.name
                    ORDER BY COALESCE(c.started_at, c.scheduled_at, c.created_at) DESC",
        [$range['start'], $range['end'], $range['start'], $range['end'], $range['start'], $range['end']]);
    $out = [];
    $tot = ['recipients' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'replied' => 0];
    foreach ($rows as $r) {
        $o = ['id' => (int) $r['id'], 'name' => $r['name'], 'type' => $r['type'], 'status' => $r['status'], 'template' => $r['template'] ?: $r['template_code'],
            'recipients' => (int) $r['total_recipients'], 'sent' => (int) $r['sent'], 'delivered' => (int) $r['delivered'], 'read' => (int) $r['rd'], 'failed' => (int) $r['failed'],
            'replied' => (int) $r['replied'], 'skipped' => (int) $r['skipped_count'], 'delivery_rate' => wa_adm_pct($r['delivered'], $r['sent']),
            'read_rate' => wa_adm_pct($r['rd'], $r['sent']), 'reply_rate' => wa_adm_pct($r['replied'], $r['sent']), 'started_at' => $r['started_at']];
        foreach ($tot as $k => $_) {
            $tot[$k] += $o[$k];
        }
        $out[] = $o;
    }
    $top = array_slice(array_values(array_filter($out, fn ($o) => $o['sent'] > 0)), 0, 8);
    return [
        'summary' => [
            ['label' => 'Campaigns', 'value' => count($out), 'format' => 'number', 'tone' => 'navy'],
            ['label' => 'Recipients', 'value' => $tot['recipients'], 'format' => 'number', 'tone' => 'blue'],
            ['label' => 'Sent', 'value' => $tot['sent'], 'format' => 'number', 'tone' => 'cyan'],
            ['label' => 'Delivery rate', 'value' => wa_adm_pct($tot['delivered'], $tot['sent']), 'format' => 'percent', 'tone' => 'green'],
            ['label' => 'Read rate', 'value' => wa_adm_pct($tot['read'], $tot['sent']), 'format' => 'percent', 'tone' => 'purple'],
            ['label' => 'Reply rate', 'value' => wa_adm_pct($tot['replied'], $tot['sent']), 'format' => 'percent', 'tone' => 'orange'],
        ],
        'charts' => [
            ['type' => 'bar', 'title' => 'Campaign performance', 'labels' => array_map(fn ($o) => mb_strimwidth($o['name'], 0, 28, '…'), $top), 'series' => [
                ['label' => 'Delivered %', 'data' => array_map(fn ($o) => $o['delivery_rate'], $top)],
                ['label' => 'Read %', 'data' => array_map(fn ($o) => $o['read_rate'], $top)],
                ['label' => 'Reply %', 'data' => array_map(fn ($o) => $o['reply_rate'], $top)]], 'percent' => true],
        ],
        'tables' => [['key' => 'campaigns', 'title' => 'Campaigns', 'columns' => [
            wa_adm_col('name', 'Campaign', 'link', ['link' => '/whatsapp/campaigns/{id}']), wa_adm_col('type', 'Type', 'badge'), wa_adm_col('status', 'Status', 'status'), wa_adm_col('template', 'Template'),
            wa_adm_col('recipients', 'Recipients', 'number'), wa_adm_col('sent', 'Sent', 'number'), wa_adm_col('delivered', 'Delivered', 'number'), wa_adm_col('read', 'Read', 'number'),
            wa_adm_col('failed', 'Failed', 'number'), wa_adm_col('replied', 'Replied', 'number'), wa_adm_col('delivery_rate', 'Delivery %', 'percent'), wa_adm_col('read_rate', 'Read %', 'percent'),
            wa_adm_col('reply_rate', 'Reply %', 'percent'), wa_adm_col('started_at', 'Started', 'datetime')], 'rows' => $out]],
    ];
}

function wa_adm_report_templates(array $range, array $q): array
{
    $where = "t.status <> 'archived'";
    $args = [$range['start'], $range['end']];
    if (!empty($q['category']) && in_array($q['category'], ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true)) {
        $where .= ' AND t.category = ?';
        $args[] = $q['category'];
    }
    $rows = db_all("SELECT t.id, COALESCE(t.display_name, t.name) AS template, t.name AS code, t.category, t.language, t.status, t.usage_count, t.last_used_at,
                           COUNT(m.id) AS sent, SUM(m.status IN ('delivered','read')) AS delivered, SUM(m.status = 'read') AS rd, SUM(m.status = 'failed') AS failed
                    FROM wa_templates t
                    LEFT JOIN wa_messages m ON m.template_id = t.id AND m.direction = 'out' AND m.status NOT IN ('queued','cancelled') AND m.created_at BETWEEN ? AND ?
                    WHERE $where GROUP BY t.id, t.display_name, t.name, t.category, t.language, t.status, t.usage_count, t.last_used_at
                    ORDER BY sent DESC, t.usage_count DESC", $args);
    $out = array_map(fn ($r) => ['id' => (int) $r['id'], 'template' => $r['template'], 'code' => $r['code'], 'category' => $r['category'], 'language' => $r['language'], 'status' => $r['status'],
        'sent' => (int) $r['sent'], 'delivered' => (int) $r['delivered'], 'read' => (int) $r['rd'], 'failed' => (int) $r['failed'],
        'read_rate' => wa_adm_pct($r['rd'], $r['sent']), 'failure_rate' => wa_adm_pct($r['failed'], $r['sent']), 'usage_total' => (int) $r['usage_count'], 'last_used_at' => $r['last_used_at']], $rows);
    $used = array_values(array_filter($out, fn ($o) => $o['sent'] > 0));
    $byCat = [];
    foreach ($out as $o) {
        $byCat[$o['category']] = ($byCat[$o['category']] ?? 0) + $o['sent'];
    }
    $status = db_pairs("SELECT status, COUNT(*) FROM wa_templates GROUP BY status");
    return [
        'summary' => [
            ['label' => 'Templates', 'value' => count($out), 'format' => 'number', 'tone' => 'navy'],
            ['label' => 'Approved', 'value' => (int) ($status['approved'] ?? 0), 'format' => 'number', 'tone' => 'green'],
            ['label' => 'Pending review', 'value' => (int) ($status['pending'] ?? 0), 'format' => 'number', 'tone' => 'amber'],
            ['label' => 'Used in period', 'value' => count($used), 'format' => 'number', 'tone' => 'blue'],
            ['label' => 'Messages', 'value' => array_sum(array_column($out, 'sent')), 'format' => 'number', 'tone' => 'cyan'],
            ['label' => 'Avg read rate', 'value' => wa_adm_pct(array_sum(array_column($out, 'read')), array_sum(array_column($out, 'sent'))), 'format' => 'percent', 'tone' => 'purple'],
        ],
        'charts' => [
            ['type' => 'bar', 'title' => 'Top templates by messages', 'labels' => array_map(fn ($o) => mb_strimwidth((string) $o['template'], 0, 26, '…'), array_slice($used, 0, 8)), 'horizontal' => true,
                'series' => [['label' => 'Sent', 'data' => array_map(fn ($o) => $o['sent'], array_slice($used, 0, 8))], ['label' => 'Read', 'data' => array_map(fn ($o) => $o['read'], array_slice($used, 0, 8))]]],
            ['type' => 'doughnut', 'title' => 'Messages by category', 'labels' => array_map('ucfirst', array_map('strtolower', array_keys($byCat))), 'data' => array_values($byCat)],
        ],
        'tables' => [['key' => 'templates', 'title' => 'Templates', 'columns' => [
            wa_adm_col('template', 'Template'), wa_adm_col('code', 'Code name', 'code'), wa_adm_col('category', 'Category', 'badge'), wa_adm_col('language', 'Lang'),
            wa_adm_col('status', 'Status', 'status'), wa_adm_col('sent', 'Sent', 'number'), wa_adm_col('delivered', 'Delivered', 'number'), wa_adm_col('read', 'Read', 'number'),
            wa_adm_col('failed', 'Failed', 'number'), wa_adm_col('read_rate', 'Read %', 'percent'), wa_adm_col('failure_rate', 'Failure %', 'percent'),
            wa_adm_col('usage_total', 'All-time uses', 'number'), wa_adm_col('last_used_at', 'Last used', 'datetime')], 'rows' => $out]],
    ];
}

function wa_adm_report_conversion(array $range, array $q): array
{
    $f = ['program_id' => $q['program_id'] ?? null, 'source' => $q['source'] ?? null];
    $funnel = wa_adm_funnel($range['start'], $range['end'], $f);
    $where = ['c.created_at BETWEEN ? AND ?', wa_adm_pipeline_sql('c'), "c.status <> 'archived'"];
    $args = [$range['start'], $range['end']];
    if (!empty($f['program_id'])) {
        $where[] = 'c.program_id = ?';
        $args[] = (int) $f['program_id'];
    }
    if (!empty($f['source'])) {
        $where[] = 'COALESCE(c.lead_source, c.source) = ?';
        $args[] = (string) $f['source'];
    }
    $w = implode(' AND ', $where);
    $agg = "COUNT(*) AS leads, SUM(c.stage NOT IN ('new_enquiry')) AS engaged, SUM(c.stage IN ('application_started','application_submitted','payment_pending','admission_confirmed')) AS applied,
            SUM(c.stage = 'admission_confirmed') AS confirmed, SUM(c.stage = 'not_interested') AS lost, SUM(c.last_inbound_at IS NOT NULL) AS replied";
    $conv = fn ($r) => ['leads' => (int) $r['leads'], 'engaged' => (int) $r['engaged'], 'applied' => (int) $r['applied'], 'confirmed' => (int) $r['confirmed'], 'lost' => (int) $r['lost'],
        'replied' => (int) $r['replied'], 'conversion_rate' => wa_adm_pct($r['confirmed'], $r['leads']), 'application_rate' => wa_adm_pct($r['applied'], $r['leads'])];
    $byProgram = db_all("SELECT COALESCE(p.name, c.program_name, 'Not specified') AS program, $agg FROM wa_contacts c LEFT JOIN programs p ON p.id = c.program_id
                         WHERE $w GROUP BY COALESCE(p.name, c.program_name, 'Not specified') ORDER BY leads DESC", $args);
    $bySource = db_all("SELECT COALESCE(c.lead_source, c.source, 'unknown') AS source, $agg FROM wa_contacts c WHERE $w GROUP BY COALESCE(c.lead_source, c.source, 'unknown') ORDER BY leads DESC", $args);
    $cols = [wa_adm_col('leads', 'Leads', 'number'), wa_adm_col('replied', 'Replied', 'number'), wa_adm_col('engaged', 'Engaged', 'number'), wa_adm_col('applied', 'Applied', 'number'),
        wa_adm_col('confirmed', 'Admitted', 'number'), wa_adm_col('lost', 'Not interested', 'number'), wa_adm_col('application_rate', 'Application %', 'percent'), wa_adm_col('conversion_rate', 'Conversion %', 'percent')];
    $topP = array_slice($byProgram, 0, 8);
    return [
        'summary' => [
            ['label' => 'Leads in period', 'value' => $funnel['total'], 'format' => 'number', 'tone' => 'navy'],
            ['label' => 'Applications', 'value' => array_sum(array_map(fn ($r) => (int) $r['applied'], $byProgram)), 'format' => 'number', 'tone' => 'blue'],
            ['label' => 'Admissions confirmed', 'value' => $funnel['confirmed'], 'format' => 'number', 'tone' => 'green'],
            ['label' => 'Conversion rate', 'value' => $funnel['conversion_rate'], 'format' => 'percent', 'tone' => 'orange'],
            ['label' => 'Not interested', 'value' => $funnel['lost'], 'format' => 'number', 'tone' => 'red'],
        ],
        'charts' => [
            ['type' => 'funnel', 'title' => 'Admission funnel', 'stages' => $funnel['stages']],
            ['type' => 'bar', 'title' => 'Leads vs admissions by program', 'labels' => array_map(fn ($r) => mb_strimwidth((string) $r['program'], 0, 24, '…'), $topP), 'series' => [
                ['label' => 'Leads', 'data' => array_map(fn ($r) => (int) $r['leads'], $topP)], ['label' => 'Admitted', 'data' => array_map(fn ($r) => (int) $r['confirmed'], $topP)]]],
            ['type' => 'doughnut', 'title' => 'Leads by source', 'labels' => array_map(fn ($r) => ucwords(str_replace('_', ' ', (string) $r['source'])), $bySource), 'data' => array_map(fn ($r) => (int) $r['leads'], $bySource)],
        ],
        'tables' => [
            ['key' => 'funnel', 'title' => 'Funnel by stage', 'columns' => [wa_adm_col('label', 'Stage'), wa_adm_col('reached', 'Reached', 'number'), wa_adm_col('current', 'Currently in stage', 'number'),
                wa_adm_col('pct_of_total', '% of leads', 'percent'), wa_adm_col('pct_of_prev', '% of previous stage', 'percent')], 'rows' => $funnel['stages']],
            ['key' => 'program', 'title' => 'By program', 'columns' => array_merge([wa_adm_col('program', 'Program')], $cols), 'rows' => array_map(fn ($r) => ['program' => $r['program']] + $conv($r), $byProgram)],
            ['key' => 'source', 'title' => 'By source', 'columns' => array_merge([wa_adm_col('source', 'Source', 'badge')], $cols), 'rows' => array_map(fn ($r) => ['source' => $r['source']] + $conv($r), $bySource)],
        ],
    ];
}

/** First-response stats per conversation for inbound turns in the window: [conversation_id => [first_in, first_reply, agent]]. */
function wa_adm_first_responses(string $start, string $end): array
{
    $rows = db_all("SELECT x.conversation_id, x.first_in,
                           (SELECT MIN(o.created_at) FROM wa_messages o WHERE o.conversation_id = x.conversation_id AND o.direction = 'out' AND o.sender_type = 'agent' AND o.created_at >= x.first_in) AS first_reply,
                           (SELECT o.sent_by FROM wa_messages o WHERE o.conversation_id = x.conversation_id AND o.direction = 'out' AND o.sender_type = 'agent' AND o.created_at >= x.first_in ORDER BY o.created_at, o.id LIMIT 1) AS agent_id
                    FROM (SELECT conversation_id, MIN(created_at) AS first_in FROM wa_messages WHERE direction = 'in' AND created_at BETWEEN ? AND ? GROUP BY conversation_id) x", [$start, $end]);
    return $rows;
}

/** Users who hold any whatsapp* permission (or are super admins), active only. */
function wa_adm_team_user_ids(): array
{
    $mods = wa_permission_modules();
    $ph = implode(',', array_fill(0, count($mods), '?'));
    return array_map('intval', db_column("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
        LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id
        WHERE u.status = 'active' AND (r.is_super = 1 OR p.module IN ($ph))", $mods));
}

/** Agent performance rows (used by the counsellor report and the team page). */
function wa_adm_agent_stats(string $start, string $end, ?array $userIds = null): array
{
    $userIds = $userIds ?? wa_adm_team_user_ids();
    // include anyone who replied / was assigned even if no longer in a WhatsApp role
    $extra = db_column("SELECT DISTINCT sent_by FROM wa_messages WHERE sender_type = 'agent' AND sent_by IS NOT NULL AND created_at BETWEEN ? AND ?", [$start, $end]);
    $extra = array_merge($extra, db_column("SELECT DISTINCT assigned_to FROM wa_conversations WHERE assigned_to IS NOT NULL AND status <> 'resolved'"));
    $ids = array_values(array_unique(array_merge($userIds, array_map('intval', $extra))));
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $users = db_all("SELECT u.id, u.name, u.email, u.avatar, u.designation, u.last_login_at, u.status FROM users u WHERE u.id IN ($ph) ORDER BY u.name", $ids);
    $open = db_pairs("SELECT assigned_to, COUNT(*) FROM wa_conversations WHERE assigned_to IN ($ph) AND status <> 'resolved' GROUP BY assigned_to", $ids);
    $waiting = db_pairs("SELECT assigned_to, COUNT(*) FROM wa_conversations WHERE assigned_to IN ($ph) AND status <> 'resolved' AND last_message_direction = 'in' GROUP BY assigned_to", $ids);
    $replies = db_pairs("SELECT sent_by, COUNT(*) FROM wa_messages WHERE sender_type = 'agent' AND direction = 'out' AND sent_by IN ($ph) AND created_at BETWEEN ? AND ? GROUP BY sent_by", array_merge($ids, [$start, $end]));
    $repliesToday = db_pairs("SELECT sent_by, COUNT(*) FROM wa_messages WHERE sender_type = 'agent' AND direction = 'out' AND sent_by IN ($ph) AND created_at >= ? GROUP BY sent_by", array_merge($ids, [date('Y-m-d 00:00:00')]));
    $convs = db_pairs("SELECT sent_by, COUNT(DISTINCT conversation_id) FROM wa_messages WHERE sender_type = 'agent' AND direction = 'out' AND sent_by IN ($ph) AND created_at BETWEEN ? AND ? GROUP BY sent_by", array_merge($ids, [$start, $end]));
    $resolved = db_pairs("SELECT user_id, COUNT(*) FROM wa_agent_activity WHERE action = 'resolve' AND user_id IN ($ph) AND created_at BETWEEN ? AND ? GROUP BY user_id", array_merge($ids, [$start, $end]));
    $actions = db_pairs("SELECT user_id, COUNT(*) FROM wa_agent_activity WHERE user_id IN ($ph) AND created_at BETWEEN ? AND ? GROUP BY user_id", array_merge($ids, [$start, $end]));
    $lastAct = db_pairs("SELECT user_id, MAX(created_at) FROM wa_agent_activity WHERE user_id IN ($ph) GROUP BY user_id", $ids);
    $leads = db_pairs("SELECT counsellor_id, COUNT(*) FROM wa_contacts WHERE counsellor_id IN ($ph) AND status = 'active' AND stage NOT IN ('admission_confirmed','not_interested') GROUP BY counsellor_id", $ids);
    $frt = [];
    foreach (wa_adm_first_responses($start, $end) as $r) {
        if ($r['first_reply'] && $r['agent_id']) {
            $frt[(int) $r['agent_id']][] = max(0, strtotime((string) $r['first_reply']) - strtotime((string) $r['first_in']));
        }
    }
    $out = [];
    foreach ($users as $u) {
        $id = (int) $u['id'];
        $times = $frt[$id] ?? [];
        sort($times);
        $avg = $times ? (int) round(array_sum($times) / count($times)) : null;
        $median = $times ? (int) $times[intdiv(count($times), 2)] : null;
        $out[] = [
            'id' => $id, 'name' => $u['name'], 'email' => $u['email'], 'avatar' => $u['avatar'], 'designation' => $u['designation'], 'last_login_at' => $u['last_login_at'],
            'open' => (int) ($open[$id] ?? 0), 'waiting' => (int) ($waiting[$id] ?? 0), 'leads' => (int) ($leads[$id] ?? 0),
            'replies' => (int) ($replies[$id] ?? 0), 'replies_today' => (int) ($repliesToday[$id] ?? 0), 'conversations' => (int) ($convs[$id] ?? 0),
            'resolved' => (int) ($resolved[$id] ?? 0), 'actions' => (int) ($actions[$id] ?? 0), 'first_responses' => count($times),
            'avg_first_response_sec' => $avg, 'median_first_response_sec' => $median, 'avg_first_response_min' => $avg !== null ? round($avg / 60, 1) : null,
            'last_activity_at' => $lastAct[$id] ?? null,
        ];
    }
    usort($out, fn ($a, $b) => [$b['replies'], $b['open']] <=> [$a['replies'], $a['open']]);
    return $out;
}

function wa_adm_report_counsellors(array $range, array $q): array
{
    $rows = wa_adm_agent_stats($range['start'], $range['end']);
    $rows = array_values(array_filter($rows, fn ($r) => $r['replies'] + $r['open'] + $r['resolved'] + $r['leads'] + $r['actions'] > 0));
    $fr = wa_adm_first_responses($range['start'], $range['end']);
    $answered = array_filter($fr, fn ($r) => $r['first_reply']);
    $secs = array_map(fn ($r) => max(0, strtotime((string) $r['first_reply']) - strtotime((string) $r['first_in'])), $answered);
    $avg = $secs ? (int) round(array_sum($secs) / count($secs)) : 0;
    sort($secs);
    $median = $secs ? (int) $secs[intdiv(count($secs), 2)] : 0;
    $within = count(array_filter($secs, fn ($s) => $s <= 3600));
    $top = array_slice($rows, 0, 10);
    return [
        'summary' => [
            ['label' => 'Conversations started', 'value' => count($fr), 'format' => 'number', 'tone' => 'navy', 'hint' => 'with an inbound message'],
            ['label' => 'Answered by an agent', 'value' => count($answered), 'format' => 'number', 'tone' => 'green', 'hint' => wa_adm_pct(count($answered), count($fr)) . '% answered'],
            ['label' => 'Median first response', 'value' => round($median / 60, 1), 'format' => 'minutes', 'tone' => 'orange', 'hint' => 'Average ' . wa_adm_duration($avg)],
            ['label' => 'Answered within 1 h', 'value' => wa_adm_pct($within, count($answered)), 'format' => 'percent', 'tone' => 'blue'],
            ['label' => 'Agent replies', 'value' => array_sum(array_column($rows, 'replies')), 'format' => 'number', 'tone' => 'cyan'],
            ['label' => 'Resolved', 'value' => array_sum(array_column($rows, 'resolved')), 'format' => 'number', 'tone' => 'purple'],
        ],
        'charts' => [
            ['type' => 'bar', 'title' => 'Replies and resolved chats', 'labels' => array_map(fn ($r) => $r['name'], $top), 'series' => [
                ['label' => 'Replies', 'data' => array_map(fn ($r) => $r['replies'], $top)], ['label' => 'Resolved', 'data' => array_map(fn ($r) => $r['resolved'], $top)],
                ['label' => 'Open now', 'data' => array_map(fn ($r) => $r['open'], $top)]]],
            ['type' => 'bar', 'title' => 'Average first response (minutes)', 'horizontal' => true, 'labels' => array_map(fn ($r) => $r['name'], array_values(array_filter($top, fn ($r) => $r['avg_first_response_min'] !== null))),
                'series' => [['label' => 'Minutes', 'data' => array_map(fn ($r) => $r['avg_first_response_min'], array_values(array_filter($top, fn ($r) => $r['avg_first_response_min'] !== null)))]]],
        ],
        'tables' => [['key' => 'counsellors', 'title' => 'Counsellors', 'columns' => [
            wa_adm_col('name', 'Counsellor', 'person', ['sub' => 'designation']), wa_adm_col('open', 'Open chats', 'number'), wa_adm_col('waiting', 'Awaiting reply', 'number'),
            wa_adm_col('leads', 'Active leads', 'number'), wa_adm_col('replies', 'Replies', 'number'), wa_adm_col('conversations', 'Chats handled', 'number'),
            wa_adm_col('resolved', 'Resolved', 'number'), wa_adm_col('first_responses', 'First responses', 'number'), wa_adm_col('avg_first_response_min', 'Avg first response (min)', 'decimal'),
            wa_adm_col('last_activity_at', 'Last activity', 'datetime')], 'rows' => $rows]],
    ];
}

function wa_adm_report_engagement(array $range, array $q): array
{
    $args = [$range['start'], $range['end']];
    $typeWhere = '';
    if (!empty($q['contact_type']) && in_array($q['contact_type'], ['lead', 'applicant', 'student', 'guardian', 'alumni', 'staff', 'other'], true)) {
        $typeWhere = ' AND c.contact_type = ?';
        $args[] = $q['contact_type'];
    }
    // contacts messaged in the window and whether they replied within 72 h of their first outbound message
    $rows = db_all("SELECT c.contact_type, COUNT(*) AS messaged,
                           SUM(EXISTS (SELECT 1 FROM wa_messages i WHERE i.contact_id = x.contact_id AND i.direction = 'in' AND i.created_at >= x.first_out
                                       AND i.created_at <= DATE_ADD(x.first_out, INTERVAL 72 HOUR))) AS replied
                    FROM (SELECT contact_id, MIN(created_at) AS first_out FROM wa_messages WHERE direction = 'out' AND status NOT IN ('queued','cancelled','failed')
                          AND created_at BETWEEN ? AND ? GROUP BY contact_id) x
                    JOIN wa_contacts c ON c.id = x.contact_id WHERE 1 = 1 $typeWhere GROUP BY c.contact_type ORDER BY messaged DESC", $args);
    $active = db_all("SELECT c.contact_type, COUNT(DISTINCT m.contact_id) AS active, COUNT(*) AS inbound
                      FROM wa_messages m JOIN wa_contacts c ON c.id = m.contact_id WHERE m.direction = 'in' AND m.created_at BETWEEN ? AND ? $typeWhere
                      GROUP BY c.contact_type", $args);
    $activeMap = [];
    foreach ($active as $a) {
        $activeMap[$a['contact_type']] = $a;
    }
    $series = wa_adm_series("SELECT DATE(m.created_at) AS d, COUNT(DISTINCT m.contact_id) AS active, COUNT(*) AS inbound FROM wa_messages m JOIN wa_contacts c ON c.id = m.contact_id
                             WHERE m.direction = 'in' AND m.created_at BETWEEN ? AND ? $typeWhere GROUP BY DATE(m.created_at)", $args, $range, ['active', 'inbound']);
    $out = [];
    $tm = $tr = 0;
    foreach ($rows as $r) {
        $a = $activeMap[$r['contact_type']] ?? ['active' => 0, 'inbound' => 0];
        $out[] = ['contact_type' => $r['contact_type'], 'messaged' => (int) $r['messaged'], 'replied' => (int) $r['replied'], 'reply_rate' => wa_adm_pct($r['replied'], $r['messaged']),
            'active' => (int) $a['active'], 'inbound' => (int) $a['inbound']];
        $tm += (int) $r['messaged'];
        $tr += (int) $r['replied'];
    }
    $top = db_all("SELECT c.id, c.name, c.phone, c.contact_type, c.program_name, COUNT(*) AS inbound, MAX(m.created_at) AS last_at
                   FROM wa_messages m JOIN wa_contacts c ON c.id = m.contact_id WHERE m.direction = 'in' AND m.created_at BETWEEN ? AND ? $typeWhere
                   GROUP BY c.id, c.name, c.phone, c.contact_type, c.program_name ORDER BY inbound DESC LIMIT 50", $args);
    $optRow = db_row("SELECT SUM(opt_in = 1) AS opted_in, SUM(opted_out_at BETWEEN ? AND ?) AS opted_out FROM wa_contacts", [$range['start'], $range['end']]) ?: [];
    return [
        'summary' => [
            ['label' => 'Contacts messaged', 'value' => $tm, 'format' => 'number', 'tone' => 'navy'],
            ['label' => 'Replied within 72 h', 'value' => $tr, 'format' => 'number', 'tone' => 'green'],
            ['label' => 'Reply rate', 'value' => wa_adm_pct($tr, $tm), 'format' => 'percent', 'tone' => 'orange'],
            ['label' => 'Active contacts', 'value' => array_sum(array_map(fn ($a) => (int) $a['active'], $active)), 'format' => 'number', 'tone' => 'blue', 'hint' => 'sent at least one message'],
            ['label' => 'Opted in', 'value' => wa_adm_int($optRow['opted_in'] ?? 0), 'format' => 'number', 'tone' => 'cyan'],
            ['label' => 'Opted out in period', 'value' => wa_adm_int($optRow['opted_out'] ?? 0), 'format' => 'number', 'tone' => 'red'],
        ],
        'charts' => [
            ['type' => 'line', 'title' => 'Active contacts', 'labels' => $range['labels'], 'series' => [['label' => 'Active contacts', 'data' => $series['active'], 'fill' => true], ['label' => 'Inbound messages', 'data' => $series['inbound'], 'dashed' => true]]],
            ['type' => 'bar', 'title' => 'Reply rate by contact type', 'labels' => array_map(fn ($o) => ucfirst((string) $o['contact_type']), $out), 'percent' => true,
                'series' => [['label' => 'Reply rate %', 'data' => array_map(fn ($o) => $o['reply_rate'], $out)]]],
        ],
        'tables' => [
            ['key' => 'types', 'title' => 'By contact type', 'columns' => [wa_adm_col('contact_type', 'Contact type', 'badge'), wa_adm_col('messaged', 'Messaged', 'number'),
                wa_adm_col('replied', 'Replied (72 h)', 'number'), wa_adm_col('reply_rate', 'Reply %', 'percent'), wa_adm_col('active', 'Active contacts', 'number'), wa_adm_col('inbound', 'Inbound messages', 'number')], 'rows' => $out],
            ['key' => 'top', 'title' => 'Most engaged contacts', 'columns' => [wa_adm_col('name', 'Contact', 'link', ['sub' => 'phone', 'link' => '/whatsapp/contacts/{id}']), wa_adm_col('contact_type', 'Type', 'badge'),
                wa_adm_col('program_name', 'Program'), wa_adm_col('inbound', 'Messages sent', 'number'), wa_adm_col('last_at', 'Last message', 'datetime')],
                'rows' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'] ?: '—', 'phone' => wa_adm_phone($r['phone']), 'contact_type' => $r['contact_type'], 'program_name' => $r['program_name'],
                    'inbound' => (int) $r['inbound'], 'last_at' => $r['last_at']], $top)],
        ],
    ];
}

function wa_adm_report_automation(array $range, array $q): array
{
    $args = [$range['start'], $range['end']];
    $wf = db_all("SELECT w.id, w.name, w.status, w.trigger_type, w.trigger_event, COUNT(r.id) AS runs, SUM(r.status = 'completed') AS completed, SUM(r.status = 'failed') AS failed,
                         SUM(r.status IN ('running','waiting')) AS in_progress, SUM(r.status = 'cancelled') AS cancelled
                  FROM wa_workflows w LEFT JOIN wa_workflow_runs r ON r.workflow_id = w.id AND r.is_test = 0 AND r.started_at BETWEEN ? AND ?
                  GROUP BY w.id, w.name, w.status, w.trigger_type, w.trigger_event ORDER BY runs DESC, w.name", $args);
    $wfMsgs = db_pairs('SELECT r.workflow_id, COUNT(*) FROM wa_messages m JOIN wa_workflow_runs r ON r.id = m.workflow_run_id WHERE m.created_at BETWEEN ? AND ? GROUP BY r.workflow_id', $args);
    $ev = db_all("SELECT l.event_code, COALESCE(e.name, l.event_code) AS name, COALESCE(e.category, CASE WHEN l.event_code LIKE 'WA!_%' ESCAPE '!' THEN 'internal' ELSE 'custom' END) AS category, COUNT(*) AS fired,
                         SUM(l.status = 'processed') AS processed, SUM(l.status = 'skipped') AS skipped, SUM(l.status = 'duplicate') AS duplicate, SUM(l.status = 'failed') AS failed
                  FROM wa_event_logs l LEFT JOIN wa_events e ON e.code = l.event_code WHERE l.created_at BETWEEN ? AND ?
                  GROUP BY l.event_code, e.name, e.category ORDER BY fired DESC", $args);
    $evMsgs = db_pairs('SELECT l.event_code, COUNT(*) FROM wa_messages m JOIN wa_event_logs l ON l.id = m.event_log_id WHERE l.created_at BETWEEN ? AND ? GROUP BY l.event_code', $args);
    foreach ($wf as &$r) {
        $r['messages'] = (int) ($wfMsgs[$r['id']] ?? 0);
    }
    unset($r);
    foreach ($ev as &$r) {
        $r['messages'] = (int) ($evMsgs[$r['event_code']] ?? 0);
    }
    unset($r);
    $series = wa_adm_series("SELECT DATE(started_at) AS d, COUNT(*) AS runs, SUM(status = 'completed') AS completed, SUM(status = 'failed') AS failed FROM wa_workflow_runs
                             WHERE is_test = 0 AND started_at BETWEEN ? AND ? GROUP BY DATE(started_at)", $args, $range, ['runs', 'completed', 'failed']);
    $evSeries = wa_adm_series("SELECT DATE(created_at) AS d, COUNT(*) AS fired FROM wa_event_logs WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at)", $args, $range, ['fired']);
    $wfRows = array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'status' => $r['status'], 'trigger' => $r['trigger_event'] ?: $r['trigger_type'], 'runs' => (int) $r['runs'],
        'completed' => (int) $r['completed'], 'failed' => (int) $r['failed'], 'in_progress' => (int) $r['in_progress'], 'cancelled' => (int) $r['cancelled'], 'messages' => (int) $r['messages'],
        'completion_rate' => wa_adm_pct($r['completed'], $r['runs']), 'failure_rate' => wa_adm_pct($r['failed'], $r['runs'])], $wf);
    $evRows = array_map(fn ($r) => ['code' => $r['event_code'], 'name' => $r['name'], 'category' => $r['category'], 'fired' => (int) $r['fired'], 'processed' => (int) $r['processed'],
        'skipped' => (int) $r['skipped'], 'duplicate' => (int) $r['duplicate'], 'failed' => (int) $r['failed'], 'messages' => (int) $r['messages'],
        'success_rate' => wa_adm_pct($r['processed'], $r['fired'])], $ev);
    $runs = array_sum(array_column($wfRows, 'runs'));
    $fired = array_sum(array_column($evRows, 'fired'));
    return [
        'summary' => [
            ['label' => 'Workflow runs', 'value' => $runs, 'format' => 'number', 'tone' => 'navy'],
            ['label' => 'Completion rate', 'value' => wa_adm_pct(array_sum(array_column($wfRows, 'completed')), $runs), 'format' => 'percent', 'tone' => 'green'],
            ['label' => 'Failed runs', 'value' => array_sum(array_column($wfRows, 'failed')), 'format' => 'number', 'tone' => 'red'],
            ['label' => 'Events fired', 'value' => $fired, 'format' => 'number', 'tone' => 'orange'],
            ['label' => 'Event success', 'value' => wa_adm_pct(array_sum(array_column($evRows, 'processed')), $fired), 'format' => 'percent', 'tone' => 'blue'],
            ['label' => 'Messages queued', 'value' => array_sum(array_column($wfRows, 'messages')) + array_sum(array_column($evRows, 'messages')), 'format' => 'number', 'tone' => 'cyan'],
        ],
        'charts' => [
            ['type' => 'bar', 'title' => 'Workflow runs', 'labels' => $range['labels'], 'stacked' => true, 'series' => [
                ['label' => 'Completed', 'data' => $series['completed']], ['label' => 'Failed', 'data' => $series['failed']],
                ['label' => 'Other', 'data' => array_map(fn ($a, $b, $c) => max(0, $a - $b - $c), $series['runs'], $series['completed'], $series['failed'])]]],
            ['type' => 'line', 'title' => 'Events fired', 'labels' => $range['labels'], 'series' => [['label' => 'Events', 'data' => $evSeries['fired'], 'fill' => true]]],
        ],
        'tables' => [
            ['key' => 'workflows', 'title' => 'Workflows', 'columns' => [wa_adm_col('name', 'Workflow', 'link', ['link' => '/whatsapp/automations/{id}']), wa_adm_col('status', 'Status', 'status'), wa_adm_col('trigger', 'Trigger', 'code'),
                wa_adm_col('runs', 'Runs', 'number'), wa_adm_col('completed', 'Completed', 'number'), wa_adm_col('failed', 'Failed', 'number'), wa_adm_col('in_progress', 'In progress', 'number'),
                wa_adm_col('messages', 'Messages', 'number'), wa_adm_col('completion_rate', 'Completion %', 'percent'), wa_adm_col('failure_rate', 'Failure %', 'percent')], 'rows' => $wfRows],
            ['key' => 'events', 'title' => 'Events', 'columns' => [wa_adm_col('name', 'Event'), wa_adm_col('code', 'Code', 'code'), wa_adm_col('category', 'Category', 'badge'), wa_adm_col('fired', 'Fired', 'number'),
                wa_adm_col('processed', 'Processed', 'number'), wa_adm_col('skipped', 'Skipped', 'number'), wa_adm_col('duplicate', 'Duplicates', 'number'), wa_adm_col('failed', 'Failed', 'number'),
                wa_adm_col('messages', 'Messages', 'number'), wa_adm_col('success_rate', 'Success %', 'percent')], 'rows' => $evRows],
        ],
    ];
}

function wa_adm_report_failures(array $range, array $q): array
{
    $args = [$range['start'], $range['end']];
    $runs = db_all("SELECT r.id, r.started_at AS at, r.error, w.name AS source_name, w.id AS workflow_id, c.name AS contact_name, c.phone
                    FROM wa_workflow_runs r JOIN wa_workflows w ON w.id = r.workflow_id LEFT JOIN wa_contacts c ON c.id = r.contact_id
                    WHERE r.status = 'failed' AND r.is_test = 0 AND r.started_at BETWEEN ? AND ? ORDER BY r.id DESC LIMIT 300", $args);
    $events = db_all("SELECT l.id, l.created_at AS at, l.error, COALESCE(e.name, l.event_code) AS source_name, c.name AS contact_name, c.phone
                      FROM wa_event_logs l LEFT JOIN wa_events e ON e.code = l.event_code LEFT JOIN wa_contacts c ON c.id = l.contact_id
                      WHERE l.status = 'failed' AND l.created_at BETWEEN ? AND ? ORDER BY l.id DESC LIMIT 300", $args);
    $msgs = db_all("SELECT m.id, COALESCE(m.failed_at, m.created_at) AS at, m.error, m.error_code, m.sender_type, COALESCE(m.template_name, m.type) AS source_name, c.name AS contact_name, c.phone
                    FROM wa_messages m JOIN wa_contacts c ON c.id = m.contact_id
                    WHERE m.direction = 'out' AND m.status = 'failed' AND m.sender_type IN ('automation','event','followup','campaign','bot','system') AND m.created_at BETWEEN ? AND ?
                    ORDER BY m.id DESC LIMIT 500", $args);
    $rows = [];
    foreach ($runs as $r) {
        $rows[] = ['at' => $r['at'], 'kind' => 'workflow', 'ref' => 'Run #' . $r['id'], 'source' => $r['source_name'], 'contact' => $r['contact_name'] ?: wa_adm_phone($r['phone']), 'error' => $r['error'] ?: 'Failed'];
    }
    foreach ($events as $r) {
        $rows[] = ['at' => $r['at'], 'kind' => 'event', 'ref' => 'Log #' . $r['id'], 'source' => $r['source_name'], 'contact' => $r['contact_name'] ?: wa_adm_phone($r['phone']), 'error' => $r['error'] ?: 'Failed'];
    }
    foreach ($msgs as $r) {
        $rows[] = ['at' => $r['at'], 'kind' => 'message', 'ref' => 'Msg #' . $r['id'] . ' · ' . $r['sender_type'], 'source' => $r['source_name'], 'contact' => $r['contact_name'] ?: wa_adm_phone($r['phone']),
            'error' => trim(($r['error_code'] ? '[' . $r['error_code'] . '] ' : '') . ($r['error'] ?: 'Failed'))];
    }
    usort($rows, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
    $byError = [];
    foreach ($rows as $r) {
        $k = mb_strimwidth(preg_replace('/#\d+/', '#…', (string) $r['error']), 0, 120, '…');
        $byError[$k] = ($byError[$k] ?? ['error' => $k, 'count' => 0, 'kinds' => []]);
        $byError[$k]['count']++;
        $byError[$k]['kinds'][$r['kind']] = true;
        $byError[$k]['last_at'] = max($byError[$k]['last_at'] ?? '', (string) $r['at']);
    }
    $byError = array_values(array_map(fn ($e) => ['error' => $e['error'], 'count' => $e['count'], 'kinds' => implode(', ', array_keys($e['kinds'])), 'last_at' => $e['last_at']], $byError));
    usort($byError, fn ($a, $b) => $b['count'] <=> $a['count']);
    $series = wa_adm_series("SELECT d, SUM(n) AS failed FROM (
            SELECT DATE(started_at) AS d, COUNT(*) AS n FROM wa_workflow_runs WHERE status = 'failed' AND is_test = 0 AND started_at BETWEEN ? AND ? GROUP BY DATE(started_at)
            UNION ALL SELECT DATE(created_at) AS d, COUNT(*) AS n FROM wa_event_logs WHERE status = 'failed' AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)
            UNION ALL SELECT DATE(created_at) AS d, COUNT(*) AS n FROM wa_messages WHERE direction = 'out' AND status = 'failed' AND sender_type IN ('automation','event','followup','campaign','bot','system') AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)
        ) x GROUP BY d", array_merge($args, $args, $args), $range, ['failed']);
    return [
        'summary' => [
            ['label' => 'Total failures', 'value' => count($rows), 'format' => 'number', 'tone' => 'red'],
            ['label' => 'Failed workflow runs', 'value' => count($runs), 'format' => 'number', 'tone' => 'orange'],
            ['label' => 'Failed events', 'value' => count($events), 'format' => 'number', 'tone' => 'amber'],
            ['label' => 'Failed automated messages', 'value' => count($msgs), 'format' => 'number', 'tone' => 'pink'],
            ['label' => 'Distinct errors', 'value' => count($byError), 'format' => 'number', 'tone' => 'slate'],
        ],
        'charts' => [
            ['type' => 'bar', 'title' => 'Failures over time', 'labels' => $range['labels'], 'series' => [['label' => 'Failures', 'data' => $series['failed'], 'color' => '#EF4444']]],
            ['type' => 'doughnut', 'title' => 'Failures by type', 'labels' => ['Workflow runs', 'Events', 'Messages'], 'data' => [count($runs), count($events), count($msgs)], 'colors' => ['#F97316', '#F59E0B', '#EF4444']],
        ],
        'tables' => [
            ['key' => 'errors', 'title' => 'Top errors', 'columns' => [wa_adm_col('error', 'Error', 'truncate'), wa_adm_col('count', 'Occurrences', 'number'), wa_adm_col('kinds', 'Where'), wa_adm_col('last_at', 'Last seen', 'datetime')], 'rows' => array_slice($byError, 0, 100)],
            ['key' => 'failures', 'title' => 'All failures', 'columns' => [wa_adm_col('at', 'Time', 'datetime'), wa_adm_col('kind', 'Type', 'badge'), wa_adm_col('ref', 'Reference', 'code'), wa_adm_col('source', 'Workflow / event / template'),
                wa_adm_col('contact', 'Contact'), wa_adm_col('error', 'Error', 'truncate')], 'rows' => array_slice($rows, 0, 500)],
        ],
    ];
}

function wa_adm_report_courses(array $range, array $q): array
{
    $args = [$range['start'], $range['end']];
    $enq = db_all("SELECT COALESCE(p.name, NULLIF(e.program_interest, ''), 'Not specified') AS program, COUNT(*) AS enquiries, SUM(e.source = 'whatsapp') AS via_whatsapp,
                          SUM(e.status = 'new') AS new_count, SUM(e.status IN ('contacted','interested')) AS in_progress, SUM(e.status = 'converted') AS converted,
                          SUM(e.status IN ('not_interested','closed')) AS closed
                   FROM enquiries e LEFT JOIN programs p ON p.id = e.program_id WHERE e.created_at BETWEEN ? AND ?
                   GROUP BY COALESCE(p.name, NULLIF(e.program_interest, ''), 'Not specified') ORDER BY enquiries DESC", $args);
    $wa = db_all("SELECT COALESCE(p.name, c.program_name, 'Not specified') AS program, COUNT(*) AS leads, SUM(c.stage = 'admission_confirmed') AS admitted,
                         SUM(c.last_inbound_at IS NOT NULL) AS engaged
                  FROM wa_contacts c LEFT JOIN programs p ON p.id = c.program_id WHERE c.created_at BETWEEN ? AND ? AND " . wa_adm_pipeline_sql('c') . "
                  GROUP BY COALESCE(p.name, c.program_name, 'Not specified')", $args);
    $map = [];
    foreach ($enq as $r) {
        $map[$r['program']] = ['program' => $r['program'], 'enquiries' => (int) $r['enquiries'], 'via_whatsapp' => (int) $r['via_whatsapp'], 'new' => (int) $r['new_count'],
            'in_progress' => (int) $r['in_progress'], 'converted' => (int) $r['converted'], 'closed' => (int) $r['closed'], 'wa_leads' => 0, 'wa_engaged' => 0, 'wa_admitted' => 0];
    }
    foreach ($wa as $r) {
        $map[$r['program']] = ($map[$r['program']] ?? ['program' => $r['program'], 'enquiries' => 0, 'via_whatsapp' => 0, 'new' => 0, 'in_progress' => 0, 'converted' => 0, 'closed' => 0])
            + ['wa_leads' => 0, 'wa_engaged' => 0, 'wa_admitted' => 0];
        $map[$r['program']]['wa_leads'] = (int) $r['leads'];
        $map[$r['program']]['wa_engaged'] = (int) $r['engaged'];
        $map[$r['program']]['wa_admitted'] = (int) $r['admitted'];
    }
    $rows = array_values($map);
    foreach ($rows as &$r) {
        $r['conversion_rate'] = wa_adm_pct($r['converted'], $r['enquiries']);
    }
    unset($r);
    usort($rows, fn ($a, $b) => [$b['enquiries'], $b['wa_leads']] <=> [$a['enquiries'], $a['wa_leads']]);
    $top = array_slice($rows, 0, 10);
    return [
        'summary' => [
            ['label' => 'Enquiries', 'value' => array_sum(array_column($rows, 'enquiries')), 'format' => 'number', 'tone' => 'navy'],
            ['label' => 'Via WhatsApp', 'value' => array_sum(array_column($rows, 'via_whatsapp')), 'format' => 'number', 'tone' => 'green'],
            ['label' => 'Converted', 'value' => array_sum(array_column($rows, 'converted')), 'format' => 'number', 'tone' => 'orange'],
            ['label' => 'WhatsApp leads', 'value' => array_sum(array_column($rows, 'wa_leads')), 'format' => 'number', 'tone' => 'blue'],
            ['label' => 'Programs', 'value' => count(array_filter($rows, fn ($r) => $r['program'] !== 'Not specified')), 'format' => 'number', 'tone' => 'purple'],
        ],
        'charts' => [
            ['type' => 'bar', 'title' => 'Enquiries by program', 'horizontal' => true, 'labels' => array_map(fn ($r) => mb_strimwidth((string) $r['program'], 0, 30, '…'), $top), 'stacked' => true, 'series' => [
                ['label' => 'Converted', 'data' => array_map(fn ($r) => $r['converted'], $top)], ['label' => 'In progress', 'data' => array_map(fn ($r) => $r['in_progress'] + $r['new'], $top)],
                ['label' => 'Closed', 'data' => array_map(fn ($r) => $r['closed'], $top)]]],
        ],
        'tables' => [['key' => 'courses', 'title' => 'Programs', 'columns' => [
            wa_adm_col('program', 'Program'), wa_adm_col('enquiries', 'Enquiries', 'number'), wa_adm_col('via_whatsapp', 'Via WhatsApp', 'number'), wa_adm_col('new', 'New', 'number'),
            wa_adm_col('in_progress', 'In progress', 'number'), wa_adm_col('converted', 'Converted', 'number'), wa_adm_col('closed', 'Closed', 'number'), wa_adm_col('conversion_rate', 'Conversion %', 'percent'),
            wa_adm_col('wa_leads', 'WhatsApp leads', 'number'), wa_adm_col('wa_engaged', 'Replied on WhatsApp', 'number'), wa_adm_col('wa_admitted', 'Admitted (WhatsApp)', 'number')], 'rows' => $rows]],
    ];
}

/** Flatten a report table for CSV/XLSX: header labels + scalar rows. */
function wa_adm_report_export_rows(array $table): array
{
    $headers = array_map(fn ($c) => $c['label'] . ($c['format'] === 'percent' && !str_contains($c['label'], '%') ? ' (%)' : ''), $table['columns']);
    $rows = [];
    foreach ($table['rows'] as $r) {
        $line = [];
        foreach ($table['columns'] as $c) {
            $v = $r[$c['key']] ?? '';
            $line[] = is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? 'Yes' : 'No') : (string) ($v ?? ''));
        }
        $rows[] = $line;
    }
    return [$headers, $rows];
}

/** CSV download (UTF-8 BOM, formula-safe). fputcsv gets every argument explicitly — PHP 8.4 deprecates the default escape. */
function wa_adm_csv_download(string $filename, array $headers, array $rows): void
{
    download_headers($filename, 'text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_safe', array_values($row)), ',', '"', '');
    }
    fclose($out);
    exit;
}

/* =================================================================================================================
 * Settings (Module 14) + Integration (Module 11)
 * ================================================================================================================= */

/** Business hours for the editor: day => {open, close, closed}. */
function wa_adm_hours_for_ui(): array
{
    $out = [];
    foreach (wa_business_hours() as $d => $h) {
        $out[$d] = ['open' => $h[0] ?? '09:00', 'close' => $h[1] ?? '18:00', 'closed' => $h === null];
    }
    return $out;
}

/** Last 4 characters of a secret (never the secret itself). */
function wa_adm_last4(string $key): ?string
{
    $v = trim((string) wa_setting($key, ''));
    return $v === '' ? null : substr($v, -4);
}

/** Every setting section for the Settings page (no secrets). */
function wa_adm_settings_get(): array
{
    $logo = (string) (setting('logo') ?: '');
    $g = fn ($k) => wa_adm_setting($k);
    return [
        'profile' => [
            'wa_business_name' => (string) $g('wa_business_name'),
            'wa_business_number' => (string) $g('wa_business_number'),
            'wa_default_language' => (string) ($g('wa_default_language') ?: 'en'),
            'wa_brochure_url' => (string) ($g('wa_brochure_url') ?? ''),
            'institute_name' => function_exists('institute_name') ? institute_name() : 'GIMT',
            'logo_url' => $logo !== '' ? (preg_match('#^https?://#', $logo) ? $logo : absolute_url(ltrim($logo, '/'))) : absolute_url('assets/images/logo.svg'),
        ],
        'hours' => ['wa_business_hours' => wa_adm_hours_for_ui(), 'timezone' => date_default_timezone_get(), 'in_business_hours' => wa_in_business_hours()],
        'notifications' => [
            'wa_notify_new_message' => $g('wa_notify_new_message') === '1', 'wa_notify_assignment' => $g('wa_notify_assignment') === '1',
            'wa_notify_handover' => $g('wa_notify_handover') === '1', 'wa_daily_digest' => $g('wa_daily_digest') === '1', 'wa_digest_time' => (string) $g('wa_digest_time'),
            'last_digest_at' => setting('_wa_adm_last_digest') ?: null,
        ],
        'automation' => [
            'wa_automation_paused' => $g('wa_automation_paused') === '1', 'wa_quiet_enabled' => $g('wa_quiet_enabled') === '1',
            'wa_quiet_start' => (string) $g('wa_quiet_start'), 'wa_quiet_end' => (string) $g('wa_quiet_end'), 'wa_max_per_contact_day' => (int) $g('wa_max_per_contact_day'),
            'wa_max_attempts' => (int) $g('wa_max_attempts'), 'wa_auto_sync_contacts' => $g('wa_auto_sync_contacts') === '1',
            'wa_optout_reply' => (string) $g('wa_optout_reply'), 'wa_optin_reply' => (string) $g('wa_optin_reply'),
        ],
        'webhook' => [
            'secret_set' => wa_adm_last4('wa_webhook_secret') !== null, 'secret_last4' => wa_adm_last4('wa_webhook_secret'),
            'wa_allow_simulate_live' => $g('wa_allow_simulate_live') === '1', 'webhook_url' => absolute_url('api/whatsapp/webhook'),
        ],
        'retention' => ['wa_retention_days' => (int) $g('wa_retention_days'), 'last_purge_at' => setting('_wa_last_purge') ?: null],
        'security' => ['wa_export_scope' => (string) $g('wa_export_scope'), 'wa_mask_phones' => $g('wa_mask_phones') === '1'],
    ];
}

function wa_adm_bool($v): string
{
    return ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on') ? '1' : '0';
}

/**
 * A link admins may put into messages: http(s)://host/… (valid URL, no whitespace) or a path on this website starting
 * with a single "/" (no protocol-relative //host). At most 500 characters.
 */
function wa_adm_valid_link(string $v): bool
{
    if ($v === '' || mb_strlen($v) > 500 || preg_match('/[\s<>"\\\\\x00-\x1F\x7F]/u', $v)) {
        return false;
    }
    if ($v[0] === '/') {
        return !isset($v[1]) || $v[1] !== '/';
    }
    if (!preg_match('#^https?://#i', $v) || filter_var($v, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $host = (string) parse_url($v, PHP_URL_HOST);
    return ($host !== '' && str_contains($host, '.')) || strcasecmp($host, 'localhost') === 0;
}

/** Validate + save one settings section. Returns [changed keys list]. Throws CrudException-like api errors via $errors. */
function wa_adm_settings_save(string $section, array $in): array
{
    $errors = [];
    $save = [];
    $hhmm = fn ($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v);
    switch ($section) {
        case 'profile':
            $name = trim(strip_tags((string) ($in['wa_business_name'] ?? '')));
            if ($name === '' || mb_strlen($name) > 120) {
                $errors['wa_business_name'] = 'Enter the business name shown to students (up to 120 characters).';
            }
            $num = trim((string) ($in['wa_business_number'] ?? ''));
            $norm = $num !== '' ? wa_normalize_phone($num) : null;
            if ($num !== '' && !$norm) {
                $errors['wa_business_number'] = 'Enter the WhatsApp business number with country code, e.g. +91 98765 43210.';
            }
            $lang = (string) ($in['wa_default_language'] ?? 'en');
            if (!in_array($lang, ['en', 'hi'], true)) {
                $errors['wa_default_language'] = 'Choose English or Hindi.';
            }
            $save = ['wa_business_name' => $name, 'wa_business_number' => $norm['phone'] ?? '', 'wa_default_language' => $lang];
            // optional institute brochure for {{brochure_link}} (core wa_brochure_link): a full http(s) link or a site path
            // such as /uploads/brochure.pdf. A client that does not send the key keeps the saved value.
            if (array_key_exists('wa_brochure_url', $in)) {
                $brochure = is_scalar($in['wa_brochure_url']) ? trim((string) $in['wa_brochure_url']) : '';
                if ($brochure !== '' && !wa_adm_valid_link($brochure)) {
                    $errors['wa_brochure_url'] = mb_strlen($brochure) > 500
                        ? 'Keep the brochure link under 500 characters.'
                        : 'Enter a full link starting with https:// (or http://), or a path on this website starting with /, e.g. /uploads/brochure.pdf.';
                }
                $save['wa_brochure_url'] = $brochure;
            }
            break;
        case 'hours':
            $hours = is_array($in['wa_business_hours'] ?? null) ? $in['wa_business_hours'] : [];
            $out = [];
            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
                $h = $hours[$d] ?? null;
                if (!is_array($h) || !empty($h['closed'])) {
                    $out[$d] = null;
                    continue;
                }
                $o = (string) ($h['open'] ?? '');
                $c = (string) ($h['close'] ?? '');
                if (!$hhmm($o) || !$hhmm($c)) {
                    $errors['wa_business_hours.' . $d] = 'Enter opening and closing times (HH:MM).';
                } elseif ($o >= $c) {
                    $errors['wa_business_hours.' . $d] = 'Closing time must be after opening time.';
                }
                $out[$d] = [$o, $c];
            }
            if (!$errors && !array_filter($out)) {
                $errors['wa_business_hours'] = 'Keep at least one working day open.';
            }
            $save = ['wa_business_hours' => (string) json_encode($out)];
            break;
        case 'notifications':
            foreach (['wa_notify_new_message', 'wa_notify_assignment', 'wa_notify_handover', 'wa_daily_digest'] as $k) {
                $save[$k] = wa_adm_bool($in[$k] ?? false);
            }
            $t = (string) ($in['wa_digest_time'] ?? '09:00');
            if (!$hhmm($t)) {
                $errors['wa_digest_time'] = 'Enter a time (HH:MM).';
            }
            $save['wa_digest_time'] = $t;
            break;
        case 'automation':
            $save['wa_automation_paused'] = wa_adm_bool($in['wa_automation_paused'] ?? false);
            $save['wa_quiet_enabled'] = wa_adm_bool($in['wa_quiet_enabled'] ?? false);
            $save['wa_auto_sync_contacts'] = wa_adm_bool($in['wa_auto_sync_contacts'] ?? false);
            foreach (['wa_quiet_start', 'wa_quiet_end'] as $k) {
                $v = (string) ($in[$k] ?? '');
                if (!$hhmm($v)) {
                    $errors[$k] = 'Enter a time (HH:MM).';
                }
                $save[$k] = $v;
            }
            if ($save['wa_quiet_enabled'] === '1' && !$errors && $save['wa_quiet_start'] === $save['wa_quiet_end']) {
                $errors['wa_quiet_end'] = 'Quiet hours must start and end at different times.';
            }
            $max = $in['wa_max_per_contact_day'] ?? 0;
            if (!is_numeric($max) || (int) $max < 0 || (int) $max > 50) {
                $errors['wa_max_per_contact_day'] = 'Enter 0 (no limit) to 50.';
            }
            $save['wa_max_per_contact_day'] = (string) (int) $max;
            $att = $in['wa_max_attempts'] ?? 5;
            if (!is_numeric($att) || (int) $att < 1 || (int) $att > 10) {
                $errors['wa_max_attempts'] = 'Enter 1 to 10 attempts.';
            }
            $save['wa_max_attempts'] = (string) (int) $att;
            foreach (['wa_optout_reply' => 'opt-out', 'wa_optin_reply' => 'opt-in'] as $k => $lbl) {
                $v = trim(strip_tags((string) ($in[$k] ?? '')));
                if ($v === '' || mb_strlen($v) > 500) {
                    $errors[$k] = 'Enter the ' . $lbl . ' confirmation message (up to 500 characters).';
                }
                $save[$k] = $v;
            }
            break;
        case 'webhook':
            $save['wa_allow_simulate_live'] = wa_adm_bool($in['wa_allow_simulate_live'] ?? false);
            break;
        case 'retention':
            $d = $in['wa_retention_days'] ?? 90;
            if (!is_numeric($d) || (int) $d < 7 || (int) $d > 3650) {
                $errors['wa_retention_days'] = 'Keep logs for 7 to 3650 days.';
            }
            $save['wa_retention_days'] = (string) (int) $d;
            break;
        case 'security':
            $scope = (string) ($in['wa_export_scope'] ?? 'permission');
            if (!in_array($scope, ['permission', 'managers'], true)) {
                $errors['wa_export_scope'] = 'Choose who can export.';
            }
            $save['wa_export_scope'] = $scope;
            $save['wa_mask_phones'] = wa_adm_bool($in['wa_mask_phones'] ?? false);
            break;
        default:
            throw new InvalidArgumentException('Unknown settings section.');
    }
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $changed = [];
    foreach ($save as $k => $v) {
        $old = (string) (wa_adm_setting($k) ?? '');
        if ($old !== (string) $v) {
            $changed[] = $k;
        }
        wa_adm_save_setting($k, $v);
    }
    return $changed;
}

/** Interakt event types to tick in Developer Settings (+ whether GIMT received one in the last 30 days). */
function wa_adm_webhook_checklist(): array
{
    $seen = db_pairs("SELECT event_type, MAX(received_at) FROM wa_webhook_logs WHERE received_at >= ? GROUP BY event_type", [date('Y-m-d H:i:s', time() - 30 * 86400)]);
    $list = [
        ['type' => 'message_api_sent', 'label' => 'Message sent (API)', 'required' => true, 'note' => 'Single tick for template / session messages sent by GIMT.'],
        ['type' => 'message_api_delivered', 'label' => 'Message delivered (API)', 'required' => true, 'note' => 'Double tick + Meta cost.'],
        ['type' => 'message_api_read', 'label' => 'Message read (API)', 'required' => true, 'note' => 'Blue tick — read rates in reports.'],
        ['type' => 'message_api_failed', 'label' => 'Message failed (API)', 'required' => true, 'note' => 'Failure reason → retry queue and error monitoring.'],
        ['type' => 'message_api_clicked', 'label' => 'Button clicked (API)', 'required' => true, 'note' => 'Quick-reply and CTA clicks start workflows.'],
        ['type' => 'message_received', 'label' => 'Message received', 'required' => true, 'note' => 'Incoming chats for the inbox and AI bot (Advanced plan or higher).', 'plan' => 'advanced'],
        ['type' => 'message_template_status_update', 'label' => 'Template status update', 'required' => true, 'note' => 'Approval / rejection of templates.'],
        ['type' => 'message_campaign_sent', 'label' => 'Campaign message events', 'required' => false, 'note' => 'Only for campaigns sent from the Interakt dashboard (sent / delivered / read / failed).'],
    ];
    foreach ($list as &$i) {
        $i['last_received_at'] = $seen[$i['type']] ?? null;
    }
    unset($i);
    return $list;
}

/** Integrations page payload (no secrets). */
function wa_adm_integration_info(): array
{
    $tpl = db_pairs('SELECT status, COUNT(*) FROM wa_templates GROUP BY status');
    $info = wa_adm_connection() + [
        'base_url' => wa_interakt_base_url(),
        'key_set' => wa_is_configured(),
        'key_last4' => wa_adm_last4('wa_interakt_api_key'),
        'key_saved_at' => setting('_wa_adm_key_saved_at') ?: null,
        'secret_set' => wa_adm_last4('wa_webhook_secret') !== null,
        'secret_last4' => wa_adm_last4('wa_webhook_secret'),
        'webhook_url' => absolute_url('api/whatsapp/webhook'),
        'webhook_https' => str_starts_with(absolute_url('api/whatsapp/webhook'), 'https://'),
        'checklist' => wa_adm_webhook_checklist(),
        'templates' => ['approved' => (int) ($tpl['approved'] ?? 0), 'pending' => (int) ($tpl['pending'] ?? 0), 'rejected' => (int) ($tpl['rejected'] ?? 0),
            'draft' => (int) ($tpl['draft'] ?? 0), 'last_sync_at' => setting('_wa_tpl_last_sync') ?: null],
        'plans' => [
            ['value' => 'growth', 'label' => 'Growth', 'rate' => 250, 'limit' => 300, 'incoming' => false],
            ['value' => 'advanced', 'label' => 'Advanced', 'rate' => 550, 'limit' => 600, 'incoming' => true],
            ['value' => 'enterprise', 'label' => 'Enterprise', 'rate' => 550, 'limit' => null, 'incoming' => true],
        ],
        'allow_simulate_live' => wa_setting('wa_allow_simulate_live', '0') === '1',
    ];
    if (wa_can('whatsapp_settings', 'manage')) {
        $info['cron'] = wa_cron_info();
    }
    return $info;
}

/** Save the Interakt connection (API key only when a new one is typed). Returns change notes for the activity log. */
function wa_adm_integration_save(array $in): array
{
    $errors = [];
    $notes = [];
    $key = trim((string) ($in['api_key'] ?? ''));
    if ($key !== '') {
        if (strlen($key) < 16 || strlen($key) > 400 || preg_match('/\s/', $key)) {
            $errors['api_key'] = 'Paste the API key exactly as shown in Interakt › Developer Settings (no spaces).';
        }
    }
    $base = trim((string) ($in['base_url'] ?? wa_interakt_base_url()));
    // The saved API key travels in the Authorization header to this host, so only Interakt's own domain is allowed
    // (otherwise a settings manager could point the base URL at any server and collect the key on "Test connection").
    $host = strtolower((string) parse_url($base, PHP_URL_HOST));
    if (!preg_match('#^https://[a-z0-9.-]+(/[A-Za-z0-9._~/-]*)?$#i', $base)) {
        $errors['base_url'] = 'The API base URL must be an https:// address without a port, e.g. https://api.interakt.ai/v1/public/.';
    } elseif ($host !== 'interakt.ai' && !str_ends_with($host, '.interakt.ai')) {
        $errors['base_url'] = 'The API base URL must be on interakt.ai (default https://api.interakt.ai/v1/public/).';
    }
    $plan = (string) ($in['plan'] ?? wa_setting('wa_interakt_plan', 'growth'));
    if (!in_array($plan, ['growth', 'advanced', 'enterprise'], true)) {
        $errors['plan'] = 'Choose your Interakt plan.';
    }
    $rate = $in['rate_per_minute'] ?? null;
    if ($rate === null || $rate === '') {
        $rate = $plan === 'growth' ? 250 : 550;
    }
    if (!is_numeric($rate) || (int) $rate < 1 || (int) $rate > 1000) {
        $errors['rate_per_minute'] = 'Enter 1 to 1000 messages per minute.';
    } elseif ($plan === 'growth' && (int) $rate > 300) {
        $errors['rate_per_minute'] = 'The Growth plan allows at most 300 requests per minute — use 250 to keep headroom.';
    } elseif ($plan === 'advanced' && (int) $rate > 600) {
        $errors['rate_per_minute'] = 'The Advanced plan allows at most 600 requests per minute — use 550 to keep headroom.';
    }
    $name = trim(strip_tags((string) ($in['business_name'] ?? wa_setting('wa_business_name', 'GIMT'))));
    if ($name === '' || mb_strlen($name) > 120) {
        $errors['business_name'] = 'Enter the WhatsApp display name (up to 120 characters).';
    }
    $num = trim((string) ($in['business_number'] ?? ''));
    $norm = $num !== '' ? wa_normalize_phone($num) : null;
    if ($num !== '' && !$norm) {
        $errors['business_number'] = 'Enter the WhatsApp business number with country code.';
    }
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    if ($key !== '') {
        wa_adm_save_setting('wa_interakt_api_key', $key);
        save_setting('_wa_adm_key_saved_at', wa_now(), 'internal');
        save_setting('_wa_last_connection_test', '', 'internal'); // the previous test was about the old key
        $notes[] = 'API key updated (…' . substr($key, -4) . ')';
    }
    $pairs = ['wa_interakt_base_url' => rtrim($base, '/') . '/', 'wa_interakt_plan' => $plan, 'wa_rate_per_minute' => (string) (int) $rate,
        'wa_business_name' => $name, 'wa_business_number' => $norm['phone'] ?? ''];
    foreach ($pairs as $k => $v) {
        if ((string) wa_setting($k, '') !== $v) {
            $notes[] = str_replace(['wa_interakt_', 'wa_'], '', $k) . ' → ' . ($v === '' ? '(empty)' : $v);
        }
        wa_adm_save_setting($k, $v);
    }
    return $notes;
}

/**
 * Test the Interakt connection (list approved templates). Interakt is contacted only in live mode — in test mode nothing
 * leaves the server and the result says so honestly ('skipped' => true, ok false, no fake success). Saves the result in
 * the internal setting _wa_last_connection_test.
 */
function wa_adm_test_connection(): array
{
    if (!wa_is_configured()) {
        $r = ['ok' => false, 'skipped' => false, 'status' => 0, 'error' => 'No Interakt API key is saved yet.', 'duration_ms' => 0, 'templates' => null];
    } elseif (wa_mode() !== 'live') {
        $why = wa_setting('wa_mode', 'test') === 'live' && function_exists('is_dev') && is_dev()
            ? 'this is a development install, so WhatsApp stays in test mode'
            : 'WhatsApp is in test mode';
        $r = ['ok' => false, 'skipped' => true, 'status' => 0, 'duration_ms' => 0, 'templates' => null,
            'error' => ucfirst($why) . ', so no request was sent to Interakt. The saved key (…' . wa_adm_last4('wa_interakt_api_key') . ') is verified when you test again in live mode.'];
    } else {
        $res = wa_interakt_request('GET', 'track/organization/templates', null, ['query' => ['offset' => 0, 'autosubmitted_for' => 'all', 'language' => 'all', 'approval_status' => 'APPROVED'], 'timeout' => 15]);
        $count = $res['ok'] ? ($res['data']['count'] ?? (is_array($res['data']['results']['templates'] ?? null) ? count($res['data']['results']['templates']) : null)) : null;
        $err = $res['error'];
        if (!$res['ok'] && in_array((int) $res['status'], [401, 403], true)) {
            $err = 'Interakt rejected the API key (HTTP ' . $res['status'] . '). Copy it again from Developer Settings.';
        }
        $r = ['ok' => (bool) $res['ok'], 'skipped' => false, 'status' => (int) $res['status'], 'error' => $err !== null ? mb_substr((string) $err, 0, 300) : null,
            'duration_ms' => (int) $res['duration_ms'], 'templates' => $count !== null ? (int) $count : null];
    }
    $r['at'] = wa_now();
    $r['mode'] = wa_mode();
    save_setting('_wa_last_connection_test', (string) wa_json($r), 'internal');
    return $r;
}

/**
 * Store the webhook secret (encrypted). The secret is typed or generated in the admin's browser and pasted into
 * Interakt from there — the server never sends a secret back (only "set" + the last 4 characters).
 */
function wa_adm_rotate_webhook_secret(string $secret): void
{
    wa_adm_save_setting('wa_webhook_secret', $secret);
    save_setting('_wa_adm_secret_rotated_at', wa_now(), 'internal');
}

/** A signed test webhook through receive + process (stored as simulated). */
function wa_adm_test_webhook(): array
{
    $payload = ['version' => '1.0', 'timestamp' => wa_utc_stamp(), 'type' => 'gimt_webhook_test',
        'data' => ['note' => 'GIMT connection test — safe to ignore', 'sent_by' => wa_user_id(), 'at' => wa_now()]];
    $r = wa_simulate_webhook($payload);
    $log = $r['webhook_log_id'] ? db_row('SELECT id, event_type, signature_valid, status, error, received_at FROM wa_webhook_logs WHERE id = ?', [(int) $r['webhook_log_id']]) : null;
    return ['webhook_log_id' => $r['webhook_log_id'], 'signature_valid' => $log ? ($log['signature_valid'] === null ? null : (int) $log['signature_valid'] === 1) : null,
        'stored' => (bool) $log, 'status' => $log['status'] ?? $r['status'], 'note' => $log['error'] ?? null];
}

/* =================================================================================================================
 * Logs: webhooks, API calls, errors, retry queue, message tracking
 * ================================================================================================================= */

/** Common date filter for logs. */
function wa_adm_log_dates(array $q, string $col, array &$where, array &$args): void
{
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        $v = (string) ($q[$k] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $where[] = "$col $op ?";
            $args[] = $v . ($k === 'from' ? ' 00:00:00' : ' 23:59:59');
        }
    }
}

function wa_adm_webhook_logs(array $q, int $page, int $perPage): array
{
    $where = ['1 = 1'];
    $args = [];
    if (!empty($q['type'])) {
        $where[] = 'event_type = ?';
        $args[] = (string) $q['type'];
    }
    if (!empty($q['status']) && in_array($q['status'], ['received', 'processed', 'ignored', 'failed'], true)) {
        $where[] = 'status = ?';
        $args[] = $q['status'];
    }
    if (isset($q['simulated']) && $q['simulated'] !== '') {
        $where[] = 'is_simulated = ?';
        $args[] = $q['simulated'] === '1' ? 1 : 0;
    }
    if (!empty($q['q'])) {
        $where[] = '(interakt_message_id LIKE ? OR error LIKE ?)';
        $args[] = '%' . $q['q'] . '%';
        $args[] = '%' . $q['q'] . '%';
    }
    wa_adm_log_dates($q, 'received_at', $where, $args);
    $w = implode(' AND ', $where);
    $total = (int) db_value("SELECT COUNT(*) FROM wa_webhook_logs WHERE $w", $args);
    // the reprocess check needs the stored payload (JSON + signature re-verification); Interakt webhooks are a few KB,
    // so payloads above 256 KB are not loaded for the list — their drawer decides (can_reprocess null here)
    $rows = db_all("SELECT id, event_type, signature_valid, interakt_message_id, status, error, is_simulated, headers, received_at, processed_at, LENGTH(payload) AS size,
                           CASE WHEN LENGTH(payload) <= 262144 THEN payload END AS payload
                    FROM wa_webhook_logs WHERE $w ORDER BY id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage), $args);
    foreach ($rows as &$r) {
        $check = $r['payload'] !== null || (int) $r['size'] === 0 ? wa_adm_webhook_reprocess_check($r) : null;
        unset($r['headers'], $r['payload']);
        $r['id'] = (int) $r['id'];
        $r['signature_valid'] = $r['signature_valid'] === null ? null : (int) $r['signature_valid'] === 1;
        $r['is_simulated'] = (int) $r['is_simulated'] === 1;
        $r['size'] = (int) $r['size'];
        $r['can_reprocess'] = $check ? $check['ok'] : null;
        $r['reprocess_reason'] = $check ? ($check['reason'] !== '' ? $check['reason'] : null) : 'Large payload — open the webhook to check whether it can be reprocessed.';
    }
    unset($r);
    $since = date('Y-m-d H:i:s', time() - 86400);
    return ['rows' => $rows, 'pagination' => paginate($total, $page, $perPage),
        'types' => db_column('SELECT DISTINCT event_type FROM wa_webhook_logs WHERE event_type IS NOT NULL ORDER BY event_type'),
        'counts' => array_map('intval', db_pairs("SELECT status, COUNT(*) FROM wa_webhook_logs WHERE received_at >= ? GROUP BY status", [$since]))];
}

function wa_adm_webhook_log(int $id): ?array
{
    $r = db_row('SELECT * FROM wa_webhook_logs WHERE id = ?', [$id]);
    if (!$r) {
        return null;
    }
    $payload = json_decode((string) $r['payload'], true);
    $msg = null;
    if (!empty($r['interakt_message_id'])) {
        $msg = db_row('SELECT id, conversation_id, status, type, template_name FROM wa_messages WHERE interakt_id = ?', [$r['interakt_message_id']]);
    }
    $check = wa_adm_webhook_reprocess_check($r);
    return ['id' => (int) $r['id'], 'event_type' => $r['event_type'], 'status' => $r['status'], 'error' => $r['error'],
        'can_reprocess' => $check['ok'], 'reprocess_reason' => $check['reason'] !== '' ? $check['reason'] : null,
        'signature_valid' => $r['signature_valid'] === null ? null : (int) $r['signature_valid'] === 1, 'is_simulated' => (int) $r['is_simulated'] === 1,
        'received_at' => $r['received_at'], 'processed_at' => $r['processed_at'], 'interakt_message_id' => $r['interakt_message_id'],
        'headers' => wa_json_decode($r['headers'], []), 'payload' => is_array($payload) ? $payload : (string) $r['payload'], 'message' => $msg];
}

/**
 * May this stored webhook be re-run? Only rows whose origin is proven: a valid Interakt signature, or GIMT's own
 * simulator. Unsigned rows (no secret saved when they arrived) and invalid signatures may be forged — replaying them
 * would apply forged STOP / START keywords, inbound messages or template status changes. Core
 * (wa_webhook_log_can_reprocess — needs the full row incl. payload + headers) owns the rule when present; this
 * fallback applies the same minimum. `reason` explains the decision (also when ok).
 * @return array{ok:bool, reason:string}
 */
function wa_adm_webhook_reprocess_check(array $row): array
{
    if (function_exists('wa_webhook_log_can_reprocess')) {
        $r = wa_webhook_log_can_reprocess($row);
        $ok = is_array($r) && !empty($r['ok']);
        $reason = is_array($r) && isset($r['reason']) && is_string($r['reason']) ? $r['reason'] : '';
        return ['ok' => $ok, 'reason' => $ok ? $reason : ($reason !== '' ? $reason : 'This webhook cannot be reprocessed.')];
    }
    if (!empty($row['is_simulated'])) {
        return ['ok' => true, 'reason' => 'Simulated by the GIMT test tools.'];
    }
    $sig = $row['signature_valid'] ?? null;
    if ($sig === true || ($sig !== null && $sig !== false && (int) $sig === 1)) {
        return ['ok' => true, 'reason' => 'The Interakt signature was verified when this webhook arrived.'];
    }
    if ($sig === false || ($sig !== null && (int) $sig === 0)) {
        return ['ok' => false, 'reason' => 'Failed the signature check — it may be forged, so it cannot be reprocessed.'];
    }
    return ['ok' => false, 'reason' => 'Unsigned (no webhook secret was saved when it arrived) — its origin cannot be verified, so it cannot be reprocessed.'];
}

/** Re-run a stored webhook — only rows wa_adm_webhook_reprocess_check() allows (signed by Interakt or simulated). */
function wa_adm_webhook_reprocess(int $id): array
{
    $r = db_row('SELECT * FROM wa_webhook_logs WHERE id = ?', [$id]);
    if (!$r) {
        api_error('Webhook log not found.', 404);
    }
    $check = wa_adm_webhook_reprocess_check($r);
    if (!$check['ok']) {
        api_error($check['reason'], 409);
    }
    db_update('wa_webhook_logs', ['status' => 'received', 'error' => null, 'processed_at' => null], 'id = ?', [$id]);
    $status = wa_process_webhook_log($id);
    $row = db_row('SELECT status, error FROM wa_webhook_logs WHERE id = ?', [$id]);
    return ['status' => $status, 'note' => $row['error'] ?? null];
}

function wa_adm_api_logs(array $q, int $page, int $perPage): array
{
    $where = ['1 = 1'];
    $args = [];
    if (isset($q['ok']) && $q['ok'] !== '') {
        $where[] = 'ok = ?';
        $args[] = $q['ok'] === '1' ? 1 : 0;
    }
    if (!empty($q['mode']) && in_array($q['mode'], ['live', 'test'], true)) {
        $where[] = 'mode = ?';
        $args[] = $q['mode'];
    }
    if (!empty($q['endpoint'])) {
        $where[] = 'endpoint LIKE ?';
        $args[] = '%' . $q['endpoint'] . '%';
    }
    if (!empty($q['message_id']) && ctype_digit((string) $q['message_id'])) {
        $where[] = 'message_id = ?';
        $args[] = (int) $q['message_id'];
    }
    wa_adm_log_dates($q, 'created_at', $where, $args);
    $w = implode(' AND ', $where);
    $total = (int) db_value("SELECT COUNT(*) FROM wa_api_logs WHERE $w", $args);
    $rows = db_all("SELECT id, endpoint, method, http_status, ok, duration_ms, message_id, mode, error, created_at FROM wa_api_logs WHERE $w ORDER BY id DESC LIMIT "
        . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage), $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['ok'] = (int) $r['ok'] === 1;
        $r['http_status'] = $r['http_status'] !== null ? (int) $r['http_status'] : null;
        $r['duration_ms'] = $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null;
        $r['message_id'] = $r['message_id'] !== null ? (int) $r['message_id'] : null;
    }
    unset($r);
    $since = date('Y-m-d H:i:s', time() - 86400);
    $s = db_row('SELECT COUNT(*) AS total, SUM(ok = 0) AS failed, AVG(duration_ms) AS avg_ms, MAX(duration_ms) AS max_ms FROM wa_api_logs WHERE created_at >= ?', [$since]) ?: [];
    return ['rows' => $rows, 'pagination' => paginate($total, $page, $perPage),
        'summary' => ['total' => wa_adm_int($s['total'] ?? 0), 'failed' => wa_adm_int($s['failed'] ?? 0), 'avg_ms' => (int) round((float) ($s['avg_ms'] ?? 0)), 'max_ms' => wa_adm_int($s['max_ms'] ?? 0)]];
}

/** Redact anything that looks like a credential before showing a stored request / response. */
function wa_adm_redact($data)
{
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = is_string($k) && preg_match('/(authorization|api[_-]?key|secret|token|password)/i', $k) ? '••••' : wa_adm_redact($v);
        }
    }
    return $data;
}

function wa_adm_api_log(int $id): ?array
{
    $r = db_row('SELECT * FROM wa_api_logs WHERE id = ?', [$id]);
    if (!$r) {
        return null;
    }
    $dec = function ($s) {
        $d = json_decode((string) $s, true);
        return is_array($d) ? wa_adm_redact($d) : ($s === null ? null : (string) $s);
    };
    return ['id' => (int) $r['id'], 'endpoint' => $r['endpoint'], 'method' => $r['method'], 'http_status' => $r['http_status'] !== null ? (int) $r['http_status'] : null,
        'ok' => (int) $r['ok'] === 1, 'duration_ms' => $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null, 'message_id' => $r['message_id'] !== null ? (int) $r['message_id'] : null,
        'mode' => $r['mode'], 'error' => $r['error'], 'created_at' => $r['created_at'], 'request' => $dec($r['request']), 'response' => $dec($r['response'])];
}

/** Error monitoring: failure rates and top errors over N days. */
function wa_adm_error_summary(int $days = 7): array
{
    $days = max(1, min(90, $days));
    $range = wa_adm_range(['period' => 'custom', 'from' => date('Y-m-d', strtotime('-' . ($days - 1) . ' days')), 'to' => date('Y-m-d')]);
    $args = [$range['start'], $range['end']];
    $api = wa_adm_series('SELECT DATE(created_at) AS d, COUNT(*) AS total, SUM(ok = 0) AS failed FROM wa_api_logs WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at)', $args, $range, ['total', 'failed']);
    $msg = wa_adm_series("SELECT DATE(created_at) AS d, SUM(status NOT IN ('queued','cancelled')) AS total, SUM(status = 'failed') AS failed FROM wa_messages
                          WHERE direction = 'out' AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)", $args, $range, ['total', 'failed']);
    $hooks = wa_adm_series("SELECT DATE(received_at) AS d, COUNT(*) AS total, SUM(status = 'failed' OR (status = 'ignored' AND signature_valid = 0 AND " . WA_ADM_SIGNED_SQL . ")) AS failed FROM wa_webhook_logs
                            WHERE received_at BETWEEN ? AND ? GROUP BY DATE(received_at)", $args, $range, ['total', 'failed']);
    $topMsg = db_all("SELECT COALESCE(error_code, '') AS code, COALESCE(error, 'Unknown error') AS error, COUNT(*) AS n, MAX(created_at) AS last_at FROM wa_messages
                      WHERE direction = 'out' AND status = 'failed' AND created_at BETWEEN ? AND ? GROUP BY COALESCE(error_code, ''), COALESCE(error, 'Unknown error') ORDER BY n DESC LIMIT 10", $args);
    $topApi = db_all("SELECT endpoint, COALESCE(http_status, 0) AS http_status, COALESCE(error, '') AS error, COUNT(*) AS n, MAX(created_at) AS last_at FROM wa_api_logs
                      WHERE ok = 0 AND created_at BETWEEN ? AND ? GROUP BY endpoint, COALESCE(http_status, 0), COALESCE(error, '') ORDER BY n DESC LIMIT 10", $args);
    $topHook = db_all("SELECT event_type, COALESCE(error, '') AS error, COUNT(*) AS n, MAX(received_at) AS last_at FROM wa_webhook_logs
                       WHERE (status = 'failed' OR (status = 'ignored' AND signature_valid = 0 AND " . WA_ADM_SIGNED_SQL . ")) AND received_at BETWEEN ? AND ? GROUP BY event_type, COALESCE(error, '') ORDER BY n DESC LIMIT 10", $args);
    $sum = fn ($a) => array_sum($a);
    return [
        'days' => $days, 'labels' => $range['labels'],
        'api' => ['total' => $sum($api['total']), 'failed' => $sum($api['failed']), 'rate' => wa_adm_pct($sum($api['failed']), $sum($api['total'])), 'series' => $api['failed']],
        'messages' => ['total' => $sum($msg['total']), 'failed' => $sum($msg['failed']), 'rate' => wa_adm_pct($sum($msg['failed']), $sum($msg['total'])), 'series' => $msg['failed']],
        'webhooks' => ['total' => $sum($hooks['total']), 'failed' => $sum($hooks['failed']), 'rate' => wa_adm_pct($sum($hooks['failed']), $sum($hooks['total'])), 'series' => $hooks['failed']],
        'outbox' => array_map('intval', db_pairs("SELECT status, COUNT(*) FROM wa_outbox GROUP BY status")),
        'top_messages' => array_map(fn ($r) => ['code' => $r['code'] ?: null, 'error' => $r['error'], 'count' => (int) $r['n'], 'last_at' => $r['last_at']], $topMsg),
        'top_api' => array_map(fn ($r) => ['endpoint' => $r['endpoint'], 'http_status' => (int) $r['http_status'] ?: null, 'error' => $r['error'], 'count' => (int) $r['n'], 'last_at' => $r['last_at']], $topApi),
        'top_webhooks' => array_map(fn ($r) => ['event_type' => $r['event_type'], 'error' => $r['error'], 'count' => (int) $r['n'], 'last_at' => $r['last_at']], $topHook),
    ];
}

/**
 * Retry queue. Rows are keyed by the MESSAGE id (wa_messages.id). The "failed" view lists every failed outbound
 * message — including failures Interakt reports by webhook after the outbox row was already 'done' (number not on
 * WhatsApp, 24 h window, template paused …) and rows without an outbox row — because those are the ones admins
 * need to re-send. The other views list wa_outbox rows by queue state.
 */
function wa_adm_queue(array $q, int $page, int $perPage): array
{
    $status = (string) ($q['status'] ?? 'failed');
    $stuckAt = date('Y-m-d H:i:s', time() - 600);
    $where = ["m.direction = 'out'"];
    $args = [];
    if ($status === 'failed') {
        $where[] = "m.status = 'failed'";
        $from = 'FROM wa_messages m LEFT JOIN wa_outbox o ON o.message_id = m.id JOIN wa_contacts c ON c.id = m.contact_id';
    } else {
        if ($status === 'stuck') {
            $where[] = "o.status = 'processing' AND o.locked_at < ?";
            $args[] = $stuckAt;
        } else {
            $status = in_array($status, ['pending', 'processing', 'cancelled', 'done'], true) ? $status : 'pending';
            $where[] = 'o.status = ?';
            $args[] = $status;
        }
        $from = 'FROM wa_outbox o JOIN wa_messages m ON m.id = o.message_id JOIN wa_contacts c ON c.id = m.contact_id';
    }
    if (!empty($q['sender_type']) && in_array($q['sender_type'], ['agent', 'bot', 'automation', 'event', 'campaign', 'followup', 'system'], true)) {
        $where[] = 'm.sender_type = ?';
        $args[] = (string) $q['sender_type'];
    }
    $term = trim(mb_substr((string) ($q['q'] ?? ''), 0, 80));
    if ($term !== '') {
        $where[] = '(c.phone LIKE ? OR c.name LIKE ? OR m.template_name LIKE ?)';
        array_push($args, '%' . $term . '%', '%' . $term . '%', '%' . $term . '%');
    }
    $w = implode(' AND ', $where);
    $total = (int) db_value("SELECT COUNT(*) $from WHERE $w", $args);
    $order = $status === 'pending' ? 'o.scheduled_at, o.priority, m.id' : ($status === 'failed' ? 'COALESCE(m.failed_at, m.created_at) DESC, m.id DESC' : 'o.id DESC');
    $rows = db_all("SELECT m.id AS message_id, o.id AS outbox_id, " . ($status === 'failed' ? "'failed'" : 'o.status') . " AS status,
                           COALESCE(o.priority, 0) AS priority, COALESCE(o.attempts, 0) AS attempts, COALESCE(o.max_attempts, 0) AS max_attempts,
                           COALESCE(o.scheduled_at, m.created_at) AS scheduled_at, o.locked_at, COALESCE(m.error, o.last_error) AS last_error,
                           m.created_at, COALESCE(m.failed_at, o.updated_at) AS updated_at, o.status AS outbox_status,
                           m.type, m.template_name, m.sender_type, m.status AS message_status, m.error_code, m.conversation_id, m.campaign_id,
                           c.id AS contact_id, c.name AS contact_name, c.phone
                    $from WHERE $w ORDER BY $order LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage), $args);
    foreach ($rows as &$r) {
        foreach (['message_id', 'outbox_id', 'priority', 'attempts', 'max_attempts', 'contact_id', 'conversation_id', 'campaign_id'] as $k) {
            $r[$k] = $r[$k] !== null ? (int) $r[$k] : null;
        }
        $r['id'] = $r['message_id'];
        $r['retryable'] = $r['status'] === 'failed' || ($r['status'] === 'processing' && $r['locked_at'] && $r['locked_at'] < $stuckAt);
        $r['phone'] = wa_adm_phone($r['phone']);
    }
    unset($r);
    $counts = array_map('intval', db_pairs("SELECT status, COUNT(*) FROM wa_outbox WHERE status <> 'failed' GROUP BY status"));
    $counts['failed'] = (int) db_value("SELECT COUNT(*) FROM wa_messages WHERE direction = 'out' AND status = 'failed'");
    $counts['stuck'] = (int) db_value("SELECT COUNT(*) FROM wa_outbox WHERE status = 'processing' AND locked_at < ?", [$stuckAt]);
    $counts['due'] = (int) db_value("SELECT COUNT(*) FROM wa_outbox WHERE status = 'pending' AND scheduled_at <= ?", [wa_now()]);
    return ['rows' => $rows, 'pagination' => paginate($total, $page, $perPage), 'counts' => $counts];
}

/**
 * Re-queue failed / stuck messages through core's wa_retry_message() (same row; checks blocked contact, the 24-hour
 * window, template still sendable and marketing opt-in). $ids are wa_messages ids.
 * @return array{requeued:int, refused:int, reasons:array<string,int>}
 */
function wa_adm_queue_retry(array $ids): array
{
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 500);
    $out = ['requeued' => 0, 'refused' => 0, 'reasons' => []];
    foreach ($ids as $id) {
        $r = wa_retry_message($id, ['priority' => 3]);
        if ($r['ok']) {
            $out['requeued']++;
        } else {
            $out['refused']++;
            $label = [
                'not_failed' => 'not failed', 'window_closed' => '24-hour window closed', 'blocked' => 'contact blocked', 'opted_out' => 'opted out (STOP) / no marketing opt-in',
                'template_not_found' => 'template deleted', 'template_not_approved' => 'template not approved', 'not_found' => 'not found',
            ][$r['code'] ?? ''] ?? (string) ($r['code'] ?? 'refused');
            $out['reasons'][$label] = ($out['reasons'][$label] ?? 0) + 1;
        }
    }
    return $out;
}

/** Cancel pending messages (core helper wa_cancel_queued). $ids are wa_messages ids. */
function wa_adm_queue_cancel(array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return 0;
    }
    return wa_cancel_queued($ids, 'Cancelled from the retry queue by ' . (current_user()['name'] ?? 'an administrator'));
}

/** Message search by Interakt id, GIMT id or phone. */
function wa_adm_track_search(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $where = [];
    $args = [];
    if (ctype_digit($q) && strlen($q) <= 12) {
        $where[] = 'm.id = ?';
        $args[] = (int) $q;
    }
    $where[] = 'm.interakt_id = ?';
    $args[] = $q;
    if (strlen($q) >= 6) {
        $where[] = 'm.interakt_id LIKE ?';
        $args[] = $q . '%';
    }
    $digits = preg_replace('/\D+/', '', $q);
    if (strlen($digits) >= 6) {
        $where[] = 'c.phone LIKE ?';
        $args[] = '%' . $digits;
    }
    $rows = db_all('SELECT m.id, m.interakt_id, m.direction, m.type, m.status, m.sender_type, m.template_name, m.body, m.created_at, m.conversation_id, m.error,
                           c.id AS contact_id, c.name AS contact_name, c.phone
                    FROM wa_messages m JOIN wa_contacts c ON c.id = m.contact_id WHERE ' . implode(' OR ', $where) . ' ORDER BY m.id DESC LIMIT 30', $args);
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'interakt_id' => $r['interakt_id'], 'direction' => $r['direction'], 'type' => $r['type'], 'status' => $r['status'],
        'sender_type' => $r['sender_type'], 'template_name' => $r['template_name'], 'preview' => wa_preview((string) $r['body'], 80), 'created_at' => $r['created_at'],
        'conversation_id' => (int) $r['conversation_id'], 'contact_id' => (int) $r['contact_id'], 'contact_name' => $r['contact_name'], 'phone' => wa_adm_phone($r['phone']), 'error' => $r['error']], $rows);
}

/** Full timeline of one message: lifecycle stamps, outbox, API calls and webhooks. */
function wa_adm_track_message(int $id): ?array
{
    $m = db_row('SELECT m.*, c.name AS contact_name, c.phone, u.name AS sender_name FROM wa_messages m JOIN wa_contacts c ON c.id = m.contact_id
                 LEFT JOIN users u ON u.id = m.sent_by WHERE m.id = ?', [$id]);
    if (!$m) {
        return null;
    }
    $ob = db_row('SELECT id, status, priority, attempts, max_attempts, scheduled_at, locked_at, last_error, created_at, updated_at FROM wa_outbox WHERE message_id = ?', [$id]);
    $api = db_all('SELECT id, endpoint, method, http_status, ok, duration_ms, mode, error, created_at FROM wa_api_logs WHERE message_id = ? ORDER BY id', [$id]);
    $hooks = $m['interakt_id'] ? db_all('SELECT id, event_type, status, signature_valid, is_simulated, error, received_at FROM wa_webhook_logs WHERE interakt_message_id = ? ORDER BY id', [$m['interakt_id']]) : [];
    $steps = [['key' => 'created', 'label' => 'Queued', 'at' => $m['created_at'], 'done' => true]];
    if ($m['direction'] === 'out') {
        $steps[] = ['key' => 'sent', 'label' => $m['status'] === 'simulated' ? 'Simulated (test mode)' : 'Sent to WhatsApp', 'at' => $m['sent_at'], 'done' => (bool) $m['sent_at'] || in_array($m['status'], ['sent', 'delivered', 'read', 'simulated'], true)];
        $steps[] = ['key' => 'delivered', 'label' => 'Delivered', 'at' => $m['delivered_at'], 'done' => (bool) $m['delivered_at'] || in_array($m['status'], ['delivered', 'read'], true)];
        $steps[] = ['key' => 'read', 'label' => 'Read', 'at' => $m['read_at'], 'done' => (bool) $m['read_at'] || $m['status'] === 'read'];
        if ($m['status'] === 'failed') {
            $steps[] = ['key' => 'failed', 'label' => 'Failed', 'at' => $m['failed_at'], 'done' => true, 'error' => trim(($m['error_code'] ? '[' . $m['error_code'] . '] ' : '') . (string) $m['error'])];
        }
        if ($m['status'] === 'cancelled') {
            $steps[] = ['key' => 'cancelled', 'label' => 'Cancelled', 'at' => null, 'done' => true, 'error' => $m['error']];
        }
    } else {
        $steps[0]['label'] = 'Received from contact';
    }
    return [
        'message' => ['id' => (int) $m['id'], 'interakt_id' => $m['interakt_id'], 'direction' => $m['direction'], 'type' => $m['type'], 'status' => $m['status'],
            'sender_type' => $m['sender_type'], 'sender_name' => $m['sender_name'], 'template_name' => $m['template_name'], 'body' => $m['body'], 'error' => $m['error'],
            'error_code' => $m['error_code'], 'cost' => $m['cost'] !== null ? (float) $m['cost'] : null, 'created_at' => $m['created_at'], 'conversation_id' => (int) $m['conversation_id'],
            'contact_id' => (int) $m['contact_id'], 'contact_name' => $m['contact_name'], 'phone' => wa_adm_phone($m['phone']), 'campaign_id' => $m['campaign_id'] ? (int) $m['campaign_id'] : null,
            'workflow_run_id' => $m['workflow_run_id'] ? (int) $m['workflow_run_id'] : null, 'event_log_id' => $m['event_log_id'] ? (int) $m['event_log_id'] : null],
        'steps' => $steps,
        'outbox' => $ob ? ['id' => (int) $ob['id'], 'status' => $ob['status'], 'priority' => (int) $ob['priority'], 'attempts' => (int) $ob['attempts'],
            'max_attempts' => (int) $ob['max_attempts'], 'scheduled_at' => $ob['scheduled_at'], 'locked_at' => $ob['locked_at'], 'last_error' => $ob['last_error'],
            'created_at' => $ob['created_at'], 'updated_at' => $ob['updated_at']] : null,
        'api_calls' => array_map(fn ($r) => ['id' => (int) $r['id'], 'endpoint' => $r['endpoint'], 'method' => $r['method'], 'http_status' => $r['http_status'] !== null ? (int) $r['http_status'] : null,
            'ok' => (int) $r['ok'] === 1, 'duration_ms' => $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null, 'mode' => $r['mode'], 'error' => $r['error'], 'created_at' => $r['created_at']], $api),
        'webhooks' => array_map(fn ($r) => ['id' => (int) $r['id'], 'event_type' => $r['event_type'], 'status' => $r['status'], 'signature_valid' => $r['signature_valid'] === null ? null : (int) $r['signature_valid'] === 1,
            'is_simulated' => (int) $r['is_simulated'] === 1, 'error' => $r['error'], 'received_at' => $r['received_at']], $hooks),
    ];
}

/* =================================================================================================================
 * API tokens
 * ================================================================================================================= */

function wa_adm_token_scopes(): array
{
    // only scopes that an ingest endpoint actually checks (wa_evt_token($token, 'events') in api/routes/whatsapp/events.php)
    return [
        'events' => ['label' => 'Events', 'description' => 'POST /api/whatsapp/ingest/event — fire GIMT events (admission, payment, exam…) from external systems; the linked template is sent automatically.'],
    ];
}

function wa_adm_tokens(): array
{
    $rows = db_all('SELECT t.id, t.name, t.token_last4, t.scopes, t.last_used_at, t.last_used_ip, t.created_at, t.revoked_at, u.name AS created_by_name,
                           (SELECT COUNT(*) FROM wa_event_logs l WHERE l.source = \'api\' AND (l.payload LIKE CONCAT(\'%"!_client!_id":\', t.id, \',%\') ESCAPE \'!\'
                                OR l.payload LIKE CONCAT(\'%"!_client!_id":\', t.id, \'}%\') ESCAPE \'!\')) AS events
                    FROM wa_api_tokens t LEFT JOIN users u ON u.id = t.created_by ORDER BY t.revoked_at IS NULL DESC, t.id DESC');
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'last4' => $r['token_last4'], 'scopes' => array_values(array_filter(explode(',', (string) $r['scopes']))),
        'last_used_at' => $r['last_used_at'], 'last_used_ip' => $r['last_used_ip'], 'created_at' => $r['created_at'], 'created_by_name' => $r['created_by_name'],
        'revoked_at' => $r['revoked_at'], 'active' => $r['revoked_at'] === null, 'events' => (int) $r['events']], $rows);
}

/** Create a token; returns [id, token] — the token is never stored in clear text. */
function wa_adm_token_create(string $name, array $scopes): array
{
    $token = 'gimt_wa_' . bin2hex(random_bytes(24));
    $id = db_insert('wa_api_tokens', ['name' => $name, 'token_hash' => hash('sha256', $token), 'token_last4' => substr($token, -4),
        'scopes' => implode(',', $scopes), 'created_by' => wa_user_id()]);
    return [(int) $id, $token];
}

/* =================================================================================================================
 * Team (Module 13)
 * ================================================================================================================= */

/** WhatsApp permission modules with labels + actions (from the registry). */
function wa_adm_wa_modules(): array
{
    $reg = permission_modules();
    $out = [];
    foreach (wa_permission_modules() as $m) {
        if (isset($reg[$m])) {
            $out[] = ['key' => $m, 'label' => $reg[$m]['label'], 'actions' => array_values($reg[$m]['actions'])];
        }
    }
    return $out;
}

function wa_adm_team(): array
{
    $mods = wa_permission_modules();
    $ph = implode(',', array_fill(0, count($mods), '?'));
    $ids = wa_adm_team_user_ids();
    $stats = [];
    $start7 = date('Y-m-d 00:00:00', strtotime('-6 days'));
    foreach (wa_adm_agent_stats($start7, date('Y-m-d 23:59:59'), $ids) as $s) {
        $stats[$s['id']] = $s;
    }
    $roles = [];
    $grants = [];
    if ($ids) {
        $ph2 = implode(',', array_fill(0, count($ids), '?'));
        foreach (db_all("SELECT ur.user_id, r.id, r.name, r.color, r.is_super FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id IN ($ph2) ORDER BY r.name", $ids) as $r) {
            $roles[(int) $r['user_id']][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'color' => $r['color'], 'is_super' => (bool) $r['is_super']];
        }
        foreach (db_all("SELECT DISTINCT ur.user_id, p.module, p.action FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id JOIN permissions p ON p.id = rp.permission_id
                         WHERE ur.user_id IN ($ph2) AND p.module IN ($ph)", array_merge($ids, $mods)) as $g) {
            $grants[(int) $g['user_id']][$g['module']][] = $g['action'];
        }
    }
    $members = [];
    foreach ($stats as $id => $s) {
        if (!in_array($id, $ids, true)) {
            continue;
        }
        $isSuper = (bool) array_filter($roles[$id] ?? [], fn ($r) => $r['is_super']);
        $g = $grants[$id] ?? [];
        $level = $isSuper || isset($g['whatsapp_settings']) && in_array('manage', $g['whatsapp_settings'], true) ? 'admin'
            : (isset($g['whatsapp']) && (in_array('create', $g['whatsapp'], true) || in_array('manage', $g['whatsapp'], true)) ? 'agent' : 'viewer');
        $members[] = $s + ['roles' => $roles[$id] ?? [], 'is_super' => $isSuper, 'permissions' => $isSuper ? 'all' : $g, 'level' => $level];
    }
    usort($members, fn ($a, $b) => [$a['level'] === 'admin' ? 0 : ($a['level'] === 'agent' ? 1 : 2), $a['name']] <=> [$b['level'] === 'admin' ? 0 : ($b['level'] === 'agent' ? 1 : 2), $b['name']]);
    $sum = ['members' => count($members), 'agents' => count(array_filter($members, fn ($m) => $m['level'] !== 'viewer')),
        'online_today' => count(array_filter($members, fn ($m) => $m['last_activity_at'] && $m['last_activity_at'] >= date('Y-m-d 00:00:00'))),
        'open' => array_sum(array_column($members, 'open')), 'unassigned' => (int) db_value("SELECT COUNT(*) FROM wa_conversations WHERE status <> 'resolved' AND assigned_to IS NULL"),
        'replies_today' => array_sum(array_column($members, 'replies_today'))];
    return ['members' => $members, 'summary' => $sum];
}

/** Roles with their whatsapp* grants for the matrix. */
function wa_adm_matrix(): array
{
    $mods = wa_permission_modules();
    $ph = implode(',', array_fill(0, count($mods), '?'));
    $roles = db_all('SELECT r.id, r.name, r.slug, r.color, r.description, r.is_super, r.is_system, (SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role_id = r.id AND u.status = \'active\') AS users
                     FROM roles r ORDER BY r.is_super DESC, r.id');
    $grants = [];
    foreach (db_all("SELECT rp.role_id, CONCAT(p.module, '.', p.action) AS k FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE p.module IN ($ph)", $mods) as $g) {
        $grants[(int) $g['role_id']][] = $g['k'];
    }
    $canEdit = wa_can('whatsapp_settings', 'manage') && wa_can('roles', 'edit');
    return [
        'modules' => wa_adm_wa_modules(),
        'actions' => function_exists('permission_action_meta') ? permission_action_meta() : [],
        'roles' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'slug' => $r['slug'], 'color' => $r['color'], 'description' => $r['description'],
            'is_super' => (bool) $r['is_super'], 'is_system' => (bool) $r['is_system'], 'users' => (int) $r['users'], 'permissions' => $r['is_super'] ? [] : ($grants[(int) $r['id']] ?? [])], $roles),
        'can_edit' => $canEdit,
        'is_super' => function_exists('is_super_admin') && is_super_admin(),
    ];
}

/** Replace only the whatsapp* grants of a role. Returns [added, removed]. */
function wa_adm_save_role_wa_permissions(array $role, array $keys): array
{
    $mods = wa_permission_modules();
    $reg = permission_modules();
    $wanted = [];
    foreach ($keys as $k) {
        if (!is_string($k) || !str_contains($k, '.')) {
            api_error('Invalid permission key.', 422);
        }
        [$m, $a] = explode('.', $k, 2);
        if (!in_array($m, $mods, true) || !isset($reg[$m]) || !in_array($a, $reg[$m]['actions'], true)) {
            api_error('Unknown WhatsApp permission: ' . $k, 422);
        }
        $wanted[$k] = true;
    }
    $before = role_permission_keys((int) $role['id']);
    $beforeWa = array_values(array_filter($before, fn ($k) => in_array(explode('.', $k, 2)[0], $mods, true)));
    if (!(function_exists('is_super_admin') && is_super_admin())) {
        $denied = [];
        foreach (array_diff(array_keys($wanted), $beforeWa) as $k) {
            [$m, $a] = explode('.', $k, 2);
            if (!can($m, $a)) {
                $denied[] = $k;
            }
        }
        if ($denied) {
            api_error('You cannot grant permissions you do not have yourself: ' . implode(', ', array_slice($denied, 0, 6)) . (count($denied) > 6 ? '…' : '') . '.', 403);
        }
    }
    $others = array_values(array_filter($before, fn ($k) => !in_array(explode('.', $k, 2)[0], $mods, true)));
    role_save_permissions((int) $role['id'], array_merge($others, array_keys($wanted)));
    return [array_values(array_diff(array_keys($wanted), $beforeWa)), array_values(array_diff($beforeWa, array_keys($wanted)))];
}

/** Activity of WhatsApp team members (agent activity + audit log entries of whatsapp* modules). */
function wa_adm_team_activity(array $q, int $page, int $perPage): array
{
    $mods = wa_permission_modules();
    $ph = implode(',', array_fill(0, count($mods), '?'));
    $wa = ['1 = 1'];
    $al = ["l.module IN ($ph)"];
    $aArgs = [];
    $lArgs = $mods;
    if (!empty($q['user_id']) && ctype_digit((string) $q['user_id'])) {
        $wa[] = 'a.user_id = ?';
        $aArgs[] = (int) $q['user_id'];
        $al[] = 'l.user_id = ?';
        $lArgs[] = (int) $q['user_id'];
    }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        $v = (string) ($q[$k] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $stamp = $v . ($k === 'from' ? ' 00:00:00' : ' 23:59:59');
            $wa[] = "a.created_at $op ?";
            $aArgs[] = $stamp;
            $al[] = "l.created_at $op ?";
            $lArgs[] = $stamp;
        }
    }
    $src = (string) ($q['source'] ?? '');
    $parts = [];
    $args = [];
    if ($src !== 'audit') {
        $parts[] = "SELECT CONCAT('a', a.id) AS uid, a.created_at, a.user_id, 'inbox' AS source, a.action, a.detail AS description, 'whatsapp' AS module,
                           a.conversation_id, a.contact_id, c.name AS contact_name
                    FROM wa_agent_activity a LEFT JOIN wa_contacts c ON c.id = a.contact_id WHERE " . implode(' AND ', $wa);
        $args = array_merge($args, $aArgs);
    }
    if ($src !== 'inbox') {
        $parts[] = "SELECT CONCAT('l', l.id) AS uid, l.created_at, l.user_id, 'audit' AS source, l.action, l.description, l.module,
                           NULL AS conversation_id, NULL AS contact_id, NULL AS contact_name
                    FROM activity_logs l WHERE " . implode(' AND ', $al);
        $args = array_merge($args, $lArgs);
    }
    $union = implode(' UNION ALL ', $parts);
    $total = (int) db_value("SELECT COUNT(*) FROM ($union) x", $args);
    $rows = db_all("SELECT x.*, u.name AS user_name, u.avatar FROM ($union) x LEFT JOIN users u ON u.id = x.user_id ORDER BY x.created_at DESC, x.uid DESC LIMIT "
        . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage), $args);
    $labels = [];
    foreach (permission_modules() as $k => $d) {
        $labels[$k] = $d['label'];
    }
    foreach ($rows as &$r) {
        $r['user_id'] = $r['user_id'] !== null ? (int) $r['user_id'] : null;
        $r['conversation_id'] = $r['conversation_id'] !== null ? (int) $r['conversation_id'] : null;
        $r['contact_id'] = $r['contact_id'] !== null ? (int) $r['contact_id'] : null;
        $r['module_label'] = $labels[$r['module']] ?? $r['module'];
    }
    unset($r);
    return ['rows' => $rows, 'pagination' => paginate($total, $page, $perPage)];
}

/* =================================================================================================================
 * API documentation payload
 * ================================================================================================================= */

function wa_adm_docs(): array
{
    $ev = db_all("SELECT code, name, category, source, sample_payload, is_active FROM wa_events WHERE is_active = 1 OR source IN ('api','js') ORDER BY source = 'api' DESC, is_active DESC, code LIMIT 40");
    $events = array_map(fn ($e) => ['code' => $e['code'], 'name' => $e['name'], 'category' => $e['category'], 'source' => $e['source'], 'active' => (int) $e['is_active'] === 1,
        'sample_payload' => wa_json_decode($e['sample_payload'], null)], $ev);
    $sample = null;
    foreach ($events as $e) {
        if ($e['source'] === 'api' || $e['code'] === 'GIMT_PAYMENT_SUCCESS') {
            $sample = $e;
            break;
        }
    }
    $track = null;
    foreach ($events as $e) {
        if ($e['source'] === 'js') {
            $track = $e;
            break;
        }
    }
    return [
        'base_url' => rtrim(absolute_url(''), '/'),
        'ingest_event_url' => absolute_url('api/whatsapp/ingest/event'),
        'ingest_track_url' => absolute_url('api/whatsapp/ingest/track'),
        'webhook_url' => absolute_url('api/whatsapp/webhook'),
        'events' => $events,
        'sample_event' => $sample ?? ($events[0] ?? null),
        'track_event' => $track,
        'scopes' => wa_adm_token_scopes(),
        'webhook_samples' => [
            'message_api_delivered' => ['version' => '1.0', 'timestamp' => '2026-11-03T05:43:33.133000', 'type' => 'message_api_delivered', 'data' => [
                'customer' => ['id' => '52918eb3-0000-0000-0000-000000000000', 'channel_phone_number' => '919876543210', 'traits' => ['name' => 'Rahul Sharma', 'whatsapp_opted_in' => true]],
                'message' => ['id' => '0a1b2c3d-0000-0000-0000-000000000000', 'chat_message_type' => 'PublicApiMessage', 'message_status' => 'Delivered',
                    'received_at_utc' => '2026-11-03T05:43:30.000000', 'delivered_at_utc' => '2026-11-03T05:43:33.000000', 'is_template_message' => true,
                    'meta_data' => ['source' => 'PublicInterakt', 'source_data' => ['callback_data' => 'gimt:msg:1234'], 'message_cost' => ['actual_message_cost' => '0.12']]]]],
            'message_received' => ['version' => '1.0', 'timestamp' => '2026-11-03T05:57:57.496889', 'type' => 'message_received', 'data' => [
                'customer' => ['id' => '52918eb3-0000-0000-0000-000000000000', 'channel_phone_number' => '919876543210', 'traits' => ['name' => 'Rahul Sharma']],
                'message' => ['id' => '60076f05-0000-0000-0000-000000000000', 'chat_message_type' => 'CustomerMessage', 'message_status' => 'Sent', 'received_at_utc' => '2026-11-03T05:57:57.359000',
                    'is_template_message' => false, 'message_content_type' => 'Text', 'media_url' => null, 'message' => 'What is the last date to apply for MBA?', 'meta_data' => (object) []]]],
        ],
        'signature' => 'Interakt-Signature: sha256=<HMAC-SHA256 of the raw request body with your webhook secret>',
    ];
}

/* =================================================================================================================
 * Background: notifications on new messages / handovers, daily digest, quiet-hours deferral
 * ================================================================================================================= */

/** New inbound message → notify the assigned agent (at most once per conversation every 10 minutes). */
function wa_adm_on_message_received(array $event, array $payload, ?array $contact): ?array
{
    if (!wa_adm_notify_enabled('new_message') || empty($payload['conversation_id'])) {
        return null;
    }
    $conv = db_row('SELECT id, assigned_to, bot_active, status FROM wa_conversations WHERE id = ?', [(int) $payload['conversation_id']]);
    if (!$conv || !$conv['assigned_to'] || ((int) $conv['bot_active'] === 1 && wa_setting('wa_bot_enabled', '0') === '1')) {
        return null;
    }
    if (function_exists('rate_limit') && !rate_limit('wa-adm-notify:' . $conv['id'], 1, 600)) {
        return null;
    }
    // notifications are stored for later display, so mask by the setting alone (no viewer context here)
    $phone = (string) ($contact['phone'] ?? '');
    $who = ($contact['name'] ?? null) ?: ((string) wa_adm_setting('wa_mask_phones') === '1' ? wa_adm_mask_phone($phone) : '+' . $phone);
    $text = trim((string) ($payload['text'] ?? ''));
    $preview = $text !== '' ? wa_preview($text, 140) : 'New ' . str_replace('_', ' ', (string) ($payload['type'] ?? 'message')) . ' message';
    notify([(int) $conv['assigned_to']], 'whatsapp', 'New WhatsApp message from ' . $who, $preview, 'admin/whatsapp/inbox?c=' . (int) $conv['id'], 'message-circle');
    return ['notified' => (int) $conv['assigned_to']];
}

/**
 * Tick handler: the daily digest. Bot hand-over notifications belong to the bot part (whatsapp_bot.php, honouring
 * wa_adm_notify_enabled('handover')); pause / quiet hours are enforced by core (wa_send() gate + the outbox hold), so
 * nothing is rescheduled here — a resumed automation sends at once instead of waiting for a pushed-back slot.
 */
function wa_adm_tick(float $budget): array
{
    $stats = [];
    $now = wa_now();

    // daily digest (once a day after wa_digest_time)
    if (wa_adm_notify_enabled('digest')) {
        $time = (string) wa_adm_setting('wa_digest_time');
        $lastDigest = (string) (setting('_wa_adm_last_digest') ?: '');
        if (preg_match('/^\d{2}:\d{2}$/', $time) && date('H:i') >= $time && substr($lastDigest, 0, 10) !== date('Y-m-d')) {
            save_setting('_wa_adm_last_digest', $now, 'internal');
            $y0 = date('Y-m-d 00:00:00', strtotime('-1 day'));
            $y1 = date('Y-m-d 23:59:59', strtotime('-1 day'));
            $c = wa_adm_message_counts($y0, $y1);
            $waiting = (int) db_value("SELECT COUNT(*) FROM wa_conversations WHERE status <> 'resolved' AND last_message_direction = 'in'");
            $fails = (int) db_value("SELECT COUNT(*) FROM wa_workflow_runs WHERE status = 'failed' AND started_at BETWEEN ? AND ?", [$y0, $y1]);
            notify('perm:whatsapp', 'whatsapp', 'WhatsApp daily summary — ' . date('d M', strtotime('-1 day')),
                sprintf('%d sent · %d delivered · %d read · %d failed · %d received. %d chats waiting for a reply, %d failed workflow runs.',
                    $c['sent'], $c['delivered'], $c['read'], $c['failed'], $c['received'], $waiting, $fails), 'admin/whatsapp', 'bar-chart-3');
            $stats['digest'] = 1;
        }
    }
    return $stats;
}

if (function_exists('wa_on')) {
    wa_on('WA_MESSAGE_RECEIVED', 'wa_adm_on_message_received', 50);
}
if (function_exists('wa_on_tick')) {
    wa_on_tick('admin', 'wa_adm_tick');
}
