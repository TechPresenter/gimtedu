import clsx from 'clsx';
import { Pause, Play } from 'lucide-react';
import { useEffect, useRef, useState, type CSSProperties } from 'react';
import { useInView } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';

export interface LogoItem {
  name: string;
  /** Logo image URL; without it a tasteful wordmark placeholder is rendered. */
  logo?: string;
  url?: string;
  industry?: string;
}

export interface LogoMarqueeProps extends IslandBaseProps {
  items: LogoItem[];
  /** 1 or 2 rows (second row scrolls the opposite way). Default 2 when there are 8+ logos. */
  rows?: 1 | 2;
  /** Pixels per second (default 45). */
  speed?: number;
  /** Logos start grayscale and gain colour on hover (default true). */
  grayscale?: boolean;
  ariaLabel?: string;
}

const TINTS = ['bg-brand-50 text-brand-800', 'bg-accent-50 text-accent-700', 'bg-cyan-50 text-cyan-700', 'bg-amber-50 text-amber-700', 'bg-violet-50 text-violet-700', 'bg-rose-50 text-rose-700'];

function Logo({ item, i, hidden }: { item: LogoItem; i: number; hidden?: boolean }) {
  const content = item.logo ? (
    <img src={item.logo} alt={hidden ? '' : item.name} loading="lazy" className="h-9 w-auto max-w-[140px] object-contain" />
  ) : (
    <>
      <span className={clsx('inline-flex h-9 w-9 items-center justify-center rounded-lg font-display text-sm font-extrabold', TINTS[i % TINTS.length])} aria-hidden="true">
        {item.name.replace(/[^A-Za-z]/g, '').slice(0, 2).toUpperCase()}
      </span>
      <span className="font-display text-lg font-extrabold tracking-tight text-slate-700">{item.name}</span>
    </>
  );
  const cls = 'logo-tile';
  return item.url && !hidden ? (
    <a href={item.url} className={cls} target="_blank" rel="noopener noreferrer" title={item.industry ? `${item.name} — ${item.industry}` : item.name}>
      {content}
    </a>
  ) : (
    <span className={cls} title={hidden ? undefined : item.industry ? `${item.name} — ${item.industry}` : item.name}>
      {content}
    </span>
  );
}

function Row({ items, reverse, speed, paused }: { items: LogoItem[]; reverse?: boolean; speed: number; paused: boolean }) {
  const trackRef = useRef<HTMLDivElement>(null);
  const [duration, setDuration] = useState(30);
  useEffect(() => {
    const t = trackRef.current;
    if (!t) return;
    const set = () => setDuration(Math.max(12, t.scrollWidth / 2 / speed));
    set();
    const ro = 'ResizeObserver' in window ? new ResizeObserver(set) : null;
    ro?.observe(t);
    return () => ro?.disconnect();
  }, [speed, items.length]);
  return (
    <div className={clsx('marquee marquee-fade', reverse && 'marquee-reverse', paused && 'is-paused')} style={{ '--marquee-duration': `${duration}s` } as CSSProperties}>
      <div ref={trackRef} className="marquee-track">
        <ul className="marquee-group">
          {items.map((it, i) => (
            <li key={`a${i}`}>
              <Logo item={it} i={i} />
            </li>
          ))}
        </ul>
        <ul className="marquee-group" aria-hidden="true">
          {items.map((it, i) => (
            <li key={`b${i}`}>
              <Logo item={it} i={i} hidden />
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

/** Infinite recruiter/partner logo marquee (CSS animation), grayscale → colour on hover, pause control. */
export default function LogoMarquee({ items, rows, speed = 45, grayscale = true, ariaLabel = 'Our recruiters' }: LogoMarqueeProps) {
  const [userPaused, setUserPaused] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const inView = useInView(ref, { once: false, threshold: 0 });
  const rowCount = rows ?? (items.length >= 8 ? 2 : 1);
  const half = Math.ceil(items.length / 2);
  const groups = rowCount === 2 ? [items.slice(0, half), items.slice(half)] : [items];

  return (
    <div ref={ref} className={clsx('logo-marquee', grayscale && 'logo-grayscale')} role="region" aria-label={ariaLabel}>
      {groups.map((g, i) => (
        <Row key={i} items={g} reverse={i === 1} speed={speed} paused={userPaused || !inView} />
      ))}
      <button type="button" className="marquee-toggle" onClick={() => setUserPaused((p) => !p)} aria-pressed={userPaused} aria-label={userPaused ? 'Play logo animation' : 'Pause logo animation'}>
        {userPaused ? <Play className="h-3.5 w-3.5" aria-hidden="true" /> : <Pause className="h-3.5 w-3.5" aria-hidden="true" />}
      </button>
    </div>
  );
}
