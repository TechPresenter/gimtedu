import { useMemo, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import { useQuery } from '@tanstack/react-query';
import { AlertCircle, CalendarClock, CheckCircle2, Clock, FlaskConical, Gauge, Rocket, Send, ShieldCheck, Users } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Field, Input, Modal, Skeleton, Toggle, useToast } from '@/components/ui';
import { ApiError, api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber } from '@/lib/format';
import { StatusTicks, WaBubble, WaPhoneFrame, formatWaPhone, type WaMessageStatus } from '../shared';
import { useCampaignRefresh } from './api';
import { businessHoursText, durationText, fromLocalInput, fromServerDate, inBusinessHours, samplePreview, templateTitle, toLocalInput, type Draft } from './lib';
import type { AudiencePreview, CampaignDetail, CampaignMeta, TestSendResult } from './types';
import type { StepKey } from './CampaignEditorPage';
import { CategoryBadge, TypeTile } from './ui';

export function StepReview({
  draft,
  patch,
  errors,
  meta,
  campaign,
  onGoto,
  saveStep,
  dirty,
  canEditDraft,
}: {
  draft: Draft;
  patch: (p: Partial<Draft>) => void;
  errors: Record<string, string>;
  meta: CampaignMeta;
  campaign: CampaignDetail;
  onGoto: (k: StepKey) => void;
  saveStep: (k?: StepKey, quiet?: boolean) => Promise<boolean>;
  dirty: boolean;
  /** May change the draft (edit permission, or create permission on an own draft). Publishers without it can still test and send. */
  canEditDraft: boolean;
}) {
  const toast = useToast();
  const navigate = useNavigate();
  const refresh = useCampaignRefresh();
  const { can } = useAuth();
  const canPublish = can('whatsapp_campaigns', 'publish');
  const canTest = canPublish || can('whatsapp_campaigns', 'create') || can('whatsapp_campaigns', 'edit');
  const tpl = meta.templates.find((t) => t.id === draft.template_id) ?? null;
  const ready = campaign.readiness;
  const [when, setWhen] = useState<'now' | 'schedule'>(campaign.status === 'scheduled' ? 'schedule' : 'now');
  const initial = fromServerDate(campaign.scheduled_at) ?? (() => {
    const d = new Date(Date.now() + 2 * 3600_000);
    d.setMinutes(0, 0, 0);
    return d;
  })();
  const [at, setAt] = useState(toLocalInput(initial));
  const [sendErrors, setSendErrors] = useState<Record<string, string>>({});
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [sending, setSending] = useState(false);
  const [testPhone, setTestPhone] = useState('');
  const [testBusy, setTestBusy] = useState(false);
  const [testErr, setTestErr] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<TestSendResult | null>(null);

  const payload = useMemo(() => ({ campaign_id: campaign.id, audience_source: draft.audience_source, audience: draft.audience, template_id: draft.template_id, type: draft.type, rate_per_minute: draft.rate_per_minute }), [campaign.id, draft]);
  const aud = useQuery({
    queryKey: ['wa', 'campaigns', 'preview', 'review', JSON.stringify(payload)],
    queryFn: ({ signal }) => api.post<AudiencePreview>('whatsapp/campaigns/audience/preview', payload, { signal }).then((r) => r.data),
    staleTime: 15_000,
  });
  const p = aud.data;

  const atDate = fromLocalInput(at);
  const outsideHours = when === 'schedule' && !!atDate && !inBusinessHours(meta.business_hours, atDate);
  const nowOutside = when === 'now' && !meta.in_business_hours;
  const blockers = ready && !ready.ok ? (['template', 'audience', 'personalise'] as const).filter((k) => ready.errors[k]) : [];

  const sendTest = async () => {
    setTestBusy(true);
    setTestErr(null);
    setTestResult(null);
    try {
      const res = await api.post<TestSendResult>(`whatsapp/campaigns/${campaign.id}/test`, { phone: testPhone, variable_map: draft.variable_map, template_id: draft.template_id });
      setTestResult(res.data);
      toast.success(res.message);
    } catch (e) {
      const err = e as ApiError;
      setTestErr(err.errors?.phone ?? err.errors?.template_id ?? err.message);
    } finally {
      setTestBusy(false);
    }
  };

  const openConfirm = async () => {
    setSendErrors({});
    if (when === 'schedule' && (!atDate || atDate.getTime() < Date.now() + 60_000)) {
      setSendErrors({ scheduled_at: 'Choose a time at least a minute from now.' });
      return;
    }
    if (dirty && canEditDraft) {
      const ok = await saveStep('review', true);
      if (!ok) return;
    }
    setConfirmOpen(true);
  };

  const doSend = async () => {
    setSending(true);
    try {
      const body = when === 'now' ? { when: 'now' } : { when: 'schedule', scheduled_at: at.replace('T', ' ') + ':00' };
      const res = await api.post(`whatsapp/campaigns/${campaign.id}/send`, body);
      toast.success(res.message);
      await refresh();
      navigate(`/whatsapp/campaigns/${campaign.id}`);
    } catch (e) {
      const err = e as ApiError;
      setSendErrors(err.errors ?? {});
      toast.error(err.message);
      setConfirmOpen(false);
    } finally {
      setSending(false);
    }
  };

  const stepFor: Record<string, StepKey> = { template: 'template', audience: 'audience', personalise: 'personalise' };
  // per-recipient values (contact field / list column) without a fallback: people lacking them are skipped at send time
  const gaps = (tpl?.slots ?? [])
    .filter((s) => {
      const m = draft.variable_map[s.key];
      return !!m && (m.source === 'contact' || m.source === 'csv') && !(m.fallback ?? '').trim();
    })
    .map((s) => s.label);

  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div className="min-w-0 space-y-5">
        {/* readiness */}
        {ready && !ready.ok ? (
          <Alert variant="error" title="Fix these before sending">
            <ul className="mt-1 space-y-1">
              {blockers.map((k) => (
                <li key={k} className="flex flex-wrap items-center gap-2">
                  <span>{ready.errors[k]}</span>
                  <button type="button" className="text-xs font-semibold underline" onClick={() => onGoto(stepFor[k])}>
                    Fix
                  </button>
                </li>
              ))}
            </ul>
          </Alert>
        ) : ready ? (
          <Alert variant="success" title="Ready to send">
            Template, audience and personalisation are complete{dirty ? ' (save your latest changes to re-check)' : ''}.
          </Alert>
        ) : null}

        {/* summary */}
        <Card>
          <CardHeader title="Summary" />
          <dl className="grid grid-cols-1 divide-y divide-slate-100 dark:divide-slate-800 sm:grid-cols-2 sm:divide-y-0">
            <SummaryItem label="Campaign" onEdit={() => onGoto('details')}>
              <span className="flex items-center gap-2">
                <TypeTile type={draft.type} size="sm" />
                <span className="min-w-0">
                  <span className="block truncate font-semibold text-slate-900 dark:text-white">{draft.name}</span>
                  <span className="text-xs text-slate-500 dark:text-slate-400">{meta.types.find((t) => t.value === draft.type)?.label}</span>
                </span>
              </span>
            </SummaryItem>
            <SummaryItem label="Template" onEdit={() => onGoto('template')}>
              {tpl ? (
                <span className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold text-slate-900 dark:text-white">{templateTitle(tpl)}</span>
                  <CategoryBadge category={tpl.category} />
                </span>
              ) : (
                <span className="text-red-600 dark:text-red-300">Not chosen</span>
              )}
            </SummaryItem>
            <SummaryItem label="Audience" onEdit={() => onGoto('audience')}>
              {p ? (
                <span>
                  <b className="text-slate-900 dark:text-white">{formatNumber(p.eligible)}</b> recipients
                  <span className="text-slate-500 dark:text-slate-400"> · {formatNumber(p.skipped)} skipped</span>
                  <span className="block text-xs text-slate-500 dark:text-slate-400">{draft.audience_source === 'filters' ? 'CRM filters' : draft.audience_source === 'csv' ? 'Uploaded list' : 'CRM filters + uploaded list'}</span>
                </span>
              ) : (
                <Skeleton className="h-5 w-32" />
              )}
            </SummaryItem>
            <SummaryItem label="Personalisation" onEdit={() => onGoto('personalise')}>
              {tpl ? (
                ready?.errors.variables ? (
                  <span className="text-amber-700 dark:text-amber-300">{Object.keys(ready.errors.variables).length} variable(s) need a value</span>
                ) : (
                  <span>
                    {tpl.slots.length} variable{tpl.slots.length === 1 ? '' : 's'} mapped
                  </span>
                )
              ) : (
                '—'
              )}
            </SummaryItem>
          </dl>
          {gaps.length > 0 && !ready?.errors.variables && (
            <p className="flex items-start gap-2 border-t border-slate-100 px-5 py-3 text-xs text-slate-600 dark:border-slate-800 dark:text-slate-300">
              <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600" aria-hidden />
              <span>
                Recipients with no value for {gaps.join(', ')} are skipped and listed in the report as “Missing personalisation values”.{' '}
                <button type="button" className="font-semibold text-brand-700 hover:underline dark:text-brand-300" onClick={() => onGoto('personalise')}>
                  Add a fallback
                </button>{' '}
                to reach everyone.
              </span>
            </p>
          )}
          {p && p.breakdown.length > 0 && (
            <div className="flex flex-wrap gap-1.5 border-t border-slate-100 px-5 py-3 dark:border-slate-800">
              {p.breakdown.map((b) => (
                <Badge key={b.reason} color="slate">
                  {b.label}: {formatNumber(b.count)}
                </Badge>
              ))}
            </div>
          )}
        </Card>

        {/* delivery options */}
        <Card>
          <CardHeader title="Delivery" subtitle="Messages are queued and sent by the WhatsApp worker; agent replies always go first." icon={Gauge} />
          <fieldset disabled={!canEditDraft} className="grid min-w-0 gap-5 p-5 pt-0 md:grid-cols-2">
            <Field label="Sending speed" htmlFor="cmp-rate" error={errors.rate_per_minute} hint={`Empty = account rate (${formatNumber(meta.rate_per_minute)} / min). Lower it to spread replies for the helpline.`}>
              <Input id="cmp-rate" type="number" min={10} max={1000} step={10} suffix="per min" value={draft.rate_per_minute} invalid={!!errors.rate_per_minute} placeholder={String(meta.rate_per_minute)} onChange={(e) => patch({ rate_per_minute: e.target.value })} />
            </Field>
            <div className="pt-1">
              <Toggle
                checked={draft.respect_business_hours}
                onChange={(v) => patch({ respect_business_hours: v })}
                label="Send only during business hours"
                description={businessHoursText(meta.business_hours)}
              />
            </div>
          </fieldset>
        </Card>

        {/* when */}
        <Card>
          <CardHeader title="When to send" icon={CalendarClock} />
          <div className="space-y-4 p-5 pt-0">
            <div role="radiogroup" aria-label="When to send" className="grid gap-2.5 sm:grid-cols-2">
              {(
                [
                  { k: 'now', l: 'Send now', d: 'Starts immediately after you confirm.', i: Rocket },
                  { k: 'schedule', l: 'Schedule', d: 'Pick a date and time (IST).', i: Clock },
                ] as const
              ).map((o) => (
                <button
                  key={o.k}
                  type="button"
                  role="radio"
                  aria-checked={when === o.k}
                  onClick={() => setWhen(o.k)}
                  className={clsx(
                    'flex items-start gap-3 rounded-xl border p-3 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                    when === o.k ? 'border-accent-500 bg-accent-50/60 ring-1 ring-accent-500 dark:bg-accent-500/10' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900',
                  )}
                >
                  <o.i className={clsx('mt-0.5 h-5 w-5 shrink-0', when === o.k ? 'text-accent-600 dark:text-accent-400' : 'text-slate-400')} aria-hidden />
                  <span>
                    <span className="block text-sm font-semibold text-slate-900 dark:text-white">{o.l}</span>
                    <span className="block text-xs text-slate-500 dark:text-slate-400">{o.d}</span>
                  </span>
                </button>
              ))}
            </div>
            {when === 'schedule' && (
              <Field label="Date and time" htmlFor="cmp-at" required error={sendErrors.scheduled_at}>
                <Input id="cmp-at" type="datetime-local" value={at} min={toLocalInput(new Date(Date.now() + 120_000))} invalid={!!sendErrors.scheduled_at} onChange={(e) => setAt(e.target.value)} />
              </Field>
            )}
            {(outsideHours || nowOutside) && (
              <Alert variant="warning">
                {when === 'now' ? 'It is outside business hours right now' : 'This time is outside business hours'} ({businessHoursText(meta.business_hours)}).
                {draft.respect_business_hours ? ' Messages wait until the next business hours start.' : ' Recipients may get the message late in the evening — consider turning on “Send only during business hours”.'}
              </Alert>
            )}
            {!canPublish && <Alert variant="info">You can prepare and test this campaign. A team member with permission to publish campaigns must send or schedule it.</Alert>}
            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
              <Button icon={when === 'now' ? Send : CalendarClock} disabled={!canPublish || !!(ready && !ready.ok) || !p || p.eligible < 1} loading={sending} onClick={openConfirm}>
                {when === 'now' ? `Send to ${p ? formatNumber(p.eligible) : '…'} recipients` : 'Schedule campaign'}
              </Button>
            </div>
          </div>
        </Card>
      </div>

      {/* test + preview */}
      <div className="space-y-4 lg:sticky lg:top-20 lg:self-start">
        <Card className="p-5">
          <p className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
            <FlaskConical className="h-4 w-4 text-accent-600" aria-hidden /> Send a test message
          </p>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Goes to one number with the current mapping (gaps filled with samples). Not counted in the campaign.</p>
          <form
            className="mt-3 flex gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              sendTest();
            }}
          >
            <Input aria-label="Test WhatsApp number" type="tel" value={testPhone} invalid={!!testErr} placeholder="+91 98765 43210" onChange={(e) => setTestPhone(e.target.value)} />
            <Button type="submit" variant="secondary" loading={testBusy} disabled={!testPhone.trim() || !tpl || !canTest}>
              Test
            </Button>
          </form>
          {testErr && (
            <p className="form-error mt-1" role="alert">
              <AlertCircle className="h-3.5 w-3.5" aria-hidden /> {testErr}
            </p>
          )}
          {testResult && (
            <p className="mt-2 flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
              <StatusTicks status={testResult.status as WaMessageStatus} size={14} /> {formatWaPhone(testResult.phone)} · {testResult.status}
              {testResult.mode === 'test' && <Badge color="amber">test mode</Badge>}
            </p>
          )}
        </Card>
        {tpl && (
          <WaPhoneFrame height={420} subtitle={testResult ? 'Last test message' : 'Template preview'}>
            <WaBubble direction="out" type="template" template={testResult?.preview ?? samplePreview(tpl)} time={null} showStatus={false} />
          </WaPhoneFrame>
        )}
      </div>

      <Modal
        open={confirmOpen}
        onClose={() => !sending && setConfirmOpen(false)}
        static={sending}
        title={when === 'now' ? 'Send this campaign now?' : 'Schedule this campaign?'}
        icon={<span className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300"><Send className="h-5 w-5" aria-hidden /></span>}
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirmOpen(false)} disabled={sending}>
              Go back
            </Button>
            <Button icon={when === 'now' ? Send : CalendarClock} loading={sending} onClick={doSend}>
              {when === 'now' ? 'Yes, send now' : 'Yes, schedule'}
            </Button>
          </>
        }
      >
        <div className="space-y-3 text-sm text-slate-700 dark:text-slate-200">
          <p>
            <b>{draft.name}</b> will {when === 'now' ? 'start sending now' : `start on ${formatDateTime(at.replace('T', ' ') + ':00')} (IST)`} using{' '}
            <b>{tpl ? templateTitle(tpl) : 'the template'}</b> {tpl && <CategoryBadge category={tpl.category} />}.
          </p>
          <div className="grid grid-cols-3 gap-2 text-center">
            <div className="rounded-xl bg-emerald-50 p-3 dark:bg-emerald-500/10">
              <Users className="mx-auto h-4 w-4 text-emerald-700 dark:text-emerald-300" aria-hidden />
              <p className="font-display text-xl font-bold tabular-nums text-emerald-800 dark:text-emerald-200">{formatNumber(p?.eligible ?? 0)}</p>
              <p className="text-xs text-emerald-800/80 dark:text-emerald-200/80">will receive it</p>
            </div>
            <div className="rounded-xl bg-slate-100 p-3 dark:bg-slate-800">
              <ShieldCheck className="mx-auto h-4 w-4 text-slate-600 dark:text-slate-300" aria-hidden />
              <p className="font-display text-xl font-bold tabular-nums text-slate-900 dark:text-white">{formatNumber(p?.skipped ?? 0)}</p>
              <p className="text-xs text-slate-500 dark:text-slate-400">skipped</p>
            </div>
            <div className="rounded-xl bg-brand-50 p-3 dark:bg-brand-500/10">
              <Clock className="mx-auto h-4 w-4 text-brand-700 dark:text-brand-300" aria-hidden />
              <p className="font-display text-xl font-bold tabular-nums text-brand-900 dark:text-white">{p ? Math.max(1, p.estimated_minutes) : '—'}</p>
              <p className="text-xs text-brand-900/70 dark:text-brand-100/70">min to send</p>
            </div>
          </div>
          {p && p.breakdown.length > 0 && (
            <ul className="space-y-0.5 text-xs text-slate-500 dark:text-slate-400">
              {p.breakdown.map((b) => (
                <li key={b.reason}>
                  {b.label}: {formatNumber(b.count)}
                </li>
              ))}
            </ul>
          )}
          <p className="flex items-start gap-2 text-xs text-slate-500 dark:text-slate-400">
            <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" aria-hidden />
            {meta.mode === 'test' ? 'Test mode: messages are simulated and not delivered to WhatsApp.' : `Sending at up to ${formatNumber(p?.rate_per_minute ?? meta.rate_per_minute)} per minute (${durationText(p?.estimated_minutes ?? 1)}). You can pause or cancel from the campaign page.`}
          </p>
        </div>
      </Modal>
    </div>
  );
}

function SummaryItem({ label, children, onEdit }: { label: string; children: ReactNode; onEdit: () => void }) {
  return (
    <div className="flex items-start justify-between gap-3 px-5 py-3">
      <div className="min-w-0">
        <dt className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</dt>
        <dd className="mt-1 text-sm text-slate-700 dark:text-slate-200">{children}</dd>
      </div>
      <button type="button" onClick={onEdit} className="shrink-0 text-xs font-semibold text-brand-700 hover:underline dark:text-brand-300">
        Edit
      </button>
    </div>
  );
}
