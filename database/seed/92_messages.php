<?php
/**
 * Demo seed (Dashboard unit): internal messages between the seeded admin users — realistic day-to-day
 * conversations (admissions, fees, exams, placement, hostel, library, website …) with replies, multi-recipient
 * messages, read / unread state and a few starred conversations, timed relative to the moment the seed runs.
 *
 * Owns the `messages` table: every run clears it first, so it is safe to re-run.
 */
require_once __DIR__ . '/lib/demo_data.php';

return function (array $opts): void {
    demo_seed(92031);
    db_exec('DELETE FROM messages');

    $users = db_pairs("SELECT username, id FROM users WHERE status = 'active'");
    $uid = fn (string $username) => isset($users[$username]) ? (int) $users[$username] : null;

    // Minutes before "now" for each message; anything newer than 18 hours stays unread for its recipients.
    $conversations = [
        [
            'star' => ['admin'],
            'messages' => [
                ['admissions', ['admin'], 3 * 1440 + 200, 'Counselling slots for Saturday walk-ins',
                    "Good morning sir,\n\nWe have 38 walk-in enquiries booked for counselling this Saturday (BBA 14, MBA 9, B.Tech 11, BCA 4). I have split them into four slots between 10:00 AM and 3:00 PM in the admission cell.\n\nKiran will handle the MBA and BBA families, I will take B.Tech and BCA. Could you please approve opening the conference room for parents who come in groups?\n\nRegards,\nPooja Malhotra\nAdmission Officer"],
                ['admin', ['admissions'], 3 * 1440 + 120, 'Re: Counselling slots for Saturday walk-ins',
                    "Approved, Pooja. Conference room 2 is yours from 9:30 AM. Please keep printed fee structures and the scholarship policy handy, and share the counselling outcome sheet with me by Monday."],
                ['admissions', ['admin'], 2 * 1440 + 900, 'Re: Counselling slots for Saturday walk-ins',
                    "Thank you sir. Outcome sheet will be shared by Monday 11 AM. We also have 6 applicants pending document verification who are coming the same day."],
            ],
        ],
        [
            'messages' => [
                ['accounts', ['admin', 'administrator'], 26 * 60, 'Fee collection summary - September 2026',
                    "Dear sir,\n\nSharing the September collection summary:\n- Tuition and semester fee: collected against 1,100+ invoices\n- UPI and online gateway now account for nearly 60% of receipts\n- 85 students have crossed the second installment due date\n\nReminder SMS and emails go out tomorrow morning. Please let me know if any category should be held back (scholarship cases are already excluded).\n\nRavi Agarwal\nAccounts Officer"],
                ['administrator', ['accounts'], 20 * 60, 'Re: Fee collection summary - September 2026',
                    "Thanks Ravi. Please hold reminders for the 12 students whose scholarship letters are still with the state portal and send me that list separately."],
            ],
        ],
        [
            'star' => ['exams'],
            'messages' => [
                ['exams', ['academics', 'faculty', 'admin'], 2 * 1440 + 300, 'Mid-term invigilation duty roster',
                    "Dear colleagues,\n\nThe invigilation roster for the mid-term examinations is now final. Each faculty member has a maximum of four duties, and no one is assigned to an exam of their own subject.\n\nPlease report to the examination cell 30 minutes before the paper to collect answer booklets and attendance sheets. Requests for swaps must reach me at least 48 hours in advance.\n\nDr. Kavita Rao\nController of Examinations"],
                ['faculty', ['exams'], 2 * 1440 + 120, 'Re: Mid-term invigilation duty roster',
                    "Ma'am, I have a guest lecture coordination on Thursday afternoon. Could my 2:00 PM duty be swapped with the Friday morning slot?"],
                ['exams', ['faculty'], 1440 + 600, 'Re: Mid-term invigilation duty roster',
                    "Done, Dr. Sakshi. You are now on Friday 10:00 AM in Room A-204. The updated roster is on the notice board as well."],
            ],
        ],
        [
            'messages' => [
                ['placement', ['admin'], 9 * 60, 'Campus drive - lab and auditorium booking',
                    "Sir,\n\nA leading IT services recruiter has confirmed an on-campus drive for the 2027 batch (B.Tech CSE, AI & DS, MCA and BCA). They need:\n1. Auditorium for the pre-placement talk (9:30 AM)\n2. Two computer labs with 120 systems for the online aptitude test\n3. Six rooms for technical and HR interviews\n\nCould you approve the bookings so that I can confirm the date with their HR team today?\n\nNeha Kapoor\nTraining & Placement Officer"],
            ],
        ],
        [
            'messages' => [
                ['library', ['admin'], 30 * 60, 'Book procurement list for approval',
                    "Dear sir,\n\nThe departments have submitted their requirements for this semester. The consolidated list has 214 titles (412 copies), mostly management case books and programming references, within the sanctioned library budget.\n\nKindly approve so that purchase orders can be raised before the vendors' Diwali closure.\n\nMeena Iyer\nChief Librarian"],
                ['admin', ['library'], 22 * 60, 'Re: Book procurement list for approval',
                    "Approved in principle, Meena. Please prioritise titles that appear in more than one syllabus and get two quotations for orders above Rs 50,000."],
            ],
        ],
        [
            'messages' => [
                ['hostel', ['admin'], 5 * 60, 'Hostel mess committee meeting',
                    "Sir, the mess committee met yesterday with student representatives. Main points: breakfast timing extended to 9:15 AM during exams, a weekly feedback form, and replacing two water coolers in Block B.\n\nMinutes are attached in the hostel register. Requesting approval for the water cooler purchase.\n\nSuresh Yadav\nChief Warden"],
            ],
        ],
        [
            'star' => ['admin'],
            'messages' => [
                ['admin', ['academics', 'admissions', 'accounts', 'exams', 'placement', 'library'], 4 * 1440 + 60, 'NAAC documentation - data due by 20 October',
                    "Dear all,\n\nAs discussed in the IQAC meeting, please upload your criterion-wise data and supporting documents to the shared NAAC folder by 20 October:\n- Admissions: intake, category-wise enrolment, demand ratio\n- Academics: syllabus revision and value-added courses\n- Examinations: result analysis and grievance records\n- Accounts: fee, scholarship and expenditure statements\n- Placement: drives, offers and higher-studies data\n- Library: e-resources and usage statistics\n\nWe will review everything together on 22 October at 11:00 AM.\n\nRegards,\nAdministrator"],
                ['academics', ['admin'], 3 * 1440 + 500, 'Re: NAAC documentation - data due by 20 October',
                    "Noted. Academic criterion data will be uploaded by 18 October. We will also include the faculty development programme reports."],
                ['placement', ['admin'], 3 * 1440 + 200, 'Re: NAAC documentation - data due by 20 October',
                    "Placement data for the last three batches is ready. I will upload it along with the offer letters by Friday."],
            ],
        ],
        [
            'messages' => [
                ['content', ['admin'], 40 * 60, 'Website banner for Diwali and admissions 2027',
                    "Hello sir,\n\nI have prepared two homepage banners: a Diwali greeting and an 'Admissions Open 2027-28' teaser for early applicants. Both are saved as drafts in Website CMS > Banners.\n\nPlease review and tell me which one should go live first.\n\nSimran Sethi\nContent Manager"],
                ['admin', ['content'], 34 * 60, 'Re: Website banner for Diwali and admissions 2027',
                    "Nice work, Simran. Publish the Diwali greeting from 1 November, then switch to the admissions teaser after the break. Please also add the helpline number on the admissions banner."],
            ],
        ],
        [
            'messages' => [
                ['transport', ['admin'], 7 * 60, 'Bus on Route 3 due for servicing',
                    "Sir, the bus on Route 3 (Noida Sector 62) is due for its 40,000 km service and the fitness certificate expires next month. I have arranged a standby bus from Route 7 for two days.\n\nPlease approve the service at the authorised workshop.\n\nMahesh Tyagi\nTransport Manager"],
            ],
        ],
        [
            'messages' => [
                ['admin', ['accounts'], 6 * 1440, 'Scholarship disbursement approval',
                    "Ravi, the merit scholarship list for semester toppers has been approved by the committee. Please adjust the amounts against the second installment invoices and share the statement with me once done."],
                ['accounts', ['admin'], 5 * 1440 + 300, 'Re: Scholarship disbursement approval',
                    "Done sir. Concessions have been applied to all eligible invoices and the revised statements are available in Fees > Scholarships."],
            ],
        ],
        [
            'messages' => [
                ['academics', ['admin', 'exams'], 14 * 60, 'Timetable revision for B.Tech semester 3',
                    "Dear sir and ma'am,\n\nTwo faculty members from CSE are on approved leave next week, so the B.Tech semester 3 timetable needs a temporary revision. The lab sessions will move to the afternoon and theory classes are covered by visiting faculty. No clash with the mid-term schedule.\n\nThe revised timetable will be published tomorrow unless you see an issue.\n\nDr. Sunil Mehta\nDean Academics"],
            ],
        ],
        [
            'messages' => [
                ['kiran.joshi', ['admissions'], 18 * 60, 'Follow-up list for MBA applicants',
                    "Pooja ma'am, I have called 24 MBA applicants from the follow-up list. 9 will visit on Saturday, 6 asked for the scholarship details by email, and the rest need a call next week. Updated notes are in each enquiry."],
                ['admissions', ['kiran.joshi'], 16 * 60, 'Re: Follow-up list for MBA applicants',
                    "Great work, Kiran. Please email the scholarship policy PDF to those six today and mark them as 'interested'."],
            ],
        ],
        [
            'messages' => [
                ['admin', ['faculty'], 8 * 1440, 'Guest lecture on AI in business',
                    "Dr. Sakshi, the guest lecture by the industry expert is confirmed for next Thursday at 2:30 PM in the auditorium. Could you coordinate the student registrations from BBA and MBA and arrange a short Q&A session?"],
                ['faculty', ['admin'], 7 * 1440 + 400, 'Re: Guest lecture on AI in business',
                    "Sure sir. Registrations will open on the student notice board today. I will share the final attendee count by Tuesday."],
            ],
        ],
        [
            'messages' => [
                ['alumni', ['admin', 'placement'], 50 * 60, 'Alumni mentorship programme - first cohort',
                    "Dear sir and ma'am,\n\n18 alumni working in IT, banking and consulting have agreed to mentor final-year students this semester. Each mentor will take 4-5 students for monthly sessions.\n\nI would like to announce it at the next placement orientation. Please share your suggestions.\n\nArjun Bhatia\nAlumni Relations"],
            ],
        ],
    ];

    $now = time();
    $rows = 0;
    foreach ($conversations as $conv) {
        $threadId = null;
        $parentId = null;
        $starredBy = array_filter(array_map($uid, $conv['star'] ?? []));
        $lastIndex = count($conv['messages']) - 1;
        foreach ($conv['messages'] as $i => [$from, $to, $minutesAgo, $subject, $body]) {
            $sender = $uid($from);
            $recipients = array_values(array_filter(array_map($uid, $to), fn ($r) => $r && $r !== $sender));
            if (!$sender || !$recipients) {
                continue;
            }
            $at = date('Y-m-d H:i:s', $now - $minutesAgo * 60 - mt_rand(0, 59));
            $fresh = $minutesAgo < 18 * 60;
            $batch = count($recipients) > 1 ? substr(md5('gimt-demo-message-' . $rows), 0, 16) : null;
            $first = null;
            foreach ($recipients as $rid) {
                $read = !$fresh || mt_rand(1, 100) <= 15;
                $id = db_insert('messages', [
                    'parent_id' => $parentId, 'thread_id' => $threadId, 'batch_key' => $batch, 'sender_id' => $sender, 'recipient_id' => $rid,
                    'subject' => $subject, 'body' => $body, 'is_read' => $read ? 1 : 0,
                    'read_at' => $read ? date('Y-m-d H:i:s', min($now, strtotime($at) + mt_rand(5, 240) * 60)) : null,
                    // a starred conversation carries the star on its latest message
                    'is_starred_by_sender' => $i === $lastIndex && in_array($sender, $starredBy, true) ? 1 : 0,
                    'is_starred_by_recipient' => $i === $lastIndex && in_array($rid, $starredBy, true) ? 1 : 0,
                    'created_at' => $at,
                ]);
                $rows++;
                $first = $first ?? $id;
            }
            if ($threadId === null) {
                $threadId = $first;
                db_exec('UPDATE messages SET thread_id = ? WHERE thread_id IS NULL AND id >= ?', [$threadId, $first]);
            }
            $parentId = $first;
        }
    }
};
