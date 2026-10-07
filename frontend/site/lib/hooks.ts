import { useCallback, useEffect, useRef, useState, type FocusEvent, type RefObject } from 'react';
import { staticMode } from '../core/dom';

/** True when animations should be skipped (prefers-reduced-motion, automated browsers, data-motion="off"). */
export function useStaticMode(): boolean {
  const [value, setValue] = useState(() => staticMode());
  useEffect(() => {
    const mq = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    if (!mq) return;
    const on = () => setValue(staticMode());
    mq.addEventListener?.('change', on);
    return () => mq.removeEventListener?.('change', on);
  }, []);
  return value;
}

export function useMediaQuery(query: string): boolean {
  const [match, setMatch] = useState(() => !!window.matchMedia?.(query).matches);
  useEffect(() => {
    const mq = window.matchMedia?.(query);
    if (!mq) return;
    const on = () => setMatch(mq.matches);
    on();
    mq.addEventListener?.('change', on);
    return () => mq.removeEventListener?.('change', on);
  }, [query]);
  return match;
}

/** True once (or while, with once=false) the element is in the viewport. */
export function useInView<T extends Element>(ref: RefObject<T>, opts: { once?: boolean; rootMargin?: string; threshold?: number } = {}): boolean {
  const { once = true, rootMargin = '0px 0px -10% 0px', threshold = 0.15 } = opts;
  const [inView, setInView] = useState(false);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (!('IntersectionObserver' in window)) {
      setInView(true);
      return;
    }
    const io = new IntersectionObserver(
      ([en]) => {
        if (en.isIntersecting) {
          setInView(true);
          if (once) io.disconnect();
        } else if (!once) setInView(false);
      },
      { rootMargin, threshold },
    );
    io.observe(el);
    return () => io.disconnect();
  }, [ref, once, rootMargin, threshold]);
  return inView;
}

/** document.visibilityState === 'visible' */
export function usePageVisible(): boolean {
  const [visible, setVisible] = useState(() => !document.hidden);
  useEffect(() => {
    const on = () => setVisible(!document.hidden);
    document.addEventListener('visibilitychange', on);
    return () => document.removeEventListener('visibilitychange', on);
  }, []);
  return visible;
}

/**
 * Autoplay timer that can pause and resume where it left off (so a CSS progress animation with the same duration
 * and `animation-play-state` stays in sync). Restarts whenever `key` changes.
 */
export function usePausableTimer(onDone: () => void, duration: number, paused: boolean, key: unknown): void {
  const cb = useRef(onDone);
  cb.current = onDone;
  const remaining = useRef(duration);
  const startedAt = useRef(0);

  useEffect(() => {
    remaining.current = duration;
  }, [key, duration]);

  useEffect(() => {
    if (paused || duration <= 0) return;
    startedAt.current = performance.now();
    const t = window.setTimeout(() => {
      remaining.current = duration;
      cb.current();
    }, Math.max(0, remaining.current));
    return () => {
      window.clearTimeout(t);
      remaining.current -= performance.now() - startedAt.current;
    };
  }, [paused, duration, key]);
}

/** Horizontal swipe detection with pointer events (ignores mostly-vertical gestures). */
export function useSwipe<T extends HTMLElement>(ref: RefObject<T>, onSwipe: (dir: 1 | -1) => void, threshold = 45): void {
  const handler = useRef(onSwipe);
  handler.current = onSwipe;
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    let sx = 0;
    let sy = 0;
    let active = false;
    const down = (e: PointerEvent) => {
      if (e.pointerType === 'mouse' && e.button !== 0) return;
      active = true;
      sx = e.clientX;
      sy = e.clientY;
    };
    const up = (e: PointerEvent) => {
      if (!active) return;
      active = false;
      const dx = e.clientX - sx;
      const dy = e.clientY - sy;
      if (Math.abs(dx) > threshold && Math.abs(dx) > Math.abs(dy) * 1.3) handler.current(dx < 0 ? 1 : -1);
    };
    const cancel = () => (active = false);
    el.addEventListener('pointerdown', down);
    el.addEventListener('pointerup', up);
    el.addEventListener('pointercancel', cancel);
    return () => {
      el.removeEventListener('pointerdown', down);
      el.removeEventListener('pointerup', up);
      el.removeEventListener('pointercancel', cancel);
    };
  }, [ref, threshold]);
}

/** Debounced value. */
export function useDebounced<T>(value: T, delay = 200): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = window.setTimeout(() => setV(value), delay);
    return () => window.clearTimeout(t);
  }, [value, delay]);
  return v;
}

/** Hover/focus-within pause state for autoplaying widgets: spread the returned handlers on the root element. */
export function useHoverPause() {
  const [hovered, setHovered] = useState(false);
  const [focused, setFocused] = useState(false);
  const onMouseEnter = useCallback(() => setHovered(true), []);
  const onMouseLeave = useCallback(() => setHovered(false), []);
  const onFocus = useCallback(() => setFocused(true), []);
  const onBlur = useCallback((e: FocusEvent) => {
    if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setFocused(false);
  }, []);
  return { paused: hovered || focused, handlers: { onMouseEnter, onMouseLeave, onFocus, onBlur } };
}
