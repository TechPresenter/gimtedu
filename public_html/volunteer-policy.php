<?php
require_once __DIR__ . '/functions.php';

$page = require_page('volunteer-policy');
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle'], ['Policies' => null]);
render_content_page($page);

require __DIR__ . '/footer.php';
