import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { AlertTriangle, CalendarClock, CheckCircle2, Copy, FlaskConical, Pencil, RotateCcw, Send, Trash2, Users, XCircle } from 'lucide-react';
import { Alert, Button, DescriptionList, Drawer, Dropdown, PageLoader, ProgressBar, StatusBadge, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber } from '@/lib/format';
import { CAMPAIGN_STATUS_COLORS, ChannelBadge, EmailFrame } from '../shared';
import type { useCampaignActions } from './actions';
import type { CampaignDetail } from './types';

interface Props {
  id: number | null;
  onClose: () => void;
  onEdit: (id: number) => void;
  actions: ReturnType<typeof useCampaignActions>;
  onChanged: () => void;
}

/** Campaign report: delivery counts, progress, failure reasons, content preview and the per-recipient log. */
export function CampaignDrawer({ id, onClose, onEdit, actions, onChanged }: Props) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const { data, isLoading, error } = useApi<CampaignDetail>(['comm', 'campaign', id], `newsletter/campaigns/${id}`, undefined, { enabled: !!id, refetchInterval: (q) => ((q.state.data as CampaignDetail | undefined)?.campaign.status === 'sending' ? 4000 : false) });
  const c = data?.campaign;
  const { data: preview } = useQuery({
    queryKey: ['comm', 'campaign-render', id, c?.updated_at],
    queryFn: () => api.post<{ html: string | null; text: string }>('communication/templates/preview', { channel: c?.channel, subject: c?.subject, body_html: c?.body_html, vars: { name: 'Ananya Sharma' } }).then((r) => r.data),
    enabled: !!c,
  });

  const status = String(c?.status ?? '');
  const editable = status === 'draft' || status === 'scheduled' || status === 'failed' || status === 'cancelled';
  const ref = c ? { id: Number(c.id), title: String(c.title), channel: String(c.channel), recipients: data?.audience ? Number(c.channel === 'email' ? data.audience.email : c.channel === 'sms' ? data.audience.sms : data.audience.whatsapp) : Number(c.recipients_count) } : null;
  const p = data?.progress;
  const recipients = Number(c?.recipients_count ?? 0);
  const sent = p && p.total ? p.sent : Number(c?.sent_count ?? 0);
  const failed = p && p.total ? p.failed : Number(c?.failed_count ?? 0);
  const rate = sent + failed ? Math.round((sent / (sent + failed)) * 1000) / 10 : null;

  const remove = async () => {
    if (!c) return;
    const ok = await confirm({ title: 'Delete campaign?', message: <>Delete <strong>{String(c.title)}</strong>? Delivery logs are kept.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/email_campaigns/${c.id}`);
      toast.success(res.message);
      onChanged();
      onClose();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const tiles = c
    ? [
        { label: 'Recipients', value: formatNumber(recipients || (data?.audience ? ref?.recipients ?? 0 : 0)), icon: Users, tone: 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' },
        { label: 'Delivered', value: formatNumber(sent), icon: CheckCircle2, tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' },
        { label: 'Failed', value: formatNumber(failed), icon: XCircle, tone: 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300' },
        { label: 'Delivery rate', value: rate === null ? '—' : `${rate}%`, icon: Send, tone: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300' },
      ]
    : [];

  return (
    <Drawer
      open={!!id}
      onClose={onClose}
      width="max-w-4xl"
      title={c ? String(c.title) : 'Campaign'}
      description={c ? <span className="flex flex-wrap items-center gap-1.5 pt-1"><StatusBadge status={status} colors={CAMPAIGN_STATUS_COLORS} /><ChannelBadge channel={String(c.channel)} /><span className="text-xs text-slate-500">{String(c.audience_label)}</span></span> : undefined}
      footer={
        c &&
        ref && (
          <div className="flex w-full flex-wrap items-center justify-between gap-2">
            <Dropdown
              align="left"
              trigger={<>More</>}
              items={[
                can('communication', 'create') && { label: 'Send a test', icon: FlaskConical, onClick: () => actions.openTest(ref) },
                can('communication', 'create') && { label: 'Duplicate as draft', icon: Copy, onClick: () => void actions.duplicate(ref, (newId) => onEdit(newId)) },
                can('communication', 'delete') && status !== 'sending' && { label: 'Delete campaign', icon: Trash2, danger: true, onClick: () => void remove() },
              ]}
            />
            <div className="flex flex-wrap gap-2">
              {can('communication', 'edit') && editable && (
                <Button variant="secondary" icon={Pencil} onClick={() => onEdit(Number(c.id))}>
                  Edit
                </Button>
              )}
              {can('communication', 'publish') && (status === 'scheduled' || status === 'sending') && (
                <Button variant="secondary" icon={XCircle} onClick={() => void actions.cancel({ ...ref, status })}>
                  {status === 'sending' ? 'Stop sending' : 'Cancel schedule'}
                </Button>
              )}
              {can('communication', 'publish') && status === 'sending' && (
                <Button icon={RotateCcw} onClick={() => actions.resume(ref)}>
                  Resume sending
                </Button>
              )}
              {can('communication', 'publish') && editable && (
                <>
                  <Button variant="secondary" icon={CalendarClock} onClick={() => actions.openSchedule(ref)}>
                    {status === 'scheduled' ? 'Reschedule' : 'Schedule'}
                  </Button>
                  <Button icon={Send} onClick={() => void actions.sendNow(ref)}>
                    Send now
                  </Button>
                </>
              )}
            </div>
          </div>
        )
      }
    >
      {error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : isLoading || !data || !c ? (
        <PageLoader />
      ) : (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {tiles.map((t) => (
              <div key={t.label} className="flex items-center gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                <span className={clsx('inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', t.tone)}>
                  <t.icon className="h-5 w-5" />
                </span>
                <div className="min-w-0">
                  <p className="truncate text-xs text-slate-500 dark:text-slate-400">{t.label}</p>
                  <p className="font-display text-lg font-bold text-slate-900 dark:text-white">{t.value}</p>
                </div>
              </div>
            ))}
          </div>

          {p && p.total > 0 && (
            <div>
              <div className="mb-1.5 flex justify-between text-xs text-slate-500 dark:text-slate-400">
                <span>{status === 'sending' ? 'Sending in progress' : 'Delivery progress'}</span>
                <span className="tabular-nums">{p.percent}% · {formatNumber(p.pending)} pending{p.cancelled ? ` · ${formatNumber(p.cancelled)} cancelled` : ''}</span>
              </div>
              <ProgressBar value={p.percent} tone={status === 'sending' ? 'blue' : 'green'} label="Campaign delivery progress" />
            </div>
          )}
          {data.audience && (
            <Alert variant="info" title={`Will be sent to ${formatNumber(ref?.recipients ?? 0)} recipients`}>
              {formatNumber(data.audience.people)} people in “{String(c.audience_label)}”. {status === 'scheduled' && c.scheduled_at ? `Scheduled for ${formatDateTime(c.scheduled_at)}.` : ''}
            </Alert>
          )}

          <DescriptionList
            items={[
              { label: 'Subject', value: c.subject ? String(c.subject) : '—', full: true },
              { label: 'Template', value: c.template_name ? String(c.template_name) : 'Written from scratch' },
              { label: 'Created by', value: `${c.created_by_name ?? 'System'} · ${formatDateTime(c.created_at)}` },
              { label: status === 'scheduled' ? 'Scheduled for' : 'Started', value: status === 'scheduled' ? formatDateTime(c.scheduled_at) : c.started_at ? formatDateTime(c.started_at) : 'Not sent yet' },
              { label: 'Completed', value: c.sent_at ? formatDateTime(c.sent_at) : '—' },
              { label: 'Last test', value: c.test_sent_at ? formatDateTime(c.test_sent_at) : 'No test sent' },
            ]}
          />

          {data.errors.length > 0 && (
            <section>
              <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Why messages failed</h3>
              <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                {data.errors.map((er) => (
                  <li key={er.error} className="flex items-start justify-between gap-3 px-3.5 py-2.5 text-sm">
                    <span className="flex min-w-0 items-start gap-2 text-slate-700 dark:text-slate-200"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" /><span className="break-words">{er.error}</span></span>
                    <span className="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(er.count)}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Message</h3>
            {c.channel === 'email' ? (
              <EmailFrame html={preview?.html} className="h-[420px]" />
            ) : (
              <div className="whitespace-pre-wrap rounded-xl bg-slate-100 p-4 text-sm text-slate-800 dark:bg-slate-800 dark:text-slate-100">{preview?.text ?? ''}</div>
            )}
          </section>

          {(sent > 0 || failed > 0) && can('communication', 'view') && (
            <CrudTable
              module="message_logs"
              scope={{ campaign_id: Number(c.id) }}
              title="Recipients"
              hideColumns={['related_label', 'channel']}
              hideFilters={['campaign_id', 'related_type', 'channel']}
              onView={(r) => navigate(`/message-logs?view=${r.id}`)}
              className="!shadow-none"
            />
          )}
        </div>
      )}
    </Drawer>
  );
}
