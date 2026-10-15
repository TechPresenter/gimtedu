/* =====================================================================
   Prerit Welfare Foundation - site management (M1) admin interactions
   Repeaters (add / remove / move), image fields with media-library picker,
   icon previews, character counters and the fraud alert preview.
   Every form also works without JavaScript (blank rows, remove tick boxes,
   image path boxes and the JSON fallback).
   ===================================================================== */
(function () {
    'use strict';

    var root = document.documentElement;
    root.classList.add('js');

    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    /* ---------- Icon preview ---------- */
    var sprite = document.getElementById('site-icon-sprite');
    function iconMarkup(name) {
        if (!sprite || !name) { return ''; }
        var el = sprite.content.querySelector('[data-icon="' + name.replace(/[^a-z0-9\-]/g, '') + '"]');
        return el ? el.innerHTML : '';
    }
    function initIconPreview(ctx) {
        qsa('.site-icon-select', ctx).forEach(function (sel) {
            if (sel.dataset.bound) { return; }
            sel.dataset.bound = '1';
            sel.addEventListener('change', function () {
                var prev = sel.parentNode.querySelector('.site-icon-preview');
                if (prev) { prev.innerHTML = iconMarkup(sel.value); }
            });
        });
    }

    /* ---------- Image fields ---------- */
    var picker = document.getElementById('site-media-picker');
    var pickTarget = null;

    function setImage(field, path, src) {
        var input = field.querySelector('[data-image-path]');
        var img = field.querySelector('[data-image-preview]');
        var file = field.querySelector('[data-image-file]');
        if (input) { input.value = path; input.dispatchEvent(new Event('change', { bubbles: true })); }
        if (file) { file.value = ''; }
        if (img) {
            if (src) { img.src = src; img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; }
        }
    }
    function initImageFields(ctx) {
        qsa('[data-image-field]', ctx).forEach(function (field) {
            if (field.dataset.bound) { return; }
            field.dataset.bound = '1';
            qsa('[data-media-pick], [data-image-clear]', field).forEach(function (b) { b.hidden = false; });
            if (!picker) { qsa('[data-media-pick]', field).forEach(function (b) { b.hidden = true; }); }
            var file = field.querySelector('[data-image-file]');
            if (file) {
                file.addEventListener('change', function () {
                    var img = field.querySelector('[data-image-preview]');
                    if (img && file.files && file.files[0] && /^image\//.test(file.files[0].type) && window.FileReader) {
                        // data: URL (the Content Security Policy does not allow blob: images)
                        var reader = new FileReader();
                        reader.onload = function () { img.src = reader.result; img.hidden = false; };
                        reader.readAsDataURL(file.files[0]);
                    }
                });
            }
        });
    }
    document.addEventListener('click', function (e) {
        var pick = e.target.closest('[data-media-pick]');
        if (pick && picker) {
            pickTarget = pick.closest('[data-image-field]');
            if (typeof picker.showModal === 'function') { picker.showModal(); } else { picker.setAttribute('open', ''); }
            var s = picker.querySelector('[data-media-search]');
            if (s) { s.focus(); }
            return;
        }
        var clear = e.target.closest('[data-image-clear]');
        if (clear) {
            setImage(clear.closest('[data-image-field]'), '', '');
            return;
        }
        var choose = e.target.closest('[data-media-choose]');
        if (choose && pickTarget) {
            setImage(pickTarget, choose.getAttribute('data-media-choose'), choose.getAttribute('data-url'));
            closeDialog(picker);
            pickTarget = null;
            return;
        }
        var close = e.target.closest('[data-dialog-close]');
        if (close) { closeDialog(close.closest('dialog')); }
    });
    function closeDialog(d) {
        if (!d) { return; }
        if (typeof d.close === 'function') { d.close(); } else { d.removeAttribute('open'); }
    }
    qsa('dialog.site-dialog').forEach(function (d) {
        d.addEventListener('click', function (e) { if (e.target === d) { closeDialog(d); } });
    });
    if (picker) {
        var search = picker.querySelector('[data-media-search]');
        if (search) {
            search.addEventListener('input', function () {
                var q = search.value.trim().toLowerCase();
                qsa('[data-media-item]', picker).forEach(function (li) {
                    li.hidden = q !== '' && (li.getAttribute('data-search') || '').indexOf(q) === -1;
                });
            });
        }
    }

    /* ---------- Repeaters ---------- */
    function renumber(rep) {
        var label = rep.getAttribute('data-label') || 'Item';
        qsa(':scope > [data-rows] > [data-row]', rep).forEach(function (row, i, all) {
            var num = row.querySelector('[data-row-num]');
            if (num) { num.textContent = label + ' ' + (i + 1); }
            var up = row.querySelector('[data-row-up]');
            var down = row.querySelector('[data-row-down]');
            if (up) { up.disabled = i === 0; }
            if (down) { down.disabled = i === all.length - 1; }
        });
    }
    function initRepeaters(ctx) {
        qsa('[data-repeater]', ctx).forEach(function (rep) {
            if (rep.dataset.bound) { return; }
            rep.dataset.bound = '1';
            // With JavaScript the empty "new entry" rows are not needed: the Add button creates rows.
            qsa(':scope > [data-rows] > [data-row][data-blank]', rep).forEach(function (row) { row.remove(); });
            qsa(':scope > [data-rows] > [data-row] [data-row-tools]', rep).forEach(function (t) { t.hidden = false; });
            var add = rep.querySelector(':scope > [data-row-add]');
            if (add) { add.hidden = false; }
            renumber(rep);
        });
    }
    document.addEventListener('click', function (e) {
        var add = e.target.closest('[data-row-add]');
        if (add) {
            var rep = add.closest('[data-repeater]');
            var tpl = rep.querySelector(':scope > template[data-row-template]');
            var list = rep.querySelector(':scope > [data-rows]');
            var next = parseInt(add.getAttribute('data-next') || '0', 10);
            // make sure the index is not used yet (rows may have been posted with gaps)
            while (list.querySelector('[name*="[' + next + ']"]')) { next++; }
            add.setAttribute('data-next', String(next + 1));
            var wrap = document.createElement('div');
            wrap.innerHTML = tpl.innerHTML.replace(/__i__/g, String(next));
            var row = wrap.firstElementChild;
            list.appendChild(row);
            qsa('[data-row-tools]', row).forEach(function (t) { t.hidden = false; });
            initImageFields(row);
            initIconPreview(row);
            initEditors(row);
            renumber(rep);
            var first = row.querySelector('input:not([type="hidden"]):not([type="checkbox"]), textarea, select');
            if (first) { first.focus(); }
            return;
        }
        var btn = e.target.closest('[data-row-remove], [data-row-up], [data-row-down]');
        if (!btn) { return; }
        var row2 = btn.closest('[data-row]');
        var rep2 = row2.closest('[data-repeater]');
        if (btn.hasAttribute('data-row-remove')) {
            var hasText = qsa('input[type="text"], textarea', row2).some(function (i) { return i.value.trim() !== ''; });
            if (hasText && !window.confirm('Remove this entry?')) { return; }
            var focusTo = row2.nextElementSibling || row2.previousElementSibling;
            row2.remove();
            if (focusTo) { var b = focusTo.querySelector('[data-row-remove]'); if (b) { b.focus(); } }
        } else if (btn.hasAttribute('data-row-up') && row2.previousElementSibling) {
            row2.parentNode.insertBefore(row2, row2.previousElementSibling);
            btn.focus();
        } else if (btn.hasAttribute('data-row-down') && row2.nextElementSibling) {
            row2.parentNode.insertBefore(row2.nextElementSibling, row2);
            btn.focus();
        }
        renumber(rep2);
    });

    /* ---------- Rich-text editor toolbar for rows added later ---------- */
    function initEditors(ctx) {
        // admin.js adds the toolbar to editors present at page load; rows added later get a plain textarea.
        qsa('textarea[data-editor]', ctx).forEach(function (ta) { ta.removeAttribute('data-editor'); });
    }

    /* ---------- Character counters ---------- */
    qsa('[data-counter]').forEach(function (input) {
        var max = parseInt(input.getAttribute('data-counter'), 10);
        var out = document.createElement('span');
        out.className = 'site-counter';
        out.setAttribute('aria-live', 'polite');
        input.insertAdjacentElement('afterend', out);
        function update() {
            var n = input.value.length;
            out.textContent = n + ' / ' + max + ' characters' + (n > max ? ' - too long, search engines will cut it off' : '');
            out.classList.toggle('is-over', n > max);
            out.classList.toggle('is-ok', n > 0 && n <= max);
        }
        input.addEventListener('input', update);
        update();
    });

    /* ---------- Fraud alert preview (uses the current, unsaved form values) ---------- */
    qsa('[data-fraud-preview]').forEach(function (btn) {
        btn.hidden = false;
        btn.addEventListener('click', function () {
            var dlg = document.getElementById(btn.getAttribute('data-fraud-preview'));
            if (!dlg) { return; }
            function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }
            function setText(sel, text) { qsa(sel, dlg).forEach(function (el) { el.textContent = text; }); }
            setText('[data-fa="title"]', val('set-fraud_alert_title') || 'Beware of fraud');
            setText('[data-fa="message"]', val('set-fraud_alert_message'));
            setText('[data-fa="title_hi"]', val('set-fraud_alert_title_hi'));
            setText('[data-fa="message_hi"]', val('set-fraud_alert_message_hi'));
            setText('[data-fa="report"]', val('set-fraud_alert_report_text'));
            setText('[data-fa="note"]', val('set-fraud_alert_verified_note'));
            var hi = dlg.querySelector('[data-fa-block="hi"]');
            if (hi) { hi.hidden = val('set-fraud_alert_message_hi').trim() === ''; }
            var note = dlg.querySelector('[data-fa-block="note"]');
            if (note) { note.hidden = val('set-fraud_alert_verified_note').trim() === ''; }
            var list = dlg.querySelector('[data-fa="points"]');
            var tpl = dlg.querySelector('template[data-fa-point]');
            if (list && tpl) {
                list.innerHTML = '';
                val('set-fraud_alert_points').split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (p) {
                    var w = document.createElement('ul');
                    w.innerHTML = tpl.innerHTML;
                    var li = w.firstElementChild;
                    li.querySelector('span').textContent = p;
                    list.appendChild(li);
                });
                list.hidden = list.children.length === 0;
            }
            if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
        });
    });
    document.addEventListener('click', function (e) {
        var c = e.target.closest('[data-modal-close]');
        if (c) { closeDialog(c.closest('dialog')); }
    });

    /* ---------- Fraud alert: switching it on requires the confirmation box ---------- */
    var faOn = document.getElementById('set-fraud_alert_enabled');
    var faConfirm = document.getElementById('fraud_confirm');
    if (faOn && faConfirm) {
        var sync = function () {
            faConfirm.required = faOn.checked;
            var box = faConfirm.closest('.site-confirm');
            if (box) { box.classList.toggle('is-required', faOn.checked); }
        };
        faOn.addEventListener('change', sync);
        sync();
    }

    /* ---------- Copy buttons that keep their icon ---------- */
    qsa('[data-copy-text]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy-text');
            var done = function () {
                var label = btn.querySelector('span');
                if (!label) { return; }
                var old = label.textContent;
                label.textContent = 'Copied';
                setTimeout(function () { label.textContent = old; }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); } catch (err) { /* ignore */ }
                ta.remove();
            }
        });
    });

    /* ---------- Menu editor: quick page picker fills the URL box ---------- */
    qsa('[data-url-pick]').forEach(function (sel) {
        sel.hidden = false;
        var lbl = document.querySelector('label[for="' + sel.id + '"]');
        if (lbl) { lbl.hidden = false; }
        sel.addEventListener('change', function () {
            var target = document.querySelector(sel.getAttribute('data-url-pick'));
            if (target && sel.value) {
                target.value = sel.value;
                var label = document.querySelector(sel.getAttribute('data-label-target') || '');
                if (label && label.value.trim() === '' && sel.selectedOptions[0]) {
                    label.value = sel.selectedOptions[0].textContent.trim();
                }
            }
        });
    });

    initImageFields(document);
    initIconPreview(document);
    initRepeaters(document);
})();
