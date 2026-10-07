import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertTriangle, ArrowRight, BookOpen, Building2, CalendarDays, ChevronsDownUp, DoorOpen, GraduationCap, Network, UserCheck, Users, UsersRound,
} from 'lucide-react';
import { BarChart, DoughnutChart, chartColor } from '@/components/charts';
import { Alert, Button, Card, CardHeader, EmptyState, ProgressBar, Reveal, SearchInput, Skeleton, StatCard, Stagger } from '@/components/ui';
import { useAcademicSession } from '@/lib/auth';
import { formatDate, formatNumber, labelize } from '@/lib/format';
import { useApi } from '@/lib/queries';
import type { ApiError } from '@/lib/api';
import type { AcademicOverview } from '../types';
import { HierarchyTree } from './HierarchyTree';

const LEVEL_LABEL: Record<string, string> = { UG: 'Undergraduate', PG: 'Postgraduate', Diploma: 'Diploma', Certificate: 'Certificate', PhD: 'Doctoral' };

export default function OverviewTab() {
  const { id: sessionId } = useAcademicSession();
  const { data, isLoading, error, refetch } = useApi<AcademicOverview>(['acad-overview', sessionId], 'academics/overview', { session_id: sessionId ?? undefined });
  const [q, setQ] = useState('');
  const [collapseKey, setCollapseKey] = useState(0);

  const deptChart = useMemo(() => {
    const rows = [...(data?.tree ?? [])].filter((d) => d.students > 0).sort((a, b) => b.students - a.students);
    return { labels: rows.map((d) => d.code), data: rows.map((d) => d.students) };
  }, [data]);

  if (error) {
    return (
      <Alert variant="error" title="Unable to load the academic overview" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
        {(error as ApiError).message}
      </Alert>
    );
  }

  const k = data?.kpis;
  const typeTotal = Object.values(data?.subject_types ?? {}).reduce((a, b) => a + b, 0);

  return (
    <div className="space-y-6">
      <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" step={50}>
        {[
          <StatCard key="d" label="Departments" value={k?.departments ?? 0} icon={Building2} tone="navy" loading={isLoading} hint={`${formatNumber(k?.programs)} programs`} to="/academics?tab=departments" />,
          <StatCard key="p" label="Programs" value={k?.programs ?? 0} icon={GraduationCap} tone="blue" loading={isLoading} hint={`${formatNumber(k?.courses)} specializations`} to="/academics?tab=programs" />,
          <StatCard key="s" label="Subjects" value={k?.subjects ?? 0} icon={BookOpen} tone="purple" loading={isLoading} hint={`${formatNumber(k?.electives)} electives`} to="/academics?tab=subjects" />,
          <StatCard key="sec" label="Sections" value={k?.sections ?? 0} icon={Users} tone="cyan" loading={isLoading} hint={`This session · ${formatNumber(k?.batches)} batches`} to="/academics?tab=sections" />,
          <StatCard key="f" label="Faculty" value={k?.faculty ?? 0} icon={UserCheck} tone="green" loading={isLoading} hint={`${formatNumber(k?.assignments)} subject assignments`} to="/academics?tab=assignments" />,
          <StatCard key="st" label="Active Students" value={k?.students ?? 0} icon={UsersRound} tone="orange" loading={isLoading} hint="Enrolled across all programs" />,
          <StatCard key="r" label="Rooms & Labs" value={k?.classrooms ?? 0} icon={DoorOpen} tone="amber" loading={isLoading} hint="Classrooms, labs & halls" to="/academics?tab=rooms" />,
          <StatCard
            key="t"
            label="Timetables Ready"
            value={isLoading ? 0 : `${k?.timetabled_sections ?? 0}/${k?.sections ?? 0}`}
            icon={CalendarDays}
            tone="pink"
            loading={isLoading}
            hint="Sections with a weekly timetable"
            to="/timetable"
          />,
        ]}
      </Stagger>

      {k && k.unassigned > 0 && (
        <Reveal>
          <Alert
            variant="warning"
            title={`${formatNumber(k.unassigned)} subject${k.unassigned === 1 ? '' : 's'} in this session have no faculty assigned`}
            action={<Button size="sm" variant="secondary" iconRight={ArrowRight} to="/academics?tab=assignments">Assign faculty</Button>}
          >
            Students in these sections cannot be timetabled or marked for attendance until a teacher is assigned.
          </Alert>
        </Reveal>
      )}

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="overflow-hidden">
            <CardHeader
              title="Academic hierarchy"
              icon={Network}
              subtitle={
                data?.session
                  ? `Session ${data.session.name} · ${formatDate(data.session.start_date)} – ${formatDate(data.session.end_date)}`
                  : 'Department → Program → Specialization → Semester → Subject → Faculty → Students'
              }
              actions={
                <Button size="sm" variant="ghost" icon={ChevronsDownUp} onClick={() => setCollapseKey((c) => c + 1)} aria-label="Collapse all">
                  <span className="hidden sm:inline">Collapse</span>
                </Button>
              }
            />
            <div className="border-b border-slate-100 px-5 py-3 dark:border-slate-800">
              <SearchInput value={q} onChange={setQ} placeholder="Find a department or program…" size="sm" className="sm:max-w-xs" />
            </div>
            {isLoading ? (
              <div className="space-y-3 p-5">
                {Array.from({ length: 6 }).map((_, i) => (
                  <Skeleton key={i} className="h-12 w-full rounded-xl" />
                ))}
              </div>
            ) : !data?.tree.length ? (
              <EmptyState icon={Building2} title="No departments yet" description="Create departments and programs to build the academic structure." action={<Button to="/academics?tab=departments">Add Department</Button>} />
            ) : (
              <HierarchyTree key={collapseKey} tree={data.tree} sessionId={sessionId} query={q} />
            )}
          </Card>
        </Reveal>

        <div className="space-y-6">
          <Reveal delay={80}>
            <Card>
              <CardHeader title="Programs by level" subtitle="Active programs offered" icon={GraduationCap} />
              <div className="p-5">
                {isLoading ? (
                  <Skeleton className="h-44 w-full" />
                ) : (
                  <DoughnutChart
                    labels={Object.keys(data?.levels ?? {}).map((l) => LEVEL_LABEL[l] ?? l)}
                    data={Object.values(data?.levels ?? {})}
                    centerValue={k?.programs ?? 0}
                    centerLabel="Programs"
                    valueFormat="number"
                    height={150}
                  />
                )}
              </div>
            </Card>
          </Reveal>
          <Reveal delay={140}>
            <Card>
              <CardHeader title="Students by department" subtitle="Active enrolments" icon={Users} />
              <div className="p-4">
                {isLoading ? <Skeleton className="h-52 w-full" /> : <BarChart labels={deptChart.labels} series={[{ label: 'Students', data: deptChart.data, color: chartColor(0) }]} height={210} horizontal />}
              </div>
            </Card>
          </Reveal>
          <Reveal delay={200}>
            <Card>
              <CardHeader title="Subjects by type" subtitle={`${formatNumber(typeTotal)} active subjects`} icon={BookOpen} />
              <ul className="space-y-3 p-5">
                {isLoading
                  ? Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-6 w-full" />)
                  : Object.entries(data?.subject_types ?? {}).map(([type, n], i) => (
                      <li key={type}>
                        <div className="mb-1 flex items-center justify-between text-sm">
                          <span className="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                            <span className="h-2.5 w-2.5 rounded-full" style={{ background: chartColor(i) }} />
                            {labelize(type)}
                          </span>
                          <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(n)}</span>
                        </div>
                        <ProgressBar value={typeTotal ? (n / typeTotal) * 100 : 0} tone={(['blue', 'green', 'orange', 'purple'] as const)[i % 4]} label={`${labelize(type)} subjects`} />
                      </li>
                    ))}
              </ul>
            </Card>
          </Reveal>
          {k && k.unassigned === 0 && k.assignments > 0 && (
            <Reveal delay={240}>
              <Link
                to="/academics?tab=assignments"
                className={clsx('card flex items-center gap-3 p-4 transition hover:-translate-y-0.5 hover:shadow-soft motion-reduce:transform-none')}
              >
                <span className="kpi-icon bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                  <UserCheck className="h-5 w-5" />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-semibold text-slate-900 dark:text-white">Every subject has a teacher</span>
                  <span className="block text-xs text-slate-500 dark:text-slate-400">{formatNumber(k.assignments)} faculty assignments this session</span>
                </span>
                <ArrowRight className="h-4 w-4 text-slate-400" />
              </Link>
            </Reveal>
          )}
          {k && k.unassigned > 0 && (
            <p className="flex items-center gap-2 text-xs text-amber-700 dark:text-amber-300">
              <AlertTriangle className="h-3.5 w-3.5" /> Coverage gaps are highlighted in the hierarchy.
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
