/* =====================================================================
   Home page hero slider editor (Admin > Pages > Home > Sections):
   tells the editor what will happen to each slide's right-side image before saving -
   new file chosen (name, size; too large / wrong type is refused at once), photo
   proportions (portrait or small photos are cropped / look soft), library pick, removal.
   Works on top of admin-site.js (preview, library picker, remove button).
   ===================================================================== */
(function () {
    'use strict';

    var sections = document.querySelectorAll('.site-section.is-hero-slider');
    if (!sections.length) { return; }

    function fmtSize(bytes) {
        return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }
    function statusFor(field) {
        var s = field.querySelector('.hero-img-status');
        if (!s) {
            s = document.createElement('p');
            s.className = 'hero-img-status';
            s.setAttribute('aria-live', 'polite');
            var controls = field.querySelector('.site-image-controls') || field;
            controls.appendChild(s);
        }
        return s;
    }
    function say(field, text, tone) {
        var s = statusFor(field);
        s.textContent = text;
        s.className = 'hero-img-status' + (tone ? ' is-' + tone : '');
        s.hidden = text === '';
    }
    function checkDims(field, img) {
        var w = img.naturalWidth, h = img.naturalHeight;
        if (!w || !h) { return ''; }
        if (w < h) { return ' It is a portrait photo (' + w + ' × ' + h + ' px): the frame is landscape, so the top and bottom will be cropped - use "Photo focus" to choose what stays visible.'; }
        if (w < 1000) { return ' It is only ' + w + ' × ' + h + ' px and may look soft on large screens - about 1200 × 900 px is best.'; }
        if (w / h > 1.9) { return ' It is very wide (' + w + ' × ' + h + ' px): the sides will be cropped to fit the 4:3 frame.'; }
        return '';
    }

    sections.forEach(function (section) {
        var maxBytes = (parseFloat(section.getAttribute('data-max-upload-mb')) || 5) * 1048576;
        section.querySelectorAll('[data-image-field]').forEach(function (field) {
            var file = field.querySelector('[data-image-file]');
            var path = field.querySelector('[data-image-path]');
            var preview = field.querySelector('[data-image-preview]');
            if (file) {
                // capture phase on the field: a refused file never reaches admin-site.js (no preview, nothing uploaded)
                field.addEventListener('change', function (e) {
                    if (e.target !== file) { return; }
                    var f = file.files && file.files[0];
                    if (!f) { say(field, ''); return; }
                    var refuse = !/^image\/(jpeg|png|webp)$/.test(f.type)
                        ? '"' + f.name + '" is not a JPG, PNG or WebP image - please choose another file.'
                        : (f.size > maxBytes ? '"' + f.name + '" is ' + fmtSize(f.size) + ' - the limit is ' + fmtSize(maxBytes) + '. Please choose a smaller file (or export it at about 1200 × 900 px).' : '');
                    if (refuse) {
                        e.stopPropagation();
                        file.value = '';
                        say(field, refuse, 'error');
                        return;
                    }
                    say(field, 'New image chosen: ' + f.name + ' (' + fmtSize(f.size) + '). It replaces the current image when you click "Save section".', 'ok');
                    if (preview) {
                        preview.addEventListener('load', function onLoad() {
                            preview.removeEventListener('load', onLoad);
                            var note = checkDims(field, preview);
                            if (note && file.files && file.files[0] === f) {
                                say(field, 'New image chosen: ' + f.name + ' (' + fmtSize(f.size) + ').' + note + ' Click "Save section" to use it.', 'warn');
                            }
                        });
                    }
                }, true);
            }
            if (path) {
                path.addEventListener('change', function () {
                    say(field, path.value === ''
                        ? 'Image removed: the default illustration is shown on this slide after you click "Save section".'
                        : 'New image selected (' + path.value + '). Click "Save section" to use it.', path.value === '' ? 'warn' : 'ok');
                });
            }
        });
    });
}());
