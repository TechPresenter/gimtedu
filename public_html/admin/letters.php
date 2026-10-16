<?php
/**
 * Authorization letters: list (search, status / year filters, CSV export), revoke with a reason, and settings
 * (reference prefix and numbering, default texts, signatory; seal image, private signature image and corporate
 * office address - those three only for staff who may change website settings).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('letters.manage');

$tab = get_str('tab') === 'settings' ? 'settings' : 'list';
$canBrand = admin_can('settings.manage');
$year = (int) date('Y');

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = post_str('action');
    admin_post_only($action === 'revoke' ? 'letters.php' : 'letters.php?tab=settings');

    if ($action === 'revoke') {
        $back = admin_back('letters.php');
        $lid = (int) ($_POST['id'] ?? 0);
        $l = $lid ? db_row('SELECT id, ref_no, status, application_id FROM authorization_letters WHERE id = ?', [$lid]) : null;
        // the reason is stored, logged and added to the application history: never with a full Aadhaar number
        $reason = mb_substr(letter_mask_aadhaar_in_text(clean_string($_POST['reason'] ?? '')), 0, 255);
        if (!$l) {
            flash('error', 'Letter not found.');
        } elseif ($l['status'] === 'revoked') {
            flash('info', 'Letter ' . $l['ref_no'] . ' is already revoked.');
        } elseif (mb_strlen($reason) < 5) {
            flash('error', 'Please give a reason for revoking ' . $l['ref_no'] . ' (at least 5 characters).');
        } else {
            db_update('authorization_letters', ['status' => 'revoked', 'revoked_reason' => $reason, 'revoked_at' => date('Y-m-d H:i:s'), 'revoked_by' => admin_id(), 'updated_by' => admin_id()],
                "id = :id AND status = 'issued'", ['id' => $lid]);
            if (!empty($l['application_id'])) {
                record_application_history('teacher', (int) $l['application_id'], 'note', 'Authorization letter ' . $l['ref_no'] . ' revoked: ' . $reason, false);
            }
            admin_log('letter_revoked', 'Authorization letter ' . $l['ref_no'] . ' (#' . $lid . ') revoked: ' . mb_substr($reason, 0, 200));
            flash('success', 'Letter ' . $l['ref_no'] . ' revoked. The verification page now shows it as revoked.');
        }
        admin_redirect($back);
    }

    if ($action === 'defaults') {
        [$data, $errors] = validate($_POST, [
            'ref_prefix' => 'required|max:12', 'next_seq' => 'integer|min:1|max:999999',
            'addressee' => 'required|min:3|max:160', 'subject' => 'required|min:5|max:255', 'salutation' => 'required|min:2|max:80',
            'body' => 'required|text|min:30|max:1600', 'enclosures' => 'text|max:1500', 'closing' => 'max:255',
            'signatory_name' => 'max:120', 'signatory_designation' => 'max:120',
        ], ['ref_prefix' => 'Reference prefix', 'next_seq' => 'Next number', 'addressee' => 'Addressed to', 'body' => 'Letter text', 'closing' => 'Closing line']);
        $prefix = strtoupper($data['ref_prefix']);
        if (!isset($errors['ref_prefix']) && !preg_match('/^[A-Z0-9][A-Z0-9-]{0,11}$/', $prefix)) {
            $errors['ref_prefix'] = 'Use 1-12 letters, digits or hyphens (e.g. EIF).';
        }
        if (!isset($errors['body']) && ($unknown = letter_unknown_placeholders($data['body']))) {
            $errors['body'] = 'Unknown placeholder' . (count($unknown) > 1 ? 's' : '') . ': ' . implode(', ', $unknown) . '.';
        }
        $maxSeq = (int) db_value('SELECT COALESCE(MAX(ref_seq), 0) FROM authorization_letters WHERE ref_year = ?', [$year]);
        if (!isset($errors['next_seq']) && $data['next_seq'] !== '' && (int) $data['next_seq'] <= $maxSeq) {
            $errors['next_seq'] = 'The next number must be higher than ' . $maxSeq . ', the last number already used in ' . $year . '.';
        }
        foreach (['addressee', 'subject', 'salutation', 'body', 'enclosures', 'closing', 'signatory_name', 'signatory_designation'] as $f) {
            if (!isset($errors[$f]) && letter_find_aadhaar((string) ($data[$f] ?? ''))) {
                $errors[$f] = 'This contains a full Aadhaar number, which is never stored or printed. Use the placeholder {id_number} instead.';
            }
        }
        if ($errors) {
            $old = [];
            foreach ($_POST as $k => $value) {   // no full Aadhaar number is kept in the session
                $old[$k] = is_string($value) ? letter_mask_aadhaar_in_text($value) : $value;
            }
            back_with_errors($errors, 'admin/letters.php?tab=settings', $old);
        }
        $values = [
            'ref_prefix' => $prefix, 'addressee' => $data['addressee'], 'subject' => $data['subject'], 'salutation' => $data['salutation'],
            'body' => $data['body'], 'enclosures' => implode("\n", letter_enclosure_list($data['enclosures'])), 'closing' => $data['closing'],
            'signatory_name' => $data['signatory_name'], 'signatory_designation' => $data['signatory_designation'],
        ];
        $changed = [];
        foreach ($values as $k => $value) {
            if (setting('letter_' . $k) !== $value) {
                save_setting('letter_' . $k, $value);
                $changed[] = $k;
            }
        }
        if ($data['next_seq'] !== '' && (int) $data['next_seq'] !== letter_next_seq($year)) {
            $next = letter_set_next_seq($year, (int) $data['next_seq']);
            $changed[] = 'next number ' . $year . ' = ' . $next;
        }
        if ($changed) {
            admin_log('letter_settings_updated', 'Letter settings changed: ' . implode(', ', $changed));
            flash('success', 'Letter settings saved.');
        } else {
            flash('info', 'No changes to save.');
        }
        admin_redirect('letters.php?tab=settings');
    }

    if ($action === 'branding') {
        if (!$canBrand) {
            flash('error', 'Only staff who may change website settings can change the seal, signature and office address.');
            admin_redirect('letters.php?tab=settings');
        }
        $notes = [];
        $address = mb_substr(clean_string($_POST['corporate_office_address'] ?? ''), 0, 255);
        if ($address !== setting('corporate_office_address')) {
            save_setting('corporate_office_address', $address);
            $notes[] = 'corporate office address';
        }
        // Seal: public image (also shown on the donate page)
        if (isset($_FILES['seal_image']['error']) && $_FILES['seal_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $path = admin_upload_image('seal_image', 'logo', 'Seal of ' . letter_org_name());
            if ($path) {
                save_setting('seal_image_path', $path);
                $notes[] = 'seal image uploaded';
            }
        } elseif (!empty($_POST['remove_seal']) && setting('seal_image_path') !== '') {
            save_setting('seal_image_path', '');   // the file stays in the media library
            $notes[] = 'seal image removed';
        }
        // Signature: PRIVATE image under uploads/documents/branding (denied by .htaccess), never in the media library
        if (isset($_FILES['signature_image']['error']) && $_FILES['signature_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = handle_upload('signature_image', 'image', 'documents/branding');
            if (!empty($up['error'])) {
                flash('error', 'Signature: ' . $up['error']);
            } elseif (!empty($up['path'])) {
                $old = setting('letter_signature_path');
                save_setting('letter_signature_path', $up['path']);
                if ($old !== '' && strpos($old, 'uploads/documents/branding/') === 0) {
                    delete_upload($old);
                }
                $notes[] = 'signature image uploaded';
            }
        } elseif (!empty($_POST['remove_signature']) && setting('letter_signature_path') !== '') {
            $old = setting('letter_signature_path');
            save_setting('letter_signature_path', '');
            if (strpos($old, 'uploads/documents/branding/') === 0) {
                delete_upload($old);
            }
            $notes[] = 'signature image removed';
        }
        if ($notes) {
            admin_log('letter_branding_updated', 'Letter branding: ' . implode(', ', $notes));
            flash('success', 'Saved: ' . implode(', ', $notes) . '.');
        } elseif (empty($_SESSION['_flash'])) {
            flash('info', 'No changes to save.');
        }
        admin_redirect('letters.php?tab=settings#branding');
    }

    flash('error', 'Unknown action.');
    admin_redirect('letters.php');
}

// ---------------------------------------------------------------------
// List data (also used by the CSV export)
// ---------------------------------------------------------------------
$q = mb_substr(clean_string(get_str('q')), 0, 100);
$status = get_str('status');
$fYear = (int) get_str('year');
$params = [];
$where = search_where(['l.ref_no', 'l.candidate_name', 'l.school_name', 'l.district'], $q, $params);
if ($fYear >= 2000 && $fYear <= 2100) {
    $where .= ' AND l.ref_year = ?';
    $params[] = $fYear;
} else {
    $fYear = 0;
}
$counts = ['issued' => 0, 'revoked' => 0];
foreach (db_rows("SELECT l.status, COUNT(*) n FROM authorization_letters l WHERE 1=1 $where GROUP BY l.status", $params) as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
if (in_array($status, ['issued', 'revoked'], true)) {
    $where .= ' AND l.status = ?';
    $params[] = $status;
} else {
    $status = '';
}

if (isset($_GET['export'])) {
    require_permission('exports.run');
    $rows = db_rows("SELECT l.*, cu.name AS created_by_name, t.application_no FROM authorization_letters l
                       LEFT JOIN users cu ON cu.id = l.created_by LEFT JOIN teacher_applications t ON t.id = l.application_id
                      WHERE 1=1 $where ORDER BY l.ref_year DESC, l.ref_seq DESC", $params);
    admin_log('letters_exported', count($rows) . ' authorization letters exported to CSV');
    send_csv('authorization-letters-' . date('Y-m-d') . '.csv',
        ['Reference', 'Letter date', 'Status', 'Candidate', 'ID type', 'ID (masked)', 'Mobile (masked)', 'Addressed to', 'School', 'School address', 'District', 'State',
            'Linked application', 'Created by', 'Created at', 'Times printed', 'Revoked at', 'Revocation reason'],
        (function () use ($rows) {
            foreach ($rows as $r) {
                yield [$r['ref_no'], $r['letter_date'], $r['status'], $r['candidate_name'], $r['id_label'], $r['id_masked'], (string) $r['mobile_masked'],
                    $r['addressee'], $r['school_name'], str_replace("\n", ', ', (string) $r['school_address']), $r['district'], $r['state'],
                    (string) $r['application_no'], (string) $r['created_by_name'], $r['created_at'], (int) $r['print_count'], (string) $r['revoked_at'], (string) $r['revoked_reason']];
            }
        })());
}

$p = paginate((int) db_value("SELECT COUNT(*) FROM authorization_letters l WHERE 1=1 $where", $params), 25);
$rows = db_rows("SELECT l.id, l.ref_no, l.letter_date, l.candidate_name, l.id_masked, l.id_label, l.school_name, l.district, l.state, l.status,
                        l.revoked_reason, l.revoked_at, l.print_count, l.created_at, cu.name AS created_by_name, t.application_no, l.application_id
                   FROM authorization_letters l LEFT JOIN users cu ON cu.id = l.created_by LEFT JOIN teacher_applications t ON t.id = l.application_id
                  WHERE 1=1 $where ORDER BY l.ref_year DESC, l.ref_seq DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$years = array_map('intval', array_column(db_rows('SELECT DISTINCT ref_year FROM authorization_letters ORDER BY ref_year DESC'), 'ref_year'));
$stats = db_row("SELECT COUNT(*) AS total, SUM(status = 'issued' AND ref_year = ?) AS this_year, SUM(status = 'revoked') AS revoked FROM authorization_letters", [$year]);

$GLOBALS['adminExtraScripts'][] = asset('assets/js/modules/admin-letters.js');
$actions = [['label' => 'New letter', 'url' => admin_url('letter-edit.php'), 'icon' => 'plus']];
if ($tab === 'list' && admin_can('exports.run') && $p['total']) {
    $actions[] = ['label' => 'Export CSV', 'url' => '?' . http_build_query(array_filter(['q' => $q, 'status' => $status, 'year' => $fYear ?: null, 'export' => 1])), 'icon' => 'download', 'style' => 'ghost'];
}
admin_page_start('Authorization Letters', $actions);
?>
<nav class="admin-tabs" aria-label="Authorization letters">
    <a href="<?= e(admin_url('letters.php')) ?>"<?= $tab === 'list' ? ' class="active" aria-current="page"' : '' ?>>Letters</a>
    <a href="<?= e(admin_url('letters.php?tab=settings')) ?>"<?= $tab === 'settings' ? ' class="active" aria-current="page"' : '' ?>>Settings</a>
</nav>

<?php if ($tab === 'list'): ?>
    <div class="stat-cards">
        <div class="stat-card"><span class="icon-circle"><?= icon('file-text') ?></span><div><strong><?= (int) ($stats['this_year'] ?? 0) ?></strong><small>Valid letters dated <?= $year ?></small></div></div>
        <div class="stat-card"><span class="icon-circle"><?= icon('x') ?></span><div><strong><?= (int) ($stats['revoked'] ?? 0) ?></strong><small>Revoked</small></div></div>
        <div class="stat-card"><span class="icon-circle"><?= icon('list') ?></span><div><strong><?= (int) ($stats['total'] ?? 0) ?></strong><small>All letters</small></div></div>
    </div>
    <?php
    $yearSelect = '<select name="year" aria-label="Year"><option value="">All years</option>';
    foreach ($years as $y) {
        $yearSelect .= '<option value="' . $y . '"' . ($fYear === $y ? ' selected' : '') . '>' . $y . '</option>';
    }
    $yearSelect .= '</select>';
    admin_filter_bar([], ['placeholder' => 'Reference, candidate, school or district', 'html' => $yearSelect, 'active' => $fYear > 0, 'hidden' => $status !== '' ? ['status' => $status] : []]);
    $chipQuery = array_filter(['q' => $q, 'year' => $fYear ?: null]);
    ?>
    <nav class="al-chipbar" aria-label="Filter by status">
        <a href="?<?= e(http_build_query($chipQuery)) ?>"<?= $status === '' ? ' class="active" aria-current="true"' : '' ?>>All <em><?= array_sum($counts) ?></em></a>
        <a href="?<?= e(http_build_query($chipQuery + ['status' => 'issued'])) ?>"<?= $status === 'issued' ? ' class="active" aria-current="true"' : '' ?>>Valid <em><?= $counts['issued'] ?></em></a>
        <a href="?<?= e(http_build_query($chipQuery + ['status' => 'revoked'])) ?>"<?= $status === 'revoked' ? ' class="active" aria-current="true"' : '' ?>>Revoked <em><?= $counts['revoked'] ?></em></a>
    </nav>
    <div class="panel">
        <?php if ($rows): ?>
            <div class="table-wrap">
                <table class="table al-table">
                    <thead><tr><th scope="col">Reference</th><th scope="col">Candidate</th><th scope="col">School</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><a href="<?= e(admin_url('letter-print.php?id=' . (int) $r['id'])) ?>"><strong><?= e($r['ref_no']) ?></strong></a><br><small class="muted"><?= e(format_date($r['letter_date'])) ?></small></td>
                            <td><?= e($r['candidate_name']) ?><br><small class="muted"><?= e($r['id_label']) ?> <span class="al-nowrap"><?= e($r['id_masked']) ?></span></small>
                                <?php if ($r['application_no']): ?><br><small class="muted"><?= icon('link') ?><?= e($r['application_no']) ?></small><?php endif; ?></td>
                            <td><?= e($r['school_name']) ?><br><small class="muted"><?= e($r['district'] . ', ' . $r['state']) ?></small></td>
                            <td><?= status_badge($r['status'] === 'issued' ? 'valid' : 'revoked') ?>
                                <?php if ($r['status'] === 'revoked'): ?><br><small class="muted" title="<?= e((string) $r['revoked_reason']) ?>"><?= e(format_date($r['revoked_at'])) ?></small><?php endif; ?>
                                <br><small class="muted">by <?= e($r['created_by_name'] ?: '-') ?><?= (int) $r['print_count'] ? ' · printed ' . (int) $r['print_count'] . '×' : '' ?></small></td>
                            <td class="actions-cell al-actions">
                                <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('letter-print.php?id=' . (int) $r['id'])) ?>"><?= icon('printer') ?>View &amp; print</a>
                                <?php if ($r['status'] === 'issued'): ?>
                                    <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('letter-edit.php?id=' . (int) $r['id'])) ?>"><?= icon('edit') ?>Edit</a>
                                    <details class="al-revoke">
                                        <summary class="btn btn-ghost btn-sm"><?= icon('x') ?>Revoke</summary>
                                        <form method="post" data-confirm="Revoke letter <?= e($r['ref_no']) ?>? The verification page will show it as revoked. This cannot be undone.">
                                            <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                            <label for="reason-<?= (int) $r['id'] ?>">Reason (kept for staff only)</label>
                                            <input id="reason-<?= (int) $r['id'] ?>" type="text" name="reason" required minlength="5" maxlength="255">
                                            <button class="btn btn-danger btn-sm" type="submit">Revoke letter</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= pagination_links($p) ?>
        <?php elseif ($q !== '' || $status !== '' || $fYear): ?>
            <p class="muted">No letters match these filters.</p>
        <?php else: ?>
            <div class="al-empty">
                <span class="icon-circle"><?= icon('file-text') ?></span>
                <h2>No authorization letters yet</h2>
                <p class="muted">Create a letter here, or open a volunteer teacher application and use <strong>Generate authorization letter</strong> to fill it in from the application.</p>
                <a class="btn btn-primary btn-sm" href="<?= e(admin_url('letter-edit.php')) ?>"><?= icon('plus') ?>New letter</a>
                <?php if (admin_can('recruitment.view')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('applications.php?type=teacher')) ?>"><?= icon('book') ?>Volunteer teacher applications</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php else:
    $sv = function (string $key) {
        return (string) old($key, letter_setting($key));
    };
    $nextSeq = letter_next_seq($year);
    $maxSeq = (int) db_value('SELECT COALESCE(MAX(ref_seq), 0) FROM authorization_letters WHERE ref_year = ?', [$year]);
    $seal = foundation_seal_url();
    $signature = letter_signature_data_uri();
    ?>
    <div class="admin-grid-main">
        <form method="post" class="panel" id="defaults">
            <?= csrf_field() ?><input type="hidden" name="action" value="defaults">
            <div class="panel-head"><h2>Numbering &amp; default text</h2></div>
            <div class="form-grid">
                <div class="form-group"><label for="ref_prefix">Reference prefix <span class="req">*</span></label>
                    <input id="ref_prefix" type="text" name="ref_prefix" required maxlength="12" pattern="[A-Za-z0-9][A-Za-z0-9\-]{0,11}" value="<?= e($sv('ref_prefix')) ?>">
                    <span class="help">Reference numbers look like <?= e(letter_ref_prefix()) ?>/<?= $year ?>/<?= str_pad((string) $nextSeq, 3, '0', STR_PAD_LEFT) ?>.</span><?= field_error('ref_prefix') ?></div>
                <div class="form-group"><label for="next_seq">Next number for <?= $year ?></label>
                    <input id="next_seq" type="number" name="next_seq" min="<?= $maxSeq + 1 ?>" max="999999" value="<?= e((string) old('next_seq', (string) $nextSeq)) ?>">
                    <span class="help">Raise it to continue after letters issued on paper (e.g. 12 after <?= e(letter_ref_prefix()) ?>/<?= $year ?>/011). Numbers already used cannot be reused.</span><?= field_error('next_seq') ?></div>
                <div class="form-group full"><label for="s_addressee">Addressed to (designation line) <span class="req">*</span></label>
                    <input id="s_addressee" type="text" name="addressee" required maxlength="160" value="<?= e($sv('addressee')) ?>"><?= field_error('addressee') ?></div>
                <div class="form-group full"><label for="s_subject">Subject <span class="req">*</span></label>
                    <input id="s_subject" type="text" name="subject" required maxlength="255" value="<?= e($sv('subject')) ?>"><?= field_error('subject') ?></div>
                <div class="form-group full"><label for="s_salutation">Salutation <span class="req">*</span></label>
                    <input id="s_salutation" type="text" name="salutation" required maxlength="80" value="<?= e($sv('salutation')) ?>"><?= field_error('salutation') ?></div>
                <div class="form-group full"><label for="s_body">Letter text <span class="req">*</span></label>
                    <textarea id="s_body" name="body" required rows="8" maxlength="1600"><?= e($sv('body')) ?></textarea>
                    <span class="help">Empty line between paragraphs; **double stars** for bold. Placeholders: <?= e(implode(' ', array_keys(letter_placeholders()))) ?></span><?= field_error('body') ?></div>
                <div class="form-group full"><label for="s_enclosures">Enclosures (one per line, at most 8)</label>
                    <textarea id="s_enclosures" name="enclosures" rows="4" maxlength="1500"><?= e($sv('enclosures')) ?></textarea><?= field_error('enclosures') ?></div>
                <div class="form-group full"><label for="s_closing">Closing line</label>
                    <input id="s_closing" type="text" name="closing" maxlength="255" value="<?= e($sv('closing')) ?>"><?= field_error('closing') ?></div>
                <div class="form-group"><label for="s_signatory_name">Signatory name</label>
                    <input id="s_signatory_name" type="text" name="signatory_name" maxlength="120" value="<?= e($sv('signatory_name')) ?>"><?= field_error('signatory_name') ?></div>
                <div class="form-group"><label for="s_signatory_designation">Signatory designation</label>
                    <input id="s_signatory_designation" type="text" name="signatory_designation" maxlength="120" value="<?= e($sv('signatory_designation')) ?>" placeholder="e.g. Secretary"><?= field_error('signatory_designation') ?></div>
            </div>
            <p class="help">These are the starting values for new letters; letters already created keep their own text.</p>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save settings</button></div>
        </form>

        <div>
            <form method="post" enctype="multipart/form-data" class="panel" id="branding">
                <?= csrf_field() ?><input type="hidden" name="action" value="branding">
                <div class="panel-head"><h2>Seal, signature &amp; office</h2></div>
                <?php if (!$canBrand): ?><p class="small muted"><?= icon('lock') ?> Only an administrator (website settings permission) can change these.</p><?php endif; ?>
                <fieldset class="al-fieldset"<?= $canBrand ? '' : ' disabled' ?>>
                    <h3 class="al-sub">Foundation seal</h3>
                    <div class="al-img-box"><?= $seal !== '' ? '<img src="' . e($seal) . '" alt="Current foundation seal">' : '<span class="muted small">No seal uploaded</span>' ?></div>
                    <div class="form-group"><label for="seal_image">Upload seal (PNG with transparent background works best)</label>
                        <input id="seal_image" type="file" name="seal_image" accept="image/png,image/jpeg,image/webp"></div>
                    <p class="help">Public image: printed on letters and shown on the donate page.</p>
                    <?php if ($seal !== ''): ?><label class="checkbox small"><input type="checkbox" name="remove_seal" value="1"><span>Remove the seal from letters</span></label><?php endif; ?>

                    <h3 class="al-sub">Authorized signatory's signature</h3>
                    <div class="al-img-box"><?= $signature !== '' ? '<img src="' . e($signature) . '" alt="Current signature image">' : '<span class="muted small">No signature uploaded - letters are signed by hand</span>' ?></div>
                    <div class="form-group"><label for="signature_image">Upload signature image</label>
                        <input id="signature_image" type="file" name="signature_image" accept="image/png,image/jpeg,image/webp"></div>
                    <p class="help"><?= icon('lock') ?> Stored privately (never reachable by a web address) and placed only on letters printed from this panel.</p>
                    <?php if ($signature !== ''): ?><label class="checkbox small"><input type="checkbox" name="remove_signature" value="1"><span>Delete the signature image</span></label><?php endif; ?>

                    <h3 class="al-sub">Corporate office</h3>
                    <div class="form-group"><label for="corporate_office_address">Corporate office address</label>
                        <textarea id="corporate_office_address" name="corporate_office_address" rows="2" maxlength="255"><?= e(setting('corporate_office_address')) ?></textarea>
                        <span class="help">Printed in the letter footer.</span></div>
                    <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit"><?= icon('upload') ?>Save</button></div>
                </fieldset>
            </form>
            <section class="panel">
                <div class="panel-head"><h2>Letterhead details</h2></div>
                <p class="small">The letterhead and footer use the registration details, email and focus-areas line from <?= admin_can('settings.manage') ? '<a href="' . e(admin_url('settings.php')) . '">Settings</a>' : 'Settings' ?>:</p>
                <dl class="detail-list al-dl">
                    <?php foreach (['cin_number' => 'CIN', 'ngo_id' => 'NGO ID', 'registration_authority' => 'Registered with', 'msme_udyam_number' => 'MSME / Udyam', 'contact_email' => 'Email', 'focus_areas_text' => 'Focus areas'] as $k => $label): ?>
                        <dt><?= e($label) ?></dt><dd><?= setting($k) !== '' ? e(setting($k)) : '<span class="muted">not set (line hidden)</span>' ?></dd>
                    <?php endforeach; ?>
                </dl>
                <p class="small muted">Public check page: <a href="<?= e(page_url('verify-letter')) ?>" target="_blank" rel="noopener"><?= e(abs_page_url('verify-letter')) ?></a></p>
            </section>
        </div>
    </div>
<?php endif;
admin_page_end();
