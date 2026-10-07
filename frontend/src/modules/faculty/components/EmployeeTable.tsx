import { useEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { CalendarPlus, Eye, KeyRound, Printer } from 'lucide-react';
import { CrudTable, type CrudBulkAction } from '@/components/crud';
import { Avatar, StatusBadge } from '@/components/ui';
import { formatDate, labelize } from '@/lib/format';
import { Link } from 'react-router-dom';
import { useAuth } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import type { Row } from '@/lib/types';
import { employeeRef, profilePath, STAFF_CATEGORIES, type EmployeeType, type NewAccount } from '../hr';

const categoryDot: Record<string, string> = {
  administration: 'bg-brand-700', accounts: 'bg-emerald-500', library: 'bg-violet-500', hostel: 'bg-amber-500', transport: 'bg-cyan-500',
  maintenance: 'bg-slate-400', security: 'bg-red-500', it: 'bg-blue-500', laboratory: 'bg-fuchsia-500', other: 'bg-slate-400',
};
import { CreateAccountModal, CredentialsModal } from './AccountModals';
import { LeaveFormModal } from './LeaveFormModal';

/**
 * Programmatic filtering of a urlState CrudTable: writes f.* params to the URL then remounts the table so it picks
 * them up (CrudTable reads URL filters on mount).
 */
export function useTableFilters() {
  const [params, setParams] = useSearchParams();
  const [tableKey, setTableKey] = useState(0);
  const pending = useRef(false);
  const tableRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (pending.current) {
      pending.current = false;
      setTableKey((k) => k + 1);
      requestAnimationFrame(() => tableRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    }
  }, [params]);
  const apply = (filters: Record<string, string | number | null>, extra: Record<string, string> = {}) => {
    pending.current = true;
    const next = new URLSearchParams(params);
    [...next.keys()].filter((k) => k.startsWith('f.') || k === 'page' || k === 'q').forEach((k) => next.delete(k));
    Object.entries(filters).forEach(([k, v]) => v !== null && v !== '' && next.set(`f.${k}`, String(v)));
    Object.entries(extra).forEach(([k, v]) => (v === '' ? next.delete(k) : next.set(k, v)));
    setParams(next, { replace: true });
  };
  const active = (key: string) => params.get(`f.${key}`);
  return { tableKey, tableRef, apply, active };
}

interface Props {
  type: EmployeeType;
  module: string;
  tableKey: number;
  tableRef: RefObject<HTMLDivElement>;
  header?: (d: { total: number; summary?: Record<string, unknown> | null }) => ReactNode;
  bulkActions?: CrudBulkAction[];
  onChanged?: () => void;
}

/** CRUD table of faculty / staff with profile, print, leave and login actions. */
export function EmployeeTable({ type, module, tableKey, tableRef, header, bulkActions, onChanged }: Props) {
  const { can } = useAuth();
  const navigate = useNavigate();
  const [account, setAccount] = useState<{ acc: NewAccount; name: string } | null>(null);
  const [leaveFor, setLeaveFor] = useState<{ ref: string; label: string; sub?: string } | null>(null);
  const [loginFor, setLoginFor] = useState<Row | null>(null);
  return (
    <div ref={tableRef} className="scroll-mt-20">
      <CrudTable
        key={tableKey}
        module={module}
        urlState
        viewTo={(r) => profilePath(type, r.id)}
        header={header}
        renderers={{
          full_name: (r) => (
            <div className="flex min-w-[13rem] items-center gap-3">
              <Avatar name={String(r.full_name)} src={r.photo} />
              <div className="min-w-0 leading-tight">
                <Link to={profilePath(type, r.id)} className="whitespace-nowrap font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                  {r.full_name}
                </Link>
                <div className="flex items-center gap-1.5 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                  {r.employee_sub}
                  {Number(r.on_leave_today) > 0 && <span className="rounded bg-amber-100 px-1 text-[10px] font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-200">On leave</span>}
                </div>
              </div>
            </div>
          ),
          designation: (r) => (
            <div className="min-w-[9rem] leading-tight">
              <p className="whitespace-nowrap font-medium text-slate-800 dark:text-slate-100">{r.designation}</p>
              {type === 'faculty' ? (
                <p className="max-w-[11rem] truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.qualification ?? '')}>{r.qualification || '—'}</p>
              ) : (
                <p className="flex max-w-[11rem] items-center gap-1.5 truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.section ?? '')}>
                  <span className={clsx('h-1.5 w-1.5 shrink-0 rounded-full', categoryDot[String(r.category)] ?? 'bg-slate-400')} />
                  <span className="truncate">{STAFF_CATEGORIES[String(r.category)] ?? labelize(String(r.category))}{r.section ? ` · ${r.section}` : ''}</span>
                </p>
              )}
            </div>
          ),
          qualification: (r) => <span className="block max-w-[10rem] truncate" title={String(r.qualification ?? '')}>{r.qualification || '—'}</span>,
          phone: (r) => (
            <div className="leading-tight">
              {r.phone ? <a href={`tel:${String(r.phone).replace(/\s/g, '')}`} className="whitespace-nowrap text-slate-800 hover:text-brand-700 dark:text-slate-100">{r.phone}</a> : <span className="text-slate-400">—</span>}
              {r.email && <a href={`mailto:${r.email}`} className="block max-w-[12rem] truncate text-xs text-slate-500 hover:text-brand-700 dark:text-slate-400">{r.email}</a>}
            </div>
          ),
          employment_type: (r) => (
            <div className="leading-tight">
              <StatusBadge status={String(r.employment_type)} label={labelize(String(r.employment_type))} colors={{ permanent: 'navy', contract: 'cyan', probation: 'amber', visiting: 'purple', guest: 'slate', outsourced: 'purple', part_time: 'slate' }} />
              {r.joining_date && <p className="mt-1 whitespace-nowrap text-[11px] text-slate-500">Since {formatDate(r.joining_date)}</p>}
            </div>
          ),
        }}
        bulkActions={bulkActions}
        addLabel={type === 'faculty' ? 'Add Faculty' : 'Add Staff'}
        emptyText={type === 'faculty' ? 'Add teaching faculty with their department, designation and qualifications.' : 'Add non-teaching staff for administration, accounts, library and support services.'}
        onSaved={(row) => {
          if (row?.new_account) setAccount({ acc: row.new_account as NewAccount, name: String(row.full_name ?? '') });
          onChanged?.();
        }}
        rowMenu={(r) => [
          { label: 'Open profile', icon: Eye, onClick: () => navigate(profilePath(type, r.id)) },
          { label: 'Print profile', icon: Printer, href: printUrl('faculty-profile.php', { id: r.id, type }), target: '_blank' },
          can('faculty', 'create') && ['active', 'on_leave'].includes(String(r.status)) && {
            label: 'Apply leave', icon: CalendarPlus,
            onClick: () => setLeaveFor({ ref: employeeRef(type, r.id), label: `${r.full_name} (${r.employee_id})`, sub: String(r.designation ?? '') }),
          },
          can('faculty', 'edit') && !r.user_id && ['active', 'on_leave'].includes(String(r.status)) && { label: 'Create login account', icon: KeyRound, onClick: () => setLoginFor(r) },
        ]}
      />
      <CredentialsModal account={account?.acc ?? null} name={account?.name} onClose={() => setAccount(null)} />
      <LeaveFormModal open={!!leaveFor} preset={leaveFor} onClose={() => setLeaveFor(null)} onSaved={onChanged} />
      {loginFor && (
        <CreateAccountModal
          open
          type={type}
          id={Number(loginFor.id)}
          name={String(loginFor.full_name)}
          email={(loginFor.email as string) ?? null}
          onClose={() => setLoginFor(null)}
          onCreated={(acc) => {
            setAccount({ acc, name: String(loginFor.full_name) });
            onChanged?.();
          }}
        />
      )}
    </div>
  );
}
