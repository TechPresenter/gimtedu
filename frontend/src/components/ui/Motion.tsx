import { useEffect, useRef, useState, type CSSProperties, type ElementType, type ReactNode } from 'react';
import clsx from 'clsx';

/**
 * Subtle "premium" motion primitives. All of them respect prefers-reduced-motion (they render the final state
 * immediately) and only animate opacity/transform so they stay cheap.
 *
 *   <CountUp value={1248} />                         1,248 counting up when it scrolls into view
 *   <CountUp value={2840000} format={formatMoneyShort} />
 *   <Reveal delay={80}><Card>…</Card></Reveal>        fade + rise when scrolled into view
 *   <Stagger className="grid gap-4 sm:grid-cols-4" step={60}>{cards}</Stagger>   children reveal one after another
 */

export function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

/** True once the element has entered the viewport (stays true). */
export function useInView<T extends Element>(rootMargin = '0px 0px -8% 0px') {
  const ref = useRef<T | null>(null);
  const [inView, setInView] = useState(false);
  useEffect(() => {
    const el = ref.current;
    if (!el || inView) return;
    if (typeof IntersectionObserver === 'undefined' || prefersReducedMotion()) {
      setInView(true);
      return;
    }
    const io = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          setInView(true);
          io.disconnect();
        }
      },
      { rootMargin },
    );
    io.observe(el);
    return () => io.disconnect();
  }, [inView, rootMargin]);
  return { ref, inView };
}

const easeOutCubic = (t: number) => 1 - Math.pow(1 - t, 3);

interface CountUpProps {
  value: number | string | null | undefined;
  /** Formatter for the displayed number (default: en-IN grouping, decimals preserved). */
  format?: (n: number) => string;
  duration?: number;
  className?: string;
}

/** Animated number that counts up from 0 the first time it becomes visible (and eases to new values afterwards). */
export function CountUp({ value, format, duration = 900, className }: CountUpProps) {
  const target = Number(value) || 0;
  const decimals = (String(value ?? '').split('.')[1] || '').length;
  const fmt = format ?? ((n: number) => n.toLocaleString('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }));
  const { ref, inView } = useInView<HTMLSpanElement>();
  const [shown, setShown] = useState(() => (prefersReducedMotion() ? target : 0));
  const fromRef = useRef(shown);

  useEffect(() => {
    if (!inView) return;
    if (prefersReducedMotion()) {
      setShown(target);
      return;
    }
    const from = fromRef.current;
    let raf = 0;
    let start: number | null = null;
    const step = (t: number) => {
      if (start === null) start = t;
      const p = Math.min(1, (t - start) / duration);
      const v = from + (target - from) * easeOutCubic(p);
      setShown(decimals ? v : Math.round(v));
      if (p < 1) raf = requestAnimationFrame(step);
      else fromRef.current = target;
    };
    raf = requestAnimationFrame(step);
    return () => cancelAnimationFrame(raf);
  }, [inView, target, duration, decimals]);

  return (
    <span ref={ref} className={clsx('tabular-nums', className)}>
      {fmt(shown)}
    </span>
  );
}

interface RevealProps {
  children: ReactNode;
  /** Delay in ms before this element animates in. */
  delay?: number;
  className?: string;
  as?: ElementType;
  /** Initial offset direction. */
  from?: 'bottom' | 'left' | 'right' | 'none';
}

/** Fades and lifts its content into place when it scrolls into view. */
export function Reveal({ children, delay = 0, className, as: Tag = 'div', from = 'bottom' }: RevealProps) {
  const { ref, inView } = useInView<HTMLElement>();
  const offset = from === 'bottom' ? 'translate-y-3' : from === 'left' ? '-translate-x-3' : from === 'right' ? 'translate-x-3' : '';
  const style: CSSProperties = delay ? { transitionDelay: `${delay}ms` } : {};
  return (
    <Tag
      ref={ref}
      style={style}
      className={clsx('transition duration-500 ease-out motion-reduce:transition-none', inView ? 'translate-x-0 translate-y-0 opacity-100' : clsx('opacity-0', offset), className)}
    >
      {children}
    </Tag>
  );
}

/** Wraps each child in a Reveal with an increasing delay (cards in a grid, list rows …). */
export function Stagger({ children, step = 60, className, itemClassName }: { children: ReactNode[]; step?: number; className?: string; itemClassName?: string }) {
  return (
    <div className={className}>
      {children.map((child, i) => (
        <Reveal key={i} delay={Math.min(i, 12) * step} className={itemClassName}>
          {child}
        </Reveal>
      ))}
    </div>
  );
}
