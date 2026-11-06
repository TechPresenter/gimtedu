import { useEffect, useMemo, useState, type DragEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  ArrowRightLeft, CalendarClock, CheckCircle2, Columns3, Eye, GripVertical, KanbanSquare, List, MessageCircleMore, MoreHorizontal, NotebookPen, Percent, Send,
  ShieldCheck, Sparkles, TrendingUp, UserCheck, UserPlus, Users, UsersRound,
} from 'lucide-react';
import {
  Alert, Avatar, Button, Card, CardHeader, DataTable, Dropdown, EmptyState, Input, PageHeader, Pagination, PersonCell, ProgressBar, Reveal, SearchInput, Select,
  Skeleton, Stagger, StatCard, useToast, type Column, type SortState,
} from '@/components/ui';
import { api, type Query } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber, formatPercent, timeAgo } from '@/lib/format';
import { useDebounce, useLocalStorage } from '@/lib/hooks';
import { crmKeys, showPhone, stageInfo, useCrmRefresh, useFacets, type Paged, type WaContact } from '../contacts/crm';

/** True on touch-first devices (phones, tablets), where HTML5 drag and drop does not work. */
function useCoarsePointer(): boolean {
  const query = '(pointer: coarse)';
  const [coarse, setCoarse] = useState(() => typeof window !== 'undefined' && !!window.matchMedia?.(query).matches);
  useEffect(() => {
    const mq = window.matchMedia?.(query);
    if (!mq) return;
    const onChange = () => setCoarse(mq.matches);
    mq.addEventListener?.('change', onChange);
    return () => mq.removeEventListener?.('change', onChange);
  }, []);
  return coarse;
}
import { AssignDialog, ContactFormModal, LastMessage, NoteDialog, SendTemplateModal, StageBadge, TagChips } from '../contacts/crmUi';

interface BoardColumn {
  key: string;
  name: string;
  color: string;
  count: number;
  cards: WaContact[];
}
interface Board {
  columns: BoardColumn[];
  total: number;
}
interface LeadStats {
  total: number;
  confirmed: number;
  lost: number;
  open: number;
  new_7d: number;
  followups_due: number;
  conversion_rate: number;
  counts: Record<string, number>;
  funnel: { key: string; name: string; count: number; reached: number }[];
  by_source: { source: string; label: string; total: number; confirmed: number }[];
  by_counsellor: { id: number | null; name: string; total: number; confirmed: number }[];
}

const CRUMBS = [{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Admission Leads' }];
const FILTERS = ['q', 'program_id', 'counsellor_id', 'lead_source', 'from', 'to'] as const;

export default function LeadsPage() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const toast = useToast();
  const qc = useQueryClient();
  const refresh = useCrmRefresh();
  const [params, setParams] = useSearchParams();
  const [savedView, setSavedView] = useLocalStorage<'board' | 'list'>('wa-leads-view', 'board');
  const view = (params.get('view') as 'board' | 'list' | null) ?? savedView;
  const { data: facets } = useFacets();
  const [q, setQ] = useState(params.get('q') ?? '');
  const dq = useDebounce(q, 350);
  const [showAdd, setShowAdd] = useState(false);
  const [action, setAction] = useState<null | { type: 'assign' | 'note' | 'template'; contact: WaContact }>(null);

  const update = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null || v === '' ? next.delete(k) : next.set(k, v)));
    if (!('page' in patch)) next.delete('page');
    setParams(next, { replace: true });
  };
  useEffect(() => {
    if ((params.get('q') ?? '') !== dq) update({ q: dq || null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dq]);
  const setView = (v: 'board' | 'list') => {
    setSavedView(v);
    update({ view: v });
  };

  const filters = useMemo(() => {
    const f: Record<string, string> = {};
    FILTERS.forEach((k) => {
      const v = params.get(k);
      if (v) f[k] = v;
    });
    return f;
  }, [params]);
  const stats = useQuery({ queryKey: crmKeys.leadStats(filters), queryFn: ({ signal }) => api.get<LeadStats>('whatsapp/leads/stats', filters, { signal }), placeholderData: keepPreviousData });
  const s = stats.data;

  const moveStage = async (contact: WaContact, to: string) => {
    if (contact.stage === to) return;
    const key = crmKeys.board(filters);
    const prev = qc.getQueryData<Board>(key);
    if (prev) {
      qc.setQueryData<Board>(key, {
        ...prev,
        columns: prev.columns.map((col) => {
          if (col.key === contact.stage) return { ...col, count: col.count - 1, cards: col.cards.filter((x) => x.id !== contact.id) };
          if (col.key === to) return { ...col, count: col.count + 1, cards: [{ ...contact, stage: to, stage_changed_at: new Date().toISOString().slice(0, 19).replace('T', ' ') }, ...col.cards] };
          return col;
        }),
      });
    }
    try {
      const r = await api.post(`whatsapp/contacts/${contact.id}/stage`, { stage: to, source: 'board' });
      toast.success(`${contact.name ?? showPhone(contact.phone)}: ${r.message}`);
    } catch (e) {
      if (prev) qc.setQueryData(key, prev);
      toast.error((e as Error).message);
    } finally {
      refresh();
    }
  };

  const optList = (list?: { value: string | number; label: string }[]) => (list ?? []).map((o) => ({ value: String(o.value), label: o.label }));
  const activeFilters = FILTERS.filter((k) => k !== 'q' && params.get(k)).length;

  return (
    <>
      <PageHeader
        title="Admission Leads"
        description="Every enquiry and applicant on one board — drag a card to another stage (on a phone or tablet, use “Move to stage” on the card); follow-ups for the new stage are scheduled automatically."
        breadcrumbs={CRUMBS}
        actions={
          <>
            <div className="inline-flex rounded-xl border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-900" role="group" aria-label="View">
              <button type="button" onClick={() => setView('board')} aria-pressed={view === 'board'} className={clsx('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition', view === 'board' ? 'bg-brand-800 text-white shadow-sm dark:bg-brand-600' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}>
                <KanbanSquare className="h-4 w-4" /> Board
              </button>
              <button type="button" onClick={() => setView('list')} aria-pressed={view === 'list'} className={clsx('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition', view === 'list' ? 'bg-brand-800 text-white shadow-sm dark:bg-brand-600' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}>
                <List className="h-4 w-4" /> List
              </button>
            </div>
            <Button variant="secondary" icon={CalendarClock} to="/whatsapp/followups">Follow-ups</Button>
            {can('whatsapp_contacts', 'create') && <Button icon={UserPlus} onClick={() => setShowAdd(true)}>Add lead</Button>}
          </>
        }
      />

      {stats.error ? (
        <Alert variant="error" className="mb-6" title="Lead statistics could not be loaded" action={<Button size="sm" variant="secondary" onClick={() => stats.refetch()}>Retry</Button>}>{(stats.error as Error).message}</Alert>
      ) : (
        <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
          {[
            <StatCard key="t" label="Leads in pipeline" icon={UsersRound} tone="navy" loading={stats.isLoading} value={s?.total ?? 0} hint={s ? `${formatNumber(s.lost)} not interested` : undefined} />,
            <StatCard key="o" label="Open leads" icon={Users} tone="blue" loading={stats.isLoading} value={s?.open ?? 0} hint="Not confirmed or lost" />,
            <StatCard key="c" label="Admissions confirmed" icon={CheckCircle2} tone="green" loading={stats.isLoading} value={s?.confirmed ?? 0} />,
            <StatCard key="r" label="Conversion rate" icon={Percent} tone="orange" loading={stats.isLoading} value={s ? formatPercent(s.conversion_rate, 1) : '0%'} hint="Confirmed ÷ all leads" />,
            <StatCard key="f" label="Follow-ups due" icon={CalendarClock} tone="red" loading={stats.isLoading} value={s?.followups_due ?? 0} to="/whatsapp/followups" hint="Today and overdue" />,
            <StatCard key="n" label="New this week" icon={Sparkles} tone="purple" loading={stats.isLoading} value={s?.new_7d ?? 0} />,
          ]}
        </Stagger>
      )}

      <Card className="mb-4 p-3">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-center">
          <SearchInput value={q} onChange={setQ} placeholder="Search name, phone, application no…" className="lg:w-72" size="sm" />
          <Select inputSize="sm" aria-label="Programme" className="lg:!w-56" value={params.get('program_id') ?? ''} onChange={(e) => update({ program_id: e.target.value || null })} options={optList(facets?.programs)} placeholder="All programmes" />
          <Select inputSize="sm" aria-label="Counsellor" className="lg:!w-48" value={params.get('counsellor_id') ?? ''} onChange={(e) => update({ counsellor_id: e.target.value || null })} options={[{ value: 'me', label: 'My leads' }, { value: 'none', label: 'Unassigned' }, ...optList(facets?.counsellors)]} placeholder="All counsellors" />
          <Select inputSize="sm" aria-label="Lead source" className="lg:!w-40" value={params.get('lead_source') ?? ''} onChange={(e) => update({ lead_source: e.target.value || null })} options={optList(facets?.lead_sources)} placeholder="All sources" />
          <div className="flex items-center gap-2">
            <Input inputSize="sm" type="date" aria-label="Added from" value={params.get('from') ?? ''} onChange={(e) => update({ from: e.target.value || null })} className="lg:!w-40" />
            <span className="text-xs text-slate-400">to</span>
            <Input inputSize="sm" type="date" aria-label="Added to" value={params.get('to') ?? ''} onChange={(e) => update({ to: e.target.value || null })} className="lg:!w-40" />
          </div>
          {(activeFilters > 0 || params.get('q')) && (
            <Button size="sm" variant="ghost" onClick={() => { setQ(''); const next = new URLSearchParams(); if (params.get('view')) next.set('view', params.get('view')!); setParams(next, { replace: true }); }}>
              Clear
            </Button>
          )}
        </div>
      </Card>

      {view === 'board' ? (
        <LeadsBoard filters={filters} onMove={moveStage} onAction={(type, contact) => setAction({ type, contact })} />
      ) : (
        <LeadsList filters={filters} onAction={(type, contact) => setAction({ type, contact })} onMove={moveStage} />
      )}

      <div className="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Admission funnel" subtitle="Leads that reached each stage (current stage or beyond)" icon={TrendingUp} />
            <div className="card-body space-y-3">
              {stats.isLoading || !s ? (
                Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-6 w-full" />)
              ) : !s.total ? (
                <EmptyState icon={TrendingUp} title="No leads match the filters" className="!py-6" />
              ) : (
                s.funnel.map((f, i) => {
                  const pct = s.total ? (f.reached * 100) / s.total : 0;
                  const prev = i > 0 ? s.funnel[i - 1].reached : f.reached;
                  const step = prev ? (f.reached * 100) / prev : 0;
                  return (
                    <div key={f.key} className="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 text-sm sm:grid-cols-[12rem_1fr_7rem]">
                      <span className="truncate text-slate-700 dark:text-slate-200">{f.name}</span>
                      <div className="relative h-7 overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-800">
                        <div className="h-full rounded-lg transition-all duration-500" style={{ width: `${Math.max(pct, 2)}%`, background: stageInfo(f.key, facets).hex, opacity: 0.85 }} />
                        <span className="absolute inset-y-0 left-2 flex items-center text-xs font-semibold text-white mix-blend-normal drop-shadow">{formatNumber(f.reached)}</span>
                      </div>
                      <span className="text-right text-xs text-slate-500 dark:text-slate-400">
                        {formatPercent(pct)}{i > 0 && <span className="hidden sm:inline"> · {formatPercent(step)} step</span>}
                      </span>
                    </div>
                  );
                })
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Sources & counsellors" subtitle="Leads and confirmed admissions" icon={Columns3} />
            <div className="card-body space-y-5">
              {stats.isLoading || !s ? (
                <Skeleton className="h-40 w-full" />
              ) : (
                <>
                  <div className="space-y-2.5">
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">By source</p>
                    {s.by_source.slice(0, 6).map((r) => (
                      <div key={r.source}>
                        <div className="mb-1 flex justify-between text-xs"><span className="text-slate-700 dark:text-slate-200">{r.label}</span><span className="text-slate-500">{formatNumber(r.total)} · {formatNumber(r.confirmed)} confirmed</span></div>
                        <ProgressBar value={s.total ? (r.total * 100) / s.total : 0} tone="orange" label={r.label} />
                      </div>
                    ))}
                  </div>
                  <div className="space-y-2">
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">By counsellor</p>
                    {s.by_counsellor.slice(0, 6).map((r) => (
                      <div key={r.name} className="flex items-center gap-2 text-sm">
                        <Avatar name={r.name} size="xs" />
                        <span className="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-200">{r.name}</span>
                        <span className="text-xs text-slate-500">{formatNumber(r.total)}</span>
                        <span className="w-14 text-right text-xs font-semibold text-emerald-700 dark:text-emerald-300">{r.total ? formatPercent((r.confirmed * 100) / r.total) : '0%'}</span>
                      </div>
                    ))}
                  </div>
                </>
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <ContactFormModal open={showAdd} onClose={() => setShowAdd(false)} onSaved={(id) => navigate(`/whatsapp/contacts/${id}`)} offerEnquiry />
      <AssignDialog open={action?.type === 'assign'} onClose={() => setAction(null)} onSubmit={async (uid) => {
        const r = await api.post(`whatsapp/contacts/${action!.contact.id}/assign`, { counsellor_id: uid });
        toast.success(r.message);
        refresh();
      }} />
      {action?.type === 'note' && <NoteDialog open onClose={() => setAction(null)} contactId={action.contact.id} />}
      {action?.type === 'template' && <SendTemplateModal open onClose={() => setAction(null)} contactId={action.contact.id} contactName={action.contact.name} />}
    </>
  );
}

type ActionFn = (type: 'assign' | 'note' | 'template', c: WaContact) => void;

/* ================================================================================================ board */

function LeadsBoard({ filters, onMove, onAction }: { filters: Record<string, string>; onMove: (c: WaContact, to: string) => void; onAction: ActionFn }) {
  const { can } = useAuth();
  const { data: facets } = useFacets();
  const board = useQuery({ queryKey: crmKeys.board(filters), queryFn: ({ signal }) => api.get<Board>('whatsapp/leads/board', filters, { signal }), placeholderData: keepPreviousData });
  const [dragging, setDragging] = useState<WaContact | null>(null);
  const [over, setOver] = useState<string | null>(null);
  const canMove = can('whatsapp_contacts', 'edit');
  const touch = useCoarsePointer();
  const [params] = useSearchParams();
  const allLink = (stage: string) => {
    const next = new URLSearchParams(params);
    next.set('view', 'list');
    next.set('stage', stage);
    next.delete('page');
    return next.toString();
  };

  if (board.error) {
    return <Alert variant="error" title="The board could not be loaded" action={<Button size="sm" variant="secondary" onClick={() => board.refetch()}>Retry</Button>}>{(board.error as Error).message}</Alert>;
  }
  const onDrop = (e: DragEvent, stage: string) => {
    e.preventDefault();
    setOver(null);
    if (dragging) onMove(dragging, stage);
    setDragging(null);
  };
  return (
    <>
    {canMove && (
      <p className={clsx('mb-2 items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400', touch ? 'flex' : 'flex md:hidden')}>
        <ArrowRightLeft className="h-3.5 w-3.5 shrink-0 text-accent-600" aria-hidden />
        Swipe sideways through the stages; change a lead’s stage with “Move to stage” on its card.
      </p>
    )}
    <div className="-mx-4 overflow-x-auto px-4 pb-3 sm:mx-0 sm:px-0" role="region" aria-label="Admission leads board" tabIndex={0}>
      <div className="flex min-w-max gap-3">
        {(board.data?.columns ?? Array.from({ length: 9 }).map((_, i) => ({ key: `sk${i}`, name: '', color: 'slate', count: 0, cards: [] as WaContact[] }))).map((col) => {
          const st = stageInfo(col.key, facets);
          return (
            <section
              key={col.key}
              aria-label={col.name}
              className={clsx('flex w-[17rem] shrink-0 flex-col rounded-2xl border bg-slate-50/80 transition dark:bg-slate-900/60', over === col.key ? 'border-accent-500 ring-2 ring-accent-500/30' : 'border-slate-200 dark:border-slate-800')}
              onDragOver={(e) => {
                if (!dragging || !canMove) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                if (over !== col.key) setOver(col.key);
              }}
              onDragLeave={(e) => {
                if (!(e.currentTarget as HTMLElement).contains(e.relatedTarget as Node)) setOver(null);
              }}
              onDrop={(e) => onDrop(e, col.key)}
            >
              <header className="flex items-center gap-2 border-b border-slate-200/80 px-3 py-2.5 dark:border-slate-800">
                <span className="h-2.5 w-2.5 rounded-full" style={{ background: st.hex }} aria-hidden />
                {board.isLoading ? <Skeleton className="h-4 w-28" /> : <h2 className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{col.name}</h2>}
                <span className="ml-auto rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-slate-600 shadow-sm dark:bg-slate-800 dark:text-slate-300">{formatNumber(col.count)}</span>
              </header>
              <div className="flex max-h-[34rem] min-h-[8rem] flex-col gap-2 overflow-y-auto p-2">
                {board.isLoading ? (
                  [0, 1, 2].map((i) => <Skeleton key={i} className="h-24 w-full rounded-xl" />)
                ) : col.cards.length === 0 ? (
                  <p className="px-2 py-6 text-center text-xs text-slate-400">{dragging ? 'Drop here' : 'No leads'}</p>
                ) : (
                  col.cards.map((c) => (
                    <LeadCard key={c.id} c={c} canMove={canMove} touch={touch} dragging={dragging?.id === c.id} onDragStart={() => setDragging(c)} onDragEnd={() => { setDragging(null); setOver(null); }} onMove={onMove} onAction={onAction} />
                  ))
                )}
                {!board.isLoading && col.count > col.cards.length && (
                  <Link to={`?${allLink(col.key)}`} className="rounded-lg px-2 py-2 text-center text-xs font-semibold text-accent-600 hover:bg-white dark:text-accent-400 dark:hover:bg-slate-800">
                    View all {formatNumber(col.count)} →
                  </Link>
                )}
              </div>
            </section>
          );
        })}
      </div>
    </div>
    </>
  );
}

function LeadCard({ c, canMove, touch, dragging, onDragStart, onDragEnd, onMove, onAction }: { c: WaContact; canMove: boolean; touch: boolean; dragging: boolean; onDragStart: () => void; onDragEnd: () => void; onMove: (c: WaContact, to: string) => void; onAction: ActionFn }) {
  const { can } = useAuth();
  const { data: facets } = useFacets();
  const overdue = !!c.next_followup_at && new Date(c.next_followup_at.replace(' ', 'T')) < new Date();
  const label = c.name || showPhone(c.phone);
  return (
    <article
      draggable={canMove && !touch}
      onDragStart={(e) => {
        e.dataTransfer.setData('text/plain', String(c.id));
        e.dataTransfer.effectAllowed = 'move';
        onDragStart();
      }}
      onDragEnd={onDragEnd}
      className={clsx('group rounded-xl border border-slate-200 bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:shadow-soft dark:border-slate-700 dark:bg-slate-800', canMove && !touch && 'cursor-grab active:cursor-grabbing', dragging && 'opacity-40')}
    >
      <div className="flex items-start gap-2">
        {canMove && !touch && <GripVertical className="mt-0.5 hidden h-4 w-4 shrink-0 text-slate-300 group-hover:text-slate-400 dark:text-slate-600 md:block" aria-hidden />}
        <div className="min-w-0 flex-1">
          <Link to={`/whatsapp/contacts/${c.id}`} className="block truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300" draggable={false}>
            {label}
          </Link>
          <p className="truncate text-xs text-slate-500 dark:text-slate-400">{c.program_code || c.program_name || 'Programme not set'}{c.application_no ? ` · ${c.application_no}` : ''}</p>
        </div>
        <Dropdown
          label={`Actions for ${c.name ?? c.phone}`}
          width="w-60"
          triggerClassName="inline-flex h-7 w-7 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-slate-200" trigger={<MoreHorizontal className="h-4 w-4" />}
          items={[
            { label: 'Open profile', icon: Eye, to: `/whatsapp/contacts/${c.id}` },
            can('whatsapp', 'view') && { label: 'Message on WhatsApp', icon: MessageCircleMore, to: `/whatsapp/inbox?contact=${c.id}` },
            can('whatsapp', 'create') && { label: 'Send template', icon: Send, onClick: () => onAction('template', c) },
            can('whatsapp_contacts', 'edit') && { label: 'Assign counsellor', icon: UserCheck, onClick: () => onAction('assign', c), divider: true },
            can('whatsapp_contacts', 'edit') && { label: 'Add note', icon: NotebookPen, onClick: () => onAction('note', c) },
            ...(can('whatsapp_contacts', 'edit')
              ? (facets?.stages ?? []).filter((s) => s.value !== c.stage).map((s, i) => ({ label: `Move to ${s.label}`, icon: ArrowRightLeft, onClick: () => onMove(c, String(s.value)), divider: i === 0 }))
              : []),
          ]}
        />
      </div>
      <TagChips tags={c.tags} max={2} className="mt-2" />
      <div className="mt-2.5 flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
        {c.counsellor_name ? (
          <span className="inline-flex min-w-0 items-center gap-1" title={`Counsellor: ${c.counsellor_name}`}>
            <Avatar name={c.counsellor_name} size="xs" />
            <span className="truncate">{c.counsellor_name.split(' ')[0]}</span>
          </span>
        ) : (
          <span className="text-amber-600 dark:text-amber-400">Unassigned</span>
        )}
        {c.opt_in ? <ShieldCheck className="h-3.5 w-3.5 text-emerald-600" aria-label="Opted in" /> : null}
        <span className="ml-auto" title={c.last_message_at ? `Last message ${formatDateTime(c.last_message_at)}` : 'No messages yet'}>
          <MessageCircleMore className="mr-0.5 inline h-3 w-3" aria-hidden />
          {c.last_message_at ? timeAgo(c.last_message_at).replace(' ago', '') : '—'}
        </span>
      </div>
      {c.next_followup_at && (
        <p className={clsx('mt-2 flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-medium', overdue ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300')}>
          <CalendarClock className="h-3 w-3" aria-hidden />
          {overdue ? 'Overdue · ' : 'Follow-up · '}
          {formatDateTime(c.next_followup_at)}
        </p>
      )}
      {canMove && (
        // drag and drop needs a mouse: phones and tablets (and keyboard users on small screens) move cards from here
        <div className={clsx('mt-2.5 items-center gap-2 border-t border-slate-100 pt-2.5 dark:border-slate-700', touch ? 'flex' : 'flex md:hidden')}>
          <ArrowRightLeft className="h-3.5 w-3.5 shrink-0 text-accent-600" aria-hidden />
          <Select
            inputSize="sm"
            aria-label={`Move ${label} to another stage`}
            className="min-w-0 flex-1 !py-1.5 text-xs font-semibold"
            value=""
            onChange={(e) => e.target.value && onMove(c, e.target.value)}
            options={(facets?.stages ?? []).filter((s) => s.value !== c.stage).map((s) => ({ value: String(s.value), label: String(s.label) }))}
            placeholder="Move to stage…"
          />
        </div>
      )}
    </article>
  );
}

/* ================================================================================================ list */

function LeadsList({ filters, onAction, onMove }: { filters: Record<string, string>; onAction: ActionFn; onMove: (c: WaContact, to: string) => void }) {
  const { can } = useAuth();
  const navigate = useNavigate();
  const { data: facets } = useFacets();
  const [params, setParams] = useSearchParams();
  const page = Number(params.get('page') ?? 1) || 1;
  const [perPage, setPerPage] = useState(25);
  const [sort, setSort] = useState<SortState>({ key: 'stage_changed_at', dir: 'desc' });
  const [stage, setStage] = useState(params.get('stage') ?? '');
  const query: Query = {
    scope: 'leads', q: filters.q, program_id: filters.program_id, counsellor_id: filters.counsellor_id, lead_source: filters.lead_source,
    created_from: filters.from, created_to: filters.to, stage: stage || undefined, page, per_page: perPage, sort: sort.key, dir: sort.dir,
  };
  const list = useQuery({ queryKey: crmKeys.list(query), queryFn: ({ signal }) => api.get<Paged<WaContact>>('whatsapp/contacts', query, { signal }), placeholderData: keepPreviousData });
  const setPage = (p: number) => {
    const next = new URLSearchParams(params);
    next.set('page', String(p));
    setParams(next, { replace: true });
  };
  const columns: Column<WaContact>[] = [
    { key: 'name', header: 'Lead', sortable: true, render: (r) => <PersonCell name={r.name || showPhone(r.phone)} sub={showPhone(r.phone)} to={`/whatsapp/contacts/${r.id}`} /> },
    { key: 'stage', header: 'Stage', sortable: true, render: (r) => <StageBadge stage={r.stage} facets={facets} /> },
    { key: 'program_name', header: 'Programme', sortable: true, render: (r) => r.program_code || r.program_name || <span className="text-slate-400">—</span> },
    { key: 'counsellor_name', header: 'Counsellor', sortable: true, render: (r) => r.counsellor_name ?? <span className="text-xs text-amber-600">Unassigned</span> },
    { key: 'lead_source', header: 'Source', render: (r) => (r.lead_source ? <span className="text-xs capitalize">{r.lead_source.replace(/_/g, ' ')}</span> : '—') },
    { key: 'tags', header: 'Tags', render: (r) => <TagChips tags={r.tags} max={2} /> },
    { key: 'last_message_at', header: 'Last message', sortable: true, render: (r) => <LastMessage at={r.last_message_at} /> },
    { key: 'next_followup_at', header: 'Next follow-up', render: (r) => (r.next_followup_at ? <span className={clsx('whitespace-nowrap text-xs', new Date(r.next_followup_at.replace(' ', 'T')) < new Date() && 'font-semibold text-red-600 dark:text-red-400')}>{formatDateTime(r.next_followup_at)}</span> : '—') },
    { key: 'stage_changed_at', header: 'In stage since', sortable: true, render: (r) => <span className="whitespace-nowrap text-xs">{r.stage_changed_at ? timeAgo(r.stage_changed_at) : '—'}</span> },
  ];
  return (
    <Card className="overflow-hidden">
      <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3 dark:border-slate-800">
        <Select inputSize="sm" aria-label="Stage" className="!w-56" value={stage} onChange={(e) => {
          setStage(e.target.value);
          const next = new URLSearchParams(params);
          if (e.target.value) next.set('stage', e.target.value);
          else next.delete('stage');
          next.delete('page');
          setParams(next, { replace: true });
        }} options={(facets?.stages ?? []).map((s) => ({ value: String(s.value), label: s.label }))} placeholder="All stages" />
        <span className="text-xs text-slate-500">{list.data ? `${formatNumber(list.data.meta.total)} leads` : ''}</span>
      </div>
      {list.error ? (
        <div className="p-4"><Alert variant="error" title="Leads could not be loaded">{(list.error as Error).message}</Alert></div>
      ) : (
        <DataTable<WaContact>
          caption="Admission leads"
          columns={columns}
          rows={list.data?.data ?? []}
          loading={list.isLoading || list.isFetching}
          sort={sort}
          onSort={setSort}
          onRowClick={(r) => navigate(`/whatsapp/contacts/${r.id}`)}
          empty={<EmptyState icon={UsersRound} title="No leads match" description="Change the filters, or sync enquiries from the admission portal on the Contacts page." action={<Button variant="secondary" to="/whatsapp/contacts">Go to contacts</Button>} />}
          actions={(r) => (
            <Dropdown
              label={`Actions for ${r.name ?? r.phone}`}
              triggerClassName="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" trigger={<MoreHorizontal className="h-4 w-4" />}
              items={[
                can('whatsapp', 'view') && { label: 'Message on WhatsApp', icon: MessageCircleMore, to: `/whatsapp/inbox?contact=${r.id}` },
                can('whatsapp', 'create') && { label: 'Send template', icon: Send, onClick: () => onAction('template', r) },
                can('whatsapp_contacts', 'edit') && { label: 'Assign counsellor', icon: UserCheck, onClick: () => onAction('assign', r) },
                can('whatsapp_contacts', 'edit') && { label: 'Add note', icon: NotebookPen, onClick: () => onAction('note', r) },
                ...(can('whatsapp_contacts', 'edit') ? (facets?.stages ?? []).filter((s) => s.value !== r.stage).map((s, i) => ({ label: `Move to ${s.label}`, icon: ArrowRightLeft, onClick: () => onMove(r, String(s.value)), divider: i === 0 })) : []),
              ]}
            />
          )}
        />
      )}
      {(list.data?.meta.total ?? 0) > 0 && (
        <Pagination className="border-t border-slate-100 dark:border-slate-800" page={list.data!.meta.page} pages={list.data!.meta.pages} total={list.data!.meta.total} perPage={perPage} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} />
      )}
    </Card>
  );
}
