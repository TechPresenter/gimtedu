import { useEffect, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, CalendarClock, Check, CheckCircle2, Pencil, Sun, X, XCircle } from 'lucide-react';
import { Alert, Avatar, Button, DescriptionList, Field, Modal, PageLoader, StatusBadge, Textarea, Timeline, useToast, type DropdownItem } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Row } from '@/lib/types';
import { daysLabel, leaveChip, leaveTone, profilePath, type BalanceRow, type EmployeeType } from '../hr';

export type LeaveDecision = 'approve' | 'reject' | 'cancel';

const decisionCopy: Record<LeaveDecision, { title: string; verb: string; icon: typeof Check; variant: 'success' | 'danger' | 'secondary'; hint: string }> = {
  approve: { title: 'Approve leave', verb: 'Approve', icon: CheckCircle2, variant: 'success', hint: 'Optional note for the employee (e.g. substitute arrangement).' },
  reject: { title: 'Reject leave', verb: 'Reject', icon: XCircle, variant: 'danger', hint: 'The reason is shared with the employee.' },
  cancel: { title: 'Cancel leave', verb: 'Cancel leave', icon: Ban, variant: 'danger', hint: 'Cancelled leave is released back to the balance.' },
};

/** Invalidate every query that shows leave data. */
export function useRefreshLeaves() {
  const qc = useQueryClient();
  return () =>
    Promise.all(
      [['crud', 'employee_leaves'], ['leaves-summary'], ['leave-calendar'], ['leave-balance'], ['leave-balance-ref'], ['leave-balances'], ['hr-profile'], ['leave-detail'], ['hr-activity']].map((k) =>
        qc.invalidateQueries({ queryKey: k }),
      ),
    );
}

interface DecisionProps {
  open: boolean;
  onClose: () => void;
  decision: LeaveDecision;
  /** One id = single endpoint; several = bulk (approve / reject only) */
  ids: number[];
  subject?: ReactNode;
  onDone?: () => void;
}

/** Approve / reject / cancel with remarks (single or bulk). */
export function LeaveDecisionModal({ open, onClose, decision, ids, subject, onDone }: DecisionProps) {
  const toast = useToast();
  const refresh = useRefreshLeaves();
  const [remarks, setRemarks] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (open) {
      setRemarks('');
      setError(null);
    }
  }, [open]);
  const copy = decisionCopy[decision];
  const submit = async () => {
    if (decision === 'reject' && !remarks.trim()) {
      setError('Give a reason for rejecting.');
      return;
    }
    setSaving(true);
    try {
      const res =
        ids.length === 1
          ? await api.post(`leaves/${ids[0]}/${decision}`, { remarks: remarks.trim() || undefined })
          : await api.post<{ skipped: Record<string, string> }>('crud/employee_leaves/bulk', { action: decision, ids, remarks: remarks.trim() || undefined });
      const skipped = ids.length > 1 ? Object.keys((res.data as { skipped?: Record<string, string> })?.skipped ?? {}).length : 0;
      if (skipped) toast.warning(res.message);
      else toast.success(res.message);
      await refresh();
      onDone?.();
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.remarks ?? err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="md"
      title={ids.length > 1 ? `${copy.verb} ${ids.length} applications` : copy.title}
      description={subject}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Close
          </Button>
          <Button variant={copy.variant} icon={copy.icon} loading={saving} onClick={submit}>
            {copy.verb}
          </Button>
        </>
      }
    >
      <Field label={decision === 'reject' ? 'Reason for rejection' : 'Remarks'} required={decision === 'reject'} htmlFor="lv-remarks" error={error} hint={copy.hint}>
        <Textarea id="lv-remarks" rows={3} maxLength={255} value={remarks} onChange={(e) => setRemarks(e.target.value)} invalid={!!error} autoFocus />
      </Field>
    </Modal>
  );
}

interface DetailPayload {
  leave: Row;
  employee: { name: string; code: string; designation: string; photo: string | null; department: string | null; status: string; type: EmployeeType; id: number; email: string | null; phone: string | null } | null;
  balance: BalanceRow | null;
  working: { days: number; calendar_days: number; weekly_offs: number; holidays: { date: string; title: string }[] };
  history: { action: string; description: string; created_at: string; user_name: string | null }[];
  can: { approve: boolean; edit: boolean; cancel: boolean; delete: boolean };
}

/** Full details of an application with approve / reject / cancel / edit actions. */
export function LeaveDetailModal({ id, onClose, onEdit }: { id: number | null; onClose: () => void; onEdit?: (id: number) => void }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['leave-detail', id],
    queryFn: () => api.get<DetailPayload>(`leaves/${id}`),
    enabled: !!id,
  });
  const [decision, setDecision] = useState<LeaveDecision | null>(null);
  const l = data?.leave;
  const tone = leaveTone(l?.leave_type_color ?? data?.balance?.color);
  return (
    <>
      <Modal
        open={!!id && !decision}
        onClose={onClose}
        size="lg"
        title="Leave application"
        description={l ? `Applied ${formatDateTime(l.created_at)}${l.applied_by_name ? ` by ${l.applied_by_name}` : ''}` : undefined}
        footer={
          data ? (
            <>
              <Button variant="secondary" onClick={onClose}>
                Close
              </Button>
              {data.can.edit && onEdit && (
                <Button variant="secondary" icon={Pencil} onClick={() => onEdit(Number(l?.id))}>
                  Edit
                </Button>
              )}
              {data.can.cancel && (
                <Button variant="secondary" icon={Ban} onClick={() => setDecision('cancel')}>
                  Cancel leave
                </Button>
              )}
              {data.can.approve && (
                <>
                  <Button variant="danger" icon={X} onClick={() => setDecision('reject')}>
                    Reject
                  </Button>
                  <Button variant="success" icon={Check} onClick={() => setDecision('approve')}>
                    Approve
                  </Button>
                </>
              )}
            </>
          ) : undefined
        }
      >
        {isLoading ? (
          <PageLoader label="Loading application…" />
        ) : error || !data || !l ? (
          <Alert variant="error">{(error as ApiError)?.message ?? 'Unable to load this application.'}</Alert>
        ) : (
          <div className="space-y-5">
            <div className="flex flex-wrap items-center gap-4 rounded-xl border border-slate-100 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-800/40">
              <Avatar name={data.employee?.name} src={data.employee?.photo} size="lg" />
              <div className="min-w-0 flex-1">
                {data.employee ? (
                  <Link to={profilePath(data.employee.type, data.employee.id)} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                    {data.employee.name}
                  </Link>
                ) : (
                  <span className="font-semibold">Employee removed</span>
                )}
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  {data.employee?.code} · {data.employee?.designation}
                  {data.employee?.department ? ` · ${data.employee.department}` : ''}
                </p>
              </div>
              <StatusBadge status={String(l.status)} />
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className={clsx('rounded-xl px-4 py-3 ring-1 ring-inset', leaveChip[tone])}>
                <p className="text-[11px] font-semibold uppercase tracking-wide opacity-75">Leave type</p>
                <p className="mt-0.5 font-semibold">{l.leave_type_name}</p>
              </div>
              <div className="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/50 dark:ring-slate-700">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Duration</p>
                <p className="mt-0.5 font-semibold text-slate-900 dark:text-white">{daysLabel(l.days)}{Number(l.is_half_day) ? ' (half day)' : ''}</p>
              </div>
              <div className="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/50 dark:ring-slate-700">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Balance ({data.balance?.name ?? 'type'})</p>
                <p className="mt-0.5 font-semibold text-slate-900 dark:text-white">
                  {!data.balance ? '—' : data.balance.unlimited ? 'No fixed quota' : `${data.balance.available} of ${data.balance.quota} left`}
                </p>
              </div>
            </div>

            <DescriptionList
              items={[
                { label: 'From', value: formatDate(l.from_date) },
                { label: 'To', value: formatDate(l.to_date) },
                { label: 'Calendar days', value: `${data.working.calendar_days} (${data.working.weekly_offs} weekly off${data.working.weekly_offs === 1 ? '' : 's'}, ${data.working.holidays.length} holiday${data.working.holidays.length === 1 ? '' : 's'})` },
                { label: 'Paid leave', value: Number(l.is_paid ?? 1) ? 'Yes' : 'No — loss of pay' },
                { label: 'Reason', value: l.reason, full: true },
                ...(l.approved_at ? [{ label: l.status === 'rejected' ? 'Rejected by' : 'Approved by', value: `${l.approved_by_name ?? '—'} · ${formatDateTime(l.approved_at)}` }] : []),
                ...(l.cancelled_at ? [{ label: 'Cancelled on', value: formatDateTime(l.cancelled_at) }] : []),
                ...(l.remarks ? [{ label: 'Remarks', value: l.remarks, full: true }] : []),
              ]}
            />
            {data.working.holidays.length > 0 && (
              <div className="flex flex-wrap gap-1.5">
                {data.working.holidays.map((h) => (
                  <span key={h.date} className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
                    <Sun className="h-3 w-3" /> {formatDate(h.date)} · {h.title}
                  </span>
                ))}
              </div>
            )}
            <div>
              <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">History</h3>
              <Timeline
                empty="No history recorded."
                items={data.history.map((h) => ({
                  icon: h.action === 'approve' ? CheckCircle2 : h.action === 'reject' ? XCircle : CalendarClock,
                  tone: h.action === 'approve' ? 'green' : h.action === 'reject' ? 'red' : 'blue',
                  title: h.description,
                  time: `${h.user_name ?? 'System'} · ${formatDateTime(h.created_at)}`,
                }))}
              />
            </div>
          </div>
        )}
      </Modal>
      {decision && l && (
        <LeaveDecisionModal
          open
          decision={decision}
          ids={[Number(l.id)]}
          subject={`${data?.employee?.name ?? ''} · ${l.leave_type_name} · ${formatDate(l.from_date)} – ${formatDate(l.to_date)}`}
          onClose={() => setDecision(null)}
          onDone={onClose}
        />
      )}
    </>
  );
}

/** Row menu items for a leave row (used by every leave table). */
export function leaveRowMenu(row: Row, can: (a: 'approve' | 'edit') => boolean, open: (d: LeaveDecision, row: Row) => void, extra: DropdownItem[] = []): DropdownItem[] {
  const items: DropdownItem[] = [];
  if (row.status === 'pending' && can('approve')) {
    items.push({ label: 'Approve', icon: Check, onClick: () => open('approve', row) });
    items.push({ label: 'Reject', icon: X, danger: true, onClick: () => open('reject', row) });
  }
  const today = new Date().toISOString().slice(0, 10);
  if (can('edit') && (row.status === 'pending' || (row.status === 'approved' && String(row.to_date) >= today))) {
    items.push({ label: 'Cancel leave', icon: Ban, onClick: () => open('cancel', row) });
  }
  return [...items, ...extra];
}

/** Shared cell renderers for leave tables (type chip, period range, days, reason). */
export const leaveCellRenderers: Record<string, (r: Row) => ReactNode> = {
  leave_type_name: (r) => (
    <div className="min-w-[10rem] leading-tight">
      <span className={clsx('badge whitespace-nowrap ring-1 ring-inset', leaveChip[leaveTone(r.leave_type_color)])} title={String(r.leave_type_name ?? '')}>
        {String(r.leave_type_name ?? r.leave_type).replace(/\s*\(.*\)$/, '')}
      </span>
      <p className="mt-1 max-w-[16rem] truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.reason ?? '')}>{r.reason || '—'}</p>
    </div>
  ),
  from_date: (r) => {
    const same = r.from_date === r.to_date;
    const sameYear = String(r.from_date).slice(0, 4) === String(r.to_date).slice(0, 4);
    const short = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
    return (
      <span className="whitespace-nowrap">
        {same ? formatDate(r.from_date) : sameYear ? `${short(r.from_date)} – ${formatDate(r.to_date)}` : `${formatDate(r.from_date)} – ${formatDate(r.to_date)}`}
      </span>
    );
  },
  days: (r) => <span className="whitespace-nowrap tabular-nums" title={Number(r.is_half_day) ? 'Half day' : undefined}>{daysLabel(r.days)}</span>,
  reason: (r) => <span className="block max-w-[12rem] truncate 2xl:max-w-[18rem] text-slate-600 dark:text-slate-300" title={String(r.reason ?? '')}>{r.reason || '—'}</span>,
};
