import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { BadgeCheck, Ban, FileCheck2, MoreHorizontal, RefreshCw, RotateCcw, ShieldAlert, ShieldCheck, Trash2, UserPlus, Users } from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, DataTable, Dropdown, EmptyState, Field, Modal, Pagination, RadioGroup, SearchInput, Select, Textarea, useConfirm, useToast, type Column,
} from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { printUrl } from '@/lib/config';
import { useApi, useInvalidate } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { formatDateTime, formatMoney } from '@/lib/format';
import { Chip } from '../shared';
import type { ExamOverview, ExamStudentRow, ExamStudentsPayload } from '../types';

export function StudentsTab({ data }: { data: ExamOverview }) {
  const e = data.exam;
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const [view, setView] = useState<'allocated' | 'pending'>('allocated');
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 300);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [selected, setSelected] = useState<Set<string | number>>(new Set());
  const [busy, setBusy] = useState<string | null>(null);
  const [override, setOverride] = useState<ExamStudentRow | null>(null);
  const locked = ['verified', 'published', 'cancelled'].includes(e.status);
  const canEdit = data.permissions.edit && !locked;
  const query = { view, status: view === 'allocated' ? status : undefined, q: dq || undefined, page, per_page: perPage };
  const list = useApi<ExamStudentsPayload>(['exams', 'students', e.id, query], `exams/${e.id}/students`, query, { placeholderData: (p) => p });
  const d = list.data;
  const s = d?.summary;
  useEffect(() => {
    setPage(1);
    setSelected(new Set());
  }, [view, status, dq]);

  const run = async (key: string, fn: () => Promise<{ message: string }>) => {
    setBusy(key);
    try {
      const res = await fn();
      toast.success(res.message);
      setSelected(new Set());
      await invalidate('exams', 'crud');
    } catch (err) {
      toast.error((err as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const allocate = (ids?: number[]) => run('allocate', () => api.post(`exams/${e.id}/students/allocate`, ids ? { student_ids: ids } : {}));
  const refresh = () => run('refresh', () => api.post(`exams/${e.id}/students/refresh`));
  const remove = async (ids: number[], name?: string) => {
    const ok = await confirm({ title: 'Remove from exam?', message: name ? `Remove ${name} from this exam? The hall ticket is cancelled.` : `Remove ${ids.length} students from this exam? Their hall tickets are cancelled.`, confirmText: 'Remove', danger: true });
    if (ok) run('remove', () => api.post(`exams/${e.id}/students/remove`, { student_ids: ids }));
  };
  const clearOverride = async (r: ExamStudentRow) => {
    const ok = await confirm({ title: 'Clear override?', message: `Eligibility of ${r.name} will be recalculated from attendance and fee dues.`, confirmText: 'Clear override' });
    if (ok) run('clear', () => api.del(`exams/${e.id}/students/${r.student_id}/override`));
  };

  const min = s?.min_attendance ?? data.min_attendance;
  const columns: Column<ExamStudentRow>[] = [
    { key: 'name', header: 'Student', render: (r) => (
      <div className="flex min-w-0 items-center gap-3">
        <Avatar name={r.name} src={r.photo} />
        <div className="min-w-0 leading-tight">
          <p className="truncate font-semibold text-slate-900 dark:text-white">{r.name}</p>
          <p className="truncate text-xs text-slate-500">{r.roll_no ?? '—'} · {r.student_uid}</p>
        </div>
      </div>
    ) },
    { key: 'section_name', header: 'Section', render: (r) => r.section_name ?? '—' },
    { key: 'attendance_percent', header: 'Attendance', align: 'center', render: (r) =>
      r.attendance_percent === null ? <span className="text-xs text-slate-400">No record</span> : (
        <span className={clsx('font-semibold tabular-nums', min > 0 && r.attendance_percent < min ? 'text-red-600 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400')}>{r.attendance_percent.toFixed(1)}%</span>
      ) },
    { key: 'fee_due', header: 'Overdue fees', align: 'right', render: (r) => (r.fee_due > 0 ? <span className="font-medium text-red-600 dark:text-red-400">{formatMoney(r.fee_due)}</span> : <span className="text-xs text-slate-400">Nil</span>) },
    { key: 'is_eligible', header: 'Eligibility', render: (r) => (
      <div className="max-w-xs space-y-1">
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge color={r.is_eligible ? 'green' : 'red'} dot>{r.is_eligible ? 'Eligible' : 'Not eligible'}</Badge>
          {r.is_override && <Badge color="purple">Override</Badge>}
        </div>
        {(r.is_override ? r.override_reason : r.ineligibility_reason) && (
          <p className="text-xs leading-snug text-slate-500 dark:text-slate-400" title={r.is_override && r.overridden_at ? `${r.overridden_by_name ?? ''} · ${formatDateTime(r.overridden_at)}` : undefined}>
            {r.is_override ? r.override_reason : r.ineligibility_reason}
          </p>
        )}
      </div>
    ) },
    { key: 'hall_ticket_no', header: 'Hall ticket / seat', hidden: view === 'pending', render: (r) =>
      r.is_eligible && r.hall_ticket_no ? (
        <div className="leading-tight">
          <p className="font-mono text-xs font-semibold text-slate-800 dark:text-slate-100">{r.hall_ticket_no}</p>
          <p className="text-xs text-slate-500">{r.seat_no ? `Seat ${r.seat_no}` : 'Seat on exam day'}</p>
        </div>
      ) : <span className="text-xs text-slate-400">—</span> },
  ];

  const actions = (r: ExamStudentRow) => {
    if (view === 'pending') {
      return canEdit ? (
        <Button size="xs" variant="soft" icon={UserPlus} onClick={() => allocate([r.student_id])} loading={busy === 'allocate'}>
          Allocate
        </Button>
      ) : null;
    }
    return (
      <Dropdown
        label={`Actions for ${r.name}`}
        triggerClassName="btn-icon"
        trigger={<MoreHorizontal className="h-4 w-4" />}
        items={[
          r.is_eligible && { label: 'Print admit card', icon: FileCheck2, href: printUrl('admit-card.php', { exam_id: e.id, student_id: r.student_id }), target: '_blank' },
          canEdit && data.permissions.override && { label: r.is_eligible ? 'Debar (override)…' : 'Allow (override)…', icon: r.is_eligible ? Ban : ShieldCheck, onClick: () => setOverride(r) },
          canEdit && data.permissions.override && r.is_override && { label: 'Clear override', icon: RotateCcw, onClick: () => clearOverride(r) },
          canEdit && { label: 'divider', divider: true },
          canEdit && { label: 'Remove from exam', icon: Trash2, danger: true, onClick: () => remove([r.student_id], r.name), disabled: r.marks_count > 0, hint: r.marks_count ? 'Marks entered' : undefined },
        ]}
      />
    );
  };

  const ids = [...selected].map(Number);
  return (
    <div className="space-y-4">
      {locked && <Alert variant="info" title="Allocation is locked">Marks of this exam are verified. Student allocation and eligibility can no longer change.</Alert>}
      {s && s.pending > 0 && canEdit && view === 'allocated' && (
        <Alert
          variant="warning"
          title={`${s.pending} candidate${s.pending === 1 ? '' : 's'} not allocated yet`}
          action={
            <Button size="sm" icon={UserPlus} onClick={() => allocate()} loading={busy === 'allocate'}>
              Allocate all
            </Button>
          }
        >
          Students of {e.cohort} are checked for attendance (minimum {min}%){s.fee_block ? ' and overdue fees' : ''}. Ineligible students are recorded with the reason and can be allowed with an override.
        </Alert>
      )}
      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
          <div className="flex flex-wrap items-center gap-2">
            <Chip label="Allocated" value={s?.allocated ?? '—'} onClick={() => { setView('allocated'); setStatus(''); }} active={view === 'allocated' && !status} />
            <Chip label="Eligible" value={s?.eligible ?? '—'} tone="green" onClick={() => { setView('allocated'); setStatus('eligible'); }} active={view === 'allocated' && status === 'eligible'} />
            <Chip label="Not eligible" value={s?.ineligible ?? '—'} tone="red" onClick={() => { setView('allocated'); setStatus('ineligible'); }} active={view === 'allocated' && status === 'ineligible'} />
            <Chip label="Overrides" value={s?.overridden ?? '—'} tone="purple" onClick={() => { setView('allocated'); setStatus('override'); }} active={view === 'allocated' && status === 'override'} />
            <Chip label="Not allocated" value={s?.pending ?? '—'} tone="amber" onClick={() => setView('pending')} active={view === 'pending'} />
          </div>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <SearchInput value={q} onChange={setQ} placeholder="Search name, roll no, student ID…" className="sm:w-72" size="sm" />
            {view === 'allocated' && (
              <Select inputSize="sm" value={status} onChange={(ev) => setStatus(ev.target.value)} className="sm:!w-44" aria-label="Eligibility filter"
                options={[{ value: 'eligible', label: 'Eligible' }, { value: 'ineligible', label: 'Not eligible' }, { value: 'override', label: 'Overridden' }]} placeholder="All students" />
            )}
            <div className="flex flex-wrap gap-2 sm:ml-auto">
              {ids.length > 0 && view === 'pending' && canEdit && (
                <Button size="sm" icon={UserPlus} onClick={() => allocate(ids)} loading={busy === 'allocate'}>
                  Allocate {ids.length}
                </Button>
              )}
              {ids.length > 0 && view === 'allocated' && canEdit && (
                <Button size="sm" variant="danger" icon={Trash2} onClick={() => remove(ids)} loading={busy === 'remove'}>
                  Remove {ids.length}
                </Button>
              )}
              {ids.length > 0 && view === 'allocated' && (
                <Button size="sm" variant="secondary" icon={FileCheck2} href={printUrl('admit-card.php', { exam_id: e.id, ids: ids.join(',') })} target="_blank">
                  Admit cards ({ids.length})
                </Button>
              )}
              {canEdit && view === 'allocated' && !!s?.allocated && (
                <Button size="sm" variant="secondary" icon={RefreshCw} onClick={refresh} loading={busy === 'refresh'}>
                  Recheck eligibility
                </Button>
              )}
              {view === 'allocated' && !!s?.eligible && !ids.length && (
                <Button size="sm" variant="secondary" icon={FileCheck2} href={printUrl('admit-card.php', { exam_id: e.id })} target="_blank">
                  Print admit cards
                </Button>
              )}
            </div>
          </div>
        </div>
        {list.error ? (
          <div className="p-5">
            <Alert variant="error" title="Unable to load students">{(list.error as ApiError).message}</Alert>
          </div>
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={d?.rows ?? []}
              rowKey={(r) => r.student_id}
              loading={!d}
              selectable={canEdit || view === 'allocated'}
              selected={selected}
              onSelectedChange={setSelected}
              actions={actions}
              rowClassName={(r) => (!r.is_eligible ? 'bg-red-50/40 dark:bg-red-500/[.04]' : undefined)}
              empty={
                <EmptyState
                  icon={Users}
                  title={view === 'pending' ? 'Everyone is allocated' : dq || status ? 'No students match' : 'No students allocated'}
                  description={view === 'pending' ? 'All candidates of this class are allocated to the exam.' : dq || status ? 'Try another search or filter.' : `Allocate the students of ${e.cohort} — eligibility is checked automatically.`}
                  action={!dq && !status && view === 'allocated' && canEdit && s?.pending ? <Button icon={UserPlus} onClick={() => allocate()} loading={busy === 'allocate'}>Allocate {s.pending} students</Button> : undefined}
                />
              }
            />
            {d && d.total > 0 && <Pagination page={d.page} pages={d.pages} total={d.total} perPage={d.per_page} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} className="border-t border-slate-100 dark:border-slate-800" />}
          </>
        )}
      </Card>
      {override && <OverrideModal examId={e.id} row={override} onClose={() => setOverride(null)} />}
    </div>
  );
}

function OverrideModal({ examId, row, onClose }: { examId: number; row: ExamStudentRow; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const [eligible, setEligible] = useState(row.is_eligible ? '0' : '1');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post(`exams/${examId}/students/${row.student_id}/override`, { eligible, reason });
      toast.success(res.message);
      await invalidate('exams');
      onClose();
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      if (!Object.keys(ex.errors ?? {}).length) toast.error(ex.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open
      onClose={onClose}
      static={saving}
      title="Override eligibility"
      description={`${row.name} · ${row.roll_no ?? row.student_uid}`}
      icon={<span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300"><ShieldAlert className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button icon={BadgeCheck} loading={saving} onClick={save}>
            Save override
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {row.ineligibility_reason && !row.is_override && <Alert variant="warning" title="Current status">{row.ineligibility_reason}</Alert>}
        <Field label="Decision" required>
          <RadioGroup name="override" value={eligible} onChange={setEligible} options={[{ value: '1', label: 'Allow to write the exam' }, { value: '0', label: 'Not eligible (debar)' }]} />
        </Field>
        <Field label="Reason" required error={errors.reason} htmlFor="ov-reason" hint="Recorded in the audit log, e.g. medical certificate verified by HOD, condonation approved by the Principal.">
          <Textarea id="ov-reason" rows={3} value={reason} onChange={(ev) => setReason(ev.target.value)} invalid={!!errors.reason} maxLength={255} />
        </Field>
        {errors.eligible && <p className="text-sm text-red-600">{errors.eligible}</p>}
      </div>
    </Modal>
  );
}
