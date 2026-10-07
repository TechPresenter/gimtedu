import type { ReactNode } from 'react';
import clsx from 'clsx';
import { BriefcaseBusiness, CalendarDays, CalendarX2, GraduationCap, UserCog, Users } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, CardHeader, PageHeader, Reveal, Skeleton, StatCard, Stagger } from '@/components/ui';
import { DoughnutChart, chartColor } from '@/components/charts';
import { useApi } from '@/lib/queries';
import { formatNumber, labelize } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { EmployeeTable, useTableFilters } from './components/EmployeeTable';
import { DepartmentBars, type DeptRow } from './components/DepartmentBars';

interface FacultySummary {
  total: number;
  working: number;
  active: number;
  statuses: Record<string, number>;
  on_leave_today: number;
  joined_this_session: number;
  pending_leaves: number;
  without_login: number;
  by_department: DeptRow[];
  by_employment: Record<string, number>;
  by_gender: Record<string, number>;
  phd: number;
  avg_experience: number;
  by_designation: Record<string, number>;
  session: string;
}

export default function FacultyPage() {
  const qc = useQueryClient();
  const { data: s, isLoading, error } = useApi<FacultySummary>(['hr-summary', 'faculty'], 'faculty/summary');
  const { tableKey, tableRef, apply, active } = useTableFilters();
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['hr-summary', 'faculty'] });
    void qc.invalidateQueries({ queryKey: ['crud', 'faculty'] });
  };
  const designations = Object.entries(s?.by_designation ?? {});
  const kpiButton = (onClick: () => void, label: string, node: ReactNode) => (
    <button key={label} type="button" onClick={onClick} className="block w-full rounded-2xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500" aria-label={label}>
      {node}
    </button>
  );

  return (
    <>
      <PageHeader
        title="Faculty"
        description="Teaching faculty across departments — qualifications, assignments, leave and payroll references."
        breadcrumbs={[{ label: 'Faculty & Staff' }, { label: 'Faculty' }]}
        actions={
          <>
            <Button variant="secondary" icon={UserCog} to="/staff">
              Staff
            </Button>
            <Button variant="secondary" icon={CalendarDays} to="/leaves">
              Leave management
            </Button>
          </>
        }
      />

      {error && <Alert variant="error" className="mb-4" title="Unable to load faculty statistics">{(error as ApiError).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        {[
          kpiButton(() => apply({ status: 'active' }), 'Show active faculty',
            <StatCard label="Total Faculty" value={s?.working ?? 0} icon={Users} tone="navy" loading={isLoading} hint={s ? `${s.active} active · ${s.statuses.on_leave ?? 0} on long leave` : undefined} />),
          kpiButton(() => apply({ on_leave: 'today' }), 'Show faculty on leave today',
            <StatCard label="On Leave Today" value={s?.on_leave_today ?? 0} icon={CalendarX2} tone="amber" loading={isLoading} hint={s ? `${s.pending_leaves} leave request${s.pending_leaves === 1 ? '' : 's'} pending` : undefined} />),
          kpiButton(() => apply({ qualification_level: 'phd' }), 'Show PhD holders',
            <StatCard label="PhD Holders" value={s?.phd ?? 0} icon={GraduationCap} tone="green" loading={isLoading} hint={s && s.working ? `${Math.round((s.phd / s.working) * 100)}% of faculty` : undefined} />),
          <StatCard key="exp" label="Avg. Experience" value={s ? `${formatNumber(s.avg_experience, 1)} yrs` : '—'} icon={BriefcaseBusiness} tone="purple" loading={isLoading}
            hint={s ? `${s.by_department.length} departments · ${s.without_login} without login` : undefined} />,
        ]}
      </Stagger>

      <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-5">
        <Reveal className="lg:col-span-3">
          <DepartmentBars
            title="Faculty by department"
            subtitle="Click a department to filter the list"
            rows={s?.by_department}
            loading={isLoading}
            activeId={active('department_id')}
            onSelect={(id) => apply({ department_id: id })}
          />
        </Reveal>
        <Reveal className="lg:col-span-2" delay={80}>
          <Card className="h-full">
            <CardHeader title="Designation mix" subtitle={s ? `${s.working} faculty · session ${s.session}` : undefined} />
            <div className="p-5">
              {isLoading ? (
                <div className="flex items-center gap-5">
                  <Skeleton className="h-40 w-40 rounded-full" />
                  <div className="flex-1 space-y-3">{[1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-4" />)}</div>
                </div>
              ) : designations.length ? (
                <DoughnutChart
                  labels={designations.map(([k]) => k)}
                  data={designations.map(([, v]) => v)}
                  colors={designations.map((_, i) => chartColor(i))}
                  centerValue={formatNumber(s?.working)}
                  centerLabel="Faculty"
                  valueFormat="number"
                  height={170}
                />
              ) : (
                <p className="py-10 text-center text-sm text-slate-500">No faculty yet.</p>
              )}
              {s && (
                <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                  {Object.entries(s.by_employment).map(([k, v]) => (
                    <button
                      key={k}
                      type="button"
                      onClick={() => apply({ employment_type: k })}
                      className={clsx('badge transition hover:-translate-y-px', active('employment_type') === k ? 'badge-navy' : 'badge-slate')}
                    >
                      {labelize(k)} · {v}
                    </button>
                  ))}
                </div>
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <EmployeeTable type="faculty" module="faculty" tableKey={tableKey} tableRef={tableRef} onChanged={refresh} />
    </>
  );
}
