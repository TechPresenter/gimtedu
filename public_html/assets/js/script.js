/* =====================================================================
   Prerit Welfare Foundation - site interactions (vanilla JavaScript)
   ===================================================================== */
(function () {
    'use strict';

    var body = document.body;
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- Mobile navigation ---------- */
    var navToggle = document.querySelector('.nav-toggle');
    var mainNav = document.getElementById('main-nav');

    function closeNav() {
        body.classList.remove('nav-open');
        if (navToggle) {
            navToggle.setAttribute('aria-expanded', 'false');
            navToggle.setAttribute('aria-label', 'Open menu');
        }
    }

    if (navToggle && mainNav) {
        navToggle.addEventListener('click', function () {
            var open = body.classList.toggle('nav-open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            navToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
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

    /* ---------- Sticky header shadow & back to top ---------- */
    var header = document.getElementById('site-header');
    var backTop = document.querySelector('.back-to-top');
    function onScroll() {
        var y = window.scrollY || window.pageYOffset;
        if (header) { header.classList.toggle('is-scrolled', y > 10); }
        if (backTop) { backTop.classList.toggle('is-visible', y > 500); }
    }
    window.addEventListener('scroll', onScroll, { passive: true });
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
                theme: { color: '#1a7f46' },
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
                var start = null;
                var duration = 1400;
                function step(ts) {
                    if (!start) { start = ts; }
                    var p = Math.min(1, (ts - start) / duration);
                    var eased = 1 - Math.pow(1 - p, 3);
                    el.textContent = prefix + Math.round(target * eased).toLocaleString('en-IN');
                    if (p < 1) { requestAnimationFrame(step); }
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

    /* ---------- Confirm before destructive actions ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg = form.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);
})();
