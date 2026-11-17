<?php
/**
 * SEO Management › AI visibility: manual log of what AI search engines answered for a query on a date (mentioned?
 * cited? accurate?). Entries are typed in by people after running the query themselves — nothing queries an AI
 * engine automatically. Stats (mention rate per engine per month) and the standard queries: /api/seo-ai/ai-tests/*.
 */
require_once APP_ROOT . '/app/services/seo_presence.php';

$url = ['max:500', 'regex:/^(https?:\/\/[^\s"\'<>]+|\/[^\s"\'<>]*)$/i'];

return [
    'table' => 'seo_ai_tests',
    'title' => 'AI visibility tests',
    'singular' => 'Test result',
    'permission' => 'seo',
    'permissions' => ['create' => ['seo', 'edit'], 'edit' => ['seo', 'edit'], 'delete' => ['seo', 'edit'], 'export' => ['seo', 'view'], 'import' => ['seo', 'edit']],
    'icon' => 'bot',
    'description' => 'What ChatGPT, Perplexity, Gemini, Copilot, Claude and Google AI answered for the standard queries — mentions, citations and accuracy.',
    'select' => 't.*, k.keyword AS keyword_name, u.name AS tested_by_name',
    'joins' => 'LEFT JOIN seo_keywords k ON k.id = t.keyword_id LEFT JOIN users u ON u.id = t.tested_by',
    'search' => ['t.query_text', 't.cited_url', 't.competitors', 't.answer_excerpt', 't.incorrect_info'],
    'search_placeholder' => 'Search queries, cited URLs, competitors…',
    'order' => 't.tested_on DESC, t.id DESC',
    'default_sort' => ['key' => 'tested_on', 'dir' => 'desc'],
    'columns' => [
        ['key' => 'query_text', 'label' => 'Query', 'format' => 'title', 'sub' => 'keyword_name', 'sortable' => true],
        ['key' => 'engine', 'label' => 'Engine', 'format' => 'badge', 'sortable' => true,
            'colors' => ['chatgpt' => 'green', 'perplexity' => 'cyan', 'gemini' => 'blue', 'copilot' => 'purple', 'claude' => 'amber', 'google_ai' => 'navy', 'bing' => 'blue', 'other' => 'slate']],
        ['key' => 'tested_on', 'label' => 'Tested', 'format' => 'date', 'sortable' => true],
        ['key' => 'mentioned', 'label' => 'Mentioned', 'format' => 'boolean', 'align' => 'center', 'sortable' => true],
        ['key' => 'cited', 'label' => 'Cited', 'format' => 'boolean', 'align' => 'center', 'sortable' => true],
        ['key' => 'cited_url', 'label' => 'Cited URL', 'format' => 'link', 'hidden' => true],
        ['key' => 'accuracy', 'label' => 'Accuracy', 'format' => 'badge', 'sortable' => true,
            'colors' => ['accurate' => 'green', 'partly' => 'amber', 'inaccurate' => 'red', 'not_applicable' => 'slate']],
        ['key' => 'competitors', 'label' => 'Competitors named', 'format' => 'truncate', 'hidden' => true],
        ['key' => 'incorrect_info', 'label' => 'Incorrect info', 'format' => 'truncate', 'hidden' => true],
        ['key' => 'corrective_action', 'label' => 'Corrective action', 'format' => 'truncate', 'hidden' => true],
        ['key' => 'answer_excerpt', 'label' => 'Answer excerpt', 'format' => 'truncate', 'hidden' => true],
        ['key' => 'screenshot', 'label' => 'Screenshot', 'format' => 'image', 'hidden' => true],
        ['key' => 'tested_by_name', 'label' => 'Tested by', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'engine', 'label' => 'Engine', 'options' => seo_ai_engines()],
        ['key' => 'tested_on', 'label' => 'Tested', 'type' => 'daterange'],
        ['key' => 'mentioned', 'label' => 'Mentioned', 'options' => ['1' => 'Mentioned', '0' => 'Not mentioned']],
        ['key' => 'cited', 'label' => 'Cited', 'options' => ['1' => 'Cited', '0' => 'Not cited']],
        ['key' => 'accuracy', 'label' => 'Accuracy', 'options' => seo_ai_accuracy_options()],
    ],
    'fields' => [
        ['name' => 'engine', 'label' => 'AI engine', 'type' => 'select', 'required' => true, 'default' => 'chatgpt', 'options' => seo_ai_engines(), 'col' => 4],
        ['name' => 'tested_on', 'label' => 'Tested on', 'type' => 'date', 'required' => true, 'default' => date('Y-m-d'), 'col' => 4],
        ['name' => 'keyword_id', 'label' => 'Keyword (optional)', 'type' => 'combobox', 'col' => 4,
            'source' => ['table' => 'seo_keywords kw', 'value' => 'kw.id', 'label' => 'kw.keyword', 'sub' => 'kw.category', 'search' => ['kw.keyword'], 'order' => 'kw.keyword', 'async' => true]],
        ['name' => 'query_text', 'label' => 'Query typed', 'type' => 'textarea', 'rows' => 2, 'required' => true, 'maxlength' => 500],
        ['name' => 'mentioned', 'label' => 'The institute was mentioned', 'type' => 'toggle', 'default' => 0, 'col' => 6],
        ['name' => 'cited', 'label' => 'The website was cited / linked', 'type' => 'toggle', 'default' => 0, 'col' => 6],
        ['name' => 'cited_url', 'label' => 'Cited URL', 'type' => 'text', 'maxlength' => 500, 'col' => 6, 'rules' => $url, 'placeholder' => 'https://…'],
        ['name' => 'accuracy', 'label' => 'Accuracy of what it said', 'type' => 'select', 'options' => seo_ai_accuracy_options(), 'col' => 6],
        ['name' => 'competitors', 'label' => 'Competitors named instead / as well', 'type' => 'text', 'maxlength' => 500],
        ['name' => 'incorrect_info', 'label' => 'Incorrect information', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 1000],
        ['name' => 'corrective_action', 'label' => 'Corrective action', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 500,
            'placeholder' => 'e.g. add the fee table to /admissions, update the Google Business Profile …'],
        ['name' => 'answer_excerpt', 'label' => 'Answer excerpt', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 1000, 'help' => 'Copy the relevant sentences (up to 1,000 characters).'],
        ['name' => 'screenshot', 'label' => 'Screenshot', 'type' => 'image', 'folder' => 'seo/ai-tests'],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            foreach (['query_text', 'competitors', 'incorrect_info', 'corrective_action', 'answer_excerpt'] as $k) {
                if (isset($data[$k]) && is_string($data[$k]) && preg_match('/<\s*\/?\s*script|javascript\s*:/i', $data[$k])) {
                    $errors[$k] = 'Scripts are not allowed. Paste plain text only.';
                }
            }
            $d = $data['tested_on'] ?? $old['tested_on'] ?? null;
            if ($d && strtotime((string) $d) > strtotime('tomorrow')) {
                $errors['tested_on'] = 'The test date cannot be in the future.';
            }
            if (!empty($data['cited']) && empty($data['mentioned'])) {
                $data['mentioned'] = 1; // a citation implies a mention (fixed in before_save)
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            foreach (['query_text', 'competitors', 'incorrect_info', 'corrective_action', 'answer_excerpt'] as $k) {
                if (isset($data[$k]) && is_string($data[$k])) {
                    $data[$k] = trim(strip_tags($data[$k])) ?: null; // stored and shown as plain text
                }
            }
            if (!empty($data['cited'])) {
                $data['mentioned'] = 1;
            }
            if (array_key_exists('mentioned', $data) && empty($data['mentioned']) && empty($data['accuracy'])) {
                $data['accuracy'] = 'not_applicable';
            }
            if ($id === null) {
                $data['tested_by'] = user_id();
            }
            return $data;
        },
        'describe' => fn (array $row): string => (seo_ai_engines()[$row['engine'] ?? ''] ?? 'AI') . ': ' . mb_substr((string) ($row['query_text'] ?? ''), 0, 80),
    ],
];
