<?php
/**
 * Secure file uploads.
 *
 *   $result = upload_file($_FILES['photo'], 'image', 'students');           // public: assets/uploads/students/2026/10/<random>.jpg
 *   $result = upload_file($_FILES['doc'], 'document', 'student-docs', true); // private: storage/private/student-docs/...
 *   if (!$result['ok']) { $error = $result['error']; } else { $path = $result['path']; }
 *
 * Checks: upload error code, size limit (Settings > max_upload_mb), extension allow-list, MIME sniffing (finfo),
 * image validity (getimagesize) and re-encodes raster images with GD to strip embedded payloads/metadata.
 * Files are stored with random names; assets/uploads/.htaccess disables script execution.
 */

function upload_types(): array
{
    return [
        'image'    => ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'gif' => ['image/gif']],
        'document' => [
            'pdf'  => ['application/pdf'],
            'doc'  => ['application/msword', 'application/octet-stream', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'xls'  => ['application/vnd.ms-excel', 'application/octet-stream', 'application/CDFV2'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'csv'  => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
            'txt'  => ['text/plain'],
            'ppt'  => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        ],
        'video'    => ['mp4' => ['video/mp4'], 'webm' => ['video/webm'], 'mov' => ['video/quicktime']],
        'spreadsheet' => [
            'csv'  => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        ],
    ];
}

/** Allowed extension => mimes for a category; 'any' = image + document, 'media' = image + video + document. */
function upload_allowed(string $category): array
{
    $t = upload_types();
    return match ($category) {
        'any'   => $t['image'] + $t['document'],
        'media' => $t['image'] + $t['video'] + $t['document'],
        default => $t[$category] ?? $t['image'],
    };
}

function upload_max_bytes(string $category = 'any'): int
{
    $mb = (float) setting($category === 'video' || $category === 'media' ? 'max_video_upload_mb' : 'max_upload_mb', $category === 'video' || $category === 'media' ? 50 : 5);
    return (int) ($mb * 1024 * 1024);
}

/**
 * @param array|null $file entry from $_FILES
 * @return array{ok:bool,error?:string,path?:string,original_name?:string,mime?:string,size?:int,extension?:string,width?:int,height?:int}
 */
function upload_file(?array $file, string $category = 'image', string $folder = 'general', bool $private = false): array
{
    if (!$file || !isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'No file was uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        ];
        return ['ok' => false, 'error' => $messages[$file['error']] ?? 'Upload failed. Please try again.'];
    }
    if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    $max = upload_max_bytes($category);
    if ($file['size'] <= 0 || $file['size'] > $max) {
        return ['ok' => false, 'error' => 'File must be smaller than ' . human_filesize($max) . '.'];
    }
    $original = basename(str_replace('\\', '/', (string) $file['name']));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = upload_allowed($category);
    if (!isset($allowed[$ext])) {
        return ['ok' => false, 'error' => 'File type .' . e($ext) . ' is not allowed. Allowed: ' . implode(', ', array_keys($allowed)) . '.'];
    }
    // Reject double extensions such as photo.php.jpg
    if (preg_match('/\.(php\d?|phtml|phar|pl|py|cgi|asp|aspx|jsp|sh|exe|htaccess)(\.|$)/i', $original)) {
        return ['ok' => false, 'error' => 'This file name is not allowed.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed[$ext], true)) {
        return ['ok' => false, 'error' => 'The file content does not match its extension.'];
    }
    $width = $height = null;
    if (isset(upload_types()['image'][$ext])) {
        $info = @getimagesize($file['tmp_name']);
        if (!$info) {
            return ['ok' => false, 'error' => 'The image file is invalid or corrupted.'];
        }
        [$width, $height] = $info;
        if ($width > 12000 || $height > 12000) {
            return ['ok' => false, 'error' => 'Image dimensions are too large.'];
        }
    }

    $folder = preg_replace('/[^a-z0-9\-_\/]/i', '', $folder) ?: 'general';
    $relDir = ($private ? 'storage/private/' : 'assets/uploads/') . trim($folder, '/') . '/' . date('Y/m');
    $absDir = APP_ROOT . '/' . $relDir;
    if (!is_dir($absDir) && !mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        return ['ok' => false, 'error' => 'Upload directory is not writable.'];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $absDir . '/' . $name;

    $moved = false;
    if ($width && function_exists('imagecreatefromstring') && in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $moved = reencode_image($file['tmp_name'], $dest, $ext);
    }
    if (!$moved) {
        $moved = PHP_SAPI === 'cli' ? copy($file['tmp_name'], $dest) : move_uploaded_file($file['tmp_name'], $dest);
    }
    if (!$moved) {
        return ['ok' => false, 'error' => 'Could not save the uploaded file.'];
    }
    @chmod($dest, 0644);
    // public photos: resized WebP copies next to the file (abc-480w.webp …) for srcset — the uploaded file itself is
    // kept as it is (it stays the src / fallback). Never fails the upload.
    if (!$private && $width && in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        try {
            image_variants_generate($relDir . '/' . $name);
        } catch (Throwable $e) {
            // variants are an optimisation only
        }
    }
    return ['ok' => true, 'path' => $relDir . '/' . $name, 'original_name' => mb_substr($original, 0, 190), 'mime' => $mime,
        'size' => (int) filesize($dest), 'extension' => $ext, 'width' => $width, 'height' => $height];
}

/* ------------------------------------------------------------------ Responsive WebP variants (performance)
 * Every public raster image (uploads under assets/uploads/, bundled photos under assets/images/) can have resized WebP
 * copies NEXT TO it, named <name>-<width>w.webp (e.g. abc.jpg → abc-480w.webp, abc-960w.webp, abc-1440w.webp and, for a
 * JPEG / PNG, a full-size abc-<width>w.webp). The original file is never modified — it stays the <img src> fallback and
 * what admins download. site_image_variants() / site_img_attrs() (includes/config.php) turn the copies that exist into
 * srcset; they are made here at upload time and for older files by `php tools/img-variants.php` (idempotent backfill).
 * A copy is (re)made when it is missing or older than its original (photos replaced in place keep fresh copies) and is
 * only kept when it is smaller than the original file. Needs GD with WebP; without it nothing happens.
 */

/** Target widths (px) of the resized copies; only widths below the original's width are made. */
function image_variant_widths(): array
{
    return [480, 960, 1440];
}

/** Variant path of an image: assets/uploads/x/abc.jpg + 480 → assets/uploads/x/abc-480w.webp */
function image_variant_path(string $path, int $width): string
{
    return preg_replace('/\.[A-Za-z0-9]+$/', '', $path) . '-' . $width . 'w.webp';
}

/** True for a relative path whose variants this module manages (local public jpg / png / webp, not a variant itself). */
function image_variant_source_ok(string $path): bool
{
    return !str_contains($path, '..') && (bool) preg_match('#^assets/(uploads|images)/.+\.(jpe?g|png|webp)$#i', $path)
        && !preg_match('#-\d{2,4}w\.webp$#i', $path) && !preg_match('#-(fav\d+|touch\d+|logo\d+)\.(png|webp)$#i', $path);
}

/** GD can read the source type and write WebP. */
function image_variants_supported(): bool
{
    return function_exists('imagewebp') && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
}

/** Load a jpg / png / webp file into GD (null when unreadable, e.g. an animated WebP). */
function image_variant_load(string $abs, int $type)
{
    $img = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($abs),
        IMAGETYPE_PNG => @imagecreatefrompng($abs),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : false,
        default => false,
    };
    if (!$img) {
        return null;
    }
    if (!imageistruecolor($img)) {
        imagepalettetotruecolor($img);
    }
    return $img;
}

/**
 * Write one resized copy ($w × $h, or square $w × $w "contain" when $square) of a GD image to $destAbs atomically.
 * $format webp | png. Returns the written size in bytes (0 on failure).
 */
function image_variant_write($img, string $destAbs, int $w, int $h, string $format = 'webp', int $quality = 80, bool $square = false, bool $opaque = false): int
{
    $sw = imagesx($img);
    $sh = imagesy($img);
    [$cw, $ch] = $square ? [$w, $w] : [$w, $h];
    $dst = imagecreatetruecolor($cw, $ch);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, $opaque ? imagecolorallocate($dst, 255, 255, 255) : imagecolorallocatealpha($dst, 255, 255, 255, 127));
    if ($opaque) {
        imagealphablending($dst, true); // composite a transparent logo onto white (iOS shows transparent touch icons on black)
    }
    if ($square) {
        // whole image centred in the square (favicons / touch icons from a wide logo)
        $scale = min($cw / $sw, $ch / $sh);
        $dw = max(1, (int) round($sw * $scale));
        $dh = max(1, (int) round($sh * $scale));
        imagecopyresampled($dst, $img, (int) (($cw - $dw) / 2), (int) (($ch - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
    } else {
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $cw, $ch, $sw, $sh);
    }
    $tmp = $destAbs . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = $format === 'png' ? imagepng($dst, $tmp, 9) : imagewebp($dst, $tmp, $quality);
    if (!$ok || !is_file($tmp)) {
        @unlink($tmp);
        return 0;
    }
    $size = (int) filesize($tmp);
    if (!@rename($tmp, $destAbs)) {
        @unlink($destAbs);
        if (!@rename($tmp, $destAbs)) {
            @unlink($tmp);
            return 0;
        }
    }
    @chmod($destAbs, 0644);
    return $size;
}

/**
 * Make the WebP variants of one image. Idempotent: a variant that exists and is not older than the original is kept
 * (unless $force). Returns ['made' => [width…], 'kept' => [width…], 'skipped' => [width => reason]] — or ['error' => …].
 */
function image_variants_generate(string $path, bool $force = false, ?array $widths = null): array
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if (!image_variant_source_ok($path)) {
        return ['error' => 'not a public jpg/png/webp image'];
    }
    if (!image_variants_supported()) {
        return ['error' => 'GD WebP support missing'];
    }
    $abs = APP_ROOT . '/' . $path;
    $info = is_file($abs) ? @getimagesize($abs) : false;
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return ['error' => 'unreadable image'];
    }
    [$w, $h, $type] = $info;
    $srcTime = (int) filemtime($abs);
    $srcSize = (int) filesize($abs);
    $targets = array_values(array_filter($widths ?? image_variant_widths(), fn ($t) => $t > 0 && $t < $w));
    if ($type !== IMAGETYPE_WEBP && !in_array($w, $targets, true)) {
        $targets[] = $w; // full-size WebP of a JPEG / PNG
    }
    $out = ['made' => [], 'kept' => [], 'skipped' => []];
    $img = null;
    foreach ($targets as $t) {
        $destAbs = APP_ROOT . '/' . image_variant_path($path, $t);
        if (!$force && is_file($destAbs) && filemtime($destAbs) >= $srcTime) {
            $out['kept'][] = $t;
            continue;
        }
        $img ??= image_variant_load($abs, $type);
        if (!$img) {
            return ['error' => 'GD could not read the image'] + $out;
        }
        $th = max(1, (int) round($h * $t / $w));
        // photos q80; graphics with transparency a little higher (edges of cut-outs and logos)
        $size = image_variant_write($img, $destAbs, $t, $th, 'webp', $type === IMAGETYPE_JPEG ? 80 : 84);
        if (!$size) {
            $out['skipped'][$t] = 'write failed';
            continue;
        }
        if ($size >= $srcSize) {
            @unlink($destAbs); // no gain over the original file: the original stays the candidate at this width
            $out['skipped'][$t] = 'not smaller than the original';
            continue;
        }
        $out['made'][] = $t;
    }
    return $out;
}

/** Remove every generated copy of an image (variants, favicon / touch icon / logo derivatives). */
function image_variants_delete(string $path): void
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if (str_contains($path, '..') || !preg_match('#^assets/(uploads|images)/#', $path)) {
        return;
    }
    $base = APP_ROOT . '/' . preg_replace('/\.[A-Za-z0-9]+$/', '', $path);
    foreach (array_merge(glob($base . '-*w.webp') ?: [], glob($base . '-fav*.png') ?: [], glob($base . '-touch*.png') ?: [], glob($base . '-logo*.webp') ?: []) as $f) {
        if (preg_match('#-(\d{2,4}w\.webp|fav\d+\.png|touch\d+\.png|logo\d+\.webp)$#', $f)) {
            @unlink($f);
        }
    }
}

/**
 * A derived file of an image with a fixed size, made on first use (idempotent, refreshed when the original changes):
 *   'logo'  → <name>-logo<H>.webp, $size = height in px (the header logo at ~2× its displayed height)
 *   'fav'   → <name>-fav<S>.png,   square $size px (favicon 48 / 96 / 192)
 *   'touch' → <name>-touch<S>.png, square $size px (apple-touch-icon 180)
 * Returns the relative path, or null when it cannot be made (original missing, SVG, no GD) — callers fall back to the original.
 */
function image_derivative(string $path, string $kind, int $size): ?string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if (str_contains($path, '..') || !preg_match('#^assets/(uploads|images)/.+\.(jpe?g|png|webp)$#i', $path) || $size < 16 || $size > 1024) {
        return null;
    }
    $suffix = match ($kind) {
        'logo' => '-logo' . $size . '.webp',
        'fav' => '-fav' . $size . '.png',
        'touch' => '-touch' . $size . '.png',
        default => null,
    };
    if ($suffix === null) {
        return null;
    }
    $abs = APP_ROOT . '/' . $path;
    if (!is_file($abs)) {
        return null;
    }
    $rel = preg_replace('/\.[A-Za-z0-9]+$/', '', $path) . $suffix;
    $destAbs = APP_ROOT . '/' . $rel;
    if (is_file($destAbs) && filemtime($destAbs) >= filemtime($abs)) {
        return $rel;
    }
    if (!image_variants_supported() || !function_exists('imagepng')) {
        return null;
    }
    $info = @getimagesize($abs);
    $img = $info ? image_variant_load($abs, (int) $info[2]) : null;
    if (!$img) {
        return null;
    }
    [$w, $h] = [imagesx($img), imagesy($img)];
    if ($kind === 'logo') {
        if ($h <= $size) {
            return null; // already small: the original is fine
        }
        $tw = max(1, (int) round($w * $size / $h));
        $written = image_variant_write($img, $destAbs, $tw, $size, 'webp', 90);
        if ($written && $written >= (int) filesize($abs)) {
            @unlink($destAbs);
            return null;
        }
        return $written ? $rel : null;
    }
    return image_variant_write($img, $destAbs, $size, $size, 'png', 0, true, $kind === 'touch') ? $rel : null;
}

/** Re-encode an image (strips metadata and anything appended to the file). Large images are downscaled to 2400px. */
function reencode_image(string $src, string $dest, string $ext): bool
{
    $data = @file_get_contents($src);
    $img = $data !== false ? @imagecreatefromstring($data) : false;
    if (!$img) {
        return false;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $maxSide = 2400;
    if (max($w, $h) > $maxSide) {
        $ratio = $maxSide / max($w, $h);
        $nw = (int) round($w * $ratio);
        $nh = (int) round($h * $ratio);
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (PHP_VERSION_ID < 80000) {
            // PHP 8+ frees GdImage objects when they go out of scope; imagedestroy() is a deprecated no-op on 8.5
            imagedestroy($img);
        }
        $img = $resized;
    }
    $ok = match ($ext) {
        'png'  => (function () use ($img, $dest) { imagesavealpha($img, true); return imagepng($img, $dest, 7); })(),
        'webp' => imagewebp($img, $dest, 85),
        default => imagejpeg($img, $dest, 86),
    };
    if (PHP_VERSION_ID < 80000) {
        imagedestroy($img);
    }
    return (bool) $ok;
}

/** Delete a previously uploaded file (only inside assets/uploads/ or storage/private/). */
function delete_upload(?string $path): void
{
    if (!$path || preg_match('#\.\.#', $path)) {
        return;
    }
    if (!str_starts_with($path, 'assets/uploads/') && !str_starts_with($path, 'storage/private/')) {
        return;
    }
    $abs = APP_ROOT . '/' . $path;
    if (is_file($abs)) {
        @unlink($abs);
    }
    if (str_starts_with($path, 'assets/uploads/')) {
        image_variants_delete($path); // its WebP copies / icon derivatives go with it
    }
}

/** Normalise $_FILES['x'] when multiple files were posted (name="x[]"). */
function normalize_files_array(array $files): array
{
    if (!is_array($files['name'])) {
        return [$files];
    }
    $out = [];
    foreach ($files['name'] as $i => $n) {
        $out[] = ['name' => $n, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
    }
    return $out;
}
