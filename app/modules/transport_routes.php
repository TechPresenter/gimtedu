<?php
/** Transport › Routes (stops are managed in transport_stops). */
require_once APP_ROOT . '/app/services/transport.php';

$cap = transport_capacity_sql('t.id');
$load = transport_load_sql('t.id');

return [
    'table' => 'transport_routes',
    'title' => 'Routes',
    'singular' => 'Route',
    'permission' => 'transport',
    'icon' => 'route',
    'description' => 'Bus routes with timings, distance, fares, vehicles and seat occupancy.',
    'select' => "t.*, $cap AS capacity, $load AS allocated,
                 (SELECT COUNT(*) FROM transport_stops ts WHERE ts.route_id = t.id) AS stops_count,
                 (SELECT GROUP_CONCAT(v.vehicle_no ORDER BY v.id SEPARATOR ', ') FROM vehicles v WHERE v.route_id = t.id AND v.status <> 'inactive') AS vehicles,
                 (SELECT dr.name FROM vehicles v2 JOIN drivers dr ON dr.id = v2.driver_id WHERE v2.route_id = t.id AND v2.status <> 'inactive' ORDER BY v2.id LIMIT 1) AS driver_name,
                 CONCAT_WS(' → ', t.start_point, t.end_point) AS path,
                 (SELECT MIN(ts.annual_fare) FROM transport_stops ts WHERE ts.route_id = t.id) AS min_fare,
                 (SELECT MAX(ts.annual_fare) FROM transport_stops ts WHERE ts.route_id = t.id) AS max_fare",
    'search' => ['t.code', 't.name', 't.start_point', 't.end_point', "(SELECT GROUP_CONCAT(ts.name) FROM transport_stops ts WHERE ts.route_id = t.id)"],
    'search_placeholder' => 'Search route, code or stop…',
    'order' => "t.status = 'inactive', t.code",
    'columns' => [
        ['key' => 'code', 'label' => 'Route', 'format' => 'title', 'sub' => 'name', 'sortable' => true, 'link' => '/transport/routes/{id}'],
        ['key' => 'start_point', 'label' => 'Starts from', 'format' => 'text', 'sortable' => true, 'hidden' => true],
        ['key' => 'path', 'label' => 'From → To', 'format' => 'truncate', 'truncate' => 60, 'hidden' => true],
        ['key' => 'stops_count', 'label' => 'Stops', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'vehicles', 'label' => 'Vehicles', 'format' => 'title', 'sub' => 'driver_name'],
        ['key' => 'allocated', 'label' => 'Occupancy', 'format' => 'number', 'sortable' => true],
        ['key' => 'distance_km', 'label' => 'Distance', 'format' => 'number', 'suffix' => ' km', 'align' => 'right', 'sortable' => true, 'hidden' => true],
        ['key' => 'morning_departure', 'label' => 'Departs', 'format' => 'time', 'sortable' => true],
        ['key' => 'evening_departure', 'label' => 'Return', 'format' => 'time', 'hidden' => true],
        ['key' => 'annual_fare', 'label' => 'Fare / yr', 'format' => 'money', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['key' => 'occupancy', 'label' => 'Occupancy', 'sql_map' => [
            'full' => "$cap > 0 AND $load >= $cap",
            'high' => "$cap > 0 AND $load >= 0.85 * $cap AND $load < $cap",
            'available' => "$cap > 0 AND $load < 0.85 * $cap",
            'no_vehicle' => "$cap = 0",
        ], 'options' => ['full' => 'Full', 'high' => '85%+ occupied', 'available' => 'Seats available', 'no_vehicle' => 'No vehicle assigned']],
    ],
    'fields' => [
        ['name' => 'code', 'label' => 'Route code', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 20, 'rules' => 'alpha_dash', 'placeholder' => 'R01'],
        ['name' => 'name', 'label' => 'Route name', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 150, 'placeholder' => 'Noida Sector 62 – Knowledge Park'],
        ['name' => 'start_point', 'label' => 'Starts at', 'type' => 'text', 'col' => 6, 'maxlength' => 150],
        ['name' => 'end_point', 'label' => 'Ends at', 'type' => 'text', 'col' => 6, 'maxlength' => 150, 'default' => 'GIMT Campus, Knowledge Park II'],
        ['name' => 'distance_km', 'label' => 'Distance (km)', 'type' => 'decimal', 'step' => 0.1, 'min' => 0, 'max' => 500, 'col' => 4],
        ['name' => 'morning_departure', 'label' => 'Morning departure', 'type' => 'time', 'col' => 4],
        ['name' => 'evening_departure', 'label' => 'Evening departure', 'type' => 'time', 'col' => 4, 'help' => 'From campus'],
        ['name' => 'annual_fare', 'label' => 'Default annual fare (₹)', 'type' => 'money', 'min' => 0, 'max' => 500000, 'col' => 6, 'help' => 'Used when a stop has no fare of its own'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'col' => 6, 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['name' => 'description', 'label' => 'Notes', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            if ($id && ($data['status'] ?? null) === 'inactive' && ($old['status'] ?? '') !== 'inactive') {
                $n = (int) db_value("SELECT COUNT(*) FROM transport_allocations WHERE route_id = ? AND status = 'active'", [$id]);
                if ($n) {
                    $errors['status'] = "This route has $n active riders. Transfer or cancel them before deactivating it.";
                }
            }
            return $errors;
        },
        'before_save' => function (array $data): array {
            if (!empty($data['code'])) {
                $data['code'] = strtoupper((string) $data['code']);
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $alloc = (int) db_value('SELECT COUNT(*) FROM transport_allocations WHERE route_id = ?', [$id]);
            if ($alloc) {
                return "Route {$row['code']} has $alloc allocation records. Mark it inactive instead of deleting it.";
            }
            $veh = (string) db_value('SELECT GROUP_CONCAT(vehicle_no) FROM vehicles WHERE route_id = ?', [$id]);
            return $veh !== '' ? "Route {$row['code']} still has vehicles ($veh). Move them to another route first." : null;
        },
        'transform_row' => function (array $row): array {
            foreach (['capacity', 'allocated', 'stops_count'] as $k) {
                if (isset($row[$k])) {
                    $row[$k] = (int) $row[$k];
                }
            }
            if (isset($row['capacity'])) {
                $row['occupancy'] = $row['capacity'] ? round($row['allocated'] * 100 / $row['capacity'], 1) : 0;
            }
            return $row;
        },
        'summary' => function (string $from, array $args): array {
            $cap = transport_capacity_sql('t.id');
            $load = transport_load_sql('t.id');
            $r = db_row("SELECT COUNT(*) AS total, SUM(t.status = 'active') AS active, COALESCE(SUM($cap), 0) AS seats, COALESCE(SUM($load), 0) AS riders,
                                SUM($cap > 0 AND $load >= $cap) AS full, SUM($cap = 0 AND t.status = 'active') AS no_vehicle,
                                (SELECT COUNT(*) FROM transport_stops) AS stops
                         $from", $args) ?? [];
            return array_map('intval', $r);
        },
        'describe' => fn (array $row) => $row['code'] . ' ' . $row['name'],
    ],
];
