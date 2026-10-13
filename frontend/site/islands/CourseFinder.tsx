import clsx from 'clsx';
import { ArrowDown, ChevronDown, Phone, RotateCcw, Search, X } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useDebounced, useStaticMode } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';
import { norm, openEnquiry, slotInner, type Option } from './home/shared';

interface QuickPick {
  key: string;
  label: string;
  level?: string;
  cat?: string;
}

interface Filters {
  q: string;
  stream: string;
  level: string;
  duration: string;
  elig: string;
  cat: string;
}

export interface CourseFinderProps extends IslandBaseProps {
  streams: Option[];
  levels: Option[];
  durations: Option[];
  eligibility: Option[];
  /** CMS category pills mapped onto filters (level or category token). */
  quick: QuickPick[];
  /** Filters from the query string (server-side state of the fallback). */
  initial: Filters;
  /** Cards shown before "Show all" while no filter is active (CMS programs.limit). */
  limit: number;
  total: number;
  /** Section id (the URL keeps #anchor when filters change). */
  anchor: string;
  /** "#enquiry" (on-page Smart Enquiry) or the admissions enquiry form URL. */
  counsellorUrl: string;
  allUrl?: string | null;
}

const EMPTY: Filters = { q: '', stream: '', level: '', duration: '', elig: '', cat: '' };
const KEYS: (keyof Filters)[] = ['q', 'stream', 'level', 'duration', 'elig', 'cat'];

interface Card {
  key: string;
  html: string;
  name: string;
  stream: string;
  level: string;
  duration: string;
  elig: string[];
  cat: string[];
  text: string;
}

/**
 * Course Finder: search + stream / degree / duration / eligibility filters + quick-pick pills over the server-rendered
 * program cards (slot "items", each wrapper carries data-stream/level/duration/elig/cat/search). Facet counts, removable
 * filter tags, URL-synced state (?stream=&level=&duration=&elig=&cat=&q=), FLIP-animated grid, "Show all" expansion,
 * empty state with a counsellor callback. Links such as <a data-finder-stream="cse"> anywhere on the page set the
 * stream filter and scroll here.
 */
export default function CourseFinder({ streams, levels, durations, eligibility, quick, initial, limit, total, counsellorUrl, slots }: CourseFinderProps) {
  const cards = useMemo<Card[]>(
    () =>
      (slots?.items ?? []).map((s, i) => ({
        key: s.data.key || String(i),
        html: slotInner(s.html),
        name: s.data.name || '',
        stream: s.data.stream || '',
        level: s.data.level || '',
        duration: s.data.duration || '',
        elig: (s.data.elig || '').split(/\s+/).filter(Boolean),
        cat: (s.data.cat || '').split(/\s+/).filter(Boolean),
        text: norm(`${s.data.search || ''} ${s.data.name || ''}`),
      })),
    [slots],
  );
  const [f, setF] = useState<Filters>({ ...EMPTY, ...initial });
  const [expanded, setExpanded] = useState(false);
  const q = useDebounced(norm(f.q), 160);
  const isStatic = useStaticMode();
  const rootRef = useRef<HTMLDivElement>(null);
  const gridRef = useRef<HTMLDivElement>(null);
  const prevRects = useRef(new Map<string, DOMRect>());

  const matches = useCallback(
    (c: Card, ff: Filters, query: string, skip?: keyof Filters) =>
      (skip === 'stream' || !ff.stream || c.stream === ff.stream) &&
      (skip === 'level' || !ff.level || c.level === ff.level) &&
      (skip === 'duration' || !ff.duration || c.duration === ff.duration) &&
      (skip === 'elig' || !ff.elig || c.elig.includes(ff.elig)) &&
      (skip === 'cat' || !ff.cat || c.cat.includes(ff.cat)) &&
      (!query || query.split(' ').every((w) => c.text.includes(w))),
    [],
  );

  const active = KEYS.some((k) => f[k] !== '');
  const visible = cards.filter((c) => matches(c, f, q));
  const shown = active || expanded ? visible : visible.slice(0, limit);

  // facet counts: matches with every other filter applied
  const count = (facet: keyof Filters, value: string) => cards.filter((c) => matches(c, { ...f, [facet]: value }, q, undefined)).length;

  const snapshot = () => {
    const map = new Map<string, DOMRect>();
    gridRef.current?.querySelectorAll<HTMLElement>('[data-card-key]').forEach((el) => map.set(el.dataset.cardKey!, el.getBoundingClientRect()));
    prevRects.current = map;
  };

  const update = (patch: Partial<Filters>) => {
    snapshot();
    setF((prev) => ({ ...prev, ...patch }));
  };

  // keep the URL shareable (and the no-JS fallback in sync)
  useEffect(() => {
    const url = new URL(window.location.href);
    KEYS.forEach((k) => (f[k] ? url.searchParams.set(k, f[k]) : url.searchParams.delete(k)));
    if (url.href !== window.location.href) window.history.replaceState(window.history.state, '', url);
  }, [f]);

  // FLIP: cards glide to their new place, new ones fade/scale in (transform + opacity only)
  useLayoutEffect(() => {
    const grid = gridRef.current;
    if (!grid || isStatic || !prevRects.current.size) {
      prevRects.current = new Map();
      return;
    }
    grid.querySelectorAll<HTMLElement>('[data-card-key]').forEach((el, i) => {
      const before = prevRects.current.get(el.dataset.cardKey!);
      const after = el.getBoundingClientRect();
      if (before) {
        const dx = before.left - after.left;
        const dy = before.top - after.top;
        if (dx || dy) el.animate([{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }], { duration: 420, easing: 'cubic-bezier(.2,.8,.2,1)' });
      } else {
        el.animate(
          [
            { opacity: 0, transform: 'translateY(16px) scale(.97)' },
            { opacity: 1, transform: 'none' },
          ],
          { duration: 380, delay: Math.min(i, 8) * 45, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' },
        );
      }
    });
    prevRects.current = new Map();
  }, [q, f.stream, f.level, f.duration, f.elig, f.cat, expanded, isStatic]);

  // re-run vanilla enhancements (lazy images, link ripples…) on injected card HTML
  useEffect(() => {
    const runtime = window.__gimtSite;
    if (runtime && runtime !== 'fallback' && gridRef.current) runtime.enhance(gridRef.current);
  }, [shown.length, q, f]);

  // department cards elsewhere on the page: <a data-finder-stream="cse">
  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      const a = (e.target as Element | null)?.closest?.<HTMLAnchorElement>('a[data-finder-stream]');
      if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const value = a.getAttribute('data-finder-stream') || '';
      if (!streams.some((s) => s.value === value)) return;
      e.preventDefault();
      snapshot();
      setF({ ...EMPTY, stream: value });
      rootRef.current?.scrollIntoView({ behavior: isStatic ? 'auto' : 'smooth', block: 'start' });
      window.setTimeout(() => rootRef.current?.querySelector<HTMLElement>('select[name="stream"]')?.focus({ preventScroll: true }), isStatic ? 0 : 500);
    };
    document.addEventListener('click', onClick);
    return () => document.removeEventListener('click', onClick);
  }, [streams, isStatic]);

  const reset = () => {
    snapshot();
    setF(EMPTY);
    setExpanded(false);
  };

  const quickActive = (p: QuickPick) => (p.key === 'all' ? !active : p.level ? f.level === p.level && !f.cat : f.cat === p.cat);
  const pickQuick = (p: QuickPick) => {
    snapshot();
    if (p.key === 'all') return setF(EMPTY);
    setF((prev) => (p.level ? { ...prev, level: prev.level === p.level && !prev.cat ? '' : p.level, cat: '' } : { ...prev, cat: prev.cat === p.cat ? '' : p.cat || '' }));
  };

  const labelOf = (list: Option[], v: string) => list.find((o) => o.value === v)?.label ?? v;
  const tags: { key: keyof Filters; label: string }[] = [
    f.q ? { key: 'q' as const, label: `“${f.q}”` } : null,
    f.stream ? { key: 'stream' as const, label: labelOf(streams, f.stream) } : null,
    f.level ? { key: 'level' as const, label: labelOf(levels, f.level) } : null,
    f.duration ? { key: 'duration' as const, label: labelOf(durations, f.duration) } : null,
    f.elig ? { key: 'elig' as const, label: labelOf(eligibility, f.elig) } : null,
    f.cat ? { key: 'cat' as const, label: quick.find((p) => p.cat === f.cat)?.label ?? f.cat } : null,
  ].filter((t): t is { key: keyof Filters; label: string } => t !== null);

  const select = (name: keyof Filters, label: string, list: Option[]) => (
    <label className="relative block min-w-0">
      <span className="sr-only">{label}</span>
      <select
        name={name}
        value={f[name]}
        onChange={(e) => update({ [name]: e.target.value } as Partial<Filters>)}
        className={clsx(
          'h-12 w-full cursor-pointer appearance-none truncate rounded-full border bg-white pl-4 pr-8 text-[13.5px] font-semibold shadow-sm sm:text-[14px] transition focus:border-brand-400 focus:outline-none focus:ring-4 focus:ring-accent-500/10',
          f[name] ? 'border-brand-900 text-brand-900 ring-1 ring-inset ring-brand-900' : 'border-warm-2 text-brand-900 hover:border-[#E2DAD0]',
        )}
      >
        <option value="">{label}: All</option>
        {list.map((o) => {
          const n = count(name, o.value);
          return (
            <option key={o.value} value={o.value}>
              {o.label} ({n})
            </option>
          );
        })}
      </select>
      <ChevronDown className="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
    </label>
  );

  return (
    <div ref={rootRef} className="course-finder scroll-mt-28">
      <form
        className="grid grid-cols-2 gap-2.5 rounded-[26px] border border-warm-2 bg-white/80 p-2.5 shadow-[0_18px_40px_-30px_rgba(7,26,59,.35)] sm:gap-3 sm:p-3 lg:grid-cols-[minmax(0,1.5fr)_repeat(4,minmax(0,1fr))_auto]"
        role="search"
        aria-label="Find a course"
        onSubmit={(e) => e.preventDefault()}
      >
        <label className="relative col-span-2 block min-w-0 lg:col-span-1">
          <span className="sr-only">Search programs</span>
          <Search className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
          <input
            type="search"
            name="q"
            value={f.q}
            onChange={(e) => update({ q: e.target.value })}
            placeholder="Search a course, e.g. MBA"
            autoComplete="off"
            className="h-12 w-full rounded-full border border-warm-2 bg-white pl-11 pr-10 text-[14px] text-brand-900 shadow-sm placeholder:text-slate-400 focus:border-brand-400 focus:outline-none focus:ring-4 focus:ring-accent-500/10 [&::-webkit-search-cancel-button]:hidden"
          />
          {f.q && (
            <button type="button" onClick={() => update({ q: '' })} className="absolute right-3 top-1/2 -translate-y-1/2 rounded-full p-1 text-slate-400 hover:bg-warm hover:text-brand-900" aria-label="Clear search">
              <X className="h-4 w-4" aria-hidden="true" />
            </button>
          )}
        </label>
        {select('stream', 'Stream', streams)}
        {select('level', 'Degree', levels)}
        {select('duration', 'Duration', durations)}
        {select('elig', 'Eligibility', eligibility)}
        <button type="button" onClick={reset} disabled={!active} className={clsx('btn btn-explore col-span-2 h-12 lg:col-span-1', !active && 'max-lg:hidden')}>
          <RotateCcw className="h-4 w-4" aria-hidden="true" />
          Reset
        </button>
      </form>

      {quick.length > 0 && (
        <div className="pill-row scrollbar-none mt-5" role="group" aria-label="Quick picks">
          {quick.map((p) => {
            const on = quickActive(p);
            return (
              <button key={p.key} type="button" onClick={() => pickQuick(p)} aria-pressed={on} className={clsx('chip !py-2 !text-[14px]', on && 'chip-active')}>
                {p.label}
              </button>
            );
          })}
        </div>
      )}

      <div className="mt-6 flex flex-wrap items-center gap-x-4 gap-y-2">
        <p className="text-[14px] font-semibold text-slate-600" aria-live="polite" aria-atomic="true">
          <span className="font-display text-lg font-extrabold text-brand-900">{visible.length}</span> {visible.length === 1 ? 'program' : 'programs'} found
          {!active && <span className="sr-only"> out of {total}</span>}
        </p>
        {tags.length > 0 && (
          <ul className="flex flex-wrap gap-2" aria-label="Active filters">
            {tags.map((t) => (
              <li key={t.key}>
                <button
                  type="button"
                  onClick={() => update({ [t.key]: '' } as Partial<Filters>)}
                  className="inline-flex items-center gap-1.5 rounded-full bg-brand-900 py-1 pl-3 pr-2 text-[12.5px] font-semibold text-white transition hover:bg-brand-800"
                >
                  {t.label}
                  <X className="h-3.5 w-3.5 opacity-80" aria-hidden="true" />
                  <span className="sr-only">— remove filter</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      <div ref={gridRef} className="mt-4 -mx-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-3 scrollbar-none sm:mx-0 sm:grid sm:overflow-visible sm:px-0 sm:pb-0 sm:grid-cols-2 sm:gap-6 lg:grid-cols-3" aria-label="Programs">
        {shown.map((c) => (
          <div key={c.key} data-card-key={c.key} className="h-full w-[85%] shrink-0 snap-start sm:w-auto [&>*]:h-full" dangerouslySetInnerHTML={{ __html: c.html }} />
        ))}
      </div>

      {visible.length === 0 && (
        <div className="empty-state mt-4 rounded-[22px] border border-dashed border-[#E2DAD0] bg-white">
          <span className="empty-state-icon">
            <Search className="h-6 w-6" aria-hidden="true" />
          </span>
          <p className="mt-3 font-display text-lg font-bold text-brand-900">No program matches these filters</p>
          <p className="mt-1 text-sm text-slate-500">Try fewer filters, or let a counsellor suggest the right course for you.</p>
          <div className="mt-5 flex flex-wrap justify-center gap-3">
            <button type="button" className="btn btn-secondary btn-sm" onClick={reset}>
              Clear filters
            </button>
            <button
              type="button"
              className="btn btn-callback btn-sm"
              onClick={() =>
                openEnquiry(counsellorUrl, {
                  level: f.level || undefined,
                  note: `Course Finder: no match for ${tags.map((t) => t.label).join(', ') || 'my filters'}`,
                })
              }
            >
              <Phone className="h-4 w-4" aria-hidden="true" />
              Talk to a counsellor
            </button>
          </div>
        </div>
      )}

      {!active && !expanded && visible.length > limit && (
        <div className="mt-10 flex justify-center">
          <button
            type="button"
            className="btn btn-brochure"
            onClick={() => {
              snapshot();
              setExpanded(true);
              window.setTimeout(() => gridRef.current?.querySelectorAll<HTMLElement>('[data-card-key]')[limit]?.querySelector<HTMLElement>('h3 a')?.focus({ preventScroll: true }), 50);
            }}
          >
            Show all {visible.length} programs
            <ArrowDown className="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
      )}
    </div>
  );
}
