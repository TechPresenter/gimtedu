<?php
/**
 * Website CMS domain logic (unit cms_site) — shared by the CRUD modules, api/routes/{cms,menus,seo}.php, the seeders
 * and the public website. The content contract lives in docs/CMS.md; the definitions below are its source of truth:
 *
 *   cms_section_types() / cms_block_types()   field definitions (served to the admin via GET /api/cms/schema)
 *   cms_validate_section() / cms_validate_blocks()   server-side validation against those definitions
 *   cms_default_sections()                    complete home page content (seeder + "Reset to default")
 *   cms_page_url() / cms_page_blocks() / cms_paragraphs()            helpers for the website
 *   cms_menu_tree() / cms_menu_save_tree() / cms_sync_program_menu()  menus
 *   cms_seo_entries() / cms_seo_overview() / cms_sitemap_urls() / cms_robots_txt()   SEO
 *   cms_stat_values() / cms_stat_value()      live numbers for stat items with a `source`
 */

if (function_exists('cms_section_types')) {
    return;
}
// CrudException / CrudValidationException (menus) — the website and CLI seeders do not load the CRUD engine themselves.
require_once APP_ROOT . '/app/crud.php';

/* =====================================================================================================================
 * Option lists
 * ===================================================================================================================*/

/** Curated lucide icon names available to editors (all exist in the PHP icon() set and the website Icon map). */
function cms_icon_names(): array
{
    return ['graduation-cap', 'school', 'book-open', 'library', 'landmark', 'building-2', 'building', 'university', 'users', 'user', 'users-round',
        'user-round', 'user-check', 'briefcase-business', 'briefcase', 'handshake', 'factory', 'trophy', 'award', 'medal', 'badge-check',
        'shield-check', 'target', 'eye', 'heart', 'lightbulb', 'rocket', 'sparkles', 'star', 'globe', 'compass', 'cpu', 'code-xml', 'laptop',
        'monitor', 'microscope', 'flask-conical', 'chart-column', 'trending-up', 'calculator', 'wallet', 'indian-rupee', 'badge-indian-rupee',
        'calendar-days', 'calendar-check', 'clock', 'clipboard-check', 'file-text', 'file-check', 'newspaper', 'megaphone', 'message-circle',
        'mail', 'phone', 'map-pin', 'bus', 'bed-double', 'dumbbell', 'trees', 'leaf', 'palette', 'pen-tool', 'presentation', 'layout-grid',
        'search', 'upload', 'download', 'circle-play', 'image', 'check', 'circle-check', 'arrow-right', 'send', 'network', 'layers', 'zap',
        'flag', 'gift', 'smile', 'thumbs-up', 'stethoscope', 'utensils', 'lock'];
}

/**
 * Permission guard for the CMS / SEO API routes: a readable 403 ("…edit website content") instead of the generic
 * module label, then the standard api_require() check.
 */
function cms_api_require(string $module, string $action = 'view'): void
{
    if (!can($module, $action)) {
        $what = $module === 'seo' ? 'SEO settings' : 'website content';
        $verb = ['view' => 'view', 'create' => 'add', 'edit' => 'change', 'delete' => 'delete', 'publish' => 'publish', 'manage' => 'manage'][$action] ?? $action;
        api_error('You do not have permission to ' . $verb . ' ' . $what . '. Ask an administrator for access.', 403);
    }
    api_require($module, $action);
}

/** Turn ['a' => 'A'] into [['value' => 'a', 'label' => 'A']]. */
function cms_opts(array $map): array
{
    $out = [];
    foreach ($map as $k => $v) {
        $out[] = ['value' => (string) $k, 'label' => (string) $v];
    }
    return $out;
}

function cms_tints(): array
{
    return ['lavender' => 'Lavender', 'sky' => 'Sky', 'aqua' => 'Aqua', 'cream' => 'Cream', 'sand' => 'Sand', 'orange' => 'Orange-red', 'ink' => 'Navy'];
}

function cms_page_templates(): array
{
    return ['default' => 'Default (page hero + content)', 'full_width' => 'Full width', 'landing' => 'Landing page (no hero)', 'sidebar' => 'With enquiry sidebar'];
}

/** location => [label, description, max depth] */
function cms_menu_locations(): array
{
    return [
        'header' => ['label' => 'Header (main navigation)', 'short' => 'Header', 'description' => 'Main navigation with dropdowns and the Programs mega menu (also used by the side drawer).', 'depth' => 3],
        'footer_quick' => ['label' => 'Footer · Institute', 'short' => 'Footer · Institute', 'description' => 'First footer link column ("Institute").', 'depth' => 1],
        'footer_academics' => ['label' => 'Footer · Programs', 'short' => 'Footer · Programs', 'description' => 'Second footer link column ("Programs").', 'depth' => 1],
        'footer_admissions' => ['label' => 'Footer · Admissions', 'short' => 'Footer · Admissions', 'description' => 'Third footer link column ("Admissions").', 'depth' => 1],
        'footer_support' => ['label' => 'Footer · Student Corner', 'short' => 'Footer · Students', 'description' => 'Fourth footer link column ("Student Corner").', 'depth' => 1],
        'legal' => ['label' => 'Footer · Legal bar', 'short' => 'Legal bar', 'description' => 'Policy links in the bottom bar.', 'depth' => 1],
        'topbar' => ['label' => 'Top bar links (optional)', 'short' => 'Top bar', 'description' => 'Optional login links for a top bar; the current design shows the announcement strip there.', 'depth' => 1],
        'mobile' => ['label' => 'Mobile drawer (override)', 'short' => 'Mobile drawer', 'description' => 'Optional; when empty the drawer uses the header menu.', 'depth' => 2],
    ];
}

function cms_banner_placements(): array
{
    return ['home_hero' => 'Home hero slider', 'page_header' => 'Inner page promo', 'popup' => 'Home popup', 'strip' => 'Promo strip'];
}

function cms_announcement_types(): array
{
    return ['topbar' => 'Top bar', 'ticker' => 'News ticker', 'popup' => 'Popup', 'banner' => 'Alert banner'];
}

function cms_program_category_options(): array
{
    return ['all' => 'All Programs', 'ug' => 'Undergraduate', 'pg' => 'Postgraduate', 'management' => 'Management', 'technology' => 'Technology',
        'commerce' => 'Commerce', 'diploma' => 'Diploma', 'certificate' => 'Certificate'];
}

function cms_faq_categories(): array
{
    return ['general' => 'General', 'admissions' => 'Admissions', 'academics' => 'Academics', 'fees' => 'Fees', 'hostel' => 'Hostel', 'placement' => 'Placement', 'campus' => 'Campus'];
}

function cms_testimonial_types(): array
{
    return ['student' => 'Students', 'alumni' => 'Alumni', 'parent' => 'Parents', 'recruiter' => 'Recruiters'];
}

function cms_robots_options(): array
{
    return ['index,follow' => 'index, follow (default)', 'noindex,follow' => 'noindex, follow', 'index,nofollow' => 'index, nofollow', 'noindex,nofollow' => 'noindex, nofollow'];
}

function cms_changefreq_options(): array
{
    return ['always' => 'Always', 'hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly', 'never' => 'Never'];
}

/* =====================================================================================================================
 * Live statistics
 * ===================================================================================================================*/

function cms_stat_sources(): array
{
    return [
        'programs' => 'Programs offered', 'departments' => 'Departments', 'students' => 'Active students', 'faculty' => 'Faculty members',
        'alumni' => 'Alumni', 'recruiters' => 'Recruiting companies', 'offers' => 'Placement offers', 'placement_rate' => 'Placement rate (%)',
        'highest_package' => 'Highest package (LPA)', 'average_package' => 'Average package (LPA)', 'years' => 'Years of excellence',
        'satisfaction' => 'Student satisfaction (%)',
    ];
}

/** Live values for every stat source (null when the data is not available). */
function cms_stat_values(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $q = function (string $sql, array $args = []) {
        try {
            $v = db_value($sql, $args);
            return $v === null || $v === false ? null : $v + 0;
        } catch (Throwable $e) {
            return null;
        }
    };
    $applied = $q('SELECT COUNT(DISTINCT student_id) FROM placement_applications');
    $placed = $q("SELECT COUNT(DISTINCT student_id) FROM placement_offers WHERE status IN ('offered','accepted','joined')");
    $established = (int) setting('established_year', 0);
    $cache = [
        'programs' => $q("SELECT COUNT(*) FROM programs WHERE status = 'active' AND show_on_website = 1"),
        'departments' => $q("SELECT COUNT(*) FROM departments WHERE status = 'active'"),
        'students' => $q("SELECT COUNT(*) FROM students WHERE status = 'active'"),
        'faculty' => $q("SELECT COUNT(*) FROM faculty WHERE status = 'active'"),
        'alumni' => $q('SELECT COUNT(*) FROM alumni'),
        'recruiters' => $q("SELECT COUNT(*) FROM companies WHERE status = 'active' AND is_recruiter = 1"),
        'offers' => $q("SELECT COUNT(*) FROM placement_offers WHERE status IN ('offered','accepted','joined')"),
        'placement_rate' => $applied ? (int) round(($placed ?? 0) / $applied * 100) : null,
        'highest_package' => ($v = $q("SELECT MAX(package_lpa) FROM placement_offers WHERE status <> 'declined'")) !== null ? round((float) $v, 1) : null,
        'average_package' => ($v = $q("SELECT AVG(package_lpa) FROM placement_offers WHERE status <> 'declined'")) !== null ? round((float) $v, 1) : null,
        'years' => $established > 1900 ? (int) date('Y') - $established : null,
        'satisfaction' => (int) setting('student_satisfaction', 95),
    ];
    return $cache;
}

/** Live value of a stat source, or $fallback. */
function cms_stat_value(?string $source, $fallback = null)
{
    if (!$source) {
        return $fallback;
    }
    $v = cms_stat_values()[$source] ?? null;
    return $v === null ? $fallback : $v;
}

/* =====================================================================================================================
 * Field definitions (schema)
 * ===================================================================================================================*/

/** Field descriptor: cms_fd('text', 'title', 'Title', ['required' => true, 'max' => 190]) */
function cms_fd(string $type, string $name, string $label, array $o = []): array
{
    return ['type' => $type, 'name' => $name, 'label' => $label] + $o;
}

function cms_link_fd(string $name, string $label, array $o = []): array
{
    return cms_fd('link', $name, $label, $o + ['col' => 12]);
}

function cms_stat_item_fields(): array
{
    return [
        cms_fd('text', 'value', 'Value', ['required' => true, 'max' => 20, 'col' => 4, 'placeholder' => '1000']),
        cms_fd('text', 'prefix', 'Prefix', ['max' => 5, 'col' => 4, 'placeholder' => '₹']),
        cms_fd('text', 'suffix', 'Suffix', ['max' => 10, 'col' => 4, 'placeholder' => '+']),
        cms_fd('text', 'label', 'Label', ['required' => true, 'max' => 60, 'col' => 12]),
        cms_fd('icon', 'icon', 'Icon', ['col' => 6]),
        cms_fd('select', 'source', 'Live value', ['col' => 6, 'options' => cms_opts(['' => 'None — use the value above'] + cms_stat_sources()),
            'help' => 'When set, the website shows the live number from the database.']),
    ];
}

function cms_heading_fields(bool $subheading = true): array
{
    $f = [
        cms_fd('text', 'eyebrow', 'Eyebrow', ['max' => 120, 'col' => 4, 'placeholder' => 'Small label']),
        cms_fd('text', 'heading', 'Heading', ['max' => 190, 'col' => 8]),
    ];
    if ($subheading) {
        $f[] = cms_fd('textarea', 'subheading', 'Intro text', ['max' => 500, 'rows' => 2]);
    }
    return $f;
}

/**
 * Home page sections: key => [label, icon, description, title_label, subtitle_label, title_required, fields].
 * `title` and `subtitle` are table columns; `fields` describe the `content` JSON.
 */
function cms_section_types(): array
{
    $eyebrow = cms_fd('text', 'eyebrow', 'Eyebrow', ['max' => 120, 'col' => 6, 'placeholder' => 'Small label above the heading']);
    $anchor = cms_fd('text', 'anchor', 'Anchor id', ['max' => 60, 'col' => 6, 'placeholder' => 'e.g. programs', 'pattern' => 'anchor', 'help' => 'For links such as /#programs']);
    return [
        'hero' => ['label' => 'Hero', 'icon' => 'panels-top-left', 'description' => 'Headline, photo, buttons, glass chips and campus tour video.',
            'title_label' => 'Headline (line 1)', 'subtitle_label' => 'Paragraph', 'title_required' => true, 'fields' => [
                cms_fd('text', 'eyebrow', 'Eyebrow / tagline', ['max' => 120, 'col' => 6, 'placeholder' => 'Shaping Future Leaders']),
                cms_fd('text', 'highlight', 'Highlighted line', ['max' => 120, 'col' => 6, 'placeholder' => 'Build Your Legacy.', 'help' => 'Shown in the accent colour.']),
                cms_fd('image', 'image', 'Hero photo', ['required' => true, 'col' => 6]),
                cms_fd('text', 'image_alt', 'Photo description (alt text)', ['max' => 190, 'col' => 6]),
                cms_link_fd('primary_cta', 'Primary button'),
                cms_link_fd('secondary_cta', 'Secondary button'),
                cms_fd('text', 'badge', 'Badge', ['max' => 120, 'col' => 6, 'placeholder' => 'Admissions Open for 2026-27']),
                cms_fd('list', 'tagline_words', 'Tagline words', ['max_items' => 6, 'item_max' => 20, 'col' => 6, 'placeholder' => 'Learn']),
                cms_fd('repeater', 'chips', 'Floating chips', ['max_items' => 6, 'item_label' => 'label', 'fields' => [
                    cms_fd('icon', 'icon', 'Icon', ['col' => 5]), cms_fd('text', 'label', 'Label', ['required' => true, 'max' => 40, 'col' => 7])]]),
                cms_fd('url', 'video_url', 'Campus tour video URL', ['col' => 6, 'placeholder' => 'https://www.youtube.com/watch?v=…']),
                cms_fd('text', 'video_label', 'Video button label', ['max' => 60, 'col' => 6, 'placeholder' => 'Watch Our Campus Tour']),
                cms_fd('toggle', 'use_banners', 'Use Banners / Sliders as hero slides', ['help' => 'When active "Home hero slider" banners exist, the hero becomes a slider built from them.']),
            ]],
        'stats' => ['label' => 'Key statistics', 'icon' => 'chart-column', 'description' => 'Numbers card overlapping the hero (or a dark stats band).',
            'title_label' => 'Heading (optional)', 'subtitle_label' => 'Intro (optional)', 'fields' => [
                cms_fd('select', 'variant', 'Style', ['col' => 6, 'default' => 'overlap', 'options' => cms_opts(['overlap' => 'Card overlapping the hero', 'band' => 'Dark navy band', 'cards' => 'Separate cards'])]),
                $anchor,
                cms_fd('repeater', 'items', 'Statistics', ['required' => true, 'min_items' => 2, 'max_items' => 6, 'item_label' => 'label', 'fields' => cms_stat_item_fields()]),
            ]],
        'features' => ['label' => 'Feature strip', 'icon' => 'layout-grid', 'description' => 'Icon strip under the hero (Academic Excellence, Industry-Focused Curriculum …).',
            'title_label' => 'Heading (screen readers)', 'subtitle_label' => 'Intro (optional)', 'fields' => [
                cms_fd('repeater', 'items', 'Features', ['required' => true, 'min_items' => 3, 'max_items' => 8, 'item_label' => 'title', 'fields' => [
                    cms_fd('icon', 'icon', 'Icon', ['required' => true, 'col' => 4]), cms_fd('text', 'title', 'Title', ['required' => true, 'max' => 60, 'col' => 8]),
                    cms_fd('text', 'text', 'Short text', ['max' => 160])]]),
            ]],
        'programs' => ['label' => 'Programs', 'icon' => 'graduation-cap', 'description' => 'Program explorer with category pills, from the Programs table.',
            'title_label' => 'Heading', 'subtitle_label' => 'Intro', 'title_required' => true, 'fields' => [
                $eyebrow, $anchor,
                cms_fd('multiselect', 'categories', 'Filter pills', ['options' => cms_opts(cms_program_category_options()), 'help' => 'Category pills shown above the cards.']),
                cms_fd('select', 'mode', 'Programs to show', ['col' => 6, 'default' => 'all', 'options' => cms_opts(['all' => 'All active programs', 'featured' => 'Featured programs only', 'selected' => 'Hand-picked programs'])]),
                cms_fd('number', 'limit', 'Maximum cards', ['col' => 6, 'min' => 3, 'max' => 24, 'default' => 6]),
                cms_fd('programs', 'program_ids', 'Hand-picked programs', ['when' => ['mode' => 'selected'], 'required' => true, 'help' => 'Shown in this order.']),
                cms_link_fd('view_all', 'View all link'),
            ]],
        'why' => ['label' => 'Why choose GIMT', 'icon' => 'sparkles', 'description' => 'Icon cards with the reasons to choose GIMT.',
            'title_label' => 'Heading', 'subtitle_label' => 'Intro', 'title_required' => true, 'fields' => [
                $eyebrow, cms_fd('image', 'image', 'Side photo (optional)', ['col' => 6]),
                cms_fd('repeater', 'items', 'Reasons', ['required' => true, 'min_items' => 4, 'max_items' => 12, 'item_label' => 'title', 'fields' => [
                    cms_fd('text', 'title', 'Title', ['required' => true, 'max' => 60, 'col' => 12]), cms_fd('icon', 'icon', 'Icon', ['required' => true, 'col' => 6]),
                    cms_fd('select', 'tint', 'Tint', ['col' => 6, 'options' => cms_opts(['' => 'Auto'] + cms_tints())]),
                    cms_fd('textarea', 'text', 'Text', ['max' => 200, 'rows' => 2])]]),
            ]],
        'academic_excellence' => ['label' => 'About & academic excellence', 'icon' => 'landmark', 'description' => 'About GIMT text, check list, campus photo and Vision / Mission cards.',
            'title_label' => 'Heading', 'subtitle_label' => 'Lead', 'title_required' => true, 'fields' => [
                $eyebrow, cms_link_fd('cta', 'Button'),
                cms_fd('textarea', 'text', 'Text', ['max' => 2000, 'rows' => 5, 'help' => 'Leave a blank line between paragraphs.']),
                cms_fd('list', 'bullets', 'Check list', ['max_items' => 8, 'item_max' => 120]),
                cms_fd('image', 'image', 'Photo', ['col' => 6]), cms_fd('text', 'image_alt', 'Photo description', ['max' => 190, 'col' => 6]),
                cms_fd('url', 'video_url', 'Virtual tour video URL', ['col' => 6]), cms_fd('text', 'video_label', 'Video chip label', ['max' => 60, 'col' => 6]),
                cms_fd('repeater', 'cards', 'Overlay cards', ['max_items' => 3, 'item_label' => 'title', 'fields' => [
                    cms_fd('icon', 'icon', 'Icon', ['col' => 4]), cms_fd('text', 'title', 'Title', ['required' => true, 'max' => 60, 'col' => 8]),
                    cms_fd('textarea', 'text', 'Text', ['max' => 300, 'rows' => 2])]]),
            ]],
        'admission_process' => ['label' => 'Admission journey', 'icon' => 'clipboard-check', 'description' => 'Numbered admission steps with a call to action.',
            'title_label' => 'Heading', 'subtitle_label' => 'Intro', 'title_required' => true, 'fields' => [
                $eyebrow, cms_link_fd('cta', 'Button'),
                cms_fd('image', 'image', 'Photo', ['col' => 6]), cms_fd('text', 'image_alt', 'Photo description', ['max' => 190, 'col' => 6]),
                cms_fd('repeater', 'steps', 'Steps', ['required' => true, 'min_items' => 3, 'max_items' => 7, 'item_label' => 'title', 'fields' => [
                    cms_fd('icon', 'icon', 'Icon', ['col' => 4]), cms_fd('text', 'title', 'Title', ['required' => true, 'max' => 60, 'col' => 8]),
                    cms_fd('textarea', 'text', 'Text', ['max' => 200, 'rows' => 2])]]),
            ]],
        'campus_life' => ['label' => 'Campus life cards', 'icon' => 'trees', 'description' => 'Photo cards with overlay: Campus Life, Placements, News & Events.',
            'title_label' => 'Heading', 'subtitle_label' => 'Intro', 'fields' => [
                $eyebrow, $anchor,
                cms_fd('repeater', 'cards', 'Cards', ['required' => true, 'min_items' => 2, 'max_items' => 6, 'item_label' => 'title', 'fields' => [
                    cms_fd('text', 'title', 'Title', ['required' => true, 'max' => 80, 'col' => 6]), cms_fd('image', 'image', 'Photo', ['required' => true, 'col' => 6]),
                    cms_fd('textarea', 'text', 'Text', ['max' => 200, 'rows' => 2]),
                    cms_fd('url', 'url', 'Link', ['col' => 6]), cms_fd('text', 'link_label', 'Link label', ['max' => 40, 'col' => 6])]]),
            ]],
        'placements' => ['label' => 'Placement highlights', 'icon' => 'briefcase-business', 'description' => 'Placement statistics, text and the latest top offers.',
            'title_label' => 'Heading', 'subtitle_label' => 'Intro', 'title_required' => true, 'fields' => [
                $eyebrow, cms_link_fd('cta', 'Button'),
                cms_fd('textarea', 'text', 'Text', ['max' => 1000, 'rows' => 3]),
                cms_fd('image', 'image', 'Photo', ['col' => 6]), cms_fd('text', 'image_alt', 'Photo description', ['max' => 190, 'col' => 6]),
                cms_fd('repeater', 'stats', 'Statistics', ['max_items' => 4, 'item_label' => 'label', 'fields' => cms_stat_item_fields()]),
                cms_fd('toggle', 'show_top_offers', 'Show latest top offers', ['col' => 6, 'help' => 'From Placement › Offers (accepted offers, highest package first).']),
                cms_fd('number', 'offers_limit', 'Offers to show', ['col' => 6, 'min' => 3, 'max' => 12, 'default' => 6, 'when' => ['show_top_offers' => true]]),
            ]],
        'recruiters' => ['label' => 'Recruiters', 'icon' => 'handshake', 'description' => 'Logo marquee of recruiting companies.',
            'title_label' => 'Heading', 'subtitle_label' => 'Intro', 'fields' => [
                $eyebrow, cms_link_fd('cta', 'Button'),
                cms_fd('select', 'source', 'Companies', ['col' => 12, 'default' => 'companies', 'options' => cms_opts(['companies' => 'From Placement › Companies', 'manual' => 'Enter manually'])]),
                cms_fd('number', 'limit', 'Maximum logos', ['col' => 6, 'min' => 6, 'max' => 48, 'default' => 24]),
                cms_fd('select', 'rows', 'Rows', ['col' => 6, 'default' => '2', 'options' => cms_opts(['1' => 'One row', '2' => 'Two rows'])]),
                cms_fd('repeater', 'items', 'Companies', ['when' => ['source' => 'manual'], 'required' => true, 'min_items' => 1, 'max_items' => 48, 'item_label' => 'name', 'fields' => [
                    cms_fd('text', 'name', 'Name', ['required' => true, 'max' => 80, 'col' => 6]), cms_fd('image', 'logo', 'Logo', ['col' => 6]),
                    cms_fd('url', 'url', 'Website', ['col' => 6]), cms_fd('text', 'industry', 'Industry', ['max' => 60, 'col' => 6])]]),
            ]],
        'testimonials' => ['label' => 'Success stories', 'icon' => 'message-circle', 'description' => 'Testimonial slider from Website CMS › Testimonials.',
            'title_label' => 'Heading', 'subtitle_label' => 'Intro', 'title_required' => true, 'fields' => [
                $eyebrow, cms_link_fd('cta', 'Button'),
                cms_fd('multiselect', 'types', 'Show', ['options' => cms_opts(cms_testimonial_types()), 'help' => 'Leave empty to show every type.']),
                cms_fd('number', 'limit', 'Maximum stories', ['col' => 6, 'min' => 3, 'max' => 20, 'default' => 8]),
                cms_fd('number', 'autoplay', 'Autoplay (ms, 0 = off)', ['col' => 6, 'min' => 0, 'max' => 20000, 'step' => 500, 'default' => 7000]),
            ]],
        'events_news' => ['label' => 'Events & news', 'icon' => 'newspaper', 'description' => 'Upcoming events, latest news and notices.',
            'title_label' => 'Heading', 'subtitle_label' => 'Intro', 'title_required' => true, 'fields' => [
                $eyebrow, $anchor,
                cms_fd('toggle', 'show_events', 'Upcoming events', ['col' => 6, 'default' => true]),
                cms_fd('number', 'events_limit', 'Events to show', ['col' => 6, 'min' => 1, 'max' => 6, 'default' => 3]),
                cms_fd('toggle', 'show_news', 'Latest news', ['col' => 6, 'default' => true]),
                cms_fd('number', 'news_limit', 'News to show', ['col' => 6, 'min' => 1, 'max' => 6, 'default' => 3]),
                cms_fd('toggle', 'show_notices', 'Notice board', ['col' => 6, 'default' => true]),
                cms_fd('number', 'notices_limit', 'Notices to show', ['col' => 6, 'min' => 1, 'max' => 10, 'default' => 5]),
                cms_link_fd('view_all_events', 'All events link'), cms_link_fd('view_all_news', 'All news link'),
            ]],
        'faqs' => ['label' => 'FAQs', 'icon' => 'circle-help', 'description' => 'Accordion of frequently asked questions from Website CMS › FAQs.',
            'title_label' => 'Heading', 'subtitle_label' => 'Intro', 'title_required' => true, 'fields' => [
                $eyebrow, cms_link_fd('view_all', 'View all link'),
                cms_fd('multiselect', 'categories', 'Categories', ['options' => cms_opts(cms_faq_categories()), 'help' => 'Leave empty for every category.']),
                cms_fd('toggle', 'home_only', 'Only FAQs marked "Show on home"', ['col' => 12, 'default' => true]),
                cms_fd('number', 'limit', 'Maximum questions', ['col' => 6, 'min' => 3, 'max' => 20, 'default' => 8]),
                cms_fd('select', 'columns', 'Columns', ['col' => 6, 'default' => '2', 'options' => cms_opts(['1' => 'One', '2' => 'Two'])]),
            ]],
        'cta' => ['label' => 'Call to action', 'icon' => 'megaphone', 'description' => 'Closing band with buttons and an optional enquiry form.',
            'title_label' => 'Heading', 'subtitle_label' => 'Text', 'title_required' => true, 'fields' => [
                $eyebrow, cms_fd('select', 'theme', 'Theme', ['col' => 6, 'default' => 'dark', 'options' => cms_opts(['dark' => 'Dark (navy)', 'orange' => 'Orange-red', 'light' => 'Light (warm grey)'])]),
                cms_link_fd('primary', 'Primary button'), cms_link_fd('secondary', 'Secondary button'),
                cms_fd('image', 'image', 'Photo', ['col' => 6]),
                cms_fd('toggle', 'show_enquiry_form', 'Show enquiry form', ['col' => 6, 'help' => 'Leads go to Enquiries (source: home_cta).']),
            ]],
    ];
}

/** Page builder block types: type => [label, icon, group, description, fields, default data]. */
function cms_block_types(): array
{
    $stock = fn (string $f) => 'assets/images/site/' . $f;
    return [
        'hero' => ['label' => 'Hero', 'icon' => 'panels-top-left', 'group' => 'Layout', 'description' => 'Large heading with photo and buttons.', 'fields' => [
            cms_fd('text', 'eyebrow', 'Eyebrow', ['max' => 120, 'col' => 4]), cms_fd('text', 'title', 'Title', ['required' => true, 'max' => 190, 'col' => 8]),
            cms_fd('text', 'highlight', 'Highlighted line', ['max' => 120, 'col' => 12]),
            cms_fd('select', 'align', 'Alignment', ['col' => 6, 'default' => 'left', 'options' => cms_opts(['left' => 'Left', 'center' => 'Centre'])]),
            cms_fd('select', 'size', 'Size', ['col' => 6, 'default' => 'md', 'options' => cms_opts(['md' => 'Medium', 'lg' => 'Large'])]),
            cms_fd('textarea', 'text', 'Text', ['max' => 600, 'rows' => 3]),
            cms_fd('image', 'image', 'Photo', ['col' => 6]), cms_fd('text', 'image_alt', 'Photo description', ['max' => 190, 'col' => 6]),
            cms_link_fd('primary_cta', 'Primary button'), cms_link_fd('secondary_cta', 'Secondary button'),
            cms_fd('repeater', 'chips', 'Feature chips', ['max_items' => 6, 'item_label' => 'label', 'fields' => [
                cms_fd('icon', 'icon', 'Icon', ['col' => 5]), cms_fd('text', 'label', 'Label', ['required' => true, 'max' => 40, 'col' => 7])]]),
        ], 'default' => ['title' => 'A heading that inspires', 'highlight' => 'and a line that stands out.', 'text' => 'Introduce this page in one or two sentences.',
            'image' => $stock('campus-building.jpg'), 'image_alt' => 'GIMT campus', 'align' => 'left', 'size' => 'md',
            'primary_cta' => ['label' => 'Apply Now', 'url' => '/apply'], 'secondary_cta' => ['label' => 'Contact Us', 'url' => '/contact'], 'chips' => []]],
        'text' => ['label' => 'Rich text', 'icon' => 'file-text', 'group' => 'Content', 'description' => 'Formatted text with headings, lists and links.', 'fields' => [
            cms_fd('text', 'heading', 'Heading', ['max' => 190, 'col' => 12]),
            cms_fd('select', 'align', 'Alignment', ['col' => 6, 'default' => 'left', 'options' => cms_opts(['left' => 'Left', 'center' => 'Centre'])]),
            cms_fd('select', 'width', 'Width', ['col' => 6, 'default' => 'normal', 'options' => cms_opts(['narrow' => 'Narrow', 'normal' => 'Normal', 'wide' => 'Wide'])]),
            cms_fd('richtext', 'html', 'Content', ['required' => true]),
        ], 'default' => ['heading' => 'Section heading', 'html' => '<p>Write your content here. Use headings, lists and links to make it easy to scan.</p>', 'align' => 'left', 'width' => 'normal']],
        'image' => ['label' => 'Image', 'icon' => 'image', 'group' => 'Media', 'description' => 'A single photo with caption.', 'fields' => [
            cms_fd('image', 'image', 'Image', ['required' => true, 'col' => 6]), cms_fd('text', 'alt', 'Description (alt text)', ['required' => true, 'max' => 190, 'col' => 6]),
            cms_fd('text', 'caption', 'Caption', ['max' => 255, 'col' => 12]),
            cms_fd('select', 'width', 'Width', ['col' => 6, 'default' => 'normal', 'options' => cms_opts(['normal' => 'Normal', 'wide' => 'Wide', 'full' => 'Full width'])]),
            cms_fd('url', 'link', 'Link (optional)', ['col' => 6]),
        ], 'default' => ['image' => $stock('campus-main.jpg'), 'alt' => 'GIMT main campus', 'caption' => '', 'width' => 'normal']],
        'video' => ['label' => 'Video', 'icon' => 'circle-play', 'group' => 'Media', 'description' => 'YouTube, Vimeo or MP4 video with a poster.', 'fields' => [
            cms_fd('url', 'url', 'Video URL', ['required' => true, 'col' => 12, 'placeholder' => 'https://www.youtube.com/watch?v=…', 'pattern' => 'video']),
            cms_fd('select', 'aspect', 'Aspect ratio', ['col' => 6, 'default' => '16:9', 'options' => cms_opts(['16:9' => '16:9', '4:3' => '4:3', '1:1' => '1:1'])]),
            cms_fd('text', 'title', 'Title', ['max' => 190, 'col' => 6]), cms_fd('image', 'poster', 'Poster image', ['col' => 6]),
            cms_fd('text', 'caption', 'Caption', ['max' => 255]),
        ], 'default' => ['url' => '', 'title' => 'Campus tour', 'poster' => $stock('campus-building.jpg'), 'aspect' => '16:9']],
        'cards' => ['label' => 'Cards', 'icon' => 'layout-grid', 'group' => 'Content', 'description' => 'Icon, photo, people or pastel cards in a grid.', 'fields' => array_merge(cms_heading_fields(), [
            cms_fd('select', 'style', 'Card style', ['col' => 6, 'default' => 'icon', 'options' => cms_opts(['icon' => 'Icon cards', 'image' => 'Photo cards', 'person' => 'People (name, role, bio)', 'pastel' => 'Tinted cards'])]),
            cms_fd('select', 'columns', 'Columns', ['col' => 6, 'default' => '3', 'options' => cms_opts(['2' => '2', '3' => '3', '4' => '4'])]),
            cms_fd('repeater', 'items', 'Cards', ['required' => true, 'min_items' => 1, 'max_items' => 24, 'item_label' => 'title', 'fields' => [
                cms_fd('text', 'title', 'Title / name', ['required' => true, 'max' => 120, 'col' => 12]),
                cms_fd('text', 'subtitle', 'Subtitle / role', ['max' => 120, 'col' => 6]), cms_fd('icon', 'icon', 'Icon', ['col' => 6]),
                cms_fd('image', 'image', 'Photo', ['col' => 12]),
                cms_fd('textarea', 'text', 'Text', ['max' => 500, 'rows' => 2]),
                cms_fd('url', 'url', 'Link', ['col' => 6]), cms_fd('text', 'link_label', 'Link label', ['max' => 40, 'col' => 6]),
                cms_fd('select', 'tint', 'Tint', ['col' => 6, 'options' => cms_opts(['' => 'Auto'] + cms_tints())])]]),
        ]), 'default' => ['heading' => 'What makes us different', 'style' => 'icon', 'columns' => '3', 'items' => [
            ['icon' => 'graduation-cap', 'title' => 'Academic excellence', 'text' => 'Rigorous, outcome-based programs taught by experienced faculty.'],
            ['icon' => 'briefcase-business', 'title' => 'Industry ready', 'text' => 'Live projects, internships and certifications built into every program.'],
            ['icon' => 'users', 'title' => 'Mentorship', 'text' => 'Every student has a faculty mentor from the first semester.']]]],
        'statistics' => ['label' => 'Statistics', 'icon' => 'chart-column', 'group' => 'Content', 'description' => 'Animated numbers (live from the database or typed).', 'fields' => array_merge(cms_heading_fields(), [
            cms_fd('select', 'variant', 'Style', ['col' => 6, 'default' => 'cards', 'options' => cms_opts(['cards' => 'Cards', 'inline' => 'Inline row', 'band' => 'Dark navy band'])]),
            cms_fd('repeater', 'items', 'Statistics', ['required' => true, 'min_items' => 1, 'max_items' => 8, 'item_label' => 'label', 'fields' => cms_stat_item_fields()]),
        ]), 'default' => ['heading' => 'GIMT at a glance', 'variant' => 'cards', 'items' => [
            ['value' => '12', 'suffix' => '+', 'label' => 'Programs', 'icon' => 'graduation-cap', 'source' => 'programs'],
            ['value' => '1000', 'suffix' => '+', 'label' => 'Students', 'icon' => 'users', 'source' => 'students'],
            ['value' => '50', 'suffix' => '+', 'label' => 'Faculty', 'icon' => 'user-check', 'source' => 'faculty'],
            ['value' => '95', 'suffix' => '%', 'label' => 'Student satisfaction', 'icon' => 'smile', 'source' => 'satisfaction']]]],
        'testimonials' => ['label' => 'Testimonials', 'icon' => 'message-circle', 'group' => 'Social proof', 'description' => 'Student and alumni stories.', 'fields' => array_merge(cms_heading_fields(), [
            cms_fd('select', 'source', 'Stories', ['col' => 6, 'default' => 'auto', 'options' => cms_opts(['auto' => 'From Website CMS › Testimonials', 'manual' => 'Enter manually'])]),
            cms_fd('number', 'limit', 'Maximum stories', ['col' => 6, 'min' => 1, 'max' => 20, 'default' => 6, 'when' => ['source' => 'auto']]),
            cms_fd('multiselect', 'types', 'Types', ['options' => cms_opts(cms_testimonial_types()), 'when' => ['source' => 'auto']]),
            cms_fd('repeater', 'items', 'Stories', ['when' => ['source' => 'manual'], 'required' => true, 'min_items' => 1, 'max_items' => 12, 'item_label' => 'name', 'fields' => [
                cms_fd('text', 'name', 'Name', ['required' => true, 'max' => 120, 'col' => 6]), cms_fd('text', 'role', 'Program / role', ['max' => 120, 'col' => 6]),
                cms_fd('textarea', 'quote', 'Quote', ['required' => true, 'max' => 600, 'rows' => 3]),
                cms_fd('image', 'photo', 'Photo', ['col' => 6]), cms_fd('number', 'rating', 'Rating', ['col' => 6, 'min' => 1, 'max' => 5, 'default' => 5])]]),
        ]), 'default' => ['heading' => 'Hear it from our students', 'source' => 'auto', 'limit' => 6, 'types' => [], 'items' => []]],
        'faq' => ['label' => 'FAQ', 'icon' => 'circle-help', 'group' => 'Social proof', 'description' => 'Accordion of questions and answers.', 'fields' => array_merge(cms_heading_fields(), [
            cms_fd('select', 'source', 'Questions', ['col' => 6, 'default' => 'auto', 'options' => cms_opts(['auto' => 'From Website CMS › FAQs', 'manual' => 'Enter manually'])]),
            cms_fd('select', 'columns', 'Columns', ['col' => 6, 'default' => '1', 'options' => cms_opts(['1' => '1', '2' => '2'])]),
            cms_fd('select', 'category', 'Category', ['col' => 6, 'options' => cms_opts(['' => 'All categories'] + cms_faq_categories()), 'when' => ['source' => 'auto']]),
            cms_fd('number', 'limit', 'Maximum', ['col' => 6, 'min' => 1, 'max' => 30, 'default' => 8, 'when' => ['source' => 'auto']]),
            cms_fd('repeater', 'items', 'Questions', ['when' => ['source' => 'manual'], 'required' => true, 'min_items' => 1, 'max_items' => 30, 'item_label' => 'q', 'fields' => [
                cms_fd('text', 'q', 'Question', ['required' => true, 'max' => 300]), cms_fd('textarea', 'a', 'Answer', ['required' => true, 'max' => 2000, 'rows' => 3])]]),
        ]), 'default' => ['heading' => 'Frequently asked questions', 'source' => 'auto', 'category' => '', 'limit' => 6, 'columns' => '1', 'items' => []]],
        'cta' => ['label' => 'Call to action', 'icon' => 'megaphone', 'group' => 'Layout', 'description' => 'A band with a heading and buttons.', 'fields' => [
            cms_fd('text', 'eyebrow', 'Eyebrow', ['max' => 120, 'col' => 4]), cms_fd('text', 'title', 'Title', ['required' => true, 'max' => 190, 'col' => 8]),
            cms_fd('textarea', 'text', 'Text', ['max' => 400, 'rows' => 2]),
            cms_link_fd('primary', 'Primary button'), cms_link_fd('secondary', 'Secondary button'),
            cms_fd('image', 'image', 'Photo', ['col' => 6]),
            cms_fd('select', 'theme', 'Theme', ['col' => 6, 'default' => 'dark', 'options' => cms_opts(['dark' => 'Dark (navy)', 'orange' => 'Orange-red', 'light' => 'Light (warm grey)'])]),
        ], 'default' => ['title' => 'Ready to Build a Brighter Future?', 'text' => 'Join GIMT and take the next step towards your academic and professional success.',
            'primary' => ['label' => 'Apply for Admission', 'url' => '/apply'], 'secondary' => ['label' => 'Talk to a Counsellor', 'url' => '/contact'], 'theme' => 'dark']],
        'gallery' => ['label' => 'Gallery', 'icon' => 'images', 'group' => 'Media', 'description' => 'Photo grid with a lightbox.', 'fields' => array_merge(cms_heading_fields(), [
            cms_fd('select', 'source', 'Photos', ['col' => 6, 'default' => 'manual', 'options' => cms_opts(['manual' => 'Pick photos', 'gallery' => 'From a gallery album'])]),
            cms_fd('gallery', 'gallery_id', 'Gallery album', ['col' => 6, 'when' => ['source' => 'gallery'], 'required' => true]),
            cms_fd('select', 'layout', 'Layout', ['col' => 6, 'default' => 'grid', 'options' => cms_opts(['grid' => 'Grid', 'masonry' => 'Masonry', 'bento' => 'Bento'])]),
            cms_fd('select', 'columns', 'Columns', ['col' => 6, 'default' => '3', 'options' => cms_opts(['2' => '2', '3' => '3', '4' => '4'])]),
            cms_fd('repeater', 'items', 'Photos', ['when' => ['source' => 'manual'], 'required' => true, 'min_items' => 1, 'max_items' => 40, 'item_label' => 'caption', 'fields' => [
                cms_fd('image', 'image', 'Photo', ['required' => true, 'col' => 6]), cms_fd('text', 'alt', 'Description', ['max' => 190, 'col' => 6]),
                cms_fd('text', 'caption', 'Caption', ['max' => 190])]]),
        ]), 'default' => ['heading' => 'Campus in pictures', 'source' => 'manual', 'layout' => 'grid', 'columns' => '3', 'items' => [
            ['image' => $stock('facility-classroom.jpg'), 'alt' => 'Smart classroom', 'caption' => 'Smart Classrooms'],
            ['image' => $stock('facility-labs.jpg'), 'alt' => 'Computer laboratory', 'caption' => 'Advanced Laboratories'],
            ['image' => $stock('facility-library.jpg'), 'alt' => 'Digital library', 'caption' => 'Digital Library']]]],
        'course_listing' => ['label' => 'Course listing', 'icon' => 'graduation-cap', 'group' => 'Data', 'description' => 'Program cards from the Programs table.', 'fields' => array_merge(cms_heading_fields(), [
            cms_fd('select', 'mode', 'Programs', ['col' => 6, 'default' => 'all', 'options' => cms_opts(['all' => 'All active programs', 'featured' => 'Featured programs', 'level' => 'By level', 'department' => 'By department', 'selected' => 'Hand-picked'])]),
            cms_fd('number', 'limit', 'Maximum cards', ['col' => 6, 'min' => 1, 'max' => 24, 'default' => 6]),
            cms_fd('select', 'level', 'Level', ['col' => 6, 'when' => ['mode' => 'level'], 'required' => true, 'options' => cms_opts(['UG' => 'Undergraduate', 'PG' => 'Postgraduate', 'PhD' => 'Doctoral', 'Diploma' => 'Diploma', 'Certificate' => 'Certificate'])]),
            cms_fd('department', 'department_id', 'Department', ['col' => 6, 'when' => ['mode' => 'department'], 'required' => true]),
            cms_fd('programs', 'program_ids', 'Programs', ['when' => ['mode' => 'selected'], 'required' => true]),
            cms_fd('toggle', 'show_filters', 'Show category filters', ['col' => 6]), cms_link_fd('cta', 'Button'),
        ]), 'default' => ['heading' => 'Explore our programs', 'mode' => 'all', 'limit' => 6, 'show_filters' => true, 'cta' => ['label' => 'View All Programs', 'url' => '/programs']]],
        'faculty_listing' => ['label' => 'Faculty listing', 'icon' => 'users', 'group' => 'Data', 'description' => 'Faculty cards from Faculty & Staff.', 'fields' => array_merge(cms_heading_fields(), [
            cms_fd('department', 'department_id', 'Department', ['col' => 6, 'help' => 'Leave empty for every department.']),
            cms_fd('number', 'limit', 'Maximum', ['col' => 6, 'min' => 1, 'max' => 48, 'default' => 8]),
            cms_fd('toggle', 'show_qualification', 'Show qualification', ['col' => 6, 'default' => true]), cms_fd('toggle', 'show_bio', 'Show short bio', ['col' => 6]),
            cms_link_fd('cta', 'Button'),
        ]), 'default' => ['heading' => 'Meet our faculty', 'limit' => 8, 'show_qualification' => true, 'show_bio' => false, 'cta' => ['label' => 'View All Faculty', 'url' => '/faculty']]],
        'placement_section' => ['label' => 'Placement section', 'icon' => 'briefcase-business', 'group' => 'Data', 'description' => 'Placement numbers, recruiters and top offers.', 'fields' => array_merge(cms_heading_fields(), [
            cms_fd('repeater', 'stats', 'Statistics', ['max_items' => 4, 'item_label' => 'label', 'fields' => cms_stat_item_fields()]),
            cms_fd('toggle', 'show_recruiters', 'Show recruiter logos', ['col' => 6, 'default' => true]),
            cms_fd('number', 'recruiters_limit', 'Logos', ['col' => 6, 'min' => 4, 'max' => 48, 'default' => 16, 'when' => ['show_recruiters' => true]]),
            cms_fd('toggle', 'show_offers', 'Show top offers', ['col' => 6, 'default' => true]),
            cms_fd('number', 'offers_limit', 'Offers', ['col' => 6, 'min' => 3, 'max' => 12, 'default' => 6, 'when' => ['show_offers' => true]]),
            cms_link_fd('cta', 'Button'),
        ]), 'default' => ['heading' => 'Placements that speak for themselves', 'stats' => [
            ['value' => '90', 'suffix' => '%', 'label' => 'Placement rate', 'icon' => 'trending-up', 'source' => 'placement_rate'],
            ['value' => '25', 'prefix' => '₹', 'suffix' => ' LPA', 'label' => 'Highest package', 'icon' => 'trophy', 'source' => 'highest_package'],
            ['value' => '35', 'suffix' => '+', 'label' => 'Recruiters', 'icon' => 'handshake', 'source' => 'recruiters']],
            'show_recruiters' => true, 'recruiters_limit' => 16, 'show_offers' => true, 'offers_limit' => 6, 'cta' => ['label' => 'View Placement Details', 'url' => '/placement']]],
    ];
}

/* =====================================================================================================================
 * Validation
 * ===================================================================================================================*/

/** Allowed link targets: /path, #anchor, http(s)://, mailto:, tel: */
function cms_valid_url(string $v): bool
{
    if (preg_match('/[\s<>"]/', $v) || preg_match('#^\s*(javascript|data|vbscript):#i', $v)) {
        return false;
    }
    if (str_starts_with($v, '/') || str_starts_with($v, '#') || str_starts_with($v, '?')) {
        return !str_starts_with($v, '//');
    }
    if (preg_match('#^(mailto:|tel:)\S+$#i', $v)) {
        return true;
    }
    return (bool) preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $v) && filter_var($v, FILTER_VALIDATE_URL) !== false;
}

/** Image values: bundled/uploaded relative path (no traversal) or an absolute http(s) URL. */
function cms_valid_image(string $v): bool
{
    if (preg_match('#^https?://#i', $v)) {
        return filter_var($v, FILTER_VALIDATE_URL) !== false;
    }
    return (bool) preg_match('#^(assets/(images|uploads)/)[A-Za-z0-9_\-./]+\.(jpe?g|png|webp|gif|svg)$#i', $v) && !str_contains($v, '..');
}

function cms_valid_video(string $v): bool
{
    return (bool) preg_match('#^https?://(www\.)?(youtube\.com/(watch\?v=|embed/|shorts/)|youtu\.be/|(player\.)?vimeo\.com/)#i', $v)
        || (bool) preg_match('#\.(mp4|webm)(\?.*)?$#i', $v);
}

/** Is the 'when' condition of a field satisfied by the sibling values? */
function cms_when_matches(array $field, array $values): bool
{
    foreach ((array) ($field['when'] ?? []) as $k => $expected) {
        $actual = $values[$k] ?? null;
        if (is_bool($expected)) {
            if ((bool) $actual !== $expected) {
                return false;
            }
        } elseif (!in_array((string) $actual, array_map('strval', (array) $expected), true)) {
            return false;
        }
    }
    return true;
}

/**
 * Validate data against field definitions. Unknown keys are dropped, values are typed and trimmed.
 * Errors are written to $errors keyed by dotted path ("content.items.2.title").
 */
function cms_validate_fields(array $fields, $data, string $path, array &$errors): array
{
    $data = is_array($data) ? $data : [];
    // Resolve select/toggle values first so 'when' conditions see typed values.
    $clean = [];
    $deferred = [];
    foreach ($fields as $f) {
        if (!empty($f['when'])) {
            $deferred[] = $f;
            continue;
        }
        $clean[$f['name']] = cms_validate_value($f, $data[$f['name']] ?? null, $path . '.' . $f['name'], $errors, true);
    }
    foreach ($deferred as $f) {
        // Fields hidden by their condition keep their value (so switching back loses nothing) but report no errors.
        $active = cms_when_matches($f, $clean);
        $ignored = [];
        $clean[$f['name']] = $active
            ? cms_validate_value($f, $data[$f['name']] ?? null, $path . '.' . $f['name'], $errors, true)
            : cms_validate_value($f, $data[$f['name']] ?? null, $path . '.' . $f['name'], $ignored, false);
    }
    // keep definition order
    $ordered = [];
    foreach ($fields as $f) {
        $ordered[$f['name']] = $clean[$f['name']];
    }
    return $ordered;
}

/** Validate one value. $active = false skips required checks (field hidden by its 'when' condition). */
function cms_validate_value(array $f, $v, string $path, array &$errors, bool $active = true)
{
    $label = $f['label'];
    $required = !empty($f['required']) && $active;
    $type = $f['type'];
    $str = function ($v): string {
        return is_scalar($v) ? trim((string) $v) : '';
    };
    switch ($type) {
        case 'text':
        case 'textarea':
        case 'icon':
        case 'url':
        case 'image':
            $s = $str($v);
            if ($type === 'text' || $type === 'icon') {
                $s = preg_replace('/\s+/u', ' ', $s);
            } else {
                $s = str_replace("\r\n", "\n", $s);
            }
            if ($s === '') {
                if ($required) {
                    $errors[$path] = "$label is required.";
                }
                return '';
            }
            $max = $f['max'] ?? ($type === 'textarea' ? 1000 : 255);
            if (mb_strlen($s) > $max) {
                $errors[$path] = "$label may not be longer than $max characters.";
                return mb_substr($s, 0, $max);
            }
            if ($type === 'url' && !cms_valid_url($s)) {
                $errors[$path] = 'Enter a valid link, e.g. /about, #section or https://example.com.';
            } elseif ($type === 'url' && ($f['pattern'] ?? '') === 'video' && !cms_valid_video($s)) {
                $errors[$path] = 'Enter a YouTube or Vimeo link, or a direct .mp4 / .webm file URL.';
            } elseif ($type === 'image' && !cms_valid_image($s)) {
                $errors[$path] = 'Choose an image from the media library or upload one.';
            } elseif ($type === 'icon' && !in_array($s, cms_icon_names(), true)) {
                $errors[$path] = 'Choose an icon from the list.';
            } elseif (($f['pattern'] ?? '') === 'anchor' && !preg_match('/^[a-z0-9][a-z0-9\-]*$/', $s)) {
                $errors[$path] = 'Use lowercase letters, numbers and dashes only.';
            }
            return $s;
        case 'richtext':
            $html = sanitize_html(is_string($v) ? $v : '');
            if (trim(strip_tags($html, '<img><iframe>')) === '') {
                if ($required) {
                    $errors[$path] = "$label is required.";
                }
                return '';
            }
            if (mb_strlen($html) > 60000) {
                $errors[$path] = "$label is too long.";
            }
            return $html;
        case 'link':
            $l = is_array($v) ? $v : [];
            $labelV = preg_replace('/\s+/u', ' ', $str($l['label'] ?? ''));
            $urlV = $str($l['url'] ?? '');
            if ($labelV === '' && $urlV === '') {
                if ($required) {
                    $errors[$path] = "$label is required.";
                }
                return ['label' => '', 'url' => ''];
            }
            if ($labelV === '') {
                $errors[$path . '.label'] = 'Add the button text.';
            } elseif (mb_strlen($labelV) > 60) {
                $errors[$path . '.label'] = 'Button text may not be longer than 60 characters.';
            }
            if ($urlV === '') {
                $errors[$path . '.url'] = 'Add the link.';
            } elseif (mb_strlen($urlV) > 255 || !cms_valid_url($urlV)) {
                $errors[$path . '.url'] = 'Enter a valid link, e.g. /apply or https://example.com.';
            }
            return ['label' => mb_substr($labelV, 0, 60), 'url' => mb_substr($urlV, 0, 255)];
        case 'select':
            $s = $str($v);
            if ($s === '' && isset($f['default'])) {
                $s = (string) $f['default'];
            }
            $allowed = array_map(fn ($o) => (string) $o['value'], $f['options'] ?? []);
            if ($s !== '' && !in_array($s, $allowed, true)) {
                $errors[$path] = "Select a valid $label.";
                return (string) ($f['default'] ?? '');
            }
            if ($s === '' && $required) {
                $errors[$path] = "$label is required.";
            }
            return $s;
        case 'multiselect':
            $allowed = array_map(fn ($o) => (string) $o['value'], $f['options'] ?? []);
            $vals = array_values(array_unique(array_filter(array_map($str, is_array($v) ? $v : []), fn ($x) => $x !== '')));
            $bad = array_diff($vals, $allowed);
            if ($bad) {
                $errors[$path] = "Select valid $label.";
                $vals = array_values(array_intersect($vals, $allowed));
            }
            if (!$vals && $required) {
                $errors[$path] = "$label is required.";
            }
            return $vals;
        case 'number':
            if ($v === null || $v === '' || !is_scalar($v)) {
                if ($required) {
                    $errors[$path] = "$label is required.";
                }
                return $f['default'] ?? null;
            }
            if (!is_numeric($v)) {
                $errors[$path] = "$label must be a number.";
                return $f['default'] ?? null;
            }
            $n = (isset($f['step']) && floor((float) $f['step']) != (float) $f['step']) ? (float) $v : (int) round((float) $v);
            if (isset($f['min']) && $n < $f['min']) {
                $errors[$path] = "$label must be at least {$f['min']}.";
            } elseif (isset($f['max']) && $n > $f['max']) {
                $errors[$path] = "$label may not be greater than {$f['max']}.";
            }
            return $n;
        case 'toggle':
            if ($v === null) {
                return (bool) ($f['default'] ?? false);
            }
            return in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true);
        case 'list':
            $max = $f['item_max'] ?? 60;
            $vals = [];
            foreach (is_array($v) ? $v : [] as $item) {
                $s = preg_replace('/\s+/u', ' ', $str($item));
                if ($s === '') {
                    continue;
                }
                if (mb_strlen($s) > $max) {
                    $errors[$path] = "Each entry may not be longer than $max characters.";
                    $s = mb_substr($s, 0, $max);
                }
                $vals[] = $s;
            }
            if (isset($f['max_items']) && count($vals) > $f['max_items']) {
                $errors[$path] = "Add at most {$f['max_items']} entries.";
            }
            if (!$vals && $required) {
                $errors[$path] = "$label is required.";
            }
            return $vals;
        case 'repeater':
            $items = [];
            foreach (array_values(is_array($v) ? $v : []) as $i => $item) {
                $items[] = cms_validate_fields($f['fields'], $item, $path . '.' . $i, $errors);
            }
            $min = $f['min_items'] ?? ($required ? 1 : 0);
            if ($active && $required && count($items) < $min) {
                $errors[$path] = $min === 1 ? "Add at least one item to $label." : "Add at least $min items to $label.";
            } elseif ($active && !$required && !empty($f['min_items']) && count($items) > 0 && count($items) < $f['min_items']) {
                $errors[$path] = "Add at least {$f['min_items']} items to $label.";
            }
            if (isset($f['max_items']) && count($items) > $f['max_items']) {
                $errors[$path] = "$label may have at most {$f['max_items']} items.";
            }
            return $items;
        case 'programs':
            $ids = array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []))));
            if ($ids) {
                $found = array_map('intval', db_column('SELECT id FROM programs WHERE id IN (' . implode(',', $ids) . ')'));
                if (count($found) !== count($ids)) {
                    $errors[$path] = 'One or more selected programs no longer exist.';
                    $ids = array_values(array_intersect($ids, $found));
                }
            }
            if (!$ids && $required) {
                $errors[$path] = "Select at least one program.";
            }
            return $ids;
        case 'department':
        case 'gallery':
            $id = (int) (is_scalar($v) ? $v : 0);
            if ($id <= 0) {
                if ($required) {
                    $errors[$path] = "$label is required.";
                }
                return null;
            }
            $table = $type === 'department' ? 'departments' : 'galleries';
            if (!(int) db_value('SELECT COUNT(*) FROM ' . $table . ' WHERE id = ?', [$id])) {
                $errors[$path] = "Selected $label no longer exists.";
                return null;
            }
            return $id;
    }
    return null;
}

/**
 * Validate a home page section update. $input: title, subtitle, content, is_enabled (optional).
 * @return array [clean ['title','subtitle','content'], errors]
 */
function cms_validate_section(string $key, array $input): array
{
    $def = cms_section_types()[$key] ?? null;
    if (!$def) {
        return [[], ['section_key' => 'Unknown home page section.']];
    }
    $errors = [];
    $title = preg_replace('/\s+/u', ' ', trim((string) ($input['title'] ?? '')));
    $subtitle = trim(str_replace("\r\n", "\n", (string) ($input['subtitle'] ?? '')));
    if ($title === '' && !empty($def['title_required'])) {
        $errors['title'] = $def['title_label'] . ' is required.';
    } elseif (mb_strlen($title) > 190) {
        $errors['title'] = $def['title_label'] . ' may not be longer than 190 characters.';
    }
    if (mb_strlen($subtitle) > 500) {
        $errors['subtitle'] = $def['subtitle_label'] . ' may not be longer than 500 characters.';
    }
    $content = $input['content'] ?? [];
    if (is_string($content)) {
        $content = json_decode($content, true) ?? [];
    }
    $clean = cms_validate_fields($def['fields'], $content, 'content', $errors);
    return [['title' => $title === '' ? null : $title, 'subtitle' => $subtitle === '' ? null : $subtitle, 'content' => $clean], $errors];
}

function cms_block_id(): string
{
    return 'b' . substr(bin2hex(random_bytes(5)), 0, 8);
}

/**
 * Validate page builder blocks (docs/CMS.md §2).
 * @return array [clean blocks, errors keyed "blocks.{i}.data.{field}"]
 */
function cms_validate_blocks($blocks): array
{
    if ($blocks === null || $blocks === '') {
        return [[], []];
    }
    if (is_string($blocks)) {
        $blocks = json_decode($blocks, true);
    }
    if (!is_array($blocks)) {
        return [[], ['blocks' => 'Blocks must be a list.']];
    }
    $types = cms_block_types();
    $errors = [];
    $clean = [];
    $ids = [];
    if (count($blocks) > 60) {
        $errors['blocks'] = 'A page can have at most 60 blocks.';
    }
    foreach (array_values($blocks) as $i => $b) {
        $b = is_array($b) ? $b : [];
        $type = (string) ($b['type'] ?? '');
        if (!isset($types[$type])) {
            $errors["blocks.$i.type"] = 'Unknown block type "' . mb_substr($type, 0, 30) . '".';
            continue;
        }
        $id = (string) ($b['id'] ?? '');
        if (!preg_match('/^[a-z0-9_\-]{1,40}$/i', $id) || isset($ids[$id])) {
            $id = cms_block_id();
        }
        $ids[$id] = true;
        $settings = is_array($b['settings'] ?? null) ? $b['settings'] : [];
        $anchor = trim((string) ($settings['anchor'] ?? ''));
        if ($anchor !== '' && !preg_match('/^[a-z0-9][a-z0-9\-]{0,59}$/', $anchor)) {
            $errors["blocks.$i.settings.anchor"] = 'Anchor: use lowercase letters, numbers and dashes only.';
            $anchor = slugify($anchor);
        }
        $bg = (string) ($settings['background'] ?? 'white');
        $pad = (string) ($settings['padding'] ?? 'normal');
        // legacy flat blocks {type, ...fields}
        $data = isset($b['data']) && is_array($b['data']) ? $b['data'] : array_diff_key($b, array_flip(['id', 'type', 'hidden', 'settings']));
        $clean[] = [
            'id' => $id,
            'type' => $type,
            'hidden' => in_array($b['hidden'] ?? false, [true, 1, '1', 'true'], true),
            'settings' => [
                'anchor' => $anchor,
                'background' => in_array($bg, ['white', 'light', 'cream', 'sand', 'dark'], true) ? $bg : 'white',
                'padding' => in_array($pad, ['normal', 'tight', 'none'], true) ? $pad : 'normal',
            ],
            'data' => cms_validate_fields($types[$type]['fields'], $data, "blocks.$i.data", $errors),
        ];
    }
    return [$clean, $errors];
}

/** Blocks stored on a page row, normalised to {id, type, hidden, settings, data} (no validation, unknown types dropped). */
function cms_normalize_blocks($raw): array
{
    $blocks = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($blocks)) {
        return [];
    }
    $types = cms_block_types();
    $out = [];
    $seen = [];
    foreach ($blocks as $b) {
        if (!is_array($b) || !isset($types[$b['type'] ?? ''])) {
            continue;
        }
        $id = (string) ($b['id'] ?? '');
        if ($id === '' || isset($seen[$id])) {
            $id = cms_block_id();
        }
        $seen[$id] = true;
        $data = isset($b['data']) && is_array($b['data']) ? $b['data'] : array_diff_key($b, array_flip(['id', 'type', 'hidden', 'settings']));
        $out[] = ['id' => $id, 'type' => $b['type'], 'hidden' => !empty($b['hidden']),
            'settings' => (is_array($b['settings'] ?? null) ? $b['settings'] : []) + ['anchor' => '', 'background' => 'white', 'padding' => 'normal'], 'data' => $data];
    }
    return $out;
}

/** Visible blocks of a page for rendering on the website. */
function cms_page_blocks(array $page): array
{
    return array_values(array_filter(cms_normalize_blocks($page['blocks'] ?? null), fn ($b) => !$b['hidden']));
}

/** Escaped paragraphs from plain text (blank line = new paragraph). */
function cms_paragraphs(?string $text, string $class = ''): string
{
    $out = '';
    foreach (preg_split('/\n\s*\n/', trim((string) $text)) as $p) {
        if (trim($p) !== '') {
            $out .= '<p' . ($class ? ' class="' . e($class) . '"' : '') . '>' . nl2br(e(trim($p))) . '</p>';
        }
    }
    return $out;
}

/* =====================================================================================================================
 * Pages
 * ===================================================================================================================*/

/** Slugs that have a dedicated PHP template in the web root (about.php → about). */
function cms_template_slugs(): array
{
    static $slugs = null;
    if ($slugs === null) {
        $slugs = [];
        foreach (glob(APP_ROOT . '/*.php') ?: [] as $f) {
            $slugs[] = basename($f, '.php');
        }
        $slugs = array_values(array_diff($slugs, ['index', 'page', 'post', 'event', 'notice', 'gallery', 'program', 'sitemap', 'robots', 'site-kit', 'install']));
    }
    return $slugs;
}

/**
 * Slugs a NEW page may not take: URL prefixes the router owns, top-level folders, and every slug that already has its
 * own PHP template or public route (a page called "apply" would never be shown — apply.php answers /apply).
 */
function cms_reserved_slugs(): array
{
    static $list = null;
    if ($list === null) {
        $list = ['admin', 'api', 'assets', 'page', 'blog', 'events', 'notices', 'gallery', 'programs', 'verify-certificate', 'sitemap', 'robots', 'storage',
            'portal', 'search', 'home', 'index', 'login', 'logout', 'includes', 'config', 'database', 'docs', 'frontend', 'tools', 'app', 'vendor', '404', '500'];
        foreach (glob(APP_ROOT . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $list[] = strtolower(basename($dir));
        }
        foreach (glob(APP_ROOT . '/*.php') ?: [] as $file) {
            $list[] = strtolower(basename($file, '.php'));
        }
        foreach (array_keys(cms_public_routes()) as $route) {
            $list[] = ltrim($route, '/');
        }
        $list = array_values(array_unique(array_filter($list)));
    }
    return $list;
}

/** Does a page slug belong to a dedicated website template / known public route (about → about.php, /placement …)? */
function cms_page_is_route(string $slug): bool
{
    return in_array($slug, cms_template_slugs(), true) || isset(cms_public_routes()['/' . $slug]);
}

/**
 * Public path of a CMS page: the system route /{slug} for system pages and pages with a dedicated template
 * (the router maps /{slug} to page.php when no {slug}.php exists), otherwise /page/{slug}.
 */
function cms_page_url(array $page): string
{
    $slug = (string) ($page['slug'] ?? '');
    if ($slug === '' || $slug === 'home') {
        return '/';
    }
    return !empty($page['is_system']) || cms_page_is_route($slug) ? '/' . $slug : '/page/' . $slug;
}

/** Where the SEO of a page is stored: ['route', '/about'] for template/route pages, ['page', id] for pages served by page.php. */
function cms_page_seo_ref(array $page): array
{
    $slug = (string) ($page['slug'] ?? '');
    return cms_page_is_route($slug) ? ['route', '/' . $slug] : ['page', (int) $page['id']];
}

/** Unique slug for a new page (adds -2, -3 …). */
function cms_unique_page_slug(string $base, ?int $ignoreId = null): string
{
    $base = slugify($base) ?: 'page';
    $slug = $base;
    $n = 1;
    while ((int) db_value('SELECT COUNT(*) FROM pages WHERE slug = ?' . ($ignoreId ? ' AND id <> ?' : ''), $ignoreId ? [$slug, $ignoreId] : [$slug])) {
        $slug = $base . '-' . (++$n);
    }
    return $slug;
}

/* =====================================================================================================================
 * Menus
 * ===================================================================================================================*/

/** Menu row for a location, created on demand. */
function cms_menu_ensure(string $location): array
{
    $loc = cms_menu_locations()[$location] ?? null;
    if (!$loc) {
        throw new InvalidArgumentException('Unknown menu location.');
    }
    $row = db_row('SELECT * FROM menus WHERE location = ?', [$location]);
    if (!$row) {
        $id = db_insert('menus', ['name' => $loc['label'], 'location' => $location, 'status' => 'active']);
        $row = db_row('SELECT * FROM menus WHERE id = ?', [$id]);
    }
    return $row;
}

/** Items of a menu as a tree (children arrays). */
function cms_menu_tree(int $menuId, bool $activeOnly = false): array
{
    $rows = db_all('SELECT mi.*, p.title AS page_title, p.slug AS page_slug, p.status AS page_status FROM menu_items mi LEFT JOIN pages p ON p.id = mi.page_id
                    WHERE mi.menu_id = ?' . ($activeOnly ? " AND mi.status = 'active'" : '') . ' ORDER BY mi.sort_order, mi.id', [$menuId]);
    $by = [];
    foreach ($rows as $r) {
        $r['id'] = (int) $r['id'];
        $r['menu_id'] = (int) $r['menu_id'];
        $r['parent_id'] = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
        $r['page_id'] = $r['page_id'] !== null ? (int) $r['page_id'] : null;
        $r['is_mega'] = (bool) $r['is_mega'];
        $r['sort_order'] = (int) $r['sort_order'];
        $by[(int) $r['parent_id']][] = $r;
    }
    $build = function (int $parent) use (&$build, $by): array {
        $out = [];
        foreach ($by[$parent] ?? [] as $r) {
            $r['children'] = $build($r['id']);
            $out[] = $r;
        }
        return $out;
    };
    return $build(0);
}

/**
 * Save a whole menu tree in one transaction (insert new, update existing, delete removed items).
 * $items: [{id?, title, url, page_id, target, icon, description, is_mega, status, children: [...]}]
 * @throws CrudValidationException
 */
function cms_menu_save_tree(string $location, array $items, array $menuPatch = []): array
{
    $loc = cms_menu_locations()[$location] ?? null;
    if (!$loc) {
        throw new CrudException('Unknown menu location.');
    }
    $menu = cms_menu_ensure($location);
    $menuId = (int) $menu['id'];
    $errors = [];
    $pages = [];
    foreach (db_all('SELECT id, slug, title, is_system FROM pages') as $p) {
        $pages[(int) $p['id']] = $p;
    }
    $icons = cms_icon_names();
    $count = 0;
    $clean = function (array $list, int $depth, string $path, bool $parentMega) use (&$clean, &$errors, &$count, $pages, $icons, $loc, $location): array {
        $out = [];
        foreach (array_values($list) as $i => $it) {
            $p = "$path.$i";
            $it = is_array($it) ? $it : [];
            $count++;
            $title = preg_replace('/\s+/u', ' ', trim((string) ($it['title'] ?? '')));
            $url = trim((string) ($it['url'] ?? ''));
            $pageId = (int) ($it['page_id'] ?? 0) ?: null;
            if ($title === '') {
                $errors["$p.title"] = 'Title is required.';
            } elseif (mb_strlen($title) > 100) {
                $errors["$p.title"] = 'Title may not be longer than 100 characters.';
            }
            if ($pageId !== null) {
                if (!isset($pages[$pageId])) {
                    $errors["$p.page_id"] = 'The selected page no longer exists.';
                    $pageId = null;
                } else {
                    // keep a ?query / #anchor typed after the page URL (e.g. /admissions#fees)
                    $base = cms_page_url($pages[$pageId]);
                    $suffix = str_starts_with($url, $base) && in_array(substr($url, strlen($base), 1), ['?', '#'], true) ? substr($url, strlen($base)) : '';
                    $url = $base . $suffix;
                }
            }
            if ($url === '') {
                $errors["$p.url"] = 'Enter a URL or choose a page.';
            } elseif (mb_strlen($url) > 255 || !cms_valid_url($url)) {
                $errors["$p.url"] = 'Enter a valid link, e.g. /about, /programs#mba or https://example.com.';
            }
            $icon = trim((string) ($it['icon'] ?? ''));
            if ($icon !== '' && !in_array($icon, $icons, true)) {
                $errors["$p.icon"] = 'Choose an icon from the list.';
                $icon = '';
            }
            $desc = preg_replace('/\s+/u', ' ', trim((string) ($it['description'] ?? '')));
            if (mb_strlen($desc) > 190) {
                $errors["$p.description"] = 'Description may not be longer than 190 characters.';
            }
            $isMega = !empty($it['is_mega']) && $depth === 0 && $location === 'header';
            $children = is_array($it['children'] ?? null) ? $it['children'] : [];
            $maxDepth = (int) $loc['depth'];
            if ($children && $depth + 1 >= $maxDepth) {
                $errors["$p.children"] = $maxDepth === 1 ? 'This menu is a flat list — sub-items are not allowed.' : 'Too many levels for this menu.';
                $children = [];
            }
            if ($children && $location === 'header' && $depth === 1 && !$parentMega) {
                $errors["$p.children"] = 'Third-level links are only allowed inside a mega menu.';
                $children = [];
            }
            $out[] = [
                'id' => (int) ($it['id'] ?? 0) ?: null,
                'title' => mb_substr($title, 0, 100),
                'url' => mb_substr($url, 0, 255),
                'page_id' => $pageId,
                'target' => ($it['target'] ?? '_self') === '_blank' ? '_blank' : '_self',
                'icon' => $icon ?: null,
                'description' => $desc === '' ? null : mb_substr($desc, 0, 190),
                'is_mega' => $isMega ? 1 : 0,
                'status' => ($it['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
                'children' => $clean($children, $depth + 1, "$p.children", $isMega),
            ];
        }
        return $out;
    };
    $tree = $clean($items, 0, 'items', false);
    if ($count > 250) {
        $errors['items'] = 'A menu can have at most 250 items.';
    }
    $name = trim((string) ($menuPatch['name'] ?? $menu['name']));
    if ($name === '' || mb_strlen($name) > 100) {
        $errors['name'] = 'Menu name is required (max 100 characters).';
    }
    if ($errors) {
        throw new CrudValidationException($errors, 'Please fix the highlighted menu items and try again.');
    }
    $status = ($menuPatch['status'] ?? $menu['status']) === 'inactive' ? 'inactive' : 'active';
    db_transaction(function () use ($menuId, $tree, $name, $status) {
        db_update('menus', ['name' => $name, 'status' => $status], 'id = ?', [$menuId]);
        $existing = array_map('intval', db_column('SELECT id FROM menu_items WHERE menu_id = ?', [$menuId]));
        $kept = [];
        $write = function (array $list, ?int $parentId) use (&$write, &$kept, $existing, $menuId) {
            foreach ($list as $i => $it) {
                $row = ['menu_id' => $menuId, 'parent_id' => $parentId, 'title' => $it['title'], 'url' => $it['url'], 'page_id' => $it['page_id'],
                    'target' => $it['target'], 'icon' => $it['icon'], 'description' => $it['description'], 'is_mega' => $it['is_mega'],
                    'sort_order' => $i + 1, 'status' => $it['status']];
                if ($it['id'] && in_array($it['id'], $existing, true) && !in_array($it['id'], $kept, true)) {
                    db_update('menu_items', $row, 'id = ?', [$it['id']]);
                    $id = $it['id'];
                } else {
                    $id = db_insert('menu_items', $row);
                }
                $kept[] = $id;
                $write($it['children'], $id);
            }
        };
        // detach everything first so moving an item under a node that is later deleted cannot cascade-delete it
        db_exec('UPDATE menu_items SET parent_id = NULL WHERE menu_id = ?', [$menuId]);
        $write($tree, null);
        $remove = array_diff($existing, $kept);
        if ($remove) {
            db_exec('DELETE FROM menu_items WHERE menu_id = ? AND id IN (' . implode(',', array_map('intval', $remove)) . ')', [$menuId]);
        }
    });
    return ['menu' => db_row('SELECT * FROM menus WHERE id = ?', [$menuId]), 'items' => cms_menu_tree($menuId), 'count' => $count];
}

/**
 * Built-in links of each location — identical to the fallbacks in includes/navbar.php and includes/footer.php
 * (the website shows them while a menu is empty). Used by the core seeder and "Load default links" in the admin.
 */
function cms_menu_fallback(string $location): array
{
    $l = fn (string $title, string $url, array $o = []) => $o + ['title' => $title, 'url' => $url, 'children' => []];
    $session = current_session()['name'] ?? '2026-27';
    return match ($location) {
        'header' => [
            $l('Home', '/'),
            $l('Programs', '/programs', ['is_mega' => true]),
            $l('Admissions', '/admissions', ['children' => [
                $l('Admission Process', '/admissions#process', ['icon' => 'clipboard-check', 'description' => 'Five simple steps to join GIMT']),
                $l('Apply Online', '/apply', ['icon' => 'file-text', 'description' => 'Start your application in minutes']),
                $l('Fees & Scholarships', '/admissions#fees', ['icon' => 'wallet', 'description' => 'Fee structure, instalments & awards']),
                $l('Eligibility & Documents', '/admissions#eligibility', ['icon' => 'file-check', 'description' => 'Who can apply and what to upload']),
                $l('Important Dates', '/admissions#dates', ['icon' => 'calendar-days', 'description' => 'Deadlines for the ' . $session . ' session']),
            ]]),
            $l('Academics', '/academics', ['children' => [
                $l('Academics Overview', '/academics', ['icon' => 'school', 'description' => 'Departments, pedagogy and calendar']),
                $l('Departments', '/academics#departments', ['icon' => 'building-2', 'description' => 'Schools and departments at GIMT']),
                $l('Our Faculty', '/faculty', ['icon' => 'graduation-cap', 'description' => 'Experienced academics & mentors']),
                $l('Academic Calendar', '/academics#calendar', ['icon' => 'calendar-days', 'description' => 'Semesters, exams and holidays']),
            ]]),
            $l('Campus Life', '/campus', ['children' => [
                $l('Campus & Facilities', '/campus', ['icon' => 'building', 'description' => 'Labs, library, hostel and sports']),
                $l('Placements', '/placement', ['icon' => 'briefcase-business', 'description' => 'Training, drives and recruiters']),
                $l('Alumni', '/alumni', ['icon' => 'users', 'description' => 'Our global alumni network']),
                $l('News & Blog', '/blog', ['icon' => 'newspaper', 'description' => 'Stories from campus']),
            ]]),
            $l('About', '/about', ['children' => [
                $l('About GIMT', '/about', ['icon' => 'landmark', 'description' => 'Our story, campus and values']),
                $l('Vision & Mission', '/about#vision', ['icon' => 'target', 'description' => 'What drives us every day']),
                $l('Leadership', '/about#leadership', ['icon' => 'users', 'description' => 'Chairman, Director and Deans']),
                $l('Approvals & Affiliations', '/about#approvals', ['icon' => 'badge-check', 'description' => 'Recognitions of our programs']),
            ]]),
            $l('Contact', '/contact'),
        ],
        'footer_quick' => [$l('About GIMT', '/about'), $l('Vision & Mission', '/about#vision'), $l('Leadership', '/about#leadership'), $l('Our Faculty', '/faculty'),
            $l('Approvals', '/about#approvals'), $l('Contact Us', '/contact')],
        'footer_academics' => [$l('All Programs', '/programs'), $l('Undergraduate', '/programs?category=ug'), $l('Postgraduate', '/programs?category=pg'),
            $l('Diploma', '/programs?category=diploma'), $l('Certificate', '/programs?category=certificate'), $l('Academics', '/academics')],
        'footer_admissions' => [$l('Admission Process', '/admissions#process'), $l('Apply Online', '/apply'), $l('Fees & Scholarships', '/admissions#fees'),
            $l('Scholarships', '/admissions#scholarships'), $l('Enquire Now', '/admissions#enquiry'), $l('Verify Certificate', '/verify-certificate')],
        'footer_support' => [$l('Student Portal', '/student-portal'), $l('Faculty Portal', '/faculty-portal'), $l('Campus Life', '/campus'), $l('Placements', '/placement'),
            $l('Notices', '/notices'), $l('FAQs', '/faq')],
        'legal' => [$l('Privacy Policy', '/privacy-policy'), $l('Terms & Conditions', '/terms-and-conditions'), $l('Refund Policy', '/refund-policy'), $l('Accessibility', '/accessibility')],
        'topbar' => [$l('Student Login', '/portal/student/login', ['icon' => 'user']), $l('Faculty Login', '/portal/faculty/login', ['icon' => 'user-round']), $l('Admin', '/admin/login', ['icon' => 'lock'])],
        default => [],
    };
}

/** Link fallback items to CMS pages whose URL matches (keeps the URL — anchors included — unchanged). */
function cms_menu_link_pages(array $items, array $pagesBySlug): array
{
    foreach ($items as &$it) {
        $path = trim((string) parse_url((string) $it['url'], PHP_URL_PATH), '/');
        if ($path !== '' && !str_contains($path, '/') && isset($pagesBySlug[$path]) && cms_page_url($pagesBySlug[$path]) === '/' . $path) {
            $it['page_id'] = (int) $pagesBySlug[$path]['id'];
        }
        $it['children'] = cms_menu_link_pages($it['children'] ?? [], $pagesBySlug);
    }
    return $items;
}

/** Icon for a program row (mirrors program_icon() in includes/components.php). */
function cms_program_icon(array $p): string
{
    return ['management' => 'briefcase-business', 'technology' => 'cpu', 'computer-applications' => 'laptop', 'commerce' => 'chart-column',
        'science' => 'flask-conical', 'diploma' => 'award', 'certificate' => 'badge-check'][slugify((string) ($p['category'] ?? ''))] ?? 'graduation-cap';
}

/** Mega-menu columns built from the programs table: [[title, url, children: [...]]] */
function cms_program_menu_columns(): array
{
    $groups = ['Undergraduate' => ['ug', []], 'Postgraduate' => ['pg', []], 'Diploma & Certificate' => ['diploma', []]];
    foreach (db_all("SELECT name, short_name, slug, level, category, duration_label FROM programs WHERE status = 'active' AND show_on_website = 1 ORDER BY sort_order, name") as $p) {
        $g = $p['level'] === 'UG' ? 'Undergraduate' : (in_array($p['level'], ['PG', 'PhD'], true) ? 'Postgraduate' : 'Diploma & Certificate');
        $groups[$g][1][] = ['title' => $p['short_name'] ?: $p['name'], 'url' => '/programs/' . $p['slug'], 'icon' => cms_program_icon($p),
            'description' => trim(($p['category'] ?: '') . ($p['duration_label'] ? ' · ' . $p['duration_label'] : ''), ' ·'), 'children' => []];
    }
    $cols = [];
    foreach ($groups as $title => [$cat, $children]) {
        if ($children) {
            $cols[] = ['title' => $title, 'url' => '/programs?category=' . $cat, 'children' => $children];
        }
    }
    return $cols;
}

/**
 * Header "Programs" mega menu. $mode 'auto' removes its children so the website auto-fills it with program cards and
 * level / department filters (recommended); 'columns' writes editable Undergraduate / Postgraduate / Diploma &
 * Certificate columns built from the programs table. Returns the number of programs.
 */
function cms_sync_program_menu(string $mode = 'auto'): int
{
    $menu = cms_menu_ensure('header');
    $tree = cms_menu_tree((int) $menu['id']);
    $cols = $mode === 'columns' ? cms_program_menu_columns() : [];
    $found = false;
    foreach ($tree as &$item) {
        if ($item['is_mega'] || str_starts_with((string) $item['url'], '/programs')) {
            $item['is_mega'] = true;
            $item['children'] = $cols;
            $found = true;
            break;
        }
    }
    unset($item);
    if (!$found) {
        array_splice($tree, min(1, count($tree)), 0, [['title' => 'Programs', 'url' => '/programs', 'is_mega' => true, 'children' => $cols]]);
    }
    cms_menu_save_tree('header', $tree);
    return (int) db_value("SELECT COUNT(*) FROM programs WHERE status = 'active' AND show_on_website = 1");
}

/* =====================================================================================================================
 * Media
 * ===================================================================================================================*/

/** Bundled photography (assets/images/site) offered in the image picker. */
function cms_stock_images(): array
{
    $out = [];
    foreach (glob(APP_ROOT . '/assets/images/site/*') ?: [] as $f) {
        if (!preg_match('/\.(jpe?g|png|webp)$/i', $f)) {
            continue;
        }
        $info = @getimagesize($f);
        $name = basename($f);
        $out[] = ['id' => null, 'path' => 'assets/images/site/' . $name, 'title' => ucwords(str_replace(['-', '_'], ' ', pathinfo($name, PATHINFO_FILENAME))),
            'width' => $info[0] ?? null, 'height' => $info[1] ?? null, 'size' => (int) filesize($f), 'source' => 'stock'];
    }
    return $out;
}

/** Register an uploaded image in the media library (table `media`). Returns the media id. */
function cms_register_media(array $res, string $folder = 'page-builder', ?string $alt = null): int
{
    return db_insert('media', [
        'filename' => basename($res['path']), 'original_name' => $res['original_name'] ?? null, 'path' => $res['path'], 'mime' => $res['mime'] ?? null,
        'size_bytes' => $res['size'] ?? null, 'type' => 'image', 'width' => $res['width'] ?? null, 'height' => $res['height'] ?? null,
        'alt_text' => $alt ? mb_substr($alt, 0, 255) : null, 'title' => $res['original_name'] ? mb_substr(pathinfo($res['original_name'], PATHINFO_FILENAME), 0, 190) : null,
        'folder' => $folder, 'uploaded_by' => user_id(),
    ]);
}

/* =====================================================================================================================
 * SEO
 * ===================================================================================================================*/

function cms_seo_entity_types(): array
{
    return ['route' => 'Routes', 'page' => 'Pages', 'program' => 'Programs', 'post' => 'Blog posts', 'event' => 'Events', 'notice' => 'Notices'];
}

/** Known public routes: path => [label, template file, description, priority, changefreq] */
function cms_public_routes(): array
{
    $d = fn (string $label, string $file, string $desc, float $prio = 0.8, string $freq = 'weekly') => ['label' => $label, 'file' => $file, 'description' => $desc, 'priority' => $prio, 'changefreq' => $freq];
    return [
        '/' => $d('Home', 'index.php', 'Global Institute of Management & Technology (GIMT), Greater Noida — industry-focused UG, PG, diploma and certificate programs.', 1.0, 'daily'),
        '/about' => $d('About GIMT', 'about.php', 'Our story, vision and mission, leadership, campus and approvals.', 0.8, 'monthly'),
        '/programs' => $d('Programs', 'programs.php', 'Explore undergraduate, postgraduate, diploma and certificate programs at GIMT.', 0.9, 'weekly'),
        '/admissions' => $d('Admissions', 'admissions.php', 'Admission process, eligibility, fees, scholarships and important dates.', 0.9, 'weekly'),
        '/admissions-open' => $d('Admissions Open (campaign)', 'admissions-open.php', 'Admissions open — apply now and get free career counselling.', 0.7, 'weekly'),
        '/academics' => $d('Academics', 'academics.php', 'Departments, faculty, academic calendar and research at GIMT.', 0.8, 'monthly'),
        '/campus' => $d('Campus Life', 'campus.php', 'Facilities, hostel, sports, clubs and life on the GIMT campus.', 0.7, 'monthly'),
        '/placement' => $d('Placements', 'placement.php', 'Placement statistics, recruiters and career development at GIMT.', 0.8, 'monthly'),
        '/alumni' => $d('Alumni', 'alumni.php', 'The GIMT alumni network, success stories and events.', 0.6, 'monthly'),
        '/faculty' => $d('Faculty', 'faculty.php', 'Meet the experienced faculty members of GIMT.', 0.6, 'monthly'),
        '/contact' => $d('Contact Us', 'contact.php', 'Contact GIMT admissions and student support — phone, email, address and map.', 0.7, 'yearly'),
        '/blog' => $d('News & Blog', 'blog.php', 'Latest news, achievements and articles from GIMT.', 0.7, 'daily'),
        '/events' => $d('Events', 'events.php', 'Upcoming and past events at GIMT.', 0.6, 'weekly'),
        '/notices' => $d('Notice Board', 'notices.php', 'Official notices and circulars from GIMT.', 0.6, 'daily'),
        '/gallery' => $d('Gallery', 'gallery.php', 'Photo albums from the GIMT campus and events.', 0.5, 'monthly'),
        '/faq' => $d('FAQs', 'faq.php', 'Answers to frequently asked questions about admissions, fees and campus life.', 0.6, 'monthly'),
        '/apply' => $d('Apply Online', 'apply.php', 'Apply online for admission to GIMT programs.', 0.8, 'monthly'),
        '/verify-certificate' => $d('Verify a Certificate', 'verify-certificate.php', 'Verify the authenticity of a certificate issued by GIMT.', 0.3, 'yearly'),
    ];
}

/** All SEO rows keyed "type:id" / "route:/path". */
function cms_seo_rows(): array
{
    $out = [];
    foreach (db_all('SELECT * FROM seo_settings') as $r) {
        $out[$r['entity_type'] === 'route' ? 'route:' . $r['route'] : $r['entity_type'] . ':' . (int) $r['entity_id']] = $r;
    }
    return $out;
}

function cms_seo_suffix(): string
{
    return ' | ' . setting('institute_short_name', 'GIMT');
}

/**
 * Coverage entries for one entity type (or all), each with effective values, issues and a 0-100 score.
 * Entry keys: key, type, entity_id, route, title, url, status, is_public, has_seo, seo_id, meta_title, effective_title,
 * title_length, meta_description, effective_description, description_length, og_image, effective_og_image, robots,
 * include_in_sitemap, issues[], score, updated_at.
 */
function cms_seo_entries(?string $type = null): array
{
    $seo = cms_seo_rows();
    $types = $type ? [$type] : array_keys(cms_seo_entity_types());
    $raw = [];
    $try = function (string $sql, array $args = []): array {
        try {
            return db_all($sql, $args);
        } catch (Throwable $e) {
            return [];
        }
    };
    foreach ($types as $t) {
        switch ($t) {
            case 'route':
                $known = cms_public_routes();
                foreach ($known as $path => $r) {
                    $raw[] = ['type' => 'route', 'entity_id' => null, 'route' => $path, 'title' => $r['label'], 'url' => $path, 'status' => is_file(APP_ROOT . '/' . $r['file']) ? 'live' : 'planned',
                        'is_public' => is_file(APP_ROOT . '/' . $r['file']), 'fallback_description' => $r['description'], 'fallback_image' => 'assets/images/site/hero-campus-students.jpg', 'updated_at' => null];
                }
                foreach ($seo as $k => $row) {
                    if ($row['entity_type'] === 'route' && !isset($known[$row['route']])) {
                        $raw[] = ['type' => 'route', 'entity_id' => null, 'route' => $row['route'], 'title' => $row['meta_title'] ?: $row['route'], 'url' => $row['route'], 'status' => 'custom',
                            'is_public' => true, 'fallback_description' => '', 'fallback_image' => null, 'updated_at' => $row['updated_at']];
                    }
                }
                break;
            case 'page':
                foreach ($try('SELECT id, title, slug, is_system, status, published_at, excerpt, subtitle, banner_image, updated_at, created_at FROM pages ORDER BY title') as $p) {
                    [$refType, $ref] = cms_page_seo_ref($p);
                    $raw[] = ['type' => 'page', 'entity_id' => (int) $p['id'], 'route' => $refType === 'route' ? $ref : null, 'title' => $p['title'], 'url' => cms_page_url($p), 'status' => $p['status'],
                        'is_public' => $p['status'] === 'published', 'fallback_description' => $p['excerpt'] ?: $p['subtitle'], 'fallback_image' => $p['banner_image'],
                        'updated_at' => $p['updated_at'] ?: $p['created_at'], 'seo_key' => $refType === 'route' ? 'route:' . $ref : 'page:' . $p['id']];
                }
                break;
            case 'program':
                foreach ($try('SELECT id, name, slug, status, show_on_website, overview, image, updated_at, created_at FROM programs ORDER BY sort_order, name') as $p) {
                    $raw[] = ['type' => 'program', 'entity_id' => (int) $p['id'], 'route' => null, 'title' => $p['name'], 'url' => '/programs/' . $p['slug'],
                        'status' => $p['status'] === 'active' && $p['show_on_website'] ? 'published' : 'hidden', 'is_public' => $p['status'] === 'active' && (int) $p['show_on_website'] === 1,
                        'fallback_description' => $p['overview'], 'fallback_image' => $p['image'], 'updated_at' => $p['updated_at'] ?: $p['created_at']];
                }
                break;
            case 'post':
                foreach ($try('SELECT id, title, slug, status, published_at, excerpt, content, thumbnail, updated_at, created_at FROM blog_posts ORDER BY COALESCE(published_at, created_at) DESC LIMIT 2000') as $p) {
                    $raw[] = ['type' => 'post', 'entity_id' => (int) $p['id'], 'route' => null, 'title' => $p['title'], 'url' => '/blog/' . $p['slug'], 'status' => $p['status'],
                        'is_public' => $p['status'] === 'published', 'fallback_description' => $p['excerpt'] ?: $p['content'], 'fallback_image' => $p['thumbnail'], 'updated_at' => $p['updated_at'] ?: $p['created_at']];
                }
                break;
            case 'event':
                foreach ($try('SELECT id, title, slug, status, show_on_website, excerpt, description, image, updated_at, created_at FROM events ORDER BY start_datetime DESC LIMIT 2000') as $p) {
                    $public = in_array($p['status'], ['published', 'completed'], true) && (int) $p['show_on_website'] === 1;
                    $raw[] = ['type' => 'event', 'entity_id' => (int) $p['id'], 'route' => null, 'title' => $p['title'], 'url' => '/events/' . $p['slug'], 'status' => $public ? 'published' : $p['status'],
                        'is_public' => $public, 'fallback_description' => $p['excerpt'] ?: $p['description'], 'fallback_image' => $p['image'], 'updated_at' => $p['updated_at'] ?: $p['created_at']];
                }
                break;
            case 'notice':
                foreach ($try('SELECT id, title, slug, status, show_on_website, description, updated_at, created_at FROM notices ORDER BY COALESCE(publish_date, created_at) DESC LIMIT 2000') as $p) {
                    $public = $p['status'] === 'published' && (int) $p['show_on_website'] === 1;
                    $raw[] = ['type' => 'notice', 'entity_id' => (int) $p['id'], 'route' => null, 'title' => $p['title'], 'url' => '/notices/' . $p['slug'], 'status' => $public ? 'published' : $p['status'],
                        'is_public' => $public, 'fallback_description' => $p['description'], 'fallback_image' => null, 'updated_at' => $p['updated_at'] ?: $p['created_at']];
                }
                break;
        }
    }
    $suffix = cms_seo_suffix();
    $out = [];
    foreach ($raw as $r) {
        $key = $r['seo_key'] ?? ($r['type'] === 'route' ? 'route:' . $r['route'] : $r['type'] . ':' . $r['entity_id']);
        $s = $seo[$key] ?? null;
        $metaTitle = trim((string) ($s['meta_title'] ?? ''));
        $metaDesc = trim((string) ($s['meta_description'] ?? ''));
        $effTitle = $metaTitle !== '' ? $metaTitle : ($r['type'] === 'route' && $r['route'] === '/' ? institute_name() . $suffix : $r['title'] . $suffix);
        $fallbackDesc = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $r['fallback_description'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $effDesc = $metaDesc !== '' ? $metaDesc : ($fallbackDesc !== '' ? str_limit($fallbackDesc, 160) : '');
        $og = trim((string) ($s['og_image'] ?? ''));
        $effOg = $og !== '' ? $og : ((string) $r['fallback_image']);
        $robots = $s['robots'] ?? 'index,follow';
        $issues = [];
        if ($metaTitle === '') {
            $issues[] = 'missing_title';
        }
        if (mb_strlen($effTitle) > 60) {
            $issues[] = 'title_long';
        }
        if ($effDesc === '') {
            $issues[] = 'missing_description';
        } elseif ($metaDesc !== '' && mb_strlen($metaDesc) > 160) {
            $issues[] = 'description_long';
        } elseif ($metaDesc !== '' && mb_strlen($metaDesc) < 70) {
            $issues[] = 'description_short';
        } elseif ($metaDesc === '') {
            $issues[] = 'auto_description';
        }
        if ($effOg === '') {
            $issues[] = 'missing_og_image';
        }
        if (str_contains($robots, 'noindex')) {
            $issues[] = 'noindex';
        }
        $penalty = ['missing_title' => 20, 'title_long' => 15, 'missing_description' => 30, 'description_long' => 10, 'description_short' => 10,
            'auto_description' => 10, 'missing_og_image' => 15, 'noindex' => 0];
        $score = max(0, 100 - array_sum(array_map(fn ($i) => $penalty[$i] ?? 0, $issues)));
        $out[] = [
            'key' => $key, 'type' => $r['type'], 'entity_id' => $r['entity_id'], 'route' => $r['route'], 'title' => $r['title'], 'url' => $r['url'],
            'status' => $r['status'], 'is_public' => (bool) $r['is_public'], 'has_seo' => (bool) $s, 'seo_id' => $s ? (int) $s['id'] : null,
            'meta_title' => $metaTitle, 'effective_title' => $effTitle, 'title_length' => mb_strlen($effTitle),
            'meta_description' => $metaDesc, 'effective_description' => $effDesc, 'description_length' => mb_strlen($effDesc),
            'og_image' => $og, 'effective_og_image' => $effOg, 'robots' => $robots, 'include_in_sitemap' => $s ? (bool) $s['include_in_sitemap'] : true,
            'issues' => $issues, 'score' => $score, 'updated_at' => $s['updated_at'] ?? $r['updated_at'],
        ];
    }
    return $out;
}

function cms_seo_issue_labels(): array
{
    return ['missing_title' => 'No custom title', 'title_long' => 'Title over 60 characters', 'missing_description' => 'No description',
        'description_long' => 'Description over 160 characters', 'description_short' => 'Description under 70 characters',
        'auto_description' => 'Auto-generated description', 'missing_og_image' => 'No social share image', 'noindex' => 'Hidden from search (noindex)'];
}

/** SEO dashboard numbers. */
function cms_seo_overview(): array
{
    $entries = cms_seo_entries();
    $public = array_values(array_filter($entries, fn ($e) => $e['is_public']));
    $byType = [];
    foreach (cms_seo_entity_types() as $t => $label) {
        $list = array_values(array_filter($public, fn ($e) => $e['type'] === $t));
        $all = array_values(array_filter($entries, fn ($e) => $e['type'] === $t));
        $byType[] = ['type' => $t, 'label' => $label, 'total' => count($all), 'public' => count($list),
            'custom' => count(array_filter($list, fn ($e) => $e['has_seo'])),
            'issues' => count(array_filter($list, fn ($e) => array_diff($e['issues'], ['noindex']))),
            'score' => $list ? (int) round(array_sum(array_column($list, 'score')) / count($list)) : null];
    }
    $issues = [];
    foreach (array_keys(cms_seo_issue_labels()) as $code) {
        $issues[$code] = count(array_filter($public, fn ($e) => in_array($code, $e['issues'], true)));
    }
    usort($public, fn ($a, $b) => $a['score'] <=> $b['score'] ?: strcmp($a['title'], $b['title']));
    $sitemap = cms_sitemap_urls(true);
    return [
        'score' => $public ? (int) round(array_sum(array_column($public, 'score')) / count($public)) : 0,
        'total' => count($public),
        'custom' => count(array_filter($public, fn ($e) => $e['has_seo'])),
        'coverage' => $public ? (int) round(count(array_filter($public, fn ($e) => $e['has_seo'])) / count($public) * 100) : 0,
        'by_type' => $byType,
        'issues' => $issues,
        'issue_labels' => cms_seo_issue_labels(),
        'worst' => array_slice(array_values(array_filter($public, fn ($e) => $e['score'] < 100)), 0, 8),
        'sitemap' => ['urls' => count(array_filter($sitemap, fn ($u) => !$u['pending'])), 'pending' => count(array_filter($sitemap, fn ($u) => $u['pending']))],
        'robots_custom' => trim((string) setting('robots_txt', '')) !== '',
        'noindex' => $issues['noindex'],
    ];
}

/** Resolve an SEO target. Returns ['ref_type','ref_id','ref_route','entity' => [...]] or null when it does not exist. */
function cms_seo_resolve(string $type, ?int $id, ?string $route): ?array
{
    if (!isset(cms_seo_entity_types()[$type])) {
        return null;
    }
    if ($type === 'route') {
        $route = '/' . ltrim(trim((string) $route), '/');
        if ($route !== '/') {
            $route = rtrim($route, '/');
        }
        if (!preg_match('#^/[A-Za-z0-9\-_/.]*$#', $route) || mb_strlen($route) > 190) {
            return null;
        }
        $known = cms_public_routes()[$route] ?? null;
        return ['ref_type' => 'route', 'ref_id' => null, 'ref_route' => $route, 'entity' => ['type' => 'route', 'id' => null, 'route' => $route,
            'title' => $known['label'] ?? $route, 'url' => $route, 'status' => $known ? (is_file(APP_ROOT . '/' . $known['file']) ? 'live' : 'planned') : 'custom',
            'fallback_description' => $known['description'] ?? '', 'fallback_image' => 'assets/images/site/hero-campus-students.jpg']];
    }
    if (!$id) {
        return null;
    }
    $map = [
        'page' => ['SELECT id, title, slug, is_system, status, COALESCE(excerpt, subtitle) AS d, banner_image AS img FROM pages WHERE id = ?', null],
        'program' => ["SELECT id, name AS title, slug, IF(status = 'active' AND show_on_website = 1, 'published', 'hidden') AS status, overview AS d, image AS img FROM programs WHERE id = ?", '/programs/'],
        'post' => ['SELECT id, title, slug, status, COALESCE(excerpt, content) AS d, thumbnail AS img FROM blog_posts WHERE id = ?', '/blog/'],
        'event' => ['SELECT id, title, slug, status, COALESCE(excerpt, description) AS d, image AS img FROM events WHERE id = ?', '/events/'],
        'notice' => ['SELECT id, title, slug, status, description AS d, NULL AS img FROM notices WHERE id = ?', '/notices/'],
    ];
    try {
        $row = db_row($map[$type][0], [$id]);
    } catch (Throwable $e) {
        $row = null;
    }
    if (!$row) {
        return null;
    }
    $url = $type === 'page' ? cms_page_url($row) : $map[$type][1] . $row['slug'];
    $entity = ['type' => $type, 'id' => (int) $row['id'], 'route' => null, 'title' => $row['title'], 'url' => $url, 'status' => $row['status'],
        'fallback_description' => trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $row['d'], ENT_QUOTES | ENT_HTML5, 'UTF-8')))), 'fallback_image' => $row['img']];
    if ($type === 'page') {
        [$rt, $ref] = cms_page_seo_ref($row);
        if ($rt === 'route') {
            $entity['route'] = $ref;
            return ['ref_type' => 'route', 'ref_id' => null, 'ref_route' => $ref, 'entity' => $entity];
        }
    }
    return ['ref_type' => $type, 'ref_id' => (int) $row['id'], 'ref_route' => null, 'entity' => $entity];
}

/** Find the SEO row of a resolved target. */
function cms_seo_find(array $target): ?array
{
    return $target['ref_type'] === 'route'
        ? db_row("SELECT * FROM seo_settings WHERE entity_type = 'route' AND route = ?", [$target['ref_route']])
        : db_row('SELECT * FROM seo_settings WHERE entity_type = ? AND entity_id = ?', [$target['ref_type'], $target['ref_id']]);
}

/**
 * Validate SEO input. @return array [clean columns, errors]
 */
function cms_seo_check_input(array $in): array
{
    $errors = [];
    $s = fn (string $k) => trim(preg_replace('/[ \t]+/u', ' ', (string) ($in[$k] ?? '')));
    $data = [
        'meta_title' => $s('meta_title'), 'meta_description' => trim(preg_replace('/\s+/u', ' ', (string) ($in['meta_description'] ?? ''))),
        'meta_keywords' => $s('meta_keywords'), 'canonical_url' => $s('canonical_url'), 'og_title' => $s('og_title'),
        'og_description' => trim(preg_replace('/\s+/u', ' ', (string) ($in['og_description'] ?? ''))), 'og_image' => $s('og_image'),
        'robots' => $s('robots') ?: 'index,follow', 'schema_json' => trim((string) ($in['schema_json'] ?? '')),
        'include_in_sitemap' => in_array($in['include_in_sitemap'] ?? true, [true, 1, '1', 'true', 'on'], true) ? 1 : 0,
        'sitemap_priority' => $in['sitemap_priority'] ?? 0.7, 'sitemap_changefreq' => $s('sitemap_changefreq') ?: 'weekly',
    ];
    foreach (['meta_title' => [190, 'SEO title'], 'meta_description' => [320, 'Meta description'], 'meta_keywords' => [255, 'Keywords'], 'canonical_url' => [255, 'Canonical URL'],
        'og_title' => [190, 'Social title'], 'og_description' => [320, 'Social description'], 'og_image' => [255, 'Social image']] as $k => [$max, $label]) {
        if (mb_strlen($data[$k]) > $max) {
            $errors[$k] = "$label may not be longer than $max characters.";
        }
    }
    if ($data['canonical_url'] !== '' && !preg_match('#^(https?://\S+|/\S*)$#i', $data['canonical_url'])) {
        $errors['canonical_url'] = 'Use an absolute URL (https://…) or a path starting with /.';
    }
    if ($data['og_image'] !== '' && !cms_valid_image($data['og_image'])) {
        $errors['og_image'] = 'Choose an image from the media library or upload one.';
    }
    if (!isset(cms_robots_options()[$data['robots']])) {
        $errors['robots'] = 'Select a valid robots directive.';
    }
    if (!isset(cms_changefreq_options()[$data['sitemap_changefreq']])) {
        $errors['sitemap_changefreq'] = 'Select a valid change frequency.';
    }
    if (!is_numeric($data['sitemap_priority']) || $data['sitemap_priority'] < 0 || $data['sitemap_priority'] > 1) {
        $errors['sitemap_priority'] = 'Priority must be between 0.0 and 1.0.';
    } else {
        $data['sitemap_priority'] = round((float) $data['sitemap_priority'], 1);
    }
    if ($data['schema_json'] !== '') {
        $decoded = json_decode($data['schema_json'], true);
        if (!is_array($decoded)) {
            $errors['schema_json'] = 'Schema must be valid JSON-LD (an object or an array): ' . json_last_error_msg() . '.';
        } else {
            $data['schema_json'] = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (mb_strlen($data['schema_json']) > 60000) {
                $errors['schema_json'] = 'Schema is too large.';
            }
        }
    }
    foreach ($data as $k => $v) {
        if ($v === '') {
            $data[$k] = null;
        }
    }
    return [$data, $errors];
}

/** Default robots.txt (used when the robots_txt setting is empty). */
function cms_default_robots_txt(): string
{
    return "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/\nDisallow: /storage/\nDisallow: /search\n\nSitemap: " . absolute_url('sitemap.xml') . "\n";
}

function cms_robots_txt(): string
{
    $custom = trim((string) setting('robots_txt', ''));
    return $custom !== '' ? $custom . "\n" : cms_default_robots_txt();
}

/** Validate robots.txt content. Returns error message or null. */
function cms_robots_error(string $content): ?string
{
    if (mb_strlen($content) > 10000) {
        return 'robots.txt may not be longer than 10,000 characters.';
    }
    foreach (preg_split('/\r?\n/', $content) as $n => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^(user-agent|allow|disallow|sitemap|crawl-delay|host|clean-param)\s*:\s*\S.*$/i', $line) && !preg_match('/^disallow\s*:\s*$/i', $line)) {
            return 'Line ' . ($n + 1) . ' is not a valid robots.txt directive: "' . mb_substr($line, 0, 60) . '". Use User-agent, Allow, Disallow, Sitemap or Crawl-delay.';
        }
    }
    return null;
}

/**
 * URLs for sitemap.xml: [{loc, path, type, title, lastmod, changefreq, priority, pending}]. Excludes noindex and
 * include_in_sitemap = 0. Entries whose template file does not exist yet are only returned with $includePending.
 */
function cms_sitemap_urls(bool $includePending = false): array
{
    $seo = cms_seo_rows();
    $out = [];
    $seen = [];
    $add = function (string $type, string $title, string $path, ?string $lastmod, string $key, float $prio, string $freq, bool $pending) use (&$out, &$seen, $seo, $includePending) {
        if (isset($seen[$path]) || ($pending && !$includePending)) {
            return;
        }
        $s = $seo[$key] ?? null;
        if ($s && (str_contains((string) $s['robots'], 'noindex') || !(int) $s['include_in_sitemap'])) {
            return;
        }
        $seen[$path] = true;
        $out[] = ['loc' => absolute_url(ltrim($path, '/')), 'path' => $path, 'type' => $type, 'title' => $title,
            'lastmod' => $lastmod ? date('Y-m-d', strtotime($lastmod)) : null,
            'changefreq' => $s['sitemap_changefreq'] ?? $freq, 'priority' => $s ? (float) $s['sitemap_priority'] : $prio, 'pending' => $pending];
    };
    $tpl = fn (string $file) => !is_file(APP_ROOT . '/' . $file);
    foreach (cms_public_routes() as $path => $r) {
        $add('route', $r['label'], $path, null, 'route:' . $path, $r['priority'], $r['changefreq'], $tpl($r['file']));
    }
    $try = function (string $sql): array {
        try {
            return db_all($sql);
        } catch (Throwable $e) {
            return [];
        }
    };
    foreach ($try("SELECT id, title, slug, is_system, updated_at, created_at FROM pages WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW()) ORDER BY sort_order, title") as $p) {
        [$rt, $ref] = cms_page_seo_ref($p);
        $add('page', $p['title'], cms_page_url($p), $p['updated_at'] ?: $p['created_at'], $rt === 'route' ? 'route:' . $ref : 'page:' . $p['id'], 0.6, 'monthly',
            $rt === 'route' ? $tpl(basename($ref) . '.php') : $tpl('page.php'));
    }
    foreach ($try("SELECT id, name, slug, updated_at, created_at FROM programs WHERE status = 'active' AND show_on_website = 1 ORDER BY sort_order, name") as $p) {
        $add('program', $p['name'], '/programs/' . $p['slug'], $p['updated_at'] ?: $p['created_at'], 'program:' . $p['id'], 0.8, 'monthly', $tpl('program.php'));
    }
    foreach ($try("SELECT id, title, slug, updated_at, published_at FROM blog_posts WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW()) ORDER BY published_at DESC LIMIT 5000") as $p) {
        $add('post', $p['title'], '/blog/' . $p['slug'], $p['updated_at'] ?: $p['published_at'], 'post:' . $p['id'], 0.6, 'monthly', $tpl('post.php'));
    }
    foreach ($try("SELECT id, title, slug, updated_at, created_at FROM events WHERE show_on_website = 1 AND status IN ('published','completed') ORDER BY start_datetime DESC LIMIT 5000") as $p) {
        $add('event', $p['title'], '/events/' . $p['slug'], $p['updated_at'] ?: $p['created_at'], 'event:' . $p['id'], 0.5, 'weekly', $tpl('event.php'));
    }
    foreach ($try("SELECT id, title, slug, updated_at, publish_date FROM notices WHERE status = 'published' AND show_on_website = 1 AND (publish_date IS NULL OR publish_date <= NOW()) AND (expiry_date IS NULL OR expiry_date >= NOW()) ORDER BY publish_date DESC LIMIT 5000") as $p) {
        $add('notice', $p['title'], '/notices/' . $p['slug'], $p['updated_at'] ?: $p['publish_date'], 'notice:' . $p['id'], 0.4, 'weekly', $tpl('notice.php'));
    }
    foreach ($try("SELECT id, title, slug, created_at FROM galleries WHERE status = 'published' ORDER BY sort_order, title") as $p) {
        $add('gallery', $p['title'], '/gallery/' . $p['slug'], $p['created_at'], 'gallery:' . $p['id'], 0.4, 'monthly', $tpl('gallery.php'));
    }
    return $out;
}

/** The sitemap.xml document for cms_sitemap_urls() (the website's sitemap.php can simply echo this). */
function cms_sitemap_xml(): string
{
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (cms_sitemap_urls() as $u) {
        $x .= '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
            . ($u['lastmod'] ? '<lastmod>' . $u['lastmod'] . '</lastmod>' : '')
            . '<changefreq>' . htmlspecialchars((string) $u['changefreq'], ENT_XML1, 'UTF-8') . '</changefreq>'
            . '<priority>' . number_format((float) $u['priority'], 1, '.', '') . '</priority></url>' . "\n";
    }
    return $x . '</urlset>' . "\n";
}

/** Are /sitemap.xml and /robots.txt served by the website yet (tools/router.php + .htaccess map them to these files)? */
function cms_seo_files_served(): array
{
    return ['sitemap' => is_file(APP_ROOT . '/sitemap.php'), 'robots' => is_file(APP_ROOT . '/robots.php')];
}

/* =====================================================================================================================
 * Home page defaults (mockup content) — used by database/seed/05_cms_core.php and "Reset to default"
 * ===================================================================================================================*/

/** key => [title, subtitle, content, sort_order, is_enabled] */
function cms_default_sections(): array
{
    $img = fn (string $f) => 'assets/images/site/' . $f;
    $session = (function () {
        try {
            return (string) (db_value('SELECT name FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1') ?: '2026-27');
        } catch (Throwable $e) {
            return '2026-27';
        }
    })();
    $tour = trim((string) setting('campus_tour_video_url', ''));
    $tour = $tour !== '' && cms_valid_url($tour) ? $tour : '';
    $s = [
        'hero' => ['Shape Your Future.', 'Global Institute of Management & Technology empowers students with industry-focused education, expert mentorship and a vibrant campus in Knowledge Park, Greater Noida.', [
            'eyebrow' => 'Shaping Future Leaders', 'highlight' => 'Build Your Legacy.', 'image' => $img('hero-campus-students.jpg'),
            'image_alt' => 'Smiling GIMT students in front of the academic block', 'primary_cta' => ['label' => 'Apply for Admission', 'url' => '/apply'],
            'secondary_cta' => ['label' => 'Explore Programs', 'url' => '/programs'], 'badge' => 'Admissions Open for ' . $session,
            'tagline_words' => ['Learn', 'Grow', 'Innovate', 'Succeed'],
            'chips' => [['icon' => 'briefcase-business', 'label' => 'Industry Focused'], ['icon' => 'users', 'label' => 'Expert Faculty'],
                ['icon' => 'building-2', 'label' => 'Modern Campus'], ['icon' => 'trending-up', 'label' => 'Placement Support']],
            'video_url' => $tour, 'video_label' => 'Watch Our Campus Tour', 'use_banners' => true]],
        'stats' => ['GIMT at a Glance', null, ['variant' => 'overlap', 'anchor' => 'at-a-glance', 'items' => [
            ['value' => '10', 'prefix' => '', 'suffix' => '+', 'label' => 'Programs', 'icon' => 'graduation-cap', 'source' => 'programs'],
            ['value' => '1000', 'prefix' => '', 'suffix' => '+', 'label' => 'Students', 'icon' => 'users', 'source' => 'students'],
            ['value' => '50', 'prefix' => '', 'suffix' => '+', 'label' => 'Faculty', 'icon' => 'user-check', 'source' => 'faculty'],
            ['value' => '95', 'prefix' => '', 'suffix' => '%', 'label' => 'Student Satisfaction', 'icon' => 'smile', 'source' => 'satisfaction']]]],
        'features' => ['Why students choose GIMT', null, ['items' => [
            ['icon' => 'award', 'title' => 'Academic Excellence', 'text' => 'Outcome-based curriculum reviewed every year with industry experts.'],
            ['icon' => 'briefcase-business', 'title' => 'Industry-Focused Curriculum', 'text' => 'Live projects, internships and certifications in every program.'],
            ['icon' => 'users', 'title' => 'Experienced Faculty', 'text' => 'PhD faculty and practitioners who mentor every student.'],
            ['icon' => 'building-2', 'title' => 'Modern Infrastructure', 'text' => 'Smart classrooms, advanced labs and a digital library.'],
            ['icon' => 'rocket', 'title' => 'Career Development', 'text' => 'Dedicated placement cell, aptitude training and mock interviews.'],
            ['icon' => 'heart', 'title' => 'Student-Centric Education', 'text' => 'Mentoring, counselling and support from day one.']]]],
        'programs' => ['Explore Our Academic Programs', 'Choose from industry-aligned undergraduate, postgraduate, diploma and certificate programs designed for the careers of tomorrow.', [
            'eyebrow' => 'Programs & Courses', 'anchor' => 'programs', 'categories' => ['all', 'ug', 'pg', 'management', 'technology', 'commerce', 'diploma', 'certificate'],
            'mode' => 'all', 'limit' => 6, 'program_ids' => [], 'view_all' => ['label' => 'View All Programs', 'url' => '/programs']]],
        'why' => ['Why Choose GIMT', 'Everything you need to learn, grow and launch a successful career — in one inspiring campus.', [
            'eyebrow' => 'Why GIMT', 'image' => '', 'items' => [
                ['icon' => 'lightbulb', 'title' => 'Industry-Relevant Learning', 'text' => 'Curriculum co-designed with recruiters and updated every year.', 'tint' => 'lavender'],
                ['icon' => 'users', 'title' => 'Expert Faculty & Mentorship', 'text' => 'Learn from experienced academics and industry practitioners.', 'tint' => 'sky'],
                ['icon' => 'laptop', 'title' => 'Digital Learning Resources', 'text' => 'Digital library, e-journals, LMS and 24×7 Wi-Fi campus.', 'tint' => 'aqua'],
                ['icon' => 'target', 'title' => 'Career Preparation', 'text' => 'Aptitude, soft-skills and interview training from the first year.', 'tint' => 'cream'],
                ['icon' => 'microscope', 'title' => 'Practical Exposure', 'text' => 'Labs, live projects, industrial visits and internships.', 'tint' => 'sand'],
                ['icon' => 'shield-check', 'title' => 'Student Support', 'text' => 'Mentors, counsellors and a responsive student helpdesk.', 'tint' => 'lavender'],
                ['icon' => 'cpu', 'title' => 'Innovation & Technology', 'text' => 'Incubation support, hackathons and research projects.', 'tint' => 'sky'],
                ['icon' => 'trophy', 'title' => 'Holistic Development', 'text' => 'Sports, clubs, cultural fests and community service.', 'tint' => 'aqua']]]],
        'academic_excellence' => ['Academic Excellence Rooted in Real-World Learning', 'Since 2008, GIMT has prepared thousands of graduates for leadership roles in business and technology.', [
            'eyebrow' => 'About GIMT',
            'text' => "Global Institute of Management & Technology (GIMT) is committed to high-quality, industry-oriented education with a strong focus on innovation, research and holistic development.\n\nOur programs combine rigorous academics with live projects, internships and mentoring, so every student graduates confident, employable and ready to lead.",
            'bullets' => ['Industry-integrated curriculum', 'Experienced and qualified faculty', 'State-of-the-art infrastructure', 'Strong placement and career support'],
            'cta' => ['label' => 'Discover GIMT', 'url' => '/about'], 'image' => $img('campus-main.jpg'), 'image_alt' => 'The GIMT main campus building',
            'video_url' => $tour, 'video_label' => 'Virtual Campus Tour',
            'cards' => [['icon' => 'eye', 'title' => 'Our Vision', 'text' => 'To be a globally recognised institution for excellence in education and innovation, creating leaders who make a positive impact on society.'],
                ['icon' => 'target', 'title' => 'Our Mission', 'text' => 'To empower students with knowledge, skills and values through industry-relevant education, research and holistic development.']]]],
        'admission_process' => ['The Admission Journey', 'Five simple steps from choosing a program to joining your first class.', [
            'eyebrow' => 'Admissions ' . $session, 'cta' => ['label' => 'Start Your Application', 'url' => '/apply'], 'image' => $img('students-laptop.jpg'),
            'image_alt' => 'Students completing the online application on a laptop', 'steps' => [
                ['icon' => 'search', 'title' => 'Explore Programs', 'text' => 'Compare programs, eligibility and fees to find the right fit.'],
                ['icon' => 'file-text', 'title' => 'Submit Application', 'text' => 'Fill the online application form in about ten minutes.'],
                ['icon' => 'upload', 'title' => 'Upload Documents', 'text' => 'Upload your photo, mark sheets and ID proof securely.'],
                ['icon' => 'badge-check', 'title' => 'Verification & Approval', 'text' => 'Our admissions team verifies documents and confirms eligibility.'],
                ['icon' => 'wallet', 'title' => 'Enrollment & Fee Payment', 'text' => 'Pay the admission fee online and receive your confirmation.']]]],
        'campus_life' => ['Life at GIMT', 'Learning goes far beyond the classroom.', [
            'eyebrow' => 'Explore', 'anchor' => 'campus-life', 'cards' => [
                ['title' => 'Campus Life', 'text' => 'Clubs, fests, sports and a green, safe campus that feels like home.', 'image' => $img('students-lawn.jpg'), 'url' => '/campus', 'link_label' => 'Explore Campus'],
                ['title' => 'Placements', 'text' => 'A dedicated placement cell connecting students with leading recruiters.', 'image' => $img('placement-student.jpg'), 'url' => '/placement', 'link_label' => 'View Placements'],
                ['title' => 'News & Events', 'text' => 'Seminars, hackathons, convocations and achievements from campus.', 'image' => $img('news-campus.jpg'), 'url' => '/blog', 'link_label' => 'Latest Updates']]]],
        'placements' => ['Placement Highlights', 'Our students are hired by leading companies across consulting, IT, banking and FMCG.', [
            'eyebrow' => 'Careers', 'cta' => ['label' => 'View Placement Details', 'url' => '/placement'],
            'text' => 'The GIMT Training & Placement Cell prepares every student through aptitude training, mock interviews and industry mentoring, and partners with recruiters for internships and final placements.',
            'image' => $img('placement-student.jpg'), 'image_alt' => 'A GIMT graduate after a successful interview', 'stats' => [
                ['value' => '90', 'prefix' => '', 'suffix' => '%', 'label' => 'Placement rate', 'icon' => 'trending-up', 'source' => 'placement_rate'],
                ['value' => '25', 'prefix' => '₹', 'suffix' => ' LPA', 'label' => 'Highest package', 'icon' => 'trophy', 'source' => 'highest_package'],
                ['value' => '6', 'prefix' => '₹', 'suffix' => ' LPA', 'label' => 'Average package', 'icon' => 'chart-column', 'source' => 'average_package'],
                ['value' => '35', 'prefix' => '', 'suffix' => '+', 'label' => 'Recruiters', 'icon' => 'handshake', 'source' => 'recruiters']],
            'show_top_offers' => true, 'offers_limit' => 6]],
        'recruiters' => ['Our Recruiters', 'Companies that trust GIMT talent.', [
            'eyebrow' => 'Top Recruiters', 'source' => 'companies', 'limit' => 24, 'rows' => '2', 'items' => [], 'cta' => ['label' => 'Partner With Us', 'url' => '/contact?type=placement#message']]],
        'testimonials' => ['Student & Alumni Success Stories', 'Hear from the people who know GIMT best.', [
            'eyebrow' => 'Success Stories', 'types' => ['student', 'alumni'], 'limit' => 8, 'autoplay' => 7000, 'cta' => ['label' => 'Meet Our Alumni', 'url' => '/alumni']]],
        'events_news' => ['Events & News', 'What is happening on campus.', [
            'eyebrow' => 'Stay Updated', 'anchor' => 'news', 'show_events' => true, 'events_limit' => 3, 'show_news' => true, 'news_limit' => 3, 'show_notices' => true, 'notices_limit' => 5,
            'view_all_events' => ['label' => 'All Events', 'url' => '/events'], 'view_all_news' => ['label' => 'All News', 'url' => '/blog']]],
        'faqs' => ['Frequently Asked Questions', 'Quick answers about admissions, fees, hostel and placements.', [
            'eyebrow' => 'FAQs', 'categories' => [], 'home_only' => true, 'limit' => 8, 'columns' => '2', 'view_all' => ['label' => 'View All FAQs', 'url' => '/faq']]],
        'cta' => ['Ready to Build a Brighter Future?', 'Join GIMT and take the next step towards your academic and professional success. Our counsellors will help you choose the right program.', [
            'eyebrow' => 'Admissions Open ' . $session, 'theme' => 'dark', 'primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
            'secondary' => ['label' => 'Download Brochure', 'url' => '/programs#brochure'], 'image' => $img('graduation.jpg'), 'show_enquiry_form' => true]],
    ];
    $out = [];
    $i = 0;
    foreach ($s as $key => [$title, $subtitle, $content]) {
        $out[$key] = ['title' => $title, 'subtitle' => $subtitle, 'content' => $content, 'sort_order' => ++$i, 'is_enabled' => 1];
    }
    return $out;
}
