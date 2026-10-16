<?php
/**
 * Programs & Events hub (workflow 2, part P): programme categories, the extended events module
 * (workshops, seminars, yoga sessions, music programmes, hackathons ...), speakers, partners,
 * resources, reports / verified outcomes, registrations with waiting list and private joining links.
 *
 * Contract for the builders: scratchpad v3/programs/CONTRACT.md. Ownership:
 *   - data layer + migrations 600-629: architect (this file, top part and migrations)
 *   - public render / registration UI functions: P1 (this file, "Public rendering" part)
 *   - admin helpers: P2, in includes/programhub-admin.php (required at the bottom of this file)
 *
 * URLs:  /programs/                    hub (template programs)
 *        /programs/<category-slug>/    category page (template program_category, a `pages` row linked from program_categories.page_id)
 *        /events/                      canonical events list (template events; /events-page/ 301s here)
 *        /events/<slug>/               event page, /events/<slug>/ics/ calendar file, /events/<slug>/join/?r=&t= private joining page
 *        /hackathons/<slug>/           hackathon / innovation challenge page (types in HACKATHON_EVENT_TYPES, cms.php)
 *        /event-details/<slug>/        old address: 301 to the canonical one
 *
 * Privacy: events.meeting_url / meeting_info are PRIVATE - never print them in public HTML, JSON-LD, the sitemap,
 * the .ics file or a list. They are shown only on the tokenised joining page and in emails to registered participants.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// =====================================================================
// Constants
// =====================================================================

/** Registration statuses (event_registrations.status) => label. */
const EVENT_REG_STATUSES = [
    'registered' => 'Registered', 'confirmed' => 'Confirmed', 'waitlisted' => 'Waitlisted',
    'attended' => 'Attended', 'cancelled' => 'Cancelled', 'no_show' => 'No-show',
];
/** Statuses that hold a seat (count against events.capacity). */
const EVENT_SEAT_STATUSES = ['registered', 'confirmed', 'attended', 'no_show'];
/** Statuses that may open the private joining page / receive the meeting link. */
const EVENT_ACCESS_STATUSES = ['registered', 'confirmed', 'attended'];
/** How an event takes place (events.mode). */
const EVENT_MODES = ['in_person' => 'In person', 'online' => 'Online', 'hybrid' => 'Hybrid (in person and online)'];
/** Roles of a speaker / trainer at an event (event_speakers.role). */
const SPEAKER_ROLES = ['speaker' => 'Speaker', 'trainer' => 'Trainer', 'mentor' => 'Mentor', 'judge' => 'Judge', 'host' => 'Host / moderator', 'performer' => 'Performer'];
/** Kinds of partner organisations (partners.partner_type). */
const PARTNER_TYPES = [
    'institution' => 'Educational institution', 'sponsor' => 'Sponsor', 'community' => 'Community organisation',
    'knowledge' => 'Knowledge partner', 'media' => 'Media partner', 'government' => 'Government body', 'other' => 'Other',
];
/** Role of a partner at one event (event_partners.role). */
const EVENT_PARTNER_ROLES = [
    'partner' => 'Partner', 'host' => 'Host institution', 'sponsor' => 'Sponsor', 'supporter' => 'Supporter',
    'knowledge_partner' => 'Knowledge partner', 'media_partner' => 'Media partner', 'community_partner' => 'Community partner',
];
/** Downloads attached to an event (event_resources.resource_type). */
const EVENT_RESOURCE_TYPES = [
    'material' => 'Learning material', 'promo' => 'Poster / promotional material', 'slides' => 'Slides',
    'rules' => 'Rules / guidelines', 'report' => 'Report', 'other' => 'Other',
];
/** Who sends a partnership enquiry (contact_enquiries.partner_kind). */
const PARTNERSHIP_KINDS = [
    'institution' => 'School / college / institution', 'trainer' => 'Trainer / expert / speaker', 'sponsor' => 'Sponsor / CSR',
    'community' => 'Community organisation', 'other' => 'Other',
];
/** Upload folders. Registered-only resources are PRIVATE (served by a permission/token-checked endpoint). */
const PROGRAMHUB_UPLOADS = [
    'category' => 'events/categories', 'speaker' => 'speakers', 'partner' => 'partners',
    'resource_public' => 'events/files', 'resource_private' => 'documents/events',
];

// =====================================================================
// Program categories
// =====================================================================

/**
 * Categories with their page: c.* + page_slug, page_status, page_title, banner_image, banner_subtitle, meta_description.
 * $publishedOnly: category AND page published (what visitors may see). Cached per request; $refresh after admin changes.
 */
function program_categories_all(bool $publishedOnly = true, bool $refresh = false): array
{
    static $cache = [];
    $key = $publishedOnly ? 'pub' : 'all';
    if ($refresh) {
        $cache = [];
    }
    if (!isset($cache[$key])) {
        try {
            $cache[$key] = db_rows('SELECT c.*, p.slug AS page_slug, p.status AS page_status, p.title AS page_title, p.banner_image,
                                           p.banner_subtitle, p.meta_description
                                    FROM program_categories c LEFT JOIN pages p ON p.id = c.page_id'
                . ($publishedOnly ? " WHERE c.status = 'published' AND p.status = 'published'" : '')
                . ' ORDER BY c.sort_order, c.title');
        } catch (Throwable $e) {
            error_log('Program categories: ' . $e->getMessage());   // before migration 600
            $cache[$key] = [];
        }
    }
    return $cache[$key];
}

function program_category_by_id(int $id, bool $publishedOnly = false): ?array
{
    foreach (program_categories_all($publishedOnly) as $c) {
        if ((int) $c['id'] === $id) {
            return $c;
        }
    }
    return null;
}

function program_category_by_slug(string $slug, bool $publishedOnly = true): ?array
{
    foreach (program_categories_all($publishedOnly) as $c) {
        if ($c['slug'] === $slug) {
            return $c;
        }
    }
    return null;
}

/** Category whose landing page is this `pages` row (any status - the template decides visibility). */
function program_category_for_page(int $pageId): ?array
{
    foreach (program_categories_all(false) as $c) {
        if ((int) $c['page_id'] === $pageId) {
            return $c;
        }
    }
    return null;
}

/** [id => title] for admin selects (all categories). */
function program_category_options(bool $publishedOnly = false): array
{
    $out = [];
    foreach (program_categories_all($publishedOnly) as $c) {
        $out[(int) $c['id']] = (string) $c['title'];
    }
    return $out;
}

/** Category page URL (site path): the linked page's slug, else /programs/<slug>/. */
function program_category_url(array $cat): string
{
    return page_url(!empty($cat['page_slug']) ? (string) $cat['page_slug'] : 'programs/' . $cat['slug']);
}

function program_category_abs_url(array $cat): string
{
    return rtrim(BASE_URL, '/') . substr(program_category_url($cat), strlen(base_path()));
}

/** Card / hero image of a category: its own image, else the page banner, else a generic illustration. */
function program_category_art(array $cat): string
{
    foreach ([(string) ($cat['image'] ?? ''), (string) ($cat['banner_image'] ?? '')] as $path) {
        if ($path !== '' && strpos($path, '..') === false && is_file(ROOT_PATH . '/' . $path)) {
            return $path;
        }
    }
    return 'assets/images/programs/general.svg';
}

/** Ids of category pages that visitors must not see (category not published) - for the sitemap. */
function program_category_hidden_page_ids(): array
{
    $ids = [];
    foreach (program_categories_all(false) as $c) {
        if ($c['status'] !== 'published' && !empty($c['page_id'])) {
            $ids[] = (int) $c['page_id'];
        }
    }
    return $ids;
}

/** Where "Hackathons" links point: the hackathons category page, else the events list filtered to hackathons. */
function programhub_hackathons_url(): string
{
    $cat = program_category_by_slug('hackathons-innovation');
    return $cat ? program_category_url($cat) : page_url('events', ['type' => 'hackathon']);
}

/** URL of the canonical events list (/events/), optionally filtered: ['type' => 'workshop', 'category' => '<slug>', 'when' => 'past']. */
function events_list_url(array $query = []): string
{
    return page_url('events', array_filter($query, function ($v) { return $v !== '' && $v !== null; }));
}

/**
 * Topbar "Programs & Events" dropdown data for header.php (lead): flat list, in display order.
 * Each item: ['label', 'url', 'icon', 'description', 'group' => 'category'|'link', 'active' => bool].
 */
function programs_nav_items(): array
{
    $items = [];
    try {
        foreach (program_categories_all() as $c) {
            if ((int) $c['show_in_nav'] !== 1) {
                continue;
            }
            $url = program_category_url($c);
            $items[] = ['label' => (string) ($c['nav_label'] ?: $c['title']), 'url' => $url, 'icon' => (string) ($c['icon'] ?: 'calendar'),
                'description' => (string) $c['card_text'], 'group' => 'category', 'active' => is_current_url($url)];
        }
        foreach ([
            ['Upcoming events', events_list_url(), 'calendar', 'Workshops, seminars, sessions and community events'],
            ['Hackathons', programhub_hackathons_url(), 'target', 'Hackathons and innovation challenges'],
            ['All programmes', page_url('programs'), 'grid', 'Our programmes and areas of work'],
        ] as [$label, $url, $icon, $desc]) {
            $items[] = ['label' => $label, 'url' => $url, 'icon' => $icon, 'description' => $desc, 'group' => 'link', 'active' => is_current_url($url)];
        }
    } catch (Throwable $e) {
        error_log('programs_nav_items: ' . $e->getMessage());
    }
    return $items;
}

// =====================================================================
// Events: queries
// =====================================================================

/** SQL list of seat-holding statuses, e.g. "'registered','confirmed','attended','no_show'". */
function programhub_seat_status_sql(): string
{
    return "'" . implode("','", EVENT_SEAT_STATUSES) . "'";
}

/** Base SELECT for event lists: e.* + category_slug/title/icon, category_page_slug and `registered` (seats taken). */
function programhub_event_select_sql(): string
{
    return 'SELECT e.*, c.slug AS category_slug, c.title AS category_title, c.icon AS category_icon, cp.slug AS category_page_slug,
                   (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status IN (' . programhub_seat_status_sql() . ')) AS registered
            FROM events e LEFT JOIN program_categories c ON c.id = e.category_id LEFT JOIN pages cp ON cp.id = c.page_id';
}

/**
 * Public event list. Filters ($f, all optional):
 *   category_id int | when 'upcoming'|'past'|'open'|'all' (default upcoming) | types string[] | exclude_types string[]
 *   (default ['awareness_day'] when no types) | include_cancelled bool (default: true for upcoming/all) | limit int (default 12, max 100) | offset int
 * 'open' = registration (or the waiting list) is open now. Rows come from programhub_event_select_sql().
 */
function programhub_events(array $f = []): array
{
    [$where, $params, $order] = programhub_events_where($f);
    $limit = max(1, min(100, (int) ($f['limit'] ?? 12)));
    $offset = max(0, (int) ($f['offset'] ?? 0));
    $when = (string) ($f['when'] ?? 'upcoming');
    try {
        $rows = db_rows(programhub_event_select_sql() . " WHERE $where ORDER BY $order LIMIT " . ($when === 'open' ? 100 : $limit) . ' OFFSET ' . ($when === 'open' ? 0 : $offset), $params);
    } catch (Throwable $e) {
        error_log('programhub_events: ' . $e->getMessage());
        return [];
    }
    if ($when === 'open') {
        $rows = array_values(array_filter($rows, function ($ev) { return event_state($ev)['can_register']; }));
        $rows = array_slice($rows, $offset, $limit);
    }
    return $rows;
}

/** Number of rows programhub_events($f) can return (for pagination; 'open' is counted exactly). */
function programhub_events_count(array $f = []): int
{
    if (($f['when'] ?? '') === 'open') {
        return count(programhub_events(['limit' => 100] + $f));
    }
    [$where, $params] = programhub_events_where($f);
    try {
        return (int) db_value("SELECT COUNT(*) FROM events e WHERE $where", $params);
    } catch (Throwable $e) {
        return 0;
    }
}

/** [where, params, order] for programhub_events(). */
function programhub_events_where(array $f): array
{
    $when = in_array($f['when'] ?? 'upcoming', ['upcoming', 'past', 'open', 'all'], true) ? (string) ($f['when'] ?? 'upcoming') : 'upcoming';
    $cancelled = array_key_exists('include_cancelled', $f) ? !empty($f['include_cancelled']) : in_array($when, ['upcoming', 'all'], true);
    $where = $cancelled ? "e.status IN ('published','cancelled')" : "e.status = 'published'";
    $params = [];
    $now = date('Y-m-d H:i:s');
    if ($when === 'upcoming') {
        $where .= ' AND COALESCE(e.end_datetime, e.start_datetime) >= ?';
        $params[] = $now;
    } elseif ($when === 'past') {
        $where .= ' AND COALESCE(e.end_datetime, e.start_datetime) < ?';
        $params[] = $now;
    } elseif ($when === 'open') {
        $where .= ' AND e.registration_enabled = 1 AND e.start_datetime > ? AND (e.registration_deadline IS NULL OR e.registration_deadline > ?)
                    AND (e.registration_opens_at IS NULL OR e.registration_opens_at <= ?)';
        array_push($params, $now, $now, $now);
    }
    if (!empty($f['category_id'])) {
        $where .= ' AND e.category_id = ?';
        $params[] = (int) $f['category_id'];
    }
    $types = array_values(array_filter((array) ($f['types'] ?? []), function ($t) { return isset(EVENT_TYPES[$t]); }));
    if ($types) {
        $where .= ' AND e.event_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        $params = array_merge($params, $types);
    } else {
        $exclude = array_values(array_filter((array) ($f['exclude_types'] ?? ['awareness_day']), function ($t) { return isset(EVENT_TYPES[$t]); }));
        if ($exclude) {
            $where .= ' AND e.event_type NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
            $params = array_merge($params, $exclude);
        }
    }
    $order = $when === 'past' ? 'e.start_datetime DESC, e.id DESC' : 'e.start_datetime ASC, e.id ASC';
    return [$where, $params, $order];
}

/** One event by slug with category data and `registered`. $publicOnly: published or cancelled only. */
function programhub_event_by_slug(string $slug, bool $publicOnly = true): ?array
{
    try {
        return db_row(programhub_event_select_sql() . ' WHERE e.slug = ?' . ($publicOnly ? " AND e.status IN ('published','cancelled')" : ''), [$slug]);
    } catch (Throwable $e) {
        error_log('programhub_event_by_slug: ' . $e->getMessage());
        return null;
    }
}

/** The category row of an event (published categories only unless $any), or null. */
function programhub_event_category(array $ev, bool $any = false): ?array
{
    return !empty($ev['category_id']) ? program_category_by_id((int) $ev['category_id'], !$any) : null;
}

/** Decode a JSON list column (agenda, faqs, outcomes) to an array of arrays. */
function programhub_json_list($value): array
{
    $list = json_field($value);
    return array_values(array_filter($list, 'is_array'));
}

/**
 * Speakers / trainers of an event: s.* + role, session_title. $publicOnly: active profiles with profile_consent = 1
 * (photo only when photo_consent = 1 - the caller must check). Never print email/phone publicly.
 */
function programhub_event_speakers(int $eventId, bool $publicOnly = true): array
{
    try {
        return db_rows('SELECT s.*, es.role, es.session_title FROM event_speakers es JOIN speakers s ON s.id = es.speaker_id
                        WHERE es.event_id = ?' . ($publicOnly ? " AND s.status = 'active' AND s.profile_consent = 1" : '') . '
                        ORDER BY es.sort_order, s.sort_order, s.name', [$eventId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Partner organisations of an event: p.* + role. $publicOnly: active partners with display_consent = 1 (documented permission). */
function programhub_event_partners(int $eventId, bool $publicOnly = true): array
{
    try {
        return db_rows('SELECT p.*, ep.role FROM event_partners ep JOIN partners p ON p.id = ep.partner_id
                        WHERE ep.event_id = ?' . ($publicOnly ? " AND p.status = 'active' AND p.display_consent = 1" : '') . '
                        ORDER BY ep.sort_order, p.sort_order, p.name', [$eventId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Downloads of an event. $visibility 'public' (published public files) | 'registered' (published, both kinds) | 'all' (admin). */
function programhub_event_resources(int $eventId, string $visibility = 'public'): array
{
    $where = 'event_id = ?';
    if ($visibility === 'public') {
        $where .= " AND visibility = 'public' AND status = 'published'";
    } elseif ($visibility === 'registered') {
        $where .= " AND status = 'published'";
    }
    try {
        return db_rows("SELECT * FROM event_resources WHERE $where ORDER BY sort_order, id", [$eventId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Report & verified outcomes of an event (outcomes decoded to a list of [label, value, note]). */
function programhub_event_report(int $eventId, bool $publishedOnly = true): ?array
{
    try {
        $row = db_row('SELECT * FROM event_reports WHERE event_id = ?' . ($publishedOnly ? " AND status = 'published'" : ''), [$eventId]);
    } catch (Throwable $e) {
        return null;
    }
    if ($row) {
        $row['outcomes_list'] = programhub_json_list($row['outcomes']);
    }
    return $row;
}

/** Published, consented gallery photos/videos linked to an event. */
function programhub_event_gallery(int $eventId, int $limit = 24): array
{
    $limit = max(1, min(100, $limit));
    try {
        return db_rows("SELECT * FROM gallery_items WHERE event_id = ? AND status = 'published' AND consent_confirmed = 1
                        ORDER BY sort_order, id DESC LIMIT $limit", [$eventId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Registrations marked attended (the only automatic attendance figure we publish). */
function programhub_attended_count(int $eventId): int
{
    return (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status = 'attended'", [$eventId]);
}

/**
 * Verified participant number for a completed event, or null when there is none to show:
 * the staff-verified figure of a published report, else registrations marked attended (if any).
 */
function programhub_verified_participants(array $ev): ?int
{
    if (!event_is_past($ev)) {
        return null;
    }
    $report = programhub_event_report((int) $ev['id']);
    if ($report && $report['participants_verified'] !== null && $report['verified_at']) {
        return (int) $report['participants_verified'];
    }
    $attended = programhub_attended_count((int) $ev['id']);
    return $attended > 0 ? $attended : null;
}

// =====================================================================
// Events: status, scheduling
// =====================================================================

/**
 * Public status of an event (computed server-side, never stored):
 * ['key' => draft|scheduled|archived|cancelled|completed|ongoing|registration_open|waitlist_open|full|registration_closed|upcoming,
 *  'label', 'tone' => green|blue|gold|red|gray, 'icon']
 */
function programhub_event_status(array $ev, ?array $state = null): array
{
    $state = $state ?? event_state($ev);
    $status = (string) ($ev['status'] ?? 'draft');
    if (in_array($status, ['draft', 'scheduled', 'archived'], true)) {
        $key = $status;
    } elseif ($status === 'cancelled') {
        $key = 'cancelled';
    } elseif ($state['is_past']) {
        $key = 'completed';
    } elseif ($state['started']) {
        $key = 'ongoing';
    } elseif ($state['open']) {
        $key = 'registration_open';
    } elseif ($state['waitlist_open']) {
        $key = 'waitlist_open';
    } elseif ((int) ($ev['registration_enabled'] ?? 0) === 1 && $state['is_full']) {
        $key = 'full';
    } elseif ((int) ($ev['registration_enabled'] ?? 0) === 1 && ($state['deadline_passed'] || $state['started'])) {
        $key = 'registration_closed';
    } else {
        $key = 'upcoming';   // registration not required, or not open yet
    }
    $map = [
        'draft' => ['Draft', 'gray', 'edit'], 'scheduled' => ['Scheduled', 'blue', 'clock'], 'archived' => ['Archived', 'gray', 'inbox'],
        'cancelled' => ['Cancelled', 'red', 'x'], 'completed' => ['Completed', 'blue', 'check-circle'], 'ongoing' => ['In progress', 'green', 'activity'],
        'registration_open' => ['Registration open', 'green', 'check'], 'waitlist_open' => ['Full - waiting list open', 'gold', 'users'],
        'full' => ['Full', 'gold', 'users'], 'registration_closed' => ['Registration closed', 'gray', 'lock'], 'upcoming' => ['Upcoming', 'blue', 'calendar'],
    ];
    [$label, $tone, $icon] = $map[$key];
    if ($key === 'upcoming' && $state['not_yet_open'] && !empty($state['opens_at'])) {
        $label = 'Registration opens ' . date('d M', (int) $state['opens_at']);
    }
    return ['key' => $key, 'label' => $label, 'tone' => $tone, 'icon' => $icon];
}

/** Status pill HTML for public pages and admin lists. */
function programhub_status_badge(array $ev, ?array $state = null): string
{
    $s = programhub_event_status($ev, $state);
    return '<span class="badge badge-' . e($s['tone']) . ' ev-status ev-status-' . e(str_replace('_', '-', $s['key'])) . '">' . icon($s['icon']) . e($s['label']) . '</span>';
}

/**
 * Scheduled publishing: events with status 'scheduled' become 'published' at publish_at.
 * Runs on every request but costs nothing until the earliest publish_at (setting programhub_next_publish) has passed.
 * P2 must call programhub_refresh_publish_schedule() after saving an event.
 */
function programhub_publish_due(bool $force = false): int
{
    $next = setting('programhub_next_publish');
    if (!$force && ($next === '' || strtotime($next) > time())) {
        return 0;
    }
    $n = 0;
    try {
        $due = db_rows("SELECT id, title FROM events WHERE status = 'scheduled' AND publish_at IS NOT NULL AND publish_at <= NOW()");
        foreach ($due as $ev) {
            $n += db_query("UPDATE events SET status = 'published' WHERE id = ? AND status = 'scheduled'", [$ev['id']])->rowCount();
            log_activity('event_published_scheduled', 'Event #' . $ev['id'] . ' "' . $ev['title'] . '" published at its scheduled time', null, true);
        }
        programhub_refresh_publish_schedule();
        if ($n) {
            hackathon_event_slugs(true);
        }
    } catch (Throwable $e) {
        error_log('programhub_publish_due: ' . $e->getMessage());
    }
    return $n;
}

/** Store the earliest pending publish_at (or '') so programhub_publish_due() knows when to look again. */
function programhub_refresh_publish_schedule(): void
{
    try {
        $next = (string) db_value("SELECT MIN(publish_at) FROM events WHERE status = 'scheduled' AND publish_at IS NOT NULL");
        if ($next !== setting('programhub_next_publish')) {
            save_setting('programhub_next_publish', $next);
        }
    } catch (Throwable $e) {
        error_log('programhub_refresh_publish_schedule: ' . $e->getMessage());
    }
}

// =====================================================================
// Registrations
// =====================================================================

/**
 * Register a participant for an event, safely under concurrency (transaction + SELECT ... FOR UPDATE on the event row).
 * Enforces: event published and registration open (deadline, opening time, start), seat limit, optional waiting list,
 * one registration per email per event (a cancelled one is re-activated), privacy consent, guardian consent for minors.
 * Field validation (lengths, phone/email format) is the caller's job (validate()); this re-checks the essentials.
 *
 * $data: name, email, phone (required) | organisation, city, message, team_name | answers (array -> JSON) |
 *        consent (truthy, required) | is_minor (bool) + guardian_name, guardian_phone, guardian_consent (truthy) when minor |
 *        user_id (default current_user_id()) | source ('event_page'|'category_page'|'admin', default 'event_page')
 * $opts: waitlist (bool, default true: use the waiting list when full and the event allows it) |
 *        admin (bool: staff adds a registration - skips the "registration open" check, still respects capacity unless 'ignore_capacity')
 *
 * Returns ['result' => 'ok'|'waitlisted'|'duplicate'|'full'|'closed'|'invalid'|'error', 'id' => int, 'status' => string,
 *          'token' => string (plain private-access token - only on ok/waitlisted; put it in the email link, never store it),
 *          'message' => string (safe to show), 'event' => array (locked fresh row)]
 */
function programhub_register(int $eventId, array $data, array $opts = []): array
{
    $out = ['result' => 'error', 'id' => 0, 'status' => '', 'token' => '', 'message' => 'Your registration could not be saved. Please try again in a few minutes.', 'event' => []];
    $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
    $name = trim((string) ($data['name'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $minor = !empty($data['is_minor']);
    if ($name === '' || $phone === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['result' => 'invalid', 'message' => 'Please enter your name, a valid email address and a mobile number.'] + $out;
    }
    if (empty($data['consent'])) {
        return ['result' => 'invalid', 'message' => 'Please agree to the use of your details for this registration.'] + $out;
    }
    if ($minor && (trim((string) ($data['guardian_name'] ?? '')) === '' || trim((string) ($data['guardian_phone'] ?? '')) === '' || empty($data['guardian_consent']))) {
        return ['result' => 'invalid', 'message' => 'Participants under 18 need the name, mobile number and consent of a parent or guardian.'] + $out;
    }
    $isAdmin = !empty($opts['admin']);
    $now = date('Y-m-d H:i:s');
    $token = bin2hex(random_bytes(24));
    $row = [
        'user_id' => array_key_exists('user_id', $data) ? ($data['user_id'] ? (int) $data['user_id'] : null) : current_user_id(),
        'name' => mb_substr($name, 0, 120), 'phone' => mb_substr($phone, 0, 20),
        'message' => trim((string) ($data['message'] ?? '')) !== '' ? mb_substr(trim((string) $data['message']), 0, 500) : null,
        'organisation' => trim((string) ($data['organisation'] ?? '')) !== '' ? mb_substr(trim((string) $data['organisation']), 0, 200) : null,
        'city' => trim((string) ($data['city'] ?? '')) !== '' ? mb_substr(trim((string) $data['city']), 0, 100) : null,
        'team_name' => trim((string) ($data['team_name'] ?? '')) !== '' ? mb_substr(trim((string) $data['team_name']), 0, 150) : null,
        'answers' => !empty($data['answers']) && is_array($data['answers']) ? json_encode($data['answers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'is_minor' => $minor ? 1 : 0,
        'guardian_name' => $minor ? mb_substr(trim((string) $data['guardian_name']), 0, 120) : null,
        'guardian_phone' => $minor ? mb_substr(trim((string) $data['guardian_phone']), 0, 20) : null,
        'guardian_consent_at' => $minor ? $now : null,
        'consent_at' => $now,
        'source' => in_array($data['source'] ?? '', ['event_page', 'category_page', 'admin', 'import'], true) ? $data['source'] : ($isAdmin ? 'admin' : 'event_page'),
        'access_token_hash' => programhub_token_hash($token),
        'cancelled_at' => null,
    ];

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $ev = db_row('SELECT * FROM events WHERE id = ? FOR UPDATE', [$eventId]);
        if (!$ev) {
            $pdo->rollBack();
            return ['result' => 'closed', 'message' => 'This event is not available.'] + $out;
        }
        $ev['registered'] = (int) db_value('SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status IN (' . programhub_seat_status_sql() . ')', [$eventId]);
        $out['event'] = $ev;
        $state = event_state($ev);
        if (!$isAdmin && !$state['can_register']) {
            $pdo->rollBack();
            return ['result' => 'closed', 'message' => $state['reason'] ?: 'Registration is closed for this event.'] + $out;
        }
        $existing = db_row('SELECT id, status FROM event_registrations WHERE event_id = ? AND email = ? FOR UPDATE', [$eventId, $email]);
        if ($existing && $existing['status'] !== 'cancelled') {
            $pdo->rollBack();
            return ['result' => 'duplicate', 'id' => (int) $existing['id'], 'status' => $existing['status'],
                'message' => 'This email address is already registered for this event. Check your inbox for the confirmation, or contact us if you need to change your details.'] + $out;
        }
        $full = $state['capacity'] !== null && $ev['registered'] >= $state['capacity'] && empty($opts['ignore_capacity']);
        if ($full && (empty($ev['waitlist_enabled']) || (isset($opts['waitlist']) && !$opts['waitlist']))) {
            $pdo->rollBack();
            return ['result' => 'full', 'message' => 'Sorry, all places for this event have just been taken.'] + $out;
        }
        $row['status'] = $full ? 'waitlisted' : 'registered';
        if ($existing) {
            db_update('event_registrations', $row, 'id = :id', ['id' => $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = db_insert('event_registrations', $row + ['event_id' => $eventId, 'email' => $email]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
            return ['result' => 'duplicate', 'message' => 'This email address is already registered for this event.'] + $out;
        }
        error_log('programhub_register: ' . $e->getMessage());
        return $out;
    }
    $waitlisted = $row['status'] === 'waitlisted';
    return ['result' => $waitlisted ? 'waitlisted' : 'ok', 'id' => $id, 'status' => $row['status'], 'token' => $token,
        'message' => $waitlisted ? 'The event is full, so you have been added to the waiting list. We will email you if a place becomes available.' : 'You are registered.',
        'event' => $out['event']];
}

/**
 * Change a registration's status and stamp confirmed_at / attended_at / cancelled_at. Returns false when unchanged/invalid.
 * Does not send email and does not write admin_log (callers do). Restoring a cancelled/waitlisted registration
 * does not check capacity - admins may overbook knowingly.
 */
function programhub_set_registration_status(int $regId, string $status): bool
{
    if (!isset(EVENT_REG_STATUSES[$status])) {
        return false;
    }
    $set = ['status' => $status];
    $stamp = ['confirmed' => 'confirmed_at', 'attended' => 'attended_at', 'cancelled' => 'cancelled_at'][$status] ?? null;
    if ($stamp) {
        $set[$stamp] = date('Y-m-d H:i:s');
    }
    return db_update('event_registrations', $set, 'id = :id AND status <> :st', ['id' => $regId, 'st' => $status]) > 0;
}

/**
 * Move the earliest waitlisted registration into a free seat (call after a cancellation or a capacity increase).
 * Returns the promoted registration row (status 'registered'), or null. The caller emails the participant.
 */
function programhub_promote_waitlist(int $eventId): ?array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $ev = db_row('SELECT id, capacity FROM events WHERE id = ? FOR UPDATE', [$eventId]);
        $taken = (int) db_value('SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status IN (' . programhub_seat_status_sql() . ')', [$eventId]);
        $next = $ev && (!$ev['capacity'] || $taken < (int) $ev['capacity'])
            ? db_row("SELECT * FROM event_registrations WHERE event_id = ? AND status = 'waitlisted' ORDER BY created_at, id LIMIT 1 FOR UPDATE", [$eventId])
            : null;
        if ($next) {
            db_query("UPDATE event_registrations SET status = 'registered' WHERE id = ?", [$next['id']]);
            $next['status'] = 'registered';
        }
        $pdo->commit();
        return $next ?: null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('programhub_promote_waitlist: ' . $e->getMessage());
        return null;
    }
}

// =====================================================================
// Private joining links (meeting link for registered participants only)
// =====================================================================

/** Tokens are stored only as SHA-256 hashes (a leaked database does not reveal working links). */
function programhub_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Issue a new private-access token for a registration (invalidates the previous link). Returns the plain token. */
function programhub_issue_access_token(int $regId): string
{
    $token = bin2hex(random_bytes(24));
    db_query('UPDATE event_registrations SET access_token_hash = ? WHERE id = ?', [programhub_token_hash($token), $regId]);
    return $token;
}

/** Absolute URL of the private joining page: <event>/join/?r=<id>&t=<token>. Only ever put it in an email to the participant. */
function programhub_join_url(array $event, int $regId, string $token): string
{
    return event_abs_url($event) . 'join/?' . http_build_query(['r' => $regId, 't' => $token]);
}

/** Registration row for a valid (event, registration id, token) triple, or null. Constant-time comparison. */
function programhub_registration_by_token(int $eventId, int $regId, string $token): ?array
{
    if ($regId <= 0 || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $reg = db_row('SELECT * FROM event_registrations WHERE id = ? AND event_id = ?', [$regId, $eventId]);
    if (!$reg || empty($reg['access_token_hash']) || !hash_equals((string) $reg['access_token_hash'], programhub_token_hash($token))) {
        return null;
    }
    return $reg;
}

/**
 * May this registration see the meeting link now? Registered / confirmed / attended, event published (not cancelled),
 * event is online or hybrid with a meeting link, and the event ended less than a day ago.
 */
function programhub_can_access_meeting(array $event, array $reg): bool
{
    return in_array($reg['status'], EVENT_ACCESS_STATUSES, true)
        && ($event['status'] ?? '') === 'published'
        && in_array(event_mode($event), ['online', 'hybrid'], true)
        && trim((string) ($event['meeting_url'] ?? '')) !== ''
        && event_end_ts($event) > time() - 86400;
}

/**
 * Registration of the signed-in member for an event (their user_id, or their verified email), or null.
 * Used by the member dashboard / event page to offer the joining page without a token.
 */
function programhub_member_registration(int $eventId): ?array
{
    $user = current_user();
    if (!$user) {
        return null;
    }
    return !empty($user['email_verified_at'])
        ? db_row("SELECT * FROM event_registrations WHERE event_id = ? AND (user_id = ? OR email = ?) AND status <> 'cancelled' ORDER BY id LIMIT 1", [$eventId, $user['id'], $user['email']])
        : db_row("SELECT * FROM event_registrations WHERE event_id = ? AND user_id = ? AND status <> 'cancelled' ORDER BY id LIMIT 1", [$eventId, $user['id']]);
}

// =====================================================================
// Public rendering (P1 owns and polishes everything below this line up to the migrations)
// =====================================================================

/** Category card for the hub / grids (reuses the programme card design). */
function program_category_card(array $cat, array $opts = []): string
{
    $url = program_category_url($cat);
    $art = program_category_art($cat);
    $tone = in_array($cat['tone'] ?? '', array_keys(program_tones()), true) ? $cat['tone'] : 'green';
    $i = (int) ($opts['index'] ?? 0);
    $count = isset($opts['upcoming']) ? (int) $opts['upcoming'] : null;
    return '<article class="program-card pc-card tone-' . e($tone) . ' reveal tilt" style="--d:' . ($i % 4) . '">'
        . '<div class="program-card-media' . (strpos($art, 'uploads/') === 0 ? ' is-photo' : '') . '"><img src="' . e(image_url($art)) . '" alt="" loading="lazy" width="480" height="360"></div>'
        . '<div class="program-card-body"><span class="program-card-icon">' . icon((string) ($cat['icon'] ?: 'calendar')) . '</span>'
        . '<h3><a class="program-card-link" href="' . e($url) . '">' . e($cat['title']) . '</a></h3>'
        . '<p>' . e((string) $cat['card_text']) . '</p>'
        . ($count ? '<p class="pc-upcoming">' . icon('calendar') . $count . ' upcoming ' . ($count === 1 ? 'session' : 'sessions') . '</p>' : '')
        . '<span class="link-arrow" aria-hidden="true">Explore ' . icon('arrow-right') . '</span></div></article>';
}

/** Number of upcoming public events per category id (one query). */
function programhub_upcoming_counts(): array
{
    $out = [];
    try {
        foreach (db_rows("SELECT category_id, COUNT(*) AS n FROM events WHERE status = 'published' AND category_id IS NOT NULL
                          AND COALESCE(end_datetime, start_datetime) >= NOW() GROUP BY category_id") as $r) {
            $out[(int) $r['category_id']] = (int) $r['n'];
        }
    } catch (Throwable $e) {
        error_log('programhub_upcoming_counts: ' . $e->getMessage());
    }
    return $out;
}

/** Event card with live status (date, time, venue / mode, eligibility, status). Rows from programhub_events(). */
function programhub_event_card(array $ev, int $i = 0): string
{
    $link = event_url($ev);
    $type = (string) ($ev['event_type'] ?? 'event');
    $state = event_state($ev);
    $mode = event_mode($ev);
    $where = $mode === 'online' ? 'Online' : trim((string) $ev['location']) . ($mode === 'hybrid' ? ' + online' : '');
    $html = '<article class="event-card ev-card ph-event-card reveal" style="--d:' . ($i % 4) . '">'
        . '<a class="event-media" href="' . e($link) . '" tabindex="-1" aria-hidden="true"><img src="' . e(image_url($ev['image'] ?? null, CONTENT_FALLBACK_IMAGE)) . '"' . image_srcset_attr($ev['image'] ?? null, '(max-width: 600px) 100vw, (max-width: 1100px) 50vw, 400px') . ' alt="" loading="lazy" width="480" height="300"></a>'
        . content_date_badge((string) $ev['start_datetime'])
        . '<div class="event-body"><span class="ev-chip ev-type-' . e($type) . '">' . icon(EVENT_TYPE_ICONS[$type] ?? 'calendar') . e(event_type_label($type)) . '</span> '
        . programhub_status_badge($ev, $state)
        . '<h3><a href="' . e($link) . '">' . e($ev['title']) . '</a></h3>'
        . '<ul class="meta-list"><li>' . icon('clock') . '<span>' . e(event_when($ev)) . '</span></li>'
        . '<li>' . icon($mode === 'online' ? 'monitor' : 'map-pin') . '<span>' . e($where) . '</span></li>';
    if (trim((string) ($ev['eligibility'] ?? '')) !== '') {
        $html .= '<li>' . icon('users') . '<span>' . e(excerpt((string) $ev['eligibility'], 90)) . '</span></li>';
    }
    if (!empty($state['deadline']) && !$state['is_past'] && ($state['open'] || $state['waitlist_open'])) {
        $html .= '<li>' . icon('alert-circle') . '<span>Register by ' . e(date('d M Y, h:i A', (int) $state['deadline'])) . '</span></li>';
    }
    $html .= '</ul><p>' . e(excerpt((string) ($ev['summary'] ?? ''), 130)) . '</p>';
    if ($state['is_past']) {
        $n = programhub_verified_participants($ev);
        if ($n) {
            $html .= '<p class="ph-verified">' . icon('check-circle') . '<span>' . $n . ' participant' . ($n === 1 ? '' : 's') . ' (verified)</span></p>';
        }
    }
    return $html . '<a class="link-arrow" href="' . e($link . ($state['can_register'] ? '#register' : '')) . '">' . ($state['can_register'] ? 'View and register' : ($state['is_past'] ? 'See the report' : 'View details'))
        . icon('arrow-right') . '<span class="sr-only">: ' . e($ev['title']) . '</span></a></div></article>';
}

// ----- Section types (CMS blocks; schemas are used by the page editor) -----

function section_schema_program_categories_grid(): array
{
    return [
        'label' => 'Programs & Events categories (cards)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => ['limit' => ['number', 'How many (1-24, empty = all)'], 'hide_counts' => ['checkbox', 'Hide the number of upcoming sessions']],
        'help' => 'Cards for the published categories from Admin > Program categories, in their order.',
    ];
}

function section_program_categories_grid(array $s, array $items, array $set, array $page): string
{
    $cats = program_categories_all();
    $limit = (int) ($set['limit'] ?? 0);
    if ($limit > 0) {
        $cats = array_slice($cats, 0, min(24, $limit));
    }
    if (!$cats) {
        return '';
    }
    $counts = empty($set['hide_counts']) ? programhub_upcoming_counts() : [];
    $html = '<div class="program-grid pc-grid">';
    foreach ($cats as $i => $c) {
        $html .= program_category_card($c, ['index' => $i, 'upcoming' => $counts[(int) $c['id']] ?? 0]);
    }
    return $html . '</div>';
}

function section_schema_program_events(): array
{
    $types = EVENT_TYPES;
    unset($types['awareness_day']);
    return [
        'label' => 'Programmes & events (live list)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => [
            'when' => ['select', 'Show', ['upcoming' => 'Upcoming (with registration status)', 'open' => 'Open for registration', 'past' => 'Completed (past) programmes']],
            'category' => ['select', 'Category', ['' => 'This page\'s category (all categories elsewhere)'] + program_category_options()],
            'type' => ['select', 'Event type', ['' => 'All types'] + $types],
            'limit' => ['number', 'How many (1-24)'],
            'empty_text' => ['text', 'Text when there is nothing to show (optional)'],
            'hide_empty' => ['checkbox', 'Hide the section when there is nothing to show'],
        ],
        'help' => 'Live list of published events from Admin > Events. Past programmes show verified participant numbers only.',
    ];
}

function section_program_events(array $s, array $items, array $set, array $page): string
{
    $when = in_array($set['when'] ?? 'upcoming', ['upcoming', 'open', 'past'], true) ? (string) ($set['when'] ?? 'upcoming') : 'upcoming';
    $cat = null;
    if (!empty($set['category'])) {
        $cat = program_category_by_id((int) $set['category']);
    } elseif (!empty($page['id'])) {
        $cat = program_category_for_page((int) $page['id']);
    }
    $limit = max(1, min(24, (int) ($set['limit'] ?? 6) ?: 6));
    $f = ['when' => $when, 'limit' => $limit, 'category_id' => $cat ? (int) $cat['id'] : 0];
    if (!empty($set['type']) && isset(EVENT_TYPES[$set['type']])) {
        $f['types'] = [(string) $set['type']];
    }
    $rows = programhub_events($f);
    if (!$rows) {
        if (!empty($set['hide_empty'])) {
            return '';
        }
        $text = trim((string) ($set['empty_text'] ?? ''));
        if ($when === 'past') {
            return empty_state($text !== '' ? $text : 'Completed sessions, with photos, short reports and verified outcomes, will appear here after they take place.');
        }
        $hasForm = !empty($page['id']) && (int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND section_type = 'program_registration' AND status = 'visible'", [$page['id']]) > 0;
        $what = $cat ? 'New ' . $cat['title'] . ' sessions' : 'New workshops and events';
        return '<div class="empty-state ph-empty">' . icon('calendar') . '<p>' . e($text !== '' ? $text : $what . ' will be announced here — register your interest and we will let you know.') . '</p>'
            . '<div class="btn-row center"><a class="btn btn-primary" href="' . e($hasForm ? '#register' : page_url('contact')) . '">' . icon('bell') . 'Register your interest</a>'
            . '<a class="btn btn-ghost" href="' . e(events_list_url()) . '">' . icon('calendar') . 'All upcoming events</a></div></div>';
    }
    $html = '<div class="card-grid ph-event-grid">';
    foreach ($rows as $i => $ev) {
        $html .= programhub_event_card($ev, $i);
    }
    $html .= '</div>';
    if (count($rows) >= $limit) {
        $html .= '<div class="section-more"><a class="btn btn-outline" href="' . e(events_list_url(['category' => $cat['slug'] ?? '', 'when' => $when === 'past' ? 'past' : ''])) . '">'
            . ($when === 'past' ? 'See all past events' : 'See all upcoming events') . icon('arrow-right') . '</a></div>';
    }
    return $html;
}

function section_schema_program_registration(): array
{
    return [
        'label' => 'Programme registration (open sessions / register interest)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => ['category' => ['select', 'Category', ['' => 'This page\'s category'] + program_category_options()]],
        'help' => 'Registration for the open sessions of this category; when none is open, visitors can register their interest.',
    ];
}

/** Minimal version (architect): open sessions link to their registration form. P1 replaces it with the full form. */
function section_program_registration(array $s, array $items, array $set, array $page): string
{
    $cat = !empty($set['category']) ? program_category_by_id((int) $set['category']) : (!empty($page['id']) ? program_category_for_page((int) $page['id']) : null);
    $open = programhub_events(['when' => 'open', 'limit' => 12, 'category_id' => $cat ? (int) $cat['id'] : 0]);
    if (!$open) {
        return '<div class="ph-register-empty empty-state">' . icon('info') . '<p><strong>There is no open programme in this area right now.</strong> '
            . 'New sessions are announced on this page. You can subscribe to our updates or write to us and we will let you know when registration opens.</p>'
            . '<div class="btn-row center"><a class="btn btn-primary" href="' . e(page_url('newsletter')) . '">' . icon('mail') . 'Get updates by email</a>'
            . '<a class="btn btn-ghost" href="' . e(page_url('contact')) . '">' . icon('message') . 'Contact us</a></div></div>';
    }
    $html = '<ul class="ph-open-list">';
    foreach ($open as $ev) {
        $state = event_state($ev);
        $html .= '<li class="ph-open-item"><div><h3><a href="' . e(event_url($ev)) . '">' . e($ev['title']) . '</a></h3>'
            . '<p class="small muted">' . icon('clock') . e(event_when($ev)) . ' · ' . e(event_mode($ev) === 'online' ? 'Online' : (string) $ev['location']) . '</p>'
            . '<p>' . programhub_status_badge($ev, $state) . ($state['seats_left'] !== null && $state['open'] ? ' <span class="small">' . (int) $state['seats_left'] . ' places left</span>' : '') . '</p></div>'
            . '<a class="btn btn-primary" href="' . e(event_url($ev) . '#register') . '">' . icon('check') . ($state['open'] ? 'Register' : 'Join the waiting list') . '<span class="sr-only">: ' . e($ev['title']) . '</span></a></li>';
    }
    return $html . '</ul><p class="form-note">' . icon('lock') . '<span>Participants under 18 need a parent\'s or guardian\'s consent. See our <a href="' . e(page_url('privacy-policy')) . '">privacy policy</a>.</span></p>';
}

function section_schema_partnership_enquiry(): array
{
    return [
        'label' => 'Contact & partnership enquiry',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => ['category' => ['select', 'Category', ['' => 'This page\'s category'] + program_category_options()]],
        'help' => 'Enquiry form for institutions, trainers, sponsors and community organisations (saved in Admin > Enquiries as "partnership").',
    ];
}

/** Minimal version (architect): who can partner + link to the contact form preset to "partnership". P1 replaces it with an inline form. */
function section_partnership_enquiry(array $s, array $items, array $set, array $page): string
{
    $html = '<div class="ph-partner grid grid-2">';
    $html .= '<ul class="check-list ph-partner-kinds">';
    foreach (['Schools, colleges and institutions that want to host a session', 'Trainers, experts and speakers who want to volunteer their time', 'Sponsors and CSR teams who want to support a programme', 'Community organisations that want to bring a programme to their area'] as $line) {
        $html .= '<li>' . icon('check-circle') . '<span>' . e($line) . '</span></li>';
    }
    $html .= '</ul><div class="ph-partner-cta"><p>Tell us about your organisation and how you would like to work together. Our team replies by email.</p>'
        . '<div class="btn-row"><a class="btn btn-primary" href="' . e(page_url('contact', ['type' => 'partnership'])) . '">' . icon('send') . 'Send a partnership enquiry</a>';
    if (setting('contact_email') !== '') {
        $html .= '<a class="btn btn-ghost" href="mailto:' . e(setting('contact_email')) . '">' . icon('mail') . e(setting('contact_email')) . '</a>';
    }
    return $html . '</div></div></div>';
}

// =====================================================================
// Migrations 600-629 (architect). P1: 630-659, P2: 660-689.
// =====================================================================

/** Add a foreign key unless a constraint with this name exists. $definition: "FOREIGN KEY (col) REFERENCES t (id) ON DELETE SET NULL". */
function programhub_add_fk_if_missing(string $table, string $name, string $definition): void
{
    $exists = (int) db_value("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
                              AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'", [$table, $name]);
    if (!$exists && table_exists($table)) {
        db()->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` ADD CONSTRAINT `' . str_replace('`', '', $name) . '` ' . $definition);
    }
}

/**
 * Strictly widen an ENUM column: keeps every existing value in its order and appends the missing ones.
 * Never removes or renames a value. No-op when nothing is missing.
 */
function programhub_widen_enum(string $table, string $column, array $values): void
{
    $col = db_row('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
    if (!$col || stripos((string) $col['COLUMN_TYPE'], 'enum(') !== 0) {
        return;
    }
    preg_match_all("/'((?:[^']|'')*)'/", (string) $col['COLUMN_TYPE'], $m);
    $current = array_map(function ($v) { return str_replace("''", "'", $v); }, $m[1]);
    $missing = array_values(array_diff($values, $current));
    if (!$missing) {
        return;
    }
    $all = array_merge($current, $missing);
    $quoted = implode(',', array_map(function ($v) { return db()->quote($v); }, $all));
    $default = $col['COLUMN_DEFAULT'];
    $defaultSql = '';
    if ($default !== null && strtoupper((string) $default) !== 'NULL') {
        $defaultSql = ' DEFAULT ' . db()->quote(trim((string) $default, "'"));
    } elseif ($col['IS_NULLABLE'] === 'YES') {
        $defaultSql = ' DEFAULT NULL';
    }
    db()->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` MODIFY `' . str_replace('`', '', $column) . '` ENUM(' . $quoted . ')'
        . ($col['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL') . $defaultSql);
}

/** New tables: categories, speakers, partners, event links, resources, reports, interest sign-ups. */
function migration_600_programhub_tables(): void
{
    $opts = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    db()->exec("CREATE TABLE IF NOT EXISTS program_categories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(120) NOT NULL,
        title VARCHAR(150) NOT NULL,
        nav_label VARCHAR(80) DEFAULT NULL,
        card_text VARCHAR(300) NOT NULL DEFAULT '',
        icon VARCHAR(40) NOT NULL DEFAULT 'calendar',
        tone VARCHAR(20) NOT NULL DEFAULT 'green',
        image VARCHAR(255) DEFAULT NULL,
        page_id INT UNSIGNED DEFAULT NULL,
        default_event_type VARCHAR(40) DEFAULT NULL,
        interest_enabled TINYINT(1) NOT NULL DEFAULT 1,
        partnership_enabled TINYINT(1) NOT NULL DEFAULT 1,
        notify_email VARCHAR(190) DEFAULT NULL,
        show_in_nav TINYINT(1) NOT NULL DEFAULT 1,
        status ENUM('published','draft') NOT NULL DEFAULT 'published',
        sort_order INT NOT NULL DEFAULT 0,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_program_categories_slug (slug),
        KEY idx_program_categories_status (status, sort_order),
        KEY fk_program_categories_page (page_id),
        KEY fk_program_categories_user (created_by),
        CONSTRAINT fk_program_categories_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE SET NULL,
        CONSTRAINT fk_program_categories_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
    )$opts");
    db()->exec("CREATE TABLE IF NOT EXISTS speakers (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(140) NOT NULL,
        designation VARCHAR(150) DEFAULT NULL,
        organisation VARCHAR(150) DEFAULT NULL,
        bio TEXT DEFAULT NULL,
        photo VARCHAR(255) DEFAULT NULL,
        website_url VARCHAR(255) DEFAULT NULL,
        linkedin_url VARCHAR(255) DEFAULT NULL,
        email VARCHAR(190) DEFAULT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        profile_consent TINYINT(1) NOT NULL DEFAULT 0,
        photo_consent TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('active','hidden') NOT NULL DEFAULT 'active',
        sort_order INT NOT NULL DEFAULT 0,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_speakers_slug (slug),
        KEY idx_speakers_status (status, sort_order),
        KEY fk_speakers_user (created_by),
        CONSTRAINT fk_speakers_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
    )$opts");
    db()->exec("CREATE TABLE IF NOT EXISTS partners (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        slug VARCHAR(170) NOT NULL,
        partner_type ENUM('institution','sponsor','community','knowledge','media','government','other') NOT NULL DEFAULT 'institution',
        logo VARCHAR(255) DEFAULT NULL,
        website_url VARCHAR(255) DEFAULT NULL,
        description VARCHAR(500) DEFAULT NULL,
        contact_name VARCHAR(120) DEFAULT NULL,
        contact_email VARCHAR(190) DEFAULT NULL,
        contact_phone VARCHAR(20) DEFAULT NULL,
        display_consent TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('active','hidden') NOT NULL DEFAULT 'active',
        sort_order INT NOT NULL DEFAULT 0,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_partners_slug (slug),
        KEY idx_partners_status (status, partner_type, sort_order),
        KEY fk_partners_user (created_by),
        CONSTRAINT fk_partners_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
    )$opts");
    db()->exec("CREATE TABLE IF NOT EXISTS event_speakers (
        event_id INT UNSIGNED NOT NULL,
        speaker_id INT UNSIGNED NOT NULL,
        role ENUM('speaker','trainer','mentor','judge','host','performer') NOT NULL DEFAULT 'speaker',
        session_title VARCHAR(200) DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (event_id, speaker_id),
        KEY idx_event_speakers_speaker (speaker_id),
        CONSTRAINT fk_event_speakers_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
        CONSTRAINT fk_event_speakers_speaker FOREIGN KEY (speaker_id) REFERENCES speakers (id) ON DELETE CASCADE
    )$opts");
    db()->exec("CREATE TABLE IF NOT EXISTS event_partners (
        event_id INT UNSIGNED NOT NULL,
        partner_id INT UNSIGNED NOT NULL,
        role VARCHAR(40) NOT NULL DEFAULT 'partner',
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (event_id, partner_id),
        KEY idx_event_partners_partner (partner_id),
        CONSTRAINT fk_event_partners_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
        CONSTRAINT fk_event_partners_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE
    )$opts");
    db()->exec("CREATE TABLE IF NOT EXISTS event_resources (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id INT UNSIGNED NOT NULL,
        title VARCHAR(200) NOT NULL,
        resource_type ENUM('material','promo','slides','rules','report','other') NOT NULL DEFAULT 'material',
        description VARCHAR(300) DEFAULT NULL,
        file_path VARCHAR(255) DEFAULT NULL,
        external_url VARCHAR(500) DEFAULT NULL,
        visibility ENUM('public','registered') NOT NULL DEFAULT 'public',
        status ENUM('published','hidden') NOT NULL DEFAULT 'published',
        sort_order INT NOT NULL DEFAULT 0,
        uploaded_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_resources_event (event_id, visibility, status, sort_order),
        KEY fk_event_resources_user (uploaded_by),
        CONSTRAINT fk_event_resources_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
        CONSTRAINT fk_event_resources_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
    )$opts");
    db()->exec("CREATE TABLE IF NOT EXISTS event_reports (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id INT UNSIGNED NOT NULL,
        summary MEDIUMTEXT DEFAULT NULL,
        outcomes MEDIUMTEXT DEFAULT NULL,
        participants_verified INT UNSIGNED DEFAULT NULL,
        verified_by INT UNSIGNED DEFAULT NULL,
        verified_at DATETIME DEFAULT NULL,
        status ENUM('draft','published') NOT NULL DEFAULT 'draft',
        published_at DATETIME DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_event_reports_event (event_id),
        KEY fk_event_reports_verifier (verified_by),
        KEY fk_event_reports_user (created_by),
        CONSTRAINT fk_event_reports_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
        CONSTRAINT fk_event_reports_verifier FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_event_reports_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
    )$opts");
    db()->exec("CREATE TABLE IF NOT EXISTS program_interests (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id INT UNSIGNED DEFAULT NULL,
        event_id INT UNSIGNED DEFAULT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        city VARCHAR(100) DEFAULT NULL,
        message VARCHAR(500) DEFAULT NULL,
        consent_at DATETIME NOT NULL,
        status ENUM('new','contacted','notified','closed') NOT NULL DEFAULT 'new',
        notified_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_program_interests_category (category_id, status),
        KEY idx_program_interests_email (email),
        KEY fk_program_interests_event (event_id),
        KEY fk_program_interests_user (user_id),
        CONSTRAINT fk_program_interests_category FOREIGN KEY (category_id) REFERENCES program_categories (id) ON DELETE SET NULL,
        CONSTRAINT fk_program_interests_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL,
        CONSTRAINT fk_program_interests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
    )$opts");
}

/** Additive columns on events / event_registrations / gallery_items / contact_enquiries / certificates; strictly widened enums. */
function migration_601_programhub_columns(): void
{
    programhub_widen_enum('events', 'event_type', array_keys(EVENT_TYPES));
    programhub_widen_enum('events', 'status', ['draft', 'published', 'cancelled', 'archived', 'scheduled']);
    programhub_widen_enum('event_registrations', 'status', array_keys(EVENT_REG_STATUSES));

    $hadMode = column_exists('events', 'mode');
    foreach ([
        'category_id' => 'INT UNSIGNED DEFAULT NULL',
        'mode' => "ENUM('in_person','online','hybrid') NOT NULL DEFAULT 'in_person'",
        'venue_address' => 'VARCHAR(300) DEFAULT NULL',
        'map_url' => 'VARCHAR(500) DEFAULT NULL',
        'meeting_url' => 'VARCHAR(500) DEFAULT NULL',
        'meeting_info' => 'VARCHAR(500) DEFAULT NULL',
        'eligibility' => 'TEXT DEFAULT NULL',
        'registration_opens_at' => 'DATETIME DEFAULT NULL',
        'registration_deadline' => 'DATETIME DEFAULT NULL',
        'waitlist_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'fee_amount' => 'DECIMAL(10,2) DEFAULT NULL',
        'fee_note' => 'VARCHAR(255) DEFAULT NULL',
        'certificate_confirmed' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'certificate_note' => 'VARCHAR(300) DEFAULT NULL',
        'organising_team' => 'TEXT DEFAULT NULL',
        'agenda' => 'MEDIUMTEXT DEFAULT NULL',
        'guidelines' => 'MEDIUMTEXT DEFAULT NULL',
        'faqs' => 'MEDIUMTEXT DEFAULT NULL',
        'contact_email' => 'VARCHAR(190) DEFAULT NULL',
        'contact_phone' => 'VARCHAR(20) DEFAULT NULL',
        'team_size_min' => 'TINYINT UNSIGNED DEFAULT NULL',
        'team_size_max' => 'TINYINT UNSIGNED DEFAULT NULL',
        'publish_at' => 'DATETIME DEFAULT NULL',
        'meta_title' => 'VARCHAR(200) DEFAULT NULL',
        'meta_description' => 'VARCHAR(300) DEFAULT NULL',
    ] as $col => $def) {
        add_column_if_missing('events', $col, $def);
    }
    if (!$hadMode) {
        // existing webinars / online events keep being shown as online
        db_query("UPDATE events SET mode = 'online' WHERE event_type = 'webinar' OR location REGEXP '(^|[^a-z])(online|virtual|zoom|google meet|webinar)([^a-z]|$)'");
    }
    add_index_if_missing('events', 'idx_events_category', 'INDEX idx_events_category (category_id, status, start_datetime)');
    add_index_if_missing('events', 'idx_events_publish', 'INDEX idx_events_publish (status, publish_at)');
    programhub_add_fk_if_missing('events', 'fk_events_category', 'FOREIGN KEY (category_id) REFERENCES program_categories (id) ON DELETE SET NULL');

    foreach ([
        'organisation' => 'VARCHAR(200) DEFAULT NULL',
        'city' => 'VARCHAR(100) DEFAULT NULL',
        'team_name' => 'VARCHAR(150) DEFAULT NULL',
        'answers' => 'TEXT DEFAULT NULL',
        'is_minor' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'guardian_name' => 'VARCHAR(120) DEFAULT NULL',
        'guardian_phone' => 'VARCHAR(20) DEFAULT NULL',
        'guardian_consent_at' => 'DATETIME DEFAULT NULL',
        'consent_at' => 'DATETIME DEFAULT NULL',
        'source' => 'VARCHAR(30) DEFAULT NULL',
        'access_token_hash' => 'CHAR(64) DEFAULT NULL',
        'meeting_link_sent_at' => 'DATETIME DEFAULT NULL',
        'confirmed_at' => 'DATETIME DEFAULT NULL',
        'attended_at' => 'DATETIME DEFAULT NULL',
        'cancelled_at' => 'DATETIME DEFAULT NULL',
        'admin_note' => 'VARCHAR(500) DEFAULT NULL',
        'certificate_id' => 'INT UNSIGNED DEFAULT NULL',
        'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
    ] as $col => $def) {
        add_column_if_missing('event_registrations', $col, $def);
    }
    add_index_if_missing('event_registrations', 'idx_event_reg_status', 'INDEX idx_event_reg_status (event_id, status, created_at)');
    programhub_add_fk_if_missing('event_registrations', 'fk_event_reg_certificate', 'FOREIGN KEY (certificate_id) REFERENCES certificates (id) ON DELETE SET NULL');

    add_column_if_missing('gallery_items', 'event_id', 'INT UNSIGNED DEFAULT NULL');
    add_index_if_missing('gallery_items', 'idx_gallery_event', 'INDEX idx_gallery_event (event_id, status)');
    programhub_add_fk_if_missing('gallery_items', 'fk_gallery_event', 'FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL');

    add_column_if_missing('contact_enquiries', 'category_id', 'INT UNSIGNED DEFAULT NULL');
    add_column_if_missing('contact_enquiries', 'event_id', 'INT UNSIGNED DEFAULT NULL');
    add_column_if_missing('contact_enquiries', 'organisation', 'VARCHAR(200) DEFAULT NULL');
    add_column_if_missing('contact_enquiries', 'partner_kind', 'VARCHAR(40) DEFAULT NULL');
    add_index_if_missing('contact_enquiries', 'idx_enquiries_category', 'INDEX idx_enquiries_category (category_id)');
    add_index_if_missing('contact_enquiries', 'idx_enquiries_event', 'INDEX idx_enquiries_event (event_id)');
    programhub_add_fk_if_missing('contact_enquiries', 'fk_enquiries_category', 'FOREIGN KEY (category_id) REFERENCES program_categories (id) ON DELETE SET NULL');
    programhub_add_fk_if_missing('contact_enquiries', 'fk_enquiries_event', 'FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL');

    add_column_if_missing('certificates', 'event_id', 'INT UNSIGNED DEFAULT NULL');
    add_index_if_missing('certificates', 'idx_certificates_event', 'INDEX idx_certificates_event (event_id)');
    programhub_add_fk_if_missing('certificates', 'fk_cert_event', 'FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL');
}

/** Permission for participant data + settings. */
function migration_602_programhub_permissions(): void
{
    add_permission_if_missing('events.registrations', 'Event registrations (participant data, exports, meeting links, attendance)', 'Content', 25, ['volunteer_coordinator']);
    add_setting_if_missing('events_admin_alerts', '1');
    add_setting_if_missing('events_alert_email', '');
    add_setting_if_missing('programhub_next_publish', '');
}

/** Section rows for a new category page (honest, descriptive defaults; all editable in Admin > Pages). */
function programhub_category_sections(array $c, array $common): array
{
    $j = function ($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); };
    $cards = function (array $list) {
        return array_map(function ($x) { return ['icon' => $x[0], 'title' => $x[1], 'text' => $x[2]]; }, $list);
    };
    $faqs = $common['faqs'];
    if (!empty($c['faq'])) {
        $faqs[] = ['q' => $c['faq'][0], 'a' => $c['faq'][1]];
    }
    $row = function (string $type, ?string $anchor, ?string $pill, ?string $title, ?string $intro, ?string $body, ?string $image, $items, array $settings, string $bg, int $sort) use ($j): array {
        return ['section_type' => $type, 'anchor' => $anchor, 'pill' => $pill, 'title' => $title, 'intro' => $intro, 'body' => $body, 'image' => $image,
            'items' => $j($items), 'settings' => $j((object) $settings), 'background' => $bg, 'status' => 'visible', 'sort_order' => $sort];
    };
    return [
        $row('split', 'about', 'About the programme', $c['about_title'], $c['about_intro'], $c['about_body'], $c['art'], $c['objectives'],
            ['image_alt' => '', 'buttons' => []], 'white', 10),
        $row('features', 'topics', 'What we cover', 'Topics & activities', 'Sessions are planned around these themes. Each event page lists exactly what a session covers.', null, null,
            $cards($c['topics']), ['cols' => '3', 'style' => 'soft'], 'tint', 20),
        $row('program_events', 'upcoming', 'Upcoming', 'Upcoming programmes', 'Dates, venues, modes and registration status are updated as sessions are scheduled.', null, null,
            [], ['when' => 'upcoming', 'limit' => 6], 'white', 30),
        $row('program_events', 'past', 'Completed', 'Past programmes', 'Completed sessions with photos, short reports and verified outcomes.', null, null,
            [], ['when' => 'past', 'limit' => 6], 'cream', 40),
        $row('checklist', 'who-can-participate', 'Eligibility', 'Who can participate', 'Each event page states its own eligibility. In general, these sessions welcome:', null, null,
            $c['who'], ['cols' => '2'], 'white', 50),
        $row('features', 'benefits', 'Outcomes', 'Benefits & learning outcomes', 'What participants can expect to take away. Outcomes depend on the session and on regular participation.', null, null,
            $cards($c['benefits']), ['cols' => '4', 'style' => 'card'], 'tint', 60),
        $row('program_registration', 'register', 'Registration', 'Register for a programme', 'Choose an open session in this area. If nothing is open right now, register your interest and we will let you know when new sessions are announced.', null, null,
            [], [], 'white', 70),
        $row('faqs', 'faqs', 'FAQs', 'Frequently asked questions', null, null, null,
            $faqs, ['width' => 'narrow', 'align' => 'center', 'open_first' => true], 'cream', 80),
        $row('partnership_enquiry', 'partner', 'Partner with us', 'Contact & partnership enquiry', 'Institutions, trainers, sponsors and community organisations can work with us on ' . $c['title'] . ' programmes.', null, null,
            [], [], 'white', 90),
    ];
}

/** The 10 categories: program_categories rows + landing pages (template program_category) with default sections. */
function migration_603_programhub_categories(): void
{
    $data = require __DIR__ . '/upgrade/program-categories.php';
    $parentId = db_value("SELECT id FROM pages WHERE slug = 'programs'");
    $sort = 0;
    foreach ($data['categories'] as $c) {
        $sort += 10;
        if (db_value('SELECT id FROM program_categories WHERE slug = ?', [$c['slug']])) {
            continue;   // already created (and possibly edited) - never overwrite
        }
        $pageSlug = 'programs/' . $c['slug'];
        $pageId = db_value('SELECT id FROM pages WHERE slug = ?', [$pageSlug]);
        if (!$pageId) {
            $pageId = db_insert('pages', [
                'parent_id' => $parentId ?: null, 'slug' => $pageSlug, 'template' => 'program_category', 'title' => $c['title'],
                'banner_eyebrow' => 'Programs & Events', 'banner_subtitle' => $c['subtitle'], 'banner_image' => $c['art'],
                'banner_buttons' => json_encode([
                    ['label' => 'Register', 'url' => '#register', 'style' => 'donate', 'icon' => 'check'],
                    ['label' => 'Explore events', 'url' => '#upcoming', 'style' => 'light', 'icon' => 'calendar'],
                ], JSON_UNESCAPED_SLASHES),
                'meta_title' => $c['meta_title'], 'meta_description' => $c['meta_description'],
                'status' => 'published', 'is_system' => 1, 'show_in_sitemap' => 1, 'noindex' => 0, 'sort_order' => 40 + (int) ($sort / 10),
                'published_at' => date('Y-m-d H:i:s'),
            ]);
            foreach (programhub_category_sections($c, $data['common']) as $s) {
                db_insert('page_sections', $s + ['page_id' => $pageId]);
            }
        }
        db_insert('program_categories', [
            'slug' => $c['slug'], 'title' => $c['title'], 'nav_label' => $c['nav_label'], 'card_text' => $c['card'],
            'icon' => $c['icon'], 'tone' => $c['tone'], 'image' => null, 'page_id' => (int) $pageId,
            'default_event_type' => $c['event_type'], 'status' => 'published', 'show_in_nav' => 1, 'sort_order' => $sort,
        ]);
    }
    program_categories_all(false, true);
}

/**
 * Canonical events addresses: the list moves from /events-page/ to /events/ (same page row, content kept);
 * /hackathons/<slug>/ gets its own page row; old links in menus, redirects and sections point to /events/.
 */
function migration_604_programhub_routes(): void
{
    if (!db_value("SELECT id FROM pages WHERE slug = 'events'")) {
        // the seeded redirect /events/ -> /events-page/ would shadow the list's new address
        db_query("DELETE FROM redirects WHERE source_path IN ('events', 'events/') AND target_url IN ('/events-page/', '/events-page')");
        if (db_value("SELECT id FROM pages WHERE slug = 'events-page'")) {
            db_query("UPDATE pages SET slug = 'events' WHERE slug = 'events-page'");
        } else {
            db_insert('pages', ['slug' => 'events', 'template' => 'events', 'title' => 'Events', 'banner_eyebrow' => 'Events & community',
                'banner_subtitle' => 'Workshops, seminars, camps, drives and community events', 'banner_image' => 'assets/images/hero/events.svg',
                'meta_description' => 'Upcoming and past workshops, seminars, camps, drives and community events of Prerit Welfare Foundation, with registration where it is open.',
                'status' => 'published', 'is_system' => 1, 'show_in_sitemap' => 1, 'sort_order' => 50, 'published_at' => date('Y-m-d H:i:s')]);
        }
    }
    if (!db_value("SELECT id FROM redirects WHERE source_path IN ('events-page', 'events-page/')")) {
        db_query("INSERT IGNORE INTO redirects (source_path, target_url, status_code) VALUES ('events-page', '/events/', 301)");
    }
    db_query("UPDATE redirects SET target_url = '/events/' WHERE target_url IN ('/events-page/', '/events-page') AND source_path NOT IN ('events', 'events/')");
    db_query("UPDATE menu_items SET url = '/events/' WHERE url IN ('/events-page/', '/events-page')");
    db_query("UPDATE page_sections SET items = REPLACE(REPLACE(items, '\"/events-page/\"', '\"/events/\"'), '\"\\\\/events-page\\\\/\"', '\"\\\\/events\\\\/\"') WHERE items LIKE '%events-page%'");
    db_query("UPDATE page_sections SET settings = REPLACE(REPLACE(settings, '\"/events-page/\"', '\"/events/\"'), '\"\\\\/events-page\\\\/\"', '\"\\\\/events\\\\/\"') WHERE settings LIKE '%events-page%'");
    db_query("UPDATE page_sections SET body = REPLACE(body, 'href=\"/events-page/\"', 'href=\"/events/\"') WHERE body LIKE '%href=\"/events-page/\"%'");
    db_query("UPDATE website_settings SET setting_value = '/events/' WHERE setting_value IN ('/events-page/', '/events-page')");

    if (!db_value("SELECT id FROM pages WHERE slug = 'hackathons'")) {
        $parent = db_value("SELECT id FROM pages WHERE slug = 'programs/hackathons-innovation'");
        db_insert('pages', ['parent_id' => $parent ?: null, 'slug' => 'hackathons', 'template' => 'event_detail', 'title' => 'Hackathons',
            'banner_eyebrow' => 'Hackathons & innovation', 'banner_image' => 'assets/images/hero/events.svg',
            'meta_description' => 'Hackathons and innovation challenges by Prerit Welfare Foundation.',
            'status' => 'published', 'is_system' => 1, 'show_in_sitemap' => 0, 'sort_order' => 52, 'published_at' => date('Y-m-d H:i:s')]);
    }
    db_query("UPDATE pages SET show_in_sitemap = 0 WHERE slug = 'event-details'");
}

/** Programmes hub: category cards above the core programmes. Text changes only while the seeded wording is unchanged. */
function migration_605_programhub_hub(): void
{
    $hub = db_value("SELECT id FROM pages WHERE slug = 'programs'");
    if (!$hub) {
        return;
    }
    if (!db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND section_type = 'program_categories_grid'", [$hub])) {
        db_insert('page_sections', [
            'page_id' => $hub, 'section_type' => 'program_categories_grid', 'anchor' => 'programs-events', 'pill' => 'Programs & Events',
            'title' => 'Workshops, programmes & events',
            'intro' => 'Learning programmes, workshops, wellness and cultural sessions, hackathons and community events. Each area lists its upcoming sessions and how to register.',
            'items' => '[]', 'settings' => '{"align":"center"}', 'background' => 'tint', 'status' => 'visible', 'sort_order' => 15,
        ]);
    }
    db_query("UPDATE page_sections SET title = 'Our core programmes' WHERE page_id = ? AND section_type = 'module' AND title = 'Explore our programmes'", [$hub]);
    db_query('UPDATE pages SET meta_description = ? WHERE id = ? AND meta_description = ?', [
        'Workshops, seminars, wellness and cultural programmes, hackathons and our core programmes in education, health, women and youth empowerment, environment and relief.',
        $hub,
        'Our programmes: education, healthcare, women and youth empowerment, rural development, environment, skills, digital learning, scholarships, food and relief.',
    ]);
}

/** Footer column "Programs & Events" (a footer_* menu is rendered automatically as a footer column). */
function migration_606_programhub_footer_menu(): void
{
    $menuId = db_value("SELECT id FROM menus WHERE menu_key = 'footer_programs'");
    if (!$menuId) {
        $menuId = db_insert('menus', ['menu_key' => 'footer_programs', 'name' => 'Footer: Programs & Events', 'description' => 'Footer column with programme and event links.']);
    }
    $sort = 0;
    foreach ([
        ['Programs', '/programs/'], ['Workshops', '/events/?type=workshop'], ['Hackathons', '/programs/hackathons-innovation/'],
        ['Yoga & Wellness', '/programs/fitness-yoga-wellness/'], ['Music & Cultural Programs', '/programs/music-arts-culture/'], ['Upcoming Events', '/events/'],
    ] as [$label, $url]) {
        $sort++;
        if (!db_value('SELECT COUNT(*) FROM menu_items WHERE menu_id = ? AND url = ?', [$menuId, $url])) {
            db_insert('menu_items', ['menu_id' => $menuId, 'label' => $label, 'url' => $url, 'status' => 'visible', 'sort_order' => $sort]);
        }
    }
}

/** Privacy policy: what event registration collects (new section, added once). */
function migration_607_programhub_privacy(): void
{
    $pageId = (int) db_value("SELECT id FROM pages WHERE slug = 'privacy-policy'");
    if (!$pageId || (int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND anchor = 'programmes-events'", [$pageId]) > 0) {
        return;
    }
    $body = '<p>When you register for a workshop, seminar, session, hackathon or other event, or register your interest in a programme, we collect the details asked for on the form - usually your name, email address, mobile number and city, and where relevant your school, college or organisation and team name. For participants under 18 we also record the name and mobile number of the parent or guardian who gives consent.</p>'
        . '<ul><li>We use these details only to manage your registration: to confirm your place, send joining instructions and updates, keep an attendance record and, where the event page confirms it, issue a participation certificate.</li>'
        . '<li>Joining links for online sessions are sent only to registered participants, by email or through a private link that works only for your registration.</li>'
        . '<li>Only authorised staff and the coordinators of that event can see participant details. We do not publish participant names, and any participant numbers we publish come from verified attendance records.</li>'
        . '<li>Photos and videos of participants are published only with consent.</li>'
        . '<li>Partnership enquiries are handled like other enquiries sent through our contact forms.</li></ul>'
        . '<p>You can ask us to cancel your registration or delete your details at any time through the <a href="/contact/">Contact Us</a> page.</p>';
    db_insert('page_sections', [
        'page_id' => $pageId, 'section_type' => 'richtext', 'anchor' => 'programmes-events', 'title' => 'Programmes and event registrations',
        'body' => $body, 'background' => 'white', 'status' => 'visible', 'sort_order' => 68,
    ]);
}

// =====================================================================
// Bootstrap
// =====================================================================
if (!defined('PWF_INSTALLER') && PHP_SAPI !== 'cli') {
    try {
        programhub_publish_due();
    } catch (Throwable $e) {
        error_log('programhub bootstrap: ' . $e->getMessage());
    }
}

// Admin helpers (P2) live in their own file so the two builders never edit the same file.
require_once __DIR__ . '/programhub-admin.php';
