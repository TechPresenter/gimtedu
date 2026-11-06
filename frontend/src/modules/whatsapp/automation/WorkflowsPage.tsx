import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { Activity, CalendarClock, CircleX, Copy, FilePlus2, History, Hourglass, LayoutTemplate, MoreHorizontal, Pencil, Plus, Sparkles, Trash2, Workflow as WorkflowIcon, Zap } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Dropdown, EmptyState, Field, Input, Modal, PageHeader, Reveal, SearchInput, Skeleton, Stagger, StatCard, StatusBadge, Tabs, Textarea, Toggle, useConfirm, useToast } from '@/components/ui';
import { LineChart } from '@/components/charts';
import { ApiError, api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import { useWorkflowList, useWorkflowRefresh } from './api';
import { NODE_SPECS } from './nodeTypes';
import { RunsPanel } from './RunsPanel';
import type { Issue, NodeType, Starter, WorkflowRow, WorkflowStatus } from './types';

const BREAD = [{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Automation Builder' }];
const STATUS_COLORS = { active: 'green', draft: 'amber', inactive: 'slate' } as const;

/** Mini preview of the step types a workflow uses (coloured chips). */
function StepChips({ types }: { types: Partial<Record<NodeType, number>> }) {
  const order = Object.keys(NODE_SPECS) as NodeType[];
  const list = order.filter((t) => types[t] && t !== 'trigger');
  return (
    <div className="flex flex-wrap gap-1">
      {list.map((t) => {
        const S = NODE_SPECS[t];
        return (
          <span key={t} title={`${types[t]} × ${S.label}`} className={clsx('inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium', S.chip)}>
            <S.icon className="h-3 w-3" aria-hidden />
            {types[t]}
          </span>
        );
      })}
    </div>
  );
}

function WorkflowCard({ w, onToggle, onDuplicate, onDelete, busy }: { w: WorkflowRow; onToggle: (w: WorkflowRow) => void; onDuplicate: (w: WorkflowRow) => void; onDelete: (w: WorkflowRow) => void; busy: boolean }) {
  const { can } = useAuth();
  const navigate = useNavigate();
  const done = w.runs_total ? Math.round((w.runs_completed / w.runs_total) * 100) : 0;
  return (
    <Card className="group flex h-full flex-col transition duration-200 hover:-translate-y-0.5 hover:shadow-soft">
      <div className="flex items-start gap-3 p-5 pb-3">
        <span className={clsx('inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', w.status === 'active' ? 'bg-accent-600 text-white' : 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>
          <WorkflowIcon className="h-5 w-5" aria-hidden />
        </span>
        <div className="min-w-0 flex-1">
          <button type="button" onClick={() => navigate(`/whatsapp/automations/${w.id}`)} className="block text-left font-display text-[15px] font-semibold leading-snug text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
            {w.name}
          </button>
          <div className="mt-1 flex flex-wrap items-center gap-1.5">
            <StatusBadge status={w.status} colors={STATUS_COLORS} />
            <span className="text-xs text-slate-500 dark:text-slate-400">v{w.version} · {w.node_count} steps</span>
          </div>
        </div>
        <Dropdown
          label={`Actions for ${w.name}`}
          triggerClassName="btn-icon !h-8 !w-8"
          trigger={<MoreHorizontal className="h-4 w-4" />}
          items={[
            { label: can('whatsapp_automation', 'edit') ? 'Open builder' : 'View workflow', icon: Pencil, to: `/whatsapp/automations/${w.id}` },
            { label: 'Execution history', icon: History, to: `/whatsapp/automations?tab=runs&workflow=${w.id}` },
            can('whatsapp_automation', 'create') && { label: 'Duplicate', icon: Copy, onClick: () => onDuplicate(w) },
            can('whatsapp_automation', 'delete') && { label: 'Delete', icon: Trash2, danger: true, divider: true, disabled: w.status === 'active', hint: w.status === 'active' ? 'deactivate first' : undefined, onClick: () => onDelete(w) },
          ]}
        />
      </div>
      <div className="flex-1 space-y-3 px-5">
        {w.description && <p className="line-clamp-2 text-sm text-slate-600 dark:text-slate-300">{w.description}</p>}
        <p className="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
          <Zap className="h-3.5 w-3.5 shrink-0 text-accent-600" aria-hidden />
          <span className="truncate">
            <span className="font-medium">Trigger:</span> {w.trigger_name}
          </span>
        </p>
        {w.schedule && (
          <p className={clsx('flex items-center gap-1.5 text-xs', w.schedule.state === 'open' ? 'text-slate-600 dark:text-slate-300' : 'text-amber-700 dark:text-amber-300')}>
            <CalendarClock className="h-3.5 w-3.5 shrink-0" aria-hidden />
            <span className="truncate">
              <span className="font-medium">{w.schedule.state === 'ended' ? 'Schedule ended:' : w.schedule.state === 'upcoming' ? 'Starts:' : 'Scheduled:'}</span>{' '}
              {w.schedule.state === 'upcoming' && w.schedule.from
                ? formatDateTime(w.schedule.from)
                : [w.schedule.from ? `from ${formatDateTime(w.schedule.from)}` : null, w.schedule.until ? `until ${formatDateTime(w.schedule.until)}` : null].filter(Boolean).join(' ')}
            </span>
          </p>
        )}
        <StepChips types={w.node_types} />
      </div>
      <div className="mt-4 grid grid-cols-3 divide-x divide-slate-100 border-t border-slate-100 dark:divide-slate-800 dark:border-slate-800">
        {[
          { label: 'Runs', value: w.runs_total, cls: 'text-slate-900 dark:text-white' },
          { label: 'Completed', value: w.runs_completed, cls: 'text-emerald-700 dark:text-emerald-300' },
          { label: 'Failed', value: w.runs_failed, cls: w.runs_failed ? 'text-red-600 dark:text-red-300' : 'text-slate-900 dark:text-white' },
        ].map((s) => (
          <div key={s.label} className="px-3 py-2.5 text-center">
            <p className={clsx('font-display text-lg font-bold tabular-nums', s.cls)}>{formatNumber(s.value)}</p>
            <p className="text-[11px] text-slate-500 dark:text-slate-400">{s.label}</p>
          </div>
        ))}
      </div>
      <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 dark:border-slate-800">
        <div className="min-w-0 text-xs text-slate-500 dark:text-slate-400">
          {w.runs_active > 0 && <span className="mr-2 font-semibold text-amber-700 dark:text-amber-300">{w.runs_active} in progress</span>}
          {w.last_run_at ? `Last run ${timeAgo(w.last_run_at)}` : 'Never run'}
          {w.runs_total > 0 && <span className="ml-2">· {done}% completed</span>}
        </div>
        {can('whatsapp_automation', 'publish') ? (
          <Toggle checked={w.status === 'active'} disabled={busy} onChange={() => onToggle(w)} label={<span className="sr-only">{w.status === 'active' ? 'Deactivate' : 'Activate'} {w.name}</span>} />
        ) : (
          <span className="text-xs text-slate-400">{w.status === 'active' ? 'Active' : 'Not active'}</span>
        )}
      </div>
    </Card>
  );
}

function CreateModal({ open, onClose, starter }: { open: boolean; onClose: () => void; starter: Starter | null }) {
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const toast = useToast();
  const navigate = useNavigate();
  const refresh = useWorkflowRefresh();
  useEffect(() => {
    if (open) {
      setName(starter?.name ?? '');
      setDescription(starter?.description ?? '');
      setErrors({});
    }
  }, [open, starter]);
  const submit = async () => {
    setBusy(true);
    setErrors({});
    try {
      const res = await api.post<{ id: number }>('whatsapp/workflows', { name: name || starter?.name, description: description || starter?.description, starter: starter?.key });
      toast.success(res.message);
      refresh();
      onClose();
      navigate(`/whatsapp/automations/${res.data.id}`);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={busy}
      title={starter ? `New workflow from “${starter.name}”` : 'New workflow'}
      description={starter ? `${starter.node_count} ready-made steps, triggered by ${starter.trigger_name}. It is created as a draft — review it, then activate.` : 'Starts with an empty canvas and an Event Trigger.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button icon={Plus} loading={busy} onClick={submit}>
            Create &amp; open builder
          </Button>
        </>
      }
    >
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          submit();
        }}
      >
        <Field label="Workflow name" required htmlFor="wf-new-name" error={errors.name}>
          <Input id="wf-new-name" autoFocus value={name} onChange={(e) => setName(e.target.value)} placeholder={starter?.name ?? 'e.g. Scholarship enquiry follow-up'} maxLength={150} invalid={!!errors.name} />
        </Field>
        <Field label="Description" htmlFor="wf-new-desc" error={errors.description}>
          <Textarea id="wf-new-desc" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} placeholder={starter?.description ?? 'What this automation does and for whom'} maxLength={1000} />
        </Field>
      </form>
    </Modal>
  );
}

export default function WorkflowsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const refresh = useWorkflowRefresh();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'runs' ? 'runs' : 'workflows';
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 250);
  const [status, setStatus] = useState<'' | WorkflowStatus>('');
  const [creating, setCreating] = useState<{ starter: Starter | null } | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const { data, isLoading, error, refetch } = useWorkflowList({ q: dq || undefined, status: status || undefined });
  const all = useWorkflowList({});
  const s = data?.summary;

  const toggle = async (w: WorkflowRow) => {
    const next = w.status === 'active' ? 'inactive' : 'active';
    if (next === 'inactive' && w.runs_active > 0 && !(await confirm({ title: `Deactivate “${w.name}”?`, message: `No new runs will start. ${w.runs_active} run(s) already in progress will finish their remaining steps.`, confirmText: 'Deactivate' }))) return;
    setBusyId(w.id);
    try {
      const res = await api.post<{ issues?: Issue[] }>(`whatsapp/workflows/${w.id}/status`, { status: next });
      const warn = (res.data.issues ?? []).some((i) => i.code === 'deadline' || i.code === 'schedule' || i.code === 'duplicate_message');
      toast[warn ? 'warning' : 'success'](res.message);
      refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusyId(null);
    }
  };
  const duplicate = async (w: WorkflowRow) => {
    try {
      const res = await api.post<{ id: number }>(`whatsapp/workflows/${w.id}/duplicate`);
      toast.success(res.message);
      refresh();
      navigate(`/whatsapp/automations/${res.data.id}`);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const remove = async (w: WorkflowRow) => {
    if (!(await confirm({ title: `Delete “${w.name}”?`, message: `The workflow and its ${formatNumber(w.runs_total)} run(s) of history are deleted permanently.`, confirmText: 'Delete workflow', danger: true }))) return;
    try {
      const res = await api.del(`whatsapp/workflows/${w.id}`);
      toast.success(res.message);
      refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const statusTabs = useMemo(
    () => [
      { key: '', label: 'All', count: s ? s.total : undefined },
      { key: 'active', label: 'Active', count: s?.active },
      { key: 'draft', label: 'Draft', count: s?.draft },
      { key: 'inactive', label: 'Inactive', count: s?.inactive },
    ],
    [s],
  );

  return (
    <>
      <PageHeader
        title="Automation Builder"
        description="Visual WhatsApp workflows that react to admissions, payments and replies — welcome messages, follow-ups, counsellor hand-offs."
        breadcrumbs={BREAD}
        actions={
          can('whatsapp_automation', 'create') && (
            <Dropdown
              label="New workflow"
              triggerClassName="btn btn-primary"
              trigger={
                <>
                  <Plus className="h-4 w-4" aria-hidden /> New workflow
                </>
              }
              width="w-72"
              items={[
                { label: 'Blank workflow', icon: FilePlus2, onClick: () => setCreating({ starter: null }) },
                ...(data?.starters ?? []).map((st, i) => ({ label: st.name, icon: LayoutTemplate, divider: i === 0, onClick: () => setCreating({ starter: st }) })),
              ]}
            />
          )
        }
      />

      <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" step={60}>
        {[
          <StatCard key="a" label="Active workflows" icon={Zap} tone="orange" loading={isLoading} value={s?.active ?? 0} hint={s ? `${s.total} in total` : undefined} />,
          <StatCard key="r" label="Runs (30 days)" icon={Activity} tone="navy" loading={isLoading} value={s?.runs_30d ?? 0} to="/whatsapp/automations?tab=runs" />,
          <StatCard key="p" label="In progress now" icon={Hourglass} tone="amber" loading={isLoading} value={s?.in_progress ?? 0} to="/whatsapp/automations?tab=runs&status=active" />,
          <StatCard key="f" label="Failed (30 days)" icon={CircleX} tone="red" loading={isLoading} value={s?.failed_30d ?? 0} hint={s ? `${formatNumber(s.completed_30d)} completed` : undefined} to="/whatsapp/automations?tab=runs&status=failed" />,
        ]}
      </Stagger>

      <div className="mt-6">
        <Tabs
          tabs={[
            { key: 'workflows', label: 'Workflows', icon: WorkflowIcon, count: s?.total },
            { key: 'runs', label: 'Execution history', icon: History },
          ]}
          value={tab}
          onChange={(k) => setParams(k === 'runs' ? { tab: 'runs' } : {}, { replace: true })}
        />
      </div>

      <div className="mt-5">
        {tab === 'runs' ? (
          <RunsPanel workflows={all.data?.rows ?? []} />
        ) : error ? (
          <Alert variant="error" title="Unable to load workflows" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
            {(error as ApiError).message}
          </Alert>
        ) : (
          <div className="space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <Tabs tabs={statusTabs} value={status} onChange={(v) => setStatus(v as '' | WorkflowStatus)} variant="pills" />
              <SearchInput value={q} onChange={setQ} placeholder="Search workflows…" className="sm:w-72" />
            </div>
            {isLoading ? (
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {[0, 1, 2].map((i) => (
                  <Skeleton key={i} className="h-72 w-full rounded-2xl" />
                ))}
              </div>
            ) : data && data.rows.length ? (
              <Stagger className="grid gap-4 md:grid-cols-2 xl:grid-cols-3" step={50}>
                {data.rows.map((w) => (
                  <WorkflowCard key={w.id} w={w} busy={busyId === w.id} onToggle={toggle} onDuplicate={duplicate} onDelete={remove} />
                ))}
              </Stagger>
            ) : (
              <Card>
                <EmptyState
                  icon={WorkflowIcon}
                  title={q || status ? 'No workflows match' : 'No workflows yet'}
                  description={q || status ? 'Try another search or status.' : 'Start from a ready-made admission workflow below or build your own on a blank canvas.'}
                  action={can('whatsapp_automation', 'create') && !q && !status ? <Button icon={Plus} onClick={() => setCreating({ starter: null })}>Blank workflow</Button> : undefined}
                />
              </Card>
            )}

            {data && data.trend.labels.length > 0 && (
              <Reveal>
                <Card>
                  <CardHeader title="Runs in the last 14 days" subtitle="Started vs completed vs failed (test runs excluded)" icon={Activity} />
                  <div className="card-body">
                    {data.trend.started.some(Boolean) || data.trend.completed.some(Boolean) ? (
                      <LineChart
                        height={220}
                        labels={data.trend.labels}
                        series={[
                          { label: 'Started', data: data.trend.started, color: '#183C7A', fill: true },
                          { label: 'Completed', data: data.trend.completed, color: '#10B981' },
                          { label: 'Failed', data: data.trend.failed, color: '#EF4444', dashed: true },
                        ]}
                        stepSize={1}
                      />
                    ) : (
                      <EmptyState icon={Activity} title="No runs in the last 14 days" description="Activate a workflow — its runs are charted here." />
                    )}
                  </div>
                </Card>
              </Reveal>
            )}

            {can('whatsapp_automation', 'create') && data && data.starters.length > 0 && (
              <Reveal>
                <div>
                  <div className="mb-3 flex items-center gap-2">
                    <Sparkles className="h-4 w-4 text-accent-600" aria-hidden />
                    <h2 className="font-display text-base font-semibold text-slate-900 dark:text-white">Start from a template</h2>
                  </div>
                  <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {data.starters.map((st) => (
                      <button
                        key={st.key}
                        type="button"
                        onClick={() => setCreating({ starter: st })}
                        className="card group flex h-full flex-col p-4 text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                      >
                        <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300">
                          <LayoutTemplate className="h-[18px] w-[18px]" aria-hidden />
                        </span>
                        <p className="mt-3 font-semibold text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300">{st.name}</p>
                        <p className="mt-1 line-clamp-3 text-xs text-slate-500 dark:text-slate-400">{st.description}</p>
                        <p className="mt-auto flex items-center gap-1.5 pt-3 text-[11px] font-medium text-slate-500 dark:text-slate-400">
                          <Badge color="navy">{st.node_count} steps</Badge>
                          <span className="truncate">{st.trigger_name}</span>
                        </p>
                      </button>
                    ))}
                    <button
                      type="button"
                      onClick={() => setCreating({ starter: null })}
                      className="flex h-full min-h-[10rem] flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-200 p-4 text-center text-slate-500 transition hover:border-accent-400 hover:text-accent-700 dark:border-slate-700 dark:text-slate-400 dark:hover:border-accent-500 dark:hover:text-accent-300"
                    >
                      <Plus className="h-6 w-6" aria-hidden />
                      <span className="mt-2 text-sm font-semibold">Blank workflow</span>
                      <span className="text-xs">Design it step by step</span>
                    </button>
                  </div>
                </div>
              </Reveal>
            )}
          </div>
        )}
      </div>
      <CreateModal open={!!creating} onClose={() => setCreating(null)} starter={creating?.starter ?? null} />
    </>
  );
}
