import { useState, type FormEvent } from 'react';
import { FlaskConical, Send } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Input } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { INTENT_LABELS, LANG_LABELS, type TryResult } from './types';
import { msLabel, ProviderBadge, ReplyText } from './shared';

const SAMPLES = ['What is the fee for BBA?', 'MBA eligibility kya hai?', 'बी.टेक की फीस कितनी है?', 'Is hostel available for girls?', 'Do you offer a PhD?'];

/** Ask the assistant from the admin (uses the saved settings, nothing is stored) — shows provider, latency and errors. */
export function TryAssistant() {
  const [q, setQ] = useState('');
  const [busy, setBusy] = useState(false);
  const [res, setRes] = useState<TryResult | null>(null);
  const [err, setErr] = useState('');
  const [history, setHistory] = useState<{ role: string; content: string }[]>([]);

  const ask = async (question: string, e?: FormEvent) => {
    e?.preventDefault();
    if (!question.trim() || busy) return;
    setBusy(true);
    setErr('');
    try {
      const r = await api.post<TryResult>('chatbot/try', { message: question, history });
      setRes(r.data);
      setHistory((h) => [...h, { role: 'user', content: question }, { role: 'assistant', content: r.data.reply }].slice(-10));
      setQ('');
    } catch (x) {
      setErr((x as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader title="Try the assistant" subtitle="Ask a question with the saved settings. Nothing is stored." icon={FlaskConical} />
      <div className="card-body space-y-3">
        <form onSubmit={(e) => void ask(q, e)} className="flex gap-2">
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="e.g. What is the MBA fee?" aria-label="Test question" maxLength={2000} className="flex-1" />
          <Button type="submit" icon={Send} loading={busy} disabled={!q.trim()}>
            Ask
          </Button>
        </form>
        <div className="flex flex-wrap gap-1.5">
          {SAMPLES.map((s) => (
            <button key={s} type="button" onClick={() => void ask(s)} disabled={busy} className="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-200 disabled:opacity-50 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700">
              {s}
            </button>
          ))}
        </div>
        {err && <Alert variant="error">{err}</Alert>}
        {res && (
          <div className="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-100">
            <ReplyText text={res.reply} />
            <div className="flex flex-wrap items-center gap-1.5 border-t border-slate-200 pt-2 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">
              <ProviderBadge provider={res.provider} />
              {res.model && <span className="font-mono">{res.model}</span>}
              <span>· {msLabel(res.latency_ms)}</span>
              <span>· {INTENT_LABELS[res.intent] ?? res.intent}</span>
              <span>· {LANG_LABELS[res.lang] ?? res.lang}</span>
              {res.usage && <span>· {res.usage.input} in / {res.usage.output} out{res.usage.cached ? ` / ${res.usage.cached} cached` : ''} tokens</span>}
              {res.lead_prompt && <Badge color="amber">Callback offered</Badge>}
            </div>
            {res.errors.length > 0 && (
              <ul className="space-y-1 text-xs text-red-700 dark:text-red-300">
                {res.errors.map((x) => (
                  <li key={x}>⚠ {x}</li>
                ))}
              </ul>
            )}
          </div>
        )}
      </div>
    </Card>
  );
}
