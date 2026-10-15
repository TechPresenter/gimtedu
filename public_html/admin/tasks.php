<?php
/**
 * Volunteer tasks: assign tasks to users who hold the Registered Volunteer or Volunteer Teacher role,
 * list / filter, update status, notify the assignee. Assignees see their tasks in the member dashboard.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('recruitment.manage');

const RC_TASK_STATUSES = ['assigned' => 'Assigned', 'in_progress' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'];

/** Active users with a volunteer / volunteer teacher role. */
function rc_task_assignees(): array
{
    return db_rows("SELECT u.id, u.name, u.email, GROUP_CONCAT(r.name ORDER BY r.sort_order SEPARATOR ', ') AS roles
                      FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                     WHERE r.slug IN ('registered_volunteer', 'volunteer_teacher') AND u.status = 'active'
                     GROUP BY u.id, u.name, u.email ORDER BY u.name");
}

$assignees = array_column(rc_task_assignees(), null, 'id');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('tasks.php');
    $back = admin_back('tasks.php');
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        [$data, $errors] = validate($_POST, [
            'user_id' => 'required|integer',
            'title' => 'required|min:3|max:200',
            'details' => 'text|max:2000',
            'due_date' => 'date',
        ], ['user_id' => 'Volunteer', 'due_date' => 'Due date']);
        if (!$errors && !isset($assignees[(int) $data['user_id']])) {
            $errors['user_id'] = 'Tasks can be assigned only to active registered volunteers and volunteer teachers.';
        }
        if (!$errors && $data['due_date'] !== '' && $data['due_date'] < date('Y-m-d')) {
            $errors['due_date'] = 'The due date cannot be in the past.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/tasks.php#new-task', $_POST);
        }
        $uid = (int) $data['user_id'];
        $taskId = db_insert('tasks', [
            'user_id' => $uid, 'title' => $data['title'], 'details' => $data['details'] ?: null,
            'due_date' => $data['due_date'] ?: null, 'status' => 'assigned', 'assigned_by' => admin_id(),
        ]);
        $person = $assignees[$uid];
        $due = $data['due_date'] ? ' (due ' . format_date($data['due_date']) . ')' : '';
        if (!empty($_POST['notify'])) {
            notify_applicant($person['email'], $person['name'], 'New task: ' . $data['title'],
                'A new task has been assigned to you' . $due . ":\n\n" . $data['title'] . ($data['details'] ? "\n" . $data['details'] : '') . "\n\nYou can see your tasks and update their progress in your dashboard.",
                ['user_id' => $uid, 'link' => '/dashboard/', 'button' => 'Open my dashboard', 'notice' => $data['title'] . $due]);
        } else {
            add_notification($uid, 'New task: ' . $data['title'], 'A new task has been assigned to you' . $due . '.', '/dashboard/', 'info');
        }
        admin_log('task_created', 'Task #' . $taskId . ' for user #' . $uid . ': ' . $data['title']);
        flash('success', 'Task assigned to ' . $person['name'] . '.');
        admin_redirect($back);
    }

    $taskId = (int) ($_POST['id'] ?? 0);
    $task = db_row('SELECT t.*, u.name, u.email FROM tasks t JOIN users u ON u.id = t.user_id WHERE t.id = ?', [$taskId]);
    if (!$task) {
        flash('error', 'Task not found.');
        admin_redirect($back);
    }
    if ($action === 'status') {
        $status = (string) ($_POST['status'] ?? '');
        if (!isset(RC_TASK_STATUSES[$status])) {
            flash('error', 'Invalid status.');
            admin_redirect($back);
        }
        db_update('tasks', ['status' => $status, 'completed_at' => $status === 'done' ? ($task['completed_at'] ?: date('Y-m-d H:i:s')) : null], 'id = :id', ['id' => $taskId]);
        if ($status !== $task['status'] && in_array($status, ['done', 'cancelled'], true)) {
            add_notification((int) $task['user_id'], 'Task ' . strtolower(RC_TASK_STATUSES[$status]) . ': ' . $task['title'],
                $status === 'done' ? 'Thank you! This task has been marked as done.' : 'This task has been cancelled by your coordinator.', '/dashboard/', $status === 'done' ? 'success' : 'info');
        }
        admin_log('task_status', 'Task #' . $taskId . ': ' . $task['status'] . ' -> ' . $status);
        flash('success', 'Task updated to "' . RC_TASK_STATUSES[$status] . '".');
    } elseif ($action === 'delete') {
        db_query('DELETE FROM tasks WHERE id = ?', [$taskId]);
        admin_log('task_deleted', 'Task #' . $taskId . ' (' . $task['title'] . ') for user #' . $task['user_id']);
        flash('success', 'Task deleted.');
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);
$status = (string) ($_GET['status'] ?? '');
$userFilter = (int) ($_GET['user'] ?? 0);
$params = [];
$where = 'WHERE 1=1' . search_where(['t.title', 'u.name', 'u.email'], $q, $params);
if ($userFilter) {
    $where .= ' AND t.user_id = ?';
    $params[] = $userFilter;
}
$countParams = $params;
$countWhere = $where;
if ($status === 'overdue') {
    $where .= " AND t.status IN ('assigned', 'in_progress') AND t.due_date < CURDATE()";
} elseif (isset(RC_TASK_STATUSES[$status])) {
    $where .= ' AND t.status = ?';
    $params[] = $status;
} else {
    $status = '';
}
$counts = [];
foreach (db_rows("SELECT t.status, COUNT(*) n FROM tasks t JOIN users u ON u.id = t.user_id $countWhere GROUP BY t.status", $countParams) as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
$overdue = (int) db_value("SELECT COUNT(*) FROM tasks t JOIN users u ON u.id = t.user_id $countWhere AND t.status IN ('assigned', 'in_progress') AND t.due_date < CURDATE()", $countParams);
$p = paginate((int) db_value("SELECT COUNT(*) FROM tasks t JOIN users u ON u.id = t.user_id $where", $params), 25);
$rows = db_rows("SELECT t.*, u.name, u.email, a.name AS assigned_by_name FROM tasks t JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.assigned_by
                 $where ORDER BY FIELD(t.status, 'in_progress', 'assigned', 'done', 'cancelled'), t.due_date IS NULL, t.due_date, t.id DESC
                 LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$prefillUser = (int) old('user_id', (string) ($_GET['user_id'] ?? 0));

admin_page_start('Volunteer Tasks', [['label' => 'Volunteer applications', 'url' => admin_url('applications.php?type=volunteer'), 'icon' => 'heart', 'style' => 'ghost']]);
echo recruitment_admin_css();
?>
<div class="stat-cards">
    <a class="stat-card" href="?status=assigned"><span class="icon-circle gold"><?= icon('inbox') ?></span><div><strong><?= (int) ($counts['assigned'] ?? 0) ?></strong><small>Assigned</small></div></a>
    <a class="stat-card" href="?status=in_progress"><span class="icon-circle blue"><?= icon('activity') ?></span><div><strong><?= (int) ($counts['in_progress'] ?? 0) ?></strong><small>In progress</small></div></a>
    <a class="stat-card" href="?status=done"><span class="icon-circle"><?= icon('check-circle') ?></span><div><strong><?= (int) ($counts['done'] ?? 0) ?></strong><small>Done</small></div></a>
    <a class="stat-card" href="?status=overdue"><span class="icon-circle"><?= icon('clock') ?></span><div><strong><?= $overdue ?></strong><small>Overdue</small></div></a>
</div>

<div class="admin-grid-main">
    <div>
        <?php
        $userSelect = '<select name="user" aria-label="Volunteer"><option value="">All volunteers</option>';
        foreach ($assignees as $a) {
            $userSelect .= '<option value="' . (int) $a['id'] . '"' . ($userFilter === (int) $a['id'] ? ' selected' : '') . '>' . e($a['name']) . '</option>';
        }
        $userSelect .= '</select>';
        admin_filter_bar([], ['placeholder' => 'Task title, volunteer name or email', 'html' => $userSelect, 'active' => $userFilter > 0, 'hidden' => $status !== '' ? ['status' => $status] : []]);
        $chipQuery = array_filter(['q' => $q, 'user' => $userFilter ?: null]);
        ?>
        <nav class="rc-chipbar" aria-label="Filter by status">
            <a href="?<?= e(http_build_query($chipQuery)) ?>"<?= $status === '' ? ' class="active"' : '' ?>>All <em><?= array_sum($counts) ?></em></a>
            <?php foreach (RC_TASK_STATUSES as $k => $label): ?><a href="?<?= e(http_build_query($chipQuery + ['status' => $k])) ?>"<?= $status === $k ? ' class="active"' : '' ?>><?= e($label) ?> <em><?= (int) ($counts[$k] ?? 0) ?></em></a><?php endforeach; ?>
            <a href="?<?= e(http_build_query($chipQuery + ['status' => 'overdue'])) ?>"<?= $status === 'overdue' ? ' class="active"' : '' ?>>Overdue <em><?= $overdue ?></em></a>
        </nav>
        <div class="panel">
            <?php if ($rows): ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Task</th><th>Volunteer</th><th>Due</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $isOverdue = in_array($r['status'], ['assigned', 'in_progress'], true) && $r['due_date'] && $r['due_date'] < date('Y-m-d'); ?>
                        <tr>
                            <td><strong><?= e($r['title']) ?></strong><?php if ($r['details']): ?><br><small class="muted"><?= e(excerpt($r['details'], 120)) ?></small><?php endif; ?><br><small class="muted">Assigned <?= e(format_date($r['created_at'])) ?><?= $r['assigned_by_name'] ? ' by ' . e($r['assigned_by_name']) : '' ?></small></td>
                            <td><?= e($r['name']) ?><br><small class="muted"><?= e($r['email']) ?></small></td>
                            <td class="nowrap"><?= $r['due_date'] ? e(format_date($r['due_date'])) : '<span class="muted">-</span>' ?><?= $isOverdue ? '<br><span class="badge badge-red">Overdue</span>' : '' ?></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <select name="status" class="rc-task-status" data-autosubmit aria-label="Status of task <?= e($r['title']) ?>">
                                        <?php foreach (RC_TASK_STATUSES as $k => $label): ?><option value="<?= e($k) ?>"<?= $r['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                                    </select>
                                    <noscript><button class="btn btn-ghost btn-sm" type="submit">Save</button></noscript>
                                </form>
                                <?php if ($r['completed_at']): ?><br><small class="muted">Done <?= e(format_date($r['completed_at'])) ?></small><?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <form method="post" class="inline-form" data-confirm="Delete this task permanently?">
                                    <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="icon-btn danger" type="submit" aria-label="Delete task <?= e($r['title']) ?>" title="Delete"><?= icon('trash') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?= pagination_links($p) ?>
            <?php else: ?>
                <?= empty_state($q !== '' || $status !== '' || $userFilter ? 'No tasks match these filters.' : 'No tasks yet. Assign the first task using the form.') ?>
            <?php endif; ?>
        </div>
    </div>

    <section class="panel" id="new-task">
        <div class="panel-head"><h2>Assign a task</h2></div>
        <?php if ($assignees): ?>
            <form method="post" class="rc-stack">
                <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="create">
                <div class="form-group"><label for="user_id">Volunteer / teacher<span class="req">*</span></label>
                    <select id="user_id" name="user_id" required><option value="">Select</option>
                        <?php foreach ($assignees as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $prefillUser === (int) $a['id'] ? ' selected' : '' ?>><?= e($a['name'] . ' (' . $a['roles'] . ')') ?></option><?php endforeach; ?>
                    </select><?= field_error('user_id') ?></div>
                <div class="form-group"><label for="title">Title<span class="req">*</span></label><input id="title" name="title" required maxlength="200" value="<?= e(old('title')) ?>"><?= field_error('title') ?></div>
                <div class="form-group"><label for="details">Details</label><textarea id="details" name="details" rows="4" maxlength="2000"><?= e(old('details')) ?></textarea><?= field_error('details') ?></div>
                <div class="form-group"><label for="due_date">Due date</label><input id="due_date" type="date" name="due_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e(old('due_date')) ?>"><?= field_error('due_date') ?></div>
                <label class="checkbox small"><input type="checkbox" name="notify" value="1" checked><span>Email the volunteer (an in-app notification is always added)</span></label>
                <button class="btn btn-primary btn-sm" type="submit"><?= icon('plus') ?>Assign task</button>
            </form>
        <?php else: ?>
            <p class="muted small">No active users hold the Registered Volunteer or Volunteer Teacher role yet. Roles are added automatically when an application is set to <strong>Active</strong>, or by an administrator in Users &amp; Roles.</p>
        <?php endif; ?>
    </section>
</div>
<?php
admin_page_end();
