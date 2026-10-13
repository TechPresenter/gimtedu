import { useEffect, type FormEvent } from 'react';
import { ShieldCheck } from 'lucide-react';
import { Alert, Button, Field, Input, Modal, Select, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { isoDate } from '@/lib/format';
import { useActionForm } from '../hooks';
import { ExpiryCell, RENEWABLE_DOCS, useTransportRefresh } from '../shared';

export interface RenewTarget {
  vehicleId: number;
  vehicleNo: string;
  document?: string | null;
  currentExpiry?: string | null;
  /** Current expiry of every renewable document (insurance, fitness, permit, puc) so switching the document updates the form. */
  expiries?: Partial<Record<string, string | null>>;
}

/** Suggested new expiry: PUC +6 months, permit +5 years, others +1 year from the current expiry (or today when lapsed). */
function suggestExpiry(doc: string, current?: string | null) {
  const base = current && current > isoDate() ? new Date(`${current}T00:00:00`) : new Date();
  const next = new Date(base);
  if (doc === 'puc') next.setMonth(next.getMonth() + 6);
  else next.setFullYear(next.getFullYear() + (doc === 'permit' ? 5 : 1));
  return isoDate(next);
}

/** Renew insurance / fitness / permit / PUC: records the renewal in maintenance and moves the vehicle expiry. */
export function RenewDocumentModal({ target, onClose }: { target: RenewTarget | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = useTransportRefresh();
  const form = useActionForm({ document: 'insurance', expiry: '', date: isoDate(), cost: '', vendor: '', bill_no: '' });
  const { values, set, errors } = form;
  const currentFor = (doc: string) => (target?.expiries && doc in target.expiries ? target.expiries[doc] : target?.document === doc ? target?.currentExpiry : undefined);
  const current = currentFor(values.document);

  useEffect(() => {
    if (!target) return;
    const doc = target.document ?? 'insurance';
    form.reset({ document: doc, expiry: suggestExpiry(doc, currentFor(doc)), date: isoDate(), cost: '', vendor: '', bill_no: '' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [target]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!target) return;
    const ok = await form.run(async () => {
      const res = await api.post(`transport/vehicles/${target.vehicleId}/renew`, values);
      toast.success(res.message || 'Document renewed.');
    });
    if (ok) {
      await refresh('vehicles', 'transport_maintenance');
      onClose();
    }
  };

  return (
    <Modal
      open={!!target}
      onClose={onClose}
      static={form.saving}
      size="md"
      title={`Renew document — ${target?.vehicleNo ?? ''}`}
      description="The renewal is recorded under maintenance and the vehicle's expiry date is updated."
      icon={<span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"><ShieldCheck className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.saving}>Cancel</Button>
          <Button type="submit" form="renew-doc-form" loading={form.saving}>Save renewal</Button>
        </>
      }
    >
      <form id="renew-doc-form" onSubmit={submit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {form.error && <Alert variant="error" className="sm:col-span-2">{form.error}</Alert>}
        <Field label="Document" required htmlFor="rn-doc" error={errors.document} className="sm:col-span-2">
          <Select id="rn-doc" value={values.document} onChange={(e) => {
            set('document', e.target.value);
            set('expiry', suggestExpiry(e.target.value, currentFor(e.target.value)));
          }} options={RENEWABLE_DOCS} invalid={!!errors.document} />
        </Field>
        {current !== undefined && (
          <div className="sm:col-span-2">
            <p className="form-label">Current expiry</p>
            <ExpiryCell date={current} />
          </div>
        )}
        <Field label="New expiry date" required htmlFor="rn-exp" error={errors.expiry}>
          <Input id="rn-exp" type="date" value={values.expiry} onChange={(e) => set('expiry', e.target.value)} invalid={!!errors.expiry} />
        </Field>
        <Field label="Renewed on" htmlFor="rn-date" error={errors.date}>
          <Input id="rn-date" type="date" value={values.date} max={isoDate()} onChange={(e) => set('date', e.target.value)} invalid={!!errors.date} />
        </Field>
        <Field label="Amount paid (₹)" htmlFor="rn-cost" error={errors.cost}>
          <Input id="rn-cost" type="number" min={0} step="1" value={values.cost} onChange={(e) => set('cost', e.target.value)} invalid={!!errors.cost} placeholder="0" />
        </Field>
        <Field label="Policy / receipt no." htmlFor="rn-bill" error={errors.bill_no}>
          <Input id="rn-bill" value={values.bill_no} maxLength={60} onChange={(e) => set('bill_no', e.target.value)} />
        </Field>
        <Field label="Insurer / authority / centre" htmlFor="rn-vendor" error={errors.vendor} className="sm:col-span-2">
          <Input id="rn-vendor" value={values.vendor} maxLength={150} onChange={(e) => set('vendor', e.target.value)} placeholder="e.g. ARTO Greater Noida" />
        </Field>
      </form>
    </Modal>
  );
}
