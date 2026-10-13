<?php
/**
 * Internal messaging between admin users (top-bar envelope, /admin/messages).
 * Storage: one `messages` row per recipient; copies of one message sent to several users share `batch_key`;
 * replies carry `parent_id` and every message of a conversation carries the root id in `thread_id`.
 * Each side deletes / stars independently (deleted_by_sender|recipient, is_starred_by_sender|recipient);
 * rows deleted by both sides are purged.
 *
 *   GET    /api/messages?folder=inbox|sent|starred&unread=1&q=&page=&per_page=   conversations in a folder (newest first)
 *   GET    /api/messages/counts                      {inbox, unread, starred, sent}
 *   GET    /api/messages/recipients?q=&ids=1,2       active users you can write to (Option[] for the combobox)
 *   GET    /api/messages/thread/{id}                 a conversation (any message id in it), as visible to you
 *   POST   /api/messages                             {recipient_ids: [], subject, body, reply_to?}
 *   POST   /api/messages/thread/{id}/read | unread
 *   POST   /api/messages/thread/{id}/star            {starred: true|false}
 *   DELETE /api/messages/thread/{id}                 delete the conversation for you
 *   DELETE /api/messages/{id}                        delete one message for you
 *   POST   /api/messages/bulk                        {action: read|unread|star|unstar|delete, thread_ids: []}
 */

const MSG_MAX_RECIPIENTS = 50;
const MSG_MAX_BODY = 10000;

/** SQL: message row visible to the user (2 placeholders: uid, uid). */
function msg_visible_sql(string $a = 'm'): string
{
    return "(($a.sender_id = ? AND $a.deleted_by_sender = 0) OR ($a.recipient_id = ? AND $a.deleted_by_recipient = 0))";
}

/** SQL: row belongs to conversation (2 placeholders: tid, tid). */
function msg_in_thread_sql(string $a = 'm'): string
{
    return "($a.thread_id = ? OR $a.id = ?)";
}

/** Conversation id of message $id when the current user can see at least one message of that conversation. */
function msg_find_thread(int $id): ?int
{
    $uid = (int) user_id();
    $tid = (int) db_value('SELECT COALESCE(thread_id, id) FROM messages WHERE id = ?', [$id]);
    if (!$tid || !db_value('SELECT 1 FROM messages m WHERE ' . msg_in_thread_sql() . ' AND ' . msg_visible_sql() . ' LIMIT 1', [$tid, $tid, $uid, $uid])) {
        return null;
    }
    return $tid;
}

/** Same as msg_find_thread() but answers 404 when the conversation is not available. */
function msg_thread_id(int $id): int
{
    $tid = msg_find_thread($id);
    if (!$tid) {
        api_error('This conversation is no longer available.', 404);
    }
    return $tid;
}

function msg_user_brief(?array $u): ?array
{
    return $u ? ['id' => (int) $u['id'], 'name' => $u['name'], 'avatar' => $u['avatar'] ? upload_url($u['avatar']) : null, 'designation' => $u['designation'] ?? null] : null;
}

function msg_snippet(?string $body, int $len = 140): string
{
    return str_limit(preg_replace('/\s+/', ' ', (string) $body), $len);
}

/** Remove rows both sides have deleted (and rows whose sender account is gone and the recipient deleted). */
function msg_purge(array $tids): void
{
    $tids = array_values(array_filter(array_map('intval', $tids)));
    if (!$tids) {
        return;
    }
    $in = implode(',', $tids);
    // replies keep working: fk_msg_parent sets their parent_id to NULL
    db_exec("DELETE FROM messages WHERE (thread_id IN ($in) OR id IN ($in)) AND deleted_by_recipient = 1 AND (deleted_by_sender = 1 OR sender_id IS NULL)");
}

/** Apply a per-user action to whole conversations. */
function msg_thread_action(array $tids, string $action): int
{
    $uid = (int) user_id();
    $tids = array_values(array_unique(array_filter(array_map('intval', $tids))));
    if (!$tids) {
        return 0;
    }
    $in = implode(',', $tids);
    $scope = "(thread_id IN ($in) OR id IN ($in))";
    switch ($action) {
        case 'read':
            return db_exec("UPDATE messages SET is_read = 1, read_at = NOW() WHERE $scope AND recipient_id = ? AND deleted_by_recipient = 0 AND is_read = 0", [$uid]);
        case 'unread':
            // Mark the latest received message of each conversation unread.
            $n = 0;
            foreach ($tids as $tid) {
                $last = db_value('SELECT MAX(id) FROM messages WHERE (thread_id = ? OR id = ?) AND recipient_id = ? AND deleted_by_recipient = 0', [$tid, $tid, $uid]);
                if ($last) {
                    $n += db_exec('UPDATE messages SET is_read = 0, read_at = NULL WHERE id = ?', [$last]);
                }
            }
            return $n;
        case 'star':
            $n = 0;
            foreach ($tids as $tid) {
                $last = db_row('SELECT m.id, m.sender_id, m.recipient_id, m.batch_key FROM messages m WHERE ' . msg_in_thread_sql() . ' AND ' . msg_visible_sql() . ' ORDER BY m.id DESC LIMIT 1', [$tid, $tid, $uid, $uid]);
                if (!$last) {
                    continue;
                }
                if ((int) $last['recipient_id'] === $uid) {
                    $n += db_exec('UPDATE messages SET is_starred_by_recipient = 1 WHERE id = ?', [$last['id']]);
                } else {
                    $n += $last['batch_key']
                        ? db_exec('UPDATE messages SET is_starred_by_sender = 1 WHERE batch_key = ? AND sender_id = ?', [$last['batch_key'], $uid])
                        : db_exec('UPDATE messages SET is_starred_by_sender = 1 WHERE id = ?', [$last['id']]);
                }
            }
            return $n;
        case 'unstar':
            return db_exec("UPDATE messages SET is_starred_by_recipient = 0 WHERE $scope AND recipient_id = ?", [$uid])
                + db_exec("UPDATE messages SET is_starred_by_sender = 0 WHERE $scope AND sender_id = ?", [$uid]);
        case 'delete':
            $n = db_exec("UPDATE messages SET deleted_by_recipient = 1, is_starred_by_recipient = 0 WHERE $scope AND recipient_id = ? AND deleted_by_recipient = 0", [$uid])
                + db_exec("UPDATE messages SET deleted_by_sender = 1, is_starred_by_sender = 0 WHERE $scope AND sender_id = ? AND deleted_by_sender = 0", [$uid]);
            msg_purge($tids);
            return $n;
    }
    throw new InvalidArgumentException('Unknown action.');
}

route('GET', '/messages', function () {
    $uid = (int) user_id();
    [$page, $perPage] = api_pagination(20);
    $folder = in_array($_GET['folder'] ?? 'inbox', ['inbox', 'sent', 'starred'], true) ? (string) ($_GET['folder'] ?? 'inbox') : 'inbox';
    if ($folder === 'sent') {
        $where = 'm.sender_id = ? AND m.deleted_by_sender = 0';
        $args = [$uid];
    } elseif ($folder === 'starred') {
        $where = '((m.recipient_id = ? AND m.deleted_by_recipient = 0 AND m.is_starred_by_recipient = 1) OR (m.sender_id = ? AND m.deleted_by_sender = 0 AND m.is_starred_by_sender = 1))';
        $args = [$uid, $uid];
    } else {
        $where = 'm.recipient_id = ? AND m.deleted_by_recipient = 0';
        $args = [$uid];
        if (!empty($_GET['unread'])) {
            $where .= ' AND m.is_read = 0';
        }
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . addcslashes(mb_substr($q, 0, 80), '\\%_') . '%';
        $where .= ' AND (m.subject LIKE ? OR m.body LIKE ? OR su.name LIKE ? OR ru.name LIKE ?)';
        array_push($args, $like, $like, $like, $like);
    }
    $from = 'FROM messages m LEFT JOIN users su ON su.id = m.sender_id LEFT JOIN users ru ON ru.id = m.recipient_id';
    $total = (int) db_value("SELECT COUNT(DISTINCT COALESCE(m.thread_id, m.id)) $from WHERE $where", $args);
    $p = paginate($total, $page, $perPage);
    $threads = db_all("SELECT COALESCE(m.thread_id, m.id) AS tid, MAX(m.id) AS last_id $from WHERE $where GROUP BY tid ORDER BY MAX(m.created_at) DESC, last_id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $args);
    $rows = [];
    if ($threads) {
        $tids = array_map(fn ($t) => (int) $t['tid'], $threads);
        $in = implode(',', $tids);
        $stats = [];
        foreach (db_all("SELECT COALESCE(m.thread_id, m.id) AS tid,
                                COALESCE(SUM(m.recipient_id = ? AND m.deleted_by_recipient = 0 AND m.is_read = 0), 0) AS unread,
                                MAX((m.recipient_id = ? AND m.deleted_by_recipient = 0 AND m.is_starred_by_recipient = 1) OR (m.sender_id = ? AND m.deleted_by_sender = 0 AND m.is_starred_by_sender = 1)) AS starred,
                                COUNT(DISTINCT COALESCE(m.batch_key, CONCAT('m', m.id))) AS messages
                         FROM messages m WHERE (m.thread_id IN ($in) OR m.id IN ($in)) AND " . msg_visible_sql() . ' GROUP BY tid', [$uid, $uid, $uid, $uid, $uid]) as $s) {
            $stats[(int) $s['tid']] = $s;
        }
        $lastIds = implode(',', array_map(fn ($t) => (int) $t['last_id'], $threads));
        $last = [];
        foreach (db_all("SELECT m.id, m.sender_id, m.recipient_id, m.batch_key, m.subject, m.body, m.created_at, su.id AS su_id, su.name AS su_name, su.avatar AS su_avatar, su.designation AS su_designation
                         FROM messages m LEFT JOIN users su ON su.id = m.sender_id WHERE m.id IN ($lastIds)") as $m) {
            $last[(int) $m['id']] = $m;
        }
        $batches = array_values(array_filter(array_unique(array_column($last, 'batch_key'))));
        $batchTo = [];
        if ($batches) {
            $place = implode(',', array_fill(0, count($batches), '?'));
            foreach (db_all("SELECT m.batch_key, u.id, u.name, u.avatar, u.designation FROM messages m JOIN users u ON u.id = m.recipient_id WHERE m.batch_key IN ($place) ORDER BY m.id", $batches) as $r) {
                $batchTo[$r['batch_key']][] = msg_user_brief($r);
            }
        }
        $singleTo = [];
        $singleIds = array_values(array_unique(array_map(fn ($m) => (int) $m['recipient_id'], array_filter($last, fn ($m) => !$m['batch_key']))));
        if ($singleIds) {
            foreach (db_all('SELECT id, name, avatar, designation FROM users WHERE id IN (' . implode(',', $singleIds) . ')') as $u) {
                $singleTo[(int) $u['id']] = msg_user_brief($u);
            }
        }
        foreach ($threads as $t) {
            $m = $last[(int) $t['last_id']] ?? null;
            if (!$m) {
                continue;
            }
            $s = $stats[(int) $t['tid']] ?? ['unread' => 0, 'starred' => 0, 'messages' => 1];
            $to = $m['batch_key'] ? ($batchTo[$m['batch_key']] ?? []) : array_values(array_filter([$singleTo[(int) $m['recipient_id']] ?? null]));
            $rows[] = [
                'thread_id' => (int) $t['tid'], 'id' => (int) $m['id'], 'subject' => $m['subject'], 'snippet' => msg_snippet($m['body']), 'created_at' => $m['created_at'],
                'mine' => (int) $m['sender_id'] === $uid, 'from' => $m['su_id'] ? msg_user_brief(['id' => $m['su_id'], 'name' => $m['su_name'], 'avatar' => $m['su_avatar'], 'designation' => $m['su_designation']]) : null,
                'to' => $to, 'unread' => (int) $s['unread'], 'starred' => (bool) $s['starred'], 'messages' => (int) $s['messages'],
            ];
        }
    }
    api_ok(['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'pages' => $p['pages'], 'per_page' => $p['per_page'], 'folder' => $folder]);
});

route('GET', '/messages/counts', function () {
    $uid = (int) user_id();
    $r = db_row('SELECT COUNT(DISTINCT CASE WHEN m.recipient_id = ? AND m.deleted_by_recipient = 0 THEN COALESCE(m.thread_id, m.id) END) AS inbox,
                        COALESCE(SUM(m.recipient_id = ? AND m.deleted_by_recipient = 0 AND m.is_read = 0), 0) AS unread,
                        COUNT(DISTINCT CASE WHEN (m.recipient_id = ? AND m.deleted_by_recipient = 0 AND m.is_starred_by_recipient = 1) OR (m.sender_id = ? AND m.deleted_by_sender = 0 AND m.is_starred_by_sender = 1) THEN COALESCE(m.thread_id, m.id) END) AS starred,
                        COUNT(DISTINCT CASE WHEN m.sender_id = ? AND m.deleted_by_sender = 0 THEN COALESCE(m.thread_id, m.id) END) AS sent
                 FROM messages m WHERE m.recipient_id = ? OR m.sender_id = ?', [$uid, $uid, $uid, $uid, $uid, $uid, $uid]);
    api_ok(array_map('intval', $r));
});

route('GET', '/messages/recipients', function () {
    $where = "u.status = 'active' AND u.id <> ?";
    $args = [user_id()];
    if (!empty($_GET['ids'])) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $_GET['ids']))));
        $where = $ids ? 'u.id IN (' . implode(',', $ids) . ')' : '1 = 0';
        $args = [];
    } elseif (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
        $like = '%' . addcslashes(mb_substr($q, 0, 60), '\\%_') . '%';
        $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.designation LIKE ?)';
        array_push($args, $like, $like, $like, $like);
    }
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 30)));
    $rows = db_all("SELECT u.id, u.name, u.designation, u.email, (SELECT GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles
                    FROM users u WHERE $where ORDER BY u.name LIMIT $limit", $args);
    api_ok(array_map(fn ($r) => ['value' => (int) $r['id'], 'label' => $r['name'], 'sub' => implode(' · ', array_filter([$r['designation'], $r['roles'] && $r['roles'] !== $r['designation'] ? $r['roles'] : null]))], $rows));
});

route('GET', '/messages/thread/{id:\d+}', function ($p) {
    $uid = (int) user_id();
    $tid = msg_thread_id((int) $p['id']);
    $rows = db_all('SELECT m.*, su.name AS su_name, su.avatar AS su_avatar, su.designation AS su_designation, ru.name AS ru_name, ru.avatar AS ru_avatar, ru.designation AS ru_designation
                    FROM messages m LEFT JOIN users su ON su.id = m.sender_id LEFT JOIN users ru ON ru.id = m.recipient_id
                    WHERE ' . msg_in_thread_sql() . ' AND ' . msg_visible_sql() . ' ORDER BY m.created_at, m.id', [$tid, $tid, $uid, $uid]);
    // Fold the copies of a multi-recipient message into one entry with every recipient listed.
    $messages = [];
    $participants = [];
    foreach ($rows as $r) {
        $key = $r['batch_key'] ?: 'm' . $r['id'];
        if (!isset($messages[$key])) {
            $messages[$key] = [
                'id' => (int) $r['id'], 'parent_id' => $r['parent_id'] ? (int) $r['parent_id'] : null, 'subject' => $r['subject'], 'body' => $r['body'], 'created_at' => $r['created_at'],
                'from' => $r['sender_id'] ? msg_user_brief(['id' => $r['sender_id'], 'name' => $r['su_name'], 'avatar' => $r['su_avatar'], 'designation' => $r['su_designation']]) : ['id' => 0, 'name' => 'Deleted user', 'avatar' => null, 'designation' => null],
                'to' => [], 'mine' => (int) $r['sender_id'] === $uid, 'is_read' => true, 'read_by' => 0, 'starred' => false,
            ];
        }
        $msg = &$messages[$key];
        $msg['to'][] = msg_user_brief(['id' => $r['recipient_id'], 'name' => $r['ru_name'] ?? 'Deleted user', 'avatar' => $r['ru_avatar'] ?? null, 'designation' => $r['ru_designation'] ?? null]);
        if ((int) $r['recipient_id'] === $uid) {
            $msg['is_read'] = (bool) $r['is_read'];
            $msg['starred'] = $msg['starred'] || (bool) $r['is_starred_by_recipient'];
        }
        if ((int) $r['sender_id'] === $uid) {
            $msg['starred'] = $msg['starred'] || (bool) $r['is_starred_by_sender'];
            $msg['read_by'] += (int) $r['is_read'];
        }
        unset($msg);
        foreach ([[$r['sender_id'], $r['su_name'], $r['su_avatar'], $r['su_designation']], [$r['recipient_id'], $r['ru_name'], $r['ru_avatar'], $r['ru_designation']]] as [$id, $name, $avatar, $desig]) {
            if ($id && (int) $id !== $uid && $name !== null) {
                $participants[(int) $id] = msg_user_brief(['id' => $id, 'name' => $name, 'avatar' => $avatar, 'designation' => $desig]);
            }
        }
    }
    if (!$messages) {
        api_error('This conversation is no longer available.', 404);
    }
    // Recipients see every co-recipient of a multi-recipient message (like "To:" in email) and can reply to all.
    $batchKeys = array_values(array_filter(array_keys($messages), fn ($k) => !str_starts_with((string) $k, 'm')));
    if ($batchKeys) {
        $place = implode(',', array_fill(0, count($batchKeys), '?'));
        $all = [];
        foreach (db_all("SELECT m.batch_key, u.id, u.name, u.avatar, u.designation FROM messages m JOIN users u ON u.id = m.recipient_id WHERE m.batch_key IN ($place) ORDER BY m.id", $batchKeys) as $r) {
            $all[$r['batch_key']][] = msg_user_brief($r);
            if ((int) $r['id'] !== $uid) {
                $participants[(int) $r['id']] = msg_user_brief($r);
            }
        }
        foreach ($all as $key => $to) {
            $messages[$key]['to'] = $to;
        }
    }
    $list = array_values($messages);
    $active = $participants ? db_column('SELECT id FROM users WHERE status = ? AND id IN (' . implode(',', array_keys($participants)) . ')', ['active']) : [];
    api_ok([
        'thread_id' => $tid, 'subject' => $list[0]['subject'], 'messages' => $list,
        'participants' => array_values(array_filter($participants, fn ($u) => in_array((string) $u['id'], array_map('strval', $active), true))),
        'starred' => (bool) array_filter($list, fn ($m) => $m['starred']), 'unread' => count(array_filter($list, fn ($m) => !$m['is_read'])),
    ]);
});

route('POST', '/messages', function () {
    $uid = (int) user_id();
    $in = api_input();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['recipient_ids'] ?? [])), fn ($i) => $i > 0 && $i !== $uid)));
    $subject = trim((string) ($in['subject'] ?? ''));
    $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
    $parent = null;
    if (!empty($in['reply_to'])) {
        $tid = msg_thread_id((int) $in['reply_to']);
        $parent = db_row('SELECT id, subject FROM messages WHERE id = ?', [(int) $in['reply_to']]);
        $parent['thread_id'] = $tid;
        if ($subject === '') {
            $subject = preg_match('/^re:/i', $parent['subject']) ? $parent['subject'] : 'Re: ' . $parent['subject'];
        }
    }
    $errors = [];
    if (!$ids) {
        $errors['recipient_ids'] = 'Choose at least one recipient.';
    } elseif (count($ids) > MSG_MAX_RECIPIENTS) {
        $errors['recipient_ids'] = 'You can message up to ' . MSG_MAX_RECIPIENTS . ' people at once.';
    } else {
        $valid = array_map('intval', db_column("SELECT id FROM users WHERE status = 'active' AND id IN (" . implode(',', $ids) . ')'));
        if (count($valid) !== count($ids)) {
            $errors['recipient_ids'] = 'One or more recipients are no longer active users.';
        }
    }
    if ($subject === '') {
        $errors['subject'] = 'Subject is required.';
    } elseif (mb_strlen($subject) > 190) {
        $errors['subject'] = 'Subject may not be longer than 190 characters.';
    }
    if ($body === '') {
        $errors['body'] = 'Write a message before sending.';
    } elseif (mb_strlen($body) > MSG_MAX_BODY) {
        $errors['body'] = 'Message may not be longer than ' . number_in(MSG_MAX_BODY) . ' characters.';
    }
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    if (!rate_limit('messages:' . $uid, 40, 600)) {
        api_error('You are sending messages too quickly. Please wait a few minutes and try again.', 429);
    }
    $batch = count($ids) > 1 ? bin2hex(random_bytes(8)) : null;
    $threadId = db_transaction(function () use ($ids, $uid, $subject, $body, $parent, $batch) {
        $threadId = $parent['thread_id'] ?? null;
        $inserted = [];
        foreach ($ids as $rid) {
            $inserted[] = db_insert('messages', [
                'parent_id' => $parent['id'] ?? null, 'thread_id' => $threadId, 'batch_key' => $batch, 'sender_id' => $uid, 'recipient_id' => $rid,
                'subject' => mb_substr($subject, 0, 190), 'body' => $body,
            ]);
        }
        if (!$threadId) {
            $threadId = $inserted[0];
            db_exec('UPDATE messages SET thread_id = ? WHERE id IN (' . implode(',', $inserted) . ')', [$threadId]);
        }
        // Replying also reads the conversation.
        if ($parent) {
            db_exec('UPDATE messages SET is_read = 1, read_at = NOW() WHERE (thread_id = ? OR id = ?) AND recipient_id = ? AND is_read = 0', [$threadId, $threadId, $uid]);
        }
        return $threadId;
    });
    $names = db_column('SELECT name FROM users WHERE id IN (' . implode(',', $ids) . ') ORDER BY name');
    $to = count($names) > 2 ? $names[0] . ', ' . $names[1] . ' and ' . (count($names) - 2) . ' more' : implode(' and ', $names);
    log_activity('create', 'messages', $threadId, ($parent ? 'Replied to an internal message' : 'Sent an internal message') . ' to ' . count($ids) . ' user' . (count($ids) === 1 ? '' : 's'));
    api_ok(['thread_id' => (int) $threadId], ($parent ? 'Reply sent to ' : 'Message sent to ') . $to . '.');
});

route('POST', '/messages/thread/{id:\d+}/read', function ($p) {
    msg_thread_action([msg_thread_id((int) $p['id'])], 'read');
    api_ok();
});

route('POST', '/messages/thread/{id:\d+}/unread', function ($p) {
    msg_thread_action([msg_thread_id((int) $p['id'])], 'unread');
    api_ok(null, 'Marked as unread.');
});

route('POST', '/messages/thread/{id:\d+}/star', function ($p) {
    $starred = filter_var(api_param('starred', true), FILTER_VALIDATE_BOOLEAN);
    msg_thread_action([msg_thread_id((int) $p['id'])], $starred ? 'star' : 'unstar');
    api_ok(['starred' => $starred], $starred ? 'Conversation starred.' : 'Star removed.');
});

route('DELETE', '/messages/thread/{id:\d+}', function ($p) {
    msg_thread_action([msg_thread_id((int) $p['id'])], 'delete');
    api_ok(null, 'Conversation deleted.');
});

route('DELETE', '/messages/{id:\d+}', function ($p) {
    $uid = (int) user_id();
    $tid = msg_thread_id((int) $p['id']);
    $row = db_row('SELECT id, sender_id, recipient_id, batch_key FROM messages WHERE id = ?', [(int) $p['id']]);
    $n = 0;
    if ((int) $row['sender_id'] === $uid) {
        $n += $row['batch_key']
            ? db_exec('UPDATE messages SET deleted_by_sender = 1, is_starred_by_sender = 0 WHERE batch_key = ? AND sender_id = ?', [$row['batch_key'], $uid])
            : db_exec('UPDATE messages SET deleted_by_sender = 1, is_starred_by_sender = 0 WHERE id = ?', [$row['id']]);
    }
    if ((int) $row['recipient_id'] === $uid) {
        $n += db_exec('UPDATE messages SET deleted_by_recipient = 1, is_starred_by_recipient = 0 WHERE id = ?', [$row['id']]);
    }
    msg_purge([$tid]);
    $left = (int) db_value('SELECT COUNT(*) FROM messages m WHERE ' . msg_in_thread_sql() . ' AND ' . msg_visible_sql(), [$tid, $tid, $uid, $uid]);
    api_ok(['remaining' => $left], 'Message deleted.');
});

route('POST', '/messages/bulk', function () {
    $in = api_input();
    $action = (string) ($in['action'] ?? '');
    if (!in_array($action, ['read', 'unread', 'star', 'unstar', 'delete'], true)) {
        api_error('Choose a valid action.', 422);
    }
    $uid = (int) user_id();
    $tids = [];
    foreach (array_slice((array) ($in['thread_ids'] ?? []), 0, 200) as $id) {
        $tid = msg_find_thread((int) $id);
        if ($tid) {
            $tids[] = (int) $tid;
        }
    }
    if (!$tids) {
        api_error('Select at least one conversation.', 422);
    }
    msg_thread_action($tids, $action);
    $n = count(array_unique($tids));
    $labels = ['read' => 'marked as read', 'unread' => 'marked as unread', 'star' => 'starred', 'unstar' => 'unstarred', 'delete' => 'deleted'];
    api_ok(['count' => $n], $n . ' conversation' . ($n === 1 ? '' : 's') . ' ' . $labels[$action] . '.');
});
