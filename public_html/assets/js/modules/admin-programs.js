/* =====================================================================
   Prerit Welfare Foundation - Programs & Events admin (P2)
   Show/hide form panels by type, mode and status; category -> default type;
   bulk-selection counter; "use attended count"; guardian fields.
   Every form works without JavaScript (all panels are then visible).
   ===================================================================== */
(function () {
    'use strict';

    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    /* ---------- Conditional panels: data-ph-when="field=value1|value2" / data-ph-unless="field=value" ---------- */
    function fieldValue(form, name) {
        var els = form.querySelectorAll('[name="' + name + '"]');
        if (!els.length) { return null; }
        var el = els[els.length - 1];
        if (el.type === 'checkbox') {
            var box = form.querySelector('input[type="checkbox"][name="' + name + '"]');
            return box && box.checked ? '1' : '0';
        }
        if (el.type === 'radio') {
            var r = form.querySelector('input[type="radio"][name="' + name + '"]:checked');
            return r ? r.value : '';
        }
        return el.value;
    }
    function test(form, rule) {
        var p = rule.split('=');
        var v = fieldValue(form, p[0]);
        if (v === null) { return true; }
        return p[1].split('|').indexOf(v) !== -1;
    }
    function sync(form) {
        qsa('[data-ph-when], [data-ph-unless]', form).forEach(function (el) {
            var show = true;
            if (el.hasAttribute('data-ph-when')) {
                show = el.getAttribute('data-ph-when').split('&').every(function (r) { return test(form, r); });
            }
            if (show && el.hasAttribute('data-ph-unless')) {
                show = !el.getAttribute('data-ph-unless').split('&').some(function (r) { return test(form, r); });
            }
            el.hidden = !show;
        });
    }
    qsa('form[data-ph-form]').forEach(function (form) {
        form.addEventListener('change', function () { sync(form); });
        sync(form);
    });

    /* ---------- New event: the category pre-selects its usual event type until the type is chosen by hand ---------- */
    var cat = document.getElementById('category_id');
    var type = document.getElementById('event_type');
    if (cat && type && type.hasAttribute('data-follow-category')) {
        type.addEventListener('change', function () { type.removeAttribute('data-follow-category'); });
        cat.addEventListener('change', function () {
            if (!type.hasAttribute('data-follow-category')) { return; }
            var opt = cat.options[cat.selectedIndex];
            var t = opt ? opt.getAttribute('data-type') : '';
            if (t && type.querySelector('option[value="' + t + '"]')) {
                type.value = t;
                type.dispatchEvent(new Event('change', { bubbles: true }));
                type.setAttribute('data-follow-category', '1');
            }
        });
    }

    /* ---------- Bulk selection: "N selected" + enable the bulk button ---------- */
    qsa('[data-ph-bulk]').forEach(function (bar) {
        var sel = bar.getAttribute('data-ph-bulk');
        var out = bar.querySelector('[data-ph-count]');
        var btn = bar.querySelector('[data-ph-bulk-submit]');
        function update() {
            var n = qsa(sel).filter(function (c) { return c.checked; }).length;
            if (out) { out.textContent = n === 1 ? '1 selected' : n + ' selected'; }
            if (btn) { btn.disabled = n === 0; }
        }
        document.addEventListener('change', function (e) {
            if (e.target.matches && (e.target.matches(sel) || e.target.hasAttribute('data-check-all'))) { setTimeout(update, 0); }
        });
        update();
    });

    /* ---------- Bulk action: ask before cancelling / emailing ---------- */
    qsa('form[data-ph-bulk-form]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var action = form.querySelector('[name="bulk_action"]');
            var opt = action && action.options[action.selectedIndex];
            var msg = opt ? opt.getAttribute('data-confirm-msg') : '';
            if (msg && !window.confirm(msg)) { e.preventDefault(); }
        });
    });

    /* ---------- Fill a number field from a button (e.g. "use the attended count") ---------- */
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-ph-fill]') : null;
        if (!b) { return; }
        var target = document.querySelector(b.getAttribute('data-ph-fill'));
        if (target) {
            target.value = b.getAttribute('data-value') || '';
            target.dispatchEvent(new Event('input', { bubbles: true }));
            target.focus();
        }
    });
})();
