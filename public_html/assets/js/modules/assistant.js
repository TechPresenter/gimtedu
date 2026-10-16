/**
 * AI website assistant - chat panel (rendered by render_assistant_widget() in includes/assistant.php).
 * Opened by the floating help button ([data-ai-toggle]). Talks to /api/chat/ (POST JSON + CSRF header).
 * Bot replies are HTML that the SERVER has already sanitised; it is parsed in an inert <template> and
 * checked again here (allow-listed tags/attributes only) before it is shown. Visitor text is shown with
 * textContent only. Accessible: dialog semantics, focus trap + focus return, Escape closes, live log.
 */
(function () {
    'use strict';
    var panel = document.getElementById('ai-assistant');
    if (!panel) { return; }

    var endpoint = panel.getAttribute('data-endpoint');
    var csrf = panel.getAttribute('data-csrf') || '';
    var maxLen = parseInt(panel.getAttribute('data-max'), 10) || 600;
    var log = panel.querySelector('.ai-log');
    var form = panel.querySelector('[data-ai-form]');
    var input = panel.querySelector('#ai-input');
    var sendBtn = panel.querySelector('.ai-send');
    var chips = panel.querySelector('.ai-chips');
    var statusEl = panel.querySelector('.ai-status');
    var countEl = panel.querySelector('.ai-count');
    var hindiUi = /[ऀ-ॿ]/;
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var opener = null;
    var busy = false;
    var historyLoaded = false;
    var lastQuestion = '';
    var statusKind = '';

    var T = {
        you: 'You: ',
        bot: 'Assistant: ',
        typing: 'Assistant is typing…',
        offline: 'You are offline. The assistant will work again when your connection is back.',
        tooLong: 'Please keep your question under ' + maxLen + ' characters.',
        failed: 'Sorry, the assistant could not be reached. Please try again in a moment.',
        expired: 'Your session has expired. Please reload the page and try again.',
        retry: 'Try again',
        reload: 'Reload page',
        cleared: 'Chat cleared.'
    };

    /* ---------- open / close ---------- */
    function toggles() { return document.querySelectorAll('[data-ai-toggle]'); }
    function setExpanded(open) {
        toggles().forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    }
    function isOpen() { return !panel.hidden; }

    function openPanel(from) {
        if (isOpen()) { input.focus(); return; }
        opener = from && from.focus ? from : document.activeElement;
        panel.hidden = false;
        document.documentElement.classList.add('ai-open');
        setExpanded(true);
        fitViewport();
        if (reduceMotion) {
            panel.classList.add('is-open');
        } else {
            window.requestAnimationFrame(function () { panel.classList.add('is-open'); });
        }
        if (!historyLoaded) { loadHistory(); }
        updateOnline();
        window.setTimeout(function () { input.focus(); scrollDown(); }, reduceMotion ? 0 : 80);
    }

    function closePanel() {
        if (!isOpen()) { return; }
        panel.classList.remove('is-open');
        panel.hidden = true;
        document.documentElement.classList.remove('ai-open');
        setExpanded(false);
        var target = opener && opener !== document.body && document.documentElement.contains(opener) ? opener : toggles()[0];
        if (target && target.focus) { target.focus(); }
    }

    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-ai-toggle], [data-help-toggle], a[href="#ai-assistant"]') : null;
        if (!t) { return; }
        // the FAQ helper owns [data-help-toggle] whenever it is on the page
        if (t.hasAttribute('data-help-toggle') && document.getElementById('help-panel')) { return; }
        e.preventDefault();
        if (isOpen() && t.hasAttribute('data-ai-toggle')) { closePanel(); } else { openPanel(t); }
    });
    panel.querySelector('[data-ai-close]').addEventListener('click', closePanel);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen() && !e.defaultPrevented) {
            e.preventDefault();
            closePanel();
        }
    });

    /* ---------- focus trap ---------- */
    function focusables() {
        var list = panel.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])');
        return Array.prototype.filter.call(list, function (el) {
            return !el.hidden && (el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement);
        });
    }
    panel.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') { return; }
        var f = focusables();
        if (!f.length) { return; }
        var first = f[0];
        var last = f[f.length - 1];
        var active = document.activeElement;
        if (e.shiftKey && (active === first || !panel.contains(active))) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && (active === last || !panel.contains(active))) {
            e.preventDefault();
            first.focus();
        }
    });

    /* ---------- mobile: follow the visual viewport (on-screen keyboard) ---------- */
    function fitViewport() {
        if (!window.visualViewport) { return; }
        if (window.innerWidth <= 600) {
            panel.style.setProperty('--ai-vh', Math.round(window.visualViewport.height) + 'px');
        } else {
            panel.style.removeProperty('--ai-vh');
        }
    }
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function () { if (isOpen()) { fitViewport(); scrollDown(); } });
    }

    /* ---------- safe HTML from the server ---------- */
    var ALLOWED = { P: 1, BR: 1, STRONG: 1, EM: 1, UL: 1, OL: 1, LI: 1, A: 1, SPAN: 1 };
    function harden(root) {
        Array.prototype.slice.call(root.querySelectorAll('*')).forEach(function (el) {
            if (!ALLOWED[el.tagName]) {
                el.replaceWith(document.createTextNode(el.textContent || ''));
                return;
            }
            Array.prototype.slice.call(el.attributes).forEach(function (a) {
                var n = a.name.toLowerCase();
                if (['href', 'target', 'rel', 'class'].indexOf(n) === -1) { el.removeAttribute(a.name); }
            });
            if (el.tagName === 'A') {
                var href = el.getAttribute('href') || '';
                if (!/^(\/(?![\/\\])|https:\/\/|tel:|mailto:)/i.test(href)) {
                    el.replaceWith(document.createTextNode(el.textContent || ''));
                    return;
                }
                if (/^https:/i.test(href)) {
                    el.setAttribute('target', '_blank');
                    el.setAttribute('rel', 'noopener noreferrer');
                } else {
                    el.removeAttribute('target');
                }
            }
        });
        return root;
    }
    function safeFragment(html) {
        var tpl = document.createElement('template');
        tpl.innerHTML = String(html || '');   // inert: nothing in a template runs or loads
        return harden(tpl.content);
    }

    /* ---------- messages ---------- */
    function scrollDown() { log.scrollTop = log.scrollHeight; }

    function addMsg(who, opts) {
        opts = opts || {};
        var m = document.createElement('div');
        m.className = 'ai-msg ' + who + (opts.cls ? ' ' + opts.cls : '');
        var sr = document.createElement('span');
        sr.className = 'sr-only';
        sr.textContent = who === 'user' ? T.you : T.bot;
        m.appendChild(sr);
        if (opts.text !== undefined) {
            var p = document.createElement('p');
            p.textContent = opts.text;
            if (hindiUi.test(opts.text)) { p.setAttribute('lang', 'hi'); p.className = 'hindi'; }
            m.appendChild(p);
        }
        if (opts.html !== undefined) {
            var d = document.createElement('div');
            d.className = 'ai-rich';
            d.appendChild(safeFragment(opts.html));
            m.appendChild(d);
        }
        log.appendChild(m);
        scrollDown();
        return m;
    }

    function showTyping() {
        var m = document.createElement('div');
        m.className = 'ai-msg bot ai-typing';
        var sr = document.createElement('span');
        sr.className = 'sr-only';
        sr.textContent = T.typing;
        m.appendChild(sr);
        for (var i = 0; i < 3; i++) {
            var dot = document.createElement('span');
            dot.className = 'ai-dot';
            dot.setAttribute('aria-hidden', 'true');
            m.appendChild(dot);
        }
        log.appendChild(m);
        scrollDown();
        return m;
    }

    function addError(text, action) {
        var m = addMsg('bot', { text: text, cls: 'is-error' });
        if (action) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'ai-retry';
            b.textContent = action === 'reload' ? T.reload : T.retry;
            b.addEventListener('click', function () {
                if (action === 'reload') { window.location.reload(); return; }
                m.remove();
                send(lastQuestion, true);
            });
            m.appendChild(b);
        }
        return m;
    }

    function showStatus(text, kind) {
        statusKind = text ? (kind || 'info') : '';
        statusEl.textContent = text || '';
        statusEl.hidden = !text;
        statusEl.className = 'ai-status' + (kind ? ' is-' + kind : '');
    }

    function setBusy(b) {
        busy = b;
        sendBtn.disabled = b || !navigator.onLine;
        panel.classList.toggle('is-busy', b);
        log.setAttribute('aria-busy', b ? 'true' : 'false');
    }

    function hideChips(hide) { if (chips) { chips.hidden = hide; } }

    /* ---------- talk to the server ---------- */
    function post(body) {
        var ctrl = window.AbortController ? new AbortController() : null;
        var timer = window.setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 45000);
        return fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(body),
            signal: ctrl ? ctrl.signal : undefined
        }).then(function (r) {
            window.clearTimeout(timer);
            return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, data: d || {} }; });
        }, function (err) {
            window.clearTimeout(timer);
            throw err;
        });
    }

    function send(text, isRetry) {
        text = String(text || '').trim();
        if (!text || busy) { return; }
        if (text.length > maxLen) { showStatus(T.tooLong, 'error'); return; }
        if (!navigator.onLine) { updateOnline(); return; }
        if (statusKind === 'error' || statusKind === 'info') { showStatus(''); }
        lastQuestion = text;
        hideChips(true);
        if (!isRetry) { addMsg('user', { text: text }); }
        input.value = '';
        autosize();
        updateCount();
        setBusy(true);
        var typing = showTyping();
        post({ message: text, page: window.location.pathname })
            .then(function (res) {
                typing.remove();
                var d = res.data;
                if ((res.status === 200 || res.status === 429) && d.html) {
                    addMsg('bot', { html: d.html, cls: d.fallback ? 'is-fallback' : '' });
                } else if (res.status === 403) {
                    addError(d.message || T.expired, 'reload');
                } else if (res.status === 422) {
                    addError(d.message || T.tooLong, null);
                } else {
                    addError(T.failed, 'retry');
                }
            })
            .catch(function () {
                typing.remove();
                if (!navigator.onLine) { updateOnline(); }
                addError(navigator.onLine ? T.failed : T.offline, 'retry');
            })
            .then(function () {
                setBusy(false);
                if (isOpen()) { input.focus(); }
            });
    }

    function loadHistory() {
        historyLoaded = true;
        fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : {}; })
            .then(function (d) {
                var turns = (d && d.history) || [];
                if (!turns.length || log.querySelectorAll('.ai-msg').length > 1) { return; }
                turns.forEach(function (t) {
                    if (t.role === 'user') { addMsg('user', { text: t.text || '' }); } else { addMsg('bot', { html: t.html || '', cls: t.fallback ? 'is-fallback' : '' }); }
                });
                hideChips(true);
            })
            .catch(function () { historyLoaded = false; });
    }

    function clearChat() {
        Array.prototype.slice.call(log.querySelectorAll('.ai-msg:not(.ai-greeting)')).forEach(function (m) { m.remove(); });
        hideChips(false);
        showStatus(T.cleared, 'info');
        window.setTimeout(function () { if (statusKind === 'info') { showStatus(''); } }, 2500);
        input.focus();
        post({ action: 'clear' }).catch(function () { /* the visible chat is cleared either way */ });
    }
    panel.querySelector('[data-ai-clear]').addEventListener('click', clearChat);

    if (chips) {
        chips.addEventListener('click', function (e) {
            var chip = e.target.closest('[data-ai-ask]');
            if (chip) { send(chip.textContent); }
        });
    }

    /* ---------- input ---------- */
    function autosize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 132) + 'px';
    }
    function updateCount() {
        var n = input.value.length;
        var near = n > maxLen - 100;
        countEl.textContent = near ? n + '/' + maxLen : '';
        countEl.classList.toggle('is-max', n >= maxLen);
    }
    input.addEventListener('input', function () { autosize(); updateCount(); });
    input.addEventListener('keydown', function (e) {
        // Enter sends, Shift+Enter = new line; never while an input method (e.g. Hindi keyboard) is composing
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
            e.preventDefault();
            send(input.value);
        }
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        send(input.value);
    });

    /* ---------- offline state ---------- */
    function updateOnline() {
        if (!navigator.onLine) {
            showStatus(T.offline, 'offline');
            panel.classList.add('is-offline');
        } else {
            panel.classList.remove('is-offline');
            if (statusKind === 'offline') { showStatus(''); }
        }
        sendBtn.disabled = busy || !navigator.onLine;
    }
    window.addEventListener('online', updateOnline);
    window.addEventListener('offline', updateOnline);

    /* ---------- keep the floating buttons above the cookie-consent banner while it is shown ---------- */
    var consent = document.querySelector('[data-consent-banner]');
    if (consent) {
        var syncConsent = function () {
            var visible = !consent.hidden && consent.offsetHeight > 0 && document.documentElement.contains(consent);
            document.documentElement.classList.toggle('ai-consent-open', visible);
            if (visible) { document.documentElement.style.setProperty('--ai-consent-h', consent.offsetHeight + 'px'); }
        };
        if (window.MutationObserver) {
            new MutationObserver(syncConsent).observe(consent, { attributes: true, attributeFilter: ['hidden', 'class', 'style'] });
            new MutationObserver(syncConsent).observe(consent.parentNode, { childList: true });
        }
        if (window.ResizeObserver) { new ResizeObserver(syncConsent).observe(consent); }
        window.addEventListener('resize', syncConsent);
        syncConsent();
    }
}());
