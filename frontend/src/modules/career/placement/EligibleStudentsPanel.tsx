import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { CheckCircle2, FilterX, GraduationCap, UserPlus, Users } from 'lucide-react';
import { Alert, Badge, Button, Card, DataTable, EmptyState, Pagination, PersonCell, SearchInput, Select, StatusBadge, useConfirm, useToast, type Column } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import type { Paginated } from '@/lib/types';
import type { DriveDetail, EligibleStudent } from './types';
import { APP_STATUS, APP_STATUS_COLORS, lpa, usePlacementRefresh } from './shared';

type EligiblePayload = Paginated<EligibleStudent> & { summary: { eligible: number; registered: number; unregistered: number; placed: number } };

/** Students meeting the drive's criteria (computed from programs, batch and latest published results) + bulk registration. */
export function EligibleStudentsPanel({ detail }: { detail: DriveDetail }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = usePlacementRefresh();
  const d = detail.drive;
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 300);
  const [show, setShow] = useState('unregistered');
  const [program, setProgram] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [selected, setSelected] = useState<Set<string | number>>(new Set());
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    setPage(1);
    setSelected(new Set());
  }, [dq, show, program, perPage]);
  const query = { q: dq || undefined, show, program_id: program || undefined, page, per_page: perPage };
  const { data, isLoading, isFetching, error } = useApi<EligiblePayload>(['placement', 'eligible', d.id, query], `placement/drives/${d.id}/eligible`, query, { placeholderData: (prev) => prev });
  const canRegister = can('placement', 'create') && detail.can_register;

  const register = async (all: boolean) => {
    const n = all ? data?.summary.unregistered ?? 0 : selected.size;
    if (all) {
      const ok = await confirm({ title: `Register all ${n} eligible students?`, message: `Every eligible student who has not registered yet is added to ${d.company_name} — ${d.title} with status “Applied”.`, confirmText: 'Register all' });
      if (!ok) return;
    }
    setBusy(true);
    try {
      const res = await api.post('placement/drives/' + d.id + '/register', all ? { all_eligible: true } : { student_ids: [...selected] });
      toast.success(res.message);
      setSelected(new Set());
      await refresh('placement_applications', 'placement_drives');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  const columns: Column<EligibleStudent>[] = useMemo(
    () => [
      { key: 'name', header: 'Student', render: (r) => <PersonCell name={r.name} src={r.photo} sub={`${r.student_uid} · ${r.program_short} · Sem ${r.current_semester}`} /> },
      { key: 'cgpa', header: 'CGPA', align: 'center', render: (r) => (r.cgpa === null ? <span className="text-slate-400">—</span> : <span className="font-semibold tabular-nums">{r.cgpa.toFixed(2)}</span>) },
      { key: 'backlogs', header: 'Backlogs', align: 'center', render: (r) => <span className={clsx('tabular-nums', r.backlogs > 0 && 'font-semibold text-amber-700 dark:text-amber-300')}>{r.backlogs}</span> },
      { key: 'previous_percentage', header: 'Prev. %', align: 'center', render: (r) => (r.previous_percentage ? `${Number(r.previous_percentage)}%` : '—') },
      { key: 'best_offer_lpa', header: 'Placed', render: (r) => (r.best_offer_lpa ? <Badge color="green">{lpa(r.best_offer_lpa)}</Badge> : <span className="text-slate-400">Not yet</span>) },
      {
        key: 'application_status', header: 'Registration',
        render: (r) => (r.application_status ? <StatusBadge status={r.application_status} label={APP_STATUS[r.application_status]} colors={APP_STATUS_COLORS} /> : <span className="text-xs text-slate-500">Not registered</span>),
      },
    ],
    [],
  );

  const s = data?.summary;
  const programs = detail.programs;
  return (
    <Card className="overflow-hidden">
      <div className="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="card-title">Eligible students</h2>
            <p className="card-subtitle">Computed from the eligible programs, batch {d.batch_year ?? '(final year)'} and the latest published results.</p>
          </div>
          {canRegister && (
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="secondary" icon={UserPlus} disabled={!selected.size} loading={busy && selected.size > 0} onClick={() => void register(false)}>
                Register selected{selected.size ? ` (${selected.size})` : ''}
              </Button>
              <Button size="sm" icon={Users} disabled={!s?.unregistered} loading={busy && !selected.size} onClick={() => void register(true)}>
                Register all eligible{s?.unregistered ? ` (${s.unregistered})` : ''}
              </Button>
            </div>
          )}
        </div>
        {s && (
          <div className="flex flex-wrap gap-2 text-xs">
            <span className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-1 font-semibold text-brand-800 dark:bg-brand-500/15 dark:text-brand-200"><GraduationCap className="h-3.5 w-3.5" />{s.eligible} eligible</span>
            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"><CheckCircle2 className="h-3.5 w-3.5" />{s.registered} registered</span>
            <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">{s.unregistered} not registered</span>
            <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{s.placed} already placed</span>
          </div>
        )}
        {!detail.can_register && <Alert variant="info">Registrations are closed — this drive is {d.status}.</Alert>}
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <SearchInput value={q} onChange={setQ} placeholder="Search name, ID or roll no…" className="w-full sm:max-w-xs" />
          <Select inputSize="sm" aria-label="Registration" value={show} onChange={(e) => setShow(e.target.value)} className="sm:!w-48"
            options={[{ value: 'unregistered', label: 'Not registered' }, { value: 'registered', label: 'Registered' }, { value: 'all', label: 'All eligible' }]} />
          {programs.length > 1 && (
            <Select inputSize="sm" aria-label="Program" value={program} onChange={(e) => setProgram(e.target.value)} className="sm:!w-48" placeholder="All programs"
              options={programs.map((p) => ({ value: p.id, label: p.short_name }))} />
          )}
        </div>
      </div>
      {error ? (
        <div className="p-6"><Alert variant="error" title="Unable to compute eligible students">{(error as Error).message}</Alert></div>
      ) : (
        <DataTable<EligibleStudent>
          columns={columns}
          rows={data?.rows ?? []}
          loading={isLoading || isFetching}
          selectable={canRegister}
          selected={selected}
          onSelectedChange={(sel) => setSelected(new Set([...sel].filter((id) => !(data?.rows ?? []).find((r) => r.id === id)?.application_id)))}
          rowClassName={(r) => (r.application_id ? 'opacity-80' : undefined)}
          caption="Eligible students"
          empty={
            <EmptyState
              icon={dq || program ? FilterX : Users}
              title={dq || program ? 'No matching students' : show === 'unregistered' ? 'Everyone eligible is registered' : 'No eligible students'}
              description={dq || program ? 'Try another search or program.' : show === 'unregistered' ? 'All students who meet the criteria have been registered for this drive.' : 'No active student currently meets the eligibility criteria. Relax the CGPA / backlog limits or add programs.'}
              action={dq || program || show !== 'all' ? <Button variant="secondary" onClick={() => { setQ(''); setProgram(''); setShow('all'); }}>Show all eligible</Button> : undefined}
            />
          }
        />
      )}
      {data && data.total > 0 && (
        <Pagination className="border-t border-slate-100 dark:border-slate-800" page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} onPerPage={setPerPage} />
      )}
    </Card>
  );
}
