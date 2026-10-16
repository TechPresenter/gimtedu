/* =====================================================================
   Prerit Welfare Foundation - admin panel interactions
   ===================================================================== */
(function () {
    'use strict';

    /* ---------- Mobile sidebar ---------- */
    var toggle = document.querySelector('.admin-menu-toggle');
    var sidebarEl = document.getElementById('admin-sidebar');
    if (toggle) {
        var closeMenu = function (returnFocus) {
            document.body.classList.remove('admin-open');
            toggle.setAttribute('aria-expanded', 'false');
            if (returnFocus) { toggle.focus(); }
        };
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = document.body.classList.toggle('admin-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && sidebarEl) {
                var first = sidebarEl.querySelector('a, button');
                if (first) { requestAnimationFrame(function () { first.focus(); }); }
            }
        });
        document.addEventListener('click', function (e) {
            if (document.body.classList.contains('admin-open') && sidebarEl && !sidebarEl.contains(e.target)) {
                closeMenu(false);
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('admin-open')) { closeMenu(true); }
        });
    }
    // keep the current page's link visible in a long sidebar
    var activeLink = sidebarEl && sidebarEl.querySelector('a.active');
    if (activeLink && activeLink.offsetTop + activeLink.offsetHeight > sidebarEl.clientHeight) {
        sidebarEl.scrollTop = Math.max(0, activeLink.offsetTop - sidebarEl.clientHeight / 2);
    }

    /* ---------- Flash messages ---------- */
    // After a save that returns to an #anchor (or a scrolled page) the message floats in view; success / info
    // messages disappear after a while, errors stay until dismissed.
    var flashWrap = document.querySelector('.admin-content > .flash-wrap');
    if (flashWrap && flashWrap.querySelector('.alert')) {
        if (location.hash.length > 1 || window.scrollY > 0) { flashWrap.classList.add('is-floating'); }
        flashWrap.querySelectorAll('.alert-success, .alert-info').forEach(function (a) {
            setTimeout(function () {
                if (a.parentNode) { a.remove(); }
                if (!flashWrap.querySelector('.alert')) { flashWrap.remove(); }
            }, 8000);
        });
    }

    /* ---------- Dismiss alerts ---------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.alert-close');
        if (btn) {
            var wrap = btn.closest('.flash-wrap');
            btn.closest('.alert').remove();
            if (wrap && !wrap.querySelector('.alert')) { wrap.remove(); }
        }
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

    /* ---------- Date range picker: presets apply at once, "Custom dates" reveals From / To ---------- */
    document.querySelectorAll('form.range-picker').forEach(function (f) {
        var sel = f.querySelector('select[name="range"]');
        var box = f.querySelector('.range-custom');
        if (!sel || !box) { return; }
        var from = f.querySelector('input[name="from"]');
        var to = f.querySelector('input[name="to"]');
        box.hidden = sel.value !== 'custom';
        sel.addEventListener('change', function () {
            if (sel.value === 'custom') {
                box.hidden = false;
                if (from) { from.focus(); }
                return;
            }
            if (from) { from.disabled = true; }   // disabled inputs are not submitted: stale dates never override a preset
            if (to) { to.disabled = true; }
            f.submit();
        });
        [from, to].forEach(function (i) {
            if (i) { i.addEventListener('change', function () { sel.value = 'custom'; }); }
        });
    });

    /* ---------- Charts: scroll wide charts to the latest data ---------- */
    document.querySelectorAll('.insights-chart').forEach(function (c) {
        if (c.scrollWidth > c.clientWidth) { c.scrollLeft = c.scrollWidth; }
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
            var text = btn.getAttribute('data-copy');
            var done = function () {
                var html = btn.innerHTML;   // keep the icon
                btn.textContent = 'Copied';
                var live = document.getElementById('copy-live');
                if (!live) {
                    live = document.createElement('span');
                    live.id = 'copy-live';
                    live.className = 'sr-only';
                    live.setAttribute('aria-live', 'polite');
                    document.body.appendChild(live);
                }
                live.textContent = 'Copied to the clipboard';
                setTimeout(function () { btn.innerHTML = html; live.textContent = ''; }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, function () {});
                return;
            }
            // plain http (e.g. before SSL is switched on): copy through a temporary text box
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { if (document.execCommand('copy')) { done(); } } catch (err) { /* ignore */ }
            document.body.removeChild(ta);
            btn.focus();
        });
    });

    /* ---------- Print ---------- */
    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.print(); });
    });
})();
