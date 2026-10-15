<?php
/**
 * Site management module (M1): helpers for the page/section editor, menus, media, settings, widgets, SEO audit,
 * plus the module section types "timeline", "logo_strip" and "image_cards".
 *
 * Editor field types (see section_schema() in cms.php):
 *   text, textarea, html, url, icon, image, number, checkbox, select, buttons, strings, date
 * Posted repeater rows are turned into the JSON `items` / `settings` of a section by site_parse_section_post().
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Section background options (render_section() accepts these). */
const SITE_SECTION_BACKGROUNDS = ['white' => 'White', 'tint' => 'Light green tint', 'cream' => 'Cream', 'blue' => 'Light blue', 'green' => 'Dark green (white text)'];

/** Addresses that can never be used as page slugs or redirect sources. */
const SITE_RESERVED_SLUGS = ['admin', 'assets', 'uploads', 'includes', 'templates', 'api', 'sitemap.xml', 'robots.txt', 'index.php',
    'install.php', 'payment-webhook.php', 'functions.php', 'config.php'];

/** Button styles understood by ui_buttons(). */
const SITE_BUTTON_STYLES = ['primary' => 'Green (primary)', 'donate' => 'Gold (donate)', 'outline' => 'Outline', 'light' => 'Light', 'blue' => 'Blue', 'ghost' => 'Plain'];

// =====================================================================
// Small helpers
// =====================================================================

/** Icon names available in icon() (read from includes/ui.php so the picker never offers a missing icon). */
function site_icon_names(): array
{
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $names = [];
    $src = @file_get_contents(ROOT_PATH . '/includes/ui.php');
    if ($src !== false && preg_match('/function icon\(.*?static \$icons = \[(.*?)\n\s*\];/s', $src, $m)) {
        preg_match_all("/^\s*'([a-z0-9\-]+)'\s*=>/m", $m[1], $mm);
        $names = array_values(array_unique($mm[1]));
    }
    sort($names);
    return $names;
}

/**
 * Validate and normalise a link entered by an editor (same rules as safe_link()).
 * Accepts site paths (/about/), https:// or http:// addresses, mailto:, tel: and #anchors. '#' alone is never allowed.
 * Returns the normalised link ('' when empty or invalid) and sets $error when invalid.
 */
function site_clean_link(string $href, ?string &$error = null): string
{
    $error = null;
    $href = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $href) ?? '');
    if ($href === '') {
        return '';
    }
    if (preg_match('/^#\s*$/', $href)) {
        $error = 'a link cannot be just "#". Enter a page address such as /about/ or a full https:// address.';
        return '';
    }
    if (preg_match('#^https?://[^\s<>"\']+$#i', $href)) {
        if (!filter_var($href, FILTER_VALIDATE_URL)) {
            $error = 'this web address is not valid.';
            return '';
        }
        return mb_substr($href, 0, 255);
    }
    if (preg_match('#^mailto:[^\s<>"\']+@[^\s<>"\']+$#i', $href) || preg_match('#^tel:\+?[\d\-\s()]{5,20}$#i', $href)) {
        return $href;
    }
    if (preg_match('#^\#[\w\-]+$#', $href)) {
        return $href;
    }
    if (preg_match('#^/?([a-z0-9\-_/]*)/?(\?[^\s<>"\'\#]*)?(\#[\w\-]+)?$#i', $href, $m)) {
        $path = '/' . ltrim($href, '/');
        // Clean URLs always end with a slash: /about -> /about/
        if (empty($m[2]) && empty($m[3]) && substr($path, -1) !== '/') {
            $path .= '/';
        }
        return preg_replace('#/{2,}#', '/', $path);
    }
    $error = 'this link is not allowed. Use a site address like /about/, a full https:// address, mailto: or tel:.';
    return '';
}

/** Path (no slashes at the ends) of an internal link, or null for external links/anchors. */
function site_internal_path(string $href): ?string
{
    if ($href === '' || preg_match('#^(https?:|mailto:|tel:|\#)#i', $href)) {
        if (preg_match('#^https?://#i', $href)) {
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ($host !== '' && $host === strtolower((string) parse_url(BASE_URL, PHP_URL_HOST))) {
                return trim((string) parse_url($href, PHP_URL_PATH), '/');
            }
        }
        return null;
    }
    $path = trim((string) parse_url($href, PHP_URL_PATH), '/');
    $base = trim(base_path(), '/');
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        $path = substr($path, strlen($base) + 1);
    }
    return $path;
}

/**
 * Does an internal link lead somewhere? 'external' | 'ok' | 'draft' (page exists but is not published) | 'redirect' | 'missing'.
 */
function site_link_status(string $href): string
{
    $path = site_internal_path($href);
    if ($path === null) {
        return 'external';
    }
    if (in_array($path, ['sitemap.xml', 'robots.txt'], true)) {
        return 'ok';
    }
    $path = strtolower($path);
    if (resolve_route($path)) {
        return 'ok';
    }
    $page = db_row('SELECT status FROM pages WHERE slug = ?', [$path === '' ? 'home' : $path]);
    if ($page) {
        return 'draft';
    }
    if (db_value('SELECT COUNT(*) FROM redirects WHERE source_path = ?', [$path])) {
        return 'redirect';
    }
    return 'missing';
}

/** Published pages as [url => title] for link pickers (datalist). */
function site_page_links(): array
{
    static $links = null;
    if ($links === null) {
        $links = [];
        foreach (db_rows("SELECT slug, title, template FROM pages WHERE status = 'published' AND template NOT IN ('logout','not_found','reset_password','verify_email','donation_success','donation_failed','donation_receipt','campaign_detail','event_detail') ORDER BY sort_order, title") as $p) {
            $links[$p['slug'] === 'home' ? '/' : '/' . $p['slug'] . '/'] = $p['title'];
        }
    }
    return $links;
}

/** <datalist> with every published page (used by url fields: list="site-page-urls"). */
function site_page_datalist(string $id = 'site-page-urls'): string
{
    $html = '<datalist id="' . e($id) . '">';
    foreach (site_page_links() as $href => $title) {
        $html .= '<option value="' . e($href) . '">' . e($title) . '</option>';
    }
    return $html . '</datalist>';
}

/** Is this a usable public image path (uploads/ or assets/images/)? */
function site_valid_image_path(string $path): bool
{
    return $path !== '' && strpos($path, '..') === false
        && (bool) preg_match('#^(uploads|assets/images)/[A-Za-z0-9._\-/]+\.(jpe?g|png|webp|svg|gif)$#i', $path)
        && strpos($path, 'uploads/documents/') !== 0 && strpos($path, 'uploads/certificates/') !== 0
        && is_file(ROOT_PATH . '/' . $path);
}

/** One file from a nested $_FILES field, e.g. site_file_at('items_files', [3, 'image']). Null when nothing was sent. */
function site_file_at(string $field, array $keys): ?array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $out = [];
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $prop) {
        $v = $_FILES[$field][$prop] ?? null;
        foreach ($keys as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return null;
            }
            $v = $v[$k];
        }
        $out[$prop] = $v;
    }
    return (is_array($out['error']) || (int) $out['error'] === UPLOAD_ERR_NO_FILE) ? null : $out;
}

/**
 * Upload one image (a $_FILES-style array) into uploads/<folder>/ through admin_upload_image()
 * so it is validated, re-encoded and added to the media library. Returns the path or null (error flashed).
 */
function site_upload_image_array(array $file, string $folder = 'pages', ?string $alt = null): ?string
{
    if (!function_exists('admin_upload_image')) {
        return null;
    }
    $_FILES['__site_upload'] = $file;
    $path = admin_upload_image('__site_upload', $folder, $alt);
    unset($_FILES['__site_upload']);
    return $path;
}

/** Media library rows for the image picker. */
function site_media_rows(int $limit = 300): array
{
    try {
        return db_rows('SELECT id, path, original_name, width, height, alt_text FROM media ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit)));
    } catch (Throwable $e) {
        return [];
    }
}

/** Where is an image path used (pages, sections, settings)? ['Label' => url, ...] for warnings before deleting. */
function site_media_usage(string $path): array
{
    $uses = [];
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path) . '%';
    foreach (db_rows('SELECT id, title FROM pages WHERE banner_image = ? OR og_image = ?', [$path, $path]) as $p) {
        $uses['Page: ' . $p['title']] = 'page-edit.php?id=' . $p['id'];
    }
    foreach (db_rows('SELECT s.id, s.page_id, p.title FROM page_sections s JOIN pages p ON p.id = s.page_id WHERE s.image = ? OR s.items LIKE ? OR s.body LIKE ? OR s.settings LIKE ?', [$path, $like, $like, $like]) as $s) {
        $uses['Section on: ' . $s['title'] . ' (#' . $s['id'] . ')'] = 'page-edit.php?id=' . $s['page_id'] . '&tab=sections&open=' . $s['id'] . '#section-' . $s['id'];
    }
    foreach (db_rows('SELECT setting_key FROM website_settings WHERE setting_value = ?', [$path]) as $st) {
        $uses['Setting: ' . $st['setting_key']] = 'settings.php';
    }
    $checks = [
        'Campaign' => "SELECT id, title FROM campaigns WHERE image = ?",
        'Programme' => "SELECT id, title FROM programs WHERE cover_image = ?",
        'Event' => "SELECT id, title FROM events WHERE image = ?",
        'Article' => "SELECT id, title FROM blog_posts WHERE image = ?",
        'Gallery' => "SELECT id, title FROM gallery_items WHERE image_path = ?",
    ];
    foreach ($checks as $label => $sql) {
        try {
            foreach (db_rows($sql, [$path]) as $r) {
                $uses[$label . ': ' . $r['title']] = '';
            }
        } catch (Throwable $e) {
            // table may not be used yet
        }
    }
    return $uses;
}

/** Name of a staff member (for "last updated by"). */
function site_user_name(?int $id): string
{
    static $cache = [];
    if (!$id) {
        return '';
    }
    if (!isset($cache[$id])) {
        $cache[$id] = (string) db_value('SELECT name FROM users WHERE id = ?', [$id]);
    }
    return $cache[$id];
}

/** Mark a page as edited now by the current staff member. */
function site_touch_page(int $pageId): void
{
    db_query('UPDATE pages SET updated_by = ?, updated_at = NOW() WHERE id = ?', [function_exists('admin_id') ? admin_id() : current_user_id(), $pageId]);
}

/** Re-number the sort order of a page's sections 1..n (keeps the current order). */
function site_renumber_sections(int $pageId): void
{
    $i = 0;
    foreach (db_rows('SELECT id FROM page_sections WHERE page_id = ? ORDER BY sort_order, id', [$pageId]) as $r) {
        db_query('UPDATE page_sections SET sort_order = ? WHERE id = ?', [++$i, $r['id']]);
    }
}

/** Validate a page slug. Returns an error message or null. */
function site_slug_problem(string $slug, int $ignoreId = 0): ?string
{
    if ($slug === '') {
        return 'The address (slug) is required.';
    }
    if (strlen($slug) > 190 || !preg_match('#^[a-z0-9][a-z0-9\-]*(/[a-z0-9][a-z0-9\-]*)*$#', $slug)) {
        return 'Use lower-case letters, numbers and hyphens only (a "/" may separate parts, e.g. programs/new-project).';
    }
    if ($slug === 'home' || in_array(explode('/', $slug)[0], SITE_RESERVED_SLUGS, true)) {
        return 'This address is reserved by the website. Please choose another one.';
    }
    if ((int) db_value('SELECT COUNT(*) FROM pages WHERE slug = ? AND id <> ?', [$slug, $ignoreId]) > 0) {
        return 'Another page already uses this address.';
    }
    return null;
}

/** Normalise a redirect source ("/old-page/?x=1", "https://site/old.php") to "old-page" / "old.php". Null if unusable. */
function site_normalize_source(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $input)) {
        $host = strtolower((string) parse_url($input, PHP_URL_HOST));
        if ($host !== strtolower((string) parse_url(BASE_URL, PHP_URL_HOST)) && $host !== strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''))) {
            return null;
        }
        $input = (string) parse_url($input, PHP_URL_PATH);
    }
    $input = (string) strtok($input, '?#');
    $input = rawurldecode($input);
    $base = trim(base_path(), '/');
    $input = trim($input, "/ \t");
    if ($base !== '' && strpos($input, $base . '/') === 0) {
        $input = substr($input, strlen($base) + 1);
    }
    if ($input === '' || strlen($input) > 255 || !preg_match('#^[A-Za-z0-9._~\-/%]+$#', $input) || strpos($input, '..') !== false) {
        return null;
    }
    return $input;
}

/** Validate a redirect target: internal path starting with / or an https:// address. Returns [target, error]. */
function site_clean_redirect_target(string $target): array
{
    $target = trim($target);
    if ($target === '') {
        return ['', 'Enter where the old address should go.'];
    }
    if (preg_match('#^https://[^\s<>"\']+$#i', $target) && filter_var($target, FILTER_VALIDATE_URL)) {
        return [mb_substr($target, 0, 255), null];
    }
    if ($target[0] === '/' && preg_match('#^/[A-Za-z0-9._~\-/%]*(\?[A-Za-z0-9=&%_\-.+]*)?(\#[\w\-]+)?$#', $target) && strpos($target, '..') === false && strpos($target, '//') !== 0) {
        if (!preg_match('#[?\#]#', $target) && !preg_match('#\.[a-z0-9]{2,5}$#i', $target) && substr($target, -1) !== '/') {
            $target .= '/';
        }
        return [$target, null];
    }
    return ['', 'The target must be a site address starting with / (e.g. /about/) or a full https:// address.'];
}

// =====================================================================
// Editor: field rendering
// =====================================================================

/** Standard section columns (page_sections) and how they are edited. */
function site_section_column_defs(): array
{
    return [
        'pill'  => ['text', 'Small label above the heading', 'Optional, 1-3 words, e.g. "Who we are".'],
        'title' => ['text', 'Heading'],
        'intro' => ['textarea', 'Introduction (plain text)'],
        'body'  => ['html', 'Text (rich text)'],
        'image' => ['image', 'Image'],
    ];
}

/** Sub-fields of a button (banner buttons, CTA buttons). */
function site_button_fields(): array
{
    return [
        'label'   => ['text', 'Button text'],
        'url'     => ['url', 'Link'],
        'style'   => ['select', 'Style', SITE_BUTTON_STYLES],
        'icon'    => ['icon', 'Icon'],
        'size'    => ['select', 'Size', ['' => 'Normal', 'sm' => 'Small', 'lg' => 'Large']],
        'new_tab' => ['checkbox', 'Open in a new tab'],
    ];
}

/** Turn a schema definition [type, label, extra] into a normalised array. */
function site_def(array $def): array
{
    $type = (string) ($def[0] ?? 'text');
    $extra = $def[2] ?? null;
    return [
        'type'    => $type,
        'label'   => (string) ($def[1] ?? ''),
        'options' => $type === 'select' && is_array($extra) ? $extra : [],
        'help'    => is_string($extra) ? $extra : (string) ($def[3] ?? ''),
    ];
}

/** Does this field type take the full width of the form grid? */
function site_field_is_wide(string $type): bool
{
    return in_array($type, ['textarea', 'html', 'image', 'buttons', 'strings'], true);
}

/**
 * HTML for one editor field.
 * $name: input name (e.g. "set[cols]"), $id: unique id, $fileName: name of the upload input for image fields.
 */
function site_field_html(string $name, string $id, array $rawDef, $value, string $fileName = '', array $opt = []): string
{
    $d = site_def($rawDef);
    $type = $d['type'];
    $label = $d['label'] !== '' ? $d['label'] : humanize($name);
    $help = $d['help'] !== '' ? '<span class="help">' . e($d['help']) . '</span>' : '';
    $wide = site_field_is_wide($type) || !empty($opt['wide']);
    $group = function (string $inner) use ($wide): string {
        return '<div class="form-group' . ($wide ? ' full' : '') . '">' . $inner . '</div>';
    };
    $lbl = '<label for="' . e($id) . '">' . e($label) . '</label>';
    $str = is_scalar($value) ? (string) $value : '';

    switch ($type) {
        case 'textarea':
            return $group($lbl . '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="3" maxlength="5000">' . e($str) . '</textarea>' . $help);
        case 'html':
            return $group($lbl . '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="10" data-editor'
                . (!empty($opt['upload_url']) ? ' data-upload-url="' . e($opt['upload_url']) . '"' : '') . '>' . e($str) . '</textarea>'
                . '<span class="help">Use the buttons to format text (H2/H3 for sub-headings - the page title is the only main heading). Scripts, styles and embedded frames are removed.</span>' . $help);
        case 'url':
            return $group($lbl . '<input type="text" inputmode="url" id="' . e($id) . '" name="' . e($name) . '" value="' . e($str) . '" maxlength="255" list="site-page-urls" placeholder="/about/ or https://…" autocomplete="off">'
                . ($help ?: '<span class="help">A page of this site (start typing to pick one) or a full https:// address.</span>'));
        case 'icon':
            $html = '<select id="' . e($id) . '" name="' . e($name) . '" class="site-icon-select"><option value="">No icon</option>';
            foreach (site_icon_names() as $ic) {
                $html .= '<option value="' . e($ic) . '"' . ($str === $ic ? ' selected' : '') . '>' . e($ic) . '</option>';
            }
            return $group($lbl . '<div class="site-icon-pick">' . $html . '</select><span class="site-icon-preview" aria-hidden="true">' . ($str !== '' ? icon($str) : '') . '</span></div>' . $help);
        case 'image':
            return $group('<span class="label" id="' . e($id) . '-label">' . e($label) . '</span>' . site_image_input($name, $id, $str, $fileName) . $help);
        case 'number':
            return $group($lbl . '<input type="number" step="any" id="' . e($id) . '" name="' . e($name) . '" value="' . e($str) . '">' . $help);
        case 'date':
            return $group($lbl . '<input type="date" id="' . e($id) . '" name="' . e($name) . '" value="' . e($str) . '">' . $help);
        case 'checkbox':
            return '<div class="form-group site-check' . ($wide ? ' full' : '') . '"><label class="checkbox" for="' . e($id) . '"><input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . (!empty($value) ? ' checked' : '') . '><span>' . e($label) . '</span></label>' . $help . '</div>';
        case 'select':
            $html = '<select id="' . e($id) . '" name="' . e($name) . '">';
            foreach ($d['options'] as $k => $v) {
                $html .= '<option value="' . e((string) $k) . '"' . ((string) $k === $str ? ' selected' : '') . '>' . e($v) . '</option>';
            }
            return $group($lbl . $html . '</select>' . $help);
        case 'strings':
            $lines = is_array($value) ? implode("\n", array_map(function ($v) { return is_scalar($v) ? (string) $v : ''; }, $value)) : $str;
            return $group($lbl . '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="4">' . e($lines) . '</textarea><span class="help">One per line.</span>' . $help);
        case 'buttons':
            $rows = is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
            return $group('<span class="label">' . e($label) . '</span>' . site_repeater_html($name, $id, site_button_fields(), $rows, '', 'Button', 0) . $help);
        case 'text':
        default:
            return $group($lbl . '<input type="text" id="' . e($id) . '" name="' . e($name) . '" value="' . e($str) . '" maxlength="500">' . $help);
    }
}

/** Image field: preview, path box (paste a path or choose from the media library), upload and remove. */
function site_image_input(string $name, string $id, string $value, string $fileName = ''): string
{
    $hasImg = $value !== '' && site_valid_image_path($value);
    $html = '<div class="site-image-field" data-image-field>'
        . '<img class="site-image-preview" src="' . e($hasImg ? url($value) : '') . '" alt="" data-image-preview' . ($hasImg ? '' : ' hidden') . '>'
        . '<div class="site-image-controls">'
        . '<input type="text" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '" maxlength="255" placeholder="uploads/media/… or assets/images/…" data-image-path aria-label="Image path">'
        . '<div class="site-image-actions">'
        . '<button type="button" class="btn btn-ghost btn-sm" data-media-pick hidden>' . icon('image') . 'Choose from library</button>'
        . '<button type="button" class="btn btn-ghost btn-sm" data-image-clear hidden>' . icon('x') . 'Remove</button>';
    if ($fileName !== '') {
        $html .= '<label class="site-upload btn btn-ghost btn-sm" for="' . e($id) . '-file">' . icon('upload') . 'Upload new</label>'
            . '<input type="file" class="site-file" id="' . e($id) . '-file" name="' . e($fileName) . '" accept=".jpg,.jpeg,.png,.webp" aria-label="Upload a new image" data-image-file>';
    }
    return $html . '</div><span class="help">JPG, PNG or WebP up to ' . (int) MAX_UPLOAD_MB . ' MB. Without JavaScript, paste a path from the media library or clear the box to remove the image.</span></div></div>';
}

/**
 * Repeater (rows of fields) that works without JavaScript: existing rows are real inputs named
 * "$base[<i>][<key>]", each row has a "remove" tick box and $blank empty rows are added for new entries.
 * admin-site.js adds "Add", "Remove", "Move up/down" buttons.
 */
function site_repeater_html(string $base, string $idBase, array $fields, array $rows, string $fileBase = '', string $rowLabel = 'Item', int $blank = 1, array $opt = []): string
{
    $idBase = preg_replace('/[^A-Za-z0-9_\-]/', '-', $idBase);
    $renderRow = function ($i, array $row, bool $isBlank) use ($base, $idBase, $fields, $fileBase, $rowLabel, $opt): string {
        $html = '<li class="repeater-row" data-row' . ($isBlank ? ' data-blank' : '') . '><div class="repeater-head"><strong data-row-num>' . e($rowLabel) . ' ' . (is_int($i) ? $i + 1 : '') . '</strong>'
            . '<span class="repeater-tools" data-row-tools hidden>'
            . '<button type="button" class="icon-btn" data-row-up aria-label="Move up" title="Move up">' . icon('arrow-up') . '</button>'
            . '<button type="button" class="icon-btn site-down" data-row-down aria-label="Move down" title="Move down">' . icon('arrow-up') . '</button>'
            . '<button type="button" class="icon-btn danger" data-row-remove aria-label="Remove" title="Remove">' . icon('trash') . '</button></span>'
            . '<label class="checkbox small repeater-remove" for="' . e($idBase . '-' . $i . '-remove') . '"><input type="checkbox" id="' . e($idBase . '-' . $i . '-remove') . '" name="' . e($base . '[' . $i . '][_remove]') . '" value="1"><span>' . ($isBlank ? 'Ignore' : 'Remove') . '</span></label></div>'
            . '<div class="form-grid repeater-fields">';
        foreach ($fields as $key => $def) {
            $d = site_def($def);
            if ($d['type'] === 'buttons') {
                $d['type'] = 'textarea';
                $def = ['textarea', $d['label'] . ' (JSON)'];
                $val = isset($row[$key]) ? json_encode($row[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
            } else {
                $val = $row[$key] ?? '';
            }
            $html .= site_field_html($base . '[' . $i . '][' . $key . ']', $idBase . '-' . $i . '-' . $key, $def, $val,
                ($d['type'] === 'image' && $fileBase !== '') ? $fileBase . '[' . $i . '][' . $key . ']' : '', $opt);
        }
        return $html . '</div></li>';
    };
    $html = '<div class="repeater" id="' . e($idBase) . '" data-repeater data-label="' . e($rowLabel) . '"><ol class="repeater-rows" data-rows>';
    $i = 0;
    foreach (array_values($rows) as $i => $row) {
        $html .= $renderRow($i, is_array($row) ? $row : [], false);
    }
    $next = $rows ? $i + 1 : 0;
    for ($b = 0; $b < $blank; $b++) {
        $html .= $renderRow($next++, [], true);
    }
    $html .= '</ol><template data-row-template>' . $renderRow('__i__', [], false) . '</template>'
        . '<button type="button" class="btn btn-ghost btn-sm" data-row-add data-next="' . $next . '" hidden>' . icon('plus') . 'Add ' . e(mb_strtolower($rowLabel)) . '</button></div>';
    return $html;
}

// =====================================================================
// Editor: parsing & validation of posted values
// =====================================================================

/**
 * Normalise one posted value. Returns [value, error|null]; value null means "empty / not set".
 * $file is an uploaded image for image fields (site_file_at()).
 */
function site_parse_value(array $rawDef, $raw, ?array $file = null, string $folder = 'pages'): array
{
    $d = site_def($rawDef);
    switch ($d['type']) {
        case 'checkbox':
            return [!empty($raw) ? 1 : null, null];
        case 'buttons':
            $out = [];
            $err = null;
            if (is_string($raw) && trim($raw) !== '') {
                $raw = json_decode($raw, true);
                if (!is_array($raw)) {
                    return [null, 'buttons must be valid JSON.'];
                }
            }
            foreach (is_array($raw) ? $raw : [] as $n => $row) {
                if (!is_array($row) || !empty($row['_remove'])) {
                    continue;
                }
                $label = mb_substr(clean_string($row['label'] ?? ''), 0, 60);
                $urlErr = null;
                $url = site_clean_link((string) ($row['url'] ?? ''), $urlErr);
                if ($label === '' && trim((string) ($row['url'] ?? '')) === '') {
                    continue;
                }
                if ($urlErr || $url === '' || $label === '') {
                    $err = 'button ' . (count($out) + 1) . ': ' . ($label === '' ? 'enter the button text.' : ($urlErr ?: 'enter a link.'));
                    continue;
                }
                $btn = ['label' => $label, 'url' => $url, 'style' => array_key_exists((string) ($row['style'] ?? ''), SITE_BUTTON_STYLES) ? (string) $row['style'] : 'primary'];
                $ic = (string) ($row['icon'] ?? '');
                if ($ic !== '' && in_array($ic, site_icon_names(), true)) {
                    $btn['icon'] = $ic;
                }
                if (in_array($row['size'] ?? '', ['sm', 'lg'], true)) {
                    $btn['size'] = $row['size'];
                }
                if (!empty($row['new_tab'])) {
                    $btn['new_tab'] = 1;
                }
                $out[] = $btn;
            }
            return [$out ?: null, $err];
        case 'strings':
            $list = is_array($raw) ? $raw : preg_split('/\r?\n/', (string) $raw);
            $list = array_values(array_filter(array_map(function ($v) { return mb_substr(clean_string($v), 0, 300); }, $list), 'strlen'));
            return [$list ?: null, null];
    }
    if (!is_scalar($raw) && $raw !== null) {
        return [null, null];
    }
    $str = (string) $raw;
    switch ($d['type']) {
        case 'html':
            $v = clean_html($str);
            return [$v !== '' ? $v : null, null];
        case 'textarea':
            $v = mb_substr(clean_string($str, true), 0, 5000);
            return [$v !== '' ? $v : null, null];
        case 'url':
            $err = null;
            $v = site_clean_link($str, $err);
            return [$v !== '' ? $v : null, $err];
        case 'icon':
            $v = clean_string($str);
            if ($v === '') {
                return [null, null];
            }
            return in_array($v, site_icon_names(), true) ? [$v, null] : [null, 'unknown icon "' . $v . '".'];
        case 'image':
            if ($file) {
                $path = site_upload_image_array($file, $folder);
                if ($path) {
                    return [$path, null];
                }
                return [null, 'the image could not be uploaded (see the message above).'];
            }
            $v = clean_string($str);
            if ($v === '') {
                return [null, null];
            }
            return site_valid_image_path($v) ? [$v, null] : [null, 'image "' . $v . '" was not found. Choose it from the media library or upload it again.'];
        case 'number':
            $v = str_replace(',', '', clean_string($str));
            if ($v === '') {
                return [null, null];
            }
            if (!is_numeric($v)) {
                return [null, 'must be a number.'];
            }
            return [preg_match('/^-?\d+$/', $v) ? (int) $v : (float) $v, null];
        case 'date':
            $v = clean_string($str);
            if ($v === '') {
                return [null, null];
            }
            $dt = DateTime::createFromFormat('Y-m-d', $v);
            return ($dt && $dt->format('Y-m-d') === $v) ? [$v, null] : [null, 'must be a valid date.'];
        case 'select':
            $v = clean_string($str);
            if (!$d['options']) {
                return [$v !== '' ? $v : null, null];
            }
            if (!array_key_exists($v, $d['options'])) {
                return [null, 'please choose a valid option.'];
            }
            return [$v !== '' ? (preg_match('/^\d+$/', $v) ? (int) $v : $v) : null, null];
        case 'text':
        default:
            $v = mb_substr(clean_string($str), 0, 500);
            return [$v !== '' ? $v : null, null];
    }
}

/** Clean an unknown (not in the schema) JSON value: scalars become safe strings/numbers, nested arrays are cleaned recursively. */
function site_clean_unknown($v, int $depth = 0)
{
    if (is_bool($v) || is_int($v) || is_float($v)) {
        return $v;
    }
    if (is_string($v)) {
        return mb_substr(clean_string($v, true), 0, 5000);
    }
    if (is_array($v) && $depth < 4) {
        $out = [];
        foreach (array_slice($v, 0, 100, true) as $k => $x) {
            $out[is_int($k) ? $k : preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)] = site_clean_unknown($x, $depth + 1);
        }
        return $out;
    }
    return null;
}

/** Normalise one item/settings object against field definitions (unknown keys are kept but cleaned). */
function site_parse_object(array $defs, array $row, string $prefix, array &$errors, ?callable $fileFor = null, bool $keepUnknown = true): array
{
    $out = [];
    foreach ($defs as $key => $def) {
        $file = $fileFor ? $fileFor($key) : null;
        [$v, $err] = site_parse_value($def, $row[$key] ?? null, $file);
        if ($err) {
            $errors[] = $prefix . site_def($def)['label'] . ': ' . $err;
        }
        if ($v !== null) {
            $out[$key] = $v;
        }
    }
    if ($keepUnknown) {
        foreach ($row as $key => $v) {
            if (!isset($defs[$key]) && $key !== '_remove' && is_string($key) && preg_match('/^[a-z0-9_\-]{1,40}$/i', $key)) {
                $clean = site_clean_unknown($v);
                if ($clean !== null && $clean !== '' && $clean !== []) {
                    if (preg_match('/(^|_)(url|link|href)$/', $key) && is_string($clean)) {
                        $err = null;
                        $clean = site_clean_link($clean, $err);
                        if ($err) {
                            $errors[] = $prefix . $key . ': ' . $err;
                            continue;
                        }
                    }
                    $out[$key] = $clean;
                }
            }
        }
    }
    return $out;
}

/**
 * Build a section from the editor POST. Returns [columns, errors] where columns has pill, title, intro, body, image,
 * anchor, background, status, items (JSON string|null), settings (JSON string|null).
 * $existing is the current DB row (unknown settings keys are preserved).
 */
function site_parse_section_post(array $existing, array $post): array
{
    $type = (string) $existing['section_type'];
    $schema = section_schema($type);
    $errors = [];
    $cols = [];
    $colDefs = site_section_column_defs();
    $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

    foreach (['pill' => 100, 'title' => 255, 'intro' => 5000, 'body' => 0, 'image' => 255] as $col => $max) {
        if (!in_array($col, $fields, true)) {
            $cols[$col] = $existing[$col] ?? null;   // not edited for this type: keep as is
            continue;
        }
        $file = $col === 'image' ? site_file_at('image_upload', []) : null;
        [$v, $err] = site_parse_value($colDefs[$col], $post[$col] ?? '', $file);
        if ($err) {
            $errors[] = $colDefs[$col][1] . ': ' . $err;
        }
        if ($v !== null && $max > 0 && is_string($v) && mb_strlen($v) > $max) {
            $v = mb_substr($v, 0, $max);
        }
        $cols[$col] = $v;
    }

    // Anchor, background, visibility
    $anchor = strtolower(trim((string) ($post['anchor'] ?? '')));
    $anchor = trim(preg_replace('/[^a-z0-9\-_]+/', '-', $anchor), '-');
    if ($anchor !== '' && !preg_match('/^[a-z][a-z0-9\-_]{0,79}$/', $anchor)) {
        $errors[] = 'Anchor: start with a letter and use only letters, numbers and hyphens.';
        $anchor = '';
    }
    $cols['anchor'] = $anchor !== '' ? $anchor : null;
    $bg = (string) ($post['background'] ?? 'white');
    $cols['background'] = array_key_exists($bg, SITE_SECTION_BACKGROUNDS) ? $bg : 'white';
    $cols['status'] = ($post['status'] ?? 'visible') === 'hidden' ? 'hidden' : 'visible';

    // Items
    $itemDefs = $schema['items'] ?? null;
    $items = null;
    $jsonItems = trim((string) ($post['items_json'] ?? ''));
    $jsonItemsChanged = isset($post['items_json']) && $jsonItems !== trim((string) ($post['items_json_orig'] ?? ''));
    if ($jsonItemsChanged) {
        if ($jsonItems === '') {
            $items = [];
        } else {
            $decoded = json_decode($jsonItems, true);
            if (!is_array($decoded) || ($decoded && array_keys($decoded) !== range(0, count($decoded) - 1))) {
                $errors[] = 'Items (JSON): this is not a valid JSON list - check commas, quotes and brackets ([ … ]).';
                $items = json_field($existing['items'] ?? null);
            } else {
                $items = [];
                foreach (array_slice($decoded, 0, 100) as $n => $row) {
                    if ($itemDefs === 'strings' || !is_array($row)) {
                        $s = is_scalar($row) ? mb_substr(clean_string((string) $row), 0, 300) : '';
                        if ($s !== '') {
                            $items[] = $s;
                        }
                        continue;
                    }
                    $obj = site_parse_object(is_array($itemDefs) ? $itemDefs : [], $row, 'Item ' . ($n + 1) . ' › ', $errors);
                    if ($obj) {
                        $items[] = $obj;
                    }
                }
            }
        }
    } elseif ($itemDefs === 'strings') {
        [$items] = site_parse_value(['strings', 'Items'], (string) ($post['items_strings'] ?? ''));
        $items = $items ?? [];
    } elseif (is_array($itemDefs)) {
        $items = [];
        $n = 0;
        foreach ((array) ($post['items'] ?? []) as $idx => $row) {
            if (!is_array($row) || !empty($row['_remove'])) {
                continue;
            }
            $hasUpload = false;
            foreach ($itemDefs as $key => $def) {
                if (site_def($def)['type'] === 'image' && site_file_at('items_files', [$idx, $key])) {
                    $hasUpload = true;
                }
            }
            $filled = array_filter($row, function ($v, $k) { return $k !== '_remove' && (is_array($v) ? (bool) $v : trim((string) $v) !== ''); }, ARRAY_FILTER_USE_BOTH);
            if (!$filled && !$hasUpload) {
                continue;   // blank row
            }
            $n++;
            $obj = site_parse_object($itemDefs, $row, 'Item ' . $n . ' › ', $errors, function ($key) use ($idx) {
                return site_file_at('items_files', [$idx, $key]);
            });
            if ($obj) {
                $items[] = $obj;
            }
            if ($n >= 100) {
                break;
            }
        }
    } else {
        $items = json_field($existing['items'] ?? null) ?: null;
    }
    $cols['items'] = $items ? json_encode(array_values($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    // Settings: start from the stored settings so keys the editor does not show are kept
    $settings = json_field($existing['settings'] ?? null);
    $setDefs = (is_array($schema['settings'] ?? null) ? $schema['settings'] : []) + section_common_settings();
    $jsonSet = trim((string) ($post['settings_json'] ?? ''));
    $jsonSetChanged = isset($post['settings_json']) && $jsonSet !== trim((string) ($post['settings_json_orig'] ?? ''));
    if ($jsonSetChanged) {
        $decoded = $jsonSet === '' ? [] : json_decode($jsonSet, true);
        if (!is_array($decoded) || ($decoded && array_keys($decoded) === range(0, count($decoded) - 1))) {
            $errors[] = 'Settings (JSON): this is not a valid JSON object - it must look like { "key": "value" }.';
        } else {
            $settings = site_parse_object($setDefs, $decoded, 'Settings › ', $errors);
        }
    } else {
        $postSet = is_array($post['set'] ?? null) ? $post['set'] : [];
        foreach ($setDefs as $key => $def) {
            $file = site_def($def)['type'] === 'image' ? site_file_at('set_files', [$key]) : null;
            [$v, $err] = site_parse_value($def, $postSet[$key] ?? null, $file);
            if ($err) {
                $errors[] = site_def($def)['label'] . ': ' . $err;
                continue;
            }
            if ($v === null) {
                unset($settings[$key]);
            } else {
                $settings[$key] = $v;
            }
        }
    }
    $cols['settings'] = $settings ? json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    return [$cols, $errors];
}

// =====================================================================
// Editor: section form
// =====================================================================

/** Short description of a section for the list ("Cards grid · 6 items"). */
function site_section_summary(array $s): string
{
    $items = json_field($s['items'] ?? null);
    $bits = [section_schema((string) $s['section_type'])['label']];
    if ($items) {
        $bits[] = count($items) . ' ' . (count($items) === 1 ? 'item' : 'items');
    }
    if (!empty($s['anchor'])) {
        $bits[] = '#' . $s['anchor'];
    }
    return implode(' · ', $bits);
}

/**
 * The edit form of one section (fields generated from section_schema()).
 * $s may be a draft (after a failed save) with the submitted values.
 */
function site_section_form(array $s, array $page, array $errors = []): string
{
    $sid = (int) $s['id'];
    $type = (string) $s['section_type'];
    $exists = function_exists('section_' . preg_replace('/[^a-z0-9_]/', '', $type));
    $schema = section_schema($type);
    $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
    $p = 's' . $sid;
    $action = 'page-edit.php?id=' . (int) $page['id'] . '&tab=sections';
    $uploadUrl = admin_url('page-edit.php?id=' . (int) $page['id'] . '&upload=1');

    $html = '<form method="post" action="' . e($action) . '" enctype="multipart/form-data" class="site-section-form" novalidate>'
        . csrf_field() . '<input type="hidden" name="action" value="save_section"><input type="hidden" name="section_id" value="' . $sid . '">';
    if ($errors) {
        $html .= '<div class="alert alert-error">' . icon('alert-circle') . '<div><strong>Not saved - please correct:</strong><ul class="site-error-list">';
        foreach ($errors as $err) {
            $html .= '<li>' . e($err) . '</li>';
        }
        $html .= '</ul></div></div>';
    }
    if (!$exists) {
        $html .= '<div class="alert alert-warning">' . icon('alert-circle') . '<div>The section type <code>' . e($type) . '</code> is provided by a module that is not installed yet, so it shows nothing on the website. You can still edit or delete it.</div></div>';
    }
    if (!empty($schema['help'])) {
        $html .= '<p class="note-box site-help">' . icon('info') . ' ' . e($schema['help']) . '</p>';
    }

    // Standard columns
    $colDefs = site_section_column_defs();
    $html .= '<div class="form-grid">';
    foreach (['pill', 'title', 'intro', 'body', 'image'] as $col) {
        if (in_array($col, $fields, true)) {
            $html .= site_field_html($col, $p . '-' . $col, $colDefs[$col], $s[$col] ?? '', $col === 'image' ? 'image_upload' : '', ['upload_url' => $uploadUrl]);
        }
    }
    $html .= '</div>';

    // Items
    $itemDefs = $schema['items'] ?? null;
    $items = is_array($s['items'] ?? null) ? $s['items'] : json_field($s['items'] ?? null);
    if ($itemDefs === 'strings') {
        $html .= '<h4 class="site-sub">Items</h4><div class="form-grid">' . site_field_html('items_strings', $p . '-items', ['strings', 'List items'], $items) . '</div>';
    } elseif (is_array($itemDefs) && $itemDefs) {
        $html .= '<h4 class="site-sub">Items</h4>' . site_repeater_html('items', $p . '-items', $itemDefs, $items, 'items_files', 'Item', $items ? 1 : 2, ['upload_url' => $uploadUrl]);
    }
    $jsonItems = $items ? json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

    // Settings
    $settings = is_array($s['settings'] ?? null) ? $s['settings'] : json_field($s['settings'] ?? null);
    $setDefs = is_array($schema['settings'] ?? null) ? $schema['settings'] : [];
    if ($setDefs) {
        $html .= '<h4 class="site-sub">Options</h4><div class="form-grid">';
        foreach ($setDefs as $key => $def) {
            $html .= site_field_html('set[' . $key . ']', $p . '-set-' . $key, $def, $settings[$key] ?? '', site_def($def)['type'] === 'image' ? 'set_files[' . $key . ']' : '');
        }
        $html .= '</div>';
    }

    // Layout & visibility
    $html .= '<h4 class="site-sub">Layout &amp; visibility</h4><div class="form-grid form-grid-3 site-layout-grid">';
    foreach (section_common_settings() as $key => $def) {
        $html .= site_field_html('set[' . $key . ']', $p . '-set-' . $key, $def, $settings[$key] ?? '');
    }
    $html .= site_field_html('background', $p . '-bg', ['select', 'Background', SITE_SECTION_BACKGROUNDS], $s['background'] ?? 'white')
        . site_field_html('anchor', $p . '-anchor', ['text', 'Anchor (for links to #anchor)', 'e.g. "our-values" - lets menus and on-page navigation link to this section.'], $s['anchor'] ?? '')
        . site_field_html('status', $p . '-status', ['select', 'Visibility', ['visible' => 'Visible on the website', 'hidden' => 'Hidden (draft)']], $s['status'] ?? 'visible')
        . '</div>';

    // JSON fallback (always available; the changed JSON wins over the fields above)
    $jsonSet = $settings ? json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    $html .= '<details class="site-json"><summary>' . icon('settings') . 'Advanced: edit items &amp; options as JSON</summary>'
        . '<p class="json-help">If you change the JSON here it replaces the fields above when you save. Leave it unchanged to use the fields.</p>'
        . '<div class="form-grid">'
        . '<div class="form-group full"><label for="' . $p . '-items-json">Items (JSON list)</label><textarea class="code-area" id="' . $p . '-items-json" name="items_json" rows="6" spellcheck="false">' . e($jsonItems) . '</textarea>'
        . '<textarea name="items_json_orig" hidden aria-hidden="true" tabindex="-1">' . e($jsonItems) . '</textarea></div>'
        . '<div class="form-group full"><label for="' . $p . '-settings-json">Options (JSON object)</label><textarea class="code-area" id="' . $p . '-settings-json" name="settings_json" rows="4" spellcheck="false">' . e($jsonSet) . '</textarea>'
        . '<textarea name="settings_json_orig" hidden aria-hidden="true" tabindex="-1">' . e($jsonSet) . '</textarea></div>'
        . '</div></details>';

    $html .= '<div class="form-actions site-form-actions"><button class="btn btn-primary" type="submit">' . icon('check') . 'Save section</button>'
        . '<a class="btn btn-ghost btn-sm" href="' . e(page_url($page['slug']) . ($page['status'] !== 'published' ? '?preview=1' : '') . ($s['anchor'] ? '#' . $s['anchor'] : '')) . '" target="_blank" rel="noopener">' . icon('eye') . 'View on page</a></div>';
    return $html . '</form>';
}

/** All icons inside a <template> so admin-site.js can preview the icon chosen in a picker. */
function site_icon_sprite(): string
{
    $html = '<template id="site-icon-sprite">';
    foreach (site_icon_names() as $name) {
        $html .= '<span data-icon="' . e($name) . '">' . icon($name) . '</span>';
    }
    return $html . '</template>';
}

/** Dialog listing the media library for image fields (opened by admin-site.js). */
function site_media_picker(): string
{
    $rows = site_media_rows();
    $html = '<dialog class="site-dialog" id="site-media-picker" aria-labelledby="site-media-picker-title"><div class="site-dialog-card">'
        . '<div class="site-dialog-head"><h2 id="site-media-picker-title">' . icon('image') . 'Choose an image</h2>'
        . '<button type="button" class="site-dialog-close" data-dialog-close aria-label="Close">' . icon('x') . '</button></div>'
        . '<div class="site-dialog-body"><label class="sr-only" for="site-media-search">Search images</label>'
        . '<input type="search" id="site-media-search" placeholder="Search by file name or description" data-media-search>';
    if (!$rows) {
        $html .= '<p class="muted mt-2">The media library is empty. Upload images in <a href="' . e(admin_url('media.php')) . '">Media Library</a> or use "Upload new".</p>';
    } else {
        $html .= '<ul class="site-media-choices">';
        foreach ($rows as $m) {
            if (!is_file(ROOT_PATH . '/' . $m['path'])) {
                continue;
            }
            $label = trim(($m['alt_text'] ?: '') . ' ' . ($m['original_name'] ?: basename($m['path'])));
            $html .= '<li data-media-item data-search="' . e(mb_strtolower($label . ' ' . $m['path'])) . '"><button type="button" data-media-choose="' . e($m['path']) . '" data-url="' . e(url($m['path'])) . '">'
                . '<img src="' . e(url($m['path'])) . '" alt="" loading="lazy" width="160" height="110"><span>' . e(mb_strimwidth($m['original_name'] ?: basename($m['path']), 0, 28, '…')) . '</span>'
                . '<small>' . (int) $m['width'] . '×' . (int) $m['height'] . '</small></button></li>';
        }
        $html .= '</ul>';
    }
    return $html . '</div></div></dialog>';
}

// =====================================================================
// Settings & widgets: field definitions, rendering and validation
// =====================================================================

/**
 * Settings tabs (Admin > Settings). Field: [type, label, help, options/limits].
 * Types: text, textarea, email, phone, digits, link, https, map, checkbox, int, date, pan, ifsc, upi, presets,
 *        image, ga, gsv, robots, handle, select, datetime.
 */
function site_settings_tabs(): array
{
    return [
        'general' => ['General & branding', 'home', [
            'site_name' => ['text', 'Organisation / site name', 'Shown in titles, emails and the header.', ['required' => true, 'max' => 120]],
            'site_tagline_en' => ['text', 'Tagline (English)', 'e.g. Together, let us build a better tomorrow', ['max' => 150]],
            'site_tagline' => ['text', 'Tagline (Hindi)', 'e.g. मिलकर बनाये बेहतर कल', ['max' => 150]],
            'brand_slogan' => ['text', 'Brand slogan (English)', 'Shown under the logo in the footer.', ['max' => 150]],
            'brand_slogan_hi' => ['text', 'Brand slogan (Hindi)', 'Shown in the header lockup.', ['max' => 150]],
            'site_description' => ['textarea', 'Short description of the foundation', 'Used in structured data for search engines.', ['max' => 500]],
            'logo_path' => ['image', 'Logo (emblem)', 'Square PNG, at least 256 × 256 px. Used in the header, admin panel, receipts and emails.'],
            'logo_horizontal_path' => ['image', 'Horizontal logo (optional)', 'Recommended about 600 × 200 px, PNG with a transparent or white background. When set it replaces the built-in emblem + name lockup in the header and footer. Leave empty to keep the built-in lockup.'],
            'favicon_path' => ['image', 'Favicon (browser tab icon)', 'Square PNG, 64 × 64 px or larger.'],
        ]],
        'contact' => ['Contact', 'mail', [
            'contact_email' => ['email', 'Public email address', 'Shown in the header, footer and contact page.', ['required' => true]],
            'contact_phone' => ['phone', 'Phone number', 'Leave blank if you do not want to publish a phone number.'],
            'whatsapp_number' => ['digits', 'WhatsApp number', 'With country code, digits only, e.g. 919812345678. Needed for the floating WhatsApp button.', ['min' => 10, 'max' => 15]],
            'contact_address' => ['textarea', 'Office address', '', ['max' => 300]],
            'office_hours' => ['text', 'Office hours', 'e.g. Monday to Saturday, 10:00 AM - 6:00 PM', ['max' => 150]],
            'map_embed_url' => ['map', 'Google Maps embed link', 'In Google Maps: Share → Embed a map → copy only the address inside src="…". It must start with https://www.google.com/maps/embed?'],
            '_grievance' => ['heading', 'Grievance redressal', 'Shown on the Grievance Redressal page. Enter only names and time limits the foundation has actually committed to - leave blank otherwise (the page then says complaints are handled as early as possible).'],
            'grievance_officer_name' => ['text', 'Grievance officer (name or designation)', '', ['max' => 120]],
            'grievance_officer_email' => ['email', 'Grievance officer email'],
            'grievance_ack_days' => ['int', 'Acknowledge complaints within (days)', '', ['min' => 1, 'max' => 365]],
            'grievance_resolution_days' => ['int', 'Resolve complaints within (days)', '', ['min' => 1, 'max' => 365]],
        ]],
        'header' => ['Header & footer', 'layers', [
            'topbar_enabled' => ['checkbox', 'Show the top bar above the header (welcome text, email, apply links, login)'],
            'topbar_text' => ['text', 'Top bar welcome text', '', ['max' => 150]],
            'header_cta_label' => ['text', 'Header button text', 'e.g. Donate Now', ['max' => 40, 'required' => true]],
            'header_cta_url' => ['link', 'Header button link', 'e.g. /donate/', ['required' => true]],
            'footer_about_text' => ['textarea', 'Footer "about" text', '', ['max' => 600]],
            'footer_copyright' => ['text', 'Copyright line', '{year} is replaced with the current year.', ['max' => 200]],
            'footer_show_newsletter' => ['checkbox', 'Show the newsletter sign-up in the footer'],
            'footer_show_contact' => ['checkbox', 'Show contact details in the footer'],
            'footer_show_social' => ['checkbox', 'Show social media icons in the footer'],
            'footer_show_registration' => ['checkbox', 'Show registration numbers in the footer (only filled-in values are shown)'],
            'footer_bottom_links' => ['checkbox', 'Show the Privacy / Terms / Refunds links in the bottom bar'],
            'newsletter_enabled' => ['checkbox', 'Newsletter sign-up enabled on the website'],
            'newsletter_consent_text' => ['textarea', 'Newsletter consent text', 'Shown next to the consent tick box.', ['max' => 255]],
        ]],
        'social' => ['Social links', 'share', [
            'social_facebook' => ['https', 'Facebook page', 'Full https:// address of the official page. Leave blank to hide.'],
            'social_instagram' => ['https', 'Instagram'],
            'social_youtube' => ['https', 'YouTube channel'],
            'social_twitter' => ['https', 'X (Twitter)'],
            'social_linkedin' => ['https', 'LinkedIn'],
        ]],
        'ngo' => ['NGO registration', 'shield', [
            'legal_name' => ['text', 'Legal name', '', ['max' => 200]],
            'registration_type' => ['text', 'Registration type', 'e.g. Trust / Society / Section 8 Company', ['max' => 120]],
            'registration_number' => ['text', 'Registration number', '', ['max' => 120]],
            'registration_date' => ['date', 'Date of registration'],
            'pan_number' => ['pan', 'PAN of the organisation'],
            'reg_12a_number' => ['text', '12A registration number', '', ['max' => 120]],
            'reg_80g_number' => ['text', '80G registration number', 'Only if 80G approval has been granted - donors use it for tax deduction.', ['max' => 120]],
            'csr1_number' => ['text', 'CSR-1 registration number', '', ['max' => 120]],
            'darpan_id' => ['text', 'NGO Darpan unique ID', '', ['max' => 120]],
            'fcra_number' => ['text', 'FCRA registration number', 'Only if registered under FCRA (foreign contributions).', ['max' => 120]],
        ]],
        'donations' => ['Donations & bank', 'rupee', [
            'online_donations_enabled' => ['checkbox', 'Accept online donations on the website'],
            'donation_min_amount' => ['int', 'Minimum online donation (₹)', '', ['min' => 1, 'max' => 100000, 'required' => true]],
            'donation_presets' => ['presets', 'Suggested amounts (₹)', 'Comma separated, e.g. 500,1000,2500,5000'],
            'bank_account_name' => ['text', 'Bank account name', 'Shown on the donate page for bank transfers. Leave blank to hide bank details.', ['max' => 150]],
            'bank_name' => ['text', 'Bank name', '', ['max' => 150]],
            'bank_account_number' => ['digits', 'Account number', '', ['min' => 6, 'max' => 20]],
            'bank_ifsc' => ['ifsc', 'IFSC code'],
            'bank_branch' => ['text', 'Branch', '', ['max' => 150]],
            'upi_id' => ['upi', 'UPI ID', 'e.g. name@bank'],
            'receipt_signatory' => ['text', 'Receipt signatory', 'Name or designation printed on donation receipts.', ['max' => 120]],
            'receipt_footer_note' => ['textarea', 'Receipt footer note', '', ['max' => 500]],
        ]],
        'email' => ['Email', 'send', [
            'admin_notify_email' => ['email', 'Notification email', 'New applications, enquiries and donations are notified to this address.', ['required' => true]],
        ]],
        'accounts' => ['Accounts', 'user', [
            'allow_registration' => ['checkbox', 'Allow visitors to create an account'],
            'require_email_verification' => ['checkbox', 'Require email verification for new accounts'],
            'auth_panel_title' => ['text', 'Login page panel title', '', ['max' => 150]],
            'auth_panel_text' => ['textarea', 'Login page panel text', '', ['max' => 500]],
            'auth_panel_image' => ['image', 'Login page panel image (optional)', 'Landscape image, about 900 × 700 px.'],
        ]],
        'recruitment' => ['Recruitment', 'briefcase', [
            'staff_positions' => ['textarea', 'Open staff positions', 'One per line as "Title | Short description". Leave empty when there are no openings.', ['max' => 5000]],
            'internship_areas' => ['textarea', 'Internship areas', 'Separate the areas with commas, e.g. Education, Health, Environment', ['max' => 1000]],
            '_certificates' => ['heading', 'Certificates', 'Printed on volunteer, internship and participation certificates. Use the name and designation of the person actually authorised to sign them.'],
            'certificate_signatory_name' => ['text', 'Certificate signatory name', '', ['max' => 120]],
            'certificate_signatory_title' => ['text', 'Certificate signatory designation', 'e.g. Secretary', ['max' => 120]],
        ]],
        'seo' => ['SEO & analytics', 'search', [
            'seo_title_suffix' => ['text', 'Title suffix', 'Added after page titles, e.g. " | Prerit Welfare Foundation".', ['max' => 80]],
            'seo_default_description' => ['textarea', 'Default meta description', 'Used when a page has no description of its own (about 150-160 characters).', ['max' => 300, 'counter' => 160]],
            'seo_default_og_image' => ['image', 'Default social sharing image', '1200 × 630 px JPG or PNG.'],
            'google_site_verification' => ['gsv', 'Google Search Console verification code', 'Paste the content value of the HTML tag (or the whole <meta …> tag).'],
            'ga_measurement_id' => ['ga', 'Google Analytics 4 measurement ID', 'Format G-XXXXXXXXXX. Visitors are asked for consent before Google Analytics loads.'],
            'analytics_enabled' => ['checkbox', 'First-party visitor statistics (no cookies, no IP addresses stored)'],
            'analytics_respect_dnt' => ['checkbox', 'Respect "Do Not Track" / Global Privacy Control'],
            'analytics_retention_days' => ['int', 'Keep visitor statistics for (days)', '', ['min' => 30, 'max' => 3650, 'required' => true]],
            'twitter_handle' => ['handle', 'X (Twitter) handle', 'e.g. @foundation'],
            'robots_txt_extra' => ['robots', 'Extra robots.txt lines', 'Advanced. Lines such as "Disallow: /private-page/". Leave empty normally.'],
            'seo_noindex_site' => ['checkbox', 'Hide the whole website from search engines (use only while the site is being set up)'],
            'share_buttons_enabled' => ['checkbox', 'Show share buttons on campaigns, events and articles'],
        ]],
        'retention' => ['Data retention', 'clock', [
            'retention_logs_days' => ['int', 'Keep audit and activity logs for (days)', '', ['min' => 30, 'max' => 3650, 'required' => true]],
            'retention_rejected_applications_days' => ['int', 'Keep rejected applications for (days)', '', ['min' => 30, 'max' => 3650, 'required' => true]],
            'retention_enquiries_days' => ['int', 'Keep closed enquiries for (days)', '', ['min' => 30, 'max' => 3650, 'required' => true]],
        ]],
    ];
}

/** Floating widgets, announcement and fraud alert fields (Admin > Widgets & Alerts). */
function site_widget_groups(): array
{
    return [
        'floating' => ['Floating buttons & effects', [
            'widget_whatsapp_enabled' => ['checkbox', 'WhatsApp button (needs the WhatsApp number in Settings > Contact)'],
            'widget_whatsapp_message' => ['text', 'WhatsApp default message', '', ['max' => 300]],
            'widget_contact_enabled' => ['checkbox', 'Call / contact button (calls the phone number, or opens the contact page when no number is set)'],
            'widget_help_enabled' => ['checkbox', 'Help assistant (automated answers from published FAQs - not a live chat)'],
            'widget_help_greeting' => ['textarea', 'Help assistant greeting', '', ['max' => 500]],
            'widget_back_to_top' => ['checkbox', '"Back to top" button'],
            'motion_effects_enabled' => ['checkbox', 'Motion effects (scroll-in animations, 3D card tilt). Always off for visitors who prefer reduced motion.'],
        ]],
        'announcement' => ['Announcement bar', [
            'announcement_enabled' => ['checkbox', 'Show the announcement bar above the header'],
            'announcement_text' => ['text', 'Announcement text', 'One short sentence, e.g. a real upcoming event or an office closure.', ['max' => 250]],
            'announcement_link_label' => ['text', 'Link text (optional)', '', ['max' => 40]],
            'announcement_link_url' => ['link', 'Link (optional)', 'e.g. /events-page/'],
            'announcement_starts' => ['datetime', 'Show from (optional)'],
            'announcement_ends' => ['datetime', 'Show until (optional)'],
        ]],
        'fraud' => ['Fraud alert', [
            'fraud_alert_title' => ['text', 'Title (English)', '', ['max' => 120]],
            'fraud_alert_message' => ['textarea', 'Message (English)', '', ['max' => 1000]],
            'fraud_alert_title_hi' => ['text', 'Title (Hindi)', '', ['max' => 120]],
            'fraud_alert_message_hi' => ['textarea', 'Message (Hindi)', '', ['max' => 1000]],
            'fraud_alert_points' => ['textarea', 'Safety points', 'One per line.', ['max' => 2000]],
            'fraud_alert_report_text' => ['textarea', 'How to report', '', ['max' => 1000]],
            'fraud_alert_show' => ['select', 'How often visitors see it', '', ['options' => ['once_per_visit' => 'Once per visit (browser session)', 'once_per_version' => 'Once, until the alert is changed']]],
            'fraud_alert_verified_note' => ['text', 'Verification note (shown in small print in the alert)', 'Filled in automatically when the alert is switched on, e.g. "Verified and published by … on …".', ['max' => 255]],
        ]],
    ];
}

/** Form control for a setting. */
function site_setting_input(string $key, array $def, string $value): string
{
    [$type, $label] = $def;
    $help = (string) ($def[2] ?? '');
    $o = $def[3] ?? [];
    $id = 'set-' . $key;
    $req = !empty($o['required']);
    $lbl = '<label for="' . e($id) . '">' . e($label) . ($req ? '<span class="req">*</span>' : '') . '</label>';
    $h = $help !== '' ? '<span class="help">' . e($help) . '</span>' : '';
    $max = isset($o['max']) && in_array($type, ['text', 'textarea'], true) ? ' maxlength="' . (int) $o['max'] . '"' : '';
    $counter = !empty($o['counter']) ? ' data-counter="' . (int) $o['counter'] . '"' : '';
    switch ($type) {
        case 'heading':
            return '<div class="form-group full site-group-head"><h3 class="site-sub">' . e($label) . '</h3>' . $h . '</div>';
        case 'checkbox':
            return '<div class="form-group full site-check"><input type="hidden" name="' . e($key) . '" value="0"><label class="checkbox" for="' . e($id) . '"><input type="checkbox" id="' . e($id) . '" name="' . e($key) . '" value="1"' . ($value === '1' ? ' checked' : '') . '><span>' . e($label) . '</span></label>' . $h . '</div>';
        case 'textarea':
        case 'robots':
            return '<div class="form-group full">' . $lbl . '<textarea id="' . e($id) . '" name="' . e($key) . '" rows="' . ($type === 'robots' || $key === 'staff_positions' ? 5 : 3) . '"' . $max . $counter . ($type === 'robots' ? ' class="code-area" spellcheck="false"' : '') . '>' . e($value) . '</textarea>' . $h . '</div>';
        case 'image':
            return '<div class="form-group full"><span class="label">' . e($label) . '</span>' . site_image_input($key, $id, $value, $key . '_upload') . $h . '</div>';
        case 'select':
            $html = '<select id="' . e($id) . '" name="' . e($key) . '">';
            foreach ($o['options'] ?? [] as $k => $v) {
                $html .= '<option value="' . e($k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . e($v) . '</option>';
            }
            return '<div class="form-group">' . $lbl . $html . '</select>' . $h . '</div>';
        case 'datetime':
            $v = $value !== '' ? str_replace(' ', 'T', substr($value, 0, 16)) : '';
            return '<div class="form-group">' . $lbl . '<input type="datetime-local" id="' . e($id) . '" name="' . e($key) . '" value="' . e($v) . '">' . $h . '</div>';
        case 'date':
            return '<div class="form-group">' . $lbl . '<input type="date" id="' . e($id) . '" name="' . e($key) . '" value="' . e($value) . '">' . $h . '</div>';
        case 'int':
            return '<div class="form-group">' . $lbl . '<input type="number" id="' . e($id) . '" name="' . e($key) . '" value="' . e($value) . '" step="1"' . (isset($o['min']) ? ' min="' . (int) $o['min'] . '"' : '') . (isset($o['max']) ? ' max="' . (int) $o['max'] . '"' : '') . ($req ? ' required' : '') . '>' . $h . '</div>';
        case 'email':
            return '<div class="form-group">' . $lbl . '<input type="email" id="' . e($id) . '" name="' . e($key) . '" value="' . e($value) . '" maxlength="190"' . ($req ? ' required' : '') . '>' . $h . '</div>';
        case 'https':
        case 'map':
            return '<div class="form-group' . ($type === 'map' ? ' full' : '') . '">' . $lbl . '<input type="' . ($type === 'map' ? 'text' : 'url') . '" inputmode="url" id="' . e($id) . '" name="' . e($key) . '" value="' . e($value) . '" maxlength="' . ($type === 'map' ? 1500 : 255) . '" placeholder="https://…">' . $h . '</div>';
        case 'link':
            return '<div class="form-group">' . $lbl . '<input type="text" id="' . e($id) . '" name="' . e($key) . '" value="' . e($value) . '" maxlength="255" list="site-page-urls" placeholder="/page/ or https://…"' . ($req ? ' required' : '') . '>' . $h . '</div>';
        default:
            $wide = in_array($type, ['gsv'], true) || mb_strlen($value) > 60;
            return '<div class="form-group' . ($wide ? ' full' : '') . '">' . $lbl . '<input type="text" id="' . e($id) . '" name="' . e($key) . '" value="' . e($value) . '"' . ($max ?: ' maxlength="255"') . ($req ? ' required' : '') . $counter . '>' . $h . '</div>';
    }
}

/**
 * Validate a posted setting. Returns [value, error|null]. $file is an uploaded image (image fields).
 */
function site_setting_parse(string $key, array $def, array $post, string $folder = 'branding'): array
{
    [$type, $label] = $def;
    $o = $def[3] ?? [];
    $raw = $post[$key] ?? '';
    if (is_array($raw)) {
        $raw = '';
    }
    $multi = in_array($type, ['textarea', 'robots'], true);
    $v = clean_string((string) $raw, $multi);
    $req = !empty($o['required']);
    if ($type === 'checkbox') {
        return [$v === '1' ? '1' : '0', null];
    }
    if ($type === 'image') {
        $file = $_FILES[$key . '_upload'] ?? null;
        if ($file && !is_array($file['error'] ?? null) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $path = function_exists('admin_upload_image') ? admin_upload_image($key . '_upload', $folder) : null;
            return $path ? [$path, null] : [null, $label . ': the image could not be uploaded.'];
        }
        if ($v === '') {
            return ['', null];
        }
        return site_valid_image_path($v) ? [$v, null] : [null, $label . ': image not found - choose it from the media library or upload it again.'];
    }
    if ($v === '') {
        return $req ? [null, $label . ' is required.'] : ['', null];
    }
    if (isset($o['max']) && in_array($type, ['text', 'textarea'], true) && mb_strlen($v) > (int) $o['max']) {
        return [null, $label . ' may not be longer than ' . (int) $o['max'] . ' characters.'];
    }
    switch ($type) {
        case 'email':
            $v = mb_strtolower($v);
            return filter_var($v, FILTER_VALIDATE_EMAIL) ? [$v, null] : [null, $label . ': please enter a valid email address.'];
        case 'phone':
            return preg_match('/^\+?[\d][\d\s\-()]{6,19}$/', $v) ? [$v, null] : [null, $label . ': please enter a valid phone number.'];
        case 'digits':
            $d = preg_replace('/[\s\-+]/', '', $v);
            $min = (int) ($o['min'] ?? 1);
            $max = (int) ($o['max'] ?? 20);
            return preg_match('/^\d{' . $min . ',' . $max . '}$/', $d) ? [$d, null] : [null, $label . ': use ' . $min . '-' . $max . ' digits.'];
        case 'link':
            $err = null;
            $l = site_clean_link($v, $err);
            return $err ? [null, $label . ': ' . $err] : [$l, null];
        case 'https':
            return (preg_match('#^https://[^\s<>"\']+$#i', $v) && filter_var($v, FILTER_VALIDATE_URL)) ? [$v, null] : [null, $label . ': enter a full address starting with https://'];
        case 'map':
            if (preg_match('/src="([^"]+)"/i', $v, $m)) {
                $v = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            return (preg_match('#^https://www\.google\.com/maps/embed\?[^\s<>"\']+$#', $v) && filter_var($v, FILTER_VALIDATE_URL)) ? [$v, null]
                : [null, $label . ': only Google Maps embed links are accepted (https://www.google.com/maps/embed?…).'];
        case 'int':
            if (!preg_match('/^\d+$/', $v)) {
                return [null, $label . ' must be a whole number.'];
            }
            if ((isset($o['min']) && (int) $v < $o['min']) || (isset($o['max']) && (int) $v > $o['max'])) {
                return [null, $label . ' must be between ' . (int) ($o['min'] ?? 0) . ' and ' . (int) ($o['max'] ?? PHP_INT_MAX) . '.'];
            }
            return [(string) (int) $v, null];
        case 'date':
            $dt = DateTime::createFromFormat('Y-m-d', $v);
            return ($dt && $dt->format('Y-m-d') === $v) ? [$v, null] : [null, $label . ' must be a valid date.'];
        case 'datetime':
            $v = str_replace('T', ' ', $v);
            $dt = DateTime::createFromFormat('Y-m-d H:i', substr($v, 0, 16));
            return $dt ? [$dt->format('Y-m-d H:i'), null] : [null, $label . ' must be a valid date and time.'];
        case 'pan':
            $v = strtoupper($v);
            return preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $v) ? [$v, null] : [null, $label . ': enter a valid PAN, e.g. AAATP1234C.'];
        case 'ifsc':
            $v = strtoupper($v);
            return preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $v) ? [$v, null] : [null, $label . ': enter a valid 11-character IFSC, e.g. SBIN0001234.'];
        case 'upi':
            return preg_match('/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z][a-zA-Z0-9]{1,63}$/', $v) ? [$v, null] : [null, $label . ': enter a valid UPI ID, e.g. name@bank.'];
        case 'presets':
            $nums = array_values(array_filter(array_map('trim', explode(',', str_replace(['₹', ' '], '', $v))), 'strlen'));
            foreach ($nums as $n) {
                if (!preg_match('/^\d{1,7}$/', $n) || (int) $n < 1) {
                    return [null, $label . ': use whole rupee amounts separated by commas, e.g. 500,1000,2500.'];
                }
            }
            $nums = array_unique(array_map('intval', $nums));
            sort($nums);
            return [implode(',', array_slice($nums, 0, 8)), null];
        case 'ga':
            $v = strtoupper($v);
            return preg_match('/^G-[A-Z0-9]{4,15}$/', $v) ? [$v, null] : [null, $label . ': a GA4 measurement ID looks like G-ABC123XYZ9.'];
        case 'gsv':
            if (preg_match('/content=["\']([^"\']+)["\']/i', $v, $m)) {
                $v = $m[1];
            }
            return preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $v) ? [$v, null] : [null, $label . ': paste the verification code from Google Search Console.'];
        case 'handle':
            $v = '@' . ltrim($v, '@');
            return preg_match('/^@[A-Za-z0-9_]{1,15}$/', $v) ? [$v, null] : [null, $label . ': a handle looks like @name (letters, numbers, underscore).'];
        case 'robots':
            $lines = [];
            foreach (preg_split('/\n/', $v) as $n => $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    $lines[] = $line;
                    continue;
                }
                if (!preg_match('#^(User-agent|Disallow|Allow|Crawl-delay|Sitemap)\s*:\s*[^\s<>"]*$#i', $line)) {
                    return [null, $label . ': line ' . ($n + 1) . ' is not a valid robots.txt rule.'];
                }
                $lines[] = $line;
            }
            return [trim(implode("\n", $lines)), null];
        case 'select':
            return array_key_exists($v, $o['options'] ?? []) ? [$v, null] : [null, $label . ': please choose a valid option.'];
        default:
            return [$v, null];
    }
}

/**
 * Save a group of settings from POST. Returns [changedKeys, errors]. Nothing is saved when there are errors.
 */
function site_save_settings(array $fields, array $post, string $folder = 'branding'): array
{
    $values = [];
    $errors = [];
    foreach ($fields as $key => $def) {
        if ($def[0] === 'heading') {
            continue;
        }
        [$v, $err] = site_setting_parse($key, $def, $post, $folder);
        if ($err) {
            $errors[$key] = $err;
        } else {
            $values[$key] = (string) $v;
        }
    }
    if ($errors) {
        return [[], $errors];
    }
    $changed = [];
    foreach ($values as $key => $v) {
        if (setting($key) !== $v || !array_key_exists($key, settings_all())) {
            save_setting($key, $v);
            $changed[] = $key;
        }
    }
    return [$changed, []];
}

// =====================================================================
// Module section types: timeline, logo_strip, image_cards
// =====================================================================

function section_schema_timeline(): array
{
    return [
        'label' => 'Timeline / milestones',
        'fields' => ['pill', 'title', 'intro'],
        'items' => [
            'year'  => ['text', 'Year or date (e.g. 2025 or March 2026)'],
            'title' => ['text', 'Milestone'],
            'text'  => ['textarea', 'Details (optional)'],
        ],
        'settings' => ['empty_text' => ['text', 'Message while there are no milestones (leave blank to hide the section)']],
        'help' => 'Add only real milestones with their actual year or date, after they have been verified. Nothing is shown until you add one (unless you enter a message for the empty state).',
    ];
}

/** Milestones entered by the admin, oldest first as entered. */
function section_timeline(array $s, array $items, array $set, array $page): string
{
    $items = array_values(array_filter($items, function ($i) {
        return is_array($i) && trim((string) ($i['title'] ?? '')) !== '';
    }));
    if (!$items) {
        return !empty($set['empty_text']) ? empty_state((string) $set['empty_text']) : '';
    }
    $html = '<ol class="timeline site-timeline" style="max-width:820px">';
    foreach ($items as $i => $it) {
        $html .= '<li class="done reveal" style="--d:' . ($i % 4) . ';padding-bottom:26px">'
            . (!empty($it['year']) ? '<span class="badge badge-green">' . e($it['year']) . '</span>' : '')
            . '<h3 style="margin:.4rem 0 .25rem;font-size:1.08rem;color:var(--green-800)">' . e($it['title']) . '</h3>'
            . (!empty($it['text']) ? '<p style="margin:0;color:var(--muted)">' . e_nl($it['text']) . '</p>' : '')
            . '</li>';
    }
    return $html . '</ol>';
}

function section_schema_logo_strip(): array
{
    return [
        'label' => 'Logos (partners / supporters)',
        'fields' => ['pill', 'title', 'intro'],
        'items' => [
            'image' => ['image', 'Logo'],
            'name'  => ['text', 'Organisation name (also used as the image description)'],
            'url'   => ['url', 'Website (optional)'],
        ],
        'settings' => ['grayscale' => ['checkbox', 'Show logos in grey until hovered']],
        'help' => 'List only organisations that have formally agreed to be named as partners or supporters, with their permission to use their logo.',
    ];
}

function section_logo_strip(array $s, array $items, array $set, array $page): string
{
    $items = array_values(array_filter($items, function ($i) {
        return is_array($i) && !empty($i['image']) && site_valid_image_path((string) $i['image']) && trim((string) ($i['name'] ?? '')) !== '';
    }));
    if (!$items) {
        return '';
    }
    $html = '<ul class="site-logo-strip" style="list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:16px;justify-content:center;align-items:stretch">';
    foreach ($items as $i => $it) {
        $img = '<img src="' . e(url($it['image'])) . '" alt="' . e($it['name']) . '" loading="lazy" style="max-height:64px;max-width:100%;width:auto;height:auto;object-fit:contain' . (!empty($set['grayscale']) ? ';filter:grayscale(1);opacity:.85' : '') . '">';
        $box = 'display:flex;align-items:center;justify-content:center;height:100%;min-height:96px;padding:14px 18px;background:#fff;border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow-sm)';
        $err = null;
        $href = !empty($it['url']) ? site_clean_link((string) $it['url'], $err) : '';
        $inner = ($href !== '' && !$err)
            ? '<a href="' . e(safe_link($href)) . '"' . (preg_match('#^https?://#i', $href) ? ' target="_blank" rel="noopener"' : '') . ' style="' . $box . '" title="' . e($it['name']) . '">' . $img . '</a>'
            : '<div style="' . $box . '">' . $img . '</div>';
        $html .= '<li class="reveal" style="--d:' . ($i % 4) . ';flex:0 1 190px;min-width:130px">' . $inner . '</li>';
    }
    return $html . '</ul>';
}

function section_schema_image_cards(): array
{
    return [
        'label' => 'Image cards',
        'fields' => ['pill', 'title', 'intro'],
        'items' => [
            'image'      => ['image', 'Image'],
            'title'      => ['text', 'Title'],
            'text'       => ['textarea', 'Text'],
            'link'       => ['url', 'Link (optional)'],
            'link_label' => ['text', 'Link text (default: Learn more)'],
        ],
        'settings' => ['cols' => ['select', 'Columns', ['' => 'Auto', '2' => '2', '3' => '3', '4' => '4']]],
        'help' => 'Use photos the foundation owns. Publish identifiable photos of children or vulnerable people only with documented consent.',
    ];
}

function section_image_cards(array $s, array $items, array $set, array $page): string
{
    $items = array_values(array_filter($items, function ($i) {
        return is_array($i) && trim((string) ($i['title'] ?? '')) !== '';
    }));
    if (!$items) {
        return '';
    }
    $cols = (int) ($set['cols'] ?? 0) ?: (count($items) % 3 === 0 || count($items) > 4 ? 3 : min(4, max(2, count($items))));
    $html = '<div class="grid grid-' . max(1, min(4, $cols)) . ' site-image-cards">';
    foreach ($items as $i => $it) {
        $err = null;
        $clean = !empty($it['link']) ? site_clean_link((string) $it['link'], $err) : '';
        $link = ($clean !== '' && !$err) ? safe_link($clean) : '';   // unsafe links are dropped, never sent to "/"
        $ext = $link !== '' && preg_match('#^https?://#i', $clean);
        $attr = $ext ? ' target="_blank" rel="noopener"' : '';
        $html .= '<article class="blog-card reveal tilt" style="--d:' . ($i % 4) . '">';
        if (!empty($it['image']) && site_valid_image_path((string) $it['image'])) {
            $img = '<img src="' . e(url($it['image'])) . '" alt="" loading="lazy" width="640" height="400">';
            $html .= $link !== '' ? '<a class="blog-media" href="' . e($link) . '"' . $attr . ' tabindex="-1" aria-hidden="true">' . $img . '</a>' : '<div class="blog-media">' . $img . '</div>';
        }
        $html .= '<div class="blog-body"><h3>' . ($link !== '' ? '<a href="' . e($link) . '"' . $attr . '>' . e($it['title']) . '</a>' : e($it['title'])) . '</h3>'
            . (!empty($it['text']) ? '<p>' . e($it['text']) . '</p>' : '')
            . ($link !== '' ? '<a class="link-arrow" href="' . e($link) . '"' . $attr . '>' . e($it['link_label'] ?? 'Learn more') . ' ' . icon('arrow-right') . '</a>' : '')
            . '</div></article>';
    }
    return $html . '</div>';
}
