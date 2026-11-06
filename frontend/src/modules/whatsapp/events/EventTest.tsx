import { useEffect, useId, useState } from 'react';
import { FlaskConical, RotateCcw, Send } from 'lucide-react';
import { Alert, Badge, Button, DescriptionList, Field, Input, Textarea, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { labelize } from '@/lib/format';
import { StatusTicks, useWaRefresh, WaText } from '../shared';
import { asRecord, logDisplayStatus, prettyJson } from './api';
import type { WaEventDetail, WaEventTestResult } from './types';

const PHONE_KEY = 'gimt.wa.events.testPhone';

/** Fire the event with the sample (or edited) payload to a test phone and show what the engine did. */
export function EventTest({ event, onOpenLog }: { event: WaEventDetail; onOpenLog: (id: number) => void }) {
  const { can, session } = useAuth();
  const toast = useToast();
  const refresh = useWaRefresh();
  const uid = useId();
  const myPhone = session?.user?.phone ?? '';
  const [phone, setPhone] = useState(() => {
    try {
      return localStorage.getItem(PHONE_KEY) || myPhone;
    } catch {
      return myPhone;
    }
  });
  const sample = JSON.stringify(asRecord(event.sample_payload), null, 2);
  const [payload, setPayload] = useState(sample);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<WaEventTestResult | null>(null);
  useEffect(() => {
    setPayload(sample);
    setResult(null);
  }, [event.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const run = async () => {
    const errs: Record<string, string> = {};
    if (phone.replace(/\D/g, '').length < 10) errs.phone = 'Enter a WhatsApp number with country code, e.g. +91 98765 43210.';
    let body: unknown = null;
    try {
      body = payload.trim() ? JSON.parse(payload) : {};
      if (!body || typeof body !== 'object' || Array.isArray(body)) errs.payload = 'The payload must be a JSON object.';
    } catch {
      errs.payload = 'The payload is not valid JSON.';
    }
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setBusy(true);
    try {
      const r = await api.post<WaEventTestResult>(`whatsapp/events/${event.id}/test`, { phone, payload: body });
      setResult(r.data);
      try {
        localStorage.setItem(PHONE_KEY, phone);
      } catch {
        /* storage unavailable */
      }
      const st = r.data.outcome?.status;
      if (st === 'queued') toast.success(r.message);
      else toast.warning(r.message);
      void refresh('events');
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message);
    } finally {
      setBusy(false);
    }
  };

  const log = result?.log;
  const status = log ? logDisplayStatus({ status: log.status, outcome: result?.outcome ?? null }) : null;
  const msg = log?.messages?.[0];

  return (
    <div className="space-y-4">
      <Alert variant="info" title="How testing works">
        The event fires through the real engine with your payload, but the message always goes to the test number below
        (customer ids are removed so no CRM record is changed). It works even while the event is inactive.
        {!event.template_id && ' Link a template first.'}
      </Alert>
      <Field label="Test WhatsApp number" required htmlFor={`${uid}-phone`} error={errors.phone} hint="Your own number, with the country code.">
        <Input id={`${uid}-phone`} type="tel" inputMode="tel" autoComplete="tel" placeholder="+91 98765 43210" value={phone} invalid={!!errors.phone} onChange={(e) => setPhone(e.target.value)} />
      </Field>
      <Field label="Payload (JSON)" htmlFor={`${uid}-payload`} error={errors.payload} aside={<button type="button" className="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300" onClick={() => setPayload(sample)}>Reset to sample</button>}>
        <Textarea id={`${uid}-payload`} rows={10} spellCheck={false} className="font-mono text-xs" value={payload} invalid={!!errors.payload} onChange={(e) => setPayload(e.target.value)} />
      </Field>
      <div className="flex flex-wrap gap-2">
        <Button icon={Send} loading={busy} disabled={!can('whatsapp_automation', 'edit')} onClick={run}>Send test</Button>
        {result && <Button variant="ghost" icon={RotateCcw} onClick={() => setResult(null)}>Clear result</Button>}
      </div>

      {result && log && (
        <div className="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h4 className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><FlaskConical className="h-4 w-4 text-accent-600" aria-hidden /> Result</h4>
            <button type="button" className="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300" onClick={() => onOpenLog(log.id)}>Open log #{log.id}</button>
          </div>
          <DescriptionList
            columns={2}
            items={[
              { label: 'Outcome', value: status ? <Badge color={status.color} dot>{status.label}</Badge> : '—' },
              { label: 'Template', value: result.outcome?.template ?? '—' },
              { label: 'Recipient', value: result.outcome?.recipient ?? '—' },
              { label: 'Message', value: msg ? <span className="inline-flex items-center gap-1">#{msg.id} <StatusTicks status={msg.status} /> {labelize(msg.status)}</span> : '—' },
              ...(result.outcome?.reason ? [{ label: 'Reason', value: result.outcome.reason, full: true }] : []),
            ]}
          />
          {msg?.body && (
            <div className="rounded-xl bg-[#efeae2] p-3 dark:bg-slate-800/70">
              <div className="max-w-md rounded-lg rounded-tr-none bg-[#d9fdd3] px-3 py-2 text-sm text-slate-800 shadow-sm dark:bg-emerald-900/60 dark:text-emerald-50">
                <WaText text={msg.body} />
              </div>
            </div>
          )}
          <details className="group">
            <summary className="cursor-pointer text-xs font-medium text-slate-600 dark:text-slate-300">Actions recorded by the engine</summary>
            <pre className="mt-2 max-h-64 overflow-auto rounded-xl bg-slate-950 p-3 text-[12px] text-slate-100">{prettyJson(log.actions)}</pre>
          </details>
        </div>
      )}
    </div>
  );
}
