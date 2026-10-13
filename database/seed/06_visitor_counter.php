<?php
/** Core seed: visitor counter settings (footer "Unique Visitors / Total Visits"). */
return function (array $opts): void {
    $rows = [
        'visitor_counter_enabled' => '1',
        'visitor_counter_exclude_admins' => '1',
        // Carry-over totals from a previous website (if any). Leave 0 for a new website.
        'visitor_counter_base_unique' => '0',
        'visitor_counter_base_visits' => '0',
    ];
    foreach ($rows as $key => $value) {
        db_exec('INSERT IGNORE INTO settings (`group`, `key`, `value`) VALUES (?, ?, ?)', ['website', $key, $value]);
    }
};
