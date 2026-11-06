import { useEffect, useMemo, useState } from 'react';
import type { UseQueryResult } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ArrowDown, ArrowUp, BrainCircuit, Clock, ExternalLink, Languages, ListChecks, MessageSquareText, Plus, Save, ShieldAlert, Trash2, Undo2 } from 'lucide-react';
import { Alert, Badge, Button, Card, CardBody, CardHeader, CardSkeleton, Checkbox, Field, IconButton, Input, RadioGroup, Select, Textarea, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber } from '@/lib/format';
import { useWaRefresh } from '../shared';
import type { BotLang, BotQuestion, BotSettings, BotSettingsValues } from './types';

type Texts = Record<string, Record<BotLang, string>>;
const LANGS: { key: BotLang; label: string }[] = [
  { key: 'en', label: 'English' },
  { key: 'hi', label: 'हिंदी' },
  { key: 'hing', label: 'Hinglish' },
];

/** Bot settings: behaviour, AI provider status (no keys), safety & hand-over, WhatsApp texts, lead qualification. */
export function SettingsTab({ query }: { query: UseQueryResult<BotSettings> }) {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useWaRefresh();
  const data = query.data;
  const [values, setValues] = useState<BotSettingsValues | null>(null);
  const [texts, setTexts] = useState<Texts>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const canEdit = can('whatsapp_automation', 'edit');

  const initialTexts = useMemo(() => Object.fromEntries((data?.texts ?? []).map((t) => [t.key, t.values])) as Texts, [data]);
  useEffect(() => {
    if (data) {
      setValues(data.values);
      setTexts(initialTexts);
      setErrors({});
    }
  }, [data, initialTexts]);

  if (query.isLoading || !values || !data) {
    return query.error ? null : (
      <div className="grid gap-5 xl:grid-cols-2">
        <CardSkeleton lines={6} />
        <CardSkeleton lines={6} />
      </div>
    );
  }

  const dirty = JSON.stringify(values) !== JSON.stringify(data.values) || JSON.stringify(texts) !== JSON.stringify(initialTexts);
  const set = <K extends keyof BotSettingsValues>(k: K, v: BotSettingsValues[K]) => setValues((s) => (s ? { ...s, [k]: v } : s));
  const setQ = (i: number, patch: Partial<BotQuestion>) => set('qualify_questions', values.qualify_questions.map((q, j) => (j === i ? { ...q, ...patch } : q)));
  const moveQ = (i: number, d: -1 | 1) => {
    const list = [...values.qualify_questions];
    const j = i + d;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];
    set('qualify_questions', list);
  };
  const addQ = () => {
    let n = 1;
    while (values.qualify_questions.some((q) => q.key === `custom_${n}`)) n++;
    set('qualify_questions', [...values.qualify_questions, { key: `custom_${n}`, type: 'text', enabled: true, label: 'New question', question: { en: '', hi: '', hing: '' }, options: [] }]);
  };

  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const { enabled: _enabled, ...rest } = values;
      void _enabled; // the on/off switch lives in the page header (publish permission)
      const r = await api.post<BotSettings>('whatsapp/bot/settings', { ...rest, texts });
      toast.success(r.message);
      await refresh('bot');
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  const ai = data.ai;
  const userOptions = data.users;

  return (
    <div className="space-y-5 pb-20">
      {!canEdit && <Alert variant="info">You can view the bot settings. Changing them needs the WhatsApp Automations “edit” permission.</Alert>}
      <fieldset disabled={!canEdit || saving} className="contents">
        <div className="grid gap-5 xl:grid-cols-2">
          <Card>
            <CardHeader title="When and how the bot answers" icon={Clock} />
            <CardBody className="space-y-5">
              <Field label="Reply languages" error={errors.languages} hint="Hinglish (Hindi in English letters) is answered when Hindi is on. Students switch any time by typing “Hindi” or “English”.">
                <div className="flex flex-wrap gap-5">
                  <Checkbox label="English" checked disabled readOnly />
                  <Checkbox label="Hindi + Hinglish" checked={values.languages.includes('hi')} onChange={(e) => set('languages', e.target.checked ? ['en', 'hi'] : ['en'])} />
                </div>
              </Field>
              <Field label="Answer chats" error={errors.hours_mode}>
                <RadioGroup
                  name="hours_mode"
                  inline={false}
                  value={values.hours_mode}
                  onChange={(v) => set('hours_mode', v as BotSettingsValues['hours_mode'])}
                  options={[
                    { value: 'always', label: 'Around the clock (24×7) when no team member owns the chat' },
                    { value: 'after_hours', label: 'Only outside business hours — the team answers during office hours' },
                  ]}
                />
              </Field>
              <p className="-mt-2 text-xs text-slate-500 dark:text-slate-400">
                Business hours: <strong>{data.business_hours.label}</strong> ({data.business_hours.open_now ? 'open now' : 'closed now'}).{' '}
                {can('whatsapp_settings', 'view') ? (
                  <Link to="/whatsapp/settings" className="font-medium text-brand-700 hover:underline dark:text-brand-300">
                    Change business hours
                  </Link>
                ) : (
                  'Set in WhatsApp › Settings.'
                )}
              </p>
              <Toggle checked={values.cover_assigned} onChange={(v) => set('cover_assigned', v)} label="Cover assigned chats after hours" description="Outside business hours the bot also answers chats owned by a team member (it never answers while the team is in office)." />
              <Toggle checked={values.use_buttons} onChange={(v) => set('use_buttons', v)} label="Use WhatsApp buttons and lists" description="Quick-reply buttons (Talk to counsellor, Programs & fees) and program lists. Off = plain text only." />
            </CardBody>
          </Card>

          <Card>
            <CardHeader
              title="AI provider"
              subtitle="Uses the website chatbot's provider — keys are never shown here"
              icon={BrainCircuit}
              actions={
                can('communication', 'view') ? (
                  <Button size="xs" variant="ghost" iconRight={ExternalLink} to="/chatbot?tab=settings">
                    Provider settings
                  </Button>
                ) : undefined
              }
            />
            <CardBody className="space-y-5">
              {ai.configured ? (
                <div className="space-y-2">
                  <div className="flex flex-wrap gap-1.5">
                    {ai.chain.map((p, i) => (
                      <Badge key={p.key} color={i === 0 ? 'purple' : 'slate'} dot>
                        {p.label} · {p.model}
                      </Badge>
                    ))}
                  </div>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    AI answers today: {formatNumber(ai.used_today_whatsapp)} WhatsApp + {formatNumber(ai.used_today_web)} website of {ai.daily_limit > 0 ? formatNumber(ai.daily_limit) : 'unlimited'} per day.
                  </p>
                </div>
              ) : (
                <Alert variant="warning" title="No AI provider configured">
                  The bot answers from program data, verified answers and the rule-based engine. Add a Claude, OpenAI or Gemini key in AI Chatbot › Settings to let it phrase answers to any question (still only from verified GIMT data).
                </Alert>
              )}
              <Toggle checked={values.use_ai} onChange={(v) => set('use_ai', v)} label="Use AI for questions without a verified answer" description="The AI only sees verified facts and must hand over when unsure. Program facts and verified answers are always sent word for word." />
              <Toggle checked={values.summary_ai} onChange={(v) => set('summary_ai', v)} label="AI conversation summaries on hand-over" description="Otherwise an extractive summary (lead details, topics, open questions) is written." />
              <Field label="Extra guidance for WhatsApp answers" htmlFor="bot-persona" error={errors.persona} hint="Optional, added to the AI instructions (tone, what to emphasise). It cannot override the accuracy rules." aside={`${values.persona.length}/3000`}>
                <Textarea id="bot-persona" rows={3} maxLength={3000} value={values.persona} onChange={(e) => set('persona', e.target.value)} invalid={!!errors.persona} />
              </Field>
            </CardBody>
          </Card>
        </div>

        <Card>
          <CardHeader title="Safety & hand-over to counsellors" icon={ShieldAlert} />
          <CardBody className="grid gap-5 lg:grid-cols-2">
            <Field label="Hand-over keywords" htmlFor="bot-hk" error={errors.handover_keywords} hint="Comma separated. Any of these in a message passes the chat to a counsellor at once.">
              <Textarea id="bot-hk" rows={3} value={values.handover_keywords} onChange={(e) => set('handover_keywords', e.target.value)} invalid={!!errors.handover_keywords} />
            </Field>
            <Field label="Sensitive topics" htmlFor="bot-sk" error={errors.sensitive_keywords} hint="Complaints, refunds, harassment, emergencies … are never answered by the bot.">
              <Textarea id="bot-sk" rows={3} value={values.sensitive_keywords} onChange={(e) => set('sensitive_keywords', e.target.value)} invalid={!!errors.sensitive_keywords} />
            </Field>
            <div className="grid grid-cols-2 gap-4">
              <Field label="Minimum confidence" htmlFor="bot-mc" error={errors.min_confidence} hint="0.1 – 0.9; below it the bot says it is not sure.">
                <Input id="bot-mc" type="number" step="0.05" min={0.1} max={0.9} value={values.min_confidence} onChange={(e) => set('min_confidence', Number(e.target.value))} invalid={!!errors.min_confidence} />
              </Field>
              <Field label="Hand over after … unsure replies" htmlFor="bot-lc" error={errors.low_conf_limit} hint="In a row.">
                <Input id="bot-lc" type="number" min={1} max={10} value={values.low_conf_limit} onChange={(e) => set('low_conf_limit', Number(e.target.value))} invalid={!!errors.low_conf_limit} />
              </Field>
              <Field label="Max bot turns per chat" htmlFor="bot-mt" error={errors.max_turns} hint="0 = no limit; then a counsellor takes over.">
                <Input id="bot-mt" type="number" min={0} max={200} value={values.max_turns} onChange={(e) => set('max_turns', Number(e.target.value))} invalid={!!errors.max_turns} />
              </Field>
              <Field label="Max bot replies per hour" htmlFor="bot-mh" error={errors.max_per_hour} hint="Per contact — loop protection.">
                <Input id="bot-mh" type="number" min={1} max={200} value={values.max_per_hour} onChange={(e) => set('max_per_hour', Number(e.target.value))} invalid={!!errors.max_per_hour} />
              </Field>
            </div>
            <Field
              label="Counsellors who receive hand-overs"
              error={errors.handover_users}
              hint={values.handover_users.length ? 'Round-robin over the selected people (a lead’s own counsellor is always preferred).' : `None selected — round-robin over the admission team: ${data.pool.map((p) => p.name).join(', ') || 'nobody found'}.`}
            >
              <div className="max-h-48 space-y-2 overflow-y-auto rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                {userOptions.length === 0 ? (
                  <p className="text-sm text-slate-500">No users with WhatsApp inbox access.</p>
                ) : (
                  userOptions.map((u) => (
                    <Checkbox
                      key={u.value}
                      label={u.label}
                      description={u.sub ?? undefined}
                      checked={values.handover_users.includes(u.value)}
                      onChange={(e) => set('handover_users', e.target.checked ? [...values.handover_users, u.value] : values.handover_users.filter((id) => id !== u.value))}
                    />
                  ))
                )}
              </div>
            </Field>
          </CardBody>
        </Card>

        <TextsCard texts={texts} defs={data.texts} errors={errors} onChange={setTexts} />

        <Card>
          <CardHeader
            title="Lead qualification"
            subtitle="Asked to new leads after the first reply — answers update the WhatsApp contact (name, program, city) and move the stage to Interested"
            icon={ListChecks}
            actions={<Toggle checked={values.qualify_enabled} onChange={(v) => set('qualify_enabled', v)} label={values.qualify_enabled ? 'On' : 'Off'} />}
          />
          <CardBody className="space-y-3">
            <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-800/40">
              <Toggle
                checked={values.create_enquiry}
                onChange={(v) => set('create_enquiry', v)}
                label="Create an admission enquiry for qualified leads"
                description="When a lead answers the last question, the bot adds them to Admissions › Enquiries (source WhatsApp, assigned to the lead’s counsellor) — or links the open enquiry of the same mobile number. The enquiry number is quoted in the counsellor’s notification and the chat history."
              />
              <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 pl-14 text-xs empty:hidden">
                {!data.enquiry_available && <span className="text-amber-700 dark:text-amber-300">The WhatsApp CRM enquiry helper is not loaded on this server — no enquiries are created.</span>}
                {data.enquiry_available && values.create_enquiry && !values.qualify_enabled && (
                  <span className="text-amber-700 dark:text-amber-300">Lead qualification is off, so the bot creates no enquiries.</span>
                )}
                {can('enquiries', 'view') && (
                  <Link to="/enquiries" className="font-medium text-brand-700 hover:underline dark:text-brand-300">
                    Open Admissions › Enquiries
                  </Link>
                )}
              </div>
            </div>
            {errors.qualify_questions && <Alert variant="error">{errors.qualify_questions}</Alert>}
            {values.qualify_questions.map((q, i) => (
              <QuestionRow
                key={i}
                q={q}
                index={i}
                count={values.qualify_questions.length}
                error={errors[`qualify_questions.${i}`]}
                intakeDefaults={data.intake_options}
                onChange={(p) => setQ(i, p)}
                onMove={(d) => moveQ(i, d)}
                onRemove={() => set('qualify_questions', values.qualify_questions.filter((_, j) => j !== i))}
              />
            ))}
            {values.qualify_questions.length < 8 && (
              <Button variant="secondary" size="sm" icon={Plus} onClick={addQ}>
                Add question
              </Button>
            )}
          </CardBody>
        </Card>
      </fieldset>

      {canEdit && (
        <div className={dirty ? 'sticky bottom-3 z-10 flex justify-end' : 'flex justify-end'}>
          <div className={dirty ? 'flex items-center gap-2 rounded-2xl border border-slate-200 bg-white/95 p-2 shadow-lg backdrop-blur dark:border-slate-700 dark:bg-slate-900/95' : 'flex items-center gap-2'}>
            {dirty && <span className="hidden px-2 text-xs text-slate-500 sm:inline dark:text-slate-400">Unsaved changes</span>}
            <Button
              variant="ghost"
              icon={Undo2}
              disabled={!dirty || saving}
              onClick={() => {
                setValues(data.values);
                setTexts(initialTexts);
                setErrors({});
              }}
            >
              Discard
            </Button>
            <Button icon={Save} loading={saving} disabled={!dirty} onClick={save}>
              Save settings
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

function TextsCard({ texts, defs, errors, onChange }: { texts: Texts; defs: BotSettings['texts']; errors: Record<string, string>; onChange: (t: Texts) => void }) {
  const [lang, setLang] = useState<BotLang>('en');
  const hasErr = (l: BotLang) => Object.keys(errors).some((k) => k.startsWith('texts.') && k.endsWith(`.${l}`));
  return (
    <Card>
      <CardHeader
        title="WhatsApp messages"
        subtitle="Greeting, not-sure fallback, hand-over and qualification texts. *bold* works; {placeholders} are filled automatically."
        icon={MessageSquareText}
        actions={
          <div className="pill-tabs" role="tablist" aria-label="Language">
            {LANGS.map((l) => (
              <button key={l.key} type="button" role="tab" aria-selected={lang === l.key} className={`pill-tab ${lang === l.key ? 'pill-tab-active' : ''}`} onClick={() => setLang(l.key)}>
                <Languages className="mr-1 inline h-3.5 w-3.5" aria-hidden />
                {l.label}
                {hasErr(l.key) && <span className="ml-1 text-red-500">•</span>}
              </button>
            ))}
          </div>
        }
      />
      <CardBody className="grid gap-4 lg:grid-cols-2">
        {defs.map((d) => {
          const err = errors[`texts.${d.key}.${lang}`];
          const v = texts[d.key]?.[lang] ?? '';
          return (
            <Field key={d.key} label={d.label} htmlFor={`bot-text-${d.key}`} error={err} hint={d.help + (lang !== 'en' ? ' Empty = the English text is used.' : '')} aside={`${v.length}/1000`}>
              <Textarea
                id={`bot-text-${d.key}`}
                rows={4}
                maxLength={1000}
                value={v}
                onChange={(e) => onChange({ ...texts, [d.key]: { ...(texts[d.key] ?? { en: '', hi: '', hing: '' }), [lang]: e.target.value } })}
                invalid={!!err}
                lang={lang === 'hi' ? 'hi' : 'en'}
              />
            </Field>
          );
        })}
      </CardBody>
    </Card>
  );
}

function QuestionRow({
  q,
  index,
  count,
  error,
  intakeDefaults,
  onChange,
  onMove,
  onRemove,
}: {
  q: BotQuestion;
  index: number;
  count: number;
  error?: string;
  intakeDefaults: string[];
  onChange: (p: Partial<BotQuestion>) => void;
  onMove: (d: -1 | 1) => void;
  onRemove: () => void;
}) {
  const builtIn = ['name', 'program', 'city', 'qualification', 'intake'].includes(q.key);
  const [optText, setOptText] = useState(q.options.join(', '));
  useEffect(() => setOptText(q.options.join(', ')), [q.options]);
  return (
    <div className={`rounded-xl border p-4 ${error ? 'border-red-300 dark:border-red-500/50' : 'border-slate-200 dark:border-slate-800'} ${q.enabled ? '' : 'opacity-70'}`}>
      <div className="flex flex-wrap items-center gap-3">
        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-brand-50 text-xs font-bold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">{index + 1}</span>
        <Input aria-label="Label" value={q.label} maxLength={60} onChange={(e) => onChange({ label: e.target.value })} className="w-48" inputSize="sm" />
        <Badge color={builtIn ? 'navy' : 'slate'}>{builtIn ? (q.key === 'name' || q.key === 'city' || q.key === 'program' ? 'Updates the contact' : 'Saved on the session') : `Custom · ${q.key}`}</Badge>
        {!builtIn && (
          <Select aria-label="Answer type" inputSize="sm" value={q.type} onChange={(e) => onChange({ type: e.target.value as BotQuestion['type'] })} options={{ text: 'Free text', choice: 'Choice (buttons / list)' }} className="w-48" />
        )}
        <div className="ml-auto flex items-center gap-1">
          <Toggle checked={q.enabled} onChange={(v) => onChange({ enabled: v })} label={<span className="sr-only">Ask this question</span>} />
          <IconButton icon={ArrowUp} label="Move up" size="sm" disabled={index === 0} onClick={() => onMove(-1)} />
          <IconButton icon={ArrowDown} label="Move down" size="sm" disabled={index === count - 1} onClick={() => onMove(1)} />
          {!builtIn && <IconButton icon={Trash2} label="Remove question" size="sm" tone="danger" onClick={onRemove} />}
        </div>
      </div>
      <div className="mt-3 grid gap-3 md:grid-cols-3">
        {LANGS.map((l) => (
          <Field key={l.key} label={`Question (${l.label})`} required={l.key === 'en'}>
            <Input value={q.question[l.key] ?? ''} maxLength={300} onChange={(e) => onChange({ question: { ...q.question, [l.key]: e.target.value } })} inputSize="sm" lang={l.key === 'hi' ? 'hi' : 'en'} />
          </Field>
        ))}
      </div>
      {q.type === 'choice' && (
        <Field
          className="mt-3"
          label="Options"
          hint={q.key === 'intake' && !q.options.length ? `Empty = automatic: ${intakeDefaults.join(', ')}` : 'Comma separated, up to 10 (24 characters each). Up to 3 options are sent as buttons, more as a list.'}
        >
          <Input
            value={optText}
            onChange={(e) => setOptText(e.target.value)}
            onBlur={() => onChange({ options: optText.split(',').map((s) => s.trim()).filter(Boolean).slice(0, 10) })}
            inputSize="sm"
          />
        </Field>
      )}
      {q.type === 'program' && <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Sent as a list of the main programs (from Course information); typed program names are recognised too.</p>}
      {error && <p className="mt-2 text-xs text-red-600 dark:text-red-400">{error}</p>}
    </div>
  );
}
