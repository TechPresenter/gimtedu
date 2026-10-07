import clsx from 'clsx';
import { ArrowRight, ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type CSSProperties } from 'react';
import { staticMode } from '../core/dom';
import { useHoverPause, usePageVisible, useStaticMode } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';

export interface CarouselItem {
  title: string;
  text?: string;
  image?: string;
  url?: string;
  meta?: string;
  badge?: string;
}

export interface CarouselProps extends IslandBaseProps {
  ariaLabel?: string;
  /** Slides visible per breakpoint (fractions allowed for a "peek"). Default { base: 1.15, sm: 2, lg: 3 }. */
  perView?: { base?: number; sm?: number; md?: number; lg?: number; xl?: number };
  /** Gap between slides in px (default 24). */
  gap?: number;
  /** Autoplay interval in ms (0 = off). Pauses on hover/focus/hidden tab; disabled for reduced motion. */
  autoplay?: number;
  /** Jump back to the first slide after the last one (default true). */
  loop?: boolean;
  arrows?: boolean;
  dots?: boolean;
  /** Simple cards when no `slides` slot is provided. */
  items?: CarouselItem[];
}

/**
 * Generic scroll-snap carousel. Slides come from the server-rendered fallback (`<div data-slot="slides">` children,
 * e.g. program_card() output) or from `items`. Native touch scrolling, mouse drag, arrows, dots, keyboard, autoplay.
 */
export default function Carousel({ ariaLabel = 'Carousel', perView, gap = 24, autoplay = 0, loop = true, arrows = true, dots = true, items = [], slots }: CarouselProps) {
  const slides = slots?.slides ?? [];
  const total = slides.length || items.length;
  const trackRef = useRef<HTMLDivElement>(null);
  const [index, setIndex] = useState(0);
  const [pages, setPages] = useState(total);
  const [announce, setAnnounce] = useState('');
  const isStatic = useStaticMode();
  const visible = usePageVisible();
  const { paused, handlers } = useHoverPause();
  const pv = { base: 1.15, sm: 2, lg: 3, ...perView };

  const slideWidth = useCallback(() => {
    const first = trackRef.current?.firstElementChild as HTMLElement | null;
    return first ? first.getBoundingClientRect().width + gap : 1;
  }, [gap]);

  const measure = useCallback(() => {
    const t = trackRef.current;
    if (!t) return;
    const w = slideWidth();
    const visibleCount = Math.max(1, Math.floor((t.clientWidth + gap / 2) / w));
    setPages(Math.max(1, total - visibleCount + 1));
    setIndex(Math.min(total - 1, Math.round(t.scrollLeft / w)));
  }, [gap, slideWidth, total]);

  useEffect(() => {
    measure();
    const t = trackRef.current;
    if (!t) return;
    const ro = 'ResizeObserver' in window ? new ResizeObserver(measure) : null;
    ro?.observe(t);
    let raf = 0;
    const onScroll = () => {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(() => setIndex(Math.round(t.scrollLeft / slideWidth())));
    };
    t.addEventListener('scroll', onScroll, { passive: true });
    return () => {
      ro?.disconnect();
      t.removeEventListener('scroll', onScroll);
    };
  }, [measure, slideWidth]);

  const goTo = useCallback(
    (i: number, manual = false) => {
      const t = trackRef.current;
      if (!t) return;
      const last = pages - 1;
      const target = i > last ? (loop ? 0 : last) : i < 0 ? (loop ? last : 0) : i;
      t.scrollTo({ left: target * slideWidth(), behavior: staticMode() ? 'auto' : 'smooth' });
      if (manual) setAnnounce(`Slide ${target + 1} of ${pages}`);
    },
    [loop, pages, slideWidth],
  );

  // autoplay
  useEffect(() => {
    if (!autoplay || isStatic || paused || !visible || pages < 2) return;
    const t = window.setInterval(() => goTo(index + 1), autoplay);
    return () => window.clearInterval(t);
  }, [autoplay, isStatic, paused, visible, pages, index, goTo]);

  // mouse drag to scroll (touch uses native scrolling)
  useEffect(() => {
    const t = trackRef.current;
    if (!t) return;
    let startX = 0;
    let startLeft = 0;
    let dragging = false;
    let moved = false;
    const down = (e: PointerEvent) => {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      dragging = true;
      moved = false;
      startX = e.clientX;
      startLeft = t.scrollLeft;
    };
    const move = (e: PointerEvent) => {
      if (!dragging) return;
      const dx = e.clientX - startX;
      if (!moved && Math.abs(dx) > 6) {
        moved = true;
        t.classList.add('is-dragging');
        t.setPointerCapture(e.pointerId);
      }
      if (moved) t.scrollLeft = startLeft - dx;
    };
    const up = (e: PointerEvent) => {
      if (!dragging) return;
      dragging = false;
      if (!moved) return;
      t.classList.remove('is-dragging');
      if (t.hasPointerCapture(e.pointerId)) t.releasePointerCapture(e.pointerId);
      const w = slideWidth();
      const dir = Math.sign(startLeft - t.scrollLeft);
      const i = dir > 0 ? Math.floor(t.scrollLeft / w) : Math.ceil(t.scrollLeft / w);
      t.scrollTo({ left: Math.max(0, i) * w, behavior: 'smooth' });
    };
    const click = (e: MouseEvent) => {
      if (moved) {
        e.preventDefault();
        e.stopPropagation();
        moved = false;
      }
    };
    t.addEventListener('pointerdown', down);
    t.addEventListener('pointermove', move);
    t.addEventListener('pointerup', up);
    t.addEventListener('pointercancel', up);
    t.addEventListener('click', click, true);
    return () => {
      t.removeEventListener('pointerdown', down);
      t.removeEventListener('pointermove', move);
      t.removeEventListener('pointerup', up);
      t.removeEventListener('pointercancel', up);
      t.removeEventListener('click', click, true);
    };
  }, [slideWidth]);

  const style = {
    '--gap': `${gap}px`,
    '--pv-base': pv.base,
    '--pv-sm': pv.sm ?? pv.base,
    '--pv-md': pv.md ?? pv.sm ?? pv.base,
    '--pv-lg': pv.lg ?? pv.md ?? pv.sm ?? pv.base,
    '--pv-xl': pv.xl ?? pv.lg ?? pv.md ?? pv.sm ?? pv.base,
  } as CSSProperties;

  return (
    <div className="carousel" role="region" aria-roledescription="carousel" aria-label={ariaLabel} {...handlers}>
      <div
        ref={trackRef}
        className="carousel-track scrollbar-none"
        style={style}
        tabIndex={0}
        onKeyDown={(e) => {
          if (e.key === 'ArrowRight') {
            e.preventDefault();
            goTo(index + 1, true);
          } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            goTo(index - 1, true);
          }
        }}
      >
        {slides.length > 0
          ? slides.map((s, i) => (
              <div key={i} className="carousel-slide" role="group" aria-roledescription="slide" aria-label={`${i + 1} of ${total}`} dangerouslySetInnerHTML={{ __html: s.html }} />
            ))
          : items.map((it, i) => (
              <div key={i} className="carousel-slide" role="group" aria-roledescription="slide" aria-label={`${i + 1} of ${total}`}>
                <article className="card card-lift group flex h-full flex-col overflow-hidden">
                  {it.image && (
                    <div className="img-zoom relative aspect-[16/10]">
                      <img src={it.image} alt="" loading="lazy" className="h-full w-full object-cover" />
                      {it.badge && <span className="absolute left-3 top-3 badge badge-green bg-white/95">{it.badge}</span>}
                    </div>
                  )}
                  <div className="flex flex-1 flex-col p-5">
                    {it.meta && <p className="text-xs font-semibold uppercase tracking-wider text-accent-600">{it.meta}</p>}
                    <h3 className="mt-1.5 text-lg font-bold leading-snug text-brand-900">{it.title}</h3>
                    {it.text && <p className="mt-2 line-clamp-3 text-sm text-slate-600">{it.text}</p>}
                    {it.url && (
                      <a href={it.url} className="link-arrow mt-auto pt-4">
                        Read more <ArrowRight className="h-4 w-4" aria-hidden="true" />
                        <span className="sr-only">about {it.title}</span>
                      </a>
                    )}
                  </div>
                </article>
              </div>
            ))}
      </div>

      {(arrows || dots) && pages > 1 && (
        <div className="carousel-controls">
          {dots && (
            <div className="carousel-dots" role="group" aria-label="Choose slide">
              {Array.from({ length: pages }, (_, i) => (
                <button key={i} type="button" className={clsx('carousel-dot', i === Math.min(index, pages - 1) && 'is-active')} onClick={() => goTo(i, true)} aria-label={`Go to slide ${i + 1}`} aria-current={i === index ? 'true' : undefined} />
              ))}
            </div>
          )}
          {arrows && (
            <div className="ml-auto flex gap-2">
              <button type="button" className="carousel-arrow" onClick={() => goTo(index - 1, true)} disabled={!loop && index <= 0} aria-label="Previous slide">
                <ChevronLeft className="h-5 w-5" aria-hidden="true" />
              </button>
              <button type="button" className="carousel-arrow" onClick={() => goTo(index + 1, true)} disabled={!loop && index >= pages - 1} aria-label="Next slide">
                <ChevronRight className="h-5 w-5" aria-hidden="true" />
              </button>
            </div>
          )}
        </div>
      )}
      <p className="sr-only" aria-live="polite">
        {announce}
      </p>
    </div>
  );
}
