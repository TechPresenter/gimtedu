<?php
require_once __DIR__ . '/functions.php';

$show = ($_GET['show'] ?? '') === 'past' ? 'past' : 'upcoming';
$where = "status IN ('published','cancelled') AND " . ($show === 'past'
    ? 'COALESCE(end_datetime, start_datetime) < NOW()'
    : 'COALESCE(end_datetime, start_datetime) >= NOW()');
$order = $show === 'past' ? 'start_datetime DESC' : 'start_datetime ASC';
$p = paginate((int) db_value("SELECT COUNT(*) FROM events WHERE $where"), 9);
$events = db_rows("SELECT * FROM events WHERE $where ORDER BY $order LIMIT {$p['per_page']} OFFSET {$p['offset']}");

$pageTitle = 'Events';
$pageDescription = 'Upcoming and past events, drives and camps organised by Prerit Welfare Foundation.';
require __DIR__ . '/header.php';

page_banner('Events', 'Camps, drives, awareness programmes and community events');
?>
<section class="section">
    <div class="container">
        <nav class="filter-bar" aria-label="Event filter">
            <a class="filter-btn<?= $show === 'upcoming' ? ' is-active' : '' ?>" href="<?= e(url('events.php')) ?>">Upcoming events</a>
            <a class="filter-btn<?= $show === 'past' ? ' is-active' : '' ?>" href="<?= e(url('events.php?show=past')) ?>">Past events</a>
        </nav>
        <?php if ($events): ?>
            <div class="grid grid-3"><?php foreach ($events as $ev) { echo event_card($ev); } ?></div>
            <?= pagination_links($p) ?>
        <?php else: ?>
            <?= empty_state($show === 'past' ? 'No past events to show yet.' : 'No upcoming events are scheduled right now. Please check back soon or subscribe to our newsletter.') ?>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
