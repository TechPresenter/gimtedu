import { useState } from 'react';
import clsx from 'clsx';
import { CheckCircle2, Circle, Download, Eye, FileImage, FileText, RotateCcw, ShieldCheck, Trash2, Upload, XCircle } from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, Field, FileUpload, IconButton, Input, Modal, ProgressBar, Select, StatusBadge, Textarea, useConfirm, useToast } from '@/components/ui';
import { api, ApiError, apiUrl, toFormData } from '@/lib/api';
import { useInvalidate } from '@/lib/queries';
import { formatDateTime, humanFileSize } from '@/lib/format';
import type { AdmissionDoc, AdmissionProfile } from './types';

export function DocumentsTab({ data }: { data: AdmissionProfile }) {
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const a = data.admission;
  const locked = !!a.student_id;
  const canEdit = data.can.edit && !locked;
  const [type, setType] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [remarks, setRemarks] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [uploading, setUploading] = useState(false);
  const [busy, setBusy] = useState<number | null>(null);
  const [rejecting, setRejecting] = useState<AdmissionDoc | null>(null);

  const req = data.required_docs;
  const verifiedReq = req.filter((r) => r.verified).length;
  const refresh = () => invalidate('adm-profile', 'adm-board', 'adm-dashboard', 'crud');

  const upload = async () => {
    const errs: Record<string, string> = {};
    if (!type) errs.doc_type = 'Select the document type.';
    if (!file) errs.file = 'Choose a file to upload.';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setUploading(true);
    try {
      const res = await api.post(`admissions/${a.id}/documents`, toFormData({ doc_type: type, file, remarks }));
      toast.success(res.message);
      setType('');
      setFile(null);
      setRemarks('');
      await refresh();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setUploading(false);
    }
  };

  const verify = async (d: AdmissionDoc, status: 'verified' | 'pending') => {
    setBusy(d.id);
    try {
      const res = await api.post(`admissions/documents/${d.id}/verify`, { status });
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const remove = async (d: AdmissionDoc) => {
    const ok = await confirm({ title: 'Delete document?', message: <>Delete <strong>{d.label}</strong> ({d.original_name})? The file will be removed permanently.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    setBusy(d.id);
    try {
      const res = await api.del(`admissions/documents/${d.id}`);
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader title="Document checklist" subtitle={`${verifiedReq} of ${req.length} required documents verified`} icon={ShieldCheck} />
        <div className="card-body">
          <ProgressBar value={req.length ? (verifiedReq / req.length) * 100 : 0} tone={verifiedReq === req.length ? 'green' : 'amber'} label="Required documents verified" />
          <ul className="mt-4 grid gap-2 sm:grid-cols-2">
            {req.map((r) => (
              <li key={r.doc_type} className="flex items-center gap-2 text-sm">
                {r.verified ? <CheckCircle2 className="h-4 w-4 text-emerald-600" aria-hidden /> : r.uploaded ? <Circle className="h-4 w-4 text-amber-500" aria-hidden /> : <XCircle className="h-4 w-4 text-slate-300 dark:text-slate-600" aria-hidden />}
                <span className="text-slate-700 dark:text-slate-200">{r.label}</span>
                <span className="text-xs text-slate-400">{r.verified ? 'Verified' : r.uploaded ? 'Awaiting verification' : 'Not uploaded'}</span>
              </li>
            ))}
          </ul>
        </div>
      </Card>

      {canEdit && (
        <Card>
          <CardHeader title="Upload a document" subtitle="PDF, Word or image files. Re-uploading a pending or rejected document replaces it." icon={Upload} />
          <div className="card-body grid gap-4 md:grid-cols-12">
            <Field className="md:col-span-4" label="Document type" required error={errors.doc_type} htmlFor="doc-type">
              <Select id="doc-type" value={type} onChange={(e) => setType(e.target.value)} options={data.doc_types} placeholder="Select type" invalid={!!errors.doc_type} />
            </Field>
            <Field className="md:col-span-8" label="File" required error={errors.file} htmlFor="doc-file">
              <FileUpload id="doc-file" compact file={file} onFile={setFile} invalid={!!errors.file} onError={(m) => toast.error(m)} accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" maxSize={5 * 1024 * 1024} />
            </Field>
            <Field className="md:col-span-9" label="Remarks" htmlFor="doc-remarks">
              <Input id="doc-remarks" value={remarks} onChange={(e) => setRemarks(e.target.value)} maxLength={255} placeholder="Optional note (e.g. original seen at desk)" />
            </Field>
            <div className="flex items-end md:col-span-3">
              <Button className="w-full" icon={Upload} loading={uploading} onClick={upload}>
                Upload
              </Button>
            </div>
          </div>
        </Card>
      )}

      <Card>
        <CardHeader title="Submitted documents" subtitle={`${data.documents.length} file${data.documents.length === 1 ? '' : 's'}`} icon={FileText} />
        {data.documents.length === 0 ? (
          <EmptyState icon={FileText} title="No documents uploaded" description="Upload the applicant’s photo, marksheets and ID proof to start document verification." />
        ) : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {data.documents.map((d) => {
              const isImg = (d.mime ?? '').startsWith('image/');
              const view = apiUrl('files/download', { path: d.file_path, name: d.original_name ?? d.label });
              return (
                <li key={d.id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                  <span className={clsx('inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', d.status === 'verified' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : d.status === 'rejected' ? 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800')}>
                    {isImg ? <FileImage className="h-5 w-5" /> : <FileText className="h-5 w-5" />}
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="text-sm font-semibold text-slate-900 dark:text-white">{d.label}</p>
                      <StatusBadge status={d.status} />
                      {!d.exists && <span className="badge badge-red">File missing</span>}
                    </div>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                      {d.original_name ?? 'document'}{d.size_bytes ? ` · ${humanFileSize(d.size_bytes)}` : ''} · uploaded {formatDateTime(d.created_at)}
                      {d.verified_at && d.status !== 'pending' ? ` · ${d.status} by ${d.verified_by_name ?? 'staff'} on ${formatDateTime(d.verified_at)}` : ''}
                    </p>
                    {d.remarks && <p className={clsx('mt-0.5 text-xs', d.status === 'rejected' ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-slate-300')}>{d.remarks}</p>}
                  </div>
                  <div className="flex flex-wrap items-center gap-1.5">
                    {d.exists && (
                      <>
                        <a href={view} target="_blank" rel="noreferrer" className="btn btn-secondary btn-xs"><Eye className="h-3.5 w-3.5" />View</a>
                        <a href={apiUrl('files/download', { path: d.file_path, name: d.original_name ?? d.label, download: 1 })} className="btn-icon !h-7 !w-7" aria-label={`Download ${d.label}`} title="Download"><Download className="h-4 w-4" /></a>
                      </>
                    )}
                    {canEdit && d.status !== 'verified' && (
                      <Button size="xs" variant="success" icon={CheckCircle2} loading={busy === d.id} onClick={() => verify(d, 'verified')}>
                        Verify
                      </Button>
                    )}
                    {canEdit && d.status !== 'rejected' && (
                      <Button size="xs" variant="secondary" icon={XCircle} disabled={busy === d.id} onClick={() => setRejecting(d)}>
                        Reject
                      </Button>
                    )}
                    {canEdit && d.status !== 'pending' && <IconButton size="sm" icon={RotateCcw} label="Reset to pending" disabled={busy === d.id} onClick={() => verify(d, 'pending')} />}
                    {canEdit && <IconButton size="sm" icon={Trash2} tone="danger" label={`Delete ${d.label}`} disabled={busy === d.id} onClick={() => remove(d)} />}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </Card>
      {locked && <Alert variant="info">Documents are locked because the applicant is enrolled. Verified documents were copied to the student’s document vault.</Alert>}
      <RejectDocModal doc={rejecting} onClose={() => setRejecting(null)} onDone={refresh} />
    </div>
  );
}

function RejectDocModal({ doc, onClose, onDone }: { doc: AdmissionDoc | null; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [remarks, setRemarks] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const submit = async () => {
    if (!doc) return;
    if (!remarks.trim()) {
      setError('Enter the reason the document was rejected.');
      return;
    }
    setSaving(true);
    try {
      const res = await api.post(`admissions/documents/${doc.id}/verify`, { status: 'rejected', remarks });
      toast.success(res.message);
      onDone();
      setRemarks('');
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.remarks ?? err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal open={!!doc} onClose={onClose} static={saving} size="sm" title={`Reject ${doc?.label ?? 'document'}`} description="The applicant will need to upload a corrected copy."
      footer={<><Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button><Button variant="danger" onClick={submit} loading={saving}>Reject document</Button></>}>
      <Field label="Reason" required error={error} htmlFor="reject-doc-remarks">
        <Textarea id="reject-doc-remarks" rows={3} value={remarks} onChange={(e) => { setRemarks(e.target.value); setError(''); }} invalid={!!error} maxLength={255} autoFocus placeholder="e.g. Scan is blurred — please upload a clear copy." />
      </Field>
    </Modal>
  );
}
