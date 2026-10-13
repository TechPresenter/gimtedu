import { $ } from './dom';

/**
 * First-visit loading screen (#site-loader, rendered by includes/header.php).
 * The inline head script adds `loader-skip` to <html> for repeat visits in the same tab session, reduced motion and
 * automated browsers, so the overlay never paints for them. Otherwise it is dismissed on window load or after
 * 700ms, whichever comes first (CSS also auto-dismisses it at ~1.2s if this script never runs).
 */
export function initLoader(): void {
  const loader = $('#site-loader');
  try {
    sessionStorage.setItem('gimt.visited', '1');
  } catch {
    /* storage disabled */
  }
  if (!loader) return;
  if (document.documentElement.classList.contains('loader-skip')) {
    loader.remove();
    return;
  }
  let done = false;
  const finish = () => {
    if (done) return;
    done = true;
    loader.classList.add('is-done');
    window.setTimeout(() => loader.remove(), 500);
  };
  if (document.readyState === 'complete') window.setTimeout(finish, 150);
  else window.addEventListener('load', finish, { once: true });
  window.setTimeout(finish, 700);
}
