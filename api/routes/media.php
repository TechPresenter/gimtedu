<?php
/**
 * Media library API (unit cms_content). Listing / metadata editing / folder moves use the generic CRUD module "media".
 *   GET    /api/media/stats                     counts per type + folder, storage used, upload rules
 *   GET    /api/media/{id}                      one item (url, dimensions, uploader) + where it is used
 *   POST   /api/media/upload                    multipart: file, folder, title?, alt_text?, caption?  → media row
 *   POST   /api/media/{id}/replace              multipart: file → swaps the file (URL kept when possible)
 *   DELETE /api/media/{id}?force=1              delete; without force a referenced file answers 409 + usage list
 *   POST   /api/media/bulk-delete               {ids[], force?}
 */

require_once APP_ROOT . '/app/services/media.php';

route('GET', '/media/stats', function () {
    api_require('media', 'view');
    api_ok(media_stats());
});

route('GET', '/media/{id:\d+}', function ($p) {
    api_require('media', 'view');
    $m = media_find((int) $p['id']);
    if (!$m) {
        api_error('This file no longer exists in the media library.', 404);
    }
    $m['usage'] = media_usage($m);
    $m['folder_label'] = media_folders()[$m['folder'] ?? 'general'] ?? label_from_key((string) $m['folder']);
    api_ok($m);
});

route('POST', '/media/upload', function () {
    api_require('media', 'create');
    $folder = (string) api_param('folder', 'general');
    if (!isset(media_folders()[$folder])) {
        api_error('Select a valid folder.', 422, ['folder' => 'Select a valid folder.']);
    }
    $meta = [
        'title' => (string) api_param('title', ''),
        'alt_text' => (string) api_param('alt_text', ''),
        'caption' => (string) api_param('caption', ''),
    ];
    $row = media_store_upload($_FILES['file'] ?? null, $folder, $meta);
    api_ok($row, '"' . $row['original_name'] . '" uploaded.', 201);
});

route('POST', '/media/{id:\d+}/replace', function ($p) {
    api_require('media', 'edit');
    $m = media_find((int) $p['id']);
    if (!$m) {
        api_error('This file no longer exists in the media library.', 404);
    }
    $row = media_replace_file($m, $_FILES['file'] ?? null);
    api_ok($row, 'File replaced. Pages using it now show the new version.');
});

route('DELETE', '/media/{id:\d+}', function ($p) {
    api_require('media', 'delete');
    $m = media_find((int) $p['id']);
    if (!$m) {
        api_error('This file no longer exists in the media library.', 404);
    }
    $usage = media_usage($m);
    $force = in_array((string) ($_GET['force'] ?? api_param('force', '')), ['1', 'true', 'yes'], true);
    if ($usage && !$force) {
        json_response(['ok' => false, 'message' => 'This file is used in ' . count($usage) . ' place' . (count($usage) === 1 ? '' : 's') . '. Confirm to delete it anyway.', 'data' => ['usage' => $usage]], 409);
    }
    media_delete($m);
    api_ok(['id' => $m['id']], 'Media file deleted.');
});

route('POST', '/media/bulk-delete', function () {
    api_require('media', 'delete');
    $ids = array_values(array_filter(array_map('intval', (array) api_param('ids', []))));
    if (!$ids) {
        api_error('Select at least one file.', 422);
    }
    $force = in_array(api_param('force', false), [true, 1, '1', 'true'], true);
    $deleted = 0;
    $skipped = [];
    foreach ($ids as $id) {
        $m = media_find($id);
        if (!$m) {
            continue;
        }
        if (!$force && media_usage($m, 1)) {
            $skipped[] = ['id' => $id, 'title' => $m['title'] ?: $m['original_name']];
            continue;
        }
        media_delete($m);
        $deleted++;
    }
    $msg = $deleted . ' file' . ($deleted === 1 ? '' : 's') . ' deleted.' . ($skipped ? ' ' . count($skipped) . ' in use and kept.' : '');
    api_ok(['deleted' => $deleted, 'skipped' => $skipped], $msg);
});
