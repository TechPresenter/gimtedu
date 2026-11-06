# GIMT Student & Faculty Portals (`/portal/`)

Client request: *"Student Login, Faculty Login ko complete kro"* — the website's **Student Login** and **Faculty Login**
links open real portals with secure sign-in, where each student or teacher sees only their own live ERP data.

Related: [`REPORT.md`](REPORT.md) (overall handover), [`DEPLOYMENT.md`](DEPLOYMENT.md) (installation).

---

## 1. Addresses

| URL | Screen |
|---|---|
| `/portal/` | Choose Student or Faculty portal |
| `/portal/student/login` · `/portal/student/activate` · `/portal/student/forgot` · `/portal/student/reset?selector=&token=` | Student sign-in (Student ID **or** registered email), first-time activation, password recovery |
| `/portal/faculty/login` · `/portal/faculty/forgot` · `/portal/faculty/reset?selector=&token=` | Faculty sign-in (email or username) and recovery |
| `/admin/portal-access` (tabs `?tab=students`, `faculty`, `activity`; **Settings** button) | Admin **Portal Access**: accounts, resets, unlock, sign-in log, portal switches |

The website top bar, mobile menu and footer ("Student Corner") link to `/portal/student/login` and
`/portal/faculty/login`. The old URLs `/student-portal` and `/faculty-portal` redirect there permanently (301). A Student
ID typed into the admin sign-in page shows a link to the Student Portal; a Faculty-role account signing in at `/admin/`
gets a "Your account uses the Faculty Portal" screen with a link.

---

## 2. Student Portal — features

| Menu | What the student can do |
|---|---|
| Dashboard | Attendance %, CGPA, fee due, upcoming exams, today's classes, exam schedule, latest notices, attendance summary |
| Timetable | Weekly timetable of their section (published, current session) |
| Attendance | Overall and subject-wise %, monthly calendar, trend, absences |
| Examinations | Date sheets, seat allocation, **admit card** print (when available; admit cards can be blocked for overdue fees by the institute's settings) |
| Results | Published results, subject-wise marks, SGPA / CGPA trend, **marksheet** print |
| Fees & Payments | Totals, invoices with instalments, payment history, scholarships / concessions, **invoice** and **receipt** prints, bank / UPI details for direct payment (System Settings › Payment › Bank & UPI details; hidden while blank). Online payment from the portal is not switched on in this release |
| Certificates | Issued certificates with their verification link; request a new certificate (goes to the certificates office for approval) |
| My Documents | Own uploaded documents with verification status (secure download) |
| Library | Membership, current loans, history, fines |
| Hostel | Allotment, roommates (first names), complaints (raise and track) |
| Transport | Route, stop, timings, vehicle and driver contact |
| Placements | Eligible open drives, apply / withdraw, application rounds, offers and offer letters |
| Grievances & Feedback | Raise tickets (optionally anonymous), follow replies, rate, reopen |
| Notices | Notices for students / their department, course or semester |
| Profile | Personal and academic details; request a correction (sent to the administration as a ticket) |
| Change password | Own password (policy from System Settings › Security) |

## 3. Faculty Portal — features

| Menu | What the teacher can do |
|---|---|
| Dashboard | Today's schedule, attendance registers still to mark, papers awaiting marks, leave balance, notices, quick actions |
| My Timetable | Weekly timetable with dates, holidays and register status |
| My Classes | Each section + subject taught, roster (contacts masked), attendance summary, last registers |
| Attendance | Mark or correct the register of **their own** periods — up to `attendance_edit_days` days back (System Settings › Academic › Attendance rules, default 7; future dates and locked registers are refused); own employee attendance calendar |
| Marks Entry | Enter internal / external / practical marks for **their own** papers and sections, from the exam date until the results are verified or published (then read-only) |
| Leave & Attendance | Leave balance, apply (with a working-day preview), cancel, history |
| My Documents | Personnel documents; upload new ones for HR verification |
| Payroll | Salary reference (monthly / annual), employment details, paid / unpaid leave this session, bank account and PAN (masked) — the ERP keeps a payroll reference, not payslips |
| Notices | Notices for faculty |
| Profile | Details; request a correction (sent to the HR office) |
| Change password | Own password |

Both portals: light / dark / system theme saved per account, desktop sidebar, phone bottom tab bar with a "More" sheet,
idle-timeout warning, printable documents.

---

## 4. Accounts

### 4.1 Students
1. **Self-activation** (setting `portal_self_activation`, on by default): Student ID + date of birth + registered email
   *or* mobile → 15-minute ticket → choose a password → signed in. Already-active accounts get "use Forgot password".
   It does not use an OTP, so GIMT should switch it off after the onboarding period (Admin › Portal Access › Settings ›
   Self-service activation).
2. **Generated by an administrator** (Portal Access › Students › Generate, or Bulk generate by program · semester ·
   section): the temporary password is shown **once** (copy / CSV download) and optionally emailed; the student must
   change it at first sign-in.
3. **Forgot password:** Student ID or email → one-time link (valid 60 minutes) to the registered email. In `dev` mode the
   mail is written to `storage/logs/mail.log`.
4. Administrators can **reset** (new temporary password), **disable / enable** (signs the student out) and **unlock**.

Allowed student statuses: `active`, `graduated`, `alumni`.

### 4.2 Faculty
Faculty use normal `users` rows with the **Faculty** role (permission module `faculty_portal`: view = sign in,
create = mark own attendance, edit = enter own marks) linked to an active / on-leave faculty profile. Portal Access ›
Faculty: **Create login** (temporary password, optional email), **Link** an existing user, **Repair link**,
**Reset password**, **Disable / enable**, **Unlock**. Forgot-password emails link to `/portal/faculty/reset`.

### 4.3 Password changes sign out other devices
Changing or resetting a password (by the user, through a reset link or by an administrator) signs that account out of
every other browser on its next request, and remember-me cookies issued before the change stop working. The browser that
made the change stays signed in.

---

## 5. Fee-receipt verification

Every printed fee receipt (admin or Student Portal) carries a QR code linking to the public page
`/api/fees/verify?no={receipt}&h={signature}`. It needs no login, is not indexed and allows 30 checks per 10 minutes per
IP. It shows **Genuine receipt / Refunded / Cancelled** with the date, amount, payment mode, the student's first name with
last initial and a partly masked Student ID. The signature is an HMAC with `app.key` — changing the key invalidates the
QR codes already printed. Certificates and marksheets have their own public checks (`/verify-certificate/{no}`,
`/verify-marksheet/{no}?code=`).

---

## 6. Settings

| Setting | Where | Default |
|---|---|---|
| `portal_student_enabled`, `portal_faculty_enabled` | Admin › Portal Access › Settings | on (a switched-off portal answers 503 with a friendly message) |
| `portal_self_activation` | Admin › Portal Access › Settings | on |
| `session_timeout`, `max_login_attempts`, `lockout_minutes`, `max_ip_attempts`, `remember_me_days`, `password_min_length`, `password_require_*`, `password_expiry_days` | System Settings › Security (shared with the admin panel) | 30 min, 5, 15 min, 20, 30 days, 8, policy on, 0 (no expiry) |
| `attendance_edit_days` | System Settings › Academic › Attendance rules | 7 |
| Bank & UPI details | System Settings › Payment › Bank & UPI details | blank (hidden) |

---

## 7. Demo logins (demo data only)

> **Demo only — password `Demo@12345` for every account below.** Never load the demo data on the production database,
> and remove or reset demo accounts on any server that is reachable from the internet. Re-running
> `php tools/seed.php 97_portal_demo` restores these accounts (it recreates every student account with the demo password).

| Portal | Login | Who / what it shows |
|---|---|---|
| Student | `GIMT25BBA0002` | Navya Nair — BBA Sem 3, results and CGPA trend, no dues |
| Student | `GIMT23BTAIDS0001` | Manish Mehta — B.Tech (AI & DS) Sem 7, ₹97,500 outstanding of which the first instalment (₹48,750) is overdue |
| Student | `GIMT26BBA0029` | Vivek Sen — BBA Sem 1, ₹86,000 overdue (semester instalment and hostel fee) |
| Student | `GIMT26BBA0001` | Harsh Chopra — BBA Sem 1, all dues cleared |
| Student activation | `GIMTX001249`, date of birth `17/03/2006`, email `kriti.verma1249@gmail.com` | A graduated demo student without an account — try **Activate account** |
| Faculty | `faculty@gimt.ac.in` (or `faculty`) | Dr. Sakshi Garg — Associate Professor, CSE; marks entry open (B.Tech CSE mid-term exams) |
| Faculty | `nandini.gupta5@gimt.ac.in` (or `nandini.gupta5`) | Dr. Nandini Gupta — Associate Professor, Management; classes, attendance, leave |
| Faculty | `mansi.yadav1@gimt.ac.in` | Dr. Mansi Yadav — Professor & Head, Management |
| Faculty | `gaurav.reddy6@gimt.ac.in` | Dr. Gaurav Reddy — Assistant Professor, Management |

Every active demo student (about 1,250) has an account with the demo password; about ten faculty members have logins.
Demo dates are anchored on the day the seeders ran, so counters such as "registers to mark" grow until the attendance
seeder is re-run.

---

## 8. Architecture (for developers)

- **SPA:** React 18 + TS + Tailwind in `frontend/portal/` (separate from the admin SPA and the website islands). It reuses
  the admin UI kit through the `@/…` alias (`@/components/ui`, `@/lib/api`, `@/lib/format`, `@/lib/theme`); portal-only
  pieces live in `frontend/portal/components` and `frontend/portal/lib`. Look: website **Design v3** (navy `#0B2A5B`,
  orange-red `#F05A28` / fills `#D03E16`, warm greys), Plus Jakarta Sans + Inter. `frontend/portal/tailwind.config.cjs`
  re-tunes the `slate` scale to warm greys / deep navy so the shared kit matches.
- **Build:** `cd frontend && npm run build:portal` → `portal/build` (manifest read by `portal/index.php`; the build is
  committed). Types: `npm run typecheck:portal`. Dev: `npm run dev:portal` → `http://127.0.0.1:5175/portal/` (HMR,
  proxies `/api` + `/assets` to :8000).
- **Shell & routing:** `portal/index.php` (nonce CSP, `noindex`, boot config) + `portal/.htaccess`; `tools/router.php`
  and the root `.htaccess` send every `/portal/*` route to it.
- **API:** `api/routes/portal.php` (`/api/portal/...`: sign-in, session, dashboard, profile, notices) +
  `app/services/portal_auth.php`; area routes in `api/routes/portal/student.php` and `api/routes/portal/faculty.php`
  (their header comments list every endpoint); data helpers in `app/services/portal_student.php` and
  `app/services/portal_faculty.php`. Admin endpoints: `api/routes/portal-access.php` (`/api/portal-access/...`, every route
  `api_require('users', 'manage')`).
- **Schema:** `database/schema/45_portal.sql` — `student_accounts`, `student_account_tokens` (remember-me),
  `student_login_logs`. Faculty use the `users` table (`users.faculty_id` ↔ `faculty.user_id`).
- **Demo data:** `database/seed/97_portal_demo.php` (re-runnable: `php tools/seed.php 97_portal_demo`).

### Security model

| Control | Students | Faculty |
|---|---|---|
| Identity | `student_accounts` (username = Student ID; sign in with Student ID **or** registered email) | `users` row with the Faculty role linked to an `active` / `on_leave` faculty profile (email or username) |
| Session | `$_SESSION['portal_student']` only — never sets `user_id`, so `require_login()`, `can()`, `/api/crud/*`, `/api/lookup/*` and every admin route reject a student | normal session; portal endpoints derive `faculty_id` from the signed-in user; the Faculty role has only `faculty_portal` permissions (admin APIs and lookups answer 403) |
| One identity per browser | a student sign-in clears the PHP session (and admin remember-me); a later admin / faculty sign-in ends the student session | faculty sign-in drops any student session |
| Passwords | `password_hash` / `password_verify`, policy from System Settings › Security | same policy (`/api/auth/password`) |
| Lockout | `max_login_attempts` failures → locked for `lockout_minutes` (423); admin can unlock | users lockout |
| Rate limits | 40 sign-ins / 10 min / IP, `max_ip_attempts` failures per IP, activation 10 / 15 min / IP + 5 / h / Student ID, forgot 5 / 15 min, reset 10 / 15 min; writes throttled per student | admin limits + forgot 5 / 15 min |
| Uniform answers | sign-in gives the same 422 message for a real, unknown or graduated ID; forgot always answers 200 with a neutral message | same |
| Idle timeout | `session_timeout` minutes: server expiry + client warning 60 s before, keep-alive while active; session ids rotate with a grace window so parallel requests never sign the user out | same |
| Remember me | optional; selector / validator token, validator stored as SHA-256, rotated on every use, `HttpOnly` `SameSite=Lax` cookie `gimt_portal_remember` | admin `gimt_remember` |
| Password change / reset | signs out every other device (§4.3) | same |
| CSRF | every non-GET needs `X-CSRF-Token` (token from the session endpoints) → 419 | same |
| Temporary passwords | admin-issued → `must_change_password = 1`; data endpoints answer 403 `{code: password_change_required}` until changed; the SPA shows a "Set your own password" screen | same |
| Scoping (no IDOR) | every query uses the signed-in `student_id`; ids in the URL are re-checked and answer 404 when not theirs | everything from the signed-in `faculty_id`; class-level actions must pass `portal_faculty_teaches()` (403); other teachers' leave / documents 404 |
| Audit | `student_login_logs` (success, failed, locked, blocked, logout, activated, reset) + `activity_logs` module `portal` | `login_logs` + `activity_logs` |

Status codes: 200 ok · 401 signed out / expired · 403 not allowed / disabled / temporary password · 404 not yours / not
found · 409 already activated · 410 expired link or activation ticket · 419 CSRF · 422 validation · 423 locked (account,
register or published marks) · 429 throttled · 503 portal switched off.

### API (sign-in and shared endpoints)

Student (`['auth' => false]` + `portal_student_require()`):
`GET portal/student/session` · `POST portal/student/login {identifier,password,remember}` · `POST …/logout` ·
`POST …/activate/verify {student_uid,dob,contact}` · `POST …/activate {password,password_confirmation}` ·
`POST …/forgot {identifier}` · `GET …/reset/{selector}?token=` · `POST …/reset {selector,token,password,password_confirmation}` ·
`POST …/password {current_password,password,password_confirmation}` · `POST …/ping` · `POST …/prefs {theme}` ·
`GET …/dashboard` · `GET …/profile` · `GET …/notices?q&category&page&limit` · `GET …/notices/{id}`.
Area endpoints (timetable, attendance, exams, results, fees, library, hostel, transport, certificates, placement,
documents, tickets, profile correction, prints): see the header of `api/routes/portal/student.php`.

Faculty (`portal_faculty_require()`): `GET portal/faculty/session` · `POST …/login` · `POST …/logout` ·
`POST …/forgot {email}` · `GET …/dashboard` · `GET …/profile` · `GET …/notices` · `GET …/notices/{id}`; password change /
reset / ping / theme reuse `/api/auth/password`, `/api/auth/reset`, `/api/auth/ping`, `/api/auth/prefs`. Area endpoints
(timetable, classes, attendance, marks, leave, documents, payroll, profile corrections): see the header of
`api/routes/portal/faculty.php`.

Admin (`users.manage`): `GET portal-access/summary` · `GET portal-access/students?q&program_id&semester&section_id&student_status&account&sort&dir&page&per_page` ·
`POST portal-access/students/{id}/generate|reset {send_email}` · `POST …/students/{id}/status {status}` · `POST …/students/{id}/unlock` ·
`POST portal-access/students/bulk {action: generate|enable|disable|unlock, ids[]}` · `GET …/students/bulk-preview` · `POST …/students/bulk-generate {program_id,semester?,section_id?,send_email}` ·
`GET portal-access/faculty?q&department_id&login&sort&dir&page` · `POST …/faculty/{id}/create {username?,send_email}` · `POST …/faculty/{id}/link {user_id}` ·
`POST …/faculty/{id}/reset|status|unlock` · `GET portal-access/users?q=` · `GET portal-access/logins?type&status&q&from&to&page` ·
`GET|POST portal-access/settings {portal_student_enabled, portal_faculty_enabled, portal_self_activation}`.

### Extending the portals

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
`portal_day_info($date)`, `portal_notices($for, $scope, …)`. Read request values with `api_str()` / `api_query()`.

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
`mobile: true` entries by `order`) and the "More" sheet are generated from `nav`. Existing modules — student:
attendance, certificates, documents, exams, fees, helpdesk, hostel, library, placement, results, timetable, transport;
faculty: attendance, classes, documents, leave, marks, payroll, timetable.
