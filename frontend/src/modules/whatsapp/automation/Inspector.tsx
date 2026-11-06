import { useMemo, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { AlertTriangle, Braces, CalendarClock, Copy, Info, Plus, Trash2, X } from 'lucide-react';
import { Alert, Badge, Button, Combobox, Dropdown, Field, IconButton, Input, RadioGroup, Select, Textarea, Toggle } from '@/components/ui';
import { useAuth } from '@/lib/auth';
import type { Option } from '@/lib/types';
import { dateTimeVarProblem, deadlineKeys, deadlineProblem, edgeTarget, isDateTimeKey, typedDateAllowed, urlError } from './graph';
import { NODE_SPECS, PORT_LABEL, nodeTitle } from './nodeTypes';
import type { Definition, Issue, Meta, MetaTemplate, NodeData, Port, Rule, WfNode } from './types';

const NO_VALUE_OPS = ['is_empty', 'not_empty', 'is_true', 'is_false'];
const UNITS = [
  { value: 'minutes', label: 'Minutes' },
  { value: 'hours', label: 'Hours' },
  { value: 'days', label: 'Days' },
];

/* ------------------------------------------------------------------------------------------ small pieces */

function Section({ title, children, aside }: { title: string; children: ReactNode; aside?: ReactNode }) {
  return (
    <section className="space-y-3 border-t border-slate-100 px-4 py-4 first:border-t-0 dark:border-slate-800">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">{title}</h3>
        {aside}
      </div>
      {children}
    </section>
  );
}

/** "Insert variable" menu — appends {{key}} to a text field. */
export function VariableMenu({ meta, onPick, label = 'Variable' }: { meta?: Meta; onPick: (key: string) => void; label?: string }) {
  const items = (meta?.variables ?? []).map((v) => ({ label: `{{${v.key}}}`, hint: v.label.length > 26 ? `${v.label.slice(0, 25)}…` : v.label, onClick: () => onPick(v.key) }));
  return (
    <Dropdown
      label="Insert a variable"
      triggerClassName="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10"
      trigger={
        <>
          <Braces className="h-3.5 w-3.5" aria-hidden />
          {label}
        </>
      }
      width="w-72 max-h-72 overflow-y-auto"
      items={items.length ? items : [{ label: 'No variables available', disabled: true }]}
    />
  );
}

/** Textarea / input with a variable menu that inserts at the cursor. */
function VarTextarea({ value, onChange, meta, rows = 4, placeholder, invalid, mono, disabled, id }: { value: string; onChange: (v: string) => void; meta?: Meta; rows?: number; placeholder?: string; invalid?: boolean; mono?: boolean; disabled?: boolean; id?: string }) {
  const ref = useRef<HTMLTextAreaElement>(null);
  const insert = (key: string) => {
    const el = ref.current;
    const token = `{{${key}}}`;
    if (!el) return onChange(value + token);
    const s = el.selectionStart ?? value.length;
    const e = el.selectionEnd ?? value.length;
    onChange(value.slice(0, s) + token + value.slice(e));
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(s + token.length, s + token.length);
    });
  };
  return (
    <div>
      <Textarea id={id} ref={ref} rows={rows} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} invalid={invalid} disabled={disabled} className={clsx(mono && 'font-mono text-xs')} />
      {!disabled && (
        <div className="mt-1 flex justify-end">
          <VariableMenu meta={meta} onPick={insert} label="Insert variable" />
        </div>
      )}
    </div>
  );
}

/** Field select with option groups (+ "custom" for payload / vars paths). */
function FieldSelect({ value, onChange, meta, roots, disabled }: { value: string; onChange: (v: string) => void; meta?: Meta; roots: string[]; disabled?: boolean }) {
  const fields = (meta?.condition_fields ?? []).filter((f) => roots.includes(f.value.split('.')[0]));
  const known = fields.some((f) => f.value === value);
  const custom = !known && value !== '';
  const groups = Array.from(new Set(fields.map((f) => f.group)));
  return (
    <div className="space-y-1.5">
      <select
        className="form-input form-input-sm pr-8"
        value={known ? value : custom ? '__custom' : ''}
        disabled={disabled}
        aria-label="Field to check"
        onChange={(e) => onChange(e.target.value === '__custom' ? 'payload.' : e.target.value)}
      >
        <option value="">Choose a field…</option>
        {groups.map((g) => (
          <optgroup key={g} label={g}>
            {fields.filter((f) => f.group === g).map((f) => (
              <option key={f.value} value={f.value}>
                {f.label}
              </option>
            ))}
          </optgroup>
        ))}
        <optgroup label="Other">
          <option value="__custom">Event payload / variable field…</option>
        </optgroup>
      </select>
      {(custom || value === 'payload.') && (
        <Input inputSize="sm" value={value} disabled={disabled} onChange={(e) => onChange(e.target.value.replace(/[^A-Za-z0-9_.]/g, ''))} placeholder="payload.amount or vars.crm.status" aria-label="Custom field path" />
      )}
    </div>
  );
}

export function RuleEditor({ rules, onChange, meta, single, roots, disabled }: { rules: Rule[]; onChange: (r: Rule[]) => void; meta?: Meta; single?: boolean; roots: string[]; disabled?: boolean }) {
  const list = single ? rules.slice(0, 1) : rules;
  const set = (i: number, patch: Partial<Rule>) => onChange(list.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  return (
    <div className="space-y-2.5">
      {list.map((r, i) => {
        const kind = meta?.condition_fields.find((f) => f.value === r.field)?.kind ?? 'text';
        const ops = (meta?.condition_ops ?? []).filter((o) =>
          kind === 'bool' ? ['is_true', 'is_false'].includes(String(o.value)) : kind === 'tags' ? ['has_tag', 'not_has_tag'].includes(String(o.value)) : !['has_tag', 'not_has_tag'].includes(String(o.value)),
        );
        return (
          <div key={i} className="space-y-1.5 rounded-xl border border-slate-200 bg-slate-50/60 p-2.5 dark:border-slate-700 dark:bg-slate-800/40">
            <div className="flex items-start gap-1.5">
              <div className="min-w-0 flex-1">
                <FieldSelect value={r.field} meta={meta} roots={roots} disabled={disabled} onChange={(field) => {
                  const k = meta?.condition_fields.find((f) => f.value === field)?.kind;
                  set(i, { field, op: k === 'bool' ? 'is_true' : k === 'tags' ? 'has_tag' : r.op && !['is_true', 'is_false', 'has_tag', 'not_has_tag'].includes(r.op) ? r.op : 'equals' });
                }} />
              </div>
              {!single && !disabled && <IconButton icon={X} label="Remove rule" size="sm" tone="danger" onClick={() => onChange(list.filter((_, j) => j !== i))} />}
            </div>
            <div className="grid grid-cols-2 gap-1.5">
              <select className="form-input form-input-sm pr-8" value={r.op} disabled={disabled} onChange={(e) => set(i, { op: e.target.value })} aria-label="Comparison">
                {ops.map((o) => (
                  <option key={String(o.value)} value={String(o.value)}>
                    {o.label}
                  </option>
                ))}
              </select>
              {NO_VALUE_OPS.includes(r.op) ? (
                <span className="self-center text-xs text-slate-400">no value needed</span>
              ) : kind === 'stage' ? (
                <select className="form-input form-input-sm pr-8" value={r.value ?? ''} disabled={disabled} onChange={(e) => set(i, { value: e.target.value })} aria-label="Stage">
                  <option value="">Choose…</option>
                  {(meta?.stages ?? []).map((s) => (
                    <option key={String(s.value)} value={String(s.value)}>
                      {s.label}
                    </option>
                  ))}
                </select>
              ) : (
                <Input inputSize="sm" value={r.value ?? ''} disabled={disabled} onChange={(e) => set(i, { value: e.target.value })} placeholder={kind === 'tags' ? 'Tag name' : 'Value'} aria-label="Value" list={kind === 'tags' ? 'wf-tag-list' : undefined} />
              )}
            </div>
          </div>
        );
      })}
      <datalist id="wf-tag-list">
        {(meta?.tags ?? []).map((t) => (
          <option key={t.name} value={t.name} />
        ))}
      </datalist>
      {!single && !disabled && (
        <Button size="xs" variant="soft" icon={Plus} onClick={() => onChange([...list, { field: roots.includes('reply') ? 'reply.received' : 'payload.', op: roots.includes('reply') ? 'is_true' : 'equals', value: '' }])}>
          Add rule
        </Button>
      )}
    </div>
  );
}

function TagInput({ value, onChange, meta, disabled }: { value: string[]; onChange: (v: string[]) => void; meta?: Meta; disabled?: boolean }) {
  const [text, setText] = useState('');
  const add = (t: string) => {
    const name = t.trim().slice(0, 60);
    if (name && !value.some((v) => v.toLowerCase() === name.toLowerCase())) onChange([...value, name]);
    setText('');
  };
  const suggestions = (meta?.tags ?? []).map((t) => t.name).filter((n) => !value.includes(n)).slice(0, 12);
  return (
    <div className="space-y-2">
      <div className="flex min-h-[2.5rem] flex-wrap items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-2 py-1.5 dark:border-slate-700 dark:bg-slate-900">
        {value.map((t) => (
          <span key={t} className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">
            {t}
            {!disabled && (
              <button type="button" aria-label={`Remove tag ${t}`} onClick={() => onChange(value.filter((v) => v !== t))} className="rounded-full hover:text-red-600">
                <X className="h-3 w-3" />
              </button>
            )}
          </span>
        ))}
        {!disabled && (
          <input
            value={text}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                add(text);
              } else if (e.key === 'Backspace' && !text && value.length) onChange(value.slice(0, -1));
            }}
            onBlur={() => text && add(text)}
            placeholder={value.length ? 'Add…' : 'Type a tag and press Enter'}
            className="min-w-[8rem] flex-1 border-0 bg-transparent p-0.5 text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0 dark:text-slate-100"
            aria-label="Add a tag"
          />
        )}
      </div>
      {!disabled && suggestions.length > 0 && (
        <div className="flex flex-wrap gap-1">
          {suggestions.map((s) => (
            <button key={s} type="button" onClick={() => add(s)} className="rounded-full border border-dashed border-slate-300 px-2 py-0.5 text-[11px] text-slate-600 hover:border-brand-400 hover:text-brand-700 dark:border-slate-600 dark:text-slate-300">
              + {s}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

/** WhatsApp-like preview of a template with the mapped / sample values. */
function TemplatePreview({ tpl, vars }: { tpl: MetaTemplate; vars: Record<string, string> }) {
  const fill = (text: string) =>
    text.replace(/\{\{\s*(\d+)\s*\}\}/g, (_, n: string) => {
      const v = tpl.variables.find((x) => x.part === 'body' && x.pos === Number(n));
      if (!v) return `{{${n}}}`;
      const mapped = vars[v.key];
      return mapped && mapped.trim() ? mapped : v.sample || `{{${v.key}}}`;
    });
  return (
    <div className="rounded-xl bg-[#efeae2] p-3 dark:bg-slate-800">
      <div className="max-w-full rounded-lg rounded-tl-none bg-white p-2.5 text-[12.5px] leading-snug text-slate-800 shadow-sm dark:bg-slate-900 dark:text-slate-100">
        {tpl.header_type === 'text' && tpl.header_text && <p className="mb-1 font-semibold">{tpl.header_text.replace(/\{\{\s*\d+\s*\}\}/g, (m) => m)}</p>}
        {tpl.header_type !== 'none' && tpl.header_type !== 'text' && <p className="mb-1.5 rounded bg-slate-100 py-3 text-center text-[11px] text-slate-500 dark:bg-slate-800">{tpl.header_type} header</p>}
        <p className="whitespace-pre-line break-words">{fill(tpl.body)}</p>
        {tpl.footer && <p className="mt-1 text-[11px] text-slate-400">{tpl.footer}</p>}
        {tpl.buttons.length > 0 && (
          <div className="mt-2 space-y-1 border-t border-slate-100 pt-1.5 dark:border-slate-800">
            {tpl.buttons.map((b, i) => (
              <p key={i} className="text-center text-[12px] font-medium text-sky-600 dark:text-sky-400">
                {b.text}
              </p>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

/** Where {{admissions_deadline}} / {{last_date}} come from, its current value, and a link to change it. */
export function DeadlineNote({ meta, keys }: { meta?: Meta; keys: string[] }) {
  const { can } = useAuth();
  const d = meta?.deadline;
  if (!d || !keys.length) return null;
  const problem = deadlineProblem(meta);
  const link = can('settings', 'view') ? (
    <Link to={d.url} className="font-semibold underline underline-offset-2">
      Open Settings › Website
    </Link>
  ) : (
    <span>Ask an administrator to set it in Settings › Website.</span>
  );
  return problem ? (
    <Alert variant="warning" title="Needs the admissions deadline">
      <span className="block">
        <code className="text-[11px]">{keys.map((k) => `{{${k}}}`).join(', ')}</code> is filled from the admissions deadline, but {problem}. This step fails for every contact until it is set (or a date is typed above).
      </span>
      <span className="mt-1 block text-xs">{d.where} · {link}</span>
    </Alert>
  ) : (
    <p className="flex items-start gap-1.5 rounded-lg bg-sky-50 px-2.5 py-2 text-xs text-sky-900 dark:bg-sky-500/10 dark:text-sky-100">
      <CalendarClock className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
      <span>
        <code className="text-[11px]">{keys.map((k) => `{{${k}}}`).join(', ')}</code> = <strong>{d.value}</strong> — the admissions deadline from {d.where}. When it passes or is cleared, steps that use it fail until it is updated.
      </span>
    </p>
  );
}

/* ------------------------------------------------------------------------------------------ per-type forms */

function NodeForm({ node, set, meta, readOnly }: { node: WfNode; set: (patch: NodeData) => void; meta?: Meta; readOnly?: boolean }) {
  const d = node.data;
  const ro = readOnly;
  switch (node.type) {
    case 'trigger': {
      const ev = meta?.events.find((e) => e.code === d.event);
      const cats = Array.from(new Set((meta?.events ?? []).map((e) => e.category)));
      return (
        <>
          <Section title="Event">
            <Field label="Start this workflow when" required htmlFor="wf-trigger-event" error={!d.event ? 'Choose an event.' : null}>
              <select id="wf-trigger-event" className="form-input pr-8" value={d.event ?? ''} disabled={ro} onChange={(e) => set({ event: e.target.value })}>
                <option value="">Choose an event…</option>
                <option value="manual">Manual enrolment (contacts are added by staff)</option>
                {cats.map((c) => (
                  <optgroup key={c} label={c === 'whatsapp' ? 'WhatsApp' : c.charAt(0).toUpperCase() + c.slice(1)}>
                    {(meta?.events ?? []).filter((e) => e.category === c).map((e) => (
                      <option key={e.code} value={e.code}>
                        {e.name}
                      </option>
                    ))}
                  </optgroup>
                ))}
              </select>
            </Field>
            {ev && (
              <div className="rounded-xl bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                <p className="font-mono text-[11px] font-semibold text-brand-800 dark:text-brand-200">{ev.code}</p>
                {ev.description && <p className="mt-1">{ev.description}</p>}
                {Object.keys(ev.sample ?? {}).length > 0 && (
                  <p className="mt-1.5 text-slate-500 dark:text-slate-400">
                    Payload: {Object.keys(ev.sample).slice(0, 10).map((k) => (
                      <code key={k} className="mr-1 rounded bg-white px-1 py-px text-[10px] dark:bg-slate-900">
                        {k}
                      </code>
                    ))}
                  </p>
                )}
              </div>
            )}
          </Section>
          <Section title="Only when (optional filters)">
            <p className="text-xs text-slate-500 dark:text-slate-400">Every rule must match the event, e.g. <code className="text-[11px]">payload.to</code> is <code className="text-[11px]">payment_pending</code>.</p>
            <RuleEditor rules={Array.isArray(d.filters) ? d.filters : []} onChange={(filters) => set({ filters })} meta={meta} roots={['payload', 'contact']} disabled={ro} />
          </Section>
          {d.event !== 'manual' && (
            <Section
              title="Schedule (optional)"
              aside={!ro && (d.active_from || d.active_until) ? <Button size="xs" variant="ghost" onClick={() => set({ active_from: '', active_until: '' })}>Clear</Button> : null}
            >
              <p className="text-xs text-slate-500 dark:text-slate-400">New runs start only inside this window (IST), e.g. an admission season. Runs already in progress always finish. Empty = always.</p>
              <div className="grid grid-cols-1 gap-2">
                <Field label="Active from" htmlFor="wf-from">
                  <Input id="wf-from" type="datetime-local" value={String(d.active_from ?? '').replace(' ', 'T').slice(0, 16)} disabled={ro} onChange={(e) => set({ active_from: e.target.value })} />
                </Field>
                <Field label="Active until" htmlFor="wf-until">
                  <Input id="wf-until" type="datetime-local" value={String(d.active_until ?? '').replace(' ', 'T').slice(0, 16)} disabled={ro} onChange={(e) => set({ active_until: e.target.value })} />
                </Field>
              </div>
            </Section>
          )}
        </>
      );
    }
    case 'contact':
      return (
        <Section title="Contact">
          <p className="text-xs text-slate-500 dark:text-slate-400">Uses <code>phone</code>, <code>name</code>, <code>email</code>, <code>enquiry_id</code>, <code>student_id</code>, <code>course_name</code> from the event. An existing contact with the same number is linked, never duplicated.</p>
          <Field label="Contact type for new contacts" htmlFor="wf-ct">
            <Select id="wf-ct" value={d.contact_type ?? 'lead'} disabled={ro} onChange={(e) => set({ contact_type: e.target.value })} options={{ lead: 'Lead', applicant: 'Applicant', student: 'Student', guardian: 'Parent / guardian', alumni: 'Alumni', other: 'Other' }} />
          </Field>
          <Field label="Start new contacts in stage" htmlFor="wf-cs" hint="Only applied when the contact is created.">
            <Select id="wf-cs" value={d.stage ?? ''} disabled={ro} onChange={(e) => set({ stage: e.target.value })} options={meta?.stages ?? []} placeholder="Keep the default" />
          </Field>
          <Toggle
            checked={!!d.create_enquiry}
            disabled={ro}
            onChange={(v) => set({ create_enquiry: v })}
            label="Also create an ERP admission enquiry"
            description="Generates the enquiry ID: the lead appears in Admissions › Enquiries (source WhatsApp, with the course from the event)."
          />
          {d.create_enquiry && (
            <div className="space-y-1.5 rounded-xl bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
              <p>
                A contact already linked to an enquiry — or whose number has an open enquiry — is linked to it instead of getting a second one. Students, alumni, staff and applicants are skipped.
              </p>
              <p>
                Later steps can use <code>{'{{enquiry_no}}'}</code> (the ERP reference, e.g. #154) and <code>{'{{enquiry_id}}'}</code>. If the enquiry cannot be created, this step shows the error and the run continues.
              </p>
            </div>
          )}
        </Section>
      );
    case 'template': {
      const tpl = meta?.templates.find((t) => t.name === d.template || String(t.id) === String(d.template));
      const vars: Record<string, string> = d.vars && typeof d.vars === 'object' && !Array.isArray(d.vars) ? d.vars : {};
      const keys = Array.from(new Map((tpl?.variables ?? []).map((v) => [v.key, v])).values());
      // one option per template name (language versions are picked per contact when sending)
      const byName = new Map<string, MetaTemplate[]>();
      (meta?.templates ?? []).forEach((t) => byName.set(t.name, [...(byName.get(t.name) ?? []), t]));
      const opts: Option[] = Array.from(byName.values()).map((list) => {
        const t = list.find((x) => x.status === 'approved') ?? list[0];
        return { value: t.name, label: `${t.display_name || t.name}${t.status !== 'approved' ? ` (${t.status})` : ''}`, sub: `${t.name} · ${t.category.toLowerCase()} · ${list.map((x) => x.language).join(', ')}` };
      });
      return (
        <>
          <Section title="Template">
            <Field label="WhatsApp template" required htmlFor="wf-tpl" error={!d.template ? 'Pick a template.' : !tpl && meta ? 'This template no longer exists.' : null}>
              <Combobox id="wf-tpl" options={opts} value={d.template || null} disabled={ro} onChange={(v) => set({ template: v ? String(v) : '', vars: {} })} placeholder="Search templates…" />
            </Field>
            {tpl && tpl.status !== 'approved' && (
              <Alert variant="warning">This template is <strong>{tpl.status}</strong>. WhatsApp only delivers approved templates — submit it from the Template Library.</Alert>
            )}
            {tpl?.category === 'MARKETING' && <p className="text-xs text-slate-500 dark:text-slate-400">Marketing template: only sent to contacts who opted in.</p>}
          </Section>
          {tpl && (
            <Section title="Variables">
              {keys.length === 0 ? (
                <p className="text-xs text-slate-500">This template has no variables.</p>
              ) : (
                <div className="space-y-2.5">
                  <p className="text-xs text-slate-500 dark:text-slate-400">Leave a value empty to fill it automatically from the contact / event. You can type text and use variables.</p>
                  {keys.map((v) => {
                    const fake = dateTimeVarProblem(v.key, vars[v.key]);
                    return (
                      <Field
                        key={v.key}
                        label={<code className="text-[11px]">{`{{${v.key}}}`}</code>}
                        htmlFor={`wf-var-${v.key}`}
                        error={fake ? `${fake} — map it to real data, e.g. {{payload.${v.key}}}, or leave it empty.` : null}
                        hint={
                          fake || !isDateTimeKey(v.key)
                            ? undefined
                            : typedDateAllowed(v.key)
                              ? 'Institute-wide date: type it once or use a variable — not {{today}} / {{tomorrow}}.'
                              : 'Personal date / time: use a variable from the event or contact — never a typed or relative value.'
                        }
                      >
                        <div className="flex items-center gap-1">
                          <Input id={`wf-var-${v.key}`} inputSize="sm" value={vars[v.key] ?? ''} disabled={ro} invalid={!!fake} placeholder={`Auto · e.g. ${v.sample || v.key}`} onChange={(e) => set({ vars: { ...vars, [v.key]: e.target.value } })} />
                          {!ro && <VariableMenu meta={meta} label="" onPick={(k) => set({ vars: { ...vars, [v.key]: `${vars[v.key] ?? ''}{{${k}}}` } })} />}
                        </div>
                      </Field>
                    );
                  })}
                </div>
              )}
              <DeadlineNote meta={meta} keys={deadlineKeys(node, meta)} />
              <TemplatePreview tpl={tpl} vars={vars} />
            </Section>
          )}
        </>
      );
    }
    case 'message':
      return (
        <Section title="Message">
          <Alert variant="info">Free-text messages reach the contact only within 24 hours of their last message. Outside the window the step is skipped — use a template instead.</Alert>
          <Field label="Message text" required htmlFor="wf-msg" aside={`${String(d.text ?? '').length}/4096`} error={!String(d.text ?? '').trim() && !d.media_url ? 'Write the message.' : null}>
            <VarTextarea id="wf-msg" value={String(d.text ?? '')} onChange={(text) => set({ text })} meta={meta} rows={6} placeholder="Hi {{student_name}}, …" disabled={ro} />
          </Field>
          <Field label="Attachment" htmlFor="wf-media">
            <Select id="wf-media" value={d.media_type ?? 'none'} disabled={ro} onChange={(e) => set({ media_type: e.target.value })} options={{ none: 'No attachment', image: 'Image', document: 'Document (PDF)', video: 'Video' }} />
          </Field>
          {d.media_type && d.media_type !== 'none' && (
            <Field label="Public https:// URL of the file" required htmlFor="wf-media-url" error={!/^https:\/\//i.test(String(d.media_url ?? '')) ? 'Use a public https:// URL.' : null}>
              <Input id="wf-media-url" value={d.media_url ?? ''} disabled={ro} onChange={(e) => set({ media_url: e.target.value })} placeholder="https://gimtedu.com/brochures/bba.pdf" />
            </Field>
          )}
        </Section>
      );
    case 'delay':
    case 'wait_reply': {
      const isReply = node.type === 'wait_reply';
      const bad = !Number.isInteger(Number(d.value)) || Number(d.value) < 1;
      return (
        <Section title={isReply ? 'Wait for a reply' : 'Delay'}>
          <div className="grid grid-cols-2 gap-2">
            <Field label={isReply ? 'Timeout' : 'Wait'} required htmlFor="wf-dv" error={bad ? 'Whole number ≥ 1.' : null}>
              <Input id="wf-dv" type="number" min={1} step={1} value={d.value ?? ''} disabled={ro} onChange={(e) => set({ value: e.target.value === '' ? '' : Math.floor(Number(e.target.value)) })} />
            </Field>
            <Field label="Unit" htmlFor="wf-du">
              <Select id="wf-du" value={d.unit ?? 'hours'} disabled={ro} onChange={(e) => set({ unit: e.target.value })} options={UNITS} />
            </Field>
          </div>
          {isReply ? (
            <>
              <Toggle checked={d.since === 'run'} disabled={ro} onChange={(v) => set({ since: v ? 'run' : 'node' })} label="Count replies since the workflow started" description="If the contact already replied earlier in this run, continue on Replied at once." />
              <Field label="Only these replies count (optional)" htmlFor="wf-kw" hint="Comma-separated keywords, e.g. yes, interested, call. Empty = any reply.">
                <Input id="wf-kw" value={d.keywords ?? ''} disabled={ro} onChange={(e) => set({ keywords: e.target.value })} placeholder="yes, interested" />
              </Field>
              <p className="text-xs text-slate-500 dark:text-slate-400">The reply text is available as <code>{'{{last_reply}}'}</code> in later steps.</p>
            </>
          ) : (
            <p className="text-xs text-slate-500 dark:text-slate-400">The worker resumes the run when the time is up (checked every minute). Test runs skip the wait.</p>
          )}
        </Section>
      );
    }
    case 'condition':
    case 'ifelse':
      return (
        <Section title={node.type === 'ifelse' ? 'If' : 'Rules'}>
          {node.type === 'condition' && (
            <RadioGroup name="wf-match" value={d.match === 'any' ? 'any' : 'all'} onChange={(match) => !ro && set({ match })} options={[{ value: 'all', label: 'All rules match' }, { value: 'any', label: 'Any rule matches' }]} />
          )}
          <RuleEditor rules={Array.isArray(d.rules) ? d.rules : []} onChange={(rules) => set({ rules })} meta={meta} single={node.type === 'ifelse'} roots={['reply', 'contact', 'payload', 'vars']} disabled={ro} />
          <p className="text-xs text-slate-500 dark:text-slate-400">Yes → the green port, No → the red port. Stage and tags are read live when the step runs.</p>
        </Section>
      );
    case 'tag':
      return (
        <Section title="Tags">
          <RadioGroup name="wf-tag-action" value={d.action === 'remove' ? 'remove' : 'add'} onChange={(action) => !ro && set({ action })} options={[{ value: 'add', label: 'Add tags' }, { value: 'remove', label: 'Remove tags' }]} />
          <TagInput value={Array.isArray(d.tags) ? d.tags : []} onChange={(tags) => set({ tags })} meta={meta} disabled={ro} />
        </Section>
      );
    case 'stage':
      return (
        <Section title="Update">
          <Field label="Admission follow-up stage" htmlFor="wf-stage">
            <Select id="wf-stage" value={d.stage ?? ''} disabled={ro} onChange={(e) => set({ stage: e.target.value })} options={meta?.stages ?? []} placeholder="Do not change" />
          </Field>
          <Field label="Lead (enquiry) status" htmlFor="wf-lead" hint="Updates the linked enquiry; converted / closed enquiries are never changed.">
            <Select id="wf-lead" value={d.lead_status ?? ''} disabled={ro} onChange={(e) => set({ lead_status: e.target.value })} options={meta?.lead_statuses ?? []} placeholder="Do not change" />
          </Field>
          {!d.stage && !d.lead_status && <p className="form-error">Choose a stage and/or a lead status.</p>}
          <p className="text-xs text-slate-500 dark:text-slate-400">A stage change can start other workflows (trigger “Admission stage changed”) and the stage follow-ups.</p>
        </Section>
      );
    case 'assign':
      return (
        <Section title="Assignment">
          <RadioGroup name="wf-assign" value={d.mode === 'specific' ? 'specific' : 'round_robin'} onChange={(mode) => !ro && set({ mode })} options={[{ value: 'round_robin', label: 'Round-robin' }, { value: 'specific', label: 'Specific counsellor' }]} />
          {d.mode === 'specific' ? (
            <Field label="Counsellor" required htmlFor="wf-user" error={!d.user_id ? 'Choose a counsellor.' : null}>
              <Combobox id="wf-user" options={meta?.counsellors ?? []} value={d.user_id || null} disabled={ro} onChange={(v) => set({ user_id: v ?? '' })} placeholder="Search staff…" />
            </Field>
          ) : (
            <Field label="Counsellor pool" htmlFor="wf-pool" hint="Empty = every active admission counsellor. Leads are shared in turn.">
              <Combobox id="wf-pool" multiple options={meta?.counsellors ?? []} value={Array.isArray(d.pool) ? d.pool : []} disabled={ro} onChange={(pool) => set({ pool })} placeholder="All counsellors" />
            </Field>
          )}
          <Toggle checked={d.keep_existing !== false} disabled={ro} onChange={(v) => set({ keep_existing: v })} label="Keep an existing counsellor" description="Skip contacts that already have one." />
          <Toggle checked={d.sync_enquiry !== false} disabled={ro} onChange={(v) => set({ sync_enquiry: v })} label="Also assign the linked enquiry" description="Only when the enquiry has no counsellor yet." />
          <Toggle checked={d.notify !== false} disabled={ro} onChange={(v) => set({ notify: v })} label="Notify the counsellor" />
        </Section>
      );
    case 'api': {
      const err = urlError(d.url);
      const headers: { key: string; value: string; masked?: boolean }[] = Array.isArray(d.headers) ? d.headers : [];
      const setHeader = (i: number, patch: Partial<{ key: string; value: string }>) => set({ headers: headers.map((h, j) => (j === i ? { ...h, ...patch, masked: false } : h)) });
      return (
        <>
          <Section title="Request">
            <div className="grid grid-cols-1 gap-2 2xl:grid-cols-[7rem_minmax(0,1fr)]">
              <Field label="Method" htmlFor="wf-method">
                <Select id="wf-method" value={String(d.method ?? 'POST').toUpperCase()} disabled={ro} onChange={(e) => set({ method: e.target.value })} options={['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => ({ value: m, label: m }))} />
              </Field>
              <Field label="URL" required htmlFor="wf-url" error={err}>
                <Input id="wf-url" value={d.url ?? ''} disabled={ro} onChange={(e) => set({ url: e.target.value })} placeholder="https://crm.example.com/api/leads" />
              </Field>
            </div>
            <p className="flex items-start gap-1.5 text-xs text-slate-500 dark:text-slate-400">
              <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
              HTTPS only · the host name is checked against private / local addresses (also after DNS) · {meta?.limits?.http_timeout ?? 10} s timeout · no redirects · response up to {meta?.limits?.http_max_kb ?? 64} KB · GIMT credentials are never sent. Variables work in the path and query only.
            </p>
          </Section>
          <Section title="Headers" aside={!ro && headers.length < 20 ? <Button size="xs" variant="ghost" icon={Plus} onClick={() => set({ headers: [...headers, { key: '', value: '' }] })}>Add</Button> : null}>
            {headers.length === 0 && <p className="text-xs text-slate-500">No headers.</p>}
            {headers.map((h, i) => (
              <div key={i} className="flex items-center gap-1.5">
                <Input inputSize="sm" value={h.key} disabled={ro} onChange={(e) => setHeader(i, { key: e.target.value })} placeholder="Authorization" aria-label="Header name" className="w-32" />
                <Input inputSize="sm" value={h.value} disabled={ro} onChange={(e) => setHeader(i, { value: e.target.value })} onFocus={(e) => h.masked && e.target.select()} placeholder="Bearer {{token}}" aria-label="Header value" />
                {!ro && <IconButton icon={X} label="Remove header" size="sm" tone="danger" onClick={() => set({ headers: headers.filter((_, j) => j !== i) })} />}
              </div>
            ))}
            {headers.some((h) => h.masked) && <p className="text-xs text-slate-500">Secret values are hidden — leave them as they are to keep the saved value.</p>}
          </Section>
          {String(d.method ?? 'POST').toUpperCase() !== 'GET' && (
            <Section title="Body (JSON)">
              <VarTextarea value={String(d.body ?? '')} onChange={(body) => set({ body })} meta={meta} rows={7} mono disabled={ro} />
            </Section>
          )}
          <Section title="Response & errors">
            <Field label="Save the JSON response as" htmlFor="wf-save" hint="Then use {{name.field}} in later steps and conditions (vars.name.field)." error={d.save_as && !/^[a-z][a-z0-9_]{0,39}$/.test(String(d.save_as)) ? 'Lowercase letters, digits and _ only.' : null}>
              <Input id="wf-save" value={d.save_as ?? ''} disabled={ro} onChange={(e) => set({ save_as: e.target.value })} placeholder="crm" />
            </Field>
            <Field label="If the request fails" htmlFor="wf-onerr">
              <Select id="wf-onerr" value={d.on_error ?? 'stop'} disabled={ro} onChange={(e) => set({ on_error: e.target.value })} options={{ stop: 'Stop the run (failed, can be retried)', continue: 'Log it and continue' }} />
            </Field>
            <Toggle checked={!!d.call_in_test} disabled={ro} onChange={(v) => set({ call_in_test: v })} label="Call in test runs" description="Off = test runs only show the request (dry run)." />
          </Section>
        </>
      );
    }
    case 'webhook': {
      const err = urlError(d.url);
      return (
        <Section title="Webhook">
          <Field label="Endpoint URL" required htmlFor="wf-hook" error={err}>
            <Input id="wf-hook" value={d.url ?? ''} disabled={ro} onChange={(e) => set({ url: e.target.value })} placeholder="https://hooks.example.com/gimt" />
          </Field>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Sends <code>POST</code> JSON: <code>event</code>, <code>workflow</code>, <code>run_id</code>, <code>contact</code>, <code>payload</code>, <code>variables</code>, <code>last_reply</code>. Headers <code>X-GIMT-Workflow</code> and <code>X-GIMT-Run</code> identify the call. HTTPS only, public hosts only (checked after DNS), 10 s timeout, no redirects; passwords / tokens in the event data are removed before sending.
          </p>
          <Field label="If the call fails" htmlFor="wf-hook-err">
            <Select id="wf-hook-err" value={d.on_error ?? 'continue'} disabled={ro} onChange={(e) => set({ on_error: e.target.value })} options={{ continue: 'Log it and continue', stop: 'Stop the run (failed, can be retried)' }} />
          </Field>
          <Toggle checked={!!d.call_in_test} disabled={ro} onChange={(v) => set({ call_in_test: v })} label="Call in test runs" />
        </Section>
      );
    }
    case 'end':
      return (
        <Section title="End">
          <p className="text-sm text-slate-600 dark:text-slate-300">The run is marked completed here. Branches without a next step also end automatically.</p>
        </Section>
      );
  }
  return null;
}

/* ------------------------------------------------------------------------------------------ inspector */

interface InspectorProps {
  def: Definition;
  node: WfNode;
  meta?: Meta;
  issues: Issue[];
  readOnly?: boolean;
  onPatch: (id: string, patch: NodeData) => void;
  onConnect: (from: string, port: Port, to: string | null) => void;
  onDelete: (id: string) => void;
  onDuplicate: (id: string) => void;
  onClose: () => void;
}

export function NodeInspector({ def, node, meta, issues, readOnly, onPatch, onConnect, onDelete, onDuplicate, onClose }: InspectorProps) {
  const spec = NODE_SPECS[node.type];
  const Icon = spec.icon;
  const mine = issues.filter((i) => i.node === node.id);
  const targets = useMemo(() => def.nodes.filter((n) => n.id !== node.id && n.type !== 'trigger'), [def.nodes, node.id]);
  const set = (patch: NodeData) => onPatch(node.id, patch);
  return (
    <div className="flex h-full flex-col">
      <div className="flex items-start gap-3 border-b border-slate-100 px-4 py-3.5 dark:border-slate-800">
        <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', spec.chip)}>
          <Icon className="h-[18px] w-[18px]" aria-hidden />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{spec.label}</p>
          <p className="truncate font-display text-sm font-semibold text-slate-900 dark:text-white">{nodeTitle(node)}</p>
        </div>
        <IconButton icon={X} label="Close step settings" size="sm" onClick={onClose} />
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto">
        {mine.length > 0 && (
          <div className="space-y-1.5 px-4 pt-4">
            {mine.map((i, k) => (
              <p key={k} className={clsx('flex items-start gap-1.5 rounded-lg px-2.5 py-1.5 text-xs', i.level === 'error' ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-200' : 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200')}>
                <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
                {i.message}
              </p>
            ))}
          </div>
        )}
        <Section title="Step">
          <p className="text-xs text-slate-500 dark:text-slate-400">{spec.description}</p>
          <Field label="Name on the canvas" htmlFor="wf-label">
            <Input id="wf-label" value={node.data.label ?? ''} disabled={readOnly} maxLength={80} onChange={(e) => set({ label: e.target.value })} placeholder={spec.label} />
          </Field>
        </Section>
        <NodeForm node={node} set={set} meta={meta} readOnly={readOnly} />
        {spec.ports.length > 0 && (
          <Section title={spec.ports.length > 1 ? 'Branches' : 'Next step'}>
            {spec.ports.map((p) => (
              <Field key={p} label={spec.ports.length > 1 ? <Badge color={p === 'false' ? 'red' : p === 'timeout' ? 'amber' : 'green'}>{PORT_LABEL[p]}</Badge> : undefined} htmlFor={`wf-port-${p}`}>
                <select id={`wf-port-${p}`} className="form-input form-input-sm pr-8" value={edgeTarget(def, node.id, p) ?? ''} disabled={readOnly} onChange={(e) => onConnect(node.id, p, e.target.value || null)} aria-label={`${PORT_LABEL[p]} goes to`}>
                  <option value="">— End here —</option>
                  {targets.map((t) => (
                    <option key={t.id} value={t.id}>
                      {nodeTitle(t)} ({NODE_SPECS[t.type].label})
                    </option>
                  ))}
                </select>
              </Field>
            ))}
            <p className="text-xs text-slate-500 dark:text-slate-400">Tip: drag from a port on the canvas to connect steps.</p>
          </Section>
        )}
      </div>
      {!readOnly && (
        <div className="flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-3 dark:border-slate-800">
          <Button size="sm" variant="secondary" icon={Copy} onClick={() => onDuplicate(node.id)} disabled={node.type === 'trigger'}>
            Duplicate
          </Button>
          <Button size="sm" variant="danger" icon={Trash2} onClick={() => onDelete(node.id)} disabled={node.type === 'trigger'} title={node.type === 'trigger' ? 'Every workflow needs its trigger' : 'Delete step (Del)'}>
            Delete
          </Button>
        </div>
      )}
    </div>
  );
}
