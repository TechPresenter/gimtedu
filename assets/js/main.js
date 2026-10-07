/* GIMT public website interactions (vanilla JS, no dependencies). */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var csrf = ($('meta[name="csrf-token"]') || {}).content || '';

  /* Sticky header shadow + back to top */
  var header = $('#site-header');
  var toTop = $('[data-back-to-top]');
  var onScroll = function () {
    var y = window.scrollY;
    if (header) header.classList.toggle('shadow-md', y > 8);
    if (toTop) { toTop.classList.toggle('hidden', y < 600); toTop.classList.toggle('inline-flex', y >= 600); }
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  if (toTop) toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });

  /* Mobile menu */
  var menu = $('#mobile-menu');
  var openBtn = $('[data-menu-open]');
  var setMenu = function (open) {
    if (!menu) return;
    menu.classList.toggle('hidden', !open);
    document.body.classList.toggle('overflow-hidden', open);
    if (openBtn) openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { var f = menu.querySelector('a,button'); if (f) f.focus(); }
  };
  if (openBtn) openBtn.addEventListener('click', function () { setMenu(true); });
  $$('[data-menu-close]').forEach(function (el) { el.addEventListener('click', function () { setMenu(false); }); });

  /* Search overlay */
  var search = $('#site-search');
  var setSearch = function (open) {
    if (!search) return;
    search.classList.toggle('hidden', !open);
    if (open) setTimeout(function () { var i = $('#site-search-input'); if (i) i.focus(); }, 30);
  };
  $$('[data-search-open]').forEach(function (el) { el.addEventListener('click', function () { setSearch(true); }); });
  $$('[data-search-close]').forEach(function (el) { el.addEventListener('click', function () { setSearch(false); }); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { setMenu(false); setSearch(false); closeVideo(); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setSearch(true); }
  });

  /* Reveal on scroll */
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-visible'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -60px 0px' });
    $$('.reveal').forEach(function (el) { io.observe(el); });

    /* Animated counters: <span data-count="1000" data-suffix="+">0</span> */
    var co = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        var el = en.target; co.unobserve(el);
        var end = parseFloat(el.getAttribute('data-count')) || 0, suffix = el.getAttribute('data-suffix') || '', dur = 1400, start = null;
        var dec = (el.getAttribute('data-count').split('.')[1] || '').length;
        var step = function (t) {
          if (!start) start = t;
          var p = Math.min(1, (t - start) / dur), v = end * (1 - Math.pow(1 - p, 3));
          el.textContent = v.toLocaleString('en-IN', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suffix;
          if (p < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      });
    }, { threshold: 0.4 });
    $$('[data-count]').forEach(function (el) { co.observe(el); });
  } else {
    $$('.reveal').forEach(function (el) { el.classList.add('is-visible'); });
  }

  /* Filter tabs: <button data-filter="ug" data-filter-group="programs"> + items with data-filter-item="ug pg" */
  $$('[data-filter]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var group = btn.getAttribute('data-filter-group') || 'default';
      var value = btn.getAttribute('data-filter');
      $$('[data-filter][data-filter-group="' + group + '"]').forEach(function (b) {
        var on = b === btn; b.classList.toggle('chip-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      $$('[data-filter-item][data-filter-group="' + group + '"]').forEach(function (item) {
        var tags = (item.getAttribute('data-filter-item') || '').split(/\s+/);
        item.classList.toggle('hidden', value !== 'all' && tags.indexOf(value) === -1);
      });
    });
  });

  /* Horizontal sliders: <div data-slider> <div data-slider-track class="flex overflow-x-auto snap-x">…</div> <button data-slider-prev> <button data-slider-next> */
  $$('[data-slider]').forEach(function (root) {
    var track = $('[data-slider-track]', root);
    if (!track) return;
    var by = function (dir) { track.scrollBy({ left: dir * Math.max(280, track.clientWidth * 0.8), behavior: 'smooth' }); };
    var prev = $('[data-slider-prev]', root), next = $('[data-slider-next]', root);
    if (prev) prev.addEventListener('click', function () { by(-1); });
    if (next) next.addEventListener('click', function () { by(1); });
    var auto = parseInt(root.getAttribute('data-autoplay') || '0', 10);
    if (auto > 0) {
      var timer = setInterval(function () {
        if (track.scrollLeft + track.clientWidth >= track.scrollWidth - 4) track.scrollTo({ left: 0, behavior: 'smooth' }); else by(1);
      }, auto);
      root.addEventListener('mouseenter', function () { clearInterval(timer); });
    }
  });

  /* Hero slideshow: <div data-hero> with children [data-slide] (first visible), dots [data-slide-dot] */
  $$('[data-hero]').forEach(function (root) {
    var slides = $$('[data-slide]', root), dots = $$('[data-slide-dot]', root), i = 0;
    if (slides.length < 2) return;
    var show = function (n) {
      i = (n + slides.length) % slides.length;
      slides.forEach(function (s, k) { s.classList.toggle('opacity-0', k !== i); s.classList.toggle('pointer-events-none', k !== i); s.setAttribute('aria-hidden', k !== i ? 'true' : 'false'); });
      dots.forEach(function (d, k) { d.classList.toggle('!bg-white', k === i); d.classList.toggle('w-8', k === i); });
    };
    dots.forEach(function (d, k) { d.addEventListener('click', function () { show(k); }); });
    var t = setInterval(function () { show(i + 1); }, 6500);
    root.addEventListener('mouseenter', function () { clearInterval(t); });
  });

  /* Video modal: <button data-video="https://www.youtube.com/embed/xxxx"> */
  var videoModal = null;
  function closeVideo() { if (videoModal) { videoModal.remove(); videoModal = null; document.body.classList.remove('overflow-hidden'); } }
  $$('[data-video]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var src = btn.getAttribute('data-video');
      if (!/^https:\/\/(www\.)?(youtube(-nocookie)?\.com|player\.vimeo\.com)\//.test(src)) return;
      videoModal = document.createElement('div');
      videoModal.className = 'fixed inset-0 z-[70] flex items-center justify-center bg-black/80 p-4';
      videoModal.innerHTML = '<div class="relative w-full max-w-4xl"><button type="button" class="absolute -top-10 right-0 text-white" aria-label="Close video">✕ Close</button><div class="aspect-video overflow-hidden rounded-xl bg-black"><iframe class="h-full w-full" src="' + src + (src.indexOf('?') > -1 ? '&' : '?') + 'autoplay=1" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen title="Campus video"></iframe></div></div>';
      videoModal.addEventListener('click', function (e) { if (e.target === videoModal || e.target.tagName === 'BUTTON') closeVideo(); });
      document.body.appendChild(videoModal);
      document.body.classList.add('overflow-hidden');
    });
  });

  /* AJAX forms: <form data-ajax-form action="/api/public/contact" data-success="Thanks!"> with [data-form-message] */
  $$('form[data-ajax-form]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('[type="submit"]');
      var msg = form.querySelector('[data-form-message]');
      $$('[data-error-for]', form).forEach(function (el) { el.textContent = ''; });
      $$('.border-red-400', form).forEach(function (el) { el.classList.remove('border-red-400'); });
      if (btn) { btn.disabled = true; btn.dataset.label = btn.innerHTML; btn.innerHTML = 'Please wait…'; }
      fetch(form.getAttribute('action'), {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
      }).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected server response.' }; }); })
        .then(function (res) {
          if (res.ok) {
            form.reset();
            if (msg) { msg.className = 'mt-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700'; msg.textContent = res.message || form.getAttribute('data-success') || 'Submitted successfully.'; }
            form.dispatchEvent(new CustomEvent('form:success', { detail: res }));
            if (res.data && res.data.redirect) window.location.href = res.data.redirect;
          } else {
            if (msg) { msg.className = 'mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700'; msg.textContent = res.message || 'Please check the form and try again.'; }
            Object.keys(res.errors || {}).forEach(function (k) {
              var holder = form.querySelector('[data-error-for="' + k + '"]'); if (holder) holder.textContent = res.errors[k];
              var input = form.querySelector('[name="' + k + '"]'); if (input) input.classList.add('border-red-400');
            });
          }
        })
        .catch(function () { if (msg) { msg.className = 'mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700'; msg.textContent = 'Unable to submit right now. Please try again.'; } })
        .finally(function () { if (btn) { btn.disabled = false; btn.innerHTML = btn.dataset.label; } });
    });
  });
})();
