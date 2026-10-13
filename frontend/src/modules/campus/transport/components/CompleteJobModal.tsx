import { useEffect, type FormEvent } from 'react';
import { CheckCircle2 } from 'lucide-react';
import { Alert, Button, Field, Input, Modal, Textarea, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { formatDate, isoDate } from '@/lib/format';
import { useActionForm } from '../hooks';
import { MAINTENANCE_TYPES, useTransportRefresh } from '../shared';

export interface JobTarget {
  id: number;
  vehicle_no: string;
  type: string;
  service_date: string;
  cost?: number | string | null;
  vendor?: string | null;
  description?: string | null;
  odometer?: number | null;
}

const RENEWALS = ['insurance', 'fitness', 'permit', 'puc'];

/** Close a scheduled / in-workshop maintenance job with the final cost, odometer and next due. */
export function CompleteJobModal({ job, onClose }: { job: JobTarget | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = useTransportRefresh();
  const form = useActionForm({ service_date: isoDate(), cost: '', odometer: '', vendor: '', bill_no: '', next_service_date: '', next_service_km: '', description: '' });
  const { values, set, errors } = form;
  const renewal = job ? RENEWALS.includes(job.type) : false;

  useEffect(() => {
    if (!job) return;
    const next = new Date();
    if (job.type === 'puc') next.setMonth(next.getMonth() + 6);
    else if (RENEWALS.includes(job.type)) next.setFullYear(next.getFullYear() + 1);
    else next.setDate(next.getDate() + 90);
    const today = isoDate();
    form.reset({
      service_date: job.service_date && job.service_date < today ? job.service_date : today,
      cost: job.cost ? String(Math.round(Number(job.cost))) : '', odometer: job.odometer ? String(job.odometer) : '', vendor: job.vendor ?? '', bill_no: '',
      next_service_date: job.type === 'repair' || job.type === 'tyre' || job.type === 'battery' ? '' : isoDate(next),
      next_service_km: job.type === 'service' && job.odometer ? String(job.odometer + 9000) : '', description: job.description ?? '',
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [job]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!job) return;
    const ok = await form.run(async () => {
      const res = await api.post(`transport/maintenance/${job.id}/complete`, values);
      toast.success(res.message || 'Job completed.');
    });
    if (ok) {
      await refresh('transport_maintenance', 'vehicles');
      onClose();
    }
  };

  return (
    <Modal
      open={!!job}
      onClose={onClose}
      static={form.saving}
      size="lg"
      title={`Complete job — ${job?.vehicle_no ?? ''}`}
      description={job ? `${MAINTENANCE_TYPES[job.type] ?? job.type} planned for ${formatDate(job.service_date)}. The vehicle returns to service when no other workshop job is open.` : undefined}
      icon={<span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"><CheckCircle2 className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.saving}>Cancel</Button>
          <Button type="submit" form="complete-job-form" loading={form.saving}>Mark completed</Button>
        </>
      }
    >
      <form id="complete-job-form" onSubmit={submit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-6">
        {form.error && <Alert variant="error" className="sm:col-span-6">{form.error}</Alert>}
        <Field label="Completed on" required htmlFor="cj-date" error={errors.service_date} className="sm:col-span-2">
          <Input id="cj-date" type="date" max={isoDate()} value={values.service_date} onChange={(e) => set('service_date', e.target.value)} invalid={!!errors.service_date} />
        </Field>
        <Field label="Final cost (₹)" htmlFor="cj-cost" error={errors.cost} className="sm:col-span-2">
          <Input id="cj-cost" type="number" min={0} value={values.cost} onChange={(e) => set('cost', e.target.value)} invalid={!!errors.cost} />
        </Field>
        <Field label="Odometer (km)" htmlFor="cj-odo" error={errors.odometer} className="sm:col-span-2">
          <Input id="cj-odo" type="number" min={0} value={values.odometer} onChange={(e) => set('odometer', e.target.value)} invalid={!!errors.odometer} />
        </Field>
        <Field label="Vendor / workshop" htmlFor="cj-vendor" error={errors.vendor} className="sm:col-span-4">
          <Input id="cj-vendor" value={values.vendor} maxLength={150} onChange={(e) => set('vendor', e.target.value)} />
        </Field>
        <Field label="Bill no." htmlFor="cj-bill" error={errors.bill_no} className="sm:col-span-2">
          <Input id="cj-bill" value={values.bill_no} maxLength={60} onChange={(e) => set('bill_no', e.target.value)} />
        </Field>
        <Field label={renewal ? 'New document expiry' : 'Next service due'} required={renewal} htmlFor="cj-next" error={errors.next_service_date} className="sm:col-span-3">
          <Input id="cj-next" type="date" value={values.next_service_date} onChange={(e) => set('next_service_date', e.target.value)} invalid={!!errors.next_service_date} />
        </Field>
        {!renewal && (
          <Field label="Next due at (km)" htmlFor="cj-km" error={errors.next_service_km} className="sm:col-span-3">
            <Input id="cj-km" type="number" min={0} value={values.next_service_km} onChange={(e) => set('next_service_km', e.target.value)} invalid={!!errors.next_service_km} />
          </Field>
        )}
        <Field label="Work done" htmlFor="cj-desc" error={errors.description} className="sm:col-span-6">
          <Textarea id="cj-desc" rows={2} maxLength={500} value={values.description} onChange={(e) => set('description', e.target.value)} />
        </Field>
      </form>
    </Modal>
  );
}
