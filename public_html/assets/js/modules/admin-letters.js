/* =====================================================================
   Authorization letters (admin)
   - letter-edit.php: ID type toggle, masked ID preview, live preview of the letter text
   - letter-print.php: fit the letter on one A4 page, zoom the preview on small screens,
     Print button, record prints in the audit log (POST with the CSRF token)
   No inline scripts (CSP); everything is progressive enhancement.
   ===================================================================== */
(function () {
    'use strict';

    /* ---------- helpers ---------- */
    function pronounWords(p) {
        var map = {
            he: { he_she: 'he', his_her: 'his', him_her: 'him', is_are: 'is' },
            she: { he_she: 'she', his_her: 'her', him_her: 'her', is_are: 'is' },
            they: { he_she: 'they', his_her: 'their', him_her: 'them', is_are: 'are' }
        };
        return map[p] || map.they;
    }

    function maskId(type, raw) {
        var v = (raw || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        if (v === '') { return { masked: '', error: '' }; }
        if (type === 'aadhaar') {
            if (/^\d{12}$/.test(v) || /^\d{4}$/.test(v)) { return { masked: 'XXXX XXXX ' + v.slice(-4), error: '' }; }
            return { masked: '', error: 'Enter the 12-digit Aadhaar number or only its last 4 digits.' };
        }
        if (/^[A-Z0-9]{4,20}$/.test(v)) {
            return { masked: new Array(Math.max(4, Math.min(16, v.length - 4)) + 1).join('X') + v.slice(-4), error: '' };
        }
        return { masked: '', error: 'Enter 4 to 20 letters or digits (or only the last 4).' };
    }

    /* text with **bold**, blank-line paragraphs and line breaks -> DOM nodes (no innerHTML) */
    function appendInline(parent, text) {
        text.split(/(\{[^{}\s]{1,40}\})/).forEach(function (part, i) {
            if (part === '') { return; }
            if (i % 2 === 1) {
                var mark = document.createElement('mark');
                mark.title = 'Unknown placeholder';
                mark.textContent = part;
                parent.appendChild(mark);
                return;
            }
            part.split('\n').forEach(function (line, j) {
                if (j > 0) { parent.appendChild(document.createElement('br')); }
                if (line !== '') { parent.appendChild(document.createTextNode(line)); }
            });
        });
    }
    function renderRich(container, text) {
        while (container.firstChild) { container.removeChild(container.firstChild); }
        text.replace(/\r/g, '').trim().split(/\n\s*\n/).forEach(function (para) {
            para = para.trim();
            if (para === '') { return; }
            var p = document.createElement('p');
            para.split(/\*\*([\s\S]+?)\*\*/).forEach(function (chunk, i) {
                if (chunk === '') { return; }
                if (i % 2 === 1) {
                    var b = document.createElement('strong');
                    appendInline(b, chunk);
                    p.appendChild(b);
                } else {
                    appendInline(p, chunk.replace(/\*\*/g, ''));
                }
            });
            container.appendChild(p);
        });
    }

    /* ---------- letter-edit.php ---------- */
    var form = document.querySelector('[data-letter-form]');
    if (form) {
        var typeSel = form.querySelector('#id_type');
        var otherWrap = form.querySelector('[data-id-other]');
        var otherInput = form.querySelector('#id_label_other');
        var idInput = form.querySelector('[data-id-input]');
        var idPreview = document.getElementById('id_number_preview');
        var tpl = form.querySelector('[data-body-template]');
        var previewWrap = form.querySelector('[data-body-preview-wrap]');
        var preview = form.querySelector('[data-body-preview]');
        var labels = { aadhaar: 'Aadhaar ID', voter: 'Voter ID', other: 'Other ID' };

        var val = function (sel) {
            var el = form.querySelector(sel);
            return el ? String(el.value || '').trim() : '';
        };
        var currentMasked = function () {
            var res = maskId(typeSel ? typeSel.value : 'aadhaar', idInput ? idInput.value : '');
            if (res.masked) { return res.masked; }
            if (idInput && idInput.value.trim() === '' && idInput.getAttribute('data-current')) { return idInput.getAttribute('data-current'); }
            return typeSel && typeSel.value === 'aadhaar' ? 'XXXX XXXX ____' : '____';
        };
        var idLabel = function () {
            var t = typeSel ? typeSel.value : 'aadhaar';
            return t === 'other' ? (val('#id_label_other') || 'ID') : labels[t];
        };
        var addressInline = function () {
            var parts = val('#school_address').split(/\n+/).map(function (s) { return s.replace(/^[\s,]+|[\s,]+$/g, ''); }).filter(Boolean);
            [val('#district'), val('#state')].forEach(function (x) {
                if (x && parts.join(', ').toLowerCase().indexOf(x.toLowerCase()) === -1) { parts.push(x); }
            });
            return parts.join(', ');
        };

        var updateId = function () {
            if (otherWrap) { otherWrap.hidden = !typeSel || typeSel.value !== 'other'; }
            if (otherInput) { otherInput.required = !!typeSel && typeSel.value === 'other'; }
            if (!idPreview || !idInput) { return; }
            var res = maskId(typeSel ? typeSel.value : 'aadhaar', idInput.value);
            idPreview.classList.toggle('is-error', !!res.error);
            idPreview.textContent = '';
            if (res.error) {
                idPreview.textContent = res.error;
            } else if (res.masked || idInput.getAttribute('data-current')) {
                idPreview.appendChild(document.createTextNode('Printed as: '));
                var s = document.createElement('strong');
                s.textContent = res.masked || idInput.getAttribute('data-current');
                idPreview.appendChild(s);
            }
        };

        var updatePreview = function () {
            if (!tpl || !preview) { return; }
            var checked = form.querySelector('input[name="pronoun"]:checked');
            var w = pronounWords(checked ? checked.value : 'they');
            var strip = function (s) { return String(s).replace(/\*/g, ''); };
            var values = {
                '{candidate_name}': val('#candidate_name') || '[candidate name]',
                '{id_label}': idLabel(),
                '{id_number}': currentMasked(),
                '{school}': val('#school_name') || '[school]',
                '{address}': addressInline() || '[address]',
                '{district}': val('#district') || '[district]',
                '{state}': val('#state') || '[state]',
                '{organisation}': form.getAttribute('data-org') || '',
                '{he_she}': w.he_she,
                '{He_She}': w.he_she.charAt(0).toUpperCase() + w.he_she.slice(1),
                '{his_her}': w.his_her,
                '{him_her}': w.him_her,
                '{is_are}': w.is_are
            };
            var text = tpl.value.replace(/\{[^{}\s]{1,40}\}/g, function (m) {
                return Object.prototype.hasOwnProperty.call(values, m) ? strip(values[m]) : m;
            });
            renderRich(preview, text);
            if (previewWrap) { previewWrap.hidden = false; }
        };

        form.addEventListener('input', function () { updateId(); updatePreview(); });
        form.addEventListener('change', function () { updateId(); updatePreview(); });
        updateId();
        updatePreview();
    }

    /* ---------- letter-print.php ---------- */
    var sheet = document.querySelector('[data-al-sheet]');
    if (sheet) {
        var main = sheet.querySelector('[data-al-main]');
        var wrap = document.querySelector('[data-al-wrap]');
        var note = document.querySelector('[data-al-fit-note]');

        var fit = function () {
            if (!main) { return; }
            var scale = 1;
            sheet.style.setProperty('--al-scale', '1');
            while (main.scrollHeight > main.clientHeight + 1 && scale > 0.79) {
                scale = Math.round((scale - 0.02) * 100) / 100;
                sheet.style.setProperty('--al-scale', String(scale));
            }
            if (!note) { return; }
            if (main.scrollHeight > main.clientHeight + 1) {
                note.textContent = 'This letter is too long for one A4 page, so the end would be cut off. Please shorten the letter text, enclosures or address (Edit).';
                note.hidden = false;
            } else if (scale < 1) {
                note.textContent = 'The text has been printed slightly smaller (' + Math.round(scale * 100) + '%) so that the letter fits on one A4 page.';
                note.hidden = false;
            } else {
                note.hidden = true;
            }
        };

        var zoom = function () {
            if (!wrap) { return; }
            var stage = wrap.parentNode;
            var sheetPx = sheet.offsetWidth || 794;
            var avail = stage.clientWidth - 32;
            var z = avail < sheetPx ? Math.max(0.3, avail / sheetPx) : 1;
            wrap.style.zoom = z === 1 ? '' : String(z);
            wrap.classList.toggle('is-zoomed', z !== 1);
        };

        var ready = (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
        ready.then(function () { fit(); zoom(); });
        var imgs = sheet.querySelectorAll('img');
        Array.prototype.forEach.call(imgs, function (img) {
            if (!img.complete) { img.addEventListener('load', fit); }
        });
        window.addEventListener('resize', zoom);

        /* audit log: one POST each time the print dialog opens (the server counts at most one a minute) */
        var beacon = document.querySelector('[data-al-beacon]');
        var lastSent = 0;
        var logPrint = function () {
            if (!beacon || Date.now() - lastSent < 5000) { return; }
            lastSent = Date.now();
            var data = new FormData(beacon);
            if (navigator.sendBeacon && navigator.sendBeacon(beacon.action, data)) { return; }
            if (window.fetch) {
                fetch(beacon.action, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true }).catch(function () {});
            }
        };
        window.addEventListener('beforeprint', function () { fit(); logPrint(); });

        document.querySelectorAll('[data-al-print]').forEach(function (btn) {
            btn.addEventListener('click', function () { window.print(); });
        });
        document.addEventListener('click', function (e) {
            var close = e.target.closest ? e.target.closest('.alert-close') : null;
            if (close && close.closest('.alert')) { close.closest('.alert').remove(); }
        });
    }
})();
