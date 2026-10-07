import clsx from 'clsx';
import { ChevronLeft, ChevronRight, ZoomIn, ZoomOut } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type CSSProperties, type MouseEvent as RMouseEvent } from 'react';
import { useSwipe } from '../lib/hooks';
import { Modal } from '../lib/Modal';
import type { IslandBaseProps } from '../lib/types';

export interface GalleryItem {
  src: string;
  thumb?: string;
  alt: string;
  caption?: string;
  category?: string;
}

export interface GalleryLightboxProps extends IslandBaseProps {
  items: GalleryItem[];
  /** Columns on large screens (2–4, default 3). */
  columns?: 2 | 3 | 4;
  /** 'grid' (uniform tiles), 'masonry' (natural heights) or 'bento' (first tile spans 2×2). */
  layout?: 'grid' | 'masonry' | 'bento';
  /** Category filter pills built from item categories. */
  filter?: boolean;
  ariaLabel?: string;
}

/**
 * Photo gallery grid (hover zoom + caption overlay, staggered reveal) with a lightbox: arrows, ←/→ keys, swipe,
 * click-to-zoom with pointer panning, thumbnails strip and counter.
 */
export default function GalleryLightbox({ items, columns = 3, layout = 'grid', filter = false, ariaLabel = 'Photo gallery' }: GalleryLightboxProps) {
  const [cat, setCat] = useState('all');
  const [index, setIndex] = useState<number | null>(null);
  const [zoom, setZoom] = useState(false);
  const [origin, setOrigin] = useState({ x: 50, y: 50 });
  const stageRef = useRef<HTMLDivElement>(null);
  const cats = useMemo(() => Array.from(new Set(items.map((i) => i.category).filter(Boolean))) as string[], [items]);
  const list = items.filter((i) => cat === 'all' || i.category === cat);
  const open = index !== null;
  const cur = open ? list[index!] : null;

  const go = (d: number) => {
    setZoom(false);
    setIndex((i) => (i === null ? i : (i + d + list.length) % list.length));
  };
  useSwipe(stageRef, (d) => !zoom && go(d));

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'ArrowRight') go(1);
      if (e.key === 'ArrowLeft') go(-1);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  });

  // preload neighbours
  useEffect(() => {
    if (index === null) return;
    [index + 1, index - 1].forEach((n) => {
      const it = list[(n + list.length) % list.length];
      if (it) new Image().src = it.src;
    });
  }, [index, list]);

  const pan = (e: RMouseEvent<HTMLImageElement>) => {
    if (!zoom) return;
    const r = e.currentTarget.getBoundingClientRect();
    setOrigin({ x: ((e.clientX - r.left) / r.width) * 100, y: ((e.clientY - r.top) / r.height) * 100 });
  };

  const colCls = { 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-2 lg:grid-cols-3', 4: 'sm:grid-cols-3 lg:grid-cols-4' }[columns];

  return (
    <div className="gallery" role="region" aria-label={ariaLabel}>
      {filter && cats.length > 1 && (
        <div className="mb-6 flex flex-wrap gap-2" role="toolbar" aria-label="Filter photos">
          {['all', ...cats].map((c) => (
            <button key={c} type="button" className={clsx('chip', c === cat && 'chip-active')} aria-pressed={c === cat} onClick={() => setCat(c)}>
              {c === 'all' ? 'All photos' : c}
            </button>
          ))}
        </div>
      )}
      <ul className={clsx(layout === 'masonry' ? `gallery-masonry columns-1 gap-4 sm:columns-2 ${columns >= 3 ? 'lg:columns-3' : ''} ${columns === 4 ? 'xl:columns-4' : ''}` : `grid grid-cols-2 gap-3 sm:gap-4 ${colCls}`, layout === 'bento' && 'gallery-bento')}>
        {list.map((it, i) => (
          <li key={`${cat}-${i}`} className={clsx('gallery-item', layout === 'masonry' && 'mb-4 break-inside-avoid')} style={{ '--i': Math.min(i, 12) } as CSSProperties}>
            <button type="button" className={clsx('gallery-tile img-zoom group', layout !== 'masonry' && 'aspect-[4/3]')} onClick={() => setIndex(i)} aria-label={`Open photo: ${it.caption || it.alt}`} data-cursor-label="View">
              <img src={it.thumb || it.src} alt={it.alt} loading="lazy" className="h-full w-full object-cover" />
              <span className="gallery-tile-overlay" aria-hidden="true">
                <ZoomIn className="h-5 w-5" />
                {it.caption && <span className="gallery-tile-caption">{it.caption}</span>}
              </span>
            </button>
          </li>
        ))}
      </ul>

      <Modal open={open} onClose={() => (setIndex(null), setZoom(false))} label="Photo viewer" variant="media">
        {cur && (
          <div className="gallery-viewer">
            <div ref={stageRef} className={clsx('gallery-stage', zoom && 'is-zoomed')}>
              <img
                key={cur.src}
                src={cur.src}
                alt={cur.alt}
                className="gallery-stage-img"
                style={{ transformOrigin: `${origin.x}% ${origin.y}%` }}
                onClick={(e) => {
                  pan(e);
                  setZoom((z) => !z);
                }}
                onPointerMove={pan}
                draggable={false}
              />
            </div>
            <div className="gallery-bar">
              <p className="min-w-0 flex-1 truncate text-sm text-white/85" aria-live="polite">
                <span className="font-semibold text-white">
                  {index! + 1} / {list.length}
                </span>
                {cur.caption && <span> · {cur.caption}</span>}
              </p>
              <button type="button" className="lightbox-btn-inline" onClick={() => setZoom((z) => !z)} aria-label={zoom ? 'Zoom out' : 'Zoom in'} aria-pressed={zoom}>
                {zoom ? <ZoomOut className="h-5 w-5" aria-hidden="true" /> : <ZoomIn className="h-5 w-5" aria-hidden="true" />}
              </button>
              {list.length > 1 && (
                <>
                  <button type="button" className="lightbox-btn-inline" onClick={() => go(-1)} aria-label="Previous photo">
                    <ChevronLeft className="h-5 w-5" aria-hidden="true" />
                  </button>
                  <button type="button" className="lightbox-btn-inline" onClick={() => go(1)} aria-label="Next photo">
                    <ChevronRight className="h-5 w-5" aria-hidden="true" />
                  </button>
                </>
              )}
            </div>
            {list.length > 1 && (
              <div className="gallery-thumbs scrollbar-none" role="group" aria-label="Choose photo">
                {list.map((it, i) => (
                  <button key={i} type="button" onClick={() => (setZoom(false), setIndex(i))} className={clsx('gallery-thumb', i === index && 'is-active')} aria-label={`Show photo ${i + 1}`} aria-current={i === index ? 'true' : undefined}>
                    <img src={it.thumb || it.src} alt="" loading="lazy" />
                  </button>
                ))}
              </div>
            )}
          </div>
        )}
      </Modal>
    </div>
  );
}
