<?php
/**
 * AI Assistant (website chatbot): on/off, OpenAI API key (stored encrypted, never shown again), model and
 * limits, greeting and quick questions, extra knowledge, connection test, usage statistics and the
 * conversation records (search, retention, delete all).
 *
 * Permission "assistant.manage" (administrators and content editors) covers the greeting, quick questions,
 * extra knowledge, the knowledge preview and usage counts. The API key, switching on/off, limits and the
 * conversation records also need "settings.manage" (administrators).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('assistant.manage');

$canConfigure = admin_can('settings.manage');
assistant_cleanup();   // retention: old conversation records and rate-limit rows

$modelChoices = ['gpt-4.1-mini', 'gpt-4.1-nano', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4o', 'gpt-5-mini', 'gpt-5-nano', 'gpt-5'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('assistant.php');
    $action = post_str('action');
    $needsConfig = in_array($action, ['connection', 'test', 'clear_error', 'logging', 'delete_logs'], true);
    if ($needsConfig && !$canConfigure) {
        admin_log('access_denied', 'AI assistant: "' . $action . '" needs the settings.manage permission');
        flash('error', 'Only administrators can change the connection, limits or conversation records.');
        admin_redirect('assistant.php');
    }

    if ($action === 'connection') {
        $errors = [];
        $enable = post_str('ai_assistant_enabled') === '1';
        $newKey = trim(post_str('openai_api_key'));
        $removeKey = post_str('remove_key') === '1';
        $model = trim(clean_string(post_str('ai_model')));
        $nums = [
            'ai_max_reply_tokens' => [50, 2000, 'Maximum reply length'],
            'ai_daily_cap' => [1, 100000, 'Daily limit'],
            'ai_hourly_per_visitor' => [1, 500, 'Messages per visitor per hour'],
        ];
        $values = [];
        foreach ($nums as $key => [$min, $max, $label]) {
            $v = trim(post_str($key));
            if (!preg_match('/^\d{1,6}$/', $v) || (int) $v < $min || (int) $v > $max) {
                $errors[$key] = $label . ' must be a whole number from ' . $min . ' to ' . number_format($max) . '.';
            } else {
                $values[$key] = (string) (int) $v;
            }
        }
        if ($newKey !== '' && !preg_match('/^[A-Za-z0-9_\-.:]{20,300}$/', $newKey)) {
            $errors['openai_api_key'] = 'That does not look like an API key (20-300 letters, digits, "-" or "_", no spaces). Paste the full secret key from the OpenAI dashboard.';
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-\/]{1,79}$/', $model)) {
            $errors['ai_model'] = 'Enter a model name such as gpt-4.1-mini.';
        }
        $configKey = defined('OPENAI_API_KEY') && (string) OPENAI_API_KEY !== '';
        $willHaveKey = $configKey || $newKey !== '' || (!$removeKey && secret_setting('openai_api_key') !== '');
        if ($enable && !$willHaveKey) {
            $errors['ai_assistant_enabled'] = 'Add an OpenAI API key first - the assistant cannot be switched on without one.';
        }
        if ($errors) {
            unset($_POST['openai_api_key']);
            back_with_errors($errors, 'admin/assistant.php#connection', $_POST);
        }
        $keyNote = 'unchanged';
        if ($removeKey && $newKey === '') {
            save_secret_setting('openai_api_key', '');
            $keyNote = 'removed';
        } elseif ($newKey !== '') {
            save_secret_setting('openai_api_key', $newKey);
            $keyNote = 'replaced';
        }
        $was = setting('ai_assistant_enabled') === '1';
        save_setting('ai_assistant_enabled', $enable ? '1' : '0');
        save_setting('ai_model', $model);
        foreach ($values as $k => $v) {
            save_setting($k, $v);
        }
        admin_log('assistant_settings_updated', 'AI assistant ' . ($enable ? 'ON' : 'off') . ($enable !== $was ? ' (switched ' . ($enable ? 'on' : 'off') . ')' : '')
            . '; model ' . $model . '; max reply tokens ' . $values['ai_max_reply_tokens'] . '; daily limit ' . $values['ai_daily_cap']
            . '; per visitor/hour ' . $values['ai_hourly_per_visitor'] . '; API key ' . $keyNote);
        $msg = 'AI assistant settings saved.';
        if ($enable && !assistant_active()) {
            $msg .= ' Note: the saved key cannot be read, so the assistant stays off until a key is entered again.';
        } elseif ($enable && $keyNote === 'replaced') {
            $msg .= ' Use "Test connection" to check the new key.';
        }
        flash('success', $msg);
        admin_redirect('assistant.php#connection');
    }

    if ($action === 'test') {
        if (assistant_api_key() === '') {
            flash('error', 'Save an OpenAI API key first.');
            admin_redirect('assistant.php#connection');
        }
        $res = assistant_openai_chat([
            ['role' => 'system', 'content' => 'You are a connection test. Reply with the single word OK.'],
            ['role' => 'user', 'content' => 'Connection test'],
        ], ['max_tokens' => 20, 'timeout' => 20]);
        if ($res['ok']) {
            save_setting('ai_last_success_at', date('Y-m-d H:i:s'));
            $tokens = (int) ($res['usage']['total_tokens'] ?? 0);
            admin_log('assistant_connection_tested', 'OK · model ' . $res['model'] . ' · ' . $res['ms'] . ' ms');
            flash('success', 'Connected. The model "' . $res['model'] . '" replied "' . mb_substr($res['text'], 0, 40) . '" in ' . number_format($res['ms']) . ' ms' . ($tokens ? ' (' . $tokens . ' tokens)' : '') . '.');
        } else {
            assistant_record_error($res['code'], (int) $res['status'], $res['message']);
            admin_log('assistant_connection_tested', 'Failed: ' . $res['code'] . ($res['status'] ? ' (HTTP ' . $res['status'] . ')' : ''));
            flash('error', 'Connection test failed: ' . assistant_scrub_secret($res['message']));
        }
        admin_redirect('assistant.php#connection');
    }

    if ($action === 'clear_error') {
        save_setting('ai_last_error', '');
        admin_log('assistant_error_cleared', 'Last AI assistant error cleared');
        flash('success', 'Error message cleared.');
        admin_redirect('assistant.php#connection');
    }

    if ($action === 'content') {
        $errors = [];
        $greetEn = trim(clean_string(post_str('ai_greeting_en'), true));
        $greetHi = trim(clean_string(post_str('ai_greeting_hi'), true));
        $extra = trim(clean_string(post_str('ai_extra_knowledge'), true));
        $lines = array_values(array_filter(array_map('trim', explode("\n", clean_string(post_str('ai_suggestions'), true))), 'strlen'));
        if ($greetEn === '' || mb_strlen($greetEn) > 400) {
            $errors['ai_greeting_en'] = 'Enter an English greeting of up to 400 characters.';
        }
        if (mb_strlen($greetHi) > 400) {
            $errors['ai_greeting_hi'] = 'The Hindi greeting can have up to 400 characters.';
        }
        if (count($lines) > 8) {
            $errors['ai_suggestions'] = 'Add up to 8 quick questions (one per line).';
        }
        foreach ($lines as $l) {
            if (mb_strlen($l) > 90) {
                $errors['ai_suggestions'] = 'Each quick question can have up to 90 characters: "' . mb_substr($l, 0, 40) . '…" is too long.';
            }
        }
        if (mb_strlen($extra) > AI_EXTRA_MAX) {
            $errors['ai_extra_knowledge'] = 'Extra knowledge can have up to ' . number_format(AI_EXTRA_MAX) . ' characters (currently ' . number_format(mb_strlen($extra)) . ').';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/assistant.php#content', $_POST);
        }
        $new = [
            'ai_greeting_en' => $greetEn, 'ai_greeting_hi' => $greetHi, 'ai_extra_knowledge' => $extra,
            'ai_suggestions' => json_encode(array_values(array_unique($lines)), JSON_UNESCAPED_UNICODE),
        ];
        $changed = [];
        foreach ($new as $k => $v) {
            if (setting($k) !== $v) {
                save_setting($k, $v);
                $changed[] = str_replace('ai_', '', $k);
            }
        }
        if ($changed) {
            admin_log('assistant_content_updated', 'AI assistant: ' . implode(', ', $changed) . ' updated');
        }
        flash($changed ? 'success' : 'info', $changed ? 'Greeting, quick questions and knowledge saved.' : 'Nothing changed.');
        admin_redirect('assistant.php#content');
    }

    if ($action === 'logging') {
        $on = post_str('ai_log_conversations') === '1';
        $days = trim(post_str('ai_log_retention_days'));
        if (!preg_match('/^\d{1,3}$/', $days) || (int) $days < 1 || (int) $days > 365) {
            back_with_errors(['ai_log_retention_days' => 'Keep records for 1 to 365 days.'], 'admin/assistant.php#records', $_POST);
        }
        save_setting('ai_log_conversations', $on ? '1' : '0');
        save_setting('ai_log_retention_days', (string) (int) $days);
        $deleted = assistant_cleanup();
        admin_log('assistant_logging_updated', 'Conversation records ' . ($on ? 'on' : 'off') . ', kept ' . (int) $days . ' days' . ($deleted ? '; ' . $deleted . ' older records deleted' : ''));
        flash('success', 'Conversation record settings saved' . ($deleted ? ' and ' . $deleted . ' older record' . ($deleted === 1 ? '' : 's') . ' deleted' : '') . '.');
        admin_redirect('assistant.php#records');
    }

    if ($action === 'delete_logs') {
        if (post_str('confirm_delete') !== '1') {
            back_with_errors(['confirm_delete' => 'Tick the box to confirm that all conversation records should be deleted.'], 'admin/assistant.php#records', []);
        }
        $n = db_query('DELETE FROM ai_chat_logs')->rowCount();
        admin_log('assistant_logs_deleted', $n . ' AI assistant conversation records deleted');
        flash('success', number_format($n) . ' conversation record' . ($n === 1 ? '' : 's') . ' deleted.');
        admin_redirect('assistant.php#records');
    }
    admin_redirect('assistant.php');
}

// ---------------------------------------------------------------------
// Data for the page
// ---------------------------------------------------------------------
$usage = function (int $days): array {
    $row = db_row('SELECT COALESCE(SUM(messages),0) AS messages, COALESCE(SUM(ai_calls),0) AS ai_calls, COALESCE(SUM(ai_replies),0) AS ai_replies,
                          COALESCE(SUM(fallbacks),0) AS fallbacks, COALESCE(SUM(errors),0) AS errors, COALESCE(SUM(limited),0) AS limited,
                          COALESCE(SUM(prompt_tokens),0) AS prompt_tokens, COALESCE(SUM(completion_tokens),0) AS completion_tokens
                   FROM ai_usage_daily WHERE day >= CURDATE() - INTERVAL ' . max(0, $days - 1) . ' DAY');
    return array_map('intval', $row ?: []);
};
$periods = ['Today' => $usage(1), 'Last 7 days' => $usage(7), 'Last 30 days' => $usage(30)];
$chart = [];
$daily = [];
foreach (db_rows('SELECT day, messages FROM ai_usage_daily WHERE day >= CURDATE() - INTERVAL 13 DAY') as $r) {
    $daily[$r['day']] = (int) $r['messages'];
}
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime('-' . $i . ' days'));
    $chart[date('d M', strtotime($d))] = $daily[$d] ?? 0;
}
$callsToday = assistant_calls_today();
$cap = assistant_daily_cap();
$active = assistant_active();
$enabled = assistant_enabled();
$keyHint = secret_hint('OPENAI_API_KEY', 'openai_api_key');
$keySource = secret_source('OPENAI_API_KEY', 'openai_api_key');
$lastError = assistant_last_error();
$lastSuccess = setting('ai_last_success_at');
$m30 = $periods['Last 30 days'];
$fallbackShare = $m30['messages'] > 0 ? round($m30['fallbacks'] / $m30['messages'] * 100) : 0;

$topQuestions = [];
$logs = [];
$logTotal = 0;
$pager = null;
$logQ = trim(mb_substr(get_str('q'), 0, 100));
$logFilter = get_str('show');
if ($canConfigure) {
    $topQuestions = db_rows('SELECT question, COUNT(*) AS c, SUM(used_fallback) AS fb FROM ai_chat_logs WHERE created_at >= NOW() - INTERVAL 30 DAY
                             GROUP BY question ORDER BY c DESC, MAX(id) DESC LIMIT 10');
    $params = [];
    $where = 'WHERE 1=1' . search_where(['question', 'answer', 'page_path', 'session_hash'], $logQ, $params);
    if ($logFilter === 'fallback') {
        $where .= ' AND used_fallback = 1';
    } elseif ($logFilter === 'ai') {
        $where .= ' AND used_fallback = 0';
    }
    $logTotal = (int) db_value('SELECT COUNT(*) FROM ai_chat_logs ' . $where, $params);
    $pager = paginate($logTotal, 25);
    $logs = db_rows('SELECT * FROM ai_chat_logs ' . $where . ' ORDER BY id DESC LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset'], $params);
}

// knowledge preview
$previewQ = trim(mb_substr(get_str('preview'), 0, 300));
$preview = null;
if ($previewQ !== '') {
    $preview = ['rank' => assistant_rank($previewQ, '', 10), 'context' => assistant_build_context($previewQ), 'lang' => assistant_detect_lang($previewQ)];
}

$val = function (string $key, string $default = '') {
    $v = old($key, null);
    return ($v === null || is_array($v)) ? setting($key, $default) : (string) $v;
};
$suggestText = implode("\n", assistant_suggestions());
$oldSuggest = old('ai_suggestions', null);
if (is_string($oldSuggest)) {
    $suggestText = $oldSuggest;
}
$enabledChecked = (string) old('ai_assistant_enabled', $enabled ? '1' : '0') === '1';
$logChecked = (string) old('ai_log_conversations', setting('ai_log_conversations', '1')) === '1';

admin_page_start('AI Assistant (chatbot)', [
    ['label' => 'Privacy notice', 'url' => page_url('privacy-policy') . '#ai-assistant', 'icon' => 'shield', 'style' => 'ghost'],
    ['label' => 'FAQs', 'url' => admin_url('faqs.php'), 'icon' => 'help-circle', 'style' => 'outline'],
]);
?>
<div class="ai-admin">
<div class="stat-cards">
    <div class="stat-card"><span class="icon-circle<?= $active ? '' : ' gold' ?>"><?= icon($active ? 'check-circle' : 'zap') ?></span><div><strong><?= $active ? 'On' : ($enabled ? 'On - no key' : 'Off') ?></strong><small><?= $active ? 'Visitors can chat with the assistant' : 'Visitors see the FAQ helper' ?></small></div></div>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('message') ?></span><div><strong><?= number_format($callsToday) ?> / <?= number_format($cap) ?></strong><small>AI requests today / daily limit</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('bar-chart') ?></span><div><strong><?= number_format($periods['Last 7 days']['messages']) ?></strong><small>Messages, last 7 days</small></div></div>
    <div class="stat-card"><span class="icon-circle gold"><?= icon('life-buoy') ?></span><div><strong><?= $fallbackShare ?>%</strong><small>Answered from FAQs (30 days)</small></div></div>
</div>

<?php if ($lastError): ?>
    <div class="alert alert-warning ai-admin-error" role="status"><?= icon('alert-triangle') ?><div>
        <strong>Last problem with the OpenAI API</strong> · <?= e(format_date($lastError['at'], 'd M Y, h:i A')) ?>
        · <?= e(humanize((string) $lastError['code'])) ?><?= !empty($lastError['status']) ? ' · HTTP ' . (int) $lastError['status'] : '' ?>
        <p class="mb-0"><?= e($lastError['message']) ?></p>
        <?php if ($lastSuccess !== '' && strtotime($lastSuccess) > strtotime($lastError['at'])): ?><p class="mb-0 help">A reply succeeded after this, on <?= e(format_date($lastSuccess, 'd M Y, h:i A')) ?>.</p><?php endif; ?>
        <p class="help mb-0">Visitors received answers from the FAQs instead. Common causes: wrong or revoked key (401), no billing credit (429 quota), a model name the key cannot use (404), or a temporary OpenAI outage (5xx).</p>
    </div>
    <?php if ($canConfigure): ?>
        <form method="post" action="<?= e(admin_url('assistant.php')) ?>" class="ai-admin-inline"><?= csrf_field() ?><input type="hidden" name="action" value="clear_error"><button class="btn btn-ghost btn-sm" type="submit">Clear</button></form>
    <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Connection & limits -->
<?php if ($canConfigure): ?>
<form method="post" action="<?= e(admin_url('assistant.php')) ?>" class="panel" id="connection" autocomplete="off">
    <div class="panel-head"><h2><?= icon('key') ?>Connection &amp; limits</h2><span class="badge <?= $active ? 'badge-green' : 'badge-gray' ?>"><?= $active ? 'On' : 'Off' ?></span></div>
    <?= csrf_field() ?><input type="hidden" name="action" value="connection">
    <p class="help mt-0">The assistant answers visitors' questions using only the information on this website (pages, programmes, events, schemes, FAQs and the extra knowledge below), and links them to the right page or form. Replies are generated by OpenAI on the server; the key never reaches visitors' browsers. While it is off - or if OpenAI is unavailable or today's limit is reached - visitors get answers from the FAQs.</p>
    <div class="form-grid">
        <div class="form-group full">
            <input type="hidden" name="ai_assistant_enabled" value="0">
            <label class="checkbox" for="ai_assistant_enabled"><input type="checkbox" id="ai_assistant_enabled" name="ai_assistant_enabled" value="1"<?= $enabledChecked ? ' checked' : '' ?>><span><strong>Switch on the AI assistant</strong> (the floating help button opens the chat)</span></label>
            <?= field_error('ai_assistant_enabled') ?>
        </div>
        <div class="form-group full">
            <label for="openai_api_key">OpenAI API key</label>
            <input type="password" id="openai_api_key" name="openai_api_key" value="" autocomplete="off" spellcheck="false" placeholder="<?= $keySource === '' ? 'Paste your secret key (sk-...)' : 'Leave blank to keep the saved key' ?>" aria-describedby="key-help">
            <span class="help" id="key-help"><strong><?= e($keyHint) ?></strong>. Leave blank to keep the saved value. Stored encrypted and never shown again.
                <?php if ($keySource === 'config'): ?> A key defined as OPENAI_API_KEY in pwf-config.php is used and overrides a key saved here.<?php endif; ?>
                Create a key at platform.openai.com &gt; API keys, and set a monthly budget limit in your OpenAI billing settings.</span>
            <?= field_error('openai_api_key') ?>
            <?php if (secret_setting('openai_api_key') !== '' || secret_unreadable('openai_api_key')): ?>
                <label class="checkbox" for="remove_key"><input type="checkbox" id="remove_key" name="remove_key" value="1"><span>Remove the saved key</span></label>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="ai_model">Model</label>
            <input type="text" id="ai_model" name="ai_model" value="<?= e($val('ai_model', AI_DEFAULT_MODEL)) ?>" list="ai-models" maxlength="80" required spellcheck="false" aria-describedby="model-help">
            <datalist id="ai-models"><?php foreach ($modelChoices as $mc): ?><option value="<?= e($mc) ?>"><?php endforeach; ?></datalist>
            <span class="help" id="model-help">Default <strong>gpt-4.1-mini</strong> (fast, low cost). You can type another model, e.g. gpt-4o-mini or gpt-5-mini; reasoning models (gpt-5, o3, o4) are slower and use more tokens.</span>
            <?= field_error('ai_model') ?>
        </div>
        <div class="form-group">
            <label for="ai_max_reply_tokens">Maximum reply length (tokens)</label>
            <input type="number" id="ai_max_reply_tokens" name="ai_max_reply_tokens" min="50" max="2000" step="10" value="<?= e($val('ai_max_reply_tokens', '400')) ?>" required aria-describedby="tok-help">
            <span class="help" id="tok-help">About 400 tokens ≈ 250-300 English words. Hindi uses more tokens per word.</span>
            <?= field_error('ai_max_reply_tokens') ?>
        </div>
        <div class="form-group">
            <label for="ai_daily_cap">Daily limit (AI replies for the whole website)</label>
            <input type="number" id="ai_daily_cap" name="ai_daily_cap" min="1" max="100000" value="<?= e($val('ai_daily_cap', '300')) ?>" required aria-describedby="cap-help">
            <span class="help" id="cap-help">Controls cost. After the limit, visitors get FAQ answers until midnight.</span>
            <?= field_error('ai_daily_cap') ?>
        </div>
        <div class="form-group">
            <label for="ai_hourly_per_visitor">Messages per visitor per hour</label>
            <input type="number" id="ai_hourly_per_visitor" name="ai_hourly_per_visitor" min="1" max="500" value="<?= e($val('ai_hourly_per_visitor', '20')) ?>" required aria-describedby="hour-help">
            <span class="help" id="hour-help">Per visit. One network (for example a shared mobile-network address) may send up to <?= assistant_ip_hourly_limit() ?> per hour.</span>
            <?= field_error('ai_hourly_per_visitor') ?>
        </div>
        <?php if (rtrim(OPENAI_API_BASE, '/') !== 'https://api.openai.com/v1'): ?>
            <div class="form-group full"><span class="help"><?= icon('info') ?> API address set in the configuration: <code><?= e(OPENAI_API_BASE) ?></code></span></div>
        <?php endif; ?>
    </div>
    <div class="form-actions mt-2"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save connection &amp; limits</button></div>
    <p class="help mb-0">Last successful reply: <?= $lastSuccess !== '' ? e(format_date($lastSuccess, 'd M Y, h:i A')) : 'none yet' ?>.</p>
</form>
<form method="post" action="<?= e(admin_url('assistant.php')) ?>" class="panel ai-admin-test">
    <?= csrf_field() ?><input type="hidden" name="action" value="test">
    <div><strong><?= icon('activity') ?>Test connection</strong><p class="help mb-0">Sends a tiny request ("reply OK") with the saved key and model. Costs a few tokens.</p></div>
    <button class="btn btn-outline" type="submit"<?= assistant_api_key() === '' ? ' disabled' : '' ?>><?= icon('zap') ?>Test connection</button>
</form>
<?php else: ?>
<section class="panel" id="connection">
    <div class="panel-head"><h2><?= icon('key') ?>Connection &amp; limits</h2><span class="badge <?= $active ? 'badge-green' : 'badge-gray' ?>"><?= $active ? 'On' : 'Off' ?></span></div>
    <p class="mb-0">Model <strong><?= e(assistant_model()) ?></strong> · daily limit <?= number_format($cap) ?> AI replies · <?= assistant_hourly_limit() ?> messages per visitor per hour. Only administrators can change the API key, switch the assistant on or off and view conversation records.</p>
</section>
<?php endif; ?>

<!-- Greeting, quick questions, extra knowledge -->
<form method="post" action="<?= e(admin_url('assistant.php')) ?>" class="panel" id="content">
    <div class="panel-head"><h2><?= icon('message') ?>Greeting, quick questions &amp; knowledge</h2></div>
    <?= csrf_field() ?><input type="hidden" name="action" value="content">
    <div class="form-grid">
        <div class="form-group">
            <label for="ai_greeting_en">Greeting (English)</label>
            <textarea id="ai_greeting_en" name="ai_greeting_en" rows="3" maxlength="400" required><?= e($val('ai_greeting_en', assistant_default_greeting('en'))) ?></textarea>
            <?= field_error('ai_greeting_en') ?>
        </div>
        <div class="form-group">
            <label for="ai_greeting_hi">Greeting (Hindi, optional)</label>
            <textarea id="ai_greeting_hi" name="ai_greeting_hi" rows="3" maxlength="400" lang="hi" class="hindi"><?= e($val('ai_greeting_hi')) ?></textarea>
            <?= field_error('ai_greeting_hi') ?>
        </div>
        <div class="form-group full">
            <label for="ai_suggestions">Quick questions (one per line, up to 8)</label>
            <textarea id="ai_suggestions" name="ai_suggestions" rows="6" aria-describedby="sugg-help"><?= e($suggestText) ?></textarea>
            <span class="help" id="sugg-help">Shown as buttons when the chat opens. Keep them honest and about things the website really offers, e.g. "How can I volunteer?", "Jobs &amp; internships".</span>
            <?= field_error('ai_suggestions') ?>
            <?php if (assistant_suggestions()): ?>
                <div class="ai-admin-chips" aria-label="Current quick questions"><?php foreach (assistant_suggestions() as $s): ?><span><?= e($s) ?></span><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
        <div class="form-group full">
            <label for="ai_extra_knowledge">Extra knowledge (facts the assistant may use)</label>
            <textarea id="ai_extra_knowledge" name="ai_extra_knowledge" rows="8" maxlength="<?= AI_EXTRA_MAX ?>" aria-describedby="extra-help"><?= e($val('ai_extra_knowledge')) ?></textarea>
            <span class="help" id="extra-help">Short, factual notes that are not on the website yet - for example the timing of a coming camp or how long application reviews usually take. Visitors may see anything written here, so <strong>never</strong> add personal data, passwords, internal notes or unverified claims (figures, tax benefits, registrations). Up to <?= number_format(AI_EXTRA_MAX) ?> characters (all of it is sent with every question). Everything else (pages, programmes, campaigns, events, schemes, FAQs, blog posts) is read from the website automatically.</span>
            <?= field_error('ai_extra_knowledge') ?>
            <?php if (mb_strlen(setting('ai_extra_knowledge')) > AI_EXTRA_MAX): ?>
                <p class="alert alert-warning mt-1 mb-0">The saved text has <?= number_format(mb_strlen(setting('ai_extra_knowledge'))) ?> characters; only the first <?= number_format(AI_EXTRA_MAX) ?> are used. Please shorten it.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="form-actions mt-2"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save greeting &amp; knowledge</button></div>
</form>

<!-- Knowledge preview -->
<section class="panel" id="preview">
    <div class="panel-head"><h2><?= icon('search') ?>What the assistant knows for a question</h2></div>
    <form method="get" action="<?= e(admin_url('assistant.php')) ?>#preview" class="filter-row">
        <label class="sr-only" for="preview-q">Sample question</label>
        <input type="search" id="preview-q" name="preview" value="<?= e($previewQ) ?>" maxlength="300" placeholder="e.g. How can I become a volunteer teacher? / दान कैसे करें?">
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('search') ?>Show knowledge</button>
    </form>
    <p class="help">Shows the website information that would be sent with this question (no request is sent to OpenAI). Improve answers by editing the matching page, programme or FAQ.</p>
    <?php if ($preview): ?>
        <h3 class="h4">Best-matching website content <small class="muted">(language: <?= e(['hi' => 'Hindi', 'hinglish' => 'Hinglish', 'en' => 'English'][$preview['lang']]) ?>)</small></h3>
        <?php if ($preview['rank']): ?>
            <ol class="ai-admin-rank">
                <?php foreach ($preview['rank'] as $d): ?>
                    <li><a href="<?= e($d['url']) ?>" target="_blank" rel="noopener"><?= e($d['title']) ?></a> <span class="muted small"><?= e($d['url']) ?> · score <?= e((string) $d['score']) ?></span></li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <p class="muted">No specific match - the assistant would rely on the core facts (contact, key pages, programmes, events).</p>
        <?php endif; ?>
        <details class="ai-admin-details"><summary>Full knowledge text (<?= number_format(mb_strlen($preview['context']['text'])) ?> characters)</summary>
            <pre class="ai-admin-pre"><?= e($preview['context']['text']) ?></pre></details>
    <?php endif; ?>
</section>

<!-- Usage -->
<section class="panel" id="usage">
    <div class="panel-head"><h2><?= icon('bar-chart') ?>Usage</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th scope="col">Period</th><th scope="col">Messages</th><th scope="col">AI replies</th><th scope="col">FAQ answers</th><th scope="col">API errors</th><th scope="col">Rate-limited</th><th scope="col">Tokens (in / out)</th></tr></thead>
        <tbody>
        <?php foreach ($periods as $label => $p): ?>
            <tr><th scope="row"><?= e($label) ?></th><td><?= number_format($p['messages'] ?? 0) ?></td><td><?= number_format($p['ai_replies'] ?? 0) ?></td>
                <td><?= number_format($p['fallbacks'] ?? 0) ?></td><td><?= number_format($p['errors'] ?? 0) ?></td><td><?= number_format($p['limited'] ?? 0) ?></td>
                <td><?= number_format($p['prompt_tokens'] ?? 0) ?> / <?= number_format($p['completion_tokens'] ?? 0) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <h3 class="h4 mt-2">Messages per day (last 14 days)</h3>
    <?= admin_bar_chart($chart) ?>
    <p class="help">Token counts come from OpenAI's replies; check exact costs in your OpenAI usage dashboard.</p>
    <?php if ($canConfigure): ?>
        <h3 class="h4 mt-2">Most asked (last 30 days)</h3>
        <?php if ($topQuestions): ?>
            <ol class="ai-admin-top">
                <?php foreach ($topQuestions as $t): ?>
                    <li><span><?= e($t['question']) ?></span> <span class="badge badge-blue"><?= (int) $t['c'] ?>×</span><?= (int) $t['fb'] > 0 ? ' <span class="badge badge-gold">' . (int) $t['fb'] . ' from FAQs</span>' : '' ?></li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <p class="muted">No recorded questions yet<?= assistant_log_enabled() ? '' : ' (conversation records are switched off)' ?>.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($canConfigure): ?>
<!-- Conversation records -->
<section class="panel" id="records">
    <div class="panel-head"><h2><?= icon('inbox') ?>Conversation records</h2><span class="badge <?= assistant_log_enabled() ? 'badge-green' : 'badge-gray' ?>"><?= assistant_log_enabled() ? 'Recording · ' . assistant_retention_days() . ' days' : 'Off' ?></span></div>
    <form method="post" action="<?= e(admin_url('assistant.php')) ?>" class="ai-admin-logging">
        <?= csrf_field() ?><input type="hidden" name="action" value="logging">
        <input type="hidden" name="ai_log_conversations" value="0">
        <label class="checkbox" for="ai_log_conversations"><input type="checkbox" id="ai_log_conversations" name="ai_log_conversations" value="1"<?= $logChecked ? ' checked' : '' ?>><span>Keep a record of questions and replies (no IP address or name; obvious personal numbers and emails are removed)</span></label>
        <div class="form-group"><label for="ai_log_retention_days">Delete records after (days)</label>
            <input type="number" id="ai_log_retention_days" name="ai_log_retention_days" min="1" max="365" value="<?= e($val('ai_log_retention_days', '30')) ?>" required><?= field_error('ai_log_retention_days') ?></div>
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save</button>
    </form>

    <?php admin_filter_bar([], ['placeholder' => 'Search questions, replies, pages or conversation code',
        'html' => '<select name="show" aria-label="Show"><option value="">All replies</option><option value="ai"' . ($logFilter === 'ai' ? ' selected' : '') . '>AI replies</option><option value="fallback"' . ($logFilter === 'fallback' ? ' selected' : '') . '>FAQ answers</option></select>',
        'active' => $logFilter !== '']); ?>
    <p class="table-meta"><?= number_format($logTotal) ?> record<?= $logTotal === 1 ? '' : 's' ?><?= $logQ !== '' ? ' matching "' . e($logQ) . '"' : '' ?>. Records older than <?= assistant_retention_days() ?> days are deleted automatically.</p>
    <?php if ($logs): ?>
        <div class="table-wrap"><table class="table ai-admin-log">
            <thead><tr><th scope="col">When</th><th scope="col">Question</th><th scope="col">Reply</th><th scope="col">Details</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="nowrap"><?= e(format_date($l['created_at'], 'd M, h:i A')) ?><br><small class="muted" title="Conversation code (random per visit)">#<?= e(substr($l['session_hash'], 0, 6)) ?></small></td>
                    <td><?= e_nl($l['question']) ?><?= $l['page_path'] ? '<br><small class="muted">on ' . e($l['page_path']) . '</small>' : '' ?></td>
                    <td><?php $ans = (string) $l['answer']; ?>
                        <?php if (mb_strlen($ans) > 220): ?>
                            <details class="ai-admin-details"><summary><?= e(mb_substr($ans, 0, 200)) ?>…</summary><div class="ai-admin-answer"><?= e_nl($ans) ?></div></details>
                        <?php else: ?><?= e_nl($ans) ?><?php endif; ?></td>
                    <td><?= $l['used_fallback'] ? '<span class="badge badge-gold">FAQ · ' . e(humanize(str_replace('error:', 'error ', (string) $l['fallback_reason']))) . '</span>' : '<span class="badge badge-green">AI</span>' ?>
                        <?= $l['model'] ? '<br><small class="muted">' . e($l['model']) . '</small>' : '' ?>
                        <?= $l['total_tokens'] ? '<br><small class="muted">' . number_format((int) $l['total_tokens']) . ' tokens</small>' : '' ?>
                        <?= $l['lang'] ? '<br><small class="muted">' . e(['hi' => 'Hindi', 'hinglish' => 'Hinglish', 'en' => 'English'][$l['lang']] ?? $l['lang']) . '</small>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($pager) ?>
    <?php else: ?>
        <p class="muted"><?= $logQ !== '' || $logFilter !== '' ? 'No records match.' : 'No conversation records yet.' ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(admin_url('assistant.php')) ?>" class="ai-admin-delete" data-confirm="Delete ALL conversation records? This cannot be undone.">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_logs">
        <label class="checkbox" for="confirm_delete"><input type="checkbox" id="confirm_delete" name="confirm_delete" value="1" required><span>I want to delete all <?= number_format((int) db_value('SELECT COUNT(*) FROM ai_chat_logs')) ?> conversation records</span></label>
        <?= field_error('confirm_delete') ?>
        <button class="btn btn-outline btn-sm ai-admin-danger" type="submit"><?= icon('trash') ?>Delete all records</button>
    </form>
</section>
<?php endif; ?>
</div>
<?php
admin_page_end();
