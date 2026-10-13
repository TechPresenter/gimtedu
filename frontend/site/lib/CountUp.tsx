import { useEffect, useRef, useState } from 'react';
import { easeOutExpo } from '../core/dom';
import { formatCount } from '../core/motion';
import { useInView, useStaticMode } from './hooks';

interface CountUpProps {
  value: number;
  prefix?: string;
  suffix?: string;
  decimals?: number;
  duration?: number;
  /** Start counting only when visible (default) or immediately. */
  start?: boolean;
  className?: string;
}

/** Number that counts up (ease-out) the first time it scrolls into view. Final value in static mode. */
export function CountUp({ value, prefix = '', suffix = '', decimals, duration = 1600, start, className }: CountUpProps) {
  const ref = useRef<HTMLSpanElement>(null);
  const isStatic = useStaticMode();
  const seen = useInView(ref, { threshold: 0.4 });
  const go = start ?? seen;
  const dec = decimals ?? (String(value).split('.')[1] || '').length;
  const [shown, setShown] = useState(isStatic ? value : 0);

  useEffect(() => {
    if (isStatic) {
      setShown(value);
      return;
    }
    if (!go) return;
    let raf = 0;
    let t0 = 0;
    const step = (t: number) => {
      if (!t0) t0 = t;
      const p = Math.min(1, (t - t0) / duration);
      setShown(value * easeOutExpo(p));
      if (p < 1) raf = requestAnimationFrame(step);
    };
    raf = requestAnimationFrame(step);
    return () => cancelAnimationFrame(raf);
  }, [go, value, duration, isStatic]);

  return (
    <span ref={ref} className={className}>
      <span aria-hidden="true">
        {prefix}
        {formatCount(shown, dec)}
        {suffix}
      </span>
      <span className="sr-only">
        {prefix}
        {formatCount(value, dec)}
        {suffix}
      </span>
    </span>
  );
}
