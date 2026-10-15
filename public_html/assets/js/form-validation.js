/* =====================================================================
   Client-side form validation (the server always validates again).
   Add data-validate to a <form> to enable it.
   ===================================================================== */
(function () {
    'use strict';

    var messages = {
        valueMissing: 'This field is required.',
        typeMismatch: { email: 'Please enter a valid email address.', url: 'Please enter a valid web address.' },
        tooShort: 'Please enter at least {min} characters.',
        tooLong: 'Please enter no more than {max} characters.',
        rangeUnderflow: 'The value must be at least {min}.',
        rangeOverflow: 'The value may not be more than {max}.',
        patternMismatch: 'Please match the requested format.'
    };

    function errorBox(field) {
        var group = field.closest('.form-group') || field.parentNode;
        var box = group.querySelector('.field-error.js-error');
        if (!box) {
            box = document.createElement('span');
            box.className = 'field-error js-error';
            box.setAttribute('aria-live', 'polite');
            group.appendChild(box);
        }
        return box;
    }

    function clearError(field) {
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
        var group = field.closest('.form-group') || field.parentNode;
        group.querySelectorAll('.field-error').forEach(function (el) { el.remove(); });
    }

    function customCheck(field) {
        var v = (field.value || '').trim();
        if (field.dataset.match) {
            var other = field.form.querySelector('[name="' + field.dataset.match + '"]');
            if (other && v !== other.value.trim()) { return 'The values do not match.'; }
        }
        if (field.type === 'tel' && v !== '') {
            var digits = v.replace(/[\s\-()]/g, '');
            if (!/^(\+91|91|0)?[6-9]\d{9}$/.test(digits) && !/^\+?\d{8,15}$/.test(digits)) {
                return 'Please enter a valid mobile number.';
            }
        }
        if (field.dataset.rule === 'password' && v !== '') {
            if (v.length < 8 || !/[A-Za-z]/.test(v) || !/\d/.test(v)) {
                return 'Use at least 8 characters with letters and numbers.';
            }
        }
        if (field.dataset.rule === 'pan' && v !== '' && !/^[A-Z]{5}\d{4}[A-Z]$/.test(v.toUpperCase())) {
            return 'Please enter a valid PAN (e.g. ABCDE1234F).';
        }
        if (field.dataset.rule === 'pincode' && v !== '' && !/^[1-9]\d{5}$/.test(v)) {
            return 'Please enter a valid 6-digit PIN code.';
        }
        if (field.type === 'file' && field.files && field.files[0]) {
            var maxMb = parseFloat(field.dataset.maxMb || '5');
            if (field.files[0].size > maxMb * 1024 * 1024) { return 'The file is too large. Maximum size is ' + maxMb + ' MB.'; }
            var accept = (field.getAttribute('accept') || '').toLowerCase();
            if (accept) {
                var name = field.files[0].name.toLowerCase();
                var ok = accept.split(',').some(function (ext) { ext = ext.trim(); return ext.charAt(0) === '.' ? name.slice(-ext.length) === ext : true; });
                if (!ok) { return 'This file type is not allowed.'; }
            }
        }
        if (field.dataset.groupRequired) {
            var checked = field.form.querySelectorAll('[name="' + field.name + '"]:checked').length;
            if (!checked) { return 'Please choose at least one option.'; }
        }
        return '';
    }

    function validateField(field) {
        if (field.disabled || field.type === 'hidden' || field.closest('.hp-field')) { return true; }
        clearError(field);
        var msg = '';
        var vs = field.validity;
        if (vs && !vs.valid) {
            if (vs.valueMissing) {
                msg = field.type === 'checkbox' ? 'Please tick this box to continue.' : messages.valueMissing;
            } else if (vs.typeMismatch) {
                msg = messages.typeMismatch[field.type] || 'Please enter a valid value.';
            } else if (vs.tooShort) {
                msg = messages.tooShort.replace('{min}', field.minLength);
            } else if (vs.tooLong) {
                msg = messages.tooLong.replace('{max}', field.maxLength);
            } else if (vs.rangeUnderflow) {
                msg = messages.rangeUnderflow.replace('{min}', field.min);
            } else if (vs.rangeOverflow) {
                msg = messages.rangeOverflow.replace('{max}', field.max);
            } else if (vs.patternMismatch) {
                msg = field.title || messages.patternMismatch;
            } else {
                msg = field.validationMessage;
            }
        }
        if (!msg) { msg = customCheck(field); }
        if (msg) {
            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');
            errorBox(field).textContent = msg;
            return false;
        }
        return true;
    }

    document.querySelectorAll('form[data-validate]').forEach(function (form) {
        form.setAttribute('novalidate', 'novalidate');

        form.addEventListener('submit', function (e) {
            var firstInvalid = null;
            var seenGroups = {};
            form.querySelectorAll('input, select, textarea').forEach(function (field) {
                if (field.dataset.groupRequired) {
                    if (seenGroups[field.name]) { return; }
                    seenGroups[field.name] = true;
                }
                if (!validateField(field) && !firstInvalid) { firstInvalid = field; }
            });
            if (firstInvalid) {
                e.preventDefault();
                firstInvalid.focus({ preventScroll: true });
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            // prevent double submissions
            var btn = form.querySelector('[type="submit"]');
            if (btn && !form.hasAttribute('data-allow-resubmit')) {
                setTimeout(function () {
                    btn.disabled = true;
                    btn.classList.add('is-loading');
                    if (btn.dataset.loading) { btn.textContent = btn.dataset.loading; }
                }, 0);
            }
        });

        form.querySelectorAll('input, select, textarea').forEach(function (field) {
            var evt = (field.type === 'checkbox' || field.type === 'radio' || field.tagName === 'SELECT' || field.type === 'file') ? 'change' : 'blur';
            field.addEventListener(evt, function () {
                if (field.value !== '' || field.classList.contains('is-invalid')) { validateField(field); }
            });
            field.addEventListener('input', function () {
                if (field.classList.contains('is-invalid')) { validateField(field); }
            });
        });
    });

    /* Uppercase PAN / IFSC inputs as the user types */
    document.querySelectorAll('[data-uppercase]').forEach(function (field) {
        field.addEventListener('input', function () {
            var pos = field.selectionStart;
            field.value = field.value.toUpperCase();
            if (pos !== null) { field.setSelectionRange(pos, pos); }
        });
    });

    /* Show / hide password */
    document.querySelectorAll('.password-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = btn.parentNode.querySelector('input');
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.textContent = show ? 'Hide' : 'Show';
        });
    });

    /* Character counter for textareas with maxlength */
    document.querySelectorAll('textarea[maxlength][data-counter]').forEach(function (ta) {
        var counter = document.createElement('span');
        counter.className = 'help';
        ta.parentNode.appendChild(counter);
        var update = function () { counter.textContent = ta.value.length + ' / ' + ta.maxLength + ' characters'; };
        ta.addEventListener('input', update);
        update();
    });
})();
