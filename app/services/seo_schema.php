<?php
/**
 * Shared JSON-LD builders for the public website (schema.org). Page templates put the returned arrays into
 * site_header(['schema' => [...]]); seo_jsonld_html() (app/services/seo.php) prints them beside the site-wide
 * CollegeOrUniversity (#organization) + WebSite (#website) graph, so every Course / Event / Article / Person here
 * links to that one organisation by @id instead of carrying its own copy.
 *
 * Rules: only data the page really shows (DB rows and settings). A builder returns null when a required property is
 * missing — never a guessed value (no invented dates, fees, ratings or qualifications).
 *
 *   seo_ld_org_ref()                 {'@type','@id','name'} reference to the site organisation
 *   seo_ld_breadcrumb($crumbs, $o)   BreadcrumbList from [[name, path|url|null], …] or ['Name' => path, …]
 *   seo_ld_faq($faqs)                FAQPage from [{q, a}] / [{question, answer}] (null when empty)
 *   seo_ld_course($program, $o)      Course from a programs row (offers / hasCourseInstance only from real data)
 *   seo_ld_course_list($rows, $name) ItemList of Course
 *   seo_ld_person($person)           Person (null for placeholders / missing names)
 *   seo_ld_video($video)             VideoObject (null unless name + thumbnail + uploadDate are known)
 */
require_once APP_ROOT . '/app/services/seo.php';

/** @id of the site-wide organisation entity printed by seo_jsonld_html(). */
function seo_ld_org_id(): string
{
    return seo_site_url() . '#organization';
}

/** Reference to the site organisation (provider / publisher / organizer / worksFor). */
function seo_ld_org_ref(): array
{
    return ['@type' => 'CollegeOrUniversity', '@id' => seo_ld_org_id(), 'name' => institute_name()];
}

/** Absolute URL for a path, site_image() result or URL (cache-busting ?v= removed), or null. */
function seo_ld_abs(?string $u): ?string
{
    $abs = seo_abs_url($u);
    return $abs === null ? null : (string) preg_replace('/([?&])v=\d+$/', '', $abs);
}

/** Canonical URL of the current request (path only, /x.php → /x). */
function seo_ld_current_url(): string
{
    return seo_path_url(seo_canonical_path(seo_site_path()));
}

/** Plain single-line text from (possibly HTML) content. */
function seo_ld_text(?string $s, int $max = 0): string
{
    $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return $max > 0 ? str_limit($t, $max) : $t;
}

/** Removes null / '' / [] values (one level). */
function seo_ld_clean(array $a): array
{
    return array_filter($a, fn ($v) => $v !== null && $v !== '' && $v !== []);
}

/**
 * BreadcrumbList. $crumbs = [['Home', ''], ['Programs', 'programs'], ['MBA', null]] (null = this page) or
 * ['Home' => '', 'Contact Us' => 'contact']. Paths are site-relative ('' = home) or absolute URLs.
 * $o: current (URL for null items, default the request's canonical URL), omit_current (bool: no item URL for them).
 */
function seo_ld_breadcrumb(array $crumbs, array $o = []): array
{
    $pairs = [];
    foreach ($crumbs as $k => $v) {
        $pairs[] = is_array($v) ? [(string) ($v[0] ?? ''), $v[1] ?? null] : [(string) $k, $v];
    }
    $items = [];
    foreach ($pairs as $i => [$name, $path]) {
        $item = null;
        if ($path === null) {
            $item = empty($o['omit_current']) ? ($o['current'] ?? seo_ld_current_url()) : null;
        } else {
            $path = (string) $path;
            $item = preg_match('#^https?://#i', $path) ? $path : absolute_url(ltrim($path, '/'));
        }
        $items[] = array_filter(['@type' => 'ListItem', 'position' => $i + 1, 'name' => seo_ld_text($name), 'item' => $item], fn ($x) => $x !== null);
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** FAQPage for the questions a page shows: [{q, a}] or [{question, answer}]. Null when there is nothing to describe. */
function seo_ld_faq(array $faqs): ?array
{
    $seen = [];
    $main = [];
    foreach ($faqs as $f) {
        if (!is_array($f)) {
            continue;
        }
        $q = seo_ld_text((string) ($f['q'] ?? $f['question'] ?? $f['name'] ?? ''));
        $a = seo_ld_text((string) ($f['a'] ?? $f['answer'] ?? $f['text'] ?? ''));
        $key = mb_strtolower($q);
        if ($q === '' || $a === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $main[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
    }
    return $main ? ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $main] : null;
}

/**
 * ISO 8601 duration of a program from its label ("6 Months", "90 Days (3 Months)", "2 Years") or, without a label,
 * duration_years (whole years → PnY, otherwise months). Null when neither is usable.
 */
function seo_ld_duration(?string $label, $years = null): ?string
{
    if (preg_match('/(\d+(?:\.\d+)?)\s*(year|yr|month|week|day|hour)/i', (string) $label, $m)) {
        $n = (float) $m[1];
        $unit = strtolower($m[2]);
        if ($unit === 'hour') {
            return 'PT' . (int) round($n) . 'H';
        }
        $code = ['year' => 'Y', 'yr' => 'Y', 'month' => 'M', 'week' => 'W', 'day' => 'D'][$unit];
        if (fmod($n, 1.0) !== 0.0) {
            return $code === 'Y' ? 'P' . (int) round($n * 12) . 'M' : null;
        }
        return 'P' . (int) $n . $code;
    }
    $y = (float) $years;
    if ($y <= 0) {
        return null;
    }
    return fmod($y, 1.0) === 0.0 ? 'P' . (int) $y . 'Y' : 'P' . (int) round($y * 12) . 'M';
}

/**
 * Delivery modes of a program as schema.org courseMode values. There is no mode column: certificate courses whose
 * highlights (admin text) say they also run online are Online + Onsite, everything else is taught on campus — the same
 * rule program.php uses for the "Mode" fact it shows.
 */
function seo_ld_course_modes(array $p): array
{
    $cert = ($p['level'] ?? '') === 'Certificate';
    return $cert && preg_match('/\bonline\b/i', (string) ($p['highlights'] ?? '')) ? ['Online', 'Onsite'] : ['Onsite'];
}

/** Campus Place (settings only) for an onsite CourseInstance, or null when no address is configured. */
function seo_ld_campus_place(): ?array
{
    $addr = seo_ld_clean(['@type' => 'PostalAddress', 'streetAddress' => (string) setting('address', ''), 'addressLocality' => (string) setting('city', ''),
        'addressRegion' => (string) setting('state', ''), 'postalCode' => (string) setting('pincode', ''), 'addressCountry' => 'IN']);
    return count($addr) > 2 ? ['@type' => 'Place', 'name' => institute_name(), 'address' => $addr] : null;
}

/**
 * Course for a programs row (programs.* as returned by sa_programs() / program.php).
 * $o: url (absolute, default /programs/{slug}), description (override), image (path/URL), lean (bool: list item —
 * no prerequisites / image / location), offers (bool, default true), instances (bool, default true).
 */
function seo_ld_course(array $p, array $o = []): array
{
    $url = (string) ($o['url'] ?? absolute_url('programs/' . $p['slug']));
    $lean = !empty($o['lean']);
    $level = function_exists('program_level_label') ? program_level_label((string) ($p['level'] ?? '')) : (string) ($p['level'] ?? '');
    $iso = seo_ld_duration((string) ($p['duration_label'] ?? ''), $p['duration_years'] ?? null);
    $desc = seo_ld_text((string) ($o['description'] ?? '') ?: (string) ($p['overview'] ?? ''), $lean ? 250 : 300);
    if ($desc === '') {
        // still only facts from the row: "<Name> — <duration> <level> program at <institute>."
        $desc = trim($p['name'] . ' — ' . trim((string) ($p['duration_label'] ?? '') . ' ' . strtolower($level)) . ' program at ' . institute_name() . '.');
    }
    $fee = (float) ($p['fee_per_year'] ?? 0);
    $perYear = $fee > 0 && stripos((string) ($p['fee_label'] ?? ''), 'year') !== false;
    $offers = null;
    if (($o['offers'] ?? true) && $fee > 0) {
        $offers = [seo_ld_clean([
            '@type' => 'Offer', 'category' => 'Paid', 'price' => $fee, 'priceCurrency' => 'INR',
            'url' => $lean ? null : absolute_url('apply?program=' . rawurlencode((string) $p['slug'])),
            'priceSpecification' => $perYear ? ['@type' => 'UnitPriceSpecification', 'price' => $fee, 'priceCurrency' => 'INR', 'unitText' => 'per year'] : null,
        ])];
    }
    $instances = null;
    if (($o['instances'] ?? true) && $iso !== null) {
        $place = $lean ? null : seo_ld_campus_place();
        $instances = array_map(fn ($mode) => seo_ld_clean([
            '@type' => 'CourseInstance', 'courseMode' => $mode, 'courseWorkload' => $iso,
            'location' => $mode === 'Onsite' ? $place : null,
        ]), seo_ld_course_modes($p));
    }
    $credential = trim((string) ($p['degree'] ?? ''));
    return seo_ld_clean([
        '@context' => 'https://schema.org', '@type' => 'Course', '@id' => $url . '#course',
        'name' => (string) $p['name'], 'description' => $desc, 'url' => $url,
        'courseCode' => (string) ($p['code'] ?? ''),
        'image' => !$lean && !empty($o['image']) ? seo_ld_abs((string) $o['image']) : null,
        'educationalLevel' => $level,
        'educationalCredentialAwarded' => $credential !== '' ? $credential : ($level !== '' ? $level : null),
        'timeRequired' => $iso,
        'numberOfCredits' => !$lean && (int) ($p['total_credits'] ?? 0) > 0 ? (int) $p['total_credits'] : null,
        'coursePrerequisites' => !$lean ? (seo_ld_text((string) ($p['eligibility'] ?? '')) ?: null) : null,
        'provider' => seo_ld_org_ref(),
        'offers' => $offers,
        'hasCourseInstance' => $instances,
    ]);
}

/** ItemList of Course (all-in-one course list page). $rows = programs rows; $o is passed to seo_ld_course(). */
function seo_ld_course_list(array $rows, string $name, array $o = []): ?array
{
    $rows = array_values($rows);
    if (!$rows) {
        return null;
    }
    $items = [];
    foreach ($rows as $i => $p) {
        $c = seo_ld_course($p, ['lean' => true] + $o);
        unset($c['@context']);
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'item' => $c];
    }
    return ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $name, 'numberOfItems' => count($items), 'itemListElement' => $items];
}

/** Text that is a stand-in waiting for real content ("Dr. X (placeholder)", "[To be confirmed by GIMT: …]"). */
function seo_ld_is_placeholder(string ...$values): bool
{
    foreach ($values as $v) {
        if (preg_match('/placeholder|to be confirmed|\[\s*tbc|lorem ipsum|sample name/i', $v)) {
            return true;
        }
    }
    return false;
}

/**
 * Person shown on a page: name (required), jobTitle, description (bio), image, url, sameAs (profile URLs),
 * knowsAbout, qualification text (hasCredential is not used — free text is not a credential record).
 * Null for placeholders or when the name is missing. worksFor = the site organisation unless 'works_for' => false.
 */
function seo_ld_person(array $p): ?array
{
    $name = seo_ld_text((string) ($p['name'] ?? ''));
    $role = seo_ld_text((string) ($p['role'] ?? $p['jobTitle'] ?? $p['designation'] ?? ''));
    $bio = seo_ld_text((string) ($p['bio'] ?? $p['description'] ?? ''), 300);
    if ($name === '' || seo_ld_is_placeholder($name, $role, $bio)) {
        return null;
    }
    $same = [];
    foreach ((array) ($p['sameAs'] ?? []) as $u) {
        $u = trim((string) $u);
        if ($u !== '' && preg_match('#^https://[^\s/]+\.[^\s/]+/\S+#i', $u)) {
            $same[] = $u;
        }
    }
    $image = trim((string) ($p['image'] ?? ''));
    return seo_ld_clean([
        '@type' => 'Person', 'name' => $name, 'jobTitle' => $role ?: null, 'description' => $bio ?: null,
        'image' => $image !== '' ? seo_ld_abs($image) : null, 'url' => !empty($p['url']) ? seo_ld_abs((string) $p['url']) : null,
        'knowsAbout' => seo_ld_text((string) ($p['knowsAbout'] ?? '')) ?: null,
        'sameAs' => $same ? array_values(array_unique($same)) : null,
        'worksFor' => ($p['works_for'] ?? true) !== false ? seo_ld_org_ref() : null,
    ]);
}

/**
 * ' width="W" height="H"' for a local image URL / path (asset(), site_image() or upload URL; SVGs from their
 * width/height or viewBox) so the browser reserves the space (no layout shift). '' when the size is unknown.
 */
function seo_ld_img_dims(?string $url): string
{
    static $cache = [];
    $key = (string) $url;
    if (!array_key_exists($key, $cache)) {
        $abs = seo_abs_url($url);
        $meta = $abs ? seo_image_meta((string) preg_replace('/\?.*$/', '', $abs)) : [];
        $w = (int) ($meta['width'] ?? 0);
        $h = (int) ($meta['height'] ?? 0);
        if ((!$w || !$h) && ($meta['mime'] ?? '') === 'image/svg+xml' && ($file = seo_local_file((string) preg_replace('/\?.*$/', '', (string) $abs)))) {
            $head = (string) @file_get_contents($file, false, null, 0, 4096);
            if (preg_match('/<svg\b[^>]*>/i', $head, $tag)) {
                if (preg_match('/\bwidth="([\d.]+)(?:px)?"/i', $tag[0], $mw) && preg_match('/\bheight="([\d.]+)(?:px)?"/i', $tag[0], $mh)) {
                    [$w, $h] = [(int) round((float) $mw[1]), (int) round((float) $mh[1])];
                } elseif (preg_match('/viewBox="\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*"/i', $tag[0], $vb)) {
                    [$w, $h] = [(int) round((float) $vb[1]), (int) round((float) $vb[2])];
                }
            }
        }
        $cache[$key] = $w > 0 && $h > 0 ? ' width="' . $w . '" height="' . $h . '"' : '';
    }
    return $cache[$key];
}

/** YouTube video id of a watch / embed / shorts / youtu.be URL, or null. */
function seo_ld_youtube_id(string $url): ?string
{
    return preg_match('#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#i', $url, $m) ? $m[1] : null;
}

/**
 * VideoObject — only with real data: name, uploadDate (a date the institute recorded) and a thumbnail (given, or the
 * YouTube thumbnail of the video itself). $v: name, description, url, thumbnail, uploadDate, duration (ISO 8601).
 * Returns null when any required property is unknown: the page then prints no VideoObject at all.
 */
function seo_ld_video(array $v): ?array
{
    $name = seo_ld_text((string) ($v['name'] ?? ''));
    $url = trim((string) ($v['url'] ?? ''));
    $date = trim((string) ($v['uploadDate'] ?? ''));
    $ts = $date !== '' ? strtotime($date) : false;
    if ($name === '' || $url === '' || $ts === false) {
        return null;
    }
    $yt = seo_ld_youtube_id($url);
    $thumb = trim((string) ($v['thumbnail'] ?? '')) ?: ($yt ? 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg' : '');
    if ($thumb === '') {
        return null;
    }
    $embed = $yt ? 'https://www.youtube.com/embed/' . $yt : (preg_match('#vimeo\.com/(?:video/)?(\d+)#i', $url, $m) ? 'https://player.vimeo.com/video/' . $m[1] : null);
    return seo_ld_clean([
        '@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => $name,
        'description' => seo_ld_text((string) ($v['description'] ?? ''), 300) ?: $name,
        'thumbnailUrl' => [seo_ld_abs($thumb)], 'uploadDate' => date('c', $ts),
        'duration' => preg_match('/^PT?\d/', (string) ($v['duration'] ?? '')) ? (string) $v['duration'] : null,
        'embedUrl' => $embed, 'contentUrl' => $embed === null ? seo_ld_abs($url) : null, 'url' => $yt ? 'https://www.youtube.com/watch?v=' . $yt : null,
        'publisher' => seo_ld_org_ref(),
    ]);
}
