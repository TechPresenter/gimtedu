import { useState, type ReactNode } from 'react';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import {
  Activity, ArrowRight, Bug, ChartLine, CheckCircle2, CircleAlert, Cookie, ExternalLink, Eye, FileCode2, KeyRound, Mail, MapPin, MessageCircle, Plug, RefreshCcw,
  RotateCcw, Save, Search, SearchCheck, ServerCog, ShieldCheck, Terminal, type LucideIcon,
} from 'lucide-react';
import { Alert, Badge, Button, Card, Field, Reveal, Skeleton, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { formatNumber, formatPhone, timeAgo, whatsappHref } from '@/lib/format';
import type { Option } from '@/lib/types';
import { SettingInput, colSpan, type SettingField } from './SettingInput';
import { ErrorLogDrawer } from './ErrorLogDrawer';

interface IntegrationField extends SettingField {
  env?: string | null;
}
interface IntegrationSection {
  key: string;
  title: string;
  icon: string;
  description: string;
  fields: IntegrationField[];
}
interface Status {
  captcha: { provider: string; ready: boolean; secret_set: boolean };
  analytics: { ga: boolean; gtm: boolean; pixel: boolean; gsc: boolean; bing: boolean; mode: string };
  consent: { mode: string; banner: boolean; version: number; policy: string };
  maps: { embed: boolean; key: boolean };
  monitoring: { client_24h: number; client_7d: number; client_total: number; client_last: string | null; server_7d: number; enabled: boolean; sentry: boolean };
  email: { driver: string; effective: string; host: string; port: string; encryption: string; from: string; password_set: boolean; dev: boolean };
  whatsapp: { number: string; gateway: boolean };
  search: { blocked: boolean; sitemap_url: string; robots_url: string; robots_custom: boolean; last_audit: { id: number; pages_crawled: number; errors_count: number; warnings_count: number; finished_at: string } | null };
}
export interface IntegrationsPayload {
  sections: IntegrationSection[];
  values: Record<string, string>;
  secrets: Record<string, boolean>;
  env: Record<string, { name: string; set: boolean }>;
  status: Status;
  env_vars: { name: string; key: string | null; label: string; secret: boolean; set: boolean }[];
  can_edit: boolean;
  can_view_errors: boolean;
  site_url: string;
  consent_preview_url: string;
}

const ICONS: Record<string, LucideIcon> = {
  'shield-check': ShieldCheck, 'chart-line': ChartLine, cookie: Cookie, 'map-pin': MapPin, activity: Activity, 'message-circle': MessageCircle, search: Search,
};
const TONE: Record<string, string> = {
  captcha: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300', analytics: 'bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-300',
  consent: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300', maps: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
  monitoring: 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300', whatsapp: 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300',
  search: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
};
const CAPTCHA_LINKS: Record<string, { label: string; url: string }> = {
  recaptcha_v3: { label: 'Google reCAPTCHA admin console', url: 'https://www.google.com/recaptcha/admin' },
  turnstile: { label: 'Cloudflare Turnstile dashboard', url: 'https://dash.cloudflare.com/?to=/:account/turnstile' },
  hcaptcha: { label: 'hCaptcha dashboard', url: 'https://dashboard.hcaptcha.com/sites' },
};
const SMTP_PRESETS = [
  { name: 'Google Workspace / Gmail', host: 'smtp.gmail.com', port: '587', enc: 'STARTTLS', user: 'Full address · App password (2-step verification)' },
  { name: 'Microsoft 365 / Outlook', host: 'smtp.office365.com', port: '587', enc: 'STARTTLS', user: 'Full address · SMTP AUTH enabled for the mailbox' },
  { name: 'Zoho Mail (India)', host: 'smtp.zoho.in', port: '587', enc: 'STARTTLS', user: 'Full address · App-specific password' },
  { name: 'Amazon SES (Mumbai)', host: 'email-smtp.ap-south-1.amazonaws.com', port: '587', enc: 'STARTTLS', user: 'SES SMTP credentials (not IAM keys)' },
  { name: 'SendGrid', host: 'smtp.sendgrid.net', port: '587', enc: 'STARTTLS', user: 'Username “apikey” · password = API key' },
  { name: 'Brevo (Sendinblue)', host: 'smtp-relay.brevo.com', port: '587', enc: 'STARTTLS', user: 'Login e-mail · SMTP key' },
];

/** Admin Settings → Integrations (GET/PUT /api/integrations). Secrets never come back from the server. */
export function IntegrationsTab() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { data, isLoading, error, refetch } = useApi<IntegrationsPayload>(['integrations'], 'integrations');
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [secretEdit, setSecretEdit] = useState<Record<string, boolean>>({});
  const [secretClear, setSecretClear] = useState<Record<string, boolean>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState<string | null>(null);
  const [logOpen, setLogOpen] = useState(false);
  const [renewing, setRenewing] = useState(false);

  if (error) {
    return (
      <Alert variant="error" title="Unable to load integrations" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
        {(error as ApiError).message}
      </Alert>
    );
  }
  if (isLoading || !data) {
    return (
      <div className="space-y-6">
        {Array.from({ length: 3 }).map((_, i) => (
          <Card key={i} className="space-y-4 p-5">
            <Skeleton className="h-5 w-1/3" />
            <div className="grid grid-cols-2 gap-4">{Array.from({ length: 4 }).map((__, j) => <Skeleton key={j} className="h-10 w-full" />)}</div>
          </Card>
        ))}
      </div>
    );
  }

  const readOnly = !data.can_edit;
  const st = data.status;
  const val = (k: string) => drafts[k] ?? data.values[k] ?? '';
  const keysOf = (s: IntegrationSection) => s.fields.map((f) => f.key);
  const dirty = (s: IntegrationSection) => s.fields.some((f) => (drafts[f.key] !== undefined && drafts[f.key] !== (data.values[f.key] ?? '')) || !!secretClear[f.key]);
  const reset = (keys: string[]) => {
    const drop = <T,>(o: Record<string, T>) => Object.fromEntries(Object.entries(o).filter(([k]) => !keys.includes(k)));
    setDrafts(drop);
    setSecretEdit(drop);
    setSecretClear(drop);
    setErrors(drop);
  };
  const save = async (s: IntegrationSection) => {
    const payload: Record<string, unknown> = {};
    const clear: string[] = [];
    s.fields.forEach((f) => {
      if (f.secret) {
        if (secretClear[f.key]) clear.push(f.key);
        else if (drafts[f.key]) payload[f.key] = drafts[f.key];
        return;
      }
      if (drafts[f.key] !== undefined) payload[f.key] = drafts[f.key];
    });
    if (clear.length) payload.__clear = clear;
    setSaving(s.key);
    try {
      const res = await api.put<IntegrationsPayload>(`integrations/${s.key}`, payload);
      qc.setQueryData(['integrations'], res.data);
      reset(keysOf(s));
      toast.success(res.message);
    } catch (e) {
      const ex = e as ApiError;
      setErrors((x) => ({ ...x, ...(ex.errors ?? {}) }));
      toast.error(ex.message || 'Unable to save. Please try again.');
    } finally {
      setSaving(null);
    }
  };
  const renewConsent = async () => {
    if (!(await confirm({ title: 'Ask every visitor again?', message: 'All stored cookie choices become invalid and the banner is shown again to everyone. Use this after adding a new tracking service or changing the cookie policy.', confirmText: 'Ask again' }))) return;
    setRenewing(true);
    try {
      const res = await api.post('integrations/consent/renew');
      toast.success(res.message);
      void refetch();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setRenewing(false);
    }
  };

  const extras: Record<string, ReactNode> = {
    captcha: (
      <div className="flex flex-col gap-2 rounded-xl border border-dashed border-slate-200 p-3.5 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-slate-700">
        <p className="text-slate-600 dark:text-slate-300">
          {st.captcha.provider === 'none' ? 'No CAPTCHA: public forms use a hidden honeypot, a minimum fill time and per-IP rate limits.'
            : st.captcha.ready ? 'Configured — every public form verifies the token on the server before saving.' : 'Keys missing — forms fall back to honeypot + rate limits until both keys are set.'}
        </p>
        {CAPTCHA_LINKS[val('captcha_provider')] && (
          <Button size="sm" variant="secondary" iconRight={ExternalLink} href={CAPTCHA_LINKS[val('captcha_provider')].url} target="_blank">{CAPTCHA_LINKS[val('captcha_provider')].label}</Button>
        )}
      </div>
    ),
    analytics: (
      <div className="space-y-2 rounded-xl border border-dashed border-slate-200 p-3.5 text-sm dark:border-slate-700">
        <p className="text-slate-600 dark:text-slate-300">
          Conversions: website forms push <code className="rounded bg-slate-100 px-1 text-xs dark:bg-slate-800">{"{event: 'generate_lead', form: 'enquiry'}"}</code> to the dataLayer.
          They are sent to GA4 (when loaded directly) and to the Meta Pixel as standard events (Lead, Contact, SubmitApplication…) — with Tag Manager, set up the conversion tags in GTM.
        </p>
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="secondary" icon={Eye} href={data.consent_preview_url} target="_blank">Preview the cookie banner</Button>
          <Button size="sm" variant="ghost" iconRight={ExternalLink} href="https://search.google.com/search-console" target="_blank">Search Console</Button>
          <Button size="sm" variant="ghost" iconRight={ExternalLink} href="https://analytics.google.com/" target="_blank">Google Analytics</Button>
        </div>
      </div>
    ),
    consent: (
      <div className="flex flex-col gap-3 rounded-xl border border-dashed border-slate-200 p-3.5 text-sm lg:flex-row lg:items-center lg:justify-between dark:border-slate-700">
        <p className="text-slate-600 dark:text-slate-300">
          {st.consent.mode === 'off' ? 'Banner switched off.' : st.consent.banner ? 'The banner is shown to new visitors.' : 'No analytics or marketing tag is configured, so the banner is not needed (Automatic).'} Consent version {st.consent.version}.
        </p>
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="secondary" icon={Eye} href={data.consent_preview_url} target="_blank">Preview banner</Button>
          <Button size="sm" variant="secondary" iconRight={ArrowRight} to="/cms/pages?q=cookie">Edit cookie policy</Button>
          {!readOnly && <Button size="sm" variant="ghost" icon={RefreshCcw} loading={renewing} onClick={() => void renewConsent()}>Ask everyone again</Button>}
        </div>
      </div>
    ),
    maps: /^https:\/\/(www\.)?google\.[a-z.]+\/maps/i.test(val('map_embed_url')) ? (
      <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700">
        <iframe title="Campus map preview" src={val('map_embed_url')} className="h-56 w-full border-0" loading="lazy" referrerPolicy="no-referrer-when-downgrade" />
      </div>
    ) : <p className="text-sm text-slate-500">Add an embed URL to show the campus map on the Contact page.</p>,
    monitoring: (
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <MiniStat label="Website errors · 24 h" value={formatNumber(st.monitoring.client_24h)} tone={st.monitoring.client_24h ? 'text-red-600 dark:text-red-400' : undefined} />
          <MiniStat label="Website errors · 7 days" value={formatNumber(st.monitoring.client_7d)} />
          <MiniStat label="Server errors · 7 days" value={formatNumber(st.monitoring.server_7d)} />
          <MiniStat label="Last website error" value={st.monitoring.client_last ? timeAgo(st.monitoring.client_last) : 'None'} />
        </div>
        <div className="flex flex-wrap gap-2">
          {data.can_view_errors && <Button size="sm" variant="secondary" icon={Bug} onClick={() => setLogOpen(true)}>Open the error log</Button>}
          <Button size="sm" variant="ghost" iconRight={ArrowRight} to="/activity-logs">Activity logs</Button>
          {st.monitoring.sentry && <Badge color="purple">Sentry connected</Badge>}
        </div>
      </div>
    ),
    whatsapp: (
      <div className="flex flex-wrap items-center gap-2 text-sm">
        {/^[0-9]{8,15}$/.test(val('whatsapp_number')) && <Button size="sm" variant="secondary" icon={MessageCircle} href={whatsappHref(`+${val('whatsapp_number')}`)} target="_blank">Test the chat link</Button>}
        <Button size="sm" variant="ghost" iconRight={ArrowRight} to="/settings?tab=email">WhatsApp Business API gateway {st.whatsapp.gateway ? '(enabled)' : '(off)'}</Button>
      </div>
    ),
    search: (
      <div className="space-y-3">
        {st.search.blocked && <Alert variant="warning" title="Search engines are discouraged">robots.txt disallows everything and every page is noindex. Switch this off before launch.</Alert>}
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="secondary" icon={FileCode2} href={st.search.sitemap_url} target="_blank">sitemap.xml</Button>
          <Button size="sm" variant="secondary" icon={FileCode2} href={st.search.robots_url} target="_blank">robots.txt</Button>
          <Button size="sm" variant="ghost" iconRight={ArrowRight} to="/cms/seo?tab=robots">Robots & sitemap</Button>
          <Button size="sm" variant="ghost" iconRight={ArrowRight} to="/redirects">Redirects</Button>
          <Button size="sm" variant="ghost" icon={SearchCheck} to="/cms/seo?tab=audit">SEO audit</Button>
        </div>
        <p className="text-xs text-slate-500">
          {st.search.last_audit ? `Last SEO audit ${timeAgo(st.search.last_audit.finished_at)}: ${formatNumber(st.search.last_audit.pages_crawled)} URLs, ${st.search.last_audit.errors_count} with errors, ${st.search.last_audit.warnings_count} with warnings.` : 'No SEO audit has been run yet.'}
        </p>
      </div>
    ),
  };

  return (
    <div className="space-y-6 motion-safe:animate-slide-up">
      <div className="flex items-center gap-3">
        <span className="kpi-icon bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300"><Plug className="h-5 w-5" /></span>
        <div>
          <h2 className="font-display text-lg font-bold text-slate-900 dark:text-white">Integrations settings</h2>
          <p className="text-sm text-slate-500 dark:text-slate-400">Spam protection, analytics & consent, maps, error monitoring, WhatsApp and search engines. Secrets stay on the server.</p>
        </div>
      </div>
      {readOnly && <Alert variant="info">You can view these settings but your role cannot change them.</Alert>}

      <Reveal>
        <Card className="p-4 sm:p-5">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 2xl:grid-cols-4">
            <StatusTile icon={ShieldCheck} label="Spam protection" ok={st.captcha.ready} value={st.captcha.provider === 'none' ? 'Honeypot + rate limits' : labelProvider(st.captcha.provider)} />
            <StatusTile icon={ChartLine} label="Analytics & tags" ok={st.analytics.ga || st.analytics.gtm || st.analytics.pixel}
              value={[st.analytics.ga && 'GA4', st.analytics.gtm && 'GTM', st.analytics.pixel && 'Meta Pixel'].filter(Boolean).join(' · ') || 'Not configured'} neutral />
            <StatusTile icon={Cookie} label="Cookie banner" ok={st.consent.mode !== 'off'} value={st.consent.mode === 'off' ? 'Off' : st.consent.banner ? `Shown · v${st.consent.version}` : 'Not needed yet'} neutral={!st.consent.banner} />
            <StatusTile icon={Activity} label="Website errors (24 h)" ok={st.monitoring.client_24h === 0} value={st.monitoring.enabled ? formatNumber(st.monitoring.client_24h) : 'Logging off'} />
            <StatusTile icon={Mail} label="Email delivery" ok={st.email.effective === 'smtp' && !!st.email.host} value={st.email.dev ? 'Development: written to log' : st.email.host ? `${st.email.host}:${st.email.port}` : 'SMTP not configured'} />
            <StatusTile icon={MapPin} label="Google Maps" ok={st.maps.embed} value={st.maps.embed ? (st.maps.key ? 'Embed + API key' : 'Embed URL') : 'No map'} />
            <StatusTile icon={MessageCircle} label="WhatsApp" ok={!!st.whatsapp.number} value={st.whatsapp.number ? formatPhone(`+${st.whatsapp.number}`) : 'Hidden'} />
            <StatusTile icon={Search} label="Search engines" ok={!st.search.blocked} value={st.search.blocked ? 'Indexing discouraged' : 'Indexing allowed'} />
          </div>
        </Card>
      </Reveal>

      {data.sections.map((s, i) => {
        const Icon = ICONS[s.icon] ?? Plug;
        const isDirty = dirty(s);
        return (
          <Reveal key={s.key} delay={Math.min(i, 4) * 60}>
            <Card id={`integration-${s.key}`} className={clsx('overflow-hidden transition-shadow duration-300', isDirty && 'ring-2 ring-amber-300/60 dark:ring-amber-500/30')}>
              <div className="flex items-start gap-3 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
                <span className={clsx('mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', TONE[s.key] ?? 'bg-slate-100 text-slate-600')}><Icon className="h-4 w-4" /></span>
                <div className="min-w-0">
                  <h3 className="card-title">{s.title}</h3>
                  <p className="card-subtitle">{s.description}</p>
                </div>
              </div>
              <div className="space-y-5 p-5">
                <div className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-12">
                  {s.fields.map((f) => {
                    const env = data.env[f.key];
                    const envSet = !!env?.set;
                    const hidden = s.key === 'captcha' && f.key !== 'captcha_provider' && val('captcha_provider') === 'none';
                    if (hidden) return null;
                    const options: Option[] | undefined = f.options;
                    return (
                      <Field key={f.key} className={clsx('col-span-1', colSpan[f.col ?? 6] ?? 'sm:col-span-6')} label={f.type === 'toggle' ? undefined : f.label} htmlFor={`s-${f.key}`}
                        error={errors[f.key]} hint={f.type === 'toggle' ? undefined : envSet ? `Set on the server by ${env?.name} — that value is used.` : f.help}>
                        <SettingInput
                          field={{ ...f, options }}
                          value={val(f.key)}
                          onChange={(v) => {
                            setDrafts((d) => ({ ...d, [f.key]: v }));
                            if (errors[f.key]) setErrors((x) => ({ ...x, [f.key]: '' }));
                          }}
                          invalid={!!errors[f.key]}
                          disabled={readOnly || envSet}
                          secretStored={data.secrets[f.key]}
                          secretEditing={secretEdit[f.key]}
                          secretCleared={secretClear[f.key]}
                          onSecretEdit={(on) => {
                            setSecretEdit((x) => ({ ...x, [f.key]: on }));
                            setSecretClear((x) => ({ ...x, [f.key]: false }));
                            if (!on) setDrafts((d) => Object.fromEntries(Object.entries(d).filter(([k]) => k !== f.key)));
                          }}
                          onSecretClear={() => setSecretClear((x) => ({ ...x, [f.key]: true }))}
                        />
                        {env && !envSet && f.type !== 'toggle' && <p className="mt-1 text-[11px] text-slate-400">Can also be set with the environment variable <code>{env.name}</code>.</p>}
                      </Field>
                    );
                  })}
                </div>
                {extras[s.key]}
              </div>
              {!readOnly && (
                <div className="flex flex-col-reverse gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-800/20">
                  <p className={clsx('text-xs transition-opacity', isDirty ? 'text-amber-700 opacity-100 dark:text-amber-300' : 'text-slate-400')}>{isDirty ? 'You have unsaved changes in this section.' : 'All changes saved.'}</p>
                  <div className="flex gap-2">
                    <Button size="sm" variant="ghost" icon={RotateCcw} onClick={() => reset(keysOf(s))} disabled={!isDirty || saving === s.key}>Reset</Button>
                    <Button size="sm" icon={Save} onClick={() => void save(s)} loading={saving === s.key} disabled={!isDirty}>Save {s.title.split(' (')[0].toLowerCase()}</Button>
                  </div>
                </div>
              )}
            </Card>
          </Reveal>
        );
      })}

      <Reveal>
        <Card className="overflow-hidden">
          <div className="flex items-start gap-3 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300"><Mail className="h-4 w-4" /></span>
            <div className="min-w-0 flex-1">
              <h3 className="card-title">Transactional email (SMTP)</h3>
              <p className="card-subtitle">Receipts, acknowledgements, password resets and newsletters. Server, port and credentials are kept in Email &amp; SMS settings — use the provider values below.</p>
            </div>
            <Button size="sm" variant="secondary" iconRight={ArrowRight} to="/settings?tab=email" className="hidden shrink-0 sm:inline-flex">Email &amp; SMS settings</Button>
          </div>
          <div className="space-y-4 p-5">
            <div className="flex flex-wrap items-center gap-2 text-sm">
              <Badge color={st.email.effective === 'smtp' && st.email.host ? 'green' : 'amber'}>{st.email.dev ? 'Development: emails go to storage/logs/mail.log' : st.email.effective === 'smtp' && st.email.host ? 'SMTP active' : st.email.effective === 'mail' ? 'PHP mail() fallback' : 'Log only'}</Badge>
              {st.email.host && <span className="font-mono text-xs text-slate-600 dark:text-slate-300">{st.email.host}:{st.email.port} · {st.email.encryption.toUpperCase()}</span>}
              {st.email.from && <span className="text-xs text-slate-500">From {st.email.from}</span>}
              <span className="inline-flex items-center gap-1 text-xs text-slate-500"><KeyRound className="h-3 w-3" />Password {st.email.password_set ? 'stored' : 'not set'}</span>
            </div>
            <div className="table-wrap overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
              <table className="data-table">
                <caption className="sr-only">SMTP settings of common providers</caption>
                <thead><tr><th>Provider</th><th>SMTP host</th><th>Port</th><th>Encryption</th><th>Username / password</th></tr></thead>
                <tbody>
                  {SMTP_PRESETS.map((p) => (
                    <tr key={p.name}>
                      <td className="whitespace-nowrap font-medium">{p.name}</td>
                      <td className="font-mono text-xs">{p.host}</td>
                      <td className="tabular-nums">{p.port}</td>
                      <td>{p.enc}</td>
                      <td className="min-w-[220px] text-xs text-slate-500">{p.user}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="text-xs text-slate-500">Also add SPF, DKIM and DMARC DNS records for your sending domain so messages do not land in spam.</p>
            <Button size="sm" variant="secondary" iconRight={ArrowRight} to="/settings?tab=email" className="sm:hidden">Email &amp; SMS settings</Button>
          </div>
        </Card>
      </Reveal>

      <Reveal>
        <Card className="overflow-hidden">
          <div className="flex items-start gap-3 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200"><Terminal className="h-4 w-4" /></span>
            <div className="min-w-0">
              <h3 className="card-title">Environment variables</h3>
              <p className="card-subtitle">Set these on the server (hosting panel, Apache SetEnv or the PHP-FPM pool) to keep keys out of the database. An environment value always wins over the value saved here; values are never shown.</p>
            </div>
          </div>
          <div className="table-wrap overflow-x-auto">
            <table className="data-table">
              <caption className="sr-only">Environment variables read by the application</caption>
              <thead><tr><th>Variable</th><th>Used for</th><th className="text-right">Status</th></tr></thead>
              <tbody>
                {data.env_vars.map((v) => (
                  <tr key={v.name}>
                    <td className="whitespace-nowrap font-mono text-xs font-semibold">{v.name}{v.secret && <KeyRound className="ml-1 inline h-3 w-3 text-slate-400" aria-label="secret" />}</td>
                    <td className="text-sm">{v.label}</td>
                    <td className="text-right">{v.set ? <Badge color="green">Set</Badge> : <Badge color="slate">Not set</Badge>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="flex items-center gap-1.5 border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800"><ServerCog className="h-3.5 w-3.5" />Changes to environment variables need a PHP / web server reload.</p>
        </Card>
      </Reveal>

      <ErrorLogDrawer open={logOpen} onClose={() => setLogOpen(false)} />
    </div>
  );
}

function labelProvider(p: string) {
  return ({ recaptcha_v3: 'reCAPTCHA v3', turnstile: 'Cloudflare Turnstile', hcaptcha: 'hCaptcha' } as Record<string, string>)[p] ?? p;
}

function StatusTile({ icon: Icon, label, value, ok, neutral }: { icon: LucideIcon; label: string; value: string; ok: boolean; neutral?: boolean }) {
  return (
    <div className="flex items-start gap-3 rounded-xl border border-slate-200/80 p-3 dark:border-slate-700/80">
      <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"><Icon className="h-4 w-4" /></span>
      <div className="min-w-0 flex-1">
        <p className="text-xs font-medium text-slate-500 dark:text-slate-400">{label}</p>
        <p className="break-words text-sm font-semibold text-slate-900 dark:text-white">{value}</p>
      </div>
      {neutral && !ok ? <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-slate-300 dark:bg-slate-600" aria-hidden />
        : ok ? <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-500" aria-label="OK" /> : <CircleAlert className="h-4 w-4 shrink-0 text-amber-500" aria-label="Needs attention" />}
    </div>
  );
}

function MiniStat({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div className="rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-slate-800/50">
      <p className="text-[11px] font-medium text-slate-500 dark:text-slate-400">{label}</p>
      <p className={clsx('font-display text-lg font-bold tabular-nums text-slate-900 dark:text-white', tone)}>{value}</p>
    </div>
  );
}

/** Extra navigation entry for SettingsPage (the Integrations "tab" lives next to the server-defined groups). */
export function IntegrationsNavButton({ variant, active, onClick }: { variant: 'pill' | 'card'; active: boolean; onClick: () => void }) {
  if (variant === 'pill') {
    return (
      <button type="button" onClick={onClick} aria-current={active ? 'page' : undefined}
        className={clsx('inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition', active ? 'border-brand-800 bg-brand-800 text-white' : 'border-slate-200 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300')}>
        <Plug className="h-3.5 w-3.5" /> Integrations
      </button>
    );
  }
  return (
    <button type="button" onClick={onClick} aria-current={active ? 'page' : undefined}
      className={clsx('group relative mb-0.5 flex w-full items-start gap-3 rounded-xl px-3 py-2.5 text-left transition duration-150', active ? 'bg-brand-50 dark:bg-brand-500/10' : 'hover:bg-slate-50 dark:hover:bg-slate-800/50')}>
      <span className={clsx('absolute left-0 top-1/2 h-7 w-1 -translate-y-1/2 rounded-r-full bg-brand-700 transition-all duration-200 dark:bg-brand-300', active ? 'opacity-100' : 'scale-y-0 opacity-0')} />
      <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-teal-100 text-teal-700 transition group-hover:scale-105 dark:bg-teal-500/20 dark:text-teal-300"><Plug className="h-4 w-4" /></span>
      <span className="min-w-0">
        <span className={clsx('flex items-center gap-1.5 text-sm font-semibold', active ? 'text-brand-900 dark:text-white' : 'text-slate-700 dark:text-slate-200')}>Integrations</span>
        <span className="line-clamp-2 text-[11px] leading-snug text-slate-500 dark:text-slate-400">CAPTCHA, analytics &amp; consent, maps, error monitoring, WhatsApp, SEO.</span>
      </span>
    </button>
  );
}

