import { useEffect, useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, BookOpenCheck, Clock3, Database, Eraser, History, MessageSquareText, Save, ShieldCheck, SlidersHorizontal, Undo2 } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, CardSkeleton, Checkbox, EmptyState, Field, FileUpload, Input, Select, Textarea, Toggle, useConfirm, useToast } from '@/components/ui';
import { api, ApiError, toFormData } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useApi } from '@/lib/queries';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { AssistantArt } from './AssistantArt';
import { ProvidersCard } from './ProvidersCard';
import { QuickRepliesEditor } from './QuickRepliesEditor';
import { TryAssistant } from './TryAssistant';
import { ProviderBadge, msLabel } from './shared';
import type { ChatbotSettings, ChatbotValues, ProviderLog } from './types';

const DAYS = [
  [1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun'],
] as const;

export default function SettingsTab() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { data, isLoading, error } = useApi<ChatbotSettings>(['chatbot', 'settings'], 'chatbot/settings');
  const logs = useApi<ProviderLog[]>(['chatbot', 'logs'], 'chatbot/logs');
  const [form, setForm] = useState<ChatbotValues | null>(null);
  const [keys, setKeys] = useState<Record<string, string>>({});
  const [clear, setClearState] = useState<Record<string, boolean>>({});
  const [avatar, setAvatar] = useState<File | null>(null);
  const [removeAvatar, setRemoveAvatar] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [purging, setPurging] = useState(false);
  const editable = can('communication', 'edit');
  const avatarPreview = useMemo(() => (avatar ? URL.createObjectURL(avatar) : null), [avatar]);
  useEffect(() => () => {
    if (avatarPreview) URL.revokeObjectURL(avatarPreview);
  }, [avatarPreview]);

  useEffect(() => {
    if (data && !form) setForm(data.values);
  }, [data, form]);

  const dirty = useMemo(
    () => !!data && !!form && (JSON.stringify(form) !== JSON.stringify(data.values) || Object.values(keys).some(Boolean) || Object.values(clear).some(Boolean) || !!avatar || removeAvatar),
    [data, form, keys, clear, avatar, removeAvatar],
  );

  if (error) return <Alert variant="error" title="Unable to load the chatbot settings">{(error as ApiError).message}</Alert>;
  if (isLoading || !data || !form) {
    return (
      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2"><CardSkeleton lines={8} /><CardSkeleton lines={6} /></div>
        <CardSkeleton lines={8} />
      </div>
    );
  }

  const set = <K extends keyof ChatbotValues>(k: K, v: ChatbotValues[K]) => setForm((f) => (f ? { ...f, [k]: v } : f));
  const flag = (k: keyof ChatbotValues) => form[k] === '1';
  const setFlag = (k: keyof ChatbotValues, v: boolean) => set(k, (v ? '1' : '0') as never);
  const err = (k: string) => errors[k];

  const reset = () => {
    setForm(data.values);
    setKeys({});
    setClearState({});
    setAvatar(null);
    setRemoveAvatar(false);
    setErrors({});
  };

  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const { avatar: _path, ...values } = form;
      void _path;
      const payload: Record<string, unknown> = { ...values, avatar: avatar ?? undefined, remove_avatar: removeAvatar && !avatar ? true : undefined };
      Object.entries(keys).forEach(([p, v]) => v.trim() && (payload[`${p}_key`] = v.trim()));
      Object.entries(clear).forEach(([p, v]) => v && (payload[`clear_${p}_key`] = true));
      const res = await api.post<ChatbotSettings>('chatbot/settings', toFormData(payload));
      qc.setQueryData(['chatbot', 'settings'], res.data);
      setForm(res.data.values);
      setKeys({});
      setClearState({});
      setAvatar(null);
      setRemoveAvatar(false);
      void qc.invalidateQueries({ queryKey: ['chatbot', 'logs'] });
      toast.success(res.message || 'Chatbot settings saved.');
    } catch (e) {
      const x = e as ApiError;
      setErrors(x.errors ?? {});
      toast.error(x.message);
    } finally {
      setSaving(false);
    }
  };

  const purge = async () => {
    const ok = await confirm({ title: 'Delete old conversations?', message: `Conversations with no activity in the last ${form.retention_days} days are deleted permanently.`, confirmText: 'Delete', danger: true });
    if (!ok) return;
    setPurging(true);
    try {
      const res = await api.post<{ deleted: number }>('chatbot/purge');
      toast.success(res.message);
      void qc.invalidateQueries({ queryKey: ['chatbot'] });
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setPurging(false);
    }
  };

  return (
    <div className="space-y-6">
      {data.warnings.map((w) => (
        <Alert key={w} variant="warning" title="Check the server setup">{w}</Alert>
      ))}

      <div className="sticky top-16 z-20 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white/90 px-4 py-3 shadow-card backdrop-blur dark:border-slate-700 dark:bg-slate-900/90">
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <Badge color={flag('enabled') ? 'green' : 'slate'} dot>{flag('enabled') ? 'Widget live on the website' : 'Widget switched off'}</Badge>
          <Badge color={data.chain.length ? 'purple' : 'slate'}>{data.chain.length ? `AI: ${data.providers.find((p) => p.key === data.chain[0])?.label}` : 'Rule-based answers (no AI key)'}</Badge>
          <Badge color={data.open_now ? 'green' : 'amber'}>{data.open_now ? 'Counsellors available now' : 'Outside counsellor hours'}</Badge>
          <span className="text-slate-500 dark:text-slate-400">{formatNumber(data.ai_today)} AI answers today</span>
        </div>
        {editable && (
          <div className="flex gap-2">
            <Button variant="ghost" icon={Undo2} onClick={reset} disabled={!dirty || saving}>Discard</Button>
            <Button icon={Save} onClick={() => void save()} loading={saving} disabled={!dirty}>Save settings</Button>
          </div>
        )}
      </div>

      <div className="grid gap-6 xl:grid-cols-3">
        <div className="space-y-6 xl:col-span-2">
          <Card>
            <CardHeader title="Website widget" subtitle="Bottom-left launcher, greeting bubble and the chat window texts." icon={MessageSquareText} />
            <div className="card-body grid gap-4 md:grid-cols-2">
              <div className="md:col-span-2 flex flex-wrap gap-x-8 gap-y-3">
                <Toggle checked={flag('enabled')} onChange={(v) => setFlag('enabled', v)} label="Show the chat on the website" disabled={!editable} />
                <Toggle checked={flag('show_mobile')} onChange={(v) => setFlag('show_mobile', v)} label="Show on phones" disabled={!editable} />
              </div>
              <Field label="Assistant name" required htmlFor="cb-name" error={err('bot_name')}>
                <Input id="cb-name" value={form.bot_name} maxLength={60} onChange={(e) => set('bot_name', e.target.value)} invalid={!!err('bot_name')} />
              </Field>
              <Field label="Avatar" hint="Square image (PNG/JPG/WebP). Leave empty for the illustrated assistant." error={err('avatar')}>
                <FileUpload
                  file={avatar}
                  onFile={(f) => {
                    setAvatar(f);
                    if (f) setRemoveAvatar(false);
                  }}
                  currentPath={removeAvatar ? null : form.avatar || null}
                  onRemoveCurrent={() => setRemoveAvatar(true)}
                  accept=".png,.jpg,.jpeg,.webp"
                  image
                  compact
                  onError={(m) => toast.error(m)}
                />
              </Field>
              <Field label="Greeting bubble" required htmlFor="cb-greeting" error={err('greeting')} hint="Shown next to the launcher once per visit. {session} = current admission session." className="md:col-span-2">
                <Textarea id="cb-greeting" rows={2} maxLength={200} value={form.greeting} onChange={(e) => set('greeting', e.target.value)} invalid={!!err('greeting')} />
              </Field>
              <Field label="Greeting delay (seconds)" htmlFor="cb-delay" error={err('greeting_delay')}>
                <Input id="cb-delay" type="number" min={0} max={60} value={form.greeting_delay} onChange={(e) => set('greeting_delay', e.target.value)} />
              </Field>
              <Field label="Welcome title" required htmlFor="cb-welcome" error={err('welcome')}>
                <Input id="cb-welcome" value={form.welcome} maxLength={120} onChange={(e) => set('welcome', e.target.value)} />
              </Field>
              <Field label="Welcome text" htmlFor="cb-welcome-sub" error={err('welcome_sub')} className="md:col-span-2">
                <Input id="cb-welcome-sub" value={form.welcome_sub} maxLength={200} onChange={(e) => set('welcome_sub', e.target.value)} />
              </Field>
              <Field label="Footer credit" htmlFor="cb-credit" error={err('credit_text')} hint='Small line under the message box; the words after "by " link to the URL. Leave empty to hide.'>
                <Input id="cb-credit" value={form.credit_text} maxLength={80} onChange={(e) => set('credit_text', e.target.value)} />
              </Field>
              <Field label="Footer credit link" htmlFor="cb-credit-url" error={err('credit_url')}>
                <Input id="cb-credit-url" type="url" value={form.credit_url} maxLength={300} onChange={(e) => set('credit_url', e.target.value)} placeholder="https://" />
              </Field>
              <div className="md:col-span-2">
                <WidgetPreview form={form} avatarUrl={avatarPreview ?? (removeAvatar ? null : data.avatar_url)} />
              </div>
            </div>
          </Card>

          <QuickRepliesEditor value={form.quick_replies} onChange={(v) => set('quick_replies', v)} error={err('quick_replies')} />

          <ProvidersCard
            providers={data.providers}
            chain={data.chain}
            form={form}
            set={set}
            keys={keys}
            setKey={(p, v) => setKeys((k) => ({ ...k, [p]: v }))}
            clear={clear}
            setClear={(p, v) => setClearState((c) => ({ ...c, [p]: v }))}
            errors={errors}
          />

          <Card>
            <CardHeader title="Answer behaviour" subtitle="How the AI answers. The rule-based assistant ignores these." icon={SlidersHorizontal} />
            <div className="card-body grid gap-4 md:grid-cols-2">
              <Field label="Reasoning effort" htmlFor="cb-effort" hint="Claude & OpenAI reasoning models: low = fastest and cheapest (recommended for chat)." error={err('effort')}>
                <Select id="cb-effort" value={form.effort} onChange={(e) => set('effort', e.target.value as ChatbotValues['effort'])} options={[{ value: 'low', label: 'Low (fast)' }, { value: 'medium', label: 'Medium' }, { value: 'high', label: 'High (slower, costlier)' }]} />
              </Field>
              <Field label="Temperature" htmlFor="cb-temp" hint="0 – 1. Applied where the model supports it (not on the newest Claude or OpenAI reasoning models)." error={err('temperature')}>
                <Input id="cb-temp" type="number" min={0} max={1} step={0.1} value={form.temperature} onChange={(e) => set('temperature', e.target.value)} />
              </Field>
              <Field label="Max tokens" htmlFor="cb-max" hint="Upper limit incl. the model's thinking; replies are kept short by the instructions." error={err('max_tokens')}>
                <Input id="cb-max" type="number" min={256} max={64000} step={256} value={form.max_tokens} onChange={(e) => set('max_tokens', e.target.value)} />
              </Field>
              <Field label="Timeout per provider (seconds)" htmlFor="cb-timeout" hint="After this, the next provider (or the rule-based assistant) answers." error={err('timeout')}>
                <Input id="cb-timeout" type="number" min={5} max={60} value={form.timeout} onChange={(e) => set('timeout', e.target.value)} />
              </Field>
              <div className="flex flex-col gap-3 md:col-span-2">
                <Toggle checked={flag('claude_fallbacks')} onChange={(v) => setFlag('claude_fallbacks', v)} label="Claude refusal fallback" description="If Claude's safety filter declines a harmless question, Anthropic retries it on its recommended fallback model (server-side)." disabled={!editable} />
                <Toggle checked={flag('streaming')} onChange={(v) => setFlag('streaming', v)} label="Stream answers word by word" description="Needs Apache / Nginx with PHP-FPM (not the single-threaded PHP development server). Off = the reply appears with a typing animation." disabled={!editable} />
              </div>
              <Field label="Extra instructions for the assistant" htmlFor="cb-persona" className="md:col-span-2" error={err('persona')} hint="Optional guidance added to the system prompt, e.g. tone, campaigns to mention, topics to avoid. Facts (fees, dates) must come from the ERP data — they are added automatically.">
                <Textarea id="cb-persona" rows={4} maxLength={3000} value={form.persona} onChange={(e) => set('persona', e.target.value)} placeholder="e.g. Mention the scholarship test on 31 October when visitors ask about scholarships." />
              </Field>
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader title="Counsellor hours" subtitle={`Now: ${data.hours_label}`} icon={Clock3} />
            <div className="card-body space-y-4">
              <fieldset>
                <legend className="form-label">Working days</legend>
                <div className="flex flex-wrap gap-1.5">
                  {DAYS.map(([n, label]) => {
                    const on = form.hours_days.includes(n);
                    return (
                      <button
                        key={n}
                        type="button"
                        aria-pressed={on}
                        disabled={!editable}
                        onClick={() => set('hours_days', on ? form.hours_days.filter((d) => d !== n) : [...form.hours_days, n].sort())}
                        className={on ? 'rounded-lg bg-brand-900 px-2.5 py-1.5 text-xs font-semibold text-white dark:bg-brand-600' : 'rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300'}
                      >
                        {label}
                      </button>
                    );
                  })}
                </div>
              </fieldset>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Opens" htmlFor="cb-start" error={err('hours_start')}>
                  <Input id="cb-start" type="time" value={form.hours_start} onChange={(e) => set('hours_start', e.target.value)} />
                </Field>
                <Field label="Closes" htmlFor="cb-end" error={err('hours_end')}>
                  <Input id="cb-end" type="time" value={form.hours_end} onChange={(e) => set('hours_end', e.target.value)} />
                </Field>
              </div>
              <Field label="Message outside hours" htmlFor="cb-offline" error={err('offline_message')} hint="{hours} = the working hours above.">
                <Textarea id="cb-offline" rows={3} maxLength={300} value={form.offline_message} onChange={(e) => set('offline_message', e.target.value)} />
              </Field>
            </div>
          </Card>

          <Card>
            <CardHeader title="Leads, limits & privacy" icon={ShieldCheck} />
            <div className="card-body space-y-4">
              <Toggle checked={flag('lead_capture')} onChange={(v) => setFlag('lead_capture', v)} label="Callback form in the chat" description="Creates an enquiry (source: AI chatbot) and notifies the admissions team." disabled={!editable} />
              <div className="grid grid-cols-2 gap-3">
                <Field label="Max message length" htmlFor="cb-maxin" error={err('max_input')}>
                  <Input id="cb-maxin" type="number" min={100} max={2000} value={form.max_input} onChange={(e) => set('max_input', e.target.value)} />
                </Field>
                <Field label="Messages / visitor / 10 min" htmlFor="cb-rate" error={err('rate_ip')}>
                  <Input id="cb-rate" type="number" min={5} max={500} value={form.rate_ip} onChange={(e) => set('rate_ip', e.target.value)} />
                </Field>
                <Field label="AI answers per day" htmlFor="cb-daily" error={err('daily_ai_limit')} hint="Cost cap; then rule-based. 0 = no cap.">
                  <Input id="cb-daily" type="number" min={0} max={100000} value={form.daily_ai_limit} onChange={(e) => set('daily_ai_limit', e.target.value)} />
                </Field>
                <Field label="Keep chats (days)" htmlFor="cb-ret" error={err('retention_days')} hint="Older conversations are deleted daily.">
                  <Input id="cb-ret" type="number" min={7} max={3650} value={form.retention_days} onChange={(e) => set('retention_days', e.target.value)} />
                </Field>
              </div>
              <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                <span>
                  {data.purge.due} conversation{data.purge.due === 1 ? '' : 's'} past retention · last clean-up {data.purge.last ? timeAgo(data.purge.last) : 'never'}
                </span>
                {can('communication', 'delete') && (
                  <Button size="xs" variant="secondary" icon={Eraser} loading={purging} onClick={() => void purge()}>Purge now</Button>
                )}
              </div>
              <p className="text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                Visitor IP addresses are stored only as a keyed hash. API keys never reach the browser. The assistant never asks for Aadhaar, bank or card details.
              </p>
            </div>
          </Card>

          <Card>
            <CardHeader title="Knowledge sources" subtitle={`${formatNumber(data.knowledge.snippets)} snippets from the live database · refreshed every 5 minutes`} icon={Database} />
            <div className="card-body space-y-2.5">
              {data.sources.map((s) => (
                <div key={s.key} className="flex items-center justify-between gap-3">
                  <Checkbox
                    label={s.label}
                    checked={form.sources[s.key] !== false}
                    disabled={!editable}
                    onChange={(e) => set('sources', { ...form.sources, [s.key]: e.target.checked })}
                  />
                  <span className="shrink-0 text-xs tabular-nums text-slate-500">{formatNumber(s.count)}</span>
                </div>
              ))}
              <p className="flex items-start gap-1.5 pt-1 text-xs text-slate-500 dark:text-slate-400">
                <BookOpenCheck className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                Institute facts (name, contacts, hours, deadline) are always included. Edit programs, FAQs, scholarships and pages in their modules — the assistant picks up changes automatically.
              </p>
            </div>
          </Card>

          <TryAssistant />

          <Card>
            <CardHeader title="Provider log" subtitle="Failed AI calls and connection tests" icon={History} />
            <div className="card-body">
              {logs.isLoading ? (
                <CardSkeleton lines={3} />
              ) : !logs.data || logs.data.length === 0 ? (
                <EmptyState icon={History} title="No provider calls logged" description="Errors and connection tests appear here." className="py-6" />
              ) : (
                <ul className="divide-y divide-slate-100 text-xs dark:divide-slate-800">
                  {logs.data.slice(0, 8).map((l) => (
                    <li key={l.id} className="py-2 first:pt-0">
                      <div className="flex flex-wrap items-center gap-1.5">
                        <ProviderBadge provider={l.provider} />
                        <Badge color={l.status === 'ok' ? 'green' : 'red'}>{l.status === 'ok' ? 'OK' : l.error_type ?? 'error'}</Badge>
                        <span className="text-slate-500">{l.kind === 'test' ? `test${l.user_name ? ` by ${l.user_name}` : ''}` : 'chat'} · {formatDateTime(l.created_at)} · {msLabel(l.latency_ms)}</span>
                      </div>
                      {l.message && (
                        <p className="mt-1 break-words text-slate-700 dark:text-slate-300">
                          {l.status === 'error' && <AlertTriangle className="mr-1 inline h-3 w-3 text-red-500" />}
                          {l.http_status ? `HTTP ${l.http_status}: ` : ''}
                          {l.message}
                        </p>
                      )}
                      {l.hint && <p className="mt-0.5 text-slate-500">{l.hint}</p>}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}

/** Static preview of the launcher + greeting + footer credit with the current (unsaved) texts. */
function WidgetPreview({ form, avatarUrl }: { form: ChatbotValues; avatarUrl: string | null }) {
  const credit = form.credit_text.trim();
  const i = credit.toLowerCase().lastIndexOf('by ');
  return (
    <div className="rounded-2xl border border-dashed border-slate-300 bg-[#F5F2EE] p-4 dark:border-slate-700 dark:bg-slate-800/60">
      <p className="mb-3 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Preview</p>
      <div className="flex flex-wrap items-end gap-4">
        <div className="flex items-end gap-3">
          <span className="grid h-[52px] w-[52px] shrink-0 place-items-center overflow-hidden rounded-full bg-white ring-[3px] ring-emerald-500">
            {avatarUrl ? <img src={avatarUrl} alt="" className="h-full w-full object-cover" /> : <AssistantArt className="h-full w-full" />}
          </span>
          <div className="max-w-[260px] rounded-2xl rounded-bl-md bg-white px-3.5 py-2.5 shadow-sm">
            <p className="text-[13px] font-bold text-brand-900">Let&apos;s chat!</p>
            <p className="text-[12.5px] text-slate-600">{form.greeting.replace('{session}', '2026-27') || '—'}</p>
          </div>
        </div>
        <div className="min-w-[220px] flex-1 rounded-2xl bg-white p-3 shadow-sm">
          <p className="text-[12px] font-bold text-white"><span className="rounded-lg bg-brand-900 px-2 py-1">{form.bot_name || 'Assistant'}</span></p>
          <p className="mt-2 text-[13px] font-semibold text-brand-900">{form.welcome}</p>
          <p className="text-[12px] text-slate-500">{form.welcome_sub}</p>
          {credit && (
            <p className="mt-2 text-center text-[10.5px] text-slate-600 opacity-[.65]">
              {i >= 0 ? credit.slice(0, i + 3) : ''}
              <span className="font-semibold">{i >= 0 ? credit.slice(i + 3) : credit}</span>
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
