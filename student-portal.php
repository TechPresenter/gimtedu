<?php
/**
 * Legacy URL /student-portal: the Student Portal is live at /portal/student, so old links in menus, bookmarks and search
 * results go straight to its sign-in page. 301 (moved permanently) so search engines replace the old URL; the earlier
 * information page that lived here was retired when the portal went live (Stage C).
 */
require_once __DIR__ . '/app/init.php';
header('X-Robots-Tag: noindex');
header('Location: ' . base_url('portal/student/login'), true, 301);
exit;
