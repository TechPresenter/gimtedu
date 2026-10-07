import { useEffect, useState } from 'react';
import { Award, BadgeCheck, ClipboardCheck, FileText, IndianRupee, Printer, Receipt, Wallet } from 'lucide-react';
import { Alert, Button, Card, CardHeader, DescriptionList, EmptyState, Field, Input, ProgressBar, Select, StatTile, Textarea, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useInvalidate } from '@/lib/queries';
import { printUrl } from '@/lib/config';
import { formatDate, formatDateTime, formatMoney, isoDate, toNumber } from '@/lib/format';
import { PAYMENT_MODES } from '../shared';
import type { AdmissionProfile } from './types';

/* ------------------------------------------------------------------ Assessment */

export function AssessmentTab({ data }: { data: AdmissionProfile }) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const a = data.admission;
  const locked = !!a.student_id || ['rejected', 'withdrawn'].includes(String(a.stage));
  const canEdit = data.can.edit && !locked;
  const init = () => ({
    entrance_exam: String(a.entrance_exam ?? ''), entrance_score: a.entrance_score ?? '', interview_date: a.interview_date ? String(a.interview_date).replace(' ', 'T').slice(0, 16) : '',
    interview_score: a.interview_score ?? '', interview_remarks: String(a.interview_remarks ?? ''),
  });
  const [v, setV] = useState<Record<string, string | number>>(init);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => setV(init()), [a.entrance_score, a.interview_score, a.interview_date, a.entrance_exam, a.interview_remarks]); // eslint-disable-line react-hooks/exhaustive-deps
  const set = (k: string, val: string) => {
    setV((s) => ({ ...s, [k]: val }));
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };
  const save = async () => {
    const errs: Record<string, string> = {};
    if (v.entrance_score === '' && v.interview_score === '') errs.entrance_score = 'Enter at least one score.';
    if (v.interview_score !== '' && (toNumber(v.interview_score) < 0 || toNumber(v.interview_score) > 100)) errs.interview_score = 'Interview score must be between 0 and 100.';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setSaving(true);
    try {
      const res = await api.post(`admissions/${a.id}/assessment`, v);
      toast.success(res.message);
      await invalidate('adm-profile', 'adm-board', 'crud');
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        <StatTile label="Qualifying exam" value={a.previous_percentage ? `${toNumber(a.previous_percentage).toFixed(1)}%` : '—'} icon={FileText} tone="blue" sub={String(a.previous_qualification ?? '')} />
        <StatTile label={String(a.entrance_exam || 'Entrance score')} value={a.entrance_score !== null && a.entrance_score !== undefined ? toNumber(a.entrance_score).toFixed(1) : '—'} icon={Award} tone="purple" sub="Percentile / score" />
        <StatTile label="Interview score" value={a.interview_score !== null && a.interview_score !== undefined ? `${toNumber(a.interview_score).toFixed(0)}/100` : '—'} icon={ClipboardCheck} tone="green" sub={a.interview_date ? formatDateTime(a.interview_date) : 'Not scheduled'} />
      </div>
      <Card>
        <CardHeader title="Entrance & interview" subtitle="Scores are required before the application can go for approval" icon={ClipboardCheck} />
        <div className="card-body grid gap-4 sm:grid-cols-12">
          <Field className="sm:col-span-6" label="Entrance exam" error={errors.entrance_exam} htmlFor="as-exam">
            <Input id="as-exam" value={String(v.entrance_exam)} onChange={(e) => set('entrance_exam', e.target.value)} disabled={!canEdit} maxLength={60} placeholder="e.g. CUET (UG), JEE Main, CAT" />
          </Field>
          <Field className="sm:col-span-6" label="Entrance score / percentile" error={errors.entrance_score} htmlFor="as-escore">
            <Input id="as-escore" type="number" step="0.01" min={0} max={1000} value={String(v.entrance_score)} onChange={(e) => set('entrance_score', e.target.value)} disabled={!canEdit} invalid={!!errors.entrance_score} />
          </Field>
          <Field className="sm:col-span-6" label="Interview date & time" error={errors.interview_date} htmlFor="as-idate">
            <Input id="as-idate" type="datetime-local" value={String(v.interview_date)} onChange={(e) => set('interview_date', e.target.value)} disabled={!canEdit} invalid={!!errors.interview_date} />
          </Field>
          <Field className="sm:col-span-6" label="Interview score (out of 100)" error={errors.interview_score} htmlFor="as-iscore">
            <Input id="as-iscore" type="number" step="0.5" min={0} max={100} value={String(v.interview_score)} onChange={(e) => set('interview_score', e.target.value)} disabled={!canEdit} invalid={!!errors.interview_score} />
          </Field>
          <Field className="sm:col-span-12" label="Panel remarks" error={errors.interview_remarks} htmlFor="as-remarks">
            <Textarea id="as-remarks" rows={3} value={String(v.interview_remarks)} onChange={(e) => set('interview_remarks', e.target.value)} disabled={!canEdit} maxLength={500} placeholder="Communication, aptitude, clarity of goals…" />
          </Field>
          {canEdit && (
            <div className="flex justify-end sm:col-span-12">
              <Button onClick={save} loading={saving}>
                Save assessment
              </Button>
            </div>
          )}
          {locked && <p className="text-xs text-slate-500 sm:col-span-12">Scores can no longer be changed for {a.student_id ? 'enrolled applicants' : 'closed applications'}.</p>}
        </div>
      </Card>
    </div>
  );
}

/* ------------------------------------------------------------------ Fees */

export function FeesTab({ data }: { data: AdmissionProfile }) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const a = data.admission;
  const due = toNumber(a.admission_fee ?? data.default_fee);
  const paid = toNumber(a.fee_paid);
  const balance = Math.max(0, due - paid);
  const payable = ['fee_payment', 'confirmed'].includes(String(a.stage));
  const canRecord = data.can.fee && payable && balance > 0;
  const [v, setV] = useState({ admission_fee: String(due), amount: String(balance || ''), mode: 'upi', reference: '', paid_on: isoDate() });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => setV((s) => ({ ...s, admission_fee: String(due), amount: String(balance || '') })), [due, balance]);
  const set = (k: keyof typeof v, val: string) => {
    setV((s) => ({ ...s, [k]: val }));
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };
  const save = async () => {
    const errs: Record<string, string> = {};
    if (!(toNumber(v.amount) > 0)) errs.amount = 'Enter the amount received.';
    if (v.mode !== 'cash' && !v.reference.trim()) errs.reference = 'Enter the transaction / UTR / cheque reference.';
    if (!v.paid_on) errs.paid_on = 'Select the payment date.';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setSaving(true);
    try {
      const res = await api.post(`admissions/${a.id}/fee`, { ...v, admission_fee: paid > 0 ? undefined : v.admission_fee });
      toast.success(res.message);
      await invalidate('adm-profile', 'adm-board', 'adm-dashboard', 'crud');
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  const status = !payable && paid <= 0 ? 'Not yet payable' : balance <= 0 ? 'Paid' : paid > 0 ? 'Partially paid' : 'Pending';
  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        <StatTile label="Admission fee" value={formatMoney(due)} icon={IndianRupee} tone="navy" sub={a.admission_fee ? 'As per offer' : 'Default for program'} />
        <StatTile label="Received" value={formatMoney(paid)} icon={Wallet} tone="green" sub={a.fee_paid_on ? `Last on ${formatDate(a.fee_paid_on)}` : 'No payment yet'} />
        <StatTile label="Balance" value={formatMoney(balance)} icon={Receipt} tone={balance > 0 ? 'amber' : 'green'} sub={status} />
      </div>
      <Card>
        <CardHeader title="Admission fee" subtitle={status} icon={IndianRupee}
          actions={paid > 0 ? <Button size="sm" variant="secondary" icon={Printer} onClick={() => window.open(printUrl('admission-receipt.php', { id: Number(a.id) }), '_blank')}>Receipt</Button> : undefined} />
        <div className="card-body space-y-5">
          <ProgressBar value={due ? (paid / due) * 100 : 0} tone={balance <= 0 ? 'green' : 'amber'} label="Admission fee paid" />
          {!payable && paid <= 0 && (
            <Alert variant="info" title="Fee becomes payable after approval">
              The admission fee can be recorded once the application is approved and moves to the Fee Payment stage.
            </Alert>
          )}
          {paid > 0 && (
            <DescriptionList
              columns={3}
              items={[
                { label: 'Latest receipt', value: a.fee_receipt_no ? <span className="font-mono">{String(a.fee_receipt_no)}</span> : null },
                { label: 'Payment mode', value: a.fee_mode ? PAYMENT_MODES[String(a.fee_mode)] ?? String(a.fee_mode) : null },
                { label: 'Reference', value: a.fee_reference ? <span className="font-mono text-xs">{String(a.fee_reference)}</span> : null },
                { label: 'Paid on', value: a.fee_paid_on ? formatDate(a.fee_paid_on) : null },
                { label: 'Offer letter', value: a.offer_letter_no ? <span className="font-mono text-xs">{String(a.offer_letter_no)}</span> : null },
                { label: 'Posted to fees', value: data.payment ? <span className="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"><BadgeCheck className="h-4 w-4" />{data.payment.invoice_no ?? 'Invoice'} · {data.payment.receipt_no}</span> : a.student_id ? 'Recorded on the application only' : 'After conversion to student' },
              ]}
            />
          )}
          {canRecord && (
            <div className="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
              <h3 className="mb-3 text-sm font-semibold text-slate-800 dark:text-slate-100">Record payment</h3>
              <div className="grid gap-4 sm:grid-cols-12">
                <Field className="sm:col-span-4" label="Admission fee (total)" error={errors.admission_fee} htmlFor="fee-due" hint={paid > 0 ? 'Locked after the first payment' : 'Edit for concessions'}>
                  <Input id="fee-due" type="number" min={1} prefix="₹" value={v.admission_fee} onChange={(e) => set('admission_fee', e.target.value)} disabled={paid > 0} invalid={!!errors.admission_fee} />
                </Field>
                <Field className="sm:col-span-4" label="Amount received" required error={errors.amount} htmlFor="fee-amount">
                  <Input id="fee-amount" type="number" min={1} prefix="₹" value={v.amount} onChange={(e) => set('amount', e.target.value)} invalid={!!errors.amount} />
                </Field>
                <Field className="sm:col-span-4" label="Payment date" required error={errors.paid_on} htmlFor="fee-date">
                  <Input id="fee-date" type="date" max={isoDate()} value={v.paid_on} onChange={(e) => set('paid_on', e.target.value)} invalid={!!errors.paid_on} />
                </Field>
                <Field className="sm:col-span-4" label="Mode" required error={errors.mode} htmlFor="fee-mode">
                  <Select id="fee-mode" value={v.mode} onChange={(e) => set('mode', e.target.value)} options={PAYMENT_MODES} />
                </Field>
                <Field className="sm:col-span-8" label="Reference / UTR / cheque no." required={v.mode !== 'cash'} error={errors.reference} htmlFor="fee-ref">
                  <Input id="fee-ref" value={v.reference} onChange={(e) => set('reference', e.target.value)} maxLength={100} invalid={!!errors.reference} placeholder={v.mode === 'cash' ? 'Optional for cash' : 'e.g. UPI/412233445566'} />
                </Field>
                <div className="flex justify-end sm:col-span-12">
                  <Button variant="success" icon={IndianRupee} loading={saving} onClick={save}>
                    Record payment
                  </Button>
                </div>
              </div>
            </div>
          )}
          {payable && balance <= 0 && !a.student_id && (
            <Alert variant="success" title="Admission fee fully paid">Confirm the admission and convert the applicant to a student from the actions above.</Alert>
          )}
          {!data.can.fee && payable && balance > 0 && <EmptyState icon={FileText} title="Fee collection restricted" description="Your role cannot record fee payments. Ask the accounts office to record the admission fee." className="!py-6" />}
        </div>
      </Card>
    </div>
  );
}
