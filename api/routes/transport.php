<?php
/**
 * Transport unit endpoints (CRUD lists/forms go through /api/crud/{vehicles|drivers|transport_*}).
 *
 *   GET  /transport/dashboard                         KPIs, monthly fuel & maintenance cost, routes overview, alerts, upcoming services
 *   GET  /transport/settings                          alert window, fee due days, GPS integration (future scope) settings
 *   POST /transport/settings                          save settings (transport.manage)
 *   GET  /transport/alerts?days=60                    document / licence expiries
 *   GET  /transport/route-options                     active routes with stops, fares and free seats (allocation forms)
 *   GET  /transport/routes/{id}                       route detail: vehicles & crew, stops with riders
 *   POST /transport/routes/{id}/stops/reorder         {ids: [stop ids in order]}
 *   GET  /transport/routes/{id}/print                 printable route sheet (HTML)
 *   GET  /transport/vehicles/{id}                     vehicle detail: documents, fuel stats, maintenance history
 *   POST /transport/vehicles/{id}/renew               {document, expiry, cost, vendor, bill_no, date}
 *   GET  /transport/fuel/stats?from&to&vehicle_id     fuel totals + efficiency per vehicle
 *   GET  /transport/pending?q&page                    day scholars who opted for transport without a seat
 *   POST /transport/allocations/{id}/cancel           {date, reason, cancel_invoice}
 *   POST /transport/allocations/{id}/transfer         {route_id, stop_id, effective_date, fee_amount, update_invoice, remarks}
 *   POST /transport/allocations/{id}/invoice          {due_date}
 *   GET  /transport/allocations/pass?ids=1,2          printable transport passes (HTML)
 *   GET  /transport/maintenance/upcoming?days=30      scheduled / in-workshop jobs and services falling due
 *   POST /transport/maintenance/{id}/complete         {service_date, cost, odometer, vendor, bill_no, next_service_date, next_service_km, description}
 *   POST /transport/complaints/{id}/status            {status, resolution, note, assigned_to}
 *   GET  /transport/complaints/{id}                   complaint with history timeline
 */

require_once APP_ROOT . '/app/services/transport.php';

route('GET', '/transport/dashboard', function () {
    api_require('transport', 'view');
    api_ok(transport_dashboard());
});

route('GET', '/transport/settings', function () {
    api_require('transport', 'view');
    api_ok(transport_settings() + ['can_manage' => can('transport', 'manage')]);
});

route('POST', '/transport/settings', function () {
    api_require('transport', 'manage');
    api_ok(transport_save_settings(api_input()), 'Transport settings saved.');
});

route('GET', '/transport/alerts', function () {
    api_require('transport', 'view');
    $days = max(7, min(365, (int) ($_GET['days'] ?? max(60, transport_alert_days()))));
    api_ok(['rows' => transport_document_alerts($days, 200), 'days' => $days, 'alert_days' => transport_alert_days()]);
});

route('GET', '/transport/route-options', function () {
    api_require('transport', 'view');
    api_ok(transport_route_options());
});

route('GET', '/transport/routes/{id:\d+}', function ($p) {
    api_require('transport', 'view');
    $d = transport_route_detail((int) $p['id']);
    if (!$d) {
        api_error('Route not found.', 404);
    }
    api_ok($d);
});

route('POST', '/transport/routes/{id:\d+}/stops/reorder', function ($p) {
    api_require('transport', 'edit');
    $id = (int) $p['id'];
    $route = db_row('SELECT code FROM transport_routes WHERE id = ?', [$id]);
    if (!$route) {
        api_error('Route not found.', 404);
    }
    $ids = (array) (api_input()['ids'] ?? []);
    if (!$ids) {
        api_error('Send the stops in their new order.', 422);
    }
    transport_reorder_stops($id, $ids);
    log_activity('update', 'transport', $id, 'Reordered the stops of route ' . $route['code']);
    api_ok(transport_route_detail($id)['stops'], 'Stop order saved.');
});

// HTML print views: auth handled by transport_print_guard() so a cold open redirects to the login page.
route('GET', '/transport/routes/{id:\d+}/print', function ($p) {
    if (transport_print_guard('view')) {
        transport_print_route_sheet((int) $p['id']);
    }
    exit;
}, ['auth' => false]);

route('GET', '/transport/vehicles/{id:\d+}', function ($p) {
    api_require('transport', 'view');
    $d = transport_vehicle_detail((int) $p['id']);
    if (!$d) {
        api_error('Vehicle not found.', 404);
    }
    api_ok($d);
});

route('POST', '/transport/vehicles/{id:\d+}/renew', function ($p) {
    api_require('transport', 'edit');
    $res = transport_renew_document((int) $p['id'], api_input());
    api_ok($res, 'Document renewed and expiry updated.');
});

route('GET', '/transport/fuel/stats', function () {
    api_require('transport', 'view');
    $from = (string) ($_GET['from'] ?? '');
    $to = (string) ($_GET['to'] ?? '');
    foreach (['from' => $from, 'to' => $to] as $k => $v) {
        if ($v !== '' && !DateTime::createFromFormat('Y-m-d', $v)) {
            api_error('Invalid date.', 422, [$k => 'Enter a valid date.']);
        }
    }
    api_ok(transport_fuel_stats($from ?: null, $to ?: null, !empty($_GET['vehicle_id']) ? (int) $_GET['vehicle_id'] : null));
});

route('GET', '/transport/pending', function () {
    api_require('transport', 'view');
    [$page, $perPage] = api_pagination(10);
    api_ok(transport_pending_students(trim((string) ($_GET['q'] ?? '')), $page, $perPage));
});

route('POST', '/transport/allocations/{id:\d+}/cancel', function ($p) {
    api_require('transport', 'edit');
    $res = transport_cancel_allocation((int) $p['id'], api_input());
    api_ok($res, 'Allocation cancelled.' . ($res['note'] ? ' ' . $res['note'] : ''));
});

route('POST', '/transport/allocations/{id:\d+}/transfer', function ($p) {
    api_require('transport', 'edit');
    $res = transport_transfer_allocation((int) $p['id'], api_input());
    api_ok($res, 'Transfer saved.' . ($res['note'] ? ' ' . $res['note'] : ''));
});

route('POST', '/transport/allocations/{id:\d+}/invoice', function ($p) {
    api_require('transport', 'edit');
    $due = trim((string) (api_input()['due_date'] ?? ''));
    $inv = transport_create_invoice((int) $p['id'], $due !== '' ? $due : null);
    api_ok(['invoice' => $inv], 'Invoice ' . $inv['invoice_no'] . ' raised for ' . money($inv['net_amount']) . '.');
});

route('GET', '/transport/allocations/pass', function () {
    if (transport_print_guard('view')) {
        $ids = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))));
        transport_print_passes(array_slice($ids, 0, 200));
    }
    exit;
}, ['auth' => false]);

route('GET', '/transport/maintenance/upcoming', function () {
    api_require('transport', 'view');
    $days = max(7, min(180, (int) ($_GET['days'] ?? 30)));
    api_ok(['rows' => transport_upcoming_services($days), 'days' => $days]);
});

route('POST', '/transport/maintenance/{id:\d+}/complete', function ($p) {
    api_require('transport', 'edit');
    api_ok(transport_complete_maintenance((int) $p['id'], api_input()), 'Job marked as completed.');
});

route('POST', '/transport/complaints/{id:\d+}/status', function ($p) {
    api_require('transport', 'edit');
    $in = api_input();
    $to = (string) ($in['status'] ?? '');
    $row = transport_complaint_set_status((int) $p['id'], $to, $in['resolution'] ?? null, $in['note'] ?? null, !empty($in['assigned_to']) ? (int) $in['assigned_to'] : null);
    api_ok($row, 'Complaint moved to ' . strtolower(transport_complaint_statuses()[$to] ?? $to) . '.');
});

route('GET', '/transport/complaints/{id:\d+}', function ($p) {
    api_require('transport', 'view');
    $id = (int) $p['id'];
    $c = db_row('SELECT tc.*, r.code AS route_code, r.name AS route_name, v.vehicle_no, s.student_uid, au.name AS assigned_name, ru.name AS resolved_by_name, cu.name AS created_by_name
                 FROM transport_complaints tc LEFT JOIN transport_routes r ON r.id = tc.route_id LEFT JOIN vehicles v ON v.id = tc.vehicle_id
                 LEFT JOIN students s ON s.id = tc.student_id LEFT JOIN users au ON au.id = tc.assigned_to LEFT JOIN users ru ON ru.id = tc.resolved_by
                 LEFT JOIN users cu ON cu.id = tc.created_by WHERE tc.id = ?', [$id]);
    if (!$c) {
        api_error('Complaint not found.', 404);
    }
    $c['id'] = (int) $c['id'];
    api_ok(['complaint' => $c, 'history' => transport_complaint_history($id), 'transitions' => transport_complaint_transitions($c['status'])]);
});
