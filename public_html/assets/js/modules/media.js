/* =====================================================================
   Media module (M6): YouTube facades, small admin helpers.
   No inline scripts - loaded with media_js() on pages that need it.
   ===================================================================== */
(function () {
    'use strict';

    /* ---------- YouTube facade: load the privacy-enhanced player only after a click ---------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('a[data-yt]') : null;
        if (!btn) { return; }
        var id = btn.getAttribute('data-yt') || '';
        if (!/^[A-Za-z0-9_-]{11}$/.test(id)) { return; }
        e.preventDefault();
        var holder = btn.parentNode;
        var frame = document.createElement('iframe');
        frame.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&modestbranding=1';
        frame.title = btn.getAttribute('data-title') || 'Video';
        frame.setAttribute('allow', 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture');
        frame.setAttribute('allowfullscreen', '');
        frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        frame.className = 'video-iframe';
        holder.classList.add('is-playing');
        holder.replaceChild(frame, btn);
        frame.focus();
    });

    /* ---------- Hide a thumbnail that cannot load (offline / blocked) - the play button stays ---------- */
    document.querySelectorAll('.video-facade img').forEach(function (img) {
        var hide = function () { img.style.visibility = 'hidden'; };
        if (img.complete && img.naturalWidth === 0 && img.currentSrc) { hide(); }
        img.addEventListener('error', hide);
    });

    /* ---------- Character counters for inputs (admin SEO fields) ---------- */
    document.querySelectorAll('input[maxlength][data-counter]').forEach(function (input) {
        var counter = document.createElement('span');
        counter.className = 'help char-counter';
        counter.setAttribute('aria-live', 'polite');
        input.insertAdjacentElement('afterend', counter);
        var ideal = parseInt(input.getAttribute('data-ideal') || '0', 10);
        var update = function () {
            var n = input.value.length;
            var text = n + ' / ' + input.maxLength + ' characters';
            if (ideal) {
                text += n === 0 ? ' (leave empty to use the default)' : (n > ideal ? ' - longer than the recommended ' + ideal : ' - good length');
            }
            counter.textContent = text;
            counter.style.color = ideal && n > ideal ? '#a05a00' : '';
        };
        input.addEventListener('input', update);
        update();
    });

    /* ---------- Show the consent reminder when "publish" is chosen without consent (admin) ---------- */
    document.querySelectorAll('[data-consent-for]').forEach(function (box) {
        var select = document.getElementById(box.getAttribute('data-consent-for'));
        var warn = document.getElementById(box.getAttribute('data-consent-warning') || '');
        if (!select || !warn) { return; }
        var sync = function () { warn.hidden = !(select.value === 'published' && !box.checked); };
        select.addEventListener('change', sync);
        box.addEventListener('change', sync);
        sync();
    });
})();
