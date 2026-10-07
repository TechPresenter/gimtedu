import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import {
  ArchiveRestore, CalendarClock, CircleAlert, CircleCheck, CircleX, Clock, Database, DatabaseBackup, Download, FileArchive, FilterX, FolderArchive, HardDrive, Image as ImageIcon,
  Lock, Play, RefreshCw, Save, ShieldCheck, Terminal, Trash2, type LucideIcon,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, CardHeader, DataTable, EmptyState, Field, IconButton, Input, Modal, PageHeader, Pagination, Reveal, SearchInput, Select, Skeleton, Stagger, StatTile,
  StatusBadge, Textarea, useConfirm, useToast, type Column,
} from '@/components/ui';
import { api, ApiError, downloadFile } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { useApi, useCrudList } from '@/lib/queries';
import { formatDateTime, formatNumber, humanFileSize, timeAgo } from '@/lib/format';
import { CopyButton } from './components';
import { RestoreModal } from './backup/RestoreModal';
import type { BackupRow } from './types';
import './system.css';

interface Overview {
  health: 'healthy' | 'stale' | 'failed' | 'none';
  last: BackupRow | null;
  last_success: BackupRow | null;
  last_files: { created_at: string; size_bytes: number } | null;
  age_days: number | null;
  totals: { total: number; completed: number; failed_30d: number; bytes: number; restores: number };
  schedule: { auto_backup: string; backup_retention: number; next_due: string | null; last_cron: string | null };
  database: { tables: number; rows: number; bytes: number; name: string; server: string };
  uploads: { files: number; bytes: number };
  storage: { path: string; writable: boolean; free_bytes: number | null; zip: boolean; zlib: boolean };
  can: { create: boolean; delete: boolean; manage: boolean };
  cron?: { url: string; command: string; windows: string };
}

const sourceLabel: Record<string, string> = { manual: 'Manual', auto: 'Scheduled', 'pre-restore': 'Safety snapshot', seed: 'Initial' };
const healthMeta: Record<Overview['health'], { icon: LucideIcon; title: string; cls: string; ring: string }> = {
  healthy: { icon: ShieldCheck, title: 'Backups are healthy', cls: 'from-emerald-50 dark:from-emerald-500/10', ring: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300' },
  stale: { icon: CircleAlert, title: 'A backup is overdue', cls: 'from-amber-50 dark:from-amber-500/10', ring: 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300' },
  failed: { icon: CircleX, title: 'The last backup failed', cls: 'from-red-50 dark:from-red-500/10', ring: 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300' },
  none: { icon: Database, title: 'No backups yet', cls: 'from-slate-100 dark:from-slate-800/60', ring: 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' },
};

export default function BackupPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { data: ov, isLoading: ovLoading, error: ovError, refetch: refetchOv } = useApi<Overview>(['backup-overview'], 'backup/overview');
  const [q, setQ] = useState('');
  const [type, setType] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const dq = useDebounce(q, 350);
  useEffect(() => setPage(1), [dq, type, status]);
  const { data: list, isLoading, isFetching, error } = useCrudList<BackupRow>('backups', { page, per_page: 10, q: dq || undefined, f: { type: type || undefined, status: status || undefined } });
  const [createType, setCreateType] = useState<'database' | 'files' | null>(null);
  const [restoreOf, setRestoreOf] = useState<BackupRow | null>(null);
  const [verifyOf, setVerifyOf] = useState<BackupRow | null>(null);

  const refresh = () => Promise.all([qc.invalidateQueries({ queryKey: ['crud', 'backups'] }), qc.invalidateQueries({ queryKey: ['backup-overview'] })]);
  const remove = async (b: BackupRow) => {
    if (!(await confirm({ title: 'Delete backup?', message: <>Permanently delete <strong className="font-mono">{b.filename}</strong> ({humanFileSize(b.size_bytes)})? It cannot be restored afterwards.</>, confirmText: 'Delete backup', danger: true }))) return;
    try {
      const res = await api.del(`backup/${b.id}`);
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const columns: Column<BackupRow>[] = [
    {
      key: 'filename', header: 'Backup',
      render: (b) => (
        <div className="flex min-w-0 items-center gap-3">
          <span className={clsx('kpi-icon !h-9 !w-9 shrink-0', b.type === 'files' ? 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300' : 'bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200')}>
            {b.type === 'files' ? <FolderArchive className="h-4 w-4" /> : <Database className="h-4 w-4" />}
          </span>
          <div className="min-w-0">
            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-slate-800 dark:text-slate-100">
              <span className="whitespace-nowrap">{formatDateTime(b.created_at)}</span>
              <Badge color={b.source === 'auto' ? 'cyan' : b.source === 'pre-restore' ? 'amber' : b.source === 'seed' ? 'purple' : 'slate'} dot={false} className="!px-1.5 !py-0 text-[10px]">{sourceLabel[b.source] ?? b.source}</Badge>
              <StatusBadge status={b.status} />
            </p>
            <p className="max-w-[20rem] truncate font-mono text-[11px] text-slate-500" title={b.filename}>{b.filename}</p>
            <p className="max-w-[20rem] truncate text-xs text-slate-500">
              {b.type === 'files' ? `${formatNumber(b.meta?.files)} files` : `${formatNumber(b.meta?.tables)} tables · ${formatNumber(b.meta?.rows)} rows`}
              {b.created_by_name ? ` · by ${b.created_by_name}` : ' · system'}
              {b.notes ? ` · ${b.notes}` : ''}
            </p>
            {b.restore_count > 0 && <p className="text-[11px] text-amber-700 dark:text-amber-300">Restored ×{b.restore_count}{b.restored_at ? ` · last ${formatDateTime(b.restored_at)}${b.restored_by_name ? ` by ${b.restored_by_name}` : ''}` : ''}</p>}
            {b.status === 'completed' && !b.file_exists && <p className="text-[11px] text-red-600">File missing from storage/backups</p>}
            {b.error && <p className="max-w-[20rem] truncate text-xs text-red-600" title={b.error}>{b.error}</p>}
          </div>
        </div>
      ),
    },
    { key: 'size_bytes', header: 'Size', align: 'right', render: (b) => <span className="whitespace-nowrap font-medium tabular-nums">{b.status === 'completed' ? humanFileSize(b.size_bytes) : '—'}</span> },
  ];

  const health = ov ? healthMeta[ov.health] : null;
  const HealthIcon = health?.icon ?? Database;

  return (
    <>
      <PageHeader
        title="Backup & Restore"
        description="Create, download, verify and restore database and uploaded-file backups."
        breadcrumbs={[{ label: 'System' }, { label: 'Backup' }]}
        actions={
          can('backup', 'create') && (
            <>
              <Button variant="secondary" icon={FolderArchive} onClick={() => setCreateType('files')}>
                Files backup
              </Button>
              <Button icon={DatabaseBackup} onClick={() => setCreateType('database')}>
                Create database backup
              </Button>
            </>
          )
        }
      />

      {ovError && <Alert variant="error" className="mb-6" title="Unable to load backup status">{(ovError as ApiError).message}</Alert>}

      {/* Health */}
      <Reveal>
        <Card className={clsx('relative mb-6 overflow-hidden bg-gradient-to-r via-white to-white dark:via-slate-900 dark:to-slate-900', health?.cls)}>
          <div className="flex flex-col gap-5 p-5 md:flex-row md:items-center">
            {ovLoading || !ov ? (
              <div className="flex flex-1 items-center gap-4">
                <Skeleton className="h-14 w-14 rounded-2xl" />
                <div className="flex-1 space-y-2">
                  <Skeleton className="h-5 w-1/3" />
                  <Skeleton className="h-4 w-2/3" />
                </div>
              </div>
            ) : (
              <>
                <span className={clsx('relative inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl', health?.ring)}>
                  {ov.health === 'healthy' && <span className="absolute inset-0 animate-ping rounded-2xl bg-emerald-400/20 [animation-duration:2.5s] motion-reduce:hidden" />}
                  <HealthIcon className="relative h-7 w-7" />
                </span>
                <div className="min-w-0 flex-1">
                  <h2 className="font-display text-lg font-bold text-slate-900 dark:text-white">{health?.title}</h2>
                  <p className="text-sm text-slate-600 dark:text-slate-300">
                    {ov.last_success ? (
                      <>
                        Last successful database backup <strong>{timeAgo(ov.last_success.created_at)}</strong> ({formatDateTime(ov.last_success.created_at)}) · {humanFileSize(ov.last_success.size_bytes)} · {formatNumber(ov.last_success.meta?.tables)} tables
                      </>
                    ) : (
                      'Create your first database backup to protect institute data.'
                    )}
                  </p>
                  {ov.health === 'failed' && ov.last?.error && <p className="mt-1 text-xs text-red-600">{ov.last.error}</p>}
                  <p className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                    <span className="inline-flex items-center gap-1"><CalendarClock className="h-3.5 w-3.5" />{ov.schedule.auto_backup === 'off' ? 'Automatic backups off' : `Automatic ${ov.schedule.auto_backup} backups`}</span>
                    {ov.schedule.next_due && <span className="inline-flex items-center gap-1"><Clock className="h-3.5 w-3.5" />Next due {formatDateTime(ov.schedule.next_due)}</span>}
                    <span className="inline-flex items-center gap-1"><Lock className="h-3.5 w-3.5" />Stored in {ov.storage.path} (web access denied)</span>
                  </p>
                </div>
                {can('backup', 'create') && ov.health !== 'healthy' && (
                  <Button icon={DatabaseBackup} onClick={() => setCreateType('database')} className="shrink-0">
                    Back up now
                  </Button>
                )}
              </>
            )}
          </div>
        </Card>
      </Reveal>

      <Stagger className="mb-6 grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 sm:gap-4 xl:grid-cols-4">
        {[
          <StatTile key="b" label="Backups stored" value={ov?.totals.completed ?? 0} icon={FileArchive} tone="navy" sub={ov ? `${ov.totals.failed_30d} failed in 30 days` : undefined} loading={ovLoading} />,
          <StatTile key="s" label="Backup storage used" value={ov ? humanFileSize(ov.totals.bytes) : '—'} icon={HardDrive} tone="purple" sub={ov?.storage.free_bytes ? `${humanFileSize(ov.storage.free_bytes)} free on disk` : undefined} loading={ovLoading} />,
          <StatTile key="d" label="Live database" value={ov ? humanFileSize(ov.database.bytes) : '—'} icon={Database} tone="blue" sub={ov ? `${ov.database.tables} tables · ~${formatNumber(ov.database.rows)} rows` : undefined} loading={ovLoading} />,
          <StatTile key="u" label="Uploaded files" value={ov?.uploads.files ?? 0} icon={ImageIcon} tone="green" sub={ov ? `${humanFileSize(ov.uploads.bytes)} in assets/uploads` : undefined} loading={ovLoading} />,
        ]}
      </Stagger>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Card className="overflow-hidden xl:col-span-2">
          <div className="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
            <div>
              <h2 className="card-title">Backup history</h2>
              <p className="card-subtitle">{list ? `${formatNumber(list.total)} backup${list.total === 1 ? '' : 's'}` : 'Loading…'} · newest first</p>
            </div>
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <SearchInput value={q} onChange={setQ} placeholder="Search file name or notes…" className="w-full sm:max-w-xs" />
              <Select inputSize="sm" value={type} onChange={(e) => setType(e.target.value)} options={{ database: 'Database', files: 'Files' }} placeholder="Type: All" aria-label="Type" className="sm:!w-36" />
              <Select inputSize="sm" value={status} onChange={(e) => setStatus(e.target.value)} options={{ completed: 'Completed', failed: 'Failed', running: 'Running' }} placeholder="Status: All" aria-label="Status" className="sm:!w-36" />
              <IconButton icon={RefreshCw} label="Refresh" onClick={() => void refresh()} className={clsx('sm:ml-auto', isFetching && '[&_svg]:animate-spin')} />
            </div>
          </div>
          {error ? (
            <div className="p-5"><Alert variant="error">{(error as ApiError).message}</Alert></div>
          ) : (
            <DataTable<BackupRow>
              columns={columns}
              rows={list?.rows ?? []}
              loading={isLoading || isFetching}
              skeletonRows={5}
              caption="Backups"
              empty={
                q || type || status ? (
                  <EmptyState icon={FilterX} title="No matching backups" description="Clear the search or filters." action={<Button variant="secondary" onClick={() => { setQ(''); setType(''); setStatus(''); }}>Clear filters</Button>} />
                ) : (
                  <EmptyState icon={DatabaseBackup} title="No backups yet" description="A backup is a compressed copy of every table you can download or restore later." action={can('backup', 'create') ? <Button icon={DatabaseBackup} onClick={() => setCreateType('database')}>Create database backup</Button> : undefined} />
                )
              }
              actions={(b) => (
                <div className="flex items-center justify-end gap-0.5">
                  {can('backup', 'create') && b.status === 'completed' && b.file_exists && <IconButton size="sm" icon={Download} label="Download" tone="primary" onClick={() => downloadFile(`backup/${b.id}/download`)} />}
                  {b.status === 'completed' && <IconButton size="sm" icon={ShieldCheck} label="Verify integrity" tone="primary" onClick={() => setVerifyOf(b)} />}
                  {can('backup', 'manage') && b.status === 'completed' && b.file_exists && <IconButton size="sm" icon={ArchiveRestore} label="Restore" onClick={() => setRestoreOf(b)} className="hover:!bg-amber-50 hover:!text-amber-700 dark:hover:!bg-amber-500/10" />}
                  {can('backup', 'delete') && b.status !== 'running' && <IconButton size="sm" icon={Trash2} label="Delete" tone="danger" onClick={() => remove(b)} />}
                </div>
              )}
            />
          )}
          {list && list.total > list.per_page && <Pagination className="border-t border-slate-100 dark:border-slate-800" page={list.page} pages={list.pages} total={list.total} perPage={list.per_page} onPage={setPage} />}
        </Card>

        <div className="space-y-6">
          <ScheduleCard ov={ov} loading={ovLoading} onSaved={() => void refetchOv()} />
          <Reveal delay={100}>
            <Card className="p-5">
              <h3 className="card-title">How backups work</h3>
              <ul className="mt-3 space-y-2.5 text-sm text-slate-600 dark:text-slate-300">
                {[
                  'Pure-PHP SQL dump of every table, gzip-compressed, with a SHA-256 checksum.',
                  'Files backups ZIP everything in assets/uploads (scripts are never included).',
                  'Restores validate the file first and run in a single transaction — on any error nothing changes.',
                  'A safety snapshot is taken automatically before each database restore.',
                  `Backups older than the retention period are pruned; the 3 newest are always kept.`,
                ].map((t) => (
                  <li key={t} className="flex gap-2">
                    <CircleCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />
                    <span>{t}</span>
                  </li>
                ))}
              </ul>
              {ov && (!ov.storage.writable || !ov.storage.zip || !ov.storage.zlib) && (
                <Alert variant="error" className="mt-4">
                  {!ov.storage.writable ? 'storage/backups is not writable by the web server.' : !ov.storage.zlib ? 'The PHP zlib extension is missing.' : 'The PHP zip extension is missing — files backups are unavailable.'}
                </Alert>
              )}
            </Card>
          </Reveal>
        </div>
      </div>

      <CreateBackupModal type={createType} onClose={() => setCreateType(null)} onDone={() => void refresh()} />
      <RestoreModal backup={restoreOf} onClose={() => setRestoreOf(null)} onDone={() => { void refresh(); void qc.invalidateQueries(); }} />
      <VerifyModal backup={verifyOf} onClose={() => setVerifyOf(null)} />
    </>
  );
}

function ScheduleCard({ ov, loading, onSaved }: { ov?: Overview; loading: boolean; onSaved: () => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const [auto, setAuto] = useState('off');
  const [retention, setRetention] = useState('15');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [running, setRunning] = useState(false);
  useEffect(() => {
    if (ov) {
      setAuto(ov.schedule.auto_backup);
      setRetention(String(ov.schedule.backup_retention));
    }
  }, [ov]);
  const manage = can('backup', 'manage');
  const dirty = !!ov && (auto !== ov.schedule.auto_backup || retention !== String(ov.schedule.backup_retention));
  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post('backup/settings', { auto_backup: auto, backup_retention: retention });
      toast.success(res.message);
      onSaved();
    } catch (e) {
      const ex = e as ApiError;
      setErrors(ex.errors ?? {});
      toast.error(ex.message);
    } finally {
      setSaving(false);
    }
  };
  const runNow = async () => {
    setRunning(true);
    try {
      const res = await api.post('backup/run-scheduled');
      toast.success(res.message);
      onSaved();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setRunning(false);
    }
  };
  return (
    <Reveal delay={60}>
      <Card>
        <CardHeader title="Schedule & retention" subtitle="Automatic database backups" icon={CalendarClock} />
        <div className="space-y-4 p-5">
          {loading || !ov ? (
            <>
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
            </>
          ) : (
            <>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Frequency" htmlFor="b-auto" error={errors.auto_backup}>
                  <Select id="b-auto" value={auto} onChange={(e) => setAuto(e.target.value)} options={{ off: 'Off', daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' }} disabled={!manage} />
                </Field>
                <Field label="Keep for" htmlFor="b-ret" error={errors.backup_retention}>
                  <Input id="b-ret" type="number" min={0} max={3650} value={retention} onChange={(e) => setRetention(e.target.value)} suffix="days" disabled={!manage} invalid={!!errors.backup_retention} />
                </Field>
              </div>
              <p className="text-xs text-slate-500">0 days keeps backups forever. {ov.schedule.last_cron ? `Scheduled task last ran ${timeAgo(ov.schedule.last_cron)}.` : 'The scheduled task has not run yet.'}</p>
              {manage && (
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" icon={Save} onClick={save} loading={saving} disabled={!dirty}>
                    Save schedule
                  </Button>
                  {ov.schedule.auto_backup !== 'off' && (
                    <Button size="sm" variant="secondary" icon={Play} onClick={runNow} loading={running}>
                      Run scheduled backup
                    </Button>
                  )}
                </div>
              )}
              {ov.cron && (
                <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                  <div className="mb-1.5 flex items-center justify-between">
                    <p className="flex items-center gap-1.5 text-xs font-semibold text-slate-700 dark:text-slate-200"><Terminal className="h-3.5 w-3.5" /> Server cron job</p>
                    <CopyButton value={ov.cron.command} />
                  </div>
                  <code className="block break-all font-mono text-[11px] leading-relaxed text-slate-600 dark:text-slate-300">{ov.cron.command}</code>
                  <p className="mt-2 text-[11px] text-slate-500">Runs daily at 02:00 and creates a backup only when one is due. Keep this URL private — its token is derived from the application key.</p>
                </div>
              )}
            </>
          )}
        </div>
      </Card>
    </Reveal>
  );
}

function CreateBackupModal({ type, onClose, onDone }: { type: 'database' | 'files' | null; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [notes, setNotes] = useState('');
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<{ b: BackupRow; message: string } | null>(null);
  useEffect(() => {
    if (type) {
      setNotes('');
      setError(null);
      setResult(null);
    }
  }, [type]);
  const start = async () => {
    setRunning(true);
    setError(null);
    try {
      const res = await api.post<BackupRow>('backup/create', { type, notes });
      setResult({ b: res.data, message: res.message });
      toast.success(res.message);
      onDone();
    } catch (e) {
      const ex = e as ApiError;
      setError(ex.message);
      onDone();
    } finally {
      setRunning(false);
    }
  };
  const isFiles = type === 'files';
  return (
    <Modal
      open={!!type}
      onClose={onClose}
      static={running}
      size="md"
      title={isFiles ? 'Create files backup' : 'Create database backup'}
      description={isFiles ? 'ZIP archive of every uploaded file (photos, documents, media).' : 'Compressed SQL dump of every table in the database.'}
      footer={
        result ? (
          <>
            <Button variant="secondary" icon={Download} onClick={() => downloadFile(`backup/${result.b.id}/download`)}>
              Download
            </Button>
            <Button onClick={onClose}>Done</Button>
          </>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose} disabled={running}>
              Cancel
            </Button>
            <Button icon={isFiles ? FolderArchive : DatabaseBackup} onClick={start} loading={running}>
              {running ? 'Backing up…' : 'Start backup'}
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
          <p className="font-semibold text-slate-900 dark:text-white">{result.message}</p>
          <code className="inline-block rounded-lg bg-slate-100 px-3 py-1.5 font-mono text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">{result.b.filename}</code>
          <p className="text-xs text-slate-500">Completed in {((result.b.meta?.duration_ms ?? 0) / 1000).toFixed(1)} s · SHA-256 {result.b.checksum?.slice(0, 16)}…</p>
        </div>
      ) : (
        <div className="space-y-4">
          {error && <Alert variant="error" title="Backup failed">{error}</Alert>}
          {running ? (
            <div className="rounded-xl border border-brand-200 bg-brand-50/60 p-4 dark:border-brand-500/30 dark:bg-brand-500/10" role="status">
              <p className="text-sm font-semibold text-brand-900 dark:text-white">{isFiles ? 'Compressing uploaded files…' : 'Dumping tables and compressing…'}</p>
              <p className="text-xs text-slate-600 dark:text-slate-300">This usually takes a few seconds. Please keep this window open.</p>
              <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-brand-100 dark:bg-brand-500/20">
                <div className="sys-indeterminate h-full w-1/3 rounded-full bg-brand-700 dark:bg-brand-300" />
              </div>
            </div>
          ) : (
            <Field label="Notes (optional)" htmlFor="b-notes" hint="e.g. Before semester result publication">
              <Textarea id="b-notes" value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={255} rows={2} />
            </Field>
          )}
        </div>
      )}
    </Modal>
  );
}

function VerifyModal({ backup, onClose }: { backup: BackupRow | null; onClose: () => void }) {
  const [res, setRes] = useState<{ ok: boolean; checks: { label: string; ok: boolean }[]; message: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const started = useRef<number | null>(null);
  useEffect(() => {
    if (!backup) {
      started.current = null;
      return;
    }
    if (started.current === backup.id) return; // StrictMode double effect guard
    started.current = backup.id;
    setRes(null);
    setError(null);
    api
      .post<{ ok: boolean; checks: { label: string; ok: boolean }[] }>(`backup/${backup.id}/verify`)
      .then((r) => setRes({ ...r.data, message: r.message }))
      .catch((e: ApiError) => setError(e.message));
  }, [backup]);
  return (
    <Modal open={!!backup} onClose={onClose} size="sm" title="Verify backup" description={backup?.filename} footer={<Button onClick={onClose}>Close</Button>}>
      {error ? (
        <Alert variant="error">{error}</Alert>
      ) : !res ? (
        <div className="space-y-3" role="status">
          <p className="text-sm text-slate-500">Checking file, size and SHA-256 checksum…</p>
          <div className="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
            <div className="sys-indeterminate h-full w-1/3 rounded-full bg-brand-700 dark:bg-brand-300" />
          </div>
        </div>
      ) : (
        <div className="space-y-3">
          <Alert variant={res.ok ? 'success' : 'error'}>{res.message}</Alert>
          <ul className="space-y-2">
            {res.checks.map((c, i) => (
              <li key={c.label} className="flex items-center gap-2 text-sm motion-safe:animate-slide-up" style={{ animationDelay: `${i * 60}ms`, animationFillMode: 'both' }}>
                {c.ok ? <CircleCheck className="h-4 w-4 text-emerald-500" /> : <CircleX className="h-4 w-4 text-red-500" />}
                <span className={c.ok ? 'text-slate-700 dark:text-slate-200' : 'text-red-700 dark:text-red-300'}>{c.label}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Modal>
  );
}
