import { useEffect, useMemo, useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import { ArrowRightLeft, BedDouble, Building2, LogOut, ReceiptIndianRupee, UserPlus, XCircle } from 'lucide-react';
import { Alert, Avatar, Badge, Button, Combobox, Field, Input, Modal, Skeleton, Textarea, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, isoDate, labelize, toNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { BedPicker, type BedChoice } from './BedPicker';
import { HOSTEL_TYPES, HostelTypeBadge, OccupancyBar, type GridRoom, type HostelStat } from './shared';

export interface StudentSummary {
  id: number;
  name: string;
  student_uid: string;
  gender: string | null;
  photo: string | null;
  mobile: string | null;
  status: string;
  current_semester: number | null;
  program_short: string | null;
  program_name: string | null;
  department_name: string | null;
  city: string | null;
  active_allocation: { id: number; allocation_no: string; hostel_code: string; room_no: string; bed_no: string | null } | null;
  history: { id: number; allocation_no: string; allocated_on: string; vacated_on: string | null; status: string; hostel_code: string; room_no: string; bed_no: string | null; session_name: string | null }[];
  eligible_hostels: HostelStat[];
}

/** Refresh every hostel query + the allocation / room / bed CRUD tables after an action. */
export function useHostelRefresh() {
  const qc = useQueryClient();
  return () =>
    qc.invalidateQueries({
      predicate: (q) => {
        const [k, sub] = q.queryKey as unknown[];
        if (k === 'hostel') return true;
        if (k === 'crud' || k === 'crud-record') return String(sub ?? '').startsWith('hostel');
        if (k === 'combobox') return String(sub ?? '').includes('hostel');
        return false;
      },
    });
}

function StudentCard({ s }: { s: StudentSummary }) {
  return (
    <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50/60 p-3 dark:border-slate-700 dark:bg-slate-800/40">
      <Avatar name={s.name} src={s.photo} size="lg" />
      <div className="min-w-0 flex-1">
        <p className="font-semibold text-slate-900 dark:text-white">{s.name}</p>
        <p className="text-xs text-slate-500 dark:text-slate-400">
          {s.student_uid} · {[s.program_short, s.current_semester ? `Sem ${s.current_semester}` : null, s.city].filter(Boolean).join(' · ')}
        </p>
        <div className="mt-1.5 flex flex-wrap gap-1.5">
          <Badge color={s.gender === 'female' ? 'purple' : s.gender === 'male' ? 'blue' : 'slate'}>{labelize(s.gender ?? 'Gender not set')}</Badge>
          {s.history.length > 0 && <Badge color="slate">{s.history.length} previous stay{s.history.length === 1 ? '' : 's'}</Badge>}
        </div>
      </div>
    </div>
  );
}

function HostelChoice({ hostels, value, onChange, gender, error }: { hostels: HostelStat[]; value: number | null; onChange: (id: number) => void; gender?: string | null; error?: string }) {
  if (!hostels.length) {
    return (
      <Alert variant="warning" title="No eligible hostel">
        There is no active {gender === 'female' ? 'girls' : gender === 'male' ? 'boys' : ''} hostel for this student. Add one under Hostels &amp; Rooms.
      </Alert>
    );
  }
  return (
    <div>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3" role="radiogroup" aria-label="Hostel">
        {hostels.map((h) => {
          const active = h.id === value;
          return (
            <button
              key={h.id}
              type="button"
              role="radio"
              aria-checked={active}
              disabled={h.available <= 0}
              onClick={() => onChange(h.id)}
              className={clsx(
                'rounded-xl border p-3 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-60',
                active ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-500/30 dark:border-brand-400 dark:bg-brand-500/15' : 'border-slate-200 hover:border-brand-300 dark:border-slate-700',
              )}
            >
              <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white" title={h.name}>{h.name}</span>
              <div className="mt-1 flex items-center justify-between gap-2">
                <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                  {h.available} free of {h.beds} beds · {h.occupancy_pct}% full
                </p>
                <HostelTypeBadge type={h.type} />
              </div>
              <OccupancyBar occupied={h.occupied} available={h.available} maintenance={h.maintenance} className="mt-2" />
            </button>
          );
        })}
      </div>
      {error && <p className="form-error">{error}</p>}
    </div>
  );
}

/* ================================================================== allocate */

export interface AllocateDefaults {
  student_id?: number;
  hostel_id?: number;
  room_id?: number;
  bed_id?: number;
}

export function AllocateModal({ open, onClose, defaults, onDone }: { open: boolean; onClose: () => void; defaults?: AllocateDefaults | null; onDone?: (id: number) => void }) {
  const toast = useToast();
  const refresh = useHostelRefresh();
  const { can } = useAuth();
  const [studentId, setStudentId] = useState<number | null>(null);
  const [hostelId, setHostelId] = useState<number | null>(null);
  const [bed, setBed] = useState<BedChoice>({ room_id: null, bed_id: null });
  const [from, setFrom] = useState(isoDate());
  const [until, setUntil] = useState('');
  const [rent, setRent] = useState('');
  const [remarks, setRemarks] = useState('');
  const [invoice, setInvoice] = useState(true);
  const [mess, setMess] = useState(true);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setStudentId(defaults?.student_id ?? null);
    setHostelId(defaults?.hostel_id ?? null);
    setBed({ room_id: defaults?.room_id ?? null, bed_id: defaults?.bed_id ?? null });
    setFrom(isoDate());
    setUntil('');
    setRent('');
    setRemarks('');
    setInvoice(true);
    setMess(true);
    setErrors({});
    setFormError(null);
  }, [open, defaults]);

  const { data: student, isFetching: loadingStudent } = useApi<StudentSummary>(['hostel', 'student', studentId], `hostel/students/${studentId}`, undefined, { enabled: open && !!studentId });
  const hostels = useMemo(() => student?.eligible_hostels ?? [], [student]);
  const lockedHostel = !!defaults?.hostel_id && !!defaults?.bed_id;
  const hostel = hostels.find((h) => h.id === hostelId) ?? null;

  useEffect(() => {
    // pick the first eligible hostel when the student changes (unless the bed was preselected)
    if (!student || lockedHostel) return;
    if (!hostels.some((h) => h.id === hostelId)) {
      const first = hostels.find((h) => h.available > 0);
      setHostelId(first?.id ?? null);
      setBed({ room_id: null, bed_id: null });
      // the hostel was picked for the user: drop a stale "Select a hostel" error from an earlier submit
      if (first) setErrors((e) => ({ ...e, hostel_id: '' }));
    }
  }, [student, hostels, hostelId, lockedHostel]);

  // clear the summary banner once every field it complained about has been filled in
  useEffect(() => {
    if (formError && studentId && hostelId && bed.room_id && bed.bed_id && !Object.values(errors).some(Boolean)) setFormError(null);
  }, [formError, studentId, hostelId, bed, errors]);

  const genderMismatch = lockedHostel && student && hostelId && !hostels.some((h) => h.id === hostelId);
  const onBed = (v: BedChoice, room: GridRoom | null) => {
    setBed(v);
    if (room) setRent(room.annual_fee !== null ? String(room.annual_fee) : hostel?.annual_fee ? String(hostel.annual_fee) : '');
    setErrors((e) => ({ ...e, room_id: '', bed_id: '', hostel_id: '' }));
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const errs: Record<string, string> = {};
    if (!studentId) errs.student_id = 'Select a student.';
    if (!hostelId) errs.hostel_id = 'Select a hostel.';
    if (!bed.room_id) errs.room_id = 'Select a room with a free bed.';
    else if (!bed.bed_id) errs.bed_id = 'Select a free bed.';
    if (!from) errs.allocated_on = 'Allotment date is required.';
    if (Object.keys(errs).length) {
      setErrors(errs);
      setFormError('Please complete the highlighted fields.');
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const res = await api.post<{ id: number }>('crud/hostel_allocations', {
        student_id: studentId, hostel_id: hostelId, room_id: bed.room_id, bed_id: bed.bed_id, allocated_on: from, valid_until: until || null,
        fee_amount: rent === '' ? null : rent, remarks: remarks || null, raise_invoice: invoice && can('hostel', 'edit') ? 1 : 0, include_mess: mess ? 1 : 0,
      });
      toast.success(invoice ? `${student?.name ?? 'Student'} allotted a bed and the hostel fee invoice was raised.` : `${student?.name ?? 'Student'} allotted a bed successfully.`);
      await refresh();
      onDone?.(res.data.id);
      onClose();
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      setFormError(ae.message || 'Unable to allot the room. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const total = toNumber(rent) + (mess ? toNumber(hostel?.mess_fee) : 0);
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="xl"
      title="Allot hostel room"
      description="Student → hostel → room → free bed. Only beds that are free right now can be selected."
      icon={<span className="kpi-icon bg-accent-100 text-accent-700 dark:bg-accent-500/20 dark:text-accent-200"><UserPlus className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button type="submit" form="hostel-allocate-form" loading={saving} icon={BedDouble} disabled={!!student?.active_allocation || !!genderMismatch}>
            Allot bed
          </Button>
        </>
      }
    >
      <form id="hostel-allocate-form" onSubmit={submit} noValidate className="space-y-5">
        {formError && <Alert variant="error">{formError}</Alert>}
        <section className="space-y-3">
          <h3 className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">1 · Student</h3>
          <Field label="Student" required htmlFor="alloc-student" error={errors.student_id} hint="Active students who do not have a room yet.">
            <Combobox
              id="alloc-student"
              value={studentId}
              onChange={(v) => {
                setStudentId(v ? Number(v) : null);
                setErrors((e) => ({ ...e, student_id: '' }));
                setFormError(null);
              }}
              optionsUrl="crud/hostel_allocations/options/student_id"
              placeholder="Search by name, student ID or mobile…"
              invalid={!!errors.student_id}
            />
          </Field>
          {studentId && loadingStudent && !student && <Skeleton className="h-20" />}
          {student && <StudentCard s={student} />}
          {student?.active_allocation && (
            <Alert variant="warning" title="Already staying in the hostel">
              {student.name} is in {student.active_allocation.hostel_code} Room {student.active_allocation.room_no}
              {student.active_allocation.bed_no ? ` (Bed ${student.active_allocation.bed_no})` : ''}. Use “Transfer” on the allocation to change the room.
            </Alert>
          )}
          {genderMismatch && (
            <Alert variant="error" title="Hostel not allowed">This bed is in a hostel that does not admit {labelize(student?.gender ?? '')} students. Choose another student or bed.</Alert>
          )}
        </section>

        {!lockedHostel && (
          <section className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">2 · Hostel</h3>
            {student ? (
              <HostelChoice hostels={hostels} value={hostelId} gender={student.gender} error={errors.hostel_id} onChange={(id) => { setHostelId(id); setBed({ room_id: null, bed_id: null }); setErrors((e) => ({ ...e, hostel_id: '' })); }} />
            ) : (
              <p className="rounded-xl border border-dashed border-slate-200 px-4 py-4 text-center text-sm text-slate-500 dark:border-slate-700">
                <Building2 className="mx-auto mb-1 h-5 w-5 text-slate-400" /> Select the student first — boys and girls hostels are offered by gender.
              </p>
            )}
          </section>
        )}

        <section className="space-y-3">
          <h3 className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">{lockedHostel ? '2' : '3'} · Room &amp; bed</h3>
          <BedPicker
            hostelId={student || lockedHostel ? hostelId : null}
            value={bed}
            onChange={onBed}
            onRoomLoaded={(r) => setRent((x) => (x === '' ? (r.annual_fee !== null ? String(r.annual_fee) : hostel?.annual_fee ? String(hostel.annual_fee) : '') : x))}
            errors={{ room_id: errors.room_id || errors.hostel_id, bed_id: errors.bed_id }}
          />
        </section>

        <section className="space-y-3">
          <h3 className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">{lockedHostel ? '3' : '4'} · Stay &amp; rent</h3>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Field label="Allotted from" required htmlFor="alloc-from" error={errors.allocated_on}>
              <Input id="alloc-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} invalid={!!errors.allocated_on} />
            </Field>
            <Field label="Allotted until" htmlFor="alloc-until" error={errors.valid_until} hint="Blank = end of the academic session">
              <Input id="alloc-until" type="date" value={until} min={from} onChange={(e) => setUntil(e.target.value)} invalid={!!errors.valid_until} />
            </Field>
            <Field label="Room rent / year" htmlFor="alloc-rent" error={errors.fee_amount} hint="Filled from the room; editable">
              <Input id="alloc-rent" type="number" min={0} step={500} prefix="₹" value={rent} onChange={(e) => setRent(e.target.value)} invalid={!!errors.fee_amount} />
            </Field>
          </div>
          <Field label="Remarks" htmlFor="alloc-remarks">
            <Textarea id="alloc-remarks" rows={2} maxLength={255} value={remarks} onChange={(e) => setRemarks(e.target.value)} placeholder="e.g. Requested lower floor; documents verified" />
          </Field>
          {can('hostel', 'edit') && (
            <div className="flex flex-col gap-3 rounded-xl border border-slate-200 p-3 sm:flex-row sm:items-center dark:border-slate-700">
              <Toggle checked={invoice} onChange={setInvoice} label="Raise hostel fee invoice" description="Creates an invoice under Fees & Accounts (fee head: Hostel Fee)." />
              {invoice && (
                <div className="flex items-center gap-4 sm:ml-auto">
                  <Toggle checked={mess} onChange={setMess} label={`Add mess charges${hostel?.mess_fee ? ` (${formatMoney(hostel.mess_fee)})` : ''}`} />
                  <span className="whitespace-nowrap text-sm font-semibold text-slate-900 dark:text-white">{formatMoney(total)}</span>
                </div>
              )}
            </div>
          )}
        </section>
      </form>
    </Modal>
  );
}

/* ================================================================== vacate / cancel / invoice / transfer */

interface ActionProps {
  allocation: Row | null;
  onClose: () => void;
  onDone?: () => void;
}

function useActionForm() {
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const toast = useToast();
  const refresh = useHostelRefresh();
  const run = async (fn: () => Promise<{ message: string }>, after?: () => void) => {
    setSaving(true);
    setFormError(null);
    try {
      const res = await fn();
      toast.success(res.message);
      await refresh();
      after?.();
      return true;
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      setFormError(ae.message);
      if (!Object.keys(ae.errors ?? {}).length) toast.error(ae.message);
      return false;
    } finally {
      setSaving(false);
    }
  };
  return { errors, setErrors, formError, setFormError, saving, run };
}

const who = (a: Row) => `${a.student_name ?? `${a.first_name ?? ''} ${a.last_name ?? ''}`.trim()}`;
const where = (a: Row) => `${a.hostel_code} Room ${a.room_no}${a.bed_no ? ` · Bed ${a.bed_no}` : ''}`;

export function VacateModal({ allocation: a, onClose, onDone }: ActionProps) {
  const f = useActionForm();
  const [date, setDate] = useState(isoDate());
  const [reason, setReason] = useState('');
  useEffect(() => {
    if (a) {
      setDate(isoDate());
      setReason('');
      f.setErrors({});
      f.setFormError(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [a]);
  if (!a) return null;
  const submit = (e: FormEvent) => {
    e.preventDefault();
    void f.run(() => api.post(`hostel/allocations/${a.id}/vacate`, { vacated_on: date, reason }), () => { onDone?.(); onClose(); });
  };
  return (
    <Modal
      open={!!a}
      onClose={onClose}
      static={f.saving}
      title="Vacate room"
      description={`${who(a)} · ${where(a)}`}
      icon={<span className="kpi-icon bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200"><LogOut className="h-5 w-5" /></span>}
      footer={<><Button variant="secondary" onClick={onClose} disabled={f.saving}>Cancel</Button><Button type="submit" form="hostel-vacate-form" loading={f.saving}>Vacate &amp; free bed</Button></>}
    >
      <form id="hostel-vacate-form" onSubmit={submit} noValidate className="space-y-4">
        {f.formError && <Alert variant="error">{f.formError}</Alert>}
        <p className="text-sm text-slate-600 dark:text-slate-300">The bed becomes free immediately and the student is no longer listed as a hosteller. Pending hostel fees stay on the student account.</p>
        <Field label="Vacating date" required htmlFor="vac-date" error={f.errors.vacated_on} hint={`Allotted on ${formatDate(a.allocated_on)}`}>
          <Input id="vac-date" type="date" value={date} min={String(a.allocated_on ?? '')} onChange={(e) => setDate(e.target.value)} invalid={!!f.errors.vacated_on} />
        </Field>
        <Field label="Reason" htmlFor="vac-reason" error={f.errors.reason}>
          <Textarea id="vac-reason" rows={2} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Course completed, shifted to day scholar…" invalid={!!f.errors.reason} />
        </Field>
      </form>
    </Modal>
  );
}

export function CancelModal({ allocation: a, onClose, onDone }: ActionProps) {
  const f = useActionForm();
  const [reason, setReason] = useState('');
  const [cancelInvoice, setCancelInvoice] = useState(true);
  useEffect(() => {
    if (a) {
      setReason('');
      setCancelInvoice(true);
      f.setErrors({});
      f.setFormError(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [a]);
  if (!a) return null;
  const hasInvoice = !!a.invoice_no && a.invoice_status !== 'cancelled';
  const submit = (e: FormEvent) => {
    e.preventDefault();
    void f.run(() => api.post(`hostel/allocations/${a.id}/cancel`, { reason, cancel_invoice: hasInvoice && cancelInvoice ? 1 : 0 }), () => { onDone?.(); onClose(); });
  };
  return (
    <Modal
      open={!!a}
      onClose={onClose}
      static={f.saving}
      title="Cancel allocation"
      description={`${who(a)} · ${where(a)}`}
      icon={<span className="kpi-icon bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300"><XCircle className="h-5 w-5" /></span>}
      footer={<><Button variant="secondary" onClick={onClose} disabled={f.saving}>Keep allocation</Button><Button type="submit" variant="danger" form="hostel-cancel-form" loading={f.saving}>Cancel allocation</Button></>}
    >
      <form id="hostel-cancel-form" onSubmit={submit} noValidate className="space-y-4">
        {f.formError && <Alert variant="error">{f.formError}</Alert>}
        <p className="text-sm text-slate-600 dark:text-slate-300">Use this for allotments made by mistake or when the student never checked in. The bed is released.</p>
        <Field label="Reason" required htmlFor="cancel-reason" error={f.errors.reason}>
          <Textarea id="cancel-reason" rows={2} maxLength={200} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Student opted out before check-in" invalid={!!f.errors.reason} />
        </Field>
        {hasInvoice && (
          <div>
            <Toggle checked={cancelInvoice} onChange={setCancelInvoice} label={`Also cancel invoice ${a.invoice_no}`} description="Only possible while no payment has been received." />
            {f.errors.cancel_invoice && <p className="form-error">{f.errors.cancel_invoice}</p>}
          </div>
        )}
      </form>
    </Modal>
  );
}

export function InvoiceModal({ allocation: a, onClose, onDone, messFee }: ActionProps & { messFee?: number | null }) {
  const f = useActionForm();
  const [includeMess, setIncludeMess] = useState(true);
  const [amount, setAmount] = useState('');
  const [due, setDue] = useState('');
  const mess = toNumber(messFee ?? a?.mess_fee);
  useEffect(() => {
    if (a) {
      setIncludeMess(true);
      setAmount(String(toNumber(a.fee_amount) + mess));
      const d = new Date();
      d.setDate(d.getDate() + 15);
      setDue(isoDate(d));
      f.setErrors({});
      f.setFormError(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [a]);
  if (!a) return null;
  const submit = (e: FormEvent) => {
    e.preventDefault();
    void f.run(() => api.post(`hostel/allocations/${a.id}/invoice`, { amount, include_mess: includeMess ? 1 : 0, due_date: due }), () => { onDone?.(); onClose(); });
  };
  return (
    <Modal
      open={!!a}
      onClose={onClose}
      static={f.saving}
      title="Raise hostel fee invoice"
      description={`${who(a)} · ${where(a)}`}
      icon={<span className="kpi-icon bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300"><ReceiptIndianRupee className="h-5 w-5" /></span>}
      footer={<><Button variant="secondary" onClick={onClose} disabled={f.saving}>Cancel</Button><Button type="submit" form="hostel-invoice-form" loading={f.saving}>Raise invoice</Button></>}
    >
      <form id="hostel-invoice-form" onSubmit={submit} noValidate className="space-y-4">
        {f.formError && <Alert variant="error">{f.formError}</Alert>}
        <div className="rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700">
          <div className="flex justify-between"><span className="text-slate-500">Room rent / year</span><span className="font-medium">{formatMoney(a.fee_amount)}</span></div>
          <div className="mt-1 flex items-center justify-between">
            <Toggle checked={includeMess} onChange={(v) => { setIncludeMess(v); setAmount(String(toNumber(a.fee_amount) + (v ? mess : 0))); }} label="Mess charges" />
            <span className="font-medium">{formatMoney(includeMess ? mess : 0)}</span>
          </div>
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Invoice amount" required htmlFor="inv-amount" error={f.errors.amount}>
            <Input id="inv-amount" type="number" min={1} step={100} prefix="₹" value={amount} onChange={(e) => setAmount(e.target.value)} invalid={!!f.errors.amount} />
          </Field>
          <Field label="Due date" required htmlFor="inv-due" error={f.errors.due_date}>
            <Input id="inv-due" type="date" value={due} onChange={(e) => setDue(e.target.value)} invalid={!!f.errors.due_date} />
          </Field>
        </div>
        <p className="text-xs text-slate-500 dark:text-slate-400">The invoice appears under Fees &amp; Accounts → Student Fees with the “Hostel Fee” head and can be collected at the counter or online.</p>
      </form>
    </Modal>
  );
}

export function TransferModal({ allocation: a, onClose, onDone }: ActionProps) {
  const f = useActionForm();
  const [hostelId, setHostelId] = useState<number | null>(null);
  const [bed, setBed] = useState<BedChoice>({ room_id: null, bed_id: null });
  const [room, setRoom] = useState<GridRoom | null>(null);
  const [date, setDate] = useState(isoDate());
  const [reason, setReason] = useState('');
  const [updateRent, setUpdateRent] = useState(false);
  const studentId = a ? Number(a.student_id) : null;
  const { data: student } = useApi<StudentSummary>(['hostel', 'student', studentId], `hostel/students/${studentId}`, undefined, { enabled: !!a && !!studentId });
  useEffect(() => {
    if (a) {
      setHostelId(Number(a.hostel_id));
      setBed({ room_id: null, bed_id: null });
      setRoom(null);
      setDate(isoDate());
      setReason('');
      setUpdateRent(false);
      f.setErrors({});
      f.setFormError(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [a]);
  if (!a) return null;
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const errs: Record<string, string> = {};
    if (!bed.room_id) errs.room_id = 'Select the new room.';
    else if (!bed.bed_id) errs.bed_id = 'Select a free bed.';
    if (!reason.trim()) errs.reason = 'Reason for the room change is required.';
    if (Object.keys(errs).length) {
      f.setErrors(errs);
      return;
    }
    void f.run(
      () => api.post(`hostel/allocations/${a.id}/transfer`, { hostel_id: hostelId, room_id: bed.room_id, bed_id: bed.bed_id, transfer_date: date, reason, update_rent: updateRent ? 1 : 0 }),
      () => { onDone?.(); onClose(); },
    );
  };
  const newRent = room?.annual_fee ?? null;
  return (
    <Modal
      open={!!a}
      onClose={onClose}
      static={f.saving}
      size="xl"
      title="Transfer to another room"
      description={`${who(a)} · currently ${where(a)}`}
      icon={<span className="kpi-icon bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300"><ArrowRightLeft className="h-5 w-5" /></span>}
      footer={<><Button variant="secondary" onClick={onClose} disabled={f.saving}>Cancel</Button><Button type="submit" form="hostel-transfer-form" loading={f.saving} icon={ArrowRightLeft}>Transfer</Button></>}
    >
      <form id="hostel-transfer-form" onSubmit={submit} noValidate className="space-y-5">
        {f.formError && <Alert variant="error">{f.formError}</Alert>}
        {student ? (
          <HostelChoice hostels={student.eligible_hostels} value={hostelId} gender={student.gender} error={f.errors.hostel_id} onChange={(id) => { setHostelId(id); setBed({ room_id: null, bed_id: null }); setRoom(null); }} />
        ) : (
          <Skeleton className="h-20" />
        )}
        <BedPicker hostelId={hostelId} value={bed} currentBedId={a.bed_id ? Number(a.bed_id) : null} onChange={(v, r) => { setBed(v); setRoom(r); f.setErrors({}); }} errors={{ room_id: f.errors.room_id, bed_id: f.errors.bed_id }} />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="Transfer date" required htmlFor="tr-date" error={f.errors.transfer_date}>
            <Input id="tr-date" type="date" value={date} min={String(a.allocated_on ?? '')} onChange={(e) => setDate(e.target.value)} invalid={!!f.errors.transfer_date} />
          </Field>
          <Field label="Reason" required htmlFor="tr-reason" error={f.errors.reason} className="sm:col-span-2">
            <Input id="tr-reason" maxLength={200} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Requested ground floor on medical grounds" invalid={!!f.errors.reason} />
          </Field>
        </div>
        {room && newRent !== null && toNumber(newRent) !== toNumber(a.fee_amount) && (
          <Toggle
            checked={updateRent}
            onChange={setUpdateRent}
            label={`Update rent to ${formatMoney(newRent)} / year`}
            description={`Current rent ${formatMoney(a.fee_amount)}. The existing hostel fee invoice is not changed.`}
          />
        )}
        {student && !student.eligible_hostels.length && <Alert variant="warning">No eligible {HOSTEL_TYPES[String(a.hostel_type)]?.toLowerCase()} hostel is active.</Alert>}
      </form>
    </Modal>
  );
}
