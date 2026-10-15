/* =====================================================================
   Prerit Welfare Foundation - admin panel interactions
   ===================================================================== */
(function () {
    'use strict';

    /* ---------- Mobile sidebar ---------- */
    var toggle = document.querySelector('.admin-menu-toggle');
    if (toggle) {
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = document.body.classList.toggle('admin-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            var sidebar = document.getElementById('admin-sidebar');
            if (document.body.classList.contains('admin-open') && sidebar && !sidebar.contains(e.target)) {
                document.body.classList.remove('admin-open');
            }
        });
    }

    /* ---------- Dismiss alerts ---------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.alert-close');
        if (btn) { btn.closest('.alert').remove(); }
    });

    /* ---------- Confirm destructive actions ---------- */
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-confirm-click]');
        if (btn && !window.confirm(btn.getAttribute('data-confirm-click'))) {
            e.preventDefault();
        }
    });

    /* ---------- Auto-submit filters ---------- */
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.submit(); });
    });

    /* ---------- Simple HTML editor toolbar ---------- */
    function wrapSelection(ta, before, after) {
        var start = ta.selectionStart, end = ta.selectionEnd;
        var selected = ta.value.substring(start, end);
        ta.value = ta.value.substring(0, start) + before + selected + after + ta.value.substring(end);
        ta.focus();
        ta.selectionStart = start + before.length;
        ta.selectionEnd = start + before.length + selected.length;
    }
    document.querySelectorAll('[data-editor]').forEach(function (ta) {
        var bar = document.createElement('div');
        bar.className = 'editor-toolbar';
        var tools = [
            ['P', '<p>', '</p>'], ['H2', '<h2>', '</h2>'], ['H3', '<h3>', '</h3>'], ['B', '<strong>', '</strong>'],
            ['I', '<em>', '</em>'], ['• List', '<ul>\n<li>', '</li>\n</ul>'], ['1. List', '<ol>\n<li>', '</li>\n</ol>'],
            ['Item', '<li>', '</li>'], ['Quote', '<blockquote>', '</blockquote>'], ['Link', null, null], ['Image', null, null]
        ];
        tools.forEach(function (t) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = t[0];
            b.addEventListener('click', function () {
                if (t[0] === 'Link') {
                    var href = window.prompt('Link address (https://… or page.php)');
                    if (href) { wrapSelection(ta, '<a href="' + href.replace(/"/g, '&quot;') + '">', '</a>'); }
                } else if (t[0] === 'Image') {
                    uploadEditorImage(ta);
                } else {
                    wrapSelection(ta, t[1], t[2]);
                }
            });
            bar.appendChild(b);
        });
        ta.parentNode.insertBefore(bar, ta);
    });

    function uploadEditorImage(ta) {
        var endpoint = ta.getAttribute('data-upload-url');
        var csrf = document.querySelector('input[name="csrf_token"]');
        if (!endpoint || !csrf) { return; }
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = '.jpg,.jpeg,.png,.webp';
        input.addEventListener('change', function () {
            if (!input.files[0]) { return; }
            var fd = new FormData();
            fd.append('csrf_token', csrf.value);
            fd.append('type', 'editor');
            fd.append('ajax', '1');
            fd.append('image', input.files[0]);
            fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.url) {
                        var alt = window.prompt('Short description of the image (for accessibility)', '') || '';
                        wrapSelection(ta, '<img src="' + data.url + '" alt="' + alt.replace(/"/g, '&quot;') + '">', '');
                    } else {
                        alert(data.error || 'Upload failed');
                    }
                })
                .catch(function () { alert('Upload failed'); });
        });
        input.click();
    }

    /* ---------- Slug preview ---------- */
    document.querySelectorAll('[data-slug-source]').forEach(function (src) {
        var target = document.querySelector(src.getAttribute('data-slug-source'));
        if (!target) { return; }
        src.addEventListener('input', function () {
            if (target.dataset.touched) { return; }
            target.value = src.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').substring(0, 180);
        });
        target.addEventListener('input', function () { target.dataset.touched = '1'; });
    });

    /* ---------- Repeatable rows (menu editor) ---------- */
    document.querySelectorAll('[data-add-row]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tpl = document.getElementById(btn.getAttribute('data-add-row'));
            var list = document.querySelector(btn.getAttribute('data-target'));
            if (tpl && list) {
                list.insertAdjacentHTML('beforeend', tpl.innerHTML);
            }
        });
    });
    document.addEventListener('click', function (e) {
        var rm = e.target.closest('[data-remove-row]');
        if (rm) {
            var row = rm.closest('.menu-row, .slide-box');
            if (row) { row.remove(); }
        }
    });

    /* ---------- Image preview for file inputs ---------- */
    document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
        input.addEventListener('change', function () {
            var img = document.querySelector(input.getAttribute('data-preview'));
            if (img && input.files && input.files[0] && /^image\//.test(input.files[0].type)) {
                img.src = URL.createObjectURL(input.files[0]);
                img.hidden = false;
            }
        });
    });

    /* ---------- Select all checkboxes ---------- */
    document.querySelectorAll('[data-check-all]').forEach(function (master) {
        master.addEventListener('change', function () {
            document.querySelectorAll(master.getAttribute('data-check-all')).forEach(function (cb) { cb.checked = master.checked; });
        });
    });

    /* ---------- Copy buttons ---------- */
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(btn.getAttribute('data-copy'));
                var t = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = t; }, 1500);
            }
        });
    });

    /* ---------- Print ---------- */
    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.print(); });
    });
})();
