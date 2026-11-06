<?php
/**
 * Demo seed (WhatsApp team, Module 13): one login for each WhatsApp role that has no demo user yet —
 * Admission Manager, Admission Counsellor, Student Support and Marketing Executive (roles + grants come from
 * 09_wa_0_core). Same pattern as the other demo role users (10_academics_people.php):
 * password Demo@12345, active, no forced password change.
 *
 * DEMO ONLY (never run on a production database). Deterministic and re-runnable: users are upserted by e-mail, so
 * rows that reference them (conversations, notes, activity) survive a re-run; each user's roles are reset to exactly
 * the one WhatsApp role. A role that does not exist is skipped.
 *
 * Logins: admmanager / counsellor / support / marketing (or the e-mail), password Demo@12345.
 */
require_once __DIR__ . '/lib/demo_data.php';

return function (array $opts): void {
    demo_seed(9861);
    $now = date('Y-m-d H:i:s');
    $hash = password_hash('Demo@12345', PASSWORD_DEFAULT);
    $deptMgt = db_value("SELECT id FROM departments WHERE code = 'MGT'") ?: null;
    $adminId = db_value('SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.is_super = 1 ORDER BY u.id LIMIT 1') ?: null;

    $users = [
        // name, username, email, role slug, designation, department
        ['Anjali Verma', 'admmanager', 'admission.manager@gimt.ac.in', 'admission-manager', 'Admission Manager', $deptMgt],
        ['Rahul Saxena', 'counsellor', 'counsellor@gimt.ac.in', 'admission-counsellor', 'Admission Counsellor', $deptMgt],
        ['Priya Nair', 'support', 'support@gimt.ac.in', 'student-support', 'Student Support Executive', null],
        ['Karan Malhotra', 'marketing', 'marketing@gimt.ac.in', 'marketing-executive', 'Marketing Executive', null],
    ];

    foreach ($users as [$name, $username, $email, $slug, $designation, $dept]) {
        $phone = demo_phone(); // drawn for every row so the sequence stays deterministic
        $roleId = db_value('SELECT id FROM roles WHERE slug = ?', [$slug]);
        if (!$roleId) {
            continue;
        }
        $row = [
            'name' => $name, 'username' => $username, 'email' => $email, 'phone' => $phone, 'password_hash' => $hash,
            'designation' => $designation, 'department_id' => $dept, 'status' => 'active', 'failed_attempts' => 0,
            'locked_until' => null, 'must_change_password' => 0, 'password_changed_at' => $now,
        ];
        $id = db_value('SELECT id FROM users WHERE email = ?', [$email]);
        if ($id) {
            if (db_value('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, (int) $id])) {
                unset($row['username']); // never take over another account's username
            }
            db_update('users', $row, 'id = ?', [(int) $id]);
        } elseif (db_value('SELECT id FROM users WHERE username = ?', [$username])) {
            continue; // the username belongs to a different (real) account — leave it alone
        } else {
            $id = db_insert('users', $row + ['created_by' => $adminId]);
        }
        db_delete('user_roles', 'user_id = ?', [(int) $id]);
        db_exec('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [(int) $id, (int) $roleId]);
    }
};
