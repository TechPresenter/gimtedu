<?php
/**
 * Start a fundraiser (/start-a-fundraiser/): explanation sections + proposal form for signed-in members.
 * A proposal is saved as a campaign with status "proposal"; staff review it in Admin > Fundraiser Proposals.
 * Money is always received by the foundation itself - never by the organiser.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$self = page_url('start-a-fundraiser');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    if (!$user) {
        flash('info', 'Please log in to submit a fundraiser proposal.');
        redirect(page_url('login', ['next' => '/start-a-fundraiser/']));
    }
    if (honeypot_triggered()) {
        redirect($self);
    }
    if (form_rate_limited('fundraiser', 3)) {
        flash('error', 'You have submitted several proposals in the last hour. Please try again later.');
        redirect($self);
    }
    $categories = campaign_categories();
    [$data, $errors] = validate($_POST, [
        'title'          => 'required|min:10|max:150',
        'category'       => 'required|in:' . implode(',', array_keys($categories)),
        'description'    => 'required|text|min:150|max:5000',
        'location'       => 'required|min:3|max:150',
        'goal_amount'    => 'required|numeric|min:1000|max:10000000',
        'end_date'       => 'date',
        'organizer_phone' => 'required|phone',
        'proposal_notes' => 'text|max:2000',
        'declare_true'   => 'accepted',
        'declare_funds'  => 'accepted',
        'declare_consent' => 'accepted',
        'declare_terms'  => 'accepted',
    ], ['title' => 'Fundraiser title', 'category' => 'Cause', 'description' => 'Description of the cause', 'goal_amount' => 'Target amount',
        'end_date' => 'End date', 'organizer_phone' => 'Your mobile number', 'proposal_notes' => 'Supporting notes',
        'declare_true' => 'the information is true', 'declare_funds' => 'funds are received by the foundation',
        'declare_consent' => 'consent of the people involved', 'declare_terms' => 'the terms and the donation policy']);
    if (!isset($errors['end_date']) && $data['end_date'] !== '') {
        if ($data['end_date'] <= date('Y-m-d')) {
            $errors['end_date'] = 'The end date must be in the future.';
        } elseif ($data['end_date'] > date('Y-m-d', strtotime('+1 year'))) {
            $errors['end_date'] = 'Fundraisers can run for up to one year.';
        }
    }
    if ($errors) {
        back_with_errors($errors, $self . '#proposal-form', $_POST);
    }

    $paragraphs = array_filter(array_map('trim', preg_split('/\n{2,}/', $data['description'])));
    $html = '';
    foreach ($paragraphs as $para) {
        $html .= '<p>' . e_nl($para) . '</p>';
    }
    $programId = db_value("SELECT id FROM programs WHERE slug = ? AND status = 'published'", [$data['category']]);
    $id = db_insert('campaigns', [
        'title'             => $data['title'],
        'slug'              => unique_slug('campaigns', $data['title']),
        'program_id'        => $programId ? (int) $programId : null,
        'category'          => $data['category'],
        'short_description' => excerpt($data['description'], 280),
        'description'       => clean_html($html),
        'goal_amount'       => round((float) $data['goal_amount'], 2),
        'location'          => $data['location'],
        'end_date'          => $data['end_date'] !== '' ? $data['end_date'] : null,
        'status'            => 'proposal',
        'submitted_by'      => (int) $user['id'],
        'organizer_name'    => $user['name'],
        'organizer_email'   => $user['email'],
        'organizer_phone'   => $data['organizer_phone'],
        'proposal_notes'    => $data['proposal_notes'] !== '' ? $data['proposal_notes'] : null,
        'created_by'        => (int) $user['id'],
    ]);
    log_activity('form_fundraiser', 'Fundraiser proposal #' . $id, (int) $user['id']);
    track_conversion('fundraiser_proposal', $id);
    add_notification((int) $user['id'], 'Fundraiser proposal received', 'Thank you for proposing "' . $data['title'] . '". Our team will review it and get back to you.', $self . '#my-proposals', 'success');
    notify_admin('New fundraiser proposal: ' . $data['title'],
        '<p><strong>' . e($user['name']) . '</strong> (' . e($user['email']) . ', ' . e($data['organizer_phone']) . ') proposed a fundraiser.</p>'
        . '<p><strong>' . e($data['title']) . '</strong><br>Cause: ' . e(campaign_category_label($data['category'])) . '<br>Location: ' . e($data['location'])
        . '<br>Target: ' . e(money($data['goal_amount'])) . '</p><p><a class="btn" href="' . e(abs_url('admin/proposals.php?id=' . $id)) . '">Review the proposal</a></p>', $user['email']);
    send_mail($user['email'], 'Fundraiser proposal received - ' . site_name(),
        '<p>Dear ' . e($user['name']) . ',</p><p>Thank you for proposing the fundraiser <strong>' . e($data['title']) . '</strong>. Our team will review the details and contact you, usually within a few working days.</p>'
        . '<p>Please remember: donations for a fundraiser are always received directly by ' . e(site_name()) . ' through this website. Never collect money in your personal account.</p>'
        . '<p>Regards,<br>' . e(site_name()) . '</p>');
    flash('success', 'Thank you! Your fundraiser proposal has been submitted for review. We will email you once it has been reviewed.');
    redirect($self . '#my-proposals');
}

page_header($page);
render_sections($page, function () use ($user, $self) {
    $categories = campaign_categories();
    $mine = $user ? db_rows("SELECT id, title, slug, status, created_at, reviewed_at, review_notes FROM campaigns WHERE submitted_by = ? ORDER BY created_at DESC LIMIT 20", [(int) $user['id']]) : [];
    $statusText = ['proposal' => 'Under review', 'draft' => 'Approved - being prepared', 'active' => 'Live', 'completed' => 'Completed', 'closed' => 'Closed', 'rejected' => 'Not approved'];
    ?>
    <section class="section section-tint fr-proposal" id="proposal-form">
        <div class="container">
            <div class="fr-proposal-grid">
                <div class="form-card">
                    <h2 class="form-section-title"><?= icon('flag') ?>Propose a fundraiser</h2>
                    <?php if (!$user): ?>
                        <div class="fr-login-prompt">
                            <span class="icon-circle"><?= icon('lock') ?></span>
                            <div>
                                <p><strong>Please sign in to propose a fundraiser.</strong> Proposals are linked to a verified account so that our team can contact the organiser and you can track the review.</p>
                                <div class="btn-row">
                                    <a class="btn btn-primary" href="<?= e(page_url('login', ['next' => '/start-a-fundraiser/'])) ?>"><?= icon('user') ?>Log in</a>
                                    <a class="btn btn-outline" href="<?= e(page_url('register', ['next' => '/start-a-fundraiser/'])) ?>">Create an account</a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="muted">Signed in as <strong><?= e($user['name']) ?></strong> (<?= e($user['email']) ?>). Fields marked <span class="req">*</span> are required.</p>
                        <form method="post" action="<?= e($self) ?>" data-validate>
                            <?= csrf_field() ?>
                            <?= honeypot_field() ?>
                            <div class="form-grid">
                                <div class="form-group full">
                                    <label for="f_title">Fundraiser title<span class="req">*</span></label>
                                    <input id="f_title" type="text" name="title" required minlength="10" maxlength="150" placeholder="e.g. School kits for children in our community learning centre" value="<?= e(old('title')) ?>">
                                    <?= field_error('title') ?>
                                </div>
                                <div class="form-group">
                                    <label for="f_category">Cause<span class="req">*</span></label>
                                    <select id="f_category" name="category" required><?= options_html($categories, (string) old('category'), true, 'Select a cause') ?></select>
                                    <?= field_error('category') ?>
                                </div>
                                <div class="form-group">
                                    <label for="f_location">Location<span class="req">*</span></label>
                                    <input id="f_location" type="text" name="location" required minlength="3" maxlength="150" placeholder="Village / town, district, state" value="<?= e(old('location')) ?>">
                                    <?= field_error('location') ?>
                                </div>
                                <div class="form-group full">
                                    <label for="f_description">Describe the cause<span class="req">*</span></label>
                                    <textarea id="f_description" name="description" required minlength="150" maxlength="5000" rows="7" aria-describedby="f_description_help"><?= e(old('description')) ?></textarea>
                                    <span class="help" id="f_description_help">Who needs support, what exactly the money would pay for, and how the work will be carried out. Do not include personal details of individuals (names, photos, medical records) without their consent.</span>
                                    <?= field_error('description') ?>
                                </div>
                                <div class="form-group">
                                    <label for="f_goal">Target amount (₹)<span class="req">*</span></label>
                                    <input id="f_goal" type="number" name="goal_amount" required min="1000" max="10000000" step="1" inputmode="numeric" value="<?= e(old('goal_amount')) ?>">
                                    <span class="help">Your best estimate of the cost. Our team will review it with you.</span>
                                    <?= field_error('goal_amount') ?>
                                </div>
                                <div class="form-group">
                                    <label for="f_end">Proposed end date</label>
                                    <input id="f_end" type="date" name="end_date" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" max="<?= e(date('Y-m-d', strtotime('+1 year'))) ?>" value="<?= e(old('end_date')) ?>">
                                    <?= field_error('end_date') ?>
                                </div>
                                <div class="form-group">
                                    <label for="f_phone">Your mobile number<span class="req">*</span></label>
                                    <input id="f_phone" type="tel" name="organizer_phone" required maxlength="15" autocomplete="tel" value="<?= e(old('organizer_phone', $user['mobile'] ?? '')) ?>">
                                    <?= field_error('organizer_phone') ?>
                                </div>
                                <div class="form-group full">
                                    <label for="f_notes">Supporting notes (optional)</label>
                                    <textarea id="f_notes" name="proposal_notes" maxlength="2000" rows="3" placeholder="Local contacts, organisations involved, quotes or estimates you already have, how you will share updates…"><?= e(old('proposal_notes')) ?></textarea>
                                    <?= field_error('proposal_notes') ?>
                                </div>
                                <fieldset class="form-group full fr-declare">
                                    <legend>Declarations<span class="req">*</span></legend>
                                    <label class="checkbox"><input type="checkbox" name="declare_true" value="1" required<?= old('declare_true') ? ' checked' : '' ?>> <span>The information I have given is true to the best of my knowledge, and I will not make exaggerated or unverified claims.</span></label>
                                    <?= field_error('declare_true') ?>
                                    <label class="checkbox"><input type="checkbox" name="declare_funds" value="1" required<?= old('declare_funds') ? ' checked' : '' ?>> <span>I understand that all donations are received directly by <?= e(site_name()) ?> and that I must never collect money in my personal account.</span></label>
                                    <?= field_error('declare_funds') ?>
                                    <label class="checkbox"><input type="checkbox" name="declare_consent" value="1" required<?= old('declare_consent') ? ' checked' : '' ?>> <span>I have the consent of the people or community involved, and I will not share identifiable photos or details of children or vulnerable people.</span></label>
                                    <?= field_error('declare_consent') ?>
                                    <label class="checkbox"><input type="checkbox" name="declare_terms" value="1" required<?= old('declare_terms') ? ' checked' : '' ?>> <span>I agree to the <a href="<?= e(page_url('terms-conditions')) ?>" target="_blank" rel="noopener">Terms &amp; Conditions</a>, the <a href="<?= e(page_url('donation-refund-policy')) ?>" target="_blank" rel="noopener">Donation &amp; Refund Policy</a> and the <a href="<?= e(page_url('privacy-policy')) ?>" target="_blank" rel="noopener">Privacy Policy</a>.</span></label>
                                    <?= field_error('declare_terms') ?>
                                </fieldset>
                            </div>
                            <div class="form-actions">
                                <button class="btn btn-primary btn-lg" type="submit" data-loading="Submitting…"><?= icon('send') ?>Submit proposal for review</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
                <aside class="fr-proposal-aside">
                    <div class="aside-card">
                        <h3><?= icon('shield') ?> What happens next</h3>
                        <ol class="fr-mini-steps">
                            <li>Our team reviews the cause, the budget and the people involved.</li>
                            <li>We may call you to understand the details or ask for documents.</li>
                            <li>If approved, we prepare the campaign page with you and publish it.</li>
                            <li>Donations go directly to the foundation; we publish updates and utilisation details.</li>
                        </ol>
                    </div>
                    <?php if ($user): ?>
                        <div class="aside-card" id="my-proposals">
                            <h3><?= icon('list') ?> Your proposals</h3>
                            <?php if ($mine): ?>
                                <ul class="fr-my-list">
                                    <?php foreach ($mine as $m): ?>
                                        <li>
                                            <strong><?= in_array($m['status'], CAMPAIGN_PUBLIC_STATUSES, true) ? '<a href="' . e(campaign_url($m['slug'])) . '">' . e($m['title']) . '</a>' : e($m['title']) ?></strong>
                                            <span class="fr-my-meta"><?= status_badge(['proposal' => 'pending', 'draft' => 'approved', 'active' => 'active', 'completed' => 'completed', 'closed' => 'closed', 'rejected' => 'rejected'][$m['status']] ?? $m['status']) ?>
                                                <small><?= e($statusText[$m['status']] ?? humanize($m['status'])) ?> · <?= e(format_date($m['created_at'])) ?></small></span>
                                            <?php if ($m['review_notes'] && in_array($m['status'], ['rejected', 'draft'], true)): ?><small class="fr-my-note"><?= e($m['review_notes']) ?></small><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="small muted">You have not submitted any proposals yet.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="aside-card">
                        <h3><?= icon('help-circle') ?> Questions?</h3>
                        <p class="small">Talk to us before you start - we are happy to help you plan.</p>
                        <a class="btn btn-outline btn-sm btn-block" href="<?= e(page_url('contact')) ?>"><?= icon('mail') ?>Contact us</a>
                    </div>
                </aside>
            </div>
        </div>
    </section>
    <?php
});
page_footer();
