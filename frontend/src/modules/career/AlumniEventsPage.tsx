import { useState } from 'react';
import clsx from 'clsx';
import { CalendarCheck, CalendarDays, CalendarHeart, CalendarPlus, CalendarX, MapPin, Percent, RotateCcw, Ticket, Users, UsersRound, Video } from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, PageHeader, ProgressBar, Reveal, Skeleton, Stagger, StatTile, StatusBadge, useConfirm, useToast } from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { EventDrawer } from './alumni/EventDrawer';
import { LinkedCrudTable, EVENT_MODE_LABELS, EVENT_TYPE_COLORS, EVENT_TYPE_LABELS, TwoLine, statsKey, useRefreshModules } from './alumni/shared';

interface EventStats {
  kpis: { upcoming: number; completed: number; cancelled: number; this_year: number; virtual_events: number; upcoming_registrations: number; attended_12m: number; attendance_rate: number | null };
  upcoming: (Row & { id: number; title: string; type: string; event_date: string; mode: string; capacity: number | null; registrations: number })[];
  by_type: { type: string; label: string; total: number }[];
}

export default function AlumniEventsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useRefreshModules();
  const { data: meta } = useCrudMeta('alumni_events');
  const { data, isLoading, error } = useApi<EventStats>(statsKey('alumni_events'), 'alumni/events/stats');
  const [drawerId, setDrawerId] = useState<number | null>(null);
  const [formId, setFormId] = useState<number | null | 'new'>(null);
  const k = data?.kpis;

  const setStatus = async (row: Row, status: 'completed' | 'cancelled' | 'upcoming') => {
    if (status === 'cancelled') {
      const ok = await confirm({ title: 'Cancel this event?', message: <>Cancel <strong>{String(row.title)}</strong>? Registered alumni will need to be informed.</>, confirmText: 'Cancel event', danger: true });
      if (!ok) return;
    }
    try {
      const res = await api.post(`alumni/events/${row.id}/status`, { status });
      toast.success(res.message);
      await refresh('alumni_events');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const isPast = (row: Row) => new Date(String(row.event_date).replace(' ', 'T')).getTime() < Date.now();

  return (
    <>
      <PageHeader
        title="Alumni Events"
        description="Reunions, homecoming, chapter meets and webinars — with registrations and attendance."
        breadcrumbs={[{ label: 'Alumni', to: '/alumni' }, { label: 'Events' }]}
        actions={
          <>
            <Button variant="secondary" icon={UsersRound} to="/alumni">Directory</Button>
            {can('alumni', 'create') && <Button icon={CalendarPlus} onClick={() => setFormId('new')}>New event</Button>}
          </>
        }
      />
      {error ? <Alert variant="error" title="Unable to load event statistics" className="mb-6">{(error as Error).message}</Alert> : null}

      <Stagger className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5" step={50}>
        {[
          <StatTile key="u" label="Upcoming events" icon={CalendarDays} tone="blue" loading={isLoading} value={k?.upcoming ?? 0} sub={k ? `${k.this_year} events this year` : undefined} />,
          <StatTile key="r" label="Registrations" icon={Ticket} tone="green" loading={isLoading} value={k?.upcoming_registrations ?? 0} sub="For upcoming events" />,
          <StatTile key="a" label="Attendees" icon={Users} tone="purple" loading={isLoading} value={k?.attended_12m ?? 0} sub="In the last 12 months" />,
          <StatTile key="p" label="Attendance rate" icon={Percent} tone="orange" loading={isLoading} value={k?.attendance_rate != null ? `${k.attendance_rate}%` : '—'} sub={k ? `${k.completed} completed events` : undefined} />,
          <StatTile key="v" label="Online & hybrid" icon={Video} tone="cyan" loading={isLoading} value={k?.virtual_events ?? 0} sub={k ? `${k.cancelled} cancelled` : undefined} />,
        ]}
      </Stagger>

      <Reveal>
        <Card className="mb-6">
          <CardHeader title="Coming up" subtitle="Next alumni events and how registrations are filling up" icon={CalendarHeart} />
          <div className="card-body !pt-0">
            {isLoading || !data ? (
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-56" />)}</div>
            ) : data.upcoming.length === 0 ? (
              <EmptyState icon={CalendarPlus} title="No upcoming events" description="Plan the next reunion, chapter meet or webinar for your alumni."
                action={can('alumni', 'create') && <Button icon={CalendarPlus} onClick={() => setFormId('new')}>New event</Button>} className="py-8" />
            ) : (
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {data.upcoming.map((e) => {
                  const d = new Date(e.event_date.replace(' ', 'T'));
                  const fill = e.capacity ? Math.round((e.registrations / e.capacity) * 100) : null;
                  return (
                    <button key={e.id} type="button" onClick={() => setDrawerId(e.id)}
                      className="group overflow-hidden rounded-2xl border border-slate-200/80 bg-white text-left transition hover:-translate-y-0.5 hover:shadow-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-slate-800 dark:bg-slate-900">
                      <div className="relative h-28 bg-brand-900">
                        {e.image && <img src={appUrl(String(e.image))} alt="" loading="lazy" className="h-full w-full object-cover opacity-80 transition duration-500 group-hover:scale-105" />}
                        <div className="absolute inset-0 bg-gradient-to-t from-brand-950/80 via-brand-900/20 to-transparent" />
                        <div className="absolute left-3 top-3 rounded-xl bg-white px-2.5 py-1 text-center shadow-card dark:bg-slate-900">
                          <span className="block text-[10px] font-bold uppercase text-accent-700 dark:text-accent-400">{d.toLocaleDateString('en-GB', { month: 'short' })}</span>
                          <span className="block font-display text-lg font-bold leading-none text-brand-900 dark:text-white">{d.getDate()}</span>
                        </div>
                        <span className="absolute bottom-2 left-3"><StatusBadge status={e.type} label={EVENT_TYPE_LABELS[e.type]} colors={EVENT_TYPE_COLORS} dot={false} /></span>
                      </div>
                      <div className="space-y-2 p-4">
                        <p className="line-clamp-2 min-h-[2.5rem] text-sm font-semibold text-slate-900 dark:text-white">{e.title}</p>
                        <p className="flex items-center gap-1.5 truncate text-xs text-slate-500 dark:text-slate-400">
                          {e.mode === 'in_person' ? <MapPin className="h-3.5 w-3.5 shrink-0" /> : <Video className="h-3.5 w-3.5 shrink-0" />}
                          <span className="truncate">{formatDateTime(e.event_date)} · {e.venue ?? EVENT_MODE_LABELS[e.mode]}</span>
                        </p>
                        <div>
                          <div className="mb-1 flex justify-between text-xs">
                            <span className="text-slate-500">{formatNumber(e.registrations)} registered</span>
                            <span className={clsx('font-semibold', fill !== null && fill >= 90 ? 'text-red-600' : 'text-slate-700 dark:text-slate-200')}>{fill !== null ? `${fill}% full` : 'No limit'}</span>
                          </div>
                          <ProgressBar value={fill ?? 0} tone={fill !== null && fill >= 90 ? 'red' : 'green'} label={`${e.title} seats filled`} />
                        </div>
                      </div>
                    </button>
                  );
                })}
              </div>
            )}
          </div>
        </Card>
      </Reveal>

      <LinkedCrudTable
        module="alumni_events"
        title="All alumni events"
        onView={(r) => setDrawerId(Number(r.id))}
        addLabel="New event"
        onSaved={() => void refresh('alumni_events')}
        emptyTitle="No alumni events yet"
        emptyText="Create a reunion, homecoming, chapter meet or webinar to engage your alumni."
        renderers={{
          title: (r) => (
            <button type="button" onClick={() => setDrawerId(Number(r.id))} className="block min-w-[12rem] max-w-[19rem] text-left">
              <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{String(r.title)}</span>
              <span className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <StatusBadge status={String(r.type)} label={EVENT_TYPE_LABELS[String(r.type)]} colors={EVENT_TYPE_COLORS} dot={false} />
                <span className="truncate">{r.venue ?? ''}</span>
              </span>
            </button>
          ),
          attendees_count: (r) => (r.status === 'completed' ? <span className="tabular-nums">{formatNumber(r.attendees_count)}</span> : <span className="text-slate-400">—</span>),
          event_date: (r) => <TwoLine title={<span className="font-normal text-slate-700 dark:text-slate-200">{formatDateTime(r.event_date)}</span>} sub={EVENT_MODE_LABELS[String(r.mode)]} className="min-w-[9rem] max-w-[12rem] whitespace-nowrap" />,
        }}
        rowMenu={(r) => [
          { label: 'Registrations & attendance', icon: Users, onClick: () => setDrawerId(Number(r.id)) },
          can('alumni', 'edit') && r.status === 'upcoming' && isPast(r) && { label: 'Mark completed', icon: CalendarCheck, onClick: () => setStatus(r, 'completed') },
          can('alumni', 'edit') && r.status === 'upcoming' && { label: 'Cancel event', icon: CalendarX, danger: true, onClick: () => setStatus(r, 'cancelled') },
          can('alumni', 'edit') && r.status !== 'upcoming' && !isPast(r) && { label: 'Re-open event', icon: RotateCcw, onClick: () => setStatus(r, 'upcoming') },
        ]}
      />

      <EventDrawer id={drawerId} onClose={() => setDrawerId(null)} onEdit={(id) => setFormId(id)} />
      <CrudFormModal
        open={formId !== null}
        onClose={() => setFormId(null)}
        meta={meta}
        module="alumni_events"
        id={formId === 'new' ? null : formId}
        onSaved={() => void refresh('alumni_events')}
      />
    </>
  );
}
