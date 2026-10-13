<?php
/** Core seed: academic sessions (the current one is 2026-27). */
return function (array $opts): void {
    $sessions = [
        ['2023-24', '2023-07-01', '2024-06-30', 0, 0, 'completed'],
        ['2024-25', '2024-07-01', '2025-06-30', 0, 0, 'completed'],
        ['2025-26', '2025-07-01', '2026-06-30', 0, 0, 'completed'],
        ['2026-27', '2026-07-01', '2027-06-30', 1, 1, 'active'],
        ['2027-28', '2027-07-01', '2028-06-30', 0, 0, 'upcoming'],
    ];
    foreach ($sessions as [$name, $start, $end, $current, $open, $status]) {
        db_exec('INSERT IGNORE INTO academic_sessions (name, start_date, end_date, is_current, admissions_open, status) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $start, $end, $current, $open, $status]);
    }
};
