<?php
/**
 * CSRF protection. Every POST form must include csrf_field() and every
 * handler must call csrf_verify() before doing anything.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && $sent !== '' && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * Stop the request unless it is a POST with a valid token.
 * $back is where the visitor is sent if the token has expired.
 */
function csrf_verify(string $back = 'index.php'): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed');
    }
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'The upload was too large for the server. Please choose a smaller file (maximum ' . upload_limit_label() . ' MB).');
        redirect($back);
    }
    if (!csrf_valid()) {
        flash('error', 'Your session expired or the form was already submitted. Please try again.');
        redirect($back);
    }
}
