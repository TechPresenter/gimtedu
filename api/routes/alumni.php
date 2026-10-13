<?php
/**
 * Alumni relations (brief §23) — custom endpoints. Plain CRUD lives in /api/crud/{alumni|alumni_events|alumni_jobs|
 * alumni_success_stories|alumni_donations|alumni_mentorships}.
 *
 *   GET    /alumni/dashboard                          directory KPIs + batch / industry / company / city breakdowns
 *   GET    /alumni/import-candidates                  passed-out students (graduated|alumni) not yet in the directory
 *   POST   /alumni/import-students                    {student_ids: []}  create alumni profiles (skips existing, links e-mail matches)
 *   GET    /alumni/{id}/profile                       profile + engagement (events, donations, mentorships, jobs, stories, timeline)
 *   GET    /alumni/mentorships/stats                  mentorship KPIs, focus areas, top mentors
 *   GET    /alumni/events/stats                       event KPIs + next upcoming events
 *   GET    /alumni/events/{id}                        event detail + registrations
 *   POST   /alumni/events/{id}/registrations          {alumni_ids: [], guests?}  register alumni
 *   POST   /alumni/events/{id}/status                 {status: upcoming|completed|cancelled}
 *   POST   /alumni/registrations/{id}                 {status: registered|attended|no_show|cancelled}
 *   DELETE /alumni/registrations/{id}
 *   GET    /alumni/jobs/stats                         job KPIs + approval queue
 *   POST   /alumni/jobs/{id}/{approve|reject|close|reopen}   {reason} for reject
 *   POST   /alumni/jobs/close-expired                 close open posts whose last date has passed
 *   GET    /alumni/stories/stats                      story KPIs + featured stories
 *   POST   /alumni/stories/{id}/publish               {publish: bool}   publish to / remove from the website
 *   POST   /alumni/stories/{id}/feature               {featured: bool}
 *   GET    /alumni/donations/stats                    totals by financial year / purpose / mode, top donors, campaigns
 *   POST   /alumni/donations/{id}/status              {status: received|cancelled, payment_mode, reference_no, donation_date | reason}
 */

require_once APP_ROOT . '/app/services/alumni.php';

route('GET', '/alumni/dashboard', function () {
    api_require('alumni', 'view');
    api_ok(alumni_dashboard());
});

route('GET', '/alumni/import-candidates', function () {
    api_require('alumni', 'import');
    api_ok(alumni_import_candidates());
});

route('POST', '/alumni/import-students', function () {
    api_require('alumni', 'import');
    $ids = api_param('student_ids', []);
    if (!is_array($ids) || !$ids) {
        api_error('Select at least one passed-out student to import.', 422, ['student_ids' => 'Select at least one student.']);
    }
    $res = alumni_import_students($ids);
    $parts = [];
    if ($res['inserted']) {
        $parts[] = $res['inserted'] . ' alumni profile' . ($res['inserted'] === 1 ? '' : 's') . ' created';
    }
    if ($res['linked']) {
        $parts[] = $res['linked'] . ' linked to existing profile' . ($res['linked'] === 1 ? '' : 's');
    }
    if ($res['skipped']) {
        $parts[] = $res['skipped'] . ' skipped (already in the directory or not passed out)';
    }
    api_ok($res, ($parts ? ucfirst(implode(', ', $parts)) : 'Nothing to import') . '.');
});

route('GET', '/alumni/{id:\d+}/profile', function ($p) {
    api_require('alumni', 'view');
    $d = alumni_profile((int) $p['id']);
    if (!$d) {
        api_error('Alumni profile not found. It may have been deleted.', 404);
    }
    api_ok($d);
});

/* ---------------------------------------------------------------- mentorships */

route('GET', '/alumni/mentorships/stats', function () {
    api_require('alumni', 'view');
    api_ok(alumni_mentorship_stats());
});

/* ---------------------------------------------------------------- events */

route('GET', '/alumni/events/stats', function () {
    api_require('alumni', 'view');
    api_ok(alumni_events_stats());
});

route('GET', '/alumni/events/{id:\d+}', function ($p) {
    api_require('alumni', 'view');
    $d = alumni_event_detail((int) $p['id']);
    if (!$d) {
        api_error('Event not found. It may have been deleted.', 404);
    }
    api_ok($d);
});

route('POST', '/alumni/events/{id:\d+}/registrations', function ($p) {
    api_require('alumni', 'edit');
    $ids = api_param('alumni_ids', []);
    $guests = (int) api_param('guests', 0);
    if ($guests < 0 || $guests > 10) {
        api_error('Please fix the highlighted fields and try again.', 422, ['guests' => 'Guests must be between 0 and 10.']);
    }
    $res = alumni_event_register((int) $p['id'], is_array($ids) ? $ids : [], $guests);
    $msg = $res['added'] . ' alumni registered.' . ($res['skipped'] ? ' ' . $res['skipped'] . ' already registered.' : '');
    api_ok($res, $msg, 201);
});

route('POST', '/alumni/events/{id:\d+}/status', function ($p) {
    api_require('alumni', 'edit');
    $res = alumni_event_set_status((int) $p['id'], (string) api_param('status', ''));
    api_ok($res, $res['message']);
});

route('POST', '/alumni/registrations/{id:\d+}', function ($p) {
    api_require('alumni', 'edit');
    $res = alumni_registration_set_status((int) $p['id'], (string) api_param('status', ''));
    api_ok($res, 'Registration updated.');
});

route('DELETE', '/alumni/registrations/{id:\d+}', function ($p) {
    api_require('alumni', 'delete');
    $res = alumni_registration_delete((int) $p['id']);
    api_ok($res, 'Registration removed.');
});

/* ---------------------------------------------------------------- jobs */

route('GET', '/alumni/jobs/stats', function () {
    api_require('alumni', 'view');
    api_ok(alumni_jobs_stats());
});

route('POST', '/alumni/jobs/close-expired', function () {
    api_require('alumni', 'edit');
    $n = alumni_jobs_close_expired();
    api_ok(['closed' => $n], $n ? "$n expired job post" . ($n === 1 ? '' : 's') . ' closed.' : 'There are no expired job posts to close.');
});

route('POST', '/alumni/jobs/{id:\d+}/{action:approve|reject|close|reopen}', function ($p) {
    api_require('alumni', in_array($p['action'], ['approve', 'reject'], true) ? 'approve' : 'edit');
    $res = alumni_job_action((int) $p['id'], $p['action'], (string) api_param('reason', ''));
    api_ok($res, $res['message']);
});

/* ---------------------------------------------------------------- stories */

route('GET', '/alumni/stories/stats', function () {
    api_require('alumni', 'view');
    api_ok(alumni_stories_stats());
});

route('POST', '/alumni/stories/{id:\d+}/publish', function ($p) {
    api_require('alumni', 'publish');
    $publish = in_array(api_param('publish', true), [true, 1, '1', 'true'], true);
    $res = alumni_story_publish((int) $p['id'], $publish);
    api_ok($res, $res['message']);
});

route('POST', '/alumni/stories/{id:\d+}/feature', function ($p) {
    api_require('alumni', 'edit');
    $featured = in_array(api_param('featured', true), [true, 1, '1', 'true'], true);
    $res = alumni_story_feature((int) $p['id'], $featured);
    api_ok($res, $res['message']);
});

/* ---------------------------------------------------------------- donations */

route('GET', '/alumni/donations/stats', function () {
    api_require('alumni', 'view');
    api_ok(alumni_donations_stats());
});

route('POST', '/alumni/donations/{id:\d+}/status', function ($p) {
    api_require('alumni', 'edit');
    $res = alumni_donation_set_status((int) $p['id'], (string) api_param('status', ''), api_input());
    api_ok($res, $res['message']);
});
