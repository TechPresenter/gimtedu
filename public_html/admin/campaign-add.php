<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('campaigns');

$adminTitle = 'New Campaign';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions"><a class="btn btn-ghost btn-sm" href="campaigns.php">&larr; All campaigns</a></div>
<?php campaign_form(null); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
