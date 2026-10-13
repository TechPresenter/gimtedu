import { useEffect, useState, type FormEvent } from 'react';
import { BadgeCheck, Ban, CircleCheckBig, RotateCcw, ThumbsDown, type LucideIcon } from 'lucide-react';
import { Alert, Button, Field, FileUpload, Input, Modal, PersonCell, Select, Textarea, useToast } from '@/components/ui';
import { api, ApiError, toFormData } from '@/lib/api';
import { isoDate } from '@/lib/format';
import type { Row } from '@/lib/types';
import { CompanyLogo, OFFER_STATUS, lpa, usePlacementRefresh } from './shared';

/** Allowed offer status transitions (mirror of placement_offer_transitions()). */
export const OFFER_TRANSITIONS: Record<string, string[]> = { offered: ['accepted', 'declined', 'revoked'], accepted: ['joined', 'declined', 'revoked'], declined: ['offered'], joined: [], revoked: ['offered'] };

export const OFFER_ACTIONS: Record<string, { label: string; icon: LucideIcon; danger?: boolean; help: string; dateLabel?: string }> = {
  accepted: { label: 'Mark placed', icon: BadgeCheck, help: 'The student accepted the offer and is counted as a confirmed placement.', dateLabel: 'Accepted on' },
  joined: { label: 'Mark joined', icon: CircleCheckBig, help: 'The student has joined the company.', dateLabel: 'Joined on' },
  declined: { label: 'Mark declined', icon: ThumbsDown, danger: true, help: 'The student declined this offer (e.g. accepted a better one).', dateLabel: 'Declined on' },
  revoked: { label: 'Revoke offer', icon: Ban, danger: true, help: 'The company withdrew the offer. The student is no longer counted as placed for it.' },
  offered: { label: 'Reinstate offer', icon: RotateCcw, help: 'Puts the offer back to “offered” (awaiting the student’s response).' },
};

export interface OfferTarget {
  id: number;
  status: string;
  student_name: string;
  company_name: string;
  package_lpa: unknown;
}

/** Change an offer's status with optional remarks / date. */
export function OfferStatusModal({ target, status, onClose }: { target: OfferTarget | null; status: string | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = usePlacementRefresh();
  const [remarks, setRemarks] = useState('');
  const [date, setDate] = useState(isoDate());
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (target && status) {
      setRemarks('');
      setDate(isoDate());
      setError(null);
    }
  }, [target, status]);
  if (!target || !status) return null;
  const def = OFFER_ACTIONS[status];
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const res = await api.post(`placement/offers/${target.id}/status`, { status, remarks, date });
      toast.success(res.message);
      await refresh('placement_offers', 'placement_applications');
      onClose();
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open
      onClose={onClose}
      static={saving}
      size="md"
      title={`${def.label}?`}
      description={`${target.student_name} · ${target.company_name} · ${lpa(target.package_lpa)}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button type="submit" form="offer-status-form" loading={saving} icon={def.icon} variant={def.danger ? 'danger' : status === 'accepted' ? 'success' : 'primary'}>{def.label}</Button>
        </>
      }
    >
      <form id="offer-status-form" onSubmit={submit} noValidate className="space-y-4">
        {error && <Alert variant="error">{error}</Alert>}
        <p className="text-sm text-slate-600 dark:text-slate-300">{def.help}</p>
        {def.dateLabel && (
          <Field label={def.dateLabel} htmlFor="os-date">
            <Input id="os-date" type="date" value={date} max={isoDate()} onChange={(e) => setDate(e.target.value)} />
          </Field>
        )}
        <Field label="Remarks (optional)" htmlFor="os-remarks">
          <Textarea id="os-remarks" rows={2} maxLength={255} value={remarks} onChange={(e) => setRemarks(e.target.value)} />
        </Field>
      </form>
    </Modal>
  );
}

export interface OfferPreset {
  student_id: number;
  student_name: string;
  student_sub?: string;
  student_photo?: string | null;
  company_id: number;
  company_name: string;
  company_logo?: string | null;
  drive_id?: number | null;
  job_role?: string;
  package_lpa?: string | number | null;
  location?: string | null;
}

/** Record an offer for a selected student (student / company / drive fixed from the application). */
export function IssueOfferModal({ preset, onClose }: { preset: OfferPreset | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = usePlacementRefresh();
  const [v, setV] = useState({ job_role: '', package_lpa: '', offer_date: isoDate(), joining_date: '', location: '', status: 'offered', remarks: '' });
  const [file, setFile] = useState<File | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (!preset) return;
    setV({ job_role: preset.job_role ?? '', package_lpa: preset.package_lpa ? String(preset.package_lpa) : '', offer_date: isoDate(), joining_date: '', location: preset.location ?? '', status: 'offered', remarks: '' });
    setFile(null);
    setErrors({});
    setFormError(null);
  }, [preset]);
  if (!preset) return null;
  const set = (k: keyof typeof v, val: string) => {
    setV((s) => ({ ...s, [k]: val }));
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const req: Record<string, string> = {};
    if (!v.job_role.trim()) req.job_role = 'Job role is required.';
    if (!v.package_lpa) req.package_lpa = 'CTC is required.';
    if (!v.offer_date) req.offer_date = 'Offer date is required.';
    if (Object.keys(req).length) {
      setErrors(req);
      setFormError('Please fill in the required fields.');
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const payload: Record<string, unknown> = { ...v, student_id: preset.student_id, company_id: preset.company_id, drive_id: preset.drive_id ?? '' };
      if (file) payload.offer_letter = file;
      const res = await api.post('crud/placement_offers', file ? toFormData(payload) : payload);
      toast.success(res.message || 'Offer recorded.');
      await refresh('placement_offers', 'placement_applications');
      onClose();
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      setFormError(ae.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open
      onClose={onClose}
      static={saving}
      size="lg"
      title="Record offer"
      description="Recording an offer counts the student as placed. Mark it accepted once the student confirms."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button type="submit" form="issue-offer-form" loading={saving} icon={BadgeCheck}>Save offer</Button>
        </>
      }
    >
      <form id="issue-offer-form" onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert variant="error">{formError}</Alert>}
        <div className="grid grid-cols-1 gap-3 rounded-xl bg-slate-50 p-3 sm:grid-cols-2 dark:bg-slate-800/60">
          <PersonCell name={preset.student_name} sub={preset.student_sub} src={preset.student_photo} />
          <div className="flex items-center gap-3">
            <CompanyLogo name={preset.company_name} src={preset.company_logo} />
            <div className="min-w-0 leading-tight">
              <p className="truncate font-semibold text-slate-900 dark:text-white">{preset.company_name}</p>
              <p className="text-xs text-slate-500 dark:text-slate-400">{preset.drive_id ? 'Linked to the drive' : 'Off-campus offer'}</p>
            </div>
          </div>
        </div>
        {(errors.student_id || errors.drive_id || errors.company_id) && <Alert variant="error">{errors.student_id || errors.drive_id || errors.company_id}</Alert>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-12">
          <Field label="Job role" required htmlFor="io-role" error={errors.job_role} className="sm:col-span-8">
            <Input id="io-role" value={v.job_role} maxLength={150} onChange={(e) => set('job_role', e.target.value)} invalid={!!errors.job_role} />
          </Field>
          <Field label="CTC (LPA)" required htmlFor="io-ctc" error={errors.package_lpa} className="sm:col-span-4">
            <Input id="io-ctc" type="number" min={0.5} step={0.01} value={v.package_lpa} onChange={(e) => set('package_lpa', e.target.value)} suffix="LPA" invalid={!!errors.package_lpa} />
          </Field>
          <Field label="Offer date" required htmlFor="io-date" error={errors.offer_date} className="sm:col-span-4">
            <Input id="io-date" type="date" value={v.offer_date} onChange={(e) => set('offer_date', e.target.value)} invalid={!!errors.offer_date} />
          </Field>
          <Field label="Joining date" htmlFor="io-join" error={errors.joining_date} className="sm:col-span-4">
            <Input id="io-join" type="date" value={v.joining_date} onChange={(e) => set('joining_date', e.target.value)} invalid={!!errors.joining_date} />
          </Field>
          <Field label="Location" htmlFor="io-loc" error={errors.location} className="sm:col-span-4">
            <Input id="io-loc" value={v.location} maxLength={150} onChange={(e) => set('location', e.target.value)} />
          </Field>
          <Field label="Status" htmlFor="io-status" error={errors.status} className="sm:col-span-6">
            <Select id="io-status" value={v.status} onChange={(e) => set('status', e.target.value)} options={{ offered: OFFER_STATUS.offered, accepted: OFFER_STATUS.accepted }} />
          </Field>
          <Field label="Remarks" htmlFor="io-remarks" error={errors.remarks} className="sm:col-span-6">
            <Input id="io-remarks" value={v.remarks} maxLength={255} onChange={(e) => set('remarks', e.target.value)} />
          </Field>
          <Field label="Offer letter" error={errors.offer_letter} className="sm:col-span-12" hint="PDF or Word — stored privately.">
            <FileUpload file={file} onFile={setFile} accept=".pdf,.doc,.docx" onError={(m) => toast.error(m)} invalid={!!errors.offer_letter} compact />
          </Field>
        </div>
      </form>
    </Modal>
  );
}

/** Offer preset from a placement_applications CRUD row. */
export function offerPresetFromRow(r: Row): OfferPreset {
  return {
    student_id: Number(r.student_id), student_name: String(r.student_name), student_sub: String(r.student_sub ?? r.student_uid ?? ''), student_photo: r.student_photo,
    company_id: Number(r.company_id), company_name: String(r.company_name), company_logo: r.company_logo, drive_id: Number(r.drive_id),
    job_role: String(r.job_role ?? '').split(',')[0].trim(), package_lpa: r.package_max ?? r.package_min, location: String(r.drive_location ?? '').split('/')[0].trim(),
  };
}
