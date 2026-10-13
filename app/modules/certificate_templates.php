<?php
/**
 * Certificate templates (Certificates unit). Rich-text body with {{placeholders}} and {{#optional}} … {{/optional}}
 * blocks, A4 design options and signatories. The SPA edits them in a full-page editor with live preview
 * (POST /api/certificates/templates/preview); saving goes through the generic CRUD endpoints.
 */
require_once APP_ROOT . '/app/services/certificates.php';

$statusOptions = ['active' => 'Active', 'inactive' => 'Inactive'];

return [
    'table' => 'certificate_templates',
    'title' => 'Certificate Templates',
    'singular' => 'Template',
    'permission' => 'certificates',
    'icon' => 'layout-template',
    'description' => 'Dynamic certificate designs with placeholders filled from student, program and result data.',
    'select' => "t.*, COALESCE(u.name, cu.name) AS updated_by_name,
                 (SELECT COUNT(*) FROM certificates c WHERE c.template_id = t.id) AS issued_count,
                 (SELECT COUNT(*) FROM certificates c WHERE c.template_id = t.id AND c.status = 'pending') AS pending_count,
                 (SELECT MAX(c.issue_date) FROM certificates c WHERE c.template_id = t.id) AS last_used",
    'joins' => 'LEFT JOIN users u ON u.id = t.updated_by LEFT JOIN users cu ON cu.id = t.created_by',
    'search' => ['t.name', 't.title', 't.description', 't.signatory_name'],
    'order' => "FIELD(t.type, 'bonafide', 'transfer', 'character', 'course_completion', 'internship', 'migration', 'provisional', 'custom'), t.is_default DESC, t.name",
    'search_placeholder' => 'Search templates…',
    'columns' => [
        ['key' => 'name', 'label' => 'Template', 'format' => 'title', 'sub' => 'description', 'sortable' => true, 'link' => 'view'],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true],
        ['key' => 'title', 'label' => 'Heading', 'format' => 'text', 'sortable' => true, 'hidden' => true],
        ['key' => 'orientation', 'label' => 'Layout', 'format' => 'text', 'sortable' => true, 'hidden' => true],
        ['key' => 'border_style', 'label' => 'Border', 'format' => 'text', 'sortable' => true, 'hidden' => true],
        ['key' => 'signatory_name', 'label' => 'Signatory', 'format' => 'text', 'sub' => 'signatory_designation', 'sortable' => true],
        ['key' => 'validity_days', 'label' => 'Validity (days)', 'format' => 'number', 'sortable' => true, 'hidden' => true],
        ['key' => 'is_default', 'label' => 'Default', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'issued_count', 'label' => 'Issued', 'format' => 'number', 'sortable' => true],
        ['key' => 'last_used', 'label' => 'Last used', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'updated_at', 'label' => 'Updated', 'format' => 'datetime', 'sortable' => 'COALESCE(t.updated_at, t.created_at)', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => cert_type_options()],
        ['key' => 'status', 'label' => 'Status', 'options' => $statusOptions],
        ['key' => 'orientation', 'label' => 'Layout', 'options' => ['portrait' => 'Portrait', 'landscape' => 'Landscape']],
        ['key' => 'border_style', 'label' => 'Border', 'options' => cert_border_styles()],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Template'],
        ['name' => 'name', 'label' => 'Template name', 'type' => 'text', 'required' => true, 'unique' => true, 'maxlength' => 150, 'col' => 6, 'placeholder' => 'e.g. Bonafide Certificate — Standard'],
        ['name' => 'type', 'label' => 'Certificate type', 'type' => 'select', 'required' => true, 'options' => cert_type_options(), 'col' => 6],
        ['name' => 'description', 'label' => 'Internal description', 'type' => 'text', 'maxlength' => 255, 'col' => 12, 'placeholder' => 'Where this design is used (not printed)'],
        ['type' => 'section', 'label' => 'Content'],
        ['name' => 'title', 'label' => 'Heading', 'type' => 'text', 'required' => true, 'maxlength' => 190, 'col' => 6, 'placeholder' => 'Bonafide Certificate'],
        ['name' => 'subtitle', 'label' => 'Sub-heading', 'type' => 'text', 'maxlength' => 190, 'col' => 6, 'placeholder' => 'To Whomsoever It May Concern'],
        ['name' => 'body_html', 'label' => 'Body', 'type' => 'richtext', 'required' => true, 'col' => 12,
            'help' => 'Use {{placeholders}} such as {{student_name}}, {{program}}, {{issue_date}}. Wrap optional text in {{#key}} … {{/key}}.'],
        ['type' => 'section', 'label' => 'Design'],
        ['name' => 'orientation', 'label' => 'Page layout', 'type' => 'select', 'required' => true, 'default' => 'portrait', 'options' => ['portrait' => 'A4 Portrait', 'landscape' => 'A4 Landscape'], 'col' => 4],
        ['name' => 'border_style', 'label' => 'Border style', 'type' => 'select', 'required' => true, 'default' => 'classic', 'options' => cert_border_styles(), 'col' => 4],
        ['name' => 'font_style', 'label' => 'Body font', 'type' => 'select', 'required' => true, 'default' => 'serif', 'options' => cert_font_styles(), 'col' => 4],
        ['name' => 'accent_color', 'label' => 'Accent colour', 'type' => 'color', 'required' => true, 'default' => '#0B2A5B', 'col' => 4],
        ['name' => 'validity_days', 'label' => 'Default validity (days)', 'type' => 'number', 'min' => 1, 'max' => 3650, 'col' => 4, 'help' => 'Blank = no expiry.'],
        ['name' => 'show_photo', 'label' => 'Show student photo', 'type' => 'toggle', 'default' => false, 'col' => 4],
        ['name' => 'show_seal', 'label' => 'Show institute seal', 'type' => 'toggle', 'default' => true, 'col' => 4],
        ['name' => 'show_qr', 'label' => 'Show verification QR code', 'type' => 'toggle', 'default' => true, 'col' => 4],
        ['type' => 'section', 'label' => 'Signatories'],
        ['name' => 'signatory_name', 'label' => 'Signatory name', 'type' => 'text', 'maxlength' => 150, 'col' => 6, 'placeholder' => 'Dr. Anil Kumar Verma'],
        ['name' => 'signatory_designation', 'label' => 'Signatory designation', 'type' => 'text', 'maxlength' => 150, 'col' => 6, 'placeholder' => 'Registrar'],
        ['name' => 'signatory2_name', 'label' => 'Second signatory name', 'type' => 'text', 'maxlength' => 150, 'col' => 6],
        ['name' => 'signatory2_designation', 'label' => 'Second signatory designation', 'type' => 'text', 'maxlength' => 150, 'col' => 6],
        ['type' => 'section', 'label' => 'Status'],
        ['name' => 'is_default', 'label' => 'Default template for this type', 'type' => 'toggle', 'default' => false, 'col' => 6],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => $statusOptions, 'col' => 6],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'export' => true,
    'import' => false,
    'form' => ['size' => '2xl', 'mode' => 'page'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $merged = array_merge($old ?? [], $data);
            if (array_key_exists('accent_color', $data) && $data['accent_color'] !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', (string) $data['accent_color'])) {
                $errors['accent_color'] = 'Accent colour must be a hex colour such as #0B2A5B.';
            }
            if (array_key_exists('body_html', $data)) {
                $text = trim(html_entity_decode(strip_tags((string) $data['body_html']), ENT_QUOTES, 'UTF-8'));
                if (mb_strlen($text) < 20) {
                    $errors['body_html'] = 'Write the certificate text (at least a sentence).';
                } elseif (mb_strlen((string) $data['body_html']) > 60000) {
                    $errors['body_html'] = 'The body is too long for one A4 page.';
                } elseif ($p = cert_template_problems((string) $data['body_html'])) {
                    $errors['body_html'] = implode(' ', $p);
                }
            }
            foreach (['title', 'subtitle'] as $f) {
                if (array_key_exists($f, $data) && $data[$f] !== null && ($p = cert_template_problems((string) $data[$f]))) {
                    $errors[$f] = implode(' ', $p);
                }
            }
            if (!empty($merged['is_default']) && ($merged['status'] ?? 'active') !== 'active') {
                $errors['is_default'] = 'An inactive template cannot be the default. Activate it or turn off "Default".';
            }
            if ($id && $old && ($data['status'] ?? $old['status']) === 'inactive' && $old['status'] === 'active') {
                $pending = (int) db_value("SELECT COUNT(*) FROM certificates WHERE template_id = ? AND status = 'pending'", [$id]);
                if ($pending) {
                    $errors['status'] = $pending . ' pending certificate request' . ($pending > 1 ? 's use' : ' uses') . ' this template. Approve or reject them first.';
                }
            }
            return $errors;
        },
        'before_save' => function (array $data): array {
            if (isset($data['accent_color'])) {
                $data['accent_color'] = strtoupper((string) $data['accent_color']);
            }
            if (array_key_exists('validity_days', $data) && ($data['validity_days'] === 0 || $data['validity_days'] === '')) {
                $data['validity_days'] = null;
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            $row = db_row('SELECT id, type, is_default, status FROM certificate_templates WHERE id = ?', [$id]);
            if (!$row) {
                return;
            }
            if ((int) $row['is_default'] === 1) {
                db_exec('UPDATE certificate_templates SET is_default = 0 WHERE type = ? AND id <> ? AND is_default = 1', [$row['type'], $id]);
            } elseif ($row['status'] === 'active' && !db_value("SELECT COUNT(*) FROM certificate_templates WHERE type = ? AND is_default = 1 AND status = 'active'", [$row['type']])) {
                // Every type keeps exactly one default while it has active templates.
                db_exec('UPDATE certificate_templates SET is_default = 1 WHERE id = ?', [$id]);
            }
            if ($old && (int) $old['is_default'] === 1 && ($row['status'] !== 'active' || $row['type'] !== $old['type'])) {
                $next = db_value("SELECT id FROM certificate_templates WHERE type = ? AND status = 'active' AND id <> ? ORDER BY id LIMIT 1", [$old['type'], $id]);
                if ($next && !db_value("SELECT COUNT(*) FROM certificate_templates WHERE type = ? AND is_default = 1 AND status = 'active'", [$old['type']])) {
                    db_exec('UPDATE certificate_templates SET is_default = 1 WHERE id = ?', [(int) $next]);
                }
            }
        },
        'after_bulk' => function (array $ids, string $field, $value): void {
            if ($field === 'status' && $value === 'inactive') {
                db_exec('UPDATE certificate_templates SET is_default = 0 WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
                foreach (db_column("SELECT DISTINCT type FROM certificate_templates WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')') as $type) {
                    if (!db_value("SELECT COUNT(*) FROM certificate_templates WHERE type = ? AND is_default = 1 AND status = 'active'", [$type])) {
                        $next = db_value("SELECT id FROM certificate_templates WHERE type = ? AND status = 'active' ORDER BY id LIMIT 1", [$type]);
                        if ($next) {
                            db_exec('UPDATE certificate_templates SET is_default = 1 WHERE id = ?', [(int) $next]);
                        }
                    }
                }
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            $used = (int) db_value('SELECT COUNT(*) FROM certificates WHERE template_id = ?', [$id]);
            if ($used) {
                return '"' . $row['name'] . '" was used for ' . $used . ' certificate' . ($used > 1 ? 's' : '') . ' and cannot be deleted. Mark it inactive instead.';
            }
            return null;
        },
        'after_delete' => function (int $id, array $row): void {
            if ((int) $row['is_default'] === 1) {
                $next = db_value("SELECT id FROM certificate_templates WHERE type = ? AND status = 'active' ORDER BY id LIMIT 1", [$row['type']]);
                if ($next) {
                    db_exec('UPDATE certificate_templates SET is_default = 1 WHERE id = ?', [(int) $next]);
                }
            }
        },
        'transform_row' => function (array $row): array {
            $row['type_label'] = cert_type_label($row['type'] ?? '');
            $row['is_default'] = (bool) ($row['is_default'] ?? false);
            foreach (['show_photo', 'show_seal', 'show_qr'] as $k) {
                if (array_key_exists($k, $row)) {
                    $row[$k] = (bool) $row[$k];
                }
            }
            foreach (['issued_count', 'pending_count'] as $k) {
                if (array_key_exists($k, $row)) {
                    $row[$k] = (int) $row[$k];
                }
            }
            return $row;
        },
        'describe' => fn (array $row): string => '"' . ($row['name'] ?? '#' . ($row['id'] ?? '')) . '" (' . cert_type_label((string) ($row['type'] ?? '')) . ')',
    ],
];
