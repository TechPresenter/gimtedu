import { useState } from 'react';
import { Bot, RefreshCw, Sparkles } from 'lucide-react';
import { Badge, Button, CardSkeleton, DescriptionList, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, timeAgo } from '@/lib/format';
import { useWaRefresh } from '../shared';
import { LANG_LABEL, STATE_META, useBotSession } from './api';
import type { BotSessionDetail } from './types';

/**
 * AI bot summary of one contact: state, language, qualification answers, conversation summary (+ refresh). Reusable —
 * the inbox right panel can embed it: <BotSessionCard contactId={conv.contact_id} compact />.
 */
export function BotSessionCard({ contactId, compact = false, session }: { contactId: number; compact?: boolean; session?: BotSessionDetail | null }) {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useWaRefresh();
  const q = useBotSession(session === undefined ? contactId : null);
  const s = session !== undefined ? session : q.data;
  const [busy, setBusy] = useState(false);

  const regenerate = async () => {
    setBusy(true);
    try {
      const r = await api.post(`whatsapp/bot/sessions/${contactId}/summary`);
      toast.success(r.message);
      await refresh('bot');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  if (session === undefined && q.isLoading) return <CardSkeleton lines={4} />;
  if (!s) {
    return (
      <p className="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
        <Bot className="h-4 w-4" aria-hidden /> The AI bot has not chatted with this contact yet.
      </p>
    );
  }
  const meta = STATE_META[s.state] ?? { label: s.state, color: 'slate' as const };
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-1.5">
        <Badge color={meta.color} dot>
          {meta.label}
        </Badge>
        <Badge color="navy">{LANG_LABEL[s.language] ?? s.language}</Badge>
        {s.qualification_done && <Badge color="green">Qualified lead</Badge>}
        {s.pending_question && <Badge color="blue">Waiting for {s.pending_question.replace(/_/g, ' ')}</Badge>}
      </div>
      {s.handover_label && (
        <p className={s.state === 'handed_over' ? 'text-xs text-amber-700 dark:text-amber-400' : 'text-xs text-slate-500 dark:text-slate-400'}>
          {s.state === 'handed_over' ? 'Handed over' : 'Last hand-over (chat handed back to the bot)'}: {s.handover_label}
          {s.handed_over_at ? ` · ${timeAgo(s.handed_over_at)}` : ''}
        </p>
      )}
      {s.qualification_items.length > 0 && <DescriptionList columns={compact ? 1 : 2} items={s.qualification_items.map((i) => ({ label: i.label, value: i.value }))} />}
      <div className="rounded-xl border border-violet-200/70 bg-violet-50/60 p-3 dark:border-violet-500/20 dark:bg-violet-500/10">
        <div className="mb-1.5 flex items-center justify-between gap-2">
          <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-300">
            <Sparkles className="h-3.5 w-3.5" aria-hidden /> Conversation summary
          </p>
          {(can('whatsapp_automation', 'edit') || can('whatsapp', 'edit')) && (
            <Button size="xs" variant="ghost" icon={RefreshCw} loading={busy} onClick={regenerate}>
              Refresh
            </Button>
          )}
        </div>
        {s.summary ? (
          <p className="whitespace-pre-line text-sm leading-relaxed text-slate-700 dark:text-slate-200">{s.summary}</p>
        ) : (
          <p className="text-sm text-slate-500 dark:text-slate-400">No summary yet — refresh to create one.</p>
        )}
      </div>
      {!compact && (
        <p className="text-xs text-slate-500 dark:text-slate-400">
          {s.messages_count} message{s.messages_count === 1 ? '' : 's'} handled by the bot · last activity {s.last_activity_at ? formatDateTime(s.last_activity_at) : '—'}
          {s.assigned_name ? ` · assigned to ${s.assigned_name}` : ''}
        </p>
      )}
    </div>
  );
}
