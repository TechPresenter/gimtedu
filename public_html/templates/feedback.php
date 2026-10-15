<?php
/**
 * Feedback form: star rating (1-5, accessible radio group), category, message, name and email.
 * Saved to `feedback` (Admin > Feedback) with a PWF-FDB-... reference. Rate limited + honeypot protected.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$self = '/' . $page['slug'] . '/';
$user = current_user();
$ratingLabels = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    if (honeypot_triggered()) {
        flash('success', 'Thank you for your feedback.');
        redirect($self);
    }
    if (form_rate_limited('feedback', 5)) {
        flash('error', 'You have sent feedback several times in the last hour. Please try again later.');
        redirect($self . '#form');
    }
    [$data, $errors] = validate($_POST, [
        'rating'   => 'integer|in:1,2,3,4,5',
        'category' => 'required|in:' . implode(',', array_keys(FEEDBACK_CATEGORIES)),
        'message'  => 'required|text|min:10|max:3000',
        'name'     => 'required|min:2|max:120',
        'email'    => 'required|email',
    ], ['rating' => 'Rating', 'category' => 'Feedback about', 'message' => 'Your feedback']);
    if ($errors) {
        back_with_errors($errors, $self . '#form', $_POST);
    }
    $rating = $data['rating'] !== '' ? (int) $data['rating'] : null;
    $id = db_insert('feedback', [
        'user_id' => current_user_id(), 'name' => $data['name'], 'email' => $data['email'], 'category' => $data['category'],
        'rating' => $rating, 'message' => $data['message'], 'status' => 'new', 'ip_address' => client_ip(),
    ]);
    $ref = reference_no('FDB', $id);
    db_update('feedback', ['reference_no' => $ref], 'id = :id', ['id' => $id]);
    log_activity('form_feedback', 'Feedback ' . $ref, current_user_id());

    $stars = $rating ? $rating . '/5 (' . $ratingLabels[$rating] . ')' : 'No rating';
    send_mail($data['email'], 'Thank you for your feedback - ' . $ref,
        '<p>Dear ' . e($data['name']) . ',</p><p>Thank you for taking the time to share your feedback with ' . e(site_name())
        . '. Every response is read by our team and helps us improve.</p><p>Reference number: <strong>' . e($ref) . '</strong></p><p>Regards,<br>' . e(site_name()) . '</p>');
    notify_admin('Feedback ' . $ref . ' - ' . FEEDBACK_CATEGORIES[$data['category']] . ' - ' . $stars,
        '<p><strong>' . e($data['name']) . '</strong> (' . e($data['email']) . ') shared feedback (' . e($ref) . ').</p>'
        . '<p><strong>About:</strong> ' . e(FEEDBACK_CATEGORIES[$data['category']]) . '<br><strong>Rating:</strong> ' . e($stars) . '</p>'
        . '<blockquote style="margin:16px 0;padding:12px 16px;background:#f5f9f4;border-left:4px solid #146B43">' . e_nl($data['message']) . '</blockquote>', $data['email']);

    $_SESSION['info_submitted'] = ['form' => 'feedback', 'ref' => $ref, 'title' => 'Thank you for your feedback',
        'text' => 'Your feedback has reached our team. If you asked a question, we will reply by email.'];
    flash('success', 'Thank you! Your feedback has been received.');
    redirect($self . '#form');
}

info_require_validation();
page_header($page);
render_sections($page, function () use ($user, $ratingLabels, $page) {
    $oldRating = (string) old('rating');
    ?>
    <section class="section feedback-section" id="form">
        <div class="container narrow">
            <?= info_submitted_panel('feedback') ?>
            <div class="form-card">
                <h2>Share your feedback</h2>
                <p class="muted">Tell us what worked well and what we can do better. Fields marked <span class="req">*</span> are required.</p>
                <form method="post" action="<?= e(page_url($page['slug'])) ?>" data-validate novalidate>
                    <?= csrf_field() ?><?= honeypot_field() ?>
                    <fieldset class="form-group star-field">
                        <legend>How would you rate your experience? <span class="muted">(optional)</span></legend>
                        <div class="star-rating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <input type="radio" id="rating-<?= $i ?>" name="rating" value="<?= $i ?>"<?= $oldRating === (string) $i ? ' checked' : '' ?>>
                                <label for="rating-<?= $i ?>" data-tip="<?= e($ratingLabels[$i]) ?>"><?= icon('star') ?><span class="sr-only"><?= $i ?> star<?= $i > 1 ? 's' : '' ?> - <?= e($ratingLabels[$i]) ?></span></label>
                            <?php endfor; ?>
                        </div>
                        <span class="star-hint muted small">1 star = poor, 5 stars = excellent</span>
                        <?= field_error('rating') ?>
                    </fieldset>
                    <div class="form-grid">
                        <div class="form-group full">
                            <label for="category">Your feedback is about<span class="req">*</span></label>
                            <select id="category" name="category" required><?= options_html(FEEDBACK_CATEGORIES, (string) old('category', 'general'), true, '') ?></select><?= field_error('category') ?>
                        </div>
                        <div class="form-group full">
                            <label for="message">Your feedback<span class="req">*</span></label>
                            <textarea id="message" name="message" required minlength="10" maxlength="3000" rows="6" data-counter><?= e(old('message')) ?></textarea><?= field_error('message') ?>
                        </div>
                        <div class="form-group">
                            <label for="name">Name<span class="req">*</span></label>
                            <input id="name" type="text" name="name" required minlength="2" maxlength="120" autocomplete="name" value="<?= e(old('name', $user['name'] ?? '')) ?>"><?= field_error('name') ?>
                        </div>
                        <div class="form-group">
                            <label for="email">Email<span class="req">*</span></label>
                            <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?= e(old('email', $user['email'] ?? '')) ?>"><?= field_error('email') ?>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary btn-lg" type="submit" data-loading="Sending…"><?= icon('send') ?>Send feedback</button>
                    </div>
                    <p class="form-note"><?= icon('lock') ?><span>We use your details only to follow up on your feedback. See our <a href="<?= e(page_url('privacy-policy')) ?>">privacy policy</a>. For complaints, please use <a href="<?= e(page_url('grievance-redressal')) ?>">grievance redressal</a>.</span></p>
                </form>
            </div>
        </div>
    </section>
    <?php
});
page_footer();
