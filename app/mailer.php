<?php
/**
 * Outbound messaging: Email (SMTP or PHP mail()), SMS and WhatsApp (HTTP gateway, integration-ready).
 * Every message is recorded in `message_logs`.
 *
 *   send_mail('parent@example.com', 'Fee reminder', '<p>Hello</p>');
 *   send_template_mail('password_reset', 'user@x.com', ['name' => 'Asha', 'reset_link' => '...']);
 *   send_sms('+919999999999', 'Your fee is due');
 *   send_whatsapp('+919999999999', 'Your result is published');
 *
 * In dev mode (config app.env = dev) or when Settings > Email > mail_driver = log, emails are written to
 * storage/logs/mail.log instead of being sent.
 */

function message_log(array $data): int
{
    try {
        return db_insert('message_logs', $data);
    } catch (Throwable $e) {
        error_log('[GIMT] message_logs insert failed: ' . $e->getMessage());
        return 0;
    }
}

/** Wrap HTML body in the branded email layout. */
function email_layout(string $html, string $subject = ''): string
{
    $name = e(institute_name());
    $logo = e(absolute_url('assets/images/logo.svg'));
    $year = date('Y');
    return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>{$subject}</title></head>
<body style="margin:0;background:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1e293b">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0">
<tr><td style="background:#0B2A5B;padding:18px 28px"><img src="{$logo}" alt="GIMT" height="40" style="display:block;background:#fff;border-radius:8px;padding:4px 8px"></td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.6">{$html}</td></tr>
<tr><td style="padding:16px 28px;background:#f8fafc;font-size:12px;color:#64748b;border-top:1px solid #e2e8f0">&copy; {$year} {$name}. This is an automated message.</td></tr>
</table></td></tr></table></body></html>
HTML;
}

/**
 * Send an email. Returns true on success.
 * $opts: from_name, from_email, reply_to, cc[], bcc[], attachments[] (absolute paths), related_type, related_id, campaign_id, raw (skip layout)
 */
function send_mail($to, string $subject, string $html, array $opts = []): bool
{
    $recipients = array_values(array_filter((array) $to, fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
    if (!$recipients) {
        return false;
    }
    $body = empty($opts['raw']) ? email_layout($html, e($subject)) : $html;
    $fromEmail = $opts['from_email'] ?? setting('mail_from_email', setting('email', 'no-reply@gimt.ac.in'));
    $fromName = $opts['from_name'] ?? setting('mail_from_name', institute_name());
    $driver = is_dev() ? 'log' : setting('mail_driver', 'smtp');
    if ($driver === 'smtp' && !setting('smtp_host')) {
        $driver = 'mail';
    }

    $ok = false;
    $error = null;
    try {
        if ($driver === 'log') {
            $line = sprintf("[%s] TO: %s | SUBJECT: %s\n%s\n\n", date('c'), implode(', ', $recipients), $subject, strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));
            $ok = file_put_contents(STORAGE_PATH . '/logs/mail.log', $line, FILE_APPEND | LOCK_EX) !== false;
        } elseif ($driver === 'smtp') {
            $ok = smtp_send($recipients, $subject, $body, $fromEmail, $fromName, $opts);
        } else {
            $headers = mime_headers($fromEmail, $fromName, $opts);
            $ok = mail(implode(', ', $recipients), encode_header($subject), chunk_split(base64_encode($body)), $headers);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $ok = false;
    }
    foreach ($recipients as $r) {
        message_log([
            'channel' => 'email', 'recipient' => $r, 'subject' => mb_substr($subject, 0, 255), 'body' => $html,
            'status' => $ok ? 'sent' : 'failed', 'error' => $error, 'related_type' => $opts['related_type'] ?? null,
            'related_id' => $opts['related_id'] ?? null, 'campaign_id' => $opts['campaign_id'] ?? null, 'sent_at' => $ok ? date('Y-m-d H:i:s') : null,
        ]);
    }
    if (!$ok) {
        log_system('error', 'Email delivery failed', ['to' => $recipients, 'subject' => $subject, 'error' => $error]);
    }
    return $ok;
}

/** Replace {{placeholders}} with escaped values. */
function render_placeholders(string $template, array $vars, bool $escape = true): string
{
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars, $escape) {
        $v = $vars[$m[1]] ?? '';
        return $escape ? e($v) : (string) $v;
    }, $template);
}

/** Send using a stored template from `email_templates` (code). */
function send_template_mail(string $code, $to, array $vars, array $opts = []): bool
{
    $tpl = db_row("SELECT * FROM email_templates WHERE code = ? AND status = 'active'", [$code]);
    $vars += ['institute_name' => institute_name(), 'site_url' => absolute_url(''), 'year' => date('Y')];
    if (!$tpl) {
        return send_mail($to, $vars['subject'] ?? institute_name(), '<p>' . e(json_encode($vars)) . '</p>', $opts);
    }
    return send_mail($to, render_placeholders($tpl['subject'], $vars, false), render_placeholders($tpl['body_html'], $vars), $opts);
}

function encode_header(string $text): string
{
    return preg_match('/[^\x20-\x7E]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
}

function mime_headers(string $fromEmail, string $fromName, array $opts): string
{
    $h = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'From: ' . encode_header($fromName) . ' <' . $fromEmail . '>',
        'X-Mailer: GIMT SmartCampus',
    ];
    if (!empty($opts['reply_to'])) {
        $h[] = 'Reply-To: ' . $opts['reply_to'];
    }
    return implode("\r\n", $h);
}

/** Minimal SMTP client supporting SSL (465), STARTTLS (587) and AUTH LOGIN. */
function smtp_send(array $recipients, string $subject, string $html, string $fromEmail, string $fromName, array $opts = []): bool
{
    $host = setting('smtp_host');
    $port = (int) setting('smtp_port', 587);
    $enc = setting('smtp_encryption', 'tls'); // ssl | tls | none
    $user = setting('smtp_username');
    $pass = setting('smtp_password');
    $timeout = 20;
    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    }
    stream_set_timeout($fp, $timeout);
    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (string $command, array $expect) use ($fp, $read) {
        fwrite($fp, $command . "\r\n");
        $resp = $read();
        if (!in_array((int) substr($resp, 0, 3), $expect, true)) {
            throw new RuntimeException('SMTP error after "' . explode(' ', $command)[0] . '": ' . trim($resp));
        }
        return $resp;
    };
    $read();
    $ehloHost = parse_url(absolute_url(''), PHP_URL_HOST) ?: 'localhost';
    $cmd('EHLO ' . $ehloHost, [250]);
    if ($enc === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('SMTP STARTTLS negotiation failed');
        }
        $cmd('EHLO ' . $ehloHost, [250]);
    }
    if ($user) {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        $cmd(base64_encode((string) $pass), [235]);
    }
    $cmd('MAIL FROM:<' . $fromEmail . '>', [250]);
    foreach ($recipients as $r) {
        $cmd('RCPT TO:<' . $r . '>', [250, 251]);
    }
    $cmd('DATA', [354]);
    $headers = 'Date: ' . date('r') . "\r\n" . 'To: ' . implode(', ', $recipients) . "\r\n" . 'Subject: ' . encode_header($subject) . "\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $ehloHost . ">\r\n" . mime_headers($fromEmail, $fromName, $opts) . "\r\n";
    $msg = $headers . "\r\n" . chunk_split(base64_encode($html)) . "\r\n.";
    $cmd($msg, [250]);
    $cmd('QUIT', [221]);
    fclose($fp);
    return true;
}

/**
 * Generic HTTP gateway call used by SMS and WhatsApp.
 * Settings: {channel}_enabled, {channel}_api_url (supports {phone}, {message}, {sender} placeholders),
 * {channel}_method (GET|POST), {channel}_api_key (sent as Authorization: Bearer), {channel}_sender_id.
 */
function gateway_send(string $channel, string $phone, string $message, array $opts = []): bool
{
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    $status = 'queued';
    $error = null;
    $ok = false;
    if (setting($channel . '_enabled', '0') === '1' && setting($channel . '_api_url') && !is_dev()) {
        $vars = ['{phone}' => rawurlencode($phone), '{message}' => rawurlencode($message), '{sender}' => rawurlencode((string) setting($channel . '_sender_id', 'GIMTIN'))];
        $url = strtr((string) setting($channel . '_api_url'), $vars);
        $method = strtoupper((string) setting($channel . '_method', 'POST'));
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($key = setting($channel . '_api_key')) {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['to' => $phone, 'message' => $message, 'sender' => setting($channel . '_sender_id', 'GIMTIN')]));
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $code >= 400) {
            $error = $resp === false ? curl_error($ch) : "HTTP $code: " . mb_substr((string) $resp, 0, 300);
            $status = 'failed';
        } else {
            $status = 'sent';
            $ok = true;
        }
        curl_close($ch);
    } else {
        $error = 'Gateway not configured - message queued only.';
    }
    message_log([
        'channel' => $channel, 'recipient' => $phone, 'subject' => null, 'body' => $message, 'status' => $status, 'error' => $error,
        'related_type' => $opts['related_type'] ?? null, 'related_id' => $opts['related_id'] ?? null, 'campaign_id' => $opts['campaign_id'] ?? null,
        'sent_at' => $ok ? date('Y-m-d H:i:s') : null,
    ]);
    return $ok;
}

function send_sms(string $phone, string $message, array $opts = []): bool
{
    return gateway_send('sms', $phone, $message, $opts);
}

function send_whatsapp(string $phone, string $message, array $opts = []): bool
{
    return gateway_send('whatsapp', $phone, $message, $opts);
}
