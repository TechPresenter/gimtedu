<?php
/**
 * Website contact messages inbox (admissions unit). Listing/delete/export use /api/crud/contact_messages.
 *
 *   GET  /contact-messages/counts                       folder counts (inbox, unread, starred, replied, resolved, archived)
 *   GET  /contact-messages/{id}                         message + reply thread (message_logs) + linked enquiry
 *   POST /contact-messages/{id}/read      {read}        mark read / unread
 *   POST /contact-messages/{id}/star      {starred}
 *   POST /contact-messages/{id}/archive   {archived}
 *   POST /contact-messages/{id}/resolve   {resolved}
 *   POST /contact-messages/{id}/reply     {subject, message}   email reply via send_mail (logged in message_logs)
 *   POST /contact-messages/{id}/convert                 create an enquiry from the message
 *   POST /contact-messages/bulk           {action: read|unread|star|unstar|archive|unarchive|resolve, ids[]}
 */
require_once APP_ROOT . '/app/services/admissions.php';

function cm_api_find(int $id): array
{
    $m = db_row('SELECT * FROM contact_messages WHERE id = ?', [$id]);
    if (!$m) {
        api_error('Message not found. It may have been deleted.', 404);
    }
    return $m;
}

function cm_public(array $m): array
{
    foreach (['id', 'is_starred', 'is_archived', 'replied_by', 'enquiry_id'] as $k) {
        $m[$k] = $m[$k] !== null ? (int) $m[$k] : null;
    }
    return $m;
}

route('GET', '/contact-messages/counts', function () {
    api_require('contact_messages', 'view');
    $c = db_row("SELECT SUM(is_archived = 0 AND status <> 'closed') AS inbox, SUM(is_archived = 0 AND status = 'new') AS unread,
                        SUM(is_archived = 0 AND is_starred = 1) AS starred, SUM(is_archived = 0 AND status = 'replied') AS replied,
                        SUM(is_archived = 0 AND status = 'closed') AS resolved, SUM(is_archived = 1) AS archived, COUNT(*) AS total,
                        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS this_week
                 FROM contact_messages");
    api_ok(array_map(fn ($v) => (int) $v, $c));
});

route('GET', '/contact-messages/{id:\d+}', function ($p) {
    api_require('contact_messages', 'view');
    $m = cm_api_find((int) $p['id']);
    $thread = db_all("SELECT id, recipient, subject, body, status, error, sent_at, created_at FROM message_logs WHERE related_type = 'contact_message' AND related_id = ? ORDER BY created_at, id", [(int) $m['id']]);
    $enquiry = $m['enquiry_id'] ? db_row('SELECT e.id, e.status, e.assigned_to, u.name AS assignee_name FROM enquiries e LEFT JOIN users u ON u.id = e.assigned_to WHERE e.id = ?', [(int) $m['enquiry_id']]) : null;
    $repliedBy = $m['replied_by'] ? db_value('SELECT name FROM users WHERE id = ?', [(int) $m['replied_by']]) : null;
    $others = (int) db_value('SELECT COUNT(*) FROM contact_messages WHERE LOWER(email) = ? AND id <> ?', [strtolower($m['email']), (int) $m['id']]);
    api_ok(['message' => cm_public($m) + ['replied_by_name' => $repliedBy], 'thread' => $thread, 'enquiry' => $enquiry, 'other_messages' => $others,
        'can' => ['edit' => can('contact_messages', 'edit'), 'delete' => can('contact_messages', 'delete'), 'convert' => can('enquiries', 'create')]]);
});

$cmFlag = function (string $field, string $param) {
    return function ($p) use ($field, $param) {
        api_require('contact_messages', 'edit');
        $m = cm_api_find((int) $p['id']);
        $on = filter_var(api_input()[$param] ?? true, FILTER_VALIDATE_BOOLEAN);
        $upd = [];
        $msg = '';
        switch ($field) {
            case 'read':
                if ($on && $m['status'] === 'new') {
                    $upd = ['status' => 'read', 'read_at' => date('Y-m-d H:i:s')];
                } elseif (!$on && $m['status'] !== 'new') {
                    $upd = ['status' => 'new', 'read_at' => null];
                }
                $msg = $on ? '' : 'Marked as unread.';
                break;
            case 'star':
                $upd = ['is_starred' => $on ? 1 : 0];
                $msg = $on ? 'Message starred.' : 'Star removed.';
                break;
            case 'archive':
                $upd = ['is_archived' => $on ? 1 : 0];
                $msg = $on ? 'Message archived.' : 'Message moved to inbox.';
                break;
            case 'resolve':
                $upd = $on ? ['status' => 'closed', 'resolved_at' => date('Y-m-d H:i:s'), 'read_at' => $m['read_at'] ?: date('Y-m-d H:i:s')]
                    : ['status' => $m['reply'] ? 'replied' : 'read', 'resolved_at' => null];
                $msg = $on ? 'Marked as resolved.' : 'Message reopened.';
                break;
        }
        if ($upd) {
            db_update('contact_messages', $upd, 'id = ?', [(int) $m['id']]);
            if ($field !== 'read' || !$on) {
                log_activity('update', 'contact_messages', $m['id'], trim($msg, '.') . ': message from ' . $m['name'] . ' <' . $m['email'] . '>');
            }
        }
        api_ok(cm_public(db_row('SELECT * FROM contact_messages WHERE id = ?', [(int) $m['id']])), $msg);
    };
};
route('POST', '/contact-messages/{id:\d+}/read', $cmFlag('read', 'read'));
route('POST', '/contact-messages/{id:\d+}/star', $cmFlag('star', 'starred'));
route('POST', '/contact-messages/{id:\d+}/archive', $cmFlag('archive', 'archived'));
route('POST', '/contact-messages/{id:\d+}/resolve', $cmFlag('resolve', 'resolved'));

route('POST', '/contact-messages/{id:\d+}/reply', function ($p) {
    api_require('contact_messages', 'edit');
    $m = cm_api_find((int) $p['id']);
    $data = api_validate(['subject' => 'required|max:255', 'message' => 'required|min:5|max:10000'], null, ['message' => 'Reply']);
    if (!rate_limit('cm-reply:' . user_id(), 60, 3600)) {
        api_error('Too many replies sent in the last hour. Please try again later.', 429);
    }
    $html = '<p>Dear ' . e($m['name']) . ',</p>' . nl2br(e($data['message'])) . '<p>Regards,<br>' . e(current_user()['name'] ?? institute_name()) . '<br>' . e(institute_name()) . '</p>'
        . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0"><p style="color:#64748b;font-size:13px">On ' . e(format_datetime($m['created_at'])) . ' you wrote:<br>' . nl2br(e(str_limit($m['message'], 1500))) . '</p>';
    $ok = send_mail($m['email'], $data['subject'], $html, ['related_type' => 'contact_message', 'related_id' => (int) $m['id'], 'reply_to' => setting('email', 'info@gimt.ac.in')]);
    if (!$ok) {
        log_activity('reply', 'contact_messages', $m['id'], 'Failed to email reply to ' . $m['email'], 'failed');
        api_error('The reply could not be sent. Check the email settings and try again.', 502);
    }
    db_update('contact_messages', ['status' => 'replied', 'reply' => $data['message'], 'replied_by' => user_id(), 'replied_at' => date('Y-m-d H:i:s'),
        'read_at' => $m['read_at'] ?: date('Y-m-d H:i:s')], 'id = ?', [(int) $m['id']]);
    log_activity('reply', 'contact_messages', $m['id'], 'Replied by email to ' . $m['name'] . ' <' . $m['email'] . '>: ' . $data['subject']);
    api_ok(null, 'Reply sent to ' . $m['email'] . '.');
});

route('POST', '/contact-messages/{id:\d+}/convert', function ($p) {
    api_require('contact_messages', 'edit');
    api_require('enquiries', 'create');
    $eid = contact_message_to_enquiry((int) $p['id']);
    api_ok(['enquiry_id' => $eid], 'Enquiry #' . $eid . ' created from this message.');
});

route('POST', '/contact-messages/bulk', function () {
    api_require('contact_messages', 'edit');
    $in = api_input();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));
    $map = [
        'read' => ["status = 'read', read_at = COALESCE(read_at, NOW())", "status = 'new'"],
        'unread' => ["status = 'new', read_at = NULL", "status IN ('read')"],
        'star' => ['is_starred = 1', '1 = 1'], 'unstar' => ['is_starred = 0', '1 = 1'],
        'archive' => ['is_archived = 1', '1 = 1'], 'unarchive' => ['is_archived = 0', '1 = 1'],
        'resolve' => ["status = 'closed', resolved_at = NOW(), read_at = COALESCE(read_at, NOW())", "status <> 'closed'"],
    ];
    $action = (string) ($in['action'] ?? '');
    if (!$ids || !isset($map[$action])) {
        api_error(!$ids ? 'Select at least one message.' : 'Unknown bulk action.', 422);
    }
    [$set, $cond] = $map[$action];
    $n = db_exec("UPDATE contact_messages SET $set WHERE $cond AND id IN (" . implode(',', $ids) . ')');
    log_activity('update', 'contact_messages', implode(',', array_slice($ids, 0, 20)), sprintf('Bulk %s on %d contact messages', $action, count($ids)));
    api_ok(['updated' => $n], sprintf('%d message%s updated.', count($ids), count($ids) === 1 ? '' : 's'));
});
