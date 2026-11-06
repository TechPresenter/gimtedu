<?php
/**
 * Named option sources for selects/comboboxes on custom pages.
 *   GET /api/lookup/{source}?q=...&ids=1,2&program_id=3&limit=30
 * Data exposed is limited to id/label/sub. Access: lookup_allowed() (app/lookups.php) - any admin-panel role for
 * reference data; people / fee sources need a related module permission; portal-only accounts get 403.
 */
route('GET', '/lookup/{source}', function ($p) {
    if (!isset(lookup_sources()[$p['source']])) {
        api_error('Unknown lookup source.', 404);
    }
    if (!lookup_allowed($p['source'])) {
        api_error('You do not have permission to view this list.', 403);
    }
    api_ok(lookup_options($p['source'], $_GET));
});
