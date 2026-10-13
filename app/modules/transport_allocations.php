<?php
/** Transport › Allocations (student / faculty / staff → route → stop, fee, optional invoice). */
require_once APP_ROOT . '/app/services/transport.php';

$name = transport_person_name_sql('t');
$sub = transport_person_sub_sql('t');

return [
    'table' => 'transport_allocations',
    'title' => 'Transport Allocations',
    'singular' => 'Allocation',
    'permission' => 'transport',
    'icon' => 'bus',
    'description' => 'Who travels on which route and stop, the annual fee and its invoice.',
    'select' => "t.*, $name AS person_name, $sub AS person_sub, " . transport_person_photo_sql('t') . ' AS person_photo, ' . transport_person_phone_sql('t') . " AS person_phone,
                 r.code AS route_code, r.name AS route_name, ts.name AS stop_name, ts.pickup_time, ts.drop_time, v.vehicle_no,
                 sf.invoice_no, sf.status AS invoice_status, sf.balance_amount AS invoice_balance, ses.name AS session_name,
                 CONCAT_WS(' · ', ts.name, TIME_FORMAT(ts.pickup_time, '%h:%i %p')) AS stop_label,
                 s.student_uid, p.short_name AS program_short",
    'joins' => transport_person_joins('t') . ' JOIN transport_routes r ON r.id = t.route_id LEFT JOIN transport_stops ts ON ts.id = t.stop_id
               LEFT JOIN vehicles v ON v.id = t.vehicle_id LEFT JOIN student_fees sf ON sf.id = t.student_fee_id LEFT JOIN academic_sessions ses ON ses.id = t.academic_session_id',
    'search' => ['s.first_name', 's.last_name', 's.student_uid', "CONCAT(s.first_name, ' ', s.last_name)", 'f.first_name', 'f.last_name', 'f.employee_id', 'st.first_name', 'st.last_name',
        'st.employee_id', 'r.code', 'r.name', 'ts.name', 'v.vehicle_no', 'sf.invoice_no'],
    'search_placeholder' => 'Search name, student ID, route, stop or vehicle…',
    'order' => "t.status = 'active' DESC, t.start_date DESC, t.id DESC",
    'scopes' => ['route_id' => 't.route_id', 'stop_id' => 't.stop_id', 'student_id' => 't.student_id', 'vehicle_id' => 't.vehicle_id'],
    'columns' => [
        ['key' => 'person_name', 'label' => 'Rider', 'format' => 'person', 'image' => 'person_photo', 'sub' => 'person_sub', 'sortable' => 'person_name'],
        ['key' => 'person_type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => ['student' => 'blue', 'faculty' => 'purple', 'staff' => 'cyan'], 'hidden' => true],
        ['key' => 'route_code', 'label' => 'Route', 'format' => 'title', 'sub' => 'route_name', 'sortable' => 'r.code'],
        ['key' => 'stop_name', 'label' => 'Stop', 'format' => 'title', 'sub' => 'pickup_time', 'sortable' => 'ts.name'],
        ['key' => 'vehicle_no', 'label' => 'Vehicle', 'format' => 'text', 'sortable' => 'v.vehicle_no', 'hidden' => true],
        ['key' => 'fee_amount', 'label' => 'Fee & invoice', 'format' => 'money', 'sortable' => true],
        ['key' => 'invoice_no', 'label' => 'Invoice', 'format' => 'title', 'sub' => 'invoice_status', 'sortable' => 'sf.invoice_no', 'hidden' => true],
        ['key' => 'start_date', 'label' => 'From', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'end_date', 'label' => 'Till', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'person_phone', 'label' => 'Phone', 'format' => 'phone', 'hidden' => true],
        ['key' => 'session_name', 'label' => 'Session', 'format' => 'text', 'hidden' => true],
        ['key' => 'remarks', 'label' => 'Remarks', 'format' => 'truncate', 'truncate' => 40, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['active' => 'green', 'transferred' => 'purple', 'cancelled' => 'red', 'completed' => 'slate']],
    ],
    'export_columns' => [
        ['key' => 'person_name', 'label' => 'Rider'], ['key' => 'person_type', 'label' => 'Type', 'format' => 'badge'], ['key' => 'student_uid', 'label' => 'Student ID'],
        ['key' => 'program_short', 'label' => 'Program'], ['key' => 'person_phone', 'label' => 'Phone'], ['key' => 'route_code', 'label' => 'Route'], ['key' => 'route_name', 'label' => 'Route name'],
        ['key' => 'stop_name', 'label' => 'Stop'], ['key' => 'pickup_time', 'label' => 'Pickup'], ['key' => 'vehicle_no', 'label' => 'Vehicle'],
        ['key' => 'fee_amount', 'label' => 'Annual fee', 'format' => 'money'], ['key' => 'invoice_no', 'label' => 'Invoice'], ['key' => 'invoice_status', 'label' => 'Invoice status', 'format' => 'badge'],
        ['key' => 'start_date', 'label' => 'From', 'format' => 'date'], ['key' => 'end_date', 'label' => 'Till', 'format' => 'date'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => transport_allocation_statuses(), 'default' => 'active'],
        ['key' => 'person_type', 'label' => 'Rider type', 'options' => transport_person_types()],
        ['key' => 'route_id', 'label' => 'Route', 'source' => transport_route_source(false)],
        ['key' => 'stop_id', 'label' => 'Stop', 'source' => 'transport_stops', 'depends' => ['route_id' => 'route_id']],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 's.program_id'],
        ['key' => 'invoice', 'label' => 'Invoice', 'sql_map' => [
            'invoiced' => "t.student_fee_id IS NOT NULL AND sf.status <> 'cancelled'",
            'not_invoiced' => "t.person_type = 'student' AND (t.student_fee_id IS NULL OR sf.status = 'cancelled')",
            'unpaid' => "sf.status IN ('pending', 'partial', 'overdue')",
            'paid' => "sf.status = 'paid'",
        ], 'options' => ['invoiced' => 'Invoice raised', 'not_invoiced' => 'Not invoiced (students)', 'unpaid' => 'Invoice unpaid', 'paid' => 'Invoice paid']],
    ],
    'fields' => [
        ['name' => 'person_type', 'label' => 'Rider type', 'type' => 'radio', 'required' => true, 'default' => 'student', 'options' => transport_person_types(), 'col' => 12, 'readonly_on_edit' => true],
        ['name' => 'student_id', 'label' => 'Student', 'type' => 'combobox', 'source' => 'students', 'col' => 12, 'readonly_on_edit' => true, 'placeholder' => 'Search student by name or ID…'],
        ['name' => 'faculty_id', 'label' => 'Faculty member', 'type' => 'combobox', 'source' => 'faculty', 'col' => 12, 'readonly_on_edit' => true],
        ['name' => 'staff_id', 'label' => 'Staff member', 'type' => 'combobox', 'source' => 'staff', 'col' => 12, 'readonly_on_edit' => true],
        ['name' => 'route_id', 'label' => 'Route', 'type' => 'select', 'required' => true, 'col' => 6, 'source' => transport_route_source(true), 'readonly_on_edit' => true],
        ['name' => 'stop_id', 'label' => 'Pickup stop', 'type' => 'select', 'required' => true, 'col' => 6, 'source' => 'transport_stops', 'depends' => ['route_id' => 'route_id'], 'readonly_on_edit' => true],
        ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true, 'col' => 4],
        ['name' => 'end_date', 'label' => 'End date', 'type' => 'date', 'col' => 4, 'help' => 'Blank = till the end of the session'],
        ['name' => 'fee_amount', 'label' => 'Annual fee (₹)', 'type' => 'money', 'min' => 0, 'max' => 500000, 'col' => 4, 'help' => 'Blank = stop fare. An unpaid transport invoice follows changes.'],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
        ['name' => 'raise_invoice', 'label' => 'Raise transport fee invoice', 'type' => 'toggle', 'create_only' => true, 'col' => 12, 'import' => false, 'help' => 'Creates an invoice in Fees with the Transport head (students only).'],
    ],
    'bulk' => ['delete' => false],
    'import' => false,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null) {
                return transport_validate_allocation($data);
            }
            $m = array_merge($old, $data);
            $errors = [];
            if (empty($m['start_date'])) {
                $errors['start_date'] = 'Start date is required.';
            }
            if (!empty($m['end_date']) && !empty($m['start_date']) && $m['end_date'] < $m['start_date']) {
                $errors['end_date'] = 'End date cannot be before the start date.';
            }
            if (isset($data['fee_amount']) && $data['fee_amount'] !== null && (float) $data['fee_amount'] < 0) {
                $errors['fee_amount'] = 'Fee cannot be negative.';
            }
            if ($old['status'] !== 'active' && (array_key_exists('fee_amount', $data) && (float) $data['fee_amount'] !== (float) $old['fee_amount'])) {
                $errors['fee_amount'] = 'The fee of a closed allocation cannot be changed.';
            } elseif (array_key_exists('fee_amount', $data) && ($msg = transport_validate_fee_change($old, $data['fee_amount']))) {
                $errors['fee_amount'] = $msg;
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null) {
                $type = $data['person_type'] ?? 'student';
                foreach (['student' => 'student_id', 'faculty' => 'faculty_id', 'staff' => 'staff_id'] as $t => $col) {
                    if ($t !== $type) {
                        $data[$col] = null;
                    }
                }
                $routeId = (int) $data['route_id'];
                if (!isset($data['fee_amount']) || $data['fee_amount'] === null || $data['fee_amount'] === '') {
                    $data['fee_amount'] = transport_default_fee((int) $data['stop_id'], $routeId, (string) $type);
                }
                $data['vehicle_id'] = transport_pick_vehicle($routeId);
                $data['academic_session_id'] = current_session_id();
                $data['status'] = 'active';
            }
            unset($data['raise_invoice']);
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            $a = db_row('SELECT * FROM transport_allocations WHERE id = ?', [$id]);
            transport_sync_student_flag($a['student_id'] ? (int) $a['student_id'] : null);
            if ($old === null && in_array($input['raise_invoice'] ?? false, [true, 1, '1', 'true', 'on', 'yes'], true) && $a['person_type'] === 'student' && (float) $a['fee_amount'] > 0) {
                transport_create_invoice($id);
            }
            // Edited fee: the open, unpaid invoice follows it (validated above).
            if ($old !== null && $a['student_fee_id'] && abs((float) $a['fee_amount'] - (float) $old['fee_amount']) >= 0.01) {
                $inv = db_row('SELECT * FROM student_fees WHERE id = ?', [(int) $a['student_fee_id']]);
                if ($inv && $inv['status'] !== 'cancelled' && (float) $inv['paid_amount'] <= 0 && (float) $a['fee_amount'] > 0) {
                    transport_update_invoice_amount($inv, (float) $a['fee_amount']);
                    log_activity('update', 'transport', $id, sprintf('Invoice %s updated to %s after the transport fee was changed', $inv['invoice_no'], money((float) $a['fee_amount'])));
                }
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            if ($row['status'] === 'active') {
                return 'Active allocations cannot be deleted. Cancel the allocation instead so the history is kept.';
            }
            if ($row['student_fee_id'] && db_value("SELECT COUNT(*) FROM student_fees WHERE id = ? AND status <> 'cancelled'", [(int) $row['student_fee_id']])
                && !db_value("SELECT COUNT(*) FROM transport_allocations WHERE student_fee_id = ? AND id <> ?", [(int) $row['student_fee_id'], $id])) {
                return 'This allocation is linked to an open fee invoice. Cancel the invoice in Fees before deleting the record.';
            }
            if (db_value('SELECT COUNT(*) FROM transport_allocations WHERE transferred_from_id = ?', [$id])) {
                return 'This record is part of a transfer history and cannot be deleted.';
            }
            return null;
        },
        'bulk_action' => function (string $action, array $ids, array $in): array {
            if ($action === 'invoice') {
                if (!can('transport', 'edit')) {
                    throw new CrudException('You do not have permission to raise invoices.');
                }
                $ok = 0;
                $skipped = [];
                foreach ($ids as $id) {
                    try {
                        transport_create_invoice((int) $id, !empty($in['due_date']) ? (string) $in['due_date'] : null);
                        $ok++;
                    } catch (CrudException $e) {
                        $skipped[] = $e->getMessage();
                    }
                }
                return ['created' => $ok, 'skipped' => count($skipped), 'message' => $ok . ' invoice(s) raised' . ($skipped ? ', ' . count($skipped) . ' skipped (already invoiced, non-student or no fee).' : '.')];
            }
            if ($action === 'cancel') {
                $reason = trim((string) ($in['reason'] ?? ''));
                if (mb_strlen($reason) < 3) {
                    throw new CrudValidationException(['reason' => 'Enter a reason for cancelling.']);
                }
                $ok = 0;
                $why = [];
                foreach ($ids as $id) {
                    try {
                        transport_cancel_allocation((int) $id, ['date' => $in['date'] ?? date('Y-m-d'), 'reason' => $reason, 'cancel_invoice' => $in['cancel_invoice'] ?? false]);
                        $ok++;
                    } catch (CrudValidationException $e) {
                        $why[] = implode(' ', $e->errors);
                    } catch (CrudException $e) {
                        $why[] = $e->getMessage();
                    }
                }
                $why = array_values(array_unique($why));
                return ['cancelled' => $ok, 'skipped' => count($ids) - $ok, 'message' => $ok . ' allocation(s) cancelled'
                    . ($ok < count($ids) ? ', ' . (count($ids) - $ok) . ' skipped (' . rtrim(implode(' ', array_slice($why, 0, 2)), '.') . ').' : '.')];
            }
            throw new CrudException('Unknown action.');
        },
        'transform_row' => function (array $row): array {
            if (array_key_exists('fee_amount', $row) && $row['fee_amount'] !== null) {
                $row['fee_amount'] = (float) $row['fee_amount'];
            }
            return $row;
        },
        'summary' => function (string $from, array $args): array {
            $r = db_row("SELECT COUNT(*) AS total, SUM(t.status = 'active') AS active, SUM(t.status = 'active' AND t.person_type = 'student') AS students,
                                SUM(t.status = 'active' AND t.person_type <> 'student') AS staff, COALESCE(SUM(CASE WHEN t.status = 'active' THEN t.fee_amount ELSE 0 END), 0) AS fee_total,
                                SUM(t.status = 'active' AND t.student_fee_id IS NOT NULL AND sf.status <> 'cancelled') AS invoiced,
                                SUM(t.status = 'active' AND t.person_type = 'student' AND (t.student_fee_id IS NULL OR sf.status = 'cancelled')) AS not_invoiced,
                                COALESCE(SUM(CASE WHEN t.status = 'active' AND sf.status <> 'cancelled' THEN sf.paid_amount ELSE 0 END), 0) AS collected,
                                COALESCE(SUM(CASE WHEN t.status = 'active' AND sf.status <> 'cancelled' THEN sf.balance_amount ELSE 0 END), 0) AS outstanding
                         $from", $args) ?? [];
            return ['total' => (int) $r['total'], 'active' => (int) $r['active'], 'students' => (int) $r['students'], 'staff' => (int) $r['staff'],
                'fee_total' => (float) $r['fee_total'], 'invoiced' => (int) $r['invoiced'], 'not_invoiced' => (int) $r['not_invoiced'],
                'collected' => round((float) $r['collected'], 2), 'outstanding' => round((float) $r['outstanding'], 2)];
        },
        'describe' => fn (array $row) => transport_allocation_person($row),
    ],
];
