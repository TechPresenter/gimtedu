<?php
/**
 * Blog / news posts (Website CMS). Edited in the full-page editor (/admin/cms/blog/{id}); the payload may carry an
 * "seo" object which is validated and saved to seo_settings (entity_type = 'post') in the same transaction.
 * Publishing (status published/scheduled) requires the blog "publish" permission.
 */
require_once APP_ROOT . '/app/services/media.php';

$statuses = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published'];
$types = ['news' => 'News', 'blog' => 'Blog article', 'press' => 'Press release', 'achievement' => 'Achievement'];

return [
    'table' => 'blog_posts',
    'title' => 'Blog Posts',
    'singular' => 'Post',
    'permission' => 'blog',
    'icon' => 'newspaper',
    'description' => 'News, blog articles, press releases and achievements shown on the website.',
    'select' => "t.*, c.name AS category_name, u.name AS author_name, u.avatar AS author_avatar, s.id AS seo_id,
                 IF(s.id IS NULL, 0, (s.meta_title IS NOT NULL AND s.meta_title <> '') + (s.meta_description IS NOT NULL AND s.meta_description <> '')) AS seo_score",
    'joins' => "LEFT JOIN blog_categories c ON c.id = t.category_id
                LEFT JOIN users u ON u.id = t.author_id
                LEFT JOIN seo_settings s ON s.entity_type = 'post' AND s.entity_id = t.id",
    'search' => ['t.title', 't.slug', 't.tags', 't.excerpt', 'c.name', 'u.name'],
    'search_placeholder' => 'Search title, tags, category, author…',
    'order' => "FIELD(t.status, 'draft', 'scheduled', 'published'), COALESCE(t.published_at, t.created_at) DESC, t.id DESC",
    'columns' => [
        ['key' => 'thumbnail', 'label' => 'Image', 'format' => 'image'],
        ['key' => 'title', 'label' => 'Title', 'format' => 'title', 'sub' => 'category_name', 'sortable' => true, 'link' => 'view'],
        ['key' => 'author_name', 'label' => 'Author', 'format' => 'person', 'image' => 'author_avatar', 'sortable' => 'u.name'],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'hidden' => true, 'colors' => ['news' => 'blue', 'blog' => 'navy', 'press' => 'purple', 'achievement' => 'green']],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'published_at', 'label' => 'Publish date', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'views', 'label' => 'Views', 'format' => 'number', 'align' => 'right', 'sortable' => true],
        ['key' => 'is_featured', 'label' => 'Featured', 'format' => 'boolean', 'sortable' => true, 'hidden' => true],
        ['key' => 'tags', 'label' => 'Tags', 'format' => 'tags', 'hidden' => true],
        ['key' => 'slug', 'label' => 'Slug', 'format' => 'code', 'hidden' => true],
        ['key' => 'seo_score', 'label' => 'SEO', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'updated_at', 'label' => 'Last updated', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
    ],
    'export_columns' => [
        ['key' => 'title', 'label' => 'Title'], ['key' => 'slug', 'label' => 'Slug'], ['key' => 'category_name', 'label' => 'Category'], ['key' => 'type', 'label' => 'Type', 'format' => 'badge'],
        ['key' => 'author_name', 'label' => 'Author'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'published_at', 'label' => 'Publish date', 'format' => 'datetime'],
        ['key' => 'views', 'label' => 'Views', 'format' => 'number'], ['key' => 'is_featured', 'label' => 'Featured', 'format' => 'boolean'], ['key' => 'tags', 'label' => 'Tags'],
        ['key' => 'excerpt', 'label' => 'Excerpt'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => $statuses],
        ['key' => 'category_id', 'label' => 'Category', 'source' => 'blog_categories'],
        ['key' => 'type', 'label' => 'Type', 'options' => $types],
        ['key' => 'author_id', 'label' => 'Author', 'source' => 'users'],
        ['key' => 'is_featured', 'label' => 'Featured', 'type' => 'boolean'],
        ['key' => 'seo', 'label' => 'SEO', 'sql_map' => ['complete' => "s.meta_title IS NOT NULL AND s.meta_title <> '' AND s.meta_description IS NOT NULL AND s.meta_description <> ''",
            'missing' => "(s.id IS NULL OR s.meta_title IS NULL OR s.meta_title = '' OR s.meta_description IS NULL OR s.meta_description = '')"],
            'options' => ['complete' => 'SEO complete', 'missing' => 'SEO missing']],
        ['key' => 'published_at', 'label' => 'Publish date', 'type' => 'daterange'],
    ],
    'scopes' => ['category_id' => 't.category_id', 'author_id' => 't.author_id'],
    'fields' => [
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'maxlength' => 255, 'col' => 12, 'placeholder' => 'e.g. GIMT students win the national hackathon'],
        ['name' => 'slug', 'label' => 'Slug', 'type' => 'slug', 'slug_from' => 'title', 'unique' => true, 'maxlength' => 190, 'col' => 12,
            'help' => 'Post URL: /blog/your-slug — generated from the title when left empty.'],
        ['name' => 'excerpt', 'label' => 'Excerpt', 'type' => 'textarea', 'maxlength' => 500, 'rows' => 3, 'help' => 'Short summary shown on listing cards and in search results.'],
        ['name' => 'content', 'label' => 'Content', 'type' => 'richtext'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'draft', 'options' => $statuses],
        ['name' => 'published_at', 'label' => 'Publish date & time', 'type' => 'datetime', 'help' => 'Scheduled posts go live automatically at this time.'],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'news', 'options' => $types],
        ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'source' => 'blog_categories'],
        ['name' => 'author_id', 'label' => 'Author', 'type' => 'combobox', 'source' => 'users'],
        ['name' => 'tags', 'label' => 'Tags', 'type' => 'text', 'maxlength' => 255, 'placeholder' => 'admissions, mba, scholarships', 'help' => 'Comma separated.'],
        ['name' => 'is_featured', 'label' => 'Featured', 'type' => 'toggle', 'default' => false, 'placeholder' => 'Highlight on the home page and blog'],
        ['name' => 'thumbnail', 'label' => 'Featured image', 'type' => 'text', 'maxlength' => 255, 'help' => 'Chosen from the media library.'],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'xl', 'mode' => 'page'],
    'view' => ['type' => 'page', 'url' => '/cms/blog/{id}'],
    'hooks' => [
        'list_query' => function (array &$where, array &$args, array $req): void {
            cms_blog_publish_due();
        },
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $errors = [];
            $status = $data['status'] ?? ($old['status'] ?? 'draft');
            $oldStatus = $old['status'] ?? 'draft';
            if (in_array($status, ['published', 'scheduled'], true) && $status !== $oldStatus && !can('blog', 'publish')) {
                $errors['status'] = 'You can save drafts, but publishing needs the Blog "publish" permission. Ask a content manager to publish it.';
            } elseif ($old && $status === 'draft' && $oldStatus !== 'draft' && !can('blog', 'publish')) {
                $errors['status'] = 'Taking a live or scheduled post offline needs the Blog "publish" permission.';
            }
            if (array_key_exists('thumbnail', $data) && !media_valid_ref($data['thumbnail'])) {
                $errors['thumbnail'] = 'Choose the featured image from the media library.';
            }
            $publishedAt = array_key_exists('published_at', $data) ? $data['published_at'] : ($old['published_at'] ?? null);
            if ($status === 'scheduled') {
                if (!$publishedAt) {
                    $errors['published_at'] = 'Choose the date and time when this post should go live.';
                } elseif (strtotime($publishedAt) <= time()) {
                    $errors['published_at'] = 'A scheduled post needs a future date and time. Choose "Published" to publish now.';
                }
            }
            if ($status !== 'draft') {
                $content = array_key_exists('content', $data) ? $data['content'] : ($old['content'] ?? '');
                if (mb_strlen(trim(strip_tags((string) $content))) < 40) {
                    $errors['content'] = 'Write the post content (at least a few sentences) before publishing.';
                }
            }
            if (isset($input['seo']) && is_array($input['seo'])) {
                [, $seoErrors] = cms_seo_validate($input['seo']);
                $errors += $seoErrors;
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            if ($id === null) {
                $data['author_id'] = $data['author_id'] ?? user_id();
                if (empty($data['slug']) && !empty($data['title'])) {
                    $data['slug'] = slugify($data['title']);
                }
            }
            if (isset($data['tags'])) {
                $tags = array_values(array_unique(array_filter(array_map(fn ($t) => trim(preg_replace('/\s+/', ' ', $t)), explode(',', (string) $data['tags'])), fn ($t) => $t !== '')));
                $data['tags'] = $tags ? mb_substr(implode(', ', $tags), 0, 255) : null;
            }
            $status = $data['status'] ?? ($old['status'] ?? 'draft');
            $publishedAt = array_key_exists('published_at', $data) ? $data['published_at'] : ($old['published_at'] ?? null);
            if ($status === 'published') {
                if (!$publishedAt) {
                    $data['published_at'] = date('Y-m-d H:i:s');
                } elseif (strtotime($publishedAt) > time() + 60) {
                    $data['status'] = 'scheduled'; // a future date means "schedule it"
                }
            }
            if (array_key_exists('excerpt', $data) && ($data['excerpt'] === null || $data['excerpt'] === '')) {
                $content = array_key_exists('content', $data) ? $data['content'] : ($old['content'] ?? '');
                $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('</', ' </', (string) $content)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $data['excerpt'] = $text !== '' ? str_limit($text, 220) : null;
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            if (isset($input['seo']) && is_array($input['seo'])) {
                [$clean] = cms_seo_validate($input['seo']);
                cms_seo_save('post', $id, $clean);
            }
            $status = $data['status'] ?? ($old['status'] ?? null);
            if ($status !== ($old['status'] ?? null) && in_array($status, ['published', 'scheduled'], true)) {
                $title = $data['title'] ?? ($old['title'] ?? '');
                log_activity('publish', 'blog', $id, ($status === 'published' ? 'Published' : 'Scheduled') . ' blog post "' . str_limit($title, 80) . '"'
                    . ($status === 'scheduled' ? ' for ' . format_datetime($data['published_at'] ?? $old['published_at']) : ''));
            }
        },
        'after_delete' => function (int $id, array $row): void {
            db_exec("DELETE FROM seo_settings WHERE entity_type = 'post' AND entity_id = ?", [$id]);
        },
        'bulk_action' => function (string $action, array $ids, array $input): array {
            if (!in_array($action, ['publish', 'draft', 'feature', 'unfeature'], true)) {
                throw new CrudException('Unknown bulk action.');
            }
            if (in_array($action, ['publish', 'draft'], true) && !can('blog', 'publish')) {
                throw new CrudException($action === 'publish' ? 'You do not have permission to publish posts.' : 'You do not have permission to unpublish posts.');
            }
            $in = implode(',', array_map('intval', $ids));
            $n = match ($action) {
                'publish' => db_exec("UPDATE blog_posts SET status = 'published', published_at = COALESCE(IF(published_at > NOW(), NOW(), published_at), NOW())
                                      WHERE id IN ($in) AND status <> 'published' AND content IS NOT NULL AND CHAR_LENGTH(content) >= 40"),
                'draft' => db_exec("UPDATE blog_posts SET status = 'draft' WHERE id IN ($in) AND status <> 'draft'"),
                'feature' => db_exec("UPDATE blog_posts SET is_featured = 1 WHERE id IN ($in)"),
                'unfeature' => db_exec("UPDATE blog_posts SET is_featured = 0 WHERE id IN ($in)"),
            };
            $posts = $n . ' post' . ($n === 1 ? '' : 's');
            $msg = ['publish' => "Published $posts.", 'draft' => "Moved $posts to drafts.", 'feature' => "Featured $posts.", 'unfeature' => "Removed $posts from featured."][$action];
            log_activity($action === 'publish' ? 'publish' : 'update', 'blog', implode(',', array_slice($ids, 0, 20)), rtrim($msg, '.') . ' (bulk)');
            $skipped = count($ids) - $n;
            if ($skipped > 0 && in_array($action, ['publish', 'draft'], true)) {
                $msg .= ' ' . $skipped . ' skipped (already ' . ($action === 'publish' ? 'published or without content' : 'drafts') . ').';
            }
            return ['updated' => $n, 'message' => $msg];
        },
        'describe' => fn (array $row): string => '"' . str_limit((string) ($row['title'] ?? ''), 80) . '"',
    ],
];
