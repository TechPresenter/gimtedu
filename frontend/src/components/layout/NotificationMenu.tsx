import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Bell, BellOff, CheckCheck } from 'lucide-react';
import { api } from '@/lib/api';
import { timeAgo } from '@/lib/format';
import { Spinner } from '@/components/ui';

export interface NotificationRow {
  id: number;
  type: string;
  title: string;
  message: string | null;
  url: string | null;
  icon: string | null;
  is_read: boolean;
  created_at: string;
}

const typeTone: Record<string, string> = {
  admission: 'bg-blue-100 text-blue-700', application: 'bg-blue-100 text-blue-700', fee: 'bg-emerald-100 text-emerald-700', payment: 'bg-emerald-100 text-emerald-700',
  attendance: 'bg-amber-100 text-amber-700', exam: 'bg-violet-100 text-violet-700', enquiry: 'bg-cyan-100 text-cyan-700', contact: 'bg-cyan-100 text-cyan-700',
  certificate: 'bg-violet-100 text-violet-700', security: 'bg-red-100 text-red-600', system: 'bg-slate-100 text-slate-600',
};

/** Convert stored URL ("admin/students/5" or "/admin/x") into an SPA route. */
export function notificationRoute(url: string | null): string | null {
  if (!url) return null;
  if (/^https?:/i.test(url)) return url;
  return '/' + url.replace(/^\/?(admin\/)?/, '');
}

export function NotificationMenu({ count }: { count: number }) {
  const qc = useQueryClient();
  const navigate = useNavigate();
  const { data, isLoading, refetch } = useQuery({
    queryKey: ['notifications', 'menu'],
    queryFn: () => api.get<{ rows: NotificationRow[]; unread: number }>('notifications', { per_page: 8 }, { background: true }),
    enabled: false,
  });
  const markAll = async () => {
    await api.post('notifications/read-all');
    await Promise.all([refetch(), qc.invalidateQueries({ queryKey: ['notification-counts'] })]);
  };
  const open = async (n: NotificationRow, close: () => void) => {
    if (!n.is_read) {
      void api.post(`notifications/${n.id}/read`).then(() => qc.invalidateQueries({ queryKey: ['notification-counts'] }));
    }
    close();
    const route = notificationRoute(n.url);
    if (route) {
      if (route.startsWith('http')) window.open(route, '_blank', 'noopener');
      else navigate(route);
    }
  };
  return (
    <Popover className="relative">
      <PopoverButton className="btn-icon relative" aria-label={`Notifications${count ? ` (${count} unread)` : ''}`} title="Notifications" onClick={() => void refetch()}>
        <Bell className="h-5 w-5" />
        {count > 0 && <span className="absolute -right-0.5 -top-0.5 inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-white dark:ring-slate-900">{count > 99 ? '99+' : count}</span>}
      </PopoverButton>
      <PopoverPanel anchor="bottom end" className="menu-panel z-50 w-[22rem] max-w-[calc(100vw-1.5rem)] !p-0 [--anchor-gap:8px]">
        {({ close }) => (
          <>
            <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
              <p className="text-sm font-semibold text-slate-900 dark:text-white">Notifications</p>
              <button type="button" onClick={markAll} className="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">
                <CheckCheck className="h-3.5 w-3.5" /> Mark all read
              </button>
            </div>
            <div className="max-h-96 overflow-y-auto">
              {isLoading ? (
                <div className="flex justify-center py-8"><Spinner className="h-5 w-5 text-slate-400" /></div>
              ) : !data?.rows.length ? (
                <div className="flex flex-col items-center gap-2 px-4 py-10 text-center text-sm text-slate-500">
                  <BellOff className="h-6 w-6 text-slate-300" />
                  You're all caught up.
                </div>
              ) : (
                data.rows.map((n) => (
                  <button key={n.id} type="button" onClick={() => open(n, close)} className={clsx('flex w-full gap-3 border-b border-slate-50 px-4 py-3 text-left transition hover:bg-slate-50 dark:border-slate-800/60 dark:hover:bg-slate-800/60', !n.is_read && 'bg-brand-50/40 dark:bg-brand-500/5')}>
                    <span className={clsx('mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full', typeTone[n.type] ?? typeTone.system)}>
                      <Bell className="h-4 w-4" />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="flex items-start justify-between gap-2">
                        <span className="text-sm font-medium text-slate-900 dark:text-white">{n.title}</span>
                        {!n.is_read && <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-600" aria-label="Unread" />}
                      </span>
                      {n.message && <span className="line-clamp-2 block text-xs text-slate-500 dark:text-slate-400">{n.message}</span>}
                      <span className="mt-0.5 block text-[11px] text-slate-400">{timeAgo(n.created_at)}</span>
                    </span>
                  </button>
                ))
              )}
            </div>
            <Link to="/notifications" onClick={() => close()} className="block border-t border-slate-100 px-4 py-2.5 text-center text-sm font-medium text-brand-700 hover:bg-slate-50 dark:border-slate-800 dark:text-brand-300 dark:hover:bg-slate-800">
              View all notifications
            </Link>
          </>
        )}
      </PopoverPanel>
    </Popover>
  );
}
