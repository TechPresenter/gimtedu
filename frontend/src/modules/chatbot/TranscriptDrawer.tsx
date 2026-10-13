import { AlertTriangle, ExternalLink, ThumbsDown, ThumbsUp, Trash2 } from 'lucide-react';
import clsx from 'clsx';
import { Alert, Badge, Button, CardSkeleton, DescriptionList, Drawer, StatusBadge } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { Link } from 'react-router-dom';
import { formatDateTime } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { LANG_LABELS, LEAD_LABELS, INTENT_LABELS, type ConversationDetail } from './types';
import { msLabel, ProviderBadge, ReplyText } from './shared';

interface Props {
  id: number | null;
  onClose: () => void;
  onDelete?: (id: number) => void;
}

const LEAD_COLORS = { none: 'slate', offered: 'amber', captured: 'green' } as const;

/** Transcript viewer: conversation metadata + every message with provider / model / latency / tokens / feedback. */
export function TranscriptDrawer({ id, onClose, onDelete }: Props) {
  const { data: c, isLoading, error } = useApi<ConversationDetail>(['chatbot', 'conversation', id], `chatbot/conversations/${id}`, undefined, { enabled: !!id });
  return (
    <Drawer
      open={!!id}
      onClose={onClose}
      title={c ? `Conversation #${c.id}` : 'Conversation'}
      description={c ? `${formatDateTime(c.started_at)} · ${c.user_message_count} question${c.user_message_count === 1 ? '' : 's'}` : undefined}
      width="max-w-2xl"
      footer={
        c && onDelete ? (
          <div className="flex justify-end">
            <Button variant="danger" icon={Trash2} onClick={() => onDelete(c.id)}>
              Delete conversation
            </Button>
          </div>
        ) : undefined
      }
    >
      {error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : isLoading || !c ? (
        <CardSkeleton lines={10} />
      ) : (
        <div className="space-y-6">
          <DescriptionList
            columns={2}
            items={[
              { label: 'Lead', value: <StatusBadge status={c.lead_status} label={LEAD_LABELS[c.lead_status]} colors={LEAD_COLORS} /> },
              {
                label: 'Enquiry',
                value: c.enquiry_id ? (
                  <Link to={`/enquiries?q=${encodeURIComponent(c.enquiry_phone ?? c.enquiry_name ?? '')}`} className="inline-flex items-center gap-1 font-medium text-brand-700 hover:underline dark:text-brand-300">
                    #{c.enquiry_id} {c.enquiry_name} <ExternalLink className="h-3 w-3" />
                  </Link>
                ) : c.lead_name ? (
                  c.lead_name
                ) : (
                  '—'
                ),
              },
              { label: 'Started on page', value: c.page_url || '—' },
              { label: 'Language', value: LANG_LABELS[c.language ?? 'en'] ?? c.language ?? '—' },
              { label: 'Device', value: c.device || '—' },
              { label: 'Visitor (hashed IP)', value: c.visitor ? <span className="font-mono text-xs">{c.visitor}…</span> : '—' },
              { label: 'Campaign', value: [c.utm_source, c.utm_medium, c.utm_campaign].filter(Boolean).join(' / ') || '—' },
              { label: 'Feedback', value: `👍 ${c.feedback_up} · 👎 ${c.feedback_down}` },
            ]}
          />
          <ol className="space-y-3 rounded-2xl bg-slate-50 p-3 sm:p-4 dark:bg-slate-900/60" aria-label="Transcript">
            {c.messages.map((m) => {
              if (m.role === 'event') {
                return (
                  <li key={m.id} className="text-center">
                    <span className="inline-block rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/30">{m.content}</span>
                  </li>
                );
              }
              const user = m.role === 'user';
              return (
                <li key={m.id} className={clsx('flex flex-col', user ? 'items-end' : 'items-start')}>
                  <div
                    className={clsx(
                      'max-w-[88%] rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed',
                      user ? 'rounded-br-md bg-[#D03E16] text-white' : 'rounded-bl-md bg-white text-slate-800 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700',
                    )}
                  >
                    {user ? <span className="whitespace-pre-wrap">{m.content}</span> : <ReplyText text={m.content} />}
                  </div>
                  <div className={clsx('mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400', user && 'justify-end')}>
                    <span>{formatDateTime(m.created_at)}</span>
                    {user && m.intent && <Badge color="slate">{INTENT_LABELS[m.intent] ?? m.intent}</Badge>}
                    {!user && (
                      <>
                        <ProviderBadge provider={m.provider} />
                        {m.model && <span className="font-mono">{m.model}</span>}
                        <span>{msLabel(m.latency_ms)}</span>
                        {m.input_tokens != null && <span>{m.input_tokens}/{m.output_tokens} tok{m.cached_tokens ? ` (${m.cached_tokens} cached)` : ''}</span>}
                        {m.feedback === 1 && <ThumbsUp className="h-3.5 w-3.5 text-emerald-600" aria-label="Helpful" />}
                        {m.feedback === -1 && <ThumbsDown className="h-3.5 w-3.5 text-red-600" aria-label="Not helpful" />}
                      </>
                    )}
                  </div>
                  {!user && m.error && (
                    <p className="mt-1 flex max-w-[88%] items-start gap-1 text-[11px] text-amber-700 dark:text-amber-300">
                      <AlertTriangle className="mt-px h-3 w-3 shrink-0" /> {m.error}
                    </p>
                  )}
                </li>
              );
            })}
          </ol>
        </div>
      )}
    </Drawer>
  );
}
