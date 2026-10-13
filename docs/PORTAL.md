# GIMT Student & Faculty Portal (`/portal/`)

Client request: *"Student Login, Faculty Login ko complete kro"* — the website's **Student Login** and **Faculty Login**
links open real portals with secure sign-in and each user's own live ERP data.

| URL | Screen |
|-----|--------|
| `/portal/` | Choose Student or Faculty portal |
| `/portal/student/login` · `/activate` · `/forgot` · `/reset?selector=&token=` | Student sign-in, first-time activation, password recovery |
| `/portal/student` · `/notices` · `/profile` · `/change-password` | Student dashboard (+ screens added by later units) |
| `/portal/faculty/login` · `/forgot` · `/reset?selector=&token=` | Faculty sign-in and recovery |
| `/portal/faculty` · `/notices` · `/profile` · `/change-password` | Faculty dashboard (+ screens added by later units) |
| `/admin/portal-access` (tabs `?tab=students|faculty|activity`) | Admin **Portal Access**: accounts, resets, unlock, sign-in log, portal switches |

The website header/top bar and mobile drawer link to `/portal/student/login` and `/portal/faculty/login`.

## Architecture
- **SPA**: React 18 + TS + Tailwind in `frontend/portal/` (separate from the admin SPA and the website islands). It
  reuses the admin UI kit through the `@/…` alias (`@/components/ui`, `@/lib/api`, `@/lib/format`, `@/lib/theme`);
  portal-only pieces live in `frontend/portal/components` and `frontend/portal/lib`.
  Look: website **Design v3** (navy `#0B2A5B`, orange-red `#F05A28` / fills `#D03E16`, warm greys), Plus Jakarta Sans +
  Inter, light / dark / system theme (saved per account), sidebar on desktop, bottom tab bar + "More" sheet on phones.
  `frontend/portal/tailwind.config.cjs` re-tunes the `slate` scale to warm greys / deep navy so the shared kit matches.
- **Build**: `cd frontend && npm run build:portal` → `portal/build` (manifest read by `portal/index.php`). Writes only
  `portal/build`. Types: `npm run typecheck:portal`. Dev: `npm run dev:portal` → `http://127.0.0.1:5175/portal/`
  (HMR, proxies `/api` + `/assets` to :8000).
- **Shell & routing**: `portal/index.php` (CSP nonce, noindex, boot config) + `portal/.htaccess`; `tools/router.php`
  and the root `.htaccess` send every `/portal/*` route to it.
- **API**: `api/routes/portal.php` (`/api/portal/...`) + `app/services/portal_auth.php`; area routes added by later
  units go in `api/routes/portal/student.php` and `api/routes/portal/faculty.php` (required by `portal.php`).
  Admin endpoints: `api/routes/portal-access.php` (`/api/portal-access/...`, every route `api_require('users','manage')`).
- **Schema**: `database/schema/45_portal.sql` — `student_accounts`, `student_account_tokens` (remember-me),
  `student_login_logs`. Faculty use the existing `users` table (`users.faculty_id` ↔ `faculty.user_id`).
- **Demo data**: `database/seed/97_portal_demo.php` (re-runnable: `php tools/seed.php 97_portal_demo`).

## Security model
| Control | Students | Faculty |
|---|---|---|
| Identity | `student_accounts` (username = Student ID; sign in with Student ID **or** registered email) | `users` row linked to an `active`/`on_leave` faculty profile (email or username) |
| Session | `$_SESSION['portal_student']` only — **never** sets `user_id`, so `require_login()`, `can()`, `/api/crud/*` and every admin route reject a student | normal admin session (`attempt_login`); portal endpoints derive `faculty_id` from the signed-in user |
| One identity per browser | a student sign-in wipes the PHP session (and admin remember-me); a later admin/faculty sign-in ends the student session | faculty sign-in drops any student session |
| Passwords | `password_hash` / `password_verify`, policy from Settings › Security (`password_min_length`, upper / number / special) | same policy (`/api/auth/password`) |
| Lockout | `max_login_attempts` failures → locked for `lockout_minutes` (423); admin can unlock | existing users lockout |
| Rate limits | 40 sign-ins / 10 min / IP, `max_ip_attempts` failures per IP, activation 10 / 15 min / IP + 5 / h / Student ID, forgot 5 / 15 min, reset 10 / 15 min | admin limits + `forgot:` 5 / 15 min |
| Idle timeout | `session_timeout` (minutes): server expiry + client warning 60 s before, keep-alive ping while active | same |
| Remember me | optional; selector/validator token, validator stored SHA-256, rotated on every use, httpOnly SameSite=Lax cookie `gimt_portal_remember` | admin `gimt_remember` |
| Password change / reset | signs out every other device (password-version check in the session) | admin remember tokens cleared |
| CSRF | every non-GET needs `X-CSRF-Token` (token from the session endpoints) → 419 | same |
| Temporary passwords | admin-issued → `must_change_password=1`; every data endpoint answers 403 `{code: password_change_required}` until changed; the SPA shows a forced "Set your own password" screen | same |
| Scoping (no IDOR) | every query uses `$me['student_id']`; ids from the request are never trusted; foreign notice ids → 404 | everything from `$me['faculty_id']`; class-level actions must pass `portal_faculty_teaches()` |
| Audit | `student_login_logs` (success, failed, locked, blocked, logout, activated, reset) + `activity_logs` module `portal` | `login_logs` + `activity_logs` |

## Student account lifecycle
1. **Self activation** (`portal_self_activation` on): Student ID + date of birth + registered email *or* mobile →
   15-minute ticket → choose password → signed in. Already-active accounts get 409 (use Forgot password).
2. **Admin generated** (Portal Access › Students › Generate / Bulk generate by program · semester · section):
   temporary password shown **once** (copy / CSV download) and optionally emailed; must be changed at first sign-in.
3. **Forgot password**: Student ID or email → one-time link (60 min) to the registered email. Dev: mails are written to
   `storage/logs/mail.log` (the link is included as plain text).
4. Admin can **reset** (new temporary password), **disable / enable** (signs the student out), **unlock**.
Allowed student statuses: `active`, `graduated`, `alumni`.

## Faculty logins
Portal Access › Faculty: **Create login** (users row with the Faculty role, temporary password, optional email),
**Link** an existing user, **Repair link**, **Reset password**, **Disable / enable**, **Unlock**. Forgot-password emails
link to `/portal/faculty/reset`, validated by the shared `/api/auth/reset` endpoints.

## API
Student (`['auth' => false]` + `portal_student_require()`):
`GET portal/student/session` · `POST portal/student/login {identifier,password,remember}` · `POST …/logout` ·
`POST …/activate/verify {student_uid,dob,contact}` · `POST …/activate {password,password_confirmation}` ·
`POST …/forgot {identifier}` · `GET …/reset/{selector}?token=` · `POST …/reset {selector,token,password,password_confirmation}` ·
`POST …/password {current_password,password,password_confirmation}` · `POST …/ping` · `POST …/prefs {theme}` ·
`GET …/dashboard` · `GET …/profile` · `GET …/notices?q&category&page&limit` · `GET …/notices/{id}`.

Faculty (`portal_faculty_require()`): `GET portal/faculty/session` · `POST …/login` · `POST …/logout` ·
`POST …/forgot {email}` · `GET …/dashboard` · `GET …/profile` · `GET …/notices` · `GET …/notices/{id}`;
password change / reset / ping / theme reuse `/api/auth/password`, `/api/auth/reset`, `/api/auth/ping`, `/api/auth/prefs`.

Admin (`users.manage`): `GET portal-access/summary` · `GET portal-access/students?q&program_id&semester&section_id&student_status&account&sort&dir&page&per_page` ·
`POST portal-access/students/{id}/generate|reset {send_email}` · `POST …/students/{id}/status {status}` · `POST …/students/{id}/unlock` ·
`POST portal-access/students/bulk {action: generate|enable|disable|unlock, ids[]}` · `GET …/students/bulk-preview` · `POST …/students/bulk-generate {program_id,semester?,section_id?,send_email}` ·
`GET portal-access/faculty?q&department_id&login&sort&dir&page` · `POST …/faculty/{id}/create {username?,send_email}` · `POST …/faculty/{id}/link {user_id}` ·
`POST …/faculty/{id}/reset|status|unlock` · `GET portal-access/users?q=` · `GET portal-access/logins?type&status&q&from&to&page` ·
`GET|POST portal-access/settings {portal_student_enabled, portal_faculty_enabled, portal_self_activation}`.

Status codes: 200 ok · 401 signed out / expired · 403 not allowed / disabled / temporary password · 404 not yours /
not found · 409 already activated · 410 expired link or activation ticket · 419 CSRF · 422 validation · 423 locked ·
429 throttled · 503 portal switched off.

## Extending the portals (next units)
**Backend** — add routes to `api/routes/portal/student.php` or `api/routes/portal/faculty.php`:
```php
route('GET', '/portal/student/attendance', function () {
    $me = portal_student_require();              // 401 / 403 handled
    api_ok(students_attendance_stats($me['student_id'], current_session_id()));   // ALWAYS scope by $me
}, ['auth' => false]);

route('POST', '/portal/faculty/attendance', function () {
    $me = portal_faculty_require();
    $in = api_input();
    if (!portal_faculty_teaches($me['faculty_id'], (int) $in['subject_id'], (int) $in['section_id'])) api_error('Not your class.', 403);
    // ...
}, ['auth' => false]);                            // non-GET keeps the CSRF check
```
Helpers: `portal_student_require()` → `['student_id','account_id','student' => profile row]`;
`portal_faculty_require()` → `['faculty_id','user_id','faculty','user']`; `portal_faculty_periods($fid)`,
`portal_day_info($date)`, `portal_notices($for, $scope, …)`.

**Frontend** — create `frontend/portal/student/modules/<name>/routes.tsx` (or `faculty/modules/<name>/routes.tsx`); it is
discovered automatically (no shared-file edits):
```tsx
import { lazy } from 'react';
import { CalendarCheck } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';
const AttendancePage = lazy(() => import('./AttendancePage'));
export const routes: PortalRoute[] = [
  { path: 'attendance', element: <AttendancePage />, nav: { label: 'Attendance', icon: CalendarCheck, order: 20, group: 'Academics', mobile: true } },
];
```
Inside pages: `usePortalQuery<T>('student', ['attendance'], 'attendance')` (GET `/api/portal/student/attendance`),
`useStudentSession()` / `useFacultySession()`, `PageHead`, `Section`, `KpiCard`, `Ring`, `EmptyBlock`, `ErrorState`,
`InfoGrid`, `PortalAvatar` (`frontend/portal/components/Ui.tsx`) plus the shared kit. Sidebar, mobile tabs (first four
`mobile: true` entries by `order`) and the "More" sheet are generated from `nav`. Dashboard KPI cards link to
`attendance`, `results`, `fees`, `exams`, `timetable` (student) and `timetable`, `attendance`, `marks`, `leave` (faculty)
automatically once routes with those paths exist. Suggested `order`: timetable 10, attendance 20, results/marks 30,
exams 40, fees/leave 50, library/hostel/transport/placement 60–90 (group "Campus"), notices 700, account 900+.

## Settings (`settings` table, group `portal`)
`portal_student_enabled`, `portal_faculty_enabled`, `portal_self_activation` (1/0, Portal Access › Settings). Shared with
the admin panel: `session_timeout`, `max_login_attempts`, `lockout_minutes`, `max_ip_attempts`, `remember_me_days`,
`password_*`.

## Demo logins (demo data only — password **`Demo@12345`** for all)
| Portal | Login | Who |
|---|---|---|
| Student | `GIMT25BBA0002` | Navya Nair — BBA Sem 3, Sec B (results / CGPA) |
| Student | `GIMT26BBA0001` | Harsh Chopra — BBA Sem 1, Sec A (fee due) |
| Student | `GIMT23BTAIDS0001` | Manish Mehta — B.Tech (AI & DS) Sem 7 |
| Faculty | `nandini.gupta5@gimt.ac.in` (or `nandini.gupta5`) | Dr. Nandini Gupta — Associate Professor, Management |
| Faculty | `mansi.yadav1@gimt.ac.in` | Dr. Mansi Yadav — Professor & Head |
| Faculty | `gaurav.reddy6@gimt.ac.in` | Dr. Gaurav Reddy — Assistant Professor |

Every active student has an account (seeded with the demo password). Graduated demo students have none — try
**Activate account** with `GIMTX001249`, date of birth `17/03/2006`, email `kriti.verma1249@gmail.com`.
Re-running `php tools/seed.php 97_portal_demo` restores all of the above.
