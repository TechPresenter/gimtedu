import { useEffect, useMemo, useRef, useState } from 'react';
import { Dialog, DialogPanel } from '@headlessui/react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowRight, CornerDownLeft, Search, X } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import { Spinner } from '@/components/ui';

export interface SearchItem {
  id: number | string;
  title: string;
  subtitle?: string;
  url: string;
  badge?: string;
  image?: string | null;
}
export interface SearchGroup {
  key: string;
  label: string;
  items: SearchItem[];
}

/** Ctrl/Cmd+K command palette backed by GET /api/search?q= (grouped results). */
export function GlobalSearch({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [q, setQ] = useState('');
  const [active, setActive] = useState(0);
  const debounced = useDebounce(q.trim(), 250);
  const navigate = useNavigate();
  const inputRef = useRef<HTMLInputElement>(null);
  const { data, isFetching, error } = useQuery({
    queryKey: ['global-search', debounced],
    queryFn: ({ signal }) => api.get<{ groups: SearchGroup[] }>('search', { q: debounced }, { signal }),
    enabled: open && debounced.length >= 2,
    staleTime: 30_000,
  });
  const flat = useMemo(() => (data?.groups ?? []).flatMap((g) => g.items.map((it) => ({ ...it, group: g.label }))), [data]);
  useEffect(() => setActive(0), [debounced]);
  useEffect(() => {
    if (!open) setQ('');
  }, [open]);

  const go = (url: string) => {
    onClose();
    if (/^https?:/.test(url)) window.open(url, '_blank', 'noopener');
    else navigate(url.replace(/^\/?(admin\/)?/, '/'));
  };

  let idx = -1;
  return (
    <Dialog open={open} onClose={onClose} className="relative z-[70]" initialFocus={inputRef}>
      <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm animate-fade-in" aria-hidden />
      <div className="fixed inset-0 flex items-start justify-center p-3 pt-[10vh]">
        <DialogPanel className="w-full max-w-2xl animate-slide-up overflow-hidden rounded-2xl bg-white shadow-pop dark:bg-slate-900 dark:ring-1 dark:ring-slate-800">
          <div className="flex items-center gap-3 border-b border-slate-100 px-4 dark:border-slate-800">
            <Search className="h-5 w-5 text-slate-400" />
            <input
              ref={inputRef}
              value={q}
              onChange={(e) => setQ(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'ArrowDown') {
                  e.preventDefault();
                  setActive((a) => Math.min(flat.length - 1, a + 1));
                } else if (e.key === 'ArrowUp') {
                  e.preventDefault();
                  setActive((a) => Math.max(0, a - 1));
                } else if (e.key === 'Enter' && flat[active]) {
                  go(flat[active].url);
                }
              }}
              placeholder="Search students, faculty, applications, receipts, notices, pages…"
              className="h-14 flex-1 border-0 bg-transparent text-[15px] text-slate-900 placeholder:text-slate-400 focus:ring-0 dark:text-white"
              aria-label="Global search"
            />
            {isFetching && <Spinner className="h-4 w-4 text-slate-400" />}
            <button type="button" onClick={onClose} className="btn-icon" aria-label="Close search">
              <X className="h-4 w-4" />
            </button>
          </div>
          <div className="max-h-[60vh] overflow-y-auto p-2">
            {debounced.length < 2 ? (
              <p className="px-3 py-8 text-center text-sm text-slate-500">Type at least 2 characters to search across students, faculty, admissions, fees, results, notices, website pages, alumni and companies.</p>
            ) : error ? (
              <p className="px-3 py-8 text-center text-sm text-red-600">{(error as ApiError).message}</p>
            ) : data && flat.length === 0 && !isFetching ? (
              <p className="px-3 py-8 text-center text-sm text-slate-500">No results for “{debounced}”.</p>
            ) : (
              (data?.groups ?? []).map((g) =>
                g.items.length ? (
                  <div key={g.key} className="mb-2">
                    <p className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{g.label}</p>
                    {g.items.map((it) => {
                      idx++;
                      const i = idx;
                      return (
                        <button
                          key={`${g.key}-${it.id}`}
                          type="button"
                          onMouseEnter={() => setActive(i)}
                          onClick={() => go(it.url)}
                          className={clsx('flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left', active === i ? 'bg-brand-50 dark:bg-slate-800' : 'hover:bg-slate-50 dark:hover:bg-slate-800/60')}
                        >
                          <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium text-slate-900 dark:text-white">{it.title}</span>
                            {it.subtitle && <span className="block truncate text-xs text-slate-500">{it.subtitle}</span>}
                          </span>
                          {it.badge && <span className="badge badge-slate">{it.badge}</span>}
                          {active === i ? <CornerDownLeft className="h-4 w-4 text-slate-400" /> : <ArrowRight className="h-4 w-4 text-slate-300" />}
                        </button>
                      );
                    })}
                  </div>
                ) : null,
              )
            )}
          </div>
          <div className="flex items-center gap-4 border-t border-slate-100 px-4 py-2 text-[11px] text-slate-400 dark:border-slate-800">
            <span><kbd className="kbd">↑</kbd> <kbd className="kbd">↓</kbd> navigate</span>
            <span><kbd className="kbd">Enter</kbd> open</span>
            <span><kbd className="kbd">Esc</kbd> close</span>
          </div>
        </DialogPanel>
      </div>
    </Dialog>
  );
}
