import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { Trash2 } from 'lucide-react';
import { Alert, Button, Field, Input, Modal, Spinner, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import { useApi } from '@/lib/queries';
import { formatDate, formatNumber } from '@/lib/format';

const presets = [30, 90, 180, 365];

/** Delete audit entries older than N days (activity_logs delete permission). Shows a live preview count. */
export function PurgeLogsModal({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [days, setDays] = useState('180');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const d = useDebounce(days, 300);
  const valid = /^\d+$/.test(d) && Number(d) >= 7 && Number(d) <= 3650;
  const { data: preview, isFetching } = useApi<{ count: number; cutoff: string }>(['purge-preview', d], 'activity-logs/purge-preview', { days: d }, { enabled: open && valid });

  useEffect(() => {
    if (open) {
      setDays('180');
      setError('');
    }
  }, [open]);

  const submit = async () => {
    if (!valid) return setError('Enter a number of days between 7 and 3650.');
    setSaving(true);
    try {
      const res = await api.post('activity-logs/purge', { days: Number(days) });
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      const ex = e as ApiError;
      setError(ex.errors?.days ?? ex.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="sm"
      title="Purge old activity logs"
      description="Permanently delete audit entries older than the selected age."
      icon={<span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/15"><Trash2 className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="danger" icon={Trash2} onClick={submit} loading={saving} disabled={!valid || !preview?.count}>
            {preview?.count ? `Delete ${formatNumber(preview.count)} entries` : 'Nothing to delete'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="flex flex-wrap gap-2">
          {presets.map((p) => (
            <button
              key={p}
              type="button"
              onClick={() => { setDays(String(p)); setError(''); }}
              className={clsx('rounded-lg border px-3 py-1.5 text-xs font-semibold transition', String(p) === days ? 'border-brand-700 bg-brand-800 text-white' : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-700 dark:text-slate-300')}
            >
              {p >= 365 ? '1 year' : `${p} days`}
            </button>
          ))}
        </div>
        <Field label="Delete entries older than" htmlFor="purge-days" error={error} hint="Minimum 7 days. The purge itself is recorded in the activity log.">
          <Input id="purge-days" type="number" min={7} max={3650} value={days} onChange={(e) => { setDays(e.target.value); setError(''); }} suffix="days" invalid={!!error} />
        </Field>
        <Alert variant={preview?.count ? 'warning' : 'info'}>
          {!valid ? (
            'Enter a value between 7 and 3650 days.'
          ) : isFetching && !preview ? (
            <span className="inline-flex items-center gap-2"><Spinner className="h-3.5 w-3.5" /> Counting entries…</span>
          ) : preview ? (
            preview.count ? (
              <><strong>{formatNumber(preview.count)}</strong> entries recorded before <strong>{formatDate(preview.cutoff)}</strong> will be deleted. This cannot be undone — export them first if you need an archive.</>
            ) : (
              <>There are no entries older than {formatDate(preview.cutoff)}.</>
            )
          ) : null}
        </Alert>
      </div>
    </Modal>
  );
}
