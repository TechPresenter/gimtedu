/* =====================================================================
   Home page hero slider editor (Admin > Pages > Home > Sections):
   tells the editor what will happen to each slide's right-side image before saving -
   new file chosen (name, size; wrong type, too large for this server, or too much for one
   save is refused at once), photo proportions (portrait or small photos are cropped / look
   soft), library pick, removal. Works on top of admin-site.js (preview, library picker,
   remove button); events are delegated, so slides added with "Add slide" are covered too.
   ===================================================================== */
(function () {
    'use strict';

    var sections = document.querySelectorAll('.site-section.is-hero-slider');
    if (!sections.length) { return; }

    var FORM_MARGIN = 262144; // room for the text fields of the form (256 KB)

    function fmtSize(bytes) {
        if (bytes < 1048576) { return Math.max(1, Math.round(bytes / 1024)) + ' KB'; }
        // "2 MB", "2.03 MB", "8.2 MB": two decimals below 10 MB, so a file just over the limit does not read as equal to it
        return String(+(bytes / 1048576).toFixed(bytes < 10485760 ? 2 : 1)) + ' MB';
    }
    // One polite live region per image field. Screen readers announce changes only in a region that already
    // exists, so it is created empty (collapsed by CSS) when the page loads, or one moment before the first
    // message for a slide added with "Add slide".
    function statusFor(field, fresh) {
        var s = field.querySelector('.hero-img-status');
        if (!s) {
            s = document.createElement('p');
            s.className = 'hero-img-status';
            s.setAttribute('aria-live', 'polite');
            (field.querySelector('.site-image-controls') || field).appendChild(s);
            s.heroReadyAt = fresh ? Date.now() + 100 : 0;
            s.heroSeq = 0;
        }
        return s;
    }
    function say(field, text, tone) {
        var s = statusFor(field, true);
        var seq = ++s.heroSeq;   // only the latest message is shown, even when an earlier one was delayed
        var show = function () {
            if (s.heroSeq !== seq) { return; }
            s.textContent = text;
            s.className = 'hero-img-status' + (tone && text ? ' is-' + tone : '');
        };
        var wait = s.heroReadyAt - Date.now();
        if (wait > 0) { setTimeout(show, wait); } else { show(); }
    }
    function checkDims(img) {
        var w = img.naturalWidth, h = img.naturalHeight;
        if (!w || !h) { return ''; }
        if (w < h) { return ' It is a portrait photo (' + w + ' × ' + h + ' px): the frame is landscape, so the top and bottom will be cropped - use "Photo focus" to choose what stays visible.'; }
        if (w < 1000) { return ' It is only ' + w + ' × ' + h + ' px and may look soft on large screens - about 1200 × 900 px is best.'; }
        if (w / h > 1.9) { return ' It is very wide (' + w + ' × ' + h + ' px): the sides will be cropped to fit the 4:3 frame.'; }
        return '';
    }

    sections.forEach(function (section) {
        // limits this server really accepts (page-edit.php: the smaller of the site limit and php.ini)
        var maxBytes = parseInt(section.getAttribute('data-max-upload-bytes'), 10) || 5242880;
        var maxPost = parseInt(section.getAttribute('data-max-post-bytes'), 10) || 0;

        // The image that "Save section" keeps when no new file is uploaded (the saved one, or a library pick /
        // removal). The preview returns to it when a chosen file is refused or the file choice is cleared, so it
        // never shows a chosen file that will not be uploaded. Read before admin-site.js changes the preview.
        function kept(field) {
            if (!field.heroKept) {
                var pv = field.querySelector('[data-image-preview]');
                field.heroKept = { src: pv ? pv.getAttribute('src') || '' : '', hidden: pv ? pv.hidden : true };
            }
            return field.heroKept;
        }
        function showKept(field) {
            var pv = field.querySelector('[data-image-preview]');
            var k = kept(field);
            if (!pv) { return; }
            if (k.src) { pv.src = k.src; } else { pv.removeAttribute('src'); }
            pv.hidden = k.hidden;
        }
        function otherFilesBytes(except) {
            var total = 0;
            section.querySelectorAll('[data-image-file]').forEach(function (inp) {
                if (inp !== except && inp.files) {
                    for (var i = 0; i < inp.files.length; i++) { total += inp.files[i].size; }
                }
            });
            return total;
        }
        section.querySelectorAll('[data-image-field]').forEach(function (field) { kept(field); statusFor(field, false); });

        // capture phase on the section: a refused file never reaches admin-site.js (no preview, nothing uploaded)
        section.addEventListener('change', function (e) {
            var t = e.target;
            var field = t && t.closest ? t.closest('[data-image-field]') : null;
            if (!field) { return; }
            if (t.matches('[data-image-file]')) {
                var f = t.files && t.files[0];
                kept(field);
                if (!f) { showKept(field); say(field, ''); return; }
                var others = otherFilesBytes(t);
                var refuse = '';
                if (!/^image\/(jpeg|png|webp)$/.test(f.type)) {
                    refuse = '"' + f.name + '" is not a JPG, PNG or WebP image - please choose another file.';
                } else if (f.size > maxBytes) {
                    refuse = '"' + f.name + '" is ' + fmtSize(f.size) + ' - this website accepts images up to ' + fmtSize(maxBytes) + '. Please choose a smaller file (or export it at about 1200 × 900 px).';
                } else if (maxPost && others + f.size + FORM_MARGIN > maxPost) {
                    refuse = 'Together with the other new image' + (others ? 's' : '') + ' chosen in this section (' + fmtSize(others) + '), "' + f.name + '" (' + fmtSize(f.size) + ') is more than the server accepts in one save (' + fmtSize(maxPost) + '). Click "Save section" first, then upload this image.';
                }
                if (refuse) {
                    e.stopPropagation();
                    t.value = '';
                    showKept(field);
                    say(field, refuse + (kept(field).src ? ' The current image stays.' : ''), 'error');
                    return;
                }
                say(field, 'New image chosen: ' + f.name + ' (' + fmtSize(f.size) + '). It replaces the current image when you click "Save section".', 'ok');
                var preview = field.querySelector('[data-image-preview]');
                if (preview) {
                    preview.addEventListener('load', function onLoad() {
                        preview.removeEventListener('load', onLoad);
                        var note = checkDims(preview);
                        if (note && t.files && t.files[0] === f) {
                            say(field, 'New image chosen: ' + f.name + ' (' + fmtSize(f.size) + ').' + note + ' Click "Save section" to use it.', 'warn');
                        }
                    });
                }
            } else if (t.matches('[data-image-path]')) {
                kept(field);
                // admin-site.js sets the preview right after this event (library pick / Remove)
                setTimeout(function () {
                    var pv = field.querySelector('[data-image-preview]');
                    var fileInput = field.querySelector('[data-image-file]');
                    if (pv && !(fileInput && fileInput.files && fileInput.files.length)) {
                        field.heroKept = { src: pv.getAttribute('src') || '', hidden: pv.hidden };
                    }
                }, 0);
                say(field, t.value === ''
                    ? 'Image removed: the default illustration is shown on this slide after you click "Save section".'
                    : 'New image selected (' + t.value + '). Click "Save section" to use it.', t.value === '' ? 'warn' : 'ok');
            }
        }, true);
    });
}());
