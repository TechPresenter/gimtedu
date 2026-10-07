import clsx from 'clsx';
import { ChevronLeft, ChevronRight, Quote, Star } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { useHoverPause, useInView, usePageVisible, usePausableTimer, useStaticMode, useSwipe } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';

export interface Testimonial {
  name: string;
  quote: string;
  role?: string;
  company?: string;
  program?: string;
  photo?: string;
  rating?: number;
}

export interface TestimonialSliderProps extends IslandBaseProps {
  items: Testimonial[];
  /** Autoplay interval in ms (default 7000, 0 = off). */
  autoplay?: number;
  /** 'light' card on light sections, 'dark' glass card on navy sections. */
  theme?: 'light' | 'dark';
  ariaLabel?: string;
}

const initials = (name: string) =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('');

function Avatar({ t, size = 'md' }: { t: Testimonial; size?: 'sm' | 'md' }) {
  const cls = size === 'sm' ? 'h-11 w-11 text-sm' : 'h-14 w-14 text-base';
  return t.photo ? (
    <img src={t.photo} alt="" loading="lazy" className={clsx(cls, 'shrink-0 rounded-full object-cover ring-2 ring-white')} />
  ) : (
    <span className={clsx(cls, 'inline-flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-600 to-accent-600 font-bold text-white ring-2 ring-white')} aria-hidden="true">
      {initials(t.name)}
    </span>
  );
}

/**
 * Testimonial / success-story slider: large quote card with rating, direction-aware slide+fade transition,
 * avatar navigation, arrows, swipe and an autoplay progress line. Pauses on hover/focus.
 */
export default function TestimonialSlider({ items, autoplay = 7000, theme = 'light', ariaLabel = 'Student and alumni testimonials' }: TestimonialSliderProps) {
  const count = items.length;
  const [index, setIndex] = useState(0);
  const [dir, setDir] = useState<1 | -1>(1);
  const isStatic = useStaticMode();
  const visible = usePageVisible();
  const { paused, handlers } = useHoverPause();
  const ref = useRef<HTMLDivElement>(null);
  const inView = useInView(ref, { once: false, threshold: 0.2, rootMargin: '0px' });
  const auto = count > 1 && autoplay > 0 && !isStatic;

  const go = useCallback(
    (n: number, d?: 1 | -1) => {
      setDir(d ?? (n > index ? 1 : -1));
      setIndex(((n % count) + count) % count);
    },
    [count, index],
  );
  usePausableTimer(() => go(index + 1, 1), auto ? autoplay : 0, paused || !visible || !inView, index);
  useSwipe(ref, (d) => go(index + d, d));

  if (!count) return null;
  const dark = theme === 'dark';

  return (
    <div
      ref={ref}
      className={clsx('testimonials', dark && 'testimonials-dark')}
      role="region"
      aria-roledescription="carousel"
      aria-label={ariaLabel}
      onKeyDown={(e) => {
        if (e.key === 'ArrowRight') go(index + 1, 1);
        if (e.key === 'ArrowLeft') go(index - 1, -1);
      }}
      {...handlers}
    >
      <div className="testimonial-stage" data-dir={dir > 0 ? 'next' : 'prev'} aria-live={auto && !paused ? 'off' : 'polite'}>
        {items.map((t, i) => {
          const active = i === index;
          return (
            <figure
              key={i}
              className={clsx('testimonial-card', active && 'is-active')}
              role="group"
              aria-roledescription="slide"
              aria-label={`${i + 1} of ${count}`}
              aria-hidden={!active}
              {...(!active ? { inert: '' } : {})}
            >
              <Quote className="testimonial-quote-icon" aria-hidden="true" />
              {t.rating ? (
                <p className="flex gap-0.5" aria-label={`Rated ${t.rating} out of 5`}>
                  {Array.from({ length: 5 }, (_, k) => (
                    <Star key={k} className={clsx('h-4 w-4', k < (t.rating ?? 0) ? 'fill-amber-400 text-amber-400' : dark ? 'text-white/25' : 'text-slate-300')} aria-hidden="true" />
                  ))}
                </p>
              ) : null}
              <blockquote className="testimonial-text">“{t.quote}”</blockquote>
              <figcaption className="mt-6 flex items-center gap-4">
                <Avatar t={t} />
                <span>
                  <span className="testimonial-name">{t.name}</span>
                  <span className="testimonial-role">{[t.role, t.company].filter(Boolean).join(' · ') || t.program}</span>
                  {t.program && (t.role || t.company) && <span className="testimonial-program">{t.program}</span>}
                </span>
              </figcaption>
            </figure>
          );
        })}
      </div>

      {count > 1 && (
        <div className="testimonial-nav">
          <div className="flex -space-x-2" role="group" aria-label="Choose testimonial">
            {items.map((t, i) => (
              <button
                key={i}
                type="button"
                onClick={() => go(i)}
                className={clsx('testimonial-thumb', i === index && 'is-active')}
                aria-label={`Show testimonial from ${t.name}`}
                aria-current={i === index ? 'true' : undefined}
              >
                <Avatar t={t} size="sm" />
              </button>
            ))}
          </div>
          <div className="ml-auto flex items-center gap-2">
            <button type="button" className="carousel-arrow" onClick={() => go(index - 1, -1)} aria-label="Previous testimonial">
              <ChevronLeft className="h-5 w-5" aria-hidden="true" />
            </button>
            <button type="button" className="carousel-arrow" onClick={() => go(index + 1, 1)} aria-label="Next testimonial">
              <ChevronRight className="h-5 w-5" aria-hidden="true" />
            </button>
          </div>
          {auto && (
            <span className="testimonial-progress" aria-hidden="true">
              <span key={index} className={clsx('testimonial-progress-fill', (paused || !visible || !inView) && 'is-paused')} style={{ animationDuration: `${autoplay}ms` }} />
            </span>
          )}
        </div>
      )}
    </div>
  );
}
