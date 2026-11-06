<?php
/**
 * Core data fixes (always runs; idempotent - safe to re-run on a live database at any time).
 *
 * Brings databases that were seeded before Stage C in line with the current core seeders WITHOUT resetting anything
 * editors may have changed (05_cms_core would rebuild every menu and page it owns):
 *   1. CMS menus: links to the old /student-portal and /faculty-portal URLs (they only redirect to the portal sign-in pages)
 *      point straight at /portal/student/login and /portal/faculty/login.
 *   2. Legal pages (privacy-policy, terms-and-conditions, refund-policy, accessibility): the "Last updated" line of the
 *      05_cms_core copy still showing 01 July 2026 shows the date their wording last changed (13 October 2026). Pages
 *      whose line an editor already changed are left alone.
 *   3-4. About-page story sentence and demo program career lists (untouched seeded text only).
 *   5. The admin "password_reset" email template gets a copy-able link under its button (untouched template only).
 *
 * Fresh installs get the same values from 01_core / 05_cms_core / app/services/cms.php directly, so here every step is a no-op.
 */
return function (array $opts): void {
    // 1. portal links in every menu location (footer "Student Corner", header, mobile drawer, top bar ...)
    $links = ['/student-portal' => '/portal/student/login', '/faculty-portal' => '/portal/faculty/login'];
    $moved = 0;
    foreach ($links as $old => $new) {
        $moved += db_exec('UPDATE menu_items SET url = ? WHERE url IN (?, ?, ?)', [$new, $old, $old . '/', $old . '.php']);
    }

    // 2. "Last updated" of the 05_cms_core legal pages (only the untouched seeded line)
    $oldLine = '<p><em>Last updated: 01 July 2026</em></p>';
    $newLine = '<p><em>Last updated: ' . date('d F Y', strtotime('2026-10-13')) . '</em></p>';
    $dated = 0;
    foreach (['privacy-policy', 'terms-and-conditions', 'refund-policy', 'accessibility'] as $slug) {
        $dated += db_exec('UPDATE pages SET content = REPLACE(content, ?, ?), updated_at = GREATEST(updated_at, ?) WHERE slug = ? AND content LIKE ?',
            [$oldLine, $newLine, '2026-10-13 10:00:00', $slug, '%' . $oldLine . '%']);
    }

    // 3. About page story: "began with a single management program" contradicted the departments (Management and
    //    Commerce were both founded in 2008) - only the untouched seeded sentence is changed
    $story = db_exec("UPDATE pages SET content = REPLACE(content, 'began with a single management program and a simple belief', 'began with programs in management and commerce and a simple belief')
                      WHERE slug = 'about' AND content LIKE '%began with a single management program and a simple belief%'");

    // 4. Career opportunities of the certificate / diploma / data-science demo programs listed generic software roles
    //    (10_academics_people now seeds program-specific ones); only rows still holding that generic list are updated
    $generic = "Software Engineer\nData Analyst\nCloud Engineer\nFull Stack Developer\nAI/ML Engineer";
    $careers = [
        'CDM' => "Digital Marketing Executive\nSEO / SEM Specialist\nSocial Media Manager\nContent Marketing Associate\nPerformance Marketing Analyst",
        'CDA' => "Data Analyst\nBusiness Intelligence Analyst\nMIS Executive\nReporting Analyst\nJunior Data Scientist",
        'DCAP' => "IT Support Technician\nOffice Automation Assistant\nData Entry & MIS Assistant\nJunior Web Designer\nComputer Operator",
        'BSCDS' => "Data Analyst\nData Scientist\nBusiness Analyst\nMachine Learning Engineer\nStatistical Analyst",
    ];
    $roles = 0;
    foreach ($careers as $code => $list) {
        $roles += db_exec('UPDATE programs SET career_prospects = ? WHERE code = ? AND REPLACE(career_prospects, CHAR(13), \'\') = ?', [$list, $code, $generic]);
    }

    // 5. Admin "password_reset" email: add a copy-able link under the button (mail apps that block buttons) - only while
    //    the template is still the untouched seeded copy (an edited template is left alone)
    $oldReset = '<p>If you did not request this, you can ignore this email.</p>';
    $newReset = '<p style="font-size:13px;color:#64748b">Button not working? Copy this link into your browser:<br>{{reset_link}}</p>'
        . '<p>If you did not request this, you can ignore this email - your password will not change.</p>';
    $reset = db_exec("UPDATE email_templates SET body_html = REPLACE(body_html, ?, ?) WHERE code = 'password_reset' AND updated_at IS NULL
                      AND body_html LIKE ? AND body_html NOT LIKE '%Copy this link%'", [$oldReset, $newReset, '%' . $oldReset . '%']);

    if (PHP_SAPI === 'cli' && ($moved || $dated || $story || $roles || $reset)) {
        fwrite(STDOUT, sprintf("    %d menu link(s) moved to the portal sign-in pages, %d legal page date(s) updated, %d about page fix, %d program career list(s), %d reset email template\n",
            $moved, $dated, $story, $roles, $reset));
    }
};
