<?php
/**
 * Input validation helpers.
 *
 * Usage:
 *   [$data, $errors] = validate($_POST, [
 *       'name'  => 'required|max:120',
 *       'email' => 'required|email',
 *       'phone' => 'required|phone',
 *   ]);
 *
 * Supported rules: required, email, phone, pincode, pan, date, past, future,
 * numeric, integer, min:N, max:N (length for text, value for numbers),
 * in:a,b,c, url, password, same:field, accepted, text (allows new lines),
 * array (multi-select of allowed values with in:).
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Trim a value and strip invisible control characters. */
function clean_string($value, bool $multiline = false): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $value = trim((string) $value);
    if ($multiline) {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    }
    return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
}

function field_label(string $field, array $labels): string
{
    return $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
}

function validate(array $source, array $rules, array $labels = []): array
{
    $data = [];
    $errors = [];

    foreach ($rules as $field => $ruleString) {
        $ruleList = explode('|', $ruleString);
        $isArray = in_array('array', $ruleList, true);
        $multiline = in_array('text', $ruleList, true);
        $raw = $source[$field] ?? ($isArray ? [] : '');
        $label = field_label($field, $labels);

        if ($isArray) {
            $value = array_values(array_filter(array_map('clean_string', (array) $raw), 'strlen'));
        } else {
            $value = clean_string($raw, $multiline);
            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = '';
            }
        }

        $empty = $isArray ? count($value) === 0 : $value === '';
        $isNumeric = in_array('numeric', $ruleList, true) || in_array('integer', $ruleList, true);

        foreach ($ruleList as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

            if ($name === 'required') {
                if ($empty) {
                    $errors[$field] = $label . ' is required.';
                    break;
                }
                continue;
            }
            if ($name === 'accepted') {
                if (!in_array($value, ['1', 'on', 'yes'], true)) {
                    $errors[$field] = 'Please confirm: ' . $label . '.';
                    break;
                }
                continue;
            }
            if ($empty) {
                continue; // optional field left blank
            }

            $error = null;
            switch ($name) {
                case 'email':
                    $value = mb_strtolower($value);
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL) || strlen($value) > 190) {
                        $error = 'Please enter a valid email address.';
                    }
                    break;
                case 'phone':
                    $digits = preg_replace('/[\s\-()]/', '', $value);
                    if (preg_match('/^(\+91|91|0)?([6-9]\d{9})$/', $digits, $m)) {
                        $value = $m[2];
                    } elseif (preg_match('/^\+?\d{8,15}$/', $digits)) {
                        $value = $digits; // international number
                    } else {
                        $error = 'Please enter a valid mobile number.';
                    }
                    break;
                case 'pincode':
                    if (!preg_match('/^[1-9]\d{5}$/', $value)) {
                        $error = 'Please enter a valid 6-digit PIN code.';
                    }
                    break;
                case 'pan':
                    $value = strtoupper($value);
                    if (!preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $value)) {
                        $error = 'Please enter a valid PAN (e.g. ABCDE1234F).';
                    }
                    break;
                case 'date':
                    $d = DateTime::createFromFormat('Y-m-d', $value);
                    if (!$d || $d->format('Y-m-d') !== $value) {
                        $error = $label . ' must be a valid date.';
                    }
                    break;
                case 'datetime':
                    $value = str_replace('T', ' ', $value);
                    if (strlen($value) === 16) {
                        $value .= ':00';
                    }
                    $d = DateTime::createFromFormat('Y-m-d H:i:s', $value);
                    if (!$d || $d->format('Y-m-d H:i:s') !== $value) {
                        $error = $label . ' must be a valid date and time.';
                    }
                    break;
                case 'past':
                    if (strtotime($value) === false || strtotime($value) > time()) {
                        $error = $label . ' must be in the past.';
                    }
                    break;
                case 'numeric':
                    $value = str_replace(',', '', $value);
                    if (!is_numeric($value)) {
                        $error = $label . ' must be a number.';
                    }
                    break;
                case 'integer':
                    if (!preg_match('/^-?\d+$/', $value)) {
                        $error = $label . ' must be a whole number.';
                    }
                    break;
                case 'min':
                    if ($isNumeric ? (float) $value < (float) $param : mb_strlen($value) < (int) $param) {
                        $error = $isNumeric
                            ? $label . ' must be at least ' . $param . '.'
                            : $label . ' must be at least ' . $param . ' characters.';
                    }
                    break;
                case 'max':
                    if ($isArray) {
                        if (count($value) > (int) $param) {
                            $error = 'Please choose at most ' . $param . ' options for ' . $label . '.';
                        }
                    } elseif ($isNumeric ? (float) $value > (float) $param : mb_strlen($value) > (int) $param) {
                        $error = $isNumeric
                            ? $label . ' may not be more than ' . $param . '.'
                            : $label . ' may not be longer than ' . $param . ' characters.';
                    }
                    break;
                case 'in':
                    $allowed = explode(',', (string) $param);
                    foreach ((array) $value as $v) {
                        if (!in_array($v, $allowed, true)) {
                            $error = 'Please choose a valid option for ' . $label . '.';
                            break;
                        }
                    }
                    break;
                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)) {
                        $error = $label . ' must be a valid web address starting with http:// or https://';
                    }
                    break;
                case 'password':
                    if (strlen($value) < 8 || !preg_match('/[A-Za-z]/', $value) || !preg_match('/\d/', $value)) {
                        $error = 'Password must be at least 8 characters and include letters and numbers.';
                    } elseif (strlen($value) > 72) {
                        $error = 'Password may not be longer than 72 characters.';
                    }
                    break;
                case 'same':
                    if ($value !== clean_string($source[$param] ?? '')) {
                        $error = $label . ' does not match.';
                    }
                    break;
            }

            if ($error !== null) {
                $errors[$field] = $error;
                break;
            }
        }

        $data[$field] = $value;
    }

    return [$data, $errors];
}

/**
 * Remember errors and submitted values, then redirect back to the form.
 * Passwords and files are never stored in the session.
 */
function back_with_errors(array $errors, string $to, array $old = []): void
{
    unset($old['password'], $old['password_confirm'], $old['current_password'], $old['csrf_token']);
    $_SESSION['_errors'] = $errors;
    $_SESSION['_old'] = $old;
    flash('error', count($errors) === 1 ? reset($errors) : 'Please correct the highlighted fields and try again.');
    redirect($to);
}

/** Previously submitted value (after a failed submission). */
function old(string $key, $default = '')
{
    static $old = null;
    if ($old === null) {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
    }
    return $old[$key] ?? $default;
}

/** Error for a field, if any. */
function field_error(string $key): string
{
    static $errors = null;
    if ($errors === null) {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
    }
    // the id lets script.js / form-validation.js link the message to its field (aria-describedby + aria-invalid)
    return isset($errors[$key]) ? '<span class="field-error" id="err-' . e(preg_replace('/[^A-Za-z0-9_-]/', '', $key)) . '">' . e($errors[$key]) . '</span>' : '';
}

/** Is a value selected / checked in old input? */
function old_checked(string $key, string $value, bool $default = false): string
{
    $current = old($key, null);
    if ($current === null) {
        return $default ? ' checked' : '';
    }
    return in_array($value, (array) $current, true) ? ' checked' : '';
}

function old_selected(string $key, string $value, string $default = ''): string
{
    $current = old($key, $default);
    return (string) $current === $value ? ' selected' : '';
}

/**
 * Validate and store an uploaded file.
 *
 * $kind 'image'    -> jpg, png, webp (re-encoded with GD when available)
 * $kind 'document' -> pdf, jpg, png
 * $kind 'pdf'      -> pdf only
 *
 * Returns ['path' => 'uploads/...'] or ['error' => 'message'] or [] when no file was sent.
 */
function handle_upload(string $field, string $kind, string $folder, bool $required = false): array
{
    $file = $_FILES[$field] ?? null;
    if (!$file || !isset($file['error']) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $required ? ['error' => 'Please choose a file to upload.'] : [];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['error' => 'The file is too large. Maximum size is ' . MAX_UPLOAD_MB . ' MB.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'The file could not be uploaded. Please try again.'];
    }
    if ($file['size'] <= 0 || $file['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
        return ['error' => 'The file is too large. Maximum size is ' . MAX_UPLOAD_MB . ' MB.'];
    }

    $allowed = [
        'image'    => ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'],
        'document' => ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'],
        'pdf' => ['pdf' => 'application/pdf'],
    ][$kind] ?? [];

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return ['error' => 'File type not allowed. Allowed types: ' . strtoupper(implode(', ', array_unique(array_keys($allowed)))) . '.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if ($mime !== $allowed[$ext]) {
        return ['error' => 'The file content does not match its type. Please upload a genuine ' . strtoupper($ext) . ' file.'];
    }

    if (strpos($mime, 'image/') === 0) {
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > 10000 || $info[1] > 10000) {
            return ['error' => 'The image file is not valid.'];
        }
    } else {
        $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($head !== '%PDF-') {
            return ['error' => 'The PDF file is not valid.'];
        }
    }

    $dir = UPLOAD_PATH . '/' . trim($folder, '/');
    if ((!is_dir($dir) && !@mkdir($dir, 0755, true)) || !is_writable($dir)) {
        error_log('Upload folder not writable: ' . $dir);
        return ['error' => 'The file could not be saved because the upload folder is not writable. Please contact the site administrator.'];
    }

    $ext = $ext === 'jpeg' ? 'jpg' : $ext;
    $name = date('Ymd') . '-' . bin2hex(random_bytes(12)) . '.' . $ext;
    $target = $dir . '/' . $name;

    $saved = false;
    if ($kind === 'image') {
        $saved = reencode_image($file['tmp_name'], $target, $mime);
    }
    if (!$saved && !move_uploaded_file($file['tmp_name'], $target)) {
        return ['error' => 'The file could not be saved. Please try again.'];
    }
    @chmod($target, 0644);
    // Smaller copies for cards and galleries (srcset); only for public image folders, never private documents
    $top = strtok(trim($folder, '/'), '/');
    if ($kind === 'image' && !in_array($top, ['documents', 'certificates'], true)) {
        try {
            make_image_variants($target);
        } catch (Throwable $e) {
            error_log('Image variants failed for ' . $name . ': ' . $e->getMessage());
        }
    }

    return ['path' => 'uploads/' . trim($folder, '/') . '/' . $name];
}

/**
 * Re-encode an image with GD (removes hidden payloads/metadata) and limit
 * its size to 1920px. Returns false if GD is not available.
 */
function reencode_image(string $source, string $target, string $mime): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    switch ($mime) {
        case 'image/jpeg':
            $img = @imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $img = @imagecreatefrompng($source);
            break;
        case 'image/webp':
            $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false;
            break;
        default:
            $img = false;
    }
    if (!$img) {
        return false;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $max = 1920;
    if ($w > $max || $h > $max) {
        $ratio = min($max / $w, $max / $h);
        $nw = (int) round($w * $ratio);
        $nh = (int) round($h * $ratio);
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $resized;
    } else {
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }

    switch ($mime) {
        case 'image/jpeg':
            $ok = imagejpeg($img, $target, 85);
            break;
        case 'image/png':
            $ok = imagepng($img, $target, 6);
            break;
        default:
            $ok = imagewebp($img, $target, 85);
    }
    imagedestroy($img);
    return $ok;
}

/** Widths of the resized copies written next to a public uploaded image: photo.jpg -> photo-640.jpg, photo-1080.jpg. */
const IMAGE_VARIANT_WIDTHS = [640, 1080];

/** Path of the resized copy of an image ('uploads/x/a.jpg', 640 -> 'uploads/x/a-640.jpg'). */
function image_variant_path(string $path, int $width): string
{
    return (string) preg_replace('/(\.[A-Za-z0-9]+)$/', '-' . $width . '$1', $path);
}

/**
 * Write the resized copies of an image file (absolute path) that is wider than each variant width.
 * Existing copies are kept. Quality: JPEG / WebP 80, PNG level 6 (alpha kept).
 */
function make_image_variants(string $fullPath): void
{
    if (!function_exists('imagecreatetruecolor') || !is_file($fullPath)) {
        return;
    }
    $info = @getimagesize($fullPath);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return;
    }
    [$w, $h] = $info;
    $todo = array_values(array_filter(IMAGE_VARIANT_WIDTHS, function ($vw) use ($w, $fullPath) {
        return $w > $vw && !is_file(image_variant_path($fullPath, $vw));
    }));
    if (!$todo) {
        return;
    }
    $mime = $info['mime'];
    $img = $mime === 'image/jpeg' ? @imagecreatefromjpeg($fullPath) : ($mime === 'image/png' ? @imagecreatefrompng($fullPath)
        : (function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fullPath) : false));
    if (!$img) {
        return;
    }
    foreach ($todo as $vw) {
        $vh = max(1, (int) round($h * $vw / $w));
        $res = imagecreatetruecolor($vw, $vh);
        imagealphablending($res, false);
        imagesavealpha($res, true);
        imagecopyresampled($res, $img, 0, 0, 0, 0, $vw, $vh, $w, $h);
        $target = image_variant_path($fullPath, $vw);
        $ok = $mime === 'image/jpeg' ? imagejpeg($res, $target, 80) : ($mime === 'image/png' ? imagepng($res, $target, 6) : imagewebp($res, $target, 80));
        imagedestroy($res);
        if ($ok) {
            @chmod($target, 0644);
        }
    }
    imagedestroy($img);
}

/** Delete a previously uploaded file (only inside uploads/), with its resized copies. */
function delete_upload(?string $path): void
{
    if (!$path || strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false) {
        return;
    }
    $full = ROOT_PATH . '/' . $path;
    if (is_file($full)) {
        @unlink($full);
    }
    if (preg_match('/\.(jpe?g|png|webp)$/i', $path)) {
        foreach (IMAGE_VARIANT_WIDTHS as $vw) {
            $variant = ROOT_PATH . '/' . image_variant_path($path, $vw);
            if (is_file($variant)) {
                @unlink($variant);
            }
        }
    }
}
