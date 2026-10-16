/* =====================================================================
   Programs & Events (public): registration form helpers and the hub
   filter announcement. Loaded only on pages that need it.
   Everything works without JavaScript - this only hides fields that do
   not apply and keeps labels in sync.
   ===================================================================== */
(function () {
    'use strict';

    /* ---------- Registration forms ---------- */
    function setBlock(block, on) {
        if (!block) { return; }
        block.hidden = !on;
        block.querySelectorAll('input, select, textarea').forEach(function (f) {
            f.disabled = !on;
            if (f.hasAttribute('data-req')) {
                if (on) { f.setAttribute('required', ''); } else { f.removeAttribute('required'); }
            }
        });
    }

    document.querySelectorAll('form[data-ph-reg]').forEach(function (form) {
        var minor = form.querySelector('input[name="is_minor"]');
        var guardian = form.querySelector('[data-ph-guardian]');
        var team = form.querySelector('[data-ph-team]');
        var submit = form.querySelector('[data-ph-submit] span');

        function selected() {
            return form.querySelector('input[name="event_id"]:checked') || form.querySelector('input[type="hidden"][name="event_id"]');
        }

        function update() {
            setBlock(guardian, !!(minor && minor.checked));
            var ev = selected();
            if (team) {
                var isTeam = !!ev && ev.getAttribute('data-team') === '1';
                setBlock(team, isTeam);
                var sizeGroup = team.querySelector('[data-ph-size]');
                if (sizeGroup && isTeam) {
                    var max = parseInt(ev.getAttribute('data-tmax') || '0', 10);
                    var min = parseInt(ev.getAttribute('data-tmin') || '1', 10);
                    setBlock(sizeGroup, max > 0);
                    var size = sizeGroup.querySelector('input');
                    if (size && max > 0) {
                        size.min = String(min);
                        size.max = String(max);
                    }
                }
            }
            if (submit && ev) {
                submit.textContent = ev.getAttribute('data-waitlist') === '1' ? 'Join the waiting list' : 'Register now';
            }
        }

        form.addEventListener('change', function (e) {
            if (e.target && (e.target.name === 'is_minor' || e.target.name === 'event_id')) { update(); }
        });
        update();
    });

    /* ---------- Hub category filter: announce the result (script.js does the filtering) ---------- */
    document.querySelectorAll('.pc-filter').forEach(function (bar) {
        var section = bar.closest('section') || document;
        var live = section.querySelector('[data-pc-live]');
        bar.addEventListener('click', function (e) {
            var btn = e.target.closest('.filter-btn');
            if (!btn || !live) { return; }
            window.setTimeout(function () {
                var shown = section.querySelectorAll('.pc-grid [data-category]:not([hidden])').length;
                live.textContent = 'Showing ' + shown + (shown === 1 ? ' programme area' : ' programme areas') + '.';
            }, 30);
        });
    });
})();
