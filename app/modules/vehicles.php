<?php
/** Transport › Vehicles (fleet register with document expiries, crew and route). */
require_once APP_ROOT . '/app/services/transport.php';

$alertDays = transport_alert_days();
$expiryFilter = function (string $col) use ($alertDays): array {
    return [
        'expired' => "t.$col IS NOT NULL AND t.$col < CURDATE()",
        'due' => "t.$col IS NOT NULL AND t.$col BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $alertDays DAY)",
        'valid' => "t.$col > DATE_ADD(CURDATE(), INTERVAL $alertDays DAY)",
        'missing' => "t.$col IS NULL",
    ];
};
$next = transport_next_expiry_sql('t');

return [
    'table' => 'vehicles',
    'title' => 'Vehicles',
    'singular' => 'Vehicle',
    'permission' => 'transport',
    'icon' => 'bus',
    'description' => 'College buses, vans and cars with crew, route, capacity and statutory document expiries.',
    'select' => "t.*, r.code AS route_code, r.name AS route_name, d.name AS driver_name, d.phone AS driver_phone, d.photo AS driver_photo,
                 c.name AS conductor_name, c.phone AS conductor_phone,
                 (SELECT COUNT(*) FROM transport_allocations a WHERE a.vehicle_id = t.id AND a.status = 'active') AS allocated,
                 $next AS next_expiry,
                 (SELECT MAX(f.fill_date) FROM transport_fuel f WHERE f.vehicle_id = t.id) AS last_fuel_date,
                 CONCAT_WS(' ', t.make, t.model) AS make_model",
    'joins' => 'LEFT JOIN transport_routes r ON r.id = t.route_id LEFT JOIN drivers d ON d.id = t.driver_id LEFT JOIN drivers c ON c.id = t.conductor_id',
    'search' => ['t.vehicle_no', "REPLACE(t.vehicle_no, ' ', '')", 't.make', 't.model', 'r.code', 'r.name', 'd.name', 'c.name', 't.gps_device_id'],
    'search_placeholder' => 'Search vehicle no, model, route or driver…',
    'order' => "t.status = 'inactive', r.code IS NULL, r.code, t.vehicle_no",
    'columns' => [
        ['key' => 'vehicle_no', 'label' => 'Vehicle', 'format' => 'title', 'sub' => 'make_model', 'sortable' => true, 'link' => 'view'],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'hidden' => true, 'colors' => ['bus' => 'navy', 'mini_bus' => 'blue', 'van' => 'cyan', 'car' => 'purple', 'other' => 'slate']],
        ['key' => 'route_code', 'label' => 'Route', 'format' => 'title', 'sub' => 'route_name', 'sortable' => 'r.code'],
        ['key' => 'driver_name', 'label' => 'Driver', 'format' => 'person', 'image' => 'driver_photo', 'sub' => 'driver_phone', 'sortable' => 'd.name'],
        ['key' => 'conductor_name', 'label' => 'Conductor', 'format' => 'text', 'hidden' => true, 'sortable' => 'c.name'],
        ['key' => 'capacity', 'label' => 'Seats', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'allocated', 'label' => 'Riders', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'next_expiry', 'label' => 'Documents', 'format' => 'date', 'sortable' => 'next_expiry'],
        ['key' => 'insurance_expiry', 'label' => 'Insurance', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'fitness_expiry', 'label' => 'Fitness', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'permit_expiry', 'label' => 'Permit', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'pollution_expiry', 'label' => 'PUC', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'fuel_type', 'label' => 'Fuel', 'format' => 'badge', 'hidden' => true, 'colors' => ['diesel' => 'slate', 'cng' => 'green', 'petrol' => 'amber', 'electric' => 'cyan']],
        ['key' => 'current_odometer', 'label' => 'Odometer (km)', 'format' => 'number', 'hidden' => true, 'sortable' => true],
        ['key' => 'manufacture_year', 'label' => 'Year', 'format' => 'number', 'hidden' => true, 'sortable' => true],
        ['key' => 'gps_device_id', 'label' => 'GPS device', 'format' => 'code', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => transport_vehicle_statuses()],
        ['key' => 'type', 'label' => 'Type', 'options' => transport_vehicle_types()],
        ['key' => 'route_id', 'label' => 'Route', 'source' => transport_route_source(false)],
        ['key' => 'fuel_type', 'label' => 'Fuel', 'options' => transport_fuel_types()],
        ['key' => 'documents', 'label' => 'Documents', 'sql_map' => [
            'expired' => "$next < CURDATE()",
            'due' => "$next BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $alertDays DAY)",
            'valid' => "$next > DATE_ADD(CURDATE(), INTERVAL $alertDays DAY)",
        ], 'options' => ['expired' => 'Any document expired', 'due' => "Expiring within $alertDays days", 'valid' => 'All valid']],
        ['key' => 'insurance', 'label' => 'Insurance', 'sql_map' => $expiryFilter('insurance_expiry'), 'options' => ['expired' => 'Expired', 'due' => 'Due soon', 'valid' => 'Valid', 'missing' => 'Not recorded']],
        ['key' => 'assignment', 'label' => 'Assignment', 'sql_map' => ['unassigned' => 't.route_id IS NULL', 'no_driver' => 't.driver_id IS NULL', 'assigned' => 't.route_id IS NOT NULL AND t.driver_id IS NOT NULL'],
            'options' => ['assigned' => 'Route & driver assigned', 'unassigned' => 'No route (standby)', 'no_driver' => 'No driver']],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Vehicle'],
        ['name' => 'vehicle_no', 'label' => 'Registration no.', 'type' => 'text', 'required' => true, 'col' => 4, 'maxlength' => 20, 'placeholder' => 'UP16 AT 4521', 'help' => 'As on the RC, e.g. UP16 AT 4521'],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'bus', 'col' => 4, 'options' => transport_vehicle_types()],
        ['name' => 'capacity', 'label' => 'Seating capacity', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 100, 'default' => 52, 'col' => 4],
        ['name' => 'make', 'label' => 'Make', 'type' => 'text', 'col' => 4, 'maxlength' => 60, 'placeholder' => 'Tata Motors'],
        ['name' => 'model', 'label' => 'Model', 'type' => 'text', 'col' => 4, 'maxlength' => 60, 'placeholder' => 'Starbus Ultra 52'],
        ['name' => 'manufacture_year', 'label' => 'Manufacture year', 'type' => 'number', 'min' => 1990, 'max' => (int) date('Y') + 1, 'col' => 4],
        ['name' => 'fuel_type', 'label' => 'Fuel', 'type' => 'select', 'default' => 'diesel', 'col' => 4, 'options' => transport_fuel_types()],
        ['name' => 'registration_date', 'label' => 'Registration date', 'type' => 'date', 'col' => 4],
        ['name' => 'current_odometer', 'label' => 'Odometer (km)', 'type' => 'number', 'min' => 0, 'max' => 5000000, 'col' => 4, 'help' => 'Updated automatically from fuel and service entries'],
        ['type' => 'section', 'label' => 'Route & crew'],
        ['name' => 'route_id', 'label' => 'Route', 'type' => 'select', 'col' => 4, 'source' => transport_route_source(false), 'placeholder' => 'Standby (no route)'],
        ['name' => 'driver_id', 'label' => 'Driver', 'type' => 'combobox', 'col' => 4, 'source' => ['table' => 'drivers dr', 'value' => 'dr.id', 'label' => 'dr.name', 'sub' => "CONCAT(dr.phone, ' · ', COALESCE(dr.license_type, ''))",
            'where' => "dr.status = 'active' AND dr.type = 'driver'", 'order' => 'dr.name', 'search' => ['dr.name', 'dr.phone', 'dr.license_no']]],
        ['name' => 'conductor_id', 'label' => 'Conductor / attendant', 'type' => 'combobox', 'col' => 4, 'source' => ['table' => 'drivers dr', 'value' => 'dr.id', 'label' => 'dr.name', 'sub' => 'dr.phone',
            'where' => "dr.status = 'active' AND dr.type IN ('conductor', 'helper')", 'order' => 'dr.name', 'search' => ['dr.name', 'dr.phone']]],
        ['type' => 'section', 'label' => 'Documents', 'help' => "Expiries within $alertDays days are flagged on the dashboard."],
        ['name' => 'insurance_expiry', 'label' => 'Insurance expiry', 'type' => 'date', 'col' => 3],
        ['name' => 'fitness_expiry', 'label' => 'Fitness expiry', 'type' => 'date', 'col' => 3],
        ['name' => 'permit_expiry', 'label' => 'Permit expiry', 'type' => 'date', 'col' => 3],
        ['name' => 'pollution_expiry', 'label' => 'PUC expiry', 'type' => 'date', 'col' => 3],
        ['type' => 'section', 'label' => 'Tracking & status'],
        ['name' => 'gps_device_id', 'label' => 'GPS device ID', 'type' => 'text', 'col' => 4, 'maxlength' => 60, 'placeholder' => 'IMEI / tracker ID', 'help' => 'For the future live-tracking integration'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'col' => 4, 'options' => transport_vehicle_statuses()],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'col' => 4, 'maxlength' => 255],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'maintenance']],
    'import' => true,
    'form' => ['size' => 'xl'],
    'hooks' => [
        'validate' => fn (array $data, ?int $id, ?array $old) => transport_validate_vehicle($data, $id, $old),
        'before_save' => function (array $data): array {
            if (!empty($data['vehicle_no'])) {
                $data['vehicle_no'] = transport_normalise_vehicle_no((string) $data['vehicle_no']);
            }
            if (!empty($data['gps_device_id'])) {
                $data['gps_device_id'] = trim((string) $data['gps_device_id']);
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            transport_after_vehicle_save($id, $old);
        },
        'before_delete' => function (int $id, array $row): ?string {
            $alloc = (int) db_value("SELECT COUNT(*) FROM transport_allocations WHERE vehicle_id = ? AND status = 'active'", [$id]);
            if ($alloc) {
                return $row['vehicle_no'] . " carries $alloc active riders. Move it off its route or mark it inactive instead.";
            }
            $history = (int) db_value('SELECT (SELECT COUNT(*) FROM transport_fuel WHERE vehicle_id = ?) + (SELECT COUNT(*) FROM transport_maintenance WHERE vehicle_id = ?)', [$id, $id]);
            if ($history) {
                return $row['vehicle_no'] . " has $history fuel / maintenance records. Mark it inactive to keep the history.";
            }
            return null;
        },
        'after_delete' => function (int $id, array $row): void {
            if ($row['route_id']) {
                transport_sync_route_vehicles((int) $row['route_id']);
            }
        },
        'after_bulk' => function (array $ids): void {
            foreach (db_column('SELECT DISTINCT route_id FROM vehicles WHERE route_id IS NOT NULL AND id IN (' . implode(',', array_map('intval', $ids)) . ')') as $r) {
                transport_sync_route_vehicles((int) $r);
            }
        },
        'transform_row' => function (array $row): array {
            foreach (['capacity', 'allocated'] as $k) {
                if (isset($row[$k])) {
                    $row[$k] = (int) $row[$k];
                }
            }
            if (array_key_exists('next_expiry', $row)) {
                $row['next_expiry_state'] = transport_expiry_state($row['next_expiry']);
                $row['next_expiry_days'] = transport_days_until($row['next_expiry']);
            }
            return $row;
        },
        'summary' => function (string $from, array $args): array {
            $alertDays = transport_alert_days();
            $next = transport_next_expiry_sql('t');
            $r = db_row("SELECT COUNT(*) AS total, SUM(t.status = 'active') AS active, SUM(t.status = 'maintenance') AS maintenance,
                                COALESCE(SUM(CASE WHEN t.status <> 'inactive' THEN t.capacity ELSE 0 END), 0) AS seats,
                                SUM(t.status <> 'inactive' AND $next < CURDATE()) AS expired,
                                SUM(t.status <> 'inactive' AND $next BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $alertDays DAY)) AS due
                         $from", $args) ?? [];
            return array_map('intval', $r) + ['alert_days' => $alertDays];
        },
        'describe' => fn (array $row) => (string) $row['vehicle_no'],
    ],
];
