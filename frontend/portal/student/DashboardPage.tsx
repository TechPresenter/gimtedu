import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  ArrowRight, BookOpenCheck, CalendarCheck, CalendarClock, CalendarOff, CircleDot, Clock, GraduationCap, IdCard, IndianRupee, MapPin, Megaphone, NotebookPen, Pin, UserRound,
} from 'lucide-react';
import { Stagger } from '@/components/ui';
import { formatDate, formatMoney, formatNumber, labelize, timeAgo } from '@/lib/format';
import { WelcomeHero, HeroChip } from '../components/Hero';
import { CategoryBadge, NoticeDrawer } from '../components/Notices';
import { EmptyBlock, ErrorState, KpiCard, KpiSkeleton, Ring, Section } from '../components/Ui';
import { areaPath } from '../lib/config';
import { usePortalQuery, usePortalTitle } from '../lib/hooks';
import { hasRoute } from '../lib/routing';
import { useStudentSession } from '../lib/session';
import { dateParts, daysLeftLabel, greeting, hhmm, longDate } from '../lib/time';
import { studentRoutes } from './routes';
import type { StudentDashboard, TodayClass } from './types';
import { AdmitCardAction, CertificateNudge, FeeDuesCard, GpaTrendCard, LibraryLoansCard, QuickActions } from './shared/DashboardExtras';
import type { StudentOverview } from './shared/types';

const linkIf = (path: string) => (hasRoute(studentRoutes, path) ? areaPath('student', path) : undefined);

function attendanceTone(p: number | null, threshold: number): 'green' | 'amber' | 'red' {
  if (p === null) return 'amber';
  if (p < threshold) return 'red';
  return p < threshold + 10 ? 'amber' : 'green';
}

const MARK_BADGE: Record<string, string> = { present: 'badge-green', absent: 'badge-red', late: 'badge-amber', leave: 'badge-blue', half_day: 'badge-amber' };

function ClassRow({ c, last }: { c: TodayClass; last: boolean }) {
  return (
    <li className="relative flex gap-3 pb-5 last:pb-0">
      {!last && <span className="absolute bottom-0 left-[70px] top-7 w-px bg-slate-200 dark:bg-slate-800" aria-hidden />}
      <div className="w-[3.25rem] shrink-0 pt-1 text-right">
        <p className="text-[13px] font-bold tabular-nums text-slate-800 dark:text-slate-100">{hhmm(c.start_time).replace(/ (AM|PM)$/, '')}</p>
        <p className="text-[10px] font-semibold uppercase text-slate-400">{hhmm(c.start_time).slice(-2)}</p>
      </div>
      <span
        className={clsx(
          'relative z-[1] mt-1.5 inline-flex h-3.5 w-3.5 shrink-0 rounded-full ring-4',
          c.state === 'now' ? 'bg-flame-500 ring-flame-100 dark:ring-flame-500/20' : c.state === 'done' ? 'bg-slate-300 ring-slate-100 dark:bg-slate-600 dark:ring-slate-800' : 'bg-brand-700 ring-brand-50 dark:bg-brand-400 dark:ring-brand-500/10',
        )}
        aria-hidden
      />
      <div
        className={clsx(
          'min-w-0 flex-1 rounded-2xl border px-3.5 py-3 transition',
          c.state === 'now' ? 'border-flame-200 bg-flame-50/70 dark:border-flame-500/30 dark:bg-flame-500/10' : 'border-slate-200/80 bg-white dark:border-slate-800 dark:bg-slate-900',
          c.state === 'done' && 'opacity-80',
        )}
      >
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
              {c.subject_code} · {labelize(c.type)}
            </p>
            <p className="truncate text-sm font-bold text-slate-900 dark:text-white">{c.subject}</p>
          </div>
          {c.state === 'now' ? (
            <span className="badge badge-flame">
              <CircleDot className="h-3 w-3 animate-pulse" aria-hidden /> In progress
            </span>
          ) : c.attendance ? (
            <span className={clsx('badge', MARK_BADGE[c.attendance] ?? 'badge-slate')}>{labelize(c.attendance)}</span>
          ) : c.state === 'done' ? (
            <span className="badge badge-slate">Completed</span>
          ) : (
            <span className="badge badge-navy">Upcoming</span>
          )}
        </div>
        <p className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
          <span className="inline-flex items-center gap-1">
            <Clock className="h-3.5 w-3.5" aria-hidden />
            {hhmm(c.start_time)} – {hhmm(c.end_time)}
          </span>
          {c.faculty && (
            <span className="inline-flex items-center gap-1">
              <UserRound className="h-3.5 w-3.5" aria-hidden />
              {c.faculty}
            </span>
          )}
          {(c.room_code || c.room) && (
            <span className="inline-flex items-center gap-1">
              <MapPin className="h-3.5 w-3.5" aria-hidden />
              {c.room_code ?? c.room}
            </span>
          )}
        </p>
      </div>
    </li>
  );
}

export default function StudentDashboardPage() {
  const session = useStudentSession();
  const s = session.student!;
  usePortalTitle('Dashboard', 'student');
  const { data, isLoading, error, refetch } = usePortalQuery<StudentDashboard>('student', ['dashboard'], 'dashboard');
  const { data: overview } = usePortalQuery<StudentOverview>('student', ['overview'], 'overview');
  const [noticeId, setNoticeId] = useState<number | null>(null);

  const k = data?.kpis;
  const att = k?.attendance;
  const attTone = att ? attendanceTone(att.percent, att.threshold) : 'amber';
  const next = data?.today.classes.find((c) => c.state !== 'done') ?? null;

  const heroAside = data ? (
    <div className="glass rounded-2xl p-4">
      <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-white/60">Today</p>
      {!data.today.working ? (
        <div className="mt-2 flex items-center gap-3">
          <CalendarOff className="h-8 w-8 text-flame-300" aria-hidden />
          <div>
            <p className="font-display text-base font-bold text-white">{data.today.holiday ?? 'Weekly off'}</p>
            <p className="text-xs text-white/70">No classes scheduled today. Enjoy your day!</p>
          </div>
        </div>
      ) : next ? (
        <div className="mt-2">
          <p className="text-xs text-white/70">{next.state === 'now' ? 'In class now' : 'Next class'}</p>
          <p className="mt-0.5 truncate font-display text-base font-bold text-white">{next.subject}</p>
          <p className="mt-1 flex flex-wrap gap-x-3 text-xs text-white/75">
            <span>
              {hhmm(next.start_time)} – {hhmm(next.end_time)}
            </span>
            {next.room_code && <span>Room {next.room_code}</span>}
          </p>
        </div>
      ) : (
        <div className="mt-2">
          <p className="font-display text-base font-bold text-white">{data.today.classes.length ? 'All classes done for today' : 'No classes on your timetable today'}</p>
          <p className="text-xs text-white/70">{data.today.classes.length ? `${data.today.classes.length} classes completed.` : 'Check notices for any schedule changes.'}</p>
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
            {greeting()}, {s.first_name}
          </>
        }
        subtitle={`${s.program_short} · Semester ${s.semester}${s.section ? ` · Section ${s.section}` : ''}${session.app.academic_session ? ` · Session ${session.app.academic_session}` : ''}`}
        chips={
          <>
            <HeroChip icon={IdCard}>{s.student_uid}</HeroChip>
            {s.roll_no && <HeroChip icon={GraduationCap}>Roll {s.roll_no}</HeroChip>}
          </>
        }
        aside={heroAside}
      />

      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : (
        <>
          {isLoading || !k || !att ? (
            <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
              {[0, 1, 2, 3].map((i) => (
                <KpiSkeleton key={i} />
              ))}
            </div>
          ) : (
            <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" itemClassName="h-full">
              {[
                <KpiCard
                  key="att"
                  label="Attendance"
                  icon={CalendarCheck}
                  tone={attTone === 'red' ? 'red' : attTone === 'amber' ? 'amber' : 'green'}
                  value={att.percent === null ? '—' : att.percent}
                  unit={att.percent === null ? undefined : '%'}
                  to={linkIf('attendance')}
                  aside={
                    <div className="hidden sm:block">
                      <Ring value={att.percent ?? 0} size={56} stroke={6} tone={attTone} label={`Attendance ${att.percent ?? 0}%`}>
                        <span className="text-[10px] font-bold text-slate-500">{att.threshold}%</span>
                      </Ring>
                    </div>
                  }
                  badge={
                    att.percent !== null && att.percent < att.threshold ? (
                      <span className="badge badge-red">Attend {att.classes_needed} more in a row</span>
                    ) : att.percent !== null ? (
                      <span className="badge badge-green">Above {att.threshold}% minimum</span>
                    ) : undefined
                  }
                  foot={att.held ? `${formatNumber(att.attended)} of ${formatNumber(att.held)} classes this session` : 'Attendance will appear once classes are marked.'}
                />,
                <KpiCard
                  key="cgpa"
                  label="CGPA"
                  icon={BookOpenCheck}
                  tone="navy"
                  value={k.cgpa.cgpa ?? k.cgpa.sgpa ?? '—'}
                  unit={k.cgpa.cgpa !== null || k.cgpa.sgpa !== null ? '/ 10' : undefined}
                  to={linkIf('results')}
                  badge={
                    k.cgpa.result_status ? (
                      <span className={clsx('badge', /pass/i.test(k.cgpa.result_status) ? 'badge-green' : 'badge-amber')}>
                        Sem {k.cgpa.semester}: {k.cgpa.result_status.toUpperCase()}
                      </span>
                    ) : undefined
                  }
                  foot={
                    k.cgpa.cgpa !== null || k.cgpa.sgpa !== null
                      ? `${k.cgpa.sgpa !== null ? `Latest SGPA ${k.cgpa.sgpa.toFixed(2)}` : 'Cumulative'}${k.cgpa.backlogs ? ` · ${k.cgpa.backlogs} backlog${k.cgpa.backlogs > 1 ? 's' : ''}` : ''}`
                      : 'Your CGPA appears after your first semester results are published.'
                  }
                />,
                <KpiCard
                  key="fees"
                  label="Fee due"
                  icon={IndianRupee}
                  tone={k.fees.overdue > 0 ? 'red' : k.fees.balance > 0 ? 'amber' : 'green'}
                  value={formatMoney(k.fees.balance)}
                  to={linkIf('fees')}
                  badge={
                    k.fees.overdue > 0 ? (
                      <span className="badge badge-red">{formatMoney(k.fees.overdue)} overdue</span>
                    ) : k.fees.balance > 0 ? (
                      <span className="badge badge-amber">Due {formatDate(k.fees.next_due)}</span>
                    ) : (
                      <span className="badge badge-green">All dues cleared</span>
                    )
                  }
                  foot={k.fees.net ? `${formatMoney(k.fees.paid)} paid of ${formatMoney(k.fees.net)}` : 'No fee invoices yet.'}
                />,
                <KpiCard
                  key="exams"
                  label="Upcoming exams"
                  icon={NotebookPen}
                  tone="flame"
                  value={k.exams.upcoming}
                  unit={k.exams.upcoming === 1 ? 'paper' : 'papers'}
                  to={linkIf('exams')}
                  badge={k.exams.next ? <span className="badge badge-flame">{daysLeftLabel(k.exams.next.days_left)}</span> : undefined}
                  foot={k.exams.next ? `Next: ${k.exams.next.subject_code} on ${formatDate(k.exams.next.exam_date)}` : 'No exams scheduled right now.'}
                />,
              ]}
            </Stagger>
          )}

          <QuickActions data={overview} />
          <CertificateNudge data={overview} />

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">
            <Section
              title="Today's classes"
              subtitle={data ? `${data.today.day}, ${formatDate(data.today.date)}` : undefined}
              icon={CalendarClock}
              className="lg:col-span-3"
              action={
                linkIf('timetable') ? (
                  <Link to={linkIf('timetable')!} className="link inline-flex items-center gap-1 text-[13px]">
                    Timetable <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                  </Link>
                ) : undefined
              }
            >
              {isLoading || !data ? (
                <div className="space-y-3">
                  {[0, 1, 2].map((i) => (
                    <div key={i} className="skeleton h-16 rounded-2xl" />
                  ))}
                </div>
              ) : !data.today.working ? (
                <EmptyBlock icon={CalendarOff} title={data.today.holiday ?? 'Weekly off'} text="There are no classes today." />
              ) : data.today.classes.length === 0 ? (
                <EmptyBlock icon={CalendarClock} title="No classes today" text="Your section has no periods on the timetable for today." />
              ) : (
                <ol aria-label="Today's classes">
                  {data.today.classes.map((c, i) => (
                    <ClassRow key={c.id} c={c} last={i === data.today.classes.length - 1} />
                  ))}
                </ol>
              )}
            </Section>

            <Section title="Exam schedule" subtitle="Your upcoming papers" icon={NotebookPen} className="lg:col-span-2" bodyClassName="p-2 sm:p-3" action={<AdmitCardAction data={overview} />}>
              {isLoading || !data ? (
                <div className="space-y-2 p-2">
                  {[0, 1, 2, 3].map((i) => (
                    <div key={i} className="skeleton h-14 rounded-xl" />
                  ))}
                </div>
              ) : data.exams.length === 0 ? (
                <EmptyBlock compact icon={NotebookPen} title="No upcoming exams" text="When the examination cell schedules papers for your semester they will show up here." />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.exams.map((x) => {
                    const d = dateParts(x.exam_date);
                    return (
                      <li key={x.id} className="flex items-center gap-3 rounded-xl px-2 py-2.5">
                        <div className="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-brand-900 text-white dark:bg-brand-800">
                          <span className="font-display text-base font-extrabold leading-none">{d.day}</span>
                          <span className="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-white/70">{d.month}</span>
                        </div>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{x.subject}</p>
                          <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                            {x.subject_code} · {d.weekday} {hhmm(x.start_time)}
                            {x.room ? ` · ${x.room}` : ''}
                          </p>
                        </div>
                        <span className={clsx('badge shrink-0', x.days_left <= 7 ? 'badge-flame' : 'badge-slate')}>{daysLeftLabel(x.days_left)}</span>
                      </li>
                    );
                  })}
                </ul>
              )}
            </Section>
          </div>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">
            <Section
              title="Latest notices"
              subtitle="From the institute, for you"
              icon={Megaphone}
              className="lg:col-span-3"
              bodyClassName="p-2 sm:p-3"
              action={
                <Link to={areaPath('student', 'notices')} className="link inline-flex items-center gap-1 text-[13px]">
                  View all <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                </Link>
              }
            >
              {isLoading || !data ? (
                <div className="space-y-2 p-2">
                  {[0, 1, 2].map((i) => (
                    <div key={i} className="skeleton h-16 rounded-xl" />
                  ))}
                </div>
              ) : data.notices.length === 0 ? (
                <EmptyBlock compact icon={Megaphone} title="No notices right now" text="Circulars for your program and semester will appear here." />
              ) : (
                <ul>
                  {data.notices.map((n) => (
                    <li key={n.id}>
                      <button type="button" onClick={() => setNoticeId(n.id)} className="flex w-full items-start gap-3 rounded-xl px-2.5 py-3 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <span className={clsx('mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', n.is_pinned ? 'bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800')}>
                          {n.is_pinned ? <Pin className="h-4 w-4" aria-hidden /> : <Megaphone className="h-4 w-4" aria-hidden />}
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-semibold text-slate-900 dark:text-white">{n.title}</span>
                          </span>
                          <span className="mt-0.5 line-clamp-1 block text-xs text-slate-500 dark:text-slate-400">{n.excerpt}</span>
                          <span className="mt-1.5 flex items-center gap-2 text-[11px] text-slate-400">
                            <CategoryBadge category={n.category} />
                            {timeAgo(n.date)}
                          </span>
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </Section>

            <Section title="Attendance summary" subtitle={data?.session ? `Session ${data.session}` : undefined} icon={CalendarCheck} className="lg:col-span-2">
              {isLoading || !att ? (
                <div className="space-y-3">
                  <div className="skeleton mx-auto h-28 w-28 rounded-full" />
                  <div className="skeleton h-4 w-full" />
                  <div className="skeleton h-4 w-5/6" />
                </div>
              ) : att.held === 0 ? (
                <EmptyBlock compact icon={CalendarCheck} title="No attendance yet" text="Your attendance appears here once faculty mark your classes." />
              ) : (
                <div className="flex flex-col items-center gap-5 sm:flex-row lg:flex-col xl:flex-row">
                  <Ring value={att.percent ?? 0} size={120} stroke={11} tone={attTone} label={`Overall attendance ${att.percent}%`}>
                    <span>
                      <span className="block font-display text-2xl font-extrabold text-slate-900 dark:text-white">{att.percent}%</span>
                      <span className="block text-[10px] font-semibold uppercase tracking-wider text-slate-400">Overall</span>
                    </span>
                  </Ring>
                  <ul className="w-full flex-1 space-y-2.5">
                    {[
                      { label: 'Present', value: att.present, cls: 'bg-emerald-500' },
                      { label: 'Late', value: att.late, cls: 'bg-amber-500' },
                      { label: 'Absent', value: att.absent, cls: 'bg-red-500' },
                      { label: 'Leave', value: att.leave, cls: 'bg-sky-500' },
                    ].map((r) => (
                      <li key={r.label}>
                        <div className="flex items-center justify-between text-xs">
                          <span className="flex items-center gap-2 font-medium text-slate-600 dark:text-slate-300">
                            <span className={clsx('h-2 w-2 rounded-full', r.cls)} aria-hidden />
                            {r.label}
                          </span>
                          <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(r.value)}</span>
                        </div>
                        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                          <div className={clsx('h-full rounded-full', r.cls)} style={{ width: `${att.held ? Math.round((r.value / att.held) * 100) : 0}%` }} />
                        </div>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </Section>
          </div>

          <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
            <GpaTrendCard data={overview} />
            <FeeDuesCard data={overview} />
            <LibraryLoansCard data={overview} />
          </div>
        </>
      )}
      <NoticeDrawer area="student" id={noticeId} onClose={() => setNoticeId(null)} />
    </div>
  );
}
