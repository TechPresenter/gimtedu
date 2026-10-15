<?php
/**
 * Handles: contact enquiries, feedback, grievances and newsletter sign-ups.
 * Also handles newsletter unsubscribe links (?unsubscribe=TOKEN).
 */
require_once __DIR__ . '/functions.php';

// Newsletter unsubscribe link from emails
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['unsubscribe'])) {
    $token = (string) $_GET['unsubscribe'];
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        db_query("UPDATE newsletter_subscribers SET status = 'unsubscribed' WHERE unsubscribe_token = ?", [$token]);
    }
    flash('success', 'You have been unsubscribed from our newsletter.');
    redirect('index.php');
}

$type = $_POST['form_type'] ?? 'contact';
$returnTo = safe_next($_POST['return_to'] ?? '', 'contact.php');
csrf_verify($returnTo);

if (honeypot_triggered()) {
    flash('success', 'Thank you. Your message has been received.');
    redirect($returnTo);
}

if ($type === 'newsletter') {
    [$data, $errors] = validate($_POST, ['email' => 'required|email']);
    if ($errors) {
        flash('error', reset($errors));
        redirect($returnTo);
    }
    if (form_rate_limited('newsletter', 10)) {
        flash('error', 'Too many requests. Please try again later.');
        redirect($returnTo);
    }
    log_activity('form_newsletter', '');
    $existing = db_row('SELECT id, status FROM newsletter_subscribers WHERE email = ?', [$data['email']]);
    if ($existing) {
        db_update('newsletter_subscribers', ['status' => 'subscribed'], 'id = :id', ['id' => $existing['id']]);
    } else {
        db_insert('newsletter_subscribers', [
            'email' => $data['email'],
            'name' => current_user()['name'] ?? null,
            'status' => 'subscribed',
            'unsubscribe_token' => bin2hex(random_bytes(32)),
            'ip_address' => client_ip(),
        ]);
    }
    flash('success', 'Thank you for subscribing to our newsletter!');
    redirect($returnTo);
}

if (!in_array($type, ['contact', 'feedback', 'grievance'], true)) {
    $type = 'contact';
}

[$data, $errors] = validate($_POST, [
    'name'    => 'required|min:2|max:120',
    'email'   => 'required|email',
    'phone'   => 'phone',
    'subject' => 'required|min:3|max:200',
    'message' => 'required|text|min:10|max:3000',
], ['message' => 'Message']);

if ($errors) {
    back_with_errors($errors, $returnTo, $_POST);
}
if (form_rate_limited('contact', 8)) {
    flash('error', 'You have sent several messages recently. Please wait a while before sending another.');
    redirect($returnTo);
}

$id = db_insert('contact_enquiries', [
    'type'       => $type,
    'name'       => $data['name'],
    'email'      => $data['email'],
    'phone'      => $data['phone'] ?: null,
    'subject'    => $data['subject'],
    'message'    => $data['message'],
    'status'     => 'new',
    'ip_address' => client_ip(),
]);
$prefix = ['contact' => 'ENQ', 'feedback' => 'FDB', 'grievance' => 'GRV'][$type];
$ref = reference_no($prefix, $id);
db_update('contact_enquiries', ['reference_no' => $ref], 'id = :id', ['id' => $id]);
log_activity('form_contact', $type . ' ' . $ref);

$label = ['contact' => 'enquiry', 'feedback' => 'feedback', 'grievance' => 'grievance'][$type];
send_mail($data['email'], 'We received your ' . $label . ' - ' . $ref,
    '<p>Dear ' . e($data['name']) . ',</p><p>Thank you for contacting ' . e(site_name()) . '. We have received your ' . $label
    . ' and will respond as soon as possible.</p><p>Reference number: <strong>' . e($ref) . '</strong></p>'
    . '<p><strong>Subject:</strong> ' . e($data['subject']) . '</p>');
notify_admin('New ' . $label . ': ' . $data['subject'],
    '<p><strong>' . e($data['name']) . '</strong> (' . e($data['email']) . ') sent a ' . $label . ' (' . e($ref) . ').</p>'
    . '<p>' . e_nl($data['message']) . '</p>', $data['email']);

flash('success', 'Thank you! Your ' . $label . ' has been received. Reference number: ' . $ref);
redirect($returnTo);
