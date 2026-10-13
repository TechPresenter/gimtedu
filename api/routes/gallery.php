<?php
/**
 * Gallery albums API (unit cms_content). Album CRUD uses /api/crud/galleries; these endpoints manage the items.
 *   GET    /api/gallery/stats                        KPIs + per-category counts
 *   GET    /api/gallery/{id}                         album + ordered items (url, caption, media info)
 *   POST   /api/gallery/{id}/items                   {media_ids[]} add library files | {video_url, caption} add a video
 *   POST   /api/gallery/{id}/items/remove            {ids[]} remove items (files stay in the library)
 *   POST   /api/gallery/{id}/reorder                 {ids[]} new order
 *   POST   /api/gallery/{id}/cover                   {item_id} use an item as the album cover | {item_id: null} reset
 *   POST   /api/gallery/items/{itemId}               {caption}
 *   DELETE /api/gallery/items/{itemId}
 */

require_once APP_ROOT . '/app/services/media.php';

function gallery_or_404(int $id): array
{
    $g = db_row('SELECT * FROM galleries WHERE id = ?', [$id]);
    if (!$g) {
        api_error('This album no longer exists.', 404);
    }
    return $g;
}

function gallery_items_out(int $galleryId): array
{
    $rows = db_all("SELECT gi.id, gi.gallery_id, gi.media_id, gi.image, gi.video_url, gi.caption, gi.sort_order,
                           COALESCE(NULLIF(gi.image, ''), m.path) AS path, m.title AS media_title, m.alt_text, m.type AS media_type, m.width, m.height, m.size_bytes, m.original_name
                    FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id
                    WHERE gi.gallery_id = ? ORDER BY gi.sort_order, gi.id", [$galleryId]);
    return array_map(function ($r) {
        $video = (string) ($r['video_url'] ?? '');
        $kind = $video !== '' || $r['media_type'] === 'video' ? 'video' : 'photo';
        $youtube = null;
        if ($video !== '' && preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_\-]{6,})~', $video, $m)) {
            $youtube = $m[1];
        }
        return [
            'id' => (int) $r['id'], 'media_id' => $r['media_id'] !== null ? (int) $r['media_id'] : null, 'caption' => $r['caption'], 'sort_order' => (int) $r['sort_order'],
            'path' => $r['path'], 'url' => $r['path'] ? upload_url($r['path']) : null, 'video_url' => $video ?: null, 'youtube_id' => $youtube,
            'thumbnail' => $r['path'] && $r['media_type'] !== 'video' ? upload_url($r['path']) : ($youtube ? 'https://img.youtube.com/vi/' . $youtube . '/hqdefault.jpg' : null),
            'kind' => $kind, 'title' => $r['media_title'] ?: $r['original_name'], 'alt_text' => $r['alt_text'], 'width' => $r['width'] ? (int) $r['width'] : null,
            'height' => $r['height'] ? (int) $r['height'] : null, 'size_label' => $r['size_bytes'] ? human_filesize((int) $r['size_bytes']) : null,
        ];
    }, $rows);
}

route('GET', '/gallery/stats', function () {
    api_require('media', 'view');
    api_ok([
        'albums' => (int) db_value('SELECT COUNT(*) FROM galleries'),
        'published' => (int) db_value("SELECT COUNT(*) FROM galleries WHERE status = 'published'"),
        'drafts' => (int) db_value("SELECT COUNT(*) FROM galleries WHERE status <> 'published'"),
        'items' => (int) db_value('SELECT COUNT(*) FROM gallery_items'),
        'videos' => (int) db_value("SELECT COUNT(*) FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id WHERE (gi.video_url IS NOT NULL AND gi.video_url <> '') OR m.type = 'video'"),
        'empty' => (int) db_value('SELECT COUNT(*) FROM galleries g WHERE NOT EXISTS (SELECT 1 FROM gallery_items gi WHERE gi.gallery_id = g.id)'),
        'by_category' => db_pairs('SELECT COALESCE(category, \'campus\'), COUNT(*) FROM galleries GROUP BY COALESCE(category, \'campus\')'),
    ]);
});

route('GET', '/gallery/{id:\d+}', function ($p) {
    api_require('media', 'view');
    $g = gallery_or_404((int) $p['id']);
    $items = gallery_items_out((int) $g['id']);
    $g['id'] = (int) $g['id'];
    $g['sort_order'] = (int) $g['sort_order'];
    $g['cover_url'] = $g['cover_image'] ? upload_url($g['cover_image']) : ($items[0]['thumbnail'] ?? null);
    $g['public_url'] = absolute_url('gallery/' . $g['slug']);
    api_ok(['album' => $g, 'items' => $items]);
});

route('POST', '/gallery/{id:\d+}/items', function ($p) {
    api_require('media', 'edit');
    $g = gallery_or_404((int) $p['id']);
    $in = api_input();
    $next = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM gallery_items WHERE gallery_id = ?', [(int) $g['id']]);
    $mediaIds = array_values(array_unique(array_filter(array_map('intval', (array) ($in['media_ids'] ?? [])))));
    if ($mediaIds) {
        $media = db_all('SELECT id, title, caption, alt_text, type FROM media WHERE id IN (' . implode(',', $mediaIds) . ") AND type IN ('image', 'video')");
        if (!$media) {
            api_error('Choose images or videos from the media library.', 422, ['media_ids' => 'Only images and videos can be added to an album.']);
        }
        $byId = array_column($media, null, 'id');
        $existing = array_map('intval', db_column('SELECT media_id FROM gallery_items WHERE gallery_id = ? AND media_id IS NOT NULL', [(int) $g['id']]));
        $added = 0;
        $dupes = 0;
        db_transaction(function () use ($mediaIds, $byId, $existing, $g, &$next, &$added, &$dupes) {
            foreach ($mediaIds as $mid) {
                if (!isset($byId[$mid])) {
                    continue;
                }
                if (in_array($mid, $existing, true)) {
                    $dupes++;
                    continue;
                }
                $m = $byId[$mid];
                $next += 10;
                db_insert('gallery_items', ['gallery_id' => (int) $g['id'], 'media_id' => $mid, 'caption' => mb_substr((string) ($m['caption'] ?: $m['title'] ?: ''), 0, 255) ?: null, 'sort_order' => $next]);
                $added++;
            }
        });
        log_activity('update', 'media', (int) $g['id'], 'Added ' . $added . ' item(s) to album "' . $g['title'] . '"');
        api_ok(['added' => $added, 'duplicates' => $dupes, 'items' => gallery_items_out((int) $g['id'])],
            $added . ' item' . ($added === 1 ? '' : 's') . ' added to the album.' . ($dupes ? ' ' . $dupes . ' already in the album.' : ''));
    }
    // A video by URL (YouTube / Vimeo / MP4)
    $v = api_validate(['video_url' => 'required|url|max:255', 'caption' => 'max:255'], $in, ['video_url' => 'Video URL']);
    if (!preg_match('#^https?://#i', (string) $v['video_url'])) {
        api_error('Please fix the highlighted fields and try again.', 422, ['video_url' => 'Video URL must start with https://']);
    }
    $id = db_insert('gallery_items', ['gallery_id' => (int) $g['id'], 'video_url' => $v['video_url'], 'caption' => $v['caption'], 'sort_order' => $next + 10]);
    log_activity('update', 'media', (int) $g['id'], 'Added a video to album "' . $g['title'] . '"');
    api_ok(['id' => $id, 'items' => gallery_items_out((int) $g['id'])], 'Video added to the album.', 201);
});

route('POST', '/gallery/{id:\d+}/items/remove', function ($p) {
    api_require('media', 'edit');
    $g = gallery_or_404((int) $p['id']);
    $ids = array_values(array_filter(array_map('intval', (array) api_param('ids', []))));
    if (!$ids) {
        api_error('Select at least one item.', 422);
    }
    $n = db_exec('DELETE FROM gallery_items WHERE gallery_id = ? AND id IN (' . implode(',', $ids) . ')', [(int) $g['id']]);
    log_activity('update', 'media', (int) $g['id'], 'Removed ' . $n . ' item(s) from album "' . $g['title'] . '"');
    api_ok(['removed' => $n, 'items' => gallery_items_out((int) $g['id'])], $n . ' item' . ($n === 1 ? '' : 's') . ' removed from the album. The files are still in the media library.');
});

route('POST', '/gallery/{id:\d+}/reorder', function ($p) {
    api_require('media', 'edit');
    $g = gallery_or_404((int) $p['id']);
    $ids = (array) api_param('ids', []);
    if (!$ids) {
        api_error('Nothing to reorder.', 422);
    }
    cms_reorder('gallery_items', $ids, 'sort_order', 'gallery_id', (int) $g['id']);
    log_activity('update', 'media', (int) $g['id'], 'Reordered photos of album "' . $g['title'] . '"');
    api_ok(['items' => gallery_items_out((int) $g['id'])], 'Order saved.');
});

route('POST', '/gallery/{id:\d+}/cover', function ($p) {
    api_require('media', 'edit');
    $g = gallery_or_404((int) $p['id']);
    $itemId = (int) api_param('item_id', 0);
    $path = null;
    if ($itemId) {
        $path = db_value("SELECT COALESCE(NULLIF(gi.image, ''), m.path) FROM gallery_items gi LEFT JOIN media m ON m.id = gi.media_id WHERE gi.id = ? AND gi.gallery_id = ? AND (m.type IS NULL OR m.type = 'image')",
            [$itemId, (int) $g['id']]);
        if (!$path) {
            api_error('Only photos of this album can be used as the cover.', 422);
        }
    }
    db_update('galleries', ['cover_image' => $path], 'id = ?', [(int) $g['id']]);
    log_activity('update', 'media', (int) $g['id'], ($path ? 'Changed cover of' : 'Reset cover of') . ' album "' . $g['title'] . '"');
    api_ok(['cover_image' => $path, 'cover_url' => $path ? upload_url($path) : null], $path ? 'Album cover updated.' : 'Cover reset to the first photo.');
});

route('POST', '/gallery/items/{item:\d+}', function ($p) {
    api_require('media', 'edit');
    $item = db_row('SELECT gi.*, g.title AS gallery_title FROM gallery_items gi JOIN galleries g ON g.id = gi.gallery_id WHERE gi.id = ?', [(int) $p['item']]);
    if (!$item) {
        api_error('This item no longer exists.', 404);
    }
    $v = api_validate(['caption' => 'max:255']);
    db_update('gallery_items', ['caption' => $v['caption']], 'id = ?', [(int) $item['id']]);
    log_activity('update', 'media', (int) $item['gallery_id'], 'Updated a caption in album "' . $item['gallery_title'] . '"');
    api_ok(['id' => (int) $item['id'], 'caption' => $v['caption']], 'Caption saved.');
});

route('DELETE', '/gallery/items/{item:\d+}', function ($p) {
    api_require('media', 'edit');
    $item = db_row('SELECT gi.*, g.title AS gallery_title FROM gallery_items gi JOIN galleries g ON g.id = gi.gallery_id WHERE gi.id = ?', [(int) $p['item']]);
    if (!$item) {
        api_error('This item no longer exists.', 404);
    }
    db_delete('gallery_items', 'id = ?', [(int) $item['id']]);
    log_activity('update', 'media', (int) $item['gallery_id'], 'Removed an item from album "' . $item['gallery_title'] . '"');
    api_ok(null, 'Item removed from the album.');
});
