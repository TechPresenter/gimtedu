<?php
/**
 * Contact form page. Page setting {"form": "contact" | "grievance" | "fraud_report"} selects the form:
 *   /contact/              enquiry (general, partnership / CSR, media)      reference PWF-ENQ-...
 *   /grievance-redressal/  grievance with nature, related area, reference   reference PWF-GRV-...
 *   /report-fraud/         report of misuse of the foundation's name         reference PWF-FRD-...
 * Every submission is stored in contact_enquiries (Admin > Enquiries & Grievances), acknowledged by email
 * and notified to the foundation's inbox. Rate limited + honeypot protected.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$form = (string) page_setting($page, 'form', 'contact');
if (!in_array($form, ['contact', 'grievance', 'fraud_report'], true)) {
    $form = 'contact';
}
$self = '/' . $page['slug'] . '/';
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    if (honeypot_triggered()) {
        flash('success', 'Thank you. Your message has been received.');
        redirect($self);
    }
    if (form_rate_limited($form, 5)) {
        flash('error', 'You have sent several messages in the last hour. Please try again later, or write to us at ' . setting('contact_email', 'our email address') . '.');
        redirect($self . '#form');
    }

    $base = ['name' => 'required|min:2|max:120', 'email' => 'required|email', 'phone' => 'phone'];
    if ($form === 'contact') {
        [$data, $errors] = validate($_POST, $base + [
            'enquiry_type' => 'required|in:contact,partnership,media',
            'subject' => 'required|min:3|max:200',
            'message' => 'required|text|min:10|max:3000',
        ], ['enquiry_type' => 'Type of enquiry']);
    } elseif ($form === 'grievance') {
        [$data, $errors] = validate($_POST, $base + [
            'nature' => 'required|in:' . implode(',', array_keys(GRIEVANCE_NATURES)),
            'related_to' => 'required|in:' . implode(',', array_keys(GRIEVANCE_RELATED)),
            'reference' => 'max:60',
            'details' => 'required|text|min:20|max:4000',
        ], ['nature' => 'Nature of grievance', 'related_to' => 'Related to', 'reference' => 'Reference number', 'details' => 'Details']);
        if (empty($errors['reference']) && $data['reference'] !== '' && !preg_match('#^[A-Za-z0-9/\-\s]{3,60}$#', $data['reference'])) {
            $errors['reference'] = 'Please enter the reference number exactly as shown, e.g. PWF-VOL-2026-00012.';
        }
    } else {
        [$data, $errors] = validate($_POST, $base + [
            'what_happened' => 'required|in:' . implode(',', array_keys(FRAUD_WHAT_HAPPENED)),
            'channel' => 'required|in:' . implode(',', array_keys(FRAUD_CHANNELS)),
            'used_contact' => 'max:255',
            'incident_date' => 'date|past',
            'amount' => 'numeric|min:0|max:100000000',
            'amount_status' => 'in:asked,paid',
            'details' => 'required|text|min:20|max:4000',
        ], ['what_happened' => 'What happened', 'channel' => 'How you were contacted', 'used_contact' => 'Number, link or handle used',
            'incident_date' => 'Date', 'amount' => 'Amount', 'amount_status' => 'Amount status', 'details' => 'Details']);
    }
    if ($errors) {
        back_with_errors($errors, $self . '#form', $_POST);
    }

    $phone = $data['phone'] !== '' ? $data['phone'] : null;
    if ($form === 'contact') {
        $type = $data['enquiry_type'];
        $subject = $data['subject'];
        $message = $data['message'];
        $prefix = 'ENQ';
    } elseif ($form === 'grievance') {
        $type = 'grievance';
        $subject = 'Grievance: ' . GRIEVANCE_NATURES[$data['nature']];
        $message = 'Nature of grievance: ' . GRIEVANCE_NATURES[$data['nature']] . "\n"
            . 'Related to: ' . GRIEVANCE_RELATED[$data['related_to']] . "\n"
            . ($data['reference'] !== '' ? 'Reference number: ' . strtoupper($data['reference']) . "\n" : '')
            . "\nDetails:\n" . $data['details'];
        $prefix = 'GRV';
    } else {
        $type = 'fraud_report';
        $subject = 'Fraud report: ' . FRAUD_WHAT_HAPPENED[$data['what_happened']];
        $amount = $data['amount'] !== '' ? (float) $data['amount'] : 0.0;
        $message = 'What happened: ' . FRAUD_WHAT_HAPPENED[$data['what_happened']] . "\n"
            . 'How they contacted: ' . FRAUD_CHANNELS[$data['channel']] . "\n"
            . ($data['used_contact'] !== '' ? 'Number / link / handle used: ' . $data['used_contact'] . "\n" : '')
            . ($data['incident_date'] !== '' ? 'Date: ' . format_date($data['incident_date']) . "\n" : '')
            . ($amount > 0 ? 'Amount ' . ($data['amount_status'] === 'paid' ? 'paid' : 'asked for') . ': ' . money($amount, true) . "\n" : '')
            . "\nDetails:\n" . $data['details'];
        $prefix = 'FRD';
    }

    [$id, $ref] = save_enquiry([
        'type' => $type, 'name' => $data['name'], 'email' => $data['email'], 'phone' => $phone,
        'subject' => mb_substr($subject, 0, 200), 'message' => $message,
    ], $prefix);
    log_activity('form_' . $form, ENQUIRY_TYPES[$type] . ' ' . $ref, current_user_id());
    track_conversion('contact_enquiry', $id);

    $label = ['contact' => 'enquiry', 'partnership' => 'partnership enquiry', 'media' => 'media enquiry', 'grievance' => 'grievance', 'fraud_report' => 'report'][$type];
    $quote = '<blockquote style="margin:16px 0;padding:12px 16px;background:#f5f9f4;border-left:4px solid #146B43">' . e_nl($message) . '</blockquote>';
    if ($type === 'grievance') {
        $ackBody = '<p>Dear ' . e($data['name']) . ',</p><p>Thank you for writing to ' . e(site_name()) . '. Your grievance has been registered and will be reviewed by a responsible member of our team.</p>'
            . '<p>' . e(grievance_timeline_text()) . '</p>';
    } elseif ($type === 'fraud_report') {
        $ackBody = '<p>Dear ' . e($data['name']) . ',</p><p>Thank you for reporting this to ' . e(site_name()) . '. We will look into it and may contact you if we need more details.</p>'
            . '<p><strong>Please remember:</strong> do not make any payment or share an OTP, password, PIN, card or bank details with anyone claiming to represent us. '
            . 'If you have lost money, call the national cyber crime helpline <strong>1930</strong> or report it at <a href="https://cybercrime.gov.in">https://cybercrime.gov.in</a> as soon as possible.</p>';
    } else {
        $ackBody = '<p>Dear ' . e($data['name']) . ',</p><p>Thank you for contacting ' . e(site_name()) . '. We have received your ' . e($label) . ' and will respond as soon as possible.</p>';
    }
    $ackBody .= '<p>Your reference number is <strong style="font-size:17px">' . e($ref) . '</strong>. Please mention it if you write to us about this matter.</p>'
        . '<p><strong>Your message:</strong></p>' . $quote . '<p>Regards,<br>' . e(site_name()) . '</p>';
    send_mail($data['email'], 'We received your ' . $label . ' - ' . $ref, $ackBody);

    if ($type === 'fraud_report') {
        $adminSubject = '[FRAUD REPORT] ' . $ref . ' - ' . FRAUD_WHAT_HAPPENED[$data['what_happened']];
    } elseif ($type === 'grievance') {
        $adminSubject = '[Grievance] ' . $ref . ' - ' . GRIEVANCE_NATURES[$data['nature']];
    } else {
        $adminSubject = ENQUIRY_TYPES[$type] . ' ' . $ref . ': ' . $subject;
    }
    notify_admin($adminSubject,
        ($type === 'fraud_report' ? '<p style="color:#c62828"><strong>Flagged: report of possible fraud or misuse of the foundation\'s name. Please review promptly.</strong></p>' : '')
        . '<p><strong>' . e($data['name']) . '</strong> (' . e($data['email']) . ($phone ? ', ' . e($phone) : '') . ') submitted a ' . e($label) . ' (' . e($ref) . ').</p>'
        . '<p><strong>Subject:</strong> ' . e($subject) . '</p>' . $quote, $data['email']);

    $_SESSION['info_submitted'] = [
        'form' => $form, 'ref' => $ref,
        'title' => ['contact' => 'Thank you - your message has been sent', 'grievance' => 'Your grievance has been registered', 'fraud_report' => 'Thank you for reporting this'][$form],
        'text' => [
            'contact' => 'We have emailed you an acknowledgement and will respond as soon as possible.',
            'grievance' => grievance_timeline_text(),
            'fraud_report' => 'We will look into your report. If you have lost money, call 1930 or report at cybercrime.gov.in as soon as possible.',
        ][$form],
    ];
    flash('success', 'Received. Your reference number is ' . $ref . '.');
    redirect($self . '#form');
}

info_require_validation();
page_header($page);

render_sections($page, function () use ($form, $user, $page) {
    $host = preg_replace('/^www\./', '', (string) parse_url(BASE_URL, PHP_URL_HOST));
    $typePrefill = in_array((string) ($_GET['type'] ?? ''), ['contact', 'partnership', 'media'], true) ? (string) $_GET['type'] : 'contact';
    ?>
    <section class="section contact-section" id="form">
        <div class="container">
            <?= info_submitted_panel($form) ?>
            <div class="contact-layout">
                <aside class="contact-aside">
                    <?php if ($form === 'contact'): ?>
                        <div class="aside-intro">
                            <h2>Get in touch</h2>
                            <p class="muted">Questions about our work, donations, volunteering, partnerships or media? Write to us and our team will respond as soon as possible.</p>
                        </div>
                        <?= contact_cards_html() ?>
                        <?= social_links_html() ?>
                        <div class="contact-shortcuts">
                            <a href="<?= e(page_url('grievance-redressal')) ?>"><?= icon('shield') ?><span><strong>Have a complaint?</strong><small>Raise a grievance</small></span></a>
                            <a href="<?= e(page_url('report-fraud')) ?>"><?= icon('alert-triangle') ?><span><strong>Suspicious request in our name?</strong><small>Report fraud or misuse</small></span></a>
                            <a href="<?= e(page_url('feedback-form')) ?>"><?= icon('star') ?><span><strong>Share feedback</strong><small>Help us improve</small></span></a>
                        </div>
                    <?php elseif ($form === 'grievance'): ?>
                        <div class="aside-card guide-card">
                            <h2 class="h3"><?= icon('list') ?>Before you submit</h2>
                            <ul class="check-list">
                                <li><?= icon('check') ?><span>Your contact details, so that we can reply to you</span></li>
                                <li><?= icon('check') ?><span>The reference number of the donation, receipt or application, if you have one</span></li>
                                <li><?= icon('check') ?><span>What happened, when and where, and who was involved</span></li>
                                <li><?= icon('check') ?><span>Any earlier emails or steps already taken, and the outcome you are seeking</span></li>
                            </ul>
                        </div>
                        <div class="aside-card guide-card">
                            <h2 class="h3"><?= icon('clock') ?>Response time</h2>
                            <p class="small"><?= e(grievance_timeline_text()) ?></p>
                            <p class="small muted mb-0"><?= icon('lock') ?> Grievances are handled confidentially and shared only with the people who need to act on them. Not satisfied with the outcome? Reply to our email quoting your reference number and ask for a further review.</p>
                        </div>
                        <?php if (setting('grievance_officer_name') !== '' || setting('grievance_officer_email') !== ''): ?>
                            <div class="aside-card"><h2 class="h3">Grievance officer</h2>
                                <p class="mb-0"><?= e(setting('grievance_officer_name')) ?><?php if (setting('grievance_officer_email') !== ''): ?><br><a href="mailto:<?= e(setting('grievance_officer_email')) ?>"><?= e(setting('grievance_officer_email')) ?></a><?php endif; ?></p></div>
                        <?php endif; ?>
                        <?= contact_cards_html(true) ?>
                    <?php else: ?>
                        <div class="aside-card safety-card">
                            <h2 class="h3"><?= icon('shield') ?>Stay safe</h2>
                            <ul class="check-list">
                                <li><?= icon('check') ?><span><strong>Do not pay</strong> anyone who contacts you in our name until you have verified the request with us.</span></li>
                                <li><?= icon('check') ?><span><strong>Never share an OTP</strong>, password, PIN, card or bank details - we will never ask for them.</span></li>
                                <li><?= icon('check') ?><span>We <strong>never charge a fee</strong> for jobs, internships, volunteering or certificates.</span></li>
                                <li><?= icon('check') ?><span>Donate only through the <a href="<?= e(page_url('donate')) ?>">Donate page</a> of this website.</span></li>
                                <li><?= icon('check') ?><span>Our official website is <strong><?= e($host) ?></strong>. Verify any request using the contact details on this website.</span></li>
                            </ul>
                        </div>
                        <div class="callout callout-gold"><span class="callout-icon"><?= icon('alert-triangle') ?></span><div><strong>Lost money?</strong>
                            <p>Call the national cyber crime helpline <a href="tel:1930"><b>1930</b></a> immediately and report it at <a href="https://cybercrime.gov.in" target="_blank" rel="noopener">cybercrime.gov.in</a>. Also inform your bank.</p></div></div>
                        <?= contact_cards_html(true) ?>
                    <?php endif; ?>
                </aside>

                <div class="form-card contact-form-card">
                    <?php if ($form === 'contact'): ?>
                        <h2>Send us a message</h2>
                        <p class="muted">Fields marked <span class="req">*</span> are required.</p>
                    <?php elseif ($form === 'grievance'): ?>
                        <h2>Raise a grievance</h2>
                        <p class="muted">Tell us what went wrong. Fields marked <span class="req">*</span> are required.</p>
                    <?php else: ?>
                        <h2>Report fraud or misuse</h2>
                        <p class="muted">Share as much as you can - it helps us warn others and act quickly. Fields marked <span class="req">*</span> are required.</p>
                    <?php endif; ?>
                    <form method="post" action="<?= e(page_url($page['slug'])) ?>" data-validate novalidate>
                        <?= csrf_field() ?><?= honeypot_field() ?>
                        <div class="form-grid">
                            <?php if ($form === 'contact'): ?>
                                <div class="form-group full">
                                    <label for="enquiry_type">Type of enquiry<span class="req">*</span></label>
                                    <select id="enquiry_type" name="enquiry_type" required>
                                        <?= options_html(['contact' => 'General enquiry', 'partnership' => 'Partnership / CSR collaboration', 'media' => 'Media / press'], (string) old('enquiry_type', $typePrefill), true, '') ?>
                                    </select><?= field_error('enquiry_type') ?>
                                </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="name">Full name<span class="req">*</span></label>
                                <input id="name" type="text" name="name" required minlength="2" maxlength="120" autocomplete="name" value="<?= e(old('name', $user['name'] ?? '')) ?>"><?= field_error('name') ?>
                            </div>
                            <div class="form-group">
                                <label for="email">Email<span class="req">*</span></label>
                                <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?= e(old('email', $user['email'] ?? '')) ?>"><?= field_error('email') ?>
                            </div>
                            <div class="form-group">
                                <label for="phone">Mobile number <span class="muted">(optional)</span></label>
                                <input id="phone" type="tel" name="phone" maxlength="15" autocomplete="tel" value="<?= e(old('phone', $user['phone'] ?? '')) ?>"><?= field_error('phone') ?>
                            </div>

                            <?php if ($form === 'contact'): ?>
                                <div class="form-group">
                                    <label for="subject">Subject<span class="req">*</span></label>
                                    <input id="subject" type="text" name="subject" required minlength="3" maxlength="200" value="<?= e(old('subject')) ?>"><?= field_error('subject') ?>
                                </div>
                                <div class="form-group full">
                                    <label for="message">Message<span class="req">*</span></label>
                                    <textarea id="message" name="message" required minlength="10" maxlength="3000" rows="6" data-counter><?= e(old('message')) ?></textarea><?= field_error('message') ?>
                                </div>

                            <?php elseif ($form === 'grievance'): ?>
                                <div class="form-group">
                                    <label for="nature">Nature of grievance<span class="req">*</span></label>
                                    <select id="nature" name="nature" required><?= options_html(GRIEVANCE_NATURES, (string) old('nature')) ?></select><?= field_error('nature') ?>
                                </div>
                                <fieldset class="form-group full radio-set">
                                    <legend>Related to<span class="req">*</span></legend>
                                    <div class="check-group">
                                        <?php foreach (GRIEVANCE_RELATED as $k => $v): ?>
                                            <label class="check-chip"><input type="radio" name="related_to" value="<?= e($k) ?>" required<?= old_checked('related_to', $k) ?>><span><?= e($v) ?></span></label>
                                        <?php endforeach; ?>
                                    </div><?= field_error('related_to') ?>
                                </fieldset>
                                <div class="form-group full">
                                    <label for="reference">Reference number <span class="muted">(optional - donation, receipt or application number)</span></label>
                                    <input id="reference" type="text" name="reference" maxlength="60" placeholder="e.g. PWF-VOL-2026-00012" value="<?= e(old('reference')) ?>"><?= field_error('reference') ?>
                                </div>
                                <div class="form-group full">
                                    <label for="details">Details of your grievance<span class="req">*</span></label>
                                    <textarea id="details" name="details" required minlength="20" maxlength="4000" rows="7" data-counter placeholder="What happened, when, who was involved and what outcome you would like."><?= e(old('details')) ?></textarea><?= field_error('details') ?>
                                </div>

                            <?php else: ?>
                                <div class="form-group">
                                    <label for="what_happened">What happened?<span class="req">*</span></label>
                                    <select id="what_happened" name="what_happened" required><?= options_html(FRAUD_WHAT_HAPPENED, (string) old('what_happened')) ?></select><?= field_error('what_happened') ?>
                                </div>
                                <fieldset class="form-group full radio-set">
                                    <legend>How were you contacted?<span class="req">*</span></legend>
                                    <div class="check-group">
                                        <?php foreach (FRAUD_CHANNELS as $k => $v): ?>
                                            <label class="check-chip"><input type="radio" name="channel" value="<?= e($k) ?>" required<?= old_checked('channel', $k) ?>><span><?= e($v) ?></span></label>
                                        <?php endforeach; ?>
                                    </div><?= field_error('channel') ?>
                                </fieldset>
                                <div class="form-group full">
                                    <label for="used_contact">Phone number, link, email or social media handle used</label>
                                    <input id="used_contact" type="text" name="used_contact" maxlength="255" placeholder="e.g. +91 9xxxxxxxxx, a website address or @handle" value="<?= e(old('used_contact')) ?>"><?= field_error('used_contact') ?>
                                </div>
                                <div class="form-group">
                                    <label for="incident_date">Date it happened</label>
                                    <input id="incident_date" type="date" name="incident_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('incident_date')) ?>"><?= field_error('incident_date') ?>
                                </div>
                                <div class="form-group">
                                    <label for="amount">Amount asked for or paid (₹), if any</label>
                                    <input id="amount" type="number" name="amount" min="0" step="1" inputmode="numeric" value="<?= e(old('amount')) ?>"><?= field_error('amount') ?>
                                </div>
                                <fieldset class="form-group full radio-set">
                                    <legend>Was any money paid?</legend>
                                    <div class="check-group">
                                        <label class="check-chip"><input type="radio" name="amount_status" value="asked"<?= old_checked('amount_status', 'asked') ?>><span>No, it was only asked for</span></label>
                                        <label class="check-chip"><input type="radio" name="amount_status" value="paid"<?= old_checked('amount_status', 'paid') ?>><span>Yes, money was paid</span></label>
                                    </div><?= field_error('amount_status') ?>
                                </fieldset>
                                <div class="form-group full">
                                    <label for="details">Details<span class="req">*</span></label>
                                    <textarea id="details" name="details" required minlength="20" maxlength="4000" rows="7" data-counter placeholder="What was said or sent, the name they used, and anything else that may help."><?= e(old('details')) ?></textarea><?= field_error('details') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-primary btn-lg" type="submit" data-loading="Sending…"><?= icon('send') ?><?= $form === 'contact' ? 'Send message' : ($form === 'grievance' ? 'Submit grievance' : 'Send report') ?></button>
                        </div>
                        <p class="form-note"><?= icon('lock') ?><span>Your details are used only to respond to you and are handled as described in our <a href="<?= e(page_url('privacy-policy')) ?>">privacy policy</a>.</span></p>
                    </form>
                </div>
            </div>
            <?php if ($form === 'contact'): ?><?= map_embed_html() ?><?php endif; ?>
        </div>
    </section>
    <?php
});
page_footer();
