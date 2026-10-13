import { useEffect, useState } from 'react';
import { Ban, CheckCircle2 } from 'lucide-react';
import { Button, Field, Input, Modal, Select, Textarea, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatMoney, isoDate } from '@/lib/format';
import type { Row } from '@/lib/types';
import { PAYMENT_MODE_LABELS } from './shared';

/** Pledged → received: capture mode, reference and date. */
export function ReceivePledgeModal({ donation, onClose, onDone }: { donation: Row | null; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [mode, setMode] = useState('upi');
  const [ref, setRef] = useState('');
  const [date, setDate] = useState(isoDate());
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (donation) {
      setMode(String(donation.payment_mode ?? 'upi'));
      setRef('');
      setDate(isoDate());
      setErrors({});
    }
  }, [donation]);
  const submit = async () => {
    if (!donation) return;
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post(`alumni/donations/${donation.id}/status`, { status: 'received', payment_mode: mode, reference_no: ref, donation_date: date });
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      const ae = e as ApiError;
      setErrors(ae.errors ?? {});
      if (!Object.keys(ae.errors ?? {}).length) toast.error(ae.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={!!donation}
      onClose={onClose}
      static={saving}
      size="md"
      title="Mark pledge as received"
      description={donation ? `${donation.receipt_no} · ${formatMoney(donation.amount)} from ${donation.donor_name}` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="success" icon={CheckCircle2} loading={saving} onClick={submit}>Mark received</Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Payment mode" required htmlFor="rp-mode" error={errors.payment_mode}>
          <Select id="rp-mode" value={mode} onChange={(e) => setMode(e.target.value)} options={Object.entries(PAYMENT_MODE_LABELS).map(([value, label]) => ({ value, label }))} invalid={!!errors.payment_mode} />
        </Field>
        <Field label="Received on" required htmlFor="rp-date" error={errors.donation_date}>
          <Input id="rp-date" type="date" value={date} max={isoDate()} onChange={(e) => setDate(e.target.value)} invalid={!!errors.donation_date} />
        </Field>
        <Field label="Reference / UTR / cheque no." required={mode !== 'cash'} htmlFor="rp-ref" error={errors.reference_no} className="sm:col-span-2">
          <Input id="rp-ref" value={ref} onChange={(e) => setRef(e.target.value)} invalid={!!errors.reference_no} placeholder={mode === 'cash' ? 'Optional for cash' : 'e.g. 412345678901'} />
        </Field>
      </div>
    </Modal>
  );
}

/** Cancel a donation with a reason (received donations cannot be deleted). */
export function CancelDonationModal({ donation, onClose, onDone }: { donation: Row | null; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [reason, setReason] = useState('');
  const [err, setErr] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (donation) {
      setReason('');
      setErr(null);
    }
  }, [donation]);
  const submit = async () => {
    if (!donation) return;
    setSaving(true);
    setErr(null);
    try {
      const res = await api.post(`alumni/donations/${donation.id}/status`, { status: 'cancelled', reason });
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      const ae = e as ApiError;
      setErr(ae.errors?.reason ?? ae.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={!!donation}
      onClose={onClose}
      static={saving}
      size="md"
      title="Cancel donation"
      description={donation ? `${donation.receipt_no} · ${formatMoney(donation.amount)} from ${donation.donor_name}` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Keep donation</Button>
          <Button variant="danger" icon={Ban} loading={saving} onClick={submit}>Cancel donation</Button>
        </>
      }
    >
      <Field label="Reason" required htmlFor="cd-reason" error={err} hint="Recorded in the donation remarks and the activity log. The receipt number stays reserved.">
        <Textarea id="cd-reason" rows={3} value={reason} invalid={!!err} onChange={(e) => { setReason(e.target.value); setErr(null); }} placeholder="e.g. Cheque bounced / payment reversed by the bank" />
      </Field>
    </Modal>
  );
}
