<?php
/**
 * Media library service (unit cms_content): uploads into the `media` table, reference checks ("where is this file
 * used?"), safe replacement and deletion. Shared by api/routes/media.php, api/routes/gallery.php and the CRUD modules
 * of the CMS content screens (blog, gallery, testimonials).
 *
 *   $row  = media_store_upload($_FILES['file'], 'blog', ['alt_text' => '...']);   // validated + inserted, returns media_out()
 *   $refs = media_usage($row);                                                    // [['label' => 'Blog post', 'title' => ..], ...]
 *   media_valid_ref('assets/uploads/media/2026/10/ab12.jpg')                      // true for library/bundled paths and http(s) URLs
 */

/** Folders used to organise the library (key => label). */
function media_folders(): array
{
    return [
        'general' => 'General', 'campus' => 'Campus', 'programs' => 'Programs', 'blog' => 'Blog & News', 'gallery' => 'Gallery',
        'events' => 'Events', 'placements' => 'Placements', 'people' => 'People', 'testimonials' => 'Testimonials',
        'banners' => 'Banners', 'documents' => 'Documents & Brochures', 'videos' => 'Videos',
        // Images uploaded from the page builder (cms_register_media() in app/services/cms.php)
        'page-builder' => 'Page Builder',
    ];
}

/** Library type for a file extension: image | video | pdf | document | other. */
function media_type_for_ext(string $ext): string
{
    $ext = strtolower($ext);
    $t = upload_types();
    if (isset($t['image'][$ext])) {
        return 'image';
    }
    if (isset($t['video'][$ext])) {
        return 'video';
    }
    if ($ext === 'pdf') {
        return 'pdf';
    }
    if (isset($t['document'][$ext])) {
        return 'document';
    }
    return 'other';
}

/** upload_file() category for an extension (gives the right size limit per type). */
function media_upload_category(string $ext): ?string
{
    $type = media_type_for_ext($ext);
    return match ($type) {
        'image' => 'image',
        'video' => 'video',
        'pdf', 'document' => 'document',
        default => null,
    };
}

/** Allowed extensions + limits for the uploader UI. */
function media_upload_rules(): array
{
    $t = upload_types();
    return [
        'image' => ['extensions' => array_keys($t['image']), 'max_bytes' => upload_max_bytes('image')],
        'video' => ['extensions' => array_keys($t['video']), 'max_bytes' => upload_max_bytes('video')],
        'document' => ['extensions' => array_keys($t['document']), 'max_bytes' => upload_max_bytes('document')],
    ];
}

/** Public URL + display helpers for a media row. */
function media_out(array $row): array
{
    $row['id'] = (int) $row['id'];
    foreach (['size_bytes', 'width', 'height', 'uploaded_by'] as $k) {
        if (isset($row[$k])) {
            $row[$k] = (int) $row[$k];
        }
    }
    $row['url'] = upload_url($row['path']);
    $row['absolute_url'] = preg_match('#^https?://#i', (string) $row['path']) ? $row['path'] : absolute_url($row['path']);
    $row['size_label'] = human_filesize((int) ($row['size_bytes'] ?? 0));
    $row['is_bundled'] = !str_starts_with((string) $row['path'], 'assets/uploads/');
    return $row;
}

function media_find(int $id): ?array
{
    $row = db_row("SELECT m.*, u.name AS uploaded_by_name FROM media m LEFT JOIN users u ON u.id = m.uploaded_by WHERE m.id = ?", [$id]);
    return $row ? media_out($row) : null;
}

/** Title from a file name: "campus_main-photo.JPG" -> "Campus Main Photo". */
function media_title_from_name(string $name): string
{
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = trim(preg_replace('/[\s_\-]+/', ' ', $base));
    return mb_substr($base === '' ? 'Untitled' : ucwords($base), 0, 190);
}

/**
 * Validate + store one uploaded file and register it in the library.
 * @return array media row (media_out) on success
 * @throws CrudValidationException with a 'file' error
 */
function media_store_upload(?array $file, string $folder = 'general', array $meta = []): array
{
    $name = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $cat = media_upload_category($ext);
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new CrudValidationException(['file' => 'Choose a file to upload.'], 'Choose a file to upload.');
    }
    if ($cat === null) {
        $allowed = array_keys(upload_allowed('media'));
        throw new CrudValidationException(['file' => 'File type .' . $ext . ' is not allowed. Allowed: ' . implode(', ', $allowed) . '.'], 'This file type is not allowed.');
    }
    if (!isset(media_folders()[$folder])) {
        $folder = 'general';
    }
    $res = upload_file($file, $cat, 'media/' . $folder);
    if (!$res['ok']) {
        throw new CrudValidationException(['file' => $res['error']], $res['error']);
    }
    $title = trim((string) ($meta['title'] ?? '')) ?: media_title_from_name($res['original_name']);
    try {
        $id = db_insert('media', [
            'filename' => basename($res['path']),
            'original_name' => $res['original_name'],
            'path' => $res['path'],
            'mime' => $res['mime'],
            'extension' => $res['extension'],
            'size_bytes' => $res['size'],
            'type' => media_type_for_ext($res['extension']),
            'width' => $res['width'] ?: null,
            'height' => $res['height'] ?: null,
            'alt_text' => mb_substr(trim((string) ($meta['alt_text'] ?? '')), 0, 255) ?: null,
            'title' => mb_substr($title, 0, 190),
            'caption' => mb_substr(trim((string) ($meta['caption'] ?? '')), 0, 500) ?: null,
            'folder' => $folder,
            'uploaded_by' => user_id(),
        ]);
    } catch (Throwable $e) {
        delete_upload($res['path']);
        throw $e;
    }
    log_activity('upload', 'media', $id, 'Uploaded ' . media_type_for_ext($res['extension']) . ' "' . $res['original_name'] . '" (' . human_filesize((int) $res['size']) . ') to ' . media_folders()[$folder]);
    return media_find($id);
}

/**
 * Is $value an acceptable file reference for CMS fields (thumbnail, cover, photo, og image)?
 * Accepts relative paths inside assets/ (library uploads + bundled photography) and absolute http(s) URLs.
 */
function media_valid_ref(?string $value): bool
{
    $value = trim((string) $value);
    if ($value === '') {
        return true;
    }
    if (preg_match('#^https?://[^\s"<>]+$#i', $value)) {
        return (bool) filter_var($value, FILTER_VALIDATE_URL);
    }
    return (bool) preg_match('#^assets/[A-Za-z0-9_\-./]+\.[A-Za-z0-9]{2,5}$#', $value) && !str_contains($value, '..');
}

/** Columns that may store a media path verbatim: [table, column, label, title expression, admin link template]. */
function media_ref_columns(): array
{
    return [
        ['blog_posts', 'thumbnail', 'Blog post thumbnail', 'title', '/cms/blog/{id}'],
        ['galleries', 'cover_image', 'Gallery cover', 'title', '/cms/gallery/{id}'],
        ['gallery_items', 'image', 'Gallery photo', "CONCAT('Item #', id)", null],
        ['testimonials', 'photo', 'Testimonial photo', 'name', '/cms/testimonials'],
        ['banners', 'desktop_image', 'Banner (desktop)', 'title', '/cms/banners'],
        ['banners', 'mobile_image', 'Banner (mobile)', 'title', '/cms/banners'],
        ['pages', 'banner_image', 'Page banner', 'title', '/cms/pages'],
        ['seo_settings', 'og_image', 'SEO social image', "CONCAT(entity_type, ' ', COALESCE(entity_id, route, ''))", '/cms/seo'],
        ['events', 'image', 'Event image', 'title', '/events'],
        ['programs', 'image', 'Program image', 'name', '/academics?tab=programs'],
        ['programs', 'brochure', 'Program brochure', 'name', '/academics?tab=programs'],
        ['departments', 'image', 'Department image', 'name', '/academics?tab=departments'],
        ['alumni_events', 'image', 'Alumni event', 'title', '/alumni/events'],
        ['alumni_success_stories', 'image', 'Alumni story', 'title', '/alumni/stories'],
        ['notices', 'attachment', 'Notice attachment', 'title', '/notices'],
    ];
}

/** Text columns that may embed a media path inside HTML/JSON: [table, column, label, title expression, link]. */
function media_ref_text_columns(): array
{
    return [
        ['blog_posts', 'content', 'Blog post content', 'title', '/cms/blog/{id}'],
        ['pages', 'content', 'Page content', 'title', '/cms/pages'],
        ['pages', 'blocks', 'Page builder block', 'title', '/cms/pages'],
        ['homepage_sections', 'content', 'Home page section', 'section_key', '/cms/homepage'],
    ];
}

/**
 * Where is a media item used? Returns at most $limit references.
 * @return array<int, array{label:string,title:string,id:int|null,link:?string}>
 */
function media_usage(array $media, int $limit = 50): array
{
    $path = (string) $media['path'];
    $out = [];
    $add = function (array $rows, string $label, ?string $link) use (&$out) {
        foreach ($rows as $r) {
            $out[] = ['label' => $label, 'title' => (string) ($r['t'] ?? ''), 'id' => isset($r['id']) ? (int) $r['id'] : null,
                'link' => $link ? str_replace('{id}', (string) ($r['id'] ?? ''), $link) : null];
        }
    };
    // Gallery items linked by media id
    $add(db_all('SELECT g.id, g.title AS t FROM gallery_items gi JOIN galleries g ON g.id = gi.gallery_id WHERE gi.media_id = ? OR gi.image = ? GROUP BY g.id, g.title LIMIT 20', [(int) $media['id'], $path]), 'Gallery album', '/cms/gallery/{id}');
    foreach (media_ref_columns() as [$table, $col, $label, $title, $link]) {
        if ($table === 'gallery_items' || !db_table_exists($table) || !in_array($col, db_table_columns($table), true)) {
            continue;
        }
        $add(db_all('SELECT id, ' . $title . ' AS t FROM ' . db_quote_ident($table) . ' WHERE ' . db_quote_ident($col) . ' = ? LIMIT 20', [$path]), $label, $link);
    }
    foreach (media_ref_text_columns() as [$table, $col, $label, $title, $link]) {
        if (!db_table_exists($table) || !in_array($col, db_table_columns($table), true)) {
            continue;
        }
        $add(db_all('SELECT id, ' . $title . ' AS t FROM ' . db_quote_ident($table) . ' WHERE ' . db_quote_ident($col) . ' LIKE ? LIMIT 20', ['%' . $path . '%']), $label, $link);
    }
    if (db_table_exists('settings')) {
        $add(db_all('SELECT id, `key` AS t FROM settings WHERE `value` = ? LIMIT 10', [$path]), 'Site setting', '/settings');
    }
    return array_slice($out, 0, $limit);
}

/** Point every verbatim reference (and rich-text embeds) from $old to $new. Returns rows changed. */
function media_replace_refs(string $old, string $new, int $mediaId): int
{
    $n = 0;
    foreach (media_ref_columns() as [$table, $col]) {
        if (!db_table_exists($table) || !in_array($col, db_table_columns($table), true)) {
            continue;
        }
        $n += db_exec('UPDATE ' . db_quote_ident($table) . ' SET ' . db_quote_ident($col) . ' = ? WHERE ' . db_quote_ident($col) . ' = ?', [$new, $old]);
    }
    foreach ([['blog_posts', 'content'], ['pages', 'content']] as [$table, $col]) {
        if (db_table_exists($table)) {
            $n += db_exec('UPDATE ' . db_quote_ident($table) . ' SET ' . db_quote_ident($col) . ' = REPLACE(' . db_quote_ident($col) . ', ?, ?) WHERE ' . db_quote_ident($col) . ' LIKE ?', [$old, $new, '%' . $old . '%']);
        }
    }
    if (db_table_exists('settings')) {
        $n += db_exec('UPDATE settings SET `value` = ? WHERE `value` = ?', [$new, $old]);
    }
    return $n;
}

/**
 * Replace the file behind a media item. Library uploads keep their URL when the extension is unchanged (the file is
 * swapped in place); otherwise the item gets a new path and every reference is rewritten. Bundled files are never
 * modified.
 */
function media_replace_file(array $media, ?array $file): array
{
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $cat = media_upload_category($ext);
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new CrudValidationException(['file' => 'Choose a file to upload.'], 'Choose a file to upload.');
    }
    if ($cat === null) {
        throw new CrudValidationException(['file' => 'File type .' . $ext . ' is not allowed.'], 'This file type is not allowed.');
    }
    $oldType = (string) $media['type'];
    $newType = media_type_for_ext($ext);
    if ($oldType === 'image' && $newType !== 'image') {
        throw new CrudValidationException(['file' => 'An image can only be replaced with another image.'], 'An image can only be replaced with another image.');
    }
    $folder = isset(media_folders()[$media['folder'] ?? '']) ? $media['folder'] : 'general';
    $res = upload_file($file, $cat, 'media/' . $folder);
    if (!$res['ok']) {
        throw new CrudValidationException(['file' => $res['error']], $res['error']);
    }
    $old = (string) $media['path'];
    $oldExt = strtolower(pathinfo($old, PATHINFO_EXTENSION));
    $inPlace = str_starts_with($old, 'assets/uploads/') && $oldExt === $res['extension'] && is_file(APP_ROOT . '/' . $old);
    $path = $res['path'];
    if ($inPlace && @rename(APP_ROOT . '/' . $res['path'], APP_ROOT . '/' . $old)) {
        $path = $old;
    } else {
        $inPlace = false;
    }
    db_transaction(function () use ($media, $res, $path, $old, $inPlace) {
        db_update('media', [
            'path' => $path, 'filename' => basename($path), 'original_name' => $res['original_name'], 'mime' => $res['mime'], 'extension' => $res['extension'],
            'size_bytes' => $res['size'], 'type' => media_type_for_ext($res['extension']), 'width' => $res['width'] ?: null, 'height' => $res['height'] ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $media['id']]);
        if (!$inPlace) {
            media_replace_refs($old, $path, (int) $media['id']);
        }
    });
    if (!$inPlace && str_starts_with($old, 'assets/uploads/')) {
        delete_upload($old);
    }
    log_activity('update', 'media', (int) $media['id'], 'Replaced file of media "' . ($media['title'] ?: $media['original_name']) . '" with ' . $res['original_name']);
    return media_find((int) $media['id']);
}

/** Delete a media item (row + uploaded file). Bundled files stay on disk. */
function media_delete(array $media): void
{
    db_transaction(function () use ($media) {
        // Gallery items that only pointed at this file would become empty tiles - remove them with the file.
        db_exec("DELETE FROM gallery_items WHERE media_id = ? AND (image IS NULL OR image = '') AND (video_url IS NULL OR video_url = '')", [(int) $media['id']]);
        // Clear references held by this unit's own content so the website falls back to defaults instead of broken images.
        $path = (string) $media['path'];
        db_exec('UPDATE galleries SET cover_image = NULL WHERE cover_image = ?', [$path]);
        db_exec('UPDATE gallery_items SET image = NULL WHERE image = ?', [$path]);
        db_exec("DELETE FROM gallery_items WHERE (image IS NULL OR image = '') AND media_id IS NULL AND (video_url IS NULL OR video_url = '')");
        db_exec('UPDATE blog_posts SET thumbnail = NULL WHERE thumbnail = ?', [$path]);
        db_exec('UPDATE testimonials SET photo = NULL WHERE photo = ?', [$path]);
        db_exec("UPDATE seo_settings SET og_image = NULL WHERE entity_type = 'post' AND og_image = ?", [$path]);
        db_delete('media', 'id = ?', [(int) $media['id']]);
    });
    delete_upload((string) $media['path']);
    log_activity('delete', 'media', (int) $media['id'], 'Deleted media "' . ($media['title'] ?: $media['original_name'] ?: $media['filename']) . '"');
}

/** Library statistics for KPI cards and filter counts. */
function media_stats(): array
{
    $byType = db_pairs('SELECT type, COUNT(*) FROM media GROUP BY type');
    $byFolder = db_pairs('SELECT COALESCE(folder, \'general\'), COUNT(*) FROM media GROUP BY COALESCE(folder, \'general\')');
    $folders = [];
    foreach (media_folders() as $k => $label) {
        $folders[] = ['value' => $k, 'label' => $label, 'count' => (int) ($byFolder[$k] ?? 0)];
    }
    return [
        'total' => (int) array_sum($byType),
        'by_type' => ['image' => (int) ($byType['image'] ?? 0), 'video' => (int) ($byType['video'] ?? 0), 'pdf' => (int) ($byType['pdf'] ?? 0),
            'document' => (int) ($byType['document'] ?? 0), 'other' => (int) ($byType['other'] ?? 0)],
        'total_bytes' => (int) db_value('SELECT COALESCE(SUM(size_bytes), 0) FROM media'),
        'this_month' => (int) db_value('SELECT COUNT(*) FROM media WHERE created_at >= ?', [date('Y-m-01')]),
        'missing_alt' => (int) db_value("SELECT COUNT(*) FROM media WHERE type = 'image' AND (alt_text IS NULL OR alt_text = '')"),
        'folders' => $folders,
        'rules' => media_upload_rules(),
    ];
}

/* =====================================================================================================================
 * CMS content helpers (blog SEO panel, scheduled publishing, manual ordering) - used by the blog/FAQ/testimonial/gallery
 * modules and routes of the same unit.
 * ===================================================================================================================== */

/** Promote scheduled posts whose publish time has passed. Cheap (indexed) and idempotent. */
function cms_blog_publish_due(): int
{
    return db_exec("UPDATE blog_posts SET status = 'published' WHERE status = 'scheduled' AND published_at IS NOT NULL AND published_at <= ?", [date('Y-m-d H:i:s')]);
}

function cms_seo_robots_options(): array
{
    return ['index,follow' => 'Index, follow (default)', 'noindex,follow' => 'No index, follow', 'index,nofollow' => 'Index, no follow', 'noindex,nofollow' => 'No index, no follow'];
}

function cms_seo_changefreq_options(): array
{
    return ['always' => 'Always', 'hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly', 'never' => 'Never'];
}

/** SEO settings for an entity (defaults when none saved yet). */
function cms_seo_get(string $entityType, int $entityId): array
{
    $row = db_row('SELECT * FROM seo_settings WHERE entity_type = ? AND entity_id = ?', [$entityType, $entityId]);
    $defaults = ['meta_title' => '', 'meta_description' => '', 'meta_keywords' => '', 'canonical_url' => '', 'og_title' => '', 'og_description' => '', 'og_image' => '',
        'robots' => 'index,follow', 'schema_json' => '', 'include_in_sitemap' => true, 'sitemap_priority' => '0.6', 'sitemap_changefreq' => 'monthly'];
    if (!$row) {
        return $defaults + ['exists' => false];
    }
    $out = [];
    foreach ($defaults as $k => $d) {
        $out[$k] = $row[$k] ?? $d;
    }
    $out['include_in_sitemap'] = (bool) $row['include_in_sitemap'];
    $out['sitemap_priority'] = number_format((float) $row['sitemap_priority'], 1);
    $out['exists'] = true;
    $out['updated_at'] = $row['updated_at'];
    return $out;
}

/**
 * Validate + normalise an SEO panel payload. Errors are keyed "seo.<field>".
 * @return array [clean, errors]
 */
function cms_seo_validate(array $seo): array
{
    $s = fn ($k) => trim((string) ($seo[$k] ?? ''));
    $clean = [
        'meta_title' => $s('meta_title'), 'meta_description' => $s('meta_description'), 'meta_keywords' => $s('meta_keywords'), 'canonical_url' => $s('canonical_url'),
        'og_title' => $s('og_title'), 'og_description' => $s('og_description'), 'og_image' => $s('og_image'), 'robots' => $s('robots') ?: 'index,follow',
        'schema_json' => $s('schema_json'),
        'include_in_sitemap' => in_array($seo['include_in_sitemap'] ?? true, [true, 1, '1', 'true', 'on', 'yes'], true) ? 1 : 0,
        'sitemap_priority' => $s('sitemap_priority') === '' ? 0.6 : $s('sitemap_priority'),
        'sitemap_changefreq' => $s('sitemap_changefreq') ?: 'monthly',
    ];
    $errors = [];
    foreach (['meta_title' => 190, 'meta_description' => 320, 'meta_keywords' => 255, 'canonical_url' => 255, 'og_title' => 190, 'og_description' => 320, 'og_image' => 255] as $k => $max) {
        if (mb_strlen($clean[$k]) > $max) {
            $errors['seo.' . $k] = label_from_key($k) . " may not be longer than $max characters.";
        }
    }
    if ($clean['canonical_url'] !== '' && !filter_var($clean['canonical_url'], FILTER_VALIDATE_URL) && !preg_match('#^/[A-Za-z0-9_\-./?=&%]*$#', $clean['canonical_url'])) {
        $errors['seo.canonical_url'] = 'Canonical URL must be a full URL (https://…) or a site path starting with /.';
    }
    if (!media_valid_ref($clean['og_image'])) {
        $errors['seo.og_image'] = 'Choose the social image from the media library.';
    }
    if (!isset(cms_seo_robots_options()[$clean['robots']])) {
        $errors['seo.robots'] = 'Select a valid robots directive.';
    }
    if (!isset(cms_seo_changefreq_options()[$clean['sitemap_changefreq']])) {
        $errors['seo.sitemap_changefreq'] = 'Select a valid change frequency.';
    }
    if (!is_numeric($clean['sitemap_priority']) || (float) $clean['sitemap_priority'] < 0 || (float) $clean['sitemap_priority'] > 1) {
        $errors['seo.sitemap_priority'] = 'Priority must be between 0.0 and 1.0.';
    } else {
        $clean['sitemap_priority'] = round((float) $clean['sitemap_priority'], 1);
    }
    if ($clean['schema_json'] !== '') {
        $decoded = json_decode($clean['schema_json'], true);
        if (!is_array($decoded)) {
            $errors['seo.schema_json'] = 'Schema must be valid JSON-LD (an object or an array).';
        } else {
            $clean['schema_json'] = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
    foreach ($clean as $k => $v) {
        if ($v === '' && !in_array($k, ['robots', 'sitemap_changefreq'], true)) {
            $clean[$k] = null;
        }
    }
    return [$clean, $errors];
}

/** Insert/update the SEO row of an entity (validated payload from cms_seo_validate). */
function cms_seo_save(string $entityType, int $entityId, array $clean): void
{
    $id = db_value('SELECT id FROM seo_settings WHERE entity_type = ? AND entity_id = ?', [$entityType, $entityId]);
    if ($id) {
        db_update('seo_settings', $clean, 'id = ?', [(int) $id]);
    } else {
        db_insert('seo_settings', $clean + ['entity_type' => $entityType, 'entity_id' => $entityId]);
    }
}

/**
 * Persist a manual order: $ids in the desired order get sort_order 10, 20, 30 ... (only ids that exist in $table).
 * @return int rows updated
 */
function cms_reorder(string $table, array $ids, string $column = 'sort_order', ?string $scopeColumn = null, $scopeValue = null): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $n = 0;
    db_transaction(function () use ($table, $ids, $column, $scopeColumn, $scopeValue, &$n) {
        foreach ($ids as $i => $id) {
            $sql = 'UPDATE ' . db_quote_ident($table) . ' SET ' . db_quote_ident($column) . ' = ? WHERE id = ?' . ($scopeColumn ? ' AND ' . db_quote_ident($scopeColumn) . ' = ?' : '');
            $n += db_exec($sql, $scopeColumn ? [($i + 1) * 10, $id, $scopeValue] : [($i + 1) * 10, $id]);
        }
    });
    return $n;
}
