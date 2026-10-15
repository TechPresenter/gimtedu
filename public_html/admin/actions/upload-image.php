<?php
/**
 * Image uploads: gallery photos (multiple) and images inserted into the
 * page/blog editor (AJAX, returns JSON).
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('content');

$type = (string) ($_POST['type'] ?? '');
$ajax = !empty($_POST['ajax']);

if ($ajax) {
    header('Content-Type: application/json');
    if (!csrf_valid()) {
        http_response_code(403);
        exit(json_encode(['error' => 'Session expired. Please reload the page.']));
    }
    $up = handle_upload('image', 'image', 'blog', true);
    if (isset($up['error'])) {
        http_response_code(422);
        exit(json_encode(['error' => $up['error']]));
    }
    admin_log('editor_image_uploaded', $up['path']);
    exit(json_encode(['url' => url($up['path'])]));
}

csrf_verify('admin/gallery.php');

if ($type === 'gallery') {
    $category = (string) ($_POST['category'] ?? 'general');
    if (!isset(GALLERY_CATEGORIES[$category])) {
        $category = 'general';
    }
    $title = mb_substr(clean_string($_POST['title'] ?? ''), 0, 150);
    $files = $_FILES['images'] ?? null;
    if (!$files || !is_array($files['name'])) {
        flash('error', 'Please choose at least one photo.');
        admin_redirect('gallery.php');
    }
    $saved = 0;
    $failed = [];
    $count = min(20, count($files['name']));
    for ($i = 0; $i < $count; $i++) {
        $_FILES['_one'] = [];
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
            $_FILES['_one'][$k] = $files[$k][$i];
        }
        $up = handle_upload('_one', 'image', 'gallery', true);
        if (isset($up['error'])) {
            $failed[] = $files['name'][$i] . ': ' . $up['error'];
            continue;
        }
        $name = $title !== '' ? $title . ($count > 1 ? ' (' . ($i + 1) . ')' : '') : ucfirst(str_replace(['-', '_'], ' ', pathinfo($files['name'][$i], PATHINFO_FILENAME)));
        db_insert('gallery', [
            'title' => mb_substr($name, 0, 150), 'category' => $category, 'image_path' => $up['path'],
            'status' => 'published', 'uploaded_by' => admin_id(),
        ]);
        $saved++;
    }
    admin_log('gallery_uploaded', $saved . ' photo(s) to ' . $category);
    if ($saved) {
        flash('success', $saved . ' photo(s) uploaded.');
    }
    if ($failed) {
        flash('error', 'Some files were not uploaded: ' . implode('; ', array_slice($failed, 0, 5)));
    }
    admin_redirect('gallery.php');
}

admin_redirect('dashboard.php');
