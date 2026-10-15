<?php
require_once __DIR__ . '/functions.php';

csrf_verify('apply-staff.php');
if (honeypot_triggered()) {
    redirect('apply-staff.php');
}
$positions = array_merge(array_column(staff_positions(), 'title'), ['General application']);

[$data, $errors] = validate($_POST, personal_rules(['name', 'email', 'phone', 'city', 'state']) + [
    'position'             => 'required|max:120',
    'qualification'        => 'required|min:2|max:150',
    'experience_years'     => 'required|numeric|min:0|max:50',
    'current_organization' => 'max:150',
    'notice_period'        => 'in:Immediate,15 days,1 month,2 months,3 months',
    'cover_letter'         => 'required|text|min:50|max:4000',
    'declaration'          => 'accepted',
], ['experience_years' => 'Experience', 'declaration' => 'Declaration']);

if (!isset($errors['position']) && !in_array($data['position'], $positions, true)) {
    $errors['position'] = 'Please choose a position from the list.';
}
if (!$errors && form_rate_limited('application', 6)) {
    flash('error', 'Several applications were submitted from your network recently. Please try again later.');
    redirect('apply-staff.php');
}
$upload = [];
if (!$errors) {
    $upload = handle_upload('resume', 'pdf', 'documents/staff', true);
    if (isset($upload['error'])) {
        $errors['resume'] = $upload['error'];
    }
}
if ($errors) {
    back_with_errors($errors, 'apply-staff.php', $_POST);
}

$id = db_insert('staff_applications', [
    'user_id'              => current_user_id(),
    'position'             => $data['position'],
    'name'                 => $data['name'],
    'email'                => $data['email'],
    'phone'                => $data['phone'],
    'date_of_birth'        => $data['date_of_birth'] ?: null,
    'gender'               => $data['gender'] ?: null,
    'address'              => $data['address'] ?: null,
    'city'                 => $data['city'],
    'state'                => $data['state'] ?: null,
    'pincode'              => $data['pincode'] ?: null,
    'qualification'        => $data['qualification'],
    'experience_years'     => (float) $data['experience_years'],
    'current_organization' => $data['current_organization'] ?: null,
    'notice_period'        => $data['notice_period'] ?: null,
    'resume_path'          => $upload['path'],
    'cover_letter'         => $data['cover_letter'],
    'ip_address'           => client_ip(),
]);
$ref = reference_no('STF', $id);
db_update('staff_applications', ['application_no' => $ref], 'id = :id', ['id' => $id]);
log_activity('form_application', 'Staff application ' . $ref, null, current_user_id());

send_application_ack($data['email'], $data['name'], 'Job application', $ref);
notify_admin('New job application ' . $ref . ' - ' . $data['position'], '<p>' . e($data['name']) . ' (' . e($data['email']) . ') applied for <strong>' . e($data['position']) . '</strong>.</p>');

flash('success', 'Thank you! Your application has been submitted. Your application number is ' . $ref . '.');
redirect('application-status.php?ref=' . urlencode($ref));
