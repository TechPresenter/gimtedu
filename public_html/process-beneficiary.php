<?php
require_once __DIR__ . '/functions.php';

csrf_verify('request-help.php');
if (honeypot_triggered()) {
    redirect('request-help.php');
}

[$data, $errors] = validate($_POST, personal_rules(['name', 'phone', 'address', 'city', 'state']) + [
    'age'            => 'integer|min:0|max:120',
    'family_members' => 'integer|min:1|max:30',
    'annual_income'  => 'numeric|min:0|max:100000000',
    'help_category'  => 'required|in:' . implode(',', array_keys(HELP_CATEGORIES)),
    'description'    => 'required|text|min:30|max:3000',
    'consent'        => 'accepted',
], ['help_category' => 'Type of support', 'consent' => 'Consent']);

if (!$errors && form_rate_limited('application', 6)) {
    flash('error', 'Several requests were submitted from your network recently. Please try again later.');
    redirect('request-help.php');
}
$upload = [];
if (!$errors) {
    $upload = handle_upload('document', 'document', 'documents/beneficiaries');
    if (isset($upload['error'])) {
        $errors['document'] = $upload['error'];
    }
}
if ($errors) {
    back_with_errors($errors, 'request-help.php', $_POST);
}

$id = db_insert('beneficiaries', [
    'user_id'        => current_user_id(),
    'name'           => $data['name'],
    'email'          => $data['email'] ?: null,
    'phone'          => $data['phone'],
    'gender'         => $data['gender'] ?: null,
    'age'            => $data['age'] !== '' ? (int) $data['age'] : null,
    'address'        => $data['address'],
    'city'           => $data['city'],
    'state'          => $data['state'] ?: null,
    'pincode'        => $data['pincode'] ?: null,
    'family_members' => $data['family_members'] !== '' ? (int) $data['family_members'] : null,
    'annual_income'  => $data['annual_income'] !== '' ? (float) $data['annual_income'] : null,
    'help_category'  => $data['help_category'],
    'description'    => $data['description'],
    'document_path'  => $upload['path'] ?? null,
    'consent'        => 1,
    'ip_address'     => client_ip(),
]);
$ref = reference_no('BEN', $id);
db_update('beneficiaries', ['reference_no' => $ref], 'id = :id', ['id' => $id]);
log_activity('form_application', 'Help request ' . $ref, null, current_user_id());

if ($data['email']) {
    send_application_ack($data['email'], $data['name'], 'Request for help', $ref);
}
notify_admin('New help request ' . $ref . ' (' . HELP_CATEGORIES[$data['help_category']] . ')', '<p>' . e($data['name']) . ', ' . e($data['city']) . ' - ' . e(excerpt($data['description'], 300)) . '</p>');

flash('success', 'Your request has been submitted. Your reference number is ' . $ref . '. Please keep it safe to check the status.');
redirect('application-status.php?ref=' . urlencode($ref));
