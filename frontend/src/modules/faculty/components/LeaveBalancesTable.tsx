import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { Scale } from 'lucide-react';
import { Alert, Card, DataTable, EmptyState, Pagination, PersonCell, SearchInput, Select, toneClasses, type Column } from '@/components/ui';
import { useApi, useLookup } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { formatNumber } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { leaveTone, profilePath, type EmployeeType, type LeaveType, type SessionWindow } from '../hr';

interface Bal {
  used: number;
  pending: number;
  quota: number;
  available: number | null;
}
interface PersonRow {
  ref: string;
  employee_type: EmployeeType;
  id: number;
  name: string;
  code: string;
  photo: string | null;
  designation: string;
  department_code: string | null;
  balances: Record<string, Bal | null>;
  total_used: number;
}
interface Payload {
  rows: PersonRow[];
  types: LeaveType[];
  session: SessionWindow;
  total: number;
  page: number;
  per_page: number;
  pages: number;
}

const n = (v: number) => formatNumber(v, v % 1 ? 1 : 0);
const shortType = (name: string) =>
  ({ 'Leave Without Pay': 'LWP', 'Compensatory Off': 'Comp. off', 'On Duty (OD)': 'On duty' } as Record<string, string>)[name] ?? name.replace(/\s*\(.*\)$/, '').replace(/ Leave$/, '');

/** Balance summary per person and leave type (server-side pagination). */
export function LeaveBalancesTable() {
  const [q, setQ] = useState('');
  const [type, setType] = useState('');
  const [dept, setDept] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const dq = useDebounce(q, 350);
  useEffect(() => setPage(1), [dq, type, dept, perPage]);
  const { data: depts = [] } = useLookup('departments');
  const query = { q: dq || undefined, employee_type: type || undefined, department_id: dept || undefined, page, per_page: perPage };
  const { data, isLoading, isFetching, error } = useApi<Payload>(['leave-balances', query], 'leaves/balances', query, { placeholderData: (prev) => prev });
  const types = (data?.types ?? []).filter((t) => t.annual_quota > 0 && t.annual_quota < 100 && !t.gender);
  const unlimited = (data?.types ?? []).filter((t) => t.annual_quota <= 0);

  const columns: Column<PersonRow>[] = [
    {
      key: 'name', header: 'Employee',
      render: (r) => <PersonCell name={r.name} src={r.photo} sub={`${r.code} · ${r.department_code ?? (r.employee_type === 'faculty' ? 'Faculty' : 'Staff')}`} to={profilePath(r.employee_type, r.id)} />,
    },
    ...types.map<Column<PersonRow>>((t) => ({
      key: t.code,
      header: <span title={t.name}>{shortType(t.name)}</span>,
      render: (r) => {
        const b = r.balances[t.code];
        if (!b) return <span className="text-xs text-slate-400">n/a</span>;
        const pct = b.quota ? Math.min(100, (b.used / b.quota) * 100) : 0;
        const low = b.available !== null && b.available <= Math.max(1, b.quota * 0.15);
        return (
          <div className="min-w-[4.5rem]">
            <div className="flex items-baseline gap-1 text-xs">
              <span className={clsx('font-semibold tabular-nums', low ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white')}>{n(b.used)}</span>
              <span className="text-slate-400">/ {n(b.quota)}</span>
              {b.pending > 0 && <span className="text-[10px] text-amber-600 dark:text-amber-400">+{n(b.pending)}</span>}
            </div>
            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
              <div className={clsx('h-full rounded-full', toneClasses[leaveTone(t.color)].bar)} style={{ width: `${pct}%` }} />
            </div>
          </div>
        );
      },
    })),
    ...unlimited.map<Column<PersonRow>>((t) => ({
      key: t.code,
      header: <span title={t.name}>{shortType(t.name)}</span>,
      align: 'center',
      render: (r) => {
        const b = r.balances[t.code];
        return b ? <span className="tabular-nums text-xs font-semibold">{n(b.used)}</span> : <span className="text-xs text-slate-400">n/a</span>;
      },
    })),
    { key: 'total', header: 'Total used', align: 'right', render: (r) => <span className="font-display font-bold tabular-nums">{n(r.total_used)}</span> },
  ];

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800 lg:flex-row lg:items-center">
        <div>
          <h2 className="card-title">Leave balances</h2>
          <p className="card-subtitle">Used / quota per leave type for session {data?.session.name ?? ''} · amber = pending</p>
        </div>
        <div className="flex flex-wrap items-center gap-2 lg:ml-auto">
          <SearchInput value={q} onChange={setQ} placeholder="Search name or employee ID…" className="w-full sm:w-64" size="sm" />
          <Select inputSize="sm" value={type} onChange={(e) => setType(e.target.value)} options={[{ value: 'faculty', label: 'Faculty' }, { value: 'staff', label: 'Staff' }]} placeholder="Everyone" aria-label="Employee type" className="!w-32" />
          <Select inputSize="sm" value={dept} onChange={(e) => setDept(e.target.value)} options={depts.map((d) => ({ value: String(d.value), label: d.sub ? `${d.sub} — ${d.label}` : d.label }))} placeholder="All departments" aria-label="Department" className="!w-48" />
        </div>
      </div>
      {error ? (
        <div className="p-5"><Alert variant="error">{(error as ApiError).message}</Alert></div>
      ) : (
        <DataTable<PersonRow>
          columns={columns}
          rows={data?.rows ?? []}
          rowKey={(r) => r.ref}
          loading={isLoading || isFetching}
          skeletonRows={8}
          caption="Leave balances"
          empty={<EmptyState icon={Scale} title="No employees match" description="Change the search or filters." />}
        />
      )}
      {data && data.total > 0 && (
        <Pagination className="border-t border-slate-100 dark:border-slate-800" page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} onPerPage={setPerPage} />
      )}
      <p className="border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500 dark:border-slate-800">
        Quotas come from the leave policy (Leave policy button above). Leave without pay and on-duty leave have no fixed quota.
      </p>
    </Card>
  );
}
