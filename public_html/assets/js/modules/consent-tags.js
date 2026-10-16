/*
 * Google Analytics 4 / Google Tag Manager behind the website's single cookie notice - Prerit Welfare Foundation (SEO WP-5).
 *
 * - Configuration comes from the JSON data block #pwf-tags printed by render_seo_footer() (includes/seo-tags.php);
 *   queued events from #pwf-dl. Both are data blocks (type application/json), never executed.
 * - Consent Mode v2, basic implementation: consent "default" is denied for every storage type before anything else; Google's
 *   script is injected only after the visitor's explicit "Allow analytics" (localStorage pwf_consent = "yes", the key shared
 *   with script.js and analytics.js - there is no second notice). That choice grants analytics_storage only; ad_storage,
 *   ad_user_data and ad_personalization always stay denied.
 * - page_location / page_referrer are reduced to origin + path + allow-listed parameters (never token, ref, email ...).
 * - Events: server-queued conversions (#pwf-dl) and clicks on [data-dl-event] elements are pushed only after consent;
 *   parameters are short lower-case slugs, never personal data.
 * window.PWFTags = { load(), deny(), push(name, params), cleanUrl(url) } - script.js delegates its loader here.
 */
(function () {
    'use strict';
    var cfgEl = document.getElementById('pwf-tags');
    if (!cfgEl || !window.JSON || window.PWFTags) { return; }
    var cfg = {};
    try { cfg = JSON.parse(cfgEl.textContent || '{}') || {}; } catch (e) { cfg = {}; }
    var noop = function () {};
    if (cfg.off || (cfg.mode !== 'gtag' && cfg.mode !== 'gtm')) {
        window.PWFTags = { load: noop, deny: noop, push: noop, cleanUrl: function () { return ''; } };
        return;
    }
    var mode = cfg.mode;
    var id = String(cfg.id || '');
    if (!(mode === 'gtag' ? /^G-[A-Z0-9]{4,15}$/ : /^GTM-[A-Z0-9]{4,12}$/).test(id)) { return; }

    var allowParams = Array.isArray(cfg.params) ? cfg.params : ['page', 'category', 'type', 'when'];
    var clientEvents = Array.isArray(cfg.client) ? cfg.client : [];
    var clientKeys = Array.isArray(cfg.ckeys) ? cfg.ckeys : [];
    var serverEvents = [];
    try {
        var dlEl = document.getElementById('pwf-dl');
        serverEvents = dlEl ? (JSON.parse(dlEl.textContent || '[]') || []) : [];
    } catch (e) { serverEvents = []; }
    var loaded = false;
    var gtmKeys = {};

    window.dataLayer = window.dataLayer || [];
    var gtag = function () { window.dataLayer.push(arguments); };
    if (typeof window.gtag !== 'function') { window.gtag = gtag; }

    // Consent Mode v2: everything denied until the visitor's choice (basic mode - no Google script is loaded before it)
    gtag('consent', 'default', {
        ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied',
        functionality_storage: 'denied', personalization_storage: 'denied', security_storage: 'granted', wait_for_update: 500
    });
    gtag('set', 'ads_data_redaction', true);

    /* ---------- privacy helpers ---------- */
    function storedChoice() {
        try { return window.localStorage.getItem('pwf_consent'); } catch (e) { return null; }
    }
    function safeValue(v) {
        v = String(v == null ? '' : v);
        return v.length > 0 && v.length <= 60 && /^[A-Za-z0-9 _.\-]+$/.test(v) && !/\d{6,}/.test(v);
    }
    function slug(v) {
        v = String(v == null ? '' : v).trim().toLowerCase();
        return v.length <= 60 && /^[a-z0-9][a-z0-9_.\-]*$/.test(v) && !/\d{5,}/.test(v) ? v : '';
    }
    function cleanPath(path) {
        return String(path || '/').split('/').map(function (seg) {
            var d = seg;
            try { d = decodeURIComponent(seg); } catch (e) { d = seg; }
            return (/@|\d{6,}/.test(d) || d.length > 80) ? 'redacted' : seg;
        }).join('/').slice(0, 500);
    }
    // origin + path + allow-listed parameters for this website; origin only for other websites
    function cleanUrl(raw, max) {
        if (!raw) { return ''; }
        var u;
        try { u = new URL(raw, location.href); } catch (e) { return ''; }
        if (u.protocol !== 'https:' && u.protocol !== 'http:') { return ''; }
        if (u.origin !== location.origin) { return u.origin + '/'; }
        var q = [];
        allowParams.forEach(function (k) {
            var v = u.searchParams.get(k);
            if (v !== null && safeValue(v)) { q.push(encodeURIComponent(k) + '=' + encodeURIComponent(v)); }
        });
        return (u.origin + cleanPath(u.pathname) + (q.length ? '?' + q.join('&') : '')).slice(0, max || 1000);
    }
    function cleanParams(p) {
        var out = {};
        if (!p || typeof p !== 'object') { return out; }
        Object.keys(p).forEach(function (k) {
            if (!/^[a-z][a-z0-9_]{0,39}$/.test(k)) { return; }
            var v = p[k];
            if (k === 'value' || k === 'price' || k === 'quantity') {
                if (typeof v === 'number' && isFinite(v) && v >= 0) { out[k] = v; }
            } else if (k === 'items' && Array.isArray(v)) {
                out.items = v.slice(0, 1).map(cleanParams);
            } else if (k === 'currency') {
                if (/^[A-Z]{3}$/.test(String(v))) { out.currency = String(v); }
            } else if (k === 'item_name') {
                if (/^[A-Za-z ]{1,40}$/.test(String(v))) { out.item_name = String(v); }
            } else {
                var s = slug(v);
                if (s) { out[k] = s; }
            }
        });
        return out;
    }

    /* ---------- events ---------- */
    function send(name, params) {
        if (!loaded || !/^[a-z][a-z0-9_]{2,39}$/.test(String(name))) { return; }
        params = cleanParams(params);
        if (mode === 'gtag') {
            gtag('event', name, params);
            return;
        }
        // Tag Manager: e-commerce data under "ecommerce" (cleared first), stale keys of earlier events reset
        var msg = { event: name };
        Object.keys(gtmKeys).forEach(function (k) { msg[k] = undefined; });
        if (name === 'begin_checkout' || name === 'purchase') {
            window.dataLayer.push({ ecommerce: null });
            var eco = {};
            ['currency', 'value', 'items'].forEach(function (k) { if (params[k] !== undefined) { eco[k] = params[k]; delete params[k]; } });
            msg.ecommerce = eco;
        }
        Object.keys(params).forEach(function (k) { msg[k] = params[k]; gtmKeys[k] = true; });
        window.dataLayer.push(msg);
    }
    function seen(uid) {
        if (!uid) { return false; }
        var list = [];
        try { list = JSON.parse(window.sessionStorage.getItem('pwf_dl_seen') || '[]') || []; } catch (e) { list = []; }
        if (list.indexOf(uid) !== -1) { return true; }
        list.push(uid);
        try { window.sessionStorage.setItem('pwf_dl_seen', JSON.stringify(list.slice(-30))); } catch (e) { /* storage blocked */ }
        return false;
    }
    function flush() {
        var queue = serverEvents;
        serverEvents = [];
        (Array.isArray(queue) ? queue : []).forEach(function (ev) {
            if (ev && typeof ev === 'object' && !seen(String(ev.u || ''))) { send(String(ev.n || ''), ev.p || {}); }
        });
    }

    /* ---------- loading (only after "Allow analytics") ---------- */
    function inject(src) {
        var sc = document.createElement('script');
        sc.async = true;
        sc.src = src;
        document.head.appendChild(sc);
    }
    function load() {
        if (loaded) { return; }
        loaded = true;
        if (window.__gaLoaded) { flush(); return; }   // an older loader already added the tag: never a second copy
        window.__gaLoaded = true;   // ... and script.js's older loader must not add one after this
        gtag('consent', 'update', { analytics_storage: 'granted' });
        var loc = cleanUrl(location.href, 1000);
        var ref = cleanUrl(document.referrer, 420);
        if (mode === 'gtag') {
            gtag('js', new Date());
            gtag('set', { page_location: loc, page_referrer: ref });
            gtag('config', id, {
                page_location: loc, page_referrer: ref,
                allow_google_signals: false, allow_ad_personalization_signals: false
            });
            inject('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id));
        } else {
            gtag('set', { page_location: loc, page_referrer: ref });
            window.dataLayer.push({ page_location: loc, page_referrer: ref });
            window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
            inject('https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(id));
        }
        flush();
    }
    function deleteGaCookies() {
        var host = location.hostname;
        var domains = ['', '; domain=' + host, '; domain=.' + host];
        var parts = host.split('.');
        if (parts.length > 2) { domains.push('; domain=.' + parts.slice(-2).join('.')); }
        document.cookie.split(';').forEach(function (c) {
            var name = c.split('=')[0].trim();
            if (name === '_ga' || name.indexOf('_ga_') === 0 || name === '_gid') {
                domains.forEach(function (d) { document.cookie = name + '=; Max-Age=0; path=/' + d; });
            }
        });
    }
    // "No, thanks" (also a later withdrawal): storage denied, no further hits, cookies removed (script.js then reloads)
    function deny() {
        serverEvents = [];
        if (loaded) {
            gtag('consent', 'update', { analytics_storage: 'denied' });
        }
        if (mode === 'gtag') { window['ga-disable-' + id] = true; }
        deleteGaCookies();
    }

    // Global Privacy Control with no stored choice counts as "No" (nothing loads without an explicit "yes" anyway)
    var choice = storedChoice();
    if (choice === 'yes') {
        load();
    } else if (choice === 'no') {
        deny();
    }

    // the one cookie notice (script.js stores the choice; both listeners are harmless together)
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target : null;
        if (!t) { return; }
        var b = t.closest('[data-consent-banner] [data-consent]');
        if (b) {
            if (b.getAttribute('data-consent') === 'yes') { load(); } else { deny(); }
            return;
        }
        // CTA clicks: <a data-dl-event="donate_click" data-dl-location="hero"> (allow-listed names and keys only)
        var el = t.closest('[data-dl-event]');
        if (!el || !loaded) { return; }
        var name = el.getAttribute('data-dl-event');
        if (clientEvents.indexOf(name) === -1) { return; }
        var params = {};
        clientKeys.forEach(function (k) {
            var v = el.getAttribute('data-dl-' + k.replace(/_/g, '-'));
            if (v !== null) { params[k] = v; }
        });
        send(name, params);
    }, true);

    window.PWFTags = {
        load: load,
        deny: deny,
        push: function (name, params) { if (clientEvents.indexOf(name) !== -1) { send(name, params || {}); } },
        cleanUrl: function (u) { return cleanUrl(u, 1000); },
        gpcNo: function () { return cfg.gpc === 1 && navigator.globalPrivacyControl === true && storedChoice() === null; }
    };
})();
