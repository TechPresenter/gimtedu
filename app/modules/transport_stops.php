<?php
/** Transport › Route stops (ordered; managed from the route detail screen). */
require_once APP_ROOT . '/app/services/transport.php';

return [
    'table' => 'transport_stops',
    'title' => 'Route Stops',
    'singular' => 'Stop',
    'permission' => 'transport',
    'icon' => 'map-pin',
    'description' => 'Pickup points of each route with timings, distance and fare.',
    'select' => "t.*, r.code AS route_code, r.name AS route_name,
                 (SELECT COUNT(*) FROM transport_allocations a WHERE a.stop_id = t.id AND a.status = 'active') AS riders",
    'joins' => 'JOIN transport_routes r ON r.id = t.route_id',
    'search' => ['t.name', 't.landmark', 'r.code', 'r.name'],
    'search_placeholder' => 'Search stop or landmark…',
    'order' => 'r.code, t.sequence, t.id',
    'scopes' => ['route_id' => 't.route_id'],
    'per_page' => 50,
    'columns' => [
        ['key' => 'sequence', 'label' => '#', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'width' => '3rem'],
        ['key' => 'name', 'label' => 'Stop', 'format' => 'title', 'sub' => 'landmark', 'sortable' => true],
        ['key' => 'route_code', 'label' => 'Route', 'format' => 'title', 'sub' => 'route_name', 'sortable' => 'r.code'],
        ['key' => 'pickup_time', 'label' => 'Pickup', 'format' => 'time', 'sortable' => true],
        ['key' => 'drop_time', 'label' => 'Drop', 'format' => 'time', 'sortable' => true],
        ['key' => 'distance_km', 'label' => 'Distance', 'format' => 'number', 'suffix' => ' km', 'align' => 'right', 'sortable' => true],
        ['key' => 'annual_fare', 'label' => 'Annual fare', 'format' => 'money', 'sortable' => true],
        ['key' => 'riders', 'label' => 'Riders', 'format' => 'number', 'align' => 'center', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'route_id', 'label' => 'Route', 'source' => transport_route_source(false)],
    ],
    'fields' => [
        ['name' => 'route_id', 'label' => 'Route', 'type' => 'select', 'required' => true, 'col' => 6, 'source' => transport_route_source(false), 'readonly_on_edit' => true],
        ['name' => 'sequence', 'label' => 'Position', 'type' => 'number', 'min' => 1, 'max' => 99, 'col' => 6, 'help' => 'Leave blank to add at the end'],
        ['name' => 'name', 'label' => 'Stop name', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 150, 'placeholder' => 'Pari Chowk'],
        ['name' => 'landmark', 'label' => 'Landmark', 'type' => 'text', 'col' => 6, 'maxlength' => 150, 'placeholder' => 'Near metro station gate 2'],
        ['name' => 'pickup_time', 'label' => 'Morning pickup', 'type' => 'time', 'col' => 4],
        ['name' => 'drop_time', 'label' => 'Evening drop', 'type' => 'time', 'col' => 4],
        ['name' => 'distance_km', 'label' => 'Distance from start (km)', 'type' => 'decimal', 'step' => 0.1, 'min' => 0, 'max' => 500, 'col' => 4],
        ['name' => 'annual_fare', 'label' => 'Annual fare (₹)', 'type' => 'money', 'min' => 0, 'max' => 500000, 'col' => 4, 'help' => 'Blank = route default fare'],
        ['name' => 'latitude', 'label' => 'Latitude', 'type' => 'decimal', 'step' => 0.0000001, 'min' => -90, 'max' => 90, 'col' => 4, 'help' => 'For future live tracking'],
        ['name' => 'longitude', 'label' => 'Longitude', 'type' => 'decimal', 'step' => 0.0000001, 'min' => -180, 'max' => 180, 'col' => 4],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $m = array_merge($old ?? [], $data);
            $errors = [];
            if (!empty($m['route_id']) && !empty($data['name'])) {
                $args = [(int) $m['route_id'], trim((string) $data['name'])];
                $sql = 'SELECT COUNT(*) FROM transport_stops WHERE route_id = ? AND name = ?';
                if ($id) {
                    $sql .= ' AND id <> ?';
                    $args[] = $id;
                }
                if ((int) db_value($sql, $args)) {
                    $errors['name'] = 'This route already has a stop with this name.';
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            $routeId = (int) ($data['route_id'] ?? ($old['route_id'] ?? 0));
            if (!$id && empty($data['sequence'])) {
                $data['sequence'] = (int) db_value('SELECT COALESCE(MAX(sequence), 0) + 1 FROM transport_stops WHERE route_id = ?', [$routeId]);
            } elseif ($id && array_key_exists('sequence', $data) && empty($data['sequence'])) {
                unset($data['sequence']);
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            $routeId = (int) db_value('SELECT route_id FROM transport_stops WHERE id = ?', [$id]);
            transport_normalise_stops($routeId, $id, isset($data['sequence']) ? (int) $data['sequence'] : null);
        },
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value("SELECT COUNT(*) FROM transport_allocations WHERE stop_id = ? AND status = 'active'", [$id]);
            return $n ? "{$row['name']} has $n active riders. Transfer them to another stop first." : null;
        },
        'after_delete' => function (int $id, array $row): void {
            transport_normalise_stops((int) $row['route_id']);
        },
        'transform_row' => function (array $row): array {
            foreach (['sequence', 'riders'] as $k) {
                if (isset($row[$k])) {
                    $row[$k] = (int) $row[$k];
                }
            }
            return $row;
        },
        'describe' => fn (array $row) => '"' . $row['name'] . '"',
    ],
];
