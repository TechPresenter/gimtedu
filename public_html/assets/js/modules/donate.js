/* Donate page (M3): keep every amount / cause preview in sync with the form. */
(function () {
    'use strict';
    var amount = document.getElementById('amount');
    var cause = document.getElementById('campaign_id');
    function fmt(v) {
        var n = Math.round(parseFloat(v));
        return n > 0 ? '₹' + n.toLocaleString('en-IN') : '₹0';
    }
    function sync() {
        if (amount) {
            document.querySelectorAll('[data-amount-preview]').forEach(function (el) { el.textContent = fmt(amount.value); });
        }
        if (cause) {
            var opt = cause.options[cause.selectedIndex];
            var label = opt && opt.value ? opt.text : 'Where it is needed most';
            document.querySelectorAll('[data-cause-preview]').forEach(function (el) { el.textContent = label; });
        }
    }
    if (amount) {
        amount.addEventListener('input', sync);
        document.querySelectorAll('input[name="preset_amount"]').forEach(function (r) { r.addEventListener('change', function () { setTimeout(sync, 0); }); });
    }
    if (cause) { cause.addEventListener('change', sync); }
    sync();
})();
