<?php
/**
 * Site-wide widgets (all configurable in Admin > Widgets):
 * announcement bar/popup, fraud-alert advisory, genuine security notices, floating WhatsApp/contact/help
 * buttons, the automated help assistant, back-to-top, analytics consent and flash toasts.
 *
 * Honesty rules: no fake alerts, no fake urgency, no invented activity. The fraud alert is shown only when
 * an administrator has enabled it after a verified incident; security notices come from real account events.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Announcement (bar above the header), if enabled and within its date range. */
function announcement(): ?array
{
    if (setting('announcement_enabled') !== '1' || trim(setting('announcement_text')) === '') {
        return null;
    }
    $now = date('Y-m-d H:i');
    $start = setting('announcement_starts');
    $end = setting('announcement_ends');
    if (($start !== '' && $now < str_replace('T', ' ', $start)) || ($end !== '' && $now > str_replace('T', ' ', $end))) {
        return null;
    }
    return [
        'text' => setting('announcement_text'), 'style' => setting('announcement_style', 'bar'),
        'label' => setting('announcement_link_label'), 'url' => setting('announcement_link_url'),
        'version' => setting('announcement_version', '1'),
    ];
}

function render_announcement_bar(): string
{
    $a = announcement();
    if (!$a || $a['style'] !== 'bar') {
        return '';
    }
    return '<div class="announce-bar" data-announce="' . e($a['version']) . '" role="region" aria-label="Announcement"><div class="container announce-inner">'
        . icon('megaphone') . '<p>' . e($a['text']) . ($a['label'] && $a['url'] ? ' <a href="' . e(safe_link($a['url'])) . '">' . e($a['label']) . ' ' . icon('arrow-right') . '</a>' : '') . '</p>'
        . '<button type="button" class="announce-close" data-announce-close aria-label="Dismiss announcement">' . icon('x') . '</button></div></div>';
}

function fraud_alert_enabled(): bool
{
    return setting('fraud_alert_enabled') === '1' && trim(setting('fraud_alert_message')) !== '';
}

/** Fraud / impersonation advisory dialog (bilingual), shown only when enabled by an administrator. */
function render_fraud_alert(): string
{
    if (!fraud_alert_enabled()) {
        return '';
    }
    $points = array_filter(array_map('trim', preg_split('/\r?\n/', setting('fraud_alert_points'))));
    $body = '<div class="fraud-body"><div class="fraud-lang"><h3>' . e(setting('fraud_alert_title', 'Beware of fraud')) . '</h3><p>' . e_nl(setting('fraud_alert_message')) . '</p>';
    if ($points) {
        $body .= '<ul class="fraud-points">';
        foreach ($points as $p) {
            $body .= '<li>' . icon('shield') . '<span>' . e($p) . '</span></li>';
        }
        $body .= '</ul>';
    }
    $body .= '</div>';
    if (trim(setting('fraud_alert_message_hi')) !== '') {
        $body .= '<div class="fraud-lang hindi" lang="hi"><h3>' . e(setting('fraud_alert_title_hi', 'धोखाधड़ी से सावधान')) . '</h3><p>' . e_nl(setting('fraud_alert_message_hi')) . '</p></div>';
    }
    $body .= '<div class="fraud-report"><strong>' . icon('flag') . 'How to report</strong><p>' . e(setting('fraud_alert_report_text')) . '</p><div class="btn-row">'
        . '<a class="btn btn-primary btn-sm" href="' . e(page_url('report-fraud')) . '">' . icon('alert-triangle') . 'Report to us</a>';
    if (setting('contact_email')) {
        $body .= '<a class="btn btn-outline btn-sm" href="mailto:' . e(setting('contact_email')) . '?subject=' . rawurlencode('Fraud report') . '">' . icon('mail') . e(setting('contact_email')) . '</a>';
    }
    $body .= '<a class="btn btn-ghost btn-sm" href="https://cybercrime.gov.in" target="_blank" rel="noopener">' . icon('external') . 'cybercrime.gov.in · 1930</a></div></div>';
    if (setting('fraud_alert_verified_note') !== '') {
        $body .= '<p class="fraud-note muted small">' . e(setting('fraud_alert_verified_note')) . '</p>';
    }
    $body .= '</div>';
    return '<dialog class="modal modal-alert" id="fraud-alert" data-fraud-alert="' . e(setting('fraud_alert_version', '1')) . '" data-show="' . e(setting('fraud_alert_show', 'once_per_visit')) . '" aria-labelledby="fraud-alert-title">'
        . '<div class="modal-card"><div class="modal-head modal-head-alert"><h2 id="fraud-alert-title">' . icon('alert-triangle') . 'Fraud Alert</h2>'
        . '<button type="button" class="modal-close" data-modal-close aria-label="Close">' . icon('x') . '</button></div><div class="modal-body">' . $body . '</div></div></dialog>';
}

/** Security notice after sign-in when real failed attempts were recorded on the account. */
function render_security_notice(): string
{
    $n = $_SESSION['security_notice'] ?? null;
    if (!$n || !is_logged_in()) {
        return '';
    }
    unset($_SESSION['security_notice']);
    $body = '<p><strong>' . (int) $n['failed'] . '</strong> unsuccessful sign-in attempt' . ((int) $n['failed'] > 1 ? 's were' : ' was') . ' recorded on your account since '
        . e(format_date($n['since'], 'd M Y, h:i A')) . '.</p><p>If this was you (for example, a mistyped password), no action is needed. If not, please change your password now.</p>'
        . '<div class="btn-row"><a class="btn btn-primary btn-sm" href="' . e(member_url('password')) . '">' . icon('key') . 'Change password</a>'
        . '<a class="btn btn-ghost btn-sm" href="' . e(page_url('report-fraud')) . '">Report a problem</a>'
        . '<button type="button" class="btn btn-outline btn-sm" data-modal-close>It was me</button></div>';
    return ui_modal('security-notice', 'Security check', $body, 'modal-security auto-open');
}

/** wa.me chat link when the WhatsApp widget is enabled and a valid number is set, otherwise ''. */
function whatsapp_chat_url(): string
{
    $wa = preg_replace('/\D+/', '', setting('whatsapp_number'));
    if (setting('widget_whatsapp_enabled', '1') !== '1' || strlen($wa) < 10) {
        return '';
    }
    $wa = strlen($wa) === 10 ? '91' . $wa : $wa;
    return 'https://wa.me/' . $wa . '?text=' . rawurlencode(setting('widget_whatsapp_message'));
}

/** Google Analytics is configured with a valid measurement id (consent banner + "Cookie settings" link). */
function ga_enabled(): bool
{
    $id = setting('ga_measurement_id');
    return $id !== '' && (bool) preg_match('/^G-[A-Z0-9]{4,15}$/', $id);
}

/** Floating action buttons + help assistant + back-to-top. */
function render_floating_widgets(): string
{
    $html = '<div class="fab-stack" aria-label="Quick actions">';
    $waUrl = whatsapp_chat_url();
    if ($waUrl !== '') {
        $html .= '<a class="fab fab-whatsapp" href="' . e($waUrl) . '" target="_blank" rel="noopener" data-tip="Chat on WhatsApp" aria-label="Chat with us on WhatsApp">' . icon('whatsapp') . '</a>';
    }
    if (setting('widget_contact_enabled', '1') === '1') {
        $phone = setting('contact_phone');
        $html .= $phone
            ? '<a class="fab fab-call" href="tel:' . e(preg_replace('/[^\d+]/', '', $phone)) . '" data-tip="Call us" aria-label="Call us">' . icon('phone') . '</a>'
            : '<a class="fab fab-call" href="' . e(page_url('contact')) . '" data-tip="Contact us" aria-label="Contact us">' . icon('mail') . '</a>';
    }
    // AI website assistant (includes/assistant.php) switched on with an API key: the help button opens the AI chat
    // (render_assistant_widget() prints the panel); otherwise the automated FAQ helper below works as before.
    $aiAssistant = function_exists('assistant_active') && assistant_active();
    if ($aiAssistant) {
        $html .= '<button type="button" class="fab fab-help fab-ai" data-ai-toggle aria-haspopup="dialog" aria-expanded="false" aria-controls="ai-chat" data-tip="Ask our website assistant" aria-label="Open the website assistant (AI chat)">'
            . icon('message') . '<span class="fab-ai-badge" aria-hidden="true">AI</span></button>';
    } elseif (setting('widget_help_enabled', '1') === '1') {
        $html .= '<button type="button" class="fab fab-help" data-help-toggle aria-expanded="false" aria-controls="help-panel" data-tip="Help assistant" aria-label="Open help assistant">' . icon('message') . '</button>';
    }
    $html .= '</div>';
    if (setting('widget_back_to_top', '1') === '1') {
        $html .= '<button type="button" class="back-to-top" data-back-to-top aria-label="Back to top"><svg class="btt-ring" viewBox="0 0 44 44" aria-hidden="true"><circle cx="22" cy="22" r="20"/></svg>' . icon('arrow-up') . '</button>';
    }
    if (!$aiAssistant && setting('widget_help_enabled', '1') === '1') {
        $html .= render_help_panel();
    }
    return $html;
}

function help_topics(): array
{
    return [
        ['Donate', 'heart', page_url('donate')],
        ['Volunteer', 'users', page_url('become-a-volunteer')],
        ['Teach', 'book', page_url('volunteer-teacher')],
        ['Jobs', 'briefcase', page_url('volunteer-staff')],
        ['Internship', 'award', page_url('internships')],
        ['Receipt', 'file-text', member_url('donations')],
        ['Track application', 'search', page_url('application-status')],
        ['Get help', 'life-buoy', page_url('request-help')],
    ];
}

function render_help_panel(): string
{
    $chips = '';
    foreach (help_topics() as [$label, $ic, $href]) {
        $chips .= '<a class="help-chip" href="' . e($href) . '">' . icon($ic) . e($label) . '</a>';
    }
    return '<section class="help-panel" id="help-panel" hidden aria-label="Help assistant" data-endpoint="' . e(url('/api/help/')) . '">'
        . '<header class="help-head"><span class="help-avatar">' . icon('message') . '</span><div><strong>Help assistant</strong><small>Automated answers · not a live chat</small></div>'
        . '<button type="button" class="help-close" data-help-toggle aria-label="Close help assistant">' . icon('x') . '</button></header>'
        . '<div class="help-log" aria-live="polite"><div class="help-msg bot"><p>' . e(setting('widget_help_greeting')) . '</p></div><div class="help-chips">' . $chips . '</div></div>'
        . '<form class="help-form" data-help-form role="search"><label class="sr-only" for="help-q">Type your question</label>'
        . '<input id="help-q" name="q" type="text" maxlength="120" placeholder="Type a question, e.g. donation receipt" autocomplete="off">'
        . '<button type="submit" class="btn btn-primary btn-sm" aria-label="Ask">' . icon('send') . '</button></form>'
        . '<footer class="help-foot">Need a person? <a href="' . e(page_url('contact')) . '">Contact us</a>'
        . (whatsapp_chat_url() !== '' ? ' · <a href="' . e(whatsapp_chat_url()) . '" target="_blank" rel="noopener">WhatsApp</a>' : '')
        . '</footer></section>';
}

/** JSON answers for the help assistant: matching FAQs + suggested pages. GET /api/help/?q= */
function help_api(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode(help_search(get_str('q')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * FAQ search used by the help assistant and as the AI assistant's fallback:
 * ['answers' => [['q','a'], ...max 3], 'links' => [['label','url'], ...max 4], 'fallback' => '', 'faq_url', 'contact_url']
 */
function help_search(string $query): array
{
    $q = mb_strtolower(trim(mb_substr($query, 0, 160)));
    // \p{M}: Devanagari vowel signs belong to the word (दान, सहायता), they must not split it
    $words = array_values(array_filter(preg_split('/[^\p{L}\p{M}\p{N}]+/u', $q), function ($w) {
        return mb_strlen($w) >= 3 && !in_array($w, ['the', 'and', 'for', 'how', 'can', 'what', 'where', 'with', 'you', 'your', 'are', 'does', 'want', 'need'], true);
    }));
    $intents = [
        'donat|pay|upi|contribut|fund|दान' => ['Donate now', page_url('donate')],
        'receipt|80g|tax|certificate of donation|रसीद' => ['Your donations & receipts', member_url('donations')],
        'volunteer|seva|स्वयंसेवक' => ['Volunteer with us', page_url('become-a-volunteer')],
        'teach|teacher|tutor|शिक्षक' => ['Volunteer Teacher Programme', page_url('volunteer-teacher')],
        'job|career|vacanc|staff|salary|नौकरी' => ['Staff application', page_url('volunteer-staff')],
        'intern' => ['Internships', page_url('internships')],
        'status|track|application|reference' => ['Check application status', page_url('application-status')],
        'help|support|assist|ration|food|medical|सहायता' => ['Request help', page_url('request-help')],
        'scholar|fees|study' => ['Scholarship application', page_url('scholarship')],
        'scheme|yojana|योजना' => ['Welfare schemes', page_url('schemes')],
        'fraud|scam|fake|cheat|धोखा' => ['Report fraud or misuse', page_url('report-fraud')],
        'event|camp|drive|calendar|hackathon' => ['Upcoming events', page_url(get_page('events') ? 'events' : 'events-page')],
        'campaign|flood|relief|disaster' => ['Campaigns', page_url('campaigns')],
        'contact|phone|email|address|office' => ['Contact us', page_url('contact')],
        'member' => ['Membership', page_url('membership')],
        'password|login|account|sign' => ['Login / reset password', page_url('forgot-password')],
    ];
    $links = [];
    foreach ($intents as $pattern => [$label, $href]) {
        if ($q !== '' && preg_match('/' . $pattern . '/u', $q)) {
            $links[] = ['label' => $label, 'url' => $href];
        }
    }
    $answers = [];
    if ($words) {
        $where = [];
        $params = [];
        foreach (array_slice($words, 0, 5) as $w) {
            $where[] = '(question LIKE ? OR answer LIKE ?)';
            $params[] = '%' . $w . '%';
            $params[] = '%' . $w . '%';
        }
        $rows = db_rows("SELECT question, answer FROM faqs WHERE status = 'published' AND (" . implode(' OR ', $where) . ') LIMIT 25', $params);
        usort($rows, function ($a, $b) use ($words) {
            $score = function ($r) use ($words) {
                $t = mb_strtolower($r['question'] . ' ' . $r['answer']);
                $s = 0;
                foreach ($words as $w) {
                    $s += substr_count(mb_strtolower($r['question']), $w) * 3 + substr_count($t, $w);
                }
                return $s;
            };
            return $score($b) <=> $score($a);
        });
        foreach (array_slice($rows, 0, 3) as $r) {
            $answers[] = ['q' => $r['question'], 'a' => excerpt($r['answer'], 320)];
        }
    }
    return [
        'answers' => $answers,
        'links' => array_slice($links, 0, 4),
        'fallback' => (!$answers && !$links) ? 'Sorry, I could not find an answer to that. Please try other words, browse the FAQ, or contact our team.' : '',
        'faq_url' => page_url('faq'),
        'contact_url' => page_url('contact'),
    ];
}

/**
 * Cookie banner - when Google Analytics is configured and/or our own statistics may set the first-party visitor
 * cookie pwf_vid (deep analytics, persistent id "only with consent"). Basic first-party statistics need no cookie.
 */
function render_consent_banner(): string
{
    $ga = ga_enabled();
    $own = function_exists('analytics_consent_banner_needed') && analytics_consent_banner_needed();
    if (!$ga && !$own) {
        return '';
    }
    if ($ga && $own) {
        $text = 'We would like to use analytics cookies - Google Analytics and our own first-party cookie that recognises return visits - to understand how visitors use this website. No advertising cookies. You can say no - the website works fully either way. See our <a href="' . e(page_url('privacy-policy') . '#website-analytics-details') . '">privacy policy</a>.';
    } elseif ($own) {
        $text = 'May we set one first-party cookie (no third parties, no advertising) so our own website statistics can recognise return visits? Visits are still counted anonymously without it, and the website works fully either way. See our <a href="' . e(page_url('privacy-policy') . '#website-analytics-details') . '">privacy policy</a>.';
    } else {
        $text = 'We would like to use Google Analytics cookies to understand how visitors use this website. You can say no - the website works fully either way. See our <a href="' . e(page_url('privacy-policy')) . '">privacy policy</a>.';
    }
    return '<div class="consent-banner" data-consent-banner data-ga="' . ($ga ? e(setting('ga_measurement_id')) : '') . '" hidden role="region" aria-label="Cookie consent"><div class="container consent-inner">'
        . '<p>' . icon('info') . '<span>' . $text . '</span></p>'
        . '<div class="btn-row"><button type="button" class="btn btn-primary btn-sm" data-consent="yes">Allow analytics</button><button type="button" class="btn btn-ghost btn-sm" data-consent="no">No, thanks</button></div></div></div>';
}

/** Flash messages as accessible toast notifications (fallback: inline alerts without JS). */
function render_toasts(): string
{
    $html = render_flash();
    return $html !== '' ? str_replace('class="flash-wrap container"', 'class="flash-wrap toast-stack" data-toasts', $html) : '';
}
