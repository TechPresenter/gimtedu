<?php
/**
 * Volunteer teacher authorization letters (admin generator + public verification).
 *
 * Admin pages: admin/letters.php (list, filters, CSV, revoke, settings), admin/letter-edit.php (create / edit,
 * prefilled from a volunteer teacher application), admin/letter-print.php (A4 print view).
 * Public page: /verify-letter/ (template verify_letter) - reference number + verification code.
 *
 * Privacy: a full Aadhaar number is never stored or printed. The ID number is accepted in full (or only its last
 * 4 characters) and reduced to "XXXX XXXX 1234" before anything is saved; the mobile number is stored as
 * "XXXXXXXX26". The authorised signatory's signature image is private (uploads/documents/branding/, denied by
 * .htaccess) and is only embedded as a data: URI in the permission-checked print view. The seal image is public
 * (setting seal_image_path, also meant for the donate page: foundation_seal_url()).
 *
 * Reference numbers: PREFIX/YYYY/NNN, sequential per year of the letter date. A counter row per year
 * (letter_ref_counters) is locked with SELECT ... FOR UPDATE inside the insert transaction, and
 * UNIQUE (ref_year, ref_seq) / UNIQUE (ref_no) are the safety net, so concurrent requests never share a number.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

const LETTER_TYPES = ['volunteer_teacher' => 'Volunteer teacher authorization'];
const LETTER_ID_TYPES = ['aadhaar' => 'Aadhaar ID', 'voter' => 'Voter ID', 'other' => 'Other ID'];
const LETTER_PRONOUNS = ['he' => 'He / him', 'she' => 'She / her', 'they' => 'They / them'];
/** Alphabet of verification codes (no 0/O, 1/I/L to avoid misreading a printed code). */
const LETTER_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

/** Placeholders accepted in the letter body: placeholder => help text. */
function letter_placeholders(): array
{
    return [
        '{candidate_name}' => 'Candidate name',
        '{id_label}'       => 'ID type, e.g. Aadhaar ID',
        '{id_number}'      => 'Masked ID number, e.g. XXXX XXXX 1234',
        '{school}'         => 'School name',
        '{address}'        => 'School address, district and state on one line',
        '{district}'       => 'District',
        '{state}'          => 'State',
        '{organisation}'   => 'Name of the foundation',
        '{he_she}'         => 'he / she / they',
        '{He_She}'         => 'He / She / They',
        '{his_her}'        => 'his / her / their',
        '{him_her}'        => 'him / her / them',
        '{is_are}'         => 'is (he / she) or are (they)',
    ];
}

/** Default texts (Admin > Authorization Letters > Settings can change them). */
function letter_defaults(): array
{
    return [
        'ref_prefix' => 'EIF',
        'addressee'  => 'The Head Teacher / Teacher-in-Charge',
        'subject'    => 'Authorization Letter for Employment of Volunteer Teacher',
        'salutation' => 'Dear Sir/Madam,',
        'body'       => "This is to authorize **{candidate_name}**, bearing {id_label}: **{id_number}**, to act on my behalf in completing employment as a Volunteer Teacher at {school}, {address} under **{organisation}**. The authorized person may submit the required documents, receive employment as Volunteer Teacher, and complete the necessary procedures related to appointment.\n\nApart from this, {he_she} {is_are} not eligible for any other duty of the organisation.",
        'enclosures' => "ID Proof (Aadhaar Card / Voter Card)\nThe Authorized Letter\nAll Educational Qualification Documents\nPassport Size Photograph",
        'closing'    => 'Thank you for your understanding and support.',
        'signatory_name' => '',
        'signatory_designation' => '',
    ];
}

/** A letter setting (website_settings key letter_<key>) with its built-in default. */
function letter_setting(string $key): string
{
    return setting('letter_' . $key, letter_defaults()[$key] ?? '');
}

/** Reference prefix: letters, digits and hyphens, upper case. */
function letter_ref_prefix(): string
{
    $p = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', letter_setting('ref_prefix')));
    return $p !== '' ? substr($p, 0, 12) : 'EIF';
}

/** Legal name of the foundation used in letters. */
function letter_org_name(): string
{
    return setting('legal_name', site_name());
}

/** Public URL of the foundation seal image, or '' when none has been uploaded. */
function foundation_seal_url(): string
{
    $path = setting('seal_image_path');
    if ($path === '' || strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false || strpos($path, 'uploads/documents/') === 0) {
        return '';
    }
    return is_file(ROOT_PATH . '/' . $path) ? url($path) : '';
}

/**
 * The private signature image as a data: URI (only for permission-checked admin pages), or ''.
 * The file lives in uploads/documents/branding/ which is never reachable by URL.
 */
function letter_signature_data_uri(): string
{
    $path = setting('letter_signature_path');
    if ($path === '' || !preg_match('#^uploads/documents/branding/[A-Za-z0-9._\-]+\.(png|jpg|webp)$#', $path)) {
        return '';
    }
    $full = ROOT_PATH . '/' . $path;
    if (!is_file($full) || filesize($full) > 3 * 1024 * 1024) {
        return '';
    }
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][strtolower(pathinfo($full, PATHINFO_EXTENSION))];
    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($full));
}

// ---------------------------------------------------------------------
// Masking & validation
// ---------------------------------------------------------------------

/** Verhoeff checksum (used by Aadhaar numbers). */
function letter_verhoeff_ok(string $number): bool
{
    static $d = [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 2, 3, 4, 0, 6, 7, 8, 9, 5], [2, 3, 4, 0, 1, 7, 8, 9, 5, 6], [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8], [5, 9, 8, 7, 6, 0, 4, 3, 2, 1], [6, 5, 9, 8, 7, 1, 0, 4, 3, 2], [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4], [9, 8, 7, 6, 5, 4, 3, 2, 1, 0]];
    static $p = [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 5, 7, 6, 2, 8, 3, 0, 9, 4], [5, 8, 0, 3, 7, 9, 6, 1, 4, 2], [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0], [4, 2, 8, 6, 5, 7, 3, 9, 0, 1], [2, 7, 9, 3, 8, 0, 6, 4, 1, 5], [7, 0, 4, 6, 9, 1, 3, 2, 5, 8]];
    if (!preg_match('/^\d+$/', $number)) {
        return false;
    }
    $c = 0;
    foreach (array_reverse(str_split($number)) as $i => $ch) {
        $c = $d[$c][$p[$i % 8][(int) $ch]];
    }
    return $c === 0;
}

/**
 * Reduce an ID number to its masked form. Accepts the full number or only the last 4 characters.
 * Returns [masked, error]: Aadhaar 'XXXX XXXX 1234'; Voter ID / other 'XXXXXX4567'. The full number is never returned.
 */
function letter_mask_id(string $type, string $input): array
{
    $v = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    if ($type === 'aadhaar') {
        if (preg_match('/^\d{12}$/', $v)) {
            if ($v[0] === '0' || $v[0] === '1' || !letter_verhoeff_ok($v)) {
                return ['', 'This is not a valid 12-digit Aadhaar number. Please check it, or enter only its last 4 digits.'];
            }
            $v = substr($v, -4);
        }
        if (!preg_match('/^\d{4}$/', $v)) {
            return ['', 'Enter the 12-digit Aadhaar number or only its last 4 digits.'];
        }
        return ['XXXX XXXX ' . $v, null];
    }
    if (!preg_match('/^[A-Z0-9]{4,20}$/', $v)) {
        return ['', 'Enter the ID number (4 to 20 letters or digits) or only its last 4 characters.'];
    }
    return [str_repeat('X', max(4, min(16, strlen($v) - 4))) . substr($v, -4), null];
}

/**
 * Masked mobile number: 'XXXXXXXX26'. Accepts a full number (with or without +91 / 0) or an already masked value
 * such as 'XXXXXXXX26'. '' stays ''. Returns [masked, error].
 */
function letter_mask_mobile(string $input): array
{
    $t = str_replace([' ', '-'], '', trim($input));
    if ($t === '') {
        return ['', null];
    }
    if (preg_match('/^[Xx*]{4,13}(\d{2})$/', $t, $m)) {
        return [str_repeat('X', strlen($t) - 2) . $m[1], null];
    }
    $digits = (string) preg_replace('/\D/', '', $t);
    if (preg_match('/^(?:91|0)?([6-9]\d{9})$/', $digits, $m)) {
        $digits = $m[1];
    } elseif (!preg_match('/^\d{8,15}$/', $digits) || preg_match('/[^\d+()]/', $t)) {
        return ['', 'Please enter a valid mobile number (only the last 2 digits are kept).'];
    }
    return [str_repeat('X', strlen($digits) - 2) . substr($digits, -2), null];
}

/**
 * Full Aadhaar numbers typed into free text (12 digits, optionally grouped 4-4-4 with spaces, hyphens or dots; first digit
 * 2-9; valid Verhoeff checksum). Returns the matches exactly as typed. Used to keep full numbers out of every text field:
 * only the ID number field may receive one, and it is reduced to its last 4 digits there.
 */
function letter_find_aadhaar(string $text): array
{
    if (!preg_match_all('/(?<![0-9])[2-9][0-9]{3}[ .\-]?[0-9]{4}[ .\-]?[0-9]{4}(?![0-9])/', $text, $m)) {
        return [];
    }
    return array_values(array_filter($m[0], function ($hit) {
        return letter_verhoeff_ok((string) preg_replace('/\D/', '', $hit));
    }));
}

/** Free text with every full Aadhaar number replaced by its masked form 'XXXX XXXX 1234'. */
function letter_mask_aadhaar_in_text(string $text): string
{
    foreach (letter_find_aadhaar($text) as $hit) {
        $text = str_replace($hit, 'XXXX XXXX ' . substr((string) preg_replace('/\D/', '', $hit), -4), $text);
    }
    return $text;
}

/** Name shown on the public verification page: 'Rahul Kumar' -> 'Ra*** K****', 'Om Prakash' -> 'O** P******'. */
function letter_public_name(string $name): string
{
    $out = [];
    foreach (array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: [], 'strlen')) as $i => $word) {
        $len = mb_strlen($word);
        // never show a whole word: at most 2 characters of the first word (1 of a 2-letter word), 1 of the others
        $keep = $i === 0 ? max(1, min(2, $len - 1)) : 1;
        $out[] = mb_substr($word, 0, $keep) . str_repeat('*', max(2, min(6, $len - $keep)));
    }
    return implode(' ', $out);
}

/** New random verification code (8 characters). */
function letter_new_code(): string
{
    $code = '';
    $max = strlen(LETTER_CODE_ALPHABET) - 1;
    for ($i = 0; $i < 8; $i++) {
        $code .= LETTER_CODE_ALPHABET[random_int(0, $max)];
    }
    return $code;
}

/** 'ABCDEFGH' -> 'ABCD-EFGH' */
function letter_format_code(string $code): string
{
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

/** Normalise a typed verification code ('abcd efgh', 'ABCD-EFGH') to 8 characters, or ''. */
function letter_normalize_code(string $input): string
{
    $c = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    return preg_match('/^[' . LETTER_CODE_ALPHABET . ']{8}$/', $c) ? $c : '';
}

/** Normalise a typed reference ('eif / 2026 / 12') to its stored form 'EIF/2026/012', or ''. */
function letter_normalize_ref(string $input): string
{
    $r = strtoupper((string) preg_replace('/\s+/', '', $input));
    $r = str_replace(['\\', '|'], '/', $r);
    if (!preg_match('#^([A-Z0-9-]{1,12})/(\d{4})/(\d{1,6})$#', $r, $m)) {
        return '';
    }
    return $m[1] . '/' . $m[2] . '/' . str_pad((string) (int) $m[3], 3, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------------
// Text
// ---------------------------------------------------------------------

/** School address, district and state on one line (for the body text). */
function letter_address_inline(array $l): string
{
    $parts = [];
    foreach (preg_split('/\R/', (string) ($l['school_address'] ?? '')) ?: [] as $line) {
        $line = trim($line, " \t,");
        if ($line !== '') {
            $parts[] = $line;
        }
    }
    foreach (['district', 'state'] as $k) {
        $v = trim((string) ($l[$k] ?? ''));
        if ($v !== '' && stripos(implode(', ', $parts), $v) === false) {
            $parts[] = $v;
        }
    }
    return implode(', ', $parts);
}

/** Placeholder => value for a letter (values cannot introduce bold markers). */
function letter_placeholder_values(array $l): array
{
    $pr = in_array($l['pronoun'] ?? '', ['he', 'she', 'they'], true) ? $l['pronoun'] : 'they';
    $values = [
        '{candidate_name}' => (string) ($l['candidate_name'] ?? ''),
        '{id_label}'       => (string) ($l['id_label'] ?? ''),
        '{id_number}'      => (string) ($l['id_masked'] ?? ''),
        '{school}'         => (string) ($l['school_name'] ?? ''),
        '{address}'        => letter_address_inline($l),
        '{district}'       => (string) ($l['district'] ?? ''),
        '{state}'          => (string) ($l['state'] ?? ''),
        '{organisation}'   => letter_org_name(),
        '{he_she}'         => $pr,
        '{He_She}'         => ucfirst($pr),
        '{his_her}'        => ['he' => 'his', 'she' => 'her', 'they' => 'their'][$pr],
        '{him_her}'        => ['he' => 'him', 'she' => 'her', 'they' => 'them'][$pr],
        '{is_are}'         => $pr === 'they' ? 'are' : 'is',
    ];
    return array_map(function ($v) {
        return str_replace('*', '', $v);
    }, $values);
}

/** Placeholders in a template that are not supported (e.g. a typo like {schol}). */
function letter_unknown_placeholders(string $template): array
{
    preg_match_all('/\{[^{}\s]{1,40}\}/', $template, $m);
    return array_values(array_unique(array_diff($m[0], array_keys(letter_placeholders()))));
}

/** Resolve the placeholders of a body template for a letter (single pass: values are never re-scanned). */
function letter_resolve_body(string $template, array $l): string
{
    return strtr(str_replace("\r", '', $template), letter_placeholder_values($l));
}

/** Plain text with **bold** markers -> HTML paragraphs (escaped). */
function letter_text_html(string $text): string
{
    $html = '';
    foreach (preg_split("/\n\s*\n/", trim(str_replace("\r", '', $text))) ?: [] as $para) {
        $para = trim($para);
        if ($para === '') {
            continue;
        }
        $p = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e($para));
        // keep a masked ID (XXXX XXXX 1234 / XXXXXX4567) on one line
        $p = (string) preg_replace('/\bX{4} X{4} \d{4}\b/', '<span class="al-nowrap">$0</span>', $p);
        $html .= '<p>' . nl2br(str_replace('**', '', $p), false) . '</p>';
    }
    return $html;
}

/** Enclosures as a list of lines (from the JSON column or a textarea). */
function letter_enclosure_list($value): array
{
    if (is_array($value)) {
        $lines = $value;
    } else {
        $decoded = json_decode((string) $value, true);
        $lines = is_array($decoded) ? $decoded : preg_split('/\R/', (string) $value);
    }
    $out = [];
    foreach ($lines ?: [] as $line) {
        $line = trim((string) $line, " \t-•*");
        if ($line !== '') {
            $out[] = mb_substr($line, 0, 150);
        }
    }
    return array_slice($out, 0, 8);
}

// ---------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------

/** Next sequence number that a new letter dated in $year would get. */
function letter_next_seq(int $year): int
{
    $counter = (int) db_value('SELECT last_seq FROM letter_ref_counters WHERE ref_year = ?', [$year]);
    $max = (int) db_value('SELECT COALESCE(MAX(ref_seq), 0) FROM authorization_letters WHERE ref_year = ?', [$year]);
    return max($counter, $max) + 1;
}

/**
 * Let the admin continue the numbering of letters issued before this system (e.g. next = 12 after EIF/2026/011).
 * The next number can never go below an existing letter's number. Returns the next number now in force.
 */
function letter_set_next_seq(int $year, int $next): int
{
    $max = (int) db_value('SELECT COALESCE(MAX(ref_seq), 0) FROM authorization_letters WHERE ref_year = ?', [$year]);
    $last = max($max, min(999999, $next) - 1);
    db_query('INSERT INTO letter_ref_counters (ref_year, last_seq) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_seq = VALUES(last_seq)', [$year, $last]);
    return $last + 1;
}

/**
 * Create a letter with the next reference number and a fresh verification code.
 * $fields: the authorization_letters columns except ref/code/status. Returns ['id', 'ref_no', 'verify_code'].
 */
function letter_create(array $fields, ?int $userId): array
{
    $year = (int) substr((string) $fields['letter_date'], 0, 4);
    if ($year < 2000 || $year > 2100) {
        throw new InvalidArgumentException('Invalid letter date.');
    }
    $prefix = letter_ref_prefix();
    // the counter row must exist before the transaction (INSERT IGNORE inside it would share-lock the row and could deadlock)
    db_query('INSERT IGNORE INTO letter_ref_counters (ref_year, last_seq) VALUES (?, 0)', [$year]);
    $pdo = db();
    $lastError = null;
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $pdo->beginTransaction();
        try {
            $last = (int) db_value('SELECT last_seq FROM letter_ref_counters WHERE ref_year = ? FOR UPDATE', [$year]);
            $max = (int) db_value('SELECT COALESCE(MAX(ref_seq), 0) FROM authorization_letters WHERE ref_year = ?', [$year]);
            $seq = max($last, $max) + 1;
            $ref = $prefix . '/' . $year . '/' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
            $code = letter_new_code();
            $id = db_insert('authorization_letters', $fields + [
                'ref_no' => $ref, 'ref_year' => $year, 'ref_seq' => $seq, 'verify_code' => $code, 'status' => 'issued',
                'created_by' => $userId, 'updated_by' => $userId,
            ]);
            db_query('UPDATE letter_ref_counters SET last_seq = ? WHERE ref_year = ?', [$seq, $year]);
            $pdo->commit();
            return ['id' => $id, 'ref_no' => $ref, 'verify_code' => $code];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $lastError = $e;
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            // 1062 duplicate (code or number), 1213 deadlock, 1205 lock wait timeout: try again with a new number / code
            if (!in_array($driverCode, [1062, 1213, 1205], true)) {
                throw $e;
            }
            usleep(random_int(5000, 40000));
        }
    }
    throw $lastError ?? new RuntimeException('Could not create the letter.');
}

/** One letter with the names of the staff involved and the linked application reference. */
function letter_get(int $id): ?array
{
    return db_row('SELECT l.*, cu.name AS created_by_name, uu.name AS updated_by_name, ru.name AS revoked_by_name, t.application_no
                     FROM authorization_letters l
                     LEFT JOIN users cu ON cu.id = l.created_by LEFT JOIN users uu ON uu.id = l.updated_by LEFT JOIN users ru ON ru.id = l.revoked_by
                     LEFT JOIN teacher_applications t ON t.id = l.application_id
                    WHERE l.id = ?', [$id]);
}

/**
 * Public verification. Returns null (not found) or ['status', 'ref_no', 'letter_date', 'name', 'school', 'type', 'revoked_at']
 * with nothing private (no ID, mobile, address or revocation reason).
 */
function letter_verify_lookup(string $ref, string $code): ?array
{
    $ref = letter_normalize_ref($ref);
    $code = letter_normalize_code($code);
    if ($ref === '' || $code === '') {
        return null;
    }
    $row = db_row('SELECT ref_no, letter_type, letter_date, candidate_name, school_name, status, revoked_at, verify_code FROM authorization_letters WHERE ref_no = ?', [$ref]);
    if (!$row || !hash_equals((string) $row['verify_code'], $code)) {
        return null;
    }
    return [
        'status' => $row['status'], 'ref_no' => $row['ref_no'], 'letter_date' => $row['letter_date'],
        'name' => letter_public_name((string) $row['candidate_name']), 'school' => (string) $row['school_name'],
        'type' => LETTER_TYPES[$row['letter_type']] ?? 'Authorization letter', 'revoked_at' => $row['revoked_at'],
    ];
}

// ---------------------------------------------------------------------
// Printable letter (A4). Styles: assets/css/modules/admin-letters.css (.al-*)
// ---------------------------------------------------------------------

/**
 * The letter sheet. $opts: signature (data: URI or ''), screen (bool, adds the fit-to-page hooks).
 * Every value is escaped; the body is plain text with **bold** markers.
 */
function letter_sheet_html(array $l, array $opts = []): string
{
    $org = letter_org_name();
    $orgUpper = mb_strtoupper($org);
    $logo = site_logo();
    $seal = foundation_seal_url();
    $signature = (string) ($opts['signature'] ?? '');
    $revoked = ($l['status'] ?? 'issued') === 'revoked';

    $regLines = [];
    $cin = setting('cin_number');
    $ngo = setting('ngo_id');
    if ($cin !== '' || $ngo !== '') {
        $regLines[] = trim(($cin !== '' ? 'CIN: ' . $cin : '') . ($cin !== '' && $ngo !== '' ? ', ' : '') . ($ngo !== '' ? 'NGO ID:-' . $ngo : ''));
    }
    if (setting('registration_authority') !== '') {
        $regLines[] = 'Registered with ' . setting('registration_authority');
    }
    if (setting('msme_udyam_number') !== '') {
        $regLines[] = 'MSME Reg. No. - ' . setting('msme_udyam_number');
    }

    $toLines = [(string) $l['addressee'], (string) $l['school_name']];
    foreach (preg_split('/\R/', (string) $l['school_address']) ?: [] as $line) {
        if (trim($line) !== '') {
            $toLines[] = trim($line);
        }
    }
    $toLines[] = 'District: ' . $l['district'] . ', ' . $l['state'];

    $enclosures = letter_enclosure_list($l['enclosures'] ?? '');
    $email = setting('contact_email');
    $website = rtrim(BASE_URL, '/');
    $office = setting('corporate_office_address');
    $focus = setting('focus_areas_text');
    $verifyUrl = rtrim(abs_page_url('verify-letter'), '/') . '/';

    $h = '<article class="al-sheet' . ($revoked ? ' is-revoked' : '') . '" aria-label="' . e('Authorization letter ' . $l['ref_no']) . '" data-al-sheet>';
    // watermark: faint logo + diagonal name, behind the text, printed too
    $h .= '<div class="al-watermark" aria-hidden="true"><img src="' . e($logo) . '" alt="" class="al-wm-logo"><span class="al-wm-text">' . e($orgUpper) . '</span></div>';
    if ($revoked) {
        $h .= '<div class="al-revoked-stamp" aria-hidden="true">REVOKED</div>';
    }
    $h .= '<div class="al-topband" aria-hidden="true"></div><div class="al-gradline" aria-hidden="true"></div>';

    // letterhead
    $h .= '<header class="al-head"><img class="al-logo" src="' . e($logo) . '" alt="' . e($org) . ' logo" width="104" height="104">'
        . '<div class="al-head-text"><p class="al-org">' . e($orgUpper) . '</p>';
    foreach ($regLines as $line) {
        $h .= '<p class="al-reg">' . e($line) . '</p>';
    }
    $h .= '</div></header><div class="al-rule" aria-hidden="true"></div>';

    $h .= '<div class="al-main" data-al-main>';
    $h .= '<div class="al-refrow"><p><strong>Ref. No.:</strong> ' . e($l['ref_no']) . '</p><p><strong>Date.</strong> ' . e(date('d/m/Y', strtotime((string) $l['letter_date']))) . '</p></div>';
    $h .= '<h2 class="al-title">AUTHORIZATION LETTER</h2>';
    $h .= '<div class="al-to"><p>To,</p><p>';
    foreach ($toLines as $i => $line) {
        $h .= ($i ? '<br>' : '') . ($i === 1 ? '<strong>' . e($line) . '</strong>' : e($line));
    }
    $h .= '</p></div>';
    $h .= '<p class="al-subject"><strong>Subject:</strong> ' . e($l['subject']) . '</p>';
    $h .= '<p class="al-salutation">' . e($l['salutation']) . '</p>';
    $h .= '<div class="al-body">' . letter_text_html((string) $l['body']) . '</div>';

    // enclosures + closing + signatory (left) | candidate details + seal (right)
    $h .= '<div class="al-cols"><div class="al-col-left">';
    if ($enclosures) {
        $h .= '<p class="al-enc-title"><strong>Enclosed:</strong></p><ul class="al-enc">';
        foreach ($enclosures as $item) {
            $h .= '<li>' . e($item) . '</li>';
        }
        $h .= '</ul>';
    }
    if (trim((string) ($l['closing_text'] ?? '')) !== '') {
        $h .= '<p class="al-closing">' . e($l['closing_text']) . '</p>';
    }
    $h .= '<div class="al-sign"><p>Yours faithfully,</p><p class="al-sign-org"><strong>' . e($org) . '</strong></p>'
        . '<div class="al-sign-space">' . ($signature !== '' ? '<img src="' . e($signature) . '" alt="Signature of the authorized signatory" class="al-sign-img">' : '') . '</div>'
        . '<p class="al-sign-role"><strong>Authorized Signatory</strong></p>';
    $sigName = trim((string) ($l['signatory_name'] ?? ''));
    $sigDesig = trim((string) ($l['signatory_designation'] ?? ''));
    if ($sigName !== '' || $sigDesig !== '') {
        $h .= '<p class="al-sign-name">' . e(trim($sigName . ($sigName !== '' && $sigDesig !== '' ? ', ' : '') . $sigDesig)) . '</p>';
    }
    $h .= '</div></div>';

    $mobile = trim((string) ($l['mobile_masked'] ?? ''));
    $h .= '<div class="al-col-right"><div class="al-cand"><p class="al-cand-title"><strong>Candidate Details</strong></p>'
        . '<p class="al-line-field"><span>Candidate Signature:</span><span class="al-blank"></span></p>'
        . '<p><span>Candidate Name:</span> <strong>' . e($l['candidate_name']) . '</strong></p>'
        . '<p class="al-line-field"><span>Mobile No.:</span>' . ($mobile !== '' ? ' <strong>' . e($mobile) . '</strong>' : '<span class="al-blank"></span>') . '</p>'
        . '</div><div class="al-seal">' . ($seal !== '' ? '<img src="' . e($seal) . '" alt="Seal of ' . e($org) . '" class="al-seal-img">' : '<span class="al-seal-blank">Seal</span>') . '</div></div>';
    $h .= '</div>';

    // school verification box
    $h .= '<section class="al-verify-box" aria-label="School verification"><p class="al-vb-title"><strong>SCHOOL VERIFICATION</strong></p>'
        . '<p class="al-vb-lead">Verified all original documents and found them satisfactory.</p>'
        . '<div class="al-vb-grid"><div>'
        . '<p class="al-line-field"><span>Head Teacher Signature:</span><span class="al-blank"></span></p>'
        . '<p class="al-line-field"><span>Name:</span><span class="al-blank"></span></p>'
        . '<p><span>Designation:</span> Head Teacher</p>'
        . '</div><div class="al-vb-right">'
        . '<p class="al-vb-seal"><span>School Seal:</span></p>'
        . '<p class="al-line-field"><span>Date:</span><span class="al-blank"></span></p>'
        . '</div></div></section>';
    $h .= '</div>';

    // footer
    $h .= '<footer class="al-foot">';
    $contact = [];
    if ($email !== '') {
        $contact[] = '<span class="al-red">Email:</span> ' . e($email);
    }
    $contact[] = '<span class="al-red">website:</span> ' . e($website);
    $h .= '<p class="al-contact">' . implode('&nbsp;&nbsp;&nbsp;', $contact) . '</p>';
    if ($office !== '') {
        $h .= '<p class="al-contact"><span class="al-red">Corporate Office:</span> ' . e($office) . '</p>';
    }
    $h .= '<p class="al-verify-line">Verify at ' . e($verifyUrl) . ' &middot; Ref: ' . e($l['ref_no']) . ' &middot; Code: ' . e(letter_format_code((string) $l['verify_code'])) . '</p>';
    $h .= '<div class="al-band">' . e($focus !== '' ? $focus : $org) . '</div>';
    $h .= '</footer></article>';
    return $h;
}

// =====================================================================
// Migrations (700-749)
// =====================================================================

/** Letters table, reference counters, permission, settings, public verification page, privacy disclosure. */
function migration_700_authorization_letters(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS letter_ref_counters (
        ref_year SMALLINT UNSIGNED NOT NULL,
        last_seq INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (ref_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS authorization_letters (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ref_no VARCHAR(40) NOT NULL,
        ref_year SMALLINT UNSIGNED NOT NULL,
        ref_seq INT UNSIGNED NOT NULL,
        letter_type VARCHAR(40) NOT NULL DEFAULT 'volunteer_teacher',
        application_id INT UNSIGNED DEFAULT NULL,
        candidate_name VARCHAR(120) NOT NULL,
        pronoun ENUM('he','she','they') NOT NULL DEFAULT 'they',
        id_type ENUM('aadhaar','voter','other') NOT NULL DEFAULT 'aadhaar',
        id_label VARCHAR(60) NOT NULL DEFAULT 'Aadhaar ID',
        id_masked VARCHAR(32) NOT NULL,
        mobile_masked VARCHAR(20) DEFAULT NULL,
        addressee VARCHAR(160) NOT NULL,
        school_name VARCHAR(200) NOT NULL,
        school_address VARCHAR(400) NOT NULL,
        district VARCHAR(80) NOT NULL,
        state VARCHAR(80) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        salutation VARCHAR(80) NOT NULL DEFAULT 'Dear Sir/Madam,',
        body_template TEXT NOT NULL,
        body TEXT NOT NULL,
        enclosures TEXT DEFAULT NULL,
        closing_text VARCHAR(255) DEFAULT NULL,
        letter_date DATE NOT NULL,
        signatory_name VARCHAR(120) DEFAULT NULL,
        signatory_designation VARCHAR(120) DEFAULT NULL,
        verify_code CHAR(8) NOT NULL,
        status ENUM('issued','revoked') NOT NULL DEFAULT 'issued',
        revoked_reason VARCHAR(255) DEFAULT NULL,
        revoked_at DATETIME DEFAULT NULL,
        revoked_by INT UNSIGNED DEFAULT NULL,
        print_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_printed_at DATETIME DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        updated_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_letters_ref (ref_no),
        UNIQUE KEY uq_letters_seq (ref_year, ref_seq),
        UNIQUE KEY uq_letters_code (verify_code),
        KEY idx_letters_status (status),
        KEY idx_letters_app (application_id),
        KEY idx_letters_date (letter_date),
        KEY fk_letters_created (created_by),
        KEY fk_letters_updated (updated_by),
        KEY fk_letters_revoked (revoked_by),
        CONSTRAINT fk_letters_app FOREIGN KEY (application_id) REFERENCES teacher_applications (id) ON DELETE SET NULL,
        CONSTRAINT fk_letters_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_letters_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_letters_revoked FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    add_permission_if_missing('letters.manage', 'Generate, print and revoke volunteer teacher authorization letters', 'People', 49, ['volunteer_coordinator']);

    foreach (letter_defaults() as $key => $value) {
        add_setting_if_missing('letter_' . $key, $value);
    }
    add_setting_if_missing('letter_signature_path', '');
    add_setting_if_missing('seal_image_path', '');
    fill_setting_if_empty('corporate_office_address', 'Wave One, L-2A, Pocket G, Sector 18, Noida, Uttar Pradesh - 201301, India');

    if (!db_value("SELECT COUNT(*) FROM pages WHERE slug = 'verify-letter'")) {
        db_insert('pages', [
            'slug' => 'verify-letter', 'template' => 'verify_letter', 'title' => 'Verify an Authorization Letter',
            'banner_eyebrow' => 'Verification',
            'banner_subtitle' => 'Check that an authorization letter was issued by ' . site_name() . ' and is still valid',
            'meta_title' => 'Verify an Authorization Letter',
            'meta_description' => 'Enter the reference number and verification code printed on an authorization letter to check that it was issued by ' . site_name() . ' and is still valid.',
            'status' => 'published', 'is_system' => 1, 'show_in_sitemap' => 1, 'noindex' => 0, 'sort_order' => 36,
            'published_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $privacyId = (int) db_value("SELECT id FROM pages WHERE slug = 'privacy-policy'");
    if ($privacyId && !db_value("SELECT COUNT(*) FROM page_sections WHERE page_id = ? AND anchor = 'authorization-letters'", [$privacyId])) {
        db_insert('page_sections', [
            'page_id' => $privacyId, 'section_type' => 'richtext', 'anchor' => 'authorization-letters',
            'title' => 'Authorization letters for volunteer teachers',
            'body' => '<p>When a volunteer teacher is placed in a school, our coordinators may issue an authorization letter addressed to that school. '
                . 'The letter record keeps the volunteer\'s name, the type of identity document with only its last four characters, the last two digits of the mobile number, '
                . 'the name and address of the school, the letter date and which staff member issued it. <strong>We never store or print a full Aadhaar number.</strong></p>'
                . '<p>Anyone can check that a letter is genuine on our <a href="' . e(url('verify-letter/')) . '">letter verification page</a> by entering the reference number and verification code printed on it. '
                . 'That page shows only whether the letter is valid or revoked, its date, a partly hidden name and the school name - never identity or contact details.</p>'
                . '<p>Letter records are seen only by authorised staff and are kept as part of our volunteer records, as explained under "How long we keep your information".</p>',
            'background' => 'white', 'status' => 'visible', 'sort_order' => 67,
        ]);
    }
}

/**
 * Footer link to the public letter verification page (menus are database rows; added once). Numbered after
 * migration_700, which creates the page. Nothing is added when the page or the footer menu has been removed, or when
 * a link to the page already exists.
 */
function migration_701_verify_letter_link(): void
{
    if (!table_exists('menu_items') || !db_value("SELECT COUNT(*) FROM pages WHERE slug = 'verify-letter'")) {
        return;
    }
    if (db_value("SELECT COUNT(*) FROM menu_items WHERE url IN ('/verify-letter/', '/verify-letter')")) {
        return;
    }
    $menuId = db_value("SELECT id FROM menus WHERE menu_key = 'footer_legal' LIMIT 1");
    if (!$menuId) {
        return;
    }
    $sort = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM menu_items WHERE menu_id = ? AND parent_id IS NULL', [$menuId]);
    db_query("INSERT INTO menu_items (menu_id, parent_id, label, url, status, sort_order) VALUES (?, NULL, 'Verify a letter', '/verify-letter/', 'visible', ?)", [$menuId, $sort]);
}
