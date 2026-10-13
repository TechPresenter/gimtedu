import { useEffect, useState } from 'react';
import { MessageSquareWarning, PenLine, Send } from 'lucide-react';
import { Field, Input, Modal, Select, Spinner, Textarea, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { errorMessage, fieldErrors } from '../../lib/errors';
import { usePortalQuery } from '../../lib/hooks';
import type { CorrectionsData } from './types';
import { useFacultyInvalidate } from './ui';

/** "Request a correction" for read-only professional / personal details (sent to the HR office). */
export function CorrectionCard({ current }: { current?: Record<string, string | null | undefined> }) {
  const toast = useToast();
  const invalidate = useFacultyInvalidate();
  const { data, isLoading } = usePortalQuery<CorrectionsData>('faculty', ['corrections'], 'profile/corrections');
  const [open, setOpen] = useState(false);
  const [field, setField] = useState('');
  const [requested, setRequested] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setField('');
    setRequested('');
    setNote('');
    setErrors({});
  }, [open]);

  const submit = async () => {
    const e: Record<string, string> = {};
    if (!field) e.field = 'Choose what needs to be corrected.';
    if (!requested.trim() && field !== 'bank') e.requested = 'Enter the correct value.';
    if (Object.keys(e).length) {
      setErrors(e);
      return;
    }
    setBusy(true);
    try {
      const res = await api.post('portal/faculty/profile/corrections', { field, requested: requested.trim(), note: note.trim() });
      toast.success(res.message || 'Request sent.');
      await invalidate('corrections');
      setOpen(false);
    } catch (ex) {
      setErrors(fieldErrors(ex));
      toast.error(errorMessage(ex, 'Unable to send the request.'));
    } finally {
      setBusy(false);
    }
  };

  const cur = field && current ? current[field] : null;
  return (
    <section className="card p-4 sm:p-5">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300">
            <MessageSquareWarning className="h-5 w-5" aria-hidden />
          </span>
          <div>
            <h2 className="card-title">Something wrong in your record?</h2>
            <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Your details are maintained by the HR office. Send a correction request and they will update the ERP.</p>
          </div>
        </div>
        <button type="button" className="btn btn-navy btn-sm shrink-0" onClick={() => setOpen(true)} disabled={!data}>
          <PenLine className="h-4 w-4" aria-hidden /> Request a correction
        </button>
      </div>
      {!isLoading && data && data.items.length > 0 && (
        <div className="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
          <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Your requests</p>
          <ul className="space-y-2">
            {data.items.slice(0, 5).map((r) => (
              <li key={r.id} className="flex flex-col gap-0.5 rounded-xl bg-slate-50 px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between dark:bg-slate-800/50">
                <span className="min-w-0">
                  <span className="font-semibold text-slate-800 dark:text-slate-100">{r.label ?? r.field}</span>
                  {r.requested ? <span className="text-slate-500 dark:text-slate-400"> → {r.requested}</span> : null}
                </span>
                <span className="shrink-0 text-xs text-slate-400">Sent {formatDateTime(r.created_at)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        static={busy}
        title="Request a correction"
        description="The HR office receives your request in the ERP and updates your record."
        footer={
          <>
            <button type="button" className="btn btn-secondary" onClick={() => setOpen(false)} disabled={busy}>
              Cancel
            </button>
            <button type="button" className="btn btn-primary" onClick={() => void submit()} disabled={busy}>
              {busy ? <Spinner className="h-4 w-4" /> : <Send className="h-4 w-4" aria-hidden />} Send request
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="What needs to change?" required error={errors.field} htmlFor="corr-field">
            <Select id="corr-field" value={field} placeholder="Choose a detail…" onChange={(e) => setField(e.target.value)} invalid={!!errors.field} options={data?.fields ?? {}} />
          </Field>
          {cur ? (
            <p className="-mt-2 text-xs text-slate-500 dark:text-slate-400">
              Currently on record: <strong className="text-slate-700 dark:text-slate-200">{cur}</strong>
            </p>
          ) : null}
          <Field
            label={field === 'bank' ? 'Details (optional)' : 'Correct value'}
            required={field !== 'bank'}
            error={errors.requested}
            htmlFor="corr-value"
            hint={field === 'bank' ? 'Do not type full account or PAN numbers here — the HR office will collect the documents from you.' : undefined}
          >
            <Input id="corr-value" value={requested} maxLength={300} onChange={(e) => setRequested(e.target.value)} invalid={!!errors.requested} placeholder={field === 'bank' ? 'e.g. Account moved to another bank' : 'e.g. Ph.D. (Computer Science), IIT Delhi'} />
          </Field>
          <Field label="Note for HR" error={errors.note} htmlFor="corr-note" aside={`${note.length}/500`}>
            <Textarea id="corr-note" rows={3} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Add any context, e.g. which document supports the change." />
          </Field>
        </div>
      </Modal>
    </section>
  );
}
