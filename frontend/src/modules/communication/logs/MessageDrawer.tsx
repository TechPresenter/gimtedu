import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowUpRight, Lock, RotateCcw } from 'lucide-react';
import { Alert, Button, DescriptionList, Drawer, PageLoader, StatusBadge, Timeline, useToast, type TimelineItem } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDateTime } from '@/lib/format';
import type { Row } from '@/lib/types';
import { ChannelBadge, EmailFrame, useCommRefresh } from '../shared';

interface LogDetail {
  log: Row;
  retries: { id: number; status: string; error: string | null; created_at: string }[];
  original: { id: number; status: string; error: string | null; created_at: string } | null;
  can_resend: boolean;
}

/** One outbound message: metadata, error, retry trail and the rendered body. */
export function MessageDrawer({ id, onClose, onOpen }: { id: number | null; onClose: () => void; onOpen: (id: number) => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useCommRefresh();
  const { data, isLoading, error } = useApi<LogDetail>(['comm', 'log', id], `communication/message-logs/${id}`, undefined, { enabled: !!id });
  const [busy, setBusy] = useState(false);
  const l = data?.log;

  const resend = async () => {
    if (!l) return;
    setBusy(true);
    try {
      const res = await api.post<{ new_id: number | null }>(`communication/message-logs/${l.id}/resend`);
      toast.success(res.message);
      await refresh('crud');
      if (res.data.new_id) onOpen(res.data.new_id);
    } catch (e) {
      toast.error((e as ApiError).message);
      await refresh('crud');
    } finally {
      setBusy(false);
    }
  };

  const trail: TimelineItem[] = [];
  if (data) {
    if (data.original) trail.push({ tone: data.original.status === 'sent' ? 'green' : 'red', title: <button type="button" className="link" onClick={() => onOpen(data.original!.id)}>Original attempt #{data.original.id} · {data.original.status}</button>, description: data.original.error ?? undefined, time: formatDateTime(data.original.created_at) });
    trail.push({ tone: l?.status === 'sent' ? 'green' : l?.status === 'failed' ? 'red' : 'amber', title: <>This message #{String(l?.id)} · {String(l?.status)}</>, description: l?.error ? String(l.error) : undefined, time: formatDateTime(l?.created_at) });
    data.retries.forEach((r) => trail.push({ tone: r.status === 'sent' ? 'green' : 'red', title: <button type="button" className="link" onClick={() => onOpen(r.id)}>Re-sent as #{r.id} · {r.status}</button>, description: r.error ?? undefined, time: formatDateTime(r.created_at) }));
  }

  return (
    <Drawer
      open={!!id}
      onClose={onClose}
      width="max-w-3xl"
      title={l ? String(l.subject || `${String(l.channel).toUpperCase()} message`) : 'Message'}
      description={l ? <span className="flex flex-wrap items-center gap-1.5 pt-1"><StatusBadge status={String(l.status)} /><ChannelBadge channel={String(l.channel)} /><span className="text-xs text-slate-500">#{String(l.id)}</span></span> : undefined}
      footer={
        l && (
          <div className="flex w-full flex-wrap items-center justify-between gap-2">
            {l.related_url ? (
              <Button variant="ghost" iconRight={ArrowUpRight} to={String(l.related_url)}>
                Open {String(l.related_label).toLowerCase()}
              </Button>
            ) : (
              <span />
            )}
            {can('communication', 'edit') && l.status !== 'sent' && (
              <Button icon={RotateCcw} loading={busy} disabled={!data?.can_resend} onClick={() => void resend()} title={data?.can_resend ? undefined : 'This channel is not enabled in Settings'}>
                Resend
              </Button>
            )}
          </div>
        )
      }
    >
      {error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : isLoading || !data || !l ? (
        <PageLoader />
      ) : (
        <div className="space-y-6">
          {l.error && (
            <Alert variant={l.status === 'queued' ? 'warning' : 'error'} title={l.status === 'queued' ? 'Not delivered yet' : 'Delivery failed'}>
              <span className="break-words">{String(l.error)}</span>
            </Alert>
          )}
          <DescriptionList
            items={[
              { label: 'Recipient', value: String(l.recipient) },
              {
                label: 'Related to',
                value: (() => {
                  const text = `${String(l.related_label)}${l.related_title ? ` · ${String(l.related_title)}` : l.related_id ? ` #${l.related_id}` : ''}`;
                  return l.related_url ? <Link to={String(l.related_url)} className="link">{text}</Link> : text;
                })(),
              },
              { label: 'Created', value: formatDateTime(l.created_at) },
              { label: 'Delivered', value: l.sent_at ? formatDateTime(l.sent_at) : '—' },
              ...(l.campaign_title ? [{ label: 'Campaign', value: String(l.campaign_title), full: true }] : []),
            ]}
          />
          {trail.length > 1 && (
            <section>
              <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Delivery attempts</h3>
              <Timeline items={trail} />
            </section>
          )}
          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Message body</h3>
            {l.redacted ? (
              <div className="flex items-start gap-3 rounded-xl border border-dashed border-slate-300 px-4 py-5 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">
                <Lock className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden />
                <p>This message contains sign-in details (a password-reset link or a temporary password), so its content is hidden. Only administrators who manage user accounts can view it. Re-sending delivers it to the original recipient only.</p>
              </div>
            ) : l.channel === 'email' ? (
              <EmailFrame html={String(l.html ?? '')} className="h-[460px]" title="Message body" />
            ) : (
              <div className="whitespace-pre-wrap rounded-xl bg-slate-100 p-4 text-sm text-slate-800 dark:bg-slate-800 dark:text-slate-100">{String(l.body ?? '')}</div>
            )}
          </section>
        </div>
      )}
    </Drawer>
  );
}
