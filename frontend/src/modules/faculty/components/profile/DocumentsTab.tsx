import { useState } from 'react';
import { BadgeCheck, Download, FileImage, FileText, RotateCcw, ShieldX } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Button, Field, Modal, Reveal, Textarea, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, apiUrl, type ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import type { Row } from '@/lib/types';
import type { EmployeeType } from '../../hr';

const downloadUrl = (r: Row, download = false) => apiUrl('files/download', { path: String(r.file_path), name: String(r.original_name ?? 'document'), download: download ? 1 : undefined });

/** Personnel-file documents with private download and verification. */
export default function DocumentsTab({ type, id, canEdit, onChanged }: { type: EmployeeType; id: number; canEdit: boolean; onChanged?: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [reject, setReject] = useState<Row | null>(null);
  const [remarks, setRemarks] = useState('');
  const [err, setErr] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const verify = async (r: Row, status: 'verified' | 'rejected' | 'pending', note?: string) => {
    setSaving(true);
    try {
      const res = await api.post(`faculty/documents/${r.id}/verify`, { status, remarks: note });
      toast.success(res.message);
      await qc.invalidateQueries({ queryKey: ['crud', 'employee_documents'] });
      onChanged?.();
      setReject(null);
    } catch (e) {
      const ae = e as ApiError;
      if (status === 'rejected') setErr(ae.errors?.remarks ?? ae.message);
      else toast.error(ae.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Reveal>
      <CrudTable
        module="employee_documents"
        title="Documents"
        description="Personnel file — stored privately, downloadable only by authorised staff."
        scope={{ employee_type: type, employee_id: id }}
        hideColumns={['employee_name']}
        addLabel="Upload document"
        emptyTitle="No documents uploaded"
        emptyText="Upload the appointment letter, ID proof, degree certificates and experience letters."
        onSaved={() => onChanged?.()}
        renderers={{
          title: (r) => {
            const Icon = String(r.mime ?? '').startsWith('image/') ? FileImage : FileText;
            return (
              <div className="flex min-w-0 items-center gap-3">
                <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">
                  <Icon className="h-4 w-4" />
                </span>
                <div className="min-w-0 leading-tight">
                  <a href={downloadUrl(r)} target="_blank" rel="noreferrer" className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                    {r.title}
                  </a>
                  <p className="truncate text-xs text-slate-500">{r.doc_type_label}{r.expiry_date ? ` · valid till ${formatDate(r.expiry_date)}` : ''}</p>
                </div>
              </div>
            );
          },
          original_name: (r) => (
            <a href={downloadUrl(r, true)} className="inline-flex max-w-[11rem] items-center gap-1.5 truncate text-sm text-slate-600 hover:text-brand-700 dark:text-slate-300 dark:hover:text-white" title={`Download ${r.original_name}`}>
              <Download className="h-3.5 w-3.5 shrink-0" /> <span className="truncate">{r.original_name}</span>
            </a>
          ),
        }}
        rowMenu={(r) => [
          { label: 'Download', icon: Download, href: downloadUrl(r, true) },
          canEdit && r.status !== 'verified' && { label: 'Mark verified', icon: BadgeCheck, onClick: () => verify(r, 'verified') },
          canEdit && r.status !== 'rejected' && { label: 'Reject…', icon: ShieldX, danger: true, onClick: () => { setRemarks(''); setErr(null); setReject(r); } },
          canEdit && r.status !== 'pending' && { label: 'Reset to pending', icon: RotateCcw, onClick: () => verify(r, 'pending') },
        ]}
      />
      <Modal
        open={!!reject}
        onClose={() => setReject(null)}
        static={saving}
        size="md"
        title="Reject document"
        description={reject ? `"${reject.title}" — the employee is asked to upload a corrected copy.` : undefined}
        footer={
          <>
            <Button variant="secondary" onClick={() => setReject(null)} disabled={saving}>Cancel</Button>
            <Button variant="danger" icon={ShieldX} loading={saving} onClick={() => (remarks.trim() ? reject && verify(reject, 'rejected', remarks.trim()) : setErr('Tell the employee why the document was rejected.'))}>
              Reject document
            </Button>
          </>
        }
      >
        <Field label="Reason" required htmlFor="doc-remarks" error={err}>
          <Textarea id="doc-remarks" rows={3} maxLength={255} value={remarks} onChange={(e) => setRemarks(e.target.value)} invalid={!!err} placeholder="e.g. Scan is unclear — upload a clearer copy" autoFocus />
        </Field>
      </Modal>
    </Reveal>
  );
}
