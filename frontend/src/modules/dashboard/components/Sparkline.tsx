import { useEffect, useId, useState } from 'react';
import clsx from 'clsx';
import { useInView } from '@/components/ui';
import { formatMoneyShort, formatNumber } from '@/lib/format';
import type { Spark } from './types';

const fmt = (v: number, f: Spark['format']) => (f === 'money' ? formatMoneyShort(v) : f === 'percent' ? `${v}%` : formatNumber(v));

/** Smooth path through the points (Catmull-Rom → cubic Bézier), clamped to the box. */
function smoothPath(xy: (readonly [number, number])[], h: number): string {
  let d = `M${xy[0][0].toFixed(1)},${xy[0][1].toFixed(1)}`;
  const clamp = (y: number) => Math.min(h, Math.max(0, y));
  for (let i = 0; i < xy.length - 1; i++) {
    const p0 = xy[i - 1] ?? xy[i];
    const p1 = xy[i];
    const p2 = xy[i + 1];
    const p3 = xy[i + 2] ?? p2;
    const c1 = [p1[0] + (p2[0] - p0[0]) / 6, clamp(p1[1] + (p2[1] - p0[1]) / 6)];
    const c2 = [p2[0] - (p3[0] - p1[0]) / 6, clamp(p2[1] - (p3[1] - p1[1]) / 6)];
    d += ` C${c1[0].toFixed(1)},${c1[1].toFixed(1)} ${c2[0].toFixed(1)},${c2[1].toFixed(1)} ${p2[0].toFixed(1)},${p2[1].toFixed(1)}`;
  }
  return d;
}

/**
 * Tiny trend line with a soft gradient area in the KPI's hue. Drawn in real pixels (measured width) so the stroke
 * stays crisp at any card width; draws in once when scrolled into view; every point is described for screen readers.
 */
export function Sparkline({ spark, color, height = 34, className }: { spark: Spark; color: string; height?: number; className?: string }) {
  const { ref, inView } = useInView<HTMLDivElement>();
  const gid = `dh-spark-${useId().replace(/[^a-zA-Z0-9]/g, '')}`;
  const [width, setWidth] = useState(0);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const measure = () => setWidth(Math.round(el.getBoundingClientRect().width));
    measure();
    if (typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [ref]);

  const pts = spark.points;
  const label = `${spark.caption}: ${pts.map((v, i) => `${spark.labels[i] ?? ''} ${fmt(v, spark.format)}`).join(', ')}`;
  let line = '';
  let area = '';
  let last: readonly [number, number] | null = null;
  if (width > 0 && pts.length > 1) {
    const padX = 4;
    const padY = 4;
    const min = Math.min(...pts);
    const max = Math.max(...pts);
    const span = max - min;
    const xy = pts.map((v, i) => [padX + (i / (pts.length - 1)) * (width - padX * 2), span === 0 ? height / 2 : padY + (1 - (v - min) / span) * (height - padY * 2)] as const);
    line = smoothPath(xy, height);
    area = `${line} L${xy[xy.length - 1][0].toFixed(1)},${height} L${xy[0][0].toFixed(1)},${height} Z`;
    last = xy[xy.length - 1];
  }

  return (
    <div ref={ref} className={clsx('relative w-full', className)} style={{ height }} role="img" aria-label={label} title={label}>
      {line && last && (
        <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} className="absolute inset-0 overflow-visible" aria-hidden>
          <defs>
            <linearGradient id={gid} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" stopColor={color} stopOpacity=".3" />
              <stop offset="1" stopColor={color} stopOpacity="0" />
            </linearGradient>
          </defs>
          <path d={area} fill={`url(#${gid})`} className={clsx('transition-opacity duration-700 motion-reduce:transition-none', inView ? 'opacity-100' : 'opacity-0')} />
          <path d={line} fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" pathLength={1} strokeDasharray="1 1" strokeDashoffset={inView ? 0 : 1} className="dh-draw" />
          <circle cx={last[0]} cy={last[1]} r="3.2" fill={color} stroke="currentColor" strokeWidth="2" className={clsx('text-white transition-opacity duration-500 dark:text-slate-900 motion-reduce:transition-none', inView ? 'opacity-100' : 'opacity-0')} />
        </svg>
      )}
    </div>
  );
}
