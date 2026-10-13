import clsx from 'clsx';
import { ArrowLeft, ArrowRight, Hand, Pause, Play } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';
import { useInView, usePageVisible, useStaticMode } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';

export interface StudentLifeItem {
  src: string;
  alt: string;
  caption: string;
  tag?: string;
  /** YouTube / Vimeo / mp4 URL — the card becomes a play button that opens the site lightbox. */
  video?: string | null;
}

export interface StudentLifeSliderProps extends IslandBaseProps {
  items: StudentLifeItem[];
  ariaLabel?: string;
  /** Auto-advance interval in ms (0 = off). Pauses on hover / focus / hidden tab / off-screen / pause button. */
  interval?: number;
}

/**
 * Student Life slider: a scroll-snap track of photo / video cards with simple mouse drag (no momentum — native touch
 * swipe on phones), arrow buttons, ←/→ keys on the focused track, an optional gentle auto-advance with a pause control,
 * a position counter and captions. Video cards open the shared lightbox.
 */
export default function StudentLifeSlider({ items, ariaLabel = 'Student life', interval = 5500 }: StudentLifeSliderProps) {
  const trackRef = useRef<HTMLDivElement>(null);
  const rootRef = useRef<HTMLDivElement>(null);
  const isStatic = useStaticMode();
  const pageVisible = usePageVisible();
  const inView = useInView(rootRef, { once: false, threshold: 0.25 });
  const [index, setIndex] = useState(0);
  const [atStart, setAtStart] = useState(true);
  const [atEnd, setAtEnd] = useState(false);
  const [userPaused, setUserPaused] = useState(false);
  const [hover, setHover] = useState(false);
  const [focusIn, setFocusIn] = useState(false);
  const [dragging, setDragging] = useState(false);
  const drag = useRef({ active: false, startX: 0, startScroll: 0, moved: 0, id: 0 });
  const autoplay = interval > 0 && !isStatic && items.length > 1;
  const paused = userPaused || hover || focusIn || !pageVisible || !inView || dragging;

  const slides = () => Array.from(trackRef.current?.children ?? []) as HTMLElement[];

  const sync = useCallback(() => {
    const t = trackRef.current;
    if (!t) return;
    setAtStart(t.scrollLeft <= 4);
    setAtEnd(t.scrollLeft + t.clientWidth >= t.scrollWidth - 4);
    const left = t.getBoundingClientRect().left;
    let best = 0;
    let dist = Infinity;
    slides().forEach((el, i) => {
      const d = Math.abs(el.getBoundingClientRect().left - left);
      if (d < dist) {
        dist = d;
        best = i;
      }
    });
    setIndex(best);
  }, []);

  const goTo = useCallback(
    (i: number) => {
      const t = trackRef.current;
      const el = slides()[Math.max(0, Math.min(items.length - 1, i))];
      if (!t || !el) return;
      t.scrollTo({ left: el.offsetLeft - (slides()[0]?.offsetLeft ?? 0), behavior: isStatic ? 'auto' : 'smooth' });
    },
    [items.length, isStatic],
  );

  const step = (dir: 1 | -1) => {
    if (dir === 1 && atEnd) return goTo(0);
    goTo(index + dir);
  };

  useEffect(() => {
    const t = trackRef.current;
    if (!t) return;
    sync();
    t.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    return () => {
      t.removeEventListener('scroll', sync);
      window.removeEventListener('resize', sync);
    };
  }, [sync]);

  // gentle auto-advance
  useEffect(() => {
    if (!autoplay || paused) return;
    const timer = window.setTimeout(() => (atEnd ? goTo(0) : goTo(index + 1)), interval);
    return () => window.clearTimeout(timer);
  }, [autoplay, paused, index, atEnd, interval, goTo]);

  // mouse drag (touch uses native scrolling); no momentum — release snaps to the nearest card
  const onPointerDown = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (e.pointerType !== 'mouse' || e.button !== 0) return;
    const t = trackRef.current;
    if (!t) return;
    drag.current = { active: true, startX: e.clientX, startScroll: t.scrollLeft, moved: 0, id: e.pointerId };
  };
  const onPointerMove = (e: ReactPointerEvent<HTMLDivElement>) => {
    const d = drag.current;
    const t = trackRef.current;
    if (!d.active || !t) return;
    const dx = e.clientX - d.startX;
    d.moved = Math.max(d.moved, Math.abs(dx));
    if (d.moved > 4 && !dragging) {
      setDragging(true);
      t.setPointerCapture?.(d.id);
    }
    if (d.moved > 4) t.scrollLeft = d.startScroll - dx;
  };
  const endDrag = () => {
    const d = drag.current;
    if (!d.active) return;
    d.active = false;
    trackRef.current?.releasePointerCapture?.(d.id);
    if (dragging) {
      setDragging(false);
      requestAnimationFrame(() => {
        sync();
      });
    }
  };

  const openVideo = (it: StudentLifeItem) => {
    const rt = window.__gimtSite;
    if (it.video && rt && rt !== 'fallback') rt.openLightbox([{ src: it.video, caption: it.caption }], 0);
    else if (it.video) window.open(it.video, '_blank', 'noopener');
  };

  return (
    <div
      ref={rootRef}
      className="student-life"
      role="region"
      aria-roledescription="carousel"
      aria-label={ariaLabel}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      onFocus={() => setFocusIn(true)}
      onBlur={(e) => !e.currentTarget.contains(e.relatedTarget as Node | null) && setFocusIn(false)}
    >
      <div
        ref={trackRef}
        tabIndex={0}
        aria-label={`${ariaLabel} — use the arrow keys to scroll`}
        onKeyDown={(e) => {
          if (e.key === 'ArrowRight') {
            e.preventDefault();
            step(1);
          } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            step(-1);
          }
        }}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={endDrag}
        onPointerCancel={endDrag}
        onClickCapture={(e) => {
          if (drag.current.moved > 6) {
            e.preventDefault();
            e.stopPropagation();
            drag.current.moved = 0;
          }
        }}
        className={clsx(
          'flex gap-4 overflow-x-auto pb-2 scrollbar-none sm:gap-5',
          dragging ? 'cursor-grabbing select-none snap-none' : 'cursor-grab snap-x snap-mandatory',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-4',
        )}
      >
        {items.map((it, i) => {
          const wide = i % 3 === 0;
          const body = (
            <>
              <img src={it.src} alt={it.alt} className="h-[340px] w-full object-cover transition duration-700 group-hover:scale-[1.04] sm:h-[400px]" loading="lazy" decoding="async" draggable={false} />
              <span className="pointer-events-none absolute inset-0 bg-gradient-to-t from-brand-950/80 via-brand-950/5 to-transparent" aria-hidden="true" />
              {it.tag && <span className="absolute left-4 top-4 rounded-full bg-white/90 px-3 py-1 text-[11.5px] font-bold text-brand-900">{it.tag}</span>}
              {it.video && (
                <span className="absolute right-4 top-4 inline-flex h-11 w-11 items-center justify-center rounded-full bg-accent-600 text-white shadow-lg">
                  <Play className="h-4 w-4 translate-x-px" aria-hidden="true" />
                </span>
              )}
              <span className="absolute inset-x-5 bottom-5 text-left font-display text-[16px] font-bold leading-snug text-white">{it.caption}</span>
            </>
          );
          const cls = clsx('group relative shrink-0 snap-start overflow-hidden rounded-[26px] bg-warm-2', wide ? 'w-[82%] sm:w-[440px]' : 'w-[64%] sm:w-[300px]');
          return it.video ? (
            <button
              key={i}
              type="button"
              className={clsx(cls, 'text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500')}
              onClick={() => openVideo(it)}
              aria-label={`Play video: ${it.caption}`}
              aria-roledescription="slide"
            >
              {body}
            </button>
          ) : (
            <figure key={i} className={cls} aria-roledescription="slide" aria-label={`${i + 1} of ${items.length}: ${it.caption}`}>
              {body}
            </figure>
          );
        })}
      </div>

      <div className="mt-5 flex items-center justify-between gap-4">
        <div className="flex min-w-0 items-center gap-4">
          <p className="font-display text-[15px] font-extrabold tabular-nums text-brand-900" aria-live="polite" aria-atomic="true">
            {String(index + 1).padStart(2, '0')}
            <span className="font-semibold text-slate-400"> / {String(items.length).padStart(2, '0')}</span>
          </p>
          <span className="relative hidden h-1 w-40 overflow-hidden rounded-full bg-warm sm:block" aria-hidden="true">
            <span className="absolute inset-y-0 left-0 w-full origin-left rounded-full bg-accent-600 transition-transform duration-500" style={{ transform: `scaleX(${(index + 1) / items.length})` }} />
          </span>
          <span className="hidden items-center gap-1.5 text-[13px] text-slate-500 md:inline-flex">
            <Hand className="h-4 w-4" aria-hidden="true" />
            Drag or swipe to explore
          </span>
        </div>
        <div className="flex items-center gap-2">
          {autoplay && (
            <button
              type="button"
              onClick={() => setUserPaused((p) => !p)}
              aria-pressed={userPaused}
              aria-label={userPaused ? 'Play slideshow' : 'Pause slideshow'}
              className="inline-flex h-11 w-11 items-center justify-center rounded-full border border-warm-2 bg-white text-brand-900 transition hover:border-brand-900"
            >
              {userPaused ? <Play className="h-4 w-4" aria-hidden="true" /> : <Pause className="h-4 w-4" aria-hidden="true" />}
            </button>
          )}
          <button
            type="button"
            onClick={() => step(-1)}
            disabled={atStart}
            aria-label="Previous photo"
            className="inline-flex h-11 w-11 items-center justify-center rounded-full border border-warm-2 bg-white text-brand-900 transition hover:bg-brand-900 hover:text-white disabled:pointer-events-none disabled:opacity-40"
          >
            <ArrowLeft className="h-5 w-5" aria-hidden="true" />
          </button>
          <button
            type="button"
            onClick={() => step(1)}
            aria-label={atEnd ? 'Back to the first photo' : 'Next photo'}
            className="inline-flex h-11 w-11 items-center justify-center rounded-full bg-brand-900 text-white transition hover:bg-accent-600"
          >
            <ArrowRight className="h-5 w-5" aria-hidden="true" />
          </button>
        </div>
      </div>
    </div>
  );
}
