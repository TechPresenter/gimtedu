import { createContext, useContext, useLayoutEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import clsx from 'clsx';
import { ArrowRight, ImageIcon, Lock } from 'lucide-react';
import { appUrl } from '@/lib/config';
import { formatNumber } from '@/lib/format';
import type { Data, LinkValue, PreviewData } from '../types';

/**
 * Building blocks for the live previews (page builder, home sections, banners, menus). They approximate the public
 * website "Design v3" (docs/DESIGN.md): deep navy #0B2A5B headings and dark bands, orange-red #D03E16 filled CTAs
 * (#F05A28 large accents), warm greys #F5F2EE / #EDE8E2, body text #4A5568, Plus Jakarta Sans 700/800 headings
 * (font-display) over Inter, rounded cards and pill buttons. Previews are always rendered in the light website theme
 * and never use dark: variants.
 */
export const ORANGE = '#D03E16';
export const ACCENT = '#F05A28';
export const NAVY = '#0B2A5B';

interface PreviewCtxValue {
  mobile: boolean;
  data?: PreviewData;
}
export const PreviewCtx = createContext<PreviewCtxValue>({ mobile: false });
export const usePreview = () => useContext(PreviewCtx);

export const gridCols: Record<number, string> = { 1: 'grid-cols-1', 2: 'grid-cols-2', 3: 'grid-cols-3', 4: 'grid-cols-4', 5: 'grid-cols-5', 6: 'grid-cols-6' };

/** Columns for the current preview device. */
export function useCols(desktop: number, mobile = 1): string {
  const { mobile: m } = usePreview();
  return gridCols[m ? mobile : desktop] ?? 'grid-cols-1';
}

export const tintClass: Record<string, string> = {
  // same mapping as the website .tint-* classes (assets/css/src/style.css)
  lavender: 'bg-[#E7E1FF]', sky: 'bg-[#DCEBFB]', aqua: 'bg-[#D9F6F4]', cream: 'bg-[#EDE8E2]', sand: 'bg-[#EDE8E2]', orange: 'bg-[#FDEBE4]', ink: 'bg-[#0B2A5B] text-white',
};
const autoTints = ['lavender', 'sky', 'aqua', 'orange', 'sand'];
export function tintFor(tint: unknown, i: number): string {
  return tintClass[String(tint || '')] ?? tintClass[autoTints[i % autoTints.length]];
}

export function paragraphs(text: unknown): string[] {
  return String(text ?? '').split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean);
}

export function hasLink(l: unknown): l is LinkValue {
  return !!l && typeof l === 'object' && !!(l as LinkValue).label && !!(l as LinkValue).url;
}

/** Stat value: the live number when a source is set and known, otherwise the typed value. */
export function statText(item: Data, stats?: Record<string, number | null>): string {
  const live = item.source && stats ? stats[item.source as string] : null;
  const raw = live !== null && live !== undefined ? live : item.value;
  const n = Number(String(raw ?? '').replace(/,/g, ''));
  const v = raw === '' || raw === null || raw === undefined ? '—' : Number.isFinite(n) ? formatNumber(n, Number.isInteger(n) ? 0 : 1) : String(raw);
  return `${item.prefix ?? ''}${v}${item.suffix ?? ''}`;
}

export function Img({ src, alt = '', className, style }: { src?: string | null; alt?: string; className?: string; style?: CSSProperties }) {
  if (!src) {
    return (
      <div className={clsx('flex items-center justify-center bg-[#FAF8F5] text-slate-300', className)} style={style} role="img" aria-label="No image">
        <ImageIcon className="h-8 w-8" />
      </div>
    );
  }
  return <img src={appUrl(src)} alt={alt} loading="lazy" className={clsx('object-cover', className)} style={style} />;
}

export function Eyebrow({ children, dark }: { children: ReactNode; dark?: boolean }) {
  return <p className={clsx('mb-2 text-[11px] font-bold uppercase tracking-[0.18em]', dark ? 'text-[#FDB48C]' : 'text-[#D03E16]')}>{children}</p>;
}

export function Heading({ eyebrow, title, sub, center, dark, size = 'md' }: { eyebrow?: unknown; title?: unknown; sub?: unknown; center?: boolean; dark?: boolean; size?: 'md' | 'lg' }) {
  if (!eyebrow && !title && !sub) return null;
  return (
    <div className={clsx('mb-7', center && 'mx-auto max-w-2xl text-center')}>
      {!!eyebrow && <Eyebrow dark={dark}>{String(eyebrow)}</Eyebrow>}
      {!!title && <h2 className={clsx('font-display font-extrabold leading-[1.1] tracking-[-0.02em]', size === 'lg' ? 'text-[40px]' : 'text-[34px]', dark ? 'text-white' : 'text-[#0B2A5B]')}>{String(title)}</h2>}
      {!!sub && <p className={clsx('mt-2 text-[15px] leading-relaxed', dark ? 'text-white/70' : 'text-[#4A5568]')}>{String(sub)}</p>}
    </div>
  );
}

export function Pill({ link, variant = 'primary', small }: { link: unknown; variant?: 'primary' | 'outline' | 'light' | 'ink'; small?: boolean }) {
  if (!hasLink(link)) return null;
  const cls = {
    primary: 'bg-[#D03E16] text-white',
    outline: 'border border-[#0B2A5B] text-[#0B2A5B]',
    light: 'bg-white text-[#0B2A5B]',
    ink: 'bg-[#0B2A5B] text-white',
  }[variant];
  return (
    <span className={clsx('inline-flex items-center gap-1.5 rounded-full font-semibold', small ? 'px-3.5 py-1.5 text-xs' : 'px-5 py-2.5 text-sm', cls)}>
      {link.label}
      {variant !== 'outline' && <ArrowRight className="h-3.5 w-3.5" />}
    </span>
  );
}

const bgClass: Record<string, string> = {
  white: 'bg-white', light: 'bg-[#F5F2EE]', cream: 'bg-[#FAF8F5]', sand: 'bg-[#EDE8E2]', dark: 'bg-gradient-to-br from-[#071A3B] to-[#0B2A5B] text-white',
};
const padClass: Record<string, string> = { normal: 'py-14', tight: 'py-7', none: 'py-0' };

/** Section wrapper with background + padding (block settings). */
export function Band({ bg = 'white', padding = 'normal', children, className, grid }: { bg?: string; padding?: string; children: ReactNode; className?: string; grid?: boolean }) {
  const { mobile } = usePreview();
  return (
    <section className={clsx('relative overflow-hidden', bgClass[bg] ?? 'bg-white', padClass[padding] ?? 'py-14', className)}>
      {grid && <div className="pointer-events-none absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px)', backgroundSize: '36px 36px' }} aria-hidden />}
      <div className={clsx('relative mx-auto', mobile ? 'px-5' : 'max-w-[1160px] px-10')}>{children}</div>
    </section>
  );
}

export function ProgramCard({ p }: { p: PreviewData['programs'][number] }) {
  const level = { UG: 'Undergraduate', PG: 'Postgraduate', PhD: 'Doctoral', Diploma: 'Diploma', Certificate: 'Certificate' }[p.level] ?? p.level;
  return (
    <div className="overflow-hidden rounded-[20px] border border-[#EDE8E2] bg-white shadow-[0_10px_30px_-18px_rgba(11,42,91,.35)]">
      <div className="relative">
        <Img src={p.image} alt={p.name} className="aspect-[16/10] w-full" />
        <div className="absolute inset-0 bg-gradient-to-t from-[#071A3B]/70 via-transparent to-transparent" />
        {!!(p.is_featured || p.is_popular) && <span className="absolute left-3 top-3 rounded-full bg-[#FDD900] px-2.5 py-0.5 text-[10px] font-bold uppercase text-[#0B2A5B]">Featured</span>}
        <span className="absolute right-3 top-3 rounded-full bg-white px-2.5 py-0.5 text-[10px] font-semibold text-[#0B2A5B]">{level}</span>
        <span className="absolute bottom-2.5 left-3 text-xs font-bold text-white">{p.short_name}</span>
      </div>
      <div className="p-4">
        <h3 className="line-clamp-2 font-display text-[17px] font-bold leading-snug text-[#0B2A5B]">{p.name}</h3>
        <div className="mt-3 grid grid-cols-2 divide-x divide-[#EDE8E2] rounded-xl border border-[#EDE8E2] bg-[#FAF8F5] py-2 text-center">
          <span><span className="block text-[9px] font-bold uppercase tracking-wider text-[#64748B]">Duration</span><span className="text-xs font-bold text-[#0B2A5B]">{p.duration_label || '—'}</span></span>
          <span><span className="block text-[9px] font-bold uppercase tracking-wider text-[#64748B]">Fee</span><span className="text-xs font-bold text-[#0B2A5B]">{p.fee_label || (p.fee_per_year ? `₹${formatNumber(p.fee_per_year)}/yr` : '—')}</span></span>
        </div>
        <div className="mt-3 flex items-center justify-between">
          <span className="inline-flex items-center gap-1 rounded-full bg-[#D03E16] px-3 py-1 text-[11px] font-semibold text-white">Apply <ArrowRight className="h-3 w-3" /></span>
          <span className="text-[11px] font-semibold text-[#C93C14]">View details →</span>
        </div>
      </div>
    </div>
  );
}

type BannerLike = { title?: string | null; cta_text?: string | null; cta_url?: string | null; cta2_text?: string | null; cta2_url?: string | null };

/** Why a live "home hero" banner is NOT shown in the website's hero spotlight (null = shown). Mirrors includes/partials/home.php. */
export function spotlightSkipReason(b: BannerLike, heroTitle?: string | null): string | null {
  const hasButton = (!!b.cta_text && !!b.cta_url) || (!!b.cta2_text && !!b.cta2_url);
  if (!hasButton) return 'Add a button (text + link) — the hero spotlight only shows slides that lead somewhere.';
  if (heroTitle && String(b.title ?? '').trim().toLowerCase() === heroTitle.trim().toLowerCase()) return 'Same heading as the home hero, so the website skips it to avoid repeating the headline.';
  return null;
}

/** Banners the home hero spotlight rotates through (max 4), in display order. */
export function spotlightSlides<T extends BannerLike>(banners: T[], heroTitle?: string | null): T[] {
  return banners.filter((b) => !spotlightSkipReason(b, heroTitle)).slice(0, 4);
}

/** Recruiter logo (fixed height, width auto, company name as alt) or a text wordmark when no logo is set. */
export function RecruiterLogo({ name, logo }: { name: string; logo?: string | null }) {
  return (
    <span className="inline-flex h-12 shrink-0 items-center justify-center rounded-xl border border-[#EDE8E2] bg-white px-4">
      {logo ? <img src={appUrl(logo)} alt={name} loading="lazy" className="h-7 w-auto max-w-[150px] object-contain" />
        : <span className="whitespace-nowrap font-display text-sm font-extrabold tracking-tight text-[#0B2A5B]/75">{name}</span>}
    </span>
  );
}

/** Fake browser chrome around a preview. */
export function BrowserChrome({ url, children, className }: { url: string; children: ReactNode; className?: string }) {
  return (
    <div className={clsx('overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card dark:border-slate-700', className)}>
      <div className="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-800">
        <span className="flex gap-1" aria-hidden>
          <span className="h-2.5 w-2.5 rounded-full bg-red-300" />
          <span className="h-2.5 w-2.5 rounded-full bg-amber-300" />
          <span className="h-2.5 w-2.5 rounded-full bg-emerald-300" />
        </span>
        <span className="flex min-w-0 flex-1 items-center gap-1.5 truncate rounded-md bg-white px-2.5 py-1 text-[11px] text-slate-500 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-700">
          <Lock className="h-3 w-3 shrink-0 text-emerald-500" />
          <span className="truncate">{url}</span>
        </span>
      </div>
      {children}
    </div>
  );
}

/**
 * Renders children at a fixed virtual width (1280 desktop / 390 mobile) and scales them down to fit the pane, so the
 * preview shows the real desktop layout instead of a squeezed one.
 */
export function ScaledFrame({ width, children, maxHeight }: { width: number; children: ReactNode; maxHeight?: number }) {
  const outer = useRef<HTMLDivElement>(null);
  const inner = useRef<HTMLDivElement>(null);
  const [box, setBox] = useState({ scale: 1, height: 0, offset: 0 });
  useLayoutEffect(() => {
    const o = outer.current;
    const i = inner.current;
    if (!o || !i) return;
    const measure = () => {
      const avail = o.clientWidth;
      const scale = Math.min(1, avail / width);
      setBox({ scale, height: Math.ceil(i.offsetHeight * scale), offset: scale < 1 ? 0 : Math.max(0, (avail - width) / 2) });
    };
    measure();
    if (typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(measure);
    ro.observe(o);
    ro.observe(i);
    return () => ro.disconnect();
  }, [width]);
  return (
    <div ref={outer} className={clsx('relative w-full bg-[#E9E4DE]', maxHeight && 'overflow-y-auto')} style={{ height: maxHeight ? Math.min(maxHeight, box.height || maxHeight) : box.height || undefined }}>
      <div style={{ height: box.height || undefined, overflow: 'hidden' }}>
        <div ref={inner} className="bg-white font-sans text-[#4A5568] antialiased" style={{ width, transform: `scale(${box.scale})`, transformOrigin: 'top left', marginLeft: box.offset }}>
          {children}
        </div>
      </div>
    </div>
  );
}
