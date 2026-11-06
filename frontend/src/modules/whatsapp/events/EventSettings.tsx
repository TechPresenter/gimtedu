import { useEffect, useId, useMemo, useState } from 'react';
import clsx from 'clsx';
import { CalendarClock, LayoutTemplate, Plus, Trash, TriangleAlert } from 'lucide-react';
import { Alert, Badge, Button, Field, IconButton, Input, Select, Textarea, Toggle } from '@/components/ui';
import { useAuth } from '@/lib/auth';
import { WaText } from '../shared';
import { asRecord, payloadKeys } from './api';
import type { WaEventCondition, WaEventDetail, WaEventOptions, WaEventSchedule } from './types';

export interface EventForm {
  name: string;
  description: string;
  category: string;
  source: string;
  is_active: boolean;
  template_id: string;
  recipient: string;
  recipient_field: string;
  conditions: WaEventCondition[];
  variable_map: Record<string, string>;
  delay_minutes: string;
  respect_business_hours: boolean;
  schedule_days: string;
  schedule_time: string;
  schedule_minutes: string;
  sample_payload: string;
}

export function formFromEvent(e: WaEventDetail): EventForm {
  const sc = asRecord(e.schedule_config) as WaEventSchedule;
  const eff = e.schedule ?? sc;
  return {
    name: e.name,
    description: e.description ?? '',
    category: e.category,
    source: e.source,
    is_active: e.is_active,
    template_id: e.template_id ? String(e.template_id) : '',
    recipient: e.recipient || 'contact',
    recipient_field: e.recipient_field ?? '',
    conditions: (e.conditions ?? []).map((c) => ({ field: c.field, op: c.op, value: String(c.value ?? '') })),
    variable_map: asRecord<string>(e.variable_map),
    delay_minutes: String(e.delay_minutes ?? 0),
    respect_business_hours: !!e.respect_business_hours,
    schedule_days: (sc.days ?? eff?.days ?? []).join(', '),
    schedule_time: sc.time ?? eff?.time ?? '',
    schedule_minutes: sc.minutes !== undefined ? String(sc.minutes) : eff?.minutes !== undefined ? String(eff.minutes) : '',
    sample_payload: JSON.stringify(asRecord(e.sample_payload), null, 2),
  };
}

/** API body for PUT whatsapp/events/{id}. */
export function formToBody(f: EventForm, e: WaEventDetail) {
  const body: Record<string, unknown> = {
    name: f.name,
    description: f.description,
    template_id: f.template_id ? Number(f.template_id) : null,
    recipient: f.recipient,
    recipient_field: f.recipient_field,
    conditions: f.conditions.filter((c) => c.field.trim() !== ''),
    variable_map: f.variable_map,
    delay_minutes: f.delay_minutes === '' ? 0 : Number(f.delay_minutes),
    respect_business_hours: f.respect_business_hours,
    sample_payload: f.sample_payload,
    is_active: f.is_active,
  };
  if (!e.is_system) {
    body.category = f.category;
    body.source = f.source;
  }
  if (e.is_scheduled) {
    body.schedule_config = {
      days: f.schedule_days.split(',').map((d) => d.trim()).filter(Boolean).map(Number),
      time: f.schedule_time,
      minutes: f.schedule_minutes,
    };
  }
  return body;
}

type MapKind = 'auto' | 'payload' | 'contact' | 'text';

interface MapSource {
  kind: MapKind;
  value: string;
  /** Text used when the variable is still empty after the source, the payload and the contact data. */
  fallback: string;
}

/** A source may end with "|default:<text>"; "default:<text>" alone = Automatic + fallback (mirrors wa_evt_map_parts()). */
const FALLBACK_SEP = '|default:';

function parseSource(v: string | undefined): MapSource {
  if (!v) return { kind: 'auto', value: '', fallback: '' };
  if (v.startsWith('text:')) return { kind: 'text', value: v.slice(5), fallback: '' };
  if (v.startsWith('default:')) return { kind: 'auto', value: '', fallback: v.slice(8) };
  const at = v.indexOf(FALLBACK_SEP);
  const src = at >= 0 ? v.slice(0, at) : v;
  const fallback = at >= 0 ? v.slice(at + FALLBACK_SEP.length) : '';
  const m = /^(payload|contact):([\s\S]*)$/.exec(src);
  if (m) return { kind: m[1] as MapKind, value: m[2], fallback };
  return { kind: 'payload', value: src, fallback };
}

/** Stored map entry for a source (null = Automatic without fallback = no entry). A fixed text needs no fallback. */
function composeSource(s: MapSource): string | null {
  const fb = s.kind === 'text' ? '' : s.fallback;
  if (s.kind === 'auto') return fb !== '' ? `default:${fb}` : null;
  return `${s.kind}:${s.value}${fb !== '' ? FALLBACK_SEP + fb : ''}`;
}

interface Props {
  event: WaEventDetail;
  options: WaEventOptions;
  form: EventForm;
  setForm: (f: EventForm) => void;
  errors: Record<string, string>;
}

/** Settings tab of the event drawer: trigger conditions, template, recipient, variable mapping, timing. */
export function EventSettings({ event, options, form, setForm, errors }: Props) {
  const { can } = useAuth();
  const uid = useId();
  const editable = can('whatsapp_automation', 'edit');
  const set = <K extends keyof EventForm>(k: K, v: EventForm[K]) => setForm({ ...form, [k]: v });

  const sampleKeys = useMemo(() => {
    try {
      return payloadKeys(JSON.parse(form.sample_payload || '{}'));
    } catch {
      return event.payload_keys;
    }
  }, [form.sample_payload, event.payload_keys]);
  const [sampleError, setSampleError] = useState<string | null>(null);
  useEffect(() => {
    try {
      const v = JSON.parse(form.sample_payload || '{}');
      setSampleError(v && typeof v === 'object' && !Array.isArray(v) ? null : 'The sample payload must be a JSON object.');
    } catch {
      setSampleError('Not valid JSON yet.');
    }
  }, [form.sample_payload]);

  const tpl = options.templates.find((t) => String(t.id) === form.template_id) ?? null;
  const conventionTpl = !form.template_id ? options.templates.find((t) => t.name === event.template_convention) : null;
  const tplVars = tpl ? tpl.variables : [];
  const extraMapKeys = Object.keys(form.variable_map).filter((k) => !tplVars.some((v) => v.key === k));
  const grouped = useMemo(() => {
    const g: Record<string, typeof options.templates> = {};
    options.templates.forEach((t) => (g[t.status === 'approved' ? 'Approved' : t.status === 'pending' ? 'Pending approval' : 'Draft / other'] ??= []).push(t));
    return ['Approved', 'Pending approval', 'Draft / other'].filter((k) => g[k]?.length).map((k) => ({ label: k, items: g[k] }));
  }, [options.templates]);

  // variables whose "If empty" editor was opened (it stays open while its text is still empty)
  const [fallbackOpen, setFallbackOpen] = useState<string[]>([]);
  useEffect(() => setFallbackOpen([]), [event.id]);
  const setMap = (key: string, patch: Partial<MapSource>) => {
    const next = { ...form.variable_map };
    const v = composeSource({ ...parseSource(form.variable_map[key]), ...patch });
    if (v === null) delete next[key];
    else next[key] = v;
    set('variable_map', next);
  };
  const resetMap = (key: string) => {
    setMap(key, { kind: 'auto', value: '', fallback: '' });
    setFallbackOpen((o) => o.filter((k) => k !== key));
  };

  const fieldOptions = [...sampleKeys.map((k) => k), ...Object.keys(options.contact_fields).map((k) => `contact.${k}`)];
  const opNeedsValue = (op: string) => !['empty', 'not_empty'].includes(op);
  const isSystem = event.is_system;

  return (
    <div className="space-y-6">
      <datalist id={`${uid}-keys`}>
        {sampleKeys.map((k) => (
          <option key={k} value={k} />
        ))}
      </datalist>
      <datalist id={`${uid}-fields`}>
        {fieldOptions.map((k) => (
          <option key={k} value={k} />
        ))}
      </datalist>

      <section className="space-y-4">
        <div className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700 dark:bg-slate-800/40">
          <Toggle
            checked={form.is_active}
            onChange={(v) => set('is_active', v)}
            disabled={!can('whatsapp_automation', 'publish')}
            label={form.is_active ? 'Active — messages are sent when this event fires' : 'Inactive — the event is only logged'}
            description={can('whatsapp_automation', 'publish') ? 'Saved with the other settings.' : 'Your role cannot activate events.'}
          />
          <span className="self-start sm:self-auto">{isSystem ? <Badge color="navy">Built-in event</Badge> : <Badge color="purple">Custom event</Badge>}</span>
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Name" required error={errors.name} htmlFor={`${uid}-name`} className="sm:col-span-2">
            <Input id={`${uid}-name`} value={form.name} maxLength={150} invalid={!!errors.name} disabled={!editable} onChange={(e) => set('name', e.target.value)} />
          </Field>
          <Field label="Description" htmlFor={`${uid}-desc`} className="sm:col-span-2" error={errors.description}>
            <Textarea id={`${uid}-desc`} rows={2} value={form.description} disabled={!editable} onChange={(e) => set('description', e.target.value)} />
          </Field>
          <Field label="Category" htmlFor={`${uid}-cat`} error={errors.category} hint={isSystem ? 'Fixed for built-in events.' : undefined}>
            <Select id={`${uid}-cat`} value={form.category} disabled={isSystem || !editable} onChange={(e) => set('category', e.target.value)} options={Object.entries(options.categories).map(([value, label]) => ({ value, label }))} />
          </Field>
          <Field label="Source" htmlFor={`${uid}-src`} error={errors.source} hint={form.source === 'js' ? 'Website JavaScript can fire it (needs phone + consent).' : form.source === 'api' ? 'Fired by external systems with an API token.' : undefined}>
            <Select id={`${uid}-src`} value={form.source} disabled={isSystem || !editable} onChange={(e) => set('source', e.target.value)} options={Object.entries(options.sources).filter(([value]) => isSystem || value !== 'cron').map(([value, label]) => ({ value, label }))} />
          </Field>
        </div>
      </section>

      <section>
        <h4 className="text-sm font-semibold text-slate-900 dark:text-white">Trigger conditions</h4>
        <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">All conditions must match. Fields are payload keys (e.g. <code className="font-mono">amount</code>, <code className="font-mono">form</code>) or contact fields (<code className="font-mono">contact.stage</code>). Leave empty to send every time.</p>
        {errors.conditions && <p className="form-error mb-2" role="alert">{errors.conditions}</p>}
        <div className="space-y-2">
          {form.conditions.map((c, i) => (
            <div key={i} className="grid grid-cols-1 gap-2 rounded-xl border border-slate-200 p-2 sm:grid-cols-[minmax(0,1fr)_150px_minmax(0,1fr)_auto] sm:border-0 sm:p-0 dark:border-slate-700">
              <Input aria-label={`Condition ${i + 1} field`} list={`${uid}-fields`} placeholder="payload key" value={c.field} disabled={!editable} inputSize="sm"
                onChange={(e) => set('conditions', form.conditions.map((x, j) => (j === i ? { ...x, field: e.target.value } : x)))} />
              <Select aria-label={`Condition ${i + 1} operator`} inputSize="sm" value={c.op} disabled={!editable}
                onChange={(e) => set('conditions', form.conditions.map((x, j) => (j === i ? { ...x, op: e.target.value } : x)))}
                options={Object.entries(options.operators).map(([value, label]) => ({ value, label }))} />
              {opNeedsValue(c.op) ? (
                <Input aria-label={`Condition ${i + 1} value`} inputSize="sm" placeholder={['in', 'not_in'].includes(c.op) ? 'value1, value2' : 'value'} value={c.value} disabled={!editable}
                  onChange={(e) => set('conditions', form.conditions.map((x, j) => (j === i ? { ...x, value: e.target.value } : x)))} />
              ) : (
                <span className="hidden sm:block" />
              )}
              <IconButton icon={Trash} label={`Remove condition ${i + 1}`} size="sm" tone="danger" disabled={!editable} onClick={() => set('conditions', form.conditions.filter((_, j) => j !== i))} />
            </div>
          ))}
        </div>
        {editable && (
          <Button size="sm" variant="ghost" icon={Plus} className="mt-2" onClick={() => set('conditions', [...form.conditions, { field: '', op: '=', value: '' }])}>
            Add condition
          </Button>
        )}
      </section>

      <section className="space-y-3">
        <h4 className="text-sm font-semibold text-slate-900 dark:text-white">Message</h4>
        <Field label="WhatsApp template" htmlFor={`${uid}-tpl`} error={errors.template_id}
          hint={conventionTpl ? <>Not linked — the template <strong>{conventionTpl.display_name}</strong> matches this event by name and is used automatically.</> : 'Approved templates are listed first. Only approved templates are delivered in live mode.'}>
          <select id={`${uid}-tpl`} className={clsx('form-input pr-9', errors.template_id && 'form-input-error')} value={form.template_id} disabled={!editable} onChange={(e) => set('template_id', e.target.value)}>
            <option value="">{conventionTpl ? `Automatic (${conventionTpl.name})` : '— No template —'}</option>
            {grouped.map((g) => (
              <optgroup key={g.label} label={g.label}>
                {g.items.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.display_name} · {t.name} ({t.language}, {t.category.toLowerCase()})
                  </option>
                ))}
              </optgroup>
            ))}
          </select>
        </Field>
        {(tpl || conventionTpl) && (
          <div className="rounded-xl bg-[#efeae2] p-3 dark:bg-slate-800/70">
            <div className="mb-1 flex items-center gap-2 text-[11px] font-medium text-slate-600 dark:text-slate-300">
              <LayoutTemplate className="h-3.5 w-3.5" aria-hidden /> {(tpl ?? conventionTpl)!.name}
              <Badge color={(tpl ?? conventionTpl)!.status === 'approved' ? 'green' : 'amber'}>{(tpl ?? conventionTpl)!.status}</Badge>
            </div>
            <div className="max-w-md rounded-lg rounded-tl-none bg-white px-3 py-2 text-sm text-slate-800 shadow-sm dark:bg-slate-900 dark:text-slate-100">
              <WaText text={(tpl ?? conventionTpl)!.body} highlightVars />
            </div>
          </div>
        )}
        {!tpl && !conventionTpl && (
          <Alert variant="warning" title="No template">Create a template named <code className="font-mono">{event.template_convention}</code> in the Template Designer, or pick one above — the event cannot send messages without one.</Alert>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Send to" htmlFor={`${uid}-rec`} error={errors.recipient}>
            <Select id={`${uid}-rec`} value={form.recipient} disabled={!editable} onChange={(e) => set('recipient', e.target.value)} options={Object.entries(options.recipients).map(([value, label]) => ({ value, label }))} />
          </Field>
          {form.recipient === 'custom_field' && (
            <Field label="Payload field with the phone" required htmlFor={`${uid}-recf`} error={errors.recipient_field}>
              <Input id={`${uid}-recf`} list={`${uid}-keys`} value={form.recipient_field} placeholder="parent_phone" invalid={!!errors.recipient_field} disabled={!editable} onChange={(e) => set('recipient_field', e.target.value)} />
            </Field>
          )}
        </div>
      </section>

      <section>
        <h4 className="text-sm font-semibold text-slate-900 dark:text-white">Variable mapping</h4>
        <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">
          Each template variable is filled from the event payload. <em>Automatic</em> uses the payload key with the same name, then the contact&apos;s CRM / ERP data (student, application, counsellor, institute).
          For data that may be missing, add an <em>If empty</em> text (e.g. <code className="font-mono">course_name</code> → &ldquo;our programmes&rdquo; for a lead who has not chosen a programme) — otherwise the message is refused for a missing variable.
        </p>
        {errors.variable_map && <p className="form-error mb-2" role="alert">{errors.variable_map}</p>}
        {tplVars.length === 0 && extraMapKeys.length === 0 ? (
          <p className="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-center text-xs text-slate-500 dark:border-slate-700">
            {tpl ? 'This template has no variables.' : 'Link a template to map its variables.'}
          </p>
        ) : (
          <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
            <div className="hidden grid-cols-[minmax(0,1fr)_150px_minmax(0,1.2fr)_32px] gap-2 bg-slate-50 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500 sm:grid dark:bg-slate-800/60">
              <span>Template variable</span><span>Filled from</span><span>Value</span><span />
            </div>
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {[...tplVars.map((v) => ({ key: v.key, label: `{{${v.pos}}} ${v.part}`, sample: v.sample, extra: false })), ...extraMapKeys.map((k) => ({ key: k, label: 'not in template', sample: '', extra: true }))].map((v) => {
                const src = parseSource(form.variable_map[v.key]);
                const showFallback = src.kind !== 'text' && (src.fallback !== '' || fallbackOpen.includes(v.key));
                return (
                  <li key={v.key} className="grid grid-cols-1 items-start gap-2 px-3 py-2 sm:grid-cols-[minmax(0,1fr)_150px_minmax(0,1.2fr)_32px]">
                    <div className="min-w-0 sm:pt-1">
                      <code className="font-mono text-xs font-semibold text-brand-800 dark:text-brand-200">{v.key}</code>
                      <div className="truncate text-[11px] text-slate-400">{v.label}{v.sample ? ` · e.g. ${v.sample}` : ''}</div>
                    </div>
                    <Select aria-label={`Source for ${v.key}`} inputSize="sm" value={src.kind} disabled={!editable}
                      onChange={(e) => setMap(v.key, { kind: e.target.value as MapKind, value: e.target.value === 'payload' ? (sampleKeys.includes(v.key) ? v.key : '') : '' })}
                      options={[{ value: 'auto', label: 'Automatic' }, { value: 'payload', label: 'Payload field' }, { value: 'contact', label: 'Contact field' }, { value: 'text', label: 'Fixed text' }]} />
                    <div className="min-w-0 space-y-1.5">
                      {src.kind === 'auto' ? (
                        <span className="block pt-1.5 text-xs text-slate-500 dark:text-slate-400">{sampleKeys.includes(v.key) ? <>payload <code className="font-mono">{v.key}</code></> : 'contact / ERP data'}</span>
                      ) : src.kind === 'contact' ? (
                        <Select aria-label={`Contact field for ${v.key}`} inputSize="sm" value={src.value} disabled={!editable} placeholder="Choose a field" onChange={(e) => setMap(v.key, { value: e.target.value })}
                          options={Object.entries(options.contact_fields).map(([value, label]) => ({ value, label }))} />
                      ) : (
                        <Input aria-label={`${src.kind === 'text' ? 'Text' : 'Payload key'} for ${v.key}`} inputSize="sm" list={src.kind === 'payload' ? `${uid}-keys` : undefined} value={src.value} disabled={!editable}
                          placeholder={src.kind === 'text' ? 'Fixed value' : 'payload key'} onChange={(e) => setMap(v.key, { value: e.target.value })} />
                      )}
                      {showFallback ? (
                        <div className="flex items-center gap-2">
                          <label htmlFor={`${uid}-fb-${v.key}`} className="shrink-0 text-[11px] font-medium text-slate-500 dark:text-slate-400">If empty</label>
                          <Input id={`${uid}-fb-${v.key}`} inputSize="sm" value={src.fallback} maxLength={200} disabled={!editable} placeholder="e.g. our programmes"
                            onChange={(e) => setMap(v.key, { fallback: e.target.value.replace(/[\r\n]+/g, ' ') })} />
                        </div>
                      ) : (
                        src.kind !== 'text' && editable && (
                          <button type="button" className="inline-flex items-center gap-1 rounded text-[11px] font-medium text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:text-brand-300"
                            onClick={() => setFallbackOpen((o) => [...o, v.key])}>
                            <Plus className="h-3 w-3" aria-hidden /> Text if empty
                          </button>
                        )
                      )}
                    </div>
                    {v.extra || src.kind !== 'auto' || src.fallback !== '' ? (
                      <IconButton icon={Trash} label={`Reset ${v.key}`} size="sm" disabled={!editable} onClick={() => resetMap(v.key)} />
                    ) : (
                      <span />
                    )}
                  </li>
                );
              })}
            </ul>
          </div>
        )}
      </section>

      <section className="space-y-3">
        <h4 className="text-sm font-semibold text-slate-900 dark:text-white">Timing</h4>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Delay (minutes)" htmlFor={`${uid}-delay`} error={errors.delay_minutes} hint="0 = send immediately.">
            <Input id={`${uid}-delay`} type="number" min={0} max={43200} value={form.delay_minutes} invalid={!!errors.delay_minutes} disabled={!editable} onChange={(e) => set('delay_minutes', e.target.value)} />
          </Field>
          <div className="pt-1 sm:pt-6">
            <Toggle checked={form.respect_business_hours} onChange={(v) => set('respect_business_hours', v)} disabled={!editable} label="Business hours only" description="Outside office hours the message waits for the next business hour." />
          </div>
        </div>
        {event.is_scheduled && (
          <div className="rounded-xl border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
            <p className="mb-3 flex items-center gap-1.5 text-xs font-medium text-amber-900 dark:text-amber-200">
              <CalendarClock className="h-3.5 w-3.5" aria-hidden /> Daily scheduled event — the worker scans once a day after the send time{event.last_scan_at ? ` (last scan ${event.last_scan_at})` : ''}.
            </p>
            {errors.schedule_config && <p className="form-error mb-2" role="alert">{errors.schedule_config}</p>}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              {event.code !== 'GIMT_PAYMENT_PENDING' && (
                <>
                  {['GIMT_FEE_INSTALMENT_DUE', 'GIMT_FEE_OVERDUE', 'GIMT_ADMISSION_DEADLINE', 'GIMT_APPLICATION_INCOMPLETE', 'GIMT_SUPPORT_FOLLOWUP'].includes(event.code) && (
                    <Field
                      label={event.code === 'GIMT_SUPPORT_FOLLOWUP' ? 'No update for (days)' : event.code === 'GIMT_FEE_INSTALMENT_DUE' || event.code === 'GIMT_ADMISSION_DEADLINE' ? 'Days before' : 'Days after'}
                      htmlFor={`${uid}-days`}
                      hint={event.code === 'GIMT_SUPPORT_FOLLOWUP' ? 'Tickets idle for this many days, e.g. 2' : 'Comma separated, e.g. 3, 1'}
                    >
                      <Input id={`${uid}-days`} inputSize="sm" value={form.schedule_days} disabled={!editable} onChange={(e) => set('schedule_days', e.target.value)} />
                    </Field>
                  )}
                  <Field label="Send time" htmlFor={`${uid}-time`}>
                    <Input id={`${uid}-time`} type="time" inputSize="sm" value={form.schedule_time} disabled={!editable} onChange={(e) => set('schedule_time', e.target.value)} />
                  </Field>
                </>
              )}
              {event.code === 'GIMT_PAYMENT_PENDING' && (
                <Field label="Unpaid for (minutes)" htmlFor={`${uid}-min`} hint="Checked every 10 minutes.">
                  <Input id={`${uid}-min`} type="number" min={10} max={4320} inputSize="sm" value={form.schedule_minutes} disabled={!editable} onChange={(e) => set('schedule_minutes', e.target.value)} />
                </Field>
              )}
            </div>
          </div>
        )}
      </section>

      <section>
        <Field
          label="Sample payload (JSON)"
          htmlFor={`${uid}-sample`}
          error={errors.sample_payload || sampleError}
          hint="Used by the Test tab, the integration examples and the field suggestions above."
        >
          <Textarea id={`${uid}-sample`} rows={10} spellCheck={false} className="font-mono text-xs" value={form.sample_payload} disabled={!editable} invalid={!!(errors.sample_payload || sampleError)} onChange={(e) => set('sample_payload', e.target.value)} />
        </Field>
        {sampleError && (
          <p className="mt-1 flex items-center gap-1 text-[11px] text-amber-700 dark:text-amber-300"><TriangleAlert className="h-3 w-3" aria-hidden /> Fix the JSON before saving.</p>
        )}
      </section>
    </div>
  );
}
