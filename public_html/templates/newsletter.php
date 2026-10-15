<?php
/**
 * Newsletter:
 *  POST  subscribe (footer, sections, this page): email + consent required; consent text, time, source and IP
 *        are stored; re-subscribing updates the record. Responses are generic (no address enumeration).
 *  GET   ?unsubscribe=<64 hex token>  -> confirmation page only (mail scanners and link previews open links):
 *        nothing changes until the visitor presses "Unsubscribe me".
 *  POST  unsubscribe=<token> (that button), or an RFC 8058 one-click POST to the link (List-Unsubscribe=One-Click):
 *        unsubscribed + confirmation email. The 256-bit token is the credential, so no CSRF token is needed.
 *  GET   ?unsubscribed=1 -> result card (changes nothing).
 *  GET   page with its sections and the sign-up form.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$self = '/' . $page['slug'] . '/';

/** Unsubscribe the subscriber of a valid token (no-op when unknown or already unsubscribed). */
$doUnsubscribe = function (string $token): void {
    $row = db_row('SELECT id, email, status FROM newsletter_subscribers WHERE unsubscribe_token = ?', [$token]);
    if ($row && $row['status'] === 'subscribed') {
        db_update('newsletter_subscribers', ['status' => 'unsubscribed', 'name' => null, 'ip_address' => null], 'id = :id', ['id' => $row['id']]);
        log_activity('newsletter_unsubscribed', 'Subscriber #' . $row['id']);
        send_mail($row['email'], 'You have been unsubscribed - ' . site_name(),
            '<p>Namaste,</p><p>You have been unsubscribed from email updates from ' . e(site_name()) . '. You will not receive any more newsletters from us.</p>'
            . '<p>Changed your mind? You can subscribe again at any time on our website:</p><p><a class="btn" href="' . e(abs_page_url('newsletter')) . '">Subscribe again</a></p>');
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unsubToken = post_str('unsubscribe', get_str('unsubscribe'));
    if ($unsubToken !== '' || isset($_POST['List-Unsubscribe'])) {
        if (preg_match('/^[a-f0-9]{64}$/', $unsubToken)) {
            $doUnsubscribe($unsubToken);
        }
        if (post_str('List-Unsubscribe') === 'One-Click') {   // RFC 8058: plain 200, no redirect
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex');
            echo 'Unsubscribed.';
            exit;
        }
        redirect($self . '?unsubscribed=1');
    }
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
    $result = newsletter_subscribe($data['email'], $data['name'] !== '' ? $data['name'] : (current_user()['name'] ?? null), post_str('source', 'website'));
    if ($result['status'] !== 'existing') {
        newsletter_send_confirmation($data['email'], $result['token']);
        track_conversion('newsletter_signup', $result['id']);
    }
    // Same answer whether the address was new or already subscribed.
    flash('success', 'Thank you! You are subscribed. A confirmation email is on its way to you.');
    redirect($back);
}

$unsub = null;
$unsubToken = '';
$unsubMasked = '';
if (isset($_GET['unsubscribed'])) {
    $unsub = 'ok';
} elseif (isset($_GET['unsubscribe'])) {
    $unsubToken = get_str('unsubscribe');
    if (preg_match('/^[a-f0-9]{64}$/', $unsubToken)) {
        // GET never changes anything: a still-subscribed address gets a confirmation button, anything else the generic result
        $row = db_row('SELECT email, status FROM newsletter_subscribers WHERE unsubscribe_token = ?', [$unsubToken]);
        if ($row && $row['status'] === 'subscribed') {
            $unsub = 'confirm';
            [$local, $domain] = array_pad(explode('@', (string) $row['email'], 2), 2, '');
            $unsubMasked = mb_substr($local, 0, 1) . '***@' . $domain;
        } else {
            $unsub = 'ok';
        }
    } else {
        $unsub = 'invalid';
    }
}

if ($unsub !== null) {
    page_header($page, ['title' => $unsub === 'confirm' ? 'Unsubscribe from email updates' : ($unsub === 'ok' ? 'You have been unsubscribed' : 'Unsubscribe link not valid'), 'noindex' => true,
        'canonical' => abs_page_url('newsletter'), 'crumbs' => [['Newsletter', page_url('newsletter')]]]);
    ?>
    <section class="section">
        <div class="container narrow">
            <div class="result-card info-result">
                <?php if ($unsub === 'confirm'): ?>
                    <span class="result-icon pending"><?= icon('mail') ?></span>
                    <h2>Stop our email updates?</h2>
                    <p>Press the button to stop newsletters to <strong><?= e($unsubMasked) ?></strong>. Emails about your own donations, applications or account (for example receipts) are not affected.</p>
                    <form method="post" action="<?= e(page_url('newsletter')) ?>" class="btn-row center">
                        <input type="hidden" name="unsubscribe" value="<?= e($unsubToken) ?>">
                        <button class="btn btn-primary" type="submit"><?= icon('check') ?>Unsubscribe me</button>
                        <a class="btn btn-outline" href="<?= e(url('/')) ?>">Keep me subscribed</a>
                    </form>
                <?php elseif ($unsub === 'ok'): ?>
                    <span class="result-icon success"><?= icon('check-circle') ?></span>
                    <h2>You will not receive further newsletters</h2>
                    <p>If this address was subscribed, it has now been removed from our mailing list and a confirmation has been sent. Emails about your own donations, applications or account (for example receipts) are not affected.</p>
                <?php else: ?>
                    <span class="result-icon pending"><?= icon('alert-circle') ?></span>
                    <h2>We could not read this link</h2>
                    <p>Please open the unsubscribe link exactly as it appears in the email, or write to us at <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a> and we will remove your address.</p>
                <?php endif; ?>
                <?php if ($unsub !== 'confirm'): ?>
                <div class="btn-row center"><a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= icon('home') ?>Go to homepage</a>
                    <?php if ($unsub === 'ok' && newsletter_enabled()): ?><a class="btn btn-outline" href="<?= e(page_url('newsletter')) ?>#subscribe">Subscribe again</a><?php endif; ?></div>
                <?php endif; ?>
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
