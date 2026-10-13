import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  ArrowRight, BadgeCheck, BookMarked, CalendarClock, CalendarDays, CalendarOff, CalendarPlus, ChevronRight, CircleDot, ClipboardCheck, ClipboardList, FolderLock, IdCard, Layers, MapPin, Megaphone,
  NotebookPen, Pin, Plane, UsersRound, type LucideIcon,
} from 'lucide-react';
import { Stagger } from '@/components/ui';
import { formatDate, formatNumber, labelize, timeAgo } from '@/lib/format';
import { HeroChip, WelcomeHero } from '../components/Hero';
import { CategoryBadge, NoticeDrawer } from '../components/Notices';
import { EmptyBlock, ErrorState, KpiCard, KpiSkeleton, Section } from '../components/Ui';
import { areaPath } from '../lib/config';
import { usePortalQuery, usePortalTitle } from '../lib/hooks';
import { hasRoute } from '../lib/routing';
import { useFacultySession } from '../lib/session';
import { dateParts, greeting, hhmm, longDate } from '../lib/time';
import { facultyRoutes } from './routes';
import type { MarksListData } from './shared/types';
import type { FacultyClass, FacultyDashboard, PendingMarks } from './types';

const linkIf = (path: string) => (hasRoute(facultyRoutes, path) ? areaPath('faculty', path) : undefined);

const markLink = linkIf('attendance');

function PendingLink({ to, children }: { to?: string; children: ReactNode }) {
  const cls = 'group flex items-center gap-3 rounded-xl px-2 py-2.5';
  return to ? (
    <Link to={to} className={clsx(cls, 'transition hover:bg-slate-50 dark:hover:bg-slate-800/50')}>
      {children}
    </Link>
  ) : (
    <div className={cls}>{children}</div>
  );
}

const QUICK: { path: string; label: string; sub: string; icon: LucideIcon; tone: string; badge?: 'att' | 'marks' }[] = [
  { path: 'attendance', label: 'Attendance', sub: 'Mark registers', icon: ClipboardCheck, tone: 'bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300', badge: 'att' },
  { path: 'marks', label: 'Marks entry', sub: 'Exam papers', icon: NotebookPen, tone: 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200', badge: 'marks' },
  { path: 'leave', label: 'Apply leave', sub: 'Balance & status', icon: CalendarPlus, tone: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300' },
  { path: 'timetable', label: 'My timetable', sub: 'Weekly schedule', icon: CalendarDays, tone: 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300' },
  { path: 'classes', label: 'My classes', sub: 'Rosters', icon: UsersRound, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' },
  { path: 'documents', label: 'Documents', sub: 'Personnel file', icon: FolderLock, tone: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' },
];

function QuickActions({ pendingAtt, pendingMarks }: { pendingAtt: number; pendingMarks: number }) {
  const actions = QUICK.filter((a) => hasRoute(facultyRoutes, a.path));
  if (!actions.length) return null;
  return (
    <nav aria-label="Quick actions" className="-mx-4 overflow-x-auto px-4 pb-1 scrollbar-none sm:mx-0 sm:px-0">
      <ul className="flex gap-2.5 sm:grid sm:grid-cols-3 xl:grid-cols-6">
        {actions.map((a) => {
          const badge = a.badge === 'att' ? pendingAtt : a.badge === 'marks' ? pendingMarks : 0;
          return (
            <li key={a.path} className="w-[44%] shrink-0 sm:w-auto">
              <Link to={areaPath('faculty', a.path)} className="card lift flex h-full items-center gap-3 p-3">
                <span className={clsx('relative inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', a.tone)}>
                  <a.icon className="h-5 w-5" aria-hidden />
                  {badge > 0 && (
                    <span className="absolute -right-1.5 -top-1.5 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white ring-2 ring-white dark:ring-slate-900" aria-label={`${badge} pending`}>
                      {badge}
                    </span>
                  )}
                </span>
                <span className="min-w-0">
                  <span className="block truncate text-[13px] font-bold text-slate-900 dark:text-white">{a.label}</span>
                  <span className="block truncate text-[11px] text-slate-500 dark:text-slate-400">{a.sub}</span>
                </span>
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

function ScheduleRow({ c }: { c: FacultyClass }) {
  return (
    <li className={clsx('flex flex-col gap-3 rounded-2xl border p-3.5 sm:flex-row sm:items-center', c.state === 'now' ? 'border-flame-200 bg-flame-50/70 dark:border-flame-500/30 dark:bg-flame-500/10' : 'border-slate-200/80 dark:border-slate-800')}>
      <div className="flex items-center gap-3 sm:w-36 sm:shrink-0">
        <span className={clsx('inline-flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-xl text-white', c.state === 'done' ? 'bg-slate-400 dark:bg-slate-700' : 'bg-brand-900 dark:bg-brand-700')}>
          <span className="font-display text-[13px] font-extrabold leading-none">{hhmm(c.start_time).replace(/ (AM|PM)$/, '')}</span>
          <span className="text-[9px] font-semibold uppercase text-white/70">{hhmm(c.start_time).slice(-2)}</span>
        </span>
        <div className="leading-tight">
          <p className="text-xs font-semibold text-slate-500 dark:text-slate-400">{c.slot}</p>
          <p className="text-[11px] text-slate-400">until {hhmm(c.end_time)}</p>
        </div>
      </div>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-bold text-slate-900 dark:text-white">
          {c.subject} <span className="font-medium text-slate-400">· {c.subject_code}</span>
        </p>
        <p className="mt-0.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
          <span className="inline-flex items-center gap-1">
            <UsersRound className="h-3.5 w-3.5" aria-hidden />
            {c.program} Sem {c.semester_no} · Sec {c.section}
          </span>
          {(c.room_code || c.room) && (
            <span className="inline-flex items-center gap-1">
              <MapPin className="h-3.5 w-3.5" aria-hidden />
              {c.room_code ?? c.room}
            </span>
          )}
          <span>{labelize(c.type)}</span>
        </p>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        {c.marked ? (
          <span className="badge badge-green">
            <BadgeCheck className="h-3 w-3" aria-hidden /> Marked {c.present}/{c.total}
          </span>
        ) : c.state === 'now' ? (
          <span className="badge badge-flame">
            <CircleDot className="h-3 w-3 animate-pulse" aria-hidden /> In progress
          </span>
        ) : c.state === 'done' ? (
          <span className="badge badge-red">Attendance pending</span>
        ) : (
          <span className="badge badge-navy">Upcoming</span>
        )}
        {markLink && (
          <Link
            to={`${markLink}?section_id=${c.section_id}&subject_id=${c.subject_id}&start=${c.start_time}`}
            className={clsx('btn btn-xs', c.marked ? 'btn-ghost' : 'btn-soft')}
            aria-label={`${c.marked ? 'View' : 'Mark'} attendance for ${c.subject}`}
          >
            {c.marked ? 'View' : 'Mark'} <ChevronRight className="h-3.5 w-3.5" aria-hidden />
          </Link>
        )}
      </div>
    </li>
  );
}

export default function FacultyDashboardPage() {
  const session = useFacultySession();
  const f = session.faculty!;
  usePortalTitle('Dashboard', 'faculty');
  const { data, isLoading, error, refetch } = usePortalQuery<FacultyDashboard>('faculty', ['dashboard'], 'dashboard');
  // Marks are counted for the faculty's own sections (same source as the Marks Entry screen).
  const marksQ = usePortalQuery<MarksListData>('faculty', ['marks', 'list'], 'marks', undefined, { enabled: hasRoute(facultyRoutes, 'marks') });
  const myPapers: PendingMarks[] | null = marksQ.data
    ? marksQ.data.exams.flatMap((e) =>
        e.papers
          .filter((p) => p.open && p.state !== 'submitted')
          .map((p) => ({ exam_id: e.id, exam: e.name, subject_id: p.subject_id, subject_code: p.subject_code, subject: p.subject, students: p.students, entered: p.entered, submitted: p.submitted, end_date: e.end_date })),
      )
    : null;
  const pendingMarks = myPapers ?? data?.pending_marks ?? [];
  const marksKpi = myPapers ? { count: myPapers.length, students: myPapers.reduce((n, m) => n + Math.max(0, m.students - m.entered), 0) } : data?.kpis.pending_marks;
  const [noticeId, setNoticeId] = useState<number | null>(null);
  const k = data?.kpis;
  const next = k?.today_classes.next ?? null;
  const name = [f.title, f.first_name].filter(Boolean).join(' ');

  const heroAside = data ? (
    <div className="glass rounded-2xl p-4">
      <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-white/60">Today</p>
      {!data.today.working ? (
        <div className="mt-2 flex items-center gap-3">
          <CalendarOff className="h-8 w-8 text-flame-300" aria-hidden />
          <div>
            <p className="font-display text-base font-bold text-white">{data.today.holiday ?? 'Weekly off'}</p>
            <p className="text-xs text-white/70">No classes scheduled today.</p>
          </div>
        </div>
      ) : next ? (
        <div className="mt-2">
          <p className="text-xs text-white/70">{next.state === 'now' ? 'Teaching now' : 'Next class'}</p>
          <p className="mt-0.5 truncate font-display text-base font-bold text-white">{next.subject}</p>
          <p className="mt-1 flex flex-wrap gap-x-3 text-xs text-white/75">
            <span>
              {hhmm(next.start_time)} – {hhmm(next.end_time)}
            </span>
            <span>
              {next.program} Sem {next.semester_no}-{next.section}
            </span>
            {next.room_code && <span>Room {next.room_code}</span>}
          </p>
        </div>
      ) : (
        <div className="mt-2">
          <p className="font-display text-base font-bold text-white">{data.today.classes.length ? 'All classes done for today' : 'No classes today'}</p>
          <p className="text-xs text-white/70">{data.today.classes.length ? `${data.today.classes.length} periods taught.` : 'Your timetable has no periods today.'}</p>
        </div>
      )}
    </div>
  ) : undefined;

  return (
    <div className="space-y-5 sm:space-y-6">
      <WelcomeHero
        eyebrow={longDate()}
        title={
          <>
            {greeting()}, {name}
          </>
        }
        subtitle={[f.designation, f.department, session.app.academic_session ? `Session ${session.app.academic_session}` : null].filter(Boolean).join(' · ')}
        chips={
          <>
            <HeroChip icon={IdCard}>{f.employee_id}</HeroChip>
            {data && (
              <HeroChip icon={Layers}>
                {data.load.subjects} subjects · {data.load.sections} sections · {data.load.periods_per_week} periods/week
              </HeroChip>
            )}
          </>
        }
        aside={heroAside}
      />

      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : (
        <>
          {isLoading || !k ? (
            <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
              {[0, 1, 2, 3].map((i) => (
                <KpiSkeleton key={i} />
              ))}
            </div>
          ) : (
            <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" itemClassName="h-full">
              {[
                <KpiCard
                  key="today"
                  label="Today's classes"
                  icon={CalendarClock}
                  tone="navy"
                  value={k.today_classes.count}
                  unit={k.today_classes.count === 1 ? 'period' : 'periods'}
                  to={linkIf('timetable')}
                  badge={k.today_classes.count ? <span className="badge badge-slate">{k.today_classes.done} done</span> : undefined}
                  foot={next ? `Next: ${next.subject_code} at ${hhmm(next.start_time)}` : k.today_classes.count ? 'All periods for today are over.' : 'Nothing on your timetable today.'}
                />,
                <KpiCard
                  key="att"
                  label="Pending attendance"
                  icon={ClipboardCheck}
                  tone={k.pending_attendance.count ? 'red' : 'green'}
                  value={k.pending_attendance.count}
                  unit={k.pending_attendance.count === 1 ? 'class' : 'classes'}
                  to={linkIf('attendance')}
                  badge={k.pending_attendance.count ? <span className="badge badge-red">Needs marking</span> : <span className="badge badge-green">All registers up to date</span>}
                  foot={`Taught periods in the last ${k.pending_attendance.days} days without an attendance register.`}
                />,
                <KpiCard
                  key="marks"
                  label="Marks entry"
                  icon={NotebookPen}
                  tone={marksKpi?.count ? 'flame' : 'green'}
                  value={marksKpi?.count ?? 0}
                  unit={marksKpi?.count === 1 ? 'paper' : 'papers'}
                  to={linkIf('marks')}
                  badge={marksKpi?.count ? <span className="badge badge-flame">{formatNumber(marksKpi.students)} students pending</span> : <span className="badge badge-green">Nothing pending</span>}
                  foot="Exam papers in marks entry for subjects you teach."
                />,
                <KpiCard
                  key="leave"
                  label="Leave balance"
                  icon={Plane}
                  tone="violet"
                  value={k.leave.available}
                  unit="days"
                  to={linkIf('leave')}
                  badge={k.leave.pending ? <span className="badge badge-amber">{k.leave.pending} request{k.leave.pending > 1 ? 's' : ''} pending</span> : undefined}
                  foot={k.leave.types.length ? k.leave.types.slice(0, 2).map((t) => `${t.name.replace(/ Leave.*$/, '')} ${t.available}`).join(' · ') : 'No leave types configured.'}
                />,
              ]}
            </Stagger>
          )}

          {k && <QuickActions pendingAtt={k.pending_attendance.count} pendingMarks={marksKpi?.count ?? 0} />}

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">
            <Section
              title="Today's schedule"
              subtitle={data ? `${data.today.day}, ${formatDate(data.today.date)}` : undefined}
              icon={CalendarClock}
              className="lg:col-span-3"
              action={
                linkIf('timetable') ? (
                  <Link to={linkIf('timetable')!} className="link inline-flex items-center gap-1 text-[13px]">
                    Full timetable <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                  </Link>
                ) : undefined
              }
            >
              {isLoading || !data ? (
                <div className="space-y-3">
                  {[0, 1, 2].map((i) => (
                    <div key={i} className="skeleton h-[4.5rem] rounded-2xl" />
                  ))}
                </div>
              ) : !data.today.working ? (
                <EmptyBlock icon={CalendarOff} title={data.today.holiday ?? 'Weekly off'} text="No classes are scheduled today." />
              ) : data.today.classes.length === 0 ? (
                <EmptyBlock icon={CalendarClock} title="No classes today" text="Your published timetable has no periods for today." />
              ) : (
                <ul className="space-y-2.5">
                  {data.today.classes.map((c) => (
                    <ScheduleRow key={c.id} c={c} />
                  ))}
                </ul>
              )}
            </Section>

            <Section title="Attendance to mark" subtitle={k ? `Last ${k.pending_attendance.days} days` : undefined} icon={ClipboardList} className="lg:col-span-2" bodyClassName="p-2 sm:p-3">
              {isLoading || !data ? (
                <div className="space-y-2 p-2">
                  {[0, 1, 2].map((i) => (
                    <div key={i} className="skeleton h-14 rounded-xl" />
                  ))}
                </div>
              ) : data.pending_attendance.length === 0 ? (
                <EmptyBlock compact icon={ClipboardCheck} title="All caught up" text="Every class you taught this week has an attendance register." />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.pending_attendance.map((p, i) => {
                    const d = dateParts(p.date);
                    return (
                      <li key={`${p.date}-${p.section_id}-${p.subject_id}-${i}`}>
                        <PendingLink to={markLink ? `${markLink}?date=${p.date}&section_id=${p.section_id}&subject_id=${p.subject_id}&start=${p.start_time}` : undefined}>
                        <div className="flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-xl bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300">
                          <span className="font-display text-sm font-extrabold leading-none">{d.day}</span>
                          <span className="text-[10px] font-semibold uppercase">{d.month}</span>
                        </div>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{p.subject}</p>
                          <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                            {p.program} Sem {p.semester_no}-{p.section} · {p.slot} ({hhmm(p.start_time)})
                          </p>
                        </div>
                        {markLink && <ChevronRight className="h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-flame-600 dark:text-slate-600" aria-hidden />}
                        </PendingLink>
                      </li>
                    );
                  })}
                </ul>
              )}
              {k && k.pending_attendance.count > (data?.pending_attendance.length ?? 0) && (
                <p className="px-3 pb-2 pt-1 text-xs text-slate-500">+ {k.pending_attendance.count - (data?.pending_attendance.length ?? 0)} more</p>
              )}
            </Section>
          </div>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <Section title="Marks entry" subtitle="Papers awaiting your marks" icon={NotebookPen} bodyClassName="p-3 sm:p-4">
              {isLoading || !data ? (
                <div className="space-y-2">
                  {[0, 1].map((i) => (
                    <div key={i} className="skeleton h-16 rounded-xl" />
                  ))}
                </div>
              ) : pendingMarks.length === 0 ? (
                <EmptyBlock compact icon={BookMarked} title="Nothing pending" text="No exam in marks entry needs your marks right now." />
              ) : (
                <ul className="space-y-3">
                  {pendingMarks.slice(0, 6).map((m) => {
                    const done = myPapers ? m.entered : m.submitted;
                    const pct = m.students ? Math.round((done / m.students) * 100) : 0;
                    return (
                      <li key={`${m.exam_id}-${m.subject_id}`}>
                        <Link
                          to={areaPath('faculty', `marks/${m.exam_id}/${m.subject_id}`)}
                          className="block rounded-xl border border-slate-200/80 p-3 transition hover:-translate-y-0.5 hover:border-flame-200 hover:shadow-soft dark:border-slate-800 dark:hover:border-flame-500/30"
                        >
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                          {m.subject_code} · {m.subject}
                        </p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">{m.exam}</p>
                        <div className="mt-2 flex items-center gap-2">
                          <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div className="h-full rounded-full bg-flame-500" style={{ width: `${pct}%` }} />
                          </div>
                          <span className="text-[11px] font-semibold tabular-nums text-slate-500">
                            {done}/{m.students} {myPapers ? 'entered' : 'submitted'}
                          </span>
                        </div>
                        </Link>
                      </li>
                    );
                  })}
                </ul>
              )}
            </Section>

            <Section title="Leave balance" subtitle={data?.session ? `Session ${data.session}` : undefined} icon={Plane}>
              {isLoading || !k ? (
                <div className="space-y-3">
                  {[0, 1, 2].map((i) => (
                    <div key={i} className="skeleton h-8 rounded-lg" />
                  ))}
                </div>
              ) : k.leave.types.length === 0 ? (
                <EmptyBlock compact icon={Plane} title="No leave types" text="Leave quotas have not been configured yet." />
              ) : (
                <ul className="space-y-3.5">
                  {k.leave.types.map((t) => {
                    const pct = t.quota ? Math.round((t.available / t.quota) * 100) : 0;
                    return (
                      <li key={t.code}>
                        <div className="flex items-baseline justify-between gap-2 text-xs">
                          <span className="font-semibold text-slate-700 dark:text-slate-200">{t.name}</span>
                          <span className="tabular-nums text-slate-500">
                            <strong className="text-sm text-slate-900 dark:text-white">{t.available}</strong> / {t.quota}
                          </span>
                        </div>
                        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                          <div className="h-full rounded-full bg-violet-500" style={{ width: `${pct}%` }} />
                        </div>
                      </li>
                    );
                  })}
                </ul>
              )}
            </Section>

            <Section
              title="Notices"
              icon={Megaphone}
              bodyClassName="p-2 sm:p-3"
              action={
                <Link to={areaPath('faculty', 'notices')} className="link inline-flex items-center gap-1 text-[13px]">
                  View all <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                </Link>
              }
            >
              {isLoading || !data ? (
                <div className="space-y-2 p-2">
                  {[0, 1, 2].map((i) => (
                    <div key={i} className="skeleton h-14 rounded-xl" />
                  ))}
                </div>
              ) : data.notices.length === 0 ? (
                <EmptyBlock compact icon={Megaphone} title="No notices right now" />
              ) : (
                <ul>
                  {data.notices.map((n) => (
                    <li key={n.id}>
                      <button type="button" onClick={() => setNoticeId(n.id)} className="flex w-full items-start gap-3 rounded-xl px-2 py-2.5 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <span className={clsx('mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', n.is_pinned ? 'bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800')}>
                          {n.is_pinned ? <Pin className="h-4 w-4" aria-hidden /> : <Megaphone className="h-4 w-4" aria-hidden />}
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="line-clamp-2 text-[13px] font-semibold leading-snug text-slate-900 dark:text-white">{n.title}</span>
                          <span className="mt-1 flex items-center gap-2 text-[11px] text-slate-400">
                            <CategoryBadge category={n.category} /> {timeAgo(n.date)}
                          </span>
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </Section>
          </div>
        </>
      )}
      <NoticeDrawer area="faculty" id={noticeId} onClose={() => setNoticeId(null)} />
    </div>
  );
}
