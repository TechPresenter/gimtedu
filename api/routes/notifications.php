<?php
/**
 * In-app notification center.
 *   GET    /api/notifications?filter=all|unread&page=1     list + unread count (send header X-Background: 1 when polling)
 *   GET    /api/notifications/count                       unread notifications + unread messages
 *   POST   /api/notifications/{id}/read
 *   POST   /api/notifications/{id}/unread
 *   POST   /api/notifications/read-all
 *   DELETE /api/notifications/{id}
 *   DELETE /api/notifications                             clear all read notifications
 */

route('GET', '/notifications', function () {
    [$page, $perPage] = api_pagination(15);
    $where = 'user_id = ?';
    $args = [user_id()];
    if (($_GET['filter'] ?? '') === 'unread') {
        $where .= ' AND is_read = 0';
    }
    if (!empty($_GET['type'])) {
        $where .= ' AND type = ?';
        $args[] = $_GET['type'];
    }
    $total = (int) db_value("SELECT COUNT(*) FROM notifications WHERE $where", $args);
    $p = paginate($total, $page, $perPage);
    $rows = db_all("SELECT id, type, title, message, url, icon, is_read, created_at FROM notifications WHERE $where ORDER BY created_at DESC, id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['is_read'] = (bool) $r['is_read'];
        $r['time_ago'] = time_ago($r['created_at']);
    }
    api_ok(['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'pages' => $p['pages'],
        'unread' => (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [user_id()])]);
});

route('GET', '/notifications/count', function () {
    api_ok([
        'notifications' => (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [user_id()]),
        'messages' => (int) db_value('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0 AND deleted_by_recipient = 0', [user_id()]),
    ]);
});

route('POST', '/notifications/read-all', function () {
    $n = db_exec('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0', [user_id()]);
    api_ok(['updated' => $n], 'All notifications marked as read.');
});

route('POST', '/notifications/{id:\d+}/read', function ($p) {
    db_exec('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?', [(int) $p['id'], user_id()]);
    api_ok();
});

route('POST', '/notifications/{id:\d+}/unread', function ($p) {
    db_exec('UPDATE notifications SET is_read = 0, read_at = NULL WHERE id = ? AND user_id = ?', [(int) $p['id'], user_id()]);
    api_ok();
});

route('DELETE', '/notifications/{id:\d+}', function ($p) {
    db_exec('DELETE FROM notifications WHERE id = ? AND user_id = ?', [(int) $p['id'], user_id()]);
    api_ok(null, 'Notification deleted.');
});

route('DELETE', '/notifications', function () {
    $n = db_exec('DELETE FROM notifications WHERE user_id = ? AND is_read = 1', [user_id()]);
    api_ok(['deleted' => $n], 'Read notifications cleared.');
});
