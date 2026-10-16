<?php
/**
 * SEO WP-6 — E-E-A-T: author, reviewer, dates and sources (display + structured data).
 *
 * Everything shown here comes from data an editor entered: team members (Admin > Team & Management) and the
 * per-entity fields in `seo_meta` (author_member_id, reviewer_member_id, reviewed_at, sources, content_updated_at).
 * No names, titles, credentials, qualifications, dates or affiliations are ever generated.
 *
 * Public HTML helpers (return HTML, escape everything, print nothing when there is nothing true to show):
 *   seo_author_box(), seo_review_line(), seo_dates_line(), seo_sources_list()
 * Structured data (schema.org):
 *   seo_team_member(), seo_person_node(), seo_team_graph(), seo_team_nodes(), seo_eeat_prepare(), seo_eeat_article()
 *   Nodes go to the page graph through WP-1's seo_graph_add() (guarded by function_exists). Call the graph helpers
 *   BEFORE page_header(): the graph is printed in <head>.
 * Admin helpers (called from editors, see the WP-6 report for the exact wiring):
 *   seo_eeat_admin_panel(), seo_eeat_validate_post(), seo_eeat_save(), seo_eeat_team_consent_field(),
 *   seo_eeat_team_row_extra(), seo_eeat_member_usage()
 *
 * Settings: seo_author_box (1/0), seo_show_reviewed (1/0) — migration 850.
 * PHP 7.4 compatible. No inline scripts. Styles: assets/css/modules/seo.css.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Entity types that can carry E-E-A-T data (same list as seo_meta in SEO-DESIGN 4.1). */
const SEO_EEAT_TYPES = ['page', 'post', 'story', 'event', 'campaign', 'scheme'];
const SEO_EEAT_MAX_SOURCES = 10;

// ---------------------------------------------------------------------
// Request cache
// ---------------------------------------------------------------------

/** Clear the per-request caches (tests, or after an admin save). */
function seo_eeat_cache_reset(): void
{
    $GLOBALS['seo_eeat_cache'] = ['meta' => [], 'member' => [], 'team_pages' => null, 'graph_ids' => [], 'columns' => null];
}

function seo_eeat_cache_init(): void
{
    if (!isset($GLOBALS['seo_eeat_cache']) || !is_array($GLOBALS['seo_eeat_cache'])) {
        seo_eeat_cache_reset();
    }
}

function seo_eeat_type_ok(string $type): bool
{
    return in_array($type, SEO_EEAT_TYPES, true);
}

// ---------------------------------------------------------------------
// Data access
// ---------------------------------------------------------------------

/** The seo_meta row of an entity ([] when there is none or the table does not exist yet). Cached per request. */
function seo_eeat_meta(string $type, int $id): array
{
    if (!seo_eeat_type_ok($type) || $id <= 0) {
        return [];
    }
    seo_eeat_cache_init();
    $key = $type . ':' . $id;
    if (!array_key_exists($key, $GLOBALS['seo_eeat_cache']['meta'])) {
        try {
            $row = db_row('SELECT * FROM seo_meta WHERE entity_type = ? AND entity_id = ?', [$type, $id]);
        } catch (Throwable $e) {
            $row = null;   // table missing (WP-1 not installed yet): behave as "nothing entered"
        }
        $GLOBALS['seo_eeat_cache']['meta'][$key] = is_array($row) ? $row : [];
    }
    return $GLOBALS['seo_eeat_cache']['meta'][$key];
}

/** Columns of seo_meta ([] when the table does not exist). */
function seo_eeat_meta_columns(): array
{
    seo_eeat_cache_init();
    if ($GLOBALS['seo_eeat_cache']['columns'] === null) {
        $cols = [];
        try {
            foreach (db_rows('SHOW COLUMNS FROM seo_meta') as $c) {
                $cols[] = (string) $c['Field'];
            }
        } catch (Throwable $e) {
            $cols = [];
        }
        $GLOBALS['seo_eeat_cache']['columns'] = $cols;
    }
    return $GLOBALS['seo_eeat_cache']['columns'];
}

/** A PUBLISHED team member (null when missing or hidden). Cached per request. */
function seo_team_member(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    seo_eeat_cache_init();
    if (!array_key_exists($id, $GLOBALS['seo_eeat_cache']['member'])) {
        try {
            $row = db_row("SELECT * FROM team_members WHERE id = ? AND status = 'published'", [$id]);
        } catch (Throwable $e) {
            $row = null;
        }
        $GLOBALS['seo_eeat_cache']['member'][$id] = (is_array($row) && trim((string) ($row['name'] ?? '')) !== '') ? $row : null;
    }
    return $GLOBALS['seo_eeat_cache']['member'][$id];
}

/**
 * Which published team page lists each member group: ['management' => 'management-body', 'advisor' => ..., ...].
 * Same group rules as templates/team.php ("management" also lists advisors; "team" also lists volunteers).
 */
function seo_eeat_team_pages(): array
{
    seo_eeat_cache_init();
    if (is_array($GLOBALS['seo_eeat_cache']['team_pages'])) {
        return $GLOBALS['seo_eeat_cache']['team_pages'];
    }
    $map = [];
    try {
        $rows = db_rows("SELECT slug, settings FROM pages WHERE template = 'team' AND status = 'published' ORDER BY sort_order, id");
    } catch (Throwable $e) {
        $rows = [];
    }
    foreach ($rows as $p) {
        $groups = page_setting($p, 'groups');
        if (!is_array($groups) || !$groups) {
            $g = (string) page_setting($p, 'group', 'team');
            $groups = $g === 'management' ? ['management', 'advisor'] : ($g === 'team' ? ['team', 'volunteer'] : [$g]);
        }
        foreach ($groups as $g) {
            $g = (string) $g;
            if ($g !== '' && !isset($map[$g])) {
                $map[$g] = (string) $p['slug'];
            }
        }
    }
    return $GLOBALS['seo_eeat_cache']['team_pages'] = $map;
}

/** Link to the member's card on the team page (/management-body/#member-5), '' when no published team page lists the group. */
function seo_eeat_member_url(array $member, bool $absolute = false): string
{
    $id = (int) ($member['id'] ?? 0);
    $slug = seo_eeat_team_pages()[(string) ($member['member_group'] ?? '')] ?? '';
    if ($id <= 0 || $slug === '') {
        return '';
    }
    return ($absolute ? abs_page_url($slug) : page_url($slug)) . '#member-' . $id;
}

/** Stable Person @id: {team page}#member-{id} (site root when no team page lists the member's group). */
function seo_eeat_person_id(array $member): string
{
    $url = seo_eeat_member_url($member, true);
    return $url !== '' ? $url : abs_url('#member-' . (int) ($member['id'] ?? 0));
}

function seo_eeat_org_id(): string
{
    return abs_url('#organization');
}

/** Single-line plain text for JSON-LD and labels (control characters removed, whitespace collapsed, length capped). */
function seo_eeat_text($value, int $max = 200): string
{
    $text = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', (string) $value));
    return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
}

/** Photo path of a member, only when the photo exists, is a raster upload AND photo consent is recorded. */
function seo_eeat_member_photo(array $member): string
{
    if ((int) ($member['photo_consent'] ?? 0) !== 1) {
        return '';
    }
    $path = (string) ($member['photo'] ?? '');
    if (!preg_match('#^uploads/[A-Za-z0-9_\-/.]+\.(jpe?g|png|webp)$#i', $path) || strpos($path, '..') !== false) {
        return '';
    }
    return is_file(ROOT_PATH . '/' . $path) ? $path : '';
}

/** Profiles entered by the admin that may be used as sameAs (https LinkedIn only, as validated by the team module). */
function seo_eeat_profile_links(array $member): array
{
    $raw = trim((string) ($member['linkedin_url'] ?? ''));
    $li = function_exists('info_linkedin_url')
        ? info_linkedin_url($raw)
        : (preg_match('#^https://([a-z]{2,3}\.)?(www\.)?linkedin\.com/[^\s<>"]*$#i', $raw) ? $raw : '');
    return ($li !== '' && stripos($li, 'https://') === 0) ? [$li] : [];
}

function seo_eeat_initials(string $name): string
{
    if (function_exists('info_initials')) {
        return info_initials($name);
    }
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** Unix time of a date/datetime string, null for empty, zero or invalid values. */
function seo_eeat_ts(?string $date): ?int
{
    $date = trim((string) $date);
    if ($date === '' || strpos($date, '0000-00-00') === 0) {
        return null;
    }
    $ts = strtotime($date);
    return $ts ? $ts : null;
}

/** A Y-m-d date that is valid and not in the future, else null. */
function seo_eeat_valid_date(?string $date): ?string
{
    $date = substr(trim((string) $date), 0, 10);
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || $date < '2000-01-01' || $date > date('Y-m-d')) {
        return null;
    }
    return $date;
}

/** Published team member chosen as the author of an entity, or null. */
function seo_eeat_author(string $type, int $id): ?array
{
    $mid = (int) (seo_eeat_meta($type, $id)['author_member_id'] ?? 0);
    return $mid > 0 ? seo_team_member($mid) : null;
}

/**
 * The review of an entity: ['member' => row, 'date' => 'Y-m-d'] only when BOTH a published reviewer and a valid,
 * non-future review date are set and the setting seo_show_reviewed is on. Otherwise null.
 */
function seo_eeat_review(string $type, int $id): ?array
{
    if (setting('seo_show_reviewed', '1') === '0') {
        return null;
    }
    $meta = seo_eeat_meta($type, $id);
    $member = seo_team_member((int) ($meta['reviewer_member_id'] ?? 0));
    $date = seo_eeat_valid_date($meta['reviewed_at'] ?? null);
    return ($member && $date) ? ['member' => $member, 'date' => $date] : null;
}

/** Name to show in a byline: linked team member → free-text author name → the foundation. */
function seo_eeat_author_name(string $type, int $id, ?string $fallbackName = null): string
{
    $m = seo_eeat_author($type, $id);
    if ($m) {
        return seo_eeat_text($m['name'], 120);
    }
    $fallbackName = seo_eeat_text($fallbackName, 120);
    return $fallbackName !== '' ? $fallbackName : site_name();
}

/**
 * Accurate dates of an entity: ['published' => ?string, 'updated' => ?string].
 * "updated" is ONLY the substantive-update date an editor recorded (seo_meta.content_updated_at). Row `updated_at`
 * columns are not used: they change on every save, status toggle and (blog_posts) on every view-counter increment,
 * so they do not say when the content changed.
 */
function seo_eeat_content_dates(string $type, int $id, array $row = []): array
{
    $published = (string) ($row['published_at'] ?? '');
    if (seo_eeat_ts($published) === null && $type !== 'page') {
        $published = (string) ($row['created_at'] ?? '');
    }
    $updated = (string) (seo_eeat_meta($type, $id)['content_updated_at'] ?? '');
    return [
        'published' => seo_eeat_ts($published) !== null ? $published : null,
        'updated'   => seo_eeat_ts($updated) !== null ? $updated : null,
    ];
}

/** Validate one source URL: absolute https, valid host with a dot, no credentials, max 500 characters. '' when invalid. */
function seo_eeat_clean_source_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 500 || !preg_match('#^https://[^\s<>"\'\\\\`]+$#i', $url) || preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return '';
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || strpos($host, '.') === false || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    // same rules as editor links elsewhere in the CMS (site_clean_link truncates at 255, so only compare shorter ones)
    if (function_exists('site_clean_link') && strlen($url) <= 255) {
        $err = null;
        if (site_clean_link($url, $err) !== $url || $err !== null) {
            return '';
        }
    }
    return $url;
}

/** Host shown next to a source link ("www.example.gov.in" → "example.gov.in"). */
function seo_eeat_source_host(string $url): string
{
    return (string) preg_replace('/^www\./i', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
}

/** Sources of an entity: [['title' => ..., 'url' => 'https://...'], ...], max 10, invalid rows dropped. */
function seo_eeat_sources(string $type, int $id): array
{
    $raw = seo_eeat_meta($type, $id)['sources'] ?? null;
    $list = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($list as $s) {
        if (!is_array($s)) {
            continue;
        }
        $url = seo_eeat_clean_source_url((string) ($s['url'] ?? ''));
        if ($url === '' || isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $title = seo_eeat_text($s['title'] ?? '', 200);
        $out[] = ['title' => $title !== '' ? $title : seo_eeat_source_host($url), 'url' => $url];
        if (count($out) >= SEO_EEAT_MAX_SOURCES) {
            break;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------
// Public HTML
// ---------------------------------------------------------------------

/**
 * Author box for posts and stories (optionally schemes/pages). Shows, in this order of preference:
 *  1. the team member linked as author: photo (only with recorded consent) or initials, name, designation,
 *     bio (max 300 characters) and "More about our team" (anchor on the team page);
 *  2. the free-text author name ($fallbackName) — name only, nothing added;
 *  3. the foundation as publisher.
 * Returns '' when the setting seo_author_box is off. $opts: label (default "Written by").
 */
function seo_author_box(string $type, int $id, ?string $fallbackName = null, array $opts = []): string
{
    if (setting('seo_author_box', '1') === '0') {
        return '';
    }
    $label = seo_eeat_text($opts['label'] ?? 'Written by', 40);
    $m = seo_eeat_author($type, $id);

    if ($m) {
        $name = seo_eeat_text($m['name'], 120);
        $role = seo_eeat_text($m['designation'] ?? '', 150);
        $photo = seo_eeat_member_photo($m);
        $url = seo_eeat_member_url($m);
        $bio = trim((string) ($m['bio'] ?? ''));
        $media = $photo !== ''
            ? '<img class="seo-author-photo" src="' . e(url($photo)) . '" alt="" width="64" height="64" loading="lazy" decoding="async">'
            : '<span class="avatar seo-author-avatar" aria-hidden="true">' . e(seo_eeat_initials($name)) . '</span>';
        return '<aside class="seo-author" aria-label="' . e('About the author: ' . $name) . '">'
            . '<div class="seo-author-media">' . $media . '</div>'
            . '<div class="seo-author-body">'
            . '<p class="seo-author-label">' . e($label) . '</p>'
            . '<p class="seo-author-name"><strong>' . e($name) . '</strong>'
            . ($role !== '' ? '<span class="seo-author-role">' . e($role) . '</span>' : '') . '</p>'
            . ($bio !== '' ? '<p class="seo-author-bio">' . e(excerpt($bio, 300)) . '</p>' : '')
            . ($url !== '' ? '<a class="seo-author-more" href="' . e($url) . '">More about our team' . icon('arrow-right') . '</a>' : '')
            . '</div></aside>';
    }

    $fallbackName = seo_eeat_text($fallbackName, 120);
    if ($fallbackName !== '' && $fallbackName !== site_name()) {
        return '<aside class="seo-author" aria-label="' . e('About the author: ' . $fallbackName) . '">'
            . '<div class="seo-author-media"><span class="avatar seo-author-avatar" aria-hidden="true">' . e(seo_eeat_initials($fallbackName)) . '</span></div>'
            . '<div class="seo-author-body"><p class="seo-author-label">' . e($label) . '</p>'
            . '<p class="seo-author-name"><strong>' . e($fallbackName) . '</strong></p></div></aside>';
    }

    $org = site_name();
    $about = function_exists('get_page') ? get_page('about') : null;
    return '<aside class="seo-author seo-author-org" aria-label="' . e('About the publisher: ' . $org) . '">'
        . '<div class="seo-author-media"><img class="seo-author-photo seo-author-logo" src="' . e(site_logo()) . '" alt="" width="64" height="64" loading="lazy" decoding="async"></div>'
        . '<div class="seo-author-body"><p class="seo-author-label">Published by</p>'
        . '<p class="seo-author-name"><strong>' . e($org) . '</strong></p>'
        . ($about ? '<a class="seo-author-more" href="' . e(page_url('about')) . '">About the foundation' . icon('arrow-right') . '</a>' : '')
        . '</div></aside>';
}

/** "Reviewed by {name}, {designation} on {date}" — only when a published reviewer AND a review date are set. */
function seo_review_line(string $type, int $id): string
{
    $r = seo_eeat_review($type, $id);
    if (!$r) {
        return '';
    }
    $m = $r['member'];
    $name = seo_eeat_text($m['name'], 120);
    $role = seo_eeat_text($m['designation'] ?? '', 150);
    $url = seo_eeat_member_url($m);
    return '<p class="seo-review">' . icon('check-circle') . '<span>Reviewed by '
        . ($url !== '' ? '<a href="' . e($url) . '">' . e($name) . '</a>' : '<strong>' . e($name) . '</strong>')
        . ($role !== '' ? ', ' . e($role) : '')
        . ' on <time datetime="' . e($r['date']) . '">' . e(format_date($r['date'], 'd F Y')) . '</time></span></p>';
}

/**
 * "Published {date} · Updated {date}" with <time datetime>. "Updated" is shown only when it is more than one day
 * after the published date (or there is no published date) and not in the future. Pass the dates from
 * seo_eeat_content_dates() so "updated" means a substantive edit.
 * $opts: wrap 'p' (default) | 'span' | 'none'; show_published (true); published_label ('Published');
 *        updated_label ('Updated'); class (extra class on the wrapper); icons (true).
 */
function seo_dates_line(?string $published, ?string $updated, array $opts = []): string
{
    $pTs = seo_eeat_ts($published);
    $uTs = seo_eeat_ts($updated);
    $icons = $opts['icons'] ?? true;
    $parts = [];
    if ($pTs && ($opts['show_published'] ?? true)) {
        $parts[] = '<span class="seo-date seo-date-published">' . ($icons ? icon('calendar') : '')
            . e(seo_eeat_text($opts['published_label'] ?? 'Published', 40)) . ' <time datetime="' . e(date('Y-m-d', $pTs)) . '">' . e(date('d F Y', $pTs)) . '</time></span>';
    }
    if ($uTs && $uTs <= time() + 86400 && (!$pTs || $uTs > $pTs + 86400)) {
        $parts[] = '<span class="seo-date seo-date-updated">' . ($icons ? icon('refresh') : '')
            . e(seo_eeat_text($opts['updated_label'] ?? 'Updated', 40)) . ' <time datetime="' . e(date('Y-m-d', $uTs)) . '">' . e(date('d F Y', $uTs)) . '</time></span>';
    }
    if (!$parts) {
        return '';
    }
    $wrap = $opts['wrap'] ?? 'p';
    if ($wrap === 'none') {
        return implode('', $parts);
    }
    $tag = $wrap === 'span' ? 'span' : 'p';
    $class = trim('seo-dates ' . preg_replace('/[^a-z0-9_\- ]/i', '', (string) ($opts['class'] ?? '')));
    return '<' . $tag . ' class="' . e($class) . '">' . implode('', $parts) . '</' . $tag . '>';
}

/**
 * "Sources" list of the admin-entered sources (https only; open in a new tab with rel="noopener").
 * $opts: title ('Sources'), heading ('h2' | 'h3').
 */
function seo_sources_list(string $type, int $id, array $opts = []): string
{
    $sources = seo_eeat_sources($type, $id);
    if (!$sources) {
        return '';
    }
    $h = ($opts['heading'] ?? 'h2') === 'h3' ? 'h3' : 'h2';
    $hid = 'seo-sources-' . preg_replace('/[^a-z]/', '', $type) . '-' . (int) $id;
    $html = '<section class="seo-sources" aria-labelledby="' . e($hid) . '"><' . $h . ' class="seo-sources-title" id="' . e($hid) . '">'
        . e(seo_eeat_text($opts['title'] ?? 'Sources', 60)) . '</' . $h . '><ol class="seo-sources-list">';
    foreach ($sources as $s) {
        $host = seo_eeat_source_host($s['url']);
        $html .= '<li><a href="' . e($s['url']) . '" target="_blank" rel="noopener">' . e($s['title'])
            . '<span class="sr-only"> (opens in a new tab)</span></a>'
            . ($host !== $s['title'] ? ' <span class="seo-source-host">' . e($host) . '</span>' : '') . '</li>';
    }
    return $html . '</ol></section>';
}

// ---------------------------------------------------------------------
// Structured data
// ---------------------------------------------------------------------

/**
 * schema.org Person for a team member ([] when the row has no id or name).
 * @id {team page}#member-{id}; jobTitle = designation as entered; worksFor #organization (management, team) or
 * affiliation #organization (advisors, volunteers — they are not employees); image only with recorded photo consent;
 * sameAs only from the profile the admin entered; description = bio (max 300 characters).
 */
function seo_person_node(array $member): array
{
    $id = (int) ($member['id'] ?? 0);
    $name = seo_eeat_text($member['name'] ?? '', 120);
    if ($id <= 0 || $name === '') {
        return [];
    }
    $node = ['@type' => 'Person', '@id' => seo_eeat_person_id($member), 'name' => $name];
    $role = seo_eeat_text($member['designation'] ?? '', 150);
    if ($role !== '') {
        $node['jobTitle'] = $role;
    }
    $url = seo_eeat_member_url($member, true);
    if ($url !== '') {
        $node['url'] = $url;
    }
    $org = ['@id' => seo_eeat_org_id()];
    if (in_array((string) ($member['member_group'] ?? ''), ['advisor', 'volunteer'], true)) {
        $node['affiliation'] = $org;
    } else {
        $node['worksFor'] = $org;
    }
    $photo = seo_eeat_member_photo($member);
    if ($photo !== '') {
        $node['image'] = abs_url($photo);
    }
    $same = seo_eeat_profile_links($member);
    if ($same) {
        $node['sameAs'] = $same;
    }
    $bio = trim((string) ($member['bio'] ?? ''));
    if ($bio !== '') {
        $node['description'] = excerpt($bio, 300);
    }
    return $node;
}

/** Add a node to the WP-1 page graph once per @id. False when the graph (WP-1) is not available. */
function seo_eeat_graph_add(array $node): bool
{
    if (!$node || !function_exists('seo_graph_add')) {
        return false;
    }
    seo_eeat_cache_init();
    $key = (string) ($node['@id'] ?? '');
    if ($key !== '' && isset($GLOBALS['seo_eeat_cache']['graph_ids'][$key])) {
        return true;
    }
    if ($key !== '') {
        $GLOBALS['seo_eeat_cache']['graph_ids'][$key] = true;
    }
    seo_graph_add($node);
    return true;
}

/** Canonical without fragment, for building {canonical}#webpage. */
function seo_eeat_canonical(string $canonical): string
{
    $canonical = trim($canonical);
    $hash = strpos($canonical, '#');
    return $hash === false ? $canonical : substr($canonical, 0, $hash);
}

/**
 * Register the E-E-A-T graph nodes of an entity (call BEFORE page_header()):
 *  - Person nodes of the author and reviewer (published team members only);
 *  - a partial WebPage node {canonical}#webpage with reviewedBy + lastReviewed (only when the review line is shown)
 *    and, for non-article types, author. WP-1 merges WebPage-family nodes with that @id into the generated #webpage.
 * Returns ['author' => ?member, 'review' => ?['member','date'], 'graph' => bool (seo_graph_add available)].
 */
function seo_eeat_prepare(string $type, int $id, string $canonical = ''): array
{
    $out = ['author' => seo_eeat_author($type, $id), 'review' => seo_eeat_review($type, $id), 'graph' => function_exists('seo_graph_add')];
    if (!$out['graph']) {
        return $out;
    }
    $authorNode = $out['author'] ? seo_person_node($out['author']) : [];
    $reviewNode = $out['review'] ? seo_person_node($out['review']['member']) : [];
    seo_eeat_graph_add($authorNode);
    seo_eeat_graph_add($reviewNode);
    $canonical = seo_eeat_canonical($canonical);
    if ($canonical !== '' && ($reviewNode || ($authorNode && !in_array($type, ['post', 'story'], true)))) {
        $wp = ['@type' => 'WebPage', '@id' => $canonical . '#webpage'];
        if ($reviewNode) {
            $wp['reviewedBy'] = ['@id' => $reviewNode['@id']];
            $wp['lastReviewed'] = $out['review']['date'];
        }
        if ($authorNode && !in_array($type, ['post', 'story'], true)) {
            $wp['author'] = ['@id' => $authorNode['@id']];
        }
        seo_eeat_graph_add($wp);
    }
    return $out;
}

/**
 * E-E-A-T fields for an Article/BlogPosting node built by the template (content_article_schema()):
 *  - author → the linked team member (by @id when the graph exists, else the full Person inline);
 *    without a linked member the template's author (free-text name or #organization) is kept;
 *  - datePublished / dateModified from seo_eeat_content_dates() (dateModified = substantive update, else published);
 *  - citation → the admin-entered sources (CreativeWork name + url).
 * Also registers the Person / WebPage nodes (seo_eeat_prepare). Call before page_header().
 */
function seo_eeat_article(array $article, string $type, int $id, array $row = [], string $canonical = ''): array
{
    if (!$article) {
        return $article;
    }
    $prep = seo_eeat_prepare($type, $id, $canonical);
    if ($prep['author']) {
        $person = seo_person_node($prep['author']);
        if ($person) {
            $article['author'] = $prep['graph'] ? ['@id' => $person['@id']] : $person;
        }
    }
    $dates = seo_eeat_content_dates($type, $id, $row);
    $pub = seo_eeat_ts($dates['published']);
    if ($pub) {
        $upd = seo_eeat_ts($dates['updated']);
        $article['datePublished'] = date('c', $pub);
        $article['dateModified'] = date('c', ($upd && $upd > $pub && $upd <= time() + 86400) ? $upd : $pub);
    }
    $sources = seo_eeat_sources($type, $id);
    if ($sources) {
        $article['citation'] = array_map(function (array $s): array {
            return ['@type' => 'CreativeWork', 'name' => $s['title'], 'url' => $s['url']];
        }, $sources);
    }
    return $article;
}

/**
 * Nodes for a team page: one Person per published member plus a partial WebPage {page}#webpage whose mainEntity
 * is an ItemList of those Persons (by @id). [] when there are no members.
 */
function seo_team_nodes(array $members, array $page): array
{
    $nodes = [];
    $items = [];
    foreach (array_slice(array_values($members), 0, 200) as $m) {
        if (!is_array($m) || (string) ($m['status'] ?? 'published') !== 'published') {
            continue;
        }
        $node = seo_person_node($m);
        if (!$node) {
            continue;
        }
        $nodes[] = $node;
        $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'item' => ['@id' => $node['@id']]];
    }
    $slug = (string) ($page['slug'] ?? '');
    if ($items && $slug !== '') {
        $nodes[] = [
            '@type' => 'WebPage',
            '@id' => abs_page_url($slug) . '#webpage',
            'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($items), 'itemListElement' => $items],
        ];
    }
    return $nodes;
}

/** Add the team page's Person nodes + ItemList to the page graph (WP-1). Call before page_header(). */
function seo_team_graph(array $members, array $page): void
{
    if (!function_exists('seo_graph_add')) {
        return;
    }
    foreach (seo_team_nodes($members, $page) as $node) {
        seo_eeat_graph_add($node);
    }
}

// ---------------------------------------------------------------------
// Admin helpers (editors). Escape everything; nothing is generated.
// ---------------------------------------------------------------------

/** True when seo_meta exists and has the E-E-A-T columns. */
function seo_eeat_table_ready(): bool
{
    $cols = seo_eeat_meta_columns();
    return in_array('author_member_id', $cols, true) && in_array('sources', $cols, true);
}

/** Team members for the author/reviewer selects: id => label. Hidden members appear only when currently selected. */
function seo_eeat_team_options(array $keepIds = []): array
{
    $keepIds = array_values(array_filter(array_map('intval', $keepIds)));
    $params = [];
    $sql = "SELECT id, name, designation, member_group, status FROM team_members WHERE status = 'published'";
    if ($keepIds) {
        $sql .= ' OR id IN (' . implode(',', array_fill(0, count($keepIds), '?')) . ')';
        $params = $keepIds;
    }
    $sql .= " ORDER BY FIELD(member_group, 'management', 'team', 'advisor', 'volunteer'), sort_order, name";
    try {
        $rows = db_rows($sql, $params);
    } catch (Throwable $e) {
        $rows = [];
    }
    $out = [];
    foreach ($rows as $r) {
        $label = seo_eeat_text($r['name'], 120) . (trim((string) $r['designation']) !== '' ? ' – ' . seo_eeat_text($r['designation'], 80) : '');
        if ($r['status'] !== 'published') {
            $label .= ' (hidden: not shown on the website)';
        }
        $out[(int) $r['id']] = $label;
    }
    return $out;
}

/** Stored sources as editor text: one "Title | https://address" per line. */
function seo_eeat_sources_text(string $type, int $id): string
{
    $lines = [];
    foreach (seo_eeat_sources($type, $id) as $s) {
        $lines[] = $s['title'] . ' | ' . $s['url'];
    }
    return implode("\n", $lines);
}

/**
 * Parse the sources textarea. Each non-empty line: "Title | https://address" or just "https://address".
 * Returns [$list, $errors] where $list = [['title','url'], ...].
 */
function seo_eeat_parse_sources(string $text): array
{
    $list = [];
    $errors = [];
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $n = 0;
    foreach ($lines as $line) {
        $n++;
        $line = trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $line));
        if ($line === '') {
            continue;
        }
        if (!preg_match('#^(.*?)\s*(\S+)$#u', $line, $m) || !preg_match('#^[a-z][a-z0-9+.\-]*:#i', $m[2])) {
            $errors[] = 'Line ' . $n . ': end the line with the full address of the source (https://...).';
            continue;
        }
        $url = seo_eeat_clean_source_url($m[2]);
        if ($url === '') {
            $errors[] = 'Line ' . $n . ': "' . mb_substr($m[2], 0, 60) . '" is not a valid https:// address.';
            continue;
        }
        $title = seo_eeat_text((string) preg_replace('/^[\s|:\-–—]+|[\s|:\-–—]+$/u', '', $m[1]), 200);
        $list[] = ['title' => $title !== '' ? $title : seo_eeat_source_host($url), 'url' => $url];
    }
    if (count($list) > SEO_EEAT_MAX_SOURCES) {
        $errors[] = 'Enter at most ' . SEO_EEAT_MAX_SOURCES . ' sources.';
    }
    return [$list, $errors];
}

/**
 * Validate the E-E-A-T fields of an editor form (field names eeat_*). Returns [$data, $errors]; merge $errors into
 * the editor's own errors before back_with_errors(). $data goes to seo_eeat_save().
 */
function seo_eeat_validate_post(array $post): array
{
    $str = function (string $key) use ($post): string {
        $v = $post[$key] ?? '';
        return is_string($v) ? trim($v) : '';
    };
    $errors = [];
    $exists = function (int $mid): bool {
        try {
            return (int) db_value('SELECT COUNT(*) FROM team_members WHERE id = ?', [$mid]) === 1;
        } catch (Throwable $e) {
            return false;
        }
    };
    $author = preg_match('/^\d{1,10}$/', $str('eeat_author_member_id')) ? (int) $str('eeat_author_member_id') : 0;
    $reviewer = preg_match('/^\d{1,10}$/', $str('eeat_reviewer_member_id')) ? (int) $str('eeat_reviewer_member_id') : 0;
    if ($str('eeat_author_member_id') !== '' && ($author <= 0 || !$exists($author))) {
        $errors['eeat_author_member_id'] = 'Choose the author from Team & Management.';
    }
    if ($str('eeat_reviewer_member_id') !== '' && ($reviewer <= 0 || !$exists($reviewer))) {
        $errors['eeat_reviewer_member_id'] = 'Choose the reviewer from Team & Management.';
    }
    $date = $str('eeat_reviewed_at');
    if ($date !== '' && seo_eeat_valid_date($date) === null) {
        $errors['eeat_reviewed_at'] = 'Enter the date of the review (not in the future).';
    }
    if ($reviewer > 0 && $date === '' && empty($errors['eeat_reviewer_member_id'])) {
        $errors['eeat_reviewed_at'] = 'Enter the date on which the reviewer checked this content.';
    }
    if ($date !== '' && $reviewer <= 0 && empty($errors['eeat_reviewed_at'])) {
        $errors['eeat_reviewer_member_id'] = 'Choose who reviewed this content, or clear the review date.';
    }
    [$sources, $srcErrors] = seo_eeat_parse_sources($str('eeat_sources'));
    if ($srcErrors) {
        $errors['eeat_sources'] = implode(' ', array_slice($srcErrors, 0, 3));
    }
    $data = [
        'author_member_id'   => $author > 0 ? $author : null,
        'reviewer_member_id' => $reviewer > 0 ? $reviewer : null,
        'reviewed_at'        => ($reviewer > 0 && $date !== '') ? $date : null,
        'sources'            => $sources ? json_encode(array_slice($sources, 0, SEO_EEAT_MAX_SOURCES), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        'substantive'        => !empty($post['eeat_substantive']) && !is_array($post['eeat_substantive']),
        'clear_updated'      => !empty($post['eeat_clear_updated']) && !is_array($post['eeat_clear_updated']),
    ];
    return [$data, $errors];
}

/**
 * Save validated E-E-A-T data (from seo_eeat_validate_post) into seo_meta for one entity. Only columns that exist are
 * written; other seo_meta fields are untouched. A "substantive update" sets content_updated_at to now; "clear" removes
 * it. Returns the changes as ['field' => ['old' => ..., 'new' => ...]] (also passed to WP-4's seo_log_changes()).
 */
function seo_eeat_save(string $type, int $id, array $data, ?int $by = null): array
{
    if (!seo_eeat_type_ok($type) || $id <= 0 || !seo_eeat_table_ready()) {
        return [];
    }
    $cols = seo_eeat_meta_columns();
    $fields = [];
    foreach (['author_member_id', 'reviewer_member_id', 'reviewed_at', 'sources'] as $f) {
        if (array_key_exists($f, $data)) {
            $fields[$f] = $data[$f];
        }
    }
    if (!empty($data['clear_updated'])) {
        $fields['content_updated_at'] = null;
    } elseif (!empty($data['substantive'])) {
        $fields['content_updated_at'] = date('Y-m-d H:i:s');
    }
    $fields = array_intersect_key($fields, array_flip($cols));

    unset($GLOBALS['seo_eeat_cache']['meta'][$type . ':' . $id]);
    $current = seo_eeat_meta($type, $id);
    $changes = [];
    foreach ($fields as $f => $new) {
        $old = $current[$f] ?? null;
        $oldCmp = $old === null ? null : (string) $old;
        $newCmp = $new === null ? null : (string) $new;
        if ($f === 'reviewed_at' && $oldCmp !== null) {
            $oldCmp = substr($oldCmp, 0, 10);
        }
        if ($oldCmp !== $newCmp) {
            $changes[$f] = ['old' => $old, 'new' => $new];
        }
    }
    if (!$changes) {
        return [];
    }
    $write = array_intersect_key($fields, $changes);
    if (in_array('updated_by', $cols, true)) {
        $write['updated_by'] = $by;
    }
    $names = array_keys($write);
    $sql = 'INSERT INTO seo_meta (entity_type, entity_id, `' . implode('`, `', $names) . '`) VALUES (?, ?' . str_repeat(', ?', count($names)) . ')'
        . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(function (string $c): string {
            return '`' . $c . '` = VALUES(`' . $c . '`)';
        }, $names));
    db_query($sql, array_merge([$type, $id], array_values($write)));
    unset($GLOBALS['seo_eeat_cache']['meta'][$type . ':' . $id]);

    if (function_exists('seo_log_changes')) {
        try {
            seo_log_changes($type, $id, $changes);
        } catch (Throwable $e) {
            error_log('seo-eeat: audit log failed: ' . $e->getMessage());
        }
    }
    return $changes;
}

/**
 * "Author, review & sources" panel for an entity editor (inside the editor's <form>). Field names: eeat_*.
 * $id = 0 for a new item (saved after the insert).
 */
function seo_eeat_admin_panel(string $type, int $id): string
{
    if (!seo_eeat_type_ok($type)) {
        return '';
    }
    $head = '<section class="panel seo-eeat-panel"><div class="panel-head"><h2>Author, review &amp; sources</h2></div>';
    if (!seo_eeat_table_ready()) {
        return $head . '<p class="muted small">These fields become available once the SEO module has been installed (table seo_meta).</p></section>';
    }
    $meta = $id > 0 ? seo_eeat_meta($type, $id) : [];
    $old = function (string $key, string $default): string {
        $v = function_exists('old') ? old($key, $default) : $default;
        return is_string($v) ? $v : $default;
    };
    $err = function (string $key): string {
        return function_exists('field_error') ? (string) field_error($key) : '';
    };
    $authorId = $old('eeat_author_member_id', (string) ($meta['author_member_id'] ?? ''));
    $reviewerId = $old('eeat_reviewer_member_id', (string) ($meta['reviewer_member_id'] ?? ''));
    $reviewed = $old('eeat_reviewed_at', substr((string) ($meta['reviewed_at'] ?? ''), 0, 10));
    $sources = $old('eeat_sources', $id > 0 ? seo_eeat_sources_text($type, $id) : '');
    $team = seo_eeat_team_options([(int) $authorId, (int) $reviewerId]);
    $options = function (string $selected, string $none) use ($team): string {
        $html = '<option value="">' . e($none) . '</option>';
        foreach ($team as $mid => $label) {
            $html .= '<option value="' . (int) $mid . '"' . ((string) $mid === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        return $html;
    };
    $teamLink = function_exists('admin_url') ? admin_url('team.php') : url('admin/team.php');
    $html = $head;
    if (!$team) {
        $html .= '<p class="note-box small">No team members are published yet. Add real people in <a href="' . e($teamLink) . '">Team &amp; Management</a> to choose an author or reviewer. Until then the author name above (or the foundation) is shown.</p>';
    }
    $html .= '<div class="form-group mb-2"><label for="eeat_author">Author (team member)</label>'
        . '<select id="eeat_author" name="eeat_author_member_id"' . ($team ? '' : ' disabled') . '>' . $options($authorId, 'None – use the author name / the foundation') . '</select>'
        . '<span class="help">Shows their photo (if they consented), designation and short bio from Team &amp; Management.</span>' . $err('eeat_author_member_id') . '</div>';
    $html .= '<div class="form-group mb-2"><label for="eeat_reviewer">Reviewed by (optional)</label>'
        . '<select id="eeat_reviewer" name="eeat_reviewer_member_id"' . ($team ? '' : ' disabled') . '>' . $options($reviewerId, 'Not reviewed') . '</select>'
        . $err('eeat_reviewer_member_id') . '</div>';
    $html .= '<div class="form-group mb-2"><label for="eeat_reviewed_at">Review date</label>'
        . '<input type="date" id="eeat_reviewed_at" name="eeat_reviewed_at" value="' . e($reviewed) . '" max="' . e(date('Y-m-d')) . '">'
        . '<span class="help">"Reviewed by … on …" appears only when both a reviewer and the date are set. Choose someone who really checked the content.</span>' . $err('eeat_reviewed_at') . '</div>';
    $updated = seo_eeat_ts((string) ($meta['content_updated_at'] ?? ''));
    $html .= '<div class="form-group mb-2" role="group" aria-labelledby="eeat_updated_label"><strong id="eeat_updated_label">Updated date</strong>'
        . '<p class="small muted">' . ($updated ? 'Readers see "Updated ' . e(date('d M Y', $updated)) . '".' : 'No update date is shown.') . '</p>'
        . '<label class="checkbox"><input type="checkbox" name="eeat_substantive" value="1"><span>This save is a substantive update (facts, figures, steps or advice changed): show today as the updated date</span></label>'
        . ($updated ? '<label class="checkbox mt-1"><input type="checkbox" name="eeat_clear_updated" value="1"><span>Remove the updated date</span></label>' : '')
        . '<span class="help">Leave unticked for typo fixes, layout changes or a new image.</span></div>';
    $html .= '<div class="form-group"><label for="eeat_sources">Sources (optional)</label>'
        . '<textarea id="eeat_sources" name="eeat_sources" rows="4" maxlength="6000" placeholder="Title of the source | https://…">' . e($sources) . '</textarea>'
        . '<span class="help">One per line: title, a | sign, then the full https:// address. Up to ' . SEO_EEAT_MAX_SOURCES . ' official or published sources you actually used.</span>'
        . $err('eeat_sources') . '</div>';
    return $html . '</section>';
}

/** Number of seo_meta rows that name this member as author or reviewer (warn before hiding/deleting a member). */
function seo_eeat_member_usage(int $memberId): int
{
    if ($memberId <= 0 || !seo_eeat_table_ready()) {
        return 0;
    }
    try {
        return (int) db_value('SELECT COUNT(*) FROM seo_meta WHERE author_member_id = ? OR reviewer_member_id = ?', [$memberId, $memberId]);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Photo-consent checkbox for admin/team.php (empty when the column does not exist yet). */
function seo_eeat_team_consent_field(?array $member): string
{
    try {
        if (!column_exists('team_members', 'photo_consent')) {
            return '';
        }
    } catch (Throwable $e) {
        return '';
    }
    $checked = (string) ($member['photo_consent'] ?? '0');
    // after a failed save the form is re-shown with the submitted values: an unticked box is simply absent there
    if (function_exists('old') && old('name', "\0") !== "\0") {
        $checked = (string) old('photo_consent', '0');
    }
    return '<div class="form-group full"><label class="checkbox"><input type="checkbox" name="photo_consent" value="1"' . ((string) $checked === '1' ? ' checked' : '') . '>'
        . '<span>This person agreed that their photo may be shown with their articles and in search results (structured data)</span></label>'
        . '<span class="help">Without this, author boxes show initials and no photo is added to the page data.</span></div>';
}

/** Extra team_members columns from the team form: ['photo_consent' => 0|1] (empty when the column does not exist). */
function seo_eeat_team_row_extra(array $post): array
{
    try {
        if (!column_exists('team_members', 'photo_consent')) {
            return [];
        }
    } catch (Throwable $e) {
        return [];
    }
    $v = $post['photo_consent'] ?? '';
    return ['photo_consent' => (is_string($v) && $v === '1') ? 1 : 0];
}

// ---------------------------------------------------------------------
// Migrations 850-859 (WP-6). Idempotent, additive, never overwrite admin-entered values.
// ---------------------------------------------------------------------

/** Display switches (SEO-DESIGN 4.1, migration 850). */
function migration_850_seo_eeat_settings(): void
{
    add_setting_if_missing('seo_author_box', '1');
    add_setting_if_missing('seo_show_reviewed', '1');
}

/**
 * E-E-A-T columns on seo_meta (WP-1 creates the table in migration 800 with the 4.1 columns; this only adds what is
 * missing) + content_updated_at: the date of the last SUBSTANTIVE edit, set only when an editor ticks it.
 * If seo_meta does not exist (WP-1 not deployed) nothing happens; see the WP-6 report.
 */
function migration_851_seo_eeat_meta_columns(): void
{
    if (!table_exists('seo_meta')) {
        return;
    }
    add_column_if_missing('seo_meta', 'author_member_id', 'INT UNSIGNED DEFAULT NULL');
    add_column_if_missing('seo_meta', 'reviewer_member_id', 'INT UNSIGNED DEFAULT NULL');
    add_column_if_missing('seo_meta', 'reviewed_at', 'DATE DEFAULT NULL');
    add_column_if_missing('seo_meta', 'sources', 'TEXT DEFAULT NULL');
    add_column_if_missing('seo_meta', 'content_updated_at', 'DATETIME DEFAULT NULL');
    add_index_if_missing('seo_meta', 'idx_seo_meta_author', 'INDEX idx_seo_meta_author (author_member_id)');
    add_index_if_missing('seo_meta', 'idx_seo_meta_reviewer', 'INDEX idx_seo_meta_reviewer (reviewer_member_id)');
}

/** Recorded photo consent for team members (default 0: no photo in author boxes or Person data until ticked). */
function migration_852_seo_eeat_team_photo_consent(): void
{
    add_column_if_missing('team_members', 'photo_consent', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER photo');
}
