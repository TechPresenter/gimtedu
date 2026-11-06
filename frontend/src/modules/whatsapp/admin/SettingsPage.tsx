import { useEffect, useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowUpRight, Bell, BookOpen, Bot, Building2, Clock, Database, KeyRound, LayoutTemplate, Lock, Save, ShieldCheck, Webhook, type LucideIcon } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Field, Input, PageHeader, RadioGroup, Select, Skeleton, Textarea, Toggle, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, timeAgo } from '@/lib/format';
import { ModeBanner, useWaRefresh } from '../shared';
import { useWaSettings, type DayHours, type SettingsData } from './lib';
import { CopyField } from './ui';

type Section = 'profile' | 'hours' | 'notifications' | 'automation' | 'webhook' | 'retention' | 'security';
const SECTIONS: { key: Section; label: string; icon: LucideIcon; manage?: boolean }[] = [
  { key: 'profile', label: 'Business profile', icon: Building2 },
  { key: 'hours', label: 'Business hours', icon: Clock },
  { key: 'notifications', label: 'Notifications', icon: Bell },
  { key: 'automation', label: 'Automation', icon: Bot },
  { key: 'webhook', label: 'Webhook', icon: Webhook, manage: true },
  { key: 'retention', label: 'Data retention', icon: Database, manage: true },
  { key: 'security', label: 'Security', icon: Lock, manage: true },
];
/** Settings that live on their own pages (Module 14 items managed elsewhere). */
const RELATED: { label: string; to: string; icon: LucideIcon; perm: string }[] = [
  { label: 'Interakt API configuration', to: '/whatsapp/integrations', icon: KeyRound, perm: 'whatsapp_settings' },
  { label: 'Message templates', to: '/whatsapp/templates', icon: LayoutTemplate, perm: 'whatsapp_templates' },
  { label: 'Team permissions', to: '/whatsapp/team?tab=permissions', icon: ShieldCheck, perm: 'whatsapp_settings' },
  { label: 'Chatbot settings', to: '/whatsapp/chatbot?tab=settings', icon: Bot, perm: 'whatsapp_automation' },
  { label: 'API documentation', to: '/whatsapp/api?tab=docs', icon: BookOpen, perm: 'whatsapp_settings' },
];
const DAYS: [string, string][] = [
  ['mon', 'Monday'],
  ['tue', 'Tuesday'],
  ['wed', 'Wednesday'],
  ['thu', 'Thursday'],
  ['fri', 'Friday'],
  ['sat', 'Saturday'],
  ['sun', 'Sunday'],
];

/** Settings (Module 14): business profile, hours, notifications, automation limits, webhook, retention and security. */
export default function SettingsPage() {
  const { can } = useAuth();
  const related = RELATED.filter((r) => can(r.perm, 'view'));
  const [params, setParams] = useSearchParams();
  const section = (SECTIONS.some((s) => s.key === params.get('section')) ? params.get('section') : 'profile') as Section;
  const { data, isLoading, error, refetch } = useWaSettings();
  const setSection = (s: Section) => setParams(s === 'profile' ? {} : { section: s }, { replace: true });

  return (
    <>
      <PageHeader
        title="WhatsApp Settings"
        description="How GIMT presents itself on WhatsApp, when automated messages may go out, who is notified and who can see what."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Settings' }]}
        actions={
          <Button variant="secondary" icon={KeyRound} to="/whatsapp/integrations">
            Interakt connection
          </Button>
        }
      />
      <ModeBanner />
      <div className="grid grid-cols-1 gap-5 lg:grid-cols-[15rem_minmax(0,1fr)]">
        <div className="space-y-2 lg:hidden">
          <Select aria-label="Settings section" value={section} onChange={(e) => setSection(e.target.value as Section)} options={SECTIONS.map((s) => ({ value: s.key, label: s.label }))} />
          {related.length > 0 && (
            <div className="flex flex-wrap gap-1.5" aria-label="More settings">
              {related.map((r) => (
                <Link key={r.to} to={r.to} className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700">
                  <r.icon className="h-3.5 w-3.5" aria-hidden />
                  {r.label}
                </Link>
              ))}
            </div>
          )}
        </div>
        <nav aria-label="Settings sections" className="hidden lg:block">
          <Card className="sticky top-20 p-2">
            <ul className="space-y-0.5">
              {SECTIONS.map((s) => (
                <li key={s.key}>
                  <button
                    type="button"
                    onClick={() => setSection(s.key)}
                    aria-current={section === s.key ? 'page' : undefined}
                    className={clsx(
                      'flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                      section === s.key ? 'bg-brand-900 font-semibold text-white dark:bg-brand-700' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
                    )}
                  >
                    <s.icon className={clsx('h-4 w-4 shrink-0', section === s.key ? 'text-accent-300' : 'text-slate-400')} aria-hidden />
                    <span className="truncate">{s.label}</span>
                  </button>
                </li>
              ))}
            </ul>
            {related.length > 0 && (
              <>
                <p className="mt-3 border-t border-slate-100 px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400 dark:border-slate-800">More settings</p>
                <ul className="space-y-0.5">
                  {related.map((r) => (
                    <li key={r.to}>
                      <Link
                        to={r.to}
                        className="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm text-slate-600 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:text-slate-300 dark:hover:bg-slate-800"
                      >
                        <r.icon className="h-4 w-4 shrink-0 text-slate-400" aria-hidden />
                        <span className="min-w-0 flex-1 truncate">{r.label}</span>
                        <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-slate-300 dark:text-slate-600" aria-hidden />
                      </Link>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </Card>
        </nav>
        <div className="min-w-0">
          {error ? (
            <Alert variant="error" title="Could not load settings" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
              {(error as ApiError).message}
            </Alert>
          ) : isLoading || !data ? (
            <Skeleton className="h-96 w-full rounded-2xl" />
          ) : (
            <SectionForm key={section} section={section} data={data} />
          )}
        </div>
      </div>
    </>
  );
}

/** Form state per section, saved with PUT whatsapp/settings/{section}. */
function SectionForm({ section, data }: { section: Section; data: SettingsData }) {
  const toast = useToast();
  const refresh = useWaRefresh();
  const meta = SECTIONS.find((s) => s.key === section)!;
  const canSave = meta.manage ? data.can.manage : data.can.edit;
  const initial = (): Record<string, unknown> => {
    switch (section) {
      case 'profile':
        return {
          wa_business_name: data.profile.wa_business_name,
          wa_business_number: data.profile.wa_business_number ? `+${data.profile.wa_business_number}` : '',
          wa_default_language: data.profile.wa_default_language,
          wa_brochure_url: data.profile.wa_brochure_url ?? '',
        };
      case 'hours':
        return { wa_business_hours: data.hours.wa_business_hours };
      case 'webhook':
        return { wa_allow_simulate_live: data.webhook.wa_allow_simulate_live };
      default:
        return { ...(data[section] as unknown as Record<string, unknown>) };
    }
  };
  const [form, setForm] = useState<Record<string, unknown>>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => setForm(initial()), [data]); // eslint-disable-line react-hooks/exhaustive-deps

  const set = (k: string, v: unknown) => {
    setForm((f) => ({ ...f, [k]: v }));
    setErrors((e) => ({ ...e, [k]: '' }));
  };
  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      // only the editable wa_* keys go back to the server (read-only status values such as "last digest" stay here)
      const body = Object.fromEntries(Object.entries(form).filter(([k]) => k.startsWith('wa_')));
      const r = await api.put(`whatsapp/settings/${section}`, body);
      toast.success(r.message);
      await refresh();
    } catch (e) {
      const ae = e as ApiError;
      setErrors(ae.errors ?? {});
      toast.error(ae.message);
    } finally {
      setSaving(false);
    }
  };
  const str = (k: string) => String(form[k] ?? '');
  const bool = (k: string) => form[k] === true || form[k] === '1' || form[k] === 1;

  let body: ReactNode = null;
  let subtitle = '';
  switch (section) {
    case 'profile':
      subtitle = 'Name, number and brochure used across WhatsApp — they fill {{support_number}} and {{brochure_link}} in templates';
      body = (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div className="flex items-center gap-4 rounded-2xl border border-slate-200 p-4 md:col-span-2 dark:border-slate-700">
            <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-white p-2 ring-1 ring-slate-200 dark:ring-slate-700">
              <img src={data.profile.logo_url} alt={`${data.profile.institute_name} logo`} className="max-h-full max-w-full object-contain" loading="lazy" />
            </div>
            <div className="min-w-0 text-sm">
              <p className="font-semibold text-slate-800 dark:text-slate-100">{data.profile.institute_name}</p>
              <p className="text-slate-500 dark:text-slate-400">The WhatsApp profile photo and “About” are managed in Interakt / Meta Business Manager; the logo here comes from System › Settings.</p>
            </div>
          </div>
          <Field label="Business name" htmlFor="s-name" required error={errors.wa_business_name}>
            <Input id="s-name" value={str('wa_business_name')} onChange={(e) => set('wa_business_name', e.target.value)} maxLength={120} invalid={!!errors.wa_business_name} disabled={!canSave} />
          </Field>
          <Field label="WhatsApp business number" htmlFor="s-num" error={errors.wa_business_number} hint="With country code, e.g. +91 90600 31540">
            <Input id="s-num" type="tel" value={str('wa_business_number')} onChange={(e) => set('wa_business_number', e.target.value)} invalid={!!errors.wa_business_number} disabled={!canSave} />
          </Field>
          <Field label="Default language" error={errors.wa_default_language} hint="Used when a contact has no language and a template exists in both.">
            <fieldset disabled={!canSave} className="disabled:opacity-60">
              <legend className="sr-only">Default language</legend>
              <RadioGroup name="s-lang" value={str('wa_default_language') || 'en'} onChange={(v) => set('wa_default_language', v)} options={[{ value: 'en', label: 'English' }, { value: 'hi', label: 'Hindi' }]} />
            </fieldset>
          </Field>
          <Field
            label="Brochure link"
            htmlFor="s-brochure"
            className="md:col-span-2"
            error={errors.wa_brochure_url}
            hint="Optional. Fills {{brochure_link}} when a programme has no brochure of its own — a full https:// link or a path on this website such as /uploads/brochure.pdf. Empty = the programme page."
          >
            <Input
              id="s-brochure"
              type="text"
              inputMode="url"
              autoComplete="off"
              spellCheck={false}
              placeholder="https://… or /uploads/brochure.pdf"
              value={str('wa_brochure_url')}
              onChange={(e) => set('wa_brochure_url', e.target.value)}
              maxLength={500}
              invalid={!!errors.wa_brochure_url}
              disabled={!canSave}
            />
          </Field>
        </div>
      );
      break;
    case 'hours': {
      subtitle = `Timezone ${data.hours.timezone} · ${data.hours.in_business_hours ? 'open now' : 'closed now'}`;
      const hours = (form.wa_business_hours ?? {}) as Record<string, DayHours>;
      const setDay = (d: string, patch: Partial<DayHours>) => set('wa_business_hours', { ...hours, [d]: { ...(hours[d] ?? { open: '09:00', close: '18:00', closed: false }), ...patch } });
      body = (
        <div className="space-y-2">
          {errors.wa_business_hours && <Alert variant="error">{errors.wa_business_hours}</Alert>}
          {DAYS.map(([d, label]) => {
            const h = hours[d] ?? { open: '09:00', close: '18:00', closed: true };
            const err = errors[`wa_business_hours.${d}`];
            return (
              <div key={d} className="flex flex-col gap-2 rounded-xl border border-slate-200 px-3 py-2.5 sm:flex-row sm:items-center dark:border-slate-700">
                <div className="flex items-center justify-between gap-3 sm:w-48">
                  <span className="font-medium text-slate-700 dark:text-slate-200">{label}</span>
                  <Toggle checked={!h.closed} onChange={(v) => setDay(d, { closed: !v })} label={h.closed ? 'Closed' : 'Open'} disabled={!canSave} />
                </div>
                {!h.closed && (
                  <div className="flex flex-wrap items-center gap-2">
                    <Input type="time" inputSize="sm" aria-label={`${label} opens`} value={h.open} onChange={(e) => setDay(d, { open: e.target.value })} className="w-32" disabled={!canSave} invalid={!!err} />
                    <span className="text-xs text-slate-400">to</span>
                    <Input type="time" inputSize="sm" aria-label={`${label} closes`} value={h.close} onChange={(e) => setDay(d, { close: e.target.value })} className="w-32" disabled={!canSave} invalid={!!err} />
                  </div>
                )}
                {err && <p className="text-xs text-red-600 dark:text-red-400 sm:ml-auto">{err}</p>}
              </div>
            );
          })}
          <p className="text-xs text-slate-500 dark:text-slate-400">Events and workflows with “business hours only” wait for the next opening time; the chatbot tells contacts when the team is back.</p>
        </div>
      );
      break;
    }
    case 'notifications':
      subtitle = 'In-app notifications for the WhatsApp team';
      body = (
        <div className="space-y-4">
          <Toggle checked={bool('wa_notify_new_message')} onChange={(v) => set('wa_notify_new_message', v)} disabled={!canSave} label="New message in my chat" description="Notify the assigned counsellor when a contact writes (at most once every 10 minutes per chat; not while the AI bot is answering)." />
          <Toggle checked={bool('wa_notify_assignment')} onChange={(v) => set('wa_notify_assignment', v)} disabled={!canSave} label="Chat assigned to me" description="Notify a team member when a conversation or lead is assigned to them." />
          <Toggle checked={bool('wa_notify_handover')} onChange={(v) => set('wa_notify_handover', v)} disabled={!canSave} label="AI bot hands over" description="Notify the assignee (or the whole WhatsApp team) when the bot passes a chat to a human." />
          <div className="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
            <Toggle checked={bool('wa_daily_digest')} onChange={(v) => set('wa_daily_digest', v)} disabled={!canSave} label="Daily digest" description="Yesterday’s sent / delivered / read / failed counts, chats waiting and failed runs, sent to everyone with WhatsApp access." />
            {bool('wa_daily_digest') && (
              <Field label="Send at" htmlFor="s-digest" className="mt-3 max-w-[12rem]" error={errors.wa_digest_time}>
                <Input id="s-digest" type="time" value={str('wa_digest_time')} onChange={(e) => set('wa_digest_time', e.target.value)} disabled={!canSave} invalid={!!errors.wa_digest_time} />
              </Field>
            )}
            {data.notifications.last_digest_at && <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Last digest {timeAgo(data.notifications.last_digest_at)}.</p>}
          </div>
        </div>
      );
      break;
    case 'automation':
      subtitle = 'Limits for automated messages (automations, events, follow-ups, campaigns) — agent replies are never held';
      body = (
        <div className="space-y-5">
          <div className={clsx('rounded-2xl border p-4', bool('wa_automation_paused') ? 'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10' : 'border-slate-200 dark:border-slate-700')}>
            <Toggle checked={bool('wa_automation_paused')} onChange={(v) => set('wa_automation_paused', v)} disabled={!canSave} label="Pause all automated messages" description="Queued automated messages wait (they are not cancelled) until you switch this off." />
          </div>
          <div className="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
            <Toggle checked={bool('wa_quiet_enabled')} onChange={(v) => set('wa_quiet_enabled', v)} disabled={!canSave} label="Quiet hours" description="No automated messages between these times — they go out when quiet hours end." />
            {bool('wa_quiet_enabled') && (
              <div className="mt-3 flex flex-wrap items-end gap-3">
                <Field label="From" htmlFor="s-qs" error={errors.wa_quiet_start}>
                  <Input id="s-qs" type="time" value={str('wa_quiet_start')} onChange={(e) => set('wa_quiet_start', e.target.value)} className="w-36" disabled={!canSave} invalid={!!errors.wa_quiet_start} />
                </Field>
                <Field label="Until" htmlFor="s-qe" error={errors.wa_quiet_end}>
                  <Input id="s-qe" type="time" value={str('wa_quiet_end')} onChange={(e) => set('wa_quiet_end', e.target.value)} className="w-36" disabled={!canSave} invalid={!!errors.wa_quiet_end} />
                </Field>
              </div>
            )}
          </div>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Field label="Max automated messages per contact per day" htmlFor="s-max" error={errors.wa_max_per_contact_day} hint="0 = no limit. Protects students from message fatigue.">
              <Input id="s-max" type="number" min={0} max={50} value={str('wa_max_per_contact_day')} onChange={(e) => set('wa_max_per_contact_day', e.target.value)} disabled={!canSave} invalid={!!errors.wa_max_per_contact_day} />
            </Field>
            <Field label="Delivery attempts" htmlFor="s-att" error={errors.wa_max_attempts} hint="Retries with backoff before a message lands in the retry queue.">
              <Input id="s-att" type="number" min={1} max={10} value={str('wa_max_attempts')} onChange={(e) => set('wa_max_attempts', e.target.value)} disabled={!canSave} invalid={!!errors.wa_max_attempts} />
            </Field>
          </div>
          <Toggle checked={bool('wa_auto_sync_contacts')} onChange={(v) => set('wa_auto_sync_contacts', v)} disabled={!canSave} label="Sync contacts to Interakt" description="Push name, program, stage, tags and opt-in status to Interakt (live mode)." />
          <Field label="Reply when a contact sends STOP" htmlFor="s-out" error={errors.wa_optout_reply}>
            <Textarea id="s-out" rows={2} maxLength={500} value={str('wa_optout_reply')} onChange={(e) => set('wa_optout_reply', e.target.value)} disabled={!canSave} invalid={!!errors.wa_optout_reply} />
          </Field>
          <Field label="Reply when a contact sends START" htmlFor="s-in" error={errors.wa_optin_reply}>
            <Textarea id="s-in" rows={2} maxLength={500} value={str('wa_optin_reply')} onChange={(e) => set('wa_optin_reply', e.target.value)} disabled={!canSave} invalid={!!errors.wa_optin_reply} />
          </Field>
        </div>
      );
      break;
    case 'webhook':
      subtitle = 'Webhook endpoint and test tools';
      body = (
        <div className="space-y-4">
          <div>
            <p className="form-label">Webhook URL</p>
            <CopyField value={data.webhook.webhook_url} label="Webhook URL" />
          </div>
          <div className="flex flex-col gap-2 rounded-2xl border border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700">
            <div className="text-sm">
              <p className="font-medium text-slate-800 dark:text-slate-100">Webhook secret</p>
              <p className="text-slate-500 dark:text-slate-400">{data.webhook.secret_set ? <>Saved • ends with <code>{data.webhook.secret_last4}</code></> : 'Not set — webhooks are not signature-checked.'}</p>
            </div>
            <Button size="sm" variant="secondary" icon={KeyRound} to="/whatsapp/integrations">
              {data.webhook.secret_set ? 'Rotate in Integrations' : 'Generate in Integrations'}
            </Button>
          </div>
          <Toggle checked={bool('wa_allow_simulate_live')} onChange={(v) => set('wa_allow_simulate_live', v)} disabled={!canSave} label="Allow test tools in live mode" description="Lets managers simulate inbound messages and delivery statuses after going live. Keep off in production." />
        </div>
      );
      break;
    case 'retention':
      subtitle = 'How long integration logs are kept';
      body = (
        <div className="space-y-4">
          <Field label="Keep webhook logs, API logs and finished queue rows for" htmlFor="s-ret" error={errors.wa_retention_days} hint="7 – 3650 days. Conversations, contacts and campaign results are never purged.">
            <Input id="s-ret" type="number" min={7} max={3650} value={str('wa_retention_days')} onChange={(e) => set('wa_retention_days', e.target.value)} suffix="days" className="max-w-[14rem]" disabled={!canSave} invalid={!!errors.wa_retention_days} />
          </Field>
          <p className="text-xs text-slate-500 dark:text-slate-400">The worker purges old rows hourly. Last purge: {data.retention.last_purge_at ? formatDateTime(data.retention.last_purge_at) : 'never'}.</p>
        </div>
      );
      break;
    case 'security':
      subtitle = 'Exports and phone-number privacy';
      body = (
        <div className="space-y-5">
          <Field label="Who can export WhatsApp reports and contacts" error={errors.wa_export_scope}>
            <fieldset disabled={!canSave} className="disabled:opacity-60">
              <legend className="sr-only">Who can export WhatsApp reports and contacts</legend>
              <RadioGroup
                name="s-export"
                inline={false}
                value={str('wa_export_scope') || 'permission'}
                onChange={(v) => set('wa_export_scope', v)}
                options={[
                  { value: 'permission', label: 'Everyone whose role has “Export” on WhatsApp Reports or WhatsApp Contacts' },
                  { value: 'managers', label: 'Only WhatsApp settings managers' },
                ]}
              />
            </fieldset>
          </Field>
          <Toggle checked={bool('wa_mask_phones')} onChange={(v) => set('wa_mask_phones', v)} disabled={!canSave} label="Mask phone numbers" description="Show numbers as +91 98•••••210 in contacts, reports, logs and activity to users without WhatsApp settings “manage”." />
        </div>
      );
      break;
  }

  return (
    <Card>
      <CardHeader
        title={meta.label}
        subtitle={subtitle}
        icon={meta.icon}
        actions={meta.manage ? <Badge color="navy">Managers</Badge> : undefined}
      />
      <div className="card-body">
        {!canSave && <Alert variant="info" className="mb-4">You can view these settings. Changing them needs WhatsApp settings “{meta.manage ? 'manage' : 'edit'}”.</Alert>}
        {body}
      </div>
      {canSave && (
        <div className="flex justify-end border-t border-slate-100 px-4 py-3 dark:border-slate-800">
          <Button icon={Save} loading={saving} onClick={save}>
            Save {meta.label.toLowerCase()}
          </Button>
        </div>
      )}
    </Card>
  );
}
