<?php
/**
 * SEO image pipeline (WP-3): EXIF orientation, WebP siblings, <picture> markup, media alt text, rich-text image
 * filter, 1200x630 social crops, static share cards for pages that only have SVG art, backfill and clean-up.
 *
 * Everything here is optional for the rest of the site: callers use function_exists() (WP-0 hooks), and every
 * function degrades to the old behaviour when GD, WebP or EXIF support is missing.
 *
 * Files written by this module (all public, never for documents/certificates):
 *   uploads/<folder>/<name>.webp, <name>-640.webp, <name>-1080.webp   WebP siblings (only when smaller than the original)
 *   uploads/og/<sha1(path):16>-<sha1(path|mtime|v1):10>.jpg            1200x630 social crops of uploaded photos
 *   uploads/og/.lock, uploads/og/.webp-skip.json                       generation lock / "WebP was not smaller" list
 *                                                                       (dotfiles and .json are denied by .htaccess)
 *   assets/images/og/<slug>.jpg                                         static 1200x630 share cards (generated at build
 *                                                                       time, committed; see seo_static_og())
 *
 * Settings (migration 820): seo_webp_enabled (1 when GD can write WebP), seo_og_generate (1).
 * PHP 7.4 compatible.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Size of social preview images (Open Graph / X large card). */
const SEO_OG_WIDTH = 1200;
const SEO_OG_HEIGHT = 630;
/** Smallest usable crop width for a social image (Facebook / X recommend at least 600 px wide). */
const SEO_OG_MIN_WIDTH = 600;
/** Bump to regenerate every social crop (part of the crop file name). */
const SEO_OG_VERSION = 'v1';
/** Upload folders whose images are public and may get WebP siblings / social crops (same list as admin/backups.php + logo, partners, speakers). */
const SEO_IMG_PUBLIC_FOLDERS = ['gallery', 'campaigns', 'events', 'blog', 'stories', 'pages', 'media', 'team', 'partners', 'speakers', 'logo'];
/** Upload folders that are never public: file names there must never carry a slug or the original name. */
const SEO_IMG_PRIVATE_FOLDERS = ['documents', 'certificates', 'avatars'];

// ---------------------------------------------------------------------
// Paths and file information
// ---------------------------------------------------------------------

/**
 * Normalise an image reference to a site-relative path ('uploads/x/a.jpg', 'assets/images/b.svg') when it points to
 * an existing local file under uploads/ or assets/; null otherwise (external URL, missing file, traversal).
 * Accepts 'uploads/..', '/uploads/..', '/<base>/uploads/..', absolute URLs on BASE_URL's host, and '?v=' suffixes.
 */
function seo_image_rel(?string $src): ?string
{
    $src = trim((string) $src);
    if ($src === '' || strlen($src) > 500 || preg_match('/[\x00-\x1F\x7F\\\\]/', $src)) {
        return null;
    }
    if (strpos($src, '//') === 0) {
        $src = 'https:' . $src;
    }
    if (preg_match('#^https?://#i', $src)) {
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));
        $own = strtolower((string) parse_url(BASE_URL, PHP_URL_HOST));
        if ($host === '' || $host !== $own) {
            return null;
        }
        $src = (string) parse_url($src, PHP_URL_PATH);
    }
    $src = (string) preg_replace('/[?#].*$/s', '', $src);
    $src = rawurldecode($src);
    if ($src !== '' && $src[0] === '/') {
        $base = function_exists('base_path') ? base_path() : '';
        if ($base !== '' && strpos($src, $base . '/') === 0) {
            $src = substr($src, strlen($base));
        }
        $src = ltrim($src, '/');
    }
    if (strpos($src, '..') !== false || !preg_match('#^(uploads|assets)/[A-Za-z0-9_\-/.]+\.(jpe?g|png|webp|gif|svg)$#i', $src)) {
        return null;
    }
    return is_file(ROOT_PATH . '/' . $src) ? $src : null;
}

/** True for a raster image under one of the public upload folders (WebP siblings / social crops allowed). */
function seo_image_is_public_upload(string $rel): bool
{
    if (!preg_match('#^uploads/([a-z0-9_\-]+)/[A-Za-z0-9_\-/.]+\.(jpe?g|png|webp)$#i', $rel, $m) || strpos($rel, '..') !== false) {
        return false;
    }
    return in_array(strtolower($m[1]), SEO_IMG_PUBLIC_FOLDERS, true);
}

/**
 * Width, height, MIME type and media-library alt text of a local image (cached per request).
 * Returns ['path' => rel|'', 'w' => int, 'h' => int, 'mime' => string, 'alt' => string, 'bytes' => int, 'mtime' => int]
 * with zeros / '' when the file is not a usable local image. SVG sizes come from width/height or the viewBox.
 */
function seo_image_info(?string $path): array
{
    $cache = &seo_image_info_cache();
    $empty = ['path' => '', 'w' => 0, 'h' => 0, 'mime' => '', 'alt' => '', 'bytes' => 0, 'mtime' => 0];
    $rel = seo_image_rel($path);
    if ($rel === null) {
        return $empty;
    }
    if (isset($cache[$rel])) {
        return $cache[$rel];
    }
    if (count($cache) > 500) {
        $cache = [];
    }
    $full = ROOT_PATH . '/' . $rel;
    $info = $empty;
    $info['path'] = $rel;
    $info['bytes'] = (int) @filesize($full);
    $info['mtime'] = (int) @filemtime($full);
    if (preg_match('/\.svg$/i', $rel)) {
        $info['mime'] = 'image/svg+xml';
        $head = (string) @file_get_contents($full, false, null, 0, 2048);
        if (preg_match('/<svg\b[^>]*>/i', $head, $tag)) {
            $w = preg_match('/\swidth="([\d.]+)(px)?"/i', $tag[0], $mw) ? (float) $mw[1] : 0.0;
            $h = preg_match('/\sheight="([\d.]+)(px)?"/i', $tag[0], $mh) ? (float) $mh[1] : 0.0;
            if ((!$w || !$h) && preg_match('/viewBox="\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*"/i', $tag[0], $vb)) {
                $w = (float) $vb[1];
                $h = (float) $vb[2];
            }
            $info['w'] = (int) round($w);
            $info['h'] = (int) round($h);
        }
    } else {
        $size = @getimagesize($full);
        if ($size) {
            $info['w'] = (int) $size[0];
            $info['h'] = (int) $size[1];
            $info['mime'] = (string) ($size['mime'] ?? '');
        }
    }
    $info['alt'] = strpos($rel, 'uploads/') === 0 ? seo_image_alt($rel) : '';
    $cache[$rel] = $info;
    return $info;
}

/** Internal per-request cache of seo_image_info() results. */
function &seo_image_info_cache(): array
{
    static $cache = [];
    return $cache;
}

/** Forget cached sizes and alt texts (after an upload, a media alt edit or a delete within the same request). */
function seo_image_cache_clear(): void
{
    $info = &seo_image_info_cache();
    $info = [];
    $alt = &seo_image_alt_cache();
    $alt = [];
}

// ---------------------------------------------------------------------
// Alt text from the media library
// ---------------------------------------------------------------------

/** Internal per-request cache of media alt texts (path => alt). */
function &seo_image_alt_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Load the media-library alt texts of several images with one query (call before rendering a list of cards).
 * Resized copies (-640 / -1080) and WebP siblings map to their original.
 */
function seo_image_alt_prime(array $paths): void
{
    $cache = &seo_image_alt_cache();
    $want = [];
    foreach ($paths as $p) {
        $key = seo_image_alt_key((string) $p);
        if ($key !== '' && !array_key_exists($key, $cache)) {
            $want[$key] = true;
            $cache[$key] = '';
        }
    }
    if (!$want) {
        return;
    }
    try {
        foreach (array_chunk(array_keys($want), 200) as $chunk) {
            $rows = db_rows('SELECT path, alt_text FROM media WHERE path IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
            foreach ($rows as $r) {
                $cache[(string) $r['path']] = trim((string) $r['alt_text']);
            }
        }
    } catch (Throwable $e) {
        // media table missing (fresh install in progress): no alt texts
    }
}

/** Media-table key of an image reference ('' when it is not a local upload). */
function seo_image_alt_key(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    $rel = preg_replace('/[?#].*$/s', '', $path);
    if (preg_match('#^https?://#i', $rel) || strpos($rel, '/') === 0) {
        $rel = seo_image_rel($path);
        if ($rel === null) {
            return '';
        }
    }
    if (strpos($rel, 'uploads/') !== 0 || strpos($rel, '..') !== false) {
        return '';
    }
    // photo-640.jpg / photo-1080.webp / photo.webp (generated sibling of photo.jpg) -> the original's row
    $widths = defined('IMAGE_VARIANT_WIDTHS') ? IMAGE_VARIANT_WIDTHS : [640, 1080];
    $rel = (string) preg_replace('/-(' . implode('|', $widths) . ')(\.[A-Za-z0-9]+)$/', '$2', $rel);
    if (preg_match('/\.webp$/i', $rel)) {
        foreach (['jpg', 'jpeg', 'png'] as $ext) {
            $cand = (string) preg_replace('/\.webp$/i', '.' . $ext, $rel);
            if (is_file(ROOT_PATH . '/' . $cand)) {
                return $cand;
            }
        }
    }
    return $rel;
}

/** Alt text of an uploaded image from the media library (Admin > Media), else $fallback. */
function seo_image_alt(?string $path, string $fallback = ''): string
{
    $key = seo_image_alt_key((string) $path);
    if ($key === '') {
        return $fallback;
    }
    $cache = &seo_image_alt_cache();
    if (!array_key_exists($key, $cache)) {
        seo_image_alt_prime([$key]);
    }
    $alt = (string) ($cache[$key] ?? '');
    return $alt !== '' ? $alt : $fallback;
}

// ---------------------------------------------------------------------
// EXIF orientation (WP-0 hook in reencode_image())
// ---------------------------------------------------------------------

/**
 * Turn a JPEG that a phone stored "sideways" (EXIF Orientation 2-8) upright. Call right after imagecreatefromjpeg();
 * returns the (possibly new) GD image. Without the exif extension, or for other types, the image is returned as is.
 * The re-encode that follows drops the EXIF block, so the stored file is upright with no orientation tag.
 */
function seo_apply_exif_orientation($img, string $source, string $mime)
{
    if (!$img || $mime !== 'image/jpeg' || !function_exists('exif_read_data') || !is_file($source)) {
        return $img;
    }
    $exif = @exif_read_data($source);
    $o = is_array($exif) ? (int) ($exif['Orientation'] ?? ($exif['IFD0']['Orientation'] ?? 1)) : 1;
    if ($o < 2 || $o > 8) {
        return $img;
    }
    $flip = function ($im, int $mode) {
        return function_exists('imageflip') ? imageflip($im, $mode) : false;
    };
    $rotate = function ($im, int $deg) {
        // imagerotate turns counter-clockwise; the returned image replaces the old one
        $new = @imagerotate($im, $deg, 0);
        if ($new) {
            imagedestroy($im);
            return $new;
        }
        return $im;
    };
    switch ($o) {
        case 2:   // mirrored
            $flip($img, IMG_FLIP_HORIZONTAL);
            break;
        case 3:   // upside down
            $img = $rotate($img, 180);
            break;
        case 4:   // mirrored + upside down
            $flip($img, IMG_FLIP_VERTICAL);
            break;
        case 5:   // transpose
            $flip($img, IMG_FLIP_VERTICAL);
            $img = $rotate($img, -90);
            break;
        case 6:   // needs 90 degrees clockwise (portrait photo held upright)
            $img = $rotate($img, -90);
            break;
        case 7:   // transverse
            $flip($img, IMG_FLIP_VERTICAL);
            $img = $rotate($img, 90);
            break;
        case 8:   // needs 90 degrees counter-clockwise
            $img = $rotate($img, 90);
            break;
    }
    return $img;
}

// ---------------------------------------------------------------------
// WebP siblings
// ---------------------------------------------------------------------

/** WebP output is on: setting seo_webp_enabled (default: GD can write WebP) and imagewebp() exists. */
function seo_webp_enabled(): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }
    try {
        $v = setting('seo_webp_enabled', '1');
    } catch (Throwable $e) {
        $v = '1';
    }
    return $v === '1';
}

/** Sibling path of an image: 'uploads/x/a.jpg' -> 'uploads/x/a.webp' (also for absolute paths and -640 copies). */
function seo_webp_path(string $path): string
{
    return (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
}

/** "WebP was not smaller" list (rel => mtime), so the backfill does not retry those files on every click. */
function seo_webp_skip_list(?array $set = null): array
{
    static $list = null;
    $file = UPLOAD_PATH . '/og/.webp-skip.json';
    if ($set !== null) {
        $list = $set;
        if (is_dir(dirname($file)) || @mkdir(dirname($file), 0755, true)) {
            @file_put_contents($file, (string) json_encode($set, JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        return $list;
    }
    if ($list === null) {
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $list = is_array($data) ? $data : [];
    }
    return $list;
}

/**
 * Write one WebP file next to $src (absolute path, JPEG or PNG). Kept only when it is smaller than the source.
 * Returns true when an up-to-date WebP sibling exists afterwards.
 */
function seo_webp_write(string $src, int $q = 80): bool
{
    $dst = seo_webp_path($src);
    if ($dst === $src || !is_file($src)) {
        return false;
    }
    $srcTime = (int) @filemtime($src);
    if (is_file($dst) && (int) @filemtime($dst) >= $srcTime) {
        return true;
    }
    $size = @getimagesize($src);
    $mime = $size ? (string) ($size['mime'] ?? '') : '';
    if ($mime === 'image/jpeg') {
        $img = @imagecreatefromjpeg($src);
    } elseif ($mime === 'image/png') {
        $img = @imagecreatefrompng($src);
    } else {
        return false;
    }
    if (!$img) {
        return false;
    }
    if (!imageistruecolor($img) && function_exists('imagepalettetotruecolor')) {
        imagepalettetotruecolor($img);   // WebP cannot be written from a palette image
    }
    if ($mime === 'image/png') {
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }
    $tmp = $dst . '.tmp-' . bin2hex(random_bytes(4));
    $ok = @imagewebp($img, $tmp, max(40, min(95, $q)));
    imagedestroy($img);
    clearstatcache(true, $tmp);
    $bytes = $ok && is_file($tmp) ? (int) filesize($tmp) : 0;
    if ($bytes > 0 && $bytes < (int) filesize($src) && @rename($tmp, $dst)) {
        @chmod($dst, 0644);
        if ($srcTime > time()) {
            @touch($dst, $srcTime);   // source stamped in the future (clock skew, copied backup): keep the sibling "up to date"
        }
        return true;
    }
    if (is_file($tmp)) {
        @unlink($tmp);
    }
    if (is_file($dst)) {
        @unlink($dst);   // stale sibling of an older version of the file
    }
    return false;
}

/**
 * Write WebP siblings of a public uploaded JPEG/PNG and of its -640/-1080 copies (absolute path).
 * Hook: end of handle_upload() for public images (after make_image_variants()). Originals are always kept.
 * Returns true when the full-size WebP exists afterwards. Never touches documents, certificates or avatars.
 */
function seo_make_webp(string $fullPath, int $q = 80): bool
{
    if (!seo_webp_enabled() || !preg_match('/\.(jpe?g|png)$/i', $fullPath)) {
        return false;
    }
    $real = realpath($fullPath);
    $uploads = realpath(UPLOAD_PATH);
    if (!$real || !$uploads || strpos($real, $uploads . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    $rel = 'uploads/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($uploads) + 1));
    if (!seo_image_is_public_upload($rel) || strpos($rel, 'uploads/og/') === 0) {
        return false;
    }
    $ok = false;
    try {
        $ok = seo_webp_write($real, $q);
        if (function_exists('image_variant_path')) {
            foreach ((defined('IMAGE_VARIANT_WIDTHS') ? IMAGE_VARIANT_WIDTHS : [640, 1080]) as $vw) {
                $variant = image_variant_path($real, $vw);
                if (is_file($variant)) {
                    seo_webp_write($variant, $q);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('WebP copy failed for ' . $rel . ': ' . $e->getMessage());
        return false;
    }
    $skip = seo_webp_skip_list();
    $mtime = (int) @filemtime($real);
    if (!$ok && ($skip[$rel] ?? null) !== $mtime) {
        $skip[$rel] = $mtime;
        seo_webp_skip_list($skip);
    } elseif ($ok && isset($skip[$rel])) {
        unset($skip[$rel]);
        seo_webp_skip_list($skip);
    }
    return $ok;
}

/**
 * srcset of the WebP siblings of an uploaded JPEG/PNG ('' when there is no full-size WebP or WebP is switched off).
 * With resized copies: "a-640.webp 640w, a-1080.webp 1080w, a.webp 1600w"; without: "a.webp".
 */
function seo_webp_srcset(?string $path): string
{
    $rel = seo_image_rel($path);
    if ($rel === null || !preg_match('/\.(jpe?g|png)$/i', $rel) || strpos($rel, 'uploads/') !== 0 || !seo_webp_enabled()) {
        return '';
    }
    $main = seo_webp_path($rel);
    if (!is_file(ROOT_PATH . '/' . $main) || (int) @filemtime(ROOT_PATH . '/' . $main) < (int) @filemtime(ROOT_PATH . '/' . $rel)) {
        return '';
    }
    $parts = [];
    if (function_exists('image_variant_path')) {
        foreach ((defined('IMAGE_VARIANT_WIDTHS') ? IMAGE_VARIANT_WIDTHS : [640, 1080]) as $w) {
            $v = seo_webp_path(image_variant_path($rel, $w));
            if (is_file(ROOT_PATH . '/' . $v)) {
                $parts[] = url($v) . ' ' . $w . 'w';
            }
        }
    }
    if (!$parts) {
        return url($main);
    }
    $info = seo_image_info($rel);
    if ($info['w'] < 1) {
        return '';
    }
    $parts[] = url($main) . ' ' . $info['w'] . 'w';
    return implode(', ', $parts);
}

// ---------------------------------------------------------------------
// <picture> / <img> markup
// ---------------------------------------------------------------------

/** HTML attributes from an array (null/false skipped, true = boolean attribute; invalid names dropped). */
function seo_html_attrs(array $attrs): string
{
    $out = '';
    foreach ($attrs as $name => $value) {
        $name = (string) $name;
        if ($value === null || $value === false || !preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:.\-]*$/', $name) || preg_match('/^on/i', $name)) {
            continue;
        }
        $out .= $value === true ? ' ' . $name : ' ' . $name . '="' . e((string) $value) . '"';
    }
    return $out;
}

/**
 * <img> for a site image, wrapped in <picture style="display:contents"> with a WebP <source> when WebP siblings exist
 * (display:contents keeps every existing CSS rule and flex/grid layout working unchanged).
 *
 * - width/height: from $attrs when given (cards keep their aspect hints), else the file's real size.
 * - srcset/sizes: the -640/-1080 copies (image_srcset()); $sizes defaults to 100vw when a srcset exists.
 * - loading="lazy" + decoding="async", except for the LCP image: pass 'fetchpriority' => 'high' (or 'lcp' => true),
 *   which prints fetchpriority="high" and no loading attribute.
 * - $alt is printed as given (keep '' for decorative / duplicate-link images). Special keys (not printed):
 *   'media_alt' => true uses the media-library alt text when $alt is ''; 'fallback' => path used when $path is
 *   missing (default: the generic programme illustration, like image_url(); '' prints nothing).
 * Returns '' when there is nothing to show.
 */
function seo_picture(?string $path, string $alt, array $attrs = [], string $sizes = ''): string
{
    $fallback = array_key_exists('fallback', $attrs) ? (string) $attrs['fallback'] : 'assets/images/programs/general.svg';
    $mediaAlt = !empty($attrs['media_alt']);
    $lcp = !empty($attrs['lcp']) || strtolower((string) ($attrs['fetchpriority'] ?? '')) === 'high';
    unset($attrs['fallback'], $attrs['media_alt'], $attrs['lcp'], $attrs['src'], $attrs['alt']);

    $path = trim((string) $path);
    $external = (bool) preg_match('#^https://#i', $path) && seo_image_rel($path) === null;
    $rel = $external ? null : seo_image_rel($path);
    $usingFallback = false;
    if (!$external && $rel === null) {
        $rel = $fallback !== '' ? seo_image_rel($fallback) : null;
        if ($rel === null) {
            return '';
        }
        $usingFallback = true;
    }
    if ($mediaAlt && $alt === '' && !$usingFallback && $rel !== null) {
        $alt = seo_image_alt($rel);
    }

    $info = $rel !== null ? seo_image_info($rel) : ['w' => 0, 'h' => 0];
    $srcset = array_key_exists('srcset', $attrs) ? (string) $attrs['srcset'] : ($rel !== null && function_exists('image_srcset') ? image_srcset($rel) : '');
    $sizesAttr = array_key_exists('sizes', $attrs) ? (string) $attrs['sizes'] : $sizes;
    if ($srcset !== '' && $sizesAttr === '') {
        $sizesAttr = '100vw';
    }
    $width = array_key_exists('width', $attrs) ? $attrs['width'] : ($info['w'] > 0 ? $info['w'] : null);
    $height = array_key_exists('height', $attrs) ? $attrs['height'] : ($info['h'] > 0 ? $info['h'] : null);
    if ($lcp) {
        $loading = array_key_exists('loading', $attrs) && $attrs['loading'] !== 'lazy' ? $attrs['loading'] : null;
        $decoding = $attrs['decoding'] ?? null;
        $priority = 'high';
    } else {
        $loading = array_key_exists('loading', $attrs) ? $attrs['loading'] : 'lazy';
        $decoding = array_key_exists('decoding', $attrs) ? $attrs['decoding'] : 'async';
        $priority = $attrs['fetchpriority'] ?? null;
    }
    unset($attrs['srcset'], $attrs['sizes'], $attrs['width'], $attrs['height'], $attrs['loading'], $attrs['decoding'], $attrs['fetchpriority']);

    $img = '<img' . seo_html_attrs(array_merge([
        'src' => $external ? $path : url((string) $rel),
        'srcset' => $srcset !== '' ? $srcset : null,
        'sizes' => $srcset !== '' ? $sizesAttr : null,
        'alt' => $alt,
        'width' => $width,
        'height' => $height,
        'loading' => $loading,
        'decoding' => $decoding,
        'fetchpriority' => $priority,
    ], $attrs)) . '>';

    $webp = (!$external && !$usingFallback && $rel !== null) ? seo_webp_srcset($rel) : '';
    if ($webp === '') {
        return $img;
    }
    $sourceSizes = strpos($webp, ' ') !== false ? ($sizesAttr !== '' ? $sizesAttr : '100vw') : null;
    return '<picture style="display:contents"><source type="image/webp"' . seo_html_attrs(['srcset' => $webp, 'sizes' => $sourceSizes]) . '>' . $img . '</picture>';
}

// ---------------------------------------------------------------------
// Rich text (applied when the HTML is rendered, never when it is stored)
// ---------------------------------------------------------------------

/**
 * Improve the <img> tags of sanitised rich text (posts, stories, events, campaigns, richtext sections):
 * loading="lazy", decoding="async", real width/height (only when the editor gave none; one given -> the other from
 * the aspect ratio), the -640/-1080 srcset + sizes, the media alt text when alt is empty, and a WebP <picture> when
 * siblings exist. Only <img> tags are rewritten; all other markup stays byte-identical. Images already inside a
 * <picture> are left alone, so running it twice changes nothing.
 * $opts: 'sizes' (default "(max-width: 800px) 100vw, 800px"), 'eager_first' (bool: first image without lazy loading).
 */
function seo_filter_content_images(string $html, array $opts = []): string
{
    if ($html === '' || stripos($html, '<img') === false) {
        return $html;
    }
    $defaultSizes = (string) ($opts['sizes'] ?? '(max-width: 800px) 100vw, 800px');
    $first = !empty($opts['eager_first']);
    $tagRe = '/<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i';
    $parts = preg_split('#(<picture\b.*?</picture>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return $html;
    }
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            continue;   // an existing <picture> block
        }
        $parts[$i] = (string) preg_replace_callback($tagRe, function (array $m) use ($defaultSizes, &$first): string {
            $attrs = seo_parse_img_tag($m[0]);
            if ($attrs === null || !isset($attrs['src'])) {
                return $m[0];
            }
            $rel = seo_image_rel($attrs['src']);
            $eager = $first;
            $first = false;
            if (!$eager && !isset($attrs['loading'])) {
                $attrs['loading'] = 'lazy';
            }
            if (!isset($attrs['decoding'])) {
                $attrs['decoding'] = 'async';
            }
            if ($rel === null) {
                return '<img' . seo_html_attrs($attrs) . '>';
            }
            $info = seo_image_info($rel);
            $w = isset($attrs['width']) && preg_match('/^\d{1,5}$/', trim($attrs['width'])) ? (int) $attrs['width'] : 0;
            $h = isset($attrs['height']) && preg_match('/^\d{1,5}$/', trim($attrs['height'])) ? (int) $attrs['height'] : 0;
            if ($info['w'] > 0 && $info['h'] > 0) {
                if (!$w && !$h) {
                    $attrs['width'] = (string) $info['w'];
                    $attrs['height'] = (string) $info['h'];
                } elseif ($w && !$h) {
                    $attrs['height'] = (string) max(1, (int) round($w * $info['h'] / $info['w']));
                } elseif ($h && !$w) {
                    $attrs['width'] = (string) max(1, (int) round($h * $info['w'] / $info['h']));
                }
            }
            if (trim((string) ($attrs['alt'] ?? '')) === '') {
                $attrs['alt'] = seo_image_alt($rel);
            }
            $sizes = $w > 0 ? '(max-width: ' . $w . 'px) 100vw, ' . $w . 'px' : $defaultSizes;
            if (!isset($attrs['srcset']) && function_exists('image_srcset')) {
                $set = image_srcset($rel);
                if ($set !== '') {
                    $attrs['srcset'] = $set;
                    $attrs['sizes'] = $attrs['sizes'] ?? $sizes;
                }
            }
            // keep src first, alt next, as editors and validators expect
            $ordered = array_merge(['src' => $attrs['src'], 'srcset' => null, 'sizes' => null, 'alt' => $attrs['alt'], 'width' => null, 'height' => null], $attrs);
            $img = '<img' . seo_html_attrs($ordered) . '>';
            $webp = seo_webp_srcset($rel);
            if ($webp === '') {
                return $img;
            }
            $sourceSizes = strpos($webp, ' ') !== false ? (string) ($attrs['sizes'] ?? $sizes) : null;
            return '<picture style="display:contents"><source type="image/webp"' . seo_html_attrs(['srcset' => $webp, 'sizes' => $sourceSizes]) . '>' . $img . '</picture>';
        }, $part);
    }
    return implode('', $parts);
}

/** Attributes of one <img ...> tag (names lower-cased, values decoded), parsed with DOMDocument; null on failure. */
function seo_parse_img_tag(string $tag): ?array
{
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $tag . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return null;
    }
    $img = $doc->getElementsByTagName('img')->item(0);
    if (!$img instanceof DOMElement) {
        return null;
    }
    $attrs = [];
    foreach ($img->attributes as $attr) {
        $attrs[strtolower($attr->nodeName)] = (string) $attr->nodeValue;
    }
    return $attrs;
}

// ---------------------------------------------------------------------
// Social images (Open Graph / X)
// ---------------------------------------------------------------------

/** Folder of generated social crops (created by migration 820, or on first use). */
function seo_og_dir(): string
{
    $dir = UPLOAD_PATH . '/og';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && !is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '<!doctype html><meta charset="utf-8"><title>403</title>');
    }
    return $dir;
}

/** File-name prefix shared by every crop of one source image (lets seo_delete_derivatives() find old versions). */
function seo_og_prefix(string $rel): string
{
    return substr(sha1($rel), 0, 16);
}

/**
 * Default social card: setting seo_default_og_image (local raster) -> assets/images/og-default.jpg -> logo.png.
 * Returns [rel, w, h, 'alt' => text, 'fallback' => true, 'generated' => false].
 */
function seo_og_default(): array
{
    $candidates = [];
    try {
        $candidates[] = (string) setting('seo_default_og_image');
    } catch (Throwable $e) {
        // settings unavailable
    }
    $candidates[] = 'assets/images/og-default.jpg';
    $candidates[] = 'assets/images/logo.png';
    foreach ($candidates as $c) {
        $rel = seo_image_rel($c);
        if ($rel === null || preg_match('/\.svg$/i', $rel)) {
            continue;
        }
        $info = seo_image_info($rel);
        if ($info['w'] < 1) {
            continue;
        }
        $alt = $rel === 'assets/images/og-default.jpg'
            ? 'Prerit Welfare Foundation logo with the tagline Empowering Communities, Spreading Hope, Creating Change'
            : ($rel === 'assets/images/logo.png' ? 'Prerit Welfare Foundation logo' : seo_image_alt($rel));
        if ($alt === '') {
            try {
                $alt = (string) setting('seo_default_og_image_alt');
            } catch (Throwable $e) {
                $alt = '';
            }
        }
        return [$rel, $info['w'], $info['h'], 'alt' => $alt, 'fallback' => true, 'generated' => false];
    }
    return [];
}

/**
 * Social preview image for an uploaded photo: a 1200x630 JPEG crop in uploads/og/ (made on first use, under a flock,
 * named after the source path + its mtime, so a replaced photo gets a new URL and stale crops are removed).
 * Returns [rel, w, h, 'alt' => media alt, 'fallback' => bool, 'generated' => bool], list()-compatible ([$p, $w, $h]).
 * SVG, missing or too small sources (crop narrower than 600 px) return the default card - or [] with $fallback = false,
 * so WP-1 can try seo_static_og() before the default card. With seo_og_generate = 0 a usable source is returned as is.
 */
function seo_og_image(?string $path, bool $fallback = true): array
{
    $rel = seo_image_rel($path);
    if ($rel !== null && preg_match('/\.(jpe?g|png|webp)$/i', $rel)) {
        $info = seo_image_info($rel);
        $cropW = $info['h'] > 0 ? (int) min($info['w'], floor($info['h'] * SEO_OG_WIDTH / SEO_OG_HEIGHT)) : 0;
        if ($cropW >= SEO_OG_MIN_WIDTH) {
            $alt = seo_image_alt($rel);
            if ($info['w'] === SEO_OG_WIDTH && $info['h'] === SEO_OG_HEIGHT && $info['mime'] === 'image/jpeg' && $info['bytes'] <= 300 * 1024) {
                return [$rel, SEO_OG_WIDTH, SEO_OG_HEIGHT, 'alt' => $alt, 'fallback' => false, 'generated' => false];
            }
            try {
                $generate = setting('seo_og_generate', '1') === '1';
            } catch (Throwable $e) {
                $generate = true;
            }
            $crop = $generate ? seo_og_crop($rel) : null;
            if ($crop !== null) {
                return [$crop, SEO_OG_WIDTH, SEO_OG_HEIGHT, 'alt' => $alt, 'fallback' => false, 'generated' => true];
            }
            return [$rel, $info['w'], $info['h'], 'alt' => $alt, 'fallback' => false, 'generated' => false];
        }
    }
    return $fallback ? seo_og_default() : [];
}

/**
 * Make (or find) the 1200x630 crop of a local raster image. Centre crop; for portrait photos the band sits a little
 * above the centre (faces are usually in the upper half). Returns the crop's rel path, or null (no GD, lock busy, error).
 */
function seo_og_crop(string $rel): ?string
{
    $full = ROOT_PATH . '/' . $rel;
    if (!function_exists('imagecreatetruecolor') || !is_file($full)) {
        return null;
    }
    $prefix = seo_og_prefix($rel);
    $name = $prefix . '-' . substr(sha1($rel . '|' . (int) @filemtime($full) . '|' . SEO_OG_VERSION), 0, 10) . '.jpg';
    $outRel = 'uploads/og/' . $name;
    if (is_file(ROOT_PATH . '/' . $outRel)) {
        return $outRel;
    }
    $dir = seo_og_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return null;
    }
    $lock = @fopen($dir . '/.lock', 'c');
    if (!$lock) {
        return null;
    }
    $locked = false;
    for ($try = 0; $try < 20 && !$locked; $try++) {
        $locked = flock($lock, LOCK_EX | LOCK_NB);
        if (!$locked) {
            usleep(100000);
        }
    }
    if (!$locked) {
        fclose($lock);
        return null;   // another request is generating; this page uses the source image
    }
    try {
        if (is_file(ROOT_PATH . '/' . $outRel)) {
            return $outRel;
        }
        $size = @getimagesize($full);
        $mime = $size ? (string) ($size['mime'] ?? '') : '';
        if ($mime === 'image/jpeg') {
            $src = @imagecreatefromjpeg($full);
        } elseif ($mime === 'image/png') {
            $src = @imagecreatefrompng($full);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $src = @imagecreatefromwebp($full);
        } else {
            $src = false;
        }
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $ratio = SEO_OG_WIDTH / SEO_OG_HEIGHT;
        if ($w / $h > $ratio) {
            $cw = (int) round($h * $ratio);
            $ch = $h;
            $x = (int) floor(($w - $cw) / 2);
            $y = 0;
        } else {
            $cw = $w;
            $ch = (int) round($w / $ratio);
            $x = 0;
            $y = (int) floor(($h - $ch) * ($h > $w ? 0.4 : 0.5));
        }
        $dst = imagecreatetruecolor(SEO_OG_WIDTH, SEO_OG_HEIGHT);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // transparent PNG areas become white
        imagecopyresampled($dst, $src, 0, 0, $x, $y, SEO_OG_WIDTH, SEO_OG_HEIGHT, $cw, $ch);
        imagedestroy($src);
        imageinterlace($dst, true);
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(4)) . '.jpg';
        $ok = false;
        foreach ([82, 72, 62] as $quality) {
            $ok = imagejpeg($dst, $tmp, $quality);
            clearstatcache(true, $tmp);
            if ($ok && filesize($tmp) <= 300 * 1024) {
                break;
            }
        }
        imagedestroy($dst);
        if (!$ok || !@rename($tmp, ROOT_PATH . '/' . $outRel)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            return null;
        }
        @chmod(ROOT_PATH . '/' . $outRel, 0644);
        foreach ((array) glob($dir . '/' . $prefix . '-*.jpg') as $old) {
            if (is_string($old) && basename($old) !== $name) {
                @unlink($old);   // crop of an older version of the same file
            }
        }
        return $outRel;
    } catch (Throwable $e) {
        error_log('Social image crop failed for ' . $rel . ': ' . $e->getMessage());
        return null;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Static 1200x630 share cards (assets/images/og/*.jpg, generated at build time from each page's own SVG art, title and
 * brand colours). Page slug => [file name, card title]. Used only when the page has no raster image of its own.
 */
function seo_static_og_map(): array
{
    return [
        'home' => ['home.jpg', 'Together, let us build a better tomorrow'],
        'programs' => ['programs.jpg', 'Our Programmes'],
        'programs/education-literacy' => ['education-literacy.jpg', 'Education & Literacy'],
        'programs/healthcare' => ['healthcare.jpg', 'Healthcare'],
        'programs/women-empowerment' => ['women-empowerment.jpg', 'Women Empowerment'],
        'programs/youth-empowerment' => ['youth-empowerment.jpg', 'Youth Empowerment'],
        'programs/rural-development' => ['rural-development.jpg', 'Rural Development'],
        'programs/environment-protection' => ['environment-protection.jpg', 'Environment Protection'],
        'skill-development' => ['skill-development.jpg', 'Skill Development & Training'],
        'programs/digital-learning' => ['digital-learning.jpg', 'Digital Learning'],
        'programs/scholarship-educational-support' => ['scholarship-educational-support.jpg', 'Scholarship & Educational Support'],
        'programs/food-distribution' => ['food-distribution.jpg', 'Food Distribution'],
        'programs/disaster-relief' => ['disaster-relief.jpg', 'Disaster Relief'],
        'programs/ai-artificial-intelligence' => ['ai-artificial-intelligence.jpg', 'AI & Artificial Intelligence'],
        'programs/robotics-technology' => ['robotics-technology.jpg', 'Robotics & Emerging Technology'],
        'programs/machine-learning-data-science' => ['machine-learning-data-science.jpg', 'Machine Learning & Data Science'],
        'programs/digital-marketing' => ['digital-marketing.jpg', 'Digital Marketing'],
        'programs/cyber-security' => ['cyber-security.jpg', 'Cyber Security & Digital Awareness'],
        'programs/career-entrepreneurship' => ['career-entrepreneurship.jpg', 'Career Development & Entrepreneurship'],
        'programs/fitness-yoga-wellness' => ['fitness-yoga-wellness.jpg', 'Fitness, Yoga & Wellness'],
        'programs/music-arts-culture' => ['music-arts-culture.jpg', 'Music, Arts & Cultural Programs'],
        'programs/hackathons-innovation' => ['hackathons-innovation.jpg', 'Hackathons & Innovation Challenges'],
        'programs/events-seminars' => ['events-seminars.jpg', 'Events, Seminars & Awareness Campaigns'],
        'donate' => ['donate.jpg', 'Donate Now'],
        'become-a-volunteer' => ['become-a-volunteer.jpg', 'Volunteer with Us'],
        'volunteer-teacher' => ['volunteer-teacher.jpg', 'Volunteer Teacher Programme'],
        'events' => ['events.jpg', 'Events'],
        'hackathons' => ['hackathons.jpg', 'Hackathons'],
        'contact' => ['contact.jpg', 'Contact Us'],
        'about' => ['about.jpg', 'About Us'],
        'ngo-details' => ['ngo-details.jpg', 'NGO Details'],
    ];
}

/** Normalised page-slug key of the static card map ('' and '/' = home; 'programs/skill-development' = 'skill-development'). */
function seo_static_og_key(string $pageSlug): string
{
    $slug = strtolower(trim($pageSlug, " /\t\n"));
    if ($slug === '' || $slug === 'index') {
        return 'home';
    }
    return $slug === 'programs/skill-development' ? 'skill-development' : $slug;
}

/** Static share card of a page ('assets/images/og/<slug>.jpg') or null when the page has none (or the file is missing). */
function seo_static_og(string $pageSlug): ?string
{
    $map = seo_static_og_map();
    $key = seo_static_og_key($pageSlug);
    if (!isset($map[$key])) {
        return null;
    }
    $rel = 'assets/images/og/' . $map[$key][0];
    return is_file(ROOT_PATH . '/' . $rel) ? $rel : null;
}

/** og:image:alt for a static share card ('' when the page has none). */
function seo_static_og_alt(string $pageSlug): string
{
    $map = seo_static_og_map();
    $key = seo_static_og_key($pageSlug);
    if (!isset($map[$key]) || seo_static_og($pageSlug) === null) {
        return '';
    }
    $name = function_exists('site_name') ? site_name() : 'Prerit Welfare Foundation';
    return $map[$key][1] . ' - ' . $name . ' card with an illustration in the foundation\'s green, gold and blue colours';
}

// ---------------------------------------------------------------------
// Backfill and clean-up
// ---------------------------------------------------------------------

/**
 * Time-limited backfill for images uploaded before this module: resized copies (make_image_variants) and WebP siblings.
 * Processes at most $limit originals or $seconds seconds per call (admin "Images" tab, about 20 / 20 s per click);
 * files that are already done are skipped cheaply, so repeated calls make progress.
 * Returns ['processed', 'webp_created', 'variants_created', 'skipped_larger', 'errors', 'remaining', 'total', 'done', 'webp_supported'].
 */
function seo_image_backfill(int $limit = 20, float $seconds = 20.0): array
{
    $result = ['processed' => 0, 'webp_created' => 0, 'variants_created' => 0, 'skipped_larger' => 0, 'errors' => 0,
        'remaining' => 0, 'total' => 0, 'done' => false, 'webp_supported' => seo_webp_enabled()];
    $limit = max(1, min(500, $limit));
    $start = microtime(true);
    $widths = defined('IMAGE_VARIANT_WIDTHS') ? IMAGE_VARIANT_WIDTHS : [640, 1080];
    $skip = seo_webp_skip_list();
    $todo = [];
    foreach (SEO_IMG_PUBLIC_FOLDERS as $folder) {
        $dir = UPLOAD_PATH . '/' . $folder;
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $name = $file->getFilename();
            if (!$file->isFile() || !preg_match('/\.(jpe?g|png)$/i', $name) || preg_match('/-(' . implode('|', $widths) . ')\.[a-z]+$/i', $name)) {
                continue;
            }
            $full = $file->getPathname();
            $rel = 'uploads/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($full, strlen(UPLOAD_PATH) + 1));
            $result['total']++;
            $needVariants = false;
            $w = 0;
            $size = @getimagesize($full);
            if ($size) {
                $w = (int) $size[0];
            }
            foreach ($widths as $vw) {
                if ($w > $vw && !is_file(image_variant_path($full, $vw))) {
                    $needVariants = true;
                }
            }
            $webp = seo_webp_path($full);
            $needWebp = $result['webp_supported'] && !(is_file($webp) && filemtime($webp) >= filemtime($full))
                && (($skip[$rel] ?? null) !== (int) filemtime($full));
            if ($needVariants || $needWebp) {
                $todo[$rel] = [$full, $needVariants, $needWebp];
            }
        }
    }
    ksort($todo);
    foreach ($todo as $rel => [$full, $needVariants, $needWebp]) {
        if ($result['processed'] >= $limit || microtime(true) - $start > $seconds) {
            break;
        }
        $result['processed']++;
        try {
            if ($needVariants && function_exists('make_image_variants')) {
                $before = 0;
                foreach ($widths as $vw) {
                    $before += is_file(image_variant_path($full, $vw)) ? 1 : 0;
                }
                make_image_variants($full);
                $after = 0;
                foreach ($widths as $vw) {
                    $after += is_file(image_variant_path($full, $vw)) ? 1 : 0;
                }
                $result['variants_created'] += max(0, $after - $before);
            }
            if ($result['webp_supported']) {
                if (seo_make_webp($full)) {
                    $result['webp_created']++;
                } else {
                    $result['skipped_larger']++;
                }
            }
        } catch (Throwable $e) {
            $result['errors']++;
            error_log('Image backfill failed for ' . $rel . ': ' . $e->getMessage());
        }
        unset($todo[$rel]);
    }
    $result['remaining'] = count($todo);
    $result['done'] = $result['remaining'] === 0;
    return $result;
}

/**
 * Delete the generated files of an upload: WebP siblings (also of the -640/-1080 copies) and its social crops.
 * Hook: delete_upload() (the original and its resized copies are deleted there). Only inside uploads/.
 */
function seo_delete_derivatives(string $path): void
{
    $path = ltrim(trim($path), '/');
    if (strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false || !preg_match('/\.(jpe?g|png|webp)$/i', $path)) {
        return;
    }
    $files = [];
    if (preg_match('/\.(jpe?g|png)$/i', $path)) {
        $files[] = seo_webp_path($path);
        if (function_exists('image_variant_path')) {
            foreach ((defined('IMAGE_VARIANT_WIDTHS') ? IMAGE_VARIANT_WIDTHS : [640, 1080]) as $vw) {
                $files[] = seo_webp_path(image_variant_path($path, $vw));
            }
        }
    }
    foreach ($files as $f) {
        if ($f !== $path && is_file(ROOT_PATH . '/' . $f)) {
            @unlink(ROOT_PATH . '/' . $f);
        }
    }
    if (is_dir(UPLOAD_PATH . '/og')) {
        foreach ((array) glob(UPLOAD_PATH . '/og/' . seo_og_prefix($path) . '-*.jpg') as $crop) {
            if (is_string($crop) && is_file($crop)) {
                @unlink($crop);
            }
        }
    }
    $skip = seo_webp_skip_list();
    if (isset($skip[$path])) {
        unset($skip[$path]);
        seo_webp_skip_list($skip);
    }
}

// ---------------------------------------------------------------------
// Public upload file names (G-I3 / P3-7) - helper for the WP-0 edit in handle_upload()
// ---------------------------------------------------------------------

/**
 * File name (without folder) for a new upload. Public image folders: a slug from the alt text or title plus a short
 * random hash ("flood-relief-kit-distribution-3f9a1c7e2b.jpg"). Private folders (documents, certificates, avatars),
 * non-images and an empty label keep the current anonymous form "YYYYMMDD-<24 hex>.<ext>". The original file name
 * is never used: it may contain personal data.
 */
function seo_upload_filename(string $folder, string $ext, string $label = ''): string
{
    $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext));
    $ext = $ext === 'jpeg' ? 'jpg' : ($ext !== '' ? $ext : 'bin');
    $top = strtolower((string) strtok(trim($folder, '/'), '/'));
    $public = in_array($top, SEO_IMG_PUBLIC_FOLDERS, true) && !in_array($top, SEO_IMG_PRIVATE_FOLDERS, true)
        && in_array($ext, ['jpg', 'png', 'webp'], true);
    $slug = $public ? seo_filename_slug($label) : '';
    if ($slug === '') {
        return date('Ymd') . '-' . bin2hex(random_bytes(12)) . '.' . $ext;
    }
    return $slug . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
}

/** ASCII slug for a file name (max 60 characters, '' when nothing usable is left, e.g. a Devanagari-only label). */
function seo_filename_slug(string $label): string
{
    $label = trim(strip_tags($label));
    if ($label === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
        if (is_string($ascii) && $ascii !== '') {
            $label = $ascii;
        }
    }
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');
    if (strlen($slug) > 60) {
        $slug = rtrim(substr($slug, 0, 60), '-');
        $cut = strrpos($slug, '-');
        if ($cut !== false && $cut > 30) {
            $slug = substr($slug, 0, $cut);
        }
    }
    return strlen($slug) >= 3 ? $slug : '';
}

// ---------------------------------------------------------------------
// Migrations (820-829)
// ---------------------------------------------------------------------

/** Image SEO settings and the uploads/og/ folder for social crops. Never overwrites admin-entered values. */
function migration_820_seo_images(): void
{
    add_setting_if_missing('seo_webp_enabled', function_exists('imagewebp') ? '1' : '0');
    add_setting_if_missing('seo_og_generate', '1');
    seo_og_dir();   // creates uploads/og/ + index.html when the folder is writable; never throws
}
