<?php
require_once __DIR__ . '/functions.php';

csrf_verify('apply-teacher.php');
if (honeypot_triggered()) {
    redirect('apply-teacher.php');
}
$subjects = ['Hindi', 'English', 'Mathematics', 'Science', 'Social Studies', 'Computer Basics', 'Spoken English', 'Arts & Crafts', 'Life Skills', 'Adult Literacy'];

[$data, $errors] = validate($_POST, personal_rules(['name', 'email', 'phone', 'city', 'state']) + [
    'qualification'       => 'required|min:2|max:150',
    'teaching_experience' => 'in:None,Less than 1 year,1-3 years,3-5 years,More than 5 years',
    'subjects'            => 'array|required|in:' . implode(',', $subjects),
    'classes_preferred'   => 'max:100',
    'languages'           => 'max:150',
    'teaching_mode'       => 'required|in:in_person,online,both',
    'availability'        => 'required|in:' . implode(',', AVAILABILITY_OPTIONS),
    'motivation'          => 'required|text|min:20|max:2000',
    'accept_policy'       => 'accepted',
], ['accept_policy' => 'Child Protection Policy']);

if (!$errors && form_rate_limited('application', 6)) {
    flash('error', 'Several applications were submitted from your network recently. Please try again later.');
    redirect('apply-teacher.php');
}
$upload = [];
if (!$errors) {
    $upload = handle_upload('resume', 'document', 'documents/teachers');
    if (isset($upload['error'])) {
        $errors['resume'] = $upload['error'];
    }
}
if ($errors) {
    back_with_errors($errors, 'apply-teacher.php', $_POST);
}

$id = db_insert('teacher_applications', [
    'user_id'             => current_user_id(),
    'name'                => $data['name'],
    'email'               => $data['email'],
    'phone'               => $data['phone'],
    'date_of_birth'       => $data['date_of_birth'] ?: null,
    'gender'              => $data['gender'] ?: null,
    'address'             => $data['address'] ?: null,
    'city'                => $data['city'],
    'state'               => $data['state'] ?: null,
    'pincode'             => $data['pincode'] ?: null,
    'qualification'       => $data['qualification'],
    'subjects'            => implode(', ', $data['subjects']),
    'classes_preferred'   => $data['classes_preferred'] ?: null,
    'languages'           => $data['languages'] ?: null,
    'teaching_experience' => $data['teaching_experience'] ?: null,
    'teaching_mode'       => $data['teaching_mode'],
    'availability'        => $data['availability'],
    'resume_path'         => $upload['path'] ?? null,
    'motivation'          => $data['motivation'],
    'ip_address'          => client_ip(),
]);
$ref = reference_no('TCH', $id);
db_update('teacher_applications', ['application_no' => $ref], 'id = :id', ['id' => $id]);
log_activity('form_application', 'Teacher application ' . $ref, null, current_user_id());

send_application_ack($data['email'], $data['name'], 'Volunteer teacher application', $ref);
notify_admin('New volunteer teacher application ' . $ref, '<p>' . e($data['name']) . ' (' . e($data['email']) . ') applied to teach ' . e(implode(', ', $data['subjects'])) . '.</p>');

flash('success', 'Thank you! Your application has been submitted. Your application number is ' . $ref . '.');
redirect('application-status.php?ref=' . urlencode($ref));
