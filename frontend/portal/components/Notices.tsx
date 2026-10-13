import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import clsx from 'clsx';
import { Bell, CalendarDays, ExternalLink, FileDown, Megaphone, Pin } from 'lucide-react';
import { Drawer, Skeleton } from '@/components/ui';
import { formatDate, labelize, timeAgo } from '@/lib/format';
import { useLocalStorage } from '@/lib/hooks';
import { areaPath, type Area } from '../lib/config';
import { usePortalQuery } from '../lib/hooks';
import type { NoticeItem } from '../lib/types';
import { EmptyBlock, ErrorState } from './Ui';

interface NoticeList {
  items: NoticeItem[];
  total: number;
  page: number;
  per_page: number;
}

const CATEGORY_TONE: Record<string, string> = {
  urgent: 'badge-red',
  exam: 'badge-flame',
  examination: 'badge-flame',
  result: 'badge-green',
  results: 'badge-green',
  holiday: 'badge-amber',
  event: 'badge-purple',
  events: 'badge-purple',
  placement: 'badge-blue',
  admission: 'badge-cyan',
  fees: 'badge-amber',
  fee: 'badge-amber',
  circular: 'badge-blue',
  scholarship: 'badge-green',
  academic: 'badge-navy',
  general: 'badge-slate',
};

export function CategoryBadge({ category }: { category: string | null }) {
  if (!category) return null;
  return <span className={clsx('badge', CATEGORY_TONE[category] ?? 'badge-slate')}>{labelize(category)}</span>;
}

/** Notification bell in the top bar: latest audience-scoped notices, unread dot until opened. */
export function NoticeBell({ area }: { area: Area }) {
  const navigate = useNavigate();
  const { data, isLoading, isError } = usePortalQuery<NoticeList>(area, ['notices', 'bell'], 'notices', { limit: 6 }, { staleTime: 120_000 });
  const [seenAt, setSeenAt] = useLocalStorage<string>(`gimt.portal.${area}.noticesSeen`, '');
  const items = data?.items ?? [];
  const unread = useMemo(() => items.filter((n) => !seenAt || n.date > seenAt).length, [items, seenAt]);
  const markSeen = () => {
    if (items[0]) setSeenAt(items.reduce((max, n) => (n.date > max ? n.date : max), seenAt || ''));
  };

  return (
    <Popover className="relative">
      {({ open, close }) => (
        <>
          <PopoverButton
            className={clsx('btn-icon relative', open && 'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-white')}
            aria-label={unread ? `Notices, ${unread} new` : 'Notices'}
            onClick={markSeen}
          >
            <Bell className="h-[19px] w-[19px]" aria-hidden />
            {unread > 0 && (
              <span className="absolute right-1.5 top-1.5 inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-flame-600 px-1 text-[10px] font-bold leading-none text-white ring-2 ring-white dark:ring-slate-900">
                {unread > 9 ? '9+' : unread}
              </span>
            )}
          </PopoverButton>
          <PopoverPanel anchor="bottom end" className="menu-panel z-50 w-[min(92vw,380px)] p-0 [--anchor-gap:8px]">
            <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
              <p className="font-display text-sm font-bold text-slate-900 dark:text-white">Notices</p>
              <span className="text-xs text-slate-500">{data?.total ?? 0} active</span>
            </div>
            <div className="max-h-[60vh] overflow-y-auto">
              {isLoading ? (
                <div className="space-y-3 p-4">
                  {[0, 1, 2].map((i) => (
                    <Skeleton key={i} className="h-12 w-full" />
                  ))}
                </div>
              ) : isError ? (
                <p className="px-4 py-6 text-center text-sm text-slate-500">Notices could not be loaded.</p>
              ) : items.length === 0 ? (
                <EmptyBlock compact icon={Megaphone} title="You're all caught up" text="New notices from the institute will appear here." />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {items.map((n) => (
                    <li key={n.id}>
                      <button
                        type="button"
                        onClick={() => {
                          close();
                          navigate(`${areaPath(area, 'notices')}?id=${n.id}`);
                        }}
                        className="flex w-full gap-3 px-4 py-3 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/60"
                      >
                        <span className={clsx('mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', n.is_pinned ? 'bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400')}>
                          {n.is_pinned ? <Pin className="h-4 w-4" aria-hidden /> : <Megaphone className="h-4 w-4" aria-hidden />}
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="line-clamp-2 text-[13px] font-semibold leading-snug text-slate-800 dark:text-slate-100">{n.title}</span>
                          <span className="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                            {labelize(n.category ?? 'general')} · {timeAgo(n.date)}
                          </span>
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
            <Link to={areaPath(area, 'notices')} onClick={() => close()} className="block border-t border-slate-100 px-4 py-2.5 text-center text-[13px] font-semibold text-flame-700 hover:bg-slate-50 dark:border-slate-800 dark:text-flame-300 dark:hover:bg-slate-800/60">
              View all notices
            </Link>
          </PopoverPanel>
        </>
      )}
    </Popover>
  );
}

/** Slide-over with the full notice (server-sanitised HTML). */
export function NoticeDrawer({ area, id, onClose }: { area: Area; id: number | null; onClose: () => void }) {
  const [lastId, setLastId] = useState<number | null>(id);
  useEffect(() => {
    if (id) setLastId(id);
  }, [id]);
  const shown = id ?? lastId;
  const { data, isLoading, error, refetch } = usePortalQuery<NoticeItem>(area, ['notice', shown], `notices/${shown}`, undefined, { enabled: !!shown, placeholderData: undefined });
  return (
    <Drawer open={!!id} onClose={onClose} title={data?.title ?? 'Notice'} description={data ? `Published ${formatDate(data.date)}` : undefined} width="max-w-2xl">
      {isLoading ? (
        <div className="space-y-3">
          <Skeleton className="h-5 w-1/3" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-5/6" />
          <Skeleton className="h-4 w-2/3" />
        </div>
      ) : error ? (
        <ErrorState error={error} onRetry={() => void refetch()} title="This notice is not available" />
      ) : data ? (
        <article>
          <div className="flex flex-wrap items-center gap-2">
            <CategoryBadge category={data.category} />
            {data.is_pinned && (
              <span className="badge badge-flame">
                <Pin className="h-3 w-3" aria-hidden /> Pinned
              </span>
            )}
            {data.expiry_date && (
              <span className="inline-flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
                <CalendarDays className="h-3.5 w-3.5" aria-hidden /> Valid till {formatDate(data.expiry_date)}
              </span>
            )}
          </div>
          <div
            className="prose-notice mt-4 space-y-3 text-[15px] leading-relaxed text-slate-700 dark:text-slate-300 [&_a]:font-semibold [&_a]:text-flame-700 dark:[&_a]:text-flame-300 [&_li]:ml-5 [&_ol]:list-decimal [&_strong]:text-slate-900 dark:[&_strong]:text-white [&_ul]:list-disc"
            dangerouslySetInnerHTML={{ __html: data.html ?? '' }}
          />
          {(data.attachment || data.url) && (
            <div className="mt-6 flex flex-wrap gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
              {data.attachment && (
                <a href={data.attachment} target="_blank" rel="noopener noreferrer" className="btn btn-primary btn-sm">
                  <FileDown className="h-4 w-4" aria-hidden /> Download attachment
                </a>
              )}
              {data.url && (
                <a href={data.url} target="_blank" rel="noopener noreferrer" className="btn btn-secondary btn-sm">
                  <ExternalLink className="h-4 w-4" aria-hidden /> Open on website
                </a>
              )}
            </div>
          )}
        </article>
      ) : null}
    </Drawer>
  );
}

export type { NoticeList };
