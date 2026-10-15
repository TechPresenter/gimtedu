<?php
require_once __DIR__ . '/functions.php';

csrf_verify('scholarship.php');
if (honeypot_triggered()) {
    redirect('scholarship.php');
}

$rules = personal_rules(['email', 'phone', 'address', 'city', 'state', 'date_of_birth']);
unset($rules['name']);
[$data, $errors] = validate($_POST, $rules + [
    'student_name'         => 'required|min:2|max:120',
    'guardian_name'        => 'required|min:2|max:120',
    'institution'          => 'required|min:3|max:200',
    'course_class'         => 'required|min:1|max:120',
    'academic_year'        => 'required|max:20',
    'last_exam_percentage' => 'numeric|min:0|max:100',
    'annual_family_income' => 'required|numeric|min:0|max:100000000',
    'reason'               => 'required|text|min:30|max:3000',
    'declaration'          => 'accepted',
], ['annual_family_income' => 'Annual family income', 'last_exam_percentage' => 'Last exam result', 'date_of_birth' => 'Date of birth']);

if (!$errors && form_rate_limited('application', 6)) {
    flash('error', 'Several applications were submitted from your network recently. Please try again later.');
    redirect('scholarship.php');
}
$upload = [];
if (!$errors) {
    $upload = handle_upload('document', 'document', 'documents/scholarships', true);
    if (isset($upload['error'])) {
        $errors['document'] = $upload['error'];
    }
}
if ($errors) {
    back_with_errors($errors, 'scholarship.php', $_POST);
}

$id = db_insert('scholarships', [
    'user_id'              => current_user_id(),
    'student_name'         => $data['student_name'],
    'guardian_name'        => $data['guardian_name'],
    'email'                => $data['email'],
    'phone'                => $data['phone'],
    'date_of_birth'        => $data['date_of_birth'] ?: null,
    'gender'               => $data['gender'] ?: null,
    'address'              => $data['address'],
    'city'                 => $data['city'],
    'state'                => $data['state'] ?: null,
    'pincode'              => $data['pincode'] ?: null,
    'institution'          => $data['institution'],
    'course_class'         => $data['course_class'],
    'academic_year'        => $data['academic_year'],
    'last_exam_percentage' => $data['last_exam_percentage'] !== '' ? (float) $data['last_exam_percentage'] : null,
    'annual_family_income' => (float) $data['annual_family_income'],
    'reason'               => $data['reason'],
    'document_path'        => $upload['path'],
    'ip_address'           => client_ip(),
]);
$ref = reference_no('SCH', $id);
db_update('scholarships', ['application_no' => $ref], 'id = :id', ['id' => $id]);
log_activity('form_application', 'Scholarship application ' . $ref, null, current_user_id());

send_application_ack($data['email'], $data['student_name'], 'Scholarship application', $ref);
notify_admin('New scholarship application ' . $ref, '<p>' . e($data['student_name']) . ' (' . e($data['course_class']) . ', ' . e($data['institution']) . ').</p>');

flash('success', 'Your scholarship application has been submitted. Your application number is ' . $ref . '.');
redirect('application-status.php?ref=' . urlencode($ref));
