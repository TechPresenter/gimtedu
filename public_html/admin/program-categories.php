<?php
/**
 * Programme categories (Programs & Events): list in display order, publish / hide (category + landing page),
 * show in the "Programs & Events" menu, event counts. Tab "Interest list": "notify me" sign-ups (program_interests),
 * which hold personal data and need the events.registrations permission.
 */
require_once __DIR__ . '/includes/auth.php';

$canCats = admin_can('programs.manage');
$canPeople = admin_can('events.registrations');
if (!$canCats && !$canPeople) {
    require_permission('programs.manage');
}
$tab = (($_GET['tab'] ?? '') === 'interests' && $canPeople) || !$canCats ? 'interests' : 'categories';
const PH_INTEREST_STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'notified' => 'Notified of a session', 'closed' => 'Closed'];

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('program-categories.php');
    $action = programhub_admin_param($_POST, 'action');
    $back = admin_back('program-categories.php');

    if (in_array($action, ['interest_status', 'interest_delete'], true)) {
        require_permission('events.registrations');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
        if (!$ids) {
            flash('error', 'Select at least one sign-up first.');
            admin_redirect($back);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($action === 'interest_delete') {
            $n = db_query("DELETE FROM program_interests WHERE id IN ($in)", $ids)->rowCount();
            admin_log('program_interests_deleted', $n . ' interest sign-up(s) deleted (#' . implode(', #', array_slice($ids, 0, 30)) . ')');
            flash('success', $n . ' sign-up' . ($n === 1 ? '' : 's') . ' deleted.');
        } else {
            $status = programhub_admin_param($_POST, 'status');
            if (!isset(PH_INTEREST_STATUSES[$status])) {
                flash('error', 'Choose a status.');
                admin_redirect($back);
            }
            $n = db_query("UPDATE program_interests SET status = ?, notified_at = IF(? = 'notified', COALESCE(notified_at, NOW()), notified_at) WHERE id IN ($in)",
                array_merge([$status, $status], $ids))->rowCount();
            admin_log('program_interests_updated', $n . ' interest sign-up(s) set to ' . $status . ' (#' . implode(', #', array_slice($ids, 0, 30)) . ')');
            flash('success', $n . ' sign-up' . ($n === 1 ? '' : 's') . ' marked as ' . strtolower(PH_INTEREST_STATUSES[$status]) . '.');
        }
        admin_redirect($back);
    }

    require_permission('programs.manage');
    if ($action === 'order') {
        $n = 0;
        foreach ((array) ($_POST['sort'] ?? []) as $cid => $sort) {
            if (is_scalar($sort) && preg_match('/^-?\d{1,6}$/', trim((string) $sort))) {
                $n += db_update('program_categories', ['sort_order' => (int) $sort], 'id = :id', ['id' => (int) $cid]);
            }
        }
        program_categories_all(false, true);
        admin_log('program_categories_reordered', 'Display order of programme categories saved (' . $n . ' changed)');
        flash('success', 'Display order saved.');
        admin_redirect($back);
    }
    $id = (int) ($_POST['id'] ?? 0);
    $cat = $id ? db_row('SELECT * FROM program_categories WHERE id = ?', [$id]) : null;
    if (!$cat) {
        flash('error', 'Category not found.');
        admin_redirect($back);
    }
    if ($action === 'toggle_status') {
        $new = $cat['status'] === 'published' ? 'draft' : 'published';
        $note = programhub_admin_set_category_status($cat, $new);
        admin_log('program_category_' . ($new === 'published' ? 'published' : 'hidden'), 'Programme category #' . $id . ' "' . $cat['title'] . '"' . $note);
        flash('success', '"' . $cat['title'] . '" is now ' . ($new === 'published' ? 'published' : 'hidden from visitors') . '.' . $note);
    } elseif ($action === 'toggle_nav') {
        $new = (int) $cat['show_in_nav'] === 1 ? 0 : 1;
        db_update('program_categories', ['show_in_nav' => $new], 'id = :id', ['id' => $id]);
        program_categories_all(false, true);
        admin_log('program_category_updated', 'Programme category #' . $id . ' "' . $cat['title'] . '": show in menu ' . ($new ? 'on' : 'off'));
        flash('success', '"' . $cat['title'] . '" will ' . ($new ? 'now' : 'no longer') . ' be listed in the Programs & Events menu.');
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// Interest list (personal data)
// ---------------------------------------------------------------------
if ($tab === 'interests') {
    $q = trim(mb_substr(clean_string($_GET['q'] ?? ''), 0, 100));
    $status = isset(PH_INTEREST_STATUSES[programhub_admin_param($_GET, 'status')]) ? (string) $_GET['status'] : '';
    $catId = (int) ($_GET['category'] ?? 0);
    $params = [];
    $where = 'WHERE 1=1' . search_where(['i.name', 'i.email', 'i.phone', 'i.city'], $q, $params);
    if ($catId > 0) {
        $where .= ' AND i.category_id = ?';
        $params[] = $catId;
    }
    $countParams = $params;
    $countWhere = $where;
    if ($status !== '') {
        $where .= ' AND i.status = ?';
        $params[] = $status;
    }
    $from = 'FROM program_interests i LEFT JOIN program_categories c ON c.id = i.category_id LEFT JOIN events ev ON ev.id = i.event_id';

    if (isset($_GET['export'])) {
        require_permission('exports.run');
        admin_log('program_interests_exported', 'Interest sign-ups exported' . ($catId ? ' (category #' . $catId . ')' : '') . ($status ? ' (' . $status . ')' : ''));
        // distinct aliases: two columns named "title" would collapse into one key and shift every later CSV column
        $rows = db_rows("SELECT i.id, i.name, i.email, i.phone, i.city, c.title AS category_title, ev.title AS event_title, i.message, i.status, i.consent_at, i.notified_at, i.created_at $from $where ORDER BY i.id", $params);
        send_csv('interest-signups-' . date('Y-m-d') . '.csv', ['ID', 'Name', 'Email', 'Mobile', 'City', 'Category', 'Event', 'Message', 'Status', 'Consent given', 'Notified', 'Signed up'], $rows);
    }

    $counts = array_fill_keys(array_keys(PH_INTEREST_STATUSES), 0);
    foreach (db_rows("SELECT i.status, COUNT(*) AS n $from $countWhere GROUP BY i.status", $countParams) as $r) {
        $counts[$r['status']] = (int) $r['n'];
    }
    $p = paginate((int) db_value("SELECT COUNT(*) $from $where", $params), 50);
    $rows = db_rows("SELECT i.*, c.title AS category_title, ev.title AS event_title $from $where ORDER BY i.created_at DESC, i.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
}

// ---------------------------------------------------------------------
// Categories list
// ---------------------------------------------------------------------
if ($tab === 'categories') {
    $cats = program_categories_all(false, true);
    $stats = programhub_admin_category_counts();
    $totals = ['all' => count($cats), 'published' => 0, 'nav' => 0, 'upcoming' => 0];
    foreach ($cats as $c) {
        $totals['published'] += $c['status'] === 'published' && $c['page_status'] === 'published' ? 1 : 0;
        $totals['nav'] += (int) $c['show_in_nav'] === 1 && $c['status'] === 'published' ? 1 : 0;
        $totals['upcoming'] += (int) ($stats[(int) $c['id']]['upcoming'] ?? 0);
    }
    $newInterests = $canPeople ? (int) db_value("SELECT COUNT(*) FROM program_interests WHERE status = 'new'") : 0;
}

$tabUrl = function (string $t): string {
    return admin_url('program-categories.php' . ($t === 'interests' ? '?tab=interests' : ''));
};
admin_page_start($tab === 'interests' ? 'Programme interest sign-ups' : 'Programme categories', $canCats ? [
    ['label' => 'New category', 'url' => admin_url('program-category-edit.php'), 'icon' => 'plus'],
    ['label' => 'Events', 'url' => admin_url('events.php'), 'icon' => 'calendar', 'style' => 'ghost'],
] : [['label' => 'Events', 'url' => admin_url('events.php'), 'icon' => 'calendar', 'style' => 'ghost']]);
?>
<div class="ph-admin">
<?php if ($canCats && $canPeople): ?>
    <nav class="admin-tabs" aria-label="Programme categories">
        <a class="<?= $tab === 'categories' ? 'active' : '' ?>" href="<?= e($tabUrl('categories')) ?>"<?= $tab === 'categories' ? ' aria-current="page"' : '' ?>><?= icon('layers') ?>Categories</a>
        <a class="<?= $tab === 'interests' ? 'active' : '' ?>" href="<?= e($tabUrl('interests')) ?>"<?= $tab === 'interests' ? ' aria-current="page"' : '' ?>><?= icon('bell') ?>Interest list<?php if ($tab === 'categories' && $newInterests): ?> <em><?= $newInterests ?> new</em><?php endif; ?></a>
    </nav>
<?php endif; ?>

<?php if ($tab === 'categories'): ?>
    <div class="stat-cards">
        <div class="stat-card"><span class="icon-circle"><?= icon('layers') ?></span><span><strong><?= $totals['all'] ?></strong><small>Categories</small></span></div>
        <div class="stat-card"><span class="icon-circle"><?= icon('check-circle') ?></span><span><strong><?= $totals['published'] ?></strong><small>Visible to visitors</small></span></div>
        <div class="stat-card"><span class="icon-circle blue"><?= icon('menu') ?></span><span><strong><?= $totals['nav'] ?></strong><small>In the Programs &amp; Events menu</small></span></div>
        <a class="stat-card" href="<?= e(admin_url('events.php')) ?>"><span class="icon-circle gold"><?= icon('calendar') ?></span><span><strong><?= $totals['upcoming'] ?></strong><small>Upcoming events in categories</small></span></a>
    </div>

    <section class="panel">
        <div class="panel-head"><h2><?= icon('layers') ?>All categories</h2><a class="btn btn-ghost btn-sm" href="<?= e(page_url('programs')) ?>" target="_blank" rel="noopener"><?= icon('external') ?>View /programs/</a></div>
        <p class="muted small">Each category has its own landing page (/programs/&lt;address&gt;/) with a banner, sections and live lists of its events. <strong>Edit</strong> changes the card, banner, main texts and SEO; <strong>Sections</strong> opens the full section editor. Publishing or hiding a category also publishes or hides its page. Lower order numbers are shown first.</p>
        <?php if (!$cats): ?>
            <?= empty_state('No programme categories yet.', 'New category', 'admin/program-category-edit.php') ?>
        <?php else: ?>
            <form id="order-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="order"></form>
            <div class="table-wrap"><table class="table">
                <thead><tr><th scope="col">Order</th><th scope="col">Category</th><th scope="col">Page</th><th scope="col">Status</th><th scope="col">In menu</th><th scope="col">Events</th><?php if ($canPeople): ?><th scope="col">Interest</th><?php endif; ?><th scope="col" class="text-right">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($cats as $c): $cid = (int) $c['id']; $s = $stats[$cid] ?? ['upcoming' => 0, 'past' => 0, 'drafts' => 0, 'total' => 0, 'interests_new' => 0]; ?>
                    <tr>
                        <td><input type="number" name="sort[<?= $cid ?>]" value="<?= (int) $c['sort_order'] ?>" form="order-form" style="width:76px" aria-label="Display order of <?= e($c['title']) ?>"></td>
                        <td><div class="ph-item">
                            <img class="thumb" src="<?= e(image_url(program_category_art($c))) ?>" alt="" width="64" height="44" style="object-fit:contain">
                            <div><a href="<?= e(admin_url('program-category-edit.php?id=' . $cid)) ?>"><strong><?= e($c['title']) ?></strong></a>
                                <small><span class="ph-tone tone-<?= e((string) $c['tone']) ?>" aria-hidden="true"></span><?= e(program_tones()[$c['tone']] ?? humanize((string) $c['tone'])) ?> · <?= icon((string) ($c['icon'] ?: 'calendar')) ?> <?= e((string) $c['icon']) ?><?= $c['default_event_type'] ? ' · new events: ' . e(event_type_label($c['default_event_type'])) : '' ?></small></div></div></td>
                        <td><?php if ($c['page_slug']): ?><a class="small" style="word-break:break-all" href="<?= e(program_category_url($c) . ($c['status'] !== 'published' || $c['page_status'] !== 'published' ? '?preview=1' : '')) ?>" target="_blank" rel="noopener">/<?= e($c['page_slug']) ?>/</a><br><?= status_badge($c['page_status']) ?><?php else: ?><span class="badge badge-red">No page</span><?php endif; ?></td>
                        <td><form method="post" class="inline-form"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= $cid ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" title="<?= $c['status'] === 'published' ? 'Hide (also unpublishes its page)' : 'Publish (also publishes its page)' ?>" aria-label="<?= $c['status'] === 'published' ? 'Hide' : 'Publish' ?> <?= e($c['title']) ?>"><?= status_badge($c['status'] === 'published' ? 'published' : 'hidden') ?></button></form></td>
                        <td><form method="post" class="inline-form"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="toggle_nav"><input type="hidden" name="id" value="<?= $cid ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" aria-label="<?= (int) $c['show_in_nav'] ? 'Remove from' : 'Add to' ?> the menu: <?= e($c['title']) ?>"><?= icon((int) $c['show_in_nav'] ? 'check-circle' : 'x') ?><?= (int) $c['show_in_nav'] ? 'Shown' : 'Not shown' ?></button></form></td>
                        <td class="small nowrap"><a href="<?= e(admin_url('events.php?category=' . $cid . '&when=all')) ?>"><?= (int) $s['upcoming'] ?> upcoming</a><br><span class="muted"><?= (int) $s['past'] ?> past · <?= (int) $s['drafts'] ?> draft/scheduled</span></td>
                        <?php if ($canPeople): ?><td class="small nowrap"><?php if ((int) $s['interests_new']): ?><a href="<?= e(admin_url('program-categories.php?tab=interests&status=new&category=' . $cid)) ?>"><?= (int) $s['interests_new'] ?> new</a><?php else: ?><span class="muted">-</span><?php endif; ?></td><?php endif; ?>
                        <td class="actions-cell">
                            <a class="icon-btn" href="<?= e(admin_url('program-category-edit.php?id=' . $cid)) ?>" title="Edit category" aria-label="Edit <?= e($c['title']) ?>"><?= icon('edit') ?></a>
                            <?php if ($c['page_id'] && admin_can('pages.manage')): ?><a class="icon-btn" href="<?= e(admin_url('page-edit.php?id=' . (int) $c['page_id'] . '&tab=sections')) ?>" title="Sections" aria-label="Edit the page sections of <?= e($c['title']) ?>"><?= icon('layers') ?></a><?php endif; ?>
                            <a class="icon-btn" href="<?= e(admin_url('event-edit.php?category=' . $cid)) ?>" title="New event in this category" aria-label="New event in <?= e($c['title']) ?>"><?= icon('plus') ?></a>
                            <?php if ($c['page_slug']): ?><a class="icon-btn" href="<?= e(program_category_url($c) . ($c['status'] !== 'published' || $c['page_status'] !== 'published' ? '?preview=1' : '')) ?>" target="_blank" rel="noopener" title="View page" aria-label="View the page of <?= e($c['title']) ?>"><?= icon('eye') ?></a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit" form="order-form"><?= icon('check') ?>Save order</button></div>
        <?php endif; ?>
    </section>
    <p class="muted small"><?= icon('info') ?> The <strong>Programs &amp; Events</strong> menu lists published categories marked "Shown", then Upcoming events, Hackathons and All programmes. Footer links are edited in <?= admin_can('menus.manage') ? '<a href="' . e(admin_url('menus.php')) . '">Menus</a>' : 'Menus' ?> ("Footer: Programs &amp; Events").</p>

<?php else: ?>
    <?php
    $catSelect = '<label class="sr-only" for="f-cat">Category</label><select id="f-cat" name="category"><option value="">All categories</option>';
    foreach (program_category_options() as $k => $label) {
        $catSelect .= '<option value="' . (int) $k . '"' . ($catId === (int) $k ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $catSelect .= '</select>';
    $hidden = ['tab' => 'interests'] + ($status !== '' ? ['status' => $status] : []);
    admin_filter_bar([], ['placeholder' => 'Search name, email, mobile or city', 'html' => $catSelect, 'active' => $catId > 0, 'hidden' => $hidden]);
    $chipQuery = array_filter(['tab' => 'interests', 'q' => $q, 'category' => $catId ?: '']);
    ?>
    <nav class="ph-chipbar" aria-label="Filter by status">
        <a href="?<?= e(http_build_query($chipQuery)) ?>"<?= $status === '' ? ' class="active" aria-current="page"' : '' ?>>All <em><?= array_sum($counts) ?></em></a>
        <?php foreach (PH_INTEREST_STATUSES as $k => $label): ?>
            <a href="?<?= e(http_build_query($chipQuery + ['status' => $k])) ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <em><?= (int) $counts[$k] ?></em></a>
        <?php endforeach; ?>
    </nav>
    <section class="panel">
        <div class="panel-head"><div><h2><?= icon('bell') ?>People who asked to hear about new sessions</h2><p class="muted small">Sign-ups from category pages when no session was open. Contact them when you schedule a session; mark them as notified.</p></div>
            <?php if (admin_can('exports.run') && $p['total']): ?><a class="btn btn-ghost btn-sm" href="?<?= e(http_build_query(array_filter(['tab' => 'interests', 'q' => $q, 'category' => $catId ?: '', 'status' => $status, 'export' => 1]))) ?>"><?= icon('download') ?>Export CSV</a><?php endif; ?></div>
        <?php if ($rows): ?>
            <form method="post" id="interest-form" data-ph-bulk-form><?= csrf_field() ?><?= return_field() ?></form>
            <div class="table-wrap"><table class="table">
                <thead><tr><th class="ph-check-col"><input type="checkbox" data-check-all=".ph-int-check" aria-label="Select all on this page"></th><th>Person</th><th>Interested in</th><th>Message</th><th>Signed up</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="ph-check-col"><input class="ph-int-check" type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="interest-form" aria-label="Select <?= e($r['name']) ?>"></td>
                        <td class="ph-person"><strong><?= e($r['name']) ?></strong><small><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></small><?php if ($r['phone']): ?><small><a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $r['phone'])) ?>"><?= e($r['phone']) ?></a></small><?php endif; ?><?php if ($r['city']): ?><small><?= e($r['city']) ?></small><?php endif; ?></td>
                        <td class="small"><?= e($r['category_title'] ?: 'Any programme') ?><?= $r['event_title'] ? '<br><span class="muted">' . e($r['event_title']) . '</span>' : '' ?></td>
                        <td class="small" style="max-width:260px"><?= $r['message'] ? e_nl($r['message']) : '<span class="muted">-</span>' ?></td>
                        <td class="nowrap small"><?= e(format_date($r['created_at'], 'd M Y')) ?><?= $r['notified_at'] ? '<br><span class="muted">Notified ' . e(format_date($r['notified_at'], 'd M Y')) . '</span>' : '' ?></td>
                        <td><span class="badge badge-<?= e(['new' => 'gold', 'contacted' => 'blue', 'notified' => 'green', 'closed' => 'gray'][$r['status']] ?? 'gray') ?>"><?= e(PH_INTEREST_STATUSES[$r['status']] ?? humanize($r['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?= pagination_links($p) ?>
            <div class="ph-bulkbar" data-ph-bulk=".ph-int-check">
                <span data-ph-count aria-live="polite">0 selected</span>
                <label class="sr-only" for="int-status">New status</label>
                <select id="int-status" name="status" form="interest-form"><?php foreach (PH_INTEREST_STATUSES as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === 'notified' ? ' selected' : '' ?>><?= e('Mark as: ' . $label) ?></option><?php endforeach; ?></select>
                <button class="btn btn-primary btn-sm" type="submit" form="interest-form" name="action" value="interest_status" data-ph-bulk-submit><?= icon('check') ?>Apply</button>
                <button class="btn btn-danger btn-sm" type="submit" form="interest-form" name="action" value="interest_delete" data-ph-bulk-submit data-confirm-click="Delete the selected sign-ups permanently (for example after a deletion request)?"><?= icon('trash') ?>Delete</button>
            </div>
        <?php else: ?>
            <?= empty_state($q !== '' || $status !== '' || $catId ? 'No sign-ups match these filters.' : 'Nobody has registered interest yet. Sign-ups appear here when visitors ask to be told about new sessions.') ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
</div>
<?php
programhub_admin_assets();
admin_page_end();
