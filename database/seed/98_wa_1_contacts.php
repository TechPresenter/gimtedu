<?php
/**
 * WhatsApp contact CRM demo (part: contacts). Runs the admission-portal sync over the demo enquiries, applications,
 * students and guardians, then makes the CRM look lived-in: counselling-scheduled leads, counsellor ownership,
 * ~70 % WhatsApp opt-in (demo consent records with their sources, a few opt-outs), tags (Hostel, Scholarship, MBA 2026, Hot lead…),
 * stage history paths, follow-ups (sent / due today / overdue / upcoming / skipped) and saved segments.
 *
 * Anchored on today. Re-runnable: removes only what it owns (follow-ups, stage history, segments, its demo tags,
 * messages with callback_data 'seed:followups' plus the follow-up messages the worker sent for them) and re-derives the
 * stages of ERP-linked contacts. Only a handful of follow-ups are overdue / due now, the rest fall due over the rest of
 * today and the coming days, so the first worker run sends a few, not a flood.
 */
require_once __DIR__ . '/lib/demo_data.php';

return function (array $opts): void {
    if (!db_table_exists('wa_contacts') || !db_table_exists('wa_segments') || !is_file(APP_ROOT . '/app/services/whatsapp.php')) {
        return;
    }
    require_once APP_ROOT . '/app/services/whatsapp.php';
    if (!function_exists('wa_crm_sync')) {
        return;
    }
    demo_seed(98001);
    $now = time();
    $fmt = fn (int $ts) => date('Y-m-d H:i:s', $ts);
    $chance = fn (int $pct) => mt_rand(1, 100) <= $pct;
    $in = fn (array $ids) => implode(',', array_fill(0, count($ids), '?'));
    /** Insert the template message a seeded follow-up "sent" (delivered / read ticks) and refresh the conversation. */
    $seedMessage = function (array $c, ?array $tpl, int $followupId, int $ts): ?int
    {
        if (!$tpl) {
            return null;
        }
        $conv = wa_conversation_for((int) $c['id']);
        try {
            $text = wa_render_template($tpl, wa_variables_for_contact($c))['text'] ?? $tpl['body'];
        } catch (Throwable $e) {
            $text = $tpl['body'];
        }
        $read = mt_rand(1, 100) <= 55;
        $readTs = $read ? $ts + mt_rand(300, 14400) : null;
        if ($readTs !== null && $readTs > time() - 60) {
            $read = false; // a read receipt must not be in the future (a follow-up sent this morning may still be unread)
            $readTs = null;
        }
        $at = date('Y-m-d H:i:s', $ts);
        $id = db_insert('wa_messages', [
            'conversation_id' => (int) $conv['id'], 'contact_id' => (int) $c['id'], 'direction' => 'out', 'type' => 'template', 'body' => $text,
            'template_id' => (int) $tpl['id'], 'template_name' => $tpl['name'], 'status' => $read ? 'read' : 'delivered', 'sender_type' => 'followup',
            // Interakt-style id so the demo webhook / API logs (98_wa_7_logs) can point at this message
            'interakt_id' => 'seed-fu-' . $followupId,
            'followup_id' => $followupId, 'callback_data' => 'seed:followups', 'created_at' => $at, 'sent_at' => $at,
            'delivered_at' => date('Y-m-d H:i:s', $ts + 20), 'read_at' => $readTs !== null ? date('Y-m-d H:i:s', $readTs) : null,
        ]);
        $last = db_row('SELECT created_at FROM wa_messages WHERE conversation_id = ? ORDER BY created_at DESC, id DESC LIMIT 1', [(int) $conv['id']]);
        if ($last && $last['created_at'] === $at) {
            db_update('wa_conversations', ['last_message_at' => $at, 'last_message_preview' => mb_substr('Template: ' . $tpl['name'], 0, 255), 'last_message_direction' => 'out'], 'id = ?', [(int) $conv['id']]);
        }
        db_exec('UPDATE wa_contacts SET last_outbound_at = GREATEST(COALESCE(last_outbound_at, ?), ?), last_message_at = GREATEST(COALESCE(last_message_at, ?), ?) WHERE id = ?', [$at, $at, $at, $at, (int) $c['id']]);
        return $id;
    };


    /* ---------------------------------------------------------------- clean own rows */
    $tagDefs = [
        'Hot lead' => 'red', 'MBA 2026' => 'navy', 'Hostel' => 'purple', 'Scholarship' => 'amber', 'Transport' => 'cyan',
        'Callback requested' => 'blue', 'Walk-in' => 'green', 'Referral' => 'green', 'Fee concession' => 'amber', 'Parent meeting' => 'slate',
    ];
    // keep the worker's incremental sync / follow-up ticks away while the demo data is rebuilt
    $locked = (int) db_value("SELECT GET_LOCK('gimt_wa_worker', 30)") === 1;
    save_setting('wa_crm_sync_last', $fmt($now), 'whatsapp');
    // the messages of the follow-ups wiped below: the seeded ones and those the worker sent for an earlier demo run
    // (otherwise every re-seed leaves another batch of orphaned reminders on top of the inbox)
    $fuWhere = "sender_type = 'followup' AND (callback_data = 'seed:followups' OR followup_id IS NOT NULL)";
    $fuConv = array_map('intval', db_column("SELECT DISTINCT conversation_id FROM wa_messages WHERE $fuWhere"));
    $fuContacts = array_map('intval', db_column("SELECT DISTINCT contact_id FROM wa_messages WHERE $fuWhere"));
    db_exec("DELETE FROM wa_messages WHERE $fuWhere");
    foreach (array_chunk($fuConv, 300) as $chunk) {
        // a chat that held nothing else goes; the others point at their latest remaining message again
        db_exec('DELETE cv FROM wa_conversations cv LEFT JOIN wa_messages m ON m.conversation_id = cv.id LEFT JOIN wa_notes n ON n.conversation_id = cv.id
                  WHERE m.id IS NULL AND n.id IS NULL AND cv.id IN (' . $in($chunk) . ')', $chunk);
        foreach (db_column('SELECT cv.id FROM wa_conversations cv WHERE cv.id IN (' . $in($chunk) . ')
                               AND NOT EXISTS (SELECT 1 FROM wa_messages m WHERE m.conversation_id = cv.id AND m.created_at = cv.last_message_at)', $chunk) as $cvId) {
            $last = db_row('SELECT created_at, body, type, template_name, direction FROM wa_messages WHERE conversation_id = ? ORDER BY created_at DESC, id DESC LIMIT 1', [(int) $cvId]);
            $text = $last ? ($last['type'] === 'template' ? ((string) $last['body'] ?: 'Template: ' . $last['template_name']) : ((string) $last['body'] ?: ucfirst(str_replace('_', ' ', (string) $last['type'])))) : '';
            db_update('wa_conversations', ['last_message_at' => $last['created_at'] ?? null, 'last_message_preview' => $last ? mb_substr(wa_preview($text), 0, 255) : null,
                'last_message_direction' => $last['direction'] ?? null], 'id = ?', [(int) $cvId]);
        }
    }
    foreach (array_chunk($fuContacts, 300) as $chunk) {
        db_exec("UPDATE wa_contacts k SET
                    k.last_outbound_at = (SELECT MAX(m.created_at) FROM wa_messages m WHERE m.contact_id = k.id AND m.direction = 'out'),
                    k.last_message_at = (SELECT MAX(m.created_at) FROM wa_messages m WHERE m.contact_id = k.id)
                  WHERE k.id IN (" . $in($chunk) . ')', $chunk);
    }
    db_exec('DELETE FROM wa_followups');
    db_exec('DELETE FROM wa_contact_stage_history');
    db_exec('DELETE FROM wa_segments');
    db_exec('DELETE FROM wa_tags WHERE name IN (' . $in(array_keys($tagDefs)) . ')', array_keys($tagDefs));
    // let the sync re-derive every ERP-linked stage (deterministic re-runs)
    db_exec("UPDATE wa_contacts SET stage = 'new_enquiry', erp_stage = NULL, counsellor_id = NULL, opt_in = 0, opt_in_at = NULL, opt_in_source = NULL, opted_out_at = NULL
             WHERE enquiry_id IS NOT NULL OR admission_id IS NOT NULL OR student_id IS NOT NULL OR source = 'sync'");

    /* ---------------------------------------------------------------- stage rules (fills template links still missing after 09_wa_3_followups) */
    (require __DIR__ . '/09_wa_3_followups.php')($opts);
    wa_crm_stages(true);

    /* ---------------------------------------------------------------- sync from the admission portal */
    $prevQuiet = wa_crm_quiet(true);
    $stats = wa_crm_sync('all', ['live' => false]);
    echo '    sync: ' . $stats['created'] . ' created, ' . $stats['updated'] . ' updated, ' . $stats['phones'] . ' phones, ' . $stats['errors'] . ' errors, ' . $stats['duration_ms'] . " ms\n";

    $team = db_all("SELECT DISTINCT u.id, u.name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                    WHERE u.status = 'active' AND r.slug = 'admission-officer' ORDER BY u.id");
    if (!$team) {
        $team = db_all("SELECT id, name FROM users WHERE status = 'active' ORDER BY id LIMIT 2");
    }
    $teamIds = array_map(fn ($u) => (int) $u['id'], $team);

    $leads = db_all("SELECT id, name, stage, contact_type, program_id, lead_source, counsellor_id, created_at, stage_changed_at, status
                     FROM wa_contacts WHERE contact_type IN ('lead','applicant') ORDER BY id");

    /* ---------------------------------------------------------------- manual stages: counselling scheduled */
    $history = [];
    foreach ($leads as &$l) {
        if ($l['stage'] === 'interested' && $chance(40)) {
            $at = $now - mt_rand(1, 5) * 86400 - mt_rand(0, 8) * 3600;
            db_update('wa_contacts', ['stage' => 'counselling_scheduled', 'stage_changed_at' => $fmt($at)], 'id = ?', [(int) $l['id']]);
            $l['stage'] = 'counselling_scheduled';
            $l['stage_changed_at'] = $fmt($at);
        }
        if ($l['stage'] === 'contacted' && $chance(20)) {
            $at = $now - mt_rand(0, 3) * 86400 - mt_rand(1, 6) * 3600;
            db_update('wa_contacts', ['stage' => 'interested', 'stage_changed_at' => $fmt($at)], 'id = ?', [(int) $l['id']]);
            $l['stage'] = 'interested';
            $l['stage_changed_at'] = $fmt($at);
        }
    }
    unset($l);

    /* ---------------------------------------------------------------- counsellors */
    if ($teamIds) {
        $byCounsellor = [];
        foreach ($leads as $i => &$l) {
            if (!$l['counsellor_id'] && $chance(85)) {
                $l['counsellor_id'] = $teamIds[$i % count($teamIds)];
                $byCounsellor[$l['counsellor_id']][] = (int) $l['id'];
            }
        }
        unset($l);
        foreach ($byCounsellor as $uid => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                db_exec('UPDATE wa_contacts SET counsellor_id = ? WHERE id IN (' . $in($chunk) . ')', array_merge([$uid], $chunk));
            }
        }
    }

    /* ---------------------------------------------------------------- opt-in (~70 %) + a few opt-outs */
    $all = db_all('SELECT id, contact_type, opt_in, created_at FROM wa_contacts ORDER BY id');
    $sources = [
        // demo consent records — each names where the person actually agreed (a lead writing to GIMT first is not consent)
        'lead' => ['Website enquiry form consent', 'Replied YES to WhatsApp opt-in request', 'Verbal consent on call', 'Education fair sign-up sheet'],
        'applicant' => ['Application form consent', 'Website enquiry form consent', 'Verbal consent on call'],
        'student' => ['Student portal consent', 'Admission form declaration'],
        'alumni' => ['Alumni portal consent'],
        'guardian' => ['Parent consent at orientation', 'Admission form declaration (parent)'],
    ];
    $optGroups = [];
    $outIds = [];
    foreach ($all as $c) {
        if ((int) $c['opt_in']) {
            continue;
        }
        $r = mt_rand(1, 100);
        if ($r <= 3) {
            $outIds[] = (int) $c['id'];
        } elseif ($r <= 70) {
            $src = demo_pick($sources[$c['contact_type']] ?? ['Manual']);
            $optGroups[$src][] = (int) $c['id'];
        }
    }
    foreach ($optGroups as $src => $ids) {
        foreach (array_chunk($ids, 500) as $chunk) {
            db_exec('UPDATE wa_contacts SET opt_in = 1, opt_in_source = ?, opt_in_at = COALESCE(created_at, NOW()) WHERE id IN (' . $in($chunk) . ')', array_merge([$src], $chunk));
        }
    }
    foreach (array_chunk($outIds, 500) as $chunk) {
        db_exec("UPDATE wa_contacts SET opt_in = 0, opted_out_at = ?, opt_in_source = 'Replied STOP' WHERE id IN (" . $in($chunk) . ')', array_merge([$fmt($now - mt_rand(2, 40) * 86400)], $chunk));
    }

    /* ---------------------------------------------------------------- tags */
    $tagIds = [];
    foreach ($tagDefs as $name => $color) {
        $existingTag = db_value('SELECT id FROM wa_tags WHERE name = ?', [$name]);
        if ($existingTag) {
            db_update('wa_tags', ['color' => $color], 'id = ?', [(int) $existingTag]);
            $tagIds[$name] = (int) $existingTag;
        } else {
            $tagIds[$name] = db_insert('wa_tags', ['name' => $name, 'color' => $color]);
        }
    }
    $ct = [];
    $tag = function (int $contactId, string $name) use (&$ct, $tagIds, $now, $fmt) {
        $ct[$contactId . ':' . $tagIds[$name]] = ['contact_id' => $contactId, 'tag_id' => $tagIds[$name], 'created_at' => $fmt($now - mt_rand(1, 60) * 86400)];
    };
    $mba = (int) db_value("SELECT id FROM programs WHERE code = 'MBA' LIMIT 1");
    foreach ($leads as $l) {
        $id = (int) $l['id'];
        if (in_array($l['stage'], ['interested', 'counselling_scheduled', 'application_started', 'payment_pending'], true) && $chance(45)) {
            $tag($id, 'Hot lead');
        }
        if ($mba && (int) $l['program_id'] === $mba) {
            $tag($id, 'MBA 2026');
        }
        if ($chance(20)) {
            $tag($id, 'Hostel');
        }
        if ($chance(15)) {
            $tag($id, 'Scholarship');
        }
        if ($chance(8)) {
            $tag($id, 'Transport');
        }
        if ($l['lead_source'] === 'phone' || (in_array($l['stage'], ['new_enquiry', 'contacted'], true) && $chance(12))) {
            $tag($id, 'Callback requested');
        }
        if ($l['lead_source'] === 'walk_in') {
            $tag($id, 'Walk-in');
        }
        if ($l['lead_source'] === 'referral') {
            $tag($id, 'Referral');
        }
        if ($l['stage'] === 'payment_pending' && $chance(30)) {
            $tag($id, 'Fee concession');
        }
    }
    foreach (db_all("SELECT c.id, s.is_hosteller, s.uses_transport FROM wa_contacts c JOIN students s ON s.id = c.student_id WHERE c.contact_type IN ('student','guardian')") as $s) {
        if ((int) $s['is_hosteller']) {
            $tag((int) $s['id'], 'Hostel');
        }
        if ((int) $s['uses_transport'] && $chance(40)) {
            $tag((int) $s['id'], 'Transport');
        }
    }
    foreach (db_column("SELECT id FROM wa_contacts WHERE contact_type = 'guardian' ORDER BY id") as $gid) {
        if ($chance(4)) {
            $tag((int) $gid, 'Parent meeting');
        }
    }
    foreach (db_column("SELECT c.id FROM wa_contacts c JOIN students s ON s.id = c.student_id WHERE c.contact_type = 'student' ORDER BY c.id") as $sid) {
        if ($chance(6)) {
            $tag((int) $sid, 'Scholarship');
        }
    }
    $existingPairs = [];
    foreach (db_all('SELECT contact_id, tag_id FROM wa_contact_tags WHERE tag_id IN (' . $in(array_values($tagIds)) . ')', array_values($tagIds)) as $p) {
        $existingPairs[$p['contact_id'] . ':' . $p['tag_id']] = true;
    }
    demo_bulk_insert('wa_contact_tags', array_values(array_diff_key($ct, $existingPairs)));

    /* ---------------------------------------------------------------- stage history paths */
    db_exec("DELETE FROM wa_contact_stage_history WHERE contact_id IN (SELECT id FROM wa_contacts WHERE contact_type IN ('lead','applicant') OR (contact_type = 'student' AND admission_id IS NOT NULL))");
    $path = ['new_enquiry', 'contacted', 'interested', 'counselling_scheduled', 'application_started', 'application_submitted', 'payment_pending', 'admission_confirmed'];
    $erpStages = ['application_started', 'application_submitted', 'payment_pending', 'admission_confirmed'];
    // a counsellor's step lands in office hours (09:30–18:30) of its day when that keeps it between its neighbours
    $officeHours = function (int $ts, int $lo, int $hi): int {
        $h = (int) date('G', $ts);
        if ($h >= 10 && $h < 19) {
            return $ts;
        }
        $cand = strtotime(date('Y-m-d 00:00:00', $ts)) + mt_rand(570, 1110) * 60 + mt_rand(0, 59);
        return $cand > $lo && $cand < $hi ? $cand : $ts;
    };
    $stageFix = []; // contact id => new stage_changed_at (lead journeys moved after the enquiry)
    $stageRows = db_all(
        "SELECT c.id, c.stage, c.counsellor_id, c.created_at, c.stage_changed_at, e.created_at AS enquiry_at, a.created_at AS admission_at
           FROM wa_contacts c LEFT JOIN enquiries e ON e.id = c.enquiry_id LEFT JOIN admissions a ON a.id = c.admission_id
          WHERE c.contact_type IN ('lead','applicant') OR (c.contact_type = 'student' AND c.admission_id IS NOT NULL) ORDER BY c.id"
    );
    foreach ($stageRows as $c) {
        if ($c['stage'] === 'not_interested') {
            $steps = ['new_enquiry', 'contacted', 'not_interested'];
        } else {
            $rank = array_search($c['stage'], $path, true);
            $steps = array_slice($path, 0, ($rank === false ? 0 : $rank) + 1);
            // not every lead goes through every optional stage
            $steps = array_values(array_filter($steps, fn ($s) => $s === $c['stage'] || !in_array($s, ['counselling_scheduled', 'application_started'], true) || mt_rand(1, 100) <= 45));
        }
        $n = count($steps);
        // the journey ends when the contact reached its current stage and starts at its first touch (enquiry,
        // application or CRM record, whichever is earliest)
        $end = min(strtotime((string) ($c['stage_changed_at'] ?: $c['created_at'])) ?: $now - 86400, $now - 600);
        $touches = array_filter(array_map(fn ($v) => $v ? strtotime((string) $v) : false, [$c['enquiry_at'], $c['admission_at'], $c['created_at']]));
        $start = min($touches ? min($touches) : $end, $end);
        if ($n > 1 && $end - $start < ($n - 1) * 3 * 3600) {
            // imported records often carry one timestamp for everything (created = stage changed): spread the steps
            if (in_array($c['stage'], $erpStages, true) || $c['admission_at']) {
                // the enquiry / counselling steps happened in the days before the application
                $start = $end - ($n - 1) * mt_rand(20, 72) * 3600 - mt_rand(0, 3600);
            } else {
                // a lead's counsellor steps follow the enquiry; its stage changed with the last one
                $newEnd = min($now - mt_rand(20, 90) * 60, $start + ($n - 1) * mt_rand(5, 40) * 3600 + mt_rand(0, 3600));
                if ($newEnd - $start >= ($n - 1) * 1800) {
                    $end = $newEnd;
                    $stageFix[(int) $c['id']] = $fmt($end);
                } else {
                    $start = $end - ($n - 1) * mt_rand(4, 30) * 3600; // a very recent lead: the steps before it
                }
            }
        }
        // random gaps (not a uniform grid), counsellor steps in office hours, strictly increasing (≥ 1 min apart)
        $times = [$start];
        if ($n > 1) {
            $w = [];
            for ($i = 1; $i < $n; $i++) {
                $w[] = mt_rand(40, 160);
            }
            $sum = array_sum($w);
            $acc = 0;
            for ($i = 1; $i < $n - 1; $i++) {
                $acc += $w[$i - 1];
                $times[$i] = $start + (int) round(($end - $start) * $acc / $sum);
                if (!in_array($steps[$i], $erpStages, true)) {
                    $times[$i] = $officeHours($times[$i], $times[$i - 1], $end);
                }
            }
            $times[$n - 1] = $end;
            for ($i = 1; $i < $n - 1; $i++) {
                $times[$i] = min(max($times[$i], $times[$i - 1] + 60 + mt_rand(0, 300)), $end - ($n - 1 - $i) * 60);
            }
        } else {
            $times = [$end];
        }
        $prev = null;
        foreach ($steps as $i => $s) {
            $erp = in_array($s, $erpStages, true);
            $history[] = [
                'contact_id' => (int) $c['id'], 'from_stage' => $prev, 'to_stage' => $s, 'source' => $i === 0 ? 'sync' : ($erp ? 'sync' : demo_pick(['manual', 'board', 'manual'])),
                'note' => $i === 0 ? 'Imported from the admission portal' : ($erp ? 'Updated from the admission portal' : null),
                'changed_by' => $i === 0 || $erp ? null : ($c['counsellor_id'] ?: null), 'created_at' => $fmt($times[$i]),
            ];
            $prev = $s;
        }
    }
    demo_bulk_insert('wa_contact_stage_history', $history);
    foreach ($stageFix as $cid => $at) {
        db_update('wa_contacts', ['stage_changed_at' => $at], 'id = ?', [$cid]);
    }

    /* ---------------------------------------------------------------- follow-ups (+ the template messages already sent) */
    $cfgs = [];
    foreach (db_all('SELECT * FROM wa_followup_stages') as $s) {
        $cfgs[$s['stage_key']] = $s;
    }
    $templates = [];
    foreach (db_all('SELECT * FROM wa_templates') as $t) {
        $templates[(int) $t['id']] = $t;
    }
    $teamNames = array_column($team, 'name', 'id');
    $openLeads = db_all("SELECT * FROM wa_contacts WHERE contact_type IN ('lead','applicant') AND status = 'active' AND stage NOT IN ('admission_confirmed','not_interested') ORDER BY id");
    $sentMessages = 0;
    $followupRows = 0;
    $dayStart = strtotime(date('Y-m-d 00:00:00', $now));
    // Only a handful are overdue / due right now (the first worker run sends every due follow-up at once); the rest
    // wait in today's remaining office hours and the coming days, so the queue drains a few at a time.
    $dueNowLeft = ['overdue' => 3, 'now' => 4];
    $officeSlot = fn (int $dayOffset): int => strtotime(date('Y-m-d', $now + $dayOffset * 86400) . ' 09:30:00') + mt_rand(0, 8 * 60) * 60 + mt_rand(0, 59);
    $laterToday = function () use ($now): ?int {
        $from = $now + 15 * 60;
        $to = strtotime(date('Y-m-d 18:30:00', $now));
        return $to - $from >= 20 * 60 ? mt_rand($from, $to) : null;
    };
    $upcoming = fn (): int => $officeSlot((int) demo_weighted([1 => 34, 2 => 26, 3 => 18, 4 => 12, 5 => 6, 6 => 4]));
    foreach ($openLeads as $c) {
        $cfg = $cfgs[$c['stage']] ?? null;
        if (!$cfg || !(int) $cfg['is_active'] || !$cfg['template_id'] || (int) $cfg['max_followups'] < 1) {
            continue;
        }
        $optedOut = !(int) $c['opt_in'] && $c['opted_out_at'];
        $max = (int) $cfg['max_followups'];
        $delay = max(1, (int) $cfg['delay_hours']) * 3600;
        $entered = strtotime((string) ($c['stage_changed_at'] ?: $c['created_at'])) ?: $now - 3 * 86400;
        $sent = $optedOut ? 0 : mt_rand(0, $max - 1);
        $t = $entered;
        for ($a = 1; $a <= $sent; $a++) {
            $t += $delay;
            if ($t > $now - 1800) {
                $sent = $a - 1;
                break;
            }
            $fid = db_insert('wa_followups', ['contact_id' => (int) $c['id'], 'stage_key' => $c['stage'], 'attempt' => $a, 'template_id' => (int) $cfg['template_id'],
                'due_at' => $fmt($t), 'status' => 'sent', 'note' => 'Sent template ' . ($templates[(int) $cfg['template_id']]['name'] ?? ''), 'created_at' => $fmt($t - $delay), 'updated_at' => $fmt($t + 40)]);
            $mid = $seedMessage($c, $templates[(int) $cfg['template_id']] ?? null, $fid, $t + 40);
            if ($mid) {
                db_update('wa_followups', ['message_id' => $mid, 'updated_at' => $fmt($t + 40)], 'id = ?', [$fid]);
                $sentMessages++;
            }
            $followupRows++;
        }
        $attempt = $sent + 1;
        if ($optedOut) {
            db_insert('wa_followups', ['contact_id' => (int) $c['id'], 'stage_key' => $c['stage'], 'attempt' => 1, 'template_id' => (int) $cfg['template_id'],
                'due_at' => $fmt($entered + $delay), 'status' => 'skipped', 'note' => 'Contact opted out', 'created_at' => $fmt($entered), 'updated_at' => $fmt($entered + $delay)]);
            $followupRows++;
            continue;
        }
        $r = mt_rand(1, 100);
        if ($r <= 10) {
            $status = 'cancelled';
            $due = min($now - 3600, $t + $delay);
            $note = 'Contact replied';
        } elseif ($r <= 15) {
            $status = 'skipped';
            $due = min($now - 3600, $t + $delay);
            $who = $c['counsellor_id'] && isset($teamNames[$c['counsellor_id']]) ? $teamNames[$c['counsellor_id']] : 'Pooja Malhotra';
            $note = 'Skipped by ' . $who . ': ' . demo_pick(['Spoke on the phone, will visit on Saturday', 'Parent asked to call after 6 PM', 'Already shared brochure on call', 'Student travelling this week']);
        } elseif ($r <= 35) {
            $status = 'scheduled';
            $note = null;
            if ($dueNowLeft['overdue'] > 0) {
                $dueNowLeft['overdue']--;
                $due = $officeSlot(-mt_rand(1, 2)); // overdue since yesterday / the day before
            } else {
                $due = $upcoming();
            }
        } elseif ($r <= 62) {
            $status = 'scheduled';
            $note = null;
            if ($dueNowLeft['now'] > 0 && $now - $dayStart > 2 * 3600) {
                $dueNowLeft['now']--;
                $due = max($dayStart + 3600, $now - mt_rand(5, 150) * 60); // due earlier today
            } else {
                $due = (mt_rand(1, 100) <= 55 ? $laterToday() : null) ?? $upcoming(); // later today, else the coming days
            }
        } else {
            $status = 'scheduled';
            $due = $upcoming();
            $note = null;
        }
        // scheduled when the stage was entered / the previous attempt went out, never after it falls due
        $createdAt = $status === 'scheduled' ? min(max($entered, $t), $due - 3600) : max($entered, $t);
        db_insert('wa_followups', ['contact_id' => (int) $c['id'], 'stage_key' => $c['stage'], 'attempt' => min($attempt, $max), 'template_id' => (int) $cfg['template_id'],
            'due_at' => $fmt($due), 'status' => $status, 'note' => $note, 'created_at' => $fmt($createdAt), 'updated_at' => $status === 'scheduled' ? null : $fmt($due)]);
        $followupRows++;
    }
    // earlier-stage follow-ups of leads that moved on (sent, then cancelled by the stage change)
    $movedOn = db_all("SELECT * FROM wa_contacts WHERE contact_type IN ('lead','applicant') AND stage IN ('application_submitted','payment_pending','admission_confirmed') ORDER BY id");
    foreach ($movedOn as $c) {
        if (!$chance(55)) {
            continue;
        }
        $prevStage = demo_pick(['interested', 'counselling_scheduled', 'application_started']);
        $cfg = $cfgs[$prevStage] ?? null;
        if (!$cfg || !$cfg['template_id']) {
            continue;
        }
        $changed = strtotime((string) ($c['stage_changed_at'] ?: $c['created_at'])) ?: $now - 5 * 86400;
        $t = $changed - mt_rand(2, 6) * 86400;
        $fid = db_insert('wa_followups', ['contact_id' => (int) $c['id'], 'stage_key' => $prevStage, 'attempt' => 1, 'template_id' => (int) $cfg['template_id'],
            'due_at' => $fmt($t), 'status' => 'sent', 'note' => 'Sent template ' . ($templates[(int) $cfg['template_id']]['name'] ?? ''), 'created_at' => $fmt($t - 86400), 'updated_at' => $fmt($t + 30)]);
        $mid = $seedMessage($c, $templates[(int) $cfg['template_id']] ?? null, $fid, $t + 30);
        if ($mid) {
            db_update('wa_followups', ['message_id' => $mid, 'updated_at' => $fmt($t + 30)], 'id = ?', [$fid]);
            $sentMessages++;
        }
        db_insert('wa_followups', ['contact_id' => (int) $c['id'], 'stage_key' => $prevStage, 'attempt' => 2, 'template_id' => (int) $cfg['template_id'],
            'due_at' => $fmt($t + (int) $cfg['delay_hours'] * 3600), 'status' => 'cancelled', 'note' => 'Stage changed to ' . wa_crm_stage_name($c['stage']), 'created_at' => $fmt($t + 30), 'updated_at' => $fmt($changed)]);
        $followupRows += 2;
    }
    echo "    follow-ups: $followupRows rows, $sentMessages template messages\n";

    /* ---------------------------------------------------------------- segments */
    $hot = $tagIds['Hot lead'];
    $segments = [
        ['Hot leads — not applied yet', 'Interested / counselling-stage leads tagged Hot lead, opted in to WhatsApp.', ['scope' => 'leads', 'stage' => ['interested', 'counselling_scheduled'], 'tag' => [(string) $hot], 'opt_in' => '1']],
        ['MBA 2026 enquiries', 'Every lead and applicant tagged MBA 2026.', ['tag' => [(string) $tagIds['MBA 2026']], 'type' => ['lead', 'applicant']]],
        ['Payment pending', 'Applicants who still have to pay the admission fee.', ['stage' => ['payment_pending']]],
        ['Hostel enquiries', 'Leads and applicants who asked about the hostel.', ['tag' => [(string) $tagIds['Hostel']], 'type' => ['lead', 'applicant']]],
        ['Parents of current students', 'Guardians of active students (opted in) — notices, fee reminders, PTMs.', ['type' => ['guardian'], 'opt_in' => '1']],
        ['Fee overdue — students', 'Students with an overdue fee instalment.', ['type' => ['student'], 'payment_status' => ['overdue']]],
    ];
    $creator = $teamIds[0] ?? null;
    foreach ($segments as [$name, $desc, $filters]) {
        $cnt = wa_crm_count($filters);
        db_insert('wa_segments', ['name' => $name, 'description' => $desc, 'filters' => json_encode($filters), 'contacts_count' => $cnt['total'],
            'opted_in_count' => $cnt['opted_in'], 'counted_at' => $fmt($now), 'created_by' => $creator]);
    }

    wa_crm_quiet($prevQuiet);
    save_setting('wa_crm_sync_last', $fmt($now - 600), 'whatsapp');
    if ($locked) {
        db_value("SELECT RELEASE_LOCK('gimt_wa_worker')");
    }
    echo '    contacts: ' . db_value('SELECT COUNT(*) FROM wa_contacts') . ', opted in: ' . db_value('SELECT COUNT(*) FROM wa_contacts WHERE opt_in = 1') . "\n";
};

