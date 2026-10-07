import { useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  BookOpen, CalendarCheck2, CalendarDays, CheckCircle2, Clock, Coffee, ExternalLink, GraduationCap, Layers, Printer, UserCheck, Users,
} from 'lucide-react';
import { CrudTable } from '@/components/crud';
import { Badge, Card, CardHeader, ProgressBar, Skeleton, Tabs, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { appUrl, printUrl } from '@/lib/config';
import { formatDate, formatTime } from '@/lib/format';
import { useCrudList, useInvalidateCrud } from '@/lib/queries';
import type { Row } from '@/lib/types';

/* ---------------------------------------------------------------- Sessions */

export function SessionsTab() {
  const { can, refresh } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidateCrud();
  const { data, isLoading } = useCrudList('academic_sessions', { per_page: 50, sort: 'start_date', dir: 'desc' });
  const current = data?.rows.find((r) => Number(r.is_current) === 1);

  const makeCurrent = async (row: Row) => {
    const ok = await confirm({
      title: `Make ${row.name} the current session?`,
      message: (
        <>
          Every module (admissions, fees, attendance, timetable) defaults to the current session. <strong>{String(current?.name ?? 'The present session')}</strong> will no longer be current.
        </>
      ),
      confirmText: 'Set as current',
    });
    if (!ok) return;
    try {
      const res = await api.post(`academics/sessions/${row.id}/current`);
      toast.success(res.message);
      await Promise.all([invalidate('academic_sessions'), refresh()]);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <div className="space-y-5">
      {isLoading ? (
        <Skeleton className="h-28 w-full rounded-2xl" />
      ) : current ? (
        <SessionBanner row={current} />
      ) : (
        <Card className="p-5 text-sm text-amber-700 dark:text-amber-300">No session is marked as current. Use “Set as current” on a session below.</Card>
      )}
      <CrudTable
        module="academic_sessions"
        urlState
        addLabel="Add Session"
        emptyText="Create the first academic session (e.g. 2026-27) to start admissions, timetables and fees."
        rowMenu={(row) => [
          can('academics', 'edit') && Number(row.is_current) !== 1 && { label: 'Set as current session', icon: CheckCircle2, onClick: () => makeCurrent(row) },
          { label: 'Sections of this session', icon: Users, to: `/academics?tab=sections&f.academic_session_id=${row.id}` },
          can('timetable') && { label: 'Timetable', icon: CalendarDays, to: `/timetable?session=${row.id}` },
        ]}
      />
    </div>
  );
}

function SessionBanner({ row }: { row: Row }) {
  const progress = Number(row.progress ?? 0);
  return (
    <section className="relative overflow-hidden rounded-2xl bg-brand-900 px-5 py-5 text-white shadow-soft sm:px-6">
      <div className="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-white/[.04]" aria-hidden />
      <div className="pointer-events-none absolute -bottom-24 right-24 h-48 w-48 rounded-full bg-accent-500/10" aria-hidden />
      <div className="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-accent-300">Current academic session</p>
          <h2 className="mt-1 font-display text-2xl font-bold">{String(row.name)}</h2>
          <p className="mt-1 text-sm text-brand-100">
            {formatDate(row.start_date)} – {formatDate(row.end_date)}
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-1 text-xs font-medium">
              <CalendarCheck2 className="h-3.5 w-3.5" /> {Number(row.sections_count)} sections
            </span>
            <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium', Number(row.admissions_open) ? 'bg-accent-500/20 text-accent-200' : 'bg-white/10 text-brand-100')}>
              <GraduationCap className="h-3.5 w-3.5" /> Admissions {Number(row.admissions_open) ? 'open' : 'closed'}
            </span>
          </div>
        </div>
        <div className="w-full sm:w-72">
          <div className="flex items-baseline justify-between text-xs text-brand-100">
            <span>Session progress</span>
            <span className="font-display text-lg font-bold text-white">{progress.toFixed(0)}%</span>
          </div>
          <div className="mt-2 h-2 overflow-hidden rounded-full bg-white/15" role="progressbar" aria-valuenow={Math.round(progress)} aria-valuemin={0} aria-valuemax={100} aria-label="Session progress">
            <div className="h-full rounded-full bg-accent-400 transition-[width] duration-700 motion-reduce:transition-none" style={{ width: `${progress}%` }} />
          </div>
        </div>
      </div>
    </section>
  );
}

/* ---------------------------------------------------------------- Departments / programs / courses / semesters / subjects */

export function DepartmentsTab() {
  const { can } = useAuth();
  return (
    <CrudTable
      module="departments"
      urlState
      addLabel="Add Department"
      emptyText="Add departments such as Management Studies or Computer Science to organise programs and faculty."
      rowMenu={(row) => [
        { label: 'Programs', icon: GraduationCap, to: `/academics?tab=programs&f.department_id=${row.id}` },
        can('faculty') && { label: 'Faculty members', icon: UserCheck, to: `/faculty?f.department_id=${row.id}` },
      ]}
    />
  );
}

export function ProgramsTab() {
  return (
    <CrudTable
      module="programs"
      urlState
      addLabel="Add Program"
      emptyText="Add programs like BBA, MBA or B.Tech. Semesters are created automatically and published programs appear on the website."
      rowMenu={(row) => [
        { label: 'Subjects', icon: BookOpen, to: `/academics?tab=subjects&f.program_id=${row.id}` },
        { label: 'Sections', icon: Users, to: `/academics?tab=sections&f.program_id=${row.id}` },
        { label: 'Specializations', icon: Layers, to: `/academics?tab=courses&f.program_id=${row.id}` },
        row.slug && Number(row.show_on_website) === 1 && { label: 'View on website', icon: ExternalLink, href: appUrl(`programs/${row.slug}`), target: '_blank' },
      ]}
    />
  );
}

export function CoursesTab() {
  return (
    <CrudTable
      module="courses"
      urlState
      addLabel="Add Specialization"
      emptyText="Add specializations such as MBA - Finance or B.Tech CSE - Cloud Computing."
      rowMenu={(row) => [{ label: 'Program subjects', icon: BookOpen, to: `/academics?tab=subjects&f.program_id=${row.program_id}` }]}
    />
  );
}

export function SemestersTab() {
  return (
    <CrudTable
      module="semesters"
      urlState
      addLabel="Add Semester"
      emptyText="Semesters are created automatically for each program. You can add dates and link them to a session here."
      rowMenu={(row) => [
        { label: 'Subjects', icon: BookOpen, to: `/academics?tab=subjects&f.program_id=${row.program_id}&f.semester_no=${row.number}` },
        { label: 'Sections', icon: Users, to: `/academics?tab=sections&f.program_id=${row.program_id}&f.semester_no=${row.number}` },
      ]}
    />
  );
}

export function SubjectsTab() {
  return (
    <CrudTable
      module="subjects"
      urlState
      addLabel="Add Subject"
      emptyText="Add the subjects (papers) of each semester with credits and the marks scheme."
      rowMenu={(row) => [
        { label: 'Faculty assignments', icon: UserCheck, to: `/academics?tab=assignments&f.program_id=${row.program_id}&f.semester_no=${row.semester_no}` },
        { label: 'Sections of this semester', icon: Users, to: `/academics?tab=sections&f.program_id=${row.program_id}&f.semester_no=${row.semester_no}` },
      ]}
    />
  );
}

/* ---------------------------------------------------------------- Sections & batches */

function useSubTab(options: string[]) {
  const [params, setParams] = useSearchParams();
  const sub = options.includes(params.get('sub') ?? '') ? (params.get('sub') as string) : options[0];
  const setSub = (k: string) => {
    const next = new URLSearchParams();
    next.set('tab', params.get('tab') ?? '');
    if (k !== options[0]) next.set('sub', k);
    setParams(next);
  };
  return [sub, setSub] as const;
}

export function SectionsTab() {
  const { can } = useAuth();
  const [sub, setSub] = useSubTab(['sections', 'batches']);
  return (
    <div className="space-y-4">
      <Tabs variant="pills" tabs={[{ key: 'sections', label: 'Sections', icon: Users }, { key: 'batches', label: 'Batches', icon: GraduationCap }]} value={sub} onChange={setSub} />
      {sub === 'sections' ? (
        <CrudTable
          key="sections"
          module="sections"
          urlState
          addLabel="Add Section"
          emptyText="Create sections (A, B …) for each program semester of the session to assign students, faculty and timetables."
          rowMenu={(row) => [
            can('timetable') && { label: 'Open timetable', icon: CalendarDays, to: `/timetable?section=${row.id}${row.academic_session_id ? `&session=${row.academic_session_id}` : ''}` },
            can('timetable') && { label: 'Print timetable', icon: Printer, href: printUrl('timetable.php', { view: 'class', id: row.id, session_id: row.academic_session_id ?? undefined }), target: '_blank' },
            { label: 'Faculty assignments', icon: UserCheck, to: `/academics?tab=assignments&f.program_id=${row.program_id}&f.section_id=${row.id}` },
            can('students') && { label: 'Students', icon: GraduationCap, to: `/students?f.section_id=${row.id}` },
          ]}
        />
      ) : (
        <CrudTable
          key="batches"
          module="batches"
          urlState
          addLabel="Add Batch"
          emptyText="Batches group students by admission year, e.g. BBA 2026-2029."
          rowMenu={(row) => [can('students') && { label: 'Students', icon: GraduationCap, to: `/students?f.batch_id=${row.id}` }]}
        />
      )}
    </div>
  );
}

/* ---------------------------------------------------------------- Rooms & time slots */

export function RoomsTab() {
  const { can } = useAuth();
  const [sub, setSub] = useSubTab(['classrooms', 'slots']);
  return (
    <div className="space-y-4">
      <Tabs variant="pills" tabs={[{ key: 'classrooms', label: 'Classrooms & Labs', icon: Layers }, { key: 'slots', label: 'Time Slots', icon: Clock }]} value={sub} onChange={setSub} />
      {sub === 'classrooms' ? (
        <CrudTable
          key="classrooms"
          module="classrooms"
          urlState
          addLabel="Add Room"
          emptyText="Add classrooms, labs and halls with their capacity so the timetable can allocate them."
          rowMenu={(row) => [
            can('timetable') && { label: 'Room timetable', icon: CalendarDays, to: `/timetable?view=room&room=${row.id}` },
            can('timetable') && { label: 'Print room timetable', icon: Printer, href: printUrl('timetable.php', { view: 'room', id: row.id }), target: '_blank' },
          ]}
          renderers={{
            utilisation: (row) => (
              <div className="ml-auto flex w-28 items-center gap-2">
                <ProgressBar value={Number(row.utilisation)} tone={Number(row.utilisation) > 85 ? 'orange' : Number(row.utilisation) > 40 ? 'green' : 'blue'} label={`${row.code} utilisation`} />
                <span className="w-10 text-right text-xs tabular-nums text-slate-600 dark:text-slate-300">{Number(row.utilisation).toFixed(0)}%</span>
              </div>
            ),
          }}
        />
      ) : (
        <>
          <DayTimeline />
          <CrudTable key="slots" module="time_slots" urlState addLabel="Add Time Slot" emptyText="Define the periods and breaks of the teaching day (e.g. Period 1, 09:00 – 09:55)." />
        </>
      )}
    </div>
  );
}

/** Proportional bar of the teaching day built from the active time slots. */
function DayTimeline() {
  const { data, isLoading } = useCrudList('time_slots', { per_page: 50, f: { status: 'active' } });
  const slots = useMemo(() => [...(data?.rows ?? [])].sort((a, b) => String(a.start_time).localeCompare(String(b.start_time))), [data]);
  const toMin = (t: unknown) => {
    const [h, m] = String(t).split(':').map(Number);
    return h * 60 + (m || 0);
  };
  const start = slots.length ? toMin(slots[0].start_time) : 0;
  const end = slots.length ? toMin(slots[slots.length - 1].end_time) : 0;
  const total = Math.max(1, end - start);
  const teaching = slots.filter((s) => !Number(s.is_break));
  return (
    <Card>
      <CardHeader
        title="Teaching day"
        icon={Clock}
        subtitle={slots.length ? `${formatTime(slots[0].start_time)} – ${formatTime(slots[slots.length - 1].end_time)} · ${teaching.length} periods · Saturday follows the same bell schedule` : 'No active time slots yet'}
      />
      <div className="p-5">
        {isLoading ? (
          <Skeleton className="h-14 w-full" />
        ) : slots.length === 0 ? (
          <p className="text-sm text-slate-500">Add time slots below to build the daily schedule.</p>
        ) : (
          <div className="flex h-16 w-full overflow-hidden rounded-xl ring-1 ring-slate-200 dark:ring-slate-800" role="list" aria-label="Daily time slots">
            {slots.map((s) => {
              const width = ((toMin(s.end_time) - toMin(s.start_time)) / total) * 100;
              const brk = Number(s.is_break) === 1;
              return (
                <div
                  key={s.id}
                  role="listitem"
                  title={`${s.name}: ${formatTime(s.start_time)} – ${formatTime(s.end_time)}`}
                  style={{ width: `${width}%` }}
                  className={clsx(
                    'flex min-w-0 flex-col justify-center border-r border-white px-1.5 text-center last:border-r-0 dark:border-slate-900',
                    brk ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-100',
                  )}
                >
                  {brk ? <Coffee className="mx-auto h-3.5 w-3.5" aria-label={String(s.name)} /> : <span className="truncate text-[11px] font-semibold">{String(s.name).replace('Period ', 'P')}</span>}
                  <span className="hidden truncate text-[10px] opacity-80 md:block">{formatTime(s.start_time)}</span>
                </div>
              );
            })}
          </div>
        )}
        {slots.length > 0 && (
          <div className="mt-3 flex flex-wrap gap-3 text-xs text-slate-500 dark:text-slate-400">
            <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-brand-100 dark:bg-brand-500/40" /> Teaching period</span>
            <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-amber-100 dark:bg-amber-500/30" /> Break</span>
            <Badge color="slate">{Math.round(total / 6) / 10} hours on campus</Badge>
          </div>
        )}
      </div>
    </Card>
  );
}
