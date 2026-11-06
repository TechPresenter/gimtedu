import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Activity, CodeXml, FlaskConical, Save, Settings2, Trash, Workflow } from 'lucide-react';
import { Alert, Badge, Button, CardSkeleton, Drawer, Skeleton, Tabs, useConfirm, useToast } from '@/components/ui';
import { LineChart } from '@/components/charts';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber, labelize, timeAgo } from '@/lib/format';
import { useWaRefresh } from '../shared';
import { categoryTone, useEventDetail, useEventStats } from './api';
import { EventLogs, LogDetailModal } from './EventLogs';
import { EventSettings, formFromEvent, formToBody, type EventForm } from './EventSettings';
import { EventTest } from './EventTest';
import { IntegrationCode } from './IntegrationCode';
import type { WaEventOptions, WaEventSaved, WaEventWorkflowOverlap } from './types';

interface Props {
  id: number | null;
  tab: string;
  onTab: (t: string) => void;
  onClose: () => void;
  options: WaEventOptions | undefined;
}

const TABS = [
  { key: 'settings', label: 'Settings', icon: Settings2 },
  { key: 'logs', label: 'Logs', icon: Activity },
  { key: 'test', label: 'Test', icon: FlaskConical },
  { key: 'code', label: 'Integration', icon: CodeXml },
];

/** Slide-over editor of one event: settings, execution log + stats, test fire, integration code. */
export function EventDrawer({ id, tab, onTab, onClose, options }: Props) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useWaRefresh();
  const { data: event, isLoading, error, refetch } = useEventDetail(id);
  const [form, setForm] = useState<EventForm | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [logId, setLogId] = useState<number | null>(null);

  useEffect(() => {
    if (event) {
      setForm(formFromEvent(event));
      setErrors({});
    }
  }, [event]);
  useEffect(() => {
    if (!id) setForm(null);
  }, [id]);

  const dirty = !!(event && form && JSON.stringify(formFromEvent(event)) !== JSON.stringify(form));
  const close = async () => {
    if (dirty && !(await confirm({ title: 'Discard changes?', message: 'You have unsaved changes to this event.', confirmText: 'Discard', danger: true }))) return;
    onClose();
  };

  const save = async () => {
    if (!event || !form) return;
    try {
      const v = JSON.parse(form.sample_payload || '{}');
      if (!v || typeof v !== 'object' || Array.isArray(v)) throw new Error('x');
    } catch {
      setErrors({ sample_payload: 'The sample payload must be a valid JSON object.' });
      onTab('settings');
      return;
    }
    setSaving(true);
    try {
      const r = await api.put<WaEventSaved>(`whatsapp/events/${event.id}`, formToBody(form, event));
      toast.success(r.message || 'Event saved.');
      if (r.data?.warning) toast.warning(r.data.warning, 'Contacts may get two messages');
      setErrors({});
      await refresh('events');
      await refetch();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
      onTab('settings');
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    if (!event) return;
    const ok = await confirm({ title: `Delete ${event.name}?`, message: 'The custom event is removed; its execution logs are kept. External systems still sending this code will get "unknown event".', confirmText: 'Delete event', danger: true });
    if (!ok) return;
    try {
      const r = await api.del(`whatsapp/events/${event.id}`);
      toast.success(r.message || 'Event deleted.');
      await refresh('events');
      onClose();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const tone = event ? categoryTone(event.category) : null;
  const canEdit = can('whatsapp_automation', 'edit');

  return (
    <Drawer
      open={!!id}
      onClose={close}
      width="max-w-3xl"
      title={event ? event.name : 'Event'}
      description={
        event ? (
          <span className="flex flex-wrap items-center gap-2">
            <code className="font-mono text-xs">{event.code}</code>
            {tone && <span className={`rounded-md px-1.5 py-0.5 text-[11px] ring-1 ring-inset ${tone.chip}`}>{options?.categories[event.category] ?? labelize(event.category)}</span>}
            <Badge color={event.is_active ? 'green' : 'slate'} dot>{event.is_active ? 'Active' : 'Inactive'}</Badge>
          </span>
        ) : undefined
      }
      footer={
        tab === 'settings' && event && form ? (
          <>
            {!event.is_system && can('whatsapp_automation', 'delete') && (
              <Button variant="ghost" icon={Trash} className="mr-auto text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" onClick={remove}>Delete</Button>
            )}
            <Button variant="secondary" onClick={close}>Close</Button>
            {canEdit && <Button icon={Save} loading={saving} disabled={!dirty} onClick={save}>Save changes</Button>}
          </>
        ) : (
          <Button variant="secondary" onClick={close}>Close</Button>
        )
      }
    >
      {isLoading || (!event && !error) ? (
        <div className="space-y-4">
          <Skeleton className="h-9 w-full" />
          <CardSkeleton lines={6} />
        </div>
      ) : error ? (
        <Alert variant="error" title="Could not load this event" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>
      ) : event && form && options ? (
        <div className="space-y-5">
          {event.description && <p className="text-sm text-slate-600 dark:text-slate-300">{event.description}</p>}
          <WorkflowOverlapAlert overlaps={event.workflow_overlaps ?? []} code={event.code} active={form.is_active} />
          <div className="grid grid-cols-3 gap-2 text-center">
            <MiniStat label="Times fired" value={formatNumber(event.execution_count)} />
            <MiniStat label="Last fired" value={event.last_executed_at ? timeAgo(event.last_executed_at) : 'Never'} />
            <MiniStat label="Sent (30 days)" value={formatNumber(event.messages_30d ?? 0)} />
          </div>
          <Tabs tabs={TABS} value={tab} onChange={onTab} />
          {tab === 'settings' && <EventSettings event={event} options={options} form={form} setForm={setForm} errors={errors} />}
          {tab === 'logs' && <EventActivity id={event.id} code={event.code} sources={options.sources} />}
          {tab === 'test' && <EventTest event={event} onOpenLog={setLogId} />}
          {tab === 'code' && <IntegrationCode event={event} options={options} />}
        </div>
      ) : (
        <CardSkeleton lines={6} />
      )}
      <LogDetailModal id={logId} onClose={() => setLogId(null)} sources={options?.sources ?? {}} />
    </Drawer>
  );
}

/**
 * Warning (never a block) when an active automation workflow started by the same event also sends templates: with the
 * event active the contact gets both messages — e.g. enquiry_received from here and the workflow's welcome step.
 */
function WorkflowOverlapAlert({ overlaps, code, active }: { overlaps: WaEventWorkflowOverlap[]; code: string; active: boolean }) {
  if (!overlaps.length) return null;
  const same = overlaps.find((o) => o.same_template);
  return (
    <Alert variant="warning" title={active ? 'A workflow also messages contacts on this event' : 'An active workflow already messages contacts on this event'}>
      <p>
        {active ? <>When <code className="font-mono text-xs">{code}</code> fires, contacts get this event’s message and the messages of:</> : <>If you activate this event, contacts also get its message next to the messages of:</>}
      </p>
      <ul className="mt-1.5 space-y-1">
        {overlaps.map((o) => (
          <li key={o.id} className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <Link to={`/whatsapp/automations/${o.id}`} className="inline-flex items-center gap-1 font-medium underline underline-offset-2 hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 rounded">
              <Workflow className="h-3.5 w-3.5" aria-hidden /> {o.name}
            </Link>
            <span className="flex flex-wrap gap-1">
              {o.templates.map((t) => (
                <code key={t} className="rounded bg-amber-100/80 px-1 font-mono text-[11px] text-amber-900 dark:bg-amber-500/20 dark:text-amber-100">{t}</code>
              ))}
            </span>
            {o.same_template && <Badge color="red">Same template</Badge>}
          </li>
        ))}
      </ul>
      {same && (
        <p className="mt-1.5">“{same.name}” sends the same template as this event — keep only one of the two: deactivate this event, or remove that step from the workflow.</p>
      )}
    </Alert>
  );
}

function MiniStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-xl bg-slate-50 px-2 py-2.5 dark:bg-slate-800/60">
      <div className="truncate font-display text-base font-semibold text-slate-900 dark:text-white">{value}</div>
      <div className="truncate text-[11px] text-slate-500 dark:text-slate-400">{label}</div>
    </div>
  );
}

/** 30-day trend + message statuses + the execution log of one event. */
function EventActivity({ id, code, sources }: { id: number; code: string; sources: Record<string, string> }) {
  const { data: s, isLoading } = useEventStats(id, 30);
  const msgOrder = ['queued', 'sent', 'delivered', 'read', 'simulated', 'failed', 'cancelled'];
  return (
    <div className="space-y-4">
      <div className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h4 className="text-sm font-semibold text-slate-900 dark:text-white">Last 30 days</h4>
          {s && <span className="text-xs text-slate-500">{formatNumber(s.total)} executions</span>}
        </div>
        {isLoading || !s ? (
          <Skeleton className="h-40 w-full" />
        ) : (
          <>
            <LineChart labels={s.trend.labels} series={[{ label: 'Fired', data: s.trend.fired, fill: true }]} height={160} beginAtZero stepSize={1} ariaLabel="Executions per day" />
            <div className="mt-3 flex flex-wrap gap-1.5 text-[11px]">
              {Object.keys(s.messages).length === 0 ? (
                <span className="text-slate-500">No messages sent by this event yet.</span>
              ) : (
                msgOrder.filter((k) => s.messages[k]).map((k) => (
                  <Badge key={k} color={k === 'failed' || k === 'cancelled' ? 'red' : k === 'read' ? 'blue' : k === 'queued' ? 'amber' : 'green'}>
                    {labelize(k)} {formatNumber(s.messages[k])}
                  </Badge>
                ))
              )}
            </div>
          </>
        )}
      </div>
      <EventLogs code={code} sources={sources} compact />
    </div>
  );
}
