<?php
/**
 * Content module (M6): blog / news / press, success stories, events & awareness calendar,
 * photo & video gallery.
 *
 * Public templates: blog.php, stories.php, events.php, event_detail.php, calendar.php, gallery.php
 * Admin: blog(-edit), stories / story-edit, events / event-edit / event-registrations, gallery
 * Section types: events_list, posts_list, stories_list, gallery_strip, awareness_days
 *
 * Authenticity: every list here shows only real rows entered by staff. Stories need
 * consent_confirmed = 1; gallery items are published only with the consent confirmation.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

const BLOG_CATEGORIES = ['blog' => 'Blog', 'news' => 'News', 'press' => 'Press release'];

const EVENT_TYPES = [
    'event' => 'Event', 'drive' => 'Drive', 'camp' => 'Camp', 'webinar' => 'Webinar', 'awareness_day' => 'Awareness day',
    'workshop' => 'Workshop', 'seminar' => 'Seminar', 'training' => 'Training', 'bootcamp' => 'Bootcamp',
    'yoga_session' => 'Yoga session', 'fitness_session' => 'Fitness session', 'music_program' => 'Music programme',
    'cultural_event' => 'Cultural programme', 'hackathon' => 'Hackathon', 'innovation_challenge' => 'Innovation challenge',
    'awareness_campaign' => 'Awareness campaign',
];
/** Plural labels for filters and empty states. */
const EVENT_TYPE_PLURALS = [
    'event' => 'Community events', 'drive' => 'Drives', 'camp' => 'Camps', 'webinar' => 'Webinars', 'awareness_day' => 'Awareness days',
    'workshop' => 'Workshops', 'seminar' => 'Seminars', 'training' => 'Training sessions', 'bootcamp' => 'Bootcamps',
    'yoga_session' => 'Yoga sessions', 'fitness_session' => 'Fitness sessions', 'music_program' => 'Music programmes',
    'cultural_event' => 'Cultural programmes', 'hackathon' => 'Hackathons', 'innovation_challenge' => 'Innovation challenges',
    'awareness_campaign' => 'Awareness campaigns',
];
const EVENT_TYPE_ICONS = [
    'event' => 'calendar', 'drive' => 'megaphone', 'camp' => 'activity', 'webinar' => 'monitor', 'awareness_day' => 'flag',
    'workshop' => 'tool', 'seminar' => 'message', 'training' => 'graduation', 'bootcamp' => 'layers', 'yoga_session' => 'sun',
    'fitness_session' => 'activity', 'music_program' => 'play', 'cultural_event' => 'star', 'hackathon' => 'target',
    'innovation_challenge' => 'zap', 'awareness_campaign' => 'megaphone',
];
/** 'scheduled' = published automatically at events.publish_at (see programhub_publish_due()). */
const EVENT_STATUSES = ['draft', 'scheduled', 'published', 'cancelled', 'archived'];

const GALLERY_CATEGORIES = [
    'education' => 'Education', 'job-placement' => 'Job Placement', 'women-empowerment' => 'Women Empowerment',
    'health' => 'Health', 'rural-development' => 'Rural Development', 'waste-management' => 'Waste Management',
    'youth-empowerment' => 'Youth Empowerment', 'food-donation' => 'Food Donation', 'disaster-relief' => 'Disaster Relief',
    'events' => 'Events', 'general' => 'General',
];

const CONTENT_FALLBACK_IMAGE = 'assets/images/programs/general.svg';

// ---------------------------------------------------------------------
// Small shared helpers
// ---------------------------------------------------------------------

/** Estimated reading time in minutes (200 words per minute, at least 1). */
function post_reading_time(?string $html): int
{
    $text = trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8'));
    $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
    return max(1, (int) ceil($words / 200));
}

/** SQL condition for blog posts visitors may see. */
function post_visible_sql(string $alias = ''): string
{
    $a = $alias !== '' ? $alias . '.' : '';
    return "{$a}status = 'published' AND {$a}published_at IS NOT NULL AND {$a}published_at <= NOW()";
}

/** SQL condition for success stories visitors may see (consent is mandatory). */
function story_visible_sql(string $alias = ''): string
{
    $a = $alias !== '' ? $alias . '.' : '';
    return "{$a}status = 'published' AND {$a}consent_confirmed = 1 AND ({$a}published_at IS NULL OR {$a}published_at <= NOW())";
}

/** Gold date badge: day + month (+ year). */
function content_date_badge(string $datetime, bool $year = true): string
{
    $ts = strtotime($datetime) ?: time();
    return '<span class="date-badge"><strong>' . date('d', $ts) . '</strong><span>' . date('M', $ts) . '</span>'
        . ($year ? '<small>' . date('Y', $ts) . '</small>' : '') . '</span>';
}

/** Width/height of a local image (cached), [0, 0] if unknown. */
function content_image_size(?string $path): array
{
    static $cache = [];
    $path = (string) $path;
    if ($path === '' || strpos($path, '..') !== false) {
        return [0, 0];
    }
    if (!isset($cache[$path])) {
        $info = is_file(ROOT_PATH . '/' . $path) ? @getimagesize(ROOT_PATH . '/' . $path) : false;
        $cache[$path] = $info ? [(int) $info[0], (int) $info[1]] : [0, 0];
    }
    return $cache[$path];
}

/** Absolute URL of a stored image (for JSON-LD / Open Graph), '' when missing. */
function content_abs_image(?string $path): string
{
    if (!$path || strpos($path, '..') !== false || !is_file(ROOT_PATH . '/' . $path)) {
        return '';
    }
    return abs_url($path);
}

/** Add the media module script once (video facades, admin counters). */
function media_js(): void
{
    $src = asset('assets/js/modules/media.js');
    if (!in_array($src, $GLOBALS['extraScripts'] ?? [], true)) {
        $GLOBALS['extraScripts'][] = $src;
    }
}

/** Lightbox markup used by script.js ([data-lightbox] links). Returned once per page. */
function content_lightbox(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<div class="lightbox" id="lightbox" hidden role="dialog" aria-modal="true" aria-label="Photo viewer">'
        . '<button class="lightbox-close" type="button" aria-label="Close">' . icon('x') . '</button>'
        . '<button class="lightbox-prev" type="button" aria-label="Previous photo">' . icon('chevron-left') . '</button>'
        . '<figure><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="" width="1" height="1" decoding="async"><figcaption aria-live="polite"></figcaption></figure>'
        . '<button class="lightbox-next" type="button" aria-label="Next photo">' . icon('chevron-right') . '</button></div>';
}

/** Is the current visitor a crawler/bot (no view counting)? */
function content_is_bot(): bool
{
    return device_type() === 'bot';
}

/** Programmes for admin select boxes: [id => title]. */
function content_programs(): array
{
    $out = [];
    try {
        foreach (db_rows('SELECT id, title FROM programs ORDER BY sort_order, title') as $p) {
            $out[(int) $p['id']] = $p['title'];
        }
    } catch (Throwable $e) {
        error_log('Programme list failed: ' . $e->getMessage());
    }
    return $out;
}

/** A published programme (with its page slug for program_url()), or null. */
function content_program(?int $id): ?array
{
    if (!$id) {
        return null;
    }
    return db_row("SELECT p.id, p.slug, p.title, p.summary, p.icon, pg.slug AS page_slug FROM programs p
                   LEFT JOIN pages pg ON pg.id = p.page_id AND pg.status = 'published'
                   WHERE p.id = ? AND p.status = 'published'", [$id]);
}

/** "Part of our programme" box. */
function content_program_box(?int $programId, string $intro = 'This is part of our programme'): string
{
    $p = content_program($programId);
    if (!$p) {
        return '';
    }
    return '<a class="program-link-box" href="' . e(program_url($p)) . '"><span class="icon-circle">' . icon($p['icon'] ?: 'heart') . '</span>'
        . '<span><small>' . e($intro) . '</small><strong>' . e($p['title']) . '</strong></span>' . icon('arrow-right', 'tile-arrow') . '</a>';
}

/** schema.org Article for a post or story. */
function content_article_schema(array $item, string $canonical, ?string $author = null): array
{
    $published = $item['published_at'] ?: $item['created_at'];
    $schema = [
        '@context' => 'https://schema.org', '@type' => 'Article',
        'headline' => mb_substr((string) $item['title'], 0, 110),
        'description' => excerpt((string) ($item['excerpt'] ?? ''), 300),
        'datePublished' => date('c', strtotime((string) $published)),
        'dateModified' => date('c', strtotime((string) ($item['updated_at'] ?: $published))),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
        'url' => $canonical,
        'author' => $author ? ['@type' => 'Person', 'name' => $author] : ['@id' => abs_url('#organization')],
        'publisher' => ['@id' => abs_url('#organization')],
        'inLanguage' => 'en-IN',
    ];
    $img = content_abs_image($item['image'] ?? null);
    $schema['image'] = [$img !== '' ? $img : content_default_schema_image()];
    return $schema;
}

/** Fallback JSON-LD image: the 1200x630 sharing card when present, else the logo. */
function content_default_schema_image(): string
{
    return abs_url(is_file(ROOT_PATH . '/assets/images/og-default.jpg') ? 'assets/images/og-default.jpg' : 'assets/images/logo.png');
}

// ---------------------------------------------------------------------
// Admin helpers (called only from admin pages, after admin/includes/auth.php)
// ---------------------------------------------------------------------

/**
 * Delete an uploaded image that is no longer referenced by any content row (file + media library entry).
 * Files still used elsewhere (e.g. chosen again in another post or a page section) are kept.
 */
function content_delete_image(?string $path): void
{
    if (!$path || strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false) {
        return;
    }
    $refs = (int) db_value('SELECT (SELECT COUNT(*) FROM blog_posts WHERE image = ?) + (SELECT COUNT(*) FROM success_stories WHERE image = ?)
        + (SELECT COUNT(*) FROM events WHERE image = ?) + (SELECT COUNT(*) FROM gallery_items WHERE image_path = ?)
        + (SELECT COUNT(*) FROM page_sections WHERE image = ?) + (SELECT COUNT(*) FROM pages WHERE banner_image = ? OR og_image = ?)',
        [$path, $path, $path, $path, $path, $path, $path]);
    if ($refs > 0) {
        return;
    }
    delete_upload($path);
    try {
        db_query('DELETE FROM media WHERE path = ?', [$path]);
    } catch (Throwable $e) {
        error_log('Media row delete failed: ' . $e->getMessage());
    }
}

/**
 * JSON endpoint for the rich-text editor's "Image" button (POST ?upload=1 on blog-edit, story-edit,
 * event-edit). Stops the request.
 */
function content_editor_upload(string $folder, string $context): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_valid()) {
        echo json_encode(['error' => 'Your session expired. Please reload the page.']);
        exit;
    }
    $before = count($_SESSION['_flash'] ?? []);
    $path = admin_upload_image('image', $folder);
    $message = 'The image could not be uploaded.';
    if (count($_SESSION['_flash'] ?? []) > $before) {
        $last = array_pop($_SESSION['_flash']);
        $message = $last['message'] ?? $message;
    }
    if ($path) {
        admin_log('media_uploaded', 'Image uploaded from the editor (' . $context . '): ' . $path);
    }
    echo json_encode($path ? ['url' => url($path), 'path' => $path] : ['error' => $message]);
    exit;
}

/** Convert a datetime-local value / DB value for <input type="datetime-local">. */
function content_dt_input(?string $value): string
{
    if (!$value) {
        return '';
    }
    $ts = strtotime(str_replace('T', ' ', $value));
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

// ---------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------
/** Title without a trailing year (awareness days are titled "World Health Day 2027"). */
function event_short_title(array $ev): string
{
    $title = (string) $ev['title'];
    return ($ev['event_type'] ?? '') === 'awareness_day' ? (string) preg_replace('/\s(19|20)\d{2}$/', '', $title) : $title;
}

function event_type_label(?string $type): string
{
    return EVENT_TYPES[$type] ?? 'Event';
}

function event_type_plural(?string $type): string
{
    return EVENT_TYPE_PLURALS[$type] ?? 'Events';
}

function event_end_ts(array $ev): int
{
    return (int) strtotime((string) (($ev['end_datetime'] ?? null) ?: $ev['start_datetime']));
}

function event_is_past(array $ev): bool
{
    return event_end_ts($ev) < time();
}

/** Starts at midnight and ends at 23:59 (or has no end) - shown as an all-day date. */
function event_is_all_day(array $ev): bool
{
    $start = strtotime((string) $ev['start_datetime']);
    if (date('H:i', $start) !== '00:00') {
        return false;
    }
    if (empty($ev['end_datetime'])) {
        return true;
    }
    $end = strtotime((string) $ev['end_datetime']);
    return in_array(date('H:i', $end), ['23:59', '00:00'], true);
}

/** 'in_person' | 'online' | 'hybrid' (events.mode; older rows without it: guessed from the type / location). */
function event_mode(array $ev): string
{
    if (isset($ev['mode']) && in_array($ev['mode'], ['in_person', 'online', 'hybrid'], true)) {
        return (string) $ev['mode'];
    }
    return (($ev['event_type'] ?? '') === 'webinar' || preg_match('/\b(online|virtual|zoom|google meet|webinar)\b/i', (string) ($ev['location'] ?? ''))) ? 'online' : 'in_person';
}

/** Fully online (no venue). Hybrid events have a venue: use event_mode() to tell them apart. */
function event_is_online(array $ev): bool
{
    return event_mode($ev) === 'online';
}

/** Human readable date/time of an event. */
function event_when(array $ev, bool $long = false): string
{
    $start = strtotime((string) $ev['start_datetime']);
    $end = !empty($ev['end_datetime']) ? strtotime((string) $ev['end_datetime']) : null;
    $dayFmt = $long ? 'l, d F Y' : 'D, d M Y';
    if (event_is_all_day($ev)) {
        if ($end && date('Y-m-d', $end) !== date('Y-m-d', $start)) {
            return date('d M Y', $start) . ' – ' . date('d M Y', $end);
        }
        return date($dayFmt, $start);
    }
    if (!$end) {
        return date($dayFmt, $start) . ' · ' . date('h:i A', $start);
    }
    if (date('Y-m-d', $end) === date('Y-m-d', $start)) {
        return date($dayFmt, $start) . ' · ' . date('h:i A', $start) . ' – ' . date('h:i A', $end);
    }
    return date('d M Y, h:i A', $start) . ' – ' . date('d M Y, h:i A', $end);
}

/** Registrations that hold a place (registered, confirmed, attended, no-show - not cancelled or waitlisted). */
function event_registered_count(int $eventId): int
{
    return (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status IN ('registered','confirmed','attended','no_show')", [$eventId]);
}

/**
 * Registration state of an event (server-side; used by the event page, cards and programhub_register()):
 * ['registered', 'capacity', 'seats_left', 'is_past', 'started', 'is_full', 'open', 'reason',
 *  'deadline' (timestamp|null), 'deadline_passed', 'opens_at' (timestamp|null), 'not_yet_open',
 *  'waitlist_open' (full but the waiting list takes registrations), 'can_register' (open || waitlist_open)]
 * Pass `registered` in $ev to avoid a query.
 */
function event_state(array $ev): array
{
    $registered = isset($ev['registered']) ? (int) $ev['registered'] : event_registered_count((int) $ev['id']);
    $capacity = !empty($ev['capacity']) ? (int) $ev['capacity'] : null;
    $seatsLeft = $capacity !== null ? max(0, $capacity - $registered) : null;
    $now = time();
    $started = strtotime((string) $ev['start_datetime']) <= $now;
    $past = event_is_past($ev);
    $full = $capacity !== null && $seatsLeft <= 0;
    $deadline = !empty($ev['registration_deadline']) ? (int) strtotime((string) $ev['registration_deadline']) : null;
    $opensAt = !empty($ev['registration_opens_at']) ? (int) strtotime((string) $ev['registration_opens_at']) : null;
    $deadlinePassed = $deadline !== null && $deadline <= $now;
    $notYetOpen = $opensAt !== null && $opensAt > $now;
    $enabled = (int) ($ev['registration_enabled'] ?? 0) === 1;
    $base = ($ev['status'] ?? '') === 'published' && $enabled && !$started && !$deadlinePassed && !$notYetOpen;
    $open = $base && !$full;
    $waitlistOpen = $base && $full && !empty($ev['waitlist_enabled']);
    $reason = '';
    if (($ev['status'] ?? '') === 'cancelled') {
        $reason = 'This event has been cancelled. Registration is closed.';
    } elseif ($past) {
        $reason = 'This event has already taken place. Registration is closed.';
    } elseif ($started) {
        $reason = 'This event is under way. Registration has closed.';
    } elseif (!$enabled) {
        $reason = ($ev['event_type'] ?? '') === 'awareness_day' ? '' : 'Registration is not required for this event.';
    } elseif ($deadlinePassed) {
        $reason = 'The registration deadline (' . date('d M Y, h:i A', $deadline) . ') has passed. Registration is closed.';
    } elseif ($notYetOpen) {
        $reason = 'Registration opens on ' . date('d M Y, h:i A', $opensAt) . '.';
    } elseif ($full && !$waitlistOpen) {
        $reason = 'All places for this event have been taken. Registration is closed.';
    } elseif (($ev['status'] ?? '') !== 'published') {
        $reason = 'Registration opens when the event is published.';
    }
    return ['registered' => $registered, 'capacity' => $capacity, 'seats_left' => $seatsLeft, 'is_past' => $past,
        'started' => $started, 'is_full' => $full, 'open' => $open, 'reason' => $reason,
        'deadline' => $deadline, 'deadline_passed' => $deadlinePassed, 'opens_at' => $opensAt, 'not_yet_open' => $notYetOpen,
        'waitlist_open' => $waitlistOpen, 'can_register' => $open || $waitlistOpen];
}

/** Google Maps search link for a location text. */
function maps_search_url(string $location): string
{
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($location);
}

/** Upcoming published awareness days (events of type awareness_day). */
function awareness_days(int $limit = 6, ?string $from = null): array
{
    $limit = max(1, min(60, $limit));
    return db_rows("SELECT * FROM events WHERE event_type = 'awareness_day' AND status = 'published'
                    AND COALESCE(end_datetime, start_datetime) >= ? ORDER BY start_datetime, id LIMIT $limit",
        [$from ?? date('Y-m-d H:i:s')]);
}

/**
 * schema.org markup for an event detail page. Awareness days are national / international observances, not events
 * the foundation holds, so they are described as a WebPage (no Event, location, organizer or offers).
 */
function event_schema(array $ev, string $canonical): array
{
    if (($ev['event_type'] ?? '') === 'awareness_day') {
        return [
            '@context' => 'https://schema.org', '@type' => 'WebPage', '@id' => $canonical . '#webpage', 'url' => $canonical,
            'name' => $ev['title'], 'description' => excerpt((string) ($ev['summary'] ?: $ev['description']), 300),
            'inLanguage' => 'en-IN', 'about' => ['@type' => 'Thing', 'name' => $ev['title']],
            'publisher' => ['@id' => abs_url('#organization')],
        ];
    }
    // The location never contains the private meeting link: VirtualLocation points to the public event page.
    $mode = event_mode($ev);
    $place = ['@type' => 'Place', 'name' => $ev['location'], 'address' => ['@type' => 'PostalAddress', 'streetAddress' => trim((string) (($ev['venue_address'] ?? '') ?: $ev['location'])), 'addressCountry' => 'IN']];
    $virtual = ['@type' => 'VirtualLocation', 'url' => $canonical];
    $statusUrl = ['cancelled' => 'https://schema.org/EventCancelled'][$ev['status']] ?? 'https://schema.org/EventScheduled';
    $schema = [
        '@context' => 'https://schema.org', '@type' => in_array($ev['event_type'] ?? '', ['hackathon', 'innovation_challenge', 'workshop', 'training', 'bootcamp', 'seminar'], true) ? 'EducationEvent' : 'Event',
        'name' => $ev['title'],
        'description' => excerpt((string) (($ev['meta_description'] ?? '') ?: ($ev['summary'] ?: $ev['description'])), 300),
        'startDate' => date('c', strtotime((string) $ev['start_datetime'])),
        'eventStatus' => $statusUrl,
        'eventAttendanceMode' => ['online' => 'https://schema.org/OnlineEventAttendanceMode', 'hybrid' => 'https://schema.org/MixedEventAttendanceMode'][$mode] ?? 'https://schema.org/OfflineEventAttendanceMode',
        'location' => $mode === 'online' ? $virtual : ($mode === 'hybrid' ? [$place, $virtual] : $place),
        'url' => $canonical,
        'image' => [content_abs_image($ev['image'] ?? null) ?: content_default_schema_image()],
    ];
    if (!empty($ev['end_datetime'])) {
        $schema['endDate'] = date('c', strtotime((string) $ev['end_datetime']));
    }
    $schema['organizer'] = ['@type' => 'NGO', '@id' => abs_url('#organization'), 'name' => site_name(), 'url' => abs_url('')];
    if (function_exists('programhub_event_speakers') && !empty($ev['id'])) {
        $performers = [];
        foreach (programhub_event_speakers((int) $ev['id']) as $sp) {
            $performers[] = array_filter(['@type' => 'Person', 'name' => $sp['name'], 'jobTitle' => $sp['designation'] ?: null]);
        }
        if ($performers) {
            $schema['performer'] = $performers;
        }
    }
    $fee = isset($ev['fee_amount']) && $ev['fee_amount'] !== null ? (float) $ev['fee_amount'] : 0.0;
    if ((int) $ev['registration_enabled'] === 1 && $ev['status'] === 'published') {
        $state = event_state($ev);
        $schema['offers'] = ['@type' => 'Offer', 'price' => $fee > 0 ? number_format($fee, 2, '.', '') : '0', 'priceCurrency' => 'INR', 'url' => $canonical . '#register',
            'availability' => $state['is_full'] ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock'];
        if (!empty($state['opens_at'])) {
            $schema['offers']['validFrom'] = date('c', (int) $state['opens_at']);
        }
    }
    return $schema;
}

/** Escape text for an iCalendar property value. */
function ics_text(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
}

/** Fold an iCalendar content line at 75 octets (UTF-8 safe). */
function ics_fold(string $line): string
{
    $out = '';
    $first = true;
    while (strlen($line) > ($first ? 75 : 74)) {
        $chunk = mb_strcut($line, 0, $first ? 75 : 74, 'UTF-8');
        $out .= ($first ? '' : ' ') . $chunk . "\r\n";
        $line = substr($line, strlen($chunk));
        $first = false;
    }
    return $out . ($first ? '' : ' ') . $line . "\r\n";
}

/** .ics calendar file for an event. */
function event_ics(array $ev): string
{
    $canonical = event_abs_url($ev);
    $host = (string) (parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost');
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . ics_text(site_name()) . '//Events//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT', 'UID:event-' . (int) $ev['id'] . '@' . $host, 'DTSTAMP:' . gmdate('Ymd\THis\Z')];
    $start = strtotime((string) $ev['start_datetime']);
    if (event_is_all_day($ev)) {
        $endDay = !empty($ev['end_datetime']) ? strtotime((string) $ev['end_datetime']) : $start;
        $lines[] = 'DTSTART;VALUE=DATE:' . date('Ymd', $start);
        $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime('+1 day', strtotime(date('Y-m-d', $endDay))));
    } else {
        $end = !empty($ev['end_datetime']) ? strtotime((string) $ev['end_datetime']) : $start + 3600;
        $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $start);
        $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', $end);
    }
    $lines[] = 'SUMMARY:' . ics_text((string) $ev['title']);
    // never the meeting link: online participants get it by email / on their private joining page
    $online = ($ev['event_type'] ?? '') !== 'awareness_day' && event_mode($ev) !== 'in_person';
    $lines[] = 'DESCRIPTION:' . ics_text(trim((string) $ev['summary']) . "\n\n" . ($online ? "Joining details for the online session are sent to registered participants.\n" : '') . 'Details: ' . $canonical);
    $lines[] = 'LOCATION:' . ics_text(event_mode($ev) === 'online' ? 'Online' : trim((string) $ev['location'] . (trim((string) ($ev['venue_address'] ?? '')) !== '' ? ', ' . $ev['venue_address'] : '')));
    $lines[] = 'URL:' . $canonical;
    $lines[] = 'STATUS:' . ($ev['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED');
    if (($ev['event_type'] ?? '') !== 'awareness_day' && setting('contact_email') !== '') {
        $lines[] = 'ORGANIZER;CN=' . ics_text(site_name()) . ':mailto:' . setting('contact_email');
    }
    if (($ev['event_type'] ?? '') === 'awareness_day') {
        $lines[] = 'TRANSP:TRANSPARENT';
    }
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';
    $out = '';
    foreach ($lines as $line) {
        $out .= ics_fold($line);
    }
    return $out;
}

// ---------------------------------------------------------------------
// Cards
// ---------------------------------------------------------------------
function event_card(array $ev, int $i = 0): string
{
    $link = event_url($ev);
    $type = (string) ($ev['event_type'] ?? 'event');
    $fallback = $type === 'awareness_day' ? 'assets/images/hero/events.svg' : CONTENT_FALLBACK_IMAGE;
    $flag = '';
    if (($ev['status'] ?? '') === 'cancelled') {
        $flag = '<span class="ev-flag ev-flag-red">Cancelled</span>';
    } elseif (event_is_past($ev) && $type !== 'awareness_day') {
        $flag = '<span class="ev-flag">Past event</span>';
    }
    return '<article class="event-card ev-card reveal tilt" style="--d:' . ($i % 4) . '">'
        . '<a class="event-media" href="' . e($link) . '" tabindex="-1" aria-hidden="true"><img src="' . e(image_url($ev['image'] ?? null, $fallback)) . '"' . image_srcset_attr($ev['image'] ?? null, '(max-width: 600px) 100vw, (max-width: 1100px) 50vw, 400px') . ' alt="" loading="lazy" width="480" height="300"></a>'
        . content_date_badge((string) $ev['start_datetime']) . $flag
        . '<div class="event-body"><span class="ev-chip ev-type-' . e($type) . '">' . icon(EVENT_TYPE_ICONS[$type] ?? 'calendar') . e(event_type_label($type)) . '</span>'
        . '<h3><a href="' . e($link) . '">' . e($ev['title']) . '</a></h3>'
        . '<ul class="meta-list"><li>' . icon('clock') . '<span>' . e(event_when($ev)) . '</span></li>'
        . '<li>' . icon(event_is_online($ev) ? 'monitor' : 'map-pin') . '<span>' . e($ev['location'] ?? '') . '</span></li></ul>'
        . '<p>' . e(excerpt((string) ($ev['summary'] ?? ''), 130)) . '</p>'
        . '<a class="link-arrow" href="' . e($link) . '">' . ($type === 'awareness_day' ? 'Learn more' : 'View details') . icon('arrow-right') . '<span class="sr-only">: ' . e($ev['title']) . '</span></a></div></article>';
}

function blog_card(array $post, int $i = 0): string
{
    $link = post_url($post['slug']);
    $cat = (string) ($post['category'] ?? 'blog');
    $minutes = isset($post['content']) ? post_reading_time($post['content']) : 0;
    return '<article class="blog-card post-card reveal tilt" style="--d:' . ($i % 4) . '">'
        . '<a class="blog-media" href="' . e($link) . '" tabindex="-1" aria-hidden="true"><img src="' . e(image_url($post['image'] ?? null, CONTENT_FALLBACK_IMAGE)) . '"' . image_srcset_attr($post['image'] ?? null, '(max-width: 600px) 100vw, (max-width: 1100px) 50vw, 400px') . ' alt="" loading="lazy" width="480" height="300"></a>'
        . '<span class="post-chip post-chip-' . e($cat) . '">' . e(BLOG_CATEGORIES[$cat] ?? 'Blog') . '</span>'
        . '<div class="blog-body"><div class="post-meta"><span>' . icon('calendar') . '<time datetime="' . e(format_date($post['published_at'] ?? null, 'Y-m-d')) . '">' . e(format_date($post['published_at'] ?? null)) . '</time></span>'
        . ($minutes ? '<span>' . icon('clock') . $minutes . ' min read</span>' : '') . '</div>'
        . '<h3><a href="' . e($link) . '">' . e($post['title']) . '</a></h3>'
        . '<p>' . e(excerpt((string) ($post['excerpt'] ?? ''), 150)) . '</p>'
        . '<a class="link-arrow" href="' . e($link) . '">Read more' . icon('arrow-right') . '<span class="sr-only">: ' . e($post['title']) . '</span></a></div></article>';
}

/** Large featured card (first post on the list page). */
function blog_card_featured(array $post): string
{
    $link = post_url($post['slug']);
    $cat = (string) ($post['category'] ?? 'blog');
    return '<article class="post-featured reveal">'
        . '<a class="post-featured-media" href="' . e($link) . '" tabindex="-1" aria-hidden="true"><img src="' . e(image_url($post['image'] ?? null, CONTENT_FALLBACK_IMAGE)) . '"' . image_srcset_attr($post['image'] ?? null, '(max-width: 991px) 100vw, 720px') . ' alt="" width="720" height="450"></a>'
        . '<div class="post-featured-body"><div class="post-meta"><span class="post-chip post-chip-' . e($cat) . '">' . e(BLOG_CATEGORIES[$cat] ?? 'Blog') . '</span>'
        . '<span class="featured-label">' . icon('clock') . 'Latest</span></div>'
        . '<h2><a href="' . e($link) . '">' . e($post['title']) . '</a></h2>'
        . '<p class="lead">' . e(excerpt((string) $post['excerpt'], 220)) . '</p>'
        . '<div class="post-meta">' . (!empty($post['author_name']) ? '<span>' . icon('user') . e($post['author_name']) . '</span>' : '')
        . '<span>' . icon('calendar') . e(format_date($post['published_at'])) . '</span>'
        . '<span>' . icon('clock') . post_reading_time($post['content'] ?? '') . ' min read</span></div>'
        . '<a class="btn btn-primary" href="' . e($link) . '">Read the article' . icon('arrow-right') . '<span class="sr-only">: ' . e($post['title']) . '</span></a></div></article>';
}

function story_card(array $s, int $i = 0): string
{
    $link = story_url($s['slug']);
    $who = trim(implode(' · ', array_filter([(string) ($s['person_name'] ?? ''), (string) ($s['location'] ?? '')])));
    return '<article class="story-card reveal tilt" style="--d:' . ($i % 4) . '">'
        . '<a class="story-media" href="' . e($link) . '" tabindex="-1" aria-hidden="true"><img src="' . e(image_url($s['image'] ?? null, 'assets/images/hero/community.svg')) . '"' . image_srcset_attr($s['image'] ?? null, '(max-width: 600px) 100vw, (max-width: 1100px) 50vw, 400px') . ' alt="" loading="lazy" width="480" height="320"></a>'
        . '<div class="story-body"><span class="story-mark" aria-hidden="true">&ldquo;</span>'
        . '<h3><a href="' . e($link) . '">' . e($s['title']) . '</a></h3>'
        . '<p>' . e(excerpt((string) ($s['excerpt'] ?? ''), 150)) . '</p>'
        . ($who !== '' ? '<p class="story-person">' . icon('user') . '<span>' . e($who) . '</span></p>' : '')
        . '<a class="link-arrow" href="' . e($link) . '">Read the story' . icon('arrow-right') . '<span class="sr-only">: ' . e($s['title']) . '</span></a></div></article>';
}

/** Photo tile for the gallery (opens in the lightbox). '' when the file is missing. */
function gallery_item_html(array $g, int $i = 0): string
{
    $path = (string) ($g['image_path'] ?? '');
    if ($path === '' || strpos($path, '..') !== false || !is_file(ROOT_PATH . '/' . $path)) {
        return '';
    }
    [$w, $h] = content_image_size($path);
    $alt = trim((string) ($g['description'] ?? '')) !== '' ? (string) $g['description'] : (string) $g['title'];
    $caption = $g['title'] . (!empty($g['description']) && $g['description'] !== $g['title'] ? ' — ' . $g['description'] : '');
    $cat = (string) ($g['category'] ?? 'general');
    return '<a class="gallery-item m-item reveal" style="--d:' . ($i % 4) . '" href="' . e(url($path)) . '" data-lightbox data-category="' . e($cat) . '" data-caption="' . e($caption) . '">'
        . '<img src="' . e(url($path)) . '"' . image_srcset_attr($path, '(max-width: 767px) 50vw, (max-width: 1199px) 33vw, 300px') . ' alt="' . e($alt) . '" loading="lazy" decoding="async"' . ($w ? ' width="' . $w . '" height="' . $h . '"' : '') . '>'
        . '<span class="m-caption"><strong>' . e($g['title']) . '</strong><small>' . e(GALLERY_CATEGORIES[$cat] ?? humanize($cat)) . '</small></span>'
        . '<span class="m-zoom" aria-hidden="true">' . icon('search') . '</span></a>';
}

/**
 * YouTube video card with a privacy-friendly facade: only the thumbnail loads until the visitor
 * clicks play; media.js then swaps in the youtube-nocookie.com player. Without JS the link opens YouTube.
 */
function video_item_html(array $g, int $i = 0): string
{
    $id = youtube_id((string) ($g['video_url'] ?? ''));
    if ($id === '') {
        return '';
    }
    media_js();
    $cat = (string) ($g['category'] ?? 'general');
    return '<figure class="video-card reveal" style="--d:' . ($i % 4) . '" data-category="' . e($cat) . '">'
        . '<div class="video-facade"><a class="video-facade-btn" href="https://www.youtube.com/watch?v=' . e($id) . '" data-yt="' . e($id) . '" data-title="' . e($g['title']) . '" target="_blank" rel="noopener" aria-label="Play video: ' . e($g['title']) . '">'
        . '<img src="https://i.ytimg.com/vi/' . e($id) . '/hqdefault.jpg" alt="" loading="lazy" width="480" height="360" referrerpolicy="no-referrer">'
        . '<span class="video-play" aria-hidden="true"><svg viewBox="0 0 68 48" width="68" height="48" focusable="false"><path class="vp-bg" d="M66.5 7.7a8.5 8.5 0 0 0-6-6C55.3.3 34 .3 34 .3s-21.3 0-26.5 1.4a8.5 8.5 0 0 0-6 6C.1 13 .1 24 .1 24s0 11 1.4 16.3a8.5 8.5 0 0 0 6 6C12.7 47.7 34 47.7 34 47.7s21.3 0 26.5-1.4a8.5 8.5 0 0 0 6-6C67.9 35 67.9 24 67.9 24s0-11-1.4-16.3z"/><path d="M45 24 27 14v20z" fill="#fff"/></svg></span>'
        . '</a></div><figcaption><span class="video-cat">' . e(GALLERY_CATEGORIES[$cat] ?? humanize($cat)) . '</span><strong>' . e($g['title']) . '</strong>'
        . (!empty($g['description']) ? '<span>' . e($g['description']) . '</span>' : '') . '</figcaption></figure>';
}

/** Compact awareness-day row (calendar lists, section). */
function awareness_day_html(array $ev, int $i = 0, string $headingTag = 'h3'): string
{
    $link = event_url($ev);
    return '<li class="aw-item reveal" style="--d:' . ($i % 4) . '">' . content_date_badge((string) $ev['start_datetime'])
        . '<div class="aw-body"><' . $headingTag . '><a href="' . e($link) . '">' . e($ev['title']) . '</a></' . $headingTag . '>'
        . '<p>' . e(excerpt((string) $ev['summary'], 170)) . '</p>'
        . '<a class="link-arrow" href="' . e($link) . '">How to take part' . icon('arrow-right') . '<span class="sr-only">: ' . e($ev['title']) . '</span></a></div></li>';
}

// ---------------------------------------------------------------------
// Section types (CMS blocks usable on any page)
// ---------------------------------------------------------------------
function section_schema_events_list(): array
{
    return [
        'label' => 'Events list (upcoming or past)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => [
            'when' => ['select', 'Show', ['upcoming' => 'Upcoming events', 'past' => 'Past events']],
            'type' => ['select', 'Event type', ['' => 'All (except awareness days)', 'event' => 'Events', 'drive' => 'Drives', 'camp' => 'Camps', 'webinar' => 'Webinars', 'awareness_day' => 'Awareness days']],
            'limit' => ['number', 'How many (1-12)'],
            'hide_link' => ['checkbox', 'Hide the "All events" link'],
        ],
        'help' => 'Shows published events from Admin > Events. The section is hidden when there are none.',
    ];
}

function section_events_list(array $s, array $items, array $set, array $page): string
{
    $when = ($set['when'] ?? 'upcoming') === 'past' ? 'past' : 'upcoming';
    $type = (string) ($set['type'] ?? '');
    $limit = max(1, min(12, (int) ($set['limit'] ?? 3) ?: 3));
    $params = [date('Y-m-d H:i:s')];
    $where = "status = 'published' AND COALESCE(end_datetime, start_datetime) " . ($when === 'past' ? '<' : '>=') . ' ?';
    if (isset(EVENT_TYPES[$type])) {
        $where .= ' AND event_type = ?';
        $params[] = $type;
    } else {
        $where .= " AND event_type <> 'awareness_day'";
    }
    $rows = db_rows("SELECT * FROM events WHERE $where ORDER BY start_datetime " . ($when === 'past' ? 'DESC' : 'ASC') . ", id LIMIT $limit", $params);
    if (!$rows) {
        return '';
    }
    $html = '<div class="card-grid">';
    foreach ($rows as $i => $ev) {
        $html .= event_card($ev, $i);
    }
    $html .= '</div>';
    if (empty($set['hide_link'])) {
        $query = array_filter(['when' => $when === 'past' ? 'past' : '', 'type' => isset(EVENT_TYPES[$type]) ? $type : '']);
        $html .= '<div class="section-more"><a class="btn btn-outline" href="' . e(page_url('events', $query)) . '">' . ($when === 'past' ? 'See all past events' : 'See all upcoming events') . icon('arrow-right') . '</a></div>';
    }
    return $html;
}

function section_schema_posts_list(): array
{
    return [
        'label' => 'Latest articles / news / press releases',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => [
            'category' => ['select', 'Category', ['all' => 'All', 'blog' => 'Blog', 'news' => 'News', 'press' => 'Press releases']],
            'limit' => ['number', 'How many (1-12)'],
            'hide_link' => ['checkbox', 'Hide the "All articles" link'],
        ],
        'help' => 'Shows published posts from Admin > Blog. The section is hidden when there are none.',
    ];
}

function section_posts_list(array $s, array $items, array $set, array $page): string
{
    $cat = (string) ($set['category'] ?? 'all');
    $limit = max(1, min(12, (int) ($set['limit'] ?? 3) ?: 3));
    $params = [];
    $where = post_visible_sql();
    if (isset(BLOG_CATEGORIES[$cat])) {
        $where .= ' AND category = ?';
        $params[] = $cat;
    }
    $rows = db_rows("SELECT id, slug, title, category, excerpt, content, image, author_name, published_at FROM blog_posts WHERE $where ORDER BY published_at DESC, id DESC LIMIT $limit", $params);
    if (!$rows) {
        return '';
    }
    $html = '<div class="card-grid">';
    foreach ($rows as $i => $p) {
        $html .= blog_card($p, $i);
    }
    $html .= '</div>';
    if (empty($set['hide_link'])) {
        $target = $cat === 'press' ? page_url('media') : page_url('blog', $cat === 'news' ? ['category' => 'news'] : []);
        $html .= '<div class="section-more"><a class="btn btn-outline" href="' . e($target) . '">' . ($cat === 'press' ? 'All press releases' : 'Read all articles') . icon('arrow-right') . '</a></div>';
    }
    return $html;
}

function section_schema_stories_list(): array
{
    return [
        'label' => 'Success stories (with consent only)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => ['limit' => ['number', 'How many (1-12)'], 'hide_link' => ['checkbox', 'Hide the "All stories" link']],
        'help' => 'Only published stories whose consent has been confirmed are shown. The section is hidden when there are none.',
    ];
}

function section_stories_list(array $s, array $items, array $set, array $page): string
{
    $limit = max(1, min(12, (int) ($set['limit'] ?? 3) ?: 3));
    $rows = db_rows('SELECT id, slug, title, person_name, location, excerpt, image FROM success_stories WHERE ' . story_visible_sql() . " ORDER BY published_at DESC, id DESC LIMIT $limit");
    if (!$rows) {
        return '';
    }
    $html = '<div class="card-grid">';
    foreach ($rows as $i => $st) {
        $html .= story_card($st, $i);
    }
    $html .= '</div>';
    if (empty($set['hide_link']) && ($page['slug'] ?? '') !== 'success-stories') {
        $html .= '<div class="section-more"><a class="btn btn-outline" href="' . e(page_url('success-stories')) . '">All success stories' . icon('arrow-right') . '</a></div>';
    }
    return $html;
}

function section_schema_gallery_strip(): array
{
    return [
        'label' => 'Gallery strip (photos or videos)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => [
            'media' => ['select', 'Media', ['image' => 'Photos', 'video' => 'Videos']],
            'category' => ['select', 'Category', ['' => 'All categories'] + GALLERY_CATEGORIES],
            'limit' => ['number', 'How many (1-24)'],
            'hide_link' => ['checkbox', 'Hide the "View gallery" link'],
        ],
        'help' => 'Shows published gallery items. The section is hidden when there are none.',
    ];
}

function section_gallery_strip(array $s, array $items, array $set, array $page): string
{
    $media = ($set['media'] ?? 'image') === 'video' ? 'video' : 'image';
    $cat = (string) ($set['category'] ?? '');
    $limit = max(1, min(24, (int) ($set['limit'] ?? ($media === 'video' ? 3 : 8)) ?: 8));
    $params = [$media];
    $where = "media_type = ? AND status = 'published' AND consent_confirmed = 1";
    if (isset(GALLERY_CATEGORIES[$cat])) {
        $where .= ' AND category = ?';
        $params[] = $cat;
    }
    $rows = db_rows("SELECT * FROM gallery_items WHERE $where ORDER BY sort_order, id DESC LIMIT $limit", $params);
    $html = '';
    foreach ($rows as $i => $g) {
        $html .= $media === 'video' ? video_item_html($g, $i) : gallery_item_html($g, $i);
    }
    if ($html === '') {
        return '';
    }
    $html = $media === 'video' ? '<div class="video-grid">' . $html . '</div>' : '<div class="gallery-strip">' . $html . '</div>' . content_lightbox();
    if (empty($set['hide_link'])) {
        $slug = $media === 'video' ? 'video-gallery' : 'gallery';
        if (($page['slug'] ?? '') !== $slug) {
            $html .= '<div class="section-more"><a class="btn btn-outline" href="' . e(page_url($slug)) . '">' . ($media === 'video' ? 'Watch more videos' : 'View the full gallery') . icon('arrow-right') . '</a></div>';
        }
    }
    return $html;
}

function section_schema_awareness_days(): array
{
    return [
        'label' => 'Upcoming awareness days',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => ['limit' => ['number', 'How many (1-12)'], 'hide_link' => ['checkbox', 'Hide the "Awareness calendar" link']],
        'help' => 'Next awareness days from Admin > Events (type "Awareness day"). Hidden when there are none.',
    ];
}

function section_awareness_days(array $s, array $items, array $set, array $page): string
{
    $limit = max(1, min(12, (int) ($set['limit'] ?? 4) ?: 4));
    $rows = awareness_days($limit);
    if (!$rows) {
        return '';
    }
    $html = '<ul class="aw-list">';
    foreach ($rows as $i => $ev) {
        $html .= awareness_day_html($ev, $i);
    }
    $html .= '</ul>';
    if (empty($set['hide_link']) && ($page['slug'] ?? '') !== 'awareness-calendar') {
        $html .= '<div class="section-more"><a class="btn btn-outline" href="' . e(page_url('awareness-calendar')) . '">' . icon('calendar') . 'Open the awareness calendar</a></div>';
    }
    return $html;
}
