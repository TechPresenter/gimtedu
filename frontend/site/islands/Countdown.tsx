import { useEffect, useState } from 'react';
import { usePageVisible } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';

export interface CountdownProps extends IslandBaseProps {
  /** ISO 8601 deadline (from the admissions_deadline setting via countdown() in PHP). */
  deadline: string;
  /** Text before the time, e.g. "Application Closes in". */
  label?: string;
  /** 'inline' = "Xd Yh Zm" (default) · 'boxes' = white day/hour/minute tiles. */
  variant?: 'inline' | 'boxes';
  /** Show seconds (ticks every second instead of every 30s). */
  seconds?: boolean;
}

const parts = (ms: number) => {
  const s = Math.max(0, Math.floor(ms / 1000));
  return { d: Math.floor(s / 86400), h: Math.floor((s % 86400) / 3600), m: Math.floor((s % 3600) / 60), s: s % 60 };
};

/**
 * Countdown to a REAL deadline (no fake urgency): renders nothing once the deadline has passed, pauses while the tab is
 * hidden, announces nothing to screen readers on every tick (the full date is in <time datetime>).
 */
export default function Countdown({ deadline, label = '', variant = 'inline', seconds = false }: CountdownProps) {
  const end = Date.parse(deadline);
  const [now, setNow] = useState(() => Date.now());
  const visible = usePageVisible();

  useEffect(() => {
    if (!visible || !Number.isFinite(end)) return;
    setNow(Date.now());
    const t = window.setInterval(() => setNow(Date.now()), seconds ? 1000 : 30000);
    return () => window.clearInterval(t);
  }, [visible, end, seconds]);

  if (!Number.isFinite(end) || end <= now) return null;
  const p = parts(end - now);
  const full = new Date(end).toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });

  if (variant === 'boxes') {
    return (
      <span className="countdown countdown-boxes" title={`Closes ${full}`}>
        {label && <span className="font-normal">{label} </span>}
        {(
          [
            [p.d, 'Days'],
            [p.h, 'Hours'],
            [p.m, 'Mins'],
            ...(seconds ? [[p.s, 'Secs'] as [number, string]] : []),
          ] as [number, string][]
        ).map(([v, l]) => (
          <span key={l} className="countdown-box">
            <b>{v}</b>
            <small>{l}</small>
          </span>
        ))}
      </span>
    );
  }
  return (
    <span className="countdown" title={`Closes ${full}`}>
      {label && <span className="font-normal">{label} </span>}
      <time dateTime={deadline}>
        {p.d}d {p.h}h {p.m}m{seconds ? ` ${p.s}s` : ''}
      </time>
    </span>
  );
}
