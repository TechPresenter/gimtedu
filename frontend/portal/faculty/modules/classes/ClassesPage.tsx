import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { BookOpen, CalendarClock, ClipboardCheck, GraduationCap, Percent, Search, Star, Users, UsersRound } from 'lucide-react';
import { SearchInput, Stagger } from '@/components/ui';
import { labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead } from '../../../components/Ui';
import { usePortalQuery } from '../../../lib/hooks';
import type { ClassesData, FacultyClassRow } from '../../shared/types';
import { Bar, DAY_SHORT, MiniStat, PercentPill, fpath, relDay } from '../../shared/ui';

function ClassCard({ c, min }: { c: FacultyClassRow; min: number }) {
  const pct = c.attendance_percent;
  return (
    <article className="card group flex h-full flex-col p-4 transition hover:-translate-y-0.5 hover:shadow-soft sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="badge badge-navy">{c.subject_code}</span>
            <span className="badge badge-slate">{labelize(c.subject_type)}</span>
            {c.is_class_teacher && (
              <span className="badge badge-flame">
                <Star className="h-3 w-3" aria-hidden /> Class teacher
              </span>
            )}
          </div>
          <h2 className="mt-2 line-clamp-2 font-display text-[16px] font-bold leading-snug text-slate-900 dark:text-white">{c.subject}</h2>
          <p className="mt-0.5 text-[13px] font-medium text-slate-500 dark:text-slate-400">{c.label}</p>
        </div>
        <PercentPill value={pct} min={min} title="Average attendance this session" />
      </div>

      <dl className="mt-4 grid grid-cols-3 gap-2 text-center">
        {[
          ['Students', c.students],
          ['Periods / wk', c.periods_per_week],
          ['Classes held', c.held],
        ].map(([label, value]) => (
          <div key={label} className="rounded-xl bg-slate-50 px-2 py-2 dark:bg-slate-800/50">
            <dt className="text-[10.5px] font-semibold uppercase tracking-wide text-slate-400">{label}</dt>
            <dd className="font-display text-lg font-extrabold text-slate-900 dark:text-white">{value}</dd>
          </div>
        ))}
      </dl>

      <div className="mt-3">
        <div className="mb-1 flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400">
          <span>Class attendance</span>
          <span>{c.last_marked ? `Last marked ${relDay(c.last_marked)}` : 'No register yet'}</span>
        </div>
        <Bar value={pct ?? 0} tone={pct === null ? 'navy' : pct < min ? 'red' : pct < min + 10 ? 'amber' : 'green'} />
      </div>

      {c.days.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-1" aria-label="Days on the timetable">
          {[1, 2, 3, 4, 5, 6].map((d) => (
            <span key={d} className={clsx('rounded-md px-1.5 py-0.5 text-[10.5px] font-bold', c.days.includes(d) ? 'bg-brand-900 text-white dark:bg-brand-600' : 'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500')}>
              {DAY_SHORT[d]}
            </span>
          ))}
        </div>
      )}

      <div className="mt-auto flex gap-2 pt-4">
        <Link to={fpath(`classes/${c.section_id}/${c.subject_id}`)} className="btn btn-secondary btn-sm flex-1">
          <UsersRound className="h-4 w-4" aria-hidden /> Roster
        </Link>
        <Link to={`${fpath('attendance')}?section_id=${c.section_id}&subject_id=${c.subject_id}`} className="btn btn-navy btn-sm flex-1">
          <ClipboardCheck className="h-4 w-4" aria-hidden /> Attendance
        </Link>
      </div>
    </article>
  );
}

export default function ClassesPage() {
  const { data, isLoading, error, refetch } = usePortalQuery<ClassesData>('faculty', ['classes'], 'classes');
  const [q, setQ] = useState('');
  const list = useMemo(() => {
    const term = q.trim().toLowerCase();
    if (!data) return [];
    return term ? data.classes.filter((c) => `${c.subject} ${c.subject_code} ${c.label} ${c.program_name}`.toLowerCase().includes(term)) : data.classes;
  }, [data, q]);
  const s = data?.summary;

  return (
    <div>
      <PageHead area="faculty" title="My Classes" subtitle="Sections and subjects you teach this session, with rosters and attendance." crumbs={[{ label: 'Dashboard', to: fpath() }, { label: 'My Classes' }]} />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data || !s ? (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="skeleton h-[74px] rounded-2xl" />
            ))}
          </div>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            {[0, 1, 2].map((i) => (
              <div key={i} className="skeleton h-72 rounded-2xl" />
            ))}
          </div>
        </div>
      ) : data.classes.length === 0 ? (
        <div className="card">
          <EmptyBlock icon={BookOpen} title="No classes assigned yet" text="Subject allocations and timetable periods made by your department for this session appear here." />
        </div>
      ) : (
        <div className="space-y-5">
          <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" itemClassName="h-full">
            {[
              <MiniStat key="c" label="Classes" value={s.classes} icon={BookOpen} tone="navy" sub="section + subject" />,
              <MiniStat key="s" label="Students" value={s.students} icon={Users} tone="violet" sub="across your sections" />,
              <MiniStat key="p" label="Periods / week" value={s.periods_per_week} icon={CalendarClock} tone="flame" />,
              <MiniStat key="a" label="Avg. attendance" value={s.attendance_percent !== null ? `${s.attendance_percent}%` : '—'} icon={Percent} tone={s.attendance_percent !== null && s.attendance_percent < s.min_percent ? 'red' : 'green'} sub={`Minimum ${s.min_percent}%`} />,
            ]}
          </Stagger>

          {s.class_teacher_of.length > 0 && (
            <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-flame-200 bg-flame-50/60 px-4 py-3 text-sm dark:border-flame-500/25 dark:bg-flame-500/10">
              <GraduationCap className="h-5 w-5 text-flame-600 dark:text-flame-300" aria-hidden />
              <span className="font-semibold text-slate-800 dark:text-slate-100">Class teacher of</span>
              {s.class_teacher_of.map((ct) => (
                <span key={ct.section_id} className="badge badge-flame">
                  {ct.label} · {ct.students} students
                </span>
              ))}
            </div>
          )}

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <SearchInput value={q} onChange={setQ} placeholder="Search subject, code or section…" className="sm:w-80" />
            <p className="text-xs text-slate-500 dark:text-slate-400">
              Showing {list.length} of {data.classes.length} classes
            </p>
          </div>

          {list.length === 0 ? (
            <div className="card">
              <EmptyBlock
                icon={Search}
                title="No class matches your search"
                action={
                  <button type="button" className="btn btn-secondary btn-sm" onClick={() => setQ('')}>
                    Clear search
                  </button>
                }
              />
            </div>
          ) : (
            <Stagger className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" itemClassName="h-full">
              {list.map((c) => (
                <ClassCard key={c.key} c={c} min={s.min_percent} />
              ))}
            </Stagger>
          )}
        </div>
      )}
    </div>
  );
}
