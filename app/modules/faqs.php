<?php
/** Frequently asked questions shown on the website (home, contact, admissions, programs pages). */
require_once APP_ROOT . '/app/services/media.php';

$categories = ['admissions' => 'Admissions', 'fees' => 'Fees & Scholarships', 'academics' => 'Academics', 'hostel' => 'Hostel & Transport',
    'placement' => 'Placements', 'campus' => 'Campus Life', 'general' => 'General'];

return [
    'table' => 'faqs',
    'title' => 'FAQs',
    'singular' => 'FAQ',
    'permission' => 'cms',
    'icon' => 'circle-help',
    'description' => 'Questions and answers grouped by category.',
    'select' => 't.*',
    'search' => ['t.question', 't.answer'],
    'search_placeholder' => 'Search questions and answers…',
    'order' => "FIELD(t.category, 'admissions', 'fees', 'academics', 'hostel', 'placement', 'campus', 'general'), t.sort_order ASC, t.id ASC",
    'per_page' => 50,
    'columns' => [
        ['key' => 'question', 'label' => 'Question', 'format' => 'truncate', 'truncate' => 90, 'sortable' => true],
        ['key' => 'category', 'label' => 'Category', 'format' => 'badge', 'sortable' => true,
            'colors' => ['admissions' => 'blue', 'fees' => 'green', 'academics' => 'navy', 'hostel' => 'amber', 'placement' => 'purple', 'campus' => 'cyan', 'general' => 'slate']],
        ['key' => 'answer', 'label' => 'Answer', 'format' => 'truncate', 'truncate' => 80, 'hidden' => true],
        ['key' => 'sort_order', 'label' => 'Order', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'show_on_home', 'label' => 'Home page', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'show_on_contact', 'label' => 'Contact page', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'updated_at', 'label' => 'Updated', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'category', 'label' => 'Category', 'options' => $categories],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['key' => 'show_on_home', 'label' => 'On home page', 'type' => 'boolean'],
        ['key' => 'show_on_contact', 'label' => 'On contact page', 'type' => 'boolean'],
    ],
    'fields' => [
        ['name' => 'question', 'label' => 'Question', 'type' => 'text', 'required' => true, 'maxlength' => 500, 'col' => 12, 'placeholder' => 'e.g. What is the last date to apply for 2026-27?'],
        ['name' => 'answer', 'label' => 'Answer', 'type' => 'textarea', 'required' => true, 'rows' => 6, 'maxlength' => 5000, 'help' => 'Plain text. Leave a blank line between paragraphs.'],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'default' => 'general', 'options' => $categories, 'col' => 6],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 3],
        ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'min' => 0, 'max' => 99999, 'col' => 3, 'help' => 'Empty = last.'],
        ['name' => 'show_on_home', 'label' => 'Home page', 'type' => 'toggle', 'default' => false, 'placeholder' => 'Show in the home page FAQ section'],
        ['name' => 'show_on_contact', 'label' => 'Contact page', 'type' => 'toggle', 'default' => false, 'placeholder' => 'Show on the Contact Us page'],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id): array {
            if (!empty($data['question'])) {
                $dup = db_value('SELECT id FROM faqs WHERE question = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$data['question'], $id] : [$data['question']]);
                if ($dup) {
                    return ['question' => 'This question already exists in the FAQ list.'];
                }
            }
            return [];
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
                $cat = $data['category'] ?? ($old['category'] ?? 'general');
                $data['sort_order'] = $id && $old && $old['category'] === $cat ? (int) $old['sort_order']
                    : (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM faqs WHERE category = ?', [$cat]);
            }
            return $data;
        },
        'bulk_action' => function (string $action, array $ids, array $input): array {
            switch ($action) {
                case 'reorder':
                    $n = cms_reorder('faqs', $ids);
                    log_activity('update', 'cms', null, 'Reordered ' . count($ids) . ' FAQs');
                    return ['updated' => $n, 'message' => 'FAQ order saved.'];
                case 'home_on':
                case 'home_off':
                case 'contact_on':
                case 'contact_off':
                    $col = str_starts_with($action, 'home') ? 'show_on_home' : 'show_on_contact';
                    $val = str_ends_with($action, '_on') ? 1 : 0;
                    $n = db_exec("UPDATE faqs SET $col = ? WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')', [$val]);
                    $where = $col === 'show_on_home' ? 'home page' : 'contact page';
                    log_activity('update', 'cms', implode(',', array_slice($ids, 0, 20)), ($val ? 'Showed ' : 'Hid ') . count($ids) . ' FAQ(s) on the ' . $where);
                    return ['updated' => $n, 'message' => count($ids) . ' FAQ' . (count($ids) === 1 ? '' : 's') . ($val ? ' shown on the ' : ' removed from the ') . $where . '.'];
            }
            throw new CrudException('Unknown bulk action.');
        },
        // Overall counts (independent of the current filters) for the KPI row and category navigation.
        'summary' => function (string $fromSql, array $args): array {
            $byCat = db_pairs('SELECT category, COUNT(*) FROM faqs GROUP BY category');
            return [
                'total' => (int) array_sum($byCat),
                'active' => (int) db_value("SELECT COUNT(*) FROM faqs WHERE status = 'active'"),
                'home' => (int) db_value("SELECT COUNT(*) FROM faqs WHERE show_on_home = 1 AND status = 'active'"),
                'contact' => (int) db_value("SELECT COUNT(*) FROM faqs WHERE show_on_contact = 1 AND status = 'active'"),
                'by_category' => array_map('intval', $byCat),
            ];
        },
        'describe' => fn (array $row): string => '"' . str_limit((string) ($row['question'] ?? ''), 80) . '"',
    ],
];
