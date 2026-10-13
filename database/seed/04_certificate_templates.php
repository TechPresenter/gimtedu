<?php
/**
 * Core data: the seven professional certificate templates (one default per type) used by the Certificates unit.
 *
 * Re-runnable without breaking issued certificates: templates are matched by name and updated in place (ids stay
 * stable, so certificates.template_id keeps pointing at them). Placeholders are documented in
 * app/services/certificates.php (cert_placeholders) and in the template editor's placeholder picker.
 *
 * Owns: certificate_templates.
 */
return function (array $opts): void {
    $registrar = ['Dr. Rajeev Saxena', 'Registrar'];
    $director = ['Prof. Neha Verma', 'Director'];
    $coe = ['Dr. Kavita Rao', 'Controller of Examinations'];
    $tpo = ['Ms. Neha Kapoor', 'Training & Placement Officer'];
    $dean = ['Dr. Amit Kumar', 'Dean — Academics'];

    $templates = [
        [
            'type' => 'bonafide', 'name' => 'Bonafide Certificate — Standard',
            'description' => 'Proof of current enrolment for banks, passports, scholarships, visas and travel concessions.',
            'title' => 'Bonafide Certificate', 'subtitle' => 'To Whomsoever It May Concern',
            'orientation' => 'portrait', 'border_style' => 'classic', 'accent_color' => '#0B2A5B', 'font_style' => 'serif', 'validity_days' => 180,
            'sign' => $registrar, 'sign2' => null, 'show_photo' => 0,
            'body' => '<p>This is to certify that <strong>{{salutation}} {{student_name}}</strong>, {{son_daughter}} of <strong>{{father_name}}</strong>, '
                . 'Student ID <strong>{{student_uid}}</strong> (Enrolment No. {{enrollment_no}}), is a bonafide student of {{institute_name}}. '
                . '{{pronoun_cap}} is presently pursuing the <strong>{{program}}</strong> programme and is studying in the <strong>{{semester}}</strong> '
                . '({{year_of_study}}) during the academic session <strong>{{session}}</strong>.</p>'
                . '<p>As per the records of the Institute, {{pronoun_possessive}} date of birth is <strong>{{dob}}</strong>{{#section}} and {{pronoun}} is enrolled in Section {{section}}{{/section}}.</p>'
                . '<p>This certificate is issued on {{pronoun_possessive}} request for the purpose of <strong>{{purpose}}</strong> and is valid up to <strong>{{valid_until}}</strong>.</p>',
        ],
        [
            'type' => 'transfer', 'name' => 'Transfer Certificate — Standard',
            'description' => 'Leaving certificate with the full particulars table; issued once per student.',
            'title' => 'Transfer Certificate', 'subtitle' => 'Institute Leaving Certificate',
            'orientation' => 'portrait', 'border_style' => 'double', 'accent_color' => '#0B2A5B', 'font_style' => 'serif', 'validity_days' => null,
            'sign' => $registrar, 'sign2' => $director, 'show_photo' => 0,
            'body' => '<p>Certified that the particulars of <strong>{{salutation}} {{student_name}}</strong> given below are as per the records of the Institute:</p>'
                . '<table><tbody>'
                . '<tr><th>Name of the student</th><td>{{student_name}}</td></tr>'
                . '<tr><th>Father\'s name</th><td>{{father_name}}</td></tr>'
                . '<tr><th>Mother\'s name</th><td>{{mother_name}}</td></tr>'
                . '<tr><th>Date of birth</th><td>{{dob}}</td></tr>'
                . '<tr><th>Nationality / Category</th><td>{{nationality}} / {{category}}</td></tr>'
                . '<tr><th>Student ID / Enrolment No.</th><td>{{student_uid}} / {{enrollment_no}}</td></tr>'
                . '<tr><th>Programme</th><td>{{program}}</td></tr>'
                . '<tr><th>Date of admission</th><td>{{admission_date}}</td></tr>'
                . '<tr><th>Date of leaving</th><td>{{leaving_date}}</td></tr>'
                . '<tr><th>Last examination appeared</th><td>{{last_exam}}</td></tr>'
                . '<tr><th>Reason for leaving</th><td>{{leaving_reason}}</td></tr>'
                . '<tr><th>Conduct and character</th><td>{{conduct}}</td></tr>'
                . '<tr><th>All dues cleared</th><td>{{dues_cleared}}</td></tr>'
                . '</tbody></table>'
                . '<p>The Institute has no objection to {{pronoun_possessive}} joining any other recognised institution. We wish {{pronoun_object}} success in all future endeavours.</p>',
        ],
        [
            'type' => 'character', 'name' => 'Character Certificate — Ornate',
            'description' => 'Conduct and character attestation for employers, higher studies and police verification.',
            'title' => 'Character Certificate', 'subtitle' => 'Certificate of Good Conduct',
            'orientation' => 'portrait', 'border_style' => 'ornate', 'accent_color' => '#14532D', 'font_style' => 'serif', 'validity_days' => null,
            'sign' => $director, 'sign2' => null, 'show_photo' => 0,
            'body' => '<p>This is to certify that <strong>{{salutation}} {{student_name}}</strong>, {{son_daughter}} of <strong>{{father_name}}</strong>, '
                . 'Student ID <strong>{{student_uid}}</strong>, has been a student of this Institute in the <strong>{{program}}</strong> programme since {{admission_year}}.</p>'
                . '<p>To the best of our knowledge and as per the records of the Institute, {{pronoun_possessive}} conduct and character during the period of study '
                . 'have been <strong>{{conduct}}</strong>. {{pronoun_cap}} has not been involved in any act of indiscipline or in any activity prejudicial to the interests of the Institute.</p>'
                . '<p>{{#purpose}}This certificate is issued for the purpose of <strong>{{purpose}}</strong>. {{/purpose}}We wish {{pronoun_object}} every success in life.</p>',
        ],
        [
            'type' => 'course_completion', 'name' => 'Course Completion — Landscape Ornate',
            'description' => 'Award-style landscape certificate for students who completed their programme.',
            'title' => 'Certificate of Course Completion', 'subtitle' => 'Presented with pride to',
            'orientation' => 'landscape', 'border_style' => 'ornate', 'accent_color' => '#0B2A5B', 'font_style' => 'serif', 'validity_days' => null,
            'sign' => $director, 'sign2' => $coe, 'show_photo' => 0,
            'body' => '<h2>{{student_name}}</h2>'
                . '<p>{{son_daughter}} of {{father_name}}, Student ID {{student_uid}}, for having successfully completed the {{duration}} '
                . '<strong>{{program}}</strong> programme of {{institute_name}} in <strong>{{completion_month_year}}</strong>'
                . '{{#division}}, and having been placed in <strong>{{division}}</strong>{{/division}}{{#cgpa}} with a CGPA of <strong>{{cgpa}}</strong>{{/cgpa}}.</p>'
                . '<p>We congratulate {{pronoun_object}} on this achievement and wish {{pronoun_object}} a bright future.</p>',
        ],
        [
            'type' => 'internship', 'name' => 'Internship Certificate — Modern',
            'description' => 'Curricular internship / industrial training completion with organisation, role and period.',
            'title' => 'Internship Certificate', 'subtitle' => 'Industrial Training & Internship Programme',
            'orientation' => 'landscape', 'border_style' => 'modern', 'accent_color' => '#1D4ED8', 'font_style' => 'display', 'validity_days' => null,
            'sign' => $tpo, 'sign2' => $dean, 'show_photo' => 0,
            'body' => '<p>This is to certify that</p><h2>{{student_name}}</h2>'
                . '<p>a student of <strong>{{program}}</strong> ({{semester}}, Student ID {{student_uid}}), has successfully completed an internship with '
                . '<strong>{{company}}</strong> as <strong>{{internship_role}}</strong> from {{internship_start}} to {{internship_end}} ({{internship_duration}})'
                . '{{#project_title}}, working on the project “{{project_title}}”{{/project_title}}{{#mentor_name}} under the guidance of {{mentor_name}}{{/mentor_name}}.</p>'
                . '<p>The internship was undertaken as part of the curriculum and {{pronoun_possessive}} performance was found to be very satisfactory.</p>',
        ],
        [
            'type' => 'migration', 'name' => 'Migration Certificate — Standard',
            'description' => 'No-objection for migrating to another university; cancels the enrolment number.',
            'title' => 'Migration Certificate', 'subtitle' => 'No Objection for Migration',
            'orientation' => 'portrait', 'border_style' => 'double', 'accent_color' => '#0B2A5B', 'font_style' => 'serif', 'validity_days' => null,
            'sign' => $registrar, 'sign2' => $coe, 'show_photo' => 0,
            'body' => '<p>This is to certify that <strong>{{salutation}} {{student_name}}</strong>, {{son_daughter}} of <strong>{{father_name}}</strong>, '
                . 'Student ID <strong>{{student_uid}}</strong> and Enrolment No. <strong>{{enrollment_no}}</strong>, was a student of the <strong>{{program}}</strong> '
                . 'programme of this Institute (admission session {{admission_session}}).</p>'
                . '<p>{{pronoun_cap}} left the Institute on <strong>{{leaving_date}}</strong>. The Institute has no objection to {{pronoun_possessive}} migrating to '
                . '<strong>{{migrating_to}}</strong> for the pursuit of higher studies.</p>'
                . '<p>{{#purpose}}This certificate is issued for the purpose of {{purpose}}. {{/purpose}}Enrolment No. {{enrollment_no}} stands cancelled with effect from the date of this certificate.</p>',
        ],
        [
            'type' => 'provisional', 'name' => 'Provisional Certificate — Standard',
            'description' => 'Provisional proof of passing with CGPA and division until the degree is conferred.',
            'title' => 'Provisional Certificate', 'subtitle' => 'Pending Conferment of Degree',
            'orientation' => 'portrait', 'border_style' => 'classic', 'accent_color' => '#0B2A5B', 'font_style' => 'serif', 'validity_days' => 365,
            'sign' => $coe, 'sign2' => $registrar, 'show_photo' => 0,
            'body' => '<p>This is to certify that <strong>{{salutation}} {{student_name}}</strong>, {{son_daughter}} of <strong>{{father_name}}</strong>, '
                . 'Student ID <strong>{{student_uid}}</strong>, Enrolment No. <strong>{{enrollment_no}}</strong>, has passed the <strong>{{program}}</strong> '
                . 'examination held in <strong>{{completion_month_year}}</strong>.</p>'
                . '<table><tbody>'
                . '<tr><th>Programme duration</th><td>{{duration}}</td></tr>'
                . '<tr><th>Cumulative Grade Point Average</th><td>{{cgpa}}</td></tr>'
                . '<tr><th>Equivalent percentage</th><td>{{percentage}}</td></tr>'
                . '<tr><th>Division</th><td>{{division}}</td></tr>'
                . '</tbody></table>'
                . '<p>The degree will be conferred on {{pronoun_object}} at the next convocation of the University. This certificate is valid until '
                . '<strong>{{valid_until}}</strong> or the issue of the original degree, whichever is earlier.</p>',
        ],
    ];

    $adminId = db_value("SELECT id FROM users WHERE username = 'admin' LIMIT 1") ?: null;
    foreach ($templates as $t) {
        $row = [
            'type' => $t['type'], 'name' => $t['name'], 'description' => $t['description'], 'title' => $t['title'], 'subtitle' => $t['subtitle'],
            'body_html' => $t['body'], 'orientation' => $t['orientation'], 'border_style' => $t['border_style'], 'accent_color' => $t['accent_color'],
            'font_style' => $t['font_style'], 'signatory_name' => $t['sign'][0], 'signatory_designation' => $t['sign'][1],
            'signatory2_name' => $t['sign2'][0] ?? null, 'signatory2_designation' => $t['sign2'][1] ?? null,
            'show_photo' => $t['show_photo'], 'show_seal' => 1, 'show_qr' => 1, 'validity_days' => $t['validity_days'], 'is_default' => 1, 'status' => 'active',
        ];
        $id = db_value('SELECT id FROM certificate_templates WHERE name = ? ORDER BY id LIMIT 1', [$t['name']]);
        if ($id) {
            db_update('certificate_templates', $row, 'id = ?', [(int) $id]);
        } else {
            $id = db_insert('certificate_templates', $row + ['created_by' => $adminId]);
        }
        // One default per type
        db_exec('UPDATE certificate_templates SET is_default = 0 WHERE type = ? AND id <> ?', [$t['type'], (int) $id]);
    }
};
