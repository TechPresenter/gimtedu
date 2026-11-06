/**
 * WhatsApp CRM UI pieces shared by the Contacts, Contact detail, Admission Leads and Follow-up Manager pages:
 * badges, the add/edit contact modal, the send-template modal and the small bulk/quick-action dialogs.
 */
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { ChevronDown, Clock3, Info, Plus, Send, ShieldCheck, ShieldOff } from 'lucide-react';
import { Alert, Badge, Button, Checkbox, Combobox, Field, Input, Modal, PhoneInput, Select, Skeleton, Textarea, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, timeAgo } from '@/lib/format';
import type { BadgeColor } from '@/lib/status';
import { ENQUIRY_CONTACT_TYPES, isMasked, PAYMENT_COLORS, plus, stageInfo, TYPE_COLORS, TYPE_LABELS, useCrmRefresh, useFacets, type EnquiryResult, type Facets, type WaContact, type WaTag } from './crm';

/* ================================================================================================ badges */

export function StageBadge({ stage, facets, className }: { stage: string | null | undefined; facets?: Facets | null; className?: string }) {
  const s = stageInfo(stage, facets);
  return (
    <Badge color={s.color} dot className={className}>
      {s.label}
    </Badge>
  );
}

export function TypeBadge({ type }: { type: string }) {
  return <Badge color={TYPE_COLORS[type] ?? 'slate'}>{TYPE_LABELS[type] ?? type}</Badge>;
}

export function PaymentBadge({ status }: { status: string | null }) {
  if (!status) return <span className="text-slate-400">—</span>;
  return <Badge color={PAYMENT_COLORS[status] ?? 'slate'}>{status === 'partial' ? 'Partially paid' : status.charAt(0).toUpperCase() + status.slice(1)}</Badge>;
}

export function OptInBadge({ contact, compact }: { contact: Pick<WaContact, 'opt_in' | 'opted_out_at' | 'opt_in_source' | 'opt_in_at'>; compact?: boolean }) {
  if (contact.opt_in) {
    return (
      <span title={[contact.opt_in_source, contact.opt_in_at && `since ${formatDateTime(contact.opt_in_at)}`].filter(Boolean).join(' · ') || 'Opted in'} className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 dark:text-emerald-300">
        <ShieldCheck className="h-3.5 w-3.5" aria-hidden />
        {compact ? 'Yes' : 'Opted in'}
      </span>
    );
  }
  if (contact.opted_out_at) {
    return (
      <span title={`Opted out ${formatDateTime(contact.opted_out_at)}`} className="inline-flex items-center gap-1 text-xs font-medium text-red-600 dark:text-red-400">
        <ShieldOff className="h-3.5 w-3.5" aria-hidden />
        {compact ? 'Out' : 'Opted out'}
      </span>
    );
  }
  return <span className="text-xs text-slate-400">{compact ? 'No' : 'No consent'}</span>;
}

/** 24-hour customer-service window indicator. */
export function WindowBadge({ open, expires, lastInbound }: { open: boolean; expires?: string | null; lastInbound?: string | null }) {
  if (open) {
    return (
      <span className="inline-flex items-center gap-1 rounded-full bg-[#25D366]/10 px-2 py-0.5 text-[11px] font-semibold text-[#128C4B] dark:bg-[#25D366]/15 dark:text-[#5BE38E]" title={expires ? `Free-form replies allowed until ${formatDateTime(expires)}` : undefined}>
        <span className="h-1.5 w-1.5 rounded-full bg-[#25D366]" aria-hidden />
        Window open
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400" title={lastInbound ? `Last reply ${formatDateTime(lastInbound)} — templates only` : 'No reply yet — templates only'}>
      <Clock3 className="h-3 w-3" aria-hidden />
      Templates only
    </span>
  );
}

export function TagChips({ tags, max = 3, className }: { tags: WaTag[]; max?: number; className?: string }) {
  if (!tags.length) return null;
  const shown = tags.slice(0, max);
  return (
    <span className={clsx('inline-flex flex-wrap items-center gap-1', className)}>
      {shown.map((t) => (
        <Badge key={t.id} color={(t.color as BadgeColor) || 'slate'} className="!px-1.5 !py-0 !text-[10px]">
          {t.name}
        </Badge>
      ))}
      {tags.length > max && <span className="text-[10px] font-medium text-slate-500" title={tags.slice(max).map((t) => t.name).join(', ')}>+{tags.length - max}</span>}
    </span>
  );
}

export function LastMessage({ at }: { at: string | null }) {
  if (!at) return <span className="text-xs text-slate-400">Never</span>;
  return (
    <span className="whitespace-nowrap text-xs text-slate-600 dark:text-slate-300" title={formatDateTime(at)}>
      {timeAgo(at)}
    </span>
  );
}

/**
 * What counts as WhatsApp consent — shown wherever staff record an opt-in (contact form, opt-in dialog, import), so
 * the rule the server applies is visible: only an explicit, recorded agreement opts a contact in for marketing.
 */
export function ConsentHelp({ className, defaultOpen }: { className?: string; defaultOpen?: boolean }) {
  return (
    <details className={clsx('group rounded-xl border border-slate-200 bg-slate-50/70 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-300', className)} open={defaultOpen}>
      <summary className="flex cursor-pointer list-none items-center gap-1.5 px-3 py-2 font-semibold text-brand-800 marker:hidden dark:text-brand-200 [&::-webkit-details-marker]:hidden">
        <Info className="h-3.5 w-3.5 shrink-0" aria-hidden />
        What counts as WhatsApp consent?
        <ChevronDown className="ml-auto h-3.5 w-3.5 shrink-0 transition group-open:rotate-180" aria-hidden />
      </summary>
      <div className="grid gap-3 border-t border-slate-200 px-3 py-2.5 dark:border-slate-700 sm:grid-cols-2">
        <div>
          <p className="mb-1 font-semibold text-emerald-700 dark:text-emerald-300">Counts — record where it was given</p>
          <ul className="list-disc space-y-0.5 pl-4">
            <li>The WhatsApp consent box ticked on a GIMT website or enquiry form (recorded automatically)</li>
            <li>A signed admission / application form declaration that names WhatsApp</li>
            <li>A reply of START or YES to an opt-in request on WhatsApp</li>
            <li>Verbal consent on a call — note the date and who took it</li>
            <li>A signed sign-up sheet at a fair, seminar or campus visit</li>
          </ul>
        </div>
        <div>
          <p className="mb-1 font-semibold text-red-600 dark:text-red-400">Does not count</p>
          <ul className="list-disc space-y-0.5 pl-4">
            <li>Having the number from an enquiry, application or student record</li>
            <li>The person writing to GIMT first — that only opens the 24-hour reply window</li>
            <li>Bought, shared or forwarded lists</li>
            <li>One family member agreeing for another person’s number</li>
          </ul>
        </div>
        <p className="text-slate-500 dark:text-slate-400 sm:col-span-2">
          Marketing templates and campaigns go only to opted-in contacts. Utility messages (application, payment, exam updates) are sent unless the contact opted out. A STOP reply opts the contact out and ends follow-ups.
        </p>
      </div>
    </details>
  );
}

/** Field errors from an ApiError (422) or empty. */
export function fieldErrors(e: unknown): Record<string, string> {
  return e instanceof ApiError ? e.errors ?? {} : {};
}

/* ================================================================================================ contact form */

interface ContactFormValues {
  name: string;
  phone: string;
  email: string;
  alt_phone: string;
  contact_type: string;
  stage: string;
  program_id: string;
  batch: string;
  city: string;
  state: string;
  counsellor_id: string;
  language: string;
  status: string;
  tags: string[];
  opt_in: boolean;
  opt_in_source: string;
  notes: string;
  application_no: string;
}

const emptyForm: ContactFormValues = {
  name: '', phone: '', email: '', alt_phone: '', contact_type: 'lead', stage: 'new_enquiry', program_id: '', batch: '', city: '', state: '',
  counsellor_id: '', language: 'en', status: 'active', tags: [], opt_in: false, opt_in_source: '', notes: '', application_no: '',
};

/**
 * Add / edit contact modal. `offerEnquiry` (Admission Leads › Add lead) adds "Also create an admission enquiry" — on by
 * default when the user may create enquiries — so the new lead reaches Admissions › Enquiries in the same request.
 */
export function ContactFormModal({ open, onClose, contact, onSaved, offerEnquiry = false }: { open: boolean; onClose: () => void; contact?: WaContact | null; onSaved?: (id: number) => void; offerEnquiry?: boolean }) {
  const { data: facets } = useFacets();
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useCrmRefresh();
  const [v, setV] = useState<ContactFormValues>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [newTag, setNewTag] = useState('');
  const wasOptedIn = !!contact?.opt_in;
  const enquiryAllowed = offerEnquiry && !contact && can('whatsapp_contacts', 'edit') && can('enquiries', 'create');
  const [withEnquiry, setWithEnquiry] = useState(true);
  const enquiryOn = enquiryAllowed && withEnquiry && ENQUIRY_CONTACT_TYPES.includes(v.contact_type);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setNewTag('');
    setWithEnquiry(true);
    setV(
      contact
        ? {
            name: contact.name ?? '', phone: plus(contact.phone), email: contact.email ?? '', alt_phone: plus(contact.alt_phone), contact_type: contact.contact_type,
            stage: contact.stage, program_id: contact.program_id ? String(contact.program_id) : '', batch: contact.batch ?? '', city: contact.city ?? '',
            state: contact.state ?? '', counsellor_id: contact.counsellor_id ? String(contact.counsellor_id) : '', language: contact.language || 'en',
            status: contact.status, tags: contact.tags.map((t) => t.name), opt_in: !!contact.opt_in, opt_in_source: '', notes: contact.notes ?? '',
            application_no: contact.application_no ?? '',
          }
        : emptyForm,
    );
  }, [open, contact]);

  const set = <K extends keyof ContactFormValues>(k: K, val: ContactFormValues[K]) => setV((p) => ({ ...p, [k]: val }));
  const tagOptions = useMemo(() => {
    const names = new Set([...(facets?.tags ?? []).map((t) => t.name), ...v.tags]);
    return [...names].sort().map((n) => ({ value: n, label: n }));
  }, [facets, v.tags]);

  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const body = { ...v, program_id: v.program_id || null, counsellor_id: v.counsellor_id || null, opt_in: v.opt_in ? 1 : 0, ...(enquiryOn ? { create_enquiry: 1 } : {}) };
      const res = contact ? await api.put<{ id: number }>(`whatsapp/contacts/${contact.id}`, body) : await api.post<{ id: number; enquiry?: EnquiryResult }>('whatsapp/contacts', body);
      const enq = (res.data as { enquiry?: EnquiryResult }).enquiry;
      if (enq && !enq.ok) toast.warning(res.message);
      else toast.success(res.message || 'Saved.');
      refresh();
      onSaved?.(res.data.id);
      onClose();
    } catch (e) {
      setErrors(fieldErrors(e));
      if (!(e instanceof ApiError) || !Object.keys(e.errors).length) toast.error((e as Error).message);
    } finally {
      setSaving(false);
    }
  };

  const options = (list?: { value: string | number; label: string }[]) => (list ?? []).map((o) => ({ value: String(o.value), label: o.label }));

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="xl"
      title={contact ? 'Edit contact' : 'Add WhatsApp contact'}
      description="Numbers are saved in international format (+91…). One contact per WhatsApp number."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button onClick={save} loading={saving}>
            {contact ? 'Save changes' : 'Add contact'}
          </Button>
        </>
      }
    >
      <form
        className="grid grid-cols-1 gap-4 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault();
          save();
        }}
      >
        <Field label="Full name" required error={errors.name} htmlFor="wa-c-name">
          <Input id="wa-c-name" value={v.name} onChange={(e) => set('name', e.target.value)} invalid={!!errors.name} maxLength={150} autoFocus />
        </Field>
        <Field label="WhatsApp number" required error={errors.phone} htmlFor="wa-c-phone" hint={isMasked(v.phone) ? 'Hidden by the phone-masking setting — stays unchanged.' : undefined}>
          {isMasked(v.phone) ? (
            <Input id="wa-c-phone" value={v.phone} readOnly disabled />
          ) : (
            <PhoneInput id="wa-c-phone" value={v.phone} onChange={(val) => set('phone', val)} invalid={!!errors.phone} />
          )}
        </Field>
        <Field label="Email" error={errors.email} htmlFor="wa-c-email">
          <Input id="wa-c-email" type="email" value={v.email} onChange={(e) => set('email', e.target.value)} invalid={!!errors.email} />
        </Field>
        <Field label="Alternate number" error={errors.alt_phone} htmlFor="wa-c-alt" hint={isMasked(v.alt_phone) ? 'Hidden by the phone-masking setting — stays unchanged.' : 'Parent or second number (not messaged).'}>
          {isMasked(v.alt_phone) ? (
            <Input id="wa-c-alt" value={v.alt_phone} readOnly disabled />
          ) : (
            <PhoneInput id="wa-c-alt" value={v.alt_phone} onChange={(val) => set('alt_phone', val)} invalid={!!errors.alt_phone} showError={false} />
          )}
        </Field>
        <Field label="Contact type" required error={errors.contact_type} htmlFor="wa-c-type">
          <Select id="wa-c-type" value={v.contact_type} onChange={(e) => set('contact_type', e.target.value)} options={TYPE_LABELS} />
        </Field>
        <Field label="Lead stage" error={errors.stage} htmlFor="wa-c-stage">
          <Select id="wa-c-stage" value={v.stage} onChange={(e) => set('stage', e.target.value)} options={options(facets?.stages)} />
        </Field>
        <Field label="Programme" error={errors.program_id} htmlFor="wa-c-program">
          <Select id="wa-c-program" value={v.program_id} onChange={(e) => set('program_id', e.target.value)} options={options(facets?.programs)} placeholder="— Not set —" />
        </Field>
        <Field label="Batch / intake" htmlFor="wa-c-batch" hint="e.g. 2026-27 or BBA 2026-2029">
          <Input id="wa-c-batch" value={v.batch} onChange={(e) => set('batch', e.target.value)} maxLength={80} />
        </Field>
        <Field label="City" htmlFor="wa-c-city">
          <Input id="wa-c-city" value={v.city} onChange={(e) => set('city', e.target.value)} maxLength={80} />
        </Field>
        <Field label="State" htmlFor="wa-c-state">
          <Input id="wa-c-state" value={v.state} onChange={(e) => set('state', e.target.value)} maxLength={80} />
        </Field>
        <Field label="Counsellor" error={errors.counsellor_id} htmlFor="wa-c-counsellor">
          <Select id="wa-c-counsellor" value={v.counsellor_id} onChange={(e) => set('counsellor_id', e.target.value)} options={options(facets?.counsellors)} placeholder="— Unassigned —" />
        </Field>
        <div className="grid grid-cols-2 gap-4">
          <Field label="Language" htmlFor="wa-c-lang">
            <Select id="wa-c-lang" value={v.language} onChange={(e) => set('language', e.target.value)} options={{ en: 'English', hi: 'Hindi' }} />
          </Field>
          <Field label="Status" error={errors.status} htmlFor="wa-c-status">
            <Select id="wa-c-status" value={v.status} onChange={(e) => set('status', e.target.value)} options={{ active: 'Active', blocked: 'Blocked', archived: 'Archived' }} />
          </Field>
        </div>
        <Field label="Application no" htmlFor="wa-c-app">
          <Input id="wa-c-app" value={v.application_no} onChange={(e) => set('application_no', e.target.value)} maxLength={40} />
        </Field>
        <Field label="Tags" error={errors.tags} className="sm:col-span-2">
          <div className="flex flex-col gap-2 sm:flex-row">
            <div className="min-w-0 flex-1">
              <Combobox multiple options={tagOptions} value={v.tags} onChange={(vals) => set('tags', vals.map(String))} placeholder="Choose tags…" />
            </div>
            <div className="flex gap-2 sm:w-64">
              <Input
                value={newTag}
                onChange={(e) => setNewTag(e.target.value)}
                placeholder="New tag"
                maxLength={60}
                aria-label="New tag name"
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    if (newTag.trim()) set('tags', [...new Set([...v.tags, newTag.trim()])]);
                    setNewTag('');
                  }
                }}
              />
              <Button
                type="button"
                variant="secondary"
                icon={Plus}
                onClick={() => {
                  if (newTag.trim()) set('tags', [...new Set([...v.tags, newTag.trim()])]);
                  setNewTag('');
                }}
                aria-label="Add tag"
              />
            </div>
          </div>
        </Field>
        <div className="rounded-xl border border-slate-200 p-3 dark:border-slate-700 sm:col-span-2">
          <Toggle checked={v.opt_in} onChange={(val) => set('opt_in', val)} label="WhatsApp opt-in (consent)" description="Campaigns (any template) and marketing templates are only sent to contacts who agreed to receive WhatsApp messages." />
          {wasOptedIn && contact?.opt_in_source && (
            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
              Recorded: {contact.opt_in_source}
              {contact.opt_in_at ? ` · ${formatDateTime(contact.opt_in_at)}` : ''}
            </p>
          )}
          {v.opt_in && !wasOptedIn && (
            <Field label="Consent source" required error={errors.opt_in_source} htmlFor="wa-c-optsrc" className="mt-3" hint='Where the consent was given, e.g. "Admission form 2026-27" or "Verbal consent on call, 12 Nov".'>
              <Input id="wa-c-optsrc" value={v.opt_in_source} onChange={(e) => set('opt_in_source', e.target.value)} invalid={!!errors.opt_in_source} maxLength={80} />
            </Field>
          )}
          <ConsentHelp className="mt-3" />
        </div>
        <Field label="Notes" htmlFor="wa-c-notes" className="sm:col-span-2">
          <Textarea id="wa-c-notes" value={v.notes} onChange={(e) => set('notes', e.target.value)} rows={2} />
        </Field>
        {enquiryAllowed && (
          <div className="rounded-xl border border-brand-100 bg-brand-50/60 p-3 dark:border-brand-500/20 dark:bg-brand-500/10 sm:col-span-2">
            <Checkbox
              id="wa-c-enquiry"
              checked={enquiryOn}
              disabled={!ENQUIRY_CONTACT_TYPES.includes(v.contact_type)}
              onChange={(e) => setWithEnquiry(e.target.checked)}
              label="Also create an admission enquiry"
              description={
                ENQUIRY_CONTACT_TYPES.includes(v.contact_type)
                  ? 'Adds the lead to Admissions › Enquiries (source WhatsApp) with the name, programme, city and counsellor above, and notifies the admissions team. If this number already has an open enquiry, the lead is linked to it instead. No WhatsApp message is sent.'
                  : `${TYPE_LABELS[v.contact_type] ?? 'This'} contacts are already in the ERP — no admission enquiry is needed.`
              }
            />
          </div>
        )}
      </form>
    </Modal>
  );
}

/* ================================================================================================ send template */

interface TplVar {
  pos: number;
  key: string;
  sample?: string;
}
interface SendableTemplate {
  id: number;
  name: string;
  display_name: string | null;
  category: string;
  language: string;
  status: string;
  body: string;
  header_type: string;
  header_text: string | null;
  footer: string | null;
  buttons: { type: string; text: string }[];
  variables: { header?: TplVar[]; body?: TplVar[] } | Record<string, never>;
  preview: string;
  missing: string[];
}

export function SendTemplateModal({ open, onClose, contactId, contactName }: { open: boolean; onClose: () => void; contactId: number; contactName?: string | null }) {
  const toast = useToast();
  const refresh = useCrmRefresh();
  const { data, isLoading, error } = useQuery({
    queryKey: ['wa', 'crm', 'contact-templates', contactId],
    queryFn: ({ signal }) => api.get<{ templates: SendableTemplate[]; values: Record<string, string>; opt_in: number; window_open: boolean }>(`whatsapp/contacts/${contactId}/templates`, undefined, { signal }),
    enabled: open,
  });
  const [tplId, setTplId] = useState<string>('');
  const [vars, setVars] = useState<Record<string, string>>({});
  const [sending, setSending] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  useEffect(() => {
    if (!open) {
      setTplId('');
      setVars({});
      setErr(null);
    }
  }, [open]);
  const tpl = data?.templates.find((t) => String(t.id) === tplId) ?? null;
  const tplVars = useMemo(() => {
    const vv = (tpl?.variables ?? {}) as { header?: TplVar[]; body?: TplVar[] };
    const list = [...(vv.header ?? []), ...(vv.body ?? [])];
    const seen = new Set<string>();
    return list.filter((x) => x && x.key && !seen.has(x.key) && seen.add(x.key));
  }, [tpl]);
  const value = (k: string) => vars[k] ?? data?.values?.[k] ?? '';
  const preview = useMemo(() => {
    if (!tpl) return '';
    const vv = (tpl.variables ?? {}) as { body?: TplVar[] };
    let text = tpl.body;
    (vv.body ?? []).forEach((x) => {
      text = text.split(`{{${x.pos}}}`).join(value(x.key) || `{{${x.pos}}}`);
    });
    return vv.body?.length ? text : tpl.preview || tpl.body;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tpl, vars, data]);
  const blocked = tpl ? tpl.status !== 'approved' ? 'This template is not approved by WhatsApp yet.' : tpl.category === 'MARKETING' && !data?.opt_in ? 'Marketing templates need the contact’s WhatsApp opt-in.' : null : null;
  const missing = tplVars.filter((x) => !value(x.key).trim()).map((x) => x.key);

  const send = async () => {
    if (!tpl) return;
    setSending(true);
    setErr(null);
    try {
      const res = await api.post(`whatsapp/contacts/${contactId}/send-template`, { template_id: tpl.id, vars: Object.fromEntries(tplVars.map((x) => [x.key, value(x.key)])) });
      toast.success(res.message || 'Template queued.');
      refresh();
      onClose();
    } catch (e) {
      setErr((e as Error).message);
    } finally {
      setSending(false);
    }
  };

  const tplOptions = (data?.templates ?? []).map((t) => ({ value: String(t.id), label: `${t.display_name || t.name}${t.status !== 'approved' ? ` (${t.status})` : ''}`, sub: `${t.name} · ${t.category.toLowerCase()}` }));

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={sending}
      size="lg"
      title="Send WhatsApp template"
      description={contactName ? `To ${contactName}. Templates can be sent any time; free-form replies only inside the 24-hour window.` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={sending}>
            Cancel
          </Button>
          <Button icon={Send} onClick={send} loading={sending} disabled={!tpl || !!blocked || missing.length > 0}>
            Send template
          </Button>
        </>
      }
    >
      {error ? (
        <Alert variant="error" title="Templates could not be loaded">
          {(error as Error).message}
        </Alert>
      ) : isLoading ? (
        <div className="space-y-3">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-32 w-full" />
        </div>
      ) : !data?.templates.length ? (
        <Alert variant="info" title="No templates yet">
          Create and submit a template in the Template Designer first.
        </Alert>
      ) : (
        <div className="space-y-4">
          <Field label="Template" required htmlFor="wa-send-tpl">
            <Combobox id="wa-send-tpl" options={tplOptions} value={tplId || null} onChange={(val) => { setTplId(val ? String(val) : ''); setVars({}); }} placeholder="Search templates…" />
          </Field>
          {tpl && (
            <>
              {blocked && (
                <Alert variant="warning" title="Cannot send this template">
                  {blocked}
                </Alert>
              )}
              {tplVars.length > 0 && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {tplVars.map((x) => (
                    <Field key={x.key} label={x.key.replace(/_/g, ' ')} htmlFor={`wa-var-${x.key}`} error={missing.includes(x.key) ? 'Required' : null}>
                      <Input id={`wa-var-${x.key}`} inputSize="sm" value={value(x.key)} placeholder={x.sample} onChange={(e) => setVars((p) => ({ ...p, [x.key]: e.target.value }))} />
                    </Field>
                  ))}
                </div>
              )}
              <div className="rounded-2xl bg-[#E9DFD5] p-4 dark:bg-[#0B141A]">
                <div className="ml-auto max-w-[85%] rounded-xl rounded-tr-sm bg-[#D9FDD3] p-3 text-sm text-slate-800 shadow-sm dark:bg-[#005C4B] dark:text-slate-100">
                  {tpl.header_type === 'text' && tpl.header_text && <p className="mb-1 font-semibold">{tpl.header_text}</p>}
                  <p className="whitespace-pre-wrap break-words">{preview}</p>
                  {tpl.footer && <p className="mt-1 text-xs text-slate-500 dark:text-slate-300/70">{tpl.footer}</p>}
                  {tpl.buttons?.length > 0 && (
                    <div className="mt-2 grid gap-1 border-t border-black/5 pt-2 dark:border-white/10">
                      {tpl.buttons.map((b, i) => (
                        <span key={i} className="text-center text-xs font-semibold text-[#027EB5] dark:text-[#53BDEB]">
                          {b.text}
                        </span>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            </>
          )}
          {err && <Alert variant="error">{err}</Alert>}
        </div>
      )}
    </Modal>
  );
}

/* ================================================================================================ small dialogs */

export function DialogShell({ open, onClose, title, description, busy, onSubmit, submitLabel, danger, children, disabled }: {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: ReactNode;
  busy: boolean;
  onSubmit: () => void;
  submitLabel: string;
  danger?: boolean;
  disabled?: boolean;
  children: ReactNode;
}) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={busy}
      size="md"
      title={title}
      description={description}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant={danger ? 'danger' : 'primary'} onClick={onSubmit} loading={busy} disabled={disabled}>
            {submitLabel}
          </Button>
        </>
      }
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          onSubmit();
        }}
        className="space-y-4"
      >
        {children}
      </form>
    </Modal>
  );
}

/** Choose a counsellor (or none). */
export function AssignDialog({ open, onClose, onSubmit, title = 'Assign counsellor', count }: { open: boolean; onClose: () => void; onSubmit: (id: number | null) => Promise<void>; title?: string; count?: number }) {
  const { data: facets } = useFacets();
  const [value, setValue] = useState<string>('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (open) {
      setValue('');
      setError(null);
    }
  }, [open]);
  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      await onSubmit(value ? Number(value) : null);
      onClose();
    } catch (e) {
      setError(fieldErrors(e).counsellor_id ?? (e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <DialogShell open={open} onClose={onClose} title={title} description={count ? `${count} contact(s) selected.` : undefined} busy={busy} onSubmit={submit} submitLabel={value ? 'Assign' : 'Remove counsellor'}>
      <Field label="Counsellor" error={error} htmlFor="wa-assign">
        <Combobox
          id="wa-assign"
          options={(facets?.counsellors ?? []).map((c) => ({ value: String(c.value), label: c.label, sub: `${c.sub ? `${c.sub} · ` : ''}${c.contacts} contacts` }))}
          value={value || null}
          onChange={(v) => setValue(v ? String(v) : '')}
          placeholder="Search counsellors…"
        />
      </Field>
    </DialogShell>
  );
}

/** Choose tags (existing or new). */
export function TagsDialog({ open, onClose, onSubmit, title, submitLabel = 'Apply', count, allowNew = true }: { open: boolean; onClose: () => void; onSubmit: (tags: string[]) => Promise<void>; title: string; submitLabel?: string; count?: number; allowNew?: boolean }) {
  const { data: facets } = useFacets();
  const [tags, setTags] = useState<string[]>([]);
  const [newTag, setNewTag] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (open) {
      setTags([]);
      setNewTag('');
      setError(null);
    }
  }, [open]);
  const opts = [...new Set([...(facets?.tags ?? []).map((t) => t.name), ...tags])].sort().map((n) => ({ value: n, label: n }));
  const submit = async () => {
    if (!tags.length) {
      setError('Choose at least one tag.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await onSubmit(tags);
      onClose();
    } catch (e) {
      setError(fieldErrors(e).tags ?? (e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <DialogShell open={open} onClose={onClose} title={title} description={count ? `${count} contact(s) selected.` : undefined} busy={busy} onSubmit={submit} submitLabel={submitLabel}>
      <Field label="Tags" error={error}>
        <Combobox multiple options={opts} value={tags} onChange={(v) => setTags(v.map(String))} placeholder="Choose tags…" />
      </Field>
      {allowNew && (
        <Field label="New tag" htmlFor="wa-newtag" hint="Press Enter to add a tag that does not exist yet.">
          <Input
            id="wa-newtag"
            value={newTag}
            maxLength={60}
            onChange={(e) => setNewTag(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                if (newTag.trim()) setTags((t) => [...new Set([...t, newTag.trim()])]);
                setNewTag('');
              }
            }}
          />
        </Field>
      )}
    </DialogShell>
  );
}

/** Choose a stage (+ optional note). */
export function StageDialog({ open, onClose, onSubmit, current, count }: { open: boolean; onClose: () => void; onSubmit: (stage: string, note: string) => Promise<void>; current?: string; count?: number }) {
  const { data: facets } = useFacets();
  const [stage, setStage] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (open) {
      setStage(current ?? '');
      setNote('');
      setError(null);
    }
  }, [open, current]);
  const submit = async () => {
    if (!stage) {
      setError('Choose a stage.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await onSubmit(stage, note);
      onClose();
    } catch (e) {
      setError(fieldErrors(e).stage ?? (e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <DialogShell open={open} onClose={onClose} title="Change lead stage" description={count ? `${count} contact(s) selected. Follow-ups of the new stage are scheduled automatically.` : 'Follow-ups of the new stage are scheduled automatically.'} busy={busy} onSubmit={submit} submitLabel="Move">
      <Field label="Stage" required error={error} htmlFor="wa-stage-sel">
        <Select id="wa-stage-sel" value={stage} onChange={(e) => setStage(e.target.value)} options={(facets?.stages ?? []).map((s) => ({ value: String(s.value), label: s.label }))} placeholder="Choose…" />
      </Field>
      {!count && (
        <Field label="Note" htmlFor="wa-stage-note" hint="Optional — shown in the stage history.">
          <Input id="wa-stage-note" value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
        </Field>
      )}
    </DialogShell>
  );
}

/** Opt-in (needs the consent source) or opt-out. */
export function OptDialog({ open, onClose, optIn, onSubmit, count }: { open: boolean; onClose: () => void; optIn: boolean; onSubmit: (source: string) => Promise<void>; count?: number }) {
  const [source, setSource] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (open) {
      setSource('');
      setError(null);
    }
  }, [open]);
  const submit = async () => {
    if (optIn && !source.trim()) {
      setError('Record where the consent came from.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await onSubmit(source.trim());
      onClose();
    } catch (e) {
      setError(fieldErrors(e).source ?? (e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <DialogShell
      open={open}
      onClose={onClose}
      title={optIn ? 'Record WhatsApp opt-in' : 'Opt out of WhatsApp'}
      description={count ? `${count} contact(s) selected.` : undefined}
      busy={busy}
      onSubmit={submit}
      submitLabel={optIn ? 'Opt in' : 'Opt out'}
      danger={!optIn}
    >
      {optIn ? (
        <>
          <Alert variant="info">Only record an opt-in when the person agreed to receive WhatsApp messages from GIMT. Meta can block numbers that message people without consent.</Alert>
          <ConsentHelp />
        </>
      ) : (
        <Alert variant="warning">Opted-out contacts receive no marketing templates and their pending follow-ups stop.</Alert>
      )}
      <Field label={optIn ? 'Consent source' : 'Reason (optional)'} required={optIn} error={error} htmlFor="wa-opt-src">
        <Input id="wa-opt-src" value={source} onChange={(e) => setSource(e.target.value)} maxLength={80} placeholder={optIn ? 'e.g. Admission form 2026-27, verbal consent on call' : 'e.g. Asked not to be messaged'} />
      </Field>
    </DialogShell>
  );
}

/** Add an internal note to a contact. */
export function NoteDialog({ open, onClose, contactId }: { open: boolean; onClose: () => void; contactId: number }) {
  const toast = useToast();
  const refresh = useCrmRefresh();
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (open) {
      setBody('');
      setError(null);
    }
  }, [open]);
  const submit = async () => {
    if (!body.trim()) {
      setError('Write a note.');
      return;
    }
    setBusy(true);
    try {
      const r = await api.post(`whatsapp/contacts/${contactId}/notes`, { body });
      toast.success(r.message || 'Note added.');
      refresh();
      onClose();
    } catch (e) {
      setError(fieldErrors(e).body ?? (e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <DialogShell open={open} onClose={onClose} title="Add note" description="Internal — never sent to the contact." busy={busy} onSubmit={submit} submitLabel="Save note">
      <Field label="Note" required error={error} htmlFor="wa-note-body">
        <Textarea id="wa-note-body" value={body} onChange={(e) => setBody(e.target.value)} rows={4} maxLength={2000} autoFocus />
      </Field>
    </DialogShell>
  );
}

/** Empty / info line used inside cards. */
export function Muted({ children, className }: { children: ReactNode; className?: string }) {
  return <p className={clsx('text-sm text-slate-500 dark:text-slate-400', className)}>{children}</p>;
}
