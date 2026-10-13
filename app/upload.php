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
    return ['ok' => true, 'path' => $relDir . '/' . $name, 'original_name' => mb_substr($original, 0, 190), 'mime' => $mime,
        'size' => (int) filesize($dest), 'extension' => $ext, 'width' => $width, 'height' => $height];
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
        imagedestroy($img);
        $img = $resized;
    }
    $ok = match ($ext) {
        'png'  => (function () use ($img, $dest) { imagesavealpha($img, true); return imagepng($img, $dest, 7); })(),
        'webp' => imagewebp($img, $dest, 85),
        default => imagejpeg($img, $dest, 86),
    };
    imagedestroy($img);
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
