import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  AlarmClock, BarChart3, CalendarClock, CalendarDays, CheckCircle2, CircleSlash, Clock3, Hourglass, ListChecks, MoreHorizontal, Pencil, Play, Send, Settings2,
  SkipForward, UsersRound,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, CardHeader, Checkbox, Combobox, DataTable, Dropdown, EmptyState, Field, Input, Modal, PageHeader, Pagination, Reveal, SearchInput,
  Select, Skeleton, Stagger, StatCard, StatusBadge, Tabs, Textarea, Toggle, useToast, type Column,
} from '@/components/ui';
import { BarChart } from '@/components/charts';
import { api, type Query } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import type { BadgeColor } from '@/lib/status';
import { crmKeys, showPhone, stageInfo, useCrmRefresh, useFacets, type Paged } from '../contacts/crm';
import { DialogShell, StageBadge, fieldErrors } from '../contacts/crmUi';

interface StopConditions {
  on_reply: boolean;
  on_stage_change: boolean;
  on_opt_out: boolean;
  on_admission_confirmed: boolean;
}
interface StageRule {
  id: number | null;
  stage_key: string;
  name: string;
  color: BadgeColor;
  sort_order: number;
  template_id: number | null;
  template_name: string | null;
  delay_hours: number;
  max_followups: number;
  counsellor_id: number | null;
  counsellor_name: string | null;
  next_action: string | null;
  stop_conditions: StopConditions;
  variable_defaults: Record<string, string>;
  is_active: number;
  is_terminal: number;
  contacts: number;
  stats: { scheduled: number; overdue: number; due_today: number; sent_30d: number; skipped_30d: number; replied_30d: number };
}
interface Counts {
  due_today: number;
  overdue: number;
  upcoming: number;
  sent: number;
  skipped: number;
  all: number;
  sent_7d: number;
}
interface StagesResponse {
  stages: StageRule[];
  templates: { value: number; label: string; sub: string; category: string; status: string; body: string; variables: { key: string; label: string; sample: string }[] }[];
  auto_variables: string[];
  counsellors: { value: number; label: string; sub?: string | null }[];
  counts: Counts;
  business_hours: boolean;
  can_edit: boolean;
}
interface FollowupRow {
  id: number;
  contact_id: number;
  stage_key: string;
  stage_name: string;
  attempt: number;
  max_followups: number;
  template_name: string | null;
  template_display: string | null;
  template_category: string | null;
  due_at: string;
  status: string;
  note: string | null;
  message_status: string | null;
  contact_name: string | null;
  phone: string;
  contact_stage: string;
  opt_in: number;
  program_name: string | null;
  counsellor_name: string | null;
  overdue: boolean;
  updated_at: string | null;
}

const CRUMBS = [{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Follow-up Manager' }];

/** 'in 3 hours' / '2 days ago' for a due time. */
function relDue(v: string): string {
  const ms = new Date(v.replace(' ', 'T')).getTime() - Date.now();
  if (ms <= 0) return timeAgo(v);
  const mins = Math.round(ms / 60000);
  if (mins < 60) return `in ${Math.max(1, mins)} min`;
  const hours = Math.round(mins / 60);
  if (hours < 48) return `in ${hours} hour${hours > 1 ? 's' : ''}`;
  return `in ${Math.round(hours / 24)} days`;
}
const STOP_LABELS: Record<keyof StopConditions, string> = { on_reply: 'Reply', on_stage_change: 'Stage change', on_opt_out: 'Opt-out', on_admission_confirmed: 'Admission confirmed' };
const STOP_SHORT: Record<keyof StopConditions, string> = { on_reply: 'Reply', on_stage_change: 'Stage', on_opt_out: 'Opt-out', on_admission_confirmed: 'Admitted' };
const COLOR_OPTIONS: Record<string, string> = { blue: 'Blue', cyan: 'Cyan', purple: 'Purple', navy: 'Navy', amber: 'Amber', red: 'Red', green: 'Green', slate: 'Slate' };

export default function FollowupManagerPage() {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useCrmRefresh();
  const cfg = useQuery({ queryKey: crmKeys.followupStages, queryFn: ({ signal }) => api.get<StagesResponse>('whatsapp/followups/stages', undefined, { signal }) });
  const [editing, setEditing] = useState<StageRule | null>(null);
  const [running, setRunning] = useState(false);
  const counts = cfg.data?.counts;

  const runNow = async () => {
    setRunning(true);
    try {
      const r = await api.post('whatsapp/followups/run');
      toast.success(r.message);
      refresh();
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setRunning(false);
    }
  };
  const toggleActive = async (s: StageRule) => {
    try {
      const r = await api.put(`whatsapp/followups/stages/${s.stage_key}`, { ...s, is_active: s.is_active ? 0 : 1 });
      toast.success(r.message);
      refresh();
    } catch (e) {
      const errs = fieldErrors(e);
      toast.error(Object.values(errs)[0] ?? (e as Error).message);
      if (Object.keys(errs).length) setEditing(s);
    }
  };

  const chart = useMemo(() => {
    const st = (cfg.data?.stages ?? []).filter((s) => !s.is_terminal);
    return {
      labels: st.map((s) => s.name),
      series: [
        { label: 'Sent', data: st.map((s) => s.stats.sent_30d), color: '#10B981' },
        { label: 'Stopped on reply', data: st.map((s) => s.stats.replied_30d), color: '#2563EB' },
        { label: 'Skipped / failed', data: st.map((s) => s.stats.skipped_30d), color: '#F05A28' },
      ],
    };
  }, [cfg.data]);

  return (
    <>
      <PageHeader
        title="Follow-up Manager"
        description="Automatic WhatsApp follow-ups for each admission stage — sent in business hours, stopped when the student replies, changes stage or opts out."
        breadcrumbs={CRUMBS}
        actions={
          <>
            <Button variant="secondary" icon={UsersRound} to="/whatsapp/leads">Admission leads</Button>
            {can('whatsapp_contacts', 'edit') && <Button icon={Play} loading={running} onClick={runNow}>Send due follow-ups</Button>}
          </>
        }
      />
      {cfg.data && !cfg.data.business_hours && (
        <Alert variant="info" className="mb-4" title="Outside business hours">Due follow-ups wait for the next business-hours slot (Settings › WhatsApp › Business hours). “Send now” on a single follow-up sends immediately.</Alert>
      )}
      {cfg.error ? (
        <Alert variant="error" className="mb-6" title="Follow-up settings could not be loaded" action={<Button size="sm" variant="secondary" onClick={() => cfg.refetch()}>Retry</Button>}>{(cfg.error as Error).message}</Alert>
      ) : (
        <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5" step={50}>
          {[
            <StatCard key="d" label="Due today" icon={CalendarClock} tone="amber" loading={cfg.isLoading} value={counts?.due_today ?? 0} />,
            <StatCard key="o" label="Overdue" icon={AlarmClock} tone="red" loading={cfg.isLoading} value={counts?.overdue ?? 0} hint="Due before today" />,
            <StatCard key="u" label="Upcoming" icon={CalendarDays} tone="blue" loading={cfg.isLoading} value={counts?.upcoming ?? 0} />,
            <StatCard key="s" label="Sent (7 days)" icon={Send} tone="green" loading={cfg.isLoading} value={counts?.sent_7d ?? 0} hint={counts ? `${formatNumber(counts.sent)} in total` : undefined} />,
            <StatCard key="k" label="Skipped / stopped" icon={CircleSlash} tone="slate" loading={cfg.isLoading} value={counts?.skipped ?? 0} hint="Replies, opt-outs, stage changes" />,
          ]}
        </Stagger>
      )}

      <Reveal>
        <Card className="mb-6 overflow-hidden">
          <CardHeader title="Stage rules" subtitle="Template, timing, counsellor and stop conditions for each of the 9 admission stages" icon={Settings2} />
          {cfg.isLoading ? (
            <div className="space-y-2 p-5">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}</div>
          ) : (
            <div className="table-wrap">
              <table className="data-table text-sm">
                <thead>
                  <tr>
                    <th>Stage</th>
                    <th>WhatsApp template</th>
                    <th>Timing</th>
                    <th>Counsellor</th>
                    <th className="hidden 2xl:table-cell">Next action</th>
                    <th className="hidden 2xl:table-cell">Stops on</th>
                    <th className="text-right">Leads</th>
                    <th className="text-right">30 days</th>
                    <th>Active</th>
                    <th className="w-px" />
                  </tr>
                </thead>
                <tbody>
                  {(cfg.data?.stages ?? []).map((s) => (
                    <tr key={s.stage_key}>
                      <td className="whitespace-nowrap"><Badge color={s.color} dot>{s.name}</Badge></td>
                      <td className="max-w-[14rem]">
                        {s.is_terminal ? <span className="text-xs text-slate-400">Final stage — no follow-ups</span> : s.template_name ? <code className="truncate rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">{s.template_name}</code> : <span className="text-xs font-medium text-amber-600">No template</span>}
                        {s.next_action && <p className="mt-1 line-clamp-1 text-[11px] text-slate-500 dark:text-slate-400 2xl:hidden" title={s.next_action}>Next: {s.next_action}</p>}
                      </td>
                      <td className="whitespace-nowrap text-xs">{s.is_terminal || !s.max_followups ? '—' : `${s.max_followups} × every ${s.delay_hours} h`}</td>
                      <td className="whitespace-nowrap text-xs">{s.counsellor_name ?? <span className="text-slate-400">Keep owner</span>}</td>
                      <td className="hidden min-w-[9rem] max-w-[12rem] text-xs text-slate-600 dark:text-slate-300 2xl:table-cell"><span className="line-clamp-2" title={s.next_action ?? undefined}>{s.next_action ?? '—'}</span></td>
                      <td className="hidden min-w-[8.5rem] max-w-[10rem] text-[11px] leading-snug text-slate-600 dark:text-slate-300 2xl:table-cell">
                        {s.is_terminal ? '—' : (Object.keys(STOP_LABELS) as (keyof StopConditions)[]).filter((k) => s.stop_conditions[k]).map((k) => STOP_SHORT[k]).join(' · ') || 'Never'}
                      </td>
                      <td className="text-right font-semibold">{formatNumber(s.contacts)}</td>
                      <td className="whitespace-nowrap text-right text-xs">
                        <span className="text-emerald-700 dark:text-emerald-300" title="Sent">{s.stats.sent_30d}</span> · <span className="text-blue-700 dark:text-blue-300" title="Stopped because the contact replied">{s.stats.replied_30d}</span> · <span className="text-slate-500" title="Skipped / failed">{s.stats.skipped_30d}</span>
                      </td>
                      <td>
                        <Toggle checked={!!s.is_active} disabled={!cfg.data?.can_edit} onChange={() => toggleActive(s)} label={<span className="sr-only">Follow-ups active for {s.name}</span>} />
                      </td>
                      <td>
                        {cfg.data?.can_edit && <Button size="xs" variant="ghost" icon={Pencil} onClick={() => setEditing(s)} aria-label={`Edit ${s.name} rule`} />}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </Reveal>

      <FollowupQueue counts={counts} />

      <Reveal className="mt-6">
        <Card>
          <CardHeader title="Results per stage (last 30 days)" subtitle="Follow-ups sent, stopped because the student replied, and skipped" icon={BarChart3} />
          <div className="card-body">
            {cfg.isLoading ? <Skeleton className="h-64 w-full" /> : chart.series.every((x) => x.data.every((v) => !v)) ? (
              <EmptyState icon={BarChart3} title="No follow-ups in the last 30 days" className="!py-8" />
            ) : (
              <BarChart labels={chart.labels} series={chart.series} stacked legend height={280} ariaLabel="Follow-up results per stage" />
            )}
          </div>
        </Card>
      </Reveal>

      {editing && cfg.data && <StageRuleModal rule={editing} templates={cfg.data.templates} counsellors={cfg.data.counsellors} auto={cfg.data.auto_variables} onClose={() => setEditing(null)} />}
    </>
  );
}

/* ================================================================================================ queue */

function FollowupQueue({ counts }: { counts?: Counts }) {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useCrmRefresh();
  const { data: facets } = useFacets();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'due_today';
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [stage, setStage] = useState('');
  const [counsellor, setCounsellor] = useState('');
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 350);
  const [busy, setBusy] = useState<number | null>(null);
  const [skip, setSkip] = useState<FollowupRow | null>(null);
  const [reschedule, setReschedule] = useState<FollowupRow | null>(null);
  useEffect(() => setPage(1), [tab, stage, counsellor, dq]);
  const query: Query = { tab, stage: stage || undefined, counsellor_id: counsellor || undefined, q: dq || undefined, page, per_page: perPage };
  const list = useQuery({ queryKey: crmKeys.followups(query), queryFn: ({ signal }) => api.get<Paged<FollowupRow> & { counts: Counts }>('whatsapp/followups', query, { signal }), placeholderData: keepPreviousData });
  const c = list.data?.counts ?? counts;

  const sendNow = async (f: FollowupRow) => {
    setBusy(f.id);
    try {
      const r = await api.post(`whatsapp/followups/${f.id}/send`);
      toast.success(r.message);
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setBusy(null);
      refresh();
    }
  };

  const columns: Column<FollowupRow>[] = [
    {
      key: 'contact',
      header: 'Contact',
      render: (r) => (
        <div className="min-w-0">
          <Link to={`/whatsapp/contacts/${r.contact_id}`} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{r.contact_name || showPhone(r.phone)}</Link>
          <p className="max-w-[14rem] truncate text-xs text-slate-500" title={[r.program_name, r.counsellor_name].filter(Boolean).join(' · ')}>{r.counsellor_name ?? 'Unassigned'}{r.program_name ? ` · ${r.program_name}` : ''}</p>
        </div>
      ),
    },
    {
      key: 'stage',
      header: 'Stage · attempt',
      render: (r) => (
        <div className="min-w-0">
          <StageBadge stage={r.stage_key} facets={facets} />
          <p className="mt-1 max-w-[13rem] truncate text-xs text-slate-500" title={r.template_name ?? undefined}>
            #{r.attempt}{r.max_followups ? ` of ${r.max_followups}` : ''}{r.template_name ? ` · ${r.template_name}` : ''}
            {r.template_category === 'MARKETING' && !r.opt_in && <span className="text-amber-600"> · needs opt-in</span>}
          </p>
        </div>
      ),
    },
    {
      key: 'due_at',
      header: 'Due',
      render: (r) => (
        <span className={clsx('whitespace-nowrap text-xs', r.overdue && 'font-semibold text-red-600 dark:text-red-400')} title={formatDateTime(r.due_at)}>
          {formatDateTime(r.due_at)}
          <span className="block font-normal text-slate-500">{relDue(r.due_at)}</span>
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (r) => (
        <div className="max-w-[13rem]">
          <span className="inline-flex flex-wrap items-center gap-1.5">
            <StatusBadge status={r.overdue ? 'overdue' : r.status} />
            {r.message_status && <StatusBadge status={r.message_status} dot={false} />}
          </span>
          {r.note && <p className="mt-0.5 line-clamp-2 text-xs text-slate-500" title={r.note}>{r.note}</p>}
        </div>
      ),
    },
  ];

  return (
    <Reveal>
      <Card className="overflow-hidden">
        <CardHeader title="Follow-up queue" subtitle="Send now, skip with a reason or move to another time" icon={ListChecks} />
        <div className="space-y-3 border-b border-slate-100 px-5 pb-4 dark:border-slate-800">
          <Tabs
            variant="pills"
            value={tab}
            onChange={(t) => {
              const next = new URLSearchParams(params);
              next.set('tab', t);
              setParams(next, { replace: true });
            }}
            tabs={[
              { key: 'due_today', label: 'Due today', icon: CalendarClock, count: c?.due_today },
              { key: 'overdue', label: 'Overdue', icon: AlarmClock, count: c?.overdue },
              { key: 'upcoming', label: 'Upcoming', icon: Hourglass, count: c?.upcoming },
              { key: 'sent', label: 'Sent', icon: CheckCircle2, count: c?.sent },
              { key: 'skipped', label: 'Skipped', icon: SkipForward, count: c?.skipped },
              { key: 'all', label: 'All', count: c?.all },
            ]}
          />
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-3 lg:flex lg:items-center">
            <SearchInput size="sm" value={q} onChange={setQ} placeholder="Search contact or number…" className="lg:w-72" />
            <Select inputSize="sm" aria-label="Stage" className="lg:!w-52" value={stage} onChange={(e) => setStage(e.target.value)} options={(facets?.stages ?? []).map((s) => ({ value: String(s.value), label: s.label }))} placeholder="All stages" />
            <Select inputSize="sm" aria-label="Counsellor" className="lg:!w-48" value={counsellor} onChange={(e) => setCounsellor(e.target.value)} options={[{ value: 'none', label: 'Unassigned' }, ...(facets?.counsellors ?? []).map((x) => ({ value: String(x.value), label: x.label }))]} placeholder="All counsellors" />
          </div>
        </div>
        {list.error ? (
          <div className="p-4"><Alert variant="error" title="The queue could not be loaded" action={<Button size="sm" variant="secondary" onClick={() => list.refetch()}>Retry</Button>}>{(list.error as Error).message}</Alert></div>
        ) : (
          <DataTable<FollowupRow>
            caption="Follow-up queue"
            columns={columns}
            rows={list.data?.data ?? []}
            loading={list.isLoading || list.isFetching}
            empty={
              <EmptyState
                icon={tab === 'overdue' ? CheckCircle2 : CalendarClock}
                title={tab === 'overdue' ? 'Nothing overdue' : tab === 'due_today' ? 'No follow-ups due today' : 'No follow-ups here'}
                description="Follow-ups are scheduled automatically when a lead enters a stage with an active rule."
              />
            }
            actions={(r) =>
              can('whatsapp_contacts', 'edit') && (r.status === 'scheduled' || r.status === 'skipped' || r.status === 'failed') ? (
                <div className="flex items-center justify-end gap-1">
                  {r.status === 'scheduled' && can('whatsapp', 'create') && <Button size="xs" variant="soft" icon={Send} loading={busy === r.id} onClick={() => sendNow(r)}>Send now</Button>}
                  <Dropdown
                    label={`More actions for follow-up ${r.id}`}
                    triggerClassName="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" trigger={<MoreHorizontal className="h-4 w-4" />}
                    items={[
                      { label: r.status === 'scheduled' ? 'Reschedule' : 'Schedule again', icon: Clock3, onClick: () => setReschedule(r) },
                      r.status === 'scheduled' && { label: 'Skip', icon: SkipForward, onClick: () => setSkip(r) },
                      { label: 'Open contact', icon: UsersRound, to: `/whatsapp/contacts/${r.contact_id}`, divider: true },
                    ]}
                  />
                </div>
              ) : (
                <Button size="xs" variant="ghost" to={`/whatsapp/contacts/${r.contact_id}`}>Open</Button>
              )
            }
          />
        )}
        {(list.data?.meta.total ?? 0) > 0 && (
          <Pagination className="border-t border-slate-100 dark:border-slate-800" page={list.data!.meta.page} pages={list.data!.meta.pages} total={list.data!.meta.total} perPage={perPage} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} />
        )}
      </Card>
      <SkipDialog row={skip} onClose={() => setSkip(null)} />
      <RescheduleDialog row={reschedule} onClose={() => setReschedule(null)} />
    </Reveal>
  );
}

function SkipDialog({ row, onClose }: { row: FollowupRow | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = useCrmRefresh();
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    setReason('');
    setError(null);
  }, [row]);
  const submit = async () => {
    if (!row) return;
    setBusy(true);
    try {
      const r = await api.post(`whatsapp/followups/${row.id}/skip`, { reason });
      toast.success(r.message);
      refresh();
      onClose();
    } catch (e) {
      setError(fieldErrors(e).reason ?? (e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <DialogShell open={!!row} onClose={onClose} title="Skip this follow-up?" description={row ? `${row.contact_name ?? showPhone(row.phone)} — attempt #${row.attempt} (${row.stage_name}). The next attempt is not scheduled.` : undefined} busy={busy} onSubmit={submit} submitLabel="Skip follow-up">
      <Field label="Reason" error={error} htmlFor="wa-skip-reason" hint="Shown in the queue and on the contact timeline.">
        <Textarea id="wa-skip-reason" rows={2} value={reason} maxLength={200} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Spoke on the phone, visiting on Saturday" />
      </Field>
    </DialogShell>
  );
}

function RescheduleDialog({ row, onClose }: { row: FollowupRow | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = useCrmRefresh();
  const [due, setDue] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (!row) return;
    const d = new Date(Date.now() + 86400000);
    d.setMinutes(0, 0, 0);
    if (d.getHours() < 10) d.setHours(10);
    const pad = (n: number) => String(n).padStart(2, '0');
    setDue(`${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:00`);
    setError(null);
  }, [row]);
  const submit = async () => {
    if (!row) return;
    setBusy(true);
    try {
      const r = await api.post(`whatsapp/followups/${row.id}/reschedule`, { due_at: due });
      toast.success(r.message);
      refresh();
      onClose();
    } catch (e) {
      setError(fieldErrors(e).due_at ?? (e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <DialogShell open={!!row} onClose={onClose} title="Reschedule follow-up" description={row ? `${row.contact_name ?? showPhone(row.phone)} — ${row.stage_name}, attempt #${row.attempt}.` : undefined} busy={busy} onSubmit={submit} submitLabel="Reschedule">
      <Field label="Send at" required error={error} htmlFor="wa-resched">
        <Input id="wa-resched" type="datetime-local" value={due} onChange={(e) => setDue(e.target.value)} />
      </Field>
    </DialogShell>
  );
}

/* ================================================================================================ stage rule editor */

function StageRuleModal({ rule, templates, counsellors, auto, onClose }: { rule: StageRule; templates: StagesResponse['templates']; counsellors: StagesResponse['counsellors']; auto: string[]; onClose: () => void }) {
  const toast = useToast();
  const refresh = useCrmRefresh();
  const [v, setV] = useState(() => ({ ...rule, template_id: rule.template_id ? String(rule.template_id) : '', counsellor_id: rule.counsellor_id ? String(rule.counsellor_id) : '', next_action: rule.next_action ?? '', variable_defaults: { ...(rule.variable_defaults ?? {}) } as Record<string, string> }));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const tpl = templates.find((t) => String(t.value) === v.template_id);
  const set = <K extends keyof typeof v>(k: K, val: (typeof v)[K]) => setV((p) => ({ ...p, [k]: val }));
  const save = async () => {
    setBusy(true);
    setErrors({});
    try {
      const r = await api.put(`whatsapp/followups/stages/${rule.stage_key}`, {
        name: v.name, color: v.color, template_id: v.template_id || null, delay_hours: Number(v.delay_hours), max_followups: Number(v.max_followups),
        counsellor_id: v.counsellor_id || null, next_action: v.next_action, stop_conditions: v.stop_conditions, variable_defaults: v.variable_defaults, is_active: v.is_active ? 1 : 0, is_terminal: v.is_terminal ? 1 : 0,
      });
      toast.success(r.message);
      refresh();
      onClose();
    } catch (e) {
      setErrors(fieldErrors(e));
      if (!Object.keys(fieldErrors(e)).length) toast.error((e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  const info = stageInfo(rule.stage_key);
  return (
    <Modal
      open
      onClose={onClose}
      static={busy}
      size="xl"
      title={`Follow-up rule: ${rule.name}`}
      description={`Applies to leads and applicants entering “${info.label}”. Changes apply to new follow-ups; a new template also applies to queued ones.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button onClick={save} loading={busy}>Save rule</Button>
        </>
      }
    >
      <form className="grid grid-cols-1 gap-4 md:grid-cols-2" onSubmit={(e) => { e.preventDefault(); save(); }}>
        <Field label="Stage name" required error={errors.name} htmlFor="wa-rule-name">
          <Input id="wa-rule-name" value={v.name} onChange={(e) => set('name', e.target.value)} maxLength={80} />
        </Field>
        <Field label="Colour" htmlFor="wa-rule-color">
          <Select id="wa-rule-color" value={v.color} onChange={(e) => set('color', e.target.value as BadgeColor)} options={COLOR_OPTIONS} />
        </Field>
        <div className="flex flex-col gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700 md:col-span-2 md:flex-row">
          <Toggle checked={!!v.is_active} onChange={(x) => set('is_active', x ? 1 : 0)} label="Follow-ups active" description="Turning off cancels the follow-ups queued for this stage." />
          <Toggle checked={!!v.is_terminal} onChange={(x) => set('is_terminal', x ? 1 : 0)} label="Final stage" description="No follow-ups (Admission Confirmed, Not Interested)." />
        </div>
        {!v.is_terminal && (
          <>
            <Field label="WhatsApp template" required={!!v.is_active && Number(v.max_followups) > 0} error={errors.template_id} className="md:col-span-2" htmlFor="wa-rule-tpl" hint="Utility templates can go to every lead; marketing templates only to contacts who opted in.">
              <Combobox id="wa-rule-tpl" options={templates.map((t) => ({ value: String(t.value), label: t.label, sub: t.sub }))} value={v.template_id || null} onChange={(x) => set('template_id', x ? String(x) : '')} placeholder="Search templates…" invalid={!!errors.template_id} />
            </Field>
            {tpl && (
              <div className="md:col-span-2">
                {tpl.status !== 'approved' && <Alert variant="warning" className="mb-2">This template is “{tpl.status}” — follow-ups are skipped until WhatsApp approves it.</Alert>}
                <p className="whitespace-pre-line rounded-xl bg-[#D9FDD3] p-3 text-sm text-slate-800 dark:bg-[#005C4B] dark:text-slate-100">{tpl.body}</p>
              </div>
            )}
            {tpl && tpl.variables.length > 0 && (
              <fieldset className="rounded-xl border border-slate-200 p-3 dark:border-slate-700 md:col-span-2">
                <legend className="px-1 text-sm font-semibold text-slate-700 dark:text-slate-200">Template values</legend>
                <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">Filled from the contact and the admission portal. Give a default for values the portal does not hold — <code>{'{+7 days}'}</code> becomes a date 7 days after sending.</p>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {tpl.variables.map((x) => {
                    const isAuto = auto.includes(x.key);
                    const val = v.variable_defaults[x.key] ?? '';
                    return (
                      <Field
                        key={x.key}
                        htmlFor={`wa-rule-var-${x.key}`}
                        label={
                          <span className="inline-flex items-center gap-1.5">
                            {x.label}
                            {isAuto ? <Badge color="green" className="!px-1.5 !py-0 !text-[10px]">auto</Badge> : !val && <Badge color="amber" className="!px-1.5 !py-0 !text-[10px]">needs default</Badge>}
                          </span>
                        }
                      >
                        <Input
                          id={`wa-rule-var-${x.key}`}
                          inputSize="sm"
                          value={val}
                          maxLength={190}
                          placeholder={isAuto ? 'From the contact (optional fallback)' : `e.g. ${x.sample}`}
                          onChange={(e) => set('variable_defaults', { ...v.variable_defaults, [x.key]: e.target.value })}
                        />
                      </Field>
                    );
                  })}
                </div>
              </fieldset>
            )}
            <Field label="Wait before each follow-up" required error={errors.delay_hours} htmlFor="wa-rule-delay" hint="After entering the stage, then between attempts.">
              <Input id="wa-rule-delay" type="number" min={1} max={720} value={v.delay_hours} onChange={(e) => set('delay_hours', Number(e.target.value))} suffix="hours" />
            </Field>
            <Field label="Number of follow-ups" required error={errors.max_followups} htmlFor="wa-rule-max" hint="0 = no WhatsApp follow-ups, only the next-action task.">
              <Input id="wa-rule-max" type="number" min={0} max={10} value={v.max_followups} onChange={(e) => set('max_followups', Number(e.target.value))} />
            </Field>
          </>
        )}
        <Field label="Assign counsellor on entering" error={errors.counsellor_id} htmlFor="wa-rule-counsellor" hint="Only for leads without a counsellor.">
          <Select id="wa-rule-counsellor" value={v.counsellor_id} onChange={(e) => set('counsellor_id', e.target.value)} options={counsellors.map((c) => ({ value: String(c.value), label: c.label }))} placeholder="— Keep current owner —" />
        </Field>
        <Field label="Next action for the counsellor" error={errors.next_action} htmlFor="wa-rule-next" hint="Sent as a task notification when a lead enters the stage.">
          <Textarea id="wa-rule-next" rows={2} value={v.next_action} maxLength={190} onChange={(e) => set('next_action', e.target.value)} />
        </Field>
        {!v.is_terminal && (
          <fieldset className="md:col-span-2">
            <legend className="form-label">Stop the follow-ups when</legend>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <Checkbox label="The student replies" description="Any inbound WhatsApp message" checked={v.stop_conditions.on_reply} onChange={(e) => set('stop_conditions', { ...v.stop_conditions, on_reply: e.target.checked })} />
              <Checkbox label="The stage changes" description="Moved on the board, by sync or by automation" checked={v.stop_conditions.on_stage_change} onChange={(e) => set('stop_conditions', { ...v.stop_conditions, on_stage_change: e.target.checked })} />
              <Checkbox label="The contact opts out" description="Always on — a STOP reply ends every automated follow-up" checked disabled readOnly />
              <Checkbox label="The admission is confirmed" checked={v.stop_conditions.on_admission_confirmed} onChange={(e) => set('stop_conditions', { ...v.stop_conditions, on_admission_confirmed: e.target.checked })} />
            </div>
          </fieldset>
        )}
      </form>
    </Modal>
  );
}
