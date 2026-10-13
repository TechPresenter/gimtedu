import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { PencilLine } from 'lucide-react';
import { Button, Field, Input, Modal, Select, Textarea } from '@/components/ui';
import { areaPath } from '../../lib/config';
import { fieldErrors } from '../../lib/errors';
import { useStudentPost } from './ui';

const FIELDS: Record<string, string> = {
  name: 'Name (spelling)',
  dob: 'Date of birth',
  gender: 'Gender',
  father_name: "Father's name",
  mother_name: "Mother's name",
  mobile: 'Mobile number',
  email: 'Email address',
  address: 'Address',
  category: 'Category',
  blood_group: 'Blood group',
  guardian: 'Parent / guardian details',
  photo: 'Photograph',
  academic: 'Program / section / roll number',
  other: 'Something else',
};

/** "Request a correction" for profile details -> administration ticket tracked under Grievances & Feedback. */
export function CorrectionModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const navigate = useNavigate();
  const blank = { field: '', correct_value: '', details: '' };
  const [form, setForm] = useState(blank);
  const post = useStudentPost<typeof form, { id: number; ticket_no: string }>('profile/correction', {
    invalidate: [['tickets'], ['overview']],
    onSuccess: (d) => {
      setForm(blank);
      onClose();
      navigate(`${areaPath('student', 'helpdesk')}?ticket=${d.id}`);
    },
  });
  const errors = fieldErrors(post.error);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    post.mutate(form);
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Request a correction"
      description="The academic office checks your documents and updates the record."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="correction" loading={post.isPending} icon={PencilLine}>
            Send request
          </Button>
        </>
      }
    >
      <form id="correction" onSubmit={submit} className="space-y-4" noValidate>
        <Field label="Which detail is wrong?" required error={errors.field} htmlFor="cr-field">
          <Select id="cr-field" value={form.field} onChange={(e) => setForm((f) => ({ ...f, field: e.target.value }))} options={FIELDS} placeholder="Choose…" invalid={!!errors.field} />
        </Field>
        <Field label="Correct value" required error={errors.correct_value} htmlFor="cr-value" hint="Exactly as it appears on your official documents.">
          <Input id="cr-value" value={form.correct_value} maxLength={255} onChange={(e) => setForm((f) => ({ ...f, correct_value: e.target.value }))} invalid={!!errors.correct_value} />
        </Field>
        <Field label="Anything else?" error={errors.details} htmlFor="cr-details" aside={`${form.details.length}/1000`}>
          <Textarea id="cr-details" rows={3} value={form.details} maxLength={1000} onChange={(e) => setForm((f) => ({ ...f, details: e.target.value }))} placeholder="e.g. Spelling differs from my Class 12 marksheet." invalid={!!errors.details} />
        </Field>
        <p className="text-xs text-slate-500 dark:text-slate-400">Keep a copy of the supporting document handy — the office may ask to see it.</p>
      </form>
    </Modal>
  );
}
