/* Donate page (M3 / v3 payments):
 *  - keep every amount / cause preview in sync with the form;
 *  - "Pay with" choice: a 10-digit Indian mobile number is required when Cashfree is chosen;
 *  - Cashfree payment step: open the Cashfree checkout (JS SDK v3, modal) and, when it closes, ask our server to
 *    verify the order with the Cashfree API (the browser result itself is never trusted). */
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

    /* ---------- Gateway choice ---------- */
    var phone = document.getElementById('donor_phone');
    var gatewayRadios = document.querySelectorAll('input[name="gateway"][type="radio"]');
    function syncGateway() {
        if (!phone || !phone.getAttribute('data-cf-pattern')) { return; }
        var chosen = document.querySelector('input[name="gateway"]:checked');
        if (chosen && chosen.value === 'cashfree') {
            phone.setAttribute('pattern', phone.getAttribute('data-cf-pattern'));
            phone.setAttribute('title', 'Enter a 10-digit Indian mobile number, e.g. 98765 43210');
        } else {
            phone.removeAttribute('pattern');
            phone.removeAttribute('title');
        }
    }
    gatewayRadios.forEach(function (r) { r.addEventListener('change', syncGateway); });
    if (gatewayRadios.length) { syncGateway(); }

    /* ---------- Cashfree checkout (payment step) ---------- */
    var cfBtn = document.getElementById('cf-pay');
    if (!cfBtn) { return; }
    var verifyForm = document.getElementById('cf-verify-form');
    var status = document.getElementById('cf-status');
    var label = cfBtn.innerHTML;
    var opening = false;
    function say(text) { if (status) { status.textContent = text; } }
    function verify(text) {
        cfBtn.disabled = true;
        cfBtn.textContent = 'Verifying payment…';
        say(text || 'Checking the payment with Cashfree…');
        verifyForm.submit();
    }
    function openCheckout() {
        if (opening) { return; }
        if (typeof window.Cashfree !== 'function') {
            say('The payment window could not be loaded. Please check your internet connection and try again.');
            return;
        }
        opening = true;
        cfBtn.disabled = true;
        say('Opening the secure Cashfree payment window…');
        var cashfree;
        try {
            cashfree = window.Cashfree({ mode: cfBtn.getAttribute('data-mode') === 'production' ? 'production' : 'sandbox' });
        } catch (e) {
            opening = false;
            cfBtn.disabled = false;
            say('The payment window could not be opened. Please try again.');
            return;
        }
        var result = cashfree.checkout({ paymentSessionId: cfBtn.getAttribute('data-session'), redirectTarget: '_modal' });
        if (!result || typeof result.then !== 'function') {
            return;   // the SDK redirects the page itself
        }
        result.then(function (res) {
            res = res || {};
            if (res.redirect) {
                say('Redirecting to complete the payment…');
                return;   // Cashfree returns to /donate/?cf_order=... afterwards
            }
            // Payment finished (any outcome) or the window was closed: our server asks Cashfree what happened.
            verify(res.error ? 'Checking whether a payment was made…' : 'Confirming your payment with Cashfree…');
        }, function () {
            verify('Checking whether a payment was made…');
        });
    }
    cfBtn.addEventListener('click', openCheckout);
    if (cfBtn.hasAttribute('data-autoopen')) {
        window.addEventListener('load', function () { setTimeout(openCheckout, 400); });
    }
    // Restore the button if the page is shown again from the back/forward cache.
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { opening = false; cfBtn.disabled = false; cfBtn.innerHTML = label; say(''); }
    });
})();
