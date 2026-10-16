/* Terms gate: a form wrapped in [data-terms-gate="<dialog id>"] stays locked until the visitor presses
   "I accept" in the terms pop-up. Without JavaScript the terms are shown in the form itself ([data-terms-inline])
   and the required checkbox does the same job; the server always checks it. */
(function () {
    'use strict';
    document.querySelectorAll('[data-terms-gate]').forEach(function (gate) {
        var dlg = document.getElementById(gate.getAttribute('data-terms-gate'));
        var form = gate.querySelector('form');
        var box = gate.querySelector('[data-terms-checkbox]');
        var bar = gate.querySelector('[data-terms-bar]');
        var barButton = bar ? bar.querySelector('button, a') : null;
        var inline = gate.querySelector('[data-terms-inline]');
        var accept = dlg ? dlg.querySelector('[data-terms-accept]') : null;
        if (!dlg || !form || !box || !accept) { return; }
        if (inline) { inline.hidden = true; }   // the pop-up shows the same text
        var justAccepted = false;
        var wasLocked = false;

        function locked() { return form.classList.contains('is-locked'); }
        function lock(on) {
            if (on) { form.setAttribute('inert', ''); } else { form.removeAttribute('inert'); }
            form.classList.toggle('is-locked', on);
            form.setAttribute('aria-hidden', on ? 'true' : 'false');
            if (bar) { bar.hidden = !on; }
        }
        function open() {
            if (dlg.open) { return; }
            // opened automatically: when it is closed without accepting, focus goes to the "Read & accept" button
            dlg.__opener = barButton || null;
            if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
            document.body.classList.add('no-scroll');
        }

        if (!box.checked) {
            lock(true);
            window.setTimeout(open, 350);
        }
        accept.addEventListener('click', function () {
            wasLocked = locked();
            box.checked = true;
            justAccepted = true;
            lock(false);
            if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.removeAttribute('open'); }
        });
        // runs after script.js has returned focus to the element that opened the pop-up
        dlg.addEventListener('close', function () {
            if (!justAccepted) {
                if (locked() && barButton && (document.activeElement === document.body || !document.activeElement)) { barButton.focus(); }
                return;
            }
            justAccepted = false;
            if (!wasLocked) { return; }   // re-read from the checkbox label: stay where the visitor was
            var first = form.querySelector('input:not([type="hidden"]):not([tabindex="-1"]), select, textarea');
            if (first) { first.focus(); }
        });
    });
})();
