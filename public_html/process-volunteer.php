<?php
require_once __DIR__ . '/functions.php';

csrf_verify('apply-volunteer.php');
$back = 'apply-volunteer.php' . (($_POST['application_type'] ?? '') === 'intern' ? '?type=intern' : '');
if (honeypot_triggered()) {
    redirect($back);
}

$req = ['name', 'email', 'phone', 'city', 'state'];
[$data, $errors] = validate($_POST, personal_rules($req) + [
    'application_type' => 'required|in:volunteer,intern',
    'occupation'       => 'max:120',
    'education'        => 'max:150',
    'interests'        => 'array|required|max:12|in:' . implode(',', VOLUNTEER_INTERESTS),
    'availability'     => 'required|in:' . implode(',', AVAILABILITY_OPTIONS),
    'skills'           => 'max:500',
    'experience'       => 'text|max:2000',
    'motivation'       => 'required|text|min:20|max:2000',
    'accept_policy'    => 'accepted',
], ['interests' => 'Areas of interest', 'accept_policy' => 'Volunteer & Child Protection Policy']);

if ($errors) {
    back_with_errors($errors, $back, $_POST);
}
if (form_rate_limited('application', 6)) {
    flash('error', 'Several applications were submitted from your network recently. Please try again later.');
    redirect($back);
}
$duplicate = db_value("SELECT application_no FROM volunteers WHERE email = ? AND application_type = ? AND status IN ('pending','under_review','shortlisted') LIMIT 1",
    [$data['email'], $data['application_type']]);
if ($duplicate) {
    flash('info', 'You already have an application under review (' . $duplicate . '). You can check its status below.');
    redirect('application-status.php');
}

$id = db_insert('volunteers', [
    'application_type' => $data['application_type'],
    'user_id'          => current_user_id(),
    'name'             => $data['name'],
    'email'            => $data['email'],
    'phone'            => $data['phone'],
    'date_of_birth'    => $data['date_of_birth'] ?: null,
    'gender'           => $data['gender'] ?: null,
    'address'          => $data['address'] ?: null,
    'city'             => $data['city'],
    'state'            => $data['state'] ?: null,
    'pincode'          => $data['pincode'] ?: null,
    'occupation'       => $data['occupation'] ?: null,
    'education'        => $data['education'] ?: null,
    'interests'        => implode(', ', $data['interests']),
    'availability'     => $data['availability'],
    'skills'           => $data['skills'] ?: null,
    'experience'       => $data['experience'] ?: null,
    'motivation'       => $data['motivation'],
    'ip_address'       => client_ip(),
]);
$ref = reference_no($data['application_type'] === 'intern' ? 'INT' : 'VOL', $id);
db_update('volunteers', ['application_no' => $ref], 'id = :id', ['id' => $id]);
log_activity('form_application', 'Volunteer application ' . $ref, null, current_user_id());

$label = $data['application_type'] === 'intern' ? 'Internship application' : 'Volunteer application';
send_application_ack($data['email'], $data['name'], $label, $ref);
notify_admin('New ' . strtolower($label) . ' ' . $ref, '<p>' . e($data['name']) . ' (' . e($data['email']) . ', ' . e($data['city']) . ') has applied. Interests: ' . e(implode(', ', $data['interests'])) . '</p>');

flash('success', 'Thank you! Your application has been submitted. Your application number is ' . $ref . '.');
redirect('application-status.php?ref=' . urlencode($ref));
