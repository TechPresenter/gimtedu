import clsx from 'clsx';
import { Bus, CalendarRange, Clock, Flag, MapPin, MapPinned, Phone, Route, ShieldCheck, Sunrise, Sunset, UserRound } from 'lucide-react';
import { formatDate, labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead, PortalAvatar, Section } from '../../../components/Ui';
import { hhmm } from '../../../lib/time';
import { ListSkeleton, MiniStat, money, useStudent } from '../../shared/ui';
import type { StudentTransport } from '../../shared/types';

function Contact({ role, name, phone }: { role: string; name: string | null; phone: string | null }) {
  if (!name) return null;
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-slate-200/80 p-3 dark:border-slate-800">
      <PortalAvatar name={name} size="sm" />
      <div className="min-w-0 flex-1">
        <p className="truncate font-semibold text-slate-900 dark:text-white">{name}</p>
        <p className="text-xs text-slate-500">{role}</p>
      </div>
      {phone && (
        <a href={`tel:${phone.replace(/[^\d+]/g, '')}`} className="btn btn-secondary btn-sm" aria-label={`Call ${role} ${name}`}>
          <Phone className="h-4 w-4" aria-hidden /> <span className="hidden sm:inline">{phone}</span>
          <span className="sm:hidden">Call</span>
        </a>
      )}
    </div>
  );
}

export default function StudentTransportPage() {
  const { data, isLoading, error, refetch } = useStudent<StudentTransport>(['transport'], 'transport');
  const a = data?.allocation;
  const v = data?.vehicle;

  return (
    <div>
      <PageHead area="student" title="Transport" subtitle="Your bus route, stop, timings and contacts." crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'Transport' }]} />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="space-y-5">
          <div className="skeleton h-44 rounded-3xl" />
          <ListSkeleton rows={4} />
        </div>
      ) : !a ? (
        <div className="card">
          <EmptyBlock icon={Bus} title="Transport not allotted" text="You are not using college transport this session. To get a bus pass for your area, contact the transport office." />
        </div>
      ) : (
        <div className="space-y-5">
          <section className="hero-navy relative isolate overflow-hidden rounded-3xl p-5 text-white shadow-soft sm:p-7">
            <div className="pattern-grid absolute inset-0 -z-10 opacity-50 [mask-image:radial-gradient(120%_100%_at_100%_0%,black,transparent_70%)]" aria-hidden />
            <div className="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
              <div className="min-w-0">
                <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-flame-300">
                  Route {a.route_code ?? ''} {a.distance_km ? `· ${a.distance_km} km` : ''}
                </p>
                <h2 className="mt-1.5 font-display text-2xl font-extrabold text-white sm:text-[28px]">{a.route}</h2>
                {(a.start_point || a.end_point) && (
                  <p className="mt-1 text-sm text-white/75">
                    {a.start_point} → {a.end_point}
                  </p>
                )}
                <div className="mt-4 flex flex-wrap gap-2">
                  {v && (
                    <span className="glass inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold">
                      <Bus className="h-3.5 w-3.5 text-flame-300" aria-hidden /> {v.vehicle_no}
                    </span>
                  )}
                  {a.session && (
                    <span className="glass inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold">
                      <CalendarRange className="h-3.5 w-3.5 text-flame-300" aria-hidden /> Session {a.session}
                    </span>
                  )}
                </div>
              </div>
              <div className="glass grid grid-cols-2 gap-px overflow-hidden rounded-2xl sm:w-[380px]">
                <div className="p-4">
                  <p className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-white/60">
                    <Sunrise className="h-3.5 w-3.5" aria-hidden /> Pickup
                  </p>
                  <p className="font-display text-2xl font-extrabold">{a.pickup_time ? hhmm(a.pickup_time) : '—'}</p>
                  <p className="truncate text-xs text-white/70">{a.stop ?? 'Your stop'}</p>
                </div>
                <div className="border-l border-white/15 p-4">
                  <p className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-white/60">
                    <Sunset className="h-3.5 w-3.5" aria-hidden /> Drop
                  </p>
                  <p className="font-display text-2xl font-extrabold">{a.drop_time ? hhmm(a.drop_time) : '—'}</p>
                  <p className="truncate text-xs text-white/70">{a.evening_departure ? `Bus leaves ${hhmm(a.evening_departure)}` : 'Evening drop'}</p>
                </div>
              </div>
            </div>
          </section>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">
            <Section title="Route stops" subtitle={`${data.stops.length} stops · your stop is highlighted`} icon={Route} className="lg:col-span-3">
              <ol className="relative" aria-label="Stops in order">
                {data.stops.map((st, i) => (
                  <li key={st.id} className="relative flex gap-3 pb-4 last:pb-0">
                    {i < data.stops.length - 1 && <span className="absolute bottom-0 left-[15px] top-8 w-0.5 bg-slate-200 dark:bg-slate-800" aria-hidden />}
                    <span
                      className={clsx(
                        'relative z-[1] inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold ring-4 ring-white dark:ring-slate-900',
                        st.mine ? 'bg-flame-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300',
                      )}
                    >
                      {st.mine ? <MapPin className="h-4 w-4" aria-hidden /> : st.sequence}
                    </span>
                    <div className={clsx('min-w-0 flex-1 rounded-2xl px-3 py-2', st.mine && 'bg-flame-50 ring-1 ring-flame-200 dark:bg-flame-500/10 dark:ring-flame-500/30')}>
                      <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <p className={clsx('text-sm font-semibold', st.mine ? 'text-flame-800 dark:text-flame-200' : 'text-slate-900 dark:text-white')}>
                          {st.name}
                          {st.mine && <span className="badge badge-flame ml-2 align-middle">Your stop</span>}
                        </p>
                        <p className="text-xs tabular-nums text-slate-500">
                          {st.pickup_time ? hhmm(st.pickup_time) : '—'}
                          {st.drop_time ? ` · ${hhmm(st.drop_time)}` : ''}
                        </p>
                      </div>
                      {st.landmark && <p className="text-xs text-slate-500">{st.landmark}</p>}
                    </div>
                  </li>
                ))}
                <li className="relative flex gap-3">
                  <span className="relative z-[1] inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-900 text-white ring-4 ring-white dark:bg-brand-600 dark:ring-slate-900">
                    <Flag className="h-4 w-4" aria-hidden />
                  </span>
                  <div className="px-3 py-2">
                    <p className="text-sm font-semibold text-slate-900 dark:text-white">{a.end_point ?? 'Campus'}</p>
                  </div>
                </li>
              </ol>
            </Section>

            <div className="space-y-5 lg:col-span-2">
              <Section title="Bus & contacts" icon={ShieldCheck}>
                {v ? (
                  <div className="space-y-3">
                    <div className="grid grid-cols-2 gap-2.5">
                      <MiniStat label="Vehicle" value={v.vehicle_no} tone="navy" sub={labelize(v.type)} />
                      <MiniStat label="Capacity" value={v.capacity ?? '—'} sub={v.model ?? undefined} />
                    </div>
                    <Contact role="Driver" name={v.driver} phone={v.driver_phone} />
                    <Contact role="Conductor / attendant" name={v.conductor} phone={v.conductor_phone} />
                    {!v.driver && !v.conductor && <p className="text-sm text-slate-500">Driver details will be shared by the transport office.</p>}
                  </div>
                ) : (
                  <EmptyBlock compact icon={Bus} title="Bus not assigned yet" text="The transport office will assign a vehicle to this route." />
                )}
              </Section>
              <Section title="Pass details" icon={MapPinned}>
                <div className="grid grid-cols-2 gap-2.5">
                  <MiniStat label="Your stop" value={a.stop ?? '—'} sub={a.landmark ?? undefined} />
                  <MiniStat label="Annual fee" value={a.fee_amount !== null ? money(a.fee_amount) : '—'} />
                  <MiniStat label="From" value={formatDate(a.start_date)} />
                  <MiniStat label="Morning start" value={a.morning_departure ? hhmm(a.morning_departure) : '—'} sub="from first stop" />
                </div>
                <p className="mt-3 flex items-start gap-2 text-xs text-slate-500">
                  <Clock className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden /> Be at your stop 5 minutes early. Timings can change on exam days — watch the notices.
                </p>
                <p className="mt-2 flex items-start gap-2 text-xs text-slate-500">
                  <UserRound className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden /> To change your stop or route, contact the transport office.
                </p>
              </Section>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
