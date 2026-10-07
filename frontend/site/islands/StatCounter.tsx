import clsx from 'clsx';
import { useRef, type CSSProperties } from 'react';
import { CountUp } from '../lib/CountUp';
import { useInView, useStaticMode } from '../lib/hooks';
import { Icon } from '../lib/Icon';
import type { IslandBaseProps } from '../lib/types';

export interface StatItem {
  value: number;
  label: string;
  prefix?: string;
  suffix?: string;
  decimals?: number;
  icon?: string;
  /** 0–100: drives the ring / bar fill (defaults to value when suffix is "%"). */
  progress?: number;
  caption?: string;
}

export interface StatCounterProps extends IslandBaseProps {
  items: StatItem[];
  /** cards (icon + number), rings (circular progress), bars (horizontal progress) or inline (big numbers). */
  variant?: 'cards' | 'rings' | 'bars' | 'inline';
  theme?: 'light' | 'dark';
  /** Grid classes (default responsive 2 → 4 columns). */
  gridClass?: string;
}

const TINTS = ['tint-blue', 'tint-green', 'tint-cyan', 'tint-amber', 'tint-violet', 'tint-rose'];

/** Animated statistics: count-up numbers with optional progress rings/bars, staggered reveal. */
export default function StatCounter({ items, variant = 'cards', theme = 'light', gridClass }: StatCounterProps) {
  const ref = useRef<HTMLUListElement>(null);
  const isStatic = useStaticMode();
  const seen = useInView(ref, { threshold: 0.25 });
  const on = seen || isStatic;
  const dark = theme === 'dark';
  const pct = (s: StatItem) => Math.max(0, Math.min(100, s.progress ?? (s.suffix?.includes('%') ? s.value : 100)));

  return (
    <ul ref={ref} className={clsx('stat-group grid gap-5', gridClass ?? (variant === 'bars' ? 'sm:grid-cols-2' : 'grid-cols-2 lg:grid-cols-4'), dark && 'stat-dark', on && 'is-on')}>
      {items.map((s, i) => (
        <li key={s.label} className={clsx('stat-item', `stat-${variant}`)} style={{ '--i': i } as CSSProperties}>
          {variant === 'rings' ? (
            <>
              <span className="stat-ring">
                <svg viewBox="0 0 120 120" aria-hidden="true">
                  <circle cx="60" cy="60" r="52" className="stat-ring-track" />
                  <circle cx="60" cy="60" r="52" pathLength={100} className="stat-ring-fill" style={{ strokeDashoffset: on ? 100 - pct(s) : 100 }} />
                </svg>
                <span className="stat-ring-value">
                  <CountUp value={s.value} prefix={s.prefix} suffix={s.suffix} decimals={s.decimals} start={on} />
                </span>
              </span>
              <span className="stat-label">{s.label}</span>
              {s.caption && <span className="stat-caption">{s.caption}</span>}
            </>
          ) : variant === 'bars' ? (
            <>
              <span className="flex items-baseline justify-between gap-3">
                <span className="stat-label">{s.label}</span>
                <CountUp className="stat-value text-2xl" value={s.value} prefix={s.prefix} suffix={s.suffix} decimals={s.decimals} start={on} />
              </span>
              <span className="progress-track mt-3">
                <span className="progress-fill" style={{ '--progress': on ? pct(s) / 100 : 0 } as CSSProperties} />
              </span>
              {s.caption && <span className="stat-caption">{s.caption}</span>}
            </>
          ) : (
            <>
              {s.icon && variant === 'cards' && (
                <span className={clsx('stat-icon', TINTS[i % TINTS.length])}>
                  <Icon name={s.icon} className="h-6 w-6" />
                </span>
              )}
              <span>
                <CountUp className="stat-value" value={s.value} prefix={s.prefix} suffix={s.suffix} decimals={s.decimals} start={on} />
                <span className="stat-label">{s.label}</span>
                {s.caption && <span className="stat-caption">{s.caption}</span>}
              </span>
            </>
          )}
        </li>
      ))}
    </ul>
  );
}
