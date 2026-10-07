import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  ArrowRight, BookOpenCheck, BriefcaseBusiness, CalendarCheck2, CheckCircle2, CircleDashed, Clock3, Fingerprint, Percent, TrendingUp, UserCheck, UserMinus, UserX, Users,
} from 'lucide-react';
import { Alert, Avatar, Button, Card, CardHeader, EmptyState, Skeleton, StatCard, Stagger, Tabs, Reveal, Toggle } from '@/components/ui';
import { DoughnutChart } from '@/components/charts';
import { PercentTrend } from './PercentTrend';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatNumber, formatTime, timeAgo, formatDate } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import type { DashboardPayload, ScheduleItem } from '../types';
import { DayBanner, PercentPill, PercentBar, STATUS_META } from './shared';
import type { MarkSelection } from './MarkAttendance';

interface Props {
  date: string;
  onMark: (s: Partial<MarkSelection>) => void;
  onDevice: () => void;
}

export function AttendanceOverview({ date, onMark, onDevice }: Props) {
  const { can } = useAuth();
  const [mine, setMine] = useState<boolean | null>(null);
  const q = useApi<DashboardPayload>(['attendance', 'dashboard', date, mine], 'attendance/dashboard', { date, mine: mine ? 1 : undefined });
  const d = q.data;
  const isFaculty = !!d?.is_faculty;

  if (q.error) {
    return (
      <Alert variant="error" title="Unable to load today's attendance" action={<Button size="sm" variant="secondary" onClick={() => q.refetch()}>Retry</Button>}>
        {(q.error as ApiError).message}
      </Alert>
    );
  }
  const k = d?.kpis;
  const loading = q.isLoading;
  const closed = !!d?.day.blocked;
  const scheduledLabel = closed ? 'Closed' : k ? (k.scheduled_source === 'timetable' ? `${formatNumber(k.scheduled_marked)} / ${formatNumber(k.scheduled)}` : `${k.sections_marked} / ${k.sections_total}`) : '';

  return (
    <div className="space-y-5">
      {d && <DayBanner day={d.day} />}

      <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 xl:grid-cols-6" step={60}>
        {[
          <StatCard key="pct" label="Present today" icon={Percent} tone="green" loading={loading} value={k?.percent !== null && k?.percent !== undefined ? `${k.percent.toFixed(1)}%` : '—'} hint={k?.session_percent ? `Session average ${k.session_percent.toFixed(1)}%` : 'No classes marked yet'} />,
          <StatCard key="present" label="Students present" icon={UserCheck} tone="blue" loading={loading} value={k?.present ?? 0} hint={k ? `${formatNumber(k.students_marked)} of ${formatNumber(k.students_total)} marked` : ''} />,
          <StatCard key="absent" label="Absent (full day)" icon={UserX} tone="red" loading={loading} value={k?.absent ?? 0} hint={k ? `${formatNumber(k.period_absences)} period absences` : ''} />,
          <StatCard key="late" label="Late arrivals" icon={Clock3} tone="amber" loading={loading} value={k?.late ?? 0} hint="Late in at least one class" />,
          <StatCard key="leave" label="On leave" icon={UserMinus} tone="purple" loading={loading} value={k?.leave ?? 0} hint="Approved leave for the day" />,
          <StatCard key="classes" label={k?.scheduled_source === 'timetable' ? 'Classes marked' : 'Sections marked'} icon={BookOpenCheck} tone="navy" loading={loading} value={scheduledLabel} hint={closed ? d?.day.holiday?.title ?? 'Weekly off' : k ? (k.scheduled_source === 'timetable' ? `${k.sessions_marked} sessions marked in total` : `${k.sessions_marked} class sessions`) : ''} />,
        ]}
      </Stagger>

      <div className="grid gap-5 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Attendance trend" subtitle="Daily student attendance over the last 14 class days" icon={TrendingUp} actions={d && <PercentPill value={d.kpis.session_percent} min={d.min_percent} />} />
            <div className="p-4 sm:p-5">
              {loading ? (
                <Skeleton className="h-[240px] w-full rounded-xl" />
              ) : d && d.trend.length ? (
                <PercentTrend labels={d.trend.map((t) => t.label)} values={d.trend.map((t) => t.percent)} min={d.min_percent} height={240} />
              ) : (
                <EmptyState icon={TrendingUp} title="No attendance recorded yet" description="The trend appears once classes are marked." />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Today's status split" subtitle="All class periods marked today" icon={CalendarCheck2} />
            <div className="p-4 sm:p-5">
              {loading ? (
                <Skeleton className="mx-auto h-[180px] w-[180px] rounded-full" />
              ) : d && d.kpis.marks > 0 ? (
                <DoughnutChart
                  labels={['Present', 'Late', 'Absent', 'Leave']}
                  data={[d.status_split.present, d.status_split.late, d.status_split.absent, d.status_split.leave]}
                  colors={['#10B981', '#F59E0B', '#EF4444', '#0EA5E9']}
                  centerValue={`${d.kpis.percent?.toFixed(0) ?? 0}%`}
                  centerLabel="present"
                  height={170}
                />
              ) : (
                closed ? (
                  <EmptyState icon={CalendarCheck2} title="Institute closed" description={d?.day.holiday?.title ?? `${d?.day.day_name} is a weekly off.`} />
                ) : (
                  <EmptyState icon={CalendarCheck2} title="Nothing marked yet" description="Start with the first period of the day." action={can('attendance', 'create') ? <Button size="sm" onClick={() => onMark({ date })}>Mark attendance</Button> : undefined} />
                )
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <div className="grid gap-5 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Schedule data={d} loading={loading} onMark={onMark} mine={mine ?? false} setMine={isFaculty ? (v) => setMine(v) : undefined} />
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader
              title="Low attendance"
              subtitle={d ? `${d.low_attendance.count} students below ${d.min_percent}% this session` : 'Session to date'}
              icon={UserX}
              actions={<Link to="/attendance/reports?tab=defaulters" className="link inline-flex items-center gap-1 text-xs">View all <ArrowRight className="h-3 w-3" /></Link>}
            />
            <div className="p-2 sm:p-3">
              {loading ? (
                <div className="space-y-3 p-3">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}</div>
              ) : d && d.low_attendance.students.length ? (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {d.low_attendance.students.map((s) => (
                    <li key={s.id} className="flex items-center gap-3 px-3 py-2.5">
                      <Avatar name={s.name} src={s.photo} size="sm" />
                      <div className="min-w-0 flex-1 leading-tight">
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{s.name}</p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">{s.class_label}</p>
                      </div>
                      <PercentPill value={s.percent} min={d.min_percent} />
                    </li>
                  ))}
                </ul>
              ) : (
                <EmptyState icon={CheckCircle2} title="Everyone is on track" description={`No student is below ${d?.min_percent ?? 75}% attendance.`} />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <div className="grid gap-5 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Recently marked" subtitle="Latest class sessions" icon={BookOpenCheck} actions={<Link to="/attendance?tab=sessions" className="link inline-flex items-center gap-1 text-xs">All sessions <ArrowRight className="h-3 w-3" /></Link>} />
            {loading ? (
              <div className="space-y-3 p-5">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}</div>
            ) : d && d.recent.length ? (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {d.recent.map((r) => (
                  <li key={r.id}>
                    <button type="button" onClick={() => onMark({ date: r.attendance_date, section: r.section_id, subject: r.subject_id, slot: r.time_slot_id })} className="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-slate-50 sm:px-5 dark:hover:bg-slate-800/40">
                      <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-xs font-bold text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">{r.subject_code.slice(-3)}</span>
                      <div className="min-w-0 flex-1 leading-tight">
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{r.subject_name}</p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                          {r.section_label} · {formatDate(r.attendance_date)}{r.slot_name ? ` · ${r.slot_name}` : ''} · {r.taken_by_name ?? 'Staff'}
                        </p>
                      </div>
                      <div className="hidden w-40 sm:block"><PercentBar value={r.counts?.percent} min={d.min_percent} /></div>
                      <span className="whitespace-nowrap text-xs text-slate-400">{timeAgo(r.updated_at ?? r.created_at)}</span>
                    </button>
                  </li>
                ))}
              </ul>
            ) : (
              <EmptyState icon={BookOpenCheck} title="No sessions yet" description="Marked classes appear here." />
            )}
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <div className="flex h-full flex-col gap-5">
            <Card>
              <CardHeader title="Faculty & staff today" subtitle={d ? `${formatNumber(d.employees.total)} employees` : ''} icon={BriefcaseBusiness} actions={<Link to="/attendance/employees" className="link inline-flex items-center gap-1 text-xs">Open <ArrowRight className="h-3 w-3" /></Link>} />
              <div className="space-y-4 p-4 sm:p-5">
                {loading || !d ? (
                  <Skeleton className="h-16 w-full" />
                ) : (
                  (['faculty', 'staff'] as const).map((t) => {
                    const e = d.employees[t];
                    const total = Object.values(e).reduce((a, b) => a + b, 0);
                    return (
                      <div key={t}>
                        <div className="mb-1.5 flex items-center justify-between text-sm">
                          <span className="font-medium capitalize text-slate-700 dark:text-slate-200">{t}</span>
                          <span className="text-xs text-slate-500">{total ? `${e.present + e.late + e.half_day} in · ${e.absent + e.leave} out` : 'Not marked yet'}</span>
                        </div>
                        <div className="flex h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden>
                          {(['present', 'late', 'half_day', 'leave', 'absent'] as const).map((s) => (total ? <span key={s} className={STATUS_META[s].dot} style={{ width: `${(e[s] / total) * 100}%` }} /> : null))}
                        </div>
                      </div>
                    );
                  })
                )}
              </div>
            </Card>
            <Card className="flex-1">
              <div className="flex items-start gap-3 p-4 sm:p-5">
                <span className="kpi-icon bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300"><Fingerprint className="h-5 w-5" /></span>
                <div className="min-w-0 flex-1">
                  <p className="font-semibold text-slate-900 dark:text-white">Biometric & QR devices</p>
                  <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Gate and classroom devices post punches to a secure, token-protected endpoint.</p>
                  <Button size="sm" variant="soft" className="mt-3" onClick={onDevice}>Device integration</Button>
                </div>
              </div>
            </Card>
          </div>
        </Reveal>
      </div>
    </div>
  );
}

function Schedule({ data: d, loading, onMark, mine, setMine }: { data?: DashboardPayload; loading: boolean; onMark: (s: Partial<MarkSelection>) => void; mine: boolean; setMine?: (v: boolean) => void }) {
  const [filter, setFilter] = useState<'pending' | 'marked' | 'all'>('pending');
  const [showAll, setShowAll] = useState(false);
  const items = d?.schedule ?? [];
  const isMarked = (s: ScheduleItem) => (s.kind === 'period' ? !!s.attendance_id : (s.sessions ?? 0) > 0);
  const pending = items.filter((s) => !isMarked(s));
  const marked = items.filter(isMarked);
  const list = filter === 'pending' ? pending : filter === 'marked' ? marked : items;
  const visible = showAll ? list : list.slice(0, 8);
  const timetable = d?.kpis.scheduled_source === 'timetable';
  const blocked = !!d?.day.blocked;
  const tabs = useMemo(() => [
    { key: 'pending', label: 'Pending', count: pending.length },
    { key: 'marked', label: 'Marked', count: marked.length },
    { key: 'all', label: 'All', count: items.length },
  ], [pending.length, marked.length, items.length]);

  return (
    <Card className="h-full">
      <CardHeader
        title={timetable ? "Today's classes" : 'Sections today'}
        subtitle={timetable ? 'From the published timetable — mark each period as it ends' : 'No timetable published for today — mark each section'}
        icon={Users}
        actions={setMine && <Toggle checked={mine} onChange={setMine} label="My classes" />}
      />
      <div className="px-4 pt-3 sm:px-5">
        <Tabs variant="pills" tabs={tabs} value={filter} onChange={(v) => { setFilter(v as typeof filter); setShowAll(false); }} />
      </div>
      {loading ? (
        <div className="space-y-3 p-5">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>
      ) : blocked ? (
        <EmptyState icon={CalendarCheck2} title="No classes today" description={d?.day.blocked ?? ''} />
      ) : list.length === 0 ? (
        <EmptyState icon={filter === 'pending' ? CheckCircle2 : CircleDashed} title={filter === 'pending' ? 'All caught up' : 'Nothing marked yet'} description={filter === 'pending' ? 'Every class has been marked for this day.' : 'Marked classes will appear here.'} />
      ) : (
        <>
          <ul className="mt-2 divide-y divide-slate-100 dark:divide-slate-800">
            {visible.map((s, i) => {
              const done = isMarked(s);
              const pct = s.kind === 'period' ? s.counts?.percent : s.percent;
              return (
                <li key={`${s.section_id}-${s.time_slot_id ?? 0}-${s.subject_id ?? 0}-${i}`} className="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                  <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', done ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-400 dark:bg-slate-800')}>
                    {done ? <CheckCircle2 className="h-4 w-4" /> : <CircleDashed className="h-4 w-4" />}
                  </span>
                  <div className="min-w-0 flex-1 leading-tight">
                    <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                      {s.kind === 'period' ? s.subject : s.section_label}
                    </p>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                      {s.kind === 'period'
                        ? `${s.section_label} · ${s.slot_name} ${formatTime(s.start_time)} · ${s.faculty_name ?? 'Unassigned'}`
                        : `${s.students} students · ${s.sessions} sessions today${s.faculty_name ? ` · Class teacher ${s.faculty_name}` : ''}`}
                    </p>
                  </div>
                  {done && <div className="hidden w-36 md:block"><PercentBar value={pct} min={d?.min_percent} /></div>}
                  <Button
                    size="xs"
                    variant={done ? 'secondary' : 'primary'}
                    onClick={() => onMark(s.kind === 'period' ? { date: d?.date, section: s.section_id, subject: s.subject_id ?? null, slot: s.time_slot_id ?? null } : { date: d?.date, section: s.section_id, subject: null, slot: null })}
                  >
                    {done ? (s.kind === 'period' ? 'View' : 'Open') : 'Mark'}
                  </Button>
                </li>
              );
            })}
          </ul>
          {list.length > 8 && (
            <div className="border-t border-slate-100 px-5 py-2.5 text-center dark:border-slate-800">
              <button type="button" className="link text-xs" onClick={() => setShowAll((v) => !v)}>
                {showAll ? 'Show fewer' : `Show all ${list.length}`}
              </button>
            </div>
          )}
        </>
      )}
    </Card>
  );
}
