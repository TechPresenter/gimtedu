<?php
require_once __DIR__ . '/functions.php';

$slug = (string) ($_GET['slug'] ?? $_POST['slug'] ?? '');
$event = db_row("SELECT * FROM events WHERE slug = ? AND status IN ('published','cancelled')", [$slug]);
if (!$event) {
    not_found();
}
$self = 'event-details.php?slug=' . urlencode($event['slug']);
$registered = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status <> 'cancelled'", [$event['id']]);
$isUpcoming = strtotime($event['start_datetime']) > time();
$isFull = $event['capacity'] !== null && $registered >= (int) $event['capacity'];
$canRegister = $event['status'] === 'published' && $event['registration_enabled'] && $isUpcoming && !$isFull;

// Registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($self);
    if (!$canRegister) {
        flash('error', 'Registration is closed for this event.');
        redirect($self);
    }
    if (honeypot_triggered()) {
        redirect($self);
    }
    [$data, $errors] = validate($_POST, [
        'name'    => 'required|min:2|max:120',
        'email'   => 'required|email',
        'phone'   => 'required|phone',
        'message' => 'text|max:500',
    ]);
    if ($errors) {
        back_with_errors($errors, $self . '#register', $_POST);
    }
    if (form_rate_limited('event', 10)) {
        flash('error', 'Too many registrations from your network. Please try again later.');
        redirect($self);
    }
    $exists = db_value('SELECT id FROM event_registrations WHERE event_id = ? AND email = ?', [$event['id'], $data['email']]);
    if ($exists) {
        flash('info', 'You are already registered for this event with this email address.');
        redirect($self);
    }
    db_insert('event_registrations', [
        'event_id' => $event['id'],
        'user_id'  => current_user_id(),
        'name'     => $data['name'],
        'email'    => $data['email'],
        'phone'    => $data['phone'],
        'message'  => $data['message'] ?: null,
    ]);
    log_activity('form_event', 'Registered for event #' . $event['id']);
    send_mail($data['email'], 'Registration confirmed: ' . $event['title'],
        '<p>Dear ' . e($data['name']) . ',</p><p>You are registered for <strong>' . e($event['title']) . '</strong>.</p>'
        . '<p><strong>When:</strong> ' . e(date('D, d M Y, h:i A', strtotime($event['start_datetime']))) . '<br><strong>Where:</strong> ' . e($event['location']) . '</p>'
        . '<p>We look forward to seeing you.</p>');
    flash('success', 'You are registered for this event. A confirmation has been sent to your email.');
    redirect($self);
}

$user = current_user();
$pageTitle = $event['title'];
$pageDescription = $event['summary'];
$ogImage = $event['image'] ?: null;
require __DIR__ . '/header.php';

page_banner($event['title'], $event['summary'], ['Events' => 'events.php']);
?>
<section class="section">
    <div class="container detail-layout">
        <div>
            <?php if ($event['status'] === 'cancelled'): ?>
                <div class="alert alert-error mb-3"><?= icon('alert-circle') ?><div>This event has been cancelled.</div></div>
            <?php endif; ?>
            <div class="detail-hero-img"><img src="<?= e(image_url($event['image'])) ?>" alt="<?= e($event['title']) ?>"></div>
            <article class="prose"><?= $event['description'] ?></article>
        </div>
        <aside class="sticky-card">
            <div class="raise-box">
                <h3>Event details</h3>
                <ul class="meta-list">
                    <li><?= icon('calendar') ?><span><?= e(date('l, d F Y', strtotime($event['start_datetime']))) ?></span></li>
                    <li><?= icon('clock') ?><span><?= e(date('h:i A', strtotime($event['start_datetime']))) ?><?= $event['end_datetime'] ? ' – ' . e(date(date('Y-m-d', strtotime($event['end_datetime'])) === date('Y-m-d', strtotime($event['start_datetime'])) ? 'h:i A' : 'd M, h:i A', strtotime($event['end_datetime']))) : '' ?></span></li>
                    <li><?= icon('map-pin') ?><span><?= e($event['location']) ?></span></li>
                    <?php if ($event['capacity']): ?>
                        <li><?= icon('users') ?><span><?= max(0, (int) $event['capacity'] - $registered) ?> of <?= (int) $event['capacity'] ?> places left</span></li>
                    <?php endif; ?>
                </ul>
                <div id="register">
                <?php if ($canRegister): ?>
                    <h3 class="mt-3">Register to attend</h3>
                    <form method="post" action="<?= e(url($self)) ?>" data-validate>
                        <?= csrf_field() ?>
                        <?= honeypot_field() ?>
                        <input type="hidden" name="slug" value="<?= e($event['slug']) ?>">
                        <div class="form-group mb-2">
                            <label for="name">Full name<span class="req">*</span></label>
                            <input id="name" type="text" name="name" required maxlength="120" value="<?= e(old('name', $user['name'] ?? '')) ?>">
                            <?= field_error('name') ?>
                        </div>
                        <div class="form-group mb-2">
                            <label for="email">Email<span class="req">*</span></label>
                            <input id="email" type="email" name="email" required maxlength="190" value="<?= e(old('email', $user['email'] ?? '')) ?>">
                            <?= field_error('email') ?>
                        </div>
                        <div class="form-group mb-2">
                            <label for="phone">Mobile number<span class="req">*</span></label>
                            <input id="phone" type="tel" name="phone" required maxlength="15" value="<?= e(old('phone', $user['phone'] ?? '')) ?>">
                            <?= field_error('phone') ?>
                        </div>
                        <div class="form-group mb-2">
                            <label for="message">Message (optional)</label>
                            <textarea id="message" name="message" maxlength="500" rows="3" style="min-height:80px"><?= e(old('message')) ?></textarea>
                        </div>
                        <button class="btn btn-primary btn-block" type="submit" data-loading="Registering…">Register Now</button>
                    </form>
                <?php elseif ($event['status'] === 'published' && $isUpcoming && $isFull): ?>
                    <div class="notice notice-gold mt-2"><?= icon('info') ?><div>Registrations are full for this event.</div></div>
                <?php elseif (!$isUpcoming): ?>
                    <div class="notice mt-2"><?= icon('info') ?><div>This event has already taken place.</div></div>
                <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
