import clsx from 'clsx';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type CSSProperties } from 'react';
import { staticMode } from '../core/dom';
import { useHoverPause, useInView, usePageVisible, useStaticMode } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';

export interface Testimonial {
  name: string;
  quote: string;
  role?: string;
  company?: string;
  program?: string;
  photo?: string;
  /** Small uppercase meta line (default "program • company"). */
  meta?: string;
  /** Wrap the text in quotation marks (default true; false for third-person alumni bios). */
  quoteMarks?: boolean;
  /** Kept for backwards compatibility; the cards do not show star ratings. */
  rating?: number;
}

export interface TestimonialSliderProps extends IslandBaseProps {
  items: Testimonial[];
  /** Autoplay interval in ms (default 7000, 0 = off). */
  autoplay?: number;
  /** 'light' grey cards (default) or 'dark' translucent cards on ink bands. */
  theme?: 'light' | 'dark';
  ariaLabel?: string;
  /** Cards per view by breakpoint (default 1.1 / 2 / 3 / 4). */
  perView?: { base?: number; sm?: number; lg?: number; xl?: number };
}

const PASTELS = ['#CCC1FF', '#A9D8FF', '#BEFFFE', '#F2DFB4', '#F7BFD8', '#E3EDA0'];
const initials = (name: string) =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('');

/**
 * Student / alumni story cards (Design v3): photo (or pastel initials), quote, bold name and an uppercase program
 * meta line in a scroll-snap track with round arrow buttons centred below. Autoplay advances one card and pauses on
 * hover/focus, hidden tab and off-screen; static for reduced motion. Native swipe on touch.
 */
export default function TestimonialSlider({ items, autoplay = 7000, theme = 'light', ariaLabel = 'Student and alumni stories', perView }: TestimonialSliderProps) {
  const trackRef = useRef<HTMLDivElement>(null);
  const [index, setIndex] = useState(0);
  const [pages, setPages] = useState(items.length);
  const isStatic = useStaticMode();
  const visible = usePageVisible();
  const { paused, handlers } = useHoverPause();
  const inView = useInView(trackRef, { once: false, threshold: 0.2, rootMargin: '0px' });
  const pv = { base: 1.1, sm: 2, lg: 3, xl: 4, ...perView };
  const gap = 20;

  const step = useCallback(() => {
    const first = trackRef.current?.firstElementChild as HTMLElement | null;
    return first ? first.getBoundingClientRect().width + gap : 1;
  }, []);

  useEffect(() => {
    const t = trackRef.current;
    if (!t) return;
    const measure = () => {
      const visibleCount = Math.max(1, Math.floor((t.clientWidth + gap / 2) / step()));
      setPages(Math.max(1, items.length - visibleCount + 1));
      setIndex(Math.round(t.scrollLeft / step()));
    };
    measure();
    const ro = 'ResizeObserver' in window ? new ResizeObserver(measure) : null;
    ro?.observe(t);
    const onScroll = () => setIndex(Math.round(t.scrollLeft / step()));
    t.addEventListener('scroll', onScroll, { passive: true });
    return () => {
      ro?.disconnect();
      t.removeEventListener('scroll', onScroll);
    };
  }, [items.length, step]);

  const goTo = useCallback(
    (i: number) => {
      const t = trackRef.current;
      if (!t) return;
      const last = pages - 1;
      const target = i > last ? 0 : i < 0 ? last : i;
      t.scrollTo({ left: target * step(), behavior: staticMode() ? 'auto' : 'smooth' });
    },
    [pages, step],
  );

  useEffect(() => {
    if (!autoplay || isStatic || paused || !visible || !inView || pages < 2) return;
    const t = window.setInterval(() => goTo(index + 1), autoplay);
    return () => window.clearInterval(t);
  }, [autoplay, isStatic, paused, visible, inView, pages, index, goTo]);

  if (!items.length) return null;
  const style = { '--gap': `${gap}px`, '--pv-base': pv.base, '--pv-sm': pv.sm, '--pv-md': pv.sm, '--pv-lg': pv.lg, '--pv-xl': pv.xl } as CSSProperties;

  return (
    <div className={clsx('testimonials carousel', theme === 'dark' && 'testimonials-dark')} role="region" aria-roledescription="carousel" aria-label={ariaLabel} {...handlers}>
      <div
        ref={trackRef}
        className="carousel-track scrollbar-none"
        style={style}
        tabIndex={0}
        onKeyDown={(e) => {
          if (e.key === 'ArrowRight') {
            e.preventDefault();
            goTo(index + 1);
          } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            goTo(index - 1);
          }
        }}
      >
        {items.map((t, i) => {
          const meta = t.meta ?? [t.program, t.company].filter(Boolean).join(' • ');
          return (
            <figure key={i} className="carousel-slide tcard" role="group" aria-roledescription="slide" aria-label={`${i + 1} of ${items.length}`}>
              <div className="tcard-photo">
                {t.photo ? (
                  <img src={t.photo} alt="" loading="lazy" decoding="async" />
                ) : (
                  <span className="tcard-initials" style={{ background: PASTELS[i % PASTELS.length] }} aria-hidden="true">
                    {initials(t.name)}
                  </span>
                )}
              </div>
              <blockquote className="tcard-quote">{t.quoteMarks === false ? t.quote : `“${t.quote}”`}</blockquote>
              <figcaption>
                <span className="tcard-name block">{t.name}</span>
                {meta && <span className="tcard-meta block">{meta}</span>}
              </figcaption>
            </figure>
          );
        })}
      </div>
      {pages > 1 && (
        <div className="carousel-controls">
          <button type="button" className="carousel-arrow" onClick={() => goTo(index - 1)} aria-label="Previous story">
            <ArrowLeft className="h-5 w-5" aria-hidden="true" />
          </button>
          <button type="button" className="carousel-arrow" onClick={() => goTo(index + 1)} aria-label="Next story">
            <ArrowRight className="h-5 w-5" aria-hidden="true" />
          </button>
        </div>
      )}
    </div>
  );
}
