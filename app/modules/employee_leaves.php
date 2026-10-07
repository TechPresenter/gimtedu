<?php
/**
 * Leave applications of faculty & staff (Leave Management). Business rules (working days, overlaps, balance)
 * live in app/services/hr.php; approve / reject / cancel go through api/routes/leaves.php.
 */
require_once APP_ROOT . '/app/services/hr.php';

$statuses = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
$faculty = hr_name_sql('faculty', 'f');
$staff = hr_name_sql('staff', 's');

return [
    'table' => 'employee_leaves',
    'title' => 'Leave Applications',
    'singular' => 'Leave Application',
    'permission' => 'leaves',
    'permissions' => [
        'view' => ['faculty', 'view'], 'create' => ['faculty', 'create'], 'edit' => ['faculty', 'edit'], 'delete' => ['faculty', 'delete'],
        'export' => ['faculty', 'export'], 'import' => ['faculty', 'import'],
    ],
    'icon' => 'calendar-x',
    'description' => 'Leave applications of faculty and staff with approval workflow.',
    'select' => "t.*, CONCAT(IF(t.employee_type = 'faculty', 'f-', 's-'), t.employee_id) AS employee_ref,
                 IF(t.employee_type = 'faculty', $faculty, $staff) AS employee_name, COALESCE(f.employee_id, s.employee_id) AS employee_code,
                 COALESCE(f.photo, s.photo) AS employee_photo, COALESCE(f.designation, s.designation) AS employee_designation,
                 d.name AS department_name, d.code AS department_code, lt.name AS leave_type_name, lt.color AS leave_type_color, lt.is_paid,
                 au.name AS approved_by_name, ab.name AS applied_by_name,
                 CONCAT(COALESCE(f.employee_id, s.employee_id), ' · ', COALESCE(d.code, IF(t.employee_type = 'faculty', 'Faculty', 'Staff'))) AS employee_sub",
    'joins' => "LEFT JOIN faculty f ON t.employee_type = 'faculty' AND f.id = t.employee_id
                LEFT JOIN staff s ON t.employee_type = 'staff' AND s.id = t.employee_id
                LEFT JOIN departments d ON d.id = COALESCE(f.department_id, s.department_id)
                LEFT JOIN leave_types lt ON lt.code = t.leave_type
                LEFT JOIN users au ON au.id = t.approved_by
                LEFT JOIN users ab ON ab.id = t.applied_by",
    'search' => ['f.first_name', 'f.last_name', 's.first_name', 's.last_name', 'f.employee_id', 's.employee_id', 't.reason', "CONCAT(COALESCE(f.first_name, s.first_name), ' ', COALESCE(f.last_name, s.last_name, ''))"],
    'search_placeholder' => 'Search employee, employee ID or reason…',
    'order' => "FIELD(t.status, 'pending', 'approved', 'rejected', 'cancelled'), CASE WHEN t.status = 'pending' THEN t.from_date END ASC, t.from_date DESC, t.id DESC",
    'columns' => [
        ['key' => 'employee_name', 'label' => 'Employee', 'format' => 'person', 'image' => 'employee_photo', 'sub' => 'employee_sub', 'sortable' => 'employee_name'],
        ['key' => 'leave_type_name', 'label' => 'Leave Type / Reason', 'format' => 'text', 'sortable' => 'lt.name'],
        ['key' => 'from_date', 'label' => 'Period', 'format' => 'date', 'sortable' => true],
        ['key' => 'days', 'label' => 'Days', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'reason', 'label' => 'Reason', 'format' => 'truncate', 'truncate' => 34, 'hidden' => true],
        ['key' => 'employee_type', 'label' => 'Type', 'format' => 'badge', 'colors' => ['faculty' => 'navy', 'staff' => 'cyan'], 'hidden' => true, 'sortable' => true],
        ['key' => 'to_date', 'label' => 'To', 'format' => 'date', 'hidden' => true, 'sortable' => true],
        ['key' => 'department_name', 'label' => 'Department', 'hidden' => true, 'sortable' => 'd.name'],
        ['key' => 'created_at', 'label' => 'Applied On', 'format' => 'date', 'hidden' => true, 'sortable' => true],
        ['key' => 'approved_by_name', 'label' => 'Decided By', 'hidden' => true],
        ['key' => 'remarks', 'label' => 'Remarks', 'format' => 'truncate', 'truncate' => 40, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'employee_code', 'label' => 'Employee ID'], ['key' => 'employee_name', 'label' => 'Employee'], ['key' => 'employee_type', 'label' => 'Type', 'format' => 'badge'],
        ['key' => 'department_name', 'label' => 'Department'], ['key' => 'leave_type_name', 'label' => 'Leave Type'], ['key' => 'from_date', 'label' => 'From', 'format' => 'date'],
        ['key' => 'to_date', 'label' => 'To', 'format' => 'date'], ['key' => 'days', 'label' => 'Days', 'format' => 'number'], ['key' => 'reason', 'label' => 'Reason'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'approved_by_name', 'label' => 'Decided By'], ['key' => 'approved_at', 'label' => 'Decided On', 'format' => 'datetime'],
        ['key' => 'remarks', 'label' => 'Remarks'], ['key' => 'created_at', 'label' => 'Applied On', 'format' => 'datetime'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => $statuses],
        ['key' => 'leave_type', 'label' => 'Leave type', 'source' => hr_leave_type_source()],
        ['key' => 'employee_type', 'label' => 'Employee', 'options' => ['faculty' => 'Faculty', 'staff' => 'Staff']],
        ['key' => 'department_id', 'label' => 'Department', 'source' => 'departments', 'column' => 'COALESCE(f.department_id, s.department_id)'],
        ['key' => 'period', 'label' => 'Leave dates', 'type' => 'daterange', 'sql' => '1 = 1'],
    ],
    'scopes' => ['employee_type' => 't.employee_type', 'employee_id' => 't.employee_id'],
    'fields' => [
        ['name' => 'employee_ref', 'label' => 'Employee', 'type' => 'combobox', 'required' => true, 'col' => 12, 'readonly_on_edit' => true, 'validate_options' => false,
            'source' => hr_employee_source(), 'placeholder' => 'Search faculty or staff by name / employee ID…'],
        ['name' => 'leave_type', 'label' => 'Leave type', 'type' => 'select', 'required' => true, 'col' => 6, 'source' => hr_leave_type_source()],
        ['name' => 'is_half_day', 'label' => 'Half day', 'type' => 'toggle', 'col' => 6, 'default' => false, 'placeholder' => 'Single-day half leave'],
        ['name' => 'from_date', 'label' => 'From date', 'type' => 'date', 'required' => true, 'col' => 6],
        ['name' => 'to_date', 'label' => 'To date', 'type' => 'date', 'required' => true, 'col' => 6],
        ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'rows' => 3, 'maxlength' => 500],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'form' => false, 'options' => $statuses],
        ['name' => 'remarks', 'label' => 'Approver remarks', 'type' => 'text', 'form' => false],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'export' => true,
    'per_page' => 25,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'list_query' => function (array &$where, array &$args, array $req) {
            $p = $req['f']['period'] ?? null;
            if (is_array($p)) {
                // Leaves overlapping the chosen range
                if (!empty($p['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $p['to'])) {
                    $where[] = 't.from_date <= ?';
                    $args[] = $p['to'];
                }
                if (!empty($p['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $p['from'])) {
                    $where[] = 't.to_date >= ?';
                    $args[] = $p['from'];
                }
            }
        },
        'transform_row' => function (array $row): array {
            $row['days'] = (float) $row['days'];
            $row['is_half_day'] = (int) $row['is_half_day'];
            return $row;
        },
        'validate' => function (array $data, ?int $id, ?array $old, array $input) {
            if ($old) {
                if ($old['status'] !== 'pending') {
                    return ['from_date' => 'Only pending applications can be edited (this one is ' . $old['status'] . ').'];
                }
                $data += ['employee_type' => $old['employee_type'], 'employee_id' => (int) $old['employee_id']];
                foreach (['leave_type', 'from_date', 'to_date', 'is_half_day'] as $k) {
                    if (!array_key_exists($k, $data)) {
                        $data[$k] = $old[$k];
                    }
                }
            } else {
                $ref = hr_parse_ref($input['employee_ref'] ?? ($data['employee_ref'] ?? null));
                $scope = (array) ($input['__scope'] ?? []);
                if (!$ref && !empty($scope['employee_type']) && !empty($scope['employee_id'])) {
                    $ref = [(string) $scope['employee_type'], (int) $scope['employee_id']];
                }
                if (!$ref) {
                    return ['employee_ref' => 'Select the employee.'];
                }
                [$data['employee_type'], $data['employee_id']] = $ref;
            }
            return hr_validate_leave($data, $id);
        },
        'before_save' => function (array $data, ?int $id, ?array $old, array $input) {
            if (!$old) {
                $ref = hr_parse_ref($input['employee_ref'] ?? null);
                $scope = (array) ($input['__scope'] ?? []);
                [$data['employee_type'], $data['employee_id']] = $ref ?: [(string) $scope['employee_type'], (int) $scope['employee_id']];
                $data['status'] = 'pending';
                $data['applied_by'] = user_id();
            }
            $from = $data['from_date'] ?? $old['from_date'];
            $to = $data['to_date'] ?? $old['to_date'];
            $half = array_key_exists('is_half_day', $data) ? !empty($data['is_half_day']) : !empty($old['is_half_day']);
            $data['days'] = hr_working_days($from, $to, $half)['days'];
            $data['is_half_day'] = $half && $from === $to ? 1 : 0;
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old) {
            if ($old) {
                return;
            }
            $leave = hr_leave_row($id);
            $approvers = array_values(array_diff(hr_leave_approver_ids(), [(int) user_id()]));
            if ($leave && $approvers) {
                notify($approvers, 'leave', 'Leave request: ' . $leave['employee_name'],
                    sprintf('%s · %s to %s (%s)', $leave['leave_type_name'], format_date($leave['from_date']), format_date($leave['to_date']), hr_days_label($leave['days'])),
                    'leaves?tab=applications&f.status=pending', 'calendar-clock');
            }
        },
        'before_delete' => function (int $id, array $row) {
            if ($row['status'] === 'approved') {
                return 'Approved leave cannot be deleted. Cancel it instead so the history is kept.';
            }
            return null;
        },
        'bulk_action' => function (string $action, array $ids, array $input) {
            if (!in_array($action, ['approve', 'reject'], true)) {
                throw new CrudException('Unknown bulk action.');
            }
            if (!can('faculty', 'approve')) {
                throw new CrudException('You do not have permission to approve or reject leave applications.');
            }
            $remarks = trim((string) ($input['remarks'] ?? ''));
            if ($action === 'reject' && $remarks === '') {
                throw new CrudValidationException(['remarks' => 'Give a reason for rejecting these applications.']);
            }
            $done = 0;
            $skipped = [];
            foreach ($ids as $id) {
                try {
                    hr_leave_decide((int) $id, $action, $remarks !== '' ? $remarks : null);
                    $done++;
                } catch (CrudException $e) {
                    $skipped[(int) $id] = $e->getMessage();
                }
            }
            $msg = $done . ' application' . ($done === 1 ? '' : 's') . ' ' . ($action === 'approve' ? 'approved' : 'rejected') . '.';
            if ($skipped) {
                $msg .= ' ' . count($skipped) . ' skipped (not pending or insufficient balance).';
            }
            return ['done' => $done, 'skipped' => $skipped, 'message' => $msg];
        },
        'describe' => function (array $row) {
            $p = hr_person($row['employee_type'], (int) $row['employee_id']);
            return sprintf('%s leave of %s (%s to %s)', label_from_key($row['leave_type']), $p['full_name'] ?? '#' . $row['employee_id'], $row['from_date'], $row['to_date']);
        },
    ],
];
