import { useEffect, useState } from 'react';
import clsx from 'clsx';
import {
  Activity,
  ArrowRight,
  Building2,
  CheckCircle2,
  Circle,
  Clock,
  FlaskConical,
  KeyRound,
  LayoutTemplate,
  Plug,
  PlugZap,
  Radio,
  RefreshCw,
  Rocket,
  Save,
  ShieldCheck,
  Sparkles,
  Trash2,
  Webhook,
} from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Field, Input, Modal, PageHeader, Select, Skeleton, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { useWaRefresh, waRunWorker } from '../shared';
import { useIntegration, workerState, type ConnectionTest, type IntegrationInfo } from './lib';
import { CopyButton, CopyField, HealthDot, KV } from './ui';

interface ConnForm {
  api_key: string;
  base_url: string;
  plan: string;
  rate_per_minute: string;
  business_number: string;
  business_name: string;
}

/**
 * Integrations (Module 11): Interakt connection (write-only API key), test / live mode, connection test, business
 * account, template sync, webhook setup (URL, secret, event checklist, test webhook) and the background worker.
 */
export default function IntegrationsPage() {
  const { can } = useAuth();
  const { data, isLoading, error, refetch } = useIntegration();
  const canManage = can('whatsapp_settings', 'manage');
  const canEdit = can('whatsapp_settings', 'edit');

  return (
    <>
      <PageHeader
        title="Integrations"
        description="Connect GIMT to the Interakt WhatsApp Business API, set up webhooks and keep the background worker running."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Integrations' }]}
        actions={
          <Button variant="secondary" icon={Webhook} to="/whatsapp/api">
            API &amp; Webhooks
          </Button>
        }
      />
      {error ? (
        <Alert variant="error" title="Could not load the integration" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : isLoading || !data ? (
        <div className="grid grid-cols-1 gap-5 xl:grid-cols-12">
          <Skeleton className="h-24 rounded-2xl xl:col-span-12" />
          <Skeleton className="h-96 rounded-2xl xl:col-span-8" />
          <Skeleton className="h-96 rounded-2xl xl:col-span-4" />
        </div>
      ) : (
        <>
          <StatusStrip info={data} />
          <div className="grid grid-cols-1 gap-5 xl:grid-cols-12">
            <div className="space-y-5 xl:col-span-8">
              <ConnectionCard info={data} canManage={canManage} canEdit={canEdit} />
              <ModeCard info={data} canManage={canManage} />
              <WebhookCard info={data} canManage={canManage} canEdit={canEdit} />
            </div>
            <div className="space-y-5 xl:col-span-4">
              <AccountCard info={data} />
              <TemplatesCard info={data} />
              <WorkerCard info={data} canManage={canManage} />
            </div>
          </div>
        </>
      )}
    </>
  );
}

/**
 * Webhook state for the status strip and the webhook card. Uses core's webhook_health (live mode: silent = sends go
 * out but Interakt stopped calling — it auto-disables a webhook after repeated failures; bad_signature = the secrets
 * differ). Test mode ('n/a') or an older core without the field falls back to "when did the last webhook arrive".
 */
function webhookState(info: IntegrationInfo): { value: string; state: 'ok' | 'warning' | 'error'; note: string | null } {
  const last = info.last_webhook_at ? `Last ${timeAgo(info.last_webhook_at)}` : 'Nothing received';
  switch (info.webhook_health) {
    case 'ok':
      return { value: last, state: 'ok', note: info.webhook_health_note ?? null };
    case 'silent':
      return {
        value: 'Silent',
        state: 'error',
        note: info.webhook_health_note ?? 'Messages are going out but no Interakt webhook has arrived — Interakt may have auto-disabled it. Re-enable it in Interakt › Developer Settings › Webhook.',
      };
    case 'bad_signature':
      return {
        value: 'Bad signature',
        state: 'error',
        note: info.webhook_health_note ?? 'Recent webhooks fail the signature check — the secret saved here differs from the one in Interakt › Developer Settings › Webhook.',
      };
    case 'never':
      return { value: 'Nothing received', state: 'warning', note: info.webhook_health_note ?? 'No webhook from Interakt has arrived yet — check the webhook URL and the ticked events in Interakt.' };
    default:
      return { value: last, state: info.last_webhook_at ? 'ok' : 'warning', note: null };
  }
}

function StatusStrip({ info }: { info: IntegrationInfo }) {
  const worker = workerState(info);
  const hook = webhookState(info);
  const items: { label: string; value: string; state: 'ok' | 'warning' | 'error'; title?: string }[] = [
    { label: 'Mode', value: info.mode === 'live' ? 'Live' : 'Test', state: info.mode === 'live' ? 'ok' : 'warning' },
    { label: 'API key', value: info.key_set ? `Saved • …${info.key_last4}` : 'Not saved', state: info.key_set ? 'ok' : 'error' },
    { label: 'Webhook', value: hook.value, state: hook.state, title: hook.note ?? undefined },
    { label: 'Worker', value: worker.short, state: worker.state, title: worker.detail },
    { label: 'Queue', value: `${formatNumber(info.queue.due)} due`, state: info.queue.due > 200 ? 'warning' : 'ok' },
  ];
  return (
    <Card className="mb-5 overflow-hidden">
      <div className="grid grid-cols-2 divide-slate-100 sm:grid-cols-3 lg:grid-cols-5 lg:divide-x dark:divide-slate-800">
        {items.map((i) => (
          <div key={i.label} className="flex items-center gap-3 px-4 py-3" title={i.title}>
            <HealthDot state={i.state} pulse={i.state === 'ok'} />
            <div className="min-w-0">
              <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{i.label}</p>
              <p className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{i.value}</p>
            </div>
          </div>
        ))}
      </div>
      {info.pages_only && (
        <p className="border-t border-slate-100 bg-amber-50 px-4 py-2 text-xs text-amber-900 dark:border-slate-800 dark:bg-amber-500/10 dark:text-amber-100">{worker.detail}</p>
      )}
    </Card>
  );
}

function ConnectionCard({ info, canManage, canEdit }: { info: IntegrationInfo; canManage: boolean; canEdit: boolean }) {
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useWaRefresh();
  const init = (): ConnForm => ({
    api_key: '',
    base_url: info.base_url,
    plan: info.plan,
    rate_per_minute: String(info.rate_per_minute),
    business_number: info.business_number ? `+${info.business_number}` : '',
    business_name: info.business_name ?? '',
  });
  const [form, setForm] = useState<ConnForm>(init);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [test, setTest] = useState<ConnectionTest | null>(info.last_test ?? null);
  useEffect(() => setTest(info.last_test ?? null), [info.last_test]);

  const set = (k: keyof ConnForm, v: string) => {
    setForm((f) => ({ ...f, [k]: v }));
    setErrors((e) => ({ ...e, [k]: '' }));
  };
  const onPlan = (plan: string) => {
    const p = info.plans.find((x) => x.value === plan);
    setForm((f) => ({ ...f, plan, rate_per_minute: p ? String(p.rate) : f.rate_per_minute }));
  };

  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const body: Record<string, string> = { ...form };
      if (!body.api_key) delete body.api_key;
      const r = await api.put('whatsapp/integration', body);
      toast.success(r.message);
      setForm((f) => ({ ...f, api_key: '' }));
      await refresh();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  const removeKey = async () => {
    const ok = await confirm({ title: 'Remove the API key?', message: 'WhatsApp switches to test mode and nothing is sent until a new key is saved.', confirmText: 'Remove key', danger: true });
    if (!ok) return;
    try {
      const r = await api.del('whatsapp/integration/key');
      toast.success(r.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const runTest = async () => {
    setTesting(true);
    try {
      const r = await api.post<ConnectionTest>('whatsapp/integration/test');
      setTest(r.data);
      if (r.data.skipped) toast.info(r.message);
      else toast.success(r.message);
    } catch (e) {
      const err = e as ApiError;
      toast.error(err.message);
      refresh('admin');
    } finally {
      setTesting(false);
    }
  };

  const planInfo = info.plans.find((p) => p.value === form.plan);

  return (
    <Card>
      <CardHeader title="Interakt connection" subtitle="Developer Settings › API key in your Interakt dashboard" icon={PlugZap} />
      <div className="card-body space-y-4">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field
            label="API key"
            htmlFor="wa-key"
            error={errors.api_key}
            hint={info.key_set ? `Saved • ends with ${info.key_last4}${info.key_saved_at ? ` · ${timeAgo(info.key_saved_at)}` : ''}. Leave empty to keep it.` : 'Stored encrypted — never shown again or sent to the browser.'}
            className="md:col-span-2"
          >
            <div className="flex gap-2">
              <div className="min-w-0 flex-1">
              <Input
                id="wa-key"
                type="password"
                autoComplete="new-password"
                spellCheck={false}
                value={form.api_key}
                onChange={(e) => set('api_key', e.target.value)}
                placeholder={info.key_set ? `•••••••••••••••• ${info.key_last4 ?? ''}` : 'Paste the Interakt API key'}
                prefix={<KeyRound className="h-4 w-4" />}
                invalid={!!errors.api_key}
                disabled={!canManage}
                className="font-mono"
              />
              </div>
              {info.key_set && canManage && (
                <Button variant="secondary" icon={Trash2} onClick={removeKey} aria-label="Remove API key" className="shrink-0">
                  <span className="hidden sm:inline">Remove</span>
                </Button>
              )}
            </div>
          </Field>
          <Field label="API base URL" htmlFor="wa-base" error={errors.base_url} hint="Must be on interakt.ai — the API key is sent only to Interakt." className="md:col-span-2">
            <Input id="wa-base" value={form.base_url} onChange={(e) => set('base_url', e.target.value)} invalid={!!errors.base_url} disabled={!canManage} className="font-mono text-[13px]" />
          </Field>
          <Field label="Interakt plan" htmlFor="wa-plan" error={errors.plan} hint={planInfo ? (planInfo.incoming ? 'Incoming messages (inbox, AI bot) are available.' : 'Incoming messages need the Advanced plan or higher.') : undefined}>
            <Select id="wa-plan" value={form.plan} onChange={(e) => onPlan(e.target.value)} options={info.plans.map((p) => ({ value: p.value, label: p.label }))} disabled={!canManage} />
          </Field>
          <Field label="Send rate (messages / minute)" htmlFor="wa-rate" error={errors.rate_per_minute} hint={planInfo?.limit ? `Interakt limit ${planInfo.limit}/min — keep headroom.` : 'Set to your Enterprise limit.'}>
            <Input id="wa-rate" type="number" min={1} max={1000} value={form.rate_per_minute} onChange={(e) => set('rate_per_minute', e.target.value)} invalid={!!errors.rate_per_minute} disabled={!canManage} />
          </Field>
          <Field label="WhatsApp business number" htmlFor="wa-num" error={errors.business_number}>
            <Input id="wa-num" type="tel" value={form.business_number} onChange={(e) => set('business_number', e.target.value)} placeholder="+91 98765 43210" invalid={!!errors.business_number} disabled={!canManage} />
          </Field>
          <Field label="Display name" htmlFor="wa-name" error={errors.business_name}>
            <Input id="wa-name" value={form.business_name} onChange={(e) => set('business_name', e.target.value)} maxLength={120} invalid={!!errors.business_name} disabled={!canManage} />
          </Field>
        </div>

        {test && info.key_set && (
          <div
            className={clsx(
              'flex items-start gap-2.5 rounded-xl px-3 py-2.5 text-sm',
              test.ok
                ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200'
                : test.skipped
                  ? 'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-100'
                  : 'bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-200',
            )}
            role="status"
          >
            {test.ok ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /> : test.skipped ? <FlaskConical className="mt-0.5 h-4 w-4 shrink-0" /> : <Circle className="mt-0.5 h-4 w-4 shrink-0" />}
            <p className="min-w-0">
              <span className="font-semibold">{test.ok ? 'Connected' : test.skipped ? 'Not tested (test mode)' : 'Connection failed'}</span>
              {test.ok ? ` in ${test.duration_ms} ms${test.templates !== null ? ` · ${test.templates} approved templates` : ''}` : ` — ${test.error ?? 'unknown error'}`}
              <span className="block text-xs opacity-80">
                {test.skipped ? 'Checked' : 'Tested'} {formatDateTime(test.at)}
              </span>
            </p>
          </div>
        )}
        {!test && info.key_set && info.mode !== 'live' && (
          <p className="flex items-start gap-2 rounded-xl bg-slate-50 px-3 py-2.5 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
            <FlaskConical className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
            Test mode: nothing is sent to Interakt, so “Test connection” can only verify the key after you switch to live mode.
          </p>
        )}

        <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
          {canEdit && (
            <Button variant="secondary" icon={Activity} loading={testing} onClick={runTest} disabled={!info.key_set} title={info.key_set ? undefined : 'Save the API key first'}>
              Test connection
            </Button>
          )}
          {canManage && (
            <Button icon={Save} loading={saving} onClick={save}>
              Save connection
            </Button>
          )}
        </div>
      </div>
    </Card>
  );
}

function ModeCard({ info, canManage }: { info: IntegrationInfo; canManage: boolean }) {
  const toast = useToast();
  const refresh = useWaRefresh();
  const [open, setOpen] = useState(false);
  const [confirmText, setConfirmText] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  const switchTo = async (mode: 'test' | 'live') => {
    setBusy(true);
    setErr('');
    try {
      const r = await api.post('whatsapp/integration/mode', { mode, confirm: confirmText });
      toast.success(r.message);
      setOpen(false);
      setConfirmText('');
      await refresh();
    } catch (e) {
      const ae = e as ApiError;
      setErr(ae.errors?.confirm || ae.errors?.mode || ae.message);
      if (mode === 'test') toast.error(ae.message);
    } finally {
      setBusy(false);
    }
  };

  const live = info.mode_setting === 'live';
  return (
    <Card>
      <CardHeader title="Sending mode" subtitle="Test mode simulates every message; live mode sends through Interakt" icon={Radio} />
      <div className="card-body">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {(
            [
              { key: 'test', title: 'Test mode', icon: FlaskConical, text: 'Messages are simulated (no WhatsApp delivery). Use the simulators to test webhooks, the inbox and automations.' },
              { key: 'live', title: 'Live mode', icon: Rocket, text: 'Real messages go to students and parents through Interakt. Needs a saved API key and a production install.' },
            ] as const
          ).map((m) => {
            const active = info.mode_setting === m.key;
            return (
              <div
                key={m.key}
                className={clsx(
                  'rounded-2xl border p-4 transition',
                  active ? (m.key === 'live' ? 'border-emerald-400 bg-emerald-50/60 dark:border-emerald-500/50 dark:bg-emerald-500/10' : 'border-amber-300 bg-amber-50/60 dark:border-amber-500/40 dark:bg-amber-500/10') : 'border-slate-200 dark:border-slate-700',
                )}
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="flex items-center gap-2 font-semibold text-slate-800 dark:text-slate-100">
                    <m.icon className="h-4 w-4" aria-hidden /> {m.title}
                  </span>
                  {active && <Badge color={m.key === 'live' ? 'green' : 'amber'}>Selected</Badge>}
                </div>
                <p className="mt-1.5 text-sm text-slate-600 dark:text-slate-300">{m.text}</p>
                {canManage && !active && (
                  <Button
                    size="sm"
                    className="mt-3"
                    variant={m.key === 'live' ? 'primary' : 'secondary'}
                    loading={busy && m.key === 'test'}
                    onClick={() => (m.key === 'live' ? setOpen(true) : switchTo('test'))}
                    disabled={m.key === 'live' && !info.key_set}
                    title={m.key === 'live' && !info.key_set ? 'Save the API key first' : undefined}
                  >
                    Switch to {m.title.toLowerCase()}
                  </Button>
                )}
              </div>
            );
          })}
        </div>
        {live && info.mode !== 'live' && (
          <Alert variant="warning" className="mt-3" title="Live mode is selected but messages are still simulated">
            {!info.key_set ? 'No API key is saved.' : info.is_dev ? 'This is a development install — live sending is disabled outside production.' : 'Check the API key.'}
          </Alert>
        )}
      </div>
      <Modal
        open={open}
        onClose={() => !busy && setOpen(false)}
        title="Switch to live mode?"
        description="From now on every queued message, campaign, follow-up and automation is sent to real WhatsApp numbers through Interakt."
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={busy}>
              Cancel
            </Button>
            <Button variant="danger" icon={Rocket} loading={busy} disabled={confirmText.trim().toUpperCase() !== 'LIVE'} onClick={() => switchTo('live')}>
              Go live
            </Button>
          </>
        }
      >
        <ul className="mb-4 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
          <li>• {formatNumber(info.queue.pending)} message(s) are waiting in the queue and will be sent.</li>
          <li>• Only approved templates can be sent outside the 24-hour window.</li>
          <li>• Interakt bills every delivered template conversation.</li>
        </ul>
        <Field label='Type "LIVE" to confirm' htmlFor="wa-live-confirm" error={err || undefined}>
          <Input id="wa-live-confirm" value={confirmText} onChange={(e) => setConfirmText(e.target.value)} autoComplete="off" placeholder="LIVE" />
        </Field>
      </Modal>
    </Card>
  );
}

function WebhookCard({ info, canManage, canEdit }: { info: IntegrationInfo; canManage: boolean; canEdit: boolean }) {
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useWaRefresh();
  const [secret, setSecret] = useState('');
  const [generated, setGenerated] = useState(false);
  const [secretErr, setSecretErr] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const hook = webhookState(info);

  // The secret is created here, in the browser (Web Crypto), because it has to be pasted into Interakt anyway —
  // the server only stores it and never sends a secret back.
  const generate = () => {
    const bytes = new Uint8Array(24);
    window.crypto.getRandomValues(bytes);
    setSecret(`whsec_${Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')}`);
    setGenerated(true);
    setSecretErr('');
  };
  const saveSecret = async () => {
    if (info.secret_set) {
      const ok = await confirm({
        title: 'Replace the webhook secret?',
        message: 'Webhooks signed with the old secret are rejected from now on — save the same secret in Interakt › Developer Settings › Webhook.',
        confirmText: 'Replace secret',
        danger: true,
      });
      if (!ok) return;
    }
    setBusy('secret');
    setSecretErr('');
    try {
      const r = await api.post('whatsapp/integration/webhook-secret', { secret: secret.trim() });
      toast.success(r.message);
      setSecret('');
      setGenerated(false);
      await refresh('admin');
    } catch (e) {
      const ae = e as ApiError;
      setSecretErr(ae.errors?.secret ?? ae.message);
    } finally {
      setBusy(null);
    }
  };
  const testHook = async () => {
    setBusy('test');
    try {
      const r = await api.post('whatsapp/integration/webhook-test');
      toast.success(r.message);
      await refresh('admin');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card>
      <CardHeader
        title="Webhook setup"
        subtitle="Interakt › Developer Settings › Webhook"
        icon={Webhook}
        actions={<Button size="xs" variant="ghost" iconRight={ArrowRight} to="/whatsapp/api?tab=webhooks">Logs</Button>}
      />
      <div className="card-body space-y-5">
        <div>
          <p className="form-label">1. Webhook URL</p>
          <CopyField value={info.webhook_url} label="Webhook URL" />
          {!info.webhook_https && <p className="mt-1.5 text-xs text-amber-700 dark:text-amber-300">Interakt only calls HTTPS URLs — this address works once the site runs on https://.</p>}
        </div>

        <div>
          <p className="form-label">2. Secret key</p>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div className="flex flex-1 items-center gap-2 text-sm">
              <ShieldCheck className={clsx('h-4 w-4', info.secret_set ? 'text-emerald-600' : 'text-slate-400')} aria-hidden />
              {info.secret_set ? (
                <span>
                  Secret saved • ends with <code className="font-mono">{info.secret_last4}</code> — every webhook is signature-checked.
                </span>
              ) : (
                <span className="text-amber-700 dark:text-amber-300">No secret — webhooks are accepted without a signature check.</span>
              )}
            </div>
          </div>
          {canManage && (
            <div className="mt-3 space-y-2">
              <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
                <Field error={secretErr || undefined} className="min-w-0 flex-1">
                  <Input
                    type={generated ? 'text' : 'password'}
                    autoComplete="new-password"
                    spellCheck={false}
                    value={secret}
                    onChange={(e) => {
                      setSecret(e.target.value);
                      setSecretErr('');
                    }}
                    placeholder={info.secret_set ? 'New secret (paste or generate)' : 'Paste the secret you entered in Interakt, or generate one'}
                    aria-label="Webhook secret"
                    invalid={!!secretErr}
                    className="font-mono text-[13px]"
                  />
                </Field>
                <div className="flex shrink-0 gap-2">
                  <Button variant="secondary" icon={Sparkles} onClick={generate}>
                    Generate
                  </Button>
                  {generated && secret && <CopyButton text={secret} />}
                  <Button icon={KeyRound} loading={busy === 'secret'} disabled={secret.trim().length < 16} onClick={saveSecret}>
                    {info.secret_set ? 'Replace' : 'Save'}
                  </Button>
                </div>
              </div>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                {generated
                  ? 'Copy this secret into Interakt › Developer Settings › Webhook (Secret key), then save it here. After saving it is stored encrypted and never shown again.'
                  : 'At least 16 characters, no spaces. The secret is stored encrypted; GIMT only ever shows its last 4 characters.'}
              </p>
            </div>
          )}
        </div>

        <div>
          <p className="form-label">3. Tick these events in Interakt</p>
          <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
            {info.checklist.map((c) => (
              <li key={c.type} className="flex flex-col gap-1 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 items-start gap-2.5">
                  {c.last_received_at ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-label="Received" /> : <Circle className="mt-0.5 h-4 w-4 shrink-0 text-slate-300 dark:text-slate-600" aria-label="Not received yet" />}
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-800 dark:text-slate-100">
                      {c.label} <code className="ml-1 rounded bg-slate-100 px-1 py-0.5 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">{c.type}</code>
                      {!c.required && <span className="ml-1 text-xs font-normal text-slate-400">optional</span>}
                    </p>
                    <p className="text-xs text-slate-500 dark:text-slate-400">{c.note}</p>
                  </div>
                </div>
                <span className="shrink-0 pl-6 text-xs text-slate-500 sm:pl-0 dark:text-slate-400">{c.last_received_at ? `Last ${timeAgo(c.last_received_at)}` : 'Not received in 30 days'}</span>
              </li>
            ))}
          </ul>
          {!info.incoming_supported && (
            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
              Your plan is <span className="font-semibold capitalize">{info.plan}</span>: Interakt sends <code>message_received</code> (incoming chats) only on the Advanced plan or higher.
            </p>
          )}
        </div>

        {hook.state === 'error' && hook.note && (
          <Alert variant="error" title={info.webhook_health === 'bad_signature' ? 'Webhooks fail the signature check' : 'Interakt webhook is silent'}>
            {hook.note}
          </Alert>
        )}
        <div className="flex flex-col gap-3 rounded-xl bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between dark:bg-slate-800/60">
          <div className="text-sm text-slate-600 dark:text-slate-300">
            <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
              <HealthDot state={hook.state} />
              <span>
                Last webhook: <span className="font-semibold">{info.last_webhook_at ? `${formatDateTime(info.last_webhook_at)}${info.last_webhook_simulated ? ' (simulated)' : ''}` : 'never'}</span>
              </span>
            </p>
            {hook.state !== 'error' && hook.note && <p className="text-xs text-slate-500 dark:text-slate-400">{hook.note}</p>}
            <p className="text-xs text-slate-500 dark:text-slate-400">
              24 h: {formatNumber(info.webhooks_24h.total)} received · {formatNumber(info.webhooks_24h.failed)} failed · {formatNumber(info.webhooks_24h.bad_signature)} bad signature · {formatNumber(info.webhooks_pending)} waiting
            </p>
          </div>
          {canEdit && (
            <Button size="sm" variant="secondary" icon={Plug} loading={busy === 'test'} onClick={testHook}>
              Send test webhook
            </Button>
          )}
        </div>
      </div>
    </Card>
  );
}

function AccountCard({ info }: { info: IntegrationInfo }) {
  return (
    <Card>
      <CardHeader title="WhatsApp business account" icon={Building2} />
      <div className="card-body">
        <dl className="divide-y divide-slate-100 dark:divide-slate-800">
          <KV label="Display name">{info.business_name || '—'}</KV>
          <KV label="Number">{info.business_number ? `+${info.business_number}` : <span className="text-amber-600">Not set</span>}</KV>
          <KV label="Plan">
            <span className="capitalize">{info.plan}</span>
          </KV>
          <KV label="Send rate">{formatNumber(info.rate_per_minute)} / min</KV>
          <KV label="Incoming chats">{info.incoming_supported ? <Badge color="green">Enabled</Badge> : <Badge color="amber">Advanced plan needed</Badge>}</KV>
          <KV label="API calls (24 h)">
            {formatNumber(info.api_24h.total)}
            {info.api_24h.failed > 0 && <span className="ml-1 text-red-600 dark:text-red-400">· {formatNumber(info.api_24h.failed)} failed</span>}
          </KV>
        </dl>
      </div>
    </Card>
  );
}

function TemplatesCard({ info }: { info: IntegrationInfo }) {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useWaRefresh();
  const [busy, setBusy] = useState(false);
  const sync = async () => {
    setBusy(true);
    try {
      const r = await api.post('whatsapp/templates/sync');
      toast.success(r.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };
  const t = info.templates;
  return (
    <Card>
      <CardHeader title="Template synchronisation" icon={LayoutTemplate} />
      <div className="card-body">
        <div className="grid grid-cols-4 gap-2 text-center">
          {[
            ['Approved', t.approved, 'text-emerald-600 dark:text-emerald-400'],
            ['Pending', t.pending, 'text-amber-600 dark:text-amber-400'],
            ['Rejected', t.rejected, 'text-red-600 dark:text-red-400'],
            ['Draft', t.draft, 'text-slate-600 dark:text-slate-300'],
          ].map(([l, v, c]) => (
            <div key={l as string} className="rounded-xl bg-slate-50 py-2 dark:bg-slate-800/60">
              <p className={clsx('font-display text-lg font-bold tabular-nums', c as string)}>{formatNumber(v)}</p>
              <p className="text-[11px] text-slate-500 dark:text-slate-400">{l as string}</p>
            </div>
          ))}
        </div>
        <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">Last sync: {t.last_sync_at ? `${formatDateTime(t.last_sync_at)} (${timeAgo(t.last_sync_at)})` : 'never'} · the worker syncs hourly.</p>
        <div className="mt-3 flex flex-wrap gap-2">
          {can('whatsapp_templates', 'approve') && (
            <Button size="sm" icon={RefreshCw} loading={busy} onClick={sync}>
              Sync statuses now
            </Button>
          )}
          {can('whatsapp_templates', 'view') && (
            <Button size="sm" variant="secondary" to="/whatsapp/templates">
              Template library
            </Button>
          )}
        </div>
      </div>
    </Card>
  );
}

function WorkerCard({ info, canManage }: { info: IntegrationInfo; canManage: boolean }) {
  const toast = useToast();
  const refresh = useWaRefresh();
  const [busy, setBusy] = useState(false);
  const run = async () => {
    setBusy(true);
    try {
      const r = await waRunWorker();
      toast.success(r.message || 'Worker run complete.');
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };
  const w = workerState(info);
  return (
    <Card>
      <CardHeader title="Background worker" subtitle="Sends the queue, processes webhooks, runs automations" icon={Clock} />
      <div className="card-body space-y-3">
        <div className="flex items-start gap-2.5">
          <span className="mt-1.5">
            <HealthDot state={w.state} pulse={w.state === 'ok'} />
          </span>
          <div className="text-sm">
            <p className="font-semibold text-slate-800 dark:text-slate-100">{w.title}</p>
            <p className="text-slate-500 dark:text-slate-400">{w.note}</p>
            {info.pages_only && (
              <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {info.last_cron_at ? `The cron last ran ${timeAgo(info.last_cron_at)}. ` : ''}Add the cron job{info.cron ? ' below' : ''} so queued messages, follow-ups and campaigns also go out when nobody has WhatsApp open.
              </p>
            )}
          </div>
        </div>
        {info.cron ? (
          <>
            <div>
              <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">Cron job (every minute)</p>
              <p className="text-xs text-slate-500 dark:text-slate-400">hPanel › Advanced › Cron Jobs → Custom, every minute.</p>
            </div>
            <CopyField value={info.cron.linux} label="Cron command" />
            <details className="text-sm">
              <summary className="cursor-pointer text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">Worker URL / Windows Task Scheduler</summary>
              <div className="mt-2 space-y-2">
                <CopyField value={info.cron.url} label="Worker URL" secret />
                <CopyField value={info.cron.windows} label="Windows command" secret />
              </div>
            </details>
          </>
        ) : (
          <p className="text-xs text-slate-500 dark:text-slate-400">Only WhatsApp settings managers can see the cron command (it contains the worker token).</p>
        )}
        {canManage && (
          <Button size="sm" variant="soft" icon={RefreshCw} loading={busy} onClick={run}>
            Run worker now
          </Button>
        )}
      </div>
    </Card>
  );
}
