<?php
require_once __DIR__ . '/includes/functions.php';

admin_redirect(current_admin() ? 'dashboard.php' : 'login.php');
