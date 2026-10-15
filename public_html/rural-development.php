<?php
require_once __DIR__ . '/functions.php';

$page = require_page('rural-development');
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle'], ['Our Work' => 'our-work.php']);
render_program_body('rural-development');

require __DIR__ . '/footer.php';
