import { useEffect, useMemo, type FormEvent, type ReactNode } from 'react';
import { ArrowRightLeft, Ban, Bus, ReceiptIndianRupee, UserPlus } from 'lucide-react';
import { Alert, Button, Combobox, Field, Input, Modal, RadioGroup, Select, Textarea, Toggle, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { formatDate, formatMoney, formatTime, isoDate } from '@/lib/format';
import type { Option, Row } from '@/lib/types';
import { useActionForm } from '../hooks';
import { OccupancyBar, useRouteOptions, useTransportRefresh, useTransportSettings } from '../shared';
import type { RouteOption } from '../types';

const PERSON_TYPES: Option[] = [
  { value: 'student', label: 'Student' },
  { value: 'faculty', label: 'Faculty' },
  { value: 'staff', label: 'Staff' },
];
const PERSON_SOURCE: Record<string, string> = { student: 'students', faculty: 'faculty', staff: 'staff' };
const PERSON_FIELD: Record<string, string> = { student: 'student_id', faculty: 'faculty_id', staff: 'staff_id' };

function DialogIcon({ children, tone }: { children: ReactNode; tone: 'blue' | 'amber' | 'red' | 'green' }) {
  const cls = {
    blue: 'bg-brand-100 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    red: 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-300',
    green: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
  }[tone];
  return <span className={`inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${cls}`}>{children}</span>;
}

/** Faculty / staff fee = share of the stop fare, rounded to ₹100 (same rule as transport_default_fee() on the server). */
function staffFee(fare: number, pct: number) {
  return Math.round((fare * pct) / 100 / 100) * 100;
}

function routeLabel(r: RouteOption) {
  return `${r.code} — ${r.name} (${r.available ? `${r.available} free` : 'full'})`;
}

function stopLabel(s: RouteOption['stops'][number]) {
  return `${s.sequence}. ${s.name}${s.pickup_time ? ` · ${formatTime(s.pickup_time)}` : ''}${s.annual_fare ? ` · ${formatMoney(s.annual_fare)}` : ''}`;
}

/** Route + stop pickers with live capacity (shared by allocate & transfer). */
function RouteStopFields({ routes, routeId, stopId, onRoute, onStop, errors, excludeFull = true, currentRouteId }: {
  routes: RouteOption[]; routeId: string; stopId: string; onRoute: (v: string) => void; onStop: (v: string, fare: number | null) => void;
  errors: Record<string, string>; excludeFull?: boolean; currentRouteId?: number;
}) {
  const route = routes.find((r) => String(r.id) === routeId);
  const routeOptions = routes.map((r) => ({ value: String(r.id), label: routeLabel(r) }));
  const full = route && route.available <= 0 && route.id !== currentRouteId;
  return (
    <>
      <Field label="Route" required htmlFor="al-route" error={errors.route_id} className="sm:col-span-6">
        <Combobox id="al-route" value={routeId || null} onChange={(v) => onRoute(v ? String(v) : '')} options={routeOptions} placeholder="Search route…" invalid={!!errors.route_id} />
      </Field>
      <Field label="Pickup stop" required htmlFor="al-stop" error={errors.stop_id} className="sm:col-span-6">
        <Select id="al-stop" value={stopId} disabled={!route}
          onChange={(e) => {
            const s = route?.stops.find((x) => String(x.id) === e.target.value);
            onStop(e.target.value, s?.annual_fare ?? route?.annual_fare ?? null);
          }}
          options={(route?.stops ?? []).map((s) => ({ value: String(s.id), label: stopLabel(s) }))} placeholder={route ? 'Select stop…' : 'Pick a route first'} invalid={!!errors.stop_id} />
      </Field>
      {route && (
        <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3 sm:col-span-12 dark:border-slate-800 dark:bg-slate-800/40">
          <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600 dark:text-slate-300">
            <span className="inline-flex items-center gap-1.5 font-medium"><Bus className="h-3.5 w-3.5" /> {route.code} · {route.stops.length} stops · departs {route.morning_departure ? formatTime(route.morning_departure) : '—'}</span>
            <span>{route.start_point} → {route.end_point}</span>
          </div>
          <OccupancyBar allocated={route.allocated} capacity={route.capacity} className="mt-2" />
          {full && excludeFull && <p className="mt-2 text-xs font-medium text-red-600 dark:text-red-400">This route is full — choose another route or add a vehicle first.</p>}
        </div>
      )}
    </>
  );
}

/* ------------------------------------------------------------------ Allocate */

export interface AllocatePrefill {
  person_type?: 'student' | 'faculty' | 'staff';
  person_id?: number;
  person_label?: string;
  route_id?: number;
}

export function AllocationFormModal({ open, onClose, prefill }: { open: boolean; onClose: () => void; prefill?: AllocatePrefill | null }) {
  const toast = useToast();
  const refresh = useTransportRefresh();
  const { data: routes = [], isLoading } = useRouteOptions(open);
  const staffPct = useTransportSettings().data?.staff_fee_percent ?? 50;
  const blank = { person_type: 'student', person_id: '', route_id: '', stop_id: '', start_date: isoDate(), end_date: '', fee_amount: '', remarks: '', raise_invoice: false };
  const form = useActionForm(blank);
  const { values, set, errors } = form;

  useEffect(() => {
    if (!open) return;
    form.reset({
      ...blank,
      person_type: prefill?.person_type ?? 'student',
      person_id: prefill?.person_id ? String(prefill.person_id) : '',
      route_id: prefill?.route_id ? String(prefill.route_id) : '',
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, prefill]);

  const initialPerson = useMemo<Option[]>(() => (prefill?.person_id && prefill.person_label ? [{ value: prefill.person_id, label: prefill.person_label }] : []), [prefill]);
  const personField = PERSON_FIELD[values.person_type];
  const personError = errors[personField] || errors.person_type;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const ok = await form.run(async () => {
      const body: Record<string, unknown> = {
        person_type: values.person_type, student_id: '', faculty_id: '', staff_id: '', route_id: values.route_id, stop_id: values.stop_id,
        start_date: values.start_date, end_date: values.end_date, fee_amount: values.fee_amount, remarks: values.remarks, raise_invoice: values.person_type === 'student' && values.raise_invoice,
      };
      body[personField] = values.person_id;
      const res = await api.post('crud/transport_allocations', body);
      toast.success(res.message || 'Seat allocated.');
    });
    if (ok) {
      await refresh('transport_allocations', 'transport_routes', 'vehicles', 'transport_stops');
      onClose();
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={form.saving}
      size="lg"
      title="Allocate transport"
      description="Assign a rider to a route and pickup stop. Seats are checked against the route's vehicles."
      icon={<DialogIcon tone="blue"><UserPlus className="h-5 w-5" /></DialogIcon>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.saving}>Cancel</Button>
          <Button type="submit" form="allocate-form" loading={form.saving} disabled={isLoading}>Allocate seat</Button>
        </>
      }
    >
      <form id="allocate-form" onSubmit={submit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-12">
        {form.error && <Alert variant="error" className="sm:col-span-12">{form.error}</Alert>}
        <Field label="Rider type" required className="sm:col-span-12" error={errors.person_type}>
          <RadioGroup name="al-type" value={values.person_type} options={PERSON_TYPES} onChange={(v) => {
            set('person_type', v);
            set('person_id', '');
            const route = routes.find((r) => String(r.id) === values.route_id);
            const fare = route ? (route.stops.find((x) => String(x.id) === values.stop_id)?.annual_fare ?? route.annual_fare) : null;
            if (fare !== null && fare !== undefined && values.stop_id) set('fee_amount', String(v === 'student' ? fare : staffFee(fare, staffPct)));
          }} />
        </Field>
        <Field label={values.person_type === 'student' ? 'Student' : values.person_type === 'faculty' ? 'Faculty member' : 'Staff member'} required htmlFor="al-person" error={personError} className="sm:col-span-12">
          <Combobox
            key={values.person_type}
            id="al-person"
            source={PERSON_SOURCE[values.person_type]}
            value={values.person_id || null}
            onChange={(v) => set('person_id', v ? String(v) : '')}
            initialOptions={values.person_type === (prefill?.person_type ?? 'student') ? initialPerson : []}
            placeholder={values.person_type === 'student' ? 'Search by name, student ID or mobile…' : 'Search by name or employee ID…'}
            invalid={!!personError}
          />
        </Field>
        <RouteStopFields
          routes={routes}
          routeId={values.route_id}
          stopId={values.stop_id}
          errors={errors}
          onRoute={(v) => { set('route_id', v); set('stop_id', ''); }}
          onStop={(v, fare) => {
            set('stop_id', v);
            if (fare !== null) set('fee_amount', String(values.person_type === 'student' ? fare : staffFee(fare, staffPct)));
          }}
        />
        <Field label="Start date" required htmlFor="al-start" error={errors.start_date} className="sm:col-span-4">
          <Input id="al-start" type="date" value={values.start_date} onChange={(e) => set('start_date', e.target.value)} invalid={!!errors.start_date} />
        </Field>
        <Field label="End date" htmlFor="al-end" error={errors.end_date} hint="Blank = end of session" className="sm:col-span-4">
          <Input id="al-end" type="date" value={values.end_date} onChange={(e) => set('end_date', e.target.value)} invalid={!!errors.end_date} />
        </Field>
        <Field label="Annual fee (₹)" htmlFor="al-fee" error={errors.fee_amount} hint="Pre-filled from the stop fare" className="sm:col-span-4">
          <Input id="al-fee" type="number" min={0} step="100" value={values.fee_amount} onChange={(e) => set('fee_amount', e.target.value)} invalid={!!errors.fee_amount} />
        </Field>
        <Field label="Remarks" htmlFor="al-remarks" error={errors.remarks} className="sm:col-span-12">
          <Input id="al-remarks" value={values.remarks} maxLength={255} onChange={(e) => set('remarks', e.target.value)} placeholder="Optional note" />
        </Field>
        {values.person_type === 'student' && (
          <div className="sm:col-span-12">
            <Toggle checked={!!values.raise_invoice} onChange={(v) => set('raise_invoice', v)} label="Raise transport fee invoice now"
              description="Creates an invoice in Fees with the Transport fee head for the annual fee above." />
          </div>
        )}
      </form>
    </Modal>
  );
}

/* ------------------------------------------------------------------ Transfer */

export function TransferModal({ allocation, onClose }: { allocation: Row | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = useTransportRefresh();
  const open = !!allocation;
  const { data: routes = [] } = useRouteOptions(open);
  const staffPct = useTransportSettings().data?.staff_fee_percent ?? 50;
  const form = useActionForm({ route_id: '', stop_id: '', effective_date: isoDate(), fee_amount: '', update_invoice: true, remarks: '' });
  const { values, set, errors } = form;

  useEffect(() => {
    if (!allocation) return;
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const minDate = new Date(`${allocation.start_date}T00:00:00`);
    minDate.setDate(minDate.getDate() + 1);
    form.reset({ route_id: String(allocation.route_id), stop_id: '', effective_date: isoDate(minDate > tomorrow ? minDate : tomorrow), fee_amount: '', update_invoice: true, remarks: '' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [allocation]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!allocation) return;
    const ok = await form.run(async () => {
      const res = await api.post(`transport/allocations/${allocation.id}/transfer`, values);
      toast.success(res.message || 'Transfer saved.');
    });
    if (ok) {
      await refresh('transport_allocations', 'transport_routes', 'vehicles', 'transport_stops');
      onClose();
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={form.saving}
      size="lg"
      title={`Transfer ${allocation?.person_name ?? ''}`}
      description="Moves the rider to another route or stop from the effective date. The current allocation is closed and kept in history."
      icon={<DialogIcon tone="amber"><ArrowRightLeft className="h-5 w-5" /></DialogIcon>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.saving}>Cancel</Button>
          <Button type="submit" form="transfer-form" loading={form.saving}>Transfer</Button>
        </>
      }
    >
      {allocation && (
        <form id="transfer-form" onSubmit={submit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-12">
          {form.error && <Alert variant="error" className="sm:col-span-12">{form.error}</Alert>}
          <div className="grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 text-sm sm:col-span-12 sm:grid-cols-4 dark:bg-slate-800/50">
            <div><p className="text-[11px] uppercase tracking-wide text-slate-500">Current route</p><p className="font-semibold">{allocation.route_code}</p></div>
            <div><p className="text-[11px] uppercase tracking-wide text-slate-500">Stop</p><p className="truncate font-semibold">{allocation.stop_name ?? '—'}</p></div>
            <div><p className="text-[11px] uppercase tracking-wide text-slate-500">Fee</p><p className="font-semibold">{formatMoney(allocation.fee_amount)}</p></div>
            <div><p className="text-[11px] uppercase tracking-wide text-slate-500">Since</p><p className="font-semibold">{formatDate(allocation.start_date)}</p></div>
          </div>
          <RouteStopFields
            routes={routes}
            routeId={values.route_id}
            stopId={values.stop_id}
            errors={errors}
            currentRouteId={Number(allocation.route_id)}
            onRoute={(v) => { set('route_id', v); set('stop_id', ''); }}
            onStop={(v, fare) => {
              set('stop_id', v);
              if (fare !== null) set('fee_amount', String(allocation.person_type === 'student' ? fare : staffFee(fare, staffPct)));
            }}
          />
          <Field label="Effective from" required htmlFor="tr-date" error={errors.effective_date} className="sm:col-span-6">
            <Input id="tr-date" type="date" value={values.effective_date} onChange={(e) => set('effective_date', e.target.value)} invalid={!!errors.effective_date} />
          </Field>
          <Field label="New annual fee (₹)" htmlFor="tr-fee" error={errors.fee_amount} hint="Blank = fare of the new stop" className="sm:col-span-6">
            <Input id="tr-fee" type="number" min={0} step="100" value={values.fee_amount} onChange={(e) => set('fee_amount', e.target.value)} invalid={!!errors.fee_amount} />
          </Field>
          <Field label="Reason / remarks" htmlFor="tr-remarks" className="sm:col-span-12">
            <Input id="tr-remarks" value={values.remarks} maxLength={200} onChange={(e) => set('remarks', e.target.value)} placeholder="e.g. Family shifted to Indirapuram" />
          </Field>
          {allocation.invoice_no && (
            <div className="sm:col-span-12">
              <Toggle checked={!!values.update_invoice} onChange={(v) => set('update_invoice', v)} label={`Update invoice ${allocation.invoice_no} if the fee changes`}
                description="Only possible while nothing has been paid against it." />
            </div>
          )}
        </form>
      )}
    </Modal>
  );
}

/* ------------------------------------------------------------------ Cancel (single or bulk) */

export function CancelAllocationModal({ target, onClose, onDone }: { target: { ids: number[]; label: string; hasInvoice?: boolean } | null; onClose: () => void; onDone?: () => void }) {
  const toast = useToast();
  const refresh = useTransportRefresh();
  const form = useActionForm({ date: isoDate(), reason: '', cancel_invoice: true });
  const { values, set, errors } = form;
  useEffect(() => {
    if (target) form.reset({ date: isoDate(), reason: '', cancel_invoice: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [target]);
  const bulk = (target?.ids.length ?? 0) > 1;
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!target) return;
    const ok = await form.run(async () => {
      const res = bulk
        ? await api.post('crud/transport_allocations/bulk', { action: 'cancel', ids: target.ids, ...values })
        : await api.post(`transport/allocations/${target.ids[0]}/cancel`, values);
      toast.success(res.message || 'Allocation cancelled.');
    });
    if (ok) {
      await refresh('transport_allocations', 'transport_routes', 'vehicles', 'transport_stops');
      onDone?.();
      onClose();
    }
  };
  return (
    <Modal
      open={!!target}
      onClose={onClose}
      static={form.saving}
      size="md"
      title={bulk ? `Cancel ${target?.ids.length} allocations` : `Cancel transport — ${target?.label ?? ''}`}
      description="The seat is released from the cancellation date. The record stays in history."
      icon={<DialogIcon tone="red"><Ban className="h-5 w-5" /></DialogIcon>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.saving}>Keep</Button>
          <Button type="submit" form="cancel-alloc-form" variant="danger" loading={form.saving}>Cancel allocation{bulk ? 's' : ''}</Button>
        </>
      }
    >
      <form id="cancel-alloc-form" onSubmit={submit} noValidate className="space-y-4">
        {form.error && <Alert variant="error">{form.error}</Alert>}
        <Field label="Last day of travel" required htmlFor="ca-date" error={errors.date}>
          <Input id="ca-date" type="date" value={values.date} onChange={(e) => set('date', e.target.value)} invalid={!!errors.date} />
        </Field>
        <Field label="Reason" required htmlFor="ca-reason" error={errors.reason}>
          <Textarea id="ca-reason" rows={2} value={values.reason} maxLength={250} onChange={(e) => set('reason', e.target.value)} invalid={!!errors.reason} placeholder="e.g. Moved to the college hostel" />
        </Field>
        {(bulk || target?.hasInvoice) && (
          <Toggle checked={!!values.cancel_invoice} onChange={(v) => set('cancel_invoice', v)} label="Cancel the unpaid transport invoice"
            description="Invoices with payments are kept — process refunds from Fees." />
        )}
      </form>
    </Modal>
  );
}

/* ------------------------------------------------------------------ Raise invoice (single or bulk) */

export function RaiseInvoiceModal({ target, onClose, onDone }: { target: { ids: number[]; label: string; amount?: number } | null; onClose: () => void; onDone?: () => void }) {
  const toast = useToast();
  const refresh = useTransportRefresh();
  const settings = useTransportSettings();
  const dueDefault = () => {
    const d = new Date();
    d.setDate(d.getDate() + (settings.data?.fee_due_days ?? 15));
    return isoDate(d);
  };
  const form = useActionForm({ due_date: dueDefault() });
  const { values, set, errors } = form;
  useEffect(() => {
    if (target) form.reset({ due_date: dueDefault() });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [target, settings.data]);
  const bulk = (target?.ids.length ?? 0) > 1;
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!target) return;
    const ok = await form.run(async () => {
      const res = bulk
        ? await api.post<{ created: number; skipped: number }>('crud/transport_allocations/bulk', { action: 'invoice', ids: target.ids, due_date: values.due_date })
        : await api.post(`transport/allocations/${target.ids[0]}/invoice`, values);
      toast.success(res.message || 'Invoice raised.');
    });
    if (ok) {
      await refresh('transport_allocations', 'student_fees');
      onDone?.();
      onClose();
    }
  };
  return (
    <Modal
      open={!!target}
      onClose={onClose}
      static={form.saving}
      size="md"
      title={bulk ? `Raise ${target?.ids.length} transport invoices` : `Raise transport invoice — ${target?.label ?? ''}`}
      description="Creates invoices in Fees with the Transport fee head for the annual fee of each allocation."
      icon={<DialogIcon tone="green"><ReceiptIndianRupee className="h-5 w-5" /></DialogIcon>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.saving}>Cancel</Button>
          <Button type="submit" form="raise-inv-form" loading={form.saving}>Raise invoice{bulk ? 's' : ''}</Button>
        </>
      }
    >
      <form id="raise-inv-form" onSubmit={submit} noValidate className="space-y-4">
        {form.error && <Alert variant="error">{form.error}</Alert>}
        {!bulk && target?.amount !== undefined && (
          <div className="rounded-xl bg-slate-50 px-4 py-3 text-sm dark:bg-slate-800/50">
            Amount <strong className="ml-1 text-slate-900 dark:text-white">{formatMoney(target.amount)}</strong>
          </div>
        )}
        {bulk && <Alert variant="info">Staff allocations and allocations already invoiced are skipped automatically.</Alert>}
        <Field label="Due date" required htmlFor="ri-due" error={errors.due_date}>
          <Input id="ri-due" type="date" value={values.due_date} onChange={(e) => set('due_date', e.target.value)} invalid={!!errors.due_date} />
        </Field>
      </form>
    </Modal>
  );
}
