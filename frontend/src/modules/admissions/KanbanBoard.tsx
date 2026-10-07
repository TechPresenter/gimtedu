import { useEffect, useMemo, useState, type DragEvent } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  Award, BadgeCheck, Ban, ChevronLeft, ChevronRight, Clock, Eye, FileCheck2, GripVertical, Hourglass, MoreHorizontal, PhoneCall, RefreshCw, Undo2, UserX,
} from 'lucide-react';
import { Avatar, Button, Dropdown, IconButton, SearchInput, Select, Skeleton, Toggle, toneClasses, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi, useInvalidate, useLookup } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { formatMoney } from '@/lib/format';
import { CLOSED_STAGES, DueChip, FollowupModal, PIPELINE, STAGE_TONE, StageMoveModal, sourceLabel, stageLabel, type BoardCard, type FollowupTarget, type MoveRequest } from './shared';

interface BoardColumn {
  stage: string;
  label: string;
  count: number;
  cards: BoardCard[];
}
interface BoardData {
  columns: BoardColumn[];
  counts: Record<string, number>;
  can: { edit: boolean; approve: boolean };
}

const PIPE = PIPELINE.map((s) => s.key);
const NEEDS_REMARKS = ['rejected', 'withdrawn', 'waitlisted'];

/** Allowed moves (mirrors admission_transitions() on the server; the server re-validates every move). */
export function transitionsFrom(stage: string): string[] {
  const i = PIPE.indexOf(stage);
  if (i >= 0) {
    if (stage === 'confirmed') return [];
    const t = [PIPE[i + 1]];
    if (i > 0) t.push(PIPE[i - 1]);
    if (stage === 'approval') t.push('waitlisted');
    return [...t, 'rejected', 'withdrawn'];
  }
  if (stage === 'waitlisted') return ['fee_payment', 'approval', 'rejected', 'withdrawn'];
  if (stage === 'rejected' || stage === 'withdrawn') return ['application'];
  return [];
}

function nextOf(stage: string): string | null {
  if (stage === 'waitlisted') return 'fee_payment';
  if (stage === 'rejected' || stage === 'withdrawn') return 'application';
  const i = PIPE.indexOf(stage);
  return i >= 0 && i < PIPE.length - 1 ? PIPE[i + 1] : null;
}
function prevOf(stage: string): string | null {
  const i = PIPE.indexOf(stage);
  return i > 0 && stage !== 'confirmed' ? PIPE[i - 1] : null;
}
function actionLabel(from: string, to: string): string {
  if (to === 'fee_payment' && (from === 'approval' || from === 'waitlisted')) return 'Approve';
  if (to === 'application' && (from === 'rejected' || from === 'withdrawn')) return 'Reopen';
  if (to === 'confirmed') return 'Confirm admission';
  return `Move to ${stageLabel(to)}`;
}

export function KanbanBoard({ sessionId }: { sessionId: number | null }) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const { can } = useAuth();
  const [q, setQ] = useState('');
  const [programId, setProgramId] = useState('');
  const [counsellor, setCounsellor] = useState('');
  const [showClosed, setShowClosed] = useState(false);
  const debouncedQ = useDebounce(q, 350);
  const params = { session_id: sessionId ?? undefined, q: debouncedQ || undefined, program_id: programId || undefined, assigned_to: counsellor || undefined, closed: showClosed ? 1 : undefined };
  const { data, isLoading, isFetching, refetch, error } = useApi<BoardData>(['adm-board', params], 'admissions/board', params, { placeholderData: keepPreviousData });
  const { data: programs = [] } = useLookup('programs');
  const { data: users = [] } = useLookup('users');
  const [extra, setExtra] = useState<Record<string, BoardCard[]>>({});
  const [loadingMore, setLoadingMore] = useState<string | null>(null);
  const [dragging, setDragging] = useState<BoardCard | null>(null);
  const [over, setOver] = useState<string | null>(null);
  const [moving, setMoving] = useState<number | null>(null);
  const [moveReq, setMoveReq] = useState<MoveRequest | null>(null);
  const [followup, setFollowup] = useState<FollowupTarget | null>(null);
  const canEdit = can('admissions', 'edit');

  useEffect(() => setExtra({}), [data]);

  const columns = useMemo(() => {
    const cols = data?.columns ?? [];
    return cols.map((c) => ({ ...c, cards: [...c.cards, ...(extra[c.stage] ?? []).filter((x) => !c.cards.some((y) => y.id === x.id))] }));
  }, [data, extra]);

  const loadMore = async (col: BoardColumn) => {
    setLoadingMore(col.stage);
    try {
      const res = await api.get<{ cards: BoardCard[] }>('admissions/board', { ...params, stage: col.stage, offset: col.cards.length, limit: 20 });
      setExtra((e) => ({ ...e, [col.stage]: [...(e[col.stage] ?? []), ...res.cards] }));
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setLoadingMore(null);
    }
  };

  const move = async (card: BoardCard, to: string) => {
    if (NEEDS_REMARKS.includes(to)) {
      setMoveReq({ ids: [card.id], to, names: `${card.name} · ${card.application_no}` });
      return;
    }
    setMoving(card.id);
    try {
      const res = await api.post(`admissions/${card.id}/move`, { to_stage: to });
      toast.success(res.message, card.name);
      await invalidate('adm-board', 'adm-dashboard', 'adm-profile', 'crud');
    } catch (e) {
      toast.error((e as ApiError).message, `Cannot move ${card.name}`);
    } finally {
      setMoving(null);
    }
  };

  const onDragStart = (e: DragEvent, card: BoardCard) => {
    e.dataTransfer.setData('text/plain', String(card.id));
    e.dataTransfer.effectAllowed = 'move';
    setDragging(card);
  };
  const allowedDrop = (stage: string) => !!dragging && transitionsFrom(dragging.stage).includes(stage);
  const onDrop = (e: DragEvent, stage: string) => {
    e.preventDefault();
    const card = dragging;
    setOver(null);
    setDragging(null);
    if (card && allowedDrop(stage)) void move(card, stage);
    else if (card && card.stage !== stage) toast.warning(`${stageLabel(card.stage)} applications cannot move directly to ${stageLabel(stage)}.`);
  };

  return (
    <div>
      <div className="flex flex-col gap-2 border-b border-slate-100 p-4 dark:border-slate-800 lg:flex-row lg:items-center">
        <SearchInput value={q} onChange={setQ} placeholder="Search applicant, application no, phone…" className="w-full lg:max-w-xs" size="sm" />
        <div className="flex flex-wrap items-center gap-2 lg:ml-auto">
          <Select inputSize="sm" value={programId} onChange={(e) => setProgramId(e.target.value)} options={programs.map((p) => ({ value: p.value, label: p.label.split(' — ')[0] }))} placeholder="All programs" aria-label="Program" className="!w-40" />
          <Select inputSize="sm" value={counsellor} onChange={(e) => setCounsellor(e.target.value)} options={users} placeholder="All counsellors" aria-label="Counsellor" className="!w-44" />
          <div className="rounded-lg border border-slate-200 px-2.5 py-1 dark:border-slate-700">
            <Toggle checked={showClosed} onChange={setShowClosed} label={<span className="text-xs">Closed stages</span>} />
          </div>
          <IconButton icon={RefreshCw} label="Refresh board" onClick={() => refetch()} className={clsx(isFetching && '[&_svg]:animate-spin')} />
        </div>
      </div>

      {error ? (
        <div className="p-6 text-sm text-red-600">{(error as ApiError).message}</div>
      ) : (
        <div className="flex snap-x gap-3 overflow-x-auto p-4 pb-5" role="list" aria-label="Admission pipeline board">
          {(isLoading ? [...PIPELINE, ...(showClosed ? CLOSED_STAGES : [])].map((s) => ({ stage: s.key, label: s.label, count: 0, cards: [] as BoardCard[] })) : columns).map((col) => {
            const tone = toneClasses[STAGE_TONE[col.stage] ?? 'slate'];
            const droppable = allowedDrop(col.stage);
            const dimmed = !!dragging && !droppable && dragging.stage !== col.stage;
            return (
              <section
                key={col.stage}
                role="listitem"
                aria-label={`${col.label}: ${col.count} applications`}
                onDragOver={(e) => {
                  if (droppable) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (over !== col.stage) setOver(col.stage);
                  }
                }}
                onDragLeave={(e) => {
                  if (!(e.currentTarget as HTMLElement).contains(e.relatedTarget as Node)) setOver((o) => (o === col.stage ? null : o));
                }}
                onDrop={(e) => onDrop(e, col.stage)}
                className={clsx(
                  'flex w-[272px] shrink-0 snap-start flex-col rounded-2xl border bg-slate-50/80 transition duration-200 dark:bg-slate-800/40',
                  over === col.stage ? 'border-brand-400 bg-brand-50/70 ring-2 ring-brand-500/20 dark:bg-brand-500/10' : 'border-slate-200/70 dark:border-slate-800',
                  dimmed && 'opacity-50',
                )}
              >
                <header className="flex items-center gap-2 px-3 pb-2 pt-3">
                  <span className={clsx('h-2.5 w-2.5 rounded-full', tone.bar)} aria-hidden />
                  <h3 className="min-w-0 flex-1 truncate text-[13px] font-semibold text-slate-800 dark:text-slate-100">{col.label}</h3>
                  {isLoading ? <Skeleton className="h-5 w-7" /> : <span className={clsx('rounded-full px-2 py-0.5 text-[11px] font-bold', tone.icon)}>{col.count}</span>}
                </header>
                <div className="flex max-h-[560px] min-h-[120px] flex-col gap-2.5 overflow-y-auto px-2.5 pb-3">
                  {isLoading &&
                    [0, 1, 2].map((i) => (
                      <div key={i} className="space-y-2 rounded-xl border border-slate-200/70 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                        <Skeleton className="h-4 w-2/3" />
                        <Skeleton className="h-3 w-1/2" />
                        <Skeleton className="h-3 w-full" />
                      </div>
                    ))}
                  {!isLoading && col.cards.length === 0 && (
                    <div className={clsx('flex flex-1 items-center justify-center rounded-xl border-2 border-dashed px-3 py-8 text-center text-xs', droppable ? 'border-brand-300 text-brand-700 dark:text-brand-300' : 'border-slate-200 text-slate-400 dark:border-slate-700')}>
                      {droppable ? 'Drop here' : 'No applications'}
                    </div>
                  )}
                  {col.cards.map((card) => (
                    <KanbanCard
                      key={card.id}
                      card={card}
                      canEdit={canEdit}
                      canApprove={!!data?.can.approve}
                      busy={moving === card.id}
                      dragging={dragging?.id === card.id}
                      onDragStart={onDragStart}
                      onDragEnd={() => {
                        setDragging(null);
                        setOver(null);
                      }}
                      onMove={move}
                      onFollowup={() => setFollowup({ kind: 'admission', id: card.id, name: card.name, sub: card.application_no })}
                    />
                  ))}
                  {!isLoading && col.cards.length < col.count && (
                    <Button variant="ghost" size="xs" className="w-full" loading={loadingMore === col.stage} onClick={() => loadMore(col)}>
                      Load {Math.min(20, col.count - col.cards.length)} more
                    </Button>
                  )}
                </div>
              </section>
            );
          })}
        </div>
      )}
      <StageMoveModal request={moveReq} onClose={() => setMoveReq(null)} />
      <FollowupModal open={!!followup} target={followup} onClose={() => setFollowup(null)} />
    </div>
  );
}

interface CardProps {
  card: BoardCard;
  canEdit: boolean;
  canApprove: boolean;
  busy: boolean;
  dragging: boolean;
  onDragStart: (e: DragEvent, c: BoardCard) => void;
  onDragEnd: () => void;
  onMove: (c: BoardCard, to: string) => void;
  onFollowup: () => void;
}

function KanbanCard({ card, canEdit, canApprove, busy, dragging, onDragStart, onDragEnd, onMove, onFollowup }: CardProps) {
  const next = nextOf(card.stage);
  const prev = prevOf(card.stage);
  const locked = card.converted;
  const allowed = transitionsFrom(card.stage);
  const needsApprove = (to: string) => (to === 'fee_payment' && (card.stage === 'approval' || card.stage === 'waitlisted')) || (['rejected', 'waitlisted'].includes(to) && ['approval', 'waitlisted', 'fee_payment'].includes(card.stage));
  const feeDue = card.admission_fee ?? 0;
  const feePct = feeDue > 0 ? Math.min(100, (card.fee_paid / feeDue) * 100) : 0;
  const menu = [
    { label: 'View profile', icon: Eye, to: `/admissions/${card.id}` },
    canEdit && { label: 'Log follow-up', icon: PhoneCall, onClick: onFollowup },
    canEdit && !locked && allowed.includes('waitlisted') && canApprove && { label: 'Waitlist', icon: Hourglass, onClick: () => onMove(card, 'waitlisted') },
    canEdit && !locked && allowed.includes('withdrawn') && { divider: true, label: 'd' },
    canEdit && !locked && allowed.includes('withdrawn') && { label: 'Mark withdrawn', icon: UserX, onClick: () => onMove(card, 'withdrawn') },
    canEdit && !locked && allowed.includes('rejected') && (!needsApprove('rejected') || canApprove) && { label: 'Reject', icon: Ban, danger: true, onClick: () => onMove(card, 'rejected') },
  ];
  return (
    <article
      draggable={canEdit && !locked && !busy}
      onDragStart={(e) => onDragStart(e, card)}
      onDragEnd={onDragEnd}
      aria-busy={busy || undefined}
      className={clsx(
        'group relative rounded-xl border border-slate-200/80 bg-white p-3 shadow-sm transition duration-200 dark:border-slate-700/70 dark:bg-slate-900',
        canEdit && !locked && 'cursor-grab active:cursor-grabbing',
        'hover:-translate-y-0.5 hover:shadow-card',
        (busy || dragging) && 'opacity-50',
      )}
    >
      <div className="flex items-start gap-2.5">
        <Avatar name={card.name} src={card.photo} size="sm" />
        <div className="min-w-0 flex-1 leading-tight">
          <Link to={`/admissions/${card.id}`} className="block truncate text-[13px] font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
            {card.name}
          </Link>
          <p className="truncate font-mono text-[10.5px] text-slate-500 dark:text-slate-400">{card.application_no}</p>
        </div>
        {canEdit && !locked && <GripVertical className="mt-0.5 h-4 w-4 shrink-0 text-slate-300 opacity-0 transition group-hover:opacity-100 dark:text-slate-600" aria-hidden />}
        <Dropdown label={`Actions for ${card.name}`} triggerClassName="btn-icon -mr-1 -mt-1 !h-7 !w-7 !rounded-lg" trigger={<MoreHorizontal className="h-4 w-4" />} items={menu} width="w-48" />
      </div>
      <div className="mt-2 flex flex-wrap items-center gap-1.5">
        <span className="badge badge-navy !px-2 !text-[11px]">{card.program}</span>
        <span className="badge badge-slate !px-2 !text-[11px]">{sourceLabel(card.source)}</span>
        {card.converted && <span className="badge badge-green !px-2 !text-[11px]">Enrolled</span>}
      </div>
      <dl className="mt-2.5 grid grid-cols-3 gap-1 text-[11px] text-slate-500 dark:text-slate-400">
        <div className="flex items-center gap-1" title="Documents verified">
          <FileCheck2 className={clsx('h-3.5 w-3.5', card.docs_count && card.docs_verified === card.docs_count ? 'text-emerald-600' : '')} aria-hidden />
          <dt className="sr-only">Documents</dt>
          <dd>{card.docs_verified}/{card.docs_count}</dd>
        </div>
        <div className="flex items-center gap-1" title={card.score !== null ? 'Entrance / interview score' : 'Qualifying exam %'}>
          <Award className="h-3.5 w-3.5" aria-hidden />
          <dt className="sr-only">Score</dt>
          <dd>{card.score !== null ? card.score : card.percentage !== null ? `${card.percentage}%` : '—'}</dd>
        </div>
        <div className={clsx('flex items-center gap-1', card.days_in_stage > 14 && !['confirmed', 'rejected', 'withdrawn'].includes(card.stage) && 'text-amber-600 dark:text-amber-400')} title="Days in this stage">
          <Clock className="h-3.5 w-3.5" aria-hidden />
          <dt className="sr-only">Days in stage</dt>
          <dd>{card.days_in_stage}d</dd>
        </div>
      </dl>
      {(card.stage === 'fee_payment' || card.stage === 'confirmed') && feeDue > 0 && (
        <div className="mt-2">
          <div className="flex justify-between text-[10.5px] text-slate-500">
            <span>Fee {formatMoney(card.fee_paid)} / {formatMoney(feeDue)}</span>
            {feePct >= 100 && <BadgeCheck className="h-3.5 w-3.5 text-emerald-600" aria-label="Fee paid" />}
          </div>
          <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
            <div className={clsx('h-full rounded-full transition-all', feePct >= 100 ? 'bg-emerald-500' : 'bg-amber-500')} style={{ width: `${feePct}%` }} />
          </div>
        </div>
      )}
      {card.stage === 'rejected' && card.rejection_reason && <p className="mt-2 line-clamp-2 text-[11px] text-red-600 dark:text-red-400">{card.rejection_reason}</p>}
      <div className="mt-2.5 flex items-center gap-2 border-t border-slate-100 pt-2 dark:border-slate-800">
        <div className="flex min-w-0 flex-1 items-center gap-1.5">
          <Avatar name={card.counsellor ?? '?'} src={card.counsellor_avatar} size="xs" />
          <span className="truncate text-[11px] text-slate-500 dark:text-slate-400">{card.counsellor ? card.counsellor.split(' ')[0] : 'Unassigned'}</span>
        </div>
        {!['confirmed', 'rejected', 'withdrawn'].includes(card.stage) && <DueChip date={card.next_followup} className="!text-[10px]" />}
        {canEdit && !locked && (
          <div className="flex shrink-0 items-center">
            {prev && allowed.includes(prev) && (
              <button type="button" onClick={() => onMove(card, prev)} disabled={busy} className="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-40 dark:hover:bg-slate-800 dark:hover:text-slate-200" title={`Back to ${stageLabel(prev)}`} aria-label={`Move ${card.name} back to ${stageLabel(prev)}`}>
                <ChevronLeft className="h-4 w-4" />
              </button>
            )}
            {next && allowed.includes(next) && (!needsApprove(next) || canApprove) && (
              <button type="button" onClick={() => onMove(card, next)} disabled={busy} className="rounded-md p-1 text-brand-700 transition hover:bg-brand-50 disabled:opacity-40 dark:text-brand-300 dark:hover:bg-brand-500/15" title={actionLabel(card.stage, next)} aria-label={`${actionLabel(card.stage, next)}: ${card.name}`}>
                {card.stage === 'rejected' || card.stage === 'withdrawn' ? <Undo2 className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
              </button>
            )}
          </div>
        )}
      </div>
    </article>
  );
}
