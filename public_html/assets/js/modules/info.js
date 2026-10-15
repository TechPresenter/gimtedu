/* =====================================================================
   M8 Information pages: FAQ search & topic filter, testimonials carousel.
   Without JavaScript every FAQ is listed and the carousel scrolls natively.
   ===================================================================== */
(function () {
    'use strict';

    /* ---------- FAQ search & topic filter ---------- */
    var tools = document.querySelector('[data-faq-tools]');
    if (tools) {
        var search = tools.querySelector('[data-faq-search]');
        var status = tools.querySelector('[data-faq-status]');
        var none = document.querySelector('[data-faq-none]');
        var buttons = tools.querySelectorAll('[data-faq-filter]');
        var groups = document.querySelectorAll('[data-faq-group]');
        var topic = 'all';
        var timer = null;

        var normalise = function (s) { return (s || '').toLowerCase().replace(/\s+/g, ' ').trim(); };

        var apply = function () {
            var words = normalise(search ? search.value : '').split(' ').filter(function (w) { return w.length > 1; });
            var shown = 0;
            var opened = 0;
            document.querySelectorAll('[data-faq-item][data-auto-open]').forEach(function (item) {
                item.open = false;
                item.removeAttribute('data-auto-open');
            });
            groups.forEach(function (group) {
                var inTopic = topic === 'all' || group.getAttribute('data-faq-group') === topic;
                var visibleInGroup = 0;
                group.querySelectorAll('[data-faq-item]').forEach(function (item) {
                    var text = item.getAttribute('data-text');
                    if (text === null) {
                        text = normalise(item.textContent);
                        item.setAttribute('data-text', text);
                    }
                    var match = inTopic && words.every(function (w) { return text.indexOf(w) !== -1; });
                    item.hidden = !match;
                    if (match) {
                        visibleInGroup++;
                        // While searching, open the first few matching answers
                        if (words.length && opened < 3 && !item.open) {
                            item.open = true;
                            item.setAttribute('data-auto-open', '1');
                            opened++;
                        }
                    }
                });
                group.hidden = visibleInGroup === 0;
                shown += visibleInGroup;
            });
            if (none) { none.hidden = shown !== 0; }
            if (status) {
                status.textContent = (shown === 1 ? '1 question' : shown + ' questions') + (words.length ? ' match your search' : '');
            }
        };

        if (search) {
            search.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(apply, 120);
            });
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { search.value = ''; apply(); }
            });
        }
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                topic = btn.getAttribute('data-faq-filter');
                buttons.forEach(function (b) {
                    var on = b === btn;
                    b.classList.toggle('is-active', on);
                    b.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                apply();
            });
        });
        // Open the question linked from elsewhere, e.g. /faq/#faq-donations
        if (location.hash) {
            var target = document.getElementById(location.hash.slice(1));
            if (target && target.matches('[data-faq-group]')) {
                var first = target.querySelector('[data-faq-item]');
                if (first) { first.open = true; }
            }
        }
    }

    /* ---------- Testimonials carousel ---------- */
    document.querySelectorAll('[data-carousel]').forEach(function (carousel) {
        var track = carousel.querySelector('[data-carousel-track]');
        var prev = carousel.querySelector('[data-carousel-prev]');
        var next = carousel.querySelector('[data-carousel-next]');
        if (!track) { return; }
        var step = function () {
            var slide = track.querySelector('.t-slide');
            return slide ? slide.getBoundingClientRect().width + 22 : track.clientWidth * 0.8;
        };
        var update = function () {
            var max = track.scrollWidth - track.clientWidth - 4;
            if (prev) { prev.disabled = track.scrollLeft <= 4; }
            if (next) { next.disabled = track.scrollLeft >= max; }
            var nav = carousel.querySelector('.t-nav');
            if (nav) { nav.hidden = track.scrollWidth <= track.clientWidth + 4; }
        };
        if (prev) { prev.addEventListener('click', function () { track.scrollBy({ left: -step(), behavior: 'smooth' }); }); }
        if (next) { next.addEventListener('click', function () { track.scrollBy({ left: step(), behavior: 'smooth' }); }); }
        track.addEventListener('scroll', function () { window.requestAnimationFrame(update); }, { passive: true });
        track.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight') { e.preventDefault(); track.scrollBy({ left: step(), behavior: 'smooth' }); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); track.scrollBy({ left: -step(), behavior: 'smooth' }); }
        });
        window.addEventListener('resize', update);
        update();
    });
})();
