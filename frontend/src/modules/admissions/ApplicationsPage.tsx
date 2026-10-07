import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowRightLeft, FileText, Mail, PhoneCall, Plus, Printer, SquareKanban } from 'lucide-react';
import { Avatar, Button, Field, Modal, PageHeader, PersonCell, Select, toneClasses } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import { formatDate, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { ALL_STAGES, FollowupModal, STAGE_TONE, StageBadge, StageMoveModal, sourceLabel, type FollowupTarget, type MoveRequest } from './shared';

export default function ApplicationsPage() {
  const [params, setParams] = useSearchParams();
  const { id: sessionId } = useAcademicSession();
  const { can } = useAuth();
  const [bulkIds, setBulkIds] = useState<{ ids: number[]; clear: () => void } | null>(null);
  const [moveReq, setMoveReq] = useState<MoveRequest | null>(null);
  const [followup, setFollowup] = useState<FollowupTarget | null>(null);
  const stageParam = params.get('f.stage') ?? '';
  // Remount the table when a stage chip changes the URL filter so CrudTable re-reads it.
  const [tableKey, setTableKey] = useState(0);
  const defaults = useMemo(() => (sessionId && !params.get('f.academic_session_id') ? { academic_session_id: String(sessionId) } : {}), [sessionId]); // eslint-disable-line react-hooks/exhaustive-deps

  const pickStage = (stage: string) => {
    const next = new URLSearchParams(params);
    if (!stage || stage === stageParam) next.delete('f.stage');
    else next.set('f.stage', stage);
    next.delete('page');
    setParams(next, { replace: true });
    setTableKey((k) => k + 1);
  };

  return (
    <>
      <PageHeader
        title="Applications"
        description="All admission applications with stage, counsellor, scores and documents."
        breadcrumbs={[{ label: 'Admissions', to: '/admissions' }, { label: 'Applications' }]}
        actions={
          <>
            <Button variant="secondary" icon={SquareKanban} to="/admissions">
              Pipeline board
            </Button>
            {can('admissions', 'create') && (
              <Button variant="success" icon={Plus} to="/admissions/new">
                New application
              </Button>
            )}
          </>
        }
      />
      <CrudTable
        key={`${tableKey}-${sessionId ?? 0}`}
        module="admissions"
        urlState
        title="All applications"
        defaultFilters={defaults}
        viewTo={(r) => `/admissions/${r.id}`}
        createTo="/admissions/new"
        editTo={(r) => `/admissions/${r.id}/edit`}
        addLabel="New application"
        emptyTitle="No applications yet"
        emptyText="Applications submitted online or entered at the admission desk will appear here."
        renderers={{
          application_no: (r) => (
            <Link to={`/admissions/${r.id}`} className="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-200">
              {String(r.application_no)}
            </Link>
          ),
          full_name: (r) => (
            <div className="min-w-[11rem] whitespace-nowrap">
              <PersonCell name={String(r.full_name)} sub={<span className="font-mono">{String(r.phone ?? '')}</span>} src={r.photo as string | null} to={`/admissions/${r.id}`} />
            </div>
          ),
          program_name: (r) => (
            <div className="max-w-[11rem] leading-tight">
              <p className="whitespace-nowrap font-semibold text-slate-900 dark:text-white">{String(r.program_name)}</p>
              {r.course_name && <p className="truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.course_name)}>{String(r.course_name).replace(/^.*? - /, '')}</p>}
            </div>
          ),
          counsellor_name: (r) =>
            r.counsellor_name ? (
              <span className="inline-flex items-center gap-2 whitespace-nowrap text-sm text-slate-700 dark:text-slate-200">
                <Avatar name={String(r.counsellor_name)} src={r.counsellor_avatar as string | null} size="xs" />
                {String(r.counsellor_name)}
              </span>
            ) : (
              <span className="text-xs text-slate-400">Unassigned</span>
            ),
          created_at: (r) => <span className="whitespace-nowrap">{formatDate(r.created_at)}</span>,
          stage: (r) => <span className="whitespace-nowrap"><StageBadge stage={String(r.stage)} /></span>,
          source: (r) => <span className="badge badge-slate">{sourceLabel(String(r.source))}</span>,
          score: (r) =>
            r.score !== null && r.score !== undefined ? (
              <span className="font-semibold tabular-nums text-slate-800 dark:text-slate-100">{Number(r.score).toFixed(1)}</span>
            ) : r.previous_percentage ? (
              <span className="text-xs text-slate-500" title="Qualifying exam %">{Number(r.previous_percentage).toFixed(1)}%</span>
            ) : (
              <span className="text-slate-400">—</span>
            ),
        }}
        header={({ summary }) => <StageChips counts={(summary?.stages as Record<string, number>) ?? {}} active={stageParam} onPick={pickStage} />}
        rowMenu={(r: Row) => [
          can('admissions', 'edit') && { label: 'Log follow-up', icon: PhoneCall, onClick: () => setFollowup({ kind: 'admission', id: Number(r.id), name: String(r.full_name), sub: String(r.application_no) }) },
          { label: 'Print application form', icon: Printer, onClick: () => window.open(printUrl('admission-form.php', { id: Number(r.id) }), '_blank') },
          (r.stage === 'fee_payment' || r.stage === 'confirmed') && { label: 'Offer letter', icon: FileText, onClick: () => window.open(printUrl('offer-letter.php', { id: Number(r.id) }), '_blank') },
          Number(r.fee_paid) > 0 && { label: 'Admission receipt', icon: Printer, onClick: () => window.open(printUrl('admission-receipt.php', { id: Number(r.id) }), '_blank') },
          r.email && { label: 'Email applicant', icon: Mail, href: `mailto:${r.email}` },
        ]}
        bulkActions={
          can('admissions', 'edit')
            ? [{ label: 'Change stage', icon: ArrowRightLeft, onClick: (ids, clear) => setBulkIds({ ids, clear }) }]
            : []
        }
      />
      <BulkStageModal
        target={bulkIds}
        onClose={() => setBulkIds(null)}
        onPick={(to) => {
          if (!bulkIds) return;
          setMoveReq({ ids: bulkIds.ids, to, title: `Move ${bulkIds.ids.length} application${bulkIds.ids.length === 1 ? '' : 's'} to ${ALL_STAGES.find((s) => s.key === to)?.label}` });
          bulkIds.clear();
          setBulkIds(null);
        }}
      />
      <StageMoveModal request={moveReq} onClose={() => setMoveReq(null)} />
      <FollowupModal open={!!followup} target={followup} onClose={() => setFollowup(null)} />
    </>
  );
}

function StageChips({ counts, active, onPick }: { counts: Record<string, number>; active: string; onPick: (s: string) => void }) {
  const total = Object.values(counts).reduce((a, b) => a + b, 0);
  return (
    <div className="flex gap-2 overflow-x-auto border-b border-slate-100 px-4 py-3 scrollbar-none dark:border-slate-800" role="toolbar" aria-label="Filter by stage">
      <button type="button" onClick={() => onPick('')} aria-pressed={!active}
        className={clsx('inline-flex shrink-0 items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-medium transition', !active ? 'border-brand-700 bg-brand-800 text-white dark:border-brand-500 dark:bg-brand-600' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800')}>
        All <span className="tabular-nums opacity-80">{formatNumber(total)}</span>
      </button>
      {ALL_STAGES.map((s) => {
        const on = active === s.key;
        return (
          <button key={s.key} type="button" onClick={() => onPick(s.key)} aria-pressed={on}
            className={clsx('inline-flex shrink-0 items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-medium transition', on ? 'border-brand-700 bg-brand-50 text-brand-900 dark:border-brand-400 dark:bg-brand-500/15 dark:text-white' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800')}>
            <span className={clsx('h-2 w-2 rounded-full', toneClasses[STAGE_TONE[s.key] ?? 'slate'].bar)} aria-hidden />
            {s.label}
            <span className="tabular-nums text-slate-400">{formatNumber(counts[s.key] ?? 0)}</span>
          </button>
        );
      })}
    </div>
  );
}

function BulkStageModal({ target, onClose, onPick }: { target: { ids: number[] } | null; onClose: () => void; onPick: (stage: string) => void }) {
  const [stage, setStage] = useState('');
  const [error, setError] = useState('');
  return (
    <Modal
      open={!!target}
      onClose={onClose}
      size="sm"
      title="Change stage"
      description={target ? `${target.ids.length} application${target.ids.length === 1 ? '' : 's'} selected. Each move is validated individually.` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={() => (stage ? onPick(stage) : setError('Select the stage to move to.'))}>Continue</Button>
        </>
      }
    >
      <Field label="Move to stage" required error={error} htmlFor="bulk-stage">
        <Select id="bulk-stage" value={stage} onChange={(e) => { setStage(e.target.value); setError(''); }} options={ALL_STAGES.map((s) => ({ value: s.key, label: s.label }))} placeholder="Select stage" invalid={!!error} />
      </Field>
      <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
        Applications move one step at a time and must meet the stage rules (documents verified, scores recorded, fee paid). Ones that don&apos;t will be listed with the reason.
      </p>
    </Modal>
  );
}
