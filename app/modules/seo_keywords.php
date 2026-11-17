<?php
/**
 * SEO Management › Keywords: the keyword master database (one primary keyword cluster per landing page).
 * CSV/Excel import accepts the owner's sheet format directly (columns keyword, category, intent, volume, difficulty,
 * competition, target_url, priority, current_position, status — "not measured" / "unknown" become empty numbers,
 * "Brand / primary (note)" sets the category + primary flag + note, "PROPOSED:https://…" marks a page still to build).
 * Import upserts on the keyword (case-insensitive). Normalisation: seo_keyword_normalize() (app/services/seo_presence.php).
 * Cannibalisation flags per row (transform_row) and the full report: GET /api/seo-ai/keywords/conflicts.
 */
require_once APP_ROOT . '/app/services/seo_presence.php';

$categories = [];
try {
    foreach (db_column("SELECT DISTINCT category FROM seo_keywords WHERE category IS NOT NULL AND category <> '' ORDER BY category LIMIT 200") as $c) {
        $categories[$c] = $c;
    }
} catch (Throwable $e) {
    $categories = [];
}

return [
    'table' => 'seo_keywords',
    'title' => 'Keywords',
    'singular' => 'Keyword',
    'permission' => 'seo',
    'permissions' => ['create' => ['seo', 'edit'], 'edit' => ['seo', 'edit'], 'delete' => ['seo', 'edit'], 'export' => ['seo', 'view'], 'import' => ['seo', 'edit']],
    'icon' => 'key-round',
    'description' => 'Keyword master database: search intent, volume, difficulty, the landing page each keyword targets and its ranking.',
    'select' => 't.*',
    'search' => ['t.keyword', 't.category', 't.cluster', 't.target_url', 't.notes'],
    'search_placeholder' => 'Search keywords, clusters, URLs…',
    'order' => "FIELD(t.priority, 'high', 'medium', 'low'), t.category, t.is_primary DESC, t.keyword",
    'per_page' => 50,
    'columns' => [
        ['key' => 'keyword', 'label' => 'Keyword', 'format' => 'title', 'sub' => 'category', 'sortable' => true],
        ['key' => 'category', 'label' => 'Category', 'sortable' => true, 'hidden' => true],
        ['key' => 'cluster', 'label' => 'Cluster', 'sortable' => true, 'hidden' => true],
        ['key' => 'is_primary', 'label' => 'Primary', 'format' => 'boolean', 'align' => 'center', 'sortable' => true],
        ['key' => 'intent', 'label' => 'Intent', 'format' => 'badge', 'sortable' => true,
            'colors' => ['informational' => 'blue', 'navigational' => 'navy', 'commercial' => 'purple', 'transactional' => 'green', 'local' => 'amber']],
        ['key' => 'monthly_volume', 'label' => 'Volume', 'format' => 'number', 'align' => 'right', 'sortable' => true],
        ['key' => 'volume_source', 'label' => 'Volume source', 'hidden' => true],
        ['key' => 'difficulty', 'label' => 'Difficulty', 'format' => 'number', 'align' => 'right', 'sortable' => true, 'hidden' => true],
        ['key' => 'competition', 'label' => 'Competition', 'format' => 'badge', 'sortable' => true, 'colors' => ['low' => 'green', 'medium' => 'amber', 'high' => 'red']],
        ['key' => 'target_url', 'label' => 'Target URL', 'format' => 'code', 'sortable' => true],
        ['key' => 'priority', 'label' => 'Priority', 'format' => 'badge', 'sortable' => "FIELD(t.priority, 'high', 'medium', 'low')", 'colors' => ['high' => 'red', 'medium' => 'amber', 'low' => 'slate']],
        ['key' => 'current_position', 'label' => 'Position', 'format' => 'number', 'align' => 'right', 'sortable' => true],
        ['key' => 'position_checked_at', 'label' => 'Position checked', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true,
            'colors' => ['pending' => 'slate', 'planned' => 'purple', 'targeting' => 'blue', 'ranking' => 'amber', 'achieved' => 'green', 'paused' => 'slate']],
        ['key' => 'notes', 'label' => 'Notes', 'format' => 'truncate', 'hidden' => true],
        ['key' => 'updated_at', 'label' => 'Updated', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'category', 'label' => 'Category', 'options' => $categories],
        ['key' => 'priority', 'label' => 'Priority', 'options' => seo_keyword_priorities()],
        ['key' => 'status', 'label' => 'Status', 'options' => seo_keyword_statuses()],
        ['key' => 'intent', 'label' => 'Intent', 'options' => seo_keyword_intents()],
        ['key' => 'is_primary', 'label' => 'Type', 'options' => ['1' => 'Primary keywords', '0' => 'Secondary keywords']],
        ['key' => 'measured', 'label' => 'Data', 'sql_map' => [
            'volume' => 't.monthly_volume IS NOT NULL', 'no_volume' => 't.monthly_volume IS NULL',
            'ranking' => 't.current_position IS NOT NULL', 'top10' => 't.current_position IS NOT NULL AND t.current_position <= 10',
            'no_url' => "(t.target_url IS NULL OR t.target_url = '')",
        ], 'options' => ['volume' => 'Volume measured', 'no_volume' => 'Volume not measured', 'ranking' => 'Position known', 'top10' => 'In the top 10', 'no_url' => 'No target URL']],
    ],
    'fields' => [
        ['name' => 'keyword', 'label' => 'Keyword', 'type' => 'text', 'required' => true, 'unique' => true, 'maxlength' => 190, 'col' => 8],
        ['name' => 'is_primary', 'label' => 'Primary keyword of its page', 'type' => 'toggle', 'default' => 0, 'col' => 4],
        ['name' => 'category', 'label' => 'Category', 'type' => 'text', 'maxlength' => 120, 'col' => 6, 'placeholder' => 'Brand, Admissions, Program BBA …',
            'help' => 'Imports accept “Category / primary (note)”.'],
        ['name' => 'cluster', 'label' => 'Cluster', 'type' => 'text', 'maxlength' => 100, 'col' => 6, 'help' => 'Keywords of one cluster should target one page. Empty = the category.'],
        ['name' => 'intent', 'label' => 'Intent', 'type' => 'select', 'options' => seo_keyword_intents(), 'validate_options' => false, 'col' => 4],
        ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'default' => 'medium', 'options' => seo_keyword_priorities(), 'validate_options' => false, 'col' => 4],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'pending', 'options' => seo_keyword_statuses(), 'validate_options' => false, 'col' => 4],
        ['name' => 'target_url', 'label' => 'Target URL', 'type' => 'text', 'maxlength' => 520, 'col' => 12, 'placeholder' => 'https://… or /programs/…',
            'help' => 'The one page that should rank for this keyword. “PROPOSED:https://…” marks a page still to be created.'],
        ['name' => 'monthly_volume', 'label' => 'Volume', 'type' => 'text', 'maxlength' => 40, 'col' => 4, 'placeholder' => 'not measured', 'help' => 'Monthly searches, or “not measured”.'],
        ['name' => 'volume_source', 'label' => 'Volume source', 'type' => 'select', 'default' => 'not_measured', 'options' => seo_keyword_volume_sources(), 'validate_options' => false, 'col' => 4],
        ['name' => 'difficulty', 'label' => 'Difficulty', 'type' => 'text', 'maxlength' => 40, 'col' => 4, 'placeholder' => '0–100', 'help' => '0–100, or “not measured”.'],
        ['name' => 'competition', 'label' => 'Competition', 'type' => 'select', 'options' => seo_keyword_competition(), 'validate_options' => false, 'col' => 4],
        ['name' => 'current_position', 'label' => 'Current position', 'type' => 'text', 'maxlength' => 40, 'col' => 4, 'placeholder' => 'unknown', 'help' => 'Average position in Search Console.'],
        ['name' => 'position_checked_at', 'label' => 'Position checked on', 'type' => 'date', 'col' => 4],
        ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 2000],
    ],
    'import_example' => ['keyword' => 'BBA college in Noida', 'category' => 'Program BBA / primary', 'intent' => 'commercial', 'monthly_volume' => 'not measured',
        'difficulty' => 'not measured', 'competition' => 'high', 'target_url' => '/programs/bba', 'priority' => 'high', 'current_position' => 'unknown', 'status' => 'pending',
        'is_primary' => '', 'cluster' => '', 'volume_source' => 'not_measured', 'position_checked_at' => '', 'notes' => ''],
    'bulk' => ['delete' => true, 'status' => array_keys(seo_keyword_statuses()), 'status_field' => 'status'],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            [$clean, $errors] = seo_keyword_normalize(array_merge($old ?? [], $data));
            foreach (array_keys($errors) as $k) {
                if (!array_key_exists($k, $data) && array_key_exists($k, $input)) {
                    unset($errors[$k]); // the field check already reported it (e.g. "already exists", too long)
                }
            }
            if (!$errors && $clean['keyword'] !== '') {
                $dup = db_value('SELECT id FROM seo_keywords WHERE keyword_hash = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$clean['keyword_hash'], $id] : [$clean['keyword_hash']]);
                if ($dup) {
                    $errors['keyword'] = 'This keyword is already in the database.';
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            [$clean] = seo_keyword_normalize(array_merge($old ?? [], $data));
            return $clean;
        },
        'transform_row' => function (array $row): array {
            $row['conflicts'] = seo_keyword_conflicts()['rows'][(int) $row['id']] ?? [];
            $row['is_primary'] = (int) $row['is_primary'];
            $row['current_position'] = $row['current_position'] !== null ? (float) $row['current_position'] : null;
            return $row;
        },
        'summary' => function (string $fromSql, array $args): array {
            $s = db_row("SELECT COUNT(*) total, SUM(t.is_primary = 1) primary_kw, SUM(t.priority = 'high') high, SUM(t.monthly_volume IS NOT NULL) measured,
                                SUM(t.current_position IS NOT NULL AND t.current_position <= 10) top10, SUM(t.status = 'planned') planned $fromSql", $args) ?: [];
            $c = seo_keyword_conflicts();
            return array_map('intval', $s) + ['conflicts' => count($c['clusters']) + count($c['urls'])];
        },
        'describe' => fn (array $row): string => (string) ($row['keyword'] ?? 'Keyword'),
    ],
];
