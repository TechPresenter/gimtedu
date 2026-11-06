import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Ban, ExternalLink, FlaskConical, MessagesSquare, RotateCcw } from 'lucide-react';
import { Alert, Badge, Button, DescriptionList, Drawer, Skeleton, StatusBadge, Tabs, useConfirm, useToast } from '@/components/ui';
import { ApiError, api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, labelize, timeAgo } from '@/lib/format';
import { parseServerDate } from '../shared';
import { useRunDetail, useWorkflowRefresh } from './api';
import { RunTimeline } from './RunTimeline';
import type { RunDetail } from './types';

export const RUN_STATUS_COLORS = { running: 'blue', waiting: 'amber', completed: 'green', failed: 'red', cancelled: 'slate' } as const;

export function waitingText(r: { status: string; waiting_for: string | null; next_run_at: string | null }): string | null {
  if (r.status !== 'waiting' || !r.next_run_at) return null;
  const when = formatDateTime(r.next_run_at);
  if (r.waiting_for === 'reply') return `Waiting for a reply · timeout ${when}`;
  if (r.waiting_for === 'business_hours') return `Paused until business hours · ${when}`;
  // server times are IST: compare as IST whatever the browser's time zone is
  return `Next step ${(parseServerDate(r.next_run_at)?.getTime() ?? 0) <= Date.now() ? 'is due now' : `at ${when}`}`;
}

/** Run details: summary, actions (retry / cancel), step timeline, messages and the event payload. */
export function RunDrawer({ runId, onClose }: { runId: number | null; onClose: () => void }) {
  const { data, isLoading, error, refetch } = useRunDetail(runId);
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useWorkflowRefresh();
  const [tab, setTab] = useState('timeline');
  const [busy, setBusy] = useState<'retry' | 'cancel' | null>(null);

  const act = async (kind: 'retry' | 'cancel') => {
    if (!data) return;
    if (kind === 'cancel' && !(await confirm({ title: 'Cancel this run?', message: 'No further steps will run for this contact in this workflow. Messages already queued are not recalled.', confirmText: 'Cancel run', danger: true }))) return;
    setBusy(kind);
    try {
      const res = await api.post<RunDetail>(`whatsapp/workflows/runs/${data.run.id}/${kind}`);
      toast[res.data.run.status === 'failed' ? 'warning' : 'success'](res.message);
      await Promise.all([refetch(), refresh()]);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const r = data?.run;
  return (
    <Drawer
      open={runId !== null}
      onClose={onClose}
      width="max-w-2xl"
      title={r ? `Run #${r.id}` : 'Run'}
      description={r ? r.workflow_name : undefined}
      footer={
        r && can('whatsapp_automation', 'edit') ? (
          <>
            {r.status === 'failed' && (
              <Button icon={RotateCcw} loading={busy === 'retry'} onClick={() => act('retry')}>
                Retry from failed step
              </Button>
            )}
            {(r.status === 'running' || r.status === 'waiting') && (
              <Button variant="danger" icon={Ban} loading={busy === 'cancel'} onClick={() => act('cancel')}>
                Cancel run
              </Button>
            )}
            <Button variant="secondary" onClick={onClose}>
              Close
            </Button>
          </>
        ) : undefined
      }
    >
      {isLoading ? (
        <div className="space-y-3">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-64 w-full" />
        </div>
      ) : error ? (
        <Alert variant="error" title="Unable to load the run" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : data && r ? (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={r.status} colors={RUN_STATUS_COLORS} />
            {r.is_test && (
              <Badge color="purple">
                <FlaskConical className="h-3 w-3" aria-hidden /> Test run
              </Badge>
            )}
            {r.attempts > 0 && <Badge color="slate">Retried {r.attempts}×</Badge>}
            <span className="text-xs text-slate-500">Workflow version {data.context.version ?? r.workflow_version}</span>
          </div>
          {r.error && (
            <Alert variant={r.status === 'cancelled' ? 'warning' : 'error'} title={r.status === 'cancelled' ? 'Stopped' : 'Failed'}>
              {r.error}
            </Alert>
          )}
          {waitingText(r) && <Alert variant="info">{waitingText(r)}</Alert>}
          <DescriptionList
            items={[
              { label: 'Contact', value: r.contact_id ? <Link className="font-semibold text-brand-700 hover:underline dark:text-brand-300" to={`/whatsapp/contacts/${r.contact_id}`}>{r.contact_name || `+${r.contact_phone}`}</Link> : 'No contact' },
              { label: 'Phone', value: r.contact_phone ? `+${r.contact_phone}` : null },
              { label: 'Current step', value: r.current_node_label },
              { label: 'Stage now', value: r.contact_stage ? labelize(r.contact_stage) : null },
              { label: 'Started', value: `${formatDateTime(r.started_at)} (${timeAgo(r.started_at)})` },
              { label: 'Finished', value: r.finished_at ? formatDateTime(r.finished_at) : null },
            ]}
          />
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="secondary" icon={ExternalLink} to={`/whatsapp/automations/${r.workflow_id}`}>
              Open workflow
            </Button>
            {r.conversation_id && (
              <Button size="sm" variant="secondary" icon={MessagesSquare} to={`/whatsapp/inbox?c=${r.conversation_id}`}>
                Open chat
              </Button>
            )}
          </div>
          <Tabs
            tabs={[
              { key: 'timeline', label: 'Steps', count: data.steps.length },
              { key: 'messages', label: 'Messages', count: data.messages.length },
              { key: 'payload', label: 'Event data' },
            ]}
            value={tab}
            onChange={setTab}
          />
          {tab === 'timeline' && <RunTimeline steps={data.steps} phone={r.contact_phone} />}
          {tab === 'messages' &&
            (data.messages.length ? (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {data.messages.map((m) => (
                  <li key={m.id} className="py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-sm font-semibold text-slate-900 dark:text-white">{m.template_name ? `Template ${m.template_name}` : labelize(m.type)}</span>
                      <StatusBadge status={m.status} />
                      <span className="ml-auto text-xs text-slate-400">{formatDateTime(m.created_at)}</span>
                    </div>
                    {m.body && <p className="mt-1 line-clamp-3 whitespace-pre-line text-[13px] text-slate-600 dark:text-slate-300">{m.body}</p>}
                    {m.error && <p className="mt-1 text-xs text-red-600 dark:text-red-300">{m.error}</p>}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="py-6 text-center text-sm text-slate-500">No WhatsApp messages were sent by this run.</p>
            ))}
          {tab === 'payload' && (
            <div className="space-y-3">
              {data.context.last_reply && (
                <div className="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-100">
                  <p className="text-[11px] font-bold uppercase tracking-wide opacity-70">Last reply · {formatDateTime(data.context.last_reply.at)}</p>
                  <p className="mt-0.5">{data.context.last_reply.text || '—'}</p>
                </div>
              )}
              <pre className="max-h-80 overflow-auto rounded-xl bg-slate-50 p-3 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">{JSON.stringify(data.context.payload, null, 2)}</pre>
              {Object.keys(data.context.vars ?? {}).length > 0 && (
                <pre className="max-h-60 overflow-auto rounded-xl bg-slate-50 p-3 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">{JSON.stringify(data.context.vars, null, 2)}</pre>
              )}
            </div>
          )}
        </div>
      ) : null}
    </Drawer>
  );
}
