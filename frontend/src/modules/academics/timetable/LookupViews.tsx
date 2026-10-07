import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { CalendarDays, DoorOpen, GraduationCap, Printer, School, Search, UserRound } from 'lucide-react';
import { Alert, Avatar, Badge, Button, Card, Combobox, EmptyState, Skeleton } from '@/components/ui';
import type { ApiError } from '@/lib/api';
import { printUrl } from '@/lib/config';
import { labelize } from '@/lib/format';
import { useApi, useLookup } from '@/lib/queries';
import type { TtDay, TtGrid, TtSlot } from '../types';
import { WeekGrid } from './WeekGrid';

interface LookupViewProps {
  sessionId: number;
  id: number | null;
  days: TtDay[];
  slots: TtSlot[];
  onSelect: (id: string | null) => void;
}

function useGrid(view: 'faculty' | 'room' | 'student', id: number | null, sessionId: number) {
  return useApi<TtGrid>(['tt', 'grid', view, id, sessionId], 'timetable/grid', { view, id: id ?? undefined, session_id: sessionId }, { enabled: !!id });
}

function Stat({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
      <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</p>
      <p className="font-display text-lg font-bold text-slate-900 dark:text-white">{value}</p>
    </div>
  );
}

function Shell({ picker, header, grid, sessionId, view, empty }: { picker: ReactNode; header: ReactNode; grid: ReturnType<typeof useGrid>; sessionId: number; view: 'faculty' | 'room' | 'student'; empty: ReactNode }) {
  const navigate = useNavigate();
  const data = grid.data;
  return (
    <div className="space-y-5">
      <Card className="p-4">{picker}</Card>
      {!data && !grid.isLoading && !grid.error ? (
        <Card>{empty}</Card>
      ) : grid.error ? (
        <Alert variant="error" title="Unable to load the timetable">{(grid.error as ApiError).message}</Alert>
      ) : (
        <Card className="overflow-hidden">
          <div className="border-b border-slate-100 px-5 py-4 dark:border-slate-800">{grid.isLoading || !data ? <Skeleton className="h-12 w-80 max-w-full" /> : header}</div>
          {data && view === 'student' && !data.context.section ? (
            <EmptyState icon={School} title="Not assigned to a section" description="This student has no section for the current session, so there is no timetable to show." />
          ) : (
            <WeekGrid
              days={data?.days ?? []}
              slots={data?.slots ?? []}
              entries={data?.entries ?? []}
              mode={view === 'student' ? 'class' : view}
              loading={grid.isLoading}
              onOpen={(e) => navigate(`/timetable?section=${e.section_id}&session=${sessionId}`)}
            />
          )}
        </Card>
      )}
    </div>
  );
}

/* ---------------------------------------------------------------- Faculty */

export function FacultyView({ sessionId, id, onSelect }: LookupViewProps) {
  const grid = useGrid('faculty', id, sessionId);
  const f = grid.data?.context.faculty;
  const stats = grid.data?.stats;
  return (
    <Shell
      view="faculty"
      sessionId={sessionId}
      grid={grid}
      picker={
        <div className="max-w-md">
          <label htmlFor="tt-faculty-pick" className="form-label">Faculty member</label>
          <Combobox id="tt-faculty-pick" source="faculty" value={id} onChange={(v) => onSelect(v ? String(v) : null)} placeholder="Search faculty by name or employee ID…" initialOptions={f ? [{ value: f.id, label: f.name }] : []} />
        </div>
      }
      empty={<EmptyState icon={UserRound} title="Choose a faculty member" description="See the weekly teaching schedule of any faculty member across all classes." />}
      header={
        f && (
          <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
            <div className="flex min-w-0 flex-1 items-center gap-3">
              <Avatar name={f.name} src={f.photo} size="lg" />
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <h2 className="font-display text-lg font-bold text-slate-900 dark:text-white">{f.name}</h2>
                  {f.status !== 'active' && <Badge color="amber">{labelize(f.status)}</Badge>}
                </div>
                <p className="truncate text-sm text-slate-500 dark:text-slate-400">
                  {f.designation} · {f.department ?? '—'} · {f.employee_id}
                </p>
              </div>
            </div>
            <div className="grid grid-cols-3 gap-2 sm:w-[22rem]">
              <Stat label="Periods / wk" value={stats?.periods ?? 0} />
              <Stat label="Subjects" value={stats?.subjects ?? 0} />
              <Stat label="Assignments" value={f.assignments} />
            </div>
            <Button size="sm" variant="secondary" icon={Printer} href={printUrl('timetable.php', { view: 'faculty', id: f.id, session_id: sessionId })} target="_blank">
              Print
            </Button>
          </div>
        )
      }
    />
  );
}

/* ---------------------------------------------------------------- Room */

export function RoomView({ sessionId, id, onSelect }: LookupViewProps) {
  const grid = useGrid('room', id, sessionId);
  const { data: rooms = [], isLoading } = useLookup('classrooms');
  const r = grid.data?.context.room;
  const stats = grid.data?.stats;
  return (
    <Shell
      view="room"
      sessionId={sessionId}
      grid={grid}
      picker={
        <div className="max-w-md">
          <label htmlFor="tt-room-pick" className="form-label">Room</label>
          <select id="tt-room-pick" className="form-input" value={id ?? ''} onChange={(e) => onSelect(e.target.value || null)} disabled={isLoading}>
            <option value="">Select a classroom, lab or hall…</option>
            {rooms.map((o) => (
              <option key={o.value} value={o.value}>{o.label}{o.sub ? ` · ${o.sub}` : ''}</option>
            ))}
          </select>
        </div>
      }
      empty={<EmptyState icon={DoorOpen} title="Choose a room" description="See when a classroom or lab is occupied through the week, and by which class." />}
      header={
        r && (
          <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
            <div className="flex min-w-0 flex-1 items-center gap-3">
              <span className="kpi-icon h-12 w-12 shrink-0 bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">
                <DoorOpen className="h-6 w-6" />
              </span>
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <h2 className="font-display text-lg font-bold text-slate-900 dark:text-white">{r.code}{r.name !== `Room ${r.code}` ? ` · ${r.name}` : ''}</h2>
                  <Badge color={r.type === 'lab' ? 'purple' : 'blue'}>{labelize(r.type)}</Badge>
                </div>
                <p className="truncate text-sm text-slate-500 dark:text-slate-400">
                  {r.building ?? '—'}{r.floor ? ` · Floor ${r.floor}` : ''} · {r.capacity} seats{r.facilities ? ` · ${r.facilities}` : ''}
                </p>
              </div>
            </div>
            <div className="grid grid-cols-3 gap-2 sm:w-[22rem]">
              <Stat label="Periods / wk" value={stats?.periods ?? 0} />
              <Stat label="Utilisation" value={`${stats?.utilisation ?? 0}%`} />
              <Stat label="Free periods" value={Math.max(0, (stats?.capacity ?? 0) - (stats?.periods ?? 0))} />
            </div>
            <Button size="sm" variant="secondary" icon={Printer} href={printUrl('timetable.php', { view: 'room', id: r.id, session_id: sessionId })} target="_blank">
              Print
            </Button>
          </div>
        )
      }
    />
  );
}

/* ---------------------------------------------------------------- Student */

export function StudentView({ sessionId, id, onSelect }: LookupViewProps) {
  const grid = useGrid('student', id, sessionId);
  const st = grid.data?.context.student;
  const sec = grid.data?.context.section;
  return (
    <Shell
      view="student"
      sessionId={sessionId}
      grid={grid}
      picker={
        <div className="max-w-md">
          <label htmlFor="tt-student-pick" className="form-label">Student</label>
          <Combobox
            id="tt-student-pick"
            source="students"
            value={id}
            onChange={(v) => onSelect(v ? String(v) : null)}
            placeholder="Search by name, student ID, roll no. or mobile…"
            initialOptions={st ? [{ value: st.id, label: `${st.name} (${st.uid})` }] : []}
          />
        </div>
      }
      empty={<EmptyState icon={Search} title="Find a student" description="Search a student to see the weekly timetable of their section." />}
      header={
        st && (
          <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
            <div className="flex min-w-0 flex-1 items-center gap-3">
              <Avatar name={st.name} src={st.photo} size="lg" />
              <div className="min-w-0">
                <h2 className="font-display text-lg font-bold text-slate-900 dark:text-white">{st.name}</h2>
                <p className="flex flex-wrap gap-x-3 text-sm text-slate-500 dark:text-slate-400">
                  <span>{st.uid}</span>
                  {st.roll_no && <span>Roll {st.roll_no}</span>}
                  <span className="inline-flex items-center gap-1"><GraduationCap className="h-3.5 w-3.5" />{sec ? sec.label : `${st.program ?? ''} · Sem ${st.semester}`}</span>
                </p>
              </div>
            </div>
            {sec && (
              <div className="grid grid-cols-3 gap-2 sm:w-[22rem]">
                <Stat label="Periods / wk" value={grid.data?.stats.periods ?? 0} />
                <Stat label="Subjects" value={grid.data?.stats.subjects ?? 0} />
                <Stat label="Classmates" value={Math.max(0, sec.strength - 1)} />
              </div>
            )}
            <div className="flex gap-2">
              {sec && (
                <Button size="sm" variant="secondary" icon={CalendarDays} to={`/timetable?section=${sec.id}&session=${sessionId}`}>
                  Class view
                </Button>
              )}
              {sec && (
                <Button size="sm" variant="secondary" icon={Printer} href={printUrl('timetable.php', { view: 'student', id: st.id, session_id: sessionId })} target="_blank">
                  Print
                </Button>
              )}
            </div>
          </div>
        )
      }
    />
  );
}

