/* Deep analytics (admin): real-time panel refresh every 15 s (paused while the tab is hidden or by the button). */
(function () {
    'use strict';
    var box = document.querySelector('[data-realtime]');
    if (!box || !window.fetch) { return; }
    var endpoint = box.getAttribute('data-endpoint');
    var interval = parseInt(box.getAttribute('data-interval'), 10) || 15000;
    var status = document.querySelector('[data-rt-status]');
    var toggle = document.querySelector('[data-rt-toggle]');
    var paused = false;
    var timer = null;
    var busy = false;

    function say(text) { if (status) { status.textContent = text; } }

    function refresh() {
        if (busy || paused || document.visibilityState !== 'visible') { return; }
        busy = true;
        box.classList.add('is-loading');
        box.setAttribute('aria-busy', 'true');
        fetch(endpoint, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) {
                if (!r.ok || (r.headers.get('Content-Type') || '').indexOf('application/json') === -1) { throw new Error('session'); }
                return r.json();
            })
            .then(function (data) {
                if (!data || !data.ok) { throw new Error('data'); }
                // focus inside the panel (e.g. on a link) is kept on the panel itself after the swap
                var hadFocus = box.contains(document.activeElement);
                box.innerHTML = data.html;
                if (hadFocus) { box.setAttribute('tabindex', '-1'); box.focus({ preventScroll: true }); }
                say('Updated ' + data.updated + ' · ' + data.active + (data.active === 1 ? ' active visitor' : ' active visitors'));
            })
            .catch(function (e) {
                if (e && e.message === 'session') {
                    paused = true;
                    window.clearInterval(timer);
                    say('Auto-refresh stopped: your admin session has ended. Reload the page to sign in again.');
                } else {
                    say('Could not refresh just now - will try again.');
                }
            })
            .then(function () {
                busy = false;
                box.classList.remove('is-loading');
                box.removeAttribute('aria-busy');
            });
    }

    timer = window.setInterval(refresh, interval);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') { refresh(); }
    });
    if (toggle) {
        toggle.addEventListener('click', function () {
            paused = !paused;
            toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
            var label = toggle.querySelector('span');
            if (label) { label.textContent = paused ? 'Resume auto-refresh' : 'Pause auto-refresh'; }
            say(paused ? 'Auto-refresh paused' : 'Auto-refresh on - every ' + Math.round(interval / 1000) + ' seconds');
            if (!paused) { refresh(); }
        });
    }
}());
