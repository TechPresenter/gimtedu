<?php
/** Admin layout footer. */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}
unset($_SESSION['_old'], $_SESSION['_errors']);
?>
        </div>
        <footer class="admin-footer">&copy; <?= date('Y') ?> <?= e(site_name()) ?> &middot; Admin Panel</footer>
    </div>
</div>
<script src="<?= e(asset('assets/js/form-validation.js')) ?>"></script>
<script src="<?= e(asset('assets/js/admin.js')) ?>"></script>
</body>
</html>
