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

    /* ---------- Mobile navigation ---------- */
    var navToggle = document.querySelector('.nav-toggle');
    var mainNav = document.getElementById('main-nav');

    function closeNav() {
        if (!body.classList.contains('nav-open')) { return; }
        body.classList.remove('nav-open');
        if (navToggle) {
            navToggle.setAttribute('aria-expanded', 'false');
            navToggle.setAttribute('aria-label', 'Open menu');
            navToggle.focus();
        }
    }

    if (navToggle && mainNav) {
        document.querySelectorAll('[data-nav-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = body.classList.toggle('nav-open');
                navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                navToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
                if (open) {
                    var first = mainNav.querySelector('.nav-close, a');
                    if (first) { setTimeout(function () { first.focus(); }, 60); }
                } else {
                    navToggle.focus();
                }
            });
        });
        document.addEventListener('click', function (e) {
            if (body.classList.contains('nav-open') && !mainNav.contains(e.target) && !navToggle.contains(e.target)) {
                closeNav();
            }
        });
    }

    document.querySelectorAll('.dropdown-toggle').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var item = btn.closest('.has-dropdown');
            var isOpen = item.classList.toggle('open');
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            document.querySelectorAll('.has-dropdown.open').forEach(function (other) {
                if (other !== item) {
                    other.classList.remove('open');
                    var t = other.querySelector('.dropdown-toggle');
                    if (t) { t.setAttribute('aria-expanded', 'false'); }
                }
            });
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeNav();
            document.querySelectorAll('.has-dropdown.open').forEach(function (el) { el.classList.remove('open'); });
            closeLightbox();
        }
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
            index = (i + slides.length) % slides.length;
            slides[index].classList.add('is-active');
            slides[index].removeAttribute('aria-hidden');
            dots[index].classList.add('is-active');
        }
        function restart() {
            if (timer) { clearInterval(timer); }
            if (!reduceMotion) { timer = setInterval(function () { go(index + 1); }, 6500); }
        }

        slides.forEach(function (s, i) { if (i !== 0) { s.setAttribute('aria-hidden', 'true'); } });
        dots[0].classList.add('is-active');

        var prev = slider.querySelector('.hero-prev');
        var next = slider.querySelector('.hero-next');
        if (prev) { prev.addEventListener('click', function () { go(index - 1); restart(); }); }
        if (next) { next.addEventListener('click', function () { go(index + 1); restart(); }); }
        slider.addEventListener('mouseenter', function () { if (timer) { clearInterval(timer); } });
        slider.addEventListener('mouseleave', restart);

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

    /* ---------- Gallery filter ---------- */
    var filterBtns = document.querySelectorAll('.filter-btn[data-filter]');
    filterBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var f = btn.getAttribute('data-filter');
            filterBtns.forEach(function (b) { b.classList.toggle('is-active', b === btn); b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
            document.querySelectorAll('[data-category]').forEach(function (item) {
                item.hidden = !(f === 'all' || item.getAttribute('data-category') === f);
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
        lightbox.querySelector('figcaption').textContent = item.getAttribute('data-caption') || '';
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
            helpPanel.hidden = !open;
            helpBtns.forEach(function (b) { if (b.classList.contains('fab')) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); } });
            if (open) { setTimeout(function () { helpInput.focus(); }, 50); }
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
        var choice = store('local', 'pwf_consent');
        if (choice === 'yes') { loadGa(); } else if (choice !== 'no') { consent.hidden = false; }
        consent.querySelectorAll('[data-consent]').forEach(function (b) {
            b.addEventListener('click', function () {
                var v = b.getAttribute('data-consent');
                store('local', 'pwf_consent', v);
                consent.hidden = true;
                if (v === 'yes') { loadGa(); }
            });
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && body.classList.contains('nav-open')) { closeNav(); }
    });
})();
