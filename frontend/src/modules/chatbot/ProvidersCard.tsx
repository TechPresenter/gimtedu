import { useState } from 'react';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, CheckCircle2, Cpu, ExternalLink, KeyRound, PlugZap, Trash2, XCircle } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Field, Input, Select } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import type { ChatbotValues, ProviderInfo, TestResult } from './types';
import { msLabel } from './shared';

interface Props {
  providers: ProviderInfo[];
  chain: string[];
  form: ChatbotValues;
  set: <K extends keyof ChatbotValues>(k: K, v: ChatbotValues[K]) => void;
  keys: Record<string, string>;
  setKey: (p: string, v: string) => void;
  clear: Record<string, boolean>;
  setClear: (p: string, v: boolean) => void;
  errors: Record<string, string>;
}

const PRIMARY_OPTIONS = [
  { value: 'anthropic', label: 'Anthropic Claude (recommended)' },
  { value: 'openai', label: 'OpenAI' },
  { value: 'gemini', label: 'Google Gemini' },
  { value: 'offline', label: 'Rule-based only (no AI, no cost)' },
];

/** Primary provider, fallback order and one panel per provider: model, masked key (replace / remove), test connection. */
export function ProvidersCard({ providers, chain, form, set, keys, setKey, clear, setClear, errors }: Props) {
  const { can } = useAuth();
  const [tests, setTests] = useState<Record<string, TestResult | null>>({});
  const [testing, setTesting] = useState<string | null>(null);
  const byKey = Object.fromEntries(providers.map((p) => [p.key, p]));
  const order = form.fallback_order.filter((k) => byKey[k]);

  const move = (i: number, dir: -1 | 1) => {
    const next = [...order];
    const j = i + dir;
    if (j < 0 || j >= next.length) return;
    [next[i], next[j]] = [next[j], next[i]];
    set('fallback_order', next);
  };

  const test = async (p: ProviderInfo) => {
    setTesting(p.key);
    setTests((t) => ({ ...t, [p.key]: null }));
    try {
      const res = await api.post<TestResult>('chatbot/test', { provider: p.key, model: form[`${p.key}_model` as keyof ChatbotValues], key: keys[p.key] || undefined });
      setTests((t) => ({ ...t, [p.key]: res.data }));
    } catch (e) {
      setTests((t) => ({ ...t, [p.key]: { ok: false, message: (e as ApiError).message, latency_ms: 0, model: '' } }));
    } finally {
      setTesting(null);
    }
  };

  return (
    <Card>
      <CardHeader title="AI providers" subtitle="The primary provider answers first; on an error or timeout the next one in the fallback order is tried, and the rule-based assistant always answers last." icon={Cpu} />
      <div className="card-body space-y-5">
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Primary provider" htmlFor="cb-provider" error={errors.provider}>
            <Select id="cb-provider" value={form.provider} onChange={(e) => set('provider', e.target.value as ChatbotValues['provider'])} options={PRIMARY_OPTIONS} />
          </Field>
          <Field label="Fallback order" hint={chain.length ? `Active chain: ${chain.map((k) => byKey[k]?.label ?? k).join(' → ')} → rule-based` : 'No API key configured yet — the rule-based assistant answers every question.'}>
            <ol className="space-y-1.5">
              {order.map((k, i) => (
                <li key={k} className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900">
                  <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{i + 1}</span>
                  <span className="flex-1 font-medium text-slate-800 dark:text-slate-100">{byKey[k].label}</span>
                  {!byKey[k].status.configured && <span className="text-xs text-slate-400">no key</span>}
                  <button type="button" onClick={() => move(i, -1)} disabled={i === 0} className="rounded p-1 text-slate-500 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" aria-label={`Move ${byKey[k].label} up`}>
                    <ArrowUp className="h-3.5 w-3.5" />
                  </button>
                  <button type="button" onClick={() => move(i, 1)} disabled={i === order.length - 1} className="rounded p-1 text-slate-500 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" aria-label={`Move ${byKey[k].label} down`}>
                    <ArrowDown className="h-3.5 w-3.5" />
                  </button>
                </li>
              ))}
            </ol>
          </Field>
        </div>

        <div className="space-y-3">
          {providers.map((p) => {
            const modelKey = `${p.key}_model` as keyof ChatbotValues;
            const result = tests[p.key];
            const st = p.status;
            const removing = !!clear[p.key];
            return (
              <div key={p.key} className={clsx('rounded-2xl border p-4', form.provider === p.key ? 'border-accent-300 bg-accent-50/40 dark:border-accent-700/60 dark:bg-accent-900/10' : 'border-slate-200 dark:border-slate-700')}>
                <div className="flex items-start justify-between gap-2">
                  <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                    <p className="font-semibold text-slate-900 dark:text-white">{p.label}{form.provider === p.key && <span className="ml-2 text-xs font-medium text-accent-700 dark:text-accent-300">primary</span>}</p>
                    <a href={p.console} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-brand-700 hover:underline dark:text-brand-300">
                      Get an API key <ExternalLink className="h-3 w-3" />
                    </a>
                  </div>
                  {st.configured && !removing ? (
                    <Badge color="green" dot>{st.source === 'env' ? 'Key from env' : 'Key saved'}</Badge>
                  ) : st.undecryptable ? (
                    <Badge color="red" dot>Re-enter key</Badge>
                  ) : (
                    <Badge color="slate" dot>{removing ? 'Will be removed' : 'Not configured'}</Badge>
                  )}
                </div>
                <div className="mt-3 grid gap-3 md:grid-cols-2">
                  <Field label="Model" htmlFor={`cb-${p.key}-model`} error={errors[modelKey]} hint={`Default: ${p.default_model}`}>
                    <Input id={`cb-${p.key}-model`} list={`cb-${p.key}-models`} value={String(form[modelKey] ?? '')} onChange={(e) => set(modelKey, e.target.value as never)} inputSize="sm" />
                    <datalist id={`cb-${p.key}-models`}>
                      {p.models.map((m) => (
                        <option key={m.id} value={m.id}>{m.label}</option>
                      ))}
                    </datalist>
                  </Field>
                  <Field
                    label="API key"
                    htmlFor={`cb-${p.key}-key`}
                    error={errors[`${p.key}_key`]}
                    hint={
                      st.source === 'env'
                        ? `Set by the ${st.env} environment variable (${st.hint}) — it overrides any saved key.`
                        : st.configured
                          ? `Saved and encrypted (${st.hint}). Paste a new key to replace it.`
                          : `Stored encrypted on the server, never shown again. Or set ${st.env}.`
                    }
                  >
                    <Input
                      id={`cb-${p.key}-key`}
                      type="password"
                      autoComplete="new-password"
                      spellCheck={false}
                      value={keys[p.key] ?? ''}
                      onChange={(e) => setKey(p.key, e.target.value)}
                      placeholder={st.configured ? 'Replace key…' : 'Paste API key'}
                      prefix={<KeyRound className="h-3.5 w-3.5" />}
                      inputSize="sm"
                      disabled={!can('communication', 'edit')}
                    />
                  </Field>
                  <div className="flex flex-wrap items-center gap-2 md:col-span-2">
                    <Button size="sm" variant="secondary" icon={PlugZap} loading={testing === p.key} disabled={!can('communication', 'edit') || (testing !== null && testing !== p.key)} onClick={() => void test(p)}>
                      Test connection
                    </Button>
                    {st.source === 'settings' && can('communication', 'edit') && (
                      <Button size="sm" variant="ghost" icon={Trash2} onClick={() => setClear(p.key, !removing)}>
                        {removing ? 'Keep key' : 'Remove key'}
                      </Button>
                    )}
                  </div>
                  {result && (
                    <div role="status" className={clsx('flex gap-2 rounded-xl px-3 py-2 text-xs leading-relaxed md:col-span-2', result.ok ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-200' : 'bg-red-50 text-red-900 dark:bg-red-500/10 dark:text-red-200')}>
                      {result.ok ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /> : <XCircle className="mt-0.5 h-4 w-4 shrink-0" />}
                      <span className="break-words">
                        {result.message}
                        {result.ok && result.reply ? ` Reply: “${result.reply}”` : ''}
                        {!result.ok && result.latency_ms > 0 ? ` (${msLabel(result.latency_ms)})` : ''}
                      </span>
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </Card>
  );
}
