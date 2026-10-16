<?php
/**
 * Donate (/donate/): donation form -> pending donation + gateway order (Razorpay or Cashfree, chosen by the donor
 * when both are offered) -> payment step (Razorpay Checkout / Cashfree checkout modal) -> server-side verification
 * (payment fetched from the gateway API + amount/order/currency match).
 *
 * GET  /donate/?campaign=ID&amount=N      form (prefilled)
 * POST /donate/ action=create              validate, save pending donation, create gateway order, redirect to payment step
 * GET  /donate/?pay=REF&token=TOKEN        payment step (opens the gateway checkout)
 * POST /donate/ action=verify              Razorpay: verify the checkout response, mark success, redirect to /donation-success/
 * POST /donate/ action=failed              Razorpay: record a failed attempt, redirect to /donation-failed/
 * POST /donate/ action=cf_verify           Cashfree: ask the Cashfree API about the order after the checkout closes
 * GET  /donate/?cf_order=ORDER_ID          Cashfree return URL (after a redirect-based payment)
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$self = page_url('donate');
$user = current_user();

// ---------------------------------------------------------------------
// POST handlers (before any output)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action', 'create');

    if ($action === 'cf_verify') {
        $donation = donation_by_ref_token(post_str('ref'), post_str('token'));
        if (!$donation || donation_gateway($donation) !== 'cashfree' || $donation['payment_method'] !== 'online') {
            not_found();
        }
        csrf_verify(donation_success_url($donation));
        if ($donation['status'] === 'success' || $donation['status'] === 'refunded') {
            redirect(donation_success_url($donation));
        }
        $check = cashfree_confirm_donation($donation, 'checkout');
        if ($check['state'] === 'paid' || $check['state'] === 'pending') {
            redirect(donation_success_url($donation));   // "thank you" or an honest "confirming" page
        }
        if ($check['state'] === 'failed') {
            redirect(donation_failed_url($donation));
        }
        if ($check['state'] === 'none') {
            flash('info', 'The payment window was closed before a payment was made. Nothing has been charged - you can try again.');
            redirect(donate_url(['pay' => $donation['donation_ref'], 'token' => $donation['access_token'], 'closed' => 1]));
        }
        log_activity('payment_check_unavailable', 'Donation ' . $donation['donation_ref'] . ': Cashfree could not be asked after checkout');
        redirect(donation_success_url($donation));   // the webhook or an admin re-check will confirm it
    }

    if ($action === 'verify' || $action === 'failed') {
        $donation = donation_by_ref_token(post_str('ref'), post_str('token'));
        if (!$donation || donation_gateway($donation) !== 'razorpay') {
            not_found();
        }
        if ($action === 'failed') {
            csrf_verify(donation_failed_url($donation));
            if ($donation['status'] === 'pending') {
                $reason = mb_substr(clean_string($_POST['error_description'] ?? ''), 0, 250);
                mark_donation_failed((int) $donation['id'], $reason !== '' ? $reason : 'Payment was not completed');
            }
            redirect(donation_failed_url($donation));
        }

        // action = verify. If the session token expired the visitor sees the "confirming" page;
        // the webhook or an admin re-check will still confirm a captured payment.
        csrf_verify(donation_success_url($donation));
        if ($donation['status'] === 'success') {
            redirect(donation_success_url($donation));
        }
        $paymentId = post_str('razorpay_payment_id');
        $orderId = post_str('razorpay_order_id');
        $signature = post_str('razorpay_signature');
        if ($donation['status'] === 'refunded' || $orderId === '' || $orderId !== (string) $donation['gateway_order_id']
            || !preg_match('/^pay_[A-Za-z0-9]{6,40}$/', $paymentId) || !razorpay_checkout_signature_valid($orderId, $paymentId, $signature)) {
            log_activity('payment_signature_invalid', 'Donation ' . $donation['donation_ref'] . ' - checkout response rejected');
            flash('error', 'We could not verify this payment. If money was deducted, it will be confirmed automatically once our payment gateway reports it, or refunded by your bank.');
            redirect(donation_failed_url($donation));
        }
        // Signature is valid - now confirm the payment itself with Razorpay (capture if only authorised).
        $payment = razorpay_verify_payment_for_donation($paymentId, $donation);
        if (!$payment) {
            log_activity('payment_not_captured', 'Donation ' . $donation['donation_ref'] . ' payment ' . $paymentId . ' not captured / not matching yet');
            redirect(donation_success_url($donation));   // shows "pending verification"
        }
        $payment['signature'] = $signature;
        mark_donation_success((int) $donation['id'], $payment, 'checkout');
        redirect(donation_success_url($donation));
    }

    // action = create
    csrf_verify($self);
    if (honeypot_triggered()) {
        log_activity('form_donation_spam', 'Honeypot triggered');
        redirect($self);
    }
    if (!online_donations_available()) {
        flash('error', bank_transfer_details()
            ? 'Online donations are not available at the moment. Please see the other ways to donate below.'
            : 'Online donations are not available at the moment. Please contact us and our team will share the current ways to donate.');
        redirect($self);
    }
    if (form_rate_limited('donation', 15)) {
        flash('error', 'Too many donation attempts from your network in the last hour. Please try again a little later.');
        redirect($self);
    }

    $gateways = active_gateways();
    $gateway = isset($_POST['gateway']) ? post_str('gateway') : default_gateway();
    $min = donation_min_amount();
    [$data, $errors] = validate($_POST, [
        'amount'        => 'required|numeric|min:' . $min . '|max:' . DONATION_MAX_AMOUNT,
        'campaign_id'   => 'integer',
        'donor_name'    => 'required|min:2|max:120',
        'donor_email'   => 'required|email',
        'donor_phone'   => 'required|phone',
        'donor_pan'     => 'pan',
        'donor_address' => 'min:5|max:255',
        'message'       => 'text|max:500',
        'citizen_declaration' => 'accepted',
        'consent'       => 'accepted',
    ], ['amount' => 'Amount', 'campaign_id' => 'Cause', 'donor_name' => 'Full name', 'donor_email' => 'Email', 'donor_phone' => 'Mobile number',
        'donor_pan' => 'PAN', 'donor_address' => 'Address', 'citizen_declaration' => 'declaration that you are an Indian citizen donating from your own funds',
        'consent' => 'agreement to the Privacy Policy and the Donation & Refund Policy']);

    $campaign = null;
    if (!isset($errors['campaign_id']) && $data['campaign_id'] !== '' && (int) $data['campaign_id'] > 0) {
        $campaign = db_row("SELECT * FROM campaigns WHERE id = ? AND status = 'active'", [(int) $data['campaign_id']]);
        if (!$campaign || !campaign_accepts_donations($campaign)) {
            $errors['campaign_id'] = 'This campaign is no longer accepting donations. Please choose another cause or "Where it is needed most".';
        }
    }
    if (!in_array($gateway, $gateways, true)) {
        $errors['gateway'] = 'Please choose one of the payment options shown.';
    } elseif ($gateway === 'cashfree' && !isset($errors['donor_phone'])) {
        // Cashfree needs a 10-digit Indian mobile number for every payment
        $mobile = indian_mobile($data['donor_phone']);
        if ($mobile === '') {
            $errors['donor_phone'] = 'Cashfree needs a 10-digit Indian mobile number, e.g. 98765 43210.';
        } else {
            $data['donor_phone'] = $mobile;
        }
    }
    if ($errors) {
        back_with_errors($errors, $self . '#donation-form', $_POST);
    }
    log_activity('form_donation', 'Donation started', current_user_id());

    $amount = round((float) $data['amount'], 2);
    $ref = new_donation_ref('PWFD');
    $donationId = db_insert('donations', [
        'donation_ref'   => $ref,
        'access_token'   => bin2hex(random_bytes(32)),
        'user_id'        => current_user_id(),
        'campaign_id'    => $campaign ? (int) $campaign['id'] : null,
        'donor_name'     => $data['donor_name'],
        'donor_email'    => $data['donor_email'],
        'donor_phone'    => $data['donor_phone'],
        'donor_pan'      => $data['donor_pan'] !== '' ? $data['donor_pan'] : null,
        'donor_address'  => $data['donor_address'] !== '' ? $data['donor_address'] : null,
        'amount'         => $amount,
        'currency'       => 'INR',
        'is_anonymous'   => !empty($_POST['is_anonymous']) ? 1 : 0,
        'message'        => $data['message'] !== '' ? $data['message'] : null,
        'payment_method' => 'online',
        'gateway'        => $gateway,
        'status'         => 'pending',
        'admin_notes'    => '[' . date('d M Y H:i') . '] Donor declared: Indian citizen, own funds, Indian payment method (no foreign contribution).',
        'ip_address'     => client_ip(),
    ]);
    track_conversion('donation_started', $donationId);

    $donation = db_row('SELECT * FROM donations WHERE id = ?', [$donationId]);
    $session = null;
    if ($gateway === 'cashfree') {
        $cf = cashfree_create_order($donation, $campaign['title'] ?? '');
        $orderId = $cf['order_id'] ?? null;
        $session = $cf['session'] ?? null;
    } else {
        $orderId = razorpay_create_order($donation, $campaign['title'] ?? '');
    }
    if (!$orderId) {
        mark_donation_failed($donationId, 'Payment could not be started (gateway unavailable)');
        $_SESSION['_old'] = array_diff_key($_POST, ['csrf_token' => 1, 'action' => 1]);
        flash('error', 'We could not connect to the payment gateway. Nothing has been charged - please try again in a few minutes.');
        redirect($self . '#donation-form');
    }
    db_update('donations', ['gateway_order_id' => $orderId, 'gateway_session_id' => $session], 'id = :id', ['id' => $donationId]);
    $donation['gateway_order_id'] = $orderId;
    if ($gateway === 'cashfree') {
        cashfree_remember_order($orderId);
    }
    redirect(donation_pay_url($donation));
}

// ---------------------------------------------------------------------
// Step 2: payment page for a donation that already has a gateway order
// ---------------------------------------------------------------------

// Cashfree return URL (?cf_order=ORDER_ID): confirm with the API. Only the browser that started the payment (or the
// signed-in donor) is sent on to the private status page; anyone else sees the bare status without personal details.
if (isset($_GET['cf_order'])) {
    $orderId = get_str('cf_order');
    $donation = cashfree_order_id_valid($orderId)
        ? db_row("SELECT * FROM donations WHERE gateway = 'cashfree' AND payment_method = 'online' AND gateway_order_id = ?", [$orderId]) : null;
    if (!$donation) {
        not_found();
    }
    if (in_array($donation['status'], ['pending', 'failed'], true) && cashfree_configured() && !form_rate_limited('cf_return', 60)) {
        log_activity('form_cf_return', 'Cashfree return ' . $donation['donation_ref']);
        cashfree_confirm_donation($donation, 'checkout');
        $donation = db_row('SELECT * FROM donations WHERE id = ?', [(int) $donation['id']]);
    }
    $own = cashfree_order_in_session($orderId) || (is_logged_in() && (int) $donation['user_id'] === (int) current_user_id());
    if ($own) {
        redirect($donation['status'] === 'failed' ? donation_failed_url($donation) : donation_success_url($donation));
    }
    header('X-Robots-Tag: noindex');
    page_header($page, ['title' => 'Donation status', 'banner' => false, 'noindex' => true, 'canonical' => abs_page_url('donate')]);
    $states = [
        'success'  => ['success', 'check-circle', 'Thank you - your donation has been received', 'The payment has been verified with Cashfree. The receipt link has been emailed to the address given with the donation.'],
        'pending'  => ['pending', 'clock', 'We are confirming your payment', 'This usually takes less than a minute. The receipt is emailed as soon as the payment is confirmed.'],
        'failed'   => ['failed', 'alert-circle', 'The payment was not completed', 'No donation has been recorded. If money was deducted, it will be confirmed automatically once Cashfree reports it, or refunded by your bank.'],
        'refunded' => ['failed', 'refresh', 'This donation was refunded', 'The refund goes back to the original payment method as per the bank\'s usual timelines.'],
    ];
    [$tone, $ic, $title, $text] = $states[$donation['status']] ?? $states['pending'];
    ?>
    <section class="section fr-result-section">
        <div class="container narrow">
            <div class="form-card result-card fr-result" role="status">
                <span class="result-icon <?= e($tone) ?>"><?= icon($ic) ?></span>
                <h1><?= e($title) ?></h1>
                <p class="lead">Donation reference <strong><?= e($donation['donation_ref']) ?></strong></p>
                <p class="muted"><?= e($text) ?></p>
                <p class="small muted">For your privacy the donation details open only in the browser used to donate, or in your account if you were signed in.</p>
                <div class="btn-row center">
                    <?php if (is_logged_in()): ?><a class="btn btn-primary" href="<?= e(member_url('donations')) ?>"><?= icon('heart') ?>My donations</a><?php endif; ?>
                    <a class="btn btn-outline" href="<?= e(page_url('contact')) ?>"><?= icon('mail') ?>Contact us</a>
                </div>
            </div>
        </div>
    </section>
    <?php
    page_footer();
    return;
}

if (isset($_GET['pay'])) {
    $donation = donation_by_ref_token(get_str('pay'), get_str('token'));
    if (!$donation) {
        not_found();
    }
    if ($donation['status'] === 'success') {
        redirect(donation_success_url($donation));
    }
    $gateway = donation_gateway($donation);
    if (!$donation['gateway_order_id'] || !gateway_active($gateway) || $donation['status'] === 'refunded' || $donation['payment_method'] !== 'online') {
        flash('error', 'This payment can no longer be completed. Please start a new donation.');
        redirect($self);
    }
    $cfSession = '';
    if ($gateway === 'cashfree') {
        $cfPay = cashfree_payment_session($donation);
        if ($cfPay && $cfPay['paid']) {
            cashfree_confirm_donation($donation, 'checkout');
            redirect(donation_success_url($donation));
        }
        if (!$cfPay) {
            flash('error', 'We could not connect to Cashfree. Nothing has been charged - please try again in a few minutes.');
            redirect(donation_failed_url($donation));
        }
        $donation = db_row('SELECT * FROM donations WHERE id = ?', [(int) $donation['id']]);
        $cfSession = $cfPay['session'];
        cashfree_remember_order((string) $donation['gateway_order_id']);
        $GLOBALS['extraScripts'][] = CASHFREE_SDK_URL;
        $GLOBALS['extraScripts'][] = asset('assets/js/modules/donate.js');
    } else {
        $GLOBALS['extraScripts'][] = 'https://checkout.razorpay.com/v1/checkout.js';
    }
    $gatewayName = gateway_label($gateway);
    $campaignTitle = $donation['campaign_id'] ? (string) db_value('SELECT title FROM campaigns WHERE id = ?', [$donation['campaign_id']]) : '';
    page_header($page, [
        'title' => 'Complete your donation', 'subtitle' => 'Secure payment through ' . $gatewayName, 'noindex' => true,
        'crumbs' => [['Donate', $self]], 'buttons' => [], 'canonical' => abs_page_url('donate'),
    ]);
    ?>
    <section class="section">
        <div class="container narrow">
            <div class="form-card result-card fr-pay">
                <?php if (gateway_test_mode($gateway)): ?>
                    <div class="test-mode-banner" role="note"><?= icon('alert-triangle') ?><?= $gateway === 'cashfree' ? 'TEST MODE - payments use the Cashfree sandbox. No real money will be charged.' : 'TEST MODE - payments use the Razorpay sandbox. No real money will be charged.' ?></div>
                <?php endif; ?>
                <span class="result-icon pending"><?= icon('heart') ?></span>
                <h2>Thank you, <?= e($donation['donor_name']) ?>!</h2>
                <p class="muted">Please check your donation and use the button below to pay securely.</p>
                <div class="pay-summary text-left">
                    <div><span>Reference</span><strong><?= e($donation['donation_ref']) ?></strong></div>
                    <div><span>Towards</span><strong><?= e($campaignTitle !== '' ? $campaignTitle : 'Where it is needed most (general fund)') ?></strong></div>
                    <div><span>Receipt will be sent to</span><strong class="fr-break"><?= e($donation['donor_email']) ?></strong></div>
                    <div><span>Pay with</span><strong><?= e($gatewayName) ?></strong></div>
                    <div><span>Amount</span><strong><?= e(money($donation['amount'], true)) ?></strong></div>
                </div>
                <?php if ($donation['status'] === 'failed'): ?>
                    <div class="alert alert-warning mb-2" role="status"><?= icon('alert-circle') ?><div>Your previous attempt did not go through<?= $donation['failure_reason'] ? ': ' . e($donation['failure_reason']) : '' ?>. You can try again.</div></div>
                <?php endif; ?>
                <?php if ($gateway === 'cashfree'): ?>
                    <button id="cf-pay" class="btn btn-donate btn-lg btn-block" type="button" data-dl-event="donation_checkout_open" data-dl-gateway="cashfree" data-dl-location="payment_step"<?= isset($_GET['closed']) ? '' : ' data-autoopen' ?>
                            data-session="<?= e($cfSession) ?>"
                            data-mode="<?= e(cashfree_env()) ?>">
                        <?= icon('lock') ?>Pay <?= e(money($donation['amount'], true)) ?> securely
                    </button>
                    <p id="cf-status" class="fr-pay-status" role="status" aria-live="polite"></p>
                    <p class="fr-pay-note"><?= icon('shield') ?><span>Card, UPI and bank details are entered in Cashfree's secure checkout and never reach our server. Your donation counts only after we verify the payment with Cashfree.</span></p>
                    <form id="cf-verify-form" method="post" action="<?= e($self) ?>" hidden>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cf_verify">
                        <input type="hidden" name="ref" value="<?= e($donation['donation_ref']) ?>">
                        <input type="hidden" name="token" value="<?= e($donation['access_token']) ?>">
                    </form>
                <?php else: ?>
                    <button id="rzp-pay" class="btn btn-donate btn-lg btn-block" type="button" data-autoopen data-dl-event="donation_checkout_open" data-dl-gateway="razorpay" data-dl-location="payment_step"
                            data-key="<?= e(razorpay_key_id()) ?>"
                            data-amount="<?= to_paise($donation['amount']) ?>"
                            data-currency="<?= e($donation['currency']) ?>"
                            data-order="<?= e($donation['gateway_order_id']) ?>"
                            data-ref="<?= e($donation['donation_ref']) ?>"
                            data-name="<?= e(site_name()) ?>"
                            data-description="<?= e('Donation ' . $donation['donation_ref']) ?>"
                            data-image="<?= e(abs_url('assets/images/logo.png')) ?>"
                            data-prefill-name="<?= e($donation['donor_name']) ?>"
                            data-prefill-email="<?= e($donation['donor_email']) ?>"
                            data-prefill-contact="<?= e((string) $donation['donor_phone']) ?>">
                        <?= icon('lock') ?>Pay <?= e(money($donation['amount'], true)) ?> securely
                    </button>
                    <p class="fr-pay-note"><?= icon('shield') ?><span>Card, UPI and bank details are entered on Razorpay's secure checkout and never reach our server. Your donation counts only after we verify the payment with Razorpay.</span></p>

                    <form id="rzp-verify-form" method="post" action="<?= e($self) ?>" hidden>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="ref" value="<?= e($donation['donation_ref']) ?>">
                        <input type="hidden" name="token" value="<?= e($donation['access_token']) ?>">
                        <input type="hidden" name="razorpay_payment_id" value="">
                        <input type="hidden" name="razorpay_order_id" value="">
                        <input type="hidden" name="razorpay_signature" value="">
                    </form>
                    <form id="rzp-fail-form" method="post" action="<?= e($self) ?>" hidden>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="failed">
                        <input type="hidden" name="ref" value="<?= e($donation['donation_ref']) ?>">
                        <input type="hidden" name="token" value="<?= e($donation['access_token']) ?>">
                        <input type="hidden" name="error_description" value="">
                        <input type="hidden" name="razorpay_payment_id" value="">
                    </form>
                <?php endif; ?>
                <p class="small mt-2"><a href="<?= e($self) ?>">Cancel and start over</a> · <a href="<?= e(page_url('contact')) ?>">Need help?</a></p>
            </div>
        </div>
    </section>
    <?php
    page_footer();
    return;
}

// ---------------------------------------------------------------------
// Step 1: donation form
// ---------------------------------------------------------------------
$online = online_donations_available();
$gateways = active_gateways();
$selectedGateway = in_array((string) old('gateway', ''), $gateways, true) ? (string) old('gateway') : default_gateway();
$bank = bank_transfer_details();
$min = donation_min_amount();
$presets = donation_presets();
$campaigns = array_values(array_filter(
    db_rows("SELECT id, title, category, program_id, end_date, status FROM campaigns WHERE status = 'active' ORDER BY is_urgent DESC, is_featured DESC, title ASC"),
    'campaign_accepts_donations'
));
$requestedCampaign = (int) ($_GET['campaign'] ?? 0);
// ?program=<slug> (links from programme pages): preselect an open campaign of that programme, if any
$program = null;
if ($requestedCampaign <= 0 && isset($_GET['program']) && is_string($_GET['program']) && preg_match('/^[a-z0-9\-]{2,120}$/', $_GET['program'])) {
    $program = db_row("SELECT id, slug, title FROM programs WHERE slug = ? AND status = 'published'", [$_GET['program']]);
    if ($program) {
        foreach ($campaigns as $c) {
            if ((int) ($c['program_id'] ?? 0) === (int) $program['id'] || $c['category'] === $program['slug']) {
                $requestedCampaign = (int) $c['id'];
                $program['campaign'] = $c['title'];
                break;
            }
        }
    }
}
$selectedCampaign = (string) old('campaign_id', $requestedCampaign > 0 ? (string) $requestedCampaign : '');
$selectedTitle = '';
foreach ($campaigns as $c) {
    if ((string) $c['id'] === $selectedCampaign) {
        $selectedTitle = $c['title'];
    }
}
$requestedAmount = (int) ($_GET['amount'] ?? 0);
$amount = (string) old('amount', ($requestedAmount >= $min && $requestedAmount <= DONATION_MAX_AMOUNT) ? (string) $requestedAmount : (string) ($presets[1] ?? $presets[0]));
$reg80g = setting('reg_80g_number');
$fcra = trim((string) setting('fcra_number'));
// Who may donate (Foreign Contribution (Regulation) Act, 2010) - never claims a registration status that is not entered
$whoCanDonate = $fcra === ''
    ? 'We can accept donations only from Indian citizens, including Indian citizens living abroad. We cannot accept donations from foreign nationals (including OCI card holders), foreign companies or other foreign sources.'
    : 'Foreign contributions can be received only in our FCRA-designated bank account and not through this form - please contact us.';
$address = trim(implode(', ', array_filter([$user['address'] ?? '', $user['city'] ?? '', $user['state'] ?? '', $user['pincode'] ?? ''])));
$contactEmail = setting('contact_email');

$metaTitle = null;
if ($selectedTitle !== '' || $requestedAmount > 0) {
    $metaTitle = 'Donate' . ($requestedAmount >= $min && $requestedAmount <= DONATION_MAX_AMOUNT ? ' ' . money($requestedAmount) : '') . ' to ' . ($selectedTitle !== '' ? $selectedTitle : site_name());
}
page_header($page, [
    'meta_title' => $metaTitle,
    'noindex' => $_GET ? true : null,
    'schema' => [[
        '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $page['title'], 'url' => abs_page_url('donate'),
        'description' => (string) $page['meta_description'],
        'potentialAction' => ['@type' => 'DonateAction', 'target' => abs_page_url('donate'), 'recipient' => ['@type' => 'NGO', '@id' => abs_url('#organization'), 'name' => site_name(), 'url' => abs_url('')]],
    ]],
]);

render_sections($page, function () use ($online, $gateways, $selectedGateway, $bank, $min, $presets, $campaigns, $selectedCampaign, $selectedTitle, $requestedCampaign, $amount, $reg80g, $whoCanDonate, $user, $address, $contactEmail, $self, $program) {
    ?>
    <section class="section" id="donation-form">
        <div class="container donate-layout">
            <div class="form-card fr-donate-card">
                <?php if (!$online): ?>
                    <h2 class="form-section-title"><?= icon('heart') ?>Ways to donate</h2>
                    <div class="notice notice-gold mb-3" role="status"><?= icon('info') ?><div><strong>Online donations are not available on this website at the moment.</strong>
                        <?= $bank ? 'You can donate by bank transfer or UPI using the details below.' : 'Please write to us and our team will share the current ways to donate.' ?>
                        <?php if ($selectedTitle !== ''): ?><br>You chose to support <strong><?= e($selectedTitle) ?></strong><?= $bank ? '.' : ' - please mention it when you write to us.' ?><?php endif; ?></div></div>
                    <p class="small muted"><?= icon('info') ?> <?= e($whoCanDonate) ?></p>
                    <?php if ($bank): ?>
                        <div class="kv-grid fr-bank">
                            <?php foreach ($bank as $label => $value): ?>
                                <div class="kv"><small><?= e($label) ?></small><strong><?= e($value) ?></strong>
                                    <button type="button" class="fr-copy" data-copy="<?= e($value) ?>" aria-label="Copy <?= e($label) ?>"><?= icon('copy') ?></button></div>
                            <?php endforeach; ?>
                        </div>
                        <p class="small muted mt-2">Bank transfers and UPI payments are accepted only from Indian citizens. After transferring, please email the transaction reference, your full name, address, PAN (optional)<?= $selectedTitle !== '' ? ' and the name of the campaign you are supporting (' . e($selectedTitle) . ')' : '' ?> to
                            <?php if ($contactEmail): ?><a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a><?php else: ?>us<?php endif; ?>.
                            Your donation is added to our verified totals and a receipt is issued once our finance team has matched it with the bank statement.</p>
                    <?php endif; ?>
                    <div class="btn-row mt-2">
                        <a class="btn btn-primary" href="<?= e(page_url('contact')) ?>"><?= icon('mail') ?>Contact us</a>
                        <a class="btn btn-outline" href="<?= e(page_url('financial-transparency')) ?>"><?= icon('shield') ?>Financial transparency</a>
                    </div>
                <?php else: ?>
                    <?php $testGateways = array_values(array_filter($gateways, 'gateway_test_mode')); ?>
                    <?php if ($testGateways): ?>
                        <div class="test-mode-banner" role="note"><?= icon('alert-triangle') ?>TEST MODE - payments <?= count($testGateways) < count($gateways) ? 'through ' . e(implode(' and ', array_map('gateway_label', $testGateways))) . ' ' : '' ?>use the <?= e(implode(' / ', array_map(function ($g) { return $g === 'cashfree' ? 'Cashfree sandbox' : 'Razorpay sandbox'; }, $testGateways))) ?>. No real money will be charged.</div>
                    <?php endif; ?>
                    <?php if ($program && !old('campaign_id')): ?>
                        <div class="alert alert-info mb-2" role="status"><?= icon('heart') ?><div><?php if (!empty($program['campaign'])): ?>Supporting <strong><?= e($program['title']) ?></strong>: we have selected its open campaign below. You can change it if you wish.<?php else: ?>There is no open campaign for <strong><?= e($program['title']) ?></strong> right now. A general donation supports all our programmes, including this one.<?php endif; ?></div></div>
                    <?php endif; ?>
                    <?php if ($requestedCampaign > 0 && $selectedTitle === '' && !old('campaign_id')): ?>
                        <div class="alert alert-info mb-2" role="status"><?= icon('info') ?><div>That campaign is not accepting donations right now. You can give where it is needed most, or choose another cause below.</div></div>
                    <?php endif; ?>
                    <form action="<?= e($self) ?>" method="post" data-validate id="donate-form">
                        <?= csrf_field() ?>
                        <?= honeypot_field() ?>
                        <input type="hidden" name="action" value="create">

                        <h2 class="form-section-title"><?= icon('heart') ?>Choose an amount</h2>
                        <div class="amount-options" role="radiogroup" aria-label="Suggested amounts">
                            <?php foreach ($presets as $preset): ?>
                                <label class="amount-option"><input type="radio" name="preset_amount" value="<?= (int) $preset ?>"<?= (string) $preset === $amount ? ' checked' : '' ?>><span><?= e(money($preset)) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-group">
                            <label for="amount">Amount in ₹ (any amount from <?= e(money($min)) ?> to <?= e(money(DONATION_MAX_AMOUNT)) ?>)<span class="req">*</span></label>
                            <div class="amount-input">
                                <span class="currency" aria-hidden="true">₹</span>
                                <input id="amount" type="number" name="amount" required min="<?= $min ?>" max="<?= DONATION_MAX_AMOUNT ?>" step="1" inputmode="numeric" value="<?= e($amount) ?>">
                            </div>
                            <?= field_error('amount') ?>
                        </div>
                        <div class="form-group mt-2">
                            <label for="campaign_id">Donate towards</label>
                            <select id="campaign_id" name="campaign_id">
                                <option value="">Where it is needed most (general fund)</option>
                                <?php foreach ($campaigns as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>"<?= $selectedCampaign === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="help"><?= $campaigns ? 'Choose a campaign, or let us use your gift where it is needed most.' : 'There are no open campaigns right now - your gift supports our programmes where it is needed most.' ?></span>
                            <?= field_error('campaign_id') ?>
                        </div>

                        <h2 class="form-section-title"><?= icon('user') ?>Your details</h2>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="donor_name">Full name<span class="req">*</span></label>
                                <input id="donor_name" type="text" name="donor_name" required minlength="2" maxlength="120" autocomplete="name" value="<?= e(old('donor_name', $user['name'] ?? '')) ?>">
                                <?= field_error('donor_name') ?>
                            </div>
                            <div class="form-group">
                                <label for="donor_email">Email<span class="req">*</span></label>
                                <input id="donor_email" type="email" name="donor_email" required maxlength="190" autocomplete="email" value="<?= e(old('donor_email', $user['email'] ?? '')) ?>">
                                <span class="help">Your receipt is sent here.</span>
                                <?= field_error('donor_email') ?>
                            </div>
                            <div class="form-group">
                                <label for="donor_phone">Mobile number<span class="req">*</span></label>
                                <?php $cfOnly = $gateways === ['cashfree']; $cfMobilePattern = '(?:\\+?91|0)?(?: |-)*[6-9](?:(?: |-)*[0-9]){9}'; ?>
                                <input id="donor_phone" type="tel" name="donor_phone" required maxlength="15" autocomplete="tel" inputmode="tel" value="<?= e(old('donor_phone', $user['mobile'] ?? '')) ?>"
                                    <?= in_array('cashfree', $gateways, true) ? ' data-cf-pattern="' . e($cfMobilePattern) . '" aria-describedby="phone-help"' : '' ?><?= $cfOnly || ($selectedGateway === 'cashfree') ? ' pattern="' . e($cfMobilePattern) . '" title="Enter a 10-digit Indian mobile number, e.g. 98765 43210"' : '' ?>>
                                <?php if (in_array('cashfree', $gateways, true)): ?>
                                    <span class="help" id="phone-help"><?= $cfOnly ? 'A 10-digit Indian mobile number is needed for the payment.' : 'If you pay with Cashfree, a 10-digit Indian mobile number is needed.' ?></span>
                                <?php endif; ?>
                                <?= field_error('donor_phone') ?>
                            </div>
                            <div class="form-group">
                                <label for="donor_pan">PAN (optional)</label>
                                <input id="donor_pan" type="text" name="donor_pan" maxlength="10" data-rule="pan" data-uppercase autocomplete="off" placeholder="ABCDE1234F" aria-describedby="pan-help" value="<?= e(old('donor_pan')) ?>">
                                <span class="help" id="pan-help"><?php if ($reg80g !== ''): ?>The foundation is registered under Section 80G (No. <?= e($reg80g) ?>). Add your PAN to have it printed on your receipt; the deduction is subject to the Income Tax Act.<?php else: ?>Printed on your receipt if given. Tax exemption under Section 80G applies only if the foundation holds a valid 80G registration - no 80G registration is published on this website at present.<?php endif; ?></span>
                                <?= field_error('donor_pan') ?>
                            </div>
                            <div class="form-group full">
                                <label for="donor_address">Address (optional, printed on your receipt)</label>
                                <input id="donor_address" type="text" name="donor_address" maxlength="255" autocomplete="street-address" value="<?= e(old('donor_address', $address)) ?>">
                                <?= field_error('donor_address') ?>
                            </div>
                            <div class="form-group full">
                                <label for="message">Message (optional)</label>
                                <textarea id="message" name="message" maxlength="500" rows="2"><?= e(old('message')) ?></textarea>
                                <?= field_error('message') ?>
                            </div>
                            <div class="form-group full">
                                <label class="checkbox"><input type="checkbox" name="is_anonymous" value="1"<?= old('is_anonymous') ? ' checked' : '' ?>> <span>Keep my donation anonymous - do not show my name in the public list of supporters.</span></label>
                                <label class="checkbox"><input type="checkbox" name="citizen_declaration" value="1" required aria-describedby="citizen-help"<?= old('citizen_declaration') ? ' checked' : '' ?>> <span>I confirm that I am an Indian citizen and that this donation is from my own funds through an Indian bank account, card or UPI (not from a foreign source).<span class="req">*</span></span></label>
                                <span class="help" id="citizen-help"><?= e($whoCanDonate) ?></span>
                                <?= field_error('citizen_declaration') ?>
                                <label class="checkbox"><input type="checkbox" name="consent" value="1" required<?= old('consent') ? ' checked' : '' ?>> <span>I agree to the <a href="<?= e(page_url('privacy-policy')) ?>" target="_blank" rel="noopener">Privacy Policy</a> and the <a href="<?= e(page_url('donation-refund-policy')) ?>" target="_blank" rel="noopener">Donation &amp; Refund Policy</a>.<span class="req">*</span></span></label>
                                <?= field_error('consent') ?>
                            </div>
                        </div>
                        <?php if (count($gateways) > 1): ?>
                            <fieldset class="fr-gateways" aria-describedby="gateway-help">
                                <legend class="form-section-title"><?= icon('lock') ?>Pay with</legend>
                                <div class="fr-gateway-options">
                                    <?php foreach ($gateways as $gw): ?>
                                        <label class="fr-gateway">
                                            <input type="radio" name="gateway" value="<?= e($gw) ?>"<?= $gw === $selectedGateway ? ' checked' : '' ?>>
                                            <span class="fr-gateway-card">
                                                <span class="fr-gateway-logo fr-gateway-<?= e($gw) ?>" aria-hidden="true"><?= $gw === 'cashfree' ? 'cashfree' : 'Razorpay' ?></span>
                                                <span class="fr-gateway-text"><strong><?= e(gateway_label($gw)) ?></strong><small>UPI · Cards · Net banking</small></span>
                                                <span class="fr-gateway-check" aria-hidden="true"><?= icon('check') ?></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <span class="help" id="gateway-help">Both are secure payment gateways. Your card, UPI or bank details are entered on the gateway's own checkout, never on our website.</span>
                                <?= field_error('gateway') ?>
                            </fieldset>
                        <?php else: ?>
                            <input type="hidden" name="gateway" value="<?= e($gateways[0] ?? 'razorpay') ?>">
                        <?php endif; ?>
                        <div class="form-actions">
                            <button class="btn btn-donate btn-lg btn-block" type="submit" data-loading="Preparing secure payment…" data-dl-event="donation_checkout_open" data-dl-location="donate_form"<?= count($gateways) === 1 ? ' data-dl-gateway="' . e($gateways[0]) . '"' : '' ?>><?= icon('lock') ?>Proceed to pay <span data-amount-preview></span></button>
                        </div>
                        <?php if (!$user): ?>
                            <p class="form-note"><?= icon('info') ?><span><a href="<?= e(page_url('login', ['next' => '/donate/'])) ?>">Log in</a> or <a href="<?= e(page_url('register')) ?>">create an account</a> to see all your donations and receipts in one place. You can also donate as a guest.</span></p>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>

            <aside class="donate-summary fr-summary" aria-label="Your donation">
                <?php if ($online): ?>
                    <p class="fr-summary-label">Your donation</p>
                    <p class="fr-summary-amount"><span data-amount-preview><?= e(money((float) $amount)) ?></span></p>
                    <p class="fr-summary-for"><?= icon('heart') ?><span data-cause-preview><?= e($selectedTitle !== '' ? $selectedTitle : 'Where it is needed most') ?></span></p>
                <?php endif; ?>
                <h3>Giving with confidence</h3>
                <ul>
                    <li><?= icon('check-circle') ?>Supports education, health, women empowerment, environment, skill development and relief work.</li>
                    <?php if ($online): ?><li><?= icon('check-circle') ?>Your payment is verified with <?= e(implode(' or ', array_map('gateway_label', $gateways))) ?> on our server before it is counted.</li><?php endif; ?>
                    <li><?= icon('check-circle') ?>A receipt is issued for every verified donation and emailed to you.</li>
                    <li><?= icon('check-circle') ?>Campaign progress and totals show verified donations only.</li>
                    <li><?= icon('check-circle') ?>Signed-in donors can download receipts any time from their dashboard.</li>
                    <?php if ($reg80g !== ''): ?><li><?= icon('check-circle') ?>Registered under Section 80G (No. <?= e($reg80g) ?>); deduction as per applicable law.</li><?php endif; ?>
                </ul>
                <?php if ($online): ?>
                    <div class="secure-badges">
                        <span><?= icon('lock') ?>Encrypted checkout</span>
                        <span><?= icon('shield') ?><?= e(implode(' / ', array_map('gateway_label', $gateways))) ?> secured</span>
                        <span><?= icon('smartphone') ?>UPI · Cards · Net banking</span>
                    </div>
                <?php endif; ?>
                <?php if ($online && $bank): ?>
                    <details class="fr-bank-details">
                        <summary><?= icon('briefcase') ?>Prefer a bank transfer or UPI?</summary>
                        <dl>
                            <?php foreach ($bank as $label => $value): ?><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd><?php endforeach; ?>
                        </dl>
                        <p>Bank transfers and UPI payments are accepted only from Indian citizens. Email the transaction reference<?= $contactEmail ? ' to ' . e($contactEmail) : '' ?> so that we can verify it and issue your receipt.</p>
                    </details>
                <?php endif; ?>
                <?php $sealUrl = function_exists('foundation_seal_url') ? foundation_seal_url() : ''; ?>
                <?php if ($sealUrl !== ''): ?>
                    <figure class="fr-seal">
                        <span class="fr-seal-img"><img src="<?= e($sealUrl) ?>" alt="Official seal of <?= e(site_name()) ?>" width="120" height="120" loading="lazy" decoding="async"></span>
                        <figcaption><strong>Official seal of <?= e(site_name()) ?></strong><?php if (setting('cin_number') !== ''): ?><small>CIN <?= e(setting('cin_number')) ?></small><?php endif; ?><?php if (setting('ngo_id') !== ''): ?><small>NGO ID <?= e(setting('ngo_id')) ?></small><?php endif; ?></figcaption>
                    </figure>
                <?php endif; ?>
                <p class="fr-summary-help"><?= icon('help-circle') ?><span>Questions? <a href="<?= e(page_url('contact')) ?>">Contact us</a> · <a href="<?= e(page_url('donation-refund-policy')) ?>">Refund policy</a></span></p>
            </aside>
        </div>
    </section>
    <?php
}, 'before');
$GLOBALS['extraScripts'][] = asset('assets/js/modules/donate.js');
page_footer();
