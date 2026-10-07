import { useMemo, useState } from 'react';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import {
  CheckCircle2, CircleDashed, Download, ExternalLink, FileImage, FileText, FileWarning, MoreHorizontal, Pencil, ShieldCheck, Trash2, Upload, XCircle,
} from 'lucide-react';
import { Alert, Button, Card, CardHeader, Dropdown, EmptyState, Field, Modal, Reveal, StatusBadge, Textarea, useConfirm, useToast } from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { api, apiUrl, ApiError } from '@/lib/api';
import { useCrudList, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, humanFileSize } from '@/lib/format';
import type { Row } from '@/lib/types';
import { docTypeLabel, REQUIRED_DOCS } from '../constants';
import { TabError, TabLoading, type TabProps } from './shared';

export default function DocumentsTab({ profile, studentId, refresh }: TabProps) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { data: meta } = useCrudMeta('student_documents');
  const list = useCrudList('student_documents', { scope: { student_id: studentId }, per_page: 100, sort: 'created_at', dir: 'desc' });
  const [upload, setUpload] = useState<{ open: boolean; id: number | null; docType?: string }>({ open: false, id: null });
  const [reject, setReject] = useState<Row | null>(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState<number | 'all' | null>(null);
  const [rejectError, setRejectError] = useState<string | null>(null);
  const canEdit = can('students', 'edit');
  const canCreate = can('students', 'create');
  const docs = useMemo(() => list.data?.rows ?? [], [list.data]);
  const isPG = profile.student.program_level === 'PG';
  const required = isPG ? [...REQUIRED_DOCS.slice(0, 2), 'graduation', ...REQUIRED_DOCS.slice(2)] : REQUIRED_DOCS;
  const defaults = useMemo(() => (upload.docType ? { doc_type: upload.docType } : undefined), [upload.docType]);

  const reload = async () => {
    await qc.invalidateQueries({ queryKey: ['crud', 'student_documents'] });
    refresh();
  };
  const verify = async (d: Row) => {
    setBusy(Number(d.id));
    try {
      const res = await api.post(`students/documents/${d.id}/verify`);
      toast.success(res.message);
      await reload();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const verifyAll = async () => {
    const pending = docs.filter((d) => d.status === 'pending');
    if (!(await confirm({ title: `Verify ${pending.length} documents?`, message: 'Mark every pending document of this student as verified. Make sure you have checked the originals.', confirmText: 'Verify all' }))) return;
    setBusy('all');
    try {
      for (const d of pending) await api.post(`students/documents/${d.id}/verify`);
      toast.success(`${pending.length} document${pending.length === 1 ? '' : 's'} verified.`);
      await reload();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const submitReject = async () => {
    if (!reject) return;
    if (!reason.trim()) return setRejectError('Enter the reason so the student knows what to correct.');
    setBusy(Number(reject.id));
    try {
      const res = await api.post(`students/documents/${reject.id}/reject`, { reason: reason.trim() });
      toast.success(res.message);
      setReject(null);
      await reload();
    } catch (e) {
      const ex = e as ApiError;
      setRejectError(ex.errors?.reason ?? ex.message);
    } finally {
      setBusy(null);
    }
  };
  const remove = async (d: Row) => {
    if (!(await confirm({ title: 'Delete document?', message: <>Delete <strong>{String(d.title)}</strong>? The file is removed permanently.</>, confirmText: 'Delete', danger: true }))) return;
    try {
      const res = await api.del(`crud/student_documents/${d.id}`);
      toast.success(res.message || 'Document deleted.');
      await reload();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const fileUrl = (d: Row, download = false) => apiUrl('files/download', { path: String(d.file_path), name: String(d.original_name ?? d.title), download: download ? 1 : undefined });

  if (list.isLoading) return <TabLoading />;
  if (list.error) return <TabError error={list.error} onRetry={() => list.refetch()} />;
  const counts = { verified: docs.filter((d) => d.status === 'verified').length, pending: docs.filter((d) => d.status === 'pending').length, rejected: docs.filter((d) => d.status === 'rejected').length };

  return (
    <div className="space-y-5">
      <div className="grid gap-5 lg:grid-cols-3 [&>*]:min-w-0">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="Document checklist" subtitle={`${required.filter((t) => docs.some((d) => d.doc_type === t && d.status === 'verified')).length} of ${required.length} required verified`} icon={ShieldCheck} />
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {required.map((t) => {
                const d = docs.find((x) => x.doc_type === t && x.status === 'verified') ?? docs.find((x) => x.doc_type === t);
                const st = d ? String(d.status) : 'missing';
                return (
                  <li key={t} className="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                    <span className="flex min-w-0 items-center gap-2.5">
                      {st === 'verified' ? <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" /> : st === 'rejected' ? <XCircle className="h-4 w-4 shrink-0 text-red-500" /> : st === 'pending' ? <CircleDashed className="h-4 w-4 shrink-0 text-amber-500" /> : <FileWarning className="h-4 w-4 shrink-0 text-slate-400" />}
                      <span className="truncate text-slate-700 dark:text-slate-200">{docTypeLabel(t)}</span>
                    </span>
                    {st === 'missing' ? (
                      canCreate ? <button type="button" className="link shrink-0 text-xs" onClick={() => setUpload({ open: true, id: null, docType: t })}>Upload</button> : <span className="text-xs text-slate-400">Missing</span>
                    ) : (
                      <StatusBadge status={st} />
                    )}
                  </li>
                );
              })}
            </ul>
          </Card>
        </Reveal>

        <Reveal className="lg:col-span-2" delay={60}>
          <Card className="h-full">
            <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-start sm:justify-between dark:border-slate-800">
              <div className="flex min-w-0 items-start gap-3">
                <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">
                  <FileText className="h-4 w-4" />
                </span>
                <div className="min-w-0">
                  <h2 className="card-title">Uploaded documents</h2>
                  <p className="card-subtitle">{`${docs.length} file${docs.length === 1 ? '' : 's'} · ${counts.verified} verified · ${counts.pending} pending · ${counts.rejected} rejected`}</p>
                </div>
              </div>
              <div className="flex shrink-0 flex-wrap gap-2">
                {canEdit && counts.pending > 0 && <Button size="sm" variant="secondary" icon={ShieldCheck} loading={busy === 'all'} onClick={verifyAll}>Verify all</Button>}
                {canCreate && <Button size="sm" icon={Upload} onClick={() => setUpload({ open: true, id: null })}>Upload</Button>}
              </div>
            </div>
            {docs.length === 0 ? (
              <EmptyState icon={FileText} title="No documents uploaded" description="Upload marksheets, ID proof and certificates. Files are stored privately and only staff with access can open them."
                action={canCreate ? <Button icon={Upload} onClick={() => setUpload({ open: true, id: null })}>Upload document</Button> : undefined} />
            ) : (
              <ul className="grid gap-3 p-4 sm:grid-cols-2 [&>*]:min-w-0">
                {docs.map((d) => {
                  const isImg = String(d.mime ?? '').startsWith('image/');
                  return (
                    <li key={d.id} className={clsx('group relative rounded-2xl border p-3.5 transition duration-200 hover:-translate-y-0.5 hover:shadow-card',
                      d.status === 'rejected' ? 'border-red-200 bg-red-50/40 dark:border-red-500/30 dark:bg-red-500/5' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900')}>
                      <div className="flex items-start gap-3">
                        <a href={fileUrl(d)} target="_blank" rel="noopener noreferrer" className={clsx('inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl transition group-hover:scale-105', isImg ? 'bg-cyan-50 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300' : 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300')} aria-label={`Open ${d.title}`}>
                          {isImg ? <FileImage className="h-5 w-5" /> : <FileText className="h-5 w-5" />}
                        </a>
                        <div className="min-w-0 flex-1">
                          <a href={fileUrl(d)} target="_blank" rel="noopener noreferrer" className="block truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{String(d.title)}</a>
                          <p className="truncate text-xs text-slate-500">
                            {docTypeLabel(String(d.doc_type)) !== d.title && `${docTypeLabel(String(d.doc_type))} · `}
                            {d.size_bytes ? humanFileSize(Number(d.size_bytes)) : '—'} · {formatDate(d.created_at)}
                          </p>
                          <div className="mt-2 flex flex-wrap items-center gap-1.5">
                            <StatusBadge status={String(d.status)} />
                            {d.verified_by_name && d.status !== 'pending' && <span className="text-[11px] text-slate-500" title={formatDateTime(d.verified_at)}>by {String(d.verified_by_name)}</span>}
                          </div>
                        </div>
                        <Dropdown
                          label="Document actions"
                          triggerClassName="btn-icon !h-8 !w-8 !rounded-lg shrink-0"
                          trigger={<MoreHorizontal className="h-4 w-4" />}
                          items={[
                            { label: 'Open', icon: ExternalLink, href: fileUrl(d), target: '_blank' },
                            { label: 'Download', icon: Download, href: fileUrl(d, true) },
                            canEdit && d.status !== 'verified' && { label: 'Verify', icon: CheckCircle2, onClick: () => verify(d), disabled: busy === Number(d.id) },
                            canEdit && d.status !== 'rejected' && { label: 'Reject…', icon: XCircle, onClick: () => { setReject(d); setReason(''); setRejectError(null); } },
                            canEdit && { label: 'Edit / replace file', icon: Pencil, onClick: () => setUpload({ open: true, id: Number(d.id) }) },
                            can('students', 'delete') && { label: '', divider: true },
                            can('students', 'delete') && { label: 'Delete', icon: Trash2, danger: true, onClick: () => remove(d) },
                          ]}
                        />
                      </div>
                      {d.status === 'rejected' && d.remarks && <p className="mt-2.5 rounded-lg bg-red-100/70 px-2.5 py-1.5 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">{String(d.remarks)}</p>}
                      {canEdit && d.status === 'pending' && (
                        <div className="mt-3 flex gap-2">
                          <Button size="xs" variant="success" icon={CheckCircle2} loading={busy === Number(d.id)} onClick={() => verify(d)}>Verify</Button>
                          <Button size="xs" variant="secondary" icon={XCircle} onClick={() => { setReject(d); setReason(''); setRejectError(null); }}>Reject</Button>
                        </div>
                      )}
                    </li>
                  );
                })}
              </ul>
            )}
          </Card>
        </Reveal>
      </div>

      <CrudFormModal
        open={upload.open}
        onClose={() => setUpload({ open: false, id: null })}
        meta={meta}
        module="student_documents"
        id={upload.id}
        scope={{ student_id: studentId }}
        defaults={defaults}
        title={upload.id ? 'Edit document' : 'Upload document'}
        size="md"
        intro={!upload.id ? <Alert variant="info">Files are stored privately (not on the public website). New uploads start as <strong>pending</strong> until verified.</Alert> : undefined}
        onSaved={() => void reload()}
      />

      <Modal open={!!reject} onClose={() => setReject(null)} size="md" title="Reject document" description={reject ? String(reject.title) : undefined} static={busy !== null}
        footer={<><Button variant="secondary" onClick={() => setReject(null)}>Cancel</Button><Button variant="danger" loading={busy !== null} onClick={submitReject}>Reject document</Button></>}>
        <Field label="Reason" required error={rejectError} htmlFor="reject-reason" hint="Shown to staff on the profile; the student should upload a corrected copy.">
          <Textarea id="reject-reason" rows={3} maxLength={255} value={reason} onChange={(e) => { setReason(e.target.value); setRejectError(null); }} invalid={!!rejectError} placeholder="e.g. Scanned copy is not legible" />
        </Field>
        <div className="mt-3 flex flex-wrap gap-1.5">
          {['Scanned copy is not legible', 'Name does not match records', 'Page missing', 'Document expired', 'Wrong document type'].map((r) => (
            <button key={r} type="button" onClick={() => { setReason(r); setRejectError(null); }} className="rounded-full border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:border-red-300 hover:bg-red-50 hover:text-red-700 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-red-500/10">
              {r}
            </button>
          ))}
        </div>
      </Modal>
    </div>
  );
}
