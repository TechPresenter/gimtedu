<?php
/**
 * Backfill the responsive WebP copies of the website's images (performance) — idempotent, safe to re-run.
 *
 *   php tools/img-variants.php                      # every public jpg / png / webp under assets/uploads and assets/images/site
 *   php tools/img-variants.php --dry-run            # only report what would be made
 *   php tools/img-variants.php --path=assets/images/site --force
 *   php tools/img-variants.php --quiet              # summary line only (for cron / deploy scripts)
 *
 * For every image it writes <name>-480w.webp, -960w.webp, -1440w.webp (only widths below the original's) and, for a
 * JPEG / PNG, a full-size <name>-<width>w.webp NEXT TO the original (app/upload.php image_variants_generate()). The
 * original file is never opened for writing: its bytes, name and modification time stay as they are. A copy that
 * already exists and is not older than its original is skipped (--force remakes it); a copy that would not be smaller
 * than the original is not kept. It also makes the header-logo copy and the square favicon / apple-touch-icon PNGs from
 * Settings › General (logo, logo_white, favicon) — the same files includes/header.php would otherwise make on first use.
 * The website then serves the copies through srcset (site_image_variants() / site_img_attrs() in includes/config.php).
 * Deleting an upload through the admin removes its copies too (delete_upload()).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/init.php';

$opts = getopt('', ['dry-run', 'force', 'quiet', 'path:', 'limit:', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php tools/img-variants.php [--dry-run] [--force] [--quiet] [--path=assets/uploads] [--limit=N]\n";
    exit(0);
}
$dry = isset($opts['dry-run']);
$force = isset($opts['force']);
$quiet = isset($opts['quiet']);
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;
// default: uploads + the bundled website photos (not logos, e-mail images, recruiter logos or OG cards)
$roots = isset($opts['path']) ? [trim(str_replace('\\', '/', (string) $opts['path']), '/')] : ['assets/uploads', 'assets/images/site'];

if (!image_variants_supported()) {
    fwrite(STDERR, "GD with WebP support is required (php-gd built with libwebp).\n");
    exit(1);
}

$stats = ['images' => 0, 'made' => 0, 'kept' => 0, 'skipped' => 0, 'errors' => 0, 'original_bytes' => 0, 'variant_bytes' => 0];
$say = function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line, "\n";
    }
};

foreach ($roots as $root) {
    if (str_contains($root, '..') || !preg_match('#^assets/(uploads|images)(/|$)#', $root) || !is_dir(APP_ROOT . '/' . $root)) {
        fwrite(STDERR, "Skipping {$root}: only folders under assets/uploads or assets/images are processed.\n");
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/' . $root, FilesystemIterator::SKIP_DOTS));
    $files = [];
    foreach ($it as $file) {
        $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(APP_ROOT))), '/');
        if ($file->isFile() && image_variant_source_ok($rel)) {
            $files[] = $rel;
        }
    }
    sort($files);
    foreach ($files as $rel) {
        if ($limit && $stats['images'] >= $limit) {
            break 2;
        }
        $stats['images']++;
        $abs = APP_ROOT . '/' . $rel;
        $info = @getimagesize($abs);
        if (!$info) {
            $stats['errors']++;
            $say("  !  {$rel}: unreadable");
            continue;
        }
        if ($dry) {
            $want = array_values(array_filter(image_variant_widths(), fn ($t) => $t < $info[0]));
            if ($info[2] !== IMAGETYPE_WEBP) {
                $want[] = $info[0];
            }
            $missing = array_filter($want, fn ($t) => $force || !is_file(APP_ROOT . '/' . image_variant_path($rel, $t)) || filemtime(APP_ROOT . '/' . image_variant_path($rel, $t)) < filemtime($abs));
            $stats['made'] += count($missing);
            $stats['kept'] += count($want) - count($missing);
            if ($missing) {
                $say("  ~  {$rel} ({$info[0]}x{$info[1]}): would make " . implode(', ', array_map(fn ($t) => $t . 'w', $missing)));
            }
            continue;
        }
        $mtimeBefore = filemtime($abs);
        $sizeBefore = filesize($abs);
        $r = image_variants_generate($rel, $force);
        clearstatcache(true, $abs);
        if (filemtime($abs) !== $mtimeBefore || filesize($abs) !== $sizeBefore) {
            fwrite(STDERR, "Original changed unexpectedly: {$rel}\n"); // never expected — the generator only reads originals
            exit(2);
        }
        if (isset($r['error'])) {
            $stats['errors']++;
            $say("  !  {$rel}: {$r['error']}");
            continue;
        }
        $stats['made'] += count($r['made']);
        $stats['kept'] += count($r['kept']);
        $stats['skipped'] += count($r['skipped']);
        $stats['original_bytes'] += $sizeBefore;
        foreach (array_merge($r['made'], $r['kept']) as $t) {
            $stats['variant_bytes'] += (int) @filesize(APP_ROOT . '/' . image_variant_path($rel, $t));
        }
        if ($r['made'] || $r['skipped']) {
            $say("  +  {$rel} ({$info[0]}x{$info[1]}, " . round($sizeBefore / 1024) . ' KB): '
                . ($r['made'] ? 'made ' . implode(', ', array_map(fn ($t) => $t . 'w', $r['made'])) : '')
                . ($r['skipped'] ? ($r['made'] ? '; ' : '') . 'not kept ' . implode(', ', array_map(fn ($t, $why) => $t . 'w (' . $why . ')', array_keys($r['skipped']), $r['skipped'])) : ''));
        }
    }
}

printf("%s%d images, %d copies %s, %d up to date, %d not kept (no gain), %d errors%s\n",
    $dry ? '[dry run] ' : '', $stats['images'], $stats['made'], $dry ? 'to make' : 'made', $stats['kept'], $stats['skipped'], $stats['errors'],
    !$dry && $stats['original_bytes'] ? sprintf(' — originals %.1f MB', $stats['original_bytes'] / 1048576) : '');

// header logo copy + square favicons / apple-touch-icon from the settings (what the website header uses). Needs the
// database (Settings › General); probed first because a failed connection ends a CLI script.
if (!$dry && !isset($opts['path'])) {
    $c = app_config('db');
    try {
        new PDO('mysql:host=' . ($c['host'] ?? '127.0.0.1') . ';port=' . (int) ($c['port'] ?? 3306) . ';dbname=' . ($c['name'] ?? ''), (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), [PDO::ATTR_TIMEOUT => 5]);
        foreach (site_brand_icon_files() as $line) {
            $say('  *  ' . $line);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Logo / favicon copies skipped (database not reachable: {$e->getMessage()}); the website makes them on first use.\n");
    }
}
exit($stats['errors'] ? 3 : 0);
