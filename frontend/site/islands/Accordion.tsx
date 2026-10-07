import clsx from 'clsx';
import { Plus, Search, X } from 'lucide-react';
import { Fragment, useId, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { useDebounced } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';

export interface AccordionItem {
  q: string;
  /** Plain text answer; blank lines start new paragraphs. */
  a: string;
  category?: string;
}

export interface AccordionProps extends IslandBaseProps {
  items: AccordionItem[];
  /** 'single' keeps one panel open (default), 'multi' allows several. */
  mode?: 'single' | 'multi';
  /** Index opened initially (-1 = none, default 0). */
  defaultOpen?: number;
  /** Search box that filters + highlights questions and answers. */
  search?: boolean;
  searchPlaceholder?: string;
  /** Category pills built from item categories. */
  categories?: boolean;
  /** 1 or 2 columns on large screens. */
  columns?: 1 | 2;
  theme?: 'light' | 'dark';
}

function highlight(text: string, q: string): ReactNode {
  if (!q) return text;
  const re = new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'ig');
  return text.split(re).map((part, i) => (i % 2 === 1 ? <mark key={i}>{part}</mark> : <Fragment key={i}>{part}</Fragment>));
}

/** Animated FAQ accordion (WAI-ARIA accordion pattern) with optional search and category filter. */
export default function Accordion({ items, mode = 'single', defaultOpen = 0, search = false, searchPlaceholder = 'Search questions…', categories = false, columns = 1, theme = 'light' }: AccordionProps) {
  const uid = useId();
  const [open, setOpen] = useState<Set<number>>(() => new Set(defaultOpen >= 0 && defaultOpen < items.length ? [defaultOpen] : []));
  const [query, setQuery] = useState('');
  const [cat, setCat] = useState('all');
  const q = useDebounced(query.trim(), 150);
  const headers = useRef<(HTMLButtonElement | null)[]>([]);

  const cats = useMemo(() => Array.from(new Set(items.map((i) => i.category).filter(Boolean))) as string[], [items]);
  const shown = items
    .map((item, index) => ({ item, index }))
    .filter(({ item }) => (cat === 'all' || item.category === cat) && (!q || `${item.q} ${item.a}`.toLowerCase().includes(q.toLowerCase())));

  const toggle = (i: number) =>
    setOpen((prev) => {
      const next = new Set(mode === 'single' ? [] : prev);
      if (prev.has(i)) next.delete(i);
      else next.add(i);
      return next;
    });

  const onKey = (e: KeyboardEvent, pos: number) => {
    const list = shown.map((s) => headers.current[s.index]).filter(Boolean) as HTMLButtonElement[];
    let n = -1;
    if (e.key === 'ArrowDown') n = (pos + 1) % list.length;
    else if (e.key === 'ArrowUp') n = (pos - 1 + list.length) % list.length;
    else if (e.key === 'Home') n = 0;
    else if (e.key === 'End') n = list.length - 1;
    if (n >= 0) {
      e.preventDefault();
      list[n]?.focus();
    }
  };

  const half = columns === 2 ? Math.ceil(shown.length / 2) : shown.length;
  const groups = columns === 2 ? [shown.slice(0, half), shown.slice(half)] : [shown];
  let pos = -1;

  return (
    <div className={clsx('faq', theme === 'dark' && 'faq-dark')}>
      {(search || (categories && cats.length > 1)) && (
        <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          {categories && cats.length > 1 && (
            <div className="flex flex-wrap gap-2" role="toolbar" aria-label="Filter questions">
              {['all', ...cats].map((c) => (
                <button key={c} type="button" className={clsx('chip', c === cat && 'chip-active')} aria-pressed={c === cat} onClick={() => setCat(c)}>
                  {c === 'all' ? 'All' : c}
                </button>
              ))}
            </div>
          )}
          {search && (
            <label className="search-field sm:ml-auto sm:max-w-xs">
              <Search className="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
              <span className="sr-only">Search questions</span>
              <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder={searchPlaceholder} className="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm focus:outline-none focus:ring-0" />
              {query && (
                <button type="button" onClick={() => setQuery('')} aria-label="Clear search" className="text-slate-400 hover:text-slate-700">
                  <X className="h-4 w-4" aria-hidden="true" />
                </button>
              )}
            </label>
          )}
        </div>
      )}

      <div className={clsx('grid gap-4', columns === 2 && 'lg:grid-cols-2')}>
        {groups.map((group, g) => (
          <div key={g} className="space-y-3">
            {group.map(({ item, index }) => {
              const isOpen = open.has(index);
              const p = ++pos;
              const hid = `${uid}-h${index}`;
              const pid = `${uid}-p${index}`;
              return (
                <div key={index} className={clsx('acc', isOpen && 'is-open')}>
                  <h3 className="m-0">
                    <button
                      ref={(el) => (headers.current[index] = el)}
                      id={hid}
                      type="button"
                      className="acc-trigger"
                      aria-expanded={isOpen}
                      aria-controls={pid}
                      onClick={() => toggle(index)}
                      onKeyDown={(e) => onKey(e, p)}
                    >
                      <span>{highlight(item.q, q)}</span>
                      <span className="acc-icon" aria-hidden="true">
                        <Plus className="h-4 w-4" />
                      </span>
                    </button>
                  </h3>
                  <div id={pid} role="region" aria-labelledby={hid} className="acc-panel" {...(!isOpen ? { inert: '' } : {})}>
                    <div className="acc-panel-inner">
                      <div className="acc-body">
                        {item.a.split(/\n{2,}/).map((para, k) => (
                          <p key={k}>{highlight(para, q)}</p>
                        ))}
                      </div>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        ))}
      </div>
      {!shown.length && <p className="rounded-2xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500">No questions match “{query}”. Try another keyword or contact our admission team.</p>}
    </div>
  );
}
