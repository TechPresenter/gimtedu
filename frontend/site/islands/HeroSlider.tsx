import clsx from 'clsx';
import { ArrowRight, ChevronLeft, ChevronRight, GraduationCap, Pause, Play } from 'lucide-react';
import { useCallback, useRef, useState, type KeyboardEvent } from 'react';
import { CountUp } from '../lib/CountUp';
import { useHoverPause, useInView, usePageVisible, usePausableTimer, useStaticMode, useSwipe } from '../lib/hooks';
import { Icon } from '../lib/Icon';
import { scribbleText } from '../lib/Scribble';
import type { IslandBaseProps, LinkProp } from '../lib/types';
import { VideoModal } from './VideoLightbox';

export interface HeroSlide {
  image: string;
  mobileImage?: string;
  alt?: string;
  eyebrow?: string;
  title: string;
  /** Word/phrase of the title circled with the hand-drawn orange ellipse. */
  scribble?: string;
  /** Second headline line rendered in orange. */
  highlight?: string;
  text?: string;
  cta?: LinkProp;
  cta2?: LinkProp;
}

export interface HeroSliderProps extends IslandBaseProps {
  slides: HeroSlide[];
  /** White pill chips floating next to the photo (desktop). */
  chips?: { icon: string; label: string }[];
  /** Bold numbers with thin dividers below the hero. */
  stats?: { value: number; prefix?: string; suffix?: string; label: string; icon?: string }[];
  /** "Watch our campus tour" button (on the photo) opening a video lightbox. */
  video?: { url: string; label: string; sublabel?: string };
  /** Line under the copy, e.g. "Admissions Open for 2026-27". */
  tagline?: string;
  taglineWords?: string[];
  /** Rotated sticker tag on the photo (default: first three taglineWords). */
  sticker?: string;
  /** Autoplay interval in ms (0 disables). Default 6500. */
  interval?: number;
  theme?: 'light' | 'dark';
  ariaLabel?: string;
}

/**
 * Design v3 homepage hero: grid-paper (light) or ink-grid (dark) background, big bold Plus Jakarta headline with a scribbled word,
 * rounded photo frame with doodles + sticker (server-rendered slots), crossfading slides, white pill chips, campus-tour
 * video and bold stats with thin dividers. Autoplay pauses on hover/focus, hidden tab, off-screen or when paused;
 * swipe + ←/→ keys; ARIA carousel semantics.
 */
export default function HeroSlider({ slides, chips = [], stats = [], video, tagline, taglineWords = [], interval = 6500, theme = 'light', ariaLabel = 'Featured highlights', slots }: HeroSliderProps) {
  const count = slides.length;
  const [index, setIndex] = useState(0);
  const [playing, setPlaying] = useState(true);
  const [videoOpen, setVideoOpen] = useState(false);
  const isStatic = useStaticMode();
  const visible = usePageVisible();
  const { paused: hoverPaused, handlers } = useHoverPause();
  const rootRef = useRef<HTMLElement>(null);
  const inView = useInView(rootRef, { once: false, threshold: 0.2, rootMargin: '0px' });
  const autoplay = count > 1 && interval > 0 && !isStatic;
  const paused = !playing || hoverPaused || !visible || videoOpen || !inView;
  const dark = theme === 'dark';

  const go = useCallback((n: number) => setIndex(((n % count) + count) % count), [count]);
  usePausableTimer(() => go(index + 1), autoplay ? interval : 0, paused, index);
  useSwipe(rootRef, (dir) => go(index + dir));

  const onKey = (e: KeyboardEvent) => {
    if (e.key === 'ArrowRight') go(index + 1);
    else if (e.key === 'ArrowLeft') go(index - 1);
  };
  const slotHtml = (name: string) => (slots?.[name] ?? []).map((s) => s.html).join('');

  return (
    <section
      ref={rootRef}
      className={clsx('hero-slider', dark ? 'hero-dark' : 'hero-light', stats.length > 0 && 'has-stats')}
      aria-roledescription="carousel"
      aria-label={ariaLabel}
      onKeyDown={onKey}
      {...handlers}
    >
      <div className="hero-bg" aria-hidden="true" />
      <div className="contents" dangerouslySetInnerHTML={{ __html: slotHtml('backdrop') }} />

      <div className="container-site hero-inner">
        <div>
          {/* Text (stacked in one grid cell so the height never jumps between slides) */}
          <div className="hero-copy" aria-live={autoplay && !paused ? 'off' : 'polite'}>
            {slides.map((s, i) => {
              const active = i === index;
              const Heading = i === 0 ? 'h1' : 'h2';
              return (
                <div
                  key={i}
                  className={clsx('hero-copy-item', active && 'is-active')}
                  role="group"
                  aria-roledescription="slide"
                  aria-label={`${i + 1} of ${count}`}
                  aria-hidden={!active}
                  {...(!active ? { inert: '' } : {})}
                >
                  {s.eyebrow && (
                    <p className="hero-eyebrow">
                      <span className="hero-eyebrow-dot" />
                      {s.eyebrow}
                    </p>
                  )}
                  <Heading className="hero-title">
                    {scribbleText(s.title, s.scribble, dark ? 'light' : 'orange')}
                    {s.highlight && <span className="hero-highlight">{s.highlight}</span>}
                  </Heading>
                  {s.text && <p className="hero-text">{s.text}</p>}
                  {(s.cta || s.cta2) && (
                    <div className="hero-actions">
                      {s.cta && (
                        <a href={s.cta.url} className="btn btn-accent btn-lg btn-shine" data-magnetic="0.2">
                          {s.cta.label}
                          <ArrowRight className="h-4 w-4" aria-hidden="true" />
                        </a>
                      )}
                      {s.cta2 && (
                        <a href={s.cta2.url} className={clsx('btn btn-lg', dark ? 'btn-outline-light' : 'btn-outline')}>
                          {s.cta2.label}
                        </a>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
            {(tagline || taglineWords.length > 0) && (
              <div className="hero-tagline">
                {tagline && (
                  <p className="hero-tagline-main">
                    <GraduationCap className="h-6 w-6 text-accent-600" aria-hidden="true" />
                    {tagline}
                  </p>
                )}
                {taglineWords.length > 0 && (
                  <p className="hero-tagline-words">
                    {taglineWords.map((w, i) => (
                      <span key={w}>
                        {i > 0 && <span className="hero-tagline-sep" aria-hidden="true">•</span>}
                        {w}
                      </span>
                    ))}
                  </p>
                )}
              </div>
            )}
          </div>

          {count > 1 && (
            <div className="hero-controls">
              <button type="button" className="hero-arrow" onClick={() => go(index - 1)} aria-label="Previous slide">
                <ChevronLeft className="h-5 w-5" aria-hidden="true" />
              </button>
              <div className="hero-bullets" role="group" aria-label="Choose slide">
                {slides.map((s, i) => (
                  <button
                    key={i}
                    type="button"
                    className={clsx('hero-bullet', i === index && 'is-active', paused && 'is-paused')}
                    onClick={() => go(i)}
                    aria-label={`Show slide ${i + 1}: ${s.title}`}
                    aria-current={i === index ? 'true' : undefined}
                  >
                    <span className="hero-bullet-fill" style={{ animationDuration: `${interval}ms` }} key={i === index ? `a${index}` : 'idle'} />
                  </button>
                ))}
              </div>
              <button type="button" className="hero-arrow" onClick={() => go(index + 1)} aria-label="Next slide">
                <ChevronRight className="h-5 w-5" aria-hidden="true" />
              </button>
              {autoplay && (
                <button type="button" className="hero-arrow" onClick={() => setPlaying((p) => !p)} aria-label={playing ? 'Pause slideshow' : 'Play slideshow'}>
                  {playing ? <Pause className="h-4 w-4" aria-hidden="true" /> : <Play className="h-4 w-4" aria-hidden="true" />}
                </button>
              )}
            </div>
          )}
        </div>

        {/* Photo frame + doodles + chips */}
        <div className="hero-visual">
          <div className="hero-frame">
            {slides.map((s, i) => (
              <div key={i} className={clsx('hero-media-item', i === index && 'is-active')} aria-hidden={i !== index}>
                <picture>
                  {s.mobileImage && <source media="(max-width: 767px)" srcSet={s.mobileImage} />}
                  <img
                    src={s.image}
                    alt={s.alt ?? ''}
                    className={clsx('hero-img', i === index && !isStatic && 'animate-ken-burns')}
                    style={{ animationDuration: `${Math.max(interval, 6000) + 2000}ms` }}
                    {...{ fetchpriority: i === 0 ? 'high' : 'low' }}
                    loading={i === 0 ? 'eager' : 'lazy'}
                    decoding={i === 0 ? 'sync' : 'async'}
                  />
                </picture>
              </div>
            ))}
            {video && (
              <button type="button" className="hero-video" onClick={() => setVideoOpen(true)} data-cursor-label="Play">
                <span className="play-pulse">
                  <Play className="h-5 w-5 translate-x-px fill-current" aria-hidden="true" />
                </span>
                <span className="text-left">
                  <span className="block text-sm font-semibold">{video.label}</span>
                  {video.sublabel && <span className="block text-xs opacity-75">{video.sublabel}</span>}
                </span>
              </button>
            )}
          </div>
          <div className="contents" dangerouslySetInnerHTML={{ __html: slotHtml('doodles') }} />
          {chips.length > 0 && (
            <ul className="hero-chips" aria-label="Highlights">
              {chips.map((c, i) => (
                <li key={c.label} className="hero-chip" style={{ ['--i' as string]: i }}>
                  <span className="hero-chip-icon">
                    <Icon name={c.icon} className="h-4 w-4" />
                  </span>
                  {c.label}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      {stats.length > 0 && (
        <div className="container-site hero-stats-wrap">
          <ul className="hero-stats" aria-label="GIMT at a glance">
            {stats.map((s) => (
              <li key={s.label} className="hero-stat">
                <p>
                  <CountUp value={s.value} prefix={s.prefix} suffix={s.suffix} className="hero-stat-value" />
                  <span className="hero-stat-label">{s.label}</span>
                </p>
              </li>
            ))}
          </ul>
        </div>
      )}

      {video && <VideoModal open={videoOpen} onClose={() => setVideoOpen(false)} url={video.url} title={video.label} />}
    </section>
  );
}
