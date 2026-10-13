import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { CalendarDays, CalendarRange, ChevronLeft, ChevronRight, Clock, DoorOpen, FilterX, LayoutList, ListOrdered, Printer, Rows3, Users } from 'lucide-react';
import {
  Alert, Badge, Button, Card, Combobox, DataTable, EmptyState, Input, Modal, PageHeader, Pagination, Reveal, SearchInput, Select, Skeleton, StatCard, Stagger, Tabs, type Column,
} from '@/components/ui';
import { useApi, useLookup } from '@/lib/queries';
import { useAcademicSession } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import { useDebounce } from '@/lib/hooks';
import { formatDate, formatNumber } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { DateTile, ExamStatusBadge, SEMESTER_OPTIONS, timeRange, todayIso } from './components/shared';
import type { DatesheetPayload, DatesheetRow } from './components/types';

const VIEWS = ['agenda', 'calendar', 'list'] as const;
type View = (typeof VIEWS)[number];
const FILTER_KEYS = ['exam_type_id', 'program_id', 'semester', 'exam_id', 'classroom_id', 'from', 'to'] as const;

function monthBounds(ym: string) {
  const [y, m] = ym.split('-').map(Number);
  const last = new Date(y, m, 0).getDate();
  return { from: `${ym}-01`, to: `${ym}-${String(last).padStart(2, '0')}`, y, m };
}
const typeTone = (code: string) => ({ ESE: 'bg-brand-50 text-brand-800 ring-brand-100 dark:bg-brand-500/15 dark:text-brand-200 dark:ring-brand-500/20', MID: 'bg-amber-50 text-amber-800 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20', PRAC: 'bg-violet-50 text-violet-800 ring-violet-100 dark:bg-violet-500/10 dark:text-violet-200 dark:ring-violet-500/20' }[code] ?? 'bg-slate-50 text-slate-700 ring-slate-100 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700');

export default function ExamSchedulePage() {
  const [params, setParams] = useSearchParams();
  const { id: currentSession, all: sessions } = useAcademicSession();
  const view: View = (VIEWS as readonly string[]).includes(params.get('view') ?? '') ? (params.get('view') as View) : 'agenda';
  const sessionId = Number(params.get('session') ?? currentSession ?? 0) || null;
  const [q, setQ] = useState(params.get('q') ?? '');
  const dq = useDebounce(q, 300);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const today = todayIso();
  const [month, setMonth] = useState(params.get('month') ?? today.slice(0, 7));
  const [day, setDay] = useState<string | null>(null);
  const showPast = params.get('past') === '1';

  const filters: Record<string, string> = {};
  FILTER_KEYS.forEach((k) => {
    const v = params.get(k);
    if (v) filters[k] = v;
  });
  const set = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)));
    setParams(next, { replace: true });
    setPage(1);
  };
  const base = { session_id: sessionId ?? undefined, ...filters, q: dq || undefined };
  const query =
    view === 'calendar'
      ? { ...base, from: monthBounds(month).from, to: monthBounds(month).to, with_summary: 1 }
      : view === 'list'
        ? { ...base, paginate: 1, page, per_page: perPage, with_summary: 1 }
        : { ...base, from: filters.from ?? (showPast ? undefined : today), with_summary: 1 };
  const ds = useApi<DatesheetPayload>(['exams', 'datesheet', query], 'exams/datesheet', query, { placeholderData: (p) => p });
  const rows = ds.data?.rows ?? [];
  const sum = ds.data?.summary;

  const programs = useLookup('programs');
  const types = useLookup('exam_types');
  const rooms = useLookup('classrooms');
  const activeCount = Object.keys(filters).length + (dq ? 1 : 0);
  // Print what is on screen: the month in calendar view, upcoming papers in the agenda (unless past papers are shown)
  const printQuery = {
    session_id: sessionId ?? undefined, ...filters, q: dq || undefined,
    ...(view === 'calendar' ? monthBounds(month) : view === 'agenda' && !filters.from && !showPast ? { from: today } : {}),
  } as Record<string, string | number | undefined>;
  delete printQuery.y;
  delete printQuery.m;

  const byDate = useMemo(() => {
    const m = new Map<string, DatesheetRow[]>();
    rows.forEach((r) => m.set(r.exam_date, [...(m.get(r.exam_date) ?? []), r]));
    return m;
  }, [rows]);

  return (
    <>
      <PageHeader
        title="Exam Schedule"
        description="Date sheet across every examination — papers by day with rooms, invigilators and candidates. Filter, switch views and print."
        breadcrumbs={[{ label: 'Examination', to: '/examination' }, { label: 'Exam Schedule' }]}
        actions={
          <>
            <Button variant="secondary" icon={ListOrdered} to="/examination">
              Examinations
            </Button>
            <Button icon={Printer} href={printUrl('exam-schedule.php', printQuery)} target="_blank">
              Print date sheet
            </Button>
          </>
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" step={70}>
        {[
          <StatCard key="t" label="Papers today" value={sum?.today ?? 0} icon={Clock} tone="cyan" loading={!sum} hint={formatDate(today)} />,
          <StatCard key="w" label="Next 7 days" value={sum?.this_week ?? 0} icon={CalendarDays} tone="blue" loading={!sum} hint={sum?.next_date ? `Next paper ${formatDate(sum.next_date)}` : 'No upcoming papers'} />,
          <StatCard key="u" label="Upcoming papers" value={sum?.upcoming ?? 0} icon={CalendarRange} tone="navy" loading={!sum} hint={`${formatNumber(sum?.papers ?? 0)} papers this session`} />,
          <StatCard key="r" label="Rooms in use" value={sum?.rooms ?? 0} icon={DoorOpen} tone={sum?.unassigned_rooms ? 'amber' : 'green'} loading={!sum} hint={sum?.unassigned_rooms ? `${sum.unassigned_rooms} papers without a room` : 'Every upcoming paper has a room'} />,
        ]}
      </Stagger>

      <Card className="mb-5 p-4">
        <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
          <Select inputSize="sm" aria-label="Session" value={String(sessionId ?? '')} onChange={(ev) => set({ session: ev.target.value, exam_id: null })} options={sessions.map((s) => ({ value: String(s.id), label: s.name }))} />
          <Select inputSize="sm" aria-label="Exam type" value={filters.exam_type_id ?? ''} onChange={(ev) => set({ exam_type_id: ev.target.value })} options={types.data ?? []} placeholder="All exam types" />
          <Select inputSize="sm" aria-label="Program" value={filters.program_id ?? ''} onChange={(ev) => set({ program_id: ev.target.value, exam_id: null })} options={programs.data ?? []} placeholder="All programs" />
          <Select inputSize="sm" aria-label="Semester" value={filters.semester ?? ''} onChange={(ev) => set({ semester: ev.target.value })} options={SEMESTER_OPTIONS} placeholder="All semesters" />
          <div className="col-span-2">
            <Combobox size="sm" source="exams" params={{ academic_session_id: sessionId ?? undefined, program_id: filters.program_id }} value={filters.exam_id ? Number(filters.exam_id) : null} onChange={(v) => set({ exam_id: v ? String(v) : null })} placeholder="All exams" clearable />
          </div>
          <Select inputSize="sm" aria-label="Room" value={filters.classroom_id ?? ''} onChange={(ev) => set({ classroom_id: ev.target.value })} options={rooms.data ?? []} placeholder="All rooms" />
          <SearchInput size="sm" value={q} onChange={(v) => { setQ(v); setPage(1); }} placeholder="Search subject…" />
        </div>
        <div className="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
          {view !== 'calendar' && (
            <div className="flex items-center gap-1.5" aria-label="Date range">
              <Input inputSize="sm" type="date" value={filters.from ?? ''} onChange={(ev) => set({ from: ev.target.value })} aria-label="From date" className="!w-40" />
              <span className="text-xs text-slate-400">to</span>
              <Input inputSize="sm" type="date" value={filters.to ?? ''} onChange={(ev) => set({ to: ev.target.value })} aria-label="To date" className="!w-40" />
            </div>
          )}
          {view === 'agenda' && !filters.from && (
            <label className="inline-flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
              <input type="checkbox" className="form-checkbox" checked={showPast} onChange={(ev) => set({ past: ev.target.checked ? '1' : null })} /> Include past papers
            </label>
          )}
          {activeCount > 0 && (
            <Button size="xs" variant="ghost" icon={FilterX} onClick={() => { setQ(''); set(Object.fromEntries(FILTER_KEYS.map((k) => [k, null]))); }}>
              Clear filters ({activeCount})
            </Button>
          )}
          <div className="sm:ml-auto">
            <Tabs
              variant="pills"
              value={view}
              onChange={(v) => set({ view: v === 'agenda' ? null : v })}
              tabs={[
                { key: 'agenda', label: 'Agenda', icon: Rows3 },
                { key: 'calendar', label: 'Calendar', icon: CalendarDays },
                { key: 'list', label: 'List', icon: LayoutList },
              ]}
            />
          </div>
        </div>
      </Card>

      {ds.error ? (
        <Alert variant="error" title="Unable to load the date sheet" action={<Button size="sm" variant="secondary" onClick={() => ds.refetch()}>Retry</Button>}>
          {(ds.error as ApiError).message}
        </Alert>
      ) : view === 'calendar' ? (
        <CalendarView month={month} setMonth={(m) => { setMonth(m); }} byDate={byDate} loading={!ds.data} onDay={setDay} today={today} />
      ) : view === 'list' ? (
        <Card className="overflow-hidden">
          <DataTable columns={listColumns(today)} rows={rows} loading={!ds.data} empty={<EmptyState icon={CalendarDays} title="No papers found" description="No papers match the filters. Schedule papers from an exam's Schedule tab." action={<Button to="/examination">Open examinations</Button>} />} />
          {ds.data && ds.data.total > 0 && <Pagination page={ds.data.page} pages={ds.data.pages} total={ds.data.total} perPage={ds.data.per_page} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} className="border-t border-slate-100 dark:border-slate-800" />}
        </Card>
      ) : !ds.data ? (
        <div className="space-y-4">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-40 w-full rounded-2xl" />)}</div>
      ) : !rows.length ? (
        <Card>
          <EmptyState icon={CalendarDays} title={showPast || filters.from ? 'No papers found' : 'No upcoming papers'} description={showPast || filters.from ? 'No papers match the selected filters.' : 'There are no papers scheduled from today. Include past papers or schedule papers from an exam.'} action={<Button to="/examination">Open examinations</Button>} />
        </Card>
      ) : (
        <div className="space-y-4">
          {[...byDate.entries()].slice(0, 60).map(([date, list], i) => (
            <Reveal key={date} delay={Math.min(i, 6) * 40}>
              <DayCard date={date} list={list} today={today} />
            </Reveal>
          ))}
          {byDate.size > 60 && <p className="text-center text-xs text-slate-500">Showing the first 60 days — narrow the filters or use the list view to see more.</p>}
        </div>
      )}

      <Modal open={!!day} onClose={() => setDay(null)} size="lg" title={day ? new Date(`${day}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) : ''} description={day ? `${byDate.get(day)?.length ?? 0} papers` : undefined}>
        {day && <PaperList list={byDate.get(day) ?? []} />}
      </Modal>
    </>
  );
}

function DayCard({ date, list, today }: { date: string; list: DatesheetRow[]; today: string }) {
  const d = new Date(`${date}T00:00:00`);
  return (
    <Card className="overflow-hidden">
      <div className={clsx('flex items-center gap-3 border-b px-5 py-3', date === today ? 'border-accent-100 bg-accent-50/60 dark:border-accent-500/20 dark:bg-accent-500/10' : 'border-slate-100 dark:border-slate-800')}>
        <DateTile date={date} tone={date === today ? 'green' : 'navy'} />
        <div>
          <p className="font-display font-semibold text-slate-900 dark:text-white">{d.toLocaleDateString('en-IN', { weekday: 'long' })}{date === today && <Badge color="green" className="ml-2">Today</Badge>}</p>
          <p className="text-xs text-slate-500">{d.toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' })} · {list.length} paper{list.length === 1 ? '' : 's'} · {formatNumber(list.reduce((a, r) => a + r.students_count, 0))} candidates</p>
        </div>
      </div>
      <PaperList list={list} />
    </Card>
  );
}

function PaperList({ list }: { list: DatesheetRow[] }) {
  return (
    <ul className="divide-y divide-slate-100 dark:divide-slate-800">
      {list.map((r) => (
        <li key={r.id} className="grid gap-2 px-5 py-3 sm:grid-cols-[9.5rem_minmax(0,1fr)_minmax(0,14rem)] sm:items-center">
          <div className="text-sm font-medium tabular-nums text-slate-700 dark:text-slate-200">{timeRange(r.start_time, r.end_time)}</div>
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
              <span className="font-mono text-xs font-normal text-slate-500">{r.subject_code}</span> {r.subject_name}
            </p>
            <p className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
              <span className={clsx('rounded-md px-1.5 py-0.5 text-[11px] font-semibold ring-1', typeTone(r.type_code))}>{r.type_code}</span>
              <Link to={`/examination/${r.exam_id}?tab=schedule`} className="hover:text-brand-700 hover:underline dark:hover:text-brand-300">
                {r.program_short ?? 'All programs'}{r.semester_no ? ` · Sem ${r.semester_no}` : ''}
              </Link>
              <span className="capitalize">· {r.subject_type}</span>
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600 dark:text-slate-300 sm:justify-end">
            <span className="inline-flex items-center gap-1"><DoorOpen className="h-3.5 w-3.5 text-slate-400" />{r.room_code ?? <span className="text-amber-600">No room</span>}</span>
            <span className="inline-flex items-center gap-1"><Users className="h-3.5 w-3.5 text-slate-400" />{r.students_count}</span>
            <span className="truncate text-slate-500" title="Chief invigilator">{r.chief_invigilator ?? 'Invigilator TBA'}</span>
          </div>
        </li>
      ))}
    </ul>
  );
}

function listColumns(today: string): Column<DatesheetRow>[] {
  return [
    { key: 'exam_date', header: 'Date & time', render: (r) => (
      <div className="leading-tight">
        <p className={clsx('whitespace-nowrap font-medium', r.exam_date === today && 'text-accent-700 dark:text-accent-400')}>
          {formatDate(r.exam_date)} <span className="text-xs font-normal text-slate-500">{new Date(`${r.exam_date}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'short' })}</span>
        </p>
        <p className="whitespace-nowrap text-xs text-slate-500">{timeRange(r.start_time, r.end_time)}</p>
      </div>
    ) },
    { key: 'subject', header: 'Paper', render: (r) => (
      <div className="min-w-[12rem] leading-tight">
        <p className="font-semibold text-slate-900 dark:text-white">{r.subject_name}</p>
        <p className="text-xs text-slate-500"><span className="font-mono">{r.subject_code}</span> · <span className="capitalize">{r.subject_type}</span></p>
      </div>
    ) },
    { key: 'exam', header: 'Exam', render: (r) => (
      <Link to={`/examination/${r.exam_id}?tab=schedule`} className="block min-w-[10rem] leading-tight hover:text-brand-700 dark:hover:text-brand-300">
        <p className="whitespace-nowrap font-medium">{r.program_short} · Sem {r.semester_no}</p>
        <p className="text-xs text-slate-500">{r.type_name}</p>
      </Link>
    ) },
    { key: 'room', header: 'Room', render: (r) => r.room_code ?? <span className="text-xs text-amber-600">Not assigned</span> },
    { key: 'invigilator', header: 'Chief invigilator', render: (r) => <span className="whitespace-nowrap">{r.chief_invigilator ?? '—'}</span> },
    { key: 'students', header: 'Students', align: 'center', render: (r) => formatNumber(r.students_count) },
    { key: 'status', header: 'Status', render: (r) => <ExamStatusBadge status={r.exam_status} /> },
  ];
}

function CalendarView({ month, setMonth, byDate, loading, onDay, today }: { month: string; setMonth: (m: string) => void; byDate: Map<string, DatesheetRow[]>; loading: boolean; onDay: (d: string) => void; today: string }) {
  const { y, m } = monthBounds(month);
  const first = new Date(y, m - 1, 1);
  const offset = (first.getDay() + 6) % 7; // Monday first
  const days = new Date(y, m, 0).getDate();
  const cells: (string | null)[] = [...Array.from({ length: offset }, () => null), ...Array.from({ length: days }, (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`)];
  while (cells.length % 7) cells.push(null);
  const shift = (n: number) => {
    const d = new Date(y, m - 1 + n, 1);
    setMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
  };
  return (
    <Card className="overflow-hidden">
      <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
        <Button size="sm" variant="ghost" icon={ChevronLeft} onClick={() => shift(-1)} aria-label="Previous month">
          <span className="hidden sm:inline">Previous</span>
        </Button>
        <div className="text-center">
          <p className="font-display text-base font-semibold text-slate-900 dark:text-white">{first.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })}</p>
          <button type="button" className="text-xs text-brand-700 hover:underline dark:text-brand-300" onClick={() => setMonth(today.slice(0, 7))}>
            Today
          </button>
        </div>
        <Button size="sm" variant="ghost" iconRight={ChevronRight} onClick={() => shift(1)} aria-label="Next month">
          <span className="hidden sm:inline">Next</span>
        </Button>
      </div>
      <div className="grid grid-cols-7 border-b border-slate-100 bg-slate-50 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50">
        {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => (
          <div key={d} className="py-2">{d}</div>
        ))}
      </div>
      <div className="grid grid-cols-7">
        {cells.map((c, i) => {
          const list = c ? byDate.get(c) ?? [] : [];
          const types = [...new Set(list.map((r) => r.type_code))];
          return (
            <button
              key={i}
              type="button"
              disabled={!c || !list.length}
              onClick={() => c && onDay(c)}
              className={clsx(
                'flex min-h-[5.5rem] flex-col items-start justify-start border-b border-r border-slate-100 p-1.5 text-left transition sm:min-h-[7rem] sm:p-2 dark:border-slate-800',
                !c && 'bg-slate-50/50 dark:bg-slate-900/40',
                c && list.length > 0 && 'hover:bg-brand-50/50 dark:hover:bg-brand-500/5',
                (i + 1) % 7 === 0 && 'border-r-0',
              )}
              aria-label={c ? `${formatDate(c)}: ${list.length} papers` : undefined}
            >
              {c && (
                <>
                  <span className={clsx('inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold', c === today ? 'bg-accent-600 text-white' : 'text-slate-700 dark:text-slate-200')}>{Number(c.slice(8))}</span>
                  {loading ? (
                    <Skeleton className="mt-1 h-4 w-full" />
                  ) : list.length > 0 ? (
                    <div className="mt-1 w-full min-w-0 space-y-1">
                      <p className="text-[11px] font-semibold text-slate-800 dark:text-slate-100">{list.length} paper{list.length === 1 ? '' : 's'}</p>
                      <div className="hidden flex-wrap gap-1 sm:flex">
                        {types.map((t) => (
                          <span key={t} className={clsx('rounded px-1 py-px text-[10px] font-semibold ring-1', typeTone(t))}>{t}</span>
                        ))}
                      </div>
                      <p className="hidden truncate text-[10px] text-slate-500 lg:block">{[...new Set(list.map((r) => r.program_short))].slice(0, 3).join(', ')}{new Set(list.map((r) => r.program_short)).size > 3 ? '…' : ''}</p>
                    </div>
                  ) : null}
                </>
              )}
            </button>
          );
        })}
      </div>
    </Card>
  );
}
