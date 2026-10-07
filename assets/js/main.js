/*
 * GIMT public website — NO-BUNDLE FALLBACK (vanilla ES5, no dependencies).
 *
 * The real website behaviour lives in the Vite-built TypeScript/React bundle (frontend/site -> assets/site, loaded by
 * site_assets_footer()). This file is only loaded when that bundle has not been built (no assets/site manifest), so
 * the site keeps working: sticky header, drawer, search, reveal, counters, filters, sliders, video modal, AJAX forms.
 * Keep it in parity with frontend/site/core/* when changing markup contracts.
 */
(function () {
  'use strict';
  window.__gimtSite = 'fallback';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var csrf = ($('meta[name="csrf-token"]') || {}).content || '';
  var html = document.documentElement;
  html.classList.add('loader-skip');
  var loader = $('#site-loader'); if (loader) loader.parentNode.removeChild(loader);

  /* Layers (drawer / search / modal): toggle hidden + is-open for the CSS transitions */
  var layer = function (el, open) {
    if (!el) return;
    if (open) { el.classList.remove('hidden'); void el.offsetWidth; el.classList.add('is-open'); html.classList.add('scroll-locked'); }
    else { el.classList.remove('is-open'); html.classList.remove('scroll-locked'); setTimeout(function () { el.classList.add('hidden'); }, 300); }
  };

  /* Sticky header + back to top + reading progress */
  var header = $('#site-header');
  var toTop = $('[data-back-to-top]');
  var bar = $('[data-read-progress-bar]');
  var onScroll = function () {
    var y = window.scrollY, max = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
    if (header) header.classList.toggle('is-scrolled', y > 8);
    if (toTop) { toTop.classList.toggle('is-visible', y >= 600); toTop.tabIndex = y >= 600 ? 0 : -1; }
    if (bar) { bar.style.transform = 'scaleX(' + Math.min(1, y / max) + ')'; bar.parentNode.classList.toggle('is-active', y > 40); }
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  if (toTop) toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });

  /* Mobile menu */
  var menu = $('#mobile-menu');
  var openBtn = $('[data-menu-open]');
  var setMenu = function (open) {
    if (!menu) return;
    layer(menu, open);
    if (openBtn) openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { var f = menu.querySelector('a,button'); if (f) f.focus(); }
  };
  if (openBtn) openBtn.addEventListener('click', function () { setMenu(true); });
  $$('[data-menu-close]').forEach(function (el) { el.addEventListener('click', function () { setMenu(false); }); });

  /* Search overlay */
  var search = $('#site-search');
  var setSearch = function (open) {
    if (!search) return;
    layer(search, open);
    if (open) setTimeout(function () { var i = $('#site-search-input'); if (i) i.focus(); }, 30);
  };
  $$('[data-search-open]').forEach(function (el) { el.addEventListener('click', function () { setSearch(true); }); });
  $$('[data-search-close]').forEach(function (el) { el.addEventListener('click', function () { setSearch(false); }); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { setMenu(false); setSearch(false); closeVideo(); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setSearch(true); }
  });

  /* Reveal on scroll (.reveal, [data-reveal], children of [data-stagger]) */
  $$('[data-stagger]').forEach(function (p) {
    var step = parseInt(p.getAttribute('data-stagger'), 10) || 80;
    Array.prototype.forEach.call(p.children, function (c, i) { if (!c.hasAttribute('data-reveal')) c.setAttribute('data-reveal', 'fade-up'); c.style.setProperty('--reveal-delay', Math.min(i, 12) * step + 'ms'); });
  });
  $$('[data-reveal-delay]').forEach(function (el) { el.style.setProperty('--reveal-delay', (parseFloat(el.getAttribute('data-reveal-delay')) || 0) + 'ms'); });
  html.classList.add('site-ready');
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if ('IntersectionObserver' in window && !reduced) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-visible'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -60px 0px' });
    $$('.reveal, [data-reveal]').forEach(function (el) { io.observe(el); });

    /* Animated counters: <span data-count="1000" data-suffix="+">1,000+</span> */
    var co = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        var el = en.target; co.unobserve(el);
        var end = parseFloat(el.getAttribute('data-count')) || 0, prefix = el.getAttribute('data-prefix') || '', suffix = el.getAttribute('data-suffix') || '', dur = 1400, start = null;
        var dec = el.hasAttribute('data-decimals') ? parseInt(el.getAttribute('data-decimals'), 10) : (el.getAttribute('data-count').split('.')[1] || '').length;
        var step = function (t) {
          if (!start) start = t;
          var p = Math.min(1, (t - start) / dur), v = end * (1 - Math.pow(1 - p, 3));
          el.textContent = prefix + v.toLocaleString('en-IN', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suffix;
          if (p < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      });
    }, { threshold: 0.4 });
    $$('[data-count]').forEach(function (el) { co.observe(el); });
  } else {
    $$('.reveal, [data-reveal]').forEach(function (el) { el.classList.add('is-visible'); });
  }
  $$('img[loading="lazy"], img[data-lazy]').forEach(function (img) { img.classList.add('is-loaded'); });
  $$('[data-progress]').forEach(function (el) { el.style.setProperty('--progress', Math.min(100, parseFloat(el.getAttribute('data-progress')) || 0) / 100); });

  /* Filter tabs: <button data-filter="ug" data-filter-group="programs"> + items with data-filter-item="ug pg" */
  $$('[data-filter]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var group = btn.getAttribute('data-filter-group') || 'default';
      var value = btn.getAttribute('data-filter');
      $$('[data-filter][data-filter-group="' + group + '"]').forEach(function (b) {
        var on = b === btn; b.classList.toggle('chip-active', on); b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      $$('[data-filter-item][data-filter-group="' + group + '"]').forEach(function (item) {
        var tags = (item.getAttribute('data-filter-item') || '').split(/\s+/);
        item.classList.toggle('hidden', value !== 'all' && tags.indexOf(value) === -1);
      });
    });
  });

  /* Horizontal sliders: <div data-slider> <div data-slider-track …>…</div> <button data-slider-prev> <button data-slider-next> */
  $$('[data-slider]').forEach(function (root) {
    var track = $('[data-slider-track]', root);
    if (!track) return;
    var by = function (dir) { track.scrollBy({ left: dir * Math.max(280, track.clientWidth * 0.8), behavior: 'smooth' }); };
    var prev = $('[data-slider-prev]', root), next = $('[data-slider-next]', root);
    if (prev) prev.addEventListener('click', function () { by(-1); });
    if (next) next.addEventListener('click', function () { by(1); });
    var auto = parseInt(root.getAttribute('data-autoplay') || '0', 10), paused = false;
    if (auto > 0) {
      root.addEventListener('mouseenter', function () { paused = true; });
      root.addEventListener('mouseleave', function () { paused = false; });
      setInterval(function () {
        if (paused) return;
        if (track.scrollLeft + track.clientWidth >= track.scrollWidth - 4) track.scrollTo({ left: 0, behavior: 'smooth' }); else by(1);
      }, auto);
    }
  });

  /* Hero slideshow: <div data-hero> with children [data-slide] (first visible), dots [data-slide-dot] */
  $$('[data-hero]').forEach(function (root) {
    var slides = $$('[data-slide]', root), dots = $$('[data-slide-dot]', root), i = 0, paused = false;
    if (slides.length < 2) return;
    var show = function (n) {
      i = (n + slides.length) % slides.length;
      slides.forEach(function (s, k) { s.classList.toggle('opacity-0', k !== i); s.classList.toggle('pointer-events-none', k !== i); s.setAttribute('aria-hidden', k !== i ? 'true' : 'false'); });
      dots.forEach(function (d, k) { d.classList.toggle('!bg-white', k === i); d.classList.toggle('w-8', k === i); });
    };
    dots.forEach(function (d, k) { d.addEventListener('click', function () { show(k); }); });
    root.addEventListener('mouseenter', function () { paused = true; });
    root.addEventListener('mouseleave', function () { paused = false; });
    setInterval(function () { if (!paused) show(i + 1); }, 6500);
  });

  /* Accordions: single-open groups of <details> */
  $$('[data-accordion="single"]').forEach(function (group) {
    $$('details', group).forEach(function (d) {
      d.addEventListener('toggle', function () { if (d.open) $$('details', group).forEach(function (o) { if (o !== d) o.open = false; }); });
    });
  });

  /* Video modal: <button data-video="https://www.youtube.com/embed/xxxx"> or <a href="youtube url" data-lightbox> */
  var videoModal = null;
  function closeVideo() { if (videoModal) { videoModal.parentNode.removeChild(videoModal); videoModal = null; html.classList.remove('scroll-locked'); } }
  var embed = function (url) {
    var m = /(?:youtube(?:-nocookie)?\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([\w-]{6,20})/.exec(url || '');
    if (m) return 'https://www.youtube-nocookie.com/embed/' + m[1] + '?autoplay=1&rel=0';
    return /^https:\/\/player\.vimeo\.com\//.test(url) ? url : null;
  };
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-video], [data-lightbox]') : null;
    if (!btn) return;
    var src = embed(btn.getAttribute('data-video') || btn.getAttribute('href'));
    if (!src) return;
    e.preventDefault();
    videoModal = document.createElement('div');
    videoModal.className = 'fixed inset-0 z-[85] flex items-center justify-center bg-black/80 p-4';
    videoModal.innerHTML = '<div class="relative w-full max-w-4xl"><button type="button" class="absolute -top-10 right-0 text-white" aria-label="Close video">✕ Close</button><div class="aspect-video overflow-hidden rounded-xl bg-black"><iframe class="h-full w-full" src="' + src + '" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen title="Video"></iframe></div></div>';
    videoModal.addEventListener('click', function (ev) { if (ev.target === videoModal || ev.target.tagName === 'BUTTON') closeVideo(); });
    document.body.appendChild(videoModal);
    html.classList.add('scroll-locked');
  });

  /* AJAX forms: <form data-ajax-form action="/api/public/contact" data-success="Thanks!"> with [data-form-message] */
  $$('form[data-ajax-form]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('[type="submit"]');
      var msg = form.querySelector('[data-form-message]');
      $$('[data-error-for]', form).forEach(function (el) { el.textContent = ''; });
      $$('.is-invalid', form).forEach(function (el) { el.classList.remove('is-invalid'); });
      if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
      fetch(form.getAttribute('action'), {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
      }).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected server response.' }; }); })
        .then(function (res) {
          if (res.ok) {
            form.reset();
            if (msg) { msg.className = 'form-message form-message-success'; msg.textContent = res.message || form.getAttribute('data-success') || 'Submitted successfully.'; }
            form.dispatchEvent(new CustomEvent('form:success', { detail: res }));
            if (res.data && res.data.redirect) window.location.href = res.data.redirect;
          } else {
            if (msg) { msg.className = 'form-message form-message-error'; msg.textContent = res.message || 'Please check the form and try again.'; }
            Object.keys(res.errors || {}).forEach(function (k) {
              var holder = form.querySelector('[data-error-for="' + k + '"]'); if (holder) holder.textContent = res.errors[k];
              var input = form.querySelector('[name="' + k + '"]'); if (input) input.classList.add('is-invalid');
            });
          }
        })
        .catch(function () { if (msg) { msg.className = 'form-message form-message-error'; msg.textContent = 'Unable to submit right now. Please try again.'; } })
        .then(function () { if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); } });
    });
  });
})();
