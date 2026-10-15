<?php
/**
 * Newsletter:
 *  POST  subscribe (footer, sections, this page): email + consent required; consent text, time, source and IP
 *        are stored; re-subscribing updates the record. Responses are generic (no address enumeration).
 *  GET   ?unsubscribe=<64 hex token>  -> unsubscribed + confirmation email.
 *  GET   page with its sections and the sign-up form.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$self = '/' . $page['slug'] . '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Return to the page the form was on (same site only)
    $back = $self;
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '' && strtolower((string) parse_url($ref, PHP_URL_HOST)) === strtolower((string) parse_url(BASE_URL, PHP_URL_HOST))) {
        $path = (string) parse_url($ref, PHP_URL_PATH);
        $base = base_path();
        if ($base !== '' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }
        $back = safe_next($path, $self);
    }
    csrf_verify($back);
    if (honeypot_triggered()) {
        flash('success', 'Thank you. Please check your inbox for a confirmation email.');
        redirect($back);
    }
    if (!newsletter_enabled()) {
        flash('info', 'Newsletter sign-up is closed at the moment. Please follow our updates on this website.');
        redirect($back);
    }
    if (form_rate_limited('newsletter', 8)) {
        flash('error', 'Too many sign-up attempts from your network. Please try again later.');
        redirect($back);
    }
    [$data, $errors] = validate($_POST, ['email' => 'required|email', 'name' => 'max:120', 'consent' => 'accepted'],
        ['consent' => 'I agree to receive email updates']);
    if ($errors) {
        if ($back === $self) {
            back_with_errors($errors, $self . '#subscribe', $_POST);
        }
        flash('error', isset($errors['consent']) ? 'Please tick the consent box to subscribe.' : reset($errors));
        redirect($back);
    }
    log_activity('form_newsletter', 'Newsletter sign-up');
    $result = newsletter_subscribe($data['email'], $data['name'] !== '' ? $data['name'] : (current_user()['name'] ?? null), (string) ($_POST['source'] ?? 'website'));
    if ($result['status'] !== 'existing') {
        newsletter_send_confirmation($data['email'], $result['token']);
        track_conversion('newsletter_signup', $result['id']);
    }
    // Same answer whether the address was new or already subscribed.
    flash('success', 'Thank you! You are subscribed. A confirmation email is on its way to you.');
    redirect($back);
}

$unsub = null;
if (isset($_GET['unsubscribe'])) {
    $token = (string) $_GET['unsubscribe'];
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        $row = db_row('SELECT id, email, status FROM newsletter_subscribers WHERE unsubscribe_token = ?', [$token]);
        if ($row && $row['status'] === 'subscribed') {
            db_update('newsletter_subscribers', ['status' => 'unsubscribed'], 'id = :id', ['id' => $row['id']]);
            log_activity('newsletter_unsubscribed', 'Subscriber #' . $row['id']);
            send_mail($row['email'], 'You have been unsubscribed - ' . site_name(),
                '<p>Namaste,</p><p>You have been unsubscribed from email updates from ' . e(site_name()) . '. You will not receive any more newsletters from us.</p>'
                . '<p>Changed your mind? You can subscribe again at any time on our website:</p><p><a class="btn" href="' . e(abs_page_url('newsletter')) . '">Subscribe again</a></p>');
        }
        $unsub = 'ok';
    } else {
        $unsub = 'invalid';
    }
}

if ($unsub !== null) {
    page_header($page, ['title' => $unsub === 'ok' ? 'You have been unsubscribed' : 'Unsubscribe link not valid', 'noindex' => true,
        'canonical' => abs_page_url('newsletter'), 'crumbs' => [['Newsletter', page_url('newsletter')]]]);
    ?>
    <section class="section">
        <div class="container narrow">
            <div class="result-card info-result">
                <?php if ($unsub === 'ok'): ?>
                    <span class="result-icon success"><?= icon('check-circle') ?></span>
                    <h2>You will not receive further newsletters</h2>
                    <p>If this address was subscribed, it has now been removed from our mailing list and a confirmation has been sent. Emails about your own donations, applications or account (for example receipts) are not affected.</p>
                <?php else: ?>
                    <span class="result-icon pending"><?= icon('alert-circle') ?></span>
                    <h2>We could not read this link</h2>
                    <p>Please open the unsubscribe link exactly as it appears in the email, or write to us at <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a> and we will remove your address.</p>
                <?php endif; ?>
                <div class="btn-row center"><a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= icon('home') ?>Go to homepage</a>
                    <?php if ($unsub === 'ok' && newsletter_enabled()): ?><a class="btn btn-outline" href="<?= e(page_url('newsletter')) ?>#subscribe">Subscribe again</a><?php endif; ?></div>
            </div>
        </div>
    </section>
    <?php
    page_footer();
    return;
}

info_require_validation();
page_header($page);
render_sections($page, function () {
    ?>
    <section class="section newsletter-section" id="subscribe">
        <div class="container">
            <div class="nl-layout">
                <div class="nl-info">
                    <span class="pill">Stay connected</span>
                    <h2>What you will receive</h2>
                    <?= ui_checklist([
                        'Updates on our programmes in education, health, women empowerment, environment and skills',
                        'Upcoming events, camps, drives and awareness days',
                        'Campaign updates and how funds are being used',
                        'Opportunities to volunteer, teach or intern with us',
                    ], 1) ?>
                    <p class="muted small">We write occasionally - never daily. Your email address is never sold or shared. Every email has a one-click unsubscribe link.</p>
                </div>
                <div class="form-card">
                    <?php if (newsletter_enabled()): ?>
                        <h2 class="h3">Subscribe by email</h2>
                        <?= newsletter_form_html('newsletter_page', 'nlp', true) ?>
                    <?php else: ?>
                        <?= empty_state('Newsletter sign-up is closed at the moment. Please follow our updates on this website.', 'Read our latest updates', '/blog/') ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
    <?php
});
page_footer();
