/*!
 * GIMT cookie consent UI (unit seo_platform) — banner + preferences modal.
 * Works with the inline <head> script printed by consent_head() (includes/partials/consent.php), which owns the
 * Consent Mode v2 defaults, the stored choice (cookie gimt_consent) and the consent-gated tag loader
 * (window.__gimtConsent). This file only renders choices and saves them.
 *
 *   [data-cc-accept] [data-cc-reject] [data-cc-customize] [data-cc-save] [data-cc-close]   banner / modal buttons
 *   [data-consent-open]                                                                     "Cookie settings" links
 *   window.gimtConsent.open() / .get() / .set(analytics, marketing)                         public API
 *   document "gimt:consent" event {analytics, marketing}; dataLayer {event: 'consent_update', …}
 */
(function () {
  'use strict';
  var w = window;
  var d = document;
  var core = w.__gimtConsent;
  if (!core) return;
  var C = core.cfg || {};
  var banner = d.getElementById('cc-banner');
  var modal = d.getElementById('cc-modal');
  var live = d.querySelector('[data-cc-live]');
  var opener = null;
  var reduce = false;
  try {
    reduce = w.matchMedia('(prefers-reduced-motion: reduce)').matches || !!navigator.webdriver;
  } catch (e) { /* old browsers */ }

  function current() {
    return core.read();
  }

  function announce(msg) {
    if (!live) return;
    live.textContent = '';
    w.setTimeout(function () { live.textContent = msg; }, 50);
  }

  /** Expire optional cookies on this host and its parent domains (GA sets them on the registrable domain). */
  function expire(test) {
    var parts = location.hostname.split('.');
    var paths = ['/', C.path || '/'];
    d.cookie.split(';').forEach(function (raw) {
      var name = raw.split('=')[0].trim();
      if (!name || !test(name)) return;
      paths.forEach(function (p) {
        d.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=' + p;
        for (var i = 0; i < parts.length - 1; i++) {
          d.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=' + p + ';domain=.' + parts.slice(i).join('.');
        }
      });
    });
  }

  function show(el) {
    if (!el) return;
    el.hidden = false;
    if (reduce) {
      el.classList.add('is-in');
      return;
    }
    // next frame so the transition runs
    w.requestAnimationFrame(function () { w.requestAnimationFrame(function () { el.classList.add('is-in'); }); });
  }

  function hide(el, done) {
    if (!el || el.hidden) {
      if (done) done();
      return;
    }
    el.classList.remove('is-in');
    var finish = function () {
      el.hidden = true;
      if (done) done();
    };
    if (reduce) finish();
    else w.setTimeout(finish, 260);
  }

  function syncToggles() {
    if (!modal) return;
    var c = current() || { a: false, m: false };
    var a = modal.querySelector('[data-cc-cat="analytics"]');
    var m = modal.querySelector('[data-cc-cat="marketing"]');
    if (a) a.checked = !!c.a;
    if (m) m.checked = !!c.m;
  }

  function save(a, m, action) {
    var before = current();
    var value = 'v' + C.v + '.a' + (a ? 1 : 0) + '.m' + (m ? 1 : 0) + '.' + Math.floor(Date.now() / 1000);
    d.cookie = 'gimt_consent=' + value + ';path=' + (C.path || '/') + ';max-age=' + ((C.days || 180) * 86400) + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
    if (!a) expire(function (n) { return n === '_ga' || n === '_gid' || n === '_gat' || n.indexOf('_ga_') === 0; });
    if (!m) expire(function (n) { return n === '_fbp' || n === '_fbc' || n.indexOf('_gcl_') === 0; });
    core.apply({ a: !!a, m: !!m });
    (w.dataLayer = w.dataLayer || []).push({
      event: 'consent_update', consent_analytics: a ? 'granted' : 'denied', consent_marketing: m ? 'granted' : 'denied', consent_action: action
    });
    try {
      d.dispatchEvent(new CustomEvent('gimt:consent', { detail: { analytics: !!a, marketing: !!m, previous: before } }));
    } catch (e) { /* IE */ }
    hide(banner);
    closeModal();
    syncToggles();
    announce('Your cookie preferences have been saved.');
  }

  function focusables() {
    return Array.prototype.filter.call(
      modal.querySelectorAll('button, [href], input:not([disabled]), summary, [tabindex]:not([tabindex="-1"])'),
      function (el) { return el.offsetParent !== null || el === d.activeElement; }
    );
  }

  function onKey(e) {
    if (!modal || modal.hidden) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      closeModal();
      return;
    }
    if (e.key !== 'Tab') return;
    var list = focusables();
    if (!list.length) return;
    var first = list[0];
    var last = list[list.length - 1];
    if (e.shiftKey && (d.activeElement === first || d.activeElement === modal.querySelector('.cc-panel'))) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && d.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function openModal(from) {
    if (!modal) return;
    opener = from || d.activeElement;
    syncToggles();
    show(modal);
    d.documentElement.style.overflow = 'hidden';
    d.addEventListener('keydown', onKey, true);
    var panel = modal.querySelector('.cc-panel');
    w.setTimeout(function () {
      var first = modal.querySelector('[data-cc-cat="analytics"]') || panel;
      if (first) first.focus();
    }, reduce ? 0 : 60);
  }

  function closeModal() {
    if (!modal || modal.hidden) return;
    d.removeEventListener('keydown', onKey, true);
    hide(modal, function () {
      d.documentElement.style.overflow = '';
      if (opener && typeof opener.focus === 'function' && d.contains(opener)) opener.focus();
      opener = null;
    });
  }

  d.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-cc-accept],[data-cc-reject],[data-cc-save],[data-cc-customize],[data-cc-close],[data-consent-open]') : null;
    if (!t) return;
    if (t.hasAttribute('data-consent-open') || t.hasAttribute('data-cc-customize')) {
      e.preventDefault();
      openModal(t.hasAttribute('data-cc-customize') ? (banner && banner.querySelector('[data-cc-customize]')) : t);
    } else if (t.hasAttribute('data-cc-accept')) {
      save(true, true, 'accept_all');
    } else if (t.hasAttribute('data-cc-reject')) {
      save(false, false, 'reject_all');
    } else if (t.hasAttribute('data-cc-save')) {
      var a = modal && modal.querySelector('[data-cc-cat="analytics"]');
      var m = modal && modal.querySelector('[data-cc-cat="marketing"]');
      save(!!(a && a.checked), !!(m && m.checked), 'custom');
    } else if (t.hasAttribute('data-cc-close')) {
      e.preventDefault();
      closeModal();
    }
  });

  // Keyboard users meet the banner early: move it right after the skip link.
  if (banner) {
    var skip = d.querySelector('body > a[href="#main"]');
    if (skip && skip.parentNode) skip.parentNode.insertBefore(banner, skip.nextSibling);
    if (!current() || banner.hasAttribute('data-preview')) show(banner);
  }
  // /cookie-policy#cookie-settings (the no-JS target of "Cookie settings") opens the preferences directly.
  if (location.hash === '#cookie-settings') openModal(null);

  w.gimtConsent = {
    open: function () { openModal(null); },
    get: function () {
      var c = current();
      return c ? { analytics: c.a, marketing: c.m, date: new Date(c.t * 1000) } : null;
    },
    set: function (analytics, marketing) { save(!!analytics, !!marketing, 'api'); }
  };
})();
