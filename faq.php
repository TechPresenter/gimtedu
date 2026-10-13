<?php
/**
 * FAQs (/faq) — every active FAQ (faqs table, CMS → FAQs) grouped by category, with a search box and category filter
 * pills. Works without JavaScript (GET ?q= / ?category= filter server-side, native <details>); with JavaScript the
 * search filters as you type and #faq-{id} deep links (used by /search) open their answer. FAQPage JSON-LD on the
 * unfiltered page; filtered views are noindex,follow with the canonical /faq. "Still have questions?" enquiry form
 * (EnquiryForm island → /api/public/enquiry, source "faq").
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/errors.php';
require_once __DIR__ . '/includes/partials/content.php';

$all = cp_faq_rows(null, 300);
$cats = cms_faq_categories();
$q = cp_query('q', 100);
$cat = cp_query('category', 40);
$cat = isset($cats[$cat]) ? $cat : '';
$words = cp_search_words($q);
$filtered = $q !== '' || $cat !== '';
$c = cp_contact();

$counts = [];
foreach ($all as $f) {
    $counts[$f['category_key']] = ($counts[$f['category_key']] ?? 0) + 1;
}
$items = array_values(array_filter($all, function ($f) use ($cat, $words) {
    if ($cat !== '' && $f['category_key'] !== $cat) {
        return false;
    }
    $hay = mb_strtolower($f['q'] . ' ' . $f['a'] . ' ' . $f['category']);
    foreach ($words as $w) {
        if (!str_contains($hay, $w)) {
            return false;
        }
    }
    return true;
}));
$groups = [];
foreach ($items as $f) {
    $groups[$f['category_key']]['label'] = $f['category'];
    $groups[$f['category_key']]['items'][] = $f;
}
$url = function (array $params): string {
    $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);
    return site_url('faq') . ($params ? '?' . http_build_query($params) : '') . '#faq-list';
};
if ($filtered) {
    header('X-Robots-Tag: noindex, follow');
}
$schema = [sa_breadcrumb_ld([['Home', ''], ['FAQs', 'faq']])];
if (!$filtered && $all && !sa_seo_schema_has('FAQPage', 'route', null, '/faq')) {
    $schema[] = cp_faq_schema($all);
}

site_header([
    'title' => $filtered ? 'FAQs' . ($q !== '' ? ': “' . $q . '”' : ' — ' . $cats[$cat]) : 'Frequently Asked Questions',
    'description' => 'Answers to common questions about admissions, eligibility, fees, scholarships, academics, hostel, placements and campus life at ' . setting('institute_short_name', 'GIMT') . '.',
    'active' => 'faq',
    'seo' => $filtered ? null : ['route', '/faq'],
    'canonical' => absolute_url('faq'),
    'robots' => $filtered ? 'noindex,follow' : null,
    'preload' => [asset('assets/images/site/students-laptop.jpg')],
    'schema' => $schema,
]);

page_hero('Frequently Asked Questions', 'Quick answers before you apply', ['FAQs'], 'assets/images/site/students-laptop.jpg',
    'Find answers about admissions, eligibility, fees, scholarships, academics, hostel, placements and campus life. Can’t find yours? Ask us — we reply within one working day.',
    [], ['image_alt' => 'Students working on laptops in a GIMT classroom', 'highlight' => 'Questions']);
?>

<?= section_open(['id' => 'faq-list', 'padding' => 'normal', 'class' => 'scroll-mt-24']) ?>
  <?php if (!$all): ?>
    <div class="mx-auto max-w-2xl rounded-[24px] border border-dashed border-warm-2 bg-warm/60 p-10 text-center">
      <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-full bg-white text-accent-600 shadow-sm"><?= icon('circle-help', 'h-7 w-7') ?></span>
      <h2 class="mt-4 font-display text-xl font-extrabold text-brand-900">Our FAQs are being updated</h2>
      <p class="mt-2 text-slate-600">Ask your question below or call <?= e($c['phone']) ?> — our team will help you right away.</p>
      <div class="mt-6 flex flex-wrap justify-center gap-3"><a href="#ask" class="btn btn-callback"><?= icon('message-circle', 'h-4 w-4') ?>Ask a Question</a><?= button_link('Contact Us', '/contact', 'explore') ?></div>
    </div>
  <?php else: ?>
  <div class="grid gap-10 lg:grid-cols-12 lg:gap-12">
    <div class="min-w-0 lg:col-span-8">
      <form action="<?= e(site_url('faq')) ?>#faq-list" method="get" role="search" class="flex items-center gap-2 rounded-full border border-warm-2 bg-white p-1.5 pl-5 shadow-sm focus-within:ring-2 focus-within:ring-accent-500" data-faq-form>
        <label for="faq-q" class="sr-only">Search the FAQs</label>
        <?= icon('search', 'h-5 w-5 shrink-0 text-slate-400') ?>
        <input id="faq-q" name="q" type="search" value="<?= e($q) ?>" maxlength="100" placeholder="Search questions, e.g. hostel fee, eligibility, scholarship" class="h-11 w-0 min-w-0 flex-1 border-0 bg-transparent text-base text-brand-900 placeholder:text-slate-400 focus:outline-none focus:ring-0" autocomplete="off" data-faq-search>
        <?php if ($cat !== ''): ?><input type="hidden" name="category" value="<?= e($cat) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-explore btn-sm shrink-0 !py-2.5">Search</button>
      </form>

      <nav class="pill-row scrollbar-none mt-5" aria-label="FAQ categories">
        <a href="<?= e($url(['q' => $q])) ?>" class="chip<?= $cat === '' ? ' chip-active' : '' ?>"<?= $cat === '' ? ' aria-current="page"' : '' ?>>All <span class="chip-count"><?= count($all) ?></span></a>
        <?php foreach ($cats as $key => $label): if (empty($counts[$key])) continue; ?>
          <a href="<?= e($url(['category' => $key, 'q' => $q])) ?>" class="chip<?= $cat === $key ? ' chip-active' : '' ?>"<?= $cat === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="chip-count"><?= (int) $counts[$key] ?></span></a>
        <?php endforeach; ?>
      </nav>

      <p class="mt-6 text-sm text-slate-500" aria-live="polite" data-faq-count>
        <?php if ($filtered): ?>
          <?= count($items) ?> <?= count($items) === 1 ? 'answer' : 'answers' ?><?= $q !== '' ? ' for “<strong class="text-brand-900">' . e($q) . '</strong>”' : '' ?><?= $cat !== '' ? ' in ' . e($cats[$cat]) : '' ?> ·
          <a href="<?= e($url([])) ?>" class="font-semibold text-accent-700 underline underline-offset-2">Show all questions</a>
        <?php else: ?>
          <?= count($all) ?> questions in <?= count($counts) ?> categories
        <?php endif; ?>
      </p>

      <div class="faq mt-4 space-y-10" data-faq-groups>
        <?php foreach ($groups as $key => $g): ?>
          <section id="cat-<?= e($key) ?>" class="scroll-mt-28" aria-labelledby="cat-<?= e($key) ?>-title" data-faq-group>
            <h2 id="cat-<?= e($key) ?>-title" class="mb-4 flex items-center gap-3 font-display text-xl font-extrabold text-brand-900">
              <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-accent-50 text-accent-600"><?= icon(cp_faq_icon($key), 'h-5 w-5') ?></span><?= e($g['label']) ?>
              <span class="text-sm font-semibold text-slate-400">(<?= count($g['items']) ?>)</span>
            </h2>
            <div class="space-y-3" data-accordion="multi">
              <?php foreach ($g['items'] as $f): ?>
                <details id="faq-<?= (int) $f['id'] ?>" class="acc-item scroll-mt-28" data-faq-item>
                  <summary><?= cp_highlight($f['q'], $words) ?></summary>
                  <div>
                    <?php foreach (preg_split('/\n{2,}/', $f['a']) as $para): if (trim($para) === '') continue; ?>
                      <p class="mb-3 last:mb-0"><?= nl2br(cp_highlight(trim($para), $words)) ?></p>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>

      <div class="<?= $items ? 'hidden' : '' ?> mt-4 rounded-[22px] border border-dashed border-warm-2 bg-warm/60 p-8 text-center" data-faq-empty role="status">
        <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-full bg-white text-accent-600 shadow-sm"><?= icon('search', 'h-6 w-6') ?></span>
        <h2 class="mt-3 font-display text-lg font-extrabold text-brand-900">No questions match your search</h2>
        <p class="mt-1 text-sm text-slate-600">Try fewer or different words, browse all categories, or ask us directly — we reply within one working day.</p>
        <div class="mt-5 flex flex-wrap justify-center gap-3">
          <a href="<?= e($url([])) ?>" class="btn btn-outline btn-sm">Show all questions</a>
          <a href="#ask" class="btn btn-callback btn-sm"><?= icon('message-circle', 'h-4 w-4') ?>Ask a Question</a>
          <?php if ($q !== '' && cp_route_ok('/search')): ?><a href="<?= e(site_url('search?q=' . rawurlencode($q))) ?>" class="btn btn-explore btn-sm">Search the whole website</a><?php endif; ?>
        </div>
      </div>
    </div>

    <aside class="lg:col-span-4" aria-label="More help">
      <div class="space-y-5 lg:sticky lg:top-28">
        <?= cp_help_card($c) ?>
        <div class="rounded-[22px] border border-warm-2 bg-white p-6 shadow-sm">
          <p class="font-display text-lg font-extrabold text-brand-900">Helpful links</p>
          <ul class="mt-3 space-y-1 text-sm">
            <?php foreach ([['Admission process', '/admissions#process', 'clipboard-check'], ['Fees & scholarships', '/admissions#fees', 'wallet'], ['Apply online', '/apply', 'file-check'],
                ['Explore courses', '/programs', 'graduation-cap'], ['Hostel & campus life', '/campus#hostel', 'building-2'], ['Policies & disclosures', '/policies', 'scroll-text']] as [$label, $href, $ic]):
                if (!cp_url_ok($href)) continue; ?>
              <li><a href="<?= e(cms_link($href)) ?>" class="group flex items-center gap-3 rounded-xl px-2 py-2 font-medium text-brand-900 transition hover:bg-warm"><?= icon($ic, 'h-4 w-4 text-accent-600') ?><span class="flex-1"><?= e($label) ?></span><?= icon('arrow-right', 'h-4 w-4 text-slate-400 transition group-hover:translate-x-0.5') ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </aside>
  </div>
  <?php endif; ?>
<?= section_close() ?>

<?= section_open(['id' => 'ask', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
  <div class="grid items-center gap-10 lg:grid-cols-12">
    <div class="lg:col-span-5"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Still have questions?</p>
      <h2 class="section-title">Ask our admission team</h2>
      <p class="section-lead">Send us your question and a counsellor will call or email you within one working day. You can also reach us directly:</p>
      <ul class="mt-6 space-y-3 text-[15px]">
        <li><a href="<?= e(cp_tel($c['phone'])) ?>" class="inline-flex items-center gap-3 font-semibold text-brand-900 hover:text-accent-700"><span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-accent-600 shadow-sm"><?= icon('phone', 'h-4 w-4') ?></span><?= e($c['phone']) ?></a></li>
        <li><a href="mailto:<?= e($c['admission_email']) ?>" class="inline-flex items-center gap-3 font-semibold text-brand-900 hover:text-accent-700"><span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-accent-600 shadow-sm"><?= icon('mail', 'h-4 w-4') ?></span><?= e($c['admission_email']) ?></a></li>
        <?php if ($c['whatsapp_url'] !== ''): ?><li><a href="<?= e($c['whatsapp_url']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-3 font-semibold text-brand-900 hover:text-accent-700"><span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-whatsapp shadow-sm"><?= icon('whatsapp-brand', 'h-5 w-5') ?></span>Chat on WhatsApp<span class="sr-only"> (opens in a new tab)</span></a></li><?php endif; ?>
      </ul>
    </div>
    <div class="lg:col-span-7">
      <?= enquiry_form(['endpoint' => base_url('api/public/enquiry'), 'theme' => 'light', 'captureUtm' => true,
          'title' => 'Ask a Question', 'subtitle' => 'We usually reply within one working day.', 'submitLabel' => 'Send My Question',
          'successMessage' => 'Thank you! Our admission team will get back to you within one working day.',
          'hidden' => ['source' => 'faq', 'form' => 'faq-question'],
          'consent' => 'I agree to be contacted by GIMT about my question by phone, SMS, email or WhatsApp.',
          'fields' => [
              ['name' => 'name', 'label' => 'Full Name', 'required' => true, 'maxLength' => 150, 'col' => 'half', 'autocomplete' => 'name'],
              ['name' => 'phone', 'label' => 'Mobile Number', 'type' => 'tel', 'required' => true, 'prefix' => '+91', 'pattern' => '^[6-9][0-9]{9}$', 'patternMessage' => 'Enter a valid 10-digit mobile number.', 'maxLength' => 10, 'col' => 'half', 'autocomplete' => 'tel-national'],
              ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'col' => 'full', 'autocomplete' => 'email'],
              ['name' => 'message', 'label' => 'Your Question', 'type' => 'textarea', 'required' => true, 'rows' => 4, 'maxLength' => 2000, 'value' => $q],
          ]]) ?>
    </div>
  </div>
<?= section_close() ?>

<script>
(function () {
  var input = document.querySelector('[data-faq-search]');
  var groups = [].slice.call(document.querySelectorAll('[data-faq-group]'));
  var empty = document.querySelector('[data-faq-empty]');
  var count = document.querySelector('[data-faq-count]');
  // Deep link (#faq-12 from the site search): open the answer and bring it into view.
  function openHash() {
    var id = decodeURIComponent((location.hash || '').slice(1));
    if (!/^faq-\d+$/.test(id)) return;
    var el = document.getElementById(id);
    if (el && el.tagName === 'DETAILS') { el.open = true; el.scrollIntoView({ block: 'start' }); }
  }
  openHash();
  window.addEventListener('hashchange', openHash);
  if (!input || !groups.length) return;
  var original = count ? count.innerHTML : '';
  input.addEventListener('input', function () {
    var words = input.value.toLowerCase().split(/\s+/).filter(function (w) { return w.length > 1; });
    var shown = 0;
    groups.forEach(function (g) {
      var n = 0;
      [].forEach.call(g.querySelectorAll('[data-faq-item]'), function (d) {
        var text = d.textContent.toLowerCase();
        var ok = words.every(function (w) { return text.indexOf(w) !== -1; });
        d.hidden = !ok;
        if (ok) n++;
      });
      g.hidden = n === 0;
      shown += n;
    });
    if (empty) empty.classList.toggle('hidden', shown > 0);
    if (count) count.innerHTML = words.length ? shown + (shown === 1 ? ' answer matches' : ' answers match') + ' your search' : original;
  });
})();
</script>
<?php site_footer([
    'cta_title' => 'Ready to Begin Your Journey?',
    'cta_text' => 'Apply online in a few minutes or explore our industry-focused programs.',
    'cta_primary' => cp_route_ok('/apply') ? ['label' => 'Apply for Admission', 'url' => '/apply'] : ['label' => 'Contact Us', 'url' => '/contact'],
    'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
