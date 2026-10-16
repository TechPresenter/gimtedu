/* =====================================================================
   Prerit Welfare Foundation - site interactions (vanilla JavaScript)
   ===================================================================== */
(function () {
    'use strict';

    var body = document.body;
    var root = document.documentElement;
    var reduceMotion = (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) || body.getAttribute('data-motion') === 'off';
    var finePointer = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    root.classList.add('js');
    if (!reduceMotion && 'IntersectionObserver' in window) { root.classList.add('js-motion'); }

    /* ---------- Navigation ----------
       Desktop (>= 1280px): disclosure menus - a submenu opens on hover or through its toggle button.
       Below 1280px the nav is a modal drawer: the page behind it is made inert while it is open. */
    var navToggle = document.querySelector('.nav-toggle');
    var mainNav = document.getElementById('main-nav');
    var siteHeader = document.getElementById('site-header');
    var desktopMq = window.matchMedia ? window.matchMedia('(min-width: 1280px)') : null;
    var inertEls = [];

    function closeDropdown(item, returnFocus) {
        item.classList.remove('open');
        var t = item.querySelector('.dropdown-toggle');
        if (!t) { return; }
        t.setAttribute('aria-expanded', 'false');
        var dd = item.querySelector('.dropdown');
        if (returnFocus && dd && dd.contains(document.activeElement)) { t.focus(); }
    }
    function closeAllDropdowns() {
        document.querySelectorAll('.has-dropdown.open').forEach(function (el) { closeDropdown(el, false); });
    }
    function setNavInert(on) {
        inertEls.forEach(function (el) { el.removeAttribute('inert'); });
        inertEls = [];
        if (!on) { return; }
        Array.prototype.forEach.call(body.children, function (el) {
            if (el !== siteHeader && el.tagName !== 'SCRIPT' && el.tagName !== 'STYLE') { inertEls.push(el); }
        });
        if (siteHeader) {
            siteHeader.querySelectorAll('.header-inner > .brand, .header-actions, .nav-strip, .scroll-progress').forEach(function (el) { inertEls.push(el); });
        }
        inertEls.forEach(function (el) { el.setAttribute('inert', ''); });
    }
    function openNav() {
        if (!navToggle || !mainNav || body.classList.contains('nav-open')) { return; }
        body.classList.add('nav-open');
        navToggle.setAttribute('aria-expanded', 'true');
        navToggle.setAttribute('aria-label', 'Close menu');
        setNavInert(true);
        var first = mainNav.querySelector('.nav-close') || mainNav.querySelector('a, button');
        if (first) { requestAnimationFrame(function () { first.focus(); }); }
    }
    function closeNav(returnFocus) {
        if (!body.classList.contains('nav-open')) { return; }
        body.classList.remove('nav-open');
        setNavInert(false); // before focusing the toggle: an inert element cannot take focus
        closeAllDropdowns();
        if (navToggle) {
            navToggle.setAttribute('aria-expanded', 'false');
            navToggle.setAttribute('aria-label', 'Open menu');
            if (returnFocus !== false) { navToggle.focus(); }
        }
    }

    if (navToggle && mainNav) {
        document.querySelectorAll('[data-nav-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (body.classList.contains('nav-open')) { closeNav(); } else { openNav(); }
            });
        });
        document.addEventListener('click', function (e) {
            if (body.classList.contains('nav-open') && !mainNav.contains(e.target) && !navToggle.contains(e.target)) {
                closeNav();
            }
        });
        // fallback for browsers without `inert`: keep Tab inside the open drawer
        if (!('inert' in HTMLElement.prototype)) {
            mainNav.addEventListener('keydown', function (e) {
                if (e.key !== 'Tab' || !body.classList.contains('nav-open')) { return; }
                var f = Array.prototype.filter.call(mainNav.querySelectorAll('a[href], button:not([disabled])'), function (el) { return el.offsetParent !== null; });
                if (!f.length) { return; }
                if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
                else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
            });
        }
        // widening past the drawer breakpoint (e.g. rotating a tablet) must never leave the page inert
        if (desktopMq) {
            var onDesktopChange = function (mq) { if (mq.matches) { closeNav(false); } fitDropdowns(); };
            if (desktopMq.addEventListener) { desktopMq.addEventListener('change', onDesktopChange); } else if (desktopMq.addListener) { desktopMq.addListener(onDesktopChange); }
        }
    }

    /* Desktop: keep every submenu inside the viewport (a centred mega menu under "About" would otherwise
       start off-screen at 1280-1370px, and the last menu would stick out on the right). */
    function fitDropdowns() {
        var dds = document.querySelectorAll('.main-nav .has-dropdown > .dropdown');
        dds.forEach(function (dd) { dd.style.removeProperty('--dd-shift'); });
        if (desktopMq && !desktopMq.matches) { return; }
        var vw = document.documentElement.clientWidth, gap = 12;
        var shifts = Array.prototype.map.call(dds, function (dd) {
            var r = dd.getBoundingClientRect();
            if (r.left < gap) { return gap - r.left; }
            if (r.right > vw - gap) { return Math.max(gap - r.left, vw - gap - r.right); }
            return 0;
        });
        dds.forEach(function (dd, i) { if (shifts[i]) { dd.style.setProperty('--dd-shift', Math.round(shifts[i]) + 'px'); } });
    }
    if (mainNav) {
        var fitQueued = false;
        window.addEventListener('resize', function () {
            if (fitQueued) { return; }
            fitQueued = true;
            requestAnimationFrame(function () { fitQueued = false; fitDropdowns(); });
        });
        fitDropdowns();
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fitDropdowns); }
        window.addEventListener('load', fitDropdowns);
    }

    document.querySelectorAll('.dropdown-toggle').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var item = btn.closest('.has-dropdown');
            item.classList.remove('is-dismissed');
            var isOpen = item.classList.toggle('open');
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            document.querySelectorAll('.has-dropdown.open').forEach(function (other) {
                if (other !== item) { closeDropdown(other, false); }
            });
        });
    });
    document.querySelectorAll('.has-dropdown').forEach(function (item) {
        item.addEventListener('mouseleave', function () { item.classList.remove('is-dismissed'); });
        item.addEventListener('focusout', function (e) {
            if (e.relatedTarget && item.contains(e.relatedTarget)) { return; }
            item.classList.remove('is-dismissed');
            // desktop: an open menu closes when focus leaves it (the drawer keeps its sections open)
            if (!body.classList.contains('nav-open') && item.classList.contains('open')) { closeDropdown(item, false); }
        });
    });
    document.addEventListener('click', function (e) {
        if (body.classList.contains('nav-open')) { return; }
        document.querySelectorAll('.has-dropdown.open').forEach(function (el) {
            if (!el.contains(e.target)) { closeDropdown(el, false); }
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        var drawerOpen = body.classList.contains('nav-open');
        document.querySelectorAll('.has-dropdown').forEach(function (el) {
            var hovered = el.matches(':hover');
            if (el.classList.contains('open') || hovered || el.contains(document.activeElement)) {
                closeDropdown(el, true);
                if (hovered && !drawerOpen) { el.classList.add('is-dismissed'); }
            }
        });
        closeNav();
        closeLightbox();
    });

    /* ---------- Horizontal scroll rows ([data-hscroll]): quick-links strip, drawer apply chips ----------
       Marks the row as overflowing / at the start / at the end (CSS edge fades), adds pointer-only "more"
       hints (keyboard users Tab through the links, which scroll themselves into view) and, for the strip,
       centres the active pill once on load. Instant scrolling of an inner row is not a layout shift. */
    var chevron = function (d) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' + d + '" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    };
    document.querySelectorAll('[data-hscroll]').forEach(function (row) {
        var host = row.parentElement;
        if (!host) { return; }
        if (getComputedStyle(host).position === 'static') { host.style.position = 'relative'; }
        function hint(cls, dir, d) {
            var h = document.createElement('span');
            h.className = 'hscroll-hint ' + cls;
            h.setAttribute('aria-hidden', 'true');
            h.innerHTML = chevron(d);
            h.addEventListener('click', function () {
                row.scrollBy({ left: dir * Math.max(120, row.clientWidth * 0.7), behavior: reduceMotion ? 'auto' : 'smooth' });
            });
            host.appendChild(h);
            return h;
        }
        hint('hscroll-prev', -1, 'M15 18l-6-6 6-6');
        var next = hint('hscroll-next', 1, 'M9 18l6-6-6-6');
        var queued = false;
        function update() {
            queued = false;
            var max = row.scrollWidth - row.clientWidth;
            var x = Math.abs(row.scrollLeft);
            row.classList.toggle('is-overflowing', max > 2);
            row.classList.toggle('at-start', x <= 2);
            row.classList.toggle('at-end', x >= max - 2);
        }
        function queue() { if (!queued) { queued = true; requestAnimationFrame(update); } }
        var activeSel = row.getAttribute('data-hscroll-active');
        var active = activeSel ? row.querySelector(activeSel) : null;
        if (active && row.scrollWidth > row.clientWidth) {
            var a = active.getBoundingClientRect(), r = row.getBoundingClientRect();
            // only when the pill is hidden or under an edge fade
            if (a.left < r.left + 40 || a.right > r.right - 64) {
                row.scrollLeft += (a.left + a.width / 2) - (r.left + r.width / 2);
            }
        }
        update();
        if (!reduceMotion && row.classList.contains('is-overflowing') && !row.classList.contains('at-end')) {
            next.classList.add('is-nudging');
            next.addEventListener('animationend', function () { next.classList.remove('is-nudging'); });
        }
        // Keyboard: a link that Tab moves to is always shown whole, clear of the edge fades. Browsers do not
        // scroll a partly visible link, so align it with the row's snap position (no fight with scroll-snap).
        row.addEventListener('focusin', function (e) {
            var el = e.target;
            // keyboard only: scrolling a link the mouse is pressing moves it away before the button is released,
            // and the click (and the visit) is lost
            var keyboard = true;
            try { keyboard = el.matches(':focus-visible'); } catch (err) { /* older browsers: always align */ }
            if (!keyboard) { return; }
            requestAnimationFrame(function () {
                var a = el.getBoundingClientRect(), r = row.getBoundingClientRect();
                if (a.left < r.left + 34 || a.right > r.right - 60) {
                    row.scrollLeft += a.left - (r.left + (parseFloat(getComputedStyle(row).scrollPaddingLeft) || 0));
                }
            });
        });
        row.addEventListener('scroll', queue, { passive: true });
        window.addEventListener('resize', queue);
        if ('ResizeObserver' in window) { new ResizeObserver(queue).observe(row); }
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(queue); }
    });

    /* ---------- Sticky glass header, scroll progress, back to top, parallax ---------- */
    var header = document.getElementById('site-header');
    var backTop = document.querySelector('[data-back-to-top]');
    var parallaxEls = reduceMotion ? [] : Array.prototype.slice.call(document.querySelectorAll('[data-parallax]'));
    var ticking = false;
    function onScroll() {
        var y = window.scrollY || window.pageYOffset;
        var max = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
        root.style.setProperty('--scroll', Math.min(1, y / max).toFixed(4));
        if (header) { header.classList.toggle('is-scrolled', y > 10); }
        if (backTop) { backTop.classList.toggle('is-visible', y > 500); }
        parallaxEls.forEach(function (el) {
            var speed = parseFloat(el.getAttribute('data-parallax')) || 0.15;
            var rect = el.getBoundingClientRect();
            if (rect.bottom < -200 || rect.top > window.innerHeight + 200) { return; }
            var offset = (rect.top + rect.height / 2 - window.innerHeight / 2) * speed * -1;
            el.style.transform = 'translate3d(0,' + offset.toFixed(1) + 'px,0)';
        });
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { ticking = true; requestAnimationFrame(onScroll); }
    }, { passive: true });
    onScroll();
    if (backTop) {
        backTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        });
    }

    /* ---------- Dismissible alerts ---------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.alert-close');
        if (btn) {
            var alert = btn.closest('.alert');
            if (alert) { alert.remove(); }
        }
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

    /* ---------- Hero slider ---------- */
    document.querySelectorAll('[data-slider]').forEach(function (slider) {
        var slides = slider.querySelectorAll('.hero-slide');
        var dotsWrap = slider.querySelector('.hero-dots');
        if (slides.length < 2) { return; }
        var index = 0;
        var timer = null;
        var dots = [];

        slides.forEach(function (slide, i) {
            var dot = document.createElement('button');
            dot.type = 'button';
            dot.setAttribute('aria-label', 'Show slide ' + (i + 1));
            dot.addEventListener('click', function () { go(i); restart(); });
            dotsWrap.appendChild(dot);
            dots.push(dot);
        });

        function go(i) {
            slides[index].classList.remove('is-active');
            slides[index].setAttribute('aria-hidden', 'true');
            dots[index].classList.remove('is-active');
            dots[index].removeAttribute('aria-current');
            index = (i + slides.length) % slides.length;
            slides[index].classList.add('is-active');
            slides[index].removeAttribute('aria-hidden');
            dots[index].classList.add('is-active');
            dots[index].setAttribute('aria-current', 'true');
        }
        function restart() {
            if (timer) { clearInterval(timer); }
            timer = null;
            if (!reduceMotion && !(typeof paused !== 'undefined' && paused)) { timer = setInterval(function () { go(index + 1); }, 6500); }
        }

        slides.forEach(function (s, i) { if (i !== 0) { s.setAttribute('aria-hidden', 'true'); } });
        dots[0].classList.add('is-active');
        dots[0].setAttribute('aria-current', 'true');

        var prev = slider.querySelector('.hero-prev');
        var next = slider.querySelector('.hero-next');
        if (prev) { prev.addEventListener('click', function () { go(index - 1); restart(); }); }
        if (next) { next.addEventListener('click', function () { go(index + 1); restart(); }); }
        var paused = false;
        var stop = function () { if (timer) { clearInterval(timer); timer = null; } };
        slider.addEventListener('mouseenter', stop);
        slider.addEventListener('mouseleave', function () { if (!paused) { restart(); } });
        slider.addEventListener('focusin', stop);
        slider.addEventListener('focusout', function (e) { if (!paused && !slider.contains(e.relatedTarget)) { restart(); } });
        if (!reduceMotion && dotsWrap) {
            var pauseBtn = document.createElement('button');
            pauseBtn.type = 'button';
            pauseBtn.className = 'hero-pause';
            pauseBtn.setAttribute('aria-label', 'Pause slideshow');
            pauseBtn.innerHTML = '<span aria-hidden="true">&#10074;&#10074;</span>';
            pauseBtn.addEventListener('click', function () {
                paused = !paused;
                pauseBtn.setAttribute('aria-label', paused ? 'Play slideshow' : 'Pause slideshow');
                pauseBtn.innerHTML = paused ? '<span aria-hidden="true">&#9654;</span>' : '<span aria-hidden="true">&#10074;&#10074;</span>';
                if (paused) { stop(); } else { restart(); }
            });
            dotsWrap.appendChild(pauseBtn);
        }

        // swipe support
        var startX = null;
        slider.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
        slider.addEventListener('touchend', function (e) {
            if (startX === null) { return; }
            var dx = e.changedTouches[0].clientX - startX;
            if (Math.abs(dx) > 50) { go(index + (dx < 0 ? 1 : -1)); restart(); }
            startX = null;
        });
        restart();
    });

    /* ---------- Filter chips (scoped to their own section) ---------- */
    document.querySelectorAll('.filter-btn[data-filter]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var f = btn.getAttribute('data-filter');
            var bar = btn.closest('[data-filter-group], .filter-bar') || btn.parentElement;
            var targetSel = bar.getAttribute('data-target');
            var scope = (targetSel && document.querySelector(targetSel)) || bar.closest('section') || document;
            bar.querySelectorAll('.filter-btn[data-filter]').forEach(function (b) {
                b.classList.toggle('is-active', b === btn);
                b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
            });
            scope.querySelectorAll('[data-category]').forEach(function (item) {
                item.hidden = !(f === 'all' || (' ' + item.getAttribute('data-category') + ' ').indexOf(' ' + f + ' ') !== -1);
            });
        });
    });

    /* ---------- Lightbox ---------- */
    var lightbox = document.getElementById('lightbox');
    var lbItems = [];
    var lbIndex = 0;

    function visibleLightboxItems() {
        return Array.prototype.filter.call(document.querySelectorAll('[data-lightbox]'), function (el) { return !el.hidden; });
    }
    function showLightbox(i) {
        if (!lightbox || !lbItems.length) { return; }
        lbIndex = (i + lbItems.length) % lbItems.length;
        var item = lbItems[lbIndex];
        var img = lightbox.querySelector('img');
        img.src = item.getAttribute('href');
        img.alt = item.getAttribute('data-caption') || '';
        var caption = item.getAttribute('data-caption') || '';
        lightbox.querySelector('figcaption').textContent = 'Photo ' + (lbIndex + 1) + ' of ' + lbItems.length + (caption ? ': ' + caption : '');
        lightbox.hidden = false;
        body.classList.add('no-scroll');
        lightbox.querySelector('.lightbox-close').focus();
    }
    function closeLightbox() {
        if (lightbox && !lightbox.hidden) {
            lightbox.hidden = true;
            body.classList.remove('no-scroll');
            if (lbItems[lbIndex]) { lbItems[lbIndex].focus(); }
        }
    }
    document.addEventListener('click', function (e) {
        var link = e.target.closest('[data-lightbox]');
        if (link && lightbox) {
            e.preventDefault();
            lbItems = visibleLightboxItems();
            showLightbox(lbItems.indexOf(link));
        }
    });
    if (lightbox) {
        lightbox.querySelector('.lightbox-close').addEventListener('click', closeLightbox);
        lightbox.querySelector('.lightbox-prev').addEventListener('click', function () { showLightbox(lbIndex - 1); });
        lightbox.querySelector('.lightbox-next').addEventListener('click', function () { showLightbox(lbIndex + 1); });
        lightbox.addEventListener('click', function (e) { if (e.target === lightbox) { closeLightbox(); } });
        document.addEventListener('keydown', function (e) {
            if (lightbox.hidden) { return; }
            if (e.key === 'Tab') {
                // aria-modal: keep keyboard focus on the lightbox controls
                var f = Array.prototype.slice.call(lightbox.querySelectorAll('button'));
                var i = f.indexOf(document.activeElement);
                e.preventDefault();
                if (i === -1) { (e.shiftKey ? f[f.length - 1] : f[0]).focus(); }
                else { f[(i + (e.shiftKey ? f.length - 1 : 1)) % f.length].focus(); }
                return;
            }
            if (e.key === 'ArrowLeft') { showLightbox(lbIndex - 1); }
            if (e.key === 'ArrowRight') { showLightbox(lbIndex + 1); }
        });
    }

    /* ---------- Donation amount presets ---------- */
    var amountInput = document.getElementById('amount');
    document.querySelectorAll('input[name="preset_amount"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (amountInput && radio.checked) {
                amountInput.value = radio.value;
                amountInput.dispatchEvent(new Event('input', { bubbles: true }));
                updateDonateSummary();
            }
        });
    });
    if (amountInput) {
        amountInput.addEventListener('input', function () {
            document.querySelectorAll('input[name="preset_amount"]').forEach(function (r) {
                r.checked = r.value === amountInput.value;
            });
            updateDonateSummary();
        });
    }
    function updateDonateSummary() {
        var out = document.querySelector('[data-amount-preview]');
        if (!out || !amountInput) { return; }
        var v = parseFloat(amountInput.value);
        out.textContent = v > 0 ? '₹' + Math.round(v).toLocaleString('en-IN') : '₹0';
    }
    updateDonateSummary();

    /* ---------- Razorpay checkout ---------- */
    var payBtn = document.getElementById('rzp-pay');
    if (payBtn) {
        var openCheckout = function () {
            if (typeof window.Razorpay !== 'function') {
                alert('The payment window could not be loaded. Please check your internet connection and try again.');
                return;
            }
            var d = payBtn.dataset;
            var verifyForm = document.getElementById('rzp-verify-form');
            var failForm = document.getElementById('rzp-fail-form');
            var paymentFailed = false;
            var options = {
                key: d.key,
                amount: d.amount,
                currency: d.currency || 'INR',
                name: d.name,
                description: d.description,
                image: d.image,
                order_id: d.order,
                prefill: { name: d.prefillName, email: d.prefillEmail, contact: d.prefillContact },
                notes: { donation_ref: d.ref },
                theme: { color: '#146B43' },
                handler: function (response) {
                    verifyForm.querySelector('[name="razorpay_payment_id"]').value = response.razorpay_payment_id;
                    verifyForm.querySelector('[name="razorpay_order_id"]').value = response.razorpay_order_id;
                    verifyForm.querySelector('[name="razorpay_signature"]').value = response.razorpay_signature;
                    payBtn.disabled = true;
                    payBtn.textContent = 'Verifying payment\u2026';
                    verifyForm.submit();
                },
                modal: {
                    ondismiss: function () {
                        // A failed attempt is reported only when the visitor closes the window
                        // (Razorpay lets them retry with another method before that).
                        if (paymentFailed && failForm) {
                            failForm.submit();
                            return;
                        }
                        payBtn.disabled = false;
                    }
                }
            };
            var rzp = new window.Razorpay(options);
            rzp.on('payment.failed', function (response) {
                paymentFailed = true;
                if (failForm && response && response.error) {
                    failForm.querySelector('[name="error_description"]').value = (response.error.description || 'Payment failed').substring(0, 250);
                    failForm.querySelector('[name="razorpay_payment_id"]').value = (response.error.metadata && response.error.metadata.payment_id) || '';
                }
            });
            payBtn.disabled = true;
            rzp.open();
        };
        payBtn.addEventListener('click', openCheckout);
        if (payBtn.hasAttribute('data-autoopen')) {
            window.addEventListener('load', function () { setTimeout(openCheckout, 400); });
        }
    }

    /* ---------- Animated counters ---------- */
    var counters = document.querySelectorAll('[data-count]');
    if (counters.length && 'IntersectionObserver' in window && !reduceMotion) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                var el = entry.target;
                io.unobserve(el);
                var target = parseFloat(el.getAttribute('data-count')) || 0;
                var prefix = el.getAttribute('data-prefix') || '';
                var finalText = el.getAttribute('data-format');
                var start = null;
                var duration = 1400;
                function step(ts) {
                    if (!start) { start = ts; }
                    var p = Math.min(1, (ts - start) / duration);
                    var eased = 1 - Math.pow(1 - p, 3);
                    el.textContent = prefix + Math.round(target * eased).toLocaleString('en-IN');
                    if (p < 1) { requestAnimationFrame(step); } else if (finalText) { el.textContent = finalText; }
                }
                requestAnimationFrame(step);
            });
        }, { threshold: 0.4 });
        counters.forEach(function (c) { io.observe(c); });
    }

    /* ---------- Copy link ---------- */
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy');
            var done = function () {
                if (btn.hasAttribute('data-tip')) {
                    var oldTip = btn.getAttribute('data-tip');
                    btn.setAttribute('data-tip', 'Copied!');
                    setTimeout(function () { btn.setAttribute('data-tip', oldTip); }, 1800);
                    toast('Link copied to clipboard', 'success');
                    return;
                }
                var old = btn.innerHTML;
                btn.textContent = 'Link copied';
                setTimeout(function () { btn.innerHTML = old; }, 1800);
            };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
                done();
            }
        });
    });

    /* ---------- Print buttons ---------- */
    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.print(); });
    });

    /* ---------- In-page navigation: highlight the section in view ---------- */
    var subLinks = document.querySelectorAll('.subnav a[href^="#"]');
    if (subLinks.length && 'IntersectionObserver' in window) {
        var map = {};
        subLinks.forEach(function (a) {
            var target = document.getElementById(a.getAttribute('href').slice(1));
            if (target) { map[target.id] = a; }
        });
        var spy = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting && map[entry.target.id]) {
                    subLinks.forEach(function (l) { l.classList.remove('is-active'); });
                    map[entry.target.id].classList.add('is-active');
                    var nav = map[entry.target.id].closest('ul');
                    if (nav) { nav.scrollTo({ left: map[entry.target.id].offsetLeft - 20, behavior: reduceMotion ? 'auto' : 'smooth' }); }
                }
            });
        }, { rootMargin: '-45% 0px -50% 0px' });
        Object.keys(map).forEach(function (id) { spy.observe(document.getElementById(id)); });
    }

    /* ---------- Confirm before destructive actions ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg = form.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    /* =================================================================
       Design system interactions
       ================================================================= */

    /* ---------- Toast notifications (flash messages + JS) ---------- */
    var toastStack = document.querySelector('[data-toasts]');
    function dismissToast(el) {
        if (!el || el.classList.contains('is-leaving')) { return; }
        el.classList.add('is-leaving');
        setTimeout(function () { el.remove(); }, reduceMotion ? 0 : 360);
    }
    function scheduleToast(el) {
        var ms = el.classList.contains('alert-error') ? 12000 : 7000;
        var t = setTimeout(function () { dismissToast(el); }, ms);
        el.addEventListener('mouseenter', function () { clearTimeout(t); });
        el.addEventListener('focusin', function () { clearTimeout(t); });
    }
    function toast(message, type) {
        if (!toastStack) {
            toastStack = document.createElement('div');
            toastStack.className = 'flash-wrap toast-stack';
            toastStack.setAttribute('data-toasts', '');
            body.appendChild(toastStack);
        }
        var el = document.createElement('div');
        el.className = 'alert alert-' + (type || 'info');
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        var text = document.createElement('div');
        text.textContent = message;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'alert-close';
        close.setAttribute('aria-label', 'Dismiss');
        close.innerHTML = '&times;';
        el.appendChild(text);
        el.appendChild(close);
        toastStack.appendChild(el);
        scheduleToast(el);
    }
    window.pwfToast = toast;
    if (toastStack) {
        toastStack.querySelectorAll('.alert').forEach(scheduleToast);
    }

    /* ---------- Scroll reveal ---------- */
    if (root.classList.contains('js-motion')) {
        var revealIO = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    revealIO.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
        document.querySelectorAll('.reveal').forEach(function (el) { revealIO.observe(el); });
        // never leave content hidden (e.g. printing, very tall elements)
        setTimeout(function () {
            document.querySelectorAll('.reveal:not(.is-visible)').forEach(function (el) {
                var r = el.getBoundingClientRect();
                if (r.top < window.innerHeight) { el.classList.add('is-visible'); }
            });
        }, 1800);
        window.pwfRevealAll = function () {
            document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('is-visible'); });
        };
        window.addEventListener('beforeprint', function () {
            document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('is-visible'); });
        });
    }

    /* ---------- 3D tilt on cards (mouse only) ---------- */
    if (finePointer && !reduceMotion) {
        document.querySelectorAll('.tilt').forEach(function (card) {
            card.addEventListener('pointermove', function (e) {
                var r = card.getBoundingClientRect();
                var x = (e.clientX - r.left) / r.width - 0.5;
                var y = (e.clientY - r.top) / r.height - 0.5;
                card.style.setProperty('--ry', (x * 6).toFixed(2) + 'deg');
                card.style.setProperty('--rx', (y * -6).toFixed(2) + 'deg');
            });
            card.addEventListener('pointerleave', function () {
                card.style.setProperty('--ry', '0deg');
                card.style.setProperty('--rx', '0deg');
            });
        });
    }

    /* ---------- Button ripple ---------- */
    if (!reduceMotion) {
        document.addEventListener('pointerdown', function (e) {
            var btn = e.target.closest('.btn');
            if (!btn || btn.disabled) { return; }
            var r = btn.getBoundingClientRect();
            var size = Math.max(r.width, r.height);
            var span = document.createElement('span');
            span.className = 'ripple';
            span.style.width = span.style.height = size + 'px';
            span.style.left = (e.clientX - r.left - size / 2) + 'px';
            span.style.top = (e.clientY - r.top - size / 2) + 'px';
            btn.appendChild(span);
            setTimeout(function () { span.remove(); }, 650);
        });
    }

    /* ---------- Loading state on form submit (prevents double submission) ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || form.hasAttribute('data-no-loading') || form.matches('[data-help-form]')) { return; }
        var btn = e.submitter || form.querySelector('button[type="submit"], button:not([type])');
        if (btn && btn.classList.contains('btn')) {
            setTimeout(function () { btn.classList.add('is-loading'); btn.setAttribute('aria-busy', 'true'); }, 0);
            setTimeout(function () { btn.classList.remove('is-loading'); btn.removeAttribute('aria-busy'); }, 15000);
        }
    });
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('.btn.is-loading').forEach(function (b) { b.classList.remove('is-loading'); });
    });

    /* ---------- Lazy image fade-in ---------- */
    document.querySelectorAll('img[loading="lazy"]').forEach(function (img) {
        if (img.complete && img.naturalWidth) { return; }
        img.classList.add('lazy-fade');
        img.addEventListener('load', function () { img.classList.add('is-loaded'); }, { once: true });
        img.addEventListener('error', function () { img.classList.add('is-loaded'); }, { once: true });
    });

    /* ---------- Progress bars animate when visible ---------- */
    var bars = document.querySelectorAll('[data-progress]');
    if (bars.length && root.classList.contains('js-motion')) {
        var barIO = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                var bar = entry.target;
                barIO.unobserve(bar);
                requestAnimationFrame(function () { bar.style.width = bar.getAttribute('data-progress') + '%'; });
            });
        }, { threshold: 0.3 });
        bars.forEach(function (bar) { bar.style.width = '0%'; barIO.observe(bar); });
    }

    /* ---------- Countdown timers (real end dates only) ---------- */
    document.querySelectorAll('[data-countdown]').forEach(function (el) {
        var end = Date.parse(el.getAttribute('data-countdown'));
        if (isNaN(end)) { return; }
        var units = {};
        el.querySelectorAll('[data-unit]').forEach(function (u) { units[u.getAttribute('data-unit')] = u; });
        function pad(n) { return n < 10 ? '0' + n : String(n); }
        function tick() {
            var diff = Math.max(0, end - Date.now());
            var s = Math.floor(diff / 1000);
            if (units.d) { units.d.textContent = Math.floor(s / 86400); }
            if (units.h) { units.h.textContent = pad(Math.floor(s % 86400 / 3600)); }
            if (units.m) { units.m.textContent = pad(Math.floor(s % 3600 / 60)); }
            if (units.s) { units.s.textContent = pad(s % 60); }
            if (diff <= 0) { clearInterval(timer); el.classList.add('is-ended'); }
        }
        var timer = setInterval(tick, 1000);
        tick();
    });

    /* ---------- Modals (<dialog>) ---------- */
    function openModal(dlg) {
        if (!dlg) { return; }
        dlg.__opener = document.activeElement;
        if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
        body.classList.add('no-scroll');
    }
    function closeModal(dlg) {
        if (!dlg) { return; }
        if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.removeAttribute('open'); }
    }
    document.querySelectorAll('dialog.modal').forEach(function (dlg) {
        dlg.addEventListener('close', function () {
            body.classList.remove('no-scroll');
            if (dlg.__opener && dlg.__opener.focus) { dlg.__opener.focus(); }
            dlg.dispatchEvent(new CustomEvent('modal:closed'));
        });
        dlg.addEventListener('click', function (e) { if (e.target === dlg) { closeModal(dlg); } });
    });
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-modal-open]');
        if (opener) {
            e.preventDefault();
            openModal(document.getElementById(opener.getAttribute('data-modal-open')));
            return;
        }
        var closer = e.target.closest('[data-modal-close]');
        if (closer) { closeModal(closer.closest('dialog')); }
    });
    document.querySelectorAll('dialog.modal.auto-open').forEach(function (dlg) { setTimeout(function () { openModal(dlg); }, 500); });

    /* ---------- Storage helpers (never required for the site to work) ---------- */
    function store(kind, key, value) {
        try {
            var s = kind === 'session' ? window.sessionStorage : window.localStorage;
            if (value === undefined) { return s.getItem(key); }
            s.setItem(key, value);
        } catch (err) { return null; }
        return null;
    }

    /* ---------- Fraud alert (only rendered when enabled by an administrator) ---------- */
    var fraud = document.querySelector('[data-fraud-alert]');
    if (fraud) {
        var fKey = 'pwf_fraud_seen_' + fraud.getAttribute('data-fraud-alert');
        var mode = fraud.getAttribute('data-show');
        var seen = mode === 'once_per_version' ? store('local', fKey) : store('session', fKey);
        if (!seen && !document.querySelector('dialog.modal.auto-open')) {
            setTimeout(function () { openModal(fraud); }, 900);
        }
        fraud.addEventListener('modal:closed', function () {
            store(mode === 'once_per_version' ? 'local' : 'session', fKey, '1');
        });
    }

    /* ---------- Announcement bar ---------- */
    var ann = document.querySelector('[data-announce]');
    if (ann) {
        var aKey = 'pwf_announce_' + ann.getAttribute('data-announce');
        if (store('session', aKey)) { ann.remove(); }
        var aClose = ann.querySelector('[data-announce-close]');
        if (aClose) {
            aClose.addEventListener('click', function () { store('session', aKey, '1'); ann.remove(); });
        }
    }

    /* ---------- Help assistant ---------- */
    var helpPanel = document.getElementById('help-panel');
    if (helpPanel) {
        var helpLog = helpPanel.querySelector('.help-log');
        var helpForm = helpPanel.querySelector('[data-help-form]');
        var helpInput = helpPanel.querySelector('input[name="q"]');
        var helpBtns = document.querySelectorAll('[data-help-toggle]');
        var setHelp = function (open) {
            var ae = document.activeElement;
            var restore = !open && (!ae || ae === document.body || helpPanel.contains(ae));
            helpPanel.hidden = !open;
            helpBtns.forEach(function (b) { if (b.classList.contains('fab')) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); } });
            if (open) { setTimeout(function () { helpInput.focus(); }, 50); }
            else if (restore) { var fab = document.querySelector('.fab-help'); if (fab) { fab.focus(); } }
        };
        helpBtns.forEach(function (b) { b.addEventListener('click', function () { setHelp(helpPanel.hidden); }); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !helpPanel.hidden) { setHelp(false); } });
        var addMsg = function (who, build) {
            var msg = document.createElement('div');
            msg.className = 'help-msg ' + who;
            build(msg);
            helpLog.appendChild(msg);
            helpLog.scrollTop = helpLog.scrollHeight;
            return msg;
        };
        var para = function (parent, text) {
            var p = document.createElement('p');
            p.textContent = text;
            parent.appendChild(p);
            return p;
        };
        var link = function (parent, label, href) {
            var a = document.createElement('a');
            a.href = href;
            a.textContent = label;
            var p = document.createElement('p');
            p.appendChild(a);
            parent.appendChild(p);
        };
        helpForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var q = helpInput.value.trim();
            if (!q) { return; }
            helpInput.value = '';
            addMsg('user', function (m) { para(m, q); });
            var typing = addMsg('bot', function (m) { m.classList.add('help-typing'); m.innerHTML = '<span></span><span></span><span></span>'; });
            fetch(helpPanel.getAttribute('data-endpoint') + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    typing.remove();
                    addMsg('bot', function (m) {
                        (data.answers || []).forEach(function (a) {
                            var p = para(m, a.a);
                            var strong = document.createElement('span');
                            strong.className = 'help-q';
                            strong.textContent = a.q;
                            p.insertBefore(strong, p.firstChild);
                        });
                        (data.links || []).forEach(function (l) { link(m, l.label + ' →', l.url); });
                        if (data.fallback) {
                            para(m, data.fallback);
                            link(m, 'Browse all FAQs →', data.faq_url);
                            link(m, 'Contact our team →', data.contact_url);
                        }
                    });
                })
                .catch(function () {
                    typing.remove();
                    addMsg('bot', function (m) { para(m, 'Sorry, something went wrong. Please try again or contact us.'); });
                });
        });
    }

    /* ---------- Analytics consent (only when Google Analytics is configured) ---------- */
    var consent = document.querySelector('[data-consent-banner]');
    if (consent) {
        var gaId = consent.getAttribute('data-ga');
        var loadGa = function () {
            if (!/^G-[A-Z0-9]{4,15}$/.test(gaId) || window.__gaLoaded) { return; }
            window.__gaLoaded = true;
            var sc = document.createElement('script');
            sc.async = true;
            sc.src = 'https://www.googletagmanager.com/gtag/js?id=' + gaId;
            document.head.appendChild(sc);
            window.dataLayer = window.dataLayer || [];
            window.gtag = function () { window.dataLayer.push(arguments); };
            window.gtag('js', new Date());
            window.gtag('config', gaId, { anonymize_ip: true });
        };
        var disableGa = function () {
            if (!/^G-[A-Z0-9]{4,15}$/.test(gaId)) { return; }
            window['ga-disable-' + gaId] = true;
            // remove Google Analytics cookies (_ga, _ga_*) on this host and its parent domain
            var host = location.hostname;
            var domains = ['', '; domain=' + host, '; domain=.' + host];
            var parts = host.split('.');
            if (parts.length > 2) { domains.push('; domain=.' + parts.slice(-2).join('.')); }
            document.cookie.split(';').forEach(function (c) {
                var name = c.split('=')[0].trim();
                if (name === '_ga' || name.indexOf('_ga_') === 0) {
                    domains.forEach(function (d) { document.cookie = name + '=; Max-Age=0; path=/' + d; });
                }
            });
        };
        var choice = store('local', 'pwf_consent');
        if (choice === 'yes') { loadGa(); } else if (choice === 'no') { disableGa(); } else { consent.hidden = false; }
        consent.querySelectorAll('[data-consent]').forEach(function (b) {
            b.addEventListener('click', function () {
                var v = b.getAttribute('data-consent');
                var before = store('local', 'pwf_consent');
                store('local', 'pwf_consent', v);
                consent.hidden = true;
                if (v === 'yes') { loadGa(); } else { disableGa(); if (before === 'yes' || window.__gaLoaded) { window.location.reload(); } }
            });
        });
        // "Cookie settings" in the footer: the analytics choice can be changed at any time
        document.querySelectorAll('[data-consent-reset]').forEach(function (btn) {
            btn.hidden = false;
            btn.addEventListener('click', function () {
                try { window.localStorage.removeItem('pwf_consent'); } catch (err) { /* storage blocked */ }
                consent.hidden = false;
                var first = consent.querySelector('[data-consent]');
                if (first) { first.focus(); }
            });
        });
    }

})();
