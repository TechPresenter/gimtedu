/* =====================================================================
   Prerit Welfare Foundation - SEO module (admin/seo.php)
   Character counters, live search-result and social-card previews and
   focus-keyword hints in the SEO editor. Everything also works without
   JavaScript: the server shows the same checks after saving.
   ===================================================================== */
(function () {
    'use strict';

    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    var NON_WORD;
    try { NON_WORD = new RegExp('[^\\p{L}\\p{N}\\p{M}]+', 'gu'); } catch (e) { NON_WORD = /[^a-z0-9ऀ-ॿ]+/gi; }

    function norm(s) {
        return String(s || '').toLowerCase().replace(/<[^>]*>/g, ' ').replace(NON_WORD, ' ').replace(/\s+/g, ' ').trim();
    }
    function contains(hay, kw) {
        var k = norm(kw);
        return k !== '' && (' ' + norm(hay) + ' ').indexOf(' ' + k + ' ') !== -1;
    }
    function inUrl(kw, path) {
        var stop = ['a', 'an', 'and', 'the', 'for', 'of', 'in', 'to', 'with', 'on', 'at', 'by', 'or', 'ngo', 'india'];
        var tokens = String(path || '').toLowerCase().split(/[^a-z0-9]+/);
        var words = norm(kw).split(' ').filter(function (w) { return w.length >= 3 && stop.indexOf(w) === -1; });
        return words.every(function (w) {
            return tokens.some(function (t) { return t !== '' && (t === w || (w.length >= 5 && t.indexOf(w.slice(0, 5)) === 0)); });
        });
    }
    function clip(s, n) {
        s = String(s || '').trim();
        return s.length > n ? s.slice(0, n - 1) + '…' : s;
    }

    /* ---------- Character counters ---------- */
    qsa('[data-seo-count]').forEach(function (input) {
        var parts = input.getAttribute('data-seo-count').split(',');
        var min = parseInt(parts[0], 10) || 0;
        var max = parseInt(parts[1], 10) || 9999;
        var out = document.createElement('span');
        out.className = 'seo-count';
        out.id = (input.id || 'seo') + '-count';
        input.insertAdjacentElement('afterend', out);
        var described = (input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
        described.push(out.id);
        input.setAttribute('aria-describedby', described.join(' '));
        function update() {
            var n = input.value.trim().length;
            var state = n === 0 ? 'empty' : (n < min ? 'short' : (n > max ? 'long' : 'ok'));
            var text = n === 0 ? 'Empty: the default text is used.' : n + ' characters';
            if (state === 'short') { text += ' - short (aim for ' + min + '-' + max + ')'; }
            if (state === 'long') { text += ' - long (aim for ' + min + '-' + max + ')'; }
            out.textContent = text;
            out.className = 'seo-count is-' + state;
        }
        input.addEventListener('input', update);
        update();
    });

    /* ---------- Search result + social card preview ---------- */
    var pv = document.querySelector('[data-seo-preview]');
    var tIn = document.querySelector('[data-pv="title"]');
    var dIn = document.querySelector('[data-pv="desc"]');
    var otIn = document.querySelector('[data-pv="og_title"]');
    var odIn = document.querySelector('[data-pv="og_desc"]');
    var imgIn = document.getElementById('og_image');
    var d = pv ? pv.dataset : {};

    function currentTitle() { return (tIn && tIn.value.trim()) || d.defaultTitle || ''; }
    function currentDesc() { return (dIn && dIn.value.trim()) || d.defaultDesc || ''; }
    function fullTitle(t) {
        if (d.home === '1') {
            if (tIn && tIn.value.trim() && d.verbatim === '1') { return t; }
            return ((t && t !== 'Home') ? t : d.site) + ' - ' + d.tagline;
        }
        if (!t || t.toLowerCase().indexOf(String(d.site || '').toLowerCase()) !== -1) { return t; }
        return (t + d.suffix).length <= (parseInt(d.limit, 10) || 60) ? t + d.suffix : t;
    }
    function imageUrl() {
        var path = imgIn ? imgIn.value.trim() : '';
        if (path && /^(uploads|assets\/images)\/[A-Za-z0-9._\-\/]+\.(jpe?g|png|webp|gif)$/i.test(path) && path.indexOf('..') === -1) {
            return (d.base || '/') + path;
        }
        return d.itemImage || d.defaultImage || '';
    }
    function renderPreview() {
        if (!pv) { return; }
        var t = currentTitle();
        var desc = currentDesc();
        var el = pv.querySelector('[data-pv-title]');
        if (el) { el.textContent = clip(fullTitle(t), 70); }
        el = pv.querySelector('[data-pv-desc]');
        if (el) { el.textContent = clip(desc, 165); }
        el = pv.querySelector('[data-pv-og-title]');
        if (el) { el.textContent = clip((otIn && otIn.value.trim()) || t, 90); }
        el = pv.querySelector('[data-pv-og-desc]');
        if (el) { el.textContent = clip((odIn && odIn.value.trim()) || desc, 120); }
        el = pv.querySelector('[data-pv-img]');
        var src = imageUrl();
        if (el && src && el.getAttribute('src') !== src) { el.setAttribute('src', src); }
    }
    if (pv) {
        [tIn, dIn, otIn, odIn].forEach(function (inp) { if (inp) { inp.addEventListener('input', function () { renderPreview(); checkKeyword(); }); } });
        if (imgIn) { imgIn.addEventListener('change', renderPreview); imgIn.addEventListener('input', renderPreview); }
        renderPreview();
    }

    /* ---------- Focus keyword hints ---------- */
    var kw = document.querySelector('[data-kw-input]');
    var hints = document.querySelector('[data-kw-hints]');
    function checkKeyword() {
        if (!kw || !hints) { return; }
        var k = kw.value.trim();
        hints.hidden = k === '';
        if (k === '') { return; }
        var results = {
            title: contains(currentTitle(), k),
            h1: contains(hints.getAttribute('data-h1'), k),
            desc: contains(currentDesc(), k),
            url: inUrl(k, hints.getAttribute('data-path'))
        };
        qsa('[data-kw-check]', hints).forEach(function (li) {
            var ok = !!results[li.getAttribute('data-kw-check')];
            li.classList.toggle('is-ok', ok);
            li.classList.toggle('is-miss', !ok);
            var state = li.querySelector('.seo-kw-state');
            if (!state) {
                state = document.createElement('span');
                state.className = 'seo-kw-state';
                li.insertBefore(state, li.firstChild);
            }
            state.textContent = ok ? 'Yes: ' : 'No: ';
        });
    }
    if (kw && hints) {
        kw.addEventListener('input', checkKeyword);
        checkKeyword();
    }
})();
