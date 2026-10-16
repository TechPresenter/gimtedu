/* Top bar "Programs & Events" dropdown.
   Without JavaScript the panel opens on hover and keyboard focus (CSS). With JavaScript (.pe-js) it is shown only while
   the root has .open, so aria-expanded on the toggle always matches what is visible:
   - the toggle button opens / closes it (click, Enter, Space; opening with the keyboard moves focus to the first link);
   - a mouse pointer resting on the item opens it and leaving closes it (touch taps do not, so a tap on the toggle works);
     a click on the toggle while it is open from hovering keeps it open, the next click closes it;
   - Escape closes it and returns focus to the toggle; focus leaving the item or a click outside closes it.
   Its links are not in the Tab order while it is closed. */
(function () {
    'use strict';
    var root = document.querySelector('[data-pe-menu]');
    if (!root) { return; }
    var toggle = root.querySelector('.pe-toggle');
    var menu = root.querySelector('.pe-menu');
    if (!toggle || !menu) { return; }
    root.classList.add('pe-js');

    var openedBy = '';        // 'hover' | 'toggle' | ''
    var hoverBlocked = false; // closed while the pointer was on it: no hover re-open until it leaves
    var closeTimer = null;

    function isOpen() { return root.classList.contains('open'); }
    function setOpen(open, by) {
        if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
        root.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        openedBy = open ? (by || 'toggle') : '';
    }

    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        if (isOpen() && openedBy === 'hover') {
            openedBy = 'toggle';   // the mouse opened it on the way to the button: the click keeps it open
        } else if (isOpen()) {
            setOpen(false);
            hoverBlocked = true;
            return;
        } else {
            setOpen(true, 'toggle');
        }
        var first = menu.querySelector('a');
        if (first && e.detail === 0) { first.focus(); }   // keyboard activation (Enter / Space): go to the first link
    });
    root.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && (isOpen() || root.contains(document.activeElement))) {
            setOpen(false);
            hoverBlocked = true;
            toggle.focus();
        }
    });
    root.addEventListener('pointerenter', function (e) {
        if (e.pointerType !== 'mouse') { return; }
        if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
        if (!isOpen() && !hoverBlocked) { setOpen(true, 'hover'); }
    });
    root.addEventListener('pointerleave', function (e) {
        if (e.pointerType !== 'mouse') { return; }
        hoverBlocked = false;
        if (isOpen() && openedBy === 'hover' && !menu.contains(document.activeElement)) {
            closeTimer = setTimeout(function () { setOpen(false); }, 160);
        }
    });
    root.addEventListener('focusout', function (e) {
        // a click on plain text inside the panel moves focus to the page body: keep it open while the mouse is on it
        if (!root.contains(e.relatedTarget) && !(e.relatedTarget === null && root.matches(':hover'))) { setOpen(false); }
    });
    document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) { setOpen(false); }
    });
})();
