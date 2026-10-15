/* =====================================================================
   M5 - Accounts & member area: show/hide password, password strength meter,
   "passwords match" hint, avatar preview, dashboard tab bar on phones.
   (No inline scripts - loaded via $GLOBALS['extraScripts'].)
   ===================================================================== */
(function () {
    'use strict';

    /* ---------- Show / hide password ---------- */
    document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
        var input = document.getElementById(btn.getAttribute('data-pw-toggle'));
        if (!input) { return; }
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('is-on', show);
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus({ preventScroll: true });
        });
    });

    /* ---------- Password strength (guidance only - the server enforces the policy) ---------- */
    var labels = ['8+ characters with letters and numbers', 'Too weak', 'Fair', 'Good', 'Strong'];
    function score(v) {
        if (!v) { return 0; }
        var hasLetter = /[A-Za-z]/.test(v), hasDigit = /\d/.test(v);
        if (v.length < 8 || !hasLetter || !hasDigit) { return 1; }
        var s = 2;
        if (v.length >= 12) { s++; }
        if ((/[a-z]/.test(v) && /[A-Z]/.test(v)) || /[^A-Za-z0-9]/.test(v)) { s++; }
        if (/^(.)\1+$/.test(v) || /^(password|qwerty|12345678)/i.test(v)) { s = 1; }
        return Math.min(4, s);
    }
    document.querySelectorAll('input[data-strength]').forEach(function (input) {
        var meter = document.getElementById(input.getAttribute('data-strength'));
        if (!meter) { return; }
        var text = meter.querySelector('.acc-meter-text');
        var update = function () {
            var s = score(input.value);
            meter.setAttribute('data-level', String(s));
            if (text) { text.textContent = s === 0 ? labels[0] : 'Password strength: ' + labels[s] + (s === 1 ? ' - use 8+ characters with letters and numbers' : ''); }
        };
        input.addEventListener('input', update);
        update();
    });

    /* ---------- Confirm password must match ---------- */
    document.querySelectorAll('input[data-match-with]').forEach(function (input) {
        var other = document.getElementById(input.getAttribute('data-match-with'));
        var msg = document.querySelector('[data-match-msg="' + input.id + '"]');
        if (!other) { return; }
        var check = function () {
            var same = input.value === other.value;
            input.setCustomValidity(input.value !== '' && !same ? 'The two passwords do not match.' : '');
            if (msg) {
                msg.textContent = input.value === '' ? '' : (same ? 'Passwords match' : 'Passwords do not match yet');
                msg.className = 'acc-match' + (input.value === '' ? '' : (same ? ' is-ok' : ' is-bad'));
            }
        };
        input.addEventListener('input', check);
        other.addEventListener('input', function () { if (input.value !== '') { check(); } });
    });

    /* ---------- Profile photo preview ---------- */
    var avatarInput = document.getElementById('avatar');
    var avatarBox = document.querySelector('.md-avatar-lg');
    if (avatarInput && avatarBox && window.FileReader) {
        avatarInput.addEventListener('change', function () {
            var file = avatarInput.files && avatarInput.files[0];
            if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type) || file.size > 5 * 1024 * 1024) { return; }
            var reader = new FileReader();
            reader.onload = function () {
                var img = avatarBox.querySelector('img') || document.createElement('img');
                img.alt = '';
                img.src = reader.result;
                if (!img.parentNode) { avatarBox.textContent = ''; avatarBox.appendChild(img); }
                avatarBox.classList.add('has-photo');
            };
            reader.readAsDataURL(file);
        });
    }

    /* ---------- Dashboard tabs on phones: keep the active tab in view ---------- */
    var navList = document.querySelector('.md-nav ul');
    var activeTab = navList && navList.querySelector('a.active');
    if (navList && activeTab && navList.scrollWidth > navList.clientWidth) {
        var li = activeTab.parentNode;
        var left = li.getBoundingClientRect().left - navList.getBoundingClientRect().left + navList.scrollLeft;
        navList.scrollLeft = Math.max(0, left - (navList.clientWidth - li.offsetWidth) / 2);
    }
})();
