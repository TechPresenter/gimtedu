<?php
require_once __DIR__ . '/functions.php';

$user = current_user();
$minAmount = max(1, (int) setting('donation_min_amount', '100'));
$presets = array_values(array_filter(array_map('intval', explode(',', setting('donation_presets', '500,1000,2500,5000')))));

// ---------------------------------------------------------------------
// Step 2: payment page for a donation that has a gateway order
// ---------------------------------------------------------------------
$payRef = (string) ($_GET['pay'] ?? '');
if ($payRef !== '') {
    $donation = db_row('SELECT d.*, c.title AS campaign_title FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id WHERE d.donation_ref = ?', [$payRef]);
    if (!$donation || !hash_equals($donation['access_token'], (string) ($_GET['token'] ?? ''))) {
        not_found();
    }
    if ($donation['status'] === 'success') {
        redirect('donation-success.php?ref=' . urlencode($donation['donation_ref']) . '&token=' . urlencode($donation['access_token']));
    }
    if (!$donation['gateway_order_id'] || !razorpay_configured() || $donation['status'] === 'refunded') {
        flash('error', 'This payment can no longer be completed. Please start a new donation.');
        redirect('donate.php');
    }

    $pageTitle = 'Complete your donation';
    $extraScripts = ['https://checkout.razorpay.com/v1/checkout.js'];
    require __DIR__ . '/header.php';
    page_banner('Complete your donation', 'Secure payment powered by Razorpay', ['Donate' => 'donate.php']);
    ?>
    <section class="section">
        <div class="container narrow">
            <div class="form-card result-card">
                <?php if (payment_test_mode()): ?>
                    <div class="test-mode-banner">TEST MODE — no real money will be charged. Use Razorpay test cards / UPI.</div>
                <?php endif; ?>
                <span class="result-icon pending"><?= icon('heart') ?></span>
                <h2>Thank you, <?= e($donation['donor_name']) ?>!</h2>
                <p class="muted">Please review your donation and click the button below to pay securely.</p>
                <div class="pay-summary text-left">
                    <div><span>Reference</span><strong><?= e($donation['donation_ref']) ?></strong></div>
                    <div><span>Towards</span><strong><?= e($donation['campaign_title'] ?: 'General Fund') ?></strong></div>
                    <div><span>Email</span><strong><?= e($donation['donor_email']) ?></strong></div>
                    <div><span>Amount</span><strong><?= e(money($donation['amount'], true)) ?></strong></div>
                </div>
                <?php if ($donation['status'] === 'failed'): ?>
                    <div class="alert alert-warning mb-2"><?= icon('alert-circle') ?><div>Your previous attempt did not go through<?= $donation['failure_reason'] ? ': ' . e($donation['failure_reason']) : '' ?>. You can try again.</div></div>
                <?php endif; ?>
                <button id="rzp-pay" class="btn btn-donate btn-lg btn-block" type="button" data-autoopen
                        data-key="<?= e(RAZORPAY_KEY_ID) ?>"
                        data-amount="<?= to_paise($donation['amount']) ?>"
                        data-currency="<?= e($donation['currency']) ?>"
                        data-order="<?= e($donation['gateway_order_id']) ?>"
                        data-ref="<?= e($donation['donation_ref']) ?>"
                        data-name="<?= e(site_name()) ?>"
                        data-description="<?= e('Donation ' . $donation['donation_ref']) ?>"
                        data-image="<?= e(abs_url('assets/images/logo.png')) ?>"
                        data-prefill-name="<?= e($donation['donor_name']) ?>"
                        data-prefill-email="<?= e($donation['donor_email']) ?>"
                        data-prefill-contact="<?= e($donation['donor_phone']) ?>">
                    <?= icon('lock') ?>Pay <?= e(money($donation['amount'], true)) ?> Securely
                </button>
                <p class="form-note" style="justify-content:center"><?= icon('shield') ?>Your card / UPI details are entered on Razorpay's secure checkout and never reach our servers.</p>

                <form id="rzp-verify-form" method="post" action="<?= e(url('process-donation.php')) ?>" hidden>
                    <input type="hidden" name="action" value="verify">
                    <input type="hidden" name="ref" value="<?= e($donation['donation_ref']) ?>">
                    <input type="hidden" name="token" value="<?= e($donation['access_token']) ?>">
                    <input type="hidden" name="razorpay_payment_id" value="">
                    <input type="hidden" name="razorpay_order_id" value="">
                    <input type="hidden" name="razorpay_signature" value="">
                </form>
                <form id="rzp-fail-form" method="post" action="<?= e(url('process-donation.php')) ?>" hidden>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="failed">
                    <input type="hidden" name="ref" value="<?= e($donation['donation_ref']) ?>">
                    <input type="hidden" name="token" value="<?= e($donation['access_token']) ?>">
                    <input type="hidden" name="error_description" value="">
                    <input type="hidden" name="razorpay_payment_id" value="">
                </form>
                <p class="small mt-2"><a href="<?= e(url('donate.php')) ?>">Cancel and start over</a></p>
            </div>
        </div>
    </section>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

// ---------------------------------------------------------------------
// Step 1: donation form
// ---------------------------------------------------------------------
$campaigns = db_rows("SELECT id, title, category FROM campaigns WHERE status = 'active' ORDER BY is_urgent DESC, title ASC");
$selectedCampaign = (string) old('campaign_id', $_GET['campaign'] ?? '');
if ($selectedCampaign === '' && isset($_GET['program'], PROGRAMS[$_GET['program']])) {
    foreach ($campaigns as $c) {
        if ($c['category'] === $_GET['program']) {
            $selectedCampaign = (string) $c['id'];
            break;
        }
    }
}
$amount = (string) old('amount', (string) ($presets[1] ?? 1000));
$has80g = setting('reg_80g_number') !== '';

$pageTitle = 'Donate';
$pageDescription = 'Donate online securely to Prerit Welfare Foundation and receive an instant receipt.';
require __DIR__ . '/header.php';
page_banner('Make a Donation', 'Your support helps us empower underprivileged communities');
?>
<section class="section">
    <div class="container donate-layout">
        <div class="form-card">
            <?php if (!razorpay_configured()): ?>
                <div class="alert alert-warning mb-3"><?= icon('alert-circle') ?><div>Online payments are being set up and are not available right now. Please see <a href="<?= e(url('financial-transparency.php')) ?>">bank transfer details</a> or <a href="<?= e(url('contact.php')) ?>">contact us</a>.</div></div>
            <?php elseif (payment_test_mode()): ?>
                <div class="test-mode-banner">TEST MODE — payments use the Razorpay sandbox. No real money will be charged.</div>
            <?php endif; ?>
            <form action="<?= e(url('process-donation.php')) ?>" method="post" data-validate>
                <?= csrf_field() ?>
                <?= honeypot_field() ?>
                <input type="hidden" name="action" value="create">

                <h2 class="form-section-title"><?= icon('heart') ?>Choose an amount</h2>
                <div class="amount-options" role="radiogroup" aria-label="Suggested amounts">
                    <?php foreach ($presets as $preset): ?>
                        <label class="amount-option"><input type="radio" name="preset_amount" value="<?= $preset ?>"<?= (string) $preset === $amount ? ' checked' : '' ?>><span><?= e(money($preset)) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="form-group">
                    <label for="amount">Amount (₹)<span class="req">*</span></label>
                    <div class="amount-input">
                        <span class="currency">₹</span>
                        <input id="amount" type="number" name="amount" required min="<?= $minAmount ?>" max="1000000" step="1" inputmode="numeric" value="<?= e($amount) ?>">
                    </div>
                    <span class="help">Minimum donation <?= e(money($minAmount)) ?>.</span>
                    <?= field_error('amount') ?>
                </div>
                <div class="form-group mt-2">
                    <label for="campaign_id">Donate towards</label>
                    <select id="campaign_id" name="campaign_id">
                        <option value="">General Fund (where it is needed most)</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"<?= $selectedCampaign === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error('campaign_id') ?>
                </div>

                <h2 class="form-section-title"><?= icon('user') ?>Your details</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="donor_name">Full name<span class="req">*</span></label>
                        <input id="donor_name" type="text" name="donor_name" required maxlength="120" autocomplete="name" value="<?= e(old('donor_name', $user['name'] ?? '')) ?>">
                        <?= field_error('donor_name') ?>
                    </div>
                    <div class="form-group">
                        <label for="donor_email">Email<span class="req">*</span></label>
                        <input id="donor_email" type="email" name="donor_email" required maxlength="190" autocomplete="email" value="<?= e(old('donor_email', $user['email'] ?? '')) ?>">
                        <span class="help">Your receipt will be sent here.</span>
                        <?= field_error('donor_email') ?>
                    </div>
                    <div class="form-group">
                        <label for="donor_phone">Mobile number<span class="req">*</span></label>
                        <input id="donor_phone" type="tel" name="donor_phone" required maxlength="15" autocomplete="tel" value="<?= e(old('donor_phone', $user['phone'] ?? '')) ?>">
                        <?= field_error('donor_phone') ?>
                    </div>
                    <div class="form-group">
                        <label for="donor_pan">PAN <?= $has80g ? '(required for 80G receipt)' : '(optional)' ?></label>
                        <input id="donor_pan" type="text" name="donor_pan" maxlength="10" data-rule="pan" data-uppercase autocomplete="off" value="<?= e(old('donor_pan')) ?>">
                        <?= field_error('donor_pan') ?>
                    </div>
                    <div class="form-group full">
                        <label for="donor_address">Address (for your receipt)</label>
                        <input id="donor_address" type="text" name="donor_address" maxlength="255" autocomplete="street-address" value="<?= e(old('donor_address', trim(($user['address'] ?? '') . ' ' . ($user['city'] ?? '')))) ?>">
                    </div>
                    <div class="form-group full">
                        <label for="message">Message (optional)</label>
                        <textarea id="message" name="message" maxlength="500" rows="2" style="min-height:80px"><?= e(old('message')) ?></textarea>
                    </div>
                    <div class="form-group full">
                        <label class="checkbox"><input type="checkbox" name="is_anonymous" value="1"<?= old('is_anonymous') ? ' checked' : '' ?>> <span>Do not show my name in the public list of supporters.</span></label>
                        <label class="checkbox"><input type="checkbox" name="accept_policy" value="1" required> <span>I have read the <a href="<?= e(url('donation-policy.php')) ?>" target="_blank">Donation &amp; Refund Policy</a>.</span></label>
                        <?= field_error('accept_policy') ?>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-donate btn-lg btn-block" type="submit" data-loading="Preparing secure payment…"<?= razorpay_configured() ? '' : ' disabled' ?>><?= icon('lock') ?>Proceed to Pay <span data-amount-preview></span></button>
                </div>
                <?php if (!$user): ?>
                    <p class="form-note"><?= icon('info') ?><span><a href="<?= e(url('login.php?next=donate.php')) ?>">Log in</a> or <a href="<?= e(url('register.php')) ?>">register</a> before donating to see all your donations and receipts in one place.</span></p>
                <?php endif; ?>
            </form>
        </div>

        <aside class="donate-summary">
            <h3>Why give to <?= e(site_name()) ?>?</h3>
            <ul>
                <li><?= icon('check-circle') ?>Supports education, health, women empowerment, environment and skill development programmes.</li>
                <li><?= icon('check-circle') ?>Instant receipt after your payment is verified.</li>
                <li><?= icon('check-circle') ?>Campaign progress shows only verified donations.</li>
                <li><?= icon('check-circle') ?>Download receipts any time from your account.</li>
                <?php if ($has80g): ?>
                    <li><?= icon('check-circle') ?>Eligible for tax deduction under Section 80G (Reg. no. <?= e(setting('reg_80g_number')) ?>), subject to applicable law.</li>
                <?php endif; ?>
            </ul>
            <div class="secure-badges">
                <span><?= icon('lock') ?>Encrypted checkout</span>
                <span><?= icon('shield') ?>Razorpay secured</span>
                <span><?= icon('check') ?>UPI · Cards · Net banking</span>
            </div>
        </aside>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
