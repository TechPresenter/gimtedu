<?php
/**
 * Success stories: list with status / consent filters and search; delete.
 * A story is shown publicly only when it is published AND its written consent is confirmed.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('stories.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('stories.php');
    $id = (int) ($_POST['id'] ?? 0);
    $story = $id ? db_row('SELECT * FROM success_stories WHERE id = ?', [$id]) : null;
    if ($story && ($_POST['action'] ?? '') === 'delete') {
        db_query('DELETE FROM success_stories WHERE id = ?', [$id]);
        content_delete_image($story['image']);
        admin_log('story_deleted', 'Story #' . $id . ' "' . $story['title'] . '" deleted');
        flash('success', 'The story "' . $story['title'] . '" was deleted.');
    }
    admin_redirect(admin_back('stories.php'));
}

$status = in_array($_GET['status'] ?? '', ['draft', 'published', 'archived'], true) ? (string) $_GET['status'] : '';
$consent = in_array($_GET['consent'] ?? '', ['yes', 'no'], true) ? (string) $_GET['consent'] : '';
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 100));
$where = 'WHERE 1';
$params = [];
if ($status !== '') {
    $where .= ' AND s.status = ?';
    $params[] = $status;
}
if ($consent !== '') {
    $where .= ' AND s.consent_confirmed = ' . ($consent === 'yes' ? 1 : 0);
}
$where .= search_where(['s.title', 's.slug', 's.person_name', 's.location'], $q, $params);
$p = paginate((int) db_value("SELECT COUNT(*) FROM success_stories s $where", $params), 20);
$rows = db_rows("SELECT s.*, pr.title AS program_title FROM success_stories s LEFT JOIN programs pr ON pr.id = s.program_id $where
                 ORDER BY (s.status = 'draft') DESC, COALESCE(s.published_at, s.created_at) DESC, s.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$counts = db_row('SELECT SUM(' . story_visible_sql() . ") AS live, SUM(status = 'draft') AS drafts, SUM(consent_confirmed = 0) AS no_consent FROM success_stories");

admin_page_start('Success Stories', [['label' => 'New story', 'url' => admin_url('story-edit.php'), 'icon' => 'plus']]);
?>
<div class="stat-cards">
    <a class="stat-card" href="<?= e(admin_url('stories.php?status=published&consent=yes')) ?>"><span class="icon-circle"><?= icon('check-circle') ?></span><span><strong><?= (int) $counts['live'] ?></strong><small>Visible on the website</small></span></a>
    <a class="stat-card" href="<?= e(admin_url('stories.php?status=draft')) ?>"><span class="icon-circle gold"><?= icon('edit') ?></span><span><strong><?= (int) $counts['drafts'] ?></strong><small>Drafts</small></span></a>
    <a class="stat-card" href="<?= e(admin_url('stories.php?consent=no')) ?>"><span class="icon-circle blue"><?= icon('shield') ?></span><span><strong><?= (int) $counts['no_consent'] ?></strong><small>Awaiting consent</small></span></a>
</div>
<div class="alert alert-info"><?= icon('shield') ?><div>Publish a story only when the person's <strong>written consent</strong> (and a parent or guardian's for anyone under 18) is on file. Stories without confirmed consent are never shown on the website.</div></div>

<?php
$consentSelect = '<label class="sr-only" for="f-consent">Consent</label><select id="f-consent" name="consent"><option value="">Consent: any</option>'
    . '<option value="yes"' . ($consent === 'yes' ? ' selected' : '') . '>Consent confirmed</option><option value="no"' . ($consent === 'no' ? ' selected' : '') . '>Consent not confirmed</option></select>';
admin_filter_bar(['draft', 'published', 'archived'], ['placeholder' => 'Search title, person or location', 'html' => $consentSelect, 'active' => $consent !== '']);
?>

<section class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Story</th><th>Person</th><th>Consent</th><th>Status</th><th>Published</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><div style="display:flex;gap:12px;align-items:center;min-width:220px">
                        <img class="thumb" src="<?= e(image_url($r['image'], 'assets/images/hero/community.svg')) ?>" alt="" width="64" height="44" loading="lazy">
                        <div><a href="<?= e(admin_url('story-edit.php?id=' . (int) $r['id'])) ?>"><strong><?= e($r['title']) ?></strong></a><br>
                            <small class="muted">/success-stories/<?= e($r['slug']) ?>/<?= $r['program_title'] ? ' · ' . e($r['program_title']) : '' ?></small></div></div></td>
                    <td><?= e($r['person_name'] ?: '-') ?><?= $r['location'] ? '<br><small class="muted">' . e($r['location']) . '</small>' : '' ?></td>
                    <td><?= (int) $r['consent_confirmed'] === 1 ? '<span class="badge badge-green">Confirmed</span>' : '<span class="badge badge-gold">Not confirmed</span>' ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= $r['published_at'] ? e(format_date($r['published_at'])) : '<span class="muted">-</span>' ?></td>
                    <td class="actions-cell">
                        <a class="icon-btn" href="<?= e(admin_url('story-edit.php?id=' . (int) $r['id'])) ?>" aria-label="Edit <?= e($r['title']) ?>" title="Edit"><?= icon('edit') ?></a>
                        <a class="icon-btn" href="<?= e(story_url($r['slug'])) ?>" target="_blank" rel="noopener" aria-label="View <?= e($r['title']) ?>" title="View / preview"><?= icon('eye') ?></a>
                        <form class="inline-form" method="post" data-confirm="Delete this story permanently?">
                            <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="icon-btn danger" type="submit" aria-label="Delete <?= e($r['title']) ?>" title="Delete"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <div class="empty-state"><?= icon('star') ?><p><?= $q !== '' || $status !== '' || $consent !== '' ? 'No stories match these filters.' : 'No stories yet. Add a story once the person has agreed in writing to share it.' ?></p>
            <a class="btn btn-primary" href="<?= e(admin_url('story-edit.php')) ?>"><?= icon('plus') ?>New story</a></div>
    <?php endif; ?>
</section>
<?php
admin_page_end();
