<?php
/** Transport › Maintenance & document renewals. */
require_once APP_ROOT . '/app/services/transport.php';

return [
    'table' => 'transport_maintenance',
    'title' => 'Maintenance',
    'singular' => 'Maintenance record',
    'permission' => 'transport',
    'icon' => 'wrench',
    'description' => 'Services, repairs, tyres and statutory renewals with cost, vendor and next due.',
    'select' => "t.*, v.vehicle_no, v.model AS vehicle_model, v.current_odometer, r.code AS route_code, u.name AS created_by_name,
                 CONCAT_WS(' · ', v.model, r.code) AS vehicle_sub",
    'joins' => 'JOIN vehicles v ON v.id = t.vehicle_id LEFT JOIN transport_routes r ON r.id = v.route_id LEFT JOIN users u ON u.id = t.created_by',
    'search' => ['v.vehicle_no', "REPLACE(v.vehicle_no, ' ', '')", 't.vendor', 't.description', 't.bill_no', 'r.code'],
    'search_placeholder' => 'Search vehicle, vendor, bill or work done…',
    'order' => "FIELD(t.status, 'in_progress', 'scheduled', 'completed'), t.service_date DESC, t.id DESC",
    'scopes' => ['vehicle_id' => 't.vehicle_id'],
    'columns' => [
        ['key' => 'service_date', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
        ['key' => 'vehicle_no', 'label' => 'Vehicle', 'format' => 'title', 'sub' => 'vehicle_sub', 'sortable' => 'v.vehicle_no'],
        ['key' => 'type', 'label' => 'Work & vendor', 'format' => 'badge', 'sortable' => true, 'colors' => ['service' => 'blue', 'repair' => 'amber', 'tyre' => 'slate', 'battery' => 'cyan', 'insurance' => 'purple', 'fitness' => 'navy', 'permit' => 'navy', 'puc' => 'green', 'other' => 'slate']],
        ['key' => 'description', 'label' => 'Details', 'format' => 'truncate', 'truncate' => 46, 'hidden' => true],
        ['key' => 'vendor', 'label' => 'Vendor', 'format' => 'title', 'sub' => 'bill_no', 'sortable' => true, 'hidden' => true],
        ['key' => 'odometer', 'label' => 'Odometer', 'format' => 'number', 'suffix' => ' km', 'align' => 'right', 'sortable' => true, 'hidden' => true],
        ['key' => 'cost', 'label' => 'Cost', 'format' => 'money', 'sortable' => true],
        ['key' => 'next_service_date', 'label' => 'Next due', 'format' => 'date', 'sortable' => true],
        ['key' => 'next_service_km', 'label' => 'Next due (km)', 'format' => 'number', 'hidden' => true],
        ['key' => 'created_by_name', 'label' => 'Recorded by', 'format' => 'text', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['scheduled' => 'amber', 'in_progress' => 'blue', 'completed' => 'green']],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => transport_maintenance_statuses()],
        ['key' => 'type', 'label' => 'Work type', 'options' => transport_maintenance_types()],
        ['key' => 'vehicle_id', 'label' => 'Vehicle', 'source' => 'vehicles'],
        ['key' => 'service_date', 'label' => 'Date', 'type' => 'daterange'],
        ['key' => 'due', 'label' => 'Next due', 'sql_map' => [
            'overdue' => "t.next_service_date < CURDATE() AND t.status = 'completed'",
            'month' => "t.next_service_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
        ], 'options' => ['overdue' => 'Next due date passed', 'month' => 'Due in 30 days']],
    ],
    'fields' => [
        ['name' => 'vehicle_id', 'label' => 'Vehicle', 'type' => 'select', 'required' => true, 'col' => 6,
            'source' => ['table' => 'vehicles v', 'value' => 'v.id', 'label' => 'v.vehicle_no', 'sub' => "CONCAT(COALESCE(v.model, v.type), ' · ', COALESCE(v.current_odometer, 0), ' km')", 'order' => 'v.vehicle_no', 'search' => ['v.vehicle_no', 'v.model']]],
        ['name' => 'type', 'label' => 'Work type', 'type' => 'select', 'required' => true, 'default' => 'service', 'col' => 6, 'options' => transport_maintenance_types()],
        ['name' => 'service_date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'col' => 4, 'help' => 'Planned date for scheduled work'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'completed', 'col' => 4, 'options' => transport_maintenance_statuses(), 'help' => '"In workshop" marks the vehicle as under maintenance'],
        ['name' => 'cost', 'label' => 'Cost (₹)', 'type' => 'money', 'min' => 0, 'max' => 5000000, 'col' => 4, 'default' => 0],
        ['name' => 'description', 'label' => 'Work done / required', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 500, 'col' => 12],
        ['name' => 'vendor', 'label' => 'Vendor / workshop', 'type' => 'text', 'col' => 6, 'maxlength' => 150],
        ['name' => 'bill_no', 'label' => 'Bill / policy no.', 'type' => 'text', 'col' => 6, 'maxlength' => 60],
        ['name' => 'odometer', 'label' => 'Odometer (km)', 'type' => 'number', 'min' => 0, 'max' => 5000000, 'col' => 4],
        ['name' => 'next_service_date', 'label' => 'Next due / new expiry', 'type' => 'date', 'col' => 4, 'help' => 'For insurance, fitness, permit and PUC this is the renewed expiry'],
        ['name' => 'next_service_km', 'label' => 'Next due at (km)', 'type' => 'number', 'min' => 0, 'max' => 5000000, 'col' => 4],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => fn (array $data, ?int $id, ?array $old) => transport_validate_maintenance($data, $id, $old),
        'after_save' => function (int $id, array $data, ?array $old): void {
            transport_after_maintenance_save($id, $old);
        },
        'after_delete' => function (int $id, array $row): void {
            transport_sync_vehicle_workshop((int) $row['vehicle_id']);
        },
        'transform_row' => function (array $row): array {
            if (isset($row['cost'])) {
                $row['cost'] = (float) $row['cost'];
            }
            return $row;
        },
        'summary' => function (string $from, array $args): array {
            $sessionStart = current_session()['start_date'] ?? date('Y') . '-07-01';
            $r = db_row("SELECT COUNT(*) AS records, COALESCE(SUM(CASE WHEN t.status = 'completed' THEN t.cost ELSE 0 END), 0) AS cost,
                                SUM(t.status = 'scheduled') AS scheduled, SUM(t.status = 'in_progress') AS in_progress,
                                COALESCE(SUM(CASE WHEN t.status = 'completed' AND t.service_date >= ? THEN t.cost ELSE 0 END), 0) AS session_cost
                         $from", array_merge([$sessionStart], $args)) ?? [];
            return ['records' => (int) $r['records'], 'cost' => (float) $r['cost'], 'scheduled' => (int) $r['scheduled'], 'in_progress' => (int) $r['in_progress'], 'session_cost' => (float) $r['session_cost']];
        },
        'describe' => function (array $row): string {
            $v = (string) db_value('SELECT vehicle_no FROM vehicles WHERE id = ?', [(int) $row['vehicle_id']]);
            return (transport_maintenance_types()[$row['type']] ?? $row['type']) . ' of ' . $v . ' (' . format_date($row['service_date']) . ')';
        },
    ],
];
