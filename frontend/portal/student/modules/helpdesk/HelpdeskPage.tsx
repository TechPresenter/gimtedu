import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { ChevronRight, Inbox, Lightbulb, Lock, MessageSquarePlus, MessageSquareText, Scale, ShieldAlert, ThumbsUp } from 'lucide-react';
import { Stagger, Tabs } from '@/components/ui';
import { timeAgo } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead } from '../../../components/Ui';
import { ListSkeleton, StatusBadge, useStudent } from '../../shared/ui';
import type { StudentTickets } from '../../shared/types';
import { NewTicketModal, TicketDrawer } from './TicketForms';

const TYPE_ICON: Record<string, typeof Inbox> = { complaint: ShieldAlert, grievance: Scale, suggestion: Lightbulb, feedback: ThumbsUp };

export default function StudentHelpdeskPage() {
  const { data, isLoading, error, refetch } = useStudent<StudentTickets>(['tickets'], 'tickets');
  const [params, setParams] = useSearchParams();
  const openId = params.get('ticket') ? Number(params.get('ticket')) : null;
  const [creating, setCreating] = useState(params.get('new') === '1');
  const [filter, setFilter] = useState('all');
  const show = (id: number | null) => {
    const p = new URLSearchParams(params);
    p.delete('new');
    if (id) p.set('ticket', String(id));
    else p.delete('ticket');
    setParams(p, { replace: true });
  };
  const list = useMemo(() => (data?.tickets ?? []).filter((t) => filter === 'all' || (filter === 'active' ? ['open', 'in_progress'].includes(t.status) : ['resolved', 'closed'].includes(t.status))), [data, filter]);
  const active = (data?.counts.open ?? 0) + (data?.counts.in_progress ?? 0);
  const done = (data?.counts.resolved ?? 0) + (data?.counts.closed ?? 0);

  return (
    <div>
      <PageHead
        area="student"
        title="Grievances & Feedback"
        subtitle="Raise a complaint, grievance or suggestion and track it to resolution."
        crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'Grievances & Feedback' }]}
        actions={
          <button type="button" className="btn btn-primary btn-sm" onClick={() => setCreating(true)} disabled={!data}>
            <MessageSquarePlus className="h-4 w-4" aria-hidden /> New request
          </button>
        }
      />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <ListSkeleton rows={4} />
      ) : (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
              { label: 'Open', value: data.counts.open, cls: 'text-blue-600 dark:text-blue-300' },
              { label: 'In progress', value: data.counts.in_progress, cls: 'text-amber-600 dark:text-amber-300' },
              { label: 'Resolved', value: data.counts.resolved, cls: 'text-emerald-600 dark:text-emerald-300' },
              { label: 'Closed', value: data.counts.closed, cls: 'text-slate-600 dark:text-slate-300' },
            ].map((k) => (
              <div key={k.label} className="card px-4 py-3">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{k.label}</p>
                <p className={clsx('font-display text-2xl font-extrabold', k.cls)}>{k.value}</p>
              </div>
            ))}
          </div>

          <Tabs
            variant="pills"
            value={filter}
            onChange={setFilter}
            tabs={[
              { key: 'all', label: 'All', count: data.tickets.length },
              { key: 'active', label: 'Active', count: active },
              { key: 'done', label: 'Resolved', count: done },
            ]}
          />

          {list.length === 0 ? (
            <div className="card">
              <EmptyBlock
                icon={MessageSquareText}
                title={data.tickets.length ? 'Nothing in this view' : 'No requests yet'}
                text="Facing a problem with classes, fees, hostel, transport or anything else? Raise a request and the right office will respond."
                action={
                  <button type="button" className="btn btn-soft btn-sm" onClick={() => setCreating(true)}>
                    <MessageSquarePlus className="h-4 w-4" aria-hidden /> Raise a request
                  </button>
                }
              />
            </div>
          ) : (
            <section className="card overflow-hidden">
              <Stagger className="divide-y divide-slate-100 dark:divide-slate-800" step={40}>
                {list.map((t) => {
                  const Icon = TYPE_ICON[t.type] ?? Inbox;
                  return (
                    <button key={t.id} type="button" onClick={() => show(t.id)} className="flex w-full items-center gap-3 px-4 py-3.5 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                      <span className={clsx('inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', t.type === 'complaint' || t.type === 'grievance' ? 'bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300' : 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>
                        <Icon className="h-5 w-5" aria-hidden />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="flex flex-wrap items-center gap-2">
                          <span className="truncate text-sm font-semibold text-slate-900 dark:text-white">{t.subject}</span>
                          {t.anonymous && <Lock className="h-3.5 w-3.5 text-slate-400" aria-label="Name hidden" />}
                        </span>
                        <span className="mt-0.5 block truncate text-xs text-slate-500">
                          {t.ticket_no} · {t.type_label} · {t.category_label} · {timeAgo(t.created_at)}
                        </span>
                      </span>
                      <span className="hidden sm:inline-flex">
                        <StatusBadge status={t.priority} />
                      </span>
                      <StatusBadge status={t.status} />
                      <ChevronRight className="h-4 w-4 shrink-0 text-slate-300" aria-hidden />
                    </button>
                  );
                })}
              </Stagger>
            </section>
          )}
          <p className="px-1 text-xs text-slate-500 dark:text-slate-400">
            Corrections to your personal details can be requested from <strong>My Profile</strong> — they are tracked here too. Urgent safety issues: contact campus security directly.
          </p>
        </div>
      )}
      {data && <NewTicketModal open={creating} onClose={() => setCreating(false)} types={data.types} categories={data.categories} onCreated={(id) => show(id)} />}
      <TicketDrawer id={openId} onClose={() => show(null)} />
    </div>
  );
}
