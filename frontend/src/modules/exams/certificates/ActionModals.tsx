import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import { Ban, FileX, RotateCcw, Settings2 } from 'lucide-react';
import { Alert, Button, Field, Input, Modal, Select, Skeleton, Textarea, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { isoDate } from '@/lib/format';
import { useCertConfig } from './shared';
import type { CertSettings, CertificateDetail } from './types';

export interface CertRef {
  id: number;
  certificate_no: string;
  type: string;
  type_label?: string;
  student_name?: string;
}

/** Invalidate everything that shows certificate data. */
export function useRefreshCertificates() {
  const qc = useQueryClient();
  return () =>
    Promise.all([
      qc.invalidateQueries({ queryKey: ['crud', 'certificates'] }),
      qc.invalidateQueries({ queryKey: ['cert-summary'] }),
      qc.invalidateQueries({ queryKey: ['cert-detail'] }),
      qc.invalidateQueries({ queryKey: ['cert-student'] }),
    ]);
}

const PRESETS = {
  revoke: ['Issued in error', 'Details were incorrect — a corrected certificate will be reissued', 'Disciplinary committee decision', 'Original reported lost or misused', 'Student withdrew the request'],
  reject: ['Supporting documents are incomplete', 'Outstanding fee dues', 'Duplicate request', 'Student is not eligible for this certificate', 'Details could not be verified'],
};

/** Revoke an issued certificate or reject a pending request (reason required). */
export function ReasonModal({ mode, cert, onClose, onDone }: { mode: 'revoke' | 'reject'; cert: CertRef | null; onClose: () => void; onDone?: () => void }) {
  const toast = useToast();
  const refresh = useRefreshCertificates();
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    setReason('');
    setError(null);
  }, [cert?.id, mode]);
  const revoke = mode === 'revoke';
  const submit = async () => {
    if (!cert) return;
    setSaving(true);
    setError(null);
    try {
      const res = await api.post(`certificates/${cert.id}/${mode}`, { reason });
      toast.success(res.message);
      await refresh();
      onDone?.();
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.reason ?? null);
      if (!err.errors?.reason) toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={!!cert}
      onClose={onClose}
      size="md"
      static={saving}
      title={revoke ? `Revoke ${cert?.certificate_no ?? ''}` : `Reject request ${cert?.certificate_no ?? ''}`}
      description={revoke ? 'The public verification page will report this certificate as revoked. This cannot be undone — reissue to replace it.' : 'The requester is notified with your reason.'}
      icon={<span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/15">{revoke ? <Ban className="h-5 w-5" /> : <FileX className="h-5 w-5" />}</span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="danger" icon={revoke ? Ban : FileX} loading={saving} onClick={submit}>{revoke ? 'Revoke certificate' : 'Reject request'}</Button>
        </>
      }
    >
      {cert?.student_name && (
        <p className="mb-4 text-sm text-slate-600 dark:text-slate-300">
          {cert.type_label} for <strong className="text-slate-900 dark:text-white">{cert.student_name}</strong>
        </p>
      )}
      <Field label="Reason" required error={error} htmlFor="cert-reason" aside={`${reason.length}/230`}>
        <Textarea id="cert-reason" rows={3} maxLength={230} value={reason} onChange={(e) => setReason(e.target.value)} invalid={!!error} placeholder={revoke ? 'Why is this certificate being withdrawn?' : 'Why is this request being rejected?'} />
      </Field>
      <div className="mt-3 flex flex-wrap gap-1.5">
        {PRESETS[mode].map((p) => (
          <button key={p} type="button" onClick={() => setReason(p)} className={clsx('rounded-full px-2.5 py-1 text-xs font-medium ring-1 transition', reason === p ? 'bg-red-600 text-white ring-red-600' : 'bg-white text-slate-600 ring-slate-200 hover:ring-red-300 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700')}>
            {p}
          </button>
        ))}
      </div>
    </Modal>
  );
}

/** Reissue: new number + same student/type; optionally corrected details. The old certificate is superseded. */
export function ReissueModal({ cert, rawFields, onClose, onDone }: { cert: CertRef | null; rawFields?: Record<string, string>; onClose: () => void; onDone?: (newId: number) => void }) {
  const toast = useToast();
  const refresh = useRefreshCertificates();
  const { data: config } = useCertConfig();
  // Original extra fields: passed by the drawer, otherwise loaded with the certificate detail.
  const detail = useApi<CertificateDetail>(['cert-detail', cert?.id ?? null], `certificates/${cert?.id}`, undefined, { enabled: !!cert && !rawFields });
  const raw = rawFields ?? detail.data?.raw_fields;
  const [reason, setReason] = useState('');
  const [issueDate, setIssueDate] = useState(isoDate());
  const [edit, setEdit] = useState(false);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  // Reset when another certificate is opened; load its original details once they arrive. A background refetch of the
  // detail (same values) must not wipe what the user is typing, hence the JSON key instead of the object identity.
  const rawKey = JSON.stringify(raw ?? null);
  useEffect(() => {
    setReason('');
    setIssueDate(isoDate());
    setEdit(false);
    setErrors({});
  }, [cert?.id]);
  useEffect(() => {
    setFields({ ...(raw ?? {}) });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cert?.id, rawKey]);
  const typeDef = config?.types.find((t) => t.key === cert?.type);
  const defs = (typeDef?.fields ?? []).map((k) => config?.fields[k]).filter((f): f is NonNullable<typeof f> => !!f);

  const submit = async () => {
    if (!cert) return;
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post<{ id: number; certificate_no: string; status: string }>(`certificates/${cert.id}/reissue`, { reason, issue_date: issueDate, fields: edit ? fields : {} });
      toast.success(res.message);
      await refresh();
      onDone?.(res.data.id);
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (err.errors?.fields || Object.keys(err.errors ?? {}).some((k) => k.startsWith('fields.'))) setEdit(true);
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={!!cert}
      onClose={onClose}
      size="lg"
      static={saving}
      title={`Reissue ${cert?.certificate_no ?? ''}`}
      description="A new certificate number and QR code are generated from the current template and student record. The original is marked as superseded."
      icon={<span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200"><RotateCcw className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button icon={RotateCcw} loading={saving} onClick={submit}>Reissue certificate</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-3">
        <Field label="Reason for reissue" required error={errors.reason} htmlFor="reissue-reason" className="sm:col-span-2">
          <Input id="reissue-reason" value={reason} maxLength={230} onChange={(e) => setReason(e.target.value)} invalid={!!errors.reason} placeholder="e.g. Name spelling corrected, duplicate copy requested" />
        </Field>
        <Field label="Issue date" required error={errors.issue_date} htmlFor="reissue-date">
          <Input id="reissue-date" type="date" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} invalid={!!errors.issue_date} />
        </Field>
      </div>
      {defs.length > 0 && (
        <div className="mt-5 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
          <Toggle checked={edit} onChange={setEdit} label={`Correct the ${typeDef?.short ?? ''} details`} description="Leave off to reuse the original details." />
          {edit && (
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              {defs.map((f) => (
                <Field key={f.key} label={f.label} required={typeDef?.required.includes(f.key)} error={errors[`fields.${f.key}`]} htmlFor={`rf-${f.key}`}>
                  {f.type === 'select' ? (
                    <Select id={`rf-${f.key}`} value={fields[f.key] ?? ''} onChange={(e) => setFields((s) => ({ ...s, [f.key]: e.target.value }))} options={(f.options ?? []).map((o) => ({ value: o, label: o }))} placeholder="Select…" />
                  ) : (
                    <Input id={`rf-${f.key}`} type={f.type === 'date' ? 'date' : 'text'} maxLength={f.maxlength} value={fields[f.key] ?? ''} onChange={(e) => setFields((s) => ({ ...s, [f.key]: e.target.value }))} invalid={!!errors[`fields.${f.key}`]} />
                  )}
                </Field>
              ))}
            </div>
          )}
        </div>
      )}
      {errors._placeholders && <Alert variant="error" className="mt-4">{errors._placeholders}</Alert>}
      {errors.type && <Alert variant="error" className="mt-4">{errors.type}</Alert>}
    </Modal>
  );
}

/** Workflow settings (certificates:manage). */
export function SettingsModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const { data, isLoading, error } = useApi<CertSettings>(['cert-settings'], 'certificates/settings', undefined, { enabled: open });
  const [form, setForm] = useState<CertSettings | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (data && open) setForm(data);
  }, [data, open]);
  const save = async () => {
    if (!form) return;
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post<CertSettings>('certificates/settings', form);
      toast.success(res.message);
      await Promise.all([qc.invalidateQueries({ queryKey: ['cert-settings'] }), qc.invalidateQueries({ queryKey: ['cert-config'] }), qc.invalidateQueries({ queryKey: ['cert-summary'] })]);
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="md"
      title="Certificate settings"
      description="Approval workflow, notifications, numbering and public verification."
      icon={<span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200"><Settings2 className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button loading={saving} disabled={!form} onClick={save}>Save settings</Button>
        </>
      }
    >
      {error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : isLoading || !form ? (
        <div className="space-y-4">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>
      ) : (
        <div className="space-y-5">
          <Toggle checked={form.approval_required} onChange={(v) => setForm({ ...form, approval_required: v })} label="Require approval before issuing"
            description="Certificates requested by staff without the approve permission wait in the Pending approval queue." />
          <Toggle checked={form.email_student} onChange={(v) => setForm({ ...form, email_student: v })} label="E-mail the student when a certificate is issued"
            description="Sends the certificate number and the verification link to the student's e-mail address." />
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Number prefix" required error={errors.prefix} htmlFor="cs-prefix" hint={`e.g. ${form.prefix || 'GIMT'}-BON-${new Date().getFullYear()}-00001`}>
              <Input id="cs-prefix" value={form.prefix} maxLength={10} onChange={(e) => setForm({ ...form, prefix: e.target.value.toUpperCase() })} invalid={!!errors.prefix} />
            </Field>
            <Field label="Public lookups per 10 min" required error={errors.verify_rate_limit} htmlFor="cs-rate" hint="Per visitor IP address.">
              <Input id="cs-rate" type="number" min={5} max={500} value={form.verify_rate_limit} onChange={(e) => setForm({ ...form, verify_rate_limit: Number(e.target.value) })} invalid={!!errors.verify_rate_limit} />
            </Field>
          </div>
        </div>
      )}
    </Modal>
  );
}
