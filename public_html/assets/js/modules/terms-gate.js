/* Terms gate: a form wrapped in [data-terms-gate="<dialog id>"] stays locked until the visitor presses
   "I accept" in the terms pop-up. Without JavaScript the required checkbox inside the form does the same job,
   and the server always checks it. */
(function () {
    'use strict';
    document.querySelectorAll('[data-terms-gate]').forEach(function (gate) {
        var dlg = document.getElementById(gate.getAttribute('data-terms-gate'));
        var form = gate.querySelector('form');
        var box = gate.querySelector('[data-terms-checkbox]');
        var bar = gate.querySelector('[data-terms-bar]');
        var accept = dlg ? dlg.querySelector('[data-terms-accept]') : null;
        if (!dlg || !form || !box || !accept) { return; }
        var justAccepted = false;

        function lock(on) {
            if (on) { form.setAttribute('inert', ''); } else { form.removeAttribute('inert'); }
            form.classList.toggle('is-locked', on);
            form.setAttribute('aria-hidden', on ? 'true' : 'false');
            if (bar) { bar.hidden = !on; }
        }
        function open() {
            if (dlg.open) { return; }
            if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
            document.body.classList.add('no-scroll');
        }

        if (!box.checked) {
            lock(true);
            window.setTimeout(open, 350);
        }
        accept.addEventListener('click', function () {
            box.checked = true;
            justAccepted = true;
            lock(false);
            if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.removeAttribute('open'); }
        });
        dlg.addEventListener('close', function () {
            if (!justAccepted) { return; }
            justAccepted = false;
            var first = form.querySelector('input:not([type="hidden"]):not([tabindex="-1"]), select, textarea');
            if (first) { first.focus(); }
        });
    });
})();
