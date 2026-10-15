<?php
/**
 * Request help (/request-help/): page sections + a simple, dignified request form (module position).
 * Saves to beneficiaries (reference PWF-HLP-YYYY-NNNNN). Optional document stored privately in uploads/documents/beneficiaries.
 * ?category=education|health|women|livelihood|food|disaster|other preselects the type of support.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$slug = $page['slug'];
$consents = ['contact', 'datause', 'minor'];
$minorAge = 18;   // the guardian declaration is required only when the person who needs support is a child
$required = ['name', 'phone', 'address', 'city', 'state'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    recruitment_guard_post($slug, 'help', 4);
    $postedAge = trim((string) ($_POST['age'] ?? ''));
    $isChild = $postedAge !== '' && ctype_digit($postedAge) && (int) $postedAge < $minorAge;
    [$consentRules, $consentLabels] = recruitment_consent_rules($isChild ? $consents : array_values(array_diff($consents, ['minor'])));
    $rules = personal_rules($required);
    unset($rules['date_of_birth']);
    [$data, $errors] = validate($_POST, [
        'help_category' => 'required|in:' . implode(',', array_keys(HELP_CATEGORIES)),
    ] + $rules + [
        'age'            => 'integer|min:0|max:120',
        'family_members' => 'integer|min:1|max:30',
        'annual_income'  => 'numeric|min:0|max:100000000',
        'description'    => 'required|text|min:30|max:3000',
    ] + $consentRules, $consentLabels + ['help_category' => 'Type of support', 'description' => 'Details of the situation', 'family_members' => 'Family members', 'annual_income' => 'Annual family income']);
    $doc = recruitment_upload('document', 'document', 'documents/beneficiaries', false, $errors);
    if ($errors) {
        back_with_errors($errors, page_url($slug) . '#apply', $_POST);
    }
    try {
        $id = db_insert('beneficiaries', [
            'user_id'        => current_user_id(),
            'name'           => $data['name'],
            'email'          => $data['email'] ?: null,
            'phone'          => $data['phone'],
            'gender'         => $data['gender'] ?: null,
            'age'            => $data['age'] !== '' ? (int) $data['age'] : null,
            'address'        => $data['address'],
            'city'           => $data['city'],
            'state'          => $data['state'] ?: null,
            'pincode'        => $data['pincode'] ?: null,
            'family_members' => $data['family_members'] !== '' ? (int) $data['family_members'] : null,
            'annual_income'  => $data['annual_income'] !== '' ? round((float) $data['annual_income'], 2) : null,
            'help_category'  => $data['help_category'],
            'description'    => $data['description'],
            'document_path'  => $doc,
            'consent'        => 1,
            'ip_address'     => client_ip(),
        ]);
    } catch (Throwable $e) {
        delete_upload($doc);
        throw $e;
    }
    recruitment_finish_submission([
        'kind' => 'beneficiary', 'id' => $id, 'prefix' => 'HLP', 'table' => 'beneficiaries', 'ref_col' => 'reference_no', 'form' => 'help', 'status' => 'pending',
        'history_note' => 'Request received online.', 'headline' => 'Your request has been received.',
        'name' => $data['name'], 'email' => $data['email'], 'label' => 'Request for help', 'conversion' => 'help_request',
        'slug' => $slug, 'admin_subject' => 'New help request (' . HELP_CATEGORIES[$data['help_category']] . ')',
        'admin_html' => '<p><strong>' . e($data['name']) . '</strong>, ' . e($data['city']) . ' - ' . e(HELP_CATEGORIES[$data['help_category']]) . '</p><p>' . e(excerpt($data['description'], 300)) . '</p>',
        'next' => [
            ['title' => 'We read your request', 'text' => 'A member of our team reads every request carefully and in confidence.'],
            ['title' => 'We talk to you', 'text' => 'We call you on the number you gave to understand your situation better.'],
            ['title' => 'Verification', 'text' => 'We may ask for a document or visit, only to confirm the need.'],
            ['title' => 'Support or guidance', 'text' => 'Where we can help, we explain how. Where we cannot, we try to guide you to other support.'],
        ],
    ]);
}

$success = recruitment_take_success($slug);
recruitment_form_assets();
page_header($page, $success ? ['buttons' => []] : []);
if ($success) {
    echo recruitment_success_html($success);
    page_footer();
    return;
}

$user = current_user();
$category = (string) old('help_category', get_str('category'));
if (!isset(HELP_CATEGORIES[$category])) {
    $category = '';
}
render_sections($page, function () use ($user, $slug, $consents, $required, $category, $minorAge) {
    ?>
    <section class="section section-tint rc-apply-section" id="apply">
        <div class="container">
            <?= ui_heading('Tell us how we can help', 'Request support', 'Share as much as you are comfortable with. Fields marked * are required.', true) ?>
            <div class="rc-apply-layout">
                <div class="form-card rc-form-card">
                    <div class="notice rc-form-callout"><?= icon('shield') ?><div>Your information is kept confidential and used only to understand and respond to your request. You can also fill this form for a family member or neighbour <strong>with their permission</strong> - please mention your relationship in the details. If the person is under 18, the form should be filled by (or with the permission of) their parent or guardian.</div></div>
                    <?= recruitment_form_open($slug, true) ?>
                        <h3 class="form-section-title"><?= icon('life-buoy') ?>Type of support</h3>
                        <div class="form-grid">
                            <div class="form-group full">
                                <label for="help_category">What kind of support is needed?<span class="req">*</span></label>
                                <select id="help_category" name="help_category" required><?= options_html(HELP_CATEGORIES, $category) ?></select>
                                <?= field_error('help_category') ?>
                            </div>
                        </div>

                        <h3 class="form-section-title"><?= icon('user') ?>Details of the person who needs support</h3>
                        <div class="form-grid">
                            <?php render_personal_fields($user, $required, ['date_of_birth']); ?>
                            <div class="form-group">
                                <label for="age">Age</label>
                                <input id="age" type="number" name="age" min="0" max="120" inputmode="numeric" value="<?= e(old('age')) ?>">
                                <?= field_error('age') ?>
                            </div>
                            <div class="form-group">
                                <label for="family_members">Members in the family</label>
                                <input id="family_members" type="number" name="family_members" min="1" max="30" inputmode="numeric" value="<?= e(old('family_members')) ?>">
                                <?= field_error('family_members') ?>
                            </div>
                            <div class="form-group">
                                <label for="annual_income">Approximate annual family income (₹, optional)</label>
                                <input id="annual_income" type="number" name="annual_income" min="0" max="100000000" step="1" inputmode="numeric" value="<?= e(old('annual_income')) ?>">
                                <?= field_error('annual_income') ?>
                            </div>
                        </div>

                        <h3 class="form-section-title"><?= icon('message') ?>About the situation</h3>
                        <div class="form-grid">
                            <div class="form-group full">
                                <label for="description">Please describe the situation and the help needed<span class="req">*</span></label>
                                <textarea id="description" name="description" rows="5" required minlength="30" maxlength="3000" data-counter><?= e(old('description')) ?></textarea>
                                <?= field_error('description') ?>
                            </div>
                            <?= recruitment_file_field('document', 'Supporting document (optional)', false, 'For example a medical prescription, school letter or income certificate - one PDF, JPG or PNG. Please do not upload Aadhaar or bank card images.', false) ?>
                        </div>

                        <?= recruitment_consent_html($consents, ['minor' => 'Required only if the person who needs support is under ' . $minorAge . ' years of age.']) ?>
                        <div class="form-actions">
                            <button class="btn btn-primary btn-lg" type="submit" data-loading="Sending…"><?= icon('send') ?>Send request</button>
                        </div>
                        <p class="form-note"><?= icon('info') ?><span>Sending a request does not guarantee support - but every request is read with care. In a medical or other emergency, please call 112 first.</span></p>
                    </form>
                </div>
                <?= recruitment_apply_aside([
                    ['title' => 'Send your request', 'text' => 'Online, in a few minutes.'],
                    ['title' => 'We call you', 'text' => 'To understand your situation.'],
                    ['title' => 'Verification', 'text' => 'Only what is needed to confirm.'],
                    ['title' => 'Support or guidance', 'text' => 'We explain what we can do.'],
                ], '<div class="aside-card"><h3>' . icon('list') . ' Welfare schemes</h3><p>Many families are eligible for government schemes. Our volunteers can guide you free of charge, and verified scheme guides are published on our Welfare Schemes page.</p><a class="link-arrow" href="' . e(page_url('schemes')) . '">Explore welfare schemes ' . icon('arrow-right') . '</a></div>', [
                    'Privacy Policy' => page_url('privacy-policy'), 'Check the status of a request' => page_url('application-status'),
                    'Beneficiary support' => page_url('beneficiary-support'), 'Contact us' => page_url('contact'),
                ]) ?>
            </div>
        </div>
    </section>
    <?php
});
page_footer();
