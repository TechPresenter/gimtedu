<?php
/**
 * WhatsApp visual automation workflows (Module 5) — definition model, validation, runner and worker hooks.
 *
 * A workflow definition is JSON {nodes: [{id, type, x, y, data}], edges: [{from, to, label}]} where `label` is the
 * output port of the source node: `next`, `true`/`false` (Condition, If/Else) or `replied`/`timeout` (Wait for Reply).
 *
 * Runner: wa_on('*') starts runs for active workflows whose trigger event fired (dedupe per event, one active run per
 * contact unless re-entry is allowed); nodes execute one after the other until a Delay / Wait for Reply / business-hours
 * pause (status waiting + next_run_at); the worker tick resumes due runs; an inbound message (WA_MESSAGE_RECEIVED)
 * resumes runs waiting for that contact's reply. Every step is written to wa_workflow_steps.
 *
 * Loaded by app/services/whatsapp.php — this file only defines functions and registers hooks (no queries at include).
 * Prefix: wa_wf_.
 */

const WA_WF_MAX_NODES = 150;
const WA_WF_MAX_STEPS_PER_PASS = 80;
const WA_WF_MAX_STEPS_PER_RUN = 250;      // whole run, across every pass (loops through a Delay / Wait for Reply included)
const WA_WF_MAX_NODE_VISITS = 25;
const WA_WF_MAX_CHAIN = 4;                // workflow → event → workflow … depth (a workflow never re-triggers itself)
const WA_WF_REENTRY_COOLDOWN = 600;       // allow_reentry: at least 10 minutes between two runs of one contact
const WA_WF_MAX_RUNS_PER_DAY = 10;        // runs of one workflow per contact per 24 hours (event floods)
const WA_WF_HTTP_MAX_BYTES = 65536;       // API / webhook response cap
const WA_WF_SECRET_MASK = '••••••••';

/* ============================================================================================ catalogue */

/** Node types: key => label, output ports, whether the node needs a WhatsApp contact. */
function wa_wf_node_types(): array
{
    return [
        'trigger'    => ['label' => 'Event Trigger', 'ports' => ['next'], 'contact' => false],
        'contact'    => ['label' => 'Create / Link Contact', 'ports' => ['next'], 'contact' => false],
        'template'   => ['label' => 'WhatsApp Template', 'ports' => ['next'], 'contact' => true],
        'message'    => ['label' => 'Send Message', 'ports' => ['next'], 'contact' => true],
        'delay'      => ['label' => 'Delay', 'ports' => ['next'], 'contact' => false],
        'condition'  => ['label' => 'Condition', 'ports' => ['true', 'false'], 'contact' => false],
        'ifelse'     => ['label' => 'If / Else', 'ports' => ['true', 'false'], 'contact' => false],
        'wait_reply' => ['label' => 'Wait for Reply', 'ports' => ['replied', 'timeout'], 'contact' => true],
        'tag'        => ['label' => 'Add Student Tag', 'ports' => ['next'], 'contact' => true],
        'stage'      => ['label' => 'Update Status / Stage', 'ports' => ['next'], 'contact' => true],
        'assign'     => ['label' => 'Assign Counsellor', 'ports' => ['next'], 'contact' => true],
        'api'        => ['label' => 'Send API Request', 'ports' => ['next'], 'contact' => false],
        'webhook'    => ['label' => 'Webhook', 'ports' => ['next'], 'contact' => false],
        'end'        => ['label' => 'End Workflow', 'ports' => [], 'contact' => false],
    ];
}

function wa_wf_ports(string $type): array
{
    return wa_wf_node_types()[$type]['ports'] ?? [];
}

/** Internal (core) events that can start a workflow in addition to the GIMT_* business events of wa_events. */
function wa_wf_internal_events(): array
{
    return [
        'WA_MESSAGE_RECEIVED' => ['name' => 'Inbound WhatsApp message', 'category' => 'whatsapp', 'description' => 'A contact sent a message to the institute number.',
            'sample' => ['contact_id' => 0, 'text' => 'Hi, I want to know about the BBA fee structure', 'type' => 'text']],
        'WA_BUTTON_CLICKED'   => ['name' => 'Template button clicked', 'category' => 'whatsapp', 'description' => 'A contact tapped a quick-reply or URL button.',
            'sample' => ['contact_id' => 0, 'button_payload' => 'Talk to counsellor', 'text' => 'Talk to counsellor']],
        'WA_OPTED_IN'         => ['name' => 'Contact opted in to WhatsApp', 'category' => 'whatsapp', 'description' => 'A contact gave WhatsApp consent (form, import, inbound START…).',
            'sample' => ['contact_id' => 0, 'source' => 'enquiry form consent']],
        'WA_STAGE_CHANGED'    => ['name' => 'Admission stage changed', 'category' => 'whatsapp', 'description' => 'The admission follow-up stage of a contact changed (payload: from, to).',
            'sample' => ['contact_id' => 0, 'from' => 'interested', 'to' => 'payment_pending']],
    ];
}

/** Enquiry (lead) statuses a workflow may set — the ERP's open/closed lists are not extended. */
function wa_wf_lead_statuses(): array
{
    return ['new' => 'New', 'contacted' => 'Contacted', 'interested' => 'Interested', 'not_interested' => 'Not interested'];
}

/** Fallback admission follow-up stages (used when wa_followup_stages is empty). */
function wa_wf_default_stages(): array
{
    return [
        'new_enquiry' => 'New Enquiry', 'contacted' => 'Contacted', 'interested' => 'Interested', 'counselling_scheduled' => 'Counselling Scheduled',
        'application_started' => 'Application Started', 'application_submitted' => 'Application Submitted', 'payment_pending' => 'Payment Pending',
        'admission_confirmed' => 'Admission Confirmed', 'not_interested' => 'Not Interested',
    ];
}

function wa_wf_stages(): array
{
    try {
        $rows = db_pairs('SELECT stage_key, name FROM wa_followup_stages ORDER BY sort_order, id');
    } catch (Throwable $e) {
        $rows = [];
    }
    return $rows ?: wa_wf_default_stages();
}

/** Fields a Condition / If-Else rule can test (anything under payload.* / vars.* may also be typed). */
function wa_wf_condition_fields(): array
{
    return [
        ['value' => 'reply.received', 'label' => 'Contact replied since the workflow started', 'group' => 'Reply', 'kind' => 'bool'],
        ['value' => 'reply.text', 'label' => 'Text of the last reply', 'group' => 'Reply', 'kind' => 'text'],
        ['value' => 'contact.stage', 'label' => 'Admission stage', 'group' => 'Contact', 'kind' => 'stage'],
        ['value' => 'contact.tags', 'label' => 'Tags', 'group' => 'Contact', 'kind' => 'tags'],
        ['value' => 'contact.opt_in', 'label' => 'Opted in to WhatsApp', 'group' => 'Contact', 'kind' => 'bool'],
        ['value' => 'contact.window_open', 'label' => '24-hour window is open', 'group' => 'Contact', 'kind' => 'bool'],
        ['value' => 'contact.contact_type', 'label' => 'Contact type', 'group' => 'Contact', 'kind' => 'text'],
        ['value' => 'contact.program_name', 'label' => 'Programme', 'group' => 'Contact', 'kind' => 'text'],
        ['value' => 'contact.city', 'label' => 'City', 'group' => 'Contact', 'kind' => 'text'],
        ['value' => 'contact.state', 'label' => 'State', 'group' => 'Contact', 'kind' => 'text'],
        ['value' => 'contact.payment_status', 'label' => 'Payment status', 'group' => 'Contact', 'kind' => 'text'],
        ['value' => 'contact.admission_status', 'label' => 'Admission status', 'group' => 'Contact', 'kind' => 'text'],
        ['value' => 'contact.counsellor_id', 'label' => 'Counsellor assigned (user id)', 'group' => 'Contact', 'kind' => 'text'],
        ['value' => 'contact.language', 'label' => 'Language', 'group' => 'Contact', 'kind' => 'text'],
        ['value' => 'contact.student_id', 'label' => 'Linked student id', 'group' => 'Contact', 'kind' => 'text'],
        ['value' => 'contact.enquiry_id', 'label' => 'Linked enquiry id', 'group' => 'Contact', 'kind' => 'text'],
    ];
}

function wa_wf_condition_ops(): array
{
    return [
        'equals' => 'is', 'not_equals' => 'is not', 'contains' => 'contains', 'not_contains' => 'does not contain', 'starts_with' => 'starts with',
        'in' => 'is one of (comma list)', 'gt' => 'greater than', 'gte' => 'at least', 'lt' => 'less than', 'lte' => 'at most',
        'is_empty' => 'is empty', 'not_empty' => 'is not empty', 'is_true' => 'is yes / true', 'is_false' => 'is no / false',
        'has_tag' => 'has tag', 'not_has_tag' => 'does not have tag',
    ];
}

/**
 * Eligible counsellors: active users who can edit enquiries or WhatsApp contacts (or super admins). With $defaultPool,
 * the round-robin default: the admission team only (super admins and the all-powerful administrator role are left
 * out unless nobody else qualifies).
 */
function wa_wf_counsellors(bool $defaultPool = false): array
{
    $all = db_all("SELECT u.id, u.name, u.designation,
                          MAX(CASE WHEN r.is_super = 0 AND r.slug <> 'administrator' AND pm.module IN ('enquiries', 'whatsapp_contacts') AND pm.action IN ('edit', 'manage') THEN 1 ELSE 0 END) AS team
                   FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                   LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions pm ON pm.id = rp.permission_id
                   WHERE u.status = 'active' AND (r.is_super = 1 OR (pm.module IN ('enquiries', 'whatsapp_contacts') AND pm.action IN ('edit', 'manage')))
                   GROUP BY u.id, u.name, u.designation ORDER BY u.name");
    if ($defaultPool) {
        $team = array_values(array_filter($all, fn ($u) => (int) $u['team'] === 1));
        return $team ?: $all;
    }
    return $all;
}

/* ============================================================================================ JSON helpers */

function wa_wf_json($value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function wa_wf_decode($json, $default = [])
{
    if (is_array($json)) {
        return $json;
    }
    if (!is_string($json) || $json === '') {
        return $default;
    }
    $v = json_decode($json, true);
    return is_array($v) ? $v : $default;
}

/** Clean a value from the client: strings trimmed + capped, arrays recursed (depth/size limited). */
function wa_wf_clean($v, int $depth = 0)
{
    if ($depth > 6) {
        return null;
    }
    if (is_string($v)) {
        return mb_substr(trim($v), 0, 10000);
    }
    if (is_int($v) || is_float($v) || is_bool($v) || $v === null) {
        return $v;
    }
    if (is_array($v)) {
        $out = [];
        $i = 0;
        foreach ($v as $k => $item) {
            if (++$i > 200) {
                break;
            }
            $key = is_int($k) ? $k : mb_substr((string) $k, 0, 80);
            $out[$key] = wa_wf_clean($item, $depth + 1);
        }
        return $out;
    }
    return null;
}

/** Normalise a definition sent by the builder: valid node ids/types, numeric positions, edges on valid ports only. */
function wa_wf_normalize_definition($def): array
{
    $def = wa_wf_decode($def, []);
    $types = wa_wf_node_types();
    $nodes = [];
    $seen = [];
    foreach (array_slice(is_array($def['nodes'] ?? null) ? $def['nodes'] : [], 0, WA_WF_MAX_NODES + 1) as $n) {
        if (!is_array($n)) {
            continue;
        }
        $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($n['id'] ?? ''));
        $type = (string) ($n['type'] ?? '');
        if ($id === '' || strlen($id) > 64 || !isset($types[$type]) || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = $type;
        $data = wa_wf_clean(is_array($n['data'] ?? null) ? $n['data'] : []);
        $nodes[] = [
            'id' => $id, 'type' => $type,
            'x' => (int) round(max(-20000, min(20000, (float) ($n['x'] ?? 0)))),
            'y' => (int) round(max(-20000, min(20000, (float) ($n['y'] ?? 0)))),
            'data' => is_array($data) ? $data : [],
        ];
    }
    $edges = [];
    $used = [];
    foreach (is_array($def['edges'] ?? null) ? $def['edges'] : [] as $e) {
        if (!is_array($e)) {
            continue;
        }
        $from = (string) ($e['from'] ?? '');
        $to = (string) ($e['to'] ?? '');
        $label = (string) ($e['label'] ?? 'next');
        if (!isset($seen[$from], $seen[$to]) || !in_array($label, wa_wf_ports($seen[$from]), true) || $seen[$to] === 'trigger') {
            continue;
        }
        // one edge per output port: the last one wins
        $used[$from . '|' . $label] = ['from' => $from, 'to' => $to, 'label' => $label];
    }
    $edges = array_values($used);
    return ['nodes' => $nodes, 'edges' => $edges];
}

function wa_wf_definition(array $wf): array
{
    return wa_wf_normalize_definition(wa_wf_decode($wf['definition'] ?? null, ['nodes' => [], 'edges' => []]));
}

function wa_wf_node_map(array $def): array
{
    $map = [];
    foreach ($def['nodes'] as $n) {
        $map[$n['id']] = $n;
    }
    return $map;
}

function wa_wf_edge_map(array $def): array
{
    $map = [];
    foreach ($def['edges'] as $e) {
        $map[$e['from']][$e['label']] = $e['to'];
    }
    return $map;
}

function wa_wf_trigger_node(array $def): ?array
{
    foreach ($def['nodes'] as $n) {
        if ($n['type'] === 'trigger') {
            return $n;
        }
    }
    return null;
}

/** "1 minute", "10 minutes", "2 days" … */
function wa_wf_duration_text(int $value, string $unit): string
{
    $unit = in_array($unit, ['minutes', 'hours', 'days'], true) ? $unit : 'minutes';
    return $value . ' ' . ($value === 1 ? rtrim($unit, 's') : $unit);
}

/** Human title of a node (custom label or a summary of its configuration). */
function wa_wf_node_title(array $node): string
{
    $label = trim((string) ($node['data']['label'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    $d = $node['data'] ?? [];
    switch ($node['type']) {
        case 'trigger':
            return 'When ' . ($d['event'] ?? 'event') . ' happens';
        case 'template':
            return 'Send template ' . ($d['template'] ?? '');
        case 'delay':
            return 'Wait ' . wa_wf_duration_text((int) ($d['value'] ?? 0), (string) ($d['unit'] ?? 'minutes'));
        case 'wait_reply':
            return 'Wait up to ' . wa_wf_duration_text((int) ($d['value'] ?? 0), (string) ($d['unit'] ?? 'hours')) . ' for a reply';
        default:
            return wa_wf_node_types()[$node['type']]['label'] ?? $node['type'];
    }
}

/* ============================================================================================ secrets in headers */

function wa_wf_is_secret_header(string $name): bool
{
    return (bool) preg_match('/auth|token|key|secret|password|signature|cookie/i', $name);
}

/** Replace secret-looking API header values with a mask before a definition goes to the browser. */
function wa_wf_mask_definition(array $def): array
{
    foreach ($def['nodes'] as &$n) {
        if ($n['type'] === 'api' && is_array($n['data']['headers'] ?? null)) {
            foreach ($n['data']['headers'] as &$h) {
                if (is_array($h) && wa_wf_is_secret_header((string) ($h['key'] ?? '')) && (string) ($h['value'] ?? '') !== '') {
                    $h['value'] = WA_WF_SECRET_MASK;
                    $h['masked'] = true;
                }
            }
            unset($h);
        }
    }
    unset($n);
    return $def;
}

/**
 * Put back the stored secret header values the browser only saw masked. A hidden value is only restored for the same
 * step calling the same host: if the URL host changed, it is cleared (someone who cannot see a token must not be able
 * to send it to another server) and listed in $cleared as "step: header".
 */
function wa_wf_unmask_definition(array $def, array $old, ?array &$cleared = null): array
{
    $cleared = [];
    $oldNodes = wa_wf_node_map($old);
    foreach ($def['nodes'] as &$n) {
        if ($n['type'] !== 'api' || !is_array($n['data']['headers'] ?? null)) {
            continue;
        }
        $prev = [];
        $oldNode = $oldNodes[$n['id']] ?? null;
        foreach ((array) ($oldNode['data']['headers'] ?? []) as $h) {
            if (is_array($h)) {
                $prev[strtolower((string) ($h['key'] ?? ''))] = (string) ($h['value'] ?? '');
            }
        }
        $strip = fn ($u) => wa_wf_url_host((string) preg_replace('/\{\{[^}]*\}\}/', 'x', (string) $u));
        $sameHost = $oldNode && ($oldNode['type'] ?? '') === 'api' && $strip($oldNode['data']['url'] ?? '') === $strip($n['data']['url'] ?? '');
        foreach ($n['data']['headers'] as &$h) {
            if (!is_array($h)) {
                continue;
            }
            unset($h['masked']);
            if ((string) ($h['value'] ?? '') === WA_WF_SECRET_MASK) {
                $key = strtolower((string) ($h['key'] ?? ''));
                if ($sameHost && isset($prev[$key])) {
                    $h['value'] = $prev[$key];
                } else {
                    $h['value'] = '';
                    if (isset($prev[$key]) && $prev[$key] !== '') {
                        $cleared[] = wa_wf_node_title($n) . ': ' . ($h['key'] ?? '');
                    }
                }
            }
        }
        unset($h);
    }
    unset($n);
    return $def;
}

/* ============================================================================================ URL safety (API / webhook nodes) */

/** Is $ip inside $cidr? Works for IPv4 and IPv6 (binary prefix compare). */
function wa_wf_ip_in_cidr(string $bin, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr) + [1 => null];
    $netBin = @inet_pton($net);
    if ($netBin === false || strlen($netBin) !== strlen($bin)) {
        return false;
    }
    $bits = $bits === null ? strlen($bin) * 8 : (int) $bits;
    $bytes = intdiv($bits, 8);
    if ($bytes && substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

/**
 * True only for a globally routable unicast address. Blocks loopback, private (RFC 1918), CGNAT, link-local (cloud
 * metadata 169.254.169.254, fd00:ec2::254), unique-local, multicast, documentation, benchmarking and reserved ranges,
 * IPv4-mapped / -compatible IPv6 (checked as the embedded IPv4) and the NAT64 / 6to4 / Teredo translation prefixes.
 */
function wa_wf_ip_is_public(string $ip): bool
{
    $ip = trim($ip, '[] ');
    if (str_contains($ip, '%')) {
        return false; // zone ids (fe80::1%eth0) are always local
    }
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    if (strlen($bin) === 16) {
        // ::ffff:a.b.c.d and the deprecated ::a.b.c.d → judge the embedded IPv4 address
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || (substr($bin, 0, 12) === str_repeat("\0", 12) && substr($bin, 12) !== "\0\0\0\0" && substr($bin, 12) !== "\0\0\0\1")) {
            return wa_wf_ip_is_public((string) inet_ntop(substr($bin, 12)));
        }
        foreach (['::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:2::/48', '2001:10::/28', '2001:20::/28', '2001:db8::/32', '2002::/16',
            '3fff::/20', '5f00::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8'] as $cidr) {
            if (wa_wf_ip_in_cidr($bin, $cidr)) {
                return false;
            }
        }
        return wa_wf_ip_in_cidr($bin, '2000::/3'); // global unicast only
    }
    foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24',
        '192.52.193.0/24', '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
        if (wa_wf_ip_in_cidr($bin, $cidr)) {
            return false;
        }
    }
    return true;
}

/** The host part of a URL in canonical form (lower case, no brackets, no trailing dot). */
function wa_wf_url_host(string $url): string
{
    $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? ''), '[]'));
    return rtrim($host, '.');
}

/**
 * Resolve a host name (A + AAAA, cached per request). Returns ['ips' => [...], 'error' => ?string]: error when the name
 * does not resolve or ANY address it resolves to is not public (DNS answers pointing at 127.0.0.1, 10.x … are refused).
 */
function wa_wf_resolve_host(string $host): array
{
    static $cache = [];
    $host = rtrim(strtolower(trim($host, '[]')), '.');
    if (isset($cache[$host])) {
        return $cache[$host];
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $res = wa_wf_ip_is_public($host) ? ['ips' => [$host], 'error' => null] : ['ips' => [], 'error' => 'Private, loopback and reserved IP addresses are not allowed.', 'private' => true];
        return $cache[$host] = $res;
    }
    $ips = @gethostbynamel($host) ?: [];
    if (function_exists('dns_get_record')) {
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
    }
    $ips = array_values(array_unique($ips));
    if (!$ips) {
        return $cache[$host] = ['ips' => [], 'error' => 'The host name ' . $host . ' could not be resolved.', 'unresolved' => true];
    }
    foreach ($ips as $ip) {
        if (!wa_wf_ip_is_public($ip)) {
            return $cache[$host] = ['ips' => [], 'error' => 'The host ' . $host . ' resolves to a private or reserved address (' . $ip . ') — requests to it are blocked.', 'private' => true];
        }
    }
    return $cache[$host] = ['ips' => $ips, 'error' => null];
}

/**
 * Checks for an outbound URL: https only, standard port, no credentials, no {{placeholders}} in the host, no local /
 * internal / private host. With $resolve the DNS name is also resolved and must point to public addresses only.
 * Returns an error message or null.
 */
function wa_wf_url_error(?string $url, bool $resolve = false): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return 'Enter the URL to call.';
    }
    if (strlen($url) > 1000) {
        return 'The URL is too long.';
    }
    if (preg_match('/[\s\\\\]/', $url)) {
        return 'The URL may not contain spaces or backslashes.';
    }
    if (!preg_match('#^https://#i', $url)) {
        return 'Use a full https:// URL (plain http is not allowed).';
    }
    // placeholders are allowed in the path / query, never in the host or port
    $authority = (string) preg_replace('#^https://([^/?\#]*).*$#is', '$1', $url);
    if (str_contains($authority, '{{') || str_contains($authority, '}}')) {
        return 'Variables can be used in the path or query only — the host name must be fixed.';
    }
    $parts = parse_url(preg_replace('/\{\{[^}]*\}\}/', 'x', $url));
    if (!$parts || empty($parts['host']) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        return 'Use a full https:// URL (plain http is not allowed).';
    }
    if (isset($parts['user']) || isset($parts['pass']) || str_contains($authority, '@')) {
        return 'Do not put a username or password in the URL — use a header instead.';
    }
    // "host." is the same DNS name as "host", but curl treats it as another name and would skip the pinned address
    if (str_ends_with(trim($parts['host'], '[]'), '.')) {
        return 'Remove the dot at the end of the host name.';
    }
    $host = strtolower(trim($parts['host'], '[]'));
    $isIp = (bool) filter_var($host, FILTER_VALIDATE_IP);
    if ($host === '' || $host === 'localhost' || preg_match('/\.(localhost|local|internal|intranet|lan|home|corp|localdomain|arpa)$/', $host) || (!$isIp && !str_contains($host, '.'))) {
        return 'Local and internal hosts are not allowed.';
    }
    // 127.1, 0x7f.0.0.1, 2130706433 … are IP addresses in disguise (a real top-level domain is never numeric)
    if (!$isIp && preg_match('/(^|\.)(0x[0-9a-f]*|\d+)$/i', $host)) {
        return 'Write IP addresses in the usual form (a.b.c.d) — and only public ones are allowed.';
    }
    if ($isIp && !wa_wf_ip_is_public($host)) {
        return 'Private, loopback and reserved IP addresses are not allowed.';
    }
    if (isset($parts['port']) && !in_array((int) $parts['port'], [443, 8443], true)) {
        return 'Only the standard HTTPS ports (443, 8443) are allowed.';
    }
    if ($resolve && !$isIp) {
        $r = wa_wf_resolve_host($host);
        if (!empty($r['private'])) {
            return $r['error'];
        }
    }
    return null;
}

/**
 * The URL with its authority rebuilt from the canonical host (lower case, IPv6 in brackets) and the explicit port, path
 * and query untouched — so the host curl connects to is exactly the name pinned with CURLOPT_RESOLVE.
 */
function wa_wf_canonical_url(string $url): string
{
    $host = wa_wf_url_host($url);
    $port = parse_url($url, PHP_URL_PORT);
    $authority = (str_contains($host, ':') ? '[' . $host . ']' : $host) . ($port ? ':' . (int) $port : '');
    return (string) preg_replace('#^https://[^/?\#]*#i', 'https://' . str_replace(['\\', '$'], ['\\\\', '\\$'], $authority), $url, 1);
}

/** GIMT secrets that must never leave the server in an API / webhook request (Interakt key, webhook secret, cron token). */
function wa_wf_secret_values(): array
{
    static $vals = null;
    if ($vals === null) {
        $vals = [];
        foreach (['wa_interakt_api_key', 'wa_webhook_secret'] as $k) {
            try {
                $v = function_exists('wa_setting') ? (string) wa_setting($k, '') : '';
            } catch (Throwable $e) {
                $v = '';
            }
            if (strlen($v) >= 8) {
                $vals[$k] = $v;
            }
        }
        try {
            $t = function_exists('wa_cron_token') ? (string) wa_cron_token() : '';
            if (strlen($t) >= 8) {
                $vals['cron'] = $t;
            }
            $appKey = function_exists('app_config') ? (string) (app_config('app')['key'] ?? '') : '';
            if (strlen($appKey) >= 8) {
                $vals['app_key'] = $appKey;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    return $vals;
}

/** Name of a GIMT secret found in the request parts, or null. */
function wa_wf_leaks_secret(string ...$parts): ?string
{
    $labels = ['wa_interakt_api_key' => 'the Interakt API key', 'wa_webhook_secret' => 'the WhatsApp webhook secret', 'cron' => 'the WhatsApp worker token', 'app_key' => 'the application key'];
    foreach (wa_wf_secret_values() as $k => $secret) {
        foreach ($parts as $p) {
            if ($p !== '' && (str_contains($p, $secret) || str_contains($p, rawurlencode($secret)) || str_contains($p, base64_encode($secret)))) {
                return $labels[$k] ?? 'a GIMT secret';
            }
        }
    }
    return null;
}

/**
 * Outbound HTTPS request from an API / webhook node: DNS resolved once and pinned (CURLOPT_RESOLVE, no rebinding),
 * every resolved address must be public, no proxy, no redirects, 10 s timeout, response capped at 64 KB (the transfer
 * is aborted beyond it), GIMT secrets refused. Header values are never logged.
 */
function wa_wf_http(string $method, string $url, array $headers, ?string $body, int $timeout = 10): array
{
    $t0 = microtime(true);
    $timeout = max(1, min(10, $timeout));
    $err = wa_wf_url_error($url);
    if ($err) {
        return ['ok' => false, 'status' => 0, 'error' => $err, 'duration_ms' => 0, 'blocked' => true];
    }
    if ($body !== null && strlen($body) > 65536) {
        return ['ok' => false, 'status' => 0, 'error' => 'The request body is larger than 64 KB.', 'duration_ms' => 0, 'blocked' => true];
    }
    $headerText = '';
    foreach ($headers as $k => $v) {
        $headerText .= $k . ':' . $v . "\n";
    }
    if ($leak = wa_wf_leaks_secret($url, $headerText, (string) $body)) {
        return ['ok' => false, 'status' => 0, 'error' => 'Blocked: the request contains ' . $leak . '. GIMT credentials are never sent to other servers.', 'duration_ms' => 0, 'blocked' => true];
    }
    $host = wa_wf_url_host($url);
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
    $isIp = (bool) filter_var($host, FILTER_VALIDATE_IP);
    $res = wa_wf_resolve_host($host);
    if ($res['error']) {
        return ['ok' => false, 'status' => 0, 'error' => $res['error'], 'duration_ms' => (int) ((microtime(true) - $t0) * 1000), 'blocked' => !empty($res['private'])];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'The PHP cURL extension is not available on this server.', 'duration_ms' => 0];
    }
    $lines = ['User-Agent: GIMT-WhatsApp-Automation/1.0', 'Accept: application/json, text/plain, */*'];
    $hasType = false;
    foreach ($headers as $k => $v) {
        $k = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $k);
        if ($k === '' || in_array(strtolower($k), ['host', 'content-length', 'transfer-encoding', 'connection', 'proxy-authorization', 'expect'], true)) {
            continue;
        }
        $hasType = $hasType || strtolower($k) === 'content-type';
        $lines[] = mb_substr($k, 0, 100) . ': ' . mb_substr(str_replace(["\r", "\n", "\0"], '', (string) $v), 0, 4000);
    }
    if ($body !== null && $body !== '' && !$hasType) {
        $lines[] = 'Content-Type: application/json';
    }
    $buffer = '';
    $truncated = false;
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => wa_wf_canonical_url($url),   // same host string as the CURLOPT_RESOLVE pin below
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $lines,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROXY => '',          // never through an environment proxy (it would bypass the pinned address)
        CURLOPT_NOPROXY => '*',
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_MAXFILESIZE => WA_WF_HTTP_MAX_BYTES * 4,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, &$truncated) {
            $room = WA_WF_HTTP_MAX_BYTES - strlen($buffer);
            if (strlen($chunk) > $room) {
                $buffer .= substr($chunk, 0, max(0, $room));
                $truncated = true;
                return 0; // abort the transfer: the cap is reached
            }
            $buffer .= $chunk;
            return strlen($chunk);
        },
    ];
    if (!$isIp) {
        // pin the address that passed the check, so a second DNS answer cannot point the connection elsewhere
        $ip = $res['ips'][0];
        $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
    }
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $opts[CURLOPT_PROTOCOLS_STR] = 'https';
        $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
    } elseif (defined('CURLOPT_PROTOCOLS')) {
        $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if ($body !== null && $body !== '' && $method !== 'GET') {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    $error = $ok === false && !($truncated && $errno === 23) ? curl_error($ch) : null;
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }
    unset($ch);
    $ms = (int) ((microtime(true) - $t0) * 1000);
    if ($error) {
        return ['ok' => false, 'status' => $status, 'error' => 'Request failed: ' . mb_substr($error, 0, 200), 'duration_ms' => $ms];
    }
    $json = $truncated ? null : json_decode($buffer, true);
    $okStatus = $status >= 200 && $status < 300;
    return [
        'ok' => $okStatus, 'status' => $status, 'duration_ms' => $ms, 'truncated' => $truncated,
        'body' => $buffer, 'json' => is_array($json) ? $json : null,
        'error' => $okStatus ? null : ($status >= 300 && $status < 400 ? 'The server answered HTTP ' . $status . ' (a redirect) — redirects are not followed; use the final URL.' : 'The server answered HTTP ' . $status . '.'),
    ];
}

/* ============================================================================================ validation */

/**
 * The admissions deadline that fills {{admissions_deadline}} / {{last_date}} (setting admissions_deadline, else
 * admission_last_date; a date without a time means the end of that day). valid = set, parseable and in the future.
 */
function wa_wf_deadline_info(): array
{
    $raw = '';
    $key = 'admissions_deadline';
    foreach (['admissions_deadline', 'admission_last_date'] as $k) {
        $v = trim((string) setting($k, ''));
        if ($v !== '') {
            $raw = $v;
            $key = $k;
            break;
        }
    }
    $ts = $raw !== '' ? strtotime($raw) : false;
    if ($ts && !preg_match('/\d{1,2}:\d{2}/', $raw)) {
        $ts = strtotime(date('Y-m-d 23:59:59', $ts));
    }
    $reason = $raw === '' ? 'missing' : (!$ts ? 'invalid' : ($ts <= time() ? 'past' : null));
    return [
        'setting' => $key, 'raw' => $raw, 'valid' => $reason === null, 'reason' => $reason,
        'value' => $ts ? date('d M Y', $ts) : null, 'date' => $ts ? date('Y-m-d H:i', $ts) : null,
        'where' => 'Settings › Website › Campaign landing pages › Application deadline', 'url' => '/settings?tab=website',
    ];
}

/** Why the deadline cannot fill a template variable (null when it can). */
function wa_wf_deadline_problem(?array $info = null): ?string
{
    $info = $info ?? wa_wf_deadline_info();
    return match ($info['reason']) {
        'missing' => 'the admissions deadline is not set',
        'invalid' => 'the admissions deadline "' . $info['raw'] . '" is not a valid date',
        'past' => 'the admissions deadline (' . $info['value'] . ') has already passed',
        default => null,
    };
}

/** Template keys of a node that are filled from the admissions deadline (mapped explicitly or left on auto). */
function wa_wf_deadline_keys(array $node, array $templateKeys): array
{
    $keys = [];
    $vars = is_array($node['data']['vars'] ?? null) ? $node['data']['vars'] : [];
    foreach ($vars as $k => $expr) {
        if (is_string($expr) && preg_match('/\{\{\s*(admissions_deadline|last_date)\s*\}\}/', $expr)) {
            $keys[] = (string) $k;
        }
    }
    foreach (['last_date', 'admissions_deadline'] as $k) {
        if (in_array($k, $templateKeys, true) && trim((string) ($vars[$k] ?? '')) === '') {
            $keys[] = $k;
        }
    }
    return array_values(array_unique($keys));
}

/** Variable keys of a template (header, body and button parts of its stored `variables` mapping). */
function wa_wf_template_keys(array $tpl): array
{
    $keys = [];
    $v = wa_wf_decode($tpl['variables'] ?? null, []);
    foreach (['header', 'body', 'buttons'] as $part) {
        foreach ((array) ($v[$part] ?? []) as $ref) {
            if (is_array($ref) && !empty($ref['key'])) {
                $keys[] = (string) $ref['key'];
            }
        }
    }
    return array_values(array_unique($keys));
}

/** Variables computed from the moment a step runs — never a real appointment, exam or due date. */
function wa_wf_relative_vars(): array
{
    return ['today', 'tomorrow', 'yesterday', 'date_today', 'now'];
}

/** Template variables that hold a date or a time (counselling_date, counselling_time, interview_date, exam_date, due_date …). */
function wa_wf_is_datetime_key(string $key): bool
{
    return (bool) preg_match('/(^|_)(date|time|datetime|slot)$/i', $key);
}

/** Institute-wide dates an admin may type as one fixed value for everyone (the last date to apply, an event day …). */
function wa_wf_fixed_date_keys(): array
{
    return ['last_date', 'admissions_deadline', 'event_date', 'holiday_date', 'reopen_date'];
}

/**
 * Date / time variables of a template step that are not mapped to real data: a relative value ({{tomorrow}}) or, for
 * personal dates and times (counselling, interview, exam, due date …), a typed one ("11:00 AM"). Every contact would
 * be told the same made-up slot. Empty values are fine (filled from the contact / event when sending; a missing value
 * fails the send instead of inventing one). Returns [key => problem].
 */
function wa_wf_datetime_var_problems(array $node, array $templateKeys): array
{
    $out = [];
    $vars = is_array($node['data']['vars'] ?? null) ? $node['data']['vars'] : [];
    foreach ($templateKeys as $key) {
        $expr = trim((string) (is_scalar($vars[$key] ?? null) ? $vars[$key] : ''));
        if ($expr === '' || !wa_wf_is_datetime_key((string) $key)) {
            continue;
        }
        preg_match_all('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', $expr, $m);
        $relative = array_values(array_intersect(array_map('strtolower', $m[1]), wa_wf_relative_vars()));
        if ($relative) {
            $out[$key] = '{{' . $key . '}} is set to {{' . $relative[0] . '}} (a date relative to when the step runs)';
            continue;
        }
        $typed = (string) preg_replace('/[\s,.:;\/()\x{2013}\x{2014}-]+/u', '', (string) preg_replace('/\{\{[^}]*\}\}/', '', $expr));
        if (!in_array($key, wa_wf_fixed_date_keys(), true) && (!$m[1] || $typed !== '')) {
            $out[$key] = '{{' . $key . '}} is a typed value ("' . mb_substr($expr, 0, 40) . '")';
        }
    }
    return $out;
}

/** Builder / runner message for wa_wf_datetime_var_problems(). */
function wa_wf_datetime_message(array $problems): string
{
    return 'This step would promise a date or time that is not real data: ' . implode('; ', $problems)
        . '. Map it to a real data variable (e.g. {{payload.' . array_key_first($problems) . '}} from the event) or leave it empty to fill it from the contact / event — otherwise every contact is told the same made-up slot.';
}

/**
 * Active Event Manager event for the workflow's trigger that already sends a template ({name, template, template_label})
 * or null. A workflow that also sends a template on the same event makes contacts get two messages at once.
 */
function wa_wf_event_message(string $code): ?array
{
    if ($code === '' || $code === 'manual') {
        return null;
    }
    try {
        $row = db_row("SELECT e.name, t.name AS template, COALESCE(NULLIF(t.display_name, ''), t.name) AS template_label
                       FROM wa_events e JOIN wa_templates t ON t.id = e.template_id WHERE e.code = ? AND e.is_active = 1 LIMIT 1", [$code]);
    } catch (Throwable $e) {
        $row = null;
    }
    return $row ?: null;
}

/** Parse a schedule bound of the trigger ('Y-m-d H:i' / 'Y-m-d\TH:i'); null when empty or invalid. */
function wa_wf_schedule_ts($v): ?int
{
    $v = trim(str_replace('T', ' ', (string) $v));
    if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}( \d{1,2}:\d{2}(:\d{2})?)?$/', $v)) {
        return null;
    }
    $ts = strtotime($v);
    return $ts ?: null;
}

/** Is the workflow's active window (trigger schedule) open at $now? Returns null when open, else the reason. */
function wa_wf_schedule_block(array $trigger, ?int $now = null): ?string
{
    $now = $now ?? time();
    $from = wa_wf_schedule_ts($trigger['data']['active_from'] ?? '');
    $until = wa_wf_schedule_ts($trigger['data']['active_until'] ?? '');
    if ($from && $now < $from) {
        return 'scheduled: starts ' . date('d M Y, h:i A', $from);
    }
    if ($until && $now > $until) {
        return 'scheduled: ended ' . date('d M Y, h:i A', $until);
    }
    return null;
}

/**
 * Validate a definition. Returns issues [{level: error|warning, node: ?id, message, code?, server?}]. Errors block
 * activation; warnings are shown in the builder only. $opts['resolve']: also resolve API / webhook host names (DNS) —
 * those issues carry server = true because the browser cannot check them.
 */
function wa_wf_validate(array $def, array $opts = []): array
{
    $issues = [];
    $add = function (string $level, ?string $node, string $msg, array $extra = []) use (&$issues) {
        $issues[] = ['level' => $level, 'node' => $node, 'message' => $msg] + $extra;
    };
    $resolve = !empty($opts['resolve']);
    $nodes = wa_wf_node_map($def);
    $edges = wa_wf_edge_map($def);
    if (count($def['nodes']) > WA_WF_MAX_NODES) {
        $add('error', null, 'A workflow can have at most ' . WA_WF_MAX_NODES . ' steps.');
    }
    $triggers = array_values(array_filter($def['nodes'], fn ($n) => $n['type'] === 'trigger'));
    if (count($triggers) === 0) {
        $add('error', null, 'Add an Event Trigger — every workflow starts with exactly one trigger.');
    } elseif (count($triggers) > 1) {
        foreach (array_slice($triggers, 1) as $t) {
            $add('error', $t['id'], 'Only one Event Trigger is allowed per workflow. Delete the extra trigger.');
        }
    }

    // templates / stages / users referenced by nodes (one query each)
    $tplNames = [];
    $userIds = [];
    foreach ($def['nodes'] as $n) {
        if ($n['type'] === 'template' && trim((string) ($n['data']['template'] ?? '')) !== '') {
            $tplNames[] = trim((string) $n['data']['template']);
        }
        if ($n['type'] === 'assign') {
            if (!empty($n['data']['user_id'])) {
                $userIds[] = (int) $n['data']['user_id'];
            }
            foreach ((array) ($n['data']['pool'] ?? []) as $u) {
                $userIds[] = (int) $u;
            }
        }
    }
    $templates = [];
    if ($tplNames) {
        $tplNames = array_values(array_unique($tplNames));
        $in = implode(',', array_fill(0, count($tplNames), '?'));
        foreach (db_all("SELECT id, name, status, variables FROM wa_templates WHERE name IN ($in) OR CAST(id AS CHAR) IN ($in) ORDER BY CASE status WHEN 'approved' THEN 0 ELSE 1 END, id", array_merge($tplNames, $tplNames)) as $t) {
            $t['keys'] = wa_wf_template_keys($t);
            // several language versions share a name: the approved one wins
            $templates[$t['name']] = $templates[$t['name']] ?? $t;
            $templates[(string) $t['id']] = $t;
        }
    }
    $deadline = null;
    $users = [];
    if ($userIds) {
        $userIds = array_values(array_unique($userIds));
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $users = db_pairs("SELECT id, status FROM users WHERE id IN ($in)", $userIds);
    }
    $stages = wa_wf_stages();

    foreach ($def['nodes'] as $n) {
        $d = $n['data'];
        $id = $n['id'];
        switch ($n['type']) {
            case 'trigger':
                $ev = trim((string) ($d['event'] ?? ''));
                if ($ev === '') {
                    $add('error', $id, 'Choose the event that starts this workflow (or "Manual enrolment").');
                } elseif ($ev !== 'manual' && !preg_match('/^[A-Z][A-Z0-9_]{2,79}$/', $ev)) {
                    $add('error', $id, 'The trigger event code is not valid.');
                }
                foreach ((array) ($d['filters'] ?? []) as $r) {
                    if ($e = wa_wf_rule_error($r)) {
                        $add('error', $id, 'Trigger filter: ' . $e);
                    }
                }
                // optional active window (workflow scheduling)
                $fromRaw = trim((string) ($d['active_from'] ?? ''));
                $untilRaw = trim((string) ($d['active_until'] ?? ''));
                $from = wa_wf_schedule_ts($fromRaw);
                $until = wa_wf_schedule_ts($untilRaw);
                if ($fromRaw !== '' && !$from) {
                    $add('error', $id, 'Schedule: "Active from" is not a valid date and time.');
                }
                if ($untilRaw !== '' && !$until) {
                    $add('error', $id, 'Schedule: "Active until" is not a valid date and time.');
                }
                if ($from && $until && $until <= $from) {
                    $add('error', $id, 'Schedule: "Active until" must be after "Active from".');
                } elseif ($until && $until < time()) {
                    $add('warning', $id, 'Schedule: the active window ended on ' . date('d M Y, h:i A', $until) . ' — no new runs start.', ['code' => 'schedule']);
                } elseif ($from && $from > time()) {
                    $add('warning', $id, 'Schedule: new runs start only from ' . date('d M Y, h:i A', $from) . '.', ['code' => 'schedule']);
                }
                break;
            case 'template':
                $t = trim((string) ($d['template'] ?? ''));
                if ($t === '') {
                    $add('error', $id, 'Pick the WhatsApp template to send.');
                } elseif (!isset($templates[$t])) {
                    $add('error', $id, 'Template "' . $t . '" does not exist any more. Pick another template.');
                } else {
                    if (($templates[$t]['status'] ?? '') !== 'approved') {
                        $add('warning', $id, 'Template "' . $t . '" is ' . $templates[$t]['status'] . ' — WhatsApp only delivers approved templates.');
                    }
                    // a counselling / interview / exam / due date must come from real data, never {{tomorrow}} or a typed slot
                    if ($fake = wa_wf_datetime_var_problems($n, $templates[$t]['keys'] ?? [])) {
                        $add('error', $id, wa_wf_datetime_message($fake), ['code' => 'fake_datetime']);
                    }
                    // data dependency: {{last_date}} / {{admissions_deadline}} come from the admissions deadline setting
                    $dKeys = wa_wf_deadline_keys($n, $templates[$t]['keys'] ?? []);
                    if ($dKeys) {
                        $deadline = $deadline ?? wa_wf_deadline_info();
                        if ($problem = wa_wf_deadline_problem($deadline)) {
                            $add('warning', $id, 'This step fills ' . implode(', ', $dKeys) . ' from the admissions deadline, but ' . $problem
                                . '. Set it under ' . $deadline['where'] . ' (or type a date in the variable) — otherwise this step fails for every contact.', ['code' => 'deadline']);
                        }
                    }
                }
                break;
            case 'message':
                if (trim((string) ($d['text'] ?? '')) === '' && trim((string) ($d['media_url'] ?? '')) === '') {
                    $add('error', $id, 'Write the message text.');
                } elseif (mb_strlen((string) ($d['text'] ?? '')) > 4096) {
                    $add('error', $id, 'A WhatsApp message can have at most 4096 characters.');
                }
                if (in_array($d['media_type'] ?? 'none', ['image', 'document', 'video'], true)) {
                    $mu = trim((string) ($d['media_url'] ?? ''));
                    if (!preg_match('#^https://#i', $mu)) {
                        $add('error', $id, 'The attachment needs a public https:// URL.');
                    }
                }
                $add('warning', $id, 'Free-text messages are only delivered inside the 24-hour window (after the contact wrote to you); otherwise the step is skipped.');
                break;
            case 'delay':
            case 'wait_reply':
                $v = (int) ($d['value'] ?? 0);
                $unit = (string) ($d['unit'] ?? '');
                if ($v < 1 || !in_array($unit, ['minutes', 'hours', 'days'], true)) {
                    $add('error', $id, 'Enter how long to wait (a whole number of minutes, hours or days).');
                } elseif (wa_wf_seconds($v, $unit) > 90 * 86400) {
                    $add('error', $id, 'Waits longer than 90 days are not allowed.');
                }
                break;
            case 'condition':
            case 'ifelse':
                $rules = array_values(array_filter((array) ($d['rules'] ?? []), 'is_array'));
                if (!$rules) {
                    $add('error', $id, 'Add at least one rule to check.');
                }
                foreach ($rules as $r) {
                    if ($e = wa_wf_rule_error($r)) {
                        $add('error', $id, $e);
                    }
                }
                break;
            case 'tag':
                $tags = array_filter(array_map(fn ($t) => trim((string) $t), (array) ($d['tags'] ?? [])));
                if (!$tags) {
                    $add('error', $id, 'Choose at least one tag.');
                }
                foreach ($tags as $t) {
                    if (mb_strlen($t) > 60) {
                        $add('error', $id, 'Tag names can have at most 60 characters.');
                    }
                }
                break;
            case 'stage':
                $st = trim((string) ($d['stage'] ?? ''));
                $ls = trim((string) ($d['lead_status'] ?? ''));
                if ($st === '' && $ls === '') {
                    $add('error', $id, 'Choose the stage (and/or lead status) to set.');
                }
                if ($st !== '' && !isset($stages[$st])) {
                    $add('error', $id, 'Stage "' . $st . '" does not exist.');
                }
                if ($ls !== '' && !isset(wa_wf_lead_statuses()[$ls])) {
                    $add('error', $id, 'Lead status "' . $ls . '" is not allowed.');
                }
                break;
            case 'assign':
                $mode = (string) ($d['mode'] ?? 'round_robin');
                if ($mode === 'specific') {
                    $uid = (int) ($d['user_id'] ?? 0);
                    if (!$uid) {
                        $add('error', $id, 'Choose the counsellor to assign.');
                    } elseif (($users[$uid] ?? '') !== 'active') {
                        $add('error', $id, 'The chosen counsellor is not an active user.');
                    }
                } elseif ($mode !== 'round_robin') {
                    $add('error', $id, 'Choose how to assign the counsellor.');
                }
                break;
            case 'api':
            case 'webhook':
                $url = (string) ($d['url'] ?? '');
                if ($e = wa_wf_url_error($url)) {
                    $add('error', $id, $e);
                } elseif ($resolve) {
                    $r = wa_wf_resolve_host(wa_wf_url_host((string) preg_replace('/\{\{[^}]*\}\}/', 'x', $url)));
                    if (!empty($r['private'])) {
                        $add('error', $id, $r['error'], ['code' => 'ssrf', 'server' => true]);
                    } elseif (!empty($r['unresolved'])) {
                        $add('warning', $id, $r['error'] . ' Check the domain — the request fails until it resolves.', ['code' => 'dns', 'server' => true]);
                    }
                }
                if (preg_match('/[?&][^=&#]*(key|token|secret|password|passwd|signature|auth)[^=&#]*=[^&#{]/i', $url)) {
                    $add('warning', $id, 'The URL seems to carry a key or token in its query string, which everyone who can view this workflow sees. Send secrets in a header instead — header values are hidden after saving.', ['code' => 'secret_in_url']);
                }
                $headerText = '';
                if ($n['type'] === 'api') {
                    if (!in_array(strtoupper((string) ($d['method'] ?? 'POST')), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                        $add('error', $id, 'Choose a valid HTTP method.');
                    }
                    $headers = array_values(array_filter((array) ($d['headers'] ?? []), 'is_array'));
                    if (count($headers) > 20) {
                        $add('error', $id, 'At most 20 headers are allowed.');
                    }
                    foreach ($headers as $h) {
                        $hk = trim((string) ($h['key'] ?? ''));
                        if ($hk !== '' && !preg_match('/^[A-Za-z0-9\-_]{1,100}$/', $hk)) {
                            $add('error', $id, 'Header names may only use letters, digits, - and _.');
                            break;
                        }
                        $headerText .= $hk . ':' . ($h['value'] ?? '') . "\n";
                    }
                    if (mb_strlen((string) ($d['body'] ?? '')) > 10000) {
                        $add('error', $id, 'The request body can have at most 10,000 characters.');
                    }
                    if (($sv = trim((string) ($d['save_as'] ?? ''))) !== '' && !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $sv)) {
                        $add('error', $id, 'The response variable name may only use lowercase letters, digits and _.');
                    }
                }
                if ($leak = wa_wf_leaks_secret($url, $headerText, (string) ($d['body'] ?? ''))) {
                    $add('error', $id, 'This request contains ' . $leak . ' — GIMT credentials are never sent to other servers. Remove it.', ['code' => 'secret']);
                }
                break;
        }
    }

    // graph: the trigger leads somewhere, every step is reachable, branches, cycles
    $trigger = $triggers[0] ?? null;
    if ($trigger) {
        if (empty($edges[$trigger['id']]['next'])) {
            $add('error', $trigger['id'], 'Connect the trigger to the first step.');
        }
        $seen = [$trigger['id'] => true];
        $queue = [$trigger['id']];
        while ($queue) {
            $cur = array_shift($queue);
            foreach ($edges[$cur] ?? [] as $to) {
                if (!isset($seen[$to])) {
                    $seen[$to] = true;
                    $queue[] = $to;
                }
            }
        }
        foreach ($def['nodes'] as $n) {
            if (!isset($seen[$n['id']]) && $n['type'] !== 'trigger') {
                $add('error', $n['id'], 'This step is not connected to the trigger — connect it or delete it.');
            }
        }
    }
    foreach ($def['nodes'] as $n) {
        if (in_array($n['type'], ['condition', 'ifelse', 'wait_reply'], true)) {
            foreach (wa_wf_ports($n['type']) as $port) {
                if (empty($edges[$n['id']][$port])) {
                    $add('warning', $n['id'], 'Nothing is connected to the "' . wa_wf_port_label($port) . '" branch — runs that take it end there.');
                }
            }
        }
    }
    // a cycle is only allowed when it passes through a Delay or Wait for Reply (otherwise it would spin forever)
    $instant = [];
    foreach ($def['edges'] as $e) {
        if (!in_array($nodes[$e['from']]['type'] ?? '', ['delay', 'wait_reply'], true) && !in_array($nodes[$e['to']]['type'] ?? '', ['delay', 'wait_reply'], true)) {
            $instant[$e['from']][] = $e['to'];
        }
    }
    $state = [];
    $cycleNode = null;
    $dfs = function ($v) use (&$dfs, &$state, $instant, &$cycleNode) {
        $state[$v] = 1;
        foreach ($instant[$v] ?? [] as $w) {
            if (($state[$w] ?? 0) === 1) {
                $cycleNode = $w;
                return true;
            }
            if (($state[$w] ?? 0) === 0 && $dfs($w)) {
                return true;
            }
        }
        $state[$v] = 2;
        return false;
    };
    $instantCycle = false;
    foreach (array_keys($nodes) as $v) {
        if (($state[$v] ?? 0) === 0 && $dfs($v)) {
            $add('error', $cycleNode, 'This loop has no Delay or Wait for Reply step — it would repeat forever. Add a wait inside the loop.', ['code' => 'loop']);
            $instantCycle = true;
            break;
        }
    }
    // loops through a wait are allowed, but bounded: say so where it starts
    if (!$instantCycle) {
        $all = [];
        foreach ($def['edges'] as $e) {
            $all[$e['from']][] = $e['to'];
        }
        $state = [];
        $cycleNode = null;
        $dfs2 = function ($v) use (&$dfs2, &$state, $all, &$cycleNode) {
            $state[$v] = 1;
            foreach ($all[$v] ?? [] as $w) {
                if (($state[$w] ?? 0) === 1) {
                    $cycleNode = $w;
                    return true;
                }
                if (($state[$w] ?? 0) === 0 && $dfs2($w)) {
                    return true;
                }
            }
            $state[$v] = 2;
            return false;
        };
        foreach (array_keys($nodes) as $v) {
            if (($state[$v] ?? 0) === 0 && $dfs2($v)) {
                $add('warning', $cycleNode, 'This step is part of a loop. Each step runs at most ' . WA_WF_MAX_NODE_VISITS . ' times per contact (' . WA_WF_MAX_STEPS_PER_RUN . ' steps per run) — then the run stops as failed.', ['code' => 'loop']);
                break;
            }
        }
    }
    // a workflow that changes the stage while being started by stage changes never restarts itself (loop protection)
    if ($trigger && ($trigger['data']['event'] ?? '') === 'WA_STAGE_CHANGED') {
        foreach ($def['nodes'] as $n) {
            if ($n['type'] === 'stage' && trim((string) ($n['data']['stage'] ?? '')) !== '') {
                $add('warning', $n['id'], 'This step changes the stage, and the workflow is started by stage changes. Loop protection: a stage change made by this workflow (or by a workflow it started) never starts it again.', ['code' => 'self_trigger']);
                break;
            }
        }
    }
    // the Event Manager may already message the contact on the same event (e.g. the welcome template on a new enquiry)
    $sends = array_values(array_filter($def['nodes'], fn ($n) => $n['type'] === 'template' && trim((string) ($n['data']['template'] ?? '')) !== ''));
    if ($trigger && $sends && ($evMsg = wa_wf_event_message(trim((string) ($trigger['data']['event'] ?? ''))))) {
        $same = array_values(array_filter($sends, fn ($n) => trim((string) $n['data']['template']) === $evMsg['template']));
        $add('warning', ($same[0] ?? $sends[0])['id'], $same
            ? 'The Event Manager already sends "' . $evMsg['template_label'] . '" when "' . $evMsg['name'] . '" happens, and this step sends the same template — every contact would get it twice. Remove this step, or switch off that event\'s message in WhatsApp › Event Manager.'
            : 'The Event Manager also sends "' . $evMsg['template_label'] . '" when "' . $evMsg['name'] . '" happens, so contacts get that message in addition to this workflow\'s messages. Make sure the two do not repeat each other (WhatsApp › Event Manager).',
            ['code' => 'duplicate_message']);
    }
    return $issues;
}

function wa_wf_has_errors(array $issues): bool
{
    foreach ($issues as $i) {
        if ($i['level'] === 'error') {
            return true;
        }
    }
    return false;
}

function wa_wf_port_label(string $port): string
{
    return ['next' => 'Next', 'true' => 'Yes', 'false' => 'No', 'replied' => 'Replied', 'timeout' => 'No reply'][$port] ?? ucfirst($port);
}

function wa_wf_rule_error($r): ?string
{
    if (!is_array($r)) {
        return 'A rule is incomplete.';
    }
    $field = trim((string) ($r['field'] ?? ''));
    $op = (string) ($r['op'] ?? '');
    if ($field === '' || !preg_match('/^(contact|payload|reply|vars)\.[A-Za-z0-9_.]{1,80}$/', $field)) {
        return 'Choose the field each rule checks.';
    }
    if (!isset(wa_wf_condition_ops()[$op])) {
        return 'Choose how each rule compares.';
    }
    if (!in_array($op, ['is_empty', 'not_empty', 'is_true', 'is_false'], true) && trim((string) ($r['value'] ?? '')) === '') {
        return 'Enter the value each rule compares with.';
    }
    return null;
}

function wa_wf_seconds(int $value, string $unit): int
{
    return $value * (['minutes' => 60, 'hours' => 3600, 'days' => 86400][$unit] ?? 60);
}

/* ============================================================================================ workflows (CRUD helpers) */

function wa_wf_get(int $id): ?array
{
    return db_row('SELECT * FROM wa_workflows WHERE id = ?', [$id]);
}

/** Starter definitions (create from template + seeds). */
function wa_wf_starters(): array
{
    $n = fn (string $id, string $type, int $x, int $y, array $data = []) => ['id' => $id, 'type' => $type, 'x' => $x, 'y' => $y, 'data' => $data];
    $e = fn (string $from, string $to, string $label = 'next') => ['from' => $from, 'to' => $to, 'label' => $label];
    return [
        'new_admission_enquiry' => [
            'name' => 'New Admission Enquiry',
            'description' => 'Links every new enquiry to a WhatsApp contact and an ERP enquiry ID, welcomes it, shares course information, and either hands interested leads to a counsellor (who is notified to call them back) or runs two follow-ups.',
            'trigger_event' => 'GIMT_ENQUIRY_RECEIVED',
            'business_hours_only' => 0,
            'definition' => [
                'nodes' => [
                    $n('trigger', 'trigger', 260, 0, ['event' => 'GIMT_ENQUIRY_RECEIVED', 'label' => 'New admission enquiry']),
                    $n('contact', 'contact', 260, 130, ['label' => 'Create / link contact + enquiry ID', 'contact_type' => 'lead', 'stage' => 'new_enquiry', 'create_enquiry' => true]),
                    $n('welcome', 'template', 260, 260, ['label' => 'Welcome message', 'template' => 'enquiry_received', 'vars' => (object) []]),
                    $n('wait10', 'delay', 260, 390, ['value' => 10, 'unit' => 'minutes']),
                    $n('course', 'template', 260, 520, ['label' => 'Send course information', 'template' => 'brochure_requested', 'vars' => ['brochure_link' => '{{site_url}}/programs']]),
                    $n('reply', 'wait_reply', 260, 650, ['label' => 'Wait 24 hours & check reply', 'value' => 24, 'unit' => 'hours', 'since' => 'run']),
                    $n('assign', 'assign', 0, 800, ['label' => 'Assign counsellor', 'mode' => 'round_robin', 'keep_existing' => true, 'sync_enquiry' => true, 'notify' => true]),
                    // no date or time is promised: the counsellor (notified with the lead's name, phone and course) calls back
                    $n('callback', 'template', 0, 930, ['label' => 'Counsellor will call back', 'template' => 'callback_requested', 'vars' => (object) []]),
                    $n('end_yes', 'end', 0, 1060, ['label' => 'End — handed to counsellor']),
                    $n('follow1', 'template', 520, 800, ['label' => 'Follow-up reminder', 'template' => 'admission_open_campaign', 'vars' => (object) []]),
                    $n('wait48', 'delay', 520, 930, ['value' => 48, 'unit' => 'hours']),
                    $n('final', 'template', 520, 1060, ['label' => 'Final follow-up', 'template' => 'admission_closing_reminder', 'vars' => ['last_date' => '{{admissions_deadline}}']]),
                    $n('end_no', 'end', 520, 1190, ['label' => 'End']),
                ],
                'edges' => [
                    $e('trigger', 'contact'), $e('contact', 'welcome'), $e('welcome', 'wait10'), $e('wait10', 'course'), $e('course', 'reply'),
                    $e('reply', 'assign', 'replied'), $e('assign', 'callback'), $e('callback', 'end_yes'),
                    $e('reply', 'follow1', 'timeout'), $e('follow1', 'wait48'), $e('wait48', 'final'), $e('final', 'end_no'),
                ],
            ],
        ],
        'payment_pending' => [
            'name' => 'Admission Fee Payment Pending — Nudges',
            'description' => 'When an applicant reaches "Payment Pending", sends the payment reminder, checks again after two and five days, and escalates to the counsellor if the fee is still unpaid.',
            'trigger_event' => 'WA_STAGE_CHANGED',
            'business_hours_only' => 1,
            'definition' => [
                'nodes' => [
                    $n('trigger', 'trigger', 260, 0, ['event' => 'WA_STAGE_CHANGED', 'label' => 'Stage changed to Payment Pending', 'filters' => [['field' => 'payload.to', 'op' => 'equals', 'value' => 'payment_pending']]]),
                    $n('remind1', 'template', 260, 130, ['label' => 'Payment reminder', 'template' => 'admission_deadline_reminder', 'vars' => ['last_date' => '{{admissions_deadline}}']]),
                    $n('wait2d', 'delay', 260, 260, ['value' => 2, 'unit' => 'days']),
                    $n('check1', 'condition', 260, 390, ['label' => 'Still payment pending?', 'match' => 'all', 'rules' => [['field' => 'contact.stage', 'op' => 'equals', 'value' => 'payment_pending']]]),
                    $n('remind2', 'template', 0, 530, ['label' => 'Second reminder', 'template' => 'admission_deadline_reminder', 'vars' => ['last_date' => '{{admissions_deadline}}']]),
                    $n('wait3d', 'delay', 0, 660, ['value' => 3, 'unit' => 'days']),
                    $n('check2', 'ifelse', 0, 790, ['label' => 'Paid yet?', 'rules' => [['field' => 'contact.stage', 'op' => 'equals', 'value' => 'payment_pending']]]),
                    $n('tag', 'tag', -260, 930, ['label' => 'Tag for fee follow-up', 'action' => 'add', 'tags' => ['Fee follow-up']]),
                    $n('assign', 'assign', -260, 1060, ['label' => 'Escalate to counsellor', 'mode' => 'round_robin', 'keep_existing' => true, 'notify' => true]),
                    $n('end1', 'end', -260, 1190, ['label' => 'End — escalated']),
                    $n('end2', 'end', 260, 930, ['label' => 'End — paid']),
                    $n('end3', 'end', 520, 530, ['label' => 'End — paid']),
                ],
                'edges' => [
                    $e('trigger', 'remind1'), $e('remind1', 'wait2d'), $e('wait2d', 'check1'), $e('check1', 'remind2', 'true'), $e('check1', 'end3', 'false'),
                    $e('remind2', 'wait3d'), $e('wait3d', 'check2'), $e('check2', 'tag', 'true'), $e('check2', 'end2', 'false'), $e('tag', 'assign'), $e('assign', 'end1'),
                ],
            ],
        ],
        'application_incomplete' => [
            'name' => 'Application Incomplete Reminder',
            'description' => 'Reminds applicants who started but did not submit the online application, then offers counsellor help to those who reply.',
            'trigger_event' => 'WA_STAGE_CHANGED',
            'business_hours_only' => 1,
            'definition' => [
                'nodes' => [
                    $n('trigger', 'trigger', 260, 0, ['event' => 'WA_STAGE_CHANGED', 'label' => 'Stage changed to Application Started', 'filters' => [['field' => 'payload.to', 'op' => 'equals', 'value' => 'application_started']]]),
                    $n('wait1d', 'delay', 260, 130, ['value' => 1, 'unit' => 'days']),
                    $n('check', 'condition', 260, 260, ['label' => 'Application still incomplete?', 'match' => 'all', 'rules' => [['field' => 'contact.stage', 'op' => 'equals', 'value' => 'application_started']]]),
                    $n('remind', 'template', 0, 400, ['label' => 'Complete your application', 'template' => 'application_incomplete', 'vars' => ['last_date' => '{{admissions_deadline}}']]),
                    $n('reply', 'wait_reply', 0, 530, ['label' => 'Wait 48 hours for a reply', 'value' => 48, 'unit' => 'hours', 'since' => 'node']),
                    $n('assign', 'assign', -260, 670, ['label' => 'Assign counsellor to help', 'mode' => 'round_robin', 'keep_existing' => true, 'notify' => true]),
                    $n('end1', 'end', -260, 800, ['label' => 'End — counsellor helping']),
                    $n('tag', 'tag', 260, 670, ['label' => 'Tag incomplete application', 'action' => 'add', 'tags' => ['Application incomplete']]),
                    $n('end2', 'end', 260, 800, ['label' => 'End']),
                    $n('end3', 'end', 520, 400, ['label' => 'End — submitted']),
                ],
                'edges' => [
                    $e('trigger', 'wait1d'), $e('wait1d', 'check'), $e('check', 'remind', 'true'), $e('check', 'end3', 'false'), $e('remind', 'reply'),
                    $e('reply', 'assign', 'replied'), $e('assign', 'end1'), $e('reply', 'tag', 'timeout'), $e('tag', 'end2'),
                ],
            ],
        ],
    ];
}

function wa_wf_blank_definition(): array
{
    return ['nodes' => [['id' => 'trigger', 'type' => 'trigger', 'x' => 260, 'y' => 0, 'data' => ['event' => '']]], 'edges' => []];
}

/** Trigger columns derived from the trigger node. */
function wa_wf_trigger_columns(array $def): array
{
    $t = wa_wf_trigger_node($def);
    $ev = trim((string) ($t['data']['event'] ?? ''));
    if ($ev === '' || $ev === 'manual') {
        return ['trigger_type' => 'manual', 'trigger_event' => null, 'trigger_config' => wa_wf_json(['filters' => []])];
    }
    return ['trigger_type' => 'event', 'trigger_event' => mb_substr($ev, 0, 80), 'trigger_config' => wa_wf_json(['filters' => array_values((array) ($t['data']['filters'] ?? []))])];
}

/** Create a workflow from a starter key or blank. Returns the id. */
function wa_wf_create(string $name, ?string $description, ?string $starter = null, string $status = 'draft'): int
{
    $s = $starter ? (wa_wf_starters()[$starter] ?? null) : null;
    $def = wa_wf_normalize_definition($s ? $s['definition'] : wa_wf_blank_definition());
    $id = db_insert('wa_workflows', [
        'name' => mb_substr($name, 0, 150), 'description' => $description,
        'definition' => wa_wf_json($def), 'status' => $status,
        'business_hours_only' => (int) ($s['business_hours_only'] ?? 0), 'allow_reentry' => 0, 'version' => 1,
        'created_by' => function_exists('user_id') ? user_id() : null, 'updated_by' => function_exists('user_id') ? user_id() : null,
    ] + wa_wf_trigger_columns($def));
    wa_wf_reset_cache();
    return $id;
}

/** Recount the run statistics of a workflow (test runs excluded). Keeps updated_at = last edit. */
function wa_wf_refresh_stats(int $wfId): void
{
    db_exec("UPDATE wa_workflows w SET
               runs_total = (SELECT COUNT(*) FROM wa_workflow_runs r WHERE r.workflow_id = w.id AND r.is_test = 0),
               runs_completed = (SELECT COUNT(*) FROM wa_workflow_runs r WHERE r.workflow_id = w.id AND r.is_test = 0 AND r.status = 'completed'),
               runs_failed = (SELECT COUNT(*) FROM wa_workflow_runs r WHERE r.workflow_id = w.id AND r.is_test = 0 AND r.status = 'failed'),
               last_run_at = (SELECT MAX(r.started_at) FROM wa_workflow_runs r WHERE r.workflow_id = w.id AND r.is_test = 0),
               updated_at = updated_at
             WHERE w.id = ?", [$wfId]);
}

function wa_wf_reset_cache(): void
{
    $GLOBALS['__wa_wf_active'] = null;
}

/** Active workflows grouped by trigger event (cached per request). */
function wa_wf_active_by_event(): array
{
    if (!isset($GLOBALS['__wa_wf_active']) || !is_array($GLOBALS['__wa_wf_active'])) {
        $map = [];
        try {
            foreach (db_all("SELECT * FROM wa_workflows WHERE status = 'active' AND trigger_type = 'event' AND trigger_event IS NOT NULL ORDER BY id") as $wf) {
                $map[$wf['trigger_event']][] = $wf;
            }
        } catch (Throwable $e) {
            $map = [];
        }
        $GLOBALS['__wa_wf_active'] = $map;
    }
    return $GLOBALS['__wa_wf_active'];
}

/* ============================================================================================ variables & rules */

/** Flatten nested arrays into dot keys (payload.course.name => value). */
function wa_wf_flatten(array $a, string $prefix = '', int $depth = 0): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v) && $depth < 4) {
            $out += wa_wf_flatten($v, $key, $depth + 1);
        } elseif (is_scalar($v) || $v === null) {
            $out[$key] = $v === null ? '' : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
        }
    }
    return $out;
}

/** Every value a message, template or request may use: CRM variables + event payload + reply + API results. */
function wa_wf_vars(array $ctx, ?array $contact, array $wf = [], array $run = []): array
{
    $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
    $vars = [];
    if ($contact && function_exists('wa_variables_for_contact')) {
        try {
            $vars = (array) wa_variables_for_contact($contact, $payload);
        } catch (Throwable $e) {
            $vars = [];
        }
    }
    // payload keys fill what the CRM did not resolve; also reachable as payload.key
    foreach (wa_wf_flatten($payload) as $k => $v) {
        if (!isset($vars[$k]) || $vars[$k] === '' || $vars[$k] === null) {
            $vars[$k] = $v;
        }
        $vars['payload.' . $k] = $v;
    }
    if ($contact) {
        foreach (['name', 'phone', 'email', 'program_name', 'application_no', 'city', 'state', 'stage', 'batch'] as $k) {
            $vars['contact.' . $k] = (string) ($contact[$k] ?? '');
        }
        $vars['student_name'] = $vars['student_name'] ?? ($contact['name'] ?? '');
        $vars['name'] = $vars['name'] ?? ($contact['name'] ?? '');
    }
    $reply = is_array($ctx['last_reply'] ?? null) ? $ctx['last_reply'] : [];
    $vars['reply.text'] = (string) ($reply['text'] ?? '');
    $vars['last_reply'] = (string) ($reply['text'] ?? '');
    foreach (wa_wf_flatten(is_array($ctx['vars'] ?? null) ? $ctx['vars'] : []) as $k => $v) {
        $vars[$k] = $v;
        $vars['vars.' . $k] = $v;
    }
    $vars['workflow_name'] = (string) ($wf['name'] ?? '');
    $vars['run_id'] = (string) ($run['id'] ?? '');
    $fill = function (string $key, callable $fn) use (&$vars) {
        if (!isset($vars[$key]) || $vars[$key] === '' || $vars[$key] === null) {
            try {
                $vars[$key] = (string) $fn();
            } catch (Throwable $e) {
                $vars[$key] = '';
            }
        }
    };
    $fill('institute_name', fn () => function_exists('institute_name') ? institute_name() : 'Global Institute of Management & Technology');
    $fill('today', fn () => date('d M Y'));
    $fill('tomorrow', fn () => date('d M Y', strtotime('+1 day')));
    $fill('site_url', fn () => wa_wf_site_url());
    $fill('counsellor_name', fn () => !empty($contact['counsellor_id']) ? (string) db_value('SELECT name FROM users WHERE id = ?', [(int) $contact['counsellor_id']]) : '');
    $fill('session_name', fn () => (string) db_value('SELECT name FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1'));
    $fill('admissions_deadline', function () {
        $d = wa_wf_deadline_info();
        return $d['valid'] ? (string) $d['value'] : '';   // never a past or invented date
    });
    $fill('last_date', fn () => $vars['admissions_deadline'] ?? '');
    $fill('support_number', function () {
        $p = trim((string) setting('phone', ''));
        if ($p === '' && function_exists('wa_setting')) {
            $p = (string) wa_setting('wa_business_number', '');
        }
        return $p !== '' && function_exists('phone_display') ? phone_display($p) : $p;
    });
    $fill('course_name', fn () => trim((string) ($contact['program_name'] ?? '')) ?: 'our programmes');
    $fill('enquiry_id', fn () => !empty($contact['enquiry_id']) ? (string) (int) $contact['enquiry_id'] : '');
    // the ERP reference of the linked enquiry (GIMT shows enquiries as "#<id>"); empty when none is linked
    $fill('enquiry_no', fn () => !empty($contact['enquiry_id'])
        ? (function_exists('wa_crm_enquiry_no') ? wa_crm_enquiry_no((int) $contact['enquiry_id']) : '#' . (int) $contact['enquiry_id']) : '');
    $fill('student_name', fn () => trim((string) ($contact['name'] ?? '')) ?: 'Student');
    return array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $vars);
}

/** Public website URL used in template links (https only; local dev URLs fall back to the live site). */
function wa_wf_site_url(): string
{
    $cfg = function_exists('app_config') ? (string) (app_config('app')['url'] ?? '') : '';
    return preg_match('#^https://#', $cfg) && !preg_match('#localhost|127\.0\.0\.1#', $cfg) ? rtrim($cfg, '/') : 'https://gimtedu.com';
}

/** Replace {{key}} placeholders. Unknown keys become empty strings. */
function wa_wf_interpolate(string $text, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $text);
}

/** Interpolate a JSON body inside its string values (keeps the JSON valid); plain interpolation otherwise. */
function wa_wf_interpolate_body(string $body, array $vars): string
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return wa_wf_interpolate($body, $vars);
    }
    array_walk_recursive($decoded, function (&$v) use ($vars) {
        if (is_string($v)) {
            $v = wa_wf_interpolate($v, $vars);
        }
    });
    return wa_wf_json($decoded);
}

/** Value of a rule field for this run. */
function wa_wf_field_value(string $field, array $ctx, ?array $contact, array $run)
{
    [$root, $path] = array_pad(explode('.', $field, 2), 2, '');
    switch ($root) {
        case 'reply':
            if ($path === 'received') {
                return wa_wf_replied_since($run, $ctx, $contact, (string) ($run['started_at'] ?? date('Y-m-d H:i:s')));
            }
            return (string) ($ctx['last_reply']['text'] ?? '');
        case 'contact':
            if (!$contact) {
                return null;
            }
            if ($path === 'tags') {
                return wa_wf_contact_tags((int) $contact['id']);
            }
            if ($path === 'window_open') {
                return function_exists('wa_contact_window_open') ? wa_contact_window_open($contact) : false;
            }
            return $contact[$path] ?? null;
        case 'payload':
            return wa_wf_flatten(is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [])[$path] ?? null;
        case 'vars':
            return wa_wf_flatten(is_array($ctx['vars'] ?? null) ? $ctx['vars'] : [])[$path] ?? null;
    }
    return null;
}

function wa_wf_contact_tags(int $contactId): array
{
    return db_column('SELECT t.name FROM wa_contact_tags ct JOIN wa_tags t ON t.id = ct.tag_id WHERE ct.contact_id = ?', [$contactId]);
}

function wa_wf_truthy($v): bool
{
    if (is_bool($v)) {
        return $v;
    }
    if (is_array($v)) {
        return (bool) $v;
    }
    return in_array(strtolower(trim((string) $v)), ['1', 'yes', 'true', 'y', 'on'], true);
}

/** Evaluate one rule. Returns [bool result, string explanation]. */
function wa_wf_eval_rule(array $r, array $ctx, ?array $contact, array $run): array
{
    $field = (string) ($r['field'] ?? '');
    $op = (string) ($r['op'] ?? 'equals');
    $expect = trim((string) ($r['value'] ?? ''));
    $actual = wa_wf_field_value($field, $ctx, $contact, $run);
    $a = is_array($actual) ? implode(', ', $actual) : (is_bool($actual) ? ($actual ? 'yes' : 'no') : trim((string) $actual));
    $al = mb_strtolower($a);
    $el = mb_strtolower($expect);
    $num = fn ($x) => is_numeric($x) ? (float) $x : null;
    switch ($op) {
        case 'equals': $ok = $al === $el; break;
        case 'not_equals': $ok = $al !== $el; break;
        case 'contains': $ok = $el !== '' && str_contains($al, $el); break;
        case 'not_contains': $ok = $el === '' || !str_contains($al, $el); break;
        case 'starts_with': $ok = $el !== '' && str_starts_with($al, $el); break;
        case 'in': $ok = in_array($al, array_map(fn ($x) => mb_strtolower(trim($x)), explode(',', $expect)), true); break;
        case 'gt': $ok = $num($a) !== null && $num($expect) !== null && $num($a) > $num($expect); break;
        case 'gte': $ok = $num($a) !== null && $num($expect) !== null && $num($a) >= $num($expect); break;
        case 'lt': $ok = $num($a) !== null && $num($expect) !== null && $num($a) < $num($expect); break;
        case 'lte': $ok = $num($a) !== null && $num($expect) !== null && $num($a) <= $num($expect); break;
        case 'is_empty': $ok = $a === ''; break;
        case 'not_empty': $ok = $a !== ''; break;
        case 'is_true': $ok = wa_wf_truthy($actual); break;
        case 'is_false': $ok = !wa_wf_truthy($actual); break;
        case 'has_tag':
        case 'not_has_tag':
            $tags = array_map('mb_strtolower', is_array($actual) ? $actual : array_filter(array_map('trim', explode(',', $a))));
            $ok = in_array($el, $tags, true);
            $ok = $op === 'has_tag' ? $ok : !$ok;
            break;
        default: $ok = false;
    }
    $opLabel = wa_wf_condition_ops()[$op] ?? $op;
    return [$ok, sprintf('%s %s%s → %s (value: %s)', $field, $opLabel, in_array($op, ['is_empty', 'not_empty', 'is_true', 'is_false'], true) ? '' : ' "' . $expect . '"', $ok ? 'yes' : 'no', $a === '' ? 'empty' : mb_substr($a, 0, 80))];
}

function wa_wf_eval_rules(array $rules, string $match, array $ctx, ?array $contact, array $run): array
{
    $results = [];
    $all = true;
    $any = false;
    foreach ($rules as $r) {
        if (!is_array($r)) {
            continue;
        }
        [$ok, $why] = wa_wf_eval_rule($r, $ctx, $contact, $run);
        $results[] = $why;
        $all = $all && $ok;
        $any = $any || $ok;
    }
    if (!$results) {
        return [true, []];
    }
    return [$match === 'any' ? $any : $all, $results];
}

/** Did the contact send a message after $since (test runs answer from the simulated reply)? */
function wa_wf_replied_since(array $run, array $ctx, ?array $contact, string $since): bool
{
    if (!empty($run['is_test'])) {
        return ($ctx['test']['reply_mode'] ?? 'timeout') === 'replied';
    }
    if (!$contact) {
        return false;
    }
    if (!empty($ctx['last_reply']['at']) && $ctx['last_reply']['at'] >= $since) {
        return true;
    }
    return (bool) db_value("SELECT 1 FROM wa_messages WHERE contact_id = ? AND direction = 'in' AND created_at >= ? LIMIT 1", [(int) $contact['id'], $since]);
}

/* ============================================================================================ runs: start */

/** Human text for a reason a run did not start (wa_wf_start / event log actions). */
function wa_wf_reason_text(string $reason): string
{
    if (str_starts_with($reason, 'scheduled: ')) {
        return 'the workflow schedule is closed (' . substr($reason, 11) . ')';
    }
    return [
        'no_trigger' => 'the workflow has no Event Trigger',
        'filtered' => 'the trigger filters do not match',
        'already_running' => 'the contact is already in this workflow',
        'daily_limit' => 'the contact already started this workflow ' . WA_WF_MAX_RUNS_PER_DAY . ' times in the last 24 hours',
        'reentry_cooldown' => 'the contact entered this workflow less than ' . intdiv(WA_WF_REENTRY_COOLDOWN, 60) . ' minutes ago',
        'duplicate_event' => 'this event already started a run',
        'loop_prevented' => 'the event was raised by this workflow itself (loop protection)',
        'chain_too_deep' => 'too many workflows triggered each other in a row (loop protection)',
        'busy' => 'another run for this contact is being started — try again',
    ][$reason] ?? $reason;
}

function wa_wf_event_code(array $event): string
{
    return (string) ($event['event_code'] ?? $event['code'] ?? '');
}

/** Listener for every event (wa_on('*')): start runs of active workflows triggered by this event. */
function wa_wf_on_event(array $event, array $payload = [], ?array $contact = null): ?array
{
    $code = wa_wf_event_code($event);
    if ($code === '') {
        return null;
    }
    $wfs = wa_wf_active_by_event()[$code] ?? [];
    if (!$wfs) {
        return null;
    }
    static $depth = 0;
    if ($depth > 3) {
        return null; // a workflow step raising an event that starts a workflow that raises an event…
    }
    // loop protection: the workflows whose step raised this event (directly or through other workflows)
    $chain = wa_wf_current_chain();
    $depth++;
    $started = [];
    $skipped = [];
    try {
        foreach ($wfs as $wf) {
            $r = wa_wf_start($wf, $contact, $payload, ['event_log_id' => isset($event['id']) ? (int) $event['id'] : null, 'inline' => true, 'chain' => $chain]);
            if ($r['ok']) {
                $started[] = ['workflow_id' => (int) $wf['id'], 'workflow' => $wf['name'], 'run_id' => $r['run_id'], 'status' => $r['status']];
            } elseif (($r['reason'] ?? '') !== 'filtered') {
                $skipped[] = ['workflow_id' => (int) $wf['id'], 'reason' => $r['reason'] ?? 'skipped'];
            }
        }
    } catch (Throwable $e) {
        error_log('[GIMT] WhatsApp workflow start failed: ' . $e->getMessage());
    } finally {
        $depth--;
    }
    if (!$started && !$skipped) {
        return null;
    }
    return ['workflow_runs' => $started] + ($skipped ? ['workflow_skipped' => $skipped] : []);
}

/**
 * Start a run of a workflow. $opts: event_log_id, inline (defer network steps to the worker), test (array of test
 * options → is_test run executed immediately with waits fast-forwarded), definition (test an unsaved definition),
 * manual (skip trigger filters), started_by.
 * Returns ['ok', 'run_id', 'status', 'reason'].
 */
function wa_wf_start(array $wf, ?array $contact, array $payload, array $opts = []): array
{
    $isTest = isset($opts['test']);
    $def = isset($opts['definition']) ? wa_wf_normalize_definition($opts['definition']) : wa_wf_definition($wf);
    $trigger = wa_wf_trigger_node($def);
    if (!$trigger) {
        return ['ok' => false, 'reason' => 'no_trigger'];
    }
    // resolve the contact from the payload when the event bus could not
    if (!$contact) {
        $cid = (int) ($payload['contact_id'] ?? 0);
        if ($cid && function_exists('wa_contact')) {
            $contact = wa_contact($cid);
        }
    }
    // secrets in the event payload (password, token, api_key …) never reach the run context, the trace or a webhook
    if (function_exists('wa_event_redact')) {
        $payload = wa_event_redact($payload);
    }
    $chain = array_values(array_unique(array_map('intval', (array) ($opts['chain'] ?? []))));
    $ctx = ['payload' => $payload, 'vars' => [], 'visits' => [], 'steps_total' => 0, '_def' => $def, 'version' => (int) ($wf['version'] ?? 1)];
    if ($chain) {
        $ctx['chain'] = $chain;
    }
    if ($isTest) {
        $ctx['test'] = $opts['test'];
    }
    $run = ['id' => 0, 'is_test' => $isTest ? 1 : 0, 'started_at' => date('Y-m-d H:i:s')];
    if (empty($opts['manual']) && !$isTest) {
        if ($why = wa_wf_schedule_block($trigger)) {
            return ['ok' => false, 'reason' => $why];
        }
        [$pass] = wa_wf_eval_rules((array) ($trigger['data']['filters'] ?? []), 'all', $ctx, $contact, $run);
        if (!$pass) {
            return ['ok' => false, 'reason' => 'filtered'];
        }
        // loop protection: never restarted by an event raised by its own steps (or by a workflow it started)
        if (in_array((int) $wf['id'], $chain, true)) {
            return ['ok' => false, 'reason' => 'loop_prevented'];
        }
        if (count($chain) >= WA_WF_MAX_CHAIN) {
            return ['ok' => false, 'reason' => 'chain_too_deep'];
        }
    }
    $contactId = $contact ? (int) $contact['id'] : null;
    $eventLogId = $opts['event_log_id'] ?? null;
    $dedupe = !$isTest && $eventLogId ? 'wf' . (int) $wf['id'] . ':ev' . (int) $eventLogId : null;
    if (!empty($opts['started_by'])) {
        $ctx['started_by'] = (int) $opts['started_by'];
    }
    // re-entry rules are checked and the run inserted under one lock per workflow + contact (two events at once
    // cannot both start a run)
    $lock = null;
    if (!$isTest && $contactId) {
        $lock = 'gimt_wa_wfs_' . (int) $wf['id'] . '_' . $contactId;
        if (!(int) db_value('SELECT GET_LOCK(?, 5)', [$lock])) {
            return ['ok' => false, 'reason' => 'busy'];
        }
    }
    try {
        if ($lock) {
            $active = db_value("SELECT id FROM wa_workflow_runs WHERE workflow_id = ? AND contact_id = ? AND status IN ('running', 'waiting') AND is_test = 0 ORDER BY id DESC LIMIT 1", [(int) $wf['id'], $contactId]);
            if ($active && empty($wf['allow_reentry'])) {
                return ['ok' => false, 'reason' => 'already_running', 'run_id' => (int) $active];
            }
            $recent = db_row('SELECT COUNT(*) AS n, MAX(started_at) AS last FROM wa_workflow_runs WHERE workflow_id = ? AND contact_id = ? AND is_test = 0 AND started_at >= ?',
                [(int) $wf['id'], $contactId, date('Y-m-d H:i:s', time() - 86400)]);
            if ((int) ($recent['n'] ?? 0) >= WA_WF_MAX_RUNS_PER_DAY) {
                return ['ok' => false, 'reason' => 'daily_limit'];
            }
            if (!empty($wf['allow_reentry']) && empty($opts['manual']) && !empty($recent['last']) && strtotime((string) $recent['last']) > time() - WA_WF_REENTRY_COOLDOWN) {
                return ['ok' => false, 'reason' => 'reentry_cooldown'];
            }
        }
        $runId = db_insert('wa_workflow_runs', [
            'workflow_id' => (int) $wf['id'], 'contact_id' => $contactId, 'event_log_id' => $eventLogId,
            'status' => 'running', 'current_node' => $trigger['id'], 'context' => wa_wf_json($ctx),
            'dedupe_key' => $dedupe, 'is_test' => $isTest ? 1 : 0, 'started_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() === '23000') {
            return ['ok' => false, 'reason' => 'duplicate_event'];
        }
        throw $e;
    } finally {
        if ($lock) {
            db_value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
    $event = trim((string) ($trigger['data']['event'] ?? ''));
    wa_wf_step($runId, $trigger, 'ok', [
        'summary' => $isTest ? 'Test run started by ' . (function_exists('current_user') ? (current_user()['name'] ?? 'an admin') : 'an admin')
            : (!empty($opts['manual']) ? 'Enrolled manually' : 'Triggered by ' . ($event ?: 'event')),
        'event' => $event, 'event_log_id' => $eventLogId, 'contact_id' => $contactId,
    ]);
    if (!$isTest) {
        wa_wf_refresh_stats((int) $wf['id']);
    }
    $res = wa_wf_execute($runId, ['inline' => !empty($opts['inline']), 'from_start' => true]);
    return ['ok' => true, 'run_id' => $runId, 'status' => $res['status'] ?? 'running'];
}

/* ============================================================================================ runs: execution */

/** Record a step. Returns its id. */
function wa_wf_step(int $runId, array $node, string $status, array $output = [], ?string $error = null): int
{
    $output['title'] = $output['title'] ?? wa_wf_node_title($node);
    return db_insert('wa_workflow_steps', [
        'run_id' => $runId, 'node_id' => mb_substr((string) $node['id'], 0, 64), 'node_type' => mb_substr((string) $node['type'], 0, 30),
        'status' => $status, 'output' => wa_wf_json($output), 'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/** Close the step a run was waiting on (waiting → ok) with extra output. */
function wa_wf_close_waiting_step(?int $stepId, array $extra, string $status = 'ok'): void
{
    if (!$stepId) {
        return;
    }
    $row = db_row('SELECT output FROM wa_workflow_steps WHERE id = ?', [$stepId]);
    if (!$row) {
        return;
    }
    $out = array_merge(wa_wf_decode($row['output'], []), $extra);
    db_update('wa_workflow_steps', ['status' => $status, 'output' => wa_wf_json($out)], 'id = ?', [$stepId]);
}

/**
 * Execute a run from where it stands until it waits, ends or fails. $opts:
 *   inline     — called from the event bus inside an ERP request: API/webhook steps are handed to the worker
 *   port       — resume a waiting run through this port (replied | timeout | next)
 *   reply      — the inbound message that resumed a Wait for Reply step
 *   from_start — first pass after the trigger step
 * Returns ['status', 'steps' => n, 'error'].
 */
/** Workflow ids whose step is executing right now (innermost last) — events raised by a step inherit this chain. */
function wa_wf_current_chain(): array
{
    $stack = $GLOBALS['__wa_wf_exec'] ?? [];
    return $stack ? (array) end($stack) : [];
}

/** Run $fn while holding the run's lock (null when the lock could not be taken within $wait seconds). */
function wa_wf_with_run_lock(int $runId, callable $fn, int $wait = 5)
{
    $lock = 'gimt_wa_wf_' . $runId;
    if (!(int) db_value('SELECT GET_LOCK(?, ?)', [$lock, $wait])) {
        return null;
    }
    try {
        return $fn();
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

function wa_wf_execute(int $runId, array $opts = []): array
{
    $lock = 'gimt_wa_wf_' . $runId;
    if (!(int) db_value('SELECT GET_LOCK(?, 3)', [$lock])) {
        return ['status' => 'locked', 'steps' => 0];
    }
    try {
        return wa_wf_execute_locked($runId, $opts);
    } catch (Throwable $e) {
        error_log('[GIMT] WhatsApp workflow run ' . $runId . ' crashed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        try {
            db_update('wa_workflow_runs', ['status' => 'failed', 'error' => mb_substr('Unexpected error: ' . $e->getMessage(), 0, 1000), 'finished_at' => date('Y-m-d H:i:s'), 'next_run_at' => null], 'id = ?', [$runId]);
            $wfId = (int) db_value('SELECT workflow_id FROM wa_workflow_runs WHERE id = ?', [$runId]);
            if ($wfId) {
                wa_wf_refresh_stats($wfId);
            }
        } catch (Throwable $e2) {
            // ignore
        }
        return ['status' => 'failed', 'steps' => 0, 'error' => $e->getMessage()];
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

function wa_wf_execute_locked(int $runId, array $opts): array
{
    $run = db_row('SELECT * FROM wa_workflow_runs WHERE id = ?', [$runId]);
    if (!$run || !in_array($run['status'], ['running', 'waiting'], true)) {
        return ['status' => $run['status'] ?? 'missing', 'steps' => 0, 'stale' => true];
    }
    // re-check under the lock what the caller saw before taking it (a reply and the timeout, or two worker ticks,
    // must never both resume the same wait — and a reply must never resume a Delay)
    if (isset($opts['expect_wait']) && ($run['status'] !== 'waiting' || (string) $run['waiting_for'] !== $opts['expect_wait'])) {
        return ['status' => $run['status'], 'steps' => 0, 'stale' => true];
    }
    if (!empty($opts['due']) && ($run['status'] !== 'waiting' || empty($run['next_run_at']) || strtotime((string) $run['next_run_at']) > time())) {
        return ['status' => $run['status'], 'steps' => 0, 'stale' => true];
    }
    $wf = wa_wf_get((int) $run['workflow_id']);
    if (!$wf) {
        db_update('wa_workflow_runs', ['status' => 'cancelled', 'error' => 'The workflow was deleted.', 'finished_at' => date('Y-m-d H:i:s')], 'id = ?', [$runId]);
        return ['status' => 'cancelled', 'steps' => 0];
    }
    $ctx = wa_wf_decode($run['context'], []);
    if (!empty($opts['reply'])) {
        $ctx['last_reply'] = $opts['reply'];
    }
    $chain = array_values(array_unique(array_merge(array_map('intval', (array) ($ctx['chain'] ?? [])), [(int) $wf['id']])));
    $def = isset($ctx['_def']['nodes']) ? wa_wf_normalize_definition($ctx['_def']) : wa_wf_definition($wf);
    $nodes = wa_wf_node_map($def);
    $edges = wa_wf_edge_map($def);
    $isTest = !empty($run['is_test']);
    $contact = $run['contact_id'] && function_exists('wa_contact') ? wa_contact((int) $run['contact_id']) : null;

    // where to continue
    $nodeId = $run['current_node'];
    if (!empty($opts['from_start'])) {
        $nodeId = $edges[$run['current_node']]['next'] ?? null;
    } elseif (isset($ctx['resume']) && is_array($ctx['resume'])) {
        $resume = $ctx['resume'];
        unset($ctx['resume']);
        $port = $opts['port'] ?? ($resume['port'] ?? null);
        $extra = ['resumed_at' => date('Y-m-d H:i:s')];
        if ($port === 'replied') {
            $extra['summary'] = 'Contact replied' . (!empty($opts['reply']['text']) ? ': "' . mb_substr((string) $opts['reply']['text'], 0, 120) . '"' : '');
            $extra['branch'] = 'replied';
        } elseif ($port === 'timeout') {
            $extra['summary'] = 'No reply before the timeout';
            $extra['branch'] = 'timeout';
        } elseif (($run['waiting_for'] ?? '') === 'business_hours') {
            $extra['summary'] = 'Business hours started — continuing';
        } else {
            $extra['summary'] = 'Wait finished';
        }
        wa_wf_close_waiting_step(isset($ctx['waiting_step']) ? (int) $ctx['waiting_step'] : null, $extra);
        unset($ctx['waiting_step']);
        $nodeId = $port === null ? $resume['node'] : ($edges[$resume['node']][$port] ?? null);
    }

    $update = ['status' => 'running', 'waiting_for' => null, 'next_run_at' => null, 'error' => null];
    $steps = 0;
    $final = null;
    while (true) {
        if ($nodeId === null || $nodeId === '') {
            $final = 'completed';
            break;
        }
        if (++$steps > WA_WF_MAX_STEPS_PER_PASS) {
            $final = 'failed';
            $update['error'] = 'Stopped after ' . WA_WF_MAX_STEPS_PER_PASS . ' steps in one pass — check the workflow for loops.';
            break;
        }
        $ctx['steps_total'] = (int) ($ctx['steps_total'] ?? 0) + 1;
        if ($ctx['steps_total'] > WA_WF_MAX_STEPS_PER_RUN) {
            $final = 'failed';
            $update['error'] = 'Stopped after ' . WA_WF_MAX_STEPS_PER_RUN . ' steps in this run — the workflow loops too often for one contact.';
            break;
        }
        $node = $nodes[$nodeId] ?? null;
        if (!$node) {
            $final = 'failed';
            $update['error'] = 'Step "' . $nodeId . '" is missing from the workflow definition.';
            break;
        }
        $update['current_node'] = $nodeId;
        $ctx['visits'][$nodeId] = (int) ($ctx['visits'][$nodeId] ?? 0) + 1;
        if ($ctx['visits'][$nodeId] > WA_WF_MAX_NODE_VISITS) {
            $final = 'failed';
            $update['error'] = 'The step "' . wa_wf_node_title($node) . '" ran more than ' . WA_WF_MAX_NODE_VISITS . ' times — stopped to protect the contact.';
            wa_wf_step($runId, $node, 'failed', [], $update['error']);
            break;
        }
        // sends wait for business hours (live runs only)
        if (!$isTest && !empty($wf['business_hours_only']) && in_array($node['type'], ['template', 'message'], true)
            && function_exists('wa_in_business_hours') && !wa_in_business_hours()) {
            $at = function_exists('wa_next_business_time') ? wa_next_business_time(new DateTimeImmutable())->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime('+1 hour'));
            $ctx['visits'][$nodeId]--;
            $ctx['steps_total']--;
            $ctx['resume'] = ['node' => $nodeId, 'port' => null];
            $ctx['waiting_step'] = wa_wf_step($runId, $node, 'waiting', ['summary' => 'Outside business hours — postponed until ' . date('d M, h:i A', strtotime($at)), 'until' => $at, 'waiting_for' => 'business_hours']);
            $update['status'] = 'waiting';
            $update['waiting_for'] = 'business_hours';
            $update['next_run_at'] = $at;
            $final = 'waiting';
            break;
        }
        // network calls never run inside an ERP request: the worker picks them up within a minute
        if (!empty($opts['inline']) && !$isTest && in_array($node['type'], ['api', 'webhook'], true)) {
            $ctx['visits'][$nodeId]--;
            $ctx['steps_total']--;
            $ctx['resume'] = ['node' => $nodeId, 'port' => null];
            $ctx['waiting_step'] = null;
            $update['status'] = 'waiting';
            $update['waiting_for'] = 'delay';
            $update['next_run_at'] = date('Y-m-d H:i:s');
            $final = 'waiting';
            break;
        }

        $GLOBALS['__wa_wf_exec'][] = $chain;
        try {
            $res = wa_wf_exec_node($node, $ctx, $run, $wf, $contact, $opts);
        } finally {
            array_pop($GLOBALS['__wa_wf_exec']);
        }
        $contact = $res['contact'] ?? $contact;
        if (!empty($res['contact_id']) && (int) $res['contact_id'] !== (int) ($run['contact_id'] ?? 0)) {
            $run['contact_id'] = (int) $res['contact_id'];
            $update['contact_id'] = (int) $res['contact_id'];
        }
        $stepId = wa_wf_step($runId, $node, $res['status'], $res['output'] ?? [], $res['error'] ?? null);
        if ($res['status'] === 'failed' && empty($res['continue'])) {
            $final = 'failed';
            $update['error'] = $res['error'] ?? 'The step failed.';
            break;
        }
        if (!empty($res['cancel'])) {
            $final = 'cancelled';
            $update['error'] = $res['error'] ?? ($res['output']['summary'] ?? 'Stopped.');
            break;
        }
        if (!empty($res['wait'])) {
            $ctx['resume'] = ['node' => $nodeId, 'port' => $res['wait']['port'] ?? 'next'];
            $ctx['waiting_step'] = $stepId;
            $update['status'] = 'waiting';
            $update['waiting_for'] = $res['wait']['for'];
            $update['next_run_at'] = $res['wait']['until'];
            $final = 'waiting';
            break;
        }
        if (!empty($res['end'])) {
            $final = 'completed';
            break;
        }
        $nodeId = $edges[$nodeId][$res['port'] ?? 'next'] ?? null;
    }

    $update['status'] = $final;
    if (in_array($final, ['completed', 'failed', 'cancelled'], true)) {
        $update['finished_at'] = date('Y-m-d H:i:s');
        $update['next_run_at'] = null;
        $update['waiting_for'] = null;
    }
    $update['context'] = wa_wf_json($ctx);
    db_update('wa_workflow_runs', $update, 'id = ?', [$runId]);
    if (!$isTest && $final !== 'waiting') {
        wa_wf_refresh_stats((int) $wf['id']);
    }
    if ($final === 'failed' && !$isTest) {
        wa_wf_notify_failure($wf, $runId, (string) ($update['error'] ?? ''));
    }
    return ['status' => $final, 'steps' => $steps, 'error' => $update['error'] ?? null];
}

/** One in-app notification per workflow per hour when runs fail (no flood). */
function wa_wf_notify_failure(array $wf, int $runId, string $error): void
{
    try {
        $key = 'wa-wf-fail:' . (int) $wf['id'];
        if (function_exists('rate_limit') && !rate_limit($key, 1, 3600)) {
            return;
        }
        if (function_exists('notify')) {
            notify('perm:whatsapp_automation', 'whatsapp', 'Workflow run failed: ' . $wf['name'], mb_substr($error, 0, 300), 'admin/whatsapp/automations?tab=runs&status=failed&workflow=' . (int) $wf['id'], 'alert-triangle');
        }
    } catch (Throwable $e) {
        // never break a run because of a notification
    }
}

/** Make sure the run has a contact (create / link it from the payload phone). */
function wa_wf_ensure_contact(?array $contact, array $ctx, array $extra = []): ?array
{
    if ($contact) {
        return $contact;
    }
    $p = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
    $phone = $p['phone'] ?? $p['mobile'] ?? $p['whatsapp'] ?? $p['contact_phone'] ?? null;
    if (!$phone || !function_exists('wa_contact_upsert')) {
        return null;
    }
    $data = array_filter([
        'phone' => (string) $phone,
        'name' => $p['name'] ?? $p['student_name'] ?? null,
        'email' => $p['email'] ?? null,
        'enquiry_id' => isset($p['enquiry_id']) ? (int) $p['enquiry_id'] : null,
        'student_id' => isset($p['student_id']) ? (int) $p['student_id'] : null,
        'admission_id' => isset($p['admission_id']) ? (int) $p['admission_id'] : null,
        'program_id' => isset($p['program_id']) ? (int) $p['program_id'] : null,
        'program_name' => $p['program_name'] ?? $p['course_name'] ?? null,
        'application_no' => $p['application_no'] ?? $p['application_number'] ?? null,
        'city' => $p['city'] ?? null,
        'source' => 'automation',
    ] + $extra, fn ($v) => $v !== null && $v !== '' && $v !== 0);
    try {
        $id = wa_contact_upsert($data);
    } catch (Throwable $e) {
        return null;
    }
    return $id ? wa_contact((int) $id) : null;
}

/**
 * "Also create an ERP admission enquiry" of the Create / Link Contact step. wa_crm_create_enquiry() (WhatsApp CRM)
 * links the contact's enquiry or creates one in Admissions › Enquiries (source whatsapp); its id and number become
 * {{enquiry_id}} / {{enquiry_no}} for the next steps. Returns [status ok|skipped|failed, note, output?, error?, contact?].
 */
function wa_wf_contact_enquiry(array $c, array &$ctx, array $wf, array $run, bool $applyCrm): array
{
    $p = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
    $linked = (int) ($c['enquiry_id'] ?? 0);
    $linkedNo = $linked ? (function_exists('wa_crm_enquiry_no') ? wa_crm_enquiry_no($linked) : '#' . $linked) : '';
    if ($linked) {
        $ctx['vars']['enquiry_id'] = (string) $linked;
        $ctx['vars']['enquiry_no'] = $linkedNo;
    }
    if (!$applyCrm) {
        return ['status' => 'skipped', 'note' => $linked ? 'linked to enquiry ' . $linkedNo . ' (CRM actions are off in this test)' : 'would create an ERP admission enquiry (CRM actions are off in this test)'];
    }
    if (!$linked && (!empty($c['student_id']) || !empty($c['admission_id']) || in_array($c['contact_type'] ?? '', ['student', 'alumni', 'staff'], true))) {
        return ['status' => 'skipped', 'note' => 'no ERP enquiry needed — the contact is already ' . (!empty($c['admission_id']) ? 'an applicant' : 'a student / staff record')];
    }
    if (!function_exists('wa_crm_create_enquiry')) {
        return ['status' => 'skipped', 'note' => 'ERP enquiry skipped — enquiry helper unavailable' .($linked ? ' (linked to enquiry ' . $linkedNo . ')' : '')];
    }
    $programId = (int) ($p['program_id'] ?? 0) ?: (int) ($c['program_id'] ?? 0);
    $opts = ['source' => 'whatsapp', 'origin' => 'workflow', 'workflow_id' => (int) $wf['id'], 'run_id' => (int) ($run['id'] ?? 0),
        'note' => 'Workflow "' . mb_substr((string) $wf['name'], 0, 100) . '", run #' . (int) ($run['id'] ?? 0) . '.'];
    if ($programId) {
        $opts['program_id'] = $programId;
    }
    try {
        $r = (array) wa_crm_create_enquiry((int) $c['id'], $opts);
    } catch (Throwable $e) {
        $r = ['ok' => false, 'error' => $e->getMessage()];
    }
    if (empty($r['ok'])) {
        return ['status' => 'failed', 'note' => 'ERP enquiry not created', 'error' => 'The ERP admission enquiry could not be created: ' . rtrim((string) ($r['error'] ?? 'unknown error'), '.') . '.'];
    }
    $id = (int) ($r['enquiry_id'] ?? 0);
    $no = trim((string) ($r['enquiry_no'] ?? ''));
    if ($id) {
        $ctx['vars']['enquiry_id'] = (string) $id;
    }
    if ($no !== '') {
        $ctx['vars']['enquiry_no'] = $no;
    }
    $label = $no !== '' ? $no : ($id ? '#' . $id : '');
    return [
        'status' => 'ok',
        'note' => (!empty($r['created']) ? 'ERP enquiry created' : 'ERP enquiry linked') . ($label !== '' ? ': ' . $label : ''),
        'output' => ['enquiry_id' => $id ?: null, 'enquiry_no' => $no !== '' ? $no : null, 'enquiry_created' => !empty($r['created'])],
        'contact' => (function_exists('wa_contact') ? wa_contact((int) $c['id']) : null) ?: $c,
    ];
}

/**
 * Step summary for a template the same-template repeat guard of wa_send() did not send again (code duplicate_recent),
 * e.g. "Skipped — the same template was sent to this contact 2 min ago by the Event Manager (message #2839)".
 */
function wa_wf_recent_duplicate_summary(array $r): string
{
    $id = (int) ($r['duplicate_of'] ?? $r['message_id'] ?? 0);
    $m = null;
    if ($id) {
        try {
            $m = db_row('SELECT sender_type, created_at, sent_at FROM wa_messages WHERE id = ?', [$id]);
        } catch (Throwable $e) {
            $m = null;
        }
    }
    if (!$m) {
        return 'Skipped — the same template was sent to this contact in the last ' . (defined('WA_REPEAT_WINDOW_MINUTES') ? WA_REPEAT_WINDOW_MINUTES : 10) . ' min'
            . ($id ? ' (message #' . $id . ')' : '');
    }
    $at = strtotime((string) ($m['sent_at'] ?: $m['created_at'])) ?: time();
    $min = intdiv(max(0, time() - $at), 60);
    $by = ['event' => 'the Event Manager', 'automation' => 'a workflow', 'followup' => 'a follow-up'][$m['sender_type']] ?? null;
    return 'Skipped — the same template was sent to this contact ' . ($min < 1 ? 'less than a minute ago' : $min . ' min ago')
        . ($by ? ' by ' . $by : '') . ' (message #' . $id . ')';
}

/** Map a wa_send() refusal to a step result. */
function wa_wf_send_result(array $r, string $what, ?array $contact = null): array
{
    if (($r['code'] ?? '') === 'duplicate') {
        return ['status' => 'skipped', 'output' => ['summary' => $what . ' was already sent by this run — not sent again', 'code' => 'duplicate', 'message_id' => $r['message_id'] ?? null]];
    }
    if (($r['code'] ?? '') === 'duplicate_recent') {
        // the same-template repeat guard (wa_send_recent_duplicate): nothing new was queued — skip the step, the run goes on
        return ['status' => 'skipped', 'output' => ['summary' => wa_wf_recent_duplicate_summary($r), 'code' => 'duplicate_recent',
            'message_id' => $r['message_id'] ?? null, 'duplicate_of' => $r['duplicate_of'] ?? $r['message_id'] ?? null]];
    }
    if (!empty($r['ok'])) {
        return ['status' => 'ok', 'output' => ['summary' => $what . ' queued', 'message_id' => $r['message_id'] ?? null, 'delivery' => $r['status'] ?? 'queued']];
    }
    $code = (string) ($r['code'] ?? '');
    $err = (string) ($r['error'] ?? 'The message could not be queued.');
    if ($code === 'duplicate') {
        return ['status' => 'skipped', 'output' => ['summary' => $what . ' was already sent by this run — not sent again', 'code' => $code, 'message_id' => $r['message_id'] ?? null]];
    }
    if ($code === 'opted_out' && $contact && empty($contact['opted_out_at'])) {
        // never opted out — only no marketing consent yet: skip this marketing template, keep the run going
        return ['status' => 'skipped', 'output' => ['summary' => 'Skipped — marketing templates need WhatsApp opt-in, and this contact has not opted in', 'code' => 'no_opt_in']];
    }
    if (in_array($code, ['opted_out', 'blocked'], true)) {
        return ['status' => 'skipped', 'cancel' => true, 'output' => ['summary' => $code === 'opted_out' ? 'Contact opted out of WhatsApp — workflow stopped' : 'Contact is blocked — workflow stopped', 'code' => $code],
            'error' => $code === 'opted_out' ? 'The contact opted out of WhatsApp messages.' : 'The contact is blocked.'];
    }
    if ($code === 'window_closed') {
        return ['status' => 'skipped', 'output' => ['summary' => 'Skipped — the 24-hour window is closed (only templates can be sent)', 'code' => $code]];
    }
    return ['status' => 'failed', 'output' => ['code' => $code ?: null], 'error' => $err];
}

/** Run one node. Returns ['status', 'output', 'error', 'port', 'wait' => {for, until, port}, 'end', 'cancel', 'continue', 'contact', 'contact_id']. */
function wa_wf_exec_node(array $node, array &$ctx, array $run, array $wf, ?array $contact, array $opts): array
{
    $d = $node['data'] ?? [];
    $runId = (int) $run['id'];
    $isTest = !empty($run['is_test']);
    $test = is_array($ctx['test'] ?? null) ? $ctx['test'] : [];
    $applyCrm = !$isTest || !empty($test['apply_crm']);
    $type = $node['type'];
    $visit = (int) ($ctx['visits'][$node['id']] ?? 1);

    if (wa_wf_node_types()[$type]['contact'] ?? false) {
        $contact = wa_wf_ensure_contact($contact, $ctx);
        if (!$contact) {
            return ['status' => 'failed', 'error' => 'This run has no WhatsApp contact (the event did not include a phone number).'];
        }
    }
    $base = ['contact' => $contact, 'contact_id' => $contact['id'] ?? null];
    $vars = fn () => wa_wf_vars($ctx, $contact, $wf, $run);
    $msgMeta = ['sender_type' => 'automation', 'workflow_run_id' => $runId, 'event_log_id' => $run['event_log_id'] ? (int) $run['event_log_id'] : null];
    // a test may message a contact that never opted in (to preview marketing steps), never one who opted out (STOP)
    $sendOpts = ['dedupe_key' => 'wfr' . $runId . ':' . $node['id'] . ':' . $visit . ($isTest ? '' : ':' . (int) ($run['attempts'] ?? 0))]
        + ($isTest ? ['priority' => 1] + (empty($contact['opted_out_at']) ? ['skip_opt_in_check' => true] : []) : ['priority' => 4]);

    switch ($type) {
        case 'contact':
            $extra = [];
            if (!empty($d['contact_type'])) {
                $extra['contact_type'] = mb_substr((string) $d['contact_type'], 0, 20);
            }
            $c = $contact;
            $created = false;
            if (!$c) {
                $c = wa_wf_ensure_contact(null, $ctx, $extra);
                $created = (bool) $c;
            } elseif ($applyCrm && function_exists('wa_contact_upsert')) {
                // link the ERP ids of this event to the existing contact
                $p = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
                $link = array_filter(['id' => (int) $c['id'], 'enquiry_id' => (int) ($p['enquiry_id'] ?? 0), 'student_id' => (int) ($p['student_id'] ?? 0), 'admission_id' => (int) ($p['admission_id'] ?? 0),
                    'email' => $p['email'] ?? null, 'program_name' => $p['program_name'] ?? $p['course_name'] ?? null]);
                if (count($link) > 1) {
                    try {
                        wa_contact_upsert($link);
                        $c = wa_contact((int) $c['id']) ?: $c;
                    } catch (Throwable $e) {
                        // linking is best effort
                    }
                }
            }
            if (!$c) {
                return ['status' => 'failed', 'error' => 'No phone number in the event payload — the contact could not be created.'];
            }
            if ($applyCrm && !empty($d['stage']) && $created && function_exists('wa_contact_set_stage') && ($c['stage'] ?? '') !== $d['stage']) {
                try {
                    wa_contact_set_stage((int) $c['id'], (string) $d['stage'], ['source' => 'workflow', 'workflow_id' => (int) $wf['id']]);
                } catch (Throwable $e) {
                    // ignore
                }
            }
            $enquiryId = (int) ($c['enquiry_id'] ?? 0) ?: (int) (($ctx['payload']['enquiry_id'] ?? 0));
            $summary = ($created ? 'Contact created: ' : 'Contact linked: ') . ($c['name'] ?: $c['phone']) . ' (+' . $c['phone'] . ')';
            $output = ['contact_id' => (int) $c['id'], 'created' => $created, 'enquiry_id' => $enquiryId ?: null];
            if (empty($d['create_enquiry'])) {
                return ['status' => 'ok', 'contact' => $c, 'contact_id' => (int) $c['id'], 'output' => ['summary' => $summary . ($enquiryId ? ' · enquiry #' . $enquiryId : '')] + $output];
            }
            // "Also create an ERP admission enquiry": the enquiry ID ({{enquiry_no}}) for the next steps
            $enq = wa_wf_contact_enquiry($c, $ctx, $wf, $run, $applyCrm);
            $c = $enq['contact'] ?? $c;
            $res = ['status' => $enq['status'] === 'failed' ? 'failed' : 'ok', 'contact' => $c, 'contact_id' => (int) $c['id'],
                'output' => ['summary' => $summary . ' · ' . $enq['note'], 'enquiry' => $enq['status']] + ($enq['output'] ?? []) + $output];
            if ($enq['status'] === 'failed') {
                // the contact is linked: the run goes on (the welcome message must not wait for the ERP), the step shows the failure
                $res += ['continue' => true, 'error' => $enq['error']];
            }
            return $res;

        case 'template':
            $tplKey = trim((string) ($d['template'] ?? ''));
            $tpl = function_exists('wa_template_find') ? wa_template_find($tplKey) : null;
            if (!$tpl) {
                return $base + ['status' => 'failed', 'error' => 'Template "' . $tplKey . '" was not found.'];
            }
            // never send a made-up slot (runs keep the definition they started with, so an older version may still have one)
            if ($fake = wa_wf_datetime_var_problems($node, wa_wf_template_keys($tpl))) {
                return $base + ['status' => 'failed', 'output' => ['code' => 'fake_datetime', 'template' => $tpl['name']],
                    'error' => 'Not sent: ' . implode('; ', $fake) . ' — every contact would be told the same made-up date or time. Map it to real data in the builder.'];
            }
            $v = $vars();
            $named = $v;
            foreach ((array) ($d['vars'] ?? []) as $k => $expr) {
                if (is_string($k) && $k !== '' && trim((string) $expr) !== '') {
                    $named[$k] = wa_wf_interpolate((string) $expr, $v);
                }
            }
            $samples = [];
            if ($isTest && function_exists('wa_render_template')) {
                // a test still runs when data is missing: those variables are filled with the template samples (and listed)
                try {
                    $samples = (array) (wa_render_template($tpl, wa_variables_for_contact($contact, $named) + $named)['missing'] ?? []);
                } catch (Throwable $e) {
                    $samples = [];
                }
            }
            $r = wa_send($msgMeta + ['contact_id' => (int) $contact['id'], 'type' => 'template', 'template' => $tpl['name'], 'vars' => $named], $sendOpts + ($samples ? ['use_samples' => true] : []));
            if (($r['code'] ?? '') === 'missing_variables' && preg_match('/\b(last_date|admissions_deadline)\b/', (string) ($r['error'] ?? '')) && ($problem = wa_wf_deadline_problem())) {
                // say exactly which setting is missing instead of a bare "missing variables"
                $r['error'] .= ' — ' . $problem . '. Set it under ' . wa_wf_deadline_info()['where'] . ', then retry this run.';
            }
            $res = wa_wf_send_result($r, 'Template "' . ($tpl['display_name'] ?: $tpl['name']) . '"', $contact);
            $res['output']['template'] = $tpl['name'];
            if ($samples && $res['status'] === 'ok') {
                $res['output']['summary'] .= ' — sample values used for missing ' . implode(', ', $samples) . ' (a live run would fail)';
            }
            return $base + $res;

        case 'message':
            $v = $vars();
            $text = wa_wf_interpolate((string) ($d['text'] ?? ''), $v);
            $mediaType = in_array($d['media_type'] ?? 'none', ['image', 'document', 'video'], true) ? $d['media_type'] : null;
            $msg = $msgMeta + ['contact_id' => (int) $contact['id'], 'type' => $mediaType ?: 'text', 'body' => $text];
            if ($mediaType) {
                $msg['media_url'] = wa_wf_interpolate((string) ($d['media_url'] ?? ''), $v);
                if (!empty($d['file_name'])) {
                    $msg['file_name'] = (string) $d['file_name'];
                }
            }
            $r = wa_send($msg, $sendOpts);
            $res = wa_wf_send_result($r, 'Message', $contact);
            $res['output']['preview'] = mb_substr($text, 0, 200);
            return $base + $res;

        case 'delay':
            $sec = wa_wf_seconds(max(1, (int) ($d['value'] ?? 1)), (string) ($d['unit'] ?? 'minutes'));
            $human = wa_wf_duration_text(max(1, (int) ($d['value'] ?? 1)), (string) ($d['unit'] ?? 'minutes'));
            if ($isTest) {
                return $base + ['status' => 'ok', 'output' => ['summary' => 'Would wait ' . $human . ' (fast-forwarded in the test)']];
            }
            $until = date('Y-m-d H:i:s', time() + $sec);
            return $base + ['status' => 'waiting', 'wait' => ['for' => 'delay', 'until' => $until, 'port' => 'next'],
                'output' => ['summary' => 'Waiting ' . $human . ' — until ' . date('d M, h:i A', strtotime($until)), 'until' => $until]];

        case 'condition':
        case 'ifelse':
            $rules = array_values(array_filter((array) ($d['rules'] ?? []), 'is_array'));
            if ($type === 'ifelse') {
                $rules = array_slice($rules, 0, 1);
            }
            [$ok, $why] = wa_wf_eval_rules($rules, (string) ($d['match'] ?? 'all'), $ctx, $contact, $run);
            return $base + ['status' => 'ok', 'port' => $ok ? 'true' : 'false', 'output' => ['summary' => $ok ? 'Condition met → Yes' : 'Condition not met → No', 'branch' => $ok ? 'true' : 'false', 'checks' => $why]];

        case 'wait_reply':
            $sec = wa_wf_seconds(max(1, (int) ($d['value'] ?? 24)), (string) ($d['unit'] ?? 'hours'));
            $human = wa_wf_duration_text(max(1, (int) ($d['value'] ?? 24)), (string) ($d['unit'] ?? 'hours'));
            if ($isTest) {
                $replied = ($test['reply_mode'] ?? 'timeout') === 'replied';
                if ($replied) {
                    $ctx['last_reply'] = ['text' => (string) ($test['reply_text'] ?? 'Yes, I am interested'), 'at' => date('Y-m-d H:i:s'), 'simulated' => true];
                }
                return $base + ['status' => 'ok', 'port' => $replied ? 'replied' : 'timeout',
                    'output' => ['summary' => $replied ? 'Simulated reply: "' . mb_substr($ctx['last_reply']['text'], 0, 120) . '"' : 'Simulated: no reply within ' . $human, 'branch' => $replied ? 'replied' : 'timeout']];
            }
            if (($d['since'] ?? 'node') === 'run' && wa_wf_replied_since($run, $ctx, $contact, (string) $run['started_at'])) {
                return $base + ['status' => 'ok', 'port' => 'replied', 'output' => ['summary' => 'The contact already replied after the workflow started', 'branch' => 'replied']];
            }
            $until = date('Y-m-d H:i:s', time() + $sec);
            return $base + ['status' => 'waiting', 'wait' => ['for' => 'reply', 'until' => $until, 'port' => 'timeout'],
                'output' => ['summary' => 'Waiting up to ' . $human . ' for a reply — until ' . date('d M, h:i A', strtotime($until)), 'until' => $until]];

        case 'tag':
            $tags = array_values(array_unique(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 60), (array) ($d['tags'] ?? [])))));
            $remove = ($d['action'] ?? 'add') === 'remove';
            if (!$applyCrm) {
                return $base + ['status' => 'skipped', 'output' => ['summary' => 'Would ' . ($remove ? 'remove' : 'add') . ' tag(s): ' . implode(', ', $tags) . ' (CRM actions are off in this test)']];
            }
            $remove ? wa_contact_untag((int) $contact['id'], $tags) : wa_contact_tag((int) $contact['id'], $tags);
            return $base + ['status' => 'ok', 'output' => ['summary' => ($remove ? 'Removed tag(s): ' : 'Added tag(s): ') . implode(', ', $tags), 'tags' => $tags]];

        case 'stage':
            $stage = trim((string) ($d['stage'] ?? ''));
            $lead = trim((string) ($d['lead_status'] ?? ''));
            $stages = wa_wf_stages();
            $parts = [];
            if ($stage !== '') {
                $parts[] = 'stage → ' . ($stages[$stage] ?? $stage);
            }
            if ($lead !== '') {
                $parts[] = 'lead status → ' . (wa_wf_lead_statuses()[$lead] ?? $lead);
            }
            if (!$applyCrm) {
                return $base + ['status' => 'skipped', 'output' => ['summary' => 'Would set ' . implode(', ', $parts) . ' (CRM actions are off in this test)']];
            }
            if ($stage !== '' && ($contact['stage'] ?? '') !== $stage) {
                wa_contact_set_stage((int) $contact['id'], $stage, ['source' => 'workflow', 'workflow_id' => (int) $wf['id'], 'run_id' => $runId, 'reason' => 'Workflow "' . mb_substr((string) $wf['name'], 0, 80) . '"']);
            }
            if ($lead !== '' && isset(wa_wf_lead_statuses()[$lead]) && !empty($contact['enquiry_id'])) {
                $n = db_exec("UPDATE enquiries SET status = ? WHERE id = ? AND status NOT IN ('converted', 'closed')", [$lead, (int) $contact['enquiry_id']]);
                if (!$n) {
                    $parts[] = '(enquiry already converted / closed — lead status unchanged)';
                }
            } elseif ($lead !== '') {
                $parts[] = '(no linked enquiry — lead status not changed)';
            }
            return $base + ['status' => 'ok', 'contact' => wa_contact((int) $contact['id']) ?: $contact, 'output' => ['summary' => 'Set ' . implode(', ', $parts)]];

        case 'assign':
            return $base + wa_wf_exec_assign($node, $contact, $wf, $applyCrm);

        case 'api':
        case 'webhook':
            return $base + wa_wf_exec_http($node, $ctx, $run, $wf, $contact, $isTest);

        case 'end':
            return $base + ['status' => 'ok', 'end' => true, 'output' => ['summary' => 'Workflow finished']];
    }
    return $base + ['status' => 'skipped', 'output' => ['summary' => 'Unknown step type — skipped']];
}

/** Assign a counsellor: specific user or round-robin over a pool (pointer kept in settings). */
function wa_wf_exec_assign(array $node, array $contact, array $wf, bool $applyCrm): array
{
    $d = $node['data'] ?? [];
    $mode = (string) ($d['mode'] ?? 'round_robin');
    if (!empty($d['keep_existing']) && !empty($contact['counsellor_id'])) {
        $name = db_value('SELECT name FROM users WHERE id = ?', [(int) $contact['counsellor_id']]);
        return ['status' => 'ok', 'output' => ['summary' => 'Already assigned to ' . ($name ?: 'a counsellor') . ' — kept', 'user_id' => (int) $contact['counsellor_id']]];
    }
    $eligible = array_column(wa_wf_counsellors(), 'name', 'id');
    if ($mode === 'specific') {
        $uid = (int) ($d['user_id'] ?? 0);
        $userName = db_value("SELECT name FROM users WHERE id = ? AND status = 'active'", [$uid]);
        if (!$userName) {
            return ['status' => 'failed', 'error' => 'The counsellor chosen in this step is not an active user any more.'];
        }
    } else {
        $pool = array_values(array_filter(array_map('intval', (array) ($d['pool'] ?? [])), fn ($id) => isset($eligible[$id])));
        if (!$pool) {
            $pool = array_map('intval', array_column(wa_wf_counsellors(true), 'id'));
        }
        if (!$pool) {
            return ['status' => 'failed', 'error' => 'No active counsellor is available for round-robin assignment.'];
        }
        sort($pool);
        // the pointer is read and moved under a lock, so two leads arriving together go to two counsellors
        $rrLock = $applyCrm && (int) db_value('SELECT GET_LOCK(?, 3)', ['gimt_wa_wf_rr']);
        try {
            $raw = db_value("SELECT value FROM settings WHERE `key` = 'wa_wf_round_robin'");
            $state = wa_wf_decode($raw !== null && $raw !== false ? (string) $raw : '{}', []);
            $key = $wf['id'] . ':' . $node['id'];
            $last = (int) ($state[$key] ?? 0);
            $uid = $pool[0];
            foreach ($pool as $candidate) {
                if ($candidate > $last) {
                    $uid = $candidate;
                    break;
                }
            }
            if ($applyCrm) {
                $state[$key] = $uid;
                save_setting('wa_wf_round_robin', wa_wf_json($state), 'whatsapp');
            }
        } finally {
            if ($rrLock) {
                db_value('SELECT RELEASE_LOCK(?)', ['gimt_wa_wf_rr']);
            }
        }
        $userName = $eligible[$uid] ?? db_value('SELECT name FROM users WHERE id = ?', [$uid]);
    }
    if (!$applyCrm) {
        return ['status' => 'skipped', 'output' => ['summary' => 'Would assign ' . $userName . ($mode === 'round_robin' ? ' (round-robin)' : '') . ' (CRM actions are off in this test)', 'user_id' => $uid]];
    }
    db_update('wa_contacts', ['counsellor_id' => $uid], 'id = ?', [(int) $contact['id']]);
    $conv = function_exists('wa_conversation_for') ? wa_conversation_for((int) $contact['id']) : null;
    if ($conv && empty($conv['assigned_to'])) {
        db_update('wa_conversations', ['assigned_to' => $uid], 'id = ?', [(int) $conv['id']]);
    }
    if (($d['sync_enquiry'] ?? true) && !empty($contact['enquiry_id'])) {
        db_exec('UPDATE enquiries SET assigned_to = ? WHERE id = ? AND assigned_to IS NULL', [$uid, (int) $contact['enquiry_id']]);
    }
    if (($d['notify'] ?? true) && function_exists('notify')) {
        // everything the counsellor needs to call the lead back: name, phone and course
        $course = trim((string) ($contact['program_name'] ?? ''));
        if ($course === '' && !empty($contact['enquiry_id'])) {
            $course = trim((string) db_value('SELECT COALESCE(p.name, e.program_interest) FROM enquiries e LEFT JOIN programs p ON p.id = e.program_id WHERE e.id = ?', [(int) $contact['enquiry_id']]));
        }
        $phone = function_exists('phone_display') ? phone_display('+' . $contact['phone']) : '+' . $contact['phone'];
        $body = implode(' · ', array_filter([trim((string) ($contact['name'] ?? '')), $phone, $course !== '' ? $course : 'course not given'], fn ($s) => $s !== ''));
        notify([$uid], 'whatsapp', 'New WhatsApp lead assigned to you — please call back', $body . ' — via workflow "' . $wf['name'] . '"',
            'admin/whatsapp/inbox' . ($conv ? '?c=' . (int) $conv['id'] : ''), 'user-plus');
    }
    if (function_exists('wa_agent_log')) {
        wa_agent_log('assign', $conv ? (int) $conv['id'] : null, (int) $contact['id'], 'Assigned to ' . $userName . ' by workflow "' . $wf['name'] . '"');
    }
    return ['status' => 'ok', 'contact' => array_merge($contact, ['counsellor_id' => $uid]), 'output' => ['summary' => 'Assigned to ' . $userName . ($mode === 'round_robin' ? ' (round-robin)' : ''), 'user_id' => $uid]];
}

/** API request / webhook step. */
function wa_wf_exec_http(array $node, array &$ctx, array $run, array $wf, ?array $contact, bool $isTest): array
{
    $d = $node['data'] ?? [];
    $v = wa_wf_vars($ctx, $contact, $wf, $run);
    $url = wa_wf_interpolate((string) ($d['url'] ?? ''), array_map('rawurlencode', $v));
    $continue = ($d['on_error'] ?? 'stop') === 'continue';
    if ($node['type'] === 'webhook') {
        $method = 'POST';
        $headers = ['X-GIMT-Event' => 'workflow.step', 'X-GIMT-Workflow' => (string) $wf['id'], 'X-GIMT-Run' => (string) $run['id']];
        $body = wa_wf_json([
            'event' => (string) (wa_wf_trigger_node(wa_wf_normalize_definition($ctx['_def'] ?? []))['data']['event'] ?? ''),
            'workflow' => ['id' => (int) $wf['id'], 'name' => $wf['name']], 'run_id' => (int) $run['id'], 'test' => $isTest,
            'contact' => $contact ? array_intersect_key($contact, array_flip(['id', 'name', 'phone', 'email', 'stage', 'contact_type', 'program_name', 'application_no', 'city', 'state', 'student_id', 'enquiry_id', 'admission_id', 'counsellor_id', 'opt_in'])) : null,
            'payload' => $ctx['payload'] ?? [], 'variables' => $ctx['vars'] ?? (object) [], 'last_reply' => $ctx['last_reply']['text'] ?? null, 'sent_at' => date('c'),
        ]);
    } else {
        $method = strtoupper((string) ($d['method'] ?? 'POST'));
        $headers = [];
        foreach ((array) ($d['headers'] ?? []) as $h) {
            if (is_array($h) && trim((string) ($h['key'] ?? '')) !== '') {
                $headers[trim((string) $h['key'])] = wa_wf_interpolate((string) ($h['value'] ?? ''), $v);
            }
        }
        $body = $method === 'GET' ? null : wa_wf_interpolate_body((string) ($d['body'] ?? ''), $v);
    }
    $safeUrl = preg_replace('/\?.*$/', '?…', $url);
    $out = ['method' => $method, 'url' => $safeUrl, 'headers' => array_keys($headers)];
    if ($isTest && empty($d['call_in_test'])) {
        return ['status' => 'skipped', 'output' => $out + ['summary' => 'Dry run: would ' . $method . ' ' . $safeUrl . ' (turn on "Call in test runs" to send it)', 'body_preview' => $body !== null ? mb_substr($body, 0, 500) : null]];
    }
    $r = wa_wf_http($method, $url, $headers, $body, 10);
    $out += ['status' => $r['status'], 'duration_ms' => $r['duration_ms'], 'response_preview' => isset($r['body']) ? mb_substr((string) $r['body'], 0, 500) : null];
    if (!empty($r['truncated'])) {
        $out['truncated'] = true;
    }
    if (!empty($r['blocked'])) {
        $out['blocked'] = true;
    }
    if ($r['ok'] && $node['type'] === 'api' && ($sv = trim((string) ($d['save_as'] ?? ''))) !== '') {
        $saved = is_array($r['json'] ?? null) && strlen(wa_wf_json($r['json'])) <= 16000 ? wa_wf_clean($r['json']) : ['body' => mb_substr((string) ($r['body'] ?? ''), 0, 2000)];
        $ctx['vars'][$sv] = (is_array($saved) ? $saved : []) + ['_status' => $r['status']];
        $out['saved_as'] = $sv;
    }
    if (!$r['ok']) {
        return ['status' => 'failed', 'continue' => $continue, 'output' => $out + ['summary' => ($node['type'] === 'webhook' ? 'Webhook' : 'API request') . ' failed' . ($continue ? ' — continuing' : '')], 'error' => $r['error'] ?? 'Request failed.'];
    }
    return ['status' => 'ok', 'output' => $out + ['summary' => $method . ' ' . $safeUrl . ' → HTTP ' . $r['status'] . ' in ' . $r['duration_ms'] . ' ms']];
}

/* ============================================================================================ runs: resume / retry / cancel */

/** Worker tick: resume runs whose delay / reply timeout / business-hours pause is due. */
function wa_wf_tick(float $budget = 8.0): array
{
    $t0 = microtime(true);
    $stats = ['due' => 0, 'resumed' => 0, 'completed' => 0, 'failed' => 0, 'waiting' => 0];
    try {
        $due = db_all("SELECT id, waiting_for FROM wa_workflow_runs WHERE status = 'waiting' AND next_run_at IS NOT NULL AND next_run_at <= ? ORDER BY next_run_at, id LIMIT 100", [date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        return $stats + ['error' => $e->getMessage()];
    }
    $stats['due'] = count($due);
    foreach ($due as $r) {
        if (microtime(true) - $t0 > $budget) {
            break;
        }
        // 'due' is re-checked under the run lock: a reply that arrived meanwhile wins over the timeout
        $res = wa_wf_execute((int) $r['id'], ($r['waiting_for'] === 'reply' ? ['port' => 'timeout', 'expect_wait' => 'reply'] : []) + ['due' => true]);
        if ($res['status'] === 'locked' || !empty($res['stale'])) {
            continue;
        }
        $stats['resumed']++;
        if (isset($stats[$res['status']])) {
            $stats[$res['status']]++;
        }
    }
    // runs stuck in "running" for 15+ minutes (process killed mid-way) become failed so they can be retried
    try {
        $stuck = db_all("SELECT id, workflow_id FROM wa_workflow_runs WHERE status = 'running' AND COALESCE(updated_at, started_at) < ? LIMIT 50", [date('Y-m-d H:i:s', time() - 900)]);
        foreach ($stuck as $s) {
            db_update('wa_workflow_runs', ['status' => 'failed', 'error' => 'The run was interrupted (server timeout). Retry it to continue from the last step.', 'finished_at' => date('Y-m-d H:i:s')], 'id = ? AND status = ?', [(int) $s['id'], 'running']);
            wa_wf_refresh_stats((int) $s['workflow_id']);
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $stats;
}

/** Inbound message: resume runs waiting for this contact's reply (replied branch) and remember the reply. */
function wa_wf_on_reply(array $event, array $payload = [], ?array $contact = null): ?array
{
    $cid = (int) ($payload['contact_id'] ?? ($contact['id'] ?? 0));
    if (!$cid) {
        return null;
    }
    $text = trim((string) ($payload['text'] ?? $payload['body'] ?? $payload['button_text'] ?? $payload['button_payload'] ?? ''));
    // one inbound message raises WA_MESSAGE_RECEIVED and (for a quick-reply tap) WA_BUTTON_CLICKED: it must resume a
    // wait only once, so the reply is keyed by its inbound message id
    $code = wa_wf_event_code($event);
    $msgId = $code === 'WA_BUTTON_CLICKED' ? ($payload['inbound_message_id'] ?? null) : ($payload['message_id'] ?? null);
    $replyKey = $msgId ? 'm' . (int) $msgId : ($code === 'WA_BUTTON_CLICKED' && !empty($payload['message_id']) ? 'c' . (int) $payload['message_id'] . ':' . md5($text) : null);
    $reply = ['text' => mb_substr($text, 0, 1000), 'at' => date('Y-m-d H:i:s'), 'message_id' => $msgId ? (int) $msgId : null, 'key' => $replyKey];
    $runs = db_all("SELECT id, status, waiting_for, current_node, context FROM wa_workflow_runs WHERE contact_id = ? AND status IN ('running', 'waiting') AND is_test = 0", [$cid]);
    $resumed = [];
    foreach ($runs as $r) {
        $ctx = wa_wf_decode($r['context'], []);
        if ($replyKey !== null && ($ctx['last_reply']['key'] ?? null) === $replyKey) {
            continue; // this reply was already handled for this run
        }
        if ($r['status'] === 'waiting' && $r['waiting_for'] === 'reply') {
            $node = wa_wf_node_map(wa_wf_normalize_definition($ctx['_def'] ?? []))[$r['current_node']] ?? null;
            $kw = array_filter(array_map(fn ($k) => mb_strtolower(trim($k)), explode(',', (string) ($node['data']['keywords'] ?? ''))));
            $match = !$kw || array_filter($kw, fn ($k) => str_contains(mb_strtolower($text), $k));
            if ($match) {
                // the state is re-checked under the run lock (the timeout may have resumed it a moment ago)
                $res = wa_wf_execute((int) $r['id'], ['port' => 'replied', 'reply' => $reply, 'expect_wait' => 'reply']);
                if (empty($res['stale']) && $res['status'] !== 'locked') {
                    $resumed[] = ['run_id' => (int) $r['id'], 'status' => $res['status']];
                    continue;
                }
            }
        }
        // remember the reply for later conditions ({{last_reply}}, reply.text) — under the lock, on the fresh context
        wa_wf_with_run_lock((int) $r['id'], function () use ($r, $reply) {
            $fresh = db_row("SELECT context FROM wa_workflow_runs WHERE id = ? AND status IN ('running', 'waiting')", [(int) $r['id']]);
            if ($fresh) {
                $c = wa_wf_decode($fresh['context'], []);
                $c['last_reply'] = $reply;
                db_update('wa_workflow_runs', ['context' => wa_wf_json($c)], 'id = ?', [(int) $r['id']]);
            }
        }, 2);
    }
    return $resumed ? ['workflow_runs_resumed' => $resumed] : null;
}

/** Opt-out: stop every active run of the contact. */
function wa_wf_on_opt_out(array $event, array $payload = [], ?array $contact = null): ?array
{
    $cid = (int) ($payload['contact_id'] ?? ($contact['id'] ?? 0));
    if (!$cid) {
        return null;
    }
    $ids = db_column("SELECT id FROM wa_workflow_runs WHERE contact_id = ? AND status IN ('running', 'waiting')", [$cid]);
    foreach ($ids as $id) {
        wa_wf_cancel((int) $id, 'The contact opted out of WhatsApp messages.');
    }
    return $ids ? ['workflow_runs_cancelled' => array_map('intval', $ids)] : null;
}

/** Re-run a failed run from the step that failed. */
function wa_wf_retry(int $runId): array
{
    $prep = wa_wf_with_run_lock($runId, function () use ($runId) {
        $run = db_row('SELECT * FROM wa_workflow_runs WHERE id = ?', [$runId]);
        if (!$run || $run['status'] !== 'failed') {
            throw new CrudException('Only failed runs can be retried.');
        }
        $wf = wa_wf_get((int) $run['workflow_id']);
        if (!$wf) {
            throw new CrudException('The workflow of this run was deleted.');
        }
        if ((int) $run['attempts'] >= 20) {
            throw new CrudException('This run was already retried 20 times — fix the cause, then enrol the contact again.');
        }
        $ctx = wa_wf_decode($run['context'], []);
        $node = $run['current_node'];
        $ctx['resume'] = ['node' => $node, 'port' => null];
        unset($ctx['waiting_step']);
        if (isset($ctx['visits'][$node])) {
            $ctx['visits'][$node] = max(0, (int) $ctx['visits'][$node] - 1);
        }
        $ctx['steps_total'] = max(0, (int) ($ctx['steps_total'] ?? 0) - 1);
        db_update('wa_workflow_runs', ['status' => 'running', 'error' => null, 'finished_at' => null, 'attempts' => min(255, (int) $run['attempts'] + 1), 'context' => wa_wf_json($ctx)], 'id = ?', [$runId]);
        wa_wf_step($runId, ['id' => $node, 'type' => 'retry', 'data' => []], 'ok', ['title' => 'Retry', 'summary' => 'Retried by ' . (function_exists('current_user') ? (current_user()['name'] ?? 'an admin') : 'an admin') . ' from step "' . $node . '"']);
        return ['wf' => $wf, 'is_test' => !empty($run['is_test'])];
    });
    if ($prep === null) {
        throw new CrudException('The run is busy right now — try again in a moment.');
    }
    $res = wa_wf_execute($runId);
    if (!$prep['is_test']) {
        wa_wf_refresh_stats((int) $prep['wf']['id']);
    }
    return $res;
}

function wa_wf_cancel(int $runId, string $reason): bool
{
    // under the run lock: a pass that is executing finishes first, then the run is stopped (never overwritten back)
    $done = wa_wf_with_run_lock($runId, function () use ($runId, $reason) {
        $run = db_row('SELECT * FROM wa_workflow_runs WHERE id = ?', [$runId]);
        if (!$run || !in_array($run['status'], ['running', 'waiting'], true)) {
            return false;
        }
        db_update('wa_workflow_runs', ['status' => 'cancelled', 'error' => mb_substr($reason, 0, 500), 'finished_at' => date('Y-m-d H:i:s'), 'next_run_at' => null, 'waiting_for' => null], 'id = ?', [$runId]);
        $ctx = wa_wf_decode($run['context'], []);
        if (!empty($ctx['waiting_step'])) {
            wa_wf_close_waiting_step((int) $ctx['waiting_step'], ['summary' => 'Cancelled while waiting'], 'skipped');
        }
        wa_wf_step($runId, ['id' => (string) ($run['current_node'] ?: 'run'), 'type' => 'cancel', 'data' => []], 'skipped', ['title' => 'Cancelled', 'summary' => $reason]);
        if (empty($run['is_test'])) {
            wa_wf_refresh_stats((int) $run['workflow_id']);
        }
        return true;
    }, 10);
    return (bool) $done;
}

/* ============================================================================================ test runs */

/**
 * Run a workflow now for a test contact with every wait fast-forwarded. $in: contact_id | phone, name, payload (array),
 * reply_mode (replied|timeout), reply_text, apply_crm (bool), definition (unsaved builder state).
 */
function wa_wf_test(array $wf, array $in): array
{
    $contact = null;
    if (!empty($in['contact_id'])) {
        $contact = wa_contact((int) $in['contact_id']);
        if (!$contact) {
            throw new CrudException('The selected contact does not exist.');
        }
    } else {
        $norm = wa_normalize_phone((string) ($in['phone'] ?? ''));
        if (!$norm) {
            throw new CrudValidationException(['phone' => 'Enter a valid WhatsApp number, e.g. 98765 43210.']);
        }
        $contact = wa_contact_by_phone($norm['phone']);
        if (!$contact) {
            $id = wa_contact_upsert(['phone' => $norm['phone'], 'name' => trim((string) ($in['name'] ?? '')) ?: 'Workflow test contact', 'source' => 'manual', 'contact_type' => 'other']);
            $contact = wa_contact((int) $id);
        }
    }
    $payload = is_array($in['payload'] ?? null) ? wa_wf_clean($in['payload']) : [];
    // identity always comes from the test contact (the sample payload may carry another person's details)
    $payload = array_merge($payload, array_filter(['contact_id' => (int) $contact['id'], 'phone' => '+' . $contact['phone'], 'name' => $contact['name'], 'email' => $contact['email'] ?? null]));
    $test = [
        'reply_mode' => ($in['reply_mode'] ?? 'replied') === 'timeout' ? 'timeout' : 'replied',
        'reply_text' => mb_substr(trim((string) ($in['reply_text'] ?? '')) ?: 'Yes, please share the details', 0, 500),
        'apply_crm' => !empty($in['apply_crm']),
        'by' => function_exists('user_id') ? user_id() : null,
    ];
    $opts = ['test' => $test, 'started_by' => function_exists('user_id') ? user_id() : null];
    if (!empty($in['definition'])) {
        $def = wa_wf_normalize_definition($in['definition']);
        $issues = wa_wf_validate($def, ['resolve' => true]);
        if (wa_wf_has_errors($issues)) {
            $first = array_values(array_filter($issues, fn ($i) => $i['level'] === 'error'))[0];
            throw new CrudValidationException(['definition' => $first['message']], 'Fix the errors in the workflow before testing it: ' . $first['message']);
        }
        $opts['definition'] = wa_wf_unmask_definition($def, wa_wf_definition($wf));
    }
    $r = wa_wf_start($wf, $contact, $payload, $opts);
    if (!$r['ok']) {
        throw new CrudException('The test run could not start: ' . wa_wf_reason_text((string) ($r['reason'] ?? 'unknown')) . '.');
    }
    return wa_wf_run_detail((int) $r['run_id']);
}

/* ============================================================================================ read models */

function wa_wf_cast_run(array $r): array
{
    foreach (['id', 'workflow_id', 'contact_id', 'event_log_id', 'attempts'] as $k) {
        if (array_key_exists($k, $r)) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
    }
    $r['is_test'] = !empty($r['is_test']);
    return $r;
}

function wa_wf_run_detail(int $runId): ?array
{
    $run = db_row("SELECT r.*, w.name AS workflow_name, w.version AS workflow_version, c.name AS contact_name, c.phone AS contact_phone, c.stage AS contact_stage,
                          (SELECT id FROM wa_conversations cv WHERE cv.contact_id = r.contact_id LIMIT 1) AS conversation_id
                   FROM wa_workflow_runs r JOIN wa_workflows w ON w.id = r.workflow_id LEFT JOIN wa_contacts c ON c.id = r.contact_id WHERE r.id = ?", [$runId]);
    if (!$run) {
        return null;
    }
    $ctx = wa_wf_decode($run['context'], []);
    $def = wa_wf_normalize_definition($ctx['_def'] ?? []);
    $nodes = wa_wf_node_map($def);
    unset($run['context']);
    $run = wa_wf_cast_run($run);
    $run['conversation_id'] = $run['conversation_id'] ? (int) $run['conversation_id'] : null;
    $run['current_node_label'] = isset($nodes[$run['current_node'] ?? '']) ? wa_wf_node_title($nodes[$run['current_node']]) : $run['current_node'];
    $steps = array_map(function ($s) use ($nodes) {
        $out = wa_wf_decode($s['output'], []);
        return ['id' => (int) $s['id'], 'node_id' => $s['node_id'], 'node_type' => $s['node_type'], 'status' => $s['status'],
            'title' => $out['title'] ?? (isset($nodes[$s['node_id']]) ? wa_wf_node_title($nodes[$s['node_id']]) : $s['node_type']),
            'summary' => $out['summary'] ?? null, 'output' => array_diff_key($out, ['title' => 1, 'summary' => 1]), 'error' => $s['error'], 'created_at' => $s['created_at']];
    }, db_all('SELECT * FROM wa_workflow_steps WHERE run_id = ? ORDER BY id', [$runId]));
    $messages = db_all("SELECT id, conversation_id, type, template_name, body, status, error, created_at, sent_at, delivered_at, read_at FROM wa_messages WHERE workflow_run_id = ? ORDER BY id", [$runId]);
    foreach ($messages as &$m) {
        $m['id'] = (int) $m['id'];
        $m['conversation_id'] = (int) $m['conversation_id'];
        $m['body'] = $m['body'] !== null ? mb_substr($m['body'], 0, 400) : null;
    }
    unset($m);
    return [
        'run' => $run, 'steps' => $steps, 'messages' => $messages,
        'context' => ['payload' => $ctx['payload'] ?? new stdClass(), 'last_reply' => $ctx['last_reply'] ?? null, 'vars' => $ctx['vars'] ?: new stdClass(), 'test' => $ctx['test'] ?? null, 'version' => $ctx['version'] ?? null],
        'nodes' => array_map(fn ($n) => ['id' => $n['id'], 'type' => $n['type'], 'title' => wa_wf_node_title($n)], $def['nodes']),
    ];
}

/** Workflow row for the API (definition masked). */
function wa_wf_present(array $wf): array
{
    $def = wa_wf_definition($wf);
    $out = $wf;
    foreach (['id', 'version', 'runs_total', 'runs_completed', 'runs_failed', 'created_by', 'updated_by'] as $k) {
        $out[$k] = $wf[$k] === null ? null : (int) $wf[$k];
    }
    $out['business_hours_only'] = !empty($wf['business_hours_only']);
    $out['allow_reentry'] = !empty($wf['allow_reentry']);
    $out['definition'] = wa_wf_mask_definition($def);
    $out['trigger_config'] = wa_wf_decode($wf['trigger_config'] ?? null, []);
    return $out;
}

/* ============================================================================================ hooks */

if (function_exists('wa_on')) {
    wa_on('*', 'wa_wf_on_event', 20);
    wa_on('WA_MESSAGE_RECEIVED', 'wa_wf_on_reply', 5);
    wa_on('WA_BUTTON_CLICKED', 'wa_wf_on_reply', 5);
    wa_on('WA_OPTED_OUT', 'wa_wf_on_opt_out', 5);
}
if (function_exists('wa_on_tick')) {
    wa_on_tick('workflows', 'wa_wf_tick');
}
