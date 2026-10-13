import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Copy, ExternalLink, Eye, FileDown, Pencil, Pin, PinOff, Printer, RotateCcw, Send, Trash2, Undo2 } from 'lucide-react';
import { Alert, Badge, Button, DescriptionList, Drawer, Dropdown, PageLoader, StatusBadge, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { ChannelBadge, NOTICE_CATEGORIES, NOTICE_STATES, openApiPrint, useCommRefresh } from '../shared';
import type { NoticeDetail } from './types';

interface Props {
  id: number | null;
  onClose: () => void;
  onEdit: (id: number) => void;
  onPublish: (id: number) => void;
  onResume: (id: number, title: string) => void;
}

/** Notice details: content, audience, schedule, delivery statistics and the per-recipient delivery log. */
export function NoticeDrawer({ id, onClose, onEdit, onPublish, onResume }: Props) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const refresh = useCommRefresh();
  const { data, isLoading, error } = useApi<NoticeDetail>(['comm', 'notice', id], `notices/${id}`, undefined, { enabled: !!id });
  const [busy, setBusy] = useState(false);
  const n = data?.notice;

  const act = async (fn: () => Promise<{ message: string }>) => {
    setBusy(true);
    try {
      const res = await fn();
      toast.success(res.message);
      await refresh('crud');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!n) return;
    const ok = await confirm({ title: 'Delete notice?', message: <>Delete <strong>{String(n.title)}</strong>? It disappears from the website and the notice board. This cannot be undone.</>, confirmText: 'Delete', danger: true });
    if (!ok) return;
    await act(() => api.del(`crud/notices/${n.id}`));
    onClose();
  };

  const duplicate = async () => {
    if (!n) return;
    setBusy(true);
    try {
      const res = await api.post<{ id: number }>(`notices/${n.id}/duplicate`);
      toast.success(res.message);
      await refresh('crud');
      onEdit(res.data.id);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  const state = String(n?.state ?? '');
  const cat = NOTICE_CATEGORIES[String(n?.category)] ?? { label: String(n?.category ?? ''), color: 'slate' as const };

  return (
    <Drawer
      open={!!id}
      onClose={onClose}
      width="max-w-3xl"
      title={n ? String(n.title) : 'Notice'}
      description={n ? <span className="flex flex-wrap items-center gap-1.5 pt-1"><Badge color={cat.color}>{cat.label}</Badge><StatusBadge status={state} label={NOTICE_STATES[state]?.label} colors={{ [state]: NOTICE_STATES[state]?.color ?? 'slate' }} />{n.is_pinned && <Badge color="navy"><Pin className="h-3 w-3" />Pinned</Badge>}</span> : undefined}
      footer={
        n && (
          <div className="flex w-full flex-wrap items-center justify-between gap-2">
            <Dropdown
              align="left"
              trigger={<>More</>}
              items={[
                { label: 'Print circular', icon: Printer, onClick: () => openApiPrint(`notices/${n.id}/print`) },
                can('notices', 'create') && { label: 'Duplicate as draft', icon: Copy, onClick: () => void duplicate() },
                can('notices', 'edit') && { label: n.is_pinned ? 'Unpin' : 'Pin to top', icon: n.is_pinned ? PinOff : Pin, onClick: () => void act(() => api.post(`notices/${n.id}/pin`, { pinned: !n.is_pinned })) },
                can('notices', 'publish') && (state === 'live' || state === 'scheduled') && { label: 'Unpublish (back to draft)', icon: Undo2, onClick: () => void act(() => api.post(`notices/${n.id}/unpublish`)) },
                n.show_on_website && state === 'live' && { label: 'View on website', icon: ExternalLink, href: String(n.public_url), target: '_blank' },
                can('notices', 'delete') && { divider: true, label: '' },
                can('notices', 'delete') && { label: 'Delete notice', icon: Trash2, danger: true, onClick: () => void remove() },
              ]}
            />
            <div className="flex flex-wrap gap-2">
              {can('notices', 'edit') && (
                <Button variant="secondary" icon={Pencil} onClick={() => onEdit(Number(n.id))} disabled={busy}>
                  Edit
                </Button>
              )}
              {can('notices', 'publish') && state !== 'expired' && (
                <Button icon={Send} onClick={() => onPublish(Number(n.id))} disabled={busy}>
                  {state === 'draft' ? 'Publish…' : 'Send…'}
                </Button>
              )}
            </div>
          </div>
        )
      }
    >
      {error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : isLoading || !data || !n ? (
        <PageLoader />
      ) : (
        <div className="space-y-6">
          <DescriptionList
            items={[
              { label: 'Audience', value: String(n.audience_label) },
              { label: 'Reach', value: `${formatNumber(data.audience.people)} people · ${formatNumber(data.audience.email)} e-mail addresses` },
              { label: 'Publish date', value: n.publish_date ? formatDateTime(n.publish_date) : state === 'draft' ? 'Not published yet' : 'Immediately' },
              { label: 'Expires', value: n.expiry_date ? formatDateTime(n.expiry_date) : 'No expiry' },
              { label: 'Website', value: n.show_on_website ? <span className="inline-flex items-center gap-1.5"><Eye className="h-3.5 w-3.5 text-slate-400" />Shown · {formatNumber(n.views)} views</span> : 'Hidden from the website' },
              { label: 'Created by', value: `${n.created_by_name ?? 'System'} · ${timeAgo(n.created_at)}` },
            ]}
          />
          {n.attachment_url && (
            <a href={appUrl(String(n.attachment_url))} target="_blank" rel="noreferrer" className="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm transition hover:border-brand-300 hover:bg-brand-50/40 dark:border-slate-700 dark:hover:bg-slate-800">
              <FileDown className="h-5 w-5 text-brand-700 dark:text-brand-300" />
              <span className="min-w-0 flex-1 truncate font-medium text-slate-800 dark:text-slate-100">{String(n.attachment_name)}</span>
              <span className="text-xs text-slate-500">Download</span>
            </a>
          )}
          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Notice</h3>
            <div
              className="rounded-xl border border-slate-200 bg-white p-4 text-sm leading-relaxed text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 [&_a]:text-brand-700 [&_a]:underline [&_li]:mt-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_p+p]:mt-2 [&_p]:mt-1 [&_ul]:mt-2 [&_ul]:list-disc [&_ul]:pl-5"
              dangerouslySetInnerHTML={{ __html: String(n.description || '<p class="text-slate-400">No description.</p>') }}
            />
          </section>
          <section>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Delivery</h3>
              {data.delivery.last_sent_at && <span className="text-xs text-slate-500">Last sent {formatDateTime(data.delivery.last_sent_at)}</span>}
            </div>
            {data.delivery.pending > 0 && (
              <Alert
                variant="warning"
                className="mb-3"
                title={`${formatNumber(data.delivery.pending)} messages waiting to be sent`}
                action={can('notices', 'publish') ? <Button size="sm" icon={RotateCcw} onClick={() => onResume(Number(n.id), String(n.title))}>Resume</Button> : undefined}
              >
                Sending was paused before all messages went out.
              </Alert>
            )}
            {data.delivery.channels.length === 0 ? (
              <p className="rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-700">
                This notice has not been sent by e-mail, SMS or WhatsApp yet.{can('notices', 'publish') && state !== 'expired' ? ' Use “Send…” to deliver it to the audience.' : ''}
              </p>
            ) : (
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                {data.delivery.channels.map((c) => (
                  <div key={c.channel} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                    <ChannelBadge channel={c.channel} />
                    <p className="mt-2 font-display text-xl font-bold tabular-nums text-slate-900 dark:text-white">{formatNumber(c.sent)}</p>
                    <p className="text-xs text-slate-500">delivered{c.failed ? <span className="text-red-600 dark:text-red-400"> · {formatNumber(c.failed)} failed</span> : null}{c.queued ? ` · ${formatNumber(c.queued)} queued` : null}</p>
                  </div>
                ))}
              </div>
            )}
          </section>
          {data.delivery.channels.length > 0 && can('communication', 'view') && (
            <CrudTable
              module="message_logs"
              scope={{ related_type: 'notice', related_id: Number(n.id) }}
              title="Delivery log"
              hideColumns={['related_label']}
              hideFilters={['related_type', 'campaign_id']}
              onView={(r) => navigate(`/message-logs?view=${r.id}`)}
              className="!shadow-none"
            />
          )}
        </div>
      )}
    </Drawer>
  );
}
