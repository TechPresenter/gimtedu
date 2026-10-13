import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BarChart3, CalendarCheck, CalendarDays, CalendarPlus, CalendarRange, ExternalLink, LineChart as LineIcon, MapPin, Printer, Radio, Ticket, TicketCheck, UserCheck, Users } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, PageHeader, ProgressBar, Reveal, Skeleton, StatCard, Stagger, StatusBadge, Tabs } from '@/components/ui';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { LineChart } from '@/components/charts';
import { ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import { formatDate, formatNumber, formatTime } from '@/lib/format';
import type { Row } from '@/lib/types';
import { EVENT_CATEGORIES, EVENT_STATUS_COLORS, Meter, openApiPrint, useCommRefresh } from './shared';
import { EventDrawer } from './events/EventDrawer';
import type { EventSummary } from './events/types';

function DateTile({ value }: { value: string }) {
  const d = new Date(String(value).replace(' ', 'T'));
  return (
    <div className="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-xl bg-white text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
      <span className="text-[10px] font-semibold uppercase tracking-wider text-accent-700 dark:text-accent-400">{d.toLocaleDateString('en-GB', { month: 'short' })}</span>
      <span className="font-display text-xl font-bold leading-none text-slate-900 dark:text-white">{d.getDate()}</span>
    </div>
  );
}

export default function EventsPage() {
  const { can } = useAuth();
  const refresh = useCommRefresh();
  const [params, setParams] = useSearchParams();
  const tab = ['upcoming', 'ongoing', 'past'].includes(String(params.get('period'))) ? String(params.get('period')) : 'all';
  const { data: s, isLoading, error } = useApi<EventSummary>(['comm', 'events-summary'], 'events/summary');
  const { data: meta } = useCrudMeta('events');
  const [viewId, setViewId] = useState<number | null>(Number(params.get('view')) || null);
  const [editId, setEditId] = useState<number | null>(null);

  useEffect(() => {
    const v = Number(params.get('view')) || null;
    if (v) setViewId(v);
  }, [params]);

  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    next.delete('page');
    setParams(next, { replace: true });
  };

  const tabs = [
    { key: 'all', label: 'All events', count: s?.total },
    { key: 'upcoming', label: 'Upcoming', count: s?.periods.upcoming },
    { key: 'ongoing', label: 'Happening now', count: s?.periods.ongoing },
    { key: 'past', label: 'Past', count: s?.periods.past },
  ];

  return (
    <>
      <PageHeader
        title="Events"
        description="Plan campus events, seminars, workshops and webinars — take registrations, send reminders and check participants in."
        breadcrumbs={[{ label: 'Communication' }, { label: 'Events' }]}
        actions={
          can('events', 'create') && (
            <Button icon={CalendarPlus} onClick={() => setEditId(0)}>
              New event
            </Button>
          )
        }
      />

      {error && <Alert variant="error" className="mb-6" title="Unable to load event statistics">{(error as ApiError).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="up" label="Upcoming events" icon={CalendarRange} tone="blue" loading={isLoading} value={s?.upcoming ?? 0} hint={s ? `${formatNumber(s.this_month)} this month` : undefined} />,
          <StatCard key="now" label="Happening now" icon={Radio} tone="green" loading={isLoading} value={s?.ongoing ?? 0} hint="In progress today" />,
          <StatCard key="open" label="Open for registration" icon={Ticket} tone="purple" loading={isLoading} value={s?.open_registration ?? 0} hint="Accepting sign-ups" />,
          <StatCard key="reg" label="Registrations" icon={Users} tone="orange" loading={isLoading} value={s?.registrations ?? 0} hint={s ? `${formatNumber(s.registrations_30d)} in the last 30 days` : undefined} />,
          <StatCard key="att" label="Checked in" icon={UserCheck} tone="cyan" loading={isLoading} value={s?.attended ?? 0} hint="Across all events" />,
          <StatCard key="rate" label="Attendance rate" icon={TicketCheck} tone="navy" loading={isLoading} value={s?.attendance_rate != null ? `${s.attendance_rate}%` : '—'} hint="Past events, registered vs present" />,
        ]}
      </Stagger>

      <section className="mb-6">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="font-display text-base font-semibold text-slate-900 dark:text-white">Coming up</h2>
          {s && s.next.length > 0 && (
            <button type="button" onClick={() => setParam('period', 'upcoming')} className="text-sm font-medium text-brand-700 hover:underline dark:text-brand-300">
              View all upcoming →
            </button>
          )}
        </div>
        {isLoading || !s ? (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-64 w-full rounded-2xl" />)}
          </div>
        ) : s.next.length === 0 ? (
          <Card>
            <EmptyState
              icon={CalendarDays}
              title="No upcoming events"
              description="Plan the next seminar, workshop or cultural event and open registrations on the website."
              action={can('events', 'create') ? <Button icon={CalendarPlus} onClick={() => setEditId(0)}>New event</Button> : undefined}
            />
          </Card>
        ) : (
          <Stagger className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" step={70}>
            {s.next.map((ev: Row) => {
              const cat = EVENT_CATEGORIES[String(ev.category)];
              return (
                <button
                  key={ev.id}
                  type="button"
                  onClick={() => setViewId(Number(ev.id))}
                  className="card group flex h-full w-full flex-col overflow-hidden text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-soft focus-visible:ring-2 focus-visible:ring-brand-500"
                >
                  <div className="relative h-32 w-full overflow-hidden bg-slate-100 dark:bg-slate-800">
                    {ev.image_url ? (
                      <img src={appUrl(String(ev.image_url))} alt="" loading="lazy" className="h-full w-full object-cover transition duration-500 group-hover:scale-105 motion-reduce:transition-none" />
                    ) : (
                      <div className="flex h-full items-center justify-center text-slate-300"><CalendarDays className="h-10 w-10" /></div>
                    )}
                    <span className="absolute left-3 top-3"><Badge color={cat?.color ?? 'slate'} className="!bg-white/95 shadow-sm dark:!bg-slate-900/90">{cat?.label ?? String(ev.category)}</Badge></span>
                  </div>
                  <div className="flex flex-1 gap-3 p-4">
                    <DateTile value={String(ev.start_datetime)} />
                    <div className="min-w-0 flex-1">
                      <p className="line-clamp-2 font-semibold leading-snug text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300">{String(ev.title)}</p>
                      <p className="mt-1 flex items-center gap-1 truncate text-xs text-slate-500 dark:text-slate-400"><MapPin className="h-3 w-3 shrink-0" />{String(ev.venue ?? 'Campus')}</p>
                    </div>
                  </div>
                  <div className="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
                    {ev.registration_enabled ? (
                      <Meter value={Number(ev.registrations)} max={ev.max_participants ? Number(ev.max_participants) : null} label={<span className="text-[11px] text-slate-500">{ev.max_participants ? 'seats booked' : 'registered'}</span>} />
                    ) : (
                      <p className="text-xs text-slate-500">Open event · no registration needed</p>
                    )}
                  </div>
                </button>
              );
            })}
          </Stagger>
        )}
      </section>

      <Tabs tabs={tabs} value={tab} onChange={(k) => setParam('period', k === 'all' ? '' : k)} variant="pills" className="mb-4" />

      <CrudTable
        key={tab}
        module="events"
        urlState
        scope={tab === 'all' ? undefined : { period: tab }}
        title={tab === 'all' ? 'All events' : tab === 'upcoming' ? 'Upcoming events' : tab === 'ongoing' ? 'Happening now' : 'Past events'}
        addLabel="New event"
        emptyTitle="No events here"
        emptyText="Create an event to publish it on the website and start taking registrations."
        onCreate={() => setEditId(0)}
        onEdit={(r) => setEditId(Number(r.id))}
        onView={(r) => setViewId(Number(r.id))}
        renderers={{
          title: (r) => (
            <button type="button" onClick={() => setViewId(Number(r.id))} className="flex max-w-[16rem] items-center gap-3 text-left">
              {r.image ? (
                <img src={appUrl(String(r.image))} alt="" loading="lazy" className="h-10 w-14 shrink-0 rounded-md object-cover ring-1 ring-slate-200 dark:ring-slate-700" />
              ) : (
                <span className="inline-flex h-10 w-14 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-400 dark:bg-slate-800"><CalendarDays className="h-4 w-4" /></span>
              )}
              <span className="min-w-0 leading-tight">
                <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white" title={String(r.title)}>
                  {String(r.title)}
                  {Number(r.is_featured) ? <span className="ml-1.5 align-middle text-[10px] font-semibold uppercase text-amber-600">★</span> : null}
                </span>
                <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{String(r.venue ?? '—')}</span>
              </span>
            </button>
          ),
          category: (r) => {
            const c = EVENT_CATEGORIES[String(r.category)];
            return <Badge color={c?.color ?? 'slate'}>{c?.label ?? String(r.category)}</Badge>;
          },
          start_datetime: (r) => (
            <span className="whitespace-nowrap text-sm leading-tight">
              {formatDate(r.start_datetime)}
              <span className="block text-xs text-slate-500 dark:text-slate-400">{formatTime(String(r.start_datetime).slice(11, 16))}</span>
              {r.period === 'ongoing' && <span className="block text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">Happening now</span>}
            </span>
          ),
          registrations_count: (r) =>
            Number(r.registration_enabled) || Number(r.registrations_count) ? (
              <Meter value={Number(r.registrations_count)} max={r.max_participants ? Number(r.max_participants) : null} />
            ) : (
              <span className="text-xs text-slate-400">No registration</span>
            ),
          status: (r) => <StatusBadge status={String(r.status)} colors={EVENT_STATUS_COLORS} />,
        }}
        rowMenu={(r) => [
          { label: 'Participants & check-in', icon: Users, onClick: () => setViewId(Number(r.id)) },
          { label: 'Print attendance sheet', icon: Printer, onClick: () => openApiPrint(`events/${r.id}/print`) },
          !!Number(r.show_on_website) && r.status === 'published' && { label: 'View on website', icon: ExternalLink, href: appUrl(`events/${r.slug}`), target: '_blank' },
        ]}
        onSaved={() => void refresh('crud')}
      />

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="Registrations trend" subtitle="New registrations per month (last 6 months)" icon={LineIcon} />
            <div className="card-body">
              {isLoading || !s ? <Skeleton className="h-60 w-full" /> : <LineChart labels={s.trend.labels} height={250} series={[{ label: 'Registrations', data: s.trend.values, color: '#1D4ED8', fill: true }]} />}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Events by category" subtitle="Active registrations per event category" icon={BarChart3} />
            <div className="card-body">
              {isLoading || !s ? (
                <Skeleton className="h-60 w-full" />
              ) : s.by_category.length === 0 ? (
                <EmptyState icon={CalendarCheck} title="No events yet" className="py-8" />
              ) : (
                <ul className="space-y-2.5">
                  {[...s.by_category]
                    .sort((x, y) => y.registrations - x.registrations)
                    .map((c) => {
                      const max = Math.max(1, ...s.by_category.map((x) => x.registrations));
                      const cat = EVENT_CATEGORIES[c.key];
                      return (
                        <li key={c.key}>
                          <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
                            <span className="truncate text-slate-700 dark:text-slate-200">
                              {cat?.label ?? c.label} <span className="text-xs text-slate-400">· {formatNumber(c.events)} event{c.events === 1 ? '' : 's'}</span>
                            </span>
                            <span className="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(c.registrations)}</span>
                          </div>
                          <ProgressBar value={(c.registrations / max) * 100} tone="green" className="!h-1.5" label={`${cat?.label ?? c.label} registrations`} />
                        </li>
                      );
                    })}
                </ul>
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <EventDrawer
        id={viewId}
        onClose={() => {
          setViewId(null);
          if (params.get('view')) setParam('view', '');
        }}
        onEdit={(id) => setEditId(id)}
      />
      <CrudFormModal
        open={editId !== null}
        onClose={() => setEditId(null)}
        meta={meta}
        module="events"
        id={editId || null}
        onSaved={(_row, id) => {
          void refresh('crud');
          if (!editId) setViewId(id);
        }}
      />
    </>
  );
}
