<?php
/**
 * Named option sources for selects/comboboxes on custom pages.
 *   GET /api/lookup/{source}?q=...&ids=1,2&program_id=3&limit=30
 * Any authenticated user may call lookups; data exposed is limited to id/label/sub.
 */
route('GET', '/lookup/{source}', function ($p) {
    if (!isset(lookup_sources()[$p['source']])) {
        api_error('Unknown lookup source.', 404);
    }
    api_ok(lookup_options($p['source'], $_GET));
});
