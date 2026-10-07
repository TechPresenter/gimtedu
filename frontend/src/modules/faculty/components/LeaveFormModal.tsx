import { useEffect, useMemo, useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { CalendarCheck2, CalendarDays, Info, Sun } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Combobox, Field, Input, Modal, PageLoader, Select, Textarea, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { formatDate, isoDate } from '@/lib/format';
import type { Option } from '@/lib/types';
import { daysLabel, parseRef, type BalanceRow, type LeaveType } from '../hr';

interface Props {
  open: boolean;
  onClose: () => void;
  /** Edit an existing (pending) application */
  leaveId?: number | null;
  /** Pre-selected employee (profile pages): ref like "f-12" + label */
  preset?: { ref: string; label: string; sub?: string } | null;
  onSaved?: () => void;
}

interface Preview {
  days: number;
  calendar_days: number;
  weekly_offs: number;
  holidays: { date: string; title: string }[];
  errors: Record<string, string>;
  balance: BalanceRow | null;
}

const empty = { employee_ref: '', leave_type: '', from_date: '', to_date: '', is_half_day: false, reason: '' };

/** Apply (on behalf) / edit a leave application with live working-day count, holidays and balance check. */
export function LeaveFormModal({ open, onClose, leaveId, preset, onSaved }: Props) {
  const toast = useToast();
  const qc = useQueryClient();
  const [values, setValues] = useState({ ...empty });
  const [initialLabel, setInitialLabel] = useState<Option[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(false);
  const { data: typesData } = useApi<{ rows: LeaveType[] }>(['leave-types'], 'leaves/types', undefined, { enabled: open, staleTime: 60_000 });

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setFormError(null);
    if (leaveId) {
      setLoading(true);
      api
        .get<{ leave: Record<string, string | number>; employee: { name: string; code: string; designation: string } | null }>(`leaves/${leaveId}`)
        .then((d) => {
          const l = d.leave;
          setValues({
            employee_ref: String(l.employee_ref), leave_type: String(l.leave_type), from_date: String(l.from_date), to_date: String(l.to_date),
            is_half_day: Number(l.is_half_day) === 1, reason: String(l.reason ?? ''),
          });
          if (d.employee) setInitialLabel([{ value: String(l.employee_ref), label: `${d.employee.name} (${d.employee.code})`, sub: d.employee.designation }]);
        })
        .catch((e: ApiError) => setFormError(e.message))
        .finally(() => setLoading(false));
    } else {
      const today = isoDate();
      setValues({ ...empty, employee_ref: preset?.ref ?? '', from_date: today, to_date: today });
      setInitialLabel(preset ? [{ value: preset.ref, label: preset.label, sub: preset.sub }] : []);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, leaveId, preset?.ref]);

  const refType = parseRef(values.employee_ref)?.type;
  // Once an employee is chosen, offer only the types that apply to them (type + gender) with their balance.
  const { data: empBalance } = useApi<{ balance: BalanceRow[] }>(['leave-balance-ref', values.employee_ref], 'leaves/balance', { employee_ref: values.employee_ref }, {
    enabled: open && !!refType,
    staleTime: 15_000,
  });
  const types = useMemo(() => {
    const active = (typesData?.rows ?? []).filter((t) => t.status === 'active' && (!refType || t.applies_to === 'all' || t.applies_to === refType));
    if (!empBalance) return active;
    const allowed = new Set(empBalance.balance.map((b) => b.code));
    return active.filter((t) => allowed.has(t.code) || t.code === values.leave_type);
  }, [typesData, refType, empBalance, values.leave_type]);
  const balanceOf = (code: string) => empBalance?.balance.find((b) => b.code === code);
  const typeLabel = (t: LeaveType) => {
    const b = balanceOf(t.code);
    if (b && !b.unlimited && b.available !== null) return `${t.name} — ${b.available} of ${b.quota} left`;
    return `${t.name}${t.annual_quota > 0 ? ` (${t.annual_quota} / session)` : ''}`;
  };
  const set = (k: keyof typeof empty, v: unknown) => {
    setValues((s) => {
      const next = { ...s, [k]: v };
      if (k === 'from_date' && (!s.to_date || s.to_date < String(v))) next.to_date = String(v);
      if ((k === 'from_date' || k === 'to_date') && next.from_date !== next.to_date) next.is_half_day = false;
      return next;
    });
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };

  const rawPreviewQuery = useMemo(
    () => ({ employee_ref: values.employee_ref, leave_type: values.leave_type, from: values.from_date, to: values.to_date, half_day: values.is_half_day ? 1 : 0, exclude_id: leaveId ?? undefined }),
    [values.employee_ref, values.leave_type, values.from_date, values.to_date, values.is_half_day, leaveId],
  );
  const previewQuery = useDebounce(rawPreviewQuery, 300);
  const { data: preview, isFetching: previewing } = useApi<Preview>(['leave-preview', previewQuery], 'leaves/preview', previewQuery, {
    enabled: open && !!previewQuery.from && !!previewQuery.to,
    staleTime: 10_000,
  });
  const selectedType = types.find((t) => t.code === values.leave_type);
  const warnings = Object.entries(preview?.errors ?? {}).filter(([k]) => k !== 'employee_ref' || !!values.employee_ref);

  const submit = async (e?: FormEvent) => {
    e?.preventDefault();
    const client: Record<string, string> = {};
    if (!values.employee_ref) client.employee_ref = 'Select the employee.';
    if (!values.leave_type) client.leave_type = 'Select the leave type.';
    if (!values.from_date) client.from_date = 'From date is required.';
    if (!values.to_date) client.to_date = 'To date is required.';
    if (!values.reason.trim()) client.reason = 'Reason is required.';
    if (Object.keys(client).length) {
      setErrors(client);
      setFormError('Please fill in the required fields.');
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const body = { leave_type: values.leave_type, from_date: values.from_date, to_date: values.to_date, is_half_day: values.is_half_day, reason: values.reason.trim() };
      const res = leaveId
        ? await api.post(`crud/employee_leaves/${leaveId}`, body)
        : await api.post('crud/employee_leaves', { ...body, employee_ref: values.employee_ref });
      toast.success(leaveId ? 'Leave application updated.' : res.message || 'Leave application submitted for approval.');
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['crud', 'employee_leaves'] }),
        qc.invalidateQueries({ queryKey: ['leaves-summary'] }),
        qc.invalidateQueries({ queryKey: ['leave-calendar'] }),
        qc.invalidateQueries({ queryKey: ['leave-balance'] }),
        qc.invalidateQueries({ queryKey: ['leave-balance-ref'] }),
        qc.invalidateQueries({ queryKey: ['leave-balances'] }),
        qc.invalidateQueries({ queryKey: ['hr-profile'] }),
      ]);
      onSaved?.();
      onClose();
    } catch (err) {
      const e2 = err as ApiError;
      setErrors(e2.errors ?? {});
      setFormError(e2.message);
      if (!Object.keys(e2.errors ?? {}).length) toast.error(e2.message || 'Unable to save the leave application. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const single = values.from_date && values.from_date === values.to_date;
  const bal = preview?.balance;
  const after = bal && !bal.unlimited && bal.available !== null ? bal.available - bal.pending - (preview?.days ?? 0) : null;

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="lg"
      title={leaveId ? 'Edit leave application' : 'Apply for leave'}
      description={leaveId ? 'Only pending applications can be changed.' : 'Submit a leave application on behalf of a faculty or staff member. It goes to the approval queue.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form="leave-form" icon={CalendarCheck2} loading={saving} disabled={loading}>
            {leaveId ? 'Save changes' : 'Submit application'}
          </Button>
        </>
      }
    >
      {loading ? (
        <PageLoader label="Loading application…" />
      ) : (
        <form id="leave-form" onSubmit={submit} noValidate className="space-y-4">
          {formError && <Alert variant="error">{formError}</Alert>}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-12">
            <Field className="sm:col-span-12" label="Employee" required htmlFor="lv-emp" error={errors.employee_ref}>
              <Combobox
                id="lv-emp"
                value={values.employee_ref || null}
                onChange={(v) => {
                  set('employee_ref', v ?? '');
                  set('leave_type', '');
                }}
                optionsUrl="crud/employee_leaves/options/employee_ref"
                placeholder="Search faculty or staff by name / employee ID…"
                initialOptions={initialLabel}
                disabled={!!leaveId || !!preset}
                invalid={!!errors.employee_ref}
                clearable={!preset && !leaveId}
              />
            </Field>
            <Field className="sm:col-span-6" label="Leave type" required htmlFor="lv-type" error={errors.leave_type} hint={selectedType?.description ?? undefined}>
              <Select
                id="lv-type"
                value={values.leave_type}
                onChange={(e) => set('leave_type', e.target.value)}
                options={types.map((t) => ({ value: t.code, label: typeLabel(t) }))}
                placeholder={values.employee_ref ? 'Select leave type' : 'Select the employee first'}
                invalid={!!errors.leave_type}
              />
            </Field>
            <Field className="sm:col-span-6" label="Half day" htmlFor="lv-half" error={errors.is_half_day}>
              <div className="flex h-[42px] items-center">
                <Toggle id="lv-half" checked={values.is_half_day} onChange={(v) => set('is_half_day', v)} disabled={!single} label={single ? 'Half-day leave' : 'Only for single-day leave'} />
              </div>
            </Field>
            <Field className="sm:col-span-6" label="From date" required htmlFor="lv-from" error={errors.from_date}>
              <Input id="lv-from" type="date" value={values.from_date} onChange={(e) => set('from_date', e.target.value)} invalid={!!errors.from_date} />
            </Field>
            <Field className="sm:col-span-6" label="To date" required htmlFor="lv-to" error={errors.to_date}>
              <Input id="lv-to" type="date" value={values.to_date} min={values.from_date || undefined} onChange={(e) => set('to_date', e.target.value)} invalid={!!errors.to_date} />
            </Field>
          </div>

          {/* Live calculation */}
          <div className={clsx('rounded-xl border p-4 transition', previewing ? 'border-slate-200 opacity-70 dark:border-slate-700' : 'border-brand-100 bg-brand-50/50 dark:border-brand-500/20 dark:bg-brand-500/5')} aria-live="polite">
            <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
              <div className="flex items-center gap-3">
                <span className="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-800 text-white dark:bg-brand-600">
                  <CalendarDays className="h-5 w-5" />
                </span>
                <div>
                  <p className="font-display text-xl font-bold text-slate-900 dark:text-white">{preview ? daysLabel(preview.days) : '—'}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">working days{values.is_half_day ? ' (half day)' : ''}</p>
                </div>
              </div>
              <dl className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-600 dark:text-slate-300">
                <div><dt className="inline text-slate-500">Calendar days: </dt><dd className="inline font-semibold">{preview?.calendar_days ?? '—'}</dd></div>
                <div><dt className="inline text-slate-500">Weekly offs: </dt><dd className="inline font-semibold">{preview?.weekly_offs ?? '—'}</dd></div>
                <div><dt className="inline text-slate-500">Holidays: </dt><dd className="inline font-semibold">{preview?.holidays.length ?? '—'}</dd></div>
              </dl>
              {bal && (
                <div className="ml-auto text-right text-xs">
                  {bal.unlimited ? (
                    <p className="font-semibold text-slate-700 dark:text-slate-200">No fixed quota</p>
                  ) : (
                    <>
                      <p className={clsx('font-display text-base font-bold', after !== null && after < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400')}>
                        {after !== null ? `${after < 0 ? 0 : after} left after this` : '—'}
                      </p>
                      <p className="text-slate-500">
                        {bal.used} used · {bal.pending} pending · {bal.quota} / session
                      </p>
                    </>
                  )}
                </div>
              )}
            </div>
            {preview && preview.holidays.length > 0 && (
              <ul className="mt-3 flex flex-wrap gap-1.5">
                {preview.holidays.map((h) => (
                  <li key={h.date} className="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-[11px] font-medium text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">
                    <Sun className="h-3 w-3 text-amber-500" /> {formatDate(h.date)} · {h.title}
                  </li>
                ))}
              </ul>
            )}
            {warnings.length > 0 && (
              <ul className="mt-3 space-y-1">
                {warnings.map(([k, msg]) => (
                  <li key={k} className="flex items-start gap-1.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                    <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" /> {msg}
                  </li>
                ))}
              </ul>
            )}
          </div>

          <Field label="Reason" required htmlFor="lv-reason" error={errors.reason} aside={`${values.reason.length}/500`}>
            <Textarea id="lv-reason" rows={3} maxLength={500} value={values.reason} onChange={(e) => set('reason', e.target.value)} invalid={!!errors.reason} placeholder="Purpose of leave, contact during leave, class arrangements…" />
          </Field>
        </form>
      )}
    </Modal>
  );
}
