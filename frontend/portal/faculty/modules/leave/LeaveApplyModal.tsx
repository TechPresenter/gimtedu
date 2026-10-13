import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { CalendarCheck2, CalendarRange, Info, Send } from 'lucide-react';
import { Field, Modal, Select, Spinner, Textarea, Toggle, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import { errorMessage, fieldErrors } from '../../../lib/errors';
import { usePortalQuery } from '../../../lib/hooks';
import type { LeaveData, LeavePreview } from '../../shared/types';
import { addDays, todayIso, useFacultyInvalidate } from '../../shared/ui';

interface Props {
  open: boolean;
  onClose: () => void;
  data: LeaveData;
}

const fmtDays = (n: number) => `${Number.isInteger(n) ? n : n.toFixed(1)} working day${n === 1 ? '' : 's'}`;

export function LeaveApplyModal({ open, onClose, data }: Props) {
  const toast = useToast();
  const invalidate = useFacultyInvalidate();
  const [type, setType] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [half, setHalf] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setType(data.types[0]?.code ?? '');
    setFrom(addDays(todayIso(), 1));
    setTo(addDays(todayIso(), 1));
    setHalf(false);
    setReason('');
    setErrors({});
  }, [open, data.types]);

  const single = !!from && from === to;
  useEffect(() => {
    if (!single && half) setHalf(false);
  }, [single, half]);

  const rawArgs = useMemo(() => ({ leave_type: type, from_date: from, to_date: to || from, is_half_day: half && single ? 1 : 0 }), [type, from, to, half, single]);
  const args = useDebounce(rawArgs, 300);
  const ready = open && !!args.leave_type && !!args.from_date;
  const { data: preview, isFetching } = usePortalQuery<LeavePreview>('faculty', ['leave', 'preview'], 'leave/preview', args, { enabled: ready, staleTime: 0 });
  const lt = useMemo(() => data.types.find((t) => t.code === type), [data.types, type]);
  const bal = preview?.balance ?? data.balance.find((b) => b.code === type) ?? null;
  const previewErrors = ready && preview ? preview.errors : {};
  const err = (k: string) => errors[k] ?? (previewErrors as Record<string, string>)[k];

  const submit = async () => {
    const e: Record<string, string> = {};
    if (!type) e.leave_type = 'Choose the leave type.';
    if (!from) e.from_date = 'Choose the start date.';
    if (!reason.trim()) e.reason = 'Give a short reason for the leave.';
    if (Object.keys(e).length) {
      setErrors(e);
      return;
    }
    setBusy(true);
    setErrors({});
    try {
      const res = await api.post('portal/faculty/leave', { leave_type: type, from_date: from, to_date: to || from, is_half_day: half && single, reason: reason.trim() });
      toast.success(res.message || 'Leave application submitted.');
      await invalidate('leave', 'dashboard');
      onClose();
    } catch (ex) {
      setErrors(fieldErrors(ex));
      toast.error(errorMessage(ex, 'Unable to submit the application.'));
    } finally {
      setBusy(false);
    }
  };

  const minDate = addDays(todayIso(), -data.backdate_days);
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={busy}
      size="lg"
      title="Apply for leave"
      description="Your application goes to the HR / leave approvers. You will be notified when it is decided."
      icon={
        <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300">
          <CalendarRange className="h-5 w-5" aria-hidden />
        </span>
      }
      footer={
        <>
          <button type="button" className="btn btn-secondary" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={() => void submit()} disabled={busy}>
            {busy ? <Spinner className="h-4 w-4" /> : <Send className="h-4 w-4" aria-hidden />} Submit application
          </button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Leave type" required error={err('leave_type')} htmlFor="lv-type" className="sm:col-span-2">
          <Select
            id="lv-type"
            value={type}
            onChange={(e) => setType(e.target.value)}
            invalid={!!err('leave_type')}
            options={data.types.map((t) => {
              const b = data.balance.find((x) => x.code === t.code);
              return { value: t.code, label: `${t.name}${b && !b.unlimited ? ` — ${b.available} of ${b.quota} left` : t.is_paid ? '' : ' (loss of pay)'}` };
            })}
          />
        </Field>
        {lt?.description && (
          <p className="-mt-2 flex items-start gap-1.5 text-xs text-slate-500 sm:col-span-2 dark:text-slate-400">
            <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden /> {lt.description}
            {lt.max_consecutive ? ` Up to ${lt.max_consecutive} working days per application.` : ''}
          </p>
        )}
        <Field label="From" required error={err('from_date')} htmlFor="lv-from">
          <input
            id="lv-from"
            type="date"
            className={clsx('form-input', err('from_date') && 'form-input-error')}
            value={from}
            min={minDate}
            onChange={(e) => {
              setFrom(e.target.value);
              if (!to || to < e.target.value) setTo(e.target.value);
            }}
          />
        </Field>
        <Field label="To" required error={err('to_date')} htmlFor="lv-to">
          <input id="lv-to" type="date" className={clsx('form-input', err('to_date') && 'form-input-error')} value={to} min={from || minDate} onChange={(e) => setTo(e.target.value)} />
        </Field>
        <div className="sm:col-span-2">
          <Toggle checked={half && single} onChange={setHalf} disabled={!single} label="Half day" description={single ? 'Only half of the working day.' : 'Available for a single-day leave.'} />
          {err('is_half_day') && <p className="form-error">{err('is_half_day')}</p>}
        </div>
        <Field label="Reason" required error={err('reason')} htmlFor="lv-reason" className="sm:col-span-2" aside={`${reason.length}/500`}>
          <Textarea id="lv-reason" rows={3} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} invalid={!!err('reason')} placeholder="e.g. Attending a family function in Jaipur" />
        </Field>
      </div>

      <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50" aria-live="polite">
        <div className="flex items-start gap-3">
          <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-brand-800 shadow-sm dark:bg-slate-900 dark:text-brand-200">
            {isFetching ? <Spinner className="h-4 w-4" /> : <CalendarCheck2 className="h-5 w-5" aria-hidden />}
          </span>
          <div className="min-w-0 text-sm">
            {preview?.days ? (
              <>
                <p className="font-bold text-slate-900 dark:text-white">{fmtDays(preview.days.days)}</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  {from && formatDate(from)}
                  {to && to !== from ? ` – ${formatDate(to)}` : ''} · {preview.days.calendar_days} calendar day{preview.days.calendar_days === 1 ? '' : 's'}
                  {preview.days.weekly_offs ? ` · ${preview.days.weekly_offs} weekly off` : ''}
                  {preview.days.holidays.length ? ` · ${preview.days.holidays.map((h) => h.title).join(', ')} excluded` : ''}
                </p>
              </>
            ) : (
              <p className="text-slate-500 dark:text-slate-400">Choose the dates to see the working days counted.</p>
            )}
            {bal && (
              <p className="mt-1.5 text-xs text-slate-600 dark:text-slate-300">
                {bal.unlimited ? (
                  <>
                    {bal.name}: no fixed quota{bal.is_paid ? '' : ' — counted as loss of pay'} · used {bal.used}
                  </>
                ) : (
                  <>
                    {bal.name} balance: <strong>{bal.available}</strong> of {bal.quota} days{bal.pending ? ` · ${bal.pending} pending approval` : ''}
                  </>
                )}
              </p>
            )}
          </div>
        </div>
      </div>
    </Modal>
  );
}
