/* Top bar "Programs & Events" dropdown: opens on hover/focus (CSS) and with the toggle button (click, Enter,
   Space). Escape closes it and returns focus to the toggle; a click outside closes it. */
(function () {
    'use strict';
    var root = document.querySelector('[data-pe-menu]');
    if (!root) { return; }
    var toggle = root.querySelector('.pe-toggle');
    var menu = root.querySelector('.pe-menu');
    if (!toggle || !menu) { return; }

    function setOpen(open) {
        root.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        setOpen(!root.classList.contains('open'));
        if (root.classList.contains('open')) {
            var first = menu.querySelector('a');
            if (first) { first.focus(); }
        }
    });
    root.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && (root.classList.contains('open') || root.contains(document.activeElement))) {
            setOpen(false);
            root.classList.add('no-hover');
            toggle.focus();
        }
    });
    root.addEventListener('mouseleave', function () { root.classList.remove('no-hover'); });
    root.addEventListener('focusout', function (e) {
        if (!root.contains(e.relatedTarget)) { setOpen(false); root.classList.remove('no-hover'); }
    });
    document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) { setOpen(false); }
    });
})();
