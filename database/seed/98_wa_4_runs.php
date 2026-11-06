<?php
/**
 * Demo execution history for the WhatsApp automation workflows: realistic runs over the last 30 days (completed,
 * waiting on a delay / reply, failed with a real-looking refusal, cancelled after an opt-out) with full step timelines,
 * anchored on today so the Automation Builder always looks live. Runs still waiting carry a consistent resume state,
 * so the worker continues them when they fall due.
 *
 * Demo only. Owns every wa_workflow_runs / wa_workflow_steps row (replaced on each run). Also activates the two seeded
 * admission workflows (and marks the application reminder inactive) so the demo shows live automations. An untouched
 * seeded workflow (version 1) is first brought back to its current starter definition, so the history never shows a
 * step the starter no longer has (e.g. the earlier made-up "counselling tomorrow at 11:00 AM" confirmation).
 */
return function (array $opts): void {
    require_once is_file(APP_ROOT . '/app/services/whatsapp.php') ? APP_ROOT . '/app/services/whatsapp.php' : APP_ROOT . '/app/services/whatsapp_workflows.php';
    require_once __DIR__ . '/lib/demo_data.php';
    demo_seed(4404);

    $plan = ['new_admission_enquiry' => 72, 'payment_pending' => 26, 'application_incomplete' => 22];
    $starters = wa_wf_starters();
    $wfs = [];
    foreach ($plan as $key => $count) {
        $wf = db_row('SELECT * FROM wa_workflows WHERE trigger_config LIKE ? ORDER BY id LIMIT 1', ['%"seed_key":"' . $key . '"%']);
        if ($wf && (int) $wf['version'] === 1 && isset($starters[$key])) {
            $def = wa_wf_normalize_definition($starters[$key]['definition']);
            $cols = wa_wf_trigger_columns($def);
            $cols['trigger_config'] = wa_wf_json(wa_wf_decode($cols['trigger_config'], []) + ['seed_key' => $key]);
            db_update('wa_workflows', ['definition' => wa_wf_json($def), 'description' => $starters[$key]['description']] + $cols, 'id = ?', [(int) $wf['id']]);
            $wf = wa_wf_get((int) $wf['id']);
        }
        if ($wf) {
            $wfs[$key] = ['wf' => $wf, 'count' => $count];
        }
    }
    db_exec('DELETE FROM wa_workflow_runs');   // steps cascade
    if (!$wfs) {
        return;
    }
    $contacts = db_all("SELECT id, name, phone, email, enquiry_id, student_id, admission_id, program_name, stage, contact_type FROM wa_contacts WHERE status = 'active' ORDER BY id");
    if (!$contacts) {
        foreach ($wfs as $x) {
            wa_wf_refresh_stats((int) $x['wf']['id']);
        }
        return;
    }
    $counsellors = array_column(wa_wf_counsellors(true), 'name', 'id');
    $cIds = array_keys($counsellors);
    sort($cIds);
    $rr = 0;
    $now = time();
    $fmt = fn (int $ts) => date('Y-m-d H:i:s', min($ts, $now));
    $human = fn (array $d, string $def) => (int) ($d['value'] ?? 1) . ' ' . ($d['unit'] ?? $def);

    $replies = ['Yes, please share the fee details', 'I am interested. When can I visit the campus?', 'Please call me after 5 pm', 'What is the eligibility for BBA?',
        'Is hostel available for girls?', 'Yes', 'Interested', 'Can I get the scholarship details?', 'Please send the brochure again', 'Ok, call me tomorrow'];
    $failures = [
        ['status' => 'failed', 'error' => 'Missing template variables: last_date — the admissions deadline is not set. Set it under Settings › Website › Campaign landing pages › Application deadline, then retry this run.'],
    ];

    foreach ($wfs as $key => $x) {
        $wf = $x['wf'];
        $def = wa_wf_definition($wf);
        $nodes = wa_wf_node_map($def);
        $edges = wa_wf_edge_map($def);
        $trigger = wa_wf_trigger_node($def);
        $event = (string) ($trigger['data']['event'] ?? '');
        $pool = $contacts;
        // website enquiries for the enquiry workflow (GIMT_ENQUIRY_RECEIVED always carries the enquiry id), applicants for
        // the others (falls back to leads, then to anyone)
        $preferred = array_values(array_filter($pool, fn ($c) => $key === 'new_admission_enquiry' ? (bool) $c['enquiry_id'] : in_array($c['contact_type'], ['applicant', 'lead'], true) || $c['admission_id']));
        if ($key === 'new_admission_enquiry' && count($preferred) < $x['count']) {
            $preferred = array_values(array_filter($pool, fn ($c) => in_array($c['contact_type'], ['lead', 'other'], true) || $c['enquiry_id']));
        }
        $pool = count($preferred) >= $x['count'] ? $preferred : $pool;
        mt_srand(4404 + crc32($key));
        shuffle($pool);
        $pool = array_slice($pool, 0, min($x['count'], count($pool)));
        foreach ($pool as $i => $c) {
            // most runs in the last 30 days, a handful in the last few hours (still in progress)
            $start = $i < 4 ? $now - mt_rand(300, 6 * 3600) : strtotime(demo_datetime('-30 days', '-6 hours'));
            $t = $start;
            $payload = match ($key) {
                'new_admission_enquiry' => array_filter(['phone' => '+' . $c['phone'], 'name' => $c['name'], 'email' => $c['email'], 'enquiry_id' => $c['enquiry_id'] ? (int) $c['enquiry_id'] : null,
                    'course_name' => $c['program_name'], 'source' => demo_pick(['website', 'landing', 'website', 'chatbot']), 'form' => demo_pick(['smart-enquiry', 'enquiry-popup', 'landing-hero'])]),
                'payment_pending' => ['contact_id' => (int) $c['id'], 'from' => 'application_submitted', 'to' => 'payment_pending'],
                default => ['contact_id' => (int) $c['id'], 'from' => 'interested', 'to' => 'application_started'],
            };
            $steps = [[$trigger, 'ok', ['summary' => 'Triggered by ' . $event, 'event' => $event, 'contact_id' => (int) $c['id']], null, $t]];
            $status = 'completed';
            $waitingFor = null;
            $nextRun = null;
            $error = null;
            $resume = null;
            $waitingIdx = null;
            $lastReply = null;
            $current = $trigger['id'];
            $visits = [];
            $vars = [];
            $roll = mt_rand(1, 100);
            $failAt = $roll <= 7 ? mt_rand(1, 3) : 0;        // fail on the n-th template send
            $optOut = $roll > 7 && $roll <= 10;                 // opted out before the 2nd template
            $sends = 0;
            $nodeId = $edges[$trigger['id']]['next'] ?? null;
            $guard = 0;
            while ($nodeId !== null && $guard++ < 40) {
                $node = $nodes[$nodeId];
                $d = $node['data'];
                $current = $nodeId;
                $visits[$nodeId] = ($visits[$nodeId] ?? 0) + 1;
                $t += mt_rand(1, 3);
                $port = 'next';
                if ($t > $now) {
                    $t = $now;
                }
                switch ($node['type']) {
                    case 'contact':
                        $out = ['summary' => 'Contact linked: ' . ($c['name'] ?: '+' . $c['phone']) . ' (+' . $c['phone'] . ')', 'contact_id' => (int) $c['id'], 'created' => false];
                        if (!empty($d['create_enquiry'])) {
                            // only the enquiry the contact really is linked to (the history never invents an enquiry)
                            $enqNo = $c['enquiry_id'] ? (function_exists('wa_crm_enquiry_no') ? wa_crm_enquiry_no((int) $c['enquiry_id']) : '#' . (int) $c['enquiry_id']) : null;
                            $out = $enqNo
                                ? ['summary' => $out['summary'] . ' · ERP enquiry linked: ' . $enqNo, 'enquiry' => 'ok', 'enquiry_id' => (int) $c['enquiry_id'], 'enquiry_no' => $enqNo, 'enquiry_created' => false] + $out
                                : ['summary' => $out['summary'] . ' · no ERP enquiry linked', 'enquiry' => 'skipped'] + $out;
                            if ($enqNo) {
                                $vars['enquiry_id'] = (string) (int) $c['enquiry_id'];
                                $vars['enquiry_no'] = $enqNo;
                            }
                        } elseif ($c['enquiry_id']) {
                            $out['summary'] .= ' · enquiry #' . (int) $c['enquiry_id'];
                            $out['enquiry_id'] = (int) $c['enquiry_id'];
                        }
                        $steps[] = [$node, 'ok', $out, null, $t];
                        break;
                    case 'template':
                    case 'message':
                        $sends++;
                        $tpl = (string) ($d['template'] ?? '');
                        if ($optOut && $sends === 2) {
                            $steps[] = [$node, 'skipped', ['summary' => 'Contact opted out of WhatsApp — workflow stopped', 'code' => 'opted_out', 'template' => $tpl], null, $t];
                            $status = 'cancelled';
                            $error = 'The contact opted out of WhatsApp messages.';
                            break 2;
                        }
                        if ($failAt && $sends === $failAt) {
                            // the failure must fit the step: a deadline step fails on last_date, others on the number or a paused template
                            if (wa_wf_deadline_keys($node, [])) {
                                $f = ['error' => $failures[0]['error'], 'code' => 'missing_variables'];
                            } elseif ($sends === 1 && $i % 2) {   // a wrong number fails on the very first message
                                $f = ['error' => 'This is not a valid WhatsApp number.', 'code' => 'invalid_phone'];
                            } else {
                                $f = ['error' => 'Template "' . $tpl . '" is paused — only approved templates can be sent.', 'code' => 'template_not_approved'];
                            }
                            $steps[] = [$node, 'failed', ['code' => $f['code'], 'template' => $tpl], $f['error'], $t];
                            $status = 'failed';
                            $error = $f['error'];
                            break 2;
                        }
                        $steps[] = [$node, 'ok', ['summary' => 'Template "' . $tpl . '" queued', 'delivery' => 'queued', 'template' => $tpl], null, $t];
                        break;
                    case 'delay':
                        $end = $t + wa_wf_seconds(max(1, (int) ($d['value'] ?? 1)), (string) ($d['unit'] ?? 'minutes'));
                        if ($end > $now) {
                            $steps[] = [$node, 'waiting', ['summary' => 'Waiting ' . $human($d, 'minutes') . ' — until ' . date('d M, h:i A', $end), 'until' => date('Y-m-d H:i:s', $end)], null, $t];
                            $status = 'waiting';
                            $waitingFor = 'delay';
                            $nextRun = date('Y-m-d H:i:s', $end);
                            $resume = ['node' => $nodeId, 'port' => 'next'];
                            $waitingIdx = count($steps) - 1;
                            break 2;
                        }
                        $steps[] = [$node, 'ok', ['summary' => 'Wait finished', 'until' => date('Y-m-d H:i:s', $end), 'resumed_at' => date('Y-m-d H:i:s', $end)], null, $t];
                        $t = $end;
                        break;
                    case 'wait_reply':
                        $timeout = wa_wf_seconds(max(1, (int) ($d['value'] ?? 24)), (string) ($d['unit'] ?? 'hours'));
                        $replied = mt_rand(1, 100) <= ($key === 'new_admission_enquiry' ? 46 : 38);
                        $end = $t + ($replied ? mt_rand(900, (int) ($timeout * 0.7)) : $timeout);
                        if ($end > $now) {
                            $steps[] = [$node, 'waiting', ['summary' => 'Waiting up to ' . $human($d, 'hours') . ' for a reply — until ' . date('d M, h:i A', $t + $timeout), 'until' => date('Y-m-d H:i:s', $t + $timeout)], null, $t];
                            $status = 'waiting';
                            $waitingFor = 'reply';
                            $nextRun = date('Y-m-d H:i:s', $t + $timeout);
                            $resume = ['node' => $nodeId, 'port' => 'timeout'];
                            $waitingIdx = count($steps) - 1;
                            break 2;
                        }
                        if ($replied) {
                            $text = demo_pick($replies);
                            $lastReply = ['text' => $text, 'at' => date('Y-m-d H:i:s', $end)];
                            $steps[] = [$node, 'ok', ['summary' => 'Contact replied: "' . $text . '"', 'branch' => 'replied', 'resumed_at' => date('Y-m-d H:i:s', $end)], null, $t];
                            $port = 'replied';
                        } else {
                            $steps[] = [$node, 'ok', ['summary' => 'No reply before the timeout', 'branch' => 'timeout', 'resumed_at' => date('Y-m-d H:i:s', $end)], null, $t];
                            $port = 'timeout';
                        }
                        $t = $end;
                        break;
                    case 'condition':
                    case 'ifelse':
                        $yes = mt_rand(1, 100) <= ($node['type'] === 'ifelse' ? 45 : 58);
                        $rule = $d['rules'][0] ?? ['field' => 'contact.stage', 'op' => 'equals', 'value' => ''];
                        $steps[] = [$node, 'ok', ['summary' => $yes ? 'Condition met → Yes' : 'Condition not met → No', 'branch' => $yes ? 'true' : 'false',
                            'checks' => [sprintf('%s is "%s" → %s (value: %s)', $rule['field'], $rule['value'], $yes ? 'yes' : 'no', $yes ? $rule['value'] : demo_pick(['admission_confirmed', 'application_submitted']))]], null, $t];
                        $port = $yes ? 'true' : 'false';
                        break;
                    case 'tag':
                        $steps[] = [$node, 'ok', ['summary' => 'Added tag(s): ' . implode(', ', (array) ($d['tags'] ?? [])), 'tags' => (array) ($d['tags'] ?? [])], null, $t];
                        break;
                    case 'stage':
                        $steps[] = [$node, 'ok', ['summary' => 'Set stage → ' . label_from_key((string) ($d['stage'] ?? ''))], null, $t];
                        break;
                    case 'assign':
                        if ($cIds) {
                            $uid = $cIds[$rr++ % count($cIds)];
                            $steps[] = [$node, 'ok', ['summary' => 'Assigned to ' . $counsellors[$uid] . ' (round-robin)', 'user_id' => $uid], null, $t];
                        } else {
                            $steps[] = [$node, 'failed', [], 'No active counsellor is available for round-robin assignment.', $t];
                            $status = 'failed';
                            $error = 'No active counsellor is available for round-robin assignment.';
                            break 2;
                        }
                        break;
                    case 'end':
                        $steps[] = [$node, 'ok', ['summary' => 'Workflow finished'], null, $t];
                        $nodeId = null;
                        break 2;
                    default:
                        $steps[] = [$node, 'ok', ['summary' => 'Done'], null, $t];
                }
                $nodeId = $edges[$nodeId][$port] ?? null;
            }
            $ctx = ['payload' => $payload, 'vars' => $vars, 'visits' => $visits, '_def' => $def, 'version' => (int) $wf['version']];
            if ($lastReply) {
                $ctx['last_reply'] = $lastReply;
            }
            $runId = db_insert('wa_workflow_runs', [
                'workflow_id' => (int) $wf['id'], 'contact_id' => (int) $c['id'], 'event_log_id' => null, 'status' => $status, 'current_node' => $current,
                'context' => wa_wf_json($ctx), 'next_run_at' => $nextRun, 'waiting_for' => $waitingFor, 'attempts' => 0, 'error' => $error,
                'dedupe_key' => null, 'is_test' => 0, 'started_at' => $fmt($start),
                'finished_at' => in_array($status, ['completed', 'failed', 'cancelled'], true) ? $fmt($t) : null,
            ]);
            $waitingStepId = null;
            foreach ($steps as $si => [$node, $st, $out, $err, $at]) {
                $id = wa_wf_step($runId, $node, $st, $out, $err);
                db_exec('UPDATE wa_workflow_steps SET created_at = ? WHERE id = ?', [$fmt($at), $id]);
                if ($si === $waitingIdx) {
                    $waitingStepId = $id;
                }
            }
            if ($resume) {
                $ctx['resume'] = $resume;
                $ctx['waiting_step'] = $waitingStepId;
                db_exec('UPDATE wa_workflow_runs SET context = ? WHERE id = ?', [wa_wf_json($ctx), $runId]);
            }
        }
        $status = $key === 'application_incomplete' ? 'inactive' : 'active';
        if ($status === 'active' && wa_wf_has_errors(wa_wf_validate($def))) {
            $status = 'draft';
        }
        db_exec('UPDATE wa_workflows SET status = ?, updated_at = updated_at WHERE id = ?', [$status, (int) $wf['id']]);
        wa_wf_refresh_stats((int) $wf['id']);
    }
    wa_wf_reset_cache();
};
