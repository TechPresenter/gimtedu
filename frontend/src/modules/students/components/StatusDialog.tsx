import { useEffect, useState } from 'react';
import { UserCheck, UserX } from 'lucide-react';
import { Alert, Button, Field, Modal, RadioGroup, Textarea, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';

interface StatusDialogProps {
  open: boolean;
  onClose: () => void;
  /** Students affected (one for the profile/row action, many for bulk). */
  students: { id: number; name: string }[];
  mode: 'deactivate' | 'activate';
  onDone?: () => void;
}

const REASONS = ['Discontinued studies', 'Fee default', 'Disciplinary action', 'Long absence', 'Transferred to another institute', 'Medical reasons'];

/** Deactivate (inactive / suspended / dropped, with a mandatory reason) or re-activate students. */
export function StatusDialog({ open, onClose, students, mode, onDone }: StatusDialogProps) {
  const toast = useToast();
  const [status, setStatus] = useState('inactive');
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (open) {
      setStatus('inactive');
      setReason('');
      setError(null);
    }
  }, [open]);

  const single = students.length === 1;
  const submit = async () => {
    if (mode === 'deactivate' && !reason.trim()) {
      setError('Enter the reason — it is shown on the student profile and in the audit log.');
      return;
    }
    setSaving(true);
    setError(null);
    try {
      let message = '';
      if (single) {
        const res = await api.post(`students/${students[0].id}/status`, { status: mode === 'activate' ? 'active' : status, reason: reason.trim() });
        message = res.message;
      } else if (mode === 'deactivate') {
        const res = await api.post('crud/students/bulk', { action: 'deactivate', ids: students.map((s) => s.id), status, reason: reason.trim() });
        message = res.message;
      } else {
        const res = await api.post('crud/students/bulk', { action: 'status', ids: students.map((s) => s.id), field: 'status', value: 'active' });
        message = res.message;
      }
      toast.success(message || 'Status updated.');
      onDone?.();
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setError(Object.values(err.errors ?? {})[0] ?? err.message);
    } finally {
      setSaving(false);
    }
  };

  const who = single ? students[0]?.name : `${students.length} students`;
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="md"
      title={mode === 'activate' ? `Re-activate ${who}?` : `Deactivate ${who}`}
      description={mode === 'activate' ? 'The student returns to class lists, attendance sheets and fee collection.' : 'Deactivated students are hidden from class lists, attendance and exam allocation. Their records are kept.'}
      icon={
        <span className={mode === 'activate' ? 'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-300'}>
          {mode === 'activate' ? <UserCheck className="h-5 w-5" /> : <UserX className="h-5 w-5" />}
        </span>
      }
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant={mode === 'activate' ? 'success' : 'danger'} loading={saving} onClick={submit}>
            {mode === 'activate' ? 'Re-activate' : 'Deactivate'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert variant="error">{error}</Alert>}
        {mode === 'deactivate' ? (
          <>
            <Field label="New status" required>
              <RadioGroup
                name="student-status"
                value={status}
                onChange={setStatus}
                options={[
                  { value: 'inactive', label: 'Inactive' },
                  { value: 'suspended', label: 'Suspended' },
                  { value: 'dropped', label: 'Dropped out' },
                ]}
              />
            </Field>
            <Field label="Reason" required htmlFor="status-reason" hint="Visible on the student profile and recorded in the activity log.">
              <Textarea id="status-reason" rows={3} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why is this student being deactivated?" invalid={!!error && !reason.trim()} />
            </Field>
            <div className="flex flex-wrap gap-1.5">
              {REASONS.map((r) => (
                <button key={r} type="button" onClick={() => setReason(r)} className="rounded-full border border-slate-200 px-2.5 py-1 text-xs text-slate-600 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-800 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-brand-500/10">
                  {r}
                </button>
              ))}
            </div>
          </>
        ) : (
          <p className="text-sm text-slate-600 dark:text-slate-300">
            {single ? `${who}'s status will be set to Active and the previous reason cleared.` : `All ${students.length} selected students will be set to Active.`}
          </p>
        )}
      </div>
    </Modal>
  );
}
