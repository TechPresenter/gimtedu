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
 *
 * Measurement (only when a tag ID is configured — window.__gimtConsent.on; events go through window.gimtTrack, which
 * the head script forwards to GA4 / GTM (dataLayer) / Meta Pixel / Clarity under the visitor's consent). No personal
 * data is ever sent: never names, phone numbers, e-mail addresses, message text or link targets of tel/mailto/WhatsApp.
 *   clicks (setting track_click_events, C.clk)  phone_click · whatsapp_click · email_click {link_location}
 *                                               apply_click {link_location, program?} · brochure_download {link_location,
 *                                               program?, file_name?}
 *   views  (C.clk)                              course_view {program_code, program, level} once on /programs/{slug}
 *                                               fee_view {program_code?} once when a #fees / [data-track-section="fees"]
 *                                               section scrolls into view
 *   forms  (always)                             form:success of a plain <form data-ajax-form>: newsletter_signup
 *                                               {form_location} (newsletter endpoint) or generate_lead {form}; the
 *                                               React islands push their own (generate_lead, submit_application).
 * Event names and parameters: docs/WEBSITE.md §17 "Analytics events".
 */
(function () {
  'use strict';
  var w = window;
  var d = document;
  var core = w.__gimtConsent;
  if (!core) return;
  var C = core.cfg || {};
  if (core.on) measure();
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
    if (!a) expire(function (n) { return n === '_ga' || n === '_gid' || n === '_gat' || n.indexOf('_ga_') === 0 || n === '_clck' || n === '_clsk'; });
    if (!m) expire(function (n) { return n === '_fbp' || n === '_fbc' || n.indexOf('_gcl_') === 0; });
    core.apply({ a: !!a, m: !!m });
    if (core.on && w.dataLayer) {
      w.dataLayer.push({
        event: 'consent_update', consent_analytics: a ? 'granted' : 'denied', consent_marketing: m ? 'granted' : 'denied', consent_action: action
      });
    }
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

  /* ------------------------------------------------------------------ Measurement (no personal data) */
  function measure() {
    var track = function (name, params) {
      try {
        if (typeof w.gimtTrack === 'function') w.gimtTrack(name, params || {});
      } catch (e) { /* never break the page */ }
    };
    var clicks = !!C.clk;
    var done = {};
    var once = function (name, params) {
      if (done[name]) return;
      done[name] = 1;
      track(name, params);
    };
    // short, safe identifiers only (program codes / slugs / levels / form keys); anything else is dropped
    var clean = function (v, max) {
      v = v == null ? '' : String(v).trim();
      // never anything that looks like a phone number or an e-mail address
      if (/\d{7,}/.test(v.replace(/[\s.()+-]/g, '')) || v.indexOf('@') >= 0) return '';
      return /^[A-Za-z0-9][A-Za-z0-9 ._()&+-]*$/.test(v) ? v.slice(0, max || 80) : '';
    };
    var closest = function (el, sel) {
      try { return el && el.closest ? el.closest(sel) : null; } catch (e) { return null; }
    };
    var PLACES = [
      ['[data-track-location]', ''], ['#mobile-menu', 'mobile_menu'], ['.announce-strip,.announce-links,.announce-item', 'announcement_bar'],
      ['#site-header,header', 'header'], ['.mobile-cta', 'mobile_bar'],
      ['.call-fab,.chat-tab,.wa-fab', 'floating_button'], ['#gimt-chat', 'chatbot'], ['[role="dialog"]', 'popup'],
      ['footer,#site-footer', 'footer'], ['main,#main', 'content']
    ];
    var place = function (el) {
      for (var i = 0; i < PLACES.length; i++) {
        var hit = closest(el, PLACES[i][0]);
        if (hit) return PLACES[i][1] || clean(hit.getAttribute('data-track-location'), 40).toLowerCase() || 'content';
      }
      return 'page';
    };
    var parse = function (href) {
      try { return new URL(href, location.href); } catch (e) { return null; }
    };
    var isWhatsApp = function (href, u) {
      return /^whatsapp:/i.test(href) || !!(u && /(^|\.)(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com|chat\.whatsapp\.com)$/i.test(u.hostname));
    };
    var programFromUrl = function (u) {
      if (!u) return '';
      var p = u.searchParams.get('program') || u.searchParams.get('course') || '';
      if (!p) {
        var m = u.pathname.match(/\/admissions-open\/([^/?#]+)/);
        if (m) {
          try { p = decodeURIComponent(m[1]); } catch (e) { p = ''; }
        }
      }
      return clean(p, 80);
    };

    // one capture-phase listener for every link (keyboard Enter on a link fires a click too)
    d.addEventListener('click', function (e) {
      if (!clicks) return;
      var el = closest(e.target, 'a[href],[data-brochure]');
      if (!el) return;
      var href = el.getAttribute('href') || '';
      var where = place(el);
      if (/^tel:/i.test(href)) return track('phone_click', { link_location: where });
      if (/^mailto:/i.test(href)) return track('email_click', { link_location: where });
      var u = href ? parse(href) : null;
      if (isWhatsApp(href, u)) return track('whatsapp_click', { link_location: where });
      if (el.hasAttribute('data-brochure') || (u && (u.searchParams.has('brochure') || /\.pdf$/i.test(u.pathname)))) {
        var file = u && /\.pdf$/i.test(u.pathname) ? clean(u.pathname.split('/').pop(), 100) : '';
        return track('brochure_download', { link_location: where, program: clean(el.getAttribute('data-brochure'), 80) || programFromUrl(u), file_name: file });
      }
      if (u && u.host === location.host && !u.searchParams.has('ack')
          && (/(^|\/)apply\/?$/.test(u.pathname) || /\/admissions-open(\/|$)/.test(u.pathname))) {
        return track('apply_click', { link_location: where, program: programFromUrl(u) });
      }
    }, true);

    // plain server-rendered forms (footer / blog newsletter, enquiry fallback); the islands report their own leads
    d.addEventListener('form:success', function (e) {
      var f = e.target;
      if (!f || !f.matches || !f.matches('form[data-ajax-form]')) return;
      var action = f.getAttribute('action') || '';
      var key = clean(f.getAttribute('data-track-form') || f.getAttribute('data-secure'), 40).toLowerCase();
      if (key === 'newsletter' || /\/newsletter(\/|\?|$)/.test(action)) track('newsletter_signup', { form_location: place(f) });
      else track('generate_lead', { form: key || 'enquiry' });
    });

    var ready = function (fn) {
      if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', fn);
      else fn();
    };
    ready(function () {
      if (!clicks) return;
      // program detail page: the page's Course entity in JSON-LD (courseCode, educationalLevel)
      var course = null;
      var slug = (location.pathname.match(/\/programs\/([^/]+)\/?$/) || [])[1] || '';
      if (slug) {
        var nodes = d.querySelectorAll('script[type="application/ld+json"]');
        for (var i = 0; i < nodes.length && !course; i++) {
          var j;
          try { j = JSON.parse(nodes[i].textContent || 'null'); } catch (err) { continue; }
          var list = Array.isArray(j) ? j : (j && j['@graph'] ? j['@graph'] : [j]);
          for (var k = 0; k < list.length; k++) {
            var t = list[k] && list[k]['@type'];
            if ((t === 'Course' || (Array.isArray(t) && t.indexOf('Course') >= 0)) && list[k].courseCode) {
              course = list[k];
              break;
            }
          }
        }
        try { slug = decodeURIComponent(slug); } catch (err) { /* keep it raw */ }
        once('course_view', { program_code: course ? clean(course.courseCode, 40) : '', program: clean(slug, 80), level: course ? clean(course.educationalLevel, 40) : '' });
      }
      // fee section (program page #fees, admissions #fees, or any [data-track-section="fees"]) scrolled into view
      var fees = d.querySelector('#fees,[data-track-section="fees"]');
      if (fees && 'IntersectionObserver' in w) {
        var params = { program_code: course ? clean(course.courseCode, 40) : '' };
        var io = new IntersectionObserver(function (entries) {
          for (var n = 0; n < entries.length; n++) {
            if (entries[n].isIntersecting) {
              io.disconnect();
              once('fee_view', params);
              return;
            }
          }
        }, { rootMargin: '0px 0px -35% 0px', threshold: 0 });
        io.observe(fees);
      }
    });
  }
})();
