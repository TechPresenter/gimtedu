import { useEffect, useMemo, useRef, useState } from 'react';
import { Dialog, DialogPanel } from '@headlessui/react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  ArrowRight, Award, BookOpen, Building2, CalendarRange, CornerDownLeft, FileText, Globe, GraduationCap, IdCard, IndianRupee, Layers, Library, Megaphone,
  MessageCircle, Newspaper, Receipt, Search, UserCog, UserPlus, UserRound, Users, UsersRound, X, type LucideIcon,
} from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import { Avatar, Spinner } from '@/components/ui';

export interface SearchItem {
  id: number | string;
  title: string;
  subtitle?: string;
  url: string;
  badge?: string;
  image?: string | null;
  /** 'photo' (people: round avatar) or 'logo' (companies: whole logo in a rounded box, never cropped) */
  image_kind?: 'photo' | 'logo' | null;
  /** lucide icon name sent by /api/search (e.g. 'graduation-cap') */
  icon?: string | null;
}
export interface SearchGroup {
  key: string;
  label: string;
  icon?: string | null;
  items: SearchItem[];
}

/** Icons /api/search uses (api/routes/search.php); unknown names fall back to a document icon. */
const SEARCH_ICONS: Record<string, LucideIcon> = {
  award: Award, 'book-open': BookOpen, 'building-2': Building2, 'calendar-range': CalendarRange, 'file-text': FileText, globe: Globe,
  'graduation-cap': GraduationCap, 'id-card': IdCard, 'indian-rupee': IndianRupee, layers: Layers, library: Library, megaphone: Megaphone,
  'message-circle': MessageCircle, newspaper: Newspaper, receipt: Receipt, 'user-cog': UserCog, 'user-plus': UserPlus, 'user-round': UserRound,
  users: Users, 'users-round': UsersRound,
};
const searchIcon = (name?: string | null): LucideIcon => (name && SEARCH_ICONS[name]) || FileText;

/** Soft tints per result group, so people, money and content are told apart at a glance. */
const GROUP_TINTS: Record<string, string> = {
  students: 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
  faculty: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
  staff: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
  admissions: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
  enquiries: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
  fees: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
  results: 'bg-cyan-50 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300',
  notices: 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
  events: 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
};
const tint = (group: string) => GROUP_TINTS[group] ?? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';

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
                    <p className="flex items-center gap-1.5 px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                      {(() => {
                        const GIcon = searchIcon(g.icon);
                        return <GIcon className="h-3.5 w-3.5" aria-hidden />;
                      })()}
                      {g.label}
                      <span className="font-medium normal-case tracking-normal text-slate-300 dark:text-slate-600">· {g.items.length}</span>
                    </p>
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
                          {it.image ? (
                            <Avatar name={it.title} src={it.image} size="sm" variant={it.image_kind === 'logo' ? 'logo' : 'person'} />
                          ) : (
                            (() => {
                              const Icon = searchIcon(it.icon ?? g.icon);
                              return (
                                <span className={clsx('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', tint(g.key))} aria-hidden>
                                  <Icon className="h-4 w-4" />
                                </span>
                              );
                            })()
                          )}
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
