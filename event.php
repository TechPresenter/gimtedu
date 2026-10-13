<?php
/**
 * Event detail (/events/{slug}) — banner hero, date / time / venue (map link: campus directions or the external venue),
 * organiser + contact, category, seats (live registrations vs capacity), description, date & timings, registration:
 * EnquiryForm island → POST /api/public/event-registration (event_id, name, email, phone, organization) shown only
 * while registration is really open (registration_enabled, deadline not passed, seats left, event not over/cancelled);
 * otherwise a clear status card (fully booked / closed / external link / cancelled / ended). Add to calendar: .ics
 * download (?ics=1, RFC 5545) + Google Calendar link. Share links, related upcoming events, photos of past events
 * from a linked gallery, BreadcrumbList + Event JSON-LD (status, attendance mode, location, organiser, capacity,
 * offers price 0 when online registration is enabled — website registration takes no payment).
 * Drafts / hidden / unknown events → 404.php.
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/includes/partials/notices_events.php';

$slug = strtolower(ne_get('slug'));
$now = ne_now();
$event = null;
if (preg_match('/^[a-z0-9-]{1,190}$/', $slug)) {
    [$w, $a] = ne_event_where(null, $now);
    $event = ne_try(fn () => db_row('SELECT ' . ne_event_columns() . " FROM events e WHERE e.slug = ? AND $w LIMIT 1", array_merge([$slug], $a)));
}
if (!$event) {
    ne_not_found('event');
}

require __DIR__ . '/includes/config.php';

/* ------------------------------------------------------------------ .ics download */
if (ne_get('ics') === '1') {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9-]/', '', (string) $event['slug']) . '.ics"');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-cache');
    echo ne_event_ics($event);
    exit;
}

/* ------------------------------------------------------------------ data */
$st = ne_event_state($event);
$when = ne_event_when($event);
$cat = ne_event_category((string) $event['category']);
$venue = trim((string) $event['venue']);
$online = ne_event_online($venue);
$map = ne_event_map($venue);
$imagePath = (string) (($event['image'] ?? '') ?: 'assets/images/site/news-campus.jpg');
// Hero composition: a small / low-resolution event photo would be visibly upscaled in the large arch frame — then a
// high-resolution campus photo fills the frame and the event's own photo sits in the inset.
$heroMain = $imagePath;
$heroInset = null;
if (!ne_image_is_large($imagePath)) {
    $heroMain = 'assets/images/site/campus-building.jpg';
    foreach (['assets/images/site/campus-building.jpg', 'assets/images/site/hero-campus-students.jpg'] as $cand) {
        if (is_file(__DIR__ . '/' . $cand) && ne_image_is_large($cand) && $cand !== $imagePath) {
            $heroMain = $cand;
            break;
        }
    }
    $heroInset = $imagePath !== $heroMain ? $imagePath : null;
}
$body = ne_rich($event['description'] ?? '');
$excerpt = ne_excerpt(($event['excerpt'] ?? '') ?: ($event['description'] ?? ''), 220);
$url = absolute_url('events/' . $event['slug']);
$icsUrl = ne_event_url($event) . '?ics=1';
$gallery = $st['phase'] === 'past' ? ne_event_gallery($event) : null;
$related = ne_upcoming_events(3, [(int) $event['id']], (string) $event['category']);
if (count($related) < 3) {
    $related = array_merge($related, ne_upcoming_events(3 - count($related), array_merge([(int) $event['id']], array_map(fn ($r) => (int) $r['id'], $related))));
}
// same-category events first in the query, but shown in date order ("coming up next")
usort($related, fn ($a, $b) => strcmp((string) $a['start_datetime'], (string) $b['start_datetime']) ?: ((int) $a['id'] <=> (int) $b['id']));
$organizer = trim((string) $event['organizer']);
$contactEmail = filter_var((string) ($event['contact_email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string) $event['contact_email'] : '';
$officeEmail = (string) setting('email', 'info@gimt.ac.in');
$active = in_array($st['phase'], ['upcoming', 'ongoing'], true);

/* ------------------------------------------------------------------ SEO */
$org = ne_org_ld();
$address = ['@type' => 'PostalAddress', 'streetAddress' => (string) setting('address', ''), 'addressLocality' => (string) setting('city', 'Greater Noida'),
    'addressRegion' => (string) setting('state', 'Uttar Pradesh'), 'postalCode' => (string) setting('pincode', '201310'), 'addressCountry' => 'IN'];
$offCampus = $map && !str_starts_with($map['label'], 'Directions');
$eventLd = array_filter([
    '@context' => 'https://schema.org', '@type' => 'Event',
    'name' => (string) $event['title'],
    'description' => $excerpt ?: null,
    'startDate' => ne_iso($event['start_datetime']),
    'endDate' => ne_iso($event['end_datetime'] ?: null),
    'eventStatus' => $st['phase'] === 'cancelled' ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
    'eventAttendanceMode' => $online ? 'https://schema.org/OnlineEventAttendanceMode' : 'https://schema.org/OfflineEventAttendanceMode',
    'location' => $online ? ['@type' => 'VirtualLocation', 'url' => $url]
        : ['@type' => 'Place', 'name' => $venue !== '' ? $venue : institute_name(), 'address' => $offCampus ? ['@type' => 'PostalAddress', 'streetAddress' => $venue, 'addressCountry' => 'IN'] : $address],
    'image' => [ne_abs_image($imagePath)],
    'organizer' => $organizer !== '' && stripos($organizer, institute_name()) === false
        ? ['@type' => 'Organization', 'name' => $organizer . ', ' . setting('institute_short_name', 'GIMT'), 'url' => absolute_url('')] + ($contactEmail ? ['email' => $contactEmail] : [])
        : $org,
    'maximumAttendeeCapacity' => $st['cap'],
    'remainingAttendeeCapacity' => $st['cap'] && $active ? $st['left'] : null,
    // free website registration: price "0" must survive the filter (a plain array_filter() drops the string "0")
    'offers' => !empty($event['registration_enabled']) && $st['phase'] !== 'cancelled' ? array_filter(['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'INR',
        'availability' => $st['reg'] === 'open' ? 'https://schema.org/InStock' : ($st['reg'] === 'full' ? 'https://schema.org/SoldOut' : 'https://schema.org/Discontinued'),
        'url' => $url . '#register', 'validFrom' => ne_iso((string) $event['created_at']), 'validThrough' => $st['deadline'] ? date('c', $st['deadline']) : null], fn ($v) => $v !== null && $v !== '') : null,
    'url' => $url,
    'inLanguage' => 'en-IN',
], fn ($v) => $v !== null && $v !== '');
site_header([
    'title' => $event['title'] . ' · ' . $when['date'],
    'description' => $excerpt !== '' ? str_limit($excerpt . ' ' . $when['date'] . ($venue !== '' ? ', ' . $venue : '') . '.', 300) : $cat['label'] . ' event at ' . institute_name() . ' on ' . $when['date'] . '.',
    'active' => 'events',
    'seo' => ['event', (int) $event['id']],
    'canonical' => $url,
    'image' => $imagePath,
    'preload' => [site_image($heroMain)],
    'schema' => [ne_breadcrumb_ld(array_values(array_filter([['Home', ''], ['Events', 'events'], $st['phase'] === 'past' ? ['Past Events', 'events?tab=past'] : null, [(string) $event['title'], null]]))), $eventLd],
]);

/* ------------------------------------------------------------------ hero */
$chips = [['icon' => 'calendar-days', 'label' => $when['date']], ['icon' => 'clock', 'label' => $when['time']]];
if ($venue !== '') {
    $chips[] = ['icon' => $online ? 'video' : 'map-pin', 'label' => str_limit($venue, 42)];
}
$statusText = [
    'open' => $st['cap'] ? 'Registration open · ' . $st['left'] . ' seat' . ($st['left'] === 1 ? '' : 's') . ' left' : 'Registration open',
    'full' => 'Fully booked', 'closed' => 'Registration closed', 'external' => 'Registration open', 'none' => $st['phase'] === 'ongoing' ? 'Happening now' : $cat['label'] . ' event',
    'ended' => 'Event completed', 'cancelled' => 'Event cancelled',
][$st['reg']];
$actions = '';
if ($st['reg'] === 'open') {
    $actions .= button_link('Register Now', '#register', 'apply', ['size' => 'lg', 'shine' => true]);
} elseif ($st['reg'] === 'external') {
    $actions .= button_link('Register Now', $st['external'], 'apply', ['size' => 'lg', 'target' => '_blank', 'icon' => 'external-link']);
}
if ($active) {
    $actions .= '<a href="' . e($icsUrl) . '" class="btn btn-brochure btn-lg" download>' . icon('calendar-plus', 'h-4 w-4') . 'Add to Calendar</a>';
} elseif ($gallery) {
    $actions .= button_link('View Photos', '#photos', 'explore', ['size' => 'lg', 'icon' => 'images']);
}
$crumbs = $st['phase'] === 'past' ? ['Events' => '/events', 'Past Events' => '/events?tab=past', str_limit((string) $event['title'], 40)] : ['Events' => '/events', str_limit((string) $event['title'], 40)];
?>
<div class="[&_.page-hero-title]:!text-[clamp(1.9rem,1.25rem+2.4vw,3.25rem)]">
<?php page_hero(
    (string) $event['title'],
    $cat['label'] . ($organizer !== '' ? ' · ' . $organizer : ''),
    $crumbs,
    $heroMain,
    $excerpt,
    $chips,
    ['eyebrow' => $st['phase'] === 'ongoing' ? 'Happening now' : ($st['phase'] === 'past' ? 'Past event' : ($st['phase'] === 'cancelled' ? 'Cancelled' : 'Upcoming event')), 'eyebrow_icon' => $cat['icon'],
     'highlight' => false, 'image_alt' => $heroInset ? 'The ' . institute_name() . ' campus' : (string) $event['title'], 'actions' => $actions]
    + ($heroInset ? ['image2' => $heroInset] : []) + [
     'badge' => ['icon' => $st['phase'] === 'cancelled' ? 'ban' : ($st['phase'] === 'past' ? 'circle-check' : 'ticket'), 'text' => $statusText]]
); ?>
</div>

<?= section_open(['bg' => 'light', 'padding' => 'tight']) ?>
  <div class="grid gap-8 lg:grid-cols-12">
    <div class="min-w-0 space-y-6 lg:col-span-7 xl:col-span-8">
      <?php
      $alert = null;
      if ($st['phase'] === 'cancelled') {
          $alert = ['ban', 'border-red-200 bg-red-50 text-red-800', 'This event has been cancelled.', 'We apologise for the inconvenience. Registered participants are informed by the organiser' . ($contactEmail ? '; for questions write to ' . $contactEmail : '') . '.'];
      } elseif ($st['phase'] === 'past') {
          $alert = ['circle-check', 'border-slate-200 bg-white text-slate-700', 'This event took place on ' . $when['date'] . '.', $gallery ? 'See the photos below and browse upcoming events.' : 'Browse upcoming events to join the next one.'];
      } elseif ($st['phase'] === 'ongoing') {
          $alert = ['activity', 'border-accent-200 bg-accent-50 text-brand-900', 'This event is happening now.', $venue !== '' ? 'Join us at ' . $venue . '.' : ''];
      }
      if ($alert): ?>
        <div class="flex gap-3 rounded-2xl border p-4 text-sm <?= $alert[1] ?>" role="note"><?= icon($alert[0], 'mt-0.5 h-5 w-5 shrink-0') ?><p><strong><?= e($alert[2]) ?></strong> <?= e($alert[3]) ?></p></div>
      <?php endif; ?>

      <section class="rounded-[22px] border border-warm-2 bg-white p-5 sm:p-8" aria-labelledby="ev-about">
        <h2 id="ev-about" class="font-display text-xl font-bold text-brand-900 sm:text-2xl">About this event</h2>
        <?php if ($body !== ''): ?>
          <div class="prose-site mt-4"><?= $body ?></div>
        <?php else: ?>
          <p class="mt-4 text-slate-600"><?= e($excerpt !== '' ? $excerpt : 'Details of this event will be shared by the organiser.') ?></p>
        <?php endif; ?>
      </section>

      <section class="rounded-[22px] border border-warm-2 bg-white p-5 sm:p-8" aria-labelledby="ev-schedule">
        <h2 id="ev-schedule" class="font-display text-xl font-bold text-brand-900 sm:text-2xl">Date &amp; timings</h2>
        <?php
        $steps = [];
        if ($st['deadline'] && in_array($st['reg'], ['open', 'closed', 'full', 'external'], true)) {
            $steps[] = [date('d M Y, g:i A', $st['deadline']), $st['deadline'] < time() ? 'Registration closed' : 'Registration closes', 'Last time to register for this event.', 'alarm-clock'];
        }
        if ($when['days'] > 1) {
            for ($d = 0; $d < $when['days']; $d++) {
                $dayTs = strtotime(date('Y-m-d', $st['start']) . ' +' . $d . ' day');
                $first = $d === 0;
                $last = $d === $when['days'] - 1;
                $steps[] = [date('D, d M Y', $dayTs), 'Day ' . ($d + 1), $first ? 'Begins at ' . date('g:i A', $st['start']) . ($venue !== '' ? ' · ' . $venue : '') : ($last ? 'Concludes at ' . date('g:i A', $st['end']) : 'Programme continues'), $first ? 'flag' : ($last ? 'circle-check' : 'calendar-days')];
            }
        } else {
            $steps[] = [date('D, d M Y · g:i A', $st['start']), 'Event begins', $venue !== '' ? ($online ? 'Online · ' : '') . $venue : '', 'flag'];
            if ($st['end'] > $st['start']) {
                $hrs = ($st['end'] - $st['start']) / 3600;
                $steps[] = [date('g:i A', $st['end']), 'Event concludes', 'Duration about ' . ($hrs >= 1 ? rtrim(rtrim(number_format($hrs, 1), '0'), '.') . ' hour' . ($hrs == 1 ? '' : 's') : round($hrs * 60) . ' minutes') . '.', 'circle-check'];
            }
        }
        ?>
        <ol class="relative mt-5 space-y-5 border-l-2 border-warm-2 pl-6">
          <?php foreach ($steps as [$time, $label, $text, $ic]): ?>
            <li class="relative">
              <span class="absolute -left-[2.35rem] top-0 inline-flex h-8 w-8 items-center justify-center rounded-full bg-white text-accent-700 ring-2 ring-warm-2"><?= icon($ic, 'h-4 w-4') ?></span>
              <p class="text-xs font-bold uppercase tracking-wider text-accent-700"><?= e($time) ?></p>
              <p class="mt-0.5 font-semibold text-brand-900"><?= e($label) ?></p>
              <?php if ($text !== ''): ?><p class="text-sm text-slate-600"><?= e($text) ?></p><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </section>

      <?php if ($gallery): ?>
        <section id="photos" class="scroll-mt-28 rounded-[22px] border border-warm-2 bg-white p-5 sm:p-8" aria-labelledby="ev-photos">
          <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <h2 id="ev-photos" class="font-display text-xl font-bold text-brand-900 sm:text-2xl">Photos: <?= e((string) $gallery['title']) ?></h2>
            <?php if (ne_route_exists('gallery')): ?><a href="<?= e(site_url('gallery/' . $gallery['slug'])) ?>" class="link-arrow text-sm">Full gallery<?= icon('arrow-right', 'h-4 w-4') ?></a><?php endif; ?>
          </div>
          <?= gallery_grid($gallery['items'], ['columns' => 3, 'ariaLabel' => 'Photos from ' . $event['title']]) ?>
        </section>
      <?php endif; ?>

      <div class="rounded-[22px] border border-warm-2 bg-white p-5 sm:px-8">
        <?= ne_share($url, (string) $event['title'] . ' — ' . $when['date'], 'Share this event') ?>
      </div>
    </div>

    <aside class="space-y-6 lg:col-span-5 xl:col-span-4" aria-label="Event information and registration">
      <section class="rounded-[22px] border border-warm-2 bg-white p-5 sm:p-6" aria-labelledby="ev-facts">
        <h2 id="ev-facts" class="font-display text-base font-bold text-brand-900">Event details</h2>
        <dl class="mt-4 space-y-4 text-sm">
          <div class="flex gap-3"><dt class="shrink-0"><span class="inline-flex h-9 w-9 items-center justify-center rounded-xl tint-orange"><?= icon('calendar-days', 'h-4 w-4') ?></span><span class="sr-only">Date</span></dt>
            <dd><span class="block font-semibold text-brand-900"><time datetime="<?= e(ne_iso($event['start_datetime']) ?? '') ?>"><?= e($when['date']) ?></time></span><span class="text-slate-600"><?= e($when['time']) ?></span></dd></div>
          <?php if ($venue !== ''): ?>
            <div class="flex gap-3"><dt class="shrink-0"><span class="inline-flex h-9 w-9 items-center justify-center rounded-xl tint-orange"><?= icon($online ? 'video' : 'map-pin', 'h-4 w-4') ?></span><span class="sr-only"><?= $online ? 'Where (online)' : 'Venue' ?></span></dt>
              <dd class="min-w-0"><span class="block font-semibold text-brand-900"><?= e($venue) ?></span>
                <?php if ($online): ?><span class="text-slate-600">Online event — joining details are shared with registered participants.</span>
                <?php elseif ($map): ?><span class="block text-slate-600"><?= e($offCampus ? '' : institute_name() . ', ' . setting('city', 'Greater Noida')) ?></span><a href="<?= e($map['url']) ?>" target="_blank" rel="noopener" class="link-arrow mt-1 text-[13px]"><?= e($map['label']) ?><?= icon('arrow-up-right', 'h-3.5 w-3.5') ?><span class="sr-only"> (opens Google Maps)</span></a><?php endif; ?>
              </dd></div>
          <?php endif; ?>
          <div class="flex gap-3"><dt class="shrink-0"><span class="inline-flex h-9 w-9 items-center justify-center rounded-xl tint-orange"><?= icon($cat['icon'], 'h-4 w-4') ?></span><span class="sr-only">Category</span></dt>
            <dd><span class="block font-semibold text-brand-900"><?= e($cat['label']) ?></span><a href="<?= e(ne_query_url('events', ['category' => $event['category']])) ?>#listing" class="text-[13px] font-medium text-accent-700 hover:text-brand-900">More <?= e(strtolower($cat['label'])) ?> events</a></dd></div>
          <?php if ($organizer !== '' || $contactEmail): ?>
            <div class="flex gap-3"><dt class="shrink-0"><span class="inline-flex h-9 w-9 items-center justify-center rounded-xl tint-orange"><?= icon('building-2', 'h-4 w-4') ?></span><span class="sr-only">Organiser</span></dt>
              <dd class="min-w-0"><span class="block font-semibold text-brand-900"><?= e($organizer !== '' ? $organizer : institute_name()) ?></span><?php if ($contactEmail): ?><a href="mailto:<?= e($contactEmail) ?>" class="text-[13px] font-medium text-accent-700 [overflow-wrap:anywhere] hover:text-brand-900"><?= e($contactEmail) ?></a><?php endif; ?></dd></div>
          <?php endif; ?>
          <?php if ($st['cap'] && $active && !empty($event['registration_enabled'])): $pct = min(1, $st['taken'] / max(1, $st['cap'])); ?>
            <div class="flex gap-3"><dt class="shrink-0"><span class="inline-flex h-9 w-9 items-center justify-center rounded-xl tint-orange"><?= icon('users', 'h-4 w-4') ?></span><span class="sr-only">Seats</span></dt>
              <dd class="min-w-0 flex-1"><span class="block font-semibold text-brand-900"><?= $st['left'] > 0 ? number_format($st['left']) . ' of ' . number_format($st['cap']) . ' seats left' : 'All ' . number_format($st['cap']) . ' seats taken' ?></span>
                <span class="progress-track mt-2" role="img" aria-label="<?= e(number_format($st['taken']) . ' of ' . number_format($st['cap']) . ' seats taken') ?>"><span class="progress-fill" style="--progress:<?= round($pct, 3) ?>"></span></span></dd></div>
          <?php endif; ?>
        </dl>
        <?php if ($active): ?>
          <div class="mt-5 border-t border-warm-2 pt-5">
            <p class="mb-2.5 text-xs font-semibold uppercase tracking-wider text-slate-500">Add to calendar</p>
            <div class="grid grid-cols-2 gap-2">
              <a href="<?= e(ne_gcal_url($event)) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm !px-3"><?= icon('calendar-plus', 'h-4 w-4') ?>Google<span class="sr-only"> Calendar (opens in a new tab)</span></a>
              <a href="<?= e($icsUrl) ?>" class="btn btn-secondary btn-sm !px-3" download><?= icon('download', 'h-4 w-4') ?>Apple / Outlook<span class="sr-only"> (.ics file)</span></a>
            </div>
          </div>
        <?php endif; ?>
      </section>

      <section id="register" class="scroll-mt-28" aria-labelledby="ev-register">
        <?php if ($st['reg'] === 'open'):
            $sub = [];
            if ($st['cap']) {
                $sub[] = $st['left'] . ' seat' . ($st['left'] === 1 ? '' : 's') . ' left';
            }
            if ($st['deadline']) {
                $sub[] = 'closes ' . date('d M, g:i A', $st['deadline']);
            }
            ?>
          <h2 id="ev-register" class="sr-only">Register for <?= e((string) $event['title']) ?></h2>
          <div>
            <?= enquiry_form([
                'endpoint' => base_url('api/public/event-registration'), 'theme' => 'light',
                'title' => 'Register for this event', 'icon' => 'ticket',
                'subtitle' => $sub ? ucfirst(implode(' · ', $sub)) . '.' : 'Fill in your details to reserve your place.',
                'submitLabel' => 'Register Now', 'successTitle' => 'You’re registered!',
                'successMessage' => 'You are registered for ' . $event['title'] . '. See you on ' . $when['date'] . '!',
                'hidden' => ['event_id' => (string) (int) $event['id']],
                'fields' => [
                    ['name' => 'name', 'label' => 'Full Name', 'required' => true, 'minLength' => 2, 'maxLength' => 150, 'autocomplete' => 'name', 'placeholder' => 'Your full name'],
                    ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true, 'maxLength' => 190, 'autocomplete' => 'email', 'placeholder' => 'you@example.com', 'help' => 'Your confirmation is linked to this email.'],
                    ['name' => 'phone', 'label' => 'Mobile Number', 'type' => 'tel', 'col' => 'half', 'maxLength' => 20, 'autocomplete' => 'tel', 'placeholder' => '10-digit mobile', 'pattern' => '^\+?[0-9][0-9 \-]{8,18}[0-9]$', 'patternMessage' => 'Enter a valid 10-digit mobile number.'],
                    ['name' => 'organization', 'label' => 'College / Organisation', 'col' => 'half', 'maxLength' => 190, 'autocomplete' => 'organization', 'placeholder' => 'Optional'],
                ],
            ]) ?>
            <p class="mt-3 px-2 text-xs leading-relaxed text-slate-500">One registration per email address. Your details are used only for this event and are never shared with third parties.</p>
          </div>
        <?php else:
            $card = [
                'full' => ['users', 'This event is fully booked', 'All ' . number_format((int) $st['cap']) . ' seats have been taken. Write to the organiser to ask about the waiting list.'],
                'closed' => ['lock', 'Registration is closed', $st['deadline'] ? 'Registration closed on ' . date('d M Y, g:i A', $st['deadline']) . '.' : 'Registration for this event is no longer open.'],
                'external' => ['external-link', 'Register on the organiser’s page', 'Registration for this event is handled by the organiser.'],
                'none' => ['info', 'No online registration', 'This event does not take registrations on the website. Contact the organiser for participation details.'],
                'ended' => ['circle-check', 'This event has ended', 'Thank you to everyone who took part. Explore what’s coming up next.'],
                'cancelled' => ['ban', 'Event cancelled', 'This event will not take place as scheduled.'],
            ][$st['reg']];
            ?>
          <div class="rounded-[22px] border border-warm-2 bg-white p-5 text-center sm:p-6">
            <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl <?= in_array($st['reg'], ['full', 'cancelled'], true) ? 'bg-red-50 text-red-700' : 'tint-navy' ?>"><?= icon($card[0], 'h-6 w-6') ?></span>
            <h2 id="ev-register" class="mt-3 font-display text-lg font-bold text-brand-900"><?= e($card[1]) ?></h2>
            <p class="mt-1.5 text-sm leading-relaxed text-slate-600"><?= e($card[2]) ?></p>
            <div class="mt-5 flex flex-wrap justify-center gap-2.5">
              <?php if ($st['reg'] === 'external'): ?>
                <?= button_link('Register Now', $st['external'], 'apply', ['size' => 'sm', 'target' => '_blank', 'icon' => 'external-link']) ?>
              <?php endif; ?>
              <?php if (in_array($st['reg'], ['full', 'closed', 'none', 'cancelled'], true)): ?>
                <a href="mailto:<?= e($contactEmail ?: $officeEmail) ?>?subject=<?= rawurlencode('Event: ' . $event['title']) ?>" class="btn btn-callback btn-sm"><?= icon('mail', 'h-4 w-4') ?>Contact organiser</a>
              <?php endif; ?>
              <a href="<?= e(site_url('events')) ?>#listing" class="btn btn-explore btn-sm">Upcoming events<?= icon('arrow-right', 'h-4 w-4') ?></a>
            </div>
          </div>
        <?php endif; ?>
      </section>
    </aside>
  </div>
<?= section_close() ?>

<?php if ($related): ?>
<?= section_open(['bg' => 'white', 'label' => 'More upcoming events']) ?>
  <?= section_heading($st['phase'] === 'past' ? 'Coming Up Next' : 'More Upcoming Events', 'Seminars, workshops, fests and more on the campus calendar.', 'Don’t miss out', 'left',
      '<a href="' . e(site_url('events')) . '#listing" class="btn btn-outline btn-sm">All events' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-stagger="80">
    <?php foreach ($related as $r): ?><?= ne_event_card($r) ?><?php endforeach; ?>
  </div>
<?= section_close() ?>
<?php endif; ?>

<?= ne_script() ?>
<?php
site_footer([
    'cta_title' => 'Experience Campus Life at GIMT',
    'cta_text' => 'Join a campus where learning goes beyond the classroom — ' . (string) setting('admissions_open_text', 'Admissions Open for 2026-27') . '.',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
