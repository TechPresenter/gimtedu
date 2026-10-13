<?php
/** Transport › Drivers, conductors and attendants. */
require_once APP_ROOT . '/app/services/transport.php';

$alertDays = transport_alert_days();
$vehicleSql = "(SELECT v.vehicle_no FROM vehicles v WHERE (v.driver_id = t.id OR v.conductor_id = t.id) AND v.status <> 'inactive' ORDER BY v.id LIMIT 1)";
$routeSql = "(SELECT r.code FROM vehicles v JOIN transport_routes r ON r.id = v.route_id WHERE (v.driver_id = t.id OR v.conductor_id = t.id) AND v.status <> 'inactive' ORDER BY v.id LIMIT 1)";

return [
    'table' => 'drivers',
    'title' => 'Drivers & Crew',
    'singular' => 'Crew member',
    'permission' => 'transport',
    'icon' => 'id-card',
    'description' => 'Drivers, conductors and attendants with licences, verification and vehicle assignment.',
    'select' => "t.*, $vehicleSql AS vehicle_no, $routeSql AS route_code,
                 TIMESTAMPDIFF(YEAR, t.joining_date, CURDATE()) AS years_with_us,
                 CONCAT_WS(' · ', t.license_no, t.license_type) AS license_label",
    'search' => ['t.name', 't.phone', 't.license_no', 't.address'],
    'search_placeholder' => 'Search name, phone or licence no…',
    'order' => "t.status = 'inactive', FIELD(t.type, 'driver', 'conductor', 'helper'), t.name",
    'columns' => [
        ['key' => 'name', 'label' => 'Name', 'format' => 'person', 'image' => 'photo', 'sub' => 'phone', 'sortable' => true],
        ['key' => 'type', 'label' => 'Role', 'format' => 'badge', 'sortable' => true, 'hidden' => true, 'colors' => ['driver' => 'navy', 'conductor' => 'blue', 'helper' => 'cyan']],
        ['key' => 'license_no', 'label' => 'Licence', 'format' => 'title', 'sub' => 'license_type', 'sortable' => true],
        ['key' => 'license_expiry', 'label' => 'Valid till', 'format' => 'date', 'sortable' => true],
        ['key' => 'experience_years', 'label' => 'Experience', 'format' => 'number', 'align' => 'center', 'suffix' => ' yrs', 'sortable' => true, 'hidden' => true],
        ['key' => 'vehicle_no', 'label' => 'Vehicle', 'format' => 'title', 'sub' => 'route_code', 'sortable' => 'vehicle_no'],
        ['key' => 'police_verified', 'label' => 'Police', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'emergency_phone', 'label' => 'Emergency contact', 'format' => 'phone', 'hidden' => true],
        ['key' => 'joining_date', 'label' => 'Joined', 'format' => 'date', 'hidden' => true, 'sortable' => true],
        ['key' => 'address', 'label' => 'Address', 'format' => 'truncate', 'truncate' => 40, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Role', 'options' => transport_driver_types()],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Inactive']],
        ['key' => 'licence', 'label' => 'Licence', 'sql_map' => [
            'expired' => 't.license_expiry < CURDATE()',
            'due' => "t.license_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $alertDays DAY)",
            'valid' => "t.license_expiry > DATE_ADD(CURDATE(), INTERVAL $alertDays DAY)",
        ], 'options' => ['expired' => 'Expired', 'due' => "Expiring within $alertDays days", 'valid' => 'Valid']],
        ['key' => 'assignment', 'label' => 'Assignment', 'sql_map' => [
            'assigned' => "EXISTS (SELECT 1 FROM vehicles v WHERE (v.driver_id = t.id OR v.conductor_id = t.id) AND v.status <> 'inactive')",
            'unassigned' => "NOT EXISTS (SELECT 1 FROM vehicles v WHERE (v.driver_id = t.id OR v.conductor_id = t.id) AND v.status <> 'inactive')",
        ], 'options' => ['assigned' => 'Assigned to a vehicle', 'unassigned' => 'Not assigned']],
        ['key' => 'police_verified', 'label' => 'Police check', 'type' => 'boolean'],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Personal details'],
        ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 150],
        ['name' => 'type', 'label' => 'Role', 'type' => 'select', 'required' => true, 'default' => 'driver', 'col' => 6, 'options' => transport_driver_types()],
        ['name' => 'phone', 'label' => 'Mobile', 'type' => 'tel', 'required' => true, 'col' => 6, 'placeholder' => '+91 98xxxxxxxx'],
        ['name' => 'emergency_phone', 'label' => 'Emergency contact', 'type' => 'tel', 'col' => 6],
        ['name' => 'address', 'label' => 'Address', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
        ['name' => 'photo', 'label' => 'Photo', 'type' => 'image', 'folder' => 'drivers', 'col' => 12],
        ['type' => 'section', 'label' => 'Licence & experience'],
        ['name' => 'license_no', 'label' => 'Licence / badge no.', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 40, 'placeholder' => 'UP16 20140012345'],
        ['name' => 'license_type', 'label' => 'Licence class', 'type' => 'select', 'col' => 4, 'options' => transport_license_types()],
        ['name' => 'license_expiry', 'label' => 'Licence valid till', 'type' => 'date', 'col' => 4],
        ['name' => 'experience_years', 'label' => 'Experience (years)', 'type' => 'number', 'min' => 0, 'max' => 50, 'col' => 4],
        ['name' => 'joining_date', 'label' => 'Joining date', 'type' => 'date', 'col' => 4],
        ['name' => 'police_verified', 'label' => 'Police verification', 'type' => 'toggle', 'col' => 4, 'placeholder' => 'Verified'],
        ['type' => 'section', 'label' => 'Status'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'col' => 4, 'options' => ['active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Inactive']],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'col' => 8, 'maxlength' => 255],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'on_leave']],
    'import' => true,
    'form' => ['size' => 'xl'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $m = array_merge($old ?? [], $data);
            $errors = [];
            if (($m['type'] ?? 'driver') === 'driver' && !empty($data) && array_key_exists('license_type', $data) && !in_array($m['license_type'] ?? '', ['HMV', 'HPMV', 'LMV'], true)) {
                $errors['license_type'] = 'Drivers need a driving licence class (HMV, HPMV or LMV).';
            }
            if (!empty($m['license_expiry']) && ($m['status'] ?? 'active') === 'active' && $m['type'] === 'driver' && $m['license_expiry'] < date('Y-m-d') && (!$old || $old['license_expiry'] !== $m['license_expiry'])) {
                $errors['license_expiry'] = 'This licence has already expired. Record the renewed licence before marking the driver active.';
            }
            if (!empty($m['joining_date']) && $m['joining_date'] > date('Y-m-d')) {
                $errors['joining_date'] = 'Joining date cannot be in the future.';
            }
            if ($id && $old) {
                $vehicle = db_row("SELECT vehicle_no, driver_id, conductor_id FROM vehicles WHERE (driver_id = ? OR conductor_id = ?) AND status <> 'inactive' LIMIT 1", [$id, $id]);
                if ($vehicle && ($m['status'] ?? 'active') === 'inactive') {
                    $errors['status'] = 'Assigned to ' . $vehicle['vehicle_no'] . '. Assign another crew member to the vehicle first.';
                }
                if ($vehicle && ($m['type'] ?? '') !== $old['type']) {
                    $isDriverSeat = (int) $vehicle['driver_id'] === $id;
                    if ($isDriverSeat !== (($m['type'] ?? '') === 'driver')) {
                        $errors['type'] = 'Assigned to ' . $vehicle['vehicle_no'] . ' as ' . ($isDriverSeat ? 'driver' : 'conductor') . '. Unassign before changing the role.';
                    }
                }
            }
            return $errors;
        },
        'before_save' => function (array $data): array {
            if (!empty($data['license_no'])) {
                $data['license_no'] = strtoupper(trim((string) $data['license_no']));
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $v = db_value('SELECT vehicle_no FROM vehicles WHERE driver_id = ? OR conductor_id = ? LIMIT 1', [$id, $id]);
            return $v ? $row['name'] . ' is assigned to ' . $v . '. Unassign them from the vehicle or mark them inactive instead.' : null;
        },
        'transform_row' => function (array $row): array {
            if (array_key_exists('license_expiry', $row)) {
                $row['license_state'] = transport_expiry_state($row['license_expiry']);
                $row['license_days'] = transport_days_until($row['license_expiry']);
            }
            return $row;
        },
        'summary' => function (string $from, array $args): array {
            $alertDays = transport_alert_days();
            $r = db_row("SELECT COUNT(*) AS total, SUM(t.type = 'driver' AND t.status = 'active') AS drivers, SUM(t.type <> 'driver' AND t.status = 'active') AS crew,
                                SUM(t.status = 'active' AND t.license_expiry < CURDATE()) AS expired,
                                SUM(t.status = 'active' AND t.license_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $alertDays DAY)) AS due,
                                SUM(t.status = 'active' AND t.police_verified = 0) AS unverified,
                                SUM(t.status = 'active' AND NOT EXISTS (SELECT 1 FROM vehicles v WHERE (v.driver_id = t.id OR v.conductor_id = t.id) AND v.status <> 'inactive')) AS unassigned
                         $from", $args) ?? [];
            return array_map('intval', $r) + ['alert_days' => $alertDays];
        },
    ],
];
