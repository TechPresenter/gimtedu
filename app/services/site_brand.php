<?php
/**
 * Website brand colours & fonts (client request 2026-10-26) — Settings › Website › "Brand colours & fonts".
 *
 * The public website's Tailwind utilities and CSS tokens read channel variables (--brand-50…950, --accent-50…950,
 * --flame-text, --flame-tint, --font-sans, --font-display) whose fallbacks are the standard GIMT design
 * (tailwind.config.cjs "ADMIN BRAND COLOURS", assets/css/src/style.css :root). site_brand_style() prints those variables
 * after style.css only when an admin picked something else, so the default site is untouched.
 *
 * Colours: every shade of the admin's colour is generated at the SAME relative luminance as the matching default shade
 * (keeping the picked hue and the default scale's saturation profile), so every documented contrast ratio still holds —
 * e.g. white text on accent-600 buttons 4.79:1, white/55 text on brand-900 bands ≥ 4.5:1. The exact picked colour
 * takes the shade nearest to it in luminance when that shade's contrast role allows it (brand_scale()). Dark-theme
 * variants (the --dk-* map of tailwind.config.cjs and the --c-* dark tokens of style.css) are generated the same way.
 * Printed documents (print.css), the admin panel and the portals are not affected.
 *
 * Fonts: self-hosted latin woff2 files in assets/fonts (@fontsource, SIL OFL / Apache 2.0 — assets/fonts/SOURCES.md).
 */

/** Settings keys with the standard design's values (database/seed/09_theme.php). */
function site_brand_defaults(): array
{
    return ['site_brand_color' => '#0B2A5B', 'site_accent_color' => '#F05A28', 'site_font_heading' => 'plus-jakarta-sans', 'site_font_body' => 'inter',
        'site_text_color' => '#4A5568'];
}

/**
 * Bundled font families: key => [CSS family, weights, extra subsets]. Inter and Plus Jakarta Sans are declared in
 * style.css (with metric-matched fallbacks); the others get their @font-face rules from site_brand_style().
 */
function site_font_catalog(): array
{
    return [
        'plus-jakarta-sans' => ['Plus Jakarta Sans', [400, 500, 600, 700, 800], []],
        'inter' => ['Inter', [400, 500, 600, 700], []],
        'poppins' => ['Poppins', [400, 500, 600, 700, 800], []],
        'montserrat' => ['Montserrat', [400, 500, 600, 700, 800], []],
        'dm-sans' => ['DM Sans', [400, 500, 600, 700, 800], []],
        'manrope' => ['Manrope', [400, 500, 600, 700, 800], []],
        'outfit' => ['Outfit', [400, 500, 600, 700, 800], []],
        'nunito-sans' => ['Nunito Sans', [400, 500, 600, 700, 800], []],
        'open-sans' => ['Open Sans', [400, 500, 600, 700, 800], []],
        'lato' => ['Lato', [400, 700, 900], []],
        'roboto' => ['Roboto', [400, 500, 700, 900], []],
        'mukta' => ['Mukta (English + हिन्दी)', [400, 500, 600, 700, 800], ['devanagari' => 'U+0900-097F, U+1CD0-1CF9, U+200C-200D, U+20A8, U+20B9, U+20F0, U+25CC, U+A830-A839, U+A8E0-A8FF, U+11B00-11B09']],
    ];
}

/** Select options for the settings form: key => label. */
function site_font_options(): array
{
    $out = [];
    foreach (site_font_catalog() as $key => [$family]) {
        $out[$key] = $family . ($key === 'plus-jakarta-sans' ? ' (default headings)' : ($key === 'inter' ? ' (default body)' : ''));
    }
    return $out;
}

/** Current brand settings, normalised (#RRGGBB upper case, known font keys) — falls back to the defaults. */
function site_brand_settings(): array
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    $d = site_brand_defaults();
    $fonts = site_font_catalog();
    $hex = fn (string $k) => preg_match('/^#?([0-9A-F]{6})$/i', trim((string) setting($k, $d[$k])), $m) ? '#' . strtoupper($m[1]) : $d[$k];
    $font = fn (string $k) => isset($fonts[$f = (string) setting($k, $d[$k])]) ? $f : $d[$k];
    return $s = ['site_brand_color' => $hex('site_brand_color'), 'site_accent_color' => $hex('site_accent_color'),
        'site_font_heading' => $font('site_font_heading'), 'site_font_body' => $font('site_font_body'), 'site_text_color' => $hex('site_text_color')];
}

/* --------------------------------------------------------------------------------------------- colour maths */

function brand_rgb(string $hex): array
{
    $h = ltrim($hex, '#');
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

function brand_hex(array $rgb): string
{
    return sprintf('#%02X%02X%02X', ...array_map(fn ($c) => max(0, min(255, (int) round($c))), $rgb));
}

/** WCAG 2 relative luminance of a #RRGGBB colour. */
function brand_luminance(string $hex): float
{
    $lin = fn ($c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    [$r, $g, $b] = brand_rgb($hex);
    return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
}

/** WCAG contrast ratio of two colours. */
function brand_contrast(string $a, string $b): float
{
    [$l1, $l2] = [brand_luminance($a), brand_luminance($b)];
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

/** #RRGGBB → [hue 0–360, saturation 0–1, lightness 0–1]. */
function brand_hsl(string $hex): array
{
    [$r, $g, $b] = array_map(fn ($c) => $c / 255, brand_rgb($hex));
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) {
        return [0.0, 0.0, $l];
    }
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = match ($max) {
        $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
        $g => ($b - $r) / $d + 2,
        default => ($r - $g) / $d + 4,
    };
    return [$h * 60, $s, $l];
}

function brand_from_hsl(float $h, float $s, float $l): string
{
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = match (true) {
        $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
        $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
    };
    return brand_hex([($r + $m) * 255, ($g + $m) * 255, ($b + $m) * 255]);
}

/**
 * The colour with the picked hue at the target luminance. Saturation follows the default colour's own saturation
 * relative to the default anchor (light tints stay soft, deep shades stay rich), scaled by the picked colour's
 * saturation; the lightness is found by bisection (luminance is monotonic in HSL lightness).
 */
function brand_shade(string $pick, string $defaultShade, string $defaultAnchor): string
{
    [$h, $sp] = brand_hsl($pick);
    [, $sa] = brand_hsl($defaultAnchor);
    [, $sk] = brand_hsl($defaultShade);
    $s = max(0.0, min(1.0, $sa > 0.01 ? $sp * ($sk / $sa) : $sk));
    $target = brand_luminance($defaultShade);
    [$lo, $hi] = [0.0, 1.0];
    for ($i = 0; $i < 30; $i++) {
        $mid = ($lo + $hi) / 2;
        if (brand_luminance(brand_from_hsl($h, $s, $mid)) < $target) {
            $lo = $mid;
        } else {
            $hi = $mid;
        }
    }
    return brand_from_hsl($h, $s, ($lo + $hi) / 2);
}

/**
 * Full scale for a picked colour: shade => #hex ($anchor = the default shade whose saturation is the reference). The
 * exact picked colour takes the shade whose default luminance is closest (so the scale stays ordered), unless that
 * shade has a contrast role the pick cannot meet ($minContrast: shade => minimum ratio against white, e.g. button
 * fills with white text) — then every shade is generated.
 */
function brand_scale(string $pick, array $defaults, int $anchor, array $minContrast = []): array
{
    $lp = brand_luminance($pick);
    $closest = null;
    foreach ($defaults as $k => $hex) {
        $dist = abs(log(($lp + 0.05) / (brand_luminance($hex) + 0.05)));
        if ($closest === null || $dist < $closest[1]) {
            $closest = [$k, $dist];
        }
    }
    $exactAt = $closest[0];
    if (isset($minContrast[$exactAt]) && brand_contrast('#FFFFFF', $pick) < $minContrast[$exactAt]) {
        $exactAt = null;
    }
    // the brand colour itself must show where people look (headings, navy bands — shade 900): use the exact pick
    // there whenever white text on it stays readable (WCAG AA 4.5:1). Before (2026-11-10) a mid-dark pick was moved to a
    // lighter shade and 900 kept a default-dark generated tone, so a changed colour looked unchanged.
    if ($anchor === 900 && isset($defaults[900]) && brand_contrast('#FFFFFF', $pick) >= 4.5) {
        $exactAt = 900;
    }
    $out = [];
    foreach ($defaults as $k => $hex) {
        $out[$k] = $k === $exactAt ? strtoupper($pick) : brand_shade($pick, $hex, $defaults[$anchor]);
    }
    return $out;
}

/** Contrast roles (minimum ratio against white) the exact pick must meet on its shade. */
function brand_min_contrast(string $family): array
{
    return $family === 'brand'
        ? [700 => 7.0, 800 => 12.0, 900 => 12.0, 950 => 12.0]   // navy bands with white/55 text, headings
        : [600 => 4.5, 700 => 4.5, 800 => 4.5];                   // filled buttons + small text on white
}

/* --------------------------------------------------------------------------------------------- default palettes */

/** The standard GIMT scales (tailwind.preset.cjs brand, tailwind.config.cjs designV3.accent). */
function brand_default_scales(): array
{
    return [
        'brand' => [50 => '#EFF4FB', 100 => '#DBE6F6', 200 => '#B8CDEC', 300 => '#89AADD', 400 => '#5480C8', 500 => '#2F5FB0',
            600 => '#1F4A94', 700 => '#183C7A', 800 => '#133266', 900 => '#0B2A5B', 950 => '#071A3B'],
        'accent' => [50 => '#FEF3EF', 100 => '#FDE4DA', 200 => '#FAC6B2', 300 => '#F6A083', 400 => '#F37A52', 500 => '#F05A28',
            600 => '#D03E16', 700 => '#B83511', 800 => '#962B0F', 900 => '#7A2410', 950 => '#431006'],
    ];
}

/**
 * Dark-theme colours that belong to the brand / accent families: CSS variable => default hex (the DARK map of
 * tailwind.config.cjs and the [data-theme="dark"] tokens of style.css). Keep in sync when those maps change.
 */
function brand_dark_vars(): array
{
    return [
        'brand' => [
            '--dk-bg-brand-50' => '#172748', '--dk-bg-brand-100' => '#1D2F55', '--dk-bg-brand-800' => '#1D3F7C', '--dk-bg-brand-900' => '#15336A', '--dk-bg-brand-950' => '#0A1530',
            '--dk-text-brand-100' => '#22365C', '--dk-text-brand-500' => '#8EAADF', '--dk-text-brand-600' => '#A3BAE3', '--dk-text-brand-700' => '#BCCDEA',
            '--dk-text-brand-800' => '#D7E0F1', '--dk-text-brand-900' => '#F1F4FA', '--dk-text-brand-950' => '#F1F4FA',
            '--dk-border-brand-100' => '#24365C', '--dk-border-brand-200' => '#2E4372', '--dk-border-brand-300' => '#3C5689', '--dk-border-brand-400' => '#5677B4',
            '--dk-border-brand-500' => '#6688C6', '--dk-border-brand-800' => '#8FA6D2', '--dk-border-brand-900' => '#A9BCE0',
            '--dk-bg-navy' => '#15336A', '--dk-bg-navy-deep' => '#050B19', '--dk-text-navy' => '#F1F4FA', '--dk-text-navy-deep' => '#F1F4FA',
            '--dk-bg-hue-all-tint' => '#16284A', '--dk-text-hue-all' => '#DBE6F6', '--dk-text-hue-all-ink' => '#DBE6F6',
            '--c-navy' => '#1F4683', '--c-navy-deep' => '#050B19', '--c-navy-800' => '#0E1F3F', '--c-navy-soft' => '#3A64AD',
            '--hue-all' => '#2A5395', '--hue-all-tint' => '#16284A', '--hue-all-ink' => '#DBE6F6',
        ],
        'accent' => [
            '--dk-bg-accent-50' => '#2C1D1D', '--dk-bg-accent-100' => '#3A231F', '--dk-bg-accent-200' => '#4A2B23',
            '--dk-text-accent-300' => '#FFB091', '--dk-text-accent-400' => '#FF8A60', '--dk-text-accent-500' => '#FF7548', '--dk-text-accent-600' => '#FF865A',
            '--dk-text-accent-700' => '#FF9B72', '--dk-text-accent-800' => '#FFAF8E', '--dk-text-accent-900' => '#FFC2A8',
            '--dk-border-accent-100' => '#4A2A20', '--dk-border-accent-200' => '#5C3123', '--dk-border-accent-300' => '#7A3D28',
            '--dk-bg-flame-tint' => '#33201B', '--dk-text-flame' => '#FF7548', '--dk-text-flame-text' => '#FF9B72',
            '--dk-bg-hue-mgt-tint' => '#33201A', '--dk-text-hue-mgt' => '#FF9B72', '--dk-text-hue-mgt-ink' => '#FFC2A8',
            '--c-flame' => '#FF6B3D', '--c-flame-text' => '#FF9B72', '--c-flame-tint' => '#33201B',
            '--hue-mgt-tint' => '#33201A', '--hue-mgt-ink' => '#FFC2A8',
        ],
    ];
}

/* --------------------------------------------------------------------------------------------- output */

/** "R G B" channel triplet for the CSS variables. */
function brand_channels(string $hex): string
{
    return implode(' ', brand_rgb($hex));
}

/**
 * Metric-matched fallback descriptors per catalog font, measured in Chrome (canvas text width of a typical English
 * paragraph + the font's ascent / descent / line gap) per weight — 400, 500 (vs Arial / Roboto), 600, 700, 800 (vs Arial
 * Bold / Roboto Bold); a weight the family does not have uses its nearest file, as the browser does. Each entry is
 * [size-adjust, ascent-override, descent-override, line-gap-override] in %. A "<Family> Fallback" face set built from
 * them sits right after the web font in the stack, so text keeps its line breaks and height when the web font swaps in
 * (no layout shift). Inter and Plus Jakarta Sans have theirs in style.css (same numbers).
 */
function site_font_fallback_metrics(): array
{
    return [
        // key => ['a' => Arial-based [400, 500, 600, 700, 800], 'r' => Roboto-based (Android) [400, 500, 600, 700, 800]]
        'inter' => ['a' => [[106.78, 90.84, 22.48, 0], [107.89, 89.91, 22.24, 0], [101.19, 95.86, 23.72, 0], [102.21, 94.9, 23.48, 0], [102.21, 94.9, 23.48, 0]],
            'r' => [[107.17, 90.51, 22.39, 0], [108.29, 89.58, 22.16, 0], [107.61, 90.14, 22.3, 0], [108.69, 89.25, 22.08, 0], [108.69, 89.25, 22.08, 0]]],
        'plus-jakarta-sans' => ['a' => [[104.91, 99.13, 20.97, 0], [106.18, 97.95, 20.72, 0], [99.69, 104.32, 22.07, 0], [100.82, 103.16, 21.82, 0], [101.9, 102.06, 21.59, 0]],
            'r' => [[105.3, 98.76, 20.89, 0], [106.57, 97.59, 20.64, 0], [106.01, 98.11, 20.75, 0], [107.21, 97.01, 20.52, 0], [108.36, 95.98, 20.3, 0]]],
        'poppins' => ['a' => [[112.03, 93.72, 31.24, 8.93], [113.26, 92.71, 30.9, 8.83], [106.03, 99.03, 33.01, 9.43], [107.02, 98.11, 32.7, 9.34], [107.87, 97.34, 32.45, 9.27]],
            'r' => [[112.45, 93.38, 31.13, 8.89], [113.68, 92.37, 30.79, 8.8], [112.75, 93.13, 31.04, 8.87], [113.8, 92.26, 30.75, 8.79], [114.71, 91.54, 30.51, 8.72]]],
        'montserrat' => ['a' => [[112.08, 86.55, 22.31, 0], [113.9, 85.16, 21.95, 0], [107.63, 90.13, 23.23, 0], [109.66, 88.46, 22.8, 0], [111.86, 86.71, 22.35, 0]],
            'r' => [[112.49, 86.23, 22.22, 0], [114.32, 84.85, 21.87, 0], [114.45, 84.76, 21.84, 0], [116.61, 83.18, 21.44, 0], [118.95, 81.55, 21.02, 0]]],
        'dm-sans' => ['a' => [[103.72, 95.45, 29.89, 0], [105.59, 93.76, 29.36, 0], [100.22, 98.78, 30.93, 0], [101.88, 97.17, 30.43, 0], [102.42, 96.67, 30.27, 0]],
            'r' => [[104.1, 95.1, 29.78, 0], [105.98, 93.41, 29.25, 0], [106.57, 92.9, 29.09, 0], [108.34, 91.38, 28.61, 0], [108.91, 90.9, 28.46, 0]]],
        'manrope' => ['a' => [[102.32, 104.58, 29.32, 0], [104.14, 102.75, 28.81, 0], [98.39, 108.75, 30.49, 0], [100.06, 106.94, 29.98, 0], [101.75, 105.16, 29.48, 0]],
            'r' => [[102.69, 104.19, 29.21, 0], [104.52, 102.37, 28.7, 0], [104.62, 102.27, 28.67, 0], [106.4, 100.56, 28.2, 0], [108.2, 98.89, 27.73, 0]]],
        'outfit' => ['a' => [[98.91, 101.1, 26.29, 0], [99.08, 100.93, 26.24, 0], [93.53, 106.91, 27.8, 0], [95.01, 105.26, 27.37, 0], [96.51, 103.62, 26.94, 0]],
            'r' => [[99.27, 100.73, 26.19, 0], [99.45, 100.56, 26.15, 0], [99.46, 100.54, 26.14, 0], [101.03, 98.98, 25.74, 0], [102.63, 97.44, 25.33, 0]]],
        'nunito-sans' => ['a' => [[100.95, 100.05, 34.67, 0], [101.81, 99.2, 34.38, 0], [95.36, 105.91, 36.7, 0], [97.33, 103.77, 35.96, 0], [99.5, 101.51, 35.18, 0]],
            'r' => [[101.32, 99.68, 34.54, 0], [102.19, 98.84, 34.25, 0], [101.41, 99.6, 34.51, 0], [103.5, 97.59, 33.82, 0], [105.81, 95.46, 33.08, 0]]],
        'open-sans' => ['a' => [[104.59, 102.3, 27.73, 0], [106.47, 100.5, 27.24, 0], [100.58, 106.38, 28.83, 0], [104.02, 102.86, 27.88, 0], [107.58, 99.46, 26.96, 0]],
            'r' => [[104.97, 101.93, 27.63, 0], [106.86, 100.13, 27.14, 0], [106.96, 100.04, 27.11, 0], [110.62, 96.73, 26.22, 0], [114.4, 93.53, 25.35, 0]]],
        'lato' => ['a' => [[97.02, 102.04, 21.64, 0], [97.02, 102.04, 21.64, 0], [92.2, 107.38, 22.78, 0], [92.2, 107.38, 22.78, 0], [92.2, 107.38, 22.78, 0]],
            'r' => [[97.38, 101.66, 21.56, 0], [97.38, 101.66, 21.56, 0], [98.04, 100.98, 21.42, 0], [98.04, 100.98, 21.42, 0], [98.04, 100.98, 21.42, 0]]],
        'roboto' => ['a' => [[99.63, 95.35, 25.09, 0], [100.53, 94.5, 24.87, 0], [100.53, 94.5, 24.87, 0], [94.04, 101.02, 26.58, 0], [94.04, 101.02, 26.58, 0]],
            'r' => [[100, 95, 25, 0], [100.9, 94.15, 24.78, 0], [100.9, 94.15, 24.78, 0], [100, 95, 25, 0], [100, 95, 25, 0]]],
        'mukta' => ['a' => [[93.2, 121.24, 56.86, 0], [94.93, 119.04, 55.83, 0], [89.59, 126.13, 59.16, 0], [91.11, 124.03, 58.17, 0], [92.61, 122.02, 57.23, 0]],
            'r' => [[93.55, 120.8, 56.66, 0], [95.27, 118.6, 55.63, 0], [95.27, 118.61, 55.63, 0], [96.88, 116.63, 54.7, 0], [98.48, 114.75, 53.82, 0]]],
    ];
}

/** CSS family name of a catalog font (without the "(English + हिन्दी)" note). */
function site_font_family(string $key): string
{
    return (string) preg_replace('/\s*\(.*\)$/', '', site_font_catalog()[$key][0] ?? 'Inter');
}

/** CSS font stack for a catalog font (the standard stacks stay with the Tailwind fallbacks). */
function site_font_stack(string $key, string $role): string
{
    $family = site_font_family($key);
    $generic = 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
    $fallback = isset(site_font_fallback_metrics()[$key]) ? ', "' . $family . ' Fallback"' : '';
    return '"' . $family . '"' . $fallback . ($role === 'display' && $key !== 'inter' ? ', Inter, "Inter Fallback"' : '') . ', ' . $generic;
}

/**
 * The "<Family> Fallback" @font-face set of a catalog font (one face per weight group, each for Arial and for Android's
 * Roboto — Chrome uses the last face whose local() font exists).
 */
function site_font_fallback_faces(string $key): string
{
    $m = site_font_fallback_metrics()[$key] ?? null;
    if (!$m) {
        return '';
    }
    $family = site_font_family($key) . ' Fallback';
    $src = [
        'a' => ["local('Arial'),local('Helvetica'),local('Liberation Sans')", "local('Arial Bold'),local('Arial-BoldMT'),local('Helvetica Bold'),local('Liberation Sans Bold')"],
        'r' => ["local('Roboto'),local('Roboto-Regular'),local('Roboto Regular')", "local('Roboto Bold'),local('Roboto-Bold')"],
    ];
    $css = '';
    $groups = [['100 400', 0], ['500', 0], ['600', 1], ['700', 1], ['800 900', 1]]; // weight range, bold local font?
    foreach (['a', 'r'] as $set) {
        foreach ($groups as $i => [$weights, $bold]) {
            [$size, $asc, $desc, $gap] = $m[$set][$i];
            $css .= '@font-face{font-family:"' . $family . '";font-weight:' . $weights . ';src:' . $src[$set][$bold] . ';size-adjust:' . $size . '%;ascent-override:' . $asc . '%;descent-override:' . $desc . '%;line-gap-override:' . $gap . '%}';
        }
    }
    return $css;
}

/**
 * URL of a bundled font file exactly as its @font-face references it — preloads must use the same URL or the browser
 * downloads the font twice (style.css declares Inter + Plus Jakarta Sans without a version; site_font_faces() adds ?v=).
 */
function site_font_url(string $key, string $file): string
{
    return in_array($key, ['inter', 'plus-jakarta-sans'], true) ? base_url($file) : asset($file);
}

/** @font-face rules (+ the metric-matched fallback faces) for a bundled font that style.css does not declare. */
function site_font_faces(string $key): string
{
    $cat = site_font_catalog();
    if (!isset($cat[$key]) || in_array($key, ['inter', 'plus-jakarta-sans'], true)) {
        return '';
    }
    [, $weights, $subsets] = $cat[$key];
    $family = site_font_family($key);
    $css = '';
    foreach ($weights as $w) {
        foreach (['latin' => null] + $subsets as $subset => $range) {
            $file = 'assets/fonts/' . $key . '-' . $subset . '-' . $w . '-normal.woff2';
            if (!is_file(APP_ROOT . '/' . $file)) {
                continue;
            }
            $css .= '@font-face{font-family:"' . $family . '";font-style:normal;font-weight:' . $w . ';font-display:swap;src:url("' . site_font_url($key, $file) . '") format("woff2")'
                . ($range ? ';unicode-range:' . $range : '') . '}';
        }
    }
    return $css . site_font_fallback_faces($key);
}

/**
 * Font files worth preloading (the text of the first screen): the heading font at its boldest weight ≤ 800 (hero
 * titles are extrabold) and the body font at 400 — with the exact URL their @font-face uses.
 */
function site_font_preloads(): array
{
    $s = site_brand_settings();
    $cat = site_font_catalog();
    $out = [];
    foreach ([[$s['site_font_heading'], 800], [$s['site_font_body'], 400]] as [$key, $want]) {
        $weights = array_filter($cat[$key][1] ?? [], fn ($w) => $w <= $want) ?: ($cat[$key][1] ?? [400]);
        $file = 'assets/fonts/' . $key . '-latin-' . max($weights) . '-normal.woff2';
        if (is_file(APP_ROOT . '/' . $file)) {
            $out[] = site_font_url($key, $file);
        }
    }
    return array_values(array_unique($out));
}

/**
 * The <style id="site-brand"> block (printed by includes/header.php right after style.css), or '' when the standard
 * design is in use. Light variables go on :root; the dark ones in [data-theme="dark"] (screen only), which comes after
 * style.css's own dark tokens and so wins at equal specificity.
 */
function site_brand_style(): string
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }
    $s = site_brand_settings();
    $d = site_brand_defaults();
    $scales = brand_default_scales();
    $darkVars = brand_dark_vars();
    $root = [];
    $dark = [];
    foreach (['brand' => ['site_brand_color', 900], 'accent' => ['site_accent_color', 500]] as $fam => [$key, $anchor]) {
        if ($s[$key] === $d[$key]) {
            continue;
        }
        foreach (brand_scale($s[$key], $scales[$fam], $anchor, brand_min_contrast($fam)) as $k => $hex) {
            $root['--' . $fam . '-' . $k] = brand_channels($hex);
        }
        if ($fam === 'accent') {
            $root['--flame-text'] = brand_channels(brand_shade($s[$key], '#C93C14', $scales['accent'][500]));
            $root['--flame-tint'] = brand_channels(brand_shade($s[$key], '#FDEDE7', $scales['accent'][500]));
        }
        foreach ($darkVars[$fam] as $var => $hex) {
            $gen = brand_shade($s[$key], $hex, $scales[$fam][$anchor]);
            // --dk-* hold channel triplets; the --c-* / --hue-* dark tokens hold complete colours
            $dark[$var] = str_starts_with($var, '--dk-') ? brand_channels($gen) : $gen;
        }
    }
    $faces = '';
    if ($s['site_font_heading'] !== $d['site_font_heading']) {
        $root['--font-display'] = site_font_stack($s['site_font_heading'], 'display');
        $faces .= site_font_faces($s['site_font_heading']);
    }
    if ($s['site_font_body'] !== $d['site_font_body']) {
        $root['--font-sans'] = site_font_stack($s['site_font_body'], 'sans');
        if ($s['site_font_body'] !== $s['site_font_heading']) {
            $faces .= site_font_faces($s['site_font_body']);
        }
    }
    // body text colour (Settings › Website › Text colour): paragraphs on light backgrounds — the --c-body token plus the
    // grey text utilities the pages use; the dark theme keeps its own light text
    $textCss = '';
    if ($s['site_text_color'] !== $d['site_text_color']) {
        $t = $s['site_text_color'];
        $root['--c-body'] = $t;
        $light = 'html:not([data-theme="dark"]) body.site-v2';
        $textCss = $light . ' :is(.text-slate-600,.text-slate-700,.prose-site){color:' . $t . '}'
            . $light . ' .text-slate-500{color:color-mix(in srgb,' . $t . ' 84%,#fff)}';
    }
    if (!$root) {
        return $out = '';
    }
    $decl = fn (array $vars) => implode('', array_map(fn ($k, $v) => $k . ':' . $v . ';', array_keys($vars), $vars));
    $css = $faces . ':root{' . $decl($root) . '}' . $textCss . ($dark ? '@media screen{[data-theme="dark"]{' . $decl($dark) . '}}' : '');
    return $out = '<style id="site-brand">' . $css . '</style>';
}
