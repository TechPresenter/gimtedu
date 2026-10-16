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

    function setDescribedBy(el, id, add) {
        var ids = (el.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (x) { return x && x !== id; });
        if (add) { ids.push(id); }
        if (ids.length) { el.setAttribute('aria-describedby', ids.join(' ')); } else { el.removeAttribute('aria-describedby'); }
    }

    /* The element that "owns" the message: a checkbox group's [role=group] wrapper, otherwise the field itself. */
    function errorOwner(field) {
        if (field.dataset.groupRequired) {
            return field.closest('[role="group"]') || field.closest('.form-group') || field;
        }
        return field;
    }

    var errorSeq = 0;
    function errorBox(field) {
        var group = field.closest('.form-group') || field.parentNode;
        var box = group.querySelector('.field-error.js-error');
        if (!box) {
            box = document.createElement('span');
            box.className = 'field-error js-error';
            // no aria-live: the message is read with the field through aria-describedby when focus moves there
            var base = (field.id || (field.name || 'field').replace(/\W/g, '')) + '-error';
            box.id = document.getElementById(base) ? base + '-' + (++errorSeq) : base;
            group.appendChild(box);
        }
        setDescribedBy(errorOwner(field), box.id, true);
        return box;
    }

    function clearError(field) {
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
        var group = field.closest('.form-group') || field.parentNode;
        var owner = errorOwner(field);
        group.querySelectorAll('.field-error').forEach(function (el) {
            if (el.id) { setDescribedBy(owner, el.id, false); if (owner !== field) { setDescribedBy(field, el.id, false); } }
            el.remove();
        });
        if (field.dataset.groupRequired && field.form) {
            field.form.querySelectorAll('[name="' + field.name + '"]').forEach(function (cb) { cb.classList.remove('is-invalid'); cb.removeAttribute('aria-invalid'); });
        }
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
            // no usable limit in the page (0, empty or not a number): the server still checks the size
            if (maxMb > 0 && field.files[0].size > maxMb * 1024 * 1024) { return 'The file is too large. Maximum size is ' + maxMb + ' MB.'; }
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
            if (field.dataset.groupRequired && field.form) {
                field.form.querySelectorAll('[name="' + field.name + '"]').forEach(function (cb) { cb.classList.add('is-invalid'); cb.setAttribute('aria-invalid', 'true'); });
            }
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

    /* ---------- Server-side validation errors: link each message to its field ---------- */
    // field_error() prints <span class="field-error" id="err-..."> after the field; screen readers then read the
    // reason with the field (aria-describedby) and the field is marked invalid. Runs once per page.
    function pwfAddDescribedBy(el, id) {
        var ids = (el.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (ids.indexOf(id) === -1) { ids.push(id); }
        el.setAttribute('aria-describedby', ids.join(' '));
    }
    function pwfErrorTarget(err) {
        var sib = err.previousElementSibling;
        while (sib) {
            if (sib.matches('input, select, textarea, [role="group"]')) { return sib; }
            var inner = sib.querySelectorAll('input:not([type="hidden"]), select, textarea');
            if (inner.length === 1) { return inner[0]; }
            if (inner.length > 1) { return sib.querySelector('[role="group"]') || (sib.matches('fieldset') ? sib : null) || sib; }
            sib = sib.previousElementSibling;
        }
        var group = err.closest('.form-group');
        return group ? (group.querySelector('[role="group"]') || group.querySelector('input:not([type="hidden"]), select, textarea')) : null;
    }
    function pwfWireServerErrors() {
        if (window.__pwfServerErrorsWired) { return; }
        window.__pwfServerErrorsWired = true;
        var first = null;
        Array.prototype.forEach.call(document.querySelectorAll('.field-error:not(.js-error)'), function (err, i) {
            if (!err.textContent.trim()) { return; }
            var target = pwfErrorTarget(err);
            if (!target) { return; }
            if (!err.id || document.getElementById(err.id) !== err) { err.id = 'srv-err-' + i; }
            pwfAddDescribedBy(target, err.id);
            var controls = target.matches('input, select, textarea') ? [target] : Array.prototype.slice.call(target.querySelectorAll('input:not([type="hidden"]), select, textarea'));
            controls.forEach(function (c) { c.setAttribute('aria-invalid', 'true'); c.classList.add('is-invalid'); });
            if (!first && controls[0]) { first = controls[0]; }
        });
        if (first && typeof first.focus === 'function') {
            first.focus({ preventScroll: true });
            first.scrollIntoView({ block: 'center' });
        }
    }
    pwfWireServerErrors();

    /* Uppercase PAN / IFSC inputs as the user types */
    document.querySelectorAll('[data-uppercase]').forEach(function (field) {
        field.addEventListener('input', function () {
            var pos = field.selectionStart;
            field.value = field.value.toUpperCase();
            if (pos !== null) { field.setSelectionRange(pos, pos); }
        });
    });

    /* Show / hide password (the eye icon stays; .is-on draws the slash) */
    document.querySelectorAll('.password-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = btn.parentNode.querySelector('input');
            if (!input) { return; }
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.classList.toggle('is-on', show);
        });
    });

    /* Character counter for textareas with maxlength */
    document.querySelectorAll('textarea[maxlength][data-counter]').forEach(function (ta) {
        if (ta.getAttribute('data-counter')) { return; } // valued counters (SEO targets) are rendered by modules/admin-site.js
        var counter = document.createElement('span');
        counter.className = 'help';
        ta.parentNode.appendChild(counter);
        var update = function () { counter.textContent = ta.value.length + ' / ' + ta.maxLength + ' characters'; };
        ta.addEventListener('input', update);
        update();
    });
})();
