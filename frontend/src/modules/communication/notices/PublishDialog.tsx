import { useEffect, useState } from 'react';
import { BellRing, Mail, MessageCircle, MessageSquareText, Send } from 'lucide-react';
import { Alert, Button, Modal, PageLoader, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { NoticeDetail, PublishResult } from './types';

interface Props {
  id: number | null;
  onClose: () => void;
  /** Called after publishing; `batch` is set when messages were queued. */
  onPublished: (res: PublishResult, title: string) => void;
}

/** Publish a notice and choose the delivery channels (e-mail / SMS / WhatsApp / in-app). */
export function PublishDialog({ id, onClose, onPublished }: Props) {
  const toast = useToast();
  const open = !!id;
  const { data, isLoading, error } = useApi<NoticeDetail>(['comm', 'notice', id], `notices/${id}`, undefined, { enabled: open });
  const [email, setEmail] = useState(true);
  const [sms, setSms] = useState(false);
  const [wa, setWa] = useState(false);
  const [notify, setNotify] = useState(true);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!data) return;
    const alreadySent = data.delivery.total_sent > 0;
    setEmail(!alreadySent && data.audience.email > 0);
    setSms(false);
    setWa(false);
    setNotify(!alreadySent);
  }, [data]);

  const n = data?.notice;
  const ch = data?.audience.channels;
  const scheduled = n?.publish_date && new Date(String(n.publish_date).replace(' ', 'T')).getTime() > Date.now();

  const submit = async () => {
    if (!id || !n) return;
    setBusy(true);
    try {
      const res = await api.post<PublishResult>(`notices/${id}/publish`, { email, sms, whatsapp: wa, notify });
      toast.success(res.message);
      onPublished(res.data, String(n.title));
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  const channelRow = (opts: { icon: typeof Mail; label: string; count: number; checked: boolean; onChange: (v: boolean) => void; enabled: boolean; hint?: string }) => {
    const Icon = opts.icon;
    const disabled = !opts.enabled || opts.count === 0;
    return (
      <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-3.5 py-3 dark:border-slate-700">
        <div className="flex min-w-0 items-center gap-3">
          <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">
            <Icon className="h-4 w-4" />
          </span>
          <div className="min-w-0">
            <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">{opts.label}</p>
            <p className="truncate text-xs text-slate-500 dark:text-slate-400">
              {!opts.enabled ? opts.hint : opts.count === 0 ? 'No recipients with this contact detail' : `${formatNumber(opts.count)} recipients`}
            </p>
          </div>
        </div>
        <Toggle checked={opts.checked && !disabled} onChange={opts.onChange} disabled={disabled} label={<span className="sr-only">{opts.label}</span>} />
      </div>
    );
  };

  const isDraft = n?.state === 'draft';
  const total = (email ? data?.audience.email ?? 0 : 0) + (sms ? data?.audience.sms ?? 0 : 0) + (wa ? data?.audience.whatsapp ?? 0 : 0);

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={busy}
      size="md"
      title={n && !isDraft ? 'Send notice to the audience' : 'Publish notice'}
      description={n ? <span className="line-clamp-2">{String(n.title)}</span> : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button icon={Send} loading={busy} disabled={!data || (!isDraft && !total && !notify)} onClick={() => void submit()}>
            {total ? `${isDraft ? 'Publish & send' : 'Send'} (${formatNumber(total)})` : isDraft ? (scheduled ? 'Schedule notice' : 'Publish notice') : notify ? 'Send in-app alerts' : 'Send'}
          </Button>
        </>
      }
    >
      {error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : isLoading || !data || !n ? (
        <PageLoader />
      ) : (
        <div className="space-y-4">
          <div className="rounded-xl bg-slate-50 px-4 py-3 text-sm dark:bg-slate-800/60">
            <p className="text-slate-500 dark:text-slate-400">Audience</p>
            <p className="font-semibold text-slate-900 dark:text-white">{String(n.audience_label)}</p>
            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
              {formatNumber(data.audience.people)} people{data.audience.breakdown.length > 1 ? ` · ${data.audience.breakdown.map((b) => `${formatNumber(b.count)} ${b.label.toLowerCase()}`).join(', ')}` : ''}
            </p>
            {scheduled && <p className="mt-2 text-xs font-medium text-brand-700 dark:text-brand-300">Appears on the website from {formatDateTime(n.publish_date)}. Messages are sent now.</p>}
          </div>
          {data.delivery.total_sent > 0 && (
            <Alert variant="warning" title="Already delivered">
              This notice was sent to {formatNumber(data.delivery.total_sent)} recipients{data.delivery.last_sent_at ? ` (last on ${formatDateTime(data.delivery.last_sent_at)})` : ''}. Sending again delivers a second copy.
            </Alert>
          )}
          <div className="space-y-2">
            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Send to the audience by</p>
            {channelRow({ icon: Mail, label: 'E-mail', count: data.audience.email, checked: email, onChange: setEmail, enabled: true })}
            {channelRow({ icon: MessageSquareText, label: 'SMS', count: data.audience.sms, checked: sms, onChange: setSms, enabled: !!ch?.sms, hint: 'SMS gateway is not enabled in Settings → Email & SMS' })}
            {channelRow({ icon: MessageCircle, label: 'WhatsApp', count: data.audience.whatsapp, checked: wa, onChange: setWa, enabled: !!ch?.whatsapp, hint: 'WhatsApp gateway is not enabled in Settings → Email & SMS' })}
          </div>
          <div className="rounded-xl border border-slate-200 px-3.5 py-3 dark:border-slate-700">
            <Toggle checked={notify} onChange={setNotify} label={<span className="inline-flex items-center gap-1.5"><BellRing className="h-4 w-4" /> In-app notification</span>} description="Alert ERP users who belong to this audience (bell icon in the top bar)." />
          </div>
        </div>
      )}
    </Modal>
  );
}

