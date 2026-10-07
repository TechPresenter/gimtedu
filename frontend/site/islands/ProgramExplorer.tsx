import clsx from 'clsx';
import { ArrowRight, Search, X } from 'lucide-react';
import { useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useDebounced, useStaticMode } from '../lib/hooks';
import { Icon } from '../lib/Icon';
import type { IslandBaseProps, LinkProp } from '../lib/types';

export interface ProgramExplorerProps extends IslandBaseProps {
  /** Filter categories; `key` is matched against each card's data-category tokens ('all' shows everything). */
  categories: { key: string; label: string; icon?: string }[];
  /** Show the search box (matches data-search + card text). Default true. */
  search?: boolean;
  searchPlaceholder?: string;
  emptyText?: string;
  /** Initially selected category key (default the first). */
  initial?: string;
  /** Keep the selected category in the URL query string (?{syncParam}=mba), e.g. 'category'. */
  syncParam?: string;
  /** 'pills' (homepage) or 'tiles' (programs page: icon tiles). */
  layout?: 'pills' | 'tiles';
  /** Grid column classes for the cards. */
  gridClass?: string;
  viewAll?: LinkProp;
}

const norm = (s: string) => s.toLowerCase().normalize('NFKD').replace(/[^\w\s]/g, ' ');

/**
 * Program explorer: category pills/tiles + search over server-rendered program cards (`data-slot="items"`, each
 * child with data-category="ug management" and optional data-search="keywords"). Animated filtering: moving cards
 * glide to their new position (FLIP, transform only) and new cards fade/scale in.
 */
export default function ProgramExplorer({
  categories,
  search = true,
  searchPlaceholder = 'Search programs…',
  emptyText = 'No programs match your selection.',
  initial,
  syncParam,
  layout = 'pills',
  gridClass = 'sm:grid-cols-2 lg:grid-cols-3',
  viewAll,
  slots,
}: ProgramExplorerProps) {
  const cards = useMemo(
    () =>
      (slots?.items ?? []).map((s, i) => {
        const text = norm(s.html.replace(/<[^>]+>/g, ' '));
        return { key: s.data.key || String(i), html: s.html, cats: (s.data.category || '').toLowerCase().split(/\s+/).filter(Boolean), text: `${norm(s.data.search || '')} ${text}` };
      }),
    [slots],
  );
  const fromUrl = syncParam ? new URLSearchParams(window.location.search).get(syncParam) : null;
  const [cat, setCat] = useState(() => (fromUrl && categories.some((c) => c.key === fromUrl) ? fromUrl : initial || categories[0]?.key || 'all'));
  const [query, setQuery] = useState('');
  const q = useDebounced(norm(query).trim(), 160);
  const isStatic = useStaticMode();

  const counts = useMemo(() => {
    const m: Record<string, number> = {};
    categories.forEach((c) => (m[c.key] = c.key === 'all' ? cards.length : cards.filter((card) => card.cats.includes(c.key.toLowerCase())).length));
    return m;
  }, [categories, cards]);

  const visible = cards.filter((c) => (cat === 'all' || c.cats.includes(cat.toLowerCase())) && (!q || q.split(/\s+/).every((w) => c.text.includes(w))));

  // FLIP: remember positions before the DOM updates
  const gridRef = useRef<HTMLDivElement>(null);
  const prevRects = useRef(new Map<string, DOMRect>());
  const snapshot = () => {
    const map = new Map<string, DOMRect>();
    gridRef.current?.querySelectorAll<HTMLElement>('[data-card-key]').forEach((el) => map.set(el.dataset.cardKey!, el.getBoundingClientRect()));
    prevRects.current = map;
  };

  useLayoutEffect(() => {
    const grid = gridRef.current;
    if (!grid || isStatic) return;
    grid.querySelectorAll<HTMLElement>('[data-card-key]').forEach((el, i) => {
      const before = prevRects.current.get(el.dataset.cardKey!);
      const after = el.getBoundingClientRect();
      if (before) {
        const dx = before.left - after.left;
        const dy = before.top - after.top;
        if (dx || dy) el.animate([{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'translate(0, 0)' }], { duration: 420, easing: 'cubic-bezier(.2,.8,.2,1)' });
      } else if (prevRects.current.size) {
        el.animate(
          [
            { opacity: 0, transform: 'translateY(14px) scale(.97)' },
            { opacity: 1, transform: 'none' },
          ],
          { duration: 380, delay: Math.min(i, 8) * 40, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' },
        );
      }
    });
    prevRects.current = new Map();
    const runtime = window.__gimtSite;
    if (runtime && runtime !== 'fallback') runtime.enhance(grid);
  }, [cat, q, isStatic]);

  const select = (key: string) => {
    if (key === cat) return;
    snapshot();
    setCat(key);
    if (syncParam) {
      const url = new URL(window.location.href);
      if (key === (categories[0]?.key ?? 'all')) url.searchParams.delete(syncParam);
      else url.searchParams.set(syncParam, key);
      window.history.replaceState(null, '', url);
    }
  };

  return (
    <div className="program-explorer">
      <div className={clsx('mb-8 flex flex-col gap-4', layout === 'pills' && 'lg:flex-row lg:items-center lg:justify-between')}>
        <div className={clsx(layout === 'tiles' ? 'grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8' : 'pill-row scrollbar-none')} role="toolbar" aria-label="Filter programs by category">
          {categories.map((c) => {
            const active = c.key === cat;
            return layout === 'tiles' ? (
              <button key={c.key} type="button" onClick={() => select(c.key)} aria-pressed={active} className={clsx('filter-tile', active && 'is-active')}>
                {c.icon && (
                  <span className="filter-tile-icon">
                    <Icon name={c.icon} className="h-5 w-5" />
                  </span>
                )}
                <span className="text-sm font-semibold">{c.label}</span>
              </button>
            ) : (
              <button key={c.key} type="button" onClick={() => select(c.key)} aria-pressed={active} className={clsx('chip', active && 'chip-active')}>
                {c.icon && <Icon name={c.icon} className="h-4 w-4" />}
                {c.label}
                <span className={clsx('chip-count', active && 'is-active')}>{counts[c.key] ?? 0}</span>
              </button>
            );
          })}
        </div>
        {(search || viewAll) && (
          <div className="flex items-center gap-3">
            {search && (
              <label className="search-field">
                <Search className="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                <span className="sr-only">Search programs</span>
                <input
                  type="search"
                  value={query}
                  onChange={(e) => {
                    snapshot();
                    setQuery(e.target.value);
                  }}
                  placeholder={searchPlaceholder}
                  className="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                />
                {query && (
                  <button type="button" onClick={() => setQuery('')} className="rounded-full p-0.5 text-slate-400 hover:text-slate-700" aria-label="Clear search">
                    <X className="h-4 w-4" aria-hidden="true" />
                  </button>
                )}
              </label>
            )}
            {viewAll && (
              <a href={viewAll.url} className="btn btn-outline btn-sm hidden shrink-0 sm:inline-flex">
                {viewAll.label}
                <ArrowRight className="h-4 w-4" aria-hidden="true" />
              </a>
            )}
          </div>
        )}
      </div>

      <p className="sr-only" aria-live="polite">
        {visible.length} {visible.length === 1 ? 'program' : 'programs'} shown
      </p>
      <div ref={gridRef} className={clsx('grid gap-6', gridClass)}>
        {visible.map((c) => (
          <div key={c.key} data-card-key={c.key} className="h-full [&>*]:h-full" dangerouslySetInnerHTML={{ __html: c.html }} />
        ))}
      </div>
      {!visible.length && (
        <div className="empty-state rounded-2xl border border-dashed border-slate-200 bg-slate-50/60">
          <span className="empty-state-icon">
            <Search className="h-6 w-6" aria-hidden="true" />
          </span>
          <p className="mt-3 font-semibold text-brand-900">{emptyText}</p>
          <button
            type="button"
            className="btn btn-secondary btn-sm mt-4"
            onClick={() => {
              setQuery('');
              select(categories[0]?.key ?? 'all');
            }}
          >
            Show all programs
          </button>
        </div>
      )}
    </div>
  );
}
