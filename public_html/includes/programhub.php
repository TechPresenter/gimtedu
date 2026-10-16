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
            $onlyFull = $state['is_full'] && $ev['status'] === 'published' && (int) $ev['registration_enabled'] === 1
                && !$state['started'] && !$state['deadline_passed'] && !$state['not_yet_open'];
            return ['result' => $onlyFull ? 'full' : 'closed', 'message' => $state['reason'] ?: 'Registration is closed for this event.'] + $out;
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
// Emails (shared: P1 calls them from the public forms, P2 from the admin pages; P1 owns the wording)
// =====================================================================

/**
 * Email to a participant. $kind: 'confirmed' (registered) | 'waitlisted' | 'promoted' (moved from the waiting list) |
 * 'meeting_link' (joining details; needs $extra['token'] - issue one with programhub_issue_access_token()) |
 * 'cancelled' (by the foundation; optional $extra['reason']) | 'reminder'.
 * For online / hybrid events 'confirmed', 'promoted' and 'reminder' include the private joining page when $extra['token'] is given.
 * The meeting link itself is only ever sent with 'meeting_link'. Returns send_mail()'s result.
 */
function programhub_send_participant_email(array $event, array $reg, string $kind, array $extra = []): bool
{
    $subjects = [
        'confirmed' => 'Registration confirmed: ', 'waitlisted' => 'Waiting list: ', 'promoted' => 'A place is now available: ',
        'meeting_link' => 'Joining details: ', 'cancelled' => 'Cancelled: ', 'reminder' => 'Reminder: ',
    ];
    if (!isset($subjects[$kind])) {
        return false;
    }
    $token = (string) ($extra['token'] ?? '');
    $joinUrl = $token !== '' ? programhub_join_url($event, (int) $reg['id'], $token) : '';
    $mode = event_mode($event);
    $online = $mode !== 'in_person';
    $title = e((string) $event['title']);
    $facts = '<table role="presentation" style="margin:14px 0;border-collapse:collapse;font-size:15px">'
        . '<tr><td style="padding:3px 14px 3px 0;color:#5e6e66">When</td><td style="padding:3px 0"><strong>' . e(event_when($event, true)) . '</strong></td></tr>'
        . '<tr><td style="padding:3px 14px 3px 0;color:#5e6e66">Where</td><td style="padding:3px 0">' . e(programhub_event_where($event, false))
        . ($mode !== 'online' && trim((string) ($event['venue_address'] ?? '')) !== '' ? '<br>' . e((string) $event['venue_address']) : '') . '</td></tr>'
        . (!empty($reg['id']) ? '<tr><td style="padding:3px 14px 3px 0;color:#5e6e66">Reference</td><td style="padding:3px 0"><strong>' . e(programhub_registration_ref($reg)) . '</strong></td></tr>' : '')
        . '</table>';
    $intro = [
        'confirmed' => '<p>Thank you for registering for <strong>' . $title . '</strong>. Your place is confirmed.</p>',
        'waitlisted' => '<p>Thank you for your interest in <strong>' . $title . '</strong>. All places are taken at the moment, so you have been added to the <strong>waiting list</strong>. We will email you if a place becomes available.</p>',
        'promoted' => '<p>Good news: a place has become available and you are now <strong>registered</strong> for <strong>' . $title . '</strong>.</p>',
        'meeting_link' => '<p>Here are the joining details for <strong>' . $title . '</strong>. They are meant only for you - please do not share them.</p>',
        'cancelled' => '<p>We are sorry to tell you that <strong>' . $title . '</strong> has been cancelled.' . (!empty($extra['reason']) ? ' ' . e((string) $extra['reason']) : '') . ' We apologise for the inconvenience.</p>',
        'reminder' => '<p>This is a reminder that <strong>' . $title . '</strong> is coming up.</p>',
    ][$kind];
    $body = '<p>Dear ' . e((string) $reg['name']) . ',</p>' . $intro . $facts;
    if ($kind === 'meeting_link') {
        if (trim((string) ($event['meeting_url'] ?? '')) !== '') {
            $body .= '<p><strong>Online joining link:</strong> <a href="' . e((string) $event['meeting_url']) . '">' . e((string) $event['meeting_url']) . '</a></p>';
            if (trim((string) ($event['meeting_info'] ?? '')) !== '') {
                $body .= '<p>' . e_nl((string) $event['meeting_info']) . '</p>';
            }
        }
        if ($joinUrl !== '') {
            $body .= '<p>Your private joining page always shows the latest details: <a href="' . e($joinUrl) . '">open your joining page</a>.</p>';
        }
    } elseif ($online && $joinUrl !== '' && in_array($kind, ['confirmed', 'promoted', 'reminder'], true)) {
        $body .= '<p><strong>Joining online?</strong> The joining link is shared only with registered participants. It will appear on your '
            . '<a href="' . e($joinUrl) . '">private joining page</a> and will be emailed to you before the session. Please do not share this link.</p>';
    }
    if ($kind === 'promoted' || $kind === 'confirmed' || $kind === 'reminder') {
        $map = programhub_map_url($event);
        if ($map !== '') {
            $body .= '<p><a href="' . e($map) . '">Find the venue on Google Maps</a></p>';
        }
    }
    if ($kind !== 'cancelled') {
        $body .= '<p><a class="btn" href="' . e(event_abs_url($event)) . '">View event details</a></p>'
            . '<p>Add it to your calendar: <a href="' . e(event_abs_url($event) . 'ics/') . '">download the calendar file (.ics)</a>.</p>';
        if ($kind !== 'meeting_link') {
            $body .= '<p>If you can no longer attend, please reply to this email so that we can offer your place to someone else.</p>';
        }
    } else {
        $body .= '<p>See our other <a href="' . e(rtrim(BASE_URL, '/') . substr(events_list_url(), strlen(base_path()))) . '">upcoming events</a>.</p>';
    }
    $body .= '<p>Regards,<br>' . e(site_name()) . '</p>';
    $replyTo = trim((string) ($event['contact_email'] ?? '')) ?: (setting('contact_email') ?: null);
    return send_mail((string) $reg['email'], $subjects[$kind] . $event['title'], $body, $replyTo);
}

/**
 * Staff alert about a new registration (setting events_admin_alerts = '1'). Recipient: the category's notify_email,
 * else setting events_alert_email, else the general admin notification address. Contains no phone number.
 */
function programhub_notify_staff_registration(array $event, array $reg): void
{
    if (setting('events_admin_alerts', '1') !== '1') {
        return;
    }
    $cat = !empty($event['category_id']) ? program_category_by_id((int) $event['category_id']) : null;
    $to = trim((string) ($cat['notify_email'] ?? '')) ?: setting('events_alert_email');
    $taken = event_registered_count((int) $event['id']);
    $html = '<p>New ' . ($reg['status'] === 'waitlisted' ? 'waiting-list entry' : 'registration') . ' for <strong>' . e($event['title']) . '</strong> (' . e(event_when($event)) . ').</p>'
        . '<p>' . e($reg['name']) . ($reg['status'] === 'waitlisted' ? ' (waiting list)' : '') . ' - ' . $taken . ($event['capacity'] ? ' of ' . (int) $event['capacity'] : '') . ' places taken.</p>'
        . '<p><a class="btn" href="' . e(abs_url('admin/event-registrations.php?event=' . (int) $event['id'])) . '">Open registrations</a></p>';
    $subject = 'Event registration: ' . $event['title'];
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        send_mail($to, '[Website] ' . $subject, $html);
    } else {
        notify_admin($subject, $html);
    }
}

// =====================================================================
// Public rendering (P1): cards, live lists, forms and their handlers, event page blocks
// =====================================================================

/** Filter groups for the category cards on the programmes hub. */
const PROGRAM_CATEGORY_GROUPS = [
    'technology' => 'Technology & AI', 'careers' => 'Careers & digital skills', 'wellness' => 'Wellness, arts & culture',
    'innovation' => 'Hackathons & events', 'more' => 'More areas',
];
/** Group of each seeded category (categories added later are grouped by their default event type). */
const PROGRAM_CATEGORY_GROUP_OF = [
    'ai-artificial-intelligence' => 'technology', 'robotics-technology' => 'technology', 'machine-learning-data-science' => 'technology',
    'cyber-security' => 'technology', 'digital-marketing' => 'careers', 'career-entrepreneurship' => 'careers',
    'fitness-yoga-wellness' => 'wellness', 'music-arts-culture' => 'wellness', 'hackathons-innovation' => 'innovation', 'events-seminars' => 'innovation',
];

function program_category_group(array $cat): string
{
    $slug = (string) ($cat['slug'] ?? '');
    if (isset(PROGRAM_CATEGORY_GROUP_OF[$slug])) {
        return PROGRAM_CATEGORY_GROUP_OF[$slug];
    }
    $type = (string) ($cat['default_event_type'] ?? '');
    if (in_array($type, ['yoga_session', 'fitness_session', 'music_program', 'cultural_event'], true)) {
        return 'wellness';
    }
    if (in_array($type, ['hackathon', 'innovation_challenge', 'seminar', 'event', 'awareness_campaign', 'drive', 'camp'], true)) {
        return 'innovation';
    }
    return 'more';
}

/** Load the public module script (forms, filters) once. */
function programhub_js(): void
{
    $src = asset('assets/js/modules/programs-hub.js');
    if (!in_array($src, $GLOBALS['extraScripts'] ?? [], true)) {
        $GLOBALS['extraScripts'][] = $src;
    }
}

/** Card / poster image of an event: [path, isIllustration]. Own image, else the category illustration, else the generic one. */
function programhub_event_image(array $ev): array
{
    $img = (string) ($ev['image'] ?? '');
    if ($img !== '' && strpos($img, '..') === false && is_file(ROOT_PATH . '/' . $img)) {
        return [$img, false];
    }
    $cat = !empty($ev['category_id']) ? program_category_by_id((int) $ev['category_id']) : null;
    return [$cat ? program_category_art($cat) : CONTENT_FALLBACK_IMAGE, true];
}

/** Where an event takes place, for cards and emails (never the meeting link). */
function programhub_event_where(array $ev, bool $short = true): string
{
    $mode = event_mode($ev);
    if ($mode === 'online') {
        return 'Online';
    }
    $loc = trim((string) ($ev['location'] ?? ''));
    return ($loc !== '' ? $loc : 'Venue to be announced') . ($mode === 'hybrid' ? ($short ? ' + online' : ' (you can also join online)') : '');
}

/** Registration reference shown to participants and staff, e.g. PWF-EVR-2026-00012 (year of registration). */
function programhub_registration_ref(array $reg): string
{
    $ts = !empty($reg['created_at']) ? strtotime((string) $reg['created_at']) : false;
    return 'PWF-EVR-' . date('Y', $ts ?: time()) . '-' . str_pad((string) (int) ($reg['id'] ?? 0), 5, '0', STR_PAD_LEFT);
}

/** Badge for a registration status (labels from EVENT_REG_STATUSES). */
function programhub_reg_badge(string $status): string
{
    $tones = ['registered' => 'green', 'confirmed' => 'green', 'waitlisted' => 'gold', 'attended' => 'blue', 'cancelled' => 'red', 'no_show' => 'gray'];
    return '<span class="badge badge-' . ($tones[$status] ?? 'gray') . '">' . e(EVENT_REG_STATUSES[$status] ?? humanize($status)) . '</span>';
}

/** a****@example.org */
function programhub_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') {
        return '***';
    }
    return mb_substr($local, 0, 1) . str_repeat('*', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain;
}

/** "Add to Google Calendar" link (title, time, public location and the event page - never the meeting link). */
function programhub_google_calendar_url(array $ev): string
{
    $start = (int) strtotime((string) $ev['start_datetime']);
    if (event_is_all_day($ev)) {
        $endDay = !empty($ev['end_datetime']) ? (int) strtotime((string) $ev['end_datetime']) : $start;
        $dates = date('Ymd', $start) . '/' . date('Ymd', (int) strtotime('+1 day', (int) strtotime(date('Y-m-d', $endDay))));
    } else {
        $end = !empty($ev['end_datetime']) ? (int) strtotime((string) $ev['end_datetime']) : $start + 3600;
        $dates = gmdate('Ymd\THis\Z', $start) . '/' . gmdate('Ymd\THis\Z', $end);
    }
    $where = event_mode($ev) === 'online' ? 'Online' : trim((string) $ev['location'] . (trim((string) ($ev['venue_address'] ?? '')) !== '' ? ', ' . $ev['venue_address'] : ''));
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE', 'text' => (string) $ev['title'], 'dates' => $dates,
        'details' => trim((string) $ev['summary']) . "\n\nDetails: " . event_abs_url($ev), 'location' => $where,
    ]);
}

/** Google Maps link for an in-person / hybrid event: the admin-entered https link, else a search for the address. '' for online events. */
function programhub_map_url(array $ev): string
{
    if (event_mode($ev) === 'online') {
        return '';
    }
    $map = trim((string) ($ev['map_url'] ?? ''));
    if ($map !== '' && preg_match('#^https://#i', $map) && filter_var($map, FILTER_VALIDATE_URL)) {
        return $map;
    }
    $q = trim((string) (($ev['venue_address'] ?? '') ?: $ev['location']));
    return $q !== '' && !preg_match('/^(online|to be announced|tba)$/i', $q) ? maps_search_url($q) : '';
}

/** Category for a section: its "category" setting, else the category of the page it is on. */
function programhub_section_category(array $set, array $page): ?array
{
    if (!empty($set['category'])) {
        return program_category_by_id((int) $set['category'], true);
    }
    return !empty($page['id']) ? program_category_for_page((int) $page['id']) : null;
}

/** One-time success panel data stored by a form handler (interest / partnership), for this category. */
function programhub_take_done(string $form, int $categoryId): ?array
{
    $d = $_SESSION['ph_done'] ?? null;
    if (!is_array($d) || ($d['form'] ?? '') !== $form || (int) ($d['cat'] ?? 0) !== $categoryId || time() - (int) ($d['at'] ?? 0) > 900) {
        return null;
    }
    unset($_SESSION['ph_done']);
    return $d;
}

/** Staff e-mail for a programme form: the category's notify_email, else events_alert_email, else the admin address. */
function programhub_notify_staff(?array $cat, string $subject, string $html, ?string $replyTo = null): void
{
    $to = trim((string) ($cat['notify_email'] ?? '')) ?: trim((string) setting('events_alert_email'));
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        send_mail($to, '[Website] ' . $subject, $html, $replyTo);
    } else {
        notify_admin($subject, $html, $replyTo);
    }
}

// ----- Cards -----

/** Category card for the hub / grids. $opts: index, upcoming (count; shown only when > 0). */
function program_category_card(array $cat, array $opts = []): string
{
    $url = program_category_url($cat);
    $art = program_category_art($cat);
    $tone = in_array($cat['tone'] ?? '', array_keys(program_tones()), true) ? $cat['tone'] : 'green';
    $i = (int) ($opts['index'] ?? 0);
    $count = (int) ($opts['upcoming'] ?? 0);
    $filter = program_category_group($cat) . ($count > 0 ? ' upcoming' : '');
    return '<article class="program-card pc-card tone-' . e($tone) . ' reveal tilt" style="--d:' . ($i % 4) . '" data-category="' . e($filter) . '">'
        . '<div class="program-card-media' . (strpos($art, 'uploads/') === 0 ? ' is-photo' : '') . '"><img src="' . e(image_url($art)) . '" alt="" loading="lazy" width="480" height="360">'
        . ($count > 0 ? '<span class="pc-count">' . icon('calendar') . $count . ' upcoming ' . ($count === 1 ? 'session' : 'sessions') . '</span>' : '') . '</div>'
        . '<div class="program-card-body"><span class="program-card-icon">' . icon((string) ($cat['icon'] ?: 'calendar')) . '</span>'
        . '<h3><a class="program-card-link" href="' . e($url) . '">' . e($cat['title']) . '</a></h3>'
        . '<p>' . e((string) $cat['card_text']) . '</p>'
        . '<span class="link-arrow" aria-hidden="true">Explore ' . icon('arrow-right') . '</span></div></article>';
}

/** Number of upcoming public (published) events per category id (one query). */
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

/** Media block of a card (event photo, or the category illustration). */
function programhub_card_media(array $ev, string $link, ?string $photo = null): string
{
    if ($photo !== null && $photo !== '' && is_file(ROOT_PATH . '/' . $photo)) {
        [$img, $isArt] = [$photo, false];
    } else {
        [$img, $isArt] = programhub_event_image($ev);
    }
    return '<a class="event-media' . ($isArt ? ' is-art' : '') . '" href="' . e($link) . '" tabindex="-1" aria-hidden="true"><img src="' . e(image_url($img)) . '"'
        . ($isArt ? '' : image_srcset_attr($img, '(max-width: 600px) 100vw, (max-width: 1100px) 50vw, 400px')) . ' alt="" loading="lazy" width="480" height="300"></a>';
}

/** Event card with live status (date, time, venue / mode, eligibility, deadline, places). Rows from programhub_events(). */
function programhub_event_card(array $ev, int $i = 0): string
{
    if (event_is_past($ev) && ($ev['status'] ?? '') === 'published') {
        return programhub_past_card($ev, $i);
    }
    $link = event_url($ev);
    $type = (string) ($ev['event_type'] ?? 'event');
    $state = event_state($ev);
    $mode = event_mode($ev);
    $html = '<article class="event-card ev-card ph-event-card reveal" style="--d:' . ($i % 4) . '">'
        . programhub_card_media($ev, $link)
        . content_date_badge((string) $ev['start_datetime'])
        . '<span class="ph-card-status">' . programhub_status_badge($ev, $state) . '</span>'
        . '<div class="event-body"><div class="ph-chips"><span class="ev-chip ev-type-' . e($type) . '">' . icon(EVENT_TYPE_ICONS[$type] ?? 'calendar') . e(event_type_label($type)) . '</span>'
        . ($mode !== 'in_person' ? '<span class="ev-chip ph-mode-chip">' . icon('monitor') . ($mode === 'online' ? 'Online' : 'Hybrid') . '</span>' : '') . '</div>'
        . '<h3><a href="' . e($link) . '">' . e($ev['title']) . '</a></h3>'
        . '<ul class="meta-list"><li>' . icon('clock') . '<span>' . e(event_when($ev)) . '</span></li>'
        . '<li>' . icon($mode === 'online' ? 'monitor' : 'map-pin') . '<span>' . e(programhub_event_where($ev)) . '</span></li>';
    if (trim((string) ($ev['eligibility'] ?? '')) !== '') {
        $html .= '<li>' . icon('users') . '<span>' . e(excerpt((string) $ev['eligibility'], 90)) . '</span></li>';
    }
    if (!empty($state['deadline']) && ($state['open'] || $state['waitlist_open'])) {
        $html .= '<li>' . icon('alert-circle') . '<span>Register by ' . e(date('d M Y, h:i A', (int) $state['deadline'])) . '</span></li>';
    }
    $html .= '</ul><p>' . e(excerpt((string) ($ev['summary'] ?? ''), 130)) . '</p><div class="ph-card-foot">';
    if ($state['open'] && $state['seats_left'] !== null) {
        $html .= '<span class="ph-seats' . ($state['seats_left'] <= 5 ? ' is-low' : '') . '">' . icon('users') . (int) $state['seats_left'] . ' of ' . (int) $state['capacity'] . ' places left</span>';
    } elseif ($state['waitlist_open']) {
        $html .= '<span class="ph-seats is-low">' . icon('users') . 'Waiting list open</span>';
    }
    return $html . '<a class="link-arrow" href="' . e($link . ($state['can_register'] ? '#register' : '')) . '">' . ($state['can_register'] ? ($state['open'] ? 'View & register' : 'Join the waiting list') : 'View details')
        . icon('arrow-right') . '<span class="sr-only">: ' . e($ev['title']) . '</span></a></div></div></article>';
}

/** Completed event: photo, short report, staff-verified outcomes and participant number (only when verified). */
function programhub_past_card(array $ev, int $i = 0): string
{
    $link = event_url($ev);
    $type = (string) ($ev['event_type'] ?? 'event');
    $report = programhub_event_report((int) $ev['id']);
    $photos = programhub_event_gallery((int) $ev['id'], 12);
    $firstPhoto = null;
    foreach ($photos as $g) {
        if (($g['media_type'] ?? 'image') === 'image' && !empty($g['image_path'])) {
            $firstPhoto = (string) $g['image_path'];
            break;
        }
    }
    $n = programhub_verified_participants($ev);
    $html = '<article class="event-card ev-card ph-event-card ph-past-card reveal" style="--d:' . ($i % 4) . '">'
        . programhub_card_media($ev, $link, !empty($ev['image']) ? null : $firstPhoto)
        . content_date_badge((string) $ev['start_datetime'])
        . '<span class="ph-card-status">' . programhub_status_badge($ev) . '</span>'
        . '<div class="event-body"><div class="ph-chips"><span class="ev-chip ev-type-' . e($type) . '">' . icon(EVENT_TYPE_ICONS[$type] ?? 'calendar') . e(event_type_label($type)) . '</span></div>'
        . '<h3><a href="' . e($link) . '">' . e($ev['title']) . '</a></h3>'
        . '<ul class="meta-list"><li>' . icon('calendar') . '<span>' . e(event_when($ev)) . '</span></li>'
        . '<li>' . icon(event_mode($ev) === 'online' ? 'monitor' : 'map-pin') . '<span>' . e(programhub_event_where($ev)) . '</span></li></ul>';
    $text = $report ? trim(strip_tags((string) $report['summary'])) : '';
    $html .= '<p>' . e(excerpt($text !== '' ? $text : (string) ($ev['summary'] ?? ''), 150)) . '</p>';
    $outcomes = $report ? array_slice(array_values(array_filter($report['outcomes_list'], function ($o) {
        return trim((string) ($o['label'] ?? '')) !== '' && trim((string) ($o['value'] ?? '')) !== '';
    })), 0, 3) : [];
    if ($outcomes || $n) {
        $html .= '<ul class="ph-outcomes-mini">';
        if ($n) {
            $html .= '<li><strong>' . $n . '</strong> participant' . ($n === 1 ? '' : 's') . '</li>';
        }
        foreach ($outcomes as $o) {
            $html .= '<li><strong>' . e((string) $o['value']) . '</strong> ' . e((string) $o['label']) . '</li>';
        }
        $html .= '</ul><p class="ph-verified">' . icon('check-circle') . '<span>Figures verified by our team</span></p>';
    }
    $html .= '<div class="ph-card-foot">' . ($photos ? '<span class="ph-seats">' . icon('image') . count($photos) . ' photo' . (count($photos) === 1 ? '' : 's') . '</span>' : '');
    return $html . '<a class="link-arrow" href="' . e($link) . '">' . ($report ? 'Read the report' : 'View details') . icon('arrow-right') . '<span class="sr-only">: ' . e($ev['title']) . '</span></a></div></div></article>';
}

/** Compact card for the "coming up" strip. */
function programhub_mini_card(array $ev, int $i = 0): string
{
    $link = event_url($ev);
    $type = (string) ($ev['event_type'] ?? 'event');
    $state = event_state($ev);
    $cat = programhub_event_category($ev);
    return '<li class="ph-mini reveal" style="--d:' . ($i % 4) . '">' . content_date_badge((string) $ev['start_datetime'], false)
        . '<div class="ph-mini-body"><div class="ph-chips"><span class="ev-chip ev-type-' . e($type) . '">' . icon(EVENT_TYPE_ICONS[$type] ?? 'calendar') . e(event_type_label($type)) . '</span>'
        . programhub_status_badge($ev, $state) . '</div>'
        . '<h3><a href="' . e($link) . '">' . e($ev['title']) . '</a></h3>'
        . '<p>' . icon('clock') . '<span>' . e(event_when($ev)) . '</span></p>'
        . '<p>' . icon(event_mode($ev) === 'online' ? 'monitor' : 'map-pin') . '<span>' . e(programhub_event_where($ev)) . '</span></p>'
        . ($cat ? '<p class="ph-mini-cat">' . icon((string) ($cat['icon'] ?: 'layers')) . '<span>' . e($cat['title']) . '</span></p>' : '')
        . '</div></li>';
}

/** Published, consented photos of a category's events (newest events first). */
function programhub_category_gallery(int $categoryId, int $limit = 8): array
{
    $limit = max(1, min(48, $limit));
    try {
        return db_rows("SELECT g.*, e.title AS event_title FROM gallery_items g JOIN events e ON e.id = g.event_id
                        WHERE e.category_id = ? AND e.status = 'published' AND g.status = 'published' AND g.consent_confirmed = 1
                          AND g.media_type = 'image' AND g.image_path IS NOT NULL AND g.image_path <> ''
                        ORDER BY e.start_datetime DESC, g.sort_order, g.id DESC LIMIT $limit", [$categoryId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Photo grid with lightbox (gallery_items rows; only files that exist). */
function programhub_photo_grid(array $photos, string $class = ''): string
{
    $html = '';
    foreach ($photos as $i => $g) {
        $html .= gallery_item_html($g + ['category' => 'events'], $i);
    }
    return $html !== '' ? '<div class="gallery-grid ph-photos ' . e($class) . '">' . $html . '</div>' . content_lightbox() : '';
}

// ----- Section types (CMS blocks; schemas are used by the page editor) -----

function section_schema_program_categories_grid(): array
{
    return [
        'label' => 'Programs & Events categories (cards)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => [
            'limit' => ['number', 'How many (1-24, empty = all)'],
            'hide_counts' => ['checkbox', 'Hide the number of upcoming sessions'],
            'hide_filters' => ['checkbox', 'Hide the filter buttons'],
        ],
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
    $html = '';
    if (empty($set['hide_filters']) && count($cats) > 4) {
        $groups = [];
        $anyUpcoming = false;
        foreach ($cats as $c) {
            $groups[program_category_group($c)] = true;
            $anyUpcoming = $anyUpcoming || !empty($counts[(int) $c['id']]);
        }
        if (count($groups) > 1 || $anyUpcoming) {
            programhub_js();
            $html .= '<div class="filter-bar pc-filter" role="group" aria-label="Filter programme areas">'
                . '<button type="button" class="filter-btn is-active" data-filter="all" aria-pressed="true">All areas</button>';
            foreach (PROGRAM_CATEGORY_GROUPS as $key => $label) {
                if (isset($groups[$key])) {
                    $html .= '<button type="button" class="filter-btn" data-filter="' . e($key) . '" aria-pressed="false">' . e($label) . '</button>';
                }
            }
            if ($anyUpcoming) {
                $html .= '<button type="button" class="filter-btn" data-filter="upcoming" aria-pressed="false">' . icon('calendar') . 'With upcoming sessions</button>';
            }
            $html .= '</div><p class="sr-only" aria-live="polite" data-pc-live></p>';
        }
    }
    $html .= '<div class="program-grid pc-grid">';
    foreach ($cats as $i => $c) {
        $html .= program_category_card($c, ['index' => $i, 'upcoming' => $counts[(int) $c['id']] ?? 0]);
    }
    return $html . '</div>';
}

function section_schema_program_upcoming_strip(): array
{
    return [
        'label' => 'Upcoming sessions strip (next events, all programme areas)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => [
            'limit' => ['number', 'How many (2-8)'],
            'category' => ['select', 'Only this category', ['' => 'All categories'] + program_category_options()],
        ],
        'help' => 'The next published workshops, sessions and events (not awareness days). Hidden when nothing is scheduled.',
    ];
}

function section_program_upcoming_strip(array $s, array $items, array $set, array $page): string
{
    $limit = max(2, min(8, (int) ($set['limit'] ?? 4) ?: 4));
    $f = ['when' => 'upcoming', 'limit' => $limit, 'include_cancelled' => false];
    if (!empty($set['category'])) {
        $f['category_id'] = (int) $set['category'];
    }
    $rows = programhub_events($f);
    if (!$rows) {
        return '';
    }
    $html = '<ul class="ph-strip" aria-label="Upcoming sessions">';
    foreach ($rows as $i => $ev) {
        $html .= programhub_mini_card($ev, $i);
    }
    return $html . '</ul><div class="section-more"><a class="btn btn-outline" href="' . e(events_list_url()) . '">' . icon('calendar') . 'See all upcoming events' . icon('arrow-right') . '</a></div>';
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
            'hide_photos' => ['checkbox', 'Past programmes: do not show the photo gallery'],
        ],
        'help' => 'Live list of published events from Admin > Events. Past programmes show verified participant numbers, published reports and consented photos only.',
    ];
}

function section_program_events(array $s, array $items, array $set, array $page): string
{
    $when = in_array($set['when'] ?? 'upcoming', ['upcoming', 'open', 'past'], true) ? (string) ($set['when'] ?? 'upcoming') : 'upcoming';
    $cat = programhub_section_category($set, $page);
    $limit = max(1, min(24, (int) ($set['limit'] ?? 6) ?: 6));
    $f = ['when' => $when, 'limit' => $limit, 'category_id' => $cat ? (int) $cat['id'] : 0];
    if (!empty($set['type']) && isset(EVENT_TYPES[$set['type']])) {
        $f['types'] = [(string) $set['type']];
    }
    $rows = programhub_events($f);
    $text = trim((string) ($set['empty_text'] ?? ''));
    if (!$rows) {
        if (!empty($set['hide_empty'])) {
            return '';
        }
        if ($when === 'past') {
            return '<div class="empty-state ph-empty">' . icon('image') . '<p>' . e($text !== '' ? $text : 'Completed sessions will appear here after they take place, with photos, a short report and outcomes verified by our team.') . '</p></div>';
        }
        $isCategoryPage = $cat && !empty($page['id']) && (int) ($cat['page_id'] ?? 0) === (int) $page['id'];
        $what = $cat ? 'New ' . $cat['title'] . ' sessions' : 'New workshops and events';
        return '<div class="empty-state ph-empty">' . icon('calendar') . '<p>' . e($text !== '' ? $text : $what . ' will be announced here — register your interest and we will let you know.') . '</p>'
            . '<div class="btn-row center">'
            . ($cat && (int) $cat['interest_enabled'] === 1
                ? '<a class="btn btn-primary" href="' . e(($isCategoryPage ? '' : program_category_url($cat)) . '#register') . '">' . icon('bell') . 'Register your interest</a>'
                : '<a class="btn btn-primary" href="' . e(page_url('newsletter')) . '">' . icon('mail') . 'Get updates by email</a>')
            . '<a class="btn btn-ghost" href="' . e(events_list_url()) . '">' . icon('calendar') . 'All upcoming events</a></div></div>';
    }
    $html = '<div class="card-grid ph-event-grid">';
    foreach ($rows as $i => $ev) {
        $html .= $when === 'past' ? programhub_past_card($ev, $i) : programhub_event_card($ev, $i);
    }
    $html .= '</div>';
    if ($when === 'past' && $cat && empty($set['hide_photos'])) {
        $photos = programhub_category_gallery((int) $cat['id'], 8);
        if ($photos) {
            $grid = programhub_photo_grid($photos, 'ph-photos-strip');
            if ($grid !== '') {
                $html .= '<div class="ph-gallery-block"><h3 class="ph-subhead">' . icon('image') . 'Photos from completed sessions</h3>' . $grid
                    . '<p class="small muted">Photos are published only with the consent of the people in them.</p></div>';
            }
        }
    }
    if (count($rows) >= $limit) {
        $html .= '<div class="section-more"><a class="btn btn-outline" href="' . e(events_list_url(['category' => $cat['slug'] ?? '', 'when' => $when === 'past' ? 'past' : ''])) . '">'
            . ($when === 'past' ? 'See all past events' : 'See all upcoming events') . icon('arrow-right') . '</a></div>';
    }
    return $html;
}

// ----- Registration form (event page and category page) -----

/**
 * Registration form. $events: open events to choose from (one = no chooser). $o: action (URL incl. #fragment),
 * selected (event id), title (heading inside the card, optional).
 * Conditional blocks (guardian, team) are visible without JavaScript; programs-hub.js shows them only when needed.
 */
function programhub_registration_form(array $events, array $o): string
{
    if (!$events) {
        return '';
    }
    programhub_js();
    $user = current_user();
    $single = count($events) === 1;
    $first = $events[0];
    $selected = (string) old('event_id', $single ? (string) $first['id'] : (string) ($o['selected'] ?? ''));
    $anyTeam = false;
    foreach ($events as $ev) {
        $anyTeam = $anyTeam || in_array($ev['event_type'], HACKATHON_EVENT_TYPES, true);
    }
    $firstState = event_state($first);
    $html = '<form class="ph-form" method="post" action="' . e($o['action']) . '" data-validate novalidate data-ph-reg>'
        . csrf_field() . honeypot_field() . '<input type="hidden" name="form" value="program_register">';
    if ($single) {
        $tmax = (int) ($first['team_size_max'] ?? 0);
        $html .= '<input type="hidden" name="event_id" value="' . (int) $first['id'] . '" data-team="' . (in_array($first['event_type'], HACKATHON_EVENT_TYPES, true) ? 1 : 0)
            . '" data-tmin="' . max(1, (int) ($first['team_size_min'] ?? 0)) . '" data-tmax="' . $tmax . '" data-waitlist="' . ($firstState['open'] ? 0 : 1) . '">';
    } else {
        $html .= '<fieldset class="form-group ph-choose"><legend class="label">Choose a session<span class="req">*</span></legend><div class="ph-options">';
        foreach ($events as $ev) {
            $state = event_state($ev);
            $team = in_array($ev['event_type'], HACKATHON_EVENT_TYPES, true);
            $id = (int) $ev['id'];
            $html .= '<label class="ph-option"><input type="radio" name="event_id" value="' . $id . '" required' . ($selected === (string) $id ? ' checked' : '')
                . ' data-team="' . ($team ? 1 : 0) . '" data-tmin="' . max(1, (int) ($ev['team_size_min'] ?? 0)) . '" data-tmax="' . (int) ($ev['team_size_max'] ?? 0) . '" data-waitlist="' . ($state['open'] ? 0 : 1) . '">'
                . '<span class="ph-option-card">' . content_date_badge((string) $ev['start_datetime'], false)
                . '<span class="ph-option-text"><strong>' . e($ev['title']) . '</strong>'
                . '<small>' . icon('clock') . e(event_when($ev)) . '</small>'
                . '<small>' . icon(event_mode($ev) === 'online' ? 'monitor' : 'map-pin') . e(programhub_event_where($ev)) . '</small>'
                . '<span class="ph-option-meta">' . programhub_status_badge($ev, $state)
                . ($state['open'] && $state['seats_left'] !== null ? '<span>' . (int) $state['seats_left'] . ' places left</span>' : '')
                . (!empty($state['deadline']) ? '<span>Register by ' . e(date('d M, h:i A', (int) $state['deadline'])) . '</span>' : '')
                . '</span></span><span class="ph-option-tick" aria-hidden="true">' . icon('check') . '</span></span></label>';
        }
        $html .= '</div>' . field_error('event_id') . '<p class="small muted ph-option-help">Each session has its own page with full details: <a href="' . e(events_list_url()) . '">see all events</a>.</p></fieldset>';
    }

    $html .= '<div class="form-grid">'
        . '<div class="form-group"><label for="reg-name">Full name<span class="req">*</span></label><input id="reg-name" type="text" name="name" required minlength="2" maxlength="120" autocomplete="name" value="' . e(old('name', $user['name'] ?? '')) . '">' . field_error('name') . '</div>'
        . '<div class="form-group"><label for="reg-email">Email<span class="req">*</span></label><input id="reg-email" type="email" name="email" required maxlength="190" autocomplete="email" value="' . e(old('email', $user['email'] ?? '')) . '">' . field_error('email') . '</div>'
        . '<div class="form-group"><label for="reg-phone">Mobile number<span class="req">*</span></label><input id="reg-phone" type="tel" name="phone" required maxlength="15" autocomplete="tel" inputmode="tel" value="' . e(old('phone', $user['phone'] ?? '')) . '">' . field_error('phone') . '</div>'
        . '<div class="form-group"><label for="reg-city">City / town <span class="muted">(optional)</span></label><input id="reg-city" type="text" name="city" maxlength="100" autocomplete="address-level2" value="' . e(old('city')) . '">' . field_error('city') . '</div>'
        . '<div class="form-group full"><label for="reg-org">School, college or organisation <span class="muted">(optional)</span></label><input id="reg-org" type="text" name="organisation" maxlength="200" autocomplete="organization" value="' . e(old('organisation')) . '">' . field_error('organisation') . '</div>'
        . '</div>';

    if ($anyTeam) {
        $tmin = max(1, (int) ($first['team_size_min'] ?? 0));
        $tmax = (int) ($first['team_size_max'] ?? 0);
        $sizeRange = $single && $tmax > 0 ? ' (' . $tmin . ($tmax > $tmin ? '–' . $tmax : '') . ')' : '';
        $html .= '<div class="ph-team" data-ph-team><p class="ph-block-title">' . icon('users') . 'Your team' . ($single ? '' : ' <span class="muted">(hackathons and innovation challenges only)</span>') . '</p><div class="form-grid">'
            . '<div class="form-group"><label for="reg-team">Team name<span class="req">*</span></label><input id="reg-team" type="text" name="team_name" maxlength="150" data-req value="' . e(old('team_name')) . '"><span class="help">Taking part alone? Use your own name.</span>' . field_error('team_name') . '</div>'
            . (!$single || $tmax > 0 ? '<div class="form-group" data-ph-size><label for="reg-team-size">Number of team members' . e($sizeRange) . '<span class="req">*</span></label><input id="reg-team-size" type="number" name="team_size" inputmode="numeric" min="' . $tmin . '"' . ($tmax > 0 ? ' max="' . $tmax . '"' : '') . ' data-req value="' . e(old('team_size')) . '">' . field_error('team_size') . '</div>' : '')
            . '</div></div>';
    }

    $html .= '<div class="form-group"><label for="reg-message">Anything we should know? <span class="muted">(optional - e.g. accessibility needs)</span></label>'
        . '<textarea id="reg-message" name="message" maxlength="500" rows="3" data-counter>' . e(old('message')) . '</textarea>' . field_error('message') . '</div>'
        . '<label class="checkbox ph-minor"><input type="checkbox" name="is_minor" value="1" aria-controls="reg-guardian"' . old_checked('is_minor', '1') . '><span>The participant is <strong>under 18</strong> (a parent or guardian must agree)</span></label>'
        . '<div class="ph-guardian" id="reg-guardian" data-ph-guardian><p class="ph-block-title">' . icon('shield') . 'Parent or guardian <span class="muted">(only if under 18)</span></p><div class="form-grid">'
        . '<div class="form-group"><label for="reg-guardian-name">Parent / guardian name<span class="req">*</span></label><input id="reg-guardian-name" type="text" name="guardian_name" maxlength="120" data-req value="' . e(old('guardian_name')) . '">' . field_error('guardian_name') . '</div>'
        . '<div class="form-group"><label for="reg-guardian-phone">Parent / guardian mobile<span class="req">*</span></label><input id="reg-guardian-phone" type="tel" name="guardian_phone" maxlength="15" inputmode="tel" data-req value="' . e(old('guardian_phone')) . '">' . field_error('guardian_phone') . '</div>'
        . '</div><div class="form-group"><label class="checkbox"><input type="checkbox" name="guardian_consent" value="1" data-req' . old_checked('guardian_consent', '1') . '><span>I am the parent or guardian, or they have agreed to this registration.<span class="req">*</span></span></label>' . field_error('guardian_consent') . '</div></div>'
        . '<div class="form-group"><label class="checkbox"><input type="checkbox" name="consent" value="1" required' . old_checked('consent', '1') . '><span>I agree that my details are used to manage this registration, as described in the <a href="' . e(page_url('privacy-policy')) . '#programmes-events">privacy policy</a>.<span class="req">*</span></span></label>' . field_error('consent') . '</div>'
        . '<button class="btn btn-primary btn-lg btn-block" type="submit" data-ph-submit>' . icon('check') . '<span>' . ($single && !$firstState['open'] ? 'Join the waiting list' : 'Register now') . '</span></button>'
        . '<p class="form-note">' . icon('lock') . '<span>Registration is free on this website - no payment is asked for or collected here. You will receive a confirmation by email.</span></p>'
        . '</form>';
    return $html;
}

/**
 * Handle a registration POST for $event (published row incl. `registered`); always redirects.
 * Success: confirmation e-mail + staff alert + conversion, then the confirmation page <event>/confirmation/.
 */
function programhub_handle_registration(array $event, string $source, string $back): void
{
    $self = event_url($event);
    if (honeypot_triggered()) {
        flash('success', 'Thank you. Your registration has been received.');
        redirect($back);
    }
    $state = event_state($event);
    if (!$state['can_register']) {
        flash('error', $state['reason'] ?: 'Registration is closed for this event.');
        redirect($back . '#register');
    }
    $minor = !empty($_POST['is_minor']);
    $hack = in_array($event['event_type'], HACKATHON_EVENT_TYPES, true);
    $tmin = max(1, (int) ($event['team_size_min'] ?? 0));
    $tmax = (int) ($event['team_size_max'] ?? 0);
    $rules = [
        'name' => 'required|min:2|max:120', 'email' => 'required|email', 'phone' => 'required|phone',
        'city' => 'max:100', 'organisation' => 'max:200', 'message' => 'text|max:500', 'consent' => 'accepted',
    ];
    if ($minor) {
        $rules += ['guardian_name' => 'required|min:2|max:120', 'guardian_phone' => 'required|phone', 'guardian_consent' => 'accepted'];
    }
    if ($hack) {
        $rules['team_name'] = 'required|min:2|max:150';
        if ($tmax > 0) {
            $rules['team_size'] = 'required|integer|min:' . $tmin . '|max:' . max($tmin, $tmax);
        }
    }
    [$data, $errors] = validate($_POST, $rules, [
        'phone' => 'Mobile number', 'city' => 'City', 'organisation' => 'School, college or organisation', 'consent' => 'Consent',
        'guardian_name' => 'Parent / guardian name', 'guardian_phone' => 'Parent / guardian mobile', 'guardian_consent' => 'Parent / guardian consent',
        'team_name' => 'Team name', 'team_size' => 'Number of team members',
    ]);
    if ($errors) {
        back_with_errors($errors, $back . '#register', $_POST);
    }
    if (form_rate_limited('event', 10)) {
        flash('error', 'Too many registrations were sent from your network in the last hour. Please try again later or contact us.');
        redirect($back . '#register');
    }
    $answers = [];
    if ($hack && $tmax > 0) {
        $answers['team_size'] = (int) $data['team_size'];
    }
    $res = programhub_register((int) $event['id'], [
        'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'message' => $data['message'],
        'city' => $data['city'], 'organisation' => $data['organisation'], 'team_name' => $hack ? $data['team_name'] : '', 'answers' => $answers,
        'consent' => true, 'is_minor' => $minor, 'guardian_name' => $data['guardian_name'] ?? '', 'guardian_phone' => $data['guardian_phone'] ?? '',
        'guardian_consent' => $minor, 'source' => $source,
    ]);
    if ($res['result'] !== 'ok' && $res['result'] !== 'waitlisted') {
        flash($res['result'] === 'duplicate' ? 'info' : 'error', $res['message']);
        redirect($back . '#register');
    }
    $regId = (int) $res['id'];
    $waitlisted = $res['result'] === 'waitlisted';
    log_activity('form_event', 'Registered for event #' . $event['id'] . ' (registration #' . $regId . ($waitlisted ? ', waiting list' : '') . ', ' . $source . ')', current_user_id());
    track_conversion('event_registration', $regId);
    $fresh = db_row('SELECT * FROM events WHERE id = ?', [(int) $event['id']]) ?: $event;
    $reg = db_row('SELECT * FROM event_registrations WHERE id = ?', [$regId]) ?: ['id' => $regId, 'name' => $data['name'], 'email' => $data['email'], 'status' => $res['status'], 'created_at' => date('Y-m-d H:i:s')];
    $sent = programhub_send_participant_email($fresh, $reg, $waitlisted ? 'waitlisted' : 'confirmed', ['token' => $res['token']]);
    programhub_notify_staff_registration($fresh, $reg);
    if (current_user_id()) {
        add_notification((int) current_user_id(), $waitlisted ? 'Added to the waiting list' : 'Event registration confirmed',
            ($waitlisted ? 'You are on the waiting list for ' : 'You are registered for ') . $event['title'] . ' (' . event_when($fresh) . '). Reference ' . programhub_registration_ref($reg) . '.', $self, 'event');
    }
    $_SESSION['ph_confirm'] = ['event_id' => (int) $event['id'], 'reg_id' => $regId, 'token' => (string) $res['token'], 'sent' => $sent, 'at' => time()];
    flash('success', $waitlisted ? 'You are on the waiting list.' : 'You are registered.');
    redirect($self . 'confirmation/#confirmation');
}

/** Confirmation page body (after a registration). */
function programhub_confirmation_html(array $event, array $reg, string $token, bool $sent): string
{
    $waitlisted = $reg['status'] === 'waitlisted';
    $mode = event_mode($event);
    $self = event_url($event);
    $html = '<div class="ph-confirm" id="confirmation" data-no-track><div class="ph-confirm-card">'
        . '<span class="ph-confirm-icon' . ($waitlisted ? ' is-wait' : '') . '">' . icon($waitlisted ? 'clock' : 'check') . '</span>'
        . '<h2>' . ($waitlisted ? 'You are on the waiting list' : 'You are registered') . '</h2>'
        . '<p class="lead">' . ($waitlisted ? 'All places are taken at the moment. If a place becomes available we will email you, in the order people joined the waiting list.' : 'Thank you for registering. We look forward to seeing you.') . '</p>'
        . '<div class="ph-ref"><small>Your reference number</small><strong>' . e(programhub_registration_ref($reg)) . '</strong>'
        . '<button type="button" class="btn btn-ghost btn-sm" data-copy="' . e(programhub_registration_ref($reg)) . '">' . icon('copy') . 'Copy</button></div>'
        . '<div class="ph-confirm-event">' . content_date_badge((string) $event['start_datetime']) . '<div><h3><a href="' . e($self) . '">' . e($event['title']) . '</a></h3>'
        . '<p>' . icon('clock') . '<span>' . e(event_when($event, true)) . '</span></p>'
        . '<p>' . icon($mode === 'online' ? 'monitor' : 'map-pin') . '<span>' . e(programhub_event_where($event, false)) . '</span></p></div></div>'
        . '<div class="btn-row center ph-confirm-actions"><a class="btn btn-primary" href="' . e($self . 'ics/') . '" rel="nofollow" download>' . icon('download') . 'Add to calendar (.ics)</a>'
        . '<a class="btn btn-outline" href="' . e(programhub_google_calendar_url($event)) . '" target="_blank" rel="noopener noreferrer">' . icon('calendar') . 'Google Calendar</a></div>';
    if (!$waitlisted && $mode !== 'in_person' && $token !== '') {
        $html .= '<div class="ph-private">' . icon('lock') . '<div><strong>Your private joining page</strong><p>The online joining link is shown there and emailed to you before the session. Please keep this link to yourself.</p>'
            . '<a class="btn btn-blue btn-sm" href="' . e(programhub_join_url($event, (int) $reg['id'], $token)) . '" rel="nofollow">' . icon('monitor') . 'Open joining page</a></div></div>';
    }
    $html .= '</div><div class="ph-next"><h3>What happens next</h3><ol class="ph-next-steps">'
        . '<li><strong>' . ($sent ? 'Check your inbox' : 'Keep your reference number') . '</strong><span>' . ($sent
            ? 'A confirmation has been sent to ' . e(programhub_mask_email((string) $reg['email'])) . '. If you cannot find it, look in your spam folder.'
            : 'We could not send a confirmation email just now. Please note your reference number - our team can find your registration with it.') . '</span></li>'
        . '<li><strong>' . ($mode === 'in_person' ? 'Plan your visit' : 'Joining details') . '</strong><span>' . ($mode === 'in_person'
            ? 'The venue, date and time are on the event page. If anything changes, we will email you.'
            : 'Joining details for the online session are shared only with registered participants, by email and on your private joining page.') . '</span></li>'
        . '<li><strong>Cannot make it?</strong><span>Please reply to the confirmation email' . (current_user_id() ? ' or cancel from <a href="' . e(member_url('events')) . '">My events</a>' : '') . ', so that someone on the waiting list can take your place.</span></li>'
        . '</ol>' . (!empty($reg['is_minor']) ? '<p class="small muted">' . icon('shield') . ' Registered as a participant under 18 with the consent of a parent or guardian.</p>' : '')
        . '<p><a class="link-arrow" href="' . e($self) . '">Back to the event page' . icon('arrow-right') . '</a></p></div></div>';
    return $html;
}

/**
 * Who may open the private joining page / registered-only files of an event:
 * a visitor who opened a valid private link in this browser session (token hash must still match), or the signed-in owner.
 * Returns ['reg' => row, 'via' => 'link'|'account'] or null.
 */
function programhub_event_access(array $event): ?array
{
    $eid = (int) $event['id'];
    $sess = $_SESSION['ph_access'][$eid] ?? null;
    if (is_array($sess)) {
        $reg = db_row('SELECT * FROM event_registrations WHERE id = ? AND event_id = ?', [(int) ($sess['reg'] ?? 0), $eid]);
        if ($reg && !empty($reg['access_token_hash']) && hash_equals((string) $reg['access_token_hash'], (string) ($sess['h'] ?? ''))) {
            return ['reg' => $reg, 'via' => 'link'];
        }
        unset($_SESSION['ph_access'][$eid]);
    }
    $reg = programhub_member_registration($eid);
    return $reg ? ['reg' => $reg, 'via' => 'account'] : null;
}

/** Remember a valid private link in the session (so the token leaves the address bar). */
function programhub_remember_access(array $event, array $reg): void
{
    $all = is_array($_SESSION['ph_access'] ?? null) ? $_SESSION['ph_access'] : [];
    $all[(int) $event['id']] = ['reg' => (int) $reg['id'], 'h' => (string) $reg['access_token_hash']];
    $_SESSION['ph_access'] = array_slice($all, -10, null, true);
}

// ----- Register interest (nothing open) -----

function programhub_interest_form(array $cat, string $action): string
{
    $user = current_user();
    return '<form class="ph-form ph-interest-form" method="post" action="' . e($action) . '" data-validate novalidate>'
        . csrf_field() . honeypot_field() . '<input type="hidden" name="form" value="program_interest">'
        . '<div class="form-grid">'
        . '<div class="form-group"><label for="in-name">Name<span class="req">*</span></label><input id="in-name" type="text" name="in_name" required minlength="2" maxlength="120" autocomplete="name" value="' . e(old('in_name', $user['name'] ?? '')) . '">' . field_error('in_name') . '</div>'
        . '<div class="form-group"><label for="in-email">Email<span class="req">*</span></label><input id="in-email" type="email" name="in_email" required maxlength="190" autocomplete="email" value="' . e(old('in_email', $user['email'] ?? '')) . '">' . field_error('in_email') . '</div>'
        . '<div class="form-group"><label for="in-phone">Mobile number <span class="muted">(optional)</span></label><input id="in-phone" type="tel" name="in_phone" maxlength="15" autocomplete="tel" inputmode="tel" value="' . e(old('in_phone', $user['phone'] ?? '')) . '">' . field_error('in_phone') . '</div>'
        . '<div class="form-group"><label for="in-city">City / town <span class="muted">(optional)</span></label><input id="in-city" type="text" name="in_city" maxlength="100" autocomplete="address-level2" value="' . e(old('in_city')) . '">' . field_error('in_city') . '</div>'
        . '<div class="form-group full"><label for="in-message">What would you like to learn or join? <span class="muted">(optional)</span></label><textarea id="in-message" name="in_message" maxlength="500" rows="3" data-counter>' . e(old('in_message')) . '</textarea>' . field_error('in_message') . '</div>'
        . '</div><div class="form-group"><label class="checkbox"><input type="checkbox" name="in_consent" value="1" required' . old_checked('in_consent', '1') . '><span>Email me when new ' . e($cat['title']) . ' sessions are announced. I can ask you to remove my details at any time.<span class="req">*</span></span></label>' . field_error('in_consent') . '</div>'
        . '<button class="btn btn-primary btn-block" type="submit">' . icon('bell') . 'Notify me</button>'
        . '<p class="form-note">' . icon('lock') . '<span>We use these details only to tell you about sessions in this area. See our <a href="' . e(page_url('privacy-policy')) . '#programmes-events">privacy policy</a>.</span></p></form>';
}

function programhub_handle_interest(array $cat, string $back): void
{
    if (honeypot_triggered()) {
        flash('success', 'Thank you. We will let you know when new sessions are announced.');
        redirect($back . '#register');
    }
    [$data, $errors] = validate($_POST, [
        'in_name' => 'required|min:2|max:120', 'in_email' => 'required|email', 'in_phone' => 'phone', 'in_city' => 'max:100',
        'in_message' => 'text|max:500', 'in_consent' => 'accepted',
    ], ['in_name' => 'Name', 'in_email' => 'Email', 'in_phone' => 'Mobile number', 'in_city' => 'City', 'in_message' => 'Message', 'in_consent' => 'Consent']);
    if ($errors) {
        back_with_errors($errors, $back . '#register-interest', $_POST);
    }
    if (form_rate_limited('program_interest', 5)) {
        flash('error', 'Several requests were sent from your network in the last hour. Please try again later.');
        redirect($back . '#register');
    }
    $existing = db_row("SELECT id FROM program_interests WHERE email = ? AND category_id = ? AND status = 'new'", [$data['in_email'], (int) $cat['id']]);
    if ($existing) {
        db_update('program_interests', array_filter(['phone' => $data['in_phone'], 'city' => $data['in_city'], 'message' => $data['in_message']], 'strlen') + ['consent_at' => date('Y-m-d H:i:s')],
            'id = :id', ['id' => (int) $existing['id']]);
        log_activity('form_program_interest', 'Updated interest #' . $existing['id'], current_user_id());
        $_SESSION['ph_done'] = ['form' => 'interest', 'cat' => (int) $cat['id'], 'again' => true, 'at' => time()];
        flash('info', 'You are already on our list for ' . $cat['title'] . '. We have updated your details.');
        redirect($back . '#register');
    }
    $id = db_insert('program_interests', [
        'category_id' => (int) $cat['id'], 'user_id' => current_user_id(), 'name' => $data['in_name'], 'email' => $data['in_email'],
        'phone' => $data['in_phone'] !== '' ? $data['in_phone'] : null, 'city' => $data['in_city'] !== '' ? $data['in_city'] : null,
        'message' => $data['in_message'] !== '' ? mb_substr($data['in_message'], 0, 500) : null, 'consent_at' => date('Y-m-d H:i:s'), 'status' => 'new',
    ]);
    log_activity('form_program_interest', 'Interest #' . $id . ' for category #' . $cat['id'], current_user_id());
    track_conversion('program_interest', $id);
    send_mail($data['in_email'], 'We will keep you posted: ' . $cat['title'],
        '<p>Dear ' . e($data['in_name']) . ',</p><p>Thank you for your interest in our <strong>' . e($cat['title']) . '</strong> programmes. '
        . 'We will email you when new sessions are announced and registration opens.</p>'
        . '<p>Upcoming sessions are always listed on <a href="' . e(program_category_abs_url($cat)) . '">' . e(program_category_abs_url($cat)) . '</a>.</p>'
        . '<p>If you no longer want these emails, reply to this message and we will remove your details.</p><p>Regards,<br>' . e(site_name()) . '</p>',
        setting('contact_email') ?: null);
    if (setting('events_admin_alerts', '1') === '1') {
        programhub_notify_staff($cat, 'Programme interest: ' . $cat['title'],
            '<p>Someone registered their interest in <strong>' . e($cat['title']) . '</strong> (no session was open). See the interest list in Admin &gt; Program categories.</p>');
    }
    $_SESSION['ph_done'] = ['form' => 'interest', 'cat' => (int) $cat['id'], 'at' => time()];
    flash('success', 'Thank you. We will let you know when new ' . $cat['title'] . ' sessions are announced.');
    redirect($back . '#register');
}

// ----- Partnership enquiry -----

function programhub_partnership_form(array $cat, string $action): string
{
    $user = current_user();
    return '<form class="ph-form" method="post" action="' . e($action) . '" data-validate novalidate>'
        . csrf_field() . honeypot_field() . '<input type="hidden" name="form" value="partnership">'
        . '<div class="form-grid">'
        . '<div class="form-group"><label for="pt-name">Your name<span class="req">*</span></label><input id="pt-name" type="text" name="pt_name" required minlength="2" maxlength="120" autocomplete="name" value="' . e(old('pt_name', $user['name'] ?? '')) . '">' . field_error('pt_name') . '</div>'
        . '<div class="form-group"><label for="pt-email">Email<span class="req">*</span></label><input id="pt-email" type="email" name="pt_email" required maxlength="190" autocomplete="email" value="' . e(old('pt_email', $user['email'] ?? '')) . '">' . field_error('pt_email') . '</div>'
        . '<div class="form-group"><label for="pt-phone">Mobile number <span class="muted">(optional)</span></label><input id="pt-phone" type="tel" name="pt_phone" maxlength="15" autocomplete="tel" inputmode="tel" value="' . e(old('pt_phone')) . '">' . field_error('pt_phone') . '</div>'
        . '<div class="form-group"><label for="pt-kind">You are<span class="req">*</span></label><select id="pt-kind" name="pt_kind" required>' . options_html(PARTNERSHIP_KINDS, (string) old('pt_kind'), true, 'Choose one') . '</select>' . field_error('pt_kind') . '</div>'
        . '<div class="form-group full"><label for="pt-org">Organisation / institution<span class="req">*</span></label><input id="pt-org" type="text" name="pt_org" required minlength="2" maxlength="200" autocomplete="organization" value="' . e(old('pt_org')) . '">' . field_error('pt_org') . '</div>'
        . '<div class="form-group full"><label for="pt-message">How would you like to work together?<span class="req">*</span></label><textarea id="pt-message" name="pt_message" required minlength="10" maxlength="2000" rows="4" data-counter>' . e(old('pt_message')) . '</textarea>' . field_error('pt_message') . '</div>'
        . '</div><div class="form-group"><label class="checkbox"><input type="checkbox" name="pt_consent" value="1" required' . old_checked('pt_consent', '1') . '><span>I agree that these details are used to reply to this enquiry, as described in the <a href="' . e(page_url('privacy-policy')) . '">privacy policy</a>.<span class="req">*</span></span></label>' . field_error('pt_consent') . '</div>'
        . '<button class="btn btn-primary btn-block" type="submit">' . icon('send') . 'Send enquiry</button></form>';
}

function programhub_handle_partnership(array $cat, string $back): void
{
    if (honeypot_triggered()) {
        flash('success', 'Thank you. Your enquiry has been received.');
        redirect($back . '#partner');
    }
    [$data, $errors] = validate($_POST, [
        'pt_name' => 'required|min:2|max:120', 'pt_email' => 'required|email', 'pt_phone' => 'phone',
        'pt_kind' => 'required|in:' . implode(',', array_keys(PARTNERSHIP_KINDS)), 'pt_org' => 'required|min:2|max:200',
        'pt_message' => 'required|text|min:10|max:2000', 'pt_consent' => 'accepted',
    ], ['pt_name' => 'Your name', 'pt_email' => 'Email', 'pt_phone' => 'Mobile number', 'pt_kind' => 'You are', 'pt_org' => 'Organisation', 'pt_message' => 'Message', 'pt_consent' => 'Consent']);
    if ($errors) {
        back_with_errors($errors, $back . '#partner', $_POST);
    }
    if (form_rate_limited('partnership', 5)) {
        flash('error', 'Several enquiries were sent from your network in the last hour. Please try again later, or write to us at ' . setting('contact_email', 'our email address') . '.');
        redirect($back . '#partner');
    }
    $kind = PARTNERSHIP_KINDS[$data['pt_kind']];
    $id = db_insert('contact_enquiries', [
        'type' => 'partnership', 'user_id' => current_user_id(), 'name' => $data['pt_name'], 'email' => $data['pt_email'],
        'phone' => $data['pt_phone'] !== '' ? $data['pt_phone'] : null, 'subject' => mb_substr('Partnership: ' . $cat['title'] . ' (' . $kind . ')', 0, 200),
        'message' => $data['pt_message'], 'status' => 'new', 'ip_address' => null, 'category_id' => (int) $cat['id'],
        'organisation' => $data['pt_org'], 'partner_kind' => $data['pt_kind'],
    ]);
    $ref = reference_no('ENQ', $id);
    db_update('contact_enquiries', ['reference_no' => $ref], 'id = :id', ['id' => $id]);
    log_activity('form_partnership', 'Partnership enquiry ' . $ref, current_user_id());
    track_conversion('contact_enquiry', $id);
    $quote = '<blockquote style="margin:16px 0;padding:12px 16px;background:#f5f9f4;border-left:4px solid #146B43">' . e_nl($data['pt_message']) . '</blockquote>';
    send_mail($data['pt_email'], 'We received your partnership enquiry - ' . $ref,
        '<p>Dear ' . e($data['pt_name']) . ',</p><p>Thank you for your interest in working with ' . e(site_name()) . ' on <strong>' . e($cat['title']) . '</strong> programmes. '
        . 'Our team will read your enquiry and reply by email.</p><p>Your reference number is <strong style="font-size:17px">' . e($ref) . '</strong>.</p>'
        . '<p><strong>Your message:</strong></p>' . $quote . '<p>Regards,<br>' . e(site_name()) . '</p>');
    programhub_notify_staff($cat, 'Partnership enquiry ' . $ref . ': ' . $cat['title'],
        '<p><strong>' . e($data['pt_name']) . '</strong> (' . e($data['pt_email']) . ') - ' . e($kind) . ', ' . e($data['pt_org']) . ' - sent a partnership enquiry about <strong>' . e($cat['title']) . '</strong> (' . e($ref) . ').</p>'
        . $quote . '<p>It is listed in Admin &gt; Enquiries.</p>', $data['pt_email']);
    $_SESSION['ph_done'] = ['form' => 'partnership', 'cat' => (int) $cat['id'], 'ref' => $ref, 'at' => time()];
    flash('success', 'Thank you. Your reference number is ' . $ref . '.');
    redirect($back . '#partner');
}

function section_schema_program_registration(): array
{
    return [
        'label' => 'Programme registration (open sessions / register interest)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => ['category' => ['select', 'Category', ['' => 'This page\'s category'] + program_category_options()]],
        'help' => 'Registration form for the open sessions of this category; when none is open, visitors can register their interest (if enabled for the category).',
    ];
}

/** Steps shown next to the registration form (honest, generic). */
function programhub_how_it_works(): string
{
    $steps = [
        ['calendar', 'Choose an open session', 'Dates, venue or online mode and eligibility are on each event page.'],
        ['edit', 'Register with your details', 'Participants under 18 need the consent of a parent or guardian.'],
        ['mail', 'Get your confirmation', 'By email, with a reference number and a calendar file. Online sessions: a private joining link for registered participants only.'],
        ['users', 'Take part', 'If a session is full it may have a waiting list - we email you if a place opens up.'],
    ];
    $html = '<aside class="ph-how"><h3>How registration works</h3><ol>';
    foreach ($steps as $i => [$ic, $t, $x]) {
        $html .= '<li><span class="ph-how-num">' . ($i + 1) . '</span><div><strong>' . e($t) . '</strong><span>' . e($x) . '</span></div></li>';
    }
    return $html . '</ol><p class="ph-how-note">' . icon('shield') . '<span>No fee is collected on this website. If a session has a participation fee, its event page says so clearly. Please do not pay anyone who asks for money in our name without checking with us first.</span></p></aside>';
}

function section_program_registration(array $s, array $items, array $set, array $page): string
{
    $cat = programhub_section_category($set, $page);
    if (!$cat) {
        $open = programhub_events(['when' => 'open', 'limit' => 12]);
        if (!$open) {
            return '';
        }
        $html = '<div class="card-grid ph-event-grid">';
        foreach ($open as $i => $ev) {
            $html .= programhub_event_card($ev, $i);
        }
        return $html . '</div>';
    }
    $open = programhub_events(['when' => 'open', 'limit' => 24, 'category_id' => (int) $cat['id']]);
    $action = program_category_url($cat);
    $interest = (int) $cat['interest_enabled'] === 1;
    $done = $interest ? programhub_take_done('interest', (int) $cat['id']) : null;
    $donePanel = $done ? '<div class="ph-done" role="status">' . icon('check-circle') . '<div><strong>' . (!empty($done['again']) ? 'You are already on our list' : 'Thank you - you are on our list') . '</strong>'
        . '<p>We will email you when new ' . e($cat['title']) . ' sessions are announced.</p></div></div>' : '';
    $html = '<div class="ph-reg-layout"><div class="ph-reg-main">';
    if ($open) {
        $html .= '<div class="form-card ph-form-card"><h3 class="ph-form-title">' . icon('check-circle') . (count($open) === 1 ? 'Register for this session' : 'Register for a session') . '</h3>'
            . programhub_registration_form($open, ['action' => $action . '#register']) . '</div>';
        if ($interest) {
            $hasInterestInput = old('in_email', null) !== null;
            $html .= '<details class="ph-more" id="register-interest"' . ($done || $hasInterestInput ? ' open' : '') . '><summary>' . icon('bell') . 'None of these dates suit you? Register your interest' . icon('chevron-down') . '</summary><div class="ph-more-body">'
                . ($donePanel !== '' ? $donePanel : programhub_interest_form($cat, $action . '#register-interest')) . '</div></details>';
        }
    } else {
        $html .= '<div class="ph-none">' . icon('calendar') . '<div><strong>There is no open programme in this area right now.</strong>'
            . '<p>New ' . e($cat['title']) . ' sessions are announced on this page and on our <a href="' . e(events_list_url()) . '">events page</a>.' . ($interest ? ' Leave your details and we will let you know when registration opens.' : '') . '</p></div></div>';
        if ($interest) {
            $html .= $donePanel !== '' ? $donePanel : '<div class="form-card ph-form-card" id="register-interest"><h3 class="ph-form-title">' . icon('bell') . 'Register your interest</h3>'
                . programhub_interest_form($cat, $action . '#register-interest') . '</div>';
        } else {
            $html .= '<div class="btn-row"><a class="btn btn-primary" href="' . e(page_url('newsletter')) . '">' . icon('mail') . 'Get updates by email</a>'
                . '<a class="btn btn-ghost" href="' . e(page_url('contact')) . '">' . icon('message') . 'Contact us</a></div>';
        }
    }
    return $html . '</div>' . programhub_how_it_works() . '</div>';
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

function section_partnership_enquiry(array $s, array $items, array $set, array $page): string
{
    $cat = programhub_section_category($set, $page);
    $kinds = [
        ['graduation', 'Schools, colleges & institutions', 'Host a session or a series for your students or members.'],
        ['user', 'Trainers, experts & speakers', 'Volunteer your time and knowledge as a trainer, mentor or judge.'],
        ['briefcase', 'Sponsors & CSR teams', 'Support a programme with resources, equipment or venues.'],
        ['users', 'Community organisations', 'Bring a programme to your neighbourhood, village or community centre.'],
    ];
    $html = '<div class="ph-partner-layout"><div class="ph-partner-info"><ul class="ph-kinds">';
    foreach ($kinds as [$ic, $t, $x]) {
        $html .= '<li><span class="icon-circle">' . icon($ic) . '</span><div><strong>' . e($t) . '</strong><span>' . e($x) . '</span></div></li>';
    }
    $html .= '</ul>' . (setting('contact_email') !== '' ? '<p class="ph-mail">' . icon('mail') . '<span>Prefer email? Write to <a href="mailto:' . e(setting('contact_email')) . '">' . e(setting('contact_email')) . '</a></span></p>' : '') . '</div>';
    if (!$cat || (int) $cat['partnership_enabled'] !== 1) {
        return $html . '<div class="form-card ph-form-card"><p>Tell us about your organisation and how you would like to work together. Our team replies by email.</p>'
            . '<a class="btn btn-primary" href="' . e(page_url('contact', ['type' => 'partnership'])) . '">' . icon('send') . 'Send a partnership enquiry</a></div></div>';
    }
    $done = programhub_take_done('partnership', (int) $cat['id']);
    $html .= '<div class="form-card ph-form-card">';
    if ($done) {
        $html .= '<div class="ph-done" role="status">' . icon('check-circle') . '<div><strong>Thank you - your enquiry has been sent</strong>'
            . '<p>Your reference number is <strong>' . e((string) ($done['ref'] ?? '')) . '</strong>. We have emailed you an acknowledgement and will reply as soon as possible.</p></div></div>';
    } else {
        $html .= '<h3 class="ph-form-title">' . icon('send') . 'Partnership enquiry</h3>' . programhub_partnership_form($cat, program_category_url($cat) . '#partner');
    }
    return $html . '</div></div>';
}

// ----- Event page blocks -----

/** "At a glance" key facts of an event (only facts that are set; fee is display-only; certificate only when confirmed). */
function programhub_event_glance(array $ev, array $state): string
{
    $facts = [];
    $cat = programhub_event_category($ev);
    $mode = event_mode($ev);
    $facts[] = ['layers', 'Type', e(event_type_label($ev['event_type']))];
    if ($cat) {
        $facts[] = [(string) ($cat['icon'] ?: 'layers'), 'Programme area', '<a href="' . e(program_category_url($cat)) . '">' . e($cat['title']) . '</a>'];
    }
    $facts[] = [$mode === 'in_person' ? 'map-pin' : 'monitor', 'Mode', e(EVENT_MODES[$mode])];
    if ($mode !== 'online') {
        $venue = e(trim((string) $ev['location']) !== '' ? (string) $ev['location'] : 'Venue to be announced');
        if (trim((string) ($ev['venue_address'] ?? '')) !== '') {
            $venue .= '<br><span class="muted">' . e((string) $ev['venue_address']) . '</span>';
        }
        $map = programhub_map_url($ev);
        if ($map !== '') {
            $venue .= '<br><a href="' . e($map) . '" target="_blank" rel="noopener noreferrer">Open in Google Maps' . icon('external') . '</a>';
        }
        $facts[] = ['map-pin', 'Venue', $venue];
    }
    if (trim((string) ($ev['eligibility'] ?? '')) !== '') {
        $facts[] = ['users', 'Who can take part', e_nl((string) $ev['eligibility'])];
    }
    if (in_array($ev['event_type'], HACKATHON_EVENT_TYPES, true) && (int) ($ev['team_size_max'] ?? 0) > 0) {
        $tmin = max(1, (int) $ev['team_size_min']);
        $tmax = (int) $ev['team_size_max'];
        $facts[] = ['users', 'Team size', $tmin === $tmax ? $tmin . ' members' : $tmin . '–' . $tmax . ' members'];
    }
    if ((int) $ev['registration_enabled'] === 1 && !$state['is_past'] && $ev['status'] === 'published') {
        $reg = [];
        if ($state['not_yet_open'] && $state['opens_at']) {
            $reg[] = 'Opens ' . date('d M Y, h:i A', (int) $state['opens_at']);
        }
        if ($state['deadline']) {
            $reg[] = ($state['deadline_passed'] ? 'Closed on ' : 'Register by ') . date('d M Y, h:i A', (int) $state['deadline']);
        }
        if ($state['capacity'] !== null) {
            $reg[] = $state['seats_left'] > 0 ? (int) $state['seats_left'] . ' of ' . (int) $state['capacity'] . ' places left'
                : ((int) $state['capacity'] === 1 ? 'The only place has been taken' : 'All ' . (int) $state['capacity'] . ' places taken') . ($state['waitlist_open'] ? ' - waiting list open' : '');
        }
        $facts[] = ['check-circle', 'Registration', e($reg ? implode(' · ', $reg) : 'Open until the event starts')];
    } elseif ((int) $ev['registration_enabled'] !== 1 && $ev['event_type'] !== 'awareness_day') {
        $facts[] = ['check-circle', 'Registration', 'Not required'];
    }
    $fee = (float) ($ev['fee_amount'] ?? 0);
    $facts[] = ['rupee', 'Participation fee', $fee > 0
        ? '<strong>' . e(money($fee)) . '</strong>' . (trim((string) ($ev['fee_note'] ?? '')) !== '' ? ' - ' . e((string) $ev['fee_note']) : '') . '<br><span class="muted">Shown for information only. No payment is collected on this website.</span>'
        : 'Free'];
    if (!empty($ev['certificate_confirmed'])) {
        $facts[] = ['award', 'Certificate', e(trim((string) ($ev['certificate_note'] ?? '')) ?: 'Participation certificates are confirmed for attendees whose attendance is recorded.')];
    }
    $facts[] = ['flag', 'Organised by', trim((string) ($ev['organising_team'] ?? '')) !== '' ? e_nl((string) $ev['organising_team']) : e(site_name())];
    $contact = [];
    $email = trim((string) ($ev['contact_email'] ?? '')) ?: setting('contact_email');
    if ($email !== '') {
        $contact[] = '<a href="mailto:' . e($email) . '">' . e($email) . '</a>';
    }
    if (trim((string) ($ev['contact_phone'] ?? '')) !== '') {
        $contact[] = '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', (string) $ev['contact_phone'])) . '">' . e((string) $ev['contact_phone']) . '</a>';
    }
    if ($contact) {
        $facts[] = ['mail', 'Questions?', implode('<br>', $contact)];
    }
    $html = '<dl class="ph-glance">';
    foreach ($facts as [$ic, $label, $value]) {
        $html .= '<div><dt>' . icon($ic) . e($label) . '</dt><dd>' . $value . '</dd></div>';
    }
    return $html . '</dl>';
}

/** Agenda / session schedule (events.agenda JSON list of {time, title, speaker, text}). */
function programhub_agenda_html(array $ev): string
{
    $items = array_values(array_filter(programhub_json_list($ev['agenda'] ?? null), function ($a) {
        return trim((string) ($a['title'] ?? '')) !== '';
    }));
    if (!$items) {
        return '';
    }
    $html = '<ol class="ph-agenda">';
    foreach ($items as $a) {
        $html .= '<li><span class="ph-agenda-time">' . e(trim((string) ($a['time'] ?? '')) ?: '·') . '</span><div class="ph-agenda-body"><strong>' . e((string) $a['title']) . '</strong>'
            . (trim((string) ($a['speaker'] ?? '')) !== '' ? '<small>' . icon('user') . e((string) $a['speaker']) . '</small>' : '')
            . (trim((string) ($a['text'] ?? '')) !== '' ? '<p>' . e_nl((string) $a['text']) . '</p>' : '') . '</div></li>';
    }
    return $html . '</ol>';
}

/** Speaker / trainer cards (public profiles only; photo only with photo consent; never e-mail or phone). */
function programhub_speakers_html(array $speakers): string
{
    if (!$speakers) {
        return '';
    }
    $html = '<div class="ph-people">';
    foreach ($speakers as $i => $sp) {
        $photo = (int) ($sp['photo_consent'] ?? 0) === 1 && !empty($sp['photo']) && strpos((string) $sp['photo'], '..') === false && is_file(ROOT_PATH . '/' . $sp['photo']) ? (string) $sp['photo'] : '';
        $initials = '';
        foreach (array_slice(preg_split('/\s+/', trim((string) $sp['name'])) ?: [], 0, 2) as $w) {
            $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        $links = '';
        foreach (['website_url' => ['globe', 'Website'], 'linkedin_url' => ['linkedin', 'LinkedIn']] as $col => [$ic, $label]) {
            $u = trim((string) ($sp[$col] ?? ''));
            if ($u !== '' && preg_match('#^https?://#i', $u)) {
                $links .= '<a href="' . e(safe_link($u)) . '" target="_blank" rel="noopener noreferrer nofollow" aria-label="' . e($label . ': ' . $sp['name']) . '">' . icon($ic) . '</a>';
            }
        }
        $role = SPEAKER_ROLES[$sp['role'] ?? 'speaker'] ?? 'Speaker';
        $html .= '<article class="ph-person reveal" style="--d:' . ($i % 4) . '">'
            . ($photo !== '' ? '<img class="ph-avatar" src="' . e(url($photo)) . '" alt="" width="88" height="88" loading="lazy">' : '<span class="ph-avatar ph-initials" aria-hidden="true">' . e($initials) . '</span>')
            . '<div class="ph-person-body"><span class="ph-role">' . e($role) . '</span><h3>' . e((string) $sp['name']) . '</h3>'
            . (trim((string) ($sp['designation'] ?? '') . (string) ($sp['organisation'] ?? '')) !== '' ? '<p class="ph-person-meta">' . e(implode(', ', array_filter([trim((string) ($sp['designation'] ?? '')), trim((string) ($sp['organisation'] ?? ''))]))) . '</p>' : '')
            . (trim((string) ($sp['session_title'] ?? '')) !== '' ? '<p class="ph-person-session">' . icon('message') . '<span>' . e((string) $sp['session_title']) . '</span></p>' : '')
            . (trim((string) ($sp['bio'] ?? '')) !== '' ? '<p class="ph-person-bio">' . e(excerpt((string) $sp['bio'], 260)) . '</p>' : '')
            . ($links !== '' ? '<p class="ph-person-links">' . $links . '</p>' : '') . '</div></article>';
    }
    return $html . '</div>';
}

/** Partner logos / names (only with documented display consent). */
function programhub_partners_html(array $partners): string
{
    if (!$partners) {
        return '';
    }
    $html = '<ul class="ph-partners">';
    foreach ($partners as $p) {
        $logo = !empty($p['logo']) && strpos((string) $p['logo'], '..') === false && is_file(ROOT_PATH . '/' . $p['logo']) ? (string) $p['logo'] : '';
        $inner = ($logo !== '' ? '<img src="' . e(url($logo)) . '" alt="' . e((string) $p['name']) . '" loading="lazy">' : '<span class="ph-partner-name">' . e((string) $p['name']) . '</span>')
            . '<small>' . e(EVENT_PARTNER_ROLES[$p['role'] ?? 'partner'] ?? 'Partner') . ($logo !== '' ? ' · ' . e((string) $p['name']) : '') . '</small>';
        $u = trim((string) ($p['website_url'] ?? ''));
        $html .= '<li>' . ($u !== '' && preg_match('#^https?://#i', $u) ? '<a href="' . e(safe_link($u)) . '" target="_blank" rel="noopener noreferrer nofollow">' . $inner . '</a>' : '<div>' . $inner . '</div>') . '</li>';
    }
    return $html . '</ul>';
}

/**
 * Downloads: public files / links, and registered-only items. $access: the visitor's access (programhub_event_access()).
 * Registered-only files are served by <event>/file/<id>/ after an access check - never linked directly.
 */
function programhub_resources_html(array $ev, ?array $access, array $types = []): string
{
    $canPrivate = $access && in_array($access['reg']['status'], EVENT_ACCESS_STATUSES, true) && $ev['status'] === 'published';
    $rows = programhub_event_resources((int) $ev['id'], 'registered');
    if ($types) {
        $rows = array_values(array_filter($rows, function ($r) use ($types) { return in_array($r['resource_type'], $types, true); }));
    }
    if (!$rows) {
        return '';
    }
    $html = '<ul class="ph-files">';
    $locked = 0;
    foreach ($rows as $r) {
        $type = EVENT_RESOURCE_TYPES[$r['resource_type']] ?? 'File';
        $href = '';
        if ($r['visibility'] === 'public') {
            if (!empty($r['external_url']) && preg_match('#^https?://#i', (string) $r['external_url'])) {
                $href = safe_link((string) $r['external_url']);
            } elseif (!empty($r['file_path']) && strpos((string) $r['file_path'], 'uploads/') === 0 && strpos((string) $r['file_path'], 'uploads/documents/') !== 0) {
                $href = url((string) $r['file_path']);
            }
        } elseif ($canPrivate) {
            $href = !empty($r['external_url']) && preg_match('#^https?://#i', (string) $r['external_url']) ? safe_link((string) $r['external_url'])
                : (!empty($r['file_path']) ? event_url($ev) . 'file/' . (int) $r['id'] . '/' : '');
        }
        if ($href === '' && $r['visibility'] === 'registered') {
            $locked++;
            $html .= '<li class="is-locked">' . icon('lock') . '<div><strong>' . e((string) $r['title']) . '</strong><small>' . e($type) . ' · for registered participants</small></div></li>';
            continue;
        }
        if ($href === '') {
            continue;
        }
        $external = strpos($href, 'http') === 0;
        $html .= '<li><a href="' . e($href) . '"' . ($external ? ' target="_blank" rel="noopener noreferrer"' : ' rel="nofollow"') . '>' . icon($external ? 'external' : 'download')
            . '<div><strong>' . e((string) $r['title']) . '</strong><small>' . e($type) . (trim((string) ($r['description'] ?? '')) !== '' ? ' · ' . e((string) $r['description']) : '') . '</small></div></a></li>';
    }
    $html .= '</ul>';
    if ($locked) {
        $html .= '<p class="small muted ph-locked-note">' . icon('info') . ' Registered participants can open these from their private joining page (link in the confirmation email) or, when signed in, from this page.</p>';
    }
    return $html;
}

/** Report and verified outcomes of a completed event (published report only; figures only when staff-verified). */
function programhub_report_html(array $ev): string
{
    $report = programhub_event_report((int) $ev['id']);
    $n = programhub_verified_participants($ev);
    $outcomes = $report ? array_values(array_filter($report['outcomes_list'], function ($o) {
        return trim((string) ($o['label'] ?? '')) !== '' && trim((string) ($o['value'] ?? '')) !== '';
    })) : [];
    if (!$report && !$n) {
        return '';
    }
    $html = '';
    if ($n || $outcomes) {
        $html .= '<div class="ph-outcomes">';
        if ($n) {
            $html .= '<div class="ph-outcome"><strong>' . $n . '</strong><span>participant' . ($n === 1 ? '' : 's') . '</span><small>' . ($report && $report['participants_verified'] !== null && $report['verified_at'] ? 'verified by our team' : 'attendance recorded') . '</small></div>';
        }
        foreach ($outcomes as $o) {
            $html .= '<div class="ph-outcome"><strong>' . e((string) $o['value']) . '</strong><span>' . e((string) $o['label']) . '</span>'
                . (trim((string) ($o['note'] ?? '')) !== '' ? '<small>' . e((string) $o['note']) . '</small>' : '') . '</div>';
        }
        $html .= '</div>';
    }
    if ($report && trim(strip_tags((string) $report['summary'])) !== '') {
        $html .= '<div class="prose article-prose ph-report">' . clean_html((string) $report['summary']) . '</div>';
    }
    if ($report && !empty($report['verified_at'])) {
        $html .= '<p class="ph-verified">' . icon('check-circle') . '<span>Figures checked by our team on ' . e(date('d M Y', strtotime((string) $report['verified_at']))) . '.</span></p>';
    }
    return $html;
}

/** Event FAQ accordion; questions are added to the page's FAQPage data (skipping duplicates). */
function programhub_event_faqs_html(array $ev): string
{
    $faqs = array_values(array_filter(programhub_json_list($ev['faqs'] ?? null), function ($f) {
        return trim((string) ($f['q'] ?? '')) !== '' && trim((string) ($f['a'] ?? '')) !== '';
    }));
    if (!$faqs) {
        return '';
    }
    $seen = array_column($GLOBALS['seo_faqs'] ?? [], 'q');
    foreach ($faqs as $f) {
        if (!in_array($f['q'], $seen, true)) {
            $GLOBALS['seo_faqs'][] = ['q' => (string) $f['q'], 'a' => (string) $f['a']];
        }
    }
    return ui_faqs($faqs, true);
}

/** Other upcoming events: same category first, then other areas (no awareness days). */
function programhub_related_events(array $ev, int $limit = 3): array
{
    $rows = [];
    if (!empty($ev['category_id'])) {
        $rows = array_values(array_filter(programhub_events(['when' => 'upcoming', 'category_id' => (int) $ev['category_id'], 'limit' => $limit + 1, 'include_cancelled' => false]),
            function ($r) use ($ev) { return (int) $r['id'] !== (int) $ev['id']; }));
    }
    if (count($rows) < $limit) {
        $ids = array_merge([(int) $ev['id']], array_map('intval', array_column($rows, 'id')));
        foreach (programhub_events(['when' => 'upcoming', 'limit' => $limit + count($ids), 'include_cancelled' => false]) as $r) {
            if (!in_array((int) $r['id'], $ids, true)) {
                $rows[] = $r;
            }
        }
    }
    return array_slice($rows, 0, $limit);
}

// ----- Member area helpers (templates/member.php "My events") -----

/** A member's registrations: their own rows and, when the account e-mail is verified, rows made with that e-mail. Never selects the meeting link. */
function programhub_member_registrations(array $user): array
{
    $verified = !empty($user['email_verified_at']);
    try {
        return db_rows("SELECT er.id, er.event_id, er.status, er.created_at, er.is_minor, e.title, e.slug, e.event_type, e.mode, e.status AS event_status,
                               e.start_datetime, e.end_datetime, e.location, (COALESCE(e.meeting_url, '') <> '') AS has_meeting
                        FROM event_registrations er JOIN events e ON e.id = er.event_id
                        WHERE (er.user_id = ?" . ($verified ? ' OR er.email = ?' : '') . ") AND e.status IN ('published','cancelled','archived')
                        ORDER BY e.start_datetime DESC", $verified ? [(int) $user['id'], mb_strtolower((string) $user['email'])] : [(int) $user['id']]);
    } catch (Throwable $e) {
        error_log('programhub_member_registrations: ' . $e->getMessage());
        return [];
    }
}

/**
 * Cancel a member's own active registration (registered / confirmed / waitlisted) before the event starts.
 * A freed seat goes to the first person on the waiting list (who is emailed). Returns the cancelled row or null.
 */
function programhub_member_cancel(array $user, int $regId): ?array
{
    $verified = !empty($user['email_verified_at']);
    $reg = db_row("SELECT er.*, e.title AS event_title FROM event_registrations er JOIN events e ON e.id = er.event_id
                   WHERE er.id = ? AND (er.user_id = ?" . ($verified ? ' OR er.email = ?' : '') . ") AND er.status IN ('registered','confirmed','waitlisted')
                   AND e.start_datetime > NOW()", $verified ? [$regId, (int) $user['id'], mb_strtolower((string) $user['email'])] : [$regId, (int) $user['id']]);
    if (!$reg || !programhub_set_registration_status((int) $reg['id'], 'cancelled')) {
        return null;
    }
    if (in_array($reg['status'], EVENT_SEAT_STATUSES, true)) {
        $promoted = programhub_promote_waitlist((int) $reg['event_id']);
        if ($promoted) {
            $event = db_row('SELECT * FROM events WHERE id = ?', [(int) $reg['event_id']]);
            if ($event) {
                programhub_send_participant_email($event, $promoted, 'promoted', ['token' => programhub_issue_access_token((int) $promoted['id'])]);
            }
        }
    }
    return $reg;
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
    // values are listed here (not taken from EVENT_TYPES / EVENT_REG_STATUSES) so the migration never depends on other files
    programhub_widen_enum('events', 'event_type', ['event', 'awareness_day', 'drive', 'camp', 'webinar', 'workshop', 'seminar', 'training', 'bootcamp',
        'yoga_session', 'fitness_session', 'music_program', 'cultural_event', 'hackathon', 'innovation_challenge', 'awareness_campaign']);
    programhub_widen_enum('events', 'status', ['draft', 'published', 'cancelled', 'archived', 'scheduled']);
    programhub_widen_enum('event_registrations', 'status', ['registered', 'attended', 'cancelled', 'confirmed', 'waitlisted', 'no_show']);

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
        $row('partnership_enquiry', 'partner', 'Partner with us', 'Contact & partnership enquiry', 'Institutions, trainers, sponsors and community organisations can work with us on ' . $c['title'] . '.', null, null,
            [], [], 'white', 90),
        programhub_category_cta_row((string) $c['title'], (string) ($c['slug'] ?? '')),
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
// Migrations 630-659 (P1: public side). Values are hard-coded so they never depend on other files.
// =====================================================================

/** CTA block at the end of a category page (editable in Admin > Pages > Sections). */
function programhub_category_cta_row(string $title, string $slug): array
{
    return [
        'section_type' => 'cta', 'anchor' => 'take-part', 'pill' => null, 'title' => 'Learn, take part or help this programme grow',
        'intro' => 'Register for an open session, ask us to tell you about new dates, or partner with us to bring ' . $title . ' sessions to your school, college or community.',
        'body' => null, 'image' => null, 'items' => '[]',
        'settings' => json_encode(['buttons' => [
            ['label' => 'Register', 'url' => '#register', 'style' => 'primary', 'icon' => 'check'],
            ['label' => 'Upcoming events', 'url' => '/events/?category=' . $slug, 'style' => 'outline', 'icon' => 'calendar'],
            ['label' => 'Partner with us', 'url' => '#partner', 'style' => 'ghost', 'icon' => 'users'],
        ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'background' => 'tint', 'status' => 'visible', 'sort_order' => 100,
    ];
}

/** Dedicated illustrations for the 10 categories (banner + "About" image) - only where the seeded image is unchanged. */
function migration_630_programhub_category_art(): void
{
    $seeded = [
        'ai-artificial-intelligence' => 'assets/images/programs/digital-learning.svg',
        'robotics-technology' => 'assets/images/programs/youth-empowerment.svg',
        'machine-learning-data-science' => 'assets/images/programs/digital-learning.svg',
        'digital-marketing' => 'assets/images/programs/skill-development.svg',
        'cyber-security' => 'assets/images/programs/digital-learning.svg',
        'career-entrepreneurship' => 'assets/images/hero/career.svg',
        'fitness-yoga-wellness' => 'assets/images/programs/healthcare.svg',
        'music-arts-culture' => 'assets/images/hero/community.svg',
        'hackathons-innovation' => 'assets/images/programs/youth-empowerment.svg',
        'events-seminars' => 'assets/images/hero/events.svg',
    ];
    foreach ($seeded as $slug => $old) {
        $new = 'assets/images/programs/categories/' . $slug . '.svg';
        $pageId = (int) db_value('SELECT id FROM pages WHERE slug = ?', ['programs/' . $slug]);
        if (!$pageId || !is_file(ROOT_PATH . '/' . $new)) {
            continue;
        }
        db_query('UPDATE pages SET banner_image = ? WHERE id = ? AND banner_image = ?', [$new, $pageId, $old]);
        db_query("UPDATE page_sections SET image = ? WHERE page_id = ? AND section_type = 'split' AND anchor = 'about' AND image = ?", [$new, $pageId, $old]);
    }
    program_categories_all(false, true);
}

/** Programmes hub: "Upcoming sessions" strip under the category cards (hidden while nothing is scheduled). */
function migration_631_programhub_hub_strip(): void
{
    $hub = (int) db_value("SELECT id FROM pages WHERE slug = 'programs'");
    if (!$hub || (int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND section_type = 'program_upcoming_strip'", [$hub]) > 0) {
        return;
    }
    db_insert('page_sections', [
        'page_id' => $hub, 'section_type' => 'program_upcoming_strip', 'anchor' => 'coming-up', 'pill' => 'Coming up', 'title' => 'Upcoming sessions',
        'intro' => 'The next workshops, sessions and events you can join. Each one has its own page with full details and registration.',
        'items' => '[]', 'settings' => '{"limit":4,"align":"center"}', 'background' => 'white', 'status' => 'visible', 'sort_order' => 16,
    ]);
}

/** Category pages: closing call-to-action block (added once per page). */
function migration_632_programhub_category_cta(): void
{
    foreach (db_rows('SELECT slug, title, page_id FROM program_categories WHERE page_id IS NOT NULL') as $c) {
        if ((int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND section_type = 'cta'", [(int) $c['page_id']]) > 0) {
            continue;
        }
        db_insert('page_sections', programhub_category_cta_row((string) $c['title'], (string) $c['slug']) + ['page_id' => (int) $c['page_id']]);
    }
}

/** Seeded partnership intro read "... on Music, Arts & Cultural Programs programmes." - drop the doubled word (only while unchanged). */
function migration_633_programhub_partner_intro(): void
{
    foreach (db_rows('SELECT c.title, c.page_id FROM program_categories c WHERE c.page_id IS NOT NULL') as $c) {
        $start = 'Institutions, trainers, sponsors and community organisations can work with us on ' . $c['title'];
        db_query("UPDATE page_sections SET intro = ? WHERE page_id = ? AND section_type = 'partnership_enquiry' AND intro = ?",
            [$start . '.', (int) $c['page_id'], $start . ' programmes.']);
    }
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
