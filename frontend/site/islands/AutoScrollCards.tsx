import clsx from 'clsx';
import { Pause, Play } from 'lucide-react';
import { useEffect, useRef, useState, type CSSProperties } from 'react';
import { useInView, useStaticMode } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';

export interface AutoScrollCardsProps extends IslandBaseProps {
  direction?: 'vertical' | 'horizontal';
  /** Pixels per second (default 28). */
  speed?: number;
  /** Viewport height in px for the vertical variant (default 420). */
  height?: number;
  ariaLabel?: string;
}

/**
 * Continuously auto-scrolling list of server-rendered cards (`data-slot="items"`): events, notices, news.
 * Seamless loop (content duplicated for the animation only), pauses on hover/focus, has a pause button
 * (WCAG 2.2.2) and turns into a normal scrollable list for reduced motion.
 */
export default function AutoScrollCards({ direction = 'vertical', speed = 28, height = 420, ariaLabel = 'Latest updates', slots }: AutoScrollCardsProps) {
  const items = slots?.items ?? [];
  const isStatic = useStaticMode();
  const [paused, setPaused] = useState(false);
  const [duration, setDuration] = useState(30);
  const hostRef = useRef<HTMLDivElement>(null);
  const trackRef = useRef<HTMLDivElement>(null);
  const inView = useInView(hostRef, { once: false, threshold: 0 });
  const vertical = direction === 'vertical';
  const animate = !isStatic && items.length > 1;

  useEffect(() => {
    const t = trackRef.current;
    if (!t || !animate) return;
    const set = () => setDuration(Math.max(10, (vertical ? t.scrollHeight : t.scrollWidth) / 2 / speed));
    set();
    const ro = 'ResizeObserver' in window ? new ResizeObserver(set) : null;
    ro?.observe(t);
    return () => ro?.disconnect();
  }, [animate, vertical, speed, items.length]);

  const list = (copy: boolean) =>
    items.map((it, i) => (
      <div key={`${copy ? 'b' : 'a'}${i}`} className="autoscroll-item" aria-hidden={copy || undefined} {...(copy ? { inert: '' } : {})} dangerouslySetInnerHTML={{ __html: it.html }} />
    ));

  return (
    <div
      ref={hostRef}
      className={clsx('autoscroll', vertical ? 'autoscroll-y' : 'autoscroll-x', animate ? 'is-animated' : 'is-static', (paused || !inView) && 'is-paused')}
      style={{ '--autoscroll-duration': `${duration}s`, ...(vertical ? { height } : {}) } as CSSProperties}
      role="region"
      aria-label={ariaLabel}
    >
      <div ref={trackRef} className="autoscroll-track">
        {list(false)}
        {animate && list(true)}
      </div>
      {animate && (
        <button type="button" className="marquee-toggle" onClick={() => setPaused((p) => !p)} aria-pressed={paused} aria-label={paused ? 'Resume scrolling' : 'Pause scrolling'}>
          {paused ? <Play className="h-3.5 w-3.5" aria-hidden="true" /> : <Pause className="h-3.5 w-3.5" aria-hidden="true" />}
        </button>
      )}
    </div>
  );
}
