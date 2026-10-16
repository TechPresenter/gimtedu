<?php
/**
 * Programs & Events - admin helpers (owned by builder P2).
 *
 * Loaded from the bottom of includes/programhub.php on every request (public and admin), so keep it to
 * function / const definitions only - no output, no queries at include time. Functions that need the admin
 * session (admin_id(), admin_log(), admin_upload_image()) may only be CALLED from admin pages.
 * Name every function programhub_admin_*() to avoid clashes with P1's public functions.
 * See scratchpad v3/programs/CONTRACT.md.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}
