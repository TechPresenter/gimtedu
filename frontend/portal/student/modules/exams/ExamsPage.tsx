import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowRight, BadgeCheck, CalendarClock, ClipboardList, FileBadge2, History, Info, MapPin, NotebookPen, ShieldAlert, Ticket } from 'lucide-react';
import { Stagger } from '@/components/ui';
import { formatDate, labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead, Section } from '../../../components/Ui';
import { areaPath } from '../../../lib/config';
import { daysLeftLabel, hhmm, longDate } from '../../../lib/time';
import { DateTile, ListSkeleton, MiniStat, PrintButton, StatusBadge, useStudent } from '../../shared/ui';
import type { StudentExam, StudentExams } from '../../shared/types';

function ExamCard({ e }: { e: StudentExam }) {
  const a = e.allocation;
  return (
    <section className="card overflow-hidden">
      <header className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 dark:border-slate-800 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={e.phase} label={e.phase === 'ongoing' ? 'In progress' : e.phase === 'upcoming' ? daysLeftLabel(e.days_left ?? 0) : 'Completed'} />
            <span className="badge badge-slate">{e.type}</span>
          </div>
          <h2 className="mt-2 font-display text-base font-bold text-slate-900 dark:text-white sm:text-lg">{e.name}</h2>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            {e.start_date ? `${formatDate(e.start_date)} – ${formatDate(e.end_date)}` : 'Dates to be announced'}
            {e.session ? ` · Session ${e.session}` : ''}
            {e.result_date ? ` · Results expected ${formatDate(e.result_date)}` : ''}
          </p>
        </div>
        <div className="shrink-0">
          {e.admit_card.available ? (
            <PrintButton path={`admit-card/${e.id}`} variant="primary" icon={Ticket}>
              Admit card
            </PrintButton>
          ) : (
            <span className="badge badge-slate">
              <Ticket className="h-3.5 w-3.5" aria-hidden /> Admit card not available
            </span>
          )}
        </div>
      </header>
      <div className="space-y-4 p-4 sm:p-5">
        {a ? (
          a.eligible ? (
            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
              <MiniStat label="Hall ticket" value={a.hall_ticket_no ?? '—'} tone="navy" />
              <MiniStat label="Seat" value={a.seat_no ?? 'On notice board'} />
              <MiniStat label="Exam hall" value={a.room ?? 'To be announced'} />
              <MiniStat label="Attendance at allocation" value={a.attendance_percent !== null ? `${a.attendance_percent}%` : '—'} tone="green" />
            </div>
          ) : (
            <div className="flex items-start gap-3 rounded-2xl bg-red-50 p-4 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">
              <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0" aria-hidden />
              <div>
                <p className="font-semibold">Not eligible for this examination</p>
                <p className="mt-0.5 text-[13px]">{a.reason ?? 'Contact the examination cell.'} Please meet the examination cell as soon as possible.</p>
              </div>
            </div>
          )
        ) : (
          e.admit_card.reason && (
            <p className="flex items-start gap-2 rounded-2xl bg-slate-50 p-3 text-[13px] text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">
              <Info className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden /> {e.admit_card.reason}
            </p>
          )
        )}

        {e.papers.length === 0 ? (
          <p className="text-sm text-slate-500">The date sheet has not been published yet.</p>
        ) : (
          <>
            <ul className="space-y-2 md:hidden" aria-label="Date sheet">
              {e.papers.map((p) => (
                <li key={p.id} className="flex items-center gap-3 rounded-2xl border border-slate-200/80 p-3 dark:border-slate-800">
                  <DateTile date={p.date} tone={p.days_left !== null && p.days_left >= 0 && p.days_left <= 7 ? 'flame' : p.days_left !== null && p.days_left < 0 ? 'muted' : 'navy'} />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{p.name}</p>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                      {p.code} · {hhmm(p.start_time)} – {hhmm(p.end_time)}
                      {p.room_code ? ` · ${p.room_code}` : ''}
                    </p>
                  </div>
                  {p.days_left !== null && p.days_left >= 0 && <span className={clsx('badge shrink-0', p.days_left <= 7 ? 'badge-flame' : 'badge-slate')}>{daysLeftLabel(p.days_left)}</span>}
                </li>
              ))}
            </ul>
            <div className="table-wrap hidden rounded-2xl border border-slate-200/80 dark:border-slate-800 md:block">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Paper</th>
                    <th>Room</th>
                    <th className="text-right">Countdown</th>
                  </tr>
                </thead>
                <tbody>
                  {e.papers.map((p) => (
                    <tr key={p.id} className={clsx(p.days_left !== null && p.days_left < 0 && 'opacity-60')}>
                      <td className="whitespace-nowrap">
                        <span className="font-semibold text-slate-900 dark:text-white">{formatDate(p.date)}</span>
                        <span className="block text-[11px] text-slate-400">{longDate(p.date).split(',')[0]}</span>
                      </td>
                      <td className="whitespace-nowrap tabular-nums">
                        {hhmm(p.start_time)} – {hhmm(p.end_time)}
                      </td>
                      <td>
                        <span className="font-semibold text-slate-900 dark:text-white">{p.name}</span>
                        <span className="block text-[11px] text-slate-400">
                          {p.code} · {labelize(p.type)}
                        </span>
                      </td>
                      <td className="whitespace-nowrap">{p.room ?? p.room_code ?? '—'}</td>
                      <td className="text-right">{p.days_left !== null && p.days_left >= 0 ? <span className={clsx('badge', p.days_left <= 7 ? 'badge-flame' : 'badge-slate')}>{daysLeftLabel(p.days_left)}</span> : <span className="text-xs text-slate-400">Done</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}

        {e.instructions && (
          <details className="group rounded-2xl bg-slate-50 px-4 py-3 text-[13px] text-slate-600 dark:bg-slate-800/40 dark:text-slate-300">
            <summary className="flex cursor-pointer list-none items-center gap-2 font-semibold text-slate-800 dark:text-slate-100">
              <ClipboardList className="h-4 w-4 text-flame-600 dark:text-flame-300" aria-hidden /> Instructions for candidates
            </summary>
            <p className="mt-2 whitespace-pre-line leading-relaxed">{e.instructions}</p>
          </details>
        )}
      </div>
    </section>
  );
}

export default function StudentExamsPage() {
  const { data, isLoading, error, refetch } = useStudent<StudentExams>(['exams'], 'exams');
  const np = data?.next_paper;
  return (
    <div>
      <PageHead area="student" title="Examinations" subtitle="Your date sheets, admit cards and seating." crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'Examinations' }]} />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="space-y-5">
          <div className="skeleton h-28 rounded-3xl" />
          <ListSkeleton rows={3} h="h-48" />
        </div>
      ) : (
        <div className="space-y-5">
          {np && (
            <section className="hero-navy relative isolate overflow-hidden rounded-3xl p-5 text-white shadow-soft sm:p-6">
              <div className="pattern-grid absolute inset-0 -z-10 opacity-50" aria-hidden />
              <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-4">
                  <div className="flex h-16 w-16 shrink-0 flex-col items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/20">
                    <span className="font-display text-2xl font-extrabold leading-none">{np.date.slice(8, 10)}</span>
                    <span className="text-[10px] font-semibold uppercase tracking-wider text-white/70">{new Date(`${np.date}T00:00:00`).toLocaleDateString('en-GB', { month: 'short' })}</span>
                  </div>
                  <div className="min-w-0">
                    <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-flame-300">Next paper · {daysLeftLabel(np.days_left ?? 0)}</p>
                    <p className="mt-1 font-display text-lg font-bold leading-tight sm:text-xl">{np.name}</p>
                    <p className="mt-1 flex flex-wrap gap-x-3 text-xs text-white/75">
                      <span>{np.code}</span>
                      <span>
                        {longDate(np.date).split(',')[0]}, {hhmm(np.start_time)} – {hhmm(np.end_time)}
                      </span>
                      {np.room && (
                        <span className="inline-flex items-center gap-1">
                          <MapPin className="h-3 w-3" aria-hidden /> {np.room}
                        </span>
                      )}
                    </p>
                  </div>
                </div>
                <p className="max-w-xs text-xs text-white/70">{np.exam}</p>
              </div>
            </section>
          )}

          {data.upcoming.length === 0 ? (
            <div className="card">
              <EmptyBlock icon={NotebookPen} title="No upcoming examinations" text="When the examination cell schedules papers for your semester, the date sheet and admit card will appear here." />
            </div>
          ) : (
            <Stagger className="space-y-5" step={80}>
              {data.upcoming.map((e) => (
                <ExamCard key={e.id} e={e} />
              ))}
            </Stagger>
          )}

          <Section title="Past examinations" subtitle="Completed this and earlier sessions" icon={History} bodyClassName="p-2 sm:p-3">
            {data.past.length === 0 ? (
              <EmptyBlock compact icon={History} title="No past examinations yet" />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {data.past.map((e) => (
                  <li key={e.id} className="flex flex-wrap items-center gap-3 px-2 py-3">
                    <DateTile date={e.start_date} tone="muted" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{e.name}</p>
                      <p className="text-xs text-slate-500">
                        {e.type} · {formatDate(e.start_date)} – {formatDate(e.end_date)}
                        {e.allocation?.hall_ticket_no ? ` · ${e.allocation.hall_ticket_no}` : ''}
                      </p>
                    </div>
                    {e.result_id ? (
                      <Link to={`${areaPath('student', 'results')}?result=${e.result_id}`} className="btn btn-soft btn-sm">
                        <BadgeCheck className="h-4 w-4" aria-hidden /> View result
                      </Link>
                    ) : (
                      <span className="badge badge-amber">
                        <CalendarClock className="h-3 w-3" aria-hidden /> Result awaited
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Section>

          <p className="flex items-start gap-2 px-1 text-xs text-slate-500 dark:text-slate-400">
            <FileBadge2 className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
            Carry the printed admit card and your college ID card to every paper. Seating and rooms can change — check the notice board on the day.
            <Link to={areaPath('student', 'notices')} className="link ml-1 inline-flex items-center gap-0.5 whitespace-nowrap">
              Notices <ArrowRight className="h-3 w-3" aria-hidden />
            </Link>
          </p>
        </div>
      )}
    </div>
  );
}
