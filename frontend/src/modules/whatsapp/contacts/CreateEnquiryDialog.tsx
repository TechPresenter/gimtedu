/**
 * "Create admission enquiry" — turns a WhatsApp contact into an ERP enquiry (POST whatsapp/contacts/{id}/enquiry) or links
 * the open enquiry of the same number, so leads that start on WhatsApp reach Admissions › Enquiries. Used by the inbox
 * contact panel and the contact record (kept out of crmUi so the inbox bundle stays small).
 */
import { useEffect, useState } from 'react';
import { ClipboardPlus } from 'lucide-react';
import { Alert, Button, Field, Input, Modal, Select, Textarea, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { stageInfo, useCrmRefresh, useFacets, type EnquiryResult } from './crm';

export interface EnquiryContact {
  id: number;
  name: string | null;
  program_id: number | null;
  program_name?: string | null;
  city: string | null;
  counsellor_id: number | null;
  stage?: string | null;
}

export function CreateEnquiryDialog({
  open,
  onClose,
  contact,
  origin,
  onDone,
}: {
  open: boolean;
  onClose: () => void;
  contact: EnquiryContact;
  origin: 'inbox' | 'contact';
  onDone?: (r: EnquiryResult) => void;
}) {
  const { data: facets } = useFacets();
  const toast = useToast();
  const refresh = useCrmRefresh();
  const [v, setV] = useState({ name: '', program_id: '', city: '', assigned_to: '', message: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setFailure(null);
    setV({ name: contact.name ?? '', program_id: contact.program_id ? String(contact.program_id) : '', city: contact.city ?? '', assigned_to: contact.counsellor_id ? String(contact.counsellor_id) : '', message: '' });
    // reset only when the dialog opens (the inbox re-polls the contact while someone types)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, contact.id]);

  const set = (k: keyof typeof v, val: string) => setV((p) => ({ ...p, [k]: val }));
  const options = (list?: { value: string | number; label: string }[]) => (list ?? []).map((o) => ({ value: String(o.value), label: o.label }));
  const stage = contact.stage ? stageInfo(contact.stage, facets).label : null;

  const save = async () => {
    if (!v.name.trim()) {
      setErrors({ name: 'Enter the student’s name.' });
      return;
    }
    setSaving(true);
    setErrors({});
    setFailure(null);
    try {
      const res = await api.post<EnquiryResult>(`whatsapp/contacts/${contact.id}/enquiry`, {
        origin,
        name: v.name.trim(),
        program_id: v.program_id || null,
        city: v.city.trim() || null,
        assigned_to: v.assigned_to || null,
        message: v.message.trim() || null,
      });
      toast.success(res.message);
      refresh();
      onDone?.(res.data);
      onClose();
    } catch (e) {
      const fe = e instanceof ApiError ? e.errors ?? {} : {};
      setErrors(fe);
      if (!Object.keys(fe).length) setFailure((e as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="lg"
      icon={
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">
          <ClipboardPlus className="h-5 w-5" aria-hidden />
        </span>
      }
      title="Create admission enquiry"
      description="Adds this WhatsApp lead to Admissions › Enquiries so the admissions team can follow it up and convert it to an application."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button icon={ClipboardPlus} onClick={save} loading={saving}>
            Create enquiry
          </Button>
        </>
      }
    >
      <form
        className="grid grid-cols-1 gap-4 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault();
          save();
        }}
      >
        {failure && (
          <Alert variant="error" className="sm:col-span-2">
            {failure}
          </Alert>
        )}
        <Field label="Student name" required error={errors.name} htmlFor="wa-enq-name">
          <Input id="wa-enq-name" value={v.name} onChange={(e) => set('name', e.target.value)} invalid={!!errors.name} maxLength={150} autoFocus />
        </Field>
        <Field
          label="Programme of interest"
          error={errors.program_id}
          htmlFor="wa-enq-program"
          hint={!v.program_id && contact.program_name ? `Kept as “${contact.program_name}” when no programme is chosen.` : undefined}
        >
          <Select id="wa-enq-program" value={v.program_id} onChange={(e) => set('program_id', e.target.value)} options={options(facets?.programs)} placeholder="— Not decided —" />
        </Field>
        <Field label="City" error={errors.city} htmlFor="wa-enq-city">
          <Input id="wa-enq-city" value={v.city} onChange={(e) => set('city', e.target.value)} maxLength={80} />
        </Field>
        <Field label="Assigned counsellor" error={errors.assigned_to} htmlFor="wa-enq-counsellor" hint="Gets an “Enquiry assigned to you” notification.">
          <Select id="wa-enq-counsellor" value={v.assigned_to} onChange={(e) => set('assigned_to', e.target.value)} options={options(facets?.counsellors)} placeholder="— Unassigned —" />
        </Field>
        <Field label="Enquiry / message" error={errors.message} htmlFor="wa-enq-message" className="sm:col-span-2" hint="Leave empty to use the student’s latest WhatsApp message.">
          <Textarea id="wa-enq-message" value={v.message} onChange={(e) => set('message', e.target.value)} rows={2} maxLength={5000} placeholder="e.g. Asked about MBA fees and hostel" />
        </Field>
        <ul className="space-y-1 rounded-xl border border-slate-200 bg-slate-50/70 p-3 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-300 sm:col-span-2">
          <li>
            Source <b>WhatsApp</b>
            {stage ? (
              <>
                {' '}
                · status from the lead stage (<b>{stage}</b>)
              </>
            ) : null}{' '}
            · next follow-up tomorrow.
          </li>
          <li>If this number already has an open enquiry, the contact is linked to it instead of creating a duplicate.</li>
          <li>The admissions team{v.assigned_to ? ' and the assigned counsellor are' : ' is'} notified. No WhatsApp message is sent to the student.</li>
        </ul>
      </form>
    </Modal>
  );
}
