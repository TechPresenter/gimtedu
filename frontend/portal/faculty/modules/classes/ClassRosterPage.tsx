import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import clsx from 'clsx';
import { AlertTriangle, ArrowDownWideNarrow, CalendarClock, ClipboardCheck, ClipboardList, Download, GraduationCap, Lock, Mail, MapPin, NotebookPen, Percent, Phone, Search, Users } from 'lucide-react';
import { Alert, SearchInput, Select, Stagger } from '@/components/ui';
import { formatDate, labelize } from '@/lib/format';
import { EmptyBlock, PageHead, PortalAvatar, Section } from '../../../components/Ui';
import { ScopedError } from '../../shared/ScopedError';
import { usePortalQuery } from '../../../lib/hooks';
import { hhmm } from '../../../lib/time';
import type { RosterData, RosterStudent } from '../../shared/types';
import { DAY_NAMES, MiniStat, PercentPill, PillTabs, TrailDots, fpath, todayIso } from '../../shared/ui';

type SortKey = 'roll' | 'name' | 'low' | 'high';

function csvCell(v: unknown): string {
  const s = v === null || v === undefined ? '' : String(v);
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function downloadCsv(data: RosterData) {
  const head = ['Roll No', 'Student ID', 'Name', `${data.class.subject_code} attendance %`, `${data.class.subject_code} classes`, 'Overall attendance %'];
  const lines = [head, ...data.students.map((s) => [s.roll_no ?? '', s.student_uid, s.name, s.subject_percent ?? '', s.subject_held, s.percent ?? ''])].map((r) => r.map(csvCell).join(','));
  const blob = new Blob([`﻿${lines.join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${data.class.subject_code}-${data.class.program}-sem${data.class.semester_no}-${data.class.section}-roster.csv`.replace(/[^\w.-]+/g, '-');
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function StudentRowCard({ s, min, dates }: { s: RosterStudent; min: number; dates: RosterData['trail_dates'] }) {
  return (
    <li className={clsx('flex items-start gap-3 px-4 py-3', s.below && 'bg-red-50/40 dark:bg-red-500/5')}>
      <PortalAvatar name={s.name} src={s.photo} />
      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{s.name}</p>
            <p className="truncate text-xs text-slate-500 dark:text-slate-400">
              {s.roll_no ?? '—'} · {s.student_uid}
            </p>
          </div>
          <PercentPill value={s.subject_percent} min={min} title="Attendance in this subject" />
        </div>
        <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
          <TrailDots trail={s.trail} dates={dates} />
          <span className="text-[11px] text-slate-500 dark:text-slate-400">Overall {s.percent !== null ? `${s.percent}%` : '—'}</span>
        </div>
      </div>
    </li>
  );
}

export default function ClassRosterPage() {
  const { sectionId, subjectId } = useParams();
  const sec = Number(sectionId) || 0;
  const sub = Number(subjectId) || 0;
  const { data, isLoading, error, refetch } = usePortalQuery<RosterData>('faculty', ['classes', 'roster', sec, sub], `classes/${sec}/${sub}`, undefined, { enabled: sec > 0 && sub > 0 });
  const [q, setQ] = useState('');
  const [filter, setFilter] = useState<'all' | 'below'>('all');
  const [sort, setSort] = useState<SortKey>('roll');

  const rows = useMemo(() => {
    if (!data) return [];
    const term = q.trim().toLowerCase();
    let list = data.students.filter((s) => (filter === 'below' ? s.below : true) && (!term || `${s.name} ${s.student_uid} ${s.roll_no ?? ''}`.toLowerCase().includes(term)));
    const pct = (s: RosterStudent) => s.subject_percent ?? -1;
    list = [...list].sort((a, b) =>
      sort === 'name' ? a.name.localeCompare(b.name) : sort === 'low' ? pct(a) - pct(b) : sort === 'high' ? pct(b) - pct(a) : (a.roll_no ?? '~').localeCompare(b.roll_no ?? '~', undefined, { numeric: true }),
    );
    return list;
  }, [data, q, filter, sort]);

  const c = data?.class;
  const title = c ? c.subject : 'Class roster';

  return (
    <div>
      <PageHead
        area="faculty"
        title={title}
        subtitle={c ? `${c.subject_code} · ${c.label}${c.class_teacher ? ` · Class teacher: ${c.class_teacher}` : ''}` : undefined}
        crumbs={[{ label: 'Dashboard', to: fpath() }, { label: 'My Classes', to: fpath('classes') }, { label: c ? `${c.subject_code} · ${c.program} ${c.semester_no}-${c.section}` : 'Roster' }]}
        actions={
          data && c ? (
            <>
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => downloadCsv(data)}>
                <Download className="h-4 w-4" aria-hidden /> CSV
              </button>
              <Link to={`${fpath('attendance')}?date=${todayIso()}&section_id=${c.section_id}&subject_id=${c.subject_id}`} className="btn btn-primary btn-sm">
                <ClipboardCheck className="h-4 w-4" aria-hidden /> Mark attendance
              </Link>
            </>
          ) : undefined
        }
      />

      {error ? (
        <ScopedError error={error} onRetry={() => void refetch()} title="We couldn't open this class" backTo={fpath('classes')} backLabel="Back to My Classes" />
      ) : isLoading || !data || !c ? (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="skeleton h-[74px] rounded-2xl" />
            ))}
          </div>
          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div className="skeleton h-[520px] rounded-2xl lg:col-span-2" />
            <div className="skeleton h-80 rounded-2xl" />
          </div>
        </div>
      ) : (
        <div className="space-y-5">
          <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" itemClassName="h-full">
            {[
              <MiniStat key="s" label="Students" value={data.stats.students} icon={Users} tone="navy" sub={c.is_class_teacher ? 'You are the class teacher' : 'active in the section'} />,
              <MiniStat key="h" label="Classes held" value={data.stats.held} icon={CalendarClock} tone="flame" sub={data.stats.last_marked ? `Last ${formatDate(data.stats.last_marked)}` : 'No register yet'} />,
              <MiniStat key="a" label="Average attendance" value={data.stats.average !== null ? `${data.stats.average}%` : '—'} icon={Percent} tone={data.stats.average !== null && data.stats.average < data.stats.min_percent ? 'red' : 'green'} sub={`in ${c.subject_code}`} />,
              <MiniStat key="b" label={`Below ${data.stats.min_percent}%`} value={data.stats.below} icon={AlertTriangle} tone={data.stats.below ? 'red' : 'green'} sub={data.stats.below ? 'need follow-up' : 'everyone on track'} />,
            ]}
          </Stagger>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <Section
              title="Roster"
              subtitle={`${rows.length} of ${data.students.length} students · contact details are masked`}
              icon={Users}
              className="lg:col-span-2"
              bodyClassName="p-0"
            >
              <div className="flex flex-col gap-2 border-b border-slate-100 p-3 md:flex-row md:flex-wrap md:items-center dark:border-slate-800">
                <PillTabs label="Filter students" value={filter} onChange={setFilter} tabs={[{ value: 'all', label: 'All', count: data.students.length }, { value: 'below', label: `Below ${data.stats.min_percent}%`, count: data.stats.below }]} />
                <SearchInput value={q} onChange={setQ} placeholder="Search name, roll no or ID…" className="md:min-w-[12rem] md:flex-1" size="sm" />
                <label className="flex items-center gap-2 text-xs text-slate-500">
                  <ArrowDownWideNarrow className="h-4 w-4" aria-hidden />
                  <span className="sr-only">Sort by</span>
                  <Select
                    inputSize="sm"
                    value={sort}
                    onChange={(e) => setSort(e.target.value as SortKey)}
                    options={[
                      { value: 'roll', label: 'Roll number' },
                      { value: 'name', label: 'Name' },
                      { value: 'low', label: 'Attendance: lowest first' },
                      { value: 'high', label: 'Attendance: highest first' },
                    ]}
                    className="w-auto"
                    aria-label="Sort students"
                  />
                </label>
              </div>
              {rows.length === 0 ? (
                <EmptyBlock compact icon={Search} title={data.students.length ? 'No student matches' : 'No students in this section yet'} text={data.students.length ? 'Try another name or clear the filter.' : undefined} />
              ) : (
                <>
                  <div className="table-wrap hidden md:block">
                    <table className="data-table">
                      <thead>
                        <tr>
                          <th>Roll no</th>
                          <th>Student</th>
                          <th className="text-right">{c.subject_code}</th>
                          <th className="text-right">Overall</th>
                          <th>
                            Last {data.trail_dates.length} classes
                          </th>
                          <th className="hidden xl:table-cell">Contact</th>
                        </tr>
                      </thead>
                      <tbody>
                        {rows.map((s) => (
                          <tr key={s.id} className={clsx(s.below && 'bg-red-50/40 dark:bg-red-500/5')}>
                            <td className="whitespace-nowrap font-mono text-xs">{s.roll_no ?? '—'}</td>
                            <td>
                              <div className="flex items-center gap-2.5">
                                <PortalAvatar name={s.name} src={s.photo} size="sm" />
                                <div className="min-w-0">
                                  <p className="truncate font-semibold text-slate-900 dark:text-white">{s.name}</p>
                                  <p className="text-xs text-slate-500 dark:text-slate-400">{s.student_uid}</p>
                                </div>
                              </div>
                            </td>
                            <td className="text-right">
                              <PercentPill value={s.subject_percent} min={data.stats.min_percent} />
                              <p className="mt-0.5 text-[10.5px] text-slate-400">{s.subject_held} classes</p>
                            </td>
                            <td className="text-right">
                              <PercentPill value={s.percent} min={data.stats.min_percent} />
                            </td>
                            <td>
                              <TrailDots trail={s.trail} dates={data.trail_dates} />
                            </td>
                            <td className="hidden max-w-[190px] text-xs text-slate-500 xl:table-cell dark:text-slate-400">
                              {s.email && (
                                <p className="flex items-center gap-1 truncate">
                                  <Mail className="h-3 w-3 shrink-0" aria-hidden /> {s.email}
                                </p>
                              )}
                              {s.mobile && (
                                <p className="flex items-center gap-1">
                                  <Phone className="h-3 w-3 shrink-0" aria-hidden /> {s.mobile}
                                </p>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <ul className="divide-y divide-slate-100 md:hidden dark:divide-slate-800">
                    {rows.map((s) => (
                      <StudentRowCard key={s.id} s={s} min={data.stats.min_percent} dates={data.trail_dates} />
                    ))}
                  </ul>
                </>
              )}
            </Section>

            <div className="space-y-5">
              <Section title="Weekly schedule" icon={CalendarClock} bodyClassName="p-3 sm:p-4">
                {data.schedule.length === 0 ? (
                  <EmptyBlock compact icon={CalendarClock} title="Not on your timetable" text="This class has no published periods for you this session." />
                ) : (
                  <ul className="space-y-2">
                    {data.schedule.map((p, i) => (
                      <li key={i} className="flex items-center gap-3 rounded-xl border border-slate-200/80 px-3 py-2.5 dark:border-slate-800">
                        <span className="inline-flex w-10 shrink-0 justify-center rounded-lg bg-brand-900 py-1 text-[11px] font-bold uppercase text-white dark:bg-brand-700">{DAY_NAMES[p.day_of_week].slice(0, 3)}</span>
                        <div className="min-w-0 flex-1 text-sm">
                          <p className="font-semibold text-slate-800 dark:text-slate-100">
                            {hhmm(p.start_time)} – {hhmm(p.end_time)}
                          </p>
                          <p className="text-xs text-slate-500 dark:text-slate-400">
                            {p.slot} · {labelize(p.type)}
                          </p>
                        </div>
                        {p.room_code && (
                          <span className="inline-flex items-center gap-1 text-xs text-slate-500">
                            <MapPin className="h-3.5 w-3.5" aria-hidden /> {p.room_code}
                          </span>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
              </Section>

              <Section title="Recent registers" icon={ClipboardList} bodyClassName="p-2 sm:p-3" action={<Link to={`${fpath('attendance')}?tab=history&section_id=${c.section_id}&subject_id=${c.subject_id}`} className="link text-[13px]">History</Link>}>
                {data.registers.length === 0 ? (
                  <EmptyBlock compact icon={ClipboardList} title="No registers yet" text="Mark the first attendance for this class from the Attendance screen." />
                ) : (
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {data.registers.map((r) => (
                      <li key={r.id}>
                        <Link
                          to={`${fpath('attendance')}?date=${r.date}&section_id=${c.section_id}&subject_id=${c.subject_id}${r.time_slot_id ? `&slot=${r.time_slot_id}` : ''}`}
                          className="flex items-center gap-3 rounded-xl px-2 py-2.5 transition hover:bg-slate-50 dark:hover:bg-slate-800/50"
                        >
                          <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">{formatDate(r.date)}</p>
                            <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                              {r.slot ?? 'Extra class'}
                              {r.start_time ? ` · ${hhmm(r.start_time)}` : ''}
                              {r.remarks ? ` · ${r.remarks}` : ''}
                            </p>
                          </div>
                          {r.is_locked && <Lock className="h-3.5 w-3.5 text-slate-400" aria-label="Locked" />}
                          <span className="text-xs font-semibold tabular-nums text-slate-600 dark:text-slate-300">
                            {r.counts.present + r.counts.late}/{r.counts.total}
                          </span>
                          <PercentPill value={r.counts.percent} min={data.stats.min_percent} />
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </Section>

              {data.next_exam ? (
                <Alert variant="info" title="Next examination">
                  {data.next_exam.exam} — {formatDate(data.next_exam.exam_date)}, {hhmm(data.next_exam.start_time)}
                  <Link to={fpath('marks')} className="mt-1 flex items-center gap-1 font-semibold underline-offset-2 hover:underline">
                    <NotebookPen className="h-3.5 w-3.5" aria-hidden /> Marks entry
                  </Link>
                </Alert>
              ) : (
                <div className="flex items-center gap-2 rounded-2xl border border-dashed border-slate-300 p-4 text-[13px] text-slate-500 dark:border-slate-700 dark:text-slate-400">
                  <GraduationCap className="h-4 w-4 shrink-0" aria-hidden /> No upcoming examination is scheduled for this subject.
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
