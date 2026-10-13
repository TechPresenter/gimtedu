<?php
/**
 * Hostel unit (brief §20) — custom endpoints. Plain CRUD lives in /api/crud/{hostels|hostel_blocks|hostel_rooms|hostel_beds|
 * hostel_allocations|hostel_complaints|hostel_visitors}.
 *
 *   GET  /hostel/dashboard                              KPIs, occupancy by hostel, room types, trend, recent complaints, visitors today
 *   GET  /hostel/overview                               hostels with live room / bed / complaint counts (cards)
 *   GET  /hostel/grid/{hostelId}?free=1                 blocks + rooms + beds + occupants (visual grid / free-bed picker)
 *   GET  /hostel/rooms/{id}                             room detail (beds, residents, history, complaints)
 *   POST /hostel/rooms/generate                         bulk-create rooms {hostel_id, block_id, floor_from, floor_to, rooms_per_floor, room_type, capacity, annual_fee, is_ac, amenities[], pattern}
 *   POST /hostel/beds/{id}/status                       {status: available|maintenance}
 *   GET  /hostel/students/{id}                          student summary for the allocation dialog (gender, current stay, history, eligible hostels)
 *   GET  /hostel/allocations/stats                      KPIs for the allocations page
 *   GET  /hostel/allocations/{id}                       allocation detail (drawer)
 *   POST /hostel/allocations/{id}/vacate                {vacated_on, reason}
 *   POST /hostel/allocations/{id}/transfer              {hostel_id, room_id, bed_id, transfer_date, reason, update_rent}
 *   POST /hostel/allocations/{id}/cancel                {reason, cancel_invoice}
 *   POST /hostel/allocations/{id}/invoice               {amount?, include_mess?, due_date?}  raise hostel fee invoice (student_fees, fee head type "hostel")
 *   GET  /hostel/complaints/stats
 *   POST /hostel/complaints/{id}/{assign|start|resolve|close|reopen}   {assigned_staff_id, start, resolution, remarks}
 *   GET  /hostel/visitors/stats?hostel_id=              KPIs, currently-inside list, 14-day trend
 *   POST /hostel/visitors/{id}/checkout                 {check_out?, remarks?}
 *   GET  /hostel/print/allotment/{id}                   allotment letter (HTML, A4)
 *   GET  /hostel/print/register/{hostelId}?block_id=    occupancy register (HTML, A4 landscape)
 *   GET  /hostel/print/visitor-pass/{id}                visitor gate pass
 *   GET  /hostel/print/complaint/{id}                   complaint job card
 */

require_once APP_ROOT . '/app/services/hostel.php';

route('GET', '/hostel/dashboard', function () {
    api_require('hostel', 'view');
    api_ok(hostel_dashboard());
});

route('GET', '/hostel/overview', function () {
    api_require('hostel', 'view');
    api_ok(['hostels' => hostel_overview()]);
});

route('GET', '/hostel/grid/{id:\d+}', function ($p) {
    api_require('hostel', 'view');
    api_ok(hostel_grid((int) $p['id'], !empty($_GET['free']) && $_GET['free'] !== '0'));
});

route('GET', '/hostel/rooms/{id:\d+}', function ($p) {
    api_require('hostel', 'view');
    $d = hostel_room_detail((int) $p['id']);
    if (!$d) {
        api_error('Room not found. It may have been deleted.', 404);
    }
    api_ok($d);
});

route('POST', '/hostel/rooms/generate', function () {
    api_require('hostel', 'create');
    $res = hostel_generate_rooms(api_input());
    $msg = $res['created'] ? sprintf('%d room%s with %d beds added.', $res['created'], $res['created'] === 1 ? '' : 's', $res['beds']) : 'No new rooms were created.';
    if ($res['skipped']) {
        $msg .= ' ' . $res['skipped'] . ' room number' . ($res['skipped'] === 1 ? '' : 's') . ' already existed and ' . ($res['skipped'] === 1 ? 'was' : 'were') . ' skipped.';
    }
    api_ok($res, $msg, $res['created'] ? 201 : 200);
});

route('POST', '/hostel/beds/{id:\d+}/status', function ($p) {
    api_require('hostel', 'edit');
    $status = (string) api_param('status', '');
    $bed = hostel_set_bed_status((int) $p['id'], $status);
    api_ok(['id' => (int) $p['id'], 'status' => $status], 'Bed ' . $bed['bed_no'] . ' of Room ' . $bed['room_no'] . ' marked ' . ($status === 'maintenance' ? 'under maintenance.' : 'available.'));
});

route('GET', '/hostel/students/{id:\d+}', function ($p) {
    api_require('hostel', 'view');
    $s = hostel_student_summary((int) $p['id']);
    if (!$s) {
        api_error('Student not found.', 404);
    }
    api_ok($s);
});

route('GET', '/hostel/allocations/stats', function () {
    api_require('hostel', 'view');
    api_ok(hostel_allocation_stats());
});

route('GET', '/hostel/allocations/{id:\d+}', function ($p) {
    api_require('hostel', 'view');
    $d = hostel_allocation_detail((int) $p['id']);
    if (!$d) {
        api_error('Allocation not found. It may have been deleted.', 404);
    }
    api_ok($d);
});

route('POST', '/hostel/allocations/{id:\d+}/vacate', function ($p) {
    api_require('hostel', 'edit');
    $a = hostel_vacate((int) $p['id'], api_input());
    api_ok($a, sprintf('%s vacated %s Room %s. The bed is free again.', hostel_student_name($a), $a['hostel_code'], $a['room_no']));
});

route('POST', '/hostel/allocations/{id:\d+}/transfer', function ($p) {
    api_require('hostel', 'edit');
    $a = hostel_transfer((int) $p['id'], api_input());
    api_ok($a, sprintf('%s moved to %s Room %s%s.', hostel_student_name($a), $a['hostel_code'], $a['room_no'], $a['bed_no'] ? ' (Bed ' . $a['bed_no'] . ')' : ''));
});

route('POST', '/hostel/allocations/{id:\d+}/cancel', function ($p) {
    api_require('hostel', 'edit');
    $a = hostel_cancel((int) $p['id'], api_input());
    api_ok($a, 'Allocation ' . $a['allocation_no'] . ' cancelled and the bed released.');
});

route('POST', '/hostel/allocations/{id:\d+}/invoice', function ($p) {
    api_require('hostel', 'edit');
    $res = hostel_raise_invoice((int) $p['id'], api_input());
    api_ok($res, 'Hostel fee invoice ' . $res['invoice_no'] . ' of ' . money($res['amount']) . ' raised.', 201);
});

route('GET', '/hostel/complaints/stats', function () {
    api_require('hostel', 'view');
    api_ok(hostel_complaint_stats());
});

route('POST', '/hostel/complaints/{id:\d+}/{action:assign|start|resolve|close|reopen}', function ($p) {
    api_require('hostel', 'edit');
    $c = hostel_complaint_action((int) $p['id'], $p['action'], api_input());
    $messages = ['assign' => 'Complaint assigned to ' . ($c['assigned_to'] ?? 'staff') . '.', 'start' => 'Complaint moved to In progress.', 'resolve' => 'Complaint marked as resolved.',
        'close' => 'Complaint closed.', 'reopen' => 'Complaint reopened.'];
    api_ok($c, $messages[$p['action']]);
});

route('GET', '/hostel/visitors/stats', function () {
    api_require('hostel', 'view');
    $hostelId = !empty($_GET['hostel_id']) ? (int) $_GET['hostel_id'] : null;
    api_ok(hostel_visitor_stats($hostelId));
});

route('POST', '/hostel/visitors/{id:\d+}/checkout', function ($p) {
    api_require('hostel', 'edit');
    $v = hostel_visitor_checkout((int) $p['id'], api_input());
    api_ok($v, $v['visitor_name'] . ' checked out at ' . date('h:i A', strtotime($v['check_out'])) . '.');
});

/* ---------------------------------------------------------------- print views (HTML) */

route('GET', '/hostel/print/allotment/{id:\d+}', function ($p) {
    hostel_print_allotment((int) $p['id']);
});

route('GET', '/hostel/print/register/{id:\d+}', function ($p) {
    hostel_print_register((int) $p['id'], !empty($_GET['block_id']) ? (int) $_GET['block_id'] : null);
});

route('GET', '/hostel/print/visitor-pass/{id:\d+}', function ($p) {
    hostel_print_visitor_pass((int) $p['id']);
});

route('GET', '/hostel/print/complaint/{id:\d+}', function ($p) {
    hostel_print_complaint((int) $p['id']);
});
