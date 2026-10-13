<?php
/** Transport › Fuel log (mileage computed from consecutive odometer readings). */
require_once APP_ROOT . '/app/services/transport.php';

$prev = transport_prev_odometer_sql('t');

return [
    'table' => 'transport_fuel',
    'title' => 'Fuel Log',
    'singular' => 'Fuel entry',
    'permission' => 'transport',
    'icon' => 'fuel',
    'description' => 'Every refill with quantity, cost, odometer and computed mileage.',
    'select' => "t.*, v.vehicle_no, v.type AS vehicle_type, v.fuel_type, r.code AS route_code, u.name AS created_by_name,
                 $prev AS prev_odometer,
                 CASE WHEN t.odometer IS NOT NULL AND $prev IS NOT NULL THEN t.odometer - $prev END AS km_run,
                 CASE WHEN t.odometer IS NOT NULL AND $prev IS NOT NULL AND t.liters > 0 THEN ROUND((t.odometer - $prev) / t.liters, 2) END AS mileage,
                 ROUND(t.cost / NULLIF(t.liters, 0), 2) AS rate",
    'joins' => 'JOIN vehicles v ON v.id = t.vehicle_id LEFT JOIN transport_routes r ON r.id = v.route_id LEFT JOIN users u ON u.id = t.created_by',
    'search' => ['v.vehicle_no', "REPLACE(v.vehicle_no, ' ', '')", 't.station', 't.bill_no', 'r.code'],
    'search_placeholder' => 'Search vehicle, station or bill no…',
    'order' => 't.fill_date DESC, t.id DESC',
    'scopes' => ['vehicle_id' => 't.vehicle_id'],
    'columns' => [
        ['key' => 'fill_date', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
        ['key' => 'vehicle_no', 'label' => 'Vehicle', 'format' => 'title', 'sub' => 'route_code', 'sortable' => 'v.vehicle_no'],
        ['key' => 'liters', 'label' => 'Qty (L/kg)', 'format' => 'number', 'align' => 'right', 'sortable' => true],
        ['key' => 'rate', 'label' => 'Rate', 'format' => 'money', 'sortable' => 'rate', 'hidden' => true],
        ['key' => 'cost', 'label' => 'Cost', 'format' => 'money', 'sortable' => true],
        ['key' => 'odometer', 'label' => 'Odometer', 'format' => 'number', 'suffix' => ' km', 'align' => 'right', 'sortable' => true],
        ['key' => 'km_run', 'label' => 'Km run', 'format' => 'number', 'align' => 'right', 'sortable' => 'km_run'],
        ['key' => 'mileage', 'label' => 'Mileage', 'format' => 'number', 'suffix' => ' km/L', 'align' => 'right', 'sortable' => 'mileage'],
        ['key' => 'station', 'label' => 'Station', 'format' => 'truncate', 'truncate' => 32],
        ['key' => 'bill_no', 'label' => 'Bill no.', 'format' => 'text', 'hidden' => true],
        ['key' => 'fuel_type', 'label' => 'Fuel', 'format' => 'badge', 'hidden' => true, 'colors' => ['diesel' => 'slate', 'cng' => 'green', 'petrol' => 'amber', 'electric' => 'cyan']],
        ['key' => 'created_by_name', 'label' => 'Logged by', 'format' => 'text', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'vehicle_id', 'label' => 'Vehicle', 'source' => 'vehicles'],
        ['key' => 'fill_date', 'label' => 'Date', 'type' => 'daterange'],
        ['key' => 'route', 'label' => 'Route', 'source' => transport_route_source(false), 'sql' => 'v.route_id = ?'],
        ['key' => 'fuel_type', 'label' => 'Fuel', 'options' => transport_fuel_types(), 'column' => 'v.fuel_type'],
    ],
    'fields' => [
        ['name' => 'vehicle_id', 'label' => 'Vehicle', 'type' => 'select', 'required' => true, 'col' => 6,
            'source' => ['table' => 'vehicles v', 'value' => 'v.id', 'label' => "CONCAT(v.vehicle_no, ' (', v.fuel_type, ')')", 'sub' => "CONCAT('Odometer ', COALESCE(v.current_odometer, 0), ' km')",
                'where' => "v.status <> 'inactive'", 'order' => 'v.vehicle_no', 'search' => ['v.vehicle_no']]],
        ['name' => 'fill_date', 'label' => 'Fill date', 'type' => 'date', 'required' => true, 'col' => 6],
        ['name' => 'liters', 'label' => 'Quantity (litres / kg)', 'type' => 'decimal', 'step' => 0.01, 'required' => true, 'min' => 0.1, 'max' => 1000, 'col' => 4],
        ['name' => 'cost', 'label' => 'Total cost (₹)', 'type' => 'money', 'required' => true, 'min' => 1, 'max' => 500000, 'col' => 4],
        ['name' => 'odometer', 'label' => 'Odometer (km)', 'type' => 'number', 'min' => 0, 'max' => 5000000, 'col' => 4, 'help' => 'Used to compute mileage'],
        ['name' => 'station', 'label' => 'Fuel station', 'type' => 'text', 'col' => 6, 'maxlength' => 150, 'placeholder' => 'Indian Oil, Knowledge Park II'],
        ['name' => 'bill_no', 'label' => 'Bill no.', 'type' => 'text', 'col' => 6, 'maxlength' => 60],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => fn (array $data, ?int $id, ?array $old) => transport_validate_fuel($data, $id, $old),
        'after_save' => function (int $id, array $data): void {
            $f = db_row('SELECT vehicle_id, odometer FROM transport_fuel WHERE id = ?', [$id]);
            transport_bump_odometer((int) $f['vehicle_id'], $f['odometer'] ? (int) $f['odometer'] : null);
        },
        'transform_row' => function (array $row): array {
            foreach (['liters', 'cost', 'rate', 'mileage'] as $k) {
                if (isset($row[$k])) {
                    $row[$k] = (float) $row[$k];
                }
            }
            return $row;
        },
        'summary' => function (string $from, array $args): array {
            $r = db_row('SELECT COUNT(*) AS fills, COALESCE(SUM(t.liters), 0) AS liters, COALESCE(SUM(t.cost), 0) AS cost, COUNT(DISTINCT t.vehicle_id) AS vehicles ' . $from, $args) ?? [];
            $liters = (float) $r['liters'];
            return ['fills' => (int) $r['fills'], 'liters' => round($liters, 1), 'cost' => round((float) $r['cost'], 2), 'vehicles' => (int) $r['vehicles'],
                'avg_rate' => $liters > 0 ? round((float) $r['cost'] / $liters, 2) : null];
        },
        'describe' => function (array $row): string {
            $v = (string) db_value('SELECT vehicle_no FROM vehicles WHERE id = ?', [(int) $row['vehicle_id']]);
            return sprintf('%s — %s L for %s on %s', $v, $row['liters'], money($row['cost']), format_date($row['fill_date']));
        },
    ],
];
