<?php
/**
 * Accounts & member dashboard module (M5).
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Sidebar navigation for the member area. */
function render_member_nav(array $user): void
{
    $items = [
        'member-dashboard.php' => ['grid', 'Dashboard'],
        'my-donations.php' => ['heart', 'My Donations'],
        'my-applications.php' => ['file-text', 'My Applications'],
        'my-certificates.php' => ['award', 'My Certificates'],
        'member-profile.php' => ['user', 'Profile & Password'],
    ];
    $current = current_page();
    echo '<aside class="member-nav"><div class="member-user"><span class="avatar">' . e(mb_strtoupper(mb_substr($user['name'], 0, 1))) . '</span>'
        . '<div><strong>' . e($user['name']) . '</strong><small>' . e($user['email']) . '</small></div></div><ul>';
    foreach ($items as $file => [$ic, $label]) {
        echo '<li><a class="' . ($current === $file ? 'active' : '') . '" href="' . e(url($file)) . '">' . icon($ic) . e($label) . '</a></li>';
    }
    echo '<li><a href="' . e(url('logout.php?token=' . csrf_token())) . '">' . icon('log-out') . 'Logout</a></li></ul></aside>';
}
