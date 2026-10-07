import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  CalendarCheck, CalendarDays, CalendarRange, DoorOpen, Gauge, List, School, Search, UserRound, Users, UserX,
} from 'lucide-react';
import { CrudTable } from '@/components/crud';
import { Alert, Button, CardSkeleton, PageHeader, Stagger, StatTile, Tabs } from '@/components/ui';
import type { ApiError } from '@/lib/api';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { useApi } from '@/lib/queries';
import type { TtMeta } from './types';
import { ClassView } from './timetable/ClassView';
import { DailyView } from './timetable/DailyView';
import { FreeRoomsDrawer } from './timetable/FreeRoomsDrawer';
import { FacultyView, RoomView, StudentView } from './timetable/LookupViews';

const VIEWS = [
  { key: 'class', label: 'Class', icon: School },
  { key: 'faculty', label: 'Faculty', icon: UserRound },
  { key: 'room', label: 'Room', icon: DoorOpen },
  { key: 'daily', label: 'Daily', icon: CalendarRange },
  { key: 'student', label: 'Student', icon: Users },
  { key: 'list', label: 'All periods', icon: List },
];

const DESCRIPTIONS: Record<string, string> = {
  class: 'Weekly class schedules with automatic faculty, room and class clash prevention.',
  faculty: 'Weekly teaching schedule of a faculty member across every class.',
  room: 'Occupancy of a classroom, lab or hall through the week.',
  daily: 'Every class of the institute on one day.',
  student: "A student's weekly timetable from their section.",
  list: 'All scheduled periods of the session - search, filter, bulk publish and export.',
};

const num = (v: string | null) => (v && /^\d+$/.test(v) ? Number(v) : null);

export default function TimetablePage() {
  const [params, setParams] = useSearchParams();
  const { can, session } = useAuth();
  // Faculty without edit rights land on their own teaching timetable.
  const myFaculty = session?.user?.faculty_id ?? null;
  const defaultView = myFaculty && !can('timetable', 'create') ? 'faculty' : 'class';
  const academic = useAcademicSession();
  const view = VIEWS.some((v) => v.key === params.get('view')) ? (params.get('view') as string) : defaultView;
  const sessionId = num(params.get('session')) ?? academic.id ?? 0;
  const [freeOpen, setFreeOpen] = useState(false);
  const meta = useApi<TtMeta>(['tt', 'meta', sessionId], 'timetable/meta', { session_id: sessionId || undefined });
  const sid = meta.data?.session_id ?? sessionId;

  /** Merge URL params (null removes a key). Automatic selections replace history; user actions push. */
  const update = (patch: Record<string, string | null>, push = false) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null || v === '' ? next.delete(k) : next.set(k, v)));
    setParams(next, { replace: !push });
  };
  const switchView = (key: string) => {
    const next = new URLSearchParams();
    if (key !== 'class') next.set('view', key);
    if (params.get('session')) next.set('session', params.get('session') as string);
    setParams(next);
  };

  const perms = { create: can('timetable', 'create'), edit: can('timetable', 'edit'), delete: can('timetable', 'delete'), publish: can('timetable', 'publish') };
  const s = meta.data?.summary;
  const sessionName = academic.all.find((x) => x.id === sid)?.name;

  return (
    <>
      <PageHeader
        title="Timetable"
        description={DESCRIPTIONS[view]}
        breadcrumbs={[{ label: 'Academics', to: '/academics' }, { label: 'Timetable' }]}
        actions={
          <>
            {myFaculty && (
              <Button variant="secondary" icon={CalendarDays} to={`/timetable?view=faculty&faculty=${myFaculty}`}>
                My timetable
              </Button>
            )}
            <Button variant="secondary" icon={Search} onClick={() => setFreeOpen(true)} disabled={!meta.data}>
              Free rooms
            </Button>
            {can('academics') && (
              <Button variant="secondary" icon={UserRound} to="/academics?tab=assignments">
                Assignments
              </Button>
            )}
          </>
        }
      />

      {meta.error ? (
        <Alert variant="error" title="Unable to load the timetable" className="mb-6">
          {(meta.error as ApiError).message}
        </Alert>
      ) : (
        <Stagger className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6" step={45}>
          {[
            <StatTile key="a" label="Scheduled" value={s ? `${s.scheduled_sections}/${s.sections}` : '—'} icon={School} tone="navy" loading={meta.isLoading} sub={sessionName ? `Sections · ${sessionName}` : 'Sections'} />,
            <StatTile key="b" label="Published" value={s?.published_sections ?? 0} icon={CalendarCheck} tone="green" loading={meta.isLoading} sub={s ? `${s.draft_sections} in draft` : undefined} />,
            <StatTile key="c" label="Periods" value={s?.periods ?? 0} icon={CalendarDays} tone="blue" loading={meta.isLoading} sub="Per week" />,
            <StatTile key="d" label="Faculty" value={s?.faculty ?? 0} icon={UserRound} tone="purple" loading={meta.isLoading} sub="Teaching" />,
            <StatTile key="e" label="Room use" value={s ? `${s.room_utilisation}%` : '—'} icon={Gauge} tone="amber" loading={meta.isLoading} sub="Rooms & labs" />,
            <StatTile key="f" label="No faculty" value={s?.without_faculty ?? 0} icon={UserX} tone={s && s.without_faculty > 0 ? 'red' : 'slate'} loading={meta.isLoading} sub="Periods" />,
          ]}
        </Stagger>
      )}

      <Tabs tabs={VIEWS} value={view} onChange={switchView} variant="pills" className="mb-5" />

      {!meta.data ? (
        meta.isLoading ? (
          <div className="space-y-4">
            <CardSkeleton lines={2} />
            <CardSkeleton lines={8} />
          </div>
        ) : null
      ) : (
        <div key={view} className="motion-safe:animate-fade-in">
          {view === 'class' && (
            <ClassView
              sessionId={sid}
              sessions={academic.all}
              sectionId={num(params.get('section'))}
              programId={num(params.get('program'))}
              days={meta.data.days}
              slots={meta.data.slots}
              perms={perms}
              onChange={(patch) => update(patch, 'section' in patch && patch.section !== null && !!params.get('section'))}
            />
          )}
          {view === 'faculty' && <FacultyView sessionId={sid} id={num(params.get('faculty')) ?? (params.get('view') ? null : myFaculty)} days={meta.data.days} slots={meta.data.slots} onSelect={(v) => update({ faculty: v }, true)} />}
          {view === 'room' && <RoomView sessionId={sid} id={num(params.get('room'))} days={meta.data.days} slots={meta.data.slots} onSelect={(v) => update({ room: v }, true)} />}
          {view === 'student' && <StudentView sessionId={sid} id={num(params.get('student'))} days={meta.data.days} slots={meta.data.slots} onSelect={(v) => update({ student: v }, true)} />}
          {view === 'daily' && (
            <DailyView
              sessionId={sid}
              days={meta.data.days}
              day={num(params.get('day'))}
              department={params.get('dept') ?? ''}
              program={params.get('program') ?? ''}
              onChange={(patch) => update(patch)}
            />
          )}
          {view === 'list' && (
            <CrudTable
              module="timetables"
              defaultFilters={{ academic_session_id: sid }}
              addLabel="Add Period"
              emptyText="No periods are scheduled for this session yet. Open the Class view to build a section's timetable."
              rowMenu={(row) => [{ label: 'Open class timetable', icon: School, to: `/timetable?section=${row.section_id}&session=${row.academic_session_id}` }]}
            />
          )}
        </div>
      )}

      {meta.data && <FreeRoomsDrawer open={freeOpen} onClose={() => setFreeOpen(false)} days={meta.data.days} slots={meta.data.slots} sessionId={sid} />}
    </>
  );
}
