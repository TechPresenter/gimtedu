import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { CalendarCheck2, CalendarClock, CalendarPlus, CalendarRange, Check, ClipboardList, Eye, Hourglass, Scale, Settings2, Sigma, UserRound, X } from 'lucide-react';
import { Alert, Avatar, Button, Card, CardHeader, EmptyState, PageHeader, Reveal, Skeleton, StatCard, Stagger, Tabs, useToast } from '@/components/ui';
import { BarChart, DoughnutChart } from '@/components/charts';
import { CrudTable } from '@/components/crud';
import { useAuth } from '@/lib/auth';
import { useApi } from '@/lib/queries';
import { formatDate, formatNumber } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import type { Row } from '@/lib/types';
import { daysLabel, leaveChip, leaveTone, parseRef, profilePath, scrollActiveTabIntoView, type EmployeeType, type SessionWindow } from './hr';
import { useTableFilters } from './components/EmployeeTable';
import { LeaveFormModal } from './components/LeaveFormModal';
import { LeaveDecisionModal, LeaveDetailModal, leaveCellRenderers, leaveRowMenu, type LeaveDecision } from './components/LeaveActions';
import { LeaveCalendar } from './components/LeaveCalendar';
import { LeaveBalancesTable } from './components/LeaveBalancesTable';
import { LeaveTypesModal } from './components/LeaveTypesModal';

interface Summary {
  session: SessionWindow;
  pending: number;
  pending_overdue: number;
  on_leave_today: number;
  on_leave_list: { ref: string; employee_type: EmployeeType; employee_id: number; leave_type: string; leave_type_name: string; color: string | null; to_date: string; name: string; photo: string | null; designation: string }[];
  upcoming_week: number;
  month_applications: number;
  month_days: number;
  session_days: number;
  session_rejected: number;
  lop_days: number;
  by_type: { code: string; name: string; color: string | null; applications: number; days: number }[];
  trend: { month: string; label: string; faculty: number; staff: number }[];
  can: { approve: boolean; create: boolean; edit: boolean; manage: boolean };
}

const TABS = [
  { key: 'applications', label: 'Applications', icon: ClipboardList },
  { key: 'calendar', label: 'Who is on leave', icon: CalendarRange },
  { key: 'balances', label: 'Balances', icon: Scale },
];
const toneHex: Record<string, string> = { blue: '#2563EB', green: '#10B981', amber: '#F59E0B', purple: '#8B5CF6', pink: '#F43F5E', cyan: '#06B6D4', red: '#EF4444', orange: '#F97316', navy: '#183C7A', slate: '#64748B' };

export default function LeavesPage() {
  const { can } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const requested = params.get('tab') ?? 'applications';
  const tab = TABS.some((t) => t.key === requested) ? requested : 'applications';
  const { data: s, isLoading, error } = useApi<Summary>(['leaves-summary'], 'leaves/summary');
  const { tableKey, tableRef, apply } = useTableFilters();
  const [form, setForm] = useState<{ open: boolean; id: number | null }>({ open: false, id: null });
  const [viewId, setViewId] = useState<number | null>(null);
  const [decision, setDecision] = useState<{ d: LeaveDecision; ids: number[]; subject?: string; clear?: () => void } | null>(null);
  const [policyOpen, setPolicyOpen] = useState(false);
  const canApprove = can('faculty', 'approve');
  const tabsRef = useRef<HTMLDivElement>(null);
  useEffect(() => scrollActiveTabIntoView(tabsRef.current), [tab]);

  const setTab = (k: string) => {
    const next = new URLSearchParams();
    if (k !== 'applications') next.set('tab', k);
    setParams(next, { replace: true });
  };
  const byType = (s?.by_type ?? []).filter((t) => t.days > 0);

  return (
    <>
      <PageHeader
        title="Leave Management"
        description={`Leave applications, approvals and balances of faculty and staff${s ? ` · session ${s.session.name}` : ''}.`}
        breadcrumbs={[{ label: 'Faculty & Staff' }, { label: 'Leave Management' }]}
        actions={
          <>
            <Button variant="secondary" icon={Settings2} onClick={() => setPolicyOpen(true)}>
              Leave policy
            </Button>
            {can('faculty', 'create') && (
              <Button icon={CalendarPlus} onClick={() => setForm({ open: true, id: null })}>
                Apply leave
              </Button>
            )}
          </>
        }
      />

      {error && <Alert variant="error" className="mb-4" title="Unable to load leave statistics">{(error as ApiError).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <button type="button" className="block w-full rounded-2xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500" onClick={() => apply({ status: 'pending' }, { tab: '' })} aria-label="Show pending applications">
          <StatCard label="Pending Approval" value={s?.pending ?? 0} icon={Hourglass} tone="amber" loading={isLoading} hint={s ? (s.pending_overdue ? `${s.pending_overdue} already started — act now` : 'Awaiting a decision') : undefined} />
        </button>
        <button type="button" className="block w-full rounded-2xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500" onClick={() => setTab('calendar')} aria-label="Open who is on leave">
          <StatCard label="On Leave Today" value={s?.on_leave_today ?? 0} icon={UserRound} tone="purple" loading={isLoading} hint={s ? `${s.upcoming_week} more starting this week` : undefined} />
        </button>
        <StatCard label="Leave Days This Month" value={s?.month_days ?? 0} icon={CalendarCheck2} tone="green" loading={isLoading} hint={s ? `${s.month_applications} approved application${s.month_applications === 1 ? '' : 's'}` : undefined} />
        <StatCard label="Session Total (days)" value={s?.session_days ?? 0} icon={Sigma} tone="navy" loading={isLoading} hint={s ? `${formatNumber(s.lop_days, s.lop_days % 1 ? 1 : 0)} LOP · ${s.session_rejected} rejected` : undefined} />
      </Stagger>

      <div ref={tabsRef} className="mb-4">
        <Tabs tabs={TABS} value={tab} onChange={setTab} variant="pills" />
      </div>

      {tab === 'applications' && (
        <>
          <div ref={tableRef} className="scroll-mt-20">
            <CrudTable
              key={tableKey}
              module="employee_leaves"
              urlState
              addLabel="Apply leave"
              emptyTitle="No leave applications"
              emptyText="Applications submitted by or for faculty and staff appear here for approval."
              onCreate={() => setForm({ open: true, id: null })}
              onView={(r) => setViewId(Number(r.id))}
              onEdit={(r) => (r.status === 'pending' ? setForm({ open: true, id: Number(r.id) }) : toast.warning(`Only pending applications can be edited (this one is ${r.status}).`))}
              renderers={{
                employee_name: (r) => {
                  const ref = parseRef(String(r.employee_ref));
                  return (
                    <div className="flex min-w-0 items-center gap-3">
                      <Avatar name={String(r.employee_name)} src={r.employee_photo} />
                      <div className="min-w-0 leading-tight">
                        {ref ? (
                          <Link to={profilePath(ref.type, ref.id)} className="whitespace-nowrap font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{r.employee_name}</Link>
                        ) : (
                          <span className="font-semibold">{r.employee_name}</span>
                        )}
                        <div className="truncate text-xs text-slate-500 dark:text-slate-400">{r.employee_sub}</div>
                      </div>
                    </div>
                  );
                },
                ...leaveCellRenderers,
              }}
              rowMenu={(r: Row) => {
                const ref = parseRef(String(r.employee_ref));
                return leaveRowMenu(
                  r,
                  (a) => can('faculty', a),
                  (d, row) => setDecision({ d, ids: [Number(row.id)], subject: `${row.employee_name} · ${row.leave_type_name} · ${formatDate(row.from_date)} – ${formatDate(row.to_date)} (${daysLabel(row.days)})` }),
                  ref ? [{ label: 'Open profile', icon: Eye, onClick: () => navigate(`${profilePath(ref.type, ref.id)}?tab=leaves`) }] : [],
                );
              }}
              bulkActions={
                canApprove
                  ? [
                      { label: 'Approve', icon: Check, onClick: (ids, clear) => setDecision({ d: 'approve', ids, clear }) },
                      { label: 'Reject', icon: X, danger: true, onClick: (ids, clear) => setDecision({ d: 'reject', ids, clear }) },
                    ]
                  : []
              }
            />
          </div>

          <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Reveal>
              <Card className="h-full">
                <CardHeader title="On leave today" subtitle={formatDate(new Date())} icon={CalendarClock} actions={<button type="button" onClick={() => setTab('calendar')} className="link text-xs">Calendar</button>} />
                <div className="p-2">
                  {isLoading ? (
                    <div className="space-y-3 p-3">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-10" />)}</div>
                  ) : s?.on_leave_list.length ? (
                    <ul className="max-h-80 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                      {s.on_leave_list.map((p) => (
                        <li key={`${p.ref}-${p.leave_type}`} className="flex items-center gap-3 px-3 py-2.5">
                          <Avatar name={p.name} src={p.photo} size="sm" />
                          <div className="min-w-0 flex-1">
                            <Link to={profilePath(p.employee_type, p.employee_id)} className="block truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{p.name}</Link>
                            <p className="truncate text-xs text-slate-500">{p.designation} · back after {formatDate(p.to_date)}</p>
                          </div>
                          <span className={clsx('badge shrink-0 ring-1 ring-inset', leaveChip[leaveTone(p.color)])}>{p.leave_type_name.replace(/ Leave$/, '').replace(/\s*\(.*\)$/, '')}</span>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <EmptyState icon={UserRound} title="Everyone is in today" description="No approved leave covers today." className="!py-8" />
                  )}
                </div>
              </Card>
            </Reveal>
            <Reveal delay={60}>
              <Card className="h-full">
                <CardHeader title="Leave days by type" subtitle={`Approved · session ${s?.session.name ?? ''}`} />
                <div className="p-5">
                  {isLoading ? (
                    <Skeleton className="h-44" />
                  ) : byType.length ? (
                    <DoughnutChart
                      labels={byType.map((t) => t.name.replace(/\s*\(.*\)$/, ''))}
                      data={byType.map((t) => t.days)}
                      colors={byType.map((t) => toneHex[leaveTone(t.color)] ?? '#64748B')}
                      centerValue={formatNumber(s?.session_days ?? 0)}
                      centerLabel="days"
                      height={160}
                      valueFormat="number"
                    />
                  ) : (
                    <p className="py-10 text-center text-sm text-slate-500">No approved leave this session yet.</p>
                  )}
                </div>
              </Card>
            </Reveal>
            <Reveal delay={120}>
              <Card className="h-full">
                <CardHeader title="Monthly trend" subtitle="Approved leave days" />
                <div className="p-4">
                  {isLoading ? (
                    <Skeleton className="h-56" />
                  ) : (
                    <BarChart
                      labels={(s?.trend ?? []).map((t) => t.label)}
                      series={[
                        { label: 'Faculty', data: (s?.trend ?? []).map((t) => t.faculty), color: '#183C7A' },
                        { label: 'Staff', data: (s?.trend ?? []).map((t) => t.staff), color: '#22943F' },
                      ]}
                      stacked
                      height={230}
                    />
                  )}
                </div>
              </Card>
            </Reveal>
          </div>
        </>
      )}

      {tab === 'calendar' && (
        <Reveal>
          <LeaveCalendar onOpen={setViewId} />
        </Reveal>
      )}
      {tab === 'balances' && (
        <Reveal>
          <LeaveBalancesTable />
        </Reveal>
      )}

      <LeaveFormModal open={form.open} leaveId={form.id} onClose={() => setForm({ open: false, id: null })} />
      <LeaveDetailModal
        id={viewId}
        onClose={() => setViewId(null)}
        onEdit={(id) => {
          setViewId(null);
          setForm({ open: true, id });
        }}
      />
      {decision && (
        <LeaveDecisionModal
          open
          decision={decision.d}
          ids={decision.ids}
          subject={decision.subject ?? `${decision.ids.length} selected application${decision.ids.length === 1 ? '' : 's'}`}
          onClose={() => setDecision(null)}
          onDone={() => decision.clear?.()}
        />
      )}
      <LeaveTypesModal open={policyOpen} onClose={() => setPolicyOpen(false)} />
    </>
  );
}
