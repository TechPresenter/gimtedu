import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { AlertTriangle, CalendarDays, CheckCircle2, Clock, Gauge, UserCheck, UserPlus, Users } from 'lucide-react';
import { CrudTable } from '@/components/crud';
import {
  Alert, Avatar, Badge, Button, Card, CardHeader, Checkbox, Combobox, EmptyState, Field, Modal, SearchInput, Skeleton, Stagger, StatTile, useToast,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { formatNumber } from '@/lib/format';
import { useApi, useLookup } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { useQueryClient } from '@tanstack/react-query';
import type { UnassignedRow, Workload, WorkloadRow } from '../types';

const loadTone: Record<WorkloadRow['load'], { bar: string; text: string; label: string }> = {
  overloaded: { bar: 'bg-red-500', text: 'text-red-600 dark:text-red-400', label: 'Overloaded' },
  normal: { bar: 'bg-emerald-500', text: 'text-emerald-700 dark:text-emerald-400', label: 'Balanced' },
  light: { bar: 'bg-blue-500', text: 'text-blue-700 dark:text-blue-300', label: 'Light' },
  free: { bar: 'bg-slate-300', text: 'text-slate-500', label: 'No classes' },
};

export default function AssignmentsTab() {
  const { id: sessionId } = useAcademicSession();
  const { can } = useAuth();
  const [dept, setDept] = useState('');
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 300);
  const { data: departments = [] } = useLookup('departments');
  // Keyed under ['crud', 'faculty_subjects'] so CrudTable saves/deletes refresh these panels too.
  const workload = useApi<Workload>(['crud', 'faculty_subjects', 'workload', sessionId, dept, dq], 'academics/workload', { session_id: sessionId ?? undefined, department_id: dept || undefined, q: dq || undefined });
  const unassigned = useApi<{ rows: UnassignedRow[]; total: number }>(['crud', 'faculty_subjects', 'unassigned', sessionId], 'academics/unassigned', { session_id: sessionId ?? undefined });
  const [assignFor, setAssignFor] = useState<UnassignedRow | null>(null);

  const st = workload.data?.stats;
  return (
    <div className="space-y-6">
      <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" step={50}>
        {[
          <StatTile key="a" label="Faculty teaching" value={st ? `${st.assigned}/${st.faculty}` : '—'} icon={UserCheck} tone="green" loading={workload.isLoading} sub="With at least one subject" />,
          <StatTile key="b" label="Average load" value={st ? `${st.avg_hours} h` : '—'} icon={Clock} tone="blue" loading={workload.isLoading} sub={st ? `Max ${st.max_hours} h / week` : undefined} />,
          <StatTile key="c" label="Overloaded faculty" value={st?.overloaded ?? 0} icon={Gauge} tone={st && st.overloaded > 0 ? 'red' : 'slate'} loading={workload.isLoading} sub="Above the weekly maximum" />,
          <StatTile key="d" label="Subjects without faculty" value={unassigned.data?.total ?? 0} icon={AlertTriangle} tone={unassigned.data && unassigned.data.total > 0 ? 'amber' : 'green'} loading={unassigned.isLoading} sub="Section-subject pairs" />,
        ]}
      </Stagger>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
        <Card className="xl:col-span-3">
          <CardHeader title="Faculty workload" subtitle="Planned teaching hours per week (from assignments) vs. periods in the timetable" icon={Gauge} />
          <div className="flex flex-col gap-2 border-b border-slate-100 px-5 py-3 sm:flex-row dark:border-slate-800">
            <SearchInput value={q} onChange={setQ} placeholder="Search faculty…" size="sm" className="sm:max-w-[14rem]" />
            <select
              value={dept}
              onChange={(e) => setDept(e.target.value)}
              aria-label="Department"
              className="form-input form-input-sm sm:w-56"
            >
              <option value="">All departments</option>
              {departments.map((d) => (
                <option key={d.value} value={d.value}>{d.label}</option>
              ))}
            </select>
          </div>
          {workload.error ? (
            <Alert variant="error" className="m-5">{(workload.error as ApiError).message}</Alert>
          ) : workload.isLoading ? (
            <div className="space-y-3 p-5">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-12 w-full rounded-xl" />)}</div>
          ) : !workload.data?.rows.length ? (
            <EmptyState icon={Users} title="No faculty found" description="Try another search or department." />
          ) : (
            <ul className="max-h-[460px] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
              {workload.data.rows.map((r) => {
                const tone = loadTone[r.load];
                const pct = Math.min(100, (r.planned_hours / Math.max(1, r.max_hours)) * 100);
                return (
                  <li key={r.id} className="flex items-center gap-3 px-5 py-3">
                    <Avatar name={r.name} src={r.photo} />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-x-2">
                        <span className="truncate text-sm font-semibold text-slate-900 dark:text-white">{r.name}</span>
                        {r.department_code && <Badge color="slate">{r.department_code}</Badge>}
                        {r.status === 'on_leave' && <Badge color="amber">On leave</Badge>}
                      </div>
                      <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                        {r.designation} · {r.subjects} subject{r.subjects === 1 ? '' : 's'} · {r.sections} section{r.sections === 1 ? '' : 's'}
                      </p>
                    </div>
                    <div className="hidden w-44 shrink-0 sm:block">
                      <div className="mb-1 flex justify-between text-[11px]">
                        <span className={clsx('font-semibold', tone.text)}>{tone.label}</span>
                        <span className="tabular-nums text-slate-600 dark:text-slate-300">
                          {r.planned_hours} / {r.max_hours} h
                        </span>
                      </div>
                      <div className="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" aria-valuenow={r.planned_hours} aria-valuemin={0} aria-valuemax={r.max_hours} aria-label={`${r.name} weekly load`}>
                        <div className={clsx('h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none', tone.bar)} style={{ width: `${pct}%` }} />
                      </div>
                    </div>
                    <span className="w-14 shrink-0 text-right text-xs tabular-nums text-slate-500 sm:hidden">{r.planned_hours} h</span>
                    {can('timetable') && (
                      <Link
                        to={`/timetable?view=faculty&faculty=${r.id}`}
                        className="btn-icon !h-8 !w-8 shrink-0"
                        title={`${r.scheduled_periods} periods in the timetable - open faculty timetable`}
                        aria-label={`Open timetable of ${r.name}`}
                      >
                        <CalendarDays className="h-4 w-4" />
                      </Link>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </Card>

        <Card className="xl:col-span-2">
          <CardHeader
            title="Subjects without faculty"
            subtitle="Sections of this session that still need a teacher"
            icon={AlertTriangle}
            actions={unassigned.data && unassigned.data.total > 0 ? <Badge color="amber">{unassigned.data.total}</Badge> : undefined}
          />
          {unassigned.isLoading ? (
            <div className="space-y-3 p-5">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-10 w-full rounded-xl" />)}</div>
          ) : unassigned.error ? (
            <Alert variant="error" className="m-5">{(unassigned.error as ApiError).message}</Alert>
          ) : !unassigned.data?.rows.length ? (
            <EmptyState icon={CheckCircle2} title="Full coverage" description="Every subject of every section in this session has a faculty member assigned." />
          ) : (
            <ul className="max-h-[460px] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
              {unassigned.data.rows.map((r) => (
                <li key={`${r.section_id}-${r.subject_id}`} className="flex items-center gap-3 px-5 py-3">
                  <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300">
                    <AlertTriangle className="h-4 w-4" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{r.subject_name}</p>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                      {r.subject_code} · {r.section_label}
                    </p>
                  </div>
                  {can('academics', 'create') && (
                    <Button size="xs" variant="soft" icon={UserPlus} onClick={() => setAssignFor(r)}>
                      Assign
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <CrudTable
        module="faculty_subjects"
        urlState
        addLabel="Assign Faculty"
        emptyTitle="No faculty assignments"
        emptyText="Assign faculty members to the subjects of each section so they can be timetabled."
        header={({ summary }) =>
          summary ? (
            <div className="flex flex-wrap gap-2 border-b border-slate-100 px-4 py-2.5 text-xs dark:border-slate-800">
              <Badge color="navy">{formatNumber(summary.assignments)} assignments</Badge>
              <Badge color="green">{formatNumber(summary.faculty)} faculty</Badge>
              <Badge color="blue">{formatNumber(summary.subjects)} subjects</Badge>
              <Badge color="slate">{formatNumber(summary.hours)} teaching hours / week</Badge>
            </div>
          ) : null
        }
        rowMenu={(row) => [
          can('timetable') && { label: 'Faculty timetable', icon: CalendarDays, to: `/timetable?view=faculty&faculty=${row.faculty_id}` },
          can('timetable') && row.section_id && { label: 'Class timetable', icon: CalendarDays, to: `/timetable?section=${row.section_id}` },
        ]}
      />

      <AssignModal row={assignFor} sessionId={sessionId} onClose={() => setAssignFor(null)} />
    </div>
  );
}

function AssignModal({ row, sessionId, onClose }: { row: UnassignedRow | null; sessionId: number | null; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [faculty, setFaculty] = useState<string | number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [allDepts, setAllDepts] = useState(false);
  const close = () => {
    setFaculty(null);
    setError(null);
    setAllDepts(false);
    onClose();
  };
  const save = async () => {
    if (!row) return;
    if (!faculty) {
      setError('Select a faculty member.');
      return;
    }
    setSaving(true);
    try {
      const res = await api.post('academics/assign', { section_id: row.section_id, subject_id: row.subject_id, faculty_id: faculty, session_id: sessionId ?? undefined });
      toast.success(res.message);
      await Promise.all([qc.invalidateQueries({ queryKey: ['crud', 'faculty_subjects'] }), qc.invalidateQueries({ queryKey: ['acad-overview'] })]);
      close();
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.faculty_id ?? err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={!!row}
      onClose={close}
      static={saving}
      size="md"
      title="Assign faculty"
      description={row ? `${row.subject_code} · ${row.subject_name} — ${row.section_label}` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={close} disabled={saving}>Cancel</Button>
          <Button onClick={save} loading={saving} icon={UserCheck}>Assign</Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Faculty member" required error={error} htmlFor="assign-faculty" hint={allDepts ? 'Showing faculty from every department.' : "Showing faculty of the program's department."}>
          <Combobox
            id="assign-faculty"
            source="faculty"
            params={allDepts || !row ? undefined : { department_id: row.department_id }}
            value={faculty}
            onChange={(v) => {
              setFaculty(v);
              setError(null);
            }}
            placeholder="Search faculty by name or ID…"
            invalid={!!error}
          />
        </Field>
        <Checkbox label="Show faculty from all departments" checked={allDepts} onChange={(e) => setAllDepts(e.target.checked)} />
      </div>
    </Modal>
  );
}
