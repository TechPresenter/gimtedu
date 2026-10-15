<?php
/** Team & management body: members shown on /management-body/ and /volunteers/ (Our Team). */
require_once __DIR__ . '/includes/auth.php';
require_permission('team.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only('team.php');
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $existing = $id ? db_row('SELECT * FROM team_members WHERE id = ?', [$id]) : null;

    if ($action === 'save') {
        $back = 'admin/team.php?' . ($existing ? 'edit=' . $id : 'new=1');
        [$data, $errors] = validate($_POST, [
            'name' => 'required|min:2|max:120',
            'designation' => 'required|min:2|max:150',
            'member_group' => 'required|in:' . implode(',', array_keys(TEAM_GROUPS)),
            'bio' => 'text|max:2000',
            'linkedin_url' => 'url|max:255',
            'sort_order' => 'integer|min:-9999|max:9999',
            'status' => 'required|in:published,hidden',
        ], ['member_group' => 'Group', 'linkedin_url' => 'LinkedIn profile']);
        if (empty($errors['linkedin_url']) && $data['linkedin_url'] !== '' && info_linkedin_url($data['linkedin_url']) === '') {
            $errors['linkedin_url'] = 'Enter a LinkedIn profile address starting with https://www.linkedin.com/';
        }
        if ($errors) {
            back_with_errors($errors, $back, $_POST);
        }
        $row = [
            'name' => $data['name'], 'designation' => $data['designation'], 'member_group' => $data['member_group'],
            'bio' => $data['bio'] !== '' ? $data['bio'] : null, 'linkedin_url' => $data['linkedin_url'] !== '' ? $data['linkedin_url'] : null,
            'sort_order' => (int) ($data['sort_order'] ?: 0), 'status' => $data['status'],
        ];
        $photo = admin_upload_image('photo', 'team', $data['name']);
        if ($photo === null && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $_SESSION['_old'] = array_diff_key($_POST, ['csrf_token' => 1]); // admin_upload_image() already explained the problem
            redirect($back);
        }
        if ($photo) {
            $row['photo'] = $photo;
        } elseif ($existing && !empty($_POST['remove_photo'])) {
            $row['photo'] = null;
        }
        if ($existing) {
            db_update('team_members', $row, 'id = :id', ['id' => $id]);
            admin_log('team_member_updated', 'Team member #' . $id . ' ' . $data['name']);
            flash('success', 'Member updated.');
        } else {
            $id = db_insert('team_members', $row);
            admin_log('team_member_created', 'Team member #' . $id . ' ' . $data['name']);
            flash('success', 'Member added.');
        }
        admin_redirect('team.php?group=' . $data['member_group']);
    }
    if ($action === 'toggle' && $existing) {
        $new = $existing['status'] === 'published' ? 'hidden' : 'published';
        db_update('team_members', ['status' => $new], 'id = :id', ['id' => $id]);
        admin_log('team_member_status', 'Team member #' . $id . ' ' . $new);
        flash('success', $new === 'published' ? 'Member is now shown on the website.' : 'Member hidden from the website.');
    }
    if ($action === 'delete' && $existing) {
        db_query('DELETE FROM team_members WHERE id = ?', [$id]);
        admin_log('team_member_deleted', 'Team member #' . $id . ' ' . $existing['name']);
        flash('success', 'Member deleted. The photo remains in the media library.');
    }
    admin_redirect(admin_back('team.php'));
}

$edit = isset($_GET['edit']) ? db_row('SELECT * FROM team_members WHERE id = ?', [(int) $_GET['edit']]) : null;
$showForm = $edit || isset($_GET['new']);
$group = (string) ($_GET['group'] ?? '');
$params = [];
$where = 'WHERE 1=1';
if (isset(TEAM_GROUPS[$group])) {
    $where .= ' AND member_group = ?';
    $params[] = $group;
}
$q = trim((string) ($_GET['q'] ?? ''));
$where .= search_where(['name', 'designation'], $q, $params);
$rows = db_rows("SELECT * FROM team_members $where ORDER BY FIELD(member_group, 'management', 'advisor', 'team', 'volunteer'), sort_order, name", $params);
$counts = array_column(db_rows('SELECT member_group, COUNT(*) n FROM team_members GROUP BY member_group'), 'n', 'member_group');

admin_page_start('Team & Management', [['label' => 'Add member', 'url' => admin_url('team.php?new=1'), 'icon' => 'plus']]);
?>
<p class="muted">Members appear on <a href="<?= e(page_url('management-body')) ?>" target="_blank" rel="noopener">Management Body</a> (management, advisors) and <a href="<?= e(page_url('volunteers')) ?>" target="_blank" rel="noopener">Our Team</a> (team, volunteers). Add people only with their agreement, and use their real designation.</p>
<nav class="admin-tabs" aria-label="Groups">
    <a href="<?= e(admin_url('team.php')) ?>" class="<?= $group === '' ? 'active' : '' ?>">All (<?= array_sum(array_map('intval', $counts)) ?>)</a>
    <?php foreach (TEAM_GROUPS as $k => $label): ?>
        <a href="<?= e(admin_url('team.php?group=' . $k)) ?>" class="<?= $group === $k ? 'active' : '' ?>"><?= e($label) ?> (<?= (int) ($counts[$k] ?? 0) ?>)</a>
    <?php endforeach; ?>
</nav>

<div class="admin-grid-main">
    <section class="panel">
        <?php admin_filter_bar([], ['placeholder' => 'Search name or designation', 'hidden' => $group !== '' ? ['group' => $group] : []]); ?>
        <?php if (!$rows): ?>
            <p class="muted">No members yet. The public pages show "Details will be published here soon" until members are added.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Photo</th><th>Name</th><th>Group</th><th>Order</th><th>Status</th><th class="actions-cell">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php if ($r['photo'] && is_file(ROOT_PATH . '/' . $r['photo'])): ?><img class="thumb" src="<?= e(url($r['photo'])) ?>" alt=""><?php else: ?><span class="avatar"><?= e(info_initials($r['name'])) ?></span><?php endif; ?></td>
                        <td><strong><?= e($r['name']) ?></strong><br><small class="muted"><?= e($r['designation']) ?></small></td>
                        <td><?= e(TEAM_GROUPS[$r['member_group']] ?? $r['member_group']) ?></td>
                        <td><?= (int) $r['sort_order'] ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="actions-cell">
                            <a class="icon-btn" href="<?= e(admin_url('team.php?edit=' . $r['id'])) ?>" title="Edit" aria-label="Edit <?= e($r['name']) ?>"><?= icon('edit') ?></a>
                            <form method="post" class="inline-form"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn" type="submit" title="<?= $r['status'] === 'published' ? 'Hide' : 'Publish' ?>" aria-label="<?= $r['status'] === 'published' ? 'Hide' : 'Publish' ?> <?= e($r['name']) ?>"><?= icon($r['status'] === 'published' ? 'eye' : 'check') ?></button></form>
                            <form method="post" class="inline-form" data-confirm="Delete <?= e($r['name']) ?> from the team list?"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn danger" type="submit" title="Delete" aria-label="Delete <?= e($r['name']) ?>"><?= icon('trash') ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><h2><?= $edit ? 'Edit member' : 'Add a member' ?></h2><?php if ($edit): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('team.php')) ?>">Cancel</a><?php endif; ?></div>
        <?php if (!$showForm): ?>
            <p class="muted">Choose <strong>Add member</strong> or the edit icon next to a person.</p>
            <a class="btn btn-primary btn-sm" href="<?= e(admin_url('team.php?new=1')) ?>"><?= icon('plus') ?>Add member</a>
        <?php else: $m = $edit ?: []; ?>
            <form method="post" enctype="multipart/form-data" class="form-grid" data-validate>
                <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($m['id'] ?? 0) ?>">
                <div class="form-group full"><label for="name">Full name</label><input type="text" id="name" name="name" required maxlength="120" value="<?= e(old('name', $m['name'] ?? '')) ?>"><?= field_error('name') ?></div>
                <div class="form-group full"><label for="designation">Designation</label><input type="text" id="designation" name="designation" required maxlength="150" placeholder="e.g. President, Programme Coordinator" value="<?= e(old('designation', $m['designation'] ?? '')) ?>"><?= field_error('designation') ?></div>
                <div class="form-group"><label for="member_group">Group</label><select id="member_group" name="member_group"><?= options_html(TEAM_GROUPS, (string) old('member_group', $m['member_group'] ?? ($group ?: 'team')), true, '') ?></select></div>
                <div class="form-group"><label for="sort_order">Order</label><input id="sort_order" type="number" name="sort_order" value="<?= e(old('sort_order', (string) ($m['sort_order'] ?? 0))) ?>"><?= field_error('sort_order') ?></div>
                <div class="form-group full"><label for="bio">Short bio</label><textarea id="bio" name="bio" rows="5" maxlength="2000"><?= e(old('bio', $m['bio'] ?? '')) ?></textarea><?= field_error('bio') ?></div>
                <div class="form-group full"><label for="linkedin_url">LinkedIn profile (optional)</label><input id="linkedin_url" type="url" name="linkedin_url" maxlength="255" placeholder="https://www.linkedin.com/in/..." value="<?= e(old('linkedin_url', $m['linkedin_url'] ?? '')) ?>"><?= field_error('linkedin_url') ?></div>
                <div class="form-group full"><label for="photo">Photo (JPG, PNG or WebP, square works best)</label>
                    <?php if (!empty($m['photo']) && is_file(ROOT_PATH . '/' . $m['photo'])): ?><img src="<?= e(url($m['photo'])) ?>" alt="Current photo" class="thumb" style="width:88px;height:88px;border-radius:50%"><label class="checkbox"><input type="checkbox" name="remove_photo" value="1"><span>Remove current photo</span></label><?php endif; ?>
                    <input id="photo" type="file" name="photo" accept=".jpg,.jpeg,.png,.webp"><?= field_error('photo') ?><span class="help">Use a photo only with the person's permission.</span></div>
                <div class="form-group"><label for="status">Status</label><select id="status" name="status"><?= options_html(['published' => 'Published', 'hidden' => 'Hidden'], (string) old('status', $m['status'] ?? 'published'), true, '') ?></select></div>
                <div class="form-actions full" style="grid-column:1/-1"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save member</button></div>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php
admin_page_end();
