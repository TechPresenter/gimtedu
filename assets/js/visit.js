/* GIMT visitor counter beacon: one POST per page view (skipped for prerender/prefetch and Do-Not-Track). */
(function () {
  'use strict';
  var s = document.currentScript;
  var url = (s && s.getAttribute('data-endpoint')) || '/api/visit';
  if (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true) return;
  // Automated browsers (screenshots, tests) are never counted — and a pending beacon would keep them from going idle.
  if (navigator.webdriver) return;
  var send = function () {
    try {
      fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(function () {});
    } catch (e) { /* ignore */ }
  };
  if (document.prerendering) {
    document.addEventListener('prerenderingchange', send, { once: true });
  } else if (document.visibilityState === 'hidden') {
    document.addEventListener('visibilitychange', function onVis() {
      if (document.visibilityState === 'visible') { document.removeEventListener('visibilitychange', onVis); send(); }
    });
  } else {
    send();
  }
})();
