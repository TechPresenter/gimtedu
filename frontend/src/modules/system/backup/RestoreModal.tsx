import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { ArchiveRestore, CircleCheck, Database, FolderArchive, Search, ShieldAlert } from 'lucide-react';
import { Alert, Button, Checkbox, Modal, Skeleton, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { formatDateTime, formatNumber, humanFileSize } from '@/lib/format';
import { TypeToConfirm } from '../components';
import '../system.css';
import type { BackupRow } from '../types';

interface Detail extends BackupRow {
  tables: { name: string; rows: number; restorable: boolean }[];
}
interface Result {
  type: 'database' | 'files';
  tables?: number;
  rows?: number;
  files?: number;
  skipped?: number;
  duration_ms: number;
  safety_backup: { id: number; filename: string } | null;
}

/** Danger dialog: choose scope, keep a safety snapshot, type RESTORE, run the restore and show the outcome. */
export function RestoreModal({ backup, onClose, onDone }: { backup: BackupRow | null; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const { data: detail, isLoading } = useApi<Detail>(['backup-detail', backup?.id], `backup/${backup?.id}`, undefined, { enabled: !!backup });
  const [scope, setScope] = useState<'all' | 'selected'>('all');
  const [tables, setTables] = useState<Set<string>>(new Set());
  const [filter, setFilter] = useState('');
  const [safety, setSafety] = useState(true);
  const [confirmText, setConfirmText] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [confirmError, setConfirmError] = useState('');
  const [running, setRunning] = useState(false);
  const [result, setResult] = useState<(Result & { message: string }) | null>(null);

  useEffect(() => {
    if (backup) {
      setScope('all');
      setTables(new Set());
      setFilter('');
      setSafety(true);
      setConfirmText('');
      setError(null);
      setConfirmError('');
      setResult(null);
    }
  }, [backup]);

  const restorable = useMemo(() => (detail?.tables ?? []).filter((t) => t.restorable), [detail]);
  const shown = restorable.filter((t) => !filter || t.name.includes(filter.toLowerCase()));
  const isFiles = backup?.type === 'files';
  const ready = confirmText === 'RESTORE' && (isFiles || scope === 'all' || tables.size > 0);

  const run = async () => {
    if (!backup) return;
    if (confirmText !== 'RESTORE') {
      setConfirmError('Type RESTORE in capitals to confirm.');
      return;
    }
    setRunning(true);
    setError(null);
    try {
      const res = await api.post<Result>(`backup/${backup.id}/restore`, { confirm: confirmText, safety_backup: safety, tables: !isFiles && scope === 'selected' ? [...tables] : undefined });
      setResult({ ...res.data, message: res.message });
      toast.success(res.message);
      onDone();
    } catch (e) {
      const ex = e as ApiError;
      setError(ex.message);
      setConfirmError(ex.errors?.confirm ?? '');
    } finally {
      setRunning(false);
    }
  };

  return (
    <Modal
      open={!!backup}
      onClose={onClose}
      static={running}
      size="lg"
      title={isFiles ? 'Restore uploaded files' : 'Restore database'}
      description={backup ? `${backup.filename} · created ${formatDateTime(backup.created_at)}` : undefined}
      icon={<span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/15"><ArchiveRestore className="h-5 w-5" /></span>}
      footer={
        result ? (
          <Button onClick={onClose}>Done</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose} disabled={running}>
              Cancel
            </Button>
            <Button variant="danger" icon={ArchiveRestore} onClick={run} loading={running} disabled={!ready}>
              {running ? 'Restoring…' : isFiles ? 'Restore files' : 'Restore database'}
            </Button>
          </>
        )
      }
    >
      {result ? (
        <div className="space-y-4 text-center motion-safe:animate-slide-up">
          <span className="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15">
            <CircleCheck className="h-8 w-8" />
          </span>
          <p className="text-base font-semibold text-slate-900 dark:text-white">{result.message}</p>
          <div className="mx-auto grid max-w-md grid-cols-3 gap-3 text-left">
            {(result.type === 'files'
              ? [['Files', formatNumber(result.files)], ['Skipped', formatNumber(result.skipped)], ['Time', `${(result.duration_ms / 1000).toFixed(1)} s`]]
              : [['Tables', formatNumber(result.tables)], ['Rows', formatNumber(result.rows)], ['Time', `${(result.duration_ms / 1000).toFixed(1)} s`]]
            ).map(([l, v]) => (
              <div key={l} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                <p className="text-[11px] text-slate-500">{l}</p>
                <p className="font-display text-lg font-bold text-slate-900 dark:text-white">{v}</p>
              </div>
            ))}
          </div>
          {result.safety_backup && <p className="text-xs text-slate-500">A safety snapshot was saved first as <code className="font-mono">{result.safety_backup.filename}</code>.</p>}
        </div>
      ) : backup ? (
        <div className={clsx('space-y-5', running && 'pointer-events-none')}>
          {running && (
            <div className="overflow-hidden rounded-xl border border-brand-200 bg-brand-50/60 p-4 dark:border-brand-500/30 dark:bg-brand-500/10" role="status">
              <p className="text-sm font-semibold text-brand-900 dark:text-white">{safety && !isFiles ? 'Taking a safety snapshot, then restoring…' : 'Restoring…'}</p>
              <p className="text-xs text-slate-600 dark:text-slate-300">Please keep this window open. All changes happen in one transaction.</p>
              <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-brand-100 dark:bg-brand-500/20">
                <div className="h-full w-1/3 sys-indeterminate rounded-full bg-brand-700 dark:bg-brand-300" />
              </div>
            </div>
          )}
          {error && <Alert variant="error" title="Restore failed — nothing was changed">{error}</Alert>}
          <Alert variant="warning" title="This replaces live data">
            {isFiles
              ? 'Files from this backup are written back into assets/uploads, overwriting files with the same name. Existing files that are not in the backup are kept.'
              : 'Every restored table is emptied and refilled with the data from this backup. Changes made after the backup (fees, attendance, users, settings…) will be lost. The backup catalogue itself is never overwritten.'}
          </Alert>
          <div className="grid grid-cols-3 gap-3">
            {[
              ['Type', isFiles ? 'Uploaded files' : 'Database', isFiles ? FolderArchive : Database],
              [isFiles ? 'Files' : 'Tables', isFiles ? formatNumber(backup.meta?.files) : formatNumber(backup.meta?.tables), null],
              ['Size', humanFileSize(backup.size_bytes), null],
            ].map(([l, v, I]) => (
              <div key={String(l)} className="rounded-xl border border-slate-200 px-3 py-2 dark:border-slate-700">
                <p className="text-[11px] text-slate-500">{String(l)}</p>
                <p className="flex items-center gap-1.5 text-sm font-semibold text-slate-900 dark:text-white">
                  {I ? (() => { const Ic = I as typeof Database; return <Ic className="h-3.5 w-3.5 text-slate-400" />; })() : null}
                  {String(v)}
                </p>
              </div>
            ))}
          </div>

          {!isFiles && (
            <div className="space-y-3">
              <p className="form-label !mb-0">What to restore</p>
              <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Restore scope">
                {[
                  { k: 'all' as const, t: 'Entire database', d: `All ${restorable.length || backup.meta?.tables || ''} tables (recommended)` },
                  { k: 'selected' as const, t: 'Selected tables only', d: 'Advanced — e.g. roll back just settings' },
                ].map((o) => (
                  <button key={o.k} type="button" role="radio" aria-checked={scope === o.k} onClick={() => setScope(o.k)}
                    className={clsx('rounded-xl border p-3 text-left transition', scope === o.k ? 'border-red-400 bg-red-50/50 ring-2 ring-red-500/10 dark:bg-red-500/10' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700')}>
                    <span className="block text-sm font-semibold text-slate-900 dark:text-white">{o.t}</span>
                    <span className="block text-xs text-slate-500">{o.d}</span>
                  </button>
                ))}
              </div>
              {scope === 'selected' && (
                <div className="rounded-xl border border-slate-200 dark:border-slate-700 motion-safe:animate-fade-in">
                  <div className="flex items-center gap-2 border-b border-slate-100 px-3 py-2 dark:border-slate-800">
                    <Search className="h-4 w-4 text-slate-400" />
                    <input value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Filter tables…" aria-label="Filter tables" className="flex-1 border-0 bg-transparent p-0 text-sm focus:ring-0 dark:text-white" />
                    <span className="text-xs text-slate-500">{tables.size} selected</span>
                  </div>
                  <div className="grid max-h-56 grid-cols-1 gap-1 overflow-y-auto p-2 sm:grid-cols-2">
                    {isLoading && Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-6 w-full" />)}
                    {shown.map((t) => (
                      <label key={t.name} className="flex cursor-pointer items-center justify-between gap-2 rounded-lg px-2 py-1 text-xs hover:bg-slate-50 dark:hover:bg-slate-800">
                        <Checkbox
                          checked={tables.has(t.name)}
                          onChange={(e) => setTables((s) => { const n = new Set(s); if (e.target.checked) n.add(t.name); else n.delete(t.name); return n; })}
                          label={<span className="font-mono text-[12px] font-normal">{t.name}</span>}
                        />
                        <span className="tabular-nums text-slate-400">{formatNumber(t.rows)}</span>
                      </label>
                    ))}
                  </div>
                </div>
              )}
              <div className="rounded-xl border border-slate-200 px-3.5 py-3 dark:border-slate-700">
                <Toggle checked={safety} onChange={setSafety} label="Take a safety snapshot first" description="Backs up the current database so you can undo this restore." />
              </div>
            </div>
          )}

          <TypeToConfirm phrase="RESTORE" value={confirmText} onChange={(v) => { setConfirmText(v); setConfirmError(''); }} error={confirmError} />
          <p className="flex items-start gap-1.5 text-xs text-slate-500">
            <ShieldAlert className="mt-px h-3.5 w-3.5 shrink-0" /> The restore is recorded in the activity log and every user with backup access is notified.
          </p>
        </div>
      ) : null}
    </Modal>
  );
}
