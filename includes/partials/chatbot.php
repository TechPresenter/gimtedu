<?php
/**
 * Website AI chat widget ("GIMT Admission Assistant") — bottom-left launcher + chat window (React island ChatWidget,
 * frontend/site/islands/ChatWidget.tsx). Rendered once per page by includes/footer.php:
 *   require_once __DIR__ . '/partials/chatbot.php'; echo chatbot_widget();
 *
 * Server output = a small, working fallback: the round assistant launcher as a plain link to /contact (no JS needed),
 * plus a tiny inline loader that turns the placeholder into the island only after the page is idle or on the first
 * interaction (pointer / key / scroll / tap on the launcher) — so the chat never competes with LCP and React is not
 * downloaded for visitors who never interact. Settings + API: app/services/chatbot.php, api/routes/chat.php.
 * Page opt-out: site_header([... 'chatbot' => false]).
 */

if (!function_exists('chatbot_widget')) {
    /** Original illustrated assistant avatar (counsellor with headset), 64×64. $uid keeps SVG ids unique. */
    function chatbot_avatar_svg(string $uid = 'gcbs', string $class = 'h-full w-full'): string
    {
        $bg = $uid . 'bg';
        $clip = $uid . 'clip';
        return '<svg viewBox="0 0 64 64" class="' . e($class) . '" aria-hidden="true" focusable="false">'
            . '<defs><linearGradient id="' . $bg . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFF4EE"/><stop offset="1" stop-color="#FAD3C2"/></linearGradient>'
            . '<clipPath id="' . $clip . '"><circle cx="32" cy="32" r="32"/></clipPath></defs>'
            . '<g clip-path="url(#' . $clip . ')"><rect width="64" height="64" fill="url(#' . $bg . ')"/>'
            . '<circle cx="50" cy="13" r="9" fill="#F05A28" opacity=".14"/>'
            . '<path d="M16.5 33c0-12 7-19.5 15.5-19.5S47.5 21 47.5 33v13h-31z" fill="#1E293B"/>'
            . '<path d="M8 64c1.2-11.5 10-18 24-18s22.8 6.5 24 18z" fill="#0B2A5B"/>'
            . '<path d="M25.5 46.5 32 55l6.5-8.5z" fill="#fff"/><path d="M29.5 49.5 32 55l2.5-5.5z" fill="#D03E16"/>'
            . '<path d="M28 40h8v6.5a4 4 0 0 1-8 0z" fill="#E3A27F"/>'
            . '<ellipse cx="32" cy="31.5" rx="10.2" ry="11.6" fill="#F2BC98"/>'
            . '<path d="M21.6 30.5c.6-8.8 5.4-13.3 10.9-13.3 6.2 0 10.2 4.6 10.4 12.1-3.4-.9-7.4-3.6-9.6-7.2-2.3 4.4-6.7 7.6-11.7 8.4z" fill="#1E293B"/>'
            . '<circle cx="27.9" cy="32.4" r="1.35" fill="#1E293B"/><circle cx="36.1" cy="32.4" r="1.35" fill="#1E293B"/>'
            . '<path d="M28.6 36.6c2 1.9 4.8 1.9 6.8 0" stroke="#9A3412" stroke-width="1.5" fill="none" stroke-linecap="round"/>'
            . '<circle cx="25.4" cy="35.4" r="1.7" fill="#F05A28" opacity=".22"/><circle cx="38.6" cy="35.4" r="1.7" fill="#F05A28" opacity=".22"/>'
            . '<path d="M19.6 31.5a12.4 12.4 0 0 1 24.8 0" stroke="#F05A28" stroke-width="2.3" fill="none" stroke-linecap="round"/>'
            . '<rect x="17.6" y="29.2" width="4.8" height="7.6" rx="2.2" fill="#F05A28"/><rect x="41.6" y="29.2" width="4.8" height="7.6" rx="2.2" fill="#F05A28"/>'
            . '<path d="M20.4 36.4c.3 3.6 2.9 5.6 6.9 6" stroke="#F05A28" stroke-width="1.6" fill="none" stroke-linecap="round"/><circle cx="27.9" cy="42.4" r="1.5" fill="#D03E16"/>'
            . '</g></svg>';
    }

    function chatbot_widget(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        try {
            require_once APP_ROOT . '/app/services/chatbot.php';
            if (!chatbot_flag('enabled') || (($GLOBALS['__site_page']['chatbot'] ?? true) === false)) {
                return '';
            }
            $config = chatbot_public_config();
        } catch (Throwable $e) {
            error_log('[GIMT] chatbot widget disabled: ' . $e->getMessage());
            return '';
        }
        $done = true;
        $online = !empty($config['online']);
        $label = 'Chat with the ' . $config['botName'] . ($online ? ' (online)' : '');
        $avatar = $config['avatar']
            ? '<img src="' . e($config['avatar']) . '" alt="" width="54" height="54" class="h-full w-full object-cover" loading="lazy" decoding="async">'
            : chatbot_avatar_svg('gcbs');
        $hostClass = 'gcb-host no-print' . (empty($config['showOnMobile']) ? ' hidden sm:block' : '');
        $props = htmlspecialchars(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
        ob_start();
        ?>
<div id="gimt-chat" class="<?= e($hostClass) ?>" data-chat-island="ChatWidget" data-props="<?= $props ?>">
  <div class="fixed bottom-[calc(var(--mobile-cta-h,0px)+14px)] left-3 z-[44] sm:left-5 lg:bottom-6">
    <a href="<?= e($config['contactUrl'] . '#message') ?>" class="group relative grid h-[60px] w-[60px] place-items-center rounded-full bg-white shadow-[0_14px_32px_-12px_rgba(7,26,59,.55)] ring-[3px] <?= $online ? 'ring-emerald-500' : 'ring-slate-300' ?> transition-transform duration-200 hover:scale-105 focus-visible:outline-none focus-visible:ring-offset-2" aria-label="<?= e($label) ?>" data-chat-launcher>
      <span class="block h-[52px] w-[52px] overflow-hidden rounded-full"><?= $avatar ?></span>
      <span class="absolute bottom-0 right-0 h-3.5 w-3.5 rounded-full border-2 border-white <?= $online ? 'bg-emerald-500' : 'bg-slate-400' ?>" aria-hidden="true"></span>
      <span class="gcb-spin pointer-events-none absolute -inset-1 hidden rounded-full border-2 border-transparent border-t-accent-600 motion-safe:animate-spin" aria-hidden="true"></span>
    </a>
  </div>
</div>
<script>
(function () {
  var host = document.getElementById('gimt-chat');
  if (!host || host.getAttribute('data-island')) return;
  var started = false;
  function mount(open) {
    if (open) {
      host.setAttribute('data-open', '1');
      var sp = host.querySelector('.gcb-spin');
      if (sp) sp.classList.remove('hidden');
    }
    if (started) return;
    started = true;
    var tries = 0;
    (function wait() {
      var rt = window.__gimtSite;
      if (rt && rt !== 'fallback' && typeof rt.mountIslands === 'function') {
        host.setAttribute('data-island', host.getAttribute('data-chat-island') || 'ChatWidget');
        host.setAttribute('data-eager', '');
        rt.mountIslands(host.parentNode || document);
      } else if (rt !== 'fallback' && tries++ < 80) {
        setTimeout(wait, 250);
      } else if (host.getAttribute('data-open')) {
        var a = host.querySelector('[data-chat-launcher]');
        if (a) window.location.href = a.getAttribute('href');
      }
    })();
  }
  var link = host.querySelector('[data-chat-launcher]');
  if (link) link.addEventListener('click', function (e) {
    if (window.__gimtSite === 'fallback') return; // no site bundle: plain link to the contact form
    e.preventDefault();
    mount(true);
    // the chat must always do something: if the island has not mounted in 5 s, follow the link
    setTimeout(function () { if (host.getAttribute('data-island-state') !== 'mounted') window.location.href = link.getAttribute('href'); }, 5000);
  });
  ['pointerdown', 'keydown', 'touchstart', 'wheel'].forEach(function (t) {
    window.addEventListener(t, function () { mount(false); }, { capture: true, passive: true, once: true });
  });
  function idle() { (window.requestIdleCallback || function (cb) { return setTimeout(cb, 1200); })(function () { mount(false); }, { timeout: 3500 }); }
  if (document.readyState === 'complete') idle(); else window.addEventListener('load', idle, { once: true });
})();
</script>
<?php
        return (string) ob_get_clean();
    }
}
