<?php
/**
 * Create / edit a volunteer teacher authorization letter.
 * ?application=ID prefills it from a volunteer teacher application; ?id=N edits an issued letter (the reference
 * number and verification code never change; revoked letters cannot be edited).
 * The ID number is accepted in full or as its last 4 characters and only the masked form is saved
 * (never a full Aadhaar number); the mobile number is saved as XXXXXXXX26.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('letters.manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$letter = $id ? letter_get($id) : null;
if ($id && !$letter) {
    flash('error', 'Letter not found.');
    admin_redirect('letters.php');
}
if ($letter && $letter['status'] === 'revoked') {
    flash('error', 'Letter ' . $letter['ref_no'] . ' has been revoked and can no longer be changed.');
    admin_redirect('letter-print.php?id=' . $id);
}
$appId = $letter ? (int) $letter['application_id'] : (int) ($_GET['application'] ?? $_POST['application_id'] ?? 0);
$app = $appId ? db_row('SELECT id, application_no, name, phone, gender, city, state, status FROM teacher_applications WHERE id = ?', [$appId]) : null;
if (!$letter && $appId && !$app) {
    flash('error', 'Volunteer teacher application not found.');
    admin_redirect('letter-edit.php');
}
$self = 'letter-edit.php' . ($letter ? '?id=' . $id : ($app ? '?application=' . (int) $app['id'] : ''));
$states = indian_states();

// ---------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    [$data, $errors] = validate($_POST, [
        'candidate_name'        => 'required|min:2|max:120',
        'pronoun'               => 'required|in:he,she,they',
        'id_type'               => 'required|in:' . implode(',', array_keys(LETTER_ID_TYPES)),
        'id_label_other'        => 'max:40',
        'addressee'             => 'required|min:3|max:160',
        'school_name'           => 'required|min:3|max:200',
        'school_address'        => 'required|text|min:3|max:400',
        'district'              => 'required|min:2|max:80',
        'state'                 => 'required|in:' . implode(',', $states),
        'letter_date'           => 'required|date',
        'subject'               => 'required|min:5|max:255',
        'salutation'            => 'required|min:2|max:80',
        'body_template'         => 'required|text|min:30|max:2000',
        'enclosures'            => 'text|max:1500',
        'closing_text'          => 'max:255',
        'signatory_name'        => 'max:120',
        'signatory_designation' => 'max:120',
    ], [
        'candidate_name' => 'Candidate name', 'id_type' => 'ID type', 'id_label_other' => 'Name of the ID document', 'addressee' => 'Addressed to',
        'school_name' => 'School name', 'school_address' => 'School address', 'letter_date' => 'Letter date', 'body_template' => 'Letter text',
        'closing_text' => 'Closing line', 'signatory_name' => 'Signatory name', 'signatory_designation' => 'Signatory designation',
    ]);

    // ID number: the raw value is used only here to build the masked form and is never stored, logged or echoed back
    $idLabel = $data['id_type'] === 'other' ? $data['id_label_other'] : (LETTER_ID_TYPES[$data['id_type']] ?? 'ID');
    if ($data['id_type'] === 'other' && mb_strlen($data['id_label_other']) < 2 && !isset($errors['id_type'])) {
        $errors['id_label_other'] = 'Enter the name of the ID document, e.g. Driving Licence.';
    }
    $idMasked = $letter ? (string) $letter['id_masked'] : '';
    $idRaw = post_str('id_number');
    if (trim($idRaw) !== '') {
        [$masked, $idError] = letter_mask_id($data['id_type'], $idRaw);
        if ($idError !== null) {
            $errors['id_number'] = $idError;
        } else {
            $idMasked = $masked;
        }
    } elseif (!$letter || $data['id_type'] !== $letter['id_type']) {
        $errors['id_number'] = $letter ? 'Enter the ID number again for the new ID type (full number or last 4 characters).' : 'ID number is required (full number or only its last 4 characters).';
    }
    unset($idRaw);

    [$mobileMasked, $mobileError] = letter_mask_mobile(post_str('mobile'));
    if ($mobileError !== null) {
        $errors['mobile'] = $mobileError;
    }

    if (!isset($errors['letter_date']) && ($data['letter_date'] < '2020-01-01' || $data['letter_date'] > date('Y-m-d', strtotime('+90 days')))) {
        $errors['letter_date'] = 'Please choose a letter date between 2020 and 90 days from today.';
    } elseif (!isset($errors['letter_date']) && $letter && (int) substr($data['letter_date'], 0, 4) !== (int) $letter['ref_year']) {
        $errors['letter_date'] = 'The date must stay in ' . (int) $letter['ref_year'] . ' because the reference number ' . $letter['ref_no'] . ' belongs to that year. Create a new letter for another year.';
    }
    if (!isset($errors['body_template']) && ($unknown = letter_unknown_placeholders($data['body_template']))) {
        $errors['body_template'] = 'Unknown placeholder' . (count($unknown) > 1 ? 's' : '') . ': ' . implode(', ', $unknown) . '. Use only the placeholders listed next to the form.';
    }
    $enclosures = letter_enclosure_list($data['enclosures']);
    if (count(preg_split('/\R/', trim($data['enclosures'])) ?: []) > 10) {
        $errors['enclosures'] = 'Please list at most 10 enclosures (one per line).';
    }

    if ($errors) {
        $old = $_POST;
        $old['id_number'] = '';   // never keep an identity number (or a full mobile number) in the session
        $old['mobile'] = $mobileError === null ? $mobileMasked : '';
        back_with_errors($errors, 'admin/' . $self, $old);
    }

    $fields = [
        'candidate_name' => $data['candidate_name'], 'pronoun' => $data['pronoun'],
        'id_type' => $data['id_type'], 'id_label' => mb_substr($idLabel, 0, 60), 'id_masked' => $idMasked,
        'mobile_masked' => $mobileMasked !== '' ? $mobileMasked : null,
        'addressee' => $data['addressee'], 'school_name' => $data['school_name'],
        'school_address' => trim((string) preg_replace("/\n{2,}/", "\n", $data['school_address'])),
        'district' => $data['district'], 'state' => $data['state'], 'subject' => $data['subject'], 'salutation' => $data['salutation'],
        'body_template' => $data['body_template'], 'enclosures' => json_encode($enclosures, JSON_UNESCAPED_UNICODE),
        'closing_text' => $data['closing_text'] !== '' ? $data['closing_text'] : null, 'letter_date' => $data['letter_date'],
        'signatory_name' => $data['signatory_name'] !== '' ? $data['signatory_name'] : null,
        'signatory_designation' => $data['signatory_designation'] !== '' ? $data['signatory_designation'] : null,
    ];
    $fields['body'] = letter_resolve_body($data['body_template'], $fields);

    if ($letter) {
        $changed = [];
        foreach ($fields as $k => $v) {
            if ((string) $v !== (string) $letter[$k]) {
                $changed[] = $k;
            }
        }
        if (!$changed) {
            flash('info', 'No changes to save.');
            admin_redirect('letter-print.php?id=' . $id);
        }
        db_update('authorization_letters', $fields + ['updated_by' => admin_id()], 'id = :id', ['id' => $id]);
        admin_log('letter_updated', 'Authorization letter ' . $letter['ref_no'] . ' (#' . $id . ') updated: ' . implode(', ', $changed));
        flash('success', 'Letter ' . $letter['ref_no'] . ' saved.');
        admin_redirect('letter-print.php?id=' . $id);
    }

    $created = letter_create($fields + ['letter_type' => 'volunteer_teacher', 'application_id' => $app ? (int) $app['id'] : null], admin_id());
    admin_log('letter_created', 'Authorization letter ' . $created['ref_no'] . ' (#' . $created['id'] . ') created' . ($app ? ' from application ' . $app['application_no'] : ''));
    if ($app) {
        record_application_history('teacher', (int) $app['id'], 'note', 'Authorization letter ' . $created['ref_no'] . ' generated.', false);
    }
    flash('success', 'Letter ' . $created['ref_no'] . ' created. Check it below, then print it or save it as PDF.');
    admin_redirect('letter-print.php?id=' . $created['id']);
}

// ---------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------
if ($letter) {
    $v = $letter;
    $v['enclosures'] = implode("\n", letter_enclosure_list($letter['enclosures']));
    $v['id_label_other'] = $letter['id_type'] === 'other' ? $letter['id_label'] : '';
} else {
    $pronoun = 'they';
    if ($app && $app['gender'] === 'male') {
        $pronoun = 'he';
    } elseif ($app && $app['gender'] === 'female') {
        $pronoun = 'she';
    }
    $v = [
        'candidate_name' => $app['name'] ?? '', 'pronoun' => $pronoun, 'id_type' => 'aadhaar', 'id_label_other' => '',
        'mobile_masked' => $app ? letter_mask_mobile((string) $app['phone'])[0] : '',
        'addressee' => letter_setting('addressee'), 'school_name' => '', 'school_address' => '', 'district' => '',
        'state' => ($app && in_array($app['state'], $states, true)) ? $app['state'] : '',
        'letter_date' => date('Y-m-d'), 'subject' => letter_setting('subject'), 'salutation' => letter_setting('salutation'),
        'body_template' => letter_setting('body'), 'enclosures' => implode("\n", letter_enclosure_list(letter_setting('enclosures'))),
        'closing_text' => letter_setting('closing'), 'signatory_name' => letter_setting('signatory_name'), 'signatory_designation' => letter_setting('signatory_designation'),
    ];
}
$val = function (string $field) use ($v): string {
    return (string) old($field, (string) ($v[$field] ?? ''));
};
$existing = (!$letter && $app) ? db_rows('SELECT id, ref_no, letter_date, status FROM authorization_letters WHERE application_id = ? ORDER BY id DESC', [(int) $app['id']]) : [];
$idType = $val('id_type');
$nextRef = letter_ref_prefix() . '/' . date('Y') . '/' . str_pad((string) letter_next_seq((int) date('Y')), 3, '0', STR_PAD_LEFT);

$GLOBALS['adminExtraScripts'][] = asset('assets/js/modules/admin-letters.js');
admin_page_start($letter ? 'Edit letter ' . $letter['ref_no'] : 'New authorization letter');
?>
<div class="page-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('letters.php')) ?>"><?= icon('chevron-left') ?>All letters</a>
    <div class="actions">
        <?php if ($letter): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('letter-print.php?id=' . $id)) ?>"><?= icon('printer') ?>Print view</a><?php endif; ?>
        <?php if ($app && admin_can('recruitment.view')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('application-view.php?type=teacher&id=' . (int) $app['id'])) ?>"><?= icon('book') ?>Application <?= e($app['application_no']) ?></a><?php endif; ?>
    </div>
</div>

<?php if ($existing): ?>
    <div class="alert alert-info"><?= icon('info') ?><div>This application already has <?= count($existing) === 1 ? 'a letter' : count($existing) . ' letters' ?>:
        <?php foreach ($existing as $i => $ex): ?><?= $i ? ', ' : ' ' ?><a href="<?= e(admin_url('letter-print.php?id=' . (int) $ex['id'])) ?>"><?= e($ex['ref_no']) ?></a> (<?= e(format_date($ex['letter_date'])) ?>, <?= e($ex['status']) ?>)<?php endforeach; ?>.
        Creating another letter gives it a new reference number.</div></div>
<?php endif; ?>

<form method="post" class="admin-grid-main al-form" data-letter-form data-org="<?= e(letter_org_name()) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <?php if ($letter): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php elseif ($app): ?><input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>"><?php endif; ?>
    <div>
        <section class="panel">
            <div class="panel-head"><h2>Candidate</h2><?php if ($app): ?><span class="badge badge-blue"><?= icon('link') ?>From <?= e($app['application_no']) ?></span><?php endif; ?></div>
            <div class="form-grid">
                <div class="form-group"><label for="candidate_name">Candidate name <span class="req">*</span></label>
                    <input id="candidate_name" type="text" name="candidate_name" required maxlength="120" value="<?= e($val('candidate_name')) ?>" data-ph="candidate_name"><?= field_error('candidate_name') ?></div>
                <div class="form-group" role="radiogroup" aria-labelledby="pronoun-label"><span class="label" id="pronoun-label">Refer to the candidate as <span class="req">*</span></span>
                    <div class="check-group">
                        <?php foreach (LETTER_PRONOUNS as $k => $label): ?>
                            <label class="check-chip"><input type="radio" name="pronoun" value="<?= e($k) ?>"<?= $val('pronoun') === $k ? ' checked' : '' ?> data-ph="pronoun"><span><?= e($label) ?></span></label>
                        <?php endforeach; ?>
                    </div><?= field_error('pronoun') ?></div>
                <div class="form-group"><label for="id_type">ID document <span class="req">*</span></label>
                    <select id="id_type" name="id_type" required data-ph="id_type"><?= options_html(LETTER_ID_TYPES, $idType, true, '') ?></select><?= field_error('id_type') ?></div>
                <div class="form-group" data-id-other<?= $idType === 'other' ? '' : ' hidden' ?>><label for="id_label_other">Name of the ID document <span class="req">*</span></label>
                    <input id="id_label_other" type="text" name="id_label_other" maxlength="40" placeholder="e.g. Driving Licence" value="<?= e($val('id_label_other')) ?>" data-ph="id_label_other"><?= field_error('id_label_other') ?></div>
                <div class="form-group"><label for="id_number">ID number<?= $letter ? '' : ' <span class="req">*</span>' ?></label>
                    <input id="id_number" type="text" name="id_number" maxlength="24" inputmode="text" autocomplete="off" spellcheck="false"<?= $letter ? '' : ' required' ?>
                        placeholder="<?= e($letter ? 'Leave blank to keep ' . $letter['id_masked'] : 'Full number or last 4 digits') ?>" aria-describedby="id_number_help id_number_preview"
                        data-id-input data-current="<?= e($letter['id_masked'] ?? '') ?>">
                    <span class="help" id="id_number_help"><?= icon('lock') ?> Only the last 4 characters are kept and printed (Aadhaar as XXXX XXXX 1234). The full number is never stored.</span>
                    <span class="al-mask-preview" id="id_number_preview" aria-live="polite"><?= $letter ? 'Printed as: <strong>' . e($letter['id_masked']) . '</strong>' : '' ?></span>
                    <?= field_error('id_number') ?></div>
                <div class="form-group"><label for="mobile">Mobile number</label>
                    <input id="mobile" type="text" name="mobile" maxlength="20" inputmode="tel" autocomplete="off" value="<?= e($val('mobile') !== '' ? $val('mobile') : (string) ($v['mobile_masked'] ?? '')) ?>" aria-describedby="mobile_help">
                    <span class="help" id="mobile_help">Printed with only the last 2 digits (XXXXXXXX26). Leave blank to print an empty line.</span><?= field_error('mobile') ?></div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>School (addressed to)</h2></div>
            <div class="form-grid">
                <div class="form-group full"><label for="addressee">Designation line <span class="req">*</span></label>
                    <input id="addressee" type="text" name="addressee" required maxlength="160" value="<?= e($val('addressee')) ?>"><?= field_error('addressee') ?></div>
                <div class="form-group full"><label for="school_name">School name <span class="req">*</span></label>
                    <input id="school_name" type="text" name="school_name" required maxlength="200" value="<?= e($val('school_name')) ?>" placeholder="e.g. Government Primary School, …" data-ph="school_name"><?= field_error('school_name') ?></div>
                <div class="form-group full"><label for="school_address">School address <span class="req">*</span></label>
                    <textarea id="school_address" name="school_address" required rows="2" maxlength="400" placeholder="Village / locality, block, post office" data-ph="school_address"><?= e($val('school_address')) ?></textarea>
                    <span class="help">One or two lines. District and state are added below it.</span><?= field_error('school_address') ?></div>
                <div class="form-group"><label for="district">District <span class="req">*</span></label>
                    <input id="district" type="text" name="district" required maxlength="80" value="<?= e($val('district')) ?>" data-ph="district"><?= field_error('district') ?></div>
                <div class="form-group"><label for="state">State <span class="req">*</span></label>
                    <select id="state" name="state" required data-ph="state"><?= state_options($val('state')) ?></select><?= field_error('state') ?></div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Letter text</h2></div>
            <div class="form-grid">
                <div class="form-group full"><label for="subject">Subject <span class="req">*</span></label>
                    <input id="subject" type="text" name="subject" required maxlength="255" value="<?= e($val('subject')) ?>"><?= field_error('subject') ?></div>
                <div class="form-group full"><label for="salutation">Salutation <span class="req">*</span></label>
                    <input id="salutation" type="text" name="salutation" required maxlength="80" value="<?= e($val('salutation')) ?>"><?= field_error('salutation') ?></div>
                <div class="form-group full"><label for="body_template">Letter text <span class="req">*</span></label>
                    <textarea id="body_template" name="body_template" required rows="8" maxlength="2000" data-body-template aria-describedby="body_help"><?= e($val('body_template')) ?></textarea>
                    <span class="help" id="body_help">Leave an empty line between paragraphs. Text between **double stars** is printed in bold. Placeholders such as {candidate_name} are filled in from the fields above.</span>
                    <?= field_error('body_template') ?></div>
                <div class="form-group full al-preview-wrap" hidden data-body-preview-wrap>
                    <span class="label" id="body-preview-label">Preview</span>
                    <div class="al-body-preview" data-body-preview aria-labelledby="body-preview-label"></div>
                </div>
                <div class="form-group full"><label for="enclosures">Enclosures</label>
                    <textarea id="enclosures" name="enclosures" rows="4" maxlength="1500"><?= e($val('enclosures')) ?></textarea>
                    <span class="help">One per line (at most 10). Printed as a bullet list under "Enclosed:".</span><?= field_error('enclosures') ?></div>
                <div class="form-group full"><label for="closing_text">Closing line</label>
                    <input id="closing_text" type="text" name="closing_text" maxlength="255" value="<?= e($val('closing_text')) ?>"><?= field_error('closing_text') ?></div>
            </div>
        </section>
    </div>

    <div>
        <section class="panel">
            <div class="panel-head"><h2>Letter</h2><?= $letter ? status_badge($letter['status']) : '' ?></div>
            <?php if ($letter): ?>
                <dl class="detail-list al-dl">
                    <dt>Reference</dt><dd><strong><?= e($letter['ref_no']) ?></strong></dd>
                    <dt>Verification code</dt><dd><code><?= e(letter_format_code($letter['verify_code'])) ?></code></dd>
                    <dt>Created</dt><dd><?= e(format_date($letter['created_at'], 'd M Y, h:i A')) ?><?= $letter['created_by_name'] ? ' by ' . e($letter['created_by_name']) : '' ?></dd>
                </dl>
            <?php else: ?>
                <p class="small muted">The reference number is assigned when you save (next for <?= date('Y') ?>: <strong><?= e($nextRef) ?></strong>; letters dated in another year are numbered in that year's series).</p>
            <?php endif; ?>
            <div class="form-group mb-2"><label for="letter_date">Letter date <span class="req">*</span></label>
                <input id="letter_date" type="date" name="letter_date" required value="<?= e($val('letter_date')) ?>" max="<?= e(date('Y-m-d', strtotime('+90 days'))) ?>"><?= field_error('letter_date') ?></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?><?= $letter ? 'Save changes' : 'Create letter' ?></button></div>
            <p class="help mt-2">After saving you see the A4 print view; print it or choose "Save as PDF" in the print dialog.</p>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Signatory</h2></div>
            <div class="form-group mb-2"><label for="signatory_name">Name (optional)</label>
                <input id="signatory_name" type="text" name="signatory_name" maxlength="120" value="<?= e($val('signatory_name')) ?>"><?= field_error('signatory_name') ?></div>
            <div class="form-group"><label for="signatory_designation">Designation (optional)</label>
                <input id="signatory_designation" type="text" name="signatory_designation" maxlength="120" value="<?= e($val('signatory_designation')) ?>" placeholder="e.g. Secretary"><?= field_error('signatory_designation') ?></div>
            <p class="help mt-2">Printed under "Authorized Signatory". The signature and seal images are managed in <a href="<?= e(admin_url('letters.php?tab=settings')) ?>">letter settings</a>.</p>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Placeholders</h2></div>
            <ul class="al-ph-list">
                <?php foreach (letter_placeholders() as $ph => $help): ?><li><code><?= e($ph) ?></code><span><?= e($help) ?></span></li><?php endforeach; ?>
            </ul>
        </section>
    </div>
</form>
<?php
admin_page_end();
