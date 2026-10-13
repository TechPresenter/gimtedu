import { useEffect, useRef, useState } from 'react';

/** Current time, re-rendered at every minute boundary (and when the tab becomes visible again). */
export function useMinuteClock(): Date {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    let interval: number | undefined;
    const tick = () => setNow(new Date());
    const timeout = window.setTimeout(() => {
      tick();
      interval = window.setInterval(tick, 60_000);
    }, 60_000 - (Date.now() % 60_000) + 50);
    const onVisible = () => {
      if (!document.hidden) tick();
    };
    document.addEventListener('visibilitychange', onVisible);
    return () => {
      window.clearTimeout(timeout);
      if (interval) window.clearInterval(interval);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, []);
  return now;
}

/** True while the element is off-screen or the tab is hidden — used to freeze ambient animations. */
export function useOffscreenPause<T extends Element>() {
  const ref = useRef<T | null>(null);
  const [paused, setPaused] = useState(false);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    let visible = true;
    const update = () => setPaused(!visible || document.hidden);
    const io = typeof IntersectionObserver !== 'undefined'
      ? new IntersectionObserver((entries) => {
        visible = entries.some((e) => e.isIntersecting);
        update();
      })
      : null;
    io?.observe(el);
    document.addEventListener('visibilitychange', update);
    return () => {
      io?.disconnect();
      document.removeEventListener('visibilitychange', update);
    };
  }, []);
  return { ref, paused };
}
