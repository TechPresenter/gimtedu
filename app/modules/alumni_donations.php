<?php
/** Alumni donations (brief §23): donor, amount, purpose / campaign, mode, reference, receipt no. */
require_once APP_ROOT . '/app/services/alumni.php';

return [
    'table' => 'alumni_donations',
    'title' => 'Donations',
    'singular' => 'Donation',
    'permission' => 'alumni',
    'icon' => 'hand-heart',
    'description' => 'Contributions from alumni to scholarships, infrastructure and other funds.',
    'select' => "t.*, a.name AS donor_name, a.photo AS donor_photo, a.email AS donor_email, a.batch_year,
                 CONCAT('Batch ', a.batch_year, IFNULL(CONCAT(' · ', NULLIF(a.company, '')), '')) AS donor_sub",
    'joins' => 'JOIN alumni a ON a.id = t.alumni_id',
    'search' => ['t.receipt_no', 't.reference_no', 't.purpose', 't.campaign', 'a.name', 'a.email'],
    'search_placeholder' => 'Search receipt no, donor, purpose, reference…',
    'order' => 't.donation_date DESC, t.id DESC',
    'columns' => [
        ['key' => 'receipt_no', 'label' => 'Receipt / date', 'format' => 'code', 'sortable' => true],
        ['key' => 'donor_name', 'label' => 'Donor', 'format' => 'person', 'image' => 'donor_photo', 'sub' => 'donor_sub', 'link' => '/alumni/{alumni_id}', 'sortable' => 'a.name'],
        ['key' => 'amount', 'label' => 'Amount', 'format' => 'money', 'sortable' => true],
        ['key' => 'purpose', 'label' => 'Purpose', 'format' => 'title', 'sub' => 'campaign', 'sortable' => true],
        ['key' => 'campaign', 'label' => 'Campaign', 'hidden' => true],
        ['key' => 'donation_date', 'label' => 'Date', 'format' => 'date', 'hidden' => true, 'sortable' => true],
        ['key' => 'payment_mode', 'label' => 'Mode', 'format' => 'badge', 'hidden' => true, 'sortable' => true,
            'colors' => ['upi' => 'cyan', 'neft' => 'navy', 'cheque' => 'purple', 'card' => 'blue', 'online' => 'green', 'cash' => 'amber']],
        ['key' => 'reference_no', 'label' => 'Reference', 'hidden' => true],
        ['key' => 'pan_no', 'label' => 'PAN', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => alumni_donation_statuses()],
        ['key' => 'fy', 'label' => 'Financial year', 'sql_map' => alumni_fy_filter_map(), 'options' => alumni_fy_filter_options()],
        ['key' => 'purpose', 'label' => 'Purpose', 'options' => alumni_donation_purposes()],
        ['key' => 'payment_mode', 'label' => 'Mode', 'options' => alumni_payment_modes()],
        ['key' => 'alumni_id', 'label' => 'Donor', 'source' => alumni_lookup_source()],
        ['key' => 'donation_date', 'label' => 'Date', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'alumni_id', 'label' => 'Donor (alumni)', 'type' => 'combobox', 'required' => true, 'source' => alumni_lookup_source(), 'col' => 12, 'placeholder' => 'Search alumni…'],
        ['name' => 'amount', 'label' => 'Amount', 'type' => 'money', 'required' => true, 'col' => 4, 'min' => 1, 'max' => 100000000, 'prefix' => '₹'],
        ['name' => 'donation_date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'col' => 4, 'default' => date('Y-m-d')],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'received', 'col' => 4, 'options' => alumni_donation_statuses(),
            'help' => 'Pledged = promised, not yet paid.'],
        ['name' => 'purpose', 'label' => 'Purpose / fund', 'type' => 'select', 'required' => true, 'col' => 6, 'options' => alumni_donation_purposes()],
        ['name' => 'campaign', 'label' => 'Campaign', 'type' => 'text', 'col' => 6, 'maxlength' => 150, 'placeholder' => 'e.g. Homecoming 2026 Scholarship Drive'],
        ['name' => 'payment_mode', 'label' => 'Payment mode', 'type' => 'select', 'required' => true, 'default' => 'upi', 'col' => 4, 'options' => alumni_payment_modes()],
        ['name' => 'reference_no', 'label' => 'Reference / UTR / cheque no.', 'type' => 'text', 'col' => 4, 'maxlength' => 100],
        ['name' => 'pan_no', 'label' => 'Donor PAN (for 80G)', 'type' => 'text', 'col' => 4, 'maxlength' => 10, 'placeholder' => 'ABCDE1234F'],
        ['name' => 'is_anonymous', 'label' => 'Anonymous donation', 'type' => 'toggle', 'col' => 4, 'default' => false, 'help' => 'Hidden from donor rankings and public lists.'],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255, 'col' => 8],
    ],
    'bulk' => ['delete' => true],
    'export' => true,
    'import' => true,
    'form' => ['size' => 'lg'],
    'scopes' => ['alumni_id' => 't.alumni_id'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $status = $data['status'] ?? ($old['status'] ?? 'received');
            $mode = $data['payment_mode'] ?? ($old['payment_mode'] ?? null);
            $ref = array_key_exists('reference_no', $data) ? $data['reference_no'] : ($old['reference_no'] ?? null);
            $date = $data['donation_date'] ?? ($old['donation_date'] ?? null);
            if ($status === 'received' && $mode && $mode !== 'cash' && !$ref) {
                $errors['reference_no'] = 'Reference / transaction no. is required for ' . (alumni_payment_modes()[$mode] ?? $mode) . ' payments.';
            }
            if ($status === 'received' && $date && $date > date('Y-m-d')) {
                $errors['donation_date'] = 'A received donation cannot be dated in the future. Record it as pledged instead.';
            }
            if (!empty($data['pan_no']) && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', strtoupper((string) $data['pan_no']))) {
                $errors['pan_no'] = 'Enter a valid 10-character PAN, e.g. ABCDE1234F.';
            }
            if ($mode === 'cash' && $status === 'received' && (float) ($data['amount'] ?? ($old['amount'] ?? 0)) > 199999) {
                $errors['payment_mode'] = 'Cash donations of ₹2,00,000 or more are not permitted. Use a banking channel.';
            }
            if ($id && ($old['status'] ?? '') === 'cancelled' && isset($data['status']) && $data['status'] !== 'cancelled') {
                $errors['status'] = 'A cancelled donation cannot be re-activated. Record a new donation instead.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id): array {
            if ($id === null) {
                $data['receipt_no'] = next_number('alumni_donation', 'DON-{Y}-{n:5}');
            }
            if (!empty($data['pan_no'])) {
                $data['pan_no'] = strtoupper((string) $data['pan_no']);
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            return $row['status'] === 'received'
                ? 'Donation ' . $row['receipt_no'] . ' has been received and receipted. Cancel it with a reason instead of deleting it.'
                : null;
        },
        'summary' => function (string $from, array $args): array {
            $r = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN t.status = 'received' THEN t.amount END), 0) AS received,
                                COALESCE(SUM(CASE WHEN t.status = 'pledged' THEN t.amount END), 0) AS pledged,
                                COUNT(DISTINCT CASE WHEN t.status = 'received' THEN t.alumni_id END) AS donors" . $from, $args) ?? [];
            return ['n' => (int) $r['n'], 'received' => (float) $r['received'], 'pledged' => (float) $r['pledged'], 'donors' => (int) $r['donors']];
        },
        'describe' => fn (array $row) => ($row['receipt_no'] ?? '') . ' of ' . money($row['amount'] ?? 0),
    ],
];
