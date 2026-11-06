import { useEffect, useRef, useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { Bot, BookOpen, Gauge, Info, RotateCcw, SendHorizontal, UserRound } from 'lucide-react';
import { Alert, Badge, Button, Card, CardBody, CardHeader, DescriptionList, EmptyState, Field, Input, ProgressBar, Select, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { WaBubble, WaPhoneFrame } from '../shared';
import { ACTION_LABEL, LANG_LABEL, PROVIDER_LABEL, confidenceTone, intentLabel } from './api';
import type { BotDecision, BotOutMessage, BotTestResult, BotTestState } from './types';

interface Line {
  id: number;
  from: 'user' | 'bot' | 'note';
  msg?: BotOutMessage;
  text?: string;
  decision?: BotDecision;
}

const SAMPLES = ['Hi', 'BBA ki fees kitni hai?', 'What documents are required?', 'एमबीए के लिए योग्यता क्या है?', 'Is there an NRI quota?', 'I want to talk to a counsellor'];

/**
 * Chat simulator: runs the same decision engine as live WhatsApp chats (language, qualification, answers, hand-over)
 * without sending or storing anything. Shows which verified facts were used and the confidence of every reply.
 */
export function TestConsole({ enabled }: { enabled: boolean }) {
  const toast = useToast();
  const [lines, setLines] = useState<Line[]>([]);
  const [state, setState] = useState<BotTestState>({});
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [contactType, setContactType] = useState('lead');
  const [name, setName] = useState('');
  const [selected, setSelected] = useState<number | null>(null);
  const seq = useRef(0);
  const scroller = useRef<HTMLDivElement>(null);

  useEffect(() => {
    scroller.current?.scrollTo({ top: scroller.current.scrollHeight, behavior: 'smooth' });
  }, [lines, busy]);

  const history = () =>
    lines
      .filter((l) => l.from !== 'note')
      .map((l) => ({ role: l.from === 'user' ? 'user' : 'assistant', content: l.from === 'user' ? (l.text ?? '') : (l.msg?.body ?? '') }));

  const send = async (message: string, payload?: string) => {
    const m = message.trim();
    if ((!m && !payload) || busy) return;
    setError(null);
    const userLine: Line = { id: ++seq.current, from: 'user', text: m || payload };
    setLines((ls) => [...ls, userLine]);
    setText('');
    setBusy(true);
    try {
      const r = await api.post<BotTestResult>('whatsapp/bot/test', { message: m, payload, state, history: history(), contact: { contact_type: contactType, name } });
      const d = r.data.decision;
      setState(r.data.state);
      const botLines: Line[] = r.data.messages.map((msg) => ({ id: ++seq.current, from: 'bot', msg, decision: d }));
      const extra: Line[] = d.handover
        ? [{ id: ++seq.current, from: 'note', text: `Handed over — ${d.handover}${d.handover_to ? ` · assigned to ${d.handover_to}` : ''}. The bot now stays silent in this chat.`, decision: d }]
        : d.note
          ? [{ id: ++seq.current, from: 'note', text: d.note, decision: d }]
          : d.qualified
            ? [{ id: ++seq.current, from: 'note', text: 'Lead qualified — in live chats the answers are saved to the contact, the admission enquiry is created or linked (Settings › Lead qualification) and the counsellor is notified. This test saves nothing.', decision: d }]
            : [];
      setLines((ls) => [...ls.map((l) => (l.id === userLine.id ? { ...l, decision: d } : l)), ...botLines, ...extra]);
      setSelected(userLine.id);
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.message ?? err.message);
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };

  const reset = () => {
    setLines([]);
    setState({});
    setSelected(null);
    setError(null);
  };

  const submit = (e: FormEvent) => {
    e.preventDefault();
    void send(text);
  };

  const current = lines.find((l) => l.id === selected)?.decision ?? [...lines].reverse().find((l) => l.decision)?.decision ?? null;
  const answers = state.answers ?? {};

  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,360px)_1fr]">
      <div className="space-y-3">
        <WaPhoneFrame
          height={600}
          subtitle={enabled ? 'AI assistant · test chat' : 'Bot is off — test chat only'}
          bodyClassName="!p-0"
          dateLabel={null}
          footer={
            <form onSubmit={submit} className="flex items-center gap-1.5 bg-[#F0F2F5] px-2 py-2 dark:bg-[#1F2C34]">
              <label htmlFor="bot-test-input" className="sr-only">
                Message
              </label>
              <input
                id="bot-test-input"
                value={text}
                onChange={(e) => setText(e.target.value)}
                maxLength={1000}
                placeholder="Type a message"
                autoComplete="off"
                className="min-w-0 flex-1 rounded-full border-0 bg-white px-3.5 py-2 text-sm text-slate-800 shadow-sm placeholder:text-slate-400 focus:ring-2 focus:ring-[#25D366] dark:bg-[#2A3942] dark:text-slate-100"
              />
              <button
                type="submit"
                disabled={busy || !text.trim()}
                aria-label="Send"
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#00A884] text-white transition hover:bg-[#008f6f] disabled:opacity-50"
              >
                <SendHorizontal className="h-4 w-4" />
              </button>
            </form>
          }
        >
          <div ref={scroller} className="h-full space-y-1.5 overflow-y-auto px-2.5 py-3">
            {lines.length === 0 && (
              <div className="mx-auto mt-6 max-w-[15rem] rounded-lg bg-[#FFF5C4] px-3 py-2 text-center text-[11.5px] leading-snug text-slate-700 shadow-sm dark:bg-[#182229] dark:text-[#FFD279]">
                Type as a student would. Nothing is sent to WhatsApp and nothing is stored.
              </div>
            )}
            {lines.map((l) =>
              l.from === 'note' ? (
                <div key={l.id} className="mx-auto my-2 max-w-[17rem] rounded-lg bg-white/90 px-3 py-1.5 text-center text-[11px] text-slate-600 shadow-sm dark:bg-[#182229] dark:text-slate-300">
                  {l.text}
                </div>
              ) : (
                <div
                  key={l.id}
                  role="button"
                  tabIndex={0}
                  onClick={() => setSelected(l.from === 'user' ? l.id : (lines.filter((x) => x.from === 'user' && x.id < l.id).pop()?.id ?? null))}
                  onKeyDown={(e) => e.key === 'Enter' && setSelected(l.id)}
                  className={clsx('rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-[#25D366]', selected === l.id && l.from === 'user' && 'ring-2 ring-accent-400/60')}
                >
                  {l.from === 'user' ? (
                    <WaBubble direction="out" type="text" body={l.text} status="read" showStatus />
                  ) : (
                    <WaBubble
                      direction="in"
                      type={l.msg?.type ?? 'text'}
                      body={l.msg?.body}
                      interactive={l.msg?.interactive ?? null}
                      onButtonClick={(b) => void send(b.title, b.id)}
                    />
                  )}
                </div>
              ),
            )}
            {busy && (
              <div className="flex w-16 items-center justify-center gap-1 rounded-lg rounded-tl-none bg-white px-3 py-2.5 shadow-sm dark:bg-[#202C33]" aria-label="Bot is typing">
                <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400" />
                <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400 [animation-delay:120ms]" />
                <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400 [animation-delay:240ms]" />
              </div>
            )}
          </div>
        </WaPhoneFrame>
        {error && <Alert variant="error">{error}</Alert>}
        <div className="flex flex-wrap justify-center gap-1.5">
          {SAMPLES.map((s) => (
            <button
              key={s}
              type="button"
              disabled={busy}
              onClick={() => void send(s)}
              className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-accent-300 hover:text-accent-700 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-accent-500/50"
            >
              {s}
            </button>
          ))}
        </div>
      </div>

      <div className="space-y-5">
        <Card>
          <CardHeader
            title="Simulated contact"
            subtitle="Qualification runs for new leads only"
            icon={UserRound}
            actions={
              <Button size="sm" variant="secondary" icon={RotateCcw} onClick={reset} disabled={busy || !lines.length}>
                New chat
              </Button>
            }
          />
          <CardBody className="grid gap-4 sm:grid-cols-2">
            <Field label="Contact type" htmlFor="bot-test-type" hint="Change it before the first message.">
              <Select id="bot-test-type" value={contactType} onChange={(e) => setContactType(e.target.value)} options={{ lead: 'New lead', applicant: 'Applicant', student: 'Student', guardian: 'Parent / guardian' }} />
            </Field>
            <Field label="WhatsApp profile name" htmlFor="bot-test-name" hint="Empty = the bot asks for the name.">
              <Input id="bot-test-name" value={name} maxLength={60} onChange={(e) => setName(e.target.value)} placeholder="e.g. Priya Singh" />
            </Field>
            <div className="sm:col-span-2">
              <DescriptionList
                columns={3}
                items={[
                  { label: 'Language', value: state.lang ? LANG_LABEL[state.lang] : '—' },
                  { label: 'Waiting for', value: (state.flow?.pending as string | undefined) ? String(state.flow?.pending).replace(/_/g, ' ') : '—' },
                  { label: 'Qualified', value: state.flow?.done ? <Badge color="green">Yes</Badge> : 'No' },
                  ...Object.entries(answers).map(([k, v]) => ({ label: k.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()), value: v })),
                ]}
              />
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="How the bot decided" subtitle="Click a message in the chat to inspect it" icon={Gauge} />
          <CardBody>
            {!current ? (
              <EmptyState icon={Bot} title="Send a message" description="The decision, confidence and the verified facts used for each reply appear here." />
            ) : (
              <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-1.5">
                  <Badge color={current.handover ? 'amber' : current.action === 'fallback' ? 'red' : 'green'} dot>
                    {ACTION_LABEL[current.action] ?? current.action}
                  </Badge>
                  <Badge color="navy">{intentLabel(current.intent)}</Badge>
                  {current.provider && <Badge color="purple">{PROVIDER_LABEL[current.provider] ?? current.provider}</Badge>}
                  <Badge color="slate">{LANG_LABEL[current.lang] ?? current.lang}</Badge>
                  <span className="text-xs text-slate-500 dark:text-slate-400">{current.latency_ms} ms</span>
                </div>
                {current.confidence !== null && (
                  <div>
                    <div className="mb-1 flex items-center justify-between text-sm">
                      <span className="font-medium text-slate-700 dark:text-slate-200">Confidence</span>
                      <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{Math.round(current.confidence * 100)}%</span>
                    </div>
                    <ProgressBar value={current.confidence * 100} tone={confidenceTone(current.confidence) === 'green' ? 'green' : confidenceTone(current.confidence) === 'amber' ? 'amber' : 'red'} />
                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Below the minimum confidence the bot says it is not sure and offers a counsellor; repeated misses hand the chat over.</p>
                  </div>
                )}
                {current.handover && (
                  <Alert variant="warning" title="Hand-over">
                    {current.handover}. {current.handover_to ? `Assigned to ${current.handover_to}` : 'Assigned to the admission team'} (round-robin over {current.pool_size || 0} counsellor{current.pool_size === 1 ? '' : 's'}), notified in-app.
                  </Alert>
                )}
                <div>
                  <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    <BookOpen className="h-3.5 w-3.5" aria-hidden /> Verified facts used
                  </p>
                  {current.facts.length ? (
                    <ul className="space-y-1.5">
                      {current.facts.map((f, i) => (
                        <li key={i} className="flex items-start justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
                          <span className="min-w-0 text-slate-700 dark:text-slate-200">{f.title}</span>
                          <Badge color={f.type === 'whatsapp_kb' ? 'green' : f.type === 'program' || f.type === 'fees' ? 'blue' : 'slate'}>{f.type.replace(/_/g, ' ')}</Badge>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                      {current.intent === 'promise'
                        ? 'Fixed safety reply — the bot never promises admission, seats, scholarships or placements.'
                        : current.action === 'answer' && ['greeting', 'thanks', 'bye', 'offtopic', 'abuse'].includes(current.intent ?? '')
                          ? 'Small talk — no institute facts needed.'
                          : 'No institute facts were needed for this step.'}
                    </p>
                  )}
                </div>
                {(current.stage || Object.keys(current.contact_updates ?? {}).length > 0) && (
                  <p className="flex items-start gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                    <Info className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden />
                    In a live chat the contact is updated:{' '}
                    {[...Object.entries(current.contact_updates ?? {}).map(([k, v]) => `${k.replace(/_/g, ' ')} = ${String(v)}`), current.stage ? `stage → ${current.stage}` : null].filter(Boolean).join(', ')}
                  </p>
                )}
                {current.errors.length > 0 && <Alert variant="warning">{current.errors.join(' ')}</Alert>}
              </div>
            )}
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
