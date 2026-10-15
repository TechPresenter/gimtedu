<?php
require_once __DIR__ . '/functions.php';

$page = require_page('women-empowerment');
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle'], ['Our Work' => 'our-work.php']);
render_program_body('women-empowerment');

require __DIR__ . '/footer.php';
