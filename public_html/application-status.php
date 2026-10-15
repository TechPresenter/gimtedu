<?php
require_once __DIR__ . '/functions.php';

/**
 * Look up any application by its reference number + the email or phone used.
 */
function find_application(string $ref, string $contact): ?array
{
    if (!preg_match('/^PWF-([A-Z]{3})-\d{4}-\d{5,}$/', $ref, $m)) {
        return null;
    }
    $map = [
        'VOL' => ['volunteers', 'application_no', 'name', 'Volunteer application'],
        'INT' => ['volunteers', 'application_no', 'name', 'Internship application'],
        'TCH' => ['teacher_applications', 'application_no', 'name', 'Volunteer teacher application'],
        'STF' => ['staff_applications', 'application_no', 'name', 'Job application'],
        'SCH' => ['scholarships', 'application_no', 'student_name', 'Scholarship application'],
        'BEN' => ['beneficiaries', 'reference_no', 'name', 'Request for help'],
        'ENQ' => ['contact_enquiries', 'reference_no', 'name', 'Enquiry'],
        'FDB' => ['contact_enquiries', 'reference_no', 'name', 'Feedback'],
        'GRV' => ['contact_enquiries', 'reference_no', 'name', 'Grievance'],
    ];
    if (!isset($map[$m[1]])) {
        return null;
    }
    [$table, $refCol, $nameCol, $label] = $map[$m[1]];
    $row = db_row("SELECT `$refCol` AS ref, `$nameCol` AS name, email, phone, status, created_at, updated_at FROM `$table` WHERE `$refCol` = ?", [$ref]);
    if (!$row) {
        return null;
    }
    $contact = mb_strtolower(trim($contact));
    $digits = preg_replace('/\D/', '', $contact);
    $phoneMatch = $digits !== '' && strlen($digits) >= 10 && substr(preg_replace('/\D/', '', (string) $row['phone']), -10) === substr($digits, -10);
    $emailMatch = $row['email'] && mb_strtolower($row['email']) === $contact;
    if (!$phoneMatch && !$emailMatch) {
        return null;
    }
    $row['label'] = $label;
    $row['table'] = $table;
    return $row;
}

$ref = strtoupper(trim((string) ($_POST['ref'] ?? $_GET['ref'] ?? '')));
$result = null;
$searched = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('application-status.php');
    $searched = true;
    $contact = clean_string($_POST['contact'] ?? '');
    if (form_rate_limited('status', 30)) {
        flash('error', 'Too many lookups. Please try again later.');
        redirect('application-status.php');
    }
    log_activity('form_status', '');
    $result = $ref !== '' && $contact !== '' ? find_application($ref, $contact) : null;
}

$steps = [
    'contact_enquiries' => ['new' => 'Received', 'in_progress' => 'In progress', 'resolved' => 'Resolved'],
    'beneficiaries'     => ['pending' => 'Received', 'under_review' => 'Under review', 'approved' => 'Approved', 'assisted' => 'Support provided'],
    'scholarships'      => ['pending' => 'Received', 'under_review' => 'Under review', 'shortlisted' => 'Shortlisted', 'approved' => 'Approved', 'disbursed' => 'Scholarship disbursed'],
    'default'           => ['pending' => 'Received', 'under_review' => 'Under review', 'shortlisted' => 'Shortlisted', 'approved' => 'Approved'],
];

$pageTitle = 'Application Status';
$pageDescription = 'Check the status of your volunteer, teacher, job, scholarship or help request application.';
require __DIR__ . '/header.php';
page_banner('Application Status', 'Track your application or request using your reference number');
?>
<section class="section">
    <div class="container">
        <div class="auth-wrap" style="max-width:620px">
            <div class="form-card">
                <form method="post" action="<?= e(url('application-status.php')) ?>" data-validate>
                    <?= csrf_field() ?>
                    <div class="form-grid">
                        <div class="form-group full">
                            <label for="ref">Application / reference number<span class="req">*</span></label>
                            <input id="ref" type="text" name="ref" required maxlength="40" placeholder="e.g. PWF-VOL-<?= date('Y') ?>-00012" value="<?= e($ref) ?>" data-uppercase>
                        </div>
                        <div class="form-group full">
                            <label for="contact">Email or mobile number used while applying<span class="req">*</span></label>
                            <input id="contact" type="text" name="contact" required maxlength="190" autocomplete="email">
                        </div>
                    </div>
                    <div class="form-actions"><button class="btn btn-primary btn-lg btn-block" type="submit"><?= icon('search') ?>Check Status</button></div>
                </form>

                <?php if ($searched && !$result): ?>
                    <div class="alert alert-error mt-3"><?= icon('alert-circle') ?><div>We could not find an application with these details. Please check the reference number and the email or mobile number you used.</div></div>
                <?php elseif ($result): ?>
                    <?php
                    $flow = $steps[$result['table']] ?? $steps['default'];
                    $keys = array_keys($flow);
                    $pos = array_search($result['status'], $keys, true);
                    ?>
                    <div class="status-result">
                        <div class="panel-head">
                            <h3><?= e($result['label']) ?></h3>
                            <?= status_badge($result['status']) ?>
                        </div>
                        <dl class="detail-list">
                            <dt>Reference</dt><dd><?= e($result['ref']) ?></dd>
                            <dt>Applicant</dt><dd><?= e($result['name']) ?></dd>
                            <dt>Submitted on</dt><dd><?= e(format_date($result['created_at'], 'd M Y, h:i A')) ?></dd>
                            <dt>Last updated</dt><dd><?= e(format_date($result['updated_at'] ?: $result['created_at'], 'd M Y, h:i A')) ?></dd>
                        </dl>
                        <?php if ($pos !== false): ?>
                            <ol class="timeline">
                                <?php foreach ($flow as $key => $text): $i = array_search($key, $keys, true); ?>
                                    <li class="<?= $i < $pos ? 'done' : ($i === $pos ? 'current' : '') ?>"><?= e($text) ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php elseif (in_array($result['status'], ['rejected', 'closed'], true)): ?>
                            <div class="notice mt-2"><?= icon('info') ?><div>Thank you for your interest. We are unable to take this application forward at this time.</div></div>
                        <?php else: ?>
                            <div class="notice mt-2"><?= icon('info') ?><div>Your application is currently <?= e(strtolower(humanize($result['status']))) ?>. We will contact you if we need more information.</div></div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($ref !== ''): ?>
                    <div class="notice notice-green mt-3"><?= icon('check-circle') ?><div>Your reference number is <strong><?= e($ref) ?></strong>. Enter the email or mobile number you used to see the current status.</div></div>
                <?php endif; ?>
            </div>
            <p class="text-center muted small mt-2">Registered users can see all their applications under <a href="<?= e(url('my-applications.php')) ?>">My Applications</a>.</p>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
