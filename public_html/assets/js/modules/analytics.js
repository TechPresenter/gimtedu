/*
 * First-party website statistics (deep analytics) - Prerit Welfare Foundation.
 * Loaded only on pages whose visit was recorded (never for staff, bots or Do-Not-Track / GPC visitors).
 * Measures visible engaged time, scroll depth, clicks on buttons and links, downloads, outbound / phone / email /
 * WhatsApp links, form submits (form id and action path only - never what was typed), share buttons, video plays,
 * JavaScript errors, rage clicks and page load time, and sends small batches to the website's own server
 * (/api/collect/) with navigator.sendBeacon / fetch keepalive. No third parties, no fingerprinting.
 */
(function () {
    'use strict';
    var el = document.getElementById('pwf-analytics');
    if (!el || !window.JSON || !document.addEventListener) { return; }
    var cfg = {
        pv: parseInt(el.getAttribute('data-pv'), 10) || 0,
        token: el.getAttribute('data-t') || '',
        url: el.getAttribute('data-endpoint') || '',
        consent: el.getAttribute('data-consent') === 'ask',
        vid: el.getAttribute('data-vid') === '1',
        dnt: el.getAttribute('data-dnt') === '1'
    };
    if (!cfg.pv || !cfg.token || !cfg.url) { return; }
    // the server already skips these visitors; double-check in case the page came from a cache
    if (cfg.dnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true)) { return; }

    var IDLE_MS = 30000;
    var HEARTBEAT_MS = 15000;
    var BATCH = 40;
    var MAX_QUEUE = 200;
    var seq = 0;
    var queue = [];
    var engagedMs = 0;
    var lastTick = Date.now();
    var lastActivity = Date.now();
    var maxScroll = 0;
    var loadMs = 0;
    var loadSent = false;
    var firstSent = false;
    var sentEngaged = -1;
    var sentScroll = -1;
    var pendingConsent = null;
    var milestones = {};
    var errors = 0;
    var rageClicks = [];
    var rageDone = {};
    var videoDone = {};
    var left = false;

    /* ---------- helpers ---------- */
    function clean(text, max) {
        text = String(text == null ? '' : text).replace(/\s+/g, ' ').trim();
        // never send what looks like personal data (emails, phone / ID numbers)
        text = text.replace(/[^\s@]+@[^\s@]+/g, '[email]').replace(/\d[\d\s\-]{4,}\d/g, '[number]');
        return text.slice(0, max || 80);
    }
    function noQuery(url) {
        return String(url || '').replace(/[?#].*$/, '');
    }
    function pathOf(url) {
        try {
            var u = new URL(url, location.href);
            return u.host === location.host ? u.pathname : u.host.replace(/^www\./, '') + u.pathname;
        } catch (e) {
            return noQuery(url).slice(0, 200);
        }
    }
    function push(type, label, target) {
        if (queue.length >= MAX_QUEUE) { return; }
        queue.push([type, label ? String(label).slice(0, 150) : '', target ? String(target).slice(0, 250) : '']);
    }
    function where(node) {
        if (node.closest('.fab-stack')) { return 'floating button'; }
        if (node.closest('footer')) { return 'footer'; }
        if (node.closest('header, .topbar')) { return 'header'; }
        return 'page';
    }
    function labelOf(node) {
        return clean(node.getAttribute('data-track') || node.getAttribute('aria-label') || node.getAttribute('data-tip') || node.textContent || node.getAttribute('title') || '', 80);
    }
    function describe(node) {
        var d = node.tagName ? node.tagName.toLowerCase() : 'element';
        if (node.id) { return d + '#' + node.id.slice(0, 40); }
        var c = typeof node.className === 'string' ? node.className.trim().split(/\s+/)[0] : '';
        return c ? d + '.' + c.slice(0, 40) : d;
    }
    function storedConsent() {
        try { return window.localStorage.getItem('pwf_consent'); } catch (e) { return null; }
    }

    /* ---------- engaged time: counts only while the page is visible and the visitor active (30 s idle pause) ---------- */
    function activity() { lastActivity = Date.now(); }
    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel', 'pointerdown'].forEach(function (name) {
        window.addEventListener(name, activity, { passive: true, capture: true });
    });
    window.setInterval(function () {
        var now = Date.now();
        var step = Math.min(now - lastTick, 2000);   // a sleeping laptop or throttled tab never adds a big jump
        lastTick = now;
        if (document.visibilityState === 'visible' && now - lastActivity < IDLE_MS) {
            engagedMs += step;
        }
    }, 1000);
    document.addEventListener('visibilitychange', function () {
        lastTick = Date.now();
        if (document.visibilityState === 'visible') {
            activity();
        } else {
            send(false);
        }
    });

    /* ---------- scroll depth ---------- */
    function scrollPct() {
        var doc = document.documentElement;
        var height = Math.max(doc.scrollHeight, document.body ? document.body.scrollHeight : 0);
        var seen = (window.pageYOffset || doc.scrollTop || 0) + window.innerHeight;
        return height > 0 ? Math.min(100, Math.round(seen / height * 100)) : 100;
    }
    var scrollQueued = false;
    window.addEventListener('scroll', function () {
        if (scrollQueued) { return; }
        scrollQueued = true;
        window.requestAnimationFrame(function () {
            scrollQueued = false;
            var pct = scrollPct();
            if (pct > maxScroll) { maxScroll = pct; }
            [25, 50, 75, 100].forEach(function (m) {
                if (pct >= (m === 100 ? 98 : m) && !milestones[m]) {
                    milestones[m] = true;
                    push('scroll_depth', String(m), '');
                }
            });
        });
    }, { passive: true });

    /* ---------- clicks ---------- */
    var DOWNLOAD_RE = /\.(pdf|docx?|xlsx?|pptx?|csv|zip|rar|7z|txt|odt|ods|odp|epub|mp3|mp4|apk)$/i;
    function linkEvent(a) {
        var href = a.getAttribute('href') || '';
        if (/^tel:/i.test(href)) { return ['phone', where(a), '']; }
        if (/^mailto:/i.test(href)) {
            return a.closest('.share-row') ? ['share', 'email', location.pathname] : ['email', where(a), ''];
        }
        if (/^(javascript:|#)/i.test(href)) { return null; }
        var u;
        try { u = new URL(a.href, location.href); } catch (e) { return null; }
        var host = u.hostname.replace(/^www\./, '');
        if (a.closest('.share-row') || /(^|\s)share-/.test(a.className || '')) {
            var net = ((a.className || '').match(/share-([a-z]+)/) || [])[1] || host;
            return ['share', net, location.pathname];
        }
        if (/^(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com|chat\.whatsapp\.com)$/.test(host) || u.protocol === 'whatsapp:') {
            return ['whatsapp', where(a), ''];
        }
        if (a.hasAttribute('download') || DOWNLOAD_RE.test(u.pathname)) {
            return ['download', clean(a.getAttribute('data-track') || a.textContent || u.pathname.split('/').pop(), 80), pathOf(u.href)];
        }
        if (a.matches('.video-facade-btn, [data-yt]')) {
            return ['video_play', clean(a.getAttribute('data-title') || 'Video', 80), 'youtube'];
        }
        if (u.host !== location.host && /^https?:$/.test(u.protocol)) {
            return ['outbound', labelOf(a), host + u.pathname];
        }
        if (a.matches('.btn, [data-track], .apply-chip, .fab, .header-donate')) {
            return ['cta_click', labelOf(a), u.pathname];
        }
        return null;
    }
    function rage(e) {
        var now = Date.now();
        rageClicks = rageClicks.filter(function (c) { return now - c.t < 1000; });
        rageClicks.push({ t: now, x: e.clientX, y: e.clientY });
        var near = rageClicks.filter(function (c) { return Math.abs(c.x - e.clientX) < 30 && Math.abs(c.y - e.clientY) < 30; });
        if (near.length >= 3) {
            var d = describe(e.target);
            if (!rageDone[d]) {
                rageDone[d] = true;
                push('rage_click', d, location.pathname);
            }
            rageClicks = [];
        }
    }
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest || t.closest('[data-no-track], #pwf-analytics')) { return; }
        activity();
        rage(e);
        var a = t.closest('a[href]');
        if (a) {
            var ev = linkEvent(a);
            if (ev) { push(ev[0], ev[1], ev[2]); }
            if (ev && (ev[0] === 'outbound' || ev[0] === 'download')) { send(false); }
            return;
        }
        var b = t.closest('button, [role="button"]');
        if (!b || b.type === 'submit') { return; }
        if (b.matches('.share-copy')) {
            push('share', 'copy link', location.pathname);
        } else if (b.matches('[data-consent]')) {
            consentClick(b.getAttribute('data-consent'));
        } else if (b.matches('.btn, [data-track], .fab, .apply-chip')) {
            push('cta_click', labelOf(b), '');
        }
    }, true);

    /* ---------- video embeds: a click into a YouTube player moves the focus into its frame ---------- */
    window.addEventListener('blur', function () {
        window.setTimeout(function () {
            var f = document.activeElement;
            if (f && f.tagName === 'IFRAME' && /youtube(-nocookie)?\.com/.test(f.src || '')) {
                var key = noQuery(f.src);
                if (!videoDone[key]) {
                    videoDone[key] = true;
                    push('video_play', clean(f.getAttribute('title') || 'Video', 80), 'youtube');
                }
            }
        }, 0);
    });

    /* ---------- forms: id and action path only; search boxes send the (scrubbed) search words ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM' || e.defaultPrevented || form.closest('[data-no-track]')) { return; }
        var action = pathOf(form.getAttribute('action') || location.pathname);
        var search = form.querySelector('input[type="search"]');
        if (search || form.getAttribute('role') === 'search') {
            var q = search ? search.value : '';
            if (q && q.trim().length >= 2) { push('search', clean(q, 80), action); }
        } else {
            push('form_submit', (form.id || '').slice(0, 60), action);
        }
        send(false);
    });
    var faqSearch = document.querySelector('[data-faq-search]');
    if (faqSearch) {
        faqSearch.addEventListener('change', function () {
            if (faqSearch.value.trim().length >= 3) { push('search', clean(faqSearch.value, 80), location.pathname); }
        });
    }

    /* ---------- JavaScript errors (message shortened, file path without query strings) ---------- */
    window.addEventListener('error', function (e) {
        if (errors >= 5 || !e || !e.message) { return; }
        var file = e.filename || '';
        if (/^(chrome|moz|safari|edge)-extension:/i.test(file)) { return; }
        errors++;
        push('js_error', clean(String(e.message).replace(/(https?:\/\/[^\s?#]+)[?#]\S*/g, '$1'), 150), (file ? pathOf(file) : location.pathname) + (e.lineno ? ':' + e.lineno : ''));
    });
    window.addEventListener('unhandledrejection', function (e) {
        if (errors >= 5) { return; }
        errors++;
        var r = e && e.reason;
        push('js_error', clean('Unhandled promise: ' + String(r && r.message ? r.message : r).replace(/(https?:\/\/[^\s?#]+)[?#]\S*/g, '$1'), 150), location.pathname);
    });

    /* ---------- page load time (Navigation Timing) ---------- */
    function measureLoad() {
        try {
            var nav = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
            if (nav) {
                loadMs = Math.round(nav.loadEventEnd > 0 ? nav.loadEventEnd : nav.domContentLoadedEventEnd);
            } else if (window.performance && performance.timing) {
                var t = performance.timing;
                loadMs = Math.max(0, (t.loadEventEnd || t.domContentLoadedEventEnd) - t.navigationStart);
            }
        } catch (e) { loadMs = 0; }
        maxScroll = Math.max(maxScroll, scrollPct());
    }
    if (document.readyState === 'complete') {
        window.setTimeout(measureLoad, 0);
    } else {
        window.addEventListener('load', function () { window.setTimeout(measureLoad, 0); });
    }

    /* ---------- consent for the persistent visitor id (cookie pwf_vid, set by the server only after "Allow") ---------- */
    function consentClick(choice) {
        if (!cfg.consent) { return; }
        if (choice === 'yes' && !cfg.vid) {
            pendingConsent = 'yes';
            cfg.vid = true;
            send(false, true);
        } else if (choice === 'no' && cfg.vid) {
            pendingConsent = 'no';
            cfg.vid = false;
            send(false, true);
        }
    }
    if (cfg.consent) {
        var stored = storedConsent();
        if (stored === 'yes' && !cfg.vid) { pendingConsent = 'yes'; cfg.vid = true; }
        if (stored === 'no' && cfg.vid) { pendingConsent = 'no'; cfg.vid = false; }
        // "Cookie settings" link in the footer (footer.php prints its own only when Google Analytics is on)
        var banner = document.querySelector('[data-consent-banner]');
        var nav = document.querySelector('.footer-bottom nav');
        if (banner && nav && !document.querySelector('[data-consent-reset]')) {
            var reset = document.createElement('button');
            reset.type = 'button';
            reset.className = 'link-button';
            reset.textContent = 'Cookie settings';
            reset.addEventListener('click', function () {
                banner.hidden = false;
                var first = banner.querySelector('[data-consent]');
                if (first) { first.focus(); }
            });
            nav.appendChild(reset);
        }
    }

    /* ---------- while the cookie notice is open, the end of the page (footer forms) can still scroll above it ---------- */
    var notice = document.querySelector('[data-consent-banner]');
    if (notice) {
        var syncNotice = function () {
            var open = !notice.hidden && notice.offsetHeight > 0;
            document.documentElement.style.setProperty('--consent-pad', open ? (notice.offsetHeight + 24) + 'px' : '0px');
            document.body.classList.toggle('consent-open', open);
        };
        if (window.MutationObserver) {
            new MutationObserver(syncNotice).observe(notice, { attributes: true, attributeFilter: ['hidden', 'class', 'style'] });
        }
        window.addEventListener('resize', syncNotice);
        window.setTimeout(syncNotice, 0);
    }

    /* ---------- sending ---------- */
    function send(final, viaFetch) {
        var engaged = Math.round(engagedMs / 1000);
        var changed = queue.length || engaged !== sentEngaged || maxScroll !== sentScroll || (loadMs && !loadSent) || pendingConsent || !firstSent;
        if (!changed && !(final && !left)) { return; }
        var p = { pv: cfg.pv, t: cfg.token, s: ++seq, e: engaged, sc: maxScroll, ev: queue.splice(0, BATCH) };
        if (loadMs && !loadSent) { p.l = loadMs; loadSent = true; }
        if (final) { p.f = 1; left = true; }
        if (!firstSent) {
            firstSent = true;
            try { p.tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { p.tz = ''; }
            p.lg = (navigator.language || '').slice(0, 16);
            p.sw = window.screen ? window.screen.width : 0;
        }
        if (pendingConsent) {
            p.c = pendingConsent;
            pendingConsent = null;
            viaFetch = true;   // the response sets / removes the cookie
        }
        sentEngaged = engaged;
        sentScroll = maxScroll;
        var body = JSON.stringify(p);
        var sent = false;
        if (!viaFetch && navigator.sendBeacon) {
            try { sent = navigator.sendBeacon(cfg.url, new Blob([body], { type: 'text/plain;charset=UTF-8' })); } catch (e) { sent = false; }
        }
        if (!sent && window.fetch) {
            try {
                fetch(cfg.url, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'text/plain;charset=UTF-8' } })
                    .catch(function () { /* offline: the next batch carries the totals */ });
            } catch (e) { /* ignore */ }
        }
        if (queue.length) { send(final, viaFetch); }
    }
    // first report shortly after the page loaded, then every 15 s while the page is visible
    window.setTimeout(function () { send(false); }, 2500);
    window.setInterval(function () {
        if (document.visibilityState === 'visible') { send(false); }
    }, HEARTBEAT_MS);
    window.addEventListener('pagehide', function () { send(true); });
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) { left = false; lastTick = Date.now(); activity(); }
    });
}());
