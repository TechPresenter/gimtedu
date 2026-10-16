<?php
/**
 * Verify an authorization letter (/verify-letter/): reference number + verification code printed on the letter.
 * POST only (CSRF, honeypot), rate limited per network (20 checks an hour), PRG. Codes have 8 characters from a
 * 31-character alphabet, so guessing one is not practical within the rate limit. The result shows only:
 * valid / revoked / not found, letter date, a partly hidden name and the school - never ID, mobile or address.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$slug = $page['slug'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify(page_url($slug));
    if (($_POST['action'] ?? '') === 'clear') {
        unset($_SESSION['letter_verify']);
        redirect('/' . $slug . '/#verify');
    }
    if (honeypot_triggered()) {
        redirect('/' . $slug . '/');
    }
    $refInput = mb_substr(clean_string($_POST['ref'] ?? ''), 0, 40);
    $codeInput = mb_substr(clean_string($_POST['code'] ?? ''), 0, 20);
    if (form_rate_limited('letter_verify', 20)) {
        flash('error', 'Too many checks from your network in the last hour. Please try again a little later.');
        redirect('/' . $slug . '/#verify');
    }
    log_activity('form_letter_verify', 'Letter verification', current_user_id());
    $ref = letter_normalize_ref($refInput);
    if ($ref === '' || letter_normalize_code($codeInput) === '') {
        $_SESSION['_old'] = ['ref' => $refInput];
        flash('error', 'Please enter the reference number (for example EIF/' . date('Y') . '/012) and the 8-character verification code exactly as printed on the letter.');
        redirect('/' . $slug . '/#verify');
    }
    $found = letter_verify_lookup($ref, $codeInput);
    $_SESSION['letter_verify'] = ['result' => $found, 'ref' => $ref, 'at' => time()];
    redirect('/' . $slug . '/#result');
}

// Result of the last check in this session (shown for 15 minutes)
$check = $_SESSION['letter_verify'] ?? null;
if (!is_array($check) || time() - (int) ($check['at'] ?? 0) > 900) {
    unset($_SESSION['letter_verify']);
    $check = null;
}
$prefill = (string) old('ref', mb_substr(clean_string($_GET['ref'] ?? ''), 0, 40));

page_header($page);
render_sections($page, function () use ($check, $prefill, $slug) {
    $contactEmail = setting('contact_email');
    ?>
    <section class="section lv-section" id="verify">
        <div class="container">
            <div class="rc-status-layout">
                <div class="lv-main">
                    <?php if ($check):
                        $r = $check['result']; ?>
                        <div class="form-card lv-result <?= $r ? ($r['status'] === 'issued' ? 'is-valid' : 'is-revoked') : 'is-unknown' ?>" id="result" role="status" tabindex="-1">
                            <?php if ($r && $r['status'] === 'issued'): ?>
                                <span class="result-icon success"><?= icon('check-circle') ?></span>
                                <h2>Valid letter</h2>
                                <p class="lv-lead">This <?= e(strtolower($r['type'])) ?> letter was issued by <?= e(site_name()) ?> on <strong><?= e(format_date($r['letter_date'], 'd F Y')) ?></strong> for <strong><?= e($r['name']) ?></strong> at <strong><?= e($r['school']) ?></strong>.</p>
                                <dl class="lv-facts">
                                    <div><dt>Reference number</dt><dd><?= e($r['ref_no']) ?></dd></div>
                                    <div><dt>Status</dt><dd><span class="badge badge-green">Valid</span></dd></div>
                                </dl>
                                <p class="small muted">Please also compare the name, school and reference number with the original letter. Names are partly hidden here to protect the volunteer's privacy.</p>
                            <?php elseif ($r): ?>
                                <span class="result-icon failed"><?= icon('alert-triangle') ?></span>
                                <h2>This letter has been revoked</h2>
                                <p class="lv-lead">Letter <strong><?= e($r['ref_no']) ?></strong> (dated <?= e(format_date($r['letter_date'], 'd F Y')) ?>) was revoked<?= $r['revoked_at'] ? ' on <strong>' . e(format_date($r['revoked_at'], 'd F Y')) . '</strong>' : '' ?> and is <strong>no longer valid</strong>.</p>
                                <p class="small muted">If someone is still using this letter, please let us know<?= $contactEmail !== '' ? ' at <a href="mailto:' . e($contactEmail) . '">' . e($contactEmail) . '</a>' : '' ?>.</p>
                            <?php else: ?>
                                <span class="result-icon pending"><?= icon('help-circle') ?></span>
                                <h2>No matching letter found</h2>
                                <p class="lv-lead">We could not find a letter with reference number <strong><?= e($check['ref']) ?></strong> and this verification code.</p>
                                <p class="small muted">Please check both exactly as printed at the bottom of the letter (the code has 8 letters and digits, for example ABCD-EF23). If it still does not match, the letter may not have been issued by us - please contact us before relying on it.</p>
                            <?php endif; ?>
                            <form method="post" action="<?= e(page_url($slug)) ?>" class="lv-again"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn btn-ghost" type="submit"><?= icon('search') ?>Check another letter</button></form>
                        </div>
                    <?php else: ?>
                        <div class="form-card rc-lookup-card">
                            <span class="icon-circle rc-login-icon"><?= icon('shield') ?></span>
                            <h2>Check a letter</h2>
                            <p class="muted">Both details are printed in small type at the bottom of every authorization letter: <em>"Verify at … · Ref: … · Code: …"</em>.</p>
                            <form method="post" action="<?= e(page_url($slug)) ?>" data-validate>
                                <?= csrf_field() ?><?= honeypot_field() ?>
                                <div class="form-grid rc-one-col">
                                    <div class="form-group full">
                                        <label for="lv-ref">Reference number<span class="req">*</span></label>
                                        <input id="lv-ref" type="text" name="ref" required maxlength="40" placeholder="e.g. EIF/<?= date('Y') ?>/012" value="<?= e($prefill) ?>" autocomplete="off" autocapitalize="characters" spellcheck="false">
                                    </div>
                                    <div class="form-group full">
                                        <label for="lv-code">Verification code<span class="req">*</span></label>
                                        <input id="lv-code" type="text" name="code" required maxlength="20" placeholder="e.g. ABCD-EF23" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-describedby="lv-code-help">
                                        <span class="help" id="lv-code-help">8 letters and digits; the hyphen is optional.</span>
                                    </div>
                                </div>
                                <div class="form-actions"><button class="btn btn-primary btn-lg btn-block" type="submit"><?= icon('search') ?>Verify letter</button></div>
                            </form>
                            <p class="form-note"><?= icon('lock') ?><span>For privacy, this page shows only whether a letter is valid, its date, a partly hidden name and the school - never identity or contact details.</span></p>
                        </div>
                    <?php endif; ?>
                </div>
                <aside class="rc-apply-aside">
                    <div class="aside-card">
                        <h3><?= icon('file-text') ?> About these letters</h3>
                        <p>Our coordinators issue authorization letters to volunteer teachers placed in schools. Each letter has its own reference number and verification code.</p>
                        <p>A letter can be revoked at any time; a revoked letter is no longer valid.</p>
                    </div>
                    <div class="aside-card">
                        <h3><?= icon('help-circle') ?> Something does not match?</h3>
                        <p>Please contact us before relying on the letter. We never charge any fee for volunteering or for a letter.</p>
                        <a class="btn btn-outline btn-sm btn-block" href="<?= e(page_url('contact')) ?>"><?= icon('mail') ?>Contact us</a>
                    </div>
                </aside>
            </div>
        </div>
    </section>
    <?php
});
page_footer();
