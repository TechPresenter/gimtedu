import clsx from 'clsx';
import { ArrowRight } from 'lucide-react';
import { useEffect, useRef, useState, type CSSProperties } from 'react';
import { clamp, rafThrottle } from '../core/dom';
import { useInView, useMediaQuery, useStaticMode } from '../lib/hooks';
import { Icon } from '../lib/Icon';
import type { IslandBaseProps, LinkProp } from '../lib/types';

export interface ProgressStepsProps extends IslandBaseProps {
  steps: { title: string; text?: string; icon?: string }[];
  /** 'auto' = horizontal from lg, vertical below (default). */
  orientation?: 'auto' | 'horizontal' | 'vertical';
  cta?: LinkProp;
  theme?: 'light' | 'dark';
}

/**
 * Animated journey/timeline (e.g. the admission process). Horizontal: the connector line draws across when the
 * steps scroll into view and each step lights up as the line reaches it. Vertical (mobile): the line is
 * scroll-linked and fills as you read down.
 */
export default function ProgressSteps({ steps, orientation = 'auto', cta, theme = 'light' }: ProgressStepsProps) {
  const ref = useRef<HTMLOListElement>(null);
  const isStatic = useStaticMode();
  const wide = useMediaQuery('(min-width: 1024px)');
  const horizontal = orientation === 'horizontal' || (orientation === 'auto' && wide);
  const seen = useInView(ref, { threshold: 0.3 });
  const [progress, setProgress] = useState(isStatic ? 1 : 0);
  const n = steps.length;

  // horizontal: timed draw once visible
  useEffect(() => {
    if (!horizontal) return;
    if (isStatic) return setProgress(1);
    if (!seen) return;
    let raf = 0;
    let t0 = 0;
    const dur = 300 * n + 400;
    const step = (t: number) => {
      if (!t0) t0 = t;
      const p = Math.min(1, (t - t0) / dur);
      setProgress(p);
      if (p < 1) raf = requestAnimationFrame(step);
    };
    raf = requestAnimationFrame(step);
    return () => cancelAnimationFrame(raf);
  }, [horizontal, seen, isStatic, n]);

  // vertical: scroll-linked fill
  useEffect(() => {
    if (horizontal || isStatic) {
      if (isStatic) setProgress(1);
      return;
    }
    const el = ref.current;
    if (!el) return;
    const update = rafThrottle(() => {
      const r = el.getBoundingClientRect();
      const mid = window.innerHeight * 0.6;
      setProgress(clamp((mid - r.top) / Math.max(1, r.height), 0, 1));
    });
    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update, { passive: true });
    return () => {
      window.removeEventListener('scroll', update);
      window.removeEventListener('resize', update);
    };
  }, [horizontal, isStatic]);

  const reached = (i: number) => (n <= 1 ? progress > 0 : progress >= i / (n - 1) - 0.001);

  return (
    <div className={clsx('steps', horizontal ? 'steps-h' : 'steps-v', theme === 'dark' && 'steps-dark')}>
      <div className="steps-track" style={{ '--n': n } as CSSProperties}>
      <span className="steps-line" aria-hidden="true">
        <span className="steps-line-fill" style={{ transform: horizontal ? `scaleX(${progress})` : `scaleY(${progress})` }} />
      </span>
      <ol ref={ref} className="steps-list">
        {steps.map((s, i) => (
          <li key={i} className={clsx('step', reached(i) && 'is-reached')} style={{ '--i': i } as CSSProperties}>
            <span className="step-icon">
              {s.icon ? <Icon name={s.icon} className="h-6 w-6" /> : <span className="font-display text-lg font-extrabold">{i + 1}</span>}
              <span className="step-pulse" aria-hidden="true" />
            </span>
            <span className="step-body">
              <span className="step-num">{String(i + 1).padStart(2, '0')}</span>
              <span className="step-title">{s.title}</span>
              {s.text && <span className="step-text">{s.text}</span>}
            </span>
          </li>
        ))}
      </ol>
      </div>
      {cta && (
        <a href={cta.url} className="btn btn-accent btn-shine mt-8" data-magnetic="0.2">
          {cta.label}
          <ArrowRight className="h-4 w-4" aria-hidden="true" />
        </a>
      )}
    </div>
  );
}
