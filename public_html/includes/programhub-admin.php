<?php
/**
 * Programs & Events - admin helpers (owned by builder P2).
 *
 * Loaded from the bottom of includes/programhub.php on every request (public and admin), so keep it to
 * function / const definitions only - no output, no queries at include time. Functions that need the admin
 * session (admin_id(), admin_log(), admin_upload_image()) may only be CALLED from admin pages.
 * Name every function programhub_admin_*() to avoid clashes with P1's public functions.
 * See scratchpad v3/programs/CONTRACT.md.
 *
 * Admin pages using these helpers: admin/program-categories.php, program-category-edit.php, speakers.php,
 * partners.php, events.php, event-edit.php, event-registrations.php.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Category landing-page sections the category editor edits directly: anchor => [section type, default sort order]. */
const PROGRAMHUB_ADMIN_CONTENT_SECTIONS = [
    'about' => ['split', 10], 'topics' => ['features', 20], 'who-can-participate' => ['checklist', 50],
    'benefits' => ['features', 60], 'faqs' => ['faqs', 80],
];
/** Registration statuses an admin may set in bulk => past-tense label for messages. */
const PROGRAMHUB_ADMIN_BULK_STATUS = [
    'confirmed' => 'confirmed', 'attended' => 'marked as attended', 'no_show' => 'marked as no-show',
    'registered' => 'moved to registered', 'cancelled' => 'cancelled', 'waitlisted' => 'moved to the waiting list',
];

// =====================================================================
// Page assets & small helpers
// =====================================================================

/** Load the repeater / icon-picker script (admin-site.js) and this module's admin script on the current admin page. */
function programhub_admin_assets(): void
{
    $GLOBALS['adminExtraScripts'][] = asset('assets/js/modules/admin-site.js');
    $GLOBALS['adminExtraScripts'][] = asset('assets/js/modules/admin-programs.js');
}

/**
 * Rows posted by a repeater (site_repeater_html): drops removed / empty rows, trims and limits every value.
 * $fields: key => max length; keys listed in $multiline keep new lines. Returns a list (max $max rows).
 */
function programhub_admin_rows($raw, array $fields, int $max = 40, array $multiline = []): array
{
    $out = [];
    foreach (is_array($raw) ? $raw : [] as $row) {
        if (!is_array($row) || !empty($row['_remove'])) {
            continue;
        }
        $clean = [];
        $any = false;
        foreach ($fields as $key => $len) {
            $v = clean_string($row[$key] ?? '', in_array($key, $multiline, true));
            $v = mb_substr($v, 0, (int) $len);
            $clean[$key] = $v;
            $any = $any || $v !== '';
        }
        if ($any) {
            $out[] = $clean;
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

/** One value per line (textarea) -> list of trimmed, non-empty strings (bullets removed). */
function programhub_admin_lines(string $text, int $maxLen = 200, int $max = 20): array
{
    $out = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim((string) preg_replace('/^[\-\*•]\s*/u', '', clean_string($line)));
        if ($line !== '') {
            $out[] = mb_substr($line, 0, $maxLen);
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

/** JSON for a list column, or null when empty. */
function programhub_admin_json(array $list): ?string
{
    return $list ? json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}

/** A web address entered by staff: '' or a valid https:// URL. Sets $error otherwise. */
function programhub_admin_https(string $value, ?string &$error = null, bool $allowHttp = false): string
{
    $error = null;
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $ok = filter_var($value, FILTER_VALIDATE_URL) && preg_match($allowHttp ? '#^https?://[^\s<>"\']+$#i' : '#^https://[^\s<>"\']+$#i', $value);
    if (!$ok || strlen($value) > 500) {
        $error = $allowHttp ? 'must be a full web address starting with https:// (or http://).' : 'must be a full, secure web address starting with https://';
        return '';
    }
    return $value;
}

/**
 * Upload an image through admin_upload_image() and return [path|null, error|null] instead of leaving a flash message.
 */
function programhub_admin_upload_image(string $field, string $folder, ?string $alt = null): array
{
    $before = count($_SESSION['_flash'] ?? []);
    $path = admin_upload_image($field, $folder, $alt);
    $error = null;
    if (!$path && count($_SESSION['_flash'] ?? []) > $before) {
        $last = array_pop($_SESSION['_flash']);
        $error = (string) ($last['message'] ?? 'The image could not be uploaded.');
    }
    return [$path, $error];
}

/** Delete an uploaded file of this module when no row references it any more (images also leave the media library). */
function programhub_admin_delete_file(?string $path): void
{
    if (!$path || strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false) {
        return;
    }
    $refs = (int) db_value('SELECT (SELECT COUNT(*) FROM speakers WHERE photo = ?) + (SELECT COUNT(*) FROM partners WHERE logo = ?)
        + (SELECT COUNT(*) FROM program_categories WHERE image = ?) + (SELECT COUNT(*) FROM event_resources WHERE file_path = ?)',
        [$path, $path, $path, $path]);
    if ($refs > 0) {
        return;
    }
    if (strpos($path, 'uploads/documents/') === 0 || strpos($path, 'uploads/events/files/') === 0) {
        delete_upload($path);
        return;
    }
    content_delete_image($path);   // also checks posts, events, gallery, pages before deleting
}

/** Registration reference shown to staff and participants, e.g. PWF-EVR-2026-00012. */
function programhub_admin_reg_ref(array $reg): string
{
    $ts = !empty($reg['created_at']) ? strtotime((string) $reg['created_at']) : false;
    return 'PWF-EVR-' . date('Y', $ts ?: time()) . '-' . str_pad((string) (int) ($reg['id'] ?? 0), 5, '0', STR_PAD_LEFT);
}

/** Badge for a registration status. */
function programhub_admin_reg_badge(string $status): string
{
    $tones = ['registered' => 'green', 'confirmed' => 'green', 'waitlisted' => 'gold', 'attended' => 'blue', 'cancelled' => 'red', 'no_show' => 'gray'];
    return '<span class="badge badge-' . ($tones[$status] ?? 'gray') . '">' . e(EVENT_REG_STATUSES[$status] ?? humanize($status)) . '</span>';
}

/** [status => count] of an event's registrations (all six statuses, zero-filled). */
function programhub_admin_reg_counts(int $eventId): array
{
    $counts = array_fill_keys(array_keys(EVENT_REG_STATUSES), 0);
    foreach (db_rows('SELECT status, COUNT(*) AS n FROM event_registrations WHERE event_id = ? GROUP BY status', [$eventId]) as $r) {
        $counts[$r['status']] = (int) $r['n'];
    }
    return $counts;
}

/** Event select options for admin filters: [id => "title (date)"], newest first. */
function programhub_admin_event_options(bool $withRegistrationsOnly = false, int $limit = 400): array
{
    $limit = max(1, min(1000, $limit));
    $sql = "SELECT e.id, e.title, e.start_datetime FROM events e WHERE e.event_type <> 'awareness_day'"
        . ($withRegistrationsOnly ? ' AND EXISTS (SELECT 1 FROM event_registrations r WHERE r.event_id = e.id)' : '')
        . " ORDER BY e.start_datetime DESC, e.id DESC LIMIT $limit";
    $out = [];
    foreach (db_rows($sql) as $r) {
        $out[(int) $r['id']] = $r['title'] . ' (' . date('d M Y', strtotime((string) $r['start_datetime'])) . ')';
    }
    return $out;
}

// =====================================================================
// Programme categories
// =====================================================================

/** Event / interest counts per category: [id => ['upcoming', 'past', 'drafts', 'total', 'interests_new']]. */
function programhub_admin_category_counts(): array
{
    $out = [];
    $rows = db_rows("SELECT category_id,
                            SUM(status IN ('published','cancelled') AND COALESCE(end_datetime, start_datetime) >= NOW()) AS upcoming,
                            SUM(status IN ('published','cancelled') AND COALESCE(end_datetime, start_datetime) < NOW()) AS past,
                            SUM(status IN ('draft','scheduled')) AS drafts, COUNT(*) AS total
                     FROM events WHERE category_id IS NOT NULL GROUP BY category_id");
    foreach ($rows as $r) {
        $out[(int) $r['category_id']] = ['upcoming' => (int) $r['upcoming'], 'past' => (int) $r['past'], 'drafts' => (int) $r['drafts'], 'total' => (int) $r['total'], 'interests_new' => 0];
    }
    foreach (db_rows("SELECT category_id, COUNT(*) AS n FROM program_interests WHERE category_id IS NOT NULL AND status = 'new' GROUP BY category_id") as $r) {
        $id = (int) $r['category_id'];
        $out[$id] = ($out[$id] ?? ['upcoming' => 0, 'past' => 0, 'drafts' => 0, 'total' => 0]) + ['interests_new' => 0];
        $out[$id]['interests_new'] = (int) $r['n'];
    }
    return $out;
}

/** The directly edited sections of a category page: [anchor => page_sections row] (first row per anchor). */
function programhub_admin_category_sections(int $pageId): array
{
    $out = [];
    if ($pageId <= 0) {
        return $out;
    }
    $anchors = array_keys(PROGRAMHUB_ADMIN_CONTENT_SECTIONS);
    $rows = db_rows('SELECT * FROM page_sections WHERE page_id = ? AND anchor IN (' . implode(',', array_fill(0, count($anchors), '?')) . ') ORDER BY sort_order, id',
        array_merge([$pageId], $anchors));
    foreach ($rows as $r) {
        if (!isset($out[$r['anchor']])) {
            $out[$r['anchor']] = $r;
        }
    }
    return $out;
}

/**
 * Save one directly edited section of a category page (update the row with this anchor, or create it).
 * $data keys (all optional): title, intro, body, items (array).
 */
function programhub_admin_save_category_section(int $pageId, string $anchor, array $data, ?array $existing): void
{
    if (!isset(PROGRAMHUB_ADMIN_CONTENT_SECTIONS[$anchor])) {
        return;
    }
    [$type, $sort] = PROGRAMHUB_ADMIN_CONTENT_SECTIONS[$anchor];
    $row = [];
    foreach (['title', 'intro', 'body'] as $k) {
        if (array_key_exists($k, $data)) {
            $row[$k] = ($data[$k] === '' || $data[$k] === null) ? null : $data[$k];
        }
    }
    if (array_key_exists('items', $data)) {
        $row['items'] = json_encode(array_values($data['items']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($existing) {
        if ($row) {
            db_update('page_sections', $row, 'id = :id', ['id' => (int) $existing['id']]);
        }
        return;
    }
    $hasContent = !empty($data['items']) || trim((string) ($data['intro'] ?? '')) !== '' || trim((string) ($data['body'] ?? '')) !== '';
    if (!$hasContent) {
        return;   // nothing to show: do not create an empty block
    }
    $defaults = [
        'about' => ['pill' => 'About the programme', 'settings' => ['image_alt' => '', 'buttons' => []], 'background' => 'white'],
        'topics' => ['pill' => 'What we cover', 'settings' => ['cols' => '3', 'style' => 'soft'], 'background' => 'tint'],
        'who-can-participate' => ['pill' => 'Eligibility', 'settings' => ['cols' => '2'], 'background' => 'white'],
        'benefits' => ['pill' => 'Outcomes', 'settings' => ['cols' => '4', 'style' => 'card'], 'background' => 'tint'],
        'faqs' => ['pill' => 'FAQs', 'settings' => ['width' => 'narrow', 'align' => 'center', 'open_first' => true], 'background' => 'cream'],
    ][$anchor];
    db_insert('page_sections', $row + [
        'page_id' => $pageId, 'section_type' => $type, 'anchor' => $anchor, 'pill' => $defaults['pill'],
        'items' => '[]', 'settings' => json_encode((object) $defaults['settings'], JSON_UNESCAPED_SLASHES),
        'background' => $defaults['background'], 'status' => 'visible', 'sort_order' => $sort,
    ]);
}

/**
 * Card rows (icon, title, text) from a repeater, keeping a link / colour that an editor set in the full section
 * editor on a card with the same title.
 */
function programhub_admin_merge_cards(array $rows, array $oldItems): array
{
    $extra = [];
    foreach ($oldItems as $old) {
        if (is_array($old) && isset($old['title'])) {
            $extra[mb_strtolower(trim((string) $old['title']))] = array_intersect_key($old, array_flip(['link', 'link_label', 'tone']));
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $item = ['icon' => $r['icon'] !== '' && in_array($r['icon'], site_icon_names(), true) ? $r['icon'] : 'check-circle', 'title' => $r['title'], 'text' => $r['text']];
        $out[] = $item + ($extra[mb_strtolower(trim($r['title']))] ?? []);
    }
    return $out;
}

/** Validate a category slug. Returns an error message or null. Checks categories, pages and redirects. */
function programhub_admin_category_slug_problem(string $slug, ?array $cat): ?string
{
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 100) {
        return 'Use lower-case letters, numbers and single hyphens only (at most 100 characters).';
    }
    $id = $cat ? (int) $cat['id'] : 0;
    if ((int) db_value('SELECT COUNT(*) FROM program_categories WHERE slug = ? AND id <> ?', [$slug, $id]) > 0) {
        return 'Another category already uses this address.';
    }
    if ($cat && $slug === $cat['slug']) {
        return null;
    }
    $path = 'programs/' . $slug;
    if ((int) db_value('SELECT COUNT(*) FROM pages WHERE slug = ? AND id <> ?', [$path, $cat ? (int) $cat['page_id'] : 0]) > 0) {
        return 'A page with the address /' . $path . '/ already exists (for example a core programme). Choose another address.';
    }
    $redirect = db_row('SELECT target_url FROM redirects WHERE source_path IN (?, ?)', [$path, $path . '/']);
    if ($redirect) {
        // our own earlier address (old -> current) may be taken back; any other redirect must be removed first
        $current = $cat ? '/programs/' . $cat['slug'] . '/' : null;
        if ($current === null || rtrim((string) $redirect['target_url'], '/') !== rtrim($current, '/')) {
            return 'The address /' . $path . '/ is used by a redirect. Choose another address or remove the redirect first (Admin > Redirects).';
        }
    }
    return null;
}

/**
 * Move a category to a new slug: category + page slug, 301 from the old address, menu links / redirects / section
 * links that pointed to the old address. Returns notes for the flash message.
 */
function programhub_admin_change_category_slug(array $cat, string $newSlug): array
{
    $oldPath = 'programs/' . $cat['slug'];
    $newPath = 'programs/' . $newSlug;
    $oldUrl = '/' . $oldPath . '/';
    $newUrl = '/' . $newPath . '/';
    db_update('program_categories', ['slug' => $newSlug], 'id = :id', ['id' => (int) $cat['id']]);
    $notes = [];
    if (!empty($cat['page_id'])) {
        $page = db_row('SELECT id, slug FROM pages WHERE id = ?', [(int) $cat['page_id']]);
        if ($page && $page['slug'] === $oldPath) {
            db_update('pages', ['slug' => $newPath, 'updated_by' => admin_id()], 'id = :id', ['id' => (int) $page['id']]);
        }
        // the closing call-to-action links to /events/?category=<slug>
        db_query('UPDATE page_sections SET settings = REPLACE(settings, ?, ?), items = REPLACE(items, ?, ?) WHERE page_id = ?',
            ['category=' . $cat['slug'] . '"', 'category=' . $newSlug . '"', 'category=' . $cat['slug'] . '"', 'category=' . $newSlug . '"', (int) $cat['page_id']]);
    }
    db_query('DELETE FROM redirects WHERE source_path IN (?, ?)', [$newPath, $newPath . '/']);
    db_query('INSERT INTO redirects (source_path, target_url, status_code) VALUES (?, ?, 301)
              ON DUPLICATE KEY UPDATE target_url = VALUES(target_url), status_code = 301', [$oldPath, $newUrl]);
    db_query('UPDATE redirects SET target_url = ? WHERE target_url IN (?, ?)', [$newUrl, $oldUrl, '/' . $oldPath]);
    $menus = db_query('UPDATE menu_items SET url = ? WHERE url IN (?, ?)', [$newUrl, $oldUrl, '/' . $oldPath])->rowCount();
    $notes[] = 'The old address ' . $oldUrl . ' now redirects (301) to ' . $newUrl . ($menus ? ' and ' . $menus . ' menu link' . ($menus === 1 ? ' was' : 's were') . ' updated' : '') . '.';
    if ($cat['slug'] === 'hackathons-innovation') {
        $notes[] = 'Note: the "Hackathons" links of the website look for the category address /programs/hackathons-innovation/ and now fall back to the events list filtered to hackathons.';
    }
    admin_log('redirect_added', 'Automatic 301 redirect ' . $oldUrl . ' -> ' . $newUrl . ' after a programme category address change');
    return $notes;
}

/** Publish / hide a category and keep its landing page in step. Returns a note for the flash message. */
function programhub_admin_set_category_status(array $cat, string $status): string
{
    if (!in_array($status, ['published', 'draft'], true)) {
        return '';
    }
    db_update('program_categories', ['status' => $status], 'id = :id', ['id' => (int) $cat['id']]);
    $note = '';
    if (!empty($cat['page_id'])) {
        $page = db_row('SELECT id, status FROM pages WHERE id = ?', [(int) $cat['page_id']]);
        if ($page && $status === 'draft' && $page['status'] === 'published') {
            db_update('pages', ['status' => 'draft', 'updated_by' => admin_id()], 'id = :id', ['id' => (int) $page['id']]);
            $note = ' Its page was unpublished too.';
        } elseif ($page && $status === 'published' && $page['status'] !== 'published') {
            db_update('pages', ['status' => 'published', 'updated_by' => admin_id(), 'published_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $page['id']]);
            $note = ' Its page was published too.';
        }
    }
    program_categories_all(false, true);
    program_categories_all(true, true);
    return $note;
}

/**
 * Create a category: program_categories row + landing page (template program_category, child of /programs/) with
 * starter sections. Text comes only from what the editor entered plus the shared, generic FAQs. Returns the new id.
 * $c: slug, title, nav_label, card_text, icon, tone, image, default_event_type, status, sort_order, show_in_nav,
 *     interest_enabled, partnership_enabled, notify_email, subtitle, meta_title, meta_description.
 */
function programhub_admin_create_category(array $c): int
{
    $parentId = db_value("SELECT id FROM pages WHERE slug = 'programs'");
    $art = (string) ($c['image'] ?: 'assets/images/programs/general.svg');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pageId = db_insert('pages', [
            'parent_id' => $parentId ?: null, 'slug' => 'programs/' . $c['slug'], 'template' => 'program_category', 'title' => $c['title'],
            'banner_eyebrow' => 'Programs & Events', 'banner_subtitle' => $c['subtitle'] !== '' ? $c['subtitle'] : $c['card_text'], 'banner_image' => $art,
            'banner_buttons' => json_encode([
                ['label' => 'Register', 'url' => '#register', 'style' => 'donate', 'icon' => 'check'],
                ['label' => 'Explore events', 'url' => '#upcoming', 'style' => 'light', 'icon' => 'calendar'],
            ], JSON_UNESCAPED_SLASHES),
            'meta_title' => $c['meta_title'] !== '' ? $c['meta_title'] : null, 'meta_description' => $c['meta_description'] !== '' ? $c['meta_description'] : $c['card_text'],
            'status' => $c['status'] === 'published' ? 'published' : 'draft', 'is_system' => 1, 'show_in_sitemap' => 1, 'noindex' => 0,
            'sort_order' => 40 + (int) floor(((int) $c['sort_order']) / 10), 'published_at' => $c['status'] === 'published' ? date('Y-m-d H:i:s') : null,
            'created_by' => admin_id(), 'updated_by' => admin_id(),
        ]);
        $common = ['faqs' => []];
        $seedFile = ROOT_PATH . '/includes/upgrade/program-categories.php';
        if (is_file($seedFile)) {
            $seed = require $seedFile;
            if (is_array($seed['common']['faqs'] ?? null)) {
                $common['faqs'] = $seed['common']['faqs'];   // the shared, generic FAQs (fees, eligibility, certificates ...)
            }
        }
        $starter = [
            'title' => $c['title'], 'slug' => $c['slug'], 'about_title' => 'About ' . $c['title'], 'about_intro' => $c['card_text'], 'about_body' => '',
            'art' => $art, 'objectives' => [], 'topics' => [], 'who' => [], 'benefits' => [], 'faq' => null,
        ];
        foreach (programhub_category_sections($starter, $common) as $s) {
            db_insert('page_sections', $s + ['page_id' => $pageId]);
        }
        if (function_exists('programhub_category_cta_row') && !(int) db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND section_type = 'cta'", [$pageId])) {
            db_insert('page_sections', programhub_category_cta_row((string) $c['title'], (string) $c['slug']) + ['page_id' => $pageId]);
        }
        $id = db_insert('program_categories', [
            'slug' => $c['slug'], 'title' => $c['title'], 'nav_label' => $c['nav_label'] !== '' ? $c['nav_label'] : null, 'card_text' => $c['card_text'],
            'icon' => $c['icon'], 'tone' => $c['tone'], 'image' => $c['image'] ?: null, 'page_id' => $pageId,
            'default_event_type' => $c['default_event_type'] ?: null, 'interest_enabled' => (int) $c['interest_enabled'],
            'partnership_enabled' => (int) $c['partnership_enabled'], 'notify_email' => $c['notify_email'] !== '' ? $c['notify_email'] : null,
            'show_in_nav' => (int) $c['show_in_nav'], 'status' => $c['status'], 'sort_order' => (int) $c['sort_order'], 'created_by' => admin_id(),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    program_categories_all(false, true);
    program_categories_all(true, true);
    return $id;
}

// =====================================================================
// Events
// =====================================================================

/** Admin list SELECT: e.* + category title/slug, seats taken (`registered`) and `waitlisted`. */
function programhub_admin_event_select_sql(): string
{
    return 'SELECT e.*, c.title AS category_title, c.slug AS category_slug, c.icon AS category_icon,
                   (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status IN (' . programhub_seat_status_sql() . ')) AS registered,
                   (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = \'waitlisted\') AS waitlisted
            FROM events e LEFT JOIN program_categories c ON c.id = e.category_id';
}

/** Public path of an event without the base path ("events/<slug>" or "hackathons/<slug>"), for redirects. */
function programhub_admin_event_path(array $ev): string
{
    $prefix = in_array((string) ($ev['event_type'] ?? ''), HACKATHON_EVENT_TYPES, true) ? 'hackathons' : 'events';
    return $prefix . '/' . $ev['slug'];
}

/**
 * After the address of a public event changed (slug, or type between hackathon and other): 301 from the old address.
 * The new address must not be captured by an older redirect.
 */
function programhub_admin_event_moved(array $old, array $new): ?string
{
    $oldPath = programhub_admin_event_path($old);
    $newPath = programhub_admin_event_path($new);
    if ($oldPath === $newPath) {
        return null;
    }
    db_query('DELETE FROM redirects WHERE source_path IN (?, ?)', [$newPath, $newPath . '/']);
    if (!in_array((string) $old['status'], ['published', 'cancelled'], true) || $old['slug'] === $new['slug']) {
        return null;   // never public, or only the prefix changed (event_detail.php already 301s between /events/ and /hackathons/)
    }
    $target = '/' . $newPath . '/';
    db_query('INSERT INTO redirects (source_path, target_url, status_code) VALUES (?, ?, 301)
              ON DUPLICATE KEY UPDATE target_url = VALUES(target_url), status_code = 301', [$oldPath, $target]);
    db_query('UPDATE redirects SET target_url = ? WHERE target_url = ?', [$target, '/' . $oldPath . '/']);
    admin_log('redirect_added', 'Automatic 301 redirect /' . $oldPath . '/ -> ' . $target . ' after an event address change');
    return 'The old address /' . $oldPath . '/ now redirects to ' . $target . '.';
}

/** Replace the speakers or partners linked to an event. $rows: [['id', 'role', 'session_title'?], ...] in display order. */
function programhub_admin_save_links(int $eventId, string $kind, array $rows): void
{
    if ($kind === 'speakers') {
        db_query('DELETE FROM event_speakers WHERE event_id = ?', [$eventId]);
        $i = 0;
        foreach ($rows as $r) {
            db_query('INSERT IGNORE INTO event_speakers (event_id, speaker_id, role, session_title, sort_order) VALUES (?, ?, ?, ?, ?)',
                [$eventId, (int) $r['id'], $r['role'], $r['session_title'] !== '' ? $r['session_title'] : null, ++$i]);
        }
    } elseif ($kind === 'partners') {
        db_query('DELETE FROM event_partners WHERE event_id = ?', [$eventId]);
        $i = 0;
        foreach ($rows as $r) {
            db_query('INSERT IGNORE INTO event_partners (event_id, partner_id, role, sort_order) VALUES (?, ?, ?, ?)',
                [$eventId, (int) $r['id'], $r['role'], ++$i]);
        }
    }
}

/**
 * Duplicate an event as a draft: every field, its speakers and partners. Not copied: registrations, report, photos,
 * downloads, scheduled time, cancellation. Returns the new id.
 */
function programhub_admin_duplicate_event(int $id): int
{
    $ev = db_row('SELECT * FROM events WHERE id = ?', [$id]);
    if (!$ev) {
        return 0;
    }
    $row = $ev;
    foreach (['id', 'created_at', 'updated_at', 'publish_at', 'cancel_reason', 'cancelled_at'] as $k) {
        unset($row[$k]);
    }
    $title = mb_substr((string) $ev['title'], 0, 190) . ' (copy)';
    $row['title'] = mb_substr($title, 0, 200);
    $row['slug'] = unique_slug('events', $ev['slug'] . '-copy');
    $row['status'] = 'draft';
    $row['created_by'] = admin_id();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $newId = db_insert('events', $row);
        db_query('INSERT INTO event_speakers (event_id, speaker_id, role, session_title, sort_order)
                  SELECT ?, speaker_id, role, session_title, sort_order FROM event_speakers WHERE event_id = ?', [$newId, $id]);
        db_query('INSERT INTO event_partners (event_id, partner_id, role, sort_order)
                  SELECT ?, partner_id, role, sort_order FROM event_partners WHERE event_id = ?', [$newId, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $newId;
}

/** Delete an event with its downloads (files too), links, report and registrations (FK cascade). Returns the number of registrations removed. */
function programhub_admin_delete_event(array $event): int
{
    $id = (int) $event['id'];
    $regs = (int) db_value('SELECT COUNT(*) FROM event_registrations WHERE event_id = ?', [$id]);
    $files = db_rows('SELECT file_path FROM event_resources WHERE event_id = ? AND file_path IS NOT NULL', [$id]);
    db_query('DELETE FROM events WHERE id = ?', [$id]);
    foreach ($files as $f) {
        programhub_admin_delete_file($f['file_path']);
    }
    content_delete_image($event['image'] ?? null);
    programhub_refresh_publish_schedule();
    return $regs;
}

/**
 * Cancel an event with a reason; optionally email everyone holding a place or waiting (registered, confirmed, waitlisted).
 * Registrations are kept as they are (the record shows who had registered). Returns [emailed, recipients].
 */
function programhub_admin_cancel_event(array $event, string $reason, bool $email): array
{
    $set = ['status' => 'cancelled', 'publish_at' => null];
    if (column_exists('events', 'cancel_reason')) {
        $set['cancel_reason'] = $reason !== '' ? $reason : null;
        $set['cancelled_at'] = date('Y-m-d H:i:s');
    }
    db_update('events', $set, 'id = :id', ['id' => (int) $event['id']]);
    programhub_refresh_publish_schedule();
    $sent = 0;
    $recipients = [];
    if ($email) {
        $recipients = db_rows("SELECT * FROM event_registrations WHERE event_id = ? AND status IN ('registered','confirmed','waitlisted') ORDER BY id", [(int) $event['id']]);
        $fresh = db_row('SELECT * FROM events WHERE id = ?', [(int) $event['id']]) ?: $event;
        foreach ($recipients as $r) {
            if (programhub_send_participant_email($fresh, $r, 'cancelled', ['reason' => $reason])) {
                $sent++;
            }
        }
    }
    return [$sent, count($recipients)];
}

/** Move a resource file between the public and the private folder. Returns the new path (or the old one on failure). */
function programhub_admin_move_resource_file(string $path, bool $toPrivate): string
{
    $folder = $toPrivate ? PROGRAMHUB_UPLOADS['resource_private'] : PROGRAMHUB_UPLOADS['resource_public'];
    if ($path === '' || strpos($path, '..') !== false || strpos($path, 'uploads/') !== 0) {
        return $path;
    }
    if (strpos($path, 'uploads/' . $folder . '/') === 0) {
        return $path;
    }
    $dir = UPLOAD_PATH . '/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return $path;
    }
    $target = 'uploads/' . $folder . '/' . basename($path);
    if (@rename(ROOT_PATH . '/' . $path, ROOT_PATH . '/' . $target)) {
        return $target;
    }
    return $path;
}

/** Staff download of an event resource (private files through send_private_file()). Stops the request. */
function programhub_admin_send_resource(array $res): void
{
    $path = (string) ($res['file_path'] ?? '');
    $name = slugify((string) $res['title']);
    if ($path !== '' && strpos($path, 'uploads/documents/') === 0) {
        send_private_file($path, $name);
    }
    if ($path !== '' && strpos($path, 'uploads/') === 0 && strpos($path, '..') === false && is_file(ROOT_PATH . '/' . $path)) {
        redirect($path);
    }
    if (!empty($res['external_url'])) {
        header('Location: ' . $res['external_url'], true, 302);
        exit;
    }
    not_found();
}

// =====================================================================
// Registrations
// =====================================================================

/**
 * Apply a status to registrations (ids already limited to the right event / permission by the caller).
 * $opts: notify (email confirmed / promoted participants), promote (offer freed places to the waiting list).
 * Returns ['changed' => n, 'skipped' => n, 'emailed' => n, 'promoted' => [names], 'over' => int seats over capacity].
 */
function programhub_admin_bulk_status(array $regs, string $status, array $opts = []): array
{
    $res = ['changed' => 0, 'skipped' => 0, 'emailed' => 0, 'promoted' => [], 'over' => 0];
    if (!isset(PROGRAMHUB_ADMIN_BULK_STATUS[$status])) {
        return $res;
    }
    $events = [];
    $touched = [];
    foreach ($regs as $reg) {
        if ($reg['status'] === $status) {
            $res['skipped']++;
            continue;
        }
        if (!programhub_set_registration_status((int) $reg['id'], $status)) {
            $res['skipped']++;
            continue;
        }
        $res['changed']++;
        $eid = (int) $reg['event_id'];
        $touched[$eid] = true;
        if (!isset($events[$eid])) {
            $events[$eid] = db_row('SELECT * FROM events WHERE id = ?', [$eid]);
        }
        $ev = $events[$eid];
        if (!empty($opts['notify']) && $ev && in_array($status, ['confirmed', 'registered'], true)) {
            $kind = ($status === 'registered' && $reg['status'] === 'waitlisted') ? 'promoted' : 'confirmed';
            $extra = in_array(event_mode($ev), ['online', 'hybrid'], true) ? ['token' => programhub_issue_access_token((int) $reg['id'])] : [];
            if (programhub_send_participant_email($ev, ['status' => $status] + $reg, $kind, $extra)) {
                $res['emailed']++;
            }
        }
    }
    foreach (array_keys($touched) as $eid) {
        $ev = $events[$eid] ?? null;
        if (!$ev) {
            continue;
        }
        if ($status === 'cancelled' && !empty($opts['promote']) && !event_is_past($ev)) {
            for ($i = 0; $i < 200; $i++) {
                $p = programhub_promote_waitlist($eid);
                if (!$p) {
                    break;
                }
                $res['promoted'][] = $p['name'];
                $extra = in_array(event_mode($ev), ['online', 'hybrid'], true) ? ['token' => programhub_issue_access_token((int) $p['id'])] : [];
                if (programhub_send_participant_email($ev, $p, 'promoted', $extra)) {
                    $res['emailed']++;
                }
                admin_log('event_waitlist_promoted', 'Event #' . $eid . ' registration #' . (int) $p['id'] . ' moved from the waiting list after a cancellation');
            }
        }
        if (!empty($ev['capacity'])) {
            $res['over'] = max($res['over'], event_registered_count($eid) - (int) $ev['capacity']);
        }
    }
    return $res;
}

/**
 * Email the online joining details (kind 'meeting_link', with a fresh private-page token) to registrations that may
 * see them. meeting_link_sent_at is stamped only when the mail server accepted the email.
 * Returns ['sent' => n, 'failed' => n (mail not accepted), 'skipped' => n (status does not allow it), 'reason' => string|null].
 */
function programhub_admin_send_meeting_links(array $event, array $regs): array
{
    $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'reason' => null];
    if (!in_array(event_mode($event), ['online', 'hybrid'], true) || trim((string) ($event['meeting_url'] ?? '')) === '') {
        $out['reason'] = 'This event has no online joining link. Set the mode to Online or Hybrid and enter the private meeting link in the event first.';
        return $out;
    }
    if ($event['status'] !== 'published') {
        $out['reason'] = 'Joining links can only be sent for published events.';
        return $out;
    }
    foreach ($regs as $reg) {
        if (!in_array($reg['status'], EVENT_ACCESS_STATUSES, true)) {
            $out['skipped']++;
            continue;
        }
        $token = programhub_issue_access_token((int) $reg['id']);
        if (programhub_send_participant_email($event, $reg, 'meeting_link', ['token' => $token])) {
            db_query('UPDATE event_registrations SET meeting_link_sent_at = NOW() WHERE id = ?', [(int) $reg['id']]);
            $out['sent']++;
        } else {
            $out['failed']++;
        }
    }
    return $out;
}

/**
 * Participation certificates for attendees of an event whose certificate is officially confirmed.
 * Only attended registrations with an active, email-verified website account (by user id or email) get one (status
 * pending, approved later in Admin > Certificates). Returns ['issued' => [numbers], 'linked' => n, 'needs_account' => [names]].
 */
function programhub_admin_issue_certificates(array $event): array
{
    $out = ['issued' => [], 'linked' => 0, 'needs_account' => []];
    if ((int) ($event['certificate_confirmed'] ?? 0) !== 1 || !function_exists('certificate_number')) {
        return $out;
    }
    $regs = db_rows("SELECT * FROM event_registrations WHERE event_id = ? AND status = 'attended' AND certificate_id IS NULL ORDER BY name", [(int) $event['id']]);
    $end = date('Y-m-d', event_end_ts($event));
    $issueDate = min($end, date('Y-m-d'));
    $title = 'Certificate of Participation';
    $desc = mb_substr('For participating in "' . $event['title'] . '" (' . event_when($event) . ').', 0, 500);
    foreach ($regs as $r) {
        $user = null;
        if (!empty($r['user_id'])) {
            $user = db_row('SELECT id, status, email_verified_at FROM users WHERE id = ?', [(int) $r['user_id']]);
        }
        if (!$user || $user['status'] !== 'active' || empty($user['email_verified_at'])) {
            $user = db_row('SELECT id, status, email_verified_at FROM users WHERE email = ?', [$r['email']]);
        }
        if (!$user || $user['status'] !== 'active' || empty($user['email_verified_at'])) {
            $out['needs_account'][] = $r['name'];
            continue;
        }
        $existing = db_value("SELECT id FROM certificates WHERE event_id = ? AND user_id = ? AND certificate_type = 'participation' AND status <> 'revoked' LIMIT 1",
            [(int) $event['id'], (int) $user['id']]);
        if ($existing) {
            db_update('event_registrations', ['certificate_id' => (int) $existing], 'id = :id', ['id' => (int) $r['id']]);
            $out['linked']++;
            continue;
        }
        $certId = db_insert('certificates', [
            'certificate_no' => 'TMP-' . bin2hex(random_bytes(8)), 'user_id' => (int) $user['id'], 'recipient_name' => mb_substr((string) $r['name'], 0, 120),
            'certificate_type' => 'participation', 'title' => $title, 'description' => $desc, 'issue_date' => $issueDate,
            'status' => 'pending', 'issued_by' => admin_id(), 'event_id' => (int) $event['id'],
        ]);
        $no = certificate_number($certId, $issueDate);
        db_update('certificates', ['certificate_no' => $no], 'id = :id', ['id' => $certId]);
        db_update('event_registrations', ['certificate_id' => $certId], 'id = :id', ['id' => (int) $r['id']]);
        $out['issued'][] = $no;
    }
    return $out;
}

/** Answers JSON of a registration as "Question: answer; ..." (CSV / detail view). */
function programhub_admin_answers_text(?string $json): string
{
    $parts = [];
    foreach (json_field($json) as $k => $v) {
        if (is_scalar($v) && trim((string) $v) !== '') {
            $parts[] = humanize((string) $k) . ': ' . $v;
        }
    }
    return implode('; ', $parts);
}

// =====================================================================
// Migrations 660-689 (P2)
// =====================================================================

/** Cancellation reason shown with a cancelled event (optional; entered when staff cancel an event). */
function migration_660_programhub_event_cancel_reason(): void
{
    add_column_if_missing('events', 'cancel_reason', 'VARCHAR(300) DEFAULT NULL');
    add_column_if_missing('events', 'cancelled_at', 'DATETIME DEFAULT NULL');
}
