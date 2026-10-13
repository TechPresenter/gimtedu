import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { CheckCircle2, GraduationCap, Link2, UserPlus } from 'lucide-react';
import { Alert, Avatar, Badge, Button, Checkbox, EmptyState, Modal, SearchInput, Skeleton, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { formatNumber } from '@/lib/format';
import { useRefreshModules } from './shared';

interface Candidate {
  id: number;
  student_uid: string;
  name: string;
  email: string | null;
  mobile: string | null;
  photo: string | null;
  program_short: string | null;
  program_name: string | null;
  status: string;
  city: string | null;
  batch_year: number;
  existing_alumni_id: number | null;
}

/** "Import passed-out students" — students with status Passed out / Alumni that are not yet in the directory. */
export function ImportStudentsModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast();
  const refresh = useRefreshModules();
  const { data, isLoading, error, refetch } = useApi<{ rows: Candidate[]; already_imported: number }>(['alumni-import-candidates'], 'alumni/import-candidates', undefined, { enabled: open });
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [q, setQ] = useState('');
  const [saving, setSaving] = useState(false);
  const rows = useMemo(() => data?.rows ?? [], [data]);

  useEffect(() => {
    if (open) void refetch();
  }, [open, refetch]);
  useEffect(() => {
    setSelected(new Set(rows.map((r) => r.id)));
  }, [rows]);

  const shown = useMemo(() => {
    const s = q.trim().toLowerCase();
    return s ? rows.filter((r) => `${r.name} ${r.student_uid} ${r.program_short ?? ''} ${r.email ?? ''}`.toLowerCase().includes(s)) : rows;
  }, [rows, q]);
  const allShown = shown.length > 0 && shown.every((r) => selected.has(r.id));
  const toggleAll = () =>
    setSelected((prev) => {
      const next = new Set(prev);
      shown.forEach((r) => (allShown ? next.delete(r.id) : next.add(r.id)));
      return next;
    });
  const toggle = (id: number) =>
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  const submit = async () => {
    setSaving(true);
    try {
      const res = await api.post<{ inserted: number; linked: number; skipped: number }>('alumni/import-students', { student_ids: [...selected] });
      toast.success(res.message);
      await refresh('alumni');
      onClose();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="xl"
      title="Import passed-out students"
      description="Create alumni profiles for students marked Passed out / Alumni. Students already in the directory are skipped automatically."
      icon={<span className="kpi-icon !h-9 !w-9 bg-accent-100 text-accent-700 dark:bg-accent-500/20 dark:text-accent-300"><GraduationCap className="h-5 w-5" /></span>}
      footer={
        <>
          <span className="mr-auto text-sm text-slate-500">{formatNumber(selected.size)} of {formatNumber(rows.length)} selected</span>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="success" icon={UserPlus} loading={saving} disabled={!selected.size} onClick={submit}>
            Import {selected.size ? formatNumber(selected.size) : ''} student{selected.size === 1 ? '' : 's'}
          </Button>
        </>
      }
    >
      {error ? (
        <Alert variant="error" title="Unable to load passed-out students">{(error as Error).message}</Alert>
      ) : isLoading || !data ? (
        <div className="space-y-3">{[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-12" />)}</div>
      ) : rows.length === 0 ? (
        <EmptyState
          icon={CheckCircle2}
          title="Everyone is already in the directory"
          description={data.already_imported ? `All ${formatNumber(data.already_imported)} passed-out students already have alumni profiles.` : 'No students are marked Passed out yet. Mark students as passed out from Students → Promotion.'}
          className="py-10"
        />
      ) : (
        <div className="space-y-3">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <SearchInput value={q} onChange={setQ} placeholder="Filter by name, ID, program…" className="w-full sm:max-w-xs" />
            <p className="text-xs text-slate-500 sm:ml-auto">
              New profiles start as <strong>Seeking opportunities</strong> — update career details later. {data.already_imported ? `${formatNumber(data.already_imported)} already imported.` : ''}
            </p>
          </div>
          <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
            <div className="flex items-center gap-3 border-b border-slate-100 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-800/50">
              <Checkbox checked={allShown} onChange={toggleAll} aria-label="Select all shown students" />
              <span className="flex-1">Student</span>
              <span className="hidden w-28 sm:block">Program</span>
              <span className="w-16 text-center">Batch</span>
            </div>
            <ul className="max-h-[50vh] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
              {shown.map((r) => (
                <li key={r.id}>
                  <label className={clsx('flex cursor-pointer items-center gap-3 px-3 py-2.5 transition hover:bg-slate-50 dark:hover:bg-slate-800/50', selected.has(r.id) && 'bg-brand-50/40 dark:bg-brand-500/5')}>
                    <Checkbox checked={selected.has(r.id)} onChange={() => toggle(r.id)} aria-label={`Select ${r.name}`} />
                    <Avatar name={r.name} src={r.photo} size="sm" />
                    <span className="min-w-0 flex-1">
                      <span className="flex flex-wrap items-center gap-1.5">
                        <span className="truncate text-sm font-semibold text-slate-900 dark:text-white">{r.name}</span>
                        {r.existing_alumni_id && <Badge color="cyan"><Link2 className="h-3 w-3" /> links to existing profile</Badge>}
                      </span>
                      <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{r.student_uid}{r.email ? ` · ${r.email}` : ''}</span>
                    </span>
                    <span className="hidden w-28 truncate text-sm text-slate-600 sm:block dark:text-slate-300">{r.program_short ?? '—'}</span>
                    <span className="w-16 text-center text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{r.batch_year}</span>
                  </label>
                </li>
              ))}
              {shown.length === 0 && <li className="px-3 py-8 text-center text-sm text-slate-500">No students match “{q}”.</li>}
            </ul>
          </div>
        </div>
      )}
    </Modal>
  );
}
