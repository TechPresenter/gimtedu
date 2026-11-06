import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Bot, MessageCircle, MessagesSquare, RotateCcw } from 'lucide-react';
import { Alert, Badge, Button, Card, CardBody, DataTable, Drawer, EmptyState, Pagination, PersonCell, SearchInput, Select, Skeleton, useConfirm, useToast, type Column } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatPhone, timeAgo } from '@/lib/format';
import { WaText, useWaRefresh } from '../shared';
import { ACTION_LABEL, LANG_LABEL, PROVIDER_LABEL, STATE_META, confidenceTone, intentLabel, useBotSession, useBotSessions } from './api';
import { BotSessionCard } from './BotSessionCard';
import type { BotSessionRow } from './types';

/** Every contact the bot has talked to: language, state, qualification, summary, hand-over, link to the inbox. */
export function SessionsTab() {
  const [params, setParams] = useSearchParams();
  const [search, setSearch] = useState('');
  const [state, setState] = useState('');
  const [language, setLanguage] = useState('');
  const [qualified, setQualified] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const query = useMemo(
    () => ({ q: search || undefined, state: state || undefined, language: language || undefined, qualified: qualified || undefined, page, per_page: perPage }),
    [search, state, language, qualified, page, perPage],
  );
  const q = useBotSessions(query);
  const openId = Number(params.get('session')) || null;
  const open = (r: BotSessionRow) => {
    const next = new URLSearchParams(params);
    next.set('session', String(r.contact_id));
    setParams(next, { replace: true });
  };
  const close = () => {
    const next = new URLSearchParams(params);
    next.delete('session');
    setParams(next, { replace: true });
  };
  const filter = (fn: () => void) => {
    fn();
    setPage(1);
  };
  const counts = q.data?.counts ?? {};

  const columns: Column<BotSessionRow>[] = [
    {
      key: 'name',
      header: 'Contact',
      render: (r) => <PersonCell name={r.name || 'Unknown'} sub={r.phone ? formatPhone(`+${r.phone}`) : undefined} size="sm" />,
    },
    {
      key: 'state',
      header: 'State',
      render: (r) => (
        <div className="space-y-1">
          <Badge color={STATE_META[r.state]?.color ?? 'slate'} dot>
            {STATE_META[r.state]?.label ?? r.state}
          </Badge>
          {r.handover_label && (
            <p className="max-w-[12rem] truncate text-[11px] text-slate-500 dark:text-slate-400" title={r.handover_label}>
              {r.state === 'handed_over' ? r.handover_label : `Last hand-over: ${r.handover_label}`}
            </p>
          )}
        </div>
      ),
    },
    { key: 'language', header: 'Language', render: (r) => LANG_LABEL[r.language] ?? r.language },
    {
      key: 'qualification',
      header: 'Qualification',
      render: (r) => {
        const a = r.qualification;
        const bits = [a.program, a.city, a.qualification, a.intake].filter(Boolean);
        return bits.length ? (
          <div className="max-w-[16rem]">
            <p className="truncate text-sm text-slate-700 dark:text-slate-200">{bits.join(' · ')}</p>
            <p className="text-[11px] text-slate-500 dark:text-slate-400">{r.qualification_done ? 'Complete' : r.pending_question ? `Waiting for ${r.pending_question.replace(/_/g, ' ')}` : 'Partial'}</p>
          </div>
        ) : (
          <span className="text-slate-400">—</span>
        );
      },
    },
    { key: 'summary', header: 'Summary', className: 'min-w-[16rem] max-w-sm', render: (r) => <span className="line-clamp-2 text-xs text-slate-600 dark:text-slate-300">{(r.summary ?? '').split('\n').slice(1).join(' ') || r.summary || '—'}</span> },
    { key: 'messages_count', header: 'Bot turns', align: 'right', render: (r) => r.messages_count },
    { key: 'last', header: 'Last activity', render: (r) => <span className="whitespace-nowrap text-xs">{r.last_activity_at ? timeAgo(r.last_activity_at) : '—'}</span> },
  ];

  return (
    <Card>
      <CardBody className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <SearchInput value={search} onChange={(v) => filter(() => setSearch(v))} placeholder="Search name, phone, city, summary…" className="min-w-[12rem] flex-1" />
          <Select
            aria-label="State"
            value={state}
            onChange={(e) => filter(() => setState(e.target.value))}
            placeholder="All states"
            className="w-full sm:w-52"
            options={Object.entries(STATE_META).map(([value, m]) => ({ value, label: `${m.label}${counts[value as BotSessionRow['state']] !== undefined ? ` (${counts[value as BotSessionRow['state']]})` : ''}` }))}
          />
          <Select aria-label="Language" value={language} onChange={(e) => filter(() => setLanguage(e.target.value))} placeholder="Any language" className="w-full sm:w-44" options={LANG_LABEL} />
          <Select aria-label="Qualification" value={qualified} onChange={(e) => filter(() => setQualified(e.target.value))} placeholder="Any lead" className="w-full sm:w-44" options={{ '1': 'Qualified leads' }} />
        </div>
        {q.error ? (
          <Alert variant="error" action={<Button size="sm" variant="secondary" onClick={() => q.refetch()}>Retry</Button>}>
            {(q.error as ApiError).message}
          </Alert>
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={q.data?.rows ?? []}
              loading={q.isLoading}
              onRowClick={open}
              rowKey={(r) => r.id}
              empty={<EmptyState icon={MessagesSquare} title="No bot sessions" description={search || state || language || qualified ? 'Change the filters.' : 'Sessions appear when the bot answers its first WhatsApp chat.'} />}
            />
            {q.data && q.data.meta.total > 0 && (
              <Pagination page={q.data.meta.page} pages={q.data.meta.pages} total={q.data.meta.total} perPage={q.data.meta.per_page} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} />
            )}
          </>
        )}
      </CardBody>
      <SessionDrawer contactId={openId} onClose={close} />
    </Card>
  );
}

function SessionDrawer({ contactId, onClose }: { contactId: number | null; onClose: () => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useWaRefresh();
  const q = useBotSession(contactId);
  const s = q.data;
  const [busy, setBusy] = useState(false);

  const reset = async () => {
    if (!contactId) return;
    const ok = await confirm({ title: 'Restart the bot session?', message: 'Qualification answers, counters and the summary of this session are cleared. Contact details in the CRM stay as they are.', confirmText: 'Restart' });
    if (!ok) return;
    setBusy(true);
    try {
      const r = await api.post(`whatsapp/bot/sessions/${contactId}/reset`);
      toast.success(r.message);
      await refresh('bot');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Drawer
      open={!!contactId}
      onClose={onClose}
      width="max-w-2xl"
      title={s ? s.name || formatPhone(`+${s.phone}`) : 'Bot session'}
      description={s ? `${formatPhone(`+${s.phone}`)}${s.program_name ? ` · ${s.program_name}` : ''}${s.city ? ` · ${s.city}` : ''}` : undefined}
      footer={
        s ? (
          <>
            {can('whatsapp_automation', 'edit') && (
              <Button variant="secondary" icon={RotateCcw} loading={busy} onClick={reset}>
                Restart session
              </Button>
            )}
            {s.conversation_id && (
              <Button icon={MessageCircle} to={`/whatsapp/inbox?c=${s.conversation_id}`}>
                Open in inbox
              </Button>
            )}
          </>
        ) : undefined
      }
    >
      {q.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : q.error ? (
        <Alert variant="error">{(q.error as ApiError).message}</Alert>
      ) : !s ? (
        <EmptyState icon={Bot} title="No bot session" description="The bot has not chatted with this contact." />
      ) : (
        <div className="space-y-6">
          <BotSessionCard contactId={s.contact_id} session={s} />
          <div>
            <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Bot decisions</h3>
            {s.turns.length === 0 ? (
              <p className="text-sm text-slate-500 dark:text-slate-400">No turns recorded.</p>
            ) : (
              <ol className="space-y-3">
                {s.turns.map((t) => (
                  <li key={t.id} className="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                    <div className="mb-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                      <Badge color={t.handover ? 'amber' : t.answered ? 'green' : 'red'} dot>
                        {ACTION_LABEL[t.action] ?? t.action}
                      </Badge>
                      <Badge color="navy">{intentLabel(t.intent)}</Badge>
                      {t.provider && <Badge color="purple">{PROVIDER_LABEL[t.provider] ?? t.provider}</Badge>}
                      {t.confidence !== null && <Badge color={confidenceTone(t.confidence)}>{Math.round(t.confidence * 100)}% sure</Badge>}
                      <span className="ml-auto text-slate-400">{timeAgo(t.created_at)}</span>
                    </div>
                    {t.question && <p className="text-sm font-medium text-slate-800 dark:text-slate-100">“{t.question}”</p>}
                    {t.reply && (
                      <p className="mt-1 line-clamp-3 text-xs text-slate-600 dark:text-slate-300">
                        <WaText text={t.reply} />
                      </p>
                    )}
                    {t.facts.length > 0 && <p className="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">Facts: {t.facts.map((f) => f.title).join(' · ')}</p>}
                  </li>
                ))}
              </ol>
            )}
          </div>
        </div>
      )}
    </Drawer>
  );
}
