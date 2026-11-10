<?php
/**
 * Website photos: the photos bundled with the website (assets/images/site/*) that pages use wherever no admin upload is
 * set — page banners, the "Apply" band, facility cards, the campus map, portal sign-in backgrounds, the default news
 * photo … Admin › Website CMS › Website Photos replaces one in place: same file name, cropped to the same shape, saved at
 * the same size and compressed, so every page that uses it changes at once (URLs carry ?v=<mtime>, no stale caches).
 * The replaced file is kept in storage/private/site-photos/<name>/ (the newest SITE_PHOTO_KEEP); Undo restores the latest.
 * Routes: api/routes/cms.php (/cms/site-photos …).
 */

const SITE_PHOTO_DIR = 'assets/images/site';
const SITE_PHOTO_BACKUP_DIR = 'storage/private/site-photos';
const SITE_PHOTO_KEEP = 5;

/** Where each bundled photo appears on the website (shown on the admin page). Unknown files: "Not used by any page". */
function site_photo_uses(): array
{
    $facility = ['About page · facilities slider', 'Campus page · facility cards'];
    return [
        'campus-academic-block.jpg' => ['Campus page', 'Campus map', 'Department cards (when a department has no cover image)'],
        'campus-building.jpg' => ['Course pages · banner background', 'About page banner (when the page has no banner)', 'Campus page · virtual tour band', 'Event pages · banner', 'Gallery', 'Faculty Portal · sign-in background'],
        'campus-hostel.jpg' => ['Campus page', 'Campus map'],
        'campus-library.jpg' => ['Academics page · library band', 'Home · "Life at GIMT" slider', 'Campus map'],
        'campus-main.jpg' => ['Home · "About GIMT" (default photo)', 'Contact page', 'Page banners · small corner photo'],
        'facility-classroom.jpg' => array_merge($facility, ['Campus map']),
        'facility-green-campus.jpg' => array_merge($facility, ['Campus map']),
        'facility-hostel.jpg' => $facility,
        'facility-labs.jpg' => array_merge($facility, ['Academics page · labs band', 'Page banners · small corner photo', 'Campus map']),
        'facility-library.jpg' => array_merge($facility, ['Page banners · small corner photo']),
        'facility-sports.jpg' => array_merge($facility, ['Events page banner · small photo', 'Campus map']),
        'graduation.jpg' => ['"Apply" band at the bottom of most pages', 'Home · call to action (default photo)', 'Alumni page banner (when the page has no banner)', 'Home · "Life at GIMT" slider'],
        'hero-campus-students.jpg' => ['Academics page banner', 'Admin sign-in page photo', 'Default photo when a page is shared on social media', 'Home banner ("Photo" layout default)'],
        'hero-counsellor.webp' => ['Home banner · photo beside the form (default; Home Page Sections › Hero › Split layout photo)'],
        'leader-1.jpg' => ['Leadership card 1 (when the person has no photo)'],
        'leader-2.jpg' => ['Leadership card 2 (when the person has no photo)'],
        'leader-3.jpg' => ['Leadership card 3 (when the person has no photo)'],
        'lp-counsellor.webp' => ['Admissions-open page · counsellor card (default; Settings › Website › Campaign landing pages)'],
        'lp-hero-counsellor.webp' => ['Admissions-open page · banner photo (default; Settings › Website › Campaign landing pages)'],
        'news-campus.jpg' => ['News, events and notices without their own photo', 'Home · campus life card (default)', 'Home · "Life at GIMT" slider'],
        'placement-student.jpg' => ['Placement page banner (when the page has no banner)', 'Home · placements and campus life card (default)'],
        'popup-counsellor.webp' => ['Enquiry popup (default; Settings › Website › Enquiry popup)'],
        'program-bcom.jpg' => ['Commerce department card (when it has no cover image)'],
        'program-btech.jpg' => ['Computer Science department card (when it has no cover image)'],
        'program-dca.jpg' => ['Computer Applications department card (when it has no cover image)'],
        'program-digital-marketing.jpg' => ['Professional Skills department card (when it has no cover image)'],
        'program-mba.jpg' => ['Management department card (when it has no cover image)'],
        'students-atrium.jpg' => ['About page', 'Admissions page', 'Faculty page banner', 'Gallery banner', 'Home · "Life at GIMT" slider'],
        'students-laptop.jpg' => ['Programs, Certifications, FAQ and Placement banners', 'Course pages', 'Home · admission journey (default)', 'Student Portal · sign-in background', 'Home · "Life at GIMT" slider'],
        'students-lawn.jpg' => ['Admissions, Campus, Events and Admissions-open banners', 'About page', 'Page banners · small corner photo', 'Home · campus life card (default)', 'Home · "Life at GIMT" slider'],
    ];
}

/** A file name of a bundled photo that exists (no paths, lower-case, image extension). */
function site_photo_valid_name(string $name): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]*\.(jpe?g|png|webp)$/', $name) && is_file(APP_ROOT . '/' . SITE_PHOTO_DIR . '/' . $name);
}

/** @return string[] absolute paths of the kept versions of $name, newest first */
function site_photo_backups(string $name): array
{
    $files = glob(APP_ROOT . '/' . SITE_PHOTO_BACKUP_DIR . '/' . $name . '/*') ?: [];
    rsort($files, SORT_STRING); // names start with YmdHis
    return $files;
}

/** Largest upload PHP and the app accept for a photo (bytes). */
function site_photo_max_bytes(): int
{
    $ini = function (string $key): int {
        $v = trim((string) ini_get($key));
        if ($v === '' || $v === '0' || $v === '-1') {
            return PHP_INT_MAX;
        }
        $n = (float) $v;
        $unit = strtolower(substr($v, -1));
        return (int) ($n * ['g' => 1073741824, 'm' => 1048576, 'k' => 1024][$unit] ?? $n);
    };
    return (int) min(upload_max_bytes('image'), $ini('upload_max_filesize'), $ini('post_max_size'));
}

function site_photo_row(string $name): array
{
    $abs = APP_ROOT . '/' . SITE_PHOTO_DIR . '/' . $name;
    $info = @getimagesize($abs) ?: [0, 0];
    $uses = site_photo_uses()[$name] ?? [];
    return [
        'name' => $name,
        'title' => ucwords(str_replace(['-', '_'], ' ', pathinfo($name, PATHINFO_FILENAME))),
        'url' => asset(SITE_PHOTO_DIR . '/' . $name),
        'width' => (int) $info[0],
        'height' => (int) $info[1],
        'size' => (int) @filesize($abs),
        'format' => strtoupper(pathinfo($name, PATHINFO_EXTENSION)) === 'JPEG' ? 'JPG' : strtoupper(pathinfo($name, PATHINFO_EXTENSION)),
        'updated_at' => date('Y-m-d H:i:s', (int) @filemtime($abs)),
        'uses' => $uses,
        'used' => $uses !== [],
        'versions' => count(site_photo_backups($name)),
    ];
}

/** Every bundled photo, the ones used by pages first. */
function site_photo_list(): array
{
    $rows = [];
    foreach (glob(APP_ROOT . '/' . SITE_PHOTO_DIR . '/*') ?: [] as $f) {
        $name = basename($f);
        if (is_file($f) && site_photo_valid_name($name)) {
            $rows[] = site_photo_row($name);
        }
    }
    usort($rows, fn ($a, $b) => [$b['used'], $a['name']] <=> [$a['used'], $b['name']]);
    return $rows;
}

/**
 * Replace a bundled photo with an uploaded one: centre-cropped to the same shape (a little above the middle, where faces
 * usually are), resized to the same width × height, re-encoded in the same format (transparency kept for PNG / WebP).
 * @return array{ok: bool, error?: string, photo?: array}
 */
function site_photo_replace(string $name, ?array $file): array
{
    if (!site_photo_valid_name($name)) {
        return ['ok' => false, 'error' => 'This website photo does not exist.'];
    }
    if (!$file || !isset($file['error']) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Choose a photo to upload.'];
    }
    $max = site_photo_max_bytes();
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $file['size'] > $max) {
        return ['ok' => false, 'error' => 'The photo must be smaller than ' . human_filesize($max) . '. Compress it (for example on squoosh.app) and try again.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli')) {
        return ['ok' => false, 'error' => 'The upload did not finish. Please try again.'];
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return ['ok' => false, 'error' => 'Upload a JPG, PNG or WebP photo.'];
    }
    $in = @getimagesize($file['tmp_name']);
    if (!$in || $in[0] < 1 || $in[1] < 1 || $in[0] > 12000 || $in[1] > 12000) {
        return ['ok' => false, 'error' => 'The photo file is invalid or too large in pixels.'];
    }
    $abs = APP_ROOT . '/' . SITE_PHOTO_DIR . '/' . $name;
    [$tw, $th] = @getimagesize($abs) ?: [0, 0];
    if ($tw < 1 || $th < 1) {
        return ['ok' => false, 'error' => 'The current photo could not be read.'];
    }
    $data = @file_get_contents($file['tmp_name']);
    $src = $data !== false ? @imagecreatefromstring($data) : false;
    if (!$src) {
        return ['ok' => false, 'error' => 'The photo could not be read. Save it again as JPG or PNG and retry.'];
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    // crop to the target shape: keep the full width or height, centre the other way (vertical: 35 % from the top)
    $targetRatio = $tw / $th;
    if ($sw / $sh > $targetRatio) {
        $cw = (int) round($sh * $targetRatio);
        $ch = $sh;
        $cx = (int) round(($sw - $cw) / 2);
        $cy = 0;
    } else {
        $cw = $sw;
        $ch = (int) round($sw / $targetRatio);
        $cx = 0;
        $cy = (int) round(($sh - $ch) * 0.35);
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $alpha = $ext === 'png' || $ext === 'webp';
    $out = imagecreatetruecolor($tw, $th);
    if ($alpha) {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    } else {
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // transparent PNG → white in a JPG
        imagealphablending($out, true);
    }
    imagecopyresampled($out, $src, 0, 0, $cx, $cy, $tw, $th, $cw, $ch);

    $tmp = APP_ROOT . '/' . SITE_PHOTO_DIR . '/.tmp-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $ok = match ($ext) {
        'png' => imagepng($out, $tmp, 8),
        'webp' => imagewebp($out, $tmp, 80),
        default => imagejpeg($out, $tmp, 82),
    };
    if (!$ok || !is_file($tmp)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'The photo could not be saved (is the images folder writable?).'];
    }
    // keep the current version, then swap the new file in
    $backupDir = APP_ROOT . '/' . SITE_PHOTO_BACKUP_DIR . '/' . $name;
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'The backup folder is not writable, so nothing was changed.'];
    }
    if (!@copy($abs, $backupDir . '/' . date('YmdHis') . '-' . bin2hex(random_bytes(2)) . '.' . $ext)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'The current photo could not be backed up, so nothing was changed.'];
    }
    if (!@rename($tmp, $abs)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'The photo could not be replaced (is the images folder writable?).'];
    }
    @chmod($abs, 0644);
    clearstatcache(true, $abs);
    foreach (array_slice(site_photo_backups($name), SITE_PHOTO_KEEP) as $old) {
        @unlink($old);
    }
    $row = site_photo_row($name);
    log_activity('update', 'media', null, 'Replaced the website photo ' . $name . ' (' . $tw . '×' . $th . ', ' . human_filesize($row['size']) . ')');
    return ['ok' => true, 'photo' => $row];
}

/** Put back the newest kept version of a bundled photo (Undo). */
function site_photo_undo(string $name): array
{
    if (!site_photo_valid_name($name)) {
        return ['ok' => false, 'error' => 'This website photo does not exist.'];
    }
    $latest = site_photo_backups($name)[0] ?? null;
    if (!$latest) {
        return ['ok' => false, 'error' => 'There is no earlier version of this photo.'];
    }
    $abs = APP_ROOT . '/' . SITE_PHOTO_DIR . '/' . $name;
    if (!@copy($latest, $abs)) {
        return ['ok' => false, 'error' => 'The earlier version could not be restored.'];
    }
    @unlink($latest);
    @touch($abs); // new ?v= so browsers fetch it again
    clearstatcache(true, $abs);
    log_activity('update', 'media', null, 'Restored the previous version of the website photo ' . $name);
    return ['ok' => true, 'photo' => site_photo_row($name)];
}
