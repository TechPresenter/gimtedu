import clsx from 'clsx';
import { ArrowRight, CalendarDays, MapPin, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState, type CSSProperties } from 'react';
import type { IslandBaseProps, LinkProp } from '../lib/types';

export interface RemoteItem {
  title: string;
  url?: string;
  image?: string;
  date?: string;
  excerpt?: string;
  category?: string;
  location?: string;
}

export interface SkeletonListProps extends IslandBaseProps {
  /** Number of placeholders (and max items rendered). */
  count?: number;
  variant?: 'card' | 'list' | 'event';
  /** Grid classes (card variant). */
  gridClass?: string;
  /**
   * Optional JSON endpoint (GET). Accepts the api_ok envelope with data = items[] or { items: [] }.
   * Without it the component only renders shimmering placeholders (loading-state demo / slot reservation).
   */
  endpoint?: string;
  emptyText?: string;
  viewAll?: LinkProp;
}

/** Shimmer placeholder card. */
export function SkeletonCard({ variant = 'card' }: { variant?: SkeletonListProps['variant'] }) {
  if (variant === 'list' || variant === 'event')
    return (
      <div className="flex gap-4 rounded-2xl border border-slate-100 bg-white p-4" aria-hidden="true">
        <span className="skeleton h-16 w-16 shrink-0 rounded-xl" />
        <span className="flex-1 space-y-2.5 py-1">
          <span className="skeleton block h-3 w-1/4 rounded" />
          <span className="skeleton block h-4 w-4/5 rounded" />
          <span className="skeleton block h-3 w-1/2 rounded" />
        </span>
      </div>
    );
  return (
    <div className="overflow-hidden rounded-2xl border border-slate-100 bg-white" aria-hidden="true">
      <span className="skeleton block aspect-[16/10]" />
      <span className="block space-y-3 p-5">
        <span className="skeleton block h-3 w-1/3 rounded" />
        <span className="skeleton block h-5 w-11/12 rounded" />
        <span className="skeleton block h-3 w-full rounded" />
        <span className="skeleton block h-3 w-2/3 rounded" />
      </span>
    </div>
  );
}

const fmtDate = (d?: string) => {
  if (!d) return { day: '', mon: '', full: '' };
  const dt = new Date(d.replace(' ', 'T'));
  if (Number.isNaN(dt.getTime())) return { day: '', mon: '', full: d };
  return {
    day: dt.toLocaleDateString('en-IN', { day: '2-digit' }),
    mon: dt.toLocaleDateString('en-IN', { month: 'short' }),
    full: dt.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }),
  };
};

/** Skeleton loaders that resolve into news/event cards fetched from a JSON endpoint (with empty/error states). */
export default function SkeletonList({ count = 3, variant = 'card', gridClass = 'sm:grid-cols-2 lg:grid-cols-3', endpoint, emptyText = 'Nothing to show yet — please check back soon.', viewAll }: SkeletonListProps) {
  const [items, setItems] = useState<RemoteItem[] | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    if (!endpoint) return;
    setError('');
    setItems(null);
    try {
      const res = await fetch(endpoint, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok || json.ok === false) throw new Error(json.message || 'Request failed');
      const data = json.data ?? json;
      setItems((Array.isArray(data) ? data : data.items ?? []).slice(0, count));
    } catch {
      setError('We couldn’t load this section right now.');
    }
  }, [endpoint, count]);

  useEffect(() => {
    void load();
  }, [load]);

  const grid = variant === 'card' ? clsx('grid gap-6', gridClass) : 'grid gap-3';

  if (error)
    return (
      <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500">
        <p>{error}</p>
        <button type="button" className="btn btn-secondary btn-sm" onClick={() => void load()}>
          <RefreshCw className="h-4 w-4" aria-hidden="true" /> Try again
        </button>
      </div>
    );

  if (!items)
    return (
      <div className={grid} role="status" aria-live="polite" aria-busy="true">
        <span className="sr-only">Loading…</span>
        {Array.from({ length: count }, (_, i) => (
          <SkeletonCard key={i} variant={variant} />
        ))}
      </div>
    );

  if (!items.length) return <p className="rounded-2xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500">{emptyText}</p>;

  return (
    <div>
      <ul className={grid}>
        {items.map((it, i) => {
          const d = fmtDate(it.date);
          return (
            <li key={i} className="animate-fade-up" style={{ animationDelay: `${i * 70}ms` } as CSSProperties}>
              {variant === 'card' ? (
                <article className="card card-lift group flex h-full flex-col overflow-hidden">
                  {it.image && (
                    <div className="img-zoom aspect-[16/10]">
                      <img src={it.image} alt="" loading="lazy" className="h-full w-full object-cover" />
                    </div>
                  )}
                  <div className="flex flex-1 flex-col p-5">
                    <p className="text-xs font-semibold uppercase tracking-wider text-accent-600">{[it.category, d.full].filter(Boolean).join(' · ')}</p>
                    <h3 className="mt-1.5 text-lg font-bold leading-snug text-brand-900">{it.url ? <a href={it.url} className="stretched-link">{it.title}</a> : it.title}</h3>
                    {it.excerpt && <p className="mt-2 line-clamp-3 text-sm text-slate-600">{it.excerpt}</p>}
                  </div>
                </article>
              ) : (
                <article className="group relative flex gap-4 rounded-2xl border border-slate-100 bg-white p-4 transition hover:border-brand-200 hover:shadow-card">
                  <span className="date-tile">
                    <span className="date-tile-day">{d.day || '—'}</span>
                    <span className="date-tile-mon">{d.mon}</span>
                  </span>
                  <span className="min-w-0">
                    {it.category && <span className="badge badge-blue mb-1">{it.category}</span>}
                    <span className="block font-semibold leading-snug text-brand-900">{it.url ? <a href={it.url} className="stretched-link">{it.title}</a> : it.title}</span>
                    <span className="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-500">
                      {d.full && (
                        <span className="inline-flex items-center gap-1">
                          <CalendarDays className="h-3.5 w-3.5" aria-hidden="true" />
                          {d.full}
                        </span>
                      )}
                      {it.location && (
                        <span className="inline-flex items-center gap-1">
                          <MapPin className="h-3.5 w-3.5" aria-hidden="true" />
                          {it.location}
                        </span>
                      )}
                    </span>
                  </span>
                </article>
              )}
            </li>
          );
        })}
      </ul>
      {viewAll && (
        <a href={viewAll.url} className="link-arrow mt-6">
          {viewAll.label} <ArrowRight className="h-4 w-4" aria-hidden="true" />
        </a>
      )}
    </div>
  );
}
