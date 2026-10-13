import type { ReactNode } from 'react';
import clsx from 'clsx';
import { CalendarDays, CalendarX2, KeyRound, LayoutGrid, UserCog, Users } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Card, CardHeader, PageHeader, Reveal, Skeleton, StatCard, Stagger } from '@/components/ui';
import { DoughnutChart, chartColor } from '@/components/charts';
import { useApi } from '@/lib/queries';
import { formatNumber, labelize } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { EmployeeTable, useTableFilters } from './components/EmployeeTable';
import { DepartmentBars, type DeptRow } from './components/DepartmentBars';
import { STAFF_CATEGORIES, STAFF_CATEGORY_CODES } from './hr';

interface StaffSummary {
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
  by_category: Record<string, number>;
  categories: number;
  session: string;
}

export default function StaffPage() {
  const qc = useQueryClient();
  const { data: s, isLoading, error } = useApi<StaffSummary>(['hr-summary', 'staff'], 'staff/summary');
  const { tableKey, tableRef, apply, active } = useTableFilters();
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['hr-summary', 'staff'] });
    void qc.invalidateQueries({ queryKey: ['crud', 'staff'] });
  };
  const categories = Object.entries(s?.by_category ?? {});
  const kpiButton = (onClick: () => void, label: string, node: ReactNode) => (
    <button key={label} type="button" onClick={onClick} className="block w-full rounded-2xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500" aria-label={label}>
      {node}
    </button>
  );
  // Category list doubles as a filter (like the department bars on the faculty page)
  const catRows: DeptRow[] = categories.map(([k, v], i) => ({ id: i, code: STAFF_CATEGORY_CODES[k] ?? k.slice(0, 3).toUpperCase(), name: STAFF_CATEGORIES[k] ?? labelize(k), total: v }));
  const activeCat = active('category');

  return (
    <>
      <PageHeader
        title="Staff"
        description="Non-teaching staff across administration, accounts, library, hostel, transport, IT and campus services."
        breadcrumbs={[{ label: 'Faculty & Staff' }, { label: 'Staff' }]}
        actions={
          <>
            <Button variant="secondary" icon={Users} to="/faculty">
              Faculty
            </Button>
            <Button variant="secondary" icon={CalendarDays} to="/leaves">
              Leave management
            </Button>
          </>
        }
      />

      {error && <Alert variant="error" className="mb-4" title="Unable to load staff statistics">{(error as ApiError).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        {[
          kpiButton(() => apply({ status: 'active' }), 'Show active staff',
            <StatCard label="Total Staff" value={s?.working ?? 0} icon={UserCog} tone="navy" loading={isLoading} hint={s ? `${s.active} active · ${s.statuses.on_leave ?? 0} on long leave` : undefined} />),
          kpiButton(() => apply({ on_leave: 'today' }), 'Show staff on leave today',
            <StatCard label="On Leave Today" value={s?.on_leave_today ?? 0} icon={CalendarX2} tone="amber" loading={isLoading} hint={s ? `${s.pending_leaves} request${s.pending_leaves === 1 ? '' : 's'} pending` : undefined} />),
          <StatCard key="cat" label="Service Categories" value={s?.categories ?? 0} icon={LayoutGrid} tone="cyan" loading={isLoading} hint={s ? `${s.joined_this_session} joined this session` : undefined} />,
          <StatCard key="login" label="Without Login" value={s?.without_login ?? 0} icon={KeyRound} tone="purple" loading={isLoading} hint="Create accounts from the row menu" />,
        ]}
      </Stagger>

      <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-5">
        <Reveal className="lg:col-span-3">
          <DepartmentBars
            title="Staff by service category"
            subtitle="Click a category to filter the list"
            rows={catRows}
            loading={isLoading}
            activeId={activeCat ? String(categories.findIndex(([k]) => k === activeCat)) : null}
            onSelect={(i) => apply({ category: i === null ? null : categories[i]?.[0] ?? null })}
          />
        </Reveal>
        <Reveal className="lg:col-span-2" delay={80}>
          <Card className="h-full">
            <CardHeader title="Employment type" subtitle={s ? `${s.working} staff · session ${s.session}` : undefined} />
            <div className="p-5">
              {isLoading ? (
                <div className="flex items-center gap-5">
                  <Skeleton className="h-40 w-40 rounded-full" />
                  <div className="flex-1 space-y-3">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-4" />)}</div>
                </div>
              ) : s && Object.keys(s.by_employment).length ? (
                <DoughnutChart
                  labels={Object.keys(s.by_employment).map(labelize)}
                  data={Object.values(s.by_employment)}
                  colors={Object.keys(s.by_employment).map((_, i) => chartColor(i))}
                  centerValue={formatNumber(s.working)}
                  centerLabel="Staff"
                  valueFormat="number"
                  height={170}
                />
              ) : (
                <p className="py-10 text-center text-sm text-slate-500">No staff yet.</p>
              )}
              {s && s.by_department.length > 0 && (
                <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                  {s.by_department.map((d) => (
                    <button
                      key={`${d.id}`}
                      type="button"
                      disabled={d.id === null}
                      onClick={() => apply({ department_id: d.id })}
                      className={clsx('badge transition hover:-translate-y-px disabled:hover:translate-y-0', active('department_id') === String(d.id) ? 'badge-navy' : 'badge-slate')}
                      title={d.name}
                    >
                      {d.id === null ? 'No department' : d.code} · {d.total}
                    </button>
                  ))}
                </div>
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <EmployeeTable type="staff" module="staff" tableKey={tableKey} tableRef={tableRef} onChanged={refresh} />
    </>
  );
}
