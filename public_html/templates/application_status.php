<?php
/**
 * Application status (/application-status/): look up any application or request by reference number + the
 * email or mobile number used. Generic "no match" message (no enumeration), rate limited, PRG.
 * Shows the current status, a progress bar, interview / information-request details and the applicant-visible timeline.
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
        unset($_SESSION['rc_status_view']);
        redirect('/' . $slug . '/#lookup');
    }
    if (honeypot_triggered()) {
        redirect('/' . $slug . '/');
    }
    $ref = mb_substr(strtoupper(clean_string($_POST['ref'] ?? '')), 0, 40);
    $contact = mb_substr(clean_string($_POST['contact'] ?? ''), 0, 190);
    if (form_rate_limited('status', 20)) {
        flash('error', 'Too many status checks from your network in the last hour. Please try again a little later.');
        redirect('/' . $slug . '/#lookup');
    }
    log_activity('form_status', 'Status lookup', current_user_id());
    $found = ($ref !== '' && $contact !== '') ? recruitment_find_application($ref, $contact) : null;
    if (!$found) {
        unset($_SESSION['rc_status_view']);
        $_SESSION['_old'] = ['ref' => $ref];
        flash('error', 'We could not find an application matching these details. Please check the reference number and use the same email address or mobile number you gave when applying.');
        redirect('/' . $slug . '/#lookup');
    }
    $_SESSION['rc_status_view'] = ['kind' => $found['kind'], 'id' => $found['id'], 'at' => time()];
    redirect('/' . $slug . '/#result');
}

// Result of the last successful lookup in this session (valid for 30 minutes)
$result = null;
$view = $_SESSION['rc_status_view'] ?? null;
if (is_array($view) && time() - (int) ($view['at'] ?? 0) < 1800 && isset(recruitment_kinds()[$view['kind'] ?? ''])) {
    $k = recruitment_kinds()[$view['kind']];
    $row = db_row('SELECT * FROM `' . $k['table'] . '` WHERE id = ?', [(int) $view['id']]);
    if ($row) {
        $result = ['kind' => $view['kind'], 'k' => $k, 'row' => $row, 'history' => application_history($view['kind'], (int) $row['id'], true)];
    }
} elseif ($view) {
    unset($_SESSION['rc_status_view']);
}
$prefill = (string) old('ref', mb_substr(strtoupper(clean_string($_GET['ref'] ?? '')), 0, 40));

/** Progress steps for the visual tracker: [status keys that belong to the step, label]. */
$progressFlows = [
    'recruitment' => [[['submitted'], 'Submitted'], [['under_review', 'info_requested'], 'Under review'], [['shortlisted'], 'Shortlisted'],
        [['interview_scheduled'], 'Interview'], [['selected', 'onboarding'], 'Selected'], [['active'], 'Active']],
    'scholarship' => [[['pending'], 'Received'], [['under_review'], 'Under review'], [['shortlisted'], 'Shortlisted'], [['approved'], 'Approved'], [['disbursed'], 'Support provided']],
    'beneficiary' => [[['pending'], 'Received'], [['under_review'], 'Under review'], [['approved'], 'Approved'], [['assisted'], 'Support provided']],
];

page_header($page, $result ? ['buttons' => []] : []);
render_sections($page, function () use ($result, $prefill, $slug, $progressFlows) {
    ?>
    <section class="section rc-status-section" id="lookup">
        <div class="container">
            <?php if ($result):
                $row = $result['row'];
                $kind = $result['kind'];
                $k = $result['k'];
                $status = (string) $row['status'];
                $info = recruitment_status_info($status, $kind);
                $ref = (string) $row[$k['ref_col']];
                $flow = $progressFlows[in_array($kind, ['scholarship', 'beneficiary'], true) ? $kind : 'recruitment'];
                $pos = -1;
                foreach ($flow as $i => [$keys]) {
                    if (in_array($status, $keys, true)) {
                        $pos = $i;
                    }
                }
                $closed = in_array($status, ['rejected', 'withdrawn'], true);
                if ($closed) {
                    // show how far the application got before it was closed
                    foreach ($result['history'] as $h) {
                        foreach ($flow as $i => [$keys]) {
                            if (in_array($h['status'], $keys, true) && $i > $pos) {
                                $pos = $i;
                            }
                        }
                    }
                }
                $interviewNote = '';
                foreach ($result['history'] as $h) {
                    if ($h['status'] === 'interview_scheduled' && $h['note']) {
                        $interviewNote = $h['note'];
                    }
                }
                $extra = [];
                if ($kind === 'staff') {
                    $extra['Position'] = $row['position'];
                } elseif ($kind === 'internship') {
                    $extra['Internship area'] = $row['internship_area'] . ' · ' . $row['duration'];
                } elseif ($kind === 'teacher') {
                    $extra['Subjects'] = $row['subjects'];
                } elseif ($kind === 'beneficiary') {
                    $extra['Support requested'] = HELP_CATEGORIES[$row['help_category']] ?? humanize($row['help_category']);
                } elseif ($kind === 'scholarship') {
                    $extra['Student'] = $row['student_name'] . ' · ' . $row['course_class'];
                }
                ?>
                <div class="rc-result-wrap">
                    <article class="form-card rc-result reveal" id="result" aria-labelledby="result-title">
                        <div class="rc-result-head">
                            <div>
                                <span class="pill"><?= e($k['label']) ?></span>
                                <h2 id="result-title"><?= e($ref) ?></h2>
                            </div>
                            <span class="rc-result-badge"><?= recruitment_status_badge($status, $kind, true) ?></span>
                        </div>
                        <p class="rc-result-lead"><?= e($info[2]) ?></p>

                        <div class="kv-grid rc-kv">
                            <div class="kv"><small><?= $kind === 'scholarship' ? 'Parent / guardian' : 'Applicant' ?></small><strong><?= e($kind === 'scholarship' ? $row['guardian_name'] : $row['name']) ?></strong></div>
                            <?php foreach ($extra as $label => $value): ?><div class="kv"><small><?= e($label) ?></small><strong><?= e($value) ?></strong></div><?php endforeach; ?>
                            <div class="kv"><small>Submitted on</small><strong><?= e(format_date($row['created_at'], 'd M Y')) ?></strong></div>
                            <div class="kv"><small>Last updated</small><strong><?= e(format_date($row['updated_at'] ?: $row['created_at'], 'd M Y')) ?></strong></div>
                        </div>

                        <?php if ($status === 'interview_scheduled' && !empty($row['interview_at'])): ?>
                            <div class="callout callout-blue rc-result-callout"><span class="callout-icon"><?= icon('calendar') ?></span><div>
                                <strong>Your interview</strong>
                                <p><?= e(format_date($row['interview_at'], 'l, d F Y \a\t h:i A')) ?><?= !empty($row['interview_mode']) ? ' &middot; ' . e($row['interview_mode']) : '' ?></p>
                                <?php if ($interviewNote !== ''): ?><p><?= e_nl($interviewNote) ?></p><?php endif; ?>
                                <p class="small">If you cannot attend at this time, please reply to the interview email or write to <?= e(setting('contact_email', 'us')) ?> quoting <?= e($ref) ?>.</p>
                            </div></div>
                        <?php endif; ?>

                        <?php if ($status === 'info_requested' && !empty($row['info_request'])): ?>
                            <div class="callout callout-gold rc-result-callout"><span class="callout-icon"><?= icon('message') ?></span><div>
                                <strong>We need a little more information</strong>
                                <p><?= e_nl($row['info_request']) ?></p>
                                <p class="small">Please reply to the email we sent you<?= setting('contact_email') !== '' ? ', or write to <a href="mailto:' . e(setting('contact_email')) . '?subject=' . rawurlencode('Information for ' . $ref) . '">' . e(setting('contact_email')) . '</a>' : '' ?>, quoting your reference number <strong><?= e($ref) ?></strong>. You can also use our <a href="<?= e(page_url('contact')) ?>">contact form</a>.</p>
                            </div></div>
                        <?php endif; ?>

                        <h3 class="rc-subhead">Progress</h3>
                        <ol class="rc-progress<?= $closed ? ' is-closed' : '' ?>" style="--steps:<?= count($flow) ?>" aria-label="Application progress">
                            <?php foreach ($flow as $i => [$keys, $label]):
                                $cls = $i < $pos ? 'done' : ($i === $pos ? ($closed ? 'stopped' : 'current') : ''); ?>
                                <li class="<?= $cls ?>"<?= $i === $pos ? ' aria-current="step"' : '' ?>><span class="rc-progress-dot"><?= $i < $pos || ($i === $pos && !$closed && $i === count($flow) - 1) ? icon('check') : ($i + 1) ?></span><span class="rc-progress-label"><?= e($label) ?></span></li>
                            <?php endforeach; ?>
                        </ol>
                        <?php if ($closed): ?><p class="muted small"><?= icon('info') ?> <?= e($info[2]) ?></p><?php endif; ?>

                        <h3 class="rc-subhead">Updates</h3>
                        <?= recruitment_timeline_html($result['history'], $kind) ?>

                        <div class="btn-row mt-3">
                            <?php if (is_logged_in()): ?><a class="btn btn-primary" href="<?= e(member_url('applications')) ?>"><?= icon('grid') ?>All my applications</a><?php endif; ?>
                            <a class="btn btn-outline" href="<?= e(page_url('contact')) ?>"><?= icon('mail') ?>Contact us</a>
                            <form method="post" action="<?= e(page_url($slug)) ?>" class="rc-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn btn-ghost" type="submit"><?= icon('search') ?>Check another application</button></form>
                        </div>
                    </article>
                </div>
            <?php else: ?>
                <div class="rc-status-layout">
                    <div class="form-card rc-lookup-card reveal">
                        <span class="icon-circle rc-login-icon"><?= icon('search') ?></span>
                        <h2>Find your application</h2>
                        <p class="muted">Enter the reference number from your acknowledgement email and the email address or mobile number you used when applying.</p>
                        <form method="post" action="<?= e(page_url($slug)) ?>" data-validate>
                            <?= csrf_field() ?><?= honeypot_field() ?>
                            <div class="form-grid rc-one-col">
                                <div class="form-group full">
                                    <label for="ref">Reference number<span class="req">*</span></label>
                                    <input id="ref" type="text" name="ref" required maxlength="40" placeholder="e.g. PWF-VOL-<?= date('Y') ?>-00012" value="<?= e($prefill) ?>" autocomplete="off" data-uppercase>
                                </div>
                                <div class="form-group full">
                                    <label for="contact">Email or mobile number used when applying<span class="req">*</span></label>
                                    <input id="contact" type="text" name="contact" required maxlength="190" autocomplete="email">
                                </div>
                            </div>
                            <div class="form-actions"><button class="btn btn-primary btn-lg btn-block" type="submit"><?= icon('search') ?>Check status</button></div>
                        </form>
                        <?php if (is_logged_in()): ?>
                            <p class="form-note"><?= icon('user') ?><span>You are signed in - see all your applications in <a href="<?= e(member_url('applications')) ?>">your dashboard</a>.</span></p>
                        <?php else: ?>
                            <p class="form-note"><?= icon('lock') ?><span>For your privacy we only show details when both the reference number and the contact details match.</span></p>
                        <?php endif; ?>
                    </div>
                    <aside class="rc-apply-aside">
                        <div class="aside-card">
                            <h3><?= icon('file-text') ?> Reference numbers</h3>
                            <ul class="rc-ref-list">
                                <?php foreach (recruitment_kinds() as $key => $kk): if ($key === 'membership') { continue; } ?>
                                    <li><code>PWF-<?= e($kk['prefix']) ?>-…</code><span><?= e($kk['label']) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="small muted">Membership status is shown on the <a href="<?= e(page_url('membership')) ?>">Membership page</a> after you log in.</p>
                        </div>
                        <div class="aside-card rc-aside-help">
                            <h3><?= icon('help-circle') ?> Lost your reference number?</h3>
                            <p>Search your inbox (and spam folder) for our acknowledgement email, or contact us with your name and the date you applied.</p>
                            <a class="btn btn-outline btn-sm btn-block" href="<?= e(page_url('contact')) ?>"><?= icon('mail') ?>Contact us</a>
                        </div>
                    </aside>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php
});
recruitment_form_assets();
page_footer();
