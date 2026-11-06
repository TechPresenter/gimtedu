import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { Activity, Clock3, Inbox, MessageSquareReply, Users } from 'lucide-react';
import { Alert, Avatar, Button, Drawer, EmptyState, Select, Skeleton } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { useActivityFeed, useAgents } from './hooks';
import { formatSecs } from './utils';

/** Team workload (open chats, replies today, average first response) + the agent activity feed. */
export function TeamDrawer({ open, onClose, isManager }: { open: boolean; onClose: () => void; isManager: boolean }) {
  const agents = useAgents(open);
  const [userId, setUserId] = useState<number | null>(null);
  const feed = useActivityFeed(userId, open);
  const rows = feed.data?.pages.flatMap((p) => p.rows) ?? [];

  return (
    <Drawer open={open} onClose={onClose} title="Inbox team" description="Workload and response times of everyone who works the WhatsApp inbox." width="max-w-2xl">
      <section>
        <h3 className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><Users className="h-3.5 w-3.5" aria-hidden />Agents</h3>
        {agents.error ? (
          <Alert variant="error" title="Unable to load the team" action={<Button size="xs" variant="secondary" onClick={() => agents.refetch()}>Retry</Button>}>{(agents.error as ApiError).message}</Alert>
        ) : agents.isLoading ? (
          <div className="space-y-2">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-16 w-full rounded-xl" />)}</div>
        ) : !agents.data?.length ? (
          <EmptyState icon={Users} title="No agents yet" description="Give a role the WhatsApp Inbox permission to add agents." className="!py-6" />
        ) : (
          <ul className="grid gap-2 sm:grid-cols-2">
            {agents.data.map((a) => (
              <li key={a.id} className={clsx('rounded-xl border p-3 transition', a.is_me ? 'border-accent-200 bg-accent-50/40 dark:border-accent-500/30 dark:bg-accent-500/5' : 'border-slate-200 dark:border-slate-700')}>
                <div className="flex items-center gap-2.5">
                  <Avatar name={a.name} src={a.avatar} size="sm" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{a.name}{a.is_me && <span className="ml-1 text-xs font-normal text-slate-400">(you)</span>}</p>
                    <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">{a.designation || a.email}</p>
                  </div>
                </div>
                <dl className="mt-3 grid grid-cols-3 gap-2 text-center">
                  <div className="rounded-lg bg-slate-50 py-1.5 dark:bg-slate-800/60">
                    <dt className="flex items-center justify-center gap-1 text-[10px] uppercase tracking-wide text-slate-500"><Inbox className="h-3 w-3" aria-hidden />Open</dt>
                    <dd className="text-base font-bold tabular-nums text-slate-900 dark:text-white">{formatNumber(a.open_assigned)}</dd>
                  </div>
                  <div className="rounded-lg bg-slate-50 py-1.5 dark:bg-slate-800/60">
                    <dt className="flex items-center justify-center gap-1 text-[10px] uppercase tracking-wide text-slate-500"><MessageSquareReply className="h-3 w-3" aria-hidden />Today</dt>
                    <dd className="text-base font-bold tabular-nums text-slate-900 dark:text-white">{formatNumber(a.replied_today)}</dd>
                  </div>
                  <div className="rounded-lg bg-slate-50 py-1.5 dark:bg-slate-800/60" title={a.response_samples ? `Average over ${a.response_samples} replies in the last 30 days` : 'No replies in the last 30 days'}>
                    <dt className="flex items-center justify-center gap-1 text-[10px] uppercase tracking-wide text-slate-500"><Clock3 className="h-3 w-3" aria-hidden />1st resp.</dt>
                    <dd className="text-base font-bold tabular-nums text-slate-900 dark:text-white">{formatSecs(a.avg_first_response_secs)}</dd>
                  </div>
                </dl>
                <p className="mt-2 text-[11px] text-slate-400">{a.pending ? `${a.pending} pending · ` : ''}{a.unread ? `${a.unread} unread · ` : ''}{a.last_active_at ? `active ${timeAgo(a.last_active_at)}` : 'no inbox activity yet'}</p>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="mt-6">
        <div className="mb-2 flex items-center justify-between gap-2">
          <h3 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500"><Activity className="h-3.5 w-3.5" aria-hidden />{isManager ? 'Team activity' : 'Your activity'}</h3>
          {isManager && agents.data && (
            <Select inputSize="sm" className="!w-48" aria-label="Filter by agent" value={userId ? String(userId) : ''} placeholder="Everyone" options={agents.data.map((a) => ({ value: String(a.id), label: a.name }))} onChange={(e) => setUserId(e.target.value ? Number(e.target.value) : null)} />
          )}
        </div>
        {feed.error ? (
          <Alert variant="error" title="Unable to load activity">{(feed.error as ApiError).message}</Alert>
        ) : feed.isLoading ? (
          <div className="space-y-2">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}</div>
        ) : rows.length === 0 ? (
          <EmptyState icon={Activity} title="No activity yet" description="Replies, assignments and notes will appear here." className="!py-6" />
        ) : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {rows.map((r) => (
              <li key={r.id} className="flex items-start gap-2.5 py-2">
                <Avatar name={r.user_name} src={r.user_avatar} size="xs" className="mt-0.5" />
                <div className="min-w-0 flex-1 text-sm">
                  <p className="text-slate-700 dark:text-slate-200">
                    <span className="font-semibold">{r.user_name}</span> <span className="text-slate-500 dark:text-slate-400">{r.title.toLowerCase()}</span>
                    {r.contact_name && r.conversation_id && (
                      <> · <Link to={`/whatsapp/inbox?c=${r.conversation_id}`} onClick={onClose} className="font-medium text-brand-700 hover:underline dark:text-brand-300">{r.contact_name}</Link></>
                    )}
                  </p>
                  {r.detail && <p className="truncate text-xs text-slate-500 dark:text-slate-400">{r.detail}</p>}
                </div>
                <time className="shrink-0 text-[11px] text-slate-400" title={formatDateTime(r.created_at)}>{timeAgo(r.created_at)}</time>
              </li>
            ))}
          </ul>
        )}
        {feed.hasNextPage && <div className="mt-2 text-center"><Button size="xs" variant="secondary" loading={feed.isFetchingNextPage} onClick={() => feed.fetchNextPage()}>Load more</Button></div>}
      </section>
    </Drawer>
  );
}
