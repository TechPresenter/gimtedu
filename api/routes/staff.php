<?php
/**
 * Non-teaching staff endpoints (list/create/update/delete go through /api/crud/staff).
 *   GET  /api/staff/summary                        KPIs + charts for the staff list
 *   GET  /api/staff/{id}/profile                   profile header, stats, account, upcoming leaves, LOP
 *   GET  /api/staff/{id}/attendance?month=YYYY-MM  employee attendance calendar + summary
 *   GET  /api/staff/{id}/activity                  audit trail
 *   GET  /api/staff/{id}/balance                   leave balance (current session)
 *   POST /api/staff/{id}/account                   create login account {username?, role_id?}
 *   POST /api/staff/{id}/status                    {status}
 * Document verification is shared: POST /api/faculty/documents/{id}/verify
 */
require_once APP_ROOT . '/app/services/hr.php';

hr_register_employee_routes('staff');
