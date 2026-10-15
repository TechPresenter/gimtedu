<?php
/** Sign out (POST with CSRF token from the account menu; GET shows a confirmation button). */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify('/');
    logout_user();
    flash('success', 'You have been signed out.');
    redirect('/login/');
}
if (!is_logged_in()) {
    redirect('/login/');
}
$page = current_page_row();
page_header($page, ['title' => 'Sign out', 'noindex' => true]);
?>
<section class="section"><div class="container narrow text-center">
    <p class="lead">Do you want to sign out of your account?</p>
    <form method="post" action="<?= e(page_url('logout')) ?>"><?= csrf_field() ?>
        <button class="btn btn-primary" type="submit"><?= icon('log-out') ?>Sign out</button>
        <a class="btn btn-ghost" href="<?= e(member_url()) ?>">Cancel</a>
    </form>
</div></section>
<?php
page_footer();
