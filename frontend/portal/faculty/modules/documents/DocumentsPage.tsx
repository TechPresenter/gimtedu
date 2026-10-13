import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { BadgeCheck, Download, Eye, FileImage, FileText, FileWarning, FolderLock, Hourglass, Trash2, UploadCloud, XCircle } from 'lucide-react';
import { Alert, Field, FileUpload, Input, Modal, Select, Spinner, Stagger, useConfirm, useToast } from '@/components/ui';
import { api, apiUrl, toFormData } from '@/lib/api';
import { formatDate, labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead } from '../../../components/Ui';
import { errorMessage, fieldErrors } from '../../../lib/errors';
import { usePortalQuery } from '../../../lib/hooks';
import type { DocumentRow, DocumentsData } from '../../shared/types';
import { MiniStat, PillTabs, fpath, useFacultyInvalidate } from '../../shared/ui';

type Filter = 'all' | 'verified' | 'pending' | 'rejected';
const STATUS_BADGE: Record<DocumentRow['status'], string> = { verified: 'badge-green', pending: 'badge-amber', rejected: 'badge-red' };

function DocIcon({ mime }: { mime: string | null }) {
  const img = mime?.startsWith('image/');
  const pdf = mime === 'application/pdf';
  const Icon = img ? FileImage : FileText;
  return (
    <span className={clsx('inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl', pdf ? 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300' : img ? 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300' : 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200')}>
      <Icon className="h-5 w-5" aria-hidden />
    </span>
  );
}

function UploadModal({ open, onClose, data }: { open: boolean; onClose: () => void; data: DocumentsData }) {
  const toast = useToast();
  const invalidate = useFacultyInvalidate();
  const [type, setType] = useState('');
  const [title, setTitle] = useState('');
  const [expiry, setExpiry] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  useEffect(() => {
    if (!open) return;
    setType('');
    setTitle('');
    setExpiry('');
    setFile(null);
    setErrors({});
  }, [open]);

  const submit = async () => {
    const e: Record<string, string> = {};
    if (!type) e.doc_type = 'Choose the document type.';
    if (!title.trim()) e.title = 'Give the document a title.';
    if (!file) e.file = 'Choose the file to upload.';
    if (Object.keys(e).length) {
      setErrors(e);
      return;
    }
    setBusy(true);
    setErrors({});
    try {
      const res = await api.post('portal/faculty/documents', toFormData({ doc_type: type, title: title.trim(), expiry_date: expiry || undefined, file }));
      toast.success(res.message || 'Document uploaded.');
      await invalidate('documents');
      onClose();
    } catch (ex) {
      setErrors(fieldErrors(ex));
      toast.error(errorMessage(ex, 'Unable to upload the document.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={busy}
      size="md"
      title="Upload a document"
      description="It is added to your personnel file and sent to the HR office for verification."
      icon={
        <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300">
          <UploadCloud className="h-5 w-5" aria-hidden />
        </span>
      }
      footer={
        <>
          <button type="button" className="btn btn-secondary" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button type="button" className="btn btn-primary" onClick={() => void submit()} disabled={busy}>
            {busy ? <Spinner className="h-4 w-4" /> : <UploadCloud className="h-4 w-4" aria-hidden />} Upload
          </button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Document type" required error={errors.doc_type} htmlFor="doc-type">
          <Select
            id="doc-type"
            value={type}
            placeholder="Choose…"
            invalid={!!errors.doc_type}
            onChange={(e) => {
              setType(e.target.value);
              if (!title.trim() && e.target.value) setTitle(data.types[e.target.value] ?? '');
            }}
            options={data.types}
          />
        </Field>
        <Field label="Valid till" hint="Optional (contracts, ID proofs)." error={errors.expiry_date} htmlFor="doc-expiry">
          <input id="doc-expiry" type="date" className="form-input" value={expiry} onChange={(e) => setExpiry(e.target.value)} />
        </Field>
        <Field label="Title" required error={errors.title} htmlFor="doc-title" className="sm:col-span-2">
          <Input id="doc-title" value={title} maxLength={190} onChange={(e) => setTitle(e.target.value)} invalid={!!errors.title} placeholder="e.g. Ph.D. Degree Certificate" />
        </Field>
        <Field label="File" required error={errors.file} className="sm:col-span-2" hint={`PDF, Word, Excel or image · up to ${Math.round(data.max_bytes / 1048576)} MB. Stored privately.`}>
          <FileUpload file={file} onFile={setFile} accept={data.accept} maxSize={data.max_bytes} invalid={!!errors.file} onError={(m) => setErrors((x) => ({ ...x, file: m }))} privateFile />
        </Field>
      </div>
    </Modal>
  );
}

export default function DocumentsPage() {
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useFacultyInvalidate();
  const { data, isLoading, error, refetch } = usePortalQuery<DocumentsData>('faculty', ['documents'], 'documents');
  const [filter, setFilter] = useState<Filter>('all');
  const [open, setOpen] = useState(false);
  const [removing, setRemoving] = useState<number | null>(null);
  const items = useMemo(() => (data ? data.items.filter((d) => filter === 'all' || d.status === filter) : []), [data, filter]);

  const remove = async (d: DocumentRow) => {
    if (!(await confirm({ title: 'Remove this document?', message: `"${d.title}" has not been verified yet. It will be removed from your personnel file.`, confirmText: 'Remove', danger: true }))) return;
    setRemoving(d.id);
    try {
      const res = await api.post(`portal/faculty/documents/${d.id}/delete`);
      toast.success(res.message || 'Document removed.');
      await invalidate('documents');
    } catch (e) {
      toast.error(errorMessage(e, 'Unable to remove the document.'));
    } finally {
      setRemoving(null);
    }
  };

  const fileUrl = (d: DocumentRow, download = false) => apiUrl(`portal/faculty/documents/${d.id}/download`, download ? { download: 1 } : undefined);

  return (
    <div>
      <PageHead
        area="faculty"
        title="My Documents"
        subtitle="Your personnel file — certificates, letters and ID proofs kept by the HR office."
        crumbs={[{ label: 'Dashboard', to: fpath() }, { label: 'My Documents' }]}
        actions={
          <button type="button" className="btn btn-primary" onClick={() => setOpen(true)} disabled={!data}>
            <UploadCloud className="h-4 w-4" aria-hidden /> Upload document
          </button>
        }
      />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="skeleton h-[74px] rounded-2xl" />
            ))}
          </div>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="skeleton h-32 rounded-2xl" />
            ))}
          </div>
        </div>
      ) : (
        <div className="space-y-5">
          <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" itemClassName="h-full">
            {[
              <MiniStat key="t" label="Documents" value={data.counts.total} icon={FolderLock} tone="navy" />,
              <MiniStat key="v" label="Verified" value={data.counts.verified} icon={BadgeCheck} tone="green" />,
              <MiniStat key="p" label="Awaiting verification" value={data.counts.pending} icon={Hourglass} tone={data.counts.pending ? 'amber' : 'green'} />,
              <MiniStat key="r" label="Rejected" value={data.counts.rejected} icon={FileWarning} tone={data.counts.rejected ? 'red' : 'green'} sub={data.counts.rejected ? 're-upload a corrected copy' : undefined} />,
            ]}
          </Stagger>

          <Alert variant="info" title="Private file">
            Only you and authorised HR staff can open these documents. Files you upload stay “awaiting verification” until the HR office checks them.
          </Alert>

          <PillTabs
            label="Filter documents"
            value={filter}
            onChange={setFilter}
            tabs={[
              { value: 'all', label: 'All', count: data.counts.total },
              { value: 'verified', label: 'Verified', count: data.counts.verified },
              { value: 'pending', label: 'Awaiting', count: data.counts.pending },
              { value: 'rejected', label: 'Rejected', count: data.counts.rejected },
            ]}
          />

          {items.length === 0 ? (
            <div className="card">
              <EmptyBlock
                icon={FolderLock}
                title={filter === 'all' ? 'No documents on file yet' : `No ${filter === 'pending' ? 'documents awaiting verification' : `${filter} documents`}`}
                text={filter === 'all' ? 'Upload your resume, degree certificates, experience letters and ID proofs for the HR office.' : undefined}
                action={
                  filter === 'all' ? (
                    <button type="button" className="btn btn-primary btn-sm" onClick={() => setOpen(true)}>
                      <UploadCloud className="h-4 w-4" aria-hidden /> Upload document
                    </button>
                  ) : undefined
                }
              />
            </div>
          ) : (
            <Stagger className="grid grid-cols-1 gap-3 md:grid-cols-2" itemClassName="h-full">
              {items.map((d) => (
                <article key={d.id} className="card flex h-full flex-col p-4">
                  <div className="flex items-start gap-3">
                    <DocIcon mime={d.mime} />
                    <div className="min-w-0 flex-1">
                      <div className="flex items-start justify-between gap-2">
                        <h2 className="min-w-0 truncate text-sm font-bold text-slate-900 dark:text-white" title={d.title}>
                          {d.title}
                        </h2>
                        <span className={clsx('badge shrink-0', STATUS_BADGE[d.status])}>{d.status === 'pending' ? 'Awaiting' : labelize(d.status)}</span>
                      </div>
                      {d.doc_type_label !== d.title && <p className="text-xs text-slate-500 dark:text-slate-400">{d.doc_type_label}</p>}
                      <p className="mt-1 truncate text-[11.5px] text-slate-400" title={d.original_name ?? undefined}>
                        {d.original_name}
                        {d.size_label ? ` · ${d.size_label}` : ''} · uploaded {formatDate(d.uploaded_at)}
                        {d.uploaded_by && !d.mine ? ` by ${d.uploaded_by}` : d.mine ? ' by you' : ''}
                      </p>
                      {d.status === 'verified' && d.verified_by && (
                        <p className="mt-0.5 text-[11.5px] text-emerald-700 dark:text-emerald-300">
                          Verified by {d.verified_by}
                          {d.verified_at ? ` on ${formatDate(d.verified_at)}` : ''}
                        </p>
                      )}
                      {d.expiry_date && (
                        <p className={clsx('mt-0.5 text-[11.5px]', d.expired ? 'font-semibold text-red-600 dark:text-red-400' : 'text-slate-500')}>
                          {d.expired ? 'Expired on' : 'Valid till'} {formatDate(d.expiry_date)}
                        </p>
                      )}
                      {d.remarks && (
                        <p className={clsx('mt-1.5 rounded-lg px-2.5 py-1.5 text-xs', d.status === 'rejected' ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-slate-50 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300')}>
                          {d.status === 'rejected' && <XCircle className="mr-1 inline h-3.5 w-3.5 align-[-2px]" aria-hidden />}
                          {d.remarks}
                        </p>
                      )}
                    </div>
                  </div>
                  <div className="mt-auto flex flex-wrap gap-2 pt-3">
                    <a href={fileUrl(d)} target="_blank" rel="noopener" className="btn btn-secondary btn-sm">
                      <Eye className="h-4 w-4" aria-hidden /> Open
                    </a>
                    <a href={fileUrl(d, true)} className="btn btn-secondary btn-sm">
                      <Download className="h-4 w-4" aria-hidden /> Download
                    </a>
                    {d.can_delete && (
                      <button type="button" className="btn btn-ghost btn-sm ml-auto !text-red-600 dark:!text-red-400" onClick={() => void remove(d)} disabled={removing === d.id}>
                        {removing === d.id ? <Spinner className="h-4 w-4" /> : <Trash2 className="h-4 w-4" aria-hidden />} Remove
                      </button>
                    )}
                  </div>
                </article>
              ))}
            </Stagger>
          )}
        </div>
      )}
      {data && <UploadModal open={open} onClose={() => setOpen(false)} data={data} />}
    </div>
  );
}
