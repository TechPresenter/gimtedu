import { useState } from 'react';
import { CircleCheck, CircleX, Download, History, LogIn, LogOut, Pencil, Plus, Send, Trash2, Upload, type LucideIcon } from 'lucide-react';
import { Card, CardHeader, EmptyState, Pagination, Skeleton, Timeline, Toggle, type Tone } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { formatDateTime, labelize, timeAgo } from '@/lib/format';
import type { ActivityRow, PageOf } from './types';

const VISUAL: Record<string, { icon: LucideIcon; tone: Tone }> = {
  create: { icon: Plus, tone: 'green' }, update: { icon: Pencil, tone: 'blue' }, delete: { icon: Trash2, tone: 'red' }, approve: { icon: CircleCheck, tone: 'purple' },
  verify: { icon: CircleCheck, tone: 'purple' }, publish: { icon: Send, tone: 'cyan' }, import: { icon: Upload, tone: 'slate' }, export: { icon: Download, tone: 'slate' },
  login: { icon: LogIn, tone: 'cyan' }, logout: { icon: LogOut, tone: 'slate' }, denied: { icon: CircleX, tone: 'red' }, reject: { icon: CircleX, tone: 'red' },
};

/** Paginated audit trail of the signed-in user. */
export function ActivityTab() {
  const [page, setPage] = useState(1);
  const [logins, setLogins] = useState(false);
  const { data, isLoading, error } = useApi<PageOf<ActivityRow>>(['profile-activity', page, logins], 'profile/activity', { page, per_page: 15, include_logins: logins ? 1 : undefined }, { placeholderData: (p) => p });
  return (
    <Card>
      <CardHeader
        title="My recent activity"
        subtitle="Everything you created, changed, approved or exported — newest first."
        icon={History}
        actions={<Toggle checked={logins} onChange={(v) => { setLogins(v); setPage(1); }} label={<span className="whitespace-nowrap text-xs">Show sign-ins</span>} />}
      />
      <div className="card-body">
        {error ? (
          <p className="text-sm text-red-600">{(error as ApiError).message}</p>
        ) : isLoading || !data ? (
          <div className="space-y-5">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="flex gap-3">
                <Skeleton className="h-8 w-8 rounded-full" />
                <div className="flex-1 space-y-2">
                  <Skeleton className="h-4 w-3/4" />
                  <Skeleton className="h-3 w-1/3" />
                </div>
              </div>
            ))}
          </div>
        ) : data.rows.length === 0 ? (
          <EmptyState icon={History} title="No activity yet" description="Your actions across SmartCampus will be listed here." className="!py-8" />
        ) : (
          <Timeline
            items={data.rows.map((a) => {
              const v = VISUAL[a.action] ?? { icon: History, tone: 'slate' as Tone };
              return {
                icon: v.icon,
                tone: a.status === 'success' ? v.tone : 'red',
                title: a.description || `${labelize(a.action)} ${a.module_label}`,
                description: `${a.module_label} · ${labelize(a.action)}${a.ip_address ? ` · ${a.ip_address}` : ''}`,
                time: (
                  <time dateTime={a.created_at} title={formatDateTime(a.created_at)}>
                    {formatDateTime(a.created_at)} · {timeAgo(a.created_at)}
                  </time>
                ),
              };
            })}
          />
        )}
      </div>
      {data && data.total > data.per_page && <Pagination page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} className="border-t border-slate-100 dark:border-slate-800" />}
    </Card>
  );
}
