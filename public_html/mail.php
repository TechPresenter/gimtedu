<?php
/**
 * Email sending without external libraries.
 * Uses SMTP (with SSL/TLS + AUTH LOGIN) when SMTP_HOST is set in config.php,
 * otherwise PHP's built-in mail() function.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Wrap content in the branded HTML email layout. */
function mail_layout(string $subject, string $bodyHtml): string
{
    $name = e(site_name());
    $address = e(setting('contact_address'));
    $email = e(setting('contact_email'));
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . e($subject) . '</title></head>'
        . '<body style="margin:0;background:#f3f6f4;font-family:Arial,Helvetica,sans-serif;color:#1f2a24">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6f4;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden">'
        . '<tr><td style="background:linear-gradient(90deg,#c9a227,#1e7f62,#1b6f9a);background-color:#1a7f46;padding:20px 28px;color:#ffffff;font-size:20px;font-weight:bold">' . $name . '</td></tr>'
        . '<tr><td style="padding:28px;font-size:15px;line-height:1.6">'
        . str_replace('class="btn"', 'style="display:inline-block;background:#f5b700;color:#1f2a24;padding:12px 22px;border-radius:30px;text-decoration:none;font-weight:bold"', $bodyHtml)
        . '</td></tr>'
        . '<tr><td style="padding:18px 28px;background:#f7faf8;font-size:12px;color:#5b6b62">' . $name . ' &middot; ' . $address . '<br>' . $email
        . '<br>This is an automated message. Please do not share your password or OTP with anyone.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Send an email. Returns true on success. Failures are logged, never shown
 * to visitors, so a mail problem never breaks a form submission.
 */
function send_mail(string $to, string $subject, string $bodyHtml, ?string $replyTo = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
    $html = mail_layout($subject, $bodyHtml);

    if (!MAIL_ENABLED) {
        error_log('[mail disabled] To: ' . $to . ' | Subject: ' . $subject);
        return false;
    }

    try {
        return SMTP_HOST !== ''
            ? smtp_send($to, $subject, $html, $replyTo)
            : php_mail_send($to, $subject, $html, $replyTo);
    } catch (Throwable $e) {
        error_log('Mail error: ' . $e->getMessage());
        return false;
    }
}

function mail_headers_common(?string $replyTo): array
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'From: =?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?= <' . MAIL_FROM . '>',
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost') . '>',
    ];
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    return $headers;
}

function php_mail_send(string $to, string $subject, string $html, ?string $replyTo): bool
{
    $headers = mail_headers_common($replyTo);
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return mail($to, $encodedSubject, chunk_split(base64_encode($html)), implode("\r\n", $headers), '-f' . MAIL_FROM);
}

function smtp_send(string $to, string $subject, string $html, ?string $replyTo): bool
{
    $host = (SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : '') . SMTP_HOST;
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $socket = @stream_socket_client($host . ':' . SMTP_PORT, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    }
    stream_set_timeout($socket, 15);

    $read = function () use ($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    };
    $cmd = function (string $command, array $expect) use ($socket, $read): string {
        fwrite($socket, $command . "\r\n");
        $response = $read();
        if (!in_array((int) substr($response, 0, 3), $expect, true)) {
            throw new RuntimeException('SMTP error after "' . strtok($command, ' ') . '": ' . trim($response));
        }
        return $response;
    };

    $read();
    $ehloHost = parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost';
    $cmd('EHLO ' . $ehloHost, [250]);
    if (SMTP_ENCRYPTION === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('SMTP STARTTLS failed');
        }
        $cmd('EHLO ' . $ehloHost, [250]);
    }
    if (SMTP_USER !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode(SMTP_USER), [334]);
        $cmd(base64_encode(SMTP_PASS), [235]);
    }
    $cmd('MAIL FROM:<' . MAIL_FROM . '>', [250]);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', [354]);

    $headers = mail_headers_common($replyTo);
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $message = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n");
    $cmd($message . "\r\n.", [250]);
    $cmd('QUIT', [221]);
    fclose($socket);
    return true;
}

/** Notify the foundation's inbox about a new submission. */
function notify_admin(string $subject, string $bodyHtml, ?string $replyTo = null): void
{
    $to = setting('admin_notify_email', setting('contact_email'));
    if ($to) {
        $link = '<p><a class="btn" href="' . e(abs_url('admin/dashboard.php')) . '">Open admin panel</a></p>';
        send_mail($to, '[Website] ' . $subject, $bodyHtml . $link, $replyTo);
    }
}

/** Acknowledgement sent to applicants with their reference number. */
function send_application_ack(string $to, string $name, string $type, string $refNo): void
{
    $body = '<p>Dear ' . e($name) . ',</p>'
        . '<p>Thank you for your ' . e($type) . ' submitted to ' . e(site_name()) . '. We have received it and our team will review it.</p>'
        . '<p>Your reference number is: <strong style="font-size:18px">' . e($refNo) . '</strong></p>'
        . '<p>You can check the status at any time using this number:</p>'
        . '<p><a class="btn" href="' . e(abs_url('application-status.php')) . '">Check application status</a></p>'
        . '<p>Regards,<br>' . e(site_name()) . '</p>';
    send_mail($to, $type . ' received - ' . $refNo, $body);
}
