<?php
/**
 * Create / edit an event (workshop, seminar, session, hackathon ...) or an awareness day.
 *   ?tab=details  every field: category, type, format / mode, venue, maps link, PRIVATE online joining details, dates,
 *                 registration window, seat limit, waiting list, eligibility, fee (display only), agenda, guidelines,
 *                 FAQs, speakers & partners, certificate, contact, SEO, banner, status (draft / scheduled / published /
 *                 cancelled / archived); cancel with reason (+ email), duplicate, delete
 *   ?tab=media    event photos (gallery_items with consent) and downloads (public or registered-only files)
 *   ?tab=report   report & staff-verified outcomes; attendance from registrations; participation certificates
 * Awareness days (observances such as World Health Day) keep the simple form and never take registrations.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('events.manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$event = $id ? db_row('SELECT * FROM events WHERE id = ?', [$id]) : null;
if ($id && !$event) {
    flash('error', 'Event not found.');
    admin_redirect('events.php');
}
$tab = $event && in_array($_GET['tab'] ?? '', ['media', 'report'], true) && $event['event_type'] !== 'awareness_day' ? (string) $_GET['tab'] : 'details';
$self = 'event-edit.php' . ($event ? '?id=' . $id : '');
$tabSelf = $self . ($tab !== 'details' ? '&tab=' . $tab : '');
const PH_PHOTO_CONSENT = 'No identifiable children or vulnerable people are shown, or documented consent was obtained';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_GET['upload'])) {
    content_editor_upload('events', $event ? 'event #' . $id : 'new event');
}

// Staff download of a resource (private files are served only here, after the permission check)
if ($event && isset($_GET['download'])) {
    $res = db_row('SELECT * FROM event_resources WHERE id = ? AND event_id = ?', [(int) $_GET['download'], $id]);
    if (!$res) {
        not_found();
    }
    admin_log('event_resource_downloaded', 'Event #' . $id . ' resource #' . (int) $res['id'] . ' "' . $res['title'] . '" opened by staff');
    programhub_admin_send_resource($res);
}

$programs = content_programs();
$categories = program_categories_all(false);
$catIds = array_map('intval', array_column($categories, 'id'));
$speakerList = db_rows('SELECT id, name, designation, profile_consent, status FROM speakers ORDER BY sort_order, name');
$partnerList = db_rows('SELECT id, name, partner_type, display_consent, status FROM partners ORDER BY sort_order, name');
$label = function (?array $ev): string {
    return ($ev && $ev['event_type'] === 'awareness_day' ? 'Awareness day' : 'Event') . ($ev ? ' #' . $ev['id'] . ' "' . $ev['title'] . '"' : '');
};

// =====================================================================
// POST
// =====================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($tabSelf);
    $action = (string) ($_POST['action'] ?? 'save');

    // ----- Delete / duplicate / cancel ----------------------------------
    if ($action === 'delete' && $event) {
        $regs = programhub_admin_delete_event($event);
        admin_log('event_deleted', $label($event) . ' deleted' . ($regs ? ' with ' . $regs . ' registrations' : ''));
        flash('success', 'The event was deleted.');
        admin_redirect('events.php' . ($event['event_type'] === 'awareness_day' ? '?tab=awareness' : ''));
    }
    if ($action === 'duplicate' && $event) {
        $newId = programhub_admin_duplicate_event($id);
        admin_log('event_duplicated', $label($event) . ' duplicated as draft #' . $newId);
        flash('success', 'This is a draft copy. Check the title, address and dates, then publish it.');
        admin_redirect('event-edit.php?id=' . $newId);
    }
    if ($action === 'cancel_event' && $event) {
        [$data, $errors] = validate($_POST, ['cancel_reason' => 'text|max:300'], ['cancel_reason' => 'Reason']);
        if ($errors) {
            back_with_errors($errors, 'admin/' . $self . '#cancel', $_POST);
        }
        $email = !empty($_POST['notify_registrants']) && admin_can('events.registrations');
        [$sent, $total] = programhub_admin_cancel_event($event, $data['cancel_reason'], $email);
        admin_log('event_cancelled', $label($event) . ' cancelled' . ($data['cancel_reason'] !== '' ? ': ' . mb_substr($data['cancel_reason'], 0, 200) : '') . ($email ? ' - ' . $sent . ' of ' . $total . ' participants emailed' : ''));
        flash('success', 'The event is cancelled and shown as cancelled on the website.' . ($email ? ' Email accepted for ' . $sent . ' of ' . $total . ' participant' . ($total === 1 ? '' : 's') . '.' : ''));
        admin_redirect($self);
    }

    // ----- Photos (tab media) -------------------------------------------
    if ($event && in_array($action, ['photo_upload', 'photo_link', 'photo_toggle', 'photo_unlink', 'photo_delete'], true)) {
        $back = 'event-edit.php?id=' . $id . '&tab=media';
        if ($action === 'photo_upload') {
            [$data, $errors] = validate($_POST, ['photo_title' => 'required|min:2|max:150', 'photo_description' => 'required|min:5|max:300'],
                ['photo_title' => 'Title', 'photo_description' => 'Description (alt text)']);
            $files = $_FILES['photos'] ?? null;
            $count = $files && is_array($files['name'] ?? null) ? count($files['name']) : 0;
            $chosen = 0;
            for ($i = 0; $i < $count; $i++) {
                $chosen += ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? 1 : 0;
            }
            if (!$chosen) {
                $errors['photos'] = 'Choose at least one photo to upload.';
            }
            if ($errors) {
                back_with_errors($errors, 'admin/' . $back . '#photos', $_POST);
            }
            $consent = !empty($_POST['photo_consent']) ? 1 : 0;
            $added = 0;
            $failed = [];
            $n = 0;
            for ($i = 0; $i < $count; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $n++;
                $_FILES['_ph_one'] = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                [$path, $err] = programhub_admin_upload_image('_ph_one', 'gallery', $data['photo_description']);
                if (!$path) {
                    $failed[] = $files['name'][$i] . ': ' . ($err ?: 'could not be uploaded');
                    continue;
                }
                $title = $chosen > 1 ? mb_substr($data['photo_title'], 0, 142) . ' (' . $n . ')' : $data['photo_title'];
                $newId = db_insert('gallery_items', [
                    'media_type' => 'image', 'title' => $title, 'category' => 'events', 'image_path' => $path, 'description' => $data['photo_description'],
                    'consent_confirmed' => $consent, 'sort_order' => 0, 'status' => $consent ? 'published' : 'hidden', 'uploaded_by' => admin_id(), 'event_id' => $id,
                ]);
                admin_log('gallery_uploaded', 'Photo #' . $newId . ' "' . $title . '" for event #' . $id . ' (' . ($consent ? 'published' : 'hidden - no consent confirmation') . ')');
                $added++;
            }
            unset($_FILES['_ph_one']);
            if ($added) {
                flash($consent ? 'success' : 'warning', $added . ' photo' . ($added === 1 ? '' : 's') . ' added' . ($consent ? ' and published with the event.' : ' but kept hidden: confirm consent to publish them.'));
            }
            if ($failed) {
                flash('error', 'Not uploaded - ' . implode('; ', array_slice($failed, 0, 5)));
            }
            admin_redirect($back . '#photos');
        }
        if ($action === 'photo_link') {
            $gid = (int) ($_POST['gallery_id'] ?? 0);
            $n = $gid ? db_update('gallery_items', ['event_id' => $id], 'id = :id AND event_id IS NULL', ['id' => $gid]) : 0;
            if ($n) {
                admin_log('gallery_linked', 'Gallery item #' . $gid . ' linked to event #' . $id);
                flash('success', 'The gallery item is now shown with this event (if it is published with consent).');
            } else {
                flash('error', 'Choose a gallery item that is not linked to another event.');
            }
            admin_redirect($back . '#photos');
        }
        $gid = (int) ($_POST['gallery_id'] ?? 0);
        $item = db_row('SELECT * FROM gallery_items WHERE id = ? AND event_id = ?', [$gid, $id]);
        if (!$item) {
            flash('error', 'Photo not found.');
            admin_redirect($back . '#photos');
        }
        $plabel = ($item['media_type'] === 'video' ? 'Video' : 'Photo') . ' #' . $gid . ' "' . $item['title'] . '"';
        if ($action === 'photo_toggle') {
            if ($item['status'] === 'published') {
                db_update('gallery_items', ['status' => 'hidden'], 'id = :id', ['id' => $gid]);
                admin_log('gallery_hidden', $plabel . ' hidden (event #' . $id . ')');
                flash('success', $plabel . ' is hidden.');
            } elseif (empty($_POST['photo_consent']) && (int) $item['consent_confirmed'] !== 1) {
                flash('error', 'Confirm "' . PH_PHOTO_CONSENT . '" before publishing.');
            } else {
                db_update('gallery_items', ['status' => 'published', 'consent_confirmed' => 1], 'id = :id', ['id' => $gid]);
                admin_log('gallery_published', $plabel . ' published with consent confirmation (event #' . $id . ')');
                flash('success', $plabel . ' is published.');
            }
        } elseif ($action === 'photo_unlink') {
            db_update('gallery_items', ['event_id' => null], 'id = :id', ['id' => $gid]);
            admin_log('gallery_unlinked', $plabel . ' removed from event #' . $id . ' (kept in the gallery)');
            flash('success', $plabel . ' was removed from this event. It stays in the gallery.');
        } elseif ($action === 'photo_delete') {
            db_query('DELETE FROM gallery_items WHERE id = ?', [$gid]);
            content_delete_image($item['image_path']);
            admin_log('gallery_deleted', $plabel . ' deleted (event #' . $id . ')');
            flash('success', $plabel . ' was deleted.');
        }
        admin_redirect($back . '#photos');
    }

    // ----- Downloads (tab media) ----------------------------------------
    if ($event && in_array($action, ['resource_add', 'resource_update', 'resource_delete'], true)) {
        $back = 'event-edit.php?id=' . $id . '&tab=media';
        if ($action === 'resource_delete') {
            $res = db_row('SELECT * FROM event_resources WHERE id = ? AND event_id = ?', [(int) ($_POST['resource_id'] ?? 0), $id]);
            if ($res) {
                db_query('DELETE FROM event_resources WHERE id = ?', [(int) $res['id']]);
                programhub_admin_delete_file($res['file_path']);
                admin_log('event_resource_deleted', 'Event #' . $id . ' download #' . (int) $res['id'] . ' "' . $res['title'] . '" deleted');
                flash('success', '"' . $res['title'] . '" was deleted.');
            }
            admin_redirect($back . '#downloads');
        }
        $res = null;
        if ($action === 'resource_update') {
            $res = db_row('SELECT * FROM event_resources WHERE id = ? AND event_id = ?', [(int) ($_POST['resource_id'] ?? 0), $id]);
            if (!$res) {
                flash('error', 'Download not found.');
                admin_redirect($back . '#downloads');
            }
        }
        [$data, $errors] = validate($_POST, [
            'res_title' => 'required|min:2|max:200',
            'res_type' => 'required|in:' . implode(',', array_keys(EVENT_RESOURCE_TYPES)),
            'res_description' => 'max:300',
            'res_visibility' => 'required|in:public,registered',
            'res_status' => 'required|in:published,hidden',
            'res_sort' => 'integer|min:-9999|max:9999',
            'res_url' => 'max:500',
        ], ['res_title' => 'Title', 'res_type' => 'Type', 'res_description' => 'Description', 'res_visibility' => 'Who can download', 'res_url' => 'External link']);
        $extUrl = programhub_admin_https($data['res_url'], $err);
        if ($err) {
            $errors['res_url'] = 'The external link ' . $err;
        }
        $private = $data['res_visibility'] === 'registered';
        $file = null;
        if (!$errors) {
            $up = handle_upload('res_file', 'document', $private ? PROGRAMHUB_UPLOADS['resource_private'] : PROGRAMHUB_UPLOADS['resource_public']);
            if (!empty($up['error'])) {
                $errors['res_file'] = $up['error'];
            }
            $file = $up['path'] ?? null;
        }
        if (!$errors && !$file && $extUrl === '' && !($res && ($res['file_path'] || $res['external_url']))) {
            $errors['res_file'] = 'Upload a file (PDF, JPG or PNG) or enter an external https:// link.';
        }
        if ($errors) {
            delete_upload($file);
            back_with_errors($errors, 'admin/' . $back . '#downloads', $_POST + ['res_form' => $res ? (string) $res['id'] : 'new']);
        }
        $row = ['title' => $data['res_title'], 'resource_type' => $data['res_type'], 'description' => $data['res_description'] ?: null,
            'visibility' => $data['res_visibility'], 'status' => $data['res_status'], 'sort_order' => (int) ($data['res_sort'] ?: 0)];
        if ($res) {
            $oldFile = (string) $res['file_path'];
            if ($file) {
                $row['file_path'] = $file;
                $row['external_url'] = null;
            } else {
                if ($extUrl !== '') {
                    $row['external_url'] = $extUrl;
                    $row['file_path'] = null;
                } elseif ($oldFile !== '' && ($res['visibility'] === 'registered') !== $private) {
                    $row['file_path'] = programhub_admin_move_resource_file($oldFile, $private);   // keep private files private
                }
            }
            db_update('event_resources', $row, 'id = :id', ['id' => (int) $res['id']]);
            if ($oldFile !== '' && array_key_exists('file_path', $row) && $row['file_path'] !== $oldFile && strpos((string) $row['file_path'], basename($oldFile)) === false) {
                programhub_admin_delete_file($oldFile);
            }
            admin_log('event_resource_updated', 'Event #' . $id . ' download #' . (int) $res['id'] . ' "' . $data['res_title'] . '" saved (' . $data['res_visibility'] . ', ' . $data['res_status'] . ')');
            flash('success', '"' . $data['res_title'] . '" saved.');
        } else {
            $rid = db_insert('event_resources', $row + ['event_id' => $id, 'file_path' => $file, 'external_url' => $file ? null : ($extUrl ?: null), 'uploaded_by' => admin_id()]);
            admin_log('event_resource_added', 'Event #' . $id . ' download #' . $rid . ' "' . $data['res_title'] . '" added (' . $data['res_visibility'] . ')');
            flash('success', '"' . $data['res_title'] . '" added' . ($private ? ' - only registered participants can open it.' : '.'));
        }
        admin_redirect($back . '#downloads');
    }

    // ----- Report & verified outcomes (tab report) ------------------------
    if ($event && $action === 'report_save') {
        $back = 'event-edit.php?id=' . $id . '&tab=report';
        [$data, $errors] = validate($_POST, [
            'participants_verified' => 'integer|min:0|max:10000000',
            'report_status' => 'required|in:draft,published',
        ], ['participants_verified' => 'Verified number of participants', 'report_status' => 'Report status']);
        $summary = clean_html((string) ($_POST['summary'] ?? ''));
        $outcomes = programhub_admin_rows($_POST['outcomes'] ?? [], ['label' => 150, 'value' => 60, 'note' => 250], 20, ['note']);
        foreach ($outcomes as $i => $o) {
            if ($o['label'] === '' || $o['value'] === '') {
                $errors['outcomes'] = 'Outcome ' . ($i + 1) . ' needs a description and a figure.';
                break;
            }
        }
        $verified = !empty($_POST['verified']);
        $hasFigures = $outcomes || $data['participants_verified'] !== '';
        if ($data['report_status'] === 'published') {
            if (!event_is_past($event)) {
                $errors['report_status'] = 'A report can be published once the event has taken place. Save it as a draft for now.';
            } elseif ($hasFigures && !$verified) {
                $errors['verified'] = 'Only staff-verified figures may be published: tick the verification box, or remove the figures.';
            } elseif (trim(strip_tags($summary)) === '' && !$hasFigures) {
                $errors['summary'] = 'Write a short report (or add verified outcomes) before publishing.';
            }
        }
        if ($errors) {
            back_with_errors($errors, 'admin/' . $back, $_POST);
        }
        $existing = db_row('SELECT * FROM event_reports WHERE event_id = ?', [$id]);
        $outJson = programhub_admin_json($outcomes);
        $pv = $data['participants_verified'] !== '' ? (int) $data['participants_verified'] : null;
        $figuresChanged = !$existing || (string) $existing['outcomes'] !== (string) $outJson || (string) $existing['participants_verified'] !== (string) $pv;
        $row = ['summary' => $summary !== '' ? $summary : null, 'outcomes' => $outJson, 'participants_verified' => $pv, 'status' => $data['report_status']];
        if ($verified && $hasFigures) {
            if ($figuresChanged || empty($existing['verified_at'])) {
                $row['verified_by'] = admin_id();
                $row['verified_at'] = date('Y-m-d H:i:s');
            }
        } else {
            $row['verified_by'] = null;
            $row['verified_at'] = null;
        }
        if ($data['report_status'] === 'published' && (!$existing || $existing['status'] !== 'published')) {
            $row['published_at'] = date('Y-m-d H:i:s');
        }
        if ($existing) {
            db_update('event_reports', $row, 'id = :id', ['id' => (int) $existing['id']]);
        } else {
            db_insert('event_reports', $row + ['event_id' => $id, 'created_by' => admin_id()]);
        }
        admin_log('event_report_saved', 'Event #' . $id . ' report saved (' . $data['report_status'] . ($hasFigures ? ', figures ' . ($verified ? 'verified' : 'not verified') : '') . ($pv !== null ? ', participants ' . $pv : '') . ')');
        flash('success', 'Report saved' . ($data['report_status'] === 'published' ? ' and published on the event page.' : ' as a draft.'));
        admin_redirect($back);
    }

    // ----- Save the event ----------------------------------------------
    if ($action !== 'save') {
        admin_redirect($tabSelf);
    }
    [$data, $errors] = validate($_POST, [
        'title' => 'required|min:3|max:200',
        'slug' => 'max:200',
        'event_type' => 'required|in:' . implode(',', array_keys(EVENT_TYPES)),
        'category_id' => 'integer',
        'program_id' => 'integer',
        'summary' => 'required|text|max:300',
        'start_datetime' => 'required|datetime',
        'end_datetime' => 'datetime',
        'mode' => 'in:in_person,online,hybrid',
        'location' => 'max:200',
        'venue_address' => 'max:300',
        'map_url' => 'max:500',
        'meeting_url' => 'max:500',
        'meeting_info' => 'text|max:500',
        'capacity' => 'integer|min:1|max:100000',
        'registration_opens_at' => 'datetime',
        'registration_deadline' => 'datetime',
        'eligibility' => 'text|max:2000',
        'team_size_min' => 'integer|min:1|max:50',
        'team_size_max' => 'integer|min:1|max:50',
        'fee_amount' => 'numeric|min:0|max:10000000',
        'fee_note' => 'max:255',
        'certificate_note' => 'max:300',
        'organising_team' => 'text|max:2000',
        'contact_email' => 'email|max:190',
        'contact_phone' => 'phone',
        'status' => 'required|in:' . implode(',', EVENT_STATUSES),
        'publish_at' => 'datetime',
        'meta_title' => 'max:200',
        'meta_description' => 'text|max:300',
    ], ['event_type' => 'Type', 'category_id' => 'Category', 'program_id' => 'Programme', 'start_datetime' => 'Start', 'end_datetime' => 'End',
        'registration_opens_at' => 'Registration opens', 'registration_deadline' => 'Registration deadline', 'team_size_min' => 'Minimum team size',
        'team_size_max' => 'Maximum team size', 'fee_amount' => 'Participation fee', 'map_url' => 'Google Maps link', 'meeting_url' => 'Meeting link',
        'publish_at' => 'Publish at', 'meta_title' => 'Search title', 'meta_description' => 'Search description']);
    $isAwareness = $data['event_type'] === 'awareness_day';
    $mode = $isAwareness ? 'in_person' : ($data['mode'] !== '' ? $data['mode'] : 'in_person');
    $description = clean_html((string) ($_POST['description'] ?? ''));
    $guidelines = clean_html((string) ($_POST['guidelines'] ?? ''));
    $programId = $data['program_id'] !== '' ? (int) $data['program_id'] : null;
    if ($programId && !isset($programs[$programId])) {
        $errors['program_id'] = 'Please choose a valid programme.';
    }
    $categoryId = $data['category_id'] !== '' && (int) $data['category_id'] > 0 ? (int) $data['category_id'] : null;
    if ($categoryId && !in_array($categoryId, $catIds, true)) {
        $errors['category_id'] = 'Please choose a valid category.';
    }
    $slugIn = strtolower($data['slug']);
    if ($slugIn !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugIn)) {
        $errors['slug'] = 'Use lower-case letters, numbers and single hyphens only.';
    }
    $mapUrl = programhub_admin_https($data['map_url'], $err);
    if ($err) {
        $errors['map_url'] = 'The Google Maps link ' . $err;
    }
    $meetingUrl = $isAwareness ? '' : programhub_admin_https($data['meeting_url'], $err);
    if (!$isAwareness && $err) {
        $errors['meeting_url'] = 'The meeting link ' . $err;
    }
    $location = $data['location'];
    if ($location === '' && $mode === 'online') {
        $location = 'Online';
    }
    if ($location === '') {
        $errors['location'] = $mode === 'hybrid' ? 'Enter the venue (people can also join online).' : 'Location is required.';
    }
    $allDay = !empty($_POST['all_day']);
    $start = $data['start_datetime'];
    $end = $data['end_datetime'] !== '' ? $data['end_datetime'] : null;
    if (!isset($errors['start_datetime']) && $allDay) {
        $start = substr($start, 0, 10) . ' 00:00:00';
        $end = substr($end ?: $start, 0, 10) . ' 23:59:00';
    }
    if (!isset($errors['start_datetime']) && !isset($errors['end_datetime']) && $end && strtotime($end) < strtotime($start)) {
        $errors['end_datetime'] = 'The end must be after the start.';
    }
    $regEnabled = !$isAwareness && !empty($_POST['registration_enabled']);
    $opens = !$isAwareness && $data['registration_opens_at'] !== '' ? $data['registration_opens_at'] : null;
    $deadline = !$isAwareness && $data['registration_deadline'] !== '' ? $data['registration_deadline'] : null;
    if ($deadline && !isset($errors['start_datetime']) && strtotime($deadline) > strtotime($start)) {
        $errors['registration_deadline'] = 'The registration deadline must be before the event starts.';
    }
    if ($opens && $deadline && strtotime($opens) >= strtotime($deadline)) {
        $errors['registration_opens_at'] = 'Registration must open before the deadline.';
    }
    $tMin = $data['team_size_min'] !== '' ? (int) $data['team_size_min'] : null;
    $tMax = $data['team_size_max'] !== '' ? (int) $data['team_size_max'] : null;
    if ($tMin && $tMax && $tMin > $tMax) {
        $errors['team_size_max'] = 'The maximum team size must be at least the minimum.';
    }
    $status = $data['status'];
    $publishAt = $data['publish_at'] !== '' ? $data['publish_at'] : null;
    if ($status === 'scheduled' && !$publishAt) {
        $errors['publish_at'] = 'Choose when the event should be published.';
    }
    $agenda = $isAwareness ? [] : programhub_admin_rows($_POST['agenda'] ?? [], ['time' => 40, 'title' => 200, 'speaker' => 120, 'text' => 600], 40, ['text']);
    foreach ($agenda as $i => $a) {
        if ($a['title'] === '') {
            $errors['agenda'] = 'Agenda item ' . ($i + 1) . ' needs a title.';
            break;
        }
    }
    $faqs = $isAwareness ? [] : programhub_admin_rows($_POST['faqs'] ?? [], ['q' => 250, 'a' => 1500], 20, ['a']);
    foreach ($faqs as $i => $f) {
        if ($f['q'] === '' || $f['a'] === '') {
            $errors['faqs'] = 'FAQ ' . ($i + 1) . ' needs both a question and an answer.';
            break;
        }
    }
    $speakerIds = array_map('intval', array_column($speakerList, 'id'));
    $partnerIds = array_map('intval', array_column($partnerList, 'id'));
    $speakers = [];
    foreach ($isAwareness ? [] : programhub_admin_rows($_POST['speakers'] ?? [], ['speaker_id' => 12, 'role' => 20, 'session_title' => 200], 30) as $s) {
        $sid = (int) $s['speaker_id'];
        if (!$sid) {
            continue;
        }
        if (!in_array($sid, $speakerIds, true) || !isset(SPEAKER_ROLES[$s['role']])) {
            $errors['speakers'] = 'Please choose a speaker and a role in every row.';
            break;
        }
        $speakers[$sid] = ['id' => $sid, 'role' => $s['role'], 'session_title' => $s['session_title']];
    }
    $partners = [];
    foreach ($isAwareness ? [] : programhub_admin_rows($_POST['partners'] ?? [], ['partner_id' => 12, 'role' => 40], 30) as $s) {
        $pid = (int) $s['partner_id'];
        if (!$pid) {
            continue;
        }
        if (!in_array($pid, $partnerIds, true) || !isset(EVENT_PARTNER_ROLES[$s['role']])) {
            $errors['partners'] = 'Please choose a partner and a role in every row.';
            break;
        }
        $partners[$pid] = ['id' => $pid, 'role' => $s['role']];
    }
    if ($errors) {
        back_with_errors($errors, 'admin/' . $self, $_POST);
    }

    [$uploaded, $err] = programhub_admin_upload_image('image', 'events', $data['title']);
    if ($err) {
        back_with_errors(['image' => $err], 'admin/' . $self, $_POST);
    }
    $image = $event['image'] ?? null;
    $oldImage = $image;
    if ($uploaded) {
        $image = $uploaded;
    } elseif (!empty($_POST['remove_image'])) {
        $image = null;
    }

    $notes = [];
    if ($status === 'scheduled' && $publishAt && strtotime($publishAt) <= time()) {
        $status = 'published';
        $notes[] = 'The publish time has already passed, so the event was published now.';
    }
    $fee = $data['fee_amount'] !== '' && (float) $data['fee_amount'] > 0 ? round((float) $data['fee_amount'], 2) : null;
    $row = [
        'title' => $data['title'],
        'slug' => unique_slug('events', $slugIn !== '' ? $slugIn : $data['title'] . ($isAwareness ? ' ' . substr($start, 0, 4) : ''), $id),
        'event_type' => $data['event_type'], 'category_id' => $isAwareness ? null : $categoryId, 'program_id' => $programId,
        'summary' => $data['summary'], 'description' => $description !== '' ? $description : '<p>' . e($data['summary']) . '</p>',
        'image' => $image, 'location' => $location, 'start_datetime' => $start, 'end_datetime' => $end,
        'mode' => $mode, 'venue_address' => $mode !== 'online' && $data['venue_address'] !== '' ? $data['venue_address'] : null,
        'map_url' => $mode !== 'online' && $mapUrl !== '' ? $mapUrl : null,
        'meeting_url' => $meetingUrl !== '' ? $meetingUrl : null, 'meeting_info' => !$isAwareness && $data['meeting_info'] !== '' ? $data['meeting_info'] : null,
        'registration_enabled' => $regEnabled ? 1 : 0,
        'capacity' => !$isAwareness && $data['capacity'] !== '' ? (int) $data['capacity'] : null,
        'waitlist_enabled' => !$isAwareness && !empty($_POST['waitlist_enabled']) ? 1 : 0,
        'registration_opens_at' => $opens, 'registration_deadline' => $deadline,
        'eligibility' => !$isAwareness && $data['eligibility'] !== '' ? $data['eligibility'] : null,
        'team_size_min' => in_array($data['event_type'], HACKATHON_EVENT_TYPES, true) ? $tMin : null,
        'team_size_max' => in_array($data['event_type'], HACKATHON_EVENT_TYPES, true) ? $tMax : null,
        'fee_amount' => $isAwareness ? null : $fee, 'fee_note' => !$isAwareness && $data['fee_note'] !== '' ? $data['fee_note'] : null,
        'certificate_confirmed' => !$isAwareness && !empty($_POST['certificate_confirmed']) ? 1 : 0,
        'certificate_note' => !$isAwareness && $data['certificate_note'] !== '' ? $data['certificate_note'] : null,
        'organising_team' => !$isAwareness && $data['organising_team'] !== '' ? $data['organising_team'] : null,
        'agenda' => programhub_admin_json($agenda), 'guidelines' => !$isAwareness && $guidelines !== '' ? $guidelines : null,
        'faqs' => programhub_admin_json($faqs),
        'contact_email' => !$isAwareness && $data['contact_email'] !== '' ? $data['contact_email'] : null,
        'contact_phone' => !$isAwareness && $data['contact_phone'] !== '' ? $data['contact_phone'] : null,
        'status' => $status, 'publish_at' => $status === 'scheduled' ? $publishAt : null,
        'meta_title' => $data['meta_title'] !== '' ? $data['meta_title'] : null, 'meta_description' => $data['meta_description'] !== '' ? $data['meta_description'] : null,
    ];
    if (column_exists('events', 'cancel_reason')) {
        if ($status === 'cancelled' && (!$event || $event['status'] !== 'cancelled')) {
            $row['cancelled_at'] = date('Y-m-d H:i:s');
        } elseif ($status !== 'cancelled' && $event && $event['status'] === 'cancelled') {
            $row['cancelled_at'] = null;
            $row['cancel_reason'] = null;
        }
    }
    $statusChanged = $event && $event['status'] !== $status;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($event) {
            db_update('events', $row, 'id = :id', ['id' => $id]);
        } else {
            $row['created_by'] = admin_id();
            $id = db_insert('events', $row);
        }
        if (!$isAwareness || $event) {
            programhub_admin_save_links($id, 'speakers', array_values($speakers));
            programhub_admin_save_links($id, 'partners', array_values($partners));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        delete_upload($uploaded);
        throw $e;
    }
    if ($event) {
        admin_log('event_updated', $label($event) . ' saved (' . $status . ($statusChanged ? ', was ' . $event['status'] : '') . ')');
        if ($moved = programhub_admin_event_moved($event, ['id' => $id] + $row)) {
            $notes[] = $moved;
        }
    } else {
        admin_log('event_created', ($isAwareness ? 'Awareness day' : 'Event') . ' #' . $id . ' "' . $data['title'] . '" created (' . $status . ')');
    }
    if ($oldImage && $oldImage !== $image) {
        content_delete_image($oldImage);
    }
    programhub_refresh_publish_schedule();

    // places freed by a larger capacity go to the waiting list (people are emailed)
    $fresh = db_row('SELECT * FROM events WHERE id = ?', [$id]);
    $promoted = 0;
    if ($event && $fresh['status'] === 'published' && !event_is_past($fresh) && (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status = 'waitlisted'", [$id]) > 0) {
        for ($i = 0; $i < 500; $i++) {
            $pr = programhub_promote_waitlist($id);
            if (!$pr) {
                break;
            }
            $promoted++;
            $extra = in_array(event_mode($fresh), ['online', 'hybrid'], true) ? ['token' => programhub_issue_access_token((int) $pr['id'])] : [];
            programhub_send_participant_email($fresh, $pr, 'promoted', $extra);
            admin_log('event_waitlist_promoted', 'Event #' . $id . ' registration #' . (int) $pr['id'] . ' moved from the waiting list (capacity change)');
        }
    }
    if ($promoted) {
        $notes[] = $promoted . ' ' . ($promoted === 1 ? 'person was' : 'people were') . ' moved from the waiting list into the free places and emailed.';
    }
    $active = event_registered_count($id);
    if ($statusChanged && $status === 'cancelled' && $active) {
        $notes[] = $active . ' people hold a place - use "Cancel this event" next time to email them, or write to them from the registrations page.';
    } elseif ($row['capacity'] !== null && $active > $row['capacity']) {
        $notes[] = 'There are already ' . $active . ' registrations - more than the capacity of ' . $row['capacity'] . '. Existing registrations are kept.';
    }
    if (in_array($mode, ['online', 'hybrid'], true) && !$isAwareness && $meetingUrl === '' && in_array($status, ['published', 'scheduled'], true)) {
        $notes[] = 'Remember to add the private online joining link before the session.';
    }
    if ($status === 'scheduled') {
        $notes[] = 'It will be published automatically on ' . date('d M Y \a\t h:i A', strtotime((string) $publishAt)) . '.';
    }
    flash($notes ? 'info' : 'success', ($isAwareness ? 'Awareness day' : 'Event') . ' saved (' . $status . ').' . ($notes ? ' ' . implode(' ', $notes) : ''));
    admin_redirect('event-edit.php?id=' . $id);
}

// =====================================================================
// View
// =====================================================================
$failed = old('title', null) !== null;
$v = function (string $key, $default = '') use ($event) {
    return old($key, $event[$key] ?? $default);
};
$presetCat = !$event ? (int) ($_GET['category'] ?? 0) : 0;
$presetCatRow = $presetCat ? program_category_by_id($presetCat) : null;
$typeDefault = isset(EVENT_TYPES[$_GET['type'] ?? '']) ? (string) $_GET['type'] : ($presetCatRow['default_event_type'] ?? 'workshop');
if (!isset(EVENT_TYPES[$typeDefault])) {
    $typeDefault = 'workshop';
}
$type = (string) $v('event_type', $typeDefault);
$isAwareness = $type === 'awareness_day';
$checked = function (string $key, bool $default) use ($failed, $event): string {
    if ($failed) {
        return old($key, null) !== null ? ' checked' : '';
    }
    return ($event ? (int) ($event[$key] ?? 0) === 1 : $default) ? ' checked' : '';
};
$allDayChecked = $failed ? old('all_day', null) !== null : ($event ? event_is_all_day($event) : $isAwareness);
$rowsFrom = function (string $key, array $fromDb): array {
    $posted = old($key, null);
    return is_array($posted) ? array_values(array_filter($posted, function ($r) { return is_array($r) && empty($r['_remove']); })) : $fromDb;
};
$counts = $event ? programhub_admin_reg_counts($id) : array_fill_keys(array_keys(EVENT_REG_STATUSES), 0);
$registered = $counts['registered'] + $counts['confirmed'] + $counts['attended'] + $counts['no_show'];
$isPublic = $event && in_array($event['status'], ['published', 'cancelled'], true);
$canPeople = admin_can('events.registrations');
$photoCount = $event ? (int) db_value('SELECT COUNT(*) FROM gallery_items WHERE event_id = ?', [$id]) : 0;
$resCount = $event ? (int) db_value('SELECT COUNT(*) FROM event_resources WHERE event_id = ?', [$id]) : 0;
$report = $event ? db_row('SELECT * FROM event_reports WHERE event_id = ?', [$id]) : null;
$tabLink = function (string $t) use ($id): string {
    return admin_url('event-edit.php?id=' . $id . ($t !== 'details' ? '&tab=' . $t : ''));
};

$speakerOptions = ['' => 'Choose a speaker / trainer'];
foreach ($speakerList as $s) {
    $speakerOptions[(string) $s['id']] = $s['name'] . ($s['designation'] ? ' - ' . $s['designation'] : '') . ((int) $s['profile_consent'] ? '' : ' (no consent: not shown)') . ($s['status'] === 'hidden' ? ' (hidden)' : '');
}
$partnerOptions = ['' => 'Choose a partner'];
foreach ($partnerList as $pt) {
    $partnerOptions[(string) $pt['id']] = $pt['name'] . ((int) $pt['display_consent'] ? '' : ' (no permission: not shown)') . ($pt['status'] === 'hidden' ? ' (hidden)' : '');
}
$speakerRows = $rowsFrom('speakers', $event ? array_map(function ($r) {
    return ['speaker_id' => (string) $r['id'], 'role' => $r['role'], 'session_title' => (string) $r['session_title']];
}, programhub_event_speakers($id, false)) : []);
$partnerRows = $rowsFrom('partners', $event ? array_map(function ($r) {
    return ['partner_id' => (string) $r['id'], 'role' => $r['role']];
}, programhub_event_partners($id, false)) : []);
$agendaRows = $rowsFrom('agenda', $event ? programhub_json_list($event['agenda']) : []);
$faqRows = $rowsFrom('faqs', $event ? programhub_json_list($event['faqs']) : []);

$title = $event ? ($event['event_type'] === 'awareness_day' ? 'Edit awareness day' : 'Edit event') : ($isAwareness ? 'New awareness day' : 'New event');
admin_page_start($title, array_values(array_filter([
    ['label' => 'All events', 'url' => admin_url('events.php' . ($isAwareness ? '?tab=awareness' : '')), 'icon' => 'list', 'style' => 'ghost'],
    $event && !$isAwareness && $canPeople ? ['label' => 'Registrations (' . $registered . ($counts['waitlisted'] ? ' + ' . $counts['waitlisted'] . ' waiting' : '') . ')', 'url' => admin_url('event-registrations.php?event=' . $id), 'icon' => 'users', 'style' => 'ghost'] : null,
])));
?>
<div class="ph-admin">
<?php if ($event): ?>
    <div class="ph-status-line">
        <?= programhub_status_badge(['registered' => $registered] + $event) ?><?= event_is_past($event) ? ' <span class="badge badge-gray">Past</span>' : '' ?>
        <span><?= $isPublic ? 'Live at' : 'Address when published:' ?> <a href="<?= e(event_url($event)) ?>" target="_blank" rel="noopener"><?= e(event_abs_url($event)) ?></a></span>
        <?php if ($event['status'] === 'scheduled' && $event['publish_at']): ?><span><?= icon('clock') ?> Publishes <?= e(format_date($event['publish_at'], 'd M Y, h:i A')) ?></span><?php endif; ?>
        <?php if ($event['status'] === 'cancelled' && !empty($event['cancel_reason'])): ?><span class="muted">Reason: <?= e($event['cancel_reason']) ?></span><?php endif; ?>
    </div>
    <?php if (!$isAwareness): ?>
        <nav class="admin-tabs" aria-label="Event sections">
            <a class="<?= $tab === 'details' ? 'active' : '' ?>" href="<?= e($tabLink('details')) ?>"<?= $tab === 'details' ? ' aria-current="page"' : '' ?>><?= icon('edit') ?>Details</a>
            <a class="<?= $tab === 'media' ? 'active' : '' ?>" href="<?= e($tabLink('media')) ?>"<?= $tab === 'media' ? ' aria-current="page"' : '' ?>><?= icon('image') ?>Photos &amp; downloads <em><?= $photoCount + $resCount ?></em></a>
            <a class="<?= $tab === 'report' ? 'active' : '' ?>" href="<?= e($tabLink('report')) ?>"<?= $tab === 'report' ? ' aria-current="page"' : '' ?>><?= icon('file-text') ?>Report &amp; outcomes<?= $report && $report['status'] === 'published' ? ' <em>published</em>' : '' ?></a>
            <?php if ($canPeople): ?><a href="<?= e(admin_url('event-registrations.php?event=' . $id)) ?>"><?= icon('users') ?>Registrations <em><?= $registered ?></em></a><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'details'): ?>
<form method="post" enctype="multipart/form-data" action="<?= e(admin_url($self)) ?>" data-validate data-ph-form>
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_MB * 1024 * 1024 ?>">
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="panel-head"><h2><?= icon('edit') ?>Basics</h2></div>
                <div class="form-grid">
                    <div class="form-group full"><label for="title">Title<span class="req">*</span></label>
                        <input type="text" id="title" name="title" required minlength="3" maxlength="200" value="<?= e($v('title')) ?>" data-slug-source="#slug"><?= field_error('title') ?></div>
                    <div class="form-group full"><label for="slug">Web address (slug)</label>
                        <input type="text" id="slug" name="slug" maxlength="200" value="<?= e($v('slug')) ?>" <?= $event ? 'data-touched="1"' : '' ?>>
                        <span class="help">/events/<strong>this-part</strong>/ (hackathons and innovation challenges: /hackathons/<strong>this-part</strong>/). Leave empty to create it from the title.<?= $isPublic ? ' Changing it adds a redirect from the old address.' : '' ?></span><?= field_error('slug') ?></div>
                    <div class="form-group full"><label for="summary">Short summary<span class="req">*</span></label>
                        <textarea id="summary" name="summary" required maxlength="300" rows="3" data-counter="300" style="min-height:84px"><?= e($v('summary')) ?></textarea>
                        <span class="help">One or two sentences for cards, the calendar and search results.</span><?= field_error('summary') ?></div>
                    <div class="form-group full"><label for="description">Full description</label>
                        <textarea id="description" name="description" rows="12" data-editor data-upload-url="<?= e(admin_url('event-edit.php?upload=1' . ($event ? '&id=' . $id : ''))) ?>"><?= e($v('description')) ?></textarea>
                        <span class="help">What will happen and who it is for. Only confirmed facts - no invented speakers, partners or numbers.</span><?= field_error('description') ?></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2><?= icon('calendar') ?>Date, time &amp; place</h2></div>
                <div class="form-grid">
                    <div class="form-group"><label for="start_datetime">Starts<span class="req">*</span></label>
                        <input id="start_datetime" type="datetime-local" name="start_datetime" required value="<?= e(content_dt_input((string) $v('start_datetime'))) ?>"><?= field_error('start_datetime') ?></div>
                    <div class="form-group"><label for="end_datetime">Ends</label>
                        <input id="end_datetime" type="datetime-local" name="end_datetime" value="<?= e(content_dt_input((string) $v('end_datetime'))) ?>"><?= field_error('end_datetime') ?></div>
                    <div class="form-group full"><label class="checkbox"><input type="checkbox" name="all_day" value="1"<?= $allDayChecked ? ' checked' : '' ?>><span>All-day (times are ignored; shown as a date)</span></label></div>
                    <div class="form-group" data-ph-unless="event_type=awareness_day"><label for="mode">Format</label>
                        <select id="mode" name="mode"><?= options_html(EVENT_MODES, (string) $v('mode', 'in_person'), true, '') ?></select>
                        <span class="help">Online and hybrid events get a private joining link for registered participants.</span><?= field_error('mode') ?></div>
                    <div class="form-group"><label for="location">Venue / location</label>
                        <input type="text" id="location" name="location" maxlength="200" value="<?= e($v('location', $isAwareness ? 'India' : '')) ?>" placeholder="Venue name, area, city - or Online">
                        <span class="help">Shown on cards. For online events "Online" is used when empty.</span><?= field_error('location') ?></div>
                    <div class="form-group full" data-ph-when="mode=in_person|hybrid" data-ph-unless="event_type=awareness_day"><label for="venue_address">Full venue address</label>
                        <input type="text" id="venue_address" name="venue_address" maxlength="300" value="<?= e($v('venue_address')) ?>" placeholder="Building, street, area, city, PIN"><?= field_error('venue_address') ?></div>
                    <div class="form-group full" data-ph-when="mode=in_person|hybrid" data-ph-unless="event_type=awareness_day"><label for="map_url">Google Maps link</label>
                        <input type="url" id="map_url" name="map_url" maxlength="500" value="<?= e($v('map_url')) ?>" placeholder="https://maps.app.goo.gl/…">
                        <span class="help">Optional. Without it visitors get a Maps search for the venue.</span><?= field_error('map_url') ?></div>
                </div>
            </section>

            <section class="panel ph-private" data-ph-when="mode=online|hybrid" data-ph-unless="event_type=awareness_day">
                <div class="panel-head"><h2><?= icon('lock') ?>Online joining details (private)</h2></div>
                <p class="ph-private-note"><?= icon('lock') ?><span>Never shown on the website, in search results, the calendar file or the sitemap. Registered participants receive it by email and on their private joining page.<?= $event && $canPeople ? ' Send it from <a href="' . e(admin_url('event-registrations.php?event=' . $id . '#joining')) . '">Registrations</a>.' : '' ?></span></p>
                <div class="form-grid">
                    <div class="form-group full"><label for="meeting_url">Meeting link</label>
                        <input type="url" id="meeting_url" name="meeting_url" maxlength="500" value="<?= e($v('meeting_url')) ?>" placeholder="https://meet.google.com/… or https://zoom.us/j/…" autocomplete="off"><?= field_error('meeting_url') ?></div>
                    <div class="form-group full"><label for="meeting_info">Joining instructions</label>
                        <textarea id="meeting_info" name="meeting_info" maxlength="500" rows="3" placeholder="Meeting ID, passcode, what to keep ready"><?= e($v('meeting_info')) ?></textarea><?= field_error('meeting_info') ?></div>
                </div>
            </section>

            <section class="panel" data-ph-unless="event_type=awareness_day">
                <div class="panel-head"><h2><?= icon('users') ?>Registration</h2></div>
                <div class="form-grid">
                    <div class="form-group full"><label class="checkbox"><input type="checkbox" name="registration_enabled" value="1"<?= $checked('registration_enabled', !$isAwareness) ?>><span>Accept registrations on the website</span></label></div>
                    <div class="form-group" data-ph-when="registration_enabled=1"><label for="capacity">Seat limit</label>
                        <input id="capacity" type="number" name="capacity" min="1" max="100000" step="1" value="<?= e((string) $v('capacity')) ?>" placeholder="No limit"><?= field_error('capacity') ?></div>
                    <div class="form-group site-check" data-ph-when="registration_enabled=1"><label class="checkbox"><input type="checkbox" name="waitlist_enabled" value="1"<?= $checked('waitlist_enabled', false) ?>><span>Waiting list when full</span></label>
                        <span class="help">Freed or added places go to the waiting list in order; people are emailed.</span></div>
                    <div class="form-group" data-ph-when="registration_enabled=1"><label for="registration_opens_at">Registration opens</label>
                        <input id="registration_opens_at" type="datetime-local" name="registration_opens_at" value="<?= e(content_dt_input((string) $v('registration_opens_at'))) ?>"><span class="help">Empty = as soon as the event is published.</span><?= field_error('registration_opens_at') ?></div>
                    <div class="form-group" data-ph-when="registration_enabled=1"><label for="registration_deadline">Registration deadline</label>
                        <input id="registration_deadline" type="datetime-local" name="registration_deadline" value="<?= e(content_dt_input((string) $v('registration_deadline'))) ?>"><span class="help">Empty = until the event starts.</span><?= field_error('registration_deadline') ?></div>
                    <div class="form-group full"><label for="eligibility">Eligibility</label>
                        <textarea id="eligibility" name="eligibility" maxlength="2000" rows="3" placeholder="e.g. Students of classes 9-12 and college students. Participants under 18 need a parent's or guardian's consent."><?= e($v('eligibility')) ?></textarea><?= field_error('eligibility') ?></div>
                    <div class="form-group" data-ph-when="event_type=hackathon|innovation_challenge"><label for="team_size_min">Team size (minimum)</label>
                        <input id="team_size_min" type="number" name="team_size_min" min="1" max="50" value="<?= e((string) $v('team_size_min')) ?>"><?= field_error('team_size_min') ?></div>
                    <div class="form-group" data-ph-when="event_type=hackathon|innovation_challenge"><label for="team_size_max">Team size (maximum)</label>
                        <input id="team_size_max" type="number" name="team_size_max" min="1" max="50" value="<?= e((string) $v('team_size_max')) ?>"><?= field_error('team_size_max') ?></div>
                    <div class="form-group"><label for="fee_amount">Participation fee (₹)</label>
                        <input id="fee_amount" type="number" name="fee_amount" min="0" step="0.01" value="<?= e((string) $v('fee_amount')) ?>" placeholder="Free">
                        <span class="help">Shown for information only - fees are never collected through the website.</span><?= field_error('fee_amount') ?></div>
                    <div class="form-group"><label for="fee_note">Fee note</label>
                        <input id="fee_note" type="text" name="fee_note" maxlength="255" value="<?= e($v('fee_note')) ?>" placeholder="e.g. Covers study material; payable at the venue"><?= field_error('fee_note') ?></div>
                </div>
                <?php if ($event && $canPeople): ?><p class="mt-2 mb-0 small"><a href="<?= e(admin_url('event-registrations.php?event=' . $id)) ?>"><?= icon('users') ?> <?= $registered ?> registered<?= $event['capacity'] ? ' of ' . (int) $event['capacity'] : '' ?><?= $counts['waitlisted'] ? ', ' . $counts['waitlisted'] . ' waiting' : '' ?> - manage</a></p><?php endif; ?>
            </section>

            <section class="panel" data-ph-unless="event_type=awareness_day">
                <div class="panel-head"><h2><?= icon('list') ?>Agenda, guidelines &amp; materials</h2></div>
                <div class="form-grid">
                    <div class="form-group full"><span class="label">Agenda / session schedule</span>
                        <?= site_repeater_html('agenda', 'agenda', ['time' => ['text', 'Time', 'e.g. 10:00 AM or Day 1, 10:00'], 'title' => ['text', 'Session'], 'speaker' => ['text', 'Speaker / trainer (optional)'], 'text' => ['textarea', 'Details (optional)']], $agendaRows, '', 'Agenda item', $agendaRows ? 0 : 1) ?><?= field_error('agenda') ?></div>
                    <div class="form-group full"><label for="guidelines">Rules, guidelines &amp; what to bring</label>
                        <textarea id="guidelines" name="guidelines" rows="8" data-editor><?= e($v('guidelines')) ?></textarea>
                        <span class="help">Rules (e.g. for a hackathon), code of conduct, materials or devices to bring. Downloads are added in "Photos &amp; downloads".</span></div>
                    <div class="form-group full"><label for="organising_team">Organising team</label>
                        <textarea id="organising_team" name="organising_team" maxlength="2000" rows="3" placeholder="e.g. Organised by the Prerit Welfare Foundation youth team"><?= e($v('organising_team')) ?></textarea><?= field_error('organising_team') ?></div>
                </div>
            </section>

            <section class="panel" id="people" data-ph-unless="event_type=awareness_day">
                <div class="panel-head"><h2><?= icon('user') ?>Speakers, trainers &amp; partners</h2></div>
                <p class="muted small">Only people and organisations who agreed to be shown appear on the event page. <a href="<?= e(admin_url('speakers.php?id=new')) ?>">Add a speaker</a> · <a href="<?= e(admin_url('partners.php?id=new')) ?>">Add a partner</a></p>
                <div class="form-grid">
                    <div class="form-group full"><span class="label">Speakers &amp; trainers</span>
                        <?php if ($speakerList): ?>
                            <?= site_repeater_html('speakers', 'speakers', ['speaker_id' => ['select', 'Person', $speakerOptions], 'role' => ['select', 'Role', SPEAKER_ROLES], 'session_title' => ['text', 'Session (optional)']], $speakerRows, '', 'Speaker', $speakerRows ? 0 : 1) ?>
                        <?php else: ?><p class="muted small mb-0">No speaker profiles yet.</p><?php endif; ?>
                        <?= field_error('speakers') ?></div>
                    <div class="form-group full"><span class="label">Partners &amp; sponsors</span>
                        <?php if ($partnerList): ?>
                            <?= site_repeater_html('partners', 'partners', ['partner_id' => ['select', 'Organisation', $partnerOptions], 'role' => ['select', 'Role at this event', EVENT_PARTNER_ROLES]], $partnerRows, '', 'Partner', $partnerRows ? 0 : 1) ?>
                        <?php else: ?><p class="muted small mb-0">No partners yet.</p><?php endif; ?>
                        <?= field_error('partners') ?></div>
                </div>
            </section>

            <section class="panel" data-ph-unless="event_type=awareness_day">
                <div class="panel-head"><h2><?= icon('help-circle') ?>FAQs, certificate &amp; contact</h2></div>
                <div class="form-grid">
                    <div class="form-group full"><span class="label">Questions &amp; answers about this event</span>
                        <?= site_repeater_html('faqs', 'faqs', ['q' => ['text', 'Question'], 'a' => ['textarea', 'Answer']], $faqRows, '', 'Question', $faqRows ? 0 : 1) ?><?= field_error('faqs') ?></div>
                    <div class="form-group full"><label class="checkbox"><input type="checkbox" name="certificate_confirmed" value="1"<?= $checked('certificate_confirmed', false) ?>><span>A participation certificate is <strong>officially confirmed</strong> for this event</span></label>
                        <span class="help">Tick only when it is decided. The event page mentions certificates only when this is ticked; certificates go to participants marked as attended.</span></div>
                    <div class="form-group full" data-ph-when="certificate_confirmed=1"><label for="certificate_note">Certificate details</label>
                        <input type="text" id="certificate_note" name="certificate_note" maxlength="300" value="<?= e($v('certificate_note')) ?>" placeholder="e.g. Certificate of participation for attending all sessions"><?= field_error('certificate_note') ?></div>
                    <div class="form-group"><label for="contact_email">Public contact email</label>
                        <input type="email" id="contact_email" name="contact_email" maxlength="190" value="<?= e($v('contact_email')) ?>" placeholder="<?= e((string) setting('contact_email')) ?>"><?= field_error('contact_email') ?></div>
                    <div class="form-group"><label for="contact_phone">Public contact number</label>
                        <input type="tel" id="contact_phone" name="contact_phone" maxlength="20" value="<?= e($v('contact_phone')) ?>"><span class="help">Shown on the event page - use an official number.</span><?= field_error('contact_phone') ?></div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2><?= icon('search') ?>Search engines &amp; sharing</h2></div>
                <div class="form-grid">
                    <div class="form-group full"><label for="meta_title">Search title</label>
                        <input type="text" id="meta_title" name="meta_title" maxlength="200" data-counter="60" value="<?= e($v('meta_title')) ?>"><span class="help">Leave empty to use the title.</span><?= field_error('meta_title') ?></div>
                    <div class="form-group full"><label for="meta_description">Search description</label>
                        <textarea id="meta_description" name="meta_description" maxlength="300" rows="3" data-counter="160" style="min-height:80px"><?= e($v('meta_description')) ?></textarea><span class="help">Leave empty to use the summary.</span><?= field_error('meta_description') ?></div>
                </div>
            </section>
        </div>

        <div>
            <div class="ph-side-sticky">
                <section class="panel">
                    <div class="panel-head"><h2><?= icon('check-circle') ?>Publish</h2></div>
                    <div class="form-group mb-2"><label for="event_type">Type<span class="req">*</span></label>
                        <select id="event_type" name="event_type" required<?= !$event && !isset($_GET['type']) && !$failed ? ' data-follow-category="1"' : '' ?>><?= options_html(EVENT_TYPES, $type, true, '') ?></select><?= field_error('event_type') ?></div>
                    <div class="form-group mb-2" data-ph-unless="event_type=awareness_day"><label for="category_id">Category</label>
                        <select id="category_id" name="category_id"><option value="">None</option>
                            <?php $catSel = (string) $v('category_id', $presetCat ?: ''); foreach ($categories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" data-type="<?= e((string) $c['default_event_type']) ?>"<?= $catSel === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['title']) ?><?= $c['status'] !== 'published' ? ' (hidden)' : '' ?></option>
                            <?php endforeach; ?>
                        </select><span class="help">The event is listed on this category's page.</span><?= field_error('category_id') ?></div>
                    <div class="form-group mb-2"><label for="status">Status</label>
                        <select id="status" name="status">
                            <?php foreach (['draft' => 'Draft (not visible)', 'scheduled' => 'Scheduled (publish later)', 'published' => 'Published', 'cancelled' => 'Cancelled (shown as cancelled)', 'archived' => 'Archived (hidden)'] as $k => $lbl): ?>
                                <option value="<?= $k ?>"<?= (string) $v('status', 'draft') === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select><?= field_error('status') ?></div>
                    <div class="form-group mb-2" data-ph-when="status=scheduled"><label for="publish_at">Publish at</label>
                        <input id="publish_at" type="datetime-local" name="publish_at" value="<?= e(content_dt_input((string) $v('publish_at'))) ?>"><span class="help">The event goes live automatically at this time.</span><?= field_error('publish_at') ?></div>
                    <div class="form-group mb-2"><label for="program_id">Core programme (optional)</label>
                        <select id="program_id" name="program_id"><?= options_html($programs, (string) $v('program_id'), true, 'None') ?></select><?= field_error('program_id') ?></div>
                    <div class="form-actions" style="margin-top:14px">
                        <button class="btn btn-primary" type="submit"><?= icon('check') ?>Save</button>
                        <?php if ($event): ?><a class="btn btn-ghost" href="<?= e(event_url($event)) ?>" target="_blank" rel="noopener"><?= icon('eye') ?><?= $isPublic ? 'View' : 'Preview' ?></a><?php endif; ?>
                    </div>
                </section>
                <section class="panel">
                    <div class="panel-head"><h2><?= icon('image') ?>Banner / poster</h2></div>
                    <?php $img = (string) ($event['image'] ?? ''); ?>
                    <img id="img-preview" class="mb-2" src="<?= e($img !== '' ? image_url($img) : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7') ?>" alt="" width="640" height="400" style="width:100%;border-radius:10px;aspect-ratio:16/10;object-fit:cover;background:var(--green-50)"<?= $img !== '' ? '' : ' hidden' ?>>
                    <div class="form-group"><label for="image"><?= $img !== '' ? 'Replace image' : 'Upload image' ?></label>
                        <input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp" data-preview="#img-preview">
                        <span class="help">Optional banner or poster (JPG, PNG, WebP). Without one, the category illustration is used.</span><?= field_error('image') ?></div>
                    <?php if ($img !== ''): ?><label class="checkbox mt-1"><input type="checkbox" name="remove_image" value="1"><span>Remove the image</span></label><?php endif; ?>
                </section>
            </div>
        </div>
    </div>
</form>

<?php if ($event): ?>
    <?php if (!in_array($event['status'], ['cancelled', 'archived'], true) && !$isAwareness): ?>
        <?php $toNotify = $counts['registered'] + $counts['confirmed'] + $counts['waitlisted']; ?>
        <section class="panel ph-danger" id="cancel">
            <div class="panel-head"><h2><?= icon('x') ?>Cancel this event</h2></div>
            <form method="post" action="<?= e(admin_url($self)) ?>" data-confirm="<?= e('Cancel "' . $event['title'] . '"? It will be shown as cancelled and registration closes.') ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel_event">
                <div class="form-group mb-2"><label for="cancel_reason">Reason (shown with the event and in the email)</label>
                    <textarea id="cancel_reason" name="cancel_reason" maxlength="300" rows="2" placeholder="e.g. Due to heavy rain the session cannot take place. A new date will be announced."><?= e((string) old('cancel_reason')) ?></textarea><?= field_error('cancel_reason') ?></div>
                <?php if ($canPeople): ?>
                    <label class="checkbox mb-2"><input type="checkbox" name="notify_registrants" value="1"<?= $toNotify ? ' checked' : ' disabled' ?>><span>Email the <?= $toNotify ?> registered and waiting participant<?= $toNotify === 1 ? '' : 's' ?></span></label>
                <?php elseif ($toNotify): ?>
                    <p class="muted small"><?= $toNotify ?> people are registered. A coordinator with access to registrations can email them.</p>
                <?php endif; ?>
                <button class="btn btn-danger btn-sm" type="submit"><?= icon('x') ?>Cancel event</button>
            </form>
        </section>
    <?php endif; ?>
    <section class="panel">
        <div class="panel-head"><h2><?= icon('settings') ?>More actions</h2></div>
        <div class="ph-actions-row">
            <form method="post" action="<?= e(admin_url($self)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate">
                <button class="btn btn-ghost btn-sm" type="submit"><?= icon('copy') ?>Duplicate as draft</button></form>
            <form method="post" action="<?= e(admin_url($self)) ?>" data-confirm="<?= e('Delete this ' . ($isAwareness ? 'awareness day' : 'event') . (($registered + $counts['waitlisted'] + $counts['cancelled']) ? ' and its ' . ($registered + $counts['waitlisted'] + $counts['cancelled']) . ' registrations' : '') . ' permanently? Archiving keeps the record instead.') ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Delete</button></form>
        </div>
        <p class="muted small mt-2 mb-0">Duplicating copies every field, speakers and partners (not registrations, photos, downloads or the report). To hide an event but keep its record, set the status to <strong>Archived</strong>.</p>
    </section>
<?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'media'):
    $photos = db_rows('SELECT * FROM gallery_items WHERE event_id = ? ORDER BY sort_order, id DESC', [$id]);
    $resources = programhub_event_resources($id, 'all');
    $unlinked = db_rows('SELECT id, title, media_type, created_at FROM gallery_items WHERE event_id IS NULL ORDER BY id DESC LIMIT 200');
    $resForm = (string) old('res_form', '');
    ?>
    <section class="panel" id="photos">
        <div class="panel-head"><div><h2><?= icon('image') ?>Event photos</h2><p class="muted small">Shown in the event's gallery (and the main gallery under "Events") when published with the consent confirmation.</p></div></div>
        <?php if ($photos): ?>
            <div class="ph-photos mb-3">
                <?php foreach ($photos as $g): $gid = (int) $g['id']; ?>
                    <div class="ph-photo">
                        <?php if ($g['media_type'] === 'image' && $g['image_path']): ?><img src="<?= e(image_url($g['image_path'])) ?>" alt="<?= e((string) $g['description']) ?>" loading="lazy" width="320" height="240">
                        <?php else: ?><div class="empty-state" style="aspect-ratio:4/3;margin:0;padding:12px"><?= icon('play') ?><p class="small">YouTube video</p></div><?php endif; ?>
                        <div class="ph-photo-body">
                            <strong><?= e($g['title']) ?></strong>
                            <div class="ph-chips"><?= status_badge($g['status']) ?><?php if (!(int) $g['consent_confirmed']): ?><span class="ph-chip is-warn"><?= icon('alert-triangle') ?>No consent confirmed</span><?php endif; ?></div>
                            <form method="post" action="<?= e(admin_url('event-edit.php?id=' . $id . '&tab=media')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="photo_toggle"><input type="hidden" name="gallery_id" value="<?= $gid ?>">
                                <?php if ($g['status'] !== 'published' && !(int) $g['consent_confirmed']): ?><label class="checkbox small"><input type="checkbox" name="photo_consent" value="1" required><span><?= e(PH_PHOTO_CONSENT) ?></span></label><?php endif; ?>
                                <button class="btn btn-ghost btn-sm" type="submit"><?= icon($g['status'] === 'published' ? 'eye' : 'check') ?><?= $g['status'] === 'published' ? 'Hide' : 'Publish' ?></button></form>
                            <div class="ph-photo-tools">
                                <form method="post" class="inline-form" action="<?= e(admin_url('event-edit.php?id=' . $id . '&tab=media')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="photo_unlink"><input type="hidden" name="gallery_id" value="<?= $gid ?>">
                                    <button class="icon-btn" type="submit" aria-label="Remove <?= e($g['title']) ?> from this event (keep in gallery)" title="Remove from event"><?= icon('x') ?></button></form>
                                <form method="post" class="inline-form" action="<?= e(admin_url('event-edit.php?id=' . $id . '&tab=media')) ?>" data-confirm="Delete this photo permanently?"><?= csrf_field() ?><input type="hidden" name="action" value="photo_delete"><input type="hidden" name="gallery_id" value="<?= $gid ?>">
                                    <button class="icon-btn danger" type="submit" aria-label="Delete <?= e($g['title']) ?>" title="Delete"><?= icon('trash') ?></button></form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">No photos for this event yet.</p>
        <?php endif; ?>
        <div class="admin-grid-2">
            <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('event-edit.php?id=' . $id . '&tab=media')) ?>" data-validate>
                <?= csrf_field() ?><input type="hidden" name="action" value="photo_upload"><input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_MB * 1024 * 1024 ?>">
                <h3 class="h5">Upload photos</h3>
                <div class="form-group mb-2"><label for="photo_files">Photos (JPG, PNG, WebP; several at once)</label>
                    <input id="photo_files" type="file" name="photos[]" accept=".jpg,.jpeg,.png,.webp" multiple required><?= field_error('photos') ?></div>
                <div class="form-group mb-2"><label for="photo_title">Title<span class="req">*</span></label>
                    <input id="photo_title" name="photo_title" required maxlength="150" value="<?= e((string) old('photo_title', $event['title'])) ?>"><?= field_error('photo_title') ?></div>
                <div class="form-group mb-2"><label for="photo_description">Description (alt text)<span class="req">*</span></label>
                    <input id="photo_description" name="photo_description" required minlength="5" maxlength="300" value="<?= e((string) old('photo_description')) ?>" placeholder="What the photo shows, e.g. Participants during the morning yoga session"><?= field_error('photo_description') ?></div>
                <label class="checkbox mb-2"><input type="checkbox" name="photo_consent" value="1"><span><?= e(PH_PHOTO_CONSENT) ?> - publish now</span></label>
                <button class="btn btn-primary btn-sm" type="submit"><?= icon('upload') ?>Upload</button>
            </form>
            <form method="post" action="<?= e(admin_url('event-edit.php?id=' . $id . '&tab=media')) ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="photo_link">
                <h3 class="h5">Use a photo from the gallery</h3>
                <?php if ($unlinked): ?>
                    <div class="form-group mb-2"><label for="gallery_id">Gallery item</label>
                        <select id="gallery_id" name="gallery_id" required><option value="">Choose…</option><?php foreach ($unlinked as $u): ?><option value="<?= (int) $u['id'] ?>">#<?= (int) $u['id'] ?> <?= e($u['title']) ?><?= $u['media_type'] === 'video' ? ' (video)' : '' ?></option><?php endforeach; ?></select></div>
                    <button class="btn btn-ghost btn-sm" type="submit"><?= icon('link') ?>Link to this event</button>
                <?php else: ?>
                    <p class="muted small">Every gallery item is already linked to an event.</p>
                <?php endif; ?>
            </form>
        </div>
    </section>

    <section class="panel" id="downloads">
        <div class="panel-head"><div><h2><?= icon('download') ?>Downloads &amp; promotional material</h2><p class="muted small">Posters, learning material, slides, rules or reports. "Registered participants only" files are stored privately and served only to registered participants (and staff).</p></div></div>
        <?php if ($resources): ?>
            <ul class="ph-file-list mb-3">
                <?php foreach ($resources as $r): $rid = (int) $r['id']; $open = $resForm === (string) $rid; $fv = function (string $k, $d) use ($open) { return $open ? old($k, $d) : $d; }; ?>
                    <li class="ph-file">
                        <span class="icon-circle<?= $r['visibility'] === 'registered' ? ' gold' : '' ?>"><?= icon($r['visibility'] === 'registered' ? 'lock' : 'file-text') ?></span>
                        <div><strong><?= e($r['title']) ?></strong>
                            <small><?= e(EVENT_RESOURCE_TYPES[$r['resource_type']] ?? 'File') ?> · <?= $r['visibility'] === 'registered' ? 'Registered participants only' : 'Public' ?> · <?= $r['status'] === 'published' ? 'Published' : 'Hidden' ?><?= $r['external_url'] ? ' · external link' : '' ?></small></div>
                        <div class="ph-photo-tools">
                            <a class="icon-btn" href="<?= e(admin_url('event-edit.php?id=' . $id . '&download=' . $rid)) ?>" aria-label="Open <?= e($r['title']) ?>" title="Open"<?= $r['external_url'] ? ' target="_blank" rel="noopener"' : '' ?>><?= icon('download') ?></a>
                            <form method="post" class="inline-form" action="<?= e(admin_url('event-edit.php?id=' . $id . '&tab=media')) ?>" data-confirm="<?= e('Delete "' . $r['title'] . '"?') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="resource_delete"><input type="hidden" name="resource_id" value="<?= $rid ?>">
                                <button class="icon-btn danger" type="submit" aria-label="Delete <?= e($r['title']) ?>" title="Delete"><?= icon('trash') ?></button></form>
                        </div>
                        <details<?= $open ? ' open' : '' ?>><summary>Edit details</summary>
                            <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('event-edit.php?id=' . $id . '&tab=media')) ?>" class="mt-2">
                                <?= csrf_field() ?><input type="hidden" name="action" value="resource_update"><input type="hidden" name="resource_id" value="<?= $rid ?>">
                                <div class="form-grid">
                                    <div class="form-group"><label for="rt-<?= $rid ?>">Title</label><input id="rt-<?= $rid ?>" name="res_title" required maxlength="200" value="<?= e((string) $fv('res_title', $r['title'])) ?>"></div>
                                    <div class="form-group"><label for="ry-<?= $rid ?>">Type</label><select id="ry-<?= $rid ?>" name="res_type"><?= options_html(EVENT_RESOURCE_TYPES, (string) $fv('res_type', $r['resource_type']), true, '') ?></select></div>
                                    <div class="form-group full"><label for="rd-<?= $rid ?>">Description</label><input id="rd-<?= $rid ?>" name="res_description" maxlength="300" value="<?= e((string) $fv('res_description', (string) $r['description'])) ?>"></div>
                                    <div class="form-group"><label for="rv-<?= $rid ?>">Who can download</label><select id="rv-<?= $rid ?>" name="res_visibility"><?= options_html(['public' => 'Everyone (public)', 'registered' => 'Registered participants only'], (string) $fv('res_visibility', $r['visibility']), true, '') ?></select></div>
                                    <div class="form-group"><label for="rs-<?= $rid ?>">Status</label><select id="rs-<?= $rid ?>" name="res_status"><?= options_html(['published' => 'Published', 'hidden' => 'Hidden'], (string) $fv('res_status', $r['status']), true, '') ?></select></div>
                                    <div class="form-group"><label for="ro-<?= $rid ?>">Order</label><input id="ro-<?= $rid ?>" type="number" name="res_sort" min="-9999" max="9999" value="<?= e((string) $fv('res_sort', (string) $r['sort_order'])) ?>"></div>
                                    <div class="form-group"><label for="rf-<?= $rid ?>">Replace file</label><input id="rf-<?= $rid ?>" type="file" name="res_file" accept=".pdf,.jpg,.jpeg,.png"></div>
                                    <div class="form-group full"><label for="ru-<?= $rid ?>">Or external link</label><input id="ru-<?= $rid ?>" type="url" name="res_url" maxlength="500" value="<?= e((string) $fv('res_url', (string) $r['external_url'])) ?>" placeholder="https://…"></div>
                                </div>
                                <?php if ($open): ?><?= field_error('res_title') ?><?= field_error('res_file') ?><?= field_error('res_url') ?><?php endif; ?>
                                <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save</button>
                            </form>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">No downloads yet.</p>
        <?php endif; ?>
        <?php $nv = function (string $k, $d) use ($resForm) { return $resForm === 'new' ? old($k, $d) : $d; }; ?>
        <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('event-edit.php?id=' . $id . '&tab=media')) ?>" data-validate>
            <?= csrf_field() ?><input type="hidden" name="action" value="resource_add"><input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_MB * 1024 * 1024 ?>">
            <h3 class="h5">Add a download</h3>
            <div class="form-grid">
                <div class="form-group"><label for="res_title">Title<span class="req">*</span></label><input id="res_title" name="res_title" required maxlength="200" value="<?= e((string) $nv('res_title', '')) ?>"><?= $resForm === 'new' ? field_error('res_title') : '' ?></div>
                <div class="form-group"><label for="res_type">Type</label><select id="res_type" name="res_type"><?= options_html(EVENT_RESOURCE_TYPES, (string) $nv('res_type', 'material'), true, '') ?></select></div>
                <div class="form-group full"><label for="res_description">Description</label><input id="res_description" name="res_description" maxlength="300" value="<?= e((string) $nv('res_description', '')) ?>"></div>
                <div class="form-group"><label for="res_visibility">Who can download</label><select id="res_visibility" name="res_visibility"><?= options_html(['public' => 'Everyone (public)', 'registered' => 'Registered participants only'], (string) $nv('res_visibility', 'public'), true, '') ?></select></div>
                <div class="form-group"><label for="res_status">Status</label><select id="res_status" name="res_status"><?= options_html(['published' => 'Published', 'hidden' => 'Hidden'], (string) $nv('res_status', 'published'), true, '') ?></select></div>
                <div class="form-group"><label for="res_file">File (PDF, JPG or PNG, up to <?= (int) MAX_UPLOAD_MB ?> MB)</label><input id="res_file" type="file" name="res_file" accept=".pdf,.jpg,.jpeg,.png"><?= $resForm === 'new' ? field_error('res_file') : '' ?></div>
                <div class="form-group"><label for="res_url">Or external link</label><input id="res_url" type="url" name="res_url" maxlength="500" value="<?= e((string) $nv('res_url', '')) ?>" placeholder="https://…"><?= $resForm === 'new' ? field_error('res_url') : '' ?></div>
                <input type="hidden" name="res_sort" value="<?= count($resources) + 1 ?>">
            </div>
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('plus') ?>Add download</button>
        </form>
    </section>
<?php endif; ?>

<?php if ($tab === 'report'):
    $attended = $counts['attended'];
    $outcomeRows = $rowsFrom('outcomes', $report ? programhub_json_list($report['outcomes']) : []);
    $verifier = $report && $report['verified_by'] ? db_value('SELECT name FROM users WHERE id = ?', [(int) $report['verified_by']]) : null;
    $past = event_is_past($event);
    $certs = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status = 'attended' AND certificate_id IS NOT NULL", [$id]);
    ?>
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="panel-head"><h2><?= icon('file-text') ?>Report &amp; verified outcomes</h2><?= $report ? status_badge($report['status']) : '<span class="badge badge-gray">Not started</span>' ?></div>
                <?php if (!$past): ?><div class="alert alert-info mb-3"><?= icon('info') ?><div>The event has not taken place yet. You can prepare a draft; it can be published after the event.</div></div><?php endif; ?>
                <form method="post" action="<?= e(admin_url('event-edit.php?id=' . $id . '&tab=report')) ?>" data-validate>
                    <?= csrf_field() ?><input type="hidden" name="action" value="report_save">
                    <div class="form-grid">
                        <div class="form-group full"><label for="summary">Report</label>
                            <textarea id="summary" name="summary" rows="10" data-editor><?= e((string) old('summary', (string) ($report['summary'] ?? ''))) ?></textarea>
                            <span class="help">What happened, what participants did and learnt, and any follow-up. Facts only.</span><?= field_error('summary') ?></div>
                        <div class="form-group full"><span class="label">Outcomes (figures from your records)</span>
                            <?= site_repeater_html('outcomes', 'outcomes', ['label' => ['text', 'What was measured', 'e.g. Saplings planted, Health check-ups done'], 'value' => ['text', 'Figure'], 'note' => ['text', 'Source / note (optional)', 'e.g. From the attendance register']], $outcomeRows, '', 'Outcome', $outcomeRows ? 0 : 1) ?><?= field_error('outcomes') ?></div>
                        <div class="form-group"><label for="participants_verified">Verified number of participants</label>
                            <input id="participants_verified" type="number" name="participants_verified" min="0" step="1" value="<?= e((string) old('participants_verified', $report['participants_verified'] ?? '')) ?>" placeholder="Leave empty to use the attendance">
                            <?php if ($attended): ?><button type="button" class="btn btn-ghost btn-sm mt-1" data-ph-fill="#participants_verified" data-value="<?= $attended ?>"><?= icon('check') ?>Use the attendance (<?= $attended ?>)</button><?php endif; ?>
                            <span class="help">For participants who did not register online (e.g. walk-ins) enter the total from your attendance sheet.</span><?= field_error('participants_verified') ?></div>
                        <div class="form-group"><label for="report_status">Status</label>
                            <select id="report_status" name="report_status"><?= options_html(['draft' => 'Draft (not visible)', 'published' => 'Published on the event page'], (string) old('report_status', $report['status'] ?? 'draft'), true, '') ?></select><?= field_error('report_status') ?></div>
                        <div class="form-group full ph-verify"><label class="checkbox"><input type="checkbox" name="verified" value="1"<?= ($failed ? old('verified', null) !== null : !empty($report['verified_at'])) ? ' checked' : '' ?>><span>I have checked these figures against our attendance records and documents (staff verification)</span></label>
                            <?php if ($report && $report['verified_at']): ?><span class="help">Verified by <?= e((string) ($verifier ?: 'a staff member')) ?> on <?= e(format_date($report['verified_at'], 'd M Y, h:i A')) ?>.</span><?php endif; ?>
                            <span class="help">Unverified figures are never shown publicly.</span><?= field_error('verified') ?></div>
                    </div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save report</button></div>
                </form>
            </section>
        </div>
        <div>
            <section class="panel">
                <div class="panel-head"><h2><?= icon('bar-chart') ?>Attendance (from registrations)</h2></div>
                <div class="ph-figures">
                    <div class="ph-figure"><strong><?= $attended ?></strong><small>Attended</small></div>
                    <div class="ph-figure"><strong><?= $counts['no_show'] ?></strong><small>No-show</small></div>
                    <div class="ph-figure"><strong><?= $counts['registered'] + $counts['confirmed'] ?></strong><small>Registered / confirmed (not marked)</small></div>
                    <div class="ph-figure"><strong><?= $counts['cancelled'] ?></strong><small>Cancelled</small></div>
                </div>
                <p class="muted small">The public page shows the verified figure above when the report is published and verified; otherwise the number of registrations marked <strong>attended</strong> (after the event).</p>
                <?php if ($canPeople): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('event-registrations.php?event=' . $id)) ?>"><?= icon('check-circle') ?>Mark attendance</a><?php endif; ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2><?= icon('award') ?>Participation certificates</h2></div>
                <?php if ((int) $event['certificate_confirmed'] === 1): ?>
                    <p class="small"><?= $certs ?> of <?= $attended ?> attendee<?= $attended === 1 ? '' : 's' ?> have a certificate record.</p>
                    <?php if ($canPeople && admin_can('certificates.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('event-registrations.php?event=' . $id . '#certificates')) ?>"><?= icon('award') ?>Issue to attendees</a><?php endif; ?>
                <?php else: ?>
                    <p class="muted small mb-0">Certificates are not confirmed for this event (Details &gt; FAQs, certificate &amp; contact).</p>
                <?php endif; ?>
            </section>
        </div>
    </div>
<?php endif; ?>
</div>
<?php
programhub_admin_assets();
admin_page_end();
